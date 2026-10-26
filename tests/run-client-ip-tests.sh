#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
PHP_BIN="${PHP_BIN:-php}"
"$PHP_BIN" tests/LikeClientIpTest.php
for mode in default trusted invalid-type invalid-entry; do
  "$PHP_BIN" tests/LikeClientIpPostTest.php "$mode"
done
"$PHP_BIN" tests/LikeRepositoryBehaviorTest.php
