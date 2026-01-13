<?php

use App\Http\Controllers\ProfileController;
use Illuminate\Support\Facades\Route;

/* Route::get('/', function () {
    return view('welcome');
}); */

Route::get('/', function () {
    return redirect()->route('login');
});

Route::get('/dashboard', function () {
    return view('dashboard');
})->middleware(['auth', 'verified'])->name('dashboard');

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    Route::get('/ai-clipper', [\App\Http\Controllers\AiClipperController::class, 'index'])->name('ai-clipper.index');
    Route::post('/ai-clipper/process', [\App\Http\Controllers\AiClipperController::class, 'store'])->name('ai-clipper.store');
});

require __DIR__ . '/auth.php';
require __DIR__ . '/dynamic-menus.php';
