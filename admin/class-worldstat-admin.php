<?php
/**
 * Admin panel — menus, dashboard, extensions manager, settings.
 *
 * @package WorldStatPlatform
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class WorldStat_Admin {

    private WorldStat_Extensions $extensions;

    public function __construct( WorldStat_Extensions $extensions ) {
        $this->extensions = $extensions;

        // Priority 5 — register BEFORE extensions (default priority 10)
        add_action( 'admin_menu', [ $this, 'register_menus' ], 5 );
        // CSV upload/delete must run before admin-header (redirect); page callback runs too late.
        add_action( 'admin_init', [ $this, 'handle_csv_admin_post' ], 0 );
        add_action( 'admin_init', [ $this, 'handle_csv_translations_post' ], 0 );
        add_action( 'admin_init', [ $this, 'maybe_redirect_stale_csv_error' ], 1 );
        add_action( 'admin_init', [ $this, 'register_settings' ] );
        add_action( 'admin_post_wsp_csv_delete', [ $this, 'handle_csv_delete_post' ] );

        // Custom columns for country list
        add_filter( 'manage_' . WorldStat_Country_CPT::SLUG . '_posts_columns',  [ $this, 'columns' ] );
        add_action( 'manage_' . WorldStat_Country_CPT::SLUG . '_posts_custom_column', [ $this, 'column_content' ], 10, 2 );
    }

    public function register_menus(): void {
        // Top-level menu
        add_menu_page(
            'World Statistics',
            'World Statistics',
            'manage_options',
            'worldstat',
            [ $this, 'page_dashboard' ],
            'dashicons-admin-site-alt3',
            30
        );

        // Dashboard sub-page
        add_submenu_page( 'worldstat', 'Dashboard', 'Dashboard', 'manage_options', 'worldstat', [ $this, 'page_dashboard' ] );

        // Countries
        add_submenu_page( 'worldstat', 'Страны', 'Страны', 'manage_options', 'edit.php?post_type=' . WorldStat_Country_CPT::SLUG );

        // Extensions Manager
        add_submenu_page( 'worldstat', 'Расширения', 'Расширения', 'manage_options', 'worldstat-extensions', [ $this, 'page_extensions' ] );

        // User CSV uploads
        add_submenu_page( 'worldstat', 'Данные CSV', 'Данные CSV', 'manage_options', 'worldstat-csv', [ $this, 'page_csv_data' ] );
        add_submenu_page( 'worldstat', __( 'Переводы показателей', 'flavor-worldstat' ), __( 'Переводы', 'flavor-worldstat' ), 'manage_options', 'worldstat-csv-translations', [ $this, 'page_csv_translations' ] );

        // Settings
        add_submenu_page( 'worldstat', 'Настройки', 'Настройки', 'manage_options', 'worldstat-settings', [ $this, 'page_settings' ] );
    }

    public function register_settings(): void {
        register_setting( 'wsp_settings', 'wsp_map_on_front', [ 'type' => 'boolean', 'default' => true ] );
        register_setting( 'wsp_settings', 'wsp_countries_per_page', [ 'type' => 'integer', 'default' => 200 ] );
        register_setting( 'wsp_settings', 'wsp_enable_rest_public', [ 'type' => 'boolean', 'default' => true ] );
    }

    /**
     * Drop ?wsp_csv_msg=error from URL after the flash message was already shown (avoid bogus notice on refresh).
     */
    public function maybe_redirect_stale_csv_error(): void {
        if ( ! isset( $_GET['page'] ) || sanitize_text_field( wp_unslash( $_GET['page'] ) ) !== 'worldstat-csv' ) {
            return;
        }
        if ( ! isset( $_GET['wsp_csv_msg'] ) || sanitize_key( wp_unslash( $_GET['wsp_csv_msg'] ) ) !== 'error' ) {
            return;
        }
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        if ( WorldStat_Uploaded_Csv::has_admin_error_flash() ) {
            return;
        }
        wp_safe_redirect( admin_url( 'admin.php?page=worldstat-csv' ) );
        exit;
    }

    /**
     * Process CSV admin forms before any HTML output (see wp-admin/admin.php: admin_init → admin-header → page callback).
     */
    public function handle_csv_admin_post(): void {
        if ( ! isset( $_GET['page'] ) || sanitize_text_field( wp_unslash( $_GET['page'] ) ) !== 'worldstat-csv' ) {
            return;
        }
        if ( ! isset( $_POST['wsp_csv_nonce'] ) ) {
            return;
        }
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Недостаточно прав.', 'flavor-worldstat' ), '', [ 'response' => 403 ] );
        }

        $nonce = sanitize_text_field( wp_unslash( $_POST['wsp_csv_nonce'] ) );
        if ( ! wp_verify_nonce( $nonce, 'wsp_csv_manage' ) ) {
            wp_die( esc_html__( 'Неверный запрос.', 'flavor-worldstat' ), '', [ 'response' => 403 ] );
        }

        $action   = sanitize_text_field( wp_unslash( $_POST['wsp_csv_form_action'] ?? '' ) );
        $redirect = admin_url( 'admin.php?page=worldstat-csv' );

        if ( $action === 'upload' && ! empty( $_FILES['wsp_csv_file'] ) ) {
            $kind_raw = isset( $_POST['wsp_csv_dataset_kind'] ) ? sanitize_key( wp_unslash( $_POST['wsp_csv_dataset_kind'] ) ) : WorldStat_Uploaded_Csv::KIND_COUNTRY;
            $kind     = WorldStat_Uploaded_Csv::sanitize_dataset_kind( $kind_raw );
            WorldStat_Uploaded_Csv::remember_last_dataset_kind_for_user( get_current_user_id(), $kind );
            $result   = WorldStat_Uploaded_Csv::save_upload( $_FILES['wsp_csv_file'], $kind );
            if ( is_wp_error( $result ) ) {
                WorldStat_Uploaded_Csv::set_admin_error_flash( $result->get_error_message() );
                wp_safe_redirect(
                    add_query_arg(
                        [
                            'wsp_csv_msg'  => 'error',
                            'wsp_csv_kind' => $kind,
                        ],
                        $redirect
                    )
                );
                exit;
            }
            wp_safe_redirect(
                add_query_arg(
                    [
                        'wsp_csv_msg'  => 'upload_ok',
                        'wsp_csv_file' => $result,
                        'wsp_csv_kind' => $kind,
                    ],
                    $redirect
                )
            );
            exit;
        }

    }

    /**
     * Импорт UTF-8 CSV «ключ → русская подпись» в опцию эргономики wsergo_data_labels_ru.
     */
    public function handle_csv_translations_post(): void {
        if ( ! isset( $_GET['page'] ) || sanitize_text_field( wp_unslash( $_GET['page'] ) ) !== 'worldstat-csv-translations' ) {
            return;
        }
        if ( ! isset( $_POST['wsp_csv_translations_nonce'] ) ) {
            return;
        }
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Недостаточно прав.', 'flavor-worldstat' ), '', [ 'response' => 403 ] );
        }
        check_admin_referer( 'wsp_csv_translations_upload', 'wsp_csv_translations_nonce' );

        if ( empty( $_FILES['wsp_translations_csv']['tmp_name'] ) ) {
            WorldStat_Uploaded_Csv::set_admin_error_flash( __( 'Выберите файл CSV.', 'flavor-worldstat' ) );
            wp_safe_redirect( admin_url( 'admin.php?page=worldstat-csv-translations&wsp_tr_msg=error' ) );
            exit;
        }
        if ( ! isset( $_FILES['wsp_translations_csv']['error'] ) || (int) $_FILES['wsp_translations_csv']['error'] !== UPLOAD_ERR_OK ) {
            WorldStat_Uploaded_Csv::set_admin_error_flash( __( 'Файл не был загружен.', 'flavor-worldstat' ) );
            wp_safe_redirect( admin_url( 'admin.php?page=worldstat-csv-translations&wsp_tr_msg=error' ) );
            exit;
        }
        if ( ! class_exists( 'WSErgo_Settings' ) ) {
            WorldStat_Uploaded_Csv::set_admin_error_flash( __( 'Нужен активный плагин WorldStat — Ergonomics (опция подписей хранится там).', 'flavor-worldstat' ) );
            wp_safe_redirect( admin_url( 'admin.php?page=worldstat-csv-translations&wsp_tr_msg=error' ) );
            exit;
        }

        $tmp = sanitize_text_field( wp_unslash( $_FILES['wsp_translations_csv']['tmp_name'] ) );
        if ( $tmp === '' || ! is_uploaded_file( $tmp ) || ! is_readable( $tmp ) ) {
            WorldStat_Uploaded_Csv::set_admin_error_flash( __( 'Не удалось прочитать загруженный файл.', 'flavor-worldstat' ) );
            wp_safe_redirect( admin_url( 'admin.php?page=worldstat-csv-translations&wsp_tr_msg=error' ) );
            exit;
        }

        $parsed = $this->parse_translations_csv_file( $tmp );
        if ( is_wp_error( $parsed ) ) {
            WorldStat_Uploaded_Csv::set_admin_error_flash( $parsed->get_error_message() );
            wp_safe_redirect( admin_url( 'admin.php?page=worldstat-csv-translations&wsp_tr_msg=error' ) );
            exit;
        }

        $opt_key  = WSErgo_Settings::OPTION_DATA_LABELS_RU;
        $existing = get_option( $opt_key, [] );
        if ( ! is_array( $existing ) ) {
            $existing = [];
        }
        $merged = array_merge( $existing, $parsed );
        $merged = $this->sanitize_translations_map( $merged );
        update_option( $opt_key, $merged, false );

        $n = count( $parsed );

        wp_safe_redirect( admin_url( 'admin.php?page=worldstat-csv-translations&wsp_tr_msg=ok&wsp_tr_n=' . (int) $n ) );
        exit;
    }

    /**
     * @param string $path Path to uploaded temp file.
     * @return array<string, string>|\WP_Error
     */
    private function parse_translations_csv_file( string $path ) {
        $fh = fopen( $path, 'rb' );
        if ( ! $fh ) {
            return new \WP_Error( 'wsp_tr_read', __( 'Не удалось открыть файл.', 'flavor-worldstat' ) );
        }
        $first = fgetcsv( $fh );
        if ( ! is_array( $first ) || ( isset( $first[0] ) && trim( (string) $first[0] ) === '' ) ) {
            fclose( $fh );
            return new \WP_Error( 'wsp_tr_empty', __( 'Пустой CSV.', 'flavor-worldstat' ) );
        }
        $skip_first = $this->csv_row_is_translation_header( $first );
        if ( ! $skip_first ) {
            rewind( $fh );
        }
        $out = [];
        while ( ( $row = fgetcsv( $fh ) ) !== false ) {
            if ( ! is_array( $row ) || count( $row ) < 2 ) {
                continue;
            }
            $k = sanitize_key( trim( (string) ( $row[0] ?? '' ) ) );
            if ( $k === '' || strlen( $k ) > 96 ) {
                continue;
            }
            $label = sanitize_text_field( (string) ( $row[1] ?? '' ) );
            if ( $label === '' ) {
                continue;
            }
            $out[ $k ] = $label;
            if ( count( $out ) >= 10000 ) {
                break;
            }
        }
        fclose( $fh );
        if ( count( $out ) < 1 ) {
            return new \WP_Error( 'wsp_tr_nodata', __( 'Не найдено ни одной строки с двумя колонками (ключ и подпись).', 'flavor-worldstat' ) );
        }
        return $out;
    }

    /**
     * @param list<string> $row
     */
    private function csv_row_is_translation_header( array $row ): bool {
        $c0 = isset( $row[0] ) ? strtolower( trim( (string) $row[0] ) ) : '';
        return in_array( $c0, [ 'key', 'slug', 'код', 'indicator', 'id', 'column', 'field' ], true );
    }

    /**
     * @param array<string, string> $map
     * @return array<string, string>
     */
    private function sanitize_translations_map( array $map ): array {
        $out = [];
        foreach ( $map as $k => $v ) {
            $key = sanitize_key( (string) $k );
            if ( $key === '' || strlen( $key ) > 96 ) {
                continue;
            }
            $text = sanitize_text_field( (string) $v );
            if ( $text !== '' ) {
                $out[ $key ] = function_exists( 'mb_substr' ) ? mb_substr( $text, 0, 240 ) : substr( $text, 0, 240 );
            }
        }
        return $out;
    }

    /**
     * Delete uploaded CSV via admin-post.php (avoids POST to admin.php losing ?page= and runs before any output).
     */
    public function handle_csv_delete_post(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Недостаточно прав.', 'flavor-worldstat' ), '', [ 'response' => 403 ] );
        }
        check_admin_referer( 'wsp_csv_delete' );

        $name = wp_basename( wp_unslash( $_REQUEST['wsp_csv_delete_name'] ?? '' ) );
        $result = WorldStat_Uploaded_Csv::delete_file( $name );
        $back   = admin_url( 'admin.php?page=worldstat-csv' );

        if ( is_wp_error( $result ) ) {
            WorldStat_Uploaded_Csv::set_admin_error_flash( $result->get_error_message() );
            wp_safe_redirect( add_query_arg( 'wsp_csv_msg', 'error', $back ) );
            exit;
        }

        wp_safe_redirect(
            add_query_arg(
                [
                    'wsp_csv_msg'  => 'delete_ok',
                    'wsp_csv_file' => $name,
                ],
                $back
            )
        );
        exit;
    }

    /* ═══════════════════════════════════════════════════════
       PAGES
    ═══════════════════════════════════════════════════════ */

    public function page_dashboard(): void {
        $exts    = $this->extensions->get_all();
        $metrics = $this->extensions->get_all_metrics();
        $count   = wp_count_posts( WorldStat_Country_CPT::SLUG );

        // Detect duplicates of analysis page by slug prefix (analysis-data*).
        global $wpdb;
        $slug = 'analysis-data';
        $ids = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT ID FROM {$wpdb->posts}
                 WHERE post_type = 'page'
                   AND post_name LIKE %s",
                $slug . '%'
            )
        );
        $ids = array_values( array_filter( array_map( 'intval', (array) $ids ) ) );
        $analysis_dups_count = max( 0, count( $ids ) - 1 );
        $analysis_dups_total = count( $ids );

        include WSP_PLUGIN_DIR . 'admin/views/dashboard.php';
    }

    public function page_extensions(): void {
        $exts   = $this->extensions->get_all();
        $layers = $this->extensions->get_layers();
        $tabs   = $this->extensions->get_tabs();
        include WSP_PLUGIN_DIR . 'admin/views/extensions.php';
    }

    public function page_settings(): void {
        include WSP_PLUGIN_DIR . 'admin/views/settings.php';
    }

    public function page_csv_data(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Недостаточно прав.', 'flavor-worldstat' ) );
        }

        $wsp_csv_files = WorldStat_Uploaded_Csv::list_files();
        include WSP_PLUGIN_DIR . 'admin/views/csv-data.php';
    }

    public function page_csv_translations(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Недостаточно прав.', 'flavor-worldstat' ) );
        }

        include WSP_PLUGIN_DIR . 'admin/views/csv-translations.php';
    }

    /* ═══════════════════════════════════════════════════════
       CUSTOM COLUMNS
    ═══════════════════════════════════════════════════════ */

    public function columns( array $cols ): array {
        $new = [];
        $new['cb']         = $cols['cb'];
        $new['wsp_flag']   = '🏳';
        $new['title']      = $cols['title'];
        $new['wsp_iso']    = 'ISO';
        $new['wsp_capital']= 'Столица';
        $new['wsp_pop']    = 'Население';
        $new['wsp_area']   = 'Площадь';
        $new['wsp_region'] = 'Регион';
        $new['date']       = $cols['date'] ?? 'Дата';
        return $new;
    }

    public function column_content( string $column, int $post_id ): void {
        switch ( $column ) {
            case 'wsp_flag':
                echo esc_html( get_post_meta( $post_id, 'wsp_flag', true ) );
                break;
            case 'wsp_iso':
                echo esc_html( get_post_meta( $post_id, 'wsp_iso_alpha2', true ) );
                echo ' / ';
                echo esc_html( get_post_meta( $post_id, 'wsp_iso_alpha3', true ) );
                break;
            case 'wsp_capital':
                echo esc_html( get_post_meta( $post_id, 'wsp_capital_ru', true ) );
                break;
            case 'wsp_pop':
                echo number_format( (int) get_post_meta( $post_id, 'wsp_population', true ), 0, '', ' ' );
                break;
            case 'wsp_area':
                echo number_format( (int) get_post_meta( $post_id, 'wsp_area_km2', true ), 0, '', ' ' ) . ' км²';
                break;
            case 'wsp_region':
                $terms = wp_get_post_terms( $post_id, WorldStat_Taxonomies::REGION );
                echo ( $terms && ! is_wp_error( $terms ) ) ? esc_html( $terms[0]->name ) : '—';
                break;
        }
    }
}
