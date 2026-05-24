<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DiscoveryRun extends Model
{
    protected $fillable = [
        'source',
        'status',
        'started_at',
        'finished_at',
        'new_count',
        'message',
    ];

    protected $casts = [
        'started_at'  => 'datetime',
        'finished_at' => 'datetime',
        'new_count'   => 'integer',
    ];
}
