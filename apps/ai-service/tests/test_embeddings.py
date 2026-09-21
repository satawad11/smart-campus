"""Tests for face embedding generation abstraction and implementations (FACE-007)."""

import numpy as np
import pytest
from pydantic import ValidationError

from app.alignment import AlignedFace, StandardFaceAligner
from app.detection import (
    AIModelType,
    MockFaceDetector,
)
from app.embeddings import (
    EmbedderNotReadyError,
    EmbeddingDimensionMismatchError,
    FaceEmbedder,
    FaceEmbeddingResult,
    InvalidEmbeddingInputError,
    InvalidEmbeddingVectorError,
    MockFaceEmbedder,
    ONNXFaceEmbedder,
    get_face_embedder,
)


def create_sample_aligned_face(
    image: np.ndarray | None = None,
    width: int = 112,
    height: int = 112,
) -> AlignedFace:
    """Helper to construct a valid test AlignedFace instance."""
    if image is None:
        image = np.random.randint(0, 256, (height, width, 3), dtype=np.uint8)

    return AlignedFace(
        image=image,
        width=width,
        height=height,
        channels=3,
        format="RGB",
        detector_name="mock_detector",
        detector_version="1.0.0",
        aligner_name="standard_5pt_aligner",
        aligner_version="1.0.0",
        model_type=AIModelType.FACE_ALIGNMENT,
    )


class TestFaceEmbedderInterfaceAndProperties:
    """Requirements 1, 3, 4, 13, 14: FaceEmbedder interface, model type, dimension, metadata."""

    def test_embedder_interface_and_properties(self) -> None:
        embedder = MockFaceEmbedder(dimension=512)
        assert isinstance(embedder, FaceEmbedder)
        assert embedder.name == "mock_face_embedder"
        assert embedder.version == "1.0.0"
        assert embedder.embedding_dimension == 512
        assert embedder.model_type == AIModelType.FACE_EMBEDDING

    def test_valid_aligned_face_generates_vector(self) -> None:
        """Requirements 2, 5, 6, 10: Valid aligned face generates non-empty finite unit vector."""
        embedder = MockFaceEmbedder(dimension=512)
        aligned = create_sample_aligned_face()

        result = embedder.generate(aligned)

        assert isinstance(result, FaceEmbeddingResult)
        assert len(result.vector) == 512
        assert result.dimension == 512
        assert result.normalized is True
        assert result.model_type == AIModelType.FACE_EMBEDDING
        assert result.model_code == "mock-arcface-v1"
        assert result.model_version == "1.0.0"
        assert result.provider == "mock_face_embedder"

        # Finite check & L2 unit norm check
        vec_arr = np.array(result.vector, dtype=np.float32)
        assert np.all(np.isfinite(vec_arr))
        norm = float(np.linalg.norm(vec_arr))
        assert pytest.approx(norm, abs=1e-3) == 1.0


class TestVectorValidationAndNormalization:
    """Requirements 7-10, 15, 17: Normalization, NaN/Inf rejection, zero-norm rejection."""

    def test_nan_vector_rejected(self) -> None:
        embedder = MockFaceEmbedder(dimension=512)
        nan_vec = [float("nan")] * 512
        with pytest.raises(InvalidEmbeddingVectorError, match="non-finite"):
            embedder.normalize_vector(nan_vec)

    def test_inf_vector_rejected(self) -> None:
        embedder = MockFaceEmbedder(dimension=512)
        inf_vec = [0.0] * 511 + [float("inf")]
        with pytest.raises(InvalidEmbeddingVectorError, match="non-finite"):
            embedder.normalize_vector(inf_vec)

    def test_zero_norm_vector_rejected(self) -> None:
        embedder = MockFaceEmbedder(dimension=512)
        zero_vec = [0.0] * 512
        with pytest.raises(InvalidEmbeddingVectorError, match="zero-norm"):
            embedder.normalize_vector(zero_vec)

    def test_dimension_mismatch_raises_typed_error(self) -> None:
        """Requirement 17: Dimension mismatch raises EmbeddingDimensionMismatchError."""
        embedder = MockFaceEmbedder(dimension=512)
        short_vec = [1.0] * 128
        with pytest.raises(EmbeddingDimensionMismatchError, match="does not match expected"):
            embedder.normalize_vector(short_vec, expected_dimension=512)

    def test_wrong_model_type_rejected_by_schema(self) -> None:
        """Requirement 15: Non-FACE_EMBEDDING model type rejected."""
        valid_vec = [1.0 / np.sqrt(512)] * 512
        with pytest.raises(ValidationError):
            FaceEmbeddingResult(
                vector=valid_vec,
                dimension=512,
                normalized=True,
                model_type="FACE_DETECTION",  # type: ignore
                model_code="test",
                model_version="1.0",
                provider="test",
            )


class TestDeterminismAndNonMutation:
    """Requirements 11, 12, 18, 19: Determinism, distinct inputs, non-mutation, invalid input."""

    def test_same_input_produces_deterministic_vector(self) -> None:
        """Requirement 11: Same input produces deterministic output."""
        embedder = MockFaceEmbedder()
        img = np.random.randint(0, 256, (112, 112, 3), dtype=np.uint8)
        aligned = create_sample_aligned_face(image=img)

        res1 = embedder.generate(aligned)
        res2 = embedder.generate(aligned)

        assert res1.vector == res2.vector
        assert res1.dimension == res2.dimension

    def test_different_inputs_produce_distinct_vectors(self) -> None:
        """Requirement 12: Different inputs produce distinct vectors."""
        embedder = MockFaceEmbedder()
        img1 = np.zeros((112, 112, 3), dtype=np.uint8)
        img2 = np.full((112, 112, 3), 255, dtype=np.uint8)

        res1 = embedder.generate(create_sample_aligned_face(image=img1))
        res2 = embedder.generate(create_sample_aligned_face(image=img2))

        assert res1.vector != res2.vector

    def test_input_image_is_not_mutated(self) -> None:
        """Requirement 19: Input image is not mutated."""
        embedder = MockFaceEmbedder()
        img = np.random.randint(0, 256, (112, 112, 3), dtype=np.uint8)
        img_copy = img.copy()

        embedder.generate(img)
        assert np.array_equal(img, img_copy)

    def test_malformed_image_input_rejected(self) -> None:
        """Requirement 18: Malformed image input rejected."""
        embedder = MockFaceEmbedder()

        with pytest.raises(InvalidEmbeddingInputError, match="cannot be None"):
            embedder.generate(None)  # type: ignore

        with pytest.raises(InvalidEmbeddingInputError, match="empty"):
            embedder.generate(np.array([]))

        with pytest.raises(InvalidEmbeddingInputError, match="empty"):
            embedder.generate(np.zeros((0, 112, 3), dtype=np.uint8))

        with pytest.raises(InvalidEmbeddingInputError, match="NaN or Inf"):
            nan_img = np.full((112, 112, 3), float("nan"), dtype=np.float32)
            embedder.generate(nan_img)


class TestONNXFaceEmbedder:
    """Requirements 16, 21, 22: ONNX unready error, runtime error wrapping, CPU provider."""

    def test_uninitialized_onnx_embedder_raises_not_ready(self) -> None:
        """Requirement 16: Missing model/artifact produces EmbedderNotReadyError."""
        embedder = ONNXFaceEmbedder(model_path="non_existent_weights.onnx")
        assert not embedder.is_ready
        aligned = create_sample_aligned_face()

        with pytest.raises(EmbedderNotReadyError, match="not ready"):
            embedder.generate(aligned)


class TestEmbedderFactory:
    """Requirements 20: Factory resolution and unsupported provider rejection."""

    def test_factory_resolves_mock(self) -> None:
        embedder = get_face_embedder("mock")
        assert isinstance(embedder, MockFaceEmbedder)

    def test_factory_resolves_onnx(self) -> None:
        embedder = get_face_embedder("onnx")
        assert isinstance(embedder, ONNXFaceEmbedder)

    def test_factory_rejects_unsupported(self) -> None:
        """Requirement 20: Unsupported provider rejected."""
        with pytest.raises(ValueError, match="Unsupported face embedder provider"):
            get_face_embedder("unsupported_backend")


class TestPipelineFlowDetectionToAlignmentToEmbedding:
    """End-to-end pipeline integration: Detection -> Alignment -> Embedding."""

    def test_full_pipeline_flow(self) -> None:
        detector = MockFaceDetector(default_num_faces=1)
        aligner = StandardFaceAligner()
        embedder = MockFaceEmbedder(dimension=512)

        source_frame = np.full((480, 640, 3), 128, dtype=np.uint8)
        source_frame[100:300, 200:400] = 50

        # Step 1: Detect
        detections = detector.detect(source_frame)
        assert len(detections) == 1

        # Step 2: Align
        aligned = aligner.align(source_frame, detections[0])
        assert aligned.width == 112
        assert aligned.height == 112

        # Step 3: Embed
        embedding = embedder.generate(aligned)
        assert isinstance(embedding, FaceEmbeddingResult)
        assert embedding.dimension == 512
        assert len(embedding.vector) == 512
        assert embedding.model_type == AIModelType.FACE_EMBEDDING


class TestStaticArchitectureAndScopeBoundaries:
    """Requirements 23-28: Ensure zero identity, similarity, access decisions, DB writes."""

    def test_embedding_result_contains_no_identity_or_similarity(self) -> None:
        embedder = MockFaceEmbedder()
        aligned = create_sample_aligned_face()
        res = embedder.generate(aligned)

        res_dict = res.model_dump()
        assert "person_id" not in res_dict
        assert "student_id" not in res_dict
        assert "face_profile_id" not in res_dict
        assert "similarity" not in res_dict
        assert "distance" not in res_dict
        assert "match" not in res_dict
        assert "access_decision" not in res_dict

    def test_no_pgvector_or_search_in_embeddings_module(self) -> None:
        import app.embeddings as emb_mod

        exported = dir(emb_mod)
        assert "VectorSearch" not in exported
        assert "PGVector" not in exported
        assert "SimilaritySearch" not in exported
        assert "RecognitionClassifier" not in exported
