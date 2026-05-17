<?php

namespace App\Ai\Harness\Evals;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

class EvalReport
{
    /** @var list<array<string, mixed>> */
    protected array $rows = [];

    public function addRow(array $row): void
    {
        $this->rows[] = $row;
    }

    public function aggregate(): array
    {
        $total = count($this->rows);

        if ($total === 0) {
            return [
                'total' => 0,
                'decision_match_rate' => 0.0,
                'high_authority_rate' => 0.0,
            ];
        }

        $decisionMatches = 0;
        $highAuthority = 0;

        foreach ($this->rows as $row) {
            if (! empty($row['decision_match'])) {
                $decisionMatches++;
            }

            if (! empty($row['cited_high_authority'])) {
                $highAuthority++;
            }
        }

        return [
            'total' => $total,
            'decision_match_rate' => round($decisionMatches / $total, 4),
            'high_authority_rate' => round($highAuthority / $total, 4),
        ];
    }

    public function write(?string $filename = null): string
    {
        $filename ??= 'evals/'.Carbon::now()->format('Y-m-d_His').'.json';

        $payload = [
            'generated_at' => Carbon::now()->toIso8601String(),
            'rows' => $this->rows,
            'aggregate' => $this->aggregate(),
        ];

        Storage::disk('local')->put($filename, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return $filename;
    }
}
