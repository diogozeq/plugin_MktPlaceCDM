<?php
/**
 * Vendor CEP storage helper
 *
 * @package CDM_Catalog_Router
 */

declare(strict_types=1);

namespace CDM;

/**
 * Handles vendor CEP zones stored in user meta.
 */
final class VendorCepStorage {

	/**
	 * User meta key for vendor CEP zones.
	 *
	 * @var string
	 */
	public const META_KEY = 'cdm_vendor_cep_zones';

	/**
	 * Filter to supply CEP zones from user meta when available.
	 *
	 * @param mixed $zones Existing zones from other sources.
	 * @param int   $seller_id Vendor user ID.
	 * @return array<string>|mixed
	 */
	public static function filter_vendor_cep_zones( $zones, int $seller_id ) {
		if ( is_array( $zones ) && ! empty( $zones ) ) {
			return $zones;
		}

		$raw = get_user_meta( $seller_id, self::META_KEY, true );
		if ( ! is_string( $raw ) || '' === trim( $raw ) ) {
			return $zones;
		}

		$parsed = self::parse_zones_from_text( $raw );
		return empty( $parsed ) ? $zones : $parsed;
	}

	/**
	 * Get sanitized zones text for a vendor.
	 *
	 * @param int $seller_id Vendor user ID.
	 * @return string
	 */
	public static function get_vendor_zones_text( int $seller_id ): string {
		$raw = get_user_meta( $seller_id, self::META_KEY, true );
		if ( ! is_string( $raw ) ) {
			return '';
		}

		return self::sanitize_zone_text( $raw );
	}

	/**
	 * Sanitize CEP zones text for storage.
	 *
	 * @param string $text Raw input.
	 * @return string
	 */
	public static function sanitize_zone_text( string $text ): string {
		$zones = self::parse_zones_from_text( $text );
		if ( empty( $zones ) ) {
			return '';
		}

		return implode( "\n", $zones );
	}

	/**
	 * Parse and normalize CEP zones from textarea input.
	 *
	 * @param string $text Raw input.
	 * @return array<string>
	 */
	public static function parse_zones_from_text( string $text ): array {
		$parts = preg_split( '/[\r\n,]+/', $text );
		if ( ! is_array( $parts ) ) {
			return array();
		}

		$zones = array();
		foreach ( $parts as $part ) {
			$clean = self::sanitize_zone_value( (string) $part );
			if ( '' !== $clean ) {
				$zones[] = $clean;
			}
		}

		return array_values( array_unique( $zones ) );
	}

	/**
	 * Sanitize a single zone value.
	 *
	 * @param string $zone Zone input.
	 * @return string
	 */
	private static function sanitize_zone_value( string $zone ): string {
		$zone = trim( $zone );
		if ( '' === $zone ) {
			return '';
		}

		$zone = preg_replace( '/[^0-9\*\?\.]/', '', $zone );
		return (string) $zone;
	}
}
