<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        /* =======================
         *  ROLES
         * ======================= */
        if (!Schema::hasTable('roles')) {
            Schema::create('roles', function (Blueprint $t) {
                $t->bigIncrements('id');
                $t->string('status_name');       // ex: Admin, HR, Karyawan
                $t->string('desc')->nullable();  // deskripsi role (opsional)
                $t->timestamps();
                $t->unique('status_name');
            });
        }

        /* =======================
         *  DEPARTEMENS
         * ======================= */
        if (!Schema::hasTable('departemens')) {
            Schema::create('departemens', function (Blueprint $t) {
                $t->bigIncrements('id');
                $t->string('departemen_name');
                $t->string('description')->nullable();
                $t->timestamps();
                $t->unique('departemen_name');
            });
        }

        /* =======================
         *  SHIFTS (template jam kerja)
         * ======================= */
        if (!Schema::hasTable('shifts')) {
            Schema::create('shifts', function (Blueprint $t) {
                $t->bigIncrements('id');
                $t->string('shift_name');        // ex: Regular, Shift A
                $t->time('start_time');          // pakai TIME biar portable
                $t->time('end_time');
                $t->unsignedInteger('grace_minutes')->default(0);
                $t->timestamps();
                $t->unique(['shift_name','start_time','end_time']);
            });
        }

        /* =======================
         *  PEGAWAIS
         * ======================= */
        if (!Schema::hasTable('pegawais')) {
            Schema::create('pegawais', function (Blueprint $t) {
                $t->bigIncrements('id');

                // Identitas dasar
                $t->string('nama');
                $t->string('nik')->nullable()->unique();
                $t->string('nip')->nullable()->unique();
                $t->date('tanggal_lahir')->nullable();
                $t->tinyInteger('jenis_kelamin')->nullable(); // 0=Perempuan, 1=Laki
                $t->string('alamat')->nullable();
                $t->string('telepon')->nullable();
                $t->date('tanggal_mulai')->nullable();

                // Relasi (nullable dulu supaya aman di awal)
                if (Schema::hasTable('users')) {
                    $t->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
                } else {
                    $t->unsignedBigInteger('user_id')->nullable();
                }

                $t->foreignId('shift_id')->nullable()->constrained('shifts')->nullOnDelete();
                $t->foreignId('departemen_id')->nullable()->constrained('departemens')->nullOnDelete();

                $t->timestamps();

                $t->index('nama');
            });
        }
    }

    public function down(): void
    {
        // Urutan drop terbalik untuk menghindari FK constraint
        Schema::dropIfExists('pegawais');
        Schema::dropIfExists('shifts');
        Schema::dropIfExists('departemens');
        Schema::dropIfExists('roles');
    }
};
