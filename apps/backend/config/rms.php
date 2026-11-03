<?php

return [
    /*
    |--------------------------------------------------------------------------
    | RMS API Center v1 (read-only student/staff source)
    |--------------------------------------------------------------------------
    |
    | The college's RMS exposes staff, groups (classes), students and
    | timetables as JSON (docs/integrations/RMS_API_CENTER.md). The key is
    | issued by the RMS administrator, is sent only in the Authorization
    | header, and must stay in .env: never in git, logs or the browser.
    | RMS also restricts callers by IP: the server's address must be on its
    | allow-list.
    |
    */
    'url' => env('RMS_API_URL', 'https://rms.dmtc.ac.th/api_center.php'),

    'key' => env('RMS_API_KEY'),

    'timeout_seconds' => (int) env('RMS_API_TIMEOUT_SECONDS', 30),

    // Items per page (RMS allows 1-500).
    'page_limit' => 500,

    // Longest Retry-After (seconds) honoured on HTTP 429 before giving up.
    'max_retry_after_seconds' => (int) env('RMS_API_MAX_RETRY_AFTER_SECONDS', 60),

    /*
    |--------------------------------------------------------------------------
    | Sync (ACR-INT-01)
    |--------------------------------------------------------------------------
    |
    | The daily sync is off until the operator has reviewed a dry run
    | (php artisan rms:sync --dry-run) and sets RMS_SYNC_ENABLED=true.
    |
    */
    'sync' => [
        'enabled' => (bool) env('RMS_SYNC_ENABLED', false),
        'cron' => env('RMS_SYNC_CRON', '30 5 * * *'),

        // A run that would see fewer students than this share of the
        // students already linked stops before writing (RMS outage or a key
        // that lost a scope must not look like a mass withdrawal).
        'min_student_ratio' => (float) env('RMS_SYNC_MIN_STUDENT_RATIO', 0.5),
    ],

    // Staff positions that become Person type TEACHER; every other position
    // (including "ไม่มี") becomes STAFF.
    'teacher_positions' => ['ครู', 'ครูผู้ช่วย', 'ผู้บริหาร'],

    // Title prefixes removed from staff first names (RMS sends the title
    // inside "name"); the full form stays in display_name.
    'name_titles' => ['ว่าที่ร้อยตรีหญิง', 'ว่าที่ร้อยตรี', 'ว่าที่ ร.ต.หญิง', 'ว่าที่ ร.ต.', 'นางสาว', 'นาง', 'นาย', 'ดร.'],

    // RMS groups carry no department; the group's major decides it. Keys are
    // RMS major names, values are department names here (created when
    // missing). A major not listed goes to "แผนกวิชา<major>", reported in the
    // sync run. DRAFT from the 2026-11-03 preview: confirm with the
    // registrar before enabling the daily sync.
    'major_departments' => [
        'ช่างยนต์' => 'แผนกวิชาช่างยนต์',
        'เทคนิคเครื่องกล' => 'แผนกวิชาช่างยนต์',
        'ช่างกลโรงงาน' => 'แผนกวิชาช่างกลโรงงาน',
        'เทคนิคการผลิต' => 'แผนกวิชาช่างกลโรงงาน',
        'ช่างเชื่อมโลหะ' => 'แผนกวิชาช่างเชื่อมโลหะ',
        'เทคนิคโลหะ' => 'แผนกวิชาช่างเชื่อมโลหะ',
        'ไฟฟ้า' => 'แผนกวิชาช่างไฟฟ้ากำลัง',
        'ช่างไฟฟ้า' => 'แผนกวิชาช่างไฟฟ้ากำลัง',
        'ช่างไฟฟ้ากำลัง' => 'แผนกวิชาช่างไฟฟ้ากำลัง',
        'อิเล็กทรอนิกส์' => 'แผนกวิชาช่างอิเล็กทรอนิกส์',
        'เทคโนโลยีอิเล็กทรอนิกส์' => 'แผนกวิชาช่างอิเล็กทรอนิกส์',
        'ช่างก่อสร้าง' => 'แผนกวิชาช่างก่อสร้าง',
        'โยธา' => 'แผนกวิชาช่างโยธา',
        'สถาปัตยกรรม' => 'แผนกวิชาช่างสถาปัตยกรรม',
        'เทคนิคสถาปัตยกรรม' => 'แผนกวิชาช่างสถาปัตยกรรม',
        'เฟอร์นิเจอร์และตกแต่งภายใน' => 'แผนกวิชาช่างอุตสาหกรรมเครื่องเรือน',
        'เทคนิคอุตสาหกรรม' => 'แผนกวิชาเทคนิคอุตสาหกรรม',
        'ช่างซ่อมบำรุง' => 'แผนกวิชาเทคนิคอุตสาหกรรม',
        'เมคคาทรอนิกส์และหุ่นยนต์' => 'แผนกวิชาช่างเมคคาทรอนิกส์',
        'ช่างเทคนิคคอมพิวเตอร์' => 'แผนกวิชาช่างเทคนิคคอมพิวเตอร์',
        'เทคโนโลยีคอมพิวเตอร์' => 'แผนกวิชาช่างเทคนิคคอมพิวเตอร์',
        'ช่างอากาศยาน' => 'แผนกวิชาช่างอากาศยาน',
        'ธุรกิจการบิน' => 'แผนกวิชาธุรกิจการบิน',
        'การบัญชี' => 'แผนกวิชาการบัญชี',
        'การตลาด' => 'แผนกวิชาการตลาด',
        'เทคโนโลยีธุรกิจดิจิทัล' => 'แผนกวิชาเทคโนโลยีธุรกิจดิจิทัล',
        'คอมพิวเตอร์ธุรกิจ' => 'แผนกวิชาเทคโนโลยีธุรกิจดิจิทัล',
        'การโรงแรม' => 'แผนกวิชาการโรงแรม',
        'การท่องเที่ยว' => 'แผนกวิชาการท่องเที่ยว',
        'ไมซ์และอีเวนต์' => 'แผนกวิชาไมซ์และอีเวนต์',
        'การจัดการโลจิสติกส์และซัพพลายเชน' => 'แผนกวิชาการจัดการโลจิสติกส์และซัพพลายเชน',
    ],
];
