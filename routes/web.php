<?php

use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\LogoutController;
use App\Http\Controllers\Auth\PasswordController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\Users\UserController;
use App\Http\Controllers\Users\UserPasswordController;
use App\Http\Controllers\Users\UserRoleController;
use App\Http\Controllers\Users\UserStatusController;
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

// Users administration (spec 001, FND-010). Authorization lives in UserPolicy.
Route::get('/users', [UserController::class, 'index'])->name('users.index');
Route::get('/users/create', [UserController::class, 'create'])->name('users.create');
Route::post('/users', [UserController::class, 'store'])->name('users.store');
Route::get('/users/{user}', [UserController::class, 'show'])->name('users.show');
Route::get('/users/{user}/edit', [UserController::class, 'edit'])->name('users.edit');
Route::put('/users/{user}', [UserController::class, 'update'])->name('users.update');
Route::post('/users/{user}/activate', [UserStatusController::class, 'activate'])->name('users.activate');
Route::post('/users/{user}/deactivate', [UserStatusController::class, 'deactivate'])->name('users.deactivate');
Route::put('/users/{user}/password', [UserPasswordController::class, 'update'])->name('users.password.reset');
Route::put('/users/{user}/roles', [UserRoleController::class, 'update'])->name('users.roles.update');
