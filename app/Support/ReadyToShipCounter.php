<?php

namespace App\Support;

use App\Models\Outbound;

/**
 * Jumlah paket yang sudah lengkap discan tetapi belum dikirim.
 *
 * Dipakai badge di menu samping supaya antrean packing terlihat tanpa perlu
 * membuka halamannya — itu satu-satunya isyarat bahwa ada pekerjaan menumpuk.
 */
class ReadyToShipCounter
{
    public static function count(): int
    {
        return Outbound::readyToShip()->count();
    }
}
