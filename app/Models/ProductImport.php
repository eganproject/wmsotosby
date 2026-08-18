<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductImport extends Model
{
    protected $fillable = [
        'filename',
        'row_count',
        'created_count',
        'updated_count',
        'stock_adjusted_count',
        'bundle_skipped_count',
        'detected_columns',
        'user_id',
    ];

    protected function casts(): array
    {
        return [
            'detected_columns' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
