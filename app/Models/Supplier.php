<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Supplier extends Model
{
    protected $fillable = [
        'code',
        'name',
        'contact_name',
        'phone',
        'email',
        'address',
        'note',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function inbounds(): HasMany
    {
        return $this->hasMany(Inbound::class);
    }

    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        return $query->when($term, function (Builder $query, string $term) {
            $query->where(function (Builder $query) use ($term) {
                $query->where('name', 'like', "%{$term}%")
                    ->orWhere('code', 'like', "%{$term}%")
                    ->orWhere('contact_name', 'like', "%{$term}%")
                    ->orWhere('phone', 'like', "%{$term}%");
            });
        });
    }

    /**
     * Kode pemasok berikutnya, contoh: SUP-0007.
     */
    public static function nextCode(): string
    {
        $last = static::where('code', 'like', 'SUP-%')->max('code');

        $sequence = $last ? ((int) substr($last, -4)) + 1 : 1;

        return 'SUP-'.str_pad((string) $sequence, 4, '0', STR_PAD_LEFT);
    }
}
