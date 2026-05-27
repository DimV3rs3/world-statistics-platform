<?php
/**
 * Admin: uploaded CSV datasets.
 *
 * @var list<array{id:int,name:string,dataset_kind:string,size:int,mtime:int}> $wsp_csv_files
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$wsp_csv_msg   = isset( $_GET['wsp_csv_msg'] ) ? sanitize_key( wp_unslash( $_GET['wsp_csv_msg'] ) ) : '';
$wsp_csv_file  = isset( $_GET['wsp_csv_file'] ) ? sanitize_file_name( wp_unslash( $_GET['wsp_csv_file'] ) ) : '';
$wsp_csv_ready = WorldStat_Uploaded_Csv::is_storage_ready();
$wsp_csv_table = WorldStat_Uploaded_Csv::table_name();
$wsp_csv_page   = WorldStat_Admin::csv_data_admin_url();
$wsp_zones_csv  = WorldStat_Admin::is_zones_csv_plugin_active();
$wsp_kind_labels = WorldStat_Uploaded_Csv::dataset_kind_labels();
$wsp_csv_selected_kind = isset( $_GET['wsp_csv_kind'] )
	? WorldStat_Uploaded_Csv::sanitize_dataset_kind( sanitize_key( wp_unslash( $_GET['wsp_csv_kind'] ) ) )
	: WorldStat_Uploaded_Csv::get_last_dataset_kind_for_user( get_current_user_id() );
?>
<div class="wrap wsp-admin-wrap">
	<h1 class="wsp-admin-title">
		<span class="dashicons dashicons-media-spreadsheet"></span>
		<?php esc_html_e( 'Данные CSV', 'flavor-worldstat' ); ?>
	</h1>
	<p>
		<a href="<?php echo esc_url( admin_url( 'admin.php?page=worldstat-csv-translations' ) ); ?>"><?php esc_html_e( 'Переводы показателей — загрузка таблицы подписей', 'flavor-worldstat' ); ?></a>
		<?php if ( $wsp_zones_csv ) : ?>
			| <a href="<?php echo esc_url( admin_url( 'admin.php?page=' . WorldStat_Admin::SLUG_CSV_ZONES ) ); ?>"><?php esc_html_e( 'CSV Import — импорт зон помещений', 'flavor-worldstat' ); ?></a>
		<?php endif; ?>
	</p>

	<?php if ( $wsp_csv_msg === 'upload_ok' && $wsp_csv_file ) : ?>
		<?php $wsp_proc_log = WorldStat_Uploaded_Csv::take_process_log_flash(); ?>
		<div class="notice notice-success is-dismissible"><p>
			<?php
			echo esc_html(
				sprintf(
					/* translators: %s: file name */
					__( 'Файл «%s» прошёл очистку по схеме (8 шагов) и сохранён в базе данных.', 'flavor-worldstat' ),
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
		<h2><?php esc_html_e( 'Как устроены данные', 'flavor-worldstat' ); ?></h2>
		<ol style="margin:0 0 1em 1.4em; max-width: 920px;">
			<li>
				<strong><?php esc_html_e( 'Подготовьте CSV', 'flavor-worldstat' ); ?></strong> —
				<?php esc_html_e( 'кодировка UTF-8, разделитель — запятая. В первой строке — заголовки столбцов. Код страны — ISO 3166-1 alpha-3 (три буквы: USA, DEU, RUS). Год — целое число в колонке year.', 'flavor-worldstat' ); ?>
			</li>
			<li>
				<strong><?php esc_html_e( 'Выберите тип набора', 'flavor-worldstat' ); ?></strong> —
				<?php esc_html_e( 'от типа зависит, куда попадут столбцы после загрузки (см. блок «Тип данных» ниже).', 'flavor-worldstat' ); ?>
			</li>
			<li>
				<strong><?php esc_html_e( 'Загрузите файл', 'flavor-worldstat' ); ?></strong> —
				<?php esc_html_e( 'файл проходит автоматическую очистку (дубликаты, пропуски, типы, выбросы), затем сохраняется в таблицу WordPress — отдельные CSV на диске не копятся.', 'flavor-worldstat' ); ?>
			</li>
			<li>
				<strong><?php esc_html_e( 'Переводы подписей (по желанию)', 'flavor-worldstat' ); ?></strong> —
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=worldstat-csv-translations' ) ); ?>"><?php esc_html_e( 'страница «Переводы»', 'flavor-worldstat' ); ?></a>:
				<?php esc_html_e( 'технические ключи столбцов (road_length, urban_pct…) сопоставьте с русскими подписями для графиков и таблиц на сайте.', 'flavor-worldstat' ); ?>
			</li>
		</ol>

		<h3 style="margin-top:1.25em;"><?php esc_html_e( 'Форматы столбцов', 'flavor-worldstat' ); ?></h3>
		<dl style="margin:0; max-width: 920px;">
			<dt style="font-weight:600;margin-top:10px;"><?php esc_html_e( 'Длинный (long)', 'flavor-worldstat' ); ?></dt>
			<dd style="margin:4px 0 0 0;">
				<code>country_code</code>, <code>year</code>, <em><?php esc_html_e( 'одна метрика', 'flavor-worldstat' ); ?></em>
				<?php esc_html_e( '— удобен для одного показателя на файл; пример в образце «Показатели страны».', 'flavor-worldstat' ); ?>
			</dd>
			<dt style="font-weight:600;margin-top:10px;"><?php esc_html_e( 'Широкий (wide)', 'flavor-worldstat' ); ?></dt>
			<dd style="margin:4px 0 0 0;">
				<code>country_code</code>, <code>year</code>, <em><?php esc_html_e( 'несколько числовых столбцов', 'flavor-worldstat' ); ?></em>
				<?php esc_html_e( '— каждый столбец после year — отдельный показатель; примеры в образцах «Индикаторы для расчётов» и «Показатели страны + расчёты».', 'flavor-worldstat' ); ?>
			</dd>
		</dl>

		<h3 style="margin-top:1.25em;"><?php esc_html_e( 'Куда попадают данные по типу', 'flavor-worldstat' ); ?></h3>
		<table class="widefat striped" style="max-width:920px;margin-top:8px;">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Тип', 'flavor-worldstat' ); ?></th>
					<th><?php esc_html_e( 'На сайте (страницы стран)', 'flavor-worldstat' ); ?></th>
					<th><?php esc_html_e( 'Расчёты эргономичности', 'flavor-worldstat' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<tr>
					<td><?php echo esc_html( $wsp_kind_labels[ WorldStat_Uploaded_Csv::KIND_COUNTRY ] ?? '' ); ?></td>
					<td><?php esc_html_e( 'Да — мета стран, блок «Данные из загруженных CSV» в обзоре.', 'flavor-worldstat' ); ?></td>
					<td><?php esc_html_e( 'Нет', 'flavor-worldstat' ); ?></td>
				</tr>
				<tr>
					<td><?php echo esc_html( $wsp_kind_labels[ WorldStat_Uploaded_Csv::KIND_INDICATOR ] ?? '' ); ?></td>
					<td><?php esc_html_e( 'Нет — только внутренние расчёты.', 'flavor-worldstat' ); ?></td>
					<td><?php esc_html_e( 'Да', 'flavor-worldstat' ); ?></td>
				</tr>
				<tr>
					<td><?php echo esc_html( $wsp_kind_labels[ WorldStat_Uploaded_Csv::KIND_COMBINED ] ?? '' ); ?></td>
					<td><?php esc_html_e( 'Да', 'flavor-worldstat' ); ?></td>
					<td><?php esc_html_e( 'Да', 'flavor-worldstat' ); ?></td>
				</tr>
			</tbody>
		</table>
	</div>

	<?php if ( class_exists( 'WorldStat_Csv_Samples' ) ) : ?>
	<div class="wsp-admin-section">
		<h2><?php esc_html_e( 'Скачать примеры данных', 'flavor-worldstat' ); ?></h2>
		<p class="description" style="max-width:920px;">
			<?php esc_html_e( 'Демонстрационные файлы (USA, DEU, RUS, ABW, годы 2020–2022). Скачайте нужный тип, отредактируйте под свои показатели и загрузите обратно с тем же типом набора.', 'flavor-worldstat' ); ?>
		</p>
		<p style="margin-top:12px;display:flex;flex-wrap:wrap;gap:8px;">
			<a class="button" href="<?php echo esc_url( WorldStat_Csv_Samples::download_url( WorldStat_Uploaded_Csv::KIND_COUNTRY ) ); ?>">
				<span class="dashicons dashicons-download" style="margin-top:3px;"></span>
				<?php
				echo esc_html(
					sprintf(
						/* translators: %s: dataset kind label */
						__( 'Пример: %s', 'flavor-worldstat' ),
						$wsp_kind_labels[ WorldStat_Uploaded_Csv::KIND_COUNTRY ] ?? ''
					)
				);
				?>
			</a>
			<a class="button" href="<?php echo esc_url( WorldStat_Csv_Samples::download_url( WorldStat_Uploaded_Csv::KIND_INDICATOR ) ); ?>">
				<span class="dashicons dashicons-download" style="margin-top:3px;"></span>
				<?php
				echo esc_html(
					sprintf(
						__( 'Пример: %s', 'flavor-worldstat' ),
						$wsp_kind_labels[ WorldStat_Uploaded_Csv::KIND_INDICATOR ] ?? ''
					)
				);
				?>
			</a>
			<a class="button" href="<?php echo esc_url( WorldStat_Csv_Samples::download_url( WorldStat_Uploaded_Csv::KIND_COMBINED ) ); ?>">
				<span class="dashicons dashicons-download" style="margin-top:3px;"></span>
				<?php
				echo esc_html(
					sprintf(
						__( 'Пример: %s', 'flavor-worldstat' ),
						$wsp_kind_labels[ WorldStat_Uploaded_Csv::KIND_COMBINED ] ?? ''
					)
				);
				?>
			</a>
		</p>
	</div>
	<?php endif; ?>

	<div class="wsp-admin-section">
		<h2><?php esc_html_e( 'Загрузить CSV', 'flavor-worldstat' ); ?></h2>
		<p class="description">
			<?php esc_html_e( 'CSV принимается во временную папку, проходит очистку (дубликаты, пропуски, типы, выбросы, строки и т.д.), затем сохраняется в таблицу MySQL WordPress. Постоянное хранение — в базе, не в виде отдельных CSV-файлов.', 'flavor-worldstat' ); ?>
		</p>
		<?php if ( ! $wsp_csv_ready ) : ?>
			<p class="notice notice-error inline"><strong><?php esc_html_e( 'Хранилище недоступно: проверьте права на wp-content/uploads и что таблица БД создана (переактивируйте плагин при необходимости).', 'flavor-worldstat' ); ?></strong></p>
		<?php else : ?>
			<p class="description" style="margin-top:8px;">
				<?php esc_html_e( 'Таблица:', 'flavor-worldstat' ); ?>
				<code><?php echo esc_html( $wsp_csv_table ); ?></code>
			</p>
			<form method="post" enctype="multipart/form-data" action="<?php echo esc_url( $wsp_csv_page ); ?>" style="margin-top:12px;">
				<?php wp_nonce_field( 'wsp_csv_manage', 'wsp_csv_nonce' ); ?>
				<input type="hidden" name="wsp_csv_form_action" value="upload" />
				<fieldset class="wsp-csv-kind-fieldset" style="margin:12px 0;border:1px solid #c3c4c7;padding:12px 14px;">
					<legend style="font-weight:600;padding:0 6px;">
						<?php esc_html_e( 'Тип данных', 'flavor-worldstat' ); ?>
					</legend>
					<p style="margin:0 0 10px;">
						<label style="display:block;margin-bottom:8px;">
							<input type="radio" name="wsp_csv_dataset_kind" value="<?php echo esc_attr( WorldStat_Uploaded_Csv::KIND_COUNTRY ); ?>" <?php checked( $wsp_csv_selected_kind, WorldStat_Uploaded_Csv::KIND_COUNTRY ); ?> />
							<?php echo esc_html( $wsp_kind_labels[ WorldStat_Uploaded_Csv::KIND_COUNTRY ] ?? '' ); ?>
						</label>
						<span class="description" style="display:block;margin:-4px 0 10px 24px;">
							<?php esc_html_e( 'Справочные ряды: дороги, население, площадь и т.п. Попадают в мета стран и в блок «Данные из загруженных CSV» на странице страны. Формат long или wide; в образце — long (country_code, year, road_length).', 'flavor-worldstat' ); ?>
						</span>
						<label style="display:block;margin-bottom:4px;">
							<input type="radio" name="wsp_csv_dataset_kind" value="<?php echo esc_attr( WorldStat_Uploaded_Csv::KIND_INDICATOR ); ?>" <?php checked( $wsp_csv_selected_kind, WorldStat_Uploaded_Csv::KIND_INDICATOR ); ?> />
							<?php echo esc_html( $wsp_kind_labels[ WorldStat_Uploaded_Csv::KIND_INDICATOR ] ?? '' ); ?>
						</label>
						<span class="description" style="display:block;margin:0 0 10px 24px;">
							<?php esc_html_e( 'Только для расчётов (индекс эргономичности и др.): на страницах стран не отображаются. Обычно wide: country_code, year и несколько метрик (pop_dens_km2, urban_pct…). См. образец выше.', 'flavor-worldstat' ); ?>
						</span>
						<label style="display:block;margin-bottom:4px;">
							<input type="radio" name="wsp_csv_dataset_kind" value="<?php echo esc_attr( WorldStat_Uploaded_Csv::KIND_COMBINED ); ?>" <?php checked( $wsp_csv_selected_kind, WorldStat_Uploaded_Csv::KIND_COMBINED ); ?> />
							<?php echo esc_html( $wsp_kind_labels[ WorldStat_Uploaded_Csv::KIND_COMBINED ] ?? '' ); ?>
						</label>
						<span class="description" style="display:block;margin:0 0 0 24px;">
							<?php esc_html_e( 'Один файл и для карточек стран, и для макрорасчётов: wide с справочными и расчётными столбцами (population_total, urban_pct…). См. объединённый образец.', 'flavor-worldstat' ); ?>
						</span>
					</p>
				</fieldset>
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
						<th><?php esc_html_e( 'Тип', 'flavor-worldstat' ); ?></th>
						<th><?php esc_html_e( 'Размер', 'flavor-worldstat' ); ?></th>
						<th><?php esc_html_e( 'Изменён', 'flavor-worldstat' ); ?></th>
						<th><?php esc_html_e( 'Действия', 'flavor-worldstat' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $wsp_csv_files as $row ) : ?>
						<tr>
							<td><code><?php echo esc_html( $row['name'] ); ?></code></td>
							<td><?php echo esc_html( $wsp_kind_labels[ $row['dataset_kind'] ] ?? $row['dataset_kind'] ); ?></td>
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
