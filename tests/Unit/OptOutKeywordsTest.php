<?php

namespace Tests\Unit;

use App\Support\OptOutKeywords;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class OptOutKeywordsTest extends TestCase
{
    public static function keywordProvider(): array
    {
        return [
            ['stop'], ['STOP'], [' Stop '], ['stop.'], ['end'], ['cancel'],
            ['unsubscribe'], ['opt out'], ['OPT-OUT'], ['optout'], ['quit'], ['remove'],
            ['please STOP and email me'], ['stop, please'], ['STOP. I said it'],
        ];
    }

    #[DataProvider('keywordProvider')]
    public function test_first_line_matches_the_fixed_set(string $text)
    {
        $this->assertTrue(OptOutKeywords::matches($text));
    }

    public static function notKeywordProvider(): array
    {
        return [
            ['what is a deadline'], ['started'], ['stopped'], ['stopwatch'], ['nonstop'],
            ['ending'], ['removal'], ['cancellation'], ['renewal'], ['options'],
            [''], [null], ['   '],
        ];
    }

    #[DataProvider('notKeywordProvider')]
    public function test_does_not_match(?string $text)
    {
        $this->assertFalse(OptOutKeywords::matches($text));
    }

    public function test_multi_line_uses_only_the_first_line()
    {
        $this->assertTrue(OptOutKeywords::matches("STOP\nbut actually I have a question"));
        $this->assertFalse(OptOutKeywords::matches("hello\nstop that"));
    }

    public function test_button_id_stop_matches()
    {
        $this->assertTrue(OptOutKeywords::matches(null, 'STOP'));
        $this->assertTrue(OptOutKeywords::matches('anything', 'stop'));
        $this->assertFalse(OptOutKeywords::matches('anything', 'TALK_TO_AGENT'));
    }

    /**
     * R42: the trim() in the button branch is live behaviour -- a padded id is a real payload shape, not a
     * theoretical one -- so it is pinned rather than left to a green suite that never sends one.
     */
    public function test_a_padded_button_id_still_matches()
    {
        $this->assertTrue(OptOutKeywords::matches(null, ' STOP '));
        $this->assertTrue(OptOutKeywords::matches(null, "\tstop\n"));
    }

    public static function phraseProvider(): array
    {
        return [
            ['I want to opt out now'], ['please opt out'], ['opt out please'],
            ['OPT OUT'], [' opt out.'], ['i want to opt out of these messages'],
            ['opt me out'], ['opt me out please'], ['please opt me out of this'],
        ];
    }

    /**
     * R45: "opt out" is two words, so the old word-by-word scan could only ever match it as a whole
     * line -- "I want to opt out now" did not suppress, while "please opt-out now" did. A missed
     * opt-out is the unrecoverable direction, so the phrase must match anywhere in the first line.
     */
    #[DataProvider('phraseProvider')]
    public function test_the_two_word_phrase_matches_anywhere(string $text)
    {
        $this->assertTrue(OptOutKeywords::matches($text));
    }

    public static function ambiguousProvider(): array
    {
        return [
            ['does the fiscal year end in december'], ['end of month pricing'],
            ['when does the form end'], ['remove the filing later'], ['please remove me'],
            ['I want to end the call earlier'],
        ];
    }

    /**
     * R45: `end` and `remove` are ordinary English in a tax conversation, so they only mean opt-out
     * when they are the entire message. This is the tier that keeps the loose direction honest instead
     * of silencing a client who asked about a deadline.
     */
    #[DataProvider('ambiguousProvider')]
    public function test_ambiguous_words_do_not_fire_mid_sentence(string $text)
    {
        $this->assertFalse(OptOutKeywords::matches($text));
    }

    public static function wholeMessageProvider(): array
    {
        return [['end'], ['remove'], ['END'], [' remove '], ['end.']];
    }

    #[DataProvider('wholeMessageProvider')]
    public function test_ambiguous_words_still_fire_when_they_are_the_whole_message(string $text)
    {
        $this->assertTrue(OptOutKeywords::matches($text), "'{$text}' is a bare opt-out word");
    }

    public static function unambiguousAnywhereProvider(): array
    {
        return [
            ['please STOP and email me'], ['you can unsubscribe me now'], ['cancel my plan'],
            ['I quit this service'], ['optout of everything'],
        ];
    }

    #[DataProvider('unambiguousAnywhereProvider')]
    public function test_unambiguous_words_fire_anywhere_including_the_phrase_ones(string $text)
    {
        $this->assertTrue(OptOutKeywords::matches($text), "'{$text}' should suppress");
    }

    /**
     * Reviewer F-4: iOS autocorrect and pasted text emit U+00A0 and friends, which an ASCII-only \s and
     * trim() do not see as separators -- so a space can hide the keyword. A hidden keyword is a missed
     * opt-out, the unrecoverable direction, hence the Unicode-aware split.
     */
    #[DataProvider('unicodeSpaceProvider')]
    public function test_unicode_spaces_do_not_hide_the_keyword(string $text)
    {
        $this->assertTrue(OptOutKeywords::matches($text), 'a non-ASCII space must not hide the keyword');
    }

    public static function unicodeSpaceProvider(): array
    {
        return [
            ["\u{00A0}stop"], ["stop\u{00A0}sending"], ["opt\u{00A0}out"], ["\u{2009}stop"],
            ["\u{3000}unsubscribe"], ["please\u{00A0}opt out"], ["\u{00A0}end"],
        ];
    }

    #[DataProvider('unicodeSpaceSafeProvider')]
    public function test_a_non_ascii_space_alone_does_not_invent_a_keyword(string $text)
    {
        $this->assertFalse(OptOutKeywords::matches($text), 'normalising spaces must not invent a keyword');
    }

    public static function unicodeSpaceSafeProvider(): array
    {
        return [["\u{00A0}hello there"], ["what\u{00A0}time do you end"]];
    }

    /**
     * R47: F-4 made the split Unicode-aware but left the punctuation strips byte-level, so the same
     * missed-opt-out class survives one axis over. iOS autocorrect converts straight quotes to curly ones
     * unasked, and a trailing "…" is a common way to end a one-word message. A byte-level strip cannot see
     * either, and for `end`/`remove` one stray mark defeats the whole-message tier as well as the word
     * scan, because the token no longer equals the keyword.
     */
    #[DataProvider('unicodePunctuationProvider')]
    public function test_unicode_punctuation_does_not_hide_the_keyword(string $text)
    {
        $this->assertTrue(OptOutKeywords::matches($text), 'a non-ASCII mark must not hide the keyword');
    }

    public static function unicodePunctuationProvider(): array
    {
        return [
            ["stop\u{2026}"], ["\u{201C}stop\u{201D}"], ["end\u{2026}"],
            ["end\u{3002}"], ["remove\u{2011}"], ["\u{201C}remove\u{201D}"],
        ];
    }

    #[DataProvider('unicodePunctuationSafeProvider')]
    public function test_stripping_non_ascii_marks_does_not_invent_a_keyword(string $text)
    {
        $this->assertFalse(OptOutKeywords::matches($text), 'normalising punctuation must not invent a keyword');
    }

    public static function unicodePunctuationSafeProvider(): array
    {
        return [
            ["\u{201C}hello\u{201D}"], ['stopwatch.'], ["removal\u{2026}"],
            ["\u{2026}end of month"], ['options?'],
        ];
    }

    /**
     * R42: the `?: []` fallback in the tokeniser is reachable, not defensive decoration -- a handset can
     * put bytes that are not valid UTF-8 on the wire and preg_split('/\s+/u', ...) returns false for them.
     * Undecodable text cannot be read as an opt-out, so the honest outcome is "no keyword": that leaves the
     * message for a human instead of throwing inside the queued job, and it is the direction a client can
     * still be replied to in the thread.
     */
    public function test_text_that_is_not_valid_utf8_neither_matches_nor_throws()
    {
        $this->assertFalse(OptOutKeywords::matches("\xFF\xFE stop"));
        $this->assertFalse(OptOutKeywords::matches("\xC3\x28 end"));
    }
}
