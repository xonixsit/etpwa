<?php

namespace App\Models;

use Carbon\CarbonInterface;
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

    /**
     * The single definition of "this digits string is a phone we can store". fromPhone()
     * throws below it, and WebhookParser drops below it too (Ruling R28) so the exception
     * is unreachable from callback data. Keeping the two on one predicate is the point:
     * duplicated literals drift, and drift here means either a queued job that fails three
     * times per callback or a callback item silently vanishing. The ceiling is the width of
     * wa_contacts.phone (varchar 20), not the E.164 standard's 15 digits: a phone over the
     * column is not a number we can store, and Ruling R29 drops rather than truncates it.
     */
    public static function isDialable(string $digits): bool
    {
        return strlen($digits) >= 10 && strlen($digits) <= 20;
    }

    public static function fromPhone(string $raw): self
    {
        $phone = self::digitsOnly($raw);
        if (! self::isDialable($phone)) {
            throw new \InvalidArgumentException("not an E.164 number: {$raw}");
        }

        return self::firstOrCreate(['phone' => $phone]);
    }

    public function touchInbound(CarbonInterface $at): void
    {
        if ($this->last_inbound_at === null || $at->greaterThan($this->last_inbound_at)) {
            $this->update(['last_inbound_at' => $at]);
        }
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
            : '+'.$this->phone;
    }
}
