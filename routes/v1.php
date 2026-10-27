<?php

use App\Http\Controllers\Api\V1\GatewayController;
use App\Http\Middleware\EnforceGatewayRateLimits;
use App\Http\Middleware\VerifyGatewayKey;
use Illuminate\Support\Facades\Route;

Route::middleware([VerifyGatewayKey::class, EnforceGatewayRateLimits::class])->group(function () {
    Route::options('/models', fn () => response('', 204));
    Route::get('/models', [GatewayController::class, 'models']);

    Route::options('/chat/completions', fn () => response('', 204));
    Route::post('/chat/completions', [GatewayController::class, 'chatCompletions']);
});
