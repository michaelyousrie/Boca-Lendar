<?php

use App\Http\Controllers\AppointmentController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\CalendarConnectionController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\GoogleEventsController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/appointments');
Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'create'])->name('login');
    Route::get('/register', [AuthController::class, 'create'])->name('register');
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:login')->name('login.store');
    Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:5,1')->name('register.store');
});

Route::middleware('auth')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
    Route::get('/appointments', DashboardController::class)->name('dashboard');
    Route::post('/appointments', [AppointmentController::class, 'store'])->middleware('throttle:30,1')->name('appointments.store');
    Route::post('/appointments/{appointment}/update', [AppointmentController::class, 'update'])->whereUuid('appointment')->middleware('throttle:30,1')->name('appointments.update');
    Route::post('/appointments/{appointment}/cancel', [AppointmentController::class, 'cancel'])->whereUuid('appointment')->name('appointments.cancel');
    Route::post('/appointments/{appointment}/sync', [AppointmentController::class, 'sync'])->whereUuid('appointment')->middleware('throttle:10,1')->name('appointments.sync');
    Route::post('/appointments/{appointment}/retry', [AppointmentController::class, 'retry'])->whereUuid('appointment')->middleware('throttle:10,1')->name('appointments.retry');
    Route::get('/calendar/google', [CalendarConnectionController::class, 'redirect'])->name('calendar.google');
    Route::get('/calendar/google/callback', [CalendarConnectionController::class, 'callback'])->name('calendar.callback');
    Route::post('/calendar/refresh', [CalendarConnectionController::class, 'refresh'])->middleware('throttle:10,1')->name('calendar.refresh');
    Route::post('/calendar/events/refresh', [GoogleEventsController::class, 'refresh'])->middleware('throttle:20,1')->name('calendar.events.refresh');
});
