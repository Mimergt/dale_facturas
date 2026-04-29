<?php
/**
 * Template: DaleCafe-V3 — Invoice
 *
 * Template para facturas electrónicas FEL de DaleCafe, compatible con el plugin
 * WooCommerce PDF Invoices & Packing Slips (WPO WCPDF).
 *
 * Los datos FEL son leídos desde los order meta guardados por class-invoice-generator.php.
 * Este template NO hace llamadas al API de Macrobase — solo lee datos ya certificados.
 *
 * Meta keys leídas:
 *   _dfc_fel_serie, _dfc_fel_transaccion, _dfc_fel_firma_electronica,
 *   _dfc_fel_numero_acceso, _dfc_fel_fecha_certificacion, _dfc_fel_es_contingencia,
 *   _dfc_fel_nit_empresa, _dfc_fel_nombre_empresa, _dfc_fel_establecimiento_nombre,
 *   _dfc_fel_resolucion_numero, _dfc_fel_resolucion_fecha
 */

defined( 'ABSPATH' ) || exit;

if ( ! isset( $this ) || ! is_object( $this ) ) {
    return;
}

$document_type = isset( $this->type ) ? $this->type : ( method_exists( $this, 'get_type' ) ? $this->get_type() : 'invoice' );
$order         = isset( $this->order ) ? $this->order : null;
if ( ! $order instanceof WC_Abstract_Order ) {
    return;
}

$order_id = $order->get_id();

// Leer datos FEL certificados desde order meta
$fel_serie          = $order->get_meta( '_dfc_fel_serie' );
$fel_transaccion    = $order->get_meta( '_dfc_fel_transaccion' );
$fel_firma          = $order->get_meta( '_dfc_fel_firma' );
$fel_numero_acceso  = $order->get_meta( '_dfc_fel_numero_acceso' );
$fel_fecha_cert     = $order->get_meta( '_dfc_fel_fecha_certificacion' );
$fel_es_contingencia = (bool) $order->get_meta( '_dfc_fel_es_contingencia' );
$fel_nit_empresa    = $order->get_meta( '_dfc_fel_nit_empresa' );
$fel_nombre_empresa = $order->get_meta( '_dfc_fel_nombre_empresa' );
$fel_establecimiento = $order->get_meta( '_dfc_fel_establecimiento_nombre' );
$fel_resolucion_num = $order->get_meta( '_dfc_fel_resolucion_numero' );
$fel_resolucion_fecha = $order->get_meta( '_dfc_fel_resolucion_fecha' );

$fel_certificado = ! empty( $fel_serie ) && ! empty( $fel_transaccion );

// Datos del cliente
$billing_nit      = $order->get_meta( '_billing_nit' ) ?: $order->get_meta( 'billing_nit' ) ?: 'CF';
$billing_nitname  = $order->get_meta( '_billing_nitname' ) ?: $order->get_meta( 'billing_nitname' ) ?: '';
$billing_name     = trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );
$billing_email    = $order->get_billing_email();
$billing_phone    = $order->get_billing_phone();
$billing_address  = $order->get_billing_address_1();

// Número de pedido
$order_number = method_exists( $order, 'get_order_number' ) ? $order->get_order_number() : $order_id;
$order_items  = method_exists( $this, 'get_order_items' ) ? $this->get_order_items() : array();
$totals       = method_exists( $this, 'get_totals' ) ? $this->get_totals() : array();

if ( ! is_array( $order_items ) ) {
    $order_items = array();
}
if ( ! is_array( $totals ) ) {
    $totals = array();
}
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="UTF-8">
    <?php do_action( 'wpo_wcpdf_' . $document_type . '_head', $document_type, $order ); ?>
</head>
<body class="<?php echo esc_attr( $document_type ); ?> <?php echo esc_attr( $fel_es_contingencia ? 'contingencia' : 'principal' ); ?>">

<?php do_action( 'wpo_wcpdf_before_document', $document_type, $order ); ?>

<!-- =====================================================================
     CABECERA
====================================================================== -->
<div id="invoice-header">
    <table class="header-table">
        <tr>
            <td class="logo-cell">
                <?php if ( method_exists( $this, 'has_header_logo' ) && $this->has_header_logo() ) : ?>
                    <?php $this->header_logo(); ?>
                <?php else : ?>
                    <h1 class="shop-name"><?php echo esc_html( method_exists( $this, 'get_title' ) ? $this->get_title() : 'DaleCafe' ); ?></h1>
                <?php endif; ?>
            </td>
            <td class="invoice-title-cell">
                <?php if ( $fel_es_contingencia ) : ?>
                    <div class="invoice-type contingencia">
                        <span class="label"><?php esc_html_e( 'FACTURA DE CONTINGENCIA', 'dale-facturas' ); ?></span>
                    </div>
                <?php else : ?>
                    <div class="invoice-type principal">
                        <span class="label"><?php esc_html_e( 'FACTURA', 'dale-facturas' ); ?></span>
                    </div>
                <?php endif; ?>

                <?php if ( $fel_certificado ) : ?>
                    <div class="fel-serie-box">
                        <div class="fel-serie">
                            <strong><?php esc_html_e( 'Serie:', 'dale-facturas' ); ?></strong>
                            <?php echo esc_html( $fel_serie ); ?>
                        </div>
                        <div class="fel-transaccion">
                            <strong><?php esc_html_e( 'No.:', 'dale-facturas' ); ?></strong>
                            <?php echo esc_html( $fel_transaccion ); ?>
                        </div>
                    </div>
                <?php endif; ?>
            </td>
        </tr>
    </table>
</div>

<!-- =====================================================================
     DATOS DE EMPRESA Y PEDIDO
====================================================================== -->
<div id="invoice-body">
    <table class="info-table">
        <tr>
            <!-- Datos del establecimiento -->
            <td class="shop-info">
                <?php do_action( 'wpo_wcpdf_before_shop_name', $document_type, $order ); ?>
                <strong class="shop-name"><?php $this->shop_name(); ?></strong>
                <?php do_action( 'wpo_wcpdf_after_shop_name', $document_type, $order ); ?>
                <?php do_action( 'wpo_wcpdf_before_shop_address', $document_type, $order ); ?>
                <div class="shop-address"><?php $this->shop_address(); ?></div>
                <?php do_action( 'wpo_wcpdf_after_shop_address', $document_type, $order ); ?>
                <?php if ( $fel_nit_empresa ) : ?>
                    <div class="shop-nit">
                        <strong><?php esc_html_e( 'NIT:', 'dale-facturas' ); ?></strong>
                        <?php echo esc_html( $fel_nit_empresa ); ?>
                    </div>
                <?php endif; ?>
            </td>

            <!-- Datos del pedido -->
            <td class="order-data">
                <table class="order-data-table">
                    <?php do_action( 'wpo_wcpdf_before_order_data', $document_type, $order ); ?>
                    <tr>
                        <th><?php esc_html_e( 'Pedido:', 'dale-facturas' ); ?></th>
                        <td><?php echo esc_html( $order_number ); ?></td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e( 'Fecha:', 'dale-facturas' ); ?></th>
                        <td><?php $this->order_date(); ?></td>
                    </tr>
                    <?php if ( $fel_fecha_cert ) : ?>
                        <tr>
                            <th><?php esc_html_e( 'Certificación:', 'dale-facturas' ); ?></th>
                            <td><?php echo esc_html( $fel_fecha_cert ); ?></td>
                        </tr>
                    <?php endif; ?>
                    <?php do_action( 'wpo_wcpdf_after_order_data', $document_type, $order ); ?>
                </table>
            </td>
        </tr>
    </table>

    <!-- Datos del cliente -->
    <div id="billing-section">
        <table class="billing-table">
            <tr>
                <td>
                    <?php do_action( 'wpo_wcpdf_before_billing_address', $document_type, $order ); ?>
                    <strong><?php esc_html_e( 'Facturar a:', 'dale-facturas' ); ?></strong><br>
                    <strong><?php esc_html_e( 'NIT:', 'dale-facturas' ); ?></strong>
                    <?php echo esc_html( $billing_nit ); ?>
                    <?php if ( $billing_nitname ) : ?>
                        — <?php echo esc_html( $billing_nitname ); ?>
                    <?php endif; ?>
                    <br>
                    <?php echo esc_html( $billing_name ); ?><br>
                    <?php echo esc_html( $billing_address ); ?>
                    <?php do_action( 'wpo_wcpdf_after_billing_address', $document_type, $order ); ?>
                </td>
                <td>
                    <?php if ( $billing_email ) : ?>
                        <strong><?php esc_html_e( 'Email:', 'dale-facturas' ); ?></strong>
                        <?php echo esc_html( $billing_email ); ?><br>
                    <?php endif; ?>
                    <?php if ( $billing_phone ) : ?>
                        <strong><?php esc_html_e( 'Teléfono:', 'dale-facturas' ); ?></strong>
                        <?php echo esc_html( $billing_phone ); ?><br>
                    <?php endif; ?>
                    <strong><?php esc_html_e( 'Pago:', 'dale-facturas' ); ?></strong>
                    <?php $this->payment_method(); ?>
                </td>
            </tr>
        </table>
    </div>

    <!-- ===================================================================
         TABLA DE PRODUCTOS
    ==================================================================== -->
    <?php do_action( 'wpo_wcpdf_before_order_details', $document_type, $order ); ?>
    <table class="order-details">
        <thead>
            <tr>
                <th class="col-product"><?php esc_html_e( 'Producto', 'dale-facturas' ); ?></th>
                <th class="col-qty"><?php esc_html_e( 'Cantidad', 'dale-facturas' ); ?></th>
                <th class="col-price"><?php esc_html_e( 'Precio unit.', 'dale-facturas' ); ?></th>
                <th class="col-total"><?php esc_html_e( 'Total', 'dale-facturas' ); ?></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ( $order_items as $item_id => $item ) : ?>
                <tr class="<?php echo esc_attr( apply_filters( 'wpo_wcpdf_item_row_class', $item_id, $document_type, $order, $item_id ) ); ?>">
                    <td class="col-product">
                        <?php echo wp_kses_post( isset( $item['name'] ) ? $item['name'] : '' ); ?>
                        <?php do_action( 'wpo_wcpdf_before_item_meta', $document_type, $item, $order ); ?>
                        <?php if ( isset( $item['meta'] ) ) : ?>
                            <?php echo wp_kses_post( $item['meta'] ); ?>
                        <?php endif; ?>
                        <?php do_action( 'wpo_wcpdf_after_item_meta', $document_type, $item, $order ); ?>
                    </td>
                    <td class="col-qty"><?php echo esc_html( isset( $item['quantity'] ) ? $item['quantity'] : '' ); ?></td>
                    <td class="col-price"><?php echo wp_kses_post( isset( $item['single_price'] ) ? $item['single_price'] : ( isset( $item['price'] ) ? $item['price'] : '' ) ); ?></td>
                    <td class="col-total"><?php echo wp_kses_post( isset( $item['line_total'] ) ? $item['line_total'] : ( isset( $item['total'] ) ? $item['total'] : '' ) ); ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
        <tfoot>
            <?php foreach ( $totals as $key => $total ) : ?>
                <tr class="<?php echo esc_attr( $key ); ?>">
                    <td colspan="3" class="col-label"><?php echo wp_kses_post( isset( $total['label'] ) ? $total['label'] : '' ); ?></td>
                    <td class="col-total"><?php echo wp_kses_post( isset( $total['value'] ) ? $total['value'] : '' ); ?></td>
                </tr>
            <?php endforeach; ?>
        </tfoot>
    </table>
    <?php do_action( 'wpo_wcpdf_after_order_details', $document_type, $order ); ?>

    <!-- ===================================================================
         DATOS FEL (Certificación electrónica)
    ==================================================================== -->
    <?php if ( $fel_certificado ) : ?>
        <div id="fel-section" class="<?php echo $fel_es_contingencia ? 'contingencia' : 'principal'; ?>">
            <?php if ( $fel_es_contingencia ) : ?>
                <div class="contingencia-banner">
                    <strong><?php esc_html_e( '⚠ FACTURA DE CONTINGENCIA', 'dale-facturas' ); ?></strong>
                </div>
            <?php endif; ?>

            <table class="fel-data-table">
                <tr>
                    <td>
                        <div class="fel-row">
                            <strong><?php esc_html_e( 'Serie:', 'dale-facturas' ); ?></strong>
                            <span><?php echo esc_html( $fel_serie ); ?></span>
                        </div>
                        <div class="fel-row">
                            <strong><?php esc_html_e( 'Número:', 'dale-facturas' ); ?></strong>
                            <span><?php echo esc_html( $fel_transaccion ); ?></span>
                        </div>
                        <?php if ( $fel_resolucion_num ) : ?>
                            <div class="fel-row">
                                <strong><?php esc_html_e( 'Resolución:', 'dale-facturas' ); ?></strong>
                                <span><?php echo esc_html( $fel_resolucion_num ); ?></span>
                            </div>
                        <?php endif; ?>
                        <?php if ( $fel_resolucion_fecha ) : ?>
                            <div class="fel-row">
                                <strong><?php esc_html_e( 'Fecha resolución:', 'dale-facturas' ); ?></strong>
                                <span><?php echo esc_html( $fel_resolucion_fecha ); ?></span>
                            </div>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ( $fel_fecha_cert ) : ?>
                            <div class="fel-row">
                                <strong><?php esc_html_e( 'Fecha y hora certificación:', 'dale-facturas' ); ?></strong>
                                <span><?php echo esc_html( $fel_fecha_cert ); ?></span>
                            </div>
                        <?php endif; ?>
                        <?php if ( $fel_numero_acceso ) : ?>
                            <div class="fel-row">
                                <strong><?php esc_html_e( 'Número de acceso:', 'dale-facturas' ); ?></strong>
                                <span><?php echo esc_html( $fel_numero_acceso ); ?></span>
                            </div>
                        <?php endif; ?>
                    </td>
                </tr>
            </table>

            <?php if ( $fel_firma ) : ?>
                <div class="firma-section">
                    <strong><?php esc_html_e( 'Firma electrónica:', 'dale-facturas' ); ?></strong>
                    <div class="firma-text"><?php echo esc_html( $fel_firma ); ?></div>
                </div>
            <?php endif; ?>
        </div>
    <?php else : ?>
        <!-- Sin certificación FEL aún -->
        <div id="fel-section pending">
            <p class="fel-pending">
                <?php esc_html_e( 'Esta factura está pendiente de certificación electrónica (FEL).', 'dale-facturas' ); ?>
            </p>
        </div>
    <?php endif; ?>

</div><!-- #invoice-body -->

<!-- =====================================================================
     PIE DE PÁGINA
====================================================================== -->
<div id="invoice-footer">
    <?php do_action( 'wpo_wcpdf_before_footer', $document_type, $order ); ?>
    <div class="footer-text">
        <?php if ( method_exists( $this, 'get_footer' ) ? $this->get_footer() : true ) : ?>
            <?php $this->footer(); ?>
        <?php endif; ?>
    </div>
    <?php do_action( 'wpo_wcpdf_after_footer', $document_type, $order ); ?>
</div>

<?php do_action( 'wpo_wcpdf_after_document', $document_type, $order ); ?>

</body>
</html>
