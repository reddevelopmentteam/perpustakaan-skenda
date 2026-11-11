<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Menambahkan kolom loan_id yang hilang pada rancangan awal Tahap 4.
     */
    public function up(): void
    {
        Schema::table('loan_extensions', function (Blueprint $table) {
            $table->foreignId('loan_id')->after('id')->constrained('loans')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('loan_extensions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('loan_id');
        });
    }
};
