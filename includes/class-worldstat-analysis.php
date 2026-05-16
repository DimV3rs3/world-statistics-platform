<?php
/**
 * Data Analysis — ML algorithms playground (classification/clustering/regression + Naive Bayes).
 *
 * Runs on AJAX endpoint: worldstat_run_data_analysis
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class WorldStat_Data_Analysis {

    public static function init(): void {
        add_action( 'wp_ajax_worldstat_run_data_analysis', [ self::class, 'ajax_run' ] );
        add_action( 'wp_ajax_nopriv_worldstat_run_data_analysis', [ self::class, 'ajax_run' ] );
    }

    /**
     * AJAX handler — returns HTML blocks with charts and explanations.
     */
    public static function ajax_run(): void {
        if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( (string) $_POST['nonce'], 'wp_rest' ) ) {
            wp_send_json_error( [ 'message' => 'Недействительный nonce.' ], 403 );
        }

        $csv = wp_unslash( (string) ( $_POST['csv'] ?? '' ) );
        if ( mb_strlen( $csv ) < 30 ) {
            wp_send_json_error( [ 'message' => 'CSV слишком короткий. Вставьте данные (несколько строк).' ] );
        }
        if ( mb_strlen( $csv ) > 2_000_000 ) {
            wp_send_json_error( [ 'message' => 'CSV слишком большой. Ограничение: 2 МБ.' ] );
        }

        $delimiter_raw = (string) ( $_POST['delimiter'] ?? ',' );
        $delimiter = mb_substr( $delimiter_raw, 0, 1 );
        if ( $delimiter === '' ) $delimiter = ',';

        $has_header = ! empty( $_POST['has_header'] );
        $target_column = sanitize_text_field( (string) ( $_POST['target_column'] ?? '' ) );

        $k = (int) ( $_POST['k'] ?? 3 );
        $k = max( 2, min( 10, $k ) );

        $eps = (float) ( $_POST['eps'] ?? 0.5 );
        if ( ! is_finite( $eps ) || $eps <= 0 ) $eps = 0.5;

        $minpts = (int) ( $_POST['minpts'] ?? 3 );
        $minpts = max( 1, min( 30, $minpts ) );

        try {
            $runner = new self();
            $result = $runner->run( [
                'csv'             => $csv,
                'delimiter'       => $delimiter,
                'has_header'     => $has_header,
                'target_column'  => $target_column,
                'k'               => $k,
                'eps'             => $eps,
                'minpts'         => $minpts,
            ] );

            wp_send_json_success( [ 'html' => $result ] );
        } catch ( \Throwable $e ) {
            wp_send_json_error( [ 'message' => 'Ошибка анализа: ' . $e->getMessage() ] );
        }
    }

    /**
     * Run analysis and build HTML output.
     *
     * @return string
     */
    public function run( array $args ): string {
        $parsed = $this->parse_csv( $args['csv'], $args['delimiter'], (bool) $args['has_header'] );

        return $this->run_from_table( $parsed['rows'], $parsed['columns'], $args );
    }

    /**
     * Run analysis on a pre-built table (rows keyed by column name).
     *
     * @param list<array<string, scalar>> $rows
     * @param list<string>                $columns
     * @param array<string, mixed>        $args target_column, regression_target_column?, k, eps, minpts, intro_html?
     */
    public function run_from_table( array $rows, array $columns, array $args ): string {
        $rows = array_slice( $rows, 0, 400 );
        $n    = count( $rows );
        if ( $n < 10 ) {
            return '<div class="wsp-notice wsp-notice-error"><p>Недостаточно строк для анализа. Нужно хотя бы 10 строк.</p></div>';
        }

        $target_column            = (string) ( $args['target_column'] ?? '' );
        $regression_target_column = (string) ( $args['regression_target_column'] ?? $target_column );
        $has_target               = $target_column !== '' && in_array( $target_column, $columns, true );
        $has_reg_target           = $regression_target_column !== '' && in_array( $regression_target_column, $columns, true );

        $numeric_cols = $this->detect_numeric_columns( $rows, $columns );

        $exclude = [];
        if ( $has_target ) {
            $exclude[] = $target_column;
        }
        if ( $has_reg_target ) {
            $exclude[] = $regression_target_column;
        }
        $exclude[] = '_target_class';

        $feature_cols = $numeric_cols;
        if ( ! empty( $exclude ) ) {
            $feature_cols = array_values( array_diff( $feature_cols, $exclude ) );
        }

        $feature_cols = array_values( array_slice( $feature_cols, 0, 12 ) );

        if ( count( $feature_cols ) === 0 ) {
            return '<div class="wsp-notice wsp-notice-error"><p>Не удалось найти числовые признаки для анализа.</p></div>';
        }

        [$X_raw, $X_all_cols, $y_class, $y_reg] = $this->build_xy_datasets(
            $rows,
            $feature_cols,
            $has_target ? $target_column : '',
            $has_target
        );

        if ( $has_reg_target && $regression_target_column !== $target_column ) {
            $y_reg = $this->build_regression_dataset( $rows, $feature_cols, $regression_target_column );
        }

        // Clustering dataset uses all numeric feature cols.
        $X_cluster_raw = $this->build_X_only( $rows, $feature_cols );
        // Hard limit to keep DBSCAN/hierarchical clustering responsive.
        if ( count( $X_cluster_raw ) > 200 ) {
            $X_cluster_raw = array_slice( $X_cluster_raw, 0, 200 );
        }

        $k = (int) $args['k'];
        $eps = (float) $args['eps'];
        $minpts = (int) $args['minpts'];

        $out = '<div class="wsp-analysis-output">';
        $out .= '<div class="wsp-analysis-meta">';
        $out .= '<p class="wsp-muted"><strong>Данные:</strong> ' . esc_html( $n ) . ' строк, ' . esc_html( count( $columns ) ) . ' колонок.</p>';
        $out .= '<p class="wsp-muted"><strong>Признаки:</strong> ' . esc_html( implode( ', ', $feature_cols ) ) . '</p>';
        if ( ! empty( $args['intro_html'] ) ) {
            $out .= (string) $args['intro_html'];
        }
        if ( $has_target ) {
            $out .= '<p class="wsp-muted"><strong>Целевая колонка (классификация):</strong> ' . esc_html( $target_column ) . '</p>';
        } else {
            $out .= '<p class="wsp-muted"><strong>Целевая колонка:</strong> не выбрана (только кластеризация).</p>';
        }
        if ( $has_reg_target && $regression_target_column !== $target_column ) {
            $out .= '<p class="wsp-muted"><strong>Целевая колонка (регрессия):</strong> ' . esc_html( $regression_target_column ) . '</p>';
        }
        $out .= '</div>';

        // Conclusions skeleton (1-2-3) filled after computations.
        $conclusion = [
            'best_model' => '',
            'clusters' => '',
            'recommendations' => '',
        ];

        // Classification + Naive Bayes
        $models_rendered = 0;
        $best_clf = null;

        if ( $has_target && count( $y_class ) >= 10 ) {
            $class_report = $this->run_classification_suite(
                $X_raw,
                $y_class,
                $X_all_cols,
                $conclusion,
                $best_clf
            );

            if ( $class_report['rendered'] > 0 ) {
                $models_rendered += (int) $class_report['rendered'];
                $out .= $class_report['html'];
            }
        }

        // Regression
        $best_reg = null;
        if ( $has_target && $y_reg['available'] ) {
            $reg_report = $this->run_regression_suite(
                $y_reg['X'],
                $y_reg['y'],
                $conclusion,
                $best_reg
            );
            $out .= $reg_report['html'];
        }

        // Clustering
        $cluster_report = $this->run_clustering_suite(
            $X_cluster_raw,
            $feature_cols,
            $k,
            $eps,
            $minpts,
            $conclusion
        );
        $out .= $cluster_report['html'];

        // Fill conclusions 1-2-3.
        $best_txt = $conclusion['best_model'] ?: ( $best_clf ? 'Классификация' : ( $best_reg ? 'Регрессия' : '' ) );
        $clusters_txt = $conclusion['clusters'] ?: 'Получены кластеры/сегменты данных.';
        $recs = $conclusion['recommendations'] ?: 'Уточните параметры (k/eps), проверьте на тестовой выборке и используйте больше данных.';

        $out .= '<div class="wsp-analysis-conclusions">';
        $out .= '<h2>Логический вывод</h2>';
        $out .= '<ol class="wsp-analysis-ol">';
        $out .= '<li><strong>Что получилось лучше всего:</strong> ' . esc_html( $best_txt ?: '—' ) . '</li>';
        $out .= '<li><strong>Как сегментируются данные:</strong> ' . esc_html( $clusters_txt ) . '</li>';
        $out .= '<li><strong>Следующие шаги:</strong> ' . esc_html( $recs ) . '</li>';
        $out .= '</ol>';
        $out .= '</div>';

        $out .= '</div>';

        return $out;
    }

    /**
     * Regression dataset for a numeric target column.
     *
     * @param list<array<string, scalar>> $rows
     * @param list<string>                $feature_cols
     */
    private function build_regression_dataset( array $rows, array $feature_cols, string $target_col ): array {
        $X_reg = [];
        $y_reg = [];
        foreach ( $rows as $row ) {
            $x  = [];
            $ok = true;
            foreach ( $feature_cols as $c ) {
                $f = $this->to_float( (string) ( $row[ $c ] ?? '' ) );
                if ( null === $f ) {
                    $ok = false;
                    break;
                }
                $x[] = $f;
            }
            if ( ! $ok ) {
                continue;
            }
            $yf = $this->to_float( (string) ( $row[ $target_col ] ?? '' ) );
            if ( null === $yf ) {
                continue;
            }
            $X_reg[] = $x;
            $y_reg[] = $yf;
        }
        return [
            'available' => count( $y_reg ) >= 10,
            'y'         => $y_reg,
            'X'         => $X_reg,
        ];
    }

    /* ─────────────────────────────────────────────────────────────
       CSV parsing + dataset building
    ───────────────────────────────────────────────────────────── */

    /**
     * Parse CSV to columns + rows (associative).
     *
     * @return array{columns: string[], rows: array<int,array<string,string>>}
     */
    private function parse_csv( string $csv, string $delimiter, bool $has_header ): array {
        $lines = preg_split( "/\r\n|\n|\r/", $csv );
        $lines = array_values( array_filter( array_map( 'trim', $lines ), fn($l) => $l !== '' ) );
        if ( count( $lines ) < 2 ) {
            throw new \RuntimeException( 'CSV не содержит достаточно строк.' );
        }

        $first = array_shift( $lines );
        $header_fields = $this->csv_to_fields( $first, $delimiter );

        if ( ! $has_header ) {
            $cols = array_map( fn($i) => 'col_' . ( $i + 1 ), array_keys( $header_fields ) );
            $rows = [];
            foreach ( $lines as $line ) {
                $fields = $this->csv_to_fields( $line, $delimiter );
                $row = [];
                foreach ( $cols as $i => $col ) {
                    $row[ $col ] = (string) ( $fields[ $i ] ?? '' );
                }
                $rows[] = $row;
            }
            return [ 'columns' => $cols, 'rows' => $rows ];
        }

        $cols = array_values( array_filter( array_map( fn($s) => trim( (string) $s ), $header_fields ), fn($s) => $s !== '' ) );
        if ( count( $cols ) < 2 ) {
            throw new \RuntimeException( 'Заголовок CSV содержит слишком мало колонок.' );
        }

        $rows = [];
        foreach ( $lines as $line ) {
            $fields = $this->csv_to_fields( $line, $delimiter );
            $row = [];
            foreach ( $cols as $i => $col ) {
                $row[ $col ] = (string) ( $fields[ $i ] ?? '' );
            }
            $rows[] = $row;
        }

        return [ 'columns' => $cols, 'rows' => $rows ];
    }

    /**
     * Minimal CSV tokenizer for a single line.
     *
     * @return string[]
     */
    private function csv_to_fields( string $line, string $delimiter ): array {
        $delimiter = $delimiter !== '' ? $delimiter : ',';
        $delimiterChar = $delimiter[0];

        $res = [];
        $cur = '';
        $inQuotes = false;
        $len = strlen( $line );
        for ( $i = 0; $i < $len; $i++ ) {
            $ch = $line[ $i ];
            if ( $ch === '"' ) {
                if ( $inQuotes && $i + 1 < $len && $line[ $i + 1 ] === '"' ) {
                    $cur .= '"';
                    $i++;
                    continue;
                }
                $inQuotes = ! $inQuotes;
                continue;
            }
            if ( ! $inQuotes && $ch === $delimiterChar ) {
                $res[] = $cur;
                $cur = '';
                continue;
            }
            $cur .= $ch;
        }
        $res[] = $cur;
        return array_map( 'trim', $res );
    }

    /**
     * Detect numeric columns: percentage of parseable floats.
     *
     * @param array<int,array<string,string>> $rows
     * @param string[] $columns
     * @return string[]
     */
    private function detect_numeric_columns( array $rows, array $columns ): array {
        $numeric = [];
        foreach ( $columns as $col ) {
            $total = 0;
            $ok = 0;
            foreach ( $rows as $row ) {
                if ( ! array_key_exists( $col, $row ) ) continue;
                $v = trim( (string) $row[ $col ] );
                if ( $v === '' ) continue;
                $total++;
                if ( $this->is_numericish( $v ) ) $ok++;
            }
            if ( $total === 0 ) continue;
            $ratio = $ok / $total;
            if ( $ratio >= 0.7 ) $numeric[] = $col;
        }
        return $numeric;
    }

    private function is_numericish( string $v ): bool {
        // Accept both "1.23" and "1,23"
        $v = str_replace( [ ' ', "\u{00A0}" ], '', $v );
        $v = str_replace( ',', '.', $v );
        return is_numeric( $v );
    }

    private function to_float( string $v ): ?float {
        $v = trim( (string) $v );
        if ( $v === '' ) return null;
        $v = str_replace( [ ' ', "\u{00A0}" ], '', $v );
        $v = str_replace( ',', '.', $v );
        if ( ! is_numeric( $v ) ) return null;
        return (float) $v;
    }

    /**
     * Build X for ML + target arrays (classification + regression).
     *
     * @return array{0:array,1:array,2:array,3:array}
     */
    private function build_xy_datasets( array $rows, array $feature_cols, string $target_col, bool $has_target ): array {
        $X_class = [];
        $y_class = [];

        $X_reg = [];
        $y_reg_values = [];
        $all_cols = $feature_cols;

        foreach ( $rows as $row ) {
            $x = [];
            $ok = true;
            foreach ( $feature_cols as $c ) {
                $f = $this->to_float( (string) ( $row[ $c ] ?? '' ) );
                if ( null === $f ) { $ok = false; break; }
                $x[] = $f;
            }
            if ( ! $ok ) continue;

            if ( $has_target ) {
                $y = (string) ( $row[ $target_col ] ?? '' );
                $y = trim( $y );
                if ( $y === '' ) continue;

                $X_class[] = $x;
                $y_class[] = $y;

                $yf = $this->to_float( $y );
                if ( null !== $yf ) {
                    $X_reg[] = $x;
                    $y_reg_values[] = $yf;
                }
            }
        }

        $y_reg = [
            'available' => false,
            'y' => [],
            'X' => [],
        ];
        if ( $has_target ) {
            $ratio = count( $y_class ) ? count( $y_reg_values ) / count( $y_class ) : 0;
            if ( $ratio >= 0.7 ) {
                $y_reg['available'] = true;
                $y_reg['y'] = $y_reg_values;
                $y_reg['X'] = $X_reg;
            }
        }

        return [ $X_class, $all_cols, $y_class, $y_reg ];
    }

    /**
     * Build X for clustering (all numeric feature cols, skip rows with missing values).
     *
     * @return array<int,array<int,float>>
     */
    private function build_X_only( array $rows, array $feature_cols ): array {
        $X = [];
        foreach ( $rows as $row ) {
            $x = [];
            $ok = true;
            foreach ( $feature_cols as $c ) {
                $f = $this->to_float( (string) ( $row[ $c ] ?? '' ) );
                if ( null === $f ) { $ok = false; break; }
                $x[] = $f;
            }
            if ( ! $ok ) continue;
            $X[] = $x;
        }
        return $X;
    }

    /**
     * Align X to regression targets that are numeric.
     *
     * @return array<int,array<int,float>>
     */
    private function align_X_to_regression( array $rows, array $feature_cols, string $target_col ): array {
        $X = [];
        foreach ( $rows as $row ) {
            $x = [];
            $ok = true;
            foreach ( $feature_cols as $c ) {
                $f = $this->to_float( (string) ( $row[ $c ] ?? '' ) );
                if ( null === $f ) { $ok = false; break; }
                $x[] = $f;
            }
            if ( ! $ok ) continue;

            $yf = $this->to_float( (string) ( $row[ $target_col ] ?? '' ) );
            if ( null === $yf ) continue;
            $X[] = $x;
        }
        return $X;
    }

    /* ─────────────────────────────────────────────────────────────
       Preprocessing: standardization + split
    ───────────────────────────────────────────────────────────── */

    private function train_test_split( array $X, array $y, float $test_ratio = 0.3 ): array {
        $n = count( $X );
        if ( $n !== count( $y ) ) {
            throw new \RuntimeException( 'X и y имеют разный размер.' );
        }
        $idx = range( 0, $n - 1 );
        // Deterministic shuffle based on current data.
        mt_srand( crc32( json_encode( [ $n, array_slice( $y, 0, 20 ) ] ) ) );
        shuffle( $idx );
        $test_n = max( 5, (int) floor( $n * $test_ratio ) );
        $test_n = min( $test_n, $n - 5 );

        $train_idx = array_slice( $idx, 0, $n - $test_n );
        $test_idx = array_slice( $idx, $n - $test_n );

        $X_train = $X_test = $y_train = $y_test = [];
        foreach ( $train_idx as $i ) { $X_train[] = $X[ $i ]; $y_train[] = $y[ $i ]; }
        foreach ( $test_idx as $i ) { $X_test[] = $X[ $i ]; $y_test[] = $y[ $i ]; }

        return [ $X_train, $X_test, $y_train, $y_test ];
    }

    /**
     * @param array<int,array<int,float>> $X
     * @return array{scaled: array, mean: array, std: array}
     */
    private function standardize( array $X ): array {
        $n = count( $X );
        if ( $n === 0 ) return [ 'scaled' => [], 'mean' => [], 'std' => [] ];
        $m = count( $X[0] );

        $mean = array_fill( 0, $m, 0.0 );
        $std = array_fill( 0, $m, 0.0 );

        for ( $j = 0; $j < $m; $j++ ) {
            $sum = 0.0;
            foreach ( $X as $row ) $sum += (float) $row[ $j ];
            $mean[ $j ] = $sum / $n;
        }
        for ( $j = 0; $j < $m; $j++ ) {
            $acc = 0.0;
            foreach ( $X as $row ) {
                $d = (float) $row[ $j ] - $mean[ $j ];
                $acc += $d * $d;
            }
            $var = $acc / max( 1, ( $n - 1 ) );
            $std[ $j ] = sqrt( $var );
            if ( $std[ $j ] <= 1e-12 ) $std[ $j ] = 1.0;
        }

        $scaled = [];
        foreach ( $X as $row ) {
            $r = [];
            for ( $j = 0; $j < $m; $j++ ) {
                $r[] = ( (float) $row[ $j ] - $mean[ $j ] ) / $std[ $j ];
            }
            $scaled[] = $r;
        }

        return [ 'scaled' => $scaled, 'mean' => $mean, 'std' => $std ];
    }

    /**
     * @param array<int,array<int,float>> $X
     * @param float[] $mean
     * @param float[] $std
     * @return array<int,array<int,float>>
     */
    private function apply_standardize( array $X, array $mean, array $std ): array {
        $scaled = [];
        foreach ( $X as $row ) {
            $r = [];
            foreach ( $row as $j => $v ) {
                $s = $std[ $j ] ?? 1.0;
                if ( $s == 0.0 ) $s = 1.0;
                $r[] = ( (float) $v - ( $mean[ $j ] ?? 0.0 ) ) / $s;
            }
            $scaled[] = $r;
        }
        return $scaled;
    }

    /* ─────────────────────────────────────────────────────────────
       Classification suite
    ───────────────────────────────────────────────────────────── */

    private function run_classification_suite( array $X_raw, array $y_class, array $X_all_cols, array &$conclusion, ?array &$best_clf ): array {
        $n = count( $X_raw );
        if ( $n < 10 || count( $y_class ) < 10 ) {
            return [ 'rendered' => 0, 'html' => '' ];
        }
        if ( count( $X_raw ) !== count( $y_class ) ) {
            // Regression alignment may have changed X_raw; for classification we rebuild X/y consistently.
            // Fallback: rebuild classification dataset strictly.
            // In current code structure we keep X_raw consistent by skipping non-numeric target rows.
            $n2 = min( count( $X_raw ), count( $y_class ) );
            $X_raw = array_slice( $X_raw, 0, $n2 );
            $y_class = array_slice( $y_class, 0, $n2 );
        }

        $classes = array_values( array_unique( $y_class ) );
        sort( $classes );
        if ( count( $classes ) < 2 ) {
            return [ 'rendered' => 0, 'html' => '<div class="wsp-notice"><p>Для выбранной целевой колонки классов меньше 2 — классификация неинформативна.</p></div>' ];
        }

        [$X_train, $X_test, $y_train, $y_test] = $this->train_test_split( $X_raw, $y_class, 0.3 );
        if ( count( $X_train ) < 6 || count( $X_test ) < 4 ) {
            return [ 'rendered' => 0, 'html' => '' ];
        }

        $sc = $this->standardize( $X_train );
        $X_train_s = $sc['scaled'];
        $X_test_s = $this->apply_standardize( $X_test, $sc['mean'], $sc['std'] );

        $html = '<div class="wsp-analysis-block">';
        $html .= '<h2>Классификация</h2>';

        $suite = [];

        $suite[] = [ 'id' => 'naive_bayes', 'title' => 'Наивный байес (Gaussian Naive Bayes)', 'model' => $this->clf_naive_bayes_gaussian_train( $X_train_s, $y_train ) ];
        $suite[] = [ 'id' => 'knn', 'title' => 'kNN (k=5)', 'model' => $this->clf_knn_train( $X_train_s, $y_train, 5 ) ];
        $suite[] = [ 'id' => 'logreg', 'title' => 'Логистическая регрессия (One-vs-Rest)', 'model' => $this->clf_logreg_train( $X_train_s, $y_train, 800, 0.05 ) ];
        $suite[] = [ 'id' => 'tree', 'title' => 'Дерево решений (CART, max depth=4)', 'model' => $this->clf_tree_train( $X_train_s, $y_train, 4, 5, 10 ) ];

        foreach ( $suite as $item ) {
            $pred = $this->clf_predict( $item['id'], $item['model'], $X_test_s );
            $accuracy = $this->accuracy( $y_test, $pred );

            if ( null === $best_clf || $accuracy > $best_clf['accuracy'] ) {
                $best_clf = [ 'id' => $item['id'], 'accuracy' => $accuracy, 'title' => $item['title'] ];
            }

            $html .= '<div class="wsp-analysis-subblock">';
            $html .= '<h3>' . esc_html( $item['title'] ) . '</h3>';
            $html .= '<p class="wsp-muted"><strong>Точность:</strong> ' . esc_html( number_format( $accuracy * 100, 2, '.', '' ) ) . '%</p>';

            $cm = $this->confusion_matrix( $y_test, $pred );
            $html .= $this->render_confusion_table( $cm );

            // Pred vs actual distribution.
            $dist_actual = $this->label_counts( $y_test );
            $dist_pred = $this->label_counts( $pred );
            $labels = array_values( array_unique( array_merge( array_keys( $dist_actual ), array_keys( $dist_pred ) ) ) );
            sort( $labels );
            $labels_out = $labels;
            $actual_data = array_map( fn($l) => (int) ( $dist_actual[ $l ] ?? 0 ), $labels_out );
            $pred_data = array_map( fn($l) => (int) ( $dist_pred[ $l ] ?? 0 ), $labels_out );

            $chart_html = WorldStat_UI::chart( [
                'type' => 'bar',
                'title' => 'Распределение классов (факт vs прогноз)',
                'labels' => $labels_out,
                'datasets' => [
                    [ 'label' => 'Факт', 'data' => $actual_data ],
                    [ 'label' => 'Прогноз', 'data' => $pred_data ],
                ],
                'x_label' => 'Класс',
                'y_label' => 'Количество',
                'height' => 260,
                'echo' => false,
            ] );

            $html .= '<div class="wsp-analysis-chart-desc">';
            $html .= $chart_html;
            $html .= '<p class="wsp-muted">Показывает, насколько модель смещает распределение прогнозов относительно фактических классов.</p>';
            $html .= '</div>';

            $html .= '</div>';
        }

        $html .= '</div>';

        if ( $best_clf ) {
            $conclusion['best_model'] = 'Лучший классификатор: ' . $best_clf['title'] . ' (' . number_format( $best_clf['accuracy'] * 100, 2, '.', '' ) . '%).';
        }

        return [ 'rendered' => count( $suite ), 'html' => $html ];
    }

    private function accuracy( array $y_true, array $y_pred ): float {
        $n = count( $y_true );
        if ( $n === 0 ) return 0.0;
        $ok = 0;
        foreach ( $y_true as $i => $v ) if ( (string) $v === (string) ( $y_pred[ $i ] ?? '' ) ) $ok++;
        return $ok / $n;
    }

    /**
     * @return array<string,array<string,int>> matrix[actual][pred]
     */
    private function confusion_matrix( array $y_true, array $y_pred ): array {
        $matrix = [];
        foreach ( $y_true as $i => $a ) {
            $p = (string) ( $y_pred[ $i ] ?? '' );
            $a = (string) $a;
            if ( ! isset( $matrix[ $a ] ) ) $matrix[ $a ] = [];
            if ( ! isset( $matrix[ $a ][ $p ] ) ) $matrix[ $a ][ $p ] = 0;
            $matrix[ $a ][ $p ]++;
        }
        return $matrix;
    }

    private function render_confusion_table( array $cm ): string {
        $labels = [];
        foreach ( $cm as $actual => $row ) {
            $labels[] = (string) $actual;
            foreach ( $row as $pred => $_cnt ) {
                $labels[] = (string) $pred;
            }
        }
        $labels = array_values( array_unique( $labels ) );
        sort( $labels, SORT_STRING );

        $headers = array_merge( [ 'Факт \\ Прогноз' ], $labels );
        $rows = [];
        foreach ( $labels as $actual ) {
            $r = [ $actual ];
            foreach ( $labels as $pred ) {
                $r[] = (int) ( $cm[ $actual ][ $pred ] ?? 0 );
            }
            $rows[] = $r;
        }

        return WorldStat_UI::table( [
            'headers' => $headers,
            'rows' => $rows,
            'sortable' => false,
            'searchable' => false,
            'exportable' => false,
            'echo' => false,
        ] );
    }

    private function label_counts( array $labels ): array {
        $cnt = [];
        foreach ( $labels as $l ) {
            $key = (string) $l;
            $cnt[ $key ] = ( $cnt[ $key ] ?? 0 ) + 1;
        }
        return $cnt;
    }

    /* ── Classifiers: model train/predict ─────────────────────── */

    private function clf_naive_bayes_gaussian_train( array $X, array $y ): array {
        $n = count( $X );
        $m = count( $X[0] );

        $classes = array_values( array_unique( $y ) );
        $stats = [];
        $class_counts = [];
        foreach ( $classes as $c ) $class_counts[ (string) $c ] = 0;

        foreach ( $X as $i => $row ) {
            $c = (string) $y[ $i ];
            $class_counts[ $c ]++;
            if ( ! isset( $stats[ $c ] ) ) $stats[ $c ] = [ 'sum' => array_fill( 0, $m, 0.0 ), 'sum2' => array_fill( 0, $m, 0.0 ) ];
            foreach ( $row as $j => $v ) {
                $stats[ $c ]['sum'][ $j ] += (float) $v;
                $stats[ $c ]['sum2'][ $j ] += (float) $v * (float) $v;
            }
        }

        $model = [
            'classes' => $classes,
            'priors' => [],
            'means' => [],
            'vars' => [],
        ];
        foreach ( $classes as $c ) {
            $c = (string) $c;
            $cnt = max( 1, (int) ( $class_counts[ $c ] ?? 0 ) );
            $model['priors'][ $c ] = $cnt / max( 1, $n );
            $model['means'][ $c ] = [];
            $model['vars'][ $c ] = [];
            for ( $j = 0; $j < $m; $j++ ) {
                $mean = ( $stats[ $c ]['sum'][ $j ] ?? 0.0 ) / $cnt;
                $var = ( $stats[ $c ]['sum2'][ $j ] ?? 0.0 ) / $cnt - $mean * $mean;
                if ( $var < 1e-9 ) $var = 1e-9;
                $model['means'][ $c ][ $j ] = $mean;
                $model['vars'][ $c ][ $j ] = $var;
            }
        }

        return $model;
    }

    private function clf_predict( string $id, array $model, array $X ): array {
        switch ( $id ) {
            case 'naive_bayes':
                return $this->clf_naive_bayes_gaussian_predict( $model, $X );
            case 'knn':
                return $this->clf_knn_predict( $model, $X );
            case 'logreg':
                return $this->clf_logreg_predict( $model, $X );
            case 'tree':
                return $this->clf_tree_predict( $model, $X );
            default:
                return array_fill( 0, count( $X ), '' );
        }
    }

    private function clf_naive_bayes_gaussian_predict( array $model, array $X ): array {
        $classes = $model['classes'] ?? [];
        $means = $model['means'] ?? [];
        $vars = $model['vars'] ?? [];
        $priors = $model['priors'] ?? [];

        $out = [];
        foreach ( $X as $row ) {
            $bestC = null;
            $bestLog = -INF;
            foreach ( $classes as $c0 ) {
                $c = (string) $c0;
                $logp = log( (float) ( $priors[ $c ] ?? 1e-9 ) );
                foreach ( $row as $j => $xj ) {
                    $mean = (float) ( $means[ $c ][ $j ] ?? 0.0 );
                    $var = (float) ( $vars[ $c ][ $j ] ?? 1e-9 );
                    $diff = (float) $xj - $mean;
                    $logp += -0.5 * log( 2 * M_PI * $var ) - ( $diff * $diff ) / ( 2 * $var );
                }
                if ( $logp > $bestLog ) {
                    $bestLog = $logp;
                    $bestC = $c;
                }
            }
            $out[] = $bestC ?? '';
        }
        return $out;
    }

    private function clf_knn_train( array $X_train, array $y_train, int $k ): array {
        return [
            'X' => $X_train,
            'y' => $y_train,
            'k' => $k,
        ];
    }

    private function clf_knn_predict( array $model, array $X_test ): array {
        $X_train = $model['X'] ?? [];
        $y_train = $model['y'] ?? [];
        $k = (int) ( $model['k'] ?? 5 );
        if ( empty( $X_train ) ) return array_fill( 0, count( $X_test ), '' );

        $out = [];
        foreach ( $X_test as $x ) {
            $dists = [];
            foreach ( $X_train as $i => $xt ) {
                $sum = 0.0;
                foreach ( $x as $j => $v ) {
                    $diff = (float) $v - (float) $xt[ $j ];
                    $sum += $diff * $diff;
                }
                $dists[] = [ 'dist' => $sum, 'label' => (string) $y_train[ $i ] ];
            }
            usort( $dists, fn($a, $b) => $a['dist'] <=> $b['dist'] );
            $k = max( 1, min( $k, count( $dists ) ) );
            $votes = [];
            for ( $i = 0; $i < $k; $i++ ) {
                $lab = $dists[ $i ]['label'];
                $votes[ $lab ] = ( $votes[ $lab ] ?? 0 ) + 1;
            }
            // Winner: max votes, tie-break by min distance sum.
            $best = null;
            $bestVotes = -1;
            foreach ( $votes as $lab => $cnt ) {
                if ( $cnt > $bestVotes ) {
                    $bestVotes = $cnt;
                    $best = $lab;
                }
            }
            $out[] = (string) $best;
        }
        return $out;
    }

    private function clf_logreg_train( array $X, array $y, int $iters = 600, float $lr = 0.05 ): array {
        $n = count( $X );
        $m = count( $X[0] );
        $classes = array_values( array_unique( $y ) );

        $models = [];
        foreach ( $classes as $c0 ) {
            $c = (string) $c0;
            $w = array_fill( 0, $m + 1, 0.0 ); // [intercept, w1..wm]

            // One-vs-rest
            for ( $it = 0; $it < $iters; $it++ ) {
                $grad = array_fill( 0, $m + 1, 0.0 );
                foreach ( $X as $i => $row ) {
                    $z = (float) $w[0];
                    for ( $j = 0; $j < $m; $j++ ) $z += (float) $w[ $j + 1 ] * (float) $row[ $j ];
                    $p = 1.0 / ( 1.0 + exp( -$z ) );
                    $ybin = ( (string) $y[ $i ] === $c ) ? 1.0 : 0.0;
                    $err = $p - $ybin;
                    $grad[0] += $err;
                    for ( $j = 0; $j < $m; $j++ ) $grad[ $j + 1 ] += $err * (float) $row[ $j ];
                }
                // Update
                for ( $j = 0; $j < $m + 1; $j++ ) {
                    $w[ $j ] -= $lr * ( $grad[ $j ] / max( 1, $n ) );
                }
            }

            $models[ $c ] = $w;
        }

        return [
            'models' => $models,
            'classes' => array_map( 'strval', $classes ),
        ];
    }

    private function clf_logreg_predict( array $model, array $X ): array {
        $models = $model['models'] ?? [];
        $classes = $model['classes'] ?? [];
        $out = [];
        $m = count( $X[0] ?? [] );

        foreach ( $X as $row ) {
            $bestC = null;
            $bestP = -INF;
            foreach ( $classes as $c ) {
                $w = $models[ (string) $c ] ?? null;
                if ( ! is_array( $w ) ) continue;
                $z = (float) $w[0];
                for ( $j = 0; $j < $m; $j++ ) $z += (float) $w[ $j + 1 ] * (float) $row[ $j ];
                $p = 1.0 / ( 1.0 + exp( -$z ) );
                if ( $p > $bestP ) { $bestP = $p; $bestC = $c; }
            }
            $out[] = (string) ( $bestC ?? '' );
        }
        return $out;
    }

    /**
     * CART-like tree for numeric features.
     */
    private function clf_tree_train( array $X, array $y, int $maxDepth, int $minSamplesSplit, int $maxThresholdsPerFeature ): array {
        $m = count( $X[0] );

        // Recursive build.
        $build = function( array $idx, int $depth ) use ( &$build, $X, $y, $m, $maxDepth, $minSamplesSplit, $maxThresholdsPerFeature ) {
            $n = count( $idx );
            $labels = [];
            foreach ( $idx as $i ) $labels[] = (string) $y[ $i ];
            $pred = $this->majority_label( $labels );
            if ( $depth >= $maxDepth || $n < $minSamplesSplit || $this->gini_impurity( $labels ) <= 1e-12 ) {
                return [ 'leaf' => true, 'pred' => $pred ];
            }

            $best = [ 'gini' => INF, 'feature' => null, 'thr' => null, 'left' => [], 'right' => [] ];
            for ( $j = 0; $j < $m; $j++ ) {
                // Candidate thresholds from quantiles.
                $vals = [];
                foreach ( $idx as $i ) $vals[] = (float) $X[ $i ][ $j ];
                sort( $vals );
                $uniq = array_values( array_unique( $vals ) );
                if ( count( $uniq ) <= 1 ) continue;

                $cands = [];
                $cntUniq = count( $uniq );
                $steps = min( $maxThresholdsPerFeature, $cntUniq - 1 );
                for ( $t = 1; $t <= $steps; $t++ ) {
                    $pos = (int) floor( $t * $cntUniq / ( $steps + 1 ) );
                    $pos = max( 0, min( $cntUniq - 1, $pos ) );
                    $cands[] = $uniq[ $pos ];
                }
                // Fallback
                if ( empty( $cands ) ) $cands = [ $uniq[1] ];

                foreach ( $cands as $thr ) {
                    $left = [];
                    $right = [];
                    foreach ( $idx as $i ) {
                        $v = (float) $X[ $i ][ $j ];
                        if ( $v <= (float) $thr ) $left[] = $i;
                        else $right[] = $i;
                    }
                    if ( empty( $left ) || empty( $right ) ) continue;
                    $gLeft = $this->gini_impurity( array_map( fn($i) => (string)$y[$i], $left ) );
                    $gRight = $this->gini_impurity( array_map( fn($i) => (string)$y[$i], $right ) );
                    $g = ( count( $left ) / $n ) * $gLeft + ( count( $right ) / $n ) * $gRight;
                    if ( $g < $best['gini'] ) {
                        $best['gini'] = $g;
                        $best['feature'] = $j;
                        $best['thr'] = $thr;
                        $best['left'] = $left;
                        $best['right'] = $right;
                    }
                }
            }

            if ( null === $best['feature'] ) {
                return [ 'leaf' => true, 'pred' => $pred ];
            }

            return [
                'leaf' => false,
                'pred' => $pred,
                'feature' => $best['feature'],
                'thr' => $best['thr'],
                'left' => $build( $best['left'], $depth + 1 ),
                'right' => $build( $best['right'], $depth + 1 ),
            ];
        };

        // Initial indices
        $idx = range( 0, count( $X ) - 1 );
        $tree = $build( $idx, 0 );

        return $tree;
    }

    private function majority_label( array $labels ): string {
        $cnt = [];
        foreach ( $labels as $l ) $cnt[ (string) $l ] = ( $cnt[ (string) $l ] ?? 0 ) + 1;
        arsort( $cnt );
        $keys = array_keys( $cnt );
        return (string) ( $keys[0] ?? '' );
    }

    private function gini_impurity( array $labels ): float {
        $n = count( $labels );
        if ( $n === 0 ) return 0.0;
        $cnt = [];
        foreach ( $labels as $l ) $cnt[ (string) $l ] = ( $cnt[ (string) $l ] ?? 0 ) + 1;
        $g = 1.0;
        foreach ( $cnt as $c ) {
            $p = $c / $n;
            $g -= $p * $p;
        }
        return $g;
    }

    private function clf_tree_predict( array $tree, array $X ): array {
        $out = [];
        foreach ( $X as $row ) {
            $node = $tree;
            while ( is_array( $node ) && empty( $node['leaf'] ) ) {
                $j = $node['feature'] ?? null;
                $thr = $node['thr'] ?? null;
                if ( null === $j || null === $thr ) break;

                $node = ( (float) ( $row[ $j ] ?? 0.0 ) <= (float) $thr )
                    ? ( $node['left'] ?? [] )
                    : ( $node['right'] ?? [] );

                if ( ! is_array( $node ) ) break;
            }

            if ( is_array( $node ) && ! empty( $node['leaf'] ) ) {
                $out[] = (string) ( $node['pred'] ?? '' );
            } else {
                $out[] = (string) ( $tree['pred'] ?? '' );
            }
        }
        return $out;
    }

    /* ─────────────────────────────────────────────────────────────
       Regression suite
    ───────────────────────────────────────────────────────────── */

    private function run_regression_suite( array $X_raw, array $y, array &$conclusion, ?array &$best_reg ): array {
        $n = count( $X_raw );
        if ( $n < 10 || count( $y ) < 10 ) {
            return [ 'html' => '' ];
        }
        $n2 = min( count( $X_raw ), count( $y ) );
        $X_raw = array_slice( $X_raw, 0, $n2 );
        $y = array_slice( $y, 0, $n2 );

        $classes = array_values( $y );
        if ( count( $X_raw ) !== count( $y ) ) {
            return [ 'html' => '' ];
        }

        [$X_train, $X_test, $y_train, $y_test] = $this->train_test_split( $X_raw, $y, 0.3 );

        $sc = $this->standardize( $X_train );
        $X_train_s = $sc['scaled'];
        $X_test_s = $this->apply_standardize( $X_test, $sc['mean'], $sc['std'] );

        // Polynomial expansion: x and x^2 per feature.
        $poly_train = $this->poly_expand_x2( $X_train_s );
        $poly_test = $this->poly_expand_x2( $X_test_s );

        $models = [];
        $models['linear'] = $this->reg_linear_train_gd( $X_train_s, $y_train, 2500, 0.01 );
        $models['polynomial_x2'] = $this->reg_linear_train_gd( $poly_train, $y_train, 2500, 0.005 );
        $models['ridge'] = $this->reg_ridge_train_gd( $X_train_s, $y_train, 2500, 0.01, 0.5 );
        $models['lasso'] = $this->reg_lasso_train_ista( $X_train_s, $y_train, 2500, 0.01, 0.1 );

        $preds = [];
        $metrics = [];

        foreach ( $models as $id => $model ) {
            $pred = $this->reg_predict_linear( $model, $id === 'polynomial_x2' ? $poly_test : $X_test_s );
            $preds[ $id ] = $pred;
            $mae = $this->reg_mae( $y_test, $pred );
            $rmse = $this->reg_rmse( $y_test, $pred );
            $metrics[ $id ] = [ 'mae' => $mae, 'rmse' => $rmse ];
            if ( null === $best_reg || $rmse < $best_reg['rmse'] ) {
                $best_reg = [ 'id' => $id, 'rmse' => $rmse, 'mae' => $mae ];
            }
        }

        if ( $best_reg ) {
            $conclusion['best_model'] = 'Лучшая регрессия: ' . esc_html( $best_reg['id'] ) . ' (RMSE=' . number_format( $best_reg['rmse'], 4, '.', '' ) . ').';
        }

        $out = '<div class="wsp-analysis-block">';
        $out .= '<h2>Регрессия</h2>';

        // Metrics table
        $headers = [ 'Метод', 'MAE', 'RMSE' ];
        $rows = [];
        foreach ( $metrics as $id => $m ) {
            $rows[] = [ $id, number_format( $m['mae'], 4, '.', '' ), number_format( $m['rmse'], 4, '.', '' ) ];
        }
        $out .= WorldStat_UI::table( [
            'headers' => $headers,
            'rows' => $rows,
            'sortable' => false,
            'searchable' => false,
            'exportable' => false,
            'echo' => false,
        ] );

        // Combined scatter chart
        $datasets = [];
        foreach ( $preds as $id => $pred ) {
            $pts = [];
            foreach ( $y_test as $i => $actual ) {
                $pts[] = [ 'x' => (float) $actual, 'y' => (float) $pred[ $i ] ];
            }
            $datasets[] = [ 'label' => $id, 'data' => $pts ];
        }

        $out .= WorldStat_UI::chart( [
            'type' => 'scatter',
            'title' => 'Факт vs Прогноз (сравнение моделей)',
            'labels' => [],
            'datasets' => $datasets,
            'x_label' => 'Факт',
            'y_label' => 'Прогноз',
            'height' => 320,
            'echo' => false,
        ] );
        $out .= '<p class="wsp-muted">Точки показывают соответствие фактических значений целевой переменной и предсказаний моделей на тестовой выборке.</p>';

        $out .= '</div>';
        return [ 'html' => $out ];
    }

    private function poly_expand_x2( array $X ): array {
        $out = [];
        foreach ( $X as $row ) {
            $r = [];
            foreach ( $row as $v ) {
                $r[] = (float) $v;
                $r[] = (float) $v * (float) $v;
            }
            $out[] = $r;
        }
        return $out;
    }

    private function reg_linear_train_gd( array $X, array $y, int $iters, float $lr ): array {
        $n = count( $X );
        $m = count( $X[0] );
        $w = array_fill( 0, $m + 1, 0.0 ); // intercept + weights

        for ( $it = 0; $it < $iters; $it++ ) {
            $grad = array_fill( 0, $m + 1, 0.0 );
            foreach ( $X as $i => $row ) {
                $yhat = (float) $w[0];
                for ( $j = 0; $j < $m; $j++ ) $yhat += (float) $w[ $j + 1 ] * (float) $row[ $j ];
                $err = (float) $yhat - (float) $y[ $i ];
                $grad[0] += 2.0 * $err;
                for ( $j = 0; $j < $m; $j++ ) $grad[ $j + 1 ] += 2.0 * $err * (float) $row[ $j ];
            }
            for ( $j = 0; $j < $m + 1; $j++ ) {
                $w[ $j ] -= $lr * ( $grad[ $j ] / max( 1, $n ) );
            }
        }

        return $w;
    }

    private function reg_ridge_train_gd( array $X, array $y, int $iters, float $lr, float $lambda ): array {
        $n = count( $X );
        $m = count( $X[0] );
        $w = array_fill( 0, $m + 1, 0.0 );

        for ( $it = 0; $it < $iters; $it++ ) {
            $grad = array_fill( 0, $m + 1, 0.0 );
            foreach ( $X as $i => $row ) {
                $yhat = (float) $w[0];
                for ( $j = 0; $j < $m; $j++ ) $yhat += (float) $w[ $j + 1 ] * (float) $row[ $j ];
                $err = (float) $yhat - (float) $y[ $i ];
                $grad[0] += 2.0 * $err;
                for ( $j = 0; $j < $m; $j++ ) $grad[ $j + 1 ] += 2.0 * $err * (float) $row[ $j ];
            }

            for ( $j = 0; $j < $m + 1; $j++ ) {
                $g = $grad[ $j ] / max( 1, $n );
                if ( $j >= 1 ) {
                    $g += 2.0 * $lambda * (float) $w[ $j ] / max( 1, $n );
                }
                $w[ $j ] -= $lr * $g;
            }
        }

        return $w;
    }

    private function reg_lasso_train_ista( array $X, array $y, int $iters, float $lr, float $lambda ): array {
        $n = count( $X );
        $m = count( $X[0] );
        $w = array_fill( 0, $m + 1, 0.0 ); // intercept + weights

        for ( $it = 0; $it < $iters; $it++ ) {
            // Gradient for SSE part (no L1 yet)
            $grad = array_fill( 0, $m + 1, 0.0 );
            foreach ( $X as $i => $row ) {
                $yhat = (float) $w[0];
                for ( $j = 0; $j < $m; $j++ ) $yhat += (float) $w[ $j + 1 ] * (float) $row[ $j ];
                $err = (float) $yhat - (float) $y[ $i ];
                $grad[0] += 2.0 * $err;
                for ( $j = 0; $j < $m; $j++ ) $grad[ $j + 1 ] += 2.0 * $err * (float) $row[ $j ];
            }
            for ( $j = 0; $j < $m + 1; $j++ ) {
                $w[ $j ] -= $lr * ( $grad[ $j ] / max( 1, $n ) );
                if ( $j >= 1 ) {
                    // Proximal soft-thresholding for L1 (excluding intercept)
                    $wj = (float) $w[ $j ];
                    $thr = $lr * $lambda;
                    if ( abs( $wj ) <= $thr ) $wj = 0.0;
                    else $wj = ( $wj > 0 ? 1 : -1 ) * ( abs( $wj ) - $thr );
                    $w[ $j ] = $wj;
                }
            }
        }

        return $w;
    }

    private function reg_predict_linear( array $w, array $X ): array {
        $out = [];
        $m = count( $X[0] ?? [] );
        foreach ( $X as $row ) {
            $yhat = (float) $w[0];
            for ( $j = 0; $j < $m; $j++ ) $yhat += (float) $w[ $j + 1 ] * (float) $row[ $j ];
            $out[] = $yhat;
        }
        return $out;
    }

    private function reg_mae( array $y_true, array $y_pred ): float {
        $n = count( $y_true );
        if ( $n === 0 ) return 0.0;
        $sum = 0.0;
        foreach ( $y_true as $i => $v ) {
            $sum += abs( (float) $v - (float) ( $y_pred[ $i ] ?? 0 ) );
        }
        return $sum / $n;
    }

    private function reg_rmse( array $y_true, array $y_pred ): float {
        $n = count( $y_true );
        if ( $n === 0 ) return 0.0;
        $sum = 0.0;
        foreach ( $y_true as $i => $v ) {
            $d = (float) $v - (float) ( $y_pred[ $i ] ?? 0 );
            $sum += $d * $d;
        }
        return sqrt( $sum / $n );
    }

    /* ─────────────────────────────────────────────────────────────
       Clustering suite
    ───────────────────────────────────────────────────────────── */

    private function run_clustering_suite(
        array $X_raw,
        array $feature_cols,
        int $k,
        float $eps,
        int $minpts,
        array &$conclusion
    ): array {
        $n = count( $X_raw );
        if ( $n < 10 ) {
            return [
                'html' => '<div class="wsp-analysis-block"><h2>Кластеризация</h2><p class="wsp-muted">Недостаточно данных для кластеризации.</p></div>'
            ];
        }

        $m = count( $X_raw[0] ?? [] );
        if ( $m === 0 ) {
            return [ 'html' => '' ];
        }

        // Standardize for clustering (eps in standardized space).
        $sc = $this->standardize( $X_raw );
        $X_s = $sc['scaled'];

        $out = '<div class="wsp-analysis-block">';
        $out .= '<h2>Кластеризация</h2>';

        $cluster_any = false;
        $summ = [];

        // KMeans
        $km = $this->cl_kmeans( $X_s, $k, 40 );
        $cluster_any = true;
        $summ[] = 'k-means: ' . (int) $k . ' кластеров';
        $out .= $this->render_cluster_charts( $X_raw, $km['labels'], 'K-Means (k=' . $k . ')', $feature_cols );

        // DBSCAN
        $db = $this->cl_dbscan( $X_s, $eps, $minpts );
        $cluster_any = true;
        $clusters_count = max( 0, (int) $db['clusters_count'] );
        $noise_count = max( 0, (int) $db['noise_count'] );
        $summ[] = 'DBSCAN: ' . $clusters_count . ' кластер(ов), шум=' . $noise_count;
        $out .= $this->render_cluster_charts( $X_raw, $db['labels'], 'DBSCAN (eps=' . $eps . ', minPts=' . $minpts . ')', $feature_cols );

        // Hierarchical (skip if too big)
        $hier_skip = false;
        if ( $n > 80 ) {
            $hier_skip = true;
        }
        if ( ! $hier_skip ) {
            $hc = $this->cl_hierarchical_agglomerative( $X_s, $k );
            $out .= $this->render_cluster_charts( $X_raw, $hc['labels'], 'Hierarchical (k=' . $k . ')', $feature_cols );
            $summ[] = 'Hierarchical: ' . (int) $k . ' кластеров';
        } else {
            $out .= '<div class="wsp-analysis-subblock"><h3>Hierarchical (пропущено)</h3><p class="wsp-muted">Агломеративная кластеризация O(n^2/3) пропущена, т.к. строк больше 80 (n=' . esc_html( (string) $n ) . ').</p></div>';
        }

        $conclusion['clusters'] = implode( '; ', $summ );
        $conclusion['recommendations'] = 'Подберите гиперпараметры кластеризации (k/eps) и используйте валидацию на дополнительных наборах данных.';

        $out .= '</div>';
        return [ 'html' => $out ];
    }

    private function render_cluster_charts( array $X_raw, array $labels, string $title, array $feature_cols ): string {
        $n = count( $labels );
        if ( $n === 0 ) return '';
        $m = count( $X_raw[0] ?? [] );

        // Sizes
        $cnt = [];
        foreach ( $labels as $l ) {
            $key = (string) (int) $l;
            $cnt[ $key ] = ( $cnt[ $key ] ?? 0 ) + 1;
        }
        ksort( $cnt, SORT_NUMERIC );

        $labels_out = array_map( fn($k) => $k === '-1' ? 'Шум' : 'C' . ( (int) $k + 1 ), array_keys( $cnt ) );
        $data_out = array_values( $cnt );

        $out = '<div class="wsp-analysis-subblock">';
        $out .= '<h3>' . esc_html( $title ) . '</h3>';
        $out .= '<p class="wsp-muted">Графики строятся по числовым признакам (первые 2 признака для scatter). Для DBSCAN параметр eps применён после стандартализации.</p>';

        $out .= '<div class="wsp-analysis-chart-desc">';
        $out .= WorldStat_UI::chart( [
            'type' => 'bar',
            'title' => 'Размеры кластеров',
            'labels' => $labels_out,
            'datasets' => [
                [ 'label' => 'Количество', 'data' => $data_out ],
            ],
            'legend' => true,
            'x_label' => 'Кластер',
            'y_label' => 'Количество объектов',
            'height' => 260,
            'echo' => false,
        ] );
        $out .= '<p class="wsp-muted">Показывает, сколько объектов попало в каждый кластер (или в шум для DBSCAN).</p>';
        $out .= '</div>';

        if ( $m >= 2 ) {
            $xIdx = 0;
            $yIdx = 1;
            // Group points by cluster.
            $clusters = [];
            foreach ( $labels as $i => $lab ) {
                $clusters[ (string) (int) $lab ][] = [
                    'x' => (float) $X_raw[ $i ][ $xIdx ],
                    'y' => (float) $X_raw[ $i ][ $yIdx ],
                ];
            }

            $palette = [ '#3366cc', '#dc3912', '#ff9900', '#109618', '#990099', '#0099c6', '#dd4477', '#66aa00', '#b82e2e', '#316395' ];
            $datasets = [];
            $colorIdx = 0;
            $sortedKeys = array_keys( $clusters );
            usort( $sortedKeys, fn($a,$b) => ((int)$a) <=> ((int)$b) );
            foreach ( $sortedKeys as $key ) {
                $lab = (int) $key;
                $name = $lab === -1 ? 'Шум' : 'C' . ( $lab + 1 );
                $datasets[] = [
                    'label' => $name,
                    'color' => $palette[ $colorIdx % count( $palette ) ],
                    'data' => $clusters[ $key ],
                ];
                $colorIdx++;
            }

            $out .= '<div class="wsp-analysis-chart-desc">';
            $out .= WorldStat_UI::chart( [
                'type' => 'scatter',
                'title' => 'Распределение объектов по кластерам',
                'labels' => [],
                'datasets' => $datasets,
                'x_label' => $feature_cols[ $xIdx ] ?? 'x1',
                'y_label' => $feature_cols[ $yIdx ] ?? 'x2',
                'height' => 320,
                'echo' => false,
            ] );
            $out .= '<p class="wsp-muted">Scatter показывает сегментацию на основе первых двух числовых признаков. Цвет соответствует номеру кластера.</p>';
            $out .= '</div>';
        }

        $out .= '</div>';
        return $out;
    }

    /* ── Clustering algorithms: k-means / DBSCAN / hierarchical ── */

    /**
     * @return array{labels:int[],centroids:array}
     */
    private function cl_kmeans( array $X, int $k, int $maxIter ): array {
        $n = count( $X );
        $m = count( $X[0] ?? [] );
        $k = max( 2, min( $k, $n ) );

        // Init centroids from random points
        $idx = range( 0, $n - 1 );
        mt_srand( crc32( json_encode( [ $n, $k ] ) ) );
        shuffle( $idx );
        $centroids = [];
        for ( $i = 0; $i < $k; $i++ ) {
            $centroids[] = $X[ $idx[ $i ] ];
        }

        $labels = array_fill( 0, $n, 0 );
        for ( $iter = 0; $iter < $maxIter; $iter++ ) {
            // Assignment
            for ( $i = 0; $i < $n; $i++ ) {
                $best = 0;
                $bestDist = INF;
                for ( $c = 0; $c < $k; $c++ ) {
                    $dist = 0.0;
                    for ( $j = 0; $j < $m; $j++ ) {
                        $d = (float) $X[ $i ][ $j ] - (float) $centroids[ $c ][ $j ];
                        $dist += $d * $d;
                    }
                    if ( $dist < $bestDist ) { $bestDist = $dist; $best = $c; }
                }
                $labels[ $i ] = $best;
            }

            // Update
            $newCentroids = array_fill( 0, $k, array_fill( 0, $m, 0.0 ) );
            $counts = array_fill( 0, $k, 0 );
            for ( $i = 0; $i < $n; $i++ ) {
                $c = (int) $labels[ $i ];
                $counts[ $c ]++;
                for ( $j = 0; $j < $m; $j++ ) $newCentroids[ $c ][ $j ] += (float) $X[ $i ][ $j ];
            }
            for ( $c = 0; $c < $k; $c++ ) {
                if ( $counts[ $c ] === 0 ) {
                    // Re-init empty cluster
                    $newCentroids[ $c ] = $X[ $idx[ rand( 0, $n - 1 ) ] ];
                    continue;
                }
                for ( $j = 0; $j < $m; $j++ ) $newCentroids[ $c ][ $j ] /= (float) $counts[ $c ];
            }
            $centroids = $newCentroids;
        }

        return [ 'labels' => $labels, 'centroids' => $centroids ];
    }

    /**
     * @return array{labels:int[],clusters_count:int,noise_count:int}
     */
    private function cl_dbscan( array $X, float $eps, int $minPts ): array {
        $n = count( $X );
        $m = count( $X[0] ?? [] );
        $labels = array_fill( 0, $n, -999 ); // -999 unvisited, -1 noise
        $clusterId = 0;

        $eps2 = $eps * $eps;
        $regionQuery = function( int $i ) use ( $X, $n, $m, $eps2 ) {
            $neighbors = [];
            for ( $j = 0; $j < $n; $j++ ) {
                $dist = 0.0;
                for ( $d = 0; $d < $m; $d++ ) {
                    $x = (float) $X[ $i ][ $d ];
                    $y = (float) $X[ $j ][ $d ];
                    $diff = $x - $y;
                    $dist += $diff * $diff;
                    if ( $dist > $eps2 ) break;
                }
                if ( $dist <= $eps2 ) $neighbors[] = $j;
            }
            return $neighbors;
        };

        for ( $i = 0; $i < $n; $i++ ) {
            if ( $labels[ $i ] !== -999 ) continue;
            $neighbors = $regionQuery( $i );
            if ( count( $neighbors ) < $minPts ) {
                $labels[ $i ] = -1;
                continue;
            }

            // Create new cluster
            $labels[ $i ] = $clusterId;
            $seed = $neighbors;
            $seedIdx = 0;
            while ( $seedIdx < count( $seed ) ) {
                $p = $seed[ $seedIdx ];
                if ( $labels[ $p ] === -1 ) $labels[ $p ] = $clusterId;
                if ( $labels[ $p ] === -999 ) {
                    $labels[ $p ] = $clusterId;
                    $pNeighbors = $regionQuery( $p );
                    if ( count( $pNeighbors ) >= $minPts ) {
                        foreach ( $pNeighbors as $nn ) {
                            $exists = false;
                            foreach ( $seed as $s ) { if ( $s === $nn ) { $exists = true; break; } }
                            if ( ! $exists ) $seed[] = $nn;
                        }
                    }
                }
                $seedIdx++;
            }

            $clusterId++;
        }

        $noise = 0;
        for ( $i = 0; $i < $n; $i++ ) if ( (int) $labels[ $i ] === -1 ) $noise++;

        return [
            'labels' => array_map( fn($l) => (int) $l, array_map( function($l){ return $l === -999 ? -1 : $l; }, $labels ) ),
            'clusters_count' => $clusterId,
            'noise_count' => $noise,
        ];
    }

    /**
     * Agglomerative clustering (average linkage) until k clusters.
     *
     * @return array{labels:int[]}
     */
    private function cl_hierarchical_agglomerative( array $X, int $k ): array {
        $n = count( $X );
        $k = max( 2, min( $k, $n ) );
        $m = count( $X[0] ?? [] );

        // Precompute point distances.
        $dist = array_fill( 0, $n, array_fill( 0, $n, 0.0 ) );
        for ( $i = 0; $i < $n; $i++ ) {
            for ( $j = $i + 1; $j < $n; $j++ ) {
                $d = 0.0;
                for ( $dIdx = 0; $dIdx < $m; $dIdx++ ) {
                    $diff = (float) $X[ $i ][ $dIdx ] - (float) $X[ $j ][ $dIdx ];
                    $d += $diff * $diff;
                }
                $dist[ $i ][ $j ] = $d;
                $dist[ $j ][ $i ] = $d;
            }
        }

        // clusters as arrays of point indices
        $clusters = [];
        for ( $i = 0; $i < $n; $i++ ) $clusters[] = [ $i ];

        $avgDist = function( array $A, array $B ) use ( $dist ) {
            $sum = 0.0;
            $cnt = 0;
            foreach ( $A as $i ) {
                foreach ( $B as $j ) {
                    $sum += (float) $dist[ $i ][ $j ];
                    $cnt++;
                }
            }
            if ( $cnt === 0 ) return INF;
            return $sum / $cnt;
        };

        while ( count( $clusters ) > $k ) {
            $bestI = null;
            $bestJ = null;
            $bestD = INF;
            $cCount = count( $clusters );
            for ( $i = 0; $i < $cCount; $i++ ) {
                for ( $j = $i + 1; $j < $cCount; $j++ ) {
                    $d = $avgDist( $clusters[ $i ], $clusters[ $j ] );
                    if ( $d < $bestD ) { $bestD = $d; $bestI = $i; $bestJ = $j; }
                }
            }
            if ( null === $bestI || null === $bestJ ) break;

            // merge J into I
            $clusters[ $bestI ] = array_merge( $clusters[ $bestI ], $clusters[ $bestJ ] );
            array_splice( $clusters, $bestJ, 1 );
        }

        // Produce labels
        $labels = array_fill( 0, $n, 0 );
        foreach ( $clusters as $cid => $pts ) {
            foreach ( $pts as $i ) $labels[ $i ] = (int) $cid;
        }

        return [ 'labels' => $labels ];
    }
}

WorldStat_Data_Analysis::init();

