<?php
// Konfigurationsdatei für das Aktienportfolio

// API-Anbieter (mögliche Werte: 'alphavantage', 'twelvedata', 'exchangerate-api')
if (!defined('API_PROVIDER_EXCHANGE_RATE')) {
    define('API_PROVIDER_EXCHANGE_RATE', 'exchangerate-api');
}

// API-Anbieter (mögliche Werte: 'alphavantage', 'twelvedata')
if (!defined('API_PROVIDER_STOCK_PRICES')) {
    define('API_PROVIDER_STOCK_PRICES', 'alphavantage');
}


// API-Schlüssel für den gewählten Anbieter
if (!defined('API_KEY')) {
    define('API_KEY', getenv('API_KEY') ?: 'H0MFBK7TEAD3T40Q');
}

// Währung für die Berechnungen
if (!defined('CURRENCY')) {
    define('CURRENCY', 'EUR');
}

// Loglevel (mögliche Werte: 'debug', 'error', 'none')
if (!defined('LOGLEVEL')) {
    define('LOGLEVEL', 'debug');
}

// Cache-Pfade
if (!defined('CACHE_DIR')) {
    define('CACHE_DIR', __DIR__ . '/cache/');
}
if (!defined('EXCHANGE_CACHE_FILE')) {
	define('EXCHANGE_CACHE_FILE', CACHE_DIR . 'exchange_rate.json');
}
if (!defined('STOCK_CACHE_FILE')) {
	define('STOCK_CACHE_FILE', CACHE_DIR . 'stock_data.json');
}

// Cache-Zeit in Sekunden (1 Stunde)
if (!defined('CACHE_TIME')) {
    define('CACHE_TIME', getenv('CACHE_TIME') ?: 3600);
}

// Erstelle Cache-Verzeichnis, falls nicht vorhanden
if (!file_exists(CACHE_DIR)) {
    mkdir(CACHE_DIR, 0755, true);
}

require 'stocks_config.php';

$stocks = $STOCKS_LIST; // Ausgelagerte Konfigurationsdatei

