<?php

namespace App\Http\Controllers;

use App\Models\WaContact;
use App\Models\WaMessage;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class MachineController extends Controller
{
    public function suppressions()
    {
        $numbers = WaContact::whereNotNull('opted_out_at')
            ->orderBy('phone')
            ->pluck('phone')
            ->implode("\n");

        return response($numbers, 200)->header('Content-Type', 'text/plain; charset=utf-8');
    }

    public function recordSend(Request $request)
    {
        $data = $request->validate([
            'to' => ['required', 'string', 'min:10', 'max:20'],
            'template' => ['required', 'string', 'max:120'],
            'language' => ['required', 'string', 'max:10'],
            'message_id' => ['required', 'string', 'max:128'],
            'utc_time' => ['nullable', 'string'],
        ]);

        // Validation measures the raw string, isDialable() measures the digits after the strip, so a ten
        // character "to" with no digits in it survives validate() and lands in fromPhone(), which throws.
        // Measured: an uncaught InvalidArgumentException renders a 500 here, so the bench gets a server
        // error for a body it could fix. Reject it as a 422 through WaContact's own predicate instead --
        // one definition of "dialable", no duplicated length literals.
        if (!WaContact::isDialable(WaContact::digitsOnly($data['to']))) {
            throw ValidationException::withMessages([
                'to' => 'The to field must contain a dialable phone number.',
            ]);
        }

        $contact = WaContact::fromPhone($data['to']);
        $sentAt = isset($data['utc_time']) && \Illuminate\Support\Carbon::hasFormat($data['utc_time'], 'Y-m-d\TH:i:s\Z')
            ? \Illuminate\Support\Carbon::parse($data['utc_time'], 'UTC')
            : now();

        WaMessage::record([
            'wa_contact_id' => $contact->id,
            'meta_id' => $data['message_id'],
            'direction' => WaMessage::OUT,
            'type' => 'template',
            'template_name' => $data['template'],
            'template_lang' => $data['language'],
            'status' => WaMessage::SENT,
            'status_at' => $sentAt,
            'sent_at' => $sentAt,
            'raw' => ['source' => 'send_bench'],
        ]);

        return response()->json(['recorded' => true, 'contact_id' => $contact->id], 201);
    }
}
