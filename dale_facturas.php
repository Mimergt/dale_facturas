<?php
/**
 * Plugin Name: Dale Facturas Test
 * Description: Plugin minimo de prueba para validar instalacion y descompresion.
 * Version: 0.0.1
 * Author: DaleCafe
 */

defined( 'ABSPATH' ) || exit;

add_action( 'admin_notices', function () {
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }

    echo '<div class="notice notice-success is-dismissible"><p>Dale Facturas Test: Hello World.</p></div>';
} );
