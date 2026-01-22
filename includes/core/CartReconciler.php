<?php
/**
 * Cart Reconciler - Re-route items on CEP change
 *
 * @package CDM_Catalog_Router
 */

declare(strict_types=1);

namespace CDM\Core;

/**
 * Cart reconciler
 */
final class CartReconciler {

	/**
	 * Router engine
	 *
	 * @var RouterEngine
	 */
	private RouterEngine $router_engine;

	/**
	 * Cart allocator
	 *
	 * @var CartAllocator
	 */
	private CartAllocator $cart_allocator;

	/**
	 * CEP state
	 *
	 * @var CepState
	 */
	private CepState $cep_state;

	/**
	 * Guard flag
	 *
	 * @var bool
	 */
	private bool $is_reconciling = false;

	/**
	 * Constructor
	 *
	 * @param RouterEngine $router_engine Router engine.
	 * @param CartAllocator $cart_allocator Cart allocator.
	 * @param CepState $cep_state CEP state.
	 */
	public function __construct( RouterEngine $router_engine, CartAllocator $cart_allocator, CepState $cep_state ) {
		$this->router_engine  = $router_engine;
		$this->cart_allocator = $cart_allocator;
		$this->cep_state      = $cep_state;
	}

	/**
	 * Init hooks
	 *
	 * @return void
	 */
	public function init(): void {
		add_action( 'woocommerce_cart_loaded_from_session', array( $this, 'reconcile_cart' ), 20 );
		add_action( 'woocommerce_cart_updated', array( $this, 'reconcile_cart' ), 20 );
		add_action( 'woocommerce_checkout_process', array( $this, 'reconcile_cart' ), 5 );
		add_action( 'cdm_cep_changed', array( $this, 'handle_cep_change' ), 10, 2 );
	}

	/**
	 * Handle CEP change event
	 *
	 * @param string|null $old CEP antigo.
	 * @param string|null $new CEP novo.
	 * @return void
	 */
	public function handle_cep_change( ?string $old, ?string $new ): void {
		if ( $old === $new ) {
			return;
		}

		$this->reconcile_cart();
	}

	/**
	 * Reconcile cart items against active CEP
	 *
	 * @return void
	 */
	public function reconcile_cart(): void {
		if ( $this->is_reconciling ) {
			return;
		}

		if ( ! WC()->cart ) {
			return;
		}

		$active_cep = $this->cep_state->get_active_cep();
		if ( ! $active_cep ) {
			return;
		}

		$cart = WC()->cart->get_cart();
		if ( empty( $cart ) ) {
			return;
		}

		$groups = $this->group_routed_items( $cart );
		if ( empty( $groups ) ) {
			return;
		}

		$this->is_reconciling = true;

		foreach ( $groups as $group ) {
			$group_cep = $group['cep_used'] ?? null;
			if ( $group_cep === $active_cep ) {
				continue;
			}

			$this->reroute_group( $group, $active_cep );
		}

		$this->is_reconciling = false;
	}

	/**
	 * Group routed items by master key
	 *
	 * @param array $cart Cart contents.
	 * @return array
	 */
	private function group_routed_items( array $cart ): array {
		$groups = array();

		foreach ( $cart as $cart_item_key => $cart_item ) {
			if ( empty( $cart_item['cdm_routed'] ) ) {
				continue;
			}

			$master_id         = (int) ( $cart_item['cdm_master_id'] ?? 0 );
			$master_variation  = (int) ( $cart_item['cdm_master_variation_id'] ?? 0 );
			$attrs_hash        = $cart_item['cdm_attrs_hash'] ?? '';

			if ( $master_id <= 0 ) {
				continue;
			}

			$key = $master_id . ':' . $master_variation . ':' . $attrs_hash;

			if ( ! isset( $groups[ $key ] ) ) {
				$groups[ $key ] = array(
					'master_id'           => $master_id,
					'master_variation_id' => $master_variation,
					'variations'          => $cart_item['variation'] ?? array(),
					'attrs_hash'          => $attrs_hash,
					'qty'                 => 0,
					'items'               => array(),
					'cep_used'            => $cart_item['cdm_cep_used'] ?? null,
				);
			}

			$groups[ $key ]['qty']   += (int) $cart_item['quantity'];
			$groups[ $key ]['items'][] = $cart_item_key;
		}

		return array_values( $groups );
	}

	/**
	 * Reroute a group of items
	 *
	 * @param array  $group Group data.
	 * @param string $cep   Active CEP.
	 * @return void
	 */
	private function reroute_group( array $group, string $cep ): void {
		foreach ( $group['items'] as $cart_item_key ) {
			WC()->cart->remove_cart_item( $cart_item_key );
		}

		$routing_result = $this->router_engine->route_product(
			$group['master_id'],
			$group['qty'],
			$group['master_variation_id'] ? (int) $group['master_variation_id'] : null,
			$group['variations'],
			$cep
		);

		if ( ! $routing_result['success'] ) {
			wc_add_notice(
				__( 'Alguns itens foram removidos por indisponibilidade no seu CEP.', 'cdm-catalog-router' ),
				'error'
			);

			return;
		}

		$this->cart_allocator->add_allocations(
			$routing_result['allocations'],
			$group['master_id'],
			$group['master_variation_id'],
			$group['variations'],
			$cep
		);

		wc_add_notice(
			__( 'Itens ajustados conforme disponibilidade para o seu CEP.', 'cdm-catalog-router' ),
			'notice'
		);
	}
}
