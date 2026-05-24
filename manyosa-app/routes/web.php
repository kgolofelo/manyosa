<?php

use App\Http\Controllers\SongController;
use Illuminate\Support\Facades\Route;

Route::get('/', [SongController::class, 'index'])->name('songs.index');
Route::get('/songs', [SongController::class, 'list'])->name('songs.list');
Route::post('/songs/{song}/review', [SongController::class, 'review'])->name('songs.review');
