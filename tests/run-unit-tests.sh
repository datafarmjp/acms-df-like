#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
PHP_BIN="${PHP_BIN:-php}"
NODE_BIN="${NODE_BIN:-node}"
PHP_BIN="$PHP_BIN" tests/run-client-ip-tests.sh
"$PHP_BIN" tests/LikeInsertFailureTest.php
"$PHP_BIN" tests/category-placement.php
"$PHP_BIN" tests/csv-import-regression.php
"$NODE_BIN" tests/dynamic-buttons.js
