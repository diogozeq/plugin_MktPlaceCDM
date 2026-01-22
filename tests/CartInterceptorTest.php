<?php
/**
 * CartInterceptor tests
 */

declare(strict_types=1);

namespace CDM\Tests;

use CDM\Cache\CacheManager;
use CDM\Core\CartAllocator;
use CDM\Core\CartInterceptor;
use CDM\Core\CepState;
use CDM\Core\RouterEngine;
use CDM\Core\SessionManager;
use CDM\Core\VariationMatcher;
use CDM\Repositories\OfferRepository;
use CDM\Repositories\StockRepository;
use CDM\Repositories\VendorRepository;
use CDM\Strategies\StockFallbackAllocator;
use CDM\Tests\Stubs\TestProductRepository;

final class CartInterceptorTest extends TestCase {

	private function build_cart_interceptor(): CartInterceptor {
		$cache_manager = new CacheManager();
		$product_repo  = new TestProductRepository( $cache_manager );
		$offer_repo    = new OfferRepository( $cache_manager, $product_repo );
		$vendor_repo   = new VendorRepository( $cache_manager );
		$stock_repo    = new StockRepository( $cache_manager, $product_repo );
		$matcher       = new VariationMatcher();
		$stock_repo->set_variation_matcher( $matcher );

		$strategy = new StockFallbackAllocator();

		$router_engine = new RouterEngine(
			$product_repo,
			$offer_repo,
			$vendor_repo,
			$stock_repo,
			$matcher,
			$strategy
		);

		$cep_state       = new CepState();
		$session_manager = new SessionManager();
		$cart_allocator  = new CartAllocator( $session_manager, $offer_repo, $cep_state );

		return new CartInterceptor(
			$product_repo,
			$offer_repo,
			$router_engine,
			$cep_state,
			$session_manager,
			$cart_allocator
		);
	}

	public function test_offer_without_master_ids_blocked_classic(): void {
		$master_id = $this->create_user( 2 );
		$vendor_id = $this->create_user( 3 );
		$this->assertSame( 2, $master_id );

		$clone_id = $this->create_simple_product( $vendor_id, 'CLONE-NO-MASTER', 5 );
		delete_post_meta( $clone_id, 'cdm_master_sku' );
		delete_post_meta( $clone_id, 'cdm_master_product_id' );

		$interceptor = $this->build_cart_interceptor();
		$result      = $interceptor->validate_and_route( true, $clone_id, 1, 0, array(), array() );

		$this->assertFalse( $result );
		$errors = function_exists( 'wc_get_notices' ) ? wc_get_notices( 'error' ) : array();
		$this->assertNotEmpty( $errors );
		$this->assertStringContainsString(
			'Oferta sem master SKU',
			$errors[0]['notice'] ?? ''
		);
	}

	public function test_offer_without_master_ids_blocked_store_api(): void {
		if ( ! class_exists( \Automattic\WooCommerce\StoreApi\Exceptions\RouteException::class ) ) {
			$this->markTestSkipped( 'Store API RouteException not available.' );
		}

		$this->create_user( 2 );
		$vendor_id = $this->create_user( 3 );
		$clone_id  = $this->create_simple_product( $vendor_id, 'CLONE-NO-MASTER-API', 5 );
		delete_post_meta( $clone_id, 'cdm_master_sku' );
		delete_post_meta( $clone_id, 'cdm_master_product_id' );

		$interceptor = $this->build_cart_interceptor();
		$product     = wc_get_product( $clone_id );

		$this->expectException( \Automattic\WooCommerce\StoreApi\Exceptions\RouteException::class );
		$interceptor->validate_store_api( $product, array( 'quantity' => 1 ) );
	}
}
