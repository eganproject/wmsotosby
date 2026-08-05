<?php

namespace App\Models\Concerns;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Alur persetujuan yang dipakai bersama oleh barang masuk, barang keluar,
 * dan penerimaan retur.
 *
 * draft ──ajukan──► diajukan ──setujui──► diposting (stok bergerak)
 *   ▲                   │
 *   └───────tolak───────┘
 */
trait HasApprovalWorkflow
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_REJECTED = 'rejected';

    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function rejecter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rejected_by');
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function isRejected(): bool
    {
        return $this->status === self::STATUS_REJECTED;
    }

    /**
     * Dokumen hanya boleh diubah selama belum diajukan.
     */
    public function isEditable(): bool
    {
        return in_array($this->status, [self::STATUS_DRAFT, self::STATUS_REJECTED], true);
    }

    /**
     * Label status untuk ditampilkan di tabel dan badge.
     */
    public function statusLabel(): string
    {
        return match ($this->status) {
            self::STATUS_PENDING => 'Menunggu persetujuan',
            self::STATUS_REJECTED => 'Ditolak',
            self::STATUS_POSTED => $this->postedLabel(),
            default => 'Draft',
        };
    }

    public function statusVariant(): string
    {
        return match ($this->status) {
            self::STATUS_PENDING => 'warning',
            self::STATUS_REJECTED => 'danger',
            self::STATUS_POSTED => 'success',
            default => 'neutral',
        };
    }

    public function statusIcon(): string
    {
        return match ($this->status) {
            self::STATUS_PENDING => 'clock',
            self::STATUS_REJECTED => 'x-circle',
            self::STATUS_POSTED => 'check-circle',
            default => 'document',
        };
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    /**
     * Label khusus tiap dokumen saat sudah diposting.
     */
    protected function postedLabel(): string
    {
        return 'Diposting';
    }
}
