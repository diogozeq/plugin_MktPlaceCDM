#!/usr/bin/env bash
set -euo pipefail

DB_NAME="${DB_NAME:-wordpress_test}"
DB_USER="${DB_USER:-root}"
DB_PASS="${DB_PASS:-root}"
DB_HOST="${DB_HOST:-127.0.0.1}"

WP_CORE_DIR="${WP_CORE_DIR:-/tmp/wordpress}"
WP_TESTS_DIR="${WP_TESTS_DIR:-/tmp/wordpress-tests-lib}"
WP_PHPUNIT_DIR="${WP_PHPUNIT_DIR:-$(pwd)/vendor/wp-phpunit/wp-phpunit}"

if ! command -v curl >/dev/null 2>&1; then
	echo "curl is required"
	exit 1
fi

if ! command -v unzip >/dev/null 2>&1; then
	echo "unzip is required"
	exit 1
fi

if [ ! -d "${WP_CORE_DIR}" ]; then
	echo "Downloading WordPress core..."
	curl -sS -o /tmp/wordpress.tar.gz https://wordpress.org/latest.tar.gz
	tar -xzf /tmp/wordpress.tar.gz -C /tmp
	mv /tmp/wordpress "${WP_CORE_DIR}"
fi

if [ ! -d "${WP_CORE_DIR}/wp-content/plugins/woocommerce" ]; then
	echo "Downloading WooCommerce..."
	mkdir -p "${WP_CORE_DIR}/wp-content/plugins"
	curl -sS -L -o /tmp/woocommerce.zip https://downloads.wordpress.org/plugin/woocommerce.latest-stable.zip
	unzip -q /tmp/woocommerce.zip -d "${WP_CORE_DIR}/wp-content/plugins"
fi

mkdir -p "${WP_TESTS_DIR}"

if [ ! -d "${WP_PHPUNIT_DIR}/includes" ]; then
	echo "wp-phpunit not found at ${WP_PHPUNIT_DIR}"
	exit 1
fi

if command -v rsync >/dev/null 2>&1; then
	rsync -a "${WP_PHPUNIT_DIR}/includes/" "${WP_TESTS_DIR}/includes/"
	rsync -a "${WP_PHPUNIT_DIR}/data/" "${WP_TESTS_DIR}/data/"
else
	cp -R "${WP_PHPUNIT_DIR}/includes" "${WP_TESTS_DIR}/includes"
	cp -R "${WP_PHPUNIT_DIR}/data" "${WP_TESTS_DIR}/data"
fi

cat > "${WP_TESTS_DIR}/wp-tests-config.php" <<EOF
<?php
define( 'DB_NAME', '${DB_NAME}' );
define( 'DB_USER', '${DB_USER}' );
define( 'DB_PASSWORD', '${DB_PASS}' );
define( 'DB_HOST', '${DB_HOST}' );
define( 'DB_CHARSET', 'utf8' );
define( 'DB_COLLATE', '' );

define( 'ABSPATH', '${WP_CORE_DIR}/' );
define( 'WP_DEBUG', true );

if ( ! defined( 'WP_TESTS_DOMAIN' ) ) {
	define( 'WP_TESTS_DOMAIN', 'example.test' );
}
if ( ! defined( 'WP_TESTS_EMAIL' ) ) {
	define( 'WP_TESTS_EMAIL', 'admin@example.test' );
}
if ( ! defined( 'WP_TESTS_TITLE' ) ) {
	define( 'WP_TESTS_TITLE', 'CDM Plugin Tests' );
}
if ( ! defined( 'WP_PHP_BINARY' ) ) {
	define( 'WP_PHP_BINARY', 'php' );
}
if ( ! defined( 'WPLANG' ) ) {
	define( 'WPLANG', '' );
}
EOF

if command -v mysql >/dev/null 2>&1; then
	echo "Creating test database..."
	mysql -h "${DB_HOST}" -u "${DB_USER}" -p"${DB_PASS}" -e "CREATE DATABASE IF NOT EXISTS ${DB_NAME};"
fi
