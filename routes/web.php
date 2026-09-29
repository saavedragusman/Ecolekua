<?php

use Illuminate\Http\Response;
use Illuminate\Support\Facades\Route;

// Interim: replaced by HomeController / Home.vue in PR 3c. Protected by default (not public).
Route::inertia('/', 'Welcome')->name('home');

// Placeholders declared public by FND-026; the real LoginController lands in PR 3b.
Route::get('/login', fn () => new Response('', 501))->name('login');
Route::post('/login', fn () => new Response('', 501))->name('login.store');
