# WhatsApp inbound inbox + webhook — design

Status: for review. No implementation code written yet.
Date: 6 November 2026
App: `D:\etp-wa-inbox` (fresh Laravel 12.71.0)
Hosting target: new subdomain on the existing etaxplanner.com Plesk server, its own MySQL database.

## 1. Problem this solves

Messages go out from the Cloud API number +1 224-214-4261 (WABA 1717463009877473). When a
recipient replies, there is currently nowhere for that reply to go:

- The number is registered as `platform_type: CLOUD_API`, so it cannot be added to the Meta
  Business Suite shared inbox. The onboarding screen rejects it: *"The phone number provided is
  not from the business app."*
- No webhook URL is subscribed for the phone number.

So every inbound message, and every delivery and read receipt, is dropped by Meta. That is also a
compliance problem: the approved templates tell recipients to "Reply STOP" and "Talk to an agent",
and neither of those currently reaches anyone.

## 2. What is in scope

1. Receive and verify Meta's webhook callbacks for this phone number.
2. Store inbound and outbound messages and show a thread per contact, so replies can be read and
   answered from a page.
3. Two automatic responses only: opt-out (STOP family) and a one-time acknowledgement of the first
   inbound message.
4. A machine-readable suppression list, which the existing local send bench must consult before it
   sends anything.
5. Delivery and read receipts recorded on the message rows they belong to.

## 3. Non-goals

Explicitly out of this build. If one of these is wanted later it is its own design:

- Chatbot flows, keyword routing, LLM drafting of replies.
- Multi-agent assignment, tags, internal notes, SLA reporting.
- Inbound media handling (images, documents, voice notes) — stored as a type and a placeholder, not
  downloaded.
- Campaign scheduling and bulk sending. Sending stays in `sender_server.py`.
- Analytics dashboards.
- Anything touching WABA 830913789751992 (the old AiSensy account, billed in INR). That account must
  never be subscribed to this endpoint.

## 4. Where it runs

**Separate app, separate subdomain.** `inbox.etaxplanner.com` (name to be confirmed before deploy)
pointed at this Laravel project's `public/` directory as a Plesk vhost, PHP 8.2.35, one dedicated
MySQL database and DB user created in Plesk. Reasons, all practical:

- The webhook endpoint is reachable and versioned independently of the marketing site; deploying
  the inbox cannot break a tax page and vice versa.
- Its own `.env` and its own database user means the Meta token and the session key never sit next
  to the main site's credentials.
- A separate vhost can have its own TLS cert, its own access log, and can be locked to the single
  URL Meta calls without affecting anything else.

**Local development** uses the MariaDB 10.4.32 server already installed at `C:\xampp\mysql`
(`mysqli` and `pdo_mysql` are compiled into the local PHP 8.2.12). Schema is written to be portable
across both: `utf8mb4` with `utf8mb4_unicode_ci` specified explicitly, no MySQL-8-only syntax, no
functional indexes, no `CHECK` constraints in migrations, `json` columns only where Laravel's
`json` cast is used.

## 5. Data model

Three tables added by this app. `users`, `cache`, `jobs` already exist from the framework.

`wa_contacts` — one row per phone number that has talked to us. This becomes the authoritative list
of who is contactable, replacing the hand-edited `test_numbers.txt` and `send_log.csv` as the
source of truth.

| column | type | notes |
| --- | --- | --- |
| id | bigint pk | |
| phone | string(20) unique | E.164 digits only, no `+` |
| profile_name | string(191) null | from Meta's `profile.name` when present |
| opted_out_at | timestamp null | the gate column |
| opt_out_source | string(20) null | `inbound_keyword`, `manual`, or `imported` |
| last_inbound_at | timestamp null | |
| created_at / updated_at | timestamps | |

`wa_messages` — one row per message or status event, inbound and outbound, newest thread view built
by ordering on `sent_at`.

| column | type | notes |
| --- | --- | --- |
| id | bigint pk | |
| wa_contact_id | fk → wa_contacts, cascade delete | |
| meta_id | string(128) unique | Meta's `wamid`; the idempotency key |
| direction | enum `in` / `out` | |
| type | string(24) | `text`, `button`, `interactive`, `template`, `image`, … |
| body | text null | encrypted, see §8 |
| template_name / template_lang | string null | set on outbound template sends |
| status | string(24) null | `sent`, `delivered`, `read`, `failed`, `deleted`, `incoming` |
| status_at | timestamp null | when that status arrived |
| error_code / error_title | string null | for `failed` |
| auto_reply | boolean default false | so machine sends are visibly marked in the thread |
| sent_at | timestamp | the message's own timestamp, not ours |
| raw | json null | the payload fragment this row came from |
| created_at / updated_at | timestamps | |

`wa_webhook_events` — the full body of every callback we accepted, kept for debugging and replay.
`id`, `received_at`, `entry_id`, `phone_number_id`, `changes_json`, `body_hash` (sha256 of the exact
raw request body, indexed but **not** unique), `signature_valid` (`valid` / `invalid` / `unchecked`),
`status` (`stored`, `duplicate`, `ignored`, `rejected_signature`). Written before parsing succeeds,
so a payload that breaks the parser is still recoverable. `body_hash` is what the dedupe check reads:
a non-unique index, because Meta's retry of an identical callback is itself an accepted callback and
earns its own audit row — a unique constraint would throw a database error inside the request we must
answer with `200`. Retention: 30 days, pruned by a scheduled command.

## 6. Routes

| method | uri | who | what |
| --- | --- | --- | --- |
| GET | `/wa/webhook` | Meta | `hub.mode`/`hub.verify_token`/`hub.challenge` echo, token compared to `WA_VERIFY_TOKEN` |
| POST | `/wa/webhook` | Meta | signature check → persist event → dispatch job → `200` with an empty body |
| GET | `/wa/suppressions` | our own sender | bearer `WA_EXPORT_TOKEN` → newline list of opted-out numbers |
| GET | `/` → `/inbox` | logged-in staff | contact list, search, unread-ish ordering |
| GET | `/inbox/{contact}` | logged-in staff | the thread, plus the reply box |
| POST | `/inbox/{contact}/reply` | logged-in staff | free-form reply if the 24-hour window is open |
| POST | `/inbox/{contact}/template` | logged-in staff | send an approved template when it is not |
| POST | `/inbox/{contact}/opt-out` | logged-in staff | set `opted_out_at` immediately |
| POST | `/inbound/record-send` | our own sender | bearer `WA_EXPORT_TOKEN`; writes the `out` row for a bench send and creates the contact if missing |
| GET | `/login` | — | Laravel's own auth; one staff account created by `php artisan wa:create-user` at deploy time |

Webhook and `/wa/suppressions` are CSRF-exempt and outside the auth middleware; the inbox is
behind standard Laravel session auth with rate limiting on login.

## 7. Webhook contract, in order

1. **Verify token** on the GET challenge, constant-time compare against `WA_VERIFY_TOKEN` (a random
   string generated once and stored only in `.env`). Mismatch → `403`, nothing logged beyond the
   server access log.
2. **Signature** on POST: require header `X-Hub-Signature-256`, prefix exactly `sha256=`, recompute
   `hash_hmac('sha256', rawBody, WA_APP_SECRET)`, compare with `hash_equals` against the hex after
   the prefix. Missing, malformed, or mismatching → `403` and the body is **not** stored. Meta
   always signs when the app has app secret enforced; treating an unsigned POST as hostile is the
   fail-closed default.
3. **Read the raw body ourselves** (`$request->getContent()`), never a parsed-then-re-serialised
   copy — the HMAC is over the exact bytes sent.
4. **Fast 200.** Persist the event row, dispatch one job per `changes[].value.messages[]` /
   `statuses[]` entry, return `200` immediately. Meta retries with backoff on any non-2xx and
   eventually disables the subscription, so nothing slow, nothing throwing, belongs on this path.
   Handler work is wrapped so a bad payload logs and still returns `200`.
5. **Filter to our number.** Process only `entry[].changes[].value.metadata.phone_number_id ==
   WA_PHONE_ID`. Anything else — including the old AiSensy WABA if it ever gets pointed here — is
   recorded as `ignored` and discarded.
6. **Idempotency.** A contact is found or created from `contacts[0].wa_id` (falling back to the
   message's `from`). Each message insert uses `meta_id` as a unique key with
   `updateOrCreate`, so Meta's retries and the `echo` of our own outbound sends (the `messages`
   array on the send response also arrives as a webhook value) cannot create duplicates. Replays
   are marked `duplicate` in `wa_webhook_events`.
7. **Statuses** map by `wamid` onto the existing outbound row: `sent`, `delivered`, `read`, `failed`
   (plus `id`/`error_code`/`title`). Statuses for messages we never stored are dropped, not
   invented.

## 8. Message body storage

Body text is encrypted with Laravel's `encrypted` cast before it reaches MySQL, so `APP_KEY` staying
private is what protects a reply that contains tax details. `raw` is the same. This is
encryption-at-application-level, not database-level: anyone with the `APP_KEY` and the DB can read
it, and Meta still sees plaintext in transit and on their side. The main site's `.env` is not copied
here; this app has its own key.

## 9. Auto-responses

Both run in the queued job, after the inbound row is stored.

- **Opt-out.** The first line of an inbound text message is lowercased, stripped of surrounding
  punctuation and split on whitespace; if *any* word in it belongs to the fixed set `stop`, `end`,
  `cancel`, `unsubscribe`, `opt out`, `opt-out`, `optout`, `quit`, `remove` the message is an opt-out
  (so "please STOP and email me" counts). A Meta `interactive` button reply whose id is `STOP` counts
  too. Then: set `opted_out_at`,
  `opt_out_source = inbound_keyword`, and send one free-form confirmation — "You won't get any more
  messages from us on this number." Suppression is applied in the same job, before the send is
  attempted, so the gate is closed whether or not the confirmation reaches the handset. The
  confirmation is the one case where we try a free-form send without checking the 24-hour window
  first: a required response to a suppression request should not be withheld because the clock ran
  out. When the window has closed Meta rejects it, the rejection and its error code are stored on
  the outbound row, and the thread shows "STOP received, confirmation not deliverable" so a human
  can follow up through another channel. We do not escalate a failed confirmation into a template.
- **First-reply acknowledgement.** When an inbound message is the first from that contact in 24
  hours and it is *not* an opt-out and *not* from a suppressed contact, send one short line
  confirming a human will read it. Marked `auto_reply = true`. The guard is one row in the `cache`
  table keyed by the contact id with a 24-hour TTL, checked with an atomic add so two callbacks in
  the same second cannot both send.
- **Nothing else.** No keyword answers, no fallback "we didn't understand", no bot. An inbound
  message that is not an opt-out and is not the first of the day produces storage and a row in the
  inbox, and that is all.
- Never auto-send to a contact where `opted_out_at` is set, from any code path, including the
  acknowledgement.

Outbound sends go through one `WhatsAppSender` service (the Cloud API `POST
/{PHONE_ID}/messages` with `WA_ACCESS_TOKEN`) used by the job, the reply form, and the auto-replies,
so the token appears in exactly one place and every send writes an `out` row with the returned
`wamid`.

## 10. Sender integration

`sender_server.py` gains a suppression gate. Before any single or batch send it fetches
`GET /wa/suppressions` with `Authorization: Bearer <WA_EXPORT_TOKEN>`, caches the list for 60
seconds, and refuses any number on it. **Fail-closed:** if that request errors, times out, or is not
a 2xx, the bench refuses to send anything and says so. Default: no suppression list.

It also posts its own sends back to the inbox (`POST /inbound/record-send`, same bearer
token, writes an `out` row and creates the contact if missing), so that opting out later still works
for people who only ever received a bench send and never replied.

## 11. Import step

`php artisan wa:import-send-log D:\...\send_log.csv`, idempotent, dry-run by default with
`--apply`. Creates each `wa_contacts` row from numbers that were sent to, each `wa_messages` row
from the accepted sends and their `message_id`, with `opt_out_source = imported` left unset unless
the log shows a STOP. After this the inbox reflects everything already sent from the bench.

## 12. Queue

`QUEUE_CONNECTION=database` (already the default here). Locally: run
`php artisan queue:work --queue=default` in a second terminal; without a worker nothing auto-replies
and messages still land, which is a fine state to develop the inbox in.

On shared Plesk hosting there is no supervisor, so deploy uses cron: `schedule:run` every minute
plus `queue:work --stop-when-empty --max-time=50` fired by the scheduler. Jobs are already
idempotent by `meta_id`, so a worker killed at its time limit is safe to restart. Failed jobs go to
`failed_jobs` and are visible with `queue:failed`.

## 13. Configuration (.env, never in chat)

`WA_APP_SECRET`, `WA_VERIFY_TOKEN`, `WA_EXPORT_TOKEN`, `WA_ACCESS_TOKEN`, `WA_PHONE_ID`,
`WA_WABA_ID`, `WA_API_BASE` (graph.facebook.com/v20.0 — the version proven on this account).
Plus `DB_*` for the Plesk database. Local `wa_token.txt` stays where it is; nothing copies it into
this repo, and `.gitignore` already excludes `.env`.

## 14. Errors and edge cases worth naming

- Free-form reply after the 24-hour window → Meta refuses it as the re-engagement error. The reply
  form checks `last_inbound_at` before calling the API and shows "window closed — send a template
  instead", with the template picker in the same panel, rather than failing after a click. The exact
  error code Meta returns for this account is captured on the first live occurrence and written to
  the message row rather than assumed here.
- Contact opted out while a message of theirs is still in flight → the send is refused at the gate
  in `WhatsAppSender`, which re-reads `opted_out_at` in the same transaction that writes the row.
- Unknown message type inbound → row stored with the type, body null, and a visible
  "(unsupported message type: image)" line in the thread. No crash, no dropped callback.
- Meta retries the same POST → `duplicate`, no second row, no second auto-reply.
- A contact with no `profile.name` → phone number is the display name everywhere.
- Webhook URL must be HTTPS with a valid cert, so this vhost needs its Let's Encrypt cert enabled
  before subscription can be saved in the developer console.

## 15. Testing

- Unit: signature verification (valid, wrong, missing, non-`sha256=` prefix), verify-token compare,
  opt-out keyword matcher including case and punctuation variants, window-open calculation, thread
  ordering.
- Feature: `POST /wa/webhook` with a recorded real-shaped payload → contact created, message stored,
  `200`; the same payload twice → one message row, event marked `duplicate`; payload for the other
  phone_number_id → `ignored`, no rows; bad signature → `403` and no rows at all; inbound STOP →
  suppressed plus exactly one confirmation send faked through the sender stub; first inbound → one
  ack, second inbound same day → none.
- Feature: `/wa/suppressions` requires the bearer token and returns only opted-out numbers.
- Importer: run twice on the real `send_log.csv`, assert the row counts do not change the second
  time.
- Live acceptance, once, on the deployed subdomain, and it is a manual step: subscribe the webhook in
  the Meta developer console, send a message from a test handset, see it appear, reply to it, send
  "STOP" from the handset and confirm suppression plus refusal from the bench. Numbered steps for
  the console are handed over, not automated.

## 16. Size

About 12 to 16 files changed or added: 3 migrations, 2 models, 1 controller for the webhook, 1 for
the inbox, 1 service for sending, 1 job, 1 console command for the import, 2 Blade views plus a
layout tweak, routes and config entries. Roughly 700 lines. It stays a small app because the sending
strategy, the templates, and the campaigns all live elsewhere.

## 17. Build order

1. MySQL database locally, `.env` switched to it, migrations for the three tables.
2. Webhook endpoint: signature, storage, dedupe, `wa_webhook_events`. Verified against replayed
   payloads before any live subscription.
3. Inbox read views (contact list, thread) — read-only first.
4. `WhatsAppSender` + reply form + template form + manual opt-out.
5. Auto-responses behind the two rules in §9.
6. `/wa/suppressions` and the fail-closed gate in `sender_server.py`.
7. `wa:import-send-log`, run against the real log.
8. Delivery/read receipts mapped onto rows.
9. Deploy to the subdomain, then the manual Meta console subscription, then live acceptance.

## 18. What stays manual

Anything in Facebook Business or the WhatsApp Cloud API developer console: creating the webhook
subscription, pasting the verify token, enabling the app secret, choosing which message fields to
subscribe to. Those are numbered steps plus the exact values to paste, done by the account owner.
This is deliberate — the account carries a quality rating and bot-driven clicks in that UI are not
worth the risk.
