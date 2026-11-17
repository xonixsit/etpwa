<?php

namespace Tests\Feature;

use Dotenv\Dotenv;
use Tests\TestCase;

class EnvExampleTest extends TestCase
{
    public function test_env_example_parses_and_keeps_the_full_reply_texts()
    {
        // R58: the host deploy died with "unexpected whitespace" because WA_ACK_TEXT and
        // WA_OPT_OUT_TEXT shipped unquoted; phpdotenv rejects unquoted values containing
        // spaces. Parsing the tracked example with the app's own parser is the guard.
        $entries = Dotenv::createArrayBacked(base_path(), '.env.example')->load();

        $this->assertSame(
            'Thanks for your message. A human from E Tax Planner will reply here within one business day.',
            $entries['WA_ACK_TEXT'] ?? null
        );
        $this->assertSame(
            'You will not receive any more messages from E Tax Planner on this number.',
            $entries['WA_OPT_OUT_TEXT'] ?? null
        );
    }
}
