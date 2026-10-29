#!/usr/bin/env bash
# Use the same portable builder on all release platforms.
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
exec python3 "$ROOT/scripts/build-release-zip.py"
