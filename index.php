<?php
// index.php
require_once 'config.php';
require_once 'functions.php';

$error_message = $_GET['error'] ?? '';
$success_message = $_GET['success'] ?? '';

// --- LOGIKA APLIKASI WEB (Routing & State Management) ---

$is_logged_in = isset($_SESSION['active_user']);
$action = $_GET['action'] ?? ($is_logged_in ? 'main_menu' : 'login');

if (isset($_POST['logout']) && $is_logged_in) {
    // Logika Logout
    session_destroy();
    header('Location: index.php?action=login&success=' . urlencode('✅ Anda telah berhasil keluar.'));
    exit;
}

// Pastikan user tidak bisa mengakses menu tanpa login
if (!$is_logged_in && !in_array($action, ['login', 'submit_phone', 'verify_otp', 'submit_otp'])) {
    $action = 'login';
}

// --- FUNGSI VIEW HTML ---

function render_header($title) {
    global $error_message, $success_message;
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $title ?> | MyXL Web</title>
    <link rel="stylesheet" href="style.css">
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@400;700&display=swap" rel="stylesheet">
</head>
<body>
<div class="app-container">
    <div class="header">
        <img src="https://upload.wikimedia.org/wikipedia/commons/e/ee/XL_Axiata_Logo.svg" alt="XL Logo" class="xl-logo">
        <h1><?= $title ?></h1>
    </div>
    <div class="content">
        <?php if ($error_message): ?>
            <div class="alert error"><?= htmlspecialchars($error_message) ?></div>
        <?php endif; ?>
        <?php if ($success_message): ?>
            <div class="alert success"><?= htmlspecialchars($success_message) ?></div>
        <?php endif; ?>
<?php
}

function render_footer() {
?>
    </div>
    <div class="footer">
        <p>&copy; 2024 MyXL Web Clone</p>
    </div>
</div>
</body>
</html>
<?php
}

// --- LOGIKA PENANGANAN ACTION ---

switch ($action) {
    
    case 'login':
        render_header('Masuk ke MyXL');
        // Halaman input nomor telepon (menggantikan /login command)
        echo "
            <form method='POST' action='index.php?action=submit_phone'>
                <p>📞 Masukkan Nomor XL Anda (Contoh : 6281234567890):</p>
                <input type='text' name='phone_number' placeholder='628...' required pattern='628[0-9]{7,11}'>
                <button type='submit' class='btn-primary'>Lanjutkan</button>
            </form>
        ";
        render_footer();
        break;

    case 'submit_phone':
        try {
            $phone_number = $_POST['phone_number'] ?? '';
            $user_id = uniqid(); // Mock user ID untuk web
            
            // Logika menyimpan user ID (menggantikan bagian awal login_start)
            insert_user_id($user_id);
            
            // Request OTP (menggantikan get_otp di phone_received)
            $subscriber_id = get_otp_request($phone_number);
            
            // Simpan state di sesi (menggantikan context.user_data)
            $_SESSION['pending_login'] = [
                'phone' => $phone_number, 
                'subscriber_id' => $subscriber_id,
                'user_id' => $user_id, // Simpan user ID mock
                'username' => 'web_user'
            ];
            
            header('Location: index.php?action=verify_otp');
            exit;
        } catch (Exception $e) {
            $error_message = $e->getMessage();
            header('Location: index.php?action=login&error=' . urlencode($error_message));
            exit;
        }

    case 'verify_otp':
        render_header('Verifikasi OTP');
        if (!isset($_SESSION['pending_login'])) {
            header('Location: index.php?action=login');
            exit;
        }
        $phone_number = $_SESSION['pending_login']['phone'];
        // Halaman input OTP (menggantikan reply_text di phone_received)
        echo "
            <form method='POST' action='index.php?action=submit_otp'>
                <p>📤 OTP telah dikirim ke nomor <b>$phone_number</b>. Masukkan 6 digit OTP:</p>
                <input type='text' name='otp_code' placeholder='6 digit OTP' required maxlength='6' pattern='\d{6}'>
                <button type='submit' class='btn-primary'>Verifikasi</button>
            </form>
        ";
        render_footer();
        break;
        
    case 'submit_otp':
        try {
            $otp = $_POST['otp_code'] ?? '';
            if (!isset($_SESSION['pending_login'])) {
                 throw new Exception("Sesi login berakhir. Silakan coba lagi.");
            }
            $pending = $_SESSION['pending_login'];
            $phone_number = $pending['phone'];
            
            // Submit OTP (menggantikan submit_otp di otp_received)
            $tokens = submit_otp_request($phone_number, $otp);
            if (!$tokens) {
                 throw new Exception("Gagal masuk. Silakan periksa OTP Anda.");
            }
            
            // Simpan token di JSON dan sesi
            if (file_exists("refresh-tokens.json")) {
                $refresh_tokens = json_decode(file_get_contents("refresh-tokens.json"), true);
            } else {
                $refresh_tokens = [];
            }
            $existing_key = array_search((int)$phone_number, array_column($refresh_tokens, 'number'));
            if ($existing_key !== false) {
                $refresh_tokens[$existing_key]['refresh_token'] = $tokens["refresh_token"];
            } else {
                $refresh_tokens[] = ["number" => (int)$phone_number, "refresh_token" => $tokens["refresh_token"]];
            }
            file_put_contents("refresh-tokens.json", json_encode($refresh_tokens, JSON_PRETTY_PRINT));
            
            $_SESSION['active_user'] = [
                "number" => (int)$phone_number,
                "tokens" => $tokens,
                "user_id" => $pending['user_id'],
                "username" => $pending['username']
            ];
            unset($_SESSION['pending_login']);
            
            // Log successful login (menggantikan user_logger.info)
            user_log('LOGIN', $pending['user_id'], $pending['username'], $phone_number);
            
            header('Location: index.php?action=main_menu&success=' . urlencode('✅ Login Berhasil!'));
            exit;
        } catch (Exception $e) {
            $error_message = $e->getMessage();
            header('Location: index.php?action=verify_otp&error=' . urlencode($error_message));
            exit;
        }

    case 'main_menu':
        render_header('Menu Utama MyXL');
        $user_number = $_SESSION['active_user']['number'];
        $tokens = $_SESSION['active_user']['tokens']; // Menggunakan tokens dari sesi
        
        try {
            $balance_data = get_balance(); // Ambil saldo
            $balance_remaining = number_format($balance_data['remaining'] ?? 0);
            $expired_at = $balance_data['expired_at'] ?? 'N/A';
            
            // Konversi timestamp ke format tanggal yang dapat dibaca
            $expired_at_dt = ($expired_at !== 'N/A' && is_numeric($expired_at)) 
                            ? date("Y-m-d H:i:s", $expired_at) 
                            : $expired_at;

            echo "<div class='card user-info'>
                    <p>✅ Anda sudah masuk Sebagai <b>{$user_number}</b></p>
                    <p>💸 Pulsa: <b>Rp {$balance_remaining}</b></p>
                    <p>📅 Masa Aktif: <b>{$expired_at_dt}</b></p>
                  </div>";
        } catch (Exception $e) {
            echo "<div class='card user-info error-card'>
                    <p>✅ Anda sudah masuk Sebagai <b>{$user_number}</b></p>
                    <p>⚠️ Gagal mengambil informasi saldo: " . htmlspecialchars($e->getMessage()) . "</p>
                  </div>";
        }
        
        // Menu pilihan (menggantikan InlineKeyboardMarkup)
        echo "<div class='menu-grid'>
                  <a href='?action=buy_xut' class='menu-item'>🔥 XUT Packages</a>
                  <a href='?action=buy_packages_menu' class='menu-item'>🛒 Kategori Paket Lain</a>
                  <form method='POST' style='display:inline;' class='menu-item-form'>
                    <button type='submit' name='logout' class='btn-danger'>🚪 Logout</button>
                  </form>
              </div>";
        render_footer();
        break;

    case 'buy_packages_menu':
        render_header('Beli Paket');
        echo "<div class='menu-grid'>
                <a href='?action=enter_family_code' class='menu-item'>👨‍👩‍👧‍👦 Beli Family Code</a>
                <a href='?action=enter_family_code_enterprise' class='menu-item'>🏢 Beli Family Code (Enterprise)</a>
              </div>";
        echo "<a href='?action=main_menu' class='btn-secondary'>🔙 Kembali ke Menu Utama</a>";
        render_footer();
        break;

    case 'buy_xut':
        render_header('XUT Packages');
        try {
            $packages = get_packages_by_family_code_for_user(PACKAGE_FAMILY_CODE);
            
            // Simpan ke cache sesi (menggantikan global cache di main.py)
            $_SESSION['xut_packages_cache'] = [];
            foreach ($packages as $i => $pkg) {
                $_SESSION['xut_packages_cache'][$i] = $pkg;
            }
            
            echo "<p>Pilih Paket XUT:</p>";
            echo "<div class='package-list'>";
            foreach ($packages as $i => $pkg) {
                $price_format = number_format($pkg['price']);
                echo "<a href='?action=show_package_details&pkg_type=xut&index=$i' class='package-item'>
                        {$pkg['name']} - Rp {$price_format}
                      </a>";
            }
            echo "</div>";
        } catch (Exception $e) {
            echo "<div class='alert error'>❌ Gagal mengambil paket: " . htmlspecialchars($e->getMessage()) . "</div>";
        }
        echo "<a href='?action=main_menu' class='btn-secondary'>🔙 Kembali ke Menu Utama</a>";
        render_footer();
        break;

    case 'enter_family_code':
    case 'enter_family_code_enterprise':
        $is_enterprise = ($action === 'enter_family_code_enterprise');
        render_header('Masukkan Family Code');
        $title = $is_enterprise ? 'Enterprise Family Code' : 'Family Code';
        
        echo "<form method='POST' action='index.php?action=show_family_packages_result'>
                <p>Masukkan {$title}:</p>
                <input type='hidden' name='is_enterprise' value='".($is_enterprise ? '1' : '0')."'>
                <input type='text' name='family_code' placeholder='Contoh: xxxx-xxxx-xxxx' required>
                <button type='submit' class='btn-primary'>Tampilkan Paket</button>
              </form>";
        echo "<a href='?action=buy_packages_menu' class='btn-secondary'>🔙 Kembali</a>";
        render_footer();
        break;

    case 'show_family_packages_result':
        render_header('Pilih Family Package');
        try {
            $family_code = $_POST['family_code'] ?? '';
            $is_enterprise = isset($_POST['is_enterprise']) && $_POST['is_enterprise'] === '1';
            
            $packages = get_packages_by_family_code_for_user($family_code, $is_enterprise);
            
            // Simpan ke cache sesi (menggantikan global cache di main.py)
            $_SESSION['family_packages_cache'] = [];
            foreach ($packages as $i => $pkg) {
                $_SESSION['family_packages_cache'][$i] = $pkg;
            }

            echo "<p>Paket untuk Family Code: <b>" . htmlspecialchars($family_code) . "</b></p>";
            echo "<div class='package-list'>";
            foreach ($packages as $i => $pkg) {
                $price_format = number_format($pkg['price']);
                echo "<a href='?action=show_package_details&pkg_type=family&index=$i' class='package-item'>
                        {$pkg['name']} - Rp {$price_format}
                      </a>";
            }
            echo "</div>";
        } catch (Exception $e) {
            echo "<div class='alert error'>❌ Gagal mengambil paket: " . htmlspecialchars($e->getMessage()) . "</div>";
        }
        echo "<a href='?action=buy_packages_menu' class='btn-secondary'>🔙 Kembali ke Kategori</a>";
        render_footer();
        break;

    case 'show_package_details':
        render_header('Detail Paket');
        $pkg_type = $_GET['pkg_type'] ?? '';
        $index = $_GET['index'] ?? -1;

        $cache = ($pkg_type === 'xut') ? ($_SESSION['xut_packages_cache'] ?? []) : ($_SESSION['family_packages_cache'] ?? []);
        $package = $cache[$index] ?? null;

        if (!$package) {
            header('Location: index.php?action=buy_xut&error=' . urlencode('❌ Paket tidak ditemukan dalam sesi.'));
            exit;
        }

        try {
            $package_details = get_package_details($package['code']);
            
            $title = $package_details["package_family"]["name"] . " " . 
                     $package_details["package_detail_variant"]["name"] . " " .
                     $package_details["package_option"]["name"];
            $price = $package_details["package_option"]["price"];
            $validity = $package_details["package_option"]["validity"];
            $tnc = strip_tags($package_details["package_option"]["tnc"] ?? 'No terms and conditions available');
            
            // Simpan detail yang diperlukan untuk proses pembayaran
            $_SESSION['current_package'] = [
                'code' => $package['code'],
                'title' => $title,
                'price' => $price,
                'index' => $index,
                'type' => $pkg_type
            ];
            
            echo "<h3>" . htmlspecialchars($title) . "</h3>";
            echo "<div class='card package-detail'>
                    <p>💰 Harga: <b>Rp " . number_format($price) . "</b></p>
                    <p>⏰ Masa Aktif: <b>" . htmlspecialchars($validity) . "</b></p>
                    <h4>📜 Syarat dan Ketentuan:</h4>
                    <p>" . nl2br(htmlspecialchars(substr($tnc, 0, 500) . (strlen($tnc) > 500 ? '...' : ''))) . "</p>
                  </div>";

            echo "<h4>💳 Pilih Metode Pembayaran:</h4>";
            echo "<div class='menu-grid payment-options'>
                    <a href='?action=process_purchase&method=BALANCE&index=$index&type=$pkg_type' class='btn-primary'>💳 Pulsa</a>
                    </div>";
        } catch (Exception $e) {
            echo "<div class='alert error'>❌ Gagal mengambil detail paket: " . htmlspecialchars($e->getMessage()) . "</div>";
        }
        $back_action = ($pkg_type === 'xut') ? 'buy_xut' : 'buy_packages_menu';
        echo "<a href='?action=$back_action' class='btn-secondary'>🔙 Kembali</a>";
        render_footer();
        break;

    case 'process_purchase':
        render_header('Proses Pembelian');
        $method = $_GET['method'] ?? 'BALANCE';
        $index = $_GET['index'] ?? -1;
        $pkg_type = $_GET['type'] ?? '';

        $cache = ($pkg_type === 'xut') ? ($_SESSION['xut_packages_cache'] ?? []) : ($_SESSION['family_packages_cache'] ?? []);
        $package = $cache[$index] ?? null;
        
        if (!$package) {
            header('Location: index.php?action=buy_xut&error=' . urlencode('❌ Paket tidak ditemukan dalam sesi.'));
            exit;
        }

        // Tentukan apakah ini paket family (untuk input amount kustom)
        $is_family_package = ($pkg_type === 'family');
        
        // Cek jika ini adalah submit form custom amount
        if ($is_family_package && $method === 'BALANCE' && !isset($_POST['amount_submitted'])) {
            // Tampilkan form input amount
            echo "<form method='POST' action='index.php?action=process_purchase&method=BALANCE&index=$index&type=family'>
                    <input type='hidden' name='amount_submitted' value='1'>
                    <p>Anda memilih paket Family. Harga asli: Rp " . number_format($package['price']) . ". Masukkan nominal yang ingin dibayarkan (0 untuk harga asli):</p>
                    <input type='number' name='custom_amount' placeholder='Masukkan nominal' min='0' required>
                    <button type='submit' class='btn-primary'>Bayar dengan Pulsa</button>
                  </form>";
        } else {
            // Proses Pembayaran (Final)
            $amount_to_pay = $package['price']; // Default ke harga asli
            if ($is_family_package && $method === 'BALANCE' && isset($_POST['custom_amount'])) {
                $custom_amount = (int)($_POST['custom_amount']);
                $amount_to_pay = ($custom_amount > 0) ? $custom_amount : $package['price'];
            }
            
            echo "<p>Memproses pembayaran paket <b>" . htmlspecialchars($package['name']) . "</b>...</p>";
            
            if ($method === 'BALANCE') {
                $result = purchase_package_with_balance($package['code'], $amount_to_pay);
                
                if ($result['success']) {
                    $log_msg = "PURCHASE_SUCCESS - User: {$_SESSION['active_user']['user_id']} ({$_SESSION['active_user']['username']}), XL Number: {$_SESSION['active_user']['number']}, Package: {$package['name']}, Price: Rp " . number_format($package['price']) . ", Paid: Rp " . number_format($amount_to_pay) . ", Method: BALANCE";
                    user_log('PURCHASE_SUCCESS', $_SESSION['active_user']['user_id'], $_SESSION['active_user']['username'], $_SESSION['active_user']['number'], $log_msg);
                    
                    echo "<div class='alert success'>✅ Pembelian Berhasil! Anda membayar Rp " . number_format($amount_to_pay) . ". Cek aplikasi XL Anda untuk konfirmasi.</div>";
                } else {
                    $log_msg = "PURCHASE_FAILED - User: {$_SESSION['active_user']['user_id']} ({$_SESSION['active_user']['username']}), XL Number: {$_SESSION['active_user']['number']}, Package: {$package['name']}, Price: Rp " . number_format($package['price']) . ", Paid: Rp " . number_format($amount_to_pay) . ", Method: BALANCE, Error: " . substr($result['error'], 0, 100);
                    user_log('PURCHASE_FAILED', $_SESSION['active_user']['user_id'], $_SESSION['active_user']['username'], $_SESSION['active_user']['number'], $log_msg);

                    echo "<div class='alert error'>❌ Pembelian Gagal: " . nl2br(htmlspecialchars($result['error'])) . "</div>";
                }
            }
            // else if ($method === 'QRIS') { ... Logika QRIS ... }
        }
        
        echo "<a href='?action=main_menu' class='btn-secondary'>🔙 Kembali ke Menu Utama</a>";
        render_footer();
        break;

    default:
        // Tampilkan error jika action tidak dikenal
        render_header('Kesalahan');
        echo "<div class='alert error'>Aksi tidak valid atau tidak ditemukan.</div>";
        echo "<a href='?action=main_menu' class='btn-secondary'>Kembali</a>";
        render_footer();
        break;
}
?>
