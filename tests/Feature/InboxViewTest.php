<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\WaContact;
use App\Models\WaMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\MessageBag;
use Illuminate\Support\ViewErrorBag;
use Tests\TestCase;

class InboxViewTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
    }

    // ---- the plan's five rows (Step 1 fence; D1: talking contact gets last_inbound_at) ----

    public function test_list_shows_name_number_and_state()
    {
        $talking = WaContact::factory()->create(['phone' => '18479043315', 'profile_name' => 'Jane Tester',
                                                 'last_inbound_at' => now()]);
        WaMessage::factory()->create(['wa_contact_id' => $talking->id, 'direction' => WaMessage::IN,
                                      'sent_at' => now(), 'body' => 'hello']);
        WaContact::factory()->create(['phone' => '15550002222', 'profile_name' => 'Silent Sam'])->optOut('manual');

        $this->get('/inbox')->assertOk()
            ->assertSee('Jane Tester')->assertSee('+18479043315')
            ->assertSee('Silent Sam')->assertSee('opted out')
            ->assertSee('window open');
    }

    public function test_list_search_filters_by_phone_and_name()
    {
        WaContact::factory()->create(['phone' => '18479043315', 'profile_name' => 'Jane Tester']);
        WaContact::factory()->create(['phone' => '19014307897', 'profile_name' => 'Bob Other']);

        $this->get('/inbox?q=Bob')->assertSee('Bob Other')->assertDontSee('Jane Tester');
        $this->get('/inbox?q=1847904')->assertSee('Jane Tester')->assertDontSee('Bob Other');
    }

    public function test_thread_shows_both_directions_and_marks_automatic_sends()
    {
        $c = WaContact::factory()->create(['phone' => '18479043315', 'profile_name' => 'Jane Tester']);
        WaMessage::factory()->create(['wa_contact_id' => $c->id, 'direction' => WaMessage::IN,
                                      'meta_id' => 'wamid.I1', 'body' => 'Is this E Tax Planner?',
                                      'sent_at' => now()->subMinutes(30)]);
        WaMessage::factory()->create(['wa_contact_id' => $c->id, 'direction' => WaMessage::OUT,
                                      'meta_id' => 'wamid.O1', 'type' => 'template',
                                      'template_name' => 'etp_opener_brand', 'template_lang' => 'en',
                                      'body' => 'Hi Jane, ...', 'status' => WaMessage::DELIVERED,
                                      'auto_reply' => false, 'sent_at' => now()->subHour()]);
        WaMessage::factory()->create(['wa_contact_id' => $c->id, 'direction' => WaMessage::OUT,
                                      'meta_id' => 'wamid.O2', 'auto_reply' => true, 'status' => WaMessage::SENT,
                                      'body' => 'Thanks, a human will reply.', 'sent_at' => now()->subMinutes(20)]);
        WaMessage::factory()->create(['wa_contact_id' => $c->id, 'direction' => WaMessage::OUT,
                                      'meta_id' => null, 'status' => WaMessage::FAILED,
                                      'error_code' => '131030', 'error_title' => 'Re-engagement message',
                                      'body' => 'late note', 'sent_at' => now()->subMinute()]);

        $this->get('/inbox/' . $c->id)->assertOk()
            ->assertSee('Is this E Tax Planner?')
            ->assertSee('Jane Tester')
            ->assertSee('delivered')
            ->assertSee('automatic')
            ->assertSee('FAILED 131030: Re-engagement message')
            ->assertSee('etp_opener_brand')
            ->assertSee('name="body"', false);
    }

    public function test_thread_of_an_image_message_shows_a_placeholder_not_a_crash()
    {
        $c = WaContact::factory()->create(['phone' => '18479043315']);
        WaMessage::factory()->create(['wa_contact_id' => $c->id, 'type' => 'image', 'body' => null]);

        $this->get('/inbox/' . $c->id)->assertOk()
            ->assertSee('(image message - text not stored here)');
    }

    public function test_thread_404s_for_an_unknown_contact()
    {
        $this->get('/inbox/999')->assertNotFound();
    }

    // ---- witness rows added by the brief ----

    public function test_list_shows_window_state_three_ways_and_prefers_opted_out()
    {
        WaContact::factory()->create(['phone' => '15550001111', 'profile_name' => 'Win Open',
                                      'last_inbound_at' => now()]);
        WaContact::factory()->create(['phone' => '15550002222', 'profile_name' => 'Window Stale',
                                      'last_inbound_at' => now()->subHours(25)]);
        WaContact::factory()->create(['phone' => '15550003333', 'profile_name' => 'Never One']);
        WaContact::factory()->create(['phone' => '15550004444', 'profile_name' => 'Both States',
                                      'last_inbound_at' => now()])->optOut('manual');

        $content = $this->get('/inbox')->assertOk()->getContent();

        $this->assertSame(1, substr_count($content, 'window open'));
        $this->assertSame(2, substr_count($content, 'template only'));
        $this->assertSame(1, substr_count($content, 'never replied'));
        $this->assertSame(1, substr_count($content, 'opted out'));
    }

    public function test_list_shows_inbound_and_outbound_message_counts()
    {
        $c = WaContact::factory()->create(['phone' => '15550101010', 'profile_name' => 'Counted']);
        WaMessage::factory()->create(['wa_contact_id' => $c->id, 'direction' => WaMessage::IN]);
        WaMessage::factory()->create(['wa_contact_id' => $c->id, 'direction' => WaMessage::OUT]);
        WaMessage::factory()->create(['wa_contact_id' => $c->id, 'direction' => WaMessage::OUT]);

        $content = $this->get('/inbox')->assertOk()->getContent();

        $this->assertStringContainsString('1 in / 2 out', $content);
        $this->assertStringNotContainsString('page=', $content);
    }

    public function test_the_layout_logout_form_and_its_token_render_on_the_contact_list()
    {
        $content = $this->get('/inbox')->assertOk()->getContent();

        $this->assertStringContainsString('action="/logout"', $content);
        $this->assertStringContainsString('name="_token"', $content);
    }

    public function test_a_thread_page_carries_four_csrf_fields_for_its_three_forms_and_the_logout()
    {
        $c = WaContact::factory()->create(['phone' => '15550202020']);

        $content = $this->get('/inbox/' . $c->id)->assertOk()->getContent();

        $this->assertSame(4, substr_count($content, 'name="_token"'));
    }

    public function test_bubbles_are_classed_by_direction_and_the_blocks_appear_once_each()
    {
        $c = WaContact::factory()->create(['phone' => '15550303030', 'profile_name' => 'Sided']);
        WaMessage::factory()->create(['wa_contact_id' => $c->id, 'direction' => WaMessage::IN,
                                      'meta_id' => 'wamid.S1', 'sent_at' => now()->subMinutes(30)]);
        WaMessage::factory()->create(['wa_contact_id' => $c->id, 'direction' => WaMessage::OUT,
                                      'meta_id' => 'wamid.S2', 'sent_at' => now()->subHour()]);
        WaMessage::factory()->create(['wa_contact_id' => $c->id, 'direction' => WaMessage::OUT,
                                      'meta_id' => 'wamid.S3', 'auto_reply' => true,
                                      'sent_at' => now()->subMinutes(20)]);
        WaMessage::factory()->create(['wa_contact_id' => $c->id, 'direction' => WaMessage::OUT,
                                      'meta_id' => null, 'status' => WaMessage::FAILED,
                                      'error_code' => '131030', 'error_title' => 'Re-engagement message',
                                      'sent_at' => now()->subMinute()]);

        $content = $this->get('/inbox/' . $c->id)->assertOk()->getContent();

        $this->assertSame(1, substr_count($content, 'class="bubble in"'));
        $this->assertSame(3, substr_count($content, 'class="bubble out"'));
        $this->assertSame(1, substr_count($content, '>automatic<'));
        $this->assertSame(1, substr_count($content, 'FAILED 131030:'));
    }

    public function test_a_sent_outbound_message_prints_no_failure_block()
    {
        $c = WaContact::factory()->create(['phone' => '15550404040']);
        WaMessage::factory()->create(['wa_contact_id' => $c->id, 'direction' => WaMessage::OUT,
                                      'status' => WaMessage::SENT, 'auto_reply' => false]);

        $content = $this->get('/inbox/' . $c->id)->assertOk()->getContent();

        $this->assertStringNotContainsString('FAILED', $content);
    }

    public function test_a_template_bubble_names_the_template_and_its_language()
    {
        $c = WaContact::factory()->create(['phone' => '15550505050']);
        WaMessage::factory()->create(['wa_contact_id' => $c->id, 'direction' => WaMessage::OUT,
                                      'type' => 'template', 'template_name' => 'etp_opener_brand',
                                      'template_lang' => 'en', 'status' => WaMessage::DELIVERED]);

        $content = $this->get('/inbox/' . $c->id)->assertOk()->getContent();

        $this->assertStringContainsString('etp_opener_brand (en)', $content);
    }

    public function test_thread_header_names_the_opt_out_source()
    {
        $c = WaContact::factory()->create(['phone' => '15550606060']);
        $c->optOut('manual');

        $content = $this->get('/inbox/' . $c->id)->assertOk()->getContent();

        $this->assertStringContainsString('OPTED OUT (manual', $content);
    }

    public function test_thread_header_shows_both_window_branches()
    {
        $open = WaContact::factory()->create(['phone' => '15550707070', 'last_inbound_at' => now()]);
        $closed = WaContact::factory()->create(['phone' => '15550707071', 'last_inbound_at' => now()->subHours(25)]);

        $this->get('/inbox/' . $open->id)->assertOk()->assertSee('reply window open');
        $this->get('/inbox/' . $closed->id)->assertOk()
            ->assertSee('reply window closed - approved template required');
    }

    public function test_the_template_select_is_built_from_the_config_key_not_a_hardcoded_list()
    {
        $previous = config('whatsapp.template_choices');
        config(['whatsapp.template_choices' => ['etp_probe_a', 'etp_probe_b']]);

        try {
            $c = WaContact::factory()->create(['phone' => '15550808080']);

            $this->get('/inbox/' . $c->id)->assertOk()
                ->assertSee('<option value="etp_probe_a">', false)
                ->assertSee('<option value="etp_probe_b">', false);
        } finally {
            config(['whatsapp.template_choices' => $previous]);
        }
    }

    // ---- M-A rows: pagination is real (shipped only if both M-A arms die) ----

    public function test_the_first_search_page_holds_thirty_rows_and_next_keeps_the_query()
    {
        for ($i = 1; $i <= 31; $i++) {
            WaContact::factory()->create(['profile_name' => sprintf('abc%02d', $i)]);
        }

        $content = $this->get('/inbox?q=abc')->assertOk()->getContent();

        $this->assertSame(30, substr_count($content, 'href="/inbox/'));
        $this->assertStringContainsString('Showing 1 to 30 of 31', $content);
        $this->assertStringContainsString('page=2', $content);
        $this->assertStringContainsString('q=abc', $content);
        $this->assertStringNotContainsString('abc01', $content);

        $second = $this->get('/inbox?q=abc&page=2')->assertOk()->getContent();
        $this->assertStringContainsString('abc01', $second);
        $this->assertStringContainsString('Showing 31 to 31 of 31', $second);
        $this->assertStringContainsString('q=abc', $second);
        $this->assertStringContainsString('page=1', $second);
    }

    // ---- M-B rows: the ordering is observable (shipped only if they kill arms) ----

    public function test_list_sorts_by_recent_activity_not_by_id()
    {
        $senior = WaContact::factory()->create(['profile_name' => 'Senior Id', 'last_inbound_at' => now()]);
        $junior = WaContact::factory()->create(['profile_name' => 'Junior Id',
                                                'last_inbound_at' => now()->subHours(10)]);
        $this->assertLessThan($junior->id, $senior->id);

        $content = $this->get('/inbox')->assertOk()->getContent();

        // PHPUnit's assertLessThan($limit, $actual): the senior (fresher activity, lower id)
        // link must sit at a SMALLER byte offset than the junior one.
        $this->assertLessThan(strpos($content, 'href="/inbox/' . $junior->id),
                              strpos($content, 'href="/inbox/' . $senior->id));
    }

    public function test_list_breaks_an_activity_tie_by_descending_id()
    {
        $at = now();
        $low = WaContact::factory()->create(['profile_name' => 'Low Id', 'last_inbound_at' => $at]);
        $high = WaContact::factory()->create(['profile_name' => 'High Id', 'last_inbound_at' => $at]);

        $content = $this->get('/inbox')->assertOk()->getContent();

        $this->assertLessThan(strpos($content, 'href="/inbox/' . $low->id),
                              strpos($content, 'href="/inbox/' . $high->id));
    }

    public function test_a_blank_query_lists_everyone_like_no_query_at_all()
    {
        WaContact::factory()->create(['phone' => '15550909090', 'profile_name' => 'Cher']);
        WaContact::factory()->create(['phone' => '15550909091', 'profile_name' => 'Jane Tester']);

        $content = $this->get('/inbox?q=%20')->assertOk()->getContent();

        $this->assertStringContainsString('Cher', $content);
        $this->assertStringContainsString('Jane Tester', $content);
    }

    public function test_the_search_box_escapes_what_it_echoes()
    {
        $content = $this->get('/inbox?q=%3Cb%3E')->assertOk()->getContent();

        $this->assertStringContainsString('&lt;b&gt;', $content);
        $this->assertStringNotContainsString('<b>', $content);
    }

    public function test_an_empty_address_book_shows_the_empty_state_and_no_rows()
    {
        $content = $this->get('/inbox')->assertOk()->getContent();

        $this->assertStringContainsString('No contacts yet.', $content);
        $this->assertStringNotContainsString('href="/inbox/', $content);
        $this->assertStringNotContainsString('page=', $content);
    }

    public function test_a_thread_without_messages_shows_a_placeholder()
    {
        $c = WaContact::factory()->create(['phone' => '15551010101']);

        $this->get('/inbox/' . $c->id)->assertOk()->assertSee('Nothing here yet.');
    }

    public function test_the_two_inbox_route_names_the_fence_promises_exist()
    {
        $this->assertTrue(Route::has('inbox'));
        $this->assertTrue(Route::has('inbox.thread'));
    }

    public function test_list_shows_a_relative_time_for_the_last_inbound()
    {
        WaContact::factory()->create(['phone' => '15551111111', 'profile_name' => 'Two Days',
                                      'last_inbound_at' => now()->subDays(2)->subMinutes(1)]);

        $content = $this->get('/inbox')->assertOk()->getContent();

        $this->assertStringContainsString('2 days ago', $content);
    }

    public function test_a_thread_page_renders_the_flash_errors_and_old_reply_it_is_handed()
    {
        $c = WaContact::factory()->create(['phone' => '15551212121']);

        $bag = new ViewErrorBag;
        $bag->put('default', new MessageBag([
            'body' => ['Reply body cannot be blank.'],
            'window' => ['The 24-hour window is closed.'],
            'template' => ['Pick an approved template.'],
        ]));

        $this->withSession(['errors' => $bag, '_old_input' => ['body' => 'etp old reply text']])
            ->get('/inbox/' . $c->id)->assertOk()
            ->assertSee('Reply body cannot be blank.')
            ->assertSee('The 24-hour window is closed.')
            ->assertSee('Pick an approved template.')
            ->assertSee('etp old reply text');
    }

    // ---- R52 review witnesses (task-11-review.md §3.2; each row mutation-verified) ----

    public function test_a_guest_is_bounced_off_the_list_and_the_thread()
    {
        $c = WaContact::factory()->create(['phone' => '15551313131']);
        auth()->logout();

        $this->get('/inbox')->assertRedirect('/login');
        $this->get('/inbox/' . $c->id)->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_a_thread_lists_oldest_message_first_even_when_insertion_is_scrambled()
    {
        $c = WaContact::factory()->create(['phone' => '15551112222']);
        WaMessage::factory()->create(['wa_contact_id' => $c->id, 'direction' => WaMessage::IN,
                                      'body' => 'SECOND-MSG', 'sent_at' => now()->subMinutes(10)]);
        WaMessage::factory()->create(['wa_contact_id' => $c->id, 'direction' => WaMessage::IN,
                                      'body' => 'FIRST-MSG', 'sent_at' => now()->subMinutes(30)]);

        $content = $this->get('/inbox/' . $c->id)->assertOk()->getContent();

        $this->assertLessThan(strpos($content, 'SECOND-MSG'), strpos($content, 'FIRST-MSG'));
    }

    public function test_a_message_body_is_rendered_escaped()
    {
        $c = WaContact::factory()->create(['phone' => '15552121212']);
        WaMessage::factory()->create(['wa_contact_id' => $c->id, 'direction' => WaMessage::IN,
                                      'body' => '<script>alert(1)</script>']);

        $content = $this->get('/inbox/' . $c->id)->assertOk()->getContent();

        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $content);
        $this->assertStringNotContainsString('<script>alert(1)', $content);
    }

    public function test_the_thread_header_and_variables_input_escape_the_profile_name()
    {
        $c = WaContact::factory()->create(['phone' => '15552222222', 'profile_name' => '<b>XSS Name</b>']);

        $content = $this->get('/inbox/' . $c->id)->assertOk()->getContent();

        $this->assertStringContainsString('<h2 style="margin:0">&lt;b&gt;XSS Name&lt;/b&gt;', $content);
        $this->assertStringContainsString('value="&lt;b&gt;XSS Name&lt;/b&gt;"', $content);
        $this->assertStringNotContainsString('<b>XSS Name</b>', $content);
    }

    public function test_the_contact_list_escapes_the_display_name()
    {
        WaContact::factory()->create(['phone' => '15552323232', 'profile_name' => '<i>Italic Name</i>']);

        $content = $this->get('/inbox')->assertOk()->getContent();

        $this->assertStringContainsString('&lt;i&gt;Italic Name&lt;/i&gt;', $content);
        $this->assertStringNotContainsString('<i>Italic Name</i>', $content);
    }

    public function test_a_never_replied_contact_sorts_by_its_creation_not_below_active_ones()
    {
        $stale = WaContact::factory()->create(['phone' => '15551919191', 'profile_name' => 'Stale Active',
                                               'last_inbound_at' => now()->subDays(3)]);
        $freshNull = WaContact::factory()->create(['phone' => '15552020202', 'profile_name' => 'Fresh Never']);

        $content = $this->get('/inbox')->assertOk()->getContent();

        $this->assertLessThan(strpos($content, 'href="/inbox/' . $stale->id),
                              strpos($content, 'href="/inbox/' . $freshNull->id));
    }

    public function test_the_three_thread_forms_post_to_the_task_12_urls()
    {
        $c = WaContact::factory()->create(['phone' => '15551414141']);

        $content = $this->get('/inbox/' . $c->id)->assertOk()->getContent();

        $this->assertStringContainsString('action="/inbox/' . $c->id . '/reply"', $content);
        $this->assertStringContainsString('action="/inbox/' . $c->id . '/template"', $content);
        $this->assertStringContainsString('action="/inbox/' . $c->id . '/opt-out"', $content);
    }

    public function test_pagination_ends_are_spans_not_empty_links()
    {
        for ($i = 1; $i <= 31; $i++) {
            WaContact::factory()->create(['profile_name' => sprintf('abc%02d', $i)]);
        }

        $first = $this->get('/inbox?q=abc')->assertOk()->getContent();
        $last = $this->get('/inbox?q=abc&page=2')->assertOk()->getContent();

        $this->assertStringNotContainsString('href=""', $first);
        $this->assertStringNotContainsString('href=""', $last);
        $this->assertStringContainsString('>Previous</span>', $first);
        $this->assertStringContainsString('>Next</span>', $last);
    }

    public function test_an_inbound_bubble_carries_its_timestamp()
    {
        $c = WaContact::factory()->create(['phone' => '15551515151']);
        $m = WaMessage::factory()->create(['wa_contact_id' => $c->id, 'direction' => WaMessage::IN,
                                           'sent_at' => now()->subMinutes(30)]);

        $this->get('/inbox/' . $c->id)->assertOk()
            ->assertSee($m->sent_at->toDayDateTimeString());
    }

    public function test_the_old_reply_is_escaped_when_it_is_echoed()
    {
        $c = WaContact::factory()->create(['phone' => '15551616161']);

        $content = $this->withSession(['_old_input' => ['body' => '<script>alert(2)</script>']])
            ->get('/inbox/' . $c->id)->assertOk()->getContent();

        $this->assertStringContainsString('&lt;script&gt;alert(2)&lt;/script&gt;', $content);
        $this->assertStringNotContainsString('<script>alert(2)', $content);
    }

    public function test_a_plain_text_bubble_does_not_claim_to_be_a_template()
    {
        $c = WaContact::factory()->create(['phone' => '15551717171']);
        WaMessage::factory()->create(['wa_contact_id' => $c->id, 'direction' => WaMessage::OUT,
                                      'type' => 'text', 'status' => WaMessage::SENT]);

        $content = $this->get('/inbox/' . $c->id)->assertOk()->getContent();

        $this->assertStringNotContainsString('&middot; template', $content);
    }

    public function test_an_empty_address_book_prints_no_showing_line()
    {
        $content = $this->get('/inbox')->assertOk()->getContent();

        $this->assertStringNotContainsString('Showing', $content);
    }

    public function test_a_nameless_contact_falls_back_to_its_number_in_both_views()
    {
        $c = WaContact::factory()->create(['phone' => '15551818181', 'profile_name' => null]);

        $list = $this->get('/inbox')->assertOk()->getContent();
        $this->assertSame(2, substr_count($list, '+15551818181')); // link text + Number column

        $thread = $this->get('/inbox/' . $c->id)->assertOk()->getContent();
        $this->assertStringContainsString('<h2 style="margin:0">+15551818181', $thread);
    }

    public function test_the_number_column_escapes_the_phone()
    {
        WaContact::factory()->create(['phone' => '<b>15551234567</b>', 'profile_name' => 'Odd Phone']);

        $content = $this->get('/inbox')->assertOk()->getContent();

        $this->assertStringContainsString('+&lt;b&gt;15551234567&lt;/b&gt;', $content);
        $this->assertStringNotContainsString('+<b>15551234567</b>', $content);
    }
}
