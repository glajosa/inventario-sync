<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../lib/llamada-protocolo.php';

$history = [
    fake_activity(10, 'Llamada saliente Ana', '2026-08-18T09:00:00-05:00'),
    fake_activity(11, '1234', '2026-08-19T10:00:00-05:00'),
    fake_activity(12, 'Llamada saliente Ana', '2026-08-20T09:00:00-05:00'),
];
test_same(['estado' => 'ESCALERA-1', 'sinContestar' => 1], llamada_calcular_protocolo($history, null), 'protocol order');
test_same(['estado' => 'CONTACTADO', 'sinContestar' => 0], llamada_calcular_protocolo($history, 12), 'exclude current telephony activity');

$now = new DateTimeImmutable('2026-08-20T16:30:00-05:00', new DateTimeZone('America/Guayaquil'));
$next = llamada_proxima_no_contesto(['estado' => 'CONTACTADO', 'sinContestar' => 0], $now);
test_same('2026-08-21', $next['at']->format('Y-m-d'), 'first retry day');
test_same('19:00', $next['at']->format('H:i'), 'rotating time slot');

$friday = new DateTimeImmutable('2026-08-21T09:00:00-05:00', new DateTimeZone('America/Guayaquil'));
$afterWeekend = llamada_proxima_no_contesto(['estado' => 'CONTACTADO', 'sinContestar' => 0], $friday);
test_same('2026-08-24', $afterWeekend['at']->format('Y-m-d'), 'weekend moves to next business day');
test_same('12:30', $afterWeekend['at']->format('H:i'), 'morning rotates to lunch slot');

$scheduled = new DateTimeImmutable('2026-08-21T19:00:00-05:00', new DateTimeZone('America/Guayaquil'));
$fields = llamada_campos_actividad([
    'nextAt' => $scheduled,
    'dealId' => 77,
    'subject' => 'Llamada saliente Ana',
    'completed' => 'N',
    'responsibleId' => 8,
    'contactId' => 9,
    'selectedPhone' => '+593 99 123 4567',
]);
test_same('VOXIMPLANT_CALL', $fields['PROVIDER_ID'], 'activity uses configured provider');
test_same('2026-08-21T20:00:00-05:00', $fields['END_TIME'], 'activity ends one hour later');
test_same([['VALUE' => '+593 99 123 4567', 'ENTITY_ID' => 9, 'ENTITY_TYPE_ID' => 3, 'TYPE' => 'PHONE']], $fields['COMMUNICATIONS'], 'activity links selected phone');
