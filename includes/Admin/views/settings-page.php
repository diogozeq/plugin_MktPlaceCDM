<?php
/**
 * Admin settings page view.
 *
 * @package CDM_Catalog_Router
 */

declare(strict_types=1);

?>
<div class="wrap cdm-admin-wrapper">
	<div class="cdm-admin-header">
		<h1><?php echo esc_html__( 'Catalog Router', 'cdm-catalog-router' ); ?></h1>
		<p><?php echo esc_html__( 'Visao rapida do funcionamento, CEPs dos sellers e logs recentes.', 'cdm-catalog-router' ); ?></p>
	</div>

	<?php if ( $settings_updated ) : ?>
		<div class="notice notice-success is-dismissible">
			<p><?php echo esc_html__( 'Configuracoes salvas.', 'cdm-catalog-router' ); ?></p>
		</div>
	<?php endif; ?>

	<?php if ( $vendor_updated ) : ?>
		<div class="notice notice-success is-dismissible">
			<p><?php echo esc_html__( 'CEPs dos sellers atualizados.', 'cdm-catalog-router' ); ?></p>
		</div>
	<?php endif; ?>

	<section class="cdm-settings-section">
		<h2><?php echo esc_html__( 'Visao rapida', 'cdm-catalog-router' ); ?></h2>
		<div class="cdm-admin-cards">
			<?php foreach ( $status_cards as $card ) : ?>
				<div class="cdm-admin-card">
					<div class="cdm-admin-card-label"><?php echo esc_html( $card['label'] ); ?></div>
					<div class="cdm-admin-card-value"><?php echo esc_html( $card['value'] ); ?></div>
				</div>
			<?php endforeach; ?>
		</div>
	</section>

	<section class="cdm-settings-section">
		<h2><?php echo esc_html__( 'Configuracao rapida', 'cdm-catalog-router' ); ?></h2>
		<form method="post" action="options.php">
			<?php settings_fields( 'cdm_settings_group' ); ?>
			<?php do_settings_sections( 'cdm_settings_page' ); ?>
			<?php submit_button( esc_html__( 'Salvar configuracoes', 'cdm-catalog-router' ) ); ?>
		</form>
	</section>

	<section class="cdm-settings-section">
		<h2><?php echo esc_html__( 'CEPs dos sellers', 'cdm-catalog-router' ); ?></h2>
		<p class="description">
			<?php echo esc_html__( 'Informe uma zona por linha ou separada por virgula. Ex: 01001000, 01001, 01001000...01009999, 0100*', 'cdm-catalog-router' ); ?>
		</p>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( 'cdm_save_vendor_ceps', 'cdm_vendor_ceps_nonce' ); ?>
			<input type="hidden" name="action" value="cdm_save_vendor_ceps" />
			<input type="hidden" name="cdm_page" value="<?php echo esc_attr( (string) $vendor_data['page'] ); ?>" />

			<?php if ( empty( $vendor_data['vendors'] ) ) : ?>
				<p><?php echo esc_html__( 'Nenhum vendedor ativo encontrado.', 'cdm-catalog-router' ); ?></p>
			<?php else : ?>
				<table class="widefat striped cdm-vendor-table">
					<thead>
						<tr>
							<th><?php echo esc_html__( 'Vendedor', 'cdm-catalog-router' ); ?></th>
							<th><?php echo esc_html__( 'Contato', 'cdm-catalog-router' ); ?></th>
							<th><?php echo esc_html__( 'CEPs / zonas', 'cdm-catalog-router' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $vendor_data['vendors'] as $vendor ) : ?>
							<?php
							$vendor_id = (int) $vendor->ID;
							$zones_text = \CDM\VendorCepStorage::get_vendor_zones_text( $vendor_id );
							?>
							<tr>
								<td>
									<strong><?php echo esc_html( $vendor->display_name ); ?></strong><br />
									<code><?php echo esc_html( (string) $vendor_id ); ?></code>
								</td>
								<td><?php echo esc_html( $vendor->user_email ); ?></td>
								<td>
									<textarea class="cdm-cep-textarea" name="cdm_vendor_cep_zones[<?php echo esc_attr( (string) $vendor_id ); ?>]"><?php echo esc_textarea( $zones_text ); ?></textarea>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
				<?php submit_button( esc_html__( 'Salvar CEPs', 'cdm-catalog-router' ) ); ?>
			<?php endif; ?>
		</form>

		<?php if ( $vendor_data['total_pages'] > 1 ) : ?>
			<div class="cdm-pagination">
				<?php
				$prev_page = max( 1, $vendor_data['page'] - 1 );
				$next_page = min( $vendor_data['total_pages'], $vendor_data['page'] + 1 );
				?>
				<?php if ( $vendor_data['page'] > 1 ) : ?>
					<a class="button" href="<?php echo esc_url( add_query_arg( array( 'page' => 'cdm-catalog-router', 'cdm_page' => $prev_page ), admin_url( 'admin.php' ) ) ); ?>">
						<?php echo esc_html__( 'Anterior', 'cdm-catalog-router' ); ?>
					</a>
				<?php endif; ?>
				<span class="cdm-pagination-info">
					<?php
					printf(
						/* translators: 1: current page, 2: total pages */
						esc_html__( 'Pagina %1$d de %2$d', 'cdm-catalog-router' ),
						(int) $vendor_data['page'],
						(int) $vendor_data['total_pages']
					);
					?>
				</span>
				<?php if ( $vendor_data['page'] < $vendor_data['total_pages'] ) : ?>
					<a class="button" href="<?php echo esc_url( add_query_arg( array( 'page' => 'cdm-catalog-router', 'cdm_page' => $next_page ), admin_url( 'admin.php' ) ) ); ?>">
						<?php echo esc_html__( 'Proxima', 'cdm-catalog-router' ); ?>
					</a>
				<?php endif; ?>
			</div>
		<?php endif; ?>
	</section>

	<section class="cdm-settings-section">
		<h2><?php echo esc_html__( 'Logs curtos', 'cdm-catalog-router' ); ?></h2>
		<p class="description">
			<?php echo esc_html__( 'Trechos dos logs mais recentes para confirmar o funcionamento.', 'cdm-catalog-router' ); ?>
			<a href="<?php echo esc_url( $logs_page_url ); ?>">
				<?php echo esc_html__( 'Abrir logs completos.', 'cdm-catalog-router' ); ?>
			</a>
		</p>
		<div class="cdm-log-grid">
			<?php foreach ( $log_cards as $log_card ) : ?>
				<div class="cdm-log-card">
					<h3><?php echo esc_html( $log_card['title'] ); ?></h3>
					<?php if ( '' !== $log_card['excerpt'] ) : ?>
						<pre class="cdm-log-box"><?php echo esc_html( $log_card['excerpt'] ); ?></pre>
					<?php else : ?>
						<p class="description"><?php echo esc_html( $log_card['empty'] ); ?></p>
					<?php endif; ?>
					<?php if ( '' !== $log_card['link'] ) : ?>
						<p>
							<a href="<?php echo esc_url( $log_card['link'] ); ?>">
								<?php echo esc_html__( 'Ver log completo', 'cdm-catalog-router' ); ?>
							</a>
						</p>
					<?php endif; ?>
				</div>
			<?php endforeach; ?>
		</div>
	</section>
</div>
