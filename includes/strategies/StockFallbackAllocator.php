<?php
/**
 * Stock Fallback Allocator - Estratégia de fallback por estoque
 *
 * @package CDM_Catalog_Router
 */

declare(strict_types=1);

namespace CDM\Strategies;

/**
 * Alocador baseado em estoque (último fallback)
 *
 * Ordena vendors por estoque descendente.
 * Usado apenas quando:
 * - Nenhum CEP match
 * - Nenhum vendor tem last_order_time
 */
final class StockFallbackAllocator implements RoutingStrategy {

	/**
	 * Aloca por estoque descendente (maior estoque primeiro)
	 *
	 * @param array       $clones Clones disponíveis.
	 * @param int         $qty    Quantidade solicitada.
	 * @param string|null $cep    CEP do cliente (ignorado nesta estratégia).
	 * @return array{allocations: array, fulfilled: bool}
	 */
	public function allocate( array $clones, int $qty, ?string $cep ): array {
		if ( empty( $clones ) ) {
			return array(
				'allocations' => array(),
				'fulfilled'   => false,
			);
		}

		// Ordenar por estoque descendente (maior estoque primeiro)
		usort(
			$clones,
			function ( $a, $b ) {
				if ( $a['stock_qty'] === $b['stock_qty'] ) {
					// Desempate por seller_id ASC
					return $a['seller_id'] <=> $b['seller_id'];
				}

				// DESC: maior estoque primeiro
				return $b['stock_qty'] <=> $a['stock_qty'];
			}
		);

		$allocations = array();
		$remaining   = $qty;

		foreach ( $clones as $clone ) {
			if ( $remaining <= 0 ) {
				break;
			}

			$allocated = min( $clone['stock_qty'], $remaining );

			$allocations[] = array(
				'clone_parent_id'    => $clone['clone_parent_id'],
				'clone_variation_id' => $clone['clone_variation_id'] ?? null,
				'seller_id'          => $clone['seller_id'],
				'qty'                => $allocated,
			);

			$remaining -= $allocated;
		}

		return array(
			'allocations' => $allocations,
			'fulfilled'   => $remaining <= 0,
		);
	}
}
