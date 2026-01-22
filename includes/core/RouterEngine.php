<?php
/**
 * Router Engine - Orquestrador principal de roteamento
 *
 * @package CDM_Catalog_Router
 */

declare(strict_types=1);

namespace CDM\Core;

use CDM\Repositories\ProductRepository;
use CDM\Repositories\OfferRepository;
use CDM\Repositories\VendorRepository;
use CDM\Repositories\StockRepository;
use CDM\Strategies\RoutingStrategy;

/**
 * Motor de roteamento
 *
 * ⚠️ MUDANÇA v1.2:
 * - Obtém master_variation_sku via ProductRepository
 * - Passa SKU para VariationMatcher (SKU-first)
 * - Retorna allocations com shape obrigatório:
 *   {clone_parent_id, clone_variation_id, seller_id, qty}
 */
final class RouterEngine {

	/**
	 * Product Repository
	 *
	 * @var ProductRepository
	 */
	private ProductRepository $product_repo;

	/**
	 * Vendor Repository
	 *
	 * @var VendorRepository
	 */
	private VendorRepository $vendor_repo;

	/**
	 * Stock Repository
	 *
	 * @var StockRepository
	 */
	private StockRepository $stock_repo;

	/**
	 * Offer Repository
	 *
	 * @var OfferRepository
	 */
	private OfferRepository $offer_repo;

	/**
	 * Variation Matcher
	 *
	 * @var VariationMatcher
	 */
	private VariationMatcher $variation_matcher;

	/**
	 * Routing Strategy (injetada)
	 *
	 * @var RoutingStrategy
	 */
	private RoutingStrategy $strategy;

	/**
	 * Construtor
	 *
	 * @param ProductRepository $product_repo       Repositório de produtos.
	 * @param VendorRepository  $vendor_repo        Repositório de vendedores.
	 * @param StockRepository   $stock_repo         Repositório de estoque.
	 * @param VariationMatcher  $variation_matcher  Matcher de variações.
	 * @param RoutingStrategy   $strategy           Estratégia de roteamento.
	 */
	public function __construct(
		ProductRepository $product_repo,
		OfferRepository $offer_repo,
		VendorRepository $vendor_repo,
		StockRepository $stock_repo,
		VariationMatcher $variation_matcher,
		RoutingStrategy $strategy
	) {
		$this->product_repo       = $product_repo;
		$this->offer_repo         = $offer_repo;
		$this->vendor_repo        = $vendor_repo;
		$this->stock_repo         = $stock_repo;
		$this->variation_matcher  = $variation_matcher;
		$this->strategy           = $strategy;
	}

	/**
	 * Roteia produto para clones
	 *
	 * @param int         $master_id      ID do produto mestre.
	 * @param int         $qty            Quantidade solicitada.
	 * @param int|null    $variation_id   ID da variação mestre (null para produto simples).
	 * @param array       $attrs          Atributos da variação.
	 * @param string|null $cep            CEP do cliente (opcional).
	 * @return array{success: bool, allocations?: array, error?: string}
	 */
	public function route_product(
		int $master_id,
		int $qty,
		?int $variation_id,
		array $attrs,
		?string $cep = null
	): array {
		$start_time = microtime( true );

		try {
			// 1. Verificar se é produto mestre
			if ( ! $this->product_repo->is_master_product( $master_id ) ) {
				return array(
					'success' => false,
					'error'   => __( 'Este produto não é multi-vendor.', 'cdm-catalog-router' ),
				);
			}

			// 2. Obter map_id
			$map_id = $this->product_repo->get_map_id( $master_id );
			if ( ! $map_id ) {
				return array(
					'success' => false,
					'error'   => __( 'Produto mestre sem map_id.', 'cdm-catalog-router' ),
				);
			}

			// 3. Obter clones ativos
			$active_clones = $this->product_repo->get_active_clones( $map_id );
			if ( empty( $active_clones ) ) {
				return array(
					'success' => false,
					'error'   => __( 'Nenhum vendedor ativo para este produto.', 'cdm-catalog-router' ),
				);
			}

			// 4. Resolver variacoes (se produto variavel)
			$clones_with_stock  = array();
			$matched_variations = null;

			if ( $variation_id ) {
				// Produto variavel: resolver clone_variation_id para cada clone
				$variable_result    = $this->resolve_variable_product_stock( $variation_id, $active_clones, $attrs );
				$clones_with_stock  = $variable_result['clones_with_stock'];
				$matched_variations = (int) $variable_result['matched_variations'];
			} else {
				// Produto simples: buscar estoque direto do clone parent
				$clones_with_stock = $this->resolve_simple_product_stock( $active_clones );
			}

			if ( empty( $clones_with_stock ) ) {
				$error = __( 'Nenhum vendedor com estoque disponivel.', 'cdm-catalog-router' );

				if ( $variation_id && 0 === $matched_variations ) {
					$error = __( 'Variacao indisponivel para este produto.', 'cdm-catalog-router' );
				}

				$this->log_warning( 'Routing failed: no candidates', array(
					'master_id'          => $master_id,
					'master_sku'         => $this->offer_repo->get_effective_master_sku( $variation_id ?: $master_id ),
					'variation_id'       => $variation_id,
					'attrs_master'       => $this->resolve_master_attrs( $variation_id, $attrs ),
					'matched_variations' => $matched_variations,
				) );

				return array(
					'success' => false,
					'error'   => $error,
				);
			}

			// 5. Aplicar estrategia de roteamento
			$result = $this->strategy->allocate( $clones_with_stock, $qty, $cep );
			$fulfilled = $result['fulfilled'];
			$this->log_decision( $master_id, $variation_id, $qty, $cep, $clones_with_stock, $result['allocations'], $attrs, $fulfilled );

			// Log de performance
			$elapsed = microtime( true ) - $start_time;
			if ( $elapsed > 1.0 ) {
				$this->log_warning( 'Roteamento lento', array(
					'master_id'   => $master_id,
					'elapsed_ms'  => round( $elapsed * 1000 ),
				) );
			}

			if ( ! $fulfilled ) {
				$this->log_warning( 'Routing failed: insufficient quantity', array(
					'master_id'     => $master_id,
					'master_sku'    => $this->offer_repo->get_effective_master_sku( $variation_id ?: $master_id ),
					'variation_id'  => $variation_id,
					'qty'           => $qty,
					'cep'           => $cep,
					'attrs_master'  => $this->resolve_master_attrs( $variation_id, $attrs ),
				) );

				return array(
					'success'     => false,
					'error'       => __( 'Quantidade indisponivel para este produto.', 'cdm-catalog-router' ),
					'allocations' => $result['allocations'],
				);
			}

			return array(
				'success'     => true,
				'allocations' => $result['allocations'],
			);

		} catch ( \Exception $e ) {
			$this->log_error( 'Erro no roteamento', array(
				'master_id' => $master_id,
				'error'     => $e->getMessage(),
			) );

			return array(
				'success' => false,
				'error'   => __( 'Erro interno no roteamento.', 'cdm-catalog-router' ),
			);
		}
	}

	/**
	 * Resolve estoque de produto variável (MUDANÇA v1.2)
	 *
	 * @param int   $master_variation_id ID da variação mestre.
	 * @param array $active_clones       Clones ativos.
	 * @return array{clones_with_stock: array, matched_variations: int}
	 */
	private function resolve_variable_product_stock( int $master_variation_id, array $active_clones, array $attrs ): array {
		// Obtém SKU da variação mestre (v1.2)
		$master_variation_sku = $this->product_repo->get_master_sku( $master_variation_id );

		if ( ! $master_variation_sku ) {
			$this->log_warning( 'Master variation sem SKU', array(
				'master_variation_id' => $master_variation_id,
			) );
		}

		$clones_with_stock  = array();
		$matched_variations = 0;

		foreach ( $active_clones as $clone ) {
			$clone_parent_id = $clone['clone_id'];
			$seller_id       = $clone['seller_id'];

			// Resolver clone_variation_id via matcher (SKU-first)
			$clone_variation_id = $this->variation_matcher->find_matching_variation(
				$clone_parent_id,
				$master_variation_sku,
				$attrs
			);

			if ( ! $clone_variation_id ) {
				$this->log_warning( 'No matching variation for clone', array(
					'clone_parent_id'      => $clone_parent_id,
					'seller_id'            => $seller_id,
					'master_variation_id'  => $master_variation_id,
					'master_sku'           => $master_variation_sku,
					'attrs_master'         => $attrs,
				) );
				continue;
			}

			$matched_variations++;

			// Buscar estoque da variação clone
			$stock_qty = $this->get_variation_stock( $clone_variation_id );

			if ( $stock_qty > 0 ) {
				$lead_time = $this->offer_repo->get_offer_lead_time_hours( $clone_parent_id );
				$max_lead  = (int) get_option( 'cdm_offer_max_lead_time_hours', 0 );
				if ( $max_lead > 0 && null !== $lead_time && $lead_time > $max_lead ) {
					continue;
				}

				$is_fresh = $this->offer_repo->is_offer_data_fresh( $clone_parent_id );
				$clones_with_stock[] = array(
					'clone_parent_id'    => $clone_parent_id,
					'clone_variation_id' => $clone_variation_id,
					'seller_id'          => $seller_id,
					'stock_qty'          => $stock_qty,
					'lead_time_hours'    => $lead_time,
					'is_stale'           => ! $is_fresh,
				);
			}
		}

		return array(
			'clones_with_stock'  => $this->filter_stale_clones( $clones_with_stock ),
			'matched_variations' => $matched_variations,
		);
	}

	/**
	 * Resolve estoque de produto simples
	 *
	 * @param array $active_clones Clones ativos.
	 * @return array
	 */
	private function resolve_simple_product_stock( array $active_clones ): array {
		$clones_with_stock = array();

		foreach ( $active_clones as $clone ) {
			$clone_id  = $clone['clone_id'];
			$seller_id = $clone['seller_id'];

			$stock_qty = $this->get_product_stock( $clone_id );
			$lead_time = $this->offer_repo->get_offer_lead_time_hours( $clone_id );
			$max_lead  = (int) get_option( 'cdm_offer_max_lead_time_hours', 0 );
			$is_fresh  = $this->offer_repo->is_offer_data_fresh( $clone_id );

			if ( $stock_qty > 0 ) {
				if ( $max_lead > 0 && null !== $lead_time && $lead_time > $max_lead ) {
					continue;
				}

				$clones_with_stock[] = array(
					'clone_parent_id'    => $clone_id,
					'clone_variation_id' => null, // Produto simples não tem variação
					'seller_id'          => $seller_id,
					'stock_qty'          => $stock_qty,
					'lead_time_hours'    => $lead_time,
					'is_stale'           => ! $is_fresh,
				);
			}
		}

		return $this->filter_stale_clones( $clones_with_stock );
	}

	/**
	 * Obtém estoque de variação
	 *
	 * @param int $variation_id ID da variação.
	 * @return int
	 */
	private function get_variation_stock( int $variation_id ): int {
		$stock = get_post_meta( $variation_id, '_stock', true );
		return max( 0, (int) $stock );
	}

	/**
	 * Obtém estoque de produto simples
	 *
	 * @param int $product_id ID do produto.
	 * @return int
	 */
	private function get_product_stock( int $product_id ): int {
		$stock = get_post_meta( $product_id, '_stock', true );
		return max( 0, (int) $stock );
	}

	/**
	 * Pick a single offer by master sku (deterministic)
	 *
	 * @param string $master_sku Master sku.
	 * @param string $cep        CEP do cliente.
	 * @param int    $qty        Quantidade.
	 * @param array  $context    Contexto adicional.
	 * @return array{success: bool, offer_product_id?: int, allocations?: array, error?: string}
	 */
	public function pick_offer( string $master_sku, string $cep, int $qty, array $context = array() ): array {
		$match = $this->offer_repo->find_master_product_by_master_sku( $master_sku );
		if ( ! $match ) {
			return array(
				'success' => false,
				'error'   => __( 'Master SKU not found.', 'cdm-catalog-router' ),
			);
		}

		$master_id      = (int) $match['master_product_id'];
		$variation_id   = $match['master_variation_id'] ? (int) $match['master_variation_id'] : null;
		$routing_result = $this->route_product( $master_id, $qty, $variation_id, array(), $cep );

		if ( ! $routing_result['success'] ) {
			return $routing_result;
		}

		$allocation = $routing_result['allocations'][0] ?? null;
		if ( ! $allocation ) {
			return array(
				'success' => false,
				'error'   => __( 'No offer allocation available.', 'cdm-catalog-router' ),
			);
		}

		$offer_product_id = $allocation['clone_variation_id'] ?? $allocation['clone_parent_id'];

		return array(
			'success'          => true,
			'offer_product_id' => (int) $offer_product_id,
			'allocations'      => $routing_result['allocations'],
		);
	}

	/**
	 * Filter stale offers if possible
	 *
	 * @param array $clones_with_stock Clones list.
	 * @return array
	 */
	private function filter_stale_clones( array $clones_with_stock ): array {
		if ( empty( $clones_with_stock ) ) {
			return $clones_with_stock;
		}

		$fresh = array_filter(
			$clones_with_stock,
			static fn( $clone ) => empty( $clone['is_stale'] )
		);

		if ( ! empty( $fresh ) ) {
			return array_values( $fresh );
		}

		$this->log_warning( 'All offers are stale, using stale data', array(
			'candidates' => $clones_with_stock,
		) );

		return $clones_with_stock;
	}

	/**
	 * Log routing decision
	 *
	 * @param int         $master_id Master product id.
	 * @param int|null    $variation_id Master variation id.
	 * @param int         $qty Quantity.
	 * @param string|null $cep CEP.
	 * @param array       $candidates Candidates.
	 * @param array       $allocations Allocations.
	 * @param array       $attrs Master attributes.
	 * @param bool        $fulfilled Fulfilled flag.
	 * @return void
	 */
	private function log_decision(
		int $master_id,
		?int $variation_id,
		int $qty,
		?string $cep,
		array $candidates,
		array $allocations,
		array $attrs,
		bool $fulfilled
	): void {
		if ( ! get_option( 'cdm_enable_logging', true ) ) {
			return;
		}

		if ( class_exists( 'WC_Logger' ) ) {
			$master_sku = null;
			if ( $variation_id ) {
				$master_sku = $this->offer_repo->get_effective_master_sku( $variation_id );
			}
			if ( ! $master_sku ) {
				$master_sku = $this->offer_repo->get_effective_master_sku( $master_id );
			}

			$attrs_master = $this->resolve_master_attrs( $variation_id, $attrs );
			$offers       = array();

			foreach ( $allocations as $allocation ) {
				$clone_parent_id    = (int) ( $allocation['clone_parent_id'] ?? 0 );
				$clone_variation_id = (int) ( $allocation['clone_variation_id'] ?? 0 );
				$offer_product_id   = $clone_variation_id > 0 ? $clone_variation_id : $clone_parent_id;

				$offers[] = array(
					'offer_product_id'  => $offer_product_id,
					'clone_parent_id'   => $clone_parent_id,
					'clone_variation_id'=> $clone_variation_id ?: null,
					'vendor_id'         => (int) ( $allocation['seller_id'] ?? 0 ),
					'qty'               => (int) ( $allocation['qty'] ?? 0 ),
					'attrs_candidate'   => $offer_product_id ? $this->get_offer_attrs( $offer_product_id ) : array(),
				);
			}

			$logger = wc_get_logger();
			$logger->info(
				'Routing decision',
				array(
					'source'       => 'cdm-router-engine',
					'master_id'    => $master_id,
					'master_product_id' => $master_id,
					'master_sku'   => $master_sku,
					'variation_id' => $variation_id,
					'qty'          => $qty,
					'cep'          => $cep,
					'strategy'     => is_object( $this->strategy ) ? get_class( $this->strategy ) : null,
					'candidates'   => $candidates,
					'allocations'  => $allocations,
					'offers'       => $offers,
					'attrs_master' => $attrs_master,
					'fulfilled'    => $fulfilled,
				)
			);
		}
	}

	/**
	 * Resolve master attributes for logging
	 *
	 * @param int|null $variation_id Master variation id.
	 * @param array    $attrs        Attributes provided.
	 * @return array
	 */
	private function resolve_master_attrs( ?int $variation_id, array $attrs ): array {
		if ( ! empty( $attrs ) ) {
			return $attrs;
		}

		if ( $variation_id ) {
			$product = wc_get_product( $variation_id );
			if ( $product && $product->is_type( 'variation' ) ) {
				return $product->get_attributes();
			}
		}

		return array();
	}

	/**
	 * Get attributes for a routed offer (candidate)
	 *
	 * @param int $offer_product_id Offer product id.
	 * @return array
	 */
	private function get_offer_attrs( int $offer_product_id ): array {
		$product = wc_get_product( $offer_product_id );
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
			$logger->error( $message, array_merge( array( 'source' => 'cdm-router-engine' ), $context ) );
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
			$logger->warning( $message, array_merge( array( 'source' => 'cdm-router-engine' ), $context ) );
		}
	}
}
