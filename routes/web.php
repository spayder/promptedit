<?php

use App\Http\Controllers\GenerationController;
use App\Http\Controllers\GenerationQuoteController;
use App\Http\Controllers\ProfileController;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    return Inertia::render('Welcome', [
        'canLogin' => Route::has('login'),
        'canRegister' => Route::has('register'),
        'laravelVersion' => Application::VERSION,
        'phpVersion' => PHP_VERSION,
    ]);
});

// Signing in lands here (Breeze redirects to "dashboard"); the product's home is the generate page.
Route::redirect('/dashboard', '/generate')->name('dashboard');

Route::middleware('auth')->group(function () {
    Route::get('/generate', [GenerationController::class, 'create'])->name('generations.create');
    Route::post('/generations', [GenerationController::class, 'store'])->name('generations.store');
    Route::post('/generations/quote', GenerationQuoteController::class)->middleware('throttle:120,1')->name('generations.quote');
    Route::get('/history', [GenerationController::class, 'index'])->name('generations.index');

    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

require __DIR__.'/auth.php';
