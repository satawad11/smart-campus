<?php

namespace App\Http\Middleware;

use App\Services\System\FeatureFlagService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware enforcing authoritative backend feature flags (SEC-002).
 */
class EnsureFeatureEnabled
{
    public function __construct(
        private readonly FeatureFlagService $featureFlagService,
    ) {}

    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next, string $feature): Response
    {
        $this->featureFlagService->assertEnabled($feature);

        return $next($request);
    }
}
