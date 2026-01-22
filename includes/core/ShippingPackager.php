<?php
/**
 * Shipping Packager - Split packages by vendor
 *
 * @package CDM_Catalog_Router
 */

declare(strict_types=1);

namespace CDM\Core;

/**
 * Shipping packager
 */
final class ShippingPackager {

	/**
	 * Init hook
	 *
	 * @return void
	 */
	public function init(): void {
		add_filter( 'woocommerce_cart_shipping_packages', array( $this, 'split_packages_by_vendor' ), 20, 1 );
	}

	/**
	 * Split packages by vendor id
	 *
	 * @param array $packages Packages.
	 * @return array
	 */
	public function split_packages_by_vendor( array $packages ): array {
		if ( ! WC()->cart ) {
			return $packages;
		}

		$cart = WC()->cart->get_cart();
		if ( empty( $cart ) ) {
			return $packages;
		}

		$grouped = array();
		foreach ( $cart as $cart_item_key => $cart_item ) {
			$vendor_id = (int) ( $cart_item['cdm_seller_id'] ?? 0 );
			$key       = $vendor_id > 0 ? (string) $vendor_id : '0';

			if ( ! isset( $grouped[ $key ] ) ) {
				$grouped[ $key ] = array(
					'vendor_id' => $vendor_id,
					'items'     => array(),
					'cost'      => 0,
				);
			}

			$grouped[ $key ]['items'][ $cart_item_key ] = $cart_item;
			$grouped[ $key ]['cost'] += $cart_item['line_total'] ?? 0;
		}

		if ( count( $grouped ) <= 1 ) {
			return $packages;
		}

		$base_package = $packages[0] ?? array();
		$new_packages = array();

		foreach ( $grouped as $group ) {
			$package = $base_package;
			$package['contents']        = $group['items'];
			$package['contents_cost']   = $group['cost'];
			$package['cdm_vendor_id']   = $group['vendor_id'];
			$package['vendor_id']       = $group['vendor_id'];
			$package['cdm_is_split']    = true;
			$package['contents_weight'] = $this->calculate_weight( $group['items'] );
			$new_packages[]             = $package;
		}

		return $new_packages;
	}

	/**
	 * Calculate package weight
	 *
	 * @param array $items Cart items.
	 * @return float
	 */
	private function calculate_weight( array $items ): float {
		$total = 0.0;
		foreach ( $items as $item ) {
			if ( ! isset( $item['data'] ) || ! $item['data'] instanceof \WC_Product ) {
				continue;
			}

			$weight = (float) $item['data']->get_weight();
			$qty    = (int) ( $item['quantity'] ?? 1 );
			$total += $weight * $qty;
		}

		return $total;
	}
}
