<?php

namespace Tests\Feature;

use App\Enums\ReportExportFormat;
use App\Enums\ReportExportStatus;
use App\Enums\RoleStatus;
use App\Enums\UserStatus;
use App\Jobs\ExportReportJob;
use App\Models\EventDailySummary;
use App\Models\Notification;
use App\Models\Permission;
use App\Models\ReportExport;
use App\Models\Role;
use App\Models\ScheduledReport;
use App\Models\User;
use App\Models\UserRole;
use App\Services\Report\ReportExportService;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Tests for RPT-013..RPT-018:
 * - Report export job creation and querying
 * - Queued CSV, XLSX, and PDF generation
 * - Export download, notification, and expiration cleanup
 * - Scheduled reports execution
 */
class ReportExportApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        Storage::fake('local');
    }

    private function userWith(array $codes): User
    {
        $user = User::factory()->create(['status' => UserStatus::ACTIVE]);
        $role = Role::create(['code' => 'RPT_'.uniqid(), 'name' => 'Reporter', 'system_role' => false, 'status' => RoleStatus::ACTIVE]);
        if ($codes !== []) {
            $role->permissions()->attach(Permission::whereIn('code', $codes)->pluck('id'));
        }
        UserRole::create(['user_id' => $user->id, 'role_id' => $role->id]);

        return $user;
    }

    public function test_requires_authentication_and_permission(): void
    {
        $this->postJson('/api/v1/reports/exports', [
            'report_type' => 'daily',
            'format' => 'CSV',
        ])->assertStatus(401);

        $noPermUser = $this->userWith([]);
        $this->actingAs($noPermUser, 'web')->postJson('/api/v1/reports/exports', [
            'report_type' => 'daily',
            'format' => 'CSV',
        ])->assertStatus(403);
    }

    public function test_queue_export_job_dispatched_and_records_export(): void
    {
        Queue::fake();
        $user = $this->userWith(['report.export']);

        $res = $this->actingAs($user, 'web')->postJson('/api/v1/reports/exports', [
            'report_type' => 'daily',
            'format' => 'CSV',
            'filters' => ['date' => '2026-09-01'],
        ]);

        $res->assertStatus(202);
        $res->assertJsonPath('data.report_type', 'daily');
        $res->assertJsonPath('data.format', 'CSV');
        $res->assertJsonPath('data.status', 'PENDING');

        Queue::assertPushed(ExportReportJob::class);
    }

    public function test_generate_and_download_csv_export(): void
    {
        $user = $this->userWith(['report.export']);

        EventDailySummary::factory()->create([
            'summary_date' => '2026-08-01',
            'decision' => 'ALLOW',
            'event_count' => 25,
        ]);

        $export = ReportExport::create([
            'user_id' => $user->id,
            'report_type' => 'daily',
            'format' => ReportExportFormat::CSV,
            'filters' => ['date' => '2026-08-01'],
            'status' => ReportExportStatus::PENDING,
            'expires_at' => now()->addDays(7),
        ]);

        // Run job directly
        $job = new ExportReportJob($export);
        $job->handle(app(ReportExportService::class));

        $export->refresh();
        $this->assertSame(ReportExportStatus::COMPLETED, $export->status);
        $this->assertNotNull($export->file_path);
        $this->assertTrue(Storage::disk('local')->exists($export->file_path));

        // Notification created
        $this->assertTrue(Notification::where('user_id', $user->id)->where('type', 'REPORT_EXPORT_COMPLETED')->exists());

        // Download API
        $res = $this->actingAs($user, 'web')->get("/api/v1/reports/exports/{$export->uuid}/download");
        $res->assertOk();
        $this->assertStringContainsString('Hour', $res->streamedContent());
    }

    public function test_generate_and_download_xlsx_export(): void
    {
        $user = $this->userWith(['report.export']);

        $export = ReportExport::create([
            'user_id' => $user->id,
            'report_type' => 'weekly',
            'format' => ReportExportFormat::XLSX,
            'filters' => ['date' => '2026-08-01'],
            'status' => ReportExportStatus::PENDING,
            'expires_at' => now()->addDays(7),
        ]);

        $job = new ExportReportJob($export);
        $job->handle(app(ReportExportService::class));

        $export->refresh();
        $this->assertSame(ReportExportStatus::COMPLETED, $export->status);
        $this->assertTrue(Storage::disk('local')->exists($export->file_path));

        $res = $this->actingAs($user, 'web')->get("/api/v1/reports/exports/{$export->uuid}/download");
        $res->assertOk();
        // XLSX starts with PK (zip magic header)
        $this->assertStringStartsWith('PK', $res->streamedContent());
    }

    public function test_generate_and_download_pdf_export(): void
    {
        $user = $this->userWith(['report.export']);

        $export = ReportExport::create([
            'user_id' => $user->id,
            'report_type' => 'monthly',
            'format' => ReportExportFormat::PDF,
            'filters' => ['date' => '2026-08-01'],
            'status' => ReportExportStatus::PENDING,
            'expires_at' => now()->addDays(7),
        ]);

        $job = new ExportReportJob($export);
        $job->handle(app(ReportExportService::class));

        $export->refresh();
        $this->assertSame(ReportExportStatus::COMPLETED, $export->status);
        $this->assertTrue(Storage::disk('local')->exists($export->file_path));

        $res = $this->actingAs($user, 'web')->get("/api/v1/reports/exports/{$export->uuid}/download");
        $res->assertOk();
        $this->assertStringStartsWith('%PDF-1.4', $res->streamedContent());
    }

    public function test_expired_export_cannot_be_downloaded(): void
    {
        $user = $this->userWith(['report.export']);

        $export = ReportExport::create([
            'user_id' => $user->id,
            'report_type' => 'daily',
            'format' => ReportExportFormat::CSV,
            'status' => ReportExportStatus::COMPLETED,
            'file_path' => 'reports/exports/test.csv',
            'expires_at' => now()->subDay(),
        ]);

        $res = $this->actingAs($user, 'web')->getJson("/api/v1/reports/exports/{$export->uuid}/download");
        $res->assertStatus(410);
    }

    public function test_cleanup_command_removes_expired_exports(): void
    {
        $user = $this->userWith(['report.export']);
        $filePath = 'reports/exports/old.csv';
        Storage::disk('local')->put($filePath, 'dummy data');

        $export = ReportExport::create([
            'user_id' => $user->id,
            'report_type' => 'daily',
            'format' => ReportExportFormat::CSV,
            'status' => ReportExportStatus::COMPLETED,
            'file_path' => $filePath,
            'expires_at' => now()->subDays(2),
        ]);

        Artisan::call('reports:cleanup-exports');

        $export->refresh();
        $this->assertSame(ReportExportStatus::EXPIRED, $export->status);
        $this->assertFalse(Storage::disk('local')->exists($filePath));
    }

    public function test_scheduled_reports_command_dispatches_due_reports(): void
    {
        Queue::fake();
        $user = $this->userWith(['report.export']);

        $scheduled = ScheduledReport::create([
            'name' => 'Weekly Monday Digest',
            'report_type' => 'weekly',
            'schedule_type' => 'WEEKLY',
            'format' => ReportExportFormat::PDF,
            'enabled' => true,
            'created_by' => $user->id,
            'next_run_at' => now()->subMinute(),
        ]);

        Artisan::call('reports:run-scheduled');

        $scheduled->refresh();
        $this->assertNotNull($scheduled->last_run_at);
        $this->assertTrue($scheduled->next_run_at->isFuture());

        Queue::assertPushed(ExportReportJob::class);
        $this->assertTrue(ReportExport::where('report_type', 'weekly')->where('user_id', $user->id)->exists());
    }
}
