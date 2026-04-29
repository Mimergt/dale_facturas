<?php
/**
 * Plugin Name:       DaleCafe Facturas
 * Plugin URI:        https://github.com/Mimergt/dale_facturas
 * Description:       Integración de WooCommerce con el API de Macrobase para facturación electrónica FEL en Guatemala. Compatible con WooCommerce PDF Invoices & Packing Slips.
 * Version:           1.0.1
 * Requires at least: 5.9
 * Requires PHP:      7.4
 * Author:            DaleCafe
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       dale-facturas
 * Domain Path:       /languages
 *
 * WC requires at least: 6.5
 * WC tested up to:      6.5.1
 */

defined( 'ABSPATH' ) || exit;

// Constantes del plugin
define( 'DFC_VERSION',     '1.0.1' );
define( 'DFC_PLUGIN_FILE', __FILE__ );
define( 'DFC_PLUGIN_DIR',  plugin_dir_path( __FILE__ ) );
define( 'DFC_PLUGIN_URL',  plugin_dir_url( __FILE__ ) );

/**
 * Verificar dependencias antes de cargar el plugin.
 */
function dfc_check_dependencies() {
    if ( ! class_exists( 'WooCommerce' ) ) {
        add_action( 'admin_notices', function () {
            echo '<div class="notice notice-error"><p>';
            echo '<strong>DaleCafe Facturas</strong>: requiere que WooCommerce esté instalado y activo.';
            echo '</p></div>';
        } );
        return false;
    }
    return true;
}

/**
 * Inicializar el plugin tras cargar todos los plugins.
 */
function dfc_init() {
    if ( ! dfc_check_dependencies() ) {
        return;
    }

    require_once DFC_PLUGIN_DIR . 'includes/class-dale-facturas.php';
    Dale_Facturas::get_instance()->init();
}
add_action( 'plugins_loaded', 'dfc_init' );

/**
 * Limpiar opciones al desinstalar (via uninstall.php).
 * Activación: no hace nada especial por ahora (seguro de activar en producción).
 */
register_activation_hook( __FILE__, 'dfc_activate' );
function dfc_activate() {
    // Cargar defaults de opciones si no existen aún
    if ( ! get_option( 'dfc_api_url' ) ) {
        update_option( 'dfc_api_url', 'https://macroapps.sistemasmb.com/apiOci/web/app.php/api/edngt/facturar' );
    }
    if ( ! get_option( 'dfc_auto_invoice' ) ) {
        // OFF por defecto — activar manualmente tras verificar en producción
        update_option( 'dfc_auto_invoice', '0' );
    }
    if ( ! get_option( 'dfc_invoice_subscriptions' ) ) {
        update_option( 'dfc_invoice_subscriptions', '0' );
    }
}
