<?php
/**
 * Price Enforcer - Sobrescreve preços de clones com preços do mestre
 *
 * @package CDM_Catalog_Router
 */

declare(strict_types=1);

namespace CDM\Core;

/**
 * Enforcador de preços
 *
 * Garante que clones sempre usem o preço do produto mestre,
 * mantendo controle centralizado de preços.
 *
 * ⚠️ CRÍTICO:
 * - Priority 20 (depois de WC setar preços iniciais)
 * - Prevenir recursão com did_action >= 2
 * - Usar cdm_master_variation_id do cart_item_data
 */
final class PriceEnforcer {

	/**
	 * Inicializa hooks
	 *
	 * @return void
	 */
	public function init(): void {
		// Hook: woocommerce_before_calculate_totals
		// Priority 20 (depois de WC setar preços iniciais mas antes de totals)
		add_action( 'woocommerce_before_calculate_totals', array( $this, 'enforce_master_prices' ), 20, 1 );
	}

	/**
	 * Enforça preços do mestre nos clones
	 *
	 * @param \WC_Cart $cart Carrinho do WooCommerce.
	 * @return void
	 */
	public function enforce_master_prices( \WC_Cart $cart ): void {
		// Prevenir recursão
		if ( did_action( 'woocommerce_before_calculate_totals' ) >= 2 ) {
			return;
		}

		// Ignorar no admin (exceto AJAX)
		if ( is_admin() && ! wp_doing_ajax() ) {
			return;
		}

		foreach ( $cart->get_cart() as $cart_item_key => $cart_item ) {
			// Apenas processar itens roteados
			if ( ! isset( $cart_item['cdm_routed'] ) || ! $cart_item['cdm_routed'] ) {
				continue;
			}

			$master_price = $this->get_master_price( $cart_item );

			if ( null === $master_price ) {
				continue;
			}

			// Sobrescrever preço do clone com preço do mestre
			$cart_item['data']->set_price( (float) $master_price );

			// Hook customizado para extensibilidade
			do_action(
				'cdm_price_enforced',
				$cart_item_key,
				$cart_item['product_id'],
				$cart_item['cdm_master_id'],
				$master_price
			);
		}
	}

	/**
	 * Obtém preço do produto mestre
	 *
	 * @param array $cart_item Item do carrinho.
	 * @return float|null
	 */
	private function get_master_price( array $cart_item ): ?float {
		// Se tem variação mestre, usar preço da variação
		if ( isset( $cart_item['cdm_master_variation_id'] ) && $cart_item['cdm_master_variation_id'] > 0 ) {
			$master_variation_id = (int) $cart_item['cdm_master_variation_id'];
			$price               = get_post_meta( $master_variation_id, '_price', true );

			if ( '' !== $price && null !== $price ) {
				return (float) $price;
			}
		}

		// Fallback: usar preço do produto pai mestre
		if ( isset( $cart_item['cdm_master_id'] ) ) {
			$master_id = (int) $cart_item['cdm_master_id'];
			$product   = wc_get_product( $master_id );

			if ( $product ) {
				return (float) $product->get_price();
			}
		}

		// Log de erro se não conseguir obter preço
		$this->log_error( 'Não foi possível obter preço do mestre', array(
			'cart_item' => $cart_item,
		) );

		return null;
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
			$logger->error( $message, array_merge( array( 'source' => 'cdm-price-enforcer' ), $context ) );
		}
	}
}
