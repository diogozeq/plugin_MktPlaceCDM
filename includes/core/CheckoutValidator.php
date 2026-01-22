<?php
/**
 * Checkout Validator - Anti-bypass com limpeza ativa
 *
 * @package CDM_Catalog_Router
 */

declare(strict_types=1);

namespace CDM\Core;

use CDM\Repositories\ProductRepository;
use CDM\Repositories\OfferRepository;

/**
 * Validador de checkout
 *
 * ⚠️ BLOQUEADOR #6 RESOLVIDO:
 * - Além de bloquear checkout, LIMPA clones não-roteados do carrinho
 * - Valida add-to-cart URL e redireciona
 * - UX melhorada: usuário entende o que aconteceu
 */
final class CheckoutValidator {

	/**
	 * Product Repository
	 *
	 * @var ProductRepository
	 */
	private ProductRepository $product_repo;

	/**
	 * Offer Repository
	 *
	 * @var OfferRepository
	 */
	private OfferRepository $offer_repo;

	/**
	 * CEP State
	 *
	 * @var CepState
	 */
	private CepState $cep_state;

	/**
	 * Construtor
	 *
	 * @param ProductRepository $product_repo Repositório de produtos.
	 */
	public function __construct( ProductRepository $product_repo, OfferRepository $offer_repo, CepState $cep_state ) {
		$this->product_repo = $product_repo;
		$this->offer_repo   = $offer_repo;
		$this->cep_state    = $cep_state;
	}

	/**
	 * Inicializa hooks
	 *
	 * @return void
	 */
	public function init(): void {
		// Hook 1: Valida e limpa carrinho (priority 5 - antes de outros validadores)
		add_action( 'woocommerce_check_cart_items', array( $this, 'validate_and_clean_cart' ), 5 );

		// Hook 2: Validação final no checkout
		add_action( 'woocommerce_checkout_process', array( $this, 'validate_cart_integrity' ) );

		// Hook 3: Anti-bypass de URL direta
		add_action( 'wp_loaded', array( $this, 'validate_add_to_cart_url' ), 20 );
	}

	/**
	 * Valida e limpa carrinho (remove clones não-roteados)
	 *
	 * @return void
	 */
	public function validate_and_clean_cart(): void {
		if ( ! WC()->cart ) {
			return;
		}

		$cart             = WC()->cart->get_cart();
		$suspicious_items = array();

		foreach ( $cart as $cart_item_key => $cart_item ) {
			$product_id = $cart_item['product_id'];

			if ( $this->is_unrouted_clone( $product_id, $cart_item ) ) {
				$suspicious_items[] = $cart_item_key;
			}

			if ( ! empty( $cart_item['cdm_routed'] ) && ! $this->has_valid_routing_meta( $cart_item ) ) {
				$suspicious_items[] = $cart_item_key;
			}
		}

		if ( ! empty( $suspicious_items ) ) {
			foreach ( $suspicious_items as $key ) {
				$removed_item = $cart[ $key ] ?? array();
				WC()->cart->remove_cart_item( $key );

				$this->log_warning( 'Clone não-roteado removido do carrinho', array(
					'cart_item_key' => $key,
					'product_id'    => $removed_item['product_id'] ?? 0,
					'user_ip'       => $this->get_user_ip(),
				) );
			}

			wc_add_notice(
				__( 'Alguns itens no carrinho não foram roteados corretamente e foram removidos.', 'cdm-catalog-router' ),
				'error'
			);
		}
	}

	/**
	 * Valida integridade do carrinho no checkout
	 *
	 * @return void
	 */
	public function validate_cart_integrity(): void {
		if ( ! WC()->cart ) {
			return;
		}

		$active_cep = $this->cep_state->get_active_cep();
		if ( ! $active_cep || ! $this->cep_state->is_valid_cep( $active_cep ) ) {
			wc_add_notice(
				__( 'CEP invalido ou ausente. Atualize seu CEP para continuar.', 'cdm-catalog-router' ),
				'error'
			);
			return;
		}

		foreach ( WC()->cart->get_cart() as $cart_item_key => $cart_item ) {
			// Validar apenas itens roteados
			if ( ! isset( $cart_item['cdm_routed'] ) || ! $cart_item['cdm_routed'] ) {
				continue;
			}

			if ( empty( $cart_item['cdm_master_sku'] ) || empty( $cart_item['cdm_offer_product_id'] ) ) {
				wc_add_notice(
					__( 'Item sem dados de roteamento. Atualize o carrinho.', 'cdm-catalog-router' ),
					'error'
				);
				continue;
			}

			if ( empty( $cart_item['cdm_cep_used'] ) ) {
				wc_add_notice(
					__( 'CEP nao associado ao item. Atualize o carrinho.', 'cdm-catalog-router' ),
					'error'
				);
				continue;
			}

			if ( $cart_item['cdm_cep_used'] !== $active_cep ) {
				wc_add_notice(
					__( 'Seu CEP mudou. Atualize o carrinho para continuar.', 'cdm-catalog-router' ),
					'error'
				);
				continue;
			}

			// 1. Validar estoque
			if ( ! $this->validate_stock( $cart_item ) ) {
				wc_add_notice(
					__( 'Estoque insuficiente para um dos produtos no carrinho.', 'cdm-catalog-router' ),
					'error'
				);
			}

			// 2. Validar preço (anti-manipulação)
			if ( ! $this->validate_price( $cart_item ) ) {
				wc_add_notice(
					__( 'Preço inconsistente detectado. Por favor, recarregue o carrinho.', 'cdm-catalog-router' ),
					'error'
				);

				$this->log_warning( 'Tentativa de manipulação de preço detectada', array(
					'cart_item_key' => $cart_item_key,
					'product_id'    => $cart_item['product_id'],
					'user_ip'       => $this->get_user_ip(),
				) );
			}
		}
	}

	/**
	 * Valida add-to-cart via URL direta (anti-bypass)
	 *
	 * @return void
	 */
	public function validate_add_to_cart_url(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! isset( $_REQUEST['add-to-cart'] ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$product_id = absint( $_REQUEST['add-to-cart'] );

		// Verificar se é clone
		if ( ! $this->is_clone( $product_id ) ) {
			return; // Não é clone, permitir
		}

		// É clone: redirecionar para produto mestre ou bloquear
		$master_id = $this->product_repo->get_master_from_clone( $product_id );

		if ( $master_id ) {
			// Redirecionar para produto mestre
			wp_safe_redirect( get_permalink( $master_id ) );
			exit;
		}

		// Se não achar mestre, bloquear
		wc_add_notice(
			__( 'Este produto não pode ser adicionado diretamente ao carrinho.', 'cdm-catalog-router' ),
			'error'
		);

		wp_safe_redirect( wc_get_cart_url() );
		exit;
	}

	/**
	 * Verifica se produto é clone não-roteado
	 *
	 * @param int   $product_id ID do produto.
	 * @param array $cart_item  Item do carrinho.
	 * @return bool
	 */
	private function is_unrouted_clone( int $product_id, array $cart_item ): bool {
		// Se marcado como roteado, OK
		if ( isset( $cart_item['cdm_routed'] ) && $cart_item['cdm_routed'] ) {
			return false;
		}

		// Checar se está na dokan_product_map (é clone)
		return $this->is_clone( $product_id );
	}

	/**
	 * Verifica se produto é clone
	 *
	 * @param int $product_id ID do produto.
	 * @return bool
	 */
	private function is_clone( int $product_id ): bool {
		global $wpdb;

		if ( $this->product_repo->is_master_product( $product_id ) ) {
			return false;
		}

		$master_seller_id = (int) apply_filters( 'cdm_master_seller_id', 2 );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$is_clone = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}dokan_product_map WHERE product_id = %d AND seller_id != %d AND is_trash = 0",
				$product_id,
				$master_seller_id
			)
		);

		return $is_clone > 0;
	}

	/**
	 * Check routed item metadata
	 *
	 * @param array $cart_item Cart item.
	 * @return bool
	 */
	private function has_valid_routing_meta( array $cart_item ): bool {
		$required = array(
			'cdm_master_id',
			'cdm_master_sku',
			'cdm_offer_product_id',
			'cdm_seller_id',
		);

		foreach ( $required as $key ) {
			if ( empty( $cart_item[ $key ] ) ) {
				return false;
			}
		}

		$product_id = (int) ( $cart_item['product_id'] ?? 0 );
		if ( $product_id <= 0 ) {
			return false;
		}

		$context = $this->offer_repo->resolve_offer_context( $product_id );
		return ! empty( $context['valid'] );
	}

	/**
	 * Valida estoque do item
	 *
	 * @param array $cart_item Item do carrinho.
	 * @return bool
	 */
	private function validate_stock( array $cart_item ): bool {
		$product_id = $cart_item['variation_id'] ?? $cart_item['product_id'];
		$stock      = get_post_meta( $product_id, '_stock', true );

		if ( '' === $stock || null === $stock ) {
			return true; // Sem gestão de estoque
		}

		$available_stock = max( 0, (int) $stock );
		$required_qty    = $cart_item['quantity'];

		return $available_stock >= $required_qty;
	}

	/**
	 * Valida preço do item (anti-manipulação)
	 *
	 * @param array $cart_item Item do carrinho.
	 * @return bool
	 */
	private function validate_price( array $cart_item ): bool {
		// Obter preço esperado do mestre
		$master_variation_id = $cart_item['cdm_master_variation_id'] ?? 0;

		if ( $master_variation_id > 0 ) {
			$expected_price = (float) get_post_meta( $master_variation_id, '_price', true );
		} else {
			$master_id      = $cart_item['cdm_master_id'] ?? 0;
			$master_product = wc_get_product( $master_id );
			$expected_price = $master_product ? (float) $master_product->get_price() : 0;
		}

		// Obter preço atual do item no carrinho
		$current_price = (float) $cart_item['data']->get_price();

		// Tolerância de ±0.01 para arredondamento
		$diff = abs( $expected_price - $current_price );

		return $diff <= 0.01;
	}

	/**
	 * Obtém IP do usuário
	 *
	 * @return string
	 */
	private function get_user_ip(): string {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		return isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : 'unknown';
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
			$logger->warning( $message, array_merge( array( 'source' => 'cdm-checkout-validator' ), $context ) );
		}
	}
}
