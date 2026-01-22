<?php
/**
 * Cart Allocator - Adds routed offers to cart
 *
 * @package CDM_Catalog_Router
 */

declare(strict_types=1);

namespace CDM\Core;

use CDM\Repositories\OfferRepository;

/**
 * Cart allocator helper
 */
final class CartAllocator {

	/**
	 * Session manager
	 *
	 * @var SessionManager
	 */
	private SessionManager $session_manager;

	/**
	 * Offer repository
	 *
	 * @var OfferRepository
	 */
	private OfferRepository $offer_repo;

	/**
	 * CEP state
	 *
	 * @var CepState
	 */
	private CepState $cep_state;

	/**
	 * Constructor
	 *
	 * @param SessionManager  $session_manager Session manager.
	 * @param OfferRepository $offer_repo      Offer repository.
	 * @param CepState        $cep_state       CEP state.
	 */
	public function __construct( SessionManager $session_manager, OfferRepository $offer_repo, CepState $cep_state ) {
		$this->session_manager = $session_manager;
		$this->offer_repo      = $offer_repo;
		$this->cep_state       = $cep_state;
	}

	/**
	 * Add allocations to cart
	 *
	 * @param array       $allocations Allocations list.
	 * @param int         $master_id Master product id.
	 * @param int         $master_variation_id Master variation id.
	 * @param array       $variations Variation attributes.
	 * @param string|null $cep CEP used.
	 * @return bool
	 */
	public function add_allocations(
		array $allocations,
		int $master_id,
		int $master_variation_id,
		array $variations,
		?string $cep
	): bool {
		$master_sku = $this->offer_repo->get_effective_master_sku(
			$master_variation_id > 0 ? $master_variation_id : $master_id
		);

		$cep_used = $cep ? $this->cep_state->normalize_cep( $cep ) : null;

		foreach ( $allocations as $allocation ) {
			$clone_parent_id    = $allocation['clone_parent_id'];
			$clone_variation_id = $allocation['clone_variation_id'] ?? 0;
			$seller_id          = $allocation['seller_id'];
			$qty                = $allocation['qty'];

			$offer_product_id = $clone_variation_id ? $clone_variation_id : $clone_parent_id;

			$custom_data = array(
				'cdm_routed'               => true,
				'cdm_master_id'            => $master_id,
				'cdm_master_variation_id'  => $master_variation_id,
				'cdm_master_sku'           => $master_sku,
				'cdm_offer_product_id'     => $offer_product_id,
				'cdm_offer_parent_id'      => $clone_parent_id,
				'cdm_seller_id'            => $seller_id,
				'cdm_cep_used'             => $cep_used,
				'cdm_routing_version'      => defined( 'CDM_ROUTING_VERSION' ) ? CDM_ROUTING_VERSION : CDM_VERSION,
				'cdm_allocation_timestamp' => time(),
				'cdm_attrs_hash'           => md5( (string) wp_json_encode( $variations ) ),
			);

			if ( $clone_variation_id ) {
				WC()->cart->add_to_cart(
					$clone_parent_id,
					$qty,
					$clone_variation_id,
					$variations,
					$custom_data
				);
			} else {
				WC()->cart->add_to_cart(
					$clone_parent_id,
					$qty,
					0,
					array(),
					$custom_data
				);
			}
		}

		if ( $cep_used ) {
			$this->session_manager->store_routing_decision(
				$master_id,
				$master_variation_id,
				$variations,
				$cep_used,
				$allocations
			);
		}

		return true;
	}
}
