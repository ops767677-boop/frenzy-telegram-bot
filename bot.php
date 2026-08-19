<?php
/**
 * =====================================================================
 * ⚡ FRENZY STORE - Telegram Bot Backend (Fixed & Fully Updated)
 * =====================================================================
 */

declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '0'); // Never leak raw errors

// ---------------------------------------------------------------------
// 1. CONFIGURATION
// ---------------------------------------------------------------------
const BOT_TOKEN  = '8965830768:AAFVs8RxGGwnLwIW8n1msmD0NUQqwzUIRpA';
const ADMIN_ID   = '8047005584';
const STORE_NAME = '⚡ FRENZY STORE ⚡';
const DB_FILE    = __DIR__ . '/frenzy_store.sqlite';

const WEBHOOK_SECRET = ''; // Optional webhook secret token

// Payment configuration
$upiId        = 'sahid.frenzy@fam';
$paymentName  = 'Frenzy Store'; // Fixed string quotes
$qrImageUrl   = ''; 

// Product catalog
$PRODUCTS = [
    99  => ['name' => 'Premium Digital Product', 'emoji' => '👑', 'tag' => '🔥 POPULAR'],
    199 => ['name' => 'VIP Digital Product',      'emoji' => '💎', 'tag' => '✨ VIP'],
    499 => ['name' => 'Ultimate Package',         'emoji' => '⚡', 'tag' => '🚀 BEST VALUE'],
];

// ---------------------------------------------------------------------
// 2. DATABASE
// ---------------------------------------------------------------------
function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $pdo = new PDO('sqlite:' . DB_FILE);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('PRAGMA foreign_keys = ON');
        initSchema($pdo);
    }
    return $pdo;
}

function initSchema(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS users (
        id          INTEGER PRIMARY KEY AUTOINCREMENT,
        telegram_id TEXT UNIQUE NOT NULL,
        username    TEXT,
        first_name  TEXT,
        state       TEXT DEFAULT 'idle',
        created_at  TEXT DEFAULT CURRENT_TIMESTAMP
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS orders (
        id          INTEGER PRIMARY KEY AUTOINCREMENT,
        order_id    TEXT UNIQUE NOT NULL,
        telegram_id TEXT NOT NULL,
        product     TEXT NOT NULL,
        amount      TEXT NOT NULL,
        status      TEXT NOT NULL DEFAULT 'created',
        utr         TEXT,
        delivery    TEXT,
        created_at  TEXT DEFAULT CURRENT_TIMESTAMP,
        updated_at  TEXT DEFAULT CURRENT_TIMESTAMP
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS support_messages (
        id          INTEGER PRIMARY KEY AUTOINCREMENT,
        telegram_id TEXT NOT NULL,
        message     TEXT NOT NULL,
        created_at  TEXT DEFAULT CURRENT_TIMESTAMP
    )");

    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_orders_telegram_id ON orders(telegram_id)");
}

// ---------------------------------------------------------------------
// 3. TELEGRAM API HELPER
// ---------------------------------------------------------------------
function tg(string $method, array $data = []): ?array
{
    $url = 'https://api.telegram.org/bot' . BOT_TOKEN . '/' . $method;

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($data),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
    ]);

    $response = curl_exec($ch);
    $curlErr  = curl_error($ch);
    curl_close($ch);

    if ($response === false) {
        error_log('Telegram cURL error on ' . $method . ': ' . $curlErr);
        return null;
    }

    $decoded = json_decode($response, true);
    if (!is_array($decoded) || empty($decoded['ok'])) {
        error_log('Telegram API error on ' . $method . ': ' . $response);
        return null;
    }
    return $decoded;
}

function sendMessage(string $chatId, string $text, ?array $keyboard = null): void
{
    $payload = [
        'chat_id'    => $chatId,
        'text'       => $text,
        'parse_mode' => 'HTML',
    ];
    if ($keyboard !== null) {
        $payload['reply_markup'] = json_encode(['inline_keyboard' => $keyboard]);
    }
    tg('sendMessage', $payload);
}

function answerCallback(string $callbackId, string $text = '', bool $alert = false): void
{
    tg('answerCallbackQuery', [
        'callback_query_id' => $callbackId,
        'text'              => $text,
        'show_alert'        => $alert,
    ]);
}

// ---------------------------------------------------------------------
// 4. UTILITIES
// ---------------------------------------------------------------------
function h(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

function isAdmin(string $telegramId): bool
{
    return hash_equals(ADMIN_ID, $telegramId);
}

function generateOrderId(): string
{
    return 'FRENZY-' . strtoupper(bin2hex(random_bytes(3)));
}

function friendlyError(string $chatId): void
{
    sendMessage($chatId, "⚠️ <b>Oops! Something went wrong.</b> 🤖💥\n\nPlease try again or reach out to support.");
}

// ---------------------------------------------------------------------
// 5. USER & ORDER HELPERS
// ---------------------------------------------------------------------
function upsertUser(string $telegramId, ?string $username, ?string $firstName): void
{
    $pdo = db();
    $stmt = $pdo->prepare('SELECT id FROM users WHERE telegram_id = ?');
    $stmt->execute([$telegramId]);

    if ($stmt->fetch()) {
        $upd = $pdo->prepare('UPDATE users SET username = ?, first_name = ? WHERE telegram_id = ?');
        $upd->execute([$username, $firstName, $telegramId]);
    } else {
        $ins = $pdo->prepare('INSERT INTO users (telegram_id, username, first_name) VALUES (?, ?, ?)');
        $ins->execute([$telegramId, $username, $firstName]);
    }
}

function getUserState(string $telegramId): string
{
    $stmt = db()->prepare('SELECT state FROM users WHERE telegram_id = ?');
    $stmt->execute([$telegramId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row['state'] ?? 'idle';
}

function setUserState(string $telegramId, string $state): void
{
    $stmt = db()->prepare('UPDATE users SET state = ? WHERE telegram_id = ?');
    $stmt->execute([$state, $telegramId]);
}

function createOrder(string $telegramId, string $productName, string $amount): array
{
    $orderId = generateOrderId();
    $stmt = db()->prepare(
        'INSERT INTO orders (order_id, telegram_id, product, amount, status) VALUES (?, ?, ?, ?, ?)'
    );
    $stmt->execute([$orderId, $telegramId, $productName, $amount, 'pending_payment']);
    return getOrder($orderId);
}

function getOrder(string $orderId): ?array
{
    $stmt = db()->prepare('SELECT * FROM orders WHERE order_id = ?');
    $stmt->execute([$orderId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function updateOrderStatus(string $orderId, string $status, ?string $extraCol = null, ?string $extraVal = null): void
{
    if ($extraCol !== null && in_array($extraCol, ['utr', 'delivery'], true)) {
        $stmt = db()->prepare("UPDATE orders SET status = ?, {$extraCol} = ?, updated_at = CURRENT_TIMESTAMP WHERE order_id = ?");
        $stmt->execute([$status, $extraVal, $orderId]);
    } else {
        $stmt = db()->prepare('UPDATE orders SET status = ?, updated_at = CURRENT_TIMESTAMP WHERE order_id = ?');
        $stmt->execute([$status, $orderId]);
    }
}

function statusLabel(string $status): string
{
    return match ($status) {
        'created'               => '✨ Created',
        'pending_payment'       => '⏳ Payment Pending',
        'pending_verification'  => '🔎 Waiting Admin Verification',
        'paid'                  => '✅ Payment Verified',
        'rejected'              => '❌ Payment Rejected',
        'completed'             => '🎉 Order Delivered',
        'cancelled'             => '🚫 Cancelled',
        default                 => ucfirst($status),
    };
}

// ---------------------------------------------------------------------
// 6. KEYBOARDS & SCREENS (Animated Emojis UI)
// ---------------------------------------------------------------------
function mainMenuKeyboard(): array
{
    return [
        [['text' => '🛍️ Browse Store 🔥', 'callback_data' => 'store'], ['text' => '📦 My Orders 🚚', 'callback_data' => 'orders']],
        [['text' => '💳 Payment Info 💸', 'callback_data' => 'payment_help'], ['text' => '🎟️ Support 🎧', 'callback_data' => 'support']],
        [['text' => '👤 Profile 🌟', 'callback_data' => 'profile']],
    ];
}

function backKeyboard(string $target = 'home'): array
{
    return [[['text' => '🔙 Back to Home', 'callback_data' => $target]]];
}

function showHome(string $chatId, string $firstName): void
{
    $text = "👋 <b>Hey, " . h($firstName) . "! Welcome aboard!</b> ✨\n\n"
          . "🚀 Welcome to <b>" . h(STORE_NAME) . "</b>\n"
          . "──────────────────────────────\n"
          . "💎 <b>Premium Digital Products</b>\n"
          . "⚡ <b>Instant & Superfast Delivery</b>\n"
          . "🔒 <b>100% Safe & Secure Checkout</b>\n"
          . "🎧 <b>24/7 Dedicated Support</b>\n"
          . "──────────────────────────────\n"
          . "👇 <i>Select an option below to start exploring!</i>";
    sendMessage($chatId, $text, mainMenuKeyboard());
}

function showStore(string $chatId): void
{
    global $PRODUCTS;
    $text = "🛒 <b>" . h(STORE_NAME) . " — Catalog</b> ✨\n\nChoose your desired product below:";
    $rows = [];
    foreach ($PRODUCTS as $price => $p) {
        $rows[] = [[
            'text' => $p['emoji'] . ' ' . $p['name'] . ' — ₹' . $price . ' (' . $p['tag'] . ')',
            'callback_data' => 'buy_' . $price,
        ]];
    }
    $rows[] = [['text' => '🔙 Back to Menu', 'callback_data' => 'home']];
    sendMessage($chatId, $text, $rows);
}

function showProductConfirm(string $chatId, int $price): void
{
    global $PRODUCTS;
    if (!isset($PRODUCTS[$price])) {
        sendMessage($chatId, '⚠️ Product not available.', backKeyboard('store'));
        return;
    }
    $p = $PRODUCTS[$price];
    $text = "🎯 <b>Order Confirmation</b> 🛒\n\n"
          . "📦 <b>Product:</b> " . $p['emoji'] . " " . h($p['name']) . "\n"
          . "💰 <b>Price:</b> ₹" . $price . "\n"
          . "⚡ <b>Delivery:</b> Instant after payment check\n\n"
          . "Are you sure you want to proceed?";
    $kb = [
        [['text' => '✅ Confirm & Proceed', 'callback_data' => 'confirm_buy_' . $price]],
        [['text' => '❌ Cancel', 'callback_data' => 'cancel_order']],
    ];
    sendMessage($chatId, $text, $kb);
}

function showPaymentScreen(string $chatId, array $order): void
{
    $text = "💳 <b>Checkout & Payment</b> 💸\n\n"
          . "🆔 <b>Order ID:</b> <code>" . h($order['order_id']) . "</code>\n"
          . "💵 <b>Amount Due:</b> ₹" . h($order['amount']) . "\n"
          . "📌 <b>Status:</b> " . statusLabel($order['status']) . "\n\n"
          . "👇 <i>Click instructions below to make payment!</i>";
    $kb = [
        [['text' => '📖 How to Pay (UPI/QR)', 'callback_data' => 'payinfo_' . $order['order_id']]],
        [['text' => '📤 Submit UTR / Ref No.', 'callback_data' => 'subutr_' . $order['order_id']]],
        [['text' => '❌ Cancel Order', 'callback_data' => 'cancel_order']],
    ];
    sendMessage($chatId, $text, $kb);
}

function showPaymentInstructions(string $chatId, array $order): void
{
    global $upiId, $paymentName, $qrImageUrl;

    $text = "📲 <b>UPI Payment Instructions</b> 💸\n\n"
          . "🆔 <b>Order:</b> <code>" . h($order['order_id']) . "</code>\n"
          . "💰 <b>Amount:</b> <b>₹" . h($order['amount']) . "</b>\n\n"
          . "👇 <b>Pay using UPI ID:</b>\n"
          . "<code>" . h($upiId) . "</code>\n"
          . "👤 <b>Name:</b> " . h($paymentName) . "\n\n"
          . "⚡ <b>Steps:</b>\n"
          . "1️⃣ Open Google Pay / PhonePe / Paytm\n"
          . "2️⃣ Pay exact amount ₹" . h($order['amount']) . "\n"
          . "3️⃣ Copy the 12-digit <b>UTR / Transaction ID</b>\n"
          . "4️⃣ Click <b>'Submit UTR'</b> button below and paste it!";

    if ($qrImageUrl !== '') {
        tg('sendPhoto', [
            'chat_id' => $chatId,
            'photo'   => $qrImageUrl,
            'caption' => "📸 Scan QR to pay ₹" . h($order['amount']),
        ]);
    }

    $kb = [
        [['text' => '📤 Submit UTR Now', 'callback_data' => 'subutr_' . $order['order_id']]],
        [['text' => '📦 My Orders', 'callback_data' => 'orders']],
    ];
    sendMessage($chatId, $text, $kb);
}

function showOrders(string $chatId, string $telegramId): void
{
    $stmt = db()->prepare('SELECT * FROM orders WHERE telegram_id = ? ORDER BY created_at DESC LIMIT 15');
    $stmt->execute([$telegramId]);
    $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (!$orders) {
        sendMessage($chatId, "📦 <b>You don't have any orders yet!</b>\n\nExplore our store to make your first purchase 🚀", backKeyboard('home'));
        return;
    }

    $text = "📦 <b>Your Recent Orders</b> 🚚\n──────────────────────────────\n\n";
    foreach ($orders as $o) {
        $text .= "🆔 <b>Order #" . h($o['order_id']) . "</b>\n"
               . "🛍️ " . h($o['product']) . "\n"
               . "💰 Amount: ₹" . h($o['amount']) . "\n"
               . "📌 Status: " . statusLabel($o['status']) . "\n";
        if ($o['status'] === 'completed' && !empty($o['delivery'])) {
            $text .= "🔑 <b>Item:</b> <code>" . h($o['delivery']) . "</code>\n";
        }
        $text .= "──────────────────────────────\n";
    }
    sendMessage($chatId, trim($text), backKeyboard('home'));
}

function showProfile(string $chatId, string $telegramId, ?string $username, ?string $firstName): void
{
    $stmt = db()->prepare('SELECT status FROM orders WHERE telegram_id = ?');
    $stmt->execute([$telegramId]);
    $statuses = $stmt->fetchAll(PDO::FETCH_COLUMN);

    $total     = count($statuses);
    $completed = count(array_filter($statuses, fn($s) => $s === 'completed'));
    $pending   = count(array_filter($statuses, fn($s) => in_array($s, ['pending_payment', 'pending_verification'], true)));

    $text = "👤 <b>User Dashboard Profile</b> 🌟\n\n"
          . "🆔 <b>Telegram ID:</b> <code>" . h($telegramId) . "</code>\n"
          . "👤 <b>Name:</b> " . h((string)$firstName) . "\n"
          . "🏷️ <b>Username:</b> " . ($username ? '@' . h($username) : 'N/A') . "\n\n"
          . "📊 <b>Activity Stats:</b>\n"
          . "▫️ Total Orders: <b>" . $total . "</b>\n"
          . "✅ Completed: <b>" . $completed . "</b>\n"
          . "⏳ Pending: <b>" . $pending . "</b>";
    sendMessage($chatId, $text, backKeyboard('home'));
}

function showSupportPrompt(string $chatId): void
{
    sendMessage(
        $chatId,
        "🎟️ <b>Customer Support Desk</b> 🎧\n\nPlease type your message or query below. Our admin team will reply directly to your chat shortly! ✨",
        backKeyboard('home')
    );
}

function showPaymentHelp(string $chatId): void
{
    $text = "💳 <b>Payment Guide & FAQ</b> 💡\n\n"
          . "1️⃣ Select product from store catalog.\n"
          . "2️⃣ Pay via UPI using provided UPI ID or QR Code.\n"
          . "3️⃣ Copy the 12-digit UTR/Reference ID from your payment app.\n"
          . "4️⃣ Submit the UTR to the bot.\n"
          . "5️⃣ Admin verifies transaction & delivers your key/product instantly! 🔥";
    sendMessage($chatId, $text, backKeyboard('home'));
}

// ---------------------------------------------------------------------
// 7. ADMIN FUNCTIONS
// ---------------------------------------------------------------------
function notifyAdminOfPayment(array $order, ?string $username): void
{
    $text = "🚨 <b>NEW PAYMENT SUBMISSION!</b> 💸\n\n"
          . "👤 <b>User:</b> " . ($username ? '@' . h($username) : 'N/A') . "\n"
          . "🆔 <b>User ID:</b> <code>" . h($order['telegram_id']) . "</code>\n"
          . "📦 <b>Product:</b> " . h($order['product']) . "\n"
          . "💰 <b>Amount:</b> ₹" . h($order['amount']) . "\n"
          . "🧾 <b>UTR Code:</b> <code>" . h((string)$order['utr']) . "</code>\n"
          . "⏳ <b>Status:</b> Awaiting Approval";
    $kb = [[
        ['text' => '✅ Approve Payment', 'callback_data' => 'approve_' . $order['order_id']],
        ['text' => '❌ Reject Payment',  'callback_data' => 'reject_' . $order['order_id']],
    ]];
    sendMessage(ADMIN_ID, $text, $kb);
}

function notifyAdminOfSupport(string $telegramId, ?string $username, string $message): void
{
    $text = "📩 <b>New Support Ticket</b> 🎟️\n\n"
          . "👤 User: " . ($username ? '@' . h($username) : 'N/A') . "\n"
          . "🆔 ID: <code>" . h($telegramId) . "</code>\n"
          . "💬 Message: " . h($message) . "\n\n"
          . "👉 <b>To reply send:</b>\n<code>/reply " . h($telegramId) . " Your response message</code>";
    sendMessage(ADMIN_ID, $text);
}

function adminApprove(string $orderId): void
{
    $order = getOrder($orderId);
    if (!$order || $order['status'] !== 'pending_verification') {
        sendMessage(ADMIN_ID, "⚠️ Order " . h($orderId) . " is not waiting for verification.");
        return;
    }
    updateOrderStatus($orderId, 'paid');

    sendMessage(
        $order['telegram_id'],
        "🎉 <b>Payment Approved!</b> ✅\n\nYour payment for Order <code>" . h($orderId) . "</code> has been verified!\n\n⚡ <i>Preparing digital delivery...</i>"
    );

    sendMessage(
        ADMIN_ID,
        "✅ <b>Order " . h($orderId) . " marked as PAID!</b>\n\nTo deliver item send:\n<code>/deliver " . h($orderId) . " Your Digital Key/Data</code>"
    );
}

function adminReject(string $orderId): void
{
    $order = getOrder($orderId);
    if (!$order || $order['status'] !== 'pending_verification') {
        sendMessage(ADMIN_ID, "⚠️ Order " . h($orderId) . " is not awaiting verification.");
        return;
    }
    updateOrderStatus($orderId, 'rejected');

    sendMessage(
        $order['telegram_id'],
        "❌ <b>Payment Declined!</b>\n\nYour payment submission for Order <code>" . h($orderId) . "</code> was rejected. If you paid, please contact support."
    );

    sendMessage(ADMIN_ID, "❌ Order " . h($orderId) . " rejected.");
}

function adminDeliver(string $orderId, string $content): void
{
    $order = getOrder($orderId);
    if (!$order || $order['status'] !== 'paid') {
        sendMessage(ADMIN_ID, "⚠️ Cannot deliver. Order non-existent or unpaid.");
        return;
    }
    updateOrderStatus($orderId, 'completed', 'delivery', $content);

    sendMessage(
        $order['telegram_id'],
        "🎉 <b>ORDER DELIVERED!</b> 🎁\n\n"
      . "🛍️ <b>Product:</b> " . h($order['product']) . "\n"
      . "🆔 <b>Order ID:</b> <code>" . h($orderId) . "</code>\n\n"
      . "🔑 <b>Delivery Content / Access Key:</b>\n"
      . "<code>" . h($content) . "</code>\n\n"
      . "✨ <i>Thank you for shopping with us!</i>"
    );

    sendMessage(ADMIN_ID, "🚀 Order " . h($orderId) . " successfully delivered.");
}

function isValidUtr(string $utr): bool
{
    $utr = trim($utr);
    return !empty($utr) && mb_strlen($utr) <= 40 && (bool)preg_match('/^[A-Za-z0-9\- ]+$/', $utr);
}

// ---------------------------------------------------------------------
// 8. ROUTERS (CALLBACK & MESSAGE)
// ---------------------------------------------------------------------
function handleCallback(array $cb): void
{
    global $PRODUCTS;

    $data       = $cb['data'] ?? '';
    $chatId     = (string)$cb['message']['chat']['id'];
    $telegramId = (string)$cb['from']['id'];
    $username   = $cb['from']['username'] ?? null;
    $firstName  = $cb['from']['first_name'] ?? 'there';
    $callbackId = $cb['id'];

    upsertUser($telegramId, $username, $firstName);
    answerCallback($callbackId);

    $known = ['home', 'store', 'orders', 'payment_help', 'support', 'profile', 'cancel_order'];

    if (in_array($data, $known, true)) {
        switch ($data) {
            case 'home':
                setUserState($telegramId, 'idle');
                showHome($chatId, $firstName);
                return;
            case 'store':
                showStore($chatId);
                return;
            case 'orders':
                showOrders($chatId, $telegramId);
                return;
            case 'payment_help':
                showPaymentHelp($chatId);
                return;
            case 'support':
                setUserState($telegramId, 'awaiting_support');
                showSupportPrompt($chatId);
                return;
            case 'profile':
                showProfile($chatId, $telegramId, $username, $firstName);
                return;
            case 'cancel_order':
                setUserState($telegramId, 'idle');
                sendMessage($chatId, '🚫 Order cancelled.', backKeyboard('home'));
                return;
        }
    }

    if (preg_match('/^buy_(\d+)$/', $data, $m)) {
        showProductConfirm($chatId, (int)$m[1]);
        return;
    }

    if (preg_match('/^confirm_buy_(\d+)$/', $data, $m)) {
        $price = (int)$m[1];
        if (!isset($PRODUCTS[$price])) {
            sendMessage($chatId, '⚠️ Product unavailable.', backKeyboard('store'));
            return;
        }
        $order = createOrder($telegramId, $PRODUCTS[$price]['name'], (string)$price);
        showPaymentScreen($chatId, $order);
        return;
    }

    if (preg_match('/^payinfo_(FRENZY-[A-Z0-9]+)$/', $data, $m)) {
        $order = getOrder($m[1]);
        if (!$order || $order['telegram_id'] !== $telegramId) {
            sendMessage($chatId, '⚠️ Order not found.', backKeyboard('orders'));
            return;
        }
        showPaymentInstructions($chatId, $order);
        return;
    }

    if (preg_match('/^subutr_(FRENZY-[A-Z0-9]+)$/', $data, $m)) {
        $order = getOrder($m[1]);
        if (!$order || $order['telegram_id'] !== $telegramId) {
            sendMessage($chatId, '⚠️ Order not found.', backKeyboard('orders'));
            return;
        }
        setUserState($telegramId, 'awaiting_utr:' . $order['order_id']);
        sendMessage($chatId, "✍️ <b>Please send your 12-digit UTR/Reference ID now:</b>");
        return;
    }

    if (preg_match('/^(approve|reject)_(FRENZY-[A-Z0-9]+)$/', $data, $m)) {
        if (!isAdmin($telegramId)) {
            answerCallback($callbackId, 'Unauthorized!', true);
            return;
        }
        if ($m[1] === 'approve') {
            adminApprove($m[2]);
        } else {
            adminReject($m[2]);
        }
        return;
    }
}

function handleMessage(array $msg): void
{
    $chatId     = (string)$msg['chat']['id'];
    $telegramId = (string)($msg['from']['id'] ?? $chatId);
    $username   = $msg['from']['username'] ?? null;
    $firstName  = $msg['from']['first_name'] ?? 'there';
    $text       = trim((string)($msg['text'] ?? ''));

    upsertUser($telegramId, $username, $firstName);

    // Admin commands
    if (isAdmin($telegramId)) {
        if (str_starts_with($text, '/reply ')) {
            $parts = explode(' ', $text, 3);
            if (count($parts) === 3 && ctype_digit($parts[1])) {
                sendMessage($parts[1], "💬 <b>Support Response:</b>\n\n" . h($parts[2]));
                sendMessage($chatId, '✅ Support response delivered.');
            } else {
                sendMessage($chatId, 'Syntax: /reply <user_id> <message>');
            }
            return;
        }

        if (str_starts_with($text, '/deliver ')) {
            $parts = explode(' ', $text, 3);
            if (count($parts) === 3 && str_starts_with($parts[1], 'FRENZY-')) {
                adminDeliver($parts[1], $parts[2]);
            } else {
                sendMessage($chatId, 'Syntax: /deliver <order_id> <digital_item>');
            }
            return;
        }
    }

    if ($text === '/start') {
        setUserState($telegramId, 'idle');
        showHome($chatId, $firstName);
        return;
    }

    $state = getUserState($telegramId);

    if (str_starts_with($state, 'awaiting_utr:')) {
        $orderId = substr($state, strlen('awaiting_utr:'));
        $order   = getOrder($orderId);

        if (!$order || $order['telegram_id'] !== $telegramId) {
            setUserState($telegramId, 'idle');
            sendMessage($chatId, '⚠️ Order not found.', backKeyboard('home'));
            return;
        }

        if (!isValidUtr($text)) {
            sendMessage($chatId, "⚠️ Invalid UTR format. Please send a valid UTR transaction ID.");
            return;
        }

        updateOrderStatus($orderId, 'pending_verification', 'utr', trim($text));
        setUserState($telegramId, 'idle');

        sendMessage(
            $chatId,
            "✅ <b>UTR Received!</b> 🚀\n\nYour order <code>" . h($orderId) . "</code> is now under manual verification by admin.",
            backKeyboard('home')
        );

        notifyAdminOfPayment(getOrder($orderId), $username);
        return;
    }

    if ($state === 'awaiting_support') {
        if ($text === '') {
            sendMessage($chatId, 'Please enter a valid message.');
            return;
        }
        $stmt = db()->prepare('INSERT INTO support_messages (telegram_id, message) VALUES (?, ?)');
        $stmt->execute([$telegramId, $text]);

        setUserState($telegramId, 'idle');
        sendMessage($chatId, "✅ <b>Ticket Submitted!</b>\n\nOur team will review your query and reply directly here soon.", backKeyboard('home'));
        notifyAdminOfSupport($telegramId, $username, $text);
        return;
    }

    sendMessage($chatId, "✨ Please use the buttons below to navigate 👇", mainMenuKeyboard());
}

// ---------------------------------------------------------------------
// 9. WEBHOOK ENTRY POINT
// ---------------------------------------------------------------------
try {
    if (WEBHOOK_SECRET !== '') {
        $incomingSecret = $_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? '';
        if (!hash_equals(WEBHOOK_SECRET, $incomingSecret)) {
            http_response_code(403);
            exit;
        }
    }

    $raw = file_get_contents('php://input');
    $update = json_decode((string)$raw, true);

    if (is_array($update)) {
        if (isset($update['callback_query'])) {
            handleCallback($update['callback_query']);
        } elseif (isset($update['message'])) {
            handleMessage($update['message']);
        }
    }
} catch (Throwable $e) {
    error_log('Bot error: ' . $e->getMessage());
    if (isset($chatId) && is_string($chatId)) {
        friendlyError($chatId);
    }
}

http_response_code(200);
