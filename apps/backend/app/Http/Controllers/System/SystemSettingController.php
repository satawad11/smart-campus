<?php

namespace App\Http\Controllers\System;

use App\Http\Controllers\Controller;
use App\Services\Audit\AuditRequestContext;
use App\Services\System\SystemSettingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Controller for Central System Settings (SEC-001).
 */
class SystemSettingController extends Controller
{
    public function __construct(
        private readonly SystemSettingService $settingService,
    ) {}

    /**
     * Get all settings grouped by group name.
     */
    public function index(): JsonResponse
    {
        $grouped = $this->settingService->getAllGrouped();

        return response()->json([
            'success' => true,
            'message' => 'Success',
            'data' => $grouped,
            'meta' => null,
        ]);
    }

    /**
     * Get settings for a specific group.
     */
    public function show(string $group): JsonResponse
    {
        $settings = $this->settingService->getByGroup($group);

        return response()->json([
            'success' => true,
            'message' => 'Success',
            'data' => $settings,
            'meta' => null,
        ]);
    }

    /**
     * Update settings within a specific group.
     */
    public function update(Request $request, string $group): JsonResponse
    {
        $validated = $request->validate([
            'settings' => ['required', 'array'],
        ]);

        $context = AuditRequestContext::fromRequest($request);
        $updated = $this->settingService->updateGroup($group, $validated['settings'], $request->user(), $context);

        return response()->json([
            'success' => true,
            'message' => "Settings for group '{$group}' updated successfully",
            'data' => $updated,
            'meta' => null,
        ]);
    }

    /**
     * Get publicly accessible settings without authentication.
     */
    public function publicSettings(): JsonResponse
    {
        $settings = $this->settingService->getPublicSettings();

        return response()->json([
            'success' => true,
            'message' => 'Success',
            'data' => $settings,
            'meta' => null,
        ]);
    }
}
