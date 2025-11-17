<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\FaceController;

Route::post('/face-verify', [FaceController::class, 'verifyFace']);
Route::post('/attendance', [FaceController::class, 'storeFromFastapiJson']);
Route::get('/admin/attendance/today', [\App\Http\Controllers\AdminAttendanceController::class, 'today']);
Route::post('/admin/attendance/update', [\App\Http\Controllers\AdminAttendanceController::class, 'updateToday']);


