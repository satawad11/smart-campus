<?php

namespace App\Exceptions;

use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FeatureDisabledException extends Exception
{
    public function __construct(
        public readonly string $feature,
        string $message = '',
        int $code = 403,
        ?\Throwable $previous = null
    ) {
        $msg = $message !== '' ? $message : "The feature '{$feature}' is disabled on this platform.";
        parent::__construct($msg, $code, $previous);
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'success' => false,
            'error_code' => 'FEATURE_DISABLED',
            'message' => $this->getMessage(),
            'feature' => $this->feature,
        ], 403);
    }
}
