#!/usr/bin/env bash
set -euo pipefail

if ! command -v composer >/dev/null 2>&1; then
	echo "composer is required"
	exit 1
fi

composer install
./tests/bin/install-wp-tests.sh
vendor/bin/phpunit
