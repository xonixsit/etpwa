<?php

namespace Tests\Feature;

use App\Models\WaMessage;
use App\Support\WebhookParser;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class WebhookParserTest extends TestCase
{
    private const OURS = '1427706937088002';

    private function fixture(string $name): array
    {
        return json_decode(
            file_get_contents(base_path('tests/Fixtures/'.$name)), true);
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

    public function test_an_interactive_button_reply_yields_its_id_and_a_missing_type_gets_named(): void
    {
        $parsed = WebhookParser::parse($this->bodyOf([
            'messages' => [
                ['from' => '18479043315', 'id' => 'wamid.B1', 'timestamp' => '1793996000',
                    'type' => 'interactive',
                    'interactive' => ['type' => 'button', 'button_reply' => ['id' => 'yes_stop',
                        'title' => 'Stop reminders']]],
                ['from' => '18479043315', 'id' => 'wamid.B2', 'text' => ['body' => 'hi']],
            ],
        ]), self::OURS);

        $button = $parsed['items'][0];
        $this->assertSame('yes_stop', $button['button_id']);
        $this->assertSame('interactive', $button['type']);
        $this->assertSame('button', $button['raw']['interactive']['type']);
        $this->assertNull($button['text']);

        // The `?: 'unknown'` default is what keeps a type-less callback from rendering as an
        // empty cell in the inbox; it is the one branch of `type` nothing else reached.
        $this->assertSame('unknown', $parsed['items'][1]['type']);
    }

    public function test_a_missing_or_absurd_timestamp_is_null_not_now(): void
    {
        $parsed = WebhookParser::parse($this->bodyOf([
            'messages' => [
                ['id' => 'wamid.T1', 'from' => '18479043315', 'text' => ['body' => 'hi']],
                ['id' => 'wamid.T2', 'from' => '18479043315', 'timestamp' => -5, 'text' => ['body' => 'hi']],
                ['id' => 'wamid.T3', 'from' => '18479043315', 'timestamp' => 1e14, 'text' => ['body' => 'hi']],
                ['id' => 'wamid.T4', 'from' => '18479043315', 'timestamp' => 1e30, 'text' => ['body' => 'hi']],
                ['id' => 'wamid.T5', 'from' => '18479043315', 'timestamp' => '99999999999999999999',
                    'text' => ['body' => 'hi']],
                ['id' => 'wamid.T7', 'from' => '18479043315', 'timestamp' => 0.5, 'text' => ['body' => 'hi']],
            ],
            'statuses' => [
                ['id' => 'wamid.T6', 'recipient_id' => '18479043315', 'status' => 'delivered',
                    'timestamp' => 1e30],
            ],
        ]), self::OURS);

        $this->assertCount(7, $parsed['items']);
        $this->assertNull($parsed['items'][0]['sent_at']);
        $this->assertNull($parsed['items'][1]['sent_at']);
        $this->assertNull($parsed['items'][2]['sent_at']);
        $this->assertNull($parsed['items'][3]['sent_at']);
        $this->assertNull($parsed['items'][4]['sent_at']);
        $this->assertNull($parsed['items'][5]['sent_at']);
        $this->assertNull($parsed['items'][6]['status_at']);
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

    public function test_an_unconfigured_phone_id_matches_no_callback_at_all(): void
    {
        // Deliberately not bodyOf(): this callback carries no metadata at all,
        // which is what made an unset WA_PHONE_ID compare equal to it.
        $body = ['entry' => [['id' => '1', 'changes' => [['field' => 'messages', 'value' => [
            'messages' => [['from' => '15550001111', 'id' => 'wamid.FOREIGN',
                'type' => 'text', 'text' => ['body' => 'not ours']]],
        ]]]]]];

        $this->assertSame([], WebhookParser::parse($body, '')['items']);
        $this->assertFalse(WebhookParser::parse($body, '')['matched']);
        $this->assertFalse(WebhookParser::parse($body, self::OURS)['matched']);

        // Control: the guard must not cost us real callbacks.
        $this->assertTrue(WebhookParser::parse($this->bodyOf([
            'messages' => [['from' => '18479043315', 'id' => 'wamid.MINE', 'text' => ['body' => 'hi']]],
        ]), self::OURS)['matched']);
    }

    public function test_a_number_too_short_to_store_is_dropped_where_it_is_read(): void
    {
        $parsed = WebhookParser::parse($this->bodyOf([
            'messages' => [['from' => '12', 'id' => 'wamid.S1', 'text' => ['body' => 'hi']]],
            'statuses' => [['id' => 'wamid.S2', 'recipient_id' => '999', 'status' => 'delivered']],
            'contacts' => [['wa_id' => '55', 'profile' => ['name' => 'Short']]],
        ]), self::OURS);

        $this->assertSame([], $parsed['items']);
        $this->assertTrue($parsed['matched'], 'dropping the items must not lose the match');
    }

    public function test_descriptive_fields_are_cut_to_the_column_width_and_a_too_long_phone_is_dropped(): void
    {
        $long = str_repeat('x', 300);

        $parsed = WebhookParser::parse($this->bodyOf([
            'contacts' => [['wa_id' => '18479043315', 'profile' => ['name' => $long]]],
            'messages' => [['from' => '18479043315', 'id' => 'wamid.W1', 'type' => $long,
                'text' => ['body' => 'hi'], 'template' => ['name' => $long,
                    'language' => ['code' => $long]]]],
            'statuses' => [['id' => 'wamid.W2', 'recipient_id' => '18479043315', 'status' => $long,
                'errors' => [['code' => 131049, 'title' => $long]]]],
        ]), self::OURS);

        $this->assertCount(3, $parsed['items']);
        $this->assertSame(255, mb_strlen($parsed['items'][0]['name']));
        $this->assertSame(24, strlen($parsed['items'][1]['type']));
        $this->assertSame(255, strlen($parsed['items'][1]['template_name']));
        $this->assertSame(10, strlen($parsed['items'][1]['template_lang']));
        $this->assertSame(24, strlen($parsed['items'][2]['status']));
        $this->assertSame(255, strlen($parsed['items'][2]['error_title']));

        $hostile = WebhookParser::parse($this->bodyOf([
            'messages' => [['from' => '184790433159999999999999', 'id' => 'wamid.W3',
                'text' => ['body' => 'hi']]],
        ]), self::OURS);

        $this->assertSame([], $hostile['items'], 'a truncated phone would be someone else');
    }

    public function test_a_timestamp_in_the_future_is_not_a_timestamp(): void
    {
        // R31: the far side of the band is not only a bad date, it is last_inbound_at, and
        // last_inbound_at is the 24-hour service window. 2030 stores happily on MariaDB, so a
        // 2038 ceiling would not have closed this.
        $future = WebhookParser::parse($this->bodyOf([
            'messages' => [['from' => '18479043315', 'id' => 'wamid.F1',
                'timestamp' => '1893456000', 'text' => ['body' => 'hi']]],
        ]), self::OURS);
        $this->assertNull($future['items'][0]['sent_at']);

        $skew = WebhookParser::parse($this->bodyOf([
            'messages' => [['from' => '18479043315', 'id' => 'wamid.F2',
                'timestamp' => (string) Carbon::now()->addHours(2)->getTimestamp(),
                'text' => ['body' => 'hi']]],
        ]), self::OURS);
        $this->assertNotNull($skew['items'][0]['sent_at'], 'a clock a little ahead is still a callback');
    }

    public function test_our_own_number_is_recognised_whatever_punctuation_it_is_configured_with(): void
    {
        // R32: WA_MY_NUMBER is compared against Meta's `from`. '+1 (224) 214-4261' is how a person
        // writes it and how display_phone_number reads; both must still mean "this is our traffic".
        config(['whatsapp.my_number' => '+1 (224) 214-4261']);

        $parsed = WebhookParser::parse($this->bodyOf([
            'messages' => [['from' => '12242144261', 'to' => '18479043315', 'id' => 'wamid.E1',
                'timestamp' => '1793996090', 'type' => 'template',
                'template' => ['name' => 'opener_brand', 'language' => ['code' => 'en_US']]]],
        ]), self::OURS);

        $this->assertSame(WaMessage::OUT, $parsed['items'][0]['direction']);
        $this->assertSame('18479043315', $parsed['items'][0]['phone']);
    }
}
