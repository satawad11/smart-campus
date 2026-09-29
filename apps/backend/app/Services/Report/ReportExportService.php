<?php

namespace App\Services\Report;

use App\Enums\ReportExportFormat;
use App\Models\AccessEvent;
use App\Models\ReportExport;
use App\Models\Site;
use Illuminate\Support\Carbon;
use ZipArchive;

class ReportExportService
{
    public function __construct(
        private readonly ReportBuilderService $reportBuilder,
    ) {}

    /**
     * @return array{headers: list<string>, rows: list<list<string|int|float>>}
     */
    public function extractExportData(ReportExport $export): array
    {
        $filters = $export->filters ?? [];
        $siteId = ! empty($filters['site_uuid']) ? Site::where('uuid', $filters['site_uuid'])->value('id') : null;

        return match (strtolower($export->report_type)) {
            'daily' => $this->extractDailyData($filters, $siteId),
            'weekly' => $this->extractWeeklyData($filters, $siteId),
            'monthly' => $this->extractMonthlyData($filters, $siteId),
            'yearly' => $this->extractYearlyData($filters, $siteId),
            'custom' => $this->extractCustomData($filters, $siteId),
            'review' => $this->extractReviewData($filters, $siteId),
            'unknown' => $this->extractUnknownData($filters, $siteId),
            'camera' => $this->extractCameraData($filters, $siteId),
            'ai_node', 'ai-node' => $this->extractAiNodeData($siteId),
            'history', 'history_search' => $this->extractHistoryData($filters, $siteId),
            default => $this->extractDefaultData($export),
        };
    }

    /**
     * @param  array{headers: list<string>, rows: list<list<string|int|float>>}  $data
     */
    public function render(array $data, ReportExportFormat $format, string $title): string
    {
        return match ($format) {
            ReportExportFormat::CSV => $this->renderCsv($data),
            ReportExportFormat::XLSX => $this->renderXlsx($data, $title),
            ReportExportFormat::PDF => $this->renderPdf($data, $title),
        };
    }

    /**
     * @param  array{headers: list<string>, rows: list<list<string|int|float>>}  $data
     */
    public function renderCsv(array $data): string
    {
        $handle = fopen('php://temp', 'r+');
        // UTF-8 BOM for Excel compatibility
        fwrite($handle, "\xEF\xBB\xBF");

        fputcsv($handle, $data['headers']);
        foreach ($data['rows'] as $row) {
            fputcsv($handle, array_map(fn ($v) => (string) $v, $row));
        }

        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        return $csv !== false ? $csv : '';
    }

    /**
     * @param  array{headers: list<string>, rows: list<list<string|int|float>>}  $data
     */
    public function renderXlsx(array $data, string $title): string
    {
        $tempFile = tempnam(sys_get_temp_dir(), 'xlsx_');
        $zip = new ZipArchive;
        $zip->open($tempFile, ZipArchive::CREATE | ZipArchive::OVERWRITE);

        // [Content_Types].xml
        $contentTypes = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            .'<Default Extension="xml" ContentType="application/xml"/>'
            .'<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            .'<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            .'<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            .'</Types>';
        $zip->addFromString('[Content_Types].xml', $contentTypes);

        // _rels/.rels
        $rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            .'</Relationships>';
        $zip->addFromString('_rels/.rels', $rels);

        // xl/_rels/workbook.xml.rels
        $wbRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            .'<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            .'</Relationships>';
        $zip->addFromString('xl/_rels/workbook.xml.rels', $wbRels);

        // xl/workbook.xml
        $cleanSheetName = substr(preg_replace('/[^A-Za-z0-9_]/', '_', $title) ?: 'Report', 0, 31);
        $workbook = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            .'<sheets>'
            .'<sheet name="'.$cleanSheetName.'" sheetId="1" r:id="rId1"/>'
            .'</sheets>'
            .'</workbook>';
        $zip->addFromString('xl/workbook.xml', $workbook);

        // xl/styles.xml
        $styles = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            .'<fonts count="1"><font><sz val="11"/><name val="Calibri"/></font></fonts>'
            .'<fills count="1"><fill><patternFill patternType="none"/></fill></fills>'
            .'<borders count="1"><border><left/><right/><top/><bottom/></border></borders>'
            .'<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            .'<cellXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/></cellXfs>'
            .'</styleSheet>';
        $zip->addFromString('xl/styles.xml', $styles);

        // xl/worksheets/sheet1.xml
        $sheetXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            .'<sheetData>';

        $rowIndex = 1;
        // Header row
        $sheetXml .= '<row r="'.$rowIndex.'">';
        foreach ($data['headers'] as $colIndex => $header) {
            $colLetter = $this->columnLetter($colIndex);
            $safeText = htmlspecialchars((string) $header, ENT_XML1, 'UTF-8');
            $sheetXml .= '<c r="'.$colLetter.$rowIndex.'" t="inlineStr"><is><t>'.$safeText.'</t></is></c>';
        }
        $sheetXml .= '</row>';

        // Data rows
        foreach ($data['rows'] as $row) {
            $rowIndex++;
            $sheetXml .= '<row r="'.$rowIndex.'">';
            foreach ($row as $colIndex => $value) {
                $colLetter = $this->columnLetter($colIndex);
                if (is_numeric($value)) {
                    $sheetXml .= '<c r="'.$colLetter.$rowIndex.'"><v>'.$value.'</v></c>';
                } else {
                    $safeText = htmlspecialchars((string) $value, ENT_XML1, 'UTF-8');
                    $sheetXml .= '<c r="'.$colLetter.$rowIndex.'" t="inlineStr"><is><t>'.$safeText.'</t></is></c>';
                }
            }
            $sheetXml .= '</row>';
        }

        $sheetXml .= '</sheetData></worksheet>';
        $zip->addFromString('xl/worksheets/sheet1.xml', $sheetXml);
        $zip->close();

        $content = file_get_contents($tempFile);
        @unlink($tempFile);

        return $content !== false ? $content : '';
    }

    /**
     * @param  array{headers: list<string>, rows: list<list<string|int|float>>}  $data
     */
    public function renderPdf(array $data, string $title): string
    {
        // Minimal standard PDF 1.4 generator
        $lines = [];
        $lines[] = '=== '.strtoupper($title).' ===';
        $lines[] = 'Generated: '.now()->toDateTimeString();
        $lines[] = str_repeat('-', 75);
        $lines[] = implode(' | ', $data['headers']);
        $lines[] = str_repeat('-', 75);

        foreach (array_slice($data['rows'], 0, 50) as $row) {
            $line = implode(' | ', array_map(fn ($v) => (string) $v, $row));
            if (strlen($line) > 100) {
                $line = substr($line, 0, 97).'...';
            }
            $lines[] = $line;
        }

        if (count($data['rows']) > 50) {
            $lines[] = '... and '.(count($data['rows']) - 50).' more rows.';
        }

        // Build text stream
        $textStream = "BT\n/F1 10 Tf\n40 750 Td\n14 TL\n";
        foreach ($lines as $line) {
            $safeLine = str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $line);
            $textStream .= "({$safeLine}) '\n";
        }
        $textStream .= "ET\n";

        $streamLen = strlen($textStream);

        $objects = [];
        $objects[1] = '<< /Type /Catalog /Pages 2 0 R >>';
        $objects[2] = '<< /Type /Pages /Kids [3 0 R] /Count 1 >>';
        $objects[3] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources 4 0 R /Contents 5 0 R >>';
        $objects[4] = '<< /Font << /F1 << /Type /Font /Subtype /Type1 /BaseFont /Courier >> >> >>';
        $objects[5] = "<< /Length {$streamLen} >>\nstream\n{$textStream}endstream";

        $pdf = "%PDF-1.4\n";
        $offsets = [];
        foreach ($objects as $num => $obj) {
            $offsets[$num] = strlen($pdf);
            $pdf .= "{$num} 0 obj\n{$obj}\nendobj\n";
        }

        $xrefOffset = strlen($pdf);
        $pdf .= "xref\n0 ".(count($objects) + 1)."\n";
        $pdf .= "0000000000 65535 f \n";
        for ($i = 1; $i <= count($objects); $i++) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$i]);
        }
        $pdf .= "trailer\n<< /Size ".(count($objects) + 1)." /Root 1 0 R >>\n";
        $pdf .= "startxref\n{$xrefOffset}\n%%EOF\n";

        return $pdf;
    }

    private function columnLetter(int $index): string
    {
        $letters = '';
        $index++;
        while ($index > 0) {
            $remainder = ($index - 1) % 26;
            $letters = chr(65 + $remainder).$letters;
            $index = intdiv($index - $remainder, 26);
        }

        return $letters;
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{headers: list<string>, rows: list<list<string|int|float>>}
     */
    private function extractDailyData(array $filters, ?int $siteId): array
    {
        $date = Carbon::parse($filters['date'] ?? now()->toDateString());
        $byHour = $this->reportBuilder->buildHourlyBreakdown($date, $siteId);

        $headers = ['Hour', 'Event Count'];
        $rows = array_map(fn ($item) => [$item['hour'], $item['count']], $byHour);

        return ['headers' => $headers, 'rows' => $rows];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{headers: list<string>, rows: list<list<string|int|float>>}
     */
    private function extractWeeklyData(array $filters, ?int $siteId): array
    {
        $date = Carbon::parse($filters['date'] ?? now()->toDateString());
        $from = $date->copy()->startOfWeek();
        $to = $date->copy()->endOfWeek();
        $byDay = $this->reportBuilder->buildDailyBreakdown($from, $to, $siteId);

        $headers = ['Date', 'Day', 'Event Count'];
        $rows = array_map(fn ($item) => [$item['date'], $item['day_name'], $item['count']], $byDay);

        return ['headers' => $headers, 'rows' => $rows];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{headers: list<string>, rows: list<list<string|int|float>>}
     */
    private function extractMonthlyData(array $filters, ?int $siteId): array
    {
        $date = Carbon::parse($filters['date'] ?? ($filters['month'] ?? now()->toDateString()));
        $from = $date->copy()->startOfMonth();
        $to = $date->copy()->endOfMonth();
        $byDay = $this->reportBuilder->buildDailyBreakdown($from, $to, $siteId);

        $headers = ['Date', 'Day', 'Event Count'];
        $rows = array_map(fn ($item) => [$item['date'], $item['day_name'], $item['count']], $byDay);

        return ['headers' => $headers, 'rows' => $rows];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{headers: list<string>, rows: list<list<string|int|float>>}
     */
    private function extractYearlyData(array $filters, ?int $siteId): array
    {
        $year = (int) ($filters['year'] ?? now()->year);
        $type = $filters['type'] ?? 'calendar';
        if ($type === 'academic') {
            $from = Carbon::create($year, 5, 1)->startOfDay();
            $to = Carbon::create($year + 1, 4, 30)->endOfDay();
        } else {
            $from = Carbon::create($year, 1, 1)->startOfDay();
            $to = Carbon::create($year, 12, 31)->endOfDay();
        }

        $byMonth = $this->reportBuilder->buildMonthlyBreakdown($from, $to, $siteId);
        $headers = ['Month', 'Month Name', 'Event Count'];
        $rows = array_map(fn ($item) => [$item['month'], $item['month_name'], $item['count']], $byMonth);

        return ['headers' => $headers, 'rows' => $rows];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{headers: list<string>, rows: list<list<string|int|float>>}
     */
    private function extractCustomData(array $filters, ?int $siteId): array
    {
        $from = Carbon::parse($filters['from'] ?? now()->subDays(7)->toDateString())->startOfDay();
        $to = Carbon::parse($filters['to'] ?? now()->toDateString())->endOfDay();
        $byDay = $this->reportBuilder->buildDailyBreakdown($from, $to, $siteId);

        $headers = ['Date', 'Day', 'Event Count'];
        $rows = array_map(fn ($item) => [$item['date'], $item['day_name'], $item['count']], $byDay);

        return ['headers' => $headers, 'rows' => $rows];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{headers: list<string>, rows: list<list<string|int|float>>}
     */
    private function extractReviewData(array $filters, ?int $siteId): array
    {
        $from = Carbon::parse($filters['from'] ?? now()->subDays(30)->toDateString())->startOfDay();
        $to = Carbon::parse($filters['to'] ?? now()->toDateString())->endOfDay();
        $report = $this->reportBuilder->buildReviewReport($from, $to, $siteId);

        $headers = ['Camera UUID', 'Camera Name', 'Review Count'];
        $rows = array_map(fn ($cam) => [$cam['camera_uuid'] ?? '', $cam['camera_name'], $cam['count']], $report['by_camera']);

        return ['headers' => $headers, 'rows' => $rows];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{headers: list<string>, rows: list<list<string|int|float>>}
     */
    private function extractUnknownData(array $filters, ?int $siteId): array
    {
        $from = Carbon::parse($filters['from'] ?? now()->subDays(30)->toDateString())->startOfDay();
        $to = Carbon::parse($filters['to'] ?? now()->toDateString())->endOfDay();
        $report = $this->reportBuilder->buildUnknownReport($from, $to, $siteId);

        $headers = ['Camera UUID', 'Camera Name', 'Unknown Count'];
        $rows = array_map(fn ($cam) => [$cam['camera_uuid'] ?? '', $cam['camera_name'], $cam['count']], $report['by_camera']);

        return ['headers' => $headers, 'rows' => $rows];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{headers: list<string>, rows: list<list<string|int|float>>}
     */
    private function extractCameraData(array $filters, ?int $siteId): array
    {
        $from = Carbon::parse($filters['from'] ?? now()->subDays(30)->toDateString())->startOfDay();
        $to = Carbon::parse($filters['to'] ?? now()->toDateString())->endOfDay();
        $report = $this->reportBuilder->buildCameraReport($from, $to, $siteId);

        $headers = ['Camera Code', 'Name', 'Site', 'Zone', 'Status', 'Detected', 'Recognized', 'Review', 'Unknown', 'Allow', 'Deny'];
        $rows = array_map(fn ($cam) => [
            $cam['camera_code'],
            $cam['name'],
            $cam['site_name'],
            $cam['zone_name'],
            $cam['status'],
            $cam['totals']['detected'],
            $cam['totals']['recognized'],
            $cam['totals']['review'],
            $cam['totals']['unknown'],
            $cam['totals']['allow'],
            $cam['totals']['deny'],
        ], $report['cameras']);

        return ['headers' => $headers, 'rows' => $rows];
    }

    /**
     * @return array{headers: list<string>, rows: list<list<string|int|float>>}
     */
    private function extractAiNodeData(?int $siteId): array
    {
        $report = $this->reportBuilder->buildAiNodeReport($siteId);

        $headers = ['Node Code', 'Name', 'Site', 'Hostname', 'IP Address', 'Status', 'Active', 'Cameras'];
        $rows = array_map(fn ($node) => [
            $node['node_code'],
            $node['name'],
            $node['site_name'],
            $node['hostname'],
            $node['ip_address'],
            $node['status'],
            $node['is_active'] ? 'YES' : 'NO',
            $node['camera_count'],
        ], $report['nodes']);

        return ['headers' => $headers, 'rows' => $rows];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{headers: list<string>, rows: list<list<string|int|float>>}
     */
    private function extractHistoryData(array $filters, ?int $siteId): array
    {
        $query = AccessEvent::query()->with(['person', 'camera', 'site'])->latest('event_time')->limit(1000);
        if ($siteId !== null) {
            $query->where('site_id', $siteId);
        }
        if (! empty($filters['from'])) {
            $query->where('event_time', '>=', $filters['from']);
        }
        if (! empty($filters['to'])) {
            $query->where('event_time', '<=', $filters['to']);
        }

        $events = $query->get();

        $headers = ['Event Time', 'Event Type', 'Decision', 'Person Name', 'Camera Name', 'Site Name'];
        $rows = $events->map(fn ($e) => [
            $e->event_time?->toIso8601String() ?? '',
            $e->event_type?->value ?? (string) $e->event_type,
            $e->decision?->value ?? (string) $e->decision,
            $e->person?->name ?? 'N/A',
            $e->camera?->name ?? 'N/A',
            $e->site?->name ?? 'N/A',
        ])->all();

        return ['headers' => $headers, 'rows' => $rows];
    }

    /**
     * @return array{headers: list<string>, rows: list<list<string|int|float>>}
     */
    private function extractDefaultData(ReportExport $export): array
    {
        return [
            'headers' => ['Report Type', 'Format', 'Created At'],
            'rows' => [[$export->report_type, $export->format->value, $export->created_at?->toIso8601String() ?? '']],
        ];
    }
}
