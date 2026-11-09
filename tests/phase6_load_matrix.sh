#!/usr/bin/env bash
# GreenCorner — Phase 6: the load measurement matrix runner
#
# The path is passed through the environment rather than on the command
# line. The MSYS shell on Windows rewrites an argument that looks like a
# path ("--path=/products" becomes "--path=C:/Program Files/Git/"), and
# the environment is not rewritten.
#
# Usage:
#   bash tests/phase6_load_matrix.sh [url] [path]

set -euo pipefail

export MSYS_NO_PATHCONV=1
export MSYS2_ARG_CONV_EXCL='*'

URL="${1:-http://127.0.0.1:8002}"
PATH_TARGET="${2:-/products}"

export GC_LOAD_PATH="$PATH_TARGET"

cd "$(dirname "$0")/.."

exec ./.tools/php/php.exe tests/phase6_load_matrix.php --url="$URL"
