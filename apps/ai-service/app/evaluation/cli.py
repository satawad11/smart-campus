"""CLI command for running AI benchmark harness (FACE-019)."""

import argparse
import json
from pathlib import Path

from app.evaluation.harness import AIModelBenchmarkHarness


def main():
    parser = argparse.ArgumentParser(description="Run AI Face Benchmark Suite")
    parser.add_argument("--output-json", type=str, default=None, help="Path to write output JSON")
    parser.add_argument("--output-md", type=str, default=None, help="Path to write output Markdown")
    args = parser.parse_args()

    harness = AIModelBenchmarkHarness()
    results = harness.run_benchmark()
    md_content = harness.generate_markdown_report(results)

    if args.output_json:
        out_path = Path(args.output_json)
        out_path.parent.mkdir(parents=True, exist_ok=True)
        out_path.write_text(json.dumps(results, indent=2), encoding="utf-8")
        print(f"Benchmark JSON written to {args.output_json}")

    if args.output_md:
        out_path = Path(args.output_md)
        out_path.parent.mkdir(parents=True, exist_ok=True)
        out_path.write_text(md_content, encoding="utf-8")
        print(f"Benchmark Markdown written to {args.output_md}")

    total_ms = results["latencies_ms"]["total_pipeline_ms"]
    print(f"EER: {results['eer'] * 100:.2f}%, Total Latency: {total_ms} ms")


if __name__ == "__main__":
    main()
