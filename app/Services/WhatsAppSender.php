<?php

namespace App\Services;

use App\Models\WaContact;
use App\Models\WaMessage;
use Illuminate\Http\Client\Factory;

class WhatsAppSender
{
    public function __construct(private Factory $http)
    {
    }

    public function sendText(string $phone, string $text, bool $autoReply = false): WaMessage
    {
        return $this->post(
            ['messaging_product' => 'whatsapp', 'to' => $phone, 'type' => 'text',
             'text' => ['preview_url' => false, 'body' => $text]],
            $phone,
            ['direction' => WaMessage::OUT, 'type' => 'text', 'body' => $text,
             'auto_reply' => $autoReply],
            $autoReply,
        );
    }

    public function sendTemplate(string $phone, string $name, string $lang,
                                 array $variables = [], bool $autoReply = false): WaMessage
    {
        $components = [];
        $image = (string) (config('whatsapp.header_images')[$name] ?? '');
        if ($image !== '') {
            $components[] = ['type' => 'header', 'parameters' => [
                ['type' => 'image', 'image' => ['link' => $image]]]];
        }
        if ($variables !== []) {
            $components[] = ['type' => 'body', 'parameters' => array_map(
                fn ($v) => ['type' => 'text', 'text' => (string) $v], $variables)];
        }

        $template = ['name' => $name, 'language' => ['code' => $lang]];
        if ($components !== []) {
            $template['components'] = $components;
        }

        return $this->post(
            ['messaging_product' => 'whatsapp', 'to' => $phone, 'type' => 'template',
             'template' => $template],
            $phone,
            ['direction' => WaMessage::OUT, 'type' => 'template', 'template_name' => $name,
             'template_lang' => $lang, 'auto_reply' => $autoReply],
            $autoReply,
        );
    }

    private static function str(mixed $v, int $max): string
    {
        $s = match (true) {
            is_string($v) => $v,
            is_int($v) || is_float($v) => (string) $v,
            default => '',
        };

        return mb_substr($s, 0, $max);
    }

    private function post(array $payload, string $phone, array $attrs, bool $autoReply): WaMessage
    {
        // R35: the contact row and the payload have to agree. fromPhone() normalises internally, so
        // the first draft stored digits while sending the caller's punctuation -- Meta answers that
        // with a 400 for a contact we filed cleanly. Normalising once, here, is the R32 rule pointed
        // at the outbound direction.
        $phone = WaContact::digitsOnly($phone);
        $payload['to'] = $phone;

        $contact = WaContact::fromPhone($phone);
        $token = (string) config('whatsapp.token', '');
        $phoneId = (string) config('whatsapp.phone_id', '');

        // R35: an unconfigured phone number id is the same kind of "we do not know who is sending" as
        // an unconfigured token, and without this guard the app still makes an authenticated HTTPS
        // request to .../v20.0//messages. Nothing goes out when either is missing.
        if ($token === '' || $phoneId === '') {
            return WaMessage::record($attrs + [
                'wa_contact_id' => $contact->id, 'meta_id' => null,
                'status' => WaMessage::FAILED, 'status_at' => now(),
                'error_code' => 'local',
                'error_title' => $token === '' ? 'no access token configured' : 'no phone number id configured',
                'sent_at' => now(), 'raw' => $payload,
            ]);
        }

        $url = rtrim((string) config('whatsapp.api_base'), '/') . '/' . $phoneId . '/messages';

        try {
            // R33: ->timeout() is the option. post() is declared post(string $url, $data = [])
            // (vendor PendingRequest:888), so a third argument is accepted by PHP and thrown away --
            // the first draft looked like it waited 20 seconds and actually waited Laravel's own
            // default of 30 (vendor PendingRequest:269).
            $res = $this->http->withToken($token)->asJson()->timeout(20)->post($url, $payload);
        } catch (\Throwable $e) {
            return WaMessage::record($attrs + [
                'wa_contact_id' => $contact->id, 'meta_id' => null,
                'status' => WaMessage::FAILED, 'status_at' => now(),
                'error_code' => 'network', 'error_title' => self::str($e->getMessage(), 255),
                'sent_at' => now(), 'raw' => $payload,
            ]);
        }

        // R34: Meta's response is external input too -- in shape, in presence and in range -- and
        // every value below lands in a bounded column. data_get() is the null-safe reader (measured:
        // a scalar `messages`, a null body and a missing key all return null with no warning), and
        // str() is the width. `(string) ['a']` would have emitted `Warning: Array to string
        // conversion`, which HandleExceptions::handleError (vendor:75-77) turns into an ErrorException
        // in every environment -- i.e. the sender throwing at its caller, which the deliverable
        // forbids -- and a 400-character `error.message` into error_title(255) is the 1406 that
        // sqlite lets the suite forget about (the round-1 review measured both widths on MariaDB).
        $json = $res->json() ?? [];
        $wamid = self::str(data_get($json, 'messages.0.id'), 128);

        if ($res->failed() || $wamid === '') {
            $code = self::str(data_get($json, 'error.code'), 12);
            $title = self::str(data_get($json, 'error.error_data.details')
                ?? data_get($json, 'error.message'), 255);

            return WaMessage::record($attrs + [
                'wa_contact_id' => $contact->id, 'meta_id' => null,
                'status' => WaMessage::FAILED, 'status_at' => now(),
                'error_code' => $code === '' ? (string) $res->status() : $code,
                'error_title' => $title === '' ? 'send rejected' : $title,
                'sent_at' => now(), 'raw' => $payload,
            ]);
        }

        return WaMessage::record($attrs + [
            'wa_contact_id' => $contact->id, 'meta_id' => $wamid,
            'status' => WaMessage::SENT, 'status_at' => now(),
            'sent_at' => now(), 'raw' => $payload,
        ]);
    }
}
