<?php /* ======================
   SAVE THE BELOW AS: backtest.php
   Purpose: Real 20y backtest (annual equal-weight rebalancing) for your tickers
   Data: Alpha Vantage TIME_SERIES_MONTHLY_ADJUSTED + FX USD→EUR
   Usage: php -S localhost:8000  and open  http://localhost:8000/backtest.php
====================== */ ?>
<?php
// -------- Backtest (standalone) --------
// Requirements: .env with ALPHAVANTAGE_KEY=...

// --- .env loader ---
function bt_load_env(string $path): void {
  if (!is_file($path)) return;
  foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
    if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) continue;
    [$k, $v] = array_map('trim', explode('=', $line, 2));
    if ((str_starts_with($v, '"') && str_ends_with($v, '"')) || (str_starts_with($v, "'") && str_ends_with($v, "'"))) $v = substr($v,1,-1);
    $_ENV[$k] = $v; putenv($k.'='.$v);
  }
}

bt_load_env(__DIR__.'/.env');
$ALPHA = getenv('ALPHAVANTAGE_KEY') ?: '';
if ($ALPHA==='') die('Bitte ALPHAVANTAGE_KEY in .env setzen.');

// --- tiny cache (1 Tag) ---
function bt_cache_get(string $k): ?string {
  $f = sys_get_temp_dir()."/bt_".md5($k);
  if (is_file($f) && time() - filemtime($f) < 86400) return @file_get_contents($f) ?: null;
  return null;
}
function bt_cache_set(string $k, string $v): void { @file_put_contents(sys_get_temp_dir()."/bt_".md5($k), $v); }

// --- HTTP (cURL) ---
function bt_http(string $url, bool $do_cache=true): ?string {
  if ($do_cache && ($c=bt_cache_get($url))) return $c;
  $ch = curl_init($url);
  curl_setopt_array($ch,[
    CURLOPT_RETURNTRANSFER=>true,
    CURLOPT_FOLLOWLOCATION=>true,
    CURLOPT_TIMEOUT=>20,
    CURLOPT_CONNECTTIMEOUT=>10,
    CURLOPT_SSL_VERIFYPEER=>true,
    CURLOPT_SSL_VERIFYHOST=>2,
    CURLOPT_HTTPHEADER=>['Accept: application/json','User-Agent: PHP-Backtest/1.0']
  ]);
  $res = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); $err = curl_error($ch); curl_close($ch);
  if ($res!==false && $code>=200 && $code<300) { if ($do_cache) bt_cache_set($url,$res); return $res; }
  return null;
}

// --- Data fetchers ---
function alpha_call(string $function, array $params, string $key, int $max_tries=6): array {
  $params['function'] = $function; $params['apikey'] = $key;
  $url = 'https://www.alphavantage.co/query?'.http_build_query($params);
  for ($i=0; $i<$max_tries; $i++) {
    $body = bt_http($url, false); // no cache on first fetch
    if ($body === null) { usleep(500000); continue; }
    $d = json_decode($body, true);
    if (isset($d['Note'])) { sleep(15); continue; } // rate limited
    if (isset($d['Error Message'])) { throw new RuntimeException('Alpha: '.$d['Error Message']); }
    if (isset($d['Information']))   { throw new RuntimeException('Alpha: '.$d['Information']); }
    // success → cache and return
    bt_cache_set($url, $body);
    return $d;
  }
  throw new RuntimeException('Alpha: rate limit/no data after retries');
}
function alpha_monthly_adjusted(string $symbol, string $key): array {
  $d = alpha_call('TIME_SERIES_MONTHLY_ADJUSTED', ['symbol'=>$symbol], $key);
  if (!isset($d['Monthly Adjusted Time Series'])) throw new RuntimeException('Alpha response unexpected for '.$symbol);
  $rows = [];
  foreach ($d['Monthly Adjusted Time Series'] as $date=>$row) $rows[$date] = (float)$row['5. adjusted close'];
  ksort($rows); return $rows;
}
function alpha_fx_monthly_usdeur(string $key): array {
  $d = alpha_call('FX_MONTHLY', ['from_symbol'=>'USD','to_symbol'=>'EUR'], $key);
  if (!isset($d['Time Series FX (Monthly)'])) throw new RuntimeException('FX response unexpected');
  $rows = [];
  foreach ($d['Time Series FX (Monthly)'] as $date=>$row) $rows[$date] = (float)$row['4. close'];
  ksort($rows); return $rows;
}

// --- Utility ---
function guess_currency(string $symbol): string {
  if (preg_match('/\.(DE|AS|PA)$/i', $symbol)) return 'EUR';
  return 'USD'; // default
}

function intersect_months(array $series_list): array {
  $sets = [];
  foreach ($series_list as $s) $sets[] = array_keys($s);
  $common = array_shift($sets) ?? [];
  foreach ($sets as $a) $common = array_values(array_intersect($common, $a));
  sort($common);
  return $common;
}

function simulate_equal_weight(array $price_series_eur, string $start_month, int $initial=10000, int $rebalance_every_months=12): array {
  // $price_series_eur: list of maps month => price_eur (adj close)
  $months = intersect_months($price_series_eur);
  $months = array_values(array_filter($months, fn($m)=>$m >= $start_month));
  if (count($months) < ($rebalance_every_months+1)) throw new RuntimeException('Nicht genug Historie.');

  $n = count($price_series_eur); $cash = 0.0; $units = array_fill(0,$n,0.0);
  // allocate at first month close
  $m0 = $months[0];
  for ($i=0;$i<$n;$i++) { $p = $price_series_eur[$i][$m0]; $units[$i] = ($initial/$n)/$p; }
  $history = [ $m0 => $initial ]; $last_reb_mi = 0;

  for ($mi=1; $mi<count($months); $mi++) {
    $m = $months[$mi];
    // portfolio value at month m
    $pv = 0.0; $prices = [];
    for ($i=0;$i<$n;$i++){ $prices[$i] = $price_series_eur[$i][$m]; $pv += $units[$i]*$prices[$i]; }
    $history[$m] = $pv + $cash;
    // rebalance at schedule end-of-month
    if ( ($mi - $last_reb_mi) >= $rebalance_every_months ) {
      $target = ($pv + $cash)/$n;
      for ($i=0;$i<$n;$i++) { $units[$i] = $target / $prices[$i]; }
      $cash = 0.0; $last_reb_mi = $mi; $history[$m] = $target*$n; // record after-rebal value
    }
  }
  return $history; // month => value
}

// --- Configure your tickers ---
$tickers = [ 'AAPL','MSFT','SAP.DE','NVDA','ASML.AS','OR.PA','A7S.DE','BMW.DE','SIE.DE','ADS.DE' ];
$start_month = date('Y-m-01', strtotime('-20 years')); // earliest desired

try {
  // fetch series
  $fx = alpha_fx_monthly_usdeur($ALPHA);
  $series_eur = []; $ok = []; $warnings = [];
  foreach ($tickers as $sym) {
    try {
      $px = alpha_monthly_adjusted($sym, $ALPHA); // adj close
      if (guess_currency($sym) === 'USD') {
        $conv = [];
        foreach ($px as $m=>$p) if (isset($fx[$m])) $conv[$m] = $p * $fx[$m];
        $px = $conv;
      }
      if (count($px) < 12) throw new RuntimeException('zu wenig Historie');
      $series_eur[] = $px; $ok[] = $sym;
    } catch (Throwable $ex) {
      $warnings[] = $sym.': '.$ex->getMessage();
    }
  }
  if (count($series_eur) < 2) throw new RuntimeException('Zu wenig gültige Ticker nach Fetch.');
  // align and simulate
  $hist = simulate_equal_weight($series_eur, $start_month, 10000, 12);
  $months = array_keys($hist); $first = reset($hist); $last = end($hist);
  $years = (strtotime(end($months)) - strtotime(reset($months)))/31557600.0;
  $cagr = pow($last/$first, 1/max(1e-9,$years)) - 1.0;
}
catch (Throwable $e) {
  http_response_code(500);
  echo '<pre style="color:#ffb4b4">Fehler: '.htmlspecialchars($e->getMessage())."
".htmlspecialchars($e->getTraceAsString()).'</pre>'; exit;
}

?><!doctype html>
<html lang="de"><head><meta charset="utf-8"><title>Backtest 20y – Equal-Weight</title>
<style>body{font-family:system-ui,sans-serif;background:#0b1020;color:#e8edff;margin:0;padding:24px} .card{background:#131a33;border-radius:16px;padding:16px;margin-bottom:16px} table{width:100%;border-collapse:collapse;margin-top:12px} th,td{padding:6px 8px;border-bottom:1px solid #263063;text-align:right} th{color:#aab3d6} td:first-child,th:first-child{text-align:left}</style>
</head><body>
<div class="card">
  <h1>Backtest (≈20 Jahre) – Equal-Weight, jährliches Rebalancing</h1>
  <div>Tickers (geladen): <strong><?= htmlspecialchars(implode(', ',$ok ?? $tickers)) ?></strong></div>
  <?php if (!empty($warnings)): ?>
    <div style="margin-top:6px;color:#ffb3b3">Übersprungen/Fehler:
      <ul><?php foreach ($warnings as $w): ?><li><?= htmlspecialchars($w) ?></li><?php endforeach; ?></ul>
    </div>
  <?php endif; ?>
</div>
<div class=\"card\">
  <h2>Monatliche Historie</h2>
  <table><thead><tr><th>Monat</th><th>Portfoliowert (€)</th></tr></thead><tbody>
  <?php foreach($hist as $m=>$v): ?>
    <tr><td><?= htmlspecialchars($m) ?></td><td><?= number_format($v,2,',','.') ?></td></tr>
  <?php endforeach; ?>
  </tbody></table>
</div>
</body></html>
