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
     * NIT por defecto si no se especifica otro.
     */
    const DEFAULT_NIT = 'CF';

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
        $nit = '';

        // 1. Buscar en meta del pedido (_billing_nit con guion)
        $nit = $order->get_meta( self::ORDER_NIT_META_KEY );
        if ( ! empty( $nit ) && 'CF' !== strtoupper( $nit ) ) {
            return self::sanitize_nit( $nit );
        }

        // 2. Buscar en meta del pedido (billing_nit sin guion)
        $nit = $order->get_meta( self::ORDER_NIT_META_KEY_ALT );
        if ( ! empty( $nit ) && 'CF' !== strtoupper( $nit ) ) {
            return self::sanitize_nit( $nit );
        }

        // 3. Buscar en meta del cliente/usuario (billing_nit)
        $customer_id = $order->get_customer_id();
        if ( $customer_id > 0 ) {
            $nit = get_user_meta( $customer_id, self::USER_NIT_META_KEY, true );
            if ( ! empty( $nit ) && 'CF' !== strtoupper( $nit ) ) {
                return self::sanitize_nit( $nit );
            }
        }

        // 4. Buscar en meta de suscripción si es orden de renovación
        $subscriptions = wcs_get_subscriptions_for_order( $order->get_id() );
        if ( ! empty( $subscriptions ) ) {
            foreach ( $subscriptions as $subscription ) {
                $nit = $subscription->get_meta( self::ORDER_NIT_META_KEY );
                if ( ! empty( $nit ) && 'CF' !== strtoupper( $nit ) ) {
                    return self::sanitize_nit( $nit );
                }
                $nit = $subscription->get_meta( self::ORDER_NIT_META_KEY_ALT );
                if ( ! empty( $nit ) && 'CF' !== strtoupper( $nit ) ) {
                    return self::sanitize_nit( $nit );
                }
            }
        }

        // 5. Retornar default
        return self::DEFAULT_NIT;
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
        // Remover caracteres no numéricos
        $sanitized = preg_replace( '/\D/', '', $nit );

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
