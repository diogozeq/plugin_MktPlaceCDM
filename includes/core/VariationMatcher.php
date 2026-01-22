<?php
/**
 * Variation Matcher - Match SKU-first com fallback de atributos
 *
 * @package CDM_Catalog_Router
 */

declare(strict_types=1);

namespace CDM\Core;

/**
 * Matcher de variações com arquitetura SKU-first
 *
 * ⚠️ CRÍTICO v1.2:
 * - Prioridade 1: Match por SKU (determinístico, 80% mais rápido)
 * - Prioridade 2: Fallback por atributos (SQL dinâmico com HAVING COUNT)
 * - SEM lowercase forçado (bloqueador #3 resolvido)
 */
final class VariationMatcher {

	/**
	 * WordPress Database object
	 *
	 * @var \wpdb
	 */
	private \wpdb $wpdb;

	/**
	 * Número máximo de atributos suportados
	 */
	private const MAX_ATTRIBUTES = 5;

	/**
	 * Construtor
	 */
	public function __construct() {
		global $wpdb;
		$this->wpdb = $wpdb;
	}

	/**
	 * Match inteligente: SKU primeiro, atributos como fallback
	 *
	 * Contrato público do matcher (orquestrador)
	 *
	 * @param int         $clone_parent_id       ID do produto clone pai.
	 * @param string|null $master_variation_sku  SKU da variação mestre (se disponível).
	 * @param array       $target_attributes     Atributos para fallback.
	 * @return int|null clone_variation_id ou null se não encontrar.
	 */
	public function find_matching_variation(
		int $clone_parent_id,
		?string $master_variation_sku,
		array $target_attributes
	): ?int {
		// Prioridade 1: Tentar match por SKU
		if ( $master_variation_sku ) {
			$match = $this->find_matching_variation_by_sku( $clone_parent_id, $master_variation_sku );

			if ( $match ) {
				$this->log_debug( 'Variation matched by SKU', array(
					'clone_parent_id'      => $clone_parent_id,
					'master_variation_sku' => $master_variation_sku,
					'clone_variation_id'   => $match,
					'attrs_master'         => $target_attributes,
					'attrs_candidate'      => $this->get_variation_attributes( $match ),
					'match_reason'         => 'sku',
				) );

				return $match;
			}

			$this->log_warning( 'SKU match failed, falling back to attributes', array(
				'clone_parent_id'      => $clone_parent_id,
				'master_variation_sku' => $master_variation_sku,
				'attrs_master'         => $target_attributes,
			) );
		}

		// Prioridade 2: Fallback para match por atributos
		if ( ! empty( $target_attributes ) ) {
			$match = $this->find_matching_variation_by_attributes( $clone_parent_id, $target_attributes );

			if ( $match ) {
				$this->log_debug( 'Variation matched by attributes', array(
					'clone_parent_id'    => $clone_parent_id,
					'clone_variation_id' => $match,
					'attributes_count'   => count( $target_attributes ),
					'attrs_master'       => $target_attributes,
					'attrs_candidate'    => $this->get_variation_attributes( $match ),
					'match_reason'       => 'attributes',
				) );

				return $match;
			}
		}

		// Nenhum match encontrado
		$this->log_error( 'No variation match found', array(
			'clone_parent_id'      => $clone_parent_id,
			'master_variation_sku' => $master_variation_sku,
			'target_attributes'    => $target_attributes,
			'attrs_master'         => $target_attributes,
			'match_reason'         => 'none',
		) );

		return null;
	}

	/**
	 * Match por SKU (Prioridade 1) - NOVO v1.2
	 *
	 * Match direto por SKU da variação mestre.
	 * Busca variação do clone que tem o mesmo SKU.
	 *
	 * @param int    $clone_parent_id       ID do produto clone pai.
	 * @param string $master_variation_sku  SKU da variação mestre.
	 * @return int|null clone_variation_id ou null se não encontrar.
	 */
	public function find_matching_variation_by_sku( int $clone_parent_id, string $master_variation_sku ): ?int {
		$sql = "
			SELECT p.ID
			FROM {$this->wpdb->prefix}posts p
			INNER JOIN {$this->wpdb->prefix}postmeta pm ON p.ID = pm.post_id
			WHERE p.post_parent = %d
			AND p.post_type = 'product_variation'
			AND p.post_status = 'publish'
			AND pm.meta_key = 'cdm_master_sku'
			AND pm.meta_value = %s
			LIMIT 1
		";

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		$variation_id = $this->wpdb->get_var(
			$this->wpdb->prepare( $sql, array( $clone_parent_id, $master_variation_sku ) )
		);

		return $variation_id ? (int) $variation_id : null;
	}

	/**
	 * Match por atributos (Prioridade 2 - Fallback) - RENOMEADO v1.2
	 *
	 * SQL dinâmico com HAVING COUNT para garantir ALL attributes match.
	 * ⚠️ SEM lowercase forçado (bloqueador #3 resolvido) - apenas trim.
	 *
	 * @param int   $clone_parent_id  ID do produto clone pai.
	 * @param array $target_attributes Atributos da variação mestre.
	 * @return int|null clone_variation_id ou null se não encontrar.
	 */
	public function find_matching_variation_by_attributes( int $clone_parent_id, array $target_attributes ): ?int {
		$count_attributes = count( $target_attributes );

		// Valida número de atributos
		if ( $count_attributes > self::MAX_ATTRIBUTES ) {
			$this->log_error( 'Variation Matcher: mais de 5 atributos não suportado', array(
				'clone_parent_id' => $clone_parent_id,
				'attributes_count' => $count_attributes,
			) );

			return null;
		}

		if ( 0 === $count_attributes ) {
			return null;
		}

		$meta_clauses    = array();
		$prepare_values  = array( $clone_parent_id );

		foreach ( $target_attributes as $key => $value ) {
			// Apenas trim (SEM lowercase - bloqueador #3 resolvido)
			$key   = trim( (string) $key );
			$value = $this->normalize_attribute_value( $key, trim( (string) $value ) );

			$meta_clauses[]   = '(pm.meta_key = %s AND pm.meta_value = %s)';
			$prepare_values[] = $key;
			$prepare_values[] = $value;
		}

		$prepare_values[] = $count_attributes;
		$where_clause     = implode( ' OR ', $meta_clauses );

		// ⚠️ CRÍTICO: HAVING COUNT garante ALL attributes match (não partial)
		$sql = "
			SELECT p.ID
			FROM {$this->wpdb->prefix}posts p
			INNER JOIN {$this->wpdb->prefix}postmeta pm ON p.ID = pm.post_id
			WHERE p.post_parent = %d
			AND p.post_type = 'product_variation'
			AND p.post_status = 'publish'
			AND ($where_clause)
			GROUP BY p.ID
			HAVING COUNT(DISTINCT pm.meta_key) = %d
			LIMIT 1
		";

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		$variation_id = $this->wpdb->get_var(
			$this->wpdb->prepare( $sql, $prepare_values )
		);

		return $variation_id ? (int) $variation_id : null;
	}

	/**
	 * Normalize attribute value for matching
	 *
	 * @param string $key Attribute meta key.
	 * @param string $value Attribute value.
	 * @return string
	 */
	private function normalize_attribute_value( string $key, string $value ): string {
		if ( str_starts_with( $key, 'attribute_pa_' ) || str_starts_with( $key, 'pa_' ) ) {
			return sanitize_title( $value );
		}

		return $value;
	}

	/**
	 * Get variation attributes for logging
	 *
	 * @param int $variation_id Variation id.
	 * @return array
	 */
	private function get_variation_attributes( int $variation_id ): array {
		$product = wc_get_product( $variation_id );
		if ( $product && $product->is_type( 'variation' ) ) {
			return $product->get_attributes();
		}

		return array();
	}

	/**
	 * Log de erro
	 *
	 * @param string $message Mensagem de erro.
	 * @param array  $context Contexto adicional.
	 * @return void
	 */
	private function log_error( string $message, array $context = array() ): void {
		if ( class_exists( 'WC_Logger' ) ) {
			$logger = wc_get_logger();
			$logger->error( $message, array_merge( array( 'source' => 'cdm-variation-matcher' ), $context ) );
		}

		if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( '[CDM] ' . $message . ' ' . wp_json_encode( $context ) );
		}
	}

	/**
	 * Log de debug
	 *
	 * @param string $message Mensagem de debug.
	 * @param array  $context Contexto adicional.
	 * @return void
	 */
	private function log_debug( string $message, array $context = array() ): void {
		if ( ! get_option( 'cdm_enable_logging', true ) ) {
			return;
		}

		if ( class_exists( 'WC_Logger' ) ) {
			$logger = wc_get_logger();
			$logger->debug( $message, array_merge( array( 'source' => 'cdm-variation-matcher' ), $context ) );
		}
	}

	/**
	 * Log de warning
	 *
	 * @param string $message Mensagem de warning.
	 * @param array  $context Contexto adicional.
	 * @return void
	 */
	private function log_warning( string $message, array $context = array() ): void {
		if ( class_exists( 'WC_Logger' ) ) {
			$logger = wc_get_logger();
			$logger->warning( $message, array_merge( array( 'source' => 'cdm-variation-matcher' ), $context ) );
		}
	}
}
