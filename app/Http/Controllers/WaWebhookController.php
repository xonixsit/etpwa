<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;

class WaWebhookController extends Controller
{
    public function verify(Request $request): Response
    {
        $token = (string) config('whatsapp.verify_token', '');
        $sent = (string) ($request->query('hub.verify_token')
            ?? $request->query('hub_verify_token'));

        if ($token === '' || !hash_equals($token, $sent)) {
            return response('forbidden', 403);
        }

        return response((string) ($request->query('hub.challenge')
            ?? $request->query('hub_challenge')), 200);
    }
}
