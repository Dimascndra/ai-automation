<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

Route::post('/ai-clipper/callback', [\App\Http\Controllers\AiClipperController::class, 'handleCallback'])->name('api.ai-clipper.callback');
