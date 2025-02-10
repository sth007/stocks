<?php
if (!file_exists('config.php')) {
    die('Fehler: Konfigurationsdatei nicht gefunden.');
}
require 'config.php';
require 'api_handler.php';

// Wechselkurs abrufen
list($exchangeRate, $response, $exchangeData) = fetchExchangeRate(CURRENCY, EXCHANGE_CACHE_FILE, CACHE_TIME, $apiError);

$values = [];
$totalValue = 0;

list($stockData, $data) = fetchStockPrices($stocks, STOCK_CACHE_FILE, CACHE_TIME, $apiError);
if (empty($stockData)) {
    $apiError = 'Fehler beim Abrufen der Aktienkurse. Es werden zwischengespeicherte Daten verwendet.';
    $stockData = json_decode(file_get_contents(STOCK_CACHE_FILE), true) ?? [];
}

foreach ($stocks as $symbol => $quantity) {
    $latestPrice = $stockData[$symbol] ?? 0;
    $values[$symbol] = ($latestPrice * $quantity) * $exchangeRate;
    $totalValue += $values[$symbol];
}
?>

<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Aktienportfolio-Wert</title>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<body>
    <?php if (LOGLEVEL === 'debug'): ?>
    <button onclick="document.getElementById('debug').style.display='block'">Fehler anzeigen</button>
    <?php endif; ?>
    <?php if (LOGLEVEL === 'debug'): ?>
    <pre id="debug" style="display:none;">API Response Wechselkurs:
    <?php var_dump($response); ?>
    
    Dekodierte Wechselkurs-Daten:
    <?php var_dump($exchangeData); ?>
    
    API Response Aktienkurse:
    <?php var_dump($data); ?></pre>
    <?php endif; ?>
    <?php if ($apiError): ?>
        <div style="color: red; font-weight: bold;">Hinweis: <?php echo $apiError; ?></div>
    <?php endif; ?>
    <h2>Gesamtwert der Aktien: <?php echo numfmt_format(numfmt_create('de_DE', NumberFormatter::CURRENCY), $totalValue); ?> <?php echo CURRENCY; ?> (Umrechnungskurs: <?php echo number_format($exchangeRate, 4, ',', '.'); ?>)</h2>
    <canvas id="stockChart" width="600" height="400"></canvas>
<script>
var canvas = document.getElementById('stockChart');
if (canvas) {
	var ctx = canvas.getContext('2d');
	var stockChart = new Chart(ctx, {
	type: 'bar',
		data: {
		labels: <?php echo json_encode(array_keys($values)); ?>,
			datasets: [{
			label: 'Wert (<?php echo CURRENCY; ?>)',
				data: <?php echo json_encode(array_values($values)); ?>,
				backgroundColor: 'blue'
	}]
	},
		options: {
		responsive: true,
			scales: {
			x: { display: true },
				y: { display: true }
	}
	}
	});
}
	</script>
</body>
</html>

