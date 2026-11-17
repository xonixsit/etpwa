<?php

namespace Tests\Feature;

use App\Jobs\ProcessInboundEvent;
use App\Models\WaContact;
use App\Models\WaMessage;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AckFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'whatsapp.phone_id' => '1427706937088002',
            'whatsapp.token' => 'tok-test',
            'whatsapp.ack_text' => 'Thanks, a human from E Tax Planner will reply here within one business day.',
            'whatsapp.opt_out_text' => 'No more messages.',
        ]);
        Cache::flush();
        // R41: no catch-all stub here. The first draft of this block installed
        // Http::fake(fn () => ... 'wamid.ACK' . uniqid() ...) in setUp, which (a) shadows every in-test
        // fake because stub callbacks merge and matching is first-registered-wins, and (b) gave every send
        // a different id, so no test could witness *which* response it got -- a double-ack was caught only
        // by the outbound-row counts. Each test below owns its stub; the two-send test uses one sequence
        // with two distinct ids; and a test that must not send gets no stub at all, which
        // preventStrayRequests turns into a failure rather than a socket.
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        // Carbon's test-now is a process-wide static; the re-arm test freezes it and nothing else here
        // would ever see the real clock again.
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function inbound(string $body, string $phone = '18479043315', ?CarbonInterface $at = null): array
    {
        // R46: the third parameter exists because two of the tests below have to put two messages in the
        // same second. A helper that always stamps now() cannot express that, and now() differs by
        // microseconds between two dispatchSync calls.
        return [
            'kind' => 'message', 'meta_id' => 'wamid.'.uniqid(), 'phone' => $phone,
            'direction' => WaMessage::IN, 'type' => 'text', 'text' => $body,
            'button_id' => null, 'template_name' => null, 'template_lang' => null,
            'sent_at' => $at ?? now(), 'raw' => ['text' => ['body' => $body]],
        ];
    }

    public function test_first_inbound_of_the_day_gets_one_ack()
    {
        Http::fake(['*' => Http::response(['messages' => [['id' => 'wamid.ACK1']]], 200)]);
        ProcessInboundEvent::dispatchSync($this->inbound('hello'));

        $ack = WaMessage::where('direction', WaMessage::OUT)->first();
        $this->assertNotNull($ack);
        $this->assertTrue($ack->auto_reply);
        $this->assertSame('wamid.ACK1', $ack->meta_id);
        $this->assertStringContainsString('a human from E Tax Planner', $ack->body);
    }

    public function test_later_messages_same_day_get_nothing()
    {
        // One stub, because exactly one send is expected. If the once-per-day rule broke, the second
        // send would still be answered and the count below would fail -- but it would fail having tried
        // to reach Meta twice, which is the thing this test exists to prevent.
        Http::fake(['*' => Http::response(['messages' => [['id' => 'wamid.ACK1']]], 200)]);
        ProcessInboundEvent::dispatchSync($this->inbound('hello'));
        ProcessInboundEvent::dispatchSync($this->inbound('and also?'));
        ProcessInboundEvent::dispatchSync($this->inbound('one more thing'));

        $this->assertSame(1, WaMessage::where('direction', WaMessage::OUT)->count());
    }

    public function test_a_different_contact_is_acknowledged_separately()
    {
        // One sequence, two distinct ids: the cache key is per-contact, so a key that ignored the phone
        // would produce one ack for two people. Asserting both ids present is what proves two different
        // sends happened, where the row count alone could not tell an ack from a retry.
        Http::fake(['*' => Http::sequence()
            ->push(['messages' => [['id' => 'wamid.ACK1']]], 200)
            ->push(['messages' => [['id' => 'wamid.ACK2']]], 200)]);
        ProcessInboundEvent::dispatchSync($this->inbound('hello', '18479043315'));
        ProcessInboundEvent::dispatchSync($this->inbound('hello', '19014307897'));

        $this->assertSame(2, WaMessage::where('direction', WaMessage::OUT)->count());
        $this->assertDatabaseHas('wa_messages', ['meta_id' => 'wamid.ACK1']);
        $this->assertDatabaseHas('wa_messages', ['meta_id' => 'wamid.ACK2']);
    }

    public function test_opt_out_message_is_not_acknowledged()
    {
        // The only send this test allows is the opt-out confirmation, so it gets the only stub.
        Http::fake(['*' => Http::response(['messages' => [['id' => 'wamid.OPT1']]], 200)]);
        ProcessInboundEvent::dispatchSync($this->inbound('STOP'));
        // only the confirmation, no ack line
        $out = WaMessage::where('direction', WaMessage::OUT)->get();
        $this->assertCount(1, $out);
        $this->assertSame('wamid.OPT1', $out->first()->meta_id);
        $this->assertStringContainsString('No more messages', $out->first()->body);
    }

    public function test_empty_ack_text_disables_the_rule()
    {
        // Deliberately no Http::fake() here: setUp's preventStrayRequests() is what makes the attempt
        // harmless. It is NOT what fails the test -- the sender catches \Throwable at
        // WhatsAppSender.php:99 and records a FAILED row instead of letting the exception escape, so a
        // broken guard shows up as an outbound row, which is what the count below asserts on.
        config(['whatsapp.ack_text' => '']);
        ProcessInboundEvent::dispatchSync($this->inbound('hello'));
        $this->assertSame(0, WaMessage::where('direction', WaMessage::OUT)->count());
    }

    /**
     * R48 witness for the `if ($contact->isOptedOut()) return;` seam in handle().
     *
     * Spec §9 promises "never auto-send to a contact where opted_out_at is set, from any code path", and
     * this seam is the only line that keeps that promise for a contact silenced by an admin (Task 12) or an
     * import (Task 13) rather than by their own STOP. Deleting the seam left all 165 rows green, because no
     * other row in this file is a suppressed contact that reaches it -- the STOP message returns inside the
     * keyword branch (`AutoResponder.php:33`) and never gets here, and the one `OptOutFlowTest` row that
     * sends a non-keyword message to a silenced contact would be stood down by the window query (`:56`) even
     * without the seam, because its own STOP is an inbound inside the window. This is the state where the
     * seam alone decides: suppressed contact, a message that is not a keyword, no inbound in the window.
     */
    public function test_a_non_keyword_inbound_from_a_silenced_contact_gets_no_ack()
    {
        WaContact::factory()->create(['phone' => '18479043315', 'opted_out_at' => now()->subDays(30)]);
        Http::fake(['*' => Http::response(['messages' => [['id' => 'wamid.ACK1']]], 200)]);
        ProcessInboundEvent::dispatchSync($this->inbound('hello'));

        $this->assertSame(0, WaMessage::where('direction', WaMessage::OUT)->count());
        $this->assertSame(1, WaMessage::where('direction', WaMessage::IN)->count());
    }

    /**
     * R48 witness for the window query in acknowledge().
     *
     * With CACHE_STORE=array the Cache::add guard answers "no" for every later message the query would
     * also stop, so no same-day test can tell the two apart -- measured: deleting the query left all 165
     * rows passing. The state where the query alone decides is an inbound the responder never ran for,
     * which is exactly what Task 13's import produces: a row in the DB, no cache key behind it.
     */
    public function test_an_earlier_inbound_stands_the_ack_down_even_with_an_empty_cache()
    {
        $contact = WaContact::factory()->create(['phone' => '18479043315']);
        WaMessage::record([
            'wa_contact_id' => $contact->id, 'meta_id' => 'wamid.OLD1', 'direction' => WaMessage::IN,
            'type' => 'text', 'body' => 'an older inbound', 'status' => WaMessage::INCOMING,
            'sent_at' => now()->subHours(2), 'raw' => ['text' => ['body' => 'an older inbound']],
        ]);
        Http::fake(['*' => Http::response(['messages' => [['id' => 'wamid.ACK1']]], 200)]);
        ProcessInboundEvent::dispatchSync($this->inbound('hello'));

        $this->assertSame(0, WaMessage::where('direction', WaMessage::OUT)->count());
        $this->assertSame(2, WaMessage::where('direction', WaMessage::IN)->count());
    }

    /**
     * R49 witness for the length of the window -- the two 24-hour numbers, the query's lower bound
     * (`AutoResponder.php:59`) and the cache key's TTL (`:67`). Widening *either* to 30 days left the whole
     * suite green as this file stood, i.e. nothing pinned "per day" rather than "per month". Freezing the
     * clock is the only way to reach the state where they decide: the key must expire (ArrayStore computes
     * expiry from Carbon::now(), which honours the freeze) and the earlier message must fall outside the
     * window.
     */
    public function test_the_contact_is_acknowledged_again_once_the_window_has_passed()
    {
        Http::fake(['*' => Http::sequence()
            ->push(['messages' => [['id' => 'wamid.ACK1']]], 200)
            ->push(['messages' => [['id' => 'wamid.ACK2']]], 200)]);
        ProcessInboundEvent::dispatchSync($this->inbound('hello'));

        Carbon::setTestNow(now()->addHours(25));
        ProcessInboundEvent::dispatchSync($this->inbound('a day later'));

        $this->assertSame(2, WaMessage::where('direction', WaMessage::OUT)->count());
        $this->assertDatabaseHas('wa_messages', ['meta_id' => 'wamid.ACK2']);
    }

    /**
     * R49 witness for `->where('direction', WaMessage::IN)` in the window query. Deleting the filter left
     * the suite green, and the state it permits is the ordinary one: staff reply in the afternoon, the
     * contact's first message next morning -- and no acknowledgement, because an outbound row of our own
     * counted as "this contact is already talking".
     */
    public function test_a_staff_reply_inside_the_window_does_not_stand_the_ack_down()
    {
        $contact = WaContact::factory()->create(['phone' => '18479043315']);
        WaMessage::record([
            'wa_contact_id' => $contact->id, 'meta_id' => 'wamid.STAFF1', 'direction' => WaMessage::OUT,
            'type' => 'text', 'body' => 'here is your filing quote', 'status' => WaMessage::SENT,
            'sent_at' => now()->subHours(2), 'raw' => ['text' => ['body' => 'here is your filing quote']],
        ]);
        Http::fake(['*' => Http::response(['messages' => [['id' => 'wamid.ACK1']]], 200)]);
        ProcessInboundEvent::dispatchSync($this->inbound('hello'));

        $this->assertSame(2, WaMessage::where('direction', WaMessage::OUT)->count());
        $this->assertDatabaseHas('wa_messages', ['meta_id' => 'wamid.ACK1', 'auto_reply' => true]);
    }

    /**
     * R49 witness for the window's upper bound (`sent_at < $at + 1s`). Meta redelivers a webhook it already
     * delivered, so an older message can be processed after a newer one; the bound says only rows at or
     * before the message being handled may stand it down. Without it (measured: suite green) the decision
     * reads rows that have not happened yet. The cache key normally hides the difference, so the state is
     * manufactured the way the import path does it: a stored row with no key behind it.
     */
    public function test_a_row_recorded_after_this_message_does_not_stand_the_ack_down()
    {
        $contact = WaContact::factory()->create(['phone' => '18479043315']);
        WaMessage::record([
            'wa_contact_id' => $contact->id, 'meta_id' => 'wamid.LATER1', 'direction' => WaMessage::IN,
            'type' => 'text', 'body' => 'a newer message, stored first', 'status' => WaMessage::INCOMING,
            'sent_at' => now()->addMinutes(5), 'raw' => ['text' => ['body' => 'a newer message, stored first']],
        ]);
        Http::fake(['*' => Http::response(['messages' => [['id' => 'wamid.ACK1']]], 200)]);
        ProcessInboundEvent::dispatchSync($this->inbound('hello'));

        $this->assertSame(1, WaMessage::where('direction', WaMessage::OUT)->count());
        $this->assertDatabaseHas('wa_messages', ['meta_id' => 'wamid.ACK1']);
    }

    public function test_two_messages_in_the_same_second_produce_one_ack()
    {
        // R46: the case the deliverable names -- two callbacks in one second. The sequence stub makes a
        // second send observable by id rather than only by a count, so a regression that sends twice shows
        // up as wamid.ACK2 in the table instead of a number that could be explained away.
        Http::fake(['*' => Http::sequence()
            ->push(['messages' => [['id' => 'wamid.ACK1']]], 200)
            ->push(['messages' => [['id' => 'wamid.ACK2']]], 200)]);
        $at = Carbon::parse('2026-11-11 09:00:00', 'UTC');
        ProcessInboundEvent::dispatchSync($this->inbound('hello', '18479043315', $at));
        ProcessInboundEvent::dispatchSync($this->inbound('and one more', '18479043315', $at));

        $this->assertSame(1, WaMessage::where('direction', WaMessage::OUT)->count());
        $this->assertDatabaseHas('wa_messages', ['meta_id' => 'wamid.ACK1']);
        $this->assertDatabaseMissing('wa_messages', ['meta_id' => 'wamid.ACK2']);
    }

    public function test_a_claimed_cache_key_is_what_stands_the_callback_down()
    {
        // R42/R46: Cache::add is the decider in exactly one state -- no earlier inbound row inside the
        // window for this contact, but the ack already claimed. sync dispatch cannot interleave two jobs,
        // so that state is manufactured here rather than left untested. Deliberately no Http::fake(): the
        // attempt is refused by preventStrayRequests, but the failure this test would see is the FAILED
        // row the sender records for it (WhatsAppSender.php:99), not an escaping exception. This test does
        // NOT prove atomicity -- see R46 for why no test can, with CACHE_STORE=array.
        $contact = WaContact::factory()->create(['phone' => '18479043315']);
        Cache::add('wa-ack-'.$contact->id, 1, now()->addHours(24));
        ProcessInboundEvent::dispatchSync($this->inbound('hello'));

        $this->assertSame(0, WaMessage::where('direction', WaMessage::OUT)->count());
        $this->assertSame(1, WaMessage::where('direction', WaMessage::IN)->count());
    }
}
