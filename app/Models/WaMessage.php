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
        $row = ($id === null || $id === '') ? null : self::where('meta_id', $id)->first();

        if ($row === null) {
            return self::create($attrs);
        }

        if ($row->direction === self::IN || !self::mayReplaceStatus($row->status, $attrs['status'] ?? null)) {
            unset($attrs['status'], $attrs['status_at'], $attrs['error_code'], $attrs['error_title']);
        }
        unset($attrs['wa_contact_id'], $attrs['direction']);

        $row->update($attrs);

        return $row;
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

    private const TERMINAL = [self::FAILED, self::DELETED];

    /**
     * R30: the one rule for changing a delivery state. Both writers go through it -- applyStatus()
     * for a receipt and record() for a message item -- because the round-1 hole was exactly that
     * record() had no opinion. A status may enter a terminal state from anywhere (a failure is
     * always believed, and believed late: Meta does not order its callbacks) and may leave one only
     * into another terminal state. Otherwise it has to move strictly forward, so a duplicate
     * `delivered` cannot even rewrite `status_at`. `unknown` and `incoming` are deliberately
     * unranked: they are not evidence of delivery, so they cannot outrank a receipt.
     */
    private static function mayReplaceStatus(?string $current, ?string $next): bool
    {
        if ($next === null) {
            return false;
        }

        if ($current === null) {
            return true;
        }

        if (in_array($current, self::TERMINAL, true)) {
            return in_array($next, self::TERMINAL, true);
        }

        return in_array($next, self::TERMINAL, true)
            || (self::RANK[$next] ?? 0) > (self::RANK[$current] ?? 0);
    }

    public function applyStatus(string $status, CarbonInterface $at, ?string $code = null, ?string $title = null): void
    {
        if ($this->direction === self::IN || !self::mayReplaceStatus($this->status, $status)) {
            return;
        }

        $terminal = in_array($status, self::TERMINAL, true);

        $this->update([
            'status' => $status,
            'status_at' => $at,
            'error_code' => $terminal ? $code : null,
            'error_title' => $terminal ? $title : null,
        ]);
    }
}