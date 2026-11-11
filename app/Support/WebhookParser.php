<?php

namespace App\Support;

use App\Models\WaContact;
use Carbon\Carbon;
use Carbon\CarbonInterface;

class WebhookParser
{
    /**
     * @return array{matched: bool, entry_id: ?string, phone_number_id: ?string, items: array<int, array<string, mixed>>}
     */
    public static function parse(array $payload, string $phoneId): array
    {
        $items = [];
        $matched = false;
        $seen = null;
        $entryId = null;

        foreach (self::arr($payload['entry'] ?? null) as $entry) {
            $entry = self::arr($entry);

            if ($entryId === null) {
                $entryId = self::nullableStr($entry['id'] ?? null, 64);
            }

            foreach (self::arr($entry['changes'] ?? null) as $change) {
                $value = self::arr(self::arr($change)['value'] ?? null);
                $numberId = self::str(self::path($value, 'metadata', 'phone_number_id'), 64);

                if ($phoneId === '' || $numberId !== $phoneId) {
                    if ($seen === null && $numberId !== '') {
                        $seen = $numberId;
                    }

                    continue;
                }

                $matched = true;

                foreach (self::arr($value['contacts'] ?? null) as $contact) {
                    $items[] = self::profile($contact);
                }

                foreach (self::arr($value['messages'] ?? null) as $message) {
                    $items[] = self::message($message);
                }

                foreach (self::arr($value['statuses'] ?? null) as $status) {
                    $items[] = self::status($status);
                }
            }
        }

        return [
            'matched' => $matched,
            'entry_id' => $entryId,
            'phone_number_id' => $seen,
            'items' => array_values(array_filter($items)),
        ];
    }

    private static function profile(mixed $raw): ?array
    {
        $contact = self::arr($raw);
        $phone = WaContact::digitsOnly(self::str($contact['wa_id'] ?? null));
        $name = self::nullableStr(self::path($contact, 'profile', 'name'), 255);

        if (!WaContact::isDialable($phone) || $name === null) {
            return null;
        }

        return ['kind' => 'profile', 'phone' => $phone, 'name' => $name, 'raw' => $contact];
    }

    private static function message(mixed $raw): ?array
    {
        $m = self::arr($raw);
        $mine = WaContact::digitsOnly(self::str(config('whatsapp.my_number')));
        $from = self::str($m['from'] ?? null);
        $isOut = $mine !== '' && $from === $mine;
        $phone = WaContact::digitsOnly($isOut ? self::str($m['to'] ?? null) : $from);

        if (!WaContact::isDialable($phone)) {
            return null;
        }

        return [
            'kind' => 'message',
            'meta_id' => self::nullableStr($m['id'] ?? null, 128),
            'phone' => $phone,
            'direction' => $isOut ? \App\Models\WaMessage::OUT : \App\Models\WaMessage::IN,
            'type' => self::str($m['type'] ?? null, 24) ?: 'unknown',
            'text' => self::nullableStr(self::path($m, 'text', 'body'))
                ?? self::nullableStr(self::path($m, 'button', 'text'))
                ?? self::nullableStr(self::path($m, 'interactive', 'name')),
            'button_id' => self::nullableStr(self::path($m, 'interactive', 'button_reply', 'id'))
                ?? self::nullableStr(self::path($m, 'interactive', 'list_reply', 'id'))
                ?? self::nullableStr(self::path($m, 'button', 'payload')),
            'template_name' => self::nullableStr(self::path($m, 'template', 'name'), 255),
            'template_lang' => self::nullableStr(self::path($m, 'template', 'language', 'code'), 10),
            'sent_at' => self::ts($m['timestamp'] ?? null),
            'raw' => $m,
        ];
    }

    private static function status(mixed $raw): ?array
    {
        $s = self::arr($raw);
        $phone = WaContact::digitsOnly(self::str($s['recipient_id'] ?? null));

        if (!WaContact::isDialable($phone)) {
            return null;
        }

        return [
            'kind' => 'status',
            'meta_id' => self::nullableStr($s['id'] ?? null, 128),
            'phone' => $phone,
            'status' => self::str($s['status'] ?? null, 24) ?: 'unknown',
            'error_code' => self::nullableStr(self::path($s, 'errors', 0, 'code'), 12),
            'error_title' => self::nullableStr(self::path($s, 'errors', 0, 'title'), 255),
            'status_at' => self::ts($s['timestamp'] ?? null),
            'raw' => $s,
        ];
    }

    /**
     * List into an array, or nothing at all. A scalar where Meta puts a list is not
     * a callback we can walk: foreach over it is a Warning, and Laravel turns
     * Warnings into ErrorException, which is a 500 and therefore a dropped
     * subscription.
     */
    private static function arr(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }

    /**
     * Read a nested key without ever offsetting a scalar. `text` arriving as the
     * string "hi" makes `$m['text']['body']` a Warning in PHP 8, so nested reads
     * have to stop as soon as a level is not an array. Keys may be ints because
     * `errors` is a list.
     */
    private static function path(array $row, string|int ...$keys): mixed
    {
        $cursor = $row;

        foreach ($keys as $key) {
            if (!is_array($cursor) || !array_key_exists($key, $cursor)) {
                return null;
            }

            $cursor = $cursor[$key];
        }

        return $cursor;
    }

    /**
     * Ints and floats count as strings here on purpose: Meta sends
     * `errors[0].code` as a JSON number, and `str()` rejecting it would silently
     * lose every delivery failure code -- the one field the inbox most needs.
     * Anything else (array, bool, null, object) becomes ''.
     */
    private static function str(mixed $value, int $max = 0): string
    {
        $out = is_string($value) || is_numeric($value) ? (string) $value : '';

        return $max > 0 ? mb_substr($out, 0, $max) : $out;
    }

    private static function nullableStr(mixed $value, int $max = 0): ?string
    {
        $out = self::str($value, $max);

        return $out === '' ? null : $out;
    }

    /**
     * 2020-01-01. Below this it is not a callback: the Business Platform is newer than the value,
     * and the low end of a MySQL `timestamp` is where a session zone east of UTC starts rejecting
     * the write outright (`1970-01-01 00:00:01` is 1292 on a +05:30 box).
     */
    private const MIN_EPOCH = 1577836800;

    /**
     * Null, not `now()`: a callback with no usable timestamp does not get to invent
     * one, because Task 5 would then have no way to tell "Meta said when" from "we
     * guessed". Consumers coalesce (`$item['sent_at'] ?? now()`); wa_messages.sent_at
     * is NOT NULL with useCurrent, so the fallback belongs at the write, not here.
     *
     * The band is not cosmetic at either end. Carbon::createFromTimestamp throws
     * InvalidFormatException from roughly 1e14 upwards, and an uncaught throw here is a 500 -- so a
     * signed body carrying `"timestamp":1e30` would drop the subscription. The far side has two
     * more reasons: the `timestamp` columns reject a value past 2038 outright (1292, which under
     * the deployed `database` queue is a silently lost receipt), and a future stamp lands in
     * last_inbound_at, which is exactly what WaContact::windowOpen() reads -- so `1893456000` held
     * a service window open until 2030 when measured through the real job. An event that already
     * happened cannot be stamped in the future, so the ceiling is now plus a day of clock skew.
     * `2147483647` is not enough of a ceiling: 2030 is under it.
     */
    private static function ts(mixed $epoch): ?CarbonInterface
    {
        if (!is_numeric($epoch) || $epoch < self::MIN_EPOCH
            || $epoch > Carbon::now()->addDay()->getTimestamp()) {
            return null;
        }

        return Carbon::createFromTimestamp((int) $epoch, 'UTC');
    }
}
