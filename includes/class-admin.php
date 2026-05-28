<?php
/**
 * Admin UI: Mostrar datos FEL en la página de editar pedido.
 */

defined( 'ABSPATH' ) || exit;

class DFC_Admin {

    /**
     * Registrar hooks.
     */
    public function register_hooks(): void {
        add_action( 'woocommerce_order_details_after_order_table', [ $this, 'display_fel_meta' ], 10, 1 );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_order_edit_assets' ] );
        add_action( 'add_meta_boxes', [ $this, 'register_subscription_meta_box' ] );
        add_action( 'wp_ajax_dfc_process_subscription_invoice', [ $this, 'ajax_process_subscription_invoice' ] );
    }

    /**
     * Registrar metabox de facturacion en suscripciones.
     */
    public function register_subscription_meta_box(): void {
        add_meta_box(
            'dfc-create-renewal-invoices',
            __( 'Facturación de Suscripción', 'dale-facturas' ),
            [ $this, 'render_subscription_meta_box' ],
            'shop_subscription',
            'side',
            'default'
        );
    }

    /**
     * Render del metabox para preparar factura antes de crear pedido de renovacion.
     */
    public function render_subscription_meta_box( WP_Post $post ): void {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            return;
        }

        $subscription = function_exists( 'wcs_get_subscription' ) ? wcs_get_subscription( $post->ID ) : null;
        $parent_order_id = $subscription ? absint( $subscription->get_parent_id() ) : 0;
        $source_order_id = $subscription ? absint( $subscription->get_meta( DFC_Invoice_Generator::META_PREBUILT_SOURCE_ORDER ) ) : 0;
        $ready_at = $subscription ? (int) $subscription->get_meta( DFC_Invoice_Generator::META_PREBUILT_READY_AT ) : 0;

        wp_nonce_field( 'dfc_process_subscription_invoice', 'dfc_subscription_invoice_nonce' );
        ?>
        <p>
            <?php esc_html_e( 'Prepara la factura FEL antes de que exista el pedido de renovación y la asigna automáticamente cuando se cree.', 'dale-facturas' ); ?>
        </p>

        <?php if ( $parent_order_id ) : ?>
            <p>
                <strong><?php esc_html_e( 'Pedido base:', 'dale-facturas' ); ?></strong>
                #<?php echo esc_html( (string) $parent_order_id ); ?>
            </p>
        <?php endif; ?>

        <?php if ( $source_order_id ) : ?>
            <p>
                <strong><?php esc_html_e( 'Factura anticipada lista desde pedido:', 'dale-facturas' ); ?></strong>
                #<?php echo esc_html( (string) $source_order_id ); ?>
            </p>
            <?php if ( $ready_at > 0 ) : ?>
                <p>
                    <em><?php echo esc_html( gmdate( 'Y-m-d H:i:s', $ready_at ) . ' UTC' ); ?></em>
                </p>
            <?php endif; ?>
        <?php endif; ?>

        <p>
            <button type="button" class="button button-secondary" id="dfc-process-subscription-invoice" data-subscription-id="<?php echo esc_attr( (string) $post->ID ); ?>">
                <?php esc_html_e( 'Procesar Factura', 'dale-facturas' ); ?>
            </button>
            <span id="dfc-process-subscription-result" style="display:block; margin-top:8px;"></span>
        </p>
        <?php
    }

    /**
     * Mostrar meta FEL en la página de editar pedido (section de orden).
     * Aparece en: WooCommerce → Pedidos → [Editar pedido] → abajo de la tabla de orden
     *
     * @param WC_Order $order Pedido.
     */
    public function display_fel_meta( WC_Order $order ): void {
        if ( ! current_user_can( 'manage_orders' ) ) {
            return;
        }

        $serie           = $order->get_meta( DFC_Invoice_Generator::META_FEL_SERIE );
        $transaccion     = $order->get_meta( DFC_Invoice_Generator::META_FEL_TRANSACCION );
        $firma           = $order->get_meta( DFC_Invoice_Generator::META_FEL_FIRMA );
        $es_contingencia = $order->get_meta( DFC_Invoice_Generator::META_FEL_CONTINGENCIA );
        $error_msg       = $order->get_meta( DFC_Invoice_Generator::META_FEL_ERROR );
        $prebuilt_from   = $order->get_meta( DFC_Invoice_Generator::META_PREBUILT_APPLIED_FROM );
        $prebuilt_at     = (int) $order->get_meta( DFC_Invoice_Generator::META_PREBUILT_APPLIED_AT );

        // Si no hay FEL y tampoco error, no mostrar nada
        if ( empty( $serie ) && empty( $error_msg ) ) {
            return;
        }

        ?>
        <div id="dfc-fel-section" style="margin-top: 20px; padding: 15px; border: 1px solid #ddd; background-color: #f9f9f9;">
            <h3><?php esc_html_e( 'Estado de Facturación FEL', 'dale-facturas' ); ?></h3>

            <?php if ( ! empty( $error_msg ) ) : ?>
                <div class="notice notice-error inline">
                    <p>
                        <strong><?php esc_html_e( 'Error:', 'dale-facturas' ); ?></strong>
                        <?php echo esc_html( $error_msg ); ?>
                    </p>
                </div>
            <?php endif; ?>

            <?php if ( ! empty( $serie ) ) : ?>
                <table class="wc-order-totals">
                    <tr>
                        <td class="label"><?php esc_html_e( 'Serie:', 'dale-facturas' ); ?></td>
                        <td class="total"><?php echo esc_html( $serie ); ?></td>
                    </tr>
                    <tr>
                        <td class="label"><?php esc_html_e( 'Transacción:', 'dale-facturas' ); ?></td>
                        <td class="total"><?php echo esc_html( $transaccion ); ?></td>
                    </tr>
                    <?php if ( ! empty( $firma ) ) : ?>
                        <tr>
                            <td class="label"><?php esc_html_e( 'Firma Electrónica:', 'dale-facturas' ); ?></td>
                            <td class="total"><code><?php echo esc_html( substr( $firma, 0, 50 ) ); ?>...</code></td>
                        </tr>
                    <?php endif; ?>
                    <?php if ( '1' === $es_contingencia ) : ?>
                        <tr>
                            <td class="label" style="color: #dc3232;"><strong><?php esc_html_e( 'Estado:', 'dale-facturas' ); ?></strong></td>
                            <td class="total" style="color: #dc3232;"><strong><?php esc_html_e( 'CONTINGENCIA', 'dale-facturas' ); ?></strong></td>
                        </tr>
                    <?php endif; ?>
                    <?php if ( ! empty( $prebuilt_from ) ) : ?>
                        <tr>
                            <td class="label"><?php esc_html_e( 'Factura origen:', 'dale-facturas' ); ?></td>
                            <td class="total">#<?php echo esc_html( (string) $prebuilt_from ); ?></td>
                        </tr>
                        <?php if ( $prebuilt_at > 0 ) : ?>
                            <tr>
                                <td class="label"><?php esc_html_e( 'Aplicada el:', 'dale-facturas' ); ?></td>
                                <td class="total"><?php echo esc_html( gmdate( 'Y-m-d H:i:s', $prebuilt_at ) . ' UTC' ); ?></td>
                            </tr>
                        <?php endif; ?>
                    <?php endif; ?>
                </table>
            <?php endif; ?>

            <!-- Botón para regenerar factura -->
            <p style="margin-top: 15px;">
                <button type="button" id="dfc-regenerate-invoice" class="button button-secondary" data-order-id="<?php echo esc_attr( $order->get_id() ); ?>">
                    <?php esc_html_e( 'Regenerar Factura FEL', 'dale-facturas' ); ?>
                </button>
                <span id="dfc-regenerate-result" style="margin-left: 12px;"></span>
            </p>

            <?php wp_nonce_field( 'dfc_regenerate_invoice', 'dfc_regenerate_nonce' ); ?>
        </div>
        <?php
    }

    /**
     * Cargar assets JS en la página de editar pedido.
     *
     * @param string $hook Nombre del hook de la página actual.
     */
    public function enqueue_order_edit_assets( string $hook ): void {
        // Solo en la página de editar pedido en admin
        if ( 'post.php' !== $hook && 'post-new.php' !== $hook ) {
            return;
        }

        if ( ! isset( $_GET['post'] ) ) {
            return;
        }

        $post_id   = absint( $_GET['post'] );
        $post_type = get_post_type( $post_id );

        if ( 'shop_order' === $post_type ) {
            wp_enqueue_script(
                'dfc-order-edit',
                DFC_PLUGIN_URL . 'assets/js/order-edit.js',
                [ 'jquery' ],
                DFC_VERSION,
                true
            );

            wp_localize_script( 'dfc-order-edit', 'dfcOrderEdit', [
                'ajaxUrl' => admin_url( 'admin-ajax.php' ),
                'i18n'    => [
                    'regenerating' => __( 'Regenerando...', 'dale-facturas' ),
                    'success'      => __( 'Éxito', 'dale-facturas' ),
                    'error'        => __( 'Error', 'dale-facturas' ),
                ],
            ] );
        }

        if ( 'shop_subscription' === $post_type ) {
            wp_enqueue_script(
                'dfc-subscription-edit',
                DFC_PLUGIN_URL . 'assets/js/subscription-edit.js',
                [ 'jquery' ],
                DFC_VERSION,
                true
            );

            wp_localize_script( 'dfc-subscription-edit', 'dfcSubscriptionEdit', [
                'ajaxUrl' => admin_url( 'admin-ajax.php' ),
                'i18n'    => [
                    'processing' => __( 'Procesando...', 'dale-facturas' ),
                    'success'    => __( 'Éxito', 'dale-facturas' ),
                    'error'      => __( 'Error', 'dale-facturas' ),
                ],
            ] );
        }
    }

    /**
     * AJAX: preparar factura anticipada para suscripcion.
     */
    public function ajax_process_subscription_invoice(): void {
        check_ajax_referer( 'dfc_process_subscription_invoice', 'nonce' );

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( [ 'message' => __( 'Sin permisos.', 'dale-facturas' ) ] );
        }

        $subscription_id = isset( $_POST['subscription_id'] ) ? absint( $_POST['subscription_id'] ) : 0;
        if ( ! $subscription_id ) {
            wp_send_json_error( [ 'message' => __( 'ID de suscripción inválido.', 'dale-facturas' ) ] );
        }

        $invoice_generator = new DFC_Invoice_Generator();
        $result = $invoice_generator->process_subscription_preinvoice( $subscription_id );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( [ 'message' => $result->get_error_message() ] );
        }

        wp_send_json_success( [
            'message' => sprintf(
                __( 'Factura anticipada lista. Serie %1$s, transacción %2$s (pedido base #%3$d).', 'dale-facturas' ),
                $result['serie'] ?: '-',
                $result['transaccion'] ?: '-',
                $result['source_order_id'] ?: 0
            ),
        ] );
    }
}
