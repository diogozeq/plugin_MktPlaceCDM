<?php
/**
 * Offer Repository - Master SKU and offer context helpers
 *
 * @package CDM_Catalog_Router
 */

declare(strict_types=1);

namespace CDM\Repositories;

use CDM\Cache\CacheManager;

/**
 * Repository for master SKU and offer metadata
 */
final class OfferRepository extends BaseRepository {

	public const META_MASTER_SKU        = 'cdm_master_sku';
	public const META_MASTER_PRODUCT_ID = 'cdm_master_product_id';
	public const META_VENDOR_ID         = 'cdm_vendor_id';
	public const META_OFFER_UPDATED_AT  = 'cdm_offer_updated_at';
	public const META_OFFER_LEAD_TIME   = 'cdm_offer_lead_time_hours';

	/**
	 * Product repository
	 *
	 * @var ProductRepository
	 */
	private ProductRepository $product_repo;

	/**
	 * Master seller id (admin)
	 *
	 * @var int
	 */
	private int $master_seller_id;

	/**
	 * Constructor
	 *
	 * @param CacheManager      $cache_manager Cache manager.
	 * @param ProductRepository $product_repo  Product repository.
	 */
	public function __construct( CacheManager $cache_manager, ProductRepository $product_repo ) {
		parent::__construct( $cache_manager );
		$this->product_repo     = $product_repo;
		$this->master_seller_id = (int) apply_filters( 'cdm_master_seller_id', 2 );
	}

	/**
	 * Resolve offer context for a product or variation
	 *
	 * @param int $product_id Product id.
	 * @return array{valid: bool, errors: array<int,string>, product_id: int, master_product_id: int, master_sku: string|null, vendor_id: int, is_master: bool}
	 */
	public function resolve_offer_context( int $product_id ): array {
		$errors  = array();
		$product = wc_get_product( $product_id );

		if ( ! $product ) {
			return array(
				'valid'             => false,
				'errors'            => array( 'product_not_found' ),
				'product_id'        => $product_id,
				'master_product_id' => 0,
				'master_sku'        => null,
				'vendor_id'         => 0,
				'is_master'         => false,
			);
		}

		$is_variation = $product->is_type( 'variation' );
		$parent_id    = $is_variation ? (int) $product->get_parent_id() : 0;
		$base_id      = $is_variation ? $parent_id : $product_id;
		$vendor_id    = $this->get_vendor_id_for_product( $product_id );
		$is_master    = $this->product_repo->is_master_product( $base_id ) || $vendor_id === $this->master_seller_id;

		$master_product_id = $is_master
			? $base_id
			: (int) get_post_meta( $base_id, self::META_MASTER_PRODUCT_ID, true );

		if ( ! $is_master && $master_product_id <= 0 ) {
			$master_product_id = $this->product_repo->get_master_from_clone( $base_id ) ?? 0;
			if ( $master_product_id > 0 ) {
				update_post_meta( $base_id, self::META_MASTER_PRODUCT_ID, $master_product_id );
			}
		}

		$master_sku = $this->get_effective_master_sku( $product_id );
		if ( ! $master_sku && $is_master ) {
			$master_sku = $this->get_effective_master_sku( $base_id );
		}

		if ( ! $master_sku && ! $is_master && $master_product_id > 0 && $product->is_type( 'variation' ) ) {
			$derived = $this->derive_master_sku_from_variation( $product, $master_product_id );
			if ( $derived ) {
				$master_sku = $derived;
				$this->set_master_sku( $product_id, $master_sku );
			}
		}

		if ( ! $master_sku && ! $is_master && $master_product_id > 0 ) {
			$master_sku = $this->get_effective_master_sku( $master_product_id );
			if ( $master_sku ) {
				$this->set_master_sku( $product_id, $master_sku );
			}
		}

		if ( ! $master_sku && $is_master ) {
			$fallback_sku = $product->get_sku();
			if ( $fallback_sku ) {
				$master_sku = $this->normalize_master_sku( $fallback_sku );
				$this->set_master_sku( $product_id, $master_sku );
			}
		}

		if ( $vendor_id <= 0 ) {
			$vendor_id = (int) get_post_meta( $base_id, self::META_VENDOR_ID, true );
		}

		if ( $vendor_id > 0 ) {
			update_post_meta( $base_id, self::META_VENDOR_ID, $vendor_id );
		}

		if ( ! $master_sku ) {
			$errors[] = 'missing_master_sku';
		}

		if ( ! $is_master && $master_product_id <= 0 ) {
			$errors[] = 'missing_master_product_id';
		}

		if ( $vendor_id <= 0 ) {
			$errors[] = 'missing_vendor_id';
		}

		return array(
			'valid'             => empty( $errors ),
			'errors'            => $errors,
			'product_id'        => $product_id,
			'master_product_id' => $master_product_id,
			'master_sku'        => $master_sku,
			'vendor_id'         => $vendor_id,
			'is_master'         => $is_master,
		);
	}

	/**
	 * Get master sku for a product or variation
	 *
	 * @param int $product_id Product id.
	 * @return string|null
	 */
	public function get_master_sku( int $product_id ): ?string {
		$sku = get_post_meta( $product_id, self::META_MASTER_SKU, true );
		if ( '' === $sku || null === $sku ) {
			return null;
		}

		return $this->normalize_master_sku( (string) $sku );
	}

	/**
	 * Get master sku for variation or its parent
	 *
	 * @param int $product_id Product id.
	 * @return string|null
	 */
	public function get_effective_master_sku( int $product_id ): ?string {
		$sku = $this->get_master_sku( $product_id );
		if ( $sku ) {
			return $sku;
		}

		$parent_id = wp_get_post_parent_id( $product_id );
		if ( $parent_id > 0 ) {
			return $this->get_master_sku( $parent_id );
		}

		return null;
	}

	/**
	 * Set master sku (immutable once saved)
	 *
	 * @param int    $product_id Product id.
	 * @param string $sku        Master sku.
	 * @return void
	 */
	public function set_master_sku( int $product_id, string $sku ): void {
		$sku = $this->normalize_master_sku( $sku );
		if ( '' === $sku ) {
			return;
		}

		$current = $this->get_master_sku( $product_id );
		if ( $current && $current !== $sku ) {
			return;
		}

		update_post_meta( $product_id, self::META_MASTER_SKU, $sku );
	}

	/**
	 * Normalize master sku
	 *
	 * @param string $sku SKU raw.
	 * @return string
	 */
	public function normalize_master_sku( string $sku ): string {
		return trim( $sku );
	}

	/**
	 * Get vendor id for a product or variation
	 *
	 * @param int $product_id Product id.
	 * @return int
	 */
	public function get_vendor_id_for_product( int $product_id ): int {
		$parent_id = wp_get_post_parent_id( $product_id );
		$base_id   = $parent_id > 0 ? $parent_id : $product_id;

		return (int) get_post_field( 'post_author', $base_id );
	}

	/**
	 * Find master product (and variation) by master sku
	 *
	 * @param string $master_sku Master sku.
	 * @return array{master_product_id: int, master_variation_id: int|null}|null
	 */
	public function find_master_product_by_master_sku( string $master_sku ): ?array {
		$master_sku = $this->normalize_master_sku( $master_sku );
		if ( '' === $master_sku ) {
			return null;
		}

		$sql = "
			SELECT p.ID, p.post_type, p.post_parent
			FROM {$this->wpdb->posts} p
			INNER JOIN {$this->wpdb->postmeta} pm
				ON p.ID = pm.post_id
			WHERE pm.meta_key = %s
			AND pm.meta_value = %s
			AND p.post_author = %d
			AND p.post_type IN ('product', 'product_variation')
			LIMIT 1
		";

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		$row = $this->wpdb->get_row(
			$this->wpdb->prepare( $sql, self::META_MASTER_SKU, $master_sku, $this->master_seller_id ),
			ARRAY_A
		);

		if ( ! $row ) {
			return null;
		}

		$post_id   = (int) $row['ID'];
		$post_type = (string) $row['post_type'];

		if ( 'product_variation' === $post_type ) {
			return array(
				'master_product_id'   => (int) $row['post_parent'],
				'master_variation_id' => $post_id,
			);
		}

		return array(
			'master_product_id'   => $post_id,
			'master_variation_id' => null,
		);
	}

	/**
	 * Get offers by master sku
	 *
	 * @param string $master_sku Master sku.
	 * @return array<int, array{product_id: int, vendor_id: int}>
	 */
	public function get_offers_by_master_sku( string $master_sku ): array {
		$master_sku = $this->normalize_master_sku( $master_sku );
		if ( '' === $master_sku ) {
			return array();
		}

		$sql = "
			SELECT p.ID, p.post_author
			FROM {$this->wpdb->posts} p
			INNER JOIN {$this->wpdb->postmeta} pm
				ON p.ID = pm.post_id
			WHERE pm.meta_key = %s
			AND pm.meta_value = %s
			AND p.post_author != %d
			AND p.post_status = 'publish'
			AND p.post_type IN ('product', 'product_variation')
		";

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		$rows = $this->wpdb->get_results(
			$this->wpdb->prepare( $sql, self::META_MASTER_SKU, $master_sku, $this->master_seller_id ),
			ARRAY_A
		);

		$offers = array();
		foreach ( $rows as $row ) {
			$offers[] = array(
				'product_id' => (int) $row['ID'],
				'vendor_id'  => (int) $row['post_author'],
			);
		}

		return $offers;
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

		$matcher = new \CDM\Core\VariationMatcher();
		$match_id = $matcher->find_matching_variation_by_attributes( $master_product_id, $attrs );
		if ( ! $match_id ) {
			return null;
		}

		$master_sku = $this->get_master_sku( $match_id );
		if ( ! $master_sku ) {
			$master_sku = get_post_meta( $match_id, '_sku', true );
		}

		return $master_sku ? $this->normalize_master_sku( (string) $master_sku ) : null;
	}

	/**
	 * Check if offer data is fresh
	 *
	 * @param int $product_id Product id.
	 * @return bool
	 */
	public function is_offer_data_fresh( int $product_id ): bool {
		$updated_at = $this->get_offer_updated_at( $product_id );
		if ( null === $updated_at ) {
			return false;
		}

		$stale_minutes = (int) get_option( 'cdm_offer_stale_minutes', 60 );
		$threshold     = max( 1, $stale_minutes ) * 60;

		return ( time() - $updated_at ) <= $threshold;
	}

	/**
	 * Get offer updated timestamp
	 *
	 * @param int $product_id Product id.
	 * @return int|null
	 */
	public function get_offer_updated_at( int $product_id ): ?int {
		$value = get_post_meta( $product_id, self::META_OFFER_UPDATED_AT, true );
		if ( '' === $value || null === $value ) {
			return null;
		}

		return (int) $value;
	}

	/**
	 * Get offer lead time in hours
	 *
	 * @param int $product_id Product id.
	 * @return int|null
	 */
	public function get_offer_lead_time_hours( int $product_id ): ?int {
		$value = get_post_meta( $product_id, self::META_OFFER_LEAD_TIME, true );
		if ( '' === $value || null === $value ) {
			return null;
		}

		return (int) $value;
	}
}
