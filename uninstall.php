<?php
/**
 * Uninstall do plugin
 *
 * Este arquivo é executado quando o plugin é DELETADO (não apenas desativado).
 * Remove todas as opções, transients e dados criados pelo plugin.
 *
 * @package CDM_Catalog_Router
 */

declare(strict_types=1);

// Previne execução direta ou não autorizada
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

/**
 * Remove todas as opções do plugin
 */
$options_to_delete = array(
	'cdm_db_version',
	'cdm_first_activation_time',
	'cdm_routing_strategy',
	'cdm_cache_duration',
	'cdm_enable_logging',
	'cdm_cache_structural_ttl',
	'cdm_cache_stock_ttl',
	'cdm_offer_stale_minutes',
	'cdm_offer_max_lead_time_hours',
	'cdm_backfill_offset',
);

foreach ( $options_to_delete as $option ) {
	delete_option( $option );
}

/**
 * Remove todos os transients do plugin
 */
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$wpdb->query(
	"DELETE FROM {$wpdb->options}
	WHERE option_name LIKE '_transient_cdm_%'
	OR option_name LIKE '_transient_timeout_cdm_%'"
);

/**
 * Remove CEPs manuais dos vendedores
 */
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->usermeta} WHERE meta_key = %s",
		'cdm_vendor_cep_zones'
	)
);

/**
 * Limpa cache de objeto (se disponível)
 */
if ( function_exists( 'wp_cache_flush' ) ) {
	wp_cache_flush();
}

/**
 * Hook customizado para permitir extensões limparem seus dados
 */
do_action( 'cdm_plugin_uninstalled' );
