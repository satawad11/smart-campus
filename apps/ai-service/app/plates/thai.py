"""Thai licence-plate text normalisation and province codes (VEH-003, ACR-VEH-01).

Mirrors the backend (App\\Support\\Vehicles\\ThaiPlate / ThaiProvince). Both are tested against
contracts/thai-plates.json, so an OCR result normalised on the node compares equal to the
plate stored in vehicles.plate_number. The plate pipeline (VEH-008) uses these functions; this
module has no model or camera dependency.
"""

from __future__ import annotations

import re
import unicodedata
from typing import Literal

PlateKind = Literal["PRIVATE", "NUMERIC"]

_PRIVATE = re.compile(r"^[1-9]?[ก-ฮ]{1,3}[0-9]{1,4}$")
_NUMERIC = re.compile(r"^[0-9]{2}[0-9]{4}$")
_PRIVATE_PARTS = re.compile(r"^([1-9]?[ก-ฮ]{1,3})([0-9]{1,4})$")
_SEPARATORS = re.compile(r"[\s ​﻿\-._/·]+")
_THAI_DIGITS = str.maketrans("๐๑๒๓๔๕๖๗๘๙", "0123456789")


def try_normalize(text: str | None) -> str | None:
    """Canonical plate text (Arabic digits, no separators), or None if not a Thai plate."""
    if text is None:
        return None
    value = unicodedata.normalize("NFC", text).translate(_THAI_DIGITS)
    value = _SEPARATORS.sub("", value.strip())
    return value if kind_of(value) is not None else None


def normalize(text: str) -> str:
    """Canonical plate text; raises ValueError when the input is not a Thai plate."""
    value = try_normalize(text)
    if value is None:
        raise ValueError("Not a valid Thai licence plate.")
    return value


def is_valid(text: str | None) -> bool:
    return try_normalize(text) is not None


def kind_of(canonical: str) -> PlateKind | None:
    """PRIVATE or NUMERIC for a canonical plate, None otherwise."""
    if _PRIVATE.fullmatch(canonical):
        return "PRIVATE"
    if _NUMERIC.fullmatch(canonical):
        return "NUMERIC"
    return None


def display(text: str) -> str:
    """Human-readable form: "1กข 1234" or "70-1234"."""
    canonical = normalize(text)
    if kind_of(canonical) == "NUMERIC":
        return f"{canonical[:2]}-{canonical[2:]}"
    match = _PRIVATE_PARTS.fullmatch(canonical)
    assert match is not None  # guaranteed by normalize()
    return f"{match.group(1)} {match.group(2)}"


# ISO 3166-2:TH codes of the 77 provinces -> Thai name.
PROVINCES: dict[str, str] = {
    "TH-10": "กรุงเทพมหานคร",
    "TH-11": "สมุทรปราการ",
    "TH-12": "นนทบุรี",
    "TH-13": "ปทุมธานี",
    "TH-14": "พระนครศรีอยุธยา",
    "TH-15": "อ่างทอง",
    "TH-16": "ลพบุรี",
    "TH-17": "สิงห์บุรี",
    "TH-18": "ชัยนาท",
    "TH-19": "สระบุรี",
    "TH-20": "ชลบุรี",
    "TH-21": "ระยอง",
    "TH-22": "จันทบุรี",
    "TH-23": "ตราด",
    "TH-24": "ฉะเชิงเทรา",
    "TH-25": "ปราจีนบุรี",
    "TH-26": "นครนายก",
    "TH-27": "สระแก้ว",
    "TH-30": "นครราชสีมา",
    "TH-31": "บุรีรัมย์",
    "TH-32": "สุรินทร์",
    "TH-33": "ศรีสะเกษ",
    "TH-34": "อุบลราชธานี",
    "TH-35": "ยโสธร",
    "TH-36": "ชัยภูมิ",
    "TH-37": "อำนาจเจริญ",
    "TH-38": "บึงกาฬ",
    "TH-39": "หนองบัวลำภู",
    "TH-40": "ขอนแก่น",
    "TH-41": "อุดรธานี",
    "TH-42": "เลย",
    "TH-43": "หนองคาย",
    "TH-44": "มหาสารคาม",
    "TH-45": "ร้อยเอ็ด",
    "TH-46": "กาฬสินธุ์",
    "TH-47": "สกลนคร",
    "TH-48": "นครพนม",
    "TH-49": "มุกดาหาร",
    "TH-50": "เชียงใหม่",
    "TH-51": "ลำพูน",
    "TH-52": "ลำปาง",
    "TH-53": "อุตรดิตถ์",
    "TH-54": "แพร่",
    "TH-55": "น่าน",
    "TH-56": "พะเยา",
    "TH-57": "เชียงราย",
    "TH-58": "แม่ฮ่องสอน",
    "TH-60": "นครสวรรค์",
    "TH-61": "อุทัยธานี",
    "TH-62": "กำแพงเพชร",
    "TH-63": "ตาก",
    "TH-64": "สุโขทัย",
    "TH-65": "พิษณุโลก",
    "TH-66": "พิจิตร",
    "TH-67": "เพชรบูรณ์",
    "TH-70": "ราชบุรี",
    "TH-71": "กาญจนบุรี",
    "TH-72": "สุพรรณบุรี",
    "TH-73": "นครปฐม",
    "TH-74": "สมุทรสาคร",
    "TH-75": "สมุทรสงคราม",
    "TH-76": "เพชรบุรี",
    "TH-77": "ประจวบคีรีขันธ์",
    "TH-80": "นครศรีธรรมราช",
    "TH-81": "กระบี่",
    "TH-82": "พังงา",
    "TH-83": "ภูเก็ต",
    "TH-84": "สุราษฎร์ธานี",
    "TH-85": "ระนอง",
    "TH-86": "ชุมพร",
    "TH-90": "สงขลา",
    "TH-91": "สตูล",
    "TH-92": "ตรัง",
    "TH-93": "พัทลุง",
    "TH-94": "ปัตตานี",
    "TH-95": "ยะลา",
    "TH-96": "นราธิวาส",
}

PROVINCE_ALIASES: dict[str, str] = {
    "กรุงเทพฯ": "TH-10",
    "กรุงเทพ": "TH-10",
    "กทม": "TH-10",
    "อยุธยา": "TH-14",
    "โคราช": "TH-30",
}

_CODE_BY_NAME = {name: code for code, name in PROVINCES.items()}
_PROVINCE_SPACES = re.compile(r"[\s ​﻿.]+")


def resolve_province(text: str | None) -> str | None:
    """Resolve a code ("TH-50", any case) or Thai name/alias to the province code."""
    if text is None:
        return None
    value = _PROVINCE_SPACES.sub("", unicodedata.normalize("NFC", text))
    if not value:
        return None
    if value.upper() in PROVINCES:
        return value.upper()
    # Exact name first, so provinces that start with "จ" (จันทบุรี) are not mangled.
    for candidate in (value, re.sub(r"^จังหวัด", "", value), re.sub(r"^จ(?=.)", "", value)):
        code = _CODE_BY_NAME.get(candidate) or PROVINCE_ALIASES.get(candidate)
        if code is not None:
            return code
    return None
