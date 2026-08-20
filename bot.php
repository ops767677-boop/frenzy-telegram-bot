<?php
/**
 * =====================================================================
 * FRENZY STORE - Telegram Bot Backend
 * =====================================================================
 * Handles: /start, browsing products, wallet balance + fund top-ups,
 * order creation & delivery, manual UPI/UTR payment verification,
 * admin dashboard (/admin), order history, profile, and support.
 *
 * SECURITY NOTES
 * - This file must NEVER be included from index.php or exposed to the
 *   browser. It should only be reachable as your Telegram webhook URL.
 * - The bot token lives ONLY here.
 * - All SQL uses PDO prepared statements.
 * - Every admin-only action re-checks the sender's Telegram ID — that
 *   ID *is* the login, there is no separate password to manage or leak.
 * - Payments are NEVER auto-approved. A human admin must approve.
 * =====================================================================
 */

declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '0'); // never show raw PHP errors to anyone

// ---------------------------------------------------------------------
// 1. CONFIGURATION
// ---------------------------------------------------------------------
const BOT_TOKEN  = '8965830768:AAFVs8RxGGwnLwIW8n1msmD0NUQqwzUIRpA';
const ADMIN_ID   = '8047005584';
const STORE_NAME = 'Frenzy Store';
const DB_FILE    = __DIR__ . '/frenzy_store.sqlite';

// Optional webhook secret token (set the same value when you call
// setWebhook with &secret_token=... — see README.txt). Leave blank to
// disable this extra check.
const WEBHOOK_SECRET = '';

// Minimum / maximum wallet top-up amount (in ₹).
const MIN_TOPUP = 10;
const MAX_TOPUP = 100000;

// Payment configuration — edit these for your own store.
$upiId       = 'YOUR_UPI_ID';
$paymentName = STORE_NAME;
$qrImageUrl  = ''; // optional: a hosted image URL of your UPI QR code

// Product catalog. Keyed by price so callback data like "buy_99" can
// look a product up directly. Keep prices unique.
$PRODUCTS = [
    99  => ['name' => 'Premium Digital Product', 'emoji' => '👑', 'tag' => 'POPULAR'],
    199 => ['name' => 'VIP Digital Product',      'emoji' => '💎', 'tag' => 'VIP'],
    499 => ['name' => 'Ultimate Package',         'emoji' => '⚡', 'tag' => 'BEST VALUE'],
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
        balance     REAL DEFAULT 0,
        created_at  TEXT DEFAULT CURRENT_TIMESTAMP
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS orders (
        id          INTEGER PRIMARY KEY AUTOINCREMENT,
        order_id    TEXT UNIQUE NOT NULL,
        telegram_id TEXT NOT NULL,
        product     TEXT NOT NULL,
        amount      TEXT NOT NULL,
        status      TEXT NOT NULL DEFAULT 'created',
        delivery    TEXT,
        created_at  TEXT DEFAULT CURRENT_TIMESTAMP,
        updated_at  TEXT DEFAULT CURRENT_TIMESTAMP
    )");

    // Wallet top-up requests (adding funds via UPI + UTR, admin-verified).
    $pdo->exec("CREATE TABLE IF NOT EXISTS wallet_topups (
        id          INTEGER PRIMARY KEY AUTOINCREMENT,
        topup_id    TEXT UNIQUE NOT NULL,
        telegram_id TEXT NOT NULL,
        amount      REAL NOT NULL,
        status      TEXT NOT NULL DEFAULT 'pending_payment',
        utr         TEXT,
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
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_topups_telegram_id ON wallet_topups(telegram_id)");
}

/*
 * Order status lifecycle:
 *   paid       -> wallet had enough balance, deducted instantly, awaiting delivery
 *   completed  -> admin has sent the digital delivery
 *   cancelled  -> customer cancelled before confirming
 *
 * Wallet top-up status lifecycle (kept explicit, never auto-approved):
 *   pending_payment       -> top-up request created, waiting for customer to pay
 *   pending_verification  -> UTR submitted, waiting for admin
 *   completed              -> admin approved, wallet credited
 *   rejected                -> admin rejected
 */

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
// 4. SMALL UTILITIES
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

function generateTopupId(): string
{
    return 'WALLET-' . strtoupper(bin2hex(random_bytes(3)));
}

function money(float $amount): string
{
    return '₹' . number_format($amount, 2);
}

function friendlyError(string $chatId): void
{
    sendMessage($chatId, "⚠️ Something went wrong.\n\nPlease try again or contact support.");
}

// ---------------------------------------------------------------------
// 5. USER / WALLET HELPERS
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

function getBalance(string $telegramId): float
{
    $stmt = db()->prepare('SELECT balance FROM users WHERE telegram_id = ?');
    $stmt->execute([$telegramId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ? (float)$row['balance'] : 0.0;
}

function adjustBalance(string $telegramId, float $delta): float
{
    $pdo = $pdo ?? db();
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('SELECT balance FROM users WHERE telegram_id = ? FOR UPDATE');
        // SQLite has no FOR UPDATE, but the transaction still serializes writes.
        $stmt = $pdo->prepare('SELECT balance FROM users WHERE telegram_id = ?');
        $stmt->execute([$telegramId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $current = $row ? (float)$row['balance'] : 0.0;
        $new = $current + $delta;

        $upd = $pdo->prepare('UPDATE users SET balance = ? WHERE telegram_id = ?');
        $upd->execute([$new, $telegramId]);
        $pdo->commit();
        return $new;
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

// ---------------------------------------------------------------------
// 6. ORDER / TOPUP HELPERS
// ---------------------------------------------------------------------
function createOrder(string $telegramId, string $productName, string $amount, string $status): array
{
    $orderId = generateOrderId();
    $stmt = db()->prepare(
        'INSERT INTO orders (order_id, telegram_id, product, amount, status) VALUES (?, ?, ?, ?, ?)'
    );
    $stmt->execute([$orderId, $telegramId, $productName, $amount, $status]);
    return getOrder($orderId);
}

function getOrder(string $orderId): ?array
{
    $stmt = db()->prepare('SELECT * FROM orders WHERE order_id = ?');
    $stmt->execute([$orderId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function updateOrderStatus(string $orderId, string $status, ?string $delivery = null): void
{
    if ($delivery !== null) {
        $stmt = db()->prepare("UPDATE orders SET status = ?, delivery = ?, updated_at = CURRENT_TIMESTAMP WHERE order_id = ?");
        $stmt->execute([$status, $delivery, $orderId]);
    } else {
        $stmt = db()->prepare('UPDATE orders SET status = ?, updated_at = CURRENT_TIMESTAMP WHERE order_id = ?');
        $stmt->execute([$status, $orderId]);
    }
}

function createTopup(string $telegramId, float $amount): array
{
    $topupId = generateTopupId();
    $stmt = db()->prepare(
        'INSERT INTO wallet_topups (topup_id, telegram_id, amount, status) VALUES (?, ?, ?, ?)'
    );
    $stmt->execute([$topupId, $telegramId, $amount, 'pending_payment']);
    return getTopup($topupId);
}

function getTopup(string $topupId): ?array
{
    $stmt = db()->prepare('SELECT * FROM wallet_topups WHERE topup_id = ?');
    $stmt->execute([$topupId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function updateTopupStatus(string $topupId, string $status, ?string $utr = null): void
{
    if ($utr !== null) {
        $stmt = db()->prepare("UPDATE wallet_topups SET status = ?, utr = ?, updated_at = CURRENT_TIMESTAMP WHERE topup_id = ?");
        $stmt->execute([$status, $utr, $topupId]);
    } else {
        $stmt = db()->prepare('UPDATE wallet_topups SET status = ?, updated_at = CURRENT_TIMESTAMP WHERE topup_id = ?');
        $stmt->execute([$status, $topupId]);
    }
}

function statusLabel(string $status): string
{
    return match ($status) {
        'created'               => '🆕 Created',
        'pending_payment'       => '⏳ Payment Pending',
        'pending_verification'  => '⏳ Waiting for Admin Verification',
        'paid'                  => '✅ Paid — Preparing Delivery',
        'rejected'              => '❌ Rejected',
        'completed'             => '🎉 Delivered',
        'cancelled'              => '🚫 Cancelled',
        default                  => ucfirst($status),
    };
}

// ---------------------------------------------------------------------
// 7. KEYBOARDS
// ---------------------------------------------------------------------
function mainMenuKeyboard(): array
{
    return [
        [['text' => '🛍️ Browse Store', 'callback_data' => 'store'], ['text' => '💰 Wallet', 'callback_data' => 'wallet']],
        [['text' => '📦 My Orders', 'callback_data' => 'orders'], ['text' => '🎟️ Support', 'callback_data' => 'support']],
        [['text' => '👤 Profile', 'callback_data' => 'profile']],
    ];
}

function backKeyboard(string $target = 'home'): array
{
    return [[['text' => '⬅️ Back', 'callback_data' => $target]]];
}

// ---------------------------------------------------------------------
// 8. CUSTOMER SCREENS
// ---------------------------------------------------------------------
function brandHeader(): string
{
    return '✨ <b>' . h(STORE_NAME) . '</b> ✨';
}

function showHome(string $chatId, string $telegramId, string $firstName): void
{
    $balance = getBalance($telegramId);
    $text = "👋 Welcome, " . h($firstName) . "!\n\n"
          . brandHeader() . "\n"
          . "━━━━━━━━━━━━━━━\n"
          . "🛍️ Premium digital products\n"
          . "⚡ Instant wallet checkout\n"
          . "🔐 Secure, admin-verified payments\n"
          . "🎟️ Real Telegram support\n"
          . "━━━━━━━━━━━━━━━\n\n"
          . "💰 Wallet Balance: <b>" . money($balance) . "</b>";
    sendMessage($chatId, $text, mainMenuKeyboard());
}

function showStore(string $chatId): void
{
    global $PRODUCTS;
    $text = brandHeader() . "\n<b>Available Products</b>\n━━━━━━━━━━━━━━━\nSelect a product to view details:";
    $rows = [];
    foreach ($PRODUCTS as $price => $p) {
        $rows[] = [[
            'text' => $p['emoji'] . ' ' . $p['name'] . ' — ₹' . $price . ' (' . $p['tag'] . ')',
            'callback_data' => 'buy_' . $price,
        ]];
    }
    $rows[] = [['text' => '⬅️ Back', 'callback_data' => 'home']];
    sendMessage($chatId, $text, $rows);
}

function showProductConfirm(string $chatId, int $price): void
{
    global $PRODUCTS;
    if (!isset($PRODUCTS[$price])) {
        sendMessage($chatId, '⚠️ That product is no longer available.', backKeyboard('store'));
        return;
    }
    $p = $PRODUCTS[$price];
    $text = "🛒 <b>Order Confirmation</b>\n━━━━━━━━━━━━━━━\n"
          . "Product:\n" . $p['emoji'] . ' ' . h($p['name']) . "\n\n"
          . "Price:\n₹" . $price;
    $kb = [
        [['text' => '✅ Confirm Order', 'callback_data' => 'confirm_buy_' . $price]],
        [['text' => '❌ Cancel', 'callback_data' => 'cancel_order']],
    ];
    sendMessage($chatId, $text, $kb);
}

// Called after "Confirm Order" — checks wallet balance and either
// completes the purchase instantly or shows an insufficient-balance
// prompt, mirroring a standard wallet-based storefront.
function processPurchase(string $chatId, string $telegramId, int $price, string $productName): void
{
    $balance = getBalance($telegramId);

    if ($balance < $price) {
        $need = $price - $balance;
        $text = "⚠️ <b>Insufficient Balance!</b>\n━━━━━━━━━━━━━━━\n"
              . "Product: " . h($productName) . "\n"
              . "Price: " . money((float)$price) . "\n"
              . "Your Balance: " . money($balance) . "\n"
              . "Need to Add: " . money($need) . "\n\n"
              . "Please add funds to your wallet to purchase this item.";
        $kb = [
            [['text' => '➕ Add Funds', 'callback_data' => 'addfund']],
            [['text' => '⬅️ Back', 'callback_data' => 'store']],
        ];
        sendMessage($chatId, $text, $kb);
        return;
    }

    adjustBalance($telegramId, -$price);
    $order = createOrder($telegramId, $productName, (string)$price, 'paid');

    $text = "🎉 <b>Order Placed!</b>\n━━━━━━━━━━━━━━━\n"
          . "Order ID: " . h($order['order_id']) . "\n"
          . "Product: " . h($productName) . "\n"
          . "Amount: " . money((float)$price) . "\n"
          . "New Balance: " . money(getBalance($telegramId)) . "\n\n"
          . "Status: " . statusLabel('paid') . "\n"
          . "Our team will deliver your product here shortly.";
    sendMessage($chatId, $text, backKeyboard('home'));

    notifyAdminOfNewOrder($order);
}

function showWallet(string $chatId, string $telegramId): void
{
    $balance = getBalance($telegramId);
    $text = "💰 <b>My Wallet</b>\n━━━━━━━━━━━━━━━\n"
          . "Current Balance:\n<b>" . money($balance) . "</b>\n\n"
          . "Add funds via UPI — verified by our team, credited to your wallet.";
    $kb = [
        [['text' => '➕ Add Funds', 'callback_data' => 'addfund']],
        [['text' => '⬅️ Back', 'callback_data' => 'home']],
    ];
    sendMessage($chatId, $text, $kb);
}

function showOrders(string $chatId, string $telegramId): void
{
    $stmt = db()->prepare('SELECT * FROM orders WHERE telegram_id = ? ORDER BY created_at DESC LIMIT 20');
    $stmt->execute([$telegramId]);
    $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (!$orders) {
        sendMessage($chatId, "📦 You don't have any orders yet.", backKeyboard('home'));
        return;
    }

    $text = "<b>📦 My Orders</b>\n━━━━━━━━━━━━━━━\n\n";
    foreach ($orders as $o) {
        $text .= "📦 Order #" . h($o['order_id']) . "\n"
               . "Product: " . h($o['product']) . "\n"
               . "Amount: ₹" . h($o['amount']) . "\n"
               . "Status: " . statusLabel($o['status']) . "\n\n";
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
    $pending   = count(array_filter($statuses, fn($s) => $s === 'paid'));

    $text = "<b>👤 Profile</b>\n━━━━━━━━━━━━━━━\n"
          . "User ID: <code>" . h($telegramId) . "</code>\n"
          . "Username: " . ($username ? '@' . h($username) : '—') . "\n"
          . "First Name: " . h((string)$firstName) . "\n\n"
          . "💰 Wallet Balance: " . money(getBalance($telegramId)) . "\n"
          . "📦 Total Orders: " . $total . "\n"
          . "🎉 Completed: " . $completed . "\n"
          . "⏳ Pending Delivery: " . $pending;
    sendMessage($chatId, $text, backKeyboard('home'));
}

function showSupportPrompt(string $chatId): void
{
    sendMessage(
        $chatId,
        "🎟️ <b>" . h(STORE_NAME) . " Support</b>\n━━━━━━━━━━━━━━━\nPlease type your question or issue, and our team will get back to you here.",
        backKeyboard('home')
    );
}

// ---------------------------------------------------------------------
// 9. WALLET TOP-UP SCREENS
// ---------------------------------------------------------------------
function promptTopupAmount(string $chatId, string $telegramId): void
{
    setUserState($telegramId, 'awaiting_topup_amount');
    sendMessage(
        $chatId,
        "➕ <b>Add Funds</b>\n━━━━━━━━━━━━━━━\nEnter the amount you'd like to add (₹" . MIN_TOPUP . " – ₹" . MAX_TOPUP . "):"
    );
}

function showTopupPaymentScreen(string $chatId, array $topup): void
{
    global $upiId, $paymentName, $qrImageUrl;

    $text = "💳 <b>Add Funds — Payment</b>\n━━━━━━━━━━━━━━━\n"
          . "Request ID: " . h($topup['topup_id']) . "\n"
          . "Amount: " . money((float)$topup['amount']) . "\n\n"
          . "Pay via UPI to:\n<code>" . h($upiId) . "</code>\n"
          . "Name: " . h($paymentName) . "\n\n"
          . "After paying, tap <b>Submit UTR</b> below.";

    if ($qrImageUrl !== '') {
        tg('sendPhoto', [
            'chat_id' => $chatId,
            'photo'   => $qrImageUrl,
            'caption' => "Scan to pay " . money((float)$topup['amount']),
        ]);
    }

    $kb = [
        [['text' => '📤 Submit UTR', 'callback_data' => 'subtopututr_' . $topup['topup_id']]],
        [['text' => '❌ Cancel', 'callback_data' => 'home']],
    ];
    sendMessage($chatId, $text, $kb);
}

// ---------------------------------------------------------------------
// 10. UTR VALIDATION
// ---------------------------------------------------------------------
function isValidUtr(string $utr): bool
{
    $utr = trim($utr);
    if ($utr === '') {
        return false;
    }
    if (mb_strlen($utr) > 40) {
        return false;
    }
    return (bool)preg_match('/^[A-Za-z0-9\- ]+$/', $utr);
}

function isValidAmount(string $text): bool
{
    if (!is_numeric($text)) {
        return false;
    }
    $amount = (float)$text;
    return $amount >= MIN_TOPUP && $amount <= MAX_TOPUP;
}

// ---------------------------------------------------------------------
// 11. ADMIN NOTIFICATIONS & ACTIONS
// ---------------------------------------------------------------------
function notifyAdminOfNewOrder(array $order): void
{
    $text = "🔔 <b>NEW ORDER — PAID FROM WALLET</b>\n━━━━━━━━━━━━━━━\n"
          . "Order ID: " . h($order['order_id']) . "\n"
          . "User ID: <code>" . h($order['telegram_id']) . "</code>\n"
          . "Product: " . h($order['product']) . "\n"
          . "Amount: ₹" . h($order['amount']) . "\n\n"
          . "Send delivery with:\n<code>/deliver " . h($order['order_id']) . " your content</code>";
    sendMessage(ADMIN_ID, $text);
}

function notifyAdminOfTopup(array $topup, ?string $username): void
{
    $text = "🔔 <b>NEW WALLET TOP-UP VERIFICATION</b>\n━━━━━━━━━━━━━━━\n"
          . "👤 User: " . ($username ? '@' . h($username) : '—') . "\n"
          . "🆔 User ID: <code>" . h($topup['telegram_id']) . "</code>\n"
          . "💰 Amount: " . money((float)$topup['amount']) . "\n"
          . "🧾 UTR: <code>" . h((string)$topup['utr']) . "</code>\n\n"
          . "⏳ Status: Pending Verification";
    $kb = [[
        ['text' => '✅ Approve', 'callback_data' => 'approvetopup_' . $topup['topup_id']],
        ['text' => '❌ Reject',  'callback_data' => 'rejecttopup_' . $topup['topup_id']],
    ]];
    sendMessage(ADMIN_ID, $text, $kb);
}

function notifyAdminOfSupport(string $telegramId, ?string $username, string $message): void
{
    $text = "🔔 <b>New Support Message</b>\n━━━━━━━━━━━━━━━\n"
          . "User: " . ($username ? '@' . h($username) : '—') . "\n"
          . "User ID: <code>" . h($telegramId) . "</code>\n\n"
          . "Message:\n" . h($message) . "\n\n"
          . "Reply with:\n<code>/reply " . h($telegramId) . " your message</code>";
    sendMessage(ADMIN_ID, $text);
}

function adminApproveTopup(string $topupId): void
{
    $topup = getTopup($topupId);
    if (!$topup || $topup['status'] !== 'pending_verification') {
        sendMessage(ADMIN_ID, "⚠️ Top-up " . h($topupId) . " is not awaiting verification (maybe already processed).");
        return;
    }
    updateTopupStatus($topupId, 'completed');
    $newBalance = adjustBalance($topup['telegram_id'], (float)$topup['amount']);

    sendMessage(
        $topup['telegram_id'],
        "🎉 <b>Wallet Credited!</b>\n━━━━━━━━━━━━━━━\nAmount: " . money((float)$topup['amount']) . "\nNew Balance: " . money($newBalance) . "\n\nYou can now shop in the store!",
        backKeyboard('home')
    );
    sendMessage(ADMIN_ID, "✅ Top-up " . h($topupId) . " approved. Wallet credited.");
}

function adminRejectTopup(string $topupId): void
{
    $topup = getTopup($topupId);
    if (!$topup || $topup['status'] !== 'pending_verification') {
        sendMessage(ADMIN_ID, "⚠️ Top-up " . h($topupId) . " is not awaiting verification (maybe already processed).");
        return;
    }
    updateTopupStatus($topupId, 'rejected');

    sendMessage(
        $topup['telegram_id'],
        "❌ <b>Top-up Rejected</b>\n━━━━━━━━━━━━━━━\nRequest ID: " . h($topupId) . "\n\nPlease contact support if you believe this was a mistake."
    );
    sendMessage(ADMIN_ID, "❌ Top-up " . h($topupId) . " rejected.");
}

function adminDeliver(string $orderId, string $content): void
{
    $order = getOrder($orderId);
    if (!$order) {
        sendMessage(ADMIN_ID, "⚠️ Order " . h($orderId) . " not found.");
        return;
    }
    if ($order['status'] !== 'paid') {
        sendMessage(ADMIN_ID, "⚠️ Order " . h($orderId) . " is not awaiting delivery.");
        return;
    }
    updateOrderStatus($orderId, 'completed', $content);

    sendMessage(
        $order['telegram_id'],
        "🎉 <b>ORDER COMPLETED</b>\n━━━━━━━━━━━━━━━\n📦 Product: " . h($order['product']) . "\n🆔 Order: " . h($orderId) . "\n\n🔑 Delivery:\n" . h($content)
    );
    sendMessage(ADMIN_ID, "📦 Delivery sent for order " . h($orderId) . ".");
}

// ---------------------------------------------------------------------
// 12. ADMIN DASHBOARD (/admin)
// ---------------------------------------------------------------------
function showAdminDashboard(string $chatId): void
{
    $pdo = db();

    $totalUsers   = (int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
    $totalOrders  = (int)$pdo->query('SELECT COUNT(*) FROM orders')->fetchColumn();
    $totalRevenue = (float)$pdo->query("SELECT COALESCE(SUM(amount),0) FROM orders WHERE status IN ('paid','completed')")->fetchColumn();
    $pendingDeliv = (int)$pdo->query("SELECT COUNT(*) FROM orders WHERE status = 'paid'")->fetchColumn();
    $pendingTopup = (int)$pdo->query("SELECT COUNT(*) FROM wallet_topups WHERE status = 'pending_verification'")->fetchColumn();

    $text = "🛠️ <b>Admin Dashboard</b>\n━━━━━━━━━━━━━━━\n"
          . "👥 Total Users: " . $totalUsers . "\n"
          . "📦 Total Orders: " . $totalOrders . "\n"
          . "💰 Total Revenue: " . money($totalRevenue) . "\n\n"
          . "⏳ Pending Top-up Verifications: " . $pendingTopup . "\n"
          . "📤 Orders Awaiting Delivery: " . $pendingDeliv;

    $kb = [
        [['text' => '⏳ Pending Top-ups', 'callback_data' => 'admin_topups']],
        [['text' => '📤 Pending Deliveries', 'callback_data' => 'admin_deliveries']],
        [['text' => '🔄 Refresh', 'callback_data' => 'admin_home']],
    ];
    sendMessage($chatId, $text, $kb);
}

function showAdminPendingTopups(string $chatId): void
{
    $stmt = db()->query("SELECT * FROM wallet_topups WHERE status = 'pending_verification' ORDER BY created_at ASC LIMIT 10");
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (!$rows) {
        sendMessage($chatId, "✅ No pending top-up verifications.", adminBackKeyboard());
        return;
    }

    foreach ($rows as $t) {
        $text = "🆔 " . h($t['topup_id']) . "\n"
              . "User: <code>" . h($t['telegram_id']) . "</code>\n"
              . "Amount: " . money((float)$t['amount']) . "\n"
              . "UTR: <code>" . h((string)$t['utr']) . "</code>";
        $kb = [[
            ['text' => '✅ Approve', 'callback_data' => 'approvetopup_' . $t['topup_id']],
            ['text' => '❌ Reject',  'callback_data' => 'rejecttopup_' . $t['topup_id']],
        ]];
        sendMessage($chatId, $text, $kb);
    }
    sendMessage($chatId, 'Use the buttons above, or refresh below.', adminBackKeyboard());
}

function showAdminPendingDeliveries(string $chatId): void
{
    $stmt = db()->query("SELECT * FROM orders WHERE status = 'paid' ORDER BY created_at ASC LIMIT 10");
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (!$rows) {
        sendMessage($chatId, "✅ No orders awaiting delivery.", adminBackKeyboard());
        return;
    }

    $text = "📤 <b>Orders Awaiting Delivery</b>\n━━━━━━━━━━━━━━━\n\n";
    foreach ($rows as $o) {
        $text .= "🆔 " . h($o['order_id']) . "\n"
               . "User: <code>" . h($o['telegram_id']) . "</code>\n"
               . "Product: " . h($o['product']) . "\n"
               . "Send: <code>/deliver " . h($o['order_id']) . " content</code>\n\n";
    }
    sendMessage($chatId, trim($text), adminBackKeyboard());
}

function adminBackKeyboard(): array
{
    return [[['text' => '⬅️ Back to Dashboard', 'callback_data' => 'admin_home']]];
}

// ---------------------------------------------------------------------
// 13. CALLBACK ROUTER
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

    $known = ['home', 'store', 'orders', 'wallet', 'addfund', 'support', 'profile', 'cancel_order'];

    if (in_array($data, $known, true)) {
        switch ($data) {
            case 'home':
                setUserState($telegramId, 'idle');
                showHome($chatId, $telegramId, $firstName);
                return;
            case 'store':
                showStore($chatId);
                return;
            case 'orders':
                showOrders($chatId, $telegramId);
                return;
            case 'wallet':
                showWallet($chatId, $telegramId);
                return;
            case 'addfund':
                promptTopupAmount($chatId, $telegramId);
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
                sendMessage($chatId, '🚫 Cancelled.', backKeyboard('home'));
                return;
        }
    }

    // Admin-only dashboard callbacks.
    if (in_array($data, ['admin_home', 'admin_topups', 'admin_deliveries'], true)) {
        if (!isAdmin($telegramId)) {
            answerCallback($callbackId, 'Not authorized.', true);
            return;
        }
        match ($data) {
            'admin_home'       => showAdminDashboard($chatId),
            'admin_topups'     => showAdminPendingTopups($chatId),
            'admin_deliveries' => showAdminPendingDeliveries($chatId),
        };
        return;
    }

    // buy_<price>
    if (preg_match('/^buy_(\d+)$/', $data, $m)) {
        showProductConfirm($chatId, (int)$m[1]);
        return;
    }

    // confirm_buy_<price>
    if (preg_match('/^confirm_buy_(\d+)$/', $data, $m)) {
        $price = (int)$m[1];
        if (!isset($PRODUCTS[$price])) {
            sendMessage($chatId, '⚠️ That product is no longer available.', backKeyboard('store'));
            return;
        }
        processPurchase($chatId, $telegramId, $price, $PRODUCTS[$price]['name']);
        return;
    }

    // subtopututr_<topupId>
    if (preg_match('/^subtopututr_(WALLET-[A-Z0-9]+)$/', $data, $m)) {
        $topup = getTopup($m[1]);
        if (!$topup || $topup['telegram_id'] !== $telegramId) {
            sendMessage($chatId, '⚠️ Request not found.', backKeyboard('wallet'));
            return;
        }
        setUserState($telegramId, 'awaiting_topup_utr:' . $topup['topup_id']);
        sendMessage($chatId, "Please send your UTR / Transaction ID.");
        return;
    }

    // approvetopup_<id> / rejecttopup_<id> — admin only
    if (preg_match('/^(approvetopup|rejecttopup)_(WALLET-[A-Z0-9]+)$/', $data, $m)) {
        if (!isAdmin($telegramId)) {
            answerCallback($callbackId, 'Not authorized.', true);
            return;
        }
        if ($m[1] === 'approvetopup') {
            adminApproveTopup($m[2]);
        } else {
            adminRejectTopup($m[2]);
        }
        return;
    }

    // Unknown callback data — ignore silently, no arbitrary execution.
}

// ---------------------------------------------------------------------
// 14. MESSAGE ROUTER
// ---------------------------------------------------------------------
function handleMessage(array $msg): void
{
    $chatId     = (string)$msg['chat']['id'];
    $telegramId = (string)($msg['from']['id'] ?? $chatId);
    $username   = $msg['from']['username'] ?? null;
    $firstName  = $msg['from']['first_name'] ?? 'there';
    $text       = trim((string)($msg['text'] ?? ''));

    upsertUser($telegramId, $username, $firstName);

    // --- Admin-only commands. The Telegram ID itself is the login —
    // there is no separate admin password to configure or leak. -----
    if (isAdmin($telegramId)) {
        if ($text === '/admin') {
            showAdminDashboard($chatId);
            return;
        }

        if (str_starts_with($text, '/reply ')) {
            $parts = explode(' ', $text, 3);
            if (count($parts) === 3 && ctype_digit($parts[1])) {
                sendMessage($parts[1], "💬 <b>Support Reply</b>\n\n" . h($parts[2]));
                sendMessage($chatId, '✅ Reply sent.');
            } else {
                sendMessage($chatId, 'Usage: /reply <user_id> <message>');
            }
            return;
        }

        if (str_starts_with($text, '/deliver ')) {
            $parts = explode(' ', $text, 3);
            if (count($parts) === 3 && str_starts_with($parts[1], 'FRENZY-')) {
                adminDeliver($parts[1], $parts[2]);
            } else {
                sendMessage($chatId, 'Usage: /deliver <order_id> <delivery content>');
            }
            return;
        }
    } else {
        // Non-admins can never use admin-only commands, even /admin.
        if ($text === '/admin' || str_starts_with($text, '/reply ') || str_starts_with($text, '/deliver ')) {
            sendMessage($chatId, '⚠️ You are not authorized to use this command.');
            return;
        }
    }

    // --- /start ---------------------------------------------------------------
    if ($text === '/start') {
        setUserState($telegramId, 'idle');
        showHome($chatId, $telegramId, $firstName);
        return;
    }

    $state = getUserState($telegramId);

    // --- Awaiting wallet top-up amount --------------------------------------
    if ($state === 'awaiting_topup_amount') {
        if (!isValidAmount($text)) {
            sendMessage($chatId, "⚠️ Enter a valid amount between ₹" . MIN_TOPUP . " and ₹" . MAX_TOPUP . ".");
            return;
        }
        $topup = createTopup($telegramId, (float)$text);
        setUserState($telegramId, 'idle');
        showTopupPaymentScreen($chatId, $topup);
        return;
    }

    // --- Awaiting top-up UTR submission --------------------------------------
    if (str_starts_with($state, 'awaiting_topup_utr:')) {
        $topupId = substr($state, strlen('awaiting_topup_utr:'));
        $topup   = getTopup($topupId);

        if (!$topup || $topup['telegram_id'] !== $telegramId) {
            setUserState($telegramId, 'idle');
            sendMessage($chatId, '⚠️ That request could not be found.', backKeyboard('home'));
            return;
        }

        if (!isValidUtr($text)) {
            sendMessage($chatId, "⚠️ That doesn't look like a valid UTR / Transaction ID. Please try again.");
            return;
        }

        updateTopupStatus($topupId, 'pending_verification', trim($text));
        setUserState($telegramId, 'idle');

        sendMessage(
            $chatId,
            "✅ <b>UTR Submitted</b>\n━━━━━━━━━━━━━━━\nYour top-up is now:\n⏳ Waiting for admin verification",
            backKeyboard('home')
        );

        notifyAdminOfTopup(getTopup($topupId), $username);
        return;
    }

    // --- Awaiting support message ------------------------------------------
    if ($state === 'awaiting_support') {
        if ($text === '') {
            sendMessage($chatId, 'Please type your question or issue as text.');
            return;
        }
        $stmt = db()->prepare('INSERT INTO support_messages (telegram_id, message) VALUES (?, ?)');
        $stmt->execute([$telegramId, $text]);

        setUserState($telegramId, 'idle');
        sendMessage($chatId, "✅ Your message has been sent to our support team. We'll get back to you here soon.", backKeyboard('home'));
        notifyAdminOfSupport($telegramId, $username, $text);
        return;
    }

    // --- Fallback ---------------------------------------------------------
    sendMessage($chatId, "Please use the buttons below to navigate " . h(STORE_NAME) . ".", mainMenuKeyboard());
}

// ---------------------------------------------------------------------
// 15. WEBHOOK ENTRY POINT
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
    error_log('Frenzy Store bot error: ' . $e->getMessage());
    if (isset($chatId) && is_string($chatId)) {
        friendlyError($chatId);
    }
}

http_response_code(200);
