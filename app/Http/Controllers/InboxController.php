<?php

namespace App\Http\Controllers;

use App\Models\WaContact;
use App\Models\WaMessage;
use App\Services\SuppressedContactException;
use App\Services\WhatsAppSender;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class InboxController extends Controller
{
    public function contacts(Request $request)
    {
        $q = trim((string) $request->query('q', ''));

        $contacts = WaContact::query()
            ->when($q !== '', function ($query) use ($q) {
                $query->where(function ($inner) use ($q) {
                    $inner->where('phone', 'like', '%' . $q . '%')
                          ->orWhere('profile_name', 'like', '%' . $q . '%');
                });
            })
            ->withCount(['messages as inbound_count' => fn ($q2) => $q2->where('direction', WaMessage::IN)])
            ->withCount(['messages as outbound_count' => fn ($q2) => $q2->where('direction', WaMessage::OUT)])
            ->orderByRaw('coalesce(last_inbound_at, created_at) desc')
            ->orderByDesc('id')
            ->paginate(30)
            ->withQueryString();

        return view('inbox.contacts', ['contacts' => $contacts, 'q' => $q]);
    }

    public function thread(WaContact $contact)
    {
        return view('inbox.thread', [
            'contact' => $contact,
            'messages' => $contact->messages()->get(),
            'choices' => config('whatsapp.template_choices'),
        ]);
    }

    public function reply(Request $request, WaContact $contact, WhatsAppSender $sender)
    {
        $data = $request->validate(['body' => ['required', 'string', 'max:4096']]);

        if (!$contact->windowOpen()) {
            return back()->withInput()->withErrors(['window' => 'The 24-hour reply window is closed. Send an approved template instead.']);
        }

        try {
            $message = $sender->sendText($contact->phone, trim($data['body']));
        } catch (SuppressedContactException $e) {
            return back()->withInput()->withErrors(['window' => $e->getMessage()]);
        }

        return back()->with('result', $message->status === WaMessage::FAILED
            ? 'Meta refused: ' . $message->error_title
            : 'Sent.');
    }

    public function template(Request $request, WaContact $contact, WhatsAppSender $sender)
    {
        $data = $request->validate([
            'template' => ['required', 'string', Rule::in(config('whatsapp.template_choices'))],
            'language' => ['required', 'string', 'max:10'],
            'variables' => ['nullable', 'string', 'max:500'],
        ]);

        $variables = array_values(array_filter(array_map('trim',
            explode(',', (string) ($data['variables'] ?? '')))));

        try {
            $message = $sender->sendTemplate($contact->phone, $data['template'], $data['language'], $variables);
        } catch (SuppressedContactException $e) {
            return back()->withInput()->withErrors(['window' => $e->getMessage()]);
        }

        return back()->with('result', $message->status === WaMessage::FAILED
            ? 'Meta refused (' . $message->error_code . '): ' . $message->error_title
            : 'Template sent.');
    }

    public function optOut(WaContact $contact)
    {
        $contact->optOut('manual');

        return back()->with('result', 'Marked opted out. No further messages will be sent to this number.');
    }
}
