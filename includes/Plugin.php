<?php
/**
 * Classe principal do plugin (Singleton)
 *
 * @package CDM_Catalog_Router
 */

declare(strict_types=1);

namespace CDM;

/**
 * Orquestrador principal do plugin
 *
 * Esta é a única classe Singleton do plugin.
 * Demais serviços são gerenciados via injeção de dependência.
 */
final class Plugin {

	/**
	 * Instância única do plugin
	 *
	 * @var Plugin|null
	 */
	private static ?Plugin $instance = null;

	/**
	 * Versão do plugin
	 *
	 * @var string
	 */
	private string $version;

	/**
	 * Construtor privado (Singleton)
	 */
	private function __construct() {
		$this->version = CDM_VERSION;
		$this->init_hooks();
	}

	/**
	 * Retorna instância única do plugin
	 *
	 * @return Plugin
	 */
	public static function get_instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Inicializa hooks do WordPress
	 *
	 * @return void
	 */
	private function init_hooks(): void {
		add_action( 'woocommerce_init', array( $this, 'init_features' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
		add_filter( 'cdm_vendor_cep_zones', array( \CDM\VendorCepStorage::class, 'filter_vendor_cep_zones' ), 10, 2 );
	}

	/**
	 * Inicializa features do plugin após WooCommerce carregar
	 *
	 * @return void
	 */
	public function init_features(): void {
		// Hook customizado para permitir extensibilidade
		do_action( 'cdm_before_init' );

		// Inicializar componentes principais com injeção de dependências
		$this->init_core_components();

		if ( is_admin() ) {
			$this->init_admin();
		}

		do_action( 'cdm_after_init' );
	}

	/**
	 * Inicializa componentes principais (injeção de dependências)
	 *
	 * @return void
	 */
	private function init_core_components(): void {
		// 1. Cache Manager (fundação)
		$cache_manager = new \CDM\Cache\CacheManager();

		// 2. Repositories
		$product_repo = new \CDM\Repositories\ProductRepository( $cache_manager );
		$offer_repo   = new \CDM\Repositories\OfferRepository( $cache_manager, $product_repo );
		$vendor_repo  = new \CDM\Repositories\VendorRepository( $cache_manager );
		$stock_repo   = new \CDM\Repositories\StockRepository( $cache_manager, $product_repo );

		// 3. Variation Matcher
		$variation_matcher = new \CDM\Core\VariationMatcher();

		// Injetar matcher no StockRepository (dependência circular resolvida)
		$stock_repo->set_variation_matcher( $variation_matcher );

		// 4. Routing Strategies
		$global_fairness_allocator = new \CDM\Strategies\GlobalFairnessAllocator( $vendor_repo );
		$cep_preferential_allocator = new \CDM\Strategies\CEPPreferentialAllocator(
			$vendor_repo,
			$global_fairness_allocator
		);

		// Determinar estratégia ativa via settings
		$strategy_name = get_option( 'cdm_routing_strategy', 'cep' );
		$active_strategy = match ( $strategy_name ) {
			'fairness' => $global_fairness_allocator,
			'stock'    => new \CDM\Strategies\StockFallbackAllocator(),
			default    => $cep_preferential_allocator,
		};

		// 5. Router Engine
		$router_engine = new \CDM\Core\RouterEngine(
			$product_repo,
			$offer_repo,
			$vendor_repo,
			$stock_repo,
			$variation_matcher,
			$active_strategy
		);

		// 6. CEP State
		$cep_state = new \CDM\Core\CepState();
		$cep_state->init();

		// 7. Session Manager
		$session_manager = new \CDM\Core\SessionManager();
		add_action( 'cdm_cep_changed', array( $session_manager, 'invalidate_on_cep_change' ), 10, 2 );

		// 8. Cart Allocator
		$cart_allocator = new \CDM\Core\CartAllocator( $session_manager, $offer_repo, $cep_state );

		// 7. Cart Interceptor
		$cart_interceptor = new \CDM\Core\CartInterceptor(
			$product_repo,
			$offer_repo,
			$router_engine,
			$cep_state,
			$session_manager,
			$cart_allocator
		);
		$cart_interceptor->init();

		// 9. Cart Reconciler
		$cart_reconciler = new \CDM\Core\CartReconciler( $router_engine, $cart_allocator, $cep_state );
		$cart_reconciler->init();

		// 10. Price Enforcer
		$price_enforcer = new \CDM\Core\PriceEnforcer();
		$price_enforcer->init();

		// 11. Checkout Validator
		$checkout_validator = new \CDM\Core\CheckoutValidator( $product_repo, $offer_repo, $cep_state );
		$checkout_validator->init();

		// 12. Offer Validator
		$offer_validator = new \CDM\Core\OfferValidator( $offer_repo, $product_repo );
		$offer_validator->init();

		// 13. Offer Backfill
		$offer_backfill = new \CDM\Core\OfferBackfill( $offer_repo, $product_repo );
		$offer_backfill->init();

		// 14. Shipping Packager
		$shipping_packager = new \CDM\Core\ShippingPackager();
		$shipping_packager->init();

		// 15. Order Meta
		$order_meta = new \CDM\Core\OrderMeta();
		$order_meta->init();

		// Hook para invalidar cache quando produtos forem atualizados
		add_action(
			'woocommerce_update_product',
			function ( $product_id ) use ( $product_repo, $stock_repo ) {
				$product_repo->invalidate_product_cache( $product_id );

				// Se for variação, invalidar cache de estoque
				$product = wc_get_product( $product_id );
				if ( $product && $product->is_type( 'variation' ) ) {
					$stock_repo->invalidate_stock_cache( $product_id );
				}
			}
		);

		// Hook Dokan: invalidar cache quando clone for atualizado
		add_action(
			'dokan_product_updated',
			function ( $product_id ) use ( $product_repo ) {
				$product_repo->invalidate_product_cache( $product_id );
			}
		);

		// Hook: atualizar last_order_time quando order completar
		add_action(
			'woocommerce_order_status_completed',
			function ( $order_id ) use ( $vendor_repo ) {
				$order = wc_get_order( $order_id );
				if ( ! $order ) {
					return;
				}

				foreach ( $order->get_items() as $item ) {
					if ( ! isset( $item['cdm_seller_id'] ) ) {
						continue;
					}

					$seller_id = (int) $item['cdm_seller_id'];
					$vendor_repo->update_last_order_time( $seller_id, time() );
				}
			}
		);
	}

	/**
	 * Inicializa recursos do admin.
	 *
	 * @return void
	 */
	private function init_admin(): void {
		$admin_page = new \CDM\Admin\AdminPage( $this->version );
		$admin_page->init();
	}

	/**
	 * Carrega assets do admin
	 *
	 * @param string $hook Hook da pagina atual.
	 * @return void
	 */
	public function enqueue_admin_assets( string $hook ): void {
		// Apenas carregar na página do plugin
		if ( ! in_array( $hook, array( 'woocommerce_page_cdm-catalog-router', 'toplevel_page_cdm-catalog-router' ), true ) ) {
			return;
		}

		wp_enqueue_style(
			'cdm-admin-styles',
			CDM_PLUGIN_URL . 'assets/css/admin/admin-styles.css',
			array(),
			$this->version,
			'all'
		);

		wp_enqueue_script(
			'cdm-admin-scripts',
			CDM_PLUGIN_URL . 'assets/js/admin/settings.js',
			array( 'jquery' ),
			$this->version,
			true
		);

		// Passar dados para JavaScript
		wp_localize_script(
			'cdm-admin-scripts',
			'cdmAdmin',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'cdm_admin_nonce' ),
			)
		);
	}

	/**
	 * Retorna versão do plugin
	 *
	 * @return string
	 */
	public function get_version(): string {
		return $this->version;
	}

	/**
	 * Previne clonagem (Singleton)
	 *
	 * @return void
	 */
	private function __clone() {
	}

	/**
	 * Previne unserialize (Singleton)
	 *
	 * @return void
	 */
	public function __wakeup() {
		throw new \Exception( 'Cannot unserialize singleton' );
	}
}
