<?php
/**
 * Vendor Repository - Queries relacionadas a vendedores
 *
 * @package CDM_Catalog_Router
 */

declare(strict_types=1);

namespace CDM\Repositories;

/**
 * Repositório de vendedores (Dokan)
 *
 * Gerencia queries de status, fairness (last_order_time), e zonas CEP.
 */
final class VendorRepository extends BaseRepository {

	/**
	 * Verifica se vendedor está ativo (pode vender)
	 *
	 * @param int $seller_id ID do vendedor (user_id).
	 * @return bool
	 */
	public function is_vendor_active( int $seller_id ): bool {
		$cache_key = "cdm_vendor_active_{$seller_id}";

		return (bool) $this->cache_manager->get_or_set(
			$cache_key,
			function () use ( $seller_id ) {
				$enable_selling = get_user_meta( $seller_id, 'dokan_enable_selling', true );
				return 'yes' === $enable_selling;
			},
			600 // 10 minutos
		);
	}

	/**
	 * Obtém timestamp da última venda completa do vendedor
	 *
	 * ⚠️ CRÍTICO para Global Fairness Allocator (bloqueador #2 resolvido)
	 *
	 * @param int $seller_id ID do vendedor.
	 * @return int|null Timestamp ou null se nunca vendeu.
	 */
	public function get_vendor_last_order_time( int $seller_id ): ?int {
		$cache_key = "cdm_vendor_last_order_{$seller_id}";

		$last_order_time = $this->cache_manager->get_or_set(
			$cache_key,
			function () use ( $seller_id ) {
				// Query: busca última ordem com status 'completed' do vendedor
				// Usa HPOS-compatible approach
				$sql = "
					SELECT MAX(o.date_created_gmt) as last_order_time
					FROM {$this->wpdb->prefix}wc_orders o
					INNER JOIN {$this->wpdb->prefix}wc_order_product_lookup opl
						ON o.id = opl.order_id
					INNER JOIN {$this->wpdb->prefix}wc_product_meta_lookup pml
						ON opl.product_id = pml.product_id
					WHERE pml.stock_quantity IS NOT NULL
					AND o.status = 'wc-completed'
					AND EXISTS (
						SELECT 1
						FROM {$this->wpdb->prefix}dokan_product_map dpm
						WHERE dpm.product_id = opl.product_id
						AND dpm.seller_id = %d
					)
					LIMIT 1
				";

				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
				$result = $this->wpdb->get_var(
					$this->wpdb->prepare( $sql, $seller_id )
				);

				if ( ! $result ) {
					return null;
				}

				// Converte para timestamp
				return strtotime( $result );
			},
			300 // 5 minutos (atualiza frequentemente para fairness preciso)
		);

		return $last_order_time;
	}

	/**
	 * Obtém zonas de CEP do vendedor
	 *
	 * Integração com zonas de entrega do Dokan (quando disponível)
	 *
	 * @param int $seller_id ID do vendedor.
	 * @return array<string>
	 */
	public function get_vendor_cep_zones( int $seller_id ): array {
		$cache_key = "cdm_vendor_cep_zones_{$seller_id}";

		return $this->cache_manager->get_or_set(
			$cache_key,
			function () use ( $seller_id ) {
				$zones = apply_filters( 'cdm_vendor_cep_zones', null, $seller_id );
				if ( is_array( $zones ) && ! empty( $zones ) ) {
					return $this->normalize_zone_list( $zones );
				}

				$zones = $this->get_dokan_zone_postcodes( $seller_id );
				if ( ! empty( $zones ) ) {
					return $this->normalize_zone_list( $zones );
				}

				return array();
			},
			3600 // 1 hora
		);
	}

	/**
	 * Verifica se vendedor atende CEP
	 *
	 * @param int    $seller_id ID do vendedor.
	 * @param string $cep       CEP do cliente (apenas números).
	 * @return bool
	 */
	public function vendor_serves_cep( int $seller_id, string $cep ): bool {
		$cep_normalized = $this->normalize_cep( $cep );
		if ( '' === $cep_normalized ) {
			return false;
		}

		$zones = $this->get_vendor_cep_zones( $seller_id );
		if ( empty( $zones ) ) {
			return false;
		}

		foreach ( $zones as $zone ) {
			if ( $this->cep_matches_pattern( $cep_normalized, $zone ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Atualiza timestamp da última venda (chamado após order completed)
	 *
	 * @param int $seller_id  ID do vendedor.
	 * @param int $order_time Timestamp da ordem.
	 * @return void
	 */
	public function update_last_order_time( int $seller_id, int $order_time ): void {
		$cache_key = "cdm_vendor_last_order_{$seller_id}";

		// Invalida cache para forçar recalculo
		$this->cache_manager->delete( $cache_key );

		$this->log_debug( 'Vendor last order time invalidated', array(
			'seller_id'  => $seller_id,
			'order_time' => $order_time,
		) );
	}

	/**
	 * Invalida cache de vendedor
	 *
	 * @param int $seller_id ID do vendedor.
	 * @return void
	 */
	public function invalidate_vendor_cache( int $seller_id ): void {
		$this->cache_manager->delete( "cdm_vendor_active_{$seller_id}" );
		$this->cache_manager->delete( "cdm_vendor_last_order_{$seller_id}" );
		$this->cache_manager->delete( "cdm_vendor_cep_zones_{$seller_id}" );
	}

	/**
	 * Normaliza CEP (mantém apenas números)
	 *
	 * @param string $cep CEP bruto.
	 * @return string
	 */
	private function normalize_cep( string $cep ): string {
		return (string) preg_replace( '/\D/', '', $cep );
	}

	/**
	 * Normaliza lista de zonas para matching
	 *
	 * @param array<int, mixed> $zones Lista bruta de zonas.
	 * @return array<string>
	 */
	private function normalize_zone_list( array $zones ): array {
		$normalized = array();

		foreach ( $zones as $zone ) {
			if ( ! is_string( $zone ) ) {
				continue;
			}

			$parts = preg_split( '/[\r\n,]+/', $zone );
			if ( ! $parts ) {
				continue;
			}

			foreach ( $parts as $part ) {
				$part = trim( $part );
				if ( '' !== $part ) {
					$normalized[] = $part;
				}
			}
		}

		return array_values( array_unique( $normalized ) );
	}

	/**
	 * Verifica se CEP combina com o padrão
	 *
	 * Suporta:
	 * - Exato: 01001000
	 * - Prefixo: 01001
	 * - Range: 01001000...01009999
	 * - Wildcards: 0100* ou 0100??
	 *
	 * @param string $cep_normalized CEP apenas números.
	 * @param string $pattern        Padrão da zona.
	 * @return bool
	 */
	private function cep_matches_pattern( string $cep_normalized, string $pattern ): bool {
		$pattern = trim( $pattern );
		if ( '' === $pattern ) {
			return false;
		}

		if ( str_contains( $pattern, '...' ) ) {
			$parts = explode( '...', $pattern, 2 );
			$start = $this->normalize_cep( $parts[0] ?? '' );
			$end   = $this->normalize_cep( $parts[1] ?? '' );

			if ( '' === $start || '' === $end ) {
				return false;
			}

			$cep_len = strlen( $cep_normalized );
			$start   = str_pad( $start, $cep_len, '0' );
			$end     = str_pad( $end, $cep_len, '9' );

			return $cep_normalized >= $start && $cep_normalized <= $end;
		}

		if ( str_contains( $pattern, '*' ) || str_contains( $pattern, '?' ) ) {
			$clean = preg_replace( '/[^0-9\*\?]/', '', $pattern );
			$regex = '/^' . str_replace(
				array( '\*', '\?' ),
				array( '\d*', '\d' ),
				preg_quote( (string) $clean, '/' )
			) . '$/';

			return (bool) preg_match( $regex, $cep_normalized );
		}

		$pattern_digits = $this->normalize_cep( $pattern );
		if ( '' === $pattern_digits ) {
			return false;
		}

		if ( strlen( $pattern_digits ) < strlen( $cep_normalized ) ) {
			return str_starts_with( $cep_normalized, $pattern_digits );
		}

		return $cep_normalized === $pattern_digits;
	}

	/**
	 * Obtém postcodes das zonas do Dokan (quando tabelas existem)
	 *
	 * @param int $seller_id ID do vendedor.
	 * @return array<string>
	 */
	private function get_dokan_zone_postcodes( int $seller_id ): array {
		$zones_table     = $this->wpdb->prefix . 'dokan_shipping_zone';
		$locations_table = $this->wpdb->prefix . 'dokan_shipping_zone_locations';

		if ( ! $this->table_has_columns( $zones_table, array( 'id', 'vendor_id' ) ) ) {
			return array();
		}

		if ( ! $this->table_has_columns( $locations_table, array( 'zone_id', 'location_code', 'location_type' ) ) ) {
			return array();
		}

		$sql = "
			SELECT l.location_code
			FROM {$locations_table} l
			INNER JOIN {$zones_table} z ON l.zone_id = z.id
			WHERE z.vendor_id = %d
			AND l.location_type IN ('postcode', 'postcodes', 'zip', 'zipcode')
		";

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		$results = $this->wpdb->get_col(
			$this->wpdb->prepare( $sql, $seller_id )
		);

		return is_array( $results ) ? $results : array();
	}

	/**
	 * Verifica se tabela existe e contém colunas necessárias
	 *
	 * @param string        $table   Nome da tabela.
	 * @param array<string> $columns Colunas obrigatórias.
	 * @return bool
	 */
	private function table_has_columns( string $table, array $columns ): bool {
		if ( empty( $columns ) ) {
			return false;
		}

		$cache_key = 'cdm_table_columns_' . md5( $table );

		$existing = $this->cache_manager->get_or_set(
			$cache_key,
			function () use ( $table ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$table_like = $this->wpdb->esc_like( $table );
				$table_exists = $this->wpdb->get_var(
					$this->wpdb->prepare( 'SHOW TABLES LIKE %s', $table_like )
				);

				if ( ! $table_exists ) {
					return array();
				}

				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$cols = $this->wpdb->get_col( "SHOW COLUMNS FROM {$table}", 0 );

				return is_array( $cols ) ? $cols : array();
			},
			3600
		);

		if ( empty( $existing ) ) {
			return false;
		}

		foreach ( $columns as $column ) {
			if ( ! in_array( $column, $existing, true ) ) {
				return false;
			}
		}

		return true;
	}
}
