<?php
/**
 * Rankings page — country rankings by metric with top-5 and full table.
 *
 * @package WorldStatPlatform
 */

if ( ! defined( 'ABSPATH' ) ) exit;

function wsp_format_value( $value ) {
    if ( ! is_scalar( $value ) || ( is_string( $value ) && $value === '' ) ) {
        return '—';
    }
    if ( ! is_numeric( $value ) ) {
        return '—';
    }
    $float = (float) $value;
    if ( abs( $float - round( $float ) ) < 0.00001 ) {
        return number_format( $float, 0, '.', ' ' );
    }
    $dp = abs( $float ) < 0.001 ? 4 : 3;
    return number_format( $float, $dp, '.', ' ' );
}

get_header();
wp_enqueue_style( 'wsp-analytics', WSP_ASSETS_URL . 'css/wsp-analytics.css', [], WSP_VERSION );
wp_enqueue_script( 'wsp-analytics', WSP_ASSETS_URL . 'js/wsp-analytics.js', [], WSP_VERSION, true );

$ext_labels = [ 'core' => 'Ключевые метрики' ];
if ( class_exists( 'WorldStat_Extensions' ) ) {
    $ext = worldstat_platform()->extensions ?? null;
    if ( $ext ) {
        foreach ( $ext->get_all() as $id => $info ) {
            $tabs = $ext->get_tabs();
            $ext_labels[ $id ] = $tabs[ $id ]['title'] ?? $info['name'] ?? ucfirst( $id );
        }
    }
}

$all_metrics = [];
foreach ( WorldStat_Data::get_available_metrics_with_core() as $k => $m ) {
    $eid                       = $m['extension'] ?? $m['ext_id'] ?? 'other';
    $m['ext_id']               = $eid;
    $m['extension_label']       = $ext_labels[ $eid ] ?? ucfirst( $eid );
    $all_metrics[ (string) $k ] = $m;
}

$num = [ 'number', 'integer', 'float', 'double', 'decimal', 'real', 'numeric', 'int' ];
$all_metrics = array_filter( $all_metrics, fn($m) => in_array( strtolower( $m['type'] ?? 'string' ), $num ) );

$cur_metric = $_GET['metric'] ?? array_key_first( $all_metrics );
$cur_region = $_GET['region'] ?? '';
$cur_year = isset( $_GET['year'] ) ? intval( $_GET['year'] ) : 0;
if ( $cur_year > 0 && class_exists( 'WorldStat_Platform_Years' ) ) {
	$cur_year = WorldStat_Platform_Years::clamp( $cur_year );
}
if ( ! isset( $all_metrics[ $cur_metric ] ) ) $cur_metric = array_key_first( $all_metrics );

if ( empty( $all_metrics ) ) {
    echo '<div class="wsp-page"><div class="wsp-empty">Нет числовых метрик.</div></div>';
    get_footer(); return;
}

// Ключ метрики всегда «расширение.показатель»; показатель может содержать точки (редко) — отрезаем только первую точку.
$cur_metric   = (string) $cur_metric;
$dot_pos      = strpos( $cur_metric, '.' );
$ext_id       = '';
$metric_slug  = '';
if ( false !== $dot_pos && $dot_pos > 0 && $dot_pos < strlen( $cur_metric ) - 1 ) {
    $ext_id      = substr( $cur_metric, 0, $dot_pos );
    $metric_slug = substr( $cur_metric, $dot_pos + 1 );
}
if ( '' === $ext_id || '' === $metric_slug ) {
    $cur_metric = (string) array_key_first( $all_metrics );
    $dot_pos    = strpos( $cur_metric, '.' );
    $ext_id     = substr( $cur_metric, 0, (int) $dot_pos );
    $metric_slug = substr( $cur_metric, (int) $dot_pos + 1 );
}

$csv_metric_years = [];
if ( $ext_id === 'csv-country-meta' && class_exists( 'WorldStat_Csv_Country_Meta_Importer' ) ) {
    $csv_metric_years = WorldStat_Csv_Country_Meta_Importer::get_years_union_for_slug( $metric_slug );
}
$y_ctx = null;
if ( $ext_id === 'csv-country-meta' && $cur_year > 0 && in_array( $cur_year, $csv_metric_years, true ) ) {
    $y_ctx = $cur_year;
}
WorldStat_Data::set_value_year_context( $y_ctx );

$countries_raw = WorldStat_Data::get_countries( [ 'per_page' => 300 ] );
$json = [];
$jp = WSP_DATA_DIR . 'countries.json';
if ( file_exists( $jp ) ) {
    $raw = json_decode( file_get_contents( $jp ), true );
    if ( is_array( $raw ) ) foreach ( $raw as $e ) { if ( $e['iso2'] ?? '' ) $json[ $e['iso2'] ] = $e; }
}
foreach ( $countries_raw as &$c ) {
    $c['region'] = $json[ $c['iso2'] ]['region'] ?? '';
    $c['subregion'] = $json[ $c['iso2'] ]['subregion'] ?? '';
}
unset( $c );

// Дерево регионов (как было)
$tree = [ '' => [ 'label' => '🌍 Весь мир', 'sub' => [] ] ];
$rname = [ 'europe'=>'Европа','asia'=>'Азия','africa'=>'Африка','americas'=>'Америка','oceania'=>'Океания' ];
$remoji = [ 'europe'=>'🇪🇺','asia'=>'🗺️','africa'=>'🌍','americas'=>'🌎','oceania'=>'🏝️' ];
$sname = [ 'northern-europe'=>'Северная Европа','western-europe'=>'Западная Европа','eastern-europe'=>'Восточная Европа','southern-europe'=>'Южная Европа','eastern-asia'=>'Восточная Азия','western-asia'=>'Западная Азия','southern-asia'=>'Южная Азия','south-eastern-asia'=>'Юго-Восточная Азия','central-asia'=>'Центральная Азия','northern-africa'=>'Северная Африка','western-africa'=>'Западная Африка','eastern-africa'=>'Восточная Африка','middle-africa'=>'Центральная Африка','southern-africa'=>'Южная Африка','northern-america'=>'Северная Америка','south-america'=>'Южная Америка','central-america'=>'Центральная Америка','caribbean'=>'Карибский бассейн','australia-and-nz'=>'Австралия и НЗ','melanesia'=>'Меланезия','micronesia'=>'Микронезия','polynesia'=>'Полинезия' ];
foreach ( $json as $e ) {
    $r = $e['region'] ?? ''; $s = $e['subregion'] ?? '';
    if ( ! $r ) continue;
    if ( ! isset( $tree[ $r ] ) ) $tree[ $r ] = [ 'label' => ( $remoji[ $r ] ?? '🌐' ) . ' ' . ( $rname[ $r ] ?? ucfirst( $r ) ), 'sub' => [] ];
    if ( $s && ! isset( $tree[ $r ]['sub'][ $s ] ) ) $tree[ $r ]['sub'][ $s ] = $sname[ $s ] ?? ucwords( str_replace( '-', ' ', $s ) );
}
foreach ( $tree as &$t ) { if ( ! empty( $t['sub'] ) ) asort( $t['sub'] ); }
unset( $t );

// Фильтр
if ( $cur_region ) {
    $is_r = isset( $tree[ $cur_region ] ) && $cur_region !== '';
    $parent = '';
    if ( ! $is_r ) { foreach ( $tree as $rs => $rd ) { if ( isset( $rd['sub'][ $cur_region ] ) ) { $parent = $rs; break; } } }
    $countries_raw = array_filter( $countries_raw, fn($c) => $is_r ? ( $c['region'] ?? '' ) === $cur_region : ( ( $c['region'] ?? '' ) === $parent && ( $c['subregion'] ?? '' ) === $cur_region ) );
}
$countries = $countries_raw;

// Рейтинг (csv-country-meta / ergonomics ergo_index: без N× провайдеров и повторной распаковки макробандла)
$rank = [];
$use_csv_bulk = ( $ext_id === 'csv-country-meta' && class_exists( 'WorldStat_Csv_Country_Meta_Importer' ) );
$use_ergo_bulk = ( $ext_id === 'ergonomics' && $metric_slug === 'ergo_index' && class_exists( 'WSErgo_Data' ) );
$bulk_values = $use_csv_bulk
    ? WorldStat_Csv_Country_Meta_Importer::get_metric_values_for_iso_list( array_column( array_values( $countries ), 'iso2' ), $metric_slug, $y_ctx )
    : ( $use_ergo_bulk
        ? WSErgo_Data::bulk_country_ergo_index_for_iso2_list( array_column( array_values( $countries ), 'iso2' ) )
        : array()
    );

foreach ( $countries as $c ) {
    if ( $use_csv_bulk || $use_ergo_bulk ) {
        $iu = strtoupper( trim( (string) ( $c['iso2'] ?? '' ) ) );
        $fv = isset( $bulk_values[ $iu ] ) ? (float) $bulk_values[ $iu ] : null;
        // Показываем 0 там, где макромодель/агрегация вернули нуль (совпадает с одиночным get).
        if ( $fv === null ) {
            continue;
        }
        if ( ! is_finite( $fv ) ) {
            continue;
        }
        $rank[] = [
            'iso2'  => $c['iso2'],
            'name'  => $c['title'],
            'flag'  => $c['flag'],
            'value' => $fv,
            'url'   => $c['url'],
        ];
        continue;
    }
    $v = WorldStat_Data::get( $ext_id, $c['iso2'], $metric_slug );
    // is_numeric( object ) в PHP 8+ даёт TypeError; некоторые фильтры могут вернуть массив — пропускаем.
    if ( ! is_scalar( $v ) || ( is_string( $v ) && $v === '' ) || ! is_numeric( $v ) ) {
        continue;
    }
    $fv = (float) $v;
    if ( ! is_finite( $fv ) ) {
        continue;
    }
    $rank[] = [
        'iso2'  => $c['iso2'],
        'name'  => $c['title'],
        'flag'  => $c['flag'],
        'value' => $fv,
        'url'   => $c['url'],
    ];
}
usort( $rank, fn($a, $b) => $b['value'] <=> $a['value'] );
foreach ( $rank as $i => &$r ) $r['rank'] = $i + 1;
unset( $r );

$minfo = $all_metrics[ $cur_metric ] ?? [];
$mlabel = $minfo['label'] ?? $metric_slug;
$munit = $minfo['unit'] ?? '';
$top5 = array_slice( $rank, 0, 5 );

// Группировка
$grouped = [];
foreach ( $all_metrics as $k => $m ) {
    $e = $m['ext_id'] ?? 'other';
    $grouped[ $e ]['label'] = $m['extension_label'] ?? $ext_labels[ $e ] ?? ucfirst( $e );
    $grouped[ $e ]['items'][ $k ] = $m;
}
?>

<div class="wsp-page">
    
    <h1 class="wsp-page__title"><?php esc_html_e( 'Мировые рейтинги', 'flavor-worldstat' ); ?></h1>
    <p class="wsp-page__subtitle"><?php esc_html_e( 'Аналитика позиций стран по ключевым показателям', 'flavor-worldstat' ); ?></p>

    <div class="wsp-card">
        <form method="GET" id="wsp-rankings-form" style="display:flex; gap:16px; flex-wrap:wrap; align-items:stretch;">
            
            <div style="flex:2; min-width:250px; position:relative;">
                <div class="wsp-card__label"><?php esc_html_e( 'Показатель', 'flavor-worldstat' ); ?></div>
                <div id="wsp-metric-dropdown-trigger" class="wsp-dropdown-trigger" onclick="wspToggleDropdown()">
                    <span id="wsp-metric-selected-label"><?php echo esc_html( $mlabel ); ?></span>
                    <span style="color:#94a3b8;font-size:12px;margin-left:8px;"><?php echo $munit ? esc_html( $munit ) : ''; ?></span>
                    <span style="margin-left:auto;transition:transform 0.2s;" id="wsp-dropdown-arrow">▾</span>
                </div>
                <input type="hidden" name="metric" id="wsp-metric-input" value="<?php echo esc_attr( $cur_metric ); ?>">
                <div id="wsp-metric-dropdown" class="wsp-dropdown" style="display:none;">
                    <?php $first = true; foreach ( $grouped as $eid => $g ) : ?>
                        <div class="wsp-dropdown__group-header <?php echo $first ? 'open' : ''; ?>" onclick="wspToggleGroup(this)">
                            <span class="wsp-dropdown__group-arrow">▸</span>
                            <span style="flex:1;"><?php echo esc_html( $g['label'] ); ?></span>
                            <span style="font-size:11px;color:#94a3b8;">(<?php echo count( $g['items'] ); ?>)</span>
                        </div>
                        <div class="wsp-dropdown__group-body" style="<?php echo $first ? '' : 'display:none;'; ?>">
                            <?php foreach ( $g['items'] as $k => $m ) : $sel = $cur_metric === $k; ?>
                                <div class="wsp-dropdown__option <?php echo $sel ? 'selected' : ''; ?>" data-value="<?php echo esc_attr( $k ); ?>" data-label="<?php echo esc_attr( $m['label'] ); ?>" data-unit="<?php echo esc_attr( $m['unit'] ?? '' ); ?>" onclick="wspSelectMetric(this)">
                                    <?php echo esc_html( $m['label'] ); ?>
                                    <?php if ( $m['unit'] ?? '' ) echo ' <span style="font-size:11px;color:#94a3b8;">' . esc_html( $m['unit'] ) . '</span>'; ?>
                                    <?php if ( $sel ) echo '<span class="wsp-metric-check">✓</span>'; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php $first = false; endforeach; ?>
                </div>
            </div>

            <div style="flex:1; min-width:200px; position:relative;">
                <div class="wsp-card__label"><?php esc_html_e( 'Регион', 'flavor-worldstat' ); ?></div>
                <div id="wsp-region-dropdown-trigger" class="wsp-dropdown-trigger" onclick="wspToggleRegionDropdown()">
                    <span id="wsp-region-selected-label">
                        <?php
                        $cl = '🌍 Весь мир';
                        if ( $cur_region && isset( $tree[ $cur_region ] ) ) $cl = $tree[ $cur_region ]['label'];
                        elseif ( $cur_region ) {
                            foreach ( $tree as $rd ) { if ( isset( $rd['sub'][ $cur_region ] ) ) { $cl = $rd['sub'][ $cur_region ]; break; } }
                        }
                        echo esc_html( $cl );
                        ?>
                    </span>
                    <span style="margin-left:auto;transition:transform 0.2s;" id="wsp-region-arrow">▾</span>
                </div>
                <input type="hidden" name="region" id="wsp-region-input" value="<?php echo esc_attr( $cur_region ); ?>">
                <div id="wsp-region-dropdown" class="wsp-dropdown" style="display:none;">
                    <?php foreach ( $tree as $rs => $rd ) :
                        $has = ! empty( $rd['sub'] );
                        $sel_r = $cur_region === $rs;
                        $sel_sub = ! $sel_r && $has && isset( $rd['sub'][ $cur_region ] );
                        $open = $sel_r || $sel_sub;
                        if ( $rs === '' ) : ?>
                            <div class="wsp-dropdown__option <?php echo $cur_region === '' ? 'selected' : ''; ?>" data-value="" data-label="🌍 Весь мир" onclick="wspSelectRegion(this)">🌍 Весь мир<?php if ( $cur_region === '' ) echo '<span class="wsp-metric-check">✓</span>'; ?></div>
                        <?php elseif ( $has ) : ?>
                            <div class="wsp-dropdown__group-header <?php echo $open ? 'open' : ''; ?>">
                                <span class="wsp-dropdown__group-arrow" onclick="wspToggleRegionGroup(this.parentElement, event)">▸</span>
                                <span style="flex:1;" onclick="wspSelectRegionGroup('<?php echo esc_attr( $rs ); ?>', this.textContent.trim(), event)"><?php echo esc_html( $rd['label'] ); ?><?php if ( $sel_r ) echo ' <span style="color:#2563eb;font-size:12px;">✓</span>'; ?></span>
                            </div>
                            <div class="wsp-dropdown__group-body" style="<?php echo $open && ! $sel_r ? '' : 'display:none;'; ?>">
                                <?php foreach ( $rd['sub'] as $ss => $sl ) : $ssel = $cur_region === $ss; ?>
                                    <div class="wsp-dropdown__option <?php echo $ssel ? 'selected' : ''; ?>" data-value="<?php echo esc_attr( $ss ); ?>" data-label="<?php echo esc_attr( $sl ); ?>" onclick="wspSelectRegion(this, event)"><?php echo esc_html( $sl ); ?><?php if ( $ssel ) echo '<span class="wsp-metric-check">✓</span>'; ?></div>
                                <?php endforeach; ?>
                            </div>
                        <?php else : ?>
                            <div class="wsp-dropdown__option <?php echo $sel_r ? 'selected' : ''; ?>" data-value="<?php echo esc_attr( $rs ); ?>" data-label="<?php echo esc_attr( $rd['label'] ); ?>" onclick="wspSelectRegion(this)"><?php echo esc_html( $rd['label'] ); ?><?php if ( $sel_r ) echo '<span class="wsp-metric-check">✓</span>'; ?></div>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </div>
            </div>

            <?php if ( ! empty( $csv_metric_years ) ) : ?>
            <div style="flex:0 1 140px; min-width:120px;">
                <label class="wsp-card__label" for="wsp-ranking-year"><?php esc_html_e( 'Год', 'flavor-worldstat' ); ?></label>
                <select name="year" id="wsp-ranking-year" class="wsp-select" onchange="this.form.submit()" style="width:100%;height:44px;padding:0 12px;border:1px solid #e2e8f0;border-radius:10px;background:#fff;">
                    <option value="0"><?php esc_html_e( 'Последний', 'flavor-worldstat' ); ?></option>
                    <?php foreach ( $csv_metric_years as $y_item ) : ?>
                        <option value="<?php echo (int) $y_item; ?>" <?php selected( $cur_year, (int) $y_item ); ?>><?php echo (int) $y_item; ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>
        </form>
    </div>

    <?php if ( $top5 ) : ?>
    <div class="wsp-top5">
        <h2 class="wsp-top5__title"><span>🏆</span> <?php esc_html_e( 'Топ-5 лидеров', 'flavor-worldstat' ); ?></h2>
        <div class="wsp-top5__grid">
            <?php foreach ( $top5 as $r ) : ?>
                <div class="wsp-top5__card">
                    <span class="wsp-top5__flag"><?php echo esc_html( $r['flag'] ); ?></span>
                    <div class="wsp-top5__name"><a href="<?php echo esc_url( $r['url'] ); ?>"><?php echo esc_html( $r['name'] ); ?></a></div>
                    <div class="wsp-top5__rank"><?php echo match($r['rank']){1=>'🥇',2=>'🥈',3=>'🥉',default=>'#'.$r['rank'].' место'}; ?></div>
                    <div class="wsp-top5__value"><?php echo wsp_format_value( $r['value'] ); ?></div>
                    <?php if ( $munit ) : ?><div class="wsp-top5__unit"><?php echo esc_html( $munit ); ?></div><?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <div class="wsp-card" style="padding:0;overflow:hidden;">
        <div style="padding:16px 20px;border-bottom:1px solid #f1f5f9;display:flex;justify-content:space-between;align-items:center;">
            <h2 style="margin:0;font-size:1.15rem;font-weight:700;"><?php printf( esc_html__( 'Полный рейтинг: %s', 'flavor-worldstat' ), esc_html( $mlabel ) ); ?></h2>
            <?php if ( $munit ) : ?><span style="background:#f1f5f9;padding:4px 12px;border-radius:20px;font-size:12px;color:#475569;font-weight:600;"><?php echo esc_html( $munit ); ?></span><?php endif; ?>
        </div>
        <?php if ( $rank ) : ?>
            <div style="overflow-x:auto;">
                <table class="wsp-table" style="border:none;border-radius:0;">
                    <thead><tr>
                        <th style="width:80px;"><?php esc_html_e( 'Место', 'flavor-worldstat' ); ?></th>
                        <th><?php esc_html_e( 'Страна', 'flavor-worldstat' ); ?></th>
                        <th style="text-align:right;"><?php esc_html_e( 'Значение', 'flavor-worldstat' ); ?></th>
                    </tr></thead>
                    <tbody>
                        <?php foreach ( array_slice( $rank, 0, 100 ) as $r ) : ?>
                            <tr>
                                <td><span class="wsp-rank wsp-rank--<?php echo match($r['rank']){1=>'gold',2=>'silver',3=>'bronze',default=>'default'}; ?>"><?php echo match($r['rank']){1=>'🥇',2=>'🥈',3=>'🥉',default=>'#'.$r['rank']}; ?></span></td>
                                <td><a href="<?php echo esc_url( $r['url'] ); ?>" style="text-decoration:none;display:flex;align-items:center;gap:10px;"><span style="font-size:20px;"><?php echo esc_html( $r['flag'] ); ?></span><span style="font-weight:600;color:#0f172a;"><?php echo esc_html( $r['name'] ); ?></span></a></td>
                                <td style="text-align:right;font-family:monospace;font-weight:600;color:#2563eb;"><?php echo wsp_format_value( $r['value'] ); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php if ( count( $rank ) > 100 ) : ?>
                <div style="padding:12px;text-align:center;background:#f8fafc;color:#64748b;font-size:12px;"><?php printf( esc_html__( 'Показано топ-100 из %d стран', 'flavor-worldstat' ), count( $rank ) ); ?></div>
            <?php endif; ?>
        <?php else : ?>
            <div class="wsp-empty"><?php esc_html_e( 'Нет данных для отображения.', 'flavor-worldstat' ); ?></div>
        <?php endif; ?>
    </div>
</div>

<?php
WorldStat_Data::reset_value_year_context();
get_footer();
