<?php

use App\Http\Controllers\DiscoveryController;
use App\Http\Controllers\SongController;
use Illuminate\Support\Facades\Route;

Route::get('/', [SongController::class, 'index'])->name('songs.index');
Route::get('/songs', [SongController::class, 'list'])->name('songs.list');
Route::post('/songs/{song}/review', [SongController::class, 'review'])->name('songs.review');

Route::get('/discovery/status', [DiscoveryController::class, 'status'])->name('discovery.status');
Route::post('/discovery/run', [DiscoveryController::class, 'trigger'])->name('discovery.run');
