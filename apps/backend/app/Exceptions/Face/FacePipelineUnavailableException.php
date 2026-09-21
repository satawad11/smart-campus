<?php

namespace App\Exceptions\Face;

class FacePipelineUnavailableException extends FaceEnrollmentException
{
    public function __construct(string $message = 'AI perception service is unavailable or timed out.')
    {
        parent::__construct(
            message: $message,
            errorCode: 'AI_SERVICE_UNAVAILABLE',
            statusCode: 503
        );
    }
}
