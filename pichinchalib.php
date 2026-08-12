<?php
/**
 * pichinchalib.php — Simulación de crédito hipotecario Banco Pichincha.
 *
 * Reproduce lo que devuelve su calculadora pública (creditohipotecario.pichincha.com).
 * Se usa para el 70% de CONTRA ENTREGA de Galero: el cliente paga el 30% de entrada y
 * el resto lo cubre con el banco, así que el asesor necesita poder decir "tu cuota
 * mensual sería $X" sin salir de la cotización.
 *
 * ── QUÉ ESTÁ VERIFICADO ────────────────────────────────────────────────────────
 * Contra TRES simulaciones reales de su calculadora (2026-08-12):
 *   A) vivienda 100.000 · préstamo  70.000 · 20 años
 *   B) vivienda 200.000 · préstamo 140.000 · 15 años
 *   C) vivienda 150.000 · préstamo 100.000 · 10 años
 *
 * Exactas (cuadran al centavo en las tres):
 *   · La cuota de capital+interés es FRANCÉS sobre el TOTAL FINANCIADO, no sobre el
 *     monto solicitado. Esto es lo que más se equivoca al copiar la calculadora.
 *   · SOLCA = 0,5% de (préstamo + avalúo + gastos legales).
 *   · Total a financiar = préstamo + avalúo + legales + SOLCA.
 *   · Seguro contra incendio y terremoto = 0,02524% MENSUAL del PRECIO DE LA VIVIENDA
 *     (no del préstamo). Dio idéntico en los tres puntos.
 *   · Seguro de desgravamen ≈ 0,0572% mensual del total financiado (±5 centavos).
 *
 * ── QUÉ ES APROXIMADO Y POR QUÉ ────────────────────────────────────────────────
 * El AVALÚO y los GASTOS LEGALES no son fórmulas: son tablas por tramos, como el
 * arancel notarial. Se comprobó: se ajustó una recta con dos puntos, se predijo el
 * tercero ANTES de consultarlo y falló por $76,80 en los legales. Su API
 * (/api/v1/research/calculator/) devuelve 401 y la página va detrás de reCAPTCHA, así
 * que no se puede consultar desde el servidor.
 * Se interpola linealmente entre los tres puntos conocidos y se extiende con la
 * pendiente del tramo más cercano fuera de rango. Por eso la simulación se muestra
 * SIEMPRE rotulada como REFERENCIAL: el número final lo confirma el banco.
 * Si algún día se consiguen las tablas, se reemplazan estas dos anclas y listo.
 */

/** Parámetros que pueden cambiar sin avisar. El vigilante (pichincha-tasa.php) los
 *  compara contra la página y avisa si Pichincha los movió. */
function pich_params(): array {
    $f = (getenv('DATA_DIR') ?: '/data') . '/pichincha_params.json';
    $base = [
        'tasa_nueva_usada' => 9.02,   // % anual · Vivienda nueva o usada
        'tasa_miti_miti'   => 4.87,   // % anual · Vivienda Miti Miti (tope $110.378)
        'solca_pct'        => 0.005,  // 0,5% sobre (préstamo + avalúo + legales)
        'desgravamen_mes'  => 0.000572,   // sobre el TOTAL financiado
        'incendio_mes'     => 0.0002524,  // sobre el PRECIO DE LA VIVIENDA
        'anios_min'        => 3,
        'anios_max'        => 20,
        'monto_min'        => 3000,
        'vivienda_min'     => 15000,
    ];
    $j = json_decode((string)@file_get_contents($f), true);
    return is_array($j) ? array_merge($base, $j) : $base;
}

/** Interpolación lineal por tramos sobre puntos observados. Fuera de rango extiende
 *  con la pendiente del tramo del borde — no inventa una curva que no se midió. */
function pich_interp(array $puntos, float $x): float {
    $xs = array_keys($puntos); sort($xs);
    $n = count($xs);
    if ($n === 0) return 0.0;
    if ($n === 1) return (float)$puntos[$xs[0]];
    if ($x <= $xs[0]) {
        $m = ($puntos[$xs[1]] - $puntos[$xs[0]]) / ($xs[1] - $xs[0]);
        return max(0.0, $puntos[$xs[0]] + $m * ($x - $xs[0]));
    }
    if ($x >= $xs[$n-1]) {
        $m = ($puntos[$xs[$n-1]] - $puntos[$xs[$n-2]]) / ($xs[$n-1] - $xs[$n-2]);
        return max(0.0, $puntos[$xs[$n-1]] + $m * ($x - $xs[$n-1]));
    }
    for ($i = 0; $i < $n - 1; $i++) {
        if ($x >= $xs[$i] && $x <= $xs[$i+1]) {
            $m = ($puntos[$xs[$i+1]] - $puntos[$xs[$i]]) / ($xs[$i+1] - $xs[$i]);
            return max(0.0, $puntos[$xs[$i]] + $m * ($x - $xs[$i]));
        }
    }
    return 0.0;
}

/** Gastos de avalúo — depende del PRECIO DE LA VIVIENDA. Puntos medidos. */
function pich_avaluo(float $vivienda): float {
    return pich_interp([100000 => 169.34, 150000 => 186.62, 200000 => 206.98], $vivienda);
}

/** Gastos legales — depende del MONTO SOLICITADO. Puntos medidos. */
function pich_legales(float $prestamo): float {
    return pich_interp([70000 => 3428.50, 100000 => 3928.50, 140000 => 4774.36], $prestamo);
}

/**
 * Simula el crédito. Devuelve la misma información que muestra su pantalla.
 *
 * @param float $vivienda  precio de la vivienda (para el seguro de incendio y el avalúo)
 * @param float $prestamo  monto solicitado
 * @param int   $anios     plazo (3 a 20)
 * @param float|null $tasa tasa anual en %; null = la de vivienda nueva o usada
 */
function pich_simular(float $vivienda, float $prestamo, int $anios, ?float $tasa = null, string $sistema = 'frances'): array {
    $p = pich_params();
    $vivienda = max(0.0, $vivienda);
    $prestamo = max(0.0, $prestamo);
    $anios    = max((int)$p['anios_min'], min((int)$p['anios_max'], $anios));
    $tasa     = $tasa !== null ? $tasa : (float)$p['tasa_nueva_usada'];

    $avaluo  = pich_avaluo($vivienda);
    $legales = pich_legales($prestamo);
    $solca   = ((float)$p['solca_pct']) * ($prestamo + $avaluo + $legales);
    $total   = $prestamo + $avaluo + $legales + $solca;

    $n = $anios * 12;
    $i = ($tasa / 100.0) / 12.0;
    $aleman = ($sistema === 'aleman');

    // FRANCÉS: cuota fija. ALEMÁN: capital fijo e interés sobre el saldo, así que la
    // cuota ARRANCA más alta y va bajando. La diferencia no es cosmética: el banco
    // califica al cliente por la PRIMERA cuota, y en un crédito a 20 años el alemán
    // empieza ~30% más caro pero ahorra ~22% de intereses. Por eso se muestran las dos.
    $capFijo   = $n > 0 ? $total / $n : 0.0;
    $capIntFr  = ($i > 0) ? $total * $i / (1 - pow(1 + $i, -$n)) : $capFijo;
    $capIntPri = $aleman ? ($capFijo + $total * $i) : $capIntFr;                 // primera
    $capIntUlt = $aleman ? ($capFijo + $capFijo * $i) : $capIntFr;               // última
    // Intereses totales del alemán: serie aritmética sobre los saldos.
    $intAleman = ($n > 0) ? $i * $total * ($n + 1) / 2 : 0.0;
    $capInt    = $capIntPri;   // lo que se muestra arriba es SIEMPRE la primera cuota

    $desgrav  = $total * (float)$p['desgravamen_mes'];
    $incendio = $vivienda * (float)$p['incendio_mes'];
    $cuota    = $capInt + $desgrav + $incendio;

    return [
        'vivienda'  => $vivienda,
        'prestamo'  => $prestamo,
        'anios'     => $anios,
        'meses'     => $n,
        'tasa'      => $tasa,
        'avaluo'    => $avaluo,
        'legales'   => $legales,
        'solca'     => $solca,
        'total'     => $total,
        'capInt'    => $capInt,
        'desgrav'   => $desgrav,
        'incendio'  => $incendio,
        'cuota'     => $cuota,
        'sistema'      => $aleman ? 'aleman' : 'frances',
        'capIntPrimera'=> $capIntPri,
        'capIntUltima' => $capIntUlt,
        'cuotaPrimera' => $capIntPri + $desgrav + $incendio,
        'cuotaUltima'  => $capIntUlt + $desgrav + $incendio,
        'totalInteres' => $aleman ? $intAleman : max(0.0, $capIntFr * $n - $total),
        'totalSeguros' => ($desgrav + $incendio) * $n,
        // Para la dona: qué porción de la cuota es cada cosa.
        'pctCapInt'   => $cuota > 0 ? $capInt   / $cuota * 100 : 0,
        'pctDesgrav'  => $cuota > 0 ? $desgrav  / $cuota * 100 : 0,
        'pctIncendio' => $cuota > 0 ? $incendio / $cuota * 100 : 0,
    ];
}
