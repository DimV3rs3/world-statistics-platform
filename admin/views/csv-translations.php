<?php
/**
 * Импорт русских подписей к ключам показателей (опция плагина эргономики).
 *
 * @package WorldStatPlatform
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$wsp_tr_msg = isset( $_GET['wsp_tr_msg'] ) ? sanitize_key( wp_unslash( $_GET['wsp_tr_msg'] ) ) : '';
$wsp_tr_n   = isset( $_GET['wsp_tr_n'] ) ? max( 0, (int) wp_unslash( $_GET['wsp_tr_n'] ) ) : 0;
$wsp_csv_back = WorldStat_Admin::csv_data_admin_url();
?>
<div class="wrap wsp-admin-wrap">
	<h1 class="wsp-admin-title">
		<span class="dashicons dashicons-translation"></span>
		<?php esc_html_e( 'Переводы показателей (CSV)', 'flavor-worldstat' ); ?>
	</h1>

	<p>
		<a href="<?php echo esc_url( $wsp_csv_back ); ?>"><?php esc_html_e( '← Назад к «Данные CSV»', 'flavor-worldstat' ); ?></a>
	</p>

	<?php if ( ! class_exists( 'WSErgo_Settings' ) ) : ?>
		<div class="notice notice-warning"><p>
			<?php esc_html_e( 'Активируйте расширение «WorldStat — Ergonomics»: подписи сохраняются в его настройках (таблица «Подписи к данным»).', 'flavor-worldstat' ); ?>
		</p></div>
	<?php endif; ?>

	<?php if ( $wsp_tr_msg === 'ok' && $wsp_tr_n > 0 ) : ?>
		<div class="notice notice-success is-dismissible"><p>
			<?php
			echo esc_html(
				sprintf(
					/* translators: %d: number of rows merged */
					__( 'Импортировано и объединено с уже сохранёнными: %d строк.', 'flavor-worldstat' ),
					$wsp_tr_n
				)
			);
			?>
		</p></div>
	<?php elseif ( $wsp_tr_msg === 'error' ) : ?>
		<?php
		$wsp_tr_err = WorldStat_Uploaded_Csv::take_admin_error_flash();
		if ( $wsp_tr_err !== '' ) :
			?>
		<div class="notice notice-error is-dismissible"><p><?php echo esc_html( $wsp_tr_err ); ?></p></div>
		<?php endif; ?>
	<?php endif; ?>

	<div class="wsp-admin-section">
		<h2><?php esc_html_e( 'Зачем нужен этот файл', 'flavor-worldstat' ); ?></h2>
		<p class="description" style="max-width:920px;">
			<?php esc_html_e( 'В CSV с данными столбцы называются латинскими ключами (road_length, urban_pct). Эта страница задаёт русские подписи для тех же ключей — они появятся в графиках, таблицах и настройках эргономики вместо сырого имени столбца.', 'flavor-worldstat' ); ?>
		</p>
		<p class="description" style="max-width:920px;">
			<?php esc_html_e( 'Ключ в файле переводов должен совпадать с именем столбца в CSV данных (регистр не важен при импорте — ключ приводится к нижнему регистру). Сначала загрузите данные на «Данные CSV», затем при необходимости дополните переводы здесь.', 'flavor-worldstat' ); ?>
		</p>
	</div>

	<div class="wsp-admin-section">
		<h2><?php esc_html_e( 'Формат файла', 'flavor-worldstat' ); ?></h2>
		<p class="description">
			<?php esc_html_e( 'UTF-8, разделитель — запятая. Две колонки: технический ключ и русская подпись. Первая строка может быть заголовком: key, label_ru (или slug, код, indicator).', 'flavor-worldstat' ); ?>
		</p>
		<pre style="background:#f6f7f7;padding:12px;border:1px solid #c3c4c7;overflow:auto;max-width:520px;">key,label_ru
road_length,Дороги, км
urban_pct,Доля городского населения, %</pre>

		<?php if ( class_exists( 'WorldStat_Csv_Samples' ) ) : ?>
		<p style="margin-top:14px;">
			<a class="button" href="<?php echo esc_url( WorldStat_Csv_Samples::translations_download_url() ); ?>">
				<span class="dashicons dashicons-download" style="margin-top:3px;"></span>
				<?php esc_html_e( 'Скачать пример переводов', 'flavor-worldstat' ); ?>
			</a>
		</p>
		<p class="description" style="max-width:920px;">
			<?php esc_html_e( 'В примере — ключи из демо-файлов на странице «Данные CSV» (road_length, population_total, pop_dens_km2 и др.). Можно отредактировать и загрузить как основу.', 'flavor-worldstat' ); ?>
		</p>
		<?php endif; ?>
	</div>

	<?php if ( class_exists( 'WSErgo_Settings' ) ) : ?>
	<div class="wsp-admin-section">
		<h2><?php esc_html_e( 'Загрузить файл переводов', 'flavor-worldstat' ); ?></h2>
	<form method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin.php?page=worldstat-csv-translations' ) ); ?>">
		<?php wp_nonce_field( 'wsp_csv_translations_upload', 'wsp_csv_translations_nonce' ); ?>
		<p>
			<label for="wsp_translations_csv"><strong><?php esc_html_e( 'Файл переводов (.csv)', 'flavor-worldstat' ); ?></strong></label><br />
			<input type="file" id="wsp_translations_csv" name="wsp_translations_csv" accept=".csv,text/csv" required />
		</p>
		<p>
			<button type="submit" class="button button-primary"><?php esc_html_e( 'Загрузить и объединить', 'flavor-worldstat' ); ?></button>
		</p>
		<p class="description">
			<?php esc_html_e( 'Новые строки добавляются; если ключ уже был в настройках — подпись перезаписывается. До 10 000 строк за один импорт.', 'flavor-worldstat' ); ?>
		</p>
	</form>
	</div>
	<?php endif; ?>
</div>
