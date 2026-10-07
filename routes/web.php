<?php

use App\Http\Controllers\Audit\AuditLogController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\LogoutController;
use App\Http\Controllers\Auth\PasswordController;
use App\Http\Controllers\Customers\CustomerAdvisorController;
use App\Http\Controllers\Customers\CustomerController;
use App\Http\Controllers\Customers\CustomerStatusController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\Roles\RoleController;
use App\Http\Controllers\Roles\RolePermissionController;
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

// Customers (spec 002, CLI-001). Authorization lives in CustomerPolicy.
Route::get('/customers', [CustomerController::class, 'index'])->name('customers.index');
// `/customers/create` must be declared before `/customers/{customer}`, or "create" would be read as an id.
Route::get('/customers/create', [CustomerController::class, 'create'])->name('customers.create');
Route::post('/customers', [CustomerController::class, 'store'])->name('customers.store');
Route::get('/customers/{customer}', [CustomerController::class, 'show'])->name('customers.show');
Route::get('/customers/{customer}/edit', [CustomerController::class, 'edit'])->name('customers.edit');
Route::put('/customers/{customer}', [CustomerController::class, 'update'])->name('customers.update');
Route::delete('/customers/{customer}', [CustomerController::class, 'destroy'])->name('customers.destroy');
Route::post('/customers/{customer}/activate', [CustomerStatusController::class, 'activate'])->name('customers.activate');
Route::post('/customers/{customer}/deactivate', [CustomerStatusController::class, 'deactivate'])->name('customers.deactivate');
Route::put('/customers/{customer}/advisor', [CustomerAdvisorController::class, 'update'])->name('customers.advisor.update');

// Audit query (FND-025). Read-only: audit records are immutable (FND-024), no other route touches them.
Route::get('/audit', [AuditLogController::class, 'index'])->name('audit.index');

Route::get('/roles', [RoleController::class, 'index'])->name('roles.index');
Route::get('/roles/create', [RoleController::class, 'create'])->name('roles.create');
Route::post('/roles', [RoleController::class, 'store'])->name('roles.store');
Route::get('/roles/{role}', [RoleController::class, 'show'])->name('roles.show');
Route::get('/roles/{role}/edit', [RoleController::class, 'edit'])->name('roles.edit');
Route::put('/roles/{role}', [RoleController::class, 'update'])->name('roles.update');
Route::delete('/roles/{role}', [RoleController::class, 'destroy'])->name('roles.destroy');
Route::put('/roles/{role}/permissions', [RolePermissionController::class, 'update'])->name('roles.permissions.update');
