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
    public function get_plu_for_product( WC_Product $product, array $item_data = [] ) {
        $sku = $this->resolve_product_sku( $product );
        $product_id = $product->get_id();
        $parent_id = method_exists( $product, 'get_parent_id' ) ? (int) $product->get_parent_id() : 0;

        // Fallback temprano: permitir mapear por ID de producto cuando no hay SKU.
        $plu = $this->find_plu_by_product_ids( $product_id, $parent_id );
        if ( $plu ) {
            return $plu;
        }

        // Si el producto no tiene SKU, aún intentar resolver por opciones del item.
        if ( empty( $sku ) ) {
            if ( ! empty( $item_data['grind'] ) ) {
                $plu = $this->find_plu_by_grind( $item_data['grind'] );
                if ( $plu ) {
                    return $plu;
                }
            }

            if ( ! empty( $item_data['blend'] ) ) {
                $plu = $this->find_plu_by_blend( $item_data['blend'] );
                if ( $plu ) {
                    return $plu;
                }
            }
        }

        if ( empty( $sku ) ) {
            return new WP_Error(
                'dfc_product_no_sku',
                sprintf(
                    __( 'El producto "%s" (ID: %d) no tiene SKU asignado ni mapeo por ID.', 'dale-facturas' ),
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

        // 1.5. Si el SKU ya es numérico, usarlo directamente como PLU.
        // Esto cubre productos como SKU "60" que en la integración original
        // funcionaban implícitamente como PLU 60.
        if ( ctype_digit( trim( $sku ) ) ) {
            return (int) $sku;
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
                __( 'No se encontró PLU para el producto "%s" (SKU: %s, ID: %d). Revisa la tabla de mapeo SKU → PLU.', 'dale-facturas' ),
                $product->get_name(),
                $sku,
                $product->get_id()
            )
        );
    }

    /**
     * Resolver SKU del producto con fallback para variaciones/suscripciones.
     */
    private function resolve_product_sku( WC_Product $product ): string {
        $sku = trim( (string) $product->get_sku() );
        if ( '' !== $sku ) {
            return $sku;
        }

        // Si es variación o producto hijo, intentar con SKU del producto padre.
        $parent_id = method_exists( $product, 'get_parent_id' ) ? (int) $product->get_parent_id() : 0;
        if ( $parent_id > 0 ) {
            $parent = wc_get_product( $parent_id );
            if ( $parent instanceof WC_Product ) {
                $parent_sku = trim( (string) $parent->get_sku() );
                if ( '' !== $parent_sku ) {
                    return $parent_sku;
                }
            }
        }

        return '';
    }

    /**
     * Buscar PLU por IDs de producto (ID actual o parent ID).
     * Permite configurar en mapa entradas con sku=ID y type=sku o type=product_id.
     */
    private function find_plu_by_product_ids( int $product_id, int $parent_id = 0 ): ?int {
        $candidates = array_filter( [ (string) $product_id, $parent_id > 0 ? (string) $parent_id : '' ] );
        if ( empty( $candidates ) ) {
            return null;
        }

        foreach ( $this->plu_map as $entry ) {
            $entry_type = isset( $entry['type'] ) ? strtolower( (string) $entry['type'] ) : '';
            $entry_sku = isset( $entry['sku'] ) ? trim( (string) $entry['sku'] ) : '';

            if ( '' === $entry_sku ) {
                continue;
            }

            if ( in_array( $entry_sku, $candidates, true ) && in_array( $entry_type, [ 'sku', 'product_id' ], true ) ) {
                return (int) $entry['plu'];
            }
        }

        return null;
    }

    /**
     * Buscar PLU por SKU exacto.
     *
     * @param string $sku SKU a buscar.
     *
     * @return int|null PLU si encontrado, null en caso contrario.
     */
    private function find_plu_by_sku( string $sku ): ?int {
        $needle = $this->normalize_lookup_value( $sku );
        foreach ( $this->plu_map as $entry ) {
            if ( $entry['type'] === 'sku' && $this->normalize_lookup_value( (string) $entry['sku'] ) === $needle ) {
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
        $needle = $this->normalize_lookup_value( $grind );
        foreach ( $this->plu_map as $entry ) {
            if ( $entry['type'] === 'grind' && $this->normalize_lookup_value( (string) $entry['sku'] ) === $needle ) {
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
        $needle = $this->normalize_lookup_value( $blend );
        foreach ( $this->plu_map as $entry ) {
            if ( $entry['type'] === 'blend' && $this->normalize_lookup_value( (string) $entry['sku'] ) === $needle ) {
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

    /**
     * Resolver PLU desde un valor de opción (legacy _tmcartepo_data).
     *
     * @param string $value Valor de opción (ej. blend o molienda).
     *
     * @return int|null
     */
    public function get_plu_from_option_value( string $value ): ?int {
        $value = trim( html_entity_decode( wp_strip_all_tags( $value ), ENT_QUOTES, 'UTF-8' ) );
        if ( '' === $value ) {
            return null;
        }

        $candidates = [ $value ];

        // Compatibilidad legacy: opciones con "<br/>Notas: ...".
        if ( false !== strpos( $value, 'Notas:' ) ) {
            $parts = explode( 'Notas:', $value, 2 );
            $candidates[] = trim( $parts[0] );
        }
        if ( false !== strpos( $value, '|' ) ) {
            $parts = explode( '|', $value, 2 );
            $candidates[] = trim( $parts[0] );
        }

        foreach ( $candidates as $candidate ) {
            if ( '' === $candidate ) {
                continue;
            }

            $by_sku = $this->find_plu_by_sku( $candidate );
            if ( $by_sku ) {
                return $by_sku;
            }

            $by_grind = $this->find_plu_by_grind( $candidate );
            if ( $by_grind ) {
                return $by_grind;
            }

            $by_blend = $this->find_plu_by_blend( $candidate );
            if ( $by_blend ) {
                return $by_blend;
            }

            if ( ctype_digit( $candidate ) ) {
                return (int) $candidate;
            }
        }

        return null;
    }

    /**
     * Normaliza valores para comparación flexible en mapeos.
     */
    private function normalize_lookup_value( string $value ): string {
        $normalized = html_entity_decode( wp_strip_all_tags( $value ), ENT_QUOTES, 'UTF-8' );
        $normalized = str_replace( [ "\xE2\x80\x98", "\xE2\x80\x99", '´', '`' ], "'", $normalized );
        $normalized = preg_replace( '/\s+/', ' ', $normalized );
        return strtolower( trim( (string) $normalized ) );
    }
}
