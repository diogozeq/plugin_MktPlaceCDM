<?php
/**
 * Offer Backfill - Fill missing offer metadata
 *
 * @package CDM_Catalog_Router
 */

declare(strict_types=1);

namespace CDM\Core;

use CDM\Repositories\OfferRepository;
use CDM\Repositories\ProductRepository;

/**
 * Offer backfill job
 */
final class OfferBackfill {

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
	 * @param OfferRepository   $offer_repo Offer repository.
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
		add_action( 'cdm_backfill_offer_meta', array( $this, 'run' ) );
	}

	/**
	 * Run backfill in batches
	 *
	 * @return void
	 */
	public function run(): void {
		global $wpdb;

		$limit  = 200;
		$offset = (int) get_option( 'cdm_backfill_offset', 0 );

		$sql = "
			SELECT product_id, seller_id
			FROM {$wpdb->prefix}dokan_product_map
			WHERE is_trash = 0
			ORDER BY product_id ASC
			LIMIT %d OFFSET %d
		";

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare( $sql, $limit, $offset ),
			ARRAY_A
		);

		if ( empty( $rows ) ) {
			delete_option( 'cdm_backfill_offset' );
			return;
		}

		foreach ( $rows as $row ) {
			$product_id = (int) $row['product_id'];
			$seller_id  = (int) $row['seller_id'];

			$this->offer_repo->resolve_offer_context( $product_id );

			$product = wc_get_product( $product_id );
			if ( $product && $product->is_type( 'variable' ) ) {
				foreach ( $product->get_children() as $child_id ) {
					$this->offer_repo->resolve_offer_context( (int) $child_id );
				}
			}

			if ( $seller_id === $this->master_seller_id ) {
				$this->offer_repo->resolve_offer_context( $product_id );
			}
		}

		$next_offset = $offset + $limit;
		update_option( 'cdm_backfill_offset', $next_offset );

		if ( ! wp_next_scheduled( 'cdm_backfill_offer_meta' ) ) {
			wp_schedule_single_event( time() + 60, 'cdm_backfill_offer_meta' );
		}
	}
}
