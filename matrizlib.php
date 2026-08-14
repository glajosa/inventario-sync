<?php
/**
 * inventario-sync — matrizlib.php
 * ---------------------------------------------------------------------------
 * El precio de una unidad NO se guarda unidad por unidad: se DEDUCE de una matriz
 * de grupo × nivel × categoría. Subir precios es tocar un número, no editar 32
 * fichas a mano.
 *
 * Es el port a PHP del motor que ya vive en `noral_motor.py` (Noral Apartments).
 * La lógica es la misma a propósito, para que los dos den el mismo número: si
 * divergen, el inventario se llena de precios que nadie sabe de dónde salieron.
 *
 * ── LAS TRES CAPAS, EN ESTE ORDEN ──────────────────────────────────────────
 *   1. precio base del GRUPO al que pertenece el edificio
 *   2. OVERRIDES del edificio, donde se sale de su grupo
 *   3. AJUSTES de gerencia, que alcanzan también a los overrides
 * El histórico de subidas es la lista de ajustes. Revertir una = borrar su línea.
 *
 * ── LOS DOS CANDADOS, QUE NO SE TOCAN ──────────────────────────────────────
 *   · Solo se escribe sobre unidades DISPONIBLES. Lo reservado y lo vendido
 *     conserva su precio histórico: es lo que el cliente firmó.
 *   · Los grupos con `lanzado: false` (hoy el edificio J) quedan fuera de toda
 *     escritura, aunque se vean en pantalla.
 * ---------------------------------------------------------------------------
 */

declare(strict_types=1);

const MZ_NIVEL_DE_PISO = [1 => 'PB', 2 => 'PA', 3 => 'PA', 4 => '4P'];
/** Diferencia mínima para considerar que un precio cambió. Por debajo es ruido. */
const MZ_TOLERANCIA = 1.0;

/** Proyectos con matriz. La clave es el categoryId del pipeline en el SPA. */
function mz_proyectos(): array {
    $out = [];
    foreach (glob(__DIR__ . '/matrices/proyecto_*.json') ?: [] as $f) {
        if (!preg_match('/proyecto_(\d+)\.json$/', $f, $m)) continue;
        $j = json_decode((string)@file_get_contents($f), true);
        if (!is_array($j)) continue;
        $out[(int)$m[1]] = $j;
    }
    ksort($out);
    return $out;
}

/** Los ajustes se guardan en /data, NO en el archivo del repo: un despliegue no
 *  puede borrar una subida de precios que ya se aplicó. */
function mz_ruta_ajustes(int $cat): string {
    return (getenv('DATA_DIR') ?: '/data') . "/ajustes_{$cat}.json";
}
function mz_ajustes(int $cat): array {
    $j = json_decode((string)@file_get_contents(mz_ruta_ajustes($cat)), true);
    return is_array($j) ? $j : [];
}
function mz_ajustes_guardar(int $cat, array $lista): void {
    $p = mz_ruta_ajustes($cat);
    $t = $p . '.tmp';
    file_put_contents($t, json_encode($lista, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX);
    rename($t, $p);
}

/** Config del proyecto con sus ajustes vivos ya incorporados. */
function mz_cfg(int $cat): ?array {
    $todos = mz_proyectos();
    if (!isset($todos[$cat])) return null;
    $cfg = $todos[$cat];
    // Los del archivo son la semilla; los de /data son los que se fueron aplicando.
    $cfg['ajustes'] = array_merge($cfg['ajustes'] ?? [], mz_ajustes($cat));
    return $cfg;
}

function mz_grupo_de(array $cfg, string $ed): ?string {
    foreach ($cfg['grupos'] as $g => $d) {
        if (in_array($ed, $d['edificios'], true)) return $g;
    }
    return null;
}

function mz_edificios(array $cfg, bool $soloLanzados = false): array {
    $out = [];
    foreach ($cfg['grupos'] as $d) {
        if ($soloLanzados && !($d['lanzado'] ?? true)) continue;
        foreach ($d['edificios'] as $e) $out[] = $e;
    }
    sort($out);
    return $out;
}

/**
 * Matriz vigente POR EDIFICIO, con las tres capas aplicadas.
 * Un edificio con override deja de compartir matriz con su grupo, y eso basta:
 * nadie tiene que acordarse de partir el grupo a mano.
 */
function mz_precios_vigentes(array $cfg): array {
    $out = [];
    foreach (mz_edificios($cfg) as $ed) {
        $g = mz_grupo_de($cfg, $ed);
        $m = [];
        foreach (($cfg['precios'][$g] ?? []) as $n => $cats) $m[$n] = $cats;
        foreach (($cfg['overrides'][$ed] ?? []) as $n => $cats) {
            $m[$n] = array_merge($m[$n] ?? [], $cats);
        }
        $out[$ed] = $m;
    }

    foreach (($cfg['ajustes'] ?? []) as $aj) {
        $destino = (string)($aj['aplica_a'] ?? '*');
        if ($destino === '*')                      $objetivo = array_keys($out);
        elseif (isset($cfg['grupos'][$destino]))   $objetivo = $cfg['grupos'][$destino]['edificios'];
        else                                       $objetivo = [$destino];

        $niveles = ($aj['nivel'] ?? '*') === '*' ? array_keys($cfg['niveles']) : [$aj['nivel']];
        $cats    = ($aj['categoria'] ?? '*') === '*' ? array_keys($cfg['categorias']) : [$aj['categoria']];

        foreach ($objetivo as $ed) {
            foreach ($niveles as $n) {
                foreach ($cats as $c) {
                    $v = $out[$ed][$n][$c] ?? null;
                    if ($v === null) continue;      // sin precio base no se ajusta
                    if (isset($aj['monto']) && $aj['monto'] !== null) $v += (float)$aj['monto'];
                    if (isset($aj['pct'])   && $aj['pct']   !== null) $v *= 1 + (float)$aj['pct'] / 100;
                    $out[$ed][$n][$c] = (int)round($v);
                }
            }
        }
    }
    return $out;
}

/** Un override de unidad manda sobre el mapa de posiciones. null = sin definir. */
function mz_categoria_de(array $cfg, string $ed, int $pos, string $unidad = ''): ?string {
    $ov = $cfg['overrides_unidad'][$unidad] ?? [];
    if (!empty($ov['categoria'])) return (string)$ov['categoria'];
    $c = $cfg['posiciones'][$ed][(string)$pos] ?? null;
    return $c === null ? null : (string)$c;
}

function mz_unidades_por_piso(array $cfg, string $ed): int {
    return (int)($cfg['unidades_por_piso'][$ed] ?? 8);
}

function mz_metraje_de(array $cfg, string $ed, int $piso, ?string $cat): ?float {
    $g = mz_grupo_de($cfg, $ed);
    $m = $cfg['metraje'][$g] ?? null;
    if (!$m) return null;
    if (MZ_NIVEL_DE_PISO[$piso] === 'PB') return (float)$m['base'];
    if ($cat !== null && in_array($cat, $m['cats_mayor'] ?? [], true)) return (float)$m['mayor'];
    return (float)($cfg['metraje_pa_medianero'][$ed] ?? $m['base']);
}

/**
 * Precio objetivo de una unidad. `sigue_a` la hace heredar la matriz de otro
 * edificio — es el caso del registro de vista: C-2-8 no lo tiene, así que sigue a
 * D, y una subida futura a D también la arrastra sin que nadie se acuerde.
 */
function mz_precio_de(array $cfg, array $px, string $ed, int $piso, int $pos): array {
    $u   = "$ed-$piso-$pos";
    $cat = mz_categoria_de($cfg, $ed, $pos, $u);
    if ($cat === null) return [null, null];
    $fuente = (string)($cfg['overrides_unidad'][$u]['sigue_a'] ?? $ed);
    return [$cat, $px[$fuente][MZ_NIVEL_DE_PISO[$piso]][$cat] ?? null];
}

/**
 * Lee TODAS las unidades del pipeline, con su etapa y su PVP.
 *
 * Lanza si el API se corta a medias. Antes devolvía lo que alcanzara a leer, y eso
 * es lo peor que puede hacer: con un QUERY_LIMIT_EXCEEDED en la página 2 la
 * pantalla mostró "27 de 50 unidades" en vez de 110 de 304 — números falsos con
 * cara de verdaderos, y una subida de precios calculada sobre ellos habría tocado
 * solo el primer edificio.
 */
function mz_unidades(array $cfg): array {
    $bx = $cfg['bitrix'];
    $out = []; $start = 0; $paginas = 0;
    do {
        $r = bx('crm.item.list', [
            'entityTypeId' => $bx['entityTypeId'],
            'filter' => ['categoryId' => $bx['categoryId']],
            'select' => ['id', 'title', 'stageId', $bx['campo_pvp'], $bx['campo_m2']],
            'start'  => $start,
        ]);
        if (!$r['ok']) {
            throw new RuntimeException("Bitrix cortó la lectura en la página " . ($paginas + 1)
                . " ({$r['error']}). No se muestra nada antes que mostrar la mitad.");
        }
        $paginas++;
        foreach (($r['result']['items'] ?? []) as $it) {
            $t = strtoupper(trim((string)($it['title'] ?? '')));
            if (!preg_match('/^([A-Z])-(\d)-(\d)/', $t, $m)) continue;
            $out["{$m[1]}-{$m[2]}-{$m[3]}"] = [
                'id'    => (int)$it['id'],
                'etapa' => (string)($it['stageId'] ?? ''),
                'pvp'   => mz_money($it[$bx['campo_pvp']] ?? null),
                'm2'    => $it[$bx['campo_m2']] ?? null,
            ];
        }
        $start = $r['next'] ?? null;
    } while ($start !== null);
    return $out;
}

/**
 * Las unidades salen del caché que YA mantiene el resto del servicio.
 *
 * `selector_cache.json` guarda id, código, categoría, etapa, m² y PVP de cada
 * unidad, y lo actualizan los eventos del SPA en cuanto algo cambia en Bitrix
 * (más warm-catalogo cada 30 min como red). O sea: la información ya está aquí y
 * ya se pagó. Leerla otra vez desde Bitrix para esta pantalla eran 7 llamadas por
 * apertura, o 42 por hora con un cron — llamadas duplicadas contra un portal que
 * vive cerca de su techo.
 *
 * Coste de esta pantalla ahora: CERO llamadas.
 *
 * La etapa viene como NOMBRE ('DISPONIBLE'), no como STATUS_ID, porque los ids
 * difieren por pipeline. Por eso se compara por nombre.
 *
 * @param array $info ['edad' => segundos del caché, 'fresco' => bool]
 */
function mz_unidades_cache(array $cfg, int $ttl = 0, ?array &$info = null): array {
    $f = (getenv('DATA_DIR') ?: '/data') . '/selector_cache.json';
    $j = json_decode((string)@file_get_contents($f), true);
    $edad = is_file($f) ? time() - (int)@filemtime($f) : PHP_INT_MAX;
    if (!is_array($j) || empty($j['units'])) {
        throw new RuntimeException('El catálogo de unidades todavía no está listo. '
            . 'Se arma solo en unos minutos; vuelve a abrir.');
    }
    $cat = (int)$cfg['bitrix']['categoryId'];
    $out = [];
    foreach ($j['units'] as $u) {
        if ((int)($u['cat'] ?? 0) !== $cat) continue;
        $cod = strtoupper(trim((string)($u['codigo'] ?? '')));
        if (!preg_match('/^([A-Z])-(\d)-(\d)/', $cod, $m)) continue;
        $out["{$m[1]}-{$m[2]}-{$m[3]}"] = [
            'id'    => (int)($u['id'] ?? 0),
            'etapa' => strtoupper((string)($u['stage'] ?? '')),
            'pvp'   => mz_money($u['pvp'] ?? null),
            'm2'    => $u['m2'] ?? null,
        ];
    }
    if (!$out) throw new RuntimeException("El catálogo no trae unidades del proyecto $cat.");

    // El caché compartido a veces guarda la etapa vacía: cuando unidadlib no logró
    // resolver el nombre, deja ''. Son pocas, pero rompen los totales — 21 firmadas
    // desaparecían del conteo y la pantalla decía 134 donde hay 155.
    // Si aparece alguna, se relee de Bitrix esta vez. Cuesta 7 llamadas, pasa rara
    // vez, y es preferible a mostrar un inventario que no cuadra.
    $conocidas = ['DISPONIBLE', 'BLOQUEADO', 'RESERVADO', 'FIRMADO', 'VENDIDO', 'PERDIDO'];
    $huecos = 0;
    foreach ($out as $d) if (!in_array($d['etapa'], $conocidas, true)) $huecos++;
    if ($huecos > 0) {
        logline("MATRIZ cat=$cat · $huecos unidades sin etapa en el caché compartido, releyendo de Bitrix");
        $bx = mz_unidades($cfg);                       // por stageId, siempre exacto
        $nom = mz_nombres_etapa($cfg);
        foreach ($bx as $u => $d) {
            $bx[$u]['etapa'] = $nom[$d['etapa']] ?? '';
        }
        $info = ['edad' => 0, 'fresco' => true, 'huecos' => $huecos];
        return $bx;
    }

    $info = ['edad' => $edad, 'fresco' => $edad < 3600, 'huecos' => 0];
    return $out;
}

/**
 * Deja el caché compartido al día tras escribir precios, sin pedirle nada a Bitrix:
 * los valores nuevos ya se conocen. Los eventos del SPA también lo actualizarían,
 * pero tardan segundos y la pantalla se recarga antes — mostraría los precios viejos
 * justo después de subirlos, que es cuando más desconfianza genera.
 */
function mz_cache_actualizar(array $cfg, array $filas): void {
    $f = (getenv('DATA_DIR') ?: '/data') . '/selector_cache.json';
    $j = json_decode((string)@file_get_contents($f), true);
    if (!is_array($j) || empty($j['units'])) return;
    $nuevo = [];
    foreach ($filas as $r) if ($r['cambia'] && $r['objetivo'] !== null) $nuevo[(int)$r['id']] = (int)round($r['objetivo']);
    if (!$nuevo) return;
    foreach ($j['units'] as $i => $u) {
        $id = (int)($u['id'] ?? 0);
        if (isset($nuevo[$id])) $j['units'][$i]['pvp'] = $nuevo[$id] . '|USD';
    }
    @file_put_contents($f . '.tmp', json_encode($j), LOCK_EX);
    @rename($f . '.tmp', $f);
}

/** STATUS_ID -> NOMBRE de las etapas del pipeline. 1 llamada. */
function mz_nombres_etapa(array $cfg): array {
    $r = bx('crm.status.list', ['filter' => ['ENTITY_ID' =>
        'DYNAMIC_' . $cfg['bitrix']['entityTypeId'] . '_STAGE_' . $cfg['bitrix']['categoryId']]]);
    $out = [];
    foreach (($r['result'] ?? []) as $s) $out[(string)$s['STATUS_ID']] = strtoupper((string)$s['NAME']);
    return $out;
}


function mz_money($v): ?float {
    if ($v === null || $v === '') return null;
    $s = str_replace(',', '', explode('|', (string)$v)[0]);
    return is_numeric($s) ? round((float)$s, 2) : null;
}

/**
 * Qué cambiaría si se aplica la matriz tal como está hoy.
 * Solo mira unidades DISPONIBLES de edificios LANZADOS: es exactamente lo que
 * escribiría `mz_aplicar`, así que la vista previa no puede prometer de más.
 */
function mz_plan(array $cfg, array $unidades, ?array $px = null): array {
    $px = $px ?? mz_precios_vigentes($cfg);
    $lanzados = mz_edificios($cfg, true);
    // El caché guarda el NOMBRE de la etapa, no el STATUS_ID.
    $disp = 'DISPONIBLE';
    $exentas = $cfg['exentas'] ?? [];
    $filas = [];
    foreach ($unidades as $u => $d) {
        if ($d['etapa'] !== $disp) continue;
        [$ed, $piso, $pos] = explode('-', $u);
        if (!in_array($ed, $lanzados, true)) continue;
        if (isset($exentas[$u])) continue;
        if ((int)$pos > mz_unidades_por_piso($cfg, $ed)) continue;
        [$cat, $tgt] = mz_precio_de($cfg, $px, $ed, (int)$piso, (int)$pos);
        $filas[] = [
            'u' => $u, 'id' => $d['id'], 'ed' => $ed, 'piso' => (int)$piso, 'pos' => (int)$pos,
            'cat' => $cat, 'actual' => $d['pvp'], 'objetivo' => $tgt,
            'cambia' => ($tgt !== null && $d['pvp'] !== null && abs($tgt - $d['pvp']) >= MZ_TOLERANCIA),
        ];
    }
    usort($filas, fn($a, $b) => strcmp($a['u'], $b['u']));
    return $filas;
}

/** Respaldos de precios, del más nuevo al más viejo. */
function mz_respaldos(int $cat): array {
    $g = glob((getenv('DATA_DIR') ?: '/data') . "/respaldo_{$cat}_*.json") ?: [];
    rsort($g);
    return $g;
}

/**
 * Escribe en Bitrix las unidades que cambian. Devuelve [escritas, errores, respaldo].
 * No decide nada: recibe el plan ya calculado, para que lo que se muestra en la
 * vista previa sea literalmente lo que se escribe.
 *
 * ANTES de tocar nada guarda el PVP anterior de cada unidad. Estos son precios de
 * unidades reales que el equipo comercial esta cotizando: sin el respaldo, una
 * subida equivocada solo se deshace a mano, ficha por ficha, adivinando cual era
 * el numero de antes. Con el, se restaura exacto.
 */
function mz_aplicar(array $cfg, array $filas): array {
    $campo = $cfg['bitrix']['campo_pvp'];
    $cat   = (int)$cfg['bitrix']['categoryId'];

    $previo = [];
    foreach ($filas as $r) {
        if ($r['cambia']) $previo[$r['u']] = ['id' => $r['id'], 'pvp' => $r['actual']];
    }
    if (!$previo) return [0, [], null];

    $ruta = (getenv('DATA_DIR') ?: '/data') . "/respaldo_{$cat}_" . gmdate('Ymd-His') . '.json';
    file_put_contents($ruta, json_encode($previo, JSON_UNESCAPED_UNICODE), LOCK_EX);

    $ok = 0; $err = [];
    foreach ($filas as $r) {
        if (!$r['cambia']) continue;
        $res = bx('crm.item.update', [
            'entityTypeId' => $cfg['bitrix']['entityTypeId'],
            'id'     => $r['id'],
            'fields' => [$campo => ((int)round($r['objetivo'])) . '|USD'],
        ]);
        if ($res['ok']) $ok++;
        else $err[] = "{$r['u']}: {$res['error']}";
    }
    return [$ok, $err, basename($ruta)];
}

/**
 * Devuelve los precios al valor que tenian antes de una subida.
 * Restaura EXACTO lo guardado, no recalcula: si se recalculara volveria a salir el
 * precio nuevo y no se desharia nada.
 */
function mz_restaurar(array $cfg, string $archivo, ?array $cfgCache = null): array {
    $ruta = (getenv('DATA_DIR') ?: '/data') . '/' . basename($archivo);
    $j = json_decode((string)@file_get_contents($ruta), true);
    if (!is_array($j) || !$j) return [0, ['No se encontró el respaldo.']];
    $campo = $cfg['bitrix']['campo_pvp'];
    $ok = 0; $err = []; $vuelta = [];
    foreach ($j as $u => $d) {
        if ($d['pvp'] === null) continue;      // no tenia precio: se deja como esta
        $res = bx('crm.item.update', [
            'entityTypeId' => $cfg['bitrix']['entityTypeId'],
            'id'     => (int)$d['id'],
            'fields' => [$campo => ((int)round((float)$d['pvp'])) . '|USD'],
        ]);
        if ($res['ok']) {
            $ok++;
            $vuelta[] = ['id' => (int)$d['id'], 'objetivo' => (float)$d['pvp'], 'cambia' => true];
        } else $err[] = "$u: {$res['error']}";
    }
    if ($cfgCache && !empty($vuelta)) mz_cache_actualizar($cfgCache, $vuelta);
    return [$ok, $err];
}
