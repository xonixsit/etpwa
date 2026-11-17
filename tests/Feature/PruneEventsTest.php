<?php

namespace Tests\Feature;

use App\Models\WaWebhookEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PruneEventsTest extends TestCase
{
    use RefreshDatabase;

    public function test_events_older_than_the_retention_window_are_deleted()
    {
        config(['whatsapp.event_retention_days' => 30]);
        $old = WaWebhookEvent::store('1427706937088002', 'E1', ['a' => 1], WaWebhookEvent::STORED);
        $old->forceFill(['received_at' => now()->subDays(45)])->save();
        $edge = WaWebhookEvent::store('1427706937088002', 'E2', ['a' => 2], WaWebhookEvent::STORED);
        $edge->forceFill(['received_at' => now()->subDays(29)])->save();
        $fresh = WaWebhookEvent::store('1427706937088002', 'E3', ['a' => 3], WaWebhookEvent::STORED);

        $this->artisan('wa:prune-events')
            ->expectsOutputToContain('Pruned 1 webhook events older than 30 days.')
            ->assertSuccessful();

        $this->assertSame(2, WaWebhookEvent::count());
        $this->assertDatabaseMissing('wa_webhook_events', ['id' => $old->id]);
        $this->assertDatabaseHas('wa_webhook_events', ['id' => $edge->id]);
        $this->assertDatabaseHas('wa_webhook_events', ['id' => $fresh->id]);
    }

    public function test_a_custom_window_is_honoured()
    {
        $old = WaWebhookEvent::store('1', 'E1', [], WaWebhookEvent::STORED);
        $old->forceFill(['received_at' => now()->subDays(3)])->save();

        $this->artisan('wa:prune-events', ['--days' => 1])
            ->expectsOutputToContain('Pruned 1 webhook events older than 1 days.')
            ->assertSuccessful();
        $this->assertSame(0, WaWebhookEvent::count());
    }

    // R55: the plan's row tested only 0 while its name promised "zero or negative" -- a provider
    // makes the name honest (the Task 12 lesson: assertions must match the name).
    public static function refusedWindowProvider(): array
    {
        return ['zero' => [0], 'negative' => [-3]];
    }

    #[DataProvider('refusedWindowProvider')]
    public function test_a_zero_or_negative_window_is_refused(int $days)
    {
        WaWebhookEvent::store('1', 'E1', [], WaWebhookEvent::STORED);
        $this->artisan('wa:prune-events', ['--days' => $days])
            ->expectsOutputToContain('The retention window must be at least 1 day.')
            ->assertExitCode(1);
        $this->assertSame(1, WaWebhookEvent::count());
    }

    // R55 (R42 gap): the plan verified schedule registration only by a human running schedule:list.
    // This row boots the console routes, then pins both events on the container's Schedule singleton:
    // the prune at 03:10 and the queue drain every minute, withoutOverlapping, with both flags.
    public function test_the_schedule_registers_pruning_and_the_queue_drain()
    {
        $this->artisan('schedule:list')->assertSuccessful();
        $events = collect(app(Schedule::class)->events());

        $prune = $events->first(fn ($e) => str_contains($e->command, 'wa:prune-events'));
        $this->assertNotNull($prune, 'wa:prune-events is not scheduled');
        $this->assertSame('10 3 * * *', $prune->expression);

        $drain = $events->first(fn ($e) => str_contains($e->command, 'queue:work'));
        $this->assertNotNull($drain, 'the queue drain is not scheduled');
        $this->assertSame('* * * * *', $drain->expression);
        $this->assertTrue($drain->withoutOverlapping);
        $this->assertStringContainsString('--stop-when-empty', $drain->command);
        $this->assertStringContainsString('--max-time=50', $drain->command);
    }
}
