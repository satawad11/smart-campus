"""Frame sampling module exports (CAM-007)."""

from app.sampling.sampler import FrameSampler
from app.sampling.schemas import SampledFrame

__all__ = [
    "FrameSampler",
    "SampledFrame",
]
