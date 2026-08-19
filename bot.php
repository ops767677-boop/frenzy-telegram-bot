<?php
/**
 * =====================================================================
 * FRENZY STORE - Telegram Bot Backend
 * =====================================================================
 * Handles: /start, browsing products, order creation, manual UPI/UTR
 * payment verification, admin approval/rejection, digital delivery,
 * order history, profile, and a support/reply system.
 *
 * SECURITY NOTES
 * - This file must NEVER be included from index.php or exposed to the
 *   browser. It should only be reachable as your Telegram webhook URL.
 * - The bot token lives ONLY here.
 * - All SQL uses PDO prepared statements.
 * - All admin-only actions re-check the sender's Telegram ID.
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

// Payment configuration — edit these for your own store.
$upiId        = 'sahid.frenzy@fam';
$paymentName  = Frenzy Store;
$qrImageUrl   = ''; // optional: a hosted image URL of your UPI QR code

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

/*
 * Order status lifecycle (kept explicit on purpose):
 *   created            -> order row created, no payment action yet
 *   pending_payment    -> customer shown payment instructions
 *   pending_verification -> UTR submitted, waiting for admin
 *   paid               -> admin approved payment
 *   rejected           -> admin rejected payment
 *   completed          -> digital delivery sent
 *   cancelled          -> customer cancelled before paying
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

function editMessage(string $chatId, int $messageId, string $text, ?array $keyboard = null): void
{
    $payload = [
        'chat_id'    => $chatId,
        'message_id' => $messageId,
        'text'       => $text,
        'parse_mode' => 'HTML',
    ];
    if ($keyboard !== null) {
        $payload['reply_markup'] = json_encode(['inline_keyboard' => $keyboard]);
    }
    // Fall back to sending a new message if the edit fails (e.g. message
    // too old, or it was a message we can't edit).
    if (tg('editMessageText', $payload) === null) {
        sendMessage($chatId, $text, $keyboard);
    }
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
    // Random, non-sequential order IDs.
    return 'FRENZY-' . strtoupper(bin2hex(random_bytes(3)));
}

function friendlyError(string $chatId): void
{
    sendMessage($chatId, "⚠️ Something went wrong.\n\nPlease try again or contact support.");
}

// ---------------------------------------------------------------------
// 5. USER HELPERS
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

// ---------------------------------------------------------------------
// 6. ORDER HELPERS
// ---------------------------------------------------------------------
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
        'created'               => '🆕 Created',
        'pending_payment'       => '⏳ Payment Pending',
        'pending_verification'  => '⏳ Waiting for Admin Verification',
        'paid'                  => '✅ Payment Verified',
        'rejected'              => '❌ Payment Rejected',
        'completed'             => '🎉 Delivered',
        'cancelled'             => '🚫 Cancelled',
        default                 => ucfirst($status),
    };
}

// ---------------------------------------------------------------------
// 7. KEYBOARDS
// ---------------------------------------------------------------------
function mainMenuKeyboard(): array
{
    return [
        [['text' => '🛍️ Browse Store', 'callback_data' => 'store'], ['text' => '📦 My Orders', 'callback_data' => 'orders']],
        [['text' => '💳 Payment Help', 'callback_data' => 'payment_help'], ['text' => '🎟️ Support', 'callback_data' => 'support']],
        [['text' => '👤 Profile', 'callback_data' => 'profile']],
    ];
}

function backKeyboard(string $target = 'home'): array
{
    return [[['text' => '⬅️ Back', 'callback_data' => $target]]];
}

// ---------------------------------------------------------------------
// 8. SCREENS
// ---------------------------------------------------------------------
function showHome(string $chatId, string $firstName): void
{
    $text = "👋 Welcome, " . h($firstName) . "!\n\n"
          . "✨ Welcome to <b>" . h(STORE_NAME) . "</b>\n\n"
          . "🛍️ Premium digital products\n"
          . "⚡ Fast order processing\n"
          . "🔐 Secure checkout\n"
          . "🎟️ Telegram support";
    sendMessage($chatId, $text, mainMenuKeyboard());
}

function showStore(string $chatId): void
{
    global $PRODUCTS;
    $text = "<b>" . h(STORE_NAME) . " Products</b>\n\nSelect a product to view details:";
    $rows = [];
    foreach ($PRODUCTS as $price => $p) {
        $rows[] = [[
            'text' => $p['emoji'] . ' ' . $p['name'] . ' — ₹' . $price,
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
    $text = "🛒 <b>Order Confirmation</b>\n\n"
          . "Product:\n" . h($p['name']) . "\n\n"
          . "Price:\n₹" . $price;
    $kb = [
        [['text' => '✅ Confirm Order', 'callback_data' => 'confirm_buy_' . $price]],
        [['text' => '❌ Cancel', 'callback_data' => 'cancel_order']],
    ];
    sendMessage($chatId, $text, $kb);
}

function showPaymentScreen(string $chatId, array $order): void
{
    $text = "💳 <b>Payment</b>\n\n"
          . "Order ID:\n" . h($order['order_id']) . "\n\n"
          . "Amount:\n₹" . h($order['amount']) . "\n\n"
          . "Payment status:\n" . statusLabel($order['status']);
    $kb = [
        [['text' => '💳 Payment Instructions', 'callback_data' => 'payinfo_' . $order['order_id']]],
        [['text' => '📤 Submit UTR', 'callback_data' => 'subutr_' . $order['order_id']]],
        [['text' => '❌ Cancel', 'callback_data' => 'cancel_order']],
    ];
    sendMessage($chatId, $text, $kb);
}

function showPaymentInstructions(string $chatId, array $order): void
{
    global $upiId, $paymentName, $qrImageUrl;

    $text = "💳 <b>Payment Instructions</b>\n\n"
          . "Order ID:\n" . h($order['order_id']) . "\n\n"
          . "Amount:\n₹" . h($order['amount']) . "\n\n"
          . "Pay via UPI to:\n<code>" . h($upiId) . "</code>\n"
          . "Name: " . h($paymentName) . "\n\n"
          . "After paying, tap <b>Submit UTR</b> and send your transaction ID / UTR "
          . "so our team can verify your payment.";

    if ($qrImageUrl !== '') {
        tg('sendPhoto', [
            'chat_id' => $chatId,
            'photo'   => $qrImageUrl,
            'caption' => "Scan to pay for order " . h($order['order_id']),
        ]);
    }

    $kb = [
        [['text' => '📤 Submit UTR', 'callback_data' => 'subutr_' . $order['order_id']]],
        [['text' => '⬅️ Back', 'callback_data' => 'orders']],
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

    $text = "<b>📦 My Orders</b>\n\n";
    foreach ($orders as $o) {
        $text .= "📦 Order #" . h($o['order_id']) . "\n"
               . "Product: " . h($o['product']) . "\n"
               . "Amount: ₹" . h($o['amount']) . "\n"
               . "Status: " . statusLabel($o['status']) . "\n";
        if ($o['status'] === 'completed') {
            $text .= "Delivery: ✅ Delivered\n";
        }
        $text .= "\n";
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

    $text = "<b>👤 Profile</b>\n\n"
          . "User ID: <code>" . h($telegramId) . "</code>\n"
          . "Username: " . ($username ? '@' . h($username) : '—') . "\n"
          . "First Name: " . h((string)$firstName) . "\n\n"
          . "Total Orders: " . $total . "\n"
          . "Completed Orders: " . $completed . "\n"
          . "Pending Orders: " . $pending;
    sendMessage($chatId, $text, backKeyboard('home'));
}

function showSupportPrompt(string $chatId): void
{
    sendMessage(
        $chatId,
        "🎟️ <b>" . h(STORE_NAME) . " Support</b>\n\nPlease type your question or issue, and our team will get back to you here.",
        backKeyboard('home')
    );
}

function showPaymentHelp(string $chatId): void
{
    $text = "💳 <b>Payment Help</b>\n\n"
          . "1. Browse the store and choose a product.\n"
          . "2. Confirm your order.\n"
          . "3. Pay using the UPI details shown.\n"
          . "4. Submit your UTR / transaction ID.\n"
          . "5. Wait for admin verification — you'll be notified here.\n\n"
          . "Need more help? Use the Support option.";
    sendMessage($chatId, $text, backKeyboard('home'));
}

// ---------------------------------------------------------------------
// 9. ADMIN SCREENS / ACTIONS
// ---------------------------------------------------------------------
function notifyAdminOfPayment(array $order, ?string $username): void
{
    $text = "🔔 <b>NEW PAYMENT VERIFICATION</b>\n\n"
          . "👤 User:\n" . ($username ? '@' . h($username) : '—') . "\n\n"
          . "🆔 User ID:\n" . h($order['telegram_id']) . "\n\n"
          . "📦 Product:\n" . h($order['product']) . "\n\n"
          . "💰 Amount:\n₹" . h($order['amount']) . "\n\n"
          . "🧾 UTR:\n<code>" . h((string)$order['utr']) . "</code>\n\n"
          . "⏳ Status:\nPending Verification";
    $kb = [[
        ['text' => '✅ Approve', 'callback_data' => 'approve_' . $order['order_id']],
        ['text' => '❌ Reject',  'callback_data' => 'reject_' . $order['order_id']],
    ]];
    sendMessage(ADMIN_ID, $text, $kb);
}

function notifyAdminOfSupport(string $telegramId, ?string $username, string $message): void
{
    $text = "🔔 <b>New Support Message</b>\n\n"
          . "User:\n" . ($username ? '@' . h($username) : '—') . "\n\n"
          . "User ID:\n<code>" . h($telegramId) . "</code>\n\n"
          . "Message:\n" . h($message) . "\n\n"
          . "Reply with:\n<code>/reply " . h($telegramId) . " your message</code>";
    sendMessage(ADMIN_ID, $text);
}

function adminApprove(string $orderId): void
{
    $order = getOrder($orderId);
    if (!$order || $order['status'] !== 'pending_verification') {
        sendMessage(ADMIN_ID, "⚠️ Order " . h($orderId) . " is not awaiting verification (maybe already processed).");
        return;
    }
    updateOrderStatus($orderId, 'paid');

    sendMessage(
        $order['telegram_id'],
        "🎉 <b>Payment Verified!</b>\n\nYour order has been approved.\n\nOrder ID:\n" . h($orderId) . "\n\nStatus:\n✅ Payment Verified\n\nYour product will be delivered here shortly."
    );

    sendMessage(
        ADMIN_ID,
        "✅ Order " . h($orderId) . " marked as PAID.\n\nTo deliver, send:\n<code>/deliver " . h($orderId) . " your delivery content</code>"
    );
}

function adminReject(string $orderId): void
{
    $order = getOrder($orderId);
    if (!$order || $order['status'] !== 'pending_verification') {
        sendMessage(ADMIN_ID, "⚠️ Order " . h($orderId) . " is not awaiting verification (maybe already processed).");
        return;
    }
    updateOrderStatus($orderId, 'rejected');

    sendMessage(
        $order['telegram_id'],
        "❌ <b>Payment Rejected</b>\n\nOrder ID:\n" . h($orderId) . "\n\nPlease contact support if you believe this was a mistake."
    );

    sendMessage(ADMIN_ID, "❌ Order " . h($orderId) . " marked as REJECTED.");
}

function adminDeliver(string $orderId, string $content): void
{
    $order = getOrder($orderId);
    if (!$order) {
        sendMessage(ADMIN_ID, "⚠️ Order " . h($orderId) . " not found.");
        return;
    }
    if ($order['status'] !== 'paid') {
        sendMessage(ADMIN_ID, "⚠️ Order " . h($orderId) . " is not in a paid state, cannot deliver.");
        return;
    }
    updateOrderStatus($orderId, 'completed', 'delivery', $content);

    sendMessage(
        $order['telegram_id'],
        "🎉 <b>ORDER COMPLETED</b>\n\n📦 Product:\n" . h($order['product']) . "\n\n🆔 Order:\n" . h($orderId) . "\n\n🔑 Delivery:\n" . h($content)
    );

    sendMessage(ADMIN_ID, "📦 Delivery sent for order " . h($orderId) . ".");
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
    // Allow letters, numbers, spaces and hyphens only.
    return (bool)preg_match('/^[A-Za-z0-9\- ]+$/', $utr);
}

// ---------------------------------------------------------------------
// 11. CALLBACK ROUTER
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

    // Only allow known, whitelisted callback patterns.
    $known = [
        'home', 'store', 'orders', 'payment_help', 'support', 'profile', 'cancel_order',
    ];

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
        $order = createOrder($telegramId, $PRODUCTS[$price]['name'], (string)$price);
        showPaymentScreen($chatId, $order);
        return;
    }

    // payinfo_<orderId>
    if (preg_match('/^payinfo_(FRENZY-[A-Z0-9]+)$/', $data, $m)) {
        $order = getOrder($m[1]);
        if (!$order || $order['telegram_id'] !== $telegramId) {
            sendMessage($chatId, '⚠️ Order not found.', backKeyboard('orders'));
            return;
        }
        showPaymentInstructions($chatId, $order);
        return;
    }

    // subutr_<orderId>
    if (preg_match('/^subutr_(FRENZY-[A-Z0-9]+)$/', $data, $m)) {
        $order = getOrder($m[1]);
        if (!$order || $order['telegram_id'] !== $telegramId) {
            sendMessage($chatId, '⚠️ Order not found.', backKeyboard('orders'));
            return;
        }
        if (!in_array($order['status'], ['pending_payment', 'pending_verification'], true)) {
            sendMessage($chatId, '⚠️ This order is not awaiting payment.', backKeyboard('orders'));
            return;
        }
        setUserState($telegramId, 'awaiting_utr:' . $order['order_id']);
        sendMessage($chatId, "Please send your UTR / Transaction ID.");
        return;
    }

    // approve_<orderId> / reject_<orderId> — admin only
    if (preg_match('/^(approve|reject)_(FRENZY-[A-Z0-9]+)$/', $data, $m)) {
        if (!isAdmin($telegramId)) {
            answerCallback($callbackId, 'Not authorized.', true);
            return;
        }
        if ($m[1] === 'approve') {
            adminApprove($m[2]);
        } else {
            adminReject($m[2]);
        }
        return;
    }

    // Unknown callback data — ignore silently, no arbitrary execution.
}

// ---------------------------------------------------------------------
// 12. MESSAGE ROUTER
// ---------------------------------------------------------------------
function handleMessage(array $msg): void
{
    $chatId     = (string)$msg['chat']['id'];
    $telegramId = (string)($msg['from']['id'] ?? $chatId);
    $username   = $msg['from']['username'] ?? null;
    $firstName  = $msg['from']['first_name'] ?? 'there';
    $text       = trim((string)($msg['text'] ?? ''));

    upsertUser($telegramId, $username, $firstName);

    // --- Admin-only commands -------------------------------------------------
    if (isAdmin($telegramId)) {
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
        // Non-admins can never use admin-only commands.
        if (str_starts_with($text, '/reply ') || str_starts_with($text, '/deliver ')) {
            sendMessage($chatId, '⚠️ You are not authorized to use this command.');
            return;
        }
    }

    // --- /start ---------------------------------------------------------------
    if ($text === '/start') {
        setUserState($telegramId, 'idle');
        showHome($chatId, $firstName);
        return;
    }

    $state = getUserState($telegramId);

    // --- Awaiting UTR submission ------------------------------------------
    if (str_starts_with($state, 'awaiting_utr:')) {
        $orderId = substr($state, strlen('awaiting_utr:'));
        $order   = getOrder($orderId);

        if (!$order || $order['telegram_id'] !== $telegramId) {
            setUserState($telegramId, 'idle');
            sendMessage($chatId, '⚠️ That order could not be found.', backKeyboard('home'));
            return;
        }

        if (!isValidUtr($text)) {
            sendMessage($chatId, "⚠️ That doesn't look like a valid UTR / Transaction ID. Please try again.");
            return;
        }

        updateOrderStatus($orderId, 'pending_verification', 'utr', trim($text));
        setUserState($telegramId, 'idle');

        sendMessage(
            $chatId,
            "✅ <b>UTR Submitted</b>\n\nYour payment is now:\n⏳ Waiting for admin verification",
            backKeyboard('home')
        );

        notifyAdminOfPayment(getOrder($orderId), $username);
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
// 13. WEBHOOK ENTRY POINT
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
    // Best-effort friendly message; never leak exception details.
    if (isset($chatId) && is_string($chatId)) {
        friendlyError($chatId);
    }
}

http_response_code(200);
