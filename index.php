<?php
// Equal-Weight Rebalancing – cents-based arithmetic, CSRF, basic validation
// + Live-Preisabfrage (Alpha Vantage / Finnhub) mit .env und 60s File-Cache
// + DEBUG-MODUS zum Aufspüren von Fehlern bei fetch_prices
// Save as index.php and run: php -S localhost:8000

session_start();
if (empty($_SESSION['csrf'])) { $_SESSION['csrf'] = bin2hex(random_bytes(32)); }
$csrf = $_SESSION['csrf'];

// ===== Helpers: money & shares =====
const MONEY_SCALE = 100; // cents
function r_he($v, $precision = 0) { return (int)round($v, $precision, PHP_ROUND_HALF_EVEN); }
function to_cents(float $eur): int { return r_he($eur * MONEY_SCALE, 0); }
function from_cents(int $cents): float { return $cents / MONEY_SCALE; }
function num(float $v, int $dec = 2): string { return number_format($v, $dec, ',', '.'); }
function num_shares(float $v, int $dec): string { return number_format($v, $dec, ',', '.'); }

// ===== Simple .env loader =====
function load_env(string $path): void {
  if (!is_file($path)) return;
  foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
    if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) continue;
    [$k, $v] = array_map('trim', explode('=', $line, 2));
    if ($k === '') continue;
    // strip optional quotes
    if ((str_starts_with($v, '"') && str_ends_with($v, '"')) || (str_starts_with($v, "'") && str_ends_with($v, "'"))) {
      $v = substr($v, 1, -1);
    }
    $_ENV[$k] = $v; putenv($k.'='.$v);
  }
}
load_env(__DIR__.'/.env');

// ===== API keys =====
$ALPHAVANTAGE_KEY = getenv('ALPHAVANTAGE_KEY') ?: '';
$FINNHUB_KEY      = getenv('FINNHUB_KEY')      ?: '';

// ===== Cache TTL & Provider-Auswahl =====
$CACHE_TTL = getenv('PRICE_CACHE_TTL') ? max(1, (int)getenv('PRICE_CACHE_TTL')) : 86400; // Standard: 24h
$provider  = $_POST['provider'] ?? 'auto';
if (!in_array($provider, ['auto','finnhub','alphavantage'], true)) { $provider = 'auto'; }

// ===== Debug flags =====
$action = $_POST['action'] ?? 'rebalance';
$DEBUG  = false; // aktiviert Debug-Panel
$NOCACHE = false; // Cache umgehen
if ($action === 'fetch_prices_debug') { $DEBUG = true; $NOCACHE = true; $action = 'fetch_prices'; }
// Manuell via Hidden-Inputs
if (isset($_POST['debug']) && $_POST['debug'] === '1') $DEBUG = true;
if (isset($_POST['nocache']) && $_POST['nocache'] === '1') $NOCACHE = true;

$DBG = []; // sammelt Debug-Infos
function dbg(array $row): void { global $DBG; $DBG[] = $row; }

// ===== Tiny file cache (60s) =====
function cache_get(string $k): ?string {
  global $NOCACHE, $CACHE_TTL; if ($NOCACHE) return null;
  $f = sys_get_temp_dir()."/stk_".md5($k);
  if (is_file($f) && (time() - filemtime($f) < $CACHE_TTL)) return @file_get_contents($f) ?: null;
  return null;
}
function cache_set(string $k, string $v): void {
  @file_put_contents(sys_get_temp_dir()."/stk_".md5($k), $v);
}

// ===== HTTP GET helper (mit Debug) =====
function extract_http_status(?array $headers): ?int {
  if (!$headers) return null;
  foreach ($headers as $h) {
    if (preg_match('#HTTP/\S+\s+(\d{3})#', $h, $m)) return (int)$m[1];
  }
  return null;
}
function http_get(string $url): ?string {
  global $DEBUG; $t0 = microtime(true);
  // 1) Cache
  $cached = cache_get($url); if ($cached !== null) {
    if ($DEBUG) dbg(['provider'=>'http','event'=>'cache_hit','url'=>$url,'bytes'=>strlen($cached)]);
    return $cached;
  }
  // 2) cURL (funktioniert auch wenn allow_url_fopen=0)
  if (function_exists('curl_init')) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_FOLLOWLOCATION => true,
      CURLOPT_TIMEOUT => 8,
      CURLOPT_CONNECTTIMEOUT => 6,
      CURLOPT_SSL_VERIFYPEER => true,
      CURLOPT_SSL_VERIFYHOST => 2,
      CURLOPT_HEADER => true, // Header + Body zurückgeben
      CURLOPT_HTTPHEADER => [ 'Accept: application/json', 'User-Agent: PHP-Rebalancer/1.0' ],
    ]);
    $resp = curl_exec($ch);
    $errno = curl_errno($ch); $err = curl_error($ch);
    $info = curl_getinfo($ch);
    curl_close($ch);
    if ($resp === false) {
      if ($DEBUG) dbg(['provider'=>'http','event'=>'curl_error','url'=>$url,'errno'=>$errno,'error'=>$err]);
      return null;
    }
    $headerSize = $info['header_size'] ?? 0;
    $headersRaw = substr($resp, 0, $headerSize);
    $body = substr($resp, $headerSize);
    $status = (int)($info['http_code'] ?? 0);
    if ($DEBUG) dbg(['provider'=>'http','event'=>'fetch(curl)','url'=>$url,'status'=>$status,'ms'=>round((microtime(true)-$t0)*1000),'bytes'=>strlen($body),'header0'=>trim(strtok($headersRaw,"
"))]);
    if ($status >= 200 && $status < 300 && $body !== '') { cache_set($url, $body); return $body; }
    return null;
  }
  // 3) Fallback: file_get_contents (benötigt allow_url_fopen=On)
  if (ini_get('allow_url_fopen')) {
    $ctx = stream_context_create(['http' => ['timeout' => 8, 'header' => "User-Agent: PHP-Rebalancer/1.0
Accept: application/json
"]]);
    $res = @file_get_contents($url, false, $ctx);
    $headers = $http_response_header ?? [];
    $status = extract_http_status($headers);
    if ($DEBUG) dbg(['provider'=>'http','event'=>'fetch(fopen)','url'=>$url,'status'=>$status,'ms'=>round((microtime(true)-$t0)*1000),'bytes'=> $res!==false?strlen($res):0,'header0'=> $headers[0] ?? null]);
    if ($res !== false) { cache_set($url, $res); return $res; }
  } else {
    if ($DEBUG) dbg(['provider'=>'http','event'=>'no_transport','url'=>$url,'note'=>'allow_url_fopen=0 and no cURL']);
  }
  return null;
}

// ===== Providers =====
function fetch_price_alpha(string $symbol, string $key): ?float {
  if ($key === '') { dbg(['provider'=>'alphavantage','symbol'=>$symbol,'error'=>'missing_key']); return null; }
  $url = 'https://www.alphavantage.co/query?function=GLOBAL_QUOTE&symbol='.rawurlencode($symbol).'&apikey='.$key;
  $j = http_get($url); if (!$j) { dbg(['provider'=>'alphavantage','symbol'=>$symbol,'error'=>'no_response']); return null; }
  $data = json_decode($j, true);
  if (json_last_error() !== JSON_ERROR_NONE) { dbg(['provider'=>'alphavantage','symbol'=>$symbol,'error'=>'json_error','msg'=>json_last_error_msg(),'excerpt'=>substr($j,0,200)]); return null; }
  $p = $data['Global Quote']['05. price'] ?? null;
  dbg(['provider'=>'alphavantage','symbol'=>$symbol,'ok'=>$p!==null,'excerpt'=>substr($j,0,120)]);
  return $p ? (float)$p : null; // Achtung: oft USD
}
function fetch_price_finnhub(string $symbol, string $key): ?float {
  if ($key === '') { dbg(['provider'=>'finnhub','symbol'=>$symbol,'error'=>'missing_key']); return null; }
  $url = 'https://finnhub.io/api/v1/quote?symbol='.rawurlencode($symbol).'&token='.$key;
  $j = http_get($url); if (!$j) { dbg(['provider'=>'finnhub','symbol'=>$symbol,'error'=>'no_response']); return null; }
  $data = json_decode($j, true);
  if (json_last_error() !== JSON_ERROR_NONE) { dbg(['provider'=>'finnhub','symbol'=>$symbol,'error'=>'json_error','msg'=>json_last_error_msg(),'excerpt'=>substr($j,0,200)]); return null; }
  $p = $data['c'] ?? null; // current price
  dbg(['provider'=>'finnhub','symbol'=>$symbol,'ok'=>$p!==null,'raw_c'=>$p,'excerpt'=>substr($j,0,120)]);
  return ($p !== null) ? (float)$p : null;
}
function fetch_price(string $symbol): ?float {
  global $ALPHAVANTAGE_KEY, $FINNHUB_KEY;
  $p = fetch_price_finnhub($symbol, $FINNHUB_KEY);
  if ($p === null) $p = fetch_price_alpha($symbol, $ALPHAVANTAGE_KEY);
  return $p; // ggf. FX beachten
}

function fetch_price_pref(string $symbol, string $pref): ?float {
  global $ALPHAVANTAGE_KEY, $FINNHUB_KEY;
  switch ($pref) {
    case 'finnhub':      return fetch_price_finnhub($symbol, $FINNHUB_KEY);
    case 'alphavantage': return fetch_price_alpha($symbol, $ALPHAVANTAGE_KEY);
    default:             return fetch_price($symbol);
  }
}

// ===== Inputs =====
$rows             = $_POST['rows'] ?? [];
$fee_pct          = isset($_POST['fee_pct']) ? (float)$_POST['fee_pct'] : 0.0;
$rebalance_mode   = $_POST['rebalance_mode'] ?? 'use_current_total';
$custom_total_eur = isset($_POST['custom_total']) ? (float)$_POST['custom_total'] : 0.0;
$precision_shares = isset($_POST['precision_shares']) ? (int)$_POST['precision_shares'] : 4;

$errors = [];

// Demo rows on first load
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($rows)) {
  $rows = [
    ['selected' => '1', 'symbol' => 'AAPL',  'price' => '0',  'shares' => '1.0000'],
    ['selected' => '1', 'symbol' => 'MSFT',  'price' => '0',  'shares' => '0.9000'],
    ['selected' => '1', 'symbol' => 'SAP.DE','price' => '0',  'shares' => '0.7000'],
    ['selected' => '1', 'symbol' => 'NVDA',  'price' => '0',  'shares' => '0.8000'],
    ['selected' => '1', 'symbol' => 'ASML.AS','price' => '0',  'shares' => '0.9000'],
    ['selected' => '1', 'symbol' => 'OR.PA', 'price' => '0',  'shares' => '1.0000'],
    ['selected' => '1', 'symbol' => 'A7S.DE','price' => '0',  'shares' => '1.0000'],
    ['selected' => '1', 'symbol' => 'BMW.DE','price' => '0',  'shares' => '1.0000'],
    ['selected' => '1', 'symbol' => 'SIE.DE','price' => '0',  'shares' => '1.0000'],
    ['selected' => '1', 'symbol' => 'ADS.DE','price' => '0',  'shares' => '0.9000'],
  ];
}

// CSRF check
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!isset($_POST['csrf']) || !hash_equals($csrf, (string)$_POST['csrf'])) {
    $errors[] = 'Ungültiges oder fehlendes CSRF-Token.';
  }
}

// Clamp inputs
$fee_pct = max(0.0, min(100.0, $fee_pct));
$precision_shares = max(0, min(6, $precision_shares));
$share_scale = 10 ** $precision_shares;
$custom_total_cents = max(0, to_cents($custom_total_eur));
$symbol_regex = '/^[A-Za-z0-9._-]{1,24}$/';

// ===== Prefetch unique symbols (dedupe) for fetch_prices =====
$symbols_to_fetch = []; $price_map = []; $fetched_count = 0;
if (is_array($rows) && $action === 'fetch_prices' && empty($errors)) {
  foreach ($rows as $r) {
    $sym = isset($r['symbol']) ? trim((string)$r['symbol']) : '';
    $price_e = isset($r['price']) ? (float)$r['price'] : 0.0;
    if ($sym !== '' && preg_match($symbol_regex, $sym) && $price_e <= 0.0) { $symbols_to_fetch[] = $sym; }
  }
  if ($symbols_to_fetch) {
    $symbols_to_fetch = array_values(array_unique($symbols_to_fetch, SORT_STRING));
    foreach ($symbols_to_fetch as $sym) {
      $p = fetch_price_pref($sym, $provider);
      if ($p !== null) { $price_map[$sym] = $p; $fetched_count++; }
    }
  }
}

// ===== Parse rows =====
$clean_rows = [];
if (is_array($rows)) {
  $i = 0;
  foreach ($rows as $r) {
    if ($i++ > 2000) { $errors[] = 'Zu viele Zeilen (max 2000).'; break; }
    $selected = isset($r['selected']) && (string)$r['selected'] === '1';
    $symbol   = isset($r['symbol']) ? trim((string)$r['symbol']) : '';
    $price_e  = isset($r['price'])  ? (float)$r['price']  : 0.0;
    $shares_f = isset($r['shares']) ? (float)$r['shares'] : 0.0;

    if ($symbol === '' || !preg_match($symbol_regex, $symbol)) {
      if ($symbol !== '') { $errors[] = "Symbol ungültig: {$symbol}"; }
      $selected = false;
    }
    if ($price_e < 0)  { $errors[] = "Preis < 0 bei {$symbol}";  $price_e = 0.0; }
    if ($shares_f < 0) { $errors[] = "Stück < 0 bei {$symbol}"; $shares_f = 0.0; }

    // Optional: Live-Preis nachladen, wenn 0 oder leer
    if ($action === 'fetch_prices' && $price_e <= 0.0 && $symbol !== '') {
      if (isset($price_map[$symbol])) { $price_e = $price_map[$symbol]; }
    }

    $price_cents   = to_cents($price_e);
    $shares_scaled = r_he($shares_f * $share_scale, 0);
    $value_cents   = ($price_cents > 0 && $shares_scaled > 0)
      ? intdiv($price_cents * $shares_scaled + intdiv($share_scale,2), $share_scale)
      : 0;

    $clean_rows[] = [
      'selected'       => $selected,
      'symbol'         => $symbol,
      'price_cents'    => $price_cents,
      'shares_scaled'  => $shares_scaled,
      'value_cents'    => $value_cents,
    ];
  }
}

// ===== Totals =====
$current_total_cents = 0; $selected_count = 0;
foreach ($clean_rows as $r) {
  if ($r['selected']) { $selected_count++; }
  $current_total_cents += $r['value_cents'];
}

$use_custom = ($rebalance_mode === 'use_custom_total' && $custom_total_cents > 0);
$target_total_cents = $use_custom ? $custom_total_cents : $current_total_cents;
if ($selected_count === 0 && $_SERVER['REQUEST_METHOD'] === 'POST' && $action==='rebalance') {
  $errors[] = 'Keine Position ausgewählt – Rebalancing nicht möglich.';
}
$target_per_cents = $selected_count > 0 ? intdiv($target_total_cents, $selected_count) : 0;

// ===== Rebalance plan =====
$plan = [];
$total_buys_cents = 0; $total_sells_cents = 0; $total_fees_cents = 0;
if (empty($errors) && $action==='rebalance') {
  foreach ($clean_rows as $r) {
    $target_value_cents = $r['selected'] ? $target_per_cents : $r['value_cents'];
    $delta_before_fee_cents = $target_value_cents - $r['value_cents'];
    $trade_abs_cents = abs($delta_before_fee_cents);
    $fee_cents = r_he(($trade_abs_cents * $fee_pct) / 100.0, 0);

    $delta_after_fee_cents = $delta_before_fee_cents;
    if ($delta_before_fee_cents > 0) { $delta_after_fee_cents += $fee_cents; $total_buys_cents  += $delta_after_fee_cents; }
    elseif ($delta_before_fee_cents < 0) { $delta_after_fee_cents -= $fee_cents; $total_sells_cents += -$delta_after_fee_cents; }
    $total_fees_cents += $fee_cents;

    if ($r['price_cents'] > 0) {
      $target_shares_scaled = r_he(($target_value_cents * $share_scale) / $r['price_cents'], 0);
      $delta_shares_scaled  = r_he(($delta_after_fee_cents * $share_scale) / $r['price_cents'], 0);
    } else { $target_shares_scaled = 0; $delta_shares_scaled = 0; }

    $plan[] = [
      'symbol' => $r['symbol'], 'selected' => $r['selected'], 'price_cents' => $r['price_cents'],
      'shares_scaled' => $r['shares_scaled'], 'value_cents' => $r['value_cents'],
      'target_value_cents' => $target_value_cents, 'fee_cents' => $fee_cents,
      'target_shares_scaled' => $target_shares_scaled, 'delta_shares_scaled' => $delta_shares_scaled,
      'delta_after_fee_cents' => $delta_after_fee_cents,
    ];
  }
}

$net_cash_needed_cents = max(0, $total_buys_cents - $total_sells_cents);
$net_cash_freed_cents  = max(0, $total_sells_cents - $total_buys_cents);

// ===== Gesamtwert nach Rebalancing (nur Holdings) =====
$unselected_total_cents = 0;
foreach ($clean_rows as $r) { if (!$r['selected']) { $unselected_total_cents += $r['value_cents']; } }
$target_selected_total_cents = $selected_count > 0 ? ($selected_count * $target_per_cents) : 0; // intdiv-Rest bleibt unverteilt
$projected_holdings_total_cents = $target_selected_total_cents + $unselected_total_cents;
$delta_holdings_vs_current_cents = $projected_holdings_total_cents - $current_total_cents;

// ===== Runtime environment diagnostics =====
if ($DEBUG) {
  dbg(['provider'=>'env','allow_url_fopen'=>ini_get('allow_url_fopen'), 'openssl'=>extension_loaded('openssl')?'loaded':'missing', 'alpha_key'=> $ALPHAVANTAGE_KEY? 'set':'empty', 'finnhub_key'=>$FINNHUB_KEY?'set':'empty']);
}
?>
<!doctype html>
<html lang="de">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Rebalancing (Equal Weight) – PHP Tool</title>
  <style>
    body { font-family: system-ui, sans-serif; background:#0b1020; color:#e8edff; margin:0; }
    .wrap { max-width: 1200px; margin: 24px auto; padding: 0 16px; }
    .card { background:#131a33; border-radius:16px; padding:16px; margin-bottom:16px; }
    .tables-wrap { display:grid; grid-template-columns: 1fr 1fr; gap:16px; }
    table { width:100%; border-collapse:collapse; margin-top:16px; }
    th,td { padding:8px; border-bottom:1px solid #263063; text-align:right; }
    th { color:#aab3d6; }
    td:first-child, th:first-child { text-align:center; }
    td:nth-child(2), th:nth-child(2) { text-align:left; }
    .btn { background:#7aa2ff; border:none; padding:8px 12px; border-radius:8px; cursor:pointer; }
    .btn.secondary { background:transparent; border:1px solid #2a3468; color:#e8edff; }
    .pill { padding:2px 6px; border-radius:6px; font-size:12px; }
    .buy { background:rgba(51,209,122,.15); color:#33d17a; }
    .sell { background:rgba(255,107,107,.15); color:#ff6b6b; }
    .muted { color:#aab3d6; }
    .alert { background:#30161b; color:#ffc2c7; border:1px solid #6b1a22; padding:10px 12px; border-radius:10px; }
    code { background:#0f1630; padding:2px 4px; border-radius:4px; }
  </style>
</head>
<body>
<div class="wrap">
  <h1>Equal-Weight Rebalancing</h1>

  <div class="tables-wrap">
    <form method="post" class="card" autocomplete="off">
      <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf, ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8') ?>">
      <h2>Positionen</h2>
      <div style="display:flex; gap:12px; flex-wrap:wrap;">
        <label>Transaktionsgebühr (%)<br>
          <input type="number" name="fee_pct" step="0.01" min="0" max="100" value="<?= htmlspecialchars($fee_pct ?? 0, ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8') ?>">
        </label>
        <label>Nachkommastellen (Stückzahl)<br>
          <input type="number" name="precision_shares" min="0" max="6" value="<?= htmlspecialchars($precision_shares ?? 4, ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8') ?>">
        </label>
        <label>Quelle (API)<br>
          <select name="provider">
            <option value="auto" <?= $provider==='auto'?'selected':'' ?>>Auto (Finnhub→Alpha)</option>
            <option value="finnhub" <?= $provider==='finnhub'?'selected':'' ?>>Finnhub</option>
            <option value="alphavantage" <?= $provider==='alphavantage'?'selected':'' ?>>Alpha Vantage</option>
          </select>
        </label>
        <div>
          <div class="muted">Ziel-Gesamtwert</div>
          <label style="display:block;">
            <input type="radio" name="rebalance_mode" value="use_current_total" <?= $rebalance_mode==='use_current_total'?'checked':'' ?>>
            Aktueller Gesamtwert (<?= num(from_cents($current_total_cents)) ?> €)
          </label>
          <label style="display:flex; align-items:center; gap:8px;">
            <input type="radio" name="rebalance_mode" value="use_custom_total" <?= $rebalance_mode==='use_custom_total'?'checked':'' ?>>
            Benutzerdefiniert: <input type="number" name="custom_total" step="0.01" min="0" value="<?= htmlspecialchars($custom_total_eur ?? 0, ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8') ?>" style="width:140px"> €
          </label>
        </div>
      </div>

      <table>
        <thead><tr><th>✓</th><th>Symbol</th><th>Preis</th><th>Stück</th><th>Wert</th></tr></thead>
        <tbody>
        <?php foreach ($clean_rows as $idx=>$r): ?>
          <tr>
            <td><input type="hidden" name="rows[<?= $idx ?>][selected]" value="0"><input type="checkbox" name="rows[<?= $idx ?>][selected]" value="1" <?= $r['selected']?'checked':'' ?>></td>
            <td><input type="text" name="rows[<?= $idx ?>][symbol]" value="<?= htmlspecialchars($r['symbol'], ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8') ?>" maxlength="24" pattern="[A-Za-z0-9._-]+"></td>
            <td><input type="number" name="rows[<?= $idx ?>][price]" value="<?= htmlspecialchars(number_format(from_cents($r['price_cents']), 4, '.', ''), ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8') ?>" step="0.0001" min="0"></td>
            <td><input type="number" name="rows[<?= $idx ?>][shares]" value="<?= htmlspecialchars(number_format($r['shares_scaled']/$share_scale, $precision_shares, '.', ''), ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8') ?>" step="0.<?= str_repeat('0', max(0,$precision_shares-1)) ?>1" min="0"></td>
            <td><?= num(from_cents($r['value_cents'])) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>

      <div style="display:flex; gap:8px; margin-top:12px;">
        <button class="btn secondary" name="action" value="fetch_prices" title="Füllt leere/0-Preise per API nach">Preise abrufen</button>
        <button class="btn secondary" name="action" value="fetch_prices_debug" title="Abruf + Debug-Panel + Cache umgehen">Preise abrufen (Debug)</button>
        <button class="btn" name="action" value="rebalance">Rebalancing berechnen</button>
      </div>
    <?php if ($_SERVER['REQUEST_METHOD']==='POST' && $action==='fetch_prices'): ?>
      <div class="muted" style="margin-top:8px;">
        Preise aktualisiert: <strong><?= (int)$fetched_count ?></strong> / <?= (int)(isset($symbols_to_fetch)?count($symbols_to_fetch):0) ?> Symbole • Quelle: <strong><?= htmlspecialchars($provider, ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8') ?></strong>
      </div>
    <?php endif; ?>
    </form>

    <?php if ($_SERVER['REQUEST_METHOD']==='POST' && empty($errors) && $action==='rebalance'): ?>
    <div class="card">
      <h2>Vorschlag Trades</h2>
      <table>
        <thead><tr><th>Symbol</th><th>Aktion</th><th>Δ Stück</th><th>Δ Wert</th></tr></thead>
        <tbody>
        <?php foreach ($plan as $p): $act='Halten'; if($p['delta_after_fee_cents']>0)$act='Kaufen'; if($p['delta_after_fee_cents']<0)$act='Verkaufen'; ?>
          <tr>
            <td><?= htmlspecialchars($p['symbol'], ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8') ?><?= !$p['selected'] ? ' <span class="muted">(ausgeschlossen)</span>' : '' ?></td>
            <td><span class="pill <?= $act==='Kaufen'?'buy':($act==='Verkaufen'?'sell':'') ?>"><?= $act ?></span></td>
            <td><?= num_shares($p['delta_shares_scaled']/$share_scale,$precision_shares) ?></td>
            <td><?= num(from_cents($p['delta_after_fee_cents'])) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>

      <div class="muted" style="margin-top:8px;">
        Gesamtwert nach Rebalancing (Holdings): <strong><?= num(from_cents($projected_holdings_total_cents)) ?> €</strong>
        &nbsp;•&nbsp; Aktuell: <strong><?= num(from_cents($current_total_cents)) ?> €</strong>
        &nbsp;•&nbsp; Δ: <strong><?= num(from_cents($delta_holdings_vs_current_cents)) ?> €</strong>
      </div>
      <div class="muted" style="margin-top:8px;">
        Gebühren gesamt: <strong><?= num(from_cents($total_fees_cents)) ?> €</strong>&nbsp;•&nbsp;
        Verkäufe (netto): <strong><?= num(from_cents($total_sells_cents)) ?> €</strong>&nbsp;•&nbsp;
        Käufe (brutto): <strong><?= num(from_cents($total_buys_cents)) ?> €</strong>
      </div>
      <div class="muted">
        Netto zusätzlicher Cashbedarf: <strong><?= num(from_cents($net_cash_needed_cents)) ?> €</strong>
        &nbsp;•&nbsp; Netto frei werdender Cash: <strong><?= num(from_cents($net_cash_freed_cents)) ?> €</strong>
      </div>
    </div>
    <?php endif; ?>
  </div>

  <?php if (!empty($errors)): ?>
    <div class="card alert">
      <strong>Fehler:</strong>
      <ul><?php foreach ($errors as $e): ?><li><?= htmlspecialchars($e, ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8') ?></li><?php endforeach; ?></ul>
    </div>
  <?php endif; ?>

  <?php if ($DEBUG): ?>
    <div class="card">
      <h2>Debug-Panel</h2>
      <div class="muted">Keys: AlphaVantage=<code><?= $ALPHAVANTAGE_KEY ? 'SET' : 'EMPTY' ?></code> • Finnhub=<code><?= $FINNHUB_KEY ? 'SET' : 'EMPTY' ?></code> • allow_url_fopen=<code><?= htmlspecialchars((string)ini_get('allow_url_fopen')) ?></code> • openssl=<code><?= extension_loaded('openssl') ? 'loaded' : 'missing' ?></code></div>
      <table>
        <thead><tr><th>Provider</th><th>Symbol</th><th>Event/Status</th><th>Info</th></tr></thead>
        <tbody>
        <?php foreach ($DBG as $d): ?>
          <tr>
            <td><?= htmlspecialchars((string)($d['provider'] ?? ''), ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8') ?></td>
            <td><?= htmlspecialchars((string)($d['symbol'] ?? ''),   ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8') ?></td>
            <td>
              <?php if (isset($d['event'])): ?>
                <?= htmlspecialchars($d['event'], ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8') ?>
                <?php if (isset($d['status'])): ?> (HTTP <?= (int)$d['status'] ?>)<?php endif; ?>
              <?php elseif (isset($d['error'])): ?>
                <span style="color:#ff6b6b;"><?= htmlspecialchars($d['error'], ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8') ?></span>
              <?php else: ?>
                <?= isset($d['ok']) ? ($d['ok']? 'ok' : 'not ok') : '' ?>
              <?php endif; ?>
            </td>
            <td style="text-align:left; font-family:ui-monospace,monospace;">
              <?php if (isset($d['url'])): ?><div>URL: <code><?= htmlspecialchars($d['url'], ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8') ?></code></div><?php endif; ?>
              <?php if (isset($d['header0'])): ?><div>Hdr: <code><?= htmlspecialchars($d['header0'], ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8') ?></code></div><?php endif; ?>
              <?php if (isset($d['ms'])): ?><div>Time: <?= (int)$d['ms'] ?> ms • Bytes: <?= (int)($d['bytes'] ?? 0) ?></div><?php endif; ?>
              <?php if (isset($d['msg'])): ?><div>Msg: <code><?= htmlspecialchars((string)$d['msg'], ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8') ?></code></div><?php endif; ?>
              <?php if (isset($d['excerpt'])): ?><div>Ex: <code><?= htmlspecialchars((string)$d['excerpt'], ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8') ?></code></div><?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <p class="muted">Tipp: Nutze <strong>„Preise abrufen (Debug)”</strong>, um Cache zu umgehen und HTTP-Status, Header und JSON-Antworten zu sehen. Prüfe außerdem Ticker-Format (z. B. <code>SAP.DE</code>, <code>ASML.AS</code>) und API-Limits des Free-Tiers.</p>
    </div>
  <?php endif; ?>

</div>
</body>
</html>
