<?php

use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\LogoutController;
use App\Http\Controllers\Auth\PasswordController;
use App\Http\Controllers\HomeController;
use Illuminate\Support\Facades\Route;

Route::get('/', HomeController::class)->name('home');

// Declared public by FND-026 (PublicRoutes); reachable only by guests.
Route::middleware('guest')->group(function () {
    Route::get('/login', [LoginController::class, 'create'])->name('login');
    Route::post('/login', [LoginController::class, 'store'])->name('login.store');
});

Route::post('/logout', LogoutController::class)->name('logout');

Route::get('/password', [PasswordController::class, 'edit'])->name('password.edit');
Route::put('/password', [PasswordController::class, 'update'])->name('password.update');
