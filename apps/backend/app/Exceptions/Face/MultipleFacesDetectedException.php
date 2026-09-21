<?php

namespace App\Exceptions\Face;

class MultipleFacesDetectedException extends FaceEnrollmentException
{
    public function __construct(int $faceCount = 2, string $message = 'Multiple faces detected. Exactly one face is required for enrollment.')
    {
        parent::__construct(
            message: $message,
            errorCode: 'MULTIPLE_FACES',
            statusCode: 422,
            details: ['face_count' => $faceCount, 'rejection_reasons' => ['MULTIPLE_FACES']]
        );
    }
}
