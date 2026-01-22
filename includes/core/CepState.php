<?php
/**
 * CEP State - Global CEP source of truth
 *
 * @package CDM_Catalog_Router
 */

declare(strict_types=1);

namespace CDM\Core;

/**
 * CEP global state manager
 */
final class CepState {

	private const SESSION_KEY = 'cdm_routing_cep';
	private const COOKIE_KEY  = 'cdm_routing_cep';
	private const USER_KEY    = 'cdm_routing_cep';
	private const CEP_LENGTH  = 8;

	/**
	 * Init hooks
	 *
	 * @return void
	 */
	public function init(): void {
		add_action( 'woocommerce_cart_updated', array( $this, 'capture_cart_cep' ) );
		add_action( 'woocommerce_checkout_process', array( $this, 'capture_checkout_cep' ), 0 );
		add_action( 'woocommerce_checkout_update_order_review', array( $this, 'capture_checkout_review_cep' ), 0, 1 );
	}

	/**
	 * Get active CEP using priority
	 *
	 * @return string|null
	 */
	public function get_active_cep(): ?string {
		$posted = $this->get_checkout_posted_cep();
		if ( $posted ) {
			return $this->set_active_cep( $posted, 'checkout_posted' );
		}

		$session = $this->get_session_cep();
		if ( $session ) {
			return $session;
		}

		$cookie = $this->get_cookie_cep();
		if ( $cookie ) {
			return $cookie;
		}

		$user = $this->get_user_cep();
		if ( $user ) {
			return $user;
		}

		return null;
	}

	/**
	 * Set active CEP and propagate to session/cookie/user
	 *
	 * @param string $cep    CEP input.
	 * @param string $source Source tag.
	 * @return string|null
	 */
	public function set_active_cep( string $cep, string $source = '' ): ?string {
		$cep = $this->normalize_cep( $cep );
		if ( ! $this->is_valid_cep( $cep ) ) {
			return null;
		}

		$prev = $this->get_session_cep() ?? $this->get_cookie_cep() ?? $this->get_user_cep();

		if ( WC()->session ) {
			WC()->session->set( self::SESSION_KEY, $cep );
		}

		$this->set_cookie( $cep );

		if ( is_user_logged_in() ) {
			update_user_meta( get_current_user_id(), self::USER_KEY, $cep );
		}

		if ( $prev !== $cep ) {
			do_action( 'cdm_cep_changed', $prev, $cep, $source );
		}

		return $cep;
	}

	/**
	 * Capture CEP from cart shipping calculator
	 *
	 * @return void
	 */
	public function capture_cart_cep(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( ! isset( $_POST['calc_shipping_postcode'] ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$cep = sanitize_text_field( wp_unslash( $_POST['calc_shipping_postcode'] ) );
		$this->set_active_cep( $cep, 'cart_posted' );
	}

	/**
	 * Capture CEP from checkout process
	 *
	 * @return void
	 */
	public function capture_checkout_cep(): void {
		$cep = $this->get_checkout_posted_cep();
		if ( $cep ) {
			$this->set_active_cep( $cep, 'checkout_posted' );
		}
	}

	/**
	 * Capture CEP from checkout update order review
	 *
	 * @param string $post_data Raw post data string.
	 * @return void
	 */
	public function capture_checkout_review_cep( string $post_data ): void {
		if ( '' === $post_data ) {
			return;
		}

		parse_str( $post_data, $data );

		$cep = '';
		if ( isset( $data['shipping_postcode'] ) ) {
			$cep = sanitize_text_field( wp_unslash( $data['shipping_postcode'] ) );
		} elseif ( isset( $data['billing_postcode'] ) ) {
			$cep = sanitize_text_field( wp_unslash( $data['billing_postcode'] ) );
		}

		if ( $cep ) {
			$this->set_active_cep( $cep, 'checkout_review' );
		}
	}

	/**
	 * Normalize CEP (digits only)
	 *
	 * @param string $cep CEP input.
	 * @return string
	 */
	public function normalize_cep( string $cep ): string {
		return (string) preg_replace( '/\D/', '', $cep );
	}

	/**
	 * Validate CEP format
	 *
	 * @param string $cep CEP input.
	 * @return bool
	 */
	public function is_valid_cep( string $cep ): bool {
		return strlen( $cep ) === self::CEP_LENGTH;
	}

	/**
	 * Get CEP from session
	 *
	 * @return string|null
	 */
	private function get_session_cep(): ?string {
		if ( WC()->session ) {
			$cep = WC()->session->get( self::SESSION_KEY );
			if ( $cep && $this->is_valid_cep( $cep ) ) {
				return (string) $cep;
			}
		}

		return null;
	}

	/**
	 * Get CEP from cookie
	 *
	 * @return string|null
	 */
	private function get_cookie_cep(): ?string {
		if ( ! isset( $_COOKIE[ self::COOKIE_KEY ] ) ) {
			return null;
		}

		$cep = sanitize_text_field( wp_unslash( $_COOKIE[ self::COOKIE_KEY ] ) );
		$cep = $this->normalize_cep( $cep );

		return $this->is_valid_cep( $cep ) ? $cep : null;
	}

	/**
	 * Get CEP from user meta
	 *
	 * @return string|null
	 */
	private function get_user_cep(): ?string {
		if ( ! is_user_logged_in() ) {
			return null;
		}

		$cep = get_user_meta( get_current_user_id(), self::USER_KEY, true );
		if ( ! $cep ) {
			return null;
		}

		$cep = $this->normalize_cep( (string) $cep );
		return $this->is_valid_cep( $cep ) ? $cep : null;
	}

	/**
	 * Get CEP posted during checkout
	 *
	 * @return string|null
	 */
	private function get_checkout_posted_cep(): ?string {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( isset( $_POST['shipping_postcode'] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing
			$cep = sanitize_text_field( wp_unslash( $_POST['shipping_postcode'] ) );
			$cep = $this->normalize_cep( $cep );
			return $this->is_valid_cep( $cep ) ? $cep : null;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( isset( $_POST['billing_postcode'] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing
			$cep = sanitize_text_field( wp_unslash( $_POST['billing_postcode'] ) );
			$cep = $this->normalize_cep( $cep );
			return $this->is_valid_cep( $cep ) ? $cep : null;
		}

		return null;
	}

	/**
	 * Set CEP cookie
	 *
	 * @param string $cep CEP.
	 * @return void
	 */
	private function set_cookie( string $cep ): void {
		setcookie(
			self::COOKIE_KEY,
			$cep,
			time() + DAY_IN_SECONDS,
			COOKIEPATH,
			COOKIE_DOMAIN,
			is_ssl(),
			true
		);
	}
}
