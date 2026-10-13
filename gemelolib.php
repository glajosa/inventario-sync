<?php
/**
 * gemelolib.php — el deal de COBRANZAS (48) puede tomar la unidad que ya tiene su
 * GEMELO de CLIENTES (44): la misma venta, el mismo contacto.
 * ---------------------------------------------------------------------------
 * Caso real 13-oct-2026, Gema Zevallos: el director cambio la unidad en el 44 (6220,
 * D-2-22 -> D-2-21) y despues habia que reubicar el 48 (6302) a esa misma unidad. El
 * selector la mostraba gris y guardar.php la rechazaba: "atada a otro deal". Era su
 * propio 44. Se resolvio a mano desde el servidor.
 *
 * Condiciones del orquestador (VIGILANTE BITRIX, 13-oct), todas en gemelo_decide():
 *  1. el 48 esta EN la etapa REUBICACION, o en la ventanita que lo lleva ahi (pendiente).
 *  2. el dueno 44 es SU gemelo: el contacto tiene UN SOLO 44 abierto y es ese. Con dos
 *     ventas, un 48 se podria llevar la unidad de la otra: se rechaza, "elegir a mano".
 *  3. los deals del contacto salen de la LIBRETA (el guardado cae a Bitrix una vez,
 *     anotando el motivo: reub_deals_contacto; la pantalla NO va a Bitrix).
 *  4. nunca BLOQUEADO/PERDIDO; FIRMADO/VENDIDO se rechaza con aviso (lo ve una persona).
 * Perilla `reubica_gemelo` en /data/config.json (0 = como antes).
 * Lo usan field.php (para no mostrarla gris) y guardar.php (el que decide de verdad).
 */
declare(strict_types=1);

const GEMELO_ETAPA_REUBICACION = 'C48:UC_1WR2BM';
const GEMELO_44_CAIDAS = ['C44:LOSE', 'C44:APOLOGY'];   // un 44 en estas ya no es venta abierta

/** Perilla, leida de config.json sin depender de nada mas (la pantalla es liviana). */
function gemelo_perilla(): bool {
    $j = json_decode((string)@file_get_contents((getenv('DATA_DIR') ?: '/data') . '/config.json'), true);
    return !is_array($j) || !array_key_exists('reubica_gemelo', $j) || (int)$j['reubica_gemelo'] === 1;
}

/**
 * ¿Puede el 48 tomar esta unidad que ya tiene dueno? PURA.
 * $dealsContacto: los deals del contacto del 48 (null = no se pudo leer).
 * Devuelve ['ok' => bool, 'motivo' => texto para el vendedor/log].
 */
function gemelo_decide(int $dueno, string $etapaUnidad, string $etapa48, bool $pendiente,
                       ?array $dealsContacto, bool $perilla = true): array {
    $no = fn(string $m) => ['ok' => false, 'motivo' => $m];
    if (!$perilla) return $no('regla del gemelo apagada');
    if ($dueno <= 0) return $no('la unidad no tiene dueno');
    if ($etapa48 !== GEMELO_ETAPA_REUBICACION && !$pendiente)
        return $no('el deal de cobranzas no esta en REUBICACION');
    if ($dealsContacto === null) return $no('no se pudieron leer las ventas del cliente');

    $abiertos = [];
    foreach ($dealsContacto as $d) {
        if ((int)($d['CATEGORY_ID'] ?? -1) !== 44) continue;
        if (in_array((string)($d['STAGE_ID'] ?? ''), GEMELO_44_CAIDAS, true)) continue;
        $abiertos[] = (int)($d['ID'] ?? 0);
    }
    if (count($abiertos) === 0) return $no('el cliente no tiene ninguna venta abierta en Clientes');
    if (count($abiertos) > 1)   return $no('el cliente tiene varias ventas, elegir a mano');
    if ($abiertos[0] !== $dueno) return $no('la unidad es de otra venta, no de la de este cliente');

    $e = strtoupper(trim($etapaUnidad));
    if (in_array($e, ['BLOQUEADO', 'PERDIDO'], true)) return $no("la unidad esta $e");
    if (in_array($e, ['FIRMADO', 'VENDIDO'], true))
        return $no("la unidad esta $e en Clientes: la reubicacion la tiene que revisar una persona");
    return ['ok' => true, 'motivo' => "es la unidad de su propia venta en Clientes (deal $dueno)"];
}

/** Deals del contacto desde la LIBRETA, sin caer a Bitrix (para la pantalla). Null si no se pudo. */
function gemelo_deals_libreta(int $contacto): ?array {
    $base = rtrim((string)getenv('LIBRETA_URL'), '/');
    $tok  = (string)getenv('LIBRETA_TOKEN');
    if ($contacto <= 0 || $base === '' || $tok === '') return null;
    $ch = curl_init($base . '/deals?contact=' . $contacto);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 3, CURLOPT_CONNECTTIMEOUT => 2,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $tok, 'X-Libreta-Cliente: inventario-gemelo']]);
    $raw = curl_exec($ch);
    $st = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    unset($ch);
    $j = is_string($raw) ? json_decode($raw, true) : null;
    if ($st !== 200 || !is_array($j)) return null;
    $out = [];
    foreach ((array)($j['items'] ?? []) as $i) if (is_array($i['dato'] ?? null)) $out[] = $i['dato'];
    return $out;
}
