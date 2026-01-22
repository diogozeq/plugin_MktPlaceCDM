<?php
/**
 * RouterEngine tests
 */

declare(strict_types=1);

namespace CDM\Tests;

use CDM\Cache\CacheManager;
use CDM\Core\RouterEngine;
use CDM\Core\VariationMatcher;
use CDM\Repositories\OfferRepository;
use CDM\Repositories\StockRepository;
use CDM\Repositories\VendorRepository;
use CDM\Strategies\StockFallbackAllocator;
use CDM\Tests\Stubs\TestProductRepository;

final class RouterEngineTest extends TestCase {

	private function build_router_engine(): RouterEngine {
		$cache_manager = new CacheManager();
		$product_repo  = new TestProductRepository( $cache_manager );
		$offer_repo    = new OfferRepository( $cache_manager, $product_repo );
		$vendor_repo   = new VendorRepository( $cache_manager );
		$stock_repo    = new StockRepository( $cache_manager, $product_repo );
		$matcher       = new VariationMatcher();
		$stock_repo->set_variation_matcher( $matcher );

		return new RouterEngine(
			$product_repo,
			$offer_repo,
			$vendor_repo,
			$stock_repo,
			$matcher,
			new StockFallbackAllocator()
		);
	}

	public function test_variation_match_sku_first(): void {
		$master_id = $this->create_user( 2 );
		$vendor_id = $this->create_user( 3 );

		$attributes = array(
			'color' => array( 'blue', 'red' ),
			'size'  => array( 'm', 'g' ),
		);

		$master = $this->create_variable_product(
			$master_id,
			$attributes,
			array( 'color' => 'blue', 'size' => 'm' ),
			'MASTER-VAR-1',
			'MSKU-1',
			5,
			true
		);

		$clone = $this->create_variable_product(
			$vendor_id,
			$attributes,
			array( 'size' => 'm', 'color' => 'blue' ),
			'CLONE-VAR-1',
			'MSKU-1',
			5,
			true
		);

		$map_id = 101;
		$this->map_product( $map_id, $master['product_id'], $master_id, 0 );
		$this->map_product( $map_id, $clone['product_id'], $vendor_id, 0 );

		$router = $this->build_router_engine();
		$attrs  = $this->prefix_attributes( array( 'size' => 'm', 'color' => 'Blue' ) );
		$result = $router->route_product( $master['product_id'], 1, $master['variation_id'], $attrs, null );

		$this->assertTrue( $result['success'] );
		$allocation = $result['allocations'][0] ?? array();
		$this->assertSame( $clone['variation_id'], (int) $allocation['clone_variation_id'] );
	}

	public function test_variation_match_fallback_attributes(): void {
		$master_id = $this->create_user( 2 );
		$vendor_id = $this->create_user( 3 );

		$attributes = array(
			'color' => array( 'blue', 'red' ),
			'size'  => array( 'm', 'g' ),
		);

		$master = $this->create_variable_product(
			$master_id,
			$attributes,
			array( 'color' => 'blue', 'size' => 'm' ),
			'MASTER-VAR-2',
			'MSKU-2',
			5,
			true
		);

		$clone = $this->create_variable_product(
			$vendor_id,
			$attributes,
			array( 'size' => 'm', 'color' => 'blue' ),
			'CLONE-VAR-2',
			'MSKU-2',
			5,
			false
		);

		$map_id = 102;
		$this->map_product( $map_id, $master['product_id'], $master_id, 0 );
		$this->map_product( $map_id, $clone['product_id'], $vendor_id, 0 );

		$router = $this->build_router_engine();
		$attrs  = $this->prefix_attributes( array( 'size' => 'm', 'color' => 'Blue' ) );
		$result = $router->route_product( $master['product_id'], 1, $master['variation_id'], $attrs, null );

		$this->assertTrue( $result['success'] );
		$allocation = $result['allocations'][0] ?? array();
		$this->assertSame( $clone['variation_id'], (int) $allocation['clone_variation_id'] );
	}

	public function test_variation_not_found_returns_error(): void {
		$master_id = $this->create_user( 2 );
		$vendor_id = $this->create_user( 3 );

		$attributes = array(
			'color' => array( 'blue', 'red' ),
		);

		$master = $this->create_variable_product(
			$master_id,
			$attributes,
			array( 'color' => 'blue' ),
			'MASTER-VAR-3',
			'MSKU-3',
			5,
			true
		);

		$clone = $this->create_variable_product(
			$vendor_id,
			$attributes,
			array( 'color' => 'red' ),
			'CLONE-VAR-3',
			'MSKU-3',
			5,
			true
		);

		$map_id = 103;
		$this->map_product( $map_id, $master['product_id'], $master_id, 0 );
		$this->map_product( $map_id, $clone['product_id'], $vendor_id, 0 );

		$router = $this->build_router_engine();
		$attrs  = $this->prefix_attributes( array( 'color' => 'blue' ) );
		$result = $router->route_product( $master['product_id'], 1, $master['variation_id'], $attrs, null );

		$this->assertFalse( $result['success'] );
		$this->assertSame( 'Variacao indisponivel para este produto.', $result['error'] ?? '' );
	}

	public function test_insufficient_stock_returns_error(): void {
		$master_id = $this->create_user( 2 );
		$vendor_id = $this->create_user( 3 );
		$vendor2   = $this->create_user( 4 );

		$master_product_id = $this->create_simple_product( $master_id, 'MASTER-SIMPLE', 0 );
		$clone_1           = $this->create_simple_product( $vendor_id, 'CLONE-SIMPLE-1', 1 );
		$clone_2           = $this->create_simple_product( $vendor2, 'CLONE-SIMPLE-2', 1 );

		$map_id = 200;
		$this->map_product( $map_id, $master_product_id, $master_id, 0 );
		$this->map_product( $map_id, $clone_1, $vendor_id, 0 );
		$this->map_product( $map_id, $clone_2, $vendor2, 0 );

		$router = $this->build_router_engine();
		$result = $router->route_product( $master_product_id, 3, null, array(), null );

		$this->assertFalse( $result['success'] );
		$this->assertSame( 'Quantidade indisponivel para este produto.', $result['error'] ?? '' );
	}
}
