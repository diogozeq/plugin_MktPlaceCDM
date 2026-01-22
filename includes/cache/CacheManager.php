<?php
/**
 * Cache Manager - Sistema de cache em 3 camadas
 *
 * @package CDM_Catalog_Router
 */

declare(strict_types=1);

namespace CDM\Cache;

/**
 * Gerenciador de cache em 3 camadas
 *
 * Camada 1: Runtime Cache (array em memória)
 * Camada 2: Transient API (cache persistente)
 * Camada 3: Database (via callback)
 *
 * Esta arquitetura minimiza queries ao banco e melhora performance.
 */
final class CacheManager {

	/**
	 * Cache em memória (runtime)
	 *
	 * @var array<string, mixed>
	 */
	private array $runtime_cache = array();

	/**
	 * Estatísticas de cache
	 *
	 * @var array{hits: int, misses: int, sets: int}
	 */
	private array $stats = array(
		'hits'   => 0,
		'misses' => 0,
		'sets'   => 0,
	);

	/**
	 * Obtém valor do cache ou executa callback
	 *
	 * @param string   $key        Chave do cache.
	 * @param callable $callback   Callback para gerar valor se cache miss.
	 * @param int      $expiration Tempo de expiração em segundos.
	 * @return mixed
	 */
	public function get_or_set( string $key, callable $callback, int $expiration = 900 ) {
		// Camada 1: Runtime cache
		if ( isset( $this->runtime_cache[ $key ] ) ) {
			++$this->stats['hits'];
			return $this->runtime_cache[ $key ];
		}

		// Camada 2: Transient
		$value = get_transient( $key );
		if ( false !== $value ) {
			$this->runtime_cache[ $key ] = $value;
			++$this->stats['hits'];
			return $value;
		}

		// Camada 3: Database (via callback)
		++$this->stats['misses'];
		$value = $callback();

		// Salva em todas as camadas
		$this->set( $key, $value, $expiration );

		return $value;
	}

	/**
	 * Define valor no cache (todas as camadas)
	 *
	 * @param string $key        Chave do cache.
	 * @param mixed  $value      Valor a ser armazenado.
	 * @param int    $expiration Tempo de expiração em segundos.
	 * @return bool
	 */
	public function set( string $key, $value, int $expiration = 900 ): bool {
		++$this->stats['sets'];

		// Runtime cache
		$this->runtime_cache[ $key ] = $value;

		// Transient
		return set_transient( $key, $value, $expiration );
	}

	/**
	 * Obtém valor do cache
	 *
	 * @param string $key Chave do cache.
	 * @return mixed|false Retorna false se não encontrar.
	 */
	public function get( string $key ) {
		// Runtime cache
		if ( isset( $this->runtime_cache[ $key ] ) ) {
			++$this->stats['hits'];
			return $this->runtime_cache[ $key ];
		}

		// Transient
		$value = get_transient( $key );
		if ( false !== $value ) {
			$this->runtime_cache[ $key ] = $value;
			++$this->stats['hits'];
			return $value;
		}

		++$this->stats['misses'];
		return false;
	}

	/**
	 * Remove valor do cache
	 *
	 * @param string $key Chave do cache.
	 * @return bool
	 */
	public function delete( string $key ): bool {
		// Remove do runtime cache
		unset( $this->runtime_cache[ $key ] );

		// Remove do transient
		return delete_transient( $key );
	}

	/**
	 * Invalida cache por padrão (wildcards)
	 *
	 * @param string $pattern Padrão para match (ex: 'cdm_structure_*').
	 * @return int Número de transients removidos.
	 */
	public function invalidate_pattern( string $pattern ): int {
		global $wpdb;

		// Remove do runtime cache
		foreach ( array_keys( $this->runtime_cache ) as $key ) {
			if ( $this->key_matches_pattern( $key, $pattern ) ) {
				unset( $this->runtime_cache[ $key ] );
			}
		}

		// Remove transients do DB
		$pattern_sql = str_replace( '*', '%', $pattern );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$deleted = $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options}
				WHERE option_name LIKE %s
				OR option_name LIKE %s",
				'_transient_' . $pattern_sql,
				'_transient_timeout_' . $pattern_sql
			)
		);

		return (int) $deleted;
	}

	/**
	 * Limpa TODO o cache do plugin
	 *
	 * @return void
	 */
	public function flush_all(): void {
		global $wpdb;

		// Limpa runtime cache
		$this->runtime_cache = array();

		// Remove todos os transients do plugin
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			"DELETE FROM {$wpdb->options}
			WHERE option_name LIKE '_transient_cdm_%'
			OR option_name LIKE '_transient_timeout_cdm_%'"
		);

		// Reset stats
		$this->stats = array(
			'hits'   => 0,
			'misses' => 0,
			'sets'   => 0,
		);
	}

	/**
	 * Retorna estatísticas de cache
	 *
	 * @return array{hits: int, misses: int, sets: int, hit_rate: float}
	 */
	public function get_stats(): array {
		$total_requests = $this->stats['hits'] + $this->stats['misses'];
		$hit_rate       = $total_requests > 0
			? ( $this->stats['hits'] / $total_requests ) * 100
			: 0;

		return array(
			'hits'     => $this->stats['hits'],
			'misses'   => $this->stats['misses'],
			'sets'     => $this->stats['sets'],
			'hit_rate' => round( $hit_rate, 2 ),
		);
	}

	/**
	 * Verifica se chave corresponde ao padrão
	 *
	 * @param string $key     Chave a verificar.
	 * @param string $pattern Padrão com wildcards.
	 * @return bool
	 */
	private function key_matches_pattern( string $key, string $pattern ): bool {
		$regex = '/^' . str_replace( '\*', '.*', preg_quote( $pattern, '/' ) ) . '$/';
		return (bool) preg_match( $regex, $key );
	}
}
