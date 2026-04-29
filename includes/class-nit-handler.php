<?php
/**
 * Manejador de NIT (Número de Identificación Tributaria).
 * Extrae el NIT del pedido con prioridad: meta del pedido → meta del cliente → default "CF"
 */

defined( 'ABSPATH' ) || exit;

class DFC_NIT_Handler {

    /**
     * Meta key para NIT en el pedido (guardado por el tema DaleCafe).
     */
    const ORDER_NIT_META_KEY = '_billing_nit';

    /**
     * Meta key para NIT en el cliente (si existe).
     */
    const USER_NIT_META_KEY = 'nit_number';

    /**
     * NIT por defecto si no se especifica otro.
     */
    const DEFAULT_NIT = 'CF';

    /**
     * Obtener el NIT de un pedido.
     * Busca en este orden:
     * 1. Meta del pedido (_billing_nit)
     * 2. Meta del cliente (nit_number)
     * 3. Campo de facturación en el pedido
     * 4. Default "CF"
     *
     * @param WC_Order $order Pedido de WooCommerce.
     *
     * @return string NIT encontrado, sanitizado (solo dígitos) o "CF".
     */
    public static function get_nit( WC_Order $order ): string {
        $nit = '';

        // 1. Buscar en meta del pedido
        $nit = $order->get_meta( self::ORDER_NIT_META_KEY );
        if ( ! empty( $nit ) ) {
            return self::sanitize_nit( $nit );
        }

        // 2. Buscar en meta del cliente
        $customer_id = $order->get_customer_id();
        if ( $customer_id > 0 ) {
            $nit = get_user_meta( $customer_id, self::USER_NIT_META_KEY, true );
            if ( ! empty( $nit ) ) {
                return self::sanitize_nit( $nit );
            }
        }

        // 3. Buscar en campos de facturación (algunos temas lo guardan aquí)
        $billing_nit = $order->get_billing_company();
        if ( ! empty( $billing_nit ) && preg_match( '/\d/', $billing_nit ) ) {
            // Si el campo de compañía contiene dígitos, podría ser NIT
            return self::sanitize_nit( $billing_nit );
        }

        // 4. Retornar default
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
