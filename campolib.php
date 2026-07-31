<?php
/**
 * campolib.php — convierte el valor del campo "Inventario" en enlaces reales.
 * Compartido por guardar.php (lo llama el campo al elegir) y sync-campo.php
 * (red de seguridad / uso manual).
 * ---------------------------------------------------------------------------
 * El campo propio guarda solo texto ("581,623"). Esto es lo que lo vuelve un
 * enlace de verdad, igual que hacían los 4 campos anteriores:
 *
 *   1. escribe parentId2 = deal en cada unidad elegida  -> aparece la DEPENDENCIA
 *   2. suelta (parentId2 = 0) las unidades que se quitaron del campo
 *   3. copia responsable y cliente del deal a la unidad
 *   4. aplica el stage según la etapa del deal (RESERVADO / FIRMADO / VENDIDO)
 *   5. a las que se sueltan las deja en DISPONIBLE
 *
 * Lo llama el propio campo al elegir/quitar una unidad, y también reconcile.php
 * como red de seguridad.
 *
 * Solo actúa sobre deals de CLIENTES(44): Cobranzas(48) es de solo lectura.
 * ---------------------------------------------------------------------------
 */

declare(strict_types=1);
set_time_limit(120);

// SPA_ENTITY debe existir ANTES de requerir stagelib.php (lo usa por dentro).
// CLIENTES_CAT no se define aquí: ya la trae stagelib.php y chocaría.
const SPA_ENTITY     = 1072;
const CAMPO_NUEVO    = 'UF_CRM_1785205972989';   // campo "Inventario" (tipo propio)

// ── AUTOLLENADO de la ficha del deal desde la unidad elegida ─────────────────
// Al elegir la unidad, estos 4 campos del deal se llenan solos con lo que ya
// está en la tarjeta de la unidad. Antes el vendedor los tecleaba a mano y son
// obligatorios para cambiar de etapa.
const D_PROYECTO = 'UF_CRM_5EECED2074CC5';   // "Proyectos 1"      (lista)
const D_ACTIVO   = 'UF_CRM_1732047127';      // "ACTIVO COMPRADO"  (texto)
const D_VALOR    = 'UF_CRM_1731969538';      // "VALOR DEL ACTIVO" (money)
// (el monto son los nativos OPPORTUNITY + CURRENCY_ID)

const U_PVP  = 'ufCrm25_1784563253861';      // PVP de la unidad (money "n|USD")
const U_TIPO = 'ufCrm25_1782616418179';      // Tipo de bien (lista)

/**
 * Pipeline del SPA + tipo de bien -> opción de "Proyectos 1" del deal.
 *
 * El mapa NO es 1:1 por pipeline: Noral Plaza se parte en 4 opciones y Galero
 * Casas en 2, según el TIPO DE BIEN. No está inventado — se derivó de los 946
 * deals de CLIENTES que ya tienen unidad atada y proyecto cargado a mano, tomando
 * la combinación mayoritaria de cada par (pipeline, tipo). Ahí se vio, por
 * ejemplo, que un Departamento de Noral Plaza va a "Noral Plaza (Suites)" y no a
 * "Locales Comerciales".
 *
 * '*' = default del pipeline cuando el tipo no está listado.
 */
const MAPA_PROYECTO = [
    33 => [1791 => 162, 1951 => 1743, 1793 => 1625, 1797 => 1625, 1801 => 1753, '*' => 162],
    39 => ['*' => 516],    // Noral Apartments
    43 => ['*' => 142],    // Barranca Apartments
    49 => ['*' => 200],    // Sun Bay
    47 => ['*' => 73],     // Galero Torre C  -> Departamentos en Playas
    51 => ['*' => 73],     // Galero Torre D  -> Departamentos en Playas
    53 => ['*' => 150],    // Galero Suites
    55 => [1799 => 115, 1947 => 115, 1945 => 115, 1943 => 514, 1949 => 514, '*' => 115],
    61 => ['*' => 134],    // Elite Apartments (pipeline nuevo, jul-2026)
];

/**
 * Código de la unidad como lo espera "ACTIVO COMPRADO": con guion entre la letra
 * y el primer número. En el SPA el título es "S3-3" o "C10-3", pero los 946 deals
 * ya cargados lo escriben "S-3-3" y "C-10-3". Los que ya vienen con guion
 * ("B-1-6", "G-2", "D-10") se dejan intactos.
 */
function codigo_activo(string $titulo): string {
    $cod = trim(explode('(', $titulo)[0]);
    return preg_replace('/^([A-Za-z]+)(?=\d)/', '$1-', $cod) ?? $cod;
}

/** Opción de "Proyectos 1" para una unidad, o 0 si su pipeline no está mapeado. */
function proyecto_de_unidad(int $cat, $tipoId): int {
    $m = MAPA_PROYECTO[$cat] ?? null;
    if ($m === null) return 0;
    $t = (string)(int)$tipoId;
    foreach ($m as $k => $v) if ((string)$k === $t) return (int)$v;
    return (int)($m['*'] ?? 0);
}

$DATA_DIR   = getenv('DATA_DIR') ?: '/data';
$WEBHOOK_IN = rtrim((string)getenv('BITRIX_WEBHOOK'), '/') . '/';

function logline(string $msg): void {
    global $DATA_DIR;
    // web.log: Apache no puede escribir en sync.log (lo crea el cron como root)
    @file_put_contents($DATA_DIR . '/web.log',
        gmdate('Y-m-d\TH:i:s\Z') . '  SYNCCAMPO ' . $msg . "\n", FILE_APPEND | LOCK_EX);
}

// Freno entre llamadas. Existe para que un BARRIDO no vacíe el presupuesto de API
// de Bitrix, pero en una acción interactiva es puro tiempo de espera para el
// vendedor: medido, 10 llamadas × 200 ms = 2 s de los 6,8 s que tardaba guardar.
// guardar.php lo pone en 0; reconcile y la migración lo dejan como está.
$BX_FRENO_US = 200000;

function bx(string $method, array $params = []): array {
    global $WEBHOOK_IN, $BX_FRENO_US;
    if ($BX_FRENO_US > 0) usleep($BX_FRENO_US);
    for ($try = 0; $try < 4; $try++) {
        $ch = curl_init($WEBHOOK_IN . $method);
        curl_setopt_array($ch, [
            CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query($params),
            CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30, CURLOPT_CONNECTTIMEOUT => 10,
        ]);
        $raw = curl_exec($ch); $errno = curl_errno($ch); curl_close($ch);
        if ($errno) { if ($try < 3) { sleep(1); continue; } return ['ok' => false, 'error' => "curl:$errno"]; }
        $j = json_decode((string)$raw, true);
        if (is_array($j) && isset($j['error'])) {
            if (in_array($j['error'], ['QUERY_LIMIT_EXCEEDED', 'OPERATION_TIME_LIMIT'], true) && $try < 3) {
                sleep(2 + $try); continue;
            }
            // Bitrix a veces manda error:"" con el motivo real en
            // error_description. Devolver solo $j['error'] dejaba errores
            // vacíos ("error":"") imposibles de diagnosticar.
            $e = trim((string)$j['error']);
            $d = trim((string)($j['error_description'] ?? ''));
            if ($e === '' && $d === '') $e = 'error-sin-detalle';
            return ['ok' => false, 'error' => $d !== '' ? ($e !== '' ? "$e: $d" : $d) : $e];
        }
        if (!is_array($j)) { if ($try < 3) { sleep(1); continue; } return ['ok' => false, 'error' => 'bad-json']; }
        return ['ok' => true, 'result' => $j['result'] ?? null, 'next' => $j['next'] ?? null];
    }
    return ['ok' => false, 'error' => 'retries-exhausted'];
}

require_once __DIR__ . '/stagelib.php';   // stage_id(), apply_unit_stage(), CLIENTES_TRIGGERS...

/**
 * Refresca en el caché del selector las unidades que acabamos de tocar.
 * Sin esto la lista seguía mostrando "DISPONIBLE" hasta el próximo refresco
 * (cada 15 min), aunque la unidad ya estuviera reservada.
 */
function refrescar_cache(array $unitIds): void {
    global $DATA_DIR;
    if (!$unitIds) return;
    $path = $DATA_DIR . '/selector_cache.json';
    $j = json_decode((string)@file_get_contents($path), true);
    if (!is_array($j) || empty($j['units'])) return;

    // nombre de stage por STATUS_ID, para guardar en el caché lo mismo que guarda rebuild
    $rev = [];
    foreach (stages_map() as $cat => $m) foreach ($m as $nombre => $sid) $rev[$sid] = $nombre;

    $nuevos = [];
    foreach ($unitIds as $uid) {
        $r = bx('crm.item.get', ['entityTypeId' => SPA_ENTITY, 'id' => (int)$uid]);
        if (!$r['ok']) continue;
        $it = $r['result']['item'] ?? $r['result'];
        $nuevos[(string)$uid] = [
            'stage'  => $rev[(string)($it['stageId'] ?? '')] ?? '',
            'dealId' => (int)($it['parentId2'] ?? 0),
        ];
    }
    if (!$nuevos) return;

    foreach ($j['units'] as &$u) {
        $k = (string)$u['id'];
        if (isset($nuevos[$k])) {
            $u['stage']  = $nuevos[$k]['stage'];
            $u['dealId'] = $nuevos[$k]['dealId'];
        }
    }
    unset($u);
    @file_put_contents($path, json_encode($j));
}

// ids_de() vive en stagelib.php (la necesita reconcile.php, que no puede
// cargar campolib). Definirla aquí también daba un fatal por redeclaración.

/**
 * Actualiza UNA unidad en el caché del selector con lo que acabamos de escribir,
 * sin volver a preguntárselo a Bitrix. Pasar null en un campo = dejarlo como está.
 * Antes esto costaba un crm.item.get por unidad en cada guardado.
 */
function cache_unidad(int $unitId, ?string $stage, ?int $dealId): void {
    global $DATA_DIR;
    $path = $DATA_DIR . '/selector_cache.json';
    $j = json_decode((string)@file_get_contents($path), true);
    if (!is_array($j) || empty($j['units'])) return;
    $toco = false;
    foreach ($j['units'] as $i => $u) {
        if ((int)($u['id'] ?? 0) !== $unitId) continue;
        if ($stage  !== null) { $j['units'][$i]['stage']  = $stage;  $toco = true; }
        if ($dealId !== null) { $j['units'][$i]['dealId'] = $dealId; $toco = true; }
        break;
    }
    if ($toco) @file_put_contents($path, json_encode($j), LOCK_EX);
}

/** Apartados del 28 leídos de disco: unitId => dealId. Cero llamadas al API. */
function apartados_registro(): array {
    global $DATA_DIR;
    $j = json_decode((string)@file_get_contents($DATA_DIR . '/apartados_puestos.json'), true);
    return is_array($j) ? $j : [];
}

/**
 * De una lista de IDs, devuelve las que SÍ se pueden atar a este deal.
 * Portero del servidor: la lista del campo pinta en gris lo ocupado, pero eso es
 * solo la pantalla. Sin esto se podía guardar un ID inventado, o una unidad que
 * otro vendedor ya tenía (doble venta).
 *
 * Se piden DOS condiciones, y las dos hacen falta:
 *
 *   a) parentId2 libre (0/null) o ya de este mismo deal.
 *   b) stage DISPONIBLE (o ya es de este deal).
 *
 * La (b) no es de adorno. Hay dos formas de que una unidad esté tomada: por
 * parentId2 (lo que escribe este sistema) y por el campo NATIVO del deal
 * PARENT_ID_1072, que deja parentId2 en null. Hoy la mayoría de las 778 unidades
 * ocupadas lo están por la vía nativa, así que mirando solo parentId2 salían
 * TODAS como libres. El stage sí las delata: una unidad tomada está en
 * RESERVADO / FIRMADO / VENDIDO, nunca en DISPONIBLE.
 */
function unidades_asignables(array $ids, int $dealId, int $contacto = 0): array {
    if (!$ids) return [];
    // sin `select`: con select explícito Bitrix devuelve id en null (bug verificado)
    $r = bx('crm.item.list', ['entityTypeId' => SPA_ENTITY, 'filter' => ['@id' => $ids]]);
    if (!$r['ok']) return [];

    // stageId -> nombre ("DT1072_33:NEW" -> "DISPONIBLE")
    $rev = [];
    foreach (stages_map() as $cat => $m) foreach ($m as $nombre => $sid) $rev[$sid] = $nombre;

    // Antes aquí se llamaba a apartados_28(), que pagina TODO el pipeline 28
    // filtrando por un campo de tipo propio: 2,96 s medidos, el 45% de lo que
    // tardaba guardar. No hace falta: quien de verdad frena una unidad apartada es
    // la condición de stage DISPONIBLE de más abajo. El registro en disco solo se
    // necesita para la EXCEPCIÓN de "soy la copia de la misma venta", así que se
    // lee de disco (0 llamadas) y solo se pregunta el contacto cuando hay choque.
    $apart = apartados_registro();

    $ok = [];
    foreach (($r['result']['items'] ?? []) as $it) {
        $id = (int)($it['id'] ?? 0);
        if (!$id) continue;
        $dueno = (int)($it['parentId2'] ?? 0);
        if ($dueno === $dealId) { $ok[] = $id; continue; }   // ya es mía
        if ($dueno !== 0) continue;                          // atada de verdad a otro deal
        $stage = $rev[(string)($it['stageId'] ?? '')] ?? '';

        $a = $apart[$id] ?? null;
        if ($a) {
            // Apartada desde Prospectos(28). Pasa si es MI propio apartado, o si
            // soy la copia de esa misma venta: mismo CONTACTO. Esa es la regla que
            // ya usa referidor.php para arrastrar copias entre pipelines, y el
            // motivo de anclar al contacto es que el MISMO código de unidad
            // reaparece en reventas a personas distintas con los años.
            if ((int)$a === $dealId) { $ok[] = $id; continue; }
            // Misma venta = mismo contacto. Se pregunta SOLO en este choque, que es
            // raro, en vez de escanear el pipeline entero en cada guardado.
            if ($contacto > 0) {
                $g = bx('crm.deal.get', ['id' => (int)$a]);
                if ($g['ok'] && (int)($g['result']['CONTACT_ID'] ?? 0) === $contacto) { $ok[] = $id; continue; }
            }
            continue;                                        // apartada por otra venta
        }

        if ($stage === 'DISPONIBLE') $ok[] = $id;
    }
    return $ok;
}

/** Candado de adopción: deals de CLIENTES que ya pasaron por la adopción una vez. */
function adopciones(): array {
    global $DATA_DIR;
    $j = json_decode((string)@file_get_contents($DATA_DIR . '/adopciones.json'), true);
    return is_array($j) ? $j : [];
}
function adopciones_guardar(array $m): void {
    global $DATA_DIR;
    @file_put_contents($DATA_DIR . '/adopciones.json', json_encode($m), LOCK_EX);
}

/**
 * APARTA las unidades elegidas en un deal de Prospectos(28).
 *
 * Diferencia con CLIENTES: aquí NO se escribe parentId2, así que la unidad no
 * queda con dependencia. Si se escribiera, al copiarse el deal a CLIENTES la
 * misma unidad tendría dependencia de DOS deals de pipelines distintos — que es
 * exactamente lo que hay que evitar. El apartado solo pone RESERVADO (para que
 * nadie más la escoja) y anota quién la aparta.
 */
function apartar_prospecto(int $dealId, array $deal): array {
    $quiere = ids_de((string)($deal[CAMPO_NUEVO] ?? ''));
    $puestos = apartados_puestos();
    $movidas = 0;

    // UNA lectura y UNA escritura por unidad. Antes eran tres crm.item.get y dos
    // crm.item.update de la MISMA unidad (~1,1 s medidos de puro trámite): uno por
    // el stage, otro por responsable/cliente, y un tercero para refrescar el caché.
    foreach ($quiere as $uid) {
        $uid = (int)$uid;
        $r = bx('crm.item.get', ['entityTypeId' => SPA_ENTITY, 'id' => $uid]);
        if (!$r['ok']) { logline("ERR get u=$uid: {$r['error']}"); continue; }
        $it = $r['result']['item'] ?? $r['result'];

        $campos = campos_owner($it, $deal);         // se ve quién la está apartando
        $st     = stage_objetivo($uid, $it, 'RESERVADO', false);
        if ($st !== null) $campos['stageId'] = $st;

        if ($campos) {
            // marca de escritura propia: el guardián del kanban la usa para saber
            // que este cambio lo hizo el sistema y no una persona
            @touch($GLOBALS['DATA_DIR'] . '/self_u_' . $uid);
            $u = bx('crm.item.update', ['entityTypeId' => SPA_ENTITY, 'id' => $uid, 'fields' => $campos]);
            if ($u['ok']) {
                if ($st !== null) { $movidas++; logline("STAGE unit=$uid -> RESERVADO"); }
                if (isset($campos['contactId']) || isset($campos['assignedById'])) logline("OWNER unit=$uid");
                // el caché se actualiza con lo que acabamos de escribir, sin releer
                cache_unidad($uid, $st !== null ? 'RESERVADO' : null, null);
            } else {
                logline("ERR update u=$uid: {$u['error']}");
            }
        }
        $puestos[(string)$uid] = $dealId;           // para poder liberarla después
    }

    // Unidades que este deal apartaba y ya sacó del campo -> liberar,
    // salvo que CLIENTES ya se las haya llevado de verdad (parentId2 puesto).
    $quitadas = [];
    foreach ($puestos as $uid => $dueno) {
        if ((int)$dueno !== $dealId) continue;
        if (in_array((int)$uid, $quiere, true)) continue;
        $quitadas[] = (int)$uid;
        unset($puestos[$uid]);
    }

    // El registro se guarda ANTES de liberar, no después: liberar mueve la unidad
    // a DISPONIBLE y eso dispara el evento del SPA, donde el guardián de estado
    // lee este mismo archivo. Si todavía dijera que está apartada, el guardián
    // creería que el movimiento es indebido y pediría un re-sync de balde.
    apartados_puestos_guardar($puestos);

    $soltadas = 0;
    foreach ($quitadas as $uid) {
        if (liberar_apartado($uid)) $soltadas++;
    }

    // Igual que en CLIENTES: la copia del otro pipeline la sigue nombrando, así
    // que se le quita también o volvería a atarla.
    $propagadas = $quitadas
        ? propagar_quitada($quitadas, $dealId, (int)($deal['CONTACT_ID'] ?? 0))
        : 0;

    return ['ok' => true, 'modo' => 'apartado-28', 'aparta' => count($quiere),
            'movidas' => $movidas, 'soltadas' => $soltadas, 'propagadas' => $propagadas];
}

/**
 * Vacía la unidad del campo Inventario del deal de PROSPECTOS(28) una vez que
 * CLIENTES(44) ya la tomó de verdad (parentId2 puesto).
 *
 * Por qué: el 28 solo APARTA — es un "no me la toquen mientras negocio". Cuando
 * el deal se copia a CLIENTES y ahí queda atada, el apartado ya no significa
 * nada y tener la misma unidad en los dos campos solo desordena: el vendedor ve
 * la unidad en dos sitios y no sabe cuál manda.
 *
 * Se hace DESPUÉS del atado, nunca antes: si se vaciara primero y el atado
 * fallara, la unidad quedaría libre en los dos pipelines y otro podría venderla.
 *
 * Solo toca deals del 28 del MISMO contacto, y solo les quita las unidades que el
 * 44 acaba de atar. Lo demás que tengan en el campo se queda.
 */
function limpiar_prospecto(int $deal44, int $contacto, array $unidades): int {
    $unidades = array_values(array_unique(array_map('intval', $unidades)));
    if (!$unidades || $contacto <= 0) return 0;

    $r = bx('crm.deal.list', [
        'filter' => ['CONTACT_ID' => $contacto, 'CATEGORY_ID' => PROSPECTOS_CAT,
                     '!' . CAMPO_NUEVO => ''],
        'select' => ['ID', CAMPO_NUEVO],
    ]);
    if (!$r['ok']) { logline("limpiar_prospecto ERR list: {$r['error']}"); return 0; }

    $n = 0;
    foreach (($r['result'] ?? []) as $d) {
        $did   = (int)($d['ID'] ?? 0);
        $tiene = ids_de((string)($d[CAMPO_NUEVO] ?? ''));
        $queda = array_values(array_diff($tiene, $unidades));
        if (count($queda) === count($tiene)) continue;

        $u = bx('crm.deal.update', ['id' => $did, 'fields' => [CAMPO_NUEVO => implode(',', $queda)]]);
        if (!$u['ok']) { logline("limpiar_prospecto deal=$did ERR: {$u['error']}"); continue; }
        logline("PROSPECTO deal=$did: campo limpiado, CLIENTES($deal44) ya ató ["
              . implode(',', array_intersect($tiene, $unidades)) . ']');
        $n++;
    }

    // El apartado también sale del registro: la unidad ya no está "apartada por el
    // 28", está vendida. Si se dejara, el barrido intentaría liberarla.
    $puestos = apartados_puestos();
    $tocado  = false;
    foreach ($unidades as $uid) {
        if (isset($puestos[(string)$uid])) { unset($puestos[(string)$uid]); $tocado = true; }
    }
    if ($tocado) apartados_puestos_guardar($puestos);

    return $n;
}

/**
 * Quita unas unidades del campo Inventario de los deals HERMANOS.
 *
 * Cuando el deal del 28 llega a RESERVA se copia a CLIENTES(44) arrastrando el
 * campo, así que la MISMA unidad queda nombrada en DOS deals. Quitarla de uno no
 * bastaba: el otro seguía nombrándola y, en su próximo evento, el sistema la
 * volvía a poner RESERVADO (si era el del 28) o la re-ataba — o sea que la
 * unidad no regresaba nunca a DISPONIBLE y nadie más podía escogerla.
 *
 * Se limita a los deals del MISMO CONTACTO en 28/44: es la regla de
 * emparejamiento que ya usa el resto del sistema, y hace falta porque el mismo
 * código de unidad reaparece en reventas a personas distintas con los años.
 *
 * NO toca deals del 44 ya firmados (PROMESA FIRMADA / CIERRE DE PROMESA): ahí el
 * campo es el registro de una venta que ocurrió de verdad y vaciarlo borraría
 * historial. Se reconoce por el stage que dispara FIRMADO/VENDIDO, en vez de con
 * una lista aparte que habría que mantener en dos sitios.
 *
 * El update del hermano dispara su propio evento y por lo tanto su propio
 * sincronizado: ahí es donde la unidad termina de soltarse. No hay bucle porque
 * en la segunda pasada el origen ya no la nombra y no queda nada que quitar.
 */
function propagar_quitada(array $unitIds, int $dealOrigen, int $contacto): int {
    $unitIds = array_values(array_unique(array_map('intval', $unitIds)));
    if (!$unitIds || $contacto <= 0) return 0;

    $r = bx('crm.deal.list', [
        'filter' => ['CONTACT_ID'        => $contacto,
                     '@CATEGORY_ID'      => [PROSPECTOS_CAT, CLIENTES_CAT],
                     '!' . CAMPO_NUEVO   => ''],
        'select' => ['ID', 'CATEGORY_ID', 'STAGE_ID', CAMPO_NUEVO],
    ]);
    if (!$r['ok']) { logline("propagar u=[" . implode(',', $unitIds) . "] ERR list: {$r['error']}"); return 0; }

    $n = 0;
    foreach (($r['result'] ?? []) as $d) {
        $did = (int)($d['ID'] ?? 0);
        if ($did <= 0 || $did === $dealOrigen) continue;

        $st = (string)($d['STAGE_ID'] ?? '');
        if (in_array(CLIENTES_TRIGGERS[$st] ?? '', ['FIRMADO', 'VENDIDO'], true)) {
            logline("propagar: deal $did en $st (venta cerrada) -> no se toca su campo");
            continue;
        }

        $tiene = ids_de((string)($d[CAMPO_NUEVO] ?? ''));
        $queda = array_values(array_diff($tiene, $unitIds));
        if (count($queda) === count($tiene)) continue;          // no la tenía

        $up = bx('crm.deal.update', ['id' => $did, 'fields' => [CAMPO_NUEVO => implode(',', $queda)]]);
        if (!$up['ok']) { logline("propagar: ERR update deal $did: {$up['error']}"); continue; }
        logline("propagada quitada de u=[" . implode(',', array_intersect($tiene, $unitIds))
              . "] al deal hermano $did (origen $dealOrigen)");
        $n++;
    }
    return $n;
}

/**
 * Llena en el deal de CLIENTES los 4 campos que salen de la tarjeta de la unidad:
 * Monto y moneda (OPPORTUNITY + CURRENCY_ID), Proyectos 1, VALOR DEL ACTIVO y
 * ACTIVO COMPRADO. Antes se teclaban a mano y son obligatorios para cambiar de
 * etapa.
 *
 * CUÁNDO corre: solo cuando el vendedor ACABA de elegir (o cambiar) la unidad, es
 * decir cuando hay unidades recién agregadas. En un barrido del reconcile o en un
 * simple cambio de etapa no se toca nada, y eso es a propósito: el monto del deal
 * NO siempre es el PVP de lista. Hay casos reales (deal 397331, unidad C10-3: PVP
 * 127.125 vs monto 141.900) donde el precio pactado incluye upgrades, bodega o
 * balcón. Si esto se ejecutara en cada sincronización, cada barrido le borraría al
 * vendedor el precio negociado y lo dejaría en el de lista.
 *
 * Varias unidades en un mismo deal (fusiones): el monto y el valor se SUMAN, los
 * códigos se concatenan y el proyecto se toma de la primera.
 *
 * DESCUENTO DE PARQUEO — solo Noral Plaza (Suites). Ahí, cuando el deal lleva más
 * de una unidad, las de más son parqueos y NO entran en el precio de venta: se
 * resta 20.000 del total. Son 20.000 fijos, no 20.000 por parqueo: con tres
 * unidades (una suite y dos parqueos) se resta lo mismo que con dos. Es la regla
 * del negocio, no un cálculo.
 *
 * Cómo se reconoce que es Suites: por el PROYECTO que resuelve la unidad, no por
 * la palabra del Tipo de bien. En Noral Plaza el Sheet nunca dice "suite" —dice
 * "Departamento"— y los dos tipos caen en "Noral Plaza (Suites)". Mirar el
 * proyecto evita depender de que el Tipo de bien esté bien puesto en cada ficha,
 * que no siempre lo está.
 *
 * Coste: 1 escritura. Cero lecturas — los datos salen del crm.item.get que el
 * bucle de arriba ya hizo para atar la unidad.
 */
const PROY_NORAL_SUITES = 1625;   // "Noral Plaza (Suites)" en la lista Proyectos 1
const DESCUENTO_PARQUEO = 20000;

// ── Generador de historias Noral ─────────────────────────────────────────────
// Solo estos 2 pipelines tienen plano allá. Barranca, Sun Bay y Galero están en el
// SPA pero no en el generador: no se les avisa y no es un error.
const NORAL_PROY = [33 => 'NORAL PLAZA', 39 => 'NORAL APARTMENTS'];

/**
 * Avisa al generador de historias que una unidad se ató (marcar) o se liberó
 * (desmarcar), para que el sello aparezca/desaparezca solo en el plano.
 *
 * Se manda el código CRUDO del título; la traducción a la nomenclatura del
 * generador (`A-2-1` -> `A2.1`) vive allá, junto a su coords.json, que es el único
 * que sabe qué páginas existen y cómo se llama cada celda.
 *
 * Fire-and-forget con timeout corto: si el generador está caído, atar la unidad en
 * Bitrix NO se puede caer por eso. El sello se puede poner a mano con un clic.
 */
function noral_avisar_generador(int $catId, string $codigo, string $accion): void {
    $proy = NORAL_PROY[$catId] ?? null;
    if ($proy === null || $codigo === '') return;

    $url = rtrim((string)getenv('NORAL_URL'), '/');
    $tok = (string)getenv('NORAL_SYNC_TOKEN');
    if ($url === '' || $tok === '') return;          // sin configurar -> no hace nada

    $ch = curl_init($url . '/sync-inventario.php');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query([
            'proyecto' => $proy, 'codigo' => $codigo, 'accion' => $accion, 'token' => $tok,
        ]),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 6, CURLOPT_CONNECTTIMEOUT => 3,
    ]);
    $raw = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $j = json_decode((string)$raw, true);
    // se registra el motivo cuando el generador no tiene ese plano, así queda
    // rastro de qué unidades quedan sin sello (bloque D de Plaza, bloque J de Apt)
    $det = is_array($j)
        ? ($j['skip'] ?? ($j['clave'] ?? ($j['error'] ?? 'ok')))
        : "http=$code";
    // llaves obligatorias: «$codigo» hace que PHP lea la comilla angular como parte
    // del nombre de la variable -> warning "Undefined variable" impreso ANTES del
    // JSON, que rompe el fetch().json() del vendedor al guardar.
    logline("NORAL $accion {$codigo} ($proy) -> $det");
}

function autollenar_ficha(int $dealId, array $fichas): array {
    // El proyecto lo manda la unidad PRINCIPAL, o sea la de mayor PVP — no la
    // primera del campo. Con "la primera" bastaba que el vendedor pusiera el
    // parqueo antes de la suite para que Proyectos 1 recibiera el proyecto del
    // parqueo, que ni siquiera es una opción válida de esa lista.
    $cods = []; $suma = 0.0; $proy = 0; $mejorPvp = -1.0; $esSuites = false;
    foreach ($fichas as $f) {
        if ($f['cod'] !== '') $cods[] = $f['cod'];
        $pvp   = (float)$f['pvp'];
        $suma += $pvp;
        if (!empty($f['proy']) && $pvp > $mejorPvp) { $mejorPvp = $pvp; $proy = (int)$f['proy']; }
        if ((int)($f['proy'] ?? 0) === PROY_NORAL_SUITES) $esSuites = true;
    }
    if (!$cods) return [];

    if ($esSuites && count($fichas) > 1 && $suma > DESCUENTO_PARQUEO) {
        $suma -= DESCUENTO_PARQUEO;
        logline("deal=$dealId Noral Plaza Suites con " . count($fichas)
              . ' unidades -> se resta el parqueo (' . DESCUENTO_PARQUEO . ')');
    }

    $campos = [D_ACTIVO => implode(', ', $cods)];
    if ($suma > 0) {
        $monto = rtrim(rtrim(number_format($suma, 2, '.', ''), '0'), '.');
        $campos['OPPORTUNITY'] = $monto;
        $campos['CURRENCY_ID'] = 'USD';
        $campos[D_VALOR]       = $monto . '|USD';
    }
    if ($proy > 0) $campos[D_PROYECTO] = $proy;

    $u = bx('crm.deal.update', ['id' => $dealId, 'fields' => $campos]);
    if (!$u['ok']) { logline("deal=$dealId ficha ERR: {$u['error']}"); return ['err' => $u['error']]; }
    logline("deal=$dealId ficha autollenada: activo=" . implode(',', $cods)
          . " monto=" . ($campos['OPPORTUNITY'] ?? '-') . " proy=" . ($proy ?: '-'));
    return ['activo' => implode(', ', $cods), 'monto' => $campos['OPPORTUNITY'] ?? null, 'proy' => $proy];
}

/**
 * Sincroniza un deal: deja atadas exactamente las unidades del campo.
 * Devuelve un resumen para el log.
 */
function sincronizar_deal(int $dealId, ?array $dealYaLeido = null): array {
    global $DATA_DIR;
    // guardar.php ya hizo el crm.deal.get para validar: se reusa en vez de repetirlo
    // (350 ms medidos de puro trámite).
    if ($dealYaLeido !== null) {
        $deal = $dealYaLeido;
    } else {
        $g = bx('crm.deal.get', ['id' => $dealId]);
        if (!$g['ok']) return ['ok' => false, 'error' => 'deal-no-existe'];
        $deal = $g['result'];
    }
    $cat  = (int)($deal['CATEGORY_ID'] ?? -1);

    // Prospectos(28): solo APARTA. No escribe parentId2 ni crea dependencia —
    // el atado de verdad nace únicamente en RESERVA de CLIENTES(44).
    if ($cat === PROSPECTOS_CAT) return apartar_prospecto($dealId, $deal);

    // guarda dura: Cobranzas(48) es read-only por regla del negocio
    if ($cat !== CLIENTES_CAT) {
        return ['ok' => false, 'error' => 'solo-clientes-44'];
    }

    $quiere = ids_de((string)($deal[CAMPO_NUEVO] ?? ''));

    // ADOPCIÓN: la copia llegó a RESERVA con el campo vacío.
    // No se puede dar por hecho que la automatización que copia el deal del 28 al
    // 44 arrastre este campo (es de tipo propio). Si llega vacío y el MISMO
    // contacto tiene una unidad apartada en Prospectos, se rellena aquí y sigue
    // el camino normal. Mismo patrón que referidor.php con CLIENTE REFERIDOR.
    // Se adopta UNA SOLA VEZ, con candado en disco. Sin el candado la adopción
    // rebota: cuando el usuario quita la unidad, el campo queda vacío, se vuelve
    // a adoptar del apartado del 28 y la unidad se re-ata sola — quitarla era
    // imposible. Mismo candado que usa referidor.php ("done") para no repetir.
    $marcas = adopciones();
    if ($quiere) {
        // ya tiene valor: se marca para que un vaciado futuro NO se re-adopte
        if (!isset($marcas[(string)$dealId])) {
            $marcas[(string)$dealId] = 1;
            adopciones_guardar($marcas);
        }
    } elseif (!isset($marcas[(string)$dealId]) && (string)($deal['STAGE_ID'] ?? '') === 'C44:NEW') {
        $adoptadas = [];
        $contacto  = (int)($deal['CONTACT_ID'] ?? 0);
        if ($contacto > 0) {
            foreach (apartados_28() as $uid => $a) {
                if ((int)$a['contacto'] === $contacto) $adoptadas[] = (int)$uid;
            }
        }
        if ($adoptadas) {
            $quiere = $adoptadas;
            bx('crm.deal.update', ['id' => $dealId,
                                   'fields' => [CAMPO_NUEVO => implode(',', $adoptadas)]]);
            logline("deal=$dealId ADOPTA del apartado 28: " . implode(',', $adoptadas));
            $deal[CAMPO_NUEVO] = implode(',', $adoptadas);
        }
        $marcas[(string)$dealId] = 1;      // se marca aunque no haya adoptado nada
        adopciones_guardar($marcas);
    }

    // Si el deal se cayó (RESERVAS CAIDAS / FIRMADOS-CAIDOS) no quiere ninguna:
    // las unidades se sueltan. El campo se deja como estaba, de registro.
    $etapaDeal = (string)($deal['STAGE_ID'] ?? '');
    if (etapa_libera($etapaDeal)) $quiere = [];

    // Lo que hoy apunta a este deal.
    // SIN `select`: con select explícito Bitrix devuelve id/title en null (bug
    // verificado). Por eso antes esta lista salía vacía, "agregadas" contaba de
    // más y —lo grave— quitar una unidad del campo NO la liberaba.
    $tiene = [];
    $r = bx('crm.item.list', ['entityTypeId' => SPA_ENTITY, 'filter' => ['parentId2' => $dealId]]);
    if ($r['ok']) foreach (($r['result']['items'] ?? []) as $it) {
        if (!empty($it['id'])) $tiene[] = (int)$it['id'];
    }

    $agregar = array_values(array_diff($quiere, $tiene));
    $soltar  = array_values(array_diff($tiene, $quiere));

    // stage que corresponde según la etapa del deal
    $etapa  = (string)($deal['STAGE_ID'] ?? '');
    $target = CLIENTES_TRIGGERS[$etapa] ?? null;

    // UNA lectura y UNA escritura por unidad. Antes, para UNA sola unidad, este
    // bloque hacía 3 lecturas y 3 escrituras: un update para parentId2, otro para
    // responsable/cliente y otro para el stage, más las lecturas que cada decisión
    // necesitaba por separado (y puede_liberar() releía otra vez). Medido: 2,6 s.
    //
    // El stage se aplica a TODAS las del campo, no solo a las recién agregadas: si
    // no, al mover el deal de etapa (Promesa firmada, Cierre de promesa,
    // Firmados-caídos) las que ya estaban atadas se quedaban igual.
    $movidas   = 0;
    $agregarSet = array_flip($agregar);
    $fichas     = [];   // datos de las unidades del campo, para el autollenado de abajo

    foreach (array_merge($quiere, $soltar) as $uid) {
        $uid = (int)$uid;
        $suelta = in_array($uid, $soltar, true);

        $r = bx('crm.item.get', ['entityTypeId' => SPA_ENTITY, 'id' => $uid]);
        if (!$r['ok']) { logline("ERR get u=$uid: {$r['error']}"); continue; }
        $it = $r['result']['item'] ?? $r['result'];

        // Se aprovecha ESTE get (ya pagado) para el autollenado de la ficha del
        // deal: así no cuesta ninguna llamada extra de lectura.
        if (!$suelta) {
            $fichas[$uid] = [
                'cod'  => codigo_activo((string)($it['title'] ?? '')),
                'pvp'  => (float)explode('|', (string)($it[U_PVP] ?? ''))[0],
                'proy' => proyecto_de_unidad((int)($it['categoryId'] ?? 0), $it[U_TIPO] ?? 0),
            ];
        }

        // Generador de historias: que nadie tenga que ir a marcar a mano lo que
        // Bitrix ya sabe. Se avisa al ATAR (marcar) y al SOLTAR (desmarcar), con el
        // código crudo del título — el generador hace la traducción a su propia
        // nomenclatura. Solo aplica a los 2 proyectos que tienen plano allá.
        noral_avisar_generador(
            (int)($it['categoryId'] ?? 0),
            trim(explode('(', (string)($it['title'] ?? ''))[0]),
            $suelta ? 'desmarcar' : 'marcar'
        );

        $campos = [];
        $nuevoStage = null;

        if ($suelta) {
            // Se borra también el contacto: al atar se le copia el cliente del deal,
            // y si al soltar no se limpia, la unidad queda libre pero con el cliente
            // del deal anterior pegado (dato sucio que confunde en la ficha).
            $campos['parentId2'] = 0;
            $campos['contactId'] = 0;
            $nuevoStage = 'DISPONIBLE';
        } else {
            if (isset($agregarSet[$uid])) {
                $campos['parentId2'] = $dealId;
                $campos += campos_owner($it, $deal);        // responsable + cliente
            }
            if ($target) {
                // Si el stage del deal manda a DISPONIBLE (reserva/firma caída), solo
                // se suelta lo que sigue siendo de este deal, no lo ya revendido a
                // otro. El dueño se lee del item que YA tenemos: puede_liberar()
                // hacía otro crm.item.get para averiguar lo mismo.
                $dueno = (int)($it['parentId2'] ?? 0);
                $puede = ($target !== 'DISPONIBLE') || $dueno === 0 || $dueno === $dealId;
                if ($puede) $nuevoStage = $target;
            }
        }

        if ($nuevoStage !== null) {
            $sid = stage_objetivo($uid, $it, $nuevoStage, false);
            if ($sid !== null) { $campos['stageId'] = $sid; } else { $nuevoStage = null; }
        }
        if (!$campos) continue;

        // marca de escritura propia: el guardián del kanban la usa para distinguir
        // un cambio del sistema de un arrastre humano
        if (isset($campos['stageId'])) @touch($DATA_DIR . '/self_u_' . $uid);

        $u = bx('crm.item.update', ['entityTypeId' => SPA_ENTITY, 'id' => $uid, 'fields' => $campos]);
        if (!$u['ok']) { logline("ERR update u=$uid: {$u['error']}"); continue; }

        if ($nuevoStage !== null) { $movidas++; logline("STAGE unit=$uid -> $nuevoStage"); }
        // el caché se actualiza con lo que acabamos de escribir, sin releer
        cache_unidad($uid, $nuevoStage,
                     array_key_exists('parentId2', $campos) ? (int)$campos['parentId2'] : null);
    }

    // Ya NO se copia la primera unidad al campo nativo PARENT_ID_1072: los 4
    // campos anteriores salen de circulación y ese reflejo solo confundía (una
    // unidad elegida aquí aparecía además en el campo viejo de arriba). La
    // dependencia real que ve el usuario en la unidad la da parentId2, no esto.

    // Lo que CLIENTES ató de verdad deja de ser un apartado del 28: se saca del
    // registro para que el barrido no intente liberarlo más adelante.
    if ($agregar) {
        $puestos = apartados_puestos();
        $tocado  = false;
        foreach ($agregar as $uid) {
            if (isset($puestos[(string)$uid])) { unset($puestos[(string)$uid]); $tocado = true; }
        }
        if ($tocado) apartados_puestos_guardar($puestos);
    }

    // Quitarla de ESTE deal no basta: su copia en el otro pipeline la sigue
    // nombrando y la volvería a tomar. Se propaga el quitado al hermano.
    // Solo cuando el usuario la sacó del campo, no cuando la etapa la liberó
    // (deal caído): ahí el campo se deja de registro, es a propósito.
    $propagadas = 0;
    if ($soltar && !etapa_libera($etapaDeal)) {
        $propagadas = propagar_quitada($soltar, $dealId, (int)($deal['CONTACT_ID'] ?? 0));
    }

    // Autollenado de la ficha del deal con los datos de la unidad elegida.
    $ficha = ($agregar && $fichas) ? autollenar_ficha($dealId, $fichas) : [];

    // Lo que CLIENTES acaba de atar se saca del campo de PROSPECTOS: ahí ya no es
    // un apartado, es una venta. Va al final, cuando el atado ya está hecho.
    $prospectos = $agregar
        ? limpiar_prospecto($dealId, (int)($deal['CONTACT_ID'] ?? 0), $agregar)
        : 0;

    return ['ok' => true, 'quiere' => count($quiere), 'agregadas' => count($agregar),
            'soltadas' => count($soltar), 'stage' => $target ?: '-', 'movidas' => $movidas,
            'propagadas' => $propagadas, 'ficha' => $ficha ?: '-', 'prospecto' => $prospectos];
}

