<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\ClientController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\Admin\DashboardController as AdminDashboardController;
use App\Http\Controllers\Co\CombinedCollectionController as CoCombinedCollectionController;
use App\Http\Controllers\Co\RegistrationController as CoRegistrationController;
use App\Http\Controllers\Co\HistoryController as CoHistoryController;
use App\Http\Controllers\Co\AnalyticsController as CoAnalyticsController;
use App\Http\Controllers\Co\ClientsController as CoClientsController;
use App\Http\Controllers\Bm\DashboardController as BmDashboardController;
use App\Http\Controllers\Bm\ClientsController as BmClientsController;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => redirect()->route('login'));

Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
    Route::post('/login', [AuthController::class, 'login'])->name('login.store');
});

Route::middleware('auth')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');
    Route::get('/clients', [ClientController::class, 'index'])->name('clients.index');
    Route::get('/clients/{client}', [ClientController::class, 'show'])->name('clients.show');

    Route::middleware('role:admin')->prefix('admin')->name('admin.')->group(function () {
        Route::get('/dashboard', [AdminDashboardController::class, 'index'])->name('dashboard');
    });

    Route::middleware('role:bm')->prefix('bm')->name('bm.')->group(function () {
        Route::get('/dashboard', [BmDashboardController::class, 'index'])->name('dashboard');
        Route::get('/activities', [BmDashboardController::class, 'activities'])->name('activities');
        Route::get('/notifications', [BmDashboardController::class, 'notifications'])->name('notifications');
        Route::post('/notifications/read', [BmDashboardController::class, 'markNotificationsRead'])->name('notifications.read');
        Route::get('/clients', [BmClientsController::class, 'index'])->name('clients');
        Route::get('/clients/data', [BmClientsController::class, 'data'])->name('clients.data');
        Route::get('/clients/{client}/history', [BmClientsController::class, 'history'])->name('clients.history');
        Route::put('/clients/{client}', [BmClientsController::class, 'update'])->name('clients.update');
    });

    Route::middleware('role:co')->prefix('co')->name('co.')->group(function () {
        Route::get('/combined-collection', [CoCombinedCollectionController::class, 'index'])->name('combined');
        Route::get('/clients', [CoClientsController::class, 'index'])->name('clients');
        Route::get('/clients/data', [CoClientsController::class, 'data'])->name('clients.data');
        Route::get('/clients/{client}/history', [CoClientsController::class, 'history'])->name('clients.history');
        Route::put('/clients/{client}', [CoClientsController::class, 'update'])->name('clients.update');
        Route::get('/combined-collection/data', [CoCombinedCollectionController::class, 'data'])->name('combined.data');
        Route::post('/combined-collection/save', [CoCombinedCollectionController::class, 'save'])->name('combined.save');
        Route::get('/registration', [CoRegistrationController::class, 'index'])->name('registration');
        Route::get('/registration/stats', [CoRegistrationController::class, 'stats'])->name('registration.stats');
        Route::get('/registration/duplicate', [CoRegistrationController::class, 'checkDuplicate'])->name('registration.duplicate');
        Route::post('/registration', [CoRegistrationController::class, 'store'])->name('registration.store');
        Route::get('/history', [CoHistoryController::class, 'index'])->name('history');
        Route::get('/history/data', [CoHistoryController::class, 'data'])->name('history.data');
        Route::get('/analytics', [CoAnalyticsController::class, 'index'])->name('analytics');
        Route::get('/analytics/data', [CoAnalyticsController::class, 'data'])->name('analytics.data');
        Route::get('/analytics/union-details', [CoAnalyticsController::class, 'unionDetails'])->name('analytics.union-details');
    });
});
