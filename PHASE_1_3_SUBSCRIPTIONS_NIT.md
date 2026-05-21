# Fase 1.3 - Suscripciones + NIT + Migracion del Theme

Fecha: 2026-05-21
Version plugin: 1.3.0

## Objetivo

1. Cubrir el flujo historico de suscripciones donde se prepara factura antes de que exista el pedido de renovacion.
2. Asegurar resolucion de NIT compatible con metadatos historicos en pedido, suscripcion y usuario.
3. Reducir riesgo de conflicto con el theme sin eliminar archivos como primera opcion.

## Cambios implementados en el plugin

### 1) Factura anticipada para suscripciones

- Se agrego metabox en admin de suscripcion con boton Procesar Factura.
- Endpoint AJAX seguro: nonce + permisos manage_woocommerce.
- El proceso toma el pedido base de la suscripcion, certifica FEL si hace falta y deja marcada la suscripcion con:
  - _dfc_prebuilt_invoice_source_order
  - _dfc_prebuilt_invoice_ready_at
- Cuando se crea el renewal order (hook wcs_renewal_order_created), se copian metadatos FEL al nuevo pedido automaticamente.
- Adicionalmente, al completar pedido se vuelve a intentar aplicar FEL preconstruida antes de generar una nueva.

### 2) Compatibilidad de NIT ampliada

- Se amplio DFC_NIT_Handler para buscar NIT en:
  - Pedido (keys prioritarias y fallback por cualquier meta key que contenga nit)
  - Usuario/cliente (incluye billing_nit y legacy nit_number)
  - Suscripcion asociada (keys prioritarias + fallback por keys con nit)
- Se mejoro sanitizacion para soportar formatos historicos alfanumericos (por ejemplo con K), sin perder compatibilidad con CF.
- El template PDF ahora usa DFC_NIT_Handler::get_nit() para la misma logica centralizada.

### 3) Version

- Cabecera del plugin y constante DFC_VERSION actualizadas a 1.3.0.

## Analisis de conflicto con el theme (sin borrar codigo aun)

### Archivos del theme que concentran el flujo viejo

- dalecafe theme referencia/inc/custom-function.php
- dalecafe theme referencia/woocommerce/pdf/DaleCafeLocal-V2/api-facturas.php

### Riesgos si se dejan activos al mismo tiempo

- Doble procesamiento de facturas por hooks en paralelo.
- Estados mixtos entre _invoice_created_by_button (legacy) y metadatos _dfc_* del plugin.
- Comportamiento no determinista en renovaciones (segun que flujo corra primero).

### Recomendacion operativa (ultima opcion: borrar)

1. No borrar archivos del theme en esta fase.
2. Desactivar hooks legacy de facturacion en theme (primera medida segura):
   - woocommerce_order_status_completed => wc_create_automatic_invoice
   - wp_ajax_custom_generate_invoice => dc_send_renewal_invoice
   - add_meta_box create-renewal-invoices en shop_subscription
3. Mantener solo campos de checkout/perfil de usuario (billing_nit y billing_nitname) mientras se valida en produccion.
4. Tras validar 1 o 2 ciclos de renovacion exitosos, retirar codigo legacy de facturacion del theme.

## Plan de validacion en produccion

1. Suscripcion activa: en admin de suscripcion usar Procesar Factura.
2. Confirmar nota en suscripcion y metadatos _dfc_prebuilt_invoice_*.
3. Crear/esperar renewal order.
4. Verificar que el renewal order reciba serie/transaccion _dfc_* sin nueva llamada innecesaria.
5. Validar NIT en payload/request guardado y en PDF generado.

## Nota de compatibilidad

Se mantiene un puente de compatibilidad para _invoice_created_by_button cuando existe en pedido origen, para evitar corte brusco durante migracion.
