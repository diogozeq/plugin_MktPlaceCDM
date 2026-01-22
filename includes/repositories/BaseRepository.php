<?php
/**
 * Base Repository - Classe abstrata para repositórios
 *
 * @package CDM_Catalog_Router
 */

declare(strict_types=1);

namespace CDM\Repositories;

use CDM\Cache\CacheManager;

/**
 * Repositório base abstrato
 *
 * Fornece funcionalidades comuns para todos os repositórios:
 * - Acesso ao $wpdb
 * - Gerenciamento de cache
 * - Helpers para queries
 */
abstract class BaseRepository {

	/**
	 * WordPress Database object
	 *
	 * @var \wpdb
	 */
	protected \wpdb $wpdb;

	/**
	 * Cache Manager
	 *
	 * @var CacheManager
	 */
	protected CacheManager $cache_manager;

	/**
	 * Construtor
	 *
	 * @param CacheManager $cache_manager Gerenciador de cache.
	 */
	public function __construct( CacheManager $cache_manager ) {
		global $wpdb;

		$this->wpdb          = $wpdb;
		$this->cache_manager = $cache_manager;
	}

	/**
	 * Prepara cláusula IN para queries SQL
	 *
	 * @param array<int|string> $values Valores para a cláusula IN.
	 * @return string SQL preparado (ex: "1,2,3").
	 */
	protected function prepare_in_clause( array $values ): string {
		if ( empty( $values ) ) {
			return '';
		}

		// Sanitiza valores
		$sanitized = array_map( 'intval', $values );

		return implode( ',', $sanitized );
	}

	/**
	 * Invalida cache por padrão
	 *
	 * @param string $pattern Padrão de chave (ex: 'cdm_structure_*').
	 * @return int Número de itens invalidados.
	 */
	protected function invalidate_cache( string $pattern ): int {
		return $this->cache_manager->invalidate_pattern( $pattern );
	}

	/**
	 * Obtém meta value de produto/post
	 *
	 * @param int    $post_id    ID do post.
	 * @param string $meta_key   Chave da meta.
	 * @param bool   $single     Retorna valor único.
	 * @param int    $cache_ttl  TTL do cache em segundos (0 = sem cache).
	 * @return mixed
	 */
	protected function get_post_meta_cached( int $post_id, string $meta_key, bool $single = true, int $cache_ttl = 3600 ) {
		if ( 0 === $cache_ttl ) {
			return get_post_meta( $post_id, $meta_key, $single );
		}

		$cache_key = "cdm_meta_{$post_id}_{$meta_key}";

		return $this->cache_manager->get_or_set(
			$cache_key,
			function () use ( $post_id, $meta_key, $single ) {
				return get_post_meta( $post_id, $meta_key, $single );
			},
			$cache_ttl
		);
	}

	/**
	 * Executa query com cache
	 *
	 * @param string   $cache_key  Chave do cache.
	 * @param callable $query      Callback que executa a query.
	 * @param int      $expiration TTL do cache em segundos.
	 * @return mixed
	 */
	protected function query_with_cache( string $cache_key, callable $query, int $expiration = 900 ) {
		return $this->cache_manager->get_or_set( $cache_key, $query, $expiration );
	}

	/**
	 * Log de erro
	 *
	 * @param string $message Mensagem de erro.
	 * @param array  $context Contexto adicional.
	 * @return void
	 */
	protected function log_error( string $message, array $context = array() ): void {
		if ( class_exists( 'WC_Logger' ) ) {
			$logger = wc_get_logger();
			$logger->error( $message, array_merge( array( 'source' => 'cdm-catalog-router' ), $context ) );
		}

		// Fallback para error_log se WC_Logger não disponível
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
	protected function log_debug( string $message, array $context = array() ): void {
		if ( ! get_option( 'cdm_enable_logging', true ) ) {
			return;
		}

		if ( class_exists( 'WC_Logger' ) ) {
			$logger = wc_get_logger();
			$logger->debug( $message, array_merge( array( 'source' => 'cdm-catalog-router' ), $context ) );
		}
	}
}
