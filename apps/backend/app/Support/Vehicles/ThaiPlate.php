<?php

namespace App\Support\Vehicles;

use InvalidArgumentException;
use Normalizer;

/**
 * Thai licence-plate text rules (VEH-003, ACR-VEH-01).
 *
 * The canonical form is what vehicles.plate_number stores and what plate lookups compare:
 * Arabic digits, no separators. The same rules are implemented in the AI service
 * (app/plates/thai.py); both are tested against contracts/thai-plates.json.
 *
 * Accepted shapes after normalisation:
 *   PRIVATE  optional leading digit 1-9, 1-3 Thai consonants, 1-4 digits   e.g. 1กข1234
 *   NUMERIC  six digits (commercial "70-1234" style)                       e.g. 701234
 */
final class ThaiPlate
{
    public const KIND_PRIVATE = 'PRIVATE';

    public const KIND_NUMERIC = 'NUMERIC';

    private const PRIVATE_PATTERN = '/^[1-9]?[ก-ฮ]{1,3}[0-9]{1,4}$/u';

    private const NUMERIC_PATTERN = '/^[0-9]{2}[0-9]{4}$/u';

    private const THAI_DIGITS = ['๐', '๑', '๒', '๓', '๔', '๕', '๖', '๗', '๘', '๙'];

    /** Spaces (incl. no-break, zero-width) and the separators people and OCR put in plates. */
    private const SEPARATORS = '/[\s\x{00A0}\x{200B}\x{FEFF}\-._\/\x{00B7}]+/u';

    /**
     * Canonical plate text, or null when the input is not a valid Thai plate.
     */
    public static function tryNormalize(?string $input): ?string
    {
        if ($input === null) {
            return null;
        }

        $text = class_exists(Normalizer::class) ? (Normalizer::normalize($input, Normalizer::FORM_C) ?: $input) : $input;
        $text = str_replace(self::THAI_DIGITS, ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'], $text);
        $text = (string) preg_replace(self::SEPARATORS, '', trim($text));

        return self::kindOf($text) === null ? null : $text;
    }

    /**
     * Canonical plate text.
     *
     * @throws InvalidArgumentException when the input is not a valid Thai plate
     */
    public static function normalize(string $input): string
    {
        return self::tryNormalize($input)
            ?? throw new InvalidArgumentException('Not a valid Thai licence plate.');
    }

    public static function isValid(?string $input): bool
    {
        return self::tryNormalize($input) !== null;
    }

    /**
     * PRIVATE or NUMERIC for a canonical plate, null otherwise.
     */
    public static function kindOf(string $canonical): ?string
    {
        return match (true) {
            preg_match(self::PRIVATE_PATTERN, $canonical) === 1 => self::KIND_PRIVATE,
            preg_match(self::NUMERIC_PATTERN, $canonical) === 1 => self::KIND_NUMERIC,
            default => null,
        };
    }

    /**
     * Human-readable form: "1กข 1234" or "70-1234".
     *
     * @throws InvalidArgumentException when the input is not a valid Thai plate
     */
    public static function display(string $input): string
    {
        $canonical = self::normalize($input);

        if (self::kindOf($canonical) === self::KIND_NUMERIC) {
            return substr($canonical, 0, 2).'-'.substr($canonical, 2);
        }

        preg_match('/^([1-9]?[ก-ฮ]{1,3})([0-9]{1,4})$/u', $canonical, $parts);

        return $parts[1].' '.$parts[2];
    }
}
