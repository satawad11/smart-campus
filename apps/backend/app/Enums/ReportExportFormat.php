<?php

namespace App\Enums;

enum ReportExportFormat: string
{
    case XLSX = 'XLSX';
    case CSV = 'CSV';
    case PDF = 'PDF';
}
