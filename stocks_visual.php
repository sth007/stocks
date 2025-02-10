<?php
// Datei: stocks_visual.php

// Lade die JSON-Daten
$stock_data = json_decode(file_get_contents("stock_data.json"), true);

// Gesamtwert des Depots berechnen
$total_value = array_sum($stock_data);

// Durchschnittswert pro Aktie berechnen
$average_value = $total_value / count($stock_data);

// Anzahl der Aktien berechnen, damit jede Aktie den Durchschnittswert erreicht
$stock_counts = [];
foreach ($stock_data as $symbol => $price) {
    $stock_counts[$symbol] = round($average_value / $price, 2);
}

// HTML-Seite mit Bootstrap und Chart.js
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Depot-Visualisierung</title>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
</head>
<body class="container mt-4">

    <h2 class="text-center">Depot-Visualisierung</h2>
    <p class="text-center">Gesamtwert des Depots: <strong><?= number_format($total_value, 2) ?> €</strong></p>
    <p class="text-center">Durchschnittswert pro Aktie: <strong><?= number_format($average_value, 2) ?> €</strong></p>

    <table class="table table-striped">
        <thead>
            <tr>
                <th>Aktie</th>
                <th>Preis (€)</th>
                <th>Benötigte Anzahl</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($stock_counts as $symbol => $count): ?>
                <tr>
                    <td><?= $symbol ?></td>
                    <td><?= number_format($stock_data[$symbol], 2) ?></td>
                    <td><?= $count ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <canvas id="stockChart"></canvas>

    <script>
        const ctx = document.getElementById('stockChart').getContext('2d');
        const stockChart = new Chart(ctx, {
            type: 'bar',
            data: {
                labels: <?= json_encode(array_keys($stock_counts)) ?>,
                datasets: [{
                    label: 'Benötigte Aktienanzahl',
                    data: <?= json_encode(array_values($stock_counts)) ?>,
                    backgroundColor: 'rgba(75, 192, 192, 0.2)',
                    borderColor: 'rgba(75, 192, 192, 1)',
                    borderWidth: 1
                }]
            },
            options: {
                responsive: true,
                scales: {
                    y: { beginAtZero: true }
                }
            }
        });
    </script>

</body>
</html>
