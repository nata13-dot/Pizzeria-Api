<?php

namespace App\Http\Middleware;

use App\Services\BranchSettings;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class MeasureApiPerformance
{
    public function __construct(private readonly BranchSettings $settings) {}

    private const TRACKED = [
        'api/products',
        'api/pos/catalog',
        'api/kitchen/orders',
        'api/orders',
        'api/orders/*/send-to-kitchen',
        'api/reports/products',
        'api/reports/profit',
        'api/reports/times',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        // Scoped services can outlive a request under long-running test and worker processes.
        $this->settings->flush();
        if (! config('performance.enabled') || ! $request->is(self::TRACKED)) {
            return $next($request);
        }

        DB::flushQueryLog();
        DB::enableQueryLog();
        $startedAt = hrtime(true);
        $response = $next($request);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        $content = $response->getContent();
        $metrics = [
            'method' => $request->method(),
            'path' => $request->path(),
            'duration_ms' => round((hrtime(true) - $startedAt) / 1_000_000, 2),
            'query_count' => count($queries),
            'query_time_ms' => round(array_sum(array_column($queries, 'time')), 2),
            'peak_memory_bytes' => memory_get_peak_usage(true),
            'response_bytes' => is_string($content) ? strlen($content) : null,
        ];
        if (is_string($content) && in_array($request->path(), ['api/products', 'api/pos/catalog'], true)) {
            $decoded = json_decode($content, true);
            $imageBytes = $this->imagePayloadBytes($decoded);
            $metrics['image_data_uri_bytes'] = $imageBytes;
            $metrics['image_data_uri_percent'] = strlen($content) > 0
                ? round($imageBytes * 100 / strlen($content), 2)
                : 0.0;
        }

        if (config('performance.log')) {
            Log::info('api.performance', $metrics);
        }
        $response->headers->set('Server-Timing', sprintf('app;dur=%.2f, db;dur=%.2f', $metrics['duration_ms'], $metrics['query_time_ms']));
        $response->headers->set('X-Performance-Metrics', base64_encode((string) json_encode($metrics)));

        return $response;
    }

    private function imagePayloadBytes(mixed $value): int
    {
        if (! is_array($value)) {
            return 0;
        }

        $bytes = 0;
        foreach ($value as $key => $item) {
            if ($key === 'image_data_uri' && is_string($item)) {
                $bytes += strlen($item);
            } else {
                $bytes += $this->imagePayloadBytes($item);
            }
        }

        return $bytes;
    }
}
