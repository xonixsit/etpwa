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
