<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use Illuminate\Http\Request;

Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);

Route::middleware('auth:api')->group(function () {
    Route::get('/me', [AuthController::class, 'me']);
});

Route::prefix('internal')->middleware('internal.api')->group(function () {
    Route::post('/transcripts', function (Request $request) {
        // Example internal endpoint: accept transcript payload
        // Implement persistence/processing as needed
        return response()->json(['status' => 'received']);
    });
});


