<?php
/**
 * reubicalib.php — REUBICACIÓN de unidad.
 * ---------------------------------------------------------------------------
 * Qué es: un cliente que ya compró cambia de unidad. No es un error de captura
 * ni una venta nueva: es la misma venta apuntando a otro bien, y hay que dejar
 * rastro de por dónde pasó (qué unidad tenía antes y a qué precio).
 *
 * DÓNDE se hace: solo en el deal de COBRANZAS(48). Es la regla del negocio —
 * las reubicaciones las maneja cobranzas, no ventas. El disparo es poner la
 * unidad nueva en el campo "Inventario" de ese deal.
 *
 * QUÉ escribe, en tres sitios:
 *
 *   COBRANZAS(48)  ACTIVO COMPRADO = unidad nueva
 *                  VALOR DEL ACTIVO = precio de la unidad nueva
 *                  REUBICADO = sí, ACTIVO INICIAL = la que tenía, PRECIO INICIAL
 *                  TITLE renombrado con el proyecto y la unidad nuevos
 *
 *   CLIENTES(44)   lo mismo, más Monto y moneda, Proyectos 1 y el campo
 *                  Inventario (aquí es donde vive la DEPENDENCIA de la unidad)
 *
 *   FAMILIA(58)    solo ACTIVO COMPRADO, VALOR DEL ACTIVO y Proyectos 1.
 *                  Sin renombrar: ese deal no lleva la unidad en el título.
 *
 * SEGUNDA y TERCERA vez: los campos van en tríos (flag + activo + precio). Se
 * usa el primer trío libre, así que la 2ª reubicación guarda en "ACTIVO NO. 2"
 * la unidad que se está reemplazando EN ESE MOMENTO (la que entró en la 1ª), no
 * la original. Cada trío es una foto de "de qué unidad salí esta vez".
 *
 * La dependencia (parentId2) NUNCA se escribe apuntando al deal 48, igual que en
 * Prospectos(28): la unidad quedaría colgada de dos deals de pipelines distintos.
 * Vive en el deal de CLIENTES.
 * ---------------------------------------------------------------------------
 */

declare(strict_types=1);
require_once __DIR__ . '/campolib.php';

const FAMILIA_CAT = 58;

// Los tres tríos de reubicación, en orden de uso. Cada uno: interruptor Sí/No,
// código de la unidad de la que se salió, y su precio.
const REUBICA_TRIOS = [
    // El precio de la 1ª ocasión usa "PRECIO ACTIVO NO. 1 (1ra Compra)", que es de
    // tipo MONEY. El campo anterior ("PRECIO INICIAL", UF_CRM_1783975599567) era
    // texto suelto y quedó fuera de uso: obligaba a escribir la moneda a mano.
    ['flag' => 'UF_CRM_1783975554626', 'activo' => 'UF_CRM_1783975581192', 'precio' => 'UF_CRM_1785443661310'],
    ['flag' => 'UF_CRM_1785415565344', 'activo' => 'UF_CRM_1785417527109', 'precio' => 'UF_CRM_1785417550374'],
    ['flag' => 'UF_CRM_1785429826419', 'activo' => 'UF_CRM_1785429969203', 'precio' => 'UF_CRM_1785417711317'],
];

/**
 * Nombre visible del proyecto (el que va en el título del deal), a partir del ID
 * de la lista "Proyectos 1". Los títulos existentes usan exactamente el texto de
 * esa lista ("Noral Apartments (Nuevo Samborondón)"), así que se lee de ahí en
 * vez de mantener una tabla paralela que se desincronizaría.
 *
 * Se cachea en disco: crm.deal.fields devuelve los 315 campos del portal y no
 * vale gastar eso en cada reubicación.
 */
function proyecto_nombre(int $enumId): string {
    if ($enumId <= 0) return '';
    $f = ($GLOBALS['DATA_DIR'] ?? '/data') . '/proyectos_enum.json';
    $m = json_decode((string)@file_get_contents($f), true);
    if (!is_array($m) || !isset($m[(string)$enumId])) {
        $r = bx('crm.deal.fields', []);
        $items = $r['result'][D_PROYECTO]['items'] ?? [];
        if (!$items) { logline("proyecto_nombre: no pude leer la lista Proyectos 1"); return ''; }
        $m = [];
        foreach ($items as $x) $m[(string)$x['ID']] = (string)$x['VALUE'];
        @file_put_contents($f, json_encode($m), LOCK_EX);
    }
    return (string)($m[(string)$enumId] ?? '');
}

/** Convierte "134630|USD" (o "134630.00") en float. */
function money_num($v): float {
    $s = trim((string)$v);
    if ($s === '') return 0.0;
    return (float)explode('|', $s)[0];
}

/** Formatea para los campos money del CRM: "134630|USD". */
function money_fmt(float $n): string {
    return rtrim(rtrim(number_format($n, 2, '.', ''), '0'), '.') . '|USD';
}

/**
 * Renombra un título que viene en segmentos "--", cambiando SOLO los dos últimos
 * (proyecto y unidad) y dejando intacto lo de delante.
 *
 * Se hace así a propósito: el nombre del cliente en esos títulos está escrito de
 * formas irregulares ("JoséJuez", "MaritzaJacome Delgado", pegado sin espacio),
 * y reconstruirlo desde el contacto cambiaría títulos que hoy están bien. Si el
 * título no tiene la forma esperada se devuelve null y no se renombra nada.
 */
function titulo_reubicado(string $titulo, string $proyecto, string $codigo): ?string {
    $p = explode('--', $titulo);
    if (count($p) < 3 || $proyecto === '' || $codigo === '') return null;
    $p = array_slice($p, 0, count($p) - 2);      // suelta proyecto y unidad viejos
    $p[] = $proyecto;
    $p[] = $codigo;
    return implode('--', $p);
}

/**
 * Encuentra la unidad del SPA cuyo código y contacto coinciden. Es el mismo
 * emparejamiento que usa reconcile.php para Cobranzas y la razón es la misma: el
 * código solo no basta porque una unidad revendida reaparece con el mismo código
 * años después, con otro dueño.
 */
function unidad_por_codigo_contacto(string $codigo, int $contacto): ?array {
    $codigo = strtoupper(str_replace(' ', '', trim($codigo)));
    if ($codigo === '' || $contacto <= 0) return null;
    $start = 0;
    do {
        $r = bx('crm.item.list', ['entityTypeId' => SPA_ENTITY, 'order' => ['id' => 'ASC'], 'start' => $start]);
        if (!$r['ok']) return null;
        foreach (($r['result']['items'] ?? []) as $it) {
            $c = strtoupper(str_replace(' ', '', trim(explode('(', (string)($it['title'] ?? ''))[0])));
            if ($c !== $codigo) continue;
            if ((int)($it['contactId'] ?? 0) === $contacto) return $it;
        }
        $start = $r['next'] ?? null;
    } while ($start !== null && $start !== '');
    return null;
}

/**
 * El deal de CLIENTES(44) que corresponde a este deal de Cobranzas: mismo
 * contacto. Si hay varios (reventas del mismo cliente), gana el que nombra la
 * unidad vieja; si ninguno la nombra, el más reciente.
 */
function clientes_hermano(int $contacto, int $unidadVieja): ?array {
    if ($contacto <= 0) return null;
    $r = bx('crm.deal.list', [
        'filter' => ['CONTACT_ID' => $contacto, 'CATEGORY_ID' => CLIENTES_CAT],
        'order'  => ['ID' => 'DESC'],
    ]);
    if (!$r['ok']) return null;
    $lista = $r['result'] ?? [];
    if (!$lista) return null;
    if ($unidadVieja > 0) {
        foreach ($lista as $d) {
            if (in_array($unidadVieja, ids_de((string)($d[CAMPO_NUEVO] ?? '')), true)) return $d;
        }
    }
    return $lista[0];
}

/**
 * REUBICA. $nuevas son los ids que quedaron en el campo Inventario del deal 48.
 *
 * Devuelve un resumen; si algo no cuadra devuelve ok=false con el motivo, y en
 * ese caso NO escribe nada a medias: todas las validaciones van antes de la
 * primera escritura.
 */
function reubicar(int $dealId, array $deal, array $nuevas): array {
    $contacto = (int)($deal['CONTACT_ID'] ?? 0);
    if (!$nuevas) {
        return ['ok' => false, 'error' => 'No hay unidad nueva en el campo'];
    }
    if ($contacto <= 0) {
        return ['ok' => false, 'error' => 'El deal de Cobranzas no tiene contacto: no puedo emparejar'];
    }

    // --- la unidad NUEVA -----------------------------------------------------
    $nuevoId = (int)$nuevas[0];
    $g = bx('crm.item.get', ['entityTypeId' => SPA_ENTITY, 'id' => $nuevoId]);
    if (!$g['ok']) return ['ok' => false, 'error' => "No pude leer la unidad $nuevoId: {$g['error']}"];
    $nueva    = $g['result']['item'] ?? $g['result'];
    $codNuevo = codigo_activo((string)($nueva['title'] ?? ''));
    $catNueva = (int)($nueva['categoryId'] ?? 0);
    $proyId   = proyecto_de_unidad($catNueva, $nueva[U_TIPO] ?? null);
    $proyTxt  = proyecto_nombre($proyId);
    $pvpNuevo = money_num($nueva[U_PVP] ?? '');

    // --- la unidad VIEJA -----------------------------------------------------
    $codViejo = trim((string)($deal[D_ACTIVO] ?? ''));
    if ($codViejo === '') {
        return ['ok' => false, 'error' => 'El deal no tiene ACTIVO COMPRADO: no sé de qué unidad se reubica'];
    }
    if (strtoupper(str_replace(' ', '', $codViejo)) === strtoupper(str_replace(' ', '', $codNuevo))) {
        return ['ok' => true, 'nada' => 'la unidad del campo es la que ya estaba: no es reubicación'];
    }

    // El deal de CLIENTES se resuelve antes de la unidad vieja: es la fuente más
    // firme, porque ahí vive la dependencia (parentId2) de la unidad que se está
    // reemplazando. Buscar solo por código+contacto no basta — en una segunda
    // reubicación la unidad anterior puede no tener contacto puesto, y entonces no
    // se encontraba y quedaba ocupada de fantasma.
    $h = clientes_hermano($contacto, 0);

    // Tres caminos, del más firme al más flojo:
    //   1. lo que el propio campo del deal 48 tenía antes de este cambio
    //   2. lo que cuelga del deal de CLIENTES por parentId2
    //   3. código + contacto (el que usa reconcile para Cobranzas)
    $vieja = null;
    $candidatos = array_values(array_diff(ids_de((string)($deal[CAMPO_NUEVO] ?? '')), $nuevas));
    if (!$candidatos && $h) {
        $r = bx('crm.item.list', ['entityTypeId' => SPA_ENTITY, 'filter' => ['parentId2' => (int)$h['ID']]]);
        foreach (($r['result']['items'] ?? []) as $it) {
            if (in_array((int)$it['id'], $nuevas, true)) continue;
            $candidatos[] = (int)$it['id'];
        }
    }
    foreach ($candidatos as $cid) {
        $x = bx('crm.item.get', ['entityTypeId' => SPA_ENTITY, 'id' => (int)$cid]);
        if (!$x['ok']) continue;
        $it = $x['result']['item'] ?? $x['result'];
        // solo vale si de verdad es la unidad del ACTIVO COMPRADO que dice el deal
        $c = strtoupper(str_replace(' ', '', codigo_activo((string)($it['title'] ?? ''))));
        if ($c === strtoupper(str_replace(' ', '', $codViejo))) { $vieja = $it; break; }
        if ($vieja === null) $vieja = $it;   // se guarda por si ninguno coincide de código
    }
    if ($vieja === null) $vieja = unidad_por_codigo_contacto($codViejo, $contacto);
    $viejaId = (int)($vieja['id'] ?? 0);

    // Si se identificó la unidad vieja de verdad, su CÓDIGO manda sobre el texto
    // del campo ACTIVO COMPRADO. Hace falta porque ese campo lo escriben también
    // otras automatizaciones del portal y llega sucio: se vio un deal con
    // ACTIVO COMPRADO = "1234" (la cédula del cliente), y así ACTIVO INICIAL
    // guardaba "1234" en vez del código de la unidad. El dato bueno es la unidad.
    $codDeCampo = $codViejo;
    if ($viejaId > 0) {
        $c = codigo_activo((string)($vieja['title'] ?? ''));
        if ($c !== '') $codViejo = $c;
    }

    // Precio de la unidad vieja. Regla del negocio: si el VALOR DEL ACTIVO del
    // deal difiere del PVP de lista, manda el del deal — ahí está el precio que
    // realmente se pactó (upgrades, bodega, balcón). Si coinciden, da igual cuál.
    //
    // Con un cordón: el valor del deal solo se cree si la ficha de ese deal está
    // sana, o sea si su ACTIVO COMPRADO es de verdad el código de la unidad vieja.
    // Si el campo trae otra cosa (se vio la cédula del cliente ahí), su VALOR DEL
    // ACTIVO tampoco es de fiar y se usa el PVP del SPA.
    $valorDeal = money_num($deal[D_VALOR] ?? '');
    $pvpViejo  = money_num($vieja[U_PVP] ?? '');
    $norm      = fn($s) => strtoupper(str_replace(' ', '', trim((string)$s)));
    $fichaSana = ($viejaId === 0) || ($norm($codDeCampo) === $norm($codViejo));
    if (!$fichaSana) {
        logline("REUBICA deal=$dealId ficha sucia: ACTIVO COMPRADO=\"$codDeCampo\" no es la unidad"
              . " $viejaId ($codViejo) -> uso el PVP del SPA, no el valor del deal");
    }
    $precioViejo = ($fichaSana && $valorDeal > 0 && abs($valorDeal - $pvpViejo) > 0.01)
        ? $valorDeal : $pvpViejo;
    if ($precioViejo <= 0) $precioViejo = $valorDeal > 0 ? $valorDeal : $pvpViejo;

    // --- qué trío de campos toca (1ª, 2ª o 3ª vez) ---------------------------
    $trio = null; $ocasion = 0;
    foreach (REUBICA_TRIOS as $i => $t) {
        $marcado = (string)($deal[$t['flag']] ?? '');
        // los boolean del CRM llegan como "1"/"0"/"" y a veces como 1/0
        if ($marcado === '1' || $marcado === 'Y' || $marcado === 'true') continue;
        $trio = $t; $ocasion = $i + 1; break;
    }
    if ($trio === null) {
        return ['ok' => false, 'error' => 'Este deal ya tiene las tres reubicaciones usadas'];
    }

    // ======================= a partir de aquí, se ESCRIBE ====================
    $hecho = ['ok' => true, 'ocasion' => $ocasion, 'de' => $codViejo, 'a' => $codNuevo,
              'unidad_vieja' => $viejaId, 'unidad_nueva' => $nuevoId, 'proyecto' => $proyTxt];

    // 1) COBRANZAS(48) — SOLO ACTIVO COMPRADO, el trío de reubicación y el título.
    //
    // Ni VALOR DEL ACTIVO ni Monto y moneda ni Proyectos 1: en Cobranzas esos los
    // llenan sus propias automatizaciones (el valor sale de la tabla de pagos
    // cuando se sube). Escribirlos desde aquí sería pisar el trabajo de otro
    // sistema con un precio de lista. El proyecto sí se calcula, pero solo para
    // armar el título.
    $c48 = [
        D_ACTIVO          => $codNuevo,
        $trio['flag']     => 1,
        $trio['activo']   => $codViejo,
        $trio['precio']   => $precioViejo > 0 ? money_fmt($precioViejo) : '',
    ];
    $t48 = titulo_reubicado((string)($deal['TITLE'] ?? ''), $proyTxt, $codNuevo);
    if ($t48 !== null) $c48['TITLE'] = $t48;

    $u = bx('crm.deal.update', ['id' => $dealId, 'fields' => $c48]);
    $hecho['cobranzas'] = $u['ok'] ? 'ok' : $u['error'];
    if (!$u['ok']) { logline("REUBICA deal=$dealId ERR 48: {$u['error']}"); return ['ok' => false, 'error' => $u['error']]; }
    logline("REUBICA deal=$dealId ocasion=$ocasion $codViejo -> $codNuevo (48 listo)");

    // 2) CLIENTES(44) — el que de verdad ata la unidad
    if ($h === null) $h = clientes_hermano($contacto, $viejaId);
    if ($h) {
        $hid  = (int)$h['ID'];
        $c44  = [
            CAMPO_NUEVO       => implode(',', array_map('intval', $nuevas)),
            D_ACTIVO          => $codNuevo,
            $trio['flag']     => 1,
            $trio['activo']   => $codViejo,
            $trio['precio']   => $precioViejo > 0 ? money_fmt($precioViejo) : '',
        ];
        if ($pvpNuevo > 0) {
            $c44[D_VALOR]      = money_fmt($pvpNuevo);
            $c44['OPPORTUNITY'] = rtrim(rtrim(number_format($pvpNuevo, 2, '.', ''), '0'), '.');
            $c44['CURRENCY_ID'] = 'USD';
        }
        if ($proyId > 0) $c44[D_PROYECTO] = $proyId;
        $t44 = titulo_reubicado((string)($h['TITLE'] ?? ''), $proyTxt, $codNuevo);
        if ($t44 !== null) $c44['TITLE'] = $t44;

        $u = bx('crm.deal.update', ['id' => $hid, 'fields' => $c44]);
        $hecho['clientes'] = $u['ok'] ? "ok (deal $hid)" : $u['error'];
        logline("REUBICA deal=$dealId clientes=$hid " . ($u['ok'] ? 'ok' : "ERR: {$u['error']}"));

        // El atado se hace explícito además del evento del hook: si el webhook se
        // pierde, la unidad nueva quedaría en el campo pero sin dependencia.
        // Es idempotente, así que hacerlo dos veces no rompe nada.
        if ($u['ok']) {
            $stageDestino = CLIENTES_TRIGGERS[(string)($h['STAGE_ID'] ?? '')] ?? null;
            if ($stageDestino === null) {
                // etapa sin regla: la unidad nueva hereda el estado de la vieja
                $stageDestino = $vieja ? unit_stage_name($vieja) : null;
            }
            foreach ($nuevas as $uid) {
                @touch(($GLOBALS['DATA_DIR'] ?? '/data') . '/self_u_' . (int)$uid);
                // El contacto y el asesor van en la MISMA escritura que la
                // dependencia. Sin el contacto, una segunda reubicación no podía
                // reconocer esta unidad como la anterior y la dejaba ocupada de
                // fantasma; además es lo que hace que salga el nombre en el kanban.
                $campos = campos_owner(['contactId' => 0, 'assignedById' => 0], $h);
                $campos['parentId2'] = $hid;
                if ($stageDestino !== null) {
                    $sid = stage_id((string)$catNueva, $stageDestino);
                    if ($sid !== null) $campos['stageId'] = $sid;
                }
                bx('crm.item.update', ['entityTypeId' => SPA_ENTITY, 'id' => (int)$uid, 'fields' => $campos]);
                cache_unidad((int)$uid, $stageDestino, $hid);
            }
            $hecho['stage_nueva'] = $stageDestino ?? '-';
        }
    } else {
        $hecho['clientes'] = 'no encontré deal de Clientes de este contacto';
        logline("REUBICA deal=$dealId sin hermano en 44 (contacto $contacto)");
    }

    // 3) la unidad VIEJA se suelta y vuelve a DISPONIBLE. Si no, quedarían dos
    //    unidades ocupadas por la misma venta y una de ellas invendible.
    if ($viejaId > 0) {
        @touch(($GLOBALS['DATA_DIR'] ?? '/data') . '/self_u_' . $viejaId);
        $sid = stage_id((string)($vieja['categoryId'] ?? ''), 'DISPONIBLE');
        $campos = ['parentId2' => 0, 'contactId' => 0];
        if ($sid !== null) $campos['stageId'] = $sid;
        $u = bx('crm.item.update', ['entityTypeId' => SPA_ENTITY, 'id' => $viejaId, 'fields' => $campos]);
        $hecho['vieja_liberada'] = $u['ok'] ? 'DISPONIBLE' : $u['error'];
        cache_unidad($viejaId, 'DISPONIBLE', 0);
        logline("REUBICA u=$viejaId ($codViejo) liberada -> DISPONIBLE");
    } else {
        $hecho['vieja_liberada'] = "no encontré la unidad $codViejo del contacto $contacto en el SPA";
        logline("REUBICA deal=$dealId no ubiqué la unidad vieja $codViejo (contacto $contacto)");
    }

    // 3b) La unidad vieja fuera del campo Inventario de los HERMANOS.
    //     Hace falta porque el portal copia deals solo (28->44 al reservar, 44->48
    //     al firmar, y los de FAMILIA/EXPERIENCIAS) arrastrando el campo. Si no se
    //     limpia, esos deals siguen nombrando la unidad vieja y el reconcile la
    //     vuelve a atar: quedaría ocupada otra vez sin que nadie la haya vendido.
    //     Se recorren TODAS las categorías del contacto, no solo 28/44, porque el
    //     arrastre llega hasta Cobranzas y Familia.
    if ($viejaId > 0) {
        $r = bx('crm.deal.list', [
            'filter' => ['CONTACT_ID' => $contacto, '!' . CAMPO_NUEVO => ''],
            'select' => ['ID', 'CATEGORY_ID', CAMPO_NUEVO],
        ]);
        $nLimpios = 0;
        foreach (($r['result'] ?? []) as $d) {
            $did = (int)($d['ID'] ?? 0);
            if ($did <= 0 || $did === $dealId) continue;
            if ($h && $did === (int)$h['ID']) continue;              // el destino ya quedó bien
            $tiene = ids_de((string)($d[CAMPO_NUEVO] ?? ''));
            $queda = array_values(array_diff($tiene, [$viejaId]));
            if (count($queda) === count($tiene)) continue;
            $u = bx('crm.deal.update', ['id' => $did, 'fields' => [CAMPO_NUEVO => implode(',', $queda)]]);
            if ($u['ok']) { $nLimpios++; logline("REUBICA hermano deal=$did: quitada u=$viejaId del campo"); }
            else logline("REUBICA hermano deal=$did ERR: {$u['error']}");
        }
        $hecho['hermanos_limpiados'] = $nLimpios;
    }

    // 4) FAMILIA(58) — solo los tres campos de ficha, sin renombrar
    $r = bx('crm.deal.list', [
        'filter' => ['CONTACT_ID' => $contacto, 'CATEGORY_ID' => FAMILIA_CAT],
        'select' => ['ID', 'TITLE'],
    ]);
    $nFam = 0;
    foreach (($r['result'] ?? []) as $f) {
        $cf = [D_ACTIVO => $codNuevo];
        if ($pvpNuevo > 0) $cf[D_VALOR] = money_fmt($pvpNuevo);
        if ($proyId > 0)   $cf[D_PROYECTO] = $proyId;
        $u = bx('crm.deal.update', ['id' => (int)$f['ID'], 'fields' => $cf]);
        if ($u['ok']) { $nFam++; logline("REUBICA familia deal={$f['ID']} actualizado"); }
        else logline("REUBICA familia deal={$f['ID']} ERR: {$u['error']}");
    }
    $hecho['familia'] = $nFam;

    return $hecho;
}
