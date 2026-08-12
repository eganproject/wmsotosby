<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_api_sync_records', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')->nullable()->index();
            $table->string('sku', 100)->unique();
            $table->string('name');
            $table->string('category')->nullable();
            $table->string('uom', 30)->default('pcs');
            $table->decimal('qty', 18, 3)->default(0);
            $table->decimal('min_qty', 18, 3)->nullable();
            $table->string('status', 10)->index();
            $table->timestamp('source_updated_at')->index();
            $table->timestamps();
            $table->index(['source_updated_at', 'sku']);
        });

        Schema::create('stock_api_allowed_ips', function (Blueprint $table): void {
            $table->id();
            $table->string('ip_address', 45)->unique();
            $table->string('label', 150)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_api_allowed_ips');
        Schema::dropIfExists('stock_api_sync_records');
    }
};
