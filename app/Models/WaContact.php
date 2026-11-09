<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WaContact extends Model
{
    use HasFactory;

    protected $fillable = [
        'phone', 'profile_name', 'opted_out_at', 'opt_out_source', 'last_inbound_at',
    ];

    protected function casts(): array
    {
        return ['opted_out_at' => 'datetime', 'last_inbound_at' => 'datetime'];
    }

    public static function digitsOnly(?string $raw): string
    {
        return preg_replace('/\D/', '', (string) $raw);
    }

    public static function fromPhone(string $raw): self
    {
        $phone = self::digitsOnly($raw);
        if ($phone === '' || strlen($phone) < 10) {
            throw new \InvalidArgumentException("not an E.164 number: {$raw}");
        }

        return self::firstOrCreate(['phone' => $phone]);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(WaMessage::class)->orderBy('sent_at');
    }

    public function isOptedOut(): bool
    {
        return $this->opted_out_at !== null;
    }

    public function optOut(string $source): void
    {
        if ($this->isOptedOut()) {
            return;
        }
        $this->update(['opted_out_at' => now(), 'opt_out_source' => $source]);
    }

    public function windowOpen(): bool
    {
        $hours = (int) config('whatsapp.window_hours', 24);

        return $this->last_inbound_at !== null
            && $this->last_inbound_at->addHours($hours)->isFuture();
    }

    public function display_name(): string
    {
        return $this->profile_name !== '' && $this->profile_name !== null
            ? $this->profile_name
            : '+' . $this->phone;
    }
}