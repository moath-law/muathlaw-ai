<?php
// تشغيل الفهرسة من cron: php /path/reindex.php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
mb_internal_encoding('UTF-8');
require __DIR__ . '/lib/search.php';
$cfg = require __DIR__ . '/config.php';
echo json_encode(refresh_index($cfg), JSON_UNESCAPED_UNICODE), "\n";
