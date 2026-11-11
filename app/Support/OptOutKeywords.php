<?php

namespace App\Support;

class OptOutKeywords
{
    public const KEYWORDS = [
        'stop', 'end', 'cancel', 'unsubscribe', 'opt out', 'opt-out', 'optout', 'quit', 'remove',
    ];

    public static function matches(?string $text, ?string $buttonId = null): bool
    {
        if ($buttonId !== null && strtolower(trim($buttonId)) === 'stop') {
            return true;
        }

        $text = trim((string) $text);
        if ($text === '') {
            return false;
        }

        $first = trim(strtolower(strtok($text, "\n")));
        $first = trim($first, " \t.,!?;:'\"");
        // "please STOP and email me" -> collapse to the words around it
        $words = preg_split('/\s+/', $first) ?: [];
        foreach ($words as $word) {
            if (in_array($word, self::KEYWORDS, true)) {
                return true;
            }
        }

        return in_array($first, self::KEYWORDS, true);
    }
}
