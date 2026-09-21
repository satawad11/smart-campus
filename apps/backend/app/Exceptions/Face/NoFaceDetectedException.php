<?php

namespace App\Exceptions\Face;

class NoFaceDetectedException extends FaceEnrollmentException
{
    public function __construct(string $message = 'No face detected in enrollment image.')
    {
        parent::__construct(
            message: $message,
            errorCode: 'NO_FACE',
            statusCode: 422,
            details: ['rejection_reasons' => ['NO_FACE']]
        );
    }
}
