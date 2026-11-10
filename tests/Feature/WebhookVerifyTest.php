<?php

namespace Tests\Feature;

use Tests\TestCase;

class WebhookVerifyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['whatsapp.verify_token' => 'verify-test']);
    }

    public function test_echoes_the_challenge_for_the_right_token()
    {
        $this->get('/wa/webhook?hub.mode=subscribe&hub.verify_token=verify-test&hub.challenge=chk123')
            ->assertOk()
            ->assertContent('chk123');
    }

    public function test_rejects_a_wrong_token_with_403_and_no_challenge()
    {
        $this->get('/wa/webhook?hub.mode=subscribe&hub.verify_token=nope&hub.challenge=chk123')
            ->assertForbidden()
            ->assertDontSee('chk123');
    }

    public function test_rejects_when_verify_token_is_not_configured()
    {
        config(['whatsapp.verify_token' => '']);
        $this->get('/wa/webhook?hub.mode=subscribe&hub.verify_token=&hub.challenge=chk123')
            ->assertForbidden();
    }

    public function test_a_hub_parameter_supplied_as_an_array_is_rejected_not_fatal()
    {
        // PHP's query parser happily produces array-valued parameters, and the
        // handler reads two spellings that both mangle to the same underscore key.
        // Casting an array to string raises a warning, which Laravel promotes to an
        // ErrorException, so an unauthenticated request would answer 500. A public
        // endpoint must answer 403 instead (Ruling R17).
        $this->get('/wa/webhook?hub_verify_token[]=x')
            ->assertForbidden()
            ->assertDontSee('chk123');

        $this->get('/wa/webhook?hub.verify_token[a]=1&hub.challenge[a]=2')
            ->assertForbidden();
    }

    public function test_rejects_a_wrong_token_arriving_under_the_underscore_spelling()
    {
        // The two tests above reach the reject branch through an empty $sent, so they
        // pass even if the underscore half of the read is deleted. This one is the only
        // test that proves the mismatch comparison itself runs on the name PHP actually
        // hands Laravel.
        $this->get('/wa/webhook?hub.mode=subscribe&hub_verify_token=nope&hub_challenge=chk456')
            ->assertForbidden()
            ->assertDontSee('chk456');
    }

    public function test_accepts_the_dotted_spelling_when_a_runtime_preserves_it()
    {
        // Parameters passed as an array go straight into the query bag, bypassing the
        // parser that rewrites dots, so this simulates a runtime (a PSR-7 bridge) that
        // keeps Meta's literal spelling. Without it the dotted half of the dual read is
        // a claim no test ever exercises.
        $this->call('GET', '/wa/webhook', [
            'hub.mode' => 'subscribe',
            'hub.verify_token' => 'verify-test',
            'hub.challenge' => 'chk-dot',
        ])->assertOk()->assertContent('chk-dot');
    }
}
