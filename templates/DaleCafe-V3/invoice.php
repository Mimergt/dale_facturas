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

// Cuando el PDF se genera desde admin-ajax.php?action=generate_wpo_wcpdf,
// este template puede abrirse antes de que exista la FEL. En ese caso,
// intentamos crearla aqui mismo para evitar PDFs en blanco.
$wpo_action = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : '';
if (
    'invoice' === $document_type
    && 'generate_wpo_wcpdf' === $wpo_action
    && empty( $order->get_meta( '_dfc_fel_serie' ) )
    && class_exists( 'DFC_Invoice_Generator' )
) {
    $invoice_generator = new DFC_Invoice_Generator();
    $generation_result = $invoice_generator->generate_invoice( $order );

    if ( ! is_wp_error( $generation_result ) ) {
        $reloaded_order = wc_get_order( $order_id );
        if ( $reloaded_order instanceof WC_Abstract_Order ) {
            $order = $reloaded_order;
        }
    }
}

// Leer datos FEL certificados desde order meta
$fel_serie          = $order->get_meta( '_dfc_fel_serie' );
$fel_transaccion    = $order->get_meta( '_dfc_fel_transaccion' );
$fel_firma          = $order->get_meta( '_dfc_fel_firma' );
$fel_numero_acceso  = $order->get_meta( '_dfc_fel_numero_acceso' );
$fel_fecha_cert     = $order->get_meta( '_dfc_fel_fecha_certificacion' );
$fel_es_contingencia_meta = $order->get_meta( '_dfc_fel_es_contingencia' );
$fel_es_contingencia = ( '1' === (string) $fel_es_contingencia_meta );
$fel_nit_empresa    = $order->get_meta( '_dfc_fel_nit_empresa' );
$fel_nombre_empresa = $order->get_meta( '_dfc_fel_nombre_empresa' );
$fel_establecimiento = $order->get_meta( '_dfc_fel_establecimiento_nombre' );
$fel_resolucion_num = $order->get_meta( '_dfc_fel_resolucion_numero' );
$fel_resolucion_fecha = $order->get_meta( '_dfc_fel_resolucion_fecha' );

$api_response_meta = $order->get_meta( '_dfc_api_response' );
$api_response      = is_string( $api_response_meta ) ? json_decode( $api_response_meta, true ) : array();
$api_request_meta  = $order->get_meta( '_dfc_api_request' );
$api_request       = is_string( $api_request_meta ) ? json_decode( $api_request_meta, true ) : array();
$respuesta_completa = array();

if ( is_array( $api_response ) ) {
    $respuesta_completa = isset( $api_response['respuesta_completa'] ) && is_array( $api_response['respuesta_completa'] )
        ? $api_response['respuesta_completa']
        : $api_response;
}

$factura_respuesta = isset( $api_response['factura'] ) && is_array( $api_response['factura'] )
    ? $api_response['factura']
    : array();

if ( empty( $factura_respuesta ) && isset( $respuesta_completa['facturas'] ) && is_array( $respuesta_completa['facturas'] ) && ! empty( $respuesta_completa['facturas'][0] ) && is_array( $respuesta_completa['facturas'][0] ) ) {
    $factura_respuesta = $respuesta_completa['facturas'][0];
}

if ( empty( $factura_respuesta ) && is_array( $respuesta_completa ) ) {
    $factura_respuesta = $respuesta_completa;
}

if ( empty( $fel_serie ) && ! empty( $factura_respuesta['serie'] ) ) {
    $fel_serie = $factura_respuesta['serie'];
}
if ( empty( $fel_transaccion ) && ! empty( $factura_respuesta['transaccion'] ) ) {
    $fel_transaccion = $factura_respuesta['transaccion'];
}
if ( empty( $fel_firma ) && ! empty( $factura_respuesta['firmaElectronica'] ) ) {
    $fel_firma = $factura_respuesta['firmaElectronica'];
}
if ( empty( $fel_nit_empresa ) && ! empty( $factura_respuesta['empresaNit'] ) ) {
    $fel_nit_empresa = $factura_respuesta['empresaNit'];
}
if ( empty( $fel_nombre_empresa ) && ! empty( $factura_respuesta['empresaNombre'] ) ) {
    $fel_nombre_empresa = $factura_respuesta['empresaNombre'];
}
if ( empty( $fel_establecimiento ) && ! empty( $factura_respuesta['establecimientoNombre'] ) ) {
    $fel_establecimiento = $factura_respuesta['establecimientoNombre'];
}
if ( empty( $fel_fecha_cert ) && ! empty( $factura_respuesta['fechaHoraCertificacion'] ) ) {
    $fel_fecha_cert = $factura_respuesta['fechaHoraCertificacion'];
}
if ( empty( $fel_numero_acceso ) && ! empty( $factura_respuesta['faceId'] ) ) {
    $fel_numero_acceso = $factura_respuesta['faceId'];
}

$establecimiento_direccion = ! empty( $factura_respuesta['establecimientoDireccion'] )
    ? $factura_respuesta['establecimientoDireccion']
    : '';
$certificador_nombre = ! empty( $factura_respuesta['gfaceEmpresa'] )
    ? $factura_respuesta['gfaceEmpresa']
    : $order->get_meta( '_dfc_fel_gface_empresa' );
$certificador_nit = ! empty( $factura_respuesta['gfaceNit'] )
    ? $factura_respuesta['gfaceNit']
    : $order->get_meta( '_dfc_fel_gface_nit' );

$fel_certificado = ! empty( $fel_serie ) && ! empty( $fel_transaccion );

// Datos del cliente
$billing_nit      = $order->get_meta( '_billing_nit' ) ?: $order->get_meta( 'billing_nit' ) ?: 'CF';
$billing_nitname  = $order->get_meta( '_billing_nitname' ) ?: $order->get_meta( 'billing_nitname' ) ?: '';
$billing_name     = trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );
$billing_email    = $order->get_billing_email();
$billing_phone    = $order->get_billing_phone();
$shipping_address = trim( $order->get_shipping_address_1() . ' ' . $order->get_shipping_address_2() . ' ' . $order->get_shipping_city() . ', ' . $order->get_shipping_state() );
$billing_address  = $order->get_billing_address_1();
$client_address   = ! empty( $shipping_address ) ? $shipping_address : $billing_address;

$cliente_nombre_api = ! empty( $factura_respuesta['facturarA'] )
    ? trim( (string) $factura_respuesta['facturarA'] )
    : '';
$cliente_nombre_request = is_array( $api_request ) && ! empty( $api_request['clienteNombre'] )
    ? trim( (string) $api_request['clienteNombre'] )
    : '';

if ( $cliente_nombre_api && preg_match( '/consumidor\s+final/i', $cliente_nombre_api ) ) {
    $cliente_nombre_api = '';
}
if ( $cliente_nombre_request && preg_match( '/consumidor\s+final/i', $cliente_nombre_request ) ) {
    $cliente_nombre_request = '';
}

$cliente_nombre_factura = $cliente_nombre_api ? $cliente_nombre_api : ( $cliente_nombre_request ? $cliente_nombre_request : $billing_name );
$cliente_direccion_factura = $client_address;

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

$q_money = static function( $amount ) {
    return 'Q.' . number_format( (float) $amount, 2, '.', ',' );
};

$empresa_nombre_opt  = get_option( 'dalecafe_empresa_nombre', '' );
$empresa_nit_opt     = get_option( 'dalecafe_empresa_nit', '' );
$empresa_ciudad_opt  = get_option( 'dalecafe_empresa_ciudad', '' );
$empresa_depto_opt   = get_option( 'dalecafe_empresa_departamento', '' );
$empresa_nombre      = ! empty( $fel_nombre_empresa ) ? $fel_nombre_empresa : ( ! empty( $empresa_nombre_opt ) ? $empresa_nombre_opt : get_bloginfo( 'name' ) );
$empresa_nit         = ! empty( $fel_nit_empresa ) ? $fel_nit_empresa : ( ! empty( $empresa_nit_opt ) ? $empresa_nit_opt : 'N/A' );

ob_start();
$this->shop_address();
$shop_address = trim( wp_strip_all_tags( ob_get_clean() ) );

if ( empty( $shop_address ) ) {
    $shop_address = trim( $empresa_ciudad_opt . ( ! empty( $empresa_depto_opt ) ? ', ' . $empresa_depto_opt : '' ) );
}

$header_fallback_logo = 'https://dalecafe.com/wp-content/uploads/2019/08/Asset-2.png';

$factura_referencia  = ! empty( $factura_respuesta['serieInterna'] ) || ! empty( $factura_respuesta['transaccionInterna'] )
    ? trim( ( $factura_respuesta['serieInterna'] ?? '' ) . ' ' . ( $factura_respuesta['transaccionInterna'] ?? '' ) )
    : $order_number;
$fecha_emision       = ! empty( $factura_respuesta['fechaEmision'] )
    ? $factura_respuesta['fechaEmision']
    : ( $order->get_date_created() ? $order->get_date_created()->date_i18n( 'd/m/Y H:i' ) : '' );
$establecimiento_info = array_filter( array(
    $fel_establecimiento ? $fel_establecimiento : $empresa_nombre,
    $establecimiento_direccion ? __( 'Dirección:', 'dale-facturas' ) . ' ' . $establecimiento_direccion : __( 'Dirección:', 'dale-facturas' ) . ' ' . $shop_address,
    __( 'NIT:', 'dale-facturas' ) . ' ' . $empresa_nit,
) );
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
<div id="invoice-header" style="background-image: url('<?php echo esc_url( $header_fallback_logo ); ?>');"></div>

<div class="panel-box dte-box">
    <div class="panel-title"><?php esc_html_e( 'DOCUMENTO TRIBUTARIO ELECTRONICO', 'dale-facturas' ); ?></div>
    <div class="panel-line">
        <?php esc_html_e( 'Factura electrónica en línea, Serie:', 'dale-facturas' ); ?>
        <strong><?php echo esc_html( $fel_serie ? $fel_serie : 'N/A' ); ?></strong>
        <?php esc_html_e( 'Número:', 'dale-facturas' ); ?>
        <strong><?php echo esc_html( $fel_transaccion ? $fel_transaccion : 'N/A' ); ?></strong>
    </div>
    <div class="panel-line">
        <?php esc_html_e( 'Referencia:', 'dale-facturas' ); ?>
        <strong><?php echo esc_html( $factura_referencia ); ?></strong>
    </div>
    <div class="panel-line">
        <?php esc_html_e( 'Fecha y hora de emisión:', 'dale-facturas' ); ?>
        <strong><?php echo esc_html( $fecha_emision ); ?></strong>
    </div>
</div>

<!-- =====================================================================
     CUERPO
====================================================================== -->
<div id="invoice-body">

    <!-- Datos del cliente -->
    <div id="billing-section" class="panel-box">
        <div class="panel-title"><?php esc_html_e( 'DATOS DEL CLIENTE:', 'dale-facturas' ); ?></div>
        <table class="billing-table">
            <tr>
                <td>
                    <?php do_action( 'wpo_wcpdf_before_billing_address', $document_type, $order ); ?>
                    <p class="info-line"><span class="first-text"><?php esc_html_e( 'Nombre:', 'dale-facturas' ); ?></span> <span class="second-text"><?php echo esc_html( $cliente_nombre_factura ); ?></span></p>
                    <p class="info-line"><span class="first-text"><?php esc_html_e( 'Dirección:', 'dale-facturas' ); ?></span> <span class="second-text"><?php echo esc_html( $cliente_direccion_factura ); ?></span></p>
                    <p class="info-line"><span class="first-text"><?php esc_html_e( 'NIT:', 'dale-facturas' ); ?></span> <span class="second-text"><?php echo esc_html( $billing_nit ); ?></span></p>
                    <?php do_action( 'wpo_wcpdf_after_billing_address', $document_type, $order ); ?>
                </td>
                <td>
                    <?php if ( $billing_email ) : ?>
                        <p class="info-line"><span class="first-text"><?php esc_html_e( 'Email:', 'dale-facturas' ); ?></span> <span class="second-text"><?php echo esc_html( $billing_email ); ?></span></p>
                    <?php endif; ?>
                    <?php if ( $billing_phone ) : ?>
                        <p class="info-line"><span class="first-text"><?php esc_html_e( 'Teléfono:', 'dale-facturas' ); ?></span> <span class="second-text"><?php echo esc_html( $billing_phone ); ?></span></p>
                    <?php endif; ?>
                    <p class="info-line"><span class="first-text"><?php esc_html_e( 'Pago:', 'dale-facturas' ); ?></span> <span class="second-text"><?php $this->payment_method(); ?></span></p>
                </td>
            </tr>
        </table>
    </div>

    <!-- ===================================================================
         TABLA DE PRODUCTOS
    ==================================================================== -->
    <?php do_action( 'wpo_wcpdf_before_order_details', $document_type, $order ); ?>
    <div class="panel-box">
        <div class="panel-title"><?php esc_html_e( 'DETALLE DE COMPRA:', 'dale-facturas' ); ?></div>
    <div class="section-divider"></div>
    <?php foreach ( $order_items as $item_id => $item ) : ?>
        <div class="detail-row <?php echo esc_attr( apply_filters( 'wpo_wcpdf_item_row_class', $item_id, $document_type, $order, $item_id ) ); ?>">
            <span class="first-text">
                <?php echo esc_html( isset( $item['name'] ) ? wp_strip_all_tags( $item['name'] ) : '' ); ?>
                <span class="qty-text">(X <?php echo esc_html( isset( $item['quantity'] ) ? $item['quantity'] : '' ); ?>)</span>
            </span>
            <span class="second-text product-cost"><?php echo wp_kses_post( isset( $item['line_total'] ) ? $item['line_total'] : ( isset( $item['total'] ) ? $item['total'] : '' ) ); ?></span>
        </div>
        <?php if ( isset( $item['meta'] ) && ! empty( trim( wp_strip_all_tags( $item['meta'] ) ) ) ) : ?>
            <div class="detail-meta"><?php echo wp_kses_post( $item['meta'] ); ?></div>
        <?php endif; ?>
    <?php endforeach; ?>

    <div class="section-divider section-divider-large"></div>

    <p class="total-line"><span class="first-text"><?php esc_html_e( 'Sub total:', 'dale-facturas' ); ?></span> <span class="second-text"><?php echo esc_html( $q_money( $order->get_subtotal() ) ); ?></span></p>
    <p class="total-line"><span class="first-text"><?php esc_html_e( 'Envío:', 'dale-facturas' ); ?></span> <span class="second-text"><?php echo esc_html( $order->get_shipping_method() ); ?> <?php echo esc_html( $q_money( $order->get_shipping_total() ) ); ?></span></p>
    <p class="total-line"><span class="first-text"><?php esc_html_e( 'Descuento:', 'dale-facturas' ); ?></span> <span class="second-text product-cost"><?php echo esc_html( $q_money( $order->get_discount_total() ) ); ?></span></p>

    <div class="section-divider section-divider-large"></div>
    <p class="grand-total"><span class="total-text"><?php esc_html_e( 'Total:', 'dale-facturas' ); ?></span> <span class="second-text product-cost"><?php echo esc_html( $q_money( $order->get_total() ) ); ?></span></p>
    </div>
    <?php do_action( 'wpo_wcpdf_after_order_details', $document_type, $order ); ?>

    <!-- ===================================================================
         DATOS FEL (Certificación electrónica)
    ==================================================================== -->
    <div id="fel-section" class="panel-box <?php echo $fel_es_contingencia ? 'contingencia' : 'principal'; ?>">
        <div class="panel-title"><?php esc_html_e( 'ESTABLECIMIENTO:', 'dale-facturas' ); ?></div>
        <?php if ( $fel_es_contingencia ) : ?>
            <div class="contingencia-banner">
                <strong><?php esc_html_e( '⚠ FACTURA DE CONTINGENCIA', 'dale-facturas' ); ?></strong>
            </div>
        <?php endif; ?>

        <div class="section-divider"></div>

        <p class="info-line est-line">
            <span class="first-text"><?php echo esc_html( implode( ' | ', $establecimiento_info ) ); ?></span>
        </p>

        <p class="info-line est-line"><span class="first-text"><?php esc_html_e( 'Autorización FEL:', 'dale-facturas' ); ?></span> <span class="second-text"><?php echo esc_html( $fel_firma ? $fel_firma : 'N/A' ); ?></span></p>
        <p class="info-line est-line"><span class="first-text"><?php esc_html_e( 'Certificador:', 'dale-facturas' ); ?></span> <span class="second-text"><?php echo esc_html( $certificador_nombre ? $certificador_nombre : 'N/A' ); ?> | <?php esc_html_e( 'NIT:', 'dale-facturas' ); ?> <?php echo esc_html( $certificador_nit ? $certificador_nit : 'N/A' ); ?></span></p>

        <?php if ( $fel_firma ) : ?>
            <div class="firma-section">
                <strong><?php esc_html_e( 'Firma electrónica:', 'dale-facturas' ); ?></strong>
                <div class="firma-text"><?php echo esc_html( $fel_firma ); ?></div>
            </div>
        <?php endif; ?>
    </div>

    <?php if ( ! $fel_certificado ) : ?>
        <div id="fel-section-pending" class="pending">
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
    <p class="regimen-isr"><strong><?php esc_html_e( 'Régimen ISR: SUJETO A PAGOS TRIMESTRALES', 'dale-facturas' ); ?></strong></p>
</div>

<?php do_action( 'wpo_wcpdf_after_document', $document_type, $order ); ?>

</body>
</html>
