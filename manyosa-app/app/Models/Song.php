<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Song extends Model
{
    protected $fillable = [
        'sort_order',
        'title',
        'artist',
        'genre',
        'spotify_url',
        'spotify_track_id',
        'status',
        'reviewed_at',
        'closed_at',
    ];

    protected $casts = [
        'reviewed_at' => 'datetime',
        'closed_at' => 'datetime',
    ];
}
