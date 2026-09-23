<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ClientController;
use Illuminate\Support\Facades\Route;

Route::get('/health', fn () => response()->json(['success' => true, 'app' => 'CUPAD Laravel']));

Route::post('/auth/login', [AuthController::class, 'login']);

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/me', [AuthController::class, 'me']);
    Route::apiResource('clients', ClientController::class)->only(['index', 'show']);
    Route::get('/clients/{client}/portfolio', [ClientController::class, 'portfolio']);
});
