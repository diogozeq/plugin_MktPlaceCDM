<?php
/**
 * Admin page for Catalog Router
 *
 * @package CDM_Catalog_Router
 */

declare(strict_types=1);

namespace CDM\Admin;

use CDM\VendorCepStorage;

/**
 * Handles admin menu, settings and vendor CEP management.
 */
final class AdminPage {

	private const MENU_SLUG     = 'cdm-catalog-router';
	private const SETTINGS_PAGE = 'cdm_settings_page';
	private const PER_PAGE      = 20;

	/**
	 * Plugin version.
	 *
	 * @var string
	 */
	private string $version;

	/**
	 * Constructor.
	 *
	 * @param string $version Plugin version.
	 */
	public function __construct( string $version ) {
		$this->version = $version;
	}

	/**
	 * Register admin hooks.
	 *
	 * @return void
	 */
	public function init(): void {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_post_cdm_save_vendor_ceps', array( $this, 'handle_vendor_ceps_save' ) );
	}

	/**
	 * Register submenu under WooCommerce.
	 *
	 * @return void
	 */
	public function register_menu(): void {
		// Usa manage_options como fallback se manage_woocommerce não existir
		$capability = 'manage_options';
		if ( class_exists( 'WooCommerce' ) ) {
			$capability = 'manage_woocommerce';
		}

		add_menu_page(
			esc_html__( 'MktPlace CDM', 'cdm-catalog-router' ),
			esc_html__( 'MktPlace CDM', 'cdm-catalog-router' ),
			$capability,
			self::MENU_SLUG,
			array( $this, 'render_page' ),
			'dashicons-networking',
			60
		);
	}

	/**
	 * Register settings with Settings API.
	 *
	 * @return void
	 */
	public function register_settings(): void {
		register_setting(
			'cdm_settings_group',
			'cdm_routing_strategy',
			array(
				'type'              => 'string',
				'sanitize_callback' => array( $this, 'sanitize_routing_strategy' ),
				'default'           => 'cep',
			)
		);

		register_setting(
			'cdm_settings_group',
			'cdm_enable_logging',
			array(
				'type'              => 'boolean',
				'sanitize_callback' => array( $this, 'sanitize_enable_logging' ),
				'default'           => true,
			)
		);

		add_settings_section(
			'cdm_settings_main',
			esc_html__( 'Configuracao basica', 'cdm-catalog-router' ),
			array( $this, 'render_settings_section' ),
			self::SETTINGS_PAGE
		);

		add_settings_field(
			'cdm_routing_strategy',
			esc_html__( 'Estrategia de roteamento', 'cdm-catalog-router' ),
			array( $this, 'render_routing_strategy_field' ),
			self::SETTINGS_PAGE,
			'cdm_settings_main'
		);

		add_settings_field(
			'cdm_enable_logging',
			esc_html__( 'Logs do roteamento', 'cdm-catalog-router' ),
			array( $this, 'render_enable_logging_field' ),
			self::SETTINGS_PAGE,
			'cdm_settings_main'
		);
	}

	/**
	 * Render settings section description.
	 *
	 * @return void
	 */
	public function render_settings_section(): void {
		echo '<p>' . esc_html__( 'Ajustes rapidos para diagnostico e roteamento.', 'cdm-catalog-router' ) . '</p>';
	}

	/**
	 * Render routing strategy field.
	 *
	 * @return void
	 */
	public function render_routing_strategy_field(): void {
		$value = (string) get_option( 'cdm_routing_strategy', 'cep' );
		?>
		<select name="cdm_routing_strategy">
			<option value="cep" <?php selected( $value, 'cep' ); ?>>
				<?php echo esc_html__( 'CEP preferencial', 'cdm-catalog-router' ); ?>
			</option>
			<option value="fairness" <?php selected( $value, 'fairness' ); ?>>
				<?php echo esc_html__( 'Fairness global', 'cdm-catalog-router' ); ?>
			</option>
			<option value="stock" <?php selected( $value, 'stock' ); ?>>
				<?php echo esc_html__( 'Fallback por estoque', 'cdm-catalog-router' ); ?>
			</option>
		</select>
		<?php
	}

	/**
	 * Render enable logging field.
	 *
	 * @return void
	 */
	public function render_enable_logging_field(): void {
		$enabled = (bool) get_option( 'cdm_enable_logging', true );
		?>
		<label>
			<input type="hidden" name="cdm_enable_logging" value="0" />
			<input type="checkbox" name="cdm_enable_logging" value="1" <?php checked( $enabled ); ?> />
			<?php echo esc_html__( 'Registrar logs no WooCommerce', 'cdm-catalog-router' ); ?>
		</label>
		<?php
	}

	/**
	 * Sanitize routing strategy.
	 *
	 * @param string $value Raw value.
	 * @return string
	 */
	public function sanitize_routing_strategy( string $value ): string {
		$allowed = array( 'cep', 'fairness', 'stock' );
		return in_array( $value, $allowed, true ) ? $value : 'cep';
	}

	/**
	 * Sanitize logging toggle.
	 *
	 * @param mixed $value Raw value.
	 * @return bool
	 */
	public function sanitize_enable_logging( $value ): bool {
		return ! empty( $value );
	}

	/**
	 * Handle vendor CEPs save.
	 *
	 * @return void
	 */
	public function handle_vendor_ceps_save(): void {
		$capability = class_exists( 'WooCommerce' ) ? 'manage_woocommerce' : 'manage_options';
		if ( ! current_user_can( $capability ) ) {
			wp_die( esc_html__( 'Sem permissao para salvar.', 'cdm-catalog-router' ) );
		}

		check_admin_referer( 'cdm_save_vendor_ceps', 'cdm_vendor_ceps_nonce' );

		$vendor_ceps = $_POST['cdm_vendor_cep_zones'] ?? array();
		$vendor_ceps = is_array( $vendor_ceps ) ? wp_unslash( $vendor_ceps ) : array();

		foreach ( $vendor_ceps as $vendor_id => $zones_text ) {
			$vendor_id = (int) $vendor_id;
			if ( $vendor_id <= 0 ) {
				continue;
			}

			$text = is_string( $zones_text ) ? $zones_text : '';
			$clean = VendorCepStorage::sanitize_zone_text( $text );

			if ( '' === $clean ) {
				delete_user_meta( $vendor_id, VendorCepStorage::META_KEY );
				delete_transient( "cdm_vendor_cep_zones_{$vendor_id}" );
				continue;
			}

			update_user_meta( $vendor_id, VendorCepStorage::META_KEY, $clean );
			delete_transient( "cdm_vendor_cep_zones_{$vendor_id}" );
		}

		$page = isset( $_POST['cdm_page'] ) ? (int) $_POST['cdm_page'] : 1;
		$page = max( 1, $page );

		$redirect = add_query_arg(
			array(
				'page'        => self::MENU_SLUG,
				'cdm_updated' => '1',
				'cdm_page'    => $page,
				'tab'         => 'vendors',
			),
			admin_url( 'admin.php' )
		);

		wp_safe_redirect( $redirect );
		exit;
	}

	/**
	 * Render admin page.
	 *
	 * @return void
	 */
	public function render_page(): void {
		$capability = class_exists( 'WooCommerce' ) ? 'manage_woocommerce' : 'manage_options';
		if ( ! current_user_can( $capability ) ) {
			return;
		}

		$page = $this->get_current_page();
		$vendor_data = $this->get_vendor_page_data( $page, self::PER_PAGE );
		$status_cards = $this->get_status_cards();
		$log_cards = $this->get_log_cards();
		$logs_page_url = admin_url( 'admin.php?page=wc-status&tab=logs' );
		$settings_updated = ! empty( $_GET['settings-updated'] );
		$vendor_updated = isset( $_GET['cdm_updated'] ) && '1' === $_GET['cdm_updated'];

		$template = CDM_PLUGIN_DIR . 'includes/Admin/views/settings-page.php';
		if ( file_exists( $template ) ) {
			include $template;
			return;
		}

		echo '<div class="wrap"><h1>' . esc_html__( 'Catalog Router', 'cdm-catalog-router' ) . '</h1></div>';
	}

	/**
	 * Get current vendor page.
	 *
	 * @return int
	 */
	private function get_current_page(): int {
		$page = isset( $_GET['cdm_page'] ) ? (int) $_GET['cdm_page'] : 1;
		return max( 1, $page );
	}

	/**
	 * Build status cards data.
	 *
	 * @return array<int, array<string, string>>
	 */
	private function get_status_cards(): array {
		$strategy = (string) get_option( 'cdm_routing_strategy', 'cep' );
		$logging = (bool) get_option( 'cdm_enable_logging', true );

		return array(
			array(
				'label' => __( 'Estrategia ativa', 'cdm-catalog-router' ),
				'value' => $this->get_strategy_label( $strategy ),
			),
			array(
				'label' => __( 'Logging', 'cdm-catalog-router' ),
				'value' => $logging ? __( 'Ativo', 'cdm-catalog-router' ) : __( 'Desligado', 'cdm-catalog-router' ),
			),
			array(
				'label' => __( 'Vendedores ativos', 'cdm-catalog-router' ),
				'value' => (string) $this->get_active_vendor_count(),
			),
			array(
				'label' => __( 'Vendedores com CEP', 'cdm-catalog-router' ),
				'value' => (string) $this->get_vendors_with_ceps_count(),
			),
		);
	}

	/**
	 * Build log cards data.
	 *
	 * @return array<int, array<string, string>>
	 */
	private function get_log_cards(): array {
		$cards = array();
		$handles = array(
			'cdm-router-engine'   => __( 'Router Engine', 'cdm-catalog-router' ),
			'cdm-cart-interceptor' => __( 'Cart Interceptor', 'cdm-catalog-router' ),
		);

		foreach ( $handles as $handle => $title ) {
			$excerpt = $this->get_log_excerpt( $handle, 10 );
			$link = $excerpt['file'] ? $this->build_log_link( $excerpt['file'] ) : '';

			$cards[] = array(
				'title'   => $title,
				'handle'  => $handle,
				'link'    => $link,
				'excerpt' => $excerpt['content'],
				'empty'   => $excerpt['empty_notice'],
			);
		}

		return $cards;
	}

	/**
	 * Get vendor list and pagination.
	 *
	 * @param int $page Current page.
	 * @param int $per_page Items per page.
	 * @return array<string, mixed>
	 */
	private function get_vendor_page_data( int $page, int $per_page ): array {
		$query = new \WP_User_Query(
			array(
				'number'       => $per_page,
				'offset'       => ( $page - 1 ) * $per_page,
				'orderby'      => 'ID',
				'order'        => 'ASC',
				'meta_key'     => 'dokan_enable_selling',
				'meta_value'   => 'yes',
				'fields'       => array( 'ID', 'display_name', 'user_email' ),
				'count_total'  => true,
			)
		);

		$total = (int) $query->get_total();
		$total_pages = $per_page > 0 ? (int) ceil( $total / $per_page ) : 1;

		return array(
			'vendors'     => $query->get_results(),
			'total'       => $total,
			'total_pages' => max( 1, $total_pages ),
			'page'        => $page,
			'per_page'    => $per_page,
		);
	}

	/**
	 * Count active vendors.
	 *
	 * @return int
	 */
	private function get_active_vendor_count(): int {
		$query = new \WP_User_Query(
			array(
				'number'      => 1,
				'meta_key'    => 'dokan_enable_selling',
				'meta_value'  => 'yes',
				'fields'      => 'ID',
				'count_total' => true,
			)
		);

		return (int) $query->get_total();
	}

	/**
	 * Count vendors with CEPs stored manually.
	 *
	 * @return int
	 */
	private function get_vendors_with_ceps_count(): int {
		$query = new \WP_User_Query(
			array(
				'number'      => 1,
				'meta_key'    => VendorCepStorage::META_KEY,
				'meta_value'  => '',
				'meta_compare'=> '!=',
				'fields'      => 'ID',
				'count_total' => true,
			)
		);

		return (int) $query->get_total();
	}

	/**
	 * Build log excerpt for a given handle.
	 *
	 * @param string $handle Log handle.
	 * @param int    $lines Number of lines.
	 * @return array<string, string>
	 */
	private function get_log_excerpt( string $handle, int $lines ): array {
		$empty_notice = __( 'Nenhum log encontrado.', 'cdm-catalog-router' );
		$content = '';
		$file = '';

		if ( function_exists( 'wc_get_log_file_path' ) ) {
			$path = wc_get_log_file_path( $handle );
			if ( is_string( $path ) && '' !== $path && is_readable( $path ) ) {
				$file = basename( $path );
				$content = $this->tail_file( $path, $lines );
				if ( '' === $content ) {
					$empty_notice = __( 'Log vazio no momento.', 'cdm-catalog-router' );
				}
			} else {
				$empty_notice = __( 'Log ainda nao foi criado.', 'cdm-catalog-router' );
			}
		} else {
			$empty_notice = __( 'Log handler indisponivel.', 'cdm-catalog-router' );
		}

		return array(
			'content'      => $content,
			'file'         => $file,
			'empty_notice' => $empty_notice,
		);
	}

	/**
	 * Build link to WooCommerce log viewer.
	 *
	 * @param string $log_file Log file name.
	 * @return string
	 */
	private function build_log_link( string $log_file ): string {
		return admin_url( 'admin.php?page=wc-status&tab=logs&log_file=' . rawurlencode( $log_file ) );
	}

	/**
	 * Tail a file without loading everything in memory.
	 *
	 * @param string $path File path.
	 * @param int    $lines Number of lines.
	 * @return string
	 */
	private function tail_file( string $path, int $lines ): string {
		$handle = fopen( $path, 'rb' );
		if ( false === $handle ) {
			return '';
		}

		$buffer = '';
		$chunk_size = 4096;
		fseek( $handle, 0, SEEK_END );
		$position = ftell( $handle );

		while ( $position > 0 && substr_count( $buffer, "\n" ) <= $lines ) {
			$read_size = min( $chunk_size, $position );
			$position -= $read_size;
			fseek( $handle, $position );
			$data = fread( $handle, $read_size );
			if ( false === $data ) {
				break;
			}
			$buffer = $data . $buffer;
		}

		fclose( $handle );

		$buffer = trim( $buffer );
		if ( '' === $buffer ) {
			return '';
		}

		$lines_array = preg_split( '/\r\n|\r|\n/', $buffer );
		if ( ! is_array( $lines_array ) ) {
			return '';
		}

		$lines_array = array_slice( $lines_array, -$lines );
		return implode( "\n", $lines_array );
	}

	/**
	 * Get label for routing strategy.
	 *
	 * @param string $strategy Strategy slug.
	 * @return string
	 */
	private function get_strategy_label( string $strategy ): string {
		return match ( $strategy ) {
			'fairness' => __( 'Fairness global', 'cdm-catalog-router' ),
			'stock'    => __( 'Fallback por estoque', 'cdm-catalog-router' ),
			default    => __( 'CEP preferencial', 'cdm-catalog-router' ),
		};
	}
}
