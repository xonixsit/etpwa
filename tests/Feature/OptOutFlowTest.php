<?php

namespace Tests\Feature;

use App\Jobs\ProcessInboundEvent;
use App\Models\WaContact;
use App\Models\WaMessage;
use App\Services\AutoResponder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OptOutFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'whatsapp.phone_id' => '1427706937088002',
            'whatsapp.token' => 'tok-test',
            'whatsapp.opt_out_text' => 'You will not receive any more messages from E Tax Planner on this number.',
            'whatsapp.ack_text' => 'Thanks, a human will reply.',
        ]);
        // DEVIATION from the plan block -- finding F-1, see task-7-report.md.
        // Http::fake() APPENDS its stub callbacks (vendor Factory.php:275 merge) and matching is
        // first-registered-wins (vendor PendingRequest.php:1512-1521 ->filter()->first()), so the
        // catch-all this setUp used to install silenced every Http::fake() call made inside a test.
        // Measured with the plan's line in place: the 400-only test recorded meta_id=wamid.CONF1
        // status=sent, i.e. setUp's stub answered a request its own test had stubbed as a refusal, and
        // wamid.CONF2 was unreachable, which left the one-time-confirmation guard with no witness.
        // So no stub is installed here; each test owns its stub, multi-send tests use one sequence so a
        // second send really does get a different wamid, and nothing can reach the network.
        Http::preventStrayRequests();
    }

    private function inbound(string $body, string $id = 'wamid.STOP1'): array
    {
        return [
            'kind' => 'message', 'meta_id' => $id, 'phone' => '18479043315',
            'direction' => WaMessage::IN, 'type' => 'text', 'text' => $body,
            'button_id' => null, 'template_name' => null, 'template_lang' => null,
            'sent_at' => now(), 'raw' => ['text' => ['body' => $body]],
        ];
    }

    public function test_stop_suppresses_and_confirms_once()
    {
        Http::fake(['*' => Http::response(['messages' => [['id' => 'wamid.CONF1']]], 200)]);
        ProcessInboundEvent::dispatchSync($this->inbound('STOP'));

        $c = WaContact::where('phone', '18479043315')->firstOrFail();
        $this->assertTrue($c->isOptedOut());
        $this->assertSame('inbound_keyword', $c->opt_out_source);

        $conf = WaMessage::where('meta_id', 'wamid.CONF1')->firstOrFail();
        $this->assertTrue($conf->auto_reply);
        $this->assertSame(WaMessage::OUT, $conf->direction);
        $this->assertSame(2, WaMessage::count());
    }

    public function test_second_stop_does_not_confirm_again()
    {
        // One stub, two queued responses: the plan's second Http::fake() could never take effect
        // (F-1), so wamid.CONF2 is reached by the sequence instead. If the already-silenced contact
        // were confirmed a second time, CONF2 would be recorded and the assertion below would fail.
        Http::fake(['*' => Http::sequence()
            ->push(['messages' => [['id' => 'wamid.CONF1']]], 200)
            ->push(['messages' => [['id' => 'wamid.CONF2']]], 200)]);
        ProcessInboundEvent::dispatchSync($this->inbound('STOP', 'wamid.S1'));
        ProcessInboundEvent::dispatchSync($this->inbound('STOP', 'wamid.S2'));

        $this->assertDatabaseMissing('wa_messages', ['meta_id' => 'wamid.CONF2']);
    }

    public function test_no_acknowledgement_is_sent_to_an_opted_out_contact()
    {
        // Same F-1 correction as above: CONF3 is reachable only through the sequence, so a future
        // acknowledgement regression that sends here lands on wamid.CONF3 and fails this test.
        Http::fake(['*' => Http::sequence()
            ->push(['messages' => [['id' => 'wamid.CONF1']]], 200)
            ->push(['messages' => [['id' => 'wamid.CONF3']]], 200)]);
        ProcessInboundEvent::dispatchSync($this->inbound('STOP', 'wamid.S1'));
        ProcessInboundEvent::dispatchSync($this->inbound('hello again', 'wamid.S3'));

        $this->assertDatabaseMissing('wa_messages', ['meta_id' => 'wamid.CONF3']);
        // R40: suppression stops the acknowledgement, never the storage. Three rows exist -- the
        // inbound STOP, its one confirmation, and this second inbound -- and only one of them ever
        // left the building.
        $this->assertSame(3, WaMessage::count(), 'STOP + confirmation + this inbound, stored and unsent');
        $this->assertSame(1, WaMessage::where('direction', WaMessage::OUT)->count(),
            'the second STOP-family inbound produced no send at all');
    }

    public function test_confirmation_is_recorded_even_when_meta_refuses_it()
    {
        Http::fake(['*' => Http::response(['error' => ['message' => 'Re-engagement', 'code' => 131030]], 400)]);
        ProcessInboundEvent::dispatchSync($this->inbound('STOP'));

        $this->assertTrue(WaContact::where('phone', '18479043315')->firstOrFail()->isOptedOut());
        $this->assertSame(WaMessage::FAILED,
            WaMessage::where('direction', WaMessage::OUT)->firstOrFail()->status);
    }

    public function test_an_outbound_row_is_never_a_keyword_hit()
    {
        // R42: this guard is the responder's own contract, not the caller's. An outbound echo whose
        // body happens to read "STOP" must not silence the client that never said it -- and the
        // sender is exactly the thing that would then be asked to confirm the opt-out.
        $c = WaContact::factory()->create(['phone' => '18479043315']);
        $out = WaMessage::record([
            'wa_contact_id' => $c->id, 'meta_id' => 'wamid.OUTS1', 'direction' => WaMessage::OUT,
            'type' => 'text', 'body' => 'STOP', 'status' => WaMessage::SENT, 'sent_at' => now(),
        ]);

        app(AutoResponder::class)->handle($out, null);

        $this->assertFalse($c->fresh()->isOptedOut());
        $this->assertSame(1, WaMessage::count(), 'the echo is the only row; nothing was confirmed');
    }

    public function test_an_unconfigured_opt_out_text_suppresses_without_sending()
    {
        // R42: WA_OPT_OUT_TEXT is env()-sourced and defaults to ''. Suppression is the promise; the
        // confirmation is a courtesy we must not make by sending an empty body to Meta.
        config(['whatsapp.opt_out_text' => '']);
        ProcessInboundEvent::dispatchSync($this->inbound('STOP'));

        $this->assertTrue(WaContact::where('phone', '18479043315')->firstOrFail()->isOptedOut());
        $this->assertSame(1, WaMessage::count(), 'the inbound STOP only -- no empty confirmation');
    }

    public function test_the_one_time_rule_is_per_contact_not_global()
    {
        // R42/F-3: every other test in this class uses one phone, so a `wasSilenced` flag that was
        // accidentally global -- one cache key, one static, one row consulted by contact id 1 -- would
        // still pass all seven. Two contacts must each get their own confirmation.
        Http::fake(['*' => Http::sequence()
            ->push(['messages' => [['id' => 'wamid.CONF1']]], 200)
            ->push(['messages' => [['id' => 'wamid.CONF2']]], 200)]);
        ProcessInboundEvent::dispatchSync($this->inbound('STOP', 'wamid.S1'));
        $other = $this->inbound('STOP', 'wamid.S2');
        $other['phone'] = '19014307897';
        ProcessInboundEvent::dispatchSync($other);

        $this->assertTrue(WaContact::where('phone', '18479043315')->firstOrFail()->isOptedOut());
        $this->assertTrue(WaContact::where('phone', '19014307897')->firstOrFail()->isOptedOut());
        $this->assertSame(2, WaMessage::where('direction', WaMessage::OUT)->count());
        $this->assertDatabaseHas('wa_messages', ['meta_id' => 'wamid.CONF1']);
        $this->assertDatabaseHas('wa_messages', ['meta_id' => 'wamid.CONF2']);
    }

    public function test_quick_reply_button_id_stops()
    {
        // Body text is not a keyword: only the button id can trigger this.
        $item = $this->inbound('Yes, please talk to someone', 'wamid.S4');
        $item['button_id'] = 'STOP';
        $item['type'] = 'interactive';
        Http::fake(['*' => Http::response(['messages' => [['id' => 'wamid.CONF1']]], 200)]);
        ProcessInboundEvent::dispatchSync($item);
        $this->assertTrue(WaContact::where('phone', '18479043315')->firstOrFail()->isOptedOut());
    }
}
