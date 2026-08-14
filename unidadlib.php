<?php
/**
 * unidadlib.php — el catálogo del campo se actualiza al INSTANTE, por evento.
 * ---------------------------------------------------------------------------
 * Antes el catálogo solo se refrescaba por reloj (cron cada 30 min), así que una
 * unidad nueva del SPA podía tardar media hora en aparecer en el campo. Ahora
 * Bitrix avisa en el momento en que la unidad se crea, cambia o se borra, y aquí
 * se toca SOLO esa unidad en el caché.
 *
 * Lo llama hook.php, que ya es el endpoint del webhook de salida. Se reutiliza
 * ese webhook a propósito en vez de crear uno nuevo: un webhook nuevo traería su
 * propio application_token y NO coincidiría con el OUTBOUND_TOKEN que el servicio
 * ya valida — daría 403 en todos los eventos.
 *
 * Eventos a suscribir (con sufijo, para no recibir los otros SPAs del portal):
 *   ONCRMDYNAMICITEMADD_1072  ONCRMDYNAMICITEMUPDATE_1072  ONCRMDYNAMICITEMDELETE_1072
 *
 * Coste: 2 llamadas a Bitrix por alta/cambio (el item y las etapas de su
 * pipeline), y CERO en un borrado. El payload de Bitrix solo trae el ID.
 *
 * Funciones con prefijo propio (u_bx, ulog) porque hook.php ya declara bx() y
 * logline(): sin el prefijo el require daría un fatal por redeclaración.
 * ---------------------------------------------------------------------------
 */

declare(strict_types=1);

if (!defined('SPA_ENTITY')) define('SPA_ENTITY', 1072);

// mismos campos de la unidad que usa selector.php al construir el catálogo
const UH_M2  = 'ufCrm25_1782615822688';
const UH_PVP = 'ufCrm25_1784563253861';
const UH_TOR = 'ufCrm25_1784314119';
const UH_PIS = 'ufCrm25_1784313244';

function ulog(string $msg): void {
    $dir = getenv('DATA_DIR') ?: '/data';
    @file_put_contents($dir . '/web.log',
        gmdate('Y-m-d\TH:i:s\Z') . '  UNIDADHOOK ' . $msg . "\n", FILE_APPEND | LOCK_EX);
}

function u_bx(string $method, array $params = []): array {
    $base = rtrim((string)getenv('BITRIX_WEBHOOK'), '/') . '/';
    $ch = curl_init($base . $method);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query($params),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_CONNECTTIMEOUT => 10,
    ]);
    $raw   = curl_exec($ch);
    $errno = curl_errno($ch);
    if ($errno)             return ['ok' => false, 'error' => "curl:$errno"];
    $j = json_decode((string)$raw, true);
    if (!is_array($j))      return ['ok' => false, 'error' => 'bad-json'];
    if (isset($j['error'])) return ['ok' => false, 'error' => $j['error'] . ':' . ($j['error_description'] ?? '')];
    return ['ok' => true, 'result' => $j['result'] ?? null];
}

/** Pide la reconstrucción COMPLETA del catálogo sin esperarla. */
function u_rebuild_fondo(): void {
    $tok = (string)getenv('OUTBOUND_TOKEN');
    if ($tok === '') return;
    $ch = curl_init('http://127.0.0.1/selector.php?warm=1&token=' . urlencode($tok));
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT_MS     => 300,   // se corta enseguida; el rebuild sigue del otro lado
        CURLOPT_NOSIGNAL       => true,
    ]);
    curl_exec($ch);
}


/**
 * ¿Algún deal nombra esta unidad en su campo "Inventario"? Devuelve su id o 0.
 *
 * Cubre el caso de una unidad ocupada de verdad a la que se le perdió el enlace
 * `parentId2`: el deal la sigue nombrando, así que es su dueño real. 1 llamada,
 * y solo se paga en movimientos humanos de unidades sin enlace.
 *
 * El filtro `%campo` es por SUBCADENA: buscando 2241 también casarían "12241" o
 * "2241,999". Por eso después se parsea el valor y se exige el id EXACTO.
 */
function u_dueno_por_campo(int $unitId): int {
    $r = u_bx('crm.deal.list', [
        'filter' => ['%UF_CRM_1785205972989' => (string)$unitId, '@CATEGORY_ID' => [28, 44]],
        'select' => ['ID', 'UF_CRM_1785205972989'],
        'order'  => ['ID' => 'DESC'],
    ]);
    if (!$r['ok']) return 0;
    foreach (($r['result'] ?? []) as $d) {
        foreach (preg_split('/[,;\s]+/', (string)($d['UF_CRM_1785205972989'] ?? '')) as $x) {
            if ((int)trim($x) === $unitId) return (int)$d['ID'];
        }
    }
    return 0;
}

/**
 * GUARDIÁN DE ESTADO: el stage de una unidad lo manda la ETAPA DE SU DEAL.
 *
 * En el kanban del SPA se puede arrastrar una unidad a cualquier columna y ahí se
 * queda. Eso está mal por diseño: una unidad pasa a FIRMADO porque su deal llegó a
 * PROMESA FIRMADA o CIERRE DE PROMESA, no porque alguien la arrastró. Y arrastrarla
 * a DISPONIBLE estando atada la vuelve escogible por otro vendedor: doble venta.
 *
 * Regla: cualquier cambio de stage hecho A MANO sobre una unidad que tenga deal se
 * revierte. Se revierte re-sincronizando el DEAL DUEÑO, que es quien sabe qué stage
 * corresponde a su etapa. Se delega a propósito en vez de recalcularlo aquí:
 * duplicar el mapa de etapas es la forma segura de que se desincronice.
 *
 * Excepciones, y son deliberadas:
 *   - BLOQUEADO y PERDIDO son gerenciales: se mueven a mano a propósito.
 *   - VENDIDO no lo dicta CLIENTES sino COBRANZAS(48), que empareja por
 *     código+contacto y no por parentId2. Aquí no hay forma de distinguir un
 *     VENDIDO legítimo de uno puesto a mano, y revertirlo podría deshacer una
 *     venta real. Se registra en el log y no se toca.
 *   - Sin deal dueño no hay etapa que dicte nada, así que no se revierte.
 *
 * Coste: CERO llamadas extra en los movimientos del propio sistema, porque
 * apply_unit_stage() deja una marca de escritura propia. Solo se paga el re-sync
 * en los movimientos humanos, que son pocos.
 */
function guardian_estado(int $unitId, array $u, string $stageAntes, array $status = []): void {
    $ahora = (string)($u['stage'] ?? '');
    if ($ahora === '' || $ahora === $stageAntes) return;   // no cambió el stage

    $dir = getenv('DATA_DIR') ?: '/data';

    // ¿lo escribimos nosotros? apply_unit_stage deja la marca antes de escribir.
    $propia = $dir . '/self_u_' . $unitId;
    if (is_file($propia) && (time() - (int)@filemtime($propia)) < 25) return;

    if ($ahora === 'BLOQUEADO' || $ahora === 'PERDIDO') {
        ulog("GUARDIAN u=$unitId $stageAntes -> $ahora a mano: gerencial, se respeta");
        return;
    }
    if ($ahora === 'VENDIDO') {
        ulog("GUARDIAN u=$unitId $stageAntes -> VENDIDO a mano: NO se revierte (lo manda Cobranzas)");
        return;
    }

    // Anti-eco: la corrección dispara otro ONCRMDYNAMICITEMUPDATE. Sin esto dos
    // eventos casi simultáneos pueden pedir el mismo re-sync dos veces.
    $marca = $dir . '/guard_u_' . $unitId;
    if (is_file($marca) && (time() - (int)@filemtime($marca)) < 30) return;

    $dueno = (int)($u['dealId'] ?? 0);          // parentId2, ya resuelto por quien llama
    if ($dueno === 0) {
        // ¿la tiene apartada un deal del 28? El registro está en disco: 0 llamadas.
        $ap = json_decode((string)@file_get_contents($dir . '/apartados_puestos.json'), true);
        if (is_array($ap) && isset($ap[(string)$unitId])) $dueno = (int)$ap[(string)$unitId];
    }
    // Sin enlace: puede que un deal la nombre igual y se haya perdido el parentId2.
    if ($dueno <= 0) $dueno = u_dueno_por_campo($unitId);

    if ($dueno <= 0) {
        // Nadie la reclama. Una unidad LIBRE no puede estar RESERVADO ni FIRMADO:
        // esos estados los produce un deal, no un arrastre. Se devuelve a
        // DISPONIBLE. BLOQUEADO/PERDIDO/VENDIDO ya salieron antes.
        if ($ahora !== 'RESERVADO' && $ahora !== 'FIRMADO') return;

        $destino = '';
        foreach ($status as $s) {
            if (strtoupper((string)$s['NAME']) === 'DISPONIBLE') { $destino = (string)$s['STATUS_ID']; break; }
        }
        if ($destino === '') {
            ulog("GUARDIAN u=$unitId sin deal y en $ahora, pero no se resolvio DISPONIBLE en cat " . ($u['cat'] ?? '?'));
            return;
        }

        @touch($marca);
        // marca de escritura propia: si no, la corrección se vería como un
        // movimiento humano más y el guardián se llamaría a sí mismo en bucle
        @touch($dir . '/self_u_' . $unitId);
        $w = u_bx('crm.item.update', ['entityTypeId' => SPA_ENTITY, 'id' => $unitId,
                                      'fields' => ['stageId' => $destino]]);
        ulog("GUARDIAN u=$unitId sin deal, movida a mano a $ahora -> devuelta a DISPONIBLE"
             . ($w['ok'] ? '' : " ERR {$w['error']}"));
        return;
    }

    @touch($marca);
    ulog("GUARDIAN u=$unitId movida a mano $stageAntes -> $ahora; manda deal=$dueno -> re-sync");

    // Se delega en sync-campo.php (usa campolib) por HTTP local: unidadlib no
    // puede requerir campolib, sus bx()/logline() chocarían por redeclaración.
    $tok = (string)getenv('OUTBOUND_TOKEN');
    if ($tok === '') return;
    $ch = curl_init('http://127.0.0.1/sync-campo.php?deal=' . $dueno . '&token=' . urlencode($tok));
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 25,   // se espera: si no, la unidad queda mal hasta el barrido
        CURLOPT_NOSIGNAL       => true,
    ]);
    curl_exec($ch);
}

/**
 * Atiende un evento de unidad del SPA. Devuelve un texto corto para el log.
 * La AUTENTICACIÓN la hace quien llama: hook.php ya validó el application_token
 * del webhook de salida contra OUTBOUND_TOKEN antes de llegar aquí.
 */
function unidad_evento(string $event, int $unitId, int $etid): string {
    $cache_path = (getenv('DATA_DIR') ?: '/data') . '/selector_cache.json';
    $event = strtoupper($event);

    if ($unitId <= 0) return 'no-id';
    // Aunque se suscriban las variantes _1072, se comprueba igual: si alguien
    // añadiera el evento genérico llegarían unidades de los otros SPAs del portal.
    if ($etid && $etid !== SPA_ENTITY) return 'otro-spa';

    $cache = json_decode((string)@file_get_contents($cache_path), true);
    if (!is_array($cache) || empty($cache['units'])) {
        u_rebuild_fondo();
        ulog("u=$unitId sin cache -> rebuild completo");
        return 'rebuild';
    }

    // ---- BORRADO: se quita del caché, sin gastar ni una llamada a Bitrix ----
    if (strpos($event, 'DELETE') !== false) {
        $antes = count($cache['units']);
        $cache['units'] = array_values(array_filter(
            $cache['units'],
            fn($u) => (int)($u['id'] ?? 0) !== $unitId
        ));
        if (count($cache['units']) !== $antes) {
            @file_put_contents($cache_path, json_encode($cache), LOCK_EX);
            ulog("u=$unitId BORRADA del catalogo");
        }
        return 'ok-delete';
    }

    // ---- ALTA / CAMBIO: se lee esa unidad y se mete en el caché ----
    $r = u_bx('crm.item.get', ['entityTypeId' => SPA_ENTITY, 'id' => $unitId]);
    if (!$r['ok']) { ulog("ERR get u=$unitId: {$r['error']}"); return 'err-get'; }
    $it  = $r['result']['item'] ?? $r['result'];
    $cid = (string)($it['categoryId'] ?? '');

    // Proyecto que el caché no conoce = pipeline NUEVO. No se adivina su nombre ni
    // sus etapas: se reconstruye todo, que es lo único que los trae. Así un
    // proyecto nuevo entra solo, sin tocar código.
    if (!isset($cache['proyectos'][$cid])) {
        u_rebuild_fondo();
        ulog("u=$unitId proyecto NUEVO cat=$cid -> rebuild completo");
        return 'rebuild-proyecto';
    }

    // Nombre de la etapa: los STATUS_ID difieren por pipeline, se resuelve por nombre.
    // El mapa viene en el caché, así que lo normal es CERO llamadas. Solo se pregunta
    // a Bitrix si aparece un STATUS_ID que el mapa no conoce — etapa recién creada.
    $sid   = (string)($it['stageId'] ?? '');
    $mapa  = $cache['stages'] ?? [];
    $stage = $mapa[$sid] ?? '';
    if ($stage === '' && $sid !== '') {
        $st = u_bx('crm.status.list', ['filter' => ['ENTITY_ID' => 'DYNAMIC_' . SPA_ENTITY . '_STAGE_' . $cid]]);
        foreach (($st['result'] ?? []) as $s) {
            if ((string)$s['STATUS_ID'] === $sid) { $stage = strtoupper((string)$s['NAME']); break; }
        }
    }
    if ($stage === '') {
        // No se pudo resolver. Se CONSERVA la etapa que ya tenía en vez de escribir
        // vacío: así fue como 21 unidades quedaron sin etapa y desaparecieron de los
        // conteos. Un dato viejo es mejor que ninguno, y el barrido lo corrige.
        foreach (($cache['units'] ?? []) as $vu) {
            if ((int)($vu['id'] ?? 0) === $unitId) { $stage = (string)($vu['stage'] ?? ''); break; }
        }
        ulog("u=$unitId etapa sin resolver (sid=$sid) -> se conserva '" . $stage . "'");
    }

    $enum  = $cache['enum'] ?? [];
    $title = (string)($it['title'] ?? '');
    $nueva = [
        'id'     => (int)$it['id'],
        'codigo' => trim(explode('(', $title)[0]),
        'cat'    => $cid,
        'stage'  => $stage,
        'm2'     => (string)($it[UH_M2]  ?? ''),
        'pvp'    => (string)($it[UH_PVP] ?? ''),
        'torre'  => $enum[UH_TOR][(string)($it[UH_TOR] ?? '')] ?? '',
        'piso'   => $enum[UH_PIS][(string)($it[UH_PIS] ?? '')] ?? '',
        'dealId' => (int)($it['parentId2'] ?? 0),
    ];

    // ---- GUARDIÁN DE ESTADO --------------------------------------------------
    // Se corre ANTES de tocar el caché para que, si la corrección se dispara, el
    // caché lo refresque el evento de la corrección con el valor definitivo.
    // El stage ANTERIOR sale del caché: así se distingue un cambio de stage de un
    // cambio de cualquier otro campo, sin gastar ni una llamada al API.
    $stageAntes = '';
    foreach ($cache['units'] as $vu) {
        if ((int)($vu['id'] ?? 0) === $unitId) { $stageAntes = (string)($vu['stage'] ?? ''); break; }
    }
    guardian_estado($unitId, $nueva, $stageAntes, $st['result'] ?? []);

    $reemplazada = false;
    foreach ($cache['units'] as $i => $u) {
        if ((int)($u['id'] ?? 0) === $unitId) {
            $cache['units'][$i] = $nueva;
            $reemplazada = true;
            break;
        }
    }
    if (!$reemplazada) $cache['units'][] = $nueva;

    @file_put_contents($cache_path, json_encode($cache), LOCK_EX);
    ulog(($reemplazada ? 'ACTUALIZADA' : 'AGREGADA') . " u=$unitId {$nueva['codigo']} cat=$cid stage=$stage");
    return $reemplazada ? 'ok-update' : 'ok-add';
}
