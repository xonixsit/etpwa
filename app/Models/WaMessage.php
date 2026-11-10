<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WaMessage extends Model
{
    use HasFactory;

    public const IN = 'in';
    public const OUT = 'out';

    public const SENT = 'sent';
    public const DELIVERED = 'delivered';
    public const READ = 'read';
    public const FAILED = 'failed';
    public const INCOMING = 'incoming';
    public const DELETED = 'deleted';

    protected $fillable = [
        'wa_contact_id', 'meta_id', 'direction', 'type', 'body', 'template_name',
        'template_lang', 'status', 'status_at', 'error_code', 'error_title',
        'auto_reply', 'sent_at', 'raw',
    ];

    protected function casts(): array
    {
        return [
            'body' => 'encrypted',
            'raw' => 'encrypted:array',
            'status_at' => 'datetime',
            'sent_at' => 'datetime',
            'auto_reply' => 'boolean',
        ];
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(WaContact::class);
    }

    public static function record(array $attrs): self
    {
        $id = $attrs['meta_id'] ?? null;
        if ($id !== null && $id !== '') {
            return self::updateOrCreate(['meta_id' => $id], $attrs);
        }

        return self::create($attrs);
    }

    public static function findByMetaId(?string $id): ?self
    {
        if ($id === null || $id === '') {
            return null;
        }

        return self::where('meta_id', $id)->first();
    }

    private const RANK = [
        self::SENT => 1,
        self::DELIVERED => 2,
        self::READ => 3,
    ];

    public function applyStatus(string $status, CarbonInterface $at, ?string $code, ?string $title): void
    {
        $current = self::RANK[$this->status] ?? 0;
        $incoming = self::RANK[$status] ?? 0;

        if ($status === self::FAILED || $status === self::DELETED) {
            $this->update([
                'status' => $status, 'status_at' => $at,
                'error_code' => $code, 'error_title' => $title,
            ]);

            return;
        }

        if ($incoming > $current) {
            $this->update(['status' => $status, 'status_at' => $at]);
        }
    }
}