<?php

namespace App\Support;

use App\Models\WaContact;
use Carbon\Carbon;
use Carbon\CarbonInterface;

class WebhookParser
{
    /**
     * @return array{matched: bool, phone_number_id: ?string, items: array<int, array<string, mixed>>}
     */
    public static function parse(array $payload, string $phoneId): array
    {
        $items = [];
        $matched = false;
        $seen = null;

        foreach ($payload['entry'] ?? [] as $entry) {
            foreach ($entry['changes'] ?? [] as $change) {
                $value = $change['value'] ?? [];
                $numberId = $value['metadata']['phone_number_id'] ?? null;
                if (!is_string($numberId) || $numberId !== $phoneId) {
                    $seen = $seen ?? $numberId;
                    continue;
                }
                $matched = true;

                foreach ($value['contacts'] ?? [] as $contact) {
                    $phone = WaContact::digitsOnly($contact['wa_id'] ?? '');
                    $name = $contact['profile']['name'] ?? null;
                    if ($phone !== '' && is_string($name) && $name !== '') {
                        $items[] = ['kind' => 'profile', 'phone' => $phone, 'name' => $name, 'raw' => $contact];
                    }
                }

                foreach ($value['messages'] ?? [] as $message) {
                    $items[] = self::message($message);
                }

                foreach ($value['statuses'] ?? [] as $status) {
                    $items[] = self::status($status);
                }
            }
        }

        $items = array_values(array_filter($items));

        return ['matched' => $matched, 'phone_number_id' => $seen, 'items' => $items];
    }

    private static function message(array $m): array
    {
        $phone = WaContact::digitsOnly(
            ($m['from'] ?? null) === config('whatsapp.my_number') ? ($m['to'] ?? '') : ($m['from'] ?? '')
        );

        return [
            'kind' => 'message',
            'meta_id' => $m['id'] ?? null,
            'phone' => $phone,
            'direction' => ($m['from'] ?? null) === config('whatsapp.my_number')
                ? \App\Models\WaMessage::OUT : \App\Models\WaMessage::IN,
            'type' => $m['type'] ?? 'unknown',
            'text' => $m['text']['body'] ?? $m['button']['text'] ?? $m['interactive']['name'] ?? null,
            'button_id' => $m['interactive']['button_reply']['id']
                ?? $m['interactive']['list_reply']['id']
                ?? $m['button']['payload'] ?? null,
            'template_name' => $m['template']['name'] ?? null,
            'template_lang' => $m['template']['language']['code'] ?? null,
            'sent_at' => self::ts($m['timestamp'] ?? null),
            'raw' => $m,
        ];
    }

    private static function status(array $s): array
    {
        return [
            'kind' => 'status',
            'meta_id' => $s['id'] ?? null,
            'phone' => WaContact::digitsOnly($s['recipient_id'] ?? ''),
            'status' => $s['status'] ?? 'unknown',
            'error_code' => isset($s['errors'][0]['code']) ? (string) $s['errors'][0]['code'] : null,
            'error_title' => isset($s['errors'][0]['title']) ? (string) $s['errors'][0]['title'] : null,
            'status_at' => self::ts($s['timestamp'] ?? null),
            'raw' => $s,
        ];
    }

    private static function ts(mixed $epoch): CarbonInterface
    {
        return is_numeric($epoch)
            ? Carbon::createFromTimestamp((int) $epoch, 'UTC')
            : now();
    }
}
