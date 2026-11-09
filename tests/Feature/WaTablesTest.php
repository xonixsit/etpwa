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