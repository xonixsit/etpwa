<?php

namespace App\Http\Controllers;

use App\Models\WaContact;
use App\Models\WaMessage;
use Illuminate\Http\Request;

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
}
