<?php

use App\Http\Controllers\Api\AnalysisController;
use App\Http\Controllers\Api\Auth\LoginController;
use App\Http\Controllers\Api\Auth\LogoutController;
use App\Http\Controllers\Api\Auth\RegisterController;
use App\Http\Controllers\Api\MeController;
use Illuminate\Support\Facades\Route;

Route::post('/register', RegisterController::class);
Route::post('/login', LoginController::class);

Route::middleware('auth:sanctum')->group(function (): void {
    Route::post('/logout', LogoutController::class);

    Route::get('/me', MeController::class);

    Route::get('/analyses', [AnalysisController::class, 'index'])->name('analyses.index');
    Route::post('/analyses', [AnalysisController::class, 'store'])->name('analyses.store');
    Route::get('/analyses/{analysis}', [AnalysisController::class, 'show'])->name('analyses.show');
    Route::delete('/analyses/{analysis}', [AnalysisController::class, 'destroy'])->name('analyses.destroy');
    Route::get('/analyses/{analysis}/image', [AnalysisController::class, 'image'])->name('analyses.image');
});
