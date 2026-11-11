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

    public function test_event_row_stores_and_decrypts_changes()
    {
        $e = WaWebhookEvent::store('1427706937088002', 'E1', ['x' => 1], WaWebhookEvent::STORED);
        $this->assertSame(['x' => 1], $e->fresh()->changes);
        $this->assertSame(WaWebhookEvent::STORED, $e->status);
    }
}