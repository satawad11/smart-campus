<?php

namespace Tests\Unit;

use App\Support\Vehicles\ThaiPlate;
use App\Support\Vehicles\ThaiProvince;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * VEH-003: backend plate/province rules must match contracts/thai-plates.json, the file the
 * AI service tests against too.
 */
class ThaiPlateContractTest extends TestCase
{
    /** @return array<string, mixed> */
    private static function contract(): array
    {
        $path = dirname(__DIR__, 4).'/contracts/thai-plates.json';

        return json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function plateCases(): iterable
    {
        foreach (self::contract()['plate_cases'] as $i => $case) {
            yield "#{$i} '{$case['input']}'" => [$case];
        }
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function provinceCases(): iterable
    {
        foreach (self::contract()['provinces']['name_cases'] as $i => $case) {
            yield "#{$i} '{$case['input']}'" => [$case];
        }
    }

    /** @param array<string, mixed> $case */
    #[DataProvider('plateCases')]
    public function test_plate_case(array $case): void
    {
        $this->assertSame($case['canonical'], ThaiPlate::tryNormalize($case['input']));
        $this->assertSame($case['canonical'] !== null, ThaiPlate::isValid($case['input']));

        if ($case['canonical'] === null) {
            $this->expectException(InvalidArgumentException::class);
            ThaiPlate::normalize($case['input']);

            return;
        }

        $this->assertSame($case['canonical'], ThaiPlate::normalize($case['input']));
        $this->assertSame($case['display'], ThaiPlate::display($case['input']));
        $this->assertSame($case['kind'], ThaiPlate::kindOf($case['canonical']));
    }

    /** @param array<string, mixed> $case */
    #[DataProvider('provinceCases')]
    public function test_province_name_case(array $case): void
    {
        $this->assertSame($case['code'], ThaiProvince::resolve($case['input']));
    }

    public function test_province_list_matches_contract_exactly(): void
    {
        $list = self::contract()['provinces']['list'];

        $this->assertCount(77, $list);
        $this->assertSame(array_column($list, 'name', 'code'), ThaiProvince::NAMES);

        $aliases = [];
        foreach ($list as $province) {
            foreach ($province['aliases'] ?? [] as $alias) {
                $aliases[$alias] = $province['code'];
            }
        }
        $this->assertEqualsCanonicalizing($aliases, ThaiProvince::ALIASES);
    }

    public function test_every_province_resolves_by_name_code_and_prefixed_name(): void
    {
        foreach (ThaiProvince::NAMES as $code => $name) {
            $this->assertSame($code, ThaiProvince::resolve($name), $name);
            $this->assertSame($code, ThaiProvince::resolve('จังหวัด'.$name), $name);
            $this->assertSame($code, ThaiProvince::resolve('จ.'.$name), $name);
            $this->assertSame($code, ThaiProvince::resolve(strtolower($code)));
            $this->assertTrue(ThaiProvince::isValidCode($code));
        }
        $this->assertFalse(ThaiProvince::isValidCode('BKK'));
        $this->assertNull(ThaiProvince::name('TH-99'));
    }
}
