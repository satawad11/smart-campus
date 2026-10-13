<?php

namespace Tests\Feature;

use App\Exceptions\ApiException;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Tests\TestCase;

/**
 * V1 audit A-24: expected business errors (ApiException, 409/422) are logged
 * at INFO, while genuine faults keep the ERROR level that alerting relies on.
 */
class ApiExceptionLogLevelTest extends TestCase
{
    public function test_api_exception_is_reported_at_info_not_error(): void
    {
        Log::spy();

        $this->app->make(ExceptionHandler::class)
            ->report(ApiException::conflict('EVENT_ALREADY_EXISTS', 'An event with this event_uuid already exists.'));

        Log::shouldHaveReceived('info')->once();
        Log::shouldNotHaveReceived('error');
    }

    public function test_unexpected_exception_is_still_reported_at_error(): void
    {
        Log::spy();

        $this->app->make(ExceptionHandler::class)->report(new RuntimeException('database exploded'));

        Log::shouldHaveReceived('error')->once();
    }
}
