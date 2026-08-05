<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('outbounds', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->date('date');
            $table->string('type')->default('regular'); // regular | marketplace
            $table->string('recipient');
            $table->string('marketplace')->nullable();
            $table->string('tracking_number')->nullable();
            $table->text('note')->nullable();
            $table->string('status')->default('draft');
            $table->timestamp('resi_verified_at')->nullable();
            $table->timestamp('posted_at')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            $table->index(['status', 'date']);
            $table->index('tracking_number');
        });

        Schema::create('outbound_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('outbound_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('quantity');
            $table->unsignedInteger('scanned_quantity')->default(0);
            $table->string('note')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('outbound_items');
        Schema::dropIfExists('outbounds');
    }
};
