<?php

use App\Http\Controllers\Auth\DeleteAccountController;
use App\Http\Controllers\Auth\ListTokensController;
use App\Http\Controllers\Auth\LoginCodeController;
use App\Http\Controllers\Auth\MeController;
use App\Http\Controllers\Auth\RevokeOneTokenController;
use App\Http\Controllers\Auth\RevokeTokenController;
use App\Http\Controllers\Auth\SessionController;
use App\Http\Controllers\Auth\TokenController;
use App\Http\Controllers\ContextController;
use App\Http\Controllers\Dashboard\DeleteMemoryController;
use App\Http\Controllers\Dashboard\ListMemoriesController;
use App\Http\Controllers\Dashboard\ListProjectsController;
use App\Http\Controllers\Dashboard\MemoryStatsController;
use App\Http\Controllers\Dashboard\SearchMemoriesController;
use App\Http\Controllers\Dashboard\ShowMemoryController;
use Illuminate\Support\Facades\Route;

Route::post('/auth/code', LoginCodeController::class)->middleware('throttle:auth-code');
Route::post('/auth/token', TokenController::class)->middleware('throttle:auth-token');
Route::post('/session', [SessionController::class, 'store'])->middleware('throttle:auth-token');

// Every token-authenticated route lives in this group, so a new route inherits the full-access
// ability check: a token limited to `usage:write` (the OTLP exporter's) can reach nothing else.
// Sessions pass (TransientToken can do anything); only the OTLP ingest route may sit outside it.
Route::middleware(['auth:sanctum', 'abilities:*'])->group(function () {
    Route::get('/context', ContextController::class)->middleware('throttle:mcp');

    Route::delete('/auth/token', RevokeTokenController::class)->middleware('throttle:auth-tokens');
    Route::get('/auth/tokens', ListTokensController::class)->middleware('throttle:auth-tokens');
    Route::delete('/auth/tokens/{id}', RevokeOneTokenController::class)->whereNumber('id')->middleware('throttle:auth-tokens');

    Route::delete('/session', [SessionController::class, 'destroy'])->middleware('throttle:auth-tokens');
    Route::get('/me', MeController::class)->middleware('throttle:mcp');

    Route::delete('/account', DeleteAccountController::class)->middleware('throttle:mcp');

    Route::get('/projects', ListProjectsController::class)->middleware('throttle:dashboard');
    Route::get('/stats', MemoryStatsController::class)->middleware('throttle:dashboard');
    Route::get('/memories', ListMemoriesController::class)->middleware('throttle:dashboard');
    Route::get('/memories/search', SearchMemoriesController::class)->middleware('throttle:dashboard');
    Route::get('/memories/{id}', ShowMemoryController::class)->whereNumber('id')->middleware('throttle:dashboard');
    Route::delete('/memories/{id}', DeleteMemoryController::class)->whereNumber('id')->middleware('throttle:dashboard');
});
