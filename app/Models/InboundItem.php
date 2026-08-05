<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InboundItem extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'inbound_id',
        'product_id',
        'quantity',
        'note',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
        ];
    }

    public function inbound(): BelongsTo
    {
        return $this->belongsTo(Inbound::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
