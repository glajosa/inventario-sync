<?php
/**
 * inventario-sync — preciolib.php
 * ---------------------------------------------------------------------------
 * COBRANZAS(48) manda el PRECIO FINAL de la unidad en el SPA Inventario(1072).
 *
 * Regla del negocio (verbatim del usuario): "el pipeline clientes mueve las
 * unidades en el spa, pero el valor final puede ser mayor o igual, y eso lo manda
 * el deal en cobranzas, y eso se debe de llenar automáticamente en el spa cuando
 * lo coloquen en el deal de cobranzas".
 *
 * Por eso aquí NO se toca el stage, ni parentId2, ni el contacto de la unidad:
 * este módulo escribe UN campo, PRECIO FINAL, y nada más.
 *
 * ── POR QUÉ POR EVENTO Y NO POR CRON ────────────────────────────────────────
 * hook.php YA recibe ONCRMDEALUPDATE de TODOS los deals del portal y descarta con
 * CERO llamadas lo que no está en su lista blanca. Este módulo se cuelga de ese
 * mismo evento, así que no agrega tráfico: solo deja de tirar a la basura los del
 * 48. El precio llega al inventario en segundos, no en la próxima vuelta de un cron.
 *
 * ── CÓMO SABE A QUÉ UNIDAD VA ───────────────────────────────────────────────
 * El payload del webhook solo trae el ID del deal. Emparejar Cobranzas↔Clientes en
 * vivo es caro y frágil (los códigos vienen como "SUN BAY J-12", "L-12 Sunbay",
 * "C-5 || Pelícano 4", agrupados tipo "J-13-30", y el titular de la cobranza a veces
 * es el cónyuge). Así que el emparejamiento se hace EN FRÍO en mapa48.php y aquí
 * solo se consulta el resultado en disco: 0 llamadas para resolver la unidad.
 *   - deal del 48 que no está en el mapa  -> se resuelve al vuelo (1 llamada) y se
 *     memoriza; cubre las copias recién creadas antes del próximo rebuild.
 *   - deal que no es del 48               -> sale sin gastar nada.
 *
 * ── LO QUE NO ESCRIBE, A PROPÓSITO ──────────────────────────────────────────
 *   · precio final MENOR que el PVP  -> la regla es mayor o igual. Se registra.
 *   · una venta de VARIAS unidades   -> un solo precio final para 2+ unidades; falta
 *     la regla de reparto, y escribir el total en cada una inflaría el inventario.
 *   · unidad sin PVP                 -> no hay contra qué validar la regla.
 * Todo eso queda en el log con su motivo, no se pierde en silencio.
 * ---------------------------------------------------------------------------
 */

declare(strict_types=1);

// Lo único que no existía ya en campolib/stagelib. El resto se reutiliza para que
// no haya dos definiciones del mismo campo que puedan divergir:
//   D_ACTIVO = ACTIVO COMPRADO (código) · D_VALOR = VALOR DEL ACTIVO · U_PVP = PVP
//   COBRANZAS_CAT = 48 · CLIENTES_CAT = 44 · CAMPO_NUEVO = campo "Inventario"
const PF_VALOR_FINAL = 'UF_CRM_1779893396954';   // VALOR FINAL DEL ACTIVO (el que manda)
const PF_U_PRECIO    = 'ufCrm25_1784821140126';  // PRECIO FINAL en la unidad del SPA

function pf_dir(): string { return getenv('DATA_DIR') ?: '/data'; }
function pf_log(string $m): void { logline('PRECIO ' . $m); }

/** dinero de Bitrix ("105400|USD") a float. null si viene vacío. */
function pf_money($v): ?float {
    if ($v === null || $v === '' ) return null;
    $s = str_replace(',', '', explode('|', (string)$v)[0]);
    return is_numeric($s) ? round((float)$s, 2) : null;
}
/** float a dinero de Bitrix. Sin decimales si es entero, como lo guarda la UI. */
function pf_fmt(float $v): string {
    return (abs($v - round($v)) < 0.005 ? (string)(int)round($v) : number_format($v, 2, '.', '')) . '|USD';
}

/** JSON de /data con candado, tolerante a archivo inexistente o corrupto. */
function pf_leer(string $f): array {
    $p = pf_dir() . '/' . $f;
    if (!is_file($p)) return [];
    $j = json_decode((string)@file_get_contents($p), true);
    return is_array($j) ? $j : [];
}
function pf_escribir(string $f, array $a): void {
    $p = pf_dir() . '/' . $f;
    $t = $p . '.tmp';
    file_put_contents($t, json_encode($a), LOCK_EX);
    rename($t, $p);   // atómico: nadie lee un archivo a medio escribir
}

/**
 * Mapa deal48 => [ids de unidad]. Lo construye mapa48.php.
 * OJO: el mapa contiene TODOS los deals del 48, incluidos los que no resuelven
 * unidad (con lista vacía). Eso es a propósito: el mapa ES la lista blanca, así
 * que un deal que no está en él simplemente no es del 48 y se descarta sin gastar
 * una sola llamada.
 */
function pf_mapa(): array { return pf_leer('mapa48.json'); }

/** Última cosa que se escribió por deal, para no repetir escrituras idénticas. */
function pf_visto(): array  { return pf_leer('pf_visto.json'); }
function pf_marcar(string $dealId, ?float $v): void {
    $m = pf_visto();
    $m[$dealId] = $v;
    pf_escribir('pf_visto.json', $m);
}

/**
 * Freno de ráfaga. Una edición masiva en Cobranzas dispara cientos de eventos que
 * no tienen nada que ver con el precio; cada uno costaría 1 lectura. Pasado el
 * umbral se sueltan: el precio no se pierde, lo recoge el barrido de mapa48.php.
 * Mismo patrón que el escudo de referidor.php, que ya evitó un incidente real.
 */
function pf_rafaga(): int {
    $f = pf_dir() . '/pf_rate_' . gmdate('YmdHi');
    $n = (int)@file_get_contents($f) + 1;
    @file_put_contents($f, (string)$n, LOCK_EX);
    if ($n === 1) foreach (glob(pf_dir() . '/pf_rate_*') ?: [] as $v) {
        if (basename($v) !== basename($f) && @filemtime($v) < time() - 300) @unlink($v);
    }
    return $n;
}

/**
 * Resuelve al vuelo la unidad de un deal del 48 que todavía no está en el mapa
 * (copia recién creada). Se apoya en el deal de CLIENTES(44) del mismo contacto
 * que tenga el campo Inventario puesto: la unidad la eligió el vendedor allá, aquí
 * solo se lee. 1 llamada. Si hay más de un candidato NO se adivina.
 */
function pf_resolver_al_vuelo(array $deal): array {
    $contacto = (int)($deal['CONTACT_ID'] ?? 0);
    if ($contacto <= 0) return [];
    $r = bx('crm.deal.list', [
        'filter' => ['CONTACT_ID' => $contacto, 'CATEGORY_ID' => CLIENTES_CAT, '!' . CAMPO_NUEVO => ''],
        'select' => ['ID', D_ACTIVO, CAMPO_NUEVO],
    ]);
    if (!$r['ok']) return [];
    $cands = $r['result'] ?? [];
    if (!$cands) return [];
    $cod = pf_cod((string)($deal[D_ACTIVO] ?? ''));
    if ($cod !== '') {
        $ex = [];
        foreach ($cands as $c) if (pf_cod((string)($c[D_ACTIVO] ?? '')) === $cod) $ex[] = $c;
        if (count($ex) === 1) return ids_de((string)($ex[0][CAMPO_NUEVO] ?? ''));
        if (count($ex) > 1)  return [];        // ambiguo: mejor no escribir nada
    }
    if (count($cands) === 1) return ids_de((string)($cands[0][CAMPO_NUEVO] ?? ''));
    return [];
}

/**
 * Normaliza un código de unidad para compararlo.
 * Los separadores IMPORTAN: aplastándolos, "C-12" y "C-1-2" dan lo mismo y se
 * emparejan ventas que no tienen nada que ver (pasó de verdad: casi se le escribe
 * a una unidad de $211.255 el precio de otra de $145.875).
 * "Pelícano 3" es el MODELO de casa, no la unidad, y se va con su número.
 */
function pf_cod(string $s): string {
    $s = strtoupper(trim($s));
    $s = strtr($s, ['Á'=>'A','É'=>'E','Í'=>'I','Ó'=>'O','Ú'=>'U','Ñ'=>'N','á'=>'A','é'=>'E','í'=>'I','ó'=>'O','ú'=>'U']);
    $s = preg_replace('/PELICANO\s*\d*/', ' ', $s);
    $s = preg_replace('/\b(SUN\s*BAY|SUNBAY|NORAL\s*PLAZA|NORAL|BARRANCA|GALERO|ELITE|RIVERSIDE|PLAZA|APARTMENTS?|TERRENOS?|CON|DISTRIBUCION|Y)\b/', ' ', $s);
    $t = array_values(array_filter(preg_split('/[^A-Z0-9]+/', $s) ?: []));
    return implode('-', $t);
}

/** Proyecto según el título ("Cliente--Proyecto (zona)--CÓDIGO"), normalizado. */
function pf_proy(string $titulo): string {
    $t = preg_replace('/^\s*COBRANZAS\s*--\s*/i', '', $titulo);
    $p = array_map('trim', explode('--', $t));
    if (count($p) < 3) return '';
    $s = strtoupper($p[1]);
    $s = strtr($s, ['Á'=>'A','É'=>'E','Í'=>'I','Ó'=>'O','Ú'=>'U','Ñ'=>'N']);
    return preg_replace('/[^A-Z0-9]/', '', $s);
}

/** Título sin el prefijo COBRANZAS, para comparar la copia con su original. */
function pf_base(string $titulo): string {
    $t = preg_replace('/^\s*COBRANZAS\s*--\s*/i', '', $titulo);
    $t = strtoupper($t);
    $t = strtr($t, ['Á'=>'A','É'=>'E','Í'=>'I','Ó'=>'O','Ú'=>'U','Ñ'=>'N']);
    return preg_replace('/[^A-Z0-9]/', '', $t);
}

/**
 * ¿El código $a (una unidad, del 44) está dentro de $b (del 48, que agrupa varias)?
 * Cobranzas junta varias unidades en un solo deal: "J-13" y "J-30" viven en
 * "J-13-30", y "D-1-11-12" + "D-1-23-24" en "D-1-11-12-23-24".
 * Se compara por TRAMOS y con multiconjunto, no con conjunto: si no, "F-4-4"
 * emparejaría con "F-4-1" (pasó de verdad en la primera versión).
 */
function pf_agrupa(string $a, string $b): bool {
    $ta = $a === '' ? [] : explode('-', pf_cod($a));
    $tb = $b === '' ? [] : explode('-', pf_cod($b));
    if (!$ta || !$tb || count($tb) <= count($ta)) return false;
    $i = 0;
    while ($i < count($ta) && $i < count($tb) && $ta[$i] === $tb[$i]) $i++;
    if ($i === 0) return false;                    // sin prefijo común no son del mismo bloque
    $ra = array_count_values(array_slice($ta, $i));
    if (!$ra) return true;                         // "J-36" es prefijo exacto de "J-36-37"
    $rb = array_count_values(array_slice($tb, $i));
    foreach ($ra as $k => $v) if (($rb[$k] ?? 0) < $v) return false;
    return true;
}

/**
 * Reparte un precio final entre las unidades de una venta conjunta, en partes
 * iguales. Los centavos sobrantes van a la última, así que la suma de las partes
 * da EXACTAMENTE el precio final y el inventario no se desvía por redondeo.
 */
function pf_repartir(string $dealId, array $unis, float $vf): string {
    sort($unis);
    $g = bx('crm.item.list', ['entityTypeId' => SPA_ENTITY,
                              'filter' => ['@id' => $unis],
                              'select' => ['id', 'title', U_PVP, PF_U_PRECIO]]);
    if (!$g['ok']) { pf_log("ERR list unidades [" . implode(',', $unis) . "]: {$g['error']}"); return 'pf-err-unidad'; }
    $items = [];
    foreach (($g['result']['items'] ?? []) as $it) $items[(int)$it['id']] = $it;
    // Bitrix ignora en silencio un filtro que no entiende y devuelve otra cosa: si
    // no volvieron TODAS las unidades pedidas, no se reparte nada.
    foreach ($unis as $u) if (!isset($items[(int)$u])) {
        pf_log("REPARTO deal=$dealId ABORTA: la unidad $u no volvió en la lectura");
        return 'pf-err-unidad';
    }

    // El PVP vacío cuenta como 0: en los combos de Noral el precio de la pareja
    // está cargado entero en una sola unidad y la otra queda en blanco a propósito.
    $suma = 0.0;
    foreach ($items as $it) $suma += (float)(pf_money($it[U_PVP] ?? null) ?? 0.0);
    if ($vf < $suma - 0.5) {
        pf_log("REPARTO deal=$dealId final=$vf < suma de PVP=$suma — no se escribe");
        return 'pf-menor-que-pvp';
    }

    $n = count($unis);
    $parte = floor($vf / $n * 100) / 100;
    $resto = round($vf - $parte * ($n - 1), 2);      // la última se lleva los centavos
    $i = 0; $esc = 0; $bajos = [];
    foreach ($unis as $uid) {
        $uid = (int)$uid;
        $val = (++$i === $n) ? $resto : $parte;
        $it  = $items[$uid];
        $pvp = pf_money($it[U_PVP] ?? null);
        $ya  = pf_money($it[PF_U_PRECIO] ?? null);
        if ($pvp !== null && $val < $pvp - 0.5) $bajos[] = ($it['title'] ?? $uid) . " ($val < PVP $pvp)";
        if ($ya !== null && abs($ya - $val) < 0.005) continue;
        @touch(pf_dir() . '/self_u_' . $uid);
        $u = bx('crm.item.update', ['entityTypeId' => SPA_ENTITY, 'id' => $uid,
                                    'fields' => [PF_U_PRECIO => pf_fmt($val)]]);
        if ($u['ok']) $esc++;
        else pf_log("ERR update u=$uid: {$u['error']}");
    }
    pf_marcar($dealId, $vf);
    $msg = "REPARTO deal=$dealId final=$vf entre $n unidades -> $parte c/u (última $resto), escritas $esc";
    if ($bajos) $msg .= ' · OJO por debajo de su PVP: ' . implode(', ', $bajos);
    pf_log($msg);
    return 'pf-reparto-ok';
}

/**
 * Handler del evento. Devuelve un texto corto para el log de Bitrix.
 * Coste: 0 llamadas si el deal no es del 48 ni cambió; 1 lectura + 1 escritura
 * cuando el precio de verdad cambió.
 */
function precio_final_evento(string $dealId, ?array $deal = null): string {
    $mapa = pf_mapa();

    // ── Filtro barato: aquí se cae el 99% del ruido del portal, sin gastar nada.
    // Si el que llama YA leyó el deal (alta de deal, que hook.php lee igual para
    // saber si es del 44), se salta el filtro y se aprovecha esa lectura en vez de
    // pedir el mismo deal dos veces.
    if ($deal === null && !array_key_exists($dealId, $mapa)) return 'pf-skip-no48';

    $n = pf_rafaga();
    $tope = max(20, (int)(getenv('PF_RAFAGA_TOPE') ?: 60));
    if ($n > $tope) {
        if ($n === $tope + 1) pf_log("RAFAGA {$n}/min en el 48: se sueltan los eventos, el barrido los recoge");
        return 'pf-rafaga';
    }

    if ($deal === null) {
        $r = bx('crm.deal.get', ['id' => $dealId]);                   // 1 llamada
        if (!$r['ok']) { pf_log("ERR get deal=$dealId: {$r['error']}"); return 'pf-err-get'; }
        $deal = $r['result'];
    }
    if ((int)($deal['CATEGORY_ID'] ?? 0) !== COBRANZAS_CAT) return 'pf-skip-no48';

    $vf = pf_money($deal[PF_VALOR_FINAL] ?? null);

    // Deal del 48 que aún no está en el mapa (copia recién creada por la
    // automatización de PROMESA FIRMADA): se resuelve y se memoriza.
    $unis = $mapa[$dealId] ?? null;
    if ($unis === null || $unis === []) {
        $unis = pf_resolver_al_vuelo($deal);
        $mapa[$dealId] = $unis;
        pf_escribir('mapa48.json', $mapa);
        if ($unis) pf_log("ALTA deal=$dealId -> unidad(es) [" . implode(',', $unis) . ']');
    }
    if (!$unis) return 'pf-sin-unidad';
    if ($vf === null) return 'pf-sin-valor';

    // ¿ya escribí exactamente esto? Entonces no gasto una escritura.
    $visto = pf_visto();
    if (array_key_exists($dealId, $visto) && $visto[$dealId] !== null
        && abs((float)$visto[$dealId] - $vf) < 0.005) return 'pf-igual';

    // ── Venta de VARIAS unidades: un solo precio final para todas ─────────────
    // Regla del negocio: se reparte MITAD Y MITAD (en partes iguales). Escribir el
    // total en cada una duplicaría la venta en el inventario.
    // La validación "mayor o igual al PVP" se hace aquí sobre el TOTAL de la venta,
    // no unidad por unidad: repartir en partes iguales puede dejar a la unidad más
    // cara por debajo de su propio PVP y esa comparación bloquearía el reparto sin
    // que haya nada malo. Esos casos se anotan en el log para que se puedan mirar.
    if (count($unis) > 1) return pf_repartir($dealId, $unis, $vf);

    $uid = (int)$unis[0];
    $g = bx('crm.item.get', ['entityTypeId' => SPA_ENTITY, 'id' => $uid,
                             'select' => ['id', 'title', U_PVP, PF_U_PRECIO]]);   // 1 llamada
    if (!$g['ok']) { pf_log("ERR get unidad=$uid: {$g['error']}"); return 'pf-err-unidad'; }
    $it  = $g['result']['item'] ?? $g['result'];
    $pvp = pf_money($it[U_PVP] ?? null);
    $ya  = pf_money($it[PF_U_PRECIO] ?? null);
    $cod = (string)($it['title'] ?? $uid);

    if ($pvp === null) { pf_log("SIN-PVP u=$uid $cod final=$vf — no se escribe"); return 'pf-sin-pvp'; }
    if ($vf < $pvp - 0.5) {
        pf_log("MENOR u=$uid $cod final=$vf < pvp=$pvp — no se escribe");
        return 'pf-menor-que-pvp';
    }
    if ($ya !== null && abs($ya - $vf) < 0.005) { pf_marcar($dealId, $vf); return 'pf-ya-estaba'; }

    // El guardián de unidadlib revierte arrastres de STAGE hechos a mano. Aquí no
    // se toca el stage, pero se deja la marca igual: es la señal de "esta escritura
    // es del sistema" y cuesta un touch.
    @touch(pf_dir() . '/self_u_' . $uid);
    $u = bx('crm.item.update', ['entityTypeId' => SPA_ENTITY, 'id' => $uid,
                                'fields' => [PF_U_PRECIO => pf_fmt($vf)]]);            // 1 llamada
    if (!$u['ok']) { pf_log("ERR update u=$uid: {$u['error']}"); return 'pf-err-update'; }

    pf_marcar($dealId, $vf);
    pf_log("OK deal=$dealId u=$uid $cod " . ($ya === null ? 'vacío' : (string)$ya) . " -> $vf");
    return 'pf-ok';
}
