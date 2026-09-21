<?php

namespace App\Exceptions\Face;

class InvalidImageException extends FaceEnrollmentException
{
    public function __construct(string $message = 'Uploaded image file is invalid or malformed.')
    {
        parent::__construct(
            message: $message,
            errorCode: 'INVALID_IMAGE',
            statusCode: 422
        );
    }
}
