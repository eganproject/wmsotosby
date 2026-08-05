<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Alur persetujuan dokumen: draft → diajukan → disetujui (stok bergerak)
 * atau ditolak dan dikembalikan ke draft.
 */
return new class extends Migration
{
    protected array $tables = ['inbounds', 'outbounds', 'return_receipts'];

    public function up(): void
    {
        foreach ($this->tables as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->timestamp('submitted_at')->nullable()->after('status');
                $table->foreignId('submitted_by')->nullable()->after('submitted_at')->constrained('users')->nullOnDelete();
                $table->timestamp('approved_at')->nullable()->after('submitted_by');
                $table->foreignId('approved_by')->nullable()->after('approved_at')->constrained('users')->nullOnDelete();
                $table->timestamp('rejected_at')->nullable()->after('approved_by');
                $table->foreignId('rejected_by')->nullable()->after('rejected_at')->constrained('users')->nullOnDelete();
                $table->string('rejection_reason')->nullable()->after('rejected_by');
            });
        }
    }

    public function down(): void
    {
        foreach ($this->tables as $name) {
            Schema::table($name, function (Blueprint $table) {
                foreach (['submitted_by', 'approved_by', 'rejected_by'] as $column) {
                    $table->dropForeign([$column]);
                }

                $table->dropColumn([
                    'submitted_at', 'submitted_by',
                    'approved_at', 'approved_by',
                    'rejected_at', 'rejected_by', 'rejection_reason',
                ]);
            });
        }
    }
};
