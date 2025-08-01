<?php

use App\Http\Controllers\KtpController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum'])->get('/user', function (Request $request) {
    return $request->user();
});

Route::post('/ktp/scan', [KtpController::class, 'scan']);
