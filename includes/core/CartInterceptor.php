<?php
/**
 * Cart Interceptor - Intercepts add-to-cart and routes to offers
 *
 * @package CDM_Catalog_Router
 */

declare(strict_types=1);

namespace CDM\Core;

use CDM\Repositories\ProductRepository;
use CDM\Repositories\OfferRepository;

/**
 * Cart interceptor
 */
final class CartInterceptor {

	/**
	 * Product Repository
	 *
	 * @var ProductRepository
	 */
	private ProductRepository $product_repo;

	/**
	 * Offer Repository
	 *
	 * @var OfferRepository
	 */
	private OfferRepository $offer_repo;

	/**
	 * Router Engine
	 *
	 * @var RouterEngine
	 */
	private RouterEngine $router_engine;

	/**
	 * CEP State
	 *
	 * @var CepState
	 */
	private CepState $cep_state;

	/**
	 * Session Manager
	 *
	 * @var SessionManager
	 */
	private SessionManager $session_manager;

	/**
	 * Cart Allocator
	 *
	 * @var CartAllocator
	 */
	private CartAllocator $cart_allocator;

	/**
	 * Constructor
	 *
	 * @param ProductRepository $product_repo Product repository.
	 * @param OfferRepository   $offer_repo Offer repository.
	 * @param RouterEngine      $router_engine Router engine.
	 * @param CepState          $cep_state CEP state.
	 * @param SessionManager    $session_manager Session manager.
	 * @param CartAllocator     $cart_allocator Cart allocator.
	 */
	public function __construct(
		ProductRepository $product_repo,
		OfferRepository $offer_repo,
		RouterEngine $router_engine,
		CepState $cep_state,
		SessionManager $session_manager,
		CartAllocator $cart_allocator
	) {
		$this->product_repo    = $product_repo;
		$this->offer_repo      = $offer_repo;
		$this->router_engine   = $router_engine;
		$this->cep_state       = $cep_state;
		$this->session_manager = $session_manager;
		$this->cart_allocator  = $cart_allocator;
	}

	/**
	 * Init hooks
	 *
	 * @return void
	 */
	public function init(): void {
		add_filter( 'woocommerce_add_to_cart_validation', array( $this, 'validate_and_route' ), 10, 6 );
		add_action( 'woocommerce_store_api_validate_add_to_cart', array( $this, 'validate_store_api' ), 10, 2 );
	}

	/**
	 * Validate and route add-to-cart
	 *
	 * @param bool  $passed       Passed validation.
	 * @param int   $product_id   Product id.
	 * @param int   $quantity     Quantity.
	 * @param int   $variation_id Variation id.
	 * @param array $variations   Variation attributes.
	 * @param array $cart_item_data Cart item data.
	 * @return bool
	 */
	public function validate_and_route(
		bool $passed,
		int $product_id,
		int $quantity,
		$variation_id = 0,
		$variations = array(),
		$cart_item_data = array()
	): bool {
		if ( ! $passed ) {
			return false;
		}

		$variation_id = (int) $variation_id;

		if ( ! $this->product_repo->is_master_product( $product_id ) ) {
			$context = $this->offer_repo->resolve_offer_context(
				$variation_id > 0 ? $variation_id : $product_id
			);

			if ( ! $context['valid'] ) {
				$this->log_block( 'Offer blocked: invalid context', $this->build_offer_log_context(
					$product_id,
					$variation_id,
					$variations,
					$context
				) );
				wc_add_notice(
					__( 'Oferta sem master SKU ou master product id.', 'cdm-catalog-router' ),
					'error'
				);
				return false;
			}

			return true;
		}

		$cep = $this->cep_state->get_active_cep();
		if ( ! $cep ) {
			$cep = $this->get_customer_cep();
			if ( $cep ) {
				$this->cep_state->set_active_cep( $cep, 'customer' );
			}
		}

		$master_sku = $this->offer_repo->get_effective_master_sku(
			$variation_id > 0 ? $variation_id : $product_id
		);
		if ( ! $master_sku ) {
			wc_add_notice(
				__( 'Produto sem master SKU configurado.', 'cdm-catalog-router' ),
				'error'
			);
			return false;
		}

		$sticky_decision = $this->session_manager->get_routing_decision(
			$product_id,
			$variation_id,
			$variations,
			$cep
		);

		if ( $sticky_decision && $this->is_sticky_valid( $sticky_decision ) ) {
			return $this->apply_sticky_routing( $sticky_decision, $quantity, $variations );
		}

		$routing_result = $this->router_engine->route_product(
			$product_id,
			$quantity,
			$variation_id,
			$variations,
			$cep
		);

		if ( ! $routing_result['success'] ) {
			wc_add_notice(
				$routing_result['error'] ?? __( 'Nao foi possivel rotear este produto.', 'cdm-catalog-router' ),
				'error'
			);
			return false;
		}

		$allocations = $routing_result['allocations'];
		return $this->add_clones_to_cart( $allocations, $product_id, $variation_id, $variations );
	}

	/**
	 * Add routed offers to cart
	 *
	 * @param array $allocations Allocations.
	 * @param int   $master_id Master product id.
	 * @param int   $master_variation_id Master variation id.
	 * @param array $variations Variation attributes.
	 * @return bool
	 */
	private function add_clones_to_cart(
		array $allocations,
		int $master_id,
		int $master_variation_id,
		array $variations
	): bool {
		$cep = $this->cep_state->get_active_cep();
		if ( ! $cep ) {
			$cep = $this->get_customer_cep();
		}

		$this->cart_allocator->add_allocations(
			$allocations,
			$master_id,
			$master_variation_id,
			$variations,
			$cep
		);

		wc_add_notice(
			__( 'Produto adicionado ao carrinho com sucesso.', 'cdm-catalog-router' ),
			'success'
		);

		return false;
	}

	/**
	 * Apply sticky routing
	 *
	 * @param array $sticky_decision Sticky decision.
	 * @param int   $quantity Quantity.
	 * @param array $variations Variation attributes.
	 * @return bool
	 */
	private function apply_sticky_routing( array $sticky_decision, int $quantity, array $variations ): bool {
		return $this->add_clones_to_cart(
			$sticky_decision['allocations'],
			$sticky_decision['master_id'],
			$sticky_decision['master_variation_id'],
			$variations
		);
	}

	/**
	 * Check sticky routing validity
	 *
	 * @param array $sticky_decision Sticky decision.
	 * @return bool
	 */
	private function is_sticky_valid( array $sticky_decision ): bool {
		$age = time() - $sticky_decision['timestamp'];
		return $age < 86400;
	}

	/**
	 * Build log context for blocked offers
	 *
	 * @param int   $product_id Product id.
	 * @param int   $variation_id Variation id.
	 * @param array $variations Variation attributes.
	 * @param array $context Offer context.
	 * @return array
	 */
	private function build_offer_log_context(
		int $product_id,
		int $variation_id,
		array $variations,
		array $context
	): array {
		$offer_product_id = $variation_id > 0 ? $variation_id : $product_id;
		$attrs_candidate  = $this->get_product_attrs( $offer_product_id );
		$attrs_master     = ! empty( $variations ) ? $variations : $attrs_candidate;

		return array(
			'reason'            => 'invalid_offer_context',
			'product_id'        => $product_id,
			'variation_id'      => $variation_id,
			'offer_product_id'  => $offer_product_id,
			'master_product_id' => (int) ( $context['master_product_id'] ?? 0 ),
			'master_sku'        => $context['master_sku'] ?? null,
			'vendor_id'         => (int) ( $context['vendor_id'] ?? 0 ),
			'errors'            => $context['errors'] ?? array(),
			'attrs_master'      => $attrs_master,
			'attrs_candidate'   => $attrs_candidate,
		);
	}

	/**
	 * Log block events
	 *
	 * @param string $message Message.
	 * @param array  $context Context.
	 * @return void
	 */
	private function log_block( string $message, array $context = array() ): void {
		if ( ! get_option( 'cdm_enable_logging', true ) ) {
			return;
		}

		if ( class_exists( 'WC_Logger' ) ) {
			$logger = wc_get_logger();
			$logger->warning( $message, array_merge( array( 'source' => 'cdm-cart-interceptor' ), $context ) );
		}
	}

	/**
	 * Get product attributes for logging
	 *
	 * @param int $product_id Product id.
	 * @return array
	 */
	private function get_product_attrs( int $product_id ): array {
		$product = wc_get_product( $product_id );
		if ( $product && $product->is_type( 'variation' ) ) {
			return $product->get_attributes();
		}

		return array();
	}

	/**
	 * Get customer CEP
	 *
	 * @return string|null
	 */
	private function get_customer_cep(): ?string {
		if ( ! WC()->customer ) {
			return null;
		}

		$cep = WC()->customer->get_shipping_postcode();
		if ( ! $cep ) {
			$cep = WC()->customer->get_billing_postcode();
		}

		return $cep ? preg_replace( '/\D/', '', $cep ) : null;
	}

	/**
	 * Validate Store API add-to-cart
	 *
	 * @param \WC_Product     $product Product.
	 * @param \WP_REST_Request $request Request.
	 * @return void
	 * @throws \Exception When master product is added.
	 */
	public function validate_store_api( $product, $request ): void {
		if ( ! $this->product_repo->is_master_product( $product->get_id() ) ) {
			$variation_id = 0;
			if ( is_array( $request ) || $request instanceof \ArrayAccess ) {
				$variation_id = (int) ( $request['variation_id'] ?? 0 );
			} elseif ( is_object( $request ) && method_exists( $request, 'get_param' ) ) {
				$variation_id = (int) $request->get_param( 'variation_id' );
			}
			$context      = $this->offer_repo->resolve_offer_context(
				$variation_id > 0 ? $variation_id : $product->get_id()
			);

			if ( $context['valid'] ) {
				return;
			}

			$this->log_block( 'Offer blocked: invalid context (store api)', $this->build_offer_log_context(
				$product->get_id(),
				$variation_id,
				array(),
				$context
			) );

			$message = __( 'Oferta sem master SKU ou master product id.', 'cdm-catalog-router' );
			if ( class_exists( \Automattic\WooCommerce\StoreApi\Exceptions\RouteException::class ) ) {
				throw new \Automattic\WooCommerce\StoreApi\Exceptions\RouteException(
					'cdm_offer_invalid',
					$message,
					400
				);
			}

			throw new \Exception( $message );
		}

		$message = __( 'Este produto requer roteamento. Use o carrinho padrao.', 'cdm-catalog-router' );
		if ( class_exists( \Automattic\WooCommerce\StoreApi\Exceptions\RouteException::class ) ) {
			throw new \Automattic\WooCommerce\StoreApi\Exceptions\RouteException(
				'cdm_route_required',
				$message,
				400
			);
		}

		throw new \Exception( $message );
	}
}
