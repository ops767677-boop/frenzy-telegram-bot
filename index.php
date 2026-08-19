<?php
/**
 * =====================================================================
 * ✨ FRENZY STORE - Web Landing Dashboard (Animated Emojis UI)
 * =====================================================================
 */

declare(strict_types=1);

$botUsername = 'frenzykeyshopbot';
$botLink     = 'https://t.me/' . $botUsername;

$products = [
    [
        'name'  => 'Premium Digital Product',
        'price' => '₹99',
        'icon'  => 'fa-crown',
        'tag'   => '🔥 POPULAR',
        'desc'  => 'Our best-selling digital product, delivered instantly after verification.',
    ],
    [
        'name'  => 'VIP Digital Product',
        'price' => '₹199',
        'icon'  => 'fa-gem',
        'tag'   => '✨ VIP',
        'desc'  => 'A premium tier product with extra perks for VIP members.',
    ],
    [
        'name'  => 'Ultimate Package',
        'price' => '₹499',
        'icon'  => 'fa-bolt',
        'tag'   => '🚀 BEST VALUE',
        'desc'  => 'Everything bundled together for maximum value.',
    ],
];

function e(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>⚡ Frenzy Store — Digital Marketplace</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
  :root{
    --bg-0:#05070B;
    --tg-blue:#229ED9;
    --accent:#5865F2;
    --accent-2:#7C5CFF;
    --white:#FFFFFF;
    --muted:#9AA4B2;
    --card:rgba(255,255,255,0.04);
    --card-border:rgba(255,255,255,0.08);
    --radius:18px;
  }

  *{margin:0;padding:0;box-sizing:border-box;}
  body{
    font-family:'Inter',sans-serif;
    background:var(--bg-0);
    color:var(--white);
    overflow-x:hidden;
    line-height:1.5;
  }
  a{text-decoration:none;color:inherit;}

  .bg-glow{
    position:fixed;
    inset:0;
    z-index:-1;
    background:
      radial-gradient(600px circle at 15% 10%, rgba(124,92,255,0.22), transparent 60%),
      radial-gradient(700px circle at 85% 25%, rgba(34,158,217,0.18), transparent 60%),
      var(--bg-0);
  }

  /* Animated Floating Emojis Background FX */
  .emoji-float {
    position: fixed;
    font-size: 24px;
    user-select: none;
    pointer-events: none;
    animation: floatAnim 10s infinite ease-in-out;
    opacity: 0.25;
  }
  @keyframes floatAnim {
    0%, 100% { transform: translateY(0px) rotate(0deg); }
    50% { transform: translateY(-25px) rotate(15deg); }
  }

  /* NAVBAR */
  .navbar{
    position:sticky;
    top:0;
    z-index:100;
    backdrop-filter:blur(16px);
    background:rgba(5,7,11,0.75);
    border-bottom:1px solid var(--card-border);
  }
  .nav-inner{
    display:flex;
    align-items:center;
    justify-content:space-between;
    padding:16px 24px;
    max-width:1200px;
    margin:0 auto;
  }
  .brand{
    display:flex;
    align-items:center;
    gap:10px;
    font-weight:800;
    font-size:20px;
  }
  .brand .logo-badge{
    width:40px;height:40px;
    display:flex;align-items:center;justify-content:center;
    border-radius:12px;
    background:linear-gradient(135deg,var(--tg-blue),var(--accent-2));
    box-shadow:0 0 20px rgba(124,92,255,0.5);
  }

  .btn{
    display:inline-flex;
    align-items:center;
    gap:8px;
    padding:12px 22px;
    border-radius:999px;
    font-weight:600;
    font-size:14.5px;
    transition:all .2s ease;
  }
  .btn-ghost{
    background:rgba(255,255,255,0.05);
    border:1px solid var(--card-border);
  }
  .btn-ghost:hover{
    background:rgba(255,255,255,0.1);
    transform:translateY(-2px);
  }
  .btn-primary{
    background:linear-gradient(135deg,var(--tg-blue),var(--accent),var(--accent-2));
    box-shadow:0 8px 24px rgba(88,101,242,0.4);
  }
  .btn-primary:hover{
    transform:translateY(-3px);
    box-shadow:0 12px 32px rgba(124,92,255,0.6);
  }

  /* HERO */
  .hero{
    padding:90px 24px 60px;
    text-align:center;
  }
  .status-badge{
    display:inline-flex;
    align-items:center;
    gap:8px;
    padding:8px 18px;
    border-radius:999px;
    background:rgba(34,158,217,0.12);
    border:1px solid rgba(34,158,217,0.4);
    font-size:13.5px;
    font-weight:600;
    color:#7fe3a3;
    margin-bottom:24px;
  }
  .status-dot{
    width:8px;height:8px;border-radius:50%;
    background:#22c55e;
    box-shadow:0 0 10px #22c55e;
    animation:pulse 1.8s infinite;
  }
  @keyframes pulse{
    0%,100%{opacity:1;}
    50%{opacity:.3;}
  }

  .hero h1{
    font-size:clamp(2.4rem, 6vw, 4.2rem);
    font-weight:800;
    line-height:1.1;
    background:linear-gradient(135deg,#fff 30%,#b9c4ff 60%,var(--tg-blue) 100%);
    -webkit-background-clip:text;
    -webkit-text-fill-color:transparent;
    margin-bottom:20px;
  }
  .hero p.subtitle{
    color:var(--muted);
    font-size:clamp(1rem, 2.4vw, 1.25rem);
    max-width:620px;
    margin:0 auto 36px;
  }

  /* PRODUCTS */
  .section{padding:60px 24px;}
  .section-head{
    text-align:center;
    max-width:640px;
    margin:0 auto 40px;
  }
  .section-head h2{font-size:2.2rem;font-weight:800;margin-bottom:10px;}

  .grid{
    display:grid;
    grid-template-columns:repeat(auto-fit,minmax(280px,1fr));
    gap:24px;
    max-width:1100px;
    margin:0 auto;
  }

  .card{
    background:var(--card);
    border:1px solid var(--card-border);
    border-radius:var(--radius);
    padding:28px;
    position:relative;
    backdrop-filter:blur(12px);
    transition:all .25s ease;
  }
  .card:hover{
    transform:translateY(-6px);
    border-color:rgba(124,92,255,0.5);
    box-shadow:0 16px 40px rgba(0,0,0,0.5);
  }

  .product-tag{
    position:absolute;
    top:20px;right:20px;
    font-size:11px;
    font-weight:700;
    padding:5px 12px;
    border-radius:999px;
    background:rgba(124,92,255,0.2);
    border:1px solid rgba(124,92,255,0.4);
    color:#dcd4ff;
  }

  .product-icon{
    width:56px;height:56px;
    border-radius:16px;
    display:flex;align-items:center;justify-content:center;
    font-size:24px;
    background:linear-gradient(135deg,var(--tg-blue),var(--accent-2));
    margin-bottom:20px;
  }

  .product-name{font-size:20px;font-weight:700;margin-bottom:8px;}
  .product-desc{color:var(--muted);font-size:14px;margin-bottom:20px;min-height:42px;}
  .product-price{font-size:26px;font-weight:800;margin-bottom:16px;}
  .buy-btn{width:100%;justify-content:center;}

  footer{
    padding:30px 24px;
    text-align:center;
    color:var(--muted);
    font-size:14px;
    border-top:1px solid var(--card-border);
  }
</style>
</head>
<body>

<div class="bg-glow"></div>

<!-- Animated Background Emojis -->
<div class="emoji-float" style="top: 15%; left: 8%;">⚡</div>
<div class="emoji-float" style="top: 25%; right: 10%; animation-delay: -2s;">🔥</div>
<div class="emoji-float" style="top: 65%; left: 5%; animation-delay: -4s;">💎</div>
<div class="emoji-float" style="top: 75%; right: 8%; animation-delay: -6s;">👑</div>

<nav class="navbar">
  <div class="nav-inner">
    <div class="brand">
      <div class="logo-badge"><i class="fa-brands fa-telegram"></i></div>
      <span>⚡ Frenzy Store</span>
    </div>
    <div style="display:flex;gap:10px;">
      <a href="<?= e($botLink) ?>" target="_blank" class="btn btn-primary"><i class="fa-brands fa-telegram"></i> Open Bot 🚀</a>
    </div>
  </div>
</nav>

<section class="hero">
  <div class="status-badge"><span class="status-dot"></span> Telegram Store • Active & Online ✨</div>
  <h1>Welcome to Frenzy Store ⚡</h1>
  <p class="subtitle">Premium Digital Products • Automated Instant Delivery • Live Telegram Support 🎧</p>
  <div style="display:flex;gap:16px;justify-content:center;flex-wrap:wrap;">
    <a href="<?= e($botLink) ?>" target="_blank" class="btn btn-primary" style="padding:15px 32px;font-size:16px;">
      <i class="fa-brands fa-telegram"></i> Start Telegram Bot 🚀
    </a>
  </div>
</section>

<section class="section">
  <div class="section-head">
    <h2>🔥 Premium Catalog 🔥</h2>
    <p>Choose products directly and purchase safely on Telegram!</p>
  </div>
  <div class="grid">
    <?php foreach ($products as $p): ?>
      <div class="card">
        <div class="product-tag"><?= e($p['tag']) ?></div>
        <div class="product-icon"><i class="fa-solid <?= e($p['icon']) ?>"></i></div>
        <div class="product-name"><?= e($p['name']) ?></div>
        <div class="product-desc"><?= e($p['desc']) ?></div>
        <div class="product-price"><?= e($p['price']) ?></div>
        <a href="<?= e($botLink) ?>" target="_blank" class="btn btn-primary buy-btn">
          <i class="fa-brands fa-telegram"></i> Buy Now 🚀
        </a>
      </div>
    <?php endforeach; ?>
  </div>
</section>

<footer>
  ⚡ <b>Frenzy Store</b> © 2026 — Premium Telegram Bot Integration
</footer>

</body>
</html>
