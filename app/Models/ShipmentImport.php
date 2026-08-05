<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Satu berkas hasil eksport Ginee yang sudah diproses.
 */
class ShipmentImport extends Model
{
    protected $fillable = [
        'filename',
        'source',
        'row_count',
        'order_count',
        'item_count',
        'unmatched_sku_count',
        'detected_columns',
        'user_id',
    ];

    protected function casts(): array
    {
        return [
            'detected_columns' => 'array',
        ];
    }

    public function orders(): HasMany
    {
        return $this->hasMany(ShipmentOrder::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
