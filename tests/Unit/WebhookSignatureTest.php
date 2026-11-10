<?php

namespace Tests\Unit;

use App\Support\WebhookSignature;
use PHPUnit\Framework\TestCase;

class WebhookSignatureTest extends TestCase
{
    private const SECRET = 'appsecret-test';

    private function body(): string
    {
        return '{"object":"whatsapp_business_account","entry":[]}';
    }

    private function good(): string
    {
        return 'sha256=' . hash_hmac('sha256', $this->body(), self::SECRET);
    }

    public function test_accepts_a_correct_sha256_header()
    {
        $this->assertTrue(WebhookSignature::isValid($this->body(), $this->good(), self::SECRET));
    }

    public function test_rejects_missing_header()
    {
        $this->assertFalse(WebhookSignature::isValid($this->body(), null, self::SECRET));
    }

    public function test_rejects_wrong_prefix()
    {
        $sha1 = 'sha1=' . hash_hmac('sha1', $this->body(), self::SECRET);
        $this->assertFalse(WebhookSignature::isValid($this->body(), $sha1, self::SECRET));
    }

    public function test_rejects_tampered_body()
    {
        $this->assertFalse(WebhookSignature::isValid($this->body() . ' ', $this->good(), self::SECRET));
    }

    public function test_rejects_empty_secret()
    {
        $this->assertFalse(WebhookSignature::isValid($this->body(), $this->good(), ''));
    }

    public function test_rejects_non_hex_garbage()
    {
        $this->assertFalse(WebhookSignature::isValid($this->body(), 'sha256=ZZZZ', self::SECRET));
    }
}
