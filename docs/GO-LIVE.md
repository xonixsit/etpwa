# Go-live handoff — the WhatsApp inbox at wa.xonixs.com

Everything in this document is performed by you, the account owner. Nothing here has been
executed: no server exists yet, no webhook is subscribed, and no live step has been taken.
The build is finished and verified locally (308 tests, 810 assertions, all green), and this
package is the single path from that state to a working inbox.

**Decisions you already made (17 Nov 2026), baked into every step below:**

- Subdomain: **wa.xonixs.com** (not the inbox.etaxplanner.com the plan assumed).
- Queue: **database + cron** — Meta always gets a fast 200; a scheduled task processes replies
  within a minute. With `sync`, one bad reply payload could 500 a callback and Meta would drop
  the subscription after repeated 500s (measured in tests).
- A rejected webhook signature now writes one warning line to the app log (no attacker content).
- The send bench is **fail-closed**: if it cannot read the opt-out list from the inbox, it refuses
  every send. This is the behaviour you confirmed.

**Rules that do not bend:**

1. Secrets are typed into the host's `.env` or your own terminal. Never into chat, never into a
   committed file.
2. The token in the laptop's `wa_token.txt` **never goes on the host**. The host gets its own,
   new, restricted System User token (Part 1, step 5).
3. The webhook verify token currently in the local `.env` was leaked into a chat log on
   10 Nov 2026. **Step 1 of the Meta-facing work is setting a fresh one** — do not reuse it.
4. Do not touch WABA `830913789751992` (the old AiSensy account). Only
   `1717463009877473` / phone number id `1427706937088002` / `+1 224-214-4261` connect to this inbox.
5. "My Super Tax" never appears in template copy, the business profile, or anything shared.
6. Past replies are **unrecoverable** — the webhook was never subscribed, so Meta discarded them.
   The test phones must reply AGAIN after subscription for anything to appear.

---

## Part 0 — Prerequisite check (5 minutes)

The hosting facts we measured (PHP 8.2.35, MySQL creatable, Plesk panel) belong to the
**etaxplanner.com** host. You chose `wa.xonixs.com`, so first confirm where xonixs.com lives:

- If xonixs.com is on the **same Plesk server**: nothing extra to check; continue.
- If it is elsewhere: that host must have PHP 8.2 with `pdo_mysql, mbstring, openssl, ctype,
  json, tokenizer, xml`, a MySQL database you can create, scheduled tasks (cron), and the
  ability to issue a Let's Encrypt certificate. If any of these is missing, stop and tell me —
  the app can be pointed at a subdomain of etaxplanner.com instead, but that is your call.

Deployed code: the committed `master` tree at commit `0273ee0` or later.

## Part 1 — Host setup (Plesk)

1. **Create the subdomain** `wa.xonixs.com` in Plesk. Add the DNS A record pointing at the
   server if Plesk does not manage the zone. Set the **document root to the app's `public/`
   directory** (e.g. `<vhost>/httpdocs/public`) — never at the project root.
2. **Create the database**: name `etp_wa_inbox`, a dedicated database user, host `localhost`.
   Note the password somewhere only you can see; it goes into `.env` in step 5.
3. **Upload the app**: git clone on the server is preferred; an archive of the committed tree
   uploaded and extracted is acceptable. The result must be the Laravel project with
   `artisan` at its root.
4. In the subdomain's directory, over SSH:
   ```bash
   composer install --no-dev --optimize-autoloader
   cp .env.example .env
   ```
5. **Fill `.env`** (edit on the server; values never leave it). The keys that matter:

   | key | value |
   |---|---|
   | `APP_ENV` | `production` |
   | `APP_DEBUG` | `false` |
   | `APP_URL` | `https://wa.xonixs.com` |
   | `DB_DATABASE` / `DB_USERNAME` / `DB_PASSWORD` | from step 2 |
   | `QUEUE_CONNECTION` | `database` (already the default — keep it) |
   | `WA_ACCESS_TOKEN` | a **new** System User token, created in Meta Business Settings → System Users: assign the app and WABA `1717463009877473`, permissions `whatsapp_business_messaging` and `whatsapp_business_management`. NOT the laptop's `wa_token.txt` value. |
   | `WA_APP_SECRET` | the app's App Secret from developers.facebook.com → App settings → Basic |
   | `WA_VERIFY_TOKEN` | a **fresh** random string you invent now (the old one leaked). You will paste the same value into Meta's console in Part 3. |
   | `WA_EXPORT_TOKEN` | another fresh random string. The laptop bench will use it in Part 4. |
   | `WA_MY_NUMBER` | `12242144261` (already the default — keep it; with it empty, our own outbound traffic is filed as inbound against our own number) |
   | `WA_ACK_TEXT` / `WA_OPT_OUT_TEXT` | keep the defaults or edit the wording. **Do not leave `WA_OPT_OUT_TEXT` empty**: an empty value still suppresses the client but sends no confirmation — silenced clients who were never told. |

   To invent a random string on the server: `php -r "echo bin2hex(random_bytes(24)), PHP_EOL;"`
6. Still over SSH:
   ```bash
   php artisan key:generate
   php artisan migrate --force
   php artisan config:cache
   ```
   Note: the webhook table's `body_hash` column was added by editing an already-applied
   migration, so on a fresh host `migrate` creates it correctly — but `migrate:status` on a
   host that somehow ran the old file would not show it as pending. Fresh host: no issue.
7. **Timezone check** (the app pins UTC; if the host ignores the pin every timestamp is offset):
   ```bash
   php artisan tinker --execute="echo now()->toDateTimeString(), ' | ', DB::selectOne('select now() x')->x;"
   ```
   Both printed instants must match. If they do not, stop and tell me.
8. **Create your live staff account** (the local demo accounts are NOT for production):
   ```bash
   php artisan wa:create-user you@your-email
   ```
   It prompts for a password; type it there, nowhere else.
9. **Scheduled task** (Plesk → Scheduled Tasks → Add, run every minute):
   ```
   php /var/www/<vhost>/httpdocs/artisan schedule:run >> /dev/null 2>&1
   ```
   Use the real path. This one cron does two jobs: drains the reply queue every minute and
   prunes old webhook audit rows nightly. **Without it, Meta sees 200s and the inbox stays
   silently empty** — Part 5 step 2 proves it runs.
10. **TLS**: issue Let's Encrypt for `wa.xonixs.com` in Plesk and enable "Enforce HTTPS".
    Meta requires HTTPS with a valid chain.

## Part 2 — Prove the endpoint before touching Meta

From your laptop (replace nothing — the token stays on the host, so run the first two
commands over SSH on the host, where `.env` lives):

```bash
VERIFY=$(grep '^WA_VERIFY_TOKEN=' .env | cut -d= -f2)
curl -s "https://wa.xonixs.com/wa/webhook?hub.mode=subscribe&hub.verify_token=$VERIFY&hub.challenge=ok123"
```
Expected: the literal `ok123` and nothing else. Then with `hub.verify_token=wrong`:
expected `forbidden` (403). Then from anywhere:
```bash
curl -s -o /dev/null -w '%{http_code}\n' https://wa.xonixs.com/wa/suppressions
```
Expected: `401` — the opt-out list is not public.

## Part 3 — Subscribe in Meta's console (manual, in this order)

1. developers.facebook.com → the app for WABA `1717463009877473` → WhatsApp →
   **Configuration → Webhooks**.
2. Callback URL: `https://wa.xonixs.com/wa/webhook`
3. Verify token: paste the **fresh** `WA_VERIFY_TOKEN` value from the host's `.env`.
   Do not paste it into chat.
4. Click **Verify and save**. It must come back green before anything else.
5. Leave the User ID field blank.
6. **Subscribe to the `messages` field** on phone number `1427706937088002`.
   Do not subscribe anything on WABA `830913789751992` (AiSensy).
7. In WhatsApp Manager → Phone Number → `+1 224-214-4261` → Webhook: confirm the same
   callback URL is shown for that number only.

From this moment Meta delivers new replies and receipts. Nothing from before this moment exists.

## Part 4 — Connect the laptop bench and clean up the demo

1. **Import the real send history on the host.** Upload the laptop's `send_log.csv` to the
   server, then:
   ```bash
   php artisan wa:import-send-log /path/to/send_log.csv          # dry run: prints what it would do
   php artisan wa:import-send-log /path/to/send_log.csv --apply  # actually imports
   ```
   Locally this measured 4 messages / 3 contacts / 5 skipped, and re-running is safe (idempotent).
2. **Point the bench at the host.** In the terminal you start the bench from (Git Bash):
   ```bash
   export SENDER_INBOX=https://wa.xonixs.com
   export SENDER_INBOX_TOKEN=<the WA_EXPORT_TOKEN value from the host .env>
   python sender_server.py
   ```
   Type the token yourself; never send it to me. Until these two are set, the bench refuses
   every send — that is the fail-closed behaviour working, not a bug. The bench's own rules
   still apply: allow-list only, 100 unique numbers for this campaign, 250 per rolling 24h.
3. **Delete the local demo data** (on the laptop, in MariaDB `etp_wa_inbox`): the demo
   messages/contacts and the two demo staff accounts (`staff@etaxplanner.com`,
   `demo@etaxplanner.com`). The exact delete statements are in
   `local-demo-credentials.txt` next to the bench. After running them, delete that file.
   The live account you created in Part 1 step 8 replaces them.

## Part 5 — Live acceptance, in order (real messages to real handsets)

Watch the counters; each line is pass/fail with what you saw, recorded in Part 7.

1. From the bench, send one approved template to `18479043315`. Expected: "accepted by Meta",
   note says delivery arrives in the inbox.
2. Within a minute: the thread shows the outbound row, and a `sent` then `delivered` status
   appears on it when the handset receives it. **This is the first time delivery is provable.**
   If the row never appears while Meta shows 200s, the cron from Part 1 step 9 is not running.
3. Reply "hello" from that handset. Expected: the reply appears, plus one automatic
   acknowledgement marked `automatic`.
4. Reply again. Expected: stored, **no second ack** (24-hour window).
5. Answer from the inbox reply box. Expected: it sends (the window is open).
6. Send `STOP` from the handset. Expected: `opted out` badge, one confirmation message,
   and the number listed at `GET /wa/suppressions` (with the bearer token).
7. From the bench, try that same number again. Expected: **refused**, error naming the
   suppression list, nothing sent to Meta.
8. Point `SENDER_INBOX` at a dead port (e.g. `http://127.0.0.1:9`) and try any send.
   Expected: **refused** with "suppression list unreachable". Restore the real URL after.
9. **Opt-out phrasing sign-off — Part 6 below. Do this before the first campaign send.**
10. Confirm `WA_OPT_OUT_TEXT` is set on the host (Part 1 step 5 already says why).
11. **Re-arm check:** send one message from the test number, then another more than 24 hours
    later; the second one must be acknowledged too. Only a real handset can prove the
    production cache expires the window key.

## Part 6 — Opt-out wording sign-off (measured, not guessed)

The matcher was measured against 76 message shapes: **33 opt-out requests caught, 13 missed,
10 false hits, 20 correct silences.** Read the two lists and give an explicit yes — or name a
change. Moving a shape is a one-word config change, not a rebuild.

**Caught** (silences the client): `stop`, `STOP`, `stop.`, ` stop `, `stop, please`,
`STOP. I said it`, `unsubscribe`, `cancel`, `quit`, `opt-out`, `optout`, `end`, `remove`,
`end.`, ` remove `, `opt out`, `OPT OUT`, ` opt out.`, `I want to opt out now`, `opt me out`,
`opt me out please`, `please opt me out of this`, `stop…`, `“stop”`, `end…`, `end。`,
`remove‑`, `“remove”`, and four non-ASCII-space forms.

**Missed opt-outs** (client asked, keeps getting messages — the dangerous direction):

| shape | why |
|---|---|
| `end now`, `remove me`, `please remove`, `please remove me`, `kindly remove me from this list`, `I want to end this` | `end`/`remove` only fire as the ENTIRE message — your earlier choice, because "end"/"remove" are ordinary English in a tax practice |
| `opting out` | no word-stemming |
| `I'd like to be removed` | `removed` is not `remove` |
| `delete my number`, `no more messages please`, `dont message me again` | free text outside any fixed set |
| `opt.out` | punctuation inside a word is not glued |
| invalid UTF-8 first line | stored for a human instead |

**False hits** (silenced without asking — visible in the thread and reversible):

| shape | why |
|---|---|
| `the opt out link is broken`, `what is an opt out`, `do not opt out of the survey`, `my friend wants to opt out` | words, not negation or person |
| `please opt me out of this survey for me` | price of catching `opt me out` |
| `stop payment request`, `stop by the office`, `the bus stop is near` | `stop` fires anywhere |
| `cancel my filing later`, `I quit the gym` | same for `cancel`/`quit` |

Every missed/false shape above can be moved with one word between the whole-message and
anywhere tiers, or one phrase added/removed. Tell me which, if any.

## Part 7 — Write down the outcome

Create `docs/DEPLOY.md` in the repo with: subdomain, document root, PHP version + extensions,
database name/user, deployed commit, the Part 2 check results, the subscription date, and each
of the eleven Part 5 lines marked pass/fail with what was seen. Then:

```bash
git add docs/DEPLOY.md && git commit -m "docs: deploy and live acceptance record"
```

Technical notes for whoever debugs later:

- No backfill may hand a stored `wa_webhook_events.changes` item straight to
  `ProcessInboundEvent`: the encrypted round trip returns timestamps as strings and the job
  is typed — re-parse through `WebhookParser` instead.
- A rejected signature now logs `wa webhook: rejected invalid signature` (warning, no body).
- Queue drain: `queue:work --stop-when-empty --max-time=50` every minute via the scheduler,
  `withoutOverlapping`. If replies stop appearing, check the cron first, then `jobs` table.
