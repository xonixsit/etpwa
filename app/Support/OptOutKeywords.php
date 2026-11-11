<?php

namespace App\Support;

class OptOutKeywords
{
    /** Words that only ever read as an opt-out request, wherever they sit in the line. */
    public const KEYWORDS = ['stop', 'cancel', 'unsubscribe', 'opt-out', 'optout', 'quit'];

    /** Word pairs that mean opt-out only together, in this order. */
    public const PHRASES = [['opt', 'out']];

    /** Ordinary English in a tax conversation, so they count only as the whole message. */
    public const WHOLE_MESSAGE = ['end', 'remove'];

    public static function matches(?string $text, ?string $buttonId = null): bool
    {
        if ($buttonId !== null && strtolower(trim($buttonId)) === 'stop') {
            return true;
        }

        $text = trim((string) $text);
        if ($text === '') {
            return false;
        }

        $first = strtolower(trim(trim((string) strtok($text, "\n")), " \t.,!?;:'\""));
        $words = self::words($first);

        foreach ($words as $word) {
            if (in_array($word, self::KEYWORDS, true)) {
                return true;
            }
        }

        foreach (self::PHRASES as $phrase) {
            if (self::containsSequence($words, $phrase)) {
                return true;
            }
        }

        return count($words) === 1 && in_array($words[0], self::WHOLE_MESSAGE, true);
    }

    /**
     * @return list<string>
     */
    private static function words(string $line): array
    {
        // The /u is load-bearing: without it \s is ASCII-only and a U+00A0 from iOS autocorrect glues
        // "stop" to its neighbour, hiding the keyword. A hidden keyword silences nobody.
        $raw = preg_split('/\s+/u', $line) ?: [];
        $clean = [];
        foreach ($raw as $word) {
            $word = trim($word, " \t.,!?;:'\"");
            if ($word !== '') {
                $clean[] = $word;
            }
        }

        return $clean;
    }

    /**
     * @param  list<string>  $words
     * @param  list<string>  $sequence
     */
    private static function containsSequence(array $words, array $sequence): bool
    {
        $span = count($sequence);
        if (count($words) < $span) {
            return false;
        }

        for ($i = 0; $i <= count($words) - $span; $i++) {
            if (array_slice($words, $i, $span) === $sequence) {
                return true;
            }
        }

        return false;
    }
}
