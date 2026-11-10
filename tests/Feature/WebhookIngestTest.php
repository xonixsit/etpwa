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
}
