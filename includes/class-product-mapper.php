<?php
/**
 * Mapeador de productos: SKU / Opciones → PLU de Macrobase.
 * Toma un producto WooCommerce y encuentra su PLU en la tabla de configuración.
 */

defined( 'ABSPATH' ) || exit;

class DFC_Product_Mapper {

    /**
     * Tabla de mapeo SKU → PLU (cacheada desde wp_options).
     *
     * @var array
     */
    private array $plu_map = [];

    public function __construct() {
        $this->load_plu_map();
    }

    /**
     * Cargar la tabla PLU desde wp_options.
     */
    private function load_plu_map(): void {
        $map = get_option( DFC_Settings::OPTION_PLU_MAP, [] );
        if ( empty( $map ) ) {
            $map = $this->get_default_plu_map();
        }
        $this->plu_map = $map;
    }

    /**
     * Obtener PLU para un producto WooCommerce.
     * Busca por: SKU exacto → Blend (si aplica) → Molienda (si aplica)
     *
     * @param WC_Product $product Producto de WooCommerce.
     * @param array      $item_data Array con datos del item (incluyendo opciones de molienda).
     *
     * @return int|WP_Error PLU encontrado, o WP_Error si no existe mapeo.
     */
    public function get_plu_for_product( WC_Product $product, array $item_data = [] ): int|WP_Error {
        $sku = $product->get_sku();

        if ( empty( $sku ) ) {
            return new WP_Error(
                'dfc_product_no_sku',
                sprintf(
                    __( 'El producto "%s" (ID: %d) no tiene SKU asignado.', 'dale-facturas' ),
                    $product->get_name(),
                    $product->get_id()
                )
            );
        }

        // Estrategia: buscar con prioridad
        // 1. SKU exacto
        $plu = $this->find_plu_by_sku( $sku );
        if ( $plu ) {
            return $plu;
        }

        // 2. Si el item tiene opciones de molienda, buscar por molienda
        if ( ! empty( $item_data['grind'] ) ) {
            $plu = $this->find_plu_by_grind( $item_data['grind'] );
            if ( $plu ) {
                return $plu;
            }
        }

        // 3. Si el item tiene blend, buscar por blend
        if ( ! empty( $item_data['blend'] ) ) {
            $plu = $this->find_plu_by_blend( $item_data['blend'] );
            if ( $plu ) {
                return $plu;
            }
        }

        return new WP_Error(
            'dfc_plu_not_found',
            sprintf(
                __( 'No se encontró PLU para el producto "%s" (SKU: %s). Revisa la tabla de mapeo SKU → PLU.', 'dale-facturas' ),
                $product->get_name(),
                $sku
            )
        );
    }

    /**
     * Buscar PLU por SKU exacto.
     *
     * @param string $sku SKU a buscar.
     *
     * @return int|null PLU si encontrado, null en caso contrario.
     */
    private function find_plu_by_sku( string $sku ): ?int {
        foreach ( $this->plu_map as $entry ) {
            if ( $entry['type'] === 'sku' && strtolower( $entry['sku'] ) === strtolower( $sku ) ) {
                return (int) $entry['plu'];
            }
        }
        return null;
    }

    /**
     * Buscar PLU por molienda (grind).
     *
     * @param string $grind Tipo de molienda (GRANO, MEDIO, GRUESO, FINO, etc.).
     *
     * @return int|null PLU si encontrado, null en caso contrario.
     */
    private function find_plu_by_grind( string $grind ): ?int {
        foreach ( $this->plu_map as $entry ) {
            if ( $entry['type'] === 'grind' && strtolower( $entry['sku'] ) === strtolower( $grind ) ) {
                return (int) $entry['plu'];
            }
        }
        return null;
    }

    /**
     * Buscar PLU por blend.
     *
     * @param string $blend Nombre del blend.
     *
     * @return int|null PLU si encontrado, null en caso contrario.
     */
    private function find_plu_by_blend( string $blend ): ?int {
        foreach ( $this->plu_map as $entry ) {
            if ( $entry['type'] === 'blend' && strtolower( $entry['sku'] ) === strtolower( $blend ) ) {
                return (int) $entry['plu'];
            }
        }
        return null;
    }

    /**
     * Extraer datos del item para mapeo.
     * Busca en meta del item opciones de molienda, blend, etc.
     *
     * @param WC_Order_Item_Product $item Item del pedido.
     *
     * @return array Array con claves: grind, blend, etc.
     */
    public static function extract_item_data( WC_Order_Item_Product $item ): array {
        $data = [];

        // Buscar en las metas del item (product_addons, atributos, etc.)
        $item_meta = $item->get_meta_data();
        if ( ! empty( $item_meta ) ) {
            foreach ( $item_meta as $meta ) {
                $key   = strtolower( $meta->key );
                $value = $meta->value;

                // Detectar molienda
                if ( strpos( $key, 'molienda' ) !== false || strpos( $key, 'grind' ) !== false ) {
                    $data['grind'] = (string) $value;
                }

                // Detectar blend
                if ( strpos( $key, 'blend' ) !== false ) {
                    $data['blend'] = (string) $value;
                }
            }
        }

        // También buscar en atributos del producto
        $product = $item->get_product();
        if ( $product ) {
            $attributes = $product->get_attributes();
            if ( ! empty( $attributes ) ) {
                foreach ( $attributes as $attr_name => $attr_value ) {
                    $attr_name_lower = strtolower( $attr_name );

                    if ( strpos( $attr_name_lower, 'molienda' ) !== false || strpos( $attr_name_lower, 'grind' ) !== false ) {
                        $data['grind'] = (string) $attr_value;
                    }

                    if ( strpos( $attr_name_lower, 'blend' ) !== false ) {
                        $data['blend'] = (string) $attr_value;
                    }
                }
            }
        }

        return $data;
    }

    /**
     * Mapeo PLU por defecto (migrado desde tema DaleCafeLocal-V2).
     * Este es el mismo que en DFC_Settings::get_default_plu_map().
     */
    private function get_default_plu_map(): array {
        return [
            // SKUs de productos principales
            [ 'sku' => 'Starter',     'plu' => 1,   'type' => 'sku' ],
            [ 'sku' => 'Starter-1',   'plu' => 1,   'type' => 'sku' ],
            [ 'sku' => 'Family',      'plu' => 2,   'type' => 'sku' ],
            [ 'sku' => 'SuperFamily', 'plu' => 75,  'type' => 'sku' ],
            // SKUs internos (-web)
            [ 'sku' => '19-web',      'plu' => 19,  'type' => 'sku' ],
            [ 'sku' => '18-web',      'plu' => 18,  'type' => 'sku' ],
            [ 'sku' => '20-web',      'plu' => 20,  'type' => 'sku' ],
            [ 'sku' => '35-web',      'plu' => 35,  'type' => 'sku' ],
            [ 'sku' => '23-web',      'plu' => 23,  'type' => 'sku' ],
            [ 'sku' => '25-web',      'plu' => 25,  'type' => 'sku' ],
            [ 'sku' => '24-web',      'plu' => 24,  'type' => 'sku' ],
            [ 'sku' => '58-web',      'plu' => 58,  'type' => 'sku' ],
            [ 'sku' => '21-web',      'plu' => 21,  'type' => 'sku' ],
            [ 'sku' => '22-web',      'plu' => 22,  'type' => 'sku' ],
            [ 'sku' => '114-web',     'plu' => 114, 'type' => 'sku' ],
            [ 'sku' => '115-web',     'plu' => 115, 'type' => 'sku' ],
            // Blends
            [ 'sku' => 'Master Blend',       'plu' => 21, 'type' => 'blend' ],
            [ 'sku' => "Antigua's Melt",     'plu' => 23, 'type' => 'blend' ],
            [ 'sku' => "Coban´s Moist",      'plu' => 25, 'type' => 'blend' ],
            [ 'sku' => "Huehue´s Sweet",     'plu' => 24, 'type' => 'blend' ],
            [ 'sku' => "Fraijanes' Flavory", 'plu' => 58, 'type' => 'blend' ],
            [ 'sku' => 'Master Blend Bold',  'plu' => 22, 'type' => 'blend' ],
            [ 'sku' => 'Antigua',            'plu' => 18, 'type' => 'blend' ],
            [ 'sku' => 'Cobán',              'plu' => 19, 'type' => 'blend' ],
            [ 'sku' => 'Huehuetenango',      'plu' => 20, 'type' => 'blend' ],
            [ 'sku' => 'Fraijanes',          'plu' => 35, 'type' => 'blend' ],
            // Molienda
            [ 'sku' => 'EN GRANO', 'plu' => 39, 'type' => 'grind' ],
            [ 'sku' => 'GRANO',    'plu' => 39, 'type' => 'grind' ],
            [ 'sku' => 'Grano',    'plu' => 39, 'type' => 'grind' ],
            [ 'sku' => 'MEDIO',    'plu' => 40, 'type' => 'grind' ],
            [ 'sku' => 'Medio',    'plu' => 40, 'type' => 'grind' ],
            [ 'sku' => 'GRUESO',   'plu' => 42, 'type' => 'grind' ],
            [ 'sku' => 'Grueso',   'plu' => 42, 'type' => 'grind' ],
            [ 'sku' => 'FINO',     'plu' => 41, 'type' => 'grind' ],
            [ 'sku' => 'Fino',     'plu' => 41, 'type' => 'grind' ],
            // Especiales
            [ 'sku' => 'Agregar',  'plu' => 88, 'type' => 'special' ],
            [ 'sku' => 'shipping', 'plu' => 81, 'type' => 'special' ],
        ];
    }

    /**
     * Obtener la tabla completa de mapeo PLU (para debug/admin).
     *
     * @return array
     */
    public function get_plu_map(): array {
        return $this->plu_map;
    }
}
