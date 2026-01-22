<?php
/**
 * Interface para estratégias de roteamento
 *
 * @package CDM_Catalog_Router
 */

declare(strict_types=1);

namespace CDM\Strategies;

/**
 * Contrato para algoritmos de roteamento
 *
 * Implementações:
 * - CEPPreferentialAllocator (prioridade 1: CEP match)
 * - GlobalFairnessAllocator (prioridade 2: last_order_time ASC)
 * - StockFallbackAllocator (prioridade 3: estoque DESC)
 */
interface RoutingStrategy {

	/**
	 * Aloca quantidade entre clones disponíveis
	 *
	 * @param array       $clones Clones disponíveis com estoque.
	 *                            Formato: [
	 *                              [
	 *                                'clone_parent_id' => int,
	 *                                'clone_variation_id' => int|null,
	 *                                'seller_id' => int,
	 *                                'stock_qty' => int
	 *                              ],
	 *                              ...
	 *                            ].
	 * @param int         $qty    Quantidade solicitada.
	 * @param string|null $cep    CEP do cliente (opcional).
	 * @return array{allocations: array, fulfilled: bool}
	 *         Formato: [
	 *           'allocations' => [
	 *             [
	 *               'clone_parent_id' => int,
	 *               'clone_variation_id' => int|null,
	 *               'seller_id' => int,
	 *               'qty' => int
	 *             ],
	 *             ...
	 *           ],
	 *           'fulfilled' => bool  // true se qty foi totalmente alocada
	 *         ]
	 */
	public function allocate( array $clones, int $qty, ?string $cep ): array;
}
