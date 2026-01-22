<?php
/**
 * Global Fairness Allocator - Estratégia de fairness global
 *
 * @package CDM_Catalog_Router
 */

declare(strict_types=1);

namespace CDM\Strategies;

use CDM\Repositories\VendorRepository;

/**
 * Alocador baseado em fairness global
 *
 * ⚠️ BLOQUEADOR #2 RESOLVIDO:
 * - Ordena vendors por last_completed_at ASC (mais antigo primeiro)
 * - Desempate por seller_id ASC
 * - Garante equidade entre vendedores
 */
final class GlobalFairnessAllocator implements RoutingStrategy {

	/**
	 * Vendor Repository
	 *
	 * @var VendorRepository
	 */
	private VendorRepository $vendor_repo;

	/**
	 * Construtor
	 *
	 * @param VendorRepository $vendor_repo Repositório de vendedores.
	 */
	public function __construct( VendorRepository $vendor_repo ) {
		$this->vendor_repo = $vendor_repo;
	}

	/**
	 * Aloca por fairness global (last_order_time ASC)
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

		// Ordenar por fairness: seller que vendeu há mais tempo primeiro
		usort(
			$clones,
			function ( $a, $b ) {
				$time_a = $this->vendor_repo->get_vendor_last_order_time( $a['seller_id'] ) ?? 0;
				$time_b = $this->vendor_repo->get_vendor_last_order_time( $b['seller_id'] ) ?? 0;

				// Vendedor que nunca vendeu (time = 0) tem prioridade máxima
				if ( $time_a === $time_b ) {
					// Desempate por seller_id ASC
					return $a['seller_id'] <=> $b['seller_id'];
				}

				// ASC: mais antigo primeiro
				return $time_a <=> $time_b;
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
