<?php

use App\Http\Controllers\Api\AgentProfileController;
use App\Http\Controllers\Api\AuthController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);

Route::middleware('auth:api')->group(function () {
    Route::get('/me', [AuthController::class, 'me']);
    Route::get('/agent-profile', [AgentProfileController::class, 'show']);
    Route::put('/agent-profile', [AgentProfileController::class, 'update']);
});

Route::prefix('internal')->middleware('internal.api')->group(function () {
    Route::post('/transcripts', function (Request $request) {
        // Example internal endpoint: accept transcript payload
        // Implement persistence/processing as needed
        return response()->json(['status' => 'received']);
    });
});
