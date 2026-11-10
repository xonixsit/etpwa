<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;

class WaWebhookController extends Controller
{
    public function verify(Request $request): Response
    {
        $token = (string) config('whatsapp.verify_token', '');
        $sent = $this->param($request, 'hub.verify_token', 'hub_verify_token');

        if ($token === '' || !hash_equals($token, $sent)) {
            return response('forbidden', 403);
        }

        $challenge = $this->param($request, 'hub.challenge', 'hub_challenge');

        if ($challenge === '') {
            return response('forbidden', 403);
        }

        return response($challenge, 200);
    }

    /**
     * Meta spells these parameters with a dot; PHP rewrites every
     * non-alphanumeric byte in a query-string key to an underscore before Laravel
     * sees it, so both spellings must be read and the dotted one wins when present.
     * Do not collapse this to one lookup (Ruling R16). A non-string is treated as
     * absent so that array-shaped query input is refused rather than fatal
     * (Ruling R17).
     */
    private function param(Request $request, string $dotted, string $underscore): string
    {
        $value = $request->query($dotted) ?? $request->query($underscore);

        return is_string($value) ? $value : '';
    }
}
