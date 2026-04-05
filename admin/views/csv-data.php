<?php
/**
 * Admin: uploaded CSV datasets.
 *
 * @var list<array{name:string,path:string,size:int,mtime:int}> $wsp_csv_files
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$wsp_csv_msg   = isset( $_GET['wsp_csv_msg'] ) ? sanitize_key( wp_unslash( $_GET['wsp_csv_msg'] ) ) : '';
$wsp_csv_file  = isset( $_GET['wsp_csv_file'] ) ? sanitize_file_name( wp_unslash( $_GET['wsp_csv_file'] ) ) : '';
$upload_dir    = WorldStat_Uploaded_Csv::get_dir();
$wsp_csv_page = admin_url( 'admin.php?page=worldstat-csv' );
?>
<div class="wrap wsp-admin-wrap">
	<h1 class="wsp-admin-title">
		<span class="dashicons dashicons-media-spreadsheet"></span>
		<?php esc_html_e( 'Данные CSV', 'flavor-worldstat' ); ?>
	</h1>

	<?php if ( $wsp_csv_msg === 'upload_ok' && $wsp_csv_file ) : ?>
		<?php $wsp_proc_log = WorldStat_Uploaded_Csv::take_process_log_flash(); ?>
		<div class="notice notice-success is-dismissible"><p>
			<?php
			echo esc_html(
				sprintf(
					/* translators: %s: file name */
					__( 'Файл «%s» прошёл очистку по схеме (8 шагов) и сохранён.', 'flavor-worldstat' ),
					$wsp_csv_file
				)
			);
			?>
		</p></div>
		<?php if ( ! empty( $wsp_proc_log ) ) : ?>
			<div class="wsp-admin-section" style="margin-top:12px;">
				<details>
					<summary><?php esc_html_e( 'Журнал этапов обработки', 'flavor-worldstat' ); ?></summary>
					<ol style="margin:10px 0 0 18px; font-size:13px;">
						<?php foreach ( $wsp_proc_log as $line ) : ?>
							<li><code><?php echo esc_html( $line ); ?></code></li>
						<?php endforeach; ?>
					</ol>
				</details>
			</div>
		<?php endif; ?>
	<?php elseif ( $wsp_csv_msg === 'delete_ok' && $wsp_csv_file ) : ?>
		<div class="notice notice-success is-dismissible"><p>
			<?php
			echo esc_html(
				sprintf(
					/* translators: %s: file name */
					__( 'Файл «%s» удалён.', 'flavor-worldstat' ),
					$wsp_csv_file
				)
			);
			?>
		</p></div>
	<?php elseif ( $wsp_csv_msg === 'error' ) : ?>
		<?php
		$wsp_csv_err_text = WorldStat_Uploaded_Csv::take_admin_error_flash();
		if ( $wsp_csv_err_text !== '' ) :
			?>
		<div class="notice notice-error is-dismissible"><p>
			<?php echo esc_html( $wsp_csv_err_text ); ?>
		</p></div>
		<?php endif; ?>
	<?php endif; ?>

	<div class="wsp-admin-section">
		<h2><?php esc_html_e( 'Загрузить CSV', 'flavor-worldstat' ); ?></h2>
		<p class="description">
			<?php esc_html_e( 'CSV сначала принимается во временную папку, затем автоматически проходит очистку (дубликаты, пропуски, типы, выбросы, строки и т.д.). В каталог данных попадает только успешно обработанный файл.', 'flavor-worldstat' ); ?>
		</p>
		<?php if ( $upload_dir === '' ) : ?>
			<p class="notice notice-error inline"><strong><?php esc_html_e( 'Каталог загрузок недоступен. Проверьте права на wp-content/uploads.', 'flavor-worldstat' ); ?></strong></p>
		<?php else : ?>
			<p class="description" style="margin-top:8px;">
				<code><?php echo esc_html( $upload_dir ); ?></code>
			</p>
			<form method="post" enctype="multipart/form-data" action="<?php echo esc_url( $wsp_csv_page ); ?>" style="margin-top:12px;">
				<?php wp_nonce_field( 'wsp_csv_manage', 'wsp_csv_nonce' ); ?>
				<input type="hidden" name="wsp_csv_form_action" value="upload" />
				<p>
					<input type="file" name="wsp_csv_file" accept=".csv,text/csv" required />
				</p>
				<?php
				submit_button(
					__( 'Загрузить', 'flavor-worldstat' ),
					'primary',
					'submit',
					false
				);
				?>
			</form>
		<?php endif; ?>
	</div>

	<div class="wsp-admin-section">
		<h2><?php esc_html_e( 'Загруженные файлы', 'flavor-worldstat' ); ?></h2>
		<?php if ( empty( $wsp_csv_files ) ) : ?>
			<p><?php esc_html_e( 'Пока нет загруженных CSV.', 'flavor-worldstat' ); ?></p>
		<?php else : ?>
			<table class="widefat striped wsp-csv-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Файл', 'flavor-worldstat' ); ?></th>
						<th><?php esc_html_e( 'Размер', 'flavor-worldstat' ); ?></th>
						<th><?php esc_html_e( 'Изменён', 'flavor-worldstat' ); ?></th>
						<th><?php esc_html_e( 'Действия', 'flavor-worldstat' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $wsp_csv_files as $row ) : ?>
						<tr>
							<td><code><?php echo esc_html( $row['name'] ); ?></code></td>
							<td><?php echo esc_html( size_format( $row['size'] ) ); ?></td>
							<td><?php echo esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $row['mtime'] ) ); ?></td>
							<td>
								<?php
								$wsp_del_url = wp_nonce_url(
									add_query_arg(
										[
											'action'              => 'wsp_csv_delete',
											'wsp_csv_delete_name' => $row['name'],
										],
										admin_url( 'admin-post.php' )
									),
									'wsp_csv_delete'
								);
								?>
								<a
									href="<?php echo esc_url( $wsp_del_url ); ?>"
									class="button button-small button-link-delete"
									onclick="return confirm(<?php echo wp_json_encode( __( 'Удалить этот файл?', 'flavor-worldstat' ) ); ?>);"
								><?php esc_html_e( 'Удалить', 'flavor-worldstat' ); ?></a>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
	</div>
</div>
