<?php

namespace App\Http\Controllers\System;

use App\Http\Controllers\Controller;
use App\Services\System\FeatureFlagService;
use Illuminate\Http\JsonResponse;

/**
 * Controller for Feature Flags (SEC-002).
 */
class FeatureFlagController extends Controller
{
    public function __construct(
        private readonly FeatureFlagService $featureFlagService,
    ) {}

    /**
     * Get all active feature flags.
     */
    public function index(): JsonResponse
    {
        $flags = $this->featureFlagService->getAll();

        return response()->json([
            'success' => true,
            'message' => 'Success',
            'data' => $flags,
            'meta' => null,
        ]);
    }
}
