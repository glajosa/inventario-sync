<?php
/**
 * HUELLA DEL CÓDIGO QUE ESTÁ CORRIENDO.
 *
 * 🔴 EL PROBLEMA QUE RESUELVE. Disparar el despliegue en EasyPanel
 * (`services.app.deployService`) devuelve **HTTP 000**: la conexión se corta
 * mientras compila. O sea la respuesta NO dice si funcionó. El 31-ago-2026 el
 * despliegue SÍ corrió y la llamada igual dio 000 — si me lo hubiera creído,
 * habría vuelto a disparar, y dispararlo dos veces deja el servicio en estado
 * inconsistente (pasó con noral-historias: "Invariant failed" y hubo que
 * recrearlo).
 *
 * El arreglo NO es hacer que la llamada conteste bien: es dejar de preguntarle a
 * la llamada. La pregunta correcta no es "¿el disparo tuvo éxito?" sino
 * **"¿el código nuevo ya está sirviendo?"** — y eso se le pregunta a la app.
 *
 * Esta huella es md5 de cada archivo de la app. Se calcula igual de los dos lados:
 *   local       php huella.php
 *   producción  GET /?huella=1&token=<OUTBOUND_TOKEN>
 * Si los dos `total` coinciden, el servidor está sirviendo exactamente tu árbol.
 *
 * También sirve ANTES de desplegar, que es la regla de CLAUDE.md: si la huella de
 * producción no coincide con la de tu HEAD, otra sesión desplegó algo que vos no
 * tenés — bajá lo suyo antes de subir lo tuyo.
 *
 * Devuelve la lista archivo por archivo a propósito: una huella que solo dice
 * "no coincide" no se puede diagnosticar. Con el detalle se ve CUÁL archivo baila.
 */
declare(strict_types=1);

/** Lo que el Dockerfile NO deja dentro de la raiz web, mas lo que nunca es codigo. */
const HUELLA_FUERA = [
    'Dockerfile', 'README.md', 'apache-tests-deny.conf', 'app_auth.json',
    // entrypoint.sh se copia a /usr/local/bin y se BORRA de la raiz web
    // (Dockerfile: `rm -f /var/www/html/entrypoint.sh`). No se ignora: se busca
    // en su sitio real, abajo. Ignorarlo dejaria un archivo que SI cambia el
    // comportamiento -- es quien corre los crons -- fuera de la huella.
    'entrypoint.sh',
];
/** Donde vive el entrypoint DENTRO del contenedor. */
const HUELLA_ENTRYPOINT = '/usr/local/bin/entrypoint.sh';
const HUELLA_CARPETAS_FUERA = [
    '.git', '.github', '.worktrees', '.superpowers', 'docs', 'node_modules',
    'tmp', 'scratch', '.specify',
    'bin',   // herramientas de despliegue: viven en el repo pero NO entran a la imagen
];
/** Solo lo que define comportamiento. Un .md o un .png no cambian lo que hace la app. */
const HUELLA_EXTENSIONES = ['php', 'json', 'html', 'js', 'css', 'sh'];

function huella_archivos(string $raiz): array {
    $raiz = rtrim($raiz, '/');
    $out  = [];
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($raiz, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($it as $ruta => $info) {
        $rel = substr((string)$ruta, strlen($raiz) + 1);
        $primera = explode('/', $rel)[0];
        if (in_array($primera, HUELLA_CARPETAS_FUERA, true)) continue;
        if (!$info->isFile()) continue;
        if (in_array($rel, HUELLA_FUERA, true)) continue;
        $ext = strtolower(pathinfo($rel, PATHINFO_EXTENSION));
        if (!in_array($ext, HUELLA_EXTENSIONES, true)) continue;
        // respaldos y temporales: nunca entran a la imagen
        if (preg_match('/\.(bak|orig|rej|swp|swo)$|\.bak-|\.pre-|~$/', $rel)) continue;
        $out[$rel] = md5_file((string)$ruta) ?: '';
    }
    ksort($out);
    return $out;
}

function huella(string $raiz): array {
    $a = huella_archivos($raiz);

    // El entrypoint vive en la raiz del repo pero en /usr/local/bin del contenedor.
    // Se guarda con la MISMA clave de los dos lados para que las huellas comparen.
    foreach ([$raiz . '/entrypoint.sh', HUELLA_ENTRYPOINT] as $cand) {
        if (is_file($cand)) { $a['entrypoint.sh'] = md5_file($cand) ?: ''; break; }
    }
    ksort($a);

    $lineas = [];
    foreach ($a as $rel => $md5) $lineas[] = "$md5  $rel";
    return [
        'total'    => md5(implode("\n", $lineas)),
        'n'        => count($a),
        'archivos' => $a,
    ];
}

// Uso por linea de comandos: php huella.php [ruta]
if (PHP_SAPI === 'cli' && realpath($argv[0] ?? '') === __FILE__) {
    $raiz = $argv[1] ?? __DIR__;
    echo json_encode(huella($raiz), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";
}
