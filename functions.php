<?php
// functions.php

// Sertakan konfigurasi
require_once 'config.php';

// --- FUNGSI UTILITY (DIAMBIL DARI main.py) ---
function java_like_timestamp() {
    // Menghasilkan timestamp dalam format YYYY-MM-DDTHH:mm:ss.SS+HH:MM
    $now = new DateTime("now", new DateTimeZone('Asia/Jakarta')); // Asumsi GMT+7
    $ms = floor(microtime(true) * 100) % 100;
    $tz_colon = $now->format("P");
    return $now->format("Y-m-d\TH:i:s.") . sprintf('%02d', $ms) . $tz_colon;
}

function ts_gmt7_without_colon() {
    // Menghasilkan timestamp dalam format YYYY-MM-DDTHH:mm:ss.ms+HHMM
    $dt = new DateTime("now", new DateTimeZone('Asia/Jakarta')); // GMT+7
    $millis = floor(microtime(true) * 1000) % 1000;
    $tz = $dt->format("P");
    return $dt->format("Y-m-d\TH:i:s.") . sprintf('%03d', $millis) . str_replace(':', '', $tz);
}

function validate_contact($contact) {
    // Memastikan format nomor XL sesuai (628 + 7-11 digit)
    return preg_match('/^628[0-9]{7,11}$/', $contact);
}

function user_log($type, $user_id, $username, $number, $message = '') {
    // Fungsi logging yang meniru format user_activity.log
    $time = date("Y-m-d H:i:s");
    $user_part = "User: $user_id ($username), XL Number: $number";
    $log_entry = "$time,000 - $type - $user_part" . ($message ? ", $message" : "") . "\n";
    file_put_contents(LOG_FILE, $log_entry, FILE_APPEND);
}

function insert_user_id($user_id) {
    // Menyimpan ID pengguna ke users.db
    $pdo = init_db();
    try {
        [span_1](start_span)$stmt = $pdo->prepare("INSERT OR IGNORE INTO users (user_id) VALUES (?)");[span_1](end_span)
        $stmt->execute([$user_id]);
        return $stmt->rowCount() > 0; // True jika user baru dimasukkan
    } catch (PDOException $e) {
        error_log("Error inserting user ID: " . $e->getMessage());
        return false;
    }
}


// --- FUNGSI WRAPPER API (MENGGUNAKAN cURL) ---

function api_request_curl($url, $method, $headers, $data = null) {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    
    // Ubah array header menjadi format yang diterima cURL: ['Header: Value']
    $curl_headers = [];
    foreach ($headers as $key => $value) {
        $curl_headers[] = "$key: $value";
    }
    curl_setopt($ch, CURLOPT_HTTPHEADER, $curl_headers);
    
    if ($data) {
        if ($method === 'POST' || $method === 'PUT' || $method === 'DELETE') {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
        } elseif ($method === 'GET' && is_array($data)) {
            $url .= '?' . http_build_query($data);
        }
    }
    
    $response = curl_exec($ch);
    $status_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    $json_body = json_decode($response, true);
    
    if (curl_errno($ch)) {
        return ['error' => 'cURL Error: ' . curl_error($ch), 'status_code' => 0];
    }
    
    return ['body' => $json_body, 'status_code' => $status_code];
}

// Fungsi Kriptografi/Signatur (Menggantikan requests.post ke layanan eksternal)
function encryptsign_xdata($id_token, $method, $path, $payload) {
    $headers = [
        "Content-Type" => "application/json",
        "x-api-key" => API_KEY,
    ];
    $request_body = [
        "id_token" => $id_token,
        "method" => $method,
        "path" => $path,
        "body" => $payload
    ];
    
    $response = api_request_curl(XDATA_ENCRYPT_SIGN_URL, "POST", $headers, json_encode($request_body));
    
    if ($response['status_code'] === 200) {
        return $response['body'];
    } else {
        throw new Exception("Encryption failed: " . ($response['body']['error'] ?? 'Unknown Error'));
    }
}

function decrypt_xdata($encrypted_payload) {
    if (!isset($encrypted_payload['xdata']) || !isset($encrypted_payload['xtime'])) {
        throw new ValueError("Invalid encrypted data format.");
    }
    
    $headers = [
        "Content-Type" => "application/json",
        "x-api-key" => API_KEY,
    ];
    $response = api_request_curl(XDATA_DECRYPT_URL, "POST", $headers, json_encode($encrypted_payload));
    
    if ($response['status_code'] === 200) {
        return json_decode($response['body']['plaintext'], true);
    } else {
        throw new Exception("Decryption failed: " . ($response['body']['error'] ?? 'Unknown Error'));
    }
}

function get_x_signature_payment($access_token, $ts_to_sign, $package_code, $token_payment, $payment_method) {
    $headers = [
        "Content-Type" => "application/json",
        "x-api-key" => API_KEY,
    ];
    $request_body = [
        "access_token" => $access_token,
        "sig_time_sec" => $ts_to_sign,
        "package_code" => $package_code,
        "token_payment" => $token_payment,
        "payment_method" => $payment_method
    ];
    $response = api_request_curl(PAYMENT_SIGN_URL, "POST", $headers, json_encode($request_body));
    if ($response['status_code'] === 200) {
        return $response['body']['x_signature'];
    } else {
        throw new Exception("Signature generation failed: " . ($response['body']['error'] ?? 'Unknown Error'));
    }
}

function ax_api_signature($ts_for_sign, $contact, $code, $contact_type) {
    $headers = [
        "Content-Type" => "application/json",
        "x-api-key" => API_KEY,
    ];
    $request_body = [
        "ts_for_sign" => $ts_for_sign,
        "contact" => $contact,
        "code" => $code,
        "contact_type" => $contact_type
    ];
    
    $response = api_request_curl(AX_SIGN_URL, "POST", $headers, json_encode($request_body));
    if ($response['status_code'] === 200) {
        return $response['body']['ax_signature'];
    } else {
        throw new Exception("Signature generation failed: " . ($response['body']['error'] ?? 'Unknown Error'));
    }
}
// --- AKHIR FUNGSI KRIPTOGRAFI/SIGNATUR ---


// --- FUNGSI AUTENTIKASI ---

function get_otp_request($contact) {
    if (!validate_contact($contact)) {
        throw new Exception("Invalid number format.");
    }
    
    $url = BASE_CIAM_URL . "/realms/xl-ciam/auth/otp";
    $querystring = http_build_query([
        "contact" => $contact,
        "contactType" => "SMS",
        "alternateContact" => "false"
    ]);
    $url .= '?' . $querystring;

    $headers = [
        "Authorization" => "Basic " . BASIC_AUTH,
        "Ax-Device-Id" => AX_DEVICE_ID,
        "Ax-Fingerprint" => AX_FP,
        "Ax-Request-At" => java_like_timestamp(),
        "Ax-Request-Id" => uniqid(),
        "Ax-Substype" => "PREPAID",
        "User-Agent" => USER_AGENT
    ];

    $response = api_request_curl($url, "GET", $headers);
    
    if ($response['status_code'] !== 200 || !isset($response['body']['subscriber_id'])) {
        $error_msg = $response['body']['error'] ?? 'Unknown API error';
        if (strpos(strtolower($error_msg), 'reach limit') !== false || $response['status_code'] == 429) {
            throw new Exception("OTP request limit reached. Please wait before requesting another OTP.");
        }
        throw new Exception("Failed to request OTP: " . $error_msg);
    }
    
    return $response['body']['subscriber_id'];
}

function submit_otp_request($contact, $code) {
    if (!validate_contact($contact) || !preg_match('/^\d{6}$/', $code)) {
        throw new Exception("Invalid number or OTP format.");
    }
    
    $url = BASE_CIAM_URL . "/realms/xl-ciam/protocol/openid-connect/token";

    $ts_for_sign = ts_gmt7_without_colon();
    $signature = ax_api_signature($ts_for_sign, $contact, $code, "SMS");

    $payload = "contactType=SMS&code=$code&grant_type=password&contact=$contact&scope=openid";

    $headers = [
        "Authorization" => "Basic " . BASIC_AUTH,
        "Ax-Api-Signature" => $signature,
        "Ax-Device-Id" => AX_DEVICE_ID,
        "Ax-Fingerprint" => AX_FP,
        "Ax-Request-At" => ts_gmt7_without_colon(),
        "Ax-Request-Id" => uniqid(),
        "Ax-Substype" => "PREPAID",
        "Content-Type" => "application/x-www-form-urlencoded",
        "User-Agent" => USER_AGENT,
    ];

    $response = api_request_curl($url, "POST", $headers, $payload);

    if ($response['status_code'] !== 200 || isset($response['body']['error'])) {
        $error_msg = $response['body']['error_description'] ?? 'Unknown API error';
        if (strpos(strtolower($error_msg), 'reach limit') !== false || $response['status_code'] == 429) {
            throw new Exception("OTP verification limit reached. Please wait before trying again.");
        }
        throw new Exception("Failed to login: " . $error_msg);
    }
    
    // Perilaku 'AuthInstance::set_active_user' diimplementasikan di index.php
    return $response['body']; // Mengandung access_token, id_token, refresh_token
}

function refresh_token_request($refresh_token) {
    // Fungsi untuk mendapatkan token baru menggunakan refresh_token
    $url = BASE_CIAM_URL . "/realms/xl-ciam/protocol/openid-connect/token";

    $headers = [
        "Authorization" => "Basic " . BASIC_AUTH,
        "Ax-Device-Id" => AX_DEVICE_ID,
        "Ax-Fingerprint" => AX_FP,
        "User-Agent" => USER_AGENT,
        "Content-Type" => "application/x-www-form-urlencoded"
    ];

    $data = http_build_query([
        "grant_type" => "refresh_token",
        "refresh_token" => $refresh_token
    ]);

    $response = api_request_curl($url, "POST", $headers, $data);
    
    if ($response['status_code'] !== 200) {
        return null;
    }
    
    $body = $response['body'];
    if (!isset($body["id_token"]) || isset($body["error"])) {
        return null;
    }
    
    return $body;
}

// --- MANAJEMEN SESI PENGGUNA (Menggantikan AuthInstance) ---

function get_tokens_from_session() {
    // Mendapatkan token baru menggunakan refresh token dari sesi aktif
    $number = $_SESSION['active_user']['number'] ?? null;
    $refresh_token = null;

    // Cari refresh token di file JSON (menggantikan AuthInstance::get_user_tokens)
    if (file_exists("refresh-tokens.json")) {
        $refresh_tokens = json_decode(file_get_contents("refresh-tokens.json"), true);
        $rt_entry = array_filter($refresh_tokens, function($rt) use ($number) {
            return $rt['number'] == $number;
        });
        $rt_entry = reset($rt_entry);
        $refresh_token = $rt_entry['refresh_token'] ?? null;
    }
    
    if (!$refresh_token) {
        return null;
    }
    
    $tokens = refresh_token_request($refresh_token);
    
    if (!$tokens) {
        // Jika gagal refresh, hapus token dari JSON (menggantikan AuthInstance::remove_refresh_token)
        if (file_exists("refresh-tokens.json")) {
            $refresh_tokens = array_filter($refresh_tokens, function($rt) use ($number) {
                return $rt['number'] != $number;
            });
            file_put_contents("refresh-tokens.json", json_encode($refresh_tokens, JSON_PRETTY_PRINT));
        }
        return null;
    }
    
    // Update tokens di sesi
    $_SESSION['active_user']['tokens'] = $tokens;
    return $tokens;
}

// --- FUNGSI API DATA/TRANSAKSI ---

function send_api_request($path, $payload_dict, $id_token, $method = "POST") {
    // Proses enkripsi dan penandatanganan payload (menggunakan layanan eksternal)
    $encrypted_payload = encryptsign_xdata($id_token, $method, $path, $payload_dict);
    
    $xtime = $encrypted_payload["encrypted_body"]["xtime"];
    $sig_time_sec = floor($xtime / 1000);
    
    $body = $encrypted_payload["encrypted_body"];
    $x_sig = $encrypted_payload["x_signature"];
    
    $headers = [
        "host" => BASE_API_URL,
        "content-type" => "application/json; charset=utf-8",
        "user-agent" => USER_AGENT,
        "x-api-key" => API_KEY,
        "authorization" => "Bearer $id_token",
        "x-hv" => "v3",
        "x-signature-time" => strval($sig_time_sec),
        "x-signature" => $x_sig,
        "x-request-id" => uniqid(),
        "x-request-at" => java_like_timestamp(),
        "x-version-app" => "8.6.0",
    ];

    $url = BASE_API_URL . "/$path";
    $resp = api_request_curl($url, "POST", $headers, json_encode($body));

    if ($resp['status_code'] !== 200) {
        // Coba dekripsi jika respons adalah JSON terenkripsi meskipun status bukan 200
        try {
            $decrypted_body = decrypt_xdata($resp['body']);
            return $decrypted_body; // Kembalikan pesan error API yang terenkripsi
        } catch (Exception $e) {
            // Jika dekripsi gagal, kembalikan teks asli (kemungkinan error non-API)
            return ['error' => 'API Request Failed', 'message' => $resp['body'] ?? $resp['error']];
        }
    }

    try {
        $decrypted_body = decrypt_xdata($resp['body']);
        return $decrypted_body;
    } catch (Exception $e) {
        return ['error' => 'Decryption Failed', 'message' => $e->getMessage()];
    }
}

function get_balance() {
    $tokens = get_tokens_from_session();
    if (!$tokens) return null;

    $path = "api/v8/packages/balance-and-credit";
    $payload = ["is_enterprise" => false, "lang" => "en"];

    try {
        $res = send_api_request($path, $payload, $tokens["id_token"], "POST");
        
        if (isset($res["data"]["balance"])) {
            return $res["data"]["balance"];
        }
        // Menangani pesan error yang sudah didekripsi
        $error_msg = $res['error'] ?? ($res['message'] ?? 'Unknown error');
        if (strpos(strtolower($error_msg), 'too many requests') !== false) {
            throw new Exception("Too many requests. Please wait before trying again.");
        }
        return null;
    } catch (Exception $e) {
        throw $e;
    }
}

function get_packages_by_family_code_for_user($family_code, $is_enterprise = false) {
    $tokens = get_tokens_from_session();
    if (!$tokens) return null;

    $path = "api/v8/xl-stores/options/list";
    $payload_dict = [
        "is_show_tagging_tab" => true,
        "is_dedicated_event" => true,
        "is_transaction_routine" => false,
        "migration_type" => "NONE",
        "package_family_code" => $family_code,
        "is_autobuy" => false,
        "is_enterprise" => $is_enterprise, // Menggunakan parameter is_enterprise
        "is_pdlp" => true,
        "referral_code" => "",
        "is_migration" => false,
        "lang" => "en"
    ];
    
    try {
        $res = send_api_request($path, $payload_dict, $tokens["id_token"], "POST");
        if ($res["status"] !== "SUCCESS") {
            throw new Exception("Failed to get family packages: " . ($res['error'] ?? 'Unknown API Error'));
        }
        
        $packages = [];
        $start_number = 1;
        $package_variants = $res["data"]["package_variants"];
        
        foreach ($package_variants as $variant) {
            foreach ($variant["package_options"] as $option) {
                $friendly_name = $option["name"];
                
                // Logika penamaan khusus untuk XUT (dari show_xut_packages)
                if ($family_code === PACKAGE_FAMILY_CODE) {
                    if (strtolower($friendly_name) === "vidio") $friendly_name = "🔥 HOT! Unli Turbo Vidio";
                    if (strtolower($friendly_name) === "iflix") $friendly_name = "🔥 HOT! Unli Turbo Iflix";
                }
                    
                $packages[] = [
                    "number" => $start_number,
                    "name" => $friendly_name,
                    "price" => $option["price"],
                    "code" => $option["package_option_code"]
                ];
                $start_number++;
            }
        }
        return $packages;
    } catch (Exception $e) {
        throw $e;
    }
}

function get_package_details($package_option_code) {
    $tokens = get_tokens_from_session();
    if (!$tokens) return null;

    $path = "api/v8/xl-stores/options/detail";
    $payload = [
        "is_transaction_routine" => false,
        "package_family_code" => "",
        "is_autobuy" => false,
        "is_enterprise" => false,
        "is_migration" => false,
        "lang" => "en",
        "package_option_code" => $package_option_code,
    ];
    
    try {
        $res = send_api_request($path, $payload, $tokens["id_token"], "POST");
        
        if (!isset($res["data"])) {
            throw new Exception("Error getting package: " . ($res['message'] ?? 'Unknown error'));
        }
        return $res["data"];
    } catch (Exception $e) {
        throw $e;
    }
}

function purchase_package_with_balance($package_option_code, $amount = null) {
    $tokens = get_tokens_from_session();
    if (!$tokens) return ["success" => false, "error" => "No active session found. Please login."];

    try {
        $package_details = get_package_details($package_option_code);
        if (!$package_details) {
            return ["success" => false, "error" => "Failed to get package details. Package may be unavailable."];
        }
            
        $token_confirmation = $package_details["token_confirmation"];
        $price = $package_details["package_option"]["price"];
        $final_amount = $amount ?? $price; // Gunakan amount kustom jika ada, jika tidak, gunakan harga asli
        
        $variant_name = $package_details["package_detail_variant"]["name"] ?? "";
        $option_name = $package_details["package_option"]["name"] ?? "";
        $item_name = trim("$variant_name $option_name");

        // 1. Dapatkan token pembayaran (token_payment)
        $payment_path = "payments/api/v8/payment-methods-option";
        $payment_payload = [
            "payment_type" => "PURCHASE",
            "payment_target" => $package_option_code,
            "token_confirmation" => $token_confirmation,
        ];
        
        $payment_res = send_api_request($payment_path, $payment_payload, $tokens["id_token"], "POST");
        
        if ($payment_res["status"] !== "SUCCESS") {
            $error_msg = "Failed to initiate payment. Server response: " . ($payment_res['message'] ?? 'Unknown Error');
            return ["success" => false, "error" => $error_msg];
        }
        
        $token_payment = $payment_res["data"]["token_payment"];
        $ts_to_sign = $payment_res["data"]["timestamp"];
        
        // 2. Settlement dengan saldo
        $settlement_path = "payments/api/v8/settlement-balance";
        $settlement_payload = [
            "total_discount" => 0,
            "total_fee" => 0,
            "total_amount" => $final_amount, // Menggunakan final_amount
            "payment_method" => "BALANCE",
            "token_payment" => $token_payment,
            "access_token" => $tokens["access_token"],
            "timestamp" => $ts_to_sign, // Menggunakan timestamp dari payment-methods-option
            "items" => [
                [
                    "item_code" => $package_option_code,
                    "item_price" => $price, // Harga asli
                    "item_name" => $item_name,
                ]
            ]
            // ... payload lain yang diperlukan
        ];

        // Penanganan payload yang terlalu kompleks untuk ditranslasikan secara harfiah,
        // hanya menyertakan yang penting.
        $full_settlement_payload = array_merge([
            "is_enterprise" => false, "payment_token" => "", "activated_autobuy_code" => "", "cc_payment_type" => "",
            "is_myxl_wallet" => false, "pin" => "", "ewallet_promo_id" => "", "members" => [], "fingerprint" => "",
            "autobuy_threshold_setting" => ["label" => "", "type" => "", "value" => 0], "is_use_point" => false,
            "lang" => "en", "points_gained" => 0, "can_trigger_rating" => false, "akrab_members" => [],
            "akrab_parent_alias" => "", "referral_unique_code" => "", "coupon" => "", "payment_for" => "BUY_PACKAGE",
            "with_upsell" => false, "topup_number" => "", "stage_token" => "", "authentication_id" => "", 
            // Bagian ini memerlukan implementasi AES/Base64 kustom dari Python, dihilangkan untuk menjaga kebersihan
            // "