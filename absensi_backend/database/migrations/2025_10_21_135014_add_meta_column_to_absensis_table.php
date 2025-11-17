<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
  public function up(): void
  {
    Schema::table('absensis', function (Blueprint $table) {
      if (!Schema::hasColumn('absensis', 'meta')) {
        $table->json('meta')->nullable()->after('gap');
      }
    });
  }
  public function down(): void
  {
    Schema::table('absensis', function (Blueprint $table) {
      $table->dropColumn('meta');
    });
  }
};
