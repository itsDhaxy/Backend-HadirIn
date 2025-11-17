<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void {
        Schema::table('absensis', function (Blueprint $table) {
            // tanggal & jam terpisah
            $table->date('day')->nullable()->after('name');
            $table->time('check_in_time')->nullable()->after('day');
            $table->time('check_out_time')->nullable()->after('check_in_time');

            // status (opsional)
            $table->enum('check_in_status', ['On Time','Late'])->nullable()->after('check_out_time');
            $table->enum('check_out_status', ['On Time','Early'])->nullable()->after('check_in_status');

            // jarak/gap dari FastAPI (opsional)
            $table->float('distance')->nullable()->after('check_out_status');
            $table->float('gap')->nullable()->after('distance');

            // baris unik per orang per hari
            $table->unique(['name','day']);
            $table->index(['day']);
        });

        // backfill sederhana dari kolom lama `time` kalau ada
        try {
            DB::statement("
                UPDATE absensis
                SET day = DATE(`time`),
                    check_in_time = TIME(`time`)
                WHERE `time` IS NOT NULL AND day IS NULL
            ");
        } catch (\Throwable $e) {}
    }

    public function down(): void {
        Schema::table('absensis', function (Blueprint $table) {
            $table->dropUnique(['name','day']);
            $table->dropIndex(['day']);
            $table->dropColumn([
                'day','check_in_time','check_out_time',
                'check_in_status','check_out_status',
                'distance','gap'
            ]);
        });
    }
};