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
        add_action( 'wp_ajax_dfc_process_subscription_invoice_v2', [ $this, 'ajax_process_subscription_invoice_v2' ] );
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

        add_meta_box(
            'dfc-create-renewal-invoices-v2',
            __( 'Generador de Facturas Nuevo', 'dale-facturas' ),
            [ $this, 'render_subscription_meta_box_v2' ],
            'shop_subscription',
            'side',
            'high'
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
        $subscription_attachment_id = $subscription ? absint( $subscription->get_meta( DFC_Invoice_Generator::META_PREBUILT_ATTACHMENT_ID ) ) : 0;
        $pdf_url = '';
        $fallback_pdf_url = '';
        if ( $source_order_id ) {
            $source_order = wc_get_order( $source_order_id );
            if ( $source_order ) {
                $attachment_id = absint( $source_order->get_meta( '_invoice_created_by_button' ) );
                if ( $attachment_id > 0 ) {
                    $pdf_url = (string) wp_get_attachment_url( $attachment_id );
                }
                $fallback_pdf_url = admin_url( 'admin-ajax.php?action=generate_wpo_wcpdf&document_type=invoice&order_ids=' . $source_order_id );
            }
        }
        if ( empty( $pdf_url ) && $subscription_attachment_id > 0 ) {
            $pdf_url = (string) wp_get_attachment_url( $subscription_attachment_id );
        }

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
     * Render del metabox nuevo para aislar pruebas del flujo legacy del theme.
     */
    public function render_subscription_meta_box_v2( WP_Post $post ): void {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            return;
        }

        $subscription = function_exists( 'wcs_get_subscription' ) ? wcs_get_subscription( $post->ID ) : null;
        $parent_order_id = $subscription ? absint( $subscription->get_parent_id() ) : 0;
        $source_order_id = $subscription ? absint( $subscription->get_meta( DFC_Invoice_Generator::META_PREBUILT_SOURCE_ORDER ) ) : 0;
        $ready_at = $subscription ? (int) $subscription->get_meta( DFC_Invoice_Generator::META_PREBUILT_READY_AT ) : 0;
        $subscription_attachment_id = $subscription ? absint( $subscription->get_meta( DFC_Invoice_Generator::META_PREBUILT_ATTACHMENT_ID ) ) : 0;
        $pdf_url = '';
        $fallback_pdf_url = '';

        if ( $source_order_id ) {
            $source_order = wc_get_order( $source_order_id );
            if ( $source_order ) {
                $attachment_id = absint( $source_order->get_meta( '_invoice_created_by_button' ) );
                if ( $attachment_id > 0 ) {
                    $pdf_url = (string) wp_get_attachment_url( $attachment_id );
                }
                $fallback_pdf_url = admin_url( 'admin-ajax.php?action=generate_wpo_wcpdf&document_type=invoice&order_ids=' . $source_order_id );
            }
        }

        if ( empty( $pdf_url ) && $subscription_attachment_id > 0 ) {
            $pdf_url = (string) wp_get_attachment_url( $subscription_attachment_id );
        }

        wp_nonce_field( 'dfc_process_subscription_invoice_v2', 'dfc_subscription_invoice_nonce_v2' );
        ?>
        <p>
            <strong><?php esc_html_e( 'Flujo nuevo del plugin (aislado del theme).', 'dale-facturas' ); ?></strong>
        </p>
        <p>
            <?php esc_html_e( 'Usa este botón para certificar FEL desde el plugin y preparar la reutilización en renovación.', 'dale-facturas' ); ?>
        </p>

        <?php if ( $parent_order_id ) : ?>
            <p>
                <strong><?php esc_html_e( 'Pedido base:', 'dale-facturas' ); ?></strong>
                #<?php echo esc_html( (string) $parent_order_id ); ?>
            </p>
        <?php endif; ?>

        <?php if ( $source_order_id ) : ?>
            <p>
                <strong><?php esc_html_e( 'Preinvoice actual:', 'dale-facturas' ); ?></strong>
                #<?php echo esc_html( (string) $source_order_id ); ?>
            </p>
            <?php if ( ! empty( $pdf_url ) ) : ?>
                <p>
                    <a class="button button-secondary" target="_blank" href="<?php echo esc_url( $pdf_url ); ?>">
                        <?php esc_html_e( 'Ver PDF preinvoice', 'dale-facturas' ); ?>
                    </a>
                </p>
            <?php elseif ( ! empty( $fallback_pdf_url ) ) : ?>
                <p>
                    <a class="button button-secondary" target="_blank" href="<?php echo esc_url( $fallback_pdf_url ); ?>">
                        <?php esc_html_e( 'Ver PDF del pedido base', 'dale-facturas' ); ?>
                    </a>
                </p>
            <?php endif; ?>
            <?php if ( $ready_at > 0 ) : ?>
                <p>
                    <em><?php echo esc_html( gmdate( 'Y-m-d H:i:s', $ready_at ) . ' UTC' ); ?></em>
                </p>
            <?php endif; ?>
        <?php endif; ?>

        <p>
            <button type="button" class="button button-primary" id="dfc-process-subscription-invoice-v2" data-subscription-id="<?php echo esc_attr( (string) $post->ID ); ?>">
                <?php esc_html_e( 'Procesar Factura (Nuevo)', 'dale-facturas' ); ?>
            </button>
            <span id="dfc-process-subscription-result-v2" style="display:block; margin-top:8px;"></span>
        </p>
        <script>
        (function () {
            var btn = document.getElementById('dfc-process-subscription-invoice-v2');
            var result = document.getElementById('dfc-process-subscription-result-v2');
            var nonceInput = document.querySelector('input[name="dfc_subscription_invoice_nonce_v2"]');
            if (!btn || !result || !nonceInput || btn.dataset.boundV2 === '1') {
                return;
            }
            btn.dataset.boundV2 = '1';

            btn.addEventListener('click', function () {
                var subscriptionId = btn.getAttribute('data-subscription-id');
                var nonce = nonceInput.value;
                if (!subscriptionId || !nonce) {
                    result.textContent = '<?php echo esc_js( __( 'Error: faltan datos del formulario.', 'dale-facturas' ) ); ?>';
                    result.style.color = '#dc3232';
                    return;
                }

                btn.disabled = true;
                result.textContent = '<?php echo esc_js( __( 'Procesando...', 'dale-facturas' ) ); ?>';
                result.style.color = '#666';

                var body = new URLSearchParams();
                body.set('action', 'dfc_process_subscription_invoice_v2');
                body.set('nonce', nonce);
                body.set('subscription_id', subscriptionId);

                fetch('<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>', {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
                    },
                    body: body.toString()
                })
                .then(function (res) { return res.text(); })
                .then(function (text) {
                    var payload = null;
                    try {
                        payload = JSON.parse(text);
                    } catch (e) {
                        payload = null;
                    }

                    if (payload && payload.success) {
                        result.textContent = payload.data && payload.data.message ? payload.data.message : '<?php echo esc_js( __( 'Éxito', 'dale-facturas' ) ); ?>';
                        result.style.color = '#46b450';
                    } else {
                        var msg = (payload && payload.data && payload.data.message) ? payload.data.message : text.replace(/\s+/g, ' ').slice(0, 180);
                        result.textContent = '<?php echo esc_js( __( 'Error', 'dale-facturas' ) ); ?>: ' + msg;
                        result.style.color = '#dc3232';
                    }
                })
                .catch(function (err) {
                    result.textContent = '<?php echo esc_js( __( 'Error', 'dale-facturas' ) ); ?>: ' + (err && err.message ? err.message : 'network');
                    result.style.color = '#dc3232';
                })
                .finally(function () {
                    btn.disabled = false;
                });
            });
        })();
        </script>
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
        if ( function_exists( 'wc_get_logger' ) ) {
            wc_get_logger()->info( 'AJAX dfc_process_subscription_invoice recibido.', [ 'source' => 'dale-facturas' ] );
        }

        check_ajax_referer( 'dfc_process_subscription_invoice', 'nonce' );

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            if ( function_exists( 'wc_get_logger' ) ) {
                wc_get_logger()->error( 'AJAX dfc_process_subscription_invoice sin permisos.', [ 'source' => 'dale-facturas' ] );
            }
            wp_send_json_error( [ 'message' => __( 'Sin permisos.', 'dale-facturas' ) ] );
        }

        $subscription_id = isset( $_POST['subscription_id'] ) ? absint( $_POST['subscription_id'] ) : 0;
        if ( ! $subscription_id ) {
            if ( function_exists( 'wc_get_logger' ) ) {
                wc_get_logger()->error( 'AJAX dfc_process_subscription_invoice sin subscription_id válido.', [ 'source' => 'dale-facturas' ] );
            }
            wp_send_json_error( [ 'message' => __( 'ID de suscripción inválido.', 'dale-facturas' ) ] );
        }

        $invoice_generator = new DFC_Invoice_Generator();
        $result = $invoice_generator->process_subscription_preinvoice( $subscription_id, true );

        if ( is_wp_error( $result ) ) {
            if ( function_exists( 'wc_get_logger' ) ) {
                wc_get_logger()->error(
                    sprintf( 'AJAX dfc_process_subscription_invoice error suscripción #%d: %s', $subscription_id, $result->get_error_message() ),
                    [ 'source' => 'dale-facturas' ]
                );
            }
            wp_send_json_error( [ 'message' => $result->get_error_message() ] );
        }

        if ( function_exists( 'wc_get_logger' ) ) {
            wc_get_logger()->info(
                sprintf( 'AJAX dfc_process_subscription_invoice OK suscripción #%d, source_order #%d.', $subscription_id, (int) $result['source_order_id'] ),
                [ 'source' => 'dale-facturas' ]
            );
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

    /**
     * AJAX v2: preparar factura anticipada para suscripcion desde flujo nuevo aislado.
     */
    public function ajax_process_subscription_invoice_v2(): void {
        if ( function_exists( 'wc_get_logger' ) ) {
            wc_get_logger()->info( 'AJAX dfc_process_subscription_invoice_v2 recibido.', [ 'source' => 'dale-facturas' ] );
        }

        check_ajax_referer( 'dfc_process_subscription_invoice_v2', 'nonce' );

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            if ( function_exists( 'wc_get_logger' ) ) {
                wc_get_logger()->error( 'AJAX dfc_process_subscription_invoice_v2 sin permisos.', [ 'source' => 'dale-facturas' ] );
            }
            wp_send_json_error( [ 'message' => __( 'Sin permisos.', 'dale-facturas' ) ] );
        }

        $subscription_id = isset( $_POST['subscription_id'] ) ? absint( $_POST['subscription_id'] ) : 0;
        if ( ! $subscription_id ) {
            if ( function_exists( 'wc_get_logger' ) ) {
                wc_get_logger()->error( 'AJAX dfc_process_subscription_invoice_v2 sin subscription_id válido.', [ 'source' => 'dale-facturas' ] );
            }
            wp_send_json_error( [ 'message' => __( 'ID de suscripción inválido.', 'dale-facturas' ) ] );
        }

        $invoice_generator = new DFC_Invoice_Generator();
        // Flujo NUEVO: siempre forzar certificación al hacer click para replicar comportamiento del theme.
        $result = $invoice_generator->process_subscription_preinvoice( $subscription_id, true );

        if ( is_wp_error( $result ) ) {
            if ( function_exists( 'wc_get_logger' ) ) {
                wc_get_logger()->error(
                    sprintf( 'AJAX dfc_process_subscription_invoice_v2 error suscripción #%d: %s', $subscription_id, $result->get_error_message() ),
                    [ 'source' => 'dale-facturas' ]
                );
            }
            wp_send_json_error( [ 'message' => $result->get_error_message() ] );
        }

        if ( function_exists( 'wc_get_logger' ) ) {
            wc_get_logger()->info(
                sprintf( 'Suscripción %d: flujo NUEVO ejecutado, preinvoice lista desde pedido #%d.', $subscription_id, (int) $result['source_order_id'] ),
                [ 'source' => 'dale-facturas' ]
            );
        }

        wp_send_json_success( [
            'message' => sprintf(
                __( '[NUEVO] Factura anticipada lista. Serie %1$s, transacción %2$s (pedido base #%3$d).', 'dale-facturas' ),
                $result['serie'] ?: '-',
                $result['transaccion'] ?: '-',
                $result['source_order_id'] ?: 0
            ),
        ] );
    }
}
