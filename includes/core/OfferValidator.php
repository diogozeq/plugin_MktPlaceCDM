<?php
/**
 * Offer Validator - Enforce master SKU and offer linkage
 *
 * @package CDM_Catalog_Router
 */

declare(strict_types=1);

namespace CDM\Core;

use CDM\Repositories\OfferRepository;
use CDM\Repositories\ProductRepository;

/**
 * Offer validator
 */
final class OfferValidator {

	/**
	 * Offer repository
	 *
	 * @var OfferRepository
	 */
	private OfferRepository $offer_repo;

	/**
	 * Product repository
	 *
	 * @var ProductRepository
	 */
	private ProductRepository $product_repo;

	/**
	 * Master seller id
	 *
	 * @var int
	 */
	private int $master_seller_id;

	/**
	 * Constructor
	 *
	 * @param OfferRepository   $offer_repo   Offer repository.
	 * @param ProductRepository $product_repo Product repository.
	 */
	public function __construct( OfferRepository $offer_repo, ProductRepository $product_repo ) {
		$this->offer_repo       = $offer_repo;
		$this->product_repo     = $product_repo;
		$this->master_seller_id = (int) apply_filters( 'cdm_master_seller_id', 2 );
	}

	/**
	 * Init hooks
	 *
	 * @return void
	 */
	public function init(): void {
		add_action( 'woocommerce_before_product_object_save', array( $this, 'validate_product' ), 10, 2 );
		add_action( 'dokan_new_product_added', array( $this, 'backfill_offer_context' ), 20, 1 );
		add_action( 'dokan_product_updated', array( $this, 'backfill_offer_context' ), 20, 1 );
	}

	/**
	 * Validate product before save
	 *
	 * @param \WC_Product $product Product.
	 * @return void
	 * @throws \WC_Data_Exception When validation fails.
	 */
	public function validate_product( $product ): void {
		if ( ! $product instanceof \WC_Product ) {
			return;
		}

		$product_id = (int) $product->get_id();
		if ( $product_id <= 0 ) {
			return;
		}

		$is_variation = $product->is_type( 'variation' );
		$parent_id    = $is_variation ? (int) $product->get_parent_id() : 0;
		$base_id      = $is_variation ? $parent_id : $product_id;
		$vendor_id    = $this->offer_repo->get_vendor_id_for_product( $product_id );
		$is_master    = $this->product_repo->is_master_product( $base_id ) || $vendor_id === $this->master_seller_id;

		$current_master_sku = $this->offer_repo->get_master_sku( $product_id );
		$incoming_master_sku = $this->get_incoming_master_sku( $product );

		if ( $current_master_sku && $incoming_master_sku && $current_master_sku !== $incoming_master_sku ) {
			throw new \WC_Data_Exception( 'cdm_master_sku_immutable', __( 'Master SKU is immutable.', 'cdm-catalog-router' ) );
		}

		if ( ! $current_master_sku && $incoming_master_sku ) {
			$this->offer_repo->set_master_sku( $product_id, $incoming_master_sku );
			$current_master_sku = $incoming_master_sku;
		}

		$master_product_id = 0;
		if ( ! $is_master ) {
			$master_product_id = (int) get_post_meta( $base_id, OfferRepository::META_MASTER_PRODUCT_ID, true );
			if ( $master_product_id <= 0 ) {
				$master_product_id = $this->product_repo->get_master_from_clone( $base_id ) ?? 0;
			}
		}

		if ( ! $current_master_sku ) {
			if ( $is_master ) {
				$fallback_sku = $product->get_sku();
				if ( $fallback_sku ) {
					$this->offer_repo->set_master_sku( $product_id, $fallback_sku );
					$current_master_sku = $fallback_sku;
				}
			} elseif ( $master_product_id > 0 ) {
				$master_sku = null;
				if ( $is_variation ) {
					$master_sku = $this->derive_master_sku_from_variation( $product, $master_product_id );
				}

				if ( ! $master_sku ) {
					$master_sku = $this->offer_repo->get_effective_master_sku( $master_product_id );
				}

				if ( $master_sku ) {
					$this->offer_repo->set_master_sku( $product_id, $master_sku );
					$current_master_sku = $master_sku;
				}
			}
		}

		if ( ! $current_master_sku ) {
			throw new \WC_Data_Exception( 'cdm_master_sku_missing', __( 'Master SKU is required.', 'cdm-catalog-router' ) );
		}

		if ( ! $this->is_unique_sku( $product_id, $product->get_sku() ) ) {
			throw new \WC_Data_Exception( 'cdm_sku_duplicate', __( 'SKU must be unique.', 'cdm-catalog-router' ) );
		}

		if ( $is_master ) {
			update_post_meta( $base_id, OfferRepository::META_MASTER_PRODUCT_ID, $base_id );
			update_post_meta( $base_id, OfferRepository::META_VENDOR_ID, $vendor_id );
			return;
		}

		if ( $master_product_id <= 0 ) {
			throw new \WC_Data_Exception( 'cdm_master_product_id_missing', __( 'Master product id is required for offers.', 'cdm-catalog-router' ) );
		}

		update_post_meta( $base_id, OfferRepository::META_MASTER_PRODUCT_ID, $master_product_id );
		update_post_meta( $base_id, OfferRepository::META_VENDOR_ID, $vendor_id );
	}

	/**
	 * Backfill offer context on vendor save
	 *
	 * @param int $product_id Product id.
	 * @return void
	 */
	public function backfill_offer_context( int $product_id ): void {
		$this->offer_repo->resolve_offer_context( $product_id );
	}

	/**
	 * Derive master sku for variation by attributes
	 *
	 * @param \WC_Product $product Variation product.
	 * @param int         $master_product_id Master product id.
	 * @return string|null
	 */
	private function derive_master_sku_from_variation( \WC_Product $product, int $master_product_id ): ?string {
		if ( ! $product->is_type( 'variation' ) ) {
			return null;
		}

		$attrs = $product->get_attributes();
		if ( empty( $attrs ) ) {
			return null;
		}

		$matcher = new VariationMatcher();
		$match_id = $matcher->find_matching_variation_by_attributes( $master_product_id, $attrs );
		if ( ! $match_id ) {
			return null;
		}

		$master_sku = $this->offer_repo->get_master_sku( $match_id );
		if ( ! $master_sku ) {
			$master_sku = get_post_meta( $match_id, '_sku', true );
		}

		return $master_sku ? $this->offer_repo->normalize_master_sku( (string) $master_sku ) : null;
	}

	/**
	 * Get incoming master sku
	 *
	 * @param \WC_Product $product Product.
	 * @return string|null
	 */
	private function get_incoming_master_sku( \WC_Product $product ): ?string {
		$meta = $product->get_meta( OfferRepository::META_MASTER_SKU, true );
		if ( $meta ) {
			return (string) $meta;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( isset( $_POST['cdm_master_sku'] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing
			return sanitize_text_field( wp_unslash( $_POST['cdm_master_sku'] ) );
		}

		return null;
	}

	/**
	 * Check if SKU is unique
	 *
	 * @param int    $product_id Product id.
	 * @param string $sku SKU.
	 * @return bool
	 */
	private function is_unique_sku( int $product_id, string $sku ): bool {
		if ( '' === $sku ) {
			return true;
		}

		if ( function_exists( 'wc_product_has_unique_sku' ) ) {
			return wc_product_has_unique_sku( $product_id, $sku );
		}

		$existing_id = wc_get_product_id_by_sku( $sku );
		return ! $existing_id || (int) $existing_id === $product_id;
	}
}
