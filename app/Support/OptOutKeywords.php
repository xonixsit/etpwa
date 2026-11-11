<?php

namespace App\Support;

class OptOutKeywords
{
    /** Words that only ever read as an opt-out request, wherever they sit in the line. */
    public const KEYWORDS = ['stop', 'cancel', 'unsubscribe', 'opt-out', 'optout', 'quit'];

    /** Word sequences that mean opt-out only together, in this order. */
    public const PHRASES = [['opt', 'out'], ['opt', 'me', 'out']];

    /** Ordinary English in a tax conversation, so they count only as the whole message. */
    public const WHOLE_MESSAGE = ['end', 'remove'];

    public static function matches(?string $text, ?string $buttonId = null): bool
    {
        if ($buttonId !== null && strtolower(trim($buttonId)) === 'stop') {
            return true;
        }

        $text = trim((string) $text);

        $first = strtolower((string) strtok($text, "\n"));
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
        // Both /u modifiers are load-bearing, and each closes a different way a mark can hide a keyword:
        // without the split's, an iOS U+00A0 glues "stop" to its neighbour; without the strip's, a curly
        // quote or a trailing "…" does the same to the token itself -- and a mark that spoils the token
        // also spoils the one-word whole-message tier, so "end…" is lost twice over. A hidden keyword
        // silences nobody, which is the unrecoverable direction; stripping only at token boundaries keeps
        // whole-word discipline, so "stopped", "removal" and "xopt outx" stay silent.
        $clean = [];
        foreach (preg_split('/\s+/u', $line) ?: [] as $token) {
            $word = preg_replace('/^[\p{P}\p{S}]+|[\p{P}\p{S}]+$/u', '', $token);
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

        // The bound itself is the length guard: when $span exceeds the line, `0 <= count - $span` is
        // false and the loop never runs.
        for ($i = 0; $i <= count($words) - $span; $i++) {
            if (array_slice($words, $i, $span) === $sequence) {
                return true;
            }
        }

        return false;
    }
}
