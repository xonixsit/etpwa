<?php

namespace Tests\Feature;

use App\Models\WaContact;
use App\Models\WaMessage;
use App\Services\SuppressedContactException;
use App\Services\WhatsAppSender;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WhatsAppSenderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['whatsapp.phone_id' => '1427706937088002', 'whatsapp.token' => 'tok-test']);
        Http::preventStrayRequests();
    }

    public function test_send_text_posts_to_graph_and_records_the_wamid()
    {
        Http::fake(['*' => Http::response(['messages' => [['id' => 'wamid.OUT9']]], 200)]);

        $m = app(WhatsAppSender::class)->sendText('18479043315', 'Hello there');

        $this->assertSame(WaMessage::OUT, $m->direction);
        $this->assertSame(WaMessage::SENT, $m->status);
        $this->assertSame('wamid.OUT9', $m->meta_id);
        $this->assertSame('Hello there', $m->body);
        $this->assertFalse($m->auto_reply);

        Http::assertSent(fn ($r) => $r['type'] === 'text'
            && $r['to'] === '18479043315'
            && $r['text']['body'] === 'Hello there'
            && $r['messaging_product'] === 'whatsapp'
            && $r->url() === 'https://graph.facebook.com/v20.0/1427706937088002/messages');
    }

    public function test_a_400_is_stored_as_failed_with_the_meta_error()
    {
        Http::fake(['*' => Http::response(['error' => [
            'message' => 'Re-engagement message', 'code' => 131030,
            'error_data' => ['details' => 'Message undeliverable'],
        ]], 400)]);

        $m = app(WhatsAppSender::class)->sendText('18479043315', 'Late reply');

        $this->assertSame(WaMessage::FAILED, $m->status);
        $this->assertSame('131030', $m->error_code);
        $this->assertSame('Message undeliverable', $m->error_title);
        $this->assertNull($m->meta_id);
        $this->assertNotNull($m->id, 'a failed send must still be visible in the thread');
    }

    public function test_missing_token_fails_locally_without_calling_graph()
    {
        config(['whatsapp.token' => '']);
        $m = app(WhatsAppSender::class)->sendText('18479043315', 'x');
        $this->assertSame(WaMessage::FAILED, $m->status);
        $this->assertSame('local', $m->error_code);
        $this->assertSame('no access token configured', $m->error_title);
    }

    public function test_template_send_re_attaches_the_approved_header_image()
    {
        config(['whatsapp.header_images' => ['etp_opener_brand' => 'https://example.test/logo.png']]);
        Http::fake(['*' => Http::response(['messages' => [['id' => 'wamid.OUT10']]], 200)]);

        app(WhatsAppSender::class)->sendTemplate('18479043315', 'etp_opener_brand', 'en', ['Sam']);

        Http::assertSent(function ($r) {
            $c = $r['template']['components'];

            return $c[0] === ['type' => 'header', 'parameters' => [[
                'type' => 'image', 'image' => ['link' => 'https://example.test/logo.png']]]]
                && $c[1]['type'] === 'body'
                && $c[1]['parameters'][0]['text'] === 'Sam'
                && $r['template']['language']['code'] === 'en';
        });
        $m = WaMessage::where('meta_id', 'wamid.OUT10')->firstOrFail();
        $this->assertSame('etp_opener_brand', $m->template_name);
        $this->assertSame('en', $m->template_lang);
    }

    public function test_template_without_variables_sends_no_components()
    {
        Http::fake(['*' => Http::response(['messages' => [['id' => 'wamid.OUT11']]], 200)]);
        app(WhatsAppSender::class)->sendTemplate('18479043315', 'etp_welcome_c', 'en_US');
        Http::assertSent(fn ($r) => ! array_key_exists('components', $r['template']));
    }

    public function test_a_punctuated_phone_is_stored_and_sent_as_digits()
    {
        // R35: the payload and the contact row must not disagree about who this is.
        Http::fake(['*' => Http::response(['messages' => [['id' => 'wamid.OUT12']]], 200)]);

        $m = app(WhatsAppSender::class)->sendText('+1 (847) 904-3315', 'Hi');

        Http::assertSent(fn ($r) => $r['to'] === '18479043315');
        $this->assertSame('18479043315', $m->contact->phone);
        $this->assertSame('18479043315', $m->raw['to'], 'the stored payload is what was sent');
    }

    public function test_an_unconfigured_phone_id_records_a_local_failure_without_calling_graph()
    {
        // R35: `.../v20.0//messages` is still an authenticated request. Not knowing which number we
        // are has to stop us before the network, exactly as a missing token does.
        config(['whatsapp.phone_id' => '']);
        Http::fake(['*' => Http::response([], 200)]);

        $m = app(WhatsAppSender::class)->sendText('18479043315', 'x');

        Http::assertNothingSent();
        $this->assertSame(WaMessage::FAILED, $m->status);
        $this->assertSame('local', $m->error_code);
        $this->assertSame('no phone number id configured', $m->error_title);
    }

    public function test_a_graph_body_that_is_shaped_wrong_is_recorded_not_thrown()
    {
        // R34: `messages.0.id` as a list is the shape that used to reach a `(string)` cast. There is
        // no id, so it is a failure, and its code falls back to the HTTP status.
        Http::fake(['*' => Http::response(['messages' => [['id' => ['w', 'x']]]], 200)]);

        $m = app(WhatsAppSender::class)->sendText('18479043315', 'x');

        $this->assertSame(WaMessage::FAILED, $m->status);
        $this->assertNull($m->meta_id);
        $this->assertSame('200', $m->error_code);
        $this->assertSame('send rejected', $m->error_title);
    }

    public function test_a_connection_failure_is_recorded_as_network_without_throwing()
    {
        // R38: the network is one of the four outcomes the deliverable names, and until now nothing
        // reached the catch -- every fake answered, and the two config guards return before the
        // request. A cURL error message is unbounded text that lands in error_title(255), so the same
        // reader caps it here as it does Meta's own message.
        Http::fake(['*' => fn () => throw new ConnectionException(
            str_repeat('x', 400))]);

        $m = app(WhatsAppSender::class)->sendText('18479043315', 'x');

        $this->assertSame(WaMessage::FAILED, $m->status);
        $this->assertSame('network', $m->error_code);
        $this->assertSame(255, mb_strlen($m->error_title));
        $this->assertNull($m->meta_id);
    }

    public function test_error_details_that_are_shaped_wrong_fall_back_to_the_message()
    {
        // R34 again, one branch further down: ?? picks the first operand whenever it is not null, so
        // an error_data.details that arrives as a list was kept, turned into '' by str(), and the
        // readable error.message next to it was never tried. The row said "send rejected" when Meta
        // had actually told us why.
        Http::fake(['*' => Http::response(['error' => [
            'message' => 'Limitation was applied due to poor quality rating',
            'code' => 131056,
            'error_data' => ['details' => ['unexpected', 'shape']],
        ]], 400)]);

        $m = app(WhatsAppSender::class)->sendText('18479043315', 'x');

        $this->assertSame('131056', $m->error_code);
        $this->assertSame('Limitation was applied due to poor quality rating', $m->error_title);
    }

    public function test_error_text_from_graph_is_cut_to_the_column_it_lands_in()
    {
        // R34: error_title is varchar(255) and both Meta's message and cURL's message are unbounded.
        // On sqlite an over-long insert succeeds, which is how the suite would forget this bug, so
        // the assertion is on the stored length -- the part a caller can observe on either engine.
        Http::fake(['*' => Http::response(['error' => [
            'message' => str_repeat('x', 400), 'code' => 131047,
        ]], 400)]);

        $m = app(WhatsAppSender::class)->sendText('18479043315', 'x');

        $this->assertSame(WaMessage::FAILED, $m->status);
        $this->assertSame('131047', $m->error_code);
        $this->assertSame(255, mb_strlen($m->error_title));
    }

    // ---- D1 gate witnesses (G1-G3). The rows above are the witness that a non-opted-out
    // contact is unaffected; they are not duplicated here. ----

    // G1: kills "gate deleted" (a send happens -> the stray guard fires) and "gate moved after
    // record()" (a row exists). No stub: any HTTP attempt is a failure, not an answer.
    public function test_send_text_to_an_opted_out_contact_throws_before_any_row_or_request()
    {
        WaContact::factory()->create(['phone' => '18479043315'])->optOut('manual');

        try {
            app(WhatsAppSender::class)->sendText('18479043315', 'x');
            $this->fail('SuppressedContactException was not thrown');
        } catch (SuppressedContactException $e) {
            $this->assertSame('This contact has opted out. Nothing may be sent to this number.',
                $e->getMessage());
        }

        $this->assertSame(0, WaMessage::count(), 'the gate refuses before any row write');
        Http::assertNothingSent();
    }

    // G2: the same refusal for the template path.
    public function test_send_template_to_an_opted_out_contact_throws_before_any_row_or_request()
    {
        WaContact::factory()->create(['phone' => '18479043315'])->optOut('inbound_keyword');

        try {
            app(WhatsAppSender::class)->sendTemplate('18479043315', 'etp_welcome_c', 'en_US');
            $this->fail('SuppressedContactException was not thrown');
        } catch (SuppressedContactException $e) {
            $this->assertSame('This contact has opted out. Nothing may be sent to this number.',
                $e->getMessage());
        }

        $this->assertSame(0, WaMessage::count(), 'the gate refuses before any row write');
        Http::assertNothingSent();
    }

    // G3: kills "exemption flag ignored in the gate" -- the one send the suppression regime
    // owes the contact must still go out and be recorded.
    public function test_send_text_with_allow_suppressed_sends_and_records_for_an_opted_out_contact()
    {
        WaContact::factory()->create(['phone' => '18479043315'])->optOut('inbound_keyword');
        Http::fake(['*' => Http::response(['messages' => [['id' => 'wamid.EXEMPT1']]], 200)]);

        $m = app(WhatsAppSender::class)->sendText('18479043315', 'You are opted out.',
            autoReply: true, allowSuppressed: true);

        $this->assertSame(WaMessage::SENT, $m->status);
        $this->assertSame('wamid.EXEMPT1', $m->meta_id);
        $this->assertTrue($m->auto_reply);
        $this->assertSame(1, WaMessage::count());
        Http::assertSent(fn ($r) => $r['to'] === '18479043315' && $r['text']['body'] === 'You are opted out.');
    }

    // G4 (R53): sendTemplate's allowSuppressed flag had no witness -- review arm V7 hard-coded
    // false in its post() call and the suite stayed green (G3 only exercises sendText).
    public function test_send_template_with_allow_suppressed_sends_and_records_for_an_opted_out_contact()
    {
        WaContact::factory()->create(['phone' => '18479043315'])->optOut('inbound_keyword');
        Http::fake(['*' => Http::response(['messages' => [['id' => 'wamid.EXEMPT2']]], 200)]);

        $m = app(WhatsAppSender::class)->sendTemplate('18479043315', 'etp_welcome_c', 'en_US',
            [], autoReply: true, allowSuppressed: true);

        $this->assertSame(WaMessage::SENT, $m->status);
        $this->assertSame('wamid.EXEMPT2', $m->meta_id);
        $this->assertSame(1, WaMessage::count());
    }
}
