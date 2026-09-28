#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)";cd "$ROOT"
EVIDENCE_DIR="${1:-${SOKNA_G6_EVIDENCE_DIR:-}}"
SOURCE_HEAD="${2:-${SOKNA_G6_SOURCE_HEAD:-}}"
if [[ -z "$EVIDENCE_DIR" ]]; then echo 'G6 final qualification requires an evidence directory.' >&2; exit 3; fi
if [[ -z "$SOURCE_HEAD" ]]; then SOURCE_HEAD="$(git rev-parse HEAD)"; fi
python3 tests/g6-release-preflight.py
python3 tests/product-parity-gate.py --mode inventory
python3 tests/g6-evidence-aggregate.py --evidence-dir "$EVIDENCE_DIR" --source-head "$SOURCE_HEAD"
