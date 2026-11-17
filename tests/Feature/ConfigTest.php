<?php

namespace Tests\Feature;

use Tests\TestCase;

class ConfigTest extends TestCase
{
    public function test_whatsapp_settings_have_safe_defaults()
    {
        config(['whatsapp.app_secret' => 'appsecret-test']);

        $this->assertSame('appsecret-test', config('whatsapp.app_secret'));
        $this->assertSame('https://graph.facebook.com/v20.0', config('whatsapp.api_base'));
        $this->assertSame(24, config('whatsapp.window_hours'));
        $this->assertSame(30, config('whatsapp.event_retention_days'));
    }

    public function test_no_credential_is_shipped_inside_config()
    {
        $this->assertSame('', (string) config('whatsapp.token'),
            'the access token is never set in config/whatsapp.php');
        $this->assertSame([], config('whatsapp.template_choices'),
            'with no WA_TEMPLATE_CHOICES in the environment the parsed list is empty');
        $this->assertIsArray(config('whatsapp.header_images'));
    }
}
