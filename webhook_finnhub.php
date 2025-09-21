<?php
// Datei: webhook_finnhub.php
// Ziel: eingehende Webhook-Calls + X-Finnhub-Secret in Textdatei protokollieren

// --- .env laden (optional) ---
function load_env($p){
  if(!is_file($p)) return;
  foreach(file($p, FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES) as $l){
    if($l==='' || $l[0]==='#' || !str_contains($l,'=')) continue;
    [$k,$v] = array_map('trim', explode('=', $l, 2));
    if(($v[0]??'')===($v[strlen($v)-1]??'') && ($v[0]==="'"||$v[0]==='"')) $v = substr($v,1,-1);
    putenv("$k=$v"); $_ENV[$k]=$v;
  }
}
load_env(__DIR__.'/.env');

// --- Log-Ziel (Textdatei) ---
$logFile = __DIR__.'/logs/finnhub_webhook.log';
@is_dir(dirname($logFile)) || @mkdir(dirname($logFile), 0775, true);

// --- Header-Helper (auch ohne Apache) ---
function all_headers(): array {
  if (function_exists('getallheaders')) return getallheaders();
  $h = [];
  foreach($_SERVER as $k=>$v){
    if (strncmp($k,'HTTP_',5)===0) {
      $name = str_replace(' ', '-', ucwords(strtolower(str_replace('_',' ', substr($k,5)))));
      $h[$name] = $v;
    }
  }
  return $h;
}

$method  = $_SERVER['REQUEST_METHOD'] ?? '';
$uri     = $_SERVER['REQUEST_URI'] ?? '';
$ip      = $_SERVER['REMOTE_ADDR'] ?? '';
$headers = all_headers();
$secretH = $headers['X-Finnhub-Secret'] ?? ($_SERVER['HTTP_X_FINNHUB_SECRET'] ?? '');
$raw     = file_get_contents('php://input') ?: '';

// --- ALLES loggen (auch vor Auth) ---
$line = sprintf(
  "[%s] %s %s  ip=%s  secret=%s  body=%s",
  date('c'), $method, $uri, $ip, $secretH, $raw
);
file_put_contents($logFile, $line.PHP_EOL, FILE_APPEND | LOCK_EX);

// --- Auth prüfen (401 bei Secret-Mismatch) ---
$expected = trim(getenv('FINNHUB_WEBHOOK_SECRET') ?: '');
if ($method !== 'POST' || $expected === '' || !hash_equals($expected, trim((string)$secretH))) {
  http_response_code(401);
  echo "unauthorized";
  exit;
}

// --- Sofort ACK (2xx) ---
http_response_code(204);
header('Connection: close');
header('Content-Length: 0');
flush();
if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();

// --- Danach (asynchron) weiterverarbeiten / weiteres Logging ---
file_put_contents(
  __DIR__.'/logs/finnhub_events.ndjson',
  json_encode(['ts'=>date('c'),'ip'=>$ip,'secretH'=>$secretH, 'headers'=>$headers,'body'=>json_decode($raw,true)], JSON_UNESCAPED_SLASHES).PHP_EOL,
  FILE_APPEND | LOCK_EX
);

