<?php
// فهرسة muathlaw.com والبحث فيه

const SECTION_LABELS = ['articles' => 'مقال', 'rulings' => 'حكم قضائي', 'regulations' => 'نظام/لائحة', 'other' => 'صفحة'];
const STOP_WORDS = ['في','من','على','الى','عن','ما','هل','او','و','ثم','مع','التي','الذي','ذلك','هذا','هذه','كيف','متى','لماذا','ان','كان','اذا','بعد','قبل'];

function norm_ar($s) {
  $s = mb_strtolower((string)$s, 'UTF-8');
  $s = preg_replace('/[\x{064B}-\x{065F}\x{0670}\x{0640}]/u', '', $s);
  $s = preg_replace('/[أإآٱ]/u', 'ا', $s);
  $s = str_replace(['ى', 'ة', 'ؤ', 'ئ'], ['ي', 'ه', 'و', 'ي'], $s);
  $s = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $s);
  return trim(preg_replace('/\s+/u', ' ', $s));
}

function tokenize_ar($s) {
  $out = [];
  foreach (explode(' ', norm_ar($s)) as $t) {
    if (mb_strlen($t) < 2 || in_array($t, STOP_WORDS, true)) continue;
    if (mb_strlen($t) > 4 && mb_substr($t, 0, 2) === 'ال') $t = mb_substr($t, 2);
    $out[] = $t;
  }
  return $out;
}

function classify_url($url, $patterns) {
  $path = mb_strtolower(rawurldecode(parse_url($url, PHP_URL_PATH) ?? ''), 'UTF-8');
  foreach ($patterns as $sec => $list) foreach ($list as $p) if (strpos($path, mb_strtolower($p)) !== false) return $sec;
  return 'other';
}

function dec($s) { return html_entity_decode((string)$s, ENT_QUOTES | ENT_HTML5, 'UTF-8'); }

function meta_tag($html, $name) {
  if (preg_match('/<meta[^>]+(?:name|property)=["\']' . preg_quote($name, '/') . '["\'][^>]*>/i', $html, $m)
      && preg_match('/content=["\']([^"\']*)["\']/i', $m[0], $c)) return dec($c[1]);
  return '';
}

function extract_text($html) {
  $body = $html;
  if (preg_match('/<article[\s\S]*?<\/article>/i', $html, $m)) $body = $m[0];
  elseif (preg_match('/<main[\s\S]*?<\/main>/i', $html, $m)) $body = $m[0];
  $body = preg_replace('/<(script|style|noscript|svg|nav|footer|header|form|aside)[\s\S]*?<\/\1>/i', ' ', $body);
  $body = preg_replace('/<br\s*\/?>|<\/(p|div|h[1-6]|li|tr)>/i', "\n", $body);
  $t = dec(preg_replace('/<[^>]+>/', ' ', $body));
  $t = preg_replace('/[ \t]+/u', ' ', $t);
  return trim(preg_replace('/\n\s*\n+/u', "\n", $t));
}

function parse_page($url, $html, $patterns) {
  $title = meta_tag($html, 'og:title');
  if ($title === '' && preg_match('/<title[^>]*>([\s\S]*?)<\/title>/i', $html, $m)) $title = trim(dec($m[1]));
  $title = trim(preg_replace('/\s*[|–-]\s*شركة معاذ للمحاماة.*$/u', '', $title));
  $desc = meta_tag($html, 'description') ?: meta_tag($html, 'og:description');
  $date = meta_tag($html, 'article:published_time');
  if ($date === '' && preg_match('/"datePublished"\s*:\s*"([^"]+)"/', $html, $m)) $date = $m[1];
  $text = extract_text($html);
  return [
    'url' => $url, 'title' => mb_substr($title, 0, 300), 'description' => mb_substr($desc, 0, 500),
    'date' => $date, 'section' => classify_url($url, $patterns), 'excerpt' => mb_substr($text, 0, 1500),
  ];
}

function http_get($url, $timeout = 20) {
  $ch = curl_init($url);
  curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => $timeout, CURLOPT_USERAGENT => 'MuathLaw-MCP/1.0']);
  $body = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
  return [$code, $body === false ? '' : $body];
}

function http_get_many(array $urls, $concurrency = 3) {
  $results = []; $queue = $urls; $mh = curl_multi_init(); $active = [];
  $add = function ($u) use ($mh, &$active) {
    $ch = curl_init($u);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => 25, CURLOPT_USERAGENT => 'MuathLaw-MCP/1.0']);
    curl_multi_add_handle($mh, $ch); $active[(int)$ch] = [$ch, $u];
  };
  while ($queue && count($active) < $concurrency) $add(array_shift($queue));
  do {
    curl_multi_exec($mh, $running); curl_multi_select($mh, 1.0);
    while ($info = curl_multi_info_read($mh)) {
      $ch = $info['handle']; [$h, $u] = $active[(int)$ch];
      $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
      $results[$u] = [$code, curl_multi_getcontent($ch)];
      curl_multi_remove_handle($mh, $ch); curl_close($ch); unset($active[(int)$ch]);
      if ($queue) $add(array_shift($queue));
    }
  } while ($active);
  curl_multi_close($mh);
  return $results;
}

function sitemap_urls($cfg) {
  $seen = []; $urls = []; $queue = [$cfg['site_origin'] . '/sitemap.xml']; $guard = 0;
  while ($queue && $guard++ < 20) {
    $sm = array_shift($queue); if (isset($seen[$sm])) continue; $seen[$sm] = 1;
    [$code, $xml] = http_get($sm); if ($code !== 200) continue;
    preg_match_all('/<loc>\s*([^<\s]+)\s*<\/loc>/i', $xml, $m);
    $locs = array_map('dec', $m[1]);
    if (stripos($xml, '<sitemapindex') !== false) array_push($queue, ...$locs); else array_push($urls, ...$locs);
  }
  return array_values(array_unique($urls));
}

function load_index($cfg) {
  $f = $cfg['index_file'];
  if (!is_file($f)) return ['docs' => [], 'updated' => null];
  $j = json_decode(file_get_contents($f), true);
  return is_array($j) ? $j : ['docs' => [], 'updated' => null];
}

function refresh_index($cfg) {
  $patterns = $cfg['section_patterns'];
  $index = load_index($cfg);
  $urls = array_values(array_filter(sitemap_urls($cfg), fn($u) => classify_url($u, $patterns) !== 'other'));
  if (!$urls) return ['error' => 'تعذّر قراءة sitemap.xml'];
  $set = array_flip($urls);
  foreach (array_keys($index['docs']) as $u) if (!isset($set[$u])) unset($index['docs'][$u]);
  // الجديد أولًا، ثم ما مضى على فهرسته أكثر من 24 ساعة فقط — لتخفيف الحمل على الاستضافة
  $stale = time() - 86400;
  $urls = array_values(array_filter($urls, fn($u) => ($index['docs'][$u]['indexed_at'] ?? 0) < $stale));
  usort($urls, fn($a, $b) => ($index['docs'][$a]['indexed_at'] ?? 0) <=> ($index['docs'][$b]['indexed_at'] ?? 0));
  $batch = array_slice($urls, 0, $cfg['batch_size']);
  if (!$batch) { return ['total_urls' => count($set), 'indexed' => count($index['docs']), 'processed' => 0]; }
  foreach (http_get_many($batch) as $u => [$code, $html]) {
    if ($code !== 200 || !$html) continue;
    $doc = parse_page($u, $html, $patterns); $doc['indexed_at'] = time();
    $index['docs'][$u] = $doc;
  }
  $index['updated'] = gmdate('c');
  @mkdir(dirname($cfg['index_file']), 0755, true);
  $tmp = $cfg['index_file'] . '.tmp';
  file_put_contents($tmp, json_encode($index, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
  rename($tmp, $cfg['index_file']);
  return ['total_urls' => count($urls), 'indexed' => count($index['docs']), 'processed' => count($batch)];
}

function score_doc($d, $qt, $qn) {
  $t = norm_ar($d['title']); $de = norm_ar($d['description']); $x = norm_ar($d['excerpt'] ?? '');
  $slug = norm_ar(str_replace(['-', '_', '/'], ' ', rawurldecode($d['url'])));
  $s = 0;
  if ($qn !== '' && mb_strpos($t, $qn) !== false) $s += 25;
  if ($qn !== '' && mb_strpos($x, $qn) !== false) $s += 8;
  $hit = 0;
  foreach ($qt as $tok) {
    $h = false;
    if (mb_strpos($t, $tok) !== false) { $s += 6; $h = true; }
    if (mb_strpos($slug, $tok) !== false) { $s += 4; $h = true; }
    if (mb_strpos($de, $tok) !== false) { $s += 3; $h = true; }
    if (mb_strpos($x, $tok) !== false) { $s += 1; $h = true; }
    if ($h) $hit++;
  }
  return $hit === 0 ? 0 : $s * ($hit / count($qt));
}

function search_site($cfg, $query, $section = 'all', $limit = 8) {
  $patterns = $cfg['section_patterns'];
  $index = load_index($cfg); $docs = array_values($index['docs']); $source = 'index';
  if (!$docs) {
    $source = 'sitemap';
    foreach (sitemap_urls($cfg) as $u) {
      $parts = array_values(array_filter(explode('/', parse_url($u, PHP_URL_PATH) ?? '')));
      $docs[] = ['url' => $u, 'title' => str_replace(['-', '_'], ' ', rawurldecode(end($parts) ?: '')), 'description' => '', 'excerpt' => '', 'section' => classify_url($u, $patterns), 'date' => ''];
    }
  }
  $docs = array_filter($docs, fn($d) => $section === 'all' ? $d['section'] !== 'other' : $d['section'] === $section);
  $qt = tokenize_ar($query); $qn = norm_ar($query);
  if (!$qt) return ['results' => [], 'source' => $source];
  $scored = [];
  foreach ($docs as $d) { $s = score_doc($d, $qt, $qn); if ($s > 0) $scored[] = [$s, $d]; }
  usort($scored, fn($a, $b) => $b[0] <=> $a[0]);
  $limit = max(1, min(20, (int)$limit));
  $results = [];
  foreach (array_slice($scored, 0, $limit) as [, $d]) {
    $results[] = ['title' => $d['title'], 'url' => $d['url'], 'section' => $d['section'], 'section_label' => SECTION_LABELS[$d['section']] ?? 'صفحة',
      'date' => $d['date'] ?: null, 'summary' => mb_substr($d['description'] ?: ($d['excerpt'] ?? ''), 0, 400)];
  }
  return ['results' => $results, 'source' => $source, 'index_updated' => $index['updated']];
}

function get_document($cfg, $url, $max = 12000) {
  $origin = parse_url($cfg['site_origin'], PHP_URL_HOST);
  if (!preg_match('#^https?://#i', (string)$url)) $url = rtrim($cfg['site_origin'], '/') . '/' . ltrim((string)$url, '/');
  $host = preg_replace('/^www\./', '', (string)parse_url($url, PHP_URL_HOST));
  if ($host !== preg_replace('/^www\./', '', $origin)) throw new Exception('هذه الأداة تقرأ صفحات muathlaw.com فقط');
  [$code, $html] = http_get($url);
  if ($code !== 200) throw new Exception("تعذّر فتح الصفحة (HTTP $code)");
  $doc = parse_page($url, $html, $cfg['section_patterns']);
  $text = extract_text($html);
  if (mb_strlen($text) > $max) $text = mb_substr($text, 0, $max) . "\n…[اقتُطع النص — افتح الرابط للنص الكامل]";
  return ['title' => $doc['title'], 'url' => $url, 'section' => $doc['section'], 'section_label' => SECTION_LABELS[$doc['section']] ?? 'صفحة', 'date' => $doc['date'] ?: null, 'text' => $text];
}
