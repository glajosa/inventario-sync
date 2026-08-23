<?php
/**
 * copiarlib.php — copiar un deal dejandole UNA unidad.
 * ---------------------------------------------------------------------------
 * Es la misma operacion que el "Copiar" del menu de engranaje del deal, que es lo
 * que el director hace a mano hoy. La diferencia: la copia nace con SU unidad, su
 * proyecto, su titulo y su monto, en vez de arrastrar las dos y que despues alguien
 * tenga que quitar una —que es donde se rompia, porque quitarla de la copia se la
 * quitaba tambien al original—.
 *
 * Se midio sobre el par que copio el director (404141 / 404143): el "Copiar" nativo
 * deja 307 campos IGUALES y solo 6 distintos. Esos 6 son los que esta libreria fija
 * por unidad; el resto se copia tal cual.
 *
 * ORDEN DE LAS ESCRITURAS — no es intercambiable:
 *   1. se crea la copia con su unidad en el campo
 *   2. se le pone parentId2 = copia a esa unidad, a mano
 *   3. recien entonces se le quita del campo al original
 * Al reves la unidad quedaria DISPONIBLE unos segundos entre el paso 3 y el 1, y en
 * esa ventana cualquier otro vendedor la puede tomar. Con este orden nunca queda
 * libre: pasa de un dueño al otro.
 * ---------------------------------------------------------------------------
 */

declare(strict_types=1);

require_once __DIR__ . '/campolib.php';   // bx(), logline(), las constantes de campo

/**
 * Campos que NUNCA se copian.
 *
 * Los de sistema los pone Bitrix solo; copiarlos da un deal con fechas mentidas o
 * lo rechaza. Los de archivo llegan del get como objeto ({id, showUrl, ...}) y
 * devolverlos asi falla: para copiar un adjunto hay que subirlo de nuevo, y un
 * comprobante de pago pertenece a la negociacion en la que se pago, no a su copia.
 */
const COPIA_NO_COPIAR = [
    'ID', 'DATE_CREATE', 'DATE_MODIFY', 'CREATED_BY_ID', 'MODIFY_BY_ID',
    'MOVED_TIME', 'MOVED_BY_ID', 'LAST_ACTIVITY_TIME', 'LAST_ACTIVITY_BY',
    'LAST_COMMUNICATION_TIME', 'SEARCH_CONTENT', 'IS_RETURN_CUSTOMER',
    'IS_REPEATED_APPROACH', 'IS_MANUAL_OPPORTUNITY', 'TAX_VALUE',
    'QUOTE_ID', 'ORIGINATOR_ID', 'ORIGIN_ID', 'PARENT_ID_1072',
];

/** Un valor que llego del get y NO se puede devolver en un add. */
function copia_valor_util($v): bool {
    if (is_array($v)) {
        // archivo o lista de archivos
        if (isset($v['id']) || isset($v['showUrl']) || isset($v['urlMachine'])) return false;
        foreach ($v as $x) if (is_array($x) && (isset($x['id']) || isset($x['showUrl']))) return false;
    }
    return true;
}

/**
 * Los datos que la copia necesita de su unidad: codigo, PVP y proyecto.
 * Sale de un solo crm.item.get, el mismo que haria falta igual para el titulo.
 */
function copia_datos_unidad(int $unitId): ?array {
    $r = bx('crm.item.get', ['entityTypeId' => SPA_ENTITY, 'id' => $unitId]);
    if (!$r['ok']) { logline("COPIA u=$unitId no se pudo leer: {$r['error']}"); return null; }
    $it = $r['result']['item'] ?? $r['result'];
    return [
        'cod'  => codigo_activo((string)($it['title'] ?? '')),
        'pvp'  => (float)explode('|', (string)($it[U_PVP] ?? ''))[0],
        'proy' => proyecto_de_unidad((int)($it['categoryId'] ?? 0), $it[U_TIPO] ?? 0),
    ];
}

/**
 * Copia el deal y le deja SOLO la unidad indicada.
 *
 * @return array{ok:bool, id?:int, error?:string}
 */
function copiar_deal_con_unidad(int $dealOrigen, array $deal, int $unitId): array {
    $u = copia_datos_unidad($unitId);
    if (!$u || $u['cod'] === '') return ['ok' => false, 'error' => 'la unidad no se pudo leer'];

    $campos = [];
    foreach ($deal as $k => $v) {
        if (in_array($k, COPIA_NO_COPIAR, true)) continue;
        if (!copia_valor_util($v)) continue;
        $campos[$k] = $v;
    }

    // Los 6 que van por unidad. El PROYECTO sale de la unidad y no se copia del
    // original a proposito: las dos unidades pueden ser de proyectos distintos —una
    // de Noral Plaza y otra de Noral Apartments— y ahi el proyecto de cada deal es
    // otro. Fue un pedido explicito.
    $monto = number_format($u['pvp'], 2, '.', '');
    $campos[CAMPO_NUEVO]   = (string)$unitId;    // su unidad, sin la marca de separadas:
                                                 // con una sola no hay nada que separar
    $campos[D_ACTIVO]      = $u['cod'];
    $campos[D_VALOR]       = $monto . '|USD';
    $campos['OPPORTUNITY'] = $monto;
    $campos['CURRENCY_ID'] = 'USD';
    if ($u['proy'] > 0) $campos[D_PROYECTO] = $u['proy'];

    $nuevoTitulo = titulo_deal((string)($deal['TITLE'] ?? ''), (int)($deal['CONTACT_ID'] ?? 0),
                               $u['proy'] > 0 ? proyecto_nombre((int)$u["proy"]) : '', $u['cod']);
    if ($nuevoTitulo !== null) $campos['TITLE'] = $nuevoTitulo;

    $r = bx('crm.deal.add', ['fields' => $campos, 'params' => ['REGISTER_SONET_EVENT' => 'N']]);
    if (!$r['ok'] || empty($r['result'])) {
        logline("COPIA deal=$dealOrigen u=$unitId FALLO add: " . ($r['error'] ?? '?'));
        return ['ok' => false, 'error' => (string)($r['error'] ?? 'no se pudo crear')];
    }
    $nuevo = (int)$r['result'];

    // La unidad pasa de dueño SIN quedar libre en el medio: se le escribe el nuevo
    // parentId2 antes de tocar el campo del original. El stage no se toca —sigue
    // RESERVADO— porque la venta no cambio de estado, solo de negociacion.
    @touch(($GLOBALS['DATA_DIR'] ?? '/data') . '/self_u_' . $unitId);
    $up = bx('crm.item.update', ['entityTypeId' => SPA_ENTITY, 'id' => $unitId,
                                 'fields' => ['parentId2' => $nuevo]]);
    if (!$up['ok']) {
        logline("COPIA deal=$nuevo u=$unitId creada pero NO se pudo reatar: {$up['error']}");
        return ['ok' => true, 'id' => $nuevo, 'error' => 'creada, pero la unidad sigue atada al original'];
    }
    cache_unidad($unitId, null, $nuevo);
    atados_anotar($unitId, $nuevo);

    // La unidad sale del campo de PROSPECTOS: ahi ya no es un apartado, es una venta.
    //
    // Hay que hacerlo ACA a mano. `sincronizar_deal` lo hace solo, pero solo para lo
    // que el deal "acaba de atar" ($agregar), y la copia ata su unidad escribiendo
    // parentId2 directo tres lineas arriba — asi que cuando su hook corre ya la tiene
    // y $agregar viene vacio. Resultado: la unidad quedaba nombrada para siempre en
    // el deal de PROSPECTOS, que es el hueco que se veia despues de partir.
    $contacto = (int)($deal['CONTACT_ID'] ?? 0);
    if ($contacto > 0) limpiar_prospecto($nuevo, $contacto, [$unitId]);

    logline("COPIA deal=$dealOrigen -> $nuevo con u=$unitId ({$u['cod']}, proy {$u['proy']}, $monto)");
    return ['ok' => true, 'id' => $nuevo];
}

/** Deals ya partidos, para no partir dos veces. */
function partidos(): array {
    $j = json_decode((string)@file_get_contents(($GLOBALS['DATA_DIR'] ?? '/data') . '/partidos.json'), true);
    return is_array($j) ? $j : [];
}

function partido_anotar(int $dealId, array $creados): void {
    $m = partidos();
    $m[(string)$dealId] = ['cuando' => date('c'), 'creados' => $creados];
    @file_put_contents(($GLOBALS['DATA_DIR'] ?? '/data') . '/partidos.json',
                       json_encode($m), LOCK_EX);
}

/**
 * Parte un deal de CLIENTES en uno por unidad.
 *
 * Solo actua si el vendedor marco las unidades como SEPARADAS. Una fusion de verdad
 * —dos fichas que son un mismo local, como C-1-23.24— tiene que seguir siendo UN
 * deal, y por eso la decision no se adivina del numero de unidades.
 *
 * El original CONSERVA la primera unidad y se crea una copia por cada una de las
 * demas: asi el historial, las actividades y los chats del original no se pierden.
 *
 * Se anota en `partidos.json` porque ONCRMDEALUPDATE llega muchas veces por el mismo
 * cambio. Sin ese registro, el segundo evento partiria otra vez y quedarian cuatro
 * deals donde deberian haber dos.
 *
 * @return array{ok:bool, motivo?:string, creados?:array}
 */
function partir_deal(int $dealId, array $deal): array {
    if ((int)($deal['CATEGORY_ID'] ?? 0) !== CLIENTES_CAT)
        return ['ok' => false, 'motivo' => 'no es CLIENTES'];
    if ((CLIENTES_TRIGGERS[(string)($deal['STAGE_ID'] ?? '')] ?? '') !== 'RESERVADO')
        return ['ok' => false, 'motivo' => 'no esta en RESERVA'];

    $valor = (string)($deal[CAMPO_NUEVO] ?? '');
    if (!unidades_separadas($valor))
        return ['ok' => false, 'motivo' => 'las unidades van en fusion'];

    $ids = ids_de($valor);
    if (count($ids) < 2)
        return ['ok' => false, 'motivo' => 'una sola unidad, no hay nada que partir'];

    if (isset(partidos()[(string)$dealId]))
        return ['ok' => false, 'motivo' => 'ya se partio antes'];

    $queda   = array_shift($ids);      // el original se queda con la primera
    $creados = [];
    foreach ($ids as $uid) {
        $r = copiar_deal_con_unidad($dealId, $deal, (int)$uid);
        if (!empty($r['id'])) $creados[] = ['deal' => (int)$r['id'], 'unidad' => (int)$uid];
        else logline("PARTIR deal=$dealId u=$uid no se copio: " . ($r['error'] ?? '?'));
    }
    // Se anota ANTES de tocar el original: ese update dispara otro evento, y si el
    // registro no estuviera ya escrito el segundo evento volveria a partir.
    partido_anotar($dealId, $creados);

    // El original se queda con la suya. Sin la marca: ya no hay nada que separar.
    // Las otras unidades YA apuntan a sus copias, asi que el sincronizador no las
    // suelta —no son de este deal— y `propagar_quitada` tampoco las toca.
    $up = bx('crm.deal.update', ['id' => $dealId,
                                 'fields' => [CAMPO_NUEVO => (string)$queda]]);
    if (!$up['ok']) logline("PARTIR deal=$dealId no se pudo dejar solo u=$queda: {$up['error']}");

    // TODAS las unidades del reparto salen del campo de PROSPECTOS, no solo las de las
    // copias: la que el original conserva tampoco es un apartado ya. Se hace aca y no
    // se confia en el sincronizado porque ese update deja el campo IGUAL a lo que el
    // deal ya tenia atado, asi que su $agregar tambien viene vacio.
    $contacto = (int)($deal['CONTACT_ID'] ?? 0);
    if ($contacto > 0) {
        $todas = array_merge([$queda], array_map(fn($c) => $c['unidad'], $creados));
        limpiar_prospecto($dealId, $contacto, $todas);
    }

    logline("PARTIR deal=$dealId -> conserva u=$queda · creados: "
          . implode(', ', array_map(fn($c) => "{$c['deal']}(u{$c['unidad']})", $creados)));
    return ['ok' => true, 'creados' => $creados, 'conserva' => $queda];
}
