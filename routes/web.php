<?php

use App\Http\Controllers\CallController;
use Illuminate\Support\Facades\Route;

Route::get('/', [CallController::class, 'index']);

Route::match(['get', 'post'], '/incoming-call', [CallController::class, 'handleIncomingCall'])
    ->withoutMiddleware(['web'])
    ->middleware('twilio.signature');
