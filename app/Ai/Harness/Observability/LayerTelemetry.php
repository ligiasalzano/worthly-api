<?php

namespace App\Ai\Harness\Observability;

use Illuminate\Support\Facades\Log;
use Throwable;

class LayerTelemetry
{
    /**
     * @var array<string, array<string, mixed>>
     */
    protected array $layers = [];

    public function reset(): void
    {
        $this->layers = [];
    }

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @param  array<string, mixed>  $context
     * @return T
     */
    public function record(string $layer, array $context, callable $callback): mixed
    {
        $startNs = hrtime(true);
        $success = true;
        $error = null;

        try {
            $result = $callback();
        } catch (Throwable $e) {
            $success = false;
            $error = $e->getMessage();

            $this->log($layer, $context, $startNs, $success, $error);

            throw $e;
        }

        $this->log($layer, $context, $startNs, $success, null);

        return $result;
    }

    public function markSkipped(string $layer, array $context = []): void
    {
        $entry = $this->buildEntry($layer, $context, 0, true);
        $entry['skipped'] = true;

        $this->layers[$layer] = $entry;

        Log::info('harness.layer', $this->logPayload($layer, $entry));
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function layers(): array
    {
        return $this->layers;
    }

    public function updateCounts(string $layer, int $itemsIn, int $itemsOut): void
    {
        if (! isset($this->layers[$layer])) {
            return;
        }

        $this->layers[$layer]['items_in'] = $itemsIn;
        $this->layers[$layer]['items_out'] = $itemsOut;
    }

    public function markCacheHit(string $layer): void
    {
        if (! isset($this->layers[$layer])) {
            return;
        }

        $this->layers[$layer]['cache_hit'] = true;
    }

    public function anyCacheHit(): bool
    {
        foreach ($this->layers as $entry) {
            if (($entry['cache_hit'] ?? false) === true) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $context
     */
    protected function log(string $layer, array $context, int $startNs, bool $success, ?string $error): void
    {
        $durationMs = (int) round((hrtime(true) - $startNs) / 1_000_000);
        $entry = $this->buildEntry($layer, $context, $durationMs, $success);

        if ($error !== null) {
            $entry['error'] = $error;
        }

        $this->layers[$layer] = $entry;

        Log::info('harness.layer', $this->logPayload($layer, $entry));
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    protected function buildEntry(string $layer, array $context, int $durationMs, bool $success): array
    {
        return [
            'duration_ms' => $durationMs,
            'cache_hit' => (bool) ($context['cache_hit'] ?? false),
            'items_in' => (int) ($context['items_in'] ?? 0),
            'items_out' => (int) ($context['items_out'] ?? 0),
            'tokens_in' => (int) ($context['tokens_in'] ?? 0),
            'tokens_out' => (int) ($context['tokens_out'] ?? 0),
            'cost_usd_estimate' => (float) ($context['cost_usd_estimate'] ?? 0.0),
            'success' => $success,
        ];
    }

    /**
     * @param  array<string, mixed>  $entry
     * @return array<string, mixed>
     */
    protected function logPayload(string $layer, array $entry): array
    {
        return array_merge(['layer' => $layer], $entry);
    }
}
