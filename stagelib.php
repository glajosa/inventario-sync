<?php
/**
 * stagelib.php — lógica de STAGE de la unidad según el estado de sus deals.
 * Compartido por hook.php (Clientes 44, tiempo real) y reconcile.php (Cobranzas 48 + barrido).
 *
 * Reglas (deal -> stage de la unidad), definidas por el usuario:
 *   CLIENTES(44) RESERVA                -> RESERVADO
 *   CLIENTES(44) PROMESA FIRMADA CLIENTE-> FIRMADO
 *   CLIENTES(44) CIERRE DE PROMESA      -> FIRMADO
 *   CLIENTES(44) FIRMADOS - CAIDOS      -> DISPONIBLE
 *   COBRANZAS(48) PAGADO TOTALMENTE     -> VENDIDO
 *   COBRANZAS(48) DADO DE BAJA          -> DISPONIBLE
 *   (quitar unidad del deal)            -> DISPONIBLE  (lo hace el sync de link)
 *
 * Cobranzas es READ-ONLY: NUNCA se escribe en el deal 48. Solo se lee su código
 * de unidad (UF_CRM_1732047127) + contacto para encontrar la unidad, y se escribe
 * en la UNIDAD.
 *
 * Prioridad: VENDIDO es protegido — un evento de Clientes NO baja una unidad ya
 * VENDIDA; solo COBRANZAS DADO DE BAJA la puede sacar de VENDIDO (a DISPONIBLE).
 */

// disparadores Clientes 44 (stageId del deal -> nombre de stage de la unidad)
const CLIENTES_CAT = 44;
const CLIENTES_TRIGGERS = [
    'C44:NEW'       => 'RESERVADO',   // RESERVA
    'C44:UC_2CE2UE' => 'FIRMADO',     // PROMESA FIRMADA POR CLIENTE (firmó, faltan documentos)
    'C44:WON'       => 'FIRMADO',     // CIERRE DE PROMESA (ya pagó notaría, documentos entregados)
    'C44:APOLOGY'   => 'DISPONIBLE',  // FIRMADOS - CAIDOS
    'C44:LOSE'      => 'DISPONIBLE',  // RESERVAS CAIDAS (se cayó la reserva -> la unidad vuelve a estar libre)
];

/**
 * Etapas en las que el deal SUELTA sus unidades.
 *
 * No basta con poner la unidad en DISPONIBLE: si el deal caído sigue siendo su
 * dueño (parentId2), el portero la rechaza y NADIE puede escogerla — quedaría
 * "disponible" de nombre pero apartada de hecho. En estas etapas la unidad se
 * desata además de liberarse.
 *
 * El campo del deal NO se vacía: queda como registro de lo que se había
 * reservado. Por eso todo lo que calcula "qué unidades quiere este deal" tiene
 * que devolver ninguna cuando el deal está en una de estas etapas; si no, el
 * barrido la volvería a atar cada 15 minutos.
 */
const CLIENTES_STAGES_LIBERAN = ['C44:APOLOGY', 'C44:LOSE'];

/** ¿El deal está en una etapa que suelta las unidades? */
function etapa_libera(string $stageId): bool {
    return in_array($stageId, CLIENTES_STAGES_LIBERAN, true);
}
// disparadores Cobranzas 48
const COBRANZAS_CAT = 48;
const COBRANZAS_TRIGGERS = [
    'C48:WON'  => 'VENDIDO',      // PAGADO TOTALMENTE
    'C48:LOSE' => 'DISPONIBLE',   // DADO DE BAJA
];
const COBRANZAS_CODE_FIELD = 'UF_CRM_1732047127';   // código de unidad en el deal 48 (read-only)

// prioridad para proteger VENDIDO
const STAGE_PRIORITY = ['DISPONIBLE' => 0, 'RESERVADO' => 1, 'FIRMADO' => 2, 'VENDIDO' => 3];

/**
 * Mapa de stages del SPA: "categoryId" => ["NOMBRE" => STATUS_ID, ...].
 * Los STATUS_ID difieren por pipeline, así que se resuelve por NOMBRE.
 * Cacheado en /data/stages.json; refrescado por rebuild.php.
 */
function stages_map(bool $force = false): array {
    global $DATA_DIR;
    $path = $DATA_DIR . '/stages.json';
    if (!$force && is_file($path)) {
        $j = json_decode((string)@file_get_contents($path), true);
        if (is_array($j) && $j) return $j;
    }
    // reconstruir desde Bitrix
    $map = [];
    $cats = bx('crm.category.list', ['entityTypeId' => SPA_ENTITY]);
    foreach (($cats['result']['categories'] ?? []) as $c) {
        $cid = (string)$c['id'];
        $st = bx('crm.status.list', ['filter' => ['ENTITY_ID' => 'DYNAMIC_' . SPA_ENTITY . '_STAGE_' . $cid]]);
        $map[$cid] = [];
        foreach (($st['result'] ?? []) as $s) $map[$cid][strtoupper($s['NAME'])] = $s['STATUS_ID'];
    }
    @file_put_contents($path, json_encode($map));
    return $map;
}

/** STATUS_ID del stage `name` para la categoría `cat`, o null. */
function stage_id(string $cat, string $name): ?string {
    static $map = null;
    if ($map === null) $map = stages_map();
    $sid = $map[(string)$cat][strtoupper($name)] ?? null;
    if ($sid === null) {                 // cache viejo (ej stage recién creada) -> refrescar 1 vez
        $map = stages_map(true);
        $sid = $map[(string)$cat][strtoupper($name)] ?? null;
    }
    return $sid;
}

/** Nombre del stage actual de una unidad (item), o null. */
function unit_stage_name(array $item): ?string {
    static $rev = null;
    if ($rev === null) {
        $rev = [];
        foreach (stages_map() as $cat => $m) foreach ($m as $name => $sid) $rev[$sid] = $name;
    }
    return $rev[$item['stageId'] ?? ''] ?? null;
}

/**
 * Aplica un stage objetivo a una unidad, con guardas. Devuelve true si cambió.
 * $writeOff = true cuando el disparo es COBRANZAS DADO DE BAJA (único que saca de VENDIDO).
 */
function apply_unit_stage(int $unitId, ?array $item, string $targetName, bool $writeOff = false): bool {
    if ($item === null) {
        $r = bx('crm.item.get', ['entityTypeId' => SPA_ENTITY, 'id' => $unitId]);
        if (!$r['ok']) return false;
        $item = $r['result']['item'] ?? $r['result'];
    }
    $cat = (string)($item['categoryId'] ?? '');
    $curName = unit_stage_name($item);

    // no tocar BLOQUEADO ni PERDIDO (son manuales/gerenciales)
    if (in_array($curName, ['BLOQUEADO', 'PERDIDO'], true)) return false;
    // proteger VENDIDO: solo un write-off (DADO DE BAJA) lo saca
    if ($curName === 'VENDIDO' && !$writeOff) return false;
    if ($curName === $targetName) return false;             // ya está

    $target = stage_id($cat, $targetName);
    if ($target === null) { logline("WARN stage '$targetName' no existe en cat $cat (unit $unitId)"); return false; }

    // Marca de ESCRITURA PROPIA, justo antes de escribir. El guardián del kanban la
    // lee para saber que este cambio de stage lo hizo el sistema y no una persona.
    // Sin ella habría que preguntar la etapa del deal dueño en CADA evento de
    // unidad, y el propio sistema mueve cientos: el portal ya va al tope de API.
    @touch((getenv('DATA_DIR') ?: '/data') . '/self_u_' . $unitId);

    $u = bx('crm.item.update', ['entityTypeId' => SPA_ENTITY, 'id' => $unitId, 'fields' => ['stageId' => $target]]);
    if ($u['ok']) { logline("STAGE unit=$unitId $curName -> $targetName"); return true; }
    logline("ERR stage unit=$unitId -> $targetName: {$u['error']}");
    return false;
}

/**
 * Copia el RESPONSABLE (ASSIGNED_BY_ID = asesor) y el CLIENTE (CONTACT_ID) del deal
 * a la unidad, solo si difieren (evita reescrituras). El deal manda. Devuelve true si cambió.
 */
function sync_unit_owner(int $unitId, array $deal): bool {
    $assigned = $deal['ASSIGNED_BY_ID'] ?? null;
    $contact  = $deal['CONTACT_ID'] ?? null;
    if (!$assigned && !($contact && (int)$contact > 0)) return false;
    $r = bx('crm.item.get', ['entityTypeId' => SPA_ENTITY, 'id' => $unitId]);
    if (!$r['ok']) return false;
    $it = $r['result']['item'] ?? $r['result'];
    $need = [];
    if ($assigned && (string)($it['assignedById'] ?? '') !== (string)$assigned) $need['assignedById'] = $assigned;
    if ($contact && (int)$contact > 0 && (string)($it['contactId'] ?? '') !== (string)$contact) $need['contactId'] = $contact;
    if (!$need) return false;
    $u = bx('crm.item.update', ['entityTypeId' => SPA_ENTITY, 'id' => $unitId, 'fields' => $need]);
    if ($u['ok']) { logline("OWNER unit=$unitId set " . implode(',', array_keys($need))); return true; }
    return false;
}

/**
 * Unidades (ids) atadas a un deal de Clientes.
 * Fuentes: parentId2=deal, el campo "Inventario" nuevo, y PARENT_ID_1072.
 *
 * Aquí NO se hace `select` a propósito: con select explícito Bitrix devuelve los
 * ids en null (bug verificado), y esta lista se quedaba vacía. Se sostenía sola
 * por PARENT_ID_1072 — que ya no se llena — así que sin el campo nuevo el
 * re-afirmado de stages del barrido se quedaba sin ninguna fuente.
 */
function units_of_clientes_deal(string $dealId, ?array $deal = null): array {
    $ids = [];
    $r = bx('crm.item.list', ['entityTypeId' => SPA_ENTITY, 'filter' => ['parentId2' => $dealId]]);
    if ($r['ok']) foreach (($r['result']['items'] ?? []) as $it) {
        if (!empty($it['id'])) $ids[(int)$it['id']] = true;
    }
    if ($deal === null) { $g = bx('crm.deal.get', ['id' => $dealId]); if ($g['ok']) $deal = $g['result']; }

    foreach (preg_split('/[,;\s]+/', (string)($deal['UF_CRM_1785205972989'] ?? '')) as $x) {
        $x = trim($x);
        if ($x !== '' && ctype_digit($x) && (int)$x > 0) $ids[(int)$x] = true;
    }
    $p = (int)($deal['PARENT_ID_1072'] ?? 0);
    if ($p > 0) $ids[$p] = true;
    return array_keys($ids);
}

/**
 * ¿Puede este deal soltar esta unidad?
 *
 * Solo si la unidad no es de nadie o ya es de este deal. Sin esto, un deal en
 * RESERVAS CAIDAS / FIRMADOS-CAIDOS que todavía nombra una unidad que ya se
 * revendió a OTRO deal la pondría en DISPONIBLE, robándosela al dueño nuevo — y
 * el barrido la volvería a pelear cada 15 minutos.
 */
function puede_liberar(int $unitId, string $dealId): bool {
    $r = bx('crm.item.get', ['entityTypeId' => SPA_ENTITY, 'id' => $unitId]);
    if (!$r['ok']) return false;                        // si no se sabe, no se toca
    $it = $r['result']['item'] ?? $r['result'];
    $dueno = (int)($it['parentId2'] ?? 0);
    return $dueno === 0 || $dueno === (int)$dealId;
}

/** Aplica la transición de un deal de CLIENTES (44) a sus unidades. */
function clientes_stage_apply(string $dealId, array $deal): int {
    $stage = (string)($deal['STAGE_ID'] ?? '');
    if (!isset(CLIENTES_TRIGGERS[$stage])) return 0;    // stage no dispara nada
    $target = CLIENTES_TRIGGERS[$stage];
    $n = 0;
    foreach (units_of_clientes_deal($dealId, $deal) as $uid) {
        if ($target === 'DISPONIBLE' && !puede_liberar((int)$uid, $dealId)) continue;
        if (apply_unit_stage($uid, null, $target, false)) $n++;
    }
    return $n;
}

// ---------------------------------------------------------------------------
// APARTADOS de Prospectos(28)
// Viven aquí y no en campolib.php porque reconcile.php necesita usarlas y NO
// puede cargar campolib (ambos declaran bx()/logline() y chocarían). stagelib sí
// lo cargan los tres puntos de entrada, cada uno con su propio bx().
// Las constantes van con guarda: cada entrada define las suyas.
// ---------------------------------------------------------------------------
if (!defined('PROSPECTOS_CAT')) define('PROSPECTOS_CAT', 28);
if (!defined('APARTADOS_FILE')) define('APARTADOS_FILE', 'apartados_puestos.json');
if (!defined('CAMPO_INV'))      define('CAMPO_INV', 'UF_CRM_1785205972989');

if (!function_exists('ids_de')) {
    /** IDs del valor del campo ("581,623"). */
    function ids_de(string $v): array {
        $out = [];
        foreach (preg_split('/[,;\s]+/', $v) as $x) {
            $x = trim($x);
            if ($x !== '' && ctype_digit($x) && (int)$x > 0) $out[] = (int)$x;
        }
        return array_values(array_unique($out));
    }
}

/**
 * Etapas del pipeline 28 que TODAVÍA apartan (las cerradas-perdidas ya no).
 * Se cachea porque son 27 etapas y no cambian.
 */
function etapas_28_activas(): array {
    global $DATA_DIR;
    $path = $DATA_DIR . '/stages28.json';
    $j = json_decode((string)@file_get_contents($path), true);
    if (is_array($j) && $j) return $j;

    $r = bx('crm.status.list', ['filter' => ['ENTITY_ID' => 'DEAL_STAGE_' . PROSPECTOS_CAT]]);
    $out = [];
    // SEMANTICS: 'F' = perdida, 'S' = ganada, vacío = en proceso. Solo las
    // perdidas dejan de apartar: una ganada (RESERVA) sigue apartando hasta que
    // la copia de CLIENTES tome la unidad de verdad.
    $reserva = ''; $ganada = '';
    if ($r['ok']) foreach (($r['result'] ?? []) as $s) {
        if ((string)($s['SEMANTICS'] ?? '') !== 'F') $out[] = (string)$s['STATUS_ID'];
        // De paso se resuelve RESERVA: es la MISMA llamada, así etapa_28_reserva()
        // no gasta una segunda contra el cupo del portal.
        if (strtoupper(trim((string)($s['NAME'] ?? ''))) === 'RESERVA') $reserva = (string)$s['STATUS_ID'];
        if ((string)($s['SEMANTICS'] ?? '') === 'S' && $ganada === '')   $ganada  = (string)$s['STATUS_ID'];
    }
    if ($out) @file_put_contents($path, json_encode($out));
    if ($reserva !== '' || $ganada !== '') {
        @file_put_contents($DATA_DIR . '/reserva28.txt', $reserva ?: $ganada);
    }
    return $out;
}

/**
 * STATUS_ID de la etapa RESERVA en PROSPECTOS(28).
 *
 * La unidad solo se puede elegir ahí (regla de negocio, jul-2026). Se resuelve
 * POR NOMBRE y no se hardcodea: si mañana renombran o recrean la etapa, el
 * candado sigue apuntando a la correcta en vez de bloquear el pipeline entero.
 * El valor lo deja escrito etapas_28_activas() en su propia llamada, así que
 * esto normalmente NO le cuesta nada al API.
 */
function etapa_28_reserva(): string {
    global $DATA_DIR;
    $path = $DATA_DIR . '/reserva28.txt';
    $c = trim((string)@file_get_contents($path));
    if ($c !== '') return $c;

    etapas_28_activas();                    // resuelve y cachea de paso
    $c = trim((string)@file_get_contents($path));
    // 'C28:WON' = única etapa ganada del 28 (verificado en vivo). Solo se usa si
    // el API no respondió; no se cachea, para que el próximo intento reintente.
    return $c !== '' ? $c : 'C28:WON';
}

/**
 * Unidades APARTADAS desde Prospectos(28): unitId => [deal, contacto, creado].
 *
 * Un apartado no es una reserva: no escribe parentId2 ni crea dependencia. Solo
 * evita que otro vendedor escoja la misma unidad mientras se cierra el acuerdo,
 * y deja pasar a la copia del deal en CLIENTES.
 *
 * Si dos deals del 28 nombran la misma unidad gana el MÁS RECIENTE — la misma
 * regla que usa referidor.php para emparejar copias entre pipelines.
 */
function apartados_28(?bool &$fiable = null): array {
    // $fiable = false si alguna página falló: la lista quedó incompleta y NO se
    // puede usar para decidir liberaciones. Sin esto, un hipo del API devolvía la
    // lista a medias y el barrido soltaba TODOS los apartados vigentes.
    $fiable = true;
    $activas = etapas_28_activas();
    if (!$activas) $fiable = false;          // sin el mapa de etapas no se filtra bien
    $out = [];
    $start = 0;
    do {
        $r = bx('crm.deal.list', [
            'filter' => ['CATEGORY_ID' => PROSPECTOS_CAT, '!' . CAMPO_INV => ''],
            'select' => ['ID', 'STAGE_ID', 'CONTACT_ID', 'DATE_CREATE', CAMPO_INV],
            'order'  => ['ID' => 'ASC'],
            'start'  => $start,
        ]);
        if (!$r['ok']) { $fiable = false; return $out; }
        foreach (($r['result'] ?? []) as $d) {
            if ($activas && !in_array((string)($d['STAGE_ID'] ?? ''), $activas, true)) continue;
            $cand = ['deal'     => (int)$d['ID'],
                     'contacto' => (int)($d['CONTACT_ID'] ?? 0),
                     'creado'   => (string)($d['DATE_CREATE'] ?? '')];
            foreach (ids_de((string)($d[CAMPO_INV] ?? '')) as $u) {
                $prev = $out[$u] ?? null;
                if (!$prev
                    || strcmp($cand['creado'], $prev['creado']) > 0
                    || ($cand['creado'] === $prev['creado'] && $cand['deal'] > $prev['deal'])) {
                    $out[$u] = $cand;
                }
            }
        }
        $start = $r['next'] ?? null;
    } while ($start !== null && $start !== '');
    return $out;
}

/** Registro de las unidades que ESTE sistema puso en RESERVADO por un apartado del 28. */
function apartados_puestos(): array {
    global $DATA_DIR;
    $j = json_decode((string)@file_get_contents($DATA_DIR . '/' . APARTADOS_FILE), true);
    return is_array($j) ? $j : [];
}
function apartados_puestos_guardar(array $m): void {
    global $DATA_DIR;
    @file_put_contents($DATA_DIR . '/' . APARTADOS_FILE, json_encode($m), LOCK_EX);
}

/**
 * Devuelve una unidad apartada a DISPONIBLE. No la toca si CLIENTES ya la ató
 * de verdad (tiene parentId2): en ese caso el apartado quedó superado, no roto.
 */
function liberar_apartado(int $unitId): bool {
    $r = bx('crm.item.get', ['entityTypeId' => SPA_ENTITY, 'id' => $unitId]);
    if (!$r['ok']) return false;
    $it = $r['result']['item'] ?? $r['result'];
    if ((int)($it['parentId2'] ?? 0) !== 0) return false;      // ya es reserva real
    bx('crm.item.update', ['entityTypeId' => SPA_ENTITY, 'id' => $unitId,
                           'fields' => ['contactId' => 0]]);
    return apply_unit_stage($unitId, null, 'DISPONIBLE', false);
}

