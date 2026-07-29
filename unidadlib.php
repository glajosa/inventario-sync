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
 * GUARDIÁN DE ESTADO: deshace un movimiento manual del kanban del SPA que
 * contradiga la realidad de la unidad.
 *
 * El problema: en el kanban del SPA se puede arrastrar una unidad de RESERVADO a
 * DISPONIBLE y ahí se queda — como si se le olvidara que está reservada, aunque
 * siga atada a un deal y con responsable. Después queda visible como libre y otro
 * vendedor puede escogerla: doble venta.
 *
 * La regla: si la unidad tiene un motivo real para estar tomada, ese motivo manda
 * sobre el arrastre. Motivos reales, en orden:
 *   1. `parentId2` = un deal de CLIENTES la tiene atada (reserva/firma de verdad).
 *   2. está en el registro de APARTADOS del 28 (un deal está cerrando el acuerdo).
 * En ambos casos se vuelve a sincronizar el deal dueño, que es quien sabe qué
 * stage corresponde a su etapa. Se DELEGA a propósito en vez de recalcularlo
 * aquí: duplicar el mapa de etapas es la forma segura de que se desincronice.
 *
 * Solo se revierte cuando la movieron a DISPONIBLE, que es la que causa daño
 * (queda escogible). BLOQUEADO y PERDIDO se respetan: son gerenciales por diseño
 * y el resto del sistema tampoco los pisa (ver apply_unit_stage).
 */
function guardian_estado(int $unitId, array $u): void {
    if (($u['stage'] ?? '') !== 'DISPONIBLE') return;

    $dir = getenv('DATA_DIR') ?: '/data';

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
    if ($dueno <= 0) return;                    // libre de verdad: el arrastre es válido

    @touch($marca);
    ulog("GUARDIAN u=$unitId la movieron a DISPONIBLE pero sigue tomada por deal=$dueno -> re-sync");

    // Se delega en sync-campo.php (usa campolib) por HTTP local: unidadlib no
    // puede requerir campolib, sus bx()/logline() choparían por redeclaración.
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
    $st = u_bx('crm.status.list', ['filter' => ['ENTITY_ID' => 'DYNAMIC_' . SPA_ENTITY . '_STAGE_' . $cid]]);
    $stage = '';
    foreach (($st['result'] ?? []) as $s) {
        if ((string)$s['STATUS_ID'] === (string)($it['stageId'] ?? '')) {
            $stage = strtoupper((string)$s['NAME']);
            break;
        }
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
    guardian_estado($unitId, $nueva);

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
