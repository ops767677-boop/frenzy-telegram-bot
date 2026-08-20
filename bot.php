<?php
/**
 * =====================================================================
 * FRENZY STORE - Telegram Bot Backend (Premium Edition)
 * =====================================================================
 * Wallet checkout, DB-managed products, referral rewards, broadcast,
 * CSV export, and a full /admin dashboard — all admin actions gated
 * by Telegram ID (your ID is the login, no password to leak).
 *
 * Payments are NEVER auto-approved. A human admin must approve.
 * =====================================================================
 */

declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '0');

// ---------------------------------------------------------------------
// 1. CONFIGURATION
// ---------------------------------------------------------------------
const BOT_TOKEN     = '8965830768:AAFVs8RxGGwnLwIW8n1msmD0NUQqwzUIRpA';
const ADMIN_ID       = '8047005584';
const STORE_NAME     = 'Frenzy Store';
const BOT_USERNAME   = 'frenzykeyshopbot';
const DB_FILE        = __DIR__ . '/frenzy_store.sqlite';
const WEBHOOK_SECRET = '';

const MIN_TOPUP        = 10;
const MAX_TOPUP        = 100000;
const REFERRAL_BONUS   = 10; // ₹ credited to referrer on referred user's first paid order

$upiId       = 'sahid.frenzy@fam';
$paymentName = Frenzy Store;
$qrImageUrl  = '';

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
        id                 INTEGER PRIMARY KEY AUTOINCREMENT,
        telegram_id        TEXT UNIQUE NOT NULL,
        username           TEXT,
        first_name         TEXT,
        state              TEXT DEFAULT 'idle',
        balance            REAL DEFAULT 0,
        referred_by        TEXT,
        referral_earnings  REAL DEFAULT 0,
        referral_rewarded  INTEGER DEFAULT 0,
        created_at         TEXT DEFAULT CURRENT_TIMESTAMP
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS products (
        id         INTEGER PRIMARY KEY AUTOINCREMENT,
        price      REAL UNIQUE NOT NULL,
        name       TEXT NOT NULL,
        emoji      TEXT DEFAULT '🛒',
        tag        TEXT DEFAULT 'NEW',
        active     INTEGER DEFAULT 1,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP
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
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_users_referred_by ON users(referred_by)");

    // Seed default products once.
    $count = (int)$pdo->query('SELECT COUNT(*) FROM products')->fetchColumn();
    if ($count === 0) {
        $seed = $pdo->prepare('INSERT INTO products (price, name, emoji, tag) VALUES (?, ?, ?, ?)');
        $seed->execute([99, 'Premium Digital Product', '👑', 'POPULAR']);
        $seed->execute([199, 'VIP Digital Product', '💎', 'VIP']);
        $seed->execute([499, 'Ultimate Package', '⚡', 'BEST VALUE']);
    }
}

// ---------------------------------------------------------------------
// 3. TELEGRAM API HELPERS
// ---------------------------------------------------------------------
function tg(string $method, array $data = []): ?array
{
    $ch = curl_init('https://api.telegram.org/bot' . BOT_TOKEN . '/' . $method);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($data),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
    ]);
    $response = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);

    if ($response === false) {
        error_log('Telegram cURL error on ' . $method . ': ' . $err);
        return null;
    }
    $decoded = json_decode($response, true);
    if (!is_array($decoded) || empty($decoded['ok'])) {
        error_log('Telegram API error on ' . $method . ': ' . $response);
        return null;
    }
    return $decoded;
}

function sendDocument(string $chatId, string $filePath, string $caption = ''): ?array
{
    $ch = curl_init('https://api.telegram.org/bot' . BOT_TOKEN . '/sendDocument');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => [
            'chat_id'  => $chatId,
            'caption'  => $caption,
            'document' => new CURLFile($filePath),
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
    ]);
    $response = curl_exec($ch);
    curl_close($ch);
    $decoded = json_decode((string)$response, true);
    return is_array($decoded) ? $decoded : null;
}

function sendMessage(string $chatId, string $text, ?array $keyboard = null): void
{
    $payload = ['chat_id' => $chatId, 'text' => $text, 'parse_mode' => 'HTML'];
    if ($keyboard !== null) {
        $payload['reply_markup'] = json_encode(['inline_keyboard' => $keyboard]);
    }
    tg('sendMessage', $payload);
}

function answerCallback(string $callbackId, string $text = '', bool $alert = false): void
{
    tg('answerCallbackQuery', ['callback_query_id' => $callbackId, 'text' => $text, 'show_alert' => $alert]);
}

// ---------------------------------------------------------------------
// 4. UTILITIES
// ---------------------------------------------------------------------
function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
function isAdmin(string $telegramId): bool { return hash_equals(ADMIN_ID, $telegramId); }
function generateOrderId(): string { return 'FRENZY-' . strtoupper(bin2hex(random_bytes(3))); }
function generateTopupId(): string { return 'WALLET-' . strtoupper(bin2hex(random_bytes(3))); }
function money(float $amount): string { return '₹' . number_format($amount, 2); }
function friendlyError(string $chatId): void { sendMessage($chatId, "⚠️ Something went wrong.\n\nPlease try again or contact support."); }

// ---------------------------------------------------------------------
// 5. USER / WALLET / REFERRAL HELPERS
// ---------------------------------------------------------------------
function upsertUser(string $telegramId, ?string $username, ?string $firstName, ?string $referredBy = null): bool
{
    $pdo = db();
    $stmt = $pdo->prepare('SELECT id FROM users WHERE telegram_id = ?');
    $stmt->execute([$telegramId]);

    if ($stmt->fetch()) {
        $upd = $pdo->prepare('UPDATE users SET username = ?, first_name = ? WHERE telegram_id = ?');
        $upd->execute([$username, $firstName, $telegramId]);
        return false; // not new
    }

    $safeReferrer = null;
    if ($referredBy !== null && $referredBy !== $telegramId && ctype_digit($referredBy)) {
        $chk = $pdo->prepare('SELECT telegram_id FROM users WHERE telegram_id = ?');
        $chk->execute([$referredBy]);
        if ($chk->fetch()) {
            $safeReferrer = $referredBy;
        }
    }

    $ins = $pdo->prepare('INSERT INTO users (telegram_id, username, first_name, referred_by) VALUES (?, ?, ?, ?)');
    $ins->execute([$telegramId, $username, $firstName, $safeReferrer]);
    return true; // new user
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

function getUser(string $telegramId): ?array
{
    $stmt = db()->prepare('SELECT * FROM users WHERE telegram_id = ?');
    $stmt->execute([$telegramId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function getBalance(string $telegramId): float
{
    $u = getUser($telegramId);
    return $u ? (float)$u['balance'] : 0.0;
}

function adjustBalance(string $telegramId, float $delta): float
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('SELECT balance FROM users WHERE telegram_id = ?');
        $stmt->execute([$telegramId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $new = ($row ? (float)$row['balance'] : 0.0) + $delta;

        $upd = $pdo->prepare('UPDATE users SET balance = ? WHERE telegram_id = ?');
        $upd->execute([$new, $telegramId]);
        $pdo->commit();
        return $new;
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

// Rewards the referrer once, on the referred user's first paid order.
function maybeRewardReferral(string $telegramId): void
{
    $user = getUser($telegramId);
    if (!$user || !$user['referred_by'] || (int)$user['referral_rewarded'] === 1) {
        return;
    }

    $pdo = db();
    $upd = $pdo->prepare('UPDATE users SET referral_rewarded = 1 WHERE telegram_id = ?');
    $upd->execute([$telegramId]);

    adjustBalance($user['referred_by'], REFERRAL_BONUS);
    $earn = $pdo->prepare('UPDATE users SET referral_earnings = referral_earnings + ? WHERE telegram_id = ?');
    $earn->execute([REFERRAL_BONUS, $user['referred_by']]);

    sendMessage(
        $user['referred_by'],
        "🎁 <b>Referral Bonus!</b>\n━━━━━━━━━━━━━━━\nSomeone you referred just made their first purchase.\nYou earned " . money(REFERRAL_BONUS) . "!\n\nNew Balance: " . money(getBalance($user['referred_by']))
    );
}

// ---------------------------------------------------------------------
// 6. PRODUCT HELPERS
// ---------------------------------------------------------------------
function getActiveProducts(): array
{
    return db()->query('SELECT * FROM products WHERE active = 1 ORDER BY price ASC')->fetchAll(PDO::FETCH_ASSOC);
}

function getProductByPrice(float $price): ?array
{
    $stmt = db()->prepare('SELECT * FROM products WHERE price = ? AND active = 1');
    $stmt->execute([$price]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function addProduct(float $price, string $name, string $emoji, string $tag): bool
{
    try {
        $stmt = db()->prepare('INSERT INTO products (price, name, emoji, tag) VALUES (?, ?, ?, ?)');
        $stmt->execute([$price, $name, $emoji, $tag]);
        return true;
    } catch (Throwable $e) {
        return false; // likely duplicate price
    }
}

function removeProduct(float $price): bool
{
    $stmt = db()->prepare('DELETE FROM products WHERE price = ?');
    $stmt->execute([$price]);
    return $stmt->rowCount() > 0;
}

// ---------------------------------------------------------------------
// 7. ORDER / TOPUP HELPERS
// ---------------------------------------------------------------------
function createOrder(string $telegramId, string $productName, string $amount, string $status): array
{
    $orderId = generateOrderId();
    $stmt = db()->prepare('INSERT INTO orders (order_id, telegram_id, product, amount, status) VALUES (?, ?, ?, ?, ?)');
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
        $stmt = db()->prepare('UPDATE orders SET status = ?, delivery = ?, updated_at = CURRENT_TIMESTAMP WHERE order_id = ?');
        $stmt->execute([$status, $delivery, $orderId]);
    } else {
        $stmt = db()->prepare('UPDATE orders SET status = ?, updated_at = CURRENT_TIMESTAMP WHERE order_id = ?');
        $stmt->execute([$status, $orderId]);
    }
}

function createTopup(string $telegramId, float $amount): array
{
    $topupId = generateTopupId();
    $stmt = db()->prepare('INSERT INTO wallet_topups (topup_id, telegram_id, amount, status) VALUES (?, ?, ?, ?)');
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
        $stmt = db()->prepare('UPDATE wallet_topups SET status = ?, utr = ?, updated_at = CURRENT_TIMESTAMP WHERE topup_id = ?');
        $stmt->execute([$status, $utr, $topupId]);
    } else {
        $stmt = db()->prepare('UPDATE wallet_topups SET status = ?, updated_at = CURRENT_TIMESTAMP WHERE topup_id = ?');
        $stmt->execute([$status, $topupId]);
    }
}

function statusLabel(string $status): string
{
    return match ($status) {
        'pending_payment'      => '⏳ Payment Pending',
        'pending_verification' => '⏳ Waiting for Admin Verification',
        'paid'                 => '✅ Paid — Preparing Delivery',
        'rejected'             => '❌ Rejected',
        'completed'            => '🎉 Delivered',
        'cancelled'            => '🚫 Cancelled',
        default                => ucfirst($status),
    };
}

// ---------------------------------------------------------------------
// 8. KEYBOARDS
// ---------------------------------------------------------------------
function mainMenuKeyboard(): array
{
    return [
        [['text' => '🛍️ Browse Store', 'callback_data' => 'store'], ['text' => '💰 Wallet', 'callback_data' => 'wallet']],
        [['text' => '📦 My Orders', 'callback_data' => 'orders'], ['text' => '🎁 Refer & Earn', 'callback_data' => 'referral']],
        [['text' => '🎟️ Support', 'callback_data' => 'support'], ['text' => '👤 Profile', 'callback_data' => 'profile']],
    ];
}

function backKeyboard(string $target = 'home'): array
{
    return [[['text' => '⬅️ Back', 'callback_data' => $target]]];
}

function adminBackKeyboard(): array
{
    return [[['text' => '⬅️ Back to Dashboard', 'callback_data' => 'admin_home']]];
}

// ---------------------------------------------------------------------
// 9. CUSTOMER SCREENS
// ---------------------------------------------------------------------
function brandHeader(): string { return '✨ <b>' . h(STORE_NAME) . '</b> ✨'; }
function divider(): string { return '━━━━━━━━━━━━━━━'; }

function showHome(string $chatId, string $telegramId, string $firstName): void
{
    $balance = getBalance($telegramId);
    $text = "👋 Welcome, " . h($firstName) . "!\n\n"
          . brandHeader() . "\n" . divider() . "\n"
          . "🛍️ Premium digital products\n"
          . "⚡ Instant wallet checkout\n"
          . "🔐 Secure, admin-verified payments\n"
          . "🎁 Earn by referring friends\n"
          . divider() . "\n\n"
          . "💰 Wallet Balance: <b>" . money($balance) . "</b>";
    sendMessage($chatId, $text, mainMenuKeyboard());
}

function showStore(string $chatId): void
{
    $products = getActiveProducts();
    $text = brandHeader() . "\n<b>Available Products</b>\n" . divider() . "\n";
    $rows = [];

    if (!$products) {
        $text .= "\nNo products available right now — check back soon!";
    } else {
        $text .= "Select a product to view details:";
        foreach ($products as $p) {
            $rows[] = [[
                'text' => $p['emoji'] . ' ' . $p['name'] . ' — ₹' . (int)$p['price'] . ' (' . $p['tag'] . ')',
                'callback_data' => 'buy_' . (int)$p['price'],
            ]];
        }
    }
    $rows[] = [['text' => '⬅️ Back', 'callback_data' => 'home']];
    sendMessage($chatId, $text, $rows);
}

function showProductConfirm(string $chatId, int $price): void
{
    $p = getProductByPrice($price);
    if (!$p) {
        sendMessage($chatId, '⚠️ That product is no longer available.', backKeyboard('store'));
        return;
    }
    $text = "🛒 <b>Order Confirmation</b>\n" . divider() . "\n"
          . "Product:\n" . $p['emoji'] . ' ' . h($p['name']) . "\n\n"
          . "Price:\n₹" . (int)$price;
    $kb = [
        [['text' => '✅ Confirm Order', 'callback_data' => 'confirm_buy_' . $price]],
        [['text' => '❌ Cancel', 'callback_data' => 'cancel_order']],
    ];
    sendMessage($chatId, $text, $kb);
}

function processPurchase(string $chatId, string $telegramId, int $price, string $productName): void
{
    $balance = getBalance($telegramId);

    if ($balance < $price) {
        $need = $price - $balance;
        $text = "⚠️ <b>Insufficient Balance!</b>\n" . divider() . "\n"
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

    $text = "🎉 <b>Order Placed!</b>\n" . divider() . "\n"
          . "Order ID: " . h($order['order_id']) . "\n"
          . "Product: " . h($productName) . "\n"
          . "Amount: " . money((float)$price) . "\n"
          . "New Balance: " . money(getBalance($telegramId)) . "\n\n"
          . "Status: " . statusLabel('paid') . "\n"
          . "Our team will deliver your product here shortly.";
    sendMessage($chatId, $text, backKeyboard('home'));

    notifyAdminOfNewOrder($order);
    maybeRewardReferral($telegramId);
}

function showWallet(string $chatId, string $telegramId): void
{
    $balance = getBalance($telegramId);
    $text = "💰 <b>My Wallet</b>\n" . divider() . "\n"
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

    $text = "<b>📦 My Orders</b>\n" . divider() . "\n\n";
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

    $text = "<b>👤 Profile</b>\n" . divider() . "\n"
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
    sendMessage($chatId, "🎟️ <b>" . h(STORE_NAME) . " Support</b>\n" . divider() . "\nPlease type your question or issue, and our team will get back to you here.", backKeyboard('home'));
}

function showReferral(string $chatId, string $telegramId): void
{
    $user = getUser($telegramId);
    $stmt = db()->prepare('SELECT COUNT(*) FROM users WHERE referred_by = ?');
    $stmt->execute([$telegramId]);
    $count = (int)$stmt->fetchColumn();

    $link = 'https://t.me/' . BOT_USERNAME . '?start=' . $telegramId;
    $text = "🎁 <b>Refer & Earn</b>\n" . divider() . "\n"
          . "Share your link — earn " . money(REFERRAL_BONUS) . " when a friend makes their first purchase!\n\n"
          . "Your Link:\n<code>" . h($link) . "</code>\n\n"
          . "👥 Friends Referred: " . $count . "\n"
          . "💰 Total Earned: " . money((float)($user['referral_earnings'] ?? 0));
    sendMessage($chatId, $text, backKeyboard('home'));
}

// ---------------------------------------------------------------------
// 10. WALLET TOP-UP SCREENS
// ---------------------------------------------------------------------
function promptTopupAmount(string $chatId, string $telegramId): void
{
    setUserState($telegramId, 'awaiting_topup_amount');
    sendMessage($chatId, "➕ <b>Add Funds</b>\n" . divider() . "\nEnter the amount you'd like to add (₹" . MIN_TOPUP . " – ₹" . MAX_TOPUP . "):");
}

function showTopupPaymentScreen(string $chatId, array $topup): void
{
    global $upiId, $paymentName, $qrImageUrl;

    $text = "💳 <b>Add Funds — Payment</b>\n" . divider() . "\n"
          . "Request ID: " . h($topup['topup_id']) . "\n"
          . "Amount: " . money((float)$topup['amount']) . "\n\n"
          . "Pay via UPI to:\n<code>" . h($upiId) . "</code>\n"
          . "Name: " . h($paymentName) . "\n\n"
          . "After paying, tap <b>Submit UTR</b> below.";

    if ($qrImageUrl !== '') {
        tg('sendPhoto', ['chat_id' => $chatId, 'photo' => $qrImageUrl, 'caption' => "Scan to pay " . money((float)$topup['amount'])]);
    }

    $kb = [
        [['text' => '📤 Submit UTR', 'callback_data' => 'subtopututr_' . $topup['topup_id']]],
        [['text' => '❌ Cancel', 'callback_data' => 'home']],
    ];
    sendMessage($chatId, $text, $kb);
}

// ---------------------------------------------------------------------
// 11. VALIDATION
// ---------------------------------------------------------------------
function isValidUtr(string $utr): bool
{
    $utr = trim($utr);
    return $utr !== '' && mb_strlen($utr) <= 40 && (bool)preg_match('/^[A-Za-z0-9\- ]+$/', $utr);
}

function isValidAmount(string $text): bool
{
    if (!is_numeric($text)) return false;
    $amount = (float)$text;
    return $amount >= MIN_TOPUP && $amount <= MAX_TOPUP;
}

// ---------------------------------------------------------------------
// 12. ADMIN NOTIFICATIONS & ACTIONS
// ---------------------------------------------------------------------
function notifyAdminOfNewOrder(array $order): void
{
    $text = "🔔 <b>NEW ORDER — PAID FROM WALLET</b>\n" . divider() . "\n"
          . "Order ID: " . h($order['order_id']) . "\n"
          . "User ID: <code>" . h($order['telegram_id']) . "</code>\n"
          . "Product: " . h($order['product']) . "\n"
          . "Amount: ₹" . h($order['amount']) . "\n\n"
          . "Send delivery with:\n<code>/deliver " . h($order['order_id']) . " your content</code>";
    sendMessage(ADMIN_ID, $text);
}

function notifyAdminOfTopup(array $topup, ?string $username): void
{
    $text = "🔔 <b>NEW WALLET TOP-UP VERIFICATION</b>\n" . divider() . "\n"
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
    $text = "🔔 <b>New Support Message</b>\n" . divider() . "\n"
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
        sendMessage(ADMIN_ID, "⚠️ Top-up " . h($topupId) . " is not awaiting verification.");
        return;
    }
    updateTopupStatus($topupId, 'completed');
    $newBalance = adjustBalance($topup['telegram_id'], (float)$topup['amount']);

    sendMessage($topup['telegram_id'], "🎉 <b>Wallet Credited!</b>\n" . divider() . "\nAmount: " . money((float)$topup['amount']) . "\nNew Balance: " . money($newBalance), backKeyboard('home'));
    sendMessage(ADMIN_ID, "✅ Top-up " . h($topupId) . " approved. Wallet credited.");
}

function adminRejectTopup(string $topupId): void
{
    $topup = getTopup($topupId);
    if (!$topup || $topup['status'] !== 'pending_verification') {
        sendMessage(ADMIN_ID, "⚠️ Top-up " . h($topupId) . " is not awaiting verification.");
        return;
    }
    updateTopupStatus($topupId, 'rejected');
    sendMessage($topup['telegram_id'], "❌ <b>Top-up Rejected</b>\n" . divider() . "\nRequest ID: " . h($topupId) . "\n\nPlease contact support if you believe this was a mistake.");
    sendMessage(ADMIN_ID, "❌ Top-up " . h($topupId) . " rejected.");
}

function adminDeliver(string $orderId, string $content): void
{
    $order = getOrder($orderId);
    if (!$order) { sendMessage(ADMIN_ID, "⚠️ Order " . h($orderId) . " not found."); return; }
    if ($order['status'] !== 'paid') { sendMessage(ADMIN_ID, "⚠️ Order " . h($orderId) . " is not awaiting delivery."); return; }

    updateOrderStatus($orderId, 'completed', $content);
    sendMessage($order['telegram_id'], "🎉 <b>ORDER COMPLETED</b>\n" . divider() . "\n📦 Product: " . h($order['product']) . "\n🆔 Order: " . h($orderId) . "\n\n🔑 Delivery:\n" . h($content));
    sendMessage(ADMIN_ID, "📦 Delivery sent for order " . h($orderId) . ".");
}

function adminBroadcast(string $chatId, string $message): void
{
    $ids = db()->query('SELECT telegram_id FROM users')->fetchAll(PDO::FETCH_COLUMN);
    $sent = 0;
    $failed = 0;

    foreach ($ids as $uid) {
        $result = tg('sendMessage', [
            'chat_id'    => $uid,
            'text'       => "📢 <b>Announcement</b>\n" . divider() . "\n" . h($message),
            'parse_mode' => 'HTML',
        ]);
        $result === null ? $failed++ : $sent++;
        usleep(50000); // ~20 messages/sec, stays under Telegram's limits
    }

    sendMessage($chatId, "📢 Broadcast finished.\n\n✅ Sent: {$sent}\n❌ Failed: {$failed}");
}

function adminExportOrders(string $chatId): void
{
    $rows = db()->query('SELECT order_id, telegram_id, product, amount, status, created_at, updated_at FROM orders ORDER BY created_at DESC')->fetchAll(PDO::FETCH_ASSOC);

    $tmpFile = sys_get_temp_dir() . '/frenzy_orders_' . time() . '.csv';
    $fh = fopen($tmpFile, 'w');
    fputcsv($fh, ['Order ID', 'Telegram ID', 'Product', 'Amount', 'Status', 'Created At', 'Updated At']);
    foreach ($rows as $r) {
        fputcsv($fh, [$r['order_id'], $r['telegram_id'], $r['product'], $r['amount'], $r['status'], $r['created_at'], $r['updated_at']]);
    }
    fclose($fh);

    sendDocument($chatId, $tmpFile, 'Order history export — ' . count($rows) . ' orders');
    @unlink($tmpFile);
}

// ---------------------------------------------------------------------
// 13. ADMIN DASHBOARD (/admin)
// ---------------------------------------------------------------------
function showAdminDashboard(string $chatId): void
{
    $pdo = db();
    $totalUsers   = (int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
    $totalOrders  = (int)$pdo->query('SELECT COUNT(*) FROM orders')->fetchColumn();
    $totalRevenue = (float)$pdo->query("SELECT COALESCE(SUM(amount),0) FROM orders WHERE status IN ('paid','completed')")->fetchColumn();
    $pendingDeliv = (int)$pdo->query("SELECT COUNT(*) FROM orders WHERE status = 'paid'")->fetchColumn();
    $pendingTopup = (int)$pdo->query("SELECT COUNT(*) FROM wallet_topups WHERE status = 'pending_verification'")->fetchColumn();
    $productCount = (int)$pdo->query('SELECT COUNT(*) FROM products WHERE active = 1')->fetchColumn();

    $text = "🛠️ <b>Admin Dashboard</b>\n" . divider() . "\n"
          . "👥 Total Users: {$totalUsers}\n"
          . "📦 Total Orders: {$totalOrders}\n"
          . "🛒 Active Products: {$productCount}\n"
          . "💰 Total Revenue: " . money($totalRevenue) . "\n\n"
          . "⏳ Pending Top-up Verifications: {$pendingTopup}\n"
          . "📤 Orders Awaiting Delivery: {$pendingDeliv}\n"
          . divider() . "\n"
          . "<b>Text Commands</b>\n"
          . "<code>/addproduct price emoji tag name</code>\n"
          . "<code>/removeproduct price</code>\n"
          . "<code>/listproducts</code>\n"
          . "<code>/broadcast message</code>\n"
          . "<code>/export</code>\n"
          . "<code>/reply user_id message</code>\n"
          . "<code>/deliver order_id content</code>";

    $kb = [
        [['text' => '⏳ Pending Top-ups', 'callback_data' => 'admin_topups']],
        [['text' => '📤 Pending Deliveries', 'callback_data' => 'admin_deliveries']],
        [['text' => '🔄 Refresh', 'callback_data' => 'admin_home']],
    ];
    sendMessage($chatId, $text, $kb);
}

function showAdminPendingTopups(string $chatId): void
{
    $rows = db()->query("SELECT * FROM wallet_topups WHERE status = 'pending_verification' ORDER BY created_at ASC LIMIT 10")->fetchAll(PDO::FETCH_ASSOC);
    if (!$rows) { sendMessage($chatId, "✅ No pending top-up verifications.", adminBackKeyboard()); return; }

    foreach ($rows as $t) {
        $text = "🆔 " . h($t['topup_id']) . "\nUser: <code>" . h($t['telegram_id']) . "</code>\nAmount: " . money((float)$t['amount']) . "\nUTR: <code>" . h((string)$t['utr']) . "</code>";
        $kb = [[
            ['text' => '✅ Approve', 'callback_data' => 'approvetopup_' . $t['topup_id']],
            ['text' => '❌ Reject',  'callback_data' => 'rejecttopup_' . $t['topup_id']],
        ]];
        sendMessage($chatId, $text, $kb);
    }
    sendMessage($chatId, 'Use the buttons above, or go back.', adminBackKeyboard());
}

function showAdminPendingDeliveries(string $chatId): void
{
    $rows = db()->query("SELECT * FROM orders WHERE status = 'paid' ORDER BY created_at ASC LIMIT 10")->fetchAll(PDO::FETCH_ASSOC);
    if (!$rows) { sendMessage($chatId, "✅ No orders awaiting delivery.", adminBackKeyboard()); return; }

    $text = "📤 <b>Orders Awaiting Delivery</b>\n" . divider() . "\n\n";
    foreach ($rows as $o) {
        $text .= "🆔 " . h($o['order_id']) . "\nUser: <code>" . h($o['telegram_id']) . "</code>\nProduct: " . h($o['product']) . "\nSend: <code>/deliver " . h($o['order_id']) . " content</code>\n\n";
    }
    sendMessage($chatId, trim($text), adminBackKeyboard());
}

// ---------------------------------------------------------------------
// 14. CALLBACK ROUTER
// ---------------------------------------------------------------------
function handleCallback(array $cb): void
{
    $data       = $cb['data'] ?? '';
    $chatId     = (string)$cb['message']['chat']['id'];
    $telegramId = (string)$cb['from']['id'];
    $username   = $cb['from']['username'] ?? null;
    $firstName  = $cb['from']['first_name'] ?? 'there';
    $callbackId = $cb['id'];

    upsertUser($telegramId, $username, $firstName);
    answerCallback($callbackId);

    $known = ['home', 'store', 'orders', 'wallet', 'addfund', 'support', 'profile', 'referral', 'cancel_order'];

    if (in_array($data, $known, true)) {
        switch ($data) {
            case 'home': setUserState($telegramId, 'idle'); showHome($chatId, $telegramId, $firstName); return;
            case 'store': showStore($chatId); return;
            case 'orders': showOrders($chatId, $telegramId); return;
            case 'wallet': showWallet($chatId, $telegramId); return;
            case 'addfund': promptTopupAmount($chatId, $telegramId); return;
            case 'support': setUserState($telegramId, 'awaiting_support'); showSupportPrompt($chatId); return;
            case 'profile': showProfile($chatId, $telegramId, $username, $firstName); return;
            case 'referral': showReferral($chatId, $telegramId); return;
            case 'cancel_order': setUserState($telegramId, 'idle'); sendMessage($chatId, '🚫 Cancelled.', backKeyboard('home')); return;
        }
    }

    if (in_array($data, ['admin_home', 'admin_topups', 'admin_deliveries'], true)) {
        if (!isAdmin($telegramId)) { answerCallback($callbackId, 'Not authorized.', true); return; }
        match ($data) {
            'admin_home'       => showAdminDashboard($chatId),
            'admin_topups'     => showAdminPendingTopups($chatId),
            'admin_deliveries' => showAdminPendingDeliveries($chatId),
        };
        return;
    }

    if (preg_match('/^buy_(\d+)$/', $data, $m)) { showProductConfirm($chatId, (int)$m[1]); return; }

    if (preg_match('/^confirm_buy_(\d+)$/', $data, $m)) {
        $price = (int)$m[1];
        $p = getProductByPrice($price);
        if (!$p) { sendMessage($chatId, '⚠️ That product is no longer available.', backKeyboard('store')); return; }
        processPurchase($chatId, $telegramId, $price, $p['name']);
        return;
    }

    if (preg_match('/^subtopututr_(WALLET-[A-Z0-9]+)$/', $data, $m)) {
        $topup = getTopup($m[1]);
        if (!$topup || $topup['telegram_id'] !== $telegramId) { sendMessage($chatId, '⚠️ Request not found.', backKeyboard('wallet')); return; }
        setUserState($telegramId, 'awaiting_topup_utr:' . $topup['topup_id']);
        sendMessage($chatId, "Please send your UTR / Transaction ID.");
        return;
    }

    if (preg_match('/^(approvetopup|rejecttopup)_(WALLET-[A-Z0-9]+)$/', $data, $m)) {
        if (!isAdmin($telegramId)) { answerCallback($callbackId, 'Not authorized.', true); return; }
        $m[1] === 'approvetopup' ? adminApproveTopup($m[2]) : adminRejectTopup($m[2]);
        return;
    }
}

// ---------------------------------------------------------------------
// 15. MESSAGE ROUTER
// ---------------------------------------------------------------------
function handleMessage(array $msg): void
{
    $chatId     = (string)$msg['chat']['id'];
    $telegramId = (string)($msg['from']['id'] ?? $chatId);
    $username   = $msg['from']['username'] ?? null;
    $firstName  = $msg['from']['first_name'] ?? 'there';
    $text       = trim((string)($msg['text'] ?? ''));

    // Parse referral payload from a deep link: /start 123456789
    $referredBy = null;
    if (preg_match('/^\/start\s+(\d+)$/', $text, $m)) {
        $referredBy = $m[1];
    }
    $isNew = upsertUser($telegramId, $username, $firstName, $referredBy);

    // --- Admin-only commands. Telegram ID is the login. --------------------
    if (isAdmin($telegramId)) {
        if ($text === '/admin') { showAdminDashboard($chatId); return; }

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

        if (str_starts_with($text, '/addproduct ')) {
            $parts = preg_split('/\s+/', $text, 5);
            // /addproduct <price> <emoji> <tag> <name...>
            if (count($parts) === 5 && is_numeric($parts[1])) {
                $ok = addProduct((float)$parts[1], $parts[4], $parts[2], $parts[3]);
                sendMessage($chatId, $ok ? "✅ Product added." : "⚠️ A product at that price already exists.");
            } else {
                sendMessage($chatId, "Usage: /addproduct <price> <emoji> <tag> <name>\nExample: /addproduct 299 🎮 NEW Gaming Pack");
            }
            return;
        }

        if (str_starts_with($text, '/removeproduct ')) {
            $parts = explode(' ', $text, 2);
            if (count($parts) === 2 && is_numeric($parts[1])) {
                $ok = removeProduct((float)$parts[1]);
                sendMessage($chatId, $ok ? "✅ Product removed." : "⚠️ No product found at that price.");
            } else {
                sendMessage($chatId, 'Usage: /removeproduct <price>');
            }
            return;
        }

        if ($text === '/listproducts') {
            $rows = db()->query('SELECT * FROM products ORDER BY price ASC')->fetchAll(PDO::FETCH_ASSOC);
            if (!$rows) { sendMessage($chatId, 'No products yet.'); return; }
            $out = "<b>🛒 All Products</b>\n" . divider() . "\n";
            foreach ($rows as $p) {
                $status = $p['active'] ? '✅' : '🚫';
                $out .= "{$status} {$p['emoji']} " . h($p['name']) . " — ₹" . (int)$p['price'] . " ({$p['tag']})\n";
            }
            sendMessage($chatId, $out);
            return;
        }

        if (str_starts_with($text, '/broadcast ')) {
            $message = substr($text, strlen('/broadcast '));
            adminBroadcast($chatId, $message);
            return;
        }

        if ($text === '/export') {
            adminExportOrders($chatId);
            return;
        }
    } else {
        if ($text === '/admin' || str_starts_with($text, '/reply ') || str_starts_with($text, '/deliver ')
            || str_starts_with($text, '/addproduct ') || str_starts_with($text, '/removeproduct ')
            || $text === '/listproducts' || str_starts_with($text, '/broadcast ') || $text === '/export') {
            sendMessage($chatId, '⚠️ You are not authorized to use this command.');
            return;
        }
    }

    // --- /start (with or without referral payload) --------------------------
    if ($text === '/start' || $referredBy !== null) {
        setUserState($telegramId, 'idle');
        showHome($chatId, $telegramId, $firstName);
        return;
    }

    $state = getUserState($telegramId);

    if ($state === 'awaiting_topup_amount') {
        if (!isValidAmount($text)) { sendMessage($chatId, "⚠️ Enter a valid amount between ₹" . MIN_TOPUP . " and ₹" . MAX_TOPUP . "."); return; }
        $topup = createTopup($telegramId, (float)$text);
        setUserState($telegramId, 'idle');
        showTopupPaymentScreen($chatId, $topup);
        return;
    }

    if (str_starts_with($state, 'awaiting_topup_utr:')) {
        $topupId = substr($state, strlen('awaiting_topup_utr:'));
        $topup   = getTopup($topupId);

        if (!$topup || $topup['telegram_id'] !== $telegramId) {
            setUserState($telegramId, 'idle');
            sendMessage($chatId, '⚠️ That request could not be found.', backKeyboard('home'));
            return;
        }
        if (!isValidUtr($text)) { sendMessage($chatId, "⚠️ That doesn't look like a valid UTR / Transaction ID. Please try again."); return; }

        updateTopupStatus($topupId, 'pending_verification', trim($text));
        setUserState($telegramId, 'idle');
        sendMessage($chatId, "✅ <b>UTR Submitted</b>\n" . divider() . "\nYour top-up is now:\n⏳ Waiting for admin verification", backKeyboard('home'));
        notifyAdminOfTopup(getTopup($topupId), $username);
        return;
    }

    if ($state === 'awaiting_support') {
        if ($text === '') { sendMessage($chatId, 'Please type your question or issue as text.'); return; }
        $stmt = db()->prepare('INSERT INTO support_messages (telegram_id, message) VALUES (?, ?)');
        $stmt->execute([$telegramId, $text]);
        setUserState($telegramId, 'idle');
        sendMessage($chatId, "✅ Your message has been sent to our support team. We'll get back to you here soon.", backKeyboard('home'));
        notifyAdminOfSupport($telegramId, $username, $text);
        return;
    }

    sendMessage($chatId, "Please use the buttons below to navigate " . h(STORE_NAME) . ".", mainMenuKeyboard());
}

// ---------------------------------------------------------------------
// 16. WEBHOOK ENTRY POINT
// ---------------------------------------------------------------------
try {
    if (WEBHOOK_SECRET !== '') {
        $incomingSecret = $_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? '';
        if (!hash_equals(WEBHOOK_SECRET, $incomingSecret)) { http_response_code(403); exit; }
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
    if (isset($chatId) && is_string($chatId)) { friendlyError($chatId); }
}

http_response_code(200);
