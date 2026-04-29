<?php
/**
 * Página de configuración del plugin DaleCafe Facturas.
 * Aparece en: WooCommerce → DaleCafe Facturas
 */

defined( 'ABSPATH' ) || exit;

class DFC_Settings {

    const OPTION_API_URL               = 'dfc_api_url';
    const OPTION_API_USUARIO           = 'dfc_api_usuario';
    const OPTION_API_CLAVE             = 'dfc_api_clave';
    const OPTION_API_MODE              = 'dfc_api_mode';         // 'production' | 'test'
    const OPTION_AUTO_INVOICE          = 'dfc_auto_invoice';     // '1' | '0'
    const OPTION_INVOICE_SUBSCRIPTIONS = 'dfc_invoice_subscriptions'; // '1' | '0'
    const OPTION_DEBUG_MODE            = 'dfc_debug_mode';       // '1' | '0'
    const OPTION_PLU_MAP               = 'dfc_plu_map';          // JSON array

    /**
     * Registrar hooks de admin.
     */
    public function register_hooks(): void {
        add_action( 'admin_menu', [ $this, 'add_menu_page' ] );
        add_action( 'admin_init', [ $this, 'register_settings' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );

        // Guardar el mapa PLU (tabla dinámica con JS) vía AJAX
        add_action( 'wp_ajax_dfc_save_plu_map', [ $this, 'ajax_save_plu_map' ] );
        // Test de conexión al API
        add_action( 'wp_ajax_dfc_test_api', [ $this, 'ajax_test_api' ] );
    }

    /**
     * Agregar página al menú de WooCommerce.
     */
    public function add_menu_page(): void {
        add_submenu_page(
            'woocommerce',
            __( 'DaleCafe Facturas', 'dale-facturas' ),
            __( 'DaleCafe Facturas', 'dale-facturas' ),
            'manage_woocommerce',
            'dale-facturas',
            [ $this, 'render_page' ]
        );
    }

    /**
     * Registrar las opciones con la Settings API de WordPress.
     */
    public function register_settings(): void {
        // Grupo de opciones de API
        register_setting( 'dfc_settings_api', self::OPTION_API_URL,     [ 'sanitize_callback' => 'esc_url_raw' ] );
        register_setting( 'dfc_settings_api', self::OPTION_API_USUARIO, [ 'sanitize_callback' => 'sanitize_text_field' ] );
        register_setting( 'dfc_settings_api', self::OPTION_API_CLAVE,   [ 'sanitize_callback' => 'sanitize_text_field' ] );
        register_setting( 'dfc_settings_api', self::OPTION_API_MODE,    [
            'sanitize_callback' => function ( $value ) {
                return in_array( $value, [ 'production', 'test' ], true ) ? $value : 'production';
            },
        ] );

        // Grupo de opciones de comportamiento
        register_setting( 'dfc_settings_behavior', self::OPTION_AUTO_INVOICE,          [ 'sanitize_callback' => 'absint' ] );
        register_setting( 'dfc_settings_behavior', self::OPTION_INVOICE_SUBSCRIPTIONS, [ 'sanitize_callback' => 'absint' ] );
        register_setting( 'dfc_settings_behavior', self::OPTION_DEBUG_MODE,            [ 'sanitize_callback' => 'absint' ] );

        // --- Sección: Conexión al API ---
        add_settings_section(
            'dfc_section_api',
            __( 'Conexión al API de Macrobase', 'dale-facturas' ),
            function () {
                echo '<p>' . esc_html__( 'Ingresa las credenciales del API de Macrobase para la certificación FEL.', 'dale-facturas' ) . '</p>';
            },
            'dale-facturas'
        );

        add_settings_field(
            self::OPTION_API_URL,
            __( 'URL del API', 'dale-facturas' ),
            [ $this, 'render_field_text' ],
            'dale-facturas',
            'dfc_section_api',
            [
                'option'      => self::OPTION_API_URL,
                'placeholder' => 'https://macroapps.sistemasmb.com/apiOci/...',
                'size'        => 'large',
            ]
        );

        add_settings_field(
            self::OPTION_API_USUARIO,
            __( 'Usuario', 'dale-facturas' ),
            [ $this, 'render_field_text' ],
            'dale-facturas',
            'dfc_section_api',
            [ 'option' => self::OPTION_API_USUARIO ]
        );

        add_settings_field(
            self::OPTION_API_CLAVE,
            __( 'Contraseña', 'dale-facturas' ),
            [ $this, 'render_field_password' ],
            'dale-facturas',
            'dfc_section_api',
            [ 'option' => self::OPTION_API_CLAVE ]
        );

        add_settings_field(
            self::OPTION_API_MODE,
            __( 'Modo', 'dale-facturas' ),
            [ $this, 'render_field_select' ],
            'dale-facturas',
            'dfc_section_api',
            [
                'option'  => self::OPTION_API_MODE,
                'options' => [
                    'production' => __( 'Producción', 'dale-facturas' ),
                    'test'       => __( 'Pruebas', 'dale-facturas' ),
                ],
            ]
        );

        // --- Sección: Comportamiento ---
        add_settings_section(
            'dfc_section_behavior',
            __( 'Comportamiento', 'dale-facturas' ),
            null,
            'dale-facturas'
        );

        add_settings_field(
            self::OPTION_AUTO_INVOICE,
            __( 'Facturación automática', 'dale-facturas' ),
            [ $this, 'render_field_checkbox' ],
            'dale-facturas',
            'dfc_section_behavior',
            [
                'option'      => self::OPTION_AUTO_INVOICE,
                'description' => __( 'Certificar la factura automáticamente al completar el pedido. <strong>Activar solo después de verificar que el plugin funciona correctamente.</strong>', 'dale-facturas' ),
            ]
        );

        add_settings_field(
            self::OPTION_INVOICE_SUBSCRIPTIONS,
            __( 'Facturar renovaciones de suscripciones', 'dale-facturas' ),
            [ $this, 'render_field_checkbox' ],
            'dale-facturas',
            'dfc_section_behavior',
            [
                'option'      => self::OPTION_INVOICE_SUBSCRIPTIONS,
                'description' => __( 'Generar factura automáticamente al procesar el pago de una renovación de suscripción.', 'dale-facturas' ),
            ]
        );

        add_settings_field(
            self::OPTION_DEBUG_MODE,
            __( 'Modo debug', 'dale-facturas' ),
            [ $this, 'render_field_checkbox' ],
            'dale-facturas',
            'dfc_section_behavior',
            [
                'option'      => self::OPTION_DEBUG_MODE,
                'description' => __( 'Guardar el request y response del API en el log de WordPress (wp-content/debug.log).', 'dale-facturas' ),
            ]
        );
    }

    // -------------------------------------------------------------------------
    // Renderizado de la página
    // -------------------------------------------------------------------------

    /**
     * Renderizar la página completa de settings.
     */
    public function render_page(): void {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( esc_html__( 'No tienes permisos para acceder a esta página.', 'dale-facturas' ) );
        }

        $active_tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'api';
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'DaleCafe Facturas', 'dale-facturas' ); ?></h1>

            <nav class="nav-tab-wrapper woo-nav-tab-wrapper">
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=dale-facturas&tab=api' ) ); ?>"
                   class="nav-tab <?php echo $active_tab === 'api' ? 'nav-tab-active' : ''; ?>">
                    <?php esc_html_e( 'API & Configuración', 'dale-facturas' ); ?>
                </a>
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=dale-facturas&tab=plu' ) ); ?>"
                   class="nav-tab <?php echo $active_tab === 'plu' ? 'nav-tab-active' : ''; ?>">
                    <?php esc_html_e( 'Mapeo SKU → PLU', 'dale-facturas' ); ?>
                </a>
            </nav>

            <?php if ( $active_tab === 'api' ) : ?>
                <form method="post" action="options.php">
                    <?php
                    settings_fields( 'dfc_settings_api' );
                    settings_fields( 'dfc_settings_behavior' );
                    do_settings_sections( 'dale-facturas' );
                    submit_button( __( 'Guardar cambios', 'dale-facturas' ) );
                    ?>
                </form>

                <hr>
                <h2><?php esc_html_e( 'Probar conexión al API', 'dale-facturas' ); ?></h2>
                <p><?php esc_html_e( 'Envía una petición de prueba al API de Macrobase para verificar que las credenciales son correctas.', 'dale-facturas' ); ?></p>
                <button id="dfc-test-api" class="button button-secondary">
                    <?php esc_html_e( 'Probar conexión', 'dale-facturas' ); ?>
                </button>
                <span id="dfc-test-api-result" style="margin-left: 12px;"></span>

            <?php elseif ( $active_tab === 'plu' ) : ?>
                <?php $this->render_plu_tab(); ?>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Renderizar pestaña de mapeo SKU → PLU.
     */
    private function render_plu_tab(): void {
        $plu_map = $this->get_plu_map();
        ?>
        <h2><?php esc_html_e( 'Mapeo de SKU / Opciones → PLU Macrobase', 'dale-facturas' ); ?></h2>
        <p><?php esc_html_e( 'Define la correspondencia entre los SKUs y opciones de productos de WooCommerce y los PLUs del sistema Macrobase.', 'dale-facturas' ); ?></p>

        <table class="widefat fixed dfc-plu-table" id="dfc-plu-table">
            <thead>
                <tr>
                    <th><?php esc_html_e( 'SKU / Valor de opción', 'dale-facturas' ); ?></th>
                    <th><?php esc_html_e( 'PLU Macrobase', 'dale-facturas' ); ?></th>
                    <th><?php esc_html_e( 'Tipo', 'dale-facturas' ); ?></th>
                    <th style="width:80px;"><?php esc_html_e( 'Acción', 'dale-facturas' ); ?></th>
                </tr>
            </thead>
            <tbody id="dfc-plu-rows">
                <?php foreach ( $plu_map as $entry ) : ?>
                    <tr>
                        <td><input type="text" name="dfc_plu_sku[]" value="<?php echo esc_attr( $entry['sku'] ); ?>" class="regular-text"></td>
                        <td><input type="number" name="dfc_plu_plu[]" value="<?php echo esc_attr( $entry['plu'] ); ?>" style="width:80px;"></td>
                        <td>
                            <select name="dfc_plu_type[]">
                                <option value="sku"     <?php selected( $entry['type'], 'sku' ); ?>><?php esc_html_e( 'SKU de producto', 'dale-facturas' ); ?></option>
                                <option value="blend"   <?php selected( $entry['type'], 'blend' ); ?>><?php esc_html_e( 'Mezcla (blend)', 'dale-facturas' ); ?></option>
                                <option value="grind"   <?php selected( $entry['type'], 'grind' ); ?>><?php esc_html_e( 'Molienda (grind)', 'dale-facturas' ); ?></option>
                                <option value="special" <?php selected( $entry['type'], 'special' ); ?>><?php esc_html_e( 'Especial', 'dale-facturas' ); ?></option>
                            </select>
                        </td>
                        <td><button type="button" class="button dfc-remove-row"><?php esc_html_e( 'Eliminar', 'dale-facturas' ); ?></button></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <p>
            <button type="button" id="dfc-add-plu-row" class="button">
                + <?php esc_html_e( 'Agregar fila', 'dale-facturas' ); ?>
            </button>
            <button type="button" id="dfc-save-plu-map" class="button button-primary" style="margin-left:8px;">
                <?php esc_html_e( 'Guardar mapeo', 'dale-facturas' ); ?>
            </button>
            <span id="dfc-plu-save-result" style="margin-left:12px;"></span>
        </p>

        <?php wp_nonce_field( 'dfc_save_plu_map', 'dfc_plu_nonce' ); ?>
        <?php
    }

    // -------------------------------------------------------------------------
    // Helpers para renderizar campos
    // -------------------------------------------------------------------------

    public function render_field_text( array $args ): void {
        $value = get_option( $args['option'], '' );
        $size  = $args['size'] ?? 'regular';
        printf(
            '<input type="text" id="%1$s" name="%1$s" value="%2$s" class="%3$s-text" placeholder="%4$s">',
            esc_attr( $args['option'] ),
            esc_attr( $value ),
            esc_attr( $size ),
            esc_attr( $args['placeholder'] ?? '' )
        );
    }

    public function render_field_password( array $args ): void {
        $value = get_option( $args['option'], '' );
        printf(
            '<input type="password" id="%1$s" name="%1$s" value="%2$s" class="regular-text" autocomplete="new-password">',
            esc_attr( $args['option'] ),
            esc_attr( $value )
        );
    }

    public function render_field_select( array $args ): void {
        $current = get_option( $args['option'], '' );
        echo '<select id="' . esc_attr( $args['option'] ) . '" name="' . esc_attr( $args['option'] ) . '">';
        foreach ( $args['options'] as $val => $label ) {
            printf(
                '<option value="%s" %s>%s</option>',
                esc_attr( $val ),
                selected( $current, $val, false ),
                esc_html( $label )
            );
        }
        echo '</select>';
    }

    public function render_field_checkbox( array $args ): void {
        $value = get_option( $args['option'], '0' );
        printf(
            '<label><input type="checkbox" id="%1$s" name="%1$s" value="1" %2$s> %3$s</label>',
            esc_attr( $args['option'] ),
            checked( '1', $value, false ),
            wp_kses( $args['description'] ?? '', [ 'strong' => [] ] )
        );
    }

    // -------------------------------------------------------------------------
    // PLU Map helpers
    // -------------------------------------------------------------------------

    /**
     * Obtener el mapa PLU guardado, con defaults del código V2 si no existe.
     */
    public function get_plu_map(): array {
        $saved = get_option( self::OPTION_PLU_MAP, null );
        if ( is_array( $saved ) && ! empty( $saved ) ) {
            return $saved;
        }
        return $this->get_default_plu_map();
    }

    /**
     * Mapa PLU por defecto (migrado desde DaleCafeLocal-V2/api-facturas.php).
     */
    private function get_default_plu_map(): array {
        return [
            // SKUs de productos principales
            [ 'sku' => 'Starter',    'plu' => 1,  'type' => 'sku' ],
            [ 'sku' => 'Starter-1',  'plu' => 1,  'type' => 'sku' ],
            [ 'sku' => 'Family',     'plu' => 2,  'type' => 'sku' ],
            [ 'sku' => 'SuperFamily','plu' => 75, 'type' => 'sku' ],
            // SKUs internos (-web)
            [ 'sku' => '19-web',     'plu' => 19, 'type' => 'sku' ],
            [ 'sku' => '18-web',     'plu' => 18, 'type' => 'sku' ],
            [ 'sku' => '20-web',     'plu' => 20, 'type' => 'sku' ],
            [ 'sku' => '35-web',     'plu' => 35, 'type' => 'sku' ],
            [ 'sku' => '23-web',     'plu' => 23, 'type' => 'sku' ],
            [ 'sku' => '25-web',     'plu' => 25, 'type' => 'sku' ],
            [ 'sku' => '24-web',     'plu' => 24, 'type' => 'sku' ],
            [ 'sku' => '58-web',     'plu' => 58, 'type' => 'sku' ],
            [ 'sku' => '21-web',     'plu' => 21, 'type' => 'sku' ],
            [ 'sku' => '22-web',     'plu' => 22, 'type' => 'sku' ],
            [ 'sku' => '114-web',    'plu' => 114,'type' => 'sku' ],
            [ 'sku' => '115-web',    'plu' => 115,'type' => 'sku' ],
            // Blends
            [ 'sku' => 'Master Blend',        'plu' => 21, 'type' => 'blend' ],
            [ 'sku' => "Antigua's Melt",      'plu' => 23, 'type' => 'blend' ],
            [ 'sku' => "Coban´s Moist",       'plu' => 25, 'type' => 'blend' ],
            [ 'sku' => "Huehue´s Sweet",      'plu' => 24, 'type' => 'blend' ],
            [ 'sku' => "Fraijanes' Flavory",  'plu' => 58, 'type' => 'blend' ],
            [ 'sku' => 'Master Blend Bold',   'plu' => 22, 'type' => 'blend' ],
            [ 'sku' => 'Antigua',             'plu' => 18, 'type' => 'blend' ],
            [ 'sku' => 'Cobán',               'plu' => 19, 'type' => 'blend' ],
            [ 'sku' => 'Huehuetenango',       'plu' => 20, 'type' => 'blend' ],
            [ 'sku' => 'Fraijanes',           'plu' => 35, 'type' => 'blend' ],
            // Molienda
            [ 'sku' => 'EN GRANO', 'plu' => 39, 'type' => 'grind' ],
            [ 'sku' => 'GRANO',    'plu' => 39, 'type' => 'grind' ],
            [ 'sku' => 'Grano',    'plu' => 39, 'type' => 'grind' ],
            [ 'sku' => 'MEDIO',    'plu' => 40, 'type' => 'grind' ],
            [ 'sku' => 'Medio',    'plu' => 40, 'type' => 'grind' ],
            [ 'sku' => 'GRUESO',   'plu' => 42, 'type' => 'grind' ],
            [ 'sku' => 'Grueso',   'plu' => 42, 'type' => 'grind' ],
            [ 'sku' => 'FINO',     'plu' => 41, 'type' => 'grind' ],
            [ 'sku' => 'Fino',     'plu' => 41, 'type' => 'grind' ],
            // Especiales
            [ 'sku' => 'Agregar',  'plu' => 88, 'type' => 'special' ],
            [ 'sku' => 'shipping', 'plu' => 81, 'type' => 'special' ],
        ];
    }

    // -------------------------------------------------------------------------
    // AJAX handlers
    // -------------------------------------------------------------------------

    /**
     * Guardar el mapa PLU vía AJAX desde la tabla editable.
     */
    public function ajax_save_plu_map(): void {
        check_ajax_referer( 'dfc_save_plu_map', 'nonce' );

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( [ 'message' => __( 'Sin permisos.', 'dale-facturas' ) ] );
        }

        $skus  = isset( $_POST['skus'] )  ? array_map( 'sanitize_text_field', (array) $_POST['skus'] )  : [];
        $plus  = isset( $_POST['plus'] )  ? array_map( 'absint',              (array) $_POST['plus'] )  : [];
        $types = isset( $_POST['types'] ) ? array_map( 'sanitize_key',        (array) $_POST['types'] ) : [];

        $valid_types = [ 'sku', 'blend', 'grind', 'special' ];
        $map = [];

        foreach ( $skus as $i => $sku ) {
            if ( '' === $sku ) {
                continue;
            }
            $type = isset( $types[ $i ] ) && in_array( $types[ $i ], $valid_types, true )
                ? $types[ $i ]
                : 'sku';
            $map[] = [
                'sku'  => $sku,
                'plu'  => $plus[ $i ] ?? 0,
                'type' => $type,
            ];
        }

        update_option( self::OPTION_PLU_MAP, $map );
        wp_send_json_success( [ 'message' => __( 'Mapeo guardado correctamente.', 'dale-facturas' ) ] );
    }

    /**
     * Probar conexión al API de Macrobase.
     * Envía un payload mínimo y verifica que la respuesta sea JSON válido.
     */
    public function ajax_test_api(): void {
        check_ajax_referer( 'dfc_test_api', 'nonce' );

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( [ 'message' => __( 'Sin permisos.', 'dale-facturas' ) ] );
        }

        $url     = get_option( self::OPTION_API_URL, '' );
        $usuario = get_option( self::OPTION_API_USUARIO, '' );
        $clave   = get_option( self::OPTION_API_CLAVE, '' );

        if ( empty( $url ) || empty( $usuario ) || empty( $clave ) ) {
            wp_send_json_error( [ 'message' => __( 'Completa primero la URL, usuario y contraseña del API.', 'dale-facturas' ) ] );
        }

        $payload = [
            'usuario' => $usuario,
            'clave'   => $clave,
            'ordenes' => [],
        ];

        $response = wp_remote_post( $url, [
            'timeout' => 15,
            'headers' => [ 'Content-Type' => 'application/json' ],
            'body'    => wp_json_encode( $payload ),
        ] );

        if ( is_wp_error( $response ) ) {
            wp_send_json_error( [ 'message' => $response->get_error_message() ] );
        }

        $code = wp_remote_retrieve_response_code( $response );
        $body = wp_remote_retrieve_body( $response );
        $json = json_decode( $body, true );

        if ( $json === null ) {
            wp_send_json_error( [
                'message' => sprintf(
                    __( 'El API respondió con HTTP %d pero no es JSON válido.', 'dale-facturas' ),
                    $code
                ),
            ] );
        }

        wp_send_json_success( [
            'message' => sprintf(
                __( 'Conexión exitosa (HTTP %d). El API respondió correctamente.', 'dale-facturas' ),
                $code
            ),
        ] );
    }

    // -------------------------------------------------------------------------
    // Assets
    // -------------------------------------------------------------------------

    public function enqueue_assets( string $hook ): void {
        if ( strpos( $hook, 'dale-facturas' ) === false ) {
            return;
        }
        wp_enqueue_script(
            'dfc-admin',
            DFC_PLUGIN_URL . 'assets/js/admin.js',
            [ 'jquery' ],
            DFC_VERSION,
            true
        );
        wp_localize_script( 'dfc-admin', 'dfcAdmin', [
            'ajaxUrl'       => admin_url( 'admin-ajax.php' ),
            'noncePlu'      => wp_create_nonce( 'dfc_save_plu_map' ),
            'nonceTestApi'  => wp_create_nonce( 'dfc_test_api' ),
            'i18n'          => [
                'saving'  => __( 'Guardando…', 'dale-facturas' ),
                'saved'   => __( '✓ Guardado', 'dale-facturas' ),
                'testing' => __( 'Probando…', 'dale-facturas' ),
                'error'   => __( '✗ Error', 'dale-facturas' ),
            ],
        ] );
    }

    // -------------------------------------------------------------------------
    // Getters públicos (usados por otras clases del plugin)
    // -------------------------------------------------------------------------

    public function get_api_url(): string {
        return get_option( self::OPTION_API_URL, '' );
    }

    public function get_api_usuario(): string {
        return get_option( self::OPTION_API_USUARIO, '' );
    }

    public function get_api_clave(): string {
        return get_option( self::OPTION_API_CLAVE, '' );
    }

    public function is_auto_invoice(): bool {
        return '1' === get_option( self::OPTION_AUTO_INVOICE, '0' );
    }

    public function is_invoice_subscriptions(): bool {
        return '1' === get_option( self::OPTION_INVOICE_SUBSCRIPTIONS, '0' );
    }

    public function is_debug(): bool {
        return '1' === get_option( self::OPTION_DEBUG_MODE, '0' );
    }
}
