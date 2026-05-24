<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('songs', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('sort_order');
            $table->string('title');
            $table->string('artist')->nullable();
            $table->string('genre')->nullable();
            $table->string('spotify_url');
            $table->string('spotify_track_id')->unique();
            $table->string('status')->default('new'); // new | reviewed | closed
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();

            $table->index('status');
            $table->index('sort_order');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('songs');
    }
};
