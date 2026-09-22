"""Region-of-interest schemas (CAM-016).

Points are normalized (0.0..1.0, relative to frame width/height) so an ROI
drawn against one camera resolution stays correct if the stream's actual
resolution later changes — the same reason CAM-013's health metrics never
store raw pixel positions either.
"""

from pydantic import BaseModel, Field, field_validator


class RoiPoint(BaseModel):
    x: float = Field(..., ge=0.0, le=1.0)
    y: float = Field(..., ge=0.0, le=1.0)


class RegionOfInterest(BaseModel):
    """A polygon (>= 3 vertices) marking the sub-region of the frame that
    should be processed. No ROI at all (None, handled by callers rather
    than this model) means "process the whole frame" — the pre-CAM-016
    default behavior, unchanged."""

    points: list[RoiPoint] = Field(..., min_length=3)

    @field_validator("points")
    @classmethod
    def validate_not_degenerate(cls, points: list[RoiPoint]) -> list[RoiPoint]:
        unique = {(p.x, p.y) for p in points}
        if len(unique) < 3:
            raise ValueError("ROI polygon must have at least 3 distinct vertices")
        return points

    def as_tuples(self) -> list[tuple[float, float]]:
        return [(p.x, p.y) for p in self.points]
