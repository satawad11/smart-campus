"""VEH-003: AI-service plate/province rules must match contracts/thai-plates.json.

The backend test suite checks the same file, so a plate normalised on the node equals the
plate stored by the backend.
"""

from __future__ import annotations

import json
from pathlib import Path

import pytest

from app.plates import thai

CONTRACT = json.loads(
    (Path(__file__).resolve().parents[3] / "contracts" / "thai-plates.json").read_text("utf-8")
)


@pytest.mark.parametrize("case", CONTRACT["plate_cases"], ids=lambda c: repr(c["input"]))
def test_plate_case(case: dict) -> None:
    assert thai.try_normalize(case["input"]) == case["canonical"]
    assert thai.is_valid(case["input"]) is (case["canonical"] is not None)

    if case["canonical"] is None:
        with pytest.raises(ValueError):
            thai.normalize(case["input"])
        return

    assert thai.normalize(case["input"]) == case["canonical"]
    assert thai.display(case["input"]) == case["display"]
    assert thai.kind_of(case["canonical"]) == case["kind"]


@pytest.mark.parametrize(
    "case", CONTRACT["provinces"]["name_cases"], ids=lambda c: repr(c["input"])
)
def test_province_name_case(case: dict) -> None:
    assert thai.resolve_province(case["input"]) == case["code"]


def test_province_list_matches_contract_exactly() -> None:
    provinces = CONTRACT["provinces"]["list"]
    assert len(provinces) == 77
    assert thai.PROVINCES == {p["code"]: p["name"] for p in provinces}
    assert thai.PROVINCE_ALIASES == {
        alias: p["code"] for p in provinces for alias in p.get("aliases", [])
    }


def test_every_province_resolves_by_name_code_and_prefixed_name() -> None:
    for code, name in thai.PROVINCES.items():
        assert thai.resolve_province(name) == code
        assert thai.resolve_province("จังหวัด" + name) == code
        assert thai.resolve_province("จ." + name) == code
        assert thai.resolve_province(code.lower()) == code
    assert thai.resolve_province(None) is None
    assert thai.try_normalize(None) is None
