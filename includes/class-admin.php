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

        // Verificar que sea un pedido de WooCommerce
        if ( ! isset( $_GET['post'] ) || get_post_type( $_GET['post'] ) !== 'shop_order' ) {
            return;
        }

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
}
