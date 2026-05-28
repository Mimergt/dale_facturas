# Fase 1.3 - Suscripciones + NIT + Migracion del Theme

Fecha: 2026-05-28
Version plugin: 1.3.20

## Objetivo

1. Cubrir el flujo historico de suscripciones donde se prepara factura antes de que exista el pedido de renovacion.
2. Asegurar resolucion de NIT compatible con metadatos historicos en pedido, suscripcion, usuario y origen ghost.
3. Reducir riesgo de conflicto con el theme desactivando hooks legacy desde el plugin.

## Cambios implementados en el plugin

### 1) Factura anticipada para suscripciones

- Se agrego metabox en admin de suscripcion con boton Procesar Factura.
- Endpoint AJAX seguro: nonce + permisos manage_woocommerce.
- El proceso usa un ghost order temporal para generar el PDF preinvoice con paridad al theme, certifica FEL si hace falta y deja marcada la suscripcion con:
  - _dfc_prebuilt_invoice_source_order
  - _dfc_prebuilt_invoice_ready_at
- El PDF interno se genera desde un ghost order temporal y se elimina al final.
- Cuando se crea el renewal order (hook wcs_renewal_order_created), se copian metadatos FEL al nuevo pedido automaticamente.
- Adicionalmente, al completar pedido se vuelve a intentar aplicar FEL preconstruida antes de generar una nueva.

### 2) Compatibilidad de NIT ampliada

- Se amplio DFC_NIT_Handler para buscar NIT en:
  - Pedido (keys prioritarias y fallback por cualquier meta key que contenga nit)
  - Usuario/cliente (incluye billing_nit y legacy nit_number)
  - Suscripcion asociada (keys prioritarias + fallback por keys con nit)
  - Origen ghost del preinvoice cuando aplica
- Se mejoro sanitizacion para soportar formatos historicos alfanumericos (por ejemplo con K), sin perder compatibilidad con CF.
- El template PDF ahora usa DFC_NIT_Handler::get_nit() para la misma logica centralizada.

### 3) Version

- Cabecera del plugin y constante DFC_VERSION actualizadas a 1.3.20.

## Analisis de conflicto con el theme (sin borrar codigo aun)

### Archivos del theme que concentran el flujo viejo

- dalecafe theme referencia/inc/custom-function.php
- dalecafe theme referencia/woocommerce/pdf/DaleCafeLocal-V2/api-facturas.php

### Riesgos si se dejan activos al mismo tiempo

- Doble procesamiento de facturas por hooks en paralelo.
- Estados mixtos entre _invoice_created_by_button (legacy) y metadatos _dfc_* del plugin.
- Comportamiento no determinista en renovaciones (segun que flujo corra primero).

### Recomendacion operativa (ultima opcion: borrar)

1. Mantener activos solo los hooks del plugin; el plugin ya desactiva los hooks legacy del theme al cargar.
2. Mantener solo campos de checkout/perfil de usuario (billing_nit y billing_nitname) mientras se valida en produccion.
3. Si se requiere limpieza final, borrar el flujo legacy del theme despues de confirmar varias renovaciones exitosas.

## Plan de validacion en produccion

1. Suscripcion activa: en admin de suscripcion usar Procesar Factura (Nuevo).
2. Confirmar nota en suscripcion y metadatos _dfc_prebuilt_invoice_*.
3. Verificar que el PDF preinvoice abra sin access key y que use attachment directo.
4. Crear/esperar renewal order.
5. Verificar que el renewal order reciba serie/transaccion _dfc_* sin nueva llamada innecesaria.
6. Validar NIT en payload/request guardado y en PDF generado.

## Nota de compatibilidad

Se mantiene un puente de compatibilidad para _invoice_created_by_button cuando existe en pedido origen, para evitar corte brusco durante migracion.
