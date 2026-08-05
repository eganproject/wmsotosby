<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OutboundItem extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'outbound_id',
        'product_id',
        'quantity',
        'scanned_quantity',
        'note',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'scanned_quantity' => 'integer',
        ];
    }

    public function outbound(): BelongsTo
    {
        return $this->belongsTo(Outbound::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function isFullyScanned(): bool
    {
        return $this->scanned_quantity >= $this->quantity;
    }

    public function remainingToScan(): int
    {
        return max(0, $this->quantity - $this->scanned_quantity);
    }
}
