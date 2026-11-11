<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessInboundEvent;
use App\Models\WaWebhookEvent;
use App\Support\WebhookParser;
use App\Support\WebhookSignature;
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

    public function ingest(Request $request): Response
    {
        $raw = $request->getContent();
        $secret = (string) config('whatsapp.app_secret', '');

        if (!WebhookSignature::isValid($raw, $request->header('X-Hub-Signature-256'), $secret)) {
            return response('forbidden', 403);
        }

        $payload = json_decode($raw, true);
        if (!is_array($payload)) {
            WaWebhookEvent::store(null, null, ['body' => mb_substr($raw, 0, 2000)],
                WaWebhookEvent::IGNORED, hash('sha256', $raw));

            return $this->accepted();
        }

        $parsed = WebhookParser::parse($payload, (string) config('whatsapp.phone_id', ''));
        $hash = hash('sha256', $raw);
        $entryId = $parsed['entry_id'];
        $ours = (string) config('whatsapp.phone_id', '');
        $numberFor = $parsed['matched'] ? $ours : $parsed['phone_number_id'];

        if (WaWebhookEvent::seenBefore($hash)) {
            WaWebhookEvent::store($numberFor, $entryId, $parsed['items'],
                WaWebhookEvent::DUPLICATE, $hash);

            return $this->accepted();
        }

        if (!$parsed['matched']) {
            WaWebhookEvent::store($numberFor, $entryId, $parsed['items'],
                WaWebhookEvent::IGNORED, $hash);

            return $this->accepted();
        }

        WaWebhookEvent::store($ours, $entryId, $parsed['items'], WaWebhookEvent::STORED, $hash);

        foreach ($parsed['items'] as $item) {
            $this->dispatch($item);
        }

        return $this->accepted();
    }

    private function accepted(): Response
    {
        return response('', 200);
    }

    protected function dispatch(array $item): void
    {
        ProcessInboundEvent::dispatch($item);
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
