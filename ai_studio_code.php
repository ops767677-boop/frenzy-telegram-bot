<?php
/**
 * ============================================================================
 * FRENZY LICENCE BOT - Production Ready Telegram Digital-Key Selling Bot
 * PHP 8.2 Compatible | Secure cURL Telegram API Engine
 * ============================================================================
 * 
 * PERSISTENT STORAGE NOTICE:
 * All application data (users, balances, categories, products, orders, settings,
 * temporary states, and error logs) are stored persistently in the /data directory.
 * When running inside Docker, ensure you mount a persistent volume to /data:
 * 
 *   docker run -d -v bot_data:/data -p 80:80 your-telegram-bot-image
 * 
 * ============================================================================
 */

declare(strict_types=1);
ob_start();

// ============================================================================
// 1. CONFIGURATION & CONSTANTS
// ============================================================================
const BOT_TOKEN = "8916507945:AAF5g9ipcEXQNGlkAY20PVvHKT3kGSA_D4g";
const ADMIN_ID  = "8777129138";

const MIN_PAYMENT_AMOUNT = 1;
const MAX_PAYMENT_AMOUNT = 5000;

// Resolve Data Directory (/data primary, fallback to __DIR__/data if local)
$dataDir = (is_dir('/data') && is_writable('/data')) ? '/data' : (__DIR__ . '/data');
if (!is_dir($dataDir)) {
    @mkdir($dataDir, 0777, true);
}
define('DATA_DIR', $dataDir);
define('ERROR_LOG_FILE', DATA_DIR . '/bot_errors.log');

// ============================================================================
// 2. DATA STORAGE & SAFE FILE HELPERS
// ============================================================================

/**
 * Log error messages safely to error log without exposing sensitive bot tokens.
 */
function logBotError(string $message, array $context = []): void {
    $date = date('Y-m-d H:i:s');
    $sanitized = str_replace(BOT_TOKEN, '[REDACTED_BOT_TOKEN]', $message);
    $contextStr = !empty($context) ? ' | Context: ' . json_encode($context, JSON_UNESCAPED_UNICODE) : '';
    $logEntry = "[$date] ERROR: $sanitized$contextStr\n";
    @file_put_contents(ERROR_LOG_FILE, $logEntry, FILE_APPEND | LOCK_EX);
}

/**
 * Perform safe one-time migration from legacy root directory to /data directory.
 */
function initializeAndMigrateData(): void {
    $jsonFiles = [
        'users.json',
        'balances.json',
        'orders.json',
        'categories.json',
        'products.json',
        'settings.json',
        'temp.json'
    ];

    foreach ($jsonFiles as $file) {
        $targetPath = DATA_DIR . '/' . $file;
        $legacyPath = __DIR__ . '/' . $file;

        if (!file_exists($targetPath)) {
            if (file_exists($legacyPath)) {
                $content = @file_get_contents($legacyPath);
                if ($content !== false && json_validate_check($content)) {
                    @file_put_contents($targetPath, $content, LOCK_EX);
                } else {
                    @file_put_contents($targetPath, "{}", LOCK_EX);
                }
            } else {
                @file_put_contents($targetPath, "{}", LOCK_EX);
            }
        }
    }

    // Ensure settings defaults exist safely without clobbering
    $settings = loadJson('settings.json');
    $updated = false;

    if (!isset($settings['upi_id'])) {
        $settings['upi_id'] = "9876543210@upi";
        $updated = true;
    }
    if (!isset($settings['upi_name'])) {
        $settings['upi_name'] = "Frenzy Licence";
        $updated = true;
    }
    if (!isset($settings['proof_link'])) {
        $settings['proof_link'] = "https://t.me/YourProofChannel";
        $updated = true;
    }
    if (!isset($settings['howto_link'])) {
        $settings['howto_link'] = "https://t.me/YourHowToVideo";
        $updated = true;
    }
    if (!isset($settings['support_user'])) {
        $settings['support_user'] = "@YourSupportUsername";
        $updated = true;
    }
    // Remove obsolete API keys if present
    if (isset($settings['api_key'])) {
        unset($settings['api_key']);
        $updated = true;
    }

    if ($updated) {
        saveJson('settings.json', $settings);
    }
}

/**
 * Helper to check valid JSON string
 */
function json_validate_check(string $string): bool {
    if (empty($string)) return false;
    json_decode($string);
    return json_last_error() === JSON_ERROR_NONE;
}

/**
 * Load JSON file safely with file locking.
 */
function loadJson(string $filename, array $default = []): array {
    $filePath = DATA_DIR . '/' . $filename;
    if (!file_exists($filePath)) {
        return $default;
    }

    $content = @file_get_contents($filePath);
    if ($content === false || trim($content) === '') {
        return $default;
    }

    $data = json_decode($content, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        logBotError("Failed to decode JSON from $filename: " . json_last_error_msg());
        return $default;
    }

    return is_array($data) ? $data : $default;
}

/**
 * Save JSON file safely with exclusive locking (LOCK_EX).
 */
function saveJson(string $filename, array $data): bool {
    $filePath = DATA_DIR . '/' . $filename;
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        logBotError("Failed to encode JSON for $filename: " . json_last_error_msg());
        return false;
    }

    $result = @file_put_contents($filePath, $json, LOCK_EX);
    if ($result === false) {
        logBotError("Failed to write JSON file to $filePath");
        return false;
    }
    return true;
}

/**
 * Manage User Balance Safely
 */
function getUserBalance(string|int $userId): int {
    $balances = loadJson('balances.json');
    return isset($balances[(string)$userId]) ? (int)$balances[(string)$userId] : 0;
}

function modifyUserBalance(string|int $userId, int $deltaAmount): int {
    $balances = loadJson('balances.json');
    $uid = (string)$userId;
    $current = isset($balances[$uid]) ? (int)$balances[$uid] : 0;
    $newBalance = max(0, $current + $deltaAmount);
    $balances[$uid] = $newBalance;
    saveJson('balances.json', $balances);
    return $newBalance;
}

function setUserBalance(string|int $userId, int $amount): void {
    $balances = loadJson('balances.json');
    $balances[(string)$userId] = max(0, $amount);
    saveJson('balances.json', $balances);
}

/**
 * Manage User Temp State Safely
 */
function getTemp(string|int $userId, string $key = '', mixed $default = null): mixed {
    $temp = loadJson('temp.json');
    $uid = (string)$userId;
    if (!isset($temp[$uid])) return $default;
    if ($key === '') return $temp[$uid];
    return $temp[$uid][$key] ?? $default;
}

function saveTemp(string|int $userId, string $key, mixed $value): void {
    $temp = loadJson('temp.json');
    $uid = (string)$userId;
    if (!isset($temp[$uid])) {
        $temp[$uid] = [];
    }
    $temp[$uid][$key] = $value;
    saveJson('temp.json', $temp);
}

function clearTemp(string|int $userId): void {
    $temp = loadJson('temp.json');
    $uid = (string)$userId;
    if (isset($temp[$uid])) {
        unset($temp[$uid]);
        saveJson('temp.json', $temp);
    }
}

// Run data bootstrap & migration
initializeAndMigrateData();

// ============================================================================
// 3. TELEGRAM BOT API ENGINE (cURL WITH ERROR HANDLING)
// ============================================================================

/**
 * Execute Telegram Bot API request using cURL.
 */
function telegramRequest(string $method, array $params = []): array|bool {
    $url = "https://api.telegram.org/bot" . BOT_TOKEN . "/" . $method;

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query($params),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_HTTPHEADER     => ["Content-Type: application/x-www-form-urlencoded"]
    ]);

    $response = curl_exec($ch);
    $curlError = curl_error($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($response === false) {
        logBotError("cURL Error executing $method: $curlError", ['params' => $params]);
        return false;
    }

    $decoded = json_decode($response, true);
    if (!is_array($decoded) || !($decoded['ok'] ?? false)) {
        $errorDescription = $decoded['description'] ?? 'Unknown Telegram Error';
        // Ignore benign Telegram callback / message not modified errors in logs
        if (!str_contains($errorDescription, 'message is not modified') &&
            !str_contains($errorDescription, 'query is too old')) {
            logBotError("Telegram API Error on $method (HTTP $httpCode): $errorDescription", ['params' => $params]);
        }
        return false;
    }

    return $decoded;
}

function sendMessage(string|int $chatId, string $text, ?string $replyMarkup = null, bool $disablePreview = true): array|bool {
    $params = [
        'chat_id'                  => $chatId,
        'text'                     => $text,
        'parse_mode'               => 'HTML',
        'disable_web_page_preview' => $disablePreview ? 'true' : 'false'
    ];
    if ($replyMarkup !== null) {
        $params['reply_markup'] = $replyMarkup;
    }
    return telegramRequest('sendMessage', $params);
}

function editMsg(string|int $chatId, int $messageId, string $text, ?string $replyMarkup = null, bool $disablePreview = true): array|bool {
    $params = [
        'chat_id'                  => $chatId,
        'message_id'               => $messageId,
        'text'                     => $text,
        'parse_mode'               => 'HTML',
        'disable_web_page_preview' => $disablePreview ? 'true' : 'false'
    ];
    if ($replyMarkup !== null) {
        $params['reply_markup'] = $replyMarkup;
    }
    return telegramRequest('editMessageText', $params);
}

function sendPhoto(string|int $chatId, string $photo, string $caption = '', ?string $replyMarkup = null): array|bool {
    $params = [
        'chat_id'    => $chatId,
        'photo'      => $photo,
        'caption'    => $caption,
        'parse_mode' => 'HTML'
    ];
    if ($replyMarkup !== null) {
        $params['reply_markup'] = $replyMarkup;
    }
    return telegramRequest('sendPhoto', $params);
}

function sendDocument(string|int $chatId, string $document, string $caption = '', ?string $replyMarkup = null): array|bool {
    $params = [
        'chat_id'    => $chatId,
        'document'   => $document,
        'caption'    => $caption,
        'parse_mode' => 'HTML'
    ];
    if ($replyMarkup !== null) {
        $params['reply_markup'] = $replyMarkup;
    }
    return telegramRequest('sendDocument', $params);
}

function sendVideo(string|int $chatId, string $video, string $caption = '', ?string $replyMarkup = null): array|bool {
    $params = [
        'chat_id'    => $chatId,
        'video'      => $video,
        'caption'    => $caption,
        'parse_mode' => 'HTML'
    ];
    if ($replyMarkup !== null) {
        $params['reply_markup'] = $replyMarkup;
    }
    return telegramRequest('sendVideo', $params);
}

function sendVoice(string|int $chatId, string $voice, string $caption = '', ?string $replyMarkup = null): array|bool {
    $params = [
        'chat_id'    => $chatId,
        'voice'      => $voice,
        'caption'    => $caption,
        'parse_mode' => 'HTML'
    ];
    if ($replyMarkup !== null) {
        $params['reply_markup'] = $replyMarkup;
    }
    return telegramRequest('sendVoice', $params);
}

function answerCallback(string $callbackQueryId, string $text = '', bool $showAlert = false): void {
    telegramRequest('answerCallbackQuery', [
        'callback_query_id' => $callbackQueryId,
        'text'              => $text,
        'show_alert'        => $showAlert ? 'true' : 'false'
    ]);
}

function deleteMsg(string|int $chatId, int $messageId): void {
    telegramRequest('deleteMessage', [
        'chat_id'    => $chatId,
        'message_id' => $messageId
    ]);
}

function btn(array $layout): string {
    $keyboard = [];
    foreach ($layout as $row) {
        $newRow = [];
        if (isset($row[0]) && is_array($row[0])) {
            foreach ($row as $button) {
                $btnData = ["text" => (string)$button[0]];
                if (isset($button[2]) && $button[2] === 'url') {
                    $btnData["url"] = (string)$button[1];
                } else {
                    $btnData["callback_data"] = (string)$button[1];
                }
                $newRow[] = $btnData;
            }
        } else {
            $btnData = ["text" => (string)$row[0]];
            if (isset($row[2]) && $row[2] === 'url') {
                $btnData["url"] = (string)$row[1];
            } else {
                $btnData["callback_data"] = (string)$row[1];
            }
            $newRow[] = $btnData;
        }
        $keyboard[] = $newRow;
    }
    return json_encode(["inline_keyboard" => $keyboard]);
}

function safeHtml(string $text): string {
    return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

// ============================================================================
// 4. INCOMING WEBHOOK PARSING
// ============================================================================

$rawInput = file_get_contents("php://input");
if (!$rawInput) {
    http_response_code(200);
    echo "Frenzy Licence Bot Engine is active.";
    exit;
}

$update = json_decode($rawInput, true);
if (!$update || !is_array($update)) {
    exit;
}

$isCallback = isset($update["callback_query"]);
$callbackQueryId = $isCallback ? ($update["callback_query"]["id"] ?? "") : "";

if ($isCallback) {
    $chat_id    = $update["callback_query"]["message"]["chat"]["id"] ?? 0;
    $message_id = $update["callback_query"]["message"]["message_id"] ?? 0;
    $user_id    = (string)($update["callback_query"]["from"]["id"] ?? "0");
    $first_name = $update["callback_query"]["from"]["first_name"] ?? "User";
    $username   = $update["callback_query"]["from"]["username"] ?? "";
    $data       = $update["callback_query"]["data"] ?? "";
    $text       = "";
    $photoArray = [];
} else {
    $chat_id    = $update["message"]["chat"]["id"] ?? 0;
    $message_id = $update["message"]["message_id"] ?? 0;
    $user_id    = (string)($update["message"]["from"]["id"] ?? "0");
    $first_name = $update["message"]["from"]["first_name"] ?? "User";
    $username   = $update["message"]["from"]["username"] ?? "";
    $text       = trim($update["message"]["text"] ?? "");
    $data       = "";
    $photoArray = $update["message"]["photo"] ?? [];
}

// Register or update user details in persistent users.json
if (!empty($user_id) && $user_id !== "0") {
    $users = loadJson('users.json');
    if (!isset($users[$user_id])) {
        $users[$user_id] = [
            "name"     => $first_name,
            "username" => $username,
            "join"     => date("d M Y")
        ];
        saveJson('users.json', $users);
    } else {
        $changed = false;
        if (($users[$user_id]['name'] ?? '') !== $first_name) {
            $users[$user_id]['name'] = $first_name;
            $changed = true;
        }
        if (($users[$user_id]['username'] ?? '') !== $username) {
            $users[$user_id]['username'] = $username;
            $changed = true;
        }
        if ($changed) {
            saveJson('users.json', $users);
        }
    }
}

// ============================================================================
// 5. USER INTERFACE SCREENS & VIEWS
// ============================================================================

function sendMainMenu(string|int $chatId, string $name, int $balance, int $messageId = 0): void {
    $escapedName = safeHtml($name);
    $msg = "👑 ———— <b>FRENZY LICENCE BOT</b> ———— 👑\n\n"
         . "🧡 Yo — ♡ <b>{$escapedName}</b>, Welcome Back!!\n\n"
         . "🔥 ———— WHY CHOOSE US ———— 🔥\n\n"
         . "🔑 Genuine Premium Keys\n"
         . "⚡ Instant Auto Delivery\n"
         . "🛡️ Secure UPI Payments\n"
         . "💎 Unbeatable Prices\n"
         . "👊 Real 24/7 Support\n"
         . "——————————————————————\n"
         . "💰 Let's get you a key!\n\n"
         . "💲 <b>Your Balance: ₹{$balance}.00</b>";

    $kb = [
        [["🛒 Shop Now", "shop"]],
        [["📦 My Orders", "orders"], ["👤 Profile", "profile"]],
        [["💰 Add Balance", "addbal"], ["📄 Payment Proof", "proof"]],
        [["📖 How to Use", "howto"], ["💬 Support", "support"]]
    ];

    if ($messageId > 0) {
        editMsg($chatId, $messageId, $msg, btn($kb));
    } else {
        sendMessage($chatId, $msg, btn($kb));
    }
}

function sendProfile(string|int $chatId, int $messageId, string|int $userId): void {
    $users = loadJson('users.json');
    $orders = loadJson('orders.json');
    $uid = (string)$userId;

    $name = safeHtml($users[$uid]['name'] ?? "User");
    $join = safeHtml($users[$uid]['join'] ?? date("d M Y"));
    $balance = getUserBalance($uid);

    $totalOrders = 0;
    foreach ($orders as $o) {
        if (($o['user_id'] ?? $o['user'] ?? '') === $uid && ($o['type'] ?? 'key_purchase') === 'key_purchase') {
            if (($o['status'] ?? '') === 'Delivered') {
                $totalOrders++;
            }
        }
    }

    $msg = "—\n👤 <b>YOUR PROFILE</b>\n—\n\n"
         . "👹 <b>Name:</b> {$name}\n"
         . "🆔 <b>User ID:</b> <code>{$uid}</code>\n"
         . "📅 <b>Member Since:</b> {$join}\n"
         . "🏷️ <b>Account Type:</b> 👤 Regular\n"
         . "💰 <b>Balance:</b> ₹{$balance}.00\n"
         . "🛒 <b>Total Completed Orders:</b> {$totalOrders}\n—";

    $kb = [
        [["🛒 Shop Now", "shop"], ["📦 My Orders", "orders"]],
        [["⬅️ Back to Menu", "back"]]
    ];

    editMsg($chatId, $messageId, $msg, btn($kb));
}

function sendCategories(string|int $chatId, int $messageId): void {
    $categories = loadJson('categories.json');

    if (empty($categories)) {
        $msg = "🛒 <b>PRODUCT STORE — SHOP</b>\n\n"
             . "❌ <b>No categories available right now.</b>\n"
             . "Admin has not created any categories yet. Please check back later!";
        $kb = [[["« Back to Menu", "back"]]];
        editMsg($chatId, $messageId, $msg, btn($kb));
        return;
    }

    $msg = "🛒 <b>PRODUCT STORE — SHOP</b>\n\n📱 Select category / device type:";
    $kb = [];
    foreach ($categories as $cid => $c) {
        $catName = safeHtml($c['name'] ?? 'Category');
        $kb[] = [[$catName, "cat_" . $cid]];
    }
    $kb[] = [["« Back to Menu", "back"]];

    editMsg($chatId, $messageId, $msg, btn($kb));
}

function sendProducts(string|int $chatId, int $messageId, string $cid): void {
    $categories = loadJson('categories.json');
    $products = loadJson('products.json');

    $catName = safeHtml($categories[$cid]['name'] ?? "Category");
    $msg = "🛒 <b>{$catName}</b>\n\nSelect product:";
    $kb = [];

    foreach ($products as $pid => $p) {
        if (($p['cat'] ?? '') === $cid) {
            $planCount = isset($p['plans']) && is_array($p['plans']) ? count($p['plans']) : 0;
            $pName = safeHtml($p['name'] ?? 'Product');
            $kb[] = [["{$pName} ({$planCount} plans)", "buy_{$pid}"]];
        }
    }

    if (empty($kb)) {
        $msg .= "\n\n❌ Is category me abhi koi product available nahi hai.";
    }

    $kb[] = [["⬅️ Back to Categories", "backshop"]];
    editMsg($chatId, $messageId, $msg, btn($kb));
}

function sendProductPlans(string|int $chatId, int $messageId, string $pid): void {
    $products = loadJson('products.json');

    if (!isset($products[$pid])) {
        editMsg($chatId, $messageId, "❌ <b>Product nahi mila.</b>", btn([[["⬅️ Back to Shop", "backshop"]]]));
        return;
    }

    $p = $products[$pid];
    $pName = safeHtml($p['name'] ?? 'Product');
    $plans = $p['plans'] ?? [];

    if (empty($plans)) {
        $msg = "📦 <b>{$pName}</b>\n\n❌ Is product ke liye abhi koi plan add nahi kiya gaya hai.\nAdmin se contact karein.";
        $kb = [[["⬅️ Back", "cat_" . ($p['cat'] ?? '')]], [["🛒 Shop Menu", "backshop"]]];
        editMsg($chatId, $messageId, $msg, btn($kb));
        return;
    }

    $msg = "📦 <b>{$pName}</b>\n\n<b>Choose a plan:</b>\n";
    $kb = [];

    foreach ($plans as $index => $plan) {
        $days = (int)($plan['days'] ?? 1);
        $price = (int)($plan['price'] ?? 0);
        $dayText = $days . ($days > 1 ? " Days" : " Day");

        $msg .= "\n• <b>{$dayText}</b> — ₹{$price}.00";
        $kb[] = [["{$dayText} — ₹{$price}", "plan_{$pid}_{$index}"]];
    }

    $catId = $p['cat'] ?? '';
    $kb[] = [["⬅️ Back", "cat_{$catId}"]];

    editMsg($chatId, $messageId, $msg, btn($kb));
}

/**
 * Handle user buying a plan (Works even if stock count is 0)
 */
function buyPlan(string|int $chatId, int $messageId, string|int $userId, string $pid, int $planIndex): void {
    $products = loadJson('products.json');
    $uid = (string)$userId;
    $userBalance = getUserBalance($uid);

    if (!isset($products[$pid])) {
        editMsg($chatId, $messageId, "❌ Product nahi mila.", btn([[["⬅️ Back to Shop", "backshop"]]]));
        return;
    }

    $p = $products[$pid];
    $plans = $p['plans'] ?? [];

    if (!isset($plans[$planIndex])) {
        editMsg($chatId, $messageId, "❌ Plan nahi mila.", btn([[["⬅️ Back to Shop", "backshop"]]]));
        return;
    }

    $plan = $plans[$planIndex];
    $planDays = (int)($plan['days'] ?? 1);
    $planPrice = (int)($plan['price'] ?? 0);
    $productName = $p['name'] ?? 'Product';

    // Insufficient balance check
    if ($userBalance < $planPrice) {
        $msg = "❌ <b>Insufficient Balance!</b>\n\n"
             . "📦 <b>Product:</b> " . safeHtml($productName) . "\n"
             . "📅 <b>Plan:</b> {$planDays} Days\n"
             . "💰 <b>Price:</b> ₹{$planPrice}.00\n"
             . "💲 <b>Your Balance:</b> ₹{$userBalance}.00\n\n"
             . "Please add balance to your account to proceed.";
        $kb = [
            [["💰 Add Balance", "addbal"]],
            [["⬅️ Back to Plans", "buy_{$pid}"]]
        ];
        editMsg($chatId, $messageId, $msg, btn($kb));
        return;
    }

    // Deduct exact balance
    $newBalance = modifyUserBalance($uid, -$planPrice);

    // Generate unique order
    $orderId = "ORD" . time() . rand(100, 999);
    $users = loadJson('users.json');
    $uName = $users[$uid]['name'] ?? 'User';
    $uUsername = $users[$uid]['username'] ?? '';

    $orders = loadJson('orders.json');
    $orders[$orderId] = [
        "type"         => "key_purchase",
        "order_id"     => $orderId,
        "user_id"      => $uid,
        "user_name"    => $uName,
        "username"     => $uUsername,
        "product_id"   => $pid,
        "product_name" => $productName,
        "days"         => $planDays,
        "price"        => $planPrice,
        "status"       => "awaiting_admin",
        "date"         => date("d M Y H:i"),
        "created_at"   => time(),
        "key"          => "",
        "download_link"=> "",
        "delivered_at" => 0,
        "refunded"     => false
    ];
    saveJson('orders.json', $orders);

    // Notify User
    $userMsg = "⏳ <b>Order Placed Successfully!</b>\n\n"
             . "📦 <b>Product:</b> " . safeHtml($productName) . "\n"
             . "📅 <b>Plan:</b> {$planDays} Days\n"
             . "💰 <b>Price Deducted:</b> ₹{$planPrice}.00\n"
             . "💲 <b>Remaining Balance:</b> ₹{$newBalance}.00\n"
             . "🆔 <b>Order ID:</b> <code>{$orderId}</code>\n\n"
             . "🛡️ <i>Your request has been forwarded to the Admin team. You will receive your license key & download link right here shortly!</i>";
    $userKb = [
        [["📦 My Orders", "orders"]],
        [["« Back to Menu", "back"]]
    ];
    editMsg($chatId, $messageId, $userMsg, btn($userKb));

    // Notify Admin with Action Buttons
    $userMention = !empty($uUsername) ? "@" . safeHtml($uUsername) : "N/A";
    $adminMsg = "🛒 <b>NEW KEY PURCHASE ORDER!</b>\n\n"
              . "👤 <b>User:</b> " . safeHtml($uName) . " ({$userMention})\n"
              . "🆔 <b>User ID:</b> <code>{$uid}</code>\n"
              . "📦 <b>Product:</b> " . safeHtml($productName) . "\n"
              . "📅 <b>Plan:</b> {$planDays} Days\n"
              . "💰 <b>Price:</b> ₹{$planPrice}.00\n"
              . "🆔 <b>Order ID:</b> <code>{$orderId}</code>\n"
              . "⏰ <b>Time:</b> " . date("d M Y H:i:s") . "\n\n"
              . "Please select an action:";
    $adminKb = [
        [["✅ APPROVE / GIVE KEY", "ordapp_{$orderId}"], ["❌ REJECT", "ordrej_{$orderId}"]]
    ];
    sendMessage(ADMIN_ID, $adminMsg, btn($adminKb));
}

function sendOrders(string|int $chatId, int $messageId, string|int $userId): void {
    $orders = loadJson('orders.json');
    $uid = (string)$userId;

    $myOrders = [];
    foreach ($orders as $id => $o) {
        if (($o['user_id'] ?? $o['user'] ?? '') === $uid && ($o['type'] ?? 'key_purchase') === 'key_purchase') {
            $myOrders[$id] = $o;
        }
    }

    if (empty($myOrders)) {
        $msg = "📄 <b>MY ORDERS / RECEIPT</b>\n\n"
             . "You have not placed any key orders yet.\n"
             . "Visit the shop to get your premium keys!";
        $kb = [
            [["🛒 Shop Now", "shop"]],
            [["« Back to Menu", "back"]]
        ];
        editMsg($chatId, $messageId, $msg, btn($kb));
        return;
    }

    $msg = "📦 <b>My Orders History</b>\n\n";
    $count = 1;
    foreach (array_reverse($myOrders) as $id => $o) {
        $pName = safeHtml($o['product_name'] ?? 'Product');
        $days = (int)($o['days'] ?? 1);
        $price = (int)($o['price'] ?? 0);
        $date = safeHtml($o['date'] ?? 'N/A');
        $status = $o['status'] ?? 'pending';

        $statusBadge = match ($status) {
            'Delivered' => "✅ Delivered",
            'awaiting_admin' => "⏳ Processing (Admin Approval)",
            'Rejected' => "❌ Rejected & Refunded",
            default => "ℹ️ " . safeHtml($status)
        };

        $msg .= "<b>{$count}. {$pName}</b> ({$days} Days)\n"
              . " 💰 Price: ₹{$price}.00\n"
              . " 📅 Date: {$date}\n"
              . " 🆔 Order: <code>{$id}</code>\n"
              . " 📊 Status: <b>{$statusBadge}</b>\n";

        if ($status === 'Delivered') {
            if (!empty($o['key'])) {
                $msg .= " 🔑 Key: <code>" . safeHtml($o['key']) . "</code>\n";
            }
            if (!empty($o['download_link']) && $o['download_link'] !== '-') {
                $msg .= " 🔗 Download: <a href='" . safeHtml($o['download_link']) . "'>Click to Download</a>\n";
            }
        } elseif ($status === 'Rejected' && !empty($o['rejection_reason'])) {
            $msg .= " 📝 Reason: <i>" . safeHtml($o['rejection_reason']) . "</i>\n";
        }

        $msg .= "\n";
        $count++;
        if ($count > 15) {
            $msg .= "<i>... and " . (count($myOrders) - 15) . " older orders</i>\n";
            break;
        }
    }

    $kb = [[["⬅️ Back to Menu", "back"]]];
    editMsg($chatId, $messageId, $msg, btn($kb));
}

function sendProof(string|int $chatId, int $messageId): void {
    $settings = loadJson('settings.json');
    $link = safeHtml($settings['proof_link'] ?? 'https://t.me');
    $msg = "📄 <b>Payment Proof Channel</b>\n\n"
         . "Yahan hamare sabhi customer payment proofs aur delivery records transparently uploaded hain.\n\n"
         . "🔗 <a href='{$link}'>Click Here to View Proofs</a>";
    editMsg($chatId, $messageId, $msg, btn([[["⬅️ Back to Menu", "back"]] activations: true]));
}

function sendHowTo(string|int $chatId, int $messageId): void {
    $settings = loadJson('settings.json');
    $link = safeHtml($settings['howto_link'] ?? 'https://t.me');
    $msg = "📖 <b>How to Use & Activate Keys</b>\n\n"
         . "1️⃣ <b>Add Balance:</b> Scan UPI QR and submit screenshot.\n"
         . "2️⃣ <b>Shop Now:</b> Select your desired device and plan.\n"
         . "3️⃣ <b>Get Key:</b> Admin immediately verifies and delivers key & setup link.\n\n"
         . "🎥 <b>Video Tutorial:</b>\n"
         . "🔗 <a href='{$link}'>Watch Video Guide</a>";
    editMsg($chatId, $messageId, $msg, btn([[["⬅️ Back to Menu", "back"]]]));
}

function sendSupport(string|int $chatId, int $messageId): void {
    $settings = loadJson('settings.json');
    $user = safeHtml($settings['support_user'] ?? '@Support');
    $cleanUser = ltrim($user, '@');
    $msg = "💬 <b>Customer Support 24/7</b>\n\n"
         . "Agar aapko koi bhi query, payment issue ya key activation me help chahiye, to seedha humare support handle par message karein:\n\n"
         . "🔗 <a href='https://t.me/{$cleanUser}'>{$user}</a>\n\n"
         . "⚡ <i>Average Response Time: 5-15 Minutes</i>";
    editMsg($chatId, $messageId, $msg, btn([[["⬅️ Back to Menu", "back"]]]));
}

// ============================================================================
// 6. DYNAMIC UPI PAYMENT & SCREENSHOT PROOF FLOW
// ============================================================================

function sendAddBalance(string|int $chatId, int $messageId, string|int $userId): void {
    $uid = (string)$userId;
    $balance = getUserBalance($uid);

    $msg = "💸 <b>Add Balance via Manual UPI</b>\n\n"
         . "💲 <b>Current Balance:</b> ₹{$balance}.00\n"
         . "Select a quick amount below, or enter a custom amount.\n\n"
         . "📌 <b>Limits:</b> Min: ₹" . MIN_PAYMENT_AMOUNT . " • Max: ₹" . number_format(MAX_PAYMENT_AMOUNT) . "\n"
         . "⚡ <i>Direct UPI QR with exact amount will be generated instantly.</i>";

    $kb = [
        [["₹50", "pay_50"], ["₹100", "pay_100"], ["₹200", "pay_200"]],
        [["₹500", "pay_500"], ["₹1000", "pay_1000"], ["₹2000", "pay_2000"]],
        [["✏️ Enter Custom Amount", "custom"]],
        [["🔙 Back to Menu", "back"]]
    ];

    editMsg($chatId, $messageId, $msg, btn($kb));
}

function sendKeypad(string|int $chatId, int $messageId, string|int $userId, string $amount): void {
    $msg = "💰 <b>Enter Custom Amount</b>\n\n"
         . "Selected: <b>₹{$amount}</b>\n\n"
         . "Limits: Min ₹" . MIN_PAYMENT_AMOUNT . " • Max ₹" . number_format(MAX_PAYMENT_AMOUNT);

    $kb = [
        [["1", "key_1"], ["2", "key_2"], ["3", "key_3"]],
        [["4", "key_4"], ["5", "key_5"], ["6", "key_6"]],
        [["7", "key_7"], ["8", "key_8"], ["9", "key_9"]],
        [["C", "key_C"], ["0", "key_0"], ["⌫", "key_DEL"]],
        [["✅ Generate QR for ₹{$amount}", "confirm_{$amount}"]],
        [["🔙 Back to Add Balance", "addbal"]]
    ];

    editMsg($chatId, $messageId, $msg, btn($kb));
}

function handleKeypad(string|int $chatId, int $messageId, string|int $userId, string $key): void {
    $uid = (string)$userId;
    $amount = (string)getTemp($uid, 'keypad_amount', '0');

    if ($key === "C") {
        $amount = "0";
    } elseif ($key === "DEL") {
        $amount = substr($amount, 0, -1);
        if ($amount === "" || $amount === false) $amount = "0";
    } else {
        $amount = ($amount === "0") ? $key : $amount . $key;
    }

    // Limit length
    if (strlen($amount) > 5) {
        $amount = substr($amount, 0, 5);
    }

    saveTemp($uid, 'keypad_amount', $amount);
    sendKeypad($chatId, $messageId, $uid, $amount);
}

/**
 * Generate Dynamic UPI Payment Order and send QR
 */
function createManualUpiPayment(string|int $chatId, int $messageId, string|int $userId, int $amount): void {
    $uid = (string)$userId;

    if ($amount < MIN_PAYMENT_AMOUNT || $amount > MAX_PAYMENT_AMOUNT) {
        $err = "❌ <b>Invalid Amount!</b>\nAmount must be between ₹" . MIN_PAYMENT_AMOUNT . " and ₹" . number_format(MAX_PAYMENT_AMOUNT);
        editMsg($chatId, $messageId, $err, btn([[["🔙 Back", "addbal"]]]));
        return;
    }

    $settings = loadJson('settings.json');
    $upiId = trim($settings['upi_id'] ?? '9876543210@upi');
    $upiName = trim($settings['upi_name'] ?? 'Frenzy Licence');

    // Create unique payment order ID
    $paymentOrderId = "PAY" . time() . rand(1000, 9999);
    $orders = loadJson('orders.json');
    $orders[$paymentOrderId] = [
        "type"             => "payment",
        "order_id"         => $paymentOrderId,
        "user_id"          => $uid,
        "amount"           => $amount,
        "upi_id"           => $upiId,
        "upi_name"         => $upiName,
        "status"           => "awaiting_payment",
        "created_at"       => time(),
        "date"             => date("d M Y H:i:s"),
        "proof_file_id"    => "",
        "approved_at"      => 0,
        "rejected_at"      => 0,
        "rejection_reason" => ""
    ];
    saveJson('orders.json', $orders);

    // Build Exact UPI Intent URI
    // Format: upi://pay?pa=UPI_ID&pn=UPI_NAME&am=500.00&cu=INR
    $formattedAmount = sprintf("%.2f", $amount);
    $upiUri = "upi://pay?pa=" . urlencode($upiId) . "&pn=" . urlencode($upiName) . "&am=" . $formattedAmount . "&cu=INR";
    $qrUrl = "https://api.qrserver.com/v1/create-qr-code/?size=350x350&data=" . urlencode($upiUri);

    $caption = "💸 <b>PAYMENT ORDER: ₹{$amount}.00</b>\n\n"
             . "🆔 <b>Order ID:</b> <code>{$paymentOrderId}</code>\n"
             . "💳 <b>UPI ID:</b> <code>" . safeHtml($upiId) . "</code>\n"
             . "👤 <b>Payee:</b> " . safeHtml($upiName) . "\n"
             . "💰 <b>Exact Amount:</b> ₹{$formattedAmount}\n\n"
             . "━━━━━━━━━━━━━━━━━━━━\n"
             . "<b>STEPS TO COMPLETE PAYMENT:</b>\n"
             . "1️⃣ Scan the QR code with GPay, PhonePe, Paytm or any UPI App.\n"
             . "2️⃣ Pay the exact amount (<b>₹{$amount}</b>).\n"
             . "3️⃣ Click <b>'📸 I Have Paid — Send Screenshot'</b> below.\n"
             . "4️⃣ Send the payment screenshot directly in this chat.\n"
             . "━━━━━━━━━━━━━━━━━━━━";

    $kb = [
        [["📸 I Have Paid — Send Screenshot", "proofsend_{$paymentOrderId}"]],
        [["❌ Cancel Order", "cancelpay_{$paymentOrderId}"]]
    ];

    if ($messageId > 0) {
        deleteMsg($chatId, $messageId);
    }
    sendPhoto($chatId, $qrUrl, $caption, btn($kb));
}

// ============================================================================
// 7. ADMIN DASHBOARD & CONTROLS
// ============================================================================

function sendAdminPanel(string|int $chatId, int $messageId = 0): void {
    $settings = loadJson('settings.json');
    $users = loadJson('users.json');
    $orders = loadJson('orders.json');

    $totalUsers = count($users);
    $upiId = safeHtml($settings['upi_id'] ?? 'Not Set');
    $upiName = safeHtml($settings['upi_name'] ?? 'Not Set');
    $proofLink = safeHtml($settings['proof_link'] ?? 'Not Set');
    $howtoLink = safeHtml($settings['howto_link'] ?? 'Not Set');
    $supportUser = safeHtml($settings['support_user'] ?? 'Not Set');

    // Count pending items
    $pendingProofs = 0;
    $pendingOrders = 0;
    foreach ($orders as $o) {
        if (($o['type'] ?? '') === 'payment' && ($o['status'] ?? '') === 'proof_submitted') {
            $pendingProofs++;
        }
        if (($o['type'] ?? '') === 'key_purchase' && ($o['status'] ?? '') === 'awaiting_admin') {
            $pendingOrders++;
        }
    }

    $msg = "👑 <b>FRENZY LICENCE — ADMIN PANEL</b>\n\n"
         . "👥 <b>Total Users:</b> {$totalUsers}\n"
         . "⏳ <b>Pending Proofs:</b> {$pendingProofs}\n"
         . "🛒 <b>Pending Key Orders:</b> {$pendingOrders}\n\n"
         . "💳 <b>UPI ID:</b> <code>{$upiId}</code>\n"
         . "👤 <b>UPI Name:</b> <code>{$upiName}</code>\n"
         . "📄 <b>Proof Link:</b> {$proofLink}\n"
         . "📖 <b>HowTo Link:</b> {$howtoLink}\n"
         . "💬 <b>Support:</b> {$supportUser}\n";

    $kb = [
        [["📁 Add Category", "addcat"], ["📦 Add Product", "addprod"]],
        [["➕ Add Plan", "addplan"], ["🔑 Add Plan Keys", "addplankeys"]],
        [["✏️ Edit Plan", "editplan"], ["🗑️ Delete Plan", "delplan"]],
        [["✏️ Edit Product", "editprod"], ["🗑️ Delete Product", "delprod"]],
        [["💳 Set UPI ID", "setupiid"], ["👤 Set UPI Name", "setupiname"]],
        [["📢 Broadcast", "broadcast"], ["👥 User List", "userlist"]],
        [["💰 Add User Balance", "adduserbal"]],
        [["📄 Set Proof Link", "setproof"], ["📖 Set HowTo Link", "sethowto"]],
        [["💬 Set Support Username", "setsupport"]],
        [["⬅️ Back to User Menu", "back"]]
    ];

    if ($messageId > 0) {
        editMsg($chatId, $messageId, $msg, btn($kb));
    } else {
        sendMessage($chatId, $msg, btn($kb));
    }
}

// Select helpers for Admin Workflows
function sendSelectCatForProduct(string|int $chatId, int $messageId): void {
    $categories = loadJson('categories.json');
    if (empty($categories)) {
        editMsg($chatId, $messageId, "❌ <b>Koi Category nahi mili!</b>\nPehle '📁 Add Category' se category add karein.", btn([[["⬅️ Back to Admin", "backadmin"]]]));
        return;
    }
    $msg = "📁 <b>Kis Category me Product Add karna hai?</b>\nSelect category:";
    $kb = [];
    foreach ($categories as $id => $c) {
        $cName = safeHtml($c['name'] ?? 'Category');
        $kb[] = [[$cName, "addtoprod_" . $id]];
    }
    $kb[] = [["⬅️ Cancel", "backadmin"]];
    editMsg($chatId, $messageId, $msg, btn($kb));
}

function sendSelectProductForAddPlan(string|int $chatId, int $messageId): void {
    $products = loadJson('products.json');
    if (empty($products)) {
        editMsg($chatId, $messageId, "❌ <b>Koi Product nahi hai!</b>\nPehle '📦 Add Product' se product create karein.", btn([[["⬅️ Back", "backadmin"]]]));
        return;
    }
    $msg = "➕ <b>Kis Product me Plan Add karna hai?</b>";
    $kb = [];
    foreach ($products as $id => $p) {
        $planCount = isset($p['plans']) ? count($p['plans']) : 0;
        $kb[] = [[safeHtml($p['name']) . " ({$planCount} plans)", "addplanprod_" . $id]];
    }
    $kb[] = [["⬅️ Back", "backadmin"]];
    editMsg($chatId, $messageId, $msg, btn($kb));
}

function sendSelectProductForAddPlanKeys(string|int $chatId, int $messageId): void {
    $products = loadJson('products.json');
    if (empty($products)) {
        editMsg($chatId, $messageId, "❌ <b>Koi Product nahi hai!</b>", btn([[["⬅️ Back", "backadmin"]]]));
        return;
    }
    $msg = "🔑 <b>Kis Product ke Plan me Keys Add karni hain?</b>";
    $kb = [];
    foreach ($products as $id => $p) {
        $planCount = isset($p['plans']) ? count($p['plans']) : 0;
        $kb[] = [[safeHtml($p['name']) . " ({$planCount} plans)", "addkeysprod_" . $id]];
    }
    $kb[] = [["⬅️ Back", "backadmin"]];
    editMsg($chatId, $messageId, $msg, btn($kb));
}

function sendPlansForAddKeys(string|int $chatId, int $messageId, string $pid): void {
    $products = loadJson('products.json');
    if (!isset($products[$pid])) {
        editMsg($chatId, $messageId, "❌ Product nahi mila!", btn([[["⬅️ Back", "backadmin"]]]));
        return;
    }
    $p = $products[$pid];
    if (empty($p['plans'])) {
        editMsg($chatId, $messageId, "❌ Is product me abhi koi plan nahi hai!\nPehle '➕ Add Plan' karein.", btn([[["⬅️ Back", "backadmin"]]]));
        return;
    }
    $msg = "🔑 <b>Add Keys — " . safeHtml($p['name']) . "</b>\n\nKis plan me keys add karni hain?";
    $kb = [];
    foreach ($p['plans'] as $index => $plan) {
        $keysCount = isset($plan['keys']) && is_array($plan['keys']) ? count($plan['keys']) : 0;
        $kb[] = [["{$plan['days']} Days - ₹{$plan['price']} ({$keysCount} keys in stock)", "addkeysplan_{$pid}_{$index}"]];
    }
    $kb[] = [["⬅️ Back", "backadmin"]];
    editMsg($chatId, $messageId, $msg, btn($kb));
}

function sendSelectProductForEditPlan(string|int $chatId, int $messageId): void {
    $products = loadJson('products.json');
    if (empty($products)) {
        editMsg($chatId, $messageId, "❌ <b>Koi Product nahi hai!</b>", btn([[["⬅️ Back", "backadmin"]]]));
        return;
    }
    $msg = "✏️ <b>Kis Product ka Plan Edit karna hai?</b>";
    $kb = [];
    foreach ($products as $id => $p) {
        $planCount = isset($p['plans']) ? count($p['plans']) : 0;
        $kb[] = [[safeHtml($p['name']) . " ({$planCount} plans)", "editplanprod_" . $id]];
    }
    $kb[] = [["⬅️ Back", "backadmin"]];
    editMsg($chatId, $messageId, $msg, btn($kb));
}

function sendPlansForEdit(string|int $chatId, int $messageId, string $pid): void {
    $products = loadJson('products.json');
    if (!isset($products[$pid])) {
        editMsg($chatId, $messageId, "❌ Product nahi mila!", btn([[["⬅️ Back", "backadmin"]]]));
        return;
    }
    $p = $products[$pid];
    if (empty($p['plans'])) {
        editMsg($chatId, $messageId, "❌ Is product me koi plan nahi hai!", btn([[["⬅️ Back", "backadmin"]]]));
        return;
    }
    $msg = "✏️ <b>Edit Plan — " . safeHtml($p['name']) . "</b>\nSelect plan:";
    $kb = [];
    foreach ($p['plans'] as $index => $plan) {
        $keysCount = isset($plan['keys']) && is_array($plan['keys']) ? count($plan['keys']) : 0;
        $kb[] = [["{$plan['days']} Days - ₹{$plan['price']} ({$keysCount} keys)", "editplan_{$pid}_{$index}"]];
    }
    $kb[] = [["⬅️ Back", "backadmin"]];
    editMsg($chatId, $messageId, $msg, btn($kb));
}

function sendSelectProductForDeletePlan(string|int $chatId, int $messageId): void {
    $products = loadJson('products.json');
    if (empty($products)) {
        editMsg($chatId, $messageId, "❌ <b>Koi Product nahi hai!</b>", btn([[["⬅️ Back", "backadmin"]]]));
        return;
    }
    $msg = "🗑️ <b>Kis Product ka Plan Delete karna hai?</b>";
    $kb = [];
    foreach ($products as $id => $p) {
        $planCount = isset($p['plans']) ? count($p['plans']) : 0;
        $kb[] = [[safeHtml($p['name']) . " ({$planCount} plans)", "delplanprod_" . $id]];
    }
    $kb[] = [["⬅️ Back", "backadmin"]];
    editMsg($chatId, $messageId, $msg, btn($kb));
}

function sendPlansForDelete(string|int $chatId, int $messageId, string $pid): void {
    $products = loadJson('products.json');
    if (!isset($products[$pid])) {
        editMsg($chatId, $messageId, "❌ Product nahi mila!", btn([[["⬅️ Back", "backadmin"]]]));
        return;
    }
    $p = $products[$pid];
    if (empty($p['plans'])) {
        editMsg($chatId, $messageId, "❌ Is product me koi plan nahi hai!", btn([[["⬅️ Back", "backadmin"]]]));
        return;
    }
    $msg = "🗑️ <b>Delete Plan — " . safeHtml($p['name']) . "</b>\nSelect plan to delete:";
    $kb = [];
    foreach ($p['plans'] as $index => $plan) {
        $kb[] = [["❌ {$plan['days']} Days - ₹{$plan['price']}", "delplan_{$pid}_{$index}"]];
    }
    $kb[] = [["⬅️ Back", "backadmin"]];
    editMsg($chatId, $messageId, $msg, btn($kb));
}

function sendSelectProductForEdit(string|int $chatId, int $messageId): void {
    $products = loadJson('products.json');
    if (empty($products)) {
        editMsg($chatId, $messageId, "❌ <b>Koi Product nahi hai!</b>", btn([[["⬅️ Back", "backadmin"]]]));
        return;
    }
    $msg = "✏️ <b>Kis Product ko Edit karna hai?</b>";
    $kb = [];
    foreach ($products as $id => $p) {
        $kb[] = [[safeHtml($p['name']) . " (" . count($p['plans'] ?? []) . " plans)", "editprod_" . $id]];
    }
    $kb[] = [["⬅️ Back", "backadmin"]];
    editMsg($chatId, $messageId, $msg, btn($kb));
}

function sendSelectProductForDelete(string|int $chatId, int $messageId): void {
    $products = loadJson('products.json');
    if (empty($products)) {
        editMsg($chatId, $messageId, "❌ <b>Koi Product nahi hai!</b>", btn([[["⬅️ Back", "backadmin"]]]));
        return;
    }
    $msg = "🗑️ <b>Kis Product ko Delete karna hai?</b>\n\n⚠️ <i>Product delete karne par uske saare plans aur keys delete ho jayenge!</i>";
    $kb = [];
    foreach ($products as $id => $p) {
        $kb[] = [["❌ " . safeHtml($p['name']) . " (" . count($p['plans'] ?? []) . " plans)", "delprod_" . $id]];
    }
    $kb[] = [["⬅️ Back", "backadmin"]];
    editMsg($chatId, $messageId, $msg, btn($kb));
}

// ============================================================================
// 8. MAIN ROUTER & HANDLERS (CALLBACKS, TEXT & MEDIA)
// ============================================================================

// -------------------- COMMAND /START --------------------
if ($text === "/start") {
    clearTemp($user_id);
    $bal = getUserBalance($user_id);
    sendMainMenu($chat_id, $first_name, $bal);
    exit;
}

// -------------------- COMMAND /ADMIN --------------------
if ($text === "/admin") {
    if ($user_id !== ADMIN_ID) {
        sendMessage($chat_id, "❌ <b>Access Denied:</b> You are not authorized to view the admin panel.");
        exit;
    }
    clearTemp($user_id);
    sendAdminPanel($chat_id, 0);
    exit;
}

// -------------------- CALLBACK QUERY HANDLERS --------------------
if ($isCallback) {
    answerCallback($callbackQueryId);

    // Standard User Navigation
    if ($data === "back") {
        clearTemp($user_id);
        $bal = getUserBalance($user_id);
        sendMainMenu($chat_id, $first_name, $bal, $message_id);
        exit;
    }
    if ($data === "shop" || $data === "backshop") {
        sendCategories($chat_id, $message_id);
        exit;
    }
    if ($data === "profile") {
        sendProfile($chat_id, $message_id, $user_id);
        exit;
    }
    if ($data === "orders") {
        sendOrders($chat_id, $message_id, $user_id);
        exit;
    }
    if ($data === "proof") {
        sendProof($chat_id, $message_id);
        exit;
    }
    if ($data === "howto") {
        sendHowTo($chat_id, $message_id);
        exit;
    }
    if ($data === "support") {
        sendSupport($chat_id, $message_id);
        exit;
    }
    if ($data === "addbal") {
        clearTemp($user_id);
        sendAddBalance($chat_id, $message_id, $user_id);
        exit;
    }

    // Category browsing -> show products
    if (str_starts_with($data, "cat_")) {
        $cid = substr($data, 4);
        sendProducts($chat_id, $message_id, $cid);
        exit;
    }

    // Product browsing -> show plans
    if (str_starts_with($data, "buy_")) {
        $pid = substr($data, 4);
        sendProductPlans($chat_id, $message_id, $pid);
        exit;
    }

    // Specific plan purchase
    if (str_starts_with($data, "plan_")) {
        $parts = explode("_", $data);
        if (count($parts) >= 3) {
            $pid = $parts[1];
            $planIndex = (int)$parts[2];
            buyPlan($chat_id, $message_id, $user_id, $pid, $planIndex);
        }
        exit;
    }

    // Payment Amount Buttons
    if (str_starts_with($data, "pay_")) {
        $amount = (int)substr($data, 4);
        createManualUpiPayment($chat_id, $message_id, $user_id, $amount);
        exit;
    }

    if ($data === "custom") {
        saveTemp($user_id, 'keypad_amount', '0');
        sendKeypad($chat_id, $message_id, $user_id, '0');
        exit;
    }

    if (str_starts_with($data, "key_")) {
        $key = substr($data, 4);
        handleKeypad($chat_id, $message_id, $user_id, $key);
        exit;
    }

    if (str_starts_with($data, "confirm_")) {
        $amount = (int)substr($data, 8);
        createManualUpiPayment($chat_id, $message_id, $user_id, $amount);
        exit;
    }

    if (str_starts_with($data, "cancelpay_")) {
        $orderId = substr($data, 10);
        $orders = loadJson('orders.json');
        if (isset($orders[$orderId])) {
            $orders[$orderId]['status'] = 'cancelled';
            saveJson('orders.json', $orders);
        }
        clearTemp($user_id);
        deleteMsg($chat_id, $message_id);
        sendMessage($chat_id, "❌ <b>Payment order {$orderId} cancelled.</b>", btn([[["💰 Add Balance", "addbal"]], [["« Menu", "back"]]]));
        exit;
    }

    // User clicks "I Have Paid - Send Screenshot"
    if (str_starts_with($data, "proofsend_")) {
        $orderId = substr($data, 10);
        saveTemp($user_id, 'waiting', 'payment_proof');
        saveTemp($user_id, 'proof_order_id', $orderId);

        $prompt = "📸 <b>Send Payment Screenshot Now</b>\n\n"
                . "Order ID: <code>{$orderId}</code>\n\n"
                . "Please send the screenshot / receipt of your payment directly in this chat.\n"
                . "Make sure UTR / Reference ID and Amount are clearly visible!";
        sendMessage($chat_id, $prompt, btn([[["❌ Cancel", "back"]]]));
        exit;
    }

    // ========================================================================
    // ADMIN ONLY CALLBACKS
    // ========================================================================
    if ($user_id === ADMIN_ID) {

        if ($data === "backadmin") {
            clearTemp($user_id);
            sendAdminPanel($chat_id, $message_id);
            exit;
        }

        // Add Category
        if ($data === "addcat") {
            saveTemp($user_id, 'waiting', 'newcat');
            editMsg($chat_id, $message_id, "📁 <b>Send New Category Name</b>\n\nExample: Non-Root Mobile", btn([[["⬅️ Cancel", "backadmin"]]]));
            exit;
        }

        // Add Product Flow
        if ($data === "addprod") {
            sendSelectCatForProduct($chat_id, $message_id);
            exit;
        }

        if (str_starts_with($data, "addtoprod_")) {
            $cid = substr($data, 10);
            $categories = loadJson('categories.json');
            if (!isset($categories[$cid])) {
                editMsg($chat_id, $message_id, "❌ <b>Error:</b> Category not found!", btn([[["⬅️ Back", "backadmin"]]]));
                exit;
            }
            saveTemp($user_id, 'addprod_cat', $cid);
            saveTemp($user_id, 'waiting', 'prod_name');
            $catName = safeHtml($categories[$cid]['name'] ?? '');
            editMsg($chat_id, $message_id, "📦 <b>Category:</b> {$catName}\n\nAb product ka naam bhejo:\nExample: <code>VIP SILENT CHEAT</code>", btn([[["⬅️ Cancel", "backadmin"]]]));
            exit;
        }

        // Add Plan Flow
        if ($data === "addplan") {
            sendSelectProductForAddPlan($chat_id, $message_id);
            exit;
        }

        if (str_starts_with($data, "addplanprod_")) {
            $pid = substr($data, 12);
            $products = loadJson('products.json');
            if (!isset($products[$pid])) {
                editMsg($chat_id, $message_id, "❌ Product not found!", btn([[["⬅️ Back", "backadmin"]]]));
                exit;
            }
            saveTemp($user_id, 'addplan_pid', $pid);
            saveTemp($user_id, 'waiting', 'plan_days');
            editMsg($chat_id, $message_id, "📅 <b>Kitne Days ka plan hai?</b>\n\nEnter number of days (Example: 1, 3, 7, 30):", btn([[["⬅️ Cancel", "backadmin"]]]));
            exit;
        }

        // Add Keys Flow
        if ($data === "addplankeys") {
            sendSelectProductForAddPlanKeys($chat_id, $message_id);
            exit;
        }

        if (str_starts_with($data, "addkeysprod_")) {
            $pid = substr($data, 12);
            sendPlansForAddKeys($chat_id, $message_id, $pid);
            exit;
        }

        if (str_starts_with($data, "addkeysplan_")) {
            $parts = explode("_", $data);
            if (count($parts) >= 3) {
                $pid = $parts[1];
                $planIndex = (int)$parts[2];
                saveTemp($user_id, 'addkeys_pid', $pid);
                saveTemp($user_id, 'addkeys_plan_index', $planIndex);
                saveTemp($user_id, 'waiting', 'add_plan_keys');
                editMsg($chat_id, $message_id, "🔑 <b>Is plan ke liye Keys Bhejo</b>\n\nEk line me ek key likhein:\n\n<code>KEY-111-AAA\nKEY-222-BBB\nKEY-333-CCC</code>", btn([[["⬅️ Cancel", "backadmin"]]]));
            }
            exit;
        }

        // Edit Plan
        if ($data === "editplan") {
            sendSelectProductForEditPlan($chat_id, $message_id);
            exit;
        }

        if (str_starts_with($data, "editplanprod_")) {
            $pid = substr($data, 13);
            sendPlansForEdit($chat_id, $message_id, $pid);
            exit;
        }

        if (str_starts_with($data, "editplan_")) {
            $parts = explode("_", $data);
            if (count($parts) >= 3) {
                $pid = $parts[1];
                $planIndex = (int)$parts[2];
                $products = loadJson('products.json');
                $plan = $products[$pid]['plans'][$planIndex] ?? null;
                if (!$plan) {
                    editMsg($chat_id, $message_id, "❌ Plan not found!", btn([[["⬅️ Back", "backadmin"]]]));
                    exit;
                }
                $keysCount = isset($plan['keys']) && is_array($plan['keys']) ? count($plan['keys']) : 0;
                $msg = "✏️ <b>Edit Plan</b>\n\n"
                     . "Current: <b>{$plan['days']} Days — ₹{$plan['price']}</b>\n"
                     . "Keys Stock: <b>{$keysCount} keys</b>\n\n"
                     . "Select what you want to edit:";
                $kb = [
                    [["📅 Edit Days", "editplandays_{$pid}_{$planIndex}"]],
                    [["💰 Edit Price", "editplanprice_{$pid}_{$planIndex}"]],
                    [["🔑 Add Keys to Stock", "editplankeys_{$pid}_{$planIndex}"]],
                    [["⬅️ Back", "backadmin"]]
                ];
                editMsg($chat_id, $message_id, $msg, btn($kb));
            }
            exit;
        }

        if (str_starts_with($data, "editplandays_")) {
            $parts = explode("_", $data);
            if (count($parts) >= 3) {
                saveTemp($user_id, 'edit_pid', $parts[1]);
                saveTemp($user_id, 'edit_plan_index', (int)$parts[2]);
                saveTemp($user_id, 'waiting', 'edit_plan_days');
                editMsg($chat_id, $message_id, "📅 <b>Enter New Days:</b> (e.g. 7)", btn([[["⬅️ Cancel", "backadmin"]]]));
            }
            exit;
        }

        if (str_starts_with($data, "editplanprice_")) {
            $parts = explode("_", $data);
            if (count($parts) >= 3) {
                saveTemp($user_id, 'edit_pid', $parts[1]);
                saveTemp($user_id, 'edit_plan_index', (int)$parts[2]);
                saveTemp($user_id, 'waiting', 'edit_plan_price');
                editMsg($chat_id, $message_id, "💰 <b>Enter New Price (₹):</b> (e.g. 299)", btn([[["⬅️ Cancel", "backadmin"]]]));
            }
            exit;
        }

        if (str_starts_with($data, "editplankeys_")) {
            $parts = explode("_", $data);
            if (count($parts) >= 3) {
                saveTemp($user_id, 'edit_pid', $parts[1]);
                saveTemp($user_id, 'edit_plan_index', (int)$parts[2]);
                saveTemp($user_id, 'waiting', 'edit_plan_keys');
                editMsg($chat_id, $message_id, "🔑 <b>Send Keys to Add:</b>\n\nOne key per line.", btn([[["⬅️ Cancel", "backadmin"]]]));
            }
            exit;
        }

        // Delete Plan
        if ($data === "delplan") {
            sendSelectProductForDeletePlan($chat_id, $message_id);
            exit;
        }

        if (str_starts_with($data, "delplanprod_")) {
            $pid = substr($data, 12);
            sendPlansForDelete($chat_id, $message_id, $pid);
            exit;
        }

        if (str_starts_with($data, "delplan_")) {
            $parts = explode("_", $data);
            if (count($parts) >= 3) {
                $pid = $parts[1];
                $planIndex = (int)$parts[2];
                $products = loadJson('products.json');
                if (isset($products[$pid]['plans'][$planIndex])) {
                    $deleted = $products[$pid]['plans'][$planIndex];
                    unset($products[$pid]['plans'][$planIndex]);
                    $products[$pid]['plans'] = array_values($products[$pid]['plans']);
                    saveJson('products.json', $products);
                    editMsg($chat_id, $message_id, "🗑️ <b>Plan Deleted:</b> {$deleted['days']} Days — ₹{$deleted['price']}", btn([[["⬅️ Back to Admin", "backadmin"]]]));
                }
            }
            exit;
        }

        // Edit Product
        if ($data === "editprod") {
            sendSelectProductForEdit($chat_id, $message_id);
            exit;
        }

        if (str_starts_with($data, "editprod_")) {
            $pid = substr($data, 9);
            $products = loadJson('products.json');
            if (isset($products[$pid])) {
                $p = $products[$pid];
                $msg = "✏️ <b>Edit Product</b>\n\nName: <b>" . safeHtml($p['name']) . "</b>\nPlans: " . count($p['plans'] ?? []);
                $kb = [
                    [["📝 Rename Product", "edit_name_{$pid}"]],
                    [["⬅️ Back", "backadmin"]]
                ];
                editMsg($chat_id, $message_id, $msg, btn($kb));
            }
            exit;
        }

        if (str_starts_with($data, "edit_name_")) {
            $pid = substr($data, 10);
            saveTemp($user_id, 'edit_pid', $pid);
            saveTemp($user_id, 'waiting', 'edit_name');
            editMsg($chat_id, $message_id, "📝 <b>Product ka naya naam bhejo:</b>", btn([[["⬅️ Cancel", "backadmin"]]]));
            exit;
        }

        // Delete Product
        if ($data === "delprod") {
            sendSelectProductForDelete($chat_id, $message_id);
            exit;
        }

        if (str_starts_with($data, "delprod_")) {
            $pid = substr($data, 8);
            $products = loadJson('products.json');
            if (isset($products[$pid])) {
                $deletedName = $products[$pid]['name'];
                unset($products[$pid]);
                saveJson('products.json', $products);
                editMsg($chat_id, $message_id, "🗑️ <b>Product Deleted:</b> " . safeHtml($deletedName), btn([[["⬅️ Back to Admin", "backadmin"]]]));
            } else {
                editMsg($chat_id, $message_id, "❌ Product not found!", btn([[["⬅️ Back", "backadmin"]]]));
            }
            exit;
        }

        // UPI Settings
        if ($data === "setupiid") {
            saveTemp($user_id, 'waiting', 'set_upi_id');
            editMsg($chat_id, $message_id, "💳 <b>Send New UPI ID</b>\n\nExample: <code>9876543210@upi</code>", btn([[["⬅️ Cancel", "backadmin"]]]));
            exit;
        }

        if ($data === "setupiname") {
            saveTemp($user_id, 'waiting', 'set_upi_name');
            editMsg($chat_id, $message_id, "👤 <b>Send New UPI Receiver Name</b>\n\nExample: <code>Frenzy Licence</code>", btn([[["⬅️ Cancel", "backadmin"]]]));
            exit;
        }

        // Other Settings
        if ($data === "setproof") {
            saveTemp($user_id, 'waiting', 'proof');
            editMsg($chat_id, $message_id, "📄 <b>Send New Payment Proof Channel Link:</b>", btn([[["⬅️ Cancel", "backadmin"]]]));
            exit;
        }

        if ($data === "sethowto") {
            saveTemp($user_id, 'waiting', 'howto');
            editMsg($chat_id, $message_id, "📖 <b>Send New HowTo Video Link:</b>", btn([[["⬅️ Cancel", "backadmin"]]]));
            exit;
        }

        if ($data === "setsupport") {
            saveTemp($user_id, 'waiting', 'support');
            editMsg($chat_id, $message_id, "💬 <b>Send Support Username (e.g. @FrenzySupport):</b>", btn([[["⬅️ Cancel", "backadmin"]]]));
            exit;
        }

        // Broadcast
        if ($data === "broadcast") {
            saveTemp($user_id, 'waiting', 'broadcast_text');
            editMsg($chat_id, $message_id, "📢 <b>Send Broadcast Message</b>\n\nYou can send Text, Photo, Video, Voice or Document. Caption will be included.", btn([[["⬅️ Cancel", "backadmin"]]]));
            exit;
        }

        // User List
        if ($data === "userlist") {
            $users = loadJson('users.json');
            $msg = "👥 <b>Total Registered Users:</b> " . count($users) . "\n\n";
            $i = 1;
            foreach ($users as $uid => $u) {
                $uName = safeHtml($u['name'] ?? 'User');
                $uUser = !empty($u['username']) ? " (@" . safeHtml($u['username']) . ")" : "";
                $join = safeHtml($u['join'] ?? 'N/A');
                $bal = getUserBalance($uid);

                $msg .= "{$i}. <b>{$uName}</b>{$uUser}\n   🆔 <code>{$uid}</code> | 💰 ₹{$bal} | 📅 {$join}\n\n";
                $i++;
                if ($i > 25) {
                    $msg .= "<i>... and " . (count($users) - 25) . " more users</i>\n";
                    break;
                }
            }
            editMsg($chat_id, $message_id, $msg, btn([[["⬅️ Back to Admin", "backadmin"]]]));
            exit;
        }

        // Manual Balance Addition
        if ($data === "adduserbal") {
            saveTemp($user_id, 'waiting', 'adduserbal_id');
            editMsg($chat_id, $message_id, "💰 <b>Add Balance to User</b>\n\nEnter Target User Telegram ID (numeric):", btn([[["⬅️ Cancel", "backadmin"]]]));
            exit;
        }

        // Payment Proof Approval / Rejection (Duplicate Protected)
        if (str_starts_with($data, "payapp_")) {
            $orderId = substr($data, 7);
            $orders = loadJson('orders.json');
            if (!isset($orders[$orderId])) {
                answerCallback($callbackQueryId, "Order not found!", true);
                exit;
            }

            $order = $orders[$orderId];
            if (($order['status'] ?? '') === 'paid') {
                answerCallback($callbackQueryId, "Already Approved!", true);
                exit;
            }

            if (($order['status'] ?? '') !== 'proof_submitted') {
                answerCallback($callbackQueryId, "Invalid order status: " . ($order['status'] ?? 'unknown'), true);
                exit;
            }

            $amount = (int)($order['amount'] ?? 0);
            $targetUserId = (string)($order['user_id'] ?? '');

            // Credit balance
            $newBalance = modifyUserBalance($targetUserId, $amount);

            // Update order status
            $orders[$orderId]['status'] = 'paid';
            $orders[$orderId]['approved_at'] = time();
            saveJson('orders.json', $orders);

            // Edit Admin Message
            editMsg($chat_id, $message_id, "✅ <b>Payment Approved!</b>\n\nOrder ID: <code>{$orderId}</code>\nAmount: ₹{$amount}\nUser: <code>{$targetUserId}</code>\nStatus: <b>CREDITED</b>");

            // Notify User
            $userNotice = "✅ <b>Payment Approved!</b>\n\n"
                        . "₹{$amount}.00 has been added to your balance.\n"
                        . "💲 <b>New Balance: ₹{$newBalance}.00</b>\n\n"
                        . "Thank you! You can now browse the shop and purchase keys.";
            sendMessage($targetUserId, $userNotice, btn([[["🛒 Shop Now", "shop"]], [["« Menu", "back"]]]));
            exit;
        }

        if (str_starts_with($data, "payrej_")) {
            $orderId = substr($data, 7);
            saveTemp($user_id, 'waiting', 'pay_reject_reason');
            saveTemp($user_id, 'target_order', $orderId);
            sendMessage($chat_id, "❌ <b>Enter Rejection Reason for Payment Order {$orderId}:</b>", btn([[["⬅️ Cancel", "backadmin"]]]));
            exit;
        }

        // Key Purchase Order Approval / Rejection
        if (str_starts_with($data, "ordapp_")) {
            $orderId = substr($data, 7);
            $orders = loadJson('orders.json');
            if (!isset($orders[$orderId])) {
                answerCallback($callbackQueryId, "Order not found!", true);
                exit;
            }

            $order = $orders[$orderId];
            if (($order['status'] ?? '') === 'Delivered') {
                answerCallback($callbackQueryId, "Order is already Delivered!", true);
                exit;
            }

            saveTemp($user_id, 'waiting', 'deliver_key');
            saveTemp($user_id, 'target_order', $orderId);

            $pName = safeHtml($order['product_name'] ?? 'Product');
            $days = $order['days'] ?? 1;
            sendMessage($chat_id, "🔑 <b>Send License Key for Order: <code>{$orderId}</code></b>\n\nProduct: {$pName} ({$days} Days)\nUser ID: <code>{$order['user_id']}</code>\n\nEnter the key below:", btn([[["⬅️ Cancel", "backadmin"]]]));
            exit;
        }

        if (str_starts_with($data, "ordrej_")) {
            $orderId = substr($data, 7);
            saveTemp($user_id, 'waiting', 'order_reject_reason');
            saveTemp($user_id, 'target_order', $orderId);
            sendMessage($chat_id, "❌ <b>Enter Rejection Reason for Key Order {$orderId}:</b>\n\n<i>Note: The purchase amount will be automatically refunded to user's balance.</i>", btn([[["⬅️ Cancel", "backadmin"]]]));
            exit;
        }
    }
}

// -------------------- TEXT & MEDIA INPUT HANDLER --------------------
$waiting = (string)getTemp($user_id, 'waiting', '');

// Photo / Screenshot upload handler for payment proof
if (!empty($photoArray)) {
    if ($waiting === 'payment_proof') {
        $orderId = (string)getTemp($user_id, 'proof_order_id', '');
        $orders = loadJson('orders.json');

        if (!isset($orders[$orderId])) {
            sendMessage($chat_id, "❌ Payment order not found or expired. Please create a new payment request.", btn([[["💰 Add Balance", "addbal"]]]));
            clearTemp($user_id);
            exit;
        }

        if ($orders[$orderId]['status'] === 'paid') {
            sendMessage($chat_id, "✅ This order has already been approved and credited.");
            clearTemp($user_id);
            exit;
        }

        // Get highest resolution photo file_id
        $bestPhoto = end($photoArray);
        $fileId = $bestPhoto['file_id'] ?? '';

        $orders[$orderId]['status'] = 'proof_submitted';
        $orders[$orderId]['proof_file_id'] = $fileId;
        $orders[$orderId]['proof_time'] = time();
        saveJson('orders.json', $orders);
        clearTemp($user_id);

        // Confirm to User
        sendMessage($chat_id, "✅ <b>Payment Proof Received!</b>\n\nOrder ID: <code>{$orderId}</code>\nAmount: ₹{$orders[$orderId]['amount']}\n\nOur team is verifying your payment. Your balance will be credited within a few minutes!", btn([[["« Back to Menu", "back"]]]));

        // Forward to Admin
        $users = loadJson('users.json');
        $uName = safeHtml($users[$user_id]['name'] ?? 'User');
        $uUsername = !empty($users[$user_id]['username']) ? "@" . safeHtml($users[$user_id]['username']) : "N/A";
        $amount = $orders[$orderId]['amount'];
        $upiId = safeHtml($orders[$orderId]['upi_id']);

        $adminCaption = "💳 <b>NEW PAYMENT PROOF RECEIVED!</b>\n\n"
                      . "👤 <b>User:</b> {$uName} ({$uUsername})\n"
                      . "🆔 <b>User ID:</b> <code>{$user_id}</code>\n"
                      . "💰 <b>Amount:</b> ₹{$amount}.00\n"
                      . "🆔 <b>Order ID:</b> <code>{$orderId}</code>\n"
                      . "💳 <b>Paid To UPI:</b> <code>{$upiId}</code>\n"
                      . "⏰ <b>Time:</b> " . date("d M Y H:i:s");

        $adminKb = [
            [["✅ APPROVE & CREDIT", "payapp_{$orderId}"], ["❌ REJECT", "payrej_{$orderId}"]]
        ];

        sendPhoto(ADMIN_ID, $fileId, $adminCaption, btn($adminKb));
        exit;
    }
}

// Admin Broadcast Media Handler
if ($user_id === ADMIN_ID && $waiting === 'broadcast_text') {
    $caption = $update["message"]["caption"] ?? $text;
    $users = loadJson('users.json');
    $sent = 0;
    $failed = 0;

    if (!empty($photoArray)) {
        $bestPhoto = end($photoArray);
        $fileId = $bestPhoto['file_id'];
        foreach ($users as $uid => $u) {
            $res = sendPhoto($uid, $fileId, "📢 <b>Announcement</b>\n\n" . safeHtml($caption));
            if ($res !== false) $sent++; else $failed++;
            usleep(40000);
        }
    } elseif (isset($update["message"]["video"])) {
        $fileId = $update["message"]["video"]["file_id"];
        foreach ($users as $uid => $u) {
            $res = sendVideo($uid, $fileId, "📢 <b>Announcement</b>\n\n" . safeHtml($caption));
            if ($res !== false) $sent++; else $failed++;
            usleep(40000);
        }
    } elseif (isset($update["message"]["voice"])) {
        $fileId = $update["message"]["voice"]["file_id"];
        foreach ($users as $uid => $u) {
            $res = sendVoice($uid, $fileId, "📢 <b>Announcement</b>\n\n" . safeHtml($caption));
            if ($res !== false) $sent++; else $failed++;
            usleep(40000);
        }
    } elseif (isset($update["message"]["document"])) {
        $fileId = $update["message"]["document"]["file_id"];
        foreach ($users as $uid => $u) {
            $res = sendDocument($uid, $fileId, "📢 <b>Announcement</b>\n\n" . safeHtml($caption));
            if ($res !== false) $sent++; else $failed++;
            usleep(40000);
        }
    } elseif ($text !== "") {
        foreach ($users as $uid => $u) {
            $res = sendMessage($uid, "📢 <b>Announcement</b>\n\n" . safeHtml($text));
            if ($res !== false) $sent++; else $failed++;
            usleep(40000);
        }
    }

    clearTemp($user_id);
    sendMessage($chat_id, "✅ <b>Broadcast Completed!</b>\n\nTotal: " . count($users) . "\n✅ Sent: {$sent}\n❌ Failed: {$failed}", btn([[["⬅️ Back to Admin", "backadmin"]]]));
    exit;
}

// -------------------- ADMIN TEXT STATES --------------------
if ($user_id === ADMIN_ID && $text !== "") {

    // 1. Add Category
    if ($waiting === "newcat") {
        $cid = "c" . time() . rand(10, 99);
        $categories = loadJson('categories.json');
        $categories[$cid] = ["name" => $text];
        saveJson('categories.json', $categories);
        clearTemp($user_id);
        sendMessage($chat_id, "✅ <b>Category Added:</b> " . safeHtml($text));
        sendAdminPanel($chat_id, 0);
        exit;
    }

    // 2. Add Product Name
    if ($waiting === "prod_name") {
        $cid = (string)getTemp($user_id, 'addprod_cat', '');
        $categories = loadJson('categories.json');

        if ($cid === '' || !isset($categories[$cid])) {
            sendMessage($chat_id, "❌ <b>Category nahi mili ya expire ho gayi!</b> Dubara koshish karein.", btn([[["⬅️ Back to Admin", "backadmin"]]]));
            clearTemp($user_id);
            exit;
        }

        $pid = "p" . time() . rand(10, 99);
        $products = loadJson('products.json');
        $products[$pid] = [
            "name"  => $text,
            "cat"   => $cid,
            "plans" => []
        ];
        saveJson('products.json', $products);
        clearTemp($user_id);

        sendMessage($chat_id, "✅ <b>Product Added!</b>\n\nName: <b>" . safeHtml($text) . "</b>\nCategory: <b>" . safeHtml($categories[$cid]['name']) . "</b>\n\nAb /admin me jaakar '➕ Add Plan' se plans add karein.");
        sendAdminPanel($chat_id, 0);
        exit;
    }

    // 3. Add Plan Days
    if ($waiting === "plan_days") {
        $days = (int)$text;
        if ($days <= 0) {
            sendMessage($chat_id, "❌ Please enter a valid number of days (e.g. 1, 3, 7, 30):");
            exit;
        }
        saveTemp($user_id, 'plan_days', $days);
        saveTemp($user_id, 'waiting', 'plan_price');
        sendMessage($chat_id, "💰 <b>Is plan ka Price kya hai (₹)?</b>\n\nExample: 199");
        exit;
    }

    // 4. Add Plan Price
    if ($waiting === "plan_price") {
        $price = (int)$text;
        if ($price <= 0) {
            sendMessage($chat_id, "❌ Please enter a valid price (e.g. 199):");
            exit;
        }

        $pid = (string)getTemp($user_id, 'addplan_pid', '');
        $days = (int)getTemp($user_id, 'plan_days', 1);

        $products = loadJson('products.json');
        if (!isset($products[$pid])) {
            sendMessage($chat_id, "❌ Product not found!", btn([[["⬅️ Back", "backadmin"]]]));
            clearTemp($user_id);
            exit;
        }

        if (!isset($products[$pid]['plans']) || !is_array($products[$pid]['plans'])) {
            $products[$pid]['plans'] = [];
        }

        $products[$pid]['plans'][] = [
            "days"  => $days,
            "price" => $price,
            "keys"  => []
        ];
        saveJson('products.json', $products);
        clearTemp($user_id);

        sendMessage($chat_id, "✅ <b>Plan Added Successfully!</b>\n\nProduct: " . safeHtml($products[$pid]['name']) . "\nDays: {$days}\nPrice: ₹{$price}\n\nAb keys add karne ke liye /admin me '🔑 Add Plan Keys' use karein.");
        sendAdminPanel($chat_id, 0);
        exit;
    }

    // 5. Add Plan Keys
    if ($waiting === "add_plan_keys") {
        $lines = array_filter(array_map('trim', explode("\n", $text)));
        if (empty($lines)) {
            sendMessage($chat_id, "❌ Koi key nahi mili. Kripya valid keys bhejein:");
            exit;
        }

        $pid = (string)getTemp($user_id, 'addkeys_pid', '');
        $planIndex = (int)getTemp($user_id, 'addkeys_plan_index', -1);

        $products = loadJson('products.json');
        if (!isset($products[$pid]['plans'][$planIndex])) {
            sendMessage($chat_id, "❌ Plan not found!", btn([[["⬅️ Back", "backadmin"]]]));
            clearTemp($user_id);
            exit;
        }

        if (!isset($products[$pid]['plans'][$planIndex]['keys']) || !is_array($products[$pid]['plans'][$planIndex]['keys'])) {
            $products[$pid]['plans'][$planIndex]['keys'] = [];
        }

        $products[$pid]['plans'][$planIndex]['keys'] = array_merge(
            $products[$pid]['plans'][$planIndex]['keys'],
            array_values($lines)
        );
        saveJson('products.json', $products);
        clearTemp($user_id);

        $plan = $products[$pid]['plans'][$planIndex];
        $totalKeys = count($plan['keys']);
        sendMessage($chat_id, "✅ <b>" . count($lines) . " Keys Added!</b>\n\nProduct: " . safeHtml($products[$pid]['name']) . "\nPlan: {$plan['days']} Days\nTotal Keys in Stock: {$totalKeys}");
        sendAdminPanel($chat_id, 0);
        exit;
    }

    // 6. Edit Product Name
    if ($waiting === "edit_name") {
        $pid = (string)getTemp($user_id, 'edit_pid', '');
        $products = loadJson('products.json');
        if (isset($products[$pid])) {
            $products[$pid]['name'] = $text;
            saveJson('products.json', $products);
            sendMessage($chat_id, "✅ <b>Product Name Updated:</b> " . safeHtml($text));
        }
        clearTemp($user_id);
        sendAdminPanel($chat_id, 0);
        exit;
    }

    // 7. Edit Plan Days
    if ($waiting === "edit_plan_days") {
        $pid = (string)getTemp($user_id, 'edit_pid', '');
        $planIndex = (int)getTemp($user_id, 'edit_plan_index', -1);
        $products = loadJson('products.json');
        if (isset($products[$pid]['plans'][$planIndex])) {
            $products[$pid]['plans'][$planIndex]['days'] = (int)$text;
            saveJson('products.json', $products);
            sendMessage($chat_id, "✅ <b>Plan Days Updated:</b> " . (int)$text);
        }
        clearTemp($user_id);
        sendAdminPanel($chat_id, 0);
        exit;
    }

    // 8. Edit Plan Price
    if ($waiting === "edit_plan_price") {
        $pid = (string)getTemp($user_id, 'edit_pid', '');
        $planIndex = (int)getTemp($user_id, 'edit_plan_index', -1);
        $products = loadJson('products.json');
        if (isset($products[$pid]['plans'][$planIndex])) {
            $products[$pid]['plans'][$planIndex]['price'] = (int)$text;
            saveJson('products.json', $products);
            sendMessage($chat_id, "✅ <b>Plan Price Updated:</b> ₹" . (int)$text);
        }
        clearTemp($user_id);
        sendAdminPanel($chat_id, 0);
        exit;
    }

    // 9. Edit Plan Keys (Append)
    if ($waiting === "edit_plan_keys") {
        $lines = array_filter(array_map('trim', explode("\n", $text)));
        $pid = (string)getTemp($user_id, 'edit_pid', '');
        $planIndex = (int)getTemp($user_id, 'edit_plan_index', -1);

        $products = loadJson('products.json');
        if (isset($products[$pid]['plans'][$planIndex])) {
            if (!isset($products[$pid]['plans'][$planIndex]['keys'])) {
                $products[$pid]['plans'][$planIndex]['keys'] = [];
            }
            $products[$pid]['plans'][$planIndex]['keys'] = array_merge(
                $products[$pid]['plans'][$planIndex]['keys'],
                array_values($lines)
            );
            saveJson('products.json', $products);
            sendMessage($chat_id, "✅ <b>" . count($lines) . " Keys Added to Stock!</b>");
        }
        clearTemp($user_id);
        sendAdminPanel($chat_id, 0);
        exit;
    }

    // 10. Set UPI ID
    if ($waiting === "set_upi_id") {
        $cleanUpi = trim($text);
        $settings = loadJson('settings.json');
        $settings['upi_id'] = $cleanUpi;
        saveJson('settings.json', $settings);
        clearTemp($user_id);
        sendMessage($chat_id, "✅ <b>UPI ID Updated:</b> <code>" . safeHtml($cleanUpi) . "</code>");
        sendAdminPanel($chat_id, 0);
        exit;
    }

    // 11. Set UPI Name
    if ($waiting === "set_upi_name") {
        $cleanName = trim($text);
        $settings = loadJson('settings.json');
        $settings['upi_name'] = $cleanName;
        saveJson('settings.json', $settings);
        clearTemp($user_id);
        sendMessage($chat_id, "✅ <b>UPI Receiver Name Updated:</b> <code>" . safeHtml($cleanName) . "</code>");
        sendAdminPanel($chat_id, 0);
        exit;
    }

    // 12. Set Proof Link
    if ($waiting === "proof") {
        $settings = loadJson('settings.json');
        $settings['proof_link'] = trim($text);
        saveJson('settings.json', $settings);
        clearTemp($user_id);
        sendMessage($chat_id, "✅ <b>Proof Link Updated:</b> " . safeHtml($text));
        sendAdminPanel($chat_id, 0);
        exit;
    }

    // 13. Set HowTo Link
    if ($waiting === "howto") {
        $settings = loadJson('settings.json');
        $settings['howto_link'] = trim($text);
        saveJson('settings.json', $settings);
        clearTemp($user_id);
        sendMessage($chat_id, "✅ <b>HowTo Link Updated:</b> " . safeHtml($text));
        sendAdminPanel($chat_id, 0);
        exit;
    }

    // 14. Set Support User
    if ($waiting === "support") {
        $settings = loadJson('settings.json');
        $settings['support_user'] = trim($text);
        saveJson('settings.json', $settings);
        clearTemp($user_id);
        sendMessage($chat_id, "✅ <b>Support Username Updated:</b> " . safeHtml($text));
        sendAdminPanel($chat_id, 0);
        exit;
    }

    // 15. Add User Balance - Stage 1 (User ID)
    if ($waiting === "adduserbal_id") {
        $targetUid = trim($text);
        $users = loadJson('users.json');
        if (!isset($users[$targetUid])) {
            sendMessage($chat_id, "❌ <b>User ID <code>{$targetUid}</code> nahi mila!</b>\nCheck /admin -> User List.", btn([[["⬅️ Back to Admin", "backadmin"]]]));
            exit;
        }
        saveTemp($user_id, 'target_user_id', $targetUid);
        saveTemp($user_id, 'waiting', 'adduserbal_amount');
        sendMessage($chat_id, "💰 <b>Target User:</b> " . safeHtml($users[$targetUid]['name']) . " (<code>{$targetUid}</code>)\n\nKitna balance add karna hai (₹)?\nExample: <code>100</code>", btn([[["⬅️ Cancel", "backadmin"]]]));
        exit;
    }

    // 16. Add User Balance - Stage 2 (Amount)
    if ($waiting === "adduserbal_amount") {
        $amount = (int)$text;
        if ($amount <= 0) {
            sendMessage($chat_id, "❌ Amount 0 se zyada hona chahiye!");
            exit;
        }

        $targetUid = (string)getTemp($user_id, 'target_user_id', '');
        $users = loadJson('users.json');
        if (!isset($users[$targetUid])) {
            sendMessage($chat_id, "❌ Target user lost!", btn([[["⬅️ Back", "backadmin"]]]));
            clearTemp($user_id);
            exit;
        }

        $newBal = modifyUserBalance($targetUid, $amount);
        clearTemp($user_id);

        sendMessage($chat_id, "✅ <b>Balance Added!</b>\n\nUser: " . safeHtml($users[$targetUid]['name']) . "\nID: <code>{$targetUid}</code>\nAdded: ₹{$amount}\nNew Balance: ₹{$newBal}");
        sendMessage($targetUid, "💰 <b>Admin Balance Top-up!</b>\n\n₹{$amount}.00 aapke balance me add kar diya gaya hai.\n💲 <b>New Balance: ₹{$newBal}.00</b>");
        sendAdminPanel($chat_id, 0);
        exit;
    }

    // 17. Payment Rejection Reason
    if ($waiting === "pay_reject_reason") {
        $orderId = (string)getTemp($user_id, 'target_order', '');
        $reason = trim($text);
        $orders = loadJson('orders.json');

        if (isset($orders[$orderId])) {
            $orders[$orderId]['status'] = 'rejected';
            $orders[$orderId]['rejected_at'] = time();
            $orders[$orderId]['rejection_reason'] = $reason;
            saveJson('orders.json', $orders);

            $targetUid = $orders[$orderId]['user_id'];
            $amount = $orders[$orderId]['amount'];

            sendMessage($chat_id, "❌ <b>Payment Order {$orderId} has been rejected.</b>");
            sendMessage($targetUid, "❌ <b>Payment Rejected</b>\n\nOrder ID: <code>{$orderId}</code>\nAmount: ₹{$amount}\nReason: <i>" . safeHtml($reason) . "</i>\n\nIf you have any doubt, please contact Support.");
        }
        clearTemp($user_id);
        sendAdminPanel($chat_id, 0);
        exit;
    }

    // 18. Key Delivery - Step 1 (Key received, ask for Download Link)
    if ($waiting === "deliver_key") {
        $orderId = (string)getTemp($user_id, 'target_order', '');
        $deliveredKey = trim($text);

        saveTemp($user_id, 'delivered_key', $deliveredKey);
        saveTemp($user_id, 'waiting', 'deliver_download_link');

        sendMessage($chat_id, "🔗 <b>Ab Download Link bhejo:</b>\n\nOrder ID: <code>{$orderId}</code>\nKey: <code>" . safeHtml($deliveredKey) . "</code>\n\n<i>(Agar koi download link nahi hai to '-' bhejein)</i>", btn([[["⬅️ Cancel", "backadmin"]]]));
        exit;
    }

    // 19. Key Delivery - Step 2 (Download link received, complete order)
    if ($waiting === "deliver_download_link") {
        $orderId = (string)getTemp($user_id, 'target_order', '');
        $deliveredKey = (string)getTemp($user_id, 'delivered_key', '');
        $downloadLink = trim($text);

        $orders = loadJson('orders.json');
        if (!isset($orders[$orderId])) {
            sendMessage($chat_id, "❌ Order not found!");
            clearTemp($user_id);
            exit;
        }

        $order = $orders[$orderId];
        $targetUid = (string)$order['user_id'];

        $orders[$orderId]['status'] = 'Delivered';
        $orders[$orderId]['key'] = $deliveredKey;
        $orders[$orderId]['download_link'] = $downloadLink;
        $orders[$orderId]['delivered_at'] = time();
        saveJson('orders.json', $orders);
        clearTemp($user_id);

        sendMessage($chat_id, "✅ <b>Order {$orderId} Delivered Successfully!</b>");

        // Notify User
        $userMsg = "🎉 ———— <b>ORDER COMPLETED!</b> ———— 🎉\n\n"
                 . "📦 <b>Product:</b> " . safeHtml($order['product_name']) . "\n"
                 . "📅 <b>Plan:</b> {$order['days']} Days\n"
                 . "🆔 <b>Order ID:</b> <code>{$orderId}</code>\n\n"
                 . "🔑 <b>YOUR LICENSE KEY:</b>\n"
                 . "<code>" . safeHtml($deliveredKey) . "</code>\n\n";

        if ($downloadLink !== '-' && !empty($downloadLink)) {
            $userMsg .= "🔗 <b>Download / Setup Link:</b>\n<a href='" . safeHtml($downloadLink) . "'>" . safeHtml($downloadLink) . "</a>\n\n";
        }

        $userMsg .= "⭐️ <i>Thank you for choosing FRENZY LICENCE!</i>";
        sendMessage($targetUid, $userMsg, btn([[["📦 My Orders", "orders"]], [["« Back to Menu", "back"]]]));

        sendAdminPanel($chat_id, 0);
        exit;
    }

    // 20. Key Order Rejection & Auto Refund
    if ($waiting === "order_reject_reason") {
        $orderId = (string)getTemp($user_id, 'target_order', '');
        $reason = trim($text);
        $orders = loadJson('orders.json');

        if (!isset($orders[$orderId])) {
            sendMessage($chat_id, "❌ Order not found!");
            clearTemp($user_id);
            exit;
        }

        $order = $orders[$orderId];
        if (($order['status'] ?? '') === 'Rejected' || ($order['refunded'] ?? false)) {
            sendMessage($chat_id, "⚠️ Order is already rejected/refunded!");
            clearTemp($user_id);
            exit;
        }

        $targetUid = (string)$order['user_id'];
        $price = (int)($order['price'] ?? 0);

        // Refund exact price to balance
        $newBal = modifyUserBalance($targetUid, $price);

        $orders[$orderId]['status'] = 'Rejected';
        $orders[$orderId]['refunded'] = true;
        $orders[$orderId]['rejected_at'] = time();
        $orders[$orderId]['rejection_reason'] = $reason;
        saveJson('orders.json', $orders);
        clearTemp($user_id);

        sendMessage($chat_id, "❌ <b>Order {$orderId} Rejected!</b>\n₹{$price} has been refunded to User <code>{$targetUid}</code>.");

        $userMsg = "❌ <b>Order Rejected & Refunded</b>\n\n"
                 . "📦 <b>Product:</b> " . safeHtml($order['product_name']) . "\n"
                 . "🆔 <b>Order ID:</b> <code>{$orderId}</code>\n"
                 . "💰 <b>Refund Amount:</b> ₹{$price}.00 (Credited back)\n"
                 . "💲 <b>Current Balance:</b> ₹{$newBal}.00\n"
                 . "📝 <b>Reason:</b> <i>" . safeHtml($reason) . "</i>\n\n"
                 . "Contact support if you need further assistance.";
        sendMessage($targetUid, $userMsg, btn([[["« Menu", "back"]]]));

        sendAdminPanel($chat_id, 0);
        exit;
    }
}