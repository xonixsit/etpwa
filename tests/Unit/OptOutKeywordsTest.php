<?php

namespace Tests\Unit;

use App\Support\OptOutKeywords;
use PHPUnit\Framework\TestCase;

class OptOutKeywordsTest extends TestCase
{
    public static function keywordProvider(): array
    {
        return [
            ['stop'], ['STOP'], [' Stop '], ['stop.'], ['end'], ['cancel'],
            ['unsubscribe'], ['opt out'], ['OPT-OUT'], ['optout'], ['quit'], ['remove'],
            ['please STOP and email me'],
        ];
    }

    /** @dataProvider keywordProvider */
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

    /** @dataProvider notKeywordProvider */
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

    public static function phraseProvider(): array
    {
        return [
            ['I want to opt out now'], ['please opt out'], ['opt out please'],
            ['OPT OUT'], [' opt out.'], ['i want to opt out of these messages'],
        ];
    }

    /**
     * R45: "opt out" is two words, so the old word-by-word scan could only ever match it as a whole
     * line -- "I want to opt out now" did not suppress, while "please opt-out now" did. A missed
     * opt-out is the unrecoverable direction, so the phrase must match anywhere in the first line.
     *
     * @dataProvider phraseProvider
     */
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
     *
     * @dataProvider ambiguousProvider
     */
    public function test_ambiguous_words_do_not_fire_mid_sentence(string $text)
    {
        $this->assertFalse(OptOutKeywords::matches($text));
    }

    public function test_ambiguous_words_still_fire_when_they_are_the_whole_message()
    {
        foreach (['end', 'remove', 'END', ' remove ', 'end.'] as $text) {
            $this->assertTrue(OptOutKeywords::matches($text), "'{$text}' is a bare opt-out word");
        }
    }

    public function test_unambiguous_words_fire_anywhere_including_the_phrase_ones()
    {
        foreach (['please STOP and email me', 'you can unsubscribe me now', 'cancel my plan',
                       'I quit this service', 'optout of everything'] as $text) {
            $this->assertTrue(OptOutKeywords::matches($text), "'{$text}' should suppress");
        }
    }

    /**
     * Reviewer F-4: iOS autocorrect and pasted text emit U+00A0 and friends, which an ASCII-only \s and
     * trim() do not see as separators -- so a space can hide the keyword. A hidden keyword is a missed
     * opt-out, the unrecoverable direction, hence the Unicode-aware split. A space with no keyword in it
     * must still be silent, which is the second half of the loop.
     */
    public function test_unicode_spaces_do_not_hide_the_keyword()
    {
        foreach (["\u{00A0}stop", "stop\u{00A0}sending", "opt\u{00A0}out", "\u{2009}stop",
                       "\u{3000}unsubscribe", "please\u{00A0}opt out", "\u{00A0}end"] as $text) {
            $this->assertTrue(OptOutKeywords::matches($text), 'a non-ASCII space must not hide the keyword');
        }
        foreach (["\u{00A0}hello there", "what\u{00A0}time do you end"] as $text) {
            $this->assertFalse(OptOutKeywords::matches($text), 'normalising spaces must not invent a keyword');
        }
    }
}
