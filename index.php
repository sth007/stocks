<?php
if (!file_exists('config.php')) {
    die('Fehler: Konfigurationsdatei nicht gefunden.');
}
require 'config.php';
require 'api_handler.php';

// Einheitliche Farbe für alle Balken
$barColor = 'rgba(54, 162, 235, 0.7)'; // Blau

// Wechselkurs abrufen
list($exchangeRate, $response, $exchangeData) = fetchExchangeRate(CURRENCY, EXCHANGE_CACHE_FILE, CACHE_TIME, $apiError);

$totalValue = 0;

list($stockData, $data) = fetchStockPrices($stocks, STOCK_CACHE_FILE, CACHE_TIME, $apiError);
if (empty($stockData)) {
    $apiError = 'Fehler beim Abrufen der Aktienkurse. Es werden zwischengespeicherte Daten verwendet.';
    $stockData = json_decode(file_get_contents(STOCK_CACHE_FILE), true) ?? [];
}

$stockDataSorted = [];
$values = [];

foreach ($stocks as $symbol => $quantity) {
    $latestPrice = $stockData[$symbol] ?? 0;
    $currentValue = $latestPrice * $quantity * $exchangeRate;
    $values[$symbol] = $currentValue;
    $totalValue += $currentValue;
}

// Durchschnittlicher Wert pro Aktie berechnen
$averageValue = $totalValue / count($stocks);

foreach ($stocks as $symbol => $quantity) {
    $needed_shares = ($stockData[$symbol] > 0) ? ($averageValue / ($stockData[$symbol] * $exchangeRate)) : 0;
    $difference_cnt = $quantity - $needed_shares;
    $difference = $difference_cnt * ($stockData[$symbol] * $exchangeRate);

    $stockDataSorted[] = [
        'symbol' => $symbol,
        'price' => number_format($stockData[$symbol] * $exchangeRate, 2),
        'current_shares' => $quantity,
        'needed_shares' => $needed_shares,
        'difference_cnt' => $difference_cnt,
        'difference' => $difference,
        'current_value' => $values[$symbol]
    ];
}

// Sortieren nach Differenz absteigend
usort($stockDataSorted, function ($a, $b) {
    return $b['difference'] <=> $a['difference'];
});
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Aktienportfolio-Analyse</title>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chartjs-plugin-annotation"></script>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
</head>
<body class="container mt-4">
    <h2 class="text-center">Depot-Analyse</h2>
    <p class="text-center">Aktueller Umrechnungskurs: <strong><?= number_format($exchangeRate, 4) ?></strong></p>
    <p class="text-center">Gesamtwert des Depots: <strong><?= number_format($totalValue, 2) ?> €</strong></p>
    <p class="text-center">Durchschnittlicher Wert pro Aktie: <strong><?= number_format($averageValue, 2) ?> €</strong></p>

    <table class="table table-striped">
        <thead>
            <tr>
                <th>Aktie</th>
                <th>Preis (€)</th>
                <th>Aktuelle Anzahl</th>
                <th>Benötigte Anzahl</th>
                <th>Differenz (Anzahl)</th>
                <th>Differenz (€)</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($stockDataSorted as $stock): ?>
                <tr>
                    <td><?= $stock['symbol'] ?></td>
                    <td><?= $stock['price'] ?></td>
                    <td><?= $stock['current_shares'] ?></td>
                    <td><?= $stock['needed_shares'] ?></td>
                    <td><?= $stock['difference_cnt'] ?></td>
                    <td><?= $stock['difference'] ?></td> 
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <canvas id="stockChart"></canvas>
    <script>
        const ctx = document.getElementById('stockChart').getContext('2d');
        const labels = <?= json_encode(array_column($stockDataSorted, 'symbol')) ?>;
        const currentValues = <?= json_encode(array_column($stockDataSorted, 'current_value')) ?>;
        const averageValue = <?= json_encode($averageValue) ?>;
        const barColor = <?= json_encode($barColor) ?>;

        const stockChart = new Chart(ctx, {
            type: 'bar',
            data: {
                labels: labels,
                datasets: [
                    {
                        label: 'Aktueller Wert (€)',
                        data: currentValues,
                        backgroundColor: barColor,
                        borderColor: 'rgba(0, 0, 0, 0.1)',
                        borderWidth: 1
                    }
                ]
            },
            options: {
                responsive: true,
                plugins: {
                    legend: { position: 'top' },
                    annotation: {
                        annotations: {
                            line1: {
                                type: 'line',
                                yMin: averageValue,
                                yMax: averageValue,
                                borderColor: 'blue',
                                borderWidth: 2,
                                label: {
                                    content: 'Durchschnitt: ' + averageValue.toFixed(2) + ' €',
                                    enabled: true,
                                    position: 'start'
                                }
                            }
                        }
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true
                    }
                }
            }
        });
    </script>
</body>
</html>
