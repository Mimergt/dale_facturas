<?php
/**
 * Cliente para el API de Macrobase.
 * Encapsula las llamadas, formatea payloads, maneja respuestas y logging.
 */

defined( 'ABSPATH' ) || exit;

class DFC_Macrobase_API {

    /**
     * Source name for WooCommerce logs.
     */
    private const LOG_SOURCE = 'dale-facturas';

    /**
     * URL del API de Macrobase.
     */
    private string $api_url;

    /**
     * Usuario de autenticación.
     */
    private string $usuario;

    /**
     * Contraseña de autenticación.
     */
    private string $clave;

    /**
     * Modo de operación: 'production' o 'test'.
     */
    private string $mode;

    /**
     * Constructor.
     *
     * @param string $api_url URL del API.
     * @param string $usuario Usuario.
     * @param string $clave   Contraseña.
     * @param string $mode    'production' o 'test'.
     */
    public function __construct( string $api_url, string $usuario, string $clave, string $mode = 'production' ) {
        $this->api_url = $api_url;
        $this->usuario = $usuario;
        $this->clave   = $clave;
        $this->mode    = $mode;
    }

    /**
     * Crear una instancia a partir de opciones guardadas en wp_options.
     */
    public static function from_options(): self {
        $url     = get_option( DFC_Settings::OPTION_API_URL, '' );
        $usuario = get_option( DFC_Settings::OPTION_API_USUARIO, '' );
        $clave   = get_option( DFC_Settings::OPTION_API_CLAVE, '' );
        $mode    = get_option( DFC_Settings::OPTION_API_MODE, 'production' );

        return new self( $url, $usuario, $clave, $mode );
    }

    /**
     * Enviar una factura a Macrobase.
     *
     * @param array $invoice_data Datos de la factura (estructura del payload).
     *
     * @return array|WP_Error Array con respuesta (serie, transaccion, firmaElectronica) o WP_Error.
     */
    public function enviar_factura( array $invoice_data ) {
        // Validar que tenemos credenciales
        if ( empty( $this->api_url ) || empty( $this->usuario ) || empty( $this->clave ) ) {
            return new WP_Error(
                'dfc_api_incomplete_credentials',
                __( 'Credenciales del API incompletas. Revisa la configuración del plugin.', 'dale-facturas' )
            );
        }

        // Construir payload
        $payload = [
            'usuario' => $this->usuario,
            'clave'   => $this->clave,
            'ordenes' => [ $invoice_data ],
        ];

        // Log de request (si debug activo)
        if ( get_option( DFC_Settings::OPTION_DEBUG_MODE ) ) {
            $this->log_debug( 'REQUEST', $payload );
        }

        // Enviar petición
        $response = wp_remote_post( $this->api_url, [
            'timeout' => 30,
            'headers' => [ 'Content-Type' => 'application/json' ],
            'body'    => wp_json_encode( $payload ),
        ] );

        if ( is_wp_error( $response ) ) {
            $this->log_debug( 'ERROR', $response->get_error_message() );
            return $response;
        }

        $http_code = wp_remote_retrieve_response_code( $response );
        $body      = wp_remote_retrieve_body( $response );
        $json      = json_decode( $body, true );

        // Log de response (si debug activo)
        if ( get_option( DFC_Settings::OPTION_DEBUG_MODE ) ) {
            $this->log_debug( 'RESPONSE', [
                'http_code' => $http_code,
                'body'      => $json,
            ] );
        }

        // Validar respuesta
        if ( ! is_array( $json ) ) {
            return new WP_Error(
                'dfc_api_invalid_response',
                sprintf(
                    __( 'El API respondió con HTTP %d pero no es JSON válido.', 'dale-facturas' ),
                    $http_code
                )
            );
        }

        // HTTP error
        if ( $http_code >= 400 ) {
            $error_msg = $json['mensaje'] ?? $json['message'] ?? 'Error del API (HTTP ' . $http_code . ')';
            return new WP_Error( 'dfc_api_http_error', $error_msg );
        }

        // Validar estructura de respuesta
        // En modo principal: debe tener serie, transaccion, firmaElectronica
        // En modo contingencia: serie, transaccion, sin firma
        if ( ! isset( $json['serie'] ) || ! isset( $json['transaccion'] ) ) {
            return new WP_Error(
                'dfc_api_invalid_structure',
                __( 'La respuesta del API no tiene la estructura esperada (falta serie o transaccion).', 'dale-facturas' )
            );
        }

        return [
            'serie'               => $json['serie'],
            'transaccion'         => $json['transaccion'],
            'firmaElectronica'    => $json['firmaElectronica'] ?? '',
            'esContingencia'      => ! isset( $json['firmaElectronica'] ) || empty( $json['firmaElectronica'] ),
            'codigoFel'           => $json['codigoFel'] ?? '',
            'numeroFel'           => $json['numeroFel'] ?? '',
            'respuesta_completa'  => $json,
        ];
    }

    /**
     * Probar conexión (envía un payload vacío).
     *
     * @return bool|WP_Error true si conexión exitosa, WP_Error en caso contrario.
     */
    public function test_connection() {
        $payload = [
            'usuario' => $this->usuario,
            'clave'   => $this->clave,
            'ordenes' => [],
        ];

        $response = wp_remote_post( $this->api_url, [
            'timeout' => 15,
            'headers' => [ 'Content-Type' => 'application/json' ],
            'body'    => wp_json_encode( $payload ),
        ] );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $http_code = wp_remote_retrieve_response_code( $response );

        if ( $http_code >= 400 ) {
            $body = wp_remote_retrieve_body( $response );
            $json = json_decode( $body, true );
            $msg  = $json['mensaje'] ?? $json['message'] ?? 'Error HTTP ' . $http_code;
            return new WP_Error( 'dfc_api_connection_failed', $msg );
        }

        return true;
    }

    /**
     * Escribir en debug.log si debug mode está activo.
     *
     * @param string $type   Tipo de log (REQUEST, RESPONSE, ERROR).
     * @param mixed  $data   Datos a loguear.
     */
    private function log_debug( string $type, $data ): void {
        if ( ! get_option( DFC_Settings::OPTION_DEBUG_MODE ) ) {
            return;
        }

        if ( ! function_exists( 'wc_get_logger' ) ) {
            return;
        }

        $logger  = wc_get_logger();
        $context = [ 'source' => self::LOG_SOURCE ];
        $message = sprintf(
            '[%s] %s',
            $type,
            wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE )
        );

        switch ( strtoupper( $type ) ) {
            case 'ERROR':
                $logger->error( $message, $context );
                break;
            case 'REQUEST':
            case 'RESPONSE':
            default:
                $logger->debug( $message, $context );
                break;
        }
    }

    /**
     * Getter: API URL.
     */
    public function get_api_url(): string {
        return $this->api_url;
    }

    /**
     * Getter: Usuario.
     */
    public function get_usuario(): string {
        return $this->usuario;
    }

    /**
     * Getter: Modo.
     */
    public function get_mode(): string {
        return $this->mode;
    }
}
