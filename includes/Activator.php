<?php
/**
 * Classe de ativação do plugin
 *
 * @package CDM_Catalog_Router
 */

declare(strict_types=1);

namespace CDM;

/**
 * Ativação do plugin
 *
 * Esta classe contém toda a lógica executada durante a ativação do plugin.
 * NÃO cria SQL Views (bloqueador #1 resolvido - views não funcionam para variações)
 */
final class Activator {

	/**
	 * Executa ações de ativação do plugin
	 *
	 * @return void
	 */
	public static function activate(): void {
		// Verifica versão do PHP
		if ( version_compare( PHP_VERSION, '8.2', '<' ) ) {
			deactivate_plugins( CDM_PLUGIN_BASENAME );
			wp_die(
				esc_html__( 'CDM Catalog Router requer PHP 8.2 ou superior.', 'cdm-catalog-router' ),
				esc_html__( 'Erro de Ativação', 'cdm-catalog-router' ),
				array( 'back_link' => true )
			);
		}

		// Verifica se WooCommerce está ativo
		if ( ! class_exists( 'WooCommerce' ) ) {
			deactivate_plugins( CDM_PLUGIN_BASENAME );
			wp_die(
				esc_html__( 'CDM Catalog Router requer WooCommerce ativo.', 'cdm-catalog-router' ),
				esc_html__( 'Erro de Ativação', 'cdm-catalog-router' ),
				array( 'back_link' => true )
			);
		}

		// Verifica se Dokan está ativo
		if ( ! class_exists( 'WeDevs_Dokan' ) ) {
			deactivate_plugins( CDM_PLUGIN_BASENAME );
			wp_die(
				esc_html__( 'CDM Catalog Router requer Dokan ativo.', 'cdm-catalog-router' ),
				esc_html__( 'Erro de Ativação', 'cdm-catalog-router' ),
				array( 'back_link' => true )
			);
		}

		// Salva versão do plugin
		add_option( 'cdm_db_version', CDM_VERSION );

		// Salva timestamp de primeira ativação
		if ( ! get_option( 'cdm_first_activation_time' ) ) {
			add_option( 'cdm_first_activation_time', time() );
		}

		// Opções padrão
		self::set_default_options();

		// Agendar backfill de ofertas
		if ( ! wp_next_scheduled( 'cdm_backfill_offer_meta' ) ) {
			wp_schedule_single_event( time() + 60, 'cdm_backfill_offer_meta' );
		}

		// Hook customizado para extensibilidade
		do_action( 'cdm_plugin_activated' );

		// Flush rewrite rules
		flush_rewrite_rules();
	}

	/**
	 * Define opções padrão do plugin
	 *
	 * @return void
	 */
	private static function set_default_options(): void {
		// Estratégia de roteamento padrão: CEP Preferencial
		add_option( 'cdm_routing_strategy', 'cep' );

		// Duração do cache: 15 minutos (900 segundos)
		add_option( 'cdm_cache_duration', 900 );

		// Habilitar logging por padrão
		add_option( 'cdm_enable_logging', true );

		// Cache estrutural TTL: 1 hora
		add_option( 'cdm_cache_structural_ttl', 3600 );

		// Cache de estoque TTL: 5 minutos
		add_option( 'cdm_cache_stock_ttl', 300 );

		// Staleness policy: 60 minutos
		add_option( 'cdm_offer_stale_minutes', 60 );

		// Max lead time (0 = ignore)
		add_option( 'cdm_offer_max_lead_time_hours', 0 );
	}
}
