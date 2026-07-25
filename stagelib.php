<?php
/**
 * stagelib.php — lógica de STAGE de la unidad según el estado de sus deals.
 * Compartido por hook.php (Clientes 44, tiempo real) y reconcile.php (Cobranzas 48 + barrido).
 *
 * Reglas (deal -> stage de la unidad), definidas por el usuario:
 *   CLIENTES(44) RESERVA                -> RESERVADO
 *   CLIENTES(44) PROMESA FIRMADA CLIENTE-> FIRMADO
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
    'C44:UC_2CE2UE' => 'FIRMADO',     // PROMESA FIRMADA POR CLIENTE
    'C44:APOLOGY'   => 'DISPONIBLE',  // FIRMADOS - CAIDOS
];
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

    $u = bx('crm.item.update', ['entityTypeId' => SPA_ENTITY, 'id' => $unitId, 'fields' => ['stageId' => $target]]);
    if ($u['ok']) { logline("STAGE unit=$unitId $curName -> $targetName"); return true; }
    logline("ERR stage unit=$unitId -> $targetName: {$u['error']}");
    return false;
}

/** Unidades (ids) atadas a un deal de Clientes: parentId2=deal + PARENT_ID_1072 del deal. */
function units_of_clientes_deal(string $dealId, ?array $deal = null): array {
    $ids = [];
    $r = bx('crm.item.list', ['entityTypeId' => SPA_ENTITY, 'filter' => ['parentId2' => $dealId], 'select' => ['id']]);
    if ($r['ok']) foreach (($r['result']['items'] ?? []) as $it) $ids[(int)$it['id']] = true;
    if ($deal === null) { $g = bx('crm.deal.get', ['id' => $dealId]); if ($g['ok']) $deal = $g['result']; }
    $p = (int)($deal['PARENT_ID_1072'] ?? 0);
    if ($p > 0) $ids[$p] = true;
    return array_keys($ids);
}

/** Aplica la transición de un deal de CLIENTES (44) a sus unidades. */
function clientes_stage_apply(string $dealId, array $deal): int {
    $stage = (string)($deal['STAGE_ID'] ?? '');
    if (!isset(CLIENTES_TRIGGERS[$stage])) return 0;    // stage no dispara nada
    $target = CLIENTES_TRIGGERS[$stage];
    $n = 0;
    foreach (units_of_clientes_deal($dealId, $deal) as $uid) {
        if (apply_unit_stage($uid, null, $target, false)) $n++;
    }
    return $n;
}
