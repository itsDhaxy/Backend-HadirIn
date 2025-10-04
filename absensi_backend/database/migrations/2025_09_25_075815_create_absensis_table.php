<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('absensis', function (Blueprint $table) {
            $table->id();
            $table->string('name');       // nama karyawan
            $table->timestamp('time');    // jam absensi
            $table->timestamps();         // created_at & updated_at
        });
    }

    public function down(): void {
        Schema::dropIfExists('absensis');
    }
};
