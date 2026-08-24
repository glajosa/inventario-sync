# API privada de recomendaciones de inventario

**Fecha:** 2026-08-24

**Estado:** diseño listo para revisión; no implementado ni desplegado

## Contexto

El vendedor virtual necesita pasar de respuestas generales a recomendaciones comerciales exactas. Cuando el prospecto ya llega asociado a un proyecto en Bitrix, el bot debe profundizar primero en ese proyecto y no abrir innecesariamente un abanico de alternativas. Después de conocer restricciones como presupuesto, tipo de activo, metraje o plazo de entrega, debe poder presentar hasta tres unidades reales y disponibles.

Inventario Sync ya concentra las piezas necesarias:

- `selector_cache.json` mantiene el catálogo de unidades y se actualiza por eventos, con un calentamiento periódico como respaldo.
- Las matrices y listas de precios contienen la lógica comercial vigente para siete proyectos.
- La aplicación de “Precios del proyecto” ya calcula precios y planes estándar sin consultar Bitrix por cada pantalla.
- La API privada de resultados de llamadas ya define el patrón de autenticación HMAC que se reutilizará.

La API nueva será de solo lectura. No cambiará el campo Proyecto, no reservará unidades, no cotizará formalmente y no escribirá en Bitrix.

## Objetivos

1. Entregar hasta tres unidades exactas y actualmente disponibles que encajen con el prospecto.
2. Priorizar el proyecto de entrada informado por Bitrix.
3. Permitir búsqueda cruzada únicamente cuando el proyecto de entrada no encaje o el prospecto abra explícitamente la búsqueda.
4. Devolver código, metraje, precio, entrega y plan estándar cuando esos datos estén respaldados por la fuente comercial vigente.
5. Fallar de forma segura: si no se puede probar disponibilidad o vigencia, no se ofrece la unidad.
6. Evitar llamadas repetidas a Bitrix durante cada conversación de WhatsApp.

## Fuera de alcance

- Crear una cotización formal o un PDF.
- Negociar descuentos o condiciones especiales.
- Reservar, bloquear o modificar una unidad.
- Actualizar campos del deal, incluido Proyecto.
- Exponer identificadores internos de deals, contactos o unidades.
- Consultar o mostrar unidades reservadas, firmadas, vendidas, bloqueadas o perdidas.
- Usar la app visual “Ver disponibilidad” como fuente de verdad.

La cotización formal será una fase posterior, una vez que el prospecto indique cómo quiere pagar y exista aprobación del vendedor.

## Fuentes de verdad y cobertura

### Disponibilidad

La fuente operativa es `/data/selector_cache.json`. Una unidad solo es ofrecible cuando se cumplen simultáneamente estas condiciones:

- su etapa normalizada es `DISPONIBLE`;
- no tiene un `dealId` asociado;
- tiene código, metraje y precio válidos;
- pertenece a una familia lanzada y soportada por la matriz/lista vigente;
- no está excluida por reglas del proyecto.

Si la etapa no puede resolverse, el catálogo falta, el caché supera 3.600 segundos de antigüedad o cualquier dato indispensable es ambiguo, la API no devuelve recomendaciones. Ese límite podrá reducirse mediante `INVENTORY_RECOMMENDATIONS_MAX_CACHE_AGE_SECONDS`, pero nunca ampliarse por encima de una hora. La petición normal nunca releerá Bitrix como atajo, porque eso puede elevar el consumo del portal y hacer que la disponibilidad dependa de una llamada lenta o fallida.

### Precio y condiciones comerciales

Las matrices de `matrices/proyecto_*.json` y el motor de listas son la fuente vigente para precio, familia, entrega y plan estándar. La API leerá el PVP vivo del catálogo y usará las reglas de matriz/lista para enriquecer y validar la opción.

La cobertura exacta inicial se limita a:

| `categoryId` | Proyecto |
| ---: | --- |
| 33 | Noral Plaza |
| 39 | Noral Apartments |
| 47 | Galero Torre C |
| 49 | Sun Bay Engabao |
| 51 | Galero Torre D |
| 53 | Galero Suites |
| 55 | Galero Casas |

Otros proyectos que aparezcan en el catálogo responderán `unsupported_project`; nunca se inferirá un precio con una regla ajena.

### Regla especial de Noral Plaza

Noral Plaza es un solo proyecto con varias familias comerciales: locales, oficinas, consultorios y monoambientes/departamentos según edificio y piso. La API conserva esa estructura y filtra por familia sin presentar “Noral Plaza Locales” y “Noral Plaza Suites” como proyectos separados.

La entrega vigente de Noral Plaza será `2031-02`, tomada de la matriz/lista comercial actual. Existe un valor anterior `2031-04` en `cotizarlib.php`; para esta API manda la matriz vigente. La discrepancia se corregirá antes de conectar una futura cotización formal.

### Planos

`noral-historias` puede aportar enlaces o imágenes de planos únicamente para Noral Plaza y Noral Apartments. Es un complemento visual opcional, no una autoridad de disponibilidad, precio ni cobertura general.

## Arquitectura elegida

Se agregará un endpoint privado dentro del servicio Inventario Sync:

`POST /api/private/inventario/v1/recomendaciones`

Flujo:

1. El bot reúne restricciones de la conversación y el proyecto de entrada de Bitrix.
2. Envía una petición estructurada y firmada a Inventario Sync.
3. Inventario Sync valida la firma, el cuerpo y la frescura del catálogo.
4. Normaliza el proyecto y las restricciones.
5. Filtra primero por disponibilidad estricta y luego por encaje comercial.
6. Ordena los candidatos y devuelve como máximo tres.
7. El bot explica esas opciones con lenguaje natural, sin cambiar cifras ni prometer una reserva.

### Alternativas descartadas

- **Consultar Bitrix en cada conversación:** aumenta latencia y presión sobre límites; replica trabajo que el caché ya resuelve.
- **Leer o raspar las pantallas de Bitrix:** la interfaz visual no es un contrato de datos estable y podría exponer tokens de sesión.
- **Usar `noral-historias` como inventario:** solo cubre dos proyectos y su función principal es dibujar planos.
- **Guardar una copia independiente en el bot:** puede quedar desactualizada y permitir que dos sistemas discrepen sobre disponibilidad.

## Seguridad y contrato HTTP

La API reutilizará `lib/private-api-auth.php`:

- cabecera `X-Galjosa-Timestamp` con época Unix;
- cabecera `X-Galjosa-Signature` con HMAC-SHA256 de `<timestamp>\n<body>`;
- ventana máxima de cinco minutos;
- comparación de firma en tiempo constante;
- secreto `INVENTARIO_SYNC_SHARED_SECRET`, con mínimo de 32 caracteres;
- `Content-Type: application/json; charset=utf-8`;
- límite de cuerpo: 32 KiB;
- respuesta `Cache-Control: no-store` y `X-Content-Type-Options: nosniff`;
- sin CORS: el consumidor será el servidor del bot, no el navegador;
- solo método `POST`.

El cuerpo y los logs nunca incluirán texto completo de la conversación, nombre, teléfono, contacto, deal ni datos personales. Para trazabilidad se acepta un `requestId` UUID generado por el bot.

## Petición

```json
{
  "requestId": "0198e9d3-86e7-7e50-8bd4-e41c68a129b4",
  "originProject": {
    "categoryId": 33,
    "name": "Noral Plaza"
  },
  "searchScope": "origin_project",
  "criteria": {
    "assetTypes": ["oficina"],
    "zone": null,
    "budget": {
      "totalMax": 145000,
      "initialMax": 15000,
      "monthlyMax": 700
    },
    "areaM2": {
      "min": 35,
      "max": null
    },
    "bedrooms": null,
    "delivery": {
      "latest": "2031-06",
      "acceptsImmediate": true
    }
  },
  "limit": 3
}
```

### Validaciones

- `requestId` es obligatorio y debe ser UUID.
- `originProject.categoryId` es la clave principal. El nombre sirve solo para diagnóstico y debe concordar si se envía.
- `searchScope` acepta `origin_project` o `all_supported`.
- `limit` acepta de 1 a 3 y por defecto vale 3.
- Todos los valores monetarios son USD, positivos y con máximo de dos decimales.
- Las áreas y dormitorios son positivos dentro de límites razonables.
- Las fechas de entrega usan `YYYY-MM`.
- Los campos desconocidos producen `invalid_request`; no se ignoran silenciosamente.

El bot omitirá un criterio cuando el prospecto aún no lo haya dado. Omitir no significa inventar cero ni imponer un valor por defecto.

## Normalización de entrega

Entrega y plazo de financiamiento son conceptos separados:

```json
{
  "delivery": {
    "mode": "fixed",
    "date": "2031-02",
    "minMonths": null,
    "maxMonths": null,
    "label": "Entrega estimada en febrero de 2031"
  }
}
```

Modos:

- `immediate`: unidad/proyecto de entrega inmediata; `date`, `minMonths` y `maxMonths` son `null`.
- `fixed`: fecha calendario tomada de la matriz vigente; solo `date` tiene valor.
- `flexible`: rango contractual en meses desde la firma; solo `minMonths` y `maxMonths` tienen valor.

Los meses de cuotas se devuelven aparte en `standardPayment.termMonths`. Nunca se presentarán los meses de financiamiento como fecha de entrega.

## Respuesta exitosa

```json
{
  "requestId": "0198e9d3-86e7-7e50-8bd4-e41c68a129b4",
  "status": "ok",
  "source": {
    "catalogGeneratedAt": "2026-08-24T20:15:00Z",
    "catalogAgeSeconds": 42,
    "priceVersion": "2026-08-18"
  },
  "search": {
    "requestedScope": "origin_project",
    "appliedScope": "origin_project",
    "originProjectSupported": true,
    "eligibleCount": 7
  },
  "recommendations": [
    {
      "code": "A-2-4",
      "project": "Noral Plaza",
      "categoryId": 33,
      "assetType": "oficina",
      "family": "oficinas",
      "areaM2": 39.5,
      "price": {
        "amount": 141200,
        "currency": "USD"
      },
      "delivery": {
        "mode": "fixed",
        "date": "2031-02",
        "minMonths": null,
        "maxMonths": null,
        "label": "Entrega estimada en febrero de 2031"
      },
      "standardPayment": {
        "available": true,
        "separation": 1000,
        "signing": 13120,
        "monthly": 523.0,
        "monthlyCount": 54,
        "extraordinary": 2824,
        "extraordinaryCount": 5,
        "currency": "USD",
        "disclaimer": "Plan referencial sujeto a validación comercial"
      },
      "fit": {
        "score": 0.91,
        "reasons": [
          "Corresponde al proyecto por el que llegó",
          "Está dentro del presupuesto total",
          "La mensualidad estándar está dentro del rango indicado"
        ],
        "tradeoffs": []
      },
      "plan": null
    }
  ]
}
```

El ejemplo ilustra el contrato, no valores comerciales que deban copiarse a producción. Las cifras reales se calculan al atender la petición.

### Reglas de salida

- `recommendations` contiene entre cero y tres elementos.
- El código comercial es el único identificador de unidad expuesto.
- Los montos salen como números, sin texto ni separadores de miles.
- `standardPayment.available=false` cuando la lista vigente no permite calcular el plan con certeza; en ese caso sus montos son `null`.
- `plan` solo se incluye si existe un plano permitido y estable; nunca bloquea la recomendación.
- La respuesta no expone `dealId`, el ID interno del SPA, contacto, vendedor ni datos de una unidad ocupada.

## Selección y ordenamiento

El filtrado ocurre antes del puntaje:

1. Proyecto/familia soportados.
2. Etapa `DISPONIBLE` y ausencia de `dealId`.
3. Familia lanzada, no exenta y con precio/metraje válidos.
4. Coincidencia con filtros estrictos del prospecto.

Después se ordena con estas prioridades, en este orden:

1. Proyecto de entrada.
2. Tipo de activo solicitado.
3. No exceder presupuesto total, entrada y mensualidad cuando fueron indicados.
4. Cumplir entrega deseada.
5. Cercanía al metraje y dormitorios solicitados.
6. Menor distancia respecto al presupuesto objetivo, sin asumir que lo más caro es mejor.
7. Código comercial como desempate estable.

La API evita devolver tres variantes casi idénticas cuando existen opciones útiles distintas. La diversidad nunca puede desplazar una opción con mejor encaje ni introducir otro proyecto fuera del alcance solicitado.

`searchScope=origin_project` no amplía la búsqueda por su cuenta. Si no hay coincidencias devuelve una respuesta vacía con razones estructuradas; el bot puede preguntar al prospecto si desea considerar otros proyectos. Solo una nueva petición con `all_supported` habilita la búsqueda cruzada.

## Sin resultados y errores

### Sin coincidencias válidas

HTTP `200`:

```json
{
  "requestId": "0198e9d3-86e7-7e50-8bd4-e41c68a129b4",
  "status": "no_matches",
  "reasons": ["monthly_budget_below_available_options"],
  "recommendations": []
}
```

Las razones pertenecen a una lista cerrada y sirven para que el bot formule una pregunta útil sin revelar inventario ocupado.

### Proyecto no soportado

HTTP `422`, error `unsupported_project`. No se intenta extrapolar una matriz.

### Catálogo ausente, inválido o viejo

HTTP `503`, error `inventory_unavailable`, con `Retry-After`. No se devuelven datos parciales ni una copia potencialmente obsoleta.

### Otros errores

- `400 invalid_request`: cuerpo, campos o tipos inválidos.
- `401 unauthorized`: firma ausente, inválida o vencida.
- `413 request_too_large`: cuerpo superior al límite.
- `422 unsupported_criteria`: criterio reconocido pero no evaluable con la cobertura actual.
- `500 internal_error`: fallo no clasificado; detalle solo en logs internos.

Los mensajes de error públicos no incluyen rutas, secretos, stack traces ni contenido del catálogo.

## Integración con el bot

La herramienta del bot recibirá datos estructurados, no una frase abierta. El modelo no tendrá acceso directo al secreto ni construirá firmas; lo hará el servidor del bot.

Secuencia conversacional:

1. Leer proyecto de entrada y contexto previo de la automatización.
2. No volver a preguntar lo que ya consta en Bitrix o en la conversación.
3. Sondear solo las restricciones necesarias.
4. Consultar primero con `origin_project`.
5. Presentar hasta tres opciones exactas y explicar por qué encajan.
6. Si no hay opciones, explicar la restricción relevante y pedir permiso antes de abrir la búsqueda.
7. Aclarar que la disponibilidad puede cambiar y que una reserva requiere confirmación humana.

El system prompt establecerá que el modelo no puede modificar códigos, precios o condiciones devueltas por la herramienta, completar datos faltantes ni prometer que una unidad quedó separada.

## Observabilidad

Por petición se registrará:

- `requestId`;
- fecha y duración;
- proyecto y alcance solicitados;
- cantidad de candidatas elegibles y devueltas;
- edad del catálogo;
- versión de precio;
- código de resultado o error.

No se registrarán teléfonos, nombres, mensajes, presupuestos textuales ni secretos. El futuro panel podrá mostrar salud de la API, edad del catálogo, último resultado y tasa de errores sin acceder a datos personales.

## Pruebas

### Unitarias

- validación estricta de método, tipo de contenido, tamaño y JSON;
- HMAC correcto, incorrecto, ausente y vencido;
- proyecto soportado/no soportado;
- disponibilidad exige simultáneamente etapa y ausencia de deal;
- rechazo de catálogo ausente, corrupto o viejo;
- normalización de entrega inmediata, fija y flexible;
- separación de entrega y meses de pago;
- filtros de presupuesto, metraje, dormitorios y tipo;
- prioridad del proyecto de entrada;
- máximo tres resultados y desempate estable;
- datos internos nunca aparecen en la respuesta;
- Noral Plaza conserva un solo proyecto y sus familias correctas;
- la entrega de Noral Plaza sale de la matriz vigente (`2031-02`).

### Integración

- fixture representativo de cada uno de los siete proyectos;
- cifras de respuesta comparadas con el motor actual de “Precios del proyecto”;
- cero llamadas a Bitrix durante una recomendación normal;
- respuestas y cabeceras HTTP reales del endpoint;
- cliente firmado desde el bot con secreto de prueba.

### Conversacionales

- prospecto con proyecto y presupuesto compatibles;
- proyecto compatible sin presupuesto todavía;
- proyecto incompatible y permiso posterior para búsqueda cruzada;
- prospecto que pide un tipo distinto dentro de Noral Plaza;
- inventario temporalmente no disponible;
- una opción deja de estar disponible entre dos mensajes.

## Despliegue gradual y reversión

1. Implementar endpoint y pruebas sin conectarlo al bot.
2. Validar en entorno aislado con fixtures y después en modo lectura contra el catálogo real.
3. Comparar una muestra de resultados con las pantallas vigentes de precios.
4. Desplegar Inventario Sync con el endpoint apagado por variable de entorno.
5. Habilitarlo solo para administradores y conversaciones de prueba.
6. Activar un canal vendedor a la vez desde una bandera del bot.
7. Vigilar errores, edad del catálogo y exactitud comercial antes de ampliar.

La reversión consiste en apagar la bandera del bot o la del endpoint. Como la API no escribe en Bitrix, deshabilitarla no requiere restaurar datos.

## Criterios de aceptación

- Ninguna recomendación normal consulta ni modifica Bitrix.
- Solo se muestran unidades probadamente disponibles.
- Cada cifra puede rastrearse al catálogo y a la matriz/lista vigente.
- Noral Plaza se trata como un proyecto único con sus familias internas.
- Se devuelven como máximo tres opciones y el proyecto de entrada tiene prioridad.
- La búsqueda no se amplía sin causa o permiso del prospecto.
- Un fallo de datos produce una respuesta segura, nunca una invención.
- La cotización formal y la aprobación del vendedor permanecen fuera de este cambio.
