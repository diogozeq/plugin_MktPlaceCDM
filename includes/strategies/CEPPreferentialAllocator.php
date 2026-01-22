<?php
/**
 * CEP Preferential Allocator - Estratégia de roteamento por CEP
 *
 * @package CDM_Catalog_Router
 */

declare(strict_types=1);

namespace CDM\Strategies;

use CDM\Repositories\VendorRepository;

/**
 * Alocador com preferência por CEP
 *
 * ⚠️ BLOQUEADOR #2 RESOLVIDO:
 * - CEP match tem prioridade (enche vendor até onde der)
 * - Depois, qty restante vai para fila global (fairness)
 * - NÃO ordena por estoque (erro do plano v1.0)
 */
final class CEPPreferentialAllocator implements RoutingStrategy {

	/**
	 * Vendor Repository
	 *
	 * @var VendorRepository
	 */
	private VendorRepository $vendor_repo;

	/**
	 * Global Fairness Allocator (fallback)
	 *
	 * @var GlobalFairnessAllocator
	 */
	private GlobalFairnessAllocator $global_fairness_allocator;

	/**
	 * Construtor
	 *
	 * @param VendorRepository        $vendor_repo                 Repositório de vendedores.
	 * @param GlobalFairnessAllocator $global_fairness_allocator   Alocador de fairness global.
	 */
	public function __construct(
		VendorRepository $vendor_repo,
		GlobalFairnessAllocator $global_fairness_allocator
	) {
		$this->vendor_repo                = $vendor_repo;
		$this->global_fairness_allocator  = $global_fairness_allocator;
	}

	/**
	 * Aloca com preferência por CEP
	 *
	 * @param array       $clones Clones disponíveis.
	 * @param int         $qty    Quantidade solicitada.
	 * @param string|null $cep    CEP do cliente.
	 * @return array{allocations: array, fulfilled: bool}
	 */
	public function allocate( array $clones, int $qty, ?string $cep ): array {
		// Se não tem CEP, vai direto para fairness global
		if ( ! $cep ) {
			return $this->global_fairness_allocator->allocate( $clones, $qty, null );
		}

		// Filtrar clones com CEP matching
		$cep_matches = array_filter(
			$clones,
			fn( $clone ) => $this->vendor_repo->vendor_serves_cep( $clone['seller_id'], $cep )
		);

		// Se nenhum vendor atende o CEP, vai direto para fairness global
		if ( empty( $cep_matches ) ) {
			return $this->global_fairness_allocator->allocate( $clones, $qty, null );
		}

		// Ordenar CEP matches por last_order_time ASC (fairness dentro do CEP match)
		usort(
			$cep_matches,
			function ( $a, $b ) {
				$time_a = $this->vendor_repo->get_vendor_last_order_time( $a['seller_id'] ) ?? 0;
				$time_b = $this->vendor_repo->get_vendor_last_order_time( $b['seller_id'] ) ?? 0;

				if ( $time_a === $time_b ) {
					return $a['seller_id'] <=> $b['seller_id'];
				}

				return $time_a <=> $time_b;
			}
		);

		$allocations = array();
		$remaining   = $qty;

		// Primeiro: encher vendors com CEP match
		foreach ( $cep_matches as $clone ) {
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

		// Se ainda falta qty, vai para fila global (fairness)
		if ( $remaining > 0 ) {
			$global_result = $this->global_fairness_allocator->allocate( $clones, $remaining, null );
			$allocations   = array_merge( $allocations, $global_result['allocations'] );
			$remaining     = $global_result['fulfilled'] ? 0 : $remaining;
		}

		return array(
			'allocations' => $allocations,
			'fulfilled'   => $remaining <= 0,
		);
	}
}
