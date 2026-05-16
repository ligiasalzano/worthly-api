<?php

use App\Http\Controllers\Api\Auth\LoginController;
use App\Http\Controllers\Api\Auth\LogoutController;
use App\Http\Controllers\Api\Auth\RegisterController;
use App\Http\Controllers\Api\MeController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::post('/register', RegisterController::class);
Route::post('/login', LoginController::class);

Route::middleware('auth:sanctum')->group(function (): void {
    Route::post('/logout', LogoutController::class);

    Route::get('/me', MeController::class);

    Route::get('/analyses', function () {
        return response()->json(['data' => []]);
    });
    Route::post('/analyses', function (Request $request) {
        return response()->json([
            'user_id' => auth()->id(),
        ], 201);
    });
    Route::get('/analyses/{id}', function () {
        return response()->json(['data' => null]);
    });
    Route::delete('/analyses/{id}', function () {
        return response()->noContent();
    });
});
