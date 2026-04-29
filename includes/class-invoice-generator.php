<?php
/**
 * Generador de facturas: integra todos los componentes para crear factura en Macrobase.
 * Se ejecuta cuando el pedido pasa a estado "Completado".
 * Incluye lógica completa de api-facturas.php: pluPadre, formasPago, Micro Lotes, etc.
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

        $factura = isset( $result['factura'] ) && is_array( $result['factura'] ) ? $result['factura'] : [];

        // 3. Guardar respuesta en meta
        $order->update_meta_data( self::META_API_RESPONSE, wp_json_encode( $result ) );
        $order->update_meta_data( self::META_FEL_SERIE, $result['serie'] );
        $order->update_meta_data( self::META_FEL_TRANSACCION, $result['transaccion'] );
        $order->update_meta_data( self::META_FEL_FIRMA, $result['firmaElectronica'] ?? '' );
        $order->update_meta_data( self::META_FEL_CONTINGENCIA, $result['esContingencia'] ? '1' : '0' );
        $order->update_meta_data( self::META_FEL_TIMESTAMP, time() );

        if ( ! empty( $factura['fechaHoraCertificacion'] ) ) {
            $order->update_meta_data( '_dfc_fel_fecha_certificacion', $factura['fechaHoraCertificacion'] );
        }
        if ( ! empty( $factura['faceId'] ) ) {
            $order->update_meta_data( '_dfc_fel_numero_acceso', $factura['faceId'] );
        }
        if ( ! empty( $factura['empresaNit'] ) ) {
            $order->update_meta_data( '_dfc_fel_nit_empresa', $factura['empresaNit'] );
        }
        if ( ! empty( $factura['empresaNombre'] ) ) {
            $order->update_meta_data( '_dfc_fel_nombre_empresa', $factura['empresaNombre'] );
        }
        if ( ! empty( $factura['establecimientoNombre'] ) ) {
            $order->update_meta_data( '_dfc_fel_establecimiento_nombre', $factura['establecimientoNombre'] );
        }
        if ( ! empty( $factura['resolucionNumero'] ) ) {
            $order->update_meta_data( '_dfc_fel_resolucion_numero', $factura['resolucionNumero'] );
        }
        if ( ! empty( $factura['resolucionFecha'] ) ) {
            $order->update_meta_data( '_dfc_fel_resolucion_fecha', $factura['resolucionFecha'] );
        }
        if ( ! empty( $factura['gfaceEmpresa'] ) ) {
            $order->update_meta_data( '_dfc_fel_gface_empresa', $factura['gfaceEmpresa'] );
        }
        if ( ! empty( $factura['gfaceNit'] ) ) {
            $order->update_meta_data( '_dfc_fel_gface_nit', $factura['gfaceNit'] );
        }

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
     * Incluye lógica completa del api-facturas.php original.
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
                $item_total = (float) $item->get_subtotal();
                $price_unitario = $item_total / $quantity;

                // Determinar pluPadre e identificar flags como Micro Lotes (líneas 221-298 api-facturas.php)
                $padre_flags = $this->determine_plu_padre_and_flags( $product, $item );
                $plu_padre = $padre_flags['plu_padre'];
                $is_micro_lote = $padre_flags['is_micro_lote'];

                // Si es Micro Lote, ajustar el precio restando 25 (como en original api-facturas.php)
                $item_total_adjusted = $item_total;
                if ( $is_micro_lote ) {
                    $item_total_adjusted = max( 0, $item_total - 25 );
                    $price_unitario = $item_total_adjusted / $quantity;
                }

                $subtotal += $item_total_adjusted;

                $items[] = [
                    'plu'                      => $plu,
                    'cantidad'                 => $quantity,
                    'precio'                   => $price_unitario,
                    'monto'                    => $item_total_adjusted,
                    'descuentoItemPorcentaje'  => 0,
                    'comboNumero'              => 1,
                    'pluPadre'                 => $plu_padre,
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

        // 3. Agregar envío como item especial (PLU 81)
        $shipping_total = (float) $order->get_shipping_total();
        if ( $shipping_total > 0 ) {
            $items[] = [
                'plu'                      => 81, // PLU especial para envío
                'cantidad'                 => 1,
                'precio'                   => $shipping_total,
                'monto'                    => $shipping_total,
                'descuentoItemPorcentaje'  => 0,
                'comboNumero'              => 1,
                'pluPadre'                 => 81,
            ];
            $subtotal += $shipping_total;
        }

        // 4. Obtener impuestos
        $tax_total = 0;
        foreach ( $order->get_items( 'tax' ) as $tax_item ) {
            $tax_total += (float) $tax_item->get_tax_total();
        }

        // 5. Obtener cliente y NIT
        $nit = DFC_NIT_Handler::get_nit( $order );
        $cliente = [
            'nombre'      => $order->get_billing_first_name() . ' ' . $order->get_billing_last_name(),
            'nit'         => $nit,
            'email'       => $order->get_billing_email(),
            'telefono'    => $order->get_billing_phone(),
            'direccion'   => $order->get_billing_address_1(),
            'direccion2'  => $order->get_billing_address_2(),
            'ciudad'      => $order->get_billing_city(),
            'departamento' => $order->get_billing_state() ?? 'Guatemala',
        ];

        // 6. Construir formasPago basado en el método de pago
        $formas_pago = $this->build_formas_pago( $order, (float) $order->get_total() );

        // 7. Construir payload final (estructura compatible con api-facturas.php original)
        $order_number = (string) $order->get_order_number();
        $macrobase_id = '1' . $order_number;

        $payload = [
            // Macrobase requiere "id" en ordenes[].
            'id'           => $macrobase_id,
            // Se mantiene por compatibilidad con implementaciones previas.
            'numeroOrden'  => $order_number,
            'clienteNombre' => $cliente['nombre'],
            'clienteTelefono' => $cliente['telefono'],
            'clienteEmail' => $cliente['email'],
            'clienteNIT'   => $nit,
            'clienteDireccion2' => $cliente['direccion'] . ' ' . $cliente['direccion2'] . ' ' . $cliente['ciudad'],
            'clienteCiudad' => $cliente['ciudad'] ?? 'CIUDAD',
            'clienteDepartamen' => $cliente['departamento'],
            'formasPago'   => $formas_pago,
            'productos'    => $items,
        ];

        return $payload;
    }

    /**
     * Determinar el pluPadre para un item e identificar flags especiales.
     * Incluye lógica de "Paso 3" vs otros, y detección de "Micro Lotes" (líneas 221-298 api-facturas.php).
     *
     * @param WC_Product $product Producto.
     * @param WC_Order_Item_Product $item Item del pedido.
     *
     * @return array Array con 'plu_padre' e 'is_micro_lote'.
     */
    private function determine_plu_padre_and_flags( WC_Product $product, WC_Order_Item_Product $item ): array {
        $product_id = $product->get_id();
        $sku = $product->get_sku();
        $variation_id = $item->get_variation_id();
        $is_micro_lote = false;

        // Por defecto, pluPadre = SKU convertido a PLU
        $plu_padre = $this->get_sku_as_plu( $sku );

        // Detectar si es Micro Lote (para ajustar precio después)
        // Busca en metadatos del item por la presencia de "Micro Lotes" (línea 221 del api-facturas.php)
        $meta_data = $item->get_meta_data();
        foreach ( $meta_data as $meta ) {
            $meta_key   = strtolower( $meta->key );
            $meta_value = strtolower( (string) $meta->value );
            if ( strpos( $meta_key, 'micro' ) !== false || strpos( $meta_value, 'micro lote' ) !== false ) {
                $is_micro_lote = true;
                break;
            }
        }

        // Si es producto variable (tiene variation_id), aplicar lógica especial según producto_id
        if ( $variation_id !== 0 ) {
            // Productos específicos que tienen pluPadre diferente (línea 256-259)
            if ( in_array( $product_id, [ 245768, 247490 ], true ) ) {
                $plu_padre = 1;
            }
        } else {
            // Si es producto simple (sin variaciones), aplicar otra lógica según producto_id (línea 262-264)
            if ( in_array( $product_id, [ 232202, 208780 ], true ) ) {
                $plu_padre = 75; // SuperFamily
            }
        }

        return [
            'plu_padre'     => $plu_padre,
            'is_micro_lote' => $is_micro_lote,
        ];
    }

    /**
     * Convertir un SKU a PLU usando la misma lógica que el mapeo.
     * Retorna el PLU o el SKU como número si no encuentra mapeo.
     *
     * @param string $sku SKU del producto.
     *
     * @return int PLU o SKU como número.
     */
    private function get_sku_as_plu( string $sku ): int {
        // Intentar mapear exactamente como en el original
        $sku_lower = strtolower( $sku );

        $map = [
            'starter'     => 1,
            'starter-1'   => 1,
            'family'      => 2,
            'superfamily' => 75,
            '19-web'      => 19,
            '18-web'      => 18,
            '20-web'      => 20,
            '35-web'      => 35,
            '23-web'      => 23,
            '25-web'      => 25,
            '24-web'      => 24,
            '58-web'      => 58,
            '21-web'      => 21,
            '22-web'      => 22,
            '114-web'     => 114,
            '115-web'     => 115,
        ];

        return $map[ $sku_lower ] ?? (int) $sku;
    }

    /**
     * Construir la estructura de formasPago basada en el método de pago.
     * Incluye mapeo completo de métodos WooCommerce a estructura Macrobase.
     *
     * @param WC_Order $order Pedido.
     * @param float    $total Monto total.
     *
     * @return array Array de formas de pago (media, emisor, codigo, monto).
     */
    private function build_formas_pago( WC_Order $order, float $total ): array {
        $payment_method = $order->get_payment_method();

        // Mapeo de métodos WooCommerce a estructura Macrobase (líneas 309-349 api-facturas.php)
        // media: 1=Efectivo, 4=Tarjeta Crédito, 9=Transferencia/Cheque
        // emisor: 3=Emisor externo (p.ej. banco), 0=No aplica
        // codigo: 0=default, 1=Transferencia, 2=Cheque/Link

        $formas_pago = [];

        switch ( $payment_method ) {
            case 'mwc_gateway': // Débito automático (tarjeta)
                $formas_pago[] = [
                    'media'   => 4,
                    'emisor'  => 3,
                    'codigo'  => 0,
                    'monto'   => $total,
                ];
                break;

            case 'cod': // Pago contra entrega (efectivo)
                $formas_pago[] = [
                    'media'   => 1,
                    'emisor'  => 0,
                    'codigo'  => 0,
                    'monto'   => $total,
                ];
                break;

            case 'bacs': // Transferencia bancaria
                $formas_pago[] = [
                    'media'   => 9,
                    'emisor'  => 0,
                    'codigo'  => 1,
                    'monto'   => $total,
                ];
                break;

            case 'cheque': // Cheque o Link
                $formas_pago[] = [
                    'media'   => 9,
                    'emisor'  => 0,
                    'codigo'  => 2,
                    'monto'   => $total,
                ];
                break;

            default: // Default: Tarjeta crédito
                $formas_pago[] = [
                    'media'   => 4,
                    'emisor'  => 3,
                    'codigo'  => 0,
                    'monto'   => $total,
                ];
        }

        return $formas_pago;
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

        if ( function_exists( 'wc_get_logger' ) ) {
            wc_get_logger()->error(
                sprintf( 'Pedido %d: %s', $order->get_id(), $error_msg ),
                [ 'source' => 'dale-facturas' ]
            );
        }

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
