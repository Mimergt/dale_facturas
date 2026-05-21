<?php
/**
 * Manejador de NIT (Número de Identificación Tributaria).
 * Extrae el NIT del pedido con prioridad: meta del pedido → meta del cliente → default "CF"
 */

defined( 'ABSPATH' ) || exit;

class DFC_NIT_Handler {

    /**
     * Meta key para NIT en el pedido (con guion).
     */
    const ORDER_NIT_META_KEY = '_billing_nit';

    /**
     * Meta key alternativa para NIT en el pedido (sin guion).
     */
    const ORDER_NIT_META_KEY_ALT = 'billing_nit';

    /**
     * Meta key para NIT en el cliente/usuario.
     */
    const USER_NIT_META_KEY = 'billing_nit';

    /**
     * Meta key historica observada en algunas implementaciones.
     */
    const USER_NIT_META_KEY_LEGACY = 'nit_number';

    /**
     * NIT por defecto si no se especifica otro.
     */
    const DEFAULT_NIT = 'CF';

    /**
     * Lista de keys candidatas (prioridad alta) para NIT.
     * Se revisan en pedido, suscripcion y usuario.
     */
    private const PRIORITY_NIT_KEYS = [
        '_billing_nit',
        'billing_nit',
        'nit_number',
        '_nit_number',
        'nit',
        '_nit',
        'cliente_nit',
        '_cliente_nit',
    ];

    /**
     * Obtener el NIT de un pedido.
     * Busca en este orden:
     * 1. Meta del pedido (_billing_nit)
     * 2. Meta del pedido (billing_nit - sin guion)
     * 3. Meta del cliente/usuario (billing_nit)
     * 4. Meta de la suscripción si es renewal
     * 5. Default "CF"
     *
     * @param WC_Order $order Pedido de WooCommerce.
     *
     * @return string NIT encontrado, sanitizado (solo dígitos) o "CF".
     */
    public static function get_nit( WC_Order $order ): string {
        // 1. Buscar en metas de pedido (keys prioritarias)
        $nit = self::find_nit_in_order( $order );
        if ( '' !== $nit ) {
            return $nit;
        }

        // 2. Buscar en metas de usuario/cliente
        $customer_id = $order->get_customer_id();
        if ( $customer_id > 0 ) {
            $nit = self::find_nit_in_user( $customer_id );
            if ( '' !== $nit ) {
                return $nit;
            }
        }

        // 3. Buscar en metas de suscripcion asociada (renovaciones)
        foreach ( self::get_related_subscriptions( $order ) as $subscription ) {
            $nit = self::find_nit_in_subscription( $subscription );
            if ( '' !== $nit ) {
                return $nit;
            }
        }

        // 4. Retornar default
        return self::DEFAULT_NIT;
    }

    /**
     * Buscar NIT en meta del pedido.
     */
    private static function find_nit_in_order( WC_Order $order ): string {
        foreach ( self::PRIORITY_NIT_KEYS as $key ) {
            $nit = $order->get_meta( $key );
            if ( self::is_valid_nit_candidate( $nit ) ) {
                return self::sanitize_nit( (string) $nit );
            }
        }

        return self::find_nit_in_meta_data_objects( $order->get_meta_data() );
    }

    /**
     * Buscar NIT en meta de suscripcion.
     *
     * @param WC_Order $subscription Objeto suscripcion.
     */
    private static function find_nit_in_subscription( WC_Order $subscription ): string {
        foreach ( self::PRIORITY_NIT_KEYS as $key ) {
            $nit = $subscription->get_meta( $key );
            if ( self::is_valid_nit_candidate( $nit ) ) {
                return self::sanitize_nit( (string) $nit );
            }
        }

        return self::find_nit_in_meta_data_objects( $subscription->get_meta_data() );
    }

    /**
     * Buscar NIT en meta de usuario.
     */
    private static function find_nit_in_user( int $customer_id ): string {
        $priority_user_keys = [
            self::USER_NIT_META_KEY,
            self::USER_NIT_META_KEY_LEGACY,
            '_billing_nit',
            '_nit_number',
            'nit',
            '_nit',
        ];

        foreach ( $priority_user_keys as $key ) {
            $nit = get_user_meta( $customer_id, $key, true );
            if ( self::is_valid_nit_candidate( $nit ) ) {
                return self::sanitize_nit( (string) $nit );
            }
        }

        $all_meta = get_user_meta( $customer_id );
        if ( ! is_array( $all_meta ) ) {
            return '';
        }

        foreach ( $all_meta as $key => $values ) {
            if ( false === stripos( (string) $key, 'nit' ) ) {
                continue;
            }

            $value = is_array( $values ) ? reset( $values ) : $values;
            if ( self::is_valid_nit_candidate( $value ) ) {
                return self::sanitize_nit( (string) $value );
            }
        }

        return '';
    }

    /**
     * Buscar NIT en un array de metadatos WC (order/subscription).
     * Acepta solo claves que contengan "nit".
     *
     * @param array $meta_objects Lista de WC_Meta_Data.
     */
    private static function find_nit_in_meta_data_objects( array $meta_objects ): string {
        foreach ( $meta_objects as $meta_object ) {
            if ( ! is_object( $meta_object ) || ! method_exists( $meta_object, 'get_data' ) ) {
                continue;
            }

            $meta_data = $meta_object->get_data();
            $key       = isset( $meta_data['key'] ) ? (string) $meta_data['key'] : '';
            $value     = $meta_data['value'] ?? '';

            if ( '' === $key || false === stripos( $key, 'nit' ) ) {
                continue;
            }

            if ( self::is_valid_nit_candidate( $value ) ) {
                return self::sanitize_nit( (string) $value );
            }
        }

        return '';
    }

    /**
     * Obtener suscripciones relacionadas con un pedido.
     *
     * @return array<int,WC_Order>
     */
    private static function get_related_subscriptions( WC_Order $order ): array {
        $subscriptions = [];

        if ( function_exists( 'wcs_get_subscriptions_for_order' ) ) {
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
     * Validar candidato de NIT antes de sanitizar.
     */
    private static function is_valid_nit_candidate( $value ): bool {
        if ( ! is_scalar( $value ) ) {
            return false;
        }

        $normalized = strtoupper( trim( (string) $value ) );
        if ( '' === $normalized ) {
            return false;
        }

        if ( 'CF' === $normalized || 'CONSUMIDOR FINAL' === $normalized ) {
            return false;
        }

        return true;
    }

    /**
     * Sanitizar NIT: remover caracteres no numéricos.
     * Si resulta vacío, retorna "CF".
     *
     * @param string $nit NIT a sanitizar.
     *
     * @return string NIT sanitizado o "CF" si está vacío después de sanitizar.
     */
    public static function sanitize_nit( string $nit ): string {
        $normalized = strtoupper( trim( $nit ) );
        if ( 'CF' === $normalized || 'CONSUMIDOR FINAL' === $normalized ) {
            return self::DEFAULT_NIT;
        }

        // Mantener caracteres alfanumericos para cubrir formatos historicos (p.ej. K).
        $sanitized = preg_replace( '/[^A-Z0-9]/', '', $normalized );

        // Si está vacío después de sanitizar, retornar default
        if ( empty( $sanitized ) ) {
            return self::DEFAULT_NIT;
        }

        return $sanitized;
    }

    /**
     * Establecer el NIT en un pedido (para testing o manual override).
     *
     * @param WC_Order $order Pedido.
     * @param string   $nit   NIT a establecer.
     *
     * @return bool
     */
    public static function set_nit( WC_Order $order, string $nit ): bool {
        return $order->update_meta_data( self::ORDER_NIT_META_KEY, sanitize_text_field( $nit ) );
    }
}
