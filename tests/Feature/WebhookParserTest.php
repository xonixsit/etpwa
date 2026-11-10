<?php

namespace Tests\Feature;

use App\Support\WebhookParser;
use Carbon\CarbonInterface;
use Tests\TestCase;

class WebhookParserTest extends TestCase
{
    private const OURS = '1427706937088002';

    private function fixture(string $name): array
    {
        return json_decode(
            file_get_contents(base_path('tests/Fixtures/' . $name)), true);
    }

    private function bodyOf(array $value): array
    {
        $value['metadata'] = ['phone_number_id' => self::OURS];

        return ['entry' => [['id' => '1717463009877473', 'changes' => [
            ['field' => 'messages', 'value' => $value],
        ]]]];
    }

    public function test_inbound_text_fixture_yields_a_profile_item_then_a_message_item(): void
    {
        $parsed = WebhookParser::parse($this->fixture('inbound_text.json'), self::OURS);

        $this->assertTrue($parsed['matched']);
        $this->assertSame('1717463009877473', $parsed['entry_id']);
        $this->assertNull($parsed['phone_number_id']);
        $this->assertCount(2, $parsed['items']);

        $this->assertSame('profile', $parsed['items'][0]['kind']);
        $this->assertSame('18479043315', $parsed['items'][0]['phone']);
        $this->assertSame('Jane Tester', $parsed['items'][0]['name']);

        $message = $parsed['items'][1];
        $this->assertSame('message', $message['kind']);
        $this->assertSame('in', $message['direction']);
        $this->assertSame('18479043315', $message['phone']);
        $this->assertSame('wamid.INBOUND1', $message['meta_id']);
        $this->assertSame('text', $message['type']);
        $this->assertSame('Hello, is this E Tax Planner?', $message['text']);
        $this->assertNull($message['button_id']);
        $this->assertInstanceOf(CarbonInterface::class, $message['sent_at']);
        $this->assertSame('2026-11-06 20:13:20', $message['sent_at']->copy()->timezone('UTC')->toDateTimeString());
    }

    public function test_status_fixture_yields_a_status_item(): void
    {
        $parsed = WebhookParser::parse($this->fixture('status_delivered.json'), self::OURS);

        $this->assertTrue($parsed['matched']);
        $this->assertCount(1, $parsed['items']);

        $status = $parsed['items'][0];
        $this->assertSame('status', $status['kind']);
        $this->assertSame('wamid.OUT1', $status['meta_id']);
        $this->assertSame('delivered', $status['status']);
        $this->assertSame('18479043315', $status['phone']);
        $this->assertNull($status['error_code']);
        $this->assertNull($status['error_title']);
        $this->assertInstanceOf(CarbonInterface::class, $status['status_at']);
    }

    public function test_callback_for_another_number_is_unmatched_and_reports_that_number(): void
    {
        $parsed = WebhookParser::parse($this->fixture('other_phone_number.json'), self::OURS);

        $this->assertFalse($parsed['matched']);
        $this->assertSame('9999999999999999', $parsed['phone_number_id']);
        $this->assertSame('1717463009877473', $parsed['entry_id']);
        $this->assertSame([], $parsed['items']);
    }

    public function test_non_string_ids_become_null_rather_than_reaching_a_typed_parameter(): void
    {
        $parsed = WebhookParser::parse([
            'entry' => [['id' => ['a', 'b'], 'changes' => 'nope']],
        ], self::OURS);

        $this->assertFalse($parsed['matched']);
        $this->assertNull($parsed['entry_id']);
        $this->assertNull($parsed['phone_number_id']);
        $this->assertSame([], $parsed['items']);
    }

    public function test_items_without_a_usable_phone_are_dropped_not_stored(): void
    {
        $parsed = WebhookParser::parse($this->bodyOf([
            'contacts' => ['scalar', ['wa_id' => [18479043315], 'profile' => ['name' => 'X']],
                ['wa_id' => '15550001111', 'profile' => ['name' => []]]],
            'messages' => ['scalar', ['from' => [1, 2]], ['id' => 'wamid.NOPHONE']],
            'statuses' => ['scalar', ['recipient_id' => ''], ['recipient_id' => null, 'status' => 'read']],
        ]), self::OURS);

        $this->assertTrue($parsed['matched']);
        $this->assertSame([], $parsed['items']);
    }

    public function test_scalar_replacements_for_lists_and_nested_maps_are_survivable(): void
    {
        $cases = [
            ['scalar entry list', ['entry' => 'scalar'], false, 0],
            ['scalar changes list', ['entry' => [['changes' => 'scalar']]], false, 0],
            ['scalar change value', ['entry' => [['changes' => [['value' => 'scalar']]]]], false, 0],
            ['text is a string', $this->bodyOf([
                'messages' => [['from' => '18479043315', 'text' => 'plain string']]]), true, 1],
            ['template is a string', $this->bodyOf([
                'messages' => [['from' => '18479043315', 'template' => 'scalar']]]), true, 1],
            ['errors is a string', $this->bodyOf([
                'statuses' => [['recipient_id' => '18479043315', 'errors' => 'scalar']]]), true, 1],
        ];

        foreach ($cases as [$label, $body, $expectMatched, $expectItems]) {
            $parsed = WebhookParser::parse($body, self::OURS);

            $this->assertSame($expectMatched, $parsed['matched'], $label);
            $this->assertCount($expectItems, $parsed['items'], $label);
        }
    }

    public function test_numeric_error_code_from_json_is_captured_as_a_string(): void
    {
        $parsed = WebhookParser::parse($this->bodyOf([
            'statuses' => [['id' => 'wamid.OUT2', 'recipient_id' => '18479043315',
                'status' => 'failed', 'errors' => [['code' => 131049, 'title' => 'Re-engagement message']]]],
        ]), self::OURS);

        $status = $parsed['items'][0];
        $this->assertSame('131049', $status['error_code']);
        $this->assertSame('Re-engagement message', $status['error_title']);
        $this->assertSame('failed', $status['status']);
    }

    public function test_a_missing_or_absurd_timestamp_is_null_not_now(): void
    {
        $parsed = WebhookParser::parse($this->bodyOf([
            'messages' => [['id' => 'wamid.T1', 'from' => '18479043315', 'text' => ['body' => 'hi']],
                ['id' => 'wamid.T2', 'from' => '18479043315', 'timestamp' => -5, 'text' => ['body' => 'hi']]],
        ]), self::OURS);

        $this->assertCount(2, $parsed['items']);
        $this->assertNull($parsed['items'][0]['sent_at']);
        $this->assertNull($parsed['items'][1]['sent_at']);
    }

    public function test_message_from_our_own_number_is_outbound_and_attributed_to_the_recipient(): void
    {
        config(['whatsapp.my_number' => '12242144261']);

        $parsed = WebhookParser::parse($this->bodyOf([
            'messages' => [['id' => 'wamid.OUT3', 'from' => '12242144261', 'to' => '18479043315',
                'type' => 'template', 'template' => ['name' => 'opener_brand', 'language' => ['code' => 'en_US']]]],
        ]), self::OURS);

        $message = $parsed['items'][0];
        $this->assertSame('out', $message['direction']);
        $this->assertSame('18479043315', $message['phone']);
        $this->assertSame('opener_brand', $message['template_name']);
        $this->assertSame('en_US', $message['template_lang']);
    }

    public function test_an_unconfigured_own_number_never_invents_an_outbound_row(): void
    {
        config(['whatsapp.my_number' => '']);

        $parsed = WebhookParser::parse($this->bodyOf([
            'messages' => [
                ['id' => 'wamid.A', 'from' => '12242144261', 'to' => '18479043315',
                    'text' => ['body' => 'hi']],
                ['id' => 'wamid.B', 'to' => '18479043315', 'text' => ['body' => 'hi']],
            ],
        ]), self::OURS);

        $this->assertCount(1, $parsed['items']);
        $this->assertSame('in', $parsed['items'][0]['direction']);
        $this->assertSame('12242144261', $parsed['items'][0]['phone']);
    }
}
