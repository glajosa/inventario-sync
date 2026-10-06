<?php
/**
 * hook48.php — receptor del destino "inventario-48-etapas" de la libreta central.
 * La libreta solo manda acá los ONCRMDEALUPDATE del embudo 48 en los que cambió STAGE_ID (solo_si + CAMPOS_IMPORTAN),
 * y con esperar_lectura: cuando llega, la copia ya tiene el cambio. Toda la lógica está en hook48lib.php.
 * Contesta 200 ENSEGUIDA y trabaja después (regla de la guía: un destino lento frena el reparto de todos).
 */
declare(strict_types=1);
header('Content-Type: text/plain; charset=utf-8');

require_once __DIR__ . '/campolib.php';     // bx(), logline(), D_ACTIVO, SPA_ENTITY; carga stagelib (apply_unit_stage, COBRANZAS_*)
require_once __DIR__ . '/reubicalib.php';   // reub_libreta()
require_once __DIR__ . '/hook48lib.php';
$BX_FRENO_US = 0;

$EXPECT = (string)getenv('OUTBOUND_TOKEN');
$token  = $_REQUEST['auth']['application_token'] ?? $_REQUEST['application_token'] ?? '';
if ($EXPECT === '' || !hash_equals($EXPECT, (string)$token)) { http_response_code(403); logline('HOOK48 403 token invalido'); echo 'forbidden'; exit; }

$event  = strtoupper((string)($_REQUEST['event'] ?? ''));
$dealId = (int)($_REQUEST['data']['FIELDS']['ID'] ?? 0);
if (getenv('HOOK48_ON') !== '1') { echo 'apagado'; exit; }                       // perilla
if ($event !== 'ONCRMDEALUPDATE' || $dealId <= 0) { echo 'ignorado'; exit; }

// 200 ya; el trabajo sigue con la conexión cerrada
ignore_user_abort(true); @set_time_limit(90);
ob_start(); echo 'ok'; header('Content-Length: ' . ob_get_length()); header('Connection: close');
ob_end_flush(); @flush();
if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();

// un solo proceso por deal (la libreta reintenta; dos avisos juntos del mismo deal no se pisan)
@mkdir(($DATA_DIR ?? '/data') . '/hook48', 0775, true);
$lk = @fopen(($DATA_DIR ?? '/data') . '/hook48/' . $dealId . '.lock', 'c');
if ($lk && !flock($lk, LOCK_EX | LOCK_NB)) { logline("HOOK48 deal=$dealId ya en curso, salgo"); exit; }
try { h48_procesar($dealId); try { reub_pend_barrer(); } catch (Throwable $e2) { logline('HOOK48 barrido pendientes: ' . $e2->getMessage()); } }
catch (Throwable $e) { logline("HOOK48 deal=$dealId excepcion: " . $e->getMessage()); }
finally { if ($lk) { flock($lk, LOCK_UN); fclose($lk); } }
