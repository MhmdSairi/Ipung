<?php
// index.php

// 1. Inisialisasi dan Konfigurasi
session_start();
ini_set('display_errors', 1);
error_reporting(E_ALL);

// -- Ambil Konfigurasi dari File .env --
// Karena PHP tidak otomatis membaca .env, kita simulasikan pemuatan variabel
// CATATAN: Dalam aplikasi nyata, gunakan library PHP-dotenv!
$env_config = [
    // Ambil dari file .env yang Anda berikan
    "API_KEY" => "b8024f49-6d92-46ce-98c0-db76a6c9100b",
    "BASE_CIAM_URL" => "https://gede.ciam.xlaxiata.co.id",
    "BASE_API_URL" => "https://api.myxl.xlaxiata.co.id",
    "BASIC_AUTH" => "OWZjOTdlZDEtNmEzMC00OGQ1LTk1MTYtNjBjNTNjZTNhMTM1OllEV21GNExKajlYSUt3UW56eTJlMmxiMHRKUWIyOW8z",
    "AX_DEVICE_ID" => "92fb44c0804233eb4d9e29f838223a14",
    "AX_FP" => "YmQLy9ZiLLBFAEVcI4Dnw9+NJWZcdGoQyewxMF/9hbfk/8GbKBgtZxqdiiam8+m2lK31E/zJQ7kjuPXpB3EE8naYL0Q8+0WLhFV1WAPl9Eg=",
    "USER_AGENT" => "myXL / 8.6.0(1179); com.android.vending; (samsung; SM-N935F; SDK 33; Android 13)",
    // URL untuk Layanan Kripto Pihak Ketiga
    "AX_SIGN_URL" => "https://crypto.mashu.lol/api/sign-ax", 
    "XDATA_ENCRYPT_SIGN_URL" => "https://crypto.mashu.lol/api/encryptsign",
    "XDATA_DECRYPT_URL" => "https://crypto.mashu.lol/api/decrypt",
    "XUT_FAMILY_CODE" => "08a3b1e6-8e78-4e45-a540-b40f06871cfe"
];

// -- Konstanta Status Aplikasi --
const STATE_PHONE_INPUT = 'phone';
const STATE_OTP_INPUT = 'otp';
const STATE_MAIN_MENU = 'menu';
const STATE_BUY_PACKAGES = 'buy_packages';

// -- Inisialisasi Status --
$current_state = STATE_PHONE_INPUT;
$error_message = '';
$success_message = '';
$output_message = '';
$view = $_GET['view'] ?? '';

// Tentukan status berdasarkan sesi
if (isset($_SESSION['is_logged_in']) && $_SESSION['is_logged_in'] === true) {
    $current_state = STATE_MAIN_MENU;
} elseif (isset($_SESSION['phone_number']) && !empty($_SESSION['phone_number'])) {
    $current_state = STATE_OTP_INPUT;
}


// --- FUNGSI UTILITY PHP (Diambil dari main.py) ---

/**
 * Mereplikasi format timestamp yang digunakan oleh API (java_like_timestamp)
 * Contoh: "2023-10-20T12:34:56.78+07:00"
 */
function java_like_timestamp() {
    $now = new DateTime('now', new DateTimeZone('Asia/Jakarta'));
    $ms = floor($now->format('u') / 10000); // Ambil 2 digit milidetik
    $tz = $now->format('O'); // Format timezone (+0700)
    $tz_colon = substr($tz, 0, 3) . ':' . substr($tz, 3); // Tambahkan kolon
    return $now->format("Y-m-d\TH:i:s.{$ms}") . $tz_colon;
}

/**
 * Mereplikasi format timestamp ts_gmt7_without_colon
 */
function ts_gmt7_without_colon() {
    $dt = new DateTime('now', new DateTimeZone('Asia/Jakarta'));
    $millis = floor($dt->format('u') / 1000); // Ambil 3 digit milidetik
    $tz = $dt->format('O'); // Format timezone (+0700)
    return $dt->format("Y-m-d\TH:i:s.{$millis}") . $tz;
}

/**
 * FUNGSI API: Menghasilkan Ax-Api-Signature.
 * Fungsi ini menggunakan layanan pihak ketiga, seperti yang ada di main.py.
 */
function ax_api_signature(string $api_key, string $ts_for_sign, string $contact, string $code, string $contact_type): ?string {
    global $env_config;
    
    $headers = [
        "Content-Type: application/json",
        "x-api-key: {$api_key}",
    ];
    
    $request_body = json_encode([
        "ts_for_sign" => $ts_for_sign,
        "contact" => $contact,
        "code" => $code,
        "contact_type" => $contact_type
    ]);
    
    $ch = curl_init($env_config["AX_SIGN_URL"]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $request_body);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($http_code == 200) {
        $data = json_decode($response, true);
        return $data['ax_signature'] ?? null;
    }
    
    error_log("Signature generation failed: HTTP {$http_code} - {$response}");
    return null;
}

/**
 * FUNGSI API: Meminta kode OTP.
 * Diterjemahkan dari get_otp di main.py.
 */
function get_otp_php(string $contact): ?string {
    global $env_config;
    
    if (!preg_match('/^628[0-9]{7,12}$/', $contact)) {
        throw new Exception("Format nomor XL tidak valid. Contoh: 6281234567890.");
    }
    
    $url = $env_config["BASE_CIAM_URL"] . "/realms/xl-ciam/auth/otp";
    $querystring = http_build_query([
        "contact" => $contact,
        "contactType" => "SMS",
        "alternateContact" => "false"
    ]);
    
    $now = new DateTime('now', new DateTimeZone('Asia/Jakarta'));
    $ax_request_at = java_like_timestamp();
    $ax_request_id = uniqid();
    
    $headers = [
        "Accept-Encoding: gzip, deflate, br",
        "Authorization: Basic {$env_config['BASIC_AUTH']}",
        "Ax-Device-Id: {$env_config['AX_DEVICE_ID']}",
        "Ax-Fingerprint: {$env_config['AX_FP']}",
        "Ax-Request-At: {$ax_request_at}",
        "Ax-Request-Device: samsung",
        "Ax-Request-Device-Model: SM-N935F",
        "Ax-Request-Id: {$ax_request_id}",
        "Ax-Substype: PREPAID",
        "Content-Type: application/json",
        "Host: " . parse_url($env_config["BASE_CIAM_URL"], PHP_URL_HOST),
        "User-Agent: {$env_config['USER_AGENT']}"
    ];
    
    $ch = curl_init("{$url}?{$querystring}");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($response === false) {
        throw new Exception("Network error requesting OTP.");
    }
    
    $json_body = json_decode($response, true);
    
    if ($http_code == 429) {
        throw new Exception("Terlalu banyak permintaan (Too many requests). Harap tunggu sejenak.");
    }
    
    if (!isset($json_body["subscriber_id"])) {
        $error_msg = $json_body['error'] ?? 'Gagal meminta OTP.';
        $error_desc = $json_body['error_description'] ?? '';
        
        if (stripos($error_msg, "reach limit") !== false || stripos($error_desc, "reach limit") !== false) {
            throw new Exception("Batas permintaan OTP tercapai. Harap tunggu sebelum meminta lagi.");
        }
        
        throw new Exception("Gagal meminta OTP: {$error_msg}");
    }
    
    return $json_body["subscriber_id"];
}

/**
 * FUNGSI API: Mengirim kode OTP untuk login.
 * Diterjemahkan dari submit_otp di main.py.
 */
function submit_otp_php(string $contact, string $code): ?array {
    global $env_config;
    
    if (!preg_match('/^\d{6}$/', $code)) {
        throw new Exception("Format kode OTP tidak valid.");
    }

    $url = $env_config["BASE_CIAM_URL"] . "/realms/xl-ciam/protocol/openid-connect/token";

    // 1. Hitung Signature
    $now_gmt7 = new DateTime('now', new DateTimeZone('Asia/Jakarta'));
    $ts_for_sign = ts_gmt7_without_colon();
    $ts_header = (new DateTime('now', new DateTimeZone('Asia/Jakarta')))->modify('-5 minutes')->format('Y-m-d\TH:i:s.000+0700'); 

    $signature = ax_api_signature($env_config['API_KEY'], $ts_for_sign, $contact, $code, "SMS");
    
    if (!$signature) {
        throw new Exception("Gagal membuat tandatangan API.");
    }

    // 2. Kirim Permintaan Token
    $payload = "contactType=SMS&code={$code}&grant_type=password&contact={$contact}&scope=openid";

    $headers = [
        "Accept-Encoding: gzip, deflate, br",
        "Authorization: Basic {$env_config['BASIC_AUTH']}",
        "Ax-Api-Signature: {$signature}",
        "Ax-Device-Id: {$env_config['AX_DEVICE_ID']}",
        "Ax-Fingerprint: {$env_config['AX_FP']}",
        "Ax-Request-At: {$ts_header}",
        "Ax-Request-Device: samsung",
        "Ax-Request-Device-Model: SM-N935F",
        "Ax-Request-Id: " . uniqid(),
        "Ax-Substype: PREPAID",
        "Content-Type: application/x-www-form-urlencoded",
        "User-Agent: {$env_config['USER_AGENT']}",
    ];

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($response === false) {
        throw new Exception("Network error during login.");
    }

    $json_body = json_decode($response, true);
    
    if ($http_code == 429) {
        throw new Exception("Terlalu banyak permintaan (Too many requests). Harap tunggu sejenak.");
    }
    
    if (isset($json_body["error"])) {
        $error_msg = $json_body['error_description'] ?? 'Login Gagal.';
        
        if (stripos($error_msg, "reach limit") !== false) {
            throw new Exception("Batas verifikasi OTP tercapai. Harap tunggu sebelum mencoba lagi.");
        }
        
        throw new Exception("Login Gagal: {$error_msg}");
    }
    
    if (!isset($json_body["refresh_token"])) {
        throw new Exception("Login Gagal. Server tidak mengembalikan token.");
    }
    
    return $json_body; // Berisi access_token, refresh_token, id_token, dll.
}

/**
 * FUNGSI MOCK: Simulasi untuk get_balance.
 * FUNGSI NYATA MEMBUTUHKAN ENKRIPSI DAN DEKRIPSI KODE.
 */
function mock_get_balance($id_token): ?array {
    // --- DI SINI ANDA PERLU MENGUBAH FUNGSI PYTHON get_balance() KE PHP ---
    // Melibatkan: send_api_request (enkripsi/dekripsi), get_balance (curl)
    // KARENA KOMPLEKSITAS, KITA GUNAKAN DATA SIMULASI:
    if (empty($id_token)) return null;
    return [
        "remaining" => 50000,
        "expired_at" => (time() + (365 * 24 * 3600)) // 1 tahun dari sekarang
    ];
}

/**
 * FUNGSI MOCK: Simulasi untuk mendapatkan daftar paket.
 * FUNGSI NYATA MEMBUTUHKAN ENKRIPSI DAN DEKRIPSI KODE.
 */
function mock_get_packages($tokens, $family_code): ?array {
    // --- DI SINI ANDA PERLU MENGUBAH FUNGSI PYTHON get_family() KE PHP ---
    // KARENA KOMPLEKSITAS, KITA GUNAKAN DATA SIMULASI:
    if (empty($tokens) || $family_code !== '08a3b1e6-8e78-4e45-a540-b40f06871cfe') return null;
    
    return [
        [
            "name" => "🔥 Unli Turbo Vidio",
            "price" => 15000,
            "code" => "PKG_XUT_VIDIO"
        ],
        [
            "name" => "🌐 Unli Turbo Iflix",
            "price" => 10000,
            "code" => "PKG_XUT_IFLIX"
        ],
        [
            "name" => "🚀 Kuota 2GB Harian",
            "price" => 30000,
            "code" => "PKG_KUOTA_2GB"
        ],
    ];
}

/**
 * FUNGSI MOCK: Simulasi untuk pembelian paket.
 * FUNGSI NYATA MEMBUTUHKAN ENKRIPSI, PENANDATANGANAN, DAN API GATEWAY.
 */
function mock_purchase_package($tokens, $package_code, $amount = null): array {
    // --- DI SINI ANDA PERLU MENGUBAH FUNGSI PYTHON purchase_package_with_balance() KE PHP ---
    // KARENA KOMPLEKSITAS, KITA GUNAKAN LOGIKA SIMULASI:
    if (empty($tokens)) {
        return ["success" => false, "error" => "Tidak ada token pengguna aktif."];
    }
    
    // Asumsi harga dari cache atau lookup (simulasi)
    $package_name = match($package_code) {
        "PKG_XUT_VIDIO" => "🔥 Unli Turbo Vidio",
        "PKG_XUT_IFLIX" => "🌐 Unli Turbo Iflix",
        "PKG_KUOTA_2GB" => "🚀 Kuota 2GB Harian",
        default => "Paket Tidak Dikenal"
    };

    if ($amount === 0) $amount = 15000; // Asumsi harga default jika 0

    // Logika gagal simulasi (misal, jika kode tertentu gagal)
    if ($package_code === "PKG_FAIL_SIMULASI") {
        return ["success" => false, "error" => "SIMULASI GAGAL: Saldo tidak mencukupi atau paket tidak valid."];
    }

    return [
        "success" => true, 
        "data" => [
            "message" => "Pembelian paket {$package_name} berhasil!",
            "amount_paid" => $amount
        ]
    ];
}

// --- FUNGSI PENGELOLAAN SESI & TOKEN ---

function save_refresh_token(int $number, string $refresh_token) {
    $filename = 'refresh-tokens.json';
    $tokens = [];
    if (file_exists($filename)) {
        $tokens = json_decode(file_get_contents($filename), true);
        if (!is_array($tokens)) $tokens = [];
    }
    
    $found = false;
    foreach ($tokens as &$token) {
        if ($token['number'] == $number) {
            $token['refresh_token'] = $refresh_token;
            $found = true;
            break;
        }
    }
    unset($token); 
    
    if (!$found) {
        $tokens[] = ["number" => $number, "refresh_token" => $refresh_token];
    }
    
    file_put_contents($filename, json_encode($tokens, JSON_PRETTY_PRINT));
}

function get_active_user_tokens(): ?array {
    // Memeriksa sesi login dan mengembalikan data token
    if (isset($_SESSION['is_logged_in']) && $_SESSION['is_logged_in'] === true) {
        return [
            'number' => $_SESSION['phone_number'],
            // Di sini harusnya ada logika get_new_token dari refresh_token
            // Tapi kita gunakan token yang disimpan dari login
            'id_token' => $_SESSION['id_token'], 
            'access_token' => $_SESSION['access_token']
        ];
    }
    return null;
}

// --- FUNGSI UTAMA HANDLER WEB ---

// 2. Tangani Aksi POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    try {
        if ($action === 'submit_phone') {
            $phone_number = $_POST['phone_number'] ?? '';
            
            // Validasi format nomor XL
            if (!preg_match('/^628[0-9]{7,12}$/', $phone_number)) {
                throw new Exception("Format nomor XL tidak valid. Contoh: 6281234567890.");
            }
            
            // Panggil API untuk meminta OTP
            get_otp_php($phone_number); 

            // Simpan data di sesi
            $_SESSION['phone_number'] = $phone_number;
            $current_state = STATE_OTP_INPUT;
            $success_message = "Kode OTP telah dikirim ke nomor {$phone_number}.";
            
        } elseif ($action === 'submit_otp') {
            $otp_code = $_POST['otp_code'] ?? '';
            $phone_number = $_SESSION['phone_number'] ?? '';

            // Validasi OTP
            if (!preg_match('/^\d{6}$/', $otp_code)) {
                throw new Exception("Kode OTP harus 6 digit angka.");
            }
            
            // Panggil API untuk verifikasi OTP
            $tokens = submit_otp_php($phone_number, $otp_code);

            if (!$tokens || !isset($tokens['refresh_token'])) {
                throw new Exception("Verifikasi OTP gagal. Cek kode Anda.");
            }

            // Simpan token ke file JSON dan sesi
            save_refresh_token(intval($phone_number), $tokens['refresh_token']);
            $_SESSION['is_logged_in'] = true;
            $_SESSION['id_token'] = $tokens['id_token'];
            $_SESSION['access_token'] = $tokens['access_token'];
            
            $current_state = STATE_MAIN_MENU;
            $success_message = "Login Berhasil!";
            
        } elseif ($action === 'logout') {
            // Hapus sesi
            unset($_SESSION['is_logged_in']);
            unset($_SESSION['phone_number']);
            unset($_SESSION['id_token']);
            unset($_SESSION['access_token']);
            $current_state = STATE_PHONE_INPUT;
            $success_message = "Anda telah logout.";
            header('Location: index.php'); // Redirect untuk menghapus data POST
            exit;
        } elseif ($action === 'buy_package' && $current_state === STATE_MAIN_MENU) {
            // Logika Pembelian Paket
            $package_code = $_POST['package_code'] ?? '';
            $amount = intval($_POST['amount'] ?? 0); // Ambil jumlah yang dibayar
            
            $user_tokens = get_active_user_tokens();
            if (!$user_tokens) {
                 throw new Exception("Sesi berakhir. Harap login kembali.");
            }
            
            // MOCK PEMBELIAN
            $result = mock_purchase_package($user_tokens, $package_code, $amount);
            
            if ($result['success']) {
                $success_message = "✅ Pembelian Berhasil! " . ($result['data']['message'] ?? '');
                $output_message = "Anda membayar sebesar Rp " . number_format($result['data']['amount_paid'], 0, ',', '.') . ". Cek aplikasi XL Anda untuk rincian kuota.";
            } else {
                $error_message = "❌ Pembelian Gagal: " . ($result['error'] ?? 'Kesalahan tidak diketahui.');
            }
            // Tetap di menu utama setelah pembelian
            $current_state = STATE_MAIN_MENU; 
        }

    } catch (Exception $e) {
        $error_message = $e->getMessage();
        // Pertahankan status saat ini jika terjadi kesalahan
        if ($action === 'submit_otp' && isset($_SESSION['phone_number'])) {
            $current_state = STATE_OTP_INPUT;
        } else {
            $current_state = STATE_PHONE_INPUT; // Kembali ke input telepon jika gagal
        }
    }
}

// 3. Logika Navigasi GET/Tampilan
if ($current_state === STATE_MAIN_MENU) {
    if ($view === 'buy') {
        $current_state = STATE_BUY_PACKAGES;
    }
}

// 4. Mulai Output HTML
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>XL Panel - Login & Isi Paket</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>

    <div class="panel-container">
        <?php if ($error_message): ?>
            <div class="alert alert-error">🚨 <?php echo htmlspecialchars($error_message); ?></div>
        <?php endif; ?>
        <?php if ($success_message): ?>
            <div class="alert alert-success">👍 <?php echo htmlspecialchars($success_message); ?></div>
        <?php endif; ?>

        <?php if ($current_state === STATE_PHONE_INPUT): ?>
            <h2>📱 Login Akun XL</h2>
            <form method="POST">
                <div class="input-group">
                    <label for="phone_number">Nomor XL (Contoh: 6281234567890)</label>
                    <input type="number" id="phone_number" name="phone_number" placeholder="628..." required>
                </div>
                <input type="hidden" name="action" value="submit_phone">
                <button type="submit" class="btn-primary">Kirim OTP</button>
            </form>

        <?php elseif ($current_state === STATE_OTP_INPUT): ?>
            <h2>🔑 Verifikasi OTP</h2>
            <p>Masukkan kode 6 digit OTP yang dikirim ke nomor <span style="font-weight: bold;"><?php echo htmlspecialchars($_SESSION['phone_number'] ?? 'Anda'); ?></span>.</p>
            <form method="POST">
                <div class="input-group">
                    <label for="otp_code">Kode OTP</label>
                    <input type="number" id="otp_code" name="otp_code" required maxlength="6" placeholder="Kode 6 digit">
                </div>
                <input type="hidden" name="action" value="submit_otp">
                <button type="submit" class="btn-primary">Login</button>
            </form>

        <?php elseif ($current_state === STATE_MAIN_MENU): ?>
            <h2>📋 Dashboard Akun</h2>
            
            <?php 
                $user_tokens = get_active_user_tokens();
                $balance_data = mock_get_balance($user_tokens['id_token']); 
                
                $number = $user_tokens['number'];
                $balance = $balance_data['remaining'] ?? 'N/A';
                $expired_at = $balance_data['expired_at'] ?? 'N/A';
                
                if ($expired_at !== 'N/A') {
                    $expired_at_dt = date("d-m-Y H:i:s", $expired_at);
                } else {
                    $expired_at_dt = 'N/A';
                }
            ?>
            <div class="account-info">
                <strong>Informasi Akun:</strong>
                <p>Nomor: <span style="color: #007bff; font-weight: bold;"><?php echo htmlspecialchars(