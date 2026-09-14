<?php
declare(strict_types=1);

require_once dirname(__DIR__, 4) . '/lib/bot-commercial-summary.php';

if (realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    bot_commercial_summary_emit_http();
}
