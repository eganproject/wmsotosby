<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inbounds', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->date('date');
            $table->string('supplier')->nullable();
            $table->string('reference')->nullable();
            $table->text('note')->nullable();
            $table->string('status')->default('draft');
            $table->timestamp('posted_at')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            $table->index(['status', 'date']);
        });

        Schema::create('inbound_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('inbound_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('quantity');
            $table->string('note')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inbound_items');
        Schema::dropIfExists('inbounds');
    }
};
