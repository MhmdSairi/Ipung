<?php
// HINDARI MENYIMPAN FILE INI DI DIREKTORI PUBLIK!

// Kredensial API
define('API_KEY', 'b8024f49-6d92-46ce-98c0-db76a6c9100b'); // Ganti dengan Kunci API Anda yang sebenarnya

// Kredensial XL API
define('BASE_API_URL', 'https://api.myxl.xlaxiata.co.id');
define('BASE_CIAM_URL', 'https://gede.ciam.xlaxiata.co.id');
define('BASIC_AUTH', 'OWZjOTdlZDEtNmEzMC00OGQ1LTk1MTYtNjBjNTNjZTNhMTM1OllEV21GNExKajlYSUt3UW56eTJlMmxiMHRKUWIyOW8z'); // Ganti dengan Basic Auth Anda yang sebenarnya
define('AX_DEVICE_ID', '92fb44c0804233eb4d9e29f838223a14');
define('AX_FP', 'YmQLy9ZiLLBFAEVcI4Dnw9+NJWZcdGoQyewxMF/9hbfk/8GbKBgtZxqdiiam8+m2lK31E/zJQ7kjuPXpB3EE8naYL0Q8+0WLhFV1WAPl9Eg=');
define('USER_AGENT', 'myXL / 8.6.0(1179); com.android.vending; (samsung; SM-N935F; SDK 33; Android 13)');
define('AES_KEY_ASCII', '5dccbf08920a5527');

// URL Layanan Kripto Eksternal (sesuai main.py)
define('XDATA_DECRYPT_URL', 'https://crypto.mashu.lol/api/decrypt');
define('XDATA_ENCRYPT_SIGN_URL', 'https://crypto.mashu.lol/api/encryptsign');
define('PAYMENT_SIGN_URL', 'https://crypto.mashu.lol/api/sign-payment');
define('AX_SIGN_URL', 'https://crypto.mashu.lol/api/sign-ax');

// Kode Paket Default (sesuai main.py)
define('PACKAGE_FAMILY_CODE', '08a3b1e6-8e78-4e45-a540-b40f06871cfe'); // XUT

// Lokasi file refresh token
define('REFRESH_TOKENS_FILE', __DIR__ . '/refresh-tokens.json');

?>
