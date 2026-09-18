# AI service (FastAPI) — development image (FND-005)
#
# Uses a stable Python base. Real perception dependencies (OpenCV, ONNX
# Runtime) are added when those pipelines are implemented (Module 03/04).
FROM python:3.12-slim

ENV PYTHONDONTWRITEBYTECODE=1 \
    PYTHONUNBUFFERED=1

WORKDIR /app

COPY requirements.txt .
RUN pip install --no-cache-dir -r requirements.txt

COPY . .

EXPOSE 8001
CMD ["uvicorn", "app.main:app", "--host", "0.0.0.0", "--port", "8001"]
