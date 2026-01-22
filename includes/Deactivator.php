<?php
/**
 * Classe de desativação do plugin
 *
 * @package CDM_Catalog_Router
 */

declare(strict_types=1);

namespace CDM;

/**
 * Desativação do plugin
 *
 * Esta classe contém toda a lógica executada durante a desativação do plugin.
 * NÃO remove dados do usuário (apenas cleanup temporário).
 */
final class Deactivator {

	/**
	 * Executa ações de desativação do plugin
	 *
	 * @return void
	 */
	public static function deactivate(): void {
		// Limpa transients de cache
		self::clear_cache();

		// Hook customizado para extensibilidade
		do_action( 'cdm_plugin_deactivated' );

		// Flush rewrite rules
		flush_rewrite_rules();
	}

	/**
	 * Limpa cache do plugin
	 *
	 * @return void
	 */
	private static function clear_cache(): void {
		global $wpdb;

		// Remove todos os transients do plugin
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			"DELETE FROM {$wpdb->options}
			WHERE option_name LIKE '_transient_cdm_%'
			OR option_name LIKE '_transient_timeout_cdm_%'"
		);
	}
}
