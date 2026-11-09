<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WaWebhookEvent extends Model
{
    public const STORED = 'stored';
    public const DUPLICATE = 'duplicate';
    public const IGNORED = 'ignored';
    public const REJECTED = 'rejected_signature';

    protected $fillable = [
        'received_at', 'entry_id', 'phone_number_id', 'changes', 'signature_valid', 'status',
    ];

    protected function casts(): array
    {
        return ['received_at' => 'datetime', 'changes' => 'encrypted:array'];
    }

    public static function store(?string $phoneNumberId, ?string $entryId, array $changes, string $status): self
    {
        return self::create([
            'received_at' => now(),
            'entry_id' => $entryId,
            'phone_number_id' => $phoneNumberId,
            'changes' => $changes,
            'status' => $status,
        ]);
    }
}