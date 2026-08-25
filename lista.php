<?php
/**
 * lista.php — la LISTA DE PRECIOS de una familia, en el formato de la direccion.
 * ---------------------------------------------------------------------------
 * El formato NO es nuestro: se reproduce el que ya usa la direccion y que vive en
 * `~/Downloads/GALJOSA - Todos los sistemas de precios`. Una fila por TIPOLOGIA,
 * banda vertical con el piso, colores por lado del parque, fila DESDE para los
 * combos, y el aviso de escasez cuando quedan una o dos.
 *
 * Lo unico que agrega esta version: se arma del inventario EN VIVO. La lista de
 * la direccion es un PDF que alguien regenera cada tanto, y el dia que una unidad
 * se vende la lista la sigue ofreciendo.
 *
 *   ?token=...&cat=33&fam=1951     oficinas y consultorios de Noral Plaza
 *
 * Cada familia declara su forma y su plazo en el bloque `listas` de su JSON.
 * ---------------------------------------------------------------------------
 */

declare(strict_types=1);

require_once __DIR__ . '/matrizlib.php';
require_once __DIR__ . '/listalib.php';

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

$esperado = (string)getenv('OUTBOUND_TOKEN');
if ($esperado === '' || !hash_equals($esperado, (string)($_GET['token'] ?? ''))) {
    http_response_code(403); exit('forbidden');
}

function lh(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
/** Precio grande: la direccion lo escribe sin centavos ($ 144,420). */
function lp(float $v): string { return '$ ' . number_format($v, 0); }
/** Cifras del plan: con centavos ($1,000.00). */
function ln(float $v): string { return '$' . number_format($v, 2); }

$cat = (int)($_GET['cat'] ?? 0);
$fam = (int)($_GET['fam'] ?? 0);
$tok = (string)($_GET['token'] ?? '');

$cfgFile = __DIR__ . "/matrices/proyecto_$cat.json";
$cfg = is_file($cfgFile) ? json_decode((string)file_get_contents($cfgFile), true) : null;
if (!$cfg) { http_response_code(404); exit('proyecto sin matriz'); }
$proyecto = (string)($cfg['proyecto'] ?? "Proyecto $cat");

try {
    $unidades = mz_unidades_cache($cfg);
} catch (Throwable $e) {
    http_response_code(503);
    exit('<!doctype html><meta charset="utf-8"><p style="font:15px system-ui;padding:40px">'
       . lh($e->getMessage()) . '</p>');
}

$familias = lst_familias($unidades, $cat);
$LISTAS   = $cfg['listas'] ?? [];
// Sin familia pedida: la primera que TENGA lista declarada, no la mas grande —
// abrir en una familia que todavia no tiene formato es mostrar una pagina vacia.
if ($fam === 0) {
    foreach ($familias as $t => $_) if (isset($LISTAS[(string)$t])) { $fam = $t; break; }
    if ($fam === 0 && $familias) $fam = (int)array_key_first($familias);
}
$L = $LISTAS[(string)$fam] ?? null;
$nombreFam = lst_nombre_familia($cat, $fam);

$hoy = new DateTimeImmutable('now');
?>
<!doctype html>
<html lang="es"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Lista de Precios · <?= lh($proyecto) ?> · <?= lh($nombreFam) ?></title>
<style>
  /* El CSS es el de la lista de la direccion. No se "moderniza": el equipo comercial
     reconoce este documento y el cliente ya lo vio asi. */
  @page { size: A4 landscape; margin: 12mm; }
  *{box-sizing:border-box;margin:0;padding:0}
  body{font-family:Calibri,'Segoe UI',Arial,sans-serif;background:#e9e9e6;color:#000;padding:18px}
  .hoja{background:#fff;padding:20px 26px 26px;margin:0 auto;max-width:1120px;
        box-shadow:0 1px 5px rgba(0,0,0,.16)}
  /* El logo va SOLO y arriba a la izquierda, ENCIMA de la tabla — asi esta en el
     documento de la direccion. El de Galjosa no aparece en la lista de precios, y el
     titulo no va al lado del logo sino como banda a todo el ancho de la tabla. */
  .cab{display:flex;align-items:flex-end;justify-content:space-between;gap:20px;margin-bottom:9px}
  .cab .logo{height:58px;width:auto}
  .leyenda{display:flex;gap:22px;font-size:10.5px;color:#3b3b38;padding-bottom:3px}
  .leyenda span{display:inline-flex;align-items:center;gap:7px;white-space:nowrap}
  .leyenda i{width:26px;height:12px;display:inline-block;border:1px solid #7d7d76}
  .leyenda i.lin{background:#e3ecd4}
  .leyenda i.cen{background:#4a6329}
  .cab .tit{flex:1}
  /* Departamentos mete el logo DENTRO de la tabla: ocupa la banda y el nombre durante
     las tres filas de cabecera. */
  td.celda-logo{background:#fff;text-align:center;vertical-align:middle;padding:8px 10px}
  td.celda-logo img{height:56px;width:auto;max-width:100%}
  /* Layout 'al_lado': el logo a la izquierda y la banda del titulo a su derecha, a la
     misma altura, como en los documentos de Oficinas y Departamentos. */
  /* La celda del logo dentro de la tabla: sin bordes internos que la partan y con
     aire alrededor, como en el documento. */
  /* Layout 'al_lado': el logo a la izquierda y el recuadro del titulo a su derecha,
     los dos ARRIBA de la tabla. La tabla empieza debajo, alineada con el logo. */
  .cab-lado{align-items:center;margin-bottom:0}
  .cab-lado .logo{height:62px}
  .cab-lado .tit{flex:1}
  .cab-lado .tit table{border-collapse:collapse}
  /* Banda lateral DERECHA, dentro de la tabla: la nota de consultorios de Oficinas
     abarca todas las filas y va en vertical, como en el documento. */
  td.lat-cel{background:#3b5323;color:#fff;font-size:10.5px;font-weight:700;
             text-align:center;vertical-align:middle;padding:6px 3px;width:26px}
  td.lat-cel span{writing-mode:vertical-rl;transform:rotate(180deg);white-space:nowrap}
  td.lat-cel em{font-style:italic}
  /* Notas al pie del documento: van en negrita la primera parte, como en el PDF. */
  .notas{margin-top:10px;font-size:10.5px;line-height:1.7;color:#1a1a18}
  .notas p{margin:0}
  .notas b{font-weight:700}
  /* Pie de dos bloques: el rotulo verde oscuro y el rango de metros en verde claro,
     a todo el ancho. Asi cierra el documento de Departamentos. */
  .pie2{display:flex;margin-top:0;border:1px solid #000;border-top:0;font-size:11px;font-weight:700}
  .pie2 b{background:#4a6329;color:#fff;padding:5px 14px;white-space:nowrap}
  .pie2 span{background:#8fae5d;color:#1a1a1a;padding:5px 14px;flex:1;text-align:center}
  /* Oficinas cierra con una pastilla corta pegada al rotulo y el resto en blanco;
     Departamentos estira la banda a todo el ancho. */
  .pie2.corto{border:0}
  .pie2.corto b{border:1px solid #000;border-top:0}
  .pie2.corto span{flex:0 0 auto;border:1px solid #000;border-top:0;border-left:0}
  .wrap{display:flex;gap:0;align-items:stretch}
  .wrap .lat{background:#3b5323;color:#fff;writing-mode:vertical-rl;transform:rotate(180deg);
       display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:700;
       padding:8px 5px;border:1px solid #000;border-left:0;letter-spacing:.03em}
  .lat em{font-style:italic}
  table{width:100%;border-collapse:collapse;font-size:11.5px}
  th,td{border:1px solid #000;padding:5.5px 6px}
  .titulo{background:#4a6329;color:#fff;font-size:15px;font-weight:700;
          text-align:center;padding:7px;letter-spacing:.02em}
  .sub{text-align:center;font-size:11.5px;font-weight:700;padding:5px;background:#fff}
  .g th{font-weight:700;text-align:center;font-size:11px}
  .g .it{font-style:italic}
  .c th{font-weight:700;text-align:center;font-size:9.5px;line-height:1.25;padding:6px 4px}
  .niv{color:#fff;font-weight:700;font-size:10px;text-align:center;width:34px;padding:2px}
  .niv span{writing-mode:vertical-rl;transform:rotate(180deg);white-space:nowrap}
  /* La banda del bloque es OCRE en el documento de la direccion, no verde: el verde
     ya lo usa la vista al parque central y repetirlo confundia las dos cosas. */
  /* Tres colores para las bandas de piso, como en el documento de Oficinas: el 2do
     verde oscuro, el 3ro verde medio y el 4to ocre. Tenerlas todas del mismo color
     hacia imposible ver de un golpe donde empieza cada piso. */
  .niv2{background:#3b5323}.niv3{background:#6b8e3d}.niv4{background:#b8860b}
  .niv5{background:#4a5d6b}.niv6{background:#7a4b00}
  /* Bandas por NOMBRE: el documento decide el color, no el numero de piso. En
     Locales los dos bloques son ocres; en Oficinas van oscuro, medio y ocre. */
  .niv-oscuro{background:#3b5323}.niv-medio{background:#6b8e3d}.niv-ocre{background:#b8860b}
  /* Codigo de color de la VISTA, calcado del PDF de la direccion: el parque lineal va
     en un verde muy palido y el CENTRAL en verde OSCURO con texto blanco. No son dos
     tonos del mismo palo — el central es el producto caro y se ve de lejos.
     td.cat y no .cat: `td:first-child{text-align:left}` le gana por especificidad y
     los nombres quedaban a la izquierda cuando en el documento van centrados. */
  td.cat{font-weight:700;font-size:11px;line-height:1.25;text-align:center;
         background:#eef4e4;color:#1f2d16}
  /* Locales centra los nombres; Oficinas y Departamentos los pegan a la izquierda.
     Las filas fuertes (DESDE, unidades unidas) van centradas en los tres. */
  td.cat.izq{text-align:left;padding-left:10px}
  td.cat.izq.fuerte{text-align:center;padding-left:6px}
  /* Codigo de color de la vista, tomado de la hoja de Excel original:
     crema para el parque lineal, verde palido para el parque central. */
  /* Tres tonos, y cada documento arma su par (ver `color_a`/`color_b` en el JSON).
     `lineal`/`central` quedan como alias de los dos claros para no romper las listas
     que todavia los nombran asi. */
  td.cat.crema, td.cat.lineal {background:#fdf6e3;color:#1f2d16}
  td.cat.palido,td.cat.central{background:#e3ecd4;color:#1f2d16}
  /* `fuerte` = verde oscuro con texto blanco. En Locales lo lleva la vista al parque
     central; en Departamentos, las unidades UNIDAS. Cada familia declara cual. */
  td.cat.fuerte{background:#4a6329;color:#fff}
  td.cat.fuerte .ult{color:#cfe0b4}
  .cat.combo{background:#3b5323;color:#fff;text-align:center;letter-spacing:.04em}
  .cat .ult{display:block;font-weight:600;font-size:6.5px;letter-spacing:.09em;
       margin-top:2px;color:#9a6a63;opacity:.9}
  td.c{text-align:center}
  td.p{text-align:right;font-weight:700;font-variant-numeric:tabular-nums}
  td.n{text-align:right;font-variant-numeric:tabular-nums}
  .pie{display:inline-flex;background:#3b5323;color:#fff;padding:5px 12px;
       border:1px solid #000;border-top:0;font-size:10.5px;font-weight:700}
  .pie span{background:#8fae5d;color:#1a1a1a;margin-left:14px;padding:0 10px;
       font-size:9.5px;display:inline-flex;align-items:center}
  .vig{margin-top:12px;background:#ffff00;border:1px solid #000;text-align:center;
       font-size:11px;font-weight:700;padding:6px}
  .meta{margin-top:7px;font-size:9.5px;color:#777;text-align:center}
  /* Barra de familias: es navegacion nuestra y NO va en el papel. */
  .barra{max-width:1120px;margin:0 auto 14px;display:flex;gap:8px;flex-wrap:wrap;align-items:center}
  .barra a,.barra button{font:600 12.5px/1 Calibri,'Segoe UI',Arial,sans-serif;padding:8px 13px;
       border:1px solid #b9bdb4;background:#fff;color:#26301c;text-decoration:none;cursor:pointer;
       border-radius:3px}
  .barra a.on{background:#3b5323;border-color:#3b5323;color:#fff}
  .barra .imp{margin-left:auto;background:#3b5323;border-color:#3b5323;color:#fff}
  .aviso{max-width:1120px;margin:0 auto 14px;background:#fff3cd;border:1px solid #e0c874;
       padding:10px 14px;font-size:12px}
  /* ── forma SOLAR (Sun Bay) ── */
  td.mz{font-weight:700;text-align:center}
  td.sal{text-align:right;font-variant-numeric:tabular-nums;background:#fce9e2;white-space:nowrap}
  .esp{height:14px;border:0}
  .nota{max-width:1120px;margin:12px auto 0;background:#ffff00;border:1px solid #000;
        text-align:center;font-size:10px;font-weight:700;padding:5px;line-height:1.5}
  .nota em{font-style:italic;text-decoration:underline}
  /* ── forma HIPOTECARIO (Torre C, Suites) ── */
  td.n.s-sep{background:#F4F8FB}
  td.n.s-ent{background:#FDF2EC}
  td.n.s-pre{background:#F4F9FD}
  td.n.s-cuo{background:#FAFAFA}
  .pies{display:flex;gap:10px;margin-top:12px;flex-wrap:wrap}
  .pieh{display:inline-flex;font-size:10.5px;font-weight:700;border:1px solid #000}
  .pieh b{background:#DDEBF7;padding:5px 14px;border-right:1px solid #000}
  .pieh span{background:#FCE4D6;padding:5px 14px}
  @media print{body{background:#fff;padding:0}.hoja{box-shadow:none}.barra,.aviso{display:none}}
</style>
</head><body>

<div class="barra">
  <?php foreach ($familias as $t => $f): ?>
    <a class="<?= $t === $fam ? 'on' : '' ?>"
       href="?token=<?= lh($tok) ?>&cat=<?= $cat ?>&fam=<?= (int)$t ?>"
    ><?= lh($f['nombre']) ?> (<?= (int)$f['n'] ?>)</a>
  <?php endforeach; ?>
  <button class="imp" onclick="window.print()">Descargar PDF</button>
</div>

<?php if (!$L): ?>
  <div class="aviso">
    <b><?= lh($nombreFam) ?></b> todavía no tiene el formato de lista declarado en
    <code>matrices/proyecto_<?= $cat ?>.json</code> (bloque <code>listas</code>).
    Las familias con formato están en la barra de arriba.
  </div>
<?php else:
  $forma = (string)($L['forma'] ?? 'tipologia');
  if ($forma !== 'tipologia') {
      // Las otras dos formas comparten cabecera y pie con esta, pero la tabla es
      // otra: en Sun Bay la fila es un SOLAR y en Torre C / Suites el pago es
      // entrada + credito hipotecario, no cuotas del constructor.
      include __DIR__ . '/lista_' . preg_replace('/[^a-z]/', '', $forma) . '.php';
      echo '</body></html>'; exit;
  }
  $fin   = $L['financiamiento'] ?? [];
  $grupos = (array)($L['grupos'] ?? []);
  $niveles = (array)($L['niveles'] ?? []);
  $orden  = (array)($L['orden'] ?? []);
  $px     = mz_precios_vigentes($cfg);
  $combo  = $L['combo'] ?? null;
  $conParq = !empty($L['parqueos']);

  // Se arma primero y se dibuja despues: hace falta saber cuantas filas tiene cada
  // piso para el rowspan de la banda vertical, y cuales pisos quedaron sin nada.
  // Una HOJA por grupo cuando la familia lo pide. La direccion saca una tabla por
  // edificio ("EDIFICIO A", "EDIFICIO B"): juntar D, E y F en una sola mezclaba
  // precios de edificios distintos bajo el mismo rotulo de piso.
  // Tres formas de partir el documento:
  //   nada                  -> una sola tabla con todo
  //   una_tabla_por_grupo   -> una por GRUPO DE PRECIO (los monoambientes de Plaza)
  //   una_tabla_por_edificio-> una por EDIFICIO (Apartments: A, B, C... hasta I)
  // Apartments necesita la tercera: la direccion saca nueve tablas, una por edificio,
  // aunque B, C, D y E compartan precio. Agrupandolas en una sola "BCDE" el vendedor
  // no encuentra su edificio y los metrajes distintos (GH son 106, I son 65) quedaban
  // escondidos detras de un rotulo que no nombra ninguno.
  $porEdificio = !empty($L['una_tabla_por_edificio']);
  $porGrupo    = !empty($L['una_tabla_por_grupo']);
  $excluir     = (array)($L['excluir_edificios'] ?? []);
  $hojas = [];
  if ($porEdificio) {
      $unidadesHoja = [];
      // Un grupo con lanzado=false queda fuera de la lista comercial aunque sus
      // unidades esten en Bitrix. Es la regla que declara el archivo de la direccion.
      foreach ($grupos as $g) {
          if (isset($cfg['grupos'][$g]['lanzado']) && !$cfg['grupos'][$g]['lanzado']) continue;
          foreach ((array)($cfg['grupos'][$g]['edificios'] ?? [$g]) as $ed)
              if (!in_array($ed, $excluir, true)) $unidadesHoja[] = $ed;
      }
      sort($unidadesHoja);
  } else {
      $unidadesHoja = $porGrupo ? $grupos : [null];
  }
  foreach ($unidadesHoja as $gSolo) {
      $gs = $gSolo === null ? $grupos : [$gSolo];
      $bloques = [];
      foreach ($niveles as $niv) {
          $filas = [];
          foreach ($gs as $g) {
              $eds = $porEdificio ? [$g] : (array)($cfg['grupos'][$g]['edificios'] ?? [$g]);
              $gDatos = $porEdificio ? mz_grupo_de($cfg, $g) : $g;   // metraje y notas van por GRUPO
              $precio = $px[$eds[0]][$niv] ?? null;
              if (!is_array($precio)) continue;
              foreach ($orden as $k) {
                  if (!isset($precio[$k])) continue;
                  $cel = lst_celda($cfg, $unidades, $eds, $niv, $k);
                  if (!$cel['cods']) continue;          // agotada: no se ofrece
                  $filas[] = ['cat' => $k, 'g' => $gDatos, 'precio' => (float)$precio[$k],
                              'm2' => lst_metros($cel['m2'], $cfg, $gDatos, $k,
                                          (string)($L['origen_metraje'] ?? 'unidades'),
                                          $niv, (string)($L['nivel_patio'] ?? '')),
                              'cods' => $cel['cods']];
              }
          }
          if ($combo && $filas) {
              // El grupo de precio del que sale el metraje del combo: con una hoja por
              // edificio, B y E comparten el de BCDE.
              $gc  = $porEdificio ? mz_grupo_de($cfg, (string)$gs[0]) : (string)$gs[0];
              $eds = [];
              foreach ($gs as $g)
                  foreach (($porEdificio ? [$g] : (array)($cfg['grupos'][$g]['edificios'] ?? [$g])) as $e)
                      $eds[] = $e;
              // El PRECIO sale del par disponible mas barato, no de la tabla: "DESDE"
              // es una promesa y tiene que existir. Si hoy no queda ningun par que se
              // pueda unir, la fila no se dibuja.
              $par = lst_par_mas_barato($cfg, $unidades, $eds, $niv);
              if ($par) {
                  $cb = lst_combo($cfg, $gc, $niv);
                  $filas[] = ['combo' => true, 'precio' => $par['precio'], 'g' => $gc,
                              'par' => $par['a'] . ' + ' . $par['b'],
                              'm2' => $cb && $cb['m2'] !== null
                                  ? rtrim(rtrim(number_format((float)$cb['m2'], 2, ',', ''), '0'), ',')
                                  : '—',
                              'cods' => []];
              }
          }
          if ($filas) $bloques[$niv] = $filas;
      }
      if ($bloques) $hojas[] = ['grupo' => $gSolo, 'bloques' => $bloques];
  }
  // Apartments cobra el patio de la planta baja y lo lista en su propia columna:
  // sin ella dos filas de 75 m2 se ven iguales cuando una trae 16,25 m2 de patio.
  $conPatio = !empty($L['columna_patio_m2']);
  $nCols = 4 + ($conParq ? 1 : 0) + ($conPatio ? 1 : 0);
  if (!$hojas) $hojas = [['grupo' => null, 'bloques' => []]];
?>
<?php foreach ($hojas as $hoja): $bloques = $hoja['bloques'];
      $tit = $hoja['grupo'] === null
          ? (string)($L['titulo'] ?? strtoupper($proyecto))
          : ($porEdificio
              ? 'EDIFICIO ' . $hoja['grupo']
              : strtoupper((string)($cfg['grupos'][$hoja['grupo']]['etiqueta'] ?? ('EDIFICIO ' . $hoja['grupo'])))); ?>
<section class="hoja">
  <div class="cab">
    <?php /* Dos logos, como las listas de la direccion: el de Galjosa y el del
             PROYECTO. Sin el propio el documento parece de otra empresa. */
          $lg = lst_logo($cat, $L); ?>
    <img class="logo" src="assets/logo_galjosa_transparente.png"
         alt="Galjosa" onerror="this.style.display='none'">
    <?php if ($lg): ?>
      <img class="logo logo-proy" src="<?= lh($lg[0]) ?>"
           alt="<?= lh($lg[1] !== '' ? $lg[1] : $proyecto) ?>" onerror="this.style.display='none'">
    <?php endif; ?>
    <div class="tit">
      <table><tr><th class="titulo"><?= lh($tit) ?></th></tr>
             <tr><th class="sub"><?= lh((string)($L['subtitulo'] ?? '')) ?></th></tr></table>
    </div>
  </div>
  <div class="wrap">
    <table>
      <thead>
        <tr class="g"><th colspan="<?= $nCols ?>">CARACTER&Iacute;STICAS</th>
          <th colspan="2" class="it"><?= (int)($fin['reserva_pct'] ?? 10) ?>% DE RESERVA</th>
          <th><?= (int)($fin['cuotas_pct'] ?? 20) ?>%</th>
          <th><?= (int)($fin['extra_pct'] ?? 10) ?>%</th></tr>
        <tr class="c"><th></th><th><?= $L['encabezado_cat'] ?? 'CARACTER&Iacute;STICAS' ?></th>
          <th>METROS (m2)</th><?php if ($conParq): ?><th>PARQUEOS</th><?php endif; ?>
          <?php if ($conPatio): ?><th>M2 PATIO</th><?php endif; ?><th>PRECIO</th>
          <th>SEPARA CON</th><th>A LA FIRMA</th>
          <th>CUOTAS<br>MENSUALES</th>
          <th>CUOTAS EXTRAORDINARIAS<br>(1 VEZ AL A&Ntilde;O)</th></tr>
      </thead>
      <tbody>
      <?php $iNiv = 0; foreach ($bloques as $niv => $filas): $iNiv++;
            $etNiv = strtoupper((string)($L['etiqueta_nivel'][$niv]
                        ?? ($cfg['niveles'][$niv]['etiqueta'] ?? $niv)));
            $clsNiv = 'niv' . (($iNiv - 1) % 5 + 2);
            $primera = true; ?>
        <?php foreach ($filas as $r): $pl = lst_plan($r['precio'], $fin); ?>
          <tr>
            <?php if ($primera): $primera = false; ?>
              <td class="niv <?= $clsNiv ?>" rowspan="<?= count($filas) ?>"><span><?= lh($etNiv) ?></span></td>
            <?php endif; ?>
            <?php if (!empty($r['combo'])): ?>
              <td class="cat combo"><?= lh((string)($combo['rotulo_por_nivel'][$niv]
                    ?? ($combo['rotulo'] ?? 'DESDE'))) ?><?php
                    /* Cual es el par, en letra chica: el vendedor necesita saber que
                       unidades son, y ademas hace verificable el numero. */
                    if (!empty($r['par']) && !empty($L['mostrar_par']))
                        echo '<span class="ult" style="color:#cfe0b4">' . lh($r['par']) . '</span>'; ?></td>
            <?php else:
              $n = count($r['cods']);
              // Una sola: la lista la nombra por su codigo. Dos: la tipologia con el
              // aviso. Es como lo escribe la direccion y es presion de escasez real.
              // Tres capas, de la mas especifica a la mas general. La direccion rotula
              // el 4to piso aparte ("4TO PISO MED") y a G-H como "3 DORM": son
              // etiquetas suyas y viven en `etiquetas_lista` de su propio archivo.
              $nomCat = $L['nombre_por_grupo'][$r['g']][$r['cat']]
                     ?? ($L['nombre_por_nivel'][$niv][$r['cat']]
                     ?? ($L['nombre'][$r['cat']] ?? $r['cat']));
              $texto = $n === 1
                  ? (($L['codigo_formato'] ?? '') === 'guion'
                        ? preg_replace('/^([A-Z])(\d+)-/', '$1-$2-', $r['cods'][0])
                        : $r['cods'][0])
                  : (string)$nomCat;
              // El texto exacto de la direccion. En Apartments escribe "ÚLTIMA UNIDAD"
              // y en Plaza "ÚLTIMA DISPONIBLE": no es lo mismo y cada lista lo declara.
              $ult = $n === 1 ? (string)($L['badge_uno'] ?? 'ÚLTIMA DISPONIBLE')
                   : ($n === 2 ? (string)($L['badge_dos'] ?? '2 ÚLTIMAS DISPONIBLES') : '');
            ?>
              <td class="cat <?= lh((string)($L['lado'][$r['cat']] ?? '')) ?>"><?= lh($texto) ?><?php
                  if ($ult !== '') echo '<span class="ult">' . lh($ult) . '</span>'; ?></td>
            <?php endif; ?>
            <td class="c"><?= lh((string)$r['m2']) ?></td>
            <?php if ($conParq): ?>
              <?php /* G y H llevan 2 parqueos por ser de 3 dormitorios, y toda unidad
                       unida lleva 2. Lo declara la direccion en su bloque `parqueos`. */ ?>
              <td class="c"><?= (int)(!empty($r['combo'])
                    ? ($L['parqueos_combo'] ?? $combo['parqueos'] ?? 2)
                    : ($L['parqueos_por_grupo'][$r['g']]
                       ?? ($L['parqueos'][$r['cat']] ?? ($L['parqueos_default'] ?? 1)))) ?></td>
            <?php endif; ?>
            <?php if ($conPatio): ?>
              <?php /* El patio existe solo en el nivel que lo declara la matriz
                       (patio_pb). En los pisos altos la celda va vacia, no en cero. */ ?>
              <td class="c"><?php
                    // Una unidad unida lleva los dos patios: 16,25 + 16,25 = 32,5m2.
                    $pt = $niv === (string)($L['nivel_patio'] ?? 'PB')
                        ? ($cfg['metraje'][$r['g']]['patio_pb'] ?? null) : null;
                    if ($pt !== null && !empty($r['combo'])) $pt = (float)$pt * 2;
                    echo $pt !== null ? lh(rtrim(rtrim(number_format((float)$pt, 2, ',', ''), '0'), ',')) . 'm2' : '-'; ?></td>
            <?php endif; ?>
            <td class="p"><?= lh(lp($r['precio'])) ?></td>
            <td class="n"><?= lh(ln($pl['separa'])) ?></td>
            <td class="n"><?= lh(ln($pl['firma'])) ?></td>
            <td class="n"><?= lh(ln($pl['mensual'])) ?></td>
            <td class="n"><?= lh(ln($pl['extra'])) ?></td>
          </tr>
        <?php endforeach; ?>
      <?php endforeach; ?>
      <?php if (!$bloques): ?>
        <tr><td colspan="<?= $nCols + 4 ?>" style="text-align:center;padding:26px">
          No hay unidades disponibles de esta familia.</td></tr>
      <?php endif; ?>
      </tbody>
    </table>
    <?php if (!empty($L['lat'])): ?>
      <div class="lat"><?= $L['lat'] ?></div>
    <?php endif; ?>
  </div>
  <?php if (!empty($L['pie'])): ?>
    <div class="pie"><?= lh((string)($L['pie']['rotulo'] ?? '')) ?><?php
        if (!empty($L['pie']['medidas'])) echo '<span>' . lh((string)$L['pie']['medidas']) . '</span>'; ?></div>
  <?php endif; ?>
  <div class="vig">ESTA COTIZACI&Oacute;N TIENE UNA VIGENCIA DE
    <?= (int)($fin['vigencia_horas'] ?? 48) ?> HRS NATURALES</div>
  <div class="meta">Generada el <?= lh($hoy->format('d/m/Y H:i')) ?> desde el inventario en vivo ·
    <?= array_sum(array_map('count', $bloques)) ?> tipologías con disponibilidad</div>
</section>
<?php endforeach; ?>
<?php endif; ?>

</body></html>
