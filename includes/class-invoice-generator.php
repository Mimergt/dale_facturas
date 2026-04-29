<?php
/**
 * Generador de facturas: integra todos los componentes para crear factura en Macrobase.
 * Se ejecuta cuando el pedido pasa a estado "Completado".
 */

defined( 'ABSPATH' ) || exit;

class DFC_Invoice_Generator {

    /**
     * Meta keys para guardar respuesta FEL del API.
     */
    const META_FEL_SERIE       = '_dfc_fel_serie';
    const META_FEL_TRANSACCION = '_dfc_fel_transaccion';
    const META_FEL_FIRMA       = '_dfc_fel_firma';
    const META_FEL_CONTINGENCIA = '_dfc_fel_es_contingencia';
    const META_FEL_ERROR       = '_dfc_fel_error';
    const META_API_REQUEST     = '_dfc_api_request';
    const META_API_RESPONSE    = '_dfc_api_response';
    const META_FEL_TIMESTAMP   = '_dfc_fel_timestamp';

    /**
     * Registrar hooks.
     */
    public function register_hooks(): void {
        // Generar factura al cambiar estado a "completed"
        add_action( 'woocommerce_order_status_completed', [ $this, 'on_order_completed' ], 10, 1 );

        // AJAX: Regenerar factura manualmente desde admin
        add_action( 'wp_ajax_dfc_regenerate_invoice', [ $this, 'ajax_regenerate_invoice' ] );
    }

    /**
     * Hook: Cuando el pedido cambia a "completed".
     *
     * @param int $order_id ID del pedido.
     */
    public function on_order_completed( int $order_id ): void {
        // Verificar si la facturación automática está habilitada
        if ( ! get_option( DFC_Settings::OPTION_AUTO_INVOICE ) ) {
            return;
        }

        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            return;
        }

        // Verificar si ya tiene FEL
        $existing_seria = $order->get_meta( self::META_FEL_SERIE );
        if ( ! empty( $existing_seria ) ) {
            // Ya fue facturada
            return;
        }

        // Generar factura
        $this->generate_invoice( $order );
    }

    /**
     * Generar factura para un pedido.
     * Construye payload, llama API, guarda meta.
     *
     * @param WC_Order $order Pedido a facturar.
     *
     * @return bool|WP_Error true si éxito, WP_Error si falló.
     */
    public function generate_invoice( WC_Order $order ) {
        // 1. Construir payload
        $payload = $this->build_invoice_payload( $order );
        if ( is_wp_error( $payload ) ) {
            $this->save_error( $order, $payload->get_error_message() );
            return $payload;
        }

        // Guardar request en meta (para debug)
        $order->update_meta_data( self::META_API_REQUEST, wp_json_encode( $payload ) );

        // 2. Crear cliente API y enviar
        $api = DFC_Macrobase_API::from_options();
        $result = $api->enviar_factura( $payload );

        if ( is_wp_error( $result ) ) {
            $this->save_error( $order, $result->get_error_message() );
            return $result;
        }

        // 3. Guardar respuesta en meta
        $order->update_meta_data( self::META_API_RESPONSE, wp_json_encode( $result ) );
        $order->update_meta_data( self::META_FEL_SERIE, $result['serie'] );
        $order->update_meta_data( self::META_FEL_TRANSACCION, $result['transaccion'] );
        $order->update_meta_data( self::META_FEL_FIRMA, $result['firmaElectronica'] ?? '' );
        $order->update_meta_data( self::META_FEL_CONTINGENCIA, $result['esContingencia'] ? '1' : '0' );
        $order->update_meta_data( self::META_FEL_TIMESTAMP, time() );

        // Limpiar error si antes lo había
        $order->delete_meta_data( self::META_FEL_ERROR );

        $order->save_meta_data();

        // 4. Agregar nota en el pedido
        $msg = sprintf(
            __( 'Factura FEL generada. Serie: %s, Transacción: %s', 'dale-facturas' ),
            $result['serie'],
            $result['transaccion']
        );
        if ( $result['esContingencia'] ) {
            $msg .= ' [' . __( 'CONTINGENCIA', 'dale-facturas' ) . ']';
        }
        $order->add_order_note( $msg, true );

        return true;
    }

    /**
     * Construir el payload de facturación para el API.
     *
     * @param WC_Order $order Pedido.
     *
     * @return array|WP_Error Payload listo para enviar, o WP_Error si algo falta.
     */
    private function build_invoice_payload( WC_Order $order ) {
        $mapper = new DFC_Product_Mapper();
        $items  = [];
        $subtotal = 0;

        // 1. Iterar items del pedido
        foreach ( $order->get_items() as $item ) {
            if ( $item->is_type( 'line_item' ) ) {
                $product = $item->get_product();
                if ( ! $product ) {
                    continue;
                }

                // Extraer datos del item (molienda, blend, etc.)
                $item_data = DFC_Product_Mapper::extract_item_data( $item );

                // Obtener PLU
                $plu = $mapper->get_plu_for_product( $product, $item_data );
                if ( is_wp_error( $plu ) ) {
                    return $plu;
                }

                $quantity = $item->get_quantity();
                $price    = (float) $item->get_subtotal();
                $subtotal += $price;

                $items[] = [
                    'plu'      => $plu,
                    'nombre'   => $product->get_name(),
                    'cantidad' => $quantity,
                    'precio'   => $price / $quantity, // Precio unitario
                    'subtotal' => $price,
                ];
            }
        }

        // 2. Validar que hay items
        if ( empty( $items ) ) {
            return new WP_Error(
                'dfc_no_items',
                __( 'El pedido no tiene items para facturar.', 'dale-facturas' )
            );
        }

        // 3. Obtener impuestos
        $tax_total = 0;
        foreach ( $order->get_items( 'tax' ) as $tax_item ) {
            $tax_total += (float) $tax_item->get_tax_total();
        }

        // 4. Obtener cliente y NIT
        $nit = DFC_NIT_Handler::get_nit( $order );
        $cliente = [
            'nombre'  => $order->get_billing_first_name() . ' ' . $order->get_billing_last_name(),
            'nit'     => $nit,
            'email'   => $order->get_billing_email(),
            'telefono' => $order->get_billing_phone(),
            'direccion' => $order->get_billing_address_1(),
        ];

        // 5. Construir payload final
        $payload = [
            'numeroOrden'  => $order->get_order_number(),
            'cliente'      => $cliente,
            'items'        => $items,
            'subtotal'     => $subtotal,
            'impuesto'     => $tax_total,
            'total'        => (float) $order->get_total(),
            'fecha'        => $order->get_date_created()->format( 'Y-m-d H:i:s' ),
            'moneda'       => $order->get_currency(),
            'formasPago'   => [ $this->get_payment_method( $order ) ],
        ];

        return $payload;
    }

    /**
     * Obtener forma de pago del pedido formateada para Macrobase.
     *
     * @param WC_Order $order Pedido.
     *
     * @return string Forma de pago.
     */
    private function get_payment_method( WC_Order $order ): string {
        $method = $order->get_payment_method();

        // Mapear métodos comunes a formatos de Macrobase
        $map = [
            'stripe'        => 'Tarjeta de Crédito',
            'paypal'        => 'PayPal',
            'woocommerce_pay' => 'Tarjeta de Crédito',
            'bacs'          => 'Transferencia Bancaria',
            'cheque'        => 'Cheque',
            'cod'           => 'Pago Contra Entrega',
        ];

        return $map[ $method ] ?? $order->get_payment_method_title() ?? 'Otro';
    }

    /**
     * Guardar error de facturación en meta del pedido.
     *
     * @param WC_Order $order Pedido.
     * @param string   $error_msg Mensaje de error.
     */
    private function save_error( WC_Order $order, string $error_msg ): void {
        $order->update_meta_data( self::META_FEL_ERROR, $error_msg );
        $order->delete_meta_data( self::META_FEL_SERIE );
        $order->delete_meta_data( self::META_FEL_TRANSACCION );
        $order->delete_meta_data( self::META_FEL_FIRMA );
        $order->save_meta_data();

        // Agregar nota de error en el pedido
        $order->add_order_note(
            sprintf( __( 'Error al generar factura FEL: %s', 'dale-facturas' ), $error_msg ),
            false
        );
    }

    /**
     * AJAX: Regenerar factura manualmente desde admin.
     */
    public function ajax_regenerate_invoice(): void {
        check_ajax_referer( 'dfc_regenerate_invoice', 'nonce' );

        if ( ! current_user_can( 'manage_orders' ) ) {
            wp_send_json_error( [ 'message' => __( 'Sin permisos.', 'dale-facturas' ) ] );
        }

        $order_id = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0;
        if ( ! $order_id ) {
            wp_send_json_error( [ 'message' => __( 'ID de pedido inválido.', 'dale-facturas' ) ] );
        }

        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            wp_send_json_error( [ 'message' => __( 'Pedido no encontrado.', 'dale-facturas' ) ] );
        }

        // Limpiar FEL anterior si existe
        $order->delete_meta_data( self::META_FEL_SERIE );
        $order->delete_meta_data( self::META_FEL_TRANSACCION );
        $order->delete_meta_data( self::META_FEL_FIRMA );
        $order->delete_meta_data( self::META_FEL_ERROR );
        $order->save_meta_data();

        // Generar
        $result = $this->generate_invoice( $order );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( [ 'message' => $result->get_error_message() ] );
        }

        wp_send_json_success( [
            'message' => sprintf(
                __( 'Factura regenerada. Serie: %s, Transacción: %s', 'dale-facturas' ),
                $order->get_meta( self::META_FEL_SERIE ),
                $order->get_meta( self::META_FEL_TRANSACCION )
            ),
        ] );
    }
}
