<?php
/**
 * Generador de facturas: integra todos los componentes para crear factura en Macrobase.
 * Se ejecuta cuando el pedido pasa a estado "Completado".
 * Incluye lógica completa de api-facturas.php: pluPadre, formasPago, Micro Lotes, etc.
 */

defined( 'ABSPATH' ) || exit;

class DFC_Invoice_Generator {

    /**
     * Cantidad maxima de intentos para obtener factura firmada (no contingencia).
     */
    private const MAX_SIGNED_ATTEMPTS = 3;

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
    const META_PREBUILT_SOURCE_ORDER = '_dfc_prebuilt_invoice_source_order';
    const META_PREBUILT_READY_AT     = '_dfc_prebuilt_invoice_ready_at';
    const META_PREBUILT_ATTACHMENT_ID = '_dfc_prebuilt_invoice_attachment_id';
    const META_PREBUILT_APPLIED_FROM = '_dfc_prebuilt_invoice_applied_from';
    const META_PREBUILT_APPLIED_AT   = '_dfc_prebuilt_invoice_applied_at';
    const USER_META_PREBUILT_SOURCE_ORDER = '_dfc_prebuilt_invoice_source_order';
    const USER_META_PREBUILT_READY_AT     = '_dfc_prebuilt_invoice_ready_at';
    const USER_META_LEGACY_ATTACHMENT_ID  = '_invoice_created_by_button';

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
        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            return;
        }

        $this->log_info( sprintf( 'on_order_completed iniciado para pedido #%d.', $order_id ) );

        // Si existe factura preparada desde suscripcion, copiarla y evitar nueva certificacion.
        if ( $this->maybe_apply_prebuilt_subscription_invoice( $order ) ) {
            $this->log_info( sprintf( 'Pedido #%d reutilizó factura preconstruida. No se genera nueva.', $order_id ) );
            return;
        }

        // Si es renovación y existe una preinvoice disponible, NO generar una nueva para evitar doble facturación.
        if ( $this->is_renewal_order( $order ) && $this->has_prebuilt_reference_for_order( $order ) ) {
            $this->log_error( sprintf( 'Pedido #%d es renovación con preinvoice disponible pero no pudo aplicarse. Se bloquea nueva certificación para evitar duplicado.', $order_id ) );
            return;
        }

        // Verificar si la facturación automática está habilitada
        if ( ! get_option( DFC_Settings::OPTION_AUTO_INVOICE ) ) {
            $this->log_info( sprintf( 'Pedido #%d: facturación automática deshabilitada.', $order_id ) );
            return;
        }

        // Verificar si ya tiene FEL
        $existing_seria = $order->get_meta( self::META_FEL_SERIE );
        if ( ! empty( $existing_seria ) ) {
            // Ya fue facturada
            $this->log_info( sprintf( 'Pedido #%d ya tiene FEL serie %s.', $order_id, (string) $existing_seria ) );
            return;
        }

        // Generar factura
        $this->generate_invoice( $order );
    }

    /**
     * Procesar factura anticipada para una suscripcion.
     * Genera FEL sobre el pedido padre y deja referencia para aplicarla al pedido de renovacion.
     *
     * @return array|WP_Error
     */
    public function process_subscription_preinvoice( int $subscription_id ) {
        if ( ! function_exists( 'wcs_get_subscription' ) ) {
            return new WP_Error(
                'dfc_subscriptions_missing',
                __( 'WooCommerce Subscriptions no está activo.', 'dale-facturas' )
            );
        }

        $subscription = wcs_get_subscription( $subscription_id );
        if ( ! $subscription ) {
            return new WP_Error(
                'dfc_subscription_not_found',
                __( 'Suscripción no encontrada.', 'dale-facturas' )
            );
        }

        $parent_order_id = absint( $subscription->get_parent_id() );
        if ( ! $parent_order_id ) {
            return new WP_Error(
                'dfc_subscription_parent_missing',
                __( 'La suscripción no tiene pedido padre para usar como base.', 'dale-facturas' )
            );
        }

        $source_order = wc_get_order( $parent_order_id );
        if ( ! $source_order ) {
            return new WP_Error(
                'dfc_source_order_not_found',
                __( 'No se pudo cargar el pedido base de la suscripción.', 'dale-facturas' )
            );
        }

        $this->log_info( sprintf( 'Preinvoice suscripción #%d usando pedido base #%d.', $subscription_id, $parent_order_id ) );

        if ( ! $source_order->get_meta( self::META_FEL_SERIE ) ) {
            $this->log_info( sprintf( 'Pedido base #%d sin FEL previa. Generando factura.', $parent_order_id ) );
            $result = $this->generate_invoice( $source_order, 'preinvoice' );
            if ( is_wp_error( $result ) ) {
                $this->log_error( sprintf( 'Error generando preinvoice para suscripción #%d: %s', $subscription_id, $result->get_error_message() ) );
                return $result;
            }
        }

        $subscription->update_meta_data( self::META_PREBUILT_SOURCE_ORDER, $source_order->get_id() );
        $subscription->update_meta_data( self::META_PREBUILT_READY_AT, time() );
        $subscription->save_meta_data();

        $attachment_id = $this->ensure_prebuilt_pdf_attachment( $source_order );
        if ( $attachment_id > 0 ) {
            $subscription->update_meta_data( self::META_PREBUILT_ATTACHMENT_ID, $attachment_id );
            $subscription->save_meta_data();
            $this->log_info( sprintf( 'Suscripción #%d guardó preinvoice attachment_id=%d.', $subscription_id, $attachment_id ) );
        }

        $customer_id = absint( $subscription->get_customer_id() );
        if ( $customer_id > 0 ) {
            update_user_meta( $customer_id, self::USER_META_PREBUILT_SOURCE_ORDER, $source_order->get_id() );
            update_user_meta( $customer_id, self::USER_META_PREBUILT_READY_AT, time() );
            if ( $attachment_id > 0 ) {
                update_user_meta( $customer_id, self::USER_META_LEGACY_ATTACHMENT_ID, $attachment_id );
            }
            $this->log_info( sprintf( 'Suscripción #%d guardó preinvoice en user_meta para user #%d, source_order #%d.', $subscription_id, $customer_id, $source_order->get_id() ) );
        }

        $subscription->add_order_note(
            sprintf(
                __( 'Factura anticipada preparada usando pedido base #%d.', 'dale-facturas' ),
                $source_order->get_id()
            )
        );

        return [
            'source_order_id' => $source_order->get_id(),
            'serie'           => (string) $source_order->get_meta( self::META_FEL_SERIE ),
            'transaccion'     => (string) $source_order->get_meta( self::META_FEL_TRANSACCION ),
        ];
    }

    /**
     * Intentar aplicar una factura preconstruida de suscripcion al pedido recibido.
     */
    private function maybe_apply_prebuilt_subscription_invoice( WC_Order $order ): bool {
        $order_id = $order->get_id();
        $source_order_id = 0;

        foreach ( $this->get_related_subscriptions_for_order( $order ) as $subscription ) {
            $source_order_id = absint( $subscription->get_meta( self::META_PREBUILT_SOURCE_ORDER ) );
            if ( $source_order_id ) {
                $this->log_info( sprintf( 'Pedido #%d encontró source_order #%d desde suscripción #%d.', $order_id, $source_order_id, $subscription->get_id() ) );
                break;
            }
        }

        if ( ! $source_order_id ) {
            $customer_id = absint( $order->get_customer_id() );
            if ( $customer_id > 0 ) {
                $source_order_id = absint( get_user_meta( $customer_id, self::USER_META_PREBUILT_SOURCE_ORDER, true ) );
                if ( $source_order_id ) {
                    $this->log_info( sprintf( 'Pedido #%d encontró source_order #%d desde user_meta user #%d.', $order_id, $source_order_id, $customer_id ) );
                }
            }
        }

        if ( ! $source_order_id ) {
            $this->log_info( sprintf( 'Pedido #%d no tiene preinvoice disponible.', $order_id ) );
            return false;
        }

        $source_order = wc_get_order( $source_order_id );
        if ( ! $source_order || ! $source_order->get_meta( self::META_FEL_SERIE ) ) {
            $this->log_error( sprintf( 'Pedido #%d tenía source_order #%d pero sin FEL válida.', $order_id, $source_order_id ) );
            return false;
        }

        // Asegurar que exista PDF preconstruido para poder asociarlo en el renewal.
        $this->ensure_prebuilt_pdf_attachment( $source_order );

        $this->copy_fel_meta( $source_order, $order );
        $order->update_meta_data( self::META_PREBUILT_APPLIED_FROM, $source_order->get_id() );
        $order->update_meta_data( self::META_PREBUILT_APPLIED_AT, time() );
        $order->save_meta_data();

        $customer_id = absint( $order->get_customer_id() );
        if ( $customer_id > 0 ) {
            delete_user_meta( $customer_id, self::USER_META_PREBUILT_SOURCE_ORDER );
            delete_user_meta( $customer_id, self::USER_META_PREBUILT_READY_AT );
        }

        $order->add_order_note(
            sprintf(
                __( 'Factura anticipada aplicada desde pedido base #%d y reutilizada en este pedido de renovación.', 'dale-facturas' ),
                $source_order->get_id()
            ),
            false
        );
        $this->log_info( sprintf( 'Pedido #%d reutilizó FEL desde source_order #%d.', $order_id, $source_order->get_id() ) );
        return true;
    }

    /**
     * Logging info con source consistente.
     */
    private function log_info( string $message ): void {
        if ( function_exists( 'wc_get_logger' ) ) {
            wc_get_logger()->info( $message, [ 'source' => 'dale-facturas' ] );
        }
    }

    /**
     * Logging error con source consistente.
     */
    private function log_error( string $message ): void {
        if ( function_exists( 'wc_get_logger' ) ) {
            wc_get_logger()->error( $message, [ 'source' => 'dale-facturas' ] );
        }
    }

    /**
     * Copiar metadatos FEL relevantes desde un pedido origen hacia uno destino.
     */
    private function copy_fel_meta( WC_Order $source_order, WC_Order $target_order ): void {
        $meta_keys = [
            self::META_FEL_SERIE,
            self::META_FEL_TRANSACCION,
            self::META_FEL_FIRMA,
            self::META_FEL_CONTINGENCIA,
            self::META_FEL_TIMESTAMP,
            self::META_API_REQUEST,
            self::META_API_RESPONSE,
            '_dfc_fel_fecha_certificacion',
            '_dfc_fel_numero_acceso',
            '_dfc_fel_nit_empresa',
            '_dfc_fel_nombre_empresa',
            '_dfc_fel_establecimiento_nombre',
            '_dfc_fel_resolucion_numero',
            '_dfc_fel_resolucion_fecha',
            '_dfc_fel_gface_empresa',
            '_dfc_fel_gface_nit',
        ];

        foreach ( $meta_keys as $meta_key ) {
            $value = $source_order->get_meta( $meta_key );
            if ( '' === $value || null === $value ) {
                continue;
            }
            $target_order->update_meta_data( $meta_key, $value );
        }

        // Compatibilidad con flujo antiguo del theme (PDF por attachment preasignado).
        $legacy_attachment_id = $source_order->get_meta( '_invoice_created_by_button' );
        if ( ! empty( $legacy_attachment_id ) ) {
            $target_order->update_meta_data( '_invoice_created_by_button', $legacy_attachment_id );
        }

        $target_order->delete_meta_data( self::META_FEL_ERROR );
        $target_order->save_meta_data();
    }

    /**
     * Obtener suscripciones relacionadas con un pedido.
     *
     * @return array<int,WC_Order>
     */
    private function get_related_subscriptions_for_order( WC_Order $order ): array {
        $subscriptions = [];

        if ( function_exists( 'wcs_get_subscriptions_for_renewal_order' ) ) {
            $subscriptions = wcs_get_subscriptions_for_renewal_order( $order );
        }

        if ( empty( $subscriptions ) && function_exists( 'wcs_get_subscriptions_for_order' ) ) {
            $subscriptions = wcs_get_subscriptions_for_order( $order->get_id() );
        }

        if ( empty( $subscriptions ) ) {
            $subscription_id = absint( $order->get_meta( '_subscription_renewal' ) );
            if ( $subscription_id && function_exists( 'wcs_get_subscription' ) ) {
                $subscription = wcs_get_subscription( $subscription_id );
                if ( $subscription ) {
                    $subscriptions = [ $subscription_id => $subscription ];
                }
            }
        }

        return is_array( $subscriptions ) ? $subscriptions : [];
    }

    /**
     * Determina si el pedido es de renovación.
     */
    private function is_renewal_order( WC_Order $order ): bool {
        if ( function_exists( 'wcs_order_contains_renewal' ) && wcs_order_contains_renewal( $order ) ) {
            return true;
        }
        return absint( $order->get_meta( '_subscription_renewal' ) ) > 0;
    }

    /**
     * Verifica si hay referencia preinvoice para el pedido de renovación.
     */
    private function has_prebuilt_reference_for_order( WC_Order $order ): bool {
        foreach ( $this->get_related_subscriptions_for_order( $order ) as $subscription ) {
            if ( absint( $subscription->get_meta( self::META_PREBUILT_SOURCE_ORDER ) ) > 0 ) {
                return true;
            }
        }

        $customer_id = absint( $order->get_customer_id() );
        if ( $customer_id > 0 && absint( get_user_meta( $customer_id, self::USER_META_PREBUILT_SOURCE_ORDER, true ) ) > 0 ) {
            return true;
        }

        return false;
    }

    /**
     * Genera y guarda (si hace falta) el PDF preconstruido como attachment.
     */
    private function ensure_prebuilt_pdf_attachment( WC_Order $source_order ): int {
        $existing_attachment_id = absint( $source_order->get_meta( '_invoice_created_by_button' ) );
        if ( $existing_attachment_id > 0 ) {
            return $existing_attachment_id;
        }

        if ( ! function_exists( 'wcpdf_get_document' ) || ! function_exists( 'WPO_WCPDF' ) ) {
            $this->log_info( sprintf( 'Pedido #%d: WPO PDF no disponible para generar attachment preinvoice.', $source_order->get_id() ) );
            return 0;
        }

        try {
            $document_type = 'invoice';
            $order_ids = [ $source_order->get_id() ];
            $document = wcpdf_get_document( $document_type, $order_ids, true );
            $output_mode = WPO_WCPDF()->settings->get_output_mode( $document_type );
            $pdf_binary = $document ? $document->get_pdf( $output_mode ) : '';

            if ( empty( $pdf_binary ) ) {
                $this->log_error( sprintf( 'Pedido #%d: no se pudo obtener binario PDF de WPO.', $source_order->get_id() ) );
                return 0;
            }

            $upload = wp_upload_bits( 'invoice-' . $source_order->get_id() . '-prebuilt.pdf', null, $pdf_binary );
            if ( ! empty( $upload['error'] ) || empty( $upload['file'] ) ) {
                $this->log_error( sprintf( 'Pedido #%d: error guardando PDF preinvoice: %s', $source_order->get_id(), (string) ( $upload['error'] ?? 'unknown' ) ) );
                return 0;
            }

            $attachment = [
                'post_mime_type' => 'application/pdf',
                'post_title'     => 'invoice-' . $source_order->get_id() . '-prebuilt',
                'post_content'   => '',
                'post_status'    => 'inherit',
            ];

            $attachment_id = wp_insert_attachment( $attachment, $upload['file'], $source_order->get_id() );
            if ( ! $attachment_id || is_wp_error( $attachment_id ) ) {
                $this->log_error( sprintf( 'Pedido #%d: fallo insertando attachment PDF preinvoice.', $source_order->get_id() ) );
                return 0;
            }

            $source_order->update_meta_data( '_invoice_created_by_button', $attachment_id );
            $source_order->save_meta_data();

            $this->log_info( sprintf( 'Pedido #%d: PDF preinvoice creado attachment_id=%d.', $source_order->get_id(), $attachment_id ) );
            return (int) $attachment_id;
        } catch ( Throwable $e ) {
            $this->log_error( sprintf( 'Pedido #%d: excepción generando PDF preinvoice: %s', $source_order->get_id(), $e->getMessage() ) );
            return 0;
        }
    }

    /**
     * Generar factura para un pedido.
     * Construye payload, llama API, guarda meta.
     *
     * @param WC_Order $order Pedido a facturar.
     *
     * @return bool|WP_Error true si éxito, WP_Error si falló.
     */
    public function generate_invoice( WC_Order $order, string $context = 'default' ) {
        // 1. Construir payload
        $payload = $this->build_invoice_payload( $order, $context );
        if ( is_wp_error( $payload ) ) {
            $this->save_error( $order, $payload->get_error_message() );
            return $payload;
        }

        // Guardar request en meta (para debug)
        $order->update_meta_data( self::META_API_REQUEST, wp_json_encode( $payload ) );

        // 2. Crear cliente API y enviar
        $api = DFC_Macrobase_API::from_options();
        $result = null;
        for ( $attempt = 1; $attempt <= self::MAX_SIGNED_ATTEMPTS; $attempt++ ) {
            $current_result = $api->enviar_factura( $payload );

            if ( is_wp_error( $current_result ) ) {
                $this->save_error( $order, $current_result->get_error_message() );
                return $current_result;
            }

            $result = $current_result;

            if ( ! $this->is_contingency_result( $current_result ) ) {
                break;
            }

            if ( function_exists( 'wc_get_logger' ) ) {
                wc_get_logger()->warning(
                    sprintf( 'Pedido %d: intento %d devolvio contingencia, reintentando para obtener firma.', $order->get_id(), $attempt ),
                    [ 'source' => 'dale-facturas' ]
                );
            }
        }

        if ( ! is_array( $result ) || $this->is_contingency_result( $result ) ) {
            $error = new WP_Error(
                'dfc_contingency_not_allowed',
                __( 'No fue posible obtener factura firmada; el API devolvió contingencia. Reintenta en unos minutos.', 'dale-facturas' )
            );
            $this->save_error( $order, $error->get_error_message() );
            return $error;
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
        $order->add_order_note( $msg, false );

        return true;
    }

    /**
     * Determina si el resultado devuelto por el API corresponde a contingencia.
     *
     * @param array $result Resultado de DFC_Macrobase_API::enviar_factura().
     *
     * @return bool
     */
    private function is_contingency_result( array $result ): bool {
        $flag_contingencia = ! empty( $result['esContingencia'] );
        $firma = isset( $result['firmaElectronica'] ) ? trim( (string) $result['firmaElectronica'] ) : '';

        return $flag_contingencia || '' === $firma;
    }

    /**
     * Construir el payload de facturación para el API.
     * Incluye lógica completa del api-facturas.php original.
     *
     * @param WC_Order $order Pedido.
     *
     * @return array|WP_Error Payload listo para enviar, o WP_Error si algo falta.
     */
    private function build_invoice_payload( WC_Order $order, string $context = 'default' ) {
        $mapper = new DFC_Product_Mapper();
        $items  = [];
        $subtotal = 0;

        $this->log_info( sprintf( 'Construyendo payload para pedido #%d.', $order->get_id() ) );

        // 1. Iterar items del pedido
        foreach ( $order->get_items() as $item ) {
            if ( $item->is_type( 'line_item' ) ) {
                $product = $item->get_product();
                if ( ! $product ) {
                    $this->log_info( sprintf( 'Pedido #%d: item %d sin producto cargable, se omite.', $order->get_id(), $item->get_id() ) );
                    continue;
                }

                $item_total = (float) $item->get_total();
                $item_qty = (int) $item->get_quantity();
                $sku = trim( (string) $product->get_sku() );
                $product_name = (string) $product->get_name();
                $product_type = method_exists( $product, 'get_type' ) ? (string) $product->get_type() : '';

                $this->log_info(
                    sprintf(
                        'Pedido #%1$d item #%2$d: product_id=%3$d name="%4$s" sku="%5$s" type="%6$s" qty=%7$d total=%8$s',
                        $order->get_id(),
                        $item->get_id(),
                        (int) $product->get_id(),
                        $product_name,
                        $sku,
                        $product_type,
                        $item_qty,
                        (string) $item_total
                    )
                );

                // Extraer datos del item (molienda, blend, etc.)
                $item_data = DFC_Product_Mapper::extract_item_data( $item );

                // Evitar que productos contenedor de suscripción sin SKU bloqueen la certificación.
                if ( '' === $sku && str_contains( strtolower( $product_type ), 'subscription' ) ) {
                    $legacy_meta_items = $item->get_meta( '_tmcartepo_data', true );
                    $legacy_added_total = 0.0;
                    $first_legacy_plu = null;
                    if ( is_array( $legacy_meta_items ) && ! empty( $legacy_meta_items ) ) {
                        $this->log_info(
                            sprintf(
                                'Pedido #%d: item contenedor de suscripción %d sin SKU, usando _tmcartepo_data (%d entradas).',
                                $order->get_id(),
                                $item->get_id(),
                                count( $legacy_meta_items )
                            )
                        );

                        foreach ( $legacy_meta_items as $legacy_row ) {
                            if ( ! is_array( $legacy_row ) ) {
                                continue;
                            }

                            $legacy_name = isset( $legacy_row['name'] ) ? trim( (string) $legacy_row['name'] ) : '';
                            $legacy_value = isset( $legacy_row['value'] ) ? trim( (string) $legacy_row['value'] ) : '';
                            $legacy_qty = isset( $legacy_row['quantity'] ) ? (int) $legacy_row['quantity'] : 1;
                            $legacy_price = isset( $legacy_row['price'] ) ? (float) $legacy_row['price'] : 0.0;

                            // Compatibilidad con flujo legacy: sólo omitir filas completamente vacías.
                            if ( '' === $legacy_value ) {
                                continue;
                            }

                            $legacy_plu = $mapper->get_plu_from_option_value( $legacy_value );
                            if ( null === $legacy_plu ) {
                                $this->log_info(
                                    sprintf(
                                        'Pedido #%d: legacy row "%s"="%s" sin PLU mapeable, se omite.',
                                        $order->get_id(),
                                        $legacy_name,
                                        $legacy_value
                                    )
                                );
                                continue;
                            }

                            if ( null === $first_legacy_plu ) {
                                $first_legacy_plu = $legacy_plu;
                            }

                            if ( $legacy_qty <= 0 ) {
                                $legacy_qty = 1;
                            }

                            $legacy_unit_price = $legacy_price > 0 ? ( $legacy_price / $legacy_qty ) : 0;
                            $items[] = [
                                'plu'                      => $legacy_plu,
                                'cantidad'                 => $legacy_qty,
                                'precio'                   => $legacy_unit_price,
                                'monto'                    => $legacy_price,
                                'descuentoItemPorcentaje'  => 0,
                                'comboNumero'              => 1,
                                'pluPadre'                 => $legacy_plu,
                            ];
                            $subtotal += $legacy_price;
                            $legacy_added_total += $legacy_price;
                        }
                    }

                    // Comportamiento legacy: el item padre aporta monto cuando addons no cargan el total completo.
                    if ( $item_total > 0 && $legacy_added_total < $item_total ) {
                        $container_plu = (int) ( $first_legacy_plu ?? 1 );
                        $container_qty = $item_qty > 0 ? $item_qty : 1;
                        $container_monto = $item_total - $legacy_added_total;
                        $container_precio = $container_monto / $container_qty;

                        $items[] = [
                            'plu'                      => $container_plu,
                            'cantidad'                 => $container_qty,
                            'precio'                   => $container_precio,
                            'monto'                    => $container_monto,
                            'descuentoItemPorcentaje'  => 0,
                            'comboNumero'              => 1,
                            'pluPadre'                 => $container_plu,
                        ];
                        $subtotal += $container_monto;

                        $this->log_info(
                            sprintf(
                                'Pedido #%d: item contenedor %d agregó línea compensatoria monto=%s plu=%d (legacy_total=%s, item_total=%s).',
                                $order->get_id(),
                                $item->get_id(),
                                (string) wc_format_decimal( $container_monto, 2 ),
                                $container_plu,
                                (string) wc_format_decimal( $legacy_added_total, 2 ),
                                (string) wc_format_decimal( $item_total, 2 )
                            )
                        );
                    }

                    $this->log_info(
                        sprintf(
                            'Pedido #%d: se omite item contenedor de suscripción sin SKU (product_id=%d, item_id=%d).',
                            $order->get_id(),
                            (int) $product->get_id(),
                            $item->get_id()
                        )
                    );
                    continue;
                }

                // Obtener PLU
                $plu = $mapper->get_plu_for_product( $product, $item_data );
                if ( is_wp_error( $plu ) ) {
                    $this->log_error(
                        sprintf(
                            'Pedido #%d: error de PLU en item %d (product_id=%d): %s',
                            $order->get_id(),
                            $item->get_id(),
                            (int) $product->get_id(),
                            $plu->get_error_message()
                        )
                    );
                    return $plu;
                }

                $quantity = $item_qty;
                // Usar total neto del item para reflejar cupones/descuentos.
                if ( $quantity <= 0 ) {
                    $this->log_info( sprintf( 'Pedido #%d: item %d con cantidad <= 0, se omite.', $order->get_id(), $item->get_id() ) );
                    continue;
                }
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

        // 6. Intentar enriquecer nombre/direccion desde consulta NIT.
        $api = DFC_Macrobase_API::from_options();
        $nit_lookup = $api->consultar_nit( (string) $nit );

        $nombre_ordenado = ! empty( $nit_lookup['nombre_ordenado'] )
            ? trim( (string) $nit_lookup['nombre_ordenado'] )
            : '';

        // Nunca usar "Consumidor Final" como nombre en la factura.
        if ( '' !== $nombre_ordenado && preg_match( '/consumidor\s+final/i', $nombre_ordenado ) ) {
            $nombre_ordenado = '';
        }

        $cliente_nombre_factura = '' !== $nombre_ordenado
            ? $nombre_ordenado
            : $cliente['nombre'];

        $cliente_direccion_factura = trim( $cliente['direccion'] . ' ' . $cliente['direccion2'] . ' ' . $cliente['ciudad'] );

        if ( ! empty( $nit_lookup['nit'] ) ) {
            $nit = (string) $nit_lookup['nit'];
        }

        // 7. Construir formasPago basado en el método de pago
        $detalles_total = 0.0;
        foreach ( $items as $detail ) {
            $detalles_total += (float) ( $detail['monto'] ?? 0 );
        }
        $order_total = (float) $order->get_total();
        $this->log_info(
            sprintf(
                'Pedido #%d: total detalles=%s vs total pedido=%s.',
                $order->get_id(),
                (string) wc_format_decimal( $detalles_total, 2 ),
                (string) wc_format_decimal( $order_total, 2 )
            )
        );

        $formas_pago = $this->build_formas_pago( $order, $order_total );

        // 8. Construir payload final (estructura compatible con api-facturas.php original)
        // Legacy theme: usa _wcj_order_number; si no existe, fallback a get_order_number().
        $legacy_order_number = (string) $order->get_meta( '_wcj_order_number' );
        $order_number_raw = '' !== trim( $legacy_order_number ) ? $legacy_order_number : (string) $order->get_order_number();
        $order_number_digits = preg_replace( '/\D+/', '', $order_number_raw );
        if ( '' === $order_number_digits ) {
            $order_number_digits = (string) $order->get_id();
        }

        // Requisito operativo: el id de Macrobase debe iniciar con "1".
        // En preinvoice usamos un ID único por post_id para evitar choques históricos de ECN.
        if ( 'preinvoice' === $context ) {
            $macrobase_id = '1' . (string) $order->get_id();
        } else {
            $macrobase_id = str_starts_with( $order_number_digits, '1' )
                ? $order_number_digits
                : '1' . $order_number_digits;
        }

        $this->log_info(
            sprintf(
                'Pedido #%d: context="%s" numeroOrden base="%s" (wcj="%s") -> id Macrobase="%s".',
                $order->get_id(),
                $context,
                $order_number_digits,
                $legacy_order_number,
                $macrobase_id
            )
        );

        $payload = [
            // Macrobase requiere "id" en ordenes[].
            'id'           => $macrobase_id,
            // Se mantiene por compatibilidad con implementaciones previas.
            'numeroOrden'  => $order_number_digits,
            'clienteNombre' => $cliente_nombre_factura,
            'clienteTelefono' => $cliente['telefono'],
            'clienteEmail' => $cliente['email'],
            'clienteNIT'   => $nit,
            'clienteDireccion2' => $cliente_direccion_factura,
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
        $order->delete_meta_data( self::META_FEL_CONTINGENCIA );
        $order->delete_meta_data( self::META_API_RESPONSE );
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
        $order->delete_meta_data( self::META_FEL_CONTINGENCIA );
        $order->delete_meta_data( self::META_FEL_ERROR );
        $order->delete_meta_data( self::META_API_RESPONSE );
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
