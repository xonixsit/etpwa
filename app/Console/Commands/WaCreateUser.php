<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

class WaCreateUser extends Command
{
    protected $signature = 'wa:create-user {email} {--password=}';

    protected $description = 'Create or reset the single staff account for the inbox';

    public function handle(): int
    {
        $email = trim((string) $this->argument('email'));
        $password = (string) ($this->option('password') ?? $this->secret('Password') ?? '');

        if ($email === '' || strlen($password) < 10) {
            $this->error('A password of at least 10 characters is required.');

            return 1;
        }

        $user = User::firstOrNew(['email' => $email]);
        $user->name = $user->name ?? 'E Tax Planner staff';
        $user->password = Hash::make($password);
        $user->save();

        $this->info('Staff account ready: '.$email);

        return 0;
    }
}
