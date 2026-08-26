<?php
ob_start();

/*
|--------------------------------------------------------------------------
| TELEGRAM BOT CONFIG
|--------------------------------------------------------------------------
| 1. BOT_TOKEN = BotFather token
| 2. ADMIN_ID  = your Telegram numeric user ID
|
| Payment is MANUAL UPI:
| - Admin sets UPI ID from /admin
| - User selects exact amount
| - Dynamic QR is generated for that exact amount
| - User sends payment screenshot
| - Admin gets screenshot + Approve/Reject buttons
| - Approve -> admin enters key + download link -> user receives both
|--------------------------------------------------------------------------
*/

$botToken = "8965830768:AAFVs8RxGGwnLwIW8n1msmD0NUQqwzUIRpA";
$adminID  = "8777129138";
$website  = "https://api.telegram.org/bot" . $botToken;

foreach ([
    "balances.json",
    "orders.json",
    "temp.json",
    "users.json",
    "settings.json",
    "categories.json",
    "products.json"
] as $file) {
    if (!file_exists($file)) file_put_contents($file, "{}");
}

/* ---------------- SETTINGS ---------------- */
$settings = json_decode(file_get_contents("settings.json"), true);
if (!is_array($settings)) $settings = [];

$defaults = [
    "upi_id"       => "",
    "upi_name"     => "Payment",
    "proof_link"   => "",
    "howto_link"   => "",
    "support_user" => ""
];

foreach ($defaults as $k => $v) {
    if (!isset($settings[$k])) $settings[$k] = $v;
}
file_put_contents("settings.json", json_encode($settings, JSON_PRETTY_PRINT));

/* ---------------- CATEGORIES ----------------
| Intentionally empty. Add your own categories from /admin.
*/
$categories = json_decode(file_get_contents("categories.json"), true);
if (!is_array($categories)) $categories = [];
file_put_contents("categories.json", json_encode($categories, JSON_PRETTY_PRINT));

/* ---------------- UPDATE ---------------- */
$update = json_decode(file_get_contents("php://input"), true);
if (!is_array($update)) exit;

$isCallback = isset($update["callback_query"]);

if ($isCallback) {
    $cb = $update["callback_query"];
    $chat_id    = $cb["message"]["chat"]["id"] ?? 0;
    $message_id = $cb["message"]["message_id"] ?? 0;
    $first_name = $cb["from"]["first_name"] ?? "User";
    $username   = $cb["from"]["username"] ?? "";
    $user_id    = $cb["from"]["id"] ?? 0;
    $text       = "";
    $data       = $cb["data"] ?? "";
    answerCallback($cb["id"] ?? "");
} else {
    $message = $update["message"] ?? [];
    $chat_id    = $message["chat"]["id"] ?? 0;
    $message_id = $message["message_id"] ?? 0;
    $first_name = $message["chat"]["first_name"] ?? "User";
    $username   = $message["chat"]["username"] ?? "";
    $user_id    = $message["from"]["id"] ?? ($message["chat"]["id"] ?? 0);
    $text       = trim($message["text"] ?? "");
    $data       = "";
}

/* ---------------- LOAD DATA ---------------- */
$balances = json_decode(file_get_contents("balances.json"), true);
$users    = json_decode(file_get_contents("users.json"), true);
$orders   = json_decode(file_get_contents("orders.json"), true);
$products = json_decode(file_get_contents("products.json"), true);
$temp     = json_decode(file_get_contents("temp.json"), true);

if (!is_array($balances)) $balances = [];
if (!is_array($users))    $users = [];
if (!is_array($orders))   $orders = [];
if (!is_array($products)) $products = [];
if (!is_array($temp))     $temp = [];

if (!isset($balances[$user_id])) $balances[$user_id] = 0;

if ($user_id && !isset($users[$user_id])) {
    $users[$user_id] = [
        "name"     => $first_name,
        "username" => $username,
        "join"     => date("d M Y")
    ];
    saveJSON("users.json", $users);
}
saveJSON("balances.json", $balances);

/* =========================================================
   COMMANDS
   ========================================================= */

if (!$isCallback && $text === "/start") {
    sendMainMenu($chat_id, $first_name, $balances[$user_id] ?? 0);
    exit;
}

if (!$isCallback && $text === "/admin") {
    if ((string)$user_id !== (string)$adminID) {
        sendMessage($chat_id, "❌ Only admin can open this panel.");
        exit;
    }
    sendAdminPanel($chat_id);
    exit;
}

/* =========================================================
   CALLBACKS
   ========================================================= */
if ($isCallback) {

    /* USER */
    if ($data === "addbal") {
        sendAddBalance($chat_id, $message_id, $user_id);
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

    if ($data === "shop") {
        sendCategories($chat_id, $message_id);
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

    if ($data === "back") {
        sendMainMenu($chat_id, $first_name, $balances[$user_id] ?? 0, $message_id);
        exit;
    }

    if ($data === "backcat" || $data === "backshop") {
        sendCategories($chat_id, $message_id);
        exit;
    }

    if ($data === "backkey") {
        sendAddBalance($chat_id, $message_id, $user_id);
        exit;
    }

    /* SHOP */
    if (strpos($data, "cat_") === 0) {
        sendProducts($chat_id, $message_id, substr($data, 4));
        exit;
    }

    if (strpos($data, "buy_") === 0) {
        sendProductPlans($chat_id, $message_id, substr($data, 4));
        exit;
    }

    if (strpos($data, "plan_") === 0) {
        $parts = explode("_", $data);
        if (count($parts) >= 3) {
            buyPlan(
                $chat_id,
                $message_id,
                $user_id,
                $parts[1],
                (int)$parts[2]
            );
        }
        exit;
    }

    /* PAYMENT AMOUNT */
    if (strpos($data, "pay_") === 0) {
        createUPIOrder(
            $chat_id,
            $message_id,
            $user_id,
            (int)substr($data, 4)
        );
        exit;
    }

    if ($data === "custom") {
        saveTemp($user_id, "amount", "0");
        sendKeypad($chat_id, $message_id, $user_id, "0");
        exit;
    }

    if (strpos($data, "key_") === 0) {
        handleKeypad(
            $chat_id,
            $message_id,
            $user_id,
            substr($data, 4)
        );
        exit;
    }

    if (strpos($data, "confirm_") === 0) {
        createUPIOrder(
            $chat_id,
            $message_id,
            $user_id,
            (int)substr($data, 8)
        );
        exit;
    }

    if (strpos($data, "cancel_pay_") === 0) {
        cancelPaymentOrder(
            $chat_id,
            $message_id,
            substr($data, 11),
            $user_id
        );
        exit;
    }

    /* ---------------- ADMIN ---------------- */
    if ((string)$user_id === (string)$adminID) {

        if ($data === "backadmin") {
            sendAdminPanel($chat_id, $message_id);
            exit;
        }

        if ($data === "addcat") {
            saveTemp($user_id, "waiting", "newcat");
            editMsg(
                $chat_id,
                $message_id,
                "📁 <b>Category ka naam bhejo</b>\n\nExample: Premium",
                btn([[["⬅️ Cancel", "backadmin"]]])
            );
            exit;
        }

        if ($data === "addprod") {
            sendSelectCatForProduct($chat_id, $message_id);
            exit;
        }

        if (strpos($data, "addtoprod_") === 0) {
            $cid = substr($data, 11);
            saveTemp($user_id, "addprod_cat", $cid);
            saveTemp($user_id, "waiting", "prod_name");
            editMsg(
                $chat_id,
                $message_id,
                "📦 <b>Product name bhejo</b>",
                btn([[["⬅️ Cancel", "backadmin"]]])
            );
            exit;
        }

        if ($data === "addplan") {
            sendSelectProductForAddPlan($chat_id, $message_id);
            exit;
        }

        if (strpos($data, "addplanprod_") === 0) {
            $pid = substr($data, 12);
            saveTemp($user_id, "addplan_pid", $pid);
            saveTemp($user_id, "waiting", "plan_days");
            editMsg(
                $chat_id,
                $message_id,
                "📅 <b>Plan kitne days ka hai?</b>\n\nExample: 1, 3, 7, 30",
                btn([[["⬅️ Cancel", "backadmin"]]])
            );
            exit;
        }

        if ($data === "addplankeys") {
            sendSelectProductForAddPlanKeys($chat_id, $message_id);
            exit;
        }

        if (strpos($data, "addkeysprod_") === 0) {
            sendPlansForAddKeys($chat_id, $message_id, substr($data, 12));
            exit;
        }

        if (strpos($data, "addkeysplan_") === 0) {
            $parts = explode("_", $data);
            if (count($parts) >= 3) {
                saveTemp($user_id, "addkeys_pid", $parts[1]);
                saveTemp($user_id, "addkeys_plan_index", (int)$parts[2]);
                saveTemp($user_id, "waiting", "add_plan_keys");
                editMsg(
                    $chat_id,
                    $message_id,
                    "🔑 <b>Keys bhejo</b>\n\nEk line me ek key.",
                    btn([[["⬅️ Cancel", "backadmin"]]])
                );
            }
            exit;
        }

        if ($data === "editplan") {
            sendSelectProductForEditPlan($chat_id, $message_id);
            exit;
        }

        if (strpos($data, "editplanprod_") === 0) {
            sendPlansForEdit($chat_id, $message_id, substr($data, 13));
            exit;
        }

        if (strpos($data, "editplan_") === 0) {
            $parts = explode("_", $data);
            if (count($parts) >= 3) {
                $pid = $parts[1];
                $idx = (int)$parts[2];
                if (isset($products[$pid]["plans"][$idx])) {
                    saveTemp($user_id, "edit_pid", $pid);
                    saveTemp($user_id, "edit_plan_index", $idx);
                    $p = $products[$pid]["plans"][$idx];
                    editMsg(
                        $chat_id,
                        $message_id,
                        "✏️ <b>Edit Plan</b>\n\nDays: {$p['days']}\nPrice: ₹{$p['price']}\nKeys: ".count($p["keys"] ?? []),
                        btn([
                            [["📅 Days", "editplandays_{$pid}_{$idx}"]],
                            [["💰 Price", "editplanprice_{$pid}_{$idx}"]],
                            [["🔑 Add Keys", "editplankeys_{$pid}_{$idx}"]],
                            [["⬅️ Back", "backadmin"]]
                        ])
                    );
                }
            }
            exit;
        }

        if (strpos($data, "editplandays_") === 0) {
            $parts = explode("_", $data);
            saveTemp($user_id, "edit_pid", $parts[1]);
            saveTemp($user_id, "edit_plan_index", (int)$parts[2]);
            saveTemp($user_id, "waiting", "edit_plan_days");
            editMsg($chat_id, $message_id, "📅 <b>Naye days bhejo</b>", btn([[["⬅️ Cancel","backadmin"]]]));
            exit;
        }

        if (strpos($data, "editplanprice_") === 0) {
            $parts = explode("_", $data);
            saveTemp($user_id, "edit_pid", $parts[1]);
            saveTemp($user_id, "edit_plan_index", (int)$parts[2]);
            saveTemp($user_id, "waiting", "edit_plan_price");
            editMsg($chat_id, $message_id, "💰 <b>Naya price bhejo</b>", btn([[["⬅️ Cancel","backadmin"]]]));
            exit;
        }

        if (strpos($data, "editplankeys_") === 0) {
            $parts = explode("_", $data);
            saveTemp($user_id, "edit_pid", $parts[1]);
            saveTemp($user_id, "edit_plan_index", (int)$parts[2]);
            saveTemp($user_id, "waiting", "edit_plan_keys");
            editMsg($chat_id, $message_id, "🔑 <b>Nayi keys bhejo</b>\n\nEk line me ek key.", btn([[["⬅️ Cancel","backadmin"]]]));
            exit;
        }

        if ($data === "delplan") {
            sendSelectProductForDeletePlan($chat_id, $message_id);
            exit;
        }

        if (strpos($data, "delplanprod_") === 0) {
            sendPlansForDelete($chat_id, $message_id, substr($data, 13));
            exit;
        }

        if (strpos($data, "delplan_") === 0) {
            $parts = explode("_", $data);
            $pid = $parts[1];
            $idx = (int)$parts[2];

            if (isset($products[$pid]["plans"][$idx])) {
                $old = $products[$pid]["plans"][$idx];
                unset($products[$pid]["plans"][$idx]);
                $products[$pid]["plans"] = array_values($products[$pid]["plans"]);
                saveJSON("products.json", $products);

                editMsg(
                    $chat_id,
                    $message_id,
                    "🗑️ <b>Plan deleted</b>\n\n{$old['days']} Days — ₹{$old['price']}",
                    btn([[["⬅️ Back", "backadmin"]]])
                );
            }
            exit;
        }

        if ($data === "editprod") {
            sendSelectProductForEdit($chat_id, $message_id);
            exit;
        }

        if (strpos($data, "editprod_") === 0) {
            $pid = substr($data, 9);
            if (isset($products[$pid])) {
                saveTemp($user_id, "edit_pid", $pid);
                editMsg(
                    $chat_id,
                    $message_id,
                    "✏️ <b>Edit Product</b>\n\nName: {$products[$pid]['name']}",
                    btn([
                        [["📝 Change Name", "edit_name_$pid"]],
                        [["⬅️ Back", "backadmin"]]
                    ])
                );
            }
            exit;
        }

        if (strpos($data, "edit_name_") === 0) {
            saveTemp($user_id, "edit_pid", substr($data, 10));
            saveTemp($user_id, "waiting", "edit_name");
            editMsg($chat_id, $message_id, "📝 <b>Naya product name bhejo</b>", btn([[["⬅️ Cancel","backadmin"]]]));
            exit;
        }

        if ($data === "delprod") {
            sendSelectProductForDelete($chat_id, $message_id);
            exit;
        }

        if (strpos($data, "delprod_") === 0) {
            $pid = substr($data, 8);
            if (isset($products[$pid])) {
                $old = $products[$pid];
                unset($products[$pid]);
                saveJSON("products.json", $products);
                editMsg(
                    $chat_id,
                    $message_id,
                    "🗑️ <b>Product deleted</b>\n\n{$old['name']}",
                    btn([[["⬅️ Back", "backadmin"]]])
                );
            }
            exit;
        }

        if ($data === "userlist") {
            sendUserList($chat_id, $message_id);
            exit;
        }

        if ($data === "broadcast") {
            saveTemp($user_id, "waiting", "broadcast_text");
            editMsg($chat_id, $message_id, "📢 <b>Broadcast message bhejo</b>", btn([[["⬅️ Cancel","backadmin"]]]));
            exit;
        }

        if ($data === "adduserbal") {
            saveTemp($user_id, "waiting", "adduserbal_id");
            editMsg($chat_id, $message_id, "💰 <b>User ID bhejo</b>", btn([[["⬅️ Cancel","backadmin"]]]));
            exit;
        }

        /* UPI SETTINGS */
        if ($data === "setupi") {
            saveTemp($user_id, "waiting", "upi_id");
            editMsg(
                $chat_id,
                $message_id,
                "💳 <b>Apni UPI ID bhejo</b>\n\nExample:\n9876543210@upi\n\nIsi UPI ID par dynamic QR generate hoga.",
                btn([[["⬅️ Cancel","backadmin"]]])
            );
            exit;
        }

        if ($data === "setupiname") {
            saveTemp($user_id, "waiting", "upi_name");
            editMsg(
                $chat_id,
                $message_id,
                "👤 <b>UPI receiver name bhejo</b>\n\nExample: My Store",
                btn([[["⬅️ Cancel","backadmin"]]])
            );
            exit;
        }

        if ($data === "setproof") {
            saveTemp($user_id, "waiting", "proof");
            editMsg($chat_id, $message_id, "📄 <b>Proof channel/link bhejo</b>", btn([[["⬅️ Cancel","backadmin"]]]));
            exit;
        }

        if ($data === "sethowto") {
            saveTemp($user_id, "waiting", "howto");
            editMsg($chat_id, $message_id, "📖 <b>How-to link bhejo</b>", btn([[["⬅️ Cancel","backadmin"]]]));
            exit;
        }

        if ($data === "setsupport") {
            saveTemp($user_id, "waiting", "support");
            editMsg($chat_id, $message_id, "💬 <b>Support username bhejo</b>\n\nExample: @support", btn([[["⬅️ Cancel","backadmin"]]]));
            exit;
        }

        /* PAYMENT APPROVAL */
        if (strpos($data, "approve_") === 0) {
            $order_id = substr($data, 8);
            approvePaymentRequest($chat_id, $message_id, $order_id);
            exit;
        }

        if (strpos($data, "reject_") === 0) {
            $order_id = substr($data, 7);
            if (isset($orders[$order_id])) {
                saveTemp($user_id, "waiting", "reject_reason");
                saveTemp($user_id, "reject_order_id", $order_id);
                editMsg(
                    $chat_id,
                    $message_id,
                    "❌ <b>Reject reason bhejo</b>\n\nOrder: <code>$order_id</code>",
                    btn([[["⬅️ Cancel", "backadmin"]]])
                );
            }
            exit;
        }

        /* KEY ORDER APPROVAL */
        if (strpos($data, "deliver_") === 0) {
            $order_id = substr($data, 8);
            if (isset($orders[$order_id])) {
                saveTemp($user_id, "waiting", "deliver_key");
                saveTemp($user_id, "deliver_order_id", $order_id);
                editMsg(
                    $chat_id,
                    $message_id,
                    "🔑 <b>Key bhejo</b>\n\nOrder: <code>$order_id</code>\n\nAgar key nahi hai to bhi manually supplied key/status use kar sakte ho.",
                    btn([[["⬅️ Cancel", "backadmin"]]])
                );
            }
            exit;
        }
    }

    exit;
}

/* =========================================================
   TEXT / PHOTO / DOCUMENT INPUTS
   ========================================================= */
$temp = json_decode(file_get_contents("temp.json"), true);
$waiting = $temp[$user_id]["waiting"] ?? "";

/* ---------------- ADMIN TEXT INPUT ---------------- */
if ((string)$user_id === (string)$adminID && $text !== "") {

    if ($waiting === "newcat") {
        $cid = "c" . time() . rand(10,99);
        $categories[$cid] = ["name" => cleanText($text)];
        saveJSON("categories.json", $categories);
        clearWaiting($user_id);
        sendMessage($chat_id, "✅ Category add ho gayi: <b>".htmlspecialchars($text)."</b>");
        sendAdminPanel($chat_id);
        exit;
    }

    if ($waiting === "prod_name") {
        $cid = $temp[$user_id]["addprod_cat"] ?? "";
        if (!isset($categories[$cid])) {
            clearWaiting($user_id);
            sendMessage($chat_id, "❌ Category nahi mili.");
            exit;
        }

        $pid = "p" . time() . rand(10,99);
        $products[$pid] = [
            "name" => cleanText($text),
            "cat" => $cid,
            "plans" => []
        ];
        saveJSON("products.json", $products);

        clearWaiting($user_id);
        sendMessage($chat_id, "✅ Product add ho gaya.");
        sendAdminPanel($chat_id);
        exit;
    }

    if ($waiting === "plan_days") {
        $days = (int)$text;
        if ($days < 1) {
            sendMessage($chat_id, "❌ Valid days bhejo.");
            exit;
        }
        saveTemp($user_id, "plan_days", $days);
        saveTemp($user_id, "waiting", "plan_price");
        sendMessage($chat_id, "💰 <b>Plan price bhejo</b>\n\nExample: 99");
        exit;
    }

    if ($waiting === "plan_price") {
        $pid = $temp[$user_id]["addplan_pid"] ?? "";
        $days = (int)($temp[$user_id]["plan_days"] ?? 0);
        $price = (int)$text;

        if (!isset($products[$pid]) || $days < 1 || $price < 1) {
            sendMessage($chat_id, "❌ Invalid product/days/price.");
            exit;
        }

        $products[$pid]["plans"][] = [
            "days" => $days,
            "price" => $price,
            "keys" => []
        ];
        saveJSON("products.json", $products);

        clearWaiting($user_id);
        sendMessage($chat_id, "✅ Plan add ho gaya: <b>$days Days — ₹$price</b>");
        sendAdminPanel($chat_id);
        exit;
    }

    if ($waiting === "add_plan_keys") {
        $pid = $temp[$user_id]["addkeys_pid"] ?? "";
        $idx = (int)($temp[$user_id]["addkeys_plan_index"] ?? -1);
        $keys = array_values(array_filter(array_map("trim", preg_split("/\R/", $text))));

        if (!isset($products[$pid]["plans"][$idx]) || !$keys) {
            sendMessage($chat_id, "❌ Keys ya plan invalid hai.");
            exit;
        }

        $products[$pid]["plans"][$idx]["keys"] =
            array_merge($products[$pid]["plans"][$idx]["keys"] ?? [], $keys);

        saveJSON("products.json", $products);
        clearWaiting($user_id);

        sendMessage($chat_id, "✅ ".count($keys)." keys add ho gayi.");
        sendAdminPanel($chat_id);
        exit;
    }

    if ($waiting === "edit_name") {
        $pid = $temp[$user_id]["edit_pid"] ?? "";
        if (isset($products[$pid])) {
            $products[$pid]["name"] = cleanText($text);
            saveJSON("products.json", $products);
            clearWaiting($user_id);
            sendMessage($chat_id, "✅ Product name update ho gaya.");
            sendAdminPanel($chat_id);
        }
        exit;
    }

    if ($waiting === "edit_plan_days") {
        $pid = $temp[$user_id]["edit_pid"] ?? "";
        $idx = (int)($temp[$user_id]["edit_plan_index"] ?? -1);
        $days = (int)$text;
        if (isset($products[$pid]["plans"][$idx]) && $days > 0) {
            $products[$pid]["plans"][$idx]["days"] = $days;
            saveJSON("products.json", $products);
            clearWaiting($user_id);
            sendMessage($chat_id, "✅ Days update.");
            sendAdminPanel($chat_id);
        }
        exit;
    }

    if ($waiting === "edit_plan_price") {
        $pid = $temp[$user_id]["edit_pid"] ?? "";
        $idx = (int)($temp[$user_id]["edit_plan_index"] ?? -1);
        $price = (int)$text;
        if (isset($products[$pid]["plans"][$idx]) && $price > 0) {
            $products[$pid]["plans"][$idx]["price"] = $price;
            saveJSON("products.json", $products);
            clearWaiting($user_id);
            sendMessage($chat_id, "✅ Price update.");
            sendAdminPanel($chat_id);
        }
        exit;
    }

    if ($waiting === "edit_plan_keys") {
        $pid = $temp[$user_id]["edit_pid"] ?? "";
        $idx = (int)($temp[$user_id]["edit_plan_index"] ?? -1);
        $keys = array_values(array_filter(array_map("trim", preg_split("/\R/", $text))));
        if (isset($products[$pid]["plans"][$idx]) && $keys) {
            $products[$pid]["plans"][$idx]["keys"] =
                array_merge($products[$pid]["plans"][$idx]["keys"] ?? [], $keys);
            saveJSON("products.json", $products);
            clearWaiting($user_id);
            sendMessage($chat_id, "✅ ".count($keys)." keys add ho gayi.");
            sendAdminPanel($chat_id);
        }
        exit;
    }

    /* UPI ID */
    if ($waiting === "upi_id") {
        $upi = trim($text);
        if (!preg_match('/^[A-Za-z0-9._-]{2,256}@[A-Za-z0-9.-]{2,64}$/', $upi)) {
            sendMessage($chat_id, "❌ UPI ID format galat hai.\nExample: 9876543210@upi");
            exit;
        }

        $settings["upi_id"] = $upi;
        saveJSON("settings.json", $settings);
        clearWaiting($user_id);

        sendMessage($chat_id, "✅ UPI ID update ho gayi:\n<code>".htmlspecialchars($upi)."</code>");
        sendAdminPanel($chat_id);
        exit;
    }

    /* UPI NAME */
    if ($waiting === "upi_name") {
        $settings["upi_name"] = cleanText($text);
        saveJSON("settings.json", $settings);
        clearWaiting($user_id);

        sendMessage($chat_id, "✅ UPI receiver name update ho gaya.");
        sendAdminPanel($chat_id);
        exit;
    }

    if ($waiting === "proof") {
        $settings["proof_link"] = trim($text);
        saveJSON("settings.json", $settings);
        clearWaiting($user_id);
        sendMessage($chat_id, "✅ Proof link update.");
        sendAdminPanel($chat_id);
        exit;
    }

    if ($waiting === "howto") {
        $settings["howto_link"] = trim($text);
        saveJSON("settings.json", $settings);
        clearWaiting($user_id);
        sendMessage($chat_id, "✅ How-to link update.");
        sendAdminPanel($chat_id);
        exit;
    }

    if ($waiting === "support") {
        $settings["support_user"] = trim($text);
        saveJSON("settings.json", $settings);
        clearWaiting($user_id);
        sendMessage($chat_id, "✅ Support update.");
        sendAdminPanel($chat_id);
        exit;
    }

    /* ADD USER BALANCE */
    if ($waiting === "adduserbal_id") {
        $target = trim($text);
        if (!isset($users[$target])) {
            sendMessage($chat_id, "❌ User ID nahi mila.");
            exit;
        }
        saveTemp($user_id, "adduserbal_target", $target);
        saveTemp($user_id, "waiting", "adduserbal_amount");
        sendMessage($chat_id, "💰 Kitna balance add karna hai?");
        exit;
    }

    if ($waiting === "adduserbal_amount") {
        $target = $temp[$user_id]["adduserbal_target"] ?? "";
        $amount = (int)$text;

        if (!isset($users[$target]) || $amount < 1) {
            sendMessage($chat_id, "❌ Invalid amount/user.");
            exit;
        }

        $balances[$target] = ($balances[$target] ?? 0) + $amount;
        saveJSON("balances.json", $balances);
        clearWaiting($user_id);

        sendMessage($chat_id, "✅ ₹$amount balance add kar diya.\nNew balance: ₹".$balances[$target]);
        sendMessage($target, "💰 <b>Balance Added</b>\n\n₹$amount add hua.\nNew Balance: ₹".$balances[$target]);
        sendAdminPanel($chat_id);
        exit;
    }

    /* REJECT PAYMENT */
    if ($waiting === "reject_reason") {
        $order_id = $temp[$user_id]["reject_order_id"] ?? "";
        rejectPaymentRequest($chat_id, $order_id, $text);
        exit;
    }

    /* DELIVER KEY */
    if ($waiting === "deliver_key") {
        $order_id = $temp[$user_id]["deliver_order_id"] ?? "";
        if (!isset($orders[$order_id])) {
            clearWaiting($user_id);
            sendMessage($chat_id, "❌ Order nahi mila.");
            exit;
        }

        $orders[$order_id]["key"] = trim($text);
        $orders[$order_id]["status"] = "approved";
        saveJSON("orders.json", $orders);

        saveTemp($user_id, "deliver_order_id", $order_id);
        saveTemp($user_id, "waiting", "deliver_link");

        sendMessage(
            $chat_id,
            "🔗 <b>Ab download link bhejo</b>\n\nOrder: <code>$order_id</code>\n\nAgar download link nahi hai to <code>-</code> bhejo."
        );
        exit;
    }

    /* DELIVER DOWNLOAD LINK */
    if ($waiting === "deliver_link") {
        $order_id = $temp[$user_id]["deliver_order_id"] ?? "";
        if (!isset($orders[$order_id])) {
            clearWaiting($user_id);
            sendMessage($chat_id, "❌ Order nahi mila.");
            exit;
        }

        $link = trim($text);
        $orders[$order_id]["download_link"] = ($link === "-" ? "" : $link);
        $orders[$order_id]["status"] = "delivered";
        saveJSON("orders.json", $orders);

        deliverApprovedOrder($order_id);
        clearWaiting($user_id);
        exit;
    }

    /* BROADCAST */
    if ($waiting === "broadcast_text") {
        broadcastText($text);
        clearWaiting($user_id);
        sendAdminPanel($chat_id);
        exit;
    }
}

/* ---------------- USER CUSTOM AMOUNT TEXT ---------------- */
if ($text !== "" && $waiting === "") {
    $amount = (int)$text;
    if ($amount >= 1 && $amount <= 5000) {
        createUPIOrder($chat_id, 0, $user_id, $amount);
        exit;
    }
}

/* =========================================================
   PHOTO / DOCUMENT PROOF
   ========================================================= */
if (!$isCallback && isset($update["message"])) {

    if ($waiting === "payment_proof") {
        $order_id = $temp[$user_id]["payment_order_id"] ?? "";

        if (!$order_id || !isset($orders[$order_id])) {
            clearWaiting($user_id);
            sendMessage($chat_id, "❌ Payment order nahi mila. Naya payment request banao.");
            exit;
        }

        $photo_id = "";
        if (isset($update["message"]["photo"]) && is_array($update["message"]["photo"])) {
            $last = end($update["message"]["photo"]);
            $photo_id = $last["file_id"] ?? "";
        }

        if ($photo_id === "") {
            sendMessage($chat_id, "📸 Payment ka screenshot/photo bhejo.");
            exit;
        }

        $orders[$order_id]["proof_file_id"] = $photo_id;
        $orders[$order_id]["proof_caption"] = $update["message"]["caption"] ?? "";
        $orders[$order_id]["status"] = "proof_submitted";
        saveJSON("orders.json", $orders);
        clearWaiting($user_id);

        sendMessage(
            $chat_id,
            "✅ <b>Payment proof receive ho gaya.</b>\n\nOrder: <code>$order_id</code>\nAmount: ₹".$orders[$order_id]["amount"]."\n\nAdmin confirmation ke baad balance add hoga."
        );

        notifyAdminPaymentProof($order_id);
        exit;
    }

    /* ADMIN MEDIA BROADCAST */
    if ((string)$user_id === (string)$adminID && $waiting === "broadcast_text") {
        broadcastMedia($update["message"]);
        clearWaiting($user_id);
        sendAdminPanel($chat_id);
        exit;
    }
}

/* =========================================================
   USER FUNCTIONS
   ========================================================= */

function sendMainMenu($chat_id, $name, $balance, $message_id = 0) {
    $safe = htmlspecialchars($name, ENT_QUOTES, "UTF-8");

    $msg =
        "👑 <b>SELLING BOT</b>\n\n".
        "🧡 Welcome, <b>$safe</b>!\n\n".
        "🔑 Premium Products\n".
        "⚡ Fast Manual Payment Verification\n".
        "🛡️ Secure UPI Payment\n".
        "💬 Admin Support\n\n".
        "💰 <b>Balance: ₹".number_format((float)$balance, 2)."</b>";

    $kb = [
        [["🛒 Shop Now", "shop"]],
        [["📦 My Orders", "orders"], ["👤 Profile", "profile"]],
        [["💰 Add Balance", "addbal"], ["📄 Payment Proof", "proof"]],
        [["📖 How to Use", "howto"], ["💬 Support", "support"]]
    ];

    if ($message_id > 0) editMsg($chat_id, $message_id, $msg, btn($kb));
    else sendMessage($chat_id, $msg, btn($kb));
}

function sendCategories($chat_id, $message_id) {
    $categories = json_decode(file_get_contents("categories.json"), true);
    $kb = [];
    $msg = "🛒 <b>SHOP</b>\n\nSelect category:";

    foreach ($categories as $id => $c) {
        $kb[] = [[$c["name"], "cat_$id"]];
    }

    if (!$kb) {
        $msg .= "\n\n⚠️ Abhi koi category available nahi hai.";
    }

    $kb[] = [["⬅️ Back", "back"]];
    editMsg($chat_id, $message_id, $msg, btn($kb));
}

function sendProducts($chat_id, $message_id, $cid) {
    $products = json_decode(file_get_contents("products.json"), true);
    $categories = json_decode(file_get_contents("categories.json"), true);

    if (!isset($categories[$cid])) {
        editMsg($chat_id, $message_id, "❌ Category nahi mili.", btn([[["⬅️ Back","backcat"]]]));
        return;
    }

    $name = htmlspecialchars($categories[$cid]["name"], ENT_QUOTES, "UTF-8");
    $msg = "🛒 <b>$name</b>\n\nSelect product:";
    $kb = [];

    foreach ($products as $id => $p) {
        if (($p["cat"] ?? "") === $cid) {
            $kb[] = [[$p["name"], "buy_$id"]];
        }
    }

    if (!$kb) $msg .= "\n\n⚠️ Is category me abhi product nahi hai.";
    $kb[] = [["⬅️ Back", "backcat"]];

    editMsg($chat_id, $message_id, $msg, btn($kb));
}

function sendProductPlans($chat_id, $message_id, $pid) {
    $products = json_decode(file_get_contents("products.json"), true);

    if (!isset($products[$pid])) {
        editMsg($chat_id, $message_id, "❌ Product nahi mila.", btn([[["⬅️ Back","backcat"]]]));
        return;
    }

    $p = $products[$pid];
    $plans = $p["plans"] ?? [];

    if (!$plans) {
        editMsg(
            $chat_id,
            $message_id,
            "📦 <b>{$p['name']}</b>\n\n⚠️ Is product me abhi plan nahi hai.",
            btn([[["⬅️ Back","backcat"]]])
        );
        return;
    }

    $msg = "📦 <b>{$p['name']}</b>\n\nChoose plan:";
    $kb = [];

    foreach ($plans as $i => $plan) {
        $days = (int)$plan["days"];
        $price = (int)$plan["price"];
        $stock = count($plan["keys"] ?? []);

        /* IMPORTANT: stock display does NOT block purchase */
        $stockText = $stock > 0 ? "In stock: $stock" : "Admin delivery";
        $msg .= "\n• $days Days — ₹$price ($stockText)";
        $kb[] = [[$days." Days — ₹".$price, "plan_{$pid}_{$i}"]];
    }

    $kb[] = [["⬅️ Back","backcat"]];
    editMsg($chat_id, $message_id, $msg, btn($kb));
}

function buyPlan($chat_id, $message_id, $user_id, $pid, $plan_index) {
    $products = json_decode(file_get_contents("products.json"), true);
    $balances = json_decode(file_get_contents("balances.json"), true);
    $orders   = json_decode(file_get_contents("orders.json"), true);

    if (!isset($products[$pid]["plans"][$plan_index])) {
        editMsg($chat_id, $message_id, "❌ Plan nahi mila.", btn([[["⬅️ Back","backcat"]]]));
        return;
    }

    $p = $products[$pid];
    $plan = $p["plans"][$plan_index];
    $price = (int)$plan["price"];
    $bal = (float)($balances[$user_id] ?? 0);

    if ($bal < $price) {
        editMsg(
            $chat_id,
            $message_id,
            "❌ <b>Insufficient Balance</b>\n\nPrice: ₹$price\nYour Balance: ₹$bal",
            btn([
                [["💰 Add Balance","addbal"]],
                [["⬅️ Back","backcat"]]
            ])
        );
        return;
    }

    /*
     * Purchase is allowed EVEN IF keys are empty.
     * Existing keys are not automatically delivered.
     * Admin receives a purchase notification and supplies key/link.
     */
    $balances[$user_id] = $bal - $price;
    saveJSON("balances.json", $balances);

    $order_id = "KEY" . date("YmdHis") . rand(100,999);

    $orders[$order_id] = [
        "type" => "key_purchase",
        "user" => $user_id,
        "product_id" => $pid,
        "product_name" => $p["name"],
        "days" => (int)$plan["days"],
        "price" => $price,
        "status" => "awaiting_admin",
        "key" => "",
        "download_link" => "",
        "date" => date("d M Y H:i:s")
    ];

    saveJSON("orders.json", $orders);

    editMsg(
        $chat_id,
        $message_id,
        "✅ <b>Order Placed!</b>\n\n".
        "📦 Product: {$p['name']}\n".
        "📅 Plan: {$plan['days']} Days\n".
        "💰 Paid from balance: ₹$price\n".
        "🆔 Order: <code>$order_id</code>\n\n".
        "⏳ Admin key + download link approve karke bhejega.\n\n".
        "💰 Remaining Balance: ₹".$balances[$user_id],
        btn([
            [["📦 My Orders","orders"]],
            [["⬅️ Back","back"]]
        ])
    );

    notifyAdminKeyPurchase($order_id);
}

function sendOrders($chat_id, $message_id, $user_id) {
    $orders = json_decode(file_get_contents("orders.json"), true);
    $mine = [];

    foreach ($orders as $id => $o) {
        if ((string)($o["user"] ?? "") === (string)$user_id) {
            $mine[$id] = $o;
        }
    }

    if (!$mine) {
        editMsg($chat_id, $message_id, "📦 <b>My Orders</b>\n\nNo orders yet.", btn([[["⬅️ Back","back"]]]));
        return;
    }

    $msg = "📦 <b>My Orders</b>\n\n";

    foreach (array_reverse($mine, true) as $id => $o) {
        $msg .=
            "🆔 <code>$id</code>\n".
            "📦 {$o['product_name']}\n".
            "📅 {$o['days']} Days\n".
            "💰 ₹{$o['price']}\n".
            "📌 Status: ".statusText($o["status"])."\n";

        if (!empty($o["key"])) {
            $msg .= "🔑 Key: <code>".htmlspecialchars($o["key"])."</code>\n";
        }

        if (!empty($o["download_link"])) {
            $msg .= "🔗 Download: {$o['download_link']}\n";
        }

        $msg .= "📅 {$o['date']}\n\n";
    }

    editMsg($chat_id, $message_id, $msg, btn([[["⬅️ Back","back"]]]));
}

function sendProfile($chat_id, $message_id, $user_id) {
    $balances = json_decode(file_get_contents("balances.json"), true);
    $users = json_decode(file_get_contents("users.json"), true);
    $orders = json_decode(file_get_contents("orders.json"), true);

    $name = htmlspecialchars($users[$user_id]["name"] ?? "User", ENT_QUOTES, "UTF-8");
    $balance = $balances[$user_id] ?? 0;
    $count = 0;

    foreach ($orders as $o) {
        if ((string)($o["user"] ?? "") === (string)$user_id &&
            in_array($o["status"] ?? "", ["awaiting_admin","approved","delivered"], true)) {
            $count++;
        }
    }

    $msg =
        "👤 <b>YOUR PROFILE</b>\n\n".
        "Name: $name\n".
        "User ID: <code>$user_id</code>\n".
        "💰 Balance: ₹$balance\n".
        "📦 Orders: $count";

    editMsg(
        $chat_id,
        $message_id,
        $msg,
        btn([
            [["🛒 Shop","shop"],["📦 Orders","orders"]],
            [["⬅️ Back","back"]]
        ])
    );
}

function sendProof($chat_id, $message_id) {
    $settings = json_decode(file_get_contents("settings.json"), true);
    $link = trim($settings["proof_link"] ?? "");

    $msg = "📄 <b>Payment Proof</b>\n\n";
    $msg .= $link ? "🔗 <a href=\"".htmlspecialchars($link, ENT_QUOTES, "UTF-8")."\">Open Proof Channel</a>" : "Admin proof channel not set.";

    editMsg($chat_id, $message_id, $msg, btn([[["⬅️ Back","back"]]]));
}

function sendHowTo($chat_id, $message_id) {
    $settings = json_decode(file_get_contents("settings.json"), true);
    $link = trim($settings["howto_link"] ?? "");

    $msg = "📖 <b>How to Use</b>\n\n1. Add Balance\n2. Select exact amount\n3. Scan QR and pay\n4. Send payment screenshot\n5. Admin confirms\n6. Balance gets credited\n7. Buy product";

    if ($link) {
        $msg .= "\n\n🔗 <a href=\"".htmlspecialchars($link, ENT_QUOTES, "UTF-8")."\">Tutorial</a>";
    }

    editMsg($chat_id, $message_id, $msg, btn([[["⬅️ Back","back"]]]));
}

function sendSupport($chat_id, $message_id) {
    $settings = json_decode(file_get_contents("settings.json"), true);
    $u = trim($settings["support_user"] ?? "");

    $msg = $u
        ? "💬 <b>Support</b>\n\n<a href=\"https://t.me/".ltrim($u, "@")."\">Contact Support</a>"
        : "💬 <b>Support</b>\n\nAdmin support username not set.";

    editMsg($chat_id, $message_id, $msg, btn([[["⬅️ Back","back"]]]));
}

/* =========================================================
   MANUAL UPI PAYMENT
   ========================================================= */

function sendAddBalance($chat_id, $message_id, $user_id) {
    $balances = json_decode(file_get_contents("balances.json"), true);
    $settings = json_decode(file_get_contents("settings.json"), true);
    $upi = trim($settings["upi_id"] ?? "");

    if ($upi === "") {
        editMsg(
            $chat_id,
            $message_id,
            "⚠️ <b>UPI payment is not configured yet.</b>\n\nAdmin ko UPI ID set karne bolo.",
            btn([[["⬅️ Back","back"]]])
        );
        return;
    }

    $bal = $balances[$user_id] ?? 0;

    $msg =
        "💰 <b>ADD BALANCE</b>\n\n".
        "Current Balance: ₹$bal\n".
        "Select exact amount:\n\n".
        "⚠️ QR me selected amount EXACT hoga.\n".
        "Payment ke baad screenshot bhejna hoga.";

    $kb = [
        [["₹50","pay_50"],["₹100","pay_100"],["₹200","pay_200"]],
        [["₹500","pay_500"],["₹1000","pay_1000"],["₹2000","pay_2000"]],
        [["✏️ Custom Amount","custom"]],
        [["⬅️ Back","back"]]
    ];

    editMsg($chat_id, $message_id, $msg, btn($kb));
}

function sendKeypad($chat_id, $message_id, $user_id, $amount) {
    $amount = (string)$amount;

    $kb = [
        [["1","key_1"],["2","key_2"],["3","key_3"]],
        [["4","key_4"],["5","key_5"],["6","key_6"]],
        [["7","key_7"],["8","key_8"],["9","key_9"]],
        [["C","key_C"],["0","key_0"],["⌫","key_DEL"]],
        [["✅ Confirm ₹$amount","confirm_$amount"]],
        [["⬅️ Back","backkey"]]
    ];

    editMsg(
        $chat_id,
        $message_id,
        "💰 <b>Enter Amount</b>\n\n₹$amount\nMin ₹1 • Max ₹5000",
        btn($kb)
    );
}

function handleKeypad($chat_id, $message_id, $user_id, $key) {
    $temp = json_decode(file_get_contents("temp.json"), true);
    $amount = (string)($temp[$user_id]["amount"] ?? "0");

    if ($key === "C") {
        $amount = "0";
    } elseif ($key === "DEL") {
        $amount = substr($amount, 0, -1);
        if ($amount === "") $amount = "0";
    } else {
        $amount = ($amount === "0") ? $key : $amount . $key;
    }

    if ((int)$amount > 5000) $amount = "5000";

    saveTemp($user_id, "amount", $amount);
    sendKeypad($chat_id, $message_id, $user_id, $amount);
}

function createUPIOrder($chat_id, $message_id, $user_id, $amount) {
    $settings = json_decode(file_get_contents("settings.json"), true);
    $upi = trim($settings["upi_id"] ?? "");
    $upiName = trim($settings["upi_name"] ?? "Payment");

    if ($amount < 1 || $amount > 5000) {
        editMsg($chat_id, $message_id, "❌ Amount ₹1 se ₹5000 ke beech hona chahiye.", btn([[["⬅️ Back","backkey"]]]));
        return;
    }

    if ($upi === "") {
        editMsg($chat_id, $message_id, "❌ Admin ne UPI ID set nahi ki hai.", btn([[["⬅️ Back","backkey"]]]));
        return;
    }

    $order_id = "PAY" . date("YmdHis") . rand(100,999);

    $orders = json_decode(file_get_contents("orders.json"), true);
    $orders[$order_id] = [
        "type" => "balance_payment",
        "user" => $user_id,
        "amount" => $amount,
        "upi_id" => $upi,
        "status" => "awaiting_payment",
        "created" => time(),
        "date" => date("d M Y H:i:s"),
        "proof_file_id" => ""
    ];
    saveJSON("orders.json", $orders);

    /*
     * Dynamic UPI QR:
     * pa = admin UPI ID
     * pn = receiver name
     * am = EXACT selected amount
     * cu = INR
     */
    $upiUri =
        "upi://pay?" .
        "pa=" . rawurlencode($upi) .
        "&pn=" . rawurlencode($upiName) .
        "&am=" . rawurlencode(number_format($amount, 2, ".", "")) .
        "&cu=INR";

    $qrUrl =
        "https://api.qrserver.com/v1/create-qr-code/?size=700x700&margin=10&data=" .
        rawurlencode($upiUri);

    $msg =
        "💳 <b>PAYMENT REQUEST</b>\n\n".
        "💰 Amount: <b>₹".number_format($amount, 2)."</b>\n".
        "🆔 Order: <code>$order_id</code>\n".
        "💳 UPI ID: <code>".htmlspecialchars($upi)."</code>\n\n".
        "📲 QR scan karo aur <b>EXACT ₹$amount</b> payment karo.\n".
        "✅ Payment ke baad <b>payment screenshot</b> isi chat me bhejo.\n\n".
        "⚠️ Screenshot ke bina balance automatically add nahi hoga.";

    $sent = sendPhoto(
        $chat_id,
        $qrUrl,
        $msg,
        btn([
            [["📸 I Have Paid — Send Screenshot","proofpay_$order_id"]],
            [["❌ Cancel","cancel_pay_$order_id"]]
        ])
    );

    if ($sent === false) {
        sendMessage($chat_id, "❌ QR send nahi ho paya. Please try again.");
        unset($orders[$order_id]);
        saveJSON("orders.json", $orders);
        return;
    }

    if ($message_id > 0) deleteMsg($chat_id, $message_id);

    saveTemp($user_id, "payment_order_id", $order_id);
    saveTemp($user_id, "waiting", "payment_proof");
}

function cancelPaymentOrder($chat_id, $message_id, $order_id, $user_id) {
    $orders = json_decode(file_get_contents("orders.json"), true);

    if (isset($orders[$order_id]) &&
        (string)$orders[$order_id]["user"] === (string)$user_id &&
        in_array($orders[$order_id]["status"], ["awaiting_payment"], true)) {

        $orders[$order_id]["status"] = "cancelled";
        saveJSON("orders.json", $orders);
        clearWaiting($user_id);

        editMsg(
            $chat_id,
            $message_id,
            "❌ <b>Payment Cancelled</b>\n\nAap naya payment order bana sakte ho.",
            btn([[["💰 Add Balance","addbal"],["⬅️ Back","back"]]])
        );
    }
}

/* =========================================================
   ADMIN PAYMENT PROOF
   ========================================================= */

function notifyAdminPaymentProof($order_id) {
    global $adminID;

    $orders = json_decode(file_get_contents("orders.json"), true);
    $users = json_decode(file_get_contents("users.json"), true);

    if (!isset($orders[$order_id])) return;

    $o = $orders[$order_id];
    $u = $users[$o["user"]] ?? [];

    $name = htmlspecialchars($u["name"] ?? "User", ENT_QUOTES, "UTF-8");
    $username = !empty($u["username"]) ? "@".$u["username"] : "No username";

    $caption =
        "💰 <b>NEW PAYMENT PROOF</b>\n\n".
        "👤 $name ($username)\n".
        "🆔 User ID: <code>{$o['user']}</code>\n".
        "💵 Amount: <b>₹{$o['amount']}</b>\n".
        "🧾 Order: <code>$order_id</code>\n".
        "💳 UPI: <code>{$o['upi_id']}</code>\n\n".
        "Payment screenshot verify karo.";

    sendPhoto(
        $adminID,
        $o["proof_file_id"],
        $caption,
        btn([
            [["✅ APPROVE", "approve_$order_id"], ["❌ REJECT", "reject_$order_id"]]
        ])
    );
}

function approvePaymentRequest($chat_id, $message_id, $order_id) {
    $orders = json_decode(file_get_contents("orders.json"), true);
    $balances = json_decode(file_get_contents("balances.json"), true);

    if (!isset($orders[$order_id])) {
        editMsg($chat_id, $message_id, "❌ Payment order nahi mila.", btn([[["⬅️ Back","backadmin"]]]));
        return;
    }

    if ($orders[$order_id]["status"] !== "proof_submitted") {
        editMsg(
            $chat_id,
            $message_id,
            "⚠️ Ye order approve nahi ho sakta.\nCurrent status: ".$orders[$order_id]["status"],
            btn([[["⬅️ Back","backadmin"]]])
        );
        return;
    }

    $user_id = $orders[$order_id]["user"];
    $amount = (int)$orders[$order_id]["amount"];

    $balances[$user_id] = ($balances[$user_id] ?? 0) + $amount;
    saveJSON("balances.json", $balances);

    $orders[$order_id]["status"] = "paid";
    $orders[$order_id]["approved_by"] = $chat_id;
    $orders[$order_id]["approved_at"] = date("d M Y H:i:s");
    saveJSON("orders.json", $orders);

    sendMessage(
        $user_id,
        "✅ <b>Payment Approved!</b>\n\n".
        "💰 ₹$amount balance me add ho gaya.\n".
        "🧾 Order: <code>$order_id</code>\n".
        "💵 New Balance: ₹".$balances[$user_id]
    );

    editMsg(
        $chat_id,
        $message_id,
        "✅ <b>Payment Approved</b>\n\n".
        "Order: <code>$order_id</code>\n".
        "Amount: ₹$amount\n".
        "Balance credited to user.",
        btn([[["⬅️ Admin Panel","backadmin"]]])
    );
}

function rejectPaymentRequest($chat_id, $order_id, $reason) {
    $orders = json_decode(file_get_contents("orders.json"), true);

    if (!isset($orders[$order_id])) {
        clearWaiting($chat_id);
        sendMessage($chat_id, "❌ Order nahi mila.");
        return;
    }

    $orders[$order_id]["status"] = "rejected";
    $orders[$order_id]["reject_reason"] = trim($reason);
    $orders[$order_id]["rejected_at"] = date("d M Y H:i:s");
    saveJSON("orders.json", $orders);

    $uid = $orders[$order_id]["user"];

    sendMessage(
        $uid,
        "❌ <b>Payment Rejected</b>\n\n".
        "Order: <code>$order_id</code>\n".
        "Reason: ".htmlspecialchars($reason, ENT_QUOTES, "UTF-8")."\n\n".
        "Agar payment genuinely ki hai to support se contact karo."
    );

    clearWaiting($chat_id);
    sendMessage(
        $chat_id,
        "❌ Payment rejected.\n\nOrder: <code>$order_id</code>\nReason: ".htmlspecialchars($reason, ENT_QUOTES, "UTF-8"),
        btn([[["⬅️ Admin Panel","backadmin"]]])
    );
}

/* =========================================================
   KEY PURCHASE ADMIN FLOW
   ========================================================= */

function notifyAdminKeyPurchase($order_id) {
    global $adminID;

    $orders = json_decode(file_get_contents("orders.json"), true);
    $users = json_decode(file_get_contents("users.json"), true);

    if (!isset($orders[$order_id])) return;

    $o = $orders[$order_id];
    $u = $users[$o["user"]] ?? [];

    $name = htmlspecialchars($u["name"] ?? "User", ENT_QUOTES, "UTF-8");
    $username = !empty($u["username"]) ? "@".$u["username"] : "No username";

    $msg =
        "🛒 <b>NEW KEY PURCHASE</b>\n\n".
        "👤 $name ($username)\n".
        "🆔 User ID: <code>{$o['user']}</code>\n".
        "📦 Product: <b>{$o['product_name']}</b>\n".
        "📅 Plan: {$o['days']} Days\n".
        "💰 Paid: ₹{$o['price']}\n".
        "🧾 Order: <code>$order_id</code>\n\n".
        "⚠️ Stock ho ya out-of-stock, purchase allowed hai.\n".
        "Admin ko key + download link dena hai.";

    sendMessage(
        $adminID,
        $msg,
        btn([
            [["🔑 APPROVE / GIVE KEY","deliver_$order_id"]],
            [["❌ REJECT","reject_key_$order_id"]]
        ])
    );
}

function deliverApprovedOrder($order_id) {
    global $adminID;

    $orders = json_decode(file_get_contents("orders.json"), true);
    if (!isset($orders[$order_id])) return;

    $o = $orders[$order_id];

    $msg =
        "🎉 <b>ORDER COMPLETED</b>\n\n".
        "📦 {$o['product_name']}\n".
        "📅 {$o['days']} Days\n".
        "🧾 Order: <code>$order_id</code>\n\n".
        "🔑 <b>Key:</b>\n<code>".htmlspecialchars($o["key"], ENT_QUOTES, "UTF-8")."</code>";

    if (!empty($o["download_link"])) {
        $msg .= "\n\n🔗 <b>Download:</b> ".$o["download_link"];
    }

    sendMessage(
        $o["user"],
        $msg,
        btn([
            [["📦 My Orders","orders"]],
            [["⬅️ Main Menu","back"]]
        ])
    );

    sendMessage(
        $adminID,
        "✅ <b>Order Delivered</b>\n\nOrder: <code>$order_id</code>",
        btn([[["⬅️ Admin Panel","backadmin"]]])
    );
}

/* =========================================================
   ADMIN PANEL
   ========================================================= */

function sendAdminPanel($chat_id, $message_id = 0) {
    $settings = json_decode(file_get_contents("settings.json"), true);
    $users = json_decode(file_get_contents("users.json"), true);
    $orders = json_decode(file_get_contents("orders.json"), true);

    $pendingPayments = 0;
    $pendingKeys = 0;

    foreach ($orders as $o) {
        if (($o["status"] ?? "") === "proof_submitted") $pendingPayments++;
        if (($o["status"] ?? "") === "awaiting_admin") $pendingKeys++;
    }

    $upi = $settings["upi_id"] ?: "Not set";

    $msg =
        "👑 <b>ADMIN PANEL</b>\n\n".
        "👥 Users: ".count($users)."\n".
        "💳 Pending Payments: $pendingPayments\n".
        "🛒 Pending Key Orders: $pendingKeys\n\n".
        "💳 UPI ID: <code>".htmlspecialchars($upi, ENT_QUOTES, "UTF-8")."</code>\n".
        "👤 UPI Name: ".htmlspecialchars($settings["upi_name"] ?? "Payment", ENT_QUOTES, "UTF-8");

    $kb = [
        [["📁 Add Category","addcat"],["📦 Add Product","addprod"]],
        [["➕ Add Plan","addplan"],["🔑 Add Keys","addplankeys"]],
        [["✏️ Edit Plan","editplan"],["🗑️ Delete Plan","delplan"]],
        [["✏️ Edit Product","editprod"],["🗑️ Delete Product","delprod"]],
        [["💳 Set UPI ID","setupi"],["👤 UPI Name","setupiname"]],
        [["👥 User List","userlist"],["💰 Add User Balance","adduserbal"]],
        [["📢 Broadcast","broadcast"]],
        [["📄 Proof Link","setproof"],["📖 HowTo Link","sethowto"]],
        [["💬 Support","setsupport"]],
        [["⬅️ Back to Menu","back"]]
    ];

    if ($message_id > 0) editMsg($chat_id, $message_id, $msg, btn($kb));
    else sendMessage($chat_id, $msg, btn($kb));
}

/* =========================================================
   ADMIN PRODUCT HELPERS
   ========================================================= */

function sendSelectCatForProduct($chat_id, $message_id) {
    $categories = json_decode(file_get_contents("categories.json"), true);
    $kb = [];

    foreach ($categories as $id => $c) {
        $kb[] = [[$c["name"], "addtoprod_$id"]];
    }

    $msg = "📁 <b>Category select karo</b>";

    if (!$kb) $msg .= "\n\n⚠️ Pehle category add karo.";

    $kb[] = [["⬅️ Back","backadmin"]];
    editMsg($chat_id, $message_id, $msg, btn($kb));
}

function sendSelectProductForAddPlan($chat_id, $message_id) {
    $products = json_decode(file_get_contents("products.json"), true);
    $kb = [];

    foreach ($products as $id => $p) {
        $kb[] = [[$p["name"], "addplanprod_$id"]];
    }

    $kb[] = [["⬅️ Back","backadmin"]];
    editMsg($chat_id, $message_id, "➕ <b>Product select karo</b>", btn($kb));
}

function sendSelectProductForAddPlanKeys($chat_id, $message_id) {
    $products = json_decode(file_get_contents("products.json"), true);
    $kb = [];

    foreach ($products as $id => $p) {
        $kb[] = [[$p["name"], "addkeysprod_$id"]];
    }

    $kb[] = [["⬅️ Back","backadmin"]];
    editMsg($chat_id, $message_id, "🔑 <b>Product select karo</b>", btn($kb));
}

function sendPlansForAddKeys($chat_id, $message_id, $pid) {
    $products = json_decode(file_get_contents("products.json"), true);
    if (!isset($products[$pid])) {
        editMsg($chat_id, $message_id, "❌ Product nahi mila.", btn([[["⬅️ Back","backadmin"]]]));
        return;
    }

    $kb = [];
    foreach ($products[$pid]["plans"] ?? [] as $i => $p) {
        $kb[] = [[$p["days"]." Days — ₹".$p["price"], "addkeysplan_{$pid}_{$i}"]];
    }

    $kb[] = [["⬅️ Back","backadmin"]];
    editMsg($chat_id, $message_id, "🔑 <b>Plan select karo</b>", btn($kb));
}

function sendSelectProductForEditPlan($chat_id, $message_id) {
    $products = json_decode(file_get_contents("products.json"), true);
    $kb = [];

    foreach ($products as $id => $p) {
        $kb[] = [[$p["name"], "editplanprod_$id"]];
    }

    $kb[] = [["⬅️ Back","backadmin"]];
    editMsg($chat_id, $message_id, "✏️ <b>Product select karo</b>", btn($kb));
}

function sendPlansForEdit($chat_id, $message_id, $pid) {
    $products = json_decode(file_get_contents("products.json"), true);
    if (!isset($products[$pid])) {
        editMsg($chat_id, $message_id, "❌ Product nahi mila.", btn([[["⬅️ Back","backadmin"]]]));
        return;
    }

    $kb = [];
    foreach ($products[$pid]["plans"] ?? [] as $i => $p) {
        $kb[] = [[$p["days"]." Days — ₹".$p["price"], "editplan_{$pid}_{$i}"]];
    }

    $kb[] = [["⬅️ Back","backadmin"]];
    editMsg($chat_id, $message_id, "✏️ <b>Plan select karo</b>", btn($kb));
}

function sendSelectProductForDeletePlan($chat_id, $message_id) {
    $products = json_decode(file_get_contents("products.json"), true);
    $kb = [];

    foreach ($products as $id => $p) {
        $kb[] = [[$p["name"], "delplanprod_$id"]];
    }

    $kb[] = [["⬅️ Back","backadmin"]];
    editMsg($chat_id, $message_id, "🗑️ <b>Product select karo</b>", btn($kb));
}

function sendPlansForDelete($chat_id, $message_id, $pid) {
    $products = json_decode(file_get_contents("products.json"), true);
    if (!isset($products[$pid])) {
        editMsg($chat_id, $message_id, "❌ Product nahi mila.", btn([[["⬅️ Back","backadmin"]]]));
        return;
    }

    $kb = [];
    foreach ($products[$pid]["plans"] ?? [] as $i => $p) {
        $kb[] = [["❌ ".$p["days"]." Days — ₹".$p["price"], "delplan_{$pid}_{$i}"]];
    }

    $kb[] = [["⬅️ Back","backadmin"]];
    editMsg($chat_id, $message_id, "🗑️ <b>Plan select karo</b>", btn($kb));
}

function sendSelectProductForEdit($chat_id, $message_id) {
    $products = json_decode(file_get_contents("products.json"), true);
    $kb = [];

    foreach ($products as $id => $p) {
        $kb[] = [[$p["name"], "editprod_$id"]];
    }

    $kb[] = [["⬅️ Back","backadmin"]];
    editMsg($chat_id, $message_id, "✏️ <b>Product select karo</b>", btn($kb));
}

function sendSelectProductForDelete($chat_id, $message_id) {
    $products = json_decode(file_get_contents("products.json"), true);
    $kb = [];

    foreach ($products as $id => $p) {
        $kb[] = [["❌ ".$p["name"], "delprod_$id"]];
    }

    $kb[] = [["⬅️ Back","backadmin"]];
    editMsg($chat_id, $message_id, "🗑️ <b>Product select karo</b>", btn($kb));
}

function sendUserList($chat_id, $message_id) {
    $users = json_decode(file_get_contents("users.json"), true);
    $msg = "👥 <b>Users: ".count($users)."</b>\n\n";
    $i = 1;

    foreach ($users as $id => $u) {
        $msg .=
            "$i. ".htmlspecialchars($u["name"] ?? "User", ENT_QUOTES, "UTF-8").
            (!empty($u["username"]) ? " (@{$u['username']})" : "").
            "\n🆔 <code>$id</code>\n📅 {$u['join']}\n\n";
        $i++;
        if ($i > 50) break;
    }

    editMsg($chat_id, $message_id, $msg, btn([[["⬅️ Back","backadmin"]]]));
}

/* =========================================================
   BROADCAST
   ========================================================= */

function broadcastText($text) {
    $users = json_decode(file_get_contents("users.json"), true);
    $sent = 0;

    foreach ($users as $id => $u) {
        if (sendMessage($id, "📢 <b>Announcement</b>\n\n".$text) !== false) $sent++;
        usleep(50000);
    }

    global $adminID;
    sendMessage($adminID, "✅ Broadcast done.\nSent: $sent / ".count($users));
}

function broadcastMedia($message) {
    $users = json_decode(file_get_contents("users.json"), true);
    $caption = $message["caption"] ?? "";
    $sent = 0;

    foreach ($users as $id => $u) {
        $ok = false;

        if (isset($message["photo"])) {
            $photo = end($message["photo"]);
            $ok = sendPhoto($id, $photo["file_id"], "📢 <b>Announcement</b>\n\n".$caption) !== false;
        } elseif (isset($message["video"])) {
            $ok = sendVideo($id, $message["video"]["file_id"], "📢 <b>Announcement</b>\n\n".$caption) !== false;
        } elseif (isset($message["document"])) {
            $ok = sendDocument($id, $message["document"]["file_id"], "📢 <b>Announcement</b>\n\n".$caption) !== false;
        } elseif (isset($message["voice"])) {
            $ok = sendVoice($id, $message["voice"]["file_id"], "📢 <b>Announcement</b>\n\n".$caption) !== false;
        }

        if ($ok) $sent++;
        usleep(50000);
    }

    global $adminID;
    sendMessage($adminID, "✅ Broadcast done.\nSent: $sent / ".count($users));
}

/* =========================================================
   HELPERS
   ========================================================= */

function saveJSON($file, $data) {
    file_put_contents(
        $file,
        json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        LOCK_EX
    );
}

function saveTemp($user_id, $key, $value) {
    $temp = json_decode(file_get_contents("temp.json"), true);
    if (!is_array($temp)) $temp = [];
    if (!isset($temp[$user_id])) $temp[$user_id] = [];
    $temp[$user_id][$key] = $value;
    saveJSON("temp.json", $temp);
}

function clearWaiting($user_id) {
    $temp = json_decode(file_get_contents("temp.json"), true);
    if (!is_array($temp)) $temp = [];

    if (!isset($temp[$user_id])) $temp[$user_id] = [];

    $keep = [];
    /* amount is only kept during keypad; all other workflow values are cleared */
    foreach (["amount"] as $k) {
        if (isset($temp[$user_id][$k])) $keep[$k] = $temp[$user_id][$k];
    }

    $temp[$user_id] = $keep;
    saveJSON("temp.json", $temp);
}

function cleanText($text) {
    return trim(strip_tags($text));
}

function statusText($status) {
    $map = [
        "awaiting_payment" => "⏳ Payment pending",
        "proof_submitted"  => "🔍 Proof under review",
        "paid"             => "✅ Balance credited",
        "cancelled"        => "❌ Cancelled",
        "rejected"         => "❌ Rejected",
        "awaiting_admin"   => "⏳ Admin key pending",
        "approved"         => "✅ Approved",
        "delivered"        => "🎉 Delivered"
    ];

    return $map[$status] ?? $status;
}

function btn($rows) {
    $keyboard = [];

    foreach ($rows as $row) {
        $line = [];

        foreach ($row as $button) {
            $line[] = [
                "text" => $button[0],
                "callback_data" => $button[1]
            ];
        }

        $keyboard[] = $line;
    }

    return json_encode(["inline_keyboard" => $keyboard], JSON_UNESCAPED_UNICODE);
}

function answerCallback($id) {
    global $website;
    if ($id !== "") {
        @file_get_contents($website."/answerCallbackQuery?".http_build_query([
            "callback_query_id" => $id
        ]));
    }
}

function sendMessage($chat_id, $text, $reply_markup = null) {
    global $website;

    $data = [
        "chat_id" => $chat_id,
        "text" => $text,
        "parse_mode" => "HTML",
        "disable_web_page_preview" => true
    ];

    if ($reply_markup !== null) $data["reply_markup"] = $reply_markup;

    return @file_get_contents($website."/sendMessage?".http_build_query($data));
}

function editMsg($chat_id, $message_id, $text, $reply_markup = null) {
    global $website;

    if ($message_id <= 0) {
        return sendMessage($chat_id, $text, $reply_markup);
    }

    $data = [
        "chat_id" => $chat_id,
        "message_id" => $message_id,
        "text" => $text,
        "parse_mode" => "HTML",
        "disable_web_page_preview" => true
    ];

    if ($reply_markup !== null) $data["reply_markup"] = $reply_markup;

    return @file_get_contents($website."/editMessageText?".http_build_query($data));
}

function sendPhoto($chat_id, $photo, $caption, $reply_markup = null) {
    global $website;

    $data = [
        "chat_id" => $chat_id,
        "photo" => $photo,
        "caption" => $caption,
        "parse_mode" => "HTML"
    ];

    if ($reply_markup !== null) $data["reply_markup"] = $reply_markup;

    return @file_get_contents($website."/sendPhoto?".http_build_query($data));
}

function sendVideo($chat_id, $video, $caption, $reply_markup = null) {
    global $website;

    $data = [
        "chat_id" => $chat_id,
        "video" => $video,
        "caption" => $caption,
        "parse_mode" => "HTML"
    ];

    if ($reply_markup !== null) $data["reply_markup"] = $reply_markup;

    return @file_get_contents($website."/sendVideo?".http_build_query($data));
}

function sendDocument($chat_id, $document, $caption, $reply_markup = null) {
    global $website;

    $data = [
        "chat_id" => $chat_id,
        "document" => $document,
        "caption" => $caption,
        "parse_mode" => "HTML"
    ];

    if ($reply_markup !== null) $data["reply_markup"] = $reply_markup;

    return @file_get_contents($website."/sendDocument?".http_build_query($data));
}

function sendVoice($chat_id, $voice, $caption, $reply_markup = null) {
    global $website;

    $data = [
        "chat_id" => $chat_id,
        "voice" => $voice,
        "caption" => $caption,
        "parse_mode" => "HTML"
    ];

    if ($reply_markup !== null) $data["reply_markup"] = $reply_markup;

    return @file_get_contents($website."/sendVoice?".http_build_query($data));
}

function deleteMsg($chat_id, $message_id) {
    global $website;

    @file_get_contents($website."/deleteMessage?".http_build_query([
        "chat_id" => $chat_id,
        "message_id" => $message_id
    ]));
}

?>
