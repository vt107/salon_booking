<?php

use App\Http\Controllers\Site\BookingController;
use App\Http\Controllers\Site\PageController;
use App\Http\Controllers\Site\QrRedirectController;
use Illuminate\Support\Facades\Route;

Route::get('/', [PageController::class, 'home'])->name('home');
Route::get('/bang-gia', [PageController::class, 'prices'])->name('prices');
Route::get('/doi-ngu', [PageController::class, 'team'])->name('team');

Route::get('/dat-lich', [BookingController::class, 'create'])->name('booking.create');
Route::get('/q/{code}', QrRedirectController::class)->name('qr.redirect');

// Link riêng có chữ ký gửi cho khách qua email
Route::middleware('signed')->group(function () {
    Route::get('/lich-hen/{booking:code}', [BookingController::class, 'show'])->name('booking.show');
    Route::post('/lich-hen/{booking:code}/huy', [BookingController::class, 'cancel'])
        ->middleware('throttle:10,1')
        ->name('booking.cancel');
});
