<?php

namespace App\Support;

trait NormalizesScanCode
{
    /**
     * Samakan format kode sebelum dibandingkan: scanner sering menyisipkan
     * spasi, dan operator kadang mengetik dengan huruf kecil.
     */
    protected function normalize(?string $value): string
    {
        return strtoupper(preg_replace('/\s+/', '', (string) $value));
    }
}
