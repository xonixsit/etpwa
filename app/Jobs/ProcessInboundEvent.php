<?php

namespace App\Jobs;

use App\Models\WaContact;
use App\Models\WaMessage;
use App\Services\AutoResponder;
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
            // R31: an unusable timestamp is not evidence of a reply time, and last_inbound_at is
            // what opens the service window, so the contact is left alone. The row above still
            // gets now(), because wa_messages.sent_at is NOT NULL.
            if ($item['sent_at'] !== null) {
                $contact->touchInbound($item['sent_at']);
            }
            app(AutoResponder::class)->handle($row, $item['button_id']);
        }
    }

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
}
