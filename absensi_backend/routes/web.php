<?php

use App\Http\Controllers\AbsensiController;

Route::get('/absensi', [AbsensiController::class, 'index']);