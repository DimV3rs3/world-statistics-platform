<?php
/**
 * Extension registration, lifecycle, dependency resolution, tab/layer/export management.
 *
 * @package WorldStatPlatform
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class WorldStat_Extensions {

    /** @var array Registered extensions: id => config */
    private array $extensions = [];

    /** @var array Data providers: "ext_id.metric" => [ 'callback' => callable, 'meta' => [] ] */
    private array $providers = [];

    /** @var array<string, array<string, array>> Country tabs: ext_id => tab_id => config */
    private array $tabs = [];

    /** @var array Map layers: ext_id => [ [ 'label', 'type', 'color_scale', 'data_callback' ], ... ] */
    private array $layers = [];

    /** @var array Map marker layers: [ [ 'ext_id', 'label', 'icon', 'color', 'data_callback' ], ... ] */
    private array $marker_layers = [];

    /** @var array Export handlers: ext_id => [ 'formats' => [], 'callback' => callable ] */
    private array $exports = [];

    /** @var bool Whether registration is locked */
    private bool $locked = false;

    public function __construct() {
        add_action( 'worldstat_init', [ $this, 'lock_registration' ], 999 );
    }

    /* ═══════════════════════════════════════════════════════
       REGISTRATION
    ═══════════════════════════════════════════════════════ */

    /**
     * Register an extension.
     *
     * @param array $config {
     *   @type string $id                Required. Unique slug.
     *   @type string $name              Required. Human-readable name.
     *   @type string $version           Required. Semver.
     *   @type string $author            Author name.
     *   @type string $description       Description text.
     *   @type string $icon              Dashicons class or URL.
     *   @type string $requires_platform Minimum platform version.
     *   @type array  $depends           IDs of required extensions.
     *   @type array  $metrics           Metric definitions [ key => [ label, type, unit ] ].
     * }
     * @return bool
     */
    public static function register( array $config ): bool {
        $platform = worldstat_platform();
        return $platform->extensions->_register( $config );
    }

    public function _register( array $config ): bool {
        if ( $this->locked ) {
            _doing_it_wrong( __METHOD__, 'Extension registration is locked. Register on worldstat_init hook.', WSP_VERSION );
            return false;
        }

        $id = $config['id'] ?? '';
        if ( ! $id || isset( $this->extensions[ $id ] ) ) return false;

        // Version check
        $requires = $config['requires_platform'] ?? '1.0.0';
        if ( version_compare( WSP_VERSION, $requires, '<' ) ) {
            add_action( 'admin_notices', function () use ( $config, $requires ) {
                printf( '<div class="notice notice-warning"><p>Extension <strong>%s</strong> requires platform v%s+.</p></div>',
                    esc_html( $config['name'] ?? $config['id'] ), esc_html( $requires ) );
            } );
            return false;
        }

        $this->extensions[ $id ] = wp_parse_args( $config, [
            'id'               => $id,
            'name'             => $id,
            'version'          => '1.0.0',
            'author'           => '',
            'description'      => '',
            'icon'             => 'dashicons-admin-plugins',
            'requires_platform'=> '1.0.0',
            'depends'          => [],
            'metrics'          => [],
            'registered_at'    => time(),
        ] );

        do_action( 'worldstat_extension_registered', $id, $this->extensions[ $id ] );
        return true;
    }

    /**
     * Register a data provider for a specific metric.
     */
    public static function add_data_provider( string $ext_id, array $config ): void {
        $platform = worldstat_platform();
        $platform->extensions->_add_data_provider( $ext_id, $config );
    }

    public function _add_data_provider( string $ext_id, array $config ): void {
        if ( ! isset( $this->extensions[ $ext_id ] ) ) return;

        $metrics = $config['metrics'] ?? [];
        foreach ( $metrics as $key => $def ) {
            $provider_key = $ext_id . '.' . $key;
            $this->providers[ $provider_key ] = [
                'ext_id'   => $ext_id,
                'metric'   => $key,
                'callback' => $def['callback'] ?? null,
                'label'    => $def['label'] ?? $key,
                'type'     => $def['type'] ?? 'number',
                'unit'     => $def['unit'] ?? '',
                'description' => $def['description'] ?? '',
            ];
        }
    }

    /**
     * Register a tab on the country page.
     */
    public static function add_country_tab( string $ext_id, array $config ): void {
        $platform = worldstat_platform();
        $platform->extensions->_add_country_tab( $ext_id, $config );
    }

    public function _add_country_tab( string $ext_id, array $config ): void {
        if ( ! isset( $this->extensions[ $ext_id ] ) ) {
            return;
        }

        // Явный id вкладки (например compare); иначе совпадает с ext_id (как у cities, ergonomics).
        $tab_id = isset( $config['id'] ) ? sanitize_key( (string) $config['id'] ) : $ext_id;
        if ( $tab_id === '' ) {
            $tab_id = $ext_id;
        }

        if ( ! isset( $this->tabs[ $ext_id ] ) || ! is_array( $this->tabs[ $ext_id ] ) ) {
            $this->tabs[ $ext_id ] = [];
        }

        // Обратная совместимость: старый формат — одна вкладка = плоский массив с title.
        if ( isset( $this->tabs[ $ext_id ]['title'] ) ) {
            $legacy_id = $ext_id;
            $legacy    = $this->tabs[ $ext_id ];
            $this->tabs[ $ext_id ] = [ $legacy_id => $legacy ];
        }

        $this->tabs[ $ext_id ][ $tab_id ] = wp_parse_args( $config, [
            'id'       => $tab_id,
            'title'    => $this->extensions[ $ext_id ]['name'],
            'icon'     => $this->extensions[ $ext_id ]['icon'],
            'callback' => null,
            'priority' => 50,
        ] );
    }

    /**
     * Register a map layer.
     */
    public static function add_map_layer( string $ext_id, array $config ): void {
        $platform = worldstat_platform();
        $platform->extensions->_add_map_layer( $ext_id, $config );
    }

    public function _add_map_layer( string $ext_id, array $config ): void {
        if ( ! isset( $this->extensions[ $ext_id ] ) ) return;

        $config['ext_id'] = $ext_id;
        $this->layers[] = wp_parse_args( $config, [
            'ext_id'        => $ext_id,
            'label'         => 'Layer',
            'type'          => 'choropleth',
            'color_scale'   => [ '#f0f0f0', '#003d99' ],
            'data_callback' => null,
        ] );
    }

    /**
     * Register a marker layer on the map.
     *
     * Extensions call this to place coordinate-based points on the map.
     * The callback should return an array of markers:
     *   [ [ 'lat' => float, 'lng' => float, 'title' => string, 'value' => mixed, 'popup' => string ], ... ]
     *
     * Optionally pass 'country_callback' that accepts ISO2 code and returns markers for that country only.
     *
     * @param string $ext_id  Extension ID (must be registered).
     * @param array  $config {
     *   @type string   $label             Layer display name.
     *   @type string   $icon              Marker icon type: 'circle'|'pin'|'square'|'diamond'. Default 'circle'.
     *   @type string   $color             Marker color (hex). Default '#ef4444'.
     *   @type int      $radius            Circle radius in px. Default 6.
     *   @type callable $data_callback     Returns ALL markers (global layer). [ [ lat, lng, title, value?, popup? ] ]
     *   @type callable $country_callback  Returns markers for a specific country (ISO2 passed as arg).
     * }
     */
    public static function add_map_markers( string $ext_id, array $config ): void {
        $platform = worldstat_platform();
        $platform->extensions->_add_map_markers( $ext_id, $config );
    }

    public function _add_map_markers( string $ext_id, array $config ): void {
        if ( ! isset( $this->extensions[ $ext_id ] ) ) return;

        $config['ext_id'] = $ext_id;
        $this->marker_layers[] = wp_parse_args( $config, [
            'ext_id'           => $ext_id,
            'label'            => $this->extensions[ $ext_id ]['name'],
            'icon'             => 'circle',
            'color'            => '#ef4444',
            'radius'           => 6,
            'data_callback'    => null,
            'country_callback' => null,
        ] );
    }

    /**
     * Register an export handler.
     */
    public static function add_export( string $ext_id, array $config ): void {
        $platform = worldstat_platform();
        $platform->extensions->_add_export( $ext_id, $config );
    }

    public function _add_export( string $ext_id, array $config ): void {
        if ( ! isset( $this->extensions[ $ext_id ] ) ) return;

        $this->exports[ $ext_id ] = wp_parse_args( $config, [
            'formats'  => [ 'csv', 'json' ],
            'callback' => null,
        ] );
    }

    /* ═══════════════════════════════════════════════════════
       GETTERS
    ═══════════════════════════════════════════════════════ */

    public function get_all(): array                { return $this->extensions; }
    public function get( string $id ): ?array       { return $this->extensions[ $id ] ?? null; }
    public function is_registered( string $id ): bool { return isset( $this->extensions[ $id ] ); }
    public function count(): int                     { return count( $this->extensions ); }

    public function get_providers(): array           { return $this->providers; }
    public function get_provider( string $key ): ?array { return $this->providers[ $key ] ?? null; }

    /**
     * Все вкладки: для каждой вкладки ключ = tab_id; для расширения с одной вкладкой также ключ ext_id.
     *
     * @return array<string, array>
     */
    public function get_tabs(): array {
        $out = [];
        foreach ( $this->tabs as $ext_id => $tab_group ) {
            if ( ! is_array( $tab_group ) ) {
                continue;
            }
            if ( isset( $tab_group['title'] ) ) {
                $out[ $ext_id ] = $tab_group;
                continue;
            }
            $primary = null;
            foreach ( $tab_group as $tab_id => $config ) {
                if ( ! is_array( $config ) ) {
                    continue;
                }
                $out[ $tab_id ] = $config;
                if ( null === $primary || (int) ( $config['priority'] ?? 50 ) < (int) ( $primary['priority'] ?? 50 ) ) {
                    $primary = $config;
                }
            }
            if ( $primary ) {
                $out[ $ext_id ] = $primary;
            }
        }
        return $out;
    }

    /**
     * Вкладка по slug (compare, ergonomics, cities…).
     */
    public function get_tab( string $tab_id ): ?array {
        $tab_id = sanitize_key( $tab_id );
        if ( $tab_id === '' ) {
            return null;
        }
        foreach ( $this->tabs as $ext_id => $tab_group ) {
            if ( ! is_array( $tab_group ) ) {
                continue;
            }
            if ( isset( $tab_group['title'] ) && $ext_id === $tab_id ) {
                return $tab_group;
            }
            if ( isset( $tab_group[ $tab_id ] ) && is_array( $tab_group[ $tab_id ] ) ) {
                return $tab_group[ $tab_id ];
            }
        }
        return null;
    }

    public function get_layers(): array              { return $this->layers; }
    public function get_marker_layers(): array       { return $this->marker_layers; }
    public function get_exports(): array             { return $this->exports; }

    public function get_all_metrics(): array {
        $out = [];
        foreach ( $this->providers as $key => $p ) {
            $out[ $key ] = [
                'extension'   => $p['ext_id'],
                'metric'      => $p['metric'],
                'label'       => $p['label'],
                'type'        => $p['type'],
                'unit'        => $p['unit'],
                'description' => $p['description'],
            ];
        }
        return $out;
    }

    /**
     * Call a data provider.
     */
    public function call_provider( string $ext_id, string $country_code, string $metric ) {
        $key = $ext_id . '.' . $metric;
        $provider = $this->providers[ $key ] ?? null;

        if ( $provider && is_callable( $provider['callback'] ) ) {
            try {
                return call_user_func( $provider['callback'], $country_code );
            } catch ( \Throwable $e ) {
                if ( defined( 'WP_DEBUG' ) && WP_DEBUG && function_exists( 'error_log' ) ) {
                    // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
                    error_log(
                        sprintf(
                            '[World Statistics Platform] Metric provider error (%s.%s): %s',
                            $ext_id,
                            $metric,
                            $e->getMessage()
                        )
                    );
                }
            }
        }

        return apply_filters( 'worldstat_get_data', null, $ext_id, $country_code, $metric );
    }

    /**
     * Check if extension dependencies are met.
     */
    public function check_dependencies( string $ext_id ): array {
        $ext = $this->extensions[ $ext_id ] ?? null;
        if ( ! $ext ) return [ 'met' => false, 'missing' => [ $ext_id ] ];

        $missing = [];
        foreach ( $ext['depends'] as $dep ) {
            if ( $dep !== 'core' && ! isset( $this->extensions[ $dep ] ) ) {
                $missing[] = $dep;
            }
        }

        return [ 'met' => empty( $missing ), 'missing' => $missing ];
    }

    public function lock_registration(): void {
        $this->locked = true;
        do_action( 'worldstat_extensions_locked', $this->extensions, $this->providers );
    }
}
