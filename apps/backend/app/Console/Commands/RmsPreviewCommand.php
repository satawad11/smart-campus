<?php

namespace App\Console\Commands;

use App\Models\Classroom;
use App\Models\Department;
use App\Models\Program;
use App\Models\StudentProfile;
use App\Services\Integration\Rms\RmsApiException;
use App\Services\Integration\Rms\RmsClient;
use Illuminate\Console\Command;

/**
 * Read-only look at the RMS API Center data before any sync is built or
 * run: counts, the distinct values of the fields that must be mapped
 * (level, grade, department, major, position) and how they line up with
 * this platform's departments, programs, classrooms and student codes.
 *
 * Writes nothing and prints no personal data (no names, no ids of people):
 * only counts and category values.
 */
class RmsPreviewCommand extends Command
{
    protected $signature = 'rms:preview {--json : Print the report as JSON}';

    protected $description = 'Read-only RMS API Center preview: counts and field values to map (no writes, no personal data)';

    public function handle(): int
    {
        $client = RmsClient::fromConfig();

        try {
            $school = $client->get('school');
            $staff = $client->all('staff');
            $groups = $client->all('groups');
            $students = $client->all('students');
        } catch (RmsApiException $e) {
            $this->error("RMS error [{$e->error}]: {$e->getMessage()}");
            if ($e->error === 'ip') {
                $this->line('Ask the RMS administrator to allow this server\'s public IP address.');
            }

            return self::FAILURE;
        }

        $report = $this->report($school, $staff, $groups, $students);

        if ($this->option('json')) {
            $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        $this->info("School: {$report['school']['name']} | current term: ".($report['school']['term'] ?? '-'));
        $this->line("Staff: {$report['staff']['count']}");
        $this->table(['Position', 'Staff'], $this->rows($report['staff']['positions']));
        $this->table(['Staff department', 'Staff'], $this->rows($report['staff']['departments']));
        $this->line("Groups: {$report['groups']['count']}");
        foreach (['levels' => 'Level', 'grades' => 'Grade', 'years' => 'Year (B.E.)', 'majors' => 'Major'] as $key => $label) {
            $this->table([$label, 'Groups'], $this->rows($report['groups'][$key]));
        }
        $this->table(['Group department', 'Groups', 'Exists here'], array_map(
            fn ($name, $n) => [$name, $n, in_array($name, $report['mapping']['departments_missing_here'], true) ? 'NO' : 'yes'],
            array_keys($report['groups']['departments']),
            $report['groups']['departments'],
        ));
        $s = $report['students'];
        $this->line("Students: {$s['count']} | in a known group: {$s['with_known_group']} | without group: {$s['without_group']} | duplicate ids: {$s['duplicate_ids']}");
        $m = $report['mapping'];
        $this->line("Already here (same student code): {$m['student_codes_already_here']} | new: {$m['student_codes_new']} | here but not in RMS: {$m['student_codes_here_not_in_rms']}");
        $this->line("Group short names matching a classroom code here: {$m['groups_matching_classroom']} / {$report['groups']['count']}");
        $this->line("Majors matching a program name here: {$m['majors_matching_program']} / ".count($report['groups']['majors']));

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $school
     * @param  list<array<string, mixed>>  $staff
     * @param  list<array<string, mixed>>  $groups
     * @param  list<array<string, mixed>>  $students
     * @return array<string, mixed>
     */
    private function report(array $school, array $staff, array $groups, array $students): array
    {
        $groupIds = array_flip(array_map(fn ($g) => (string) ($g['id'] ?? ''), $groups));
        $studentIds = array_map(fn ($s) => (string) ($s['id'] ?? ''), $students);

        $staffDepartments = [];
        foreach ($staff as $person) {
            foreach ((array) ($person['departments'] ?? []) as $d) {
                $name = is_array($d) ? (string) ($d['name'] ?? json_encode($d, JSON_UNESCAPED_UNICODE)) : (string) $d;
                $staffDepartments[$name] = ($staffDepartments[$name] ?? 0) + 1;
            }
        }
        arsort($staffDepartments);

        $groupDepartments = $this->countBy($groups, 'department');
        $ourDepartments = Department::pluck('name')->all();
        $ourCodes = StudentProfile::pluck('student_code')->all();
        $rmsCodes = array_flip($studentIds);
        $alreadyHere = count(array_filter($ourCodes, fn ($c) => isset($rmsCodes[$c])));
        $classroomCodes = array_flip(Classroom::pluck('code')->all());
        $programNames = array_flip(Program::pluck('name')->all());
        $majors = $this->countBy($groups, 'major');

        return [
            'school' => [
                'name' => (string) ($school['school']['name'] ?? '-'),
                'term' => $school['term']['id'] ?? null,
            ],
            'staff' => [
                'count' => count($staff),
                'positions' => $this->countBy($staff, 'position'),
                'departments' => $staffDepartments,
            ],
            'groups' => [
                'count' => count($groups),
                'levels' => $this->countBy($groups, 'level'),
                'grades' => $this->countBy($groups, 'grade'),
                'years' => $this->countBy($groups, 'year'),
                'majors' => $majors,
                'departments' => $groupDepartments,
                'without_advisor' => count(array_filter($groups, fn ($g) => empty($g['advisors']))),
            ],
            'students' => [
                'count' => count($students),
                'with_known_group' => count(array_filter($students, fn ($s) => isset($groupIds[(string) ($s['group_id'] ?? '')]))),
                'without_group' => count(array_filter($students, fn ($s) => empty($s['group_id']))),
                'duplicate_ids' => count($studentIds) - count(array_unique($studentIds)),
                'field_names' => array_keys($students[0] ?? []),
            ],
            'mapping' => [
                'departments_missing_here' => array_values(array_diff(array_keys($groupDepartments), $ourDepartments)),
                'student_codes_already_here' => $alreadyHere,
                'student_codes_new' => count($rmsCodes) - $alreadyHere,
                'student_codes_here_not_in_rms' => count($ourCodes) - $alreadyHere,
                'groups_matching_classroom' => count(array_filter($groups, fn ($g) => isset($classroomCodes[(string) ($g['short_name'] ?? '')]))),
                'majors_matching_program' => count(array_filter(array_keys($majors), fn ($m) => isset($programNames[$m]))),
            ],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return array<string, int>
     */
    private function countBy(array $items, string $field): array
    {
        $counts = [];
        foreach ($items as $item) {
            $value = $item[$field] ?? null;
            $key = $value === null || $value === '' ? '(empty)' : (string) $value;
            $counts[$key] = ($counts[$key] ?? 0) + 1;
        }
        arsort($counts);

        return $counts;
    }

    /**
     * @param  array<string, int>  $counts
     * @return list<array{0: string, 1: int}>
     */
    private function rows(array $counts): array
    {
        return array_map(fn ($k, $v) => [$k, $v], array_keys($counts), $counts);
    }
}
