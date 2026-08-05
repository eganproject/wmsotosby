<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('suppliers', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->string('contact_name')->nullable();
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->text('address')->nullable();
            $table->string('note')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('name');
        });

        Schema::table('inbounds', function (Blueprint $table) {
            $table->foreignId('supplier_id')->nullable()->after('date')->constrained()->nullOnDelete();
        });

        // Nama pemasok lama dipindahkan ke master pemasok agar tidak ada data hilang.
        $names = DB::table('inbounds')->whereNotNull('supplier')->distinct()->pluck('supplier');

        foreach ($names as $index => $name) {
            if (blank($name)) {
                continue;
            }

            $id = DB::table('suppliers')->insertGetId([
                'code' => 'SUP-'.str_pad((string) ($index + 1), 4, '0', STR_PAD_LEFT),
                'name' => $name,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('inbounds')->where('supplier', $name)->update(['supplier_id' => $id]);
        }

        Schema::table('inbounds', function (Blueprint $table) {
            $table->dropColumn('supplier');
        });
    }

    public function down(): void
    {
        Schema::table('inbounds', function (Blueprint $table) {
            $table->string('supplier')->nullable()->after('date');
            $table->dropForeign(['supplier_id']);
            $table->dropColumn('supplier_id');
        });

        Schema::dropIfExists('suppliers');
    }
};
