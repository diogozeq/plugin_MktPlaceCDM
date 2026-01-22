<?php
/**
 * Order Meta - Attach routing metadata to order items
 *
 * @package CDM_Catalog_Router
 */

declare(strict_types=1);

namespace CDM\Core;

/**
 * Order meta handler
 */
final class OrderMeta {

	/**
	 * Init hooks
	 *
	 * @return void
	 */
	public function init(): void {
		add_action( 'woocommerce_checkout_create_order_line_item', array( $this, 'add_routing_meta' ), 10, 4 );
	}

	/**
	 * Add routing metadata to order item
	 *
	 * @param \WC_Order_Item_Product $item Order item.
	 * @param string                $cart_item_key Cart item key.
	 * @param array                 $values Cart item values.
	 * @param \WC_Order             $order Order.
	 * @return void
	 */
	public function add_routing_meta( \WC_Order_Item_Product $item, string $cart_item_key, array $values, \WC_Order $order ): void {
		if ( empty( $values['cdm_routed'] ) ) {
			return;
		}

		$keys = array(
			'cdm_master_id',
			'cdm_master_variation_id',
			'cdm_master_sku',
			'cdm_offer_product_id',
			'cdm_offer_parent_id',
			'cdm_seller_id',
			'cdm_cep_used',
			'cdm_routing_version',
		);

		foreach ( $keys as $key ) {
			if ( isset( $values[ $key ] ) ) {
				$item->add_meta_data( $key, $values[ $key ], true );
			}
		}
	}
}
