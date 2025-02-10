<?php
if (!file_exists('config.php')) {
    die('Fehler: Konfigurationsdatei nicht gefunden.');
}
require 'config.php';
require 'api_handler.php';

// Konfigurierbare Farben für den aktuellen Wert
$color_current_default = 'rgba(54, 162, 235, 0.7)'; // Standardfarbe für aktuellen Wert
$color_higher = 'rgba(0, 128, 0, 0.7)'; // Grün, falls aktueller Wert höher als Durchschnitt
$color_lower = 'rgba(255, 0, 0, 0.7)'; // Rot, falls aktueller Wert niedriger als Durchschnitt

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

// Durchschnittswert pro Aktie berechnen
$averageValue = $totalValue / count($stocks);

// Berechnung der benötigten Aktienanzahl pro Aktie
$stockCounts = [];
$chartColors = [];
foreach ($stocks as $symbol => $quantity) {
    $latestPrice = $stockData[$symbol] ?? 0;
    $stockCounts[$symbol] = $latestPrice > 0 ? round($averageValue / ($latestPrice * $exchangeRate), 2) : 0;

    // Farbe bestimmen: Rot falls niedriger, Grün falls höher
    $chartColors[$symbol] = ($values[$symbol] < $averageValue) ? $color_lower : $color_higher;
}
?>

<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Aktienportfolio-Analyse</title>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
</head>
<body class="container mt-4">
    <h2 class="text-center">Depot-Analyse</h2>
    <p class="text-center">Gesamtwert des Depots: <strong><?= number_format($totalValue, 2) ?> €</strong></p>
    <p class="text-center">Durchschnittswert pro Aktie: <strong><?= number_format($averageValue, 2) ?> €</strong></p>

    <table class="table table-striped">
        <thead>
            <tr>
                <th>Aktie</th>
                <th>Preis (€)</th>
                <th>Benötigte Anzahl</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($stockCounts as $symbol => $count): ?>
                <tr>
                    <td><?= $symbol ?></td>
                    <td><?= number_format($stockData[$symbol] * $exchangeRate, 2) ?></td>
                    <td><?= $count ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <canvas id="stockChart"></canvas>

    <script>
        const ctx = document.getElementById('stockChart').getContext('2d');
        const labels = <?= json_encode(array_keys($values)) ?>;
        const currentValues = <?= json_encode(array_values($values)) ?>;
        const averageValues = Array(labels.length).fill(<?= json_encode($averageValue) ?>);
        const barColors = <?= json_encode(array_values($chartColors)) ?>;

        const stockChart = new Chart(ctx, {
            type: 'bar',
            data: {
                labels: labels,
                datasets: [
                    {
                        label: 'Aktueller Wert (€)',
                        data: currentValues,
                        backgroundColor: barColors,
                        borderColor: 'rgba(0, 0, 0, 0.1)',
                        borderWidth: 1
                    },
                    {
                        label: 'Durchschnittswert (€)',
                        data: averageValues,
                        backgroundColor: 'rgba(54, 162, 235, 0.5)',
                        borderColor: 'rgba(0, 0, 0, 0.1)',
                        borderWidth: 1
                    }
                ]
            },
            options: {
                responsive: true,
                plugins: {
                    legend: { position: 'top' }
                },
                scales: {
                    y: { beginAtZero: true }
                }
            }
        });
    </script>

</body>
</html>
