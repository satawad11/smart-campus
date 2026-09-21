<?php

namespace App\Exceptions\Face;

class FaceQualityRejectedException extends FaceEnrollmentException
{
    /**
     * @param  list<string>  $rejectionReasons
     * @param  array<string, mixed>  $qualitySummary
     */
    public function __construct(
        array $rejectionReasons = [],
        array $qualitySummary = [],
        string $message = 'Face quality criteria not met for biometric enrollment.'
    ) {
        parent::__construct(
            message: $message,
            errorCode: 'FACE_QUALITY_REJECTED',
            statusCode: 422,
            details: [
                'rejection_reasons' => $rejectionReasons,
                'quality' => $qualitySummary,
            ]
        );
    }
}
