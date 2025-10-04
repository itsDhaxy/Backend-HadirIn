<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\FaceController;

Route::post('/face-verify', [FaceController::class, 'verifyFace']);

