<?php

namespace Tests\Feature;

use App\Models\WaContact;
use App\Models\WaMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ImportSendLogTest extends TestCase
{
    use RefreshDatabase;

    private function path(): string
    {
        return base_path('tests/Fixtures/send_log_sample.csv');
    }

    public function test_dry_run_imports_nothing_but_reports_the_counts()
    {
        // R54: ONE expectation pinning the whole line. In this Laravel version each doWrite call
        // satisfies at most one expectsOutputToContain (PendingCommand's Mockery matcher consumes
        // the call for the first matching substring), so the plan's two stacked substrings on this
        // single output line could never both match -- measured, not guessed.
        $this->artisan('wa:import-send-log', ['path' => $this->path()])
            ->expectsOutputToContain('DRY RUN: would import 2 messages, 2 contacts, 1 skipped')
            ->assertSuccessful();

        $this->assertSame(0, WaMessage::count());
        $this->assertSame(0, WaContact::count());
    }

    public function test_apply_creates_contacts_and_outbound_rows()
    {
        $this->artisan('wa:import-send-log', ['path' => $this->path(), '--apply' => true])
            ->assertSuccessful();

        $this->assertSame(2, WaContact::count());
        $this->assertSame(2, WaMessage::count());
        $this->assertSame(0, WaContact::where('phone', '15559998888')->count(),
            'a number the bench refused must not become a contact');

        $m = WaMessage::where('meta_id', 'wamid.BENCH_A')->firstOrFail();
        $this->assertSame(WaMessage::OUT, $m->direction);
        $this->assertSame('etp_opener_brand', $m->template_name);
        $this->assertSame('2026-11-05 14:03:11', $m->sent_at->toDateTimeString());
        $this->assertSame('send_log_import', $m->raw['source']);
        $this->assertFalse($m->contact->isOptedOut());
    }

    public function test_running_it_twice_changes_nothing()
    {
        $this->artisan('wa:import-send-log', ['path' => $this->path(), '--apply' => true]);
        $first = [WaContact::count(), WaMessage::count()];
        $this->artisan('wa:import-send-log', ['path' => $this->path(), '--apply' => true]);
        $this->assertSame($first, [WaContact::count(), WaMessage::count()]);
    }

    public function test_a_missing_file_fails_without_touching_the_database()
    {
        $this->artisan('wa:import-send-log', ['path' => base_path('tests/Fixtures/nope.csv'),
                                              '--apply' => true])
            ->expectsOutputToContain('not found')
            ->assertExitCode(1);
        $this->assertSame(0, WaMessage::count());
    }

    // ---- R54 witness rows (pre-flight F2/F3/F5; the malformed rows live in a SECOND fixture so the
    // plan's four rows and their counts stay untouched, per R43's warning) ----

    private function malformedPath(): string
    {
        return base_path('tests/Fixtures/send_log_malformed.csv');
    }

    // W-MALFORMED-DRY: kills the arm that narrows the phone guard back to strlen < 10 (a 21-digit
    // phone passes it and crashes fromPhone() mid-apply) and the arm that counts contacts per row
    // instead of by distinct phone (R43: the repeat number would print 3 contacts, not 1).
    public function test_malformed_phones_are_counted_skipped_and_repeats_count_one_contact()
    {
        $this->artisan('wa:import-send-log', ['path' => $this->malformedPath()])
            ->expectsOutputToContain('DRY RUN: would import 3 messages, 1 contacts, 2 skipped')
            ->assertSuccessful();

        $this->assertSame(0, WaMessage::count());
        $this->assertSame(0, WaContact::count());
    }

    // W-MALFORMED-APPLY: the 21-digit and 9-digit rows must be skipped without a crash and without
    // contacts; the wamid-carrying 'failed' row must import as FAILED, not SENT (R43 correction 2 --
    // kills the arm that hardcodes SENT); the two repeats keep their own sent_at.
    public function test_apply_skips_undialable_phones_and_reads_the_status_column()
    {
        $this->artisan('wa:import-send-log', ['path' => $this->malformedPath(), '--apply' => true])
            ->assertSuccessful();

        $this->assertSame(1, WaContact::count());
        $this->assertSame(3, WaMessage::count());
        $this->assertSame(0, WaContact::where('phone', '123456789012345678901')->count());
        $this->assertSame(0, WaContact::where('phone', '123456789')->count());

        $failed = WaMessage::where('meta_id', 'wamid.FAILED_ROW')->firstOrFail();
        $this->assertSame(WaMessage::FAILED, $failed->status);

        $this->assertSame('2026-11-05 14:05:00',
            WaMessage::where('meta_id', 'wamid.REPEAT_A')->value('sent_at')->toDateTimeString());
        $this->assertSame('2026-11-06 14:05:00',
            WaMessage::where('meta_id', 'wamid.REPEAT_B')->value('sent_at')->toDateTimeString());
    }

    // W-HEADER: kills the arm that deletes the header check -- a CSV that is not a send log must be
    // refused before any row is read, not imported through array_combine warnings.
    public function test_a_file_without_the_send_log_header_is_refused()
    {
        $path = sys_get_temp_dir() . '/wa-not-a-log-' . getmypid() . '.csv';
        file_put_contents($path, "name,phone\nJane,18479043315\n");
        try {
            $this->artisan('wa:import-send-log', ['path' => $path, '--apply' => true])
                ->expectsOutputToContain('Not a send log')
                ->assertExitCode(1);
            $this->assertSame(0, WaMessage::count());
            $this->assertSame(0, WaContact::count());
        } finally {
            unlink($path);
        }
    }
}
