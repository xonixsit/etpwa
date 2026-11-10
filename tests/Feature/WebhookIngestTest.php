<?php

namespace Tests\Feature;

use App\Models\WaContact;
use App\Models\WaMessage;
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
}
