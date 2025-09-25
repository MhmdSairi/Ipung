<?php
// Ganti path ini ke lokasi file config.php Anda yang sebenarnya
require_once __DIR__ . '/config.php'; 

session_start();

// --- API Helper Functions (Minimal set) ---

function get_current_timestamp_xl() {
    // Implementasi ts_gmt7_without_colon dari Python
    $dt = new DateTime("now", new DateTimeZone('Asia/Jakarta')); // GMT+7
    $millis = round($dt->format('u')/1000);
    $ts = $dt->format("Y-m-d\TH:i:s.") . sprintf('%03d', $millis) . $dt->format('O');
    return $ts;
}

function get_java_like_timestamp() {
    // Implementasi java_like_timestamp dari Python
    $dt = new DateTime("now", new DateTimeZone('Asia/Jakarta')); // GMT+7
    $ms2 = floor($dt->format('u')/10000);
    $tz = $dt->format("O");
    $tz_colon = substr($tz, 0, 3) . ':' . substr($tz, 3);
    return $dt->format("Y-m-d\TH:i:s.") . sprintf('%02d', $ms2) . $tz_colon;
}

function ax_api_signature($ts_for_sign, $contact, $code) {
    // Memanggil layanan eksternal untuk penandatanganan (sesuai main.py)
    $url = AX_SIGN_URL;
    $headers = [
        "Content-Type: application/json",
        "x-api-key: " . API_KEY,
    ];
    $request_body = json_encode([
        "ts_for_sign" => $ts_for_sign,
        "contact" => $contact,
        "code" => $code,
        "contact_type" => "SMS"
    ]);

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $request_body);
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($http_code === 200) {
        $data = json_decode($response, true);
        return $data['ax_signature'] ?? null;
    }
    return null;
}

function encryptsign_xdata($id_token, $method, $path, $payload) {
    // Memanggil layanan eksternal untuk enkripsi dan penandatanganan (sesuai main.py)
    $url = XDATA_ENCRYPT_SIGN_URL;
    $headers = [
        "Content-Type: application/json",
        "x-api-key: " . API_KEY,
    ];
    $request_body = json_encode([
        "id_token" => $id_token,
        "method" => $method,
        "path" => $path,
        "body" => $payload
    ]);

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $request_body);
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($http_code === 200) {
        return json_decode($response, true);
    }
    return null;
}

function decrypt_xdata($encrypted_payload) {
    // Memanggil layanan eksternal untuk dekripsi (sesuai main.py)
    $url = XDATA_DECRYPT_URL;
    $headers = [
        "Content-Type: application/json",
        "x-api-key: " . API_KEY,
    ];

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($encrypted_payload));
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($http_code === 200) {
        $data = json_decode($response, true);
        return json_decode($data['plaintext'] ?? '{}', true);
    }
    return null;
}

function send_api_request($path, $payload_dict, $tokens, $method = "POST") {
    // 1. Enkripsi dan Tanda Tangan data (menggunakan layanan eksternal)
    $encrypted_data = encryptsign_xdata($tokens['id_token'], $method, $path, $payload_dict);
    if (!$encrypted_data) return ["status" => "FAILED", "error" => "Encryption/Signature failed."];
    
    $xtime = $encrypted_data["encrypted_body"]["xtime"];
    $sig_time_sec = floor($xtime / 1000);
    $body = $encrypted_data["encrypted_body"];
    $x_sig = $encrypted_data["x_signature"];
    
    // 2. Kirim permintaan ke XL API
    $url = BASE_API_URL . "/" . $path;
    $headers = [
        "host: " . str_replace("https://", "", BASE_API_URL),
        "content-type: application/json; charset=utf-8",
        "user-agent: " . USER_AGENT,
        "x-api-key: " . API_KEY,
        "authorization: Bearer " . $tokens['id_token'],
        "x-hv: v3",
        "x-signature-time: " . $sig_time_sec,
        "x-signature: " . $x_sig,
        "x-request-id: " . uniqid(),
        "x-request-at: " . get_java_like_timestamp(),
        "x-version-app: 8.6.0",
    ];

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    $response = curl_exec($ch);
    curl_close($ch);

    // 3. Dekripsi respons (menggunakan layanan eksternal)
    $json_resp = json_decode($response, true);
    if (isset($json_resp['error'])) return $json_resp; // API error before decryption
    
    $decrypted_body = decrypt_xdata($json_resp);
    return $decrypted_body;
}

// --- Token and Auth Management ---

function load_refresh_tokens() {
    if (file_exists(REFRESH_TOKENS_FILE)) {
        return json_decode(file_get_contents(REFRESH_TOKENS_FILE), true);
    }
    return [];
}

function save_refresh_tokens($tokens) {
    file_put_contents(REFRESH_TOKENS_FILE, json_encode($tokens, JSON_PRETTY_PRINT));
}

function get_new_token($refresh_token) {
    // Implementasi get_new_token dari Python
    $url = BASE_CIAM_URL . "/realms/xl-ciam/protocol/openid-connect/token";

    $headers = [
        "Host: " . str_replace("https://", "", BASE_CIAM_URL),
        "ax-request-at: " . get_current_timestamp_xl(),
        "ax-device-id: " . AX_DEVICE_ID,
        "ax-request-id: " . uniqid(),
        "ax-request-device: samsung",
        "ax-request-device-model: SM-N935F",
        "ax-fingerprint: " . AX_FP,
        "authorization: Basic " . BASIC_AUTH,
        "user-agent: " . USER_AGENT,
        "ax-substype: PREPAID",
        "content-type: application/x-www-form-urlencoded"
    ];

    $data = http_build_query([
        "grant_type" => "refresh_token",
        "refresh_token" => $refresh_token
    ]);

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
    $resp = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $body = json_decode($resp, true);
    if ($http_code !== 200 || isset($body['error'])) {
        return null; // Token refresh failed
    }
    return $body;
}

function get_active_user_tokens() {
    if (!isset($_SESSION['phone_number']) || !isset($_SESSION['refresh_token'])) {
        return null;
    }

    $tokens = get_new_token($_SESSION['refresh_token']);
    if ($tokens) {
        // Update refresh token in session and file
        $_SESSION['refresh_token'] = $tokens['refresh_token'];
        $all_tokens = load_refresh_tokens();
        $updated = false;
        foreach ($all_tokens as &$t) {
            if ($t['number'] == $_SESSION['phone_number']) {
                $t['refresh_token'] = $tokens['refresh_token'];
                $updated = true;
                break;
            }
        }
        if ($updated) save_refresh_tokens($all_tokens);

        return $tokens;
    } else {
        // Token expired, log out
        unset($_SESSION['phone_number']);
        unset($_SESSION['refresh_token']);
        return null;
    }
}


// --- API Requests from main.py ---

function get_otp_xl($contact) {
    // Implementasi get_otp dari Python
    $url = BASE_CIAM_URL . "/realms/xl-ciam/auth/otp";
    $querystring = http_build_query([
        "contact" => $contact,
        "contactType" => "SMS",
        "alternateContact" => "false"
    ]);

    $headers = [
        "Accept-Encoding: gzip, deflate, br",
        "Authorization: Basic " . BASIC_AUTH,
        "Ax-Device-Id: " . AX_DEVICE_ID,
        "Ax-Fingerprint: " . AX_FP,
        "Ax-Request-At: " . get_java_like_timestamp(),
        "Ax-Request-Device: samsung",
        "Ax-Request-Device-Model: SM-N935F",
        "Ax-Request-Id: " . uniqid(),
        "Ax-Substype: PREPAID",
        "Content-Type: application/json",
        "Host: " . str_replace("https://", "", BASE_CIAM_URL),
        "User-Agent: " . USER_AGENT
    ];

    $ch = curl_init($url . "?" . $querystring);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    $response = curl_exec($ch);
    curl_close($ch);

    $json_body = json_decode($response, true);
    if (isset($json_body["subscriber_id"])) {
        return true; // OTP requested successfully
    }
    return $json_body['error_description'] ?? 'Failed to request OTP';
}

function submit_otp_xl($contact, $code) {
    // Implementasi submit_otp dari Python
    $url = BASE_CIAM_URL . "/realms/xl-ciam/protocol/openid-connect/token";

    $ts_for_sign = get_current_timestamp_xl();
    $signature = ax_api_signature($ts_for_sign, $contact, $code);
    if (!$signature) return ["error" => "Failed to generate signature"];

    $ts_header = (new DateTime("now", new DateTimeZone('Asia/Jakarta')))->sub(new DateInterval('PT5M'))->format("Y-m-d\TH:i:s.000O");
    
    $payload = http_build_query([
        "contactType" => "SMS",
        "code" => $code,
        "grant_type" => "password",
        "contact" => $contact,
        "scope" => "openid"
    ]);

    $headers = [
        "Accept-Encoding: gzip, deflate, br",
        "Authorization: Basic " . BASIC_AUTH,
        "Ax-Api-Signature: " . $signature,
        "Ax-Device-Id: " . AX_DEVICE_ID,
        "Ax-Fingerprint: " . AX_FP,
        "Ax-Request-At: " . $ts_header,
        "Ax-Request-Device: samsung",
        "Ax-Request-Device-Model: SM-N935F",
        "Ax-Request-Id: " . uniqid(),
        "Ax-Substype: PREPAID",
        "Content-Type: application/x-www-form-urlencoded",
        "User-Agent: " . USER_AGENT,
    ];

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    $response = curl_exec($ch);
    curl_close($ch);

    $json_body = json_decode($response, true);
    if (isset($json_body["error"])) {
        return ["error" => $json_body['error_description'] ?? 'Unknown error during login'];
    }
    return $json_body;
}

function get_balance($tokens) {
    // Implementasi get_balance dari Python
    $path = "api/v8/packages/balance-and-credit";
    $payload = ["is_enterprise" => false, "lang" => "en"];
    
    $res = send_api_request($path, $payload, $tokens, "POST");
    return $res['data']['balance'] ?? null;
}

function get_package_xut($tokens) {
    // Implementasi get_package_xut_for_user dari Python
    $path = "api/v8/xl-stores/options/list";
    $payload_dict = [
        "is_show_tagging_tab" => true,
        "is_dedicated_event" => true,
        "is_transaction_routine" => false,
        "migration_type" => "NONE",
        "package_family_code" => PACKAGE_FAMILY_CODE,
        "is_autobuy" => false,
        "is_enterprise" => false,
        "is_pdlp" => true,
        "referral_code" => "",
        "is_migration" => false,
        "lang" => "en"
    ];

    $res = send_api_request($path, $payload_dict, $tokens, "POST");
    if (!isset($res['data']['package_variants'])) return null;

    $packages = [];
    $start_number = 0;
    foreach ($res['data']['package_variants'] as $variant) {
        foreach ($variant['package_options'] as $option) {
            $friendly_name = $option['name'];
            
            if (strtolower($friendly_name) === "vidio") $friendly_name = "🔥 HOT! Unli Turbo Vidio";
            if (strtolower($friendly_name) === "iflix") $friendly_name = "🔥 HOT! Unli Turbo Iflix";

            $packages[$start_number] = [
                "name" => $friendly_name,
                "price" => $option["price"],
                "code" => $option["package_option_code"]
            ];
            $start_number++;
        }
    }
    return $packages;
}

function get_package_details($tokens, $package_code) {
    // Implementasi get_package dari Python
    $path = "api/v8/xl-stores/options/detail";
    
    $payload = [
        "is_transaction_routine" => false,
        "migration_type" => "NONE",
        "package_family_code" => "",
        "family_role_hub" => "",
        "is_autobuy" => false,
        "is_enterprise" => false,
        "is_shareable" => false,
        "is_migration" => false,
        "lang" => "en",
        "package_option_code" => $package_code,
        "is_upsell_pdp" => false,
        "package_variant_code" => ""
    ];
    
    $res = send_api_request($path, $payload, $tokens, "POST");
    return $res['data'] ?? null;
}

function purchase_package_with_balance($tokens, $package_code) {
    // Implementasi purchase_package_with_balance dari Python (simplified)
    $package_details = get_package_details($tokens, $package_code);
    if (!$package_details) return ["success" => false, "error" => "Gagal mendapatkan detail paket."];
    
    $token_confirmation = $package_details["token_confirmation"];
    $price = $package_details["package_option"]["price"];
    $item_name = trim(($package_details["package_detail_variant"]["name"] ?? '') . ' ' . ($package_details["package_option"]["name"] ?? ''));

    // 1. Get token_payment and ts_to_sign
    $payment_path = "payments/api/v8/payment-methods-option";
    $payment_payload = [
        "payment_type" => "PURCHASE",
        "is_enterprise" => false,
        "payment_target" => $package_code,
        "lang" => "en",
        "is_referral" => false,
        "token_confirmation" => $token_confirmation
    ];
    $payment_res = send_api_request($payment_path, $payment_payload, $tokens, "POST");
    if (($payment_res['status'] ?? 'FAILED') !== 'SUCCESS') {
        return ["success" => false, "error" => "Gagal memulai pembayaran. " . ($payment_res['error'] ?? 'Unknown Error')];
    }
    
    $token_payment = $payment_res["data"]["token_payment"];
    $ts_to_sign = $payment_res["data"]["timestamp"];

    // 2. Settlement request for balance payment (using dummy build_encrypted_field)
    $settlement_path = "payments/api/v8/settlement-balance";
    $settlement_payload = [
        "total_discount" => 0, "is_enterprise" => false, "payment_token" => "", "token_payment" => $token_payment,
        "activated_autobuy_code" => "", "cc_payment_type" => "", "is_myxl_wallet" => false, "pin" => "",
        "ewallet_promo_id" => "", "members" => [], "total_fee" => 0, "fingerprint" => "",
        "autobuy_threshold_setting" => ["label" => "", "type" => "", "value" => 0], "is_use_point" => false,
        "lang" => "en", "payment_method" => "BALANCE", "timestamp" => time(), "points_gained" => 0,
        "can_trigger_rating" => false, "akrab_members" => [], "akrab_parent_alias" => "",
        "referral_unique_code" => "", "coupon" => "", "payment_for" => "BUY_PACKAGE", "with_upsell" => false,
        "topup_number" => "", "stage_token" => "", "authentication_id" => "",
        "encrypted_payment_token" => "AAAAAAAAA", // Dummy value - Requires full crypto implementation
        "token" => "", "token_confirmation" => "", "access_token" => $tokens["access_token"],
        "wallet_number" => "", 
        "encrypted_authentication_id" => "AAAAAAAAA", // Dummy value - Requires full crypto implementation
        "additional_data" => [], "total_amount" => $price, "is_using_autobuy" => false,
        "items" => [[
            "item_code" => $package_code, "product_type" => "", "item_price" => $price, 
            "item_name" => $item_name, "tax" => 0
        ]]
    ];

    // 3. Send payment request (requires special x_signature for payment)
    $encrypted_payload = encryptsign_xdata($tokens['id_token'], "POST", $settlement_path, $settlement_payload);
    if (!$encrypted_payload) return ["success" => false, "error" => "Encryption/Signature failed."];
    
    $xtime = $encrypted_payload["encrypted_body"]["xtime"];
    $sig_time_sec = floor($xtime / 1000);
    $body = $encrypted_payload["encrypted_body"];

    // Call external service for payment signature
    $payment_sig_url = PAYMENT_SIGN_URL;
    $payment_sig_headers = [
        "Content-Type: application/json", "x-api-key: " . API_KEY,
    ];
    $payment_sig_body = json_encode([
        "access_token" => $tokens["access_token"],
        "sig_time_sec" => $ts_to_sign, // Use timestamp from payment-methods-option response
        "package_code" => $package_code,
        "token_payment" => $token_payment,
        "payment_method" => "BALANCE"
    ]);
    
    $ch_sig = curl_init($payment_sig_url);
    curl_setopt($ch_sig, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch_sig, CURLOPT_HTTPHEADER, $payment_sig_headers);
    curl_setopt($ch_sig, CURLOPT_POST, true);
    curl_setopt($ch_sig, CURLOPT_POSTFIELDS, $payment_sig_body);
    $sig_response = curl_exec($ch_sig);
    $http_code_sig = curl_getinfo($ch_sig, CURLINFO_HTTP_CODE);
    curl_close($ch_sig);
    
    if ($http_code_sig !== 200) return ["success" => false, "error" => "Failed to get payment signature"];
    $sig_data = json_decode($sig_response, true);
    $x_sig = $sig_data['x_signature'] ?? null;
    if (!$x_sig) return ["success" => false, "error" => "Failed to get payment signature"];


    $headers = [
        "host: " . str_replace("https://", "", BASE_API_URL),
        "content-type: application/json; charset=utf-8",
        "user-agent: " . USER_AGENT,
        "x-api-key: " . API_KEY,
        "authorization: Bearer " . $tokens['id_token'],
        "x-hv: v3",
        "x-signature-time: " . $sig_time_sec,
        "x-signature: " . $x_sig,
        "x-request-id: " . uniqid(),
        "x-request-at: " . get_java_like_timestamp(),
        "x-version-app: 8.6.0",
    ];

    $ch = curl_init(BASE_API_URL . "/" . $settlement_path);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    $resp = curl_exec($ch);
    curl_close($ch);

    $json_resp = json_decode($resp, true);
    $decrypted_body = decrypt_xdata($json_resp);
    
    if (($decrypted_body['status'] ?? 'FAILED') === 'SUCCESS') {
        return ["success" => true, "data" => $decrypted_body];
    } else {
        return ["success" => false, "error" => $decrypted_body['message'] ?? 'Unknown Purchase Error'];
    }
}


// --- HTML & Flow Control ---

$message = "";
$error = "";
$flow = 'main_menu';

if (isset($_POST['action'])) {
    $action = $_POST['action'];

    switch ($action) {
        case 'request_otp':
            $phone_number = filter_input(INPUT_POST, 'phone_number', FILTER_SANITIZE_STRING);
            if (!preg_match('/^628\d{7,11}$/', $phone_number)) {
                $error = "Nomor XL tidak valid (contoh: 6281234567890).";
                $flow = 'login_form';
            } else {
                $otp_result = get_otp_xl($phone_number);
                if ($otp_result === true) {
                    $_SESSION['temp_phone'] = $phone_number;
                    $message = "OTP telah dikirim ke $phone_number. Masukkan 6 digit OTP.";
                    $flow = 'otp_form';
                } else {
                    $error = "Gagal meminta OTP: " . $otp_result;
                    $flow = 'login_form';
                }
            }
            break;

        case 'submit_otp':
            $phone_number = $_SESSION['temp_phone'] ?? '';
            $otp_code = filter_input(INPUT_POST, 'otp_code', FILTER_SANITIZE_STRING);

            if (!$phone_number || !preg_match('/^\d{6}$/', $otp_code)) {
                $error = "Data tida