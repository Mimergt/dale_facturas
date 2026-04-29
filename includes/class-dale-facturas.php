<?php
/**
 * Clase principal del plugin DaleCafe Facturas.
 * Actúa como loader: registra todos los hooks de las sub-clases.
 */

defined( 'ABSPATH' ) || exit;

class Dale_Facturas {

    /** @var Dale_Facturas|null Instancia singleton */
    private static $instance = null;

    /** @var DFC_Settings */
    public $settings;

    /**
     * Singleton.
     */
    public static function get_instance(): self {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {}

    /**
     * Cargar todas las clases y registrar hooks.
     * Llamado desde dfc_init() en dale-facturas.php vía plugins_loaded.
     */
    public function init(): void {
        $this->load_dependencies();
        $this->register_hooks();
    }

    /**
     * Incluir archivos de clases.
     */
    private function load_dependencies(): void {
        require_once DFC_PLUGIN_DIR . 'includes/class-settings.php';
        require_once DFC_PLUGIN_DIR . 'includes/class-macrobase-api.php';
        require_once DFC_PLUGIN_DIR . 'includes/class-product-mapper.php';
        require_once DFC_PLUGIN_DIR . 'includes/class-nit-handler.php';

        // Las siguientes clases se cargarán en fases posteriores:
        // require_once DFC_PLUGIN_DIR . 'includes/class-invoice-generator.php';
        // require_once DFC_PLUGIN_DIR . 'includes/class-order-duplicator.php';
        // require_once DFC_PLUGIN_DIR . 'includes/class-admin.php';

        $this->settings = new DFC_Settings();
    }

    /**
     * Registrar todos los hooks de WordPress/WooCommerce.
     */
    private function register_hooks(): void {
        // Settings admin
        $this->settings->register_hooks();

        // Registrar el path del template WCPDF "DaleCafe-V3"
        add_filter( 'wpo_wcpdf_template_paths', [ $this, 'register_template_path' ] );

        // i18n
        add_action( 'init', [ $this, 'load_textdomain' ] );
    }

    /**
     * Registrar la carpeta de templates del plugin en WCPDF.
     * El template aparecerá como "DaleCafe-V3" en la configuración del plugin WCPDF.
     *
     * @param array $paths Paths de templates registrados.
     * @return array
     */
    public function register_template_path( array $paths ): array {
        $paths['DaleCafe-V3'] = DFC_PLUGIN_DIR . 'templates/';
        return $paths;
    }

    /**
     * Cargar traducciones del plugin.
     */
    public function load_textdomain(): void {
        load_plugin_textdomain(
            'dale-facturas',
            false,
            dirname( plugin_basename( DFC_PLUGIN_FILE ) ) . '/languages/'
        );
    }
}
