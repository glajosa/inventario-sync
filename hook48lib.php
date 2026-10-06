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
    $ok = apply_unit_stage($uid, $it, $target, $stage === 'C48:LOSE');
    if ($ok && $stage === 'C48:LOSE') h48_estado_guardar($d, ['por' => 'C48:LOSE', 'unidad' => $uid, 'antes' => $antes, 'd44' => $d44, 'ts' => gmdate('c')]);
    logline("HOOK48 deal=$d $stage -> unidad $uid ($antes -> $target) por " . $cand['por'] . ($ok ? '' : ' (ya estaba)'));
    return ['ok' => true, 'unidad' => $uid, 'antes' => $antes, 'a' => $target, 'por' => $cand['por'], 'cambio' => $ok];
}
