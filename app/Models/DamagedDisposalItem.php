<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DamagedDisposalItem extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'damaged_disposal_id',
        'product_id',
        'quantity',
        'note',
    ];

    protected function casts(): array
    {
        return ['quantity' => 'integer'];
    }

    public function disposal(): BelongsTo
    {
        return $this->belongsTo(DamagedDisposal::class, 'damaged_disposal_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
