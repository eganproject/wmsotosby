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
        'damaged_quantity',
        'note',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'damaged_quantity' => 'integer',
        ];
    }

    /**
     * Unit yang benar-benar layak jual dari kiriman ini.
     *
     * quantity adalah jumlah yang diterima seluruhnya; yang rusak adalah bagian
     * darinya, bukan tambahan di luarnya — sama seperti pada penerimaan retur.
     */
    public function goodQuantity(): int
    {
        return max(0, $this->quantity - $this->damaged_quantity);
    }

    public function hasDamaged(): bool
    {
        return $this->damaged_quantity > 0;
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
