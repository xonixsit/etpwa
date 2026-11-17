<?php

namespace App\Console\Commands;

use App\Models\WaWebhookEvent;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class WaPruneEvents extends Command
{
    protected $signature = 'wa:prune-events {--days= : Retention window in days}';

    protected $description = 'Delete webhook audit events older than the retention window';

    public function handle(): int
    {
        $days = (int) ($this->option('days') ?? config('whatsapp.event_retention_days', 30));
        if ($days < 1) {
            $this->error('The retention window must be at least 1 day.');

            return 1;
        }

        $cut = Carbon::now()->subDays($days);
        $deleted = 0;

        // Chunked deletes: a one-shot mass delete would lock the table on a big backlog.
        // chunkById paginates by id > last, so deleting inside the callback is safe.
        WaWebhookEvent::where('received_at', '<', $cut)
            ->orderBy('id')
            ->chunkById(500, function ($rows) use (&$deleted) {
                $deleted += $rows->count();
                WaWebhookEvent::whereIn('id', $rows->pluck('id'))->delete();
            });

        $this->line("Pruned {$deleted} webhook events older than {$days} days.");

        return 0;
    }
}
