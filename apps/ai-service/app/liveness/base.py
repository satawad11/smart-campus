"""Base interface for Face Liveness / PAD Detection (FACE-015)."""

from abc import ABC, abstractmethod
from dataclasses import dataclass, field
from typing import Any

import numpy as np


@dataclass
class LivenessResult:
    """Dataclass holding liveness evaluation outcome."""

    score: float
    is_live: bool
    method: str
    status: str
    rejection_reasons: list[str] = field(default_factory=list)
    details: dict[str, Any] = field(default_factory=dict)


class FaceLivenessDetector(ABC):
    """Abstract base class for face liveness / presentation attack detection."""

    @abstractmethod
    def evaluate(
        self,
        image: np.ndarray,
        bounding_box: tuple[float, float, float, float] | None = None,
    ) -> LivenessResult:
        """Evaluate presentation attack likelihood on the face image."""
        pass
