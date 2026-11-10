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
        $this->assertCount(1, WaMessage::where('meta_id', 'wamid.OUT1')->get(),
            'the echo must update the sender row, never add a second one');
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
}
