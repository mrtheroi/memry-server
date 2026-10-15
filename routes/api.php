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

Route::get('/context', ContextController::class)->middleware(['auth:sanctum', 'throttle:mcp']);

Route::post('/auth/code', LoginCodeController::class)->middleware('throttle:auth-code');
Route::post('/auth/token', TokenController::class)->middleware('throttle:auth-token');
Route::delete('/auth/token', RevokeTokenController::class)->middleware(['auth:sanctum', 'throttle:auth-tokens']);
Route::get('/auth/tokens', ListTokensController::class)->middleware(['auth:sanctum', 'throttle:auth-tokens']);
Route::delete('/auth/tokens/{id}', RevokeOneTokenController::class)->whereNumber('id')->middleware(['auth:sanctum', 'throttle:auth-tokens']);

Route::post('/session', [SessionController::class, 'store'])->middleware('throttle:auth-token');
Route::delete('/session', [SessionController::class, 'destroy'])->middleware(['auth:sanctum', 'throttle:auth-tokens']);
Route::get('/me', MeController::class)->middleware(['auth:sanctum', 'throttle:mcp']);

Route::delete('/account', DeleteAccountController::class)->middleware(['auth:sanctum', 'throttle:mcp']);

Route::get('/projects', ListProjectsController::class)->middleware(['auth:sanctum', 'throttle:dashboard']);
Route::get('/stats', MemoryStatsController::class)->middleware(['auth:sanctum', 'throttle:dashboard']);
Route::get('/memories', ListMemoriesController::class)->middleware(['auth:sanctum', 'throttle:dashboard']);
Route::get('/memories/search', SearchMemoriesController::class)->middleware(['auth:sanctum', 'throttle:dashboard']);
Route::get('/memories/{id}', ShowMemoryController::class)->whereNumber('id')->middleware(['auth:sanctum', 'throttle:dashboard']);
Route::delete('/memories/{id}', DeleteMemoryController::class)->whereNumber('id')->middleware(['auth:sanctum', 'throttle:dashboard']);
