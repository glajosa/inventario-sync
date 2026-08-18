<?php
/**
 * motivo.php — POR QUÉ Bitrix rechazó el registro de la llamada. Solo lectura.
 * ---------------------------------------------------------------------------
 * Cuando el panel recibe "Access denied" al crear la actividad, el mensaje de
 * Bitrix no dice qué pasó. Esto lo averigua y devuelve algo que el vendedor
 * pueda leer y actuar.
 *
 * LA CAUSA CONOCIDA: la actividad se liga al CONTACTO (campo COMMUNICATIONS,
 * obligatorio para que el dashboard la cuente como llamada), y el rol de CRM es
 * "solo los propios". Si el deal es de un asesor y el contacto de otro, Bitrix
 * rechaza — aunque el deal sí sea suyo.
 *
 * ⚠ NO ARREGLA NADA. Antes este archivo alineaba el responsable del contacto
 * solo; se quitó por decisión del usuario (18-ago): reasignar dueños de clientes
 * en silencio es tocar la cartera de otro asesor sin que nadie lo decida. Ahora
 * se informa y la persona decide.
 *
 * Candado igual, aunque sea de lectura: solo el responsable del deal puede
 * preguntar por su deal. No se filtra a quién pertenece la cartera ajena.
 *
 * Respuesta: {"ok":true,"motivo":"contacto_ajeno","dueno":"Ricardo Corral"}
 *            {"ok":true,"motivo":"otro"}
 * ---------------------------------------------------------------------------
 */
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
require_once __DIR__ . '/appauth.php';

$dealId = (int)($_POST['deal'] ?? $_GET['deal'] ?? 0);
$authId = (string)($_POST['auth'] ?? $_GET['auth'] ?? '');
$sal = fn(array $x) => print(json_encode($x, JSON_UNESCAPED_UNICODE));

if ($dealId <= 0 || $authId === '') {
    http_response_code(400); $sal(['ok' => false, 'error' => 'falta deal o auth']); exit;
}

// ── quién pregunta: se lo confirma Bitrix con SU token ──────────────────────
$ch = curl_init('https://galjosa.bitrix24.com/rest/user.current.json?auth=' . rawurlencode($authId));
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15]);
$resp = json_decode((string)curl_exec($ch), true);
curl_close($ch);
$quien = (int)($resp['result']['ID'] ?? 0);
if ($quien <= 0) { http_response_code(403); $sal(['ok' => false, 'error' => 'sesion no valida']); exit; }

$d = app_bx('crm.deal.get', ['id' => $dealId])['result'] ?? [];
if (!$d) { http_response_code(404); $sal(['ok' => false, 'error' => 'deal no existe']); exit; }

// Solo por SU deal: así no sirve para espiar de quién es la cartera ajena.
if ($quien !== (int)($d['ASSIGNED_BY_ID'] ?? 0)) {
    http_response_code(403); $sal(['ok' => false, 'error' => 'no eres el responsable']); exit;
}

$contactId = (int)($d['CONTACT_ID'] ?? 0);
if ($contactId <= 0) { $sal(['ok' => true, 'motivo' => 'sin_contacto']); exit; }

$c = app_bx('crm.contact.get', ['id' => $contactId])['result'] ?? [];
$dueñoCont = (int)($c['ASSIGNED_BY_ID'] ?? 0);

if ($dueñoCont === $quien) { $sal(['ok' => true, 'motivo' => 'otro']); exit; }

$u = app_bx('user.get', ['ID' => $dueñoCont])['result'][0] ?? [];
$nombre = trim(($u['NAME'] ?? '') . ' ' . ($u['LAST_NAME'] ?? '')) ?: ('usuario ' . $dueñoCont);
$sal(['ok' => true, 'motivo' => 'contacto_ajeno', 'dueno' => $nombre, 'contacto' => $contactId]);
