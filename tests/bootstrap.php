<?php
/**
 * PHPUnit bootstrap for CDM Catalog Router tests
 */

declare(strict_types=1);

$tests_dir = getenv( 'WP_TESTS_DIR' );
if ( ! $tests_dir ) {
	$tests_dir = '/tmp/wordpress-tests-lib';
}

if ( ! file_exists( $tests_dir . '/includes/functions.php' ) ) {
	fwrite( STDOUT, "WP tests dir not found: {$tests_dir}\n" );
	exit( 1 );
}

$core_dir = getenv( 'WP_CORE_DIR' );
if ( ! $core_dir ) {
	$core_dir = '/tmp/wordpress';
}

if ( ! defined( 'WP_CORE_DIR' ) ) {
	define( 'WP_CORE_DIR', $core_dir );
}

require_once $tests_dir . '/includes/functions.php';

tests_add_filter(
	'muplugins_loaded',
	static function () {
		$wc_plugin = WP_CORE_DIR . '/wp-content/plugins/woocommerce/woocommerce.php';
		if ( file_exists( $wc_plugin ) ) {
			require $wc_plugin;
		}

		if ( ! class_exists( 'WeDevs_Dokan' ) ) {
			class WeDevs_Dokan {
			}
		}

		require dirname( __DIR__ ) . '/cdm-catalog-router.php';
	}
);

require $tests_dir . '/includes/bootstrap.php';
