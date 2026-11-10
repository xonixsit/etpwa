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
