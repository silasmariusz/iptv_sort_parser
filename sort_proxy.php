<?php
declare(strict_types=1);

function norm_name(string $text): string {
    if ($text === '') return '';
    $text = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text) ?: $text;
    $text = strtolower(str_replace('_', ' ', $text));
    $text = preg_replace('/\b(pl|polska|poland)\b/', ' ', $text);
    $text = preg_replace('/\b(hd|fhd|uhd|4k\+?|1080p|sd|backup|ppv)\b/', ' ', $text);
    $text = preg_replace('/\b(channel)\b/', ' ', $text);
    $text = str_replace(['&', '+'], [' and ', ' plus '], $text);
    $text = preg_replace('/[^a-z0-9]+/', ' ', $text);
    return trim(preg_replace('/\s+/', ' ', $text) ?? '');
}

function aliases(string $key): array {
    $out = [$key];
    $pairs = [
        ['paramount channel', 'paramount network'],
        ['canal 1', 'canal plus1'],
        ['canal sport 1 plus', 'canal plus sport'],
        ['canal sport 2', 'canal plus sport 2'],
        ['canal seriale', 'canal plus seriale'],
        ['canal family', 'canal plus family'],
        ['hbo 2', 'hbo2'],
        ['hbo 3', 'hbo3'],
        ['baby', 'baby tv'],
        ['nick music', 'nickmusic'],
        ['viaplay 1', 'viaplay'],
        ['dtx hd', 'dtx'],
        ['fox comedy', 'fx comedy'],
        ['fox', 'fx'],
        ['nat geo', 'national geo'],
        ['national geographic people', 'nat geo people'],
        ['discovery historia', 'historia'],
        ['discovery science', 'science'],
        ['golf channel', 'golf zone'],
        ['polsat romance', 'romance'],
        ['h2', 'history 2'],
        ['scifi universal', 'scifi'],
        ['sport klub', 'sportklub'],
        ['mtv live', 'mtv'],
        ['warner', 'warnertv'],
    ];
    foreach ($pairs as [$src, $dst]) {
        if (str_contains($key, $src)) $out[] = str_replace($src, $dst, $key);
    }
    if ($key === '4') $out[] = 'tv4';
    if ($key === '6') $out[] = 'tv6';
    return array_values(array_unique(array_filter($out)));
}

function parse_attrs(string $line): array {
    $out = [];
    if (preg_match_all('/([a-zA-Z0-9_-]+)="([^"]*)"/', $line, $m, PREG_SET_ORDER)) {
        foreach ($m as $x) $out[$x[1]] = $x[2];
    }
    return $out;
}

function set_attr(string $line, string $key, string $value): string {
    if (preg_match('/' . preg_quote($key, '/') . '="[^"]*"/', $line)) {
        return preg_replace('/' . preg_quote($key, '/') . '="[^"]*"/', $key . '="' . $value . '"', $line, 1) ?? $line;
    }
    if (str_starts_with($line, '#EXTINF:-1 ')) {
        return str_replace('#EXTINF:-1 ', '#EXTINF:-1 ' . $key . '="' . $value . '" ', $line);
    }
    return $line;
}

function strip_pl_prefix(string $text): string {
    $text = preg_replace('/^\s*pl\s*[:|]\s*/i', '', $text) ?? $text;
    $text = preg_replace('/^\s*pl\s+/i', '', $text) ?? $text;
    return trim($text);
}

function parse_entries(array $lines): array {
    $preamble = [];
    $entries = [];
    $i = 0;
    while ($i < count($lines)) {
        $line = $lines[$i];
        if (str_starts_with($line, '#EXTINF:')) {
            $block = [$line];
            $i++;
            while ($i < count($lines) && !str_starts_with($lines[$i], '#EXTINF:')) {
                $block[] = $lines[$i];
                $i++;
            }
            $entries[] = $block;
        } else {
            if (count($entries) === 0) $preamble[] = $line;
            $i++;
        }
    }
    return [$preamble, $entries];
}

function entry_names(string $extinf): array {
    $attrs = parse_attrs($extinf);
    $display = str_contains($extinf, ',') ? trim(explode(',', $extinf, 2)[1]) : '';
    $names = [$attrs['tvg-name'] ?? '', $display, $attrs['tvg-id'] ?? ''];
    foreach ($names as $n) if ($n !== '') $names[] = strip_pl_prefix($n);
    return array_values(array_filter($names, fn($x) => $x !== ''));
}

function token_best(string $key, array $known, float $min = 0.66): ?string {
    $keyTokens = array_values(array_filter(explode(' ', $key)));
    if (count($keyTokens) < 2) return null;
    $keySet = array_flip($keyTokens);
    $best = null;
    $bestScore = 0.0;
    foreach ($known as $candidate) {
        $tokens = array_values(array_filter(explode(' ', $candidate)));
        if (!$tokens) continue;
        $set = array_flip($tokens);
        $common = count(array_intersect_key($keySet, $set));
        if ($common === 0) continue;
        $score = $common / max(count($keyTokens), count($tokens));
        if ($score > $bestScore) {
            $bestScore = $score;
            $best = $candidate;
        }
    }
    return $bestScore >= $min ? $best : null;
}

function build_alias_lookup(array $orderKeys): array {
    $lookup = [];
    foreach ($orderKeys as $canonical) {
        if (!isset($lookup[$canonical])) $lookup[$canonical] = $canonical;
        foreach (aliases($canonical) as $v) {
            if (!isset($lookup[$v])) $lookup[$v] = $canonical;
        }
    }
    return $lookup;
}

function classify_key(string $extinf, array $knownKeys, array $aliasLookup): ?string {
    foreach (entry_names($extinf) as $name) {
        $base = norm_name($name);
        if ($base === '') continue;
        if (isset($aliasLookup[$base])) return $aliasLookup[$base];
        foreach (aliases($base) as $variant) if (isset($aliasLookup[$variant])) return $aliasLookup[$variant];
        $hit = token_best($base, $knownKeys);
        if ($hit !== null) return $hit;
    }
    return null;
}

function load_order_file(string $path): array {
    if (!is_file($path)) return [];
    $lines = preg_split('/\R/u', file_get_contents($path) ?: '') ?: [];
    $seen = [];
    $out = [];
    foreach ($lines as $line) {
        $k = norm_name($line);
        if ($k === '' || isset($seen[$k])) continue;
        $seen[$k] = true;
        $out[] = $k;
    }
    return $out;
}

function build_icon_map(string $path): array {
    $result = [];
    if (!is_file($path)) return $result;
    $lines = preg_split('/\R/u', file_get_contents($path) ?: '');
    [, $entries] = parse_entries($lines ?: []);
    foreach ($entries as $block) {
        $extinf = $block[0];
        $attrs = parse_attrs($extinf);
        $logo = $attrs['tvg-logo'] ?? '';
        if (!str_starts_with($logo, './')) continue;
        foreach (entry_names($extinf) as $name) {
            $k = norm_name($name);
            if ($k === '') continue;
            foreach (aliases($k) as $v) {
                if (!isset($result[$v])) $result[$v] = $logo;
            }
        }
    }
    return $result;
}

function local_logo_url_for_key(string $key, string $baseDir, string $baseUrl): ?string {
    $base = str_replace(' ', '_', $key);
    $pattern = $baseDir . '/' . $base . '*.png';
    $matches = glob($pattern) ?: [];
    if (!$matches) return null;
    sort($matches, SORT_NATURAL | SORT_FLAG_CASE);
    $file = basename($matches[0]);
    return rtrim($baseUrl, '/') . '/' . $file;
}

function base_url_for_script_dir(): string {
    $forwardedProto = strtolower(trim(explode(',', (string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''))[0] ?? ''));
    $forwardedHost = trim(explode(',', (string)($_SERVER['HTTP_X_FORWARDED_HOST'] ?? ''))[0] ?? '');
    $requestScheme = strtolower((string)($_SERVER['REQUEST_SCHEME'] ?? ''));
    $https = (
        (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['SERVER_PORT'] ?? '') === '443')
        || $forwardedProto === 'https'
        || $requestScheme === 'https'
    );
    $scheme = $https ? 'https' : 'http';
    $host = 'localhost';
    if ($forwardedHost !== '') {
        $host = $forwardedHost;
    } elseif (!empty($_SERVER['HTTP_HOST'])) {
        $host = (string)$_SERVER['HTTP_HOST'];
    } elseif (!empty($_SERVER['SERVER_NAME'])) {
        $host = (string)$_SERVER['SERVER_NAME'];
    } elseif (!empty($_SERVER['SERVER_ADDR'])) {
        $host = (string)$_SERVER['SERVER_ADDR'];
    }
    $scriptName = $_SERVER['SCRIPT_NAME'] ?? '/sort_proxy.php';
    $dir = rtrim(str_replace('\\', '/', dirname($scriptName)), '/');
    if ($dir === '.' || $dir === '') $dir = '';
    return $scheme . '://' . $host . ($dir ? $dir : '');
}

function decode_nested_url(string $value): string {
    $current = trim($value);
    for ($i = 0; $i < 3; $i++) {
        $decoded = urldecode($current);
        if ($decoded === $current) break;
        $current = $decoded;
    }
    return $current;
}

function stream_url_from_block(array $block): string {
    foreach ($block as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) continue;
        return $line;
    }
    return '';
}

function render_preview_page(string $resolvedUrl, array $lines): void {
    header('Content-Type: text/html; charset=utf-8');
    $resolvedEsc = htmlspecialchars($resolvedUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $contentEsc = htmlspecialchars(implode("\n", $lines) . "\n", ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">';
    echo '<title>M3U Preview</title><style>body{font-family:Arial,sans-serif;background:#f7f7f7;margin:0;padding:24px}.wrap{max-width:1100px;margin:0 auto;background:#fff;border:1px solid #ddd;border-radius:8px;padding:16px}';
    echo 'h1{margin:0 0 10px}.meta{font-size:13px;color:#333;margin-bottom:12px;padding:8px;border-radius:6px;background:#f0f4ff;border:1px solid #d5def8}pre{white-space:pre-wrap;word-break:break-word;background:#111;color:#e6e6e6;padding:12px;border-radius:6px;max-height:75vh;overflow:auto}';
    echo '</style></head><body><div class="wrap">';
    echo '<h1>M3U Preview</h1>';
    echo '<div class="meta"><strong>Resolved source URL:</strong> ' . $resolvedEsc . '</div>';
    echo '<pre>' . $contentEsc . '</pre>';
    echo '</div></body></html>';
}

function render_channels_page(string $resolvedUrl, array $sortedBlocks, string $baseUrl): void {
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">';
    echo '<title>Channels View</title><style>body{font-family:Arial,sans-serif;background:#f7f7f7;margin:0;padding:24px}.wrap{max-width:1100px;margin:0 auto;background:#fff;border:1px solid #ddd;border-radius:8px;padding:16px}';
    echo 'h1{margin:0 0 10px}.meta{font-size:13px;color:#333;margin-bottom:12px;padding:8px;border-radius:6px;background:#f0f4ff;border:1px solid #d5def8}.list{display:flex;flex-direction:column;gap:8px}';
    echo '.row{display:grid;grid-template-columns:220px 1fr auto;align-items:center;gap:10px;padding:8px;border:1px solid #e2e2e2;border-radius:8px;background:#fff}.logo{width:200px;height:120px;object-fit:contain;background:#fafafa;border:1px solid #eee;border-radius:6px}';
    echo '.name{font-size:14px;color:#111}.link a{font-size:13px;color:#1565c0;text-decoration:none}.muted{color:#999;font-size:12px}</style></head><body><div class="wrap">';
    echo '<h1>Channels View</h1>';
    echo '<div class="meta"><strong>Resolved source URL:</strong> ' . htmlspecialchars($resolvedUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</div>';
    echo '<div class="list">';
    foreach ($sortedBlocks as $block) {
        $extinf = $block[0] ?? '';
        $attrs = parse_attrs($extinf);
        $name = str_contains($extinf, ',') ? trim(explode(',', $extinf, 2)[1]) : ($attrs['tvg-name'] ?? 'Unknown');
        $logo = $attrs['tvg-logo'] ?? '';
        $stream = stream_url_from_block($block);
        $displayLogo = '';
        if ($logo !== '') {
            if (str_starts_with($logo, './')) {
                $displayLogo = rtrim($baseUrl, '/') . '/' . ltrim(substr($logo, 2), '/');
            } elseif (preg_match('/^https?:\/\//i', $logo) === 1) {
                $displayLogo = $logo;
            } elseif (str_starts_with($logo, '/')) {
                $displayLogo = rtrim($baseUrl, '/') . $logo;
            }
        }
        // If page runs on HTTPS and logo points to same host over HTTP, upgrade it.
        $isHttpsPage = (stripos($baseUrl, 'https://') === 0);
        if ($isHttpsPage && stripos($displayLogo, 'http://') === 0) {
            $pageHost = parse_url($baseUrl, PHP_URL_HOST);
            $logoHost = parse_url($displayLogo, PHP_URL_HOST);
            if ($pageHost !== null && $logoHost !== null && strcasecmp((string)$pageHost, (string)$logoHost) === 0) {
                $displayLogo = 'https://' . substr($displayLogo, 7);
            }
        }
        $isPng = $displayLogo !== '' && preg_match('/\.png(?:\?|$)/i', $displayLogo) === 1;
        $nameEsc = htmlspecialchars($name, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $logoEsc = htmlspecialchars($displayLogo, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $streamEsc = htmlspecialchars($stream, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        echo '<div class="row">';
        echo $isPng ? '<img class="logo" src="' . $logoEsc . '" alt="logo" loading="lazy">' : '<div class="logo muted">PNG not found</div>';
        echo '<div class="name">' . $nameEsc . '</div>';
        echo $stream !== '' ? '<div class="link"><a href="' . $streamEsc . '" target="_blank" rel="noopener">Open stream</a></div>' : '<div class="link muted">No stream URL</div>';
        echo '</div>';
    }
    echo '</div></div></body></html>';
}

function render_form(): void {
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">';
    echo '<title>IPTV Sort Proxy</title>';
    echo '<style>body{font-family:Arial,sans-serif;background:#f7f7f7;margin:0;padding:24px}.wrap{max-width:780px;margin:0 auto;background:#fff;border:1px solid #ddd;border-radius:8px;padding:20px}';
    echo 'h1{margin-top:0;font-size:22px}label{display:block;margin:12px 0 6px}input[type=text]{width:100%;padding:10px;border:1px solid #bbb;border-radius:6px}';
    echo '.actions{margin-top:14px;display:flex;gap:8px;flex-wrap:wrap}button{padding:10px 14px;border:0;border-radius:6px;background:#1565c0;color:#fff;cursor:pointer}button.secondary{background:#555}';
    echo '.hint{color:#555;font-size:13px;margin-top:10px}</style></head><body><div class="wrap">';
    echo '<h1>IPTV Sort Proxy</h1>';
    echo '<p>Paste your playlist URL. The script will URL-encode it automatically, then you can download, preview, or browse channels.</p>';
    echo '<form id="sortForm" method="get" action="">';
    echo '<label for="source_url">Playlist URL</label>';
    echo '<input id="source_url" name="source_url" type="text" placeholder="http://iptv_host/get.php?username=...&password=...&type=m3u&output=ts" required>';
    echo '<input id="url" name="url" type="hidden">';
    echo '<input id="mode" name="mode" type="hidden" value="download">';
    echo '<div class="actions">';
    echo '<button type="submit" data-mode="download">Generate and Download</button>';
    echo '<button type="submit" class="secondary" data-mode="preview">Preview M3U</button>';
    echo '<button type="submit" class="secondary" data-mode="channels">Channels View</button>';
    echo '</div>';
    echo '</form>';
    echo '<div class="hint">Tip: you can still call this endpoint directly using <code>?url=...</code>.</div>';
    echo '</div><script>';
    echo 'document.querySelectorAll("#sortForm button[type=submit]").forEach(function(btn){btn.addEventListener("click",function(){document.getElementById("mode").value=btn.getAttribute("data-mode")||"download";});});';
    echo 'document.getElementById("sortForm").addEventListener("submit",function(e){var src=document.getElementById("source_url").value.trim();if(!src){e.preventDefault();return;}document.getElementById("url").value=encodeURIComponent(src);});';
    echo '</script></body></html>';
}

// 1) Prefer source_url from form (it arrives decoded by PHP and avoids double-encoding issues)
$url = '';
$mode = $_GET['mode'] ?? (isset($_GET['preview']) && $_GET['preview'] === '1' ? 'preview' : 'download');
if (isset($_GET['source_url']) && trim((string)$_GET['source_url']) !== '') {
    $url = trim((string)$_GET['source_url']);
}

// 2) Fallback to ?url=... (supports raw/encoded/double-encoded nested URL)
if ($url === '' && isset($_GET['url'])) {
    $url = decode_nested_url((string)$_GET['url']);
}

// 3) Extra fallback for clients passing only raw query string
if ($url === '' && isset($_SERVER['QUERY_STRING']) && str_starts_with($_SERVER['QUERY_STRING'], 'url=')) {
    $rawUrl = substr($_SERVER['QUERY_STRING'], 4);
    if ($rawUrl !== '') $url = decode_nested_url($rawUrl);
}

if ($url === '') {
    render_form();
    exit;
}

$ctx = stream_context_create(['http' => ['timeout' => 12, 'header' => "User-Agent: IPTV-Sorter/1.0\r\n"]]);
$raw = @file_get_contents($url, false, $ctx);
if ($raw === false) {
    http_response_code(502);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Cannot fetch URL: $url\n";
    exit;
}

$baseDir = __DIR__;
$refOrder = load_order_file($baseDir . '/channel_order.txt');
if (!$refOrder) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Missing or empty order file: {$baseDir}/channel_order.txt\n";
    exit;
}
$orderIndex = [];
foreach ($refOrder as $i => $k) $orderIndex[$k] = $i;
$knownKeys = array_keys($orderIndex);
$aliasLookup = build_alias_lookup($knownKeys);
$iconMap = build_icon_map($baseDir . '/input.m3u');
$baseUrl = base_url_for_script_dir();

$lines = preg_split('/\R/u', $raw) ?: [];
[$preamble, $entries] = parse_entries($lines);

$sortable = [];
$unknownBase = 1000000000;
foreach ($entries as $i => $block) {
    $extinf = $block[0];
    $key = classify_key($extinf, $knownKeys, $aliasLookup);
    if ($key !== null) {
        $logo = null;
        if (isset($iconMap[$key])) {
            $logo = $iconMap[$key];
            if (str_starts_with($logo, './')) {
                $logo = $baseUrl . '/' . ltrim(substr($logo, 2), '/');
            }
        } else {
            $logo = local_logo_url_for_key($key, $baseDir, $baseUrl);
        }
        // For recognized channels prefer local branding and do not keep provider logos.
        $block[0] = set_attr($extinf, 'tvg-logo', $logo ?? '');
    }
    $rank = $key !== null && isset($orderIndex[$key]) ? $orderIndex[$key] : $unknownBase + $i;
    $sortable[] = ['rank' => $rank, 'idx' => $i, 'block' => $block];
}

usort($sortable, function ($a, $b) {
    if ($a['rank'] === $b['rank']) return $a['idx'] <=> $b['idx'];
    return $a['rank'] <=> $b['rank'];
});

$out = count($preamble) ? $preamble : ['#EXTM3U'];
foreach ($sortable as $x) {
    foreach ($x['block'] as $line) $out[] = $line;
}

if ($mode === 'channels') {
    $sortedBlocks = array_map(fn($x) => $x['block'], $sortable);
    render_channels_page($url, $sortedBlocks, $baseUrl);
} elseif ($mode === 'preview') {
    render_preview_page($url, $out);
} else {
    header('Content-Type: application/x-mpegURL; charset=utf-8');
    header('Content-Disposition: attachment; filename="sorted_output.m3u"');
    echo implode("\n", $out) . "\n";
}
