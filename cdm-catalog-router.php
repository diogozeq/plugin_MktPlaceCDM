<?php
/**
 * Plugin Name: CDM Catalog Router
 * Plugin URI: https://github.com/diogozeq/plugin_MktPlaceCDM
 * Description: Plugin WordPress/WooCommerce para roteamento inteligente de pedidos de marketplace para clones de vendedores (Dokan SPMV Engine)
 * Version: 1.0.0
 * Author: CDM Team
 * Author URI: https://teste.casadomedico.com.br/
 * License: GPL-2.0+
 * License URI: http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain: cdm-catalog-router
 * Domain Path: /languages
 * Requires at least: 6.0
 * Requires PHP: 8.2
 * WC requires at least: 8.0
 * WC tested up to: 9.5
 *
 * @package CDM_Catalog_Router
 */

declare(strict_types=1);

// Previne acesso direto
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Define constantes do plugin
define( 'CDM_VERSION', '1.0.0' );
define( 'CDM_ROUTING_VERSION', '1' );
define( 'CDM_PLUGIN_FILE', __FILE__ );
define( 'CDM_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'CDM_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'CDM_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

// Autoloader Composer
if ( file_exists( CDM_PLUGIN_DIR . 'vendor/autoload.php' ) ) {
	require_once CDM_PLUGIN_DIR . 'vendor/autoload.php';
}

/**
 * Verifica dependências do plugin
 *
 * @param bool $fail_hard Se true, desativa e interrompe a execução (ativação).
 * @return bool
 */
function cdm_check_dependencies( bool $fail_hard = false ): bool {
	$missing_dependencies = array();

	// Verifica WooCommerce
	if ( ! class_exists( 'WooCommerce' ) ) {
		$missing_dependencies[] = 'WooCommerce';
	}

	// Verifica Dokan
	if ( ! class_exists( 'WeDevs_Dokan' ) ) {
		$missing_dependencies[] = 'Dokan';
	}

	// Verifica versão do PHP
	if ( version_compare( PHP_VERSION, '8.2', '<' ) ) {
		$missing_dependencies[] = 'PHP 8.2+';
	}

	if ( empty( $missing_dependencies ) ) {
		return true;
	}

	if ( $fail_hard ) {
		deactivate_plugins( CDM_PLUGIN_BASENAME );
		wp_die(
			sprintf(
				/* translators: %s: lista de dependências faltando */
				esc_html__( 'CDM Catalog Router requer as seguintes dependências: %s', 'cdm-catalog-router' ),
				'<strong>' . implode( ', ', $missing_dependencies ) . '</strong>'
			),
			esc_html__( 'Dependências Faltando', 'cdm-catalog-router' ),
			array( 'back_link' => true )
		);
	}

	return false;
}

/**
 * Hook de ativação do plugin
 *
 * @return void
 */
function cdm_activate_plugin() {
	cdm_check_dependencies( true );

	if ( class_exists( 'CDM\Activator' ) ) {
		CDM\Activator::activate();
	}
}
register_activation_hook( __FILE__, 'cdm_activate_plugin' );

/**
 * Hook de desativação do plugin
 *
 * @return void
 */
function cdm_deactivate_plugin() {
	if ( class_exists( 'CDM\Deactivator' ) ) {
		CDM\Deactivator::deactivate();
	}
}
register_deactivation_hook( __FILE__, 'cdm_deactivate_plugin' );

/**
 * Declaração de compatibilidade com HPOS (High-Performance Order Storage)
 * WooCommerce 9.0+ usa HPOS por padrão
 *
 * @return void
 */
add_action(
	'before_woocommerce_init',
	function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
				'custom_order_tables',
				__FILE__,
				true
			);
		}
	}
);

/**
 * Inicializa o plugin após WooCommerce carregar
 *
 * @return void
 */
function cdm_init_plugin() {
	// Verifica dependências novamente
	if ( ! cdm_check_dependencies() ) {
		return;
	}

	// Inicializa o plugin (Singleton)
	if ( class_exists( 'CDM\Plugin' ) ) {
		CDM\Plugin::get_instance();
	}
}
add_action( 'plugins_loaded', 'cdm_init_plugin', 20 );

/**
 * Carrega text domain para tradução
 *
 * @return void
 */
function cdm_load_textdomain() {
	load_plugin_textdomain(
		'cdm-catalog-router',
		false,
		dirname( CDM_PLUGIN_BASENAME ) . '/languages/'
	);
}
add_action( 'init', 'cdm_load_textdomain' );

/**
 * Exibe aviso de dependências faltando no admin
 *
 * @return void
 */
function cdm_admin_notice_missing_dependencies() {
	if ( ! class_exists( 'WooCommerce' ) || ! class_exists( 'WeDevs_Dokan' ) ) {
		?>
		<div class="notice notice-error">
			<p>
				<strong><?php esc_html_e( 'CDM Catalog Router', 'cdm-catalog-router' ); ?>:</strong>
				<?php esc_html_e( 'Este plugin requer WooCommerce e Dokan ativos.', 'cdm-catalog-router' ); ?>
			</p>
		</div>
		<?php
	}
}
add_action( 'admin_notices', 'cdm_admin_notice_missing_dependencies' );
