<?php
require 'config.php';

if (LOGLEVEL === 'debug') {
	//var_dump('Aktueller API_PROVIDER:', API_PROVIDER);
}
function fetchExchangeRate($currency, $cacheFile, $cacheTime, &$apiError) {
	$response = null;
	$exchangeData = null;

	if (!file_exists($cacheFile) || (time() - filemtime($cacheFile)) >= $cacheTime) {
		if (API_PROVIDER === 'exchangerate-api') {
			$url = "https://api.exchangerate-api.com/v4/latest/USD";
		} elseif (API_PROVIDER === 'twelvedata') {
			$url = "https://api.twelvedata.com/exchange_rate?symbol=USD/EUR&apikey=" . API_KEY;
		} elseif (API_PROVIDER === 'alphavantage') {
			$url = "https://www.alphavantage.co/query?function=CURRENCY_EXCHANGE_RATE&from_currency=USD&to_currency=" . $currency . "&apikey=" . API_KEY;
		} else {
			$apiError = "Ungültiger API-Anbieter für Wechselkurse.";
			return [1, $response, $exchangeData];
		}

		$ch = curl_init($url);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		$response = curl_exec($ch);
		curl_close($ch);
		if (LOGLEVEL === 'debug') {

			//var_dump('API URL:', $url); // Debugging-Ausgabe
			//var_dump('API Response:', $response); // Debugging-Ausgabe
		}
		if ($response === false) {
			if (LOGLEVEL === 'debug') {

				var_dump('cURL-Fehler:', curl_error($ch)); // Debugging-Ausgabe
			}
			$apiError = 'Fehler beim Abrufen der Aktienkurse: ' . curl_error($ch);
			$apiError = 'Fehler beim Abrufen der Aktienkurse: Keine Antwort von der API.';
			return [[], null];
		}
		$exchangeData = json_decode($response, true);

		if (!$exchangeData || !isset($exchangeData['rates'][$currency])) {
			$apiError = "Fehler beim Abrufen des Wechselkurses.";
			return [1, $response, $exchangeData];
		}

		file_put_contents($cacheFile, json_encode($exchangeData, JSON_PRETTY_PRINT));
	} else {
		$exchangeData = json_decode(file_get_contents($cacheFile), true);
	}

	return [$exchangeData['rates'][$currency] ?? 1, $response, $exchangeData];
}

function fetchStockPrices($stocks, $cacheFile, $cacheTime, &$apiError) {
	$response = null;
	$data = null;

	if (!file_exists($cacheFile) || (time() - filemtime($cacheFile)) >= $cacheTime) {
		$stockData = [];
		foreach ($stocks as $symbol => $quantity) {
			if (API_PROVIDER === 'alphavantage') {
				$url = "https://www.alphavantage.co/query?function=TIME_SERIES_DAILY&symbol=$symbol&apikey=" . API_KEY;
			} elseif (API_PROVIDER === 'twelvedata') {
				$url = "https://api.twelvedata.com/time_series?symbol=$symbol&interval=1day&apikey=" . API_KEY;
			} else {
				$apiError = "Ungültiger API-Anbieter für Aktienkurse.";
				return [[], $data];
			}

			$ch = curl_init($url);
			curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
			$response = curl_exec($ch);
			curl_close($ch);
			$data = json_decode($response, true);
			if ($data === null) {
				$apiError = 'Fehler beim Dekodieren der API-Antwort: ' . json_last_error_msg();
				$apiError = 'Fehler beim Dekodieren der API-Antwort: ' . json_last_error_msg();
			}
			if ($data === null) {
				$apiError = 'Fehler beim Dekodieren der API-Antwort: ' . json_last_error_msg();
			}
			if (!isset($data["Time Series (Daily)"])) {
				$apiError = 'Fehler: Keine Kursdaten von der API erhalten.';
				return [[], $data];
			}
			$latestDate = array_key_first($data["Time Series (Daily)"]);
			$latestPrice = $data["Time Series (Daily)"][$latestDate]["4. close"] ?? 0;

			$stockData[$symbol] = $latestPrice;
		}
		file_put_contents($cacheFile, json_encode($stockData));
	} else {
		$stockData = json_decode(file_get_contents($cacheFile), true);
	}

	return [$stockData, $data];
}


