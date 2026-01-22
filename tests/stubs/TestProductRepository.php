<?php
/**
 * Test product repository stub
 */

declare(strict_types=1);

namespace CDM\Tests\Stubs;

use CDM\Repositories\ProductRepository;

final class TestProductRepository extends ProductRepository {

	public function is_master_product( int $product_id ): bool {
		$master_seller_id = (int) apply_filters( 'cdm_master_seller_id', 2 );
		$author           = (int) get_post_field( 'post_author', $product_id );

		return $author === $master_seller_id;
	}

	public function get_map_id( int $product_id ): ?int {
		$table = $this->wpdb->prefix . 'dokan_product_map';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		$map_id = $this->wpdb->get_var(
			$this->wpdb->prepare(
				"SELECT map_id FROM {$table} WHERE product_id = %d LIMIT 1",
				$product_id
			)
		);

		return $map_id ? (int) $map_id : null;
	}

	public function get_active_clones( int $map_id ): array {
		$table            = $this->wpdb->prefix . 'dokan_product_map';
		$master_seller_id = (int) apply_filters( 'cdm_master_seller_id', 2 );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		$rows = $this->wpdb->get_results(
			$this->wpdb->prepare(
				"SELECT product_id AS clone_id, seller_id
				FROM {$table}
				WHERE map_id = %d
				AND seller_id != %d
				AND is_trash = 0",
				$map_id,
				$master_seller_id
			),
			ARRAY_A
		);

		$clones = array();
		foreach ( $rows as $row ) {
			$clones[] = array(
				'clone_id'  => (int) $row['clone_id'],
				'seller_id' => (int) $row['seller_id'],
			);
		}

		return $clones;
	}

	public function get_master_from_clone( int $clone_id ): ?int {
		$table            = $this->wpdb->prefix . 'dokan_product_map';
		$master_seller_id = (int) apply_filters( 'cdm_master_seller_id', 2 );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		$map_id = $this->wpdb->get_var(
			$this->wpdb->prepare(
				"SELECT map_id FROM {$table} WHERE product_id = %d LIMIT 1",
				$clone_id
			)
		);

		if ( ! $map_id ) {
			return null;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		$master_id = $this->wpdb->get_var(
			$this->wpdb->prepare(
				"SELECT product_id FROM {$table}
				WHERE map_id = %d
				AND seller_id = %d
				LIMIT 1",
				$map_id,
				$master_seller_id
			)
		);

		return $master_id ? (int) $master_id : null;
	}
}
