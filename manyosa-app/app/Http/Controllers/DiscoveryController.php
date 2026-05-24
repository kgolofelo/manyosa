<?php

namespace App\Http\Controllers;

use App\Models\DiscoveryRun;
use Illuminate\Http\JsonResponse;

class DiscoveryController extends Controller
{
    private const SCRIPT = '/home/kgolofelo/manyosa/manyosa-app/scripts/run.sh';

    public function status(): JsonResponse
    {
        return response()->json($this->snapshot());
    }

    public function trigger(): JsonResponse
    {
        // If something is already running (per DB), refuse — the wrapper would
        // skip anyway via flock, but reporting it back to the UI is friendlier.
        $running = DiscoveryRun::where('status', 'running')->latest('id')->first();
        if ($running) {
            return response()->json([
                'queued' => false,
                'reason' => 'already_running',
                'run'    => $running,
            ] + $this->snapshot(), 409);
        }

        // Fire-and-forget. Wrapper logs to storage/discovery/cron.log and
        // records its own row in discovery_runs.
        $cmd = sprintf(
            'nohup %s manual >/dev/null 2>&1 &',
            escapeshellarg(self::SCRIPT)
        );
        @exec($cmd);

        return response()->json(['queued' => true] + $this->snapshot());
    }

    /**
     * @return array{latest:?\App\Models\DiscoveryRun,running:bool,today_success:int,daily_target:int}
     */
    private function snapshot(): array
    {
        $latest = DiscoveryRun::latest('id')->first();
        $today  = DiscoveryRun::where('status', 'success')
            ->whereDate('started_at', now()->toDateString())
            ->count();

        return [
            'latest'        => $latest,
            'running'       => $latest && $latest->status === 'running',
            'today_success' => $today,
            'daily_target'  => 2,
        ];
    }
}
