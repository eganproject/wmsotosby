<?php

namespace App\Console\Commands;

use App\Models\StockApiAllowedIp;
use Illuminate\Console\Command;

class AllowStockApiIp extends Command
{
    protected $signature = 'stock-api:allow-ip {ip : Alamat IPv4 atau IPv6} {--label= : Keterangan sumber}';

    protected $description = 'Menambahkan atau mengaktifkan IP pemanggil API stok';

    public function handle(): int
    {
        $ip = (string) $this->argument('ip');
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            $this->error('Alamat IP tidak valid.');

            return self::FAILURE;
        }

        StockApiAllowedIp::query()->updateOrCreate(
            ['ip_address' => $ip],
            ['label' => $this->option('label') ?: null, 'is_active' => true],
        );

        $this->info("IP {$ip} diizinkan mengakses API stok.");

        return self::SUCCESS;
    }
}
