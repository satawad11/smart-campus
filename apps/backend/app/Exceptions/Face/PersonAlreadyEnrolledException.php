<?php

namespace App\Exceptions\Face;

class PersonAlreadyEnrolledException extends FaceEnrollmentException
{
    public function __construct(string $message = 'Person already has an active face profile.')
    {
        parent::__construct(
            message: $message,
            errorCode: 'FACE_ALREADY_ENROLLED',
            statusCode: 409
        );
    }
}
