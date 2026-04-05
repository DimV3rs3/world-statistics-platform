<?php
/**
 * /analysis-data page — ML analysis playground.
 *
 * Theme override:
 *  wp-content/themes/THEME/worldstat/page-analysis.php
 */
if ( ! defined( 'ABSPATH' ) ) exit;

get_header();

wp_enqueue_style(
    'worldstat-analysis',
    WSP_ASSETS_URL . 'css/analysis.css',
    [],
    WSP_VERSION
);

/**
 * Read CSV preview lines (for UI defaults).
 */
function wsp_csv_preview( string $abs_path, int $limit = 18 ): string {
    if ( ! file_exists( $abs_path ) ) return '';

    $f = new SplFileObject( $abs_path );
    $lines = [];
    $i = 0;
    foreach ( $f as $line ) {
        if ( $i >= $limit ) break;
        $line = rtrim( (string) $line, "\r\n" );
        if ( $line === '' ) continue;
        $lines[] = $line;
        $i++;
    }
    return implode( "\n", $lines );
}

$samples       = [];
$sample_labels = [];

if ( class_exists( 'WorldStat_Uploaded_Csv' ) ) {
    foreach ( WorldStat_Uploaded_Csv::list_files() as $row ) {
        $path = $row['path'] ?? '';
        if ( $path === '' || ! is_readable( $path ) ) {
            continue;
        }
        $fname = $row['name'] ?? basename( $path );
        $base  = basename( $path, '.csv' );
        $key   = preg_replace( '/[^a-zA-Z0-9_-]/', '_', $base );
        if ( $key === '' || $key === '_' ) {
            $key = 'file_' . substr( md5( $path ), 0, 8 );
        }
        $orig_key = $key;
        $n        = 1;
        while ( isset( $samples[ $key ] ) ) {
            $key = $orig_key . '_' . $n;
            ++$n;
        }
        $samples[ $key ]       = wsp_csv_preview( $path, 18 );
        $sample_labels[ $key ] = $fname;
    }
}

$default_key = ! empty( $samples ) ? array_key_first( $samples ) : null;
$default_csv = ( $default_key !== null && isset( $samples[ $default_key ] ) ) ? $samples[ $default_key ] : '';
?>

<div class="wsp-analysis-page">
    <div class="wsp-container">
        <h1 class="wsp-page-title">Анализ данных</h1>
        <p class="wsp-page-desc">
            Вставьте CSV (с заголовком или без), выберите целевую колонку (для классификации/регрессии),
            и запустите вычисления: классификация, кластеризация, регрессия и наивный байес.
        </p>

        <div class="wsp-analysis-layout">
            <div class="wsp-analysis-form">
                <div class="wsp-field">
                    <label for="wsp-analysis-source">Пример данных</label>
                    <select id="wsp-analysis-source" class="wsp-select">
                        <option value="custom">Кастомный CSV</option>
                        <?php foreach ( array_keys( $samples ) as $k ) : ?>
                            <option value="<?php echo esc_attr( $k ); ?>"><?php echo esc_html( $sample_labels[ $k ] ?? $k ); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <p class="wsp-muted" style="margin:6px 0 0;">
                        При выборе примера текст в поле будет заменён на CSV-превью.
                    </p>
                </div>

                <div class="wsp-field">
                    <label for="wsp-analysis-csv">CSV данные</label>
                    <textarea
                        id="wsp-analysis-csv"
                        rows="12"
                        class="wsp-analysis-textarea"
                        spellcheck="false"
                    ><?php echo esc_textarea( $default_csv ); ?></textarea>
                </div>

                <div class="wsp-analysis-grid">
                    <div class="wsp-field">
                        <label for="wsp-analysis-delim">Разделитель</label>
                        <input id="wsp-analysis-delim" class="wsp-select" value="," maxlength="1" />
                    </div>

                    <div class="wsp-field wsp-inline">
                        <label class="wsp-inline-label">
                            <input type="checkbox" id="wsp-analysis-has-header" checked />
                            Заголовок CSV
                        </label>
                    </div>

                    <div class="wsp-field">
                        <label for="wsp-analysis-target">Целевая колонка</label>
                        <select id="wsp-analysis-target" class="wsp-select">
                            <option value="">(не выбрано)</option>
                        </select>
                        <p class="wsp-muted" style="margin:6px 0 0;">
                            Для классификации/регрессии. Для кластеризации целевая колонка не нужна.
                        </p>
                    </div>
                </div>

                <div class="wsp-analysis-grid">
                    <div class="wsp-field">
                        <label for="wsp-analysis-k">k (k-means / hierarchical)</label>
                        <input id="wsp-analysis-k" class="wsp-select" value="3" type="number" min="2" max="10" step="1" />
                    </div>

                    <div class="wsp-field">
                        <label for="wsp-analysis-eps">DBSCAN eps</label>
                        <input id="wsp-analysis-eps" class="wsp-select" value="0.5" type="number" step="0.1" min="0.01" />
                    </div>

                    <div class="wsp-field">
                        <label for="wsp-analysis-minpts">DBSCAN minPts</label>
                        <input id="wsp-analysis-minpts" class="wsp-select" value="3" type="number" min="1" max="30" step="1" />
                    </div>
                </div>

                <div class="wsp-analysis-actions">
                    <button type="button" class="wsp-btn" id="wsp-analysis-run">Запустить анализ</button>
                    <span class="wsp-muted" id="wsp-analysis-status" style="margin-left:10px;"></span>
                </div>
            </div>

            <div class="wsp-analysis-results">
                <div id="wsp-analysis-output" class="wsp-analysis-output"></div>
            </div>
        </div>
    </div>
</div>

<script>
    window.worldstatAnalysisSamples = <?php echo wp_json_encode( $samples ); ?>;
    window.worldstatAnalysisDefault = <?php echo wp_json_encode( $default_key ); ?>;
</script>

<?php
    wp_enqueue_script(
        'worldstat-analysis-data',
        WSP_ASSETS_URL . 'js/analysis-data.js',
        [ 'worldstat-platform' ],
        WSP_VERSION,
        true
    );
?>

<?php get_footer(); ?>

