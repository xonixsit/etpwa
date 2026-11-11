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
}
