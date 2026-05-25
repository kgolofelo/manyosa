<?php

namespace App\Http\Controllers;

use App\Models\Song;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class SongController extends Controller
{
    public function index()
    {
        $songs = Song::query()
            ->whereIn('status', ['new', 'reviewed'])
            ->orderBy('sort_order')
            ->get();

        return view('songs.index', [
            'songs'  => $songs,
            'counts' => $this->counts(),
        ]);
    }

    public function list(): JsonResponse
    {
        $songs = Song::query()
            ->whereIn('status', ['new', 'reviewed'])
            ->orderBy('sort_order')
            ->get(['id', 'sort_order', 'title', 'artist', 'genre', 'spotify_url', 'status', 'reviewed_at']);

        return response()->json([
            'songs'  => $songs,
            'counts' => $this->counts(),
        ]);
    }

    public function review(Song $song): JsonResponse
    {
        $closedId = null;

        DB::transaction(function () use ($song, &$closedId) {
            if ($song->status === 'new') {
                $song->update([
                    'status'      => 'reviewed',
                    'reviewed_at' => now(),
                ]);

                // After marking a previously-new song as reviewed, close the
                // oldest *other* song already in 'reviewed' status.
                $oldest = Song::query()
                    ->where('status', 'reviewed')
                    ->where('id', '!=', $song->id)
                    ->orderBy('reviewed_at')
                    ->first();

                if ($oldest) {
                    $oldest->update([
                        'status'    => 'closed',
                        'closed_at' => now(),
                    ]);
                    $closedId = $oldest->id;
                }
            }
        });

        return response()->json([
            'song'      => $song->fresh(),
            'closed_id' => $closedId,
            'counts'    => $this->counts(),
        ]);
    }

    public function close(Song $song): JsonResponse
    {
        if ($song->status !== 'closed') {
            $song->update([
                'status'    => 'closed',
                'closed_at' => now(),
            ]);
        }

        return response()->json([
            'song'   => $song->fresh(),
            'counts' => $this->counts(),
        ]);
    }

    /** @return array{new:int,reviewed:int,closed:int,total:int} */
    private function counts(): array
    {
        $rows = Song::query()
            ->selectRaw('status, COUNT(*) as c')
            ->groupBy('status')
            ->pluck('c', 'status')
            ->all();

        return [
            'new'      => (int) ($rows['new'] ?? 0),
            'reviewed' => (int) ($rows['reviewed'] ?? 0),
            'closed'   => (int) ($rows['closed'] ?? 0),
            'total'    => array_sum($rows),
        ];
    }
}
