<?php

namespace App\Ai\Harness\Observability;

use App\Models\HarnessRun;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

class DegradedRateWatcher
{
    public const THRESHOLD = 0.10;

    public function check(): array
    {
        $since = Carbon::now()->subHour();

        $total = HarnessRun::query()
            ->where('created_at', '>=', $since)
            ->count();

        if ($total === 0) {
            return [
                'total' => 0,
                'degraded' => 0,
                'rate' => 0.0,
                'alerted' => false,
            ];
        }

        $degraded = HarnessRun::query()
            ->where('created_at', '>=', $since)
            ->where('degraded', true)
            ->count();

        $rate = $degraded / $total;

        $alerted = $rate > self::THRESHOLD;

        if ($alerted) {
            Log::warning('harness.degraded_rate_alert', [
                'total' => $total,
                'degraded' => $degraded,
                'rate' => $rate,
                'threshold' => self::THRESHOLD,
                'window' => '1h',
            ]);
        }

        return [
            'total' => $total,
            'degraded' => $degraded,
            'rate' => $rate,
            'alerted' => $alerted,
        ];
    }
}
