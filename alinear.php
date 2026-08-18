<?php
/**
 * alinear.php — pone el responsable del CONTACTO igual al del DEAL.
 * ---------------------------------------------------------------------------
 * PARA QUÉ. Al registrar una llamada desde el panel, Bitrix puede devolver
 * "Access denied" cuando el deal es de un asesor y el contacto es de otro: la
 * actividad se liga al contacto (campo COMMUNICATIONS, obligatorio para que el
 * dashboard la cuente) y el rol de CRM es "solo los propios".
 *
 * El asesor NO puede arreglarlo solo: tampoco tiene permiso para reasignar un
 * contacto ajeno. Por eso lo hace el servidor con la auth de la app.
 *
 * ⚠ EL CANDADO. Reasignar dueños de clientes con un endpoint abierto sería un
 * agujero: cualquiera podría repuntar la cartera. Entonces se exige que:
 *
 *   1 · venga el token de sesión del usuario (AUTH_ID del placement)
 *   2 · ese token identifique a un usuario real, preguntándole a Bitrix
 *   3 · ese usuario SEA el responsable del deal
 *
 * O sea: cada uno puede alinear el contacto de SU propio deal, y nada más.
 *
 * Respuesta: {"ok":true,"cambio":true|false,"de":<id>,"a":<id>}
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

// ── 1 · ¿quién es el que pide? Se lo pregunta a Bitrix con SU token ─────────
$dom = 'galjosa.bitrix24.com';
$url = "https://$dom/rest/user.current.json?auth=" . rawurlencode($authId);
$ch = curl_init($url);
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15]);
$resp = json_decode((string)curl_exec($ch), true);
curl_close($ch);
$quien = (int)($resp['result']['ID'] ?? 0);
if ($quien <= 0) {
    http_response_code(403); $sal(['ok' => false, 'error' => 'sesion no valida']); exit;
}

// ── 2 · el deal, con la auth de la app ──────────────────────────────────────
$d = app_bx('crm.deal.get', ['id' => $dealId])['result'] ?? [];
if (!$d) { http_response_code(404); $sal(['ok' => false, 'error' => 'deal no existe']); exit; }

$dueñoDeal = (int)($d['ASSIGNED_BY_ID'] ?? 0);
$contactId = (int)($d['CONTACT_ID'] ?? 0);

// ── 3 · solo el responsable del deal puede pedirlo ──────────────────────────
if ($quien !== $dueñoDeal) {
    http_response_code(403);
    $sal(['ok' => false, 'error' => 'no eres el responsable de este deal']); exit;
}
if ($contactId <= 0) { $sal(['ok' => true, 'cambio' => false, 'error' => 'el deal no tiene contacto']); exit; }

$c = app_bx('crm.contact.get', ['id' => $contactId])['result'] ?? [];
$dueñoCont = (int)($c['ASSIGNED_BY_ID'] ?? 0);

if ($dueñoCont === $dueñoDeal) { $sal(['ok' => true, 'cambio' => false]); exit; }

// ── 4 · alinear ─────────────────────────────────────────────────────────────
app_bx('crm.contact.update', ['id' => $contactId, 'fields' => ['ASSIGNED_BY_ID' => $dueñoDeal]]);
// Queda anotado en el timeline del contacto: nadie descubre después que su
// cliente cambió de dueño sin saber por qué.
app_bx('crm.timeline.comment.add', ['fields' => [
    'ENTITY_ID'   => $contactId,
    'ENTITY_TYPE' => 'contact',
    'COMMENT'     => 'Responsable alineado con el del deal ' . $dealId
                   . ' para poder registrar la llamada (el CRM no deja escribir'
                   . ' actividades en un contacto de otro asesor).',
]]);
$sal(['ok' => true, 'cambio' => true, 'de' => $dueñoCont, 'a' => $dueñoDeal, 'contacto' => $contactId]);
