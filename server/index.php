<?php
// خادم MCP لشركة معاذ للمحاماة — muathlaw.com (Streamable HTTP / JSON)
if (!function_exists("array_is_list")) { function array_is_list(array $a): bool { return $a === [] || array_keys($a) === range(0, count($a) - 1); } }
mb_internal_encoding('UTF-8');
require __DIR__ . '/lib/labor.php';
require __DIR__ . '/lib/search.php';
$cfg = require __DIR__ . '/config.php';

const SERVER_INFO = ['name' => 'muathlaw', 'title' => 'معاذ للمحاماة — muathlaw.com', 'version' => '1.0.0'];
const SUPPORTED_VERSIONS = ['2025-11-25', '2025-06-18', '2025-03-26', '2024-11-05'];
const INSTRUCTIONS = 'خادم شركة معاذ للمحاماة (المملكة العربية السعودية). استخدم search_muathlaw للبحث في مقالات الموقع وأحكامه القضائية وأنظمته، ثم get_muathlaw_document لقراءة النص، واذكر الرابط مصدرًا. استخدم calculate_labor_dues لحساب المستحقات العمالية وفق نظام العمل السعودي. النتائج للتوعية وليست استشارة قانونية؛ وجّه المستخدم للتواصل مع المكتب في الحالات الفعلية.';

function cors() {
  header('Access-Control-Allow-Origin: *');
  header('Access-Control-Allow-Methods: POST, GET, OPTIONS, DELETE');
  header('Access-Control-Allow-Headers: content-type, mcp-session-id, mcp-protocol-version, authorization');
  header('Access-Control-Expose-Headers: mcp-session-id');
}
function send_json($body, int $status = 200) {
  http_response_code($status); cors();
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}
function rpc_result($id, $result) { return ['jsonrpc' => '2.0', 'id' => $id, 'result' => $result]; }
function rpc_error($id, int $code, string $msg) { return ['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => $code, 'message' => $msg]]; }
function tool_text(string $text, $structured) { return ['content' => [['type' => 'text', 'text' => $text]], 'structuredContent' => $structured, 'isError' => false]; }
function tool_error(string $msg) { return ['content' => [['type' => 'text', 'text' => "خطأ: $msg"]], 'isError' => true]; }

function call_tool($cfg, $name, array $args) {
  try {
    if ($name === 'search_muathlaw') {
      $q = trim((string)($args['query'] ?? ''));
      if ($q === '') return tool_error('عبارة البحث مطلوبة');
      $r = search_site($cfg, $q, $args['section'] ?? 'all', $args['limit'] ?? 8);
      if (!$r['results']) return tool_text("لا توجد نتائج في muathlaw.com لـ «{$q}». جرّب كلمات أخرى أو قسمًا آخر.", $r);
      $lines = [];
      foreach ($r['results'] as $i => $x) {
        $lines[] = ($i + 1) . ". [{$x['section_label']}] {$x['title']}\n{$x['url']}" . ($x['date'] ? "\nالتاريخ: {$x['date']}" : '') . ($x['summary'] ? "\n{$x['summary']}" : '');
      }
      return tool_text(implode("\n\n", $lines), $r);
    }
    if ($name === 'get_muathlaw_document') {
      $d = get_document($cfg, $args['url'] ?? '');
      return tool_text("{$d['title']}\n[{$d['section_label']}] {$d['url']}\n\n{$d['text']}", $d);
    }
    if ($name === 'calculate_labor_dues') {
      $r = calculate_labor_dues($args);
      return tool_text(format_labor_result($r), $r);
    }
    return null;
  } catch (Throwable $e) {
    return tool_error($e->getMessage());
  }
}

function handle_rpc($cfg, $msg) {
  if (!is_array($msg) || ($msg['jsonrpc'] ?? '') !== '2.0' || !is_string($msg['method'] ?? null)) return rpc_error($msg['id'] ?? null, -32600, 'Invalid Request');
  $id = $msg['id'] ?? null; $isNote = !array_key_exists('id', $msg) || $id === null;
  $method = $msg['method']; $params = is_array($msg['params'] ?? null) ? $msg['params'] : [];
  switch ($method) {
    case 'initialize':
      $v = in_array($params['protocolVersion'] ?? '', SUPPORTED_VERSIONS, true) ? $params['protocolVersion'] : SUPPORTED_VERSIONS[0];
      return rpc_result($id, ['protocolVersion' => $v, 'capabilities' => ['tools' => ['listChanged' => false]], 'serverInfo' => SERVER_INFO, 'instructions' => INSTRUCTIONS]);
    case 'ping':
      return $isNote ? null : rpc_result($id, new stdClass());
    case 'tools/list':
      return rpc_result($id, ['tools' => json_decode(file_get_contents(__DIR__ . '/lib/tools.json'))]);
    case 'tools/call':
      $args = is_array($params['arguments'] ?? null) ? $params['arguments'] : [];
      $out = call_tool($cfg, $params['name'] ?? '', $args);
      return $out === null ? rpc_error($id, -32602, 'Unknown tool: ' . ($params['name'] ?? '')) : rpc_result($id, $out);
    default:
      if (strpos($method, 'notifications/') === 0) return null;
      return $isNote ? null : rpc_error($id, -32601, "Method not found: $method");
  }
}

$path = rtrim(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/', '/') ?: '/';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method === 'OPTIONS') { http_response_code(204); cors(); exit; }

if ($path === '/' && $method === 'GET') {
  header('Content-Type: text/html; charset=utf-8');
  $host = 'https://' . ($_SERVER['HTTP_HOST'] ?? 'mcp.muathlaw.com');
  echo '<!doctype html><html lang="ar" dir="rtl"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . SERVER_INFO['title'] . '</title><body style="font-family:sans-serif;max-width:680px;margin:40px auto;padding:0 16px;line-height:1.9"><h1>' . SERVER_INFO['title'] . '</h1><p>خادم MCP رسمي لشركة معاذ للمحاماة. أضف الرابط التالي موصلًا في Claude أو ChatGPT:</p><pre style="background:#f3f3f3;padding:10px;direction:ltr">' . $host . '/mcp</pre><p>الأدوات: البحث في المقالات والأحكام والأنظمة — قراءة المقال أو الحكم — حاسبة المستحقات العمالية</p><p><a href="/privacy">سياسة الخصوصية</a></p></body></html>';
  exit;
}
if ($path === '/privacy') {
  header('Content-Type: text/html; charset=utf-8');
  echo '<!doctype html><html lang="ar" dir="rtl"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>سياسة الخصوصية — موصل معاذ للمحاماة</title><body style="font-family:sans-serif;max-width:680px;margin:40px auto;padding:0 16px;line-height:1.9"><h1>سياسة الخصوصية — موصل شركة معاذ للمحاماة</h1><ol><li>الموصل لا يطلب تسجيل دخول ولا يجمع بيانات شخصية.</li><li>ما يُرسل إليه من نصوص البحث وأرقام الحاسبة يُعالج لحظيًا لإرجاع النتيجة، ولا يُخزّن ولا يُشارك مع أي طرف.</li><li>الموصل يقرأ المحتوى المنشور علنًا على muathlaw.com فقط.</li><li>النتائج للتوعية وليست استشارة قانونية، ولا تنشئ علاقة محامٍ بموكل.</li><li>للتواصل: muath@muathlaw.com — واتساب 0125118667</li></ol><p>آخر تحديث: نوفمبر 2026</p></body></html>';
  exit;
}
if ($path === '/health') { $i = load_index($cfg); send_json(['ok' => true] + SERVER_INFO + ['indexed' => count($i['docs']), 'index_updated' => $i['updated']]); }
if ($path === '/reindex') {
  $tok = $_GET['token'] ?? str_replace('Bearer ', '', $_SERVER['HTTP_AUTHORIZATION'] ?? '');
  if (!hash_equals($cfg['admin_token'], (string)$tok)) send_json(['error' => 'unauthorized'], 401);
  set_time_limit(300);
  send_json(refresh_index($cfg));
}
if ($path !== '/mcp') send_json(['error' => 'not found'], 404);
if ($method === 'GET') { http_response_code(405); header('Allow: POST'); cors(); exit; }
if ($method === 'DELETE') { http_response_code(204); cors(); exit; }
if ($method !== 'POST') { http_response_code(405); cors(); exit; }

$body = json_decode(file_get_contents('php://input') ?: '', true);
if ($body === null) send_json(rpc_error(null, -32700, 'Parse error'), 400);
if (array_is_list($body) && $body) {
  $out = array_values(array_filter(array_map(fn($m) => handle_rpc($cfg, $m), $body)));
  if ($out) send_json($out);
  http_response_code(202); cors(); exit;
}
$out = handle_rpc($cfg, $body);
if ($out !== null) send_json($out);
http_response_code(202); cors(); exit;
