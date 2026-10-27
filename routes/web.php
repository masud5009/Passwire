<?php

use App\Http\Controllers\AdminAuthController;
use App\Http\Controllers\AdminDashboardController;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

// Redirect root to dashboard if logged in, or login
Route::get('/', function () {
    return Auth::check() ? redirect()->route('dashboard') : redirect()->route('login');
});

// Authentication routes
Route::get('/login', [AdminAuthController::class, 'showLogin'])->name('login');
Route::post('/login', [AdminAuthController::class, 'login']);
Route::post('/logout', [AdminAuthController::class, 'logout'])->name('logout');

// Protected Dashboard routes
Route::middleware('auth')->prefix('dashboard')->name('dashboard.')->group(function () {
    // Dedicated page routes
    Route::get('/', [AdminDashboardController::class, 'overview'])->name('index');
    Route::get('/overview', [AdminDashboardController::class, 'overview'])->name('overview');
    Route::get('/providers', [AdminDashboardController::class, 'providers'])->name('providers');
    Route::get('/providers/{provider}', [AdminDashboardController::class, 'providerDetail'])->name('providers.show');
    Route::post('/providers/{provider}/import-models', [AdminDashboardController::class, 'importModels'])->name('providers.import-models');
    Route::get('/combos', [AdminDashboardController::class, 'combos'])->name('combos');
    Route::get('/gateway-keys', [AdminDashboardController::class, 'gatewayKeys'])->name('gateway-keys');
    Route::get('/playground', [AdminDashboardController::class, 'playground'])->name('playground');
    Route::get('/logs', [AdminDashboardController::class, 'logs'])->name('logs');
    Route::get('/settings', [AdminDashboardController::class, 'settings'])->name('settings');
    Route::put('/settings/site', [AdminDashboardController::class, 'updateSiteSettings'])->name('settings.site.update');
    Route::get('/alerts', [AdminDashboardController::class, 'alerts'])->name('alerts');
    Route::put('/alerts', [AdminDashboardController::class, 'updateAlertSettings'])->name('alerts.update');
    Route::post('/alerts/test', [AdminDashboardController::class, 'testAlertWebhook'])->name('alerts.test');
    // Backward-compatible aliases (old dashboard.settings.alerts.* names)
    Route::put('/settings/alerts', [AdminDashboardController::class, 'updateAlertSettings'])->name('settings.alerts.update');
    Route::post('/settings/alerts/test', [AdminDashboardController::class, 'testAlertWebhook'])->name('settings.alerts.test');
    Route::get('/analytics/charts', [AdminDashboardController::class, 'chartAnalytics'])->name('analytics.charts');

    // Providers & Accounts
    Route::post('/providers', [AdminDashboardController::class, 'storeProvider'])->name('providers.store');
    Route::put('/providers/{provider}', [AdminDashboardController::class, 'updateProvider'])->name('providers.update');
    Route::delete('/providers/{provider}', [AdminDashboardController::class, 'deleteProvider'])->name('providers.delete');
    Route::post('/accounts', [AdminDashboardController::class, 'storeAccount'])->name('accounts.store');
    Route::post('/accounts/{account}/toggle', [AdminDashboardController::class, 'toggleAccount'])->name('accounts.toggle');
    Route::post('/accounts/{account}/clear-cooldown', [AdminDashboardController::class, 'clearCooldown'])->name('accounts.clear-cooldown');
    Route::delete('/accounts/{account}', [AdminDashboardController::class, 'deleteAccount'])->name('accounts.delete');
    Route::post('/accounts/{account}/test', [AdminDashboardController::class, 'testAccount'])->name('accounts.test');

    // Routing Combos
    Route::post('/combos', [AdminDashboardController::class, 'storeCombo'])->name('combos.store');
    Route::post('/combos/{combo}/toggle', [AdminDashboardController::class, 'toggleCombo'])->name('combos.toggle');
    Route::delete('/combos/{combo}', [AdminDashboardController::class, 'deleteCombo'])->name('combos.delete');

    // Gateway Keys
    Route::post('/gateway-keys', [AdminDashboardController::class, 'storeGatewayKey'])->name('gateway-keys.store');
    Route::put('/gateway-keys/{gatewayKey}', [AdminDashboardController::class, 'updateGatewayKey'])->name('gateway-keys.update');
    Route::post('/gateway-keys/{gatewayKey}/toggle', [AdminDashboardController::class, 'toggleGatewayKey'])->name('gateway-keys.toggle');
    Route::delete('/gateway-keys/{gatewayKey}', [AdminDashboardController::class, 'deleteGatewayKey'])->name('gateway-keys.delete');

    // Logs, Cache & Playground
    Route::post('/logs/clear', [AdminDashboardController::class, 'clearLogs'])->name('logs.clear');
    Route::post('/cache/purge', [AdminDashboardController::class, 'purgeCache'])->name('cache.purge');
    Route::post('/playground/chat', [AdminDashboardController::class, 'playgroundChat'])->name('playground.chat');
});

// Alias for route('dashboard')
Route::middleware('auth')->get('/dashboard', [AdminDashboardController::class, 'overview'])->name('dashboard');
