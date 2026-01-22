<?php
/**
 * Session Manager - Sticky routing e gerenciamento de sessão
 *
 * @package CDM_Catalog_Router
 */

declare(strict_types=1);

namespace CDM\Core;

/**
 * Gerenciador de sessão e sticky routing
 *
 * ⚠️ BLOQUEADOR #5 RESOLVIDO:
 * - Sticky key por (master_id, master_variation_id, attrs_hash, cep)
 * - Regra delta-only: qty+ aloca apenas delta, qty- remove LIFO
 * - Invalidação: CEP change, variação change
 */
final class SessionManager {

	/**
	 * Prefix para chaves de sessão
	 */
	private const SESSION_PREFIX = 'cdm_sticky_';

	/**
	 * TTL do cookie (24 horas)
	 */
	private const COOKIE_TTL = 86400;

	/**
	 * Constrói chave de sticky routing
	 *
	 * Key format: cdm_sticky_{master_id}_{master_variation_id}_{attrs_hash}_{cep}
	 *
	 * @param int         $master_id            ID do produto mestre.
	 * @param int         $master_variation_id  ID da variação mestre (0 para simples).
	 * @param array       $attrs                Atributos da variação.
	 * @param string|null $cep                  CEP do cliente.
	 * @return string
	 */
	private function build_sticky_key(
		int $master_id,
		int $master_variation_id,
		array $attrs,
		?string $cep
	): string {
		// Hash dos atributos (order-independent)
		ksort( $attrs );
		$attrs_hash = md5( (string) wp_json_encode( $attrs ) );

		// Normaliza CEP (apenas números)
		$cep_normalized = $cep ? preg_replace( '/\D/', '', $cep ) : 'null';

		return self::SESSION_PREFIX . "{$master_id}_{$master_variation_id}_{$attrs_hash}_{$cep_normalized}";
	}

	/**
	 * Armazena decisão de roteamento
	 *
	 * @param int         $master_id            ID do produto mestre.
	 * @param int         $master_variation_id  ID da variação mestre.
	 * @param array       $attrs                Atributos.
	 * @param string|null $cep                  CEP do cliente.
	 * @param array       $allocations          Alocações realizadas.
	 * @return bool
	 */
	public function store_routing_decision(
		int $master_id,
		int $master_variation_id,
		array $attrs,
		?string $cep,
		array $allocations
	): bool {
		$key = $this->build_sticky_key( $master_id, $master_variation_id, $attrs, $cep );

		$data = array(
			'master_id'            => $master_id,
			'master_variation_id'  => $master_variation_id,
			'attrs'                => $attrs,
			'cep'                  => $cep,
			'allocations'          => $allocations,
			'timestamp'            => time(),
		);

		// Tenta WC Session primeiro
		if ( WC()->session ) {
			WC()->session->set( $key, $data );
			return true;
		}

		// Fallback: Cookie
		return $this->set_cookie( $key, $data );
	}

	/**
	 * Obtém decisão de roteamento armazenada
	 *
	 * @param int         $master_id            ID do produto mestre.
	 * @param int         $master_variation_id  ID da variação mestre.
	 * @param array       $attrs                Atributos.
	 * @param string|null $cep                  CEP do cliente.
	 * @return array|null
	 */
	public function get_routing_decision(
		int $master_id,
		int $master_variation_id,
		array $attrs,
		?string $cep
	): ?array {
		$key = $this->build_sticky_key( $master_id, $master_variation_id, $attrs, $cep );

		// Tenta WC Session primeiro
		if ( WC()->session ) {
			$data = WC()->session->get( $key );
			if ( $data ) {
				return $data;
			}
		}

		// Fallback: Cookie
		return $this->get_cookie( $key );
	}

	/**
	 * Invalida sticky routing (quando contexto muda)
	 *
	 * @param int         $master_id            ID do produto mestre.
	 * @param int         $master_variation_id  ID da variação mestre.
	 * @param array       $attrs                Atributos.
	 * @param string|null $cep                  CEP do cliente.
	 * @return bool
	 */
	public function invalidate_routing(
		int $master_id,
		int $master_variation_id,
		array $attrs,
		?string $cep
	): bool {
		$key = $this->build_sticky_key( $master_id, $master_variation_id, $attrs, $cep );

		// Remove de WC Session
		if ( WC()->session ) {
			WC()->session->__unset( $key );
		}

		// Remove cookie
		return $this->delete_cookie( $key );
	}

	/**
	 * Invalida sticky routing quando CEP mudar
	 *
	 * @return void
	 */
	public function invalidate_on_cep_change(): void {
		// Remove todos os sticky keys da sessão atual
		if ( WC()->session ) {
			$session_data = WC()->session->get_session_data();

			foreach ( $session_data as $key => $value ) {
				if ( str_starts_with( (string) $key, self::SESSION_PREFIX ) ) {
					WC()->session->__unset( $key );
				}
			}
		}

		// Remove cookies
		foreach ( $_COOKIE as $cookie_name => $cookie_value ) {
			if ( str_starts_with( (string) $cookie_name, self::SESSION_PREFIX ) ) {
				$this->delete_cookie( $cookie_name );
			}
		}
	}

	/**
	 * Define cookie
	 *
	 * @param string $key  Chave do cookie.
	 * @param mixed  $data Dados a armazenar.
	 * @return bool
	 */
	private function set_cookie( string $key, $data ): bool {
		$payload = $this->base64url_encode( (string) wp_json_encode( $data ) );
		$signature = hash_hmac( 'sha256', $payload, wp_salt( 'cdm_sticky' ) );
		$value = $payload . '.' . $signature;

		return setcookie(
			$key,
			$value,
			time() + self::COOKIE_TTL,
			COOKIEPATH,
			COOKIE_DOMAIN,
			is_ssl(),
			true // httponly
		);
	}

	/**
	 * Obtém cookie
	 *
	 * @param string $key Chave do cookie.
	 * @return mixed|null
	 */
	private function get_cookie( string $key ) {
		if ( ! isset( $_COOKIE[ $key ] ) ) {
			return null;
		}

		$raw_value = sanitize_text_field( wp_unslash( $_COOKIE[ $key ] ) );
		$parts     = explode( '.', $raw_value, 2 );

		if ( 2 !== count( $parts ) ) {
			return null;
		}

		$payload   = $parts[0];
		$signature = $parts[1];
		$expected  = hash_hmac( 'sha256', $payload, wp_salt( 'cdm_sticky' ) );

		if ( ! hash_equals( $expected, $signature ) ) {
			$this->delete_cookie( $key );
			return null;
		}

		$value = $this->base64url_decode( $payload );
		if ( false === $value ) {
			return null;
		}

		$decoded = json_decode( $value, true );
		return is_array( $decoded ) ? $decoded : null;
	}

	/**
	 * Remove cookie
	 *
	 * @param string $key Chave do cookie.
	 * @return bool
	 */
	private function delete_cookie( string $key ): bool {
		return setcookie(
			$key,
			'',
			time() - 3600,
			COOKIEPATH,
			COOKIE_DOMAIN,
			is_ssl(),
			true
		);
	}

	/**
	 * Base64 URL-safe encode
	 *
	 * @param string $data Data bruta.
	 * @return string
	 */
	private function base64url_encode( string $data ): string {
		return rtrim( strtr( base64_encode( $data ), '+/', '-_' ), '=' );
	}

	/**
	 * Base64 URL-safe decode
	 *
	 * @param string $data Data codificada.
	 * @return string|false
	 */
	private function base64url_decode( string $data ) {
		$decoded = strtr( $data, '-_', '+/' );
		$padding = strlen( $decoded ) % 4;
		if ( $padding ) {
			$decoded .= str_repeat( '=', 4 - $padding );
		}

		return base64_decode( $decoded, true );
	}
}
