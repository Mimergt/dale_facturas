<?php
/**
 * Se ejecuta cuando el usuario desinstala el plugin desde WP Admin.
 * Elimina todas las opciones del plugin de la base de datos.
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$options = [
    'dfc_api_url',
    'dfc_api_usuario',
    'dfc_api_clave',
    'dfc_api_mode',
    'dfc_auto_invoice',
    'dfc_invoice_subscriptions',
    'dfc_debug_mode',
    'dfc_plu_map',
];

foreach ( $options as $option ) {
    delete_option( $option );
}
