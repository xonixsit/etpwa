<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\WaContact;
use App\Models\WaMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class InboxActionsTest extends TestCase
{
    use RefreshDatabase;

    private $contact;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
        config(['whatsapp.token' => 'tok-test', 'whatsapp.phone_id' => '1427706937088002',
            'whatsapp.template_choices' => ['etp_opener_brand', 'etp_welcome_c'],
            'whatsapp.header_images' => ['etp_opener_brand' => 'https://example.test/logo.png']]);
        $this->contact = WaContact::factory()->create(['phone' => '18479043315',
            'profile_name' => 'Jane Tester', 'last_inbound_at' => now()->subHour()]);
        // R41: the first draft installed Http::fake(['*' => ... wamid.STAFF1 ...]) here, which shadowed
        // the two in-test fakes below and made preventStrayRequests() inert for every request in the class
        // (a '*' stub always matches, and the stray guard only fires when nothing matched). No stub here
        // now; each sending test brings its own, and an unstubbed request fails the test.
        Http::preventStrayRequests();
    }

    public function test_reply_inside_the_window_is_sent_and_stored()
    {
        Http::fake(['*' => Http::response(['messages' => [['id' => 'wamid.STAFF1']]], 200)]);
        $this->post('/inbox/'.$this->contact->id.'/reply', ['body' => 'Happy to help.'])
            ->assertRedirect();
        $m = WaMessage::where('meta_id', 'wamid.STAFF1')->firstOrFail();
        $this->assertSame(WaMessage::OUT, $m->direction);
        $this->assertFalse($m->auto_reply);
        $this->assertSame('Happy to help.', $m->body);
    }

    public function test_reply_outside_the_window_is_refused_locally_without_calling_graph()
    {
        $this->contact->update(['last_inbound_at' => now()->subHours(30)]);
        // No Http::fake() and no in-test preventStrayRequests(): setUp's guard is what makes the name
        // true. If the window check broke, the controller would attempt a send, nothing would be stubbed,
        // and this test would fail with StrayRequestException instead of quietly passing on a count.
        $this->post('/inbox/'.$this->contact->id.'/reply', ['body' => 'late'])
            ->assertSessionHasErrors('window');
        $this->assertSame(0, WaMessage::where('direction', WaMessage::OUT)->count());
    }

    public function test_empty_reply_is_rejected()
    {
        $this->post('/inbox/'.$this->contact->id.'/reply', ['body' => '   '])
            ->assertSessionHasErrors('body');
    }

    public function test_template_can_be_sent_with_a_closed_window()
    {
        Http::fake(['*' => Http::response(['messages' => [['id' => 'wamid.STAFF1']]], 200)]);
        $this->contact->update(['last_inbound_at' => now()->subDays(3)]);
        $this->post('/inbox/'.$this->contact->id.'/template',
            ['template' => 'etp_opener_brand', 'language' => 'en', 'variables' => 'Jane'])->assertRedirect();

        Http::assertSent(fn ($r) => $r['type'] === 'template'
            && $r['template']['name'] === 'etp_opener_brand'
            && $r['template']['components'][1]['parameters'][0]['text'] === 'Jane');
        $this->assertSame('etp_opener_brand',
            WaMessage::where('meta_id', 'wamid.STAFF1')->value('template_name'));
    }

    public function test_only_approved_template_names_are_accepted()
    {
        // No stub: an unapproved name must be refused before the sender is reached, and setUp's
        // preventStrayRequests() turns any attempt into a failure rather than a silent 200.
        $this->post('/inbox/'.$this->contact->id.'/template',
            ['template' => 'made_up_template', 'language' => 'en', 'variables' => ''])->assertSessionHasErrors('template');
        $this->assertSame(0, WaMessage::where('direction', WaMessage::OUT)->count());
    }

    public function test_nothing_can_be_sent_to_an_opted_out_contact()
    {
        // No stub for the same reason as the two tests above: suppression is enforced locally, so an
        // attempt to reach Graph here is a failure, not something to be answered.
        $this->contact->optOut('inbound_keyword');

        $this->post('/inbox/'.$this->contact->id.'/reply', ['body' => 'hi'])
            ->assertSessionHasErrors('window');
        $this->post('/inbox/'.$this->contact->id.'/template',
            ['template' => 'etp_welcome_c', 'language' => 'en_US', 'variables' => ''])
            ->assertSessionHasErrors('window');
        $this->assertSame(0, WaMessage::where('direction', WaMessage::OUT)->count());
    }

    public function test_manual_opt_out_stops_the_thread_from_sending()
    {
        $this->post('/inbox/'.$this->contact->id.'/opt-out')->assertRedirect();
        $this->assertTrue($this->contact->fresh()->isOptedOut());
        $this->assertSame('manual', $this->contact->fresh()->opt_out_source);
        // The name promises more than the state change above proves, so the send attempt is part of the
        // test: an opted-out thread must be refused before the sender runs. Without these two lines a
        // regression that recorded the opt-out but kept sending would still pass.
        $this->post('/inbox/'.$this->contact->id.'/reply', ['body' => 'after'])
            ->assertSessionHasErrors('window');
        $this->assertSame(0, WaMessage::where('direction', WaMessage::OUT)->count());
    }

    public function test_a_guest_cannot_post_a_reply()
    {
        auth()->logout();
        $this->post('/inbox/'.$this->contact->id.'/reply', ['body' => 'x'])->assertRedirect('/login');
    }

    // ---- witness rows owed by the brief (each names the arm it kills) ----

    // W-GUEST2: the plan covers reply only; guests must bounce off template and opt-out too.
    public function test_a_guest_cannot_post_a_template_or_an_opt_out()
    {
        auth()->logout();
        $this->post('/inbox/'.$this->contact->id.'/template',
            ['template' => 'etp_opener_brand', 'language' => 'en', 'variables' => ''])->assertRedirect('/login');
        $this->post('/inbox/'.$this->contact->id.'/opt-out')->assertRedirect('/login');
        $this->assertSame(0, WaMessage::where('direction', WaMessage::OUT)->count());
        $this->assertFalse($this->contact->fresh()->isOptedOut());
    }

    // W-FLASH: kills the arm that deletes the @if (session('result')) block from thread.blade.php.
    // Task 11 row 25 seeds errors/old input only -- it never exercises session('result').
    public function test_a_successful_reply_flashes_sent_inside_the_ok_wrapper()
    {
        Http::fake(['*' => Http::response(['messages' => [['id' => 'wamid.STAFF1']]], 200)]);
        $response = $this->from('/inbox/'.$this->contact->id)
            ->post('/inbox/'.$this->contact->id.'/reply', ['body' => 'Happy to help.']);
        $this->followRedirects($response)
            ->assertSee('Sent.')
            ->assertSee('<div class="ok">Sent.</div>', false);
    }

    // W-REFERER: kills the arm that replaces back() with redirect('/').
    public function test_a_reply_redirects_back_to_the_thread_it_came_from()
    {
        Http::fake(['*' => Http::response(['messages' => [['id' => 'wamid.STAFF1']]], 200)]);
        $this->from('/inbox/'.$this->contact->id)
            ->post('/inbox/'.$this->contact->id.'/reply', ['body' => 'Hi'])
            ->assertRedirect('/inbox/'.$this->contact->id);
    }

    // W-RERENDER: the request side of Task 11 row 25 -- a real refused POST feeding withErrors +
    // withInput + old(). Kills the arm that deletes ->withInput() from the window-refusal return.
    public function test_a_refused_reply_re_renders_the_thread_with_the_error_and_the_old_body()
    {
        $this->contact->update(['last_inbound_at' => now()->subHours(30)]);
        $response = $this->from('/inbox/'.$this->contact->id)
            ->post('/inbox/'.$this->contact->id.'/reply', ['body' => 'keep me']);
        $this->followRedirects($response)
            ->assertSee('The 24-hour reply window is closed. Send an approved template instead.')
            ->assertSee('<textarea name="body">keep me</textarea>', false);
        $this->assertSame(0, WaMessage::where('direction', WaMessage::OUT)->count());
    }

    // W-WIDTHS: one arm per max: rule -- deleting max:4096 / max:500 / max:10 kills its case.
    // No stub: an over-wide value must be refused before the sender, and the stray guard makes
    // any attempt a failure rather than a silent send.
    public static function overWideProvider(): array
    {
        return [
            'body 4097' => ['reply', ['body' => str_repeat('x', 4097)], 'body'],
            'variables 501' => ['template',
                ['template' => 'etp_opener_brand', 'language' => 'en', 'variables' => str_repeat('x', 501)],
                'variables'],
            'language 11' => ['template',
                ['template' => 'etp_opener_brand', 'language' => str_repeat('x', 11), 'variables' => ''],
                'language'],
        ];
    }

    #[DataProvider('overWideProvider')]
    public function test_over_wide_input_is_rejected_without_sending(string $action, array $payload, string $field)
    {
        $this->post('/inbox/'.$this->contact->id.'/'.$action, $payload)
            ->assertSessionHasErrors($field);
        $this->assertSame(0, WaMessage::where('direction', WaMessage::OUT)->count());
    }

    // W-COMMA: kills the arm that deletes array_filter from the variables line. The plan's row
    // covers only the clean single value; a trailing or leading comma must not add an empty parameter.
    public static function commaVariablesProvider(): array
    {
        return ['trailing comma' => ['Jane, '], 'leading comma' => [',Jane']];
    }

    #[DataProvider('commaVariablesProvider')]
    public function test_comma_padded_variables_produce_exactly_one_body_parameter(string $variables)
    {
        Http::fake(['*' => Http::response(['messages' => [['id' => 'wamid.STAFF1']]], 200)]);
        $this->post('/inbox/'.$this->contact->id.'/template',
            ['template' => 'etp_opener_brand', 'language' => 'en', 'variables' => $variables])->assertRedirect();

        Http::assertSentCount(1);
        Http::assertSent(fn ($r) => $r['type'] === 'template'
            && count($r['template']['components'][1]['parameters']) === 1
            && $r['template']['components'][1]['parameters'][0]['text'] === 'Jane');
    }

    // W-IDEM: pins the observable idempotency of optOut()'s early-return (closed code): a second
    // manual opt-out must not move opted_out_at, and both views must show the silenced state.
    public function test_a_second_manual_opt_out_does_not_move_the_timestamp()
    {
        $this->post('/inbox/'.$this->contact->id.'/opt-out')->assertRedirect();
        $first = $this->contact->fresh()->opted_out_at;
        $this->assertNotNull($first);

        $this->post('/inbox/'.$this->contact->id.'/opt-out')->assertRedirect();
        $second = $this->contact->fresh()->opted_out_at;
        $this->assertSame($first->format('Y-m-d H:i:s.u'), $second->format('Y-m-d H:i:s.u'));

        $this->get('/inbox/'.$this->contact->id)->assertSee('OPTED OUT (manual');
        $this->get('/inbox')->assertSee('opted out');
    }

    // W-REFUSED: kills the arm that flips the FAILED-flash ternary. The stub is the exact shape
    // post()'s rejected branch parses: error.code, error.error_data.details, error.message.
    public function test_a_refused_reply_flashes_meta_refused_and_stores_the_failed_row()
    {
        Http::fake(['*' => Http::response(['error' => [
            'message' => 'Re-engagement message', 'code' => 131030,
            'error_data' => ['details' => 'Message undeliverable'],
        ]], 400)]);
        $response = $this->from('/inbox/'.$this->contact->id)
            ->post('/inbox/'.$this->contact->id.'/reply', ['body' => 'late note']);
        $this->followRedirects($response)
            ->assertSee('Meta refused')
            ->assertSee('Message undeliverable');

        $m = WaMessage::where('direction', WaMessage::OUT)->firstOrFail();
        $this->assertSame(WaMessage::FAILED, $m->status);
        $this->assertSame('131030', $m->error_code);
    }

    // W-OPTFLASH (R53): the opt-out flash copy had no witness -- review arm V4 changed the string
    // and the whole suite stayed green. Follow the real redirect and pin the exact copy + wrapper.
    public function test_a_manual_opt_out_flashes_the_confirmation_copy()
    {
        $response = $this->from('/inbox/'.$this->contact->id)
            ->post('/inbox/'.$this->contact->id.'/opt-out');
        $this->followRedirects($response)
            ->assertSee('<div class="ok">Marked opted out. No further messages will be sent to this number.</div>', false);
    }

    // W-TPLFLASH (R53): the template-success flash copy had no witness -- review arm V5 changed
    // 'Template sent.' and the whole suite stayed green.
    public function test_a_successful_template_flashes_template_sent_inside_the_ok_wrapper()
    {
        Http::fake(['*' => Http::response(['messages' => [['id' => 'wamid.STAFF1']]], 200)]);
        $response = $this->from('/inbox/'.$this->contact->id)
            ->post('/inbox/'.$this->contact->id.'/template',
                ['template' => 'etp_welcome_c', 'language' => 'en_US', 'variables' => '']);
        $this->followRedirects($response)
            ->assertSee('<div class="ok">Template sent.</div>', false);
    }

    // W-NAMES (R53): the route NAMES had no witness -- review arm V8 renamed inbox.reply and the
    // suite stayed green because every POST row uses literal URLs. route() must resolve all three
    // names to the exact URLs the thread forms post to.
    public function test_the_three_action_routes_resolve_by_name_to_the_form_urls()
    {
        $id = $this->contact->id;
        $this->assertSame(url('/inbox/'.$id.'/reply'), route('inbox.reply', $id));
        $this->assertSame(url('/inbox/'.$id.'/template'), route('inbox.template', $id));
        $this->assertSame(url('/inbox/'.$id.'/opt-out'), route('inbox.optout', $id));
    }

    public function test_a_refused_template_flashes_meta_refused_with_the_code_and_stores_the_failed_row()
    {
        Http::fake(['*' => Http::response(['error' => [
            'message' => 'Re-engagement message', 'code' => 131030,
            'error_data' => ['details' => 'Message undeliverable'],
        ]], 400)]);
        $response = $this->from('/inbox/'.$this->contact->id)
            ->post('/inbox/'.$this->contact->id.'/template',
                ['template' => 'etp_welcome_c', 'language' => 'en_US', 'variables' => '']);
        $this->followRedirects($response)
            ->assertSee('Meta refused (')
            ->assertSee('131030')
            ->assertSee('Message undeliverable');

        $m = WaMessage::where('direction', WaMessage::OUT)->firstOrFail();
        $this->assertSame(WaMessage::FAILED, $m->status);
        $this->assertSame('131030', $m->error_code);
    }
}
