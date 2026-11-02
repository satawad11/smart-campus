<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\StudentProfile;
use App\Services\Integration\Rms\RmsApiException;
use App\Services\Integration\Rms\RmsClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * RMS API Center v1 read-only client and the rms:preview report. Fixtures
 * follow the vendor document (docs/integrations/RMS_API_CENTER.md) with
 * invented people; no real data.
 */
class RmsIntegrationPreviewTest extends TestCase
{
    use RefreshDatabase;

    private const URL = 'https://rms.example.test/api_center.php';

    private const KEY = 'rmsk_test0000_0000000000000000000000000000000000000000000000';

    protected function setUp(): void
    {
        parent::setUp();
        config(['rms.url' => self::URL, 'rms.key' => self::KEY]);
    }

    private function ok(array $extra): array
    {
        return ['ok' => true, 'api' => 'rms', 'v' => 1, 'ts' => 1793101774, ...$extra];
    }

    private function fakeRms(): void
    {
        Http::fake(function (Request $request) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $q);

            return match ($q['res'] ?? '') {
                'school' => Http::response($this->ok([
                    'school' => ['id' => 1, 'name' => 'วิทยาลัยทดสอบ'],
                    'term' => ['id' => '2/2569', 'start' => '2026-11-01', 'end' => '2027-03-31'],
                ])),
                'staff' => Http::response($this->ok(['res' => 'staff', 'page' => 1, 'limit' => 500, 'has_more' => false, 'items' => [
                    ['id' => 's_aaaa', 'name' => 'นายสมมุติ', 'surname' => 'ทดสอบ', 'position' => 'ครู', 'departments' => ['ช่างยนต์']],
                    ['id' => 's_bbbb', 'name' => 'นางสมมุติ', 'surname' => 'ทดลอง', 'position' => 'เจ้าหน้าที่', 'departments' => ['บริหารงานทั่วไป']],
                ]])),
                'groups' => Http::response($this->ok(['res' => 'groups', 'page' => 1, 'limit' => 500, 'has_more' => false, 'items' => [
                    ['id' => 'g1', 'short_name' => 'ชย.1/1', 'name' => 'ปวช.1 ช่างยนต์ 1', 'level' => 'ปวช.', 'grade' => 1, 'major' => 'ยานยนต์', 'department' => 'ช่างยนต์', 'year' => 2569, 'advisors' => ['s_aaaa']],
                    ['id' => 'g2', 'short_name' => 'ชฟ.2/1', 'name' => 'ปวส.2 ไฟฟ้า 1', 'level' => 'ปวส.', 'grade' => 2, 'major' => 'ไฟฟ้ากำลัง', 'department' => 'ช่างไฟฟ้า', 'year' => 2569, 'advisors' => []],
                ]])),
                'students' => ($q['page'] ?? '1') === '1'
                    ? Http::response($this->ok(['res' => 'students', 'page' => 1, 'limit' => 500, 'has_more' => true, 'items' => [
                        ['id' => '6920100001', 'prefix' => 'นาย', 'name' => 'หนึ่ง', 'surname' => 'ทดสอบ', 'group_id' => 'g1', 'number' => 1],
                        ['id' => '6920100002', 'prefix' => 'นางสาว', 'name' => 'สอง', 'surname' => 'ทดสอบ', 'group_id' => 'g1', 'number' => 2],
                    ]]))
                    : Http::response($this->ok(['res' => 'students', 'page' => 2, 'limit' => 500, 'has_more' => false, 'items' => [
                        ['id' => '6830200001', 'prefix' => 'นาย', 'name' => 'สาม', 'surname' => 'ทดสอบ', 'group_id' => 'g2', 'number' => 1],
                    ]])),
                default => Http::response(['ok' => false, 'api' => 'rms', 'v' => 1, 'ts' => 1, 'error' => 'not_found', 'error_msg' => 'ไม่มี res นี้'], 404),
            };
        });
    }

    public function test_client_sends_the_key_only_in_the_header_and_follows_pages(): void
    {
        $this->fakeRms();

        $students = RmsClient::fromConfig()->all('students');

        $this->assertCount(3, $students);
        Http::assertSent(function (Request $request) {
            return $request->hasHeader('Authorization', 'Bearer '.self::KEY)
                && ! str_contains($request->url(), self::KEY)
                && str_contains($request->url(), 'v=1')
                && $request->method() === 'GET';
        });
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'page=2') && str_contains($r->url(), 'limit=500'));
    }

    public function test_api_errors_carry_the_code_and_never_the_key(): void
    {
        Http::fake(['*' => Http::response(['ok' => false, 'api' => 'rms', 'v' => 1, 'ts' => 1, 'error' => 'ip', 'error_msg' => 'เครื่องนี้ไม่อยู่ในรายการ IP ที่โรงเรียนอนุญาต'], 403)]);

        try {
            RmsClient::fromConfig()->get('school');
            $this->fail('expected an RmsApiException');
        } catch (RmsApiException $e) {
            $this->assertSame('ip', $e->error);
            $this->assertSame(403, $e->httpStatus);
            $this->assertStringNotContainsString(self::KEY, $e->getMessage());
        }
    }

    public function test_missing_key_or_plain_http_is_refused_before_any_call(): void
    {
        Http::fake();

        config(['rms.key' => null]);
        try {
            RmsClient::fromConfig()->get('school');
            $this->fail('expected no_key');
        } catch (RmsApiException $e) {
            $this->assertSame('no_key', $e->error);
        }

        config(['rms.key' => self::KEY, 'rms.url' => 'http://rms.example.test/api_center.php']);
        try {
            RmsClient::fromConfig()->get('school');
            $this->fail('expected https_required');
        } catch (RmsApiException $e) {
            $this->assertSame('https_required', $e->error);
        }

        Http::assertNothingSent();
    }

    public function test_rate_limit_is_retried_once_after_retry_after(): void
    {
        Http::fakeSequence()
            ->push(['ok' => false, 'api' => 'rms', 'v' => 1, 'ts' => 1, 'error' => 'rate', 'error_msg' => 'ถี่เกินไป'], 429, ['Retry-After' => '1'])
            ->push($this->ok(['school' => ['id' => 1, 'name' => 'วิทยาลัยทดสอบ'], 'term' => null]));

        $this->assertSame('วิทยาลัยทดสอบ', RmsClient::fromConfig()->get('school')['school']['name']);
    }

    public function test_preview_reports_mapping_without_writing_or_printing_people(): void
    {
        $this->fakeRms();
        Department::factory()->create(['name' => 'ช่างยนต์']);
        StudentProfile::factory()->create(['student_code' => '6920100001']);
        $before = StudentProfile::count();

        $this->assertSame(0, Artisan::call('rms:preview', ['--json' => true]));
        $report = json_decode(Artisan::output(), true);

        $this->assertSame(2, $report['staff']['count']);
        $this->assertSame(['ปวช.' => 1, 'ปวส.' => 1], $report['groups']['levels']);
        $this->assertSame(3, $report['students']['count']);
        $this->assertSame(3, $report['students']['with_known_group']);
        $this->assertSame(['ช่างไฟฟ้า'], $report['mapping']['departments_missing_here']);
        $this->assertSame(1, $report['mapping']['student_codes_already_here']);
        $this->assertSame(2, $report['mapping']['student_codes_new']);

        // Read-only and no personal data in the report
        $this->assertSame($before, StudentProfile::count());
        $json = json_encode($report, JSON_UNESCAPED_UNICODE);
        foreach (['หนึ่ง', 'สมมุติ', '6920100002', 's_aaaa'] as $personal) {
            $this->assertStringNotContainsString($personal, $json);
        }
    }

    public function test_preview_explains_an_ip_block(): void
    {
        Http::fake(['*' => Http::response(['ok' => false, 'api' => 'rms', 'v' => 1, 'ts' => 1, 'error' => 'ip', 'error_msg' => 'x'], 403)]);

        $this->artisan('rms:preview')
            ->expectsOutputToContain('RMS error [ip]')
            ->assertExitCode(1);
    }
}
