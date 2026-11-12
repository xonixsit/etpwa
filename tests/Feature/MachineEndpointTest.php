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
