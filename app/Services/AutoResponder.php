<?php

namespace App\Services;

use App\Models\WaContact;
use App\Models\WaMessage;
use App\Support\OptOutKeywords;
use Illuminate\Support\Facades\Cache;

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

        if (OptOutKeywords::matches($in->body, $buttonId)) {
            $wasSilenced = $contact->isOptedOut();
            $contact->optOut('inbound_keyword');
            $text = (string) config('whatsapp.opt_out_text', '');
            if (!$wasSilenced && $text !== '') {
                // D1: the ONE exemption to the sender's suppression gate -- the confirmation the
                // suppression regime owes the contact after their own STOP.
                $this->sender->sendText($contact->phone, $text, autoReply: true, allowSuppressed: true);
            }

            return;
        }

        $this->acknowledge($contact, $in);
    }

    private function acknowledge(WaContact $contact, WaMessage $in): void
    {
        $text = (string) config('whatsapp.ack_text', '');
        if ($text === '') {
            return;
        }

        $hours = (int) config('whatsapp.window_hours', 24);
        $at = $in->sent_at;

        // R46: exclude the row this job just wrote. ProcessInboundEvent stores the inbound before it
        // calls the responder, so without this the window query finds the message's own row and no ack
        // is ever sent. Measured, not reasoned about.
        $alreadyTalking = WaMessage::where('wa_contact_id', $contact->id)
            ->where('direction', WaMessage::IN)
            ->where('id', '!=', $in->id)
            ->where('sent_at', '>=', $at->copy()->subHours($hours))
            ->where('sent_at', '<', $at->copy()->addSecond())
            ->exists();
        if ($alreadyTalking) {
            return;
        }

        // The race guard. Atomic on the production store, not on the test store -- see R46.
        if (!Cache::add('wa-ack-' . $contact->id, 1, now()->addHours($hours))) {
            return;
        }

        // D1/F5b: the sender's gate is the single enforcement point for suppression; this catch is the
        // single translation point. The pre-check that used to sit in handle() is gone -- keeping it
        // would make this catch un-witnessable (R51 X01 joint redundancy). ProcessInboundEvent has no
        // try/catch and $tries = 3, so an escaping throw would retry the job three times. Known side
        // effect: an opted-out contact consumes the R46 cache key above before the gate refuses;
        // harmless, no send, and the key expires with the window.
        try {
            $this->sender->sendText($contact->phone, $text, autoReply: true);
        } catch (SuppressedContactException) {
            return;
        }
    }
}
