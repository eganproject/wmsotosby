<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StockApiAllowedIp extends Model
{
    protected $fillable = ['ip_address', 'label', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }
}
