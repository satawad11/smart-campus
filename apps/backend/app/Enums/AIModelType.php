<?php

namespace App\Enums;

/**
 * AI Model Type / Purpose (database blueprint §5, Module 03).
 *
 * Defines the functional role of an AI model in the recognition and vision pipeline.
 */
enum AIModelType: string
{
    case FACE_DETECTION = 'FACE_DETECTION';
    case FACE_ALIGNMENT = 'FACE_ALIGNMENT';
    case FACE_QUALITY = 'FACE_QUALITY';
    case FACE_RECOGNITION = 'FACE_RECOGNITION';
    case FACE_EMBEDDING = 'FACE_EMBEDDING';
    case LIVENESS = 'LIVENESS';
    case OTHER = 'OTHER';
}
