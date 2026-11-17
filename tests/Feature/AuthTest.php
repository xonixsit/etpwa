<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_page_renders_a_form()
    {
        $this->get('/login')->assertOk()
            ->assertSee('E Tax Planner inbox', false)
            ->assertSee('name="password"', false);
    }

    public function test_correct_credentials_open_a_session_and_send_staff_to_the_inbox()
    {
        User::factory()->create(['email' => 'staff@etaxplanner.com',
            'password' => Hash::make('good-pass-123')]);

        $this->post('/login', ['email' => 'staff@etaxplanner.com', 'password' => 'good-pass-123'])
            ->assertRedirect('/inbox');
        $this->assertAuthenticated();
        $this->get('/')->assertRedirect('/inbox');
    }

    public function test_wrong_password_stays_on_the_form()
    {
        User::factory()->create(['email' => 'staff@etaxplanner.com',
            'password' => Hash::make('good-pass-123')]);

        $this->from('/login')->post('/login', ['email' => 'staff@etaxplanner.com', 'password' => 'nope'])
            ->assertRedirect('/login')
            ->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_a_guest_cannot_reach_the_staff_area()
    {
        $this->get('/')->assertRedirect('/login');
    }

    public function test_logout_ends_the_session()
    {
        $this->actingAs(User::factory()->create());
        $this->post('/logout')->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_create_user_command_is_idempotent_on_email()
    {
        $this->artisan('wa:create-user', ['email' => 'a@b.co', '--password' => 'first-pass-1'])
            ->assertSuccessful();
        $this->assertSame(1, User::where('email', 'a@b.co')->count());

        $this->artisan('wa:create-user', ['email' => 'a@b.co', '--password' => 'second-pass-2'])
            ->assertSuccessful();
        $this->assertSame(1, User::where('email', 'a@b.co')->count());
        $this->assertTrue(Hash::check('second-pass-2', User::where('email', 'a@b.co')->value('password')));
    }

    public function test_create_user_rejects_a_short_password()
    {
        $this->artisan('wa:create-user', ['email' => 'a@b.co', '--password' => 'short'])
            ->assertExitCode(1);
        $this->assertSame(0, User::where('email', 'a@b.co')->count());
    }

    public function test_an_authenticated_staff_member_is_bounced_off_the_login_form()
    {
        $this->actingAs(User::factory()->create());

        $this->get('/login')->assertRedirect('/inbox');
    }

    public function test_the_sixth_login_attempt_is_refused_by_the_throttle()
    {
        User::factory()->create(['email' => 'staff@etaxplanner.com',
            'password' => Hash::make('good-pass-123')]);

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->from('/login')->post('/login', ['email' => 'staff@etaxplanner.com', 'password' => 'nope'])
                ->assertRedirect('/login');
        }

        $this->post('/login', ['email' => 'staff@etaxplanner.com', 'password' => 'good-pass-123'])
            ->assertStatus(429);
        $this->assertGuest();
    }

    public function test_the_login_form_renders_a_csrf_token()
    {
        $html = $this->get('/login')->getContent();

        $this->assertStringContainsString('name="_token" value="'.session()->token().'"', $html);
    }

    public function test_the_login_endpoint_rejects_a_request_with_no_token()
    {
        // Flip the bound env so Application::runningUnitTests() is false and VerifyCsrfToken runs at all.
        $this->app->instance('env', 'production');

        User::factory()->create(['email' => 'staff@etaxplanner.com',
            'password' => Hash::make('good-pass-123')]);

        $html = $this->get('/login')->getContent();
        preg_match('/name="_token" value="([^"]+)"/', $html, $match);
        $token = $match[1] ?? '';

        $this->post('/login', ['email' => 'staff@etaxplanner.com', 'password' => 'good-pass-123'])
            ->assertStatus(419);

        $this->post('/login', ['_token' => $token, 'email' => 'staff@etaxplanner.com', 'password' => 'good-pass-123'])
            ->assertStatus(302);
    }

    public function test_the_login_form_renders_the_three_inputs_it_promises()
    {
        $this->get('/login')
            ->assertSee('name="email"', false)
            ->assertSee('name="password"', false)
            ->assertSee('name="remember"', false);
    }

    public function test_a_failed_sign_in_displays_the_credentials_message()
    {
        User::factory()->create(['email' => 'staff@etaxplanner.com',
            'password' => Hash::make('good-pass-123')]);

        $this->from('/login')->post('/login', ['email' => 'staff@etaxplanner.com', 'password' => 'nope']);

        $html = $this->get('/login')->getContent();

        $this->assertStringContainsString('Those credentials do not match our records.', $html);
    }

    public function test_stay_signed_in_sets_a_recall_token()
    {
        $user = User::factory()->create(['email' => 'staff@etaxplanner.com',
            'password' => Hash::make('good-pass-123'),
            'remember_token' => null]);

        $this->post('/login', ['email' => 'staff@etaxplanner.com', 'password' => 'good-pass-123', 'remember' => '1'])
            ->assertRedirect('/inbox');

        $this->assertNotNull($user->fresh()->remember_token);
    }

    public function test_no_recall_token_is_set_when_stay_signed_in_is_left_unchecked()
    {
        $user = User::factory()->create(['email' => 'staff@etaxplanner.com',
            'password' => Hash::make('good-pass-123'),
            'remember_token' => null]);

        $this->post('/login', ['email' => 'staff@etaxplanner.com', 'password' => 'good-pass-123'])
            ->assertRedirect('/inbox');

        $this->assertNull($user->fresh()->remember_token);
    }

    public function test_the_validate_block_runs_and_the_email_rule_is_the_email_rule()
    {
        DB::enableQueryLog();

        DB::flushQueryLog();
        $this->post('/login', ['email' => 'not-an-email', 'password' => 'anything']);
        $this->assertSame(0, count(DB::getQueryLog()));

        DB::flushQueryLog();
        $this->post('/login', ['email' => 'staff@etaxplanner.com', 'password' => 'wrong-but-shaped']);
        $this->assertGreaterThan(0, count(DB::getQueryLog()));
    }

    public function test_a_missing_password_is_a_redirect_not_a_crash()
    {
        User::factory()->create(['email' => 'staff@etaxplanner.com',
            'password' => Hash::make('good-pass-123')]);

        $this->post('/login', ['email' => 'staff@etaxplanner.com'])
            ->assertStatus(302);
        $this->assertGuest();
    }

    public function test_a_nested_array_password_is_a_302_not_a_500()
    {
        User::factory()->create(['email' => 'staff@etaxplanner.com',
            'password' => Hash::make('good-pass-123')]);

        $this->post('/login', ['email' => 'staff@etaxplanner.com', 'password' => ['nested']])
            ->assertStatus(302);
        $this->assertGuest();
    }

    public function test_logout_destroys_the_sessions_stored_data()
    {
        $this->actingAs(User::factory()->create());
        session()->put('probe-key', 'alive');

        $this->post('/logout')->assertRedirect('/login');

        $this->assertNull(session()->get('probe-key'));
    }

    public function test_a_password_of_exactly_ten_characters_is_accepted()
    {
        $this->artisan('wa:create-user', ['email' => 'a@b.co', '--password' => '0123456789'])
            ->assertSuccessful();

        $this->assertSame(1, User::where('email', 'a@b.co')->count());
        $this->assertTrue(Hash::check('0123456789', User::where('email', 'a@b.co')->value('password')));
    }

    public function test_a_password_of_nine_characters_is_refused()
    {
        $this->artisan('wa:create-user', ['email' => 'a@b.co', '--password' => '123456789'])
            ->assertExitCode(1);

        $this->assertSame(0, User::where('email', 'a@b.co')->count());
    }

    public function test_the_email_argument_is_trimmed()
    {
        $this->artisan('wa:create-user', ['email' => '  a@b.co  ', '--password' => 'first-pass-1'])
            ->assertSuccessful();

        $this->assertSame(1, User::where('email', 'a@b.co')->count());
        $this->assertSame(0, User::where('email', '  a@b.co  ')->count());
    }

    public function test_an_empty_email_is_refused_without_touching_the_db()
    {
        $this->artisan('wa:create-user', ['email' => '', '--password' => 'first-pass-1'])
            ->assertExitCode(1);

        $this->assertSame(0, User::count());
    }

    public function test_the_default_staff_display_name_is_the_one_we_ship()
    {
        $this->artisan('wa:create-user', ['email' => 'a@b.co', '--password' => 'first-pass-1'])
            ->assertSuccessful();

        $this->assertSame('E Tax Planner staff', User::where('email', 'a@b.co')->value('name'));
    }

    public function test_the_two_route_names_the_deliverable_promises_exist()
    {
        $this->assertTrue(Route::has('login'));
        $this->assertTrue(Route::has('logout'));
    }

    public function test_the_throttle_is_on_the_post_only()
    {
        $response = null;
        for ($attempt = 1; $attempt <= 6; $attempt++) {
            $response = $this->get('/login');
        }

        $this->assertNotSame(429, $response->getStatusCode());
    }
}
