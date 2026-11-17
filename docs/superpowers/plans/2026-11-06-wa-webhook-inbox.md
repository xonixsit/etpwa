# WhatsApp Inbound Inbox + Webhook Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Receive Meta's WhatsApp Cloud API webhooks for +1 224-214-4261, show a replyable inbox, auto-handle STOP and a first-reply acknowledgement, and publish a suppression list that the existing send bench must consult before sending anything.

**Architecture:** One small Laravel 12 app on its own subdomain with its own MySQL database. A signature-verified webhook endpoint stores the event and returns 200 immediately; a queued job normalises each parsed item into `wa_contacts` / `wa_messages` rows and applies two narrow auto-reply rules through one send service. An authenticated inbox reads those rows and replies through the same service. Two bearer-token machine endpoints expose the suppression list and accept send records from the Python bench, which becomes fail-closed: no suppression list, no send.

**Tech Stack:** PHP 8.2.12 local / 8.2.35 on host, Laravel Framework 12.71.0, PHPUnit 11.5.57, Guzzle 7.16.1 (already installed), MariaDB 10.4.32 local (`C:\xampp\mysql`), MySQL on Plesk, Python 3 stdlib for the bench.

**Spec:** `docs/superpowers/specs/2026-11-06-wa-webhook-inbox-design.md`

## Global Constraints

- Work in `D:\etp-wa-inbox` (bash: `/d/etp-wa-inbox`). Git is initialised; commit after every task.
- **No new Composer packages.** `laravel/framework` 12.71.0, `phpunit/phpunit` 11.5.57 and `guzzlehttp/guzzle` 7.16.1 are already installed (measured with `vendor/bin/phpunit --version`; the plan said 11.5.50 until R49). Never run `composer require`.
- The app runs on MySQL/MariaDB. **Tests run on sqlite `:memory:`** (`phpunit.xml` already sets `DB_CONNECTION=sqlite`). Every migration must be valid on MariaDB 10.4, MySQL 8 and sqlite: no `enum` columns, no MySQL-8-only syntax, no functional indexes, `longText` for encrypted columns.
- Secrets (`WA_ACCESS_TOKEN`, `WA_APP_SECRET`, `WA_VERIFY_TOKEN`, `WA_EXPORT_TOKEN`) live in `.env` only (already gitignored). Never echo one into a response, a log line, a test fixture, or chat. Test values are the literal strings `appsecret-test`, `verify-test`, `export-test`.
- Message body text and stored payloads are encrypted with Laravel's `encrypted` cast, so sqlite's `text` columns are used for those columns in tests and `longText` in migrations.
- Recipient phone numbers are stored as E.164 digits with no `+`, no spaces: `18479043315`.
- Process only `metadata.phone_number_id` equal to config `whatsapp.phone_id` (env `WA_PHONE_ID`, value `1427706937088002`). Everything else is recorded as `ignored` and creates no contact and no message.
- The webhook POST returns `200` with an empty body for any request that passes the signature check, even when parsing fails. Non-2xx makes Meta retry and eventually drop the subscription.
- Exactly two automatic sends exist, both marked `auto_reply = true`: the opt-out confirmation and the first-inbound acknowledgement. No keyword answers, no fallback reply.
- Never send anything automatically to a contact whose `opted_out_at` is set, from any code path.
- The bench refusal message when the suppression list is unreachable must contain the words `refused` and `suppression`.
- Copy rules: "E Tax Planner" only. "My Super Tax" must not appear in any code, view, fixture, seed or comment.
- Windows console is cp1252 — do not print `→` or `₹` from PHP/Python; ASCII in command output.
- Run one test file at a time: `php artisan test tests/Feature/WebhookIngestTest.php` (avoid `--filter` with bracket syntax on Windows).
- **R36 -- code in this plan is only trustworthy once it has been parsed.** Ten lines across Tasks 6, 7
  and 12 shipped an `Http::fake(['*' => Http::response([...])])` literal one bracket short
  (`'wamid.OUT9']]), 200)]);`), which is a fatal parse error the moment the block is copied into a test
  file -- and a test file that cannot be parsed reports nothing, so those tasks would have stalled at
  their first run step with no diagnosis. All 39 complete-file `php` blocks in this plan now pass
  `php -l`; the partial (method- and statement-sized) blocks fail only on purpose when wrapped in a class
  body. If a step's code does not parse, the plan is wrong: say so and fix the plan text, do not silently
  repair the code.

## File Structure

| file | responsibility |
| --- | --- |
| `config/whatsapp.php` | every WA setting read from env, one place |
| `database/migrations/*_create_wa_tables.php` | 3 tables: `wa_contacts`, `wa_messages`, `wa_webhook_events` |
| `app/Models/WaContact.php` | who we may talk to; `optOut()`, `windowOpen()`, `display_name()` |
| `app/Models/WaMessage.php` | one message or status, deduped by `meta_id` |
| `app/Models/WaWebhookEvent.php` | raw callback audit row |
| `database/factories/WaContactFactory.php`, `WaMessageFactory.php` | test data |
| `app/Support/WebhookSignature.php` | `sha256=` HMAC verify, constant time |
| `app/Support/WebhookParser.php` | Meta payload → typed items, filters other phone numbers |
| `app/Support/OptOutKeywords.php` | STOP-family matching, text and button id |
| `app/Jobs/ProcessInboundEvent.php` | turn one parsed item into rows |
| `app/Services/WhatsAppSender.php` | the only Graph write path; text + template, records the outbound row |
| `app/Services/AutoResponder.php` | the two automatic rules and nothing else |
| `app/Http/Controllers/WaWebhookController.php` | GET challenge, POST ingest |
| `app/Http/Controllers/MachineController.php` | `wa.bearer` endpoints: suppressions, record-send |
| `app/Http/Controllers/InboxController.php` | contacts list, thread, reply, template, opt-out |
| `app/Http/Controllers/Auth/LoginController.php` | minimal staff login/logout |
| `app/Http/Middleware/VerifyWaBearer.php` | bearer token guard |
| `app/Console/Commands/WaCreateUser.php` | seed the one staff account |
| `app/Console/Commands/WaImportSendLog.php` | `send_log.csv` → rows, dry-run by default |
| `app/Console/Commands/WaPruneEvents.php` | 30-day retention, scheduled |
| `resources/views/layout.blade.php`, `auth/login.blade.php`, `inbox/contacts.blade.php`, `inbox/thread.blade.php` | the whole UI |
| `routes/web.php`, `bootstrap/app.php` | routes, CSRF exception, guest redirect, middleware alias, schedule |
| `tests/Feature/*`, `tests/Unit/*`, `tests/Fixtures/*.json` | tests and recorded payload shapes |
| `C:\Users\Hp\Documents\Qoder\2026-10-08\21238e53\sender_server.py` | suppression gate + record-send (Python, Task 15) |

---

## Task 1: Database, env keys, config file

**Deliverable:** the app talks to a real local MariaDB database and `config('whatsapp.*')` returns the env values. No business logic yet.

- [ ] **Step 1: Start the local MariaDB server** (it is installed but not running — nothing listens on 3306). Use the XAMPP Control Panel if it is open, otherwise in a terminal you leave running:

```bash
/c/xampp/mysql/bin/mysqld --defaults-file="C:/xampp/mysql/bin/my.ini" --console
```

Verify:

```bash
/c/xampp/mysql/bin/mysqladmin --protocol=tcp -u root status
```

Expected: `Uptime: ...`. If root has a password from earlier XAMPP use, add `-p` and use it locally; do not put it in chat or in git.

- [ ] **Step 2: Create the database and a dedicated user**

```bash
/c/xampp/mysql/bin/mysql --protocol=tcp -u root -e \
  "CREATE DATABASE IF NOT EXISTS etp_wa_inbox CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; \
   CREATE USER IF NOT EXISTS 'etp_wa'@'localhost' IDENTIFIED BY 'etp_wa_local'; \
   GRANT ALL PRIVILEGES ON etp_wa_inbox.* TO 'etp_wa'@'localhost'; FLUSH PRIVILEGES;"
```

- [ ] **Step 3: Switch `.env` to MySQL and add the WA keys.** Edit `D:\etp-wa-inbox\.env`: `DB_CONNECTION=mysql`, `DB_HOST=127.0.0.1`, `DB_PORT=3306`, `DB_DATABASE=etp_wa_inbox`, `DB_USERNAME=etp_wa`, `DB_PASSWORD=etp_wa_local`, `APP_URL=http://inbox.localhost`. Append this block. Generate each of the three random secrets with `php -r "echo bin2hex(random_bytes(32)) . PHP_EOL;"` and paste them into `.env` there — never into chat:

```
WA_API_BASE=https://graph.facebook.com/v20.0
WA_ACCESS_TOKEN=
WA_PHONE_ID=1427706937088002
WA_WABA_ID=1717463009877473
WA_MY_NUMBER=12242144261
WA_APP_SECRET=
WA_VERIFY_TOKEN=
WA_EXPORT_TOKEN=
WA_TEMPLATE_CHOICES=etp_opener_brand,etp_welcome_c,etp_welcome_a,filing_deadline_reminder
WA_HEADER_IMAGE_ETP_OPENER_BRAND=https://www.etaxplanner.com/assets/images/logo/etaxplanner_logo.png
WA_ACK_TEXT=Thanks for your message. A human from E Tax Planner will reply here within one business day.
WA_OPT_OUT_TEXT=You will not receive any more messages from E Tax Planner on this number.
```

`WA_ACCESS_TOKEN` stays empty in `.env` for now. The local app does not need it until a live send is tested; it is filled at deploy time by the account owner, from the same token file the bench reads.

- [ ] **Step 4: Create `config/whatsapp.php`**

```php
<?php

return [
    'api_base' => env('WA_API_BASE', 'https://graph.facebook.com/v20.0'),
    'token' => env('WA_ACCESS_TOKEN', ''),
    'phone_id' => env('WA_PHONE_ID', ''),
    'waba_id' => env('WA_WABA_ID', ''),
    'my_number' => env('WA_MY_NUMBER', ''),
    'app_secret' => env('WA_APP_SECRET', ''),
    'verify_token' => env('WA_VERIFY_TOKEN', ''),
    'export_token' => env('WA_EXPORT_TOKEN', ''),
    'template_choices' => array_values(array_filter(array_map('trim',
        explode(',', (string) env('WA_TEMPLATE_CHOICES', ''))))),
    'header_images' => [
        'etp_opener_brand' => env('WA_HEADER_IMAGE_ETP_OPENER_BRAND', ''),
    ],
    'ack_text' => env('WA_ACK_TEXT', ''),
    'opt_out_text' => env('WA_OPT_OUT_TEXT', ''),
    'window_hours' => 24,
    'event_retention_days' => 30,
];
```

- [ ] **Step 5: Run the framework migrations against MySQL and confirm the connection**

```bash
php artisan migrate --force
php artisan db:table users --json | head -c 200
```

Expected: `Migrations ... RUNNING` then `DONE` for the three default tables; `users` reported from `etp_wa_inbox`, i.e. the app is no longer on sqlite.

- [ ] **Step 6: Make the suite independent of the developer's `.env`.** Laravel still loads `.env`
under test (`phpunit.xml` sets `APP_ENV=testing`, and with no `.env.testing` present the framework
falls back to `.env`), so any `WA_*` key Step 3 put there is visible to every test. Add this block
inside the existing `<php>` element of `phpunit.xml`, after the `NIGHTWATCH_ENABLED` line. Leave
every existing line — especially `DB_CONNECTION`, `DB_DATABASE`, `QUEUE_CONNECTION`, `CACHE_STORE` —
exactly as it is:

```xml
        <env name="WA_ACCESS_TOKEN" value=""/>
        <env name="WA_APP_SECRET" value=""/>
        <env name="WA_VERIFY_TOKEN" value=""/>
        <env name="WA_EXPORT_TOKEN" value=""/>
        <env name="WA_PHONE_ID" value=""/>
        <env name="WA_MY_NUMBER" value=""/>
        <env name="WA_TEMPLATE_CHOICES" value=""/>
        <env name="WA_HEADER_IMAGE_ETP_OPENER_BRAND" value=""/>
        <env name="WA_ACK_TEXT" value=""/>
        <env name="WA_OPT_OUT_TEXT" value=""/>
```

Every later test that needs a value sets it with `config([...])` in its own `setUp`, so blanking the
environment costs nothing and makes the assertions below test `config/whatsapp.php`'s **defaults**
rather than whatever happens to be in one developer's `.env`. `APP_KEY` is deliberately not listed:
it keeps coming from `.env`, which the encrypted-column tests in Task 2 need.

- [ ] **Step 7: Add a config smoke test** — `tests/Feature/ConfigTest.php`

```php
<?php

namespace Tests\Feature;

use Tests\TestCase;

class ConfigTest extends TestCase
{
    public function test_whatsapp_settings_have_safe_defaults()
    {
        config(['whatsapp.app_secret' => 'appsecret-test']);

        $this->assertSame('appsecret-test', config('whatsapp.app_secret'));
        $this->assertSame('https://graph.facebook.com/v20.0', config('whatsapp.api_base'));
        $this->assertSame(24, config('whatsapp.window_hours'));
        $this->assertSame(30, config('whatsapp.event_retention_days'));
    }

    public function test_no_credential_is_shipped_inside_config()
    {
        $this->assertSame('', (string) config('whatsapp.token'),
            'the access token is never set in config/whatsapp.php');
        $this->assertSame([], config('whatsapp.template_choices'),
            'with no WA_TEMPLATE_CHOICES in the environment the parsed list is empty');
        $this->assertIsArray(config('whatsapp.header_images'));
    }
}
```

`template_choices` is empty under test because Step 6 blanks `WA_TEMPLATE_CHOICES`; the parsing itself is proved in Task 12, where the test sets the list explicitly.

- [ ] **Step 8: Run it**

```bash
php artisan test tests/Feature/ConfigTest.php
```

Expected: 2 passed.

- [ ] **Step 9: Record the key names in `.env.example`.** Task 16 deploys with
`cp .env.example .env` and then fills every value "including the WA_ block", so the tracked example
must carry the same key names Step 3 added to `.env`. Append the block with the non-secret values
filled and the four secrets left empty:

```
WA_API_BASE=https://graph.facebook.com/v20.0
WA_ACCESS_TOKEN=
WA_PHONE_ID=1427706937088002
WA_WABA_ID=1717463009877473
WA_MY_NUMBER=12242144261
WA_APP_SECRET=
WA_VERIFY_TOKEN=
WA_EXPORT_TOKEN=
WA_TEMPLATE_CHOICES=etp_opener_brand,etp_welcome_c,etp_welcome_a,filing_deadline_reminder
WA_HEADER_IMAGE_ETP_OPENER_BRAND=https://www.etaxplanner.com/assets/images/logo/etaxplanner_logo.png
WA_ACK_TEXT=Thanks for your message. A human from E Tax Planner will reply here within one business day.
WA_OPT_OUT_TEXT=You will not receive any more messages from E Tax Planner on this number.
```

Also set `DB_CONNECTION=mysql`, `DB_HOST=127.0.0.1`, `DB_PORT=3306`, `DB_DATABASE=etp_wa_inbox`,
`DB_USERNAME=etp_wa`, `DB_PASSWORD=` in `.env.example` so the production shape is visible. No secret
value goes in this file — it is tracked.

- [ ] **Step 10: Commit**

```bash
git add config/whatsapp.php tests/Feature/ConfigTest.php phpunit.xml .env.example
git commit -m "feat: MySQL connection, whatsapp config, env keys"
```

---

## Task 2: Tables, models, factories

**Deliverable:** the three tables exist and are portable; `WaContact` owns opt-out and the 24-hour window; `WaMessage` dedupes on `meta_id`; bodies are unreadable in raw storage.

**Produces:** `WaContact::fromPhone(string): WaContact`, `->optOut(string): void`, `->isOptedOut(): bool`, `->windowOpen(): bool`, `->display_name(): string`, `->messages()`; `WaMessage::record(array): WaMessage`, constants `WaMessage::IN` / `::OUT`; `WaWebhookEvent::store(...)` and the four status constants.

- [ ] **Step 1: Write the failing test** — `tests/Feature/WaTablesTest.php`

```php
<?php

namespace Tests\Feature;

use App\Models\WaContact;
use App\Models\WaMessage;
use App\Models\WaWebhookEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WaTablesTest extends TestCase
{
    use RefreshDatabase;

    public function test_phone_is_unique_and_opt_out_is_idempotent()
    {
        $a = WaContact::fromPhone('+1 (847) 904-3315');
        $b = WaContact::fromPhone('18479043315');
        $this->assertTrue($a->is($b), 'digits must normalise to the same row');
        $this->assertSame('18479043315', $a->phone);
        $this->assertFalse($a->isOptedOut());
        $this->assertFalse($a->windowOpen());

        $a->optOut('manual');
        $this->assertTrue($a->fresh()->isOptedOut());

        $a->optOut('inbound_keyword');
        $this->assertSame('manual', $a->fresh()->opt_out_source,
            'the first reason must win');
    }

    public function test_window_opens_for_a_recent_inbound_and_closes_after_24_hours()
    {
        $recent = WaContact::factory()->create(['last_inbound_at' => now()->subHour()]);
        $this->assertTrue($recent->windowOpen(),
            'an inbound inside the 24-hour window must open it');

        $stale = WaContact::factory()->create(['last_inbound_at' => now()->subHours(25)]);
        $this->assertFalse($stale->windowOpen(),
            'a 25-hour-old inbound must close it');

        $edge = WaContact::factory()->create(['last_inbound_at' => now()->subHours(24)]);
        $this->assertFalse($edge->windowOpen(),
            'exactly 24 hours ago is already closed, not still open');

        config(['whatsapp.window_hours' => 48]);
        $this->assertTrue($stale->fresh()->windowOpen(),
            'the window must come from config, not a hardcoded 24');
    }

    public function test_message_record_dedupes_on_meta_id_and_encrypts_body()
    {
        $c = WaContact::factory()->create();
        $m = WaMessage::record([
            'wa_contact_id' => $c->id, 'meta_id' => 'wamid.AAA',
            'direction' => WaMessage::IN, 'type' => 'text',
            'body' => 'My SSN is 123-45-6789', 'sent_at' => now(),
        ]);
        $again = WaMessage::record([
            'wa_contact_id' => $c->id, 'meta_id' => 'wamid.AAA',
            'direction' => WaMessage::IN, 'type' => 'text',
            'body' => 'My SSN is 123-45-6789', 'sent_at' => now(),
        ]);
        $this->assertSame(1, WaMessage::count());
        $this->assertTrue($m->is($again));
        $this->assertSame('My SSN is 123-45-6789', $m->fresh()->body);
        $raw = \DB::table('wa_messages')->value('body');
        $this->assertStringNotContainsString('SSN', $raw, 'column must hold ciphertext');

        $m2 = WaMessage::record([
            'wa_contact_id' => $c->id, 'meta_id' => null,
            'direction' => WaMessage::OUT, 'type' => 'text',
            'body' => 'no id', 'sent_at' => now(),
        ]);
        $this->assertNotNull($m2->id);
    }

    public function test_event_row_stores_and_decrypts_changes()
    {
        $e = WaWebhookEvent::store('1427706937088002', 'E1', ['x' => 1], WaWebhookEvent::STORED);
        $this->assertSame(['x' => 1], $e->fresh()->changes);
        $this->assertSame(WaWebhookEvent::STORED, $e->status);
    }
}
```

- [ ] **Step 2: Run it to confirm it fails**

```bash
php artisan test tests/Feature/WaTablesTest.php
```

Expected: FAIL — `Class "App\Models\WaContact" not found`.

- [ ] **Step 3: Migration** — `database/migrations/2026_11_06_000001_create_wa_tables.php`

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wa_contacts', function (Blueprint $table) {
            $table->id();
            $table->string('phone', 20)->unique();
            $table->string('profile_name')->nullable();
            $table->timestamp('opted_out_at')->nullable();
            $table->string('opt_out_source', 20)->nullable();
            $table->timestamp('last_inbound_at')->nullable();
            $table->timestamps();
            $table->index('last_inbound_at');
        });

        Schema::create('wa_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('wa_contact_id')->constrained()->cascadeOnDelete();
            $table->string('meta_id', 128)->nullable();
            $table->string('direction', 3);
            $table->string('type', 24)->default('text');
            $table->longText('body')->nullable();
            $table->string('template_name')->nullable();
            $table->string('template_lang', 10)->nullable();
            $table->string('status', 24)->nullable();
            $table->timestamp('status_at')->nullable();
            $table->string('error_code', 12)->nullable();
            $table->string('error_title')->nullable();
            $table->boolean('auto_reply')->default(false);
            $table->timestamp('sent_at')->useCurrent();
            $table->longText('raw')->nullable();
            $table->timestamps();
            $table->index(['wa_contact_id', 'sent_at']);
            $table->index(['direction', 'status']);
        });

        Schema::create('wa_webhook_events', function (Blueprint $table) {
            $table->id();
            $table->timestamp('received_at')->useCurrent();
            $table->string('entry_id', 64)->nullable();
            $table->string('phone_number_id', 64)->nullable();
            $table->longText('changes')->nullable();
            $table->string('signature_valid', 12)->default('unchecked');
            $table->string('status', 20)->default('stored');
            $table->timestamps();
            $table->index(['received_at', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wa_messages');
        Schema::dropIfExists('wa_webhook_events');
        Schema::dropIfExists('wa_contacts');
    }
};
```

Note the deliberate absence of `enum` and of a unique index on `meta_id` — `meta_id` is nullable and deduped in code (`WaMessage::record`), which keeps the table portable and still idempotent for Meta's retries.

Both NOT NULL timestamps carry `->useCurrent()`, and that is load-bearing, not decoration. MariaDB 10.4 runs with `explicit_defaults_for_timestamp=0`, where the FIRST TIMESTAMP column of a table gets `DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP` automatically only if it is NOT NULL with no explicit default. In `wa_messages` the first TIMESTAMP is the nullable `status_at`, so the auto-default never reaches `sent_at`; `sent_at` then inherits the implicit zero-date default, which Laravel's strict session (`NO_ZERO_DATE` + `STRICT_TRANS_TABLES`) rejects at CREATE TABLE time with error 1067. An explicit `DEFAULT CURRENT_TIMESTAMP` fixes it and is valid on MySQL 8 and sqlite too. In `wa_webhook_events`, `received_at` IS the first TIMESTAMP, so the bare form creates successfully but silently acquires `ON UPDATE CURRENT_TIMESTAMP` — any later UPDATE of an event row would rewrite the arrival time and defeat Task 14's 30-day retention prune. Supplying `->useCurrent()` explicitly suppresses that auto-`ON UPDATE`. Verified by probe on MariaDB 10.4.32: bare `sent_at` fails 1067; `useCurrent()` on both columns creates cleanly with an empty `Extra` (no `ON UPDATE`) on each. Every code path sets both columns explicitly, so the defaults are inert in practice. The three nullable timestamps need nothing.

- [ ] **Step 4: `app/Models/WaContact.php`**

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WaContact extends Model
{
    use HasFactory;

    protected $fillable = [
        'phone', 'profile_name', 'opted_out_at', 'opt_out_source', 'last_inbound_at',
    ];

    protected function casts(): array
    {
        return ['opted_out_at' => 'datetime', 'last_inbound_at' => 'datetime'];
    }

    public static function digitsOnly(?string $raw): string
    {
        return preg_replace('/\D/', '', (string) $raw);
    }

    public static function fromPhone(string $raw): self
    {
        $phone = self::digitsOnly($raw);
        if ($phone === '' || strlen($phone) < 10) {
            throw new \InvalidArgumentException("not an E.164 number: {$raw}");
        }

        return self::firstOrCreate(['phone' => $phone]);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(WaMessage::class)->orderBy('sent_at');
    }

    public function isOptedOut(): bool
    {
        return $this->opted_out_at !== null;
    }

    public function optOut(string $source): void
    {
        if ($this->isOptedOut()) {
            return;
        }
        $this->update(['opted_out_at' => now(), 'opt_out_source' => $source]);
    }

    public function windowOpen(): bool
    {
        $hours = (int) config('whatsapp.window_hours', 24);

        return $this->last_inbound_at !== null
            && $this->last_inbound_at->addHours($hours)->isFuture();
    }

    public function display_name(): string
    {
        return $this->profile_name !== '' && $this->profile_name !== null
            ? $this->profile_name
            : '+' . $this->phone;
    }
}
```

- [ ] **Step 5: `app/Models/WaMessage.php`**

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WaMessage extends Model
{
    use HasFactory;

    public const IN = 'in';
    public const OUT = 'out';

    public const SENT = 'sent';
    public const DELIVERED = 'delivered';
    public const READ = 'read';
    public const FAILED = 'failed';
    public const INCOMING = 'incoming';

    protected $fillable = [
        'wa_contact_id', 'meta_id', 'direction', 'type', 'body', 'template_name',
        'template_lang', 'status', 'status_at', 'error_code', 'error_title',
        'auto_reply', 'sent_at', 'raw',
    ];

    protected function casts(): array
    {
        return [
            'body' => 'encrypted',
            'raw' => 'encrypted:array',
            'status_at' => 'datetime',
            'sent_at' => 'datetime',
            'auto_reply' => 'boolean',
        ];
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(WaContact::class, 'wa_contact_id');
    }

    public static function record(array $attrs): self
    {
        $id = $attrs['meta_id'] ?? null;
        if ($id !== null && $id !== '') {
            return self::updateOrCreate(['meta_id' => $id], $attrs);
        }

        return self::create($attrs);
    }

    public static function findByMetaId(?string $id): ?self
    {
        if ($id === null || $id === '') {
            return null;
        }

        return self::where('meta_id', $id)->first();
    }
}
```

**R37 -- a relation must name its key when the method name is not the column.** As first written,
`contact()` called `belongsTo(WaContact::class)`. `HasRelationships::belongsTo()` derives the foreign key
from the *calling method's* name (`Str::snake($relation).'_'.$instance->getKeyName()`), so it asked for
`contact_id`; the column is `wa_contact_id`. Nothing in Tasks 2-5 ever read the relation -- grep for
`->contact` returned zero hits -- which is how a broken schema relation survived four tasks of green
tests, and Task 11's thread view would have been the first to meet it. Found during Task 6 by the
implementer and reproduced by the controller. The failure mode is the reason it is worth a ruling:
`BelongsTo::getResults()` begins with `if (is_null($this->getForeignKeyFrom($this->child))) { return
$this->getDefaultFor($this->parent); }`, and `$this->child->contact_id` is null because no such attribute
exists -- so it returns null **without issuing a query**. There is no `no such column: contact_id`, no
warning, nothing in the log; the caller simply gets `Call to a member function is() on null`. Measured:
with the second argument removed, `WaTablesTest::test_a_message_can_reach_the_contact_whose_row_holds_it`
fails (1 failed, 4 passed) at its first assertion; with it, 5 passed. A silently null relation is the most
dangerous shape a relation can have, because every consumer decides its own fallback. The reverse
relation is not affected: `hasMany()` derives its key from the *parent model's* name, so
`WaContact::messages()` already resolves to `wa_contact_id` -- asserted in the same test. The test is the
pin, added to `tests/Feature/WaTablesTest.php` above `test_event_row_stores_and_decrypts_changes`:

```php
    public function test_a_message_can_reach_the_contact_whose_row_holds_it()
    {
        // R37: belongsTo() guesses the key from the method name, so contact() asked for a column
        // named contact_id that does not exist. Nothing read the relation until Task 11's thread
        // view, which is exactly how a broken schema relation survives four tasks of green tests.
        $c = WaContact::factory()->create(['phone' => '18479043315']);
        $m = WaMessage::record([
            'wa_contact_id' => $c->id, 'meta_id' => 'wamid.REL1',
            'direction' => WaMessage::IN, 'type' => 'text', 'body' => 'hi', 'sent_at' => now(),
        ]);

        $this->assertTrue($m->contact->is($c));
        $this->assertSame('18479043315', $m->fresh()->contact->phone);
        $this->assertTrue($c->fresh()->messages->contains($m));
    }
```

- [ ] **Step 6: `app/Models/WaWebhookEvent.php`**

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WaWebhookEvent extends Model
{
    public const STORED = 'stored';
    public const DUPLICATE = 'duplicate';
    public const IGNORED = 'ignored';
    public const REJECTED = 'rejected_signature';

    protected $fillable = [
        'received_at', 'entry_id', 'phone_number_id', 'changes', 'signature_valid', 'status',
    ];

    protected function casts(): array
    {
        return ['received_at' => 'datetime', 'changes' => 'encrypted:array'];
    }

    public static function store(?string $phoneNumberId, ?string $entryId, array $changes, string $status): self
    {
        return self::create([
            'received_at' => now(),
            'entry_id' => $entryId,
            'phone_number_id' => $phoneNumberId,
            'changes' => $changes,
            'status' => $status,
        ]);
    }
}
```

- [ ] **Step 7: Factories** — `database/factories/WaContactFactory.php` and `WaMessageFactory.php`

```php
<?php

namespace Database\Factories;

use App\Models\WaContact;
use Illuminate\Database\Eloquent\Factories\Factory;

class WaContactFactory extends Factory
{
    protected $model = WaContact::class;

    public function definition(): array
    {
        return [
            'phone' => '1' . $this->faker->unique()->numerify('##########'),
            'profile_name' => $this->faker->name(),
        ];
    }

    public function optedOut(): static
    {
        return $this->state(fn () => [
            'opted_out_at' => now(), 'opt_out_source' => 'inbound_keyword',
        ]);
    }
}
```

```php
<?php

namespace Database\Factories;

use App\Models\WaContact;
use App\Models\WaMessage;
use Illuminate\Database\Eloquent\Factories\Factory;

class WaMessageFactory extends Factory
{
    protected $model = WaMessage::class;

    public function definition(): array
    {
        return [
            'wa_contact_id' => WaContact::factory(),
            'meta_id' => 'wamid.' . $this->faker->unique()->md5(),
            'direction' => WaMessage::IN,
            'type' => 'text',
            'body' => $this->faker->sentence(),
            'status' => WaMessage::INCOMING,
            'sent_at' => now(),
        ];
    }

    public function out(): static
    {
        return $this->state(fn () => [
            'direction' => WaMessage::OUT, 'status' => WaMessage::SENT,
        ]);
    }
}
```

- [ ] **Step 8: Register the factories** — add to the existing `Database\Seeders\DatabaseSeeder` is not needed; Laravel resolves `database/factories` automatically. Confirm resolution:

```bash
php artisan test tests/Feature/WaTablesTest.php
```

Expected: FAIL until Step 9, then PASS. (Step 3-7 must be done; if the factory is not found the message is `No factory found for class App\Models\WaContact` — fix by checking the namespace is `Database\Factories` and the class name is exactly `WaContactFactory`.)

- [ ] **Step 9: Run `php artisan migrate --force`, then the test again.** Expected: 4 passed as written
      here; 5 once R37's relation test (Task 6's round) is in the file.

- [ ] **Step 10: Commit**

```bash
git add database/migrations app/Models database/factories tests/Feature/WaTablesTest.php
git commit -m "feat: wa_contacts, wa_messages, wa_webhook_events with encrypted bodies"
```

---

## Task 3: Webhook signature and GET verification

**Deliverable:** `WebhookSignature::isValid()` rejects missing, wrong, malformed and unsigned callbacks; the GET challenge echoes only for the right token.

**Consumes:** `config('whatsapp.app_secret')`, `config('whatsapp.verify_token')`.
**Produces:** `App\Support\WebhookSignature::isValid(string $rawBody, ?string $header, string $secret): bool`; `GET /wa/webhook`.

- [ ] **Step 1: Write the failing unit test** — `tests/Unit/WebhookSignatureTest.php`

```php
<?php

namespace Tests\Unit;

use App\Support\WebhookSignature;
use PHPUnit\Framework\TestCase;

class WebhookSignatureTest extends TestCase
{
    private const SECRET = 'appsecret-test';

    private function body(): string
    {
        return '{"object":"whatsapp_business_account","entry":[]}';
    }

    private function good(): string
    {
        return 'sha256=' . hash_hmac('sha256', $this->body(), self::SECRET);
    }

    public function test_accepts_a_correct_sha256_header()
    {
        $this->assertTrue(WebhookSignature::isValid($this->body(), $this->good(), self::SECRET));
    }

    public function test_rejects_missing_header()
    {
        $this->assertFalse(WebhookSignature::isValid($this->body(), null, self::SECRET));
    }

    public function test_rejects_wrong_prefix()
    {
        $sha1 = 'sha1=' . hash_hmac('sha1', $this->body(), self::SECRET);
        $this->assertFalse(WebhookSignature::isValid($this->body(), $sha1, self::SECRET));
    }

    public function test_rejects_tampered_body()
    {
        $this->assertFalse(WebhookSignature::isValid($this->body() . ' ', $this->good(), self::SECRET));
    }

    public function test_rejects_empty_secret()
    {
        $this->assertFalse(WebhookSignature::isValid($this->body(), $this->good(), ''));
    }

    public function test_rejects_non_hex_garbage()
    {
        $this->assertFalse(WebhookSignature::isValid($this->body(), 'sha256=ZZZZ', self::SECRET));
    }
}
```

- [ ] **Step 2: Run it**

```bash
php artisan test tests/Unit/WebhookSignatureTest.php
```

Expected: FAIL — `Class "App\Support\WebhookSignature" not found`.

- [ ] **Step 3: Implement `app/Support/WebhookSignature.php`**

```php
<?php

namespace App\Support;

class WebhookSignature
{
    public static function isValid(string $rawBody, ?string $header, string $secret): bool
    {
        if ($secret === '' || $header === null || !str_starts_with($header, 'sha256=')) {
            return false;
        }

        $given = strtolower(substr($header, 7));
        if (!preg_match('/^[0-9a-f]{64}$/', $given)) {
            return false;
        }

        return hash_equals(hash_hmac('sha256', $rawBody, $secret), $given);
    }
}
```

- [ ] **Step 4: Run it again.** Expected: 6 passed.

- [ ] **Step 5: Write the failing feature test** — `tests/Feature/WebhookVerifyTest.php`

```php
<?php

namespace Tests\Feature;

use Tests\TestCase;

class WebhookVerifyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['whatsapp.verify_token' => 'verify-test']);
    }

    public function test_echoes_the_challenge_for_the_right_token()
    {
        $this->get('/wa/webhook?hub.mode=subscribe&hub.verify_token=verify-test&hub.challenge=chk123')
            ->assertOk()
            ->assertContent('chk123');
    }

    public function test_rejects_a_wrong_token_with_403_and_no_challenge()
    {
        $this->get('/wa/webhook?hub.mode=subscribe&hub.verify_token=nope&hub.challenge=chk123')
            ->assertForbidden()
            ->assertDontSee('chk123');
    }

    public function test_rejects_when_verify_token_is_not_configured()
    {
        config(['whatsapp.verify_token' => '']);
        $this->get('/wa/webhook?hub.mode=subscribe&hub.verify_token=&hub.challenge=chk123')
            ->assertForbidden();
    }

    public function test_a_hub_parameter_supplied_as_an_array_is_rejected_not_fatal()
    {
        // PHP's query parser happily produces array-valued parameters, and the
        // handler reads two spellings that both mangle to the same underscore key.
        // Casting an array to string raises a warning, which Laravel promotes to an
        // ErrorException, so an unauthenticated request would answer 500. A public
        // endpoint must answer 403 instead (Ruling R17).
        $this->get('/wa/webhook?hub_verify_token[]=x&hub_challenge=chk789')
            ->assertForbidden()
            ->assertDontSee('chk789');

        $this->get('/wa/webhook?hub.verify_token[a]=1&hub.challenge[a]=2')
            ->assertForbidden();
    }

    public function test_a_malformed_challenge_is_refused_even_when_the_token_is_valid()
    {
        // The second half of Ruling R17, and the half the array tests above cannot reach:
        // their token is already invalid, so they abort at the guard before the challenge
        // is ever read. Here the caller is authorised and the fatal would happen while
        // BUILDING the success response. An empty challenge means there is nothing to
        // echo, so the handshake has failed and a refusal is the only honest answer --
        // answering 200 would tell an operator the verification succeeded. Reverting
        // this one line to the old inline cast makes this the test that notices.
        $this->get('/wa/webhook?hub.mode=subscribe&hub.verify_token=verify-test&hub.challenge[]=9')
            ->assertForbidden();
    }

    public function test_accepts_the_dotted_spelling_when_a_runtime_preserves_it()
    {
        // Parameters passed as an array go straight into the query bag, bypassing the
        // parser that rewrites dots, so this simulates a runtime (a PSR-7 bridge) that
        // keeps Meta's literal spelling. Without it the dotted half of the dual read is
        // a claim no test ever exercises.
        $this->call('GET', '/wa/webhook', [
            'hub.mode' => 'subscribe',
            'hub.verify_token' => 'verify-test',
            'hub.challenge' => 'chk-dot',
        ])->assertOk()->assertContent('chk-dot');
    }
}
```

- [ ] **Step 6: Run it** — expected FAIL `OK (0)` / route not found (404).

- [ ] **Step 7: Create `app/Http/Controllers/WaWebhookController.php`** (POST is added in Task 4; only the GET branch exists now)

```php
<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;

class WaWebhookController extends Controller
{
    public function verify(Request $request): Response
    {
        $token = (string) config('whatsapp.verify_token', '');
        $sent = $this->param($request, 'hub.verify_token', 'hub_verify_token');

        if ($token === '' || !hash_equals($token, $sent)) {
            return response('forbidden', 403);
        }

        $challenge = $this->param($request, 'hub.challenge', 'hub_challenge');

        if ($challenge === '') {
            return response('forbidden', 403);
        }

        return response($challenge, 200);
    }

    /**
     * Meta spells these parameters with a dot; PHP rewrites every
     * non-alphanumeric byte in a query-string key to an underscore before Laravel
     * sees it, so both spellings must be read and the dotted one wins when present.
     * Do not collapse this to one lookup (Ruling R16). A non-string is treated as
     * absent so that array-shaped query input is refused rather than fatal
     * (Ruling R17).
     */
    private function param(Request $request, string $dotted, string $underscore): string
    {
        $value = $request->query($dotted) ?? $request->query($underscore);

        return is_string($value) ? $value : '';
    }
}
```

**Read the query string under BOTH spellings, and do not "simplify" this back to the
dotted name alone.** Meta sends `hub.mode`/`hub.verify_token`/`hub.challenge` with
literal dots, but PHP rewrites every non-alphanumeric byte in a query-string *key*
to `_` while populating `$_GET`, so what Laravel actually receives is
`hub_verify_token`. Probed on the `cli-server` SAPI under PHP 8.2.12:
`QUERY_STRING` stays `hub.verify_token=...` while `array_keys($_GET)` comes back
`["hub_mode","hub_verify_token","hub_challenge"]`. The same mangling happens in tests
because Symfony builds the bag with `parse_str`. A controller that reads only the
dotted key answers `403` to every legitimate handshake — in production, not just in
the suite.

The dotted lookup is kept first rather than dropped because `Arr::get` tests the
literal key before it splits on dots (verified: a bag holding
`'hub.verify_token'` resolves, a bag holding only `hub_verify_token` returns `null`),
so the pair covers a runtime that preserves dots, such as a PSR-7 bridge. It is not
dead weight and the order is not arbitrary: dotted first means the spelling Meta
names on the wire wins if a client somehow supplies both.

The GET test below needs no change — it puts the dotted names on the URL, which is
exactly Meta's wire format, so it exercises the real mangling path. It was the
handler that was wrong, not the assertion.

- [ ] **Step 8: Add the route** to `routes/web.php` (after the existing `/` route, which Task 9 replaces):

```php
use App\Http\Controllers\WaWebhookController;

Route::get('/wa/webhook', [WaWebhookController::class, 'verify'])->name('wa.webhook.verify');
```

Task 4 adds `->withoutMiddleware('web')` to this line under Ruling R22; leave it out here,
where nothing has yet established that the route needs to be stateless.

- [ ] **Step 9: Run both test files**

```bash
php artisan test tests/Unit/WebhookSignatureTest.php && php artisan test tests/Feature/WebhookVerifyTest.php
```

Expected: `WebhookSignatureTest` 6 passed; `WebhookVerifyTest` 6 passed; full suite 20 passed.

- [ ] **Step 10: Commit**

```bash
git add app/Support/WebhookSignature.php app/Http/Controllers/WaWebhookController.php routes/web.php tests
git commit -m "feat: webhook signature check and hub.challenge verification"
```

## Task 4: POST ingest — store, verify, dedupe, fast 200

**Deliverable:** a signed POST is stored once, replayed bodies are marked `duplicate`, payloads for another phone number are marked `ignored`, a bad signature is `403` with nothing stored, and the handler still returns `200` when the JSON is unparseable.

**Consumes:** `WebhookSignature::isValid()` (Task 3), `WaWebhookEvent::store()` (Task 2).
**Produces:** `POST /wa/webhook`; `App\Support\WebhookParser::parse(array $payload, string $phoneId): array` returning `['matched' => bool, 'phone_number_id' => ?string, 'items' => [...]]` where each item is one of:

```php
// inbound or outbound message
['kind' => 'message', 'meta_id' => 'wamid.X', 'phone' => '18479043315',
 'direction' => 'in', 'type' => 'text', 'text' => 'Hello', 'button_id' => null,
 'template_name' => null, 'template_lang' => null, 'sent_at' => Carbon, 'raw' => [...]]
// delivery/read/failure receipt for a message we sent
['kind' => 'status', 'meta_id' => 'wamid.X', 'phone' => '18479043315',
 'status' => 'delivered', 'error_code' => null, 'error_title' => null,
 'status_at' => Carbon, 'raw' => [...]]
// sender name Meta supplied with the batch
['kind' => 'profile', 'phone' => '18479043315', 'name' => 'Jane', 'raw' => [...]]
```

- [ ] **Step 1: Fixtures.** Create `tests/Fixtures/inbound_text.json`:

```json
{"object":"whatsapp_business_account","entry":[{"id":"1717463009877473","changes":[{"field":"messages","value":{"messaging_product":"whatsapp","metadata":{"display_phone_number":"12242144261","phone_number_id":"1427706937088002"},"contacts":[{"profile":{"name":"Jane Tester"},"wa_id":"18479043315"}],"messages":[{"from":"18479043315","id":"wamid.INBOUND1","timestamp":"1793996000","type":"text","text":{"body":"Hello, is this E Tax Planner?"}}]}}]}]}
```

Create `tests/Fixtures/replayed_inbound.json` as an exact copy of that file. Create `tests/Fixtures/other_phone_number.json` with the same shape but `"phone_number_id":"9999999999999999"` and `"wa_id":"15550001111"`, `"from":"15550001111"`, `"id":"wamid.OTHER1"`. Create `tests/Fixtures/status_delivered.json`:

```json
{"object":"whatsapp_business_account","entry":[{"id":"1717463009877473","changes":[{"field":"messages","value":{"messaging_product":"whatsapp","metadata":{"display_phone_number":"12242144261","phone_number_id":"1427706937088002"},"statuses":[{"id":"wamid.OUT1","status":"delivered","timestamp":"1793996100","recipient_id":"18479043315"}]}}]}]}
```

- [ ] **Step 2: Write the failing test** — `tests/Feature/WebhookIngestTest.php`

```php
<?php

namespace Tests\Feature;

use App\Models\WaWebhookEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WebhookIngestTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'whatsapp.app_secret' => 'appsecret-test',
            'whatsapp.phone_id' => '1427706937088002',
        ]);
    }

    private function postCallback(string $raw, ?string $sig = 'bad')
    {
        if ($sig === 'bad') {
            $sig = 'sha256=' . hash_hmac('sha256', $raw, 'appsecret-test');
        }

        return $this->call('POST', '/wa/webhook', [], [], [],
            ['HTTP_X_HUB_SIGNATURE_256' => $sig, 'CONTENT_TYPE' => 'application/json'], $raw);
    }

    public function test_valid_signature_returns_200_and_stores_one_event()
    {
        $raw = file_get_contents(base_path('tests/Fixtures/inbound_text.json'));
        $this->postCallback($raw)->assertOk()->assertContent('');
        $this->assertCount(1, WaWebhookEvent::all());
        $this->assertSame(WaWebhookEvent::STORED, WaWebhookEvent::first()->status);
    }

    public function test_bad_signature_is_403_and_stores_nothing()
    {
        $raw = file_get_contents(base_path('tests/Fixtures/inbound_text.json'));
        $this->postCallback($raw, 'sha256=' . str_repeat('0', 64))->assertForbidden();
        $this->assertSame(0, WaWebhookEvent::count());
    }

    public function test_missing_signature_header_is_403()
    {
        $raw = file_get_contents(base_path('tests/Fixtures/inbound_text.json'));
        $this->call('POST', '/wa/webhook', [], [], [],
            ['CONTENT_TYPE' => 'application/json'], $raw)->assertForbidden();
        $this->assertSame(0, WaWebhookEvent::count());
    }

    public function test_identical_replay_gets_its_own_row_marked_duplicate_and_is_not_reprocessed()
    {
        $raw = file_get_contents(base_path('tests/Fixtures/inbound_text.json'));
        $this->postCallback($raw);
        $this->postCallback(file_get_contents(base_path('tests/Fixtures/replayed_inbound.json')));

        $this->assertSame(2, WaWebhookEvent::count());
        $this->assertSame(WaWebhookEvent::STORED, WaWebhookEvent::orderBy('id')->value('status'));
        $this->assertSame(WaWebhookEvent::DUPLICATE, WaWebhookEvent::orderByDesc('id')->value('status'));
    }

    public function test_payload_for_another_phone_number_is_ignored()
    {
        $raw = file_get_contents(base_path('tests/Fixtures/other_phone_number.json'));
        $this->postCallback($raw)->assertOk();
        $this->assertSame(WaWebhookEvent::IGNORED, WaWebhookEvent::first()->status);
    }

    public function test_unparseable_json_returns_200_and_is_ignored()
    {
        $this->postCallback('not json at all')->assertOk();
        $this->assertSame(WaWebhookEvent::IGNORED, WaWebhookEvent::first()->status);
    }

    public function test_a_non_string_entry_id_is_recorded_as_null_instead_of_fatalling()
    {
        // Ruling R23. $payload['entry'][0]['id'] goes straight into store()'s
        // ?string $entryId, so under this app's coercive typing an int survives but an
        // array or object raises a TypeError -- and a TypeError is a 500, the response
        // class that makes Meta retry and then drop the subscription outright. Only a
        // body correctly signed with the app secret reaches this line, so it is
        // hardening rather than a hole, but the cost of getting it wrong is external.
        // Reverting the guard below to the bare lookup makes this the test that notices.
        $raw = str_replace('"id":"1717463009877473"', '"id":["a","b"]',
            file_get_contents(base_path('tests/Fixtures/inbound_text.json')));

        $this->postCallback($raw)->assertOk();
        $this->assertSame(WaWebhookEvent::STORED, WaWebhookEvent::first()->status);
        $this->assertNull(WaWebhookEvent::first()->entry_id);
    }

    public function test_a_hostile_timestamp_is_stored_as_null_instead_of_500ing()
    {
        // Ruling R26. The unit test in WebhookParserTest covers the mechanism; this is the
        // HTTP-level half of the promise, because the whole class of outage is "a 500 to a
        // correctly signed callback". R24's shape normalisation cannot see it: this value is
        // a number, in an array, at the right key -- and past ~1e14 Carbon throws while the
        // (int) cast of a float above PHP_INT_MAX is undefined. Reverting ts()'s bounds to
        // `is_numeric && > 0` makes this answer 500.
        $raw = str_replace('"timestamp":"1793996000"', '"timestamp":1e30',
            file_get_contents(base_path('tests/Fixtures/inbound_text.json')));

        $this->postCallback($raw)->assertOk()->assertContent('');
        $this->assertSame(WaWebhookEvent::STORED, WaWebhookEvent::first()->status);

        $message = collect(WaWebhookEvent::first()->changes)->firstWhere('kind', 'message');
        $this->assertNotNull($message);
        $this->assertNull($message['sent_at']);
    }
}
```

- [ ] **Step 3: Run it**

```bash
php artisan test tests/Feature/WebhookIngestTest.php
```

Expected: FAIL — 405 on the POST, `Class "App\Support\WebhookParser" not found`.

- [ ] **Step 4: Add `body_hash` to the Task 2 migration.** In `database/migrations/2026_11_06_000001_create_wa_tables.php`, inside the `wa_webhook_events` block, after `$table->string('status', 20)->default('stored');` add:

```php
            $table->string('body_hash', 64)->nullable();
            $table->index('body_hash');
```

The index is not unique: Meta's retry of an identical callback is itself an accepted callback, so it earns its own audit row — marked `duplicate` — and a unique constraint there would throw a database error inside the request we must answer with `200`.

Then rebuild the local database (nothing of value is in it yet):

```bash
php artisan migrate:fresh --force
```

- [ ] **Step 5: Extend `WaWebhookEvent`** — add `'body_hash'` to `$fillable`, and replace `store()` with:

```php
    public static function store(?string $phoneNumberId, ?string $entryId, array $changes,
                                 string $status, ?string $bodyHash = null,
                                 string $signatureValid = 'valid'): self
    {
        return self::create([
            'received_at' => now(),
            'entry_id' => $entryId,
            'phone_number_id' => $phoneNumberId,
            'changes' => $changes,
            'status' => $status,
            'body_hash' => $bodyHash,
            'signature_valid' => $signatureValid,
        ]);
    }

    public static function seenBefore(?string $bodyHash): bool
    {
        return $bodyHash !== null && self::where('body_hash', $bodyHash)->exists();
    }
```

- [ ] **Step 6: Implement `app/Support/WebhookParser.php`**

Ruling R24: **every read of the decoded body goes through four normalising helpers, and no
body shape can reach a typed parameter or a `foreach` unnormalised.** The earlier version of
this file checked `is_string` in one place and cast in another, and a throwaway probe against
the committed controller proved five of six hostile shapes answered 500 — array
`phone_number_id`, scalar `entry`, scalar `changes`, scalar message element, array `wa_id`.
Only array `text.body` survived, by accident. See the R24 paragraph after Step 7 for the
evidence and for why normalising at the boundary beats patching the next leak.

```php
<?php

namespace App\Support;

use App\Models\WaContact;
use Carbon\Carbon;
use Carbon\CarbonInterface;

class WebhookParser
{
    /**
     * @return array{matched: bool, entry_id: ?string, phone_number_id: ?string, items: array<int, array<string, mixed>>}
     */
    public static function parse(array $payload, string $phoneId): array
    {
        $items = [];
        $matched = false;
        $seen = null;
        $entryId = null;

        foreach (self::arr($payload['entry'] ?? null) as $entry) {
            $entry = self::arr($entry);

            if ($entryId === null) {
                $entryId = self::nullableStr($entry['id'] ?? null, 64);
            }

            foreach (self::arr($entry['changes'] ?? null) as $change) {
                $value = self::arr(self::arr($change)['value'] ?? null);
                $numberId = self::str(self::path($value, 'metadata', 'phone_number_id'), 64);

                if ($phoneId === '' || $numberId !== $phoneId) {
                    if ($seen === null && $numberId !== '') {
                        $seen = $numberId;
                    }

                    continue;
                }

                $matched = true;

                foreach (self::arr($value['contacts'] ?? null) as $contact) {
                    $items[] = self::profile($contact);
                }

                foreach (self::arr($value['messages'] ?? null) as $message) {
                    $items[] = self::message($message);
                }

                foreach (self::arr($value['statuses'] ?? null) as $status) {
                    $items[] = self::status($status);
                }
            }
        }

        return [
            'matched' => $matched,
            'entry_id' => $entryId,
            'phone_number_id' => $seen,
            'items' => array_values(array_filter($items)),
        ];
    }

    private static function profile(mixed $raw): ?array
    {
        $contact = self::arr($raw);
        $phone = WaContact::digitsOnly(self::str($contact['wa_id'] ?? null));
        $name = self::nullableStr(self::path($contact, 'profile', 'name'));

        if ($phone === '' || $name === null) {
            return null;
        }

        return ['kind' => 'profile', 'phone' => $phone, 'name' => $name, 'raw' => $contact];
    }

    private static function message(mixed $raw): ?array
    {
        $m = self::arr($raw);
        $mine = self::str(config('whatsapp.my_number'));
        $from = self::str($m['from'] ?? null);
        $isOut = $mine !== '' && $from === $mine;
        $phone = WaContact::digitsOnly($isOut ? self::str($m['to'] ?? null) : $from);

        if ($phone === '') {
            return null;
        }

        return [
            'kind' => 'message',
            'meta_id' => self::nullableStr($m['id'] ?? null, 128),
            'phone' => $phone,
            'direction' => $isOut ? \App\Models\WaMessage::OUT : \App\Models\WaMessage::IN,
            'type' => self::str($m['type'] ?? null) ?: 'unknown',
            'text' => self::nullableStr(self::path($m, 'text', 'body'))
                ?? self::nullableStr(self::path($m, 'button', 'text'))
                ?? self::nullableStr(self::path($m, 'interactive', 'name')),
            'button_id' => self::nullableStr(self::path($m, 'interactive', 'button_reply', 'id'))
                ?? self::nullableStr(self::path($m, 'interactive', 'list_reply', 'id'))
                ?? self::nullableStr(self::path($m, 'button', 'payload')),
            'template_name' => self::nullableStr(self::path($m, 'template', 'name')),
            'template_lang' => self::nullableStr(self::path($m, 'template', 'language', 'code')),
            'sent_at' => self::ts($m['timestamp'] ?? null),
            'raw' => $m,
        ];
    }

    private static function status(mixed $raw): ?array
    {
        $s = self::arr($raw);
        $phone = WaContact::digitsOnly(self::str($s['recipient_id'] ?? null));

        if ($phone === '') {
            return null;
        }

        return [
            'kind' => 'status',
            'meta_id' => self::nullableStr($s['id'] ?? null, 128),
            'phone' => $phone,
            'status' => self::str($s['status'] ?? null) ?: 'unknown',
            'error_code' => self::nullableStr(self::path($s, 'errors', 0, 'code'), 12),
            'error_title' => self::nullableStr(self::path($s, 'errors', 0, 'title')),
            'status_at' => self::ts($s['timestamp'] ?? null),
            'raw' => $s,
        ];
    }

    /**
     * List into an array, or nothing at all. A scalar where Meta puts a list is not
     * a callback we can walk: foreach over it is a Warning, and Laravel turns
     * Warnings into ErrorException, which is a 500 and therefore a dropped
     * subscription.
     */
    private static function arr(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }

    /**
     * Read a nested key without ever offsetting a scalar. `text` arriving as the
     * string "hi" makes `$m['text']['body']` a Warning in PHP 8, so nested reads
     * have to stop as soon as a level is not an array. Keys may be ints because
     * `errors` is a list.
     */
    private static function path(array $row, string|int ...$keys): mixed
    {
        $cursor = $row;

        foreach ($keys as $key) {
            if (!is_array($cursor) || !array_key_exists($key, $cursor)) {
                return null;
            }

            $cursor = $cursor[$key];
        }

        return $cursor;
    }

    /**
     * Ints and floats count as strings here on purpose: Meta sends
     * `errors[0].code` as a JSON number, and `str()` rejecting it would silently
     * lose every delivery failure code -- the one field the inbox most needs.
     * Anything else (array, bool, null, object) becomes ''.
     */
    private static function str(mixed $value, int $max = 0): string
    {
        $out = is_string($value) || is_numeric($value) ? (string) $value : '';

        return $max > 0 ? mb_substr($out, 0, $max) : $out;
    }

    private static function nullableStr(mixed $value, int $max = 0): ?string
    {
        $out = self::str($value, $max);

        return $out === '' ? null : $out;
    }

    /**
     * Null, not `now()`: a callback with no usable timestamp does not get to invent
     * one, because Task 5 would then have no way to tell "Meta said when" from "we
     * guessed". Consumers coalesce (`$item['sent_at'] ?? now()`); wa_messages.sent_at
     * is NOT NULL with useCurrent, so the fallback belongs at the write, not here.
     *
     * The ceiling is not cosmetic. Carbon::createFromTimestamp throws
     * InvalidFormatException from roughly 1e14 upwards, and an uncaught throw here is a
     * 500 -- so a signed body carrying `"timestamp":1e30` would drop the subscription.
     * 4e10 is year 3237: far above any real callback, far below the throw. The floor is
     * `1`, not `0`, because the (int) cast turns a sub-second fraction into epoch 0,
     * which would be an invented 1970 date rather than the null Task 5 coalesces.
     */
    private static function ts(mixed $epoch): ?CarbonInterface
    {
        if (!is_numeric($epoch) || $epoch < 1 || $epoch > 4e10) {
            return null;
        }

        return Carbon::createFromTimestamp((int) $epoch, 'UTC');
    }
}
```

Two contract changes later tasks must know about: `parse()` now also returns `entry_id` (so the
controller never touches the decoded body itself), and `sent_at`/`status_at` are nullable.
`array_filter` is no longer dead code — the three per-item builders return `?array` and drop an
item that has no usable phone number, which is what keeps a garbage element from becoming a
`wa_contacts` row with an empty key in Task 5.

- [ ] **Step 7: Add the POST branch to `WaWebhookController`** (replace the class body, keep `verify()` as-is and add the ingest method plus a protected seam that Task 5 fills in):

```php
    public function ingest(Request $request): Response
    {
        $raw = $request->getContent();
        $secret = (string) config('whatsapp.app_secret', '');

        if (!WebhookSignature::isValid($raw, $request->header('X-Hub-Signature-256'), $secret)) {
            return response('forbidden', 403);
        }

        $payload = json_decode($raw, true);
        if (!is_array($payload)) {
            WaWebhookEvent::store(null, null, ['body' => mb_substr($raw, 0, 2000)],
                WaWebhookEvent::IGNORED, hash('sha256', $raw));

            return $this->accepted();
        }

        $parsed = WebhookParser::parse($payload, (string) config('whatsapp.phone_id', ''));
        $hash = hash('sha256', $raw);
        $ours = (string) config('whatsapp.phone_id', '');
        $numberFor = $parsed['matched'] ? $ours : $parsed['phone_number_id'];
        $entryId = $parsed['entry_id'];

        if (WaWebhookEvent::seenBefore($hash)) {
            WaWebhookEvent::store($numberFor, $entryId, $parsed['items'],
                WaWebhookEvent::DUPLICATE, $hash);

            return $this->accepted();
        }

        if (!$parsed['matched']) {
            WaWebhookEvent::store($numberFor, $entryId, $parsed['items'],
                WaWebhookEvent::IGNORED, $hash);

            return $this->accepted();
        }

        WaWebhookEvent::store($ours, $entryId, $parsed['items'], WaWebhookEvent::STORED, $hash);

        foreach ($parsed['items'] as $item) {
            $this->dispatch($item);
        }

        return $this->accepted();
    }

    private function accepted(): Response
    {
        return response('', 200);
    }

    protected function dispatch(array $item): void
    {
        // Task 5 replaces this with ProcessInboundEvent::dispatch($item).
    }
```

The controller reads **nothing** from `$payload` except the fact that it decoded to an array.
Under Ruling R23 it grew a local `stringOrNull()` guard for `entry[0]['id']`; under R24 that
read moved into `parse()` and the helper was deleted, because a per-call-site guard is the
pattern that let five other leaks survive it.

Ruling R24 (supersedes R23's scope). R23 was real but its justification — "every other value
handed to a typed parameter is already stringly-typed by construction" — was false, and the
whole plan should have been read as: **any value that arrives from the JSON body is untrusted
in shape as well as in signature.** A throwaway probe against the committed controller, each
body correctly signed, showed:

| body mutation | committed | after R24 |
|---|---|---|
| `"entry": "x"` (list replaced by a scalar) | **500** | 200, `ignored` |
| `"changes": "x"` | **500** | 200, `ignored` |
| one message element is the string `"nope"` | **500** | 200, item dropped |
| `"wa_id": [18479043315]` | **500** | 200, item dropped |
| `"metadata": {"phone_number_id": {"x":1}}` | **500** | 200, `phone_number_id` null |
| `"text": {"body": ["a","b"]}` | 200 (only by luck) | 200, `text` null |

Three distinct mechanisms, all reachable from one signed callback: `foreach` over a scalar and
array-offset reads on a scalar both raise an `E_WARNING`, which Laravel converts to
`ErrorException`; and `WaContact::digitsOnly(?string)` / `WaWebhookEvent::store(?string …)`
raise `TypeError` on an array. Each is a 500, and a 500 on the POST is what makes Meta retry and
then drop the subscription — the same outage R22 was ruled to prevent, arriving through the
request body instead of the session middleware. `WebhookParser` is now the single place that
touches the decoded body, it normalises every read through `arr()` / `path()` / `str()` /
`nullableStr()`, and the per-item builders return `?array` so an unusable element disappears
instead of becoming a row. R17's rule ("treat a non-string as absent") and R19's ("never bare-cast
request input") were both under-applied here; the generalised form is what Task 5 and Task 10
must follow, not another local guard.

Ruling R26 closes the fourth mechanism, found in the Round 4 re-review. Shape normalisation is
not sufficient on its own: a value can be perfectly well-shaped and still make the parser throw.
`Carbon::createFromTimestamp` raises `InvalidFormatException` for anything from roughly `1e14`
upwards (measured: `4e10` → year 3237 and `1e11` → year 5138 both succeed, `1e14`, `2e14`, `1e30`
and the numeric string `'99999999999999999999'` all throw), and that throw escapes `ts()` through
`parse()` into a **500** — verified end-to-end through a correctly signed POST before the fix. So
`ts()` bounds the epoch on **both** sides (`$epoch < 1 || $epoch > 4e10` → null) rather than
catching around it: 4e10 is year 3237, far above any timestamp Meta will ever send and far below the
threshold where Carbon throws; the floor is `1` rather than `0` because the `(int)` cast turns a
sub-second fraction into epoch 0, which would store an invented 1970 date instead of the null Task 5
coalesces. The same reasoning applies to any other place in this app that converts external input
into a typed object — bound the accepted range at the reader; a `try/catch` would keep the 500
reachable from whatever the next unforeseen value turns out to be. Two tests carry it: the unit case
in `WebhookParserTest` covers the mechanism, and `test_a_hostile_timestamp_is_stored_as_null_instead_of_500ing`
in `WebhookIngestTest` holds the promise at the HTTP boundary, where it actually matters.

Add the imports at the top of the controller: `use App\Support\WebhookParser;`, `use App\Support\WebhookSignature;`, `use App\Models\WaWebhookEvent;`.

Register the routes in `routes/web.php` — the POST beside the existing GET, and both with the
browser middleware group taken off (Ruling R20 as corrected by Ruling R22). `/wa/webhook` is
called by Meta with no cookie, and `.env` runs `SESSION_DRIVER=database`, so today every
unauthenticated callback inserts a row into `sessions` (measured: one row per GET, plus an
`XSRF-TOKEN` cookie on the response). Growth is the minor half of the problem: if the deployed
database has no `sessions` table, each callback throws, Meta retries, and after repeated
failures **drops the webhook subscription** — total loss of the feature for a reason unrelated
to this plan's code.

Write both route lines as:

```php
Route::get('/wa/webhook', [WaWebhookController::class, 'verify'])
    ->withoutMiddleware('web')
    ->name('wa.webhook.verify');

Route::post('/wa/webhook', [WaWebhookController::class, 'ingest'])
    ->withoutMiddleware('web')
    ->name('wa.webhook.ingest');
```

`withoutMiddleware('web')` drops the whole group, because two different members of it touch the
session and each one 500s on its own: `ShareErrorsFromSession@handle` calls
`$request->session()->get('errors')`, and `VerifyCsrfToken@newCookie` — reached even for a
request that `inExceptArray()` already let through — calls `$request->session()->token()`.
Excluding only `StartSession` gives a 500; excluding `StartSession` *and*
`ShareErrorsFromSession` still gives a 500 with `RuntimeException: Session store not set on
request.` at `Illuminate/Http/Request.php:612`. Enumerating the session-using classes is the
brittle fix: it has to be re-derived every time Laravel reshuffles the group. Dropping `'web'`
is what Ruling R22 chose after measuring all four variants.

Do not strip the group application-wide (no `Middleware::web(remove:)`): the staff login and
inbox in Tasks 9 and 11 need sessions. No test covers this — `phpunit.xml` pins
`SESSION_DRIVER=array`, so a "no session row was written" assertion could only pass vacuously;
the ruling was verified with a throwaway probe that forced `session.driver = database` and
counted `sessions` rows (result: 200, zero rows, no `Set-Cookie`, challenge still echoed).

Also exempt both machine paths from CSRF in `bootstrap/app.php`, changing the `withMiddleware`
closure to:

```php
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->validateCsrfTokens(except: [
            'wa/webhook',
            'inbound/record-send',
        ]);
    })
```

With `withoutMiddleware('web')` on the routes, `ValidateCsrfToken` is not in their pipeline at
all, so this except list is a second lock rather than the mechanism. Keep it anyway: the
failure it guards against — a `419` on Meta's `POST`, Meta retrying, Meta dropping the
subscription — costs two lines of config to insure against, and if a later task ever puts
these paths back under `web`, the machine endpoints are the ones that must stay reachable.

- [ ] **Step 8: Add `tests/Feature/WebhookParserTest.php`**

The ingest tests only ever see the parser through an HTTP status and a `status` column, which is
why the parser could carry six shape leaks with a green suite. This file pins the contract itself
so that a renamed or dropped key fails here instead of in Task 5. `WebhookParser` is a pure
static, so no `RefreshDatabase` and no HTTP.

```php
<?php

namespace Tests\Feature;

use App\Support\WebhookParser;
use Carbon\CarbonInterface;
use Tests\TestCase;

class WebhookParserTest extends TestCase
{
    private const OURS = '1427706937088002';

    private function fixture(string $name): array
    {
        return json_decode(
            file_get_contents(base_path('tests/Fixtures/' . $name)), true);
    }

    private function bodyOf(array $value): array
    {
        $value['metadata'] = ['phone_number_id' => self::OURS];

        return ['entry' => [['id' => '1717463009877473', 'changes' => [
            ['field' => 'messages', 'value' => $value],
        ]]]];
    }

    public function test_inbound_text_fixture_yields_a_profile_item_then_a_message_item(): void
    {
        $parsed = WebhookParser::parse($this->fixture('inbound_text.json'), self::OURS);

        $this->assertTrue($parsed['matched']);
        $this->assertSame('1717463009877473', $parsed['entry_id']);
        $this->assertNull($parsed['phone_number_id']);
        $this->assertCount(2, $parsed['items']);

        $this->assertSame('profile', $parsed['items'][0]['kind']);
        $this->assertSame('18479043315', $parsed['items'][0]['phone']);
        $this->assertSame('Jane Tester', $parsed['items'][0]['name']);

        $message = $parsed['items'][1];
        $this->assertSame('message', $message['kind']);
        $this->assertSame('in', $message['direction']);
        $this->assertSame('18479043315', $message['phone']);
        $this->assertSame('wamid.INBOUND1', $message['meta_id']);
        $this->assertSame('text', $message['type']);
        $this->assertSame('Hello, is this E Tax Planner?', $message['text']);
        $this->assertNull($message['button_id']);
        $this->assertInstanceOf(CarbonInterface::class, $message['sent_at']);
        $this->assertSame('2026-11-06 20:13:20', $message['sent_at']->copy()->timezone('UTC')->toDateTimeString());
    }

    public function test_status_fixture_yields_a_status_item(): void
    {
        $parsed = WebhookParser::parse($this->fixture('status_delivered.json'), self::OURS);

        $this->assertTrue($parsed['matched']);
        $this->assertCount(1, $parsed['items']);

        $status = $parsed['items'][0];
        $this->assertSame('status', $status['kind']);
        $this->assertSame('wamid.OUT1', $status['meta_id']);
        $this->assertSame('delivered', $status['status']);
        $this->assertSame('18479043315', $status['phone']);
        $this->assertNull($status['error_code']);
        $this->assertNull($status['error_title']);
        $this->assertInstanceOf(CarbonInterface::class, $status['status_at']);
    }

    public function test_callback_for_another_number_is_unmatched_and_reports_that_number(): void
    {
        $parsed = WebhookParser::parse($this->fixture('other_phone_number.json'), self::OURS);

        $this->assertFalse($parsed['matched']);
        $this->assertSame('9999999999999999', $parsed['phone_number_id']);
        $this->assertSame('1717463009877473', $parsed['entry_id']);
        $this->assertSame([], $parsed['items']);
    }

    public function test_non_string_ids_become_null_rather_than_reaching_a_typed_parameter(): void
    {
        $parsed = WebhookParser::parse([
            'entry' => [['id' => ['a', 'b'], 'changes' => 'nope']],
        ], self::OURS);

        $this->assertFalse($parsed['matched']);
        $this->assertNull($parsed['entry_id']);
        $this->assertNull($parsed['phone_number_id']);
        $this->assertSame([], $parsed['items']);
    }

    public function test_items_without_a_usable_phone_are_dropped_not_stored(): void
    {
        $parsed = WebhookParser::parse($this->bodyOf([
            'contacts' => ['scalar', ['wa_id' => [18479043315], 'profile' => ['name' => 'X']],
                ['wa_id' => '15550001111', 'profile' => ['name' => []]]],
            'messages' => ['scalar', ['from' => [1, 2]], ['id' => 'wamid.NOPHONE']],
            'statuses' => ['scalar', ['recipient_id' => ''], ['recipient_id' => null, 'status' => 'read']],
        ]), self::OURS);

        $this->assertTrue($parsed['matched']);
        $this->assertSame([], $parsed['items']);
    }

    public function test_scalar_replacements_for_lists_and_nested_maps_are_survivable(): void
    {
        $cases = [
            ['scalar entry list', ['entry' => 'scalar'], false, 0],
            ['scalar changes list', ['entry' => [['changes' => 'scalar']]], false, 0],
            ['scalar change value', ['entry' => [['changes' => [['value' => 'scalar']]]]], false, 0],
            ['text is a string', $this->bodyOf([
                'messages' => [['from' => '18479043315', 'text' => 'plain string']]]), true, 1],
            ['template is a string', $this->bodyOf([
                'messages' => [['from' => '18479043315', 'template' => 'scalar']]]), true, 1],
            ['errors is a string', $this->bodyOf([
                'statuses' => [['recipient_id' => '18479043315', 'errors' => 'scalar']]]), true, 1],
        ];

        foreach ($cases as [$label, $body, $expectMatched, $expectItems]) {
            $parsed = WebhookParser::parse($body, self::OURS);

            $this->assertSame($expectMatched, $parsed['matched'], $label);
            $this->assertCount($expectItems, $parsed['items'], $label);
        }
    }

    public function test_numeric_error_code_from_json_is_captured_as_a_string(): void
    {
        $parsed = WebhookParser::parse($this->bodyOf([
            'statuses' => [['id' => 'wamid.OUT2', 'recipient_id' => '18479043315',
                'status' => 'failed', 'errors' => [['code' => 131049, 'title' => 'Re-engagement message']]]],
        ]), self::OURS);

        $status = $parsed['items'][0];
        $this->assertSame('131049', $status['error_code']);
        $this->assertSame('Re-engagement message', $status['error_title']);
        $this->assertSame('failed', $status['status']);
    }

    public function test_an_interactive_button_reply_yields_its_id_and_a_missing_type_gets_named(): void
    {
        $parsed = WebhookParser::parse($this->bodyOf([
            'messages' => [
                ['from' => '18479043315', 'id' => 'wamid.B1', 'timestamp' => '1793996000',
                    'type' => 'interactive',
                    'interactive' => ['type' => 'button', 'button_reply' => ['id' => 'yes_stop',
                        'title' => 'Stop reminders']]],
                ['from' => '18479043315', 'id' => 'wamid.B2', 'text' => ['body' => 'hi']],
            ],
        ]), self::OURS);

        $button = $parsed['items'][0];
        $this->assertSame('yes_stop', $button['button_id']);
        $this->assertSame('interactive', $button['type']);
        $this->assertSame('button', $button['raw']['interactive']['type']);
        $this->assertNull($button['text']);

        // The `?: 'unknown'` default is what keeps a type-less callback from rendering as an
        // empty cell in the inbox; it is the one branch of `type` nothing else reached.
        $this->assertSame('unknown', $parsed['items'][1]['type']);
    }

    public function test_a_missing_or_absurd_timestamp_is_null_not_now(): void
    {
        $parsed = WebhookParser::parse($this->bodyOf([
            'messages' => [
                ['id' => 'wamid.T1', 'from' => '18479043315', 'text' => ['body' => 'hi']],
                ['id' => 'wamid.T2', 'from' => '18479043315', 'timestamp' => -5, 'text' => ['body' => 'hi']],
                ['id' => 'wamid.T3', 'from' => '18479043315', 'timestamp' => 1e14, 'text' => ['body' => 'hi']],
                ['id' => 'wamid.T4', 'from' => '18479043315', 'timestamp' => 1e30, 'text' => ['body' => 'hi']],
                ['id' => 'wamid.T5', 'from' => '18479043315', 'timestamp' => '99999999999999999999',
                    'text' => ['body' => 'hi']],
                ['id' => 'wamid.T7', 'from' => '18479043315', 'timestamp' => 0.5, 'text' => ['body' => 'hi']],
            ],
            'statuses' => [
                ['id' => 'wamid.T6', 'recipient_id' => '18479043315', 'status' => 'delivered',
                    'timestamp' => 1e30],
            ],
        ]), self::OURS);

        $this->assertCount(7, $parsed['items']);
        $this->assertNull($parsed['items'][0]['sent_at']);
        $this->assertNull($parsed['items'][1]['sent_at']);
        $this->assertNull($parsed['items'][2]['sent_at']);
        $this->assertNull($parsed['items'][3]['sent_at']);
        $this->assertNull($parsed['items'][4]['sent_at']);
        $this->assertNull($parsed['items'][5]['sent_at']);
        $this->assertNull($parsed['items'][6]['status_at']);
    }

    public function test_message_from_our_own_number_is_outbound_and_attributed_to_the_recipient(): void
    {
        config(['whatsapp.my_number' => '12242144261']);

        $parsed = WebhookParser::parse($this->bodyOf([
            'messages' => [['id' => 'wamid.OUT3', 'from' => '12242144261', 'to' => '18479043315',
                'type' => 'template', 'template' => ['name' => 'opener_brand', 'language' => ['code' => 'en_US']]]],
        ]), self::OURS);

        $message = $parsed['items'][0];
        $this->assertSame('out', $message['direction']);
        $this->assertSame('18479043315', $message['phone']);
        $this->assertSame('opener_brand', $message['template_name']);
        $this->assertSame('en_US', $message['template_lang']);
    }

    public function test_an_unconfigured_own_number_never_invents_an_outbound_row(): void
    {
        config(['whatsapp.my_number' => '']);

        $parsed = WebhookParser::parse($this->bodyOf([
            'messages' => [
                ['id' => 'wamid.A', 'from' => '12242144261', 'to' => '18479043315',
                    'text' => ['body' => 'hi']],
                ['id' => 'wamid.B', 'to' => '18479043315', 'text' => ['body' => 'hi']],
            ],
        ]), self::OURS);

        $this->assertCount(1, $parsed['items']);
        $this->assertSame('in', $parsed['items'][0]['direction']);
        $this->assertSame('12242144261', $parsed['items'][0]['phone']);
    }

    public function test_an_unconfigured_phone_id_matches_no_callback_at_all(): void
    {
        // Deliberately not bodyOf(): this callback carries no metadata at all,
        // which is what made an unset WA_PHONE_ID compare equal to it.
        $body = ['entry' => [['id' => '1', 'changes' => [['field' => 'messages', 'value' => [
            'messages' => [['from' => '15550001111', 'id' => 'wamid.FOREIGN',
                'type' => 'text', 'text' => ['body' => 'not ours']]],
        ]]]]]];

        $this->assertSame([], WebhookParser::parse($body, '')['items']);
        $this->assertFalse(WebhookParser::parse($body, '')['matched']);
        $this->assertFalse(WebhookParser::parse($body, self::OURS)['matched']);

        // Control: the guard must not cost us real callbacks.
        $this->assertTrue(WebhookParser::parse($this->bodyOf([
            'messages' => [['from' => '18479043315', 'id' => 'wamid.MINE', 'text' => ['body' => 'hi']]],
        ]), self::OURS)['matched']);
    }
}
```

`test_an_unconfigured_phone_id_matches_no_callback_at_all` is Ruling R25, found by the Round 4
implementer and reproduced by the controller: normalising `phone_number_id` to `''` made "our
configured id" and "a callback with no `metadata`" compare **equal**, so with `WA_PHONE_ID` unset
every metadata-less callback matched and got dispatched as ours (pre-R24 it fell through
`!is_string($numberId)` and was ignored). The match now requires a non-empty configured id.
Same shape as R19's empty-challenge refusal and `verify()`'s `$token === ''` check: **an unset
credential must never be satisfiable by an absent value.**

The last two tests are the `WA_MY_NUMBER` half of R24. Direction came from
`($m['from'] ?? null) === config('whatsapp.my_number')`, and `config()` is `''` when the key is
unset, so with `WA_MY_NUMBER` missing nothing was ever outbound and a message carrying only `to`
became an item with an **empty phone** -- the row Task 5 would have tried to write into
`wa_contacts.phone` (unique, varchar 20) as `''`. Two changes: the comparison is guarded by
`$mine !== ''`, and a builder drops any item with no usable phone. The residual cost, stated
plainly: while `WA_MY_NUMBER` is unset our own traffic is filed as inbound against our own number,
which is visible and wrong rather than silently blank, and Task 16 sets the key before the first
live callback.

- [ ] **Step 9: Run the tests**

```bash
php artisan test tests/Feature/WebhookIngestTest.php tests/Feature/WebhookParserTest.php
```

Expected: **20 passed** (8 ingest + 12 parser). Full suite: **40 passed**.

- [ ] **Step 10: Commit**

```bash
git add app database/migrations routes/web.php bootstrap/app.php tests
git commit -m "feat: signature-verified webhook ingest with replay and foreign-number handling"
```

---

## Task 5: Event job — contacts, messages, receipts

**Deliverable:** parsed items become rows: profile names land on the contact, inbound messages set `last_inbound_at`, outbound rows are recorded, statuses map onto the row they belong to and never move backwards, statuses for unknown `wamid`s are dropped.

**Consumes:** the item shapes from Task 4, `WaMessage::record()`, `WaContact::fromPhone()`.
**Produces:** `App\Jobs\ProcessInboundEvent` (constructor `array $item`), `WaContact::touchInbound(CarbonInterface $at): void`, `WaContact::isDialable(string $digits): bool` (R28 — the parser calls it too), `WaMessage::applyStatus(string $status, CarbonInterface $at, ?string $code = null, ?string $title = null): void`, and the status rule `WaMessage::record()` shares with it (R30).

- [ ] **Step 1: Write the failing test** — `tests/Feature/IngestRowsTest.php`

```php
<?php

namespace Tests\Feature;

use App\Jobs\ProcessInboundEvent;
use App\Models\WaContact;
use App\Models\WaMessage;
use App\Support\WebhookParser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IngestRowsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['whatsapp.phone_id' => '1427706937088002', 'whatsapp.my_number' => '12242144261']);
    }

    private function items(string $fixture): array
    {
        $payload = json_decode(file_get_contents(base_path('tests/Fixtures/' . $fixture)), true);

        return WebhookParser::parse($payload, '1427706937088002')['items'];
    }

    public function test_inbound_message_creates_contact_profile_name_and_row()
    {
        foreach ($this->items('inbound_text.json') as $item) {
            ProcessInboundEvent::dispatchSync($item);
        }

        $c = WaContact::where('phone', '18479043315')->firstOrFail();
        $this->assertSame('Jane Tester', $c->profile_name);
        $this->assertSame('2026-11-06 20:13:20', $c->last_inbound_at->toDateTimeString());

        $m = WaMessage::where('meta_id', 'wamid.INBOUND1')->firstOrFail();
        $this->assertSame(WaMessage::IN, $m->direction);
        $this->assertSame('Hello, is this E Tax Planner?', $m->body);
        $this->assertSame('incoming', $m->status);
        $this->assertTrue($m->auto_reply === false);
    }

    public function test_replayed_inbound_does_not_duplicate_the_row()
    {
        foreach ($this->items('inbound_text.json') as $item) {
            ProcessInboundEvent::dispatchSync($item);
            ProcessInboundEvent::dispatchSync($item);
        }
        $this->assertSame(1, WaMessage::count());
    }

    public function test_status_maps_onto_the_sent_row_and_never_moves_backwards()
    {
        $c = WaContact::factory()->create(['phone' => '18479043315']);
        WaMessage::record([
            'wa_contact_id' => $c->id, 'meta_id' => 'wamid.OUT1', 'direction' => WaMessage::OUT,
            'type' => 'template', 'status' => WaMessage::SENT, 'sent_at' => now(),
        ]);

        ProcessInboundEvent::dispatchSync($this->items('status_delivered.json')[0]);
        $m = WaMessage::where('meta_id', 'wamid.OUT1')->firstOrFail();
        $this->assertSame(WaMessage::DELIVERED, $m->status);

        ProcessInboundEvent::dispatchSync([
            'kind' => 'status', 'meta_id' => 'wamid.OUT1', 'phone' => '18479043315',
            'status' => 'delivered', 'error_code' => null, 'error_title' => null,
            'status_at' => now()->subMinute(), 'raw' => [],
        ]);
        $this->assertSame(WaMessage::DELIVERED, $m->fresh()->status);

        ProcessInboundEvent::dispatchSync([
            'kind' => 'status', 'meta_id' => 'wamid.OUT1', 'phone' => '18479043315',
            'status' => 'read', 'error_code' => null, 'error_title' => null,
            'status_at' => now(), 'raw' => [],
        ]);
        $this->assertSame(WaMessage::READ, $m->fresh()->status);

        ProcessInboundEvent::dispatchSync([
            'kind' => 'status', 'meta_id' => 'wamid.UNKNOWN', 'phone' => '18479043315',
            'status' => 'failed', 'error_code' => '131047', 'error_title' => 'Message undeliverable',
            'status_at' => now(), 'raw' => [],
        ]);
        $this->assertSame(1, WaMessage::count(), 'an unknown wamid must not invent a row');
    }

    public function test_failed_status_records_the_error()
    {
        $c = WaContact::factory()->create(['phone' => '18479043315']);
        WaMessage::record([
            'wa_contact_id' => $c->id, 'meta_id' => 'wamid.OUT2', 'direction' => WaMessage::OUT,
            'type' => 'text', 'status' => WaMessage::SENT, 'sent_at' => now(),
        ]);
        ProcessInboundEvent::dispatchSync([
            'kind' => 'status', 'meta_id' => 'wamid.OUT2', 'phone' => '18479043315',
            'status' => 'failed', 'error_code' => '131047', 'error_title' => 'Message undeliverable',
            'status_at' => now(), 'raw' => [],
        ]);
        $m = WaMessage::where('meta_id', 'wamid.OUT2')->firstOrFail();
        $this->assertSame(WaMessage::FAILED, $m->status);
        $this->assertSame('131047', $m->error_code);
        $this->assertSame('Message undeliverable', $m->error_title);
    }

    public function test_the_outbound_echo_does_not_erase_a_receipt_already_recorded()
    {
        // Ruling R27. Meta echoes every message we send as a `messages` object with from =
        // our own number, and the callback order is not guaranteed: the echo can arrive
        // after the delivery status for the same wamid. record() is an updateOrCreate keyed
        // on meta_id, and message() used to pass status => null for outbound, so that echo
        // rolled a recorded receipt back to nothing (measured: 'delivered' -> NULL).
        $c = WaContact::factory()->create(['phone' => '18479043315']);
        WaMessage::record([
            'wa_contact_id' => $c->id, 'meta_id' => 'wamid.OUT1', 'direction' => WaMessage::OUT,
            'type' => 'template', 'status' => WaMessage::DELIVERED, 'sent_at' => now(),
        ]);

        ProcessInboundEvent::dispatchSync($this->items('outbound_echo.json')[0]);

        $m = WaMessage::where('meta_id', 'wamid.OUT1')->firstOrFail();
        $this->assertSame(WaMessage::DELIVERED, $m->status, 'the echo must not touch status');
        $this->assertSame('opener_brand', $m->template_name, 'but it does fill in what it knows');
    }

    public function test_an_echo_for_a_message_this_app_never_sent_is_stored_without_a_status()
    {
        ProcessInboundEvent::dispatchSync($this->items('outbound_echo.json')[0]);

        $m = WaMessage::where('meta_id', 'wamid.OUT1')->firstOrFail();
        $this->assertSame(WaMessage::OUT, $m->direction);
        $this->assertNull($m->status, 'a callback alone is not a receipt');
        $this->assertSame('12242144261', $m->raw['from'], 'the payload is kept verbatim');
    }
}
```

- [ ] **Step 1b: Add the fixture** — `tests/Fixtures/outbound_echo.json`, a real Cloud API
  outbound echo (our number in `from`, the recipient in `to`, the same `wamid` the status
  callbacks will later refer to). Written as one line, no trailing newline, like the other four:

```json
{"object":"whatsapp_business_account","entry":[{"id":"1717463009877473","changes":[{"field":"messages","value":{"messaging_product":"whatsapp","metadata":{"display_phone_number":"12242144261","phone_number_id":"1427706937088002"},"messages":[{"from":"12242144261","to":"18479043315","id":"wamid.OUT1","timestamp":"1793996090","type":"template","template":{"name":"opener_brand","language":{"code":"en_US"}}}]}}]}]}
```

Verified against the committed parser, not assumed: `WebhookParser::parse()` of this body yields
exactly one item — `kind=message`, `direction=out`, `phone=18479043315`, `meta_id=wamid.OUT1`,
`template_name=opener_brand`, `template_lang=en_US`.

- [ ] **Step 2: Run it** — expected FAIL `Class "App\Jobs\ProcessInboundEvent" not found`.

- [ ] **Step 3: Create `app/Jobs/ProcessInboundEvent.php`**

```php
<?php

namespace App\Jobs;

use App\Models\WaContact;
use App\Models\WaMessage;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProcessInboundEvent implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public array $item)
    {
    }

    public function handle(): void
    {
        match ($this->item['kind'] ?? '') {
            'profile' => $this->profile(),
            'message' => $this->message(),
            'status' => $this->status(),
            default => null,
        };
    }

    private function profile(): void
    {
        $contact = WaContact::fromPhone($this->item['phone']);
        if ($contact->profile_name !== $this->item['name']) {
            $contact->update(['profile_name' => $this->item['name']]);
        }
    }

    private function message(): void
    {
        $item = $this->item;
        $at = $item['sent_at'] ?? now();
        $inbound = $item['direction'] === WaMessage::IN;

        $contact = WaContact::fromPhone($item['phone']);
        $attrs = [
            'wa_contact_id' => $contact->id,
            'meta_id' => $item['meta_id'],
            'direction' => $item['direction'],
            'type' => $item['type'],
            'body' => $item['text'],
            'template_name' => $item['template_name'],
            'template_lang' => $item['template_lang'],
            'sent_at' => $at,
            'raw' => $item['raw'],
        ];

        // R27: status is only ever *claimed* for an inbound message. For an outbound echo the
        // key is absent, so updateOrCreate leaves whatever the sender wrote (sent/delivered/
        // read/failed) alone instead of blanking it.
        if ($inbound) {
            $attrs['status'] = WaMessage::INCOMING;
        }

        $row = WaMessage::record($attrs);

        if ($inbound) {
            $contact->touchInbound($at);
            // Task 7 adds the automatic-response call here.
        }
    }

    private function status(): void
    {
        $item = $this->item;
        $row = WaMessage::findByMetaId($item['meta_id']);
        if ($row === null) {
            return;
        }
        $row->applyStatus($item['status'], $item['status_at'] ?? now(),
            $item['error_code'], $item['error_title']);
    }
}
```

Ruling R24 changes what this job may assume, in both directions. `phone` is now guaranteed
non-empty, so the old `if ($item['phone'] === '') return;` guard is gone -- the parser drops the
item instead, and an assertion that cannot fail is worse than none. `sent_at` and `status_at` are
now **nullable**, because the parser no longer invents `now()` for a callback that carried no
timestamp; the job coalesces here rather than the parser deciding, and it has to:
`wa_messages.sent_at` is `timestamp NOT NULL` with `useCurrent`. `meta_id` can also be null
(`nullableStr`), and `WaMessage::findByMetaId()` already refuses `null` and `''` before querying
(app/Models/WaMessage.php:54-60), so an id-less status is dropped rather than matching some other
row whose `meta_id` is `NULL`.

Ruling R27 is the second half of the same warning: **what a callback may overwrite.** Meta sends an
echo of every message we send (`messages` with `from` = our own number) and it does not promise the
order of that echo against the status callbacks for the same `wamid`. `record()` is an
`updateOrCreate` keyed on `meta_id`, so the original `status => …INCOMING : null` expression meant
the echo wrote `status = NULL` over a receipt that had already arrived — measured through the real
models before this ruling: `delivered` → `NULL`. Outbound status is therefore *absent* from the
attribute array unless the item is inbound, which leaves whatever our own sender recorded
(`sent`) and whatever a status callback advanced it to (`delivered`, `read`, `failed`) untouched.
Two things follow and are now covered by tests in Step 1: an echo for a message this app never sent
still creates its row, with `status = null` — a callback alone is not a receipt, and inventing `sent`
here would claim a send this app cannot account for — and the echo's `raw` payload is stored
verbatim, which is where `button_id` lives, because `wa_messages` has no `button_id` column
(`grep -rn button_id app/ database/` finds only the parser).

Ruling R28 closes the last way this job can throw on data: **length.** `WaContact::fromPhone()`
raises `InvalidArgumentException` when the digits string is shorter than 10 (app/Models/WaContact.php:30),
and until now the parser guaranteed only *non-empty* — `if ($phone === '')`. A signed body carrying
`"from": "12"` therefore parsed into a perfectly usable-looking item and threw inside the job, three
retries and a `failed_jobs` row per callback. That is the R24/R26 class one level on: a value that is
the right shape and non-empty but out of the accepted range, checked where it is written instead of
where it is read. The floor now lives in one predicate both sides call.

Deliberately **not** done, and the reason recorded so a reviewer can argue with evidence: wrapping
`dispatch()` in `try/catch (\Throwable)` would also silence this class of failure — but it silences
every other processing failure with it, converting a real defect into a log line while the inbox
quietly stops filling. The job's throw only reaches the HTTP response when the queue runs inline,
and this app deploys with `QUEUE_CONNECTION=database` (`.env.example:38`); under that driver a job
failure cannot make Meta retry at all. So the never-5xx guarantee holds by *configuration*, and
Task 16 carries it as a hard constraint: **do not deploy with `sync`** — with `sync`, any exception
in `ProcessInboundEvent` becomes a 500 on the callback and, after retries, a dropped subscription.
Step 4b makes that constraint non-hypothetical for the one throw we know about; if a later task
finds another, prefer bounding it at the reader over catching it here.

Ruling R29 is R28's other edge and the last one the parser owns: **column width is part of the
accepted range.** It belongs here rather than in a `try/catch` at the write because the tests cannot
see it — sqlite, this project's test database, does not enforce varchar widths, so an over-long
value passes locally and becomes MariaDB error 1406 on the deployed MySQL. Descriptive fields are
truncated to their column; `phone` is dropped above 20 digits, because a truncated phone number is
a different recipient and the inbox is where someone clicks Reply.

- [ ] **Step 4: Add the model methods and the dialable rule.** To `WaContact`:

```php
    /**
     * The single definition of "this digits string is a phone we can store". fromPhone()
     * throws below it, and WebhookParser drops below it too (Ruling R28) so the exception
     * is unreachable from callback data. Keeping the two on one predicate is the point:
     * duplicated literals drift, and drift here means either a queued job that fails three
     * times per callback or a callback item silently vanishing.
     */
    public static function isDialable(string $digits): bool
    {
        return strlen($digits) >= 10;
    }
```

and change `fromPhone()`'s guard to use it — the `''` case is already covered by the length:

```php
        $phone = self::digitsOnly($raw);
        if (!self::isDialable($phone)) {
            throw new \InvalidArgumentException("not an E.164 number: {$raw}");
        }
```

```php
    public function touchInbound(CarbonInterface $at): void
    {
        if ($this->last_inbound_at === null || $at->greaterThan($this->last_inbound_at)) {
            $this->update(['last_inbound_at' => $at]);
        }
    }
```

and add `use Carbon\CarbonInterface;` to its imports. To `WaMessage`:

```php
    private const RANK = [
        self::SENT => 1,
        self::DELIVERED => 2,
        self::READ => 3,
    ];

    public function applyStatus(string $status, CarbonInterface $at, ?string $code, ?string $title): void
    {
        $current = self::RANK[$this->status] ?? 0;
        $incoming = self::RANK[$status] ?? 0;

        if ($status === self::FAILED || $status === self::DELETED) {
            $this->update([
                'status' => $status, 'status_at' => $at,
                'error_code' => $code, 'error_title' => $title,
            ]);

            return;
        }

        if ($incoming > $current) {
            $this->update(['status' => $status, 'status_at' => $at]);
        }
    }
```

add `public const DELETED = 'deleted';` next to the other status constants, and `use Carbon\CarbonInterface;` to its imports.

- [ ] **Step 4b: Make the parser the place that length is decided** (Ruling R28). Three guards in
  `app/Support/WebhookParser.php` change; nothing else in the file moves. `profile()` currently reads
  `if ($phone === '' || $name === null)`, and `message()` and `status()` each read
  `if ($phone === '')` — all three become the predicate:

```php
        if (!WaContact::isDialable($phone) || $name === null) {
            return null;
        }
```

```php
        if (!WaContact::isDialable($phone)) {
            return null;
        }
```

`app/Models/WaContact` is already imported by the parser (`use App\Models\WaContact;`), so no new
import. Append these two tests — the first to `tests/Feature/WebhookParserTest.php` (13 tests) and the
second to `tests/Feature/WebhookIngestTest.php` (9 tests, and it needs `use App\Models\WaContact;`):

```php
    public function test_a_number_too_short_to_store_is_dropped_where_it_is_read(): void
    {
        $parsed = WebhookParser::parse($this->bodyOf([
            'messages' => [['from' => '12', 'id' => 'wamid.S1', 'text' => ['body' => 'hi']]],
            'statuses' => [['id' => 'wamid.S2', 'recipient_id' => '999', 'status' => 'delivered']],
            'contacts' => [['wa_id' => '55', 'profile' => ['name' => 'Short']]],
        ]), self::OURS);

        $this->assertSame([], $parsed['items']);
        $this->assertTrue($parsed['matched'], 'dropping the items must not lose the match');
    }
```

```php
    public function test_a_callback_whose_message_number_is_unusable_still_stores_and_answers_200()
    {
        // Ruling R28. "12" is a non-empty digits string, so pre-R28 it parsed into a normal
        // item and reached WaContact::fromPhone(), which throws below 10 digits -- three
        // retries and a failed_jobs row per callback. The item dies at the parser instead,
        // the audit row keeps the callback, and the usable contact from the same body lands.
        $raw = str_replace('"from":"18479043315"', '"from":"12"',
            file_get_contents(base_path('tests/Fixtures/inbound_text.json')));

        $this->postCallback($raw)->assertOk();
        $this->assertSame(WaWebhookEvent::STORED, WaWebhookEvent::first()->status);
        $this->assertSame('18479043315', WaContact::query()->value('phone'));
        $this->assertSame(0, WaMessage::count());
    }
```

Both are load-bearing: with the guards reverted to `=== ''`, the parser test gets three items back
and the ingest test dispatches a job that throws.

- [ ] **Step 4c: Finish the limits in the same place** (Ruling R29). R28 bounded the phone's lower
  end; the upper ends are still open, and they are open **where the database will reject them**. The
  columns are `wa_contacts.phone` varchar(20), `wa_contacts.profile_name` varchar(255),
  `wa_messages.type` varchar(24), `wa_messages.template_name` varchar(255),
  `wa_messages.template_lang` varchar(10), `wa_messages.status` varchar(24) and
  `wa_messages.error_title` varchar(255) (database/migrations/2026_11_06_000001_create_wa_tables.php).
  MariaDB in strict mode answers an overflow with error 1406, which Laravel raises as a
  `QueryException` — inside the job, so under the deployed `database` queue that is a silently
  failing job, and sqlite **will not show it to you**: this project's test database does not enforce
  column widths at all (measured in an earlier round — an over-long insert succeeds on sqlite and
  1406s on MariaDB). Every other field in the parser is already capped (`entry_id` 64,
  `phone_number_id` 64, `meta_id` 128, `error_code` 12); these were missed.

Two rules, chosen deliberately differently:

1. **Truncate** the descriptive fields, because the row is worth keeping and a cut-down label is
   honest — a 40-character `type` is not a message type we have a name for, but the message is real.
2. **Drop** the phone above 20 digits, because a truncated phone number is a *different person's*
   number and the inbox is where someone later clicks Reply. E.164 caps at 15 digits, so anything
   over 20 is not a phone at all.

`WaContact::isDialable` therefore becomes a range, and its comment says why the ceiling is the
column, not the standard:

```php
    public static function isDialable(string $digits): bool
    {
        return strlen($digits) >= 10 && strlen($digits) <= 20;
    }
```

The parser caps, using the `$max` argument `str()` and `nullableStr()` already accept — the `?:`
fallback stays *outside* the cap so a 40-character type still becomes its first 24 characters rather
than `unknown`:

```php
            'type' => self::str($m['type'] ?? null, 24) ?: 'unknown',
            'template_name' => self::nullableStr(self::path($m, 'template', 'name'), 255),
            'template_lang' => self::nullableStr(self::path($m, 'template', 'language', 'code'), 10),
```

```php
            'status' => self::str($s['status'] ?? null, 24) ?: 'unknown',
            'error_title' => self::nullableStr(self::path($s, 'errors', 0, 'title'), 255),
```

```php
        $name = self::nullableStr(self::path($contact, 'profile', 'name'), 255);
```

Two more tests go in `tests/Feature/WebhookParserTest.php` (14 tests after this step):

```php
    public function test_descriptive_fields_are_cut_to_the_column_width_and_a_too_long_phone_is_dropped(): void
    {
        $long = str_repeat('x', 300);

        $parsed = WebhookParser::parse($this->bodyOf([
            'contacts' => [['wa_id' => '18479043315', 'profile' => ['name' => $long]]],
            'messages' => [['from' => '18479043315', 'id' => 'wamid.W1', 'type' => $long,
                'text' => ['body' => 'hi'], 'template' => ['name' => $long,
                    'language' => ['code' => $long]]]],
            'statuses' => [['id' => 'wamid.W2', 'recipient_id' => '18479043315', 'status' => $long,
                'errors' => [['code' => 131049, 'title' => $long]]]],
        ]), self::OURS);

        $this->assertCount(3, $parsed['items']);
        $this->assertSame(255, mb_strlen($parsed['items'][0]['name']));
        $this->assertSame(24, strlen($parsed['items'][1]['type']));
        $this->assertSame(255, strlen($parsed['items'][1]['template_name']));
        $this->assertSame(10, strlen($parsed['items'][1]['template_lang']));
        $this->assertSame(24, strlen($parsed['items'][2]['status']));
        $this->assertSame(255, strlen($parsed['items'][2]['error_title']));

        $hostile = WebhookParser::parse($this->bodyOf([
            'messages' => [['from' => '184790433159999999999999', 'id' => 'wamid.W3',
                'text' => ['body' => 'hi']]],
        ]), self::OURS);

        $this->assertSame([], $hostile['items'], 'a truncated phone would be someone else');
    }
```

- [ ] **Step 5: Wire the dispatch** — replace the body of `WaWebhookController::dispatch()`:

```php
    protected function dispatch(array $item): void
    {
        ProcessInboundEvent::dispatch($item);
    }
```

with `use App\Jobs\ProcessInboundEvent;` added to the imports.

- [ ] **Step 6: Run the test**

```bash
php artisan test tests/Feature/IngestRowsTest.php
```

Expected: **6 passed** (4 as written above plus the two R27 echo tests). Then the R28/R29 tests and
the full suite:

```bash
php artisan test tests/Feature/WebhookParserTest.php tests/Feature/WebhookIngestTest.php tests/Feature/IngestRowsTest.php
php artisan test
```

Expected: **29 passed** (14 parser + 9 ingest + 6 rows) and full suite **49 passed** (40 today + the
6 rows + 2 parser + 1 ingest). `QUEUE_CONNECTION=sync` in `phpunit.xml` runs the job inline — which
is exactly why the R28 guards matter, and exactly why the deployed driver must not be `sync`.
(Step 8 is the review round that follows this commit; its counts are in Step 9.)

- [ ] **Step 7: Commit**

```bash
git add app tests/Feature/IngestRowsTest.php tests/Feature/WebhookParserTest.php \
        tests/Feature/WebhookIngestTest.php tests/Fixtures
git commit -m "feat: normalise webhook items into contacts, messages and receipts"
```

- [ ] **Step 8: Round 2 -- rulings R30, R31 and R32.** The round-1 review found three behaviours that
the round-1 tests could not see. All three were reproduced by the controller against the committed
code before being ruled (probe output quoted under each), so this step is written as final text to
replace what Steps 3, 4 and 6 put in, not as advice.

**R30 -- what a callback may overwrite, finished.** R27 fixed one case of this (`status` absent for an
echo). The review found the general form: `RANK` only contained `sent/delivered/read`, so `failed`,
`deleted` and `incoming` all read as rank 0, and `record()` was not rank-guarded at all. Measured on
`f2b513b`, through the real job:

```
failed, then a delivered stamped an hour EARLIER -> status=delivered error_code=131047
    error_title='Message undeliverable' status_at=2026-11-10 17:56:21   (was 18:56:21)
failed, then record() called the way Task 6's sender calls it -> status=sent, error fields kept
inbound row (status=incoming), then a delivered receipt -> status=delivered on an inbound message
```

So the deliverable's own sentence -- "statuses ... never move backwards" -- was false in three
directions, and one of them is Task 6's write path. The rule, in one place, used by both writers:

```php
    private const RANK = [
        self::SENT => 1,
        self::DELIVERED => 2,
        self::READ => 3,
    ];

    private const TERMINAL = [self::FAILED, self::DELETED];

    /**
     * R30: the one rule for changing a delivery state. Both writers go through it -- applyStatus()
     * for a receipt and record() for a message item -- because the round-1 hole was exactly that
     * record() had no opinion. A status may enter a terminal state from anywhere (a failure is
     * always believed, and believed late: Meta does not order its callbacks) and may leave one only
     * into another terminal state. Otherwise it has to move strictly forward, so a duplicate
     * `delivered` cannot even rewrite `status_at`. `unknown` and `incoming` are deliberately
     * unranked: they are not evidence of delivery, so they cannot outrank a receipt.
     */
    private static function mayReplaceStatus(?string $current, ?string $next): bool
    {
        if ($next === null) {
            return false;
        }

        if ($current === null) {
            return true;
        }

        if (in_array($current, self::TERMINAL, true)) {
            return in_array($next, self::TERMINAL, true);
        }

        return in_array($next, self::TERMINAL, true)
            || (self::RANK[$next] ?? 0) > (self::RANK[$current] ?? 0);
    }
```

`record()` becomes -- and note the two keys it stops honouring once the row exists, which is the same
rule applied to identity rather than to state: a `wamid`'s contact and direction are settled when the
row is created, so a later callback that disagrees is noise, not news (this is what made I-3's
mis-filed echo able to re-parent a row at all):

```php
    public static function record(array $attrs): self
    {
        $id = $attrs['meta_id'] ?? null;
        $row = ($id === null || $id === '') ? null : self::where('meta_id', $id)->first();

        if ($row === null) {
            return self::create($attrs);
        }

        if ($row->direction === self::IN || !self::mayReplaceStatus($row->status, $attrs['status'] ?? null)) {
            unset($attrs['status'], $attrs['status_at'], $attrs['error_code'], $attrs['error_title']);
        }
        unset($attrs['wa_contact_id'], $attrs['direction']);

        $row->update($attrs);

        return $row;
    }
```

`applyStatus()` is the other half of the same rule and its own replacement, because the file keeps
`findByMetaId()` and the `RANK` / `TERMINAL` / `mayReplaceStatus()` block between the two writers:

```php
    public function applyStatus(string $status, CarbonInterface $at, ?string $code = null, ?string $title = null): void
    {
        if ($this->direction === self::IN || !self::mayReplaceStatus($this->status, $status)) {
            return;
        }

        $terminal = in_array($status, self::TERMINAL, true);

        $this->update([
            'status' => $status,
            'status_at' => $at,
            'error_code' => $terminal ? $code : null,
            'error_title' => $terminal ? $title : null,
        ]);
    }
```

The old `applyStatus()` (with its own inline rank comparison and its separate terminal branch) is
deleted, not kept alongside. An inbound row never carries a delivery state, so the direction check
comes first in both writers: `findByMetaId()` is global and does not know which direction it matched.
`error_code`/`error_title` are written in the same statement as the status they belong to, so a row
can never read `delivered` while still holding `131047 / Message undeliverable` from an earlier
generation -- which is the internally impossible pair that Task 11 would paint green.

**R31 -- `ts()`'s band has to be the band the row can hold and the window can survive.** Step 6 of
Task 4 chose the ceiling against *Carbon's* throw limit. The binding limits are elsewhere, and both
were measured: the local MariaDB 10.4.32 (`STRICT_TRANS_TABLES`, session `SYSTEM` = Asia/Calcutta)
rejects `3237-07-19` **and** `1970-01-01 00:00:01` with `SQLSTATE[22007] ... 1292 Incorrect datetime
value`, because `wa_messages.sent_at`/`status_at` and `wa_contacts.last_inbound_at` are `timestamp`
columns and a `timestamp` converts through the session zone -- so the storeable band moves with the
host. `2096-10-02` rejected, `2038-01-19 03:14:07` accepted, on both zones. Same callback on the
suite's sqlite: stored fine, HTTP **200**, which is R29's lesson one column over.

The range error is the smaller half. Through the real job on sqlite, `timestamp: 1893456000` (2030)
produced `last_inbound_at = 2030-01-01 00:00:00` with `windowOpen() === true`: a far-future stamp does
not merely look wrong, it holds the 24-hour service window open for years -- and MariaDB stores 2030
happily, so no ceiling short of `now` closes that. Hence a floor well above the epoch (the Business
Platform did not exist before 2020, and the low end is where the session-zone conversion starts
failing) and a ceiling one day ahead of the clock:

```php
    /**
     * 2020-01-01. Below this it is not a callback: the Business Platform is newer than the value,
     * and the low end of a MySQL `timestamp` is where a session zone east of UTC starts rejecting
     * the write outright (`1970-01-01 00:00:01` is 1292 on a +05:30 box).
     */
    private const MIN_EPOCH = 1577836800;

    /**
     * Null, not `now()`: a callback with no usable timestamp does not get to invent
     * one, because Task 5 would then have no way to tell "Meta said when" from "we
     * guessed". Consumers coalesce (`$item['sent_at'] ?? now()`); wa_messages.sent_at
     * is NOT NULL with useCurrent, so the fallback belongs at the write, not here.
     *
     * The band is not cosmetic at either end. Carbon::createFromTimestamp throws
     * InvalidFormatException from roughly 1e14 upwards, and an uncaught throw here is a 500 -- so a
     * signed body carrying `"timestamp":1e30` would drop the subscription. The far side has two
     * more reasons: the `timestamp` columns reject a value past 2038 outright (1292, which under
     * the deployed `database` queue is a silently lost receipt), and a future stamp lands in
     * last_inbound_at, which is exactly what WaContact::windowOpen() reads -- so `1893456000` held
     * a service window open until 2030 when measured through the real job. An event that already
     * happened cannot be stamped in the future, so the ceiling is now plus a day of clock skew.
     * `2147483647` is not enough of a ceiling: 2030 is under it.
     */
    private static function ts(mixed $epoch): ?CarbonInterface
    {
        if (!is_numeric($epoch) || $epoch < self::MIN_EPOCH
            || $epoch > Carbon::now()->addDay()->getTimestamp()) {
            return null;
        }

        return Carbon::createFromTimestamp((int) $epoch, 'UTC');
    }
```

`MIN_EPOCH` sits immediately above `ts()`. Two lines in `config/database.php`, then: add
`'timezone' => '+00:00',` to the `mysql` **and** `mariadb` connection arrays (the driver honours it --
`vendor/laravel/framework/src/Illuminate/Database/Connectors/MySqlConnector.php:110-112` emits
`time_zone='...'`). This is *not* what makes the range safe -- R31's floor and ceiling are, and they
hold on a host nobody pinned. It is for the rows themselves: a value written under a +05:30 session
and read under a +04:00 one is a different instant, and this database will be written on this laptop
(Task 13's import) and read on the host. Recorded so nobody is surprised: rows already in the local
`etp_wa_inbox` read 5.5 hours earlier after the pin. Dev data only, and Task 16 asserts
`db now()` equals `php now()`.

**R32 -- recognising our own traffic cannot depend on how the number is punctuated.**
`$isOut = $mine !== '' && $from === $mine` compares raw strings, and `WA_MY_NUMBER` is
byte-compared against Meta's `from`. Measured with the same fixture, only the config changing:

```
WA_MY_NUMBER='12242144261'      -> direction=out phone=18479043315   (the echo, correctly)
WA_MY_NUMBER='+1 (224) 214-4261' -> direction=in  phone=12242144261   (our own number, as a contact)
```

and through the job that second row becomes `direction=in status=incoming` with a **second contact**
and `last_inbound_at` set -- our own send opening a window against ourselves, and R27's receipt erased
by the very echo R27 was written for. `+1 224 214 4261` is the spelling Meta itself uses in
`display_phone_number` and the spelling a person types into an env file. One line, using the helper
the file already calls two lines below:

```php
        $mine = WaContact::digitsOnly(self::str(config('whatsapp.my_number')));
```

**Tests for Step 8.** Append to `tests/Feature/IngestRowsTest.php` (15 tests after this), using the
same hand-built item shapes Step 1 uses -- they go through the job, not the parser, so R29's caps do
not apply to them:

```php
    private function statusItem(string $wamid, string $status, $at, ?string $code = null, ?string $title = null): array
    {
        return ['kind' => 'status', 'meta_id' => $wamid, 'phone' => '18479043315',
            'status' => $status, 'error_code' => $code, 'error_title' => $title,
            'status_at' => $at, 'raw' => []];
    }

    private function sentRow(string $wamid, string $status, ?string $code = null, ?string $title = null): WaContact
    {
        $c = WaContact::factory()->create(['phone' => '18479043315']);
        WaMessage::record([
            'wa_contact_id' => $c->id, 'meta_id' => $wamid, 'direction' => WaMessage::OUT,
            'type' => 'template', 'status' => $status, 'sent_at' => now(), 'status_at' => now(),
            'error_code' => $code, 'error_title' => $title,
        ]);

        return $c;
    }

    public function test_a_late_delivered_cannot_un_fail_a_message(): void
    {
        // R30. Meta does not order status callbacks, and record() is keyed on wamid: a delivered
        // that arrives after a failure is a duplicate of an older event, not a new state.
        $this->sentRow('wamid.R1', WaMessage::FAILED, '131047', 'Message undeliverable');
        $failed = WaMessage::where('meta_id', 'wamid.R1')->firstOrFail();
        $failedAt = $failed->status_at->toDateTimeString();

        ProcessInboundEvent::dispatchSync($this->statusItem('wamid.R1', 'delivered', now()->subHour()));

        $m = WaMessage::where('meta_id', 'wamid.R1')->firstOrFail();
        $this->assertSame(WaMessage::FAILED, $m->status);
        $this->assertSame('131047', $m->error_code);
        $this->assertSame($failedAt, $m->status_at->toDateTimeString(), 'and it must not move the clock either');
    }

    public function test_a_second_record_cannot_downgrade_a_failed_row(): void
    {
        // R30, the Task 6 path: the sender re-records the same wamid. applyStatus() was not the
        // only writer, which is how round 1 left this open.
        $c = $this->sentRow('wamid.R2', WaMessage::FAILED, '131047', 'Message undeliverable');
        WaMessage::record([
            'wa_contact_id' => $c->id, 'meta_id' => 'wamid.R2', 'direction' => WaMessage::OUT,
            'type' => 'template', 'status' => WaMessage::SENT, 'sent_at' => now(),
        ]);

        $m = WaMessage::where('meta_id', 'wamid.R2')->firstOrFail();
        $this->assertSame(WaMessage::FAILED, $m->status);
        $this->assertSame('Message undeliverable', $m->error_title);
    }

    public function test_a_read_cannot_resurrect_a_deleted_message(): void
    {
        $this->sentRow('wamid.R3', WaMessage::DELETED);
        ProcessInboundEvent::dispatchSync($this->statusItem('wamid.R3', 'read', now()));

        $this->assertSame(WaMessage::DELETED, WaMessage::where('meta_id', 'wamid.R3')->value('status'));
    }

    public function test_an_inbound_row_never_acquires_a_delivery_state(): void
    {
        $c = WaContact::factory()->create(['phone' => '18479043315']);
        WaMessage::record([
            'wa_contact_id' => $c->id, 'meta_id' => 'wamid.R4', 'direction' => WaMessage::IN,
            'type' => 'text', 'body' => 'hi', 'status' => WaMessage::INCOMING, 'sent_at' => now(),
        ]);

        ProcessInboundEvent::dispatchSync($this->statusItem('wamid.R4', 'delivered', now()));

        $this->assertSame(WaMessage::INCOMING, WaMessage::where('meta_id', 'wamid.R4')->value('status'));
    }

    public function test_a_record_over_an_inbound_row_cannot_write_a_delivery_state(): void
    {
        // R30 on the other writer. R4 covers applyStatus(), which has its own direction check; the
        // same guard sits in record(), and Task 13's import re-records wamids through that path.
        $c = WaContact::factory()->create(['phone' => '18479043315']);
        WaMessage::record([
            'wa_contact_id' => $c->id, 'meta_id' => 'wamid.R9', 'direction' => WaMessage::IN,
            'type' => 'text', 'body' => 'hi', 'status' => WaMessage::INCOMING, 'sent_at' => now(),
        ]);

        WaMessage::record([
            'wa_contact_id' => $c->id, 'meta_id' => 'wamid.R9', 'direction' => WaMessage::IN,
            'type' => 'text', 'body' => 'hi', 'status' => WaMessage::DELIVERED, 'sent_at' => now(),
        ]);

        $this->assertSame(WaMessage::INCOMING, WaMessage::where('meta_id', 'wamid.R9')->value('status'));
    }

    public function test_a_record_cannot_re_parent_or_turn_an_existing_wamid(): void
    {
        // R30's identity half. A wamid's contact and direction are settled when its row is created,
        // so a callback that disagrees about either is noise, not news. Deleting the unset() that
        // drops those two keys from an update left the whole suite green until this test existed.
        $jane = WaContact::factory()->create(['phone' => '18479043315']);
        $other = WaContact::factory()->create(['phone' => '15551234567']);

        WaMessage::record([
            'wa_contact_id' => $jane->id, 'meta_id' => 'wamid.R10', 'direction' => WaMessage::IN,
            'type' => 'text', 'body' => 'hi', 'status' => WaMessage::INCOMING, 'sent_at' => now(),
        ]);

        WaMessage::record([
            'wa_contact_id' => $other->id, 'meta_id' => 'wamid.R10', 'direction' => WaMessage::OUT,
            'type' => 'text', 'body' => 'corrected in place', 'status' => WaMessage::SENT,
            'sent_at' => now(),
        ]);

        $m = WaMessage::firstWhere('meta_id', 'wamid.R10');
        $this->assertSame(1, WaMessage::where('meta_id', 'wamid.R10')->count());
        $this->assertSame($jane->id, (int) $m->wa_contact_id, 'a disagreeing callback must not re-parent the row');
        $this->assertSame(WaMessage::IN, $m->direction);
        $this->assertSame('corrected in place', $m->body, 'only identity and status are refused, not the payload');
    }

    public function test_the_error_fields_travel_with_the_status_that_wrote_them(): void
    {
        $c = WaContact::factory()->create(['phone' => '18479043315']);
        WaMessage::record([
            'wa_contact_id' => $c->id, 'meta_id' => 'wamid.R5', 'direction' => WaMessage::OUT,
            'type' => 'template', 'status' => WaMessage::DELIVERED, 'sent_at' => now(),
            'error_code' => '131047', 'error_title' => 'Message undeliverable',
        ]);

        // The receipt deliberately arrives still carrying the error fields from the earlier
        // generation: without the ternaries the row would read `delivered` alongside `131047`,
        // the internally impossible pair Task 11 would paint green.
        ProcessInboundEvent::dispatchSync($this->statusItem('wamid.R5', 'read', now(), '131047', 'Message undeliverable'));

        $m = WaMessage::where('meta_id', 'wamid.R5')->firstOrFail();
        $this->assertSame(WaMessage::READ, $m->status);
        $this->assertNull($m->error_code, 'a row must not hold an error from another generation');
        $this->assertNull($m->error_title);
    }

    public function test_an_older_inbound_does_not_pull_the_service_window_backwards(): void
    {
        // The monotonic half of touchInbound(), which round 1 left untested: with the greaterThan
        // comparison deleted the whole suite still passed, and windowOpen() flipped true -> false.
        $c = WaContact::factory()->create(['phone' => '18479043315', 'last_inbound_at' => now()]);
        $before = $c->last_inbound_at->toDateTimeString();
        $item = [
            'kind' => 'message', 'meta_id' => 'wamid.R6', 'phone' => '18479043315',
            'direction' => WaMessage::IN, 'type' => 'text', 'text' => 'late replay',
            'template_name' => null, 'template_lang' => null, 'button_id' => null,
            'sent_at' => now()->subHours(30), 'raw' => [],
        ];

        ProcessInboundEvent::dispatchSync($item);

        $this->assertSame($before, $c->refresh()->last_inbound_at->toDateTimeString());
        $this->assertTrue($c->windowOpen());
    }

    public function test_an_inbound_without_a_usable_timestamp_does_not_open_the_window(): void
    {
        // R31's other side. The row still gets a sent_at, because the column is NOT NULL, but an
        // invented time is not proof that the customer replied inside 24 hours -- and last_inbound_at
        // is what Task 12's free-form gate reads. Pessimistic is the only safe direction here.
        $c = WaContact::factory()->create(['phone' => '18479043315', 'last_inbound_at' => now()->subHours(30)]);

        ProcessInboundEvent::dispatchSync([
            'kind' => 'message', 'meta_id' => 'wamid.R7', 'phone' => '18479043315',
            'direction' => WaMessage::IN, 'type' => 'text', 'text' => 'no stamp',
            'template_name' => null, 'template_lang' => null, 'button_id' => null,
            'sent_at' => null, 'raw' => [],
        ]);

        $this->assertFalse($c->refresh()->windowOpen());
        $this->assertNotNull(WaMessage::where('meta_id', 'wamid.R7')->value('sent_at'));
    }

    public function test_a_receipt_naming_another_number_does_not_move_this_contacts_row(): void
    {
        $a = $this->sentRow('wamid.R8', WaMessage::SENT);
        WaContact::factory()->create(['phone' => '15551234567']);
        $item = $this->statusItem('wamid.R8', 'read', now());
        $item['phone'] = '15551234567';

        ProcessInboundEvent::dispatchSync($item);

        $this->assertSame(WaMessage::SENT, WaMessage::where('meta_id', 'wamid.R8')->value('status'));
        $this->assertSame($a->id, (int) WaMessage::where('meta_id', 'wamid.R8')->value('wa_contact_id'));
    }
```

One more edit to that file, in `test_the_outbound_echo_does_not_erase_a_receipt_already_recorded`
(M-4): the status assertion there also passed when `record()` was mutated into a plain `create()`,
because `firstOrFail()` returned the seeded row and the duplicate was ignored. Immediately before the
`assertSame(WaMessage::DELIVERED, …)` line, add:

```php
        $this->assertCount(1, WaMessage::where('meta_id', 'wamid.OUT1')->get(),
            'the echo must update the sender row, never add a second one');
```

Append to `tests/Feature/WebhookParserTest.php` (16 tests after this) -- R31 and R32 at the reader,
where the rest of the band lives:

```php
    public function test_a_timestamp_in_the_future_is_not_a_timestamp(): void
    {
        // R31: the far side of the band is not only a bad date, it is last_inbound_at, and
        // last_inbound_at is the 24-hour service window. 2030 stores happily on MariaDB, so a
        // 2038 ceiling would not have closed this.
        $future = WebhookParser::parse($this->bodyOf([
            'messages' => [['from' => '18479043315', 'id' => 'wamid.F1',
                'timestamp' => '1893456000', 'text' => ['body' => 'hi']]],
        ]), self::OURS);
        $this->assertNull($future['items'][0]['sent_at']);

        $skew = WebhookParser::parse($this->bodyOf([
            'messages' => [['from' => '18479043315', 'id' => 'wamid.F2',
                'timestamp' => (string) Carbon::now()->addHours(2)->getTimestamp(),
                'text' => ['body' => 'hi']]],
        ]), self::OURS);
        $this->assertNotNull($skew['items'][0]['sent_at'], 'a clock a little ahead is still a callback');
    }

    public function test_our_own_number_is_recognised_whatever_punctuation_it_is_configured_with(): void
    {
        // R32: WA_MY_NUMBER is compared against Meta's `from`. '+1 (224) 214-4261' is how a person
        // writes it and how display_phone_number reads; both must still mean "this is our traffic".
        config(['whatsapp.my_number' => '+1 (224) 214-4261']);

        $parsed = WebhookParser::parse($this->bodyOf([
            'messages' => [['from' => '12242144261', 'to' => '18479043315', 'id' => 'wamid.E1',
                'timestamp' => '1793996090', 'type' => 'template',
                'template' => ['name' => 'opener_brand', 'language' => ['code' => 'en_US']]]],
        ]), self::OURS);

        $this->assertSame(WaMessage::OUT, $parsed['items'][0]['direction']);
        $this->assertSame('18479043315', $parsed['items'][0]['phone']);
    }
```

Add `use App\Models\WaMessage;` and `use Illuminate\Support\Carbon;` to that file's imports if they are
not already there.

In `app/Jobs/ProcessInboundEvent.php`, `message()`'s inbound branch and `status()` become (R31's
coalescing rule plus the ownership check the review called M-3 -- `findByMetaId()` is global, so a
receipt has to name the contact whose row it is landing on, and dropping it keeps the 200):

```php
        if ($inbound) {
            // R31: an unusable timestamp is not evidence of a reply time, and last_inbound_at is
            // what opens the service window, so the contact is left alone. The row above still
            // gets now(), because wa_messages.sent_at is NOT NULL.
            if ($item['sent_at'] !== null) {
                $contact->touchInbound($item['sent_at']);
            }
            // Task 7 adds the automatic-response call here.
        }
```

```php
    private function status(): void
    {
        $item = $this->item;
        $row = WaMessage::findByMetaId($item['meta_id']);
        if ($row === null) {
            return;
        }

        $contactId = WaContact::where('phone', $item['phone'])->value('id');
        if ($contactId === null || (int) $row->wa_contact_id !== (int) $contactId) {
            return;
        }

        $row->applyStatus($item['status'], $item['status_at'] ?? now(),
            $item['error_code'], $item['error_title']);
    }
```

`$at` is still built once at the top of `message()` and still used for `'sent_at' => $at`; only the
`touchInbound()` call changes what it does with a null.

- [ ] **Step 9: Run round 2**

```bash
php artisan test tests/Feature/IngestRowsTest.php tests/Feature/WebhookParserTest.php
php artisan test
```

Expected: **16 passed** in the rows file and **16** in the parser file, and full suite **61 passed**
(49 after round 1, plus 10 rows tests, plus 2 parser tests). Then prove each new guard is load-bearing
by mutation, one at a time, restoring and re-running after each. The `wamid` letters below are the
tests that must fail, and the measured output is written under each -- this list is the record of the
run, not a prediction. The first pass found three mutations that left the suite green (marked
*found dead*); Step 8's R9 test, Step 8's error-carrying R5 receipt and the R10 identity test are what
closed them, so this round ships two tests more than the ruling first asked for.

Every `Measured:` line below is from the focused two-file run unless it says otherwise: 31 tests at the
time of the run (16 parser + 15 rows), which is why a single failure reads as `1 failed, 30 passed`. The
identity mutation is the one measured against the whole suite, because that is what showed it green.

- `mayReplaceStatus()` returning `true` unconditionally -> R1, R2, R3 fail.
  Measured: `3 failed, 28 passed`. On the *first* pass, before the R9/R10 tests existed, the three
  failures were R1, R3 and round 1's `test_the_outbound_echo_does_not_erase_a_receipt_already_recorded`;
  R2 needed the hardening commit before this mutation could reach it, because `record()` already
  refused a downgrade on a row that existed and R2 is what makes it exist.
- `mayReplaceStatus()` returning `false` unconditionally -> R5 plus round 1's
  `test_status_maps_onto_the_sent_row_and_never_moves_backwards` and
  `test_failed_status_records_the_error` fail, so the rule cannot be a blanket "never write".
  Measured: `3 failed, 28 passed`.
- the `direction === self::IN` check deleted from `record()` -> R9 fails. *Found dead:* the first
  pass deleted only this one and R4 still passed, because R4 goes through `applyStatus()`, which
  carries its own copy of the check -- nothing tested `record()`'s.
- the `unset($attrs['wa_contact_id'], $attrs['direction'])` line deleted from `record()` -> R10 fails.
  Measured: `1 failed, 60 passed`. *Found dead:* the same line stayed green across the whole suite
  until R10 existed, which is the identity half of R30 going unpinned.
- the same check deleted from `applyStatus()` -> R4 fails. Measured: `1 failed, 30 passed` each.
- the `'error_code' => $terminal ? … : null` ternaries replaced with `$code` / `$title` -> R5 fails.
  Measured: `1 failed, 30 passed`. *Found dead* as first written: the receipt R5 dispatched carried no
  error fields, so both versions of the code wrote `null` and the assertion could not tell them
  apart. The test now dispatches a `read` that still holds `131047 / Message undeliverable`, which is
  the case the ternaries exist for.
- `MIN_EPOCH` back to `1` **and** the ceiling back to `4e10` -> the parser's future-timestamp test
  fails. Measured: `1 failed, 30 passed`.
  `test_a_missing_or_absurd_timestamp_is_null_not_now` keeps passing under this one: it only covers
  the near side, which is exactly the gap R31 is about.
- `digitsOnly()` removed from the `$mine` line -> the punctuation test fails. Measured: `1 failed, 30 passed`.
- `greaterThan` removed from `touchInbound()` -> R6 fails (round 1 shipped this mutation unnoticed).
  Measured: `1 failed, 30 passed`.
- the ownership check removed from the job's `status()` -> R8 fails. Measured: `1 failed, 30 passed`.
- the `if ($item['sent_at'] !== null)` guard removed -> R7 fails. Measured: `1 failed, 30 passed`.

All eleven now break something, and each names the test that breaks.

Any mutation that leaves the suite green is a test that does not test anything -- report it rather
than shipping it.

- [ ] **Step 10: Commit round 2**

The code and the first set of tests go in one commit; the two tests Step 9 had to add or strengthen to
make its own mutations bite go in a second, so the ledger shows which round each came from.

```bash
git add app config/database.php tests/Feature/IngestRowsTest.php tests/Feature/WebhookParserTest.php
git commit -m "fix: keep delivery states monotonic, bound timestamps to what the schema can hold"
git add tests/Feature/IngestRowsTest.php
git commit -m "test: make the R30 direction guard and the error-field ternaries actually observable"
```

**Carried out of round 1 without code** (each lands in the task named, and Task 16's list is the one
that must not lose them): a message item with no `id` stores `meta_id = null` and duplicates if Meta
resends it with any byte difference -- the body hash absorbs an *identical* replay, so this is a
Task 13 double-counting note, not a webhook defect (M-5). `00`-prefixed numbers stay distinct from
the same number bare, so one person can be two contacts; if it is fixed it goes inside
`digitsOnly()`, never in a second copy (M-7). The two new files and the fixture carry the Pint
complaints the plan's own text produced, plus one new rule category at
`app/Models/WaContact.php:45` (`!` spacing, straight from Step 4) -- Task 14 sweeps all of it (M-8,
Q4). And **replaying a stored `wa_webhook_events.changes` item through this job is fatal**, because
the `encrypted:array` round trip demotes `sent_at`/`status_at` to strings and `touchInbound()` is
typed: `TypeError … Argument #1 ($at) must be of type Carbon\CarbonInterface, string given`. Nothing
in the plan replays stored items today; any future backfill must go through `WebhookParser` again
rather than through the stored array (M-9).

---

## Task 6: The one outbound send path

**Deliverable:** `WhatsAppSender` is the only code that can write to Meta. It records an `out` row for every attempt, including failures, and never throws at its callers.

**Consumes:** `config('whatsapp.token')`, `api_base`, `phone_id`, `header_images`; `WaContact::fromPhone()`, `WaMessage::record()`.
**Produces:**

```php
App\Services\WhatsAppSender::sendText(string $phone, string $text, bool $autoReply = false): WaMessage
App\Services\WhatsAppSender::sendTemplate(string $phone, string $name, string $lang, array $variables = [], bool $autoReply = false): WaMessage
```

Both return the stored row; `$row->status === WaMessage::FAILED` means Meta refused or no token is configured, with `error_code` / `error_title` set. `meta_id` is non-null only when Meta accepted the send.

**Pre-flight rulings R33, R34 and R35.** Task 6's text was checked against the R24–R32 rule before
anyone implemented it, because this is the first file in the app that writes externally derived text
into bounded columns and the first that has to keep a promise about not throwing. Three defects, each
measured before being ruled. Steps 1 and 3 above and below already carry the corrected final text --
implement what is written, not what these paragraphs describe replacing.

**R33 -- the timeout this task asked for was inert.** The first draft wrote
`->post($url, $payload, ['timeout' => 20])`. `PendingRequest::post()` is declared with two parameters
(`vendor/laravel/framework/src/Illuminate/Http/Client/PendingRequest.php:888`,
`public function post(string $url, $data = [])`), PHP accepts and discards extra arguments to a
user-defined method (measured: a two-parameter method called with three neither errors nor sees the
third), and Laravel's own default is `'timeout' => 30` (`PendingRequest.php:269`). So the code looked
like a 20-second send and was a 30-second one. Fixed by calling `->timeout(20)` on the pending request.
This one is *not* covered by a test and the reason is stated rather than hidden: `Http::fake()` never
touches the transport, so no unit test can see a transport option. The real check is Task 16's live
handoff, against a listener that accepts the connection and never answers; the row it must produce is
`status=failed, error_code=network` inside about 20 seconds, not 30.

**R34 -- Meta's response is external input too.** The webhook rule (R24/R29: untrusted in shape, in
presence and in range, decided once at the reader) was never applied to what comes *back*, and every
value read from the response goes into a bounded column. Measured, on PHP 8.2 with this app's
autoloader:

```
(string) ['a', 'b']        -> Warning: Array to string conversion, value "Array"
error_reporting() & E_WARNING -> HandleExceptions::handleError throws ErrorException (vendor:75-77)
data_get(['messages' => 'x'], 'messages.0.id')                 -> NULL, no warning
data_get(null, 'error.code')                                    -> NULL
str_repeat('x', 400) into error_title varchar(255)              -> SQLSTATE[22001] 1406 on MariaDB,
                                                                   accepted by sqlite (so invisible here)
```

An array-shaped `error.code` therefore made the sender throw at its caller -- a 500 on Task 12's staff
reply, three failed attempts per send for Task 8's queued acknowledgement -- and a long message would
have done the same on the deployed engine while the suite stayed green. The fix is one private reader,
`str(mixed $v, int $max)`, applied at all four sites (wamid 128, error_code 12, error_title 255 twice)
with `data_get()` for the shapes, and the HTTP status as the fallback code when the JSON does not give
a usable one.

**R35 -- the sender must recognise itself by digits, and refuse to talk without a number id.**
`to` took the caller's `$phone` verbatim while `fromPhone()` normalised the contact behind it, so
`+1 (847) 904-3315` stores a clean contact and sends a payload Meta answers with a 400 -- the same
punctuation-dependence R32 measured on the inbound side. Separately, `phone_id` defaults to `''` in
`config/whatsapp.php:6`, and the first draft built `.../v20.0//messages` and made an authenticated
HTTPS request with a real token anyway. A missing token already stopped before the network; a missing
phone number id is the same class of "we do not know who is sending" and stops there too.

- [ ] **Step 1: Write the failing test** — `tests/Feature/WhatsAppSenderTest.php`

```php
<?php

namespace Tests\Feature;

use App\Models\WaMessage;
use App\Services\WhatsAppSender;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WhatsAppSenderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['whatsapp.phone_id' => '1427706937088002', 'whatsapp.token' => 'tok-test']);
        Http::preventStrayRequests();
    }

    public function test_send_text_posts_to_graph_and_records_the_wamid()
    {
        Http::fake(['*' => Http::response(['messages' => [['id' => 'wamid.OUT9']]], 200)]);

        $m = app(WhatsAppSender::class)->sendText('18479043315', 'Hello there');

        $this->assertSame(WaMessage::OUT, $m->direction);
        $this->assertSame(WaMessage::SENT, $m->status);
        $this->assertSame('wamid.OUT9', $m->meta_id);
        $this->assertSame('Hello there', $m->body);
        $this->assertFalse($m->auto_reply);

        Http::assertSent(fn ($r) => $r['type'] === 'text'
            && $r['to'] === '18479043315'
            && $r['text']['body'] === 'Hello there'
            && $r['messaging_product'] === 'whatsapp'
            && $r->url() === 'https://graph.facebook.com/v20.0/1427706937088002/messages');
    }

    public function test_a_400_is_stored_as_failed_with_the_meta_error()
    {
        Http::fake(['*' => Http::response(['error' => [
            'message' => 'Re-engagement message', 'code' => 131030,
            'error_data' => ['details' => 'Message undeliverable'],
        ]], 400)]);

        $m = app(WhatsAppSender::class)->sendText('18479043315', 'Late reply');

        $this->assertSame(WaMessage::FAILED, $m->status);
        $this->assertSame('131030', $m->error_code);
        $this->assertSame('Message undeliverable', $m->error_title);
        $this->assertNull($m->meta_id);
        $this->assertNotNull($m->id, 'a failed send must still be visible in the thread');
    }

    public function test_missing_token_fails_locally_without_calling_graph()
    {
        config(['whatsapp.token' => '']);
        $m = app(WhatsAppSender::class)->sendText('18479043315', 'x');
        $this->assertSame(WaMessage::FAILED, $m->status);
        $this->assertSame('local', $m->error_code);
        $this->assertSame('no access token configured', $m->error_title);
    }

    public function test_template_send_re_attaches_the_approved_header_image()
    {
        config(['whatsapp.header_images' => ['etp_opener_brand' => 'https://example.test/logo.png']]);
        Http::fake(['*' => Http::response(['messages' => [['id' => 'wamid.OUT10']]], 200)]);

        app(WhatsAppSender::class)->sendTemplate('18479043315', 'etp_opener_brand', 'en', ['Sam']);

        Http::assertSent(function ($r) {
            $c = $r['template']['components'];

            return $c[0] === ['type' => 'header', 'parameters' => [[
                'type' => 'image', 'image' => ['link' => 'https://example.test/logo.png']]]]
                && $c[1]['type'] === 'body'
                && $c[1]['parameters'][0]['text'] === 'Sam'
                && $r['template']['language']['code'] === 'en';
        });
        $m = WaMessage::where('meta_id', 'wamid.OUT10')->firstOrFail();
        $this->assertSame('etp_opener_brand', $m->template_name);
        $this->assertSame('en', $m->template_lang);
    }

    public function test_template_without_variables_sends_no_components()
    {
        Http::fake(['*' => Http::response(['messages' => [['id' => 'wamid.OUT11']]], 200)]);
        app(WhatsAppSender::class)->sendTemplate('18479043315', 'etp_welcome_c', 'en_US');
        Http::assertSent(fn ($r) => !array_key_exists('components', $r['template']));
    }

    public function test_a_punctuated_phone_is_stored_and_sent_as_digits()
    {
        // R35: the payload and the contact row must not disagree about who this is.
        Http::fake(['*' => Http::response(['messages' => [['id' => 'wamid.OUT12']]], 200)]);

        $m = app(WhatsAppSender::class)->sendText('+1 (847) 904-3315', 'Hi');

        Http::assertSent(fn ($r) => $r['to'] === '18479043315');
        $this->assertSame('18479043315', $m->contact->phone);
        $this->assertSame('18479043315', $m->raw['to'], 'the stored payload is what was sent');
    }

    public function test_an_unconfigured_phone_id_records_a_local_failure_without_calling_graph()
    {
        // R35: `.../v20.0//messages` is still an authenticated request. Not knowing which number we
        // are has to stop us before the network, exactly as a missing token does.
        config(['whatsapp.phone_id' => '']);
        Http::fake(['*' => Http::response([], 200)]);

        $m = app(WhatsAppSender::class)->sendText('18479043315', 'x');

        Http::assertNothingSent();
        $this->assertSame(WaMessage::FAILED, $m->status);
        $this->assertSame('local', $m->error_code);
        $this->assertSame('no phone number id configured', $m->error_title);
    }

    public function test_a_graph_body_that_is_shaped_wrong_is_recorded_not_thrown()
    {
        // R34: `messages.0.id` as a list is the shape that used to reach a `(string)` cast. There is
        // no id, so it is a failure, and its code falls back to the HTTP status.
        Http::fake(['*' => Http::response(['messages' => [['id' => ['w', 'x']]]], 200)]);

        $m = app(WhatsAppSender::class)->sendText('18479043315', 'x');

        $this->assertSame(WaMessage::FAILED, $m->status);
        $this->assertNull($m->meta_id);
        $this->assertSame('200', $m->error_code);
        $this->assertSame('send rejected', $m->error_title);
    }

    public function test_a_connection_failure_is_recorded_as_network_without_throwing()
    {
        // R38: the network is one of the four outcomes the deliverable names, and until now nothing
        // reached the catch -- every fake answered, and the two config guards return before the
        // request. A cURL error message is unbounded text that lands in error_title(255), so the same
        // reader caps it here as it does Meta's own message.
        Http::fake(['*' => fn () => throw new \Illuminate\Http\Client\ConnectionException(
            str_repeat('x', 400))]);

        $m = app(WhatsAppSender::class)->sendText('18479043315', 'x');

        $this->assertSame(WaMessage::FAILED, $m->status);
        $this->assertSame('network', $m->error_code);
        $this->assertSame(255, mb_strlen($m->error_title));
        $this->assertNull($m->meta_id);
    }

    public function test_error_details_that_are_shaped_wrong_fall_back_to_the_message()
    {
        // R34 again, one branch further down: ?? picks the first operand whenever it is not null, so
        // an error_data.details that arrives as a list was kept, turned into '' by str(), and the
        // readable error.message next to it was never tried. The row said "send rejected" when Meta
        // had actually told us why.
        Http::fake(['*' => Http::response(['error' => [
            'message' => 'Limitation was applied due to poor quality rating',
            'code' => 131056,
            'error_data' => ['details' => ['unexpected', 'shape']],
        ]], 400)]);

        $m = app(WhatsAppSender::class)->sendText('18479043315', 'x');

        $this->assertSame('131056', $m->error_code);
        $this->assertSame('Limitation was applied due to poor quality rating', $m->error_title);
    }

    public function test_error_text_from_graph_is_cut_to_the_column_it_lands_in()
    {
        // R34: error_title is varchar(255) and both Meta's message and cURL's message are unbounded.
        // On sqlite an over-long insert succeeds, which is how the suite would forget this bug, so
        // the assertion is on the stored length -- the part a caller can observe on either engine.
        Http::fake(['*' => Http::response(['error' => [
            'message' => str_repeat('x', 400), 'code' => 131047,
        ]], 400)]);

        $m = app(WhatsAppSender::class)->sendText('18479043315', 'x');

        $this->assertSame(WaMessage::FAILED, $m->status);
        $this->assertSame('131047', $m->error_code);
        $this->assertSame(255, mb_strlen($m->error_title));
    }
}
```

- [ ] **Step 2: Run it** — expected FAIL `Class "App\Services\WhatsAppSender" not found`.

- [ ] **Step 3: Implement `app/Services/WhatsAppSender.php`**

```php
<?php

namespace App\Services;

use App\Models\WaContact;
use App\Models\WaMessage;
use Illuminate\Http\Client\Factory;

class WhatsAppSender
{
    public function __construct(private Factory $http)
    {
    }

    public function sendText(string $phone, string $text, bool $autoReply = false): WaMessage
    {
        return $this->post(
            ['messaging_product' => 'whatsapp', 'to' => $phone, 'type' => 'text',
             'text' => ['preview_url' => false, 'body' => $text]],
            $phone,
            ['direction' => WaMessage::OUT, 'type' => 'text', 'body' => $text,
             'auto_reply' => $autoReply],
        );
    }

    public function sendTemplate(string $phone, string $name, string $lang,
                                 array $variables = [], bool $autoReply = false): WaMessage
    {
        $components = [];
        $image = (string) (config('whatsapp.header_images')[$name] ?? '');
        if ($image !== '') {
            $components[] = ['type' => 'header', 'parameters' => [
                ['type' => 'image', 'image' => ['link' => $image]]]];
        }
        if ($variables !== []) {
            $components[] = ['type' => 'body', 'parameters' => array_map(
                fn ($v) => ['type' => 'text', 'text' => (string) $v], $variables)];
        }

        $template = ['name' => $name, 'language' => ['code' => $lang]];
        if ($components !== []) {
            $template['components'] = $components;
        }

        return $this->post(
            ['messaging_product' => 'whatsapp', 'to' => $phone, 'type' => 'template',
             'template' => $template],
            $phone,
            ['direction' => WaMessage::OUT, 'type' => 'template', 'template_name' => $name,
             'template_lang' => $lang, 'auto_reply' => $autoReply],
        );
    }

    private static function str(mixed $v, int $max): string
    {
        $s = match (true) {
            is_string($v) => $v,
            is_int($v) || is_float($v) => (string) $v,
            default => '',
        };

        return mb_substr($s, 0, $max);
    }

    private function post(array $payload, string $phone, array $attrs): WaMessage
    {
        // R35: the contact row and the payload have to agree. fromPhone() normalises internally, so
        // the first draft stored digits while sending the caller's punctuation -- Meta answers that
        // with a 400 for a contact we filed cleanly. Normalising once, here, is the R32 rule pointed
        // at the outbound direction.
        $phone = WaContact::digitsOnly($phone);
        $payload['to'] = $phone;

        $contact = WaContact::fromPhone($phone);
        $token = (string) config('whatsapp.token', '');
        $phoneId = (string) config('whatsapp.phone_id', '');

        // R35: an unconfigured phone number id is the same kind of "we do not know who is sending" as
        // an unconfigured token, and without this guard the app still makes an authenticated HTTPS
        // request to .../v20.0//messages. Nothing goes out when either is missing.
        if ($token === '' || $phoneId === '') {
            return WaMessage::record($attrs + [
                'wa_contact_id' => $contact->id, 'meta_id' => null,
                'status' => WaMessage::FAILED, 'status_at' => now(),
                'error_code' => 'local',
                'error_title' => $token === '' ? 'no access token configured' : 'no phone number id configured',
                'sent_at' => now(), 'raw' => $payload,
            ]);
        }

        $url = rtrim((string) config('whatsapp.api_base'), '/') . '/' . $phoneId . '/messages';

        try {
            // R33: ->timeout() is the option. post() is declared post(string $url, $data = [])
            // (vendor PendingRequest:888), so a third argument is accepted by PHP and thrown away --
            // the first draft looked like it waited 20 seconds and actually waited Laravel's own
            // default of 30 (vendor PendingRequest:269).
            $res = $this->http->withToken($token)->asJson()->timeout(20)->post($url, $payload);
        } catch (\Throwable $e) {
            return WaMessage::record($attrs + [
                'wa_contact_id' => $contact->id, 'meta_id' => null,
                'status' => WaMessage::FAILED, 'status_at' => now(),
                'error_code' => 'network', 'error_title' => self::str($e->getMessage(), 255),
                'sent_at' => now(), 'raw' => $payload,
            ]);
        }

        // R34: Meta's response is external input too -- in shape, in presence and in range -- and
        // every value below lands in a bounded column. data_get() is the null-safe reader (measured:
        // a scalar `messages`, a null body and a missing key all return null with no warning), and
        // str() is the width. `(string) ['a']` would have emitted `Warning: Array to string
        // conversion`, which HandleExceptions::handleError (vendor:75-77) turns into an ErrorException
        // in every environment -- i.e. the sender throwing at its caller, which the deliverable
        // forbids -- and a 400-character `error.message` into error_title(255) is the 1406 that
        // sqlite lets the suite forget about (the round-1 review measured both widths on MariaDB).
        $json = $res->json() ?? [];
        $wamid = self::str(data_get($json, 'messages.0.id'), 128);

        if ($res->failed() || $wamid === '') {
            $code = self::str(data_get($json, 'error.code'), 12);
            $title = self::str(data_get($json, 'error.error_data.details'), 255)
                ?: self::str(data_get($json, 'error.message'), 255);

            return WaMessage::record($attrs + [
                'wa_contact_id' => $contact->id, 'meta_id' => null,
                'status' => WaMessage::FAILED, 'status_at' => now(),
                'error_code' => $code === '' ? (string) $res->status() : $code,
                'error_title' => $title === '' ? 'send rejected' : $title,
                'sent_at' => now(), 'raw' => $payload,
            ]);
        }

        return WaMessage::record($attrs + [
            'wa_contact_id' => $contact->id, 'meta_id' => $wamid,
            'status' => WaMessage::SENT, 'status_at' => now(),
            'sent_at' => now(), 'raw' => $payload,
        ]);
    }
}
```

**One boundary this class keeps, deliberately:** `WaContact::fromPhone($phone)` still throws for a
`$phone` that is not dialable, and that is not a defect. The row cannot exist without a contact
(`wa_contact_id` is a NOT NULL FK), so "you passed me something that is not a number" is a caller bug
and the honest answer is an exception. Every caller in this plan passes `$contact->phone` from the
database, which the parser already bound to 10–20 digits (R28). The rule the deliverable states --
never throws at its callers -- is about what Meta and the network can do, and R33/R34 are what make
that true.

**Convergence, recorded so it is not lost (Task 14):** `str()` here and `str()`/`nullableStr()`/
`path()` in `WebhookParser` are the same idea twice. Task 14 moves them into one
`App\Support\Read` and re-checks every cap against the migration, because two copies of a width
literal are exactly what drifts. Doing it now would have rewritten Task 4's shipped blocks, which is
the wrong trade inside a task whose deliverable is the send path.

**R38 -- an outcome the deliverable names has to be reached by a test, not only by code.** The task
states four outcomes: accepted, Meta-rejected, network-thrown, config-refused. Nine tests covered
three of them; nothing in the file ever reached the `catch`, because every fake answered and both
config guards return before the request is built. So the fourth outcome was a branch with a name and
no witness: narrowing `catch (\Throwable $e)` to `catch (\InvalidArgumentException $e)` -- which lets
`ConnectionException` escape and breaks the "never throws at its caller" deliverable outright -- left
the suite green, and so did dropping the `str()` cap on `$e->getMessage()`. `test_a_connection_failure_is_recorded_as_network_without_throwing`
is the witness; a throwing stub (`Http::fake(['*' => fn () => throw new ConnectionException(...)])`)
is how the fake layer reaches the transport-failure shape without touching the network. The same pass
found a real defect one line away (the reviewer called it M-b): `data_get($j,'error.error_data.details')
?? data_get($j,'error.message')` reads as "prefer details, fall back to the message", but `??` only
skips null, so a `details` that arrives as a *list* is kept, becomes `''` in `str()`, and the readable
`error.message` sitting next to it is never tried -- the row said `send rejected` when Meta had told
us why. `?:` between two `str()` calls is the reading that matches the intent, and
`test_error_details_that_are_shaped_wrong_fall_back_to_the_message` pins it (measured: fails against
the `??` form, passes against `?:`).

**Two findings this task did not take, and where they actually live.** The review also flagged that
`(string) $v` in `sendTemplate()` throws if a variable is an array, and that `template_lang` reaches a
varchar(10) uncapped. Both are true of the *class* in isolation and false of the *system*: every caller
the plan defines builds `$variables` from `explode(',', (string) $data['variables'])` -- strings by
construction -- and validates `'language' => 'max:10'`, which is the column width (Task 12, and Task 10's
`recordSend` does the same). Adding a second coercion inside the sender would be validating an internal
call boundary twice, and R34 is about *external* input, which this is not. The genuinely uncapped path
is Task 13's CSV import, which reads `$row['language']` and `$row['template']` straight from a file into
those columns with no validation in front of it; that is external input and it is fixed there, not by
duplicating a guard here.

**Cleanup that was not optional (M-a).** `post()`'s fourth parameter `bool $autoReply` was dead -- it
was already inside `$attrs`, and the body never read the parameter. A private signature that lies about
what it needs is exactly what Tasks 7-13 will mis-call, so the parameter is gone from the signature and
both call sites. No behaviour change; the eleven tests and the 73-pass suite are the check.

- [ ] **Step 4: Run the test**

```bash
php artisan test tests/Feature/WhatsAppSenderTest.php
```

Expected: **11 passed** (the five that describe the happy and 400 paths, the four R34/R35 tests, and
the two R38 tests). Full suite after this task: **73 passed** (62 after Task 5's rounds, which include
R37's relation test, plus these 11). Then mutate each new guard once, restoring and re-running after
each, and report any mutation that leaves the suite green:

- the two `error_title` reads through `str()` back to a bare value, and the `str()` calls losing their
  widths -> `test_error_text_from_graph_is_cut_to_the_column_it_lands_in` fails.
- `str()`'s numeric branch dropped (`is_string($v) ? $v : ''`) -> the 400 test's `error_code` assertion
  fails, because Meta sends its code as an integer.
- `$payload['to'] = $phone;` removed -> `test_a_punctuated_phone_is_stored_and_sent_as_digits` fails.
- `$phoneId === ''` removed from the guard -> `test_an_unconfigured_phone_id_records_a_local_failure_without_calling_graph`
  fails, and `Http::assertNothingSent()` answers first.
- `data_get($json, 'messages.0.id')` back to `$json['messages'][0]['id'] ?? null`, with the `=== ''`
  comparison back to `=== null` -> `test_a_graph_body_that_is_shaped_wrong_is_recorded_not_thrown`
  fails, and it fails with an exception rather than an assertion -- which is the whole point of R34.
- `catch (\Throwable $e)` narrowed to `catch (\InvalidArgumentException $e)`, so a `ConnectionException`
  escapes -> `test_a_connection_failure_is_recorded_as_network_without_throwing` fails (R38).
- `self::str($e->getMessage(), 255)` back to a bare cast -> the same test fails on the length.
- `?:` back to `??` between the two `str()` calls ->
  `test_error_details_that_are_shaped_wrong_fall_back_to_the_message` fails (R38).
- `$code === '' ? (string) $res->status() : $code` back to `$code` -> `test_a_graph_body_that_is_shaped_wrong_is_recorded_not_thrown`
  fails, because a body with no `error.code` would then store an empty code.

**Measured, and one correction to the record (Task 6 Step 4).** All nine bit. Round 1: M1 caps lost -> 2
failed (`shaped-wrong`, because an uncapped `$title` is null for a body with no `error` key, and
`cut-to-column`); M2 numeric branch -> 2 failed (the 400 test and `cut-to-column`); M3 payload `to` ->
1 failed (`punctuated-phone`); M4 `phone_id` guard -> 1 failed (`unconfigured-phone-id`); M5 unguarded
read -> 1 failed (`shaped-wrong`). Round 2, after the review: N1 narrowed catch -> 1 failed; N2 uncapped
network message -> 1 failed; N3 `??` restored -> 1 failed; N4 no status fallback -> 1 failed. So the
bullets above under-report: each of those guards is pinned by more than the test named next to it. The
pass also has to disclose its own tooling: the driver that produced the *first* Task 6 numbers
destructured its mutation table with `const [, name, ...pairs]` instead of `const [name, ...pairs]`,
which silently dropped every mutation's first substitution pair -- so three single-pair mutations were
never applied, the file was byte-identical to the original, and I wrote those runs up as three dead
mutations and almost "strengthened" tests that already worked. Fixed driver, `landed=` flag printed per
run, and `restored_identical true` after every restore. The lesson is the R36 lesson again: an absence of
evidence produced by your own harness is not evidence of absence, and a mutation result is only
trustworthy if the harness proves the edit landed.

- [ ] **Step 5: Commit**

```bash
git add app/Services/WhatsAppSender.php tests/Feature/WhatsAppSenderTest.php
git commit -m "feat: single Graph write path that records every send attempt"
```

---

## Task 7: Opt-out rule

**Deliverable:** STOP-family text or button sets `opted_out_at` before anything else happens, sends exactly one free-form confirmation, and never re-confirms for an already suppressed contact.

**Consumes:** `WaMessage` rows from Task 5, `WhatsAppSender` from Task 6.
**Produces:** `App\Support\OptOutKeywords::matches(?string $text, ?string $buttonId = null): bool`, `App\Services\AutoResponder::handle(WaMessage $in, ?string $buttonId = null): void`.

- [ ] **Step 1: Write the failing tests** — `tests/Unit/OptOutKeywordsTest.php`

```php
<?php

namespace Tests\Unit;

use App\Support\OptOutKeywords;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class OptOutKeywordsTest extends TestCase
{
    public static function keywordProvider(): array
    {
        return [
            ['stop'], ['STOP'], [' Stop '], ['stop.'], ['end'], ['cancel'],
            ['unsubscribe'], ['opt out'], ['OPT-OUT'], ['optout'], ['quit'], ['remove'],
            ['please STOP and email me'], ['stop, please'], ['STOP. I said it'],
        ];
    }

    #[DataProvider('keywordProvider')]
    public function test_first_line_matches_the_fixed_set(string $text)
    {
        $this->assertTrue(OptOutKeywords::matches($text));
    }

    public static function notKeywordProvider(): array
    {
        return [
            ['what is a deadline'], ['started'], ['stopped'], ['stopwatch'], ['nonstop'],
            ['ending'], ['removal'], ['cancellation'], ['renewal'], ['options'],
            [''], [null], ['   '],
        ];
    }

    #[DataProvider('notKeywordProvider')]
    public function test_does_not_match(?string $text)
    {
        $this->assertFalse(OptOutKeywords::matches($text));
    }

    public function test_multi_line_uses_only_the_first_line()
    {
        $this->assertTrue(OptOutKeywords::matches("STOP\nbut actually I have a question"));
        $this->assertFalse(OptOutKeywords::matches("hello\nstop that"));
    }

    public function test_button_id_stop_matches()
    {
        $this->assertTrue(OptOutKeywords::matches(null, 'STOP'));
        $this->assertTrue(OptOutKeywords::matches('anything', 'stop'));
        $this->assertFalse(OptOutKeywords::matches('anything', 'TALK_TO_AGENT'));
    }

    /**
     * R42: the trim() in the button branch is live behaviour -- a padded id is a real payload shape, not a
     * theoretical one -- so it is pinned rather than left to a green suite that never sends one.
     */
    public function test_a_padded_button_id_still_matches()
    {
        $this->assertTrue(OptOutKeywords::matches(null, ' STOP '));
        $this->assertTrue(OptOutKeywords::matches(null, "\tstop\n"));
    }

    public static function phraseProvider(): array
    {
        return [
            ['I want to opt out now'], ['please opt out'], ['opt out please'],
            ['OPT OUT'], [' opt out.'], ['i want to opt out of these messages'],
            ['opt me out'], ['opt me out please'], ['please opt me out of this'],
        ];
    }

    /**
     * R45: "opt out" is two words, so the old word-by-word scan could only ever match it as a whole
     * line -- "I want to opt out now" did not suppress, while "please opt-out now" did. A missed
     * opt-out is the unrecoverable direction, so the phrase must match anywhere in the first line.
     *
     */
    #[DataProvider('phraseProvider')]
    public function test_the_two_word_phrase_matches_anywhere(string $text)
    {
        $this->assertTrue(OptOutKeywords::matches($text));
    }

    public static function ambiguousProvider(): array
    {
        return [
            ['does the fiscal year end in december'], ['end of month pricing'],
            ['when does the form end'], ['remove the filing later'], ['please remove me'],
            ['I want to end the call earlier'],
        ];
    }

    /**
     * R45: `end` and `remove` are ordinary English in a tax conversation, so they only mean opt-out
     * when they are the entire message. This is the tier that keeps the loose direction honest instead
     * of silencing a client who asked about a deadline.
     *
     */
    #[DataProvider('ambiguousProvider')]
    public function test_ambiguous_words_do_not_fire_mid_sentence(string $text)
    {
        $this->assertFalse(OptOutKeywords::matches($text));
    }

    public static function wholeMessageProvider(): array
    {
        return [['end'], ['remove'], ['END'], [' remove '], ['end.']];
    }

    #[DataProvider('wholeMessageProvider')]
    public function test_ambiguous_words_still_fire_when_they_are_the_whole_message(string $text)
    {
        $this->assertTrue(OptOutKeywords::matches($text), "'{$text}' is a bare opt-out word");
    }

    public static function unambiguousAnywhereProvider(): array
    {
        return [
            ['please STOP and email me'], ['you can unsubscribe me now'], ['cancel my plan'],
            ['I quit this service'], ['optout of everything'],
        ];
    }

    #[DataProvider('unambiguousAnywhereProvider')]
    public function test_unambiguous_words_fire_anywhere_including_the_phrase_ones(string $text)
    {
        $this->assertTrue(OptOutKeywords::matches($text), "'{$text}' should suppress");
    }

    /**
     * Reviewer F-4: iOS autocorrect and pasted text emit U+00A0 and friends, which an ASCII-only \s and
     * trim() do not see as separators -- so a space can hide the keyword. A hidden keyword is a missed
     * opt-out, the unrecoverable direction, hence the Unicode-aware split.
     *
     */
    #[DataProvider('unicodeSpaceProvider')]
    public function test_unicode_spaces_do_not_hide_the_keyword(string $text)
    {
        $this->assertTrue(OptOutKeywords::matches($text), 'a non-ASCII space must not hide the keyword');
    }

    public static function unicodeSpaceProvider(): array
    {
        return [
            ["\u{00A0}stop"], ["stop\u{00A0}sending"], ["opt\u{00A0}out"], ["\u{2009}stop"],
            ["\u{3000}unsubscribe"], ["please\u{00A0}opt out"], ["\u{00A0}end"],
        ];
    }

    #[DataProvider('unicodeSpaceSafeProvider')]
    public function test_a_non_ascii_space_alone_does_not_invent_a_keyword(string $text)
    {
        $this->assertFalse(OptOutKeywords::matches($text), 'normalising spaces must not invent a keyword');
    }

    public static function unicodeSpaceSafeProvider(): array
    {
        return [["\u{00A0}hello there"], ["what\u{00A0}time do you end"]];
    }

    /**
     * R47: F-4 made the split Unicode-aware but left the punctuation strips byte-level, so the same
     * missed-opt-out class survives one axis over. iOS autocorrect converts straight quotes to curly ones
     * unasked, and a trailing "…" is a common way to end a one-word message. A byte-level strip cannot see
     * either, and for `end`/`remove` one stray mark defeats the whole-message tier as well as the word
     * scan, because the token no longer equals the keyword.
     *
     */
    #[DataProvider('unicodePunctuationProvider')]
    public function test_unicode_punctuation_does_not_hide_the_keyword(string $text)
    {
        $this->assertTrue(OptOutKeywords::matches($text), 'a non-ASCII mark must not hide the keyword');
    }

    public static function unicodePunctuationProvider(): array
    {
        return [
            ["stop\u{2026}"], ["\u{201C}stop\u{201D}"], ["end\u{2026}"],
            ["end\u{3002}"], ["remove\u{2011}"], ["\u{201C}remove\u{201D}"],
        ];
    }

    #[DataProvider('unicodePunctuationSafeProvider')]
    public function test_stripping_non_ascii_marks_does_not_invent_a_keyword(string $text)
    {
        $this->assertFalse(OptOutKeywords::matches($text), 'normalising punctuation must not invent a keyword');
    }

    public static function unicodePunctuationSafeProvider(): array
    {
        return [
            ["\u{201C}hello\u{201D}"], ['stopwatch.'], ["removal\u{2026}"],
            ["\u{2026}end of month"], ['options?'],
        ];
    }

    /**
     * R42: the `?: []` fallback in the tokeniser is reachable, not defensive decoration -- a handset can
     * put bytes that are not valid UTF-8 on the wire and preg_split('/\s+/u', ...) returns false for them.
     * Undecodable text cannot be read as an opt-out, so the honest outcome is "no keyword": that leaves the
     * message for a human instead of throwing inside the queued job, and it is the direction a client can
     * still be replied to in the thread.
     */
    public function test_text_that_is_not_valid_utf8_neither_matches_nor_throws()
    {
        $this->assertFalse(OptOutKeywords::matches("\xFF\xFE stop"));
        $this->assertFalse(OptOutKeywords::matches("\xC3\x28 end"));
    }
}
```

The `@dataProvider` annotations above work on PHPUnit 11; the methods must be `public static function` returning `array` as written.

- [ ] **Step 2: Run it** — expected FAIL class not found.

- [ ] **Step 3: Implement `app/Support/OptOutKeywords.php`**

```php
<?php

namespace App\Support;

class OptOutKeywords
{
    /** Words that only ever read as an opt-out request, wherever they sit in the line. */
    public const KEYWORDS = ['stop', 'cancel', 'unsubscribe', 'opt-out', 'optout', 'quit'];

    /** Word sequences that mean opt-out only together, in this order. */
    public const PHRASES = [['opt', 'out'], ['opt', 'me', 'out']];

    /** Ordinary English in a tax conversation, so they count only as the whole message. */
    public const WHOLE_MESSAGE = ['end', 'remove'];

    public static function matches(?string $text, ?string $buttonId = null): bool
    {
        if ($buttonId !== null && strtolower(trim($buttonId)) === 'stop') {
            return true;
        }

        $text = trim((string) $text);

        $first = strtolower((string) strtok($text, "\n"));
        $words = self::words($first);

        foreach ($words as $word) {
            if (in_array($word, self::KEYWORDS, true)) {
                return true;
            }
        }

        foreach (self::PHRASES as $phrase) {
            if (self::containsSequence($words, $phrase)) {
                return true;
            }
        }

        return count($words) === 1 && in_array($words[0], self::WHOLE_MESSAGE, true);
    }

    /**
     * @return list<string>
     */
    private static function words(string $line): array
    {
        // Both /u modifiers are load-bearing, and each closes a different way a mark can hide a keyword:
        // without the split's, an iOS U+00A0 glues "stop" to its neighbour; without the strip's, a curly
        // quote or a trailing "…" does the same to the token itself -- and a mark that spoils the token
        // also spoils the one-word whole-message tier, so "end…" is lost twice over. A hidden keyword
        // silences nobody, which is the unrecoverable direction; stripping only at token boundaries keeps
        // whole-word discipline, so "stopped", "removal" and "xopt outx" stay silent.
        $clean = [];
        foreach (preg_split('/\s+/u', $line) ?: [] as $token) {
            $word = preg_replace('/^[\p{P}\p{S}]+|[\p{P}\p{S}]+$/u', '', $token);
            if ($word !== '') {
                $clean[] = $word;
            }
        }

        return $clean;
    }

    /**
     * @param  list<string>  $words
     * @param  list<string>  $sequence
     */
    private static function containsSequence(array $words, array $sequence): bool
    {
        $span = count($sequence);

        // The bound itself is the length guard: when $span exceeds the line, `0 <= count - $span` is
        // false and the loop never runs.
        for ($i = 0; $i <= count($words) - $span; $i++) {
            if (array_slice($words, $i, $span) === $sequence) {
                return true;
            }
        }

        return false;
    }
}
```

- [ ] **Step 4: Run it**

```bash
php artisan test tests/Unit/OptOutKeywordsTest.php
```

Expected: 77 passed (82 assertions).

The matcher is tiered, not a single flat list -- **R45** below is the ruling and the measurements behind it. `stop`, `cancel`, `unsubscribe`, `opt-out`, `optout`, `quit` and the phrases `opt out` and `opt me out` match anywhere in the first line; `end` and `remove` only when they are the whole message; neither a non-ASCII space nor a non-ASCII mark around a word hides any of them (R45 opened that axis for spaces, **R47** closed it for punctuation and replaced the two byte-level punctuation strips with one Unicode-aware layer). So "i want to stop paying taxes" and "cancel my filing" suppress, and "does the fiscal year end in december" does not. Suppression blocks outbound sends only -- the inbound message is still stored and still visible in the thread, so a human can read it and call them. A missed opt-out has no such recovery path, which is why the ambiguous words got the tighter tier rather than the phrase getting the looser one.

- [ ] **Step 5: Write the failing feature test** — `tests/Feature/OptOutFlowTest.php`

```php
<?php

namespace Tests\Feature;

use App\Jobs\ProcessInboundEvent;
use App\Models\WaContact;
use App\Models\WaMessage;
use App\Services\AutoResponder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OptOutFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'whatsapp.phone_id' => '1427706937088002',
            'whatsapp.token' => 'tok-test',
            'whatsapp.opt_out_text' => 'You will not receive any more messages from E Tax Planner on this number.',
            'whatsapp.ack_text' => 'Thanks, a human will reply.',
        ]);
        // DEVIATION from the plan block -- finding F-1, see task-7-report.md.
        // Http::fake() APPENDS its stub callbacks (vendor Factory.php:275 merge) and matching is
        // first-registered-wins (vendor PendingRequest.php:1512-1521 ->filter()->first()), so the
        // catch-all this setUp used to install silenced every Http::fake() call made inside a test.
        // Measured with the plan's line in place: the 400-only test recorded meta_id=wamid.CONF1
        // status=sent, i.e. setUp's stub answered a request its own test had stubbed as a refusal, and
        // wamid.CONF2 was unreachable, which left the one-time-confirmation guard with no witness.
        // So no stub is installed here; each test owns its stub, multi-send tests use one sequence so a
        // second send really does get a different wamid, and nothing can reach the network.
        Http::preventStrayRequests();
    }

    private function inbound(string $body, string $id = 'wamid.STOP1'): array
    {
        return [
            'kind' => 'message', 'meta_id' => $id, 'phone' => '18479043315',
            'direction' => WaMessage::IN, 'type' => 'text', 'text' => $body,
            'button_id' => null, 'template_name' => null, 'template_lang' => null,
            'sent_at' => now(), 'raw' => ['text' => ['body' => $body]],
        ];
    }

    public function test_stop_suppresses_and_confirms_once()
    {
        Http::fake(['*' => Http::response(['messages' => [['id' => 'wamid.CONF1']]], 200)]);
        ProcessInboundEvent::dispatchSync($this->inbound('STOP'));

        $c = WaContact::where('phone', '18479043315')->firstOrFail();
        $this->assertTrue($c->isOptedOut());
        $this->assertSame('inbound_keyword', $c->opt_out_source);

        $conf = WaMessage::where('meta_id', 'wamid.CONF1')->firstOrFail();
        $this->assertTrue($conf->auto_reply);
        $this->assertSame(WaMessage::OUT, $conf->direction);
        $this->assertSame(2, WaMessage::count());
    }

    public function test_second_stop_does_not_confirm_again()
    {
        // One stub, two queued responses: the plan's second Http::fake() could never take effect
        // (F-1), so wamid.CONF2 is reached by the sequence instead. If the already-silenced contact
        // were confirmed a second time, CONF2 would be recorded and the assertion below would fail.
        Http::fake(['*' => Http::sequence()
            ->push(['messages' => [['id' => 'wamid.CONF1']]], 200)
            ->push(['messages' => [['id' => 'wamid.CONF2']]], 200)]);
        ProcessInboundEvent::dispatchSync($this->inbound('STOP', 'wamid.S1'));
        ProcessInboundEvent::dispatchSync($this->inbound('STOP', 'wamid.S2'));

        $this->assertDatabaseMissing('wa_messages', ['meta_id' => 'wamid.CONF2']);
    }

    public function test_no_acknowledgement_is_sent_to_an_opted_out_contact()
    {
        // Same F-1 correction as above: CONF3 is reachable only through the sequence, so a future
        // acknowledgement regression that sends here lands on wamid.CONF3 and fails this test.
        Http::fake(['*' => Http::sequence()
            ->push(['messages' => [['id' => 'wamid.CONF1']]], 200)
            ->push(['messages' => [['id' => 'wamid.CONF3']]], 200)]);
        ProcessInboundEvent::dispatchSync($this->inbound('STOP', 'wamid.S1'));
        ProcessInboundEvent::dispatchSync($this->inbound('hello again', 'wamid.S3'));

        $this->assertDatabaseMissing('wa_messages', ['meta_id' => 'wamid.CONF3']);
        // R40: suppression stops the acknowledgement, never the storage. Three rows exist -- the
        // inbound STOP, its one confirmation, and this second inbound -- and only one of them ever
        // left the building.
        $this->assertSame(3, WaMessage::count(), 'STOP + confirmation + this inbound, stored and unsent');
        $this->assertSame(1, WaMessage::where('direction', WaMessage::OUT)->count(),
            'the second STOP-family inbound produced no send at all');
    }

    public function test_confirmation_is_recorded_even_when_meta_refuses_it()
    {
        Http::fake(['*' => Http::response(['error' => ['message' => 'Re-engagement', 'code' => 131030]], 400)]);
        ProcessInboundEvent::dispatchSync($this->inbound('STOP'));

        $this->assertTrue(WaContact::where('phone', '18479043315')->firstOrFail()->isOptedOut());
        $this->assertSame(WaMessage::FAILED,
            WaMessage::where('direction', WaMessage::OUT)->firstOrFail()->status);
    }

    public function test_an_outbound_row_is_never_a_keyword_hit()
    {
        // R42: this guard is the responder's own contract, not the caller's. An outbound echo whose
        // body happens to read "STOP" must not silence the client that never said it -- and the
        // sender is exactly the thing that would then be asked to confirm the opt-out.
        $c = WaContact::factory()->create(['phone' => '18479043315']);
        $out = WaMessage::record([
            'wa_contact_id' => $c->id, 'meta_id' => 'wamid.OUTS1', 'direction' => WaMessage::OUT,
            'type' => 'text', 'body' => 'STOP', 'status' => WaMessage::SENT, 'sent_at' => now(),
        ]);

        app(AutoResponder::class)->handle($out, null);

        $this->assertFalse($c->fresh()->isOptedOut());
        $this->assertSame(1, WaMessage::count(), 'the echo is the only row; nothing was confirmed');
    }

    public function test_an_unconfigured_opt_out_text_suppresses_without_sending()
    {
        // R42: WA_OPT_OUT_TEXT is env()-sourced and defaults to ''. Suppression is the promise; the
        // confirmation is a courtesy we must not make by sending an empty body to Meta.
        config(['whatsapp.opt_out_text' => '']);
        ProcessInboundEvent::dispatchSync($this->inbound('STOP'));

        $this->assertTrue(WaContact::where('phone', '18479043315')->firstOrFail()->isOptedOut());
        $this->assertSame(1, WaMessage::count(), 'the inbound STOP only -- no empty confirmation');
    }

    public function test_the_one_time_rule_is_per_contact_not_global()
    {
        // R42/F-3: every other test in this class uses one phone, so a `wasSilenced` flag that was
        // accidentally global -- one cache key, one static, one row consulted by contact id 1 -- would
        // still pass all seven. Two contacts must each get their own confirmation.
        Http::fake(['*' => Http::sequence()
            ->push(['messages' => [['id' => 'wamid.CONF1']]], 200)
            ->push(['messages' => [['id' => 'wamid.CONF2']]], 200)]);
        ProcessInboundEvent::dispatchSync($this->inbound('STOP', 'wamid.S1'));
        $other = $this->inbound('STOP', 'wamid.S2');
        $other['phone'] = '19014307897';
        ProcessInboundEvent::dispatchSync($other);

        $this->assertTrue(WaContact::where('phone', '18479043315')->firstOrFail()->isOptedOut());
        $this->assertTrue(WaContact::where('phone', '19014307897')->firstOrFail()->isOptedOut());
        $this->assertSame(2, WaMessage::where('direction', WaMessage::OUT)->count());
        $this->assertDatabaseHas('wa_messages', ['meta_id' => 'wamid.CONF1']);
        $this->assertDatabaseHas('wa_messages', ['meta_id' => 'wamid.CONF2']);
    }

    public function test_quick_reply_button_id_stops()
    {
        // Body text is not a keyword: only the button id can trigger this.
        $item = $this->inbound('Yes, please talk to someone', 'wamid.S4');
        $item['button_id'] = 'STOP';
        $item['type'] = 'interactive';
        Http::fake(['*' => Http::response(['messages' => [['id' => 'wamid.CONF1']]], 200)]);
        ProcessInboundEvent::dispatchSync($item);
        $this->assertTrue(WaContact::where('phone', '18479043315')->firstOrFail()->isOptedOut());
    }
}
```

- [ ] **Step 6: Run it** — expected FAIL `Class "App\Services\AutoResponder" not found`.

- [ ] **Step 7: Create `app/Services/AutoResponder.php`**

```php
<?php

namespace App\Services;

use App\Models\WaContact;
use App\Models\WaMessage;
use App\Support\OptOutKeywords;

class AutoResponder
{
    public function __construct(private WhatsAppSender $sender)
    {
    }

    public function handle(WaMessage $in, ?string $buttonId = null): void
    {
        if ($in->direction !== WaMessage::IN) {
            return;
        }

        /** @var WaContact $contact */
        $contact = $in->contact;

        if (OptOutKeywords::matches($in->body, $buttonId)) {
            $wasSilenced = $contact->isOptedOut();
            $contact->optOut('inbound_keyword');
            $text = (string) config('whatsapp.opt_out_text', '');
            if (!$wasSilenced && $text !== '') {
                $this->sender->sendText($contact->phone, $text, autoReply: true);
            }

            return;
        }

        if ($contact->isOptedOut()) {
            return;
        }
    }
}
```

The acknowledgement rule is added in Task 8; the class is deliberately split so the opt-out half can be reviewed on its own.

**R39 -- the responder does not re-derive `button_id`; the parser is its only reader.** An earlier
draft of `handle()` ended with `$buttonId = $buttonId ?? ($in->raw['interactive']['button_reply']['id']
?? $in->raw['button']['payload'] ?? null);`, a second source for a value the caller already supplies. It
is redundant -- the job calls `handle($row, $item['button_id'])` and `WebhookParser` reads all three
shapes (`interactive.button_reply.id`, `interactive.list_reply.id`, `button.payload`) with `path()` +
`nullableStr()` (parser:98-100) -- and, worse, it is a *weaker* copy: it misses `list_reply`, so a
list-reply STOP button that reached the responder with a null param would silently not fire. The first
draft also looked like a crash risk -- indexing into a `raw` whose `interactive` might be a string. It
is not: measured, the whole `??` chain returns `NULL` for string/int intermediates exactly as the
parser's `path()` reader does, because `??` uses `isset()` semantics that suppress offset-on-scalar
notices. So this is a single-source/YAGNI correction, not a safety fix, and it is stated as such:
**external input is decided once, at the reader.** Re-reading `raw` here would put a second,
divergent opinion about what a button id is into the system -- the same mistake R28 (digits compared in
one place) and R34 (Meta's response read through one reader) were written to prevent. `handle()` takes
the caller's `$buttonId` and does not look at `raw` at all.

**R40 -- `test_no_acknowledgement_is_still_recorded` counts three rows, not two.** The third dispatch
(`hello again`, `wamid.S3`) is an inbound message and `record()` always creates its row; suppression
stops the *acknowledgement*, never the *storage* -- that is the entire recovery story in Step 4 ("the
inbound message is still stored and still visible in the thread, so a human can read it and call them").
The plan's `assertSame(2, ...)` contradicts its own design: with no ack there is no third *outbound*,
but `S3` is a third *row*. The assertion counts what the test actually claims -- no send -- by the column
that proves it, so a future ack regression cannot hide inside a total.

**R41 -- a test class never installs an `Http::fake()` catch-all in `setUp()`; each test owns its stub.**
Four facts, each read from vendor source in this install rather than inferred:

1. `Factory::fake()` clears `$this->recorded` (Factory.php:260) but **merges** into `$this->stubCallbacks`
   (Factory.php:275). Registering a stub twice therefore accumulates; it does not replace.
2. `PendingRequest::buildStubHandler()` resolves with `->map->__invoke(...)->filter()->first()`
   (PendingRequest.php:1512-1521). `first()` means **the earliest matching stub wins** and every later one
   for the same URL pattern is dead code.
3. `Http::preventStrayRequests()` is only a boolean flag (Factory.php:346-351). `StrayRequestException` is
   thrown solely when *no* stub matched (PendingRequest.php:1523-1526). A `'*'` stub registered earlier
   always matches, so a later `preventStrayRequests()` is **permanently inert** for that request.
4. `Http::fake()` with **no arguments is not a no-op**: Factory.php:262-265 substitutes a
   `return static::response()` closure, i.e. it appends its own 200-returning catch-all. So the idiom
   `Http::fake(); Http::preventStrayRequests();` -- which appears in this plan's Task 12 block -- is
   self-defeating even in a class with no setUp stub: the first call answers the request, the second can
   therefore never fire. The correct way to assert "no request is made" is a bare
   `Http::preventStrayRequests()` and nothing else.

Consequence of 1 + 2: a `'*'` stub installed in `setUp()` is registered before any test body runs and
shadows every `Http::fake()` that test makes. Measured with this plan's original Step 5 in place:
`1 failed, 4 passed` -- the 400-only test recorded `meta_id=wamid.CONF1 status=sent`, i.e. setUp's stub
answered a request its own test had stubbed as a refusal, and `wamid.CONF2` was never returned, which left
the one-time-confirmation guard with no witness.
Step 5 above is the corrected block. The rule for the rest of the plan: no stub in `setUp()`; a test that
sends once calls `Http::fake()` with its own response; a test that can send more than once uses **one**
`Http::sequence()` that pushes a distinct payload per send; and any class that can reach `WhatsAppSender`
calls `Http::preventStrayRequests()` so an unstubbed request is a failure rather than a socket.

**R42 -- a guard is not shipped without either a witness or a report.** Every conditional in this task was
mutated (delete or neuter it, re-run, record which test fails, `landed=` proof, restore from a byte-identical
temp copy). Four mutations are lethal:

| mutation | caught by | observed failure |
| --- | --- | --- |
| `OptOutKeywords::matches(...)` branch → `if (false)` | 6 of the 8 tests | `Failed asserting that false is true.` at the `isOptedOut()` assert |
| `$wasSilenced = $contact->isOptedOut()` → `= false` | `test_second_stop_does_not_confirm_again` | `assertDatabaseMissing` found `wamid.CONF2` |
| `if ($in->direction !== WaMessage::IN)` → `if (false)` | `test_an_outbound_row_is_never_a_keyword_hit` (new) | `Failed asserting that true is false.` |
| `if (!$wasSilenced && $text !== '')` → drop `$text !== ''` | `test_an_unconfigured_opt_out_text_suppresses_without_sending` (new) | `Failed asserting that 2 is identical to 1.` |

The last two tests exist because no earlier one noticed: the `direction` guard is the responder's own
contract, not the caller's (an outbound echo whose body reads "STOP" must not silence the client that never
said it), and `WA_OPT_OUT_TEXT` is `env()`-sourced and defaults to `''` -- suppression is the promise, the
confirmation is a courtesy we must not turn into an empty-body send to Meta.

One guard is **inert here by construction, reported rather than artificially tested**:
`if ($contact->isOptedOut()) { return; }` at the end of `handle()` has no effect in Task 7, because nothing
follows it, so neutering it leaves all 8 opt-out tests and the whole 124-test suite green (re-measured in the R45 round). It is the seam Task 8 writes into (its Step 3 replaces
that statement with the acknowledgement rule), and Task 8's `test_opt_out_message_is_not_acknowledged` is its
witness. A guard whose effect has not been written yet is a scaffold, not dead code; the rule is that the
controller says so in the ledger instead of manufacturing a passing test for it.

**R45 -- the matcher is tiered, and a non-ASCII space does not hide a keyword.** Reviewer findings F-1
and F-2 found a contradiction inside spec §9 as written: its algorithm ("split on whitespace; if *any word*
belongs to the fixed set") can never match that set's own two-word entry `opt out`, because no word
contains a space. Measured against the matcher as the spec dictated, `"I want to opt out now"` → no,
`"please opt out"` → no, `"opt out"` → HIT, while `"please opt-out now"` → HIT. That asymmetry misses
opt-outs in exactly the direction this task calls unrecoverable. The same word-by-word scan silenced clients
over ordinary English: `"end of month pricing"` → HIT, `"does the fiscal year end in december"` → HIT.
Put to the account owner as a compliance-facing fork, the answer was "phrases + tiered strictness":

| tier | entries | rule |
| --- | --- | --- |
| word, anywhere in the first line | `stop`, `cancel`, `unsubscribe`, `opt-out`, `optout`, `quit` | some word equals it |
| phrase, anywhere in the first line | `opt out` | consecutive words equal the pair, in order |
| whole message only | `end`, `remove` | the line is that word and nothing else |
| button | `button_id` = `stop` | unchanged (R39) |

`opt out` is therefore a `PHRASES` entry held as an array of words and matched as a run by
`containsSequence()`, never as a string -- which is what keeps whole-word discipline intact: `"xopt outx"`
cannot fire because its words are `"xopt"` and `"outx"`. "cancel my filing" suppresses; that is the accepted
cost of keeping an unambiguous STOP-family verb in the anywhere tier.

F-4 folded into the same edit: `preg_split('/\s+/', ...)` is ASCII-only and iOS autocorrect emits U+00A0.
Before: `"\u{00A0}stop"` → no, `"stop\u{00A0}sending"` → no, `"opt\u{00A0}out"` → no. The `u` modifier makes
`\s` Unicode-aware in PHP's PCRE (verified directly: `preg_split('/\s+/u', "\u{00A0}stop\u{2009}sending")` →
`['', 'stop', 'sending']`; the same pattern without `u` → one glued token). The `u` moves every
pinned positive shape on that axis -- seven of seven -- from miss to hit ("three" was a mis-count in this
ruling; corrected by R47). **Not adopted:** a zero-width joiner *inside* a word (`"zo\u{200D}pt out"`)
still does not match -- deleting a client's characters to manufacture a keyword is a different act from
treating a space as a space. Recorded as a known limit. **R47 note:** the same byte-level argument that made
this `u` necessary was left unapplied to the two punctuation strips, and that gap was real; it is closed in
R47, so the zero-width case below is now the only "not adopted" survivor.

**Known limits of the tier, measured on the shipped matcher and NOT re-litigated with the owner.** The
whole-message tier silences nobody for `"end now"`, `"remove me"`, `"please remove"`,
`"I want to end this"`, `"I'd like to be removed"` -- all → no. The phrase tier silences clients who did not
ask to be silenced for `"the opt out link is broken"` and `"do not opt out of the survey"` -- both → HIT.
`test_ambiguous_words_do_not_fire_mid_sentence` deliberately pins the first group, so the suite defends the
miss: it is asserting the owner's chosen policy, not an oversight. Because a missed opt-out is the direction
with no recovery path, this list goes to the account owner before any live send (Task 16 checklist), and the
cheap knob if they want it tighter is one word moved from `WHOLE_MESSAGE` to `KEYWORDS` plus that test's rows
updated -- no structural change.

Spec §9's opt-out bullet is amended to this ruling in the same commit; it previously stated the flat set
whose algorithm made `opt out` unreachable, and leaving the two documents disagreeing is what R45 exists to
stop.

**R47 supersedes the "Known limits" list above.** That list was narrower than the code: it omitted the phrase
tier's own miss class (`"opt me out"`), the whole punctuation axis, and a false-positive family the owner
would care about (`"stop payment request"`). The complete measured table is in R47 and that, not this
paragraph, is the artifact that goes to the account owner.

Witnesses and mutations, all re-run on these files with `landed=` proof and restore verified
byte-identical. **Row counts in this table are R45-era (43 unit rows); after R47 the same mutations are
re-measured against 77 rows in the R47 table below.**

| mutation | observed failure |
| --- | --- |
| K1 word tier → `foreach ([]) as $word` | 13 of 43 unit rows |
| K2 phrase tier → `foreach ([]) as $phrase` | 8 rows |
| K3 whole-message tier → matches at any position | **corrected by R47: 7 rows, not 2.** `2` is the count for a strictly weaker mutation that keeps testing `$words[0]` and only drops the count conjunct |
| K4 whole-message tier → `return false` | 4 rows |
| K5 `/\s+/u` → `/\s+/` | 1 row (`test_unicode_spaces_do_not_hide_the_keyword`) |
| G1 `$wasSilenced = $contact->isOptedOut()` → global read | 1 of 8 feature tests -- `test_the_one_time_rule_is_per_contact_not_global` |

F-3 close-out: the reviewer asked to defer the second-contact test to Task 8. It landed here instead,
because the guard it pins ships here -- and G1 is the proof that it is the *only* test which notices a
hypothetical global "already confirmed once" flag (with the per-contact read replaced by
`WaContact::whereNotNull('opted_out_at')->exists()`, 7 of 8 stay green).

Counts after this round: unit 43 passed (62 assertions), `OptOutFlowTest` 8 passed (21 assertions), full
suite **124 passed (323 assertions)**. (Superseded by R47: unit 77 / 82, feature 8 / 21, full 158 / 343.)

**R47 -- the punctuation strip was still byte-level, and the phrase tier missed the commonest phrasing of
its own intent.** Round 2 of the Task 7 review returned "ship the code, fix the ledger": nothing shipped was
wrong, but two axes of the matcher were narrower than the ruling claimed. Both were reproduced against the
shipped file before being adopted -- `php C:/Users/Hp/AppData/Local/Temp/t6/shapecheck.php`, read-only -- and
both came back exactly as reported: `stop…`, `“stop”`, `end…`, `end。`, `remove‑` and `“remove”` → no, while
R45's ASCII forms `stop.` and `stop!` → HIT; and `opt me out`, `opt me out please` and
`please opt me out of this` → no, while the adjacent `opt out` → HIT.

**Finding R-1 (Important, adopted).** R45 made the *split* Unicode-aware and left both *strips* ASCII, so the
same missed-opt-out class survived one axis over. iOS autocorrect converts straight quotes to curly ones
unasked and `…` is a common way to end a one-word message; for `end`/`remove` one stray mark defeats the token
*and* the `count($words) === 1` test, so the whole-message tier cannot rescue it either. Fixed by deleting the
redundant outer strip and making the per-token strip the single normalisation layer:
`preg_replace('/^[\p{P}\p{S}]+|[\p{P}\p{S}]+$/u', '', $token)`. Boundary-only application keeps whole-word
discipline, which is why `stopped`, `stopwatch`, `nonstop`, `removal`, `options` and `xopt outx` are all still
silent. This also removes a mutant that round 2 flagged: the two strips were individually deletable with a
green suite, and the one carrying mid-line punctuation was the unwitnessed one.

**Finding R-2 (Important, adopted).** `containsSequence()` required `opt` and `out` to be adjacent, so the
English dative -- "opt **me** out" -- could not match. That is the same intent one word apart, and it is
plausibly the commonest real phrasing of what R45 was written to catch. `PHRASES` += `['opt','me','out']`.

| tier | entries | rule |
| --- | --- | --- |
| word, anywhere in the first line | `stop`, `cancel`, `unsubscribe`, `opt-out`, `optout`, `quit` | some word equals it |
| phrase, anywhere in the first line | `opt out`, `opt me out` | consecutive words equal the run, in order |
| whole message only | `end`, `remove` | the line is that one word and nothing else |
| button | `button_id` = `stop` | unchanged (R39), now pinned padded |

**The complete measured shape table -- this is the artifact for the account owner.** 76 shapes, generated by
`php C:/Users/Hp/AppData/Local/Temp/t6/table47.php` against the shipped class; 0 of 76 disagree with their
intended class. Counts: 33 requests caught, 13 missed opt-outs, 10 false hits, 20 correct silences.

*Caught* (all HIT, and these are the ones R45+R47 moved out of the miss column): `stop`, `STOP`, `stop.`,
` stop `, `stop, please`, `STOP. I said it`, `unsubscribe`, `cancel`, `quit`, `opt-out`, `optout`, `end`,
`remove`, `end.`, ` remove `, `opt out`, `OPT OUT`, ` opt out.`, `I want to opt out now`, **`opt me out`**,
**`opt me out please`**, **`please opt me out of this`**, **`stop…`**, **`“stop”`**, **`end…`**, **`end。`**,
**`remove‑`**, **`“remove”`**, and the four non-ASCII-space forms.

*Missed opt-outs* -- a client who asked to be silenced keeps getting messages, which is the direction with no
recovery path. The first six are a **design floor, not a bug**: `end` and `remove` are ordinary English in a
tax practice, and the owner chose the tighter tier for them.

| shape | why it misses |
| --- | --- |
| `end now`, `remove me`, `please remove`, `please remove me`, `kindly remove me from this list`, `I want to end this` | whole-message tier requires exactly one word |
| `opting out` | no stemming; the lemma is not in any set |
| `I'd like to be removed` | `removed` is not `remove` |
| `delete my number`, `no more messages please`, `dont message me again` | free-text intent outside any fixed set |
| `opt.out` | punctuation *inside* a token is not glued; see W14 |
| a first line that is not valid UTF-8 | undecodable text cannot be read as an opt-out; it is stored for a human |

*False hits* -- a client is silently silenced who did not ask. Visible in the thread and reversible, which is
why the tier is allowed to err here.

| shape | why it fires |
| --- | --- |
| `the opt out link is broken`, `what is an opt out`, `do not opt out of the survey`, `my friend wants to opt out` | the phrase tier reads intent from words, not negation or person |
| `please opt me out of this survey for me` | **new in R47** -- the 3-word run is the price of catching `opt me out` |
| `stop payment request`, `stop by the office`, `the bus stop is near` | `stop` is in the anywhere tier; "stop payment" is a standing instruction in an accounting practice |
| `cancel my filing later`, `I quit the gym` | same, for `cancel` and `quit` |

The knob if the owner wants any of these moved is one word between `WHOLE_MESSAGE` and `KEYWORDS`, or one
phrase added to or removed from `PHRASES` -- no structural change, and the providers make the test update
mechanical.

**R-4 to R-9 dispositions.** R-4: the two unwitnessed live behaviours are now pinned (`stop, please` and
`STOP. I said it` in `keywordProvider`, and `test_a_padded_button_id_still_matches`); the redundant outer
punctuation strip is deleted rather than noted, and the two provably-equivalent guards were measured and
recorded (W4, W14 below). R-5: no finding. R-6: the Task 12 note cited a section that does not exist --
corrected to spec §14 "Errors and edge cases worth naming", lines 246-247 (verified: §13 is Configuration at
232, §15 is Testing at 255, and 242-243 is the 24-hour-window bullet). R-7: the "three shapes" prose is
corrected above. R-8: the three multi-shape `foreach` tests are now `@dataProvider`s, so every row count in
this ledger is a count of shapes and not of methods. R-9: the two Task 16 checklist lines are added to Task 16
Step 6, so the limits list and the empty-`WA_OPT_OUT_TEXT` behaviour can no longer fall between the documents.

Witnesses and mutations, re-run on these files with `landed=` proof and restore verified byte-identical
(`node .superpowers/sdd/2026-11-06-wa-webhook-inbox/mut7g.mjs unit` and the same with `full`). Baseline before
the battery: 77 unit rows / 82 assertions and 158 tests / 343 assertions.

| mutation | unit | full | note |
| --- | --- | --- | --- |
| W1 token punctuation strip → no-op | 11 | 11 | carries the ASCII *and* Unicode axes, because the outer strip is gone |
| W17 token strip → the old byte-level `trim` | 6 | 6 | **the R-1 witness**: exactly the 6 Unicode-punctuation rows |
| W3 strip loses `/u` | 6 | 6 | same 6 rows, one character |
| W2 split loses `/u` | 48 + 28 warnings | 61 + 28 | lethal, and noisy |
| W7 word tier → `false` | 24 | 29 | |
| W8 phrase loop emptied | 12 | 12 | |
| W9 drop `['opt','me','out']` | 3 | 3 | **the R-2 witness**: the 3 dative rows |
| W10 whole-message tier → `return false` | 12 | 12 | |
| W11 whole-message → genuine any-position scan | 8 | 8 | R45's "2" was wrong (R-3) |
| W12 → drop only the count conjunct | 3 + 4 warnings | 3 + 4 | this is the mutation the "2" actually described |
| W13 button `trim` removed | 1 | 1 | the new pin works |
| W15 first-line selection removed | 1 | 1 | |
| W16 `containsSequence` window → whole-array compare | 7 | 7 | |
| W4 drop the `preg_split` `?: []` fallback | 1 warning, 0 failed | 1 warning, 0 failed | reachable on a real payload (verified: `preg_split('/\s+/u', "\xFF\xFE stop")` → `false`), but PHPUnit 11 records it as a warning, not a failure. Guard kept; the witness is the warning. Making it red needs `failOnWarning="true"` in `phpunit.xml`, which is a suite-wide config change and is **carried to Task 14** rather than done here |
| W14 strip unanchored (punctuation anywhere in the token) | **0 failed, 158 passed** | 0 failed | genuinely equivalent under every pinned shape. The only input that separates it is a glued form like `opt.out`, which would *widen* matching; there is no evidence for that phrasing, so it is recorded as a miss in the table above rather than coded for |

Counts after this round: unit **77 passed (82 assertions)**, `OptOutFlowTest` **8 passed (21 assertions)**,
full suite **158 passed (343 assertions)**. `AutoResponder.php` was not touched by this round; the seam at
`:35-37` is still Task 8's and still inert.

- [ ] **Step 8: Wire the job to the responder.** In `app/Jobs/ProcessInboundEvent.php`, replace the line `// Task 7 adds the automatic-response call here.` with:

```php
            app(AutoResponder::class)->handle($row, $item['button_id']);
```

and add `use App\Services\AutoResponder;` to the imports. `$row` is the message record the same method already builds, and `button_id` is the parser field set in Task 4 — the responder takes it explicitly rather than re-deriving it from `raw`, so the quick-reply test below exercises the real path instead of matching the word "Stop" in the body by accident.

- [ ] **Step 9: Run it**

```bash
php artisan test tests/Feature/OptOutFlowTest.php
```

Expected: 8 passed (21 assertions) -- the 5 opt-out flows, the 2 R42 witnesses, and the R45/F-3
per-contact test. Full suite at this step: 158 passed (343 assertions) — R47 raised it from the 124/323 the
R45 round recorded, by 33 provider rows and one new test method, with no existing row changing verdict.

- [ ] **Step 10: Commit**

```bash
git add app/Support/OptOutKeywords.php app/Services/AutoResponder.php app/Jobs/ProcessInboundEvent.php tests
git commit -m "feat: STOP-family opt-out with a one-time confirmation"
```

---

## Task 8: First-reply acknowledgement

**Deliverable:** the first inbound message from a contact in 24 hours gets one short human-will-reply line; every later message that day gets nothing; suppressed contacts get nothing; two callbacks in the same second cannot both send.

- [ ] **Step 1: Write the failing test** — `tests/Feature/AckFlowTest.php`

```php
<?php

namespace Tests\Feature;

use App\Jobs\ProcessInboundEvent;
use App\Models\WaContact;
use App\Models\WaMessage;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AckFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'whatsapp.phone_id' => '1427706937088002',
            'whatsapp.token' => 'tok-test',
            'whatsapp.ack_text' => 'Thanks, a human from E Tax Planner will reply here within one business day.',
            'whatsapp.opt_out_text' => 'No more messages.',
        ]);
        Cache::flush();
        // R41: no catch-all stub here. The first draft of this block installed
        // Http::fake(fn () => ... 'wamid.ACK' . uniqid() ...) in setUp, which (a) shadows every in-test
        // fake because stub callbacks merge and matching is first-registered-wins, and (b) gave every send
        // a different id, so no test could witness *which* response it got -- a double-ack was caught only
        // by the outbound-row counts. Each test below owns its stub; the two-send test uses one sequence
        // with two distinct ids; and a test that must not send gets no stub at all, which
        // preventStrayRequests turns into a failure rather than a socket.
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        // Carbon's test-now is a process-wide static; the re-arm test freezes it and nothing else here
        // would ever see the real clock again.
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function inbound(string $body, string $phone = '18479043315', ?CarbonInterface $at = null): array
    {
        // R46: the third parameter exists because two of the tests below have to put two messages in the
        // same second. A helper that always stamps now() cannot express that, and now() differs by
        // microseconds between two dispatchSync calls.
        return [
            'kind' => 'message', 'meta_id' => 'wamid.' . uniqid(), 'phone' => $phone,
            'direction' => WaMessage::IN, 'type' => 'text', 'text' => $body,
            'button_id' => null, 'template_name' => null, 'template_lang' => null,
            'sent_at' => $at ?? now(), 'raw' => ['text' => ['body' => $body]],
        ];
    }

    public function test_first_inbound_of_the_day_gets_one_ack()
    {
        Http::fake(['*' => Http::response(['messages' => [['id' => 'wamid.ACK1']]], 200)]);
        ProcessInboundEvent::dispatchSync($this->inbound('hello'));

        $ack = WaMessage::where('direction', WaMessage::OUT)->first();
        $this->assertNotNull($ack);
        $this->assertTrue($ack->auto_reply);
        $this->assertSame('wamid.ACK1', $ack->meta_id);
        $this->assertStringContainsString('a human from E Tax Planner', $ack->body);
    }

    public function test_later_messages_same_day_get_nothing()
    {
        // One stub, because exactly one send is expected. If the once-per-day rule broke, the second
        // send would still be answered and the count below would fail -- but it would fail having tried
        // to reach Meta twice, which is the thing this test exists to prevent.
        Http::fake(['*' => Http::response(['messages' => [['id' => 'wamid.ACK1']]], 200)]);
        ProcessInboundEvent::dispatchSync($this->inbound('hello'));
        ProcessInboundEvent::dispatchSync($this->inbound('and also?'));
        ProcessInboundEvent::dispatchSync($this->inbound('one more thing'));

        $this->assertSame(1, WaMessage::where('direction', WaMessage::OUT)->count());
    }

    public function test_a_different_contact_is_acknowledged_separately()
    {
        // One sequence, two distinct ids: the cache key is per-contact, so a key that ignored the phone
        // would produce one ack for two people. Asserting both ids present is what proves two different
        // sends happened, where the row count alone could not tell an ack from a retry.
        Http::fake(['*' => Http::sequence()
            ->push(['messages' => [['id' => 'wamid.ACK1']]], 200)
            ->push(['messages' => [['id' => 'wamid.ACK2']]], 200)]);
        ProcessInboundEvent::dispatchSync($this->inbound('hello', '18479043315'));
        ProcessInboundEvent::dispatchSync($this->inbound('hello', '19014307897'));

        $this->assertSame(2, WaMessage::where('direction', WaMessage::OUT)->count());
        $this->assertDatabaseHas('wa_messages', ['meta_id' => 'wamid.ACK1']);
        $this->assertDatabaseHas('wa_messages', ['meta_id' => 'wamid.ACK2']);
    }

    public function test_opt_out_message_is_not_acknowledged()
    {
        // The only send this test allows is the opt-out confirmation, so it gets the only stub.
        Http::fake(['*' => Http::response(['messages' => [['id' => 'wamid.OPT1']]], 200)]);
        ProcessInboundEvent::dispatchSync($this->inbound('STOP'));
        // only the confirmation, no ack line
        $out = WaMessage::where('direction', WaMessage::OUT)->get();
        $this->assertCount(1, $out);
        $this->assertSame('wamid.OPT1', $out->first()->meta_id);
        $this->assertStringContainsString('No more messages', $out->first()->body);
    }

    public function test_empty_ack_text_disables_the_rule()
    {
        // Deliberately no Http::fake() here: setUp's preventStrayRequests() is what makes the attempt
        // harmless. It is NOT what fails the test -- the sender catches \Throwable at
        // WhatsAppSender.php:99 and records a FAILED row instead of letting the exception escape, so a
        // broken guard shows up as an outbound row, which is what the count below asserts on.
        config(['whatsapp.ack_text' => '']);
        ProcessInboundEvent::dispatchSync($this->inbound('hello'));
        $this->assertSame(0, WaMessage::where('direction', WaMessage::OUT)->count());
    }

    /**
     * R48 witness for the `if ($contact->isOptedOut()) return;` seam in handle().
     *
     * Spec §9 promises "never auto-send to a contact where opted_out_at is set, from any code path", and
     * this seam is the only line that keeps that promise for a contact silenced by an admin (Task 12) or an
     * import (Task 13) rather than by their own STOP. Deleting the seam left all 165 rows green, because no
     * other row in this file is a suppressed contact that reaches it -- the STOP message returns inside the
     * keyword branch (`AutoResponder.php:33`) and never gets here, and the one `OptOutFlowTest` row that
     * sends a non-keyword message to a silenced contact would be stood down by the window query (`:56`) even
     * without the seam, because its own STOP is an inbound inside the window. This is the state where the
     * seam alone decides: suppressed contact, a message that is not a keyword, no inbound in the window.
     */
    public function test_a_non_keyword_inbound_from_a_silenced_contact_gets_no_ack()
    {
        WaContact::factory()->create(['phone' => '18479043315', 'opted_out_at' => now()->subDays(30)]);
        Http::fake(['*' => Http::response(['messages' => [['id' => 'wamid.ACK1']]], 200)]);
        ProcessInboundEvent::dispatchSync($this->inbound('hello'));

        $this->assertSame(0, WaMessage::where('direction', WaMessage::OUT)->count());
        $this->assertSame(1, WaMessage::where('direction', WaMessage::IN)->count());
    }

    /**
     * R48 witness for the window query in acknowledge().
     *
     * With CACHE_STORE=array the Cache::add guard answers "no" for every later message the query would
     * also stop, so no same-day test can tell the two apart -- measured: deleting the query left all 165
     * rows passing. The state where the query alone decides is an inbound the responder never ran for,
     * which is exactly what Task 13's import produces: a row in the DB, no cache key behind it.
     */
    public function test_an_earlier_inbound_stands_the_ack_down_even_with_an_empty_cache()
    {
        $contact = WaContact::factory()->create(['phone' => '18479043315']);
        WaMessage::record([
            'wa_contact_id' => $contact->id, 'meta_id' => 'wamid.OLD1', 'direction' => WaMessage::IN,
            'type' => 'text', 'body' => 'an older inbound', 'status' => WaMessage::INCOMING,
            'sent_at' => now()->subHours(2), 'raw' => ['text' => ['body' => 'an older inbound']],
        ]);
        Http::fake(['*' => Http::response(['messages' => [['id' => 'wamid.ACK1']]], 200)]);
        ProcessInboundEvent::dispatchSync($this->inbound('hello'));

        $this->assertSame(0, WaMessage::where('direction', WaMessage::OUT)->count());
        $this->assertSame(2, WaMessage::where('direction', WaMessage::IN)->count());
    }

    /**
     * R49 witness for the length of the window -- the two 24-hour numbers, the query's lower bound
     * (`AutoResponder.php:59`) and the cache key's TTL (`:67`). Widening *either* to 30 days left the whole
     * suite green as this file stood, i.e. nothing pinned "per day" rather than "per month". Freezing the
     * clock is the only way to reach the state where they decide: the key must expire (ArrayStore computes
     * expiry from Carbon::now(), which honours the freeze) and the earlier message must fall outside the
     * window.
     */
    public function test_the_contact_is_acknowledged_again_once_the_window_has_passed()
    {
        Http::fake(['*' => Http::sequence()
            ->push(['messages' => [['id' => 'wamid.ACK1']]], 200)
            ->push(['messages' => [['id' => 'wamid.ACK2']]], 200)]);
        ProcessInboundEvent::dispatchSync($this->inbound('hello'));

        Carbon::setTestNow(now()->addHours(25));
        ProcessInboundEvent::dispatchSync($this->inbound('a day later'));

        $this->assertSame(2, WaMessage::where('direction', WaMessage::OUT)->count());
        $this->assertDatabaseHas('wa_messages', ['meta_id' => 'wamid.ACK2']);
    }

    /**
     * R49 witness for `->where('direction', WaMessage::IN)` in the window query. Deleting the filter left
     * the suite green, and the state it permits is the ordinary one: staff reply in the afternoon, the
     * contact's first message next morning -- and no acknowledgement, because an outbound row of our own
     * counted as "this contact is already talking".
     */
    public function test_a_staff_reply_inside_the_window_does_not_stand_the_ack_down()
    {
        $contact = WaContact::factory()->create(['phone' => '18479043315']);
        WaMessage::record([
            'wa_contact_id' => $contact->id, 'meta_id' => 'wamid.STAFF1', 'direction' => WaMessage::OUT,
            'type' => 'text', 'body' => 'here is your filing quote', 'status' => WaMessage::SENT,
            'sent_at' => now()->subHours(2), 'raw' => ['text' => ['body' => 'here is your filing quote']],
        ]);
        Http::fake(['*' => Http::response(['messages' => [['id' => 'wamid.ACK1']]], 200)]);
        ProcessInboundEvent::dispatchSync($this->inbound('hello'));

        $this->assertSame(2, WaMessage::where('direction', WaMessage::OUT)->count());
        $this->assertDatabaseHas('wa_messages', ['meta_id' => 'wamid.ACK1', 'auto_reply' => true]);
    }

    /**
     * R49 witness for the window's upper bound (`sent_at < $at + 1s`). Meta redelivers a webhook it already
     * delivered, so an older message can be processed after a newer one; the bound says only rows at or
     * before the message being handled may stand it down. Without it (measured: suite green) the decision
     * reads rows that have not happened yet. The cache key normally hides the difference, so the state is
     * manufactured the way the import path does it: a stored row with no key behind it.
     */
    public function test_a_row_recorded_after_this_message_does_not_stand_the_ack_down()
    {
        $contact = WaContact::factory()->create(['phone' => '18479043315']);
        WaMessage::record([
            'wa_contact_id' => $contact->id, 'meta_id' => 'wamid.LATER1', 'direction' => WaMessage::IN,
            'type' => 'text', 'body' => 'a newer message, stored first', 'status' => WaMessage::INCOMING,
            'sent_at' => now()->addMinutes(5), 'raw' => ['text' => ['body' => 'a newer message, stored first']],
        ]);
        Http::fake(['*' => Http::response(['messages' => [['id' => 'wamid.ACK1']]], 200)]);
        ProcessInboundEvent::dispatchSync($this->inbound('hello'));

        $this->assertSame(1, WaMessage::where('direction', WaMessage::OUT)->count());
        $this->assertDatabaseHas('wa_messages', ['meta_id' => 'wamid.ACK1']);
    }

    public function test_two_messages_in_the_same_second_produce_one_ack()
    {
        // R46: the case the deliverable names -- two callbacks in one second. The sequence stub makes a
        // second send observable by id rather than only by a count, so a regression that sends twice shows
        // up as wamid.ACK2 in the table instead of a number that could be explained away.
        Http::fake(['*' => Http::sequence()
            ->push(['messages' => [['id' => 'wamid.ACK1']]], 200)
            ->push(['messages' => [['id' => 'wamid.ACK2']]], 200)]);
        $at = Carbon::parse('2026-11-11 09:00:00', 'UTC');
        ProcessInboundEvent::dispatchSync($this->inbound('hello', '18479043315', $at));
        ProcessInboundEvent::dispatchSync($this->inbound('and one more', '18479043315', $at));

        $this->assertSame(1, WaMessage::where('direction', WaMessage::OUT)->count());
        $this->assertDatabaseHas('wa_messages', ['meta_id' => 'wamid.ACK1']);
        $this->assertDatabaseMissing('wa_messages', ['meta_id' => 'wamid.ACK2']);
    }

    public function test_a_claimed_cache_key_is_what_stands_the_callback_down()
    {
        // R42/R46: Cache::add is the decider in exactly one state -- no earlier inbound row inside the
        // window for this contact, but the ack already claimed. sync dispatch cannot interleave two jobs,
        // so that state is manufactured here rather than left untested. Deliberately no Http::fake(): the
        // attempt is refused by preventStrayRequests, but the failure this test would see is the FAILED
        // row the sender records for it (WhatsAppSender.php:99), not an escaping exception. This test does
        // NOT prove atomicity -- see R46 for why no test can, with CACHE_STORE=array.
        $contact = WaContact::factory()->create(['phone' => '18479043315']);
        Cache::add('wa-ack-' . $contact->id, 1, now()->addHours(24));
        ProcessInboundEvent::dispatchSync($this->inbound('hello'));

        $this->assertSame(0, WaMessage::where('direction', WaMessage::OUT)->count());
        $this->assertSame(1, WaMessage::where('direction', WaMessage::IN)->count());
    }
}
```

- [ ] **Step 2: Run it** — expected FAIL (0 outbound rows found).

- [ ] **Step 3: Extend `AutoResponder`** — replace the trailing `if ($contact->isOptedOut()) { return; }` in `handle()` with the acknowledgement rule, and add the import `use Illuminate\Support\Facades\Cache;` (`WaContact`, `WaMessage` and `WhatsAppSender` are already imported by Task 7). The block below is the **corrected** version: R46 found that the first draft suppressed every ack.

```php
        if ($contact->isOptedOut()) {
            return;
        }

        $this->acknowledge($contact, $in);
    }

    private function acknowledge(WaContact $contact, WaMessage $in): void
    {
        $text = (string) config('whatsapp.ack_text', '');
        if ($text === '') {
            return;
        }

        $hours = (int) config('whatsapp.window_hours', 24);
        $at = $in->sent_at;

        // R46: exclude the row this job just wrote. ProcessInboundEvent stores the inbound before it
        // calls the responder, so without this the window query finds the message's own row and no ack
        // is ever sent. Measured, not reasoned about.
        $alreadyTalking = WaMessage::where('wa_contact_id', $contact->id)
            ->where('direction', WaMessage::IN)
            ->where('id', '!=', $in->id)
            ->where('sent_at', '>=', $at->copy()->subHours($hours))
            ->where('sent_at', '<', $at->copy()->addSecond())
            ->exists();
        if ($alreadyTalking) {
            return;
        }

        // The race guard. Atomic on the production store, not on the test store -- see R46.
        if (!Cache::add('wa-ack-' . $contact->id, 1, now()->addHours($hours))) {
            return;
        }

        $this->sender->sendText($contact->phone, $text, autoReply: true);
    }
```

**R46 -- the window query must exclude the message's own row, and the cache guard ships with a
manufactured witness because nothing else can reach it.** Two measurements, both before this task was
implemented.

1. `ProcessInboundEvent::handle()` stores the inbound row at `app/Jobs/ProcessInboundEvent.php:65` and
   only then calls the responder (`:74`) -- that ordering is the spec's ("both run in the queued job, after
   the inbound row is stored"). So the Step 3 draft, which asked "is there an inbound in the last 24 hours"
   without excluding the current row, always answers yes. Measured against the local MariaDB in a
   transaction that was rolled back afterwards (`rows_in_table_before=0 after=1`, then `rolled_back, rows
   now=0`, `probe_contact_still_exists=false`): **`plan_query_matches_own_row=true`**, and the same query
   with `->where('id', '!=', $row->id)` → `excluding_own_row=false`. Consequence: the first draft of this
   task fails its own Step 1 test `test_first_inbound_of_the_day_gets_one_ack` and would silence the
   acknowledgement feature entirely -- a defect that reads as "the rule is working" because nothing is sent.
2. `Cache::add()` atomicity, read from vendor rather than assumed: `Repository::add()`
   (Repository.php:462-492) delegates to the store's own `add()` **only if the store has one**
   (Repository.php:478). `ArrayStore` -- what `phpunit.xml:25` sets `CACHE_STORE` to -- has no `add()`
   method (its method list is get/put/increment/decrement/forever/forget/flush), so in tests `Cache::add`
   runs the get-then-put fallback at Repository.php:488-490 and is **not atomic**. The production default is
   `database` (`config/cache.php:18`, and `CACHE_STORE` is set in `.env`), and
   `DatabaseStore::add()` (DatabaseStore.php:212-223) uses `insertOrIgnore(...) > 0` for anything that is
   not SQL Server, which is atomic against the `cache.key` unique index.

So: the atomicity claim in the deliverable is true in production and **unprovable by any test in this
suite** -- it is stated with the vendor citations above, and no test may pretend to witness it. What the
suite can witness is that a claimed key stands the callback down, which is why
`test_a_claimed_cache_key_is_what_stands_the_callback_down` exists: it manufactures the only state in which
`Cache::add` is the decider (key present, no earlier inbound row in the window), because `sync` dispatch
cannot interleave two jobs. `test_two_messages_in_the_same_second_produce_one_ack` then witnesses the pair
behaving as the deliverable promises, without claiming to prove which of the two guards stopped the second
one. **R48 corrects the last clause: measured, it is `Cache::add`, not the window query.**

- [ ] **Step 4: Run it**

```bash
php artisan test tests/Feature/AckFlowTest.php
```

Expected: 12 passed (27 assertions). If `test_first_inbound_of_the_day_gets_one_ack` fails with 0 outbound rows, check that
the `where('id', '!=', $in->id)` exclusion survived the edit -- that is R46's failure mode, not a test bug.

- [ ] **Step 5: Run the whole suite**

```bash
php artisan test
```

Expected: **170 passed** (the 158 that Task 7 closed on after R47, plus this task's 12 -- R48 added two rows and R49 three more), no warnings -- R48 removed the 10 `@dataProvider` warnings the R47 round left behind. Report the
assertion count you observe; it is what the next task's brief will quote. Then, per R42, mutate every guard
this task adds -- the `$text === ''` return, the `$alreadyTalking` return, the `Cache::add` return, the
`isOptedOut()` return, and the `where('id', '!=', $in->id)` exclusion -- and record which test fails for
each with `landed=` proof. The exclusion is the one that will tempt a silent reimplementation: deleting it
does not crash anything, it just stops every acknowledgement. Two of the five had no witness as this step was
first written (**R48**), and the review of the result found four more arms nobody had tried at all (**R49**).
Counting the guards is not enough: mutate the *constants* too -- the window length, the TTL, the direction
filter and the upper bound each survived deletion with the suite green.

**R48 -- the two guards this task shipped without witnesses now have them; three ledger claims corrected.**

The implementation round ran the R42 battery over all five guards Step 3 adds. Two of them left the whole suite green:

| guard in `AutoResponder.php` | mutation | suite as Step 1 shipped | witness after R48 |
| --- | --- | --- | --- |
| `$text === ''` return | → `if (false)` | 2 failed (this task's row + `IngestRowsTest` collateral) | `test_empty_ack_text_disables_the_rule` |
| `$alreadyTalking` window query | → `if (false)` | **0 failed, 165 passed** | `test_an_earlier_inbound_stands_the_ack_down_even_with_an_empty_cache` |
| `Cache::add` return | → `if (false)` | 1 failed | `test_a_claimed_cache_key_is_what_stands_the_callback_down` |
| `isOptedOut()` seam in `handle()` | → `if (false)` | **0 failed, 165 passed** | `test_a_non_keyword_inbound_from_a_silenced_contact_gets_no_ack` |
| `where('id', '!=', $in->id)` | line deleted | 4 failed | all four positive-ack rows |

Those five rows are the controller's own re-runs on the shipped bytes, each with `landed=` proof and restore
verified byte-identical. The two green arms are not dead code -- each has exactly one reachable state in which
it is the decider, and none of Step 1's seven tests enters it:

- With `CACHE_STORE=array` the *first* message claims the cache key, so `Cache::add` alone stops the second
  and the window query is never the difference. The state where the query decides is an inbound row that
  never went through the responder at all -- which is Task 13's `wa:import-send-log`, not a live callback.
- An inbound `STOP` returns inside the keyword branch (`AutoResponder.php:33`) and never reaches the seam.
  The `OptOutFlowTest` row that sends a non-keyword message to a silenced contact is stood down by the
  window query first.

So the spec §9 promise "never auto-send to a contact where `opted_out_at` is set, from any code path" was
being enforced by one line with no test behind it, and the once-per-24-hours promise by a query no test
isolated. Suppression is compliance-facing, so both get witnesses rather than a written report: the two tests
above were added to `AckFlowTest`, which is why Task 8 ships **9** tests, not 7.

Three ledger claims corrected:

1. R46's closing sentence ("in `sync` order it is the window query") is wrong. Measured: with the window
   query neutered, `test_two_messages_in_the_same_second_produce_one_ack` still passes -- the guard that
   stands the second callback down is `Cache::add`, whose second call on the array store returns `false`
   without being atomic. R46's substance (no test may claim atomicity, because the production store is the
   only one that provides it) is untouched; this is a misattribution of which guard fires.
2. Step 1's comments attributed two failures to `StrayRequestException`. Measured: both fail on the row
   count. `WhatsAppSender::post()` catches `\Throwable` (`WhatsAppSender.php:99`) and records a `FAILED` row
   with `error_code='network'` (`:100-106`), so the stray-request exception never reaches the test. The
   witnesses still work -- an unstubbed request still becomes an outbound row the counts see -- and the
   comments now say so. No live request is made either way.
3. The implementation brief's "deleting the exclusion must fail exactly one test" measured four. A wrong
   prediction in the brief, not a defect in the code: the exclusion has more witnesses than it needed.

Also folded in: the R47 round left 10 `WARN Metadata found in doc-comment for method
Tests\Unit\OptOutKeywordsTest::...` lines, which made Step 5's "no warnings" false. Fixed by converting all
10 `@dataProvider` annotations in that file to `#[DataProvider]` attributes -- same 77 rows and 82
assertions, zero warnings, and one file less that would break when PHPUnit 12 drops the doc-comment form.
Ledger-label note for future briefs: "unit 77/82" means `tests/Unit/OptOutKeywordsTest.php` alone;
`php artisan test tests/Unit` is 84 passed (89 assertions). Say which one a count refers to.

Counts after R48: `AckFlowTest` 9 passed (21 assertions), full suite **167 passed (364 assertions)**, no
warnings.

**R49 -- the window's shape had no witnesses: four arms the review found, three tests that close them.**

The independent review of Task 8 did not re-run R48's five arms; it invented new ones, and four of them left
the suite green on the code as R48 shipped it. All four were reproduced here, and all four are now witnessed by
rows added to `AckFlowTest`, which is why Task 8 ships **12** tests:

| arm | mutation | suite as R48 shipped | witness after R49 |
| --- | --- | --- | --- |
| MT-A | delete `->where('direction', WaMessage::IN)` from the window query | **0 failed, 167 passed** | `test_a_staff_reply_inside_the_window_does_not_stand_the_ack_down` |
| MT-B | delete the upper bound `->where('sent_at', '<', $at->copy()->addSecond())` | **0 failed, 167 passed** | `test_a_row_recorded_after_this_message_does_not_stand_the_ack_down` |
| MT-C | cache TTL `now()->addHours($hours)` → `now()->addDays(30)` | **0 failed, 167 passed** | `test_the_contact_is_acknowledged_again_once_the_window_has_passed` |
| MT-H | query lower bound `subHours($hours)` → `subDays(30)` | **0 failed, 167 passed** | the same re-arm row |
| M5 re-run | delete the R46 own-row exclusion | 4 failed as R48 recorded | now **7** failed -- the three new rows also depend on it |

Mutated hashes for the record: MT-A `3c3f60fd`, MT-B `65a3adb4`, MT-C `5b24947c`, MT-H `573b65b4`, every arm
restored byte-identical to `81ea9b95…` with `hits=1` proof; `AutoResponder.php` itself was not edited this round.

MT-A is the one that would have hurt: with the filter gone, **our own outbound rows count as "this contact is
already talking"**, so an afternoon staff reply silences the next morning's first message -- the exact case the
rule exists to serve. MT-C and MT-H are the two halves of "24 hours": widening either to a month still
satisfies every other row in the file, because none of them crossed the boundary; the re-arm row freezes the
clock 25 hours (`Carbon::setTestNow`, reset in `tearDown` -- the test-now static is process-wide) and requires
the second ack. MT-B only matters for out-of-order redelivery, which the cache key usually masks, so it is
manufactured the way the import path does it: a stored row with no key behind it.

One false statement shipped by R48 and corrected here: the silenced-contact docblock claimed the other rows
"reach the seam with the window query already answering 'no' first". That is the wrong order -- the seam is
`AutoResponder.php:36` and the query is `:56`, inside `acknowledge()`, which the seam returns past. The real
reason those rows do not witness the seam is that none of them *is* a suppressed contact reaching it: the STOP
row returns at `:33`, and `OptOutFlowTest::test_no_acknowledgement_is_sent_to_an_opted_out_contact` would be
suppressed by the query even with the seam gone, because its own STOP is an inbound inside the window.

Recorded, deliberately **not** changed:

- **Two clocks, one rule.** The TTL (`:67`) is anchored at `now()`, the window (`:59`) at the message's
  `sent_at`. For live callbacks the two are the same instant; for an imported row they are not, and the pair
  can disagree (a row older than the window whose key is still alive suppresses, and vice versa). No test
  pins the disagreement because no realistic state needs it resolved -- Task 13 carries the note.
- **A refused ack is burned for the window.** The key is claimed before `sendText`, and the sender never
  throws (`WhatsAppSender.php:99-106` records a `FAILED` row), so a network failure costs that contact its
  acknowledgement for the rest of the window rather than retrying on their next message. Accepted: the
  alternative re-attempts a send Meta has just refused, on an account whose quality rating is the thing we
  are protecting. If the owner wants retry-on-failure, that is a deliberate change, not an oversight.
- **`test_a_claimed_cache_key_is_what_stands_the_callback_down` re-hardcodes** `'wa-ack-'` and
  `addHours(24)` instead of reading `window_hours`. Plan-fenced code, unchanged; if the key format or the
  TTL ever moves, that row goes red for a reason a reader can find.

Task 16 gains checklist item 11 so the live number, not sqlite, answers the one thing this round still cannot:
does a second-day message actually get acknowledged again on a real handset.

Counts after R49: `AckFlowTest` 12 passed (27 assertions), full suite **170 passed (370 assertions)**, no
warnings. Also corrected: the stack line said PHPUnit 11.5.50; the installed binary reports **11.5.57**.

- [ ] **Step 6: Commit**

```bash
git add app/Services/AutoResponder.php tests/Feature/AckFlowTest.php
git commit -m "feat: one acknowledgement per contact per 24 hours, never to suppressed contacts"
```

## Task 9: Staff authentication

**Deliverable:** one staff account exists, `/login` works, everything under the inbox group needs it, and a guest is bounced to the login page instead of a 403.

**Consumes:** the framework `users` table and `App\Models\User`, both already present.
**Produces:** `php artisan wa:create-user {email} {--password=}`; `GET|POST /login`, `POST /logout`, route names `login` / `logout`; the framework's guest redirect pointing at `/login`, which every `auth`-protected route added later inherits. No inbox page exists yet — Task 11 registers the first ones and proves the protection works end to end.

- [ ] **Step 1: Write the failing test** — `tests/Feature/AuthTest.php`

```php
<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_page_renders_a_form()
    {
        $this->get('/login')->assertOk()
            ->assertSee('E Tax Planner inbox', false)
            ->assertSee('name="password"', false);
    }

    public function test_correct_credentials_open_a_session_and_send_staff_to_the_inbox()
    {
        User::factory()->create(['email' => 'staff@etaxplanner.com',
                                 'password' => Hash::make('good-pass-123')]);

        $this->post('/login', ['email' => 'staff@etaxplanner.com', 'password' => 'good-pass-123'])
            ->assertRedirect('/inbox');
        $this->assertAuthenticated();
        $this->get('/')->assertRedirect('/inbox');
    }

    public function test_wrong_password_stays_on_the_form()
    {
        User::factory()->create(['email' => 'staff@etaxplanner.com',
                                 'password' => Hash::make('good-pass-123')]);

        $this->from('/login')->post('/login', ['email' => 'staff@etaxplanner.com', 'password' => 'nope'])
            ->assertRedirect('/login')
            ->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_a_guest_cannot_reach_the_staff_area()
    {
        $this->get('/')->assertRedirect('/login');
    }

    public function test_logout_ends_the_session()
    {
        $this->actingAs(User::factory()->create());
        $this->post('/logout')->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_create_user_command_is_idempotent_on_email()
    {
        $this->artisan('wa:create-user', ['email' => 'a@b.co', '--password' => 'first-pass-1'])
            ->assertSuccessful();
        $this->assertSame(1, User::where('email', 'a@b.co')->count());

        $this->artisan('wa:create-user', ['email' => 'a@b.co', '--password' => 'second-pass-2'])
            ->assertSuccessful();
        $this->assertSame(1, User::where('email', 'a@b.co')->count());
        $this->assertTrue(Hash::check('second-pass-2', User::where('email', 'a@b.co')->value('password')));
    }

    public function test_create_user_rejects_a_short_password()
    {
        $this->artisan('wa:create-user', ['email' => 'a@b.co', '--password' => 'short'])
            ->assertExitCode(1);
        $this->assertSame(0, User::where('email', 'a@b.co')->count());
    }

    public function test_an_authenticated_staff_member_is_bounced_off_the_login_form()
    {
        $this->actingAs(User::factory()->create());

        $this->get('/login')->assertRedirect('/inbox');
    }

    public function test_the_sixth_login_attempt_is_refused_by_the_throttle()
    {
        User::factory()->create(['email' => 'staff@etaxplanner.com',
                                 'password' => Hash::make('good-pass-123')]);

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->from('/login')->post('/login', ['email' => 'staff@etaxplanner.com', 'password' => 'nope'])
                ->assertRedirect('/login');
        }

        $this->post('/login', ['email' => 'staff@etaxplanner.com', 'password' => 'good-pass-123'])
            ->assertStatus(429);
        $this->assertGuest();
    }

    public function test_the_login_form_renders_a_csrf_token()
    {
        $html = $this->get('/login')->getContent();

        $this->assertStringContainsString('name="_token" value="' . session()->token() . '"', $html);
    }

    public function test_the_login_endpoint_rejects_a_request_with_no_token()
    {
        // Flip the bound env so Application::runningUnitTests() is false and VerifyCsrfToken runs at all.
        $this->app->instance('env', 'production');

        User::factory()->create(['email' => 'staff@etaxplanner.com',
                                 'password' => Hash::make('good-pass-123')]);

        $html = $this->get('/login')->getContent();
        preg_match('/name="_token" value="([^"]+)"/', $html, $match);
        $token = $match[1] ?? '';

        $this->post('/login', ['email' => 'staff@etaxplanner.com', 'password' => 'good-pass-123'])
            ->assertStatus(419);

        $this->post('/login', ['_token' => $token, 'email' => 'staff@etaxplanner.com', 'password' => 'good-pass-123'])
            ->assertStatus(302);
    }

    public function test_the_login_form_renders_the_three_inputs_it_promises()
    {
        $this->get('/login')
            ->assertSee('name="email"', false)
            ->assertSee('name="password"', false)
            ->assertSee('name="remember"', false);
    }

    public function test_a_failed_sign_in_displays_the_credentials_message()
    {
        User::factory()->create(['email' => 'staff@etaxplanner.com',
                                 'password' => Hash::make('good-pass-123')]);

        $this->from('/login')->post('/login', ['email' => 'staff@etaxplanner.com', 'password' => 'nope']);

        $html = $this->get('/login')->getContent();

        $this->assertStringContainsString('Those credentials do not match our records.', $html);
    }

    public function test_stay_signed_in_sets_a_recall_token()
    {
        $user = User::factory()->create(['email' => 'staff@etaxplanner.com',
                                         'password' => Hash::make('good-pass-123'),
                                         'remember_token' => null]);

        $this->post('/login', ['email' => 'staff@etaxplanner.com', 'password' => 'good-pass-123', 'remember' => '1'])
            ->assertRedirect('/inbox');

        $this->assertNotNull($user->fresh()->remember_token);
    }

    public function test_no_recall_token_is_set_when_stay_signed_in_is_left_unchecked()
    {
        $user = User::factory()->create(['email' => 'staff@etaxplanner.com',
                                         'password' => Hash::make('good-pass-123'),
                                         'remember_token' => null]);

        $this->post('/login', ['email' => 'staff@etaxplanner.com', 'password' => 'good-pass-123'])
            ->assertRedirect('/inbox');

        $this->assertNull($user->fresh()->remember_token);
    }

    public function test_the_validate_block_runs_and_the_email_rule_is_the_email_rule()
    {
        \Illuminate\Support\Facades\DB::enableQueryLog();

        \Illuminate\Support\Facades\DB::flushQueryLog();
        $this->post('/login', ['email' => 'not-an-email', 'password' => 'anything']);
        $this->assertSame(0, count(\Illuminate\Support\Facades\DB::getQueryLog()));

        \Illuminate\Support\Facades\DB::flushQueryLog();
        $this->post('/login', ['email' => 'staff@etaxplanner.com', 'password' => 'wrong-but-shaped']);
        $this->assertGreaterThan(0, count(\Illuminate\Support\Facades\DB::getQueryLog()));
    }

    public function test_a_missing_password_is_a_redirect_not_a_crash()
    {
        User::factory()->create(['email' => 'staff@etaxplanner.com',
                                 'password' => Hash::make('good-pass-123')]);

        $this->post('/login', ['email' => 'staff@etaxplanner.com'])
            ->assertStatus(302);
        $this->assertGuest();
    }

    public function test_a_nested_array_password_is_a_302_not_a_500()
    {
        User::factory()->create(['email' => 'staff@etaxplanner.com',
                                 'password' => Hash::make('good-pass-123')]);

        $this->post('/login', ['email' => 'staff@etaxplanner.com', 'password' => ['nested']])
            ->assertStatus(302);
        $this->assertGuest();
    }

    public function test_logout_destroys_the_sessions_stored_data()
    {
        $this->actingAs(User::factory()->create());
        session()->put('probe-key', 'alive');

        $this->post('/logout')->assertRedirect('/login');

        $this->assertNull(session()->get('probe-key'));
    }

    public function test_a_password_of_exactly_ten_characters_is_accepted()
    {
        $this->artisan('wa:create-user', ['email' => 'a@b.co', '--password' => '0123456789'])
            ->assertSuccessful();

        $this->assertSame(1, User::where('email', 'a@b.co')->count());
        $this->assertTrue(Hash::check('0123456789', User::where('email', 'a@b.co')->value('password')));
    }

    public function test_a_password_of_nine_characters_is_refused()
    {
        $this->artisan('wa:create-user', ['email' => 'a@b.co', '--password' => '123456789'])
            ->assertExitCode(1);

        $this->assertSame(0, User::where('email', 'a@b.co')->count());
    }

    public function test_the_email_argument_is_trimmed()
    {
        $this->artisan('wa:create-user', ['email' => '  a@b.co  ', '--password' => 'first-pass-1'])
            ->assertSuccessful();

        $this->assertSame(1, User::where('email', 'a@b.co')->count());
        $this->assertSame(0, User::where('email', '  a@b.co  ')->count());
    }

    public function test_an_empty_email_is_refused_without_touching_the_db()
    {
        $this->artisan('wa:create-user', ['email' => '', '--password' => 'first-pass-1'])
            ->assertExitCode(1);

        $this->assertSame(0, User::count());
    }

    public function test_the_default_staff_display_name_is_the_one_we_ship()
    {
        $this->artisan('wa:create-user', ['email' => 'a@b.co', '--password' => 'first-pass-1'])
            ->assertSuccessful();

        $this->assertSame('E Tax Planner staff', User::where('email', 'a@b.co')->value('name'));
    }

    public function test_the_two_route_names_the_deliverable_promises_exist()
    {
        $this->assertTrue(\Illuminate\Support\Facades\Route::has('login'));
        $this->assertTrue(\Illuminate\Support\Facades\Route::has('logout'));
    }

    public function test_the_throttle_is_on_the_post_only()
    {
        $response = null;
        for ($attempt = 1; $attempt <= 6; $attempt++) {
            $response = $this->get('/login');
        }

        $this->assertNotSame(429, $response->getStatusCode());
    }
}
```

- [ ] **Step 2: Run it** — expected FAIL 404 on `/login`, command not found.

- [ ] **Step 3: Create `app/Console/Commands/WaCreateUser.php`**

```php
<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

class WaCreateUser extends Command
{
    protected $signature = 'wa:create-user {email} {--password=}';

    protected $description = 'Create or reset the single staff account for the inbox';

    public function handle(): int
    {
        $email = trim((string) $this->argument('email'));
        $password = (string) ($this->option('password') ?? $this->secret('Password') ?? '');

        if ($email === '' || strlen($password) < 10) {
            $this->error('A password of at least 10 characters is required.');

            return 1;
        }

        $user = User::firstOrNew(['email' => $email]);
        $user->name = $user->name ?? 'E Tax Planner staff';
        $user->password = Hash::make($password);
        $user->save();

        $this->info('Staff account ready: ' . $email);

        return 0;
    }
}
```

- [ ] **Step 4: Create `app/Http/Controllers/Auth/LoginController.php`**

```php
<?php

namespace App\Http\Controllers\Auth;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use App\Http\Controllers\Controller;

class LoginController extends Controller
{
    public function show()
    {
        return view('auth.login');
    }

    public function store(Request $request)
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        if (!Auth::attempt($credentials, $request->boolean('remember'))) {
            throw ValidationException::withMessages([
                'email' => 'Those credentials do not match our records.',
            ]);
        }

        $request->session()->regenerate();

        return redirect('/inbox');
    }

    public function destroy(Request $request)
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/login');
    }
}
```

- [ ] **Step 5: Create `resources/views/layout.blade.php`**

```blade
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="csrf-token" content="{{ csrf_token() }}">
<title>{{ $title ?? 'E Tax Planner inbox' }}</title>
<style>
:root { --ink:#111827; --grey:#566174; --line:#dfe4ec; --blue:#1830B8; --warn:#F07030; }
* { box-sizing:border-box; }
body { font:15px/1.5 -apple-system,"Segoe UI",Arial,sans-serif; color:var(--ink); margin:0; background:#f7f8fb; }
header { background:var(--blue); color:#fff; padding:12px 20px; display:flex; justify-content:space-between; align-items:center; }
header a { color:#fff; }
main { max-width:1080px; margin:0 auto; padding:20px; }
table { width:100%; border-collapse:collapse; background:#fff; }
th,td { text-align:left; padding:9px 12px; border-bottom:1px solid var(--line); vertical-align:top; }
th { font-size:11px; letter-spacing:.06em; text-transform:uppercase; color:var(--grey); background:#f2f4f9; }
.bubble { max-width:70%; padding:9px 13px; border-radius:14px; margin:6px 0; }
.in { background:#fff; border:1px solid var(--line); }
.out { background:#e6f0ff; margin-left:auto; }
.auto { font-size:11px; color:var(--warn); text-transform:uppercase; letter-spacing:.06em; }
.badge { font-size:11px; padding:2px 7px; border-radius:10px; background:#ffe9dc; color:#9a4a10; }
input,textarea,select,button { font:inherit; padding:8px 10px; border:1px solid var(--line); border-radius:7px; }
textarea { width:100%; min-height:84px; }
button { background:var(--blue); color:#fff; border:0; cursor:pointer; }
.muted { color:var(--grey); font-size:13px; }
.err { color:#b00020; font-size:13px; }
.ok { color:#0a6b3b; font-size:13px; }
</style>
</head>
<body>
<header>
  <strong>E Tax Planner &mdash; WhatsApp inbox</strong>
  @auth
    <form method="POST" action="/logout" style="display:inline">
      @csrf
      <button type="submit" style="background:transparent;color:#fff;border:0;cursor:pointer">Sign out</button>
    </form>
  @endauth
</header>
<main>
@yield('content')
</main>
</body>
</html>
```

- [ ] **Step 6: Create `resources/views/auth/login.blade.php`**

```blade
@extends('layout')
@section('content')
<div style="max-width:380px;margin:60px auto;background:#fff;padding:24px;border:1px solid var(--line);border-radius:10px">
  <h2 style="margin:0 0 4px">E Tax Planner inbox</h2>
  <p class="muted" style="margin:0 0 18px">Staff sign in</p>
  <form method="POST" action="/login">
    @csrf
    <label class="muted">Email</label>
    <input name="email" type="email" value="{{ old('email') }}" style="width:100%;margin:4px 0 14px">
    @error('email')<div class="err">{{ $message }}</div>@enderror
    <label class="muted">Password</label>
    <input name="password" type="password" style="width:100%;margin:4px 0 14px">
    <label style="font-size:13px"><input type="checkbox" name="remember" value="1"> Stay signed in</label>
    <div style="margin-top:16px"><button type="submit">Sign in</button></div>
  </form>
</div>
@endsection
```

The layout yields `@section('content')`, which is the pattern every view in this app follows.

- [ ] **Step 7: Routes and guest redirect.** Replace `routes/web.php` with:

```php
<?php

use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\WaWebhookController;
use Illuminate\Support\Facades\Route;

Route::get('/wa/webhook', [WaWebhookController::class, 'verify'])
    ->withoutMiddleware('web')
    ->name('wa.webhook.verify');
Route::post('/wa/webhook', [WaWebhookController::class, 'ingest'])
    ->withoutMiddleware('web')
    ->name('wa.webhook.ingest');

Route::get('/login', [LoginController::class, 'show'])->middleware('guest')->name('login');
Route::post('/login', [LoginController::class, 'store'])->middleware('throttle:5,1');
Route::post('/logout', [LoginController::class, 'destroy'])->name('logout');

Route::middleware('auth')->group(function () {
    Route::get('/', fn () => redirect('/inbox'))->name('home');
});
```

The root route is the first staff-only page: an authenticated visitor is sent to `/inbox`, which does not exist until Task 11, and a guest is sent to `/login`. That is enough for the `auth` middleware and the guest redirect to be tested here without a placeholder inbox page.

Then in `bootstrap/app.php`, inside the existing `withMiddleware` closure, after the CSRF exception:

```php
        $middleware->redirectGuestsTo(fn () => '/login');
        $middleware->redirectUsersTo('/inbox');
```

- [ ] **Step 8: Run the test**

```bash
php artisan test tests/Feature/AuthTest.php
```

Expected: 26 passed (76 assertions). The plan's 7 rows are all still here; the other 19 come from R50 and the
three deviations recorded at the end of this task.

- [ ] **Step 9: Create the real staff account locally** (password is typed at the prompt, never in chat or in a file):

```bash
php artisan wa:create-user staff@etaxplanner.com
```

- [ ] **Step 10: Commit**

```bash
git rm tests/Feature/ExampleTest.php resources/views/welcome.blade.php
git add app resources routes/web.php bootstrap/app.php tests/Feature/AuthTest.php
git commit -m "feat: staff login, logout and one seeded account"
```

---

**R50 -- the plan's Step 7 shipped a line that tested nothing and dropped a spec line it promised; the review then found 24 unguarded arms.**

Three deviations from the plan were authorised in the brief before any code was written, and one of them
turned out to require a code change the brief said would not be needed.

**1. `tests/Feature/ExampleTest.php` and `resources/views/welcome.blade.php` are deleted, not rewritten.**
Step 7 replaces `routes/web.php`, and the new `/` is behind `auth` and redirects, so the scaffolding row --
`$this->get('/')` then `assertStatus(200)` at `ExampleTest.php:15,17` -- returns a 302 the moment Step 7 lands.
Measured on the way: with Steps 3-7 applied and the deletions not yet made, the suite was
**1 failed, 176 passed (395 assertions)**, the failure being exactly that row. It is a duplicate of
`test_a_guest_cannot_reach_the_staff_area` and `test_correct_credentials_open_a_session_and_send_staff_to_the_inbox`,
so it is gone rather than re-pointed; `tests/Unit/ExampleTest.php` (which asserts nothing about `/`) stays.
Note for whoever greps next: the pre-deletion reference is only `routes/web.php:7`, and a search for the bare
word `welcome` misleads -- `tests/Feature/WhatsAppSenderTest.php:90` uses the template name `etp_welcome_c`.

**2. `bootstrap/app.php`'s `redirectUsersTo('/inbox')` was inert, and the fix is one line of middleware.**
The brief said this deviation would "add tests and change no code". That is false, and the measurement is in
vendor: `Middleware::redirectUsersTo()` (`Foundation/Configuration/Middleware.php:550-553`) does exactly one
thing -- `RedirectIfAuthenticated::redirectUsing($users)` -- and its own docblock at `:545` reads "where users
are redirected **by the guest middleware**". The Step 7 fence declares `GET /login` with no `guest`
middleware, and nothing else in the app uses it, so `RedirectIfAuthenticated` never ran and an authenticated
visitor to `/login` got **200 plus a second login form**. `routes/web.php:14` now carries
`->middleware('guest')` (alias at `Middleware.php:787`, so no registration was needed). Ratified after an
independent review measured the smaller option -- deleting `redirectUsersTo`, the middleware and the row
together yields a clean **177 passed (406 assertions)** -- and rejected, because that state silently removes
a plan instruction instead of completing one, and leaves signed-in staff staring at a credentials form on a
public subdomain. Two things go with the ratification: the witness row
`test_an_authenticated_staff_member_is_bounced_off_the_login_form` is the only thing that notices either half
(`bootstrap/app.php:19` deleted, or `guest` deleted, each fails that row and nothing else), and between Task 9
and Task 11 a signed-in visitor to `/login` is bounced to a **404**, because `/inbox` does not exist yet. That
is a deploy-note wart, not a defect, and it disappears at Task 11.

**3. `POST /login` is throttled, because spec section 6 line 128 promises it and the plan never implemented it.**
`routes/web.php:15` carries `->middleware('throttle:5,1')` on the POST only -- the GET stays unthrottled so a
signed-in staff member can navigate back without a 429. The row
`test_the_sixth_login_attempt_is_refused_by_the_throttle` sends five wrong passwords (each needing
`from('/login')`, without which the redirect-back goes to the `APP_URL` root rather than `/login`) and then the
*correct* password, asserting 429 and `assertGuest()`. Both halves of the middleware are witnessed: widening to
`throttle:1000,1` and deleting it outright each fail exactly that row. **Not verified, and it is a real gap:**
`CACHE_STORE=array` means the limiter's bucket is per-test-process and keyed to one IP; behaviour under a
production cache store, with real client IPs and decay, is unchecked. Task 16 gains the item.

Then the review. It did not re-run these arms as its main work -- it invented 31 new ones and **24 of them left
all 178 green**, which is the same hole R48 and R49 found in Task 8, in a task that looked thoroughly tested.
V25 was never run, so the numbering below skips it. "control" means the arm was already caught by a row the
plan itself wrote; "green 178" means nothing in the suite noticed until R50.

| arm | mutation (one exact-string replace, `occurrences=1`) | suite as Step 10 shipped it | witness after R50 |
| --- | --- | --- | --- |
| V1 | `layout.blade.php` header logout form loses its `@csrf` | green 178 | not witnessable in this task -- the form is inside `@auth` and Task 9 ships no authed page; **Task 11 must assert `_token` in the rendered logout form** |
| V2 | login form loses its `@csrf` | green 178 | `test_the_login_form_renders_a_csrf_token` + `test_the_login_endpoint_rejects_a_request_with_no_token` |
| V3 | the `remember` checkbox deleted | green 178 | `test_the_login_form_renders_the_three_inputs_it_promises` |
| V4 | `<h2>E Tax Planner inbox</h2>` deleted | green 178 | none -- heading copy with no behaviour behind it; recorded as accepted |
| V5 | the `@error('email')` render block deleted | green 178 | `test_a_failed_sign_in_displays_the_credentials_message` |
| V6 | the whole `name="email"` input deleted | green 178 | `test_the_login_form_renders_the_three_inputs_it_promises` |
| V7 | `<meta name="csrf-token">` deleted | green 178 | not witnessable in this task -- its consumer is Task 13's JavaScript; **Task 13 must witness the tag being read** |
| V8 | `Auth::attempt($credentials, $request->boolean('remember'))` -> `Auth::attempt($credentials)` | green 178 | `test_stay_signed_in_sets_a_recall_token` |
| V34 | ... -> `Auth::attempt($credentials, true)` (found by the fix round, not the review) | green 195 | `test_no_recall_token_is_set_when_stay_signed_in_is_left_unchecked` |
| V9 | the `validate([...])` block -> `$credentials = $request->all();` | green 178 | `test_the_validate_block_runs_and_the_email_rule_is_the_email_rule` + the two request-shape rows |
| V10 | `'email' => ['required','email']` -> `['required']` | green 178 | `test_the_validate_block_runs_and_the_email_rule_is_the_email_rule` |
| V11 | the credentials message -> `'Nope.'` | green 178 | `test_a_failed_sign_in_displays_the_credentials_message` |
| V12 | `redirect('/inbox')` -> `redirect()->intended('/inbox')` | green 178 | none -- recorded below as a deliberate choice |
| V13 | `destroy()` loses `session()->invalidate()` | green 178 | `test_logout_destroys_the_sessions_stored_data` |
| V14 | `destroy()` loses `session()->regenerateToken()` | green 178 | no row possible -- written report below |
| V15 | `destroy()` loses `Auth::logout()` | 1 failed (control) | `test_logout_ends_the_session` |
| V16 | `store()` loses `session()->regenerate()` | green 178 | no row possible -- written report below |
| V17 | `strlen($password) < 10` -> `< 11` | green 178 | `test_a_password_of_exactly_ten_characters_is_accepted` |
| V18 | `strlen($password) < 10` -> `< 9` | green 178 | `test_a_password_of_nine_characters_is_refused` |
| V19 | `Hash::make($password)` -> `$password` | green 178 | none -- the `hashed` cast does the work; written report below |
| V20 | `trim((string) $this->argument('email'))` loses its `trim` | green 178 | `test_the_email_argument_is_trimmed` |
| V21 | the `$email === ''` guard deleted | green 178 | `test_an_empty_email_is_refused_without_touching_the_db` |
| V22 | the default-name line deleted | 1 failed (`users.name` NOT NULL) | pre-existing idempotency row + `test_the_default_staff_display_name_is_the_one_we_ship` |
| V23 | `throttle:5,1` added to **GET** `/login` | green 178 | `test_the_throttle_is_on_the_post_only` |
| V24 | `->name('login')` dropped | green 178 | `test_the_two_route_names_the_deliverable_promises_exist` |
| V26 | `->name('logout')` dropped | green 178 | the same row |
| V27 | `auth` *moved* off `/` onto POST `/logout` | 1 failed (control) | `test_a_guest_cannot_reach_the_staff_area` |
| V28 | `->middleware('guest')` dropped | 1 failed (control) | `test_an_authenticated_staff_member_is_bounced_off_the_login_form` |
| V29 | `->middleware('throttle:5,1')` deleted outright | 1 failed (control) | `test_the_sixth_login_attempt_is_refused_by_the_throttle` |
| V30 | `redirectUsersTo('/inbox')` -> `redirectUsersTo('/')` | 1 failed (control) | the same bounce row |
| V31 | `redirectGuestsTo(fn () => '/login')` -> `redirectGuestsTo('/')` | 1 failed (control) | `test_a_guest_cannot_reach_the_staff_area` |
| V32 | `'login'` added to `validateCsrfTokens(except:)` | green 178 | `test_the_login_endpoint_rejects_a_request_with_no_token` |
| V33 | *three files at once*: `redirectUsersTo`, the `guest` middleware and the bounce row all deleted | n/a -- the alternative design | measured **177 passed (406 assertions)**, a coherent state, rejected in deviation 2 |

One of these is worth reading twice because it is not the implementer's oversight but the plan's:
`test_login_page_renders_a_form` asserts `assertSee('E Tax Planner inbox')`, and that string is in
`layout.blade.php:7`'s `<title>` -- so it is satisfied by the layout on any page, and deleting the login form's
entire `name="email"` input leaves the row green. The same class of problem the implementer caught in its own
F-1, one row over. The fenced row is left exactly as the plan wrote it; the real assertions are the ones added
here.

Recorded, deliberately **not** changed, and not written up as tests because a test would pass for the wrong reason:

- **`$request->session()->regenerate()` in `store()` is redundant.** Deleting it leaves the suite green, and the
  implementer's explanation (`SESSION_DRIVER=array`, so nothing can observe the id) is the wrong cause: with the
  line deleted the session id *does* rotate, because `SessionGuard::updateSession()`
  (`vendor/.../Auth/SessionGuard.php:600-605`) calls `session->regenerate(true)` inside `Auth::attempt()`, before
  `LoginController.php:30` runs. A rotation row would therefore be green either way. The line stays as
  defence-in-depth for a future login path that does not use `attempt()`; note the cost -- on a server-side
  driver a successful login now does two destroy-and-write cycles.
- **`$request->session()->regenerateToken()` in `destroy()`** is redundant for the same shape of reason:
  `invalidate()` is `flush()` + `migrate(true)` (`Store.php:605-610`), so `_token` is already gone and
  `Store::token()` recreates it lazily. `invalidate()` itself is now witnessed; `Auth::logout()` was already.
- **`Hash::make()` in `wa:create-user` is not what hashes the password** -- the `'password' => 'hashed'` cast does
  (`app/Models/User.php:46`, `HasAttributes.php:1477`). Harmless duplication; the cast is why the factory and the
  command can both be trusted.
- **Login ignores the intended URL.** `redirect('/inbox')` rather than `redirect()->intended('/inbox')`: with one
  staff page there is nothing to intend. If Task 11 makes `/inbox/{contact}` a deep link worth returning to, this
  is the line to revisit.
- **`redirectGuestsTo(fn () => '/login')` and `->name('login')` are individually redundant and jointly
  load-bearing.** With the callback absent, `Foundation/Exceptions/Handler.php:763` falls back to `route('login')`;
  with the route name absent, the callback answers. Deleting either one alone leaves the suite green; changing the
  callback's *target* fails `test_a_guest_cannot_reach_the_staff_area`. So the honest statement is "the presence is
  redundant, the value is not".
- **Two things the login-only app cannot render yet**, carried rather than faked: the header Sign-out form
  (`layout.blade.php:34-39`, inside `@auth`, reachable only by guests on this task's only page) and
  `<meta name="csrf-token">` (whose consumer is Task 13's JavaScript). Task 11 must witness the logout form's
  `_token`; Task 13 must witness the meta tag being read.
- **CSRF on `/login` cannot be tested the normal way at all.** `VerifyCsrfToken` skips whenever
  `Application::runningUnitTests()` is true (`Application.php:832-835`, which is just `$this['env'] === 'testing'`),
  so deleting `@csrf` from the login form, or adding `'login'` to the `validateCsrfTokens` exception list -- the
  exact edit the brief forbids -- were both green at 178. One row now closes both by flipping the container's
  `env` for the duration of the test. That is a deliberate hole in the harness and is named as such in the file;
  it is one row, not a pattern.
- **One row was written, tested, found to be a false witness, and deleted.** The fix round's "box unchecked means
  no recall token" row was assigned V8 by the brief and stayed green under it: V8 removes the second argument
  entirely, so `remember` is `false` for the shipped and the mutated code alike and nothing distinguishes them.
  Dropping the argument was the wrong arm for that observable. The observable is real and is now pinned by
  `test_no_recall_token_is_set_when_stay_signed_in_is_left_unchecked` against the arm it does close (V34,
  `boolean('remember')` -> `true`), which was measured green at 195 before the row existed and fails exactly that
  row after it.

Counts after R50: baseline **178 passed (408 assertions)** -> 17 candidate rows **195 (445)** -> minus the one
false witness **194 (442)** -> plus the corrected V34 row **195 passed (445 assertions)**, of which
`tests/Feature/AuthTest.php` is **26 rows / 76 assertions** (the plan's 7, the 2 the deviations needed, and 17
from R50). The six non-test source files are byte-identical to the state the arms were run against
(`routes/web.php 79903b09/772`, `bootstrap/app.php 0ae96855/743`, `LoginController.php ef12297f/1044`,
`WaCreateUser.php 0071aae6/954`, `layout.blade.php 575da960/2063`, `auth/login.blade.php 152be586/871`); only
`AuthTest.php` grew, 3385 -> 10085 bytes, sha8 `92989a3f`. The Step 1 and Step 7 fences above are now the shipped
files, byte for byte.

---

## Task 10: Machine endpoints — suppression list and send records

**Deliverable:** the bench can fetch who has opted out and can report what it sent, both behind a bearer token that fails closed when the token is not configured.

**Consumes:** `WaContact`, `WaMessage`, `config('whatsapp.export_token')`.
**Produces:** `GET /wa/suppressions` → `text/plain`, one opted-out E.164 number per line, ascending **bytewise** (a `varchar` `ORDER BY`, so lexicographic over the digit string -- see R51 §5), no trailing blank; `POST /inbound/record-send` → `201` with `{"recorded":true,"contact_id":N}`; middleware alias `wa.bearer`; `App\Http\Middleware\VerifyWaBearer`.

- [ ] **Step 1: Write the failing test** — `tests/Feature/MachineEndpointTest.php`

```php
<?php

namespace Tests\Feature;

use App\Models\WaContact;
use App\Models\WaMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class MachineEndpointTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['whatsapp.export_token' => 'export-test']);
    }

    private function bearer(string $token = 'export-test'): array
    {
        return ['HTTP_AUTHORIZATION' => 'Bearer ' . $token];
    }

    public function test_suppressions_lists_only_opted_out_numbers_sorted()
    {
        WaContact::factory()->create(['phone' => '19014307897']);
        WaContact::factory()->create(['phone' => '18479043315'])->optOut('inbound_keyword');
        WaContact::factory()->create(['phone' => '15550009999'])->optOut('manual');

        $res = $this->getJson('/wa/suppressions', $this->bearer());
        $res->assertOk()->assertHeader('Content-Type', 'text/plain; charset=utf-8');
        $this->assertSame("15550009999\n18479043315", trim($res->getContent()));
    }

    public function test_missing_bearer_is_401()
    {
        $this->getJson('/wa/suppressions')->assertUnauthorized();
    }

    public function test_wrong_bearer_is_401()
    {
        $this->getJson('/wa/suppressions', $this->bearer('nope'))->assertUnauthorized();
    }

    public function test_unconfigured_export_token_refuses_everything()
    {
        config(['whatsapp.export_token' => '']);
        $this->getJson('/wa/suppressions', $this->bearer(''))->assertUnauthorized();
        $this->postJson('/inbound/record-send', ['to' => '18479043315'], $this->bearer(''))
            ->assertUnauthorized();
    }

    public function test_record_send_creates_contact_and_out_row()
    {
        $this->postJson('/inbound/record-send', [
            'to' => '18479043315', 'template' => 'etp_opener_brand', 'language' => 'en',
            'message_id' => 'wamid.BENCH1', 'utc_time' => '2026-11-06T10:00:00Z',
        ], $this->bearer())->assertCreated();

        $c = WaContact::where('phone', '18479043315')->firstOrFail();
        $m = WaMessage::where('meta_id', 'wamid.BENCH1')->firstOrFail();
        $this->assertSame(WaMessage::OUT, $m->direction);
        $this->assertSame('etp_opener_brand', $m->template_name);
        $this->assertSame('en', $m->template_lang);
        $this->assertSame(WaMessage::SENT, $m->status);
        $this->assertSame('2026-11-06 10:00:00', $m->sent_at->toDateTimeString());
        $this->assertFalse($c->isOptedOut());
    }

    public function test_record_send_replays_do_not_duplicate()
    {
        $body = ['to' => '18479043315', 'template' => 'etp_welcome_c', 'language' => 'en_US',
                 'message_id' => 'wamid.BENCH2', 'utc_time' => '2026-11-06T10:00:00Z'];
        $this->postJson('/inbound/record-send', $body, $this->bearer());
        $this->postJson('/inbound/record-send', $body, $this->bearer());
        $this->assertSame(1, WaMessage::count());
    }

    public function test_record_send_refuses_a_row_without_a_message_id()
    {
        $this->postJson('/inbound/record-send',
            ['to' => '18479043315', 'template' => 'x', 'language' => 'en', 'message_id' => ''],
            $this->bearer())->assertStatus(422);
        $this->assertSame(0, WaMessage::count());
    }

    public function test_record_send_never_lifts_a_suppression()
    {
        WaContact::factory()->create(['phone' => '18479043315'])->optOut('inbound_keyword');
        $this->postJson('/inbound/record-send', [
            'to' => '18479043315', 'template' => 'etp_welcome_a', 'language' => 'en_US',
            'message_id' => 'wamid.BENCH3', 'utc_time' => '2026-11-06T10:00:00Z',
        ], $this->bearer())->assertCreated();
        $this->assertTrue(WaContact::where('phone', '18479043315')->first()->isOptedOut());
    }

    public function test_both_bodies_are_exactly_what_the_deliverable_promises()
    {
        // N1. The plan's first row trims the body, so it cannot see a trailing newline; the Produces line
        // promises a two-key 201 body, so nothing else may ride along in it. The opt-outs are inserted in
        // descending phone order on purpose, so this row also depends on the sort rather than on insertion.
        WaContact::factory()->create(['phone' => '18479043315'])->optOut('inbound_keyword');
        WaContact::factory()->create(['phone' => '15550009999'])->optOut('manual');

        $list = $this->getJson('/wa/suppressions', $this->bearer());
        $this->assertSame("15550009999\n18479043315", $list->getContent());

        $send = $this->postJson('/inbound/record-send', [
            'to' => '18479043315', 'template' => 'etp_opener_brand', 'language' => 'en',
            'message_id' => 'wamid.BENCH_N1', 'utc_time' => '2026-11-06T10:00:00Z',
        ], $this->bearer());

        $this->assertSame(201, $send->getStatusCode());
        $this->assertSame(['recorded' => true, 'contact_id' => WaContact::where('phone', '18479043315')->value('id')],
            $send->json());
    }

    public function test_suppressions_answers_with_an_empty_body_before_anyone_opts_out()
    {
        // N2. The bench's first call in a fresh database: 200 and zero bytes, not 204, not '0', not "\n".
        $res = $this->getJson('/wa/suppressions', $this->bearer());

        $res->assertOk();
        $this->assertSame('', $res->getContent());
    }

    public function test_a_bearer_that_only_compares_equal_to_the_token_is_refused()
    {
        // N3. The only row that tells hash_equals() from ==: both of these are numeric strings that ==
        // treats as equal (0e1234567 == 0e9876543 is PHP's magic-hash comparison), so the loose operator
        // would open the endpoint while every other bearer row still passes.
        config(['whatsapp.export_token' => '0e1234567']);

        $this->getJson('/wa/suppressions', $this->bearer('0e9876543'))->assertUnauthorized();
    }

    public static function requiredFieldProvider(): array
    {
        return [
            ['to'],
            ['template'],
            ['language'],
            ['message_id'],
        ];
    }

    #[DataProvider('requiredFieldProvider')]
    public function test_record_send_refuses_a_body_that_drops_one_required_field(string $field): void
    {
        // N4. One row per required rule, so a required: that quietly becomes nullable: shows up on its own.
        $body = ['to' => '18479043315', 'template' => 'etp_welcome_a', 'language' => 'en_US',
                 'message_id' => 'wamid.BENCH_N4', 'utc_time' => '2026-11-06T10:00:00Z'];
        unset($body[$field]);

        $this->postJson('/inbound/record-send', $body, $this->bearer())->assertStatus(422);
        $this->assertSame(0, WaMessage::count());
    }

    public static function oversizedFieldProvider(): array
    {
        return [
            // The 'to' value is 21 characters but only 11 of them are digits, so the only thing standing
            // between it and the phone column is max:20 -- the digit guard would let 11 digits through.
            ['to', str_repeat('+', 10) . '18479043315'],
            ['template', str_repeat('t', 121)],
            ['language', str_repeat('e', 11)],
            ['message_id', str_repeat('w', 129)],
        ];
    }

    #[DataProvider('oversizedFieldProvider')]
    public function test_record_send_refuses_a_field_over_its_length_ceiling(string $field, string $value): void
    {
        // N5. Without these the max: rules are decoration and an over-wide value reaches a width-limited
        // column (language 10, meta_id 128, phone 20).
        $body = ['to' => '18479043315', 'template' => 'etp_welcome_a', 'language' => 'en_US',
                 'message_id' => 'wamid.BENCH_N5', 'utc_time' => '2026-11-06T10:00:00Z'];
        $body[$field] = $value;

        $this->postJson('/inbound/record-send', $body, $this->bearer())->assertStatus(422);
        $this->assertSame(0, WaMessage::count());
        $this->assertSame(0, WaContact::count());
    }

    public function test_record_send_stamps_now_when_the_body_has_no_utc_time()
    {
        // N6. The other side of the ternary: sender_server.py always sends utc_time, so this fallback is
        // only ever exercised by a caller that does not. Within a minute, not equal to now().
        $this->postJson('/inbound/record-send', [
            'to' => '18479043315', 'template' => 'etp_welcome_a', 'language' => 'en_US',
            'message_id' => 'wamid.BENCH_N6',
        ], $this->bearer())->assertCreated();

        $sentAt = WaMessage::where('meta_id', 'wamid.BENCH_N6')->firstOrFail()->sent_at;

        $this->assertNotNull($sentAt);
        $this->assertTrue($sentAt->between(now()->subMinute(), now()->addMinute()));
    }

    public function test_record_send_ignores_a_utc_time_that_is_not_the_bench_format()
    {
        // N7. hasFormat() is the guard: drop it and Carbon::parse('not-a-date') throws, which M-B measured
        // as a rendered 500. A garbage timestamp is not a reason to lose the send record.
        $this->postJson('/inbound/record-send', [
            'to' => '18479043315', 'template' => 'etp_welcome_a', 'language' => 'en_US',
            'message_id' => 'wamid.BENCH_N7', 'utc_time' => 'not-a-date',
        ], $this->bearer())->assertCreated();

        $sentAt = WaMessage::where('meta_id', 'wamid.BENCH_N7')->firstOrFail()->sent_at;

        $this->assertNotNull($sentAt);
        $this->assertTrue($sentAt->between(now()->subMinute(), now()->addMinute()));
    }

    public function test_a_nested_array_utc_time_is_a_422_not_a_crash()
    {
        // N8. Same shape as AuthTest's nested-array password row. This is what makes 'string' in the
        // utc_time rule load-bearing: hasFormat()'s first parameter is typed string, so an array is a
        // TypeError and M-B measured a TypeError as a rendered 500.
        $this->postJson('/inbound/record-send', [
            'to' => '18479043315', 'template' => 'etp_welcome_a', 'language' => 'en_US',
            'message_id' => 'wamid.BENCH_N8', 'utc_time' => ['nested'],
        ], $this->bearer())->assertStatus(422);

        $this->assertSame(0, WaMessage::count());
    }

    public function test_record_send_refuses_a_to_that_survives_validation_without_any_digits()
    {
        // N9. D3, gated on M-B: ten characters is enough for min:10 and zero digits is enough to make
        // fromPhone() throw, so the controller rejects it as a validation failure instead of a 500.
        $this->postJson('/inbound/record-send', [
            'to' => 'aaaaaaaaaa', 'template' => 'etp_welcome_a', 'language' => 'en_US',
            'message_id' => 'wamid.BENCH_N9', 'utc_time' => '2026-11-06T10:00:00Z',
        ], $this->bearer())->assertStatus(422);

        $this->assertSame(0, WaMessage::count());
        $this->assertSame(0, WaContact::count());
    }

    public static function machineCallProvider(): array
    {
        return [
            ['get', '/wa/suppressions', []],
            ['post', '/inbound/record-send', [
                'to' => '18479043315', 'template' => 'etp_opener_brand', 'language' => 'en',
                'message_id' => 'wamid.BENCH_N10', 'utc_time' => '2026-11-06T10:00:00Z',
            ]],
        ];
    }

    #[DataProvider('machineCallProvider')]
    public function test_a_machine_call_answers_successfully_and_sets_no_cookie(string $verb, string $uri, array $body): void
    {
        // N10. M-A's residue. Both CSRF exemptions on this POST (the route's ->withoutMiddleware('web')
        // and the 'inbound/record-send' entry in bootstrap/app.php) answer 201 with no token, so the status
        // code cannot tell them apart; what only the route exclusion can do is keep the web group out of
        // the pipeline, and StartSession and VerifyCsrfToken both show up as Set-Cookie headers when it runs.
        // SESSION_DRIVER is array in tests, so a cookie is the whole of what is observable here.
        $res = $verb === 'get' ? $this->getJson($uri, $this->bearer()) : $this->postJson($uri, $body, $this->bearer());

        $this->assertTrue($res->isSuccessful(), $verb . ' ' . $uri . ' answered ' . $res->getStatusCode());
        $this->assertSame([], $res->headers->getCookies());
    }

    public function test_the_record_send_post_refuses_missing_and_wrong_bearers(): void
    {
        // W1. The POST's bearer guard was only ever witnessed as the half of an unrelated row (the unconfigured
        // token row), so moving ->middleware('wa.bearer') off the group onto the GET alone left the whole suite
        // green except that half-row. This is the negative-bearer row for the write endpoint on its own.
        $body = ['to' => '18479043315', 'template' => 'etp_opener_brand', 'language' => 'en',
                 'message_id' => 'wamid.BENCH_W1', 'utc_time' => '2026-11-06T10:00:00Z'];

        $this->postJson('/inbound/record-send', $body)->assertUnauthorized();
        $this->postJson('/inbound/record-send', $body, $this->bearer('nope'))->assertUnauthorized();
        $this->assertSame(0, WaMessage::count());
    }

    public function test_the_two_route_names_the_fence_promises_exist(): void
    {
        // W2. Task 9 parity (AuthTest's route-name row): the fence ships both names and nothing read them.
        // Asserted through Route::has(), never through a generated URL, because a name asserted against a URL
        // passes for the wrong reason under a different APP_URL.
        $this->assertTrue(\Illuminate\Support\Facades\Route::has('wa.suppressions'));
        $this->assertTrue(\Illuminate\Support\Facades\Route::has('wa.record_send'));
    }

    public function test_a_non_string_to_is_422_not_a_crash(): void
    {
        // W3. min:10 and max:20 count ARRAY ELEMENTS (ValidatesAttributes::getSize() returns count() for arrays),
        // so a value must have 10..20 elements to slip past them and reach WaContact::digitsOnly(?string) as an
        // array. A 1-element array is 422 under the mutation too, because min:10 refuses it -- that would be a
        // false witness. This is what makes 'string' in the to rule load-bearing.
        $this->postJson('/inbound/record-send', [
            'to' => array_fill(0, 12, '18479043315'), 'template' => 'etp_opener_brand', 'language' => 'en',
            'message_id' => 'wamid.BENCH_W3',
        ], $this->bearer())->assertStatus(422);

        $this->assertSame(0, WaMessage::count());
        $this->assertSame(0, WaContact::count());
    }

    public static function nonStringFieldProvider(): array
    {
        return [['template', ['x']], ['language', ['en']], ['message_id', ['w']]];
    }

    #[DataProvider('nonStringFieldProvider')]
    public function test_a_non_string_field_is_422_not_a_crash(string $field, array $value): void
    {
        // W4. Same class as W3 for the other three 'string' rules. No min:/max: length rule on these fields
        // counts elements the way min:10 does on to, so a one-element array already reaches the DB binding.
        $body = ['to' => '18479043315', 'template' => 'etp_welcome_a', 'language' => 'en_US',
                 'message_id' => 'wamid.BENCH_W4', 'utc_time' => '2026-11-06T10:00:00Z'];
        $body[$field] = $value;

        $this->postJson('/inbound/record-send', $body, $this->bearer())->assertStatus(422);
        $this->assertSame(0, WaMessage::count());
    }

    public function test_a_null_utc_time_is_accepted_and_stamps_now(): void
    {
        // W5. isset() skips a key that is present but null; array_key_exists() does not, and would feed null to
        // Carbon::hasFormat(string $date) and turn a legal utc_time: null into a 500. N6 only omits the key, which
        // both spellings handle identically, so this is the corner that tells them apart.
        $this->postJson('/inbound/record-send', [
            'to' => '18479043315', 'template' => 'etp_welcome_a', 'language' => 'en_US',
            'message_id' => 'wamid.BENCH_W5', 'utc_time' => null,
        ], $this->bearer())->assertCreated();

        $m = WaMessage::where('meta_id', 'wamid.BENCH_W5')->firstOrFail();

        $this->assertTrue($m->sent_at->between(now()->subMinute(), now()->addMinute()));
    }

    public function test_the_bench_record_carries_its_type_and_source(): void
    {
        // W6. The type column defaults to 'text', so dropping 'type' => 'template' writes a silently wrong row,
        // and raw.source is the only provenance marker on a bench send. Task 13/16 inbox queries read the type
        // column, so this is the guard that matters there.
        $this->postJson('/inbound/record-send', [
            'to' => '18479043315', 'template' => 'etp_opener_brand', 'language' => 'en',
            'message_id' => 'wamid.BENCH_W6', 'utc_time' => '2026-11-06T10:00:00Z',
        ], $this->bearer())->assertCreated();

        $m = WaMessage::where('meta_id', 'wamid.BENCH_W6')->firstOrFail();

        $this->assertSame('template', $m->type);
        $this->assertSame(['source' => 'send_bench'], $m->raw);
    }
}
```

- [ ] **Step 2: Run it** — expected FAIL 404 for both URIs.

- [ ] **Step 3: Create `app/Http/Middleware/VerifyWaBearer.php`**

```php
<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class VerifyWaBearer
{
    public function handle(Request $request, Closure $next)
    {
        $expected = (string) config('whatsapp.export_token', '');
        $given = (string) $request->bearerToken();

        if ($expected === '' || $given === '' || !hash_equals($expected, $given)) {
            return response()->json(['message' => 'unauthorized'], 401);
        }

        return $next($request);
    }
}
```

- [ ] **Step 4: Create `app/Http/Controllers/MachineController.php`**

```php
<?php

namespace App\Http\Controllers;

use App\Models\WaContact;
use App\Models\WaMessage;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class MachineController extends Controller
{
    public function suppressions()
    {
        $numbers = WaContact::whereNotNull('opted_out_at')
            ->orderBy('phone')
            ->pluck('phone')
            ->implode("\n");

        return response($numbers, 200)->header('Content-Type', 'text/plain; charset=utf-8');
    }

    public function recordSend(Request $request)
    {
        $data = $request->validate([
            'to' => ['required', 'string', 'min:10', 'max:20'],
            'template' => ['required', 'string', 'max:120'],
            'language' => ['required', 'string', 'max:10'],
            'message_id' => ['required', 'string', 'max:128'],
            'utc_time' => ['nullable', 'string'],
        ]);

        // Validation measures the raw string, isDialable() measures the digits after the strip, so a ten
        // character "to" with no digits in it survives validate() and lands in fromPhone(), which throws.
        // Measured: an uncaught InvalidArgumentException renders a 500 here, so the bench gets a server
        // error for a body it could fix. Reject it as a 422 through WaContact's own predicate instead --
        // one definition of "dialable", no duplicated length literals.
        if (!WaContact::isDialable(WaContact::digitsOnly($data['to']))) {
            throw ValidationException::withMessages([
                'to' => 'The to field must contain a dialable phone number.',
            ]);
        }

        $contact = WaContact::fromPhone($data['to']);
        $sentAt = isset($data['utc_time']) && \Illuminate\Support\Carbon::hasFormat($data['utc_time'], 'Y-m-d\TH:i:s\Z')
            ? \Illuminate\Support\Carbon::parse($data['utc_time'], 'UTC')
            : now();

        WaMessage::record([
            'wa_contact_id' => $contact->id,
            'meta_id' => $data['message_id'],
            'direction' => WaMessage::OUT,
            'type' => 'template',
            'template_name' => $data['template'],
            'template_lang' => $data['language'],
            'status' => WaMessage::SENT,
            'status_at' => $sentAt,
            'sent_at' => $sentAt,
            'raw' => ['source' => 'send_bench'],
        ]);

        return response()->json(['recorded' => true, 'contact_id' => $contact->id], 201);
    }
}
```

- [ ] **Step 5: Routes and middleware alias.** In `routes/web.php` add the import `use App\Http\Controllers\MachineController;` and, after the webhook routes (outside the `auth` group — these are machine calls, not staff sessions):

```php
Route::middleware('wa.bearer')->group(function () {
    Route::get('/wa/suppressions', [MachineController::class, 'suppressions'])
        ->withoutMiddleware('web')
        ->name('wa.suppressions');
    Route::post('/inbound/record-send', [MachineController::class, 'recordSend'])
        ->withoutMiddleware('web')
        ->name('wa.record_send');
});
```

Same reasoning as Ruling R22 on the webhook: the bench calls these with a bearer token and no
cookie, so they must not mint `sessions` rows or carry an `XSRF-TOKEN`. `withoutMiddleware` is
a **per-route** method — `Illuminate\Routing\Router` has no group-level version (`Route.php:1173`
is the only relevant declaration), so it goes on each route inside the group, not on the group.

In `bootstrap/app.php`, inside `withMiddleware`, add:

```php
        $middleware->alias(['wa.bearer' => \App\Http\Middleware\VerifyWaBearer::class]);
```

Note `recordSend` never touches `opted_out_at`, which is what the suppression-preserving test checks.

- [ ] **Step 6: Run the test**

```bash
php artisan test tests/Feature/MachineEndpointTest.php
```

Expected: 33 passed (80 assertions) -- 25 rows as the brief shipped them, plus the 6 methods (8 rows, one of
them a 3-case provider) that the independent review added under R51 below. Whole suite at this point:
**228 passed (525 assertions)**.

- [ ] **Step 7: Commit**

```bash
git add app routes/web.php bootstrap/app.php tests/Feature/MachineEndpointTest.php
git commit -m "feat: bearer-guarded suppression list and bench send records"
```

---

**R51 -- the implementer called five arms green and was right about four; the review's sixth finding was a guard
the *brief* had promised would fail and did not, and six more shipped with no witness at all.**

Task 10 ran the full cycle: implementer (31 arms, 5 green) -> independent adversarial reviewer (49 runs, 35 arms
of its own, 15 more green) -> fix round (6 methods / 8 rows, each killed against its named arm before shipping).
The five Step fences above are now the shipped files, byte for byte.

**The four pre-authorised deviations, and the two measurements that gated them.**

- **D1** -- `use Illuminate\Validation\Rule;` deleted from the Step 4 fence: unused, sole occurrence.
- **D2** -- `message_id` dropped from the 201 body. The plan's fence returned a third key whose name collides with
  the *request* field `message_id` (a `wamid` string) while holding an integer primary key, and the Produces line
  promised two keys. N1 now asserts the body is *exactly* `{recorded, contact_id}`, so a silently re-added key dies.
- **D3** -- landed, gated on **M-B**. `to = 'aaaaaaaaaa'` passes `min:10,max:20` (those measure the raw string) and
  reaches `fromPhone()`, which throws. Measured on the un-deviated fence state: **500**
  (`InvalidArgumentException: not an E.164 number: aaaaaaaaaa`), 0 rows written, **and the probe test method itself
  did not fail** -- Laravel renders the exception into the response, so a 500 is invisible to PHPUnit unless a row
  asserts the status. That is the general trap for this app: an unwitnessed crash looks like a passing run.
  The controller now rejects with `ValidationException::withMessages(['to' => ...])` computed through
  `WaContact::isDialable(WaContact::digitsOnly(...))`, and the same request is **422**.
- **D4** -- resolved by **M-A**, and the deviation is "no row for the CSRF status". Five states, `env` flipped to
  `production`, valid bearer, no `_token`: shipped **201 / no cookies**; `withoutMiddleware('web')` off the POST
  **201 / XSRF-TOKEN + laravel-session**; that *and* the `except` entry gone **419 / nothing written**; `except`
  entry gone alone **201 / no cookies** (identical to shipped). So the status code cannot attribute either
  exemption; the only distinguishing observable is `Set-Cookie`, which is exactly "did the web group run", and
  that is what N10 asserts.

**The vendor fact that settles the `except` entry.** `Route::withoutMiddleware()` appends to `excluded_middleware`
(`Route.php:1173`) and `Router::resolveMiddleware()` maps an excluded *group alias* through
`MiddlewareNameResolver::resolve()`, which returns the group's concrete members (`MiddlewareNameResolver.php:35-37,
57-90`; web members at `Foundation/Configuration/Middleware.php:485-493`). So `withoutMiddleware('web')` removes
`VerifyCsrfToken`, `EncryptCookies` **and** `StartSession` from that route's pipeline, and it is a route property
that never reads `env` -- which is why M-A's S4 changes nothing in *any* environment. The Task 1 `except` entry is
therefore dead config as shipped and the only thing standing between a regressed route and a 419 in production;
**kept**, as a tripwire, with its status written here instead of a row that would pass for the wrong reason.

**The arms that were green as shipped, and what R51 did about each.**

| arm | mutation (`occurrences=1`, `landed=true`) | as shipped | witness after R51 |
| --- | --- | --- | --- |
| X21 | `wa.bearer` moved from the group onto the GET only | 1 failed, 219 | the surviving death was `test_unconfigured_export_token_refuses_everything`'s POST half, replying **422** -- the POST's guard was only ever half a row. Now **W1** `test_the_record_send_post_refuses_missing_and_wrong_bearers` |
| X23 / X24 | each `->name(...)` deleted | green 220 | **W2** `test_the_two_route_names_the_fence_promises_exist`, through `Route::has()` and never a generated URL (the Task 9 lesson: a name asserted against a URL passes for the wrong reason under another `APP_URL`) |
| X07 | `'string'` dropped from the `to` rule | green 220 | **W3** `test_a_non_string_to_is_422_not_a_crash`. The implementer's C20 claim ("unwitnessable") is refuted: `ValidatesAttributes::getSize()` returns `count($value)` for arrays (`:2764-2781`), so `min:10`/`max:20` count **elements** and a 10..20-element array reaches `digitsOnly(?string)` as a `TypeError` -> 500. The reviewer's refinement to the brief's own reasoning: a 1-element array is 422 under the mutation too, so it would have been a false witness. |
| X09-X11 | `'string'` dropped from `template` / `language` / `message_id` | green 220 | **W4** `test_a_non_string_field_is_422_not_a_crash`, a 3-case `#[DataProvider]` (no `min:`/`max:` stands in front of the DB binding here, so one element suffices) |
| X14 | `isset($data['utc_time'])` -> `array_key_exists(...)` | green 220 | **W5** `test_a_null_utc_time_is_accepted_and_stamps_now`. `isset()` skips a present-but-null key; `array_key_exists()` does not, and would feed `null` to `Carbon::hasFormat(string)` (`Comparison.php:1187`) -- a legal `utc_time: null` becoming a 500. The plan's N6 omits the key, which both spellings handle identically. |
| X12 / X13 | `'type' => 'template'` deleted / `raw.source` rewritten | green 220 | **W6** `test_the_bench_record_carries_its_type_and_source`. The column default is `'text'`, so the deletion writes a silently wrong row, and Tasks 11/13 read the `type` column. |
| X01 | **both** `$expected === ''` and `$given === ''` deleted, only `hash_equals` left | 1 failed, 219 | the brief predicted the single deletions would fail that row; measured **green** (X01a, X01b). Deleting both fails it at the GET, which answers **200** because the row sends `bearer('')` -> `Request::bearerToken()` is `''` and `hash_equals('', '')` is **true**. So: individually redundant, jointly load-bearing, **jointly witnessed** -- the report's §6.1 said "`hash_equals()` already refuses the unconfigured state", which is false for the only bearer that row sends. Same shape as Task 9's `redirectGuestsTo`/`name('login')` pair, one step better: this pair dies on plain deletion. |
| X04 | `(string)` cast dropped from `$given` | 1 failed, 219 | already witnessed by `test_missing_bearer_is_401`, which receives a **500** (`hash_equals(): Argument #2 must be of type string, null given`). Recorded because nobody chose that cast consciously: with the header absent `bearerToken()` is `null`, not `''`. |

**Recorded, deliberately not changed, and not written up as rows because a row would pass for the wrong reason:**

- **`hash_equals` vs strict `!==` (X03) is un-witnessable in-process.** Deleting `$expected !== $given` for
  `hash_equals` leaves the suite green, and no feature row can separate a constant-time compare from a
  non-constant one; that would need a timing benchmark, which the house rules forbid. N3 already pins the `==`
  failure mode (type juggling), which is the realistic bug. Written report instead.
- **`min:10` on `to` (X08) cannot change an outcome.** `digitsOnly()` can only shrink, so fewer than 10 characters
  can never yield 10..20 digits, and the D3 guard 422s exactly what `min:10` 422s. The two are status-equivalent and
  differ only in the error copy. Kept (zero cost, narrows the D3 surface); a message-pinning row was rejected as
  copy-fragile. The report's §6.4 had the order backwards -- `validate()` runs *first*; the guard is what runs after.
- **`(string)` on `$expected` (X05) and the 401 body copy `'unauthorized'` (X06)** -- inert config cast, and copy
  with no behaviour behind it (Task 9's V4 family). Recorded.
- **Alias vs inline class for the middleware (X22) is unpinned**; the *presence* of the alias is witnessed jointly
  by X30 (deleting it fails all 25 rows and drops the suite to exactly 195/445). Nothing pins which spelling
  `routes/web.php` uses; accepted.
- **"Ascending" means bytewise.** `orderBy('phone')` over a `varchar` is lexicographic, so `10000000000` lists
  *before* `9999999999` (measured). No shipped writer can put a non-digit into `wa_contacts.phone` -- every path goes
  through `fromPhone()` -> `digitsOnly()` + `isDialable()`. Harmless for a set-diff consumer, and MariaDB's
  `utf8mb4_*_ci` agrees with sqlite's BINARY for pure digits, so no cross-driver drift. The Produces line now says
  so. Only worth a code change if the bench ever diffs by position.
- **A replaying bench splits two columns on one row.** Same `meta_id`, second `utc_time` `2027-01-02T03:04:05Z`:
  rows stay **1** (dedupe is honest) but `sent_at` is rewritten while `status_at` keeps the first send, because
  `WaMessage::record()` unsets `status`/`status_at` on a same-rank replay and leaves `sent_at` in the update set.
  The plan's replay row posts the same body twice and cannot see it. **Recorded for Task 13, not pinned** -- the
  only green row available today would certify the wart as the contract on the page a reader expects the promise,
  and fixing `record()` is out of scope (Task 5 closed). Task 13's import decides.
- **Harness discipline from the fix round:** a first attempt at the X21 arm was written as
  `Route::group(function () { ... })`, which is not valid Laravel API (`Router::group()` takes an attributes array
  first) -- every HTTP test in the suite errored (`errors="144"`, no `Tests:` summary) while the name-matching still
  reported "my row died". Refused and re-run as `Route::group([], ...)`, and two guards added to the harness before
  any number was kept: a run with no artisan summary is refused, and each row's *reason of death* is printed from
  the junit failure body rather than inferred from the row name. A second bug went the safe direction: exact-string
  row matching does not match PHPUnit's `… with data set #0`, so D1 first reported `MY_ROW_KILLED=false` for an arm
  that did kill it; the matcher was patched and the arm re-run rather than re-interpreted.

Counts after R51: baseline **195 passed (445 assertions)** -> implementer **220 (506)** -> fix round
**228 passed (525 assertions)**, of which `tests/Feature/MachineEndpointTest.php` is **33 rows / 80 assertions**
(the plan's 8, the brief's N1-N10 to 25, and R51's 6 methods = 8 rows). Four source files unchanged by the fix
round (`VerifyWaBearer.php 9b55b56d/497`, `MachineController.php 98fde92c/2474`, `routes/web.php 91241c84/1173`,
`bootstrap/app.php 14700659/832`); `MachineEndpointTest.php` 12344 -> **17145 bytes, sha8 `c60395e0`**. Task 9's
five files untouched at `ef12297f` / `0071aae6` / `575da960` / `152be586` / `92989a3f`.

---

## Task 11: Inbox views

**Deliverable:** a signed-in user sees the contact list with opt-out and window state, and a thread that shows inbound on the left, outbound on the right, marks machine sends as automatic, and shows the failure reason for refused sends.

**Consumes:** `WaContact::display_name()`, `windowOpen()`, `isOptedOut()`, `WaMessage` rows.
**Produces:** `App\Http\Controllers\InboxController::contacts(Request)` and `::thread(WaContact)`; views `inbox/contacts.blade.php`, `inbox/thread.blade.php`, `vendor/pagination/etp.blade.php` (D3); the first `/inbox` routes, added inside the `auth` group Task 9 created.

- [ ] **Step 1: Write the failing test** — `tests/Feature/InboxViewTest.php`

```php
<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\WaContact;
use App\Models\WaMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\MessageBag;
use Illuminate\Support\ViewErrorBag;
use Tests\TestCase;

class InboxViewTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
    }

    // ---- the plan's five rows (Step 1 fence; D1: talking contact gets last_inbound_at) ----

    public function test_list_shows_name_number_and_state()
    {
        $talking = WaContact::factory()->create(['phone' => '18479043315', 'profile_name' => 'Jane Tester',
                                                 'last_inbound_at' => now()]);
        WaMessage::factory()->create(['wa_contact_id' => $talking->id, 'direction' => WaMessage::IN,
                                      'sent_at' => now(), 'body' => 'hello']);
        WaContact::factory()->create(['phone' => '15550002222', 'profile_name' => 'Silent Sam'])->optOut('manual');

        $this->get('/inbox')->assertOk()
            ->assertSee('Jane Tester')->assertSee('+18479043315')
            ->assertSee('Silent Sam')->assertSee('opted out')
            ->assertSee('window open');
    }

    public function test_list_search_filters_by_phone_and_name()
    {
        WaContact::factory()->create(['phone' => '18479043315', 'profile_name' => 'Jane Tester']);
        WaContact::factory()->create(['phone' => '19014307897', 'profile_name' => 'Bob Other']);

        $this->get('/inbox?q=Bob')->assertSee('Bob Other')->assertDontSee('Jane Tester');
        $this->get('/inbox?q=1847904')->assertSee('Jane Tester')->assertDontSee('Bob Other');
    }

    public function test_thread_shows_both_directions_and_marks_automatic_sends()
    {
        $c = WaContact::factory()->create(['phone' => '18479043315', 'profile_name' => 'Jane Tester']);
        WaMessage::factory()->create(['wa_contact_id' => $c->id, 'direction' => WaMessage::IN,
                                      'meta_id' => 'wamid.I1', 'body' => 'Is this E Tax Planner?',
                                      'sent_at' => now()->subMinutes(30)]);
        WaMessage::factory()->create(['wa_contact_id' => $c->id, 'direction' => WaMessage::OUT,
                                      'meta_id' => 'wamid.O1', 'type' => 'template',
                                      'template_name' => 'etp_opener_brand', 'template_lang' => 'en',
                                      'body' => 'Hi Jane, ...', 'status' => WaMessage::DELIVERED,
                                      'auto_reply' => false, 'sent_at' => now()->subHour()]);
        WaMessage::factory()->create(['wa_contact_id' => $c->id, 'direction' => WaMessage::OUT,
                                      'meta_id' => 'wamid.O2', 'auto_reply' => true, 'status' => WaMessage::SENT,
                                      'body' => 'Thanks, a human will reply.', 'sent_at' => now()->subMinutes(20)]);
        WaMessage::factory()->create(['wa_contact_id' => $c->id, 'direction' => WaMessage::OUT,
                                      'meta_id' => null, 'status' => WaMessage::FAILED,
                                      'error_code' => '131030', 'error_title' => 'Re-engagement message',
                                      'body' => 'late note', 'sent_at' => now()->subMinute()]);

        $this->get('/inbox/' . $c->id)->assertOk()
            ->assertSee('Is this E Tax Planner?')
            ->assertSee('Jane Tester')
            ->assertSee('delivered')
            ->assertSee('automatic')
            ->assertSee('FAILED 131030: Re-engagement message')
            ->assertSee('etp_opener_brand')
            ->assertSee('name="body"', false);
    }

    public function test_thread_of_an_image_message_shows_a_placeholder_not_a_crash()
    {
        $c = WaContact::factory()->create(['phone' => '18479043315']);
        WaMessage::factory()->create(['wa_contact_id' => $c->id, 'type' => 'image', 'body' => null]);

        $this->get('/inbox/' . $c->id)->assertOk()
            ->assertSee('(image message - text not stored here)');
    }

    public function test_thread_404s_for_an_unknown_contact()
    {
        $this->get('/inbox/999')->assertNotFound();
    }

    // ---- witness rows added by the brief ----

    public function test_list_shows_window_state_three_ways_and_prefers_opted_out()
    {
        WaContact::factory()->create(['phone' => '15550001111', 'profile_name' => 'Win Open',
                                      'last_inbound_at' => now()]);
        WaContact::factory()->create(['phone' => '15550002222', 'profile_name' => 'Window Stale',
                                      'last_inbound_at' => now()->subHours(25)]);
        WaContact::factory()->create(['phone' => '15550003333', 'profile_name' => 'Never One']);
        WaContact::factory()->create(['phone' => '15550004444', 'profile_name' => 'Both States',
                                      'last_inbound_at' => now()])->optOut('manual');

        $content = $this->get('/inbox')->assertOk()->getContent();

        $this->assertSame(1, substr_count($content, 'window open'));
        $this->assertSame(2, substr_count($content, 'template only'));
        $this->assertSame(1, substr_count($content, 'never replied'));
        $this->assertSame(1, substr_count($content, 'opted out'));
    }

    public function test_list_shows_inbound_and_outbound_message_counts()
    {
        $c = WaContact::factory()->create(['phone' => '15550101010', 'profile_name' => 'Counted']);
        WaMessage::factory()->create(['wa_contact_id' => $c->id, 'direction' => WaMessage::IN]);
        WaMessage::factory()->create(['wa_contact_id' => $c->id, 'direction' => WaMessage::OUT]);
        WaMessage::factory()->create(['wa_contact_id' => $c->id, 'direction' => WaMessage::OUT]);

        $content = $this->get('/inbox')->assertOk()->getContent();

        $this->assertStringContainsString('1 in / 2 out', $content);
        $this->assertStringNotContainsString('page=', $content);
    }

    public function test_the_layout_logout_form_and_its_token_render_on_the_contact_list()
    {
        $content = $this->get('/inbox')->assertOk()->getContent();

        $this->assertStringContainsString('action="/logout"', $content);
        $this->assertStringContainsString('name="_token"', $content);
    }

    public function test_a_thread_page_carries_four_csrf_fields_for_its_three_forms_and_the_logout()
    {
        $c = WaContact::factory()->create(['phone' => '15550202020']);

        $content = $this->get('/inbox/' . $c->id)->assertOk()->getContent();

        $this->assertSame(4, substr_count($content, 'name="_token"'));
    }

    public function test_bubbles_are_classed_by_direction_and_the_blocks_appear_once_each()
    {
        $c = WaContact::factory()->create(['phone' => '15550303030', 'profile_name' => 'Sided']);
        WaMessage::factory()->create(['wa_contact_id' => $c->id, 'direction' => WaMessage::IN,
                                      'meta_id' => 'wamid.S1', 'sent_at' => now()->subMinutes(30)]);
        WaMessage::factory()->create(['wa_contact_id' => $c->id, 'direction' => WaMessage::OUT,
                                      'meta_id' => 'wamid.S2', 'sent_at' => now()->subHour()]);
        WaMessage::factory()->create(['wa_contact_id' => $c->id, 'direction' => WaMessage::OUT,
                                      'meta_id' => 'wamid.S3', 'auto_reply' => true,
                                      'sent_at' => now()->subMinutes(20)]);
        WaMessage::factory()->create(['wa_contact_id' => $c->id, 'direction' => WaMessage::OUT,
                                      'meta_id' => null, 'status' => WaMessage::FAILED,
                                      'error_code' => '131030', 'error_title' => 'Re-engagement message',
                                      'sent_at' => now()->subMinute()]);

        $content = $this->get('/inbox/' . $c->id)->assertOk()->getContent();

        $this->assertSame(1, substr_count($content, 'class="bubble in"'));
        $this->assertSame(3, substr_count($content, 'class="bubble out"'));
        $this->assertSame(1, substr_count($content, '>automatic<'));
        $this->assertSame(1, substr_count($content, 'FAILED 131030:'));
    }

    public function test_a_sent_outbound_message_prints_no_failure_block()
    {
        $c = WaContact::factory()->create(['phone' => '15550404040']);
        WaMessage::factory()->create(['wa_contact_id' => $c->id, 'direction' => WaMessage::OUT,
                                      'status' => WaMessage::SENT, 'auto_reply' => false]);

        $content = $this->get('/inbox/' . $c->id)->assertOk()->getContent();

        $this->assertStringNotContainsString('FAILED', $content);
    }

    public function test_a_template_bubble_names_the_template_and_its_language()
    {
        $c = WaContact::factory()->create(['phone' => '15550505050']);
        WaMessage::factory()->create(['wa_contact_id' => $c->id, 'direction' => WaMessage::OUT,
                                      'type' => 'template', 'template_name' => 'etp_opener_brand',
                                      'template_lang' => 'en', 'status' => WaMessage::DELIVERED]);

        $content = $this->get('/inbox/' . $c->id)->assertOk()->getContent();

        $this->assertStringContainsString('etp_opener_brand (en)', $content);
    }

    public function test_thread_header_names_the_opt_out_source()
    {
        $c = WaContact::factory()->create(['phone' => '15550606060']);
        $c->optOut('manual');

        $content = $this->get('/inbox/' . $c->id)->assertOk()->getContent();

        $this->assertStringContainsString('OPTED OUT (manual', $content);
    }

    public function test_thread_header_shows_both_window_branches()
    {
        $open = WaContact::factory()->create(['phone' => '15550707070', 'last_inbound_at' => now()]);
        $closed = WaContact::factory()->create(['phone' => '15550707071', 'last_inbound_at' => now()->subHours(25)]);

        $this->get('/inbox/' . $open->id)->assertOk()->assertSee('reply window open');
        $this->get('/inbox/' . $closed->id)->assertOk()
            ->assertSee('reply window closed - approved template required');
    }

    public function test_the_template_select_is_built_from_the_config_key_not_a_hardcoded_list()
    {
        $previous = config('whatsapp.template_choices');
        config(['whatsapp.template_choices' => ['etp_probe_a', 'etp_probe_b']]);

        try {
            $c = WaContact::factory()->create(['phone' => '15550808080']);

            $this->get('/inbox/' . $c->id)->assertOk()
                ->assertSee('<option value="etp_probe_a">', false)
                ->assertSee('<option value="etp_probe_b">', false);
        } finally {
            config(['whatsapp.template_choices' => $previous]);
        }
    }

    // ---- M-A rows: pagination is real (shipped only if both M-A arms die) ----

    public function test_the_first_search_page_holds_thirty_rows_and_next_keeps_the_query()
    {
        for ($i = 1; $i <= 31; $i++) {
            WaContact::factory()->create(['profile_name' => sprintf('abc%02d', $i)]);
        }

        $content = $this->get('/inbox?q=abc')->assertOk()->getContent();

        $this->assertSame(30, substr_count($content, 'href="/inbox/'));
        $this->assertStringContainsString('Showing 1 to 30 of 31', $content);
        $this->assertStringContainsString('page=2', $content);
        $this->assertStringContainsString('q=abc', $content);
        $this->assertStringNotContainsString('abc01', $content);

        $second = $this->get('/inbox?q=abc&page=2')->assertOk()->getContent();
        $this->assertStringContainsString('abc01', $second);
        $this->assertStringContainsString('Showing 31 to 31 of 31', $second);
        $this->assertStringContainsString('q=abc', $second);
        $this->assertStringContainsString('page=1', $second);
    }

    // ---- M-B rows: the ordering is observable (shipped only if they kill arms) ----

    public function test_list_sorts_by_recent_activity_not_by_id()
    {
        $senior = WaContact::factory()->create(['profile_name' => 'Senior Id', 'last_inbound_at' => now()]);
        $junior = WaContact::factory()->create(['profile_name' => 'Junior Id',
                                                'last_inbound_at' => now()->subHours(10)]);
        $this->assertLessThan($junior->id, $senior->id);

        $content = $this->get('/inbox')->assertOk()->getContent();

        // PHPUnit's assertLessThan($limit, $actual): the senior (fresher activity, lower id)
        // link must sit at a SMALLER byte offset than the junior one.
        $this->assertLessThan(strpos($content, 'href="/inbox/' . $junior->id),
                              strpos($content, 'href="/inbox/' . $senior->id));
    }

    public function test_list_breaks_an_activity_tie_by_descending_id()
    {
        $at = now();
        $low = WaContact::factory()->create(['profile_name' => 'Low Id', 'last_inbound_at' => $at]);
        $high = WaContact::factory()->create(['profile_name' => 'High Id', 'last_inbound_at' => $at]);

        $content = $this->get('/inbox')->assertOk()->getContent();

        $this->assertLessThan(strpos($content, 'href="/inbox/' . $low->id),
                              strpos($content, 'href="/inbox/' . $high->id));
    }

    public function test_a_blank_query_lists_everyone_like_no_query_at_all()
    {
        WaContact::factory()->create(['phone' => '15550909090', 'profile_name' => 'Cher']);
        WaContact::factory()->create(['phone' => '15550909091', 'profile_name' => 'Jane Tester']);

        $content = $this->get('/inbox?q=%20')->assertOk()->getContent();

        $this->assertStringContainsString('Cher', $content);
        $this->assertStringContainsString('Jane Tester', $content);
    }

    public function test_the_search_box_escapes_what_it_echoes()
    {
        $content = $this->get('/inbox?q=%3Cb%3E')->assertOk()->getContent();

        $this->assertStringContainsString('&lt;b&gt;', $content);
        $this->assertStringNotContainsString('<b>', $content);
    }

    public function test_an_empty_address_book_shows_the_empty_state_and_no_rows()
    {
        $content = $this->get('/inbox')->assertOk()->getContent();

        $this->assertStringContainsString('No contacts yet.', $content);
        $this->assertStringNotContainsString('href="/inbox/', $content);
        $this->assertStringNotContainsString('page=', $content);
    }

    public function test_a_thread_without_messages_shows_a_placeholder()
    {
        $c = WaContact::factory()->create(['phone' => '15551010101']);

        $this->get('/inbox/' . $c->id)->assertOk()->assertSee('Nothing here yet.');
    }

    public function test_the_two_inbox_route_names_the_fence_promises_exist()
    {
        $this->assertTrue(Route::has('inbox'));
        $this->assertTrue(Route::has('inbox.thread'));
    }

    public function test_list_shows_a_relative_time_for_the_last_inbound()
    {
        WaContact::factory()->create(['phone' => '15551111111', 'profile_name' => 'Two Days',
                                      'last_inbound_at' => now()->subDays(2)->subMinutes(1)]);

        $content = $this->get('/inbox')->assertOk()->getContent();

        $this->assertStringContainsString('2 days ago', $content);
    }

    public function test_a_thread_page_renders_the_flash_errors_and_old_reply_it_is_handed()
    {
        $c = WaContact::factory()->create(['phone' => '15551212121']);

        $bag = new ViewErrorBag;
        $bag->put('default', new MessageBag([
            'body' => ['Reply body cannot be blank.'],
            'window' => ['The 24-hour window is closed.'],
            'template' => ['Pick an approved template.'],
        ]));

        $this->withSession(['errors' => $bag, '_old_input' => ['body' => 'etp old reply text']])
            ->get('/inbox/' . $c->id)->assertOk()
            ->assertSee('Reply body cannot be blank.')
            ->assertSee('The 24-hour window is closed.')
            ->assertSee('Pick an approved template.')
            ->assertSee('etp old reply text');
    }

    // ---- R52 review witnesses (task-11-review.md §3.2; each row mutation-verified) ----

    public function test_a_guest_is_bounced_off_the_list_and_the_thread()
    {
        $c = WaContact::factory()->create(['phone' => '15551313131']);
        auth()->logout();

        $this->get('/inbox')->assertRedirect('/login');
        $this->get('/inbox/' . $c->id)->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_a_thread_lists_oldest_message_first_even_when_insertion_is_scrambled()
    {
        $c = WaContact::factory()->create(['phone' => '15551112222']);
        WaMessage::factory()->create(['wa_contact_id' => $c->id, 'direction' => WaMessage::IN,
                                      'body' => 'SECOND-MSG', 'sent_at' => now()->subMinutes(10)]);
        WaMessage::factory()->create(['wa_contact_id' => $c->id, 'direction' => WaMessage::IN,
                                      'body' => 'FIRST-MSG', 'sent_at' => now()->subMinutes(30)]);

        $content = $this->get('/inbox/' . $c->id)->assertOk()->getContent();

        $this->assertLessThan(strpos($content, 'SECOND-MSG'), strpos($content, 'FIRST-MSG'));
    }

    public function test_a_message_body_is_rendered_escaped()
    {
        $c = WaContact::factory()->create(['phone' => '15552121212']);
        WaMessage::factory()->create(['wa_contact_id' => $c->id, 'direction' => WaMessage::IN,
                                      'body' => '<script>alert(1)</script>']);

        $content = $this->get('/inbox/' . $c->id)->assertOk()->getContent();

        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $content);
        $this->assertStringNotContainsString('<script>alert(1)', $content);
    }

    public function test_the_thread_header_and_variables_input_escape_the_profile_name()
    {
        $c = WaContact::factory()->create(['phone' => '15552222222', 'profile_name' => '<b>XSS Name</b>']);

        $content = $this->get('/inbox/' . $c->id)->assertOk()->getContent();

        $this->assertStringContainsString('<h2 style="margin:0">&lt;b&gt;XSS Name&lt;/b&gt;', $content);
        $this->assertStringContainsString('value="&lt;b&gt;XSS Name&lt;/b&gt;"', $content);
        $this->assertStringNotContainsString('<b>XSS Name</b>', $content);
    }

    public function test_the_contact_list_escapes_the_display_name()
    {
        WaContact::factory()->create(['phone' => '15552323232', 'profile_name' => '<i>Italic Name</i>']);

        $content = $this->get('/inbox')->assertOk()->getContent();

        $this->assertStringContainsString('&lt;i&gt;Italic Name&lt;/i&gt;', $content);
        $this->assertStringNotContainsString('<i>Italic Name</i>', $content);
    }

    public function test_a_never_replied_contact_sorts_by_its_creation_not_below_active_ones()
    {
        $stale = WaContact::factory()->create(['phone' => '15551919191', 'profile_name' => 'Stale Active',
                                               'last_inbound_at' => now()->subDays(3)]);
        $freshNull = WaContact::factory()->create(['phone' => '15552020202', 'profile_name' => 'Fresh Never']);

        $content = $this->get('/inbox')->assertOk()->getContent();

        $this->assertLessThan(strpos($content, 'href="/inbox/' . $stale->id),
                              strpos($content, 'href="/inbox/' . $freshNull->id));
    }

    public function test_the_three_thread_forms_post_to_the_task_12_urls()
    {
        $c = WaContact::factory()->create(['phone' => '15551414141']);

        $content = $this->get('/inbox/' . $c->id)->assertOk()->getContent();

        $this->assertStringContainsString('action="/inbox/' . $c->id . '/reply"', $content);
        $this->assertStringContainsString('action="/inbox/' . $c->id . '/template"', $content);
        $this->assertStringContainsString('action="/inbox/' . $c->id . '/opt-out"', $content);
    }

    public function test_pagination_ends_are_spans_not_empty_links()
    {
        for ($i = 1; $i <= 31; $i++) {
            WaContact::factory()->create(['profile_name' => sprintf('abc%02d', $i)]);
        }

        $first = $this->get('/inbox?q=abc')->assertOk()->getContent();
        $last = $this->get('/inbox?q=abc&page=2')->assertOk()->getContent();

        $this->assertStringNotContainsString('href=""', $first);
        $this->assertStringNotContainsString('href=""', $last);
        $this->assertStringContainsString('>Previous</span>', $first);
        $this->assertStringContainsString('>Next</span>', $last);
    }

    public function test_an_inbound_bubble_carries_its_timestamp()
    {
        $c = WaContact::factory()->create(['phone' => '15551515151']);
        $m = WaMessage::factory()->create(['wa_contact_id' => $c->id, 'direction' => WaMessage::IN,
                                           'sent_at' => now()->subMinutes(30)]);

        $this->get('/inbox/' . $c->id)->assertOk()
            ->assertSee($m->sent_at->toDayDateTimeString());
    }

    public function test_the_old_reply_is_escaped_when_it_is_echoed()
    {
        $c = WaContact::factory()->create(['phone' => '15551616161']);

        $content = $this->withSession(['_old_input' => ['body' => '<script>alert(2)</script>']])
            ->get('/inbox/' . $c->id)->assertOk()->getContent();

        $this->assertStringContainsString('&lt;script&gt;alert(2)&lt;/script&gt;', $content);
        $this->assertStringNotContainsString('<script>alert(2)', $content);
    }

    public function test_a_plain_text_bubble_does_not_claim_to_be_a_template()
    {
        $c = WaContact::factory()->create(['phone' => '15551717171']);
        WaMessage::factory()->create(['wa_contact_id' => $c->id, 'direction' => WaMessage::OUT,
                                      'type' => 'text', 'status' => WaMessage::SENT]);

        $content = $this->get('/inbox/' . $c->id)->assertOk()->getContent();

        $this->assertStringNotContainsString('&middot; template', $content);
    }

    public function test_an_empty_address_book_prints_no_showing_line()
    {
        $content = $this->get('/inbox')->assertOk()->getContent();

        $this->assertStringNotContainsString('Showing', $content);
    }

    public function test_a_nameless_contact_falls_back_to_its_number_in_both_views()
    {
        $c = WaContact::factory()->create(['phone' => '15551818181', 'profile_name' => null]);

        $list = $this->get('/inbox')->assertOk()->getContent();
        $this->assertSame(2, substr_count($list, '+15551818181')); // link text + Number column

        $thread = $this->get('/inbox/' . $c->id)->assertOk()->getContent();
        $this->assertStringContainsString('<h2 style="margin:0">+15551818181', $thread);
    }

    public function test_the_number_column_escapes_the_phone()
    {
        WaContact::factory()->create(['phone' => '<b>15551234567</b>', 'profile_name' => 'Odd Phone']);

        $content = $this->get('/inbox')->assertOk()->getContent();

        $this->assertStringContainsString('+&lt;b&gt;15551234567&lt;/b&gt;', $content);
        $this->assertStringNotContainsString('+<b>15551234567</b>', $content);
    }
}
```

- [ ] **Step 2: Run it** — expected FAIL class not found / view not found.

- [ ] **Step 3: Create `app/Http/Controllers/InboxController.php`** (read methods only; the POSTs arrive in Task 12)

```php
<?php

namespace App\Http\Controllers;

use App\Models\WaContact;
use App\Models\WaMessage;
use Illuminate\Http\Request;

class InboxController extends Controller
{
    public function contacts(Request $request)
    {
        $q = trim((string) $request->query('q', ''));

        $contacts = WaContact::query()
            ->when($q !== '', function ($query) use ($q) {
                $query->where(function ($inner) use ($q) {
                    $inner->where('phone', 'like', '%' . $q . '%')
                          ->orWhere('profile_name', 'like', '%' . $q . '%');
                });
            })
            ->withCount(['messages as inbound_count' => fn ($q2) => $q2->where('direction', WaMessage::IN)])
            ->withCount(['messages as outbound_count' => fn ($q2) => $q2->where('direction', WaMessage::OUT)])
            ->orderByRaw('coalesce(last_inbound_at, created_at) desc')
            ->orderByDesc('id')
            ->paginate(30)
            ->withQueryString();

        return view('inbox.contacts', ['contacts' => $contacts, 'q' => $q]);
    }

    public function thread(WaContact $contact)
    {
        return view('inbox.thread', [
            'contact' => $contact,
            'messages' => $contact->messages()->get(),
            'choices' => config('whatsapp.template_choices'),
        ]);
    }
}
```

`orderByRaw('coalesce(last_inbound_at, created_at) desc')` is valid on MariaDB, MySQL 8 and sqlite, which is why the ordering is raw SQL rather than two chained `orderBy` calls.

- [ ] **Step 4: Create `resources/views/inbox/contacts.blade.php`**

```blade
@extends('layout')
@section('content')
<form method="GET" action="/inbox" style="margin-bottom:14px">
  <input name="q" value="{{ $q }}" placeholder="name or number" style="width:320px">
  <button type="submit">Search</button>
</form>

<table>
  <thead><tr><th>Contact</th><th>Number</th><th>Last inbound</th><th>Msgs</th><th>State</th></tr></thead>
  <tbody>
  @forelse ($contacts as $c)
    <tr>
      <td><a href="/inbox/{{ $c->id }}">{{ $c->display_name() }}</a></td>
      <td>+{{ $c->phone }}</td>
      <td>{{ $c->last_inbound_at ? $c->last_inbound_at->diffForHumans() : 'never replied' }}</td>
      <td>{{ $c->inbound_count }} in / {{ $c->outbound_count }} out</td>
      <td>
        @if ($c->isOptedOut())
          <span class="badge">opted out</span>
        @elseif ($c->windowOpen())
          <span class="badge" style="background:#e6f0ff;color:#1830B8">window open</span>
        @else
          <span class="muted">template only</span>
        @endif
      </td>
    </tr>
  @empty
    <tr><td colspan="5" class="muted">No contacts yet. Inbound messages appear here as soon as the webhook is subscribed.</td></tr>
  @endforelse
  </tbody>
</table>
<div class="muted">{{ $contacts->links('pagination::etp') }}</div>
@endsection
```

**D3 (R52): also create `resources/views/vendor/pagination/etp.blade.php`** — the list calls `links('pagination::etp')`, not the framework default (which doubles its text and needs Tailwind).

```blade
@if ($paginator->hasPages())
  <div style="display:flex;gap:14px;align-items:center">
    @if ($paginator->onFirstPage())
      <span class="muted">Previous</span>
    @else
      <a href="{{ $paginator->previousPageUrl() }}">Previous</a>
    @endif
    <span class="muted">Showing {{ $paginator->firstItem() }} to {{ $paginator->lastItem() }} of {{ $paginator->total() }}</span>
    @if ($paginator->hasMorePages())
      <a href="{{ $paginator->nextPageUrl() }}">Next</a>
    @else
      <span class="muted">Next</span>
    @endif
  </div>
@endif
```

- [ ] **Step 5: Create `resources/views/inbox/thread.blade.php`**

```blade
@extends('layout')
@section('content')
<p class="muted"><a href="/inbox">All contacts</a></p>
<h2 style="margin:0">{{ $contact->display_name() }} <span class="muted">+{{ $contact->phone }}</span></h2>
<p class="muted">
  {{ $contact->isOptedOut() ? 'OPTED OUT (' . $contact->opt_out_source . ' ' . $contact->opted_out_at?->toDayDateTimeString() . ')' : ($contact->windowOpen() ? 'reply window open' : 'reply window closed - approved template required') }}
</p>

<div style="background:#fff;border:1px solid var(--line);border-radius:10px;padding:14px;margin:14px 0">
@forelse ($messages as $m)
  <div class="bubble {{ $m->direction === 'in' ? 'in' : 'out' }}">
    @if ($m->direction === 'out')
      <div class="muted" style="font-size:12px">
        {{ $m->sent_at->toDayDateTimeString() }}
        @if ($m->type === 'template') &middot; template {{ $m->template_name }} ({{ $m->template_lang }}) @endif
        @if ($m->status) &middot; {{ $m->status }} @endif
      </div>
    @endif
    {{ $m->body ?? '(' . $m->type . ' message - text not stored here)' }}
    @if ($m->status === 'failed')
      <div class="err">FAILED {{ $m->error_code }}: {{ $m->error_title }}</div>
    @endif
    @if ($m->auto_reply)
      <div class="auto">automatic</div>
    @endif
    @if ($m->direction === 'in')
      <div class="muted" style="font-size:12px">{{ $m->sent_at->toDayDateTimeString() }}</div>
    @endif
  </div>
@empty
  <p class="muted">Nothing here yet.</p>
@endforelse
</div>

<form method="POST" action="/inbox/{{ $contact->id }}/reply">
  @csrf
  <label class="muted">Reply (free-form, needs an open window)</label>
  <textarea name="body">{{ old('body') }}</textarea>
  @error('body')<div class="err">{{ $message }}</div>@enderror
  @foreach ($errors->get('window') as $e) <div class="err">{{ $e }}</div> @endforeach
  <div style="margin-top:8px"><button type="submit">Send reply</button></div>
</form>

<form method="POST" action="/inbox/{{ $contact->id }}/template" style="margin-top:18px">
  @csrf
  <label class="muted">Approved template (works outside the window)</label>
  <div>
    <select name="template">
      @foreach ($choices as $choice) <option value="{{ $choice }}">{{ $choice }}</option> @endforeach
    </select>
    <input name="language" value="en" size="6" placeholder="lang">
    <input name="variables" value="{{ $contact->profile_name }}" size="24" placeholder="body variables, comma separated">
  </div>
  @foreach ($errors->get('template') as $e) <div class="err">{{ $e }}</div> @endforeach
  <div style="margin-top:8px"><button type="submit">Send template</button></div>
</form>

<form method="POST" action="/inbox/{{ $contact->id }}/opt-out" style="margin-top:18px">
  @csrf
  <button type="submit" style="background:#9aa4b2">Mark opted out</button>
  <span class="muted">stops every automatic message to this number</span>
</form>
@endsection
```

The two `@error('window')` loops reference flash errors that only exist after Task 12 adds the POST handlers; until then the forms below the thread render but are not wired, and the thread test only asserts the markup is present.

- [ ] **Step 6: Register the inbox pages.** Add `use App\Http\Controllers\InboxController;` to the imports at the top of `routes/web.php`, and add these two lines inside the existing `Route::middleware('auth')->group(...)` block, next to the `home` route:

```php
    Route::get('/inbox', [InboxController::class, 'contacts'])->name('inbox');
    Route::get('/inbox/{contact}', [InboxController::class, 'thread'])->name('inbox.thread');
```

- [ ] **Step 7: Run the test**

```bash
php artisan test tests/Feature/InboxViewTest.php
```

Expected: 39 passed (134 assertions) — the plan's 5 rows, the brief's 20 (D1 fixture fix, the M-A/M-B gated
rows, the R50 V1 carry), and R52's 14 review witnesses below. Whole suite at this point: **267 passed
(659 assertions)**. The 404 row relies on implicit route binding: because `thread()` type-hints `WaContact`,
Laravel aborts with 404 for an id that does not exist, which is the behavior being asserted.

- [ ] **Step 8: Commit**

```bash
git add app/Http/Controllers/InboxController.php resources/views/inbox resources/views/vendor routes/web.php tests/Feature/InboxViewTest.php
git commit -m "feat: contact list and thread views"
```

---

**R52 -- the review's 37-arm battery found the staff inbox's guest bounce unwitnessed, and two of the
controller's seed predictions were refuted by measurement.**

Task 11 ran the full cycle: implementer (25 rows / 89 assertions, 41 arm runs) -> independent adversarial
reviewer (37 whole-suite arms + 18 targeted witness-verification runs, **22 green arms**, 14 mutation-verified
rows proposed, nothing shipped, tree byte-identical at departure) -> fix round (the 14 rows appended verbatim)
-> controller re-verification (17 arms re-run independently: every arm `occ=1`, `landed=true`, `restored=true`,
and each killed **exactly one row -- its intended witness** (`1 failed, 38 passed`), no collateral). The Step
fences above are now the shipped files, byte for byte, including the D3 pagination partial.

**The five pre-authorised deviations.**

- **D1** -- the plan's first row could not pass as written: the talking contact had null `last_inbound_at`, so
  nothing on the page could say `window open`. Fixed in the fixture (`'last_inbound_at' => now()`), not in the model.
- **D2** -- `use Illuminate\View\Factory;` deleted from the controller fence: unused, sole occurrence (Task 10's
  `Rule` import, same shape).
- **D3** -- `links()` -> `links('pagination::etp')` plus the ~15-line partial fenced above. The framework default
  doubles its text and depends on Tailwind, which this app does not ship. Length-aware ("Showing X to Y of Z",
  Previous/Next), because the pagination rows depend on the next-page link existing.
- **D4** -- `->with('contact')` dropped from the thread query: the view never reads `$m->contact`. The reviewer
  re-graded it (O11): dead weight, deviation stands.
- **D5 (M-A/M-B)** -- both M-A arms killable (`paginate(31)`, `withQueryString()` deleted -> row 16 dies); all
  three M-B arms killed (raw-order deletion and `asc` flip -> row 17; `orderByDesc('id')` deletion -> row 18,
  plus row 16 incidentally). The rows shipped; none was a false witness.

**The headline hole (A1).** Moving both `/inbox` routes out of the `auth` group left **253/614 green**: every
`InboxViewTest` row acts as a signed-in user, and `AuthTest`'s guest row targets `/` only. Over real HTTP the
shipped state bounces guests (review P5: 302 -> `/login` on all five guest GETs), but no row pinned it -- a
regression exposing the whole staff inbox to anonymous visitors would have shipped silently. Now
`test_a_guest_is_bounced_off_the_list_and_the_thread` kills it (`auth()->logout()` undoes the class setUp).

**The 14 witnesses the fix round shipped** (each verified twice: reviewer's targeted run, then the controller's
independent re-run; full PHP in `task-11-review.md` §3.2, run records in `t11rev/runs_witness.jsonl` and
`t11fix/runs.jsonl`):

| row | killing arm(s) |
| --- | --- |
| `test_a_guest_is_bounced_off_the_list_and_the_thread` | A1 (routes out of `auth`) |
| `test_a_thread_lists_oldest_message_first_even_when_insertion_is_scrambled` | A3b (`->reorder()->orderByDesc('sent_at')`) |
| `test_a_message_body_is_rendered_escaped` | A4 (body `{!! !!}`) |
| `test_the_thread_header_and_variables_input_escape_the_profile_name` | A5 (h2 raw) + X1 (variables input raw) |
| `test_the_contact_list_escapes_the_display_name` | A6 (list name raw) |
| `test_a_never_replied_contact_sorts_by_its_creation_not_below_active_ones` | A12 (coalesce -> `last_inbound_at` only) |
| `test_the_three_thread_forms_post_to_the_task_12_urls` | A16 (any action URL drift) |
| `test_pagination_ends_are_spans_not_empty_links` | X3 (`onFirstPage()` -> false) + X4 (`hasMorePages()` -> true) |
| `test_an_inbound_bubble_carries_its_timestamp` | X5 (inbound timestamp block deleted) |
| `test_the_old_reply_is_escaped_when_it_is_echoed` | X6 (`old('body')` raw) |
| `test_a_plain_text_bubble_does_not_claim_to_be_a_template` | X7 (template `@if` -> true) |
| `test_an_empty_address_book_prints_no_showing_line` | X8 (`hasPages()` -> true) |
| `test_a_nameless_contact_falls_back_to_its_number_in_both_views` | X9 (list raw `profile_name`) + X10 (h2 raw `profile_name`) |
| `test_the_number_column_escapes_the_phone` | X2 (phone raw; defence-in-depth -- every write path digits-only's it) |

**Green arms with written dispositions, deliberately no rows:**

- **A7 (LIKE wildcards unescaped)** -- measurement, not mutation: `q=%` lists everyone, `q=_` everyone,
  `q=o%r` filters. Staff-only read-only box over data staff already see in full; wildcards widen a search,
  they leak nothing. A row would pin an accident of LIKE semantics (R48-R51's pass-for-the-wrong-reason shape).
  Literal-`%` search would be a feature decision (escape + `ESCAPE` clause), not a bug fix.
- **A8 (`when($q !== '')` -> `when(true)`)** -- individually un-killable: `LIKE '%%'` matches every row anyway
  (`phone` NOT NULL). Blank-query behaviour jointly witnessed (row 19 + TrimStrings + guard). Kept: short-circuits
  two pointless LIKE scans per empty search.
- **A20 (`colspan="5"` -> `"4"`)** -- cosmetic, no contract. Recorded.
- **B1 (search closure flattened)** -- green as shipped, but the reviewer's probe controller showed the closure
  IS load-bearing once a third `where` joins the query (flat SQL then leaks opted-out contacts into results).
  Keep the closure; row 2's `assertDontSee` witnesses today's grouping.
- **R11 (`trim` deleted)** -- green: the web group's `TrimStrings` + `ConvertEmptyStringsToNull` trim/null the
  query input before the controller ever reads it (mechanism measured at the Symfony layer). Individually
  redundant, jointly load-bearing, kept (R51 X01 family).

**Refuted predictions -- recorded because a prediction is not a witness (R51's lesson, applied to the brief itself):**

1. The review instructions predicted **A12 kills row 16**. Measured **GREEN**: row 16's 31 contacts all have
   null activity and tie on `created_at`, so both orderings fall through to the id tie-break. The hole is real
   and founder-visible: P4 (SELECT-only on the demo MariaDB) shows never-replied Owen/Luis at the TOP of the
   real list under the shipped coalesce and at the BOTTOM under the mutant. sqlite and MariaDB agree on
   NULLs-last under DESC (both measured). W-A12 now pins it.
2. The instructions' **A3 arm is behaviourally inert**: an `orderByDesc` added after the relation's `orderBy`
   yields `order by sent_at asc, sent_at desc` -- the first key wins (SQL measured via `toSql()`). General
   Eloquent fact: appending an order to a relation-ordered query does not override it; `->reorder()` does.
   A3b is the honest arm, and it is an owned-path arm, so the thread-order row ships with a runnable witness
   (the relation's `orderBy` deletion remains a closed-code arm, R50 V1 shape).
3. The reviewer's own A14 prediction missed (predicted f1 #2, measured f2 #2+#16): row 16 doubles as a search
   witness. Recorded per the discipline.

**Task 12 debt (review §4, carried into its brief):** real POST rows must feed the `body`/`window`/`template`
error bags (row 25 proves only the view CONSUMES a seeded session); guest-bounce row per POST route; opt-out
POST observables (`opted_out_at` + `opt_out_source='manual'` + header/badge flip + idempotent second POST);
URLs must match the W-A16 actions; POST rows must NOT be named or counted as CSRF-enforcement witnesses
(`VerifyCsrfToken` skips under `runningUnitTests()`). **R50 carries:** V1 (layout logout `@csrf`) is now
witnessed by row 8's rendered-page observable (narrow closed-code arm disclosed); V7 (csrf-token meta tag)
stays with Task 13.

**Not verified (review §7, stated as such):** authed pages over real HTTP (no login POST permitted that round);
CSRF enforcement itself; the two closed-code arms; non-ASCII LIKE divergence (collations measured, accented
fixture not exercised); the thread relation's `sent_at` tie-break (zero ties in demo data and no fixture).

Counts after R52: baseline **228 passed (525 assertions)** -> implementer **253 (614)** -> fix round
**267 passed (659 assertions)**, of which `tests/Feature/InboxViewTest.php` is **39 rows / 134 assertions**
(the plan's 5, the brief's 20, R52's 14). Five source files unchanged through review and fix
(`InboxController.php addc0f93/1340`, `contacts.blade.php a99516a4/1245`, `thread.blade.php 1427c157/2876`,
`etp.blade.php c2357edd/549`, `routes/web.php 8a451184/1388`); `InboxViewTest.php` 16272 -> **23388 bytes,
sha8 `4eca0d0f`**. All LF-only.
---

## Task 12: Reply, template send, manual opt-out

**Deliverable:** staff can reply while the window is open, send an approved template when it is not, and silence a contact from the thread. A closed window is refused before the API is called, and nothing at all can be sent to an opted-out contact — enforced by a gate in `WhatsAppSender` itself, so every present and future caller inherits the refusal.

**Consumes:** `WhatsAppSender` (Task 6), `WaContact::windowOpen()` / `optOut()` (Task 2), views from Task 11.
**Produces:** `POST /inbox/{contact}/reply`, `POST /inbox/{contact}/template`, `POST /inbox/{contact}/opt-out` (names `inbox.reply` / `inbox.template` / `inbox.optout`); `App\Services\SuppressedContactException`; `sendText(string $phone, string $text, bool $autoReply = false, bool $allowSuppressed = false): WaMessage` and `sendTemplate(string $phone, string $name, string $lang, array $variables = [], bool $autoReply = false, bool $allowSuppressed = false): WaMessage`.

**R53 ruling — the gate lives in the sender (spec §14 amended).** The pre-flight measured that
`post()` ALWAYS writes a row (FAILED local / FAILED network / FAILED rejected / SENT), so a gate
that "refuses" by returning a FAILED row would break the four refuse-tests' zero-OUT-row counts.
The gate therefore THROWS `SuppressedContactException` before any row write and any HTTP attempt,
reading the contact fresh by the NORMALISED phone (`WaContact::where('phone', ...)->first()`,
never creating). ONE named exemption: the STOP confirmation (`allowSuppressed: true` on that
single AutoResponder call) — the one send the suppression regime owes the contact. The blanket-gate
RED measurement killed **7** named closed-file rows (6 OptOutFlowTest + AckFlowTest::test_opt_out_message_is_not_acknowledged),
all on the confirmation send after `optOut()`; the exemption revived all 7. The AutoResponder
`isOptedOut()` pre-check was DROPPED and the ack send wrapped in catch-and-skip: `ProcessInboundEvent`
has no try/catch and `$tries = 3`, so an escaping throw would retry the job three times, and keeping
the pre-check would make the catch un-witnessable (R51 X01 joint redundancy). Recorded side effect:
an opted-out contact consumes the R46 ack cache key before the gate refuses — harmless, no send,
the key expires with the window. TOCTOU: no transaction or row lock across the network wait; a
concurrent opt-out between gate and send can slip one message. The plan sanctions accepting the
race; accepted and recorded here and in spec §14. Scope honesty: Task 13's importer only records
historical rows and never calls the sender, so "every caller inherits it" binds the controller, the
AutoResponder, and any future Laravel send path — not the importer, and not Task 15's Python bench
(which gets its own fail-closed check against the suppression endpoint).

- [ ] **Step 1: Write the failing test** — `tests/Feature/InboxActionsTest.php` (shipped: 23 rows — the plan's original 8 plus 12 brief witness rows plus 3 R53 fix-round rows):

```php
<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\WaContact;
use App\Models\WaMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class InboxActionsTest extends TestCase
{
    use RefreshDatabase;

    private $contact;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
        config(['whatsapp.token' => 'tok-test', 'whatsapp.phone_id' => '1427706937088002',
                'whatsapp.template_choices' => ['etp_opener_brand', 'etp_welcome_c'],
                'whatsapp.header_images' => ['etp_opener_brand' => 'https://example.test/logo.png']]);
        $this->contact = WaContact::factory()->create(['phone' => '18479043315',
            'profile_name' => 'Jane Tester', 'last_inbound_at' => now()->subHour()]);
        // R41: the first draft installed Http::fake(['*' => ... wamid.STAFF1 ...]) here, which shadowed
        // the two in-test fakes below and made preventStrayRequests() inert for every request in the class
        // (a '*' stub always matches, and the stray guard only fires when nothing matched). No stub here
        // now; each sending test brings its own, and an unstubbed request fails the test.
        Http::preventStrayRequests();
    }

    public function test_reply_inside_the_window_is_sent_and_stored()
    {
        Http::fake(['*' => Http::response(['messages' => [['id' => 'wamid.STAFF1']]], 200)]);
        $this->post('/inbox/' . $this->contact->id . '/reply', ['body' => 'Happy to help.'])
            ->assertRedirect();
        $m = WaMessage::where('meta_id', 'wamid.STAFF1')->firstOrFail();
        $this->assertSame(WaMessage::OUT, $m->direction);
        $this->assertFalse($m->auto_reply);
        $this->assertSame('Happy to help.', $m->body);
    }

    public function test_reply_outside_the_window_is_refused_locally_without_calling_graph()
    {
        $this->contact->update(['last_inbound_at' => now()->subHours(30)]);
        // No Http::fake() and no in-test preventStrayRequests(): setUp's guard is what makes the name
        // true. If the window check broke, the controller would attempt a send, nothing would be stubbed,
        // and this test would fail with StrayRequestException instead of quietly passing on a count.
        $this->post('/inbox/' . $this->contact->id . '/reply', ['body' => 'late'])
            ->assertSessionHasErrors('window');
        $this->assertSame(0, WaMessage::where('direction', WaMessage::OUT)->count());
    }

    public function test_empty_reply_is_rejected()
    {
        $this->post('/inbox/' . $this->contact->id . '/reply', ['body' => '   '])
            ->assertSessionHasErrors('body');
    }

    public function test_template_can_be_sent_with_a_closed_window()
    {
        Http::fake(['*' => Http::response(['messages' => [['id' => 'wamid.STAFF1']]], 200)]);
        $this->contact->update(['last_inbound_at' => now()->subDays(3)]);
        $this->post('/inbox/' . $this->contact->id . '/template',
            ['template' => 'etp_opener_brand', 'language' => 'en', 'variables' => 'Jane'])->assertRedirect();

        Http::assertSent(fn ($r) => $r['type'] === 'template'
            && $r['template']['name'] === 'etp_opener_brand'
            && $r['template']['components'][1]['parameters'][0]['text'] === 'Jane');
        $this->assertSame('etp_opener_brand',
            WaMessage::where('meta_id', 'wamid.STAFF1')->value('template_name'));
    }

    public function test_only_approved_template_names_are_accepted()
    {
        // No stub: an unapproved name must be refused before the sender is reached, and setUp's
        // preventStrayRequests() turns any attempt into a failure rather than a silent 200.
        $this->post('/inbox/' . $this->contact->id . '/template',
            ['template' => 'made_up_template', 'language' => 'en', 'variables' => ''])->assertSessionHasErrors('template');
        $this->assertSame(0, WaMessage::where('direction', WaMessage::OUT)->count());
    }

    public function test_nothing_can_be_sent_to_an_opted_out_contact()
    {
        // No stub for the same reason as the two tests above: suppression is enforced locally, so an
        // attempt to reach Graph here is a failure, not something to be answered.
        $this->contact->optOut('inbound_keyword');

        $this->post('/inbox/' . $this->contact->id . '/reply', ['body' => 'hi'])
            ->assertSessionHasErrors('window');
        $this->post('/inbox/' . $this->contact->id . '/template',
            ['template' => 'etp_welcome_c', 'language' => 'en_US', 'variables' => ''])
            ->assertSessionHasErrors('window');
        $this->assertSame(0, WaMessage::where('direction', WaMessage::OUT)->count());
    }

    public function test_manual_opt_out_stops_the_thread_from_sending()
    {
        $this->post('/inbox/' . $this->contact->id . '/opt-out')->assertRedirect();
        $this->assertTrue($this->contact->fresh()->isOptedOut());
        $this->assertSame('manual', $this->contact->fresh()->opt_out_source);
        // The name promises more than the state change above proves, so the send attempt is part of the
        // test: an opted-out thread must be refused before the sender runs. Without these two lines a
        // regression that recorded the opt-out but kept sending would still pass.
        $this->post('/inbox/' . $this->contact->id . '/reply', ['body' => 'after'])
            ->assertSessionHasErrors('window');
        $this->assertSame(0, WaMessage::where('direction', WaMessage::OUT)->count());
    }

    public function test_a_guest_cannot_post_a_reply()
    {
        auth()->logout();
        $this->post('/inbox/' . $this->contact->id . '/reply', ['body' => 'x'])->assertRedirect('/login');
    }

    // ---- witness rows owed by the brief (each names the arm it kills) ----

    // W-GUEST2: the plan covers reply only; guests must bounce off template and opt-out too.
    public function test_a_guest_cannot_post_a_template_or_an_opt_out()
    {
        auth()->logout();
        $this->post('/inbox/' . $this->contact->id . '/template',
            ['template' => 'etp_opener_brand', 'language' => 'en', 'variables' => ''])->assertRedirect('/login');
        $this->post('/inbox/' . $this->contact->id . '/opt-out')->assertRedirect('/login');
        $this->assertSame(0, WaMessage::where('direction', WaMessage::OUT)->count());
        $this->assertFalse($this->contact->fresh()->isOptedOut());
    }

    // W-FLASH: kills the arm that deletes the @if (session('result')) block from thread.blade.php.
    // Task 11 row 25 seeds errors/old input only -- it never exercises session('result').
    public function test_a_successful_reply_flashes_sent_inside_the_ok_wrapper()
    {
        Http::fake(['*' => Http::response(['messages' => [['id' => 'wamid.STAFF1']]], 200)]);
        $response = $this->from('/inbox/' . $this->contact->id)
            ->post('/inbox/' . $this->contact->id . '/reply', ['body' => 'Happy to help.']);
        $this->followRedirects($response)
            ->assertSee('Sent.')
            ->assertSee('<div class="ok">Sent.</div>', false);
    }

    // W-REFERER: kills the arm that replaces back() with redirect('/').
    public function test_a_reply_redirects_back_to_the_thread_it_came_from()
    {
        Http::fake(['*' => Http::response(['messages' => [['id' => 'wamid.STAFF1']]], 200)]);
        $this->from('/inbox/' . $this->contact->id)
            ->post('/inbox/' . $this->contact->id . '/reply', ['body' => 'Hi'])
            ->assertRedirect('/inbox/' . $this->contact->id);
    }

    // W-RERENDER: the request side of Task 11 row 25 -- a real refused POST feeding withErrors +
    // withInput + old(). Kills the arm that deletes ->withInput() from the window-refusal return.
    public function test_a_refused_reply_re_renders_the_thread_with_the_error_and_the_old_body()
    {
        $this->contact->update(['last_inbound_at' => now()->subHours(30)]);
        $response = $this->from('/inbox/' . $this->contact->id)
            ->post('/inbox/' . $this->contact->id . '/reply', ['body' => 'keep me']);
        $this->followRedirects($response)
            ->assertSee('The 24-hour reply window is closed. Send an approved template instead.')
            ->assertSee('<textarea name="body">keep me</textarea>', false);
        $this->assertSame(0, WaMessage::where('direction', WaMessage::OUT)->count());
    }

    // W-WIDTHS: one arm per max: rule -- deleting max:4096 / max:500 / max:10 kills its case.
    // No stub: an over-wide value must be refused before the sender, and the stray guard makes
    // any attempt a failure rather than a silent send.
    public static function overWideProvider(): array
    {
        return [
            'body 4097' => ['reply', ['body' => str_repeat('x', 4097)], 'body'],
            'variables 501' => ['template',
                ['template' => 'etp_opener_brand', 'language' => 'en', 'variables' => str_repeat('x', 501)],
                'variables'],
            'language 11' => ['template',
                ['template' => 'etp_opener_brand', 'language' => str_repeat('x', 11), 'variables' => ''],
                'language'],
        ];
    }

    #[DataProvider('overWideProvider')]
    public function test_over_wide_input_is_rejected_without_sending(string $action, array $payload, string $field)
    {
        $this->post('/inbox/' . $this->contact->id . '/' . $action, $payload)
            ->assertSessionHasErrors($field);
        $this->assertSame(0, WaMessage::where('direction', WaMessage::OUT)->count());
    }

    // W-COMMA: kills the arm that deletes array_filter from the variables line. The plan's row
    // covers only the clean single value; a trailing or leading comma must not add an empty parameter.
    public static function commaVariablesProvider(): array
    {
        return ['trailing comma' => ['Jane, '], 'leading comma' => [',Jane']];
    }

    #[DataProvider('commaVariablesProvider')]
    public function test_comma_padded_variables_produce_exactly_one_body_parameter(string $variables)
    {
        Http::fake(['*' => Http::response(['messages' => [['id' => 'wamid.STAFF1']]], 200)]);
        $this->post('/inbox/' . $this->contact->id . '/template',
            ['template' => 'etp_opener_brand', 'language' => 'en', 'variables' => $variables])->assertRedirect();

        Http::assertSentCount(1);
        Http::assertSent(fn ($r) => $r['type'] === 'template'
            && count($r['template']['components'][1]['parameters']) === 1
            && $r['template']['components'][1]['parameters'][0]['text'] === 'Jane');
    }

    // W-IDEM: pins the observable idempotency of optOut()'s early-return (closed code): a second
    // manual opt-out must not move opted_out_at, and both views must show the silenced state.
    public function test_a_second_manual_opt_out_does_not_move_the_timestamp()
    {
        $this->post('/inbox/' . $this->contact->id . '/opt-out')->assertRedirect();
        $first = $this->contact->fresh()->opted_out_at;
        $this->assertNotNull($first);

        $this->post('/inbox/' . $this->contact->id . '/opt-out')->assertRedirect();
        $second = $this->contact->fresh()->opted_out_at;
        $this->assertSame($first->format('Y-m-d H:i:s.u'), $second->format('Y-m-d H:i:s.u'));

        $this->get('/inbox/' . $this->contact->id)->assertSee('OPTED OUT (manual');
        $this->get('/inbox')->assertSee('opted out');
    }

    // W-REFUSED: kills the arm that flips the FAILED-flash ternary. The stub is the exact shape
    // post()'s rejected branch parses: error.code, error.error_data.details, error.message.
    public function test_a_refused_reply_flashes_meta_refused_and_stores_the_failed_row()
    {
        Http::fake(['*' => Http::response(['error' => [
            'message' => 'Re-engagement message', 'code' => 131030,
            'error_data' => ['details' => 'Message undeliverable'],
        ]], 400)]);
        $response = $this->from('/inbox/' . $this->contact->id)
            ->post('/inbox/' . $this->contact->id . '/reply', ['body' => 'late note']);
        $this->followRedirects($response)
            ->assertSee('Meta refused')
            ->assertSee('Message undeliverable');

        $m = WaMessage::where('direction', WaMessage::OUT)->firstOrFail();
        $this->assertSame(WaMessage::FAILED, $m->status);
        $this->assertSame('131030', $m->error_code);
    }

    // W-OPTFLASH (R53): the opt-out flash copy had no witness -- review arm V4 changed the string
    // and the whole suite stayed green. Follow the real redirect and pin the exact copy + wrapper.
    public function test_a_manual_opt_out_flashes_the_confirmation_copy()
    {
        $response = $this->from('/inbox/' . $this->contact->id)
            ->post('/inbox/' . $this->contact->id . '/opt-out');
        $this->followRedirects($response)
            ->assertSee('<div class="ok">Marked opted out. No further messages will be sent to this number.</div>', false);
    }

    // W-TPLFLASH (R53): the template-success flash copy had no witness -- review arm V5 changed
    // 'Template sent.' and the whole suite stayed green.
    public function test_a_successful_template_flashes_template_sent_inside_the_ok_wrapper()
    {
        Http::fake(['*' => Http::response(['messages' => [['id' => 'wamid.STAFF1']]], 200)]);
        $response = $this->from('/inbox/' . $this->contact->id)
            ->post('/inbox/' . $this->contact->id . '/template',
                ['template' => 'etp_welcome_c', 'language' => 'en_US', 'variables' => '']);
        $this->followRedirects($response)
            ->assertSee('<div class="ok">Template sent.</div>', false);
    }

    // W-NAMES (R53): the route NAMES had no witness -- review arm V8 renamed inbox.reply and the
    // suite stayed green because every POST row uses literal URLs. route() must resolve all three
    // names to the exact URLs the thread forms post to.
    public function test_the_three_action_routes_resolve_by_name_to_the_form_urls()
    {
        $id = $this->contact->id;
        $this->assertSame(url('/inbox/' . $id . '/reply'), route('inbox.reply', $id));
        $this->assertSame(url('/inbox/' . $id . '/template'), route('inbox.template', $id));
        $this->assertSame(url('/inbox/' . $id . '/opt-out'), route('inbox.optout', $id));
    }

    public function test_a_refused_template_flashes_meta_refused_with_the_code_and_stores_the_failed_row()
    {
        Http::fake(['*' => Http::response(['error' => [
            'message' => 'Re-engagement message', 'code' => 131030,
            'error_data' => ['details' => 'Message undeliverable'],
        ]], 400)]);
        $response = $this->from('/inbox/' . $this->contact->id)
            ->post('/inbox/' . $this->contact->id . '/template',
                ['template' => 'etp_welcome_c', 'language' => 'en_US', 'variables' => '']);
        $this->followRedirects($response)
            ->assertSee('Meta refused (')
            ->assertSee('131030')
            ->assertSee('Message undeliverable');

        $m = WaMessage::where('direction', WaMessage::OUT)->firstOrFail();
        $this->assertSame(WaMessage::FAILED, $m->status);
        $this->assertSame('131030', $m->error_code);
    }
}
```

- [ ] **Step 2: Run it** — measured RED: `8 failed (8 assertions)`. The plan's original prediction
"FAIL 405 for all three POSTs" was wrong in the code: this Laravel build answers an unregistered POST
under a GET-only path with **404**, so the four redirect-expecting rows died on 404 and the four
refuse-rows died on `Session is missing expected key [errors]`. Reported as measured, not predicted.

- [ ] **Step 3: Add the three actions to `InboxController`** — shipped file (D2: window check inline
in `reply()` only; `blockReason()` never created; no `isOptedOut()` branch — opt-out refusal surfaces
only via the caught `SuppressedContactException` translated into the `window` error bag. D3: imports
are exactly `WaContact`, `WaMessage`, `SuppressedContactException`, `WhatsAppSender`, `Request`,
`Rule` — the original step's `use Illuminate\Support\Carbon;` was unused and dropped):

```php
<?php

namespace App\Http\Controllers;

use App\Models\WaContact;
use App\Models\WaMessage;
use App\Services\SuppressedContactException;
use App\Services\WhatsAppSender;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class InboxController extends Controller
{
    public function contacts(Request $request)
    {
        $q = trim((string) $request->query('q', ''));

        $contacts = WaContact::query()
            ->when($q !== '', function ($query) use ($q) {
                $query->where(function ($inner) use ($q) {
                    $inner->where('phone', 'like', '%' . $q . '%')
                          ->orWhere('profile_name', 'like', '%' . $q . '%');
                });
            })
            ->withCount(['messages as inbound_count' => fn ($q2) => $q2->where('direction', WaMessage::IN)])
            ->withCount(['messages as outbound_count' => fn ($q2) => $q2->where('direction', WaMessage::OUT)])
            ->orderByRaw('coalesce(last_inbound_at, created_at) desc')
            ->orderByDesc('id')
            ->paginate(30)
            ->withQueryString();

        return view('inbox.contacts', ['contacts' => $contacts, 'q' => $q]);
    }

    public function thread(WaContact $contact)
    {
        return view('inbox.thread', [
            'contact' => $contact,
            'messages' => $contact->messages()->get(),
            'choices' => config('whatsapp.template_choices'),
        ]);
    }

    public function reply(Request $request, WaContact $contact, WhatsAppSender $sender)
    {
        $data = $request->validate(['body' => ['required', 'string', 'max:4096']]);

        if (!$contact->windowOpen()) {
            return back()->withInput()->withErrors(['window' => 'The 24-hour reply window is closed. Send an approved template instead.']);
        }

        try {
            $message = $sender->sendText($contact->phone, trim($data['body']));
        } catch (SuppressedContactException $e) {
            return back()->withInput()->withErrors(['window' => $e->getMessage()]);
        }

        return back()->with('result', $message->status === WaMessage::FAILED
            ? 'Meta refused: ' . $message->error_title
            : 'Sent.');
    }

    public function template(Request $request, WaContact $contact, WhatsAppSender $sender)
    {
        $data = $request->validate([
            'template' => ['required', 'string', Rule::in(config('whatsapp.template_choices'))],
            'language' => ['required', 'string', 'max:10'],
            'variables' => ['nullable', 'string', 'max:500'],
        ]);

        $variables = array_values(array_filter(array_map('trim',
            explode(',', (string) ($data['variables'] ?? '')))));

        try {
            $message = $sender->sendTemplate($contact->phone, $data['template'], $data['language'], $variables);
        } catch (SuppressedContactException $e) {
            return back()->withInput()->withErrors(['window' => $e->getMessage()]);
        }

        return back()->with('result', $message->status === WaMessage::FAILED
            ? 'Meta refused (' . $message->error_code . '): ' . $message->error_title
            : 'Template sent.');
    }

    public function optOut(WaContact $contact)
    {
        $contact->optOut('manual');

        return back()->with('result', 'Marked opted out. No further messages will be sent to this number.');
    }
}
```

- [ ] **Step 4: Build the suppression gate (D1)** — create `app/Services/SuppressedContactException.php`:

```php
<?php

namespace App\Services;

class SuppressedContactException extends \RuntimeException
{
    public function __construct(string $message = 'This contact has opted out. Nothing may be sent to this number.')
    {
        parent::__construct($message);
    }
}
```

Gate in `WhatsAppSender` (shipped file — the gate sits in `post()` after phone normalisation, before
`fromPhone()`, any row write, and any HTTP attempt; `allowSuppressed` threaded through both public
methods). Build it FIRST without the exemption and run the closed OptOutFlowTest/AckFlowTest: the
7-row kill listed in the R53 ruling above is the measurement that justifies the exemption; then add
the flag and watch all 7 revive:

```php
<?php

namespace App\Services;

use App\Models\WaContact;
use App\Models\WaMessage;
use Illuminate\Http\Client\Factory;

class WhatsAppSender
{
    public function __construct(private Factory $http)
    {
    }

    public function sendText(string $phone, string $text, bool $autoReply = false,
                             bool $allowSuppressed = false): WaMessage
    {
        return $this->post(
            ['messaging_product' => 'whatsapp', 'to' => $phone, 'type' => 'text',
             'text' => ['preview_url' => false, 'body' => $text]],
            $phone,
            ['direction' => WaMessage::OUT, 'type' => 'text', 'body' => $text,
             'auto_reply' => $autoReply],
            $allowSuppressed,
        );
    }

    public function sendTemplate(string $phone, string $name, string $lang,
                                 array $variables = [], bool $autoReply = false,
                                 bool $allowSuppressed = false): WaMessage
    {
        $components = [];
        $image = (string) (config('whatsapp.header_images')[$name] ?? '');
        if ($image !== '') {
            $components[] = ['type' => 'header', 'parameters' => [
                ['type' => 'image', 'image' => ['link' => $image]]]];
        }
        if ($variables !== []) {
            $components[] = ['type' => 'body', 'parameters' => array_map(
                fn ($v) => ['type' => 'text', 'text' => (string) $v], $variables)];
        }

        $template = ['name' => $name, 'language' => ['code' => $lang]];
        if ($components !== []) {
            $template['components'] = $components;
        }

        return $this->post(
            ['messaging_product' => 'whatsapp', 'to' => $phone, 'type' => 'template',
             'template' => $template],
            $phone,
            ['direction' => WaMessage::OUT, 'type' => 'template', 'template_name' => $name,
             'template_lang' => $lang, 'auto_reply' => $autoReply],
            $allowSuppressed,
        );
    }

    private static function str(mixed $v, int $max): string
    {
        $s = match (true) {
            is_string($v) => $v,
            is_int($v) || is_float($v) => (string) $v,
            default => '',
        };

        return mb_substr($s, 0, $max);
    }

    private function post(array $payload, string $phone, array $attrs,
                          bool $allowSuppressed = false): WaMessage
    {
        // R35: the contact row and the payload have to agree. fromPhone() normalises internally, so
        // the first draft stored digits while sending the caller's punctuation -- Meta answers that
        // with a 400 for a contact we filed cleanly. Normalising once, here, is the R32 rule pointed
        // at the outbound direction.
        $phone = WaContact::digitsOnly($phone);
        $payload['to'] = $phone;

        // D1: the suppression gate. Fresh read by the NORMALISED phone, no contact creation, no row
        // write and no HTTP attempt before it. Throws instead of recording a FAILED row because the
        // refuse-tests assert zero OUT rows. allowSuppressed is the ONE exemption: the opt-out
        // confirmation the AutoResponder owes the contact after a STOP. TOCTOU: a concurrent opt-out
        // between this read and the send can slip one message; the plan sanctions accepting the race
        // (recorded in the ledger).
        $gateContact = WaContact::where('phone', $phone)->first();
        if (!$allowSuppressed && $gateContact?->isOptedOut()) {
            throw new SuppressedContactException();
        }

        $contact = WaContact::fromPhone($phone);
        $token = (string) config('whatsapp.token', '');
        $phoneId = (string) config('whatsapp.phone_id', '');

        // R35: an unconfigured phone number id is the same kind of "we do not know who is sending" as
        // an unconfigured token, and without this guard the app still makes an authenticated HTTPS
        // request to .../v20.0//messages. Nothing goes out when either is missing.
        if ($token === '' || $phoneId === '') {
            return WaMessage::record($attrs + [
                'wa_contact_id' => $contact->id, 'meta_id' => null,
                'status' => WaMessage::FAILED, 'status_at' => now(),
                'error_code' => 'local',
                'error_title' => $token === '' ? 'no access token configured' : 'no phone number id configured',
                'sent_at' => now(), 'raw' => $payload,
            ]);
        }

        $url = rtrim((string) config('whatsapp.api_base'), '/') . '/' . $phoneId . '/messages';

        try {
            // R33: ->timeout() is the option. post() is declared post(string $url, $data = [])
            // (vendor PendingRequest:888), so a third argument is accepted by PHP and thrown away --
            // the first draft looked like it waited 20 seconds and actually waited Laravel's own
            // default of 30 (vendor PendingRequest:269).
            $res = $this->http->withToken($token)->asJson()->timeout(20)->post($url, $payload);
        } catch (\Throwable $e) {
            return WaMessage::record($attrs + [
                'wa_contact_id' => $contact->id, 'meta_id' => null,
                'status' => WaMessage::FAILED, 'status_at' => now(),
                'error_code' => 'network', 'error_title' => self::str($e->getMessage(), 255),
                'sent_at' => now(), 'raw' => $payload,
            ]);
        }

        // R34: Meta's response is external input too -- in shape, in presence and in range -- and
        // every value below lands in a bounded column. data_get() is the null-safe reader (measured:
        // a scalar `messages`, a null body and a missing key all return null with no warning), and
        // str() is the width. `(string) ['a']` would have emitted `Warning: Array to string
        // conversion`, which HandleExceptions::handleError (vendor:75-77) turns into an ErrorException
        // in every environment -- i.e. the sender throwing at its caller, which the deliverable
        // forbids -- and a 400-character `error.message` into error_title(255) is the 1406 that
        // sqlite lets the suite forget about (the round-1 review measured both widths on MariaDB).
        $json = $res->json() ?? [];
        $wamid = self::str(data_get($json, 'messages.0.id'), 128);

        if ($res->failed() || $wamid === '') {
            $code = self::str(data_get($json, 'error.code'), 12);
            $title = self::str(data_get($json, 'error.error_data.details'), 255)
                ?: self::str(data_get($json, 'error.message'), 255);

            return WaMessage::record($attrs + [
                'wa_contact_id' => $contact->id, 'meta_id' => null,
                'status' => WaMessage::FAILED, 'status_at' => now(),
                'error_code' => $code === '' ? (string) $res->status() : $code,
                'error_title' => $title === '' ? 'send rejected' : $title,
                'sent_at' => now(), 'raw' => $payload,
            ]);
        }

        return WaMessage::record($attrs + [
            'wa_contact_id' => $contact->id, 'meta_id' => $wamid,
            'status' => WaMessage::SENT, 'status_at' => now(),
            'sent_at' => now(), 'raw' => $payload,
        ]);
    }
}
```

`AutoResponder` (shipped file — STOP confirmation carries `allowSuppressed: true`; the old
`isOptedOut()` pre-check is gone; the ack send is wrapped in catch-and-skip):

```php
<?php

namespace App\Services;

use App\Models\WaContact;
use App\Models\WaMessage;
use App\Support\OptOutKeywords;
use Illuminate\Support\Facades\Cache;

class AutoResponder
{
    public function __construct(private WhatsAppSender $sender)
    {
    }

    public function handle(WaMessage $in, ?string $buttonId = null): void
    {
        if ($in->direction !== WaMessage::IN) {
            return;
        }

        /** @var WaContact $contact */
        $contact = $in->contact;

        if (OptOutKeywords::matches($in->body, $buttonId)) {
            $wasSilenced = $contact->isOptedOut();
            $contact->optOut('inbound_keyword');
            $text = (string) config('whatsapp.opt_out_text', '');
            if (!$wasSilenced && $text !== '') {
                // D1: the ONE exemption to the sender's suppression gate -- the confirmation the
                // suppression regime owes the contact after their own STOP.
                $this->sender->sendText($contact->phone, $text, autoReply: true, allowSuppressed: true);
            }

            return;
        }

        $this->acknowledge($contact, $in);
    }

    private function acknowledge(WaContact $contact, WaMessage $in): void
    {
        $text = (string) config('whatsapp.ack_text', '');
        if ($text === '') {
            return;
        }

        $hours = (int) config('whatsapp.window_hours', 24);
        $at = $in->sent_at;

        // R46: exclude the row this job just wrote. ProcessInboundEvent stores the inbound before it
        // calls the responder, so without this the window query finds the message's own row and no ack
        // is ever sent. Measured, not reasoned about.
        $alreadyTalking = WaMessage::where('wa_contact_id', $contact->id)
            ->where('direction', WaMessage::IN)
            ->where('id', '!=', $in->id)
            ->where('sent_at', '>=', $at->copy()->subHours($hours))
            ->where('sent_at', '<', $at->copy()->addSecond())
            ->exists();
        if ($alreadyTalking) {
            return;
        }

        // The race guard. Atomic on the production store, not on the test store -- see R46.
        if (!Cache::add('wa-ack-' . $contact->id, 1, now()->addHours($hours))) {
            return;
        }

        // D1/F5b: the sender's gate is the single enforcement point for suppression; this catch is the
        // single translation point. The pre-check that used to sit in handle() is gone -- keeping it
        // would make this catch un-witnessable (R51 X01 joint redundancy). ProcessInboundEvent has no
        // try/catch and $tries = 3, so an escaping throw would retry the job three times. Known side
        // effect: an opted-out contact consumes the R46 cache key above before the gate refuses;
        // harmless, no send, and the key expires with the window.
        try {
            $this->sender->sendText($contact->phone, $text, autoReply: true);
        } catch (SuppressedContactException) {
            return;
        }
    }
}
```

- [ ] **Step 5: Register the POST routes** inside the `auth` group in `routes/web.php` (shipped file):

```php
<?php

use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\InboxController;
use App\Http\Controllers\MachineController;
use App\Http\Controllers\WaWebhookController;
use Illuminate\Support\Facades\Route;

Route::get('/wa/webhook', [WaWebhookController::class, 'verify'])
    ->withoutMiddleware('web')
    ->name('wa.webhook.verify');
Route::post('/wa/webhook', [WaWebhookController::class, 'ingest'])
    ->withoutMiddleware('web')
    ->name('wa.webhook.ingest');

Route::middleware('wa.bearer')->group(function () {
    Route::get('/wa/suppressions', [MachineController::class, 'suppressions'])
        ->withoutMiddleware('web')
        ->name('wa.suppressions');
    Route::post('/inbound/record-send', [MachineController::class, 'recordSend'])
        ->withoutMiddleware('web')
        ->name('wa.record_send');
});

Route::get('/login', [LoginController::class, 'show'])->middleware('guest')->name('login');
Route::post('/login', [LoginController::class, 'store'])->middleware('throttle:5,1');
Route::post('/logout', [LoginController::class, 'destroy'])->name('logout');

Route::middleware('auth')->group(function () {
    Route::get('/', fn () => redirect('/inbox'))->name('home');
    Route::get('/inbox', [InboxController::class, 'contacts'])->name('inbox');
    Route::get('/inbox/{contact}', [InboxController::class, 'thread'])->name('inbox.thread');
    Route::post('/inbox/{contact}/reply', [InboxController::class, 'reply'])->name('inbox.reply');
    Route::post('/inbox/{contact}/template', [InboxController::class, 'template'])->name('inbox.template');
    Route::post('/inbox/{contact}/opt-out', [InboxController::class, 'optOut'])->name('inbox.optout');
});
```

- [ ] **Step 6: Show the flash result in the thread view** — `resources/views/inbox/thread.blade.php`
(shipped file; the `@if (session('result'))` block sits immediately after the `<h2 ...>` line. The
original plan duplicated this step verbatim at two consecutive lines; the duplicate was deleted in
the R53 re-sync):

```blade
@extends('layout')
@section('content')
<p class="muted"><a href="/inbox">All contacts</a></p>
<h2 style="margin:0">{{ $contact->display_name() }} <span class="muted">+{{ $contact->phone }}</span></h2>
@if (session('result'))
  <div class="ok">{{ session('result') }}</div>
@endif
<p class="muted">
  {{ $contact->isOptedOut() ? 'OPTED OUT (' . $contact->opt_out_source . ' ' . $contact->opted_out_at?->toDayDateTimeString() . ')' : ($contact->windowOpen() ? 'reply window open' : 'reply window closed - approved template required') }}
</p>

<div style="background:#fff;border:1px solid var(--line);border-radius:10px;padding:14px;margin:14px 0">
@forelse ($messages as $m)
  <div class="bubble {{ $m->direction === 'in' ? 'in' : 'out' }}">
    @if ($m->direction === 'out')
      <div class="muted" style="font-size:12px">
        {{ $m->sent_at->toDayDateTimeString() }}
        @if ($m->type === 'template') &middot; template {{ $m->template_name }} ({{ $m->template_lang }}) @endif
        @if ($m->status) &middot; {{ $m->status }} @endif
      </div>
    @endif
    {{ $m->body ?? '(' . $m->type . ' message - text not stored here)' }}
    @if ($m->status === 'failed')
      <div class="err">FAILED {{ $m->error_code }}: {{ $m->error_title }}</div>
    @endif
    @if ($m->auto_reply)
      <div class="auto">automatic</div>
    @endif
    @if ($m->direction === 'in')
      <div class="muted" style="font-size:12px">{{ $m->sent_at->toDayDateTimeString() }}</div>
    @endif
  </div>
@empty
  <p class="muted">Nothing here yet.</p>
@endforelse
</div>

<form method="POST" action="/inbox/{{ $contact->id }}/reply">
  @csrf
  <label class="muted">Reply (free-form, needs an open window)</label>
  <textarea name="body">{{ old('body') }}</textarea>
  @error('body')<div class="err">{{ $message }}</div>@enderror
  @foreach ($errors->get('window') as $e) <div class="err">{{ $e }}</div> @endforeach
  <div style="margin-top:8px"><button type="submit">Send reply</button></div>
</form>

<form method="POST" action="/inbox/{{ $contact->id }}/template" style="margin-top:18px">
  @csrf
  <label class="muted">Approved template (works outside the window)</label>
  <div>
    <select name="template">
      @foreach ($choices as $choice) <option value="{{ $choice }}">{{ $choice }}</option> @endforeach
    </select>
    <input name="language" value="en" size="6" placeholder="lang">
    <input name="variables" value="{{ $contact->profile_name }}" size="24" placeholder="body variables, comma separated">
  </div>
  @foreach ($errors->get('template') as $e) <div class="err">{{ $e }}</div> @endforeach
  <div style="margin-top:8px"><button type="submit">Send template</button></div>
</form>

<form method="POST" action="/inbox/{{ $contact->id }}/opt-out" style="margin-top:18px">
  @csrf
  <button type="submit" style="background:#9aa4b2">Mark opted out</button>
  <span class="muted">stops every automatic message to this number</span>
</form>
@endsection
```

- [ ] **Step 7: Sender gate witnesses** — `tests/Feature/WhatsAppSenderTest.php` (shipped file; G1–G3
from the brief, G4 from the R53 fix round — the template-path exemption had no witness until G4):

```php
<?php

namespace Tests\Feature;

use App\Models\WaContact;
use App\Models\WaMessage;
use App\Services\SuppressedContactException;
use App\Services\WhatsAppSender;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WhatsAppSenderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['whatsapp.phone_id' => '1427706937088002', 'whatsapp.token' => 'tok-test']);
        Http::preventStrayRequests();
    }

    public function test_send_text_posts_to_graph_and_records_the_wamid()
    {
        Http::fake(['*' => Http::response(['messages' => [['id' => 'wamid.OUT9']]], 200)]);

        $m = app(WhatsAppSender::class)->sendText('18479043315', 'Hello there');

        $this->assertSame(WaMessage::OUT, $m->direction);
        $this->assertSame(WaMessage::SENT, $m->status);
        $this->assertSame('wamid.OUT9', $m->meta_id);
        $this->assertSame('Hello there', $m->body);
        $this->assertFalse($m->auto_reply);

        Http::assertSent(fn ($r) => $r['type'] === 'text'
            && $r['to'] === '18479043315'
            && $r['text']['body'] === 'Hello there'
            && $r['messaging_product'] === 'whatsapp'
            && $r->url() === 'https://graph.facebook.com/v20.0/1427706937088002/messages');
    }

    public function test_a_400_is_stored_as_failed_with_the_meta_error()
    {
        Http::fake(['*' => Http::response(['error' => [
            'message' => 'Re-engagement message', 'code' => 131030,
            'error_data' => ['details' => 'Message undeliverable'],
        ]], 400)]);

        $m = app(WhatsAppSender::class)->sendText('18479043315', 'Late reply');

        $this->assertSame(WaMessage::FAILED, $m->status);
        $this->assertSame('131030', $m->error_code);
        $this->assertSame('Message undeliverable', $m->error_title);
        $this->assertNull($m->meta_id);
        $this->assertNotNull($m->id, 'a failed send must still be visible in the thread');
    }

    public function test_missing_token_fails_locally_without_calling_graph()
    {
        config(['whatsapp.token' => '']);
        $m = app(WhatsAppSender::class)->sendText('18479043315', 'x');
        $this->assertSame(WaMessage::FAILED, $m->status);
        $this->assertSame('local', $m->error_code);
        $this->assertSame('no access token configured', $m->error_title);
    }

    public function test_template_send_re_attaches_the_approved_header_image()
    {
        config(['whatsapp.header_images' => ['etp_opener_brand' => 'https://example.test/logo.png']]);
        Http::fake(['*' => Http::response(['messages' => [['id' => 'wamid.OUT10']]], 200)]);

        app(WhatsAppSender::class)->sendTemplate('18479043315', 'etp_opener_brand', 'en', ['Sam']);

        Http::assertSent(function ($r) {
            $c = $r['template']['components'];

            return $c[0] === ['type' => 'header', 'parameters' => [[
                'type' => 'image', 'image' => ['link' => 'https://example.test/logo.png']]]]
                && $c[1]['type'] === 'body'
                && $c[1]['parameters'][0]['text'] === 'Sam'
                && $r['template']['language']['code'] === 'en';
        });
        $m = WaMessage::where('meta_id', 'wamid.OUT10')->firstOrFail();
        $this->assertSame('etp_opener_brand', $m->template_name);
        $this->assertSame('en', $m->template_lang);
    }

    public function test_template_without_variables_sends_no_components()
    {
        Http::fake(['*' => Http::response(['messages' => [['id' => 'wamid.OUT11']]], 200)]);
        app(WhatsAppSender::class)->sendTemplate('18479043315', 'etp_welcome_c', 'en_US');
        Http::assertSent(fn ($r) => !array_key_exists('components', $r['template']));
    }

    public function test_a_punctuated_phone_is_stored_and_sent_as_digits()
    {
        // R35: the payload and the contact row must not disagree about who this is.
        Http::fake(['*' => Http::response(['messages' => [['id' => 'wamid.OUT12']]], 200)]);

        $m = app(WhatsAppSender::class)->sendText('+1 (847) 904-3315', 'Hi');

        Http::assertSent(fn ($r) => $r['to'] === '18479043315');
        $this->assertSame('18479043315', $m->contact->phone);
        $this->assertSame('18479043315', $m->raw['to'], 'the stored payload is what was sent');
    }

    public function test_an_unconfigured_phone_id_records_a_local_failure_without_calling_graph()
    {
        // R35: `.../v20.0//messages` is still an authenticated request. Not knowing which number we
        // are has to stop us before the network, exactly as a missing token does.
        config(['whatsapp.phone_id' => '']);
        Http::fake(['*' => Http::response([], 200)]);

        $m = app(WhatsAppSender::class)->sendText('18479043315', 'x');

        Http::assertNothingSent();
        $this->assertSame(WaMessage::FAILED, $m->status);
        $this->assertSame('local', $m->error_code);
        $this->assertSame('no phone number id configured', $m->error_title);
    }

    public function test_a_graph_body_that_is_shaped_wrong_is_recorded_not_thrown()
    {
        // R34: `messages.0.id` as a list is the shape that used to reach a `(string)` cast. There is
        // no id, so it is a failure, and its code falls back to the HTTP status.
        Http::fake(['*' => Http::response(['messages' => [['id' => ['w', 'x']]]], 200)]);

        $m = app(WhatsAppSender::class)->sendText('18479043315', 'x');

        $this->assertSame(WaMessage::FAILED, $m->status);
        $this->assertNull($m->meta_id);
        $this->assertSame('200', $m->error_code);
        $this->assertSame('send rejected', $m->error_title);
    }

    public function test_a_connection_failure_is_recorded_as_network_without_throwing()
    {
        // R38: the network is one of the four outcomes the deliverable names, and until now nothing
        // reached the catch -- every fake answered, and the two config guards return before the
        // request. A cURL error message is unbounded text that lands in error_title(255), so the same
        // reader caps it here as it does Meta's own message.
        Http::fake(['*' => fn () => throw new \Illuminate\Http\Client\ConnectionException(
            str_repeat('x', 400))]);

        $m = app(WhatsAppSender::class)->sendText('18479043315', 'x');

        $this->assertSame(WaMessage::FAILED, $m->status);
        $this->assertSame('network', $m->error_code);
        $this->assertSame(255, mb_strlen($m->error_title));
        $this->assertNull($m->meta_id);
    }

    public function test_error_details_that_are_shaped_wrong_fall_back_to_the_message()
    {
        // R34 again, one branch further down: ?? picks the first operand whenever it is not null, so
        // an error_data.details that arrives as a list was kept, turned into '' by str(), and the
        // readable error.message next to it was never tried. The row said "send rejected" when Meta
        // had actually told us why.
        Http::fake(['*' => Http::response(['error' => [
            'message' => 'Limitation was applied due to poor quality rating',
            'code' => 131056,
            'error_data' => ['details' => ['unexpected', 'shape']],
        ]], 400)]);

        $m = app(WhatsAppSender::class)->sendText('18479043315', 'x');

        $this->assertSame('131056', $m->error_code);
        $this->assertSame('Limitation was applied due to poor quality rating', $m->error_title);
    }

    public function test_error_text_from_graph_is_cut_to_the_column_it_lands_in()
    {
        // R34: error_title is varchar(255) and both Meta's message and cURL's message are unbounded.
        // On sqlite an over-long insert succeeds, which is how the suite would forget this bug, so
        // the assertion is on the stored length -- the part a caller can observe on either engine.
        Http::fake(['*' => Http::response(['error' => [
            'message' => str_repeat('x', 400), 'code' => 131047,
        ]], 400)]);

        $m = app(WhatsAppSender::class)->sendText('18479043315', 'x');

        $this->assertSame(WaMessage::FAILED, $m->status);
        $this->assertSame('131047', $m->error_code);
        $this->assertSame(255, mb_strlen($m->error_title));
    }

    // ---- D1 gate witnesses (G1-G3). The rows above are the witness that a non-opted-out
    // contact is unaffected; they are not duplicated here. ----

    // G1: kills "gate deleted" (a send happens -> the stray guard fires) and "gate moved after
    // record()" (a row exists). No stub: any HTTP attempt is a failure, not an answer.
    public function test_send_text_to_an_opted_out_contact_throws_before_any_row_or_request()
    {
        WaContact::factory()->create(['phone' => '18479043315'])->optOut('manual');

        try {
            app(WhatsAppSender::class)->sendText('18479043315', 'x');
            $this->fail('SuppressedContactException was not thrown');
        } catch (SuppressedContactException $e) {
            $this->assertSame('This contact has opted out. Nothing may be sent to this number.',
                $e->getMessage());
        }

        $this->assertSame(0, WaMessage::count(), 'the gate refuses before any row write');
        Http::assertNothingSent();
    }

    // G2: the same refusal for the template path.
    public function test_send_template_to_an_opted_out_contact_throws_before_any_row_or_request()
    {
        WaContact::factory()->create(['phone' => '18479043315'])->optOut('inbound_keyword');

        try {
            app(WhatsAppSender::class)->sendTemplate('18479043315', 'etp_welcome_c', 'en_US');
            $this->fail('SuppressedContactException was not thrown');
        } catch (SuppressedContactException $e) {
            $this->assertSame('This contact has opted out. Nothing may be sent to this number.',
                $e->getMessage());
        }

        $this->assertSame(0, WaMessage::count(), 'the gate refuses before any row write');
        Http::assertNothingSent();
    }

    // G3: kills "exemption flag ignored in the gate" -- the one send the suppression regime
    // owes the contact must still go out and be recorded.
    public function test_send_text_with_allow_suppressed_sends_and_records_for_an_opted_out_contact()
    {
        WaContact::factory()->create(['phone' => '18479043315'])->optOut('inbound_keyword');
        Http::fake(['*' => Http::response(['messages' => [['id' => 'wamid.EXEMPT1']]], 200)]);

        $m = app(WhatsAppSender::class)->sendText('18479043315', 'You are opted out.',
            autoReply: true, allowSuppressed: true);

        $this->assertSame(WaMessage::SENT, $m->status);
        $this->assertSame('wamid.EXEMPT1', $m->meta_id);
        $this->assertTrue($m->auto_reply);
        $this->assertSame(1, WaMessage::count());
        Http::assertSent(fn ($r) => $r['to'] === '18479043315' && $r['text']['body'] === 'You are opted out.');
    }

    // G4 (R53): sendTemplate's allowSuppressed flag had no witness -- review arm V7 hard-coded
    // false in its post() call and the suite stayed green (G3 only exercises sendText).
    public function test_send_template_with_allow_suppressed_sends_and_records_for_an_opted_out_contact()
    {
        WaContact::factory()->create(['phone' => '18479043315'])->optOut('inbound_keyword');
        Http::fake(['*' => Http::response(['messages' => [['id' => 'wamid.EXEMPT2']]], 200)]);

        $m = app(WhatsAppSender::class)->sendTemplate('18479043315', 'etp_welcome_c', 'en_US',
            [], autoReply: true, allowSuppressed: true);

        $this->assertSame(WaMessage::SENT, $m->status);
        $this->assertSame('wamid.EXEMPT2', $m->meta_id);
        $this->assertSame(1, WaMessage::count());
    }
}
```

- [ ] **Step 8: Run the touched classes**

```bash
php artisan test tests/Feature/InboxActionsTest.php tests/Feature/WhatsAppSenderTest.php
```

Expected: InboxActionsTest 23 passed, WhatsAppSenderTest 15 passed (the original "8 passed" was
recomputed at re-sync: 8 plan rows + 12 brief witness rows + 3 R53 rows; sender 11 + G1–G4).

- [ ] **Step 9: Run the whole suite**

```bash
php artisan test
```

Expected: **294 passed (749 assertions)** — 267 Task-11 baseline + 23 InboxActionsTest + 4 new
WhatsAppSenderTest rows. Fix any regression in this task rather than leaving it for later.

- [ ] **Step 10: Commit** (every path the task owns — the original list omitted the four gate paths):

```bash
git add app/Http/Controllers/InboxController.php app/Services/WhatsAppSender.php app/Services/AutoResponder.php app/Services/SuppressedContactException.php resources/views/inbox/thread.blade.php routes/web.php tests/Feature/InboxActionsTest.php tests/Feature/WhatsAppSenderTest.php
git commit -m "feat: staff reply, template send and manual opt-out"
```

**R41 -- this block is corrected above, in the same pass that corrected Task 7's.** The first draft
installed a `'*'` stub (`wamid.STAFF1`) in `setUp()` and then wrote
`Http::fake(); Http::preventStrayRequests();` inside two tests intending to prove no HTTP request is
attempted. Both halves of that pair are dead: the setUp stub matched first, and even on its own
`Http::fake()` with no arguments appends *another* 200-returning catch-all (Factory.php:262-265), so the
stray guard -- which fires only when nothing matched (PendingRequest.php:1523-1526) -- could never have
thrown. The tests were not vacuous (`post()` records an OUT row even when Meta refuses, so the
`assertSame(0, ...)` counts still bite), but their names promised something no assertion delivered. They
now get what R41 prescribes: no stub in `setUp()`, `Http::preventStrayRequests()` in `setUp()`, a stub
only in the two tests that really send, and the four refuse-tests send nothing.

`test_manual_opt_out_stops_the_thread_from_sending` had the same disease in a different organ: it asserted
`isOptedOut()` and `opt_out_source` and nothing else, so a regression that recorded the opt-out and kept
sending would have passed a test whose name says "stops ... from sending". It now attempts the reply.

A whole-plan sweep for this shape (Tasks 8-16, every `Http` fence) found only two affected classes: this
one and Task 8's `AckFlowTest`, both corrected. Tasks 9, 10, 11, 13 and 14 use no `Http` facade at all;
Task 15 is Python and mocks `urlopen` directly; Task 16 has no test fences.

**R53 review record (replaces the "Open for Task 12" spec-vs-code question — decided, not carried).**
The implementer completed the task and its 17-arm battery (17/17 killed, every arm occ=1 / landed /
restored bytes+sha256, evidence `Temp/task12-arms/results-final.json`), then the session hit the account
credit limit and the Explore/Plan/general-purpose agent types were removed. The controller review ran
INLINE with the same criteria — **no independent subagent review was performed for Task 12**; the
controller's own 8-arm battery (`Temp/t12review/`) reproduced three claimed kill-sets exactly (gate
deleted → 5, flash deleted → 3, exemption removed → 7) and confirmed five GREEN residuals. Fix round
shipped four witness rows (W-OPTFLASH, W-TPLFLASH, W-NAMES, G4); re-run arms each killed exactly its one
new row (`Temp/t12review2/`). Residual dispositions: TOCTOU accepted-recorded; controller `trim` kept
as R51 X01 joint redundancy with TrimStrings — the report's proposed padded-body row was REJECTED as a
wrong-reason witness (it would pass with the trim deleted); R46 cache-key consumption recorded harmless.
Cosmetic note: `template()`'s FAILED flash renders `Meta refused (): <title>` when `error_code` is null
(network failure) — left as shipped, future polish. Full record: `.superpowers/sdd/2026-11-06-wa-webhook-inbox/r53-review.md`.
Spec §14's "same transaction that writes the row" sentence was amended to the shipped fresh-read gate +
accepted race. **Task 13's baseline is 294 (749).**

---
## Task 13: Import the existing send log

**Deliverable:** `wa:import-send-log` seeds `wa_contacts` and `wa_messages` from the bench's `send_log.csv`, is dry-run unless `--apply`, and running it twice changes nothing.

**Consumes:** `WaContact::fromPhone()`, `WaMessage::record()`.
**Produces:** `php artisan wa:import-send-log {path} {--apply}` printing counts; refused rows are counted as skipped and never become messages.
**Carried from R48:** the import writes message rows that never went through `AutoResponder`, so no `wa-ack-<contact>` cache key
stands behind them. That is the one state in which the 24-hour window query, and not `Cache::add`, decides whether a
contact gets acknowledged -- Task 8's `test_an_earlier_inbound_stands_the_ack_down_even_with_an_empty_cache` pins it for a
row two hours old. Neither guard is tested against a row **older** than the window whose key is still alive, because the
key's TTL is anchored at `now()` while the query looks at `sent_at`; an import of a months-old send log is exactly how
that shape appears. If this task can produce it, record that in its report rather than assuming Task 8 covered it.

- [ ] **Step 1: Create the fixture** `tests/Fixtures/send_log_sample.csv` (same columns as the real log; the third row is a refusal, the fourth a blank line):

```csv
utc_time,to,template,language,status,message_id,error
2026-11-05T14:03:11Z,18479043315,etp_opener_brand,en,sent,wamid.BENCH_A,
2026-11-05T14:05:02Z,19014307897,etp_welcome_c,en_US,sent,wamid.BENCH_B,
2026-11-06T09:00:00Z,15559998888,etp_welcome_a,en_US,refused,,15559998888 is not in test_numbers.txt

```

- [ ] **Step 2: Write the failing test** — `tests/Feature/ImportSendLogTest.php`

```php
<?php

namespace Tests\Feature;

use App\Models\WaContact;
use App\Models\WaMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ImportSendLogTest extends TestCase
{
    use RefreshDatabase;

    private function path(): string
    {
        return base_path('tests/Fixtures/send_log_sample.csv');
    }

    public function test_dry_run_imports_nothing_but_reports_the_counts()
    {
        $this->artisan('wa:import-send-log', ['path' => $this->path()])
            ->expectsOutputToContain('DRY RUN')
            ->expectsOutputToContain('2 messages, 2 contacts, 1 skipped')
            ->assertSuccessful();

        $this->assertSame(0, WaMessage::count());
        $this->assertSame(0, WaContact::count());
    }

    public function test_apply_creates_contacts_and_outbound_rows()
    {
        $this->artisan('wa:import-send-log', ['path' => $this->path(), '--apply' => true])
            ->assertSuccessful();

        $this->assertSame(2, WaContact::count());
        $this->assertSame(2, WaMessage::count());
        $this->assertSame(0, WaContact::where('phone', '15559998888')->count(),
            'a number the bench refused must not become a contact');

        $m = WaMessage::where('meta_id', 'wamid.BENCH_A')->firstOrFail();
        $this->assertSame(WaMessage::OUT, $m->direction);
        $this->assertSame('etp_opener_brand', $m->template_name);
        $this->assertSame('2026-11-05 14:03:11', $m->sent_at->toDateTimeString());
        $this->assertSame('send_log_import', $m->raw['source']);
        $this->assertFalse($m->contact->isOptedOut());
    }

    public function test_running_it_twice_changes_nothing()
    {
        $this->artisan('wa:import-send-log', ['path' => $this->path(), '--apply' => true]);
        $first = [WaContact::count(), WaMessage::count()];
        $this->artisan('wa:import-send-log', ['path' => $this->path(), '--apply' => true]);
        $this->assertSame($first, [WaContact::count(), WaMessage::count()]);
    }

    public function test_a_missing_file_fails_without_touching_the_database()
    {
        $this->artisan('wa:import-send-log', ['path' => base_path('tests/Fixtures/nope.csv'),
                                              '--apply' => true])
            ->expectsOutputToContain('not found')
            ->assertExitCode(1);
        $this->assertSame(0, WaMessage::count());
    }
}
```

- [ ] **Step 3: Run it** — expected FAIL `Command "wa:import-send-log" is not defined`.

- [ ] **Step 4: Create `app/Console/Commands/WaImportSendLog.php`**

```php
<?php

namespace App\Console\Commands;

use App\Models\WaContact;
use App\Models\WaMessage;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class WaImportSendLog extends Command
{
    protected $signature = 'wa:import-send-log {path : CSV exported by the send bench} {--apply}';

    protected $description = 'Seed wa_contacts and wa_messages from send_log.csv';

    public function handle(): int
    {
        $path = (string) $this->argument('path');
        if (!is_file($path) || !is_readable($path)) {
            $this->error('File not found or unreadable: ' . $path);

            return 1;
        }

        $handle = fopen($path, 'rb');
        $header = fgetcsv($handle);
        if (!is_array($header) || !in_array('to', $header, true)) {
            $this->error('Not a send log: expected a header row containing "to".');

            return 1;
        }

        $messages = $skipped = 0;
        $distinctPhones = [];
        $apply = (bool) $this->option('apply');

        while (($row = fgetcsv($handle)) !== false) {
            $row = array_combine($header, array_pad($row, count($header), null));
            $phone = WaContact::digitsOnly($row['to'] ?? '');
            if (strlen($phone) < 10) {
                continue;
            }

            $wamid = trim((string) ($row['message_id'] ?? ''));
            if ($wamid === '') {
                $skipped++;

                continue;
            }

            $distinctPhones[$phone] = true;
            $messages++;

            if (!$apply) {
                continue;
            }

            $contact = WaContact::fromPhone($phone);
            $sentAt = Carbon::hasFormat((string) $row['utc_time'], 'Y-m-d\TH:i:s\Z')
                ? Carbon::parse($row['utc_time'], 'UTC')
                : now();

            WaMessage::record([
                'wa_contact_id' => $contact->id,
                'meta_id' => $wamid,
                'direction' => WaMessage::OUT,
                'type' => 'template',
                'template_name' => $row['template'] ?? null,
                'template_lang' => $row['language'] ?? null,
                'status' => ($row['status'] ?? '') === 'sent' ? WaMessage::SENT : WaMessage::FAILED,
                'sent_at' => $sentAt,
                'raw' => ['source' => 'send_log_import'],
            ]);
        }
        fclose($handle);
        $contacts = count($distinctPhones);

        $this->line($apply ? "Imported {$messages} messages, {$contacts} contacts, {$skipped} skipped."
                           : "DRY RUN: would import {$messages} messages, {$contacts} contacts, {$skipped} skipped.");
        if (!$apply) {
            $this->line('Re-run with --apply to write.');
        }

        return 0;
    }
}
```

**R43 -- the importer counts contacts by distinct phone and reads the `status` column instead of
assuming it.** Two corrections to the draft above, both found by a whole-plan sweep rather than by a
failing test, which is why they are stated here instead of in a ruling about behaviour:

1. `$contacts++` sat inside the per-row loop, so a number that appears in the log twice was counted as two
   contacts while `WaContact::fromPhone()` produced one row. The summary line prints that number and Step 6
   tells a human to compare it against `WaContact::count()`, so an inflated figure would read as a bug in
   the database rather than in the counter. The log is written by a bench whose uniqueness cap is per-day,
   so repeats across days are the normal case, not an edge case. `$distinctPhones[$phone] = true` and
   `count($distinctPhones)` make the figure mean what the label says. The sample fixture uses two distinct
   numbers, so no test here can catch this -- do not "strengthen" the fixture into the only witness.
2. `'status' => WaMessage::SENT` hardcoded the outcome while the CSV has a `status` column. For the log as
   the bench writes it today the hardcoding is not wrong: `append_log()` writes a `message_id` only on the
   success path (sender_server.py:285), and every refusal path writes an empty id and is skipped by the
   `wamid === ''` guard above it. So no row that survives to `record()` is anything but `sent`. But the
   importer is the thing that will one day read a log written by something else, and a row that carries an
   id and a failed status must not be imported as a delivery. `'status' => ($row['status'] ?? '') === 'sent'
   ? WaMessage::SENT : WaMessage::FAILED` costs nothing and stops the importer from asserting an outcome it
   never verified -- the same rule R34 applied to reading Meta's response: decide at the reader, do not
   assume downstream.

- [ ] **Step 5: Run the test**

```bash
php artisan test tests/Feature/ImportSendLogTest.php
```

Expected: 4 passed.

- [ ] **Step 6: Import the real log** (this is the file the bench writes, so use a copy to keep the original untouched):

```bash
cp /c/Users/Hp/Documents/Qoder/2026-10-08/21238e53/send_log.csv storage/send_log_backup.csv
php artisan wa:import-send-log storage/send_log_backup.csv
php artisan wa:import-send-log storage/send_log_backup.csv --apply
php artisan tinker --execute="echo \App\Models\WaContact::count().' contacts, '.\App\Models\WaMessage::count().' messages';"
```

Expected: the dry run prints `DRY RUN: would import ...`; the counts match the rows that have a `message_id`. `storage/send_log_backup.csv` is inside `storage/`, which Laravel's `.gitignore` already excludes — confirm with `git status --short` showing no new file before committing.

- [ ] **Step 8: Commit**

```bash
git add app/Console/Commands/WaImportSendLog.php tests/Feature/ImportSendLogTest.php tests/Fixtures/send_log_sample.csv
git commit -m "feat: idempotent send_log importer"
```

---

## Task 14: Retention, schedule, style, full suite

**Deliverable:** webhook events stop accumulating after 30 days without a cron line nobody wrote down, and the whole suite plus Laravel Pint are clean.

- [ ] **Step 1: Write the failing test** — `tests/Feature/PruneEventsTest.php`

```php
<?php

namespace Tests\Feature;

use App\Models\WaWebhookEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PruneEventsTest extends TestCase
{
    use RefreshDatabase;

    public function test_events_older_than_the_retention_window_are_deleted()
    {
        config(['whatsapp.event_retention_days' => 30]);
        $old = WaWebhookEvent::store('1427706937088002', 'E1', ['a' => 1], WaWebhookEvent::STORED);
        $old->forceFill(['received_at' => now()->subDays(45)])->save();
        $edge = WaWebhookEvent::store('1427706937088002', 'E2', ['a' => 2], WaWebhookEvent::STORED);
        $edge->forceFill(['received_at' => now()->subDays(29)])->save();
        $fresh = WaWebhookEvent::store('1427706937088002', 'E3', ['a' => 3], WaWebhookEvent::STORED);

        $this->artisan('wa:prune-events')->assertSuccessful();

        $this->assertSame(2, WaWebhookEvent::count());
        $this->assertDatabaseMissing('wa_webhook_events', ['id' => $old->id]);
        $this->assertDatabaseHas('wa_webhook_events', ['id' => $edge->id]);
        $this->assertDatabaseHas('wa_webhook_events', ['id' => $fresh->id]);
    }

    public function test_a_custom_window_is_honoured()
    {
        $old = WaWebhookEvent::store('1', 'E1', [], WaWebhookEvent::STORED);
        $old->forceFill(['received_at' => now()->subDays(3)])->save();

        $this->artisan('wa:prune-events', ['--days' => 1])->assertSuccessful();
        $this->assertSame(0, WaWebhookEvent::count());
    }

    public function test_a_zero_or_negative_window_is_refused()
    {
        WaWebhookEvent::store('1', 'E1', [], WaWebhookEvent::STORED);
        $this->artisan('wa:prune-events', ['--days' => 0])->assertExitCode(1);
        $this->assertSame(1, WaWebhookEvent::count());
    }
}
```

- [ ] **Step 2: Run it** — expected FAIL command not defined.

- [ ] **Step 3: Create `app/Console/Commands/WaPruneEvents.php`**

```php
<?php

namespace App\Console\Commands;

use App\Models\WaWebhookEvent;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class WaPruneEvents extends Command
{
    protected $signature = 'wa:prune-events {--days= : Retention window in days}';

    protected $description = 'Delete webhook audit events older than the retention window';

    public function handle(): int
    {
        $days = (int) ($this->option('days') ?? config('whatsapp.event_retention_days', 30));
        if ($days < 1) {
            $this->error('The retention window must be at least 1 day.');

            return 1;
        }

        $cut = Carbon::now()->subDays($days);
        $deleted = 0;

        // Chunked deletes: an encrypted column cannot be filtered in SQL, and a
        // one-shot mass delete would lock the table on a big backlog.
        WaWebhookEvent::where('received_at', '<', $cut)
            ->orderBy('id')
            ->chunkById(500, function ($rows) use (&$deleted) {
                $deleted += $rows->count();
                WaWebhookEvent::whereIn('id', $rows->pluck('id'))->delete();
            });

        $this->line("Pruned {$deleted} webhook events older than {$days} days.");

        return 0;
    }
}
```

- [ ] **Step 4: Register the schedule** — append to `routes/console.php` (the file currently contains only the framework's `ping` closure):

```php
use Illuminate\Support\Facades\Schedule;

Schedule::command('wa:prune-events')->dailyAt('03:10');
Schedule::command('queue:work --stop-when-empty --max-time=50')->everyMinute()->withoutOverlapping();
```

Verify it registered:

```bash
php artisan schedule:list
```

Expected: two lines, `wa:prune-events` at 03:10 and the queue worker every minute.

- [ ] **Step 5: Run the full suite and the style check**

```bash
php artisan test
php vendor/bin/pint --test
```

Expected: every test passes; Pint reports no issues. If Pint asks to rewrite files, run `php vendor/bin/pint` once and commit the result separately as `style: pint`.

- [ ] **Step 6: Commit**

```bash
git add app/Console/Commands/WaPruneEvents.php routes/console.php tests/Feature/PruneEventsTest.php
git commit -m "feat: event retention, scheduled queue drain, clean style and suite"
```

---

## Task 15: Make the send bench fail closed on the suppression list

**Deliverable:** the Python bench consults the inbox app before every send, refuses opted-out numbers, and refuses everything if the suppression list cannot be fetched. It also reports each accepted send back to the inbox.

**Consumes:** `GET /wa/suppressions`, `POST /inbound/record-send` (Task 10), both with `Authorization: Bearer <WA_EXPORT_TOKEN>`.
**Produces in `C:\Users\Hp\Documents\Qoder\2026-10-08\21238e53\sender_server.py`:** `fetch_suppressions()`, `suppressions(force=False)`, `record_send(to, template, lang, msg_id)`, a fifth `blocked` parameter on `check_gates()`, and three new keys in `/api/state`.

- [ ] **Step 1: Write the failing test** — `C:\Users\Hp\Documents\Qoder\2026-10-08\21238e53\test_suppression_gate.py`. These tests patch every network call and every log write, so nothing is sent and `send_log.csv` is untouched.

```python
"""Suppression gate tests for sender_server. Run: python -m unittest -v test_suppression_gate"""

import os
import sys
import unittest
from unittest import mock

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import sender_server as s

US2 = "18479043315"
TPL = {"name": "etp_welcome_c", "language": "en_US", "variables": 0,
       "firstIsName": False, "components": {}}


class Resp:
    def __init__(self, body):
        self._b = body.encode("utf-8") if isinstance(body, str) else body

    def read(self):
        return self._b

    def __enter__(self):
        return self

    def __exit__(self, *exc):
        return False


class GateTest(unittest.TestCase):
    def setUp(self):
        self.saved = (s.INBOX_URL, s.INBOX_TOKEN)
        s._suppressions.update({"at": 0.0, "numbers": None})
        s.INBOX_URL = "http://inbox.local.test"
        s.INBOX_TOKEN = "tok"
        self.logged = []
        self.p_log = mock.patch.object(s, "append_log",
                                       lambda *a: self.logged.append(a)).start()

    def tearDown(self):
        mock.patch.stopall()
        s.INBOX_URL, s.INBOX_TOKEN = self.saved
        s._suppressions.update({"at": 0.0, "numbers": None})

    def test_opted_out_number_is_refused(self):
        verdict, why = s.check_gates(US2, {US2: "US test 2"}, set(), set(), {US2})
        self.assertEqual(verdict, "refused")
        self.assertIn("suppression", why)

    def test_list_is_cached_for_sixty_seconds(self):
        calls = []

        def fake_urlopen(req, timeout=None):
            calls.append(req)
            return Resp("18479043315")

        with mock.patch.object(s.urllib.request, "urlopen", fake_urlopen):
            s.suppressions()
            s.suppressions()
        self.assertEqual(len(calls), 1, "second call must come from cache")

    def test_unreachable_inbox_blocks_the_send(self):
        def boom(req, timeout=None):
            raise OSError("connection refused")

        with mock.patch.object(s.urllib.request, "urlopen", boom), \
             mock.patch.object(s, "graph", side_effect=AssertionError("graph must not be called")) as g:
            res = s.send_one(TPL, US2, None, None)
        self.assertFalse(res["ok"])
        self.assertEqual(res["status"], "refused")
        self.assertIn("suppression", res["error"])
        self.assertEqual(g.call_count, 0)
        self.assertEqual(self.logged[-1][3], "refused")

    def test_no_inbox_url_configured_also_blocks(self):
        s.INBOX_URL = ""
        with mock.patch.object(s, "graph", side_effect=AssertionError("graph must not be called")):
            res = s.send_one(TPL, US2, None, None)
        self.assertIn("refused", res["status"])
        self.assertIn("suppression", res["error"])

    def test_allowed_number_sends_and_is_recorded(self):
        seen = []

        def fake_urlopen(req, timeout=None):
            seen.append(req)
            return Resp('{"recorded":true}')

        with mock.patch.object(s.urllib.request, "urlopen", fake_urlopen), \
             mock.patch.object(s, "allow_list", lambda: {US2: "US test 2"}), \
             mock.patch.object(s, "unique_in_log", lambda: set()), \
             mock.patch.object(s, "last_24h_unique", lambda: set()), \
             mock.patch.object(s, "graph",
                               lambda *a, **k: {"messages": [{"id": "wamid.ME1"}]}):
            res = s.send_one(TPL, US2, None, None)
        self.assertTrue(res["ok"])
        self.assertEqual(res["message_id"], "wamid.ME1")
        urls = [r.get_full_url() for r in seen]
        self.assertTrue(any(u.endswith("/wa/suppressions") for u in urls), urls)
        self.assertTrue(any(u.endswith("/inbound/record-send") for u in urls), urls)
        # R44: assert the header per request. `{k: v for r in seen for k, v in r.header_items()}` merges
        # both requests into one dict, so if record-send sent no Authorization header at all the value from
        # the suppressions call would satisfy the assertion -- a green test proving the wrong thing.
        for r in seen:
            sent = {k.lower(): v for k, v in r.header_items()}
            self.assertEqual(sent.get("authorization"), "Bearer tok",
                "%s sent without the bearer header" % r.get_full_url())
        self.assertEqual(len(seen), 2, "expected one suppressions read and one record-send write")

    def test_a_record_send_failure_does_not_undo_an_accepted_send(self):
        def fake_urlopen(req, timeout=None):
            if req.get_full_url().endswith("/wa/suppressions"):
                return Resp("")
            raise OSError("inbox is down")

        with mock.patch.object(s.urllib.request, "urlopen", fake_urlopen), \
             mock.patch.object(s, "allow_list", lambda: {US2: "US test 2"}), \
             mock.patch.object(s, "unique_in_log", lambda: set()), \
             mock.patch.object(s, "last_24h_unique", lambda: set()), \
             mock.patch.object(s, "graph",
                               lambda *a, **k: {"messages": [{"id": "wamid.ME2"}]}):
            res = s.send_one(TPL, US2, None, None)
        self.assertTrue(res["ok"], "Meta accepted it; that fact must not be softened")
        self.assertIn("inbox record failed", res["note"])

    def test_the_suppression_list_beats_the_allow_list(self):
        # R44: the number must be on BOTH lists or this proves nothing. Passing an empty `blocked` set --
        # as the first draft did -- exercises only the allow-list branch and would still pass if the
        # suppression check were deleted. Suppression wins: an opted-out contact is not messaged, even one
        # we own a test handset for. Refusing an allow-listed number costs us a warm-up send; sending to a
        # suppressed one costs the account its quality rating, and Meta's opt-out handling is audited.
        verdict, why = s.check_gates(US2, {US2: "US test 2"}, set(), set(), {US2})
        self.assertEqual(verdict, "refused", why)
        self.assertIn("suppression list", why)
        self.assertNotIn("test_numbers.txt", why)
```

**R44 -- a test that names a policy must construct the case where the policy is the only thing that
decides.** Found by the same whole-plan sweep that produced R41/R42/R43, in Task 15's Python gate tests,
which R41 does not reach because they mock `urlopen` rather than using the `Http` facade. Two instances,
both invisible to a green run:

1. `test_allow_list_still_wins_over_the_suppression_list` called `check_gates(number, allowed, set(),
   set(), set())` with an empty `blocked` set. With nothing on the suppression list the suppression branch
   is never taken, so the test would pass unchanged if that branch were deleted -- and its name asserts a
   winner between two lists that were never both in play. The name also states the wrong policy:
   `check_gates` (Step 5) refuses anything on the suppression list even when it is allow-listed, which is
   the compliance-correct ordering, so the test is now `test_the_suppression_list_beats_the_allow_list`,
   puts the number on both lists, and asserts the refusal reason names the suppression list rather than
   the allow-list file. Deleting the blocked check makes it fail; so does reordering the checks.
2. `headers = {k.lower(): v for r in seen for k, v in r.header_items()}` flattens every recorded request
   into one dict before asserting `authorization == "Bearer tok"`. Both requests carry that header, so a
   regression that dropped it from `record-send` alone would still find the value from the suppressions
   read and pass. Asserted per request now, with the request count pinned so a silently missing call
   cannot hide behind a header that happens to be right.

These are plan-code corrections; neither is verified by a run yet, because Task 15 is not implemented.
Step 1's own expected result is a RED suite, so the first execution of this file confirms or refutes both.

- [ ] **Step 2: Run it and watch it fail**

```bash
cd /c/Users/Hp/Documents/Qoder/2026-10-08/21238e53 && python -m unittest -v test_suppression_gate
```

Expected: FAIL — `module 'sender_server' has no attribute 'INBOX_URL'` in `setUp`.

- [ ] **Step 3: Add the constants.** In `sender_server.py`, after `HEADER_IMAGE = {...}` (line 41-43) and before `LOCK = threading.Lock()` (line 45), insert:

```python
# The inbox app holds the opt-out truth. If it cannot be reached, nothing is sent.
INBOX_URL = os.environ.get("SENDER_INBOX", "").rstrip("/")
INBOX_TOKEN = os.environ.get("SENDER_INBOX_TOKEN", "")
SUPPRESSION_TTL = 60.0
```

and next to the other caches on line 46 (`_tpl_cache = ...`) add:

```python
_suppressions = {"at": 0.0, "numbers": None}
```

- [ ] **Step 4: Add the three functions** after `graph(...)` (which ends on line 79), before `load_templates()`:

```python
# ------------------------------------------------------------- suppression list

def fetch_suppressions():
    """Opted-out numbers from the inbox app. Raises when the list cannot be read."""
    if not INBOX_URL:
        raise RuntimeError("SENDER_INBOX is not set")
    req = urllib.request.Request(
        INBOX_URL + "/wa/suppressions",
        headers={"Authorization": "Bearer " + INBOX_TOKEN})
    with urllib.request.urlopen(req, timeout=8) as r:
        body = r.read().decode("utf-8", "replace")
    return {digits(line) for line in body.splitlines() if digits(line)}


def suppressions(force=False):
    """Cached for SUPPRESSION_TTL seconds so a 100-row batch makes one call."""
    now = time.time()
    if (not force and _suppressions["numbers"] is not None
            and now - _suppressions["at"] < SUPPRESSION_TTL):
        return _suppressions["numbers"]
    numbers = fetch_suppressions()
    _suppressions.update({"at": now, "numbers": numbers})
    return numbers


def record_send(to, template, lang, msg_id):
    """Tell the inbox what was sent. Best effort: a failure is reported, never fatal."""
    if not INBOX_URL or not msg_id:
        return ""
    payload = json.dumps({"to": to, "template": template, "language": lang,
                          "message_id": msg_id,
                          "utc_time": datetime.utcnow().strftime("%Y-%m-%dT%H:%M:%SZ")}).encode()
    req = urllib.request.Request(
        INBOX_URL + "/inbound/record-send", data=payload, method="POST",
        headers={"Authorization": "Bearer " + INBOX_TOKEN, "Content-Type": "application/json"})
    try:
        with urllib.request.urlopen(req, timeout=8) as r:
            r.read()
        return ""
    except Exception as e:                                      # noqa: BLE001
        return "inbox record failed: %s" % e
```

- [ ] **Step 5: Widen `check_gates`** (currently lines 255-265). Replace the whole function with:

```python
def check_gates(to, allowed, used, in24, blocked=frozenset()):
    """Every refusal reason that is not about the payload itself."""
    if to not in allowed:
        return "refused", "%s is not in test_numbers.txt" % to
    if len(to) < 10:
        return "refused", "%s looks too short to be E.164" % to
    if to in blocked:
        return "refused", "%s is on the suppression list (opted out)" % to
    if to not in used and len(used) >= MAX_UNIQUE:
        return "cap", "campaign cap reached: %d unique numbers" % MAX_UNIQUE
    if to not in in24 and len(in24) >= DAY_CAP:
        return "cap", "rolling 24h cap reached: %d unique recipients" % DAY_CAP
    return "ok", ""
```

The default keeps the signature backward compatible, and the allow-list and length checks stay ahead of the
suppression check so a number that is not on the allow-list is refused without its suppression status ever
being consulted. Read that sentence narrowly: it orders the *checks*, not the *calls*. `send_one` fetches
the suppression list first (Step 6), so every send attempt costs one inbox read even for a number the
allow-list will refuse -- that is the price of fail-closed, and the fetch is cached for `SUPPRESSION_TTL`
seconds so a warm-up burst pays it once. Do not "optimise" it by moving the fetch behind the allow-list
check: then a number that is not allow-listed short-circuits, and the first-draft bug -- a `blocked` set
that silently stayed empty -- becomes invisible instead of costly.

- [ ] **Step 6: Gate `send_one`** (currently lines 268-287). Replace its body with:

```python
def send_one(tpl, to, name, extras):
    """Validate, gate, send, log. Returns a dict for the browser."""
    values = build_values(tpl, name, extras)
    payload = build_payload(tpl, to, values)
    try:
        blocked = suppressions()
    except Exception as e:                                      # noqa: BLE001
        why = "send refused: suppression list unreachable (%s)" % e
        append_log(to, tpl["name"], tpl["language"], "refused", "", why)
        return {"ok": False, "to": to, "status": "refused", "error": why}
    allowed, used, in24 = allow_list(), unique_in_log(), last_24h_unique()
    verdict, why = check_gates(to, allowed, used, in24, blocked)
    if verdict != "ok":
        append_log(to, tpl["name"], tpl["language"], verdict, "", why)
        return {"ok": False, "to": to, "status": verdict, "error": why}
    try:
        res = graph("POST", "/%s/messages" % PHONE_ID, payload)
    except RuntimeError as e:
        append_log(to, tpl["name"], tpl["language"], "error", "", str(e))
        return {"ok": False, "to": to, "status": "error", "error": str(e)}
    msg_id = ""
    for m in res.get("messages", []) or []:
        msg_id = m.get("id", "")
    append_log(to, tpl["name"], tpl["language"], "sent", msg_id, "")
    record_note = record_send(to, tpl["name"], tpl["language"], msg_id)
    note = "accepted by Meta; delivery and replies now arrive in the inbox"
    if record_note:
        note += " (" + record_note + ")"
    return {"ok": True, "to": to, "status": "sent", "message_id": msg_id, "note": note}
```

`/api/batch` needs no change: it calls `send_one` per row, and the suppression cache means one HTTP call for the whole batch.

- [ ] **Step 7: Show the state.** In `do_GET`'s `/api/state` handler, replace the whole `return self.reply(200, {...})` block (lines 335-346) with the following. The suppression fetch is wrapped so the page still loads when the inbox is down, while `send_one` keeps failing closed:

```python
                try:
                    blocked = suppressions()
                    sup_note = "%d opted out" % len(blocked)
                except Exception as e:                          # noqa: BLE001
                    blocked, sup_note = None, "inbox unreachable - sends are blocked: %s" % e
                return self.reply(200, {
                    "allow_list": len(allowed),
                    "allow": [{"to": k, "label": v} for k, v in sorted(allowed.items())],
                    "unique_used": len(used),
                    "unique_left": max(0, MAX_UNIQUE - len(used)),
                    "in_24h": len(in24),
                    "sent_today": today_count(),
                    "cost_today_aed": round(today_count() * PRICE_AED, 4),
                    "max_unique": MAX_UNIQUE,
                    "day_cap": DAY_CAP,
                    "price_aed": PRICE_AED,
                    "suppressed": None if blocked is None else sorted(blocked),
                    "suppression_note": sup_note,
                    "inbox_configured": bool(INBOX_URL),
                })
```

- [ ] **Step 8: Run the tests**

```bash
cd /c/Users/Hp/Documents/Qoder/2026-10-08/21238e53 && python -m unittest -v test_suppression_gate
```

Expected: 7 passed.

- [ ] **Step 9: Prove the fail-closed behaviour against a dead inbox** (nothing is sent — this only asserts the refusal, and it writes one `refused` line into `send_log.csv`, which is the honest record):

```bash
cd /c/Users/Hp/Documents/Qoder/2026-10-08/21238e53
SENDER_INBOX=http://127.0.0.1:9 SENDER_INBOX_TOKEN=x python sender_server.py &
sleep 1
curl -s -X POST http://127.0.0.1:8765/api/send -H 'Content-Type: application/json' \
  -d '{"template":"etp_welcome_c","to":"18479043315","name":"Sam"}'
```

Expected JSON contains `"status":"refused"` and an `error` naming the suppression list. Then stop that server (`Get-NetTCPConnection -LocalPort 8765 | ForEach-Object { Stop-Process -Id $_.OwningProcess }` in PowerShell, or `kill %1` in bash) and note in the commit message that a refused row was written by the probe.

- [ ] **Step 10: Keep the bench change with the plan.** The bench directory is not a git repository, so the Python diff is copied into the Laravel repo for the record:

```bash
mkdir -p /d/etp-wa-inbox/docs/bench
cp /c/Users/Hp/Documents/Qoder/2026-10-08/21238e53/sender_server.py \
   /c/Users/Hp/Documents/Qoder/2026-10-08/21238e53/test_suppression_gate.py \
   /d/etp-wa-inbox/docs/bench/
cd /d/etp-wa-inbox
git add docs/bench
git commit -m "feat(bench): fail-closed suppression gate and send recording"
```

The copies are snapshots for review, not the running code: the live bench remains `C:\Users\Hp\Documents\Qoder\2026-10-08\21238e53\sender_server.py`.

---

## Task 16: Deploy, subscribe the webhook, live acceptance

**Deliverable:** the subdomain serves the app over HTTPS, Meta's callbacks arrive and are stored, a reply from a handset appears in the inbox and can be answered, and STOP silences the bench. Everything here is performed by the account owner; nothing in this task is automated, and each live step sends a real message to a real number.

- [ ] **Step 1: Write down the deploy record** — create `docs/DEPLOY.md` with these fields filled in by whoever deploys. Values are typed into the host's `.env`, never pasted into chat:

```markdown
# Deploy record

Subdomain: inbox.etaxplanner.com (confirmed / changed: ______)
Document root: <Plesk vhost>/httpdocs -> must point at the Laravel public/ directory
PHP: 8.2, extensions present: pdo_mysql, mbstring, openssl, ctype, json, tokenizer, xml
Database: etp_wa_inbox (created in Plesk) + dedicated DB user, host localhost
Deployed commit: ______
WA_PHONE_ID: 1427706937088002
WA_WABA_ID: 1717463009877473
WA_MY_NUMBER: 12242144261  (must be set before the first live callback: with it empty, our own
  outbound traffic is filed as inbound against our own number, and R32's digit-normalising means a
  punctuated value like +1 (224) 214-4261 also works -- but no value at all does not)
QUEUE_CONNECTION: database  (NOT sync. Under sync an exception inside ProcessInboundEvent is an HTTP
  500 to a correctly signed callback, and Meta drops the subscription after repeated 5xx. R28, R31)
  Also running: php /var/www/<vhost>/httpdocs/artisan queue:work --tries=3  under a supervisor, or
  process the queue some other way that cannot silently stop
DB session timezone: php artisan tinker --execute="echo now()->toDateTimeString(), ' | ', DB::selectOne('select now() x')->x;"
  must print the same instant twice (config/database.php pins time_zone='+00:00'; R31). If it does
  not, the host is not honouring the pin and every timestamp on screen is offset.
No backfill may hand a stored wa_webhook_events.changes item straight to ProcessInboundEvent: the
  encrypted:array round trip returns sent_at/status_at as strings and touchInbound()/applyStatus()
  are typed, so it throws. Re-parse through WebhookParser instead (M-9).
webhook table note: body_hash was added by editing an already-applied migration
  (2026_11_06_000001_create_wa_tables.php), so on the host that file must be run from scratch --
  `migrate:status` will not show it as pending.
Webhook URL: https://inbox.etaxplanner.com/wa/webhook
Cron (Plesk > Scheduled Tasks, every minute):
  php /var/www/<vhost>/httpdocs/artisan schedule:run >> /dev/null 2>&1
TLS: Let's Encrypt issued and "Enforce HTTPS" enabled (Meta requires HTTPS with a valid chain)
```

- [ ] **Step 2: Create the subdomain and database in Plesk**, add the A/CNAME record, point the document root at `public/`, upload the app (git clone on the server is preferred; `git` is not yet on the host, so an archive upload of the committed tree is acceptable), then on the host:

```bash
composer install --no-dev --optimize-autoloader
cp .env.example .env            # then fill every value, including the WA_ block
php artisan key:generate
php artisan migrate --force
php artisan config:cache
php artisan wa:create-user <staff email>
```

- [ ] **Step 3: Confirm the endpoint answers before touching Meta.** Locally on the host, then over the public URL:

```bash
VERIFY=$(grep '^WA_VERIFY_TOKEN=' .env | cut -d= -f2)
curl -s "https://inbox.etaxplanner.com/wa/webhook?hub.mode=subscribe&hub.verify_token=$VERIFY&hub.challenge=ok123"
```

Expected: the literal `ok123` and nothing else. A wrong token must print `forbidden` with 403 — test that too by sending `hub.verify_token=wrong`.

Then confirm the suppression endpoint fails closed from outside:

```bash
curl -s -o /dev/null -w '%{http_code}\n' https://inbox.etaxplanner.com/wa/suppressions
```

Expected: `401`.

- [ ] **Step 4: Subscribe the webhook in the Meta developer console** — manual, by the account owner, in this order:

1. developers.facebook.com, app for WABA `1717463009877473`, WhatsApp product, **Configuration > Webhooks**.
2. Callback URL: `https://inbox.etaxplanner.com/wa/webhook`.
3. Verify token: paste the value from the host's `WA_VERIFY_TOKEN`. Do not paste it into chat.
4. Click **Verify and save**. A `GET` with `hub.challenge` must come back green before going further.
5. User ID field: leave blank (this app has no test-user id requirement).
6. **Subscribe to this field**: `messages` on phone number `1427706937088002`. Do not subscribe to anything on WABA `830913789751992` — that is the old AiSensy account and it must not point at this inbox.
7. In WhatsApp Manager > Phone Number > `+1 224-214-4261`, under **Webhook**, confirm the same callback URL is shown for that number only.

- [ ] **Step 5: Live acceptance, in order, with the counters watched**

1. From the bench, send one approved template to `18479043315` (`python sender_server.py` with `SENDER_INBOX` and `SENDER_INBOX_TOKEN` pointing at the deployed subdomain). Expected: the response says `accepted by Meta` and the note says delivery arrives in the inbox. This is a real message to a real handset.
2. Within a minute: `wa_webhook_events` gains a `stored` row and `/inbox/3`-style thread shows the outbound row; when the handset receives it, a `sent` then `delivered` status appears on that same row. This is the first time delivery is provable rather than assumed.
3. Reply "hello" from that handset. Expected: an inbound row and one automatic acknowledgement, both visible, the ack marked `automatic`.
4. Reply a second message. Expected: stored, no second ack.
5. Answer from the inbox reply box. Expected: the free-form message sends because the window is open.
6. Send `STOP` from the handset. Expected: `opted out` badge, one confirmation, and the contact listed by `GET /wa/suppressions`.
7. From the bench, try to send that same number again. Expected: `refused`, error naming the suppression list, nothing sent to Meta.
8. Stop the inbox app (or set `SENDER_INBOX` at a dead port) and try any send. Expected: `refused` with "suppression list unreachable". No send may succeed while the list is unreadable.
9. **Opt-out phrasing sign-off (R47).** Read the account owner the measured shape table in Task 7's R47 ruling --
   33 caught, 13 missed opt-outs, 10 false hits, 20 correct silences -- and get an explicit yes or a requested
   change before the first campaign send. The knobs are one word between `WHOLE_MESSAGE` and `KEYWORDS`, or a
   `PHRASES` entry; no structural change. Do not send to a number that is not on the allow list while doing this.
10. **Empty `WA_OPT_OUT_TEXT` behaviour (R42).** With the confirmation text unset, an opt-out is still
    *suppressed* but no confirmation is sent, silently. Check the key is set on the host before go-live, or
    expect silenced clients who were never told they were silenced.
11. **The re-arm on a real handset (R49).** The suite proves the 24-hour window with a frozen clock on sqlite.
    Send one message from the test number, then another more than `window_hours` later, and confirm the second
    one is acknowledged too. Nothing here can prove the production cache store expires the key -- R46 covers
    why, and only the live number can answer it.

- [ ] **Step 6: Record the outcome** in `docs/DEPLOY.md` — the eleven lines above, each marked pass or fail with what was seen, then commit:

```bash
cd /d/etp-wa-inbox && git add docs/DEPLOY.md && git commit -m "docs: deploy and live acceptance record"
```

---

## Done when

- `php artisan test` is green and `php vendor/bin/pint --test` is clean.
- `wa_contacts` is the authoritative contactable list: the log import ran, and STOP from a handset moves a number from sendable to refused within seconds.
- The bench cannot send while the inbox is unreachable, and says so in the refusal message.
- Meta's subscription is live on `1427706937088002` only, and `830913789751992` remains unconnected.
- Delivery state for a live send is visible in the thread, which is the fact that has been unverifiable until now.



