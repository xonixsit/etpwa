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
}
