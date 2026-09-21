<?php

namespace Tests\Feature;

use App\Enums\FaceLivenessStatus;
use App\Exceptions\Face\FacePipelineUnavailableException;
use App\Exceptions\Face\InvalidImageException;
use App\Services\Face\FaceLivenessService;
use App\Services\Face\Liveness\AiServiceLivenessProvider;
use App\Services\Face\Liveness\MockLivenessProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class FaceLivenessInterfaceTest extends TestCase
{
    use RefreshDatabase;

    protected function fakeImage(string $filename = 'face.jpg'): UploadedFile
    {
        $dummyContent = "\xFF\xD8\xFF\xE0\x00\x10JFIF\x00\x01\x01\x01\x00`\x00`\x00\x00".str_repeat("\x00", 100);

        return UploadedFile::fake()->createWithContent($filename, $dummyContent);
    }

    public function test_mock_liveness_real_face(): void
    {
        $provider = new MockLivenessProvider(defaultScore: 0.95);
        $service = new FaceLivenessService($provider);

        $image = $this->fakeImage();
        $result = $service->evaluate($image);

        $this->assertTrue($result->isLive);
        $this->assertEquals(FaceLivenessStatus::REAL, $result->status);
        $this->assertEquals(0.95, $result->livenessScore);
        $this->assertEmpty($result->rejectionReasons);
    }

    public function test_mock_liveness_spoof_detection(): void
    {
        $provider = new MockLivenessProvider(defaultScore: 0.25);
        $service = new FaceLivenessService($provider);

        $image = $this->fakeImage();
        $result = $service->evaluate($image);

        $this->assertFalse($result->isLive);
        $this->assertEquals(FaceLivenessStatus::SPOOF, $result->status);
        $this->assertEquals(0.25, $result->livenessScore);
        $this->assertNotEmpty($result->rejectionReasons);
    }

    public function test_mock_liveness_uncertain_band(): void
    {
        $provider = new MockLivenessProvider(defaultScore: 0.55);
        $service = new FaceLivenessService($provider);

        $image = $this->fakeImage();
        $result = $service->evaluate($image);

        $this->assertFalse($result->isLive);
        $this->assertEquals(FaceLivenessStatus::UNCERTAIN, $result->status);
        $this->assertEquals(0.55, $result->livenessScore);
    }

    public function test_ai_service_provider_successful_http_call(): void
    {
        Http::fake([
            '*/internal/v1/face/liveness' => Http::response([
                'success' => true,
                'liveness_score' => 0.88,
                'status' => 'REAL',
                'is_live' => true,
                'method' => 'PASSIVE_TEXTURE',
                'confidence' => 0.95,
                'rejection_reasons' => [],
                'details' => ['resolution' => '640x480'],
            ], 200),
        ]);

        $provider = new AiServiceLivenessProvider('http://127.0.0.1:8001');
        $service = new FaceLivenessService($provider);

        $result = $service->evaluate($this->fakeImage());

        $this->assertTrue($result->isLive);
        $this->assertEquals(FaceLivenessStatus::REAL, $result->status);
        $this->assertEquals(0.88, $result->livenessScore);
        $this->assertEquals('PASSIVE_TEXTURE', $result->method);
    }

    public function test_ai_service_provider_handles_rejection(): void
    {
        Http::fake([
            '*/internal/v1/face/liveness' => Http::response([
                'success' => false,
                'error_code' => 'SPOOF_DETECTED',
                'message' => 'Presentation attack detected (screen replay)',
                'rejection_reasons' => ['PRESENTATION_ATTACK_SUSPECTED'],
            ], 422),
        ]);

        $provider = new AiServiceLivenessProvider('http://127.0.0.1:8001');
        $service = new FaceLivenessService($provider);

        $result = $service->evaluate($this->fakeImage());

        $this->assertFalse($result->isLive);
        $this->assertEquals(FaceLivenessStatus::SPOOF, $result->status);
        $this->assertContains('PRESENTATION_ATTACK_SUSPECTED', $result->rejectionReasons);
    }

    public function test_ai_service_provider_unavailable_throws_exception(): void
    {
        Http::fake([
            '*/internal/v1/face/liveness' => Http::response([], 503),
        ]);

        $provider = new AiServiceLivenessProvider('http://127.0.0.1:8001');
        $service = new FaceLivenessService($provider);

        $this->expectException(FacePipelineUnavailableException::class);
        $service->evaluate($this->fakeImage());
    }

    public function test_disabled_liveness_bypasses_as_real(): void
    {
        Config::set('face.liveness.enabled', false);

        $provider = new MockLivenessProvider(defaultScore: 0.10);
        $service = new FaceLivenessService($provider);

        $result = $service->evaluate($this->fakeImage());

        $this->assertTrue($result->isLive);
        $this->assertEquals(FaceLivenessStatus::REAL, $result->status);
        $this->assertEquals('BYPASS_DISABLED', $result->method);
    }

    public function test_empty_image_payload_rejected(): void
    {
        $service = new FaceLivenessService(new MockLivenessProvider);

        $this->expectException(InvalidImageException::class);
        $service->evaluate('');
    }
}
