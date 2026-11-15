<?php

namespace App\Support\Vehicles;

/**
 * Province of a Thai licence plate (VEH-003, ACR-VEH-01).
 *
 * Codes are ISO 3166-2:TH for the 77 provinces; vehicles.plate_province stores the code.
 * Kept identical to contracts/thai-plates.json and the AI service (app/plates/thai.py).
 */
final class ThaiProvince
{
    /** @var array<string, string> code => Thai name */
    public const NAMES = [
        'TH-10' => 'กรุงเทพมหานคร',
        'TH-11' => 'สมุทรปราการ',
        'TH-12' => 'นนทบุรี',
        'TH-13' => 'ปทุมธานี',
        'TH-14' => 'พระนครศรีอยุธยา',
        'TH-15' => 'อ่างทอง',
        'TH-16' => 'ลพบุรี',
        'TH-17' => 'สิงห์บุรี',
        'TH-18' => 'ชัยนาท',
        'TH-19' => 'สระบุรี',
        'TH-20' => 'ชลบุรี',
        'TH-21' => 'ระยอง',
        'TH-22' => 'จันทบุรี',
        'TH-23' => 'ตราด',
        'TH-24' => 'ฉะเชิงเทรา',
        'TH-25' => 'ปราจีนบุรี',
        'TH-26' => 'นครนายก',
        'TH-27' => 'สระแก้ว',
        'TH-30' => 'นครราชสีมา',
        'TH-31' => 'บุรีรัมย์',
        'TH-32' => 'สุรินทร์',
        'TH-33' => 'ศรีสะเกษ',
        'TH-34' => 'อุบลราชธานี',
        'TH-35' => 'ยโสธร',
        'TH-36' => 'ชัยภูมิ',
        'TH-37' => 'อำนาจเจริญ',
        'TH-38' => 'บึงกาฬ',
        'TH-39' => 'หนองบัวลำภู',
        'TH-40' => 'ขอนแก่น',
        'TH-41' => 'อุดรธานี',
        'TH-42' => 'เลย',
        'TH-43' => 'หนองคาย',
        'TH-44' => 'มหาสารคาม',
        'TH-45' => 'ร้อยเอ็ด',
        'TH-46' => 'กาฬสินธุ์',
        'TH-47' => 'สกลนคร',
        'TH-48' => 'นครพนม',
        'TH-49' => 'มุกดาหาร',
        'TH-50' => 'เชียงใหม่',
        'TH-51' => 'ลำพูน',
        'TH-52' => 'ลำปาง',
        'TH-53' => 'อุตรดิตถ์',
        'TH-54' => 'แพร่',
        'TH-55' => 'น่าน',
        'TH-56' => 'พะเยา',
        'TH-57' => 'เชียงราย',
        'TH-58' => 'แม่ฮ่องสอน',
        'TH-60' => 'นครสวรรค์',
        'TH-61' => 'อุทัยธานี',
        'TH-62' => 'กำแพงเพชร',
        'TH-63' => 'ตาก',
        'TH-64' => 'สุโขทัย',
        'TH-65' => 'พิษณุโลก',
        'TH-66' => 'พิจิตร',
        'TH-67' => 'เพชรบูรณ์',
        'TH-70' => 'ราชบุรี',
        'TH-71' => 'กาญจนบุรี',
        'TH-72' => 'สุพรรณบุรี',
        'TH-73' => 'นครปฐม',
        'TH-74' => 'สมุทรสาคร',
        'TH-75' => 'สมุทรสงคราม',
        'TH-76' => 'เพชรบุรี',
        'TH-77' => 'ประจวบคีรีขันธ์',
        'TH-80' => 'นครศรีธรรมราช',
        'TH-81' => 'กระบี่',
        'TH-82' => 'พังงา',
        'TH-83' => 'ภูเก็ต',
        'TH-84' => 'สุราษฎร์ธานี',
        'TH-85' => 'ระนอง',
        'TH-86' => 'ชุมพร',
        'TH-90' => 'สงขลา',
        'TH-91' => 'สตูล',
        'TH-92' => 'ตรัง',
        'TH-93' => 'พัทลุง',
        'TH-94' => 'ปัตตานี',
        'TH-95' => 'ยะลา',
        'TH-96' => 'นราธิวาส',
    ];

    /** @var array<string, string> common short name => code */
    public const ALIASES = [
        'กรุงเทพฯ' => 'TH-10',
        'กรุงเทพ' => 'TH-10',
        'กทม' => 'TH-10',
        'อยุธยา' => 'TH-14',
        'โคราช' => 'TH-30',
    ];

    public static function isValidCode(?string $code): bool
    {
        return $code !== null && array_key_exists($code, self::NAMES);
    }

    public static function name(string $code): ?string
    {
        return self::NAMES[$code] ?? null;
    }

    /**
     * Resolve a code ("TH-50", any case) or a Thai name/alias ("เชียงใหม่", "จ.เชียงใหม่",
     * "จังหวัดเชียงใหม่", "กทม") to the province code, or null if unknown.
     */
    public static function resolve(?string $input): ?string
    {
        if ($input === null) {
            return null;
        }

        $text = (string) preg_replace('/[\s\x{00A0}\x{200B}\x{FEFF}.]+/u', '', $input);
        if ($text === '') {
            return null;
        }

        $upper = strtoupper($text);
        if (self::isValidCode($upper)) {
            return $upper;
        }

        // Exact name first, so provinces that start with "จ" (จันทบุรี) are not mangled.
        foreach ([$text, preg_replace('/^จังหวัด/u', '', $text), preg_replace('/^จ(?=.)/u', '', $text)] as $candidate) {
            $code = array_search($candidate, self::NAMES, true) ?: (self::ALIASES[$candidate] ?? null);
            if ($code !== null) {
                return $code;
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    public static function codes(): array
    {
        return array_keys(self::NAMES);
    }
}
