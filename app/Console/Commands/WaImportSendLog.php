<?php

namespace App\Console\Commands;

use App\Models\WaContact;
use App\Models\WaMessage;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class WaImportSendLog extends Command
{
    protected $signature = 'wa:import-send-log {path : CSV exported by the send bench} {--apply}';

    protected $description = 'Seed wa_contacts and wa_messages from send_log.csv';

    public function handle(): int
    {
        $path = (string) $this->argument('path');
        if (! is_file($path) || ! is_readable($path)) {
            $this->error('File not found or unreadable: '.$path);

            return 1;
        }

        $handle = fopen($path, 'rb');
        $header = fgetcsv($handle);
        if (! is_array($header) || ! in_array('to', $header, true)) {
            $this->error('Not a send log: expected a header row containing "to".');

            return 1;
        }

        $messages = $skipped = 0;
        $distinctPhones = [];
        $apply = (bool) $this->option('apply');

        while (($row = fgetcsv($handle)) !== false) {
            // R54/F3: fgetcsv returns [null] for a blank line, not false. Guard before any counting,
            // or the plan fixture's trailing blank line becomes a phantom skip.
            if ($row === [null]) {
                continue;
            }

            $row = array_combine($header, array_pad($row, count($header), null));
            $phone = WaContact::digitsOnly($row['to'] ?? '');
            // R54/F2: the guard is fromPhone()'s own predicate (10..20 digits, the column width),
            // not strlen >= 10 -- a 21-digit phone passed the narrow guard and crashed --apply
            // mid-loop with partial writes. Undialable rows are counted skipped, like refusals.
            if (! WaContact::isDialable($phone)) {
                $skipped++;

                continue;
            }

            $wamid = trim((string) ($row['message_id'] ?? ''));
            if ($wamid === '') {
                $skipped++;

                continue;
            }

            $distinctPhones[$phone] = true;
            $messages++;

            if (! $apply) {
                continue;
            }

            $contact = WaContact::fromPhone($phone);
            $sentAt = Carbon::hasFormat((string) $row['utc_time'], 'Y-m-d\TH:i:s\Z')
                ? Carbon::parse($row['utc_time'], 'UTC')
                : now();

            WaMessage::record([
                'wa_contact_id' => $contact->id,
                'meta_id' => $wamid,
                'direction' => WaMessage::OUT,
                'type' => 'template',
                'template_name' => $row['template'] ?? null,
                'template_lang' => $row['language'] ?? null,
                'status' => ($row['status'] ?? '') === 'sent' ? WaMessage::SENT : WaMessage::FAILED,
                'sent_at' => $sentAt,
                'raw' => ['source' => 'send_log_import'],
            ]);
        }
        fclose($handle);
        $contacts = count($distinctPhones);

        $this->line($apply ? "Imported {$messages} messages, {$contacts} contacts, {$skipped} skipped."
                           : "DRY RUN: would import {$messages} messages, {$contacts} contacts, {$skipped} skipped.");
        if (! $apply) {
            $this->line('Re-run with --apply to write.');
        }

        return 0;
    }
}
