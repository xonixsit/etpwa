<?php

namespace App\Support;

class WebhookSignature
{
    public static function isValid(string $rawBody, ?string $header, string $secret): bool
    {
        if ($secret === '' || $header === null || ! str_starts_with($header, 'sha256=')) {
            return false;
        }

        $given = strtolower(substr($header, 7));
        if (! preg_match('/^[0-9a-f]{64}$/', $given)) {
            return false;
        }

        return hash_equals(hash_hmac('sha256', $rawBody, $secret), $given);
    }
}
