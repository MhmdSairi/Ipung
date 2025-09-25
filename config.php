<?php
// config.php

// Memulai Sesi untuk mengelola state pengguna
session_start();

// --- Konfigurasi API (Diambil dari main.py) ---
define('DB_PATH', 'users.db');

// Konstanta API yang kritis (DIJAGA TIDAK BERUBAH DARI SUMBER PYTHON)
define('API_KEY', 'vT8tINqHaOxXbGE7eOWAhA==');
define('XDATA_DECRYPT_URL', 'https://crypto.mashu.lol/api/decrypt');
define('XDATA_ENCRYPT_SIGN_URL', 'https://crypto.mashu.lol/api/encryptsign');
define('PAYMENT_SIGN_URL', 'https://crypto.mashu.lol/api/sign-payment');
define('AX_SIGN_URL', 'https://crypto.mashu.lol/api/sign-ax');
define('BOUNTY_SIGN_URL', 'https://crypto.mashu.lol/api/sign-bounty');
define('BASE_API_URL', 'https://api.myxl.xlaxiata.co.id');
define('BASE_CIAM_URL', 'https://gede.ciam.xlaxiata.co.id');
define('BASIC_AUTH', 'OWZjOTdlZDEtNmEzMC00OGQ1LTk1MTYtNjBjNTNjZTNhMTM1OllEV21GNExKajlYSUt3UW56eTJlMmxiMHRKUWIyOW8z');
define('AX_DEVICE_ID', '92fb44c0804233eb4d9e29f838223a14');
define('AX_FP', 'YmQLy9ZiLLBFAEVcI4Dnw9+NJWZcdGoQyewxMF/9hbfk/8GbKBgtZxqdiiam8+m2lK31E/zJQ7kjuPXpB3EE8naYL0Q8+0WLhFV1WAPl9Eg=');
define('USER_AGENT', 'myXL / 8.6.0(1179); com.android.vending; (samsung; SM-N935F; SDK 33; Android 13)');
define('PACKAGE_FAMILY_CODE', '08a3b1e6-8e78-4e45-a540-b40f06871cfe');
define('LOG_FILE', 'user_activity.log');
// --- Akhir Konfigurasi API ---


/**
 * Inisialisasi Database SQLite
 */
function init_db() {
    try {
        $pdo = new PDO('sqlite:' . DB_PATH);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        [span_0](start_span)// Struktur tabel users dipertahankan[span_0](end_span)
        $pdo->exec("CREATE TABLE IF NOT EXISTS users (
            user_id INTEGER PRIMARY KEY, 
            timestamp DATETIME DEFAULT CURRENT_TIMESTAMP
        )");
        return $pdo;
    } catch (PDOException $e) {
        error_log("Database initialization error: " . $e->getMessage());
        die("Database connection failed.");
    }
}

// Global cache untuk paket agar dapat diakses di berbagai request
if (!isset($_SESSION['xut_packages_cache'])) {
    $_SESSION['xut_packages_cache'] = [];
}
if (!isset($_SESSION['family_packages_cache'])) {
    $_SESSION['family_packages_cache'] = [];
}

// Inisialisasi DB saat script dijalankan (jika diperlukan)
init_db();
?>
