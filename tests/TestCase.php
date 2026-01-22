<?php
/**
 * Base test case for CDM Catalog Router
 */

declare(strict_types=1);

namespace CDM\Tests;

use WP_UnitTestCase;

abstract class TestCase extends WP_UnitTestCase {

	private $master_seller_filter;

	protected function setUp(): void {
		parent::setUp();

		$this->ensure_wc();
		$this->prepare_dokan_table();
		$this->reset_cart();
		$this->master_seller_filter = static fn() => 2;
		add_filter( 'cdm_master_seller_id', $this->master_seller_filter );
	}

	protected function tearDown(): void {
		$this->reset_cart();
		if ( $this->master_seller_filter ) {
			remove_filter( 'cdm_master_seller_id', $this->master_seller_filter );
		}
		parent::tearDown();
	}

	protected function ensure_wc(): void {
		if ( ! class_exists( 'WooCommerce' ) ) {
			$this->markTestSkipped( 'WooCommerce not loaded.' );
		}

		if ( ! WC()->session ) {
			WC()->session = new \WC_Session_Handler();
			WC()->session->init();
		}

		if ( ! WC()->customer ) {
			WC()->customer = new \WC_Customer( 0, true );
		}

		if ( ! WC()->cart ) {
			WC()->cart = new \WC_Cart();
		}
	}

	protected function reset_cart(): void {
		if ( function_exists( 'wc_clear_notices' ) ) {
			wc_clear_notices();
		}

		if ( WC()->cart ) {
			WC()->cart->empty_cart();
		}
	}

	protected function prepare_dokan_table(): void {
		global $wpdb;
		$table   = $wpdb->prefix . 'dokan_product_map';
		$charset = $wpdb->get_charset_collate();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			"CREATE TABLE IF NOT EXISTS {$table} (
				map_id bigint(20) unsigned NOT NULL,
				product_id bigint(20) unsigned NOT NULL,
				seller_id bigint(20) unsigned NOT NULL,
				is_trash tinyint(1) unsigned NOT NULL DEFAULT 0,
				KEY map_id (map_id),
				KEY product_id (product_id),
				KEY seller_id (seller_id)
			) {$charset}"
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( "TRUNCATE TABLE {$table}" );
	}

	protected function map_product( int $map_id, int $product_id, int $seller_id, int $is_trash = 0 ): void {
		global $wpdb;
		$table = $wpdb->prefix . 'dokan_product_map';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->insert(
			$table,
			array(
				'map_id'     => $map_id,
				'product_id' => $product_id,
				'seller_id'  => $seller_id,
				'is_trash'   => $is_trash,
			),
			array( '%d', '%d', '%d', '%d' )
		);
	}

	protected function create_user( int $user_id, string $role = 'administrator' ): int {
		$user = get_user_by( 'id', $user_id );
		if ( $user ) {
			return $user_id;
		}

		return (int) wp_insert_user(
			array(
				'ID'         => $user_id,
				'user_login' => 'user_' . $user_id,
				'user_pass'  => 'password',
				'user_email' => "user_{$user_id}@example.test",
				'role'       => $role,
			)
		);
	}

	protected function create_simple_product( int $author_id, string $sku, int $stock_qty ): int {
		$product = new \WC_Product_Simple();
		$product->set_name( 'Simple ' . $sku );
		$product->set_status( 'publish' );
		$product->set_catalog_visibility( 'visible' );
		$product->set_regular_price( '10' );
		$product->set_manage_stock( true );
		$product->set_stock_quantity( $stock_qty );
		$product->set_sku( $sku );
		$product_id = $product->save();

		wp_update_post(
			array(
				'ID'          => $product_id,
				'post_author' => $author_id,
			)
		);

		return $product_id;
	}

	protected function create_variable_product(
		int $author_id,
		array $attributes,
		array $variation_attrs,
		string $sku,
		string $master_sku,
		int $stock_qty,
		bool $set_master_sku = true
	): array {
		$wc_attributes = array();

		foreach ( $attributes as $name => $options ) {
			$attr = new \WC_Product_Attribute();
			$attr->set_name( $name );
			$attr->set_options( $options );
			$attr->set_visible( true );
			$attr->set_variation( true );
			$attr->set_position( 0 );
			$wc_attributes[] = $attr;
		}

		$variable = new \WC_Product_Variable();
		$variable->set_name( 'Variable ' . $sku );
		$variable->set_status( 'publish' );
		$variable->set_catalog_visibility( 'visible' );
		$variable->set_attributes( $wc_attributes );
		$product_id = $variable->save();

		wp_update_post(
			array(
				'ID'          => $product_id,
				'post_author' => $author_id,
			)
		);

		$variation = new \WC_Product_Variation();
		$variation->set_parent_id( $product_id );
		$variation->set_regular_price( '12' );
		$variation->set_manage_stock( true );
		$variation->set_stock_quantity( $stock_qty );
		$variation->set_sku( $sku );
		$variation_id = $variation->save();

		wp_update_post(
			array(
				'ID'          => $variation_id,
				'post_author' => $author_id,
			)
		);

		foreach ( $this->prefix_attributes( $variation_attrs ) as $key => $value ) {
			update_post_meta( $variation_id, $key, $value );
		}

		if ( $set_master_sku ) {
			update_post_meta( $variation_id, 'cdm_master_sku', $master_sku );
		}

		return array(
			'product_id'   => $product_id,
			'variation_id' => $variation_id,
		);
	}

	protected function prefix_attributes( array $attrs ): array {
		$prefixed = array();
		foreach ( $attrs as $key => $value ) {
			$meta_key = str_starts_with( $key, 'attribute_' ) ? $key : 'attribute_' . $key;
			$prefixed[ $meta_key ] = $value;
		}

		return $prefixed;
	}
}
