<?php
/**
 * Stock Repository - Agregação de estoque de variações
 *
 * @package CDM_Catalog_Router
 */

declare(strict_types=1);

namespace CDM\Repositories;

/**
 * Repositório de estoque
 *
 * ⚠️ CRÍTICO v1.2:
 * - Entrada: master_variation_id (interface pública mantida)
 * - Resolução interna: por SKU via cache estrutural (80% mais rápido)
 * - Agregação MANUAL de variações (não usa SQL View - bloqueador #1 resolvido)
 */
final class StockRepository extends BaseRepository {

	/**
	 * Product Repository (injeção de dependência)
	 *
	 * @var ProductRepository
	 */
	private ProductRepository $product_repo;

	/**
	 * Variation Matcher (será injetado depois)
	 *
	 * @var object|null
	 */
	private ?object $variation_matcher = null;

	/**
	 * Construtor
	 *
	 * @param \CDM\Cache\CacheManager      $cache_manager   Gerenciador de cache.
	 * @param ProductRepository             $product_repo    Repositório de produtos.
	 */
	public function __construct( \CDM\Cache\CacheManager $cache_manager, ProductRepository $product_repo ) {
		parent::__construct( $cache_manager );
		$this->product_repo = $product_repo;
	}

	/**
	 * Define Variation Matcher (injeção tardia para evitar dependência circular)
	 *
	 * @param object $matcher Instância do VariationMatcher.
	 * @return void
	 */
	public function set_variation_matcher( object $matcher ): void {
		$this->variation_matcher = $matcher;
	}

	/**
	 * Obtém estoque de variação por vendedor
	 *
	 * ⚠️ MUDANÇA v1.2:
	 * - Interface pública mantém master_variation_id
	 * - Internamente resolve clone_variation_id via SKU (cache estrutural)
	 * - Se cache miss: chama matcher SKU-first
	 *
	 * @param int $master_variation_id ID da variação mestre.
	 * @return array<int, array{seller_id: int, clone_variation_id: int, stock_qty: int, clone_parent_id: int}>
	 */
	public function get_variation_stock_by_vendor( int $master_variation_id ): array {
		$cache_key = "cdm_stock_{$master_variation_id}";

		return $this->cache_manager->get_or_set(
			$cache_key,
			function () use ( $master_variation_id ) {
				// 1. Obter SKU da variação mestre
				$master_variation_sku = $this->product_repo->get_master_sku( $master_variation_id );

				if ( ! $master_variation_sku ) {
					$this->log_error( 'Master variation sem SKU', array(
						'master_variation_id' => $master_variation_id,
					) );
					return array();
				}

				// 2. Obter produto pai da variação mestre
				$master_parent_id = wp_get_post_parent_id( $master_variation_id );
				if ( ! $master_parent_id ) {
					return array();
				}

				$attrs            = array();
				$master_variation = wc_get_product( $master_variation_id );
				if ( $master_variation && $master_variation->is_type( 'variation' ) ) {
					$attrs = $master_variation->get_attributes();
				}

				// 3. Obter map_id do produto mestre
				$map_id = $this->product_repo->get_map_id( $master_parent_id );
				if ( ! $map_id ) {
					return array();
				}

				// 4. Obter clones ativos
				$active_clones = $this->product_repo->get_active_clones( $map_id );
				if ( empty( $active_clones ) ) {
					return array();
				}

				// 5. Para cada clone, resolver clone_variation_id via SKU
				$stock_data = array();

				foreach ( $active_clones as $clone ) {
					$clone_parent_id = $clone['clone_id'];
					$seller_id       = $clone['seller_id'];

					// 5.1 Tentar cache estrutural primeiro
					$clone_variation_id = $this->product_repo->get_clone_variation_id_by_sku(
						$clone_parent_id,
						$master_variation_sku
					);

					// 5.2 Se cache miss, resolver via matcher e gravar cache
					if ( null === $clone_variation_id && $this->variation_matcher ) {
						$clone_variation_id = $this->variation_matcher->find_matching_variation(
							$clone_parent_id,
							$master_variation_sku,
							$attrs
						);

						if ( $clone_variation_id ) {
							$this->product_repo->set_clone_variation_cache(
								$clone_parent_id,
								$master_variation_sku,
								$clone_variation_id
							);
						}
					}

					if ( ! $clone_variation_id ) {
						$this->log_debug( 'Clone variation não encontrada', array(
							'clone_parent_id'      => $clone_parent_id,
							'master_variation_sku' => $master_variation_sku,
						) );
						continue;
					}

					// 6. Buscar estoque da variação clone
					$stock_qty = $this->get_variation_stock( $clone_variation_id );

					if ( $stock_qty > 0 ) {
						$stock_data[] = array(
							'seller_id'          => $seller_id,
							'clone_variation_id' => $clone_variation_id,
							'clone_parent_id'    => $clone_parent_id,
							'stock_qty'          => $stock_qty,
						);
					}
				}

				return $stock_data;
			},
			300 // 5 minutos (cache de estoque)
		);
	}

	/**
	 * Obtém estoque total de uma variação no mercado (todos vendedores)
	 *
	 * @param int $master_variation_id ID da variação mestre.
	 * @return int
	 */
	public function get_total_market_stock( int $master_variation_id ): int {
		$stock_by_vendor = $this->get_variation_stock_by_vendor( $master_variation_id );

		$total = 0;
		foreach ( $stock_by_vendor as $data ) {
			$total += $data['stock_qty'];
		}

		return $total;
	}

	/**
	 * Obtém estoque de uma variação específica
	 *
	 * @param int $variation_id ID da variação.
	 * @return int
	 */
	private function get_variation_stock( int $variation_id ): int {
		$stock = get_post_meta( $variation_id, '_stock', true );

		if ( '' === $stock || null === $stock ) {
			return 0;
		}

		return max( 0, (int) $stock );
	}

	/**
	 * Invalida cache de estoque
	 *
	 * Chamado quando:
	 * - Estoque for atualizado
	 * - Pedido reduzir estoque
	 * - Admin alterar estoque manualmente
	 *
	 * @param int $master_variation_id ID da variação mestre.
	 * @return void
	 */
	public function invalidate_stock_cache( int $master_variation_id ): void {
		$cache_key = "cdm_stock_{$master_variation_id}";
		$this->cache_manager->delete( $cache_key );

		$this->log_debug( 'Stock cache invalidated', array(
			'master_variation_id' => $master_variation_id,
		) );
	}
}
