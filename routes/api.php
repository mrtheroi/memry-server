<?php

use App\Http\Controllers\Auth\DeleteAccountController;
use App\Http\Controllers\Auth\ListTokensController;
use App\Http\Controllers\Auth\LoginCodeController;
use App\Http\Controllers\Auth\RevokeOneTokenController;
use App\Http\Controllers\Auth\RevokeTokenController;
use App\Http\Controllers\Auth\TokenController;
use App\Http\Controllers\ContextController;
use Illuminate\Support\Facades\Route;

Route::get('/context', ContextController::class)->middleware(['auth:sanctum', 'throttle:mcp']);

Route::post('/auth/code', LoginCodeController::class)->middleware('throttle:auth-code');
Route::post('/auth/token', TokenController::class)->middleware('throttle:auth-token');
Route::delete('/auth/token', RevokeTokenController::class)->middleware(['auth:sanctum', 'throttle:auth-tokens']);
Route::get('/auth/tokens', ListTokensController::class)->middleware(['auth:sanctum', 'throttle:auth-tokens']);
Route::delete('/auth/tokens/{id}', RevokeOneTokenController::class)->whereNumber('id')->middleware(['auth:sanctum', 'throttle:auth-tokens']);

Route::delete('/account', DeleteAccountController::class)->middleware(['auth:sanctum', 'throttle:mcp']);
