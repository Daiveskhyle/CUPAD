<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ClientController;
use App\Http\Controllers\Api\SavingsController;
use App\Http\Controllers\Api\LoanCollectionController;
use App\Http\Controllers\Api\CombinedCollectionController;
use App\Http\Controllers\Api\LoanDisbursementController;
use App\Http\Controllers\Api\ClientRegistrationController;
use App\Http\Controllers\Api\MobileDashboardController;
use Illuminate\Support\Facades\Route;

Route::get('/health', fn () => response()->json(['success' => true, 'app' => 'CUPAD Laravel']));
Route::post('/auth/login', [AuthController::class, 'login']);

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/me', [AuthController::class, 'me']);
    Route::match(['put', 'post'], '/profile', [AuthController::class, 'profile']);

    Route::get('/dashboard/stats', [MobileDashboardController::class, 'stats']);
    Route::get('/activities', [MobileDashboardController::class, 'activities']);

    Route::apiResource('clients', ClientController::class)->only(['index', 'show']);
    Route::get('/clients/{client}/portfolio', [ClientController::class, 'portfolio']);
    Route::get('/clients/{client}/savings', [ClientController::class, 'savings']);
    Route::get('/clients/{client}/loans', [ClientController::class, 'loans']);
    Route::get('/clients/{client}/transactions', [ClientController::class, 'transactions']);
    Route::post('/clients/register', [ClientRegistrationController::class, 'store']);

    Route::post('/savings/collect', [SavingsController::class, 'collect']);
    Route::post('/savings/withdraw', [SavingsController::class, 'withdraw']);

    Route::post('/loans/collect', [LoanCollectionController::class, 'collect']);
    Route::post('/loans/disburse', [LoanDisbursementController::class, 'store']);

    Route::get('/combined/union-data', [CombinedCollectionController::class, 'unionData']);
    // Keep the legacy mobile contract (/combined/save) while retaining the descriptive alias.
    Route::post('/combined/save', [CombinedCollectionController::class, 'saveClient']);
    Route::post('/combined/save-client', [CombinedCollectionController::class, 'saveClient']);
});
