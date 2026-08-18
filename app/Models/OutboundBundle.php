<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Paket yang dipesan pada satu dokumen barang keluar.
 *
 * Baris ini tidak menggerakkan stok apa pun — yang bergerak adalah komponen
 * di outbound_items. Gunanya menjelaskan asal-usul: tanpa ini dokumen hanya
 * berupa daftar barang lepas, dan tidak ada yang bisa menjawab kenapa ada
 * tiga botol oli di paket yang pembelinya memesan dua paket servis.
 */
class OutboundBundle extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'outbound_id',
        'bundle_id',
        'quantity',
        'composition',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'composition' => 'array',
        ];
    }

    public function outbound(): BelongsTo
    {
        return $this->belongsTo(Outbound::class);
    }

    public function bundle(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'bundle_id');
    }

    /**
     * Unit komponen yang dihasilkan paket ini pada dokumennya.
     */
    public function totalUnits(): int
    {
        return collect($this->composition)->sum(fn (array $line) => (int) $line['quantity']) * $this->quantity;
    }
}
