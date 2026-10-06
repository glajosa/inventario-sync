<?php
/**
 * hook48lib.php — COBRANZAS(48) DADO DE BAJA / PAGADO TOTALMENTE -> la UNIDAD al instante.
 * ---------------------------------------------------------------------------
 * Jesua, 6-oct-2026: "hay que cambiar eso, que es cada 15 minutos... justamente por eso está la libreta central.
 * Hágalo real". Hasta hoy solo lo hacía reconcile.php cada 15 min (hook.php ignora el 48 a propósito: ~17.000
 * avisos de cobranzas al día). Ahora la libreta reparte SOLO los cambios de STAGE_ID del 48 a hook48.php.
 *
 * Camino aprobado por el orquestador (VIGILANTE BITRIX, 6-oct), con sus 5 ajustes:
 *  1) la unidad se encuentra por el PAR DE IDs: 48.UF_CRM_ID_DEAL_CLIENTES -> su 44 -> la unidad atada a ese 44, y
 *     se comprueba que su código sea el ACTIVO COMPRADO del 48. Código + contacto solo si el par está vacío.
 *     0 o 2+ candidatas -> no escribe y avisa (por código y contacto se rompió el 9610).
 *  2) se relee la unidad y no se toca si está atada a OTRO deal (parentId2 ni 0 ni el 44 del par).
 *  3) la VUELTA: si el 48 sale de DADO DE BAJA a una etapa viva, la unidad vuelve a la etapa que tenía.
 *  5) NUNCA se escribe en el 48. Escrituras: 0 o 1 por aviso, solo en la unidad, con la marca self_u_ de siempre.
 * Perillas (env): HOOK48_ON (0 = no hace nada), HOOK48_VUELTA_ON (1).
 */

declare(strict_types=1);

/** PURA. Código comparable: "C1-1 (Galero Torre C)" y "C-1-1" dan lo mismo (codigo_activo + mayúsculas sin espacios). */
function h48_cod(string $s): string {
    $c = trim(explode('(', $s)[0]);
    $c = preg_replace('/^([A-Za-z]+)(?=\d)/', '$1-', $c) ?? $c;
    return strtoupper(str_replace(' ', '', $c));
}

/** PURA. De las unidades candidatas, la ÚNICA cuyo código es el del 48 y, si el 48 trae proyecto, de ESE proyecto
 *  (orquestador 6-oct: un cliente con varias compras puede tener el mismo código en dos proyectos — la C-3-7 de Marcel).
 *  Cada item trae '_proy' (proyecto_de_unidad, 0 = no se sabe). ['id'=>..] | ['error'=>...]. */
function h48_elegir(array $items, string $activo48, int $proy48 = 0): array {
    $k = h48_cod($activo48);
    if ($k === '' || !preg_match('/\d/', $k)) return ['error' => 'el_48_no_tiene_activo_comprado'];
    $c = array_values(array_filter($items, fn($it) => h48_cod((string)($it['title'] ?? '')) === $k));
    if (!$c) return ['error' => 'sin_unidad'];
    if ($proy48 > 0) {
        $c = array_values(array_filter($c, fn($it) => (int)($it['_proy'] ?? 0) === 0 || (int)$it['_proy'] === $proy48));
        if (!$c) return ['error' => 'la_unidad_es_de_otro_proyecto'];
    }
    if (count($c) > 1) return ['error' => 'varias_unidades', 'ids' => array_map(fn($it) => (int)$it['id'], $c)];
    return ['id' => (int)$c[0]['id'], 'item' => $c[0]];
}

/** PURA. ¿Qué hacer con este aviso? 'aplicar' (LOSE/WON) · 'vuelta' (sale de una baja que soltó la unidad) · 'nada'. */
function h48_accion(string $stage, ?array $estado, array $triggers): string {
    if (isset($triggers[$stage])) return 'aplicar';
    // la vuelta NO aplica si sale de la baja hacia REUBICACIÓN: ahí la unidad vieja se suelta a propósito
    // (el flujo de Jesua es baja -> cambio de unidad), y devolverla la reservaría de nuevo.
    if ($estado !== null && ($estado['por'] ?? '') === 'C48:LOSE' && $stage !== 'C48:LOSE' && $stage !== 'C48:UC_1WR2BM') return 'vuelta';
    return 'nada';
}

/** PURA. ¿La unidad es de este deal? (guarda 2) — sin dueño o atada al 44 del par. */
function h48_es_suya(array $item, int $deal44): bool {
    $p = (int)($item['parentId2'] ?? 0);
    return $p === 0 || ($deal44 > 0 && $p === $deal44);
}

/** El dueño de la unidad, ¿es el 44 de ESTE cliente? (deals viejos sin par: la unidad cuelga de su 44, que no es "otro"). */
function h48_dueno_es_hermano(int $p, int $contacto): bool {
    if ($p <= 0 || $contacto <= 0) return false;
    $r = reub_libreta('/deal/' . $p);
    $x = ($r['status'] === 200 && is_array($r['json'])) ? ($r['json']['dato'] ?? $r['json']) : null;
    if (!is_array($x) || !isset($x['CATEGORY_ID'])) { $g = bx('crm.deal.get', ['id' => $p]); $x = $g['ok'] ? (array)$g['result'] : null; }
    return is_array($x) && (int)($x['CATEGORY_ID'] ?? -1) === CLIENTES_CAT && (int)($x['CONTACT_ID'] ?? 0) === $contacto;
}

// ── ESCALERA de DADO DE BAJA (Jesua 6-oct): 44 -> FIRMADOS-CAÍDOS, ficha de FAMILIA -> CLIENTE CAÍDO, al instante ──
const H48_44_CAIDO = 'C44:APOLOGY';      // FIRMADOS - CAIDOS
const H48_58_CAIDO = 'C58:UC_DIEZL7';    // CLIENTE CAIDO
/** PURA. ¿Etapa de pérdida? (no se mueve lo que ya cayó) */
function h48_es_caida(string $stage): bool {
    return (bool)preg_match('/:(LOSE|APOLOGY)$/', $stage) || in_array($stage, ['C58:UC_DIEZL7', 'C58:UC_2FYWWL', 'C58:UC_RQT228'], true);
}
/** Etapas del 44 desde las que una baja lo lleva a FIRMADOS-CAÍDOS: una venta VIVA. Incluye CIERRE DE PROMESA (WON):
 *  Jesua 6-oct, "si ya están en promesa firmada por cliente... y en cierre de promesa... se mueven a firmados caídos"
 *  (el orquestador lo había excluido; decide Jesua). CESIONES, CANJE u otra etapa rara NO se mueve: lo mira una persona. */
const H48_44_DESDE = ['C44:NEW', 'C44:UC_Z3GY5H', 'C44:UC_4R587H', 'C44:UC_2CE2UE', 'C44:UC_N637MD', 'C44:WON'];
/** PURA. El 44 se mueve solo si nombra ESA unidad y está en una etapa de venta viva. */
function h48_44_mover(array $d44, string $cod): bool {
    return h48_cod((string)($d44[D_ACTIVO] ?? '')) === h48_cod($cod) && h48_cod($cod) !== ''
        && in_array((string)($d44['STAGE_ID'] ?? ''), H48_44_DESDE, true);
}
/** PURA. Las fichas de FAMILIA de ESA compra: mismo código Y proyecto (ficha sin proyecto: alcanza el código).
 *  Una ficha compartida (ACTIVO vacío) o de otra compra NO se toca: el cliente puede tener otra compra viva. */
function h48_familia(array $deals, string $cod, int $proy): array {
    $k = h48_cod($cod); if ($k === '') return [];
    $out = [];
    foreach ($deals as $f) {
        if ((int)($f['CATEGORY_ID'] ?? -1) !== 58) continue;
        if (h48_cod((string)($f[D_ACTIVO] ?? '')) !== $k) continue;
        $pf = (int)($f[D_PROYECTO] ?? 0);
        if ($pf > 0 && $proy > 0 && $pf !== $proy) continue;
        if (h48_es_caida((string)($f['STAGE_ID'] ?? ''))) continue;
        $out[] = (int)$f['ID'];
    }
    return $out;
}

/** Escritura de la escalera. En SECO (?seco) no escribe: anota en $GLOBALS['H48_PLAN'] lo que haría. */
function h48_w(string $metodo, array $params, string $desc): array {
    if (!empty($GLOBALS['H48_SECO'])) { $GLOBALS['H48_PLAN'][] = $desc; return ['ok' => true, 'seco' => true]; }
    return bx($metodo, $params);
}
/** 1) (orquestador) quién lo movió queda en la ficha: un comentario en la línea de tiempo. */
function h48_comentar(int $id, string $txt, string $desc): void {
    h48_w('crm.timeline.comment.add', ['fields' => ['ENTITY_ID' => $id, 'ENTITY_TYPE' => 'deal', 'COMMENT' => $txt]], $desc);
}

/** La escalera de una baja. Devuelve lo que movió (para la vuelta). 0-3 crm.deal.update, nunca en el 48. */
function h48_escalera(int $d, array $deal, int $d44, string $cod): array {
    if (getenv('HOOK48_ESCALERA_ON') !== '1') return [];
    $c = (int)($deal['CONTACT_ID'] ?? 0); $proy = (int)($deal[D_PROYECTO] ?? 0);
    $r = reub_libreta('/deals?contact=' . $c);
    if ($r['status'] !== 200 || !is_array($r['json'])) { h48_frenar($d, 'escalera: no pude leer los deals del contacto ' . $c . ' (libreta http ' . $r['status'] . ')'); return []; }
    $deals = array_map(fn($it) => (array)($it['dato'] ?? $it), (array)($r['json']['items'] ?? []));
    $mov = [];
    // 44: el del par / hermano; si no hay, el ÚNICO 44 del contacto que nombra la unidad
    if ($d44 <= 0) {
        $c44 = array_values(array_filter($deals, fn($x) => (int)($x['CATEGORY_ID'] ?? -1) === 44 && h48_44_mover($x, $cod)));
        if (count($c44) === 1) $d44 = (int)$c44[0]['ID'];
        elseif (count($c44) > 1) h48_frenar($d, 'escalera: varios 44 nombran ' . $cod . ' -> no muevo clientes');
    }
    if ($d44 > 0) {
        $x = null; foreach ($deals as $y) if ((int)($y['ID'] ?? 0) === $d44) $x = $y;
        if ($x && !h48_44_mover($x, $cod) && !h48_es_caida((string)($x['STAGE_ID'] ?? ''))) {
            h48_frenar($d, "escalera: el 44 $d44 está en " . (string)($x['STAGE_ID'] ?? '?') . ' (no es una venta viva o no nombra ' . $cod . ') -> no lo muevo, que lo mire una persona');
        }
        if ($x && h48_44_mover($x, $cod)) {
            $u = h48_w('crm.deal.update', ['id' => $d44, 'fields' => ['STAGE_ID' => H48_44_CAIDO]], "44 $d44: " . (string)$x['STAGE_ID'] . ' -> ' . H48_44_CAIDO . ' (FIRMADOS-CAIDOS)');
            if ($u['ok']) { $mov['44'] = ['id' => $d44, 'antes' => (string)$x['STAGE_ID']];
                h48_comentar($d44, "Movido a FIRMADOS-CAÍDOS por la baja del deal de cobranzas $d (sistema).", "comentario en 44 $d44"); }
            logline("HOOK48 deal=$d escalera: clientes $d44 " . (string)$x['STAGE_ID'] . ' -> FIRMADOS-CAIDOS ' . ($u['ok'] ? 'ok' : 'ERR ' . $u['error']));
        }
    }
    foreach (h48_familia($deals, $cod, $proy) as $fid) {
        $x = null; foreach ($deals as $y) if ((int)($y['ID'] ?? 0) === $fid) $x = $y;
        $u = h48_w('crm.deal.update', ['id' => $fid, 'fields' => ['STAGE_ID' => H48_58_CAIDO]], "58 $fid: " . (string)($x['STAGE_ID'] ?? '?') . ' -> ' . H48_58_CAIDO . ' (CLIENTE CAIDO)');
        if ($u['ok']) { $mov['58'][] = ['id' => $fid, 'antes' => (string)($x['STAGE_ID'] ?? '')];
            h48_comentar($fid, "Movido a CLIENTE CAÍDO por la baja del deal de cobranzas $d (sistema).", "comentario en 58 $fid"); }
        logline("HOOK48 deal=$d escalera: familia $fid " . (string)($x['STAGE_ID'] ?? '?') . ' -> CLIENTE CAIDO ' . ($u['ok'] ? 'ok' : 'ERR ' . $u['error']));
    }
    return $mov;
}

/** La vuelta de la escalera: cada deal vuelve a su etapa SOLO si sigue en la que lo dejamos (no se pisa a una persona). */
function h48_escalera_vuelta(int $d, array $mov): void {
    $pares = [];
    if (!empty($mov['44'])) $pares[] = [$mov['44'], H48_44_CAIDO];
    foreach ((array)($mov['58'] ?? []) as $f) $pares[] = [$f, H48_58_CAIDO];
    foreach ($pares as [$m, $puesta]) {
        $g = bx('crm.deal.get', ['id' => (int)$m['id']]);
        if (!$g['ok'] || (string)($g['result']['STAGE_ID'] ?? '') !== $puesta) { logline("HOOK48 deal=$d vuelta escalera: {$m['id']} ya no está en $puesta -> no lo toco"); continue; }
        $u = bx('crm.deal.update', ['id' => (int)$m['id'], 'fields' => ['STAGE_ID' => (string)$m['antes']]]);
        logline("HOOK48 deal=$d vuelta escalera: {$m['id']} -> {$m['antes']} " . ($u['ok'] ? 'ok' : 'ERR ' . $u['error']));
    }
}

function h48_estado_file(int $d): string { return (getenv('DATA_DIR') ?: '/data') . '/hook48/' . $d . '.json'; }
function h48_estado(int $d): ?array { $f = h48_estado_file($d); $j = is_file($f) ? json_decode((string)@file_get_contents($f), true) : null; return is_array($j) ? $j : null; }
function h48_estado_guardar(int $d, ?array $e): void {
    $f = h48_estado_file($d); @mkdir(dirname($f), 0775, true);
    if ($e === null) { @unlink($f); return; }
    @file_put_contents($f, json_encode($e, JSON_UNESCAPED_UNICODE), LOCK_EX);
}

/** "No escribí" visible: log + monitor. */
function h48_frenar(int $d, string $motivo): array {
    logline("HOOK48 deal=$d FRENADO (no se escribió): $motivo");
    $url = rtrim((string)(getenv('CONTROL_URL') ?: 'https://galjosa-bitrix-control.pwluu1.easypanel.host'), '/');
    $ch = curl_init($url . '/api/falla.php');
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 2, CURLOPT_CONNECTTIMEOUT => 2,
        CURLOPT_POSTFIELDS => http_build_query(['sistema' => 'inventario-hook48', 'fallas' => json_encode([[
            'metodo' => 'unidad_por_etapa_48', 'id' => $d, 'tipo' => 'escritura', 'error' => mb_substr($motivo, 0, 300),
            'destino' => 'reintenta', 'nota' => 'la unidad no se movio; el reconcile de 15 min es la red']], JSON_UNESCAPED_UNICODE)])]);
    @curl_exec($ch); unset($ch);
    return ['ok' => false, 'error' => $motivo];
}

/** El deal 48 desde la LIBRETA (Bitrix solo si la libreta no lo tiene, y se dice por qué). */
function h48_deal(int $d): ?array {
    $r = reub_libreta('/deal/' . $d);
    if ($r['status'] === 200 && is_array($r['json'])) {
        $x = $r['json']['dato'] ?? $r['json'];
        if (is_array($x) && isset($x['STAGE_ID'])) return $x;
    }
    logline("HOOK48 deal=$d libreta http {$r['status']} -> leo de Bitrix");
    $g = bx('crm.deal.get', ['id' => $d]);
    return $g['ok'] ? (array)$g['result'] : null;
}

/** A cada unidad le anota su proyecto ('_proy'), con la misma tabla que usa el campo (MAPA_PROYECTO). */
function h48_con_proyecto(array $items): array {
    foreach ($items as $i => $it) $items[$i]['_proy'] = proyecto_de_unidad((int)($it['categoryId'] ?? 0), $it[U_TIPO] ?? null);
    return $items;
}

/** Las unidades candidatas: por el PAR (la unidad atada al 44); por código + contacto solo si el par está vacío. */
function h48_candidatas(array $deal): array {
    $d44 = (int)($deal['UF_CRM_ID_DEAL_CLIENTES'] ?? 0);
    if ($d44 > 0) {
        $r = bx('crm.item.list', ['entityTypeId' => SPA_ENTITY, 'filter' => ['parentId2' => $d44]]);
        if (!$r['ok']) return ['error' => 'no_pude_leer_las_unidades_del_44'];
        return ['por' => 'par', 'd44' => $d44, 'items' => h48_con_proyecto((array)($r['result']['items'] ?? []))];
    }
    $c = (int)($deal['CONTACT_ID'] ?? 0);
    if ($c <= 0) return ['error' => 'sin_par_y_sin_contacto'];
    $r = bx('crm.item.list', ['entityTypeId' => SPA_ENTITY, 'filter' => ['contactId' => $c]]);
    if (!$r['ok']) return ['error' => 'no_pude_leer_las_unidades_del_contacto'];
    return ['por' => 'codigo+contacto', 'd44' => 0, 'items' => h48_con_proyecto((array)($r['result']['items'] ?? []))];
}

/** El aviso de un 48. Idempotente: apply_unit_stage no escribe si la unidad ya está en el objetivo. */
function h48_procesar(int $d): array {
    $deal = h48_deal($d);
    if (!$deal) return h48_frenar($d, 'no pude leer el deal');
    if ((int)($deal['CATEGORY_ID'] ?? -1) !== COBRANZAS_CAT) return ['nada' => 'no_es_48'];
    $stage  = (string)($deal['STAGE_ID'] ?? '');
    // 6-oct: la unidad elegida FUERA de la etapa (pendiente) se aplica cuando el 48 ENTRA a REUBICACIÓN
    if ($stage === 'C48:UC_1WR2BM' && reub_pend_leer($d) !== null) {
        if (!array_key_exists(CAMPO_NUEVO, $deal)) {   // la copia no trae el campo: se lee de Bitrix (vacío NO es "lo cambiaron")
            $g = bx('crm.deal.get', ['id' => $d]); if (!$g['ok']) return h48_frenar($d, 'pendiente: no pude leer el campo Inventario');
            $deal[CAMPO_NUEVO] = $g['result'][CAMPO_NUEVO] ?? '';
        }
        return reub_pend_procesar($d, $stage, (string)($deal[CAMPO_NUEVO] ?? ''));
    }
    $estado = h48_estado($d);
    $acc    = h48_accion($stage, $estado, COBRANZAS_TRIGGERS);
    if ($acc === 'nada') return ['nada' => $stage];

    if ($acc === 'vuelta') {
        if (getenv('HOOK48_VUELTA_ON') === '0') return ['nada' => 'vuelta_apagada'];
        $uid = (int)$estado['unidad']; $antes = (string)$estado['antes'];
        $g = bx('crm.item.get', ['entityTypeId' => SPA_ENTITY, 'id' => $uid]);
        if (!$g['ok']) return h48_frenar($d, "vuelta: no pude releer la unidad $uid");
        $it = $g['result']['item'] ?? $g['result'];
        // solo si sigue como la dejamos: DISPONIBLE y sin dueño nuevo. Si otro la tomó, es suya.
        // y el 48 tiene que seguir nombrando ESA unidad: si le cambiaron el ACTIVO COMPRADO, fue una reubicación
        if (h48_cod((string)($deal[D_ACTIVO] ?? '')) !== h48_cod((string)($it['title'] ?? ''))) {
            h48_estado_guardar($d, null);
            return ['nada' => 'vuelta_otra_unidad'];
        }
        if (unit_stage_name($it) !== 'DISPONIBLE' || !h48_es_suya($it, (int)($estado['d44'] ?? 0))) {
            h48_estado_guardar($d, null);
            return h48_frenar($d, "vuelta: la unidad $uid ya no está como la dejó la baja (" . unit_stage_name($it) . ", dueño " . (int)($it['parentId2'] ?? 0) . ') -> no la toco');
        }
        $ok = apply_unit_stage($uid, $it, $antes, false);
        if (!empty($estado['escalera'])) h48_escalera_vuelta($d, (array)$estado['escalera']);
        h48_estado_guardar($d, null);
        logline("HOOK48 deal=$d VUELTA de DADO DE BAJA a $stage: unidad $uid -> $antes " . ($ok ? 'ok' : '(sin cambio)'));
        return ['ok' => true, 'vuelta' => $uid, 'a' => $antes];
    }

    // aplicar: LOSE -> DISPONIBLE, WON -> VENDIDO. Una vuelta anotada de una baja anterior ya no vale.
    $target = COBRANZAS_TRIGGERS[$stage];
    if ($stage !== 'C48:LOSE' && $estado !== null) h48_estado_guardar($d, null);
    $cand = h48_candidatas($deal);
    if (isset($cand['error'])) return h48_frenar($d, $cand['error']);
    $e = h48_elegir($cand['items'], (string)($deal[D_ACTIVO] ?? ''), (int)($deal[D_PROYECTO] ?? 0));
    if (isset($e['error'])) return h48_frenar($d, $e['error'] . ' (por ' . $cand['por'] . ')' . (isset($e['ids']) ? ' ' . implode(',', $e['ids']) : ''));
    $uid = $e['id'];
    $g = bx('crm.item.get', ['entityTypeId' => SPA_ENTITY, 'id' => $uid]);           // guarda 2: se relee
    if (!$g['ok']) return h48_frenar($d, "no pude releer la unidad $uid");
    $it = $g['result']['item'] ?? $g['result'];
    $d44 = (int)$cand['d44'];
    if (!h48_es_suya($it, $d44)) {
        // sin par (deal viejo): si la unidad cuelga del 44 de ESTE mismo cliente, es suya
        if ($d44 === 0 && h48_dueno_es_hermano((int)$it['parentId2'], (int)($deal['CONTACT_ID'] ?? 0))) $d44 = (int)$it['parentId2'];
        else return h48_frenar($d, "la unidad $uid está atada a OTRO deal (" . (int)$it['parentId2'] . ') -> no la toco');
    }
    $antes = (string)unit_stage_name($it);
    if (!empty($GLOBALS['H48_SECO'])) { $GLOBALS['H48_PLAN'][] = "unidad $uid: $antes -> $target" . ($antes === $target ? ' (ya estaba)' : ''); $ok = false; }
    else $ok = apply_unit_stage($uid, $it, $target, $stage === 'C48:LOSE');
    $mov = $stage === 'C48:LOSE' ? h48_escalera($d, $deal, $d44, (string)($deal[D_ACTIVO] ?? '')) : [];
    if (empty($GLOBALS['H48_SECO']) && $stage === 'C48:LOSE' && ($ok || $mov))
        h48_estado_guardar($d, ['por' => 'C48:LOSE', 'unidad' => $uid, 'antes' => $ok ? $antes : 'DISPONIBLE', 'd44' => $d44, 'escalera' => $mov, 'ts' => gmdate('c')]);
    logline("HOOK48 deal=$d $stage -> unidad $uid ($antes -> $target) por " . $cand['por'] . ($ok ? '' : ' (ya estaba)'));
    return ['ok' => true, 'unidad' => $uid, 'antes' => $antes, 'a' => $target, 'por' => $cand['por'], 'cambio' => $ok];
}
