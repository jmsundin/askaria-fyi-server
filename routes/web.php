<?php

use App\Http\Controllers\CallController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::match(['get', 'post'], '/incoming-call', [CallController::class, 'handleIncomingCall'])
    ->withoutMiddleware(['web'])
    ->middleware('twilio.signature');
