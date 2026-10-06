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

// proyecto_nombre(), money_num() y money_fmt() viven en campolib.php: las usa
// también el autollenado de la ficha, y campolib se carga antes que este archivo.

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
function clientes_hermano(int $contacto, int $unidadVieja, ?string &$motivo = null): ?array {
    $motivo = null;
    if ($contacto <= 0) { $motivo = 'el deal no tiene contacto'; return null; }

    // 🔴 El `select` NO es opcional: sin él Bitrix devuelve el deal pero con los
    //    userfields VACÍOS, así que la rama de abajo —la que elige el deal que
    //    realmente nombra la unidad vieja— nunca podía acertar y siempre ganaba
    //    el más reciente. En un cliente con varias compras eso escribe la
    //    reubicación en el deal equivocado.
    // La lista se pide UNA sola vez por contacto: reubicar() llama dos veces —
    // primero sin saber la unidad vieja (la necesita para ubicarla) y después ya
    // sabiéndola, para afinar la elección. Sin este caché la segunda llamada
    // pagaría otra vez lo mismo.
    static $cache = [];
    if (isset($cache[$contacto])) {
        $r = $cache[$contacto];
    } else {
        $r = bx('crm.deal.list', [
            'filter' => ['CONTACT_ID' => $contacto, 'CATEGORY_ID' => CLIENTES_CAT],
            'select' => ['ID', 'TITLE', 'STAGE_ID', 'ASSIGNED_BY_ID', 'CONTACT_ID', CAMPO_NUEVO],
            'order'  => ['ID' => 'DESC'],
        ]);
        // Un fallo NO se cachea: se reintenta en la siguiente llamada.
        if ($r['ok']) $cache[$contacto] = $r;
    }

    // 🔴 Fallar al preguntar NO es lo mismo que no tener hermano. Antes los dos
    //    casos devolvían null y quien llamaba escribía "sin hermano en 44" —
    //    medido el 2026-09-02 en el deal 9350, cuyo contacto SÍ tenía el deal
    //    9348 en CLIENTES. Un diagnóstico que miente manda a buscar una falla
    //    que no existe.
    if (!$r['ok']) { $motivo = 'no pude consultar CLIENTES: ' . $r['error']; return null; }

    $lista = $r['result'] ?? [];
    if (!$lista) { $motivo = "el contacto $contacto no tiene ningún deal en CLIENTES"; return null; }

    if ($unidadVieja > 0) {
        foreach ($lista as $d) {
            if (in_array($unidadVieja, ids_de((string)($d[CAMPO_NUEVO] ?? '')), true)) return $d;
        }
    }
    return $lista[0];
}

// ===========================================================================
// MODO ESTRICTO (REUBICA_ESTRICTO, por defecto 1) — 5-oct-2026, deal 9610.
// ---------------------------------------------------------------------------
// Qué pasó: el 48 decía G-4-3 en ACTIVO COMPRADO, en el título y en las 50 cuotas.
// El Inventario estaba vacío, así que la unidad vieja se buscó en el 44 MÁS NUEVO
// del contacto (241608, otra compra: un local E-1-21). De ahí salió la 815
// (C-3-7 de Noral Apartments), el respaldo "si ninguna coincide, la primera" la
// aceptó aunque el código no era G-4-3, y su código PISÓ el ACTIVO COMPRADO.
// Resultado: ACTIVO INICIAL = C-3-7, la C-3-7 del cliente liberada a DISPONIBLE
// (tiene su 282904 vivo) y la unidad nueva atada a un deal de otra compra.
//
// Regla de Jesua: "más alternativas de cómo llegar al dato verdadero" y "que un
// cliente cambie a otro deal, eso está equivocadísimo". Y (mismo día): "cada vez
// que se haga reubicación" el 44 va a ELABORACIÓN PROMESA DE COMPRAVENTA. Por eso:
//   1. La unidad vieja es (PROYECTO, CÓDIGO). C-3-7 existe en tres proyectos. El
//      código sale de TRES fuentes: ACTIVO COMPRADO, el título del 48 y el prefijo
//      de sus cuotas. Manda la mayoría; si no hay mayoría, no se escribe.
//   2. Una unidad con otro código o de otro proyecto NUNCA es la vieja.
//   3. El 44 es el que nombra ESA unidad (Inventario o título). Si no hay ninguno
//      (y el contacto tiene más de uno) -> no se escribe. Si está caído, revive.
//   4. No se libera una unidad que otro deal vivo del cliente sigue nombrando.
// Todo "no escribí" va al monitor (falla.php), no solo al log.
// ===========================================================================

function reub_estricto(): bool {
    return trim((string)getenv('REUBICA_ESTRICTO')) !== '0';
}

/** "g - 4-3 " -> "G-4-3". */
function reub_cod(string $c): string {
    return strtoupper(str_replace(' ', '', trim($c)));
}

/** Mayúsculas, sin tildes, espacios simples: para comparar nombres de proyecto. */
function reub_txt(string $s): string {
    $s = strtr($s, ['á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u','ñ'=>'n','Á'=>'A','É'=>'E','Í'=>'I','Ó'=>'O','Ú'=>'U','Ñ'=>'N']);
    return strtoupper(trim(preg_replace('/\s+/', ' ', $s) ?? ''));
}

/**
 * Proyecto y código de un título "…--Proyecto--CODIGO" (44 y 48). null si el
 * título no tiene esa forma o el último tramo no parece un código.
 */
function reub_titulo_partes(string $titulo): ?array {
    $p = array_map('trim', explode('--', $titulo));
    if (count($p) < 3) return null;
    $cod = reub_cod((string)end($p));
    $proy = trim((string)$p[count($p) - 2]);
    if ($cod === '' || !preg_match('/\d/', $cod) || $proy === '') return null;
    return ['proyecto' => $proy, 'codigo' => $cod];
}

/**
 * El código que llevan las cuotas del 48 ("G-4-3 - Cuota 2024-07 -($578.38)").
 * Gana el prefijo más repetido. Las "Cuota Extra" no llevan prefijo y no cuentan.
 */
function reub_codigo_cuotas(array $titulos): ?string {
    $n = [];
    foreach ($titulos as $t) {
        if (!preg_match('/^\s*([A-Za-z0-9]+(?:\s*-\s*[A-Za-z0-9]+)+)\s+-\s+/u', (string)$t, $m)) continue;
        $c = reub_cod($m[1]);
        if (!preg_match('/\d/', $c)) continue;
        $n[$c] = ($n[$c] ?? 0) + 1;
    }
    if (!$n) return null;
    arsort($n);
    return (string)array_key_first($n);
}

/**
 * De qué unidad sale el cliente, votando entre tres fuentes. Devuelve
 * ['codigo' => 'G-4-3', 'fuentes' => [...]] o ['error' => motivo].
 */
function reub_decidir_codigo(string $activo, ?string $titulo, ?string $cuotas): array {
    $fuentes = array_filter([
        'ACTIVO COMPRADO' => reub_cod($activo),
        'título del deal' => reub_cod((string)$titulo),
        'cuotas'          => reub_cod((string)$cuotas),
    ], fn($v) => $v !== '');
    if (!$fuentes) return ['error' => 'no sé de qué unidad sale: ACTIVO COMPRADO, título y cuotas vacíos'];
    $votos = array_count_values($fuentes);
    arsort($votos);
    $gana = (string)array_key_first($votos);
    if ($votos[$gana] * 2 <= count($fuentes)) {
        $txt = implode(' · ', array_map(fn($k, $v) => "$k=$v", array_keys($fuentes), $fuentes));
        return ['error' => "las fuentes no coinciden en la unidad de la que sale ($txt)", 'fuentes' => $fuentes];
    }
    return ['codigo' => $gana, 'fuentes' => $fuentes];
}

/** ¿La unidad "G-4-3 (Barranca Apartments)" es del proyecto "Barranca Apartments (Nuevo Samborondón)"? */
function reub_mismo_proyecto(string $proyDeal, string $tituloUnidad): bool {
    if ($proyDeal === '') return true;                       // sin proyecto conocido no se puede descartar
    if (!preg_match('/\(([^)]*)\)\s*$/u', $tituloUnidad, $m)) return false;
    $u = reub_txt($m[1]);
    $d = reub_txt($proyDeal);
    $dBase = trim(explode('(', $d)[0]);
    return $u !== '' && (str_starts_with($d, $u) || str_starts_with($u, $dBase));
}

/** La unidad del SPA es exactamente (proyecto, código). */
function reub_unidad_es(array $u, string $codigo, string $proyecto): bool {
    $t = (string)($u['title'] ?? '');
    return reub_cod(codigo_activo($t)) === $codigo && reub_mismo_proyecto($proyecto, $t);
}

/**
 * ¿Esta ficha de FAMILIA es la de la unidad que se deja? Mismo código Y mismo
 * proyecto (Proyectos 1): los códigos se repiten entre proyectos. Si la ficha no
 * tiene proyecto cargado, alcanza con el código (no hay con qué más comparar).
 */
function reub_familia_es(array $f, string $codViejo, string $proyIdViejo): bool {
    if (reub_cod((string)($f[D_ACTIVO] ?? '')) !== reub_cod($codViejo)) return false;
    $pf = trim((string)($f[D_PROYECTO] ?? ''));
    return $pf === '' || trim($proyIdViejo) === '' || $pf === trim($proyIdViejo);
}

/** Etapa a la que va el 44 en TODA reubicación (Jesua, 5-oct). REUBICA_ETAPA_44=0 la apaga. */
function reub_etapa_44(): ?string {
    $v = trim((string)getenv('REUBICA_ETAPA_44'));
    if ($v === '0') return null;
    return $v !== '' ? $v : 'C44:UC_Z3GY5H';   // ELABORACION PROMESA DE COMPRAVENTA
}

/**
 * Etapa a la que pasa el deal de COBRANZAS(48) al reubicarse. Jesua 6-oct-2026: "se pondrá automáticamente en la
 * etapa reubicación, a su vez también se vaciarán varios campos y se eliminarán las dependencias". El vaciado y el
 * borrado los hace cobranza2 al recibir el aviso (lib_reubica_fusion.php, perilla reubica_vaciar_on); aquí solo la etapa,
 * en el MISMO update del 48. Env REUBICA_ETAPA_48: vacío o '0' = no se mueve (por defecto), '1' = REUBICACIÓN, o un stageId.
 */
function reub_etapa_48(): ?string {
    $v = trim((string)getenv('REUBICA_ETAPA_48'));
    if ($v === '' || $v === '0') return null;
    return $v === '1' ? 'C48:UC_1WR2BM' : $v;   // REUBICACION
}

/** Campo de texto que la ficha de CLIENTES muestra como "PRECIO ACTIVO NO. 1 (1ra Compra)". */
const REUB_PRECIO_TEXTO_44 = 'UF_CRM_1783975599567';

/** Etapas del 44 en las que el deal está caído: atar ahí deja la unidad DISPONIBLE. */
const REUB_44_CAIDO = ['C44:APOLOGY', 'C44:LOSE'];
/** Etapas de cualquier embudo que ya no cuentan como dueño vivo. */
function reub_deal_vivo(array $d): bool {
    $st = (string)($d['STAGE_ID'] ?? '');
    return $st !== '' && !preg_match('/(^|:)(LOSE|APOLOGY)$/', $st);
}

/**
 * El 44 de esta compra entre los deals del contacto. Gana el que tiene la unidad
 * vieja en su Inventario; si no, el que la nombra en el título (código y
 * proyecto). Si el contacto tiene UN solo 44, ese (comportamiento de siempre).
 * Nunca "el más nuevo": ese puede ser otra compra.
 */
function reub_elegir_hermano(array $deals, string $codigo, string $proyecto, int $viejaId): array {
    $d44 = array_values(array_filter($deals, fn($d) => (int)($d['CATEGORY_ID'] ?? -1) === CLIENTES_CAT));
    if (!$d44) return ['error' => 'el contacto no tiene ningún deal en CLIENTES'];
    $elegido = null; $por = '';
    if ($viejaId > 0) {
        foreach ($d44 as $d) {
            if (in_array($viejaId, ids_de((string)($d[CAMPO_NUEVO] ?? '')), true)) { $elegido = $d; $por = 'Inventario'; break; }
        }
    }
    if (!$elegido) {
        $porTitulo = [];
        foreach ($d44 as $d) {
            $tp = reub_titulo_partes((string)($d['TITLE'] ?? ''));
            if (!$tp || $tp['codigo'] !== $codigo) continue;
            if ($proyecto !== '' && reub_txt(explode('(', $tp['proyecto'])[0]) !== reub_txt(explode('(', $proyecto)[0])) continue;
            $porTitulo[] = $d;
        }
        if (count($porTitulo) > 1) {
            // varios con el mismo código y proyecto: el vivo, y si son varios vivos no se adivina
            $vivos = array_values(array_filter($porTitulo, fn($d) => reub_deal_vivo($d)));
            if (count($vivos) === 1) $porTitulo = $vivos;
            else return ['error' => count($porTitulo) . " deals de CLIENTES dicen $codigo ($proyecto): "
                . implode(', ', array_map(fn($d) => (string)$d['ID'], $porTitulo)) . ' — no adivino cuál'];
        }
        if ($porTitulo) { $elegido = $porTitulo[0]; $por = 'título'; }
    }
    if (!$elegido && count($d44) === 1) { $elegido = $d44[0]; $por = 'único deal de CLIENTES'; }
    if (!$elegido) {
        return ['error' => count($d44) . " deals en CLIENTES y ninguno es $codigo" . ($proyecto !== '' ? " de $proyecto" : '')
            . ' (ni por Inventario ni por título) — no lo cambio a otra compra'];
    }
    // Caído (APOLOGY/LOSE) NO frena: la reubicación lo pasa a ELABORACIÓN PROMESA
    // (regla de Jesua, 5-oct), así la unidad nueva no queda DISPONIBLE. Caso 9610:
    // el G-4-3 de CLIENTES (5792) estaba en FIRMADOS-CAÍDOS y era el correcto.
    return ['deal' => $elegido, 'por' => $por,
            'caido' => in_array((string)($elegido['STAGE_ID'] ?? ''), REUB_44_CAIDO, true)];
}

/**
 * Otro deal VIVO del contacto (fuera de este 48 y de su 44) que sigue nombrando
 * la unidad vieja por título o ACTIVO COMPRADO. Si existe, la unidad NO se libera.
 */
function reub_otro_dueno(array $deals, array $excluir, string $codigo, string $proyecto): ?int {
    foreach ($deals as $d) {
        $id = (int)($d['ID'] ?? 0);
        if ($id <= 0 || in_array($id, $excluir, true) || !reub_deal_vivo($d)) continue;
        if (!in_array((int)($d['CATEGORY_ID'] ?? -1), [CLIENTES_CAT, COBRANZAS_CAT], true)) continue;
        $tp = reub_titulo_partes((string)($d['TITLE'] ?? ''));
        $proyOk = $proyecto === '' || ($tp && reub_txt(explode('(', $tp['proyecto'])[0]) === reub_txt(explode('(', $proyecto)[0]));
        if ($tp && $tp['codigo'] === $codigo && $proyOk) return $id;
        if (reub_cod((string)($d[D_ACTIVO] ?? '')) === $codigo && $proyOk) return $id;
    }
    return null;
}

/**
 * EL CEREBRO, sin Bitrix: con el 48, los títulos de sus cuotas, los deals del
 * contacto y una función que trae unidades, decide de qué unidad sale, cuál es su
 * 44 y si la vieja se puede liberar. reubicar() la usa tal cual; la prueba del
 * 9610 también. $unidadesDe(int $hermanoId, int[] $idsInventario): array de unidades.
 */
function reub_resolver(array $deal48, array $cuotaTitulos, array $deals, callable $unidadesDe): array {
    $dealId = (int)($deal48['ID'] ?? 0);
    $tit = reub_titulo_partes((string)($deal48['TITLE'] ?? ''));
    $dec = reub_decidir_codigo((string)($deal48[D_ACTIVO] ?? ''), $tit['codigo'] ?? null, reub_codigo_cuotas($cuotaTitulos));
    if (isset($dec['error'])) return ['ok' => false, 'error' => $dec['error']];
    $codigo = $dec['codigo'];
    $proyecto = ($tit && $tit['codigo'] === $codigo) ? $tit['proyecto'] : '';

    $h = reub_elegir_hermano($deals, $codigo, $proyecto, 0);
    $hid = isset($h['deal']) ? (int)$h['deal']['ID'] : 0;

    // La vieja: entre lo que cuelga de ese 44 y lo que diga el Inventario del 48,
    // SOLO la que es (proyecto, código). Nada de "la primera que aparezca".
    $vieja = null;
    foreach ($unidadesDe($hid, ids_de((string)($deal48[CAMPO_NUEVO] ?? ''))) as $u) {
        if (reub_unidad_es($u, $codigo, $proyecto)) { $vieja = $u; break; }
    }
    $viejaId = (int)($vieja['id'] ?? 0);
    if ($viejaId > 0) $h = reub_elegir_hermano($deals, $codigo, $proyecto, $viejaId);   // afinar con el id
    if (isset($h['error'])) return ['ok' => false, 'error' => $h['error'], 'codigo' => $codigo];

    $otro = $viejaId > 0 ? reub_otro_dueno($deals, [$dealId, (int)$h['deal']['ID']], $codigo, $proyecto) : null;
    return ['ok' => true, 'codigo' => $codigo, 'proyecto' => $proyecto, 'fuentes' => $dec['fuentes'],
            'hermano' => $h['deal'], 'hermano_por' => $h['por'], 'vieja' => $vieja,
            'liberar' => $viejaId > 0 && $otro === null, 'otro_dueno' => $otro];
}

/** Pedido a la libreta (solo lectura). ['status'=>int,'json'=>?array]; status 0 = sin config o sin red. */
function reub_libreta(string $ruta): array {
    $base = rtrim((string)getenv('LIBRETA_URL'), '/');
    $tok  = (string)getenv('LIBRETA_TOKEN');
    if ($base === '' || $tok === '') return ['status' => 0, 'json' => null];
    $ch = curl_init($base . $ruta);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 12, CURLOPT_CONNECTTIMEOUT => 4,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $tok, 'X-Libreta-Cliente: reubica'],
    ]);
    $body = curl_exec($ch);
    $st = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    unset($ch);
    $j = is_string($body) ? json_decode($body, true) : null;
    return ['status' => $st, 'json' => is_array($j) ? $j : null];
}

/** Títulos de las cuotas del 48 (libreta /cuotas). null = no se pudo leer (no es "sin cuotas"). */
function reub_titulos_cuotas(int $dealId): ?array {
    $r = reub_libreta('/cuotas?deal=' . $dealId);
    if ($r['status'] !== 200 || !is_array($r['json'])) {
        logline("REUBICA deal=$dealId libreta /cuotas http {$r['status']} -> sigo sin la fuente 'cuotas'");
        return null;
    }
    $out = [];
    foreach ((array)($r['json']['cuotas'] ?? []) as $c) $out[] = (string)($c['dato']['title'] ?? '');
    return $out;
}

/** Todos los deals del contacto, de todos los embudos. Libreta primero; Bitrix si no (y se dice por qué). */
function reub_deals_contacto(int $contacto): ?array {
    $out = []; $cur = ''; $vueltas = 0;
    do {
        $r = reub_libreta('/deals?contact=' . $contacto . ($cur !== '' ? '&cursor=' . rawurlencode($cur) : ''));
        if ($r['status'] !== 200 || !is_array($r['json'])) { $out = null; break; }
        foreach ((array)($r['json']['items'] ?? []) as $i) if (is_array($i['dato'] ?? null)) $out[] = $i['dato'];
        $cur = (string)($r['json']['siguiente'] ?? '');
    } while ($cur !== '' && ++$vueltas < 20);
    if ($out !== null) return $out;
    logline("REUBICA contacto=$contacto libreta /deals sin respuesta (http {$r['status']}) -> Bitrix");
    $b = bx('crm.deal.list', ['filter' => ['CONTACT_ID' => $contacto],
        'select' => ['ID', 'TITLE', 'STAGE_ID', 'CATEGORY_ID', 'ASSIGNED_BY_ID', 'CONTACT_ID', CAMPO_NUEVO, D_ACTIVO]]);
    return $b['ok'] ? (array)($b['result'] ?? []) : null;
}

/**
 * LIBRETA DE REUBICACIONES (Jesua, 5-oct: "que todo eso se quede guardado siempre,
 * para que la información no se pierda"). Una línea JSON por paso, en el volumen
 * persistente: 'antes' (foto COMPLETA del 48, del 44, de la unidad vieja y la nueva,
 * tomada antes de la primera escritura), 'hecho' (qué se escribió) y 'frenada'
 * (por qué no). Con la línea 'antes' cualquier reubicación se puede deshacer a mano.
 * Solo se agrega; nunca se reescribe ni se purga.
 */
function reub_anotar(string $paso, int $dealId, array $datos): void {
    $datos = reub_sin_links($datos);
    $linea = json_encode(['cuando' => gmdate('c'), 'paso' => $paso, 'deal48' => $dealId] + $datos,
        JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
    $ok = @file_put_contents(($GLOBALS['DATA_DIR'] ?? '/data') . '/reubicaciones.jsonl', $linea . "\n", FILE_APPEND | LOCK_EX);
    if ($ok === false) logline("REUBICA deal=$dealId NO pude anotar '$paso' en reubicaciones.jsonl");
}

/** Los campos de archivo traen links con la llave del webhook: a la libreta NO van. */
function reub_sin_links(array $a): array {
    foreach ($a as $k => $v) {
        if (is_array($v)) $a[$k] = reub_sin_links($v);
        elseif (is_string($v) && preg_match('#https?://|/rest/\d+/|auth=#i', $v)) $a[$k] = '[link omitido]';
    }
    return $a;
}

/** "No escribí" visible: log + contador + monitor (falla.php). Devuelve el ok=false para reubicar(). */
function reub_frenar(int $dealId, string $motivo): array {
    logline("REUBICA deal=$dealId FRENADA (no se escribió nada): $motivo");
    reub_anotar('frenada', $dealId, ['motivo' => $motivo]);
    $f = ($GLOBALS['DATA_DIR'] ?? '/data') . '/reubica_frenadas_' . gmdate('Ymd');
    @file_put_contents($f, (string)((int)@file_get_contents($f) + 1), LOCK_EX);
    $url = rtrim((string)(getenv('CONTROL_URL') ?: 'https://galjosa-bitrix-control.pwluu1.easypanel.host'), '/');
    $ch = curl_init($url . '/api/falla.php');
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 2, CURLOPT_CONNECTTIMEOUT => 2,
        CURLOPT_POSTFIELDS => http_build_query(['sistema' => 'inventario-reubica', 'fallas' => json_encode([[
            'metodo' => 'reubicar', 'id' => $dealId, 'tipo' => 'escritura', 'error' => mb_substr($motivo, 0, 300),
            'destino' => 'perdido', 'nota' => 'reubicación NO aplicada: hay que resolverla a mano']], JSON_UNESCAPED_UNICODE)])]);
    @curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    unset($ch);
    if ($code !== 200) logline("REUBICA deal=$dealId aviso al monitor NO llegó (http $code)");
    return ['ok' => false, 'error' => "Reubicación NO aplicada: $motivo"];
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
    $estricto = reub_estricto();
    $liberar = true; $dealsContacto = null; $res = null;
    if ($estricto) {
        // Ver el bloque MODO ESTRICTO arriba (deal 9610). Todo se decide ANTES de escribir.
        $dealsContacto = reub_deals_contacto($contacto);
        if ($dealsContacto === null) return reub_frenar($dealId, "no pude leer los deals del contacto $contacto (ni libreta ni Bitrix)");
        $res = reub_resolver($deal + ['ID' => $dealId], reub_titulos_cuotas($dealId) ?? [], $dealsContacto,
            function (int $hid, array $inv) use ($nuevas): array {
                $out = []; $vistos = [];
                foreach (array_diff($inv, $nuevas) as $cid) {
                    $x = bx('crm.item.get', ['entityTypeId' => SPA_ENTITY, 'id' => (int)$cid]);
                    if ($x['ok']) { $it = $x['result']['item'] ?? $x['result']; $out[] = $it; $vistos[(int)$cid] = 1; }
                }
                if ($hid > 0) {
                    $r = bx('crm.item.list', ['entityTypeId' => SPA_ENTITY, 'filter' => ['parentId2' => $hid]]);
                    foreach (($r['result']['items'] ?? []) as $it) {
                        $id = (int)($it['id'] ?? 0);
                        if ($id > 0 && !isset($vistos[$id]) && !in_array($id, $nuevas, true)) $out[] = $it;
                    }
                }
                return $out;
            });
        if (!$res['ok']) return reub_frenar($dealId, $res['error']);
        $codViejo = $res['codigo'];
        if ($codViejo === reub_cod($codNuevo)) {
            return ['ok' => true, 'nada' => 'la unidad del campo es la que ya estaba: no es reubicación'];
        }
        $vieja = $res['vieja'];
        if ($vieja === null) {
            $u = unidad_por_codigo_contacto($codViejo, $contacto);
            if ($u && reub_unidad_es($u, $codViejo, $res['proyecto'])) $vieja = $u;   // misma llave (proyecto, código) o nada
        }
        $viejaId = (int)($vieja['id'] ?? 0);
        // El 44 elegido salió de la libreta: se relee de Bitrix justo antes de escribir.
        $hf = bx('crm.deal.get', ['id' => (int)$res['hermano']['ID']]);
        if (!$hf['ok']) return reub_frenar($dealId, "no pude releer el deal de CLIENTES {$res['hermano']['ID']}: {$hf['error']}");
        $h = $hf['result'];
        $tpH = reub_titulo_partes((string)($h['TITLE'] ?? ''));
        $sigue = ($tpH && $tpH['codigo'] === $codViejo)
              || ($viejaId > 0 && in_array($viejaId, ids_de((string)($h[CAMPO_NUEVO] ?? '')), true))
              || $res['hermano_por'] === 'único deal de CLIENTES';
        if (!$sigue) return reub_frenar($dealId, "el deal de CLIENTES {$h['ID']} cambió mientras tanto y ya no nombra $codViejo");
        $otro = $viejaId > 0 ? reub_otro_dueno($dealsContacto, [$dealId, (int)$h['ID']], $codViejo, $res['proyecto']) : null;
        $liberar = $otro === null;
        if (!$liberar) logline("REUBICA deal=$dealId la unidad $viejaId ($codViejo) NO se libera: el deal vivo $otro todavía la nombra");
        $codDeCampo = trim((string)($deal[D_ACTIVO] ?? ''));
    } else {
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

    reub_anotar('antes', $dealId, ['deal48' => $deal, 'deal44' => $h, 'vieja' => $vieja, 'nueva' => $nueva,
        'codigo_viejo' => $codViejo, 'codigo_nuevo' => $codNuevo, 'fuentes' => $res['fuentes'] ?? null,
        'hermano_por' => $res['hermano_por'] ?? 'camino viejo', 'liberar_vieja' => $liberar, 'estricto' => $estricto]);

    // ======================= a partir de aquí, se ESCRIBE ====================
    $hecho = ['ok' => true, 'ocasion' => $ocasion, 'de' => $codViejo, 'a' => $codNuevo,
              'unidad_vieja' => $viejaId, 'unidad_nueva' => $nuevoId, 'proyecto' => $proyTxt];

    // 1) COBRANZAS(48) — SOLO ACTIVO COMPRADO, el trío de reubicación y el título.
    //
    // Ni VALOR DEL ACTIVO ni Monto y moneda: en Cobranzas esos los llenan sus
    // propias automatizaciones (el valor sale de la tabla de pagos cuando se
    // sube). Escribirlos desde aquí sería pisar el trabajo de otro sistema con
    // un precio de lista.
    //
    // Proyectos 1 SÍ se escribe (antes se omitía junto con el precio). El
    // argumento de "no pisar a Cobranzas" cubre el PRECIO, que sale de la tabla;
    // el proyecto no sale de ninguna tabla, es un dato de la unidad. Omitirlo
    // dejaba el deal contradiciéndose solo: medido el 2026-09-02 en el deal 9350,
    // cuyo título decía "Noral Apartments" mientras Proyectos 1 seguía en
    // "Barranca Apartments" — y la reubicación había cruzado de proyecto.
    $c48 = [
        D_ACTIVO          => $codNuevo,
        $trio['flag']     => 1,
        $trio['activo']   => $codViejo,
        $trio['precio']   => $precioViejo > 0 ? money_fmt($precioViejo) : '',
    ];
    if ($proyId > 0) $c48[D_PROYECTO] = $proyId;
    $t48 = titulo_reubicado((string)($deal['TITLE'] ?? ''), $proyTxt, $codNuevo);
    if ($t48 !== null) $c48['TITLE'] = $t48;
    $etapa48 = reub_etapa_48();
    if ($etapa48 !== null) $c48['STAGE_ID'] = $etapa48;

    $u = bx('crm.deal.update', ['id' => $dealId, 'fields' => $c48]);
    $hecho['cobranzas'] = $u['ok'] ? 'ok' : $u['error'];
    if (!$u['ok']) { logline("REUBICA deal=$dealId ERR 48: {$u['error']}"); return ['ok' => false, 'error' => $u['error']]; }
    logline("REUBICA deal=$dealId ocasion=$ocasion $codViejo -> $codNuevo (48 listo)");

    // 2) CLIENTES(44) — el que de verdad ata la unidad
    // Se vuelve a resolver AHORA que se conoce la unidad vieja. Arriba (línea ~176)
    // se pidió con 0 porque hacía falta el deal para poder ubicarla, y con 0 la
    // función no puede hacer otra cosa que devolver el más reciente. En un cliente
    // con varias compras ese no tiene por qué ser el de esta unidad. Con la lista
    // ya cacheada esto no cuesta ninguna llamada más.
    $afinado = $estricto ? null : clientes_hermano($contacto, $viejaId, $porQueNoHay);
    if ($afinado !== null) {
        if ($h !== null && (int)$afinado['ID'] !== (int)$h['ID']) {
            logline("REUBICA deal=$dealId hermano corregido: {$h['ID']} -> {$afinado['ID']} (el que tiene la unidad $viejaId)");
        }
        $h = $afinado;
    }
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
        // La ficha de CLIENTES dibuja "PRECIO ACTIVO NO. 1" con el campo VIEJO de texto
        // (UF_CRM_1783975599567, rotulado así solo en esa ficha; medido en el DOM del
        // 5788 el 5-oct). La de COBRANZAS usa el de dinero. Se llenan los dos.
        if ($ocasion === 1 && $precioViejo > 0) $c44[REUB_PRECIO_TEXTO_44] = '$' . number_format($precioViejo, 2, '.', ',');
        $etapa44 = reub_etapa_44();
        if ($etapa44 !== null) $c44['STAGE_ID'] = $etapa44;
        $t44 = titulo_reubicado((string)($h['TITLE'] ?? ''), $proyTxt, $codNuevo);
        if ($t44 !== null) $c44['TITLE'] = $t44;

        $u = bx('crm.deal.update', ['id' => $hid, 'fields' => $c44]);
        $hecho['clientes'] = $u['ok'] ? "ok (deal $hid)" : $u['error'];
        logline("REUBICA deal=$dealId clientes=$hid " . ($u['ok'] ? 'ok' : "ERR: {$u['error']}"));

        // El atado se hace explícito además del evento del hook: si el webhook se
        // pierde, la unidad nueva quedaría en el campo pero sin dependencia.
        // Es idempotente, así que hacerlo dos veces no rompe nada.
        if ($u['ok']) {
            // Con el 44 en ELABORACIÓN PROMESA (sin regla propia) la unidad nueva queda
            // RESERVADO: apartada para este cliente, igual que una reserva.
            $stageDestino = $etapa44 !== null
                ? (CLIENTES_TRIGGERS[$etapa44] ?? 'RESERVADO')
                : (CLIENTES_TRIGGERS[(string)($h['STAGE_ID'] ?? '')] ?? null);
            if ($stageDestino === null) {
                // etapa sin regla: la unidad nueva hereda el estado de la vieja
                $stageDestino = $vieja ? unit_stage_name($vieja) : null;
            }
            // 🔴 ESTA es la escritura que de verdad importa: sin ella la unidad
            //    nueva queda SIN parentId2 y en DISPONIBLE — o sea, vendida en el
            //    papel y ofertable en el inventario al mismo tiempo. Medido el
            //    2026-09-02: u=759 (B-4-3) y u=761 (B-4-4) quedaron así, y el
            //    resumen igual devolvió clientes=ok porque nadie miraba el
            //    resultado. Ahora se comprueba, se registra y se refleja.
            $atadas = 0; $fallos = [];
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
                    else logline("REUBICA u=$uid SIN stage: no existe '$stageDestino' en la categoría $catNueva");
                }
                $ua = bx('crm.item.update', ['entityTypeId' => SPA_ENTITY, 'id' => (int)$uid, 'fields' => $campos]);
                if ($ua['ok']) {
                    $atadas++;
                    cache_unidad((int)$uid, $stageDestino, $hid);
                    logline("REUBICA u=$uid atada al deal $hid" . ($stageDestino !== null ? " ($stageDestino)" : ''));
                } else {
                    // No se cachea un atado que no ocurrió: el caché mentiría igual
                    // que el resumen.
                    $fallos[] = "u=$uid: {$ua['error']}";
                    logline("REUBICA u=$uid NO SE ATÓ al deal $hid ERR: {$ua['error']}");
                }
            }
            $hecho['stage_nueva']    = $stageDestino ?? '-';
            $hecho['unidades_atadas'] = $atadas . '/' . count($nuevas);
            if ($fallos) {
                // Se avisa fuerte: el 48 y el 44 ya quedaron con la unidad nueva,
                // pero sin dependencia el campo Inventario se vacía solo y la
                // unidad sigue ofertable. Es media reubicación, no una completa.
                $hecho['ERROR_ATAR'] = implode(' · ', $fallos);
                $hecho['aviso'] = 'La unidad NO quedó atada: sigue apareciendo disponible en el inventario';
            }
        }
    } else {
        // El motivo real, no "no encontré": puede ser que el API falló, y eso se
        // reintenta; que no haya deal en CLIENTES es otra cosa y se arregla a mano.
        $razon = $porQueNoHay ?? 'motivo desconocido';
        $hecho['clientes'] = 'NO se actualizó CLIENTES — ' . $razon;
        $hecho['aviso'] = 'La reubicación quedó a medias: COBRANZAS cambió pero CLIENTES no';
        logline("REUBICA deal=$dealId SIN TOCAR 44 (contacto $contacto): $razon");
    }

    // 3) la unidad VIEJA se suelta y vuelve a DISPONIBLE. Si no, quedarían dos
    //    unidades ocupadas por la misma venta y una de ellas invendible.
    if ($viejaId > 0 && !$liberar) {
        $hecho['vieja_liberada'] = "NO: el deal vivo $otro todavía nombra $codViejo";
    } elseif ($viejaId > 0) {
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

    // 3b) La unidad vieja fuera del campo Inventario de los HERMANOS (solo si se liberó).
    //     Hace falta porque el portal copia deals solo (28->44 al reservar, 44->48
    //     al firmar, y los de FAMILIA/EXPERIENCIAS) arrastrando el campo. Si no se
    //     limpia, esos deals siguen nombrando la unidad vieja y el reconcile la
    //     vuelve a atar: quedaría ocupada otra vez sin que nadie la haya vendido.
    //     Se recorren TODAS las categorías del contacto, no solo 28/44, porque el
    //     arrastre llega hasta Cobranzas y Familia.
    if ($viejaId > 0 && $liberar) {
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
        'select' => ['ID', 'TITLE', D_ACTIVO, D_PROYECTO],
    ]);
    $nFam = 0;
    foreach (($r['result'] ?? []) as $f) {
        // Un cliente con varias compras tiene UNA ficha de FAMILIA por compra (o una
        // sola para todas). Pisarlas todas con la unidad nueva mezcla compras: en el
        // 9610 la de Marcel Salvador quedó diciendo E-1-21. Solo la que nombraba la vieja.
        if ($estricto && !reub_familia_es($f, $codViejo, (string)($deal[D_PROYECTO] ?? ''))) continue;
        $cf = [D_ACTIVO => $codNuevo];
        if ($pvpNuevo > 0) $cf[D_VALOR] = money_fmt($pvpNuevo);
        if ($proyId > 0)   $cf[D_PROYECTO] = $proyId;
        $u = bx('crm.deal.update', ['id' => (int)$f['ID'], 'fields' => $cf]);
        if ($u['ok']) { $nFam++; logline("REUBICA familia deal={$f['ID']} actualizado"); }
        else logline("REUBICA familia deal={$f['ID']} ERR: {$u['error']}");
    }
    $hecho['familia'] = $nFam;

    reub_anotar('hecho', $dealId, $hecho);
    return $hecho;
}
