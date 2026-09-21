<?php

namespace App\Enums;

/**
 * Face sample intake source (Module 03).
 */
enum FaceSampleSource: string
{
    case UPLOAD = 'UPLOAD';
    case CAMERA = 'CAMERA';
    case CAPTURE = 'CAPTURE';
}
