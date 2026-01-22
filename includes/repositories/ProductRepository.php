<?php
/**
 * Product Repository - Queries relacionadas a produtos
 *
 * @package CDM_Catalog_Router
 */

declare(strict_types=1);

namespace CDM\Repositories;

/**
 * Repositório de produtos
 *
 * Gerencia queries de produtos master/clone e variações.
 * IMPORTANTE: Sempre carregar master_variation_sku quando existir master_variation_id.
 */
final class ProductRepository extends BaseRepository {

	/**
	 * Get master sku for product or variation
	 *
	 * @param int $product_id Product id.
	 * @return string|null
	 */
	public function get_master_sku( int $product_id ): ?string {
		$cache_key = "cdm_master_sku_{$product_id}";

		$sku = $this->cache_manager->get_or_set(
			$cache_key,
			function () use ( $product_id ) {
				$sku = get_post_meta( $product_id, 'cdm_master_sku', true );
				if ( ! empty( $sku ) ) {
					return (string) $sku;
				}

				// Fallback para SKU do Woo (apenas para legado/backfill).
				$sku = get_post_meta( $product_id, '_sku', true );
				return ! empty( $sku ) ? (string) $sku : null;
			},
			3600 // 1 hora (SKU raramente muda)
		);

		return $sku;
	}

	/**
	 * Obtém clone_variation_id pelo SKU da variação mestre (cache estrutural)
	 *
	 * ⚠️ NOVO v1.2 - Cache estrutural SKU-based
	 * Key: cdm_structure_{clone_parent_id}_{sku_hash}
	 * Value: clone_variation_id
	 *
	 * @param int    $clone_parent_id       ID do produto clone pai.
	 * @param string $master_variation_sku  SKU da variação mestre.
	 * @return int|null
	 */
	public function get_clone_variation_id_by_sku( int $clone_parent_id, string $master_variation_sku ): ?int {
		$sku_hash  = $this->hash_sku_for_cache( $master_variation_sku );
		$cache_key = "cdm_structure_{$clone_parent_id}_{$sku_hash}";

		$clone_variation_id = $this->cache_manager->get( $cache_key );

		if ( false !== $clone_variation_id ) {
			$this->log_debug( 'Cache estrutural HIT', array(
				'clone_parent_id'       => $clone_parent_id,
				'master_variation_sku'  => $master_variation_sku,
				'clone_variation_id'    => $clone_variation_id,
			) );

			return $clone_variation_id;
		}

		$this->log_debug( 'Cache estrutural MISS', array(
			'clone_parent_id'      => $clone_parent_id,
			'master_variation_sku' => $master_variation_sku,
		) );

		// Retorna null para indicar cache miss
		// O VariationMatcher irá resolver e gravar o cache
		return null;
	}

	/**
	 * Grava cache estrutural (SKU → clone_variation_id)
	 *
	 * @param int    $clone_parent_id       ID do produto clone pai.
	 * @param string $master_variation_sku  SKU da variação mestre.
	 * @param int    $clone_variation_id    ID da variação clone.
	 * @return bool
	 */
	public function set_clone_variation_cache( int $clone_parent_id, string $master_variation_sku, int $clone_variation_id ): bool {
		$sku_hash  = $this->hash_sku_for_cache( $master_variation_sku );
		$cache_key = "cdm_structure_{$clone_parent_id}_{$sku_hash}";

		return $this->cache_manager->set(
			$cache_key,
			$clone_variation_id,
			3600 // 1 hora
		);
	}

	/**
	 * Invalida cache de produto (quando produto for atualizado)
	 *
	 * @param int $product_id ID do produto.
	 * @return void
	 */
	public function invalidate_product_cache( int $product_id ): void {
		// Remove cache específico do produto
		$this->cache_manager->delete( "cdm_is_master_{$product_id}" );
		$this->cache_manager->delete( "cdm_map_id_{$product_id}" );
		$this->cache_manager->delete( "cdm_master_from_clone_{$product_id}" );
		$this->cache_manager->delete( "cdm_master_sku_{$product_id}" );

		// Invalida cache estrutural relacionado
		$this->cache_manager->invalidate_pattern( "cdm_structure_{$product_id}_*" );
	}

	/**
	 * Normaliza SKU para chave de cache
	 *
	 * @param string $sku SKU bruto.
	 * @return string
	 */
	private function hash_sku_for_cache( string $sku ): string {
		return md5( $sku );
	}
}
