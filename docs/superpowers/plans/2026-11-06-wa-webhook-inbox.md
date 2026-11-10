# WhatsApp Inbound Inbox + Webhook Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Receive Meta's WhatsApp Cloud API webhooks for +1 224-214-4261, show a replyable inbox, auto-handle STOP and a first-reply acknowledgement, and publish a suppression list that the existing send bench must consult before sending anything.

**Architecture:** One small Laravel 12 app on its own subdomain with its own MySQL database. A signature-verified webhook endpoint stores the event and returns 200 immediately; a queued job normalises each parsed item into `wa_contacts` / `wa_messages` rows and applies two narrow auto-reply rules through one send service. An authenticated inbox reads those rows and replies through the same service. Two bearer-token machine endpoints expose the suppression list and accept send records from the Python bench, which becomes fail-closed: no suppression list, no send.

**Tech Stack:** PHP 8.2.12 local / 8.2.35 on host, Laravel Framework 12.71.0, PHPUnit 11.5.50, Guzzle 7.16.1 (already installed), MariaDB 10.4.32 local (`C:\xampp\mysql`), MySQL on Plesk, Python 3 stdlib for the bench.

**Spec:** `docs/superpowers/specs/2026-11-06-wa-webhook-inbox-design.md`

## Global Constraints

- Work in `D:\etp-wa-inbox` (bash: `/d/etp-wa-inbox`). Git is initialised; commit after every task.
- **No new Composer packages.** `laravel/framework` 12.71.0, `phpunit/phpunit` 11.5.50 and `guzzlehttp/guzzle` 7.16.1 are already installed. Never run `composer require`.
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
        return $this->belongsTo(WaContact::class);
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

- [ ] **Step 9: Run `php artisan migrate --force`, then the test again.** Expected: 4 passed.

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

```php
<?php

namespace App\Support;

use App\Models\WaContact;
use Carbon\Carbon;
use Carbon\CarbonInterface;

class WebhookParser
{
    /**
     * @return array{matched: bool, phone_number_id: ?string, items: array<int, array<string, mixed>>}
     */
    public static function parse(array $payload, string $phoneId): array
    {
        $items = [];
        $matched = false;
        $seen = null;

        foreach ($payload['entry'] ?? [] as $entry) {
            foreach ($entry['changes'] ?? [] as $change) {
                $value = $change['value'] ?? [];
                $numberId = $value['metadata']['phone_number_id'] ?? null;
                if (!is_string($numberId) || $numberId !== $phoneId) {
                    $seen = $seen ?? $numberId;
                    continue;
                }
                $matched = true;

                foreach ($value['contacts'] ?? [] as $contact) {
                    $phone = WaContact::digitsOnly($contact['wa_id'] ?? '');
                    $name = $contact['profile']['name'] ?? null;
                    if ($phone !== '' && is_string($name) && $name !== '') {
                        $items[] = ['kind' => 'profile', 'phone' => $phone, 'name' => $name, 'raw' => $contact];
                    }
                }

                foreach ($value['messages'] ?? [] as $message) {
                    $items[] = self::message($message);
                }

                foreach ($value['statuses'] ?? [] as $status) {
                    $items[] = self::status($status);
                }
            }
        }

        $items = array_values(array_filter($items));

        return ['matched' => $matched, 'phone_number_id' => $seen, 'items' => $items];
    }

    private static function message(array $m): array
    {
        $phone = WaContact::digitsOnly(
            ($m['from'] ?? null) === config('whatsapp.my_number') ? ($m['to'] ?? '') : ($m['from'] ?? '')
        );

        return [
            'kind' => 'message',
            'meta_id' => $m['id'] ?? null,
            'phone' => $phone,
            'direction' => ($m['from'] ?? null) === config('whatsapp.my_number')
                ? \App\Models\WaMessage::OUT : \App\Models\WaMessage::IN,
            'type' => $m['type'] ?? 'unknown',
            'text' => $m['text']['body'] ?? $m['button']['text'] ?? $m['interactive']['name'] ?? null,
            'button_id' => $m['interactive']['button_reply']['id']
                ?? $m['interactive']['list_reply']['id']
                ?? $m['button']['payload'] ?? null,
            'template_name' => $m['template']['name'] ?? null,
            'template_lang' => $m['template']['language']['code'] ?? null,
            'sent_at' => self::ts($m['timestamp'] ?? null),
            'raw' => $m,
        ];
    }

    private static function status(array $s): array
    {
        return [
            'kind' => 'status',
            'meta_id' => $s['id'] ?? null,
            'phone' => WaContact::digitsOnly($s['recipient_id'] ?? ''),
            'status' => $s['status'] ?? 'unknown',
            'error_code' => isset($s['errors'][0]['code']) ? (string) $s['errors'][0]['code'] : null,
            'error_title' => isset($s['errors'][0]['title']) ? (string) $s['errors'][0]['title'] : null,
            'status_at' => self::ts($s['timestamp'] ?? null),
            'raw' => $s,
        ];
    }

    private static function ts(mixed $epoch): CarbonInterface
    {
        return is_numeric($epoch)
            ? Carbon::createFromTimestamp((int) $epoch, 'UTC')
            : now();
    }
}
```

`Carbon::createFromTimestamp` rather than `Carbon::parse`, because Meta's `timestamp` is a Unix integer.

- [ ] **Step 7: Add the POST branch to `WaWebhookController`** (replace the class body, keep `verify()` as-is and add the ingest method plus a protected seam that Task 5 fills in):

```php
    public function ingest(Request $request)
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
        $entryId = $this->stringOrNull($payload['entry'][0]['id'] ?? null);
        $ours = (string) config('whatsapp.phone_id', '');
        $numberFor = $parsed['matched'] ? $ours : $parsed['phone_number_id'];

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

    /**
     * Same rule as param(), one step further along the request: anything we hand to a
     * typed argument comes from a body Meta signs with our app secret, so a value that
     * is not a string is not a format we recognise and must not be able to raise a
     * TypeError. A TypeError is a 500, and a 500 on the POST is what makes Meta drop
     * the subscription (Ruling R23).
     */
    private function stringOrNull(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }

    private function accepted()
    {
        return response('', 200);
    }

    protected function dispatch(array $item): void
    {
        // Task 5 replaces this with ProcessInboundEvent::dispatch($item).
    }
```

Ruling R23 is the `stringOrNull()` guard on the entry id. Every other value handed to a typed
parameter here is already stringly-typed by construction (`$hash`, `$ours`, `$parsed['items']`,
and the parser's own `is_string` check on `phone_number_id`), but `entry[0]['id']` is read
straight out of the decoded body. Without the guard, `{"entry":[{"id":["a","b"]}]}` correctly
signed with the app secret raises `TypeError: WaWebhookEvent::store(): Argument #2 ($entryId)
must be of type ?string, array given` and answers 500 — verified by mutation, so the test in
Step 2 detects it rather than merely sitting beside it. Same principle as R17, applied one step
further along the request: the shape of a callback must never be able to decide whether we stay
subscribed.

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

- [ ] **Step 8: Run the test**

```bash
php artisan test tests/Feature/WebhookIngestTest.php
```

Expected: **7 passed** — the six this task's steps describe, plus the R23 entry-id guard. Full
suite: **27 passed**.

- [ ] **Step 9: Commit**

```bash
git add app database/migrations routes/web.php bootstrap/app.php tests
git commit -m "feat: signature-verified webhook ingest with replay and foreign-number handling"
```

---

## Task 5: Event job — contacts, messages, receipts

**Deliverable:** parsed items become rows: profile names land on the contact, inbound messages set `last_inbound_at`, outbound rows are recorded, statuses map onto the row they belong to and never move backwards, statuses for unknown `wamid`s are dropped.

**Consumes:** the item shapes from Task 4, `WaMessage::record()`, `WaContact::fromPhone()`.
**Produces:** `App\Jobs\ProcessInboundEvent` (constructor `array $item`), `WaContact::touchInbound(CarbonInterface $at): void`, `WaMessage::applyStatus(string $status, CarbonInterface $at, ?string $code, ?string $title): void`.

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
}
```

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
        if ($item['phone'] === '') {
            return;
        }

        $contact = WaContact::fromPhone($item['phone']);
        $row = WaMessage::record([
            'wa_contact_id' => $contact->id,
            'meta_id' => $item['meta_id'],
            'direction' => $item['direction'],
            'type' => $item['type'],
            'body' => $item['text'],
            'template_name' => $item['template_name'],
            'template_lang' => $item['template_lang'],
            'status' => $item['direction'] === WaMessage::IN ? WaMessage::INCOMING : null,
            'sent_at' => $item['sent_at'],
            'raw' => $item['raw'],
        ]);

        if ($item['direction'] === WaMessage::IN) {
            $contact->touchInbound($item['sent_at']);
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
        $row->applyStatus($item['status'], $item['status_at'], $item['error_code'], $item['error_title']);
    }
}
```

- [ ] **Step 4: Add the two model methods.** To `WaContact`:

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

Expected: 4 passed. (`QUEUE_CONNECTION=sync` in `phpunit.xml` runs the job inline; on a real queue the same code runs in the worker.)

- [ ] **Step 7: Commit**

```bash
git add app tests/Feature/IngestRowsTest.php tests/Fixtures
git commit -m "feat: normalise webhook items into contacts, messages and receipts"
```

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
        Http::fake(['*' => Http::response(['messages' => [['id' => 'wamid.OUT9']]), 200)]);

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
        ]]), 400)]);

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
        Http::fake(['*' => Http::response(['messages' => [['id' => 'wamid.OUT10']]), 200)]);

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
        Http::fake(['*' => Http::response(['messages' => [['id' => 'wamid.OUT11']]), 200)]);
        app(WhatsAppSender::class)->sendTemplate('18479043315', 'etp_welcome_c', 'en_US');
        Http::assertSent(fn ($r) => !array_key_exists('components', $r['template']));
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
            $autoReply,
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
            $autoReply,
        );
    }

    private function post(array $payload, string $phone, array $attrs, bool $autoReply): WaMessage
    {
        $contact = WaContact::fromPhone($phone);
        $url = rtrim((string) config('whatsapp.api_base'), '/')
            . '/' . config('whatsapp.phone_id') . '/messages';
        $token = (string) config('whatsapp.token', '');

        if ($token === '') {
            return WaMessage::record($attrs + [
                'wa_contact_id' => $contact->id, 'meta_id' => null,
                'status' => WaMessage::FAILED, 'status_at' => now(),
                'error_code' => 'local', 'error_title' => 'no access token configured',
                'sent_at' => now(), 'raw' => $payload,
            ]);
        }

        try {
            $res = $this->http->withToken($token)
                ->asJson()
                ->post($url, $payload, ['timeout' => 20]);
        } catch (\Throwable $e) {
            return WaMessage::record($attrs + [
                'wa_contact_id' => $contact->id, 'meta_id' => null,
                'status' => WaMessage::FAILED, 'status_at' => now(),
                'error_code' => 'network', 'error_title' => $e->getMessage(),
                'sent_at' => now(), 'raw' => $payload,
            ]);
        }

        $json = $res->json() ?? [];
        $wamid = $json['messages'][0]['id'] ?? null;

        if ($res->failed() || $wamid === null) {
            return WaMessage::record($attrs + [
                'wa_contact_id' => $contact->id, 'meta_id' => null,
                'status' => WaMessage::FAILED, 'status_at' => now(),
                'error_code' => isset($json['error']['code']) ? (string) $json['error']['code'] : (string) $res->status(),
                'error_title' => $json['error']['error_data']['details']
                    ?? $json['error']['message'] ?? 'send rejected',
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

- [ ] **Step 4: Run the test**

```bash
php artisan test tests/Feature/WhatsAppSenderTest.php
```

Expected: 5 passed.

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
use PHPUnit\Framework\TestCase;

class OptOutKeywordsTest extends TestCase
{
    public static function keywordProvider(): array
    {
        return [
            ['stop'], ['STOP'], [' Stop '], ['stop.'], ['end'], ['cancel'],
            ['unsubscribe'], ['opt out'], ['OPT-OUT'], ['optout'], ['quit'], ['remove'],
            ['please STOP and email me'],
        ];
    }

    /** @dataProvider keywordProvider */
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

    /** @dataProvider notKeywordProvider */
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
    public const KEYWORDS = [
        'stop', 'end', 'cancel', 'unsubscribe', 'opt out', 'opt-out', 'optout', 'quit', 'remove',
    ];

    public static function matches(?string $text, ?string $buttonId = null): bool
    {
        if ($buttonId !== null && strtolower(trim($buttonId)) === 'stop') {
            return true;
        }

        $text = trim((string) $text);
        if ($text === '') {
            return false;
        }

        $first = trim(strtolower(strtok($text, "\n")));
        $first = trim($first, " \t.,!?;:'\"");
        // "please STOP and email me" -> collapse to the words around it
        $words = preg_split('/\s+/', $first) ?: [];
        foreach ($words as $word) {
            if (in_array($word, self::KEYWORDS, true)) {
                return true;
            }
        }

        return in_array($first, self::KEYWORDS, true);
    }
}
```

- [ ] **Step 4: Run it**

```bash
php artisan test tests/Unit/OptOutKeywordsTest.php
```

Expected: all data rows pass. Note the deliberate looseness: any single STOP-family word anywhere in the first line opts the contact out. A false opt-out costs one silenced client; a missed one costs a complaint against the account's quality rating.

The negative rows test whole-word discipline (`stopped`, `stopwatch`, `nonstop` must not fire), not sentence intent. "i want to stop paying taxes" **does** suppress that contact, and that is intended: suppression blocks outbound sends only, the inbound message is still stored and still visible in the thread, so a human can read it and call them. A missed opt-out has no such recovery path.

- [ ] **Step 5: Write the failing feature test** — `tests/Feature/OptOutFlowTest.php`

```php
<?php

namespace Tests\Feature;

use App\Jobs\ProcessInboundEvent;
use App\Models\WaContact;
use App\Models\WaMessage;
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
        Http::fake(['*' => Http::response(['messages' => [['id' => 'wamid.CONF1']]), 200)]);
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
        ProcessInboundEvent::dispatchSync($this->inbound('STOP', 'wamid.S1'));
        Http::fake(['*' => Http::response(['messages' => [['id' => 'wamid.CONF2']]), 200)]);
        ProcessInboundEvent::dispatchSync($this->inbound('STOP', 'wamid.S2'));

        $this->assertDatabaseMissing('wa_messages', ['meta_id' => 'wamid.CONF2']);
    }

    public function test_no_acknowledgement_is_sent_to_an_opted_out_contact()
    {
        ProcessInboundEvent::dispatchSync($this->inbound('STOP', 'wamid.S1'));
        Http::fake(['*' => Http::response(['messages' => [['id' => 'wamid.CONF3']]), 200)]);
        ProcessInboundEvent::dispatchSync($this->inbound('hello again', 'wamid.S3'));

        $this->assertDatabaseMissing('wa_messages', ['meta_id' => 'wamid.CONF3']);
        $this->assertSame(2, WaMessage::count(), 'inbound STOP + confirmation only');
    }

    public function test_confirmation_is_recorded_even_when_meta_refuses_it()
    {
        Http::fake(['*' => Http::response(['error' => ['message' => 'Re-engagement', 'code' => 131030]], 400)]);
        ProcessInboundEvent::dispatchSync($this->inbound('STOP'));

        $this->assertTrue(WaContact::where('phone', '18479043315')->firstOrFail()->isOptedOut());
        $this->assertSame(WaMessage::FAILED,
            WaMessage::where('direction', WaMessage::OUT)->firstOrFail()->status);
    }

    public function test_quick_reply_button_id_stops()
    {
        // Body text is not a keyword: only the button id can trigger this.
        $item = $this->inbound('Yes, please talk to someone', 'wamid.S4');
        $item['button_id'] = 'STOP';
        $item['type'] = 'interactive';
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
        $buttonId = $buttonId ?? ($in->raw['interactive']['button_reply']['id']
            ?? $in->raw['button']['payload'] ?? null);

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

- [ ] **Step 8: Wire the job to the responder.** In `app/Jobs/ProcessInboundEvent.php`, replace the line `// Task 7 adds the automatic-response call here.` with:

```php
            app(AutoResponder::class)->handle($row, $item['button_id']);
```

and add `use App\Services\AutoResponder;` to the imports. `$row` is the message record the same method already builds, and `button_id` is the parser field set in Task 4 — the responder takes it explicitly rather than re-deriving it from `raw`, so the quick-reply test below exercises the real path instead of matching the word "Stop" in the body by accident.

- [ ] **Step 9: Run it**

```bash
php artisan test tests/Feature/OptOutFlowTest.php
```

Expected: 5 passed.

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
use App\Models\WaMessage;
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
        Http::fake(fn () => Http::response(['messages' => [['id' => 'wamid.ACK' . uniqid()]]]));
    }

    private function inbound(string $body, string $phone = '18479043315'): array
    {
        return [
            'kind' => 'message', 'meta_id' => 'wamid.' . uniqid(), 'phone' => $phone,
            'direction' => WaMessage::IN, 'type' => 'text', 'text' => $body,
            'button_id' => null, 'template_name' => null, 'template_lang' => null,
            'sent_at' => now(), 'raw' => ['text' => ['body' => $body]],
        ];
    }

    public function test_first_inbound_of_the_day_gets_one_ack()
    {
        ProcessInboundEvent::dispatchSync($this->inbound('hello'));

        $ack = WaMessage::where('direction', WaMessage::OUT)->first();
        $this->assertNotNull($ack);
        $this->assertTrue($ack->auto_reply);
        $this->assertStringContainsString('a human from E Tax Planner', $ack->body);
    }

    public function test_later_messages_same_day_get_nothing()
    {
        ProcessInboundEvent::dispatchSync($this->inbound('hello'));
        ProcessInboundEvent::dispatchSync($this->inbound('and also?'));
        ProcessInboundEvent::dispatchSync($this->inbound('one more thing'));

        $this->assertSame(1, WaMessage::where('direction', WaMessage::OUT)->count());
    }

    public function test_a_different_contact_is_acknowledged_separately()
    {
        ProcessInboundEvent::dispatchSync($this->inbound('hello', '18479043315'));
        ProcessInboundEvent::dispatchSync($this->inbound('hello', '19014307897'));

        $this->assertSame(2, WaMessage::where('direction', WaMessage::OUT)->count());
    }

    public function test_opt_out_message_is_not_acknowledged()
    {
        ProcessInboundEvent::dispatchSync($this->inbound('STOP'));
        // only the confirmation, no ack line
        $out = WaMessage::where('direction', WaMessage::OUT)->get();
        $this->assertCount(1, $out);
        $this->assertStringContainsString('No more messages', $out->first()->body);
    }

    public function test_empty_ack_text_disables_the_rule()
    {
        config(['whatsapp.ack_text' => '']);
        ProcessInboundEvent::dispatchSync($this->inbound('hello'));
        $this->assertSame(0, WaMessage::where('direction', WaMessage::OUT)->count());
    }
}
```

- [ ] **Step 2: Run it** — expected FAIL (0 outbound rows found).

- [ ] **Step 3: Extend `AutoResponder`** — replace the trailing `if ($contact->isOptedOut()) { return; }` in `handle()` with the acknowledgement rule, and add the imports `use Illuminate\Support\Facades\Cache;` and `use Carbon\CarbonInterface;`:

```php
        if ($contact->isOptedOut()) {
            return;
        }

        $this->acknowledge($contact, $in->sent_at);
    }

    private function acknowledge(\App\Models\WaContact $contact, CarbonInterface $at): void
    {
        $text = (string) config('whatsapp.ack_text', '');
        if ($text === '') {
            return;
        }

        $hours = (int) config('whatsapp.window_hours', 24);

        $alreadyTalking = WaMessage::where('wa_contact_id', $contact->id)
            ->where('direction', WaMessage::IN)
            ->where('sent_at', '>=', $at->copy()->subHours($hours))
            ->where('sent_at', '<', $at->copy()->addSecond())
            ->exists();
        if ($alreadyTalking) {
            return;
        }

        // Atomic add: two callbacks in the same second cannot both send.
        if (!Cache::add('wa-ack-' . $contact->id, 1, now()->addHours($hours))) {
            return;
        }

        $this->sender->sendText($contact->phone, $text, autoReply: true);
    }
```

- [ ] **Step 4: Run it**

```bash
php artisan test tests/Feature/AckFlowTest.php
```

Expected: 5 passed.

- [ ] **Step 5: Run the whole suite**

```bash
php artisan test
```

Expected: all passed, no warnings.

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

Route::get('/login', [LoginController::class, 'show'])->name('login');
Route::post('/login', [LoginController::class, 'store']);
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

Expected: 7 passed.

- [ ] **Step 9: Create the real staff account locally** (password is typed at the prompt, never in chat or in a file):

```bash
php artisan wa:create-user staff@etaxplanner.com
```

- [ ] **Step 10: Commit**

```bash
git add app resources routes/web.php bootstrap/app.php tests/Feature/AuthTest.php
git commit -m "feat: staff login, logout and one seeded account"
```

---

## Task 10: Machine endpoints — suppression list and send records

**Deliverable:** the bench can fetch who has opted out and can report what it sent, both behind a bearer token that fails closed when the token is not configured.

**Consumes:** `WaContact`, `WaMessage`, `config('whatsapp.export_token')`.
**Produces:** `GET /wa/suppressions` → `text/plain`, one opted-out E.164 number per line, ascending, no trailing blank; `POST /inbound/record-send` → `201` with `{"recorded":true,"contact_id":N}`; middleware alias `wa.bearer`; `App\Http\Middleware\VerifyWaBearer`.

- [ ] **Step 1: Write the failing test** — `tests/Feature/MachineEndpointTest.php`

```php
<?php

namespace Tests\Feature;

use App\Models\WaContact;
use App\Models\WaMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
use Illuminate\Validation\Rule;

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

        $contact = WaContact::fromPhone($data['to']);
        $sentAt = isset($data['utc_time']) && \Illuminate\Support\Carbon::hasFormat($data['utc_time'], 'Y-m-d\TH:i:s\Z')
            ? \Illuminate\Support\Carbon::parse($data['utc_time'], 'UTC')
            : now();

        $message = WaMessage::record([
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

        return response()->json(['recorded' => true, 'contact_id' => $contact->id,
                                 'message_id' => $message->id], 201);
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

Expected: 8 passed.

- [ ] **Step 7: Commit**

```bash
git add app routes/web.php bootstrap/app.php tests/Feature/MachineEndpointTest.php
git commit -m "feat: bearer-guarded suppression list and bench send records"
```

---

## Task 11: Inbox views

**Deliverable:** a signed-in user sees the contact list with opt-out and window state, and a thread that shows inbound on the left, outbound on the right, marks machine sends as automatic, and shows the failure reason for refused sends.

**Consumes:** `WaContact::display_name()`, `windowOpen()`, `isOptedOut()`, `WaMessage` rows.
**Produces:** `App\Http\Controllers\InboxController::contacts(Request)` and `::thread(WaContact)`; views `inbox/contacts.blade.php`, `inbox/thread.blade.php`; the first `/inbox` routes, added inside the `auth` group Task 9 created.

- [ ] **Step 1: Write the failing test** — `tests/Feature/InboxViewTest.php`

```php
<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\WaContact;
use App\Models\WaMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InboxViewTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
    }

    public function test_list_shows_name_number_and_state()
    {
        $talking = WaContact::factory()->create(['phone' => '18479043315', 'profile_name' => 'Jane Tester']);
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
use Illuminate\View\Factory;

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
            'messages' => $contact->messages()->with('contact')->get(),
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
<div class="muted">{{ $contacts->links() }}</div>
@endsection
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

Expected: 5 passed. The last test relies on implicit route binding: because `thread()` type-hints `WaContact`, Laravel aborts with 404 for an id that does not exist, which is the behavior being asserted.

- [ ] **Step 8: Commit**

```bash
git add app/Http/Controllers/InboxController.php resources/views/inbox routes/web.php tests/Feature/InboxViewTest.php
git commit -m "feat: contact list and thread views"
```

---

## Task 12: Reply, template send, manual opt-out

**Deliverable:** staff can reply while the window is open, send an approved template when it is not, and silence a contact from the thread. A closed window is refused before the API is called, and nothing at all can be sent to an opted-out contact.

**Consumes:** `WhatsAppSender` (Task 6), `WaContact::windowOpen()` / `optOut()` (Task 2), views from Task 11.
**Produces:** `POST /inbox/{contact}/reply`, `POST /inbox/{contact}/template`, `POST /inbox/{contact}/opt-out`.

- [ ] **Step 1: Write the failing test** — `tests/Feature/InboxActionsTest.php`

```php
<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\WaContact;
use App\Models\WaMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
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
        Http::fake(['*' => Http::response(['messages' => [['id' => 'wamid.STAFF1']]), 200)]);
    }

    public function test_reply_inside_the_window_is_sent_and_stored()
    {
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
        Http::fake();
        Http::preventStrayRequests();

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
        Http::fake();
        Http::preventStrayRequests();
        $this->post('/inbox/' . $this->contact->id . '/template',
            ['template' => 'made_up_template', 'language' => 'en', 'variables' => ''])->assertSessionHasErrors('template');
        $this->assertSame(0, WaMessage::where('direction', WaMessage::OUT)->count());
    }

    public function test_nothing_can_be_sent_to_an_opted_out_contact()
    {
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
    }

    public function test_a_guest_cannot_post_a_reply()
    {
        auth()->logout();
        $this->post('/inbox/' . $this->contact->id . '/reply', ['body' => 'x'])->assertRedirect('/login');
    }
}
```

- [ ] **Step 2: Run it** — expected FAIL 405 for all three POSTs.

- [ ] **Step 3: Add the three actions to `InboxController`**, plus imports `use App\Services\WhatsAppSender;`, `use Illuminate\Support\Carbon;`, `use Illuminate\Validation\Rule;`:

```php
    public function reply(Request $request, WaContact $contact, WhatsAppSender $sender)
    {
        $data = $request->validate(['body' => ['required', 'string', 'max:4096']]);

        if ($blocked = $this->blockReason($contact)) {
            return back()->withInput()->withErrors(['window' => $blocked]);
        }

        $message = $sender->sendText($contact->phone, trim($data['body']));

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

        if ($blocked = $this->blockReason($contact, templateAllowed: true)) {
            return back()->withInput()->withErrors(['window' => $blocked]);
        }

        $variables = array_values(array_filter(array_map('trim',
            explode(',', (string) ($data['variables'] ?? '')))));

        $message = $sender->sendTemplate($contact->phone, $data['template'], $data['language'], $variables);

        return back()->with('result', $message->status === WaMessage::FAILED
            ? 'Meta refused (' . $message->error_code . '): ' . $message->error_title
            : 'Template sent.');
    }

    public function optOut(WaContact $contact)
    {
        $contact->optOut('manual');

        return back()->with('result', 'Marked opted out. No further messages will be sent to this number.');
    }

    private function blockReason(WaContact $contact, bool $templateAllowed = false): ?string
    {
        if ($contact->isOptedOut()) {
            return 'This contact has opted out. Nothing may be sent to this number.';
        }

        if (!$templateAllowed && !$contact->windowOpen()) {
            return 'The 24-hour reply window is closed. Send an approved template instead.';
        }

        return null;
    }
```

- [ ] **Step 4: Register the POST routes** inside the `auth` group in `routes/web.php`:

```php
    Route::post('/inbox/{contact}/reply', [InboxController::class, 'reply'])->name('inbox.reply');
    Route::post('/inbox/{contact}/template', [InboxController::class, 'template'])->name('inbox.template');
    Route::post('/inbox/{contact}/opt-out', [InboxController::class, 'optOut'])->name('inbox.optout');
```

- [ ] **Step 5: Show the flash result in the thread view.** In `resources/views/inbox/thread.blade.php`, immediately after the `<h2 ...>` line add:

```blade
@if (session('result'))
  <div class="ok">{{ session('result') }}</div>
@endif
```

- [ ] **Step 6: Run the test**

```bash
php artisan test tests/Feature/InboxActionsTest.php
```

Expected: 8 passed.

- [ ] **Step 7: Run the whole suite**

```bash
php artisan test
```

Expected: all green. Fix any regression in this task rather than leaving it for later.

- [ ] **Step 8: Commit**

```bash
git add app/Http/Controllers/InboxController.php resources/views/inbox/thread.blade.php routes/web.php tests/Feature/InboxActionsTest.php
git commit -m "feat: staff reply, template send and manual opt-out"
```

## Task 13: Import the existing send log

**Deliverable:** `wa:import-send-log` seeds `wa_contacts` and `wa_messages` from the bench's `send_log.csv`, is dry-run unless `--apply`, and running it twice changes nothing.

**Consumes:** `WaContact::fromPhone()`, `WaMessage::record()`.
**Produces:** `php artisan wa:import-send-log {path} {--apply}` printing counts; refused rows are counted as skipped and never become messages.

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

        $contacts = $messages = $skipped = 0;
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

            $contacts++;
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
                'status' => WaMessage::SENT,
                'sent_at' => $sentAt,
                'raw' => ['source' => 'send_log_import'],
            ]);
        }
        fclose($handle);

        $this->line($apply ? "Imported {$messages} messages, {$contacts} contacts, {$skipped} skipped."
                           : "DRY RUN: would import {$messages} messages, {$contacts} contacts, {$skipped} skipped.");
        if (!$apply) {
            $this->line('Re-run with --apply to write.');
        }

        return 0;
    }
}
```

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
        headers = {k.lower(): v for r in seen for k, v in r.header_items()}
        self.assertEqual(headers.get("authorization"), "Bearer tok")

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

    def test_allow_list_still_wins_over_the_suppression_list(self):
        verdict, why = s.check_gates("19998887777", {US2: "US test 2"}, set(), set(), set())
        self.assertEqual(verdict, "refused")
        self.assertIn("test_numbers.txt", why)
```

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

The default keeps the signature backward compatible, and the allow-list and length checks stay ahead of the suppression check so a refused number never reaches the inbox call in the message text.

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

- [ ] **Step 6: Record the outcome** in `docs/DEPLOY.md` — the eight lines above, each marked pass or fail with what was seen, then commit:

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



