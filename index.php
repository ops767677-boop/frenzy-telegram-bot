<?php
/**
 * =====================================================================
 * FRENZY STORE - Landing / Dashboard Page
 * =====================================================================
 * Pure front-end presentation. Contains NO bot token, NO admin ID, and
 * NO database credentials. Safe to serve directly to the browser.
 * =====================================================================
 */

declare(strict_types=1);

// Telegram bot username (no @, no URL) — configure this for your bot.
$botUsername = 'frenzykeyshopbot';
$botLink     = 'https://t.me/' . $botUsername;

// Product catalog shown on the landing page. Edit freely — this is
// presentation only and does not affect the bot's own product list
// in bot.php.
$products = [
    [
        'name'  => 'Premium Digital Product',
        'price' => '₹99',
        'icon'  => 'fa-crown',
        'tag'   => 'POPULAR',
        'desc'  => 'Our best-selling digital product, delivered instantly after verification.',
    ],
    [
        'name'  => 'VIP Digital Product',
        'price' => '₹199',
        'icon'  => 'fa-gem',
        'tag'   => 'VIP',
        'desc'  => 'A premium tier product with extra perks for VIP members.',
    ],
    [
        'name'  => 'Ultimate Package',
        'price' => '₹499',
        'icon'  => 'fa-bolt',
        'tag'   => 'BEST VALUE',
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
<title>Frenzy Store — Premium Telegram Digital Store</title>
<meta name="description" content="Frenzy Store — Premium Digital Products, Fast Delivery, Telegram Support.">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
  :root{
    --bg-0:#05070B;
    --bg-1:#0B1118;
    --tg-blue:#229ED9;
    --accent:#5865F2;
    --accent-2:#7C5CFF;
    --white:#FFFFFF;
    --muted:#9AA4B2;
    --muted-2:#6b7280;
    --card:rgba(255,255,255,0.04);
    --card-border:rgba(255,255,255,0.08);
    --radius:18px;
  }

  *{margin:0;padding:0;box-sizing:border-box;}

  html{scroll-behavior:smooth;}

  body{
    font-family:'Inter',-apple-system,BlinkMacSystemFont,sans-serif;
    background:var(--bg-0);
    color:var(--white);
    overflow-x:hidden;
    min-height:100vh;
    line-height:1.5;
  }

  a{text-decoration:none;color:inherit;}

  .bg-glow{
    position:fixed;
    inset:0;
    z-index:-1;
    background:
      radial-gradient(600px circle at 15% 10%, rgba(124,92,255,0.18), transparent 60%),
      radial-gradient(700px circle at 85% 25%, rgba(34,158,217,0.15), transparent 60%),
      radial-gradient(600px circle at 50% 90%, rgba(88,101,242,0.12), transparent 60%),
      var(--bg-0);
  }

  .container{
    max-width:1200px;
    margin:0 auto;
    padding:0 24px;
  }

  /* ---------- NAVBAR ---------- */
  .navbar{
    position:sticky;
    top:0;
    z-index:100;
    backdrop-filter:blur(16px);
    -webkit-backdrop-filter:blur(16px);
    background:rgba(5,7,11,0.72);
    border-bottom:1px solid var(--card-border);
  }

  .nav-inner{
    display:flex;
    align-items:center;
    justify-content:space-between;
    padding:14px 24px;
    max-width:1200px;
    margin:0 auto;
  }

  .brand{
    display:flex;
    align-items:center;
    gap:10px;
    font-weight:800;
    font-size:19px;
    letter-spacing:-0.02em;
  }

  .brand .logo-badge{
    width:38px;height:38px;
    display:flex;align-items:center;justify-content:center;
    border-radius:12px;
    background:linear-gradient(135deg,var(--tg-blue),var(--accent-2));
    box-shadow:0 0 20px rgba(124,92,255,0.45);
    font-size:18px;
  }

  .nav-links{
    display:flex;
    align-items:center;
    gap:10px;
  }

  .btn{
    display:inline-flex;
    align-items:center;
    justify-content:center;
    gap:8px;
    padding:11px 20px;
    border-radius:999px;
    font-weight:600;
    font-size:14.5px;
    border:1px solid transparent;
    cursor:pointer;
    transition:transform .18s ease, box-shadow .18s ease, background .18s ease, border-color .18s ease;
    white-space:nowrap;
  }

  .btn:active{transform:scale(0.97);}

  .btn-ghost{
    background:rgba(255,255,255,0.04);
    border-color:var(--card-border);
    color:var(--white);
  }
  .btn-ghost:hover{
    background:rgba(255,255,255,0.09);
    transform:translateY(-2px);
  }

  .btn-primary{
    background:linear-gradient(135deg,var(--tg-blue),var(--accent),var(--accent-2));
    color:#fff;
    box-shadow:0 8px 24px rgba(88,101,242,0.35);
  }
  .btn-primary:hover{
    transform:translateY(-3px);
    box-shadow:0 12px 32px rgba(124,92,255,0.5);
  }

  .nav-cta{display:none;}

  /* ---------- HERO ---------- */
  .hero{
    padding:96px 24px 72px;
    text-align:center;
  }

  .status-badge{
    display:inline-flex;
    align-items:center;
    gap:8px;
    padding:8px 16px;
    border-radius:999px;
    background:rgba(34,158,217,0.1);
    border:1px solid rgba(34,158,217,0.35);
    font-size:13px;
    font-weight:600;
    color:#7fe3a3;
    margin-bottom:28px;
  }

  .status-dot{
    width:8px;height:8px;border-radius:50%;
    background:#22c55e;
    box-shadow:0 0 10px #22c55e;
    animation:pulse 1.8s infinite;
  }

  @keyframes pulse{
    0%,100%{opacity:1;}
    50%{opacity:.4;}
  }

  .hero h1{
    font-size:clamp(2.2rem, 6vw, 4.2rem);
    font-weight:800;
    letter-spacing:-0.03em;
    line-height:1.08;
    background:linear-gradient(135deg,#fff 30%,#b9c4ff 60%,var(--tg-blue) 100%);
    -webkit-background-clip:text;
    background-clip:text;
    color:transparent;
    margin-bottom:20px;
  }

  .hero p.subtitle{
    color:var(--muted);
    font-size:clamp(1rem, 2.4vw, 1.25rem);
    max-width:620px;
    margin:0 auto 36px;
    font-weight:500;
  }

  .hero-actions{
    display:flex;
    gap:16px;
    justify-content:center;
    flex-wrap:wrap;
  }

  .hero-actions .btn{padding:15px 28px;font-size:16px;}

  /* ---------- SECTION HEADINGS ---------- */
  .section{padding:64px 24px;}
  .section-head{
    text-align:center;
    max-width:640px;
    margin:0 auto 44px;
  }
  .section-head h2{
    font-size:clamp(1.6rem,4vw,2.4rem);
    font-weight:800;
    letter-spacing:-0.02em;
    margin-bottom:12px;
  }
  .section-head p{color:var(--muted);font-size:15.5px;}

  /* ---------- PRODUCT GRID ---------- */
  .grid{
    display:grid;
    grid-template-columns:repeat(auto-fit,minmax(260px,1fr));
    gap:22px;
    max-width:1100px;
    margin:0 auto;
  }

  .card{
    position:relative;
    background:var(--card);
    border:1px solid var(--card-border);
    border-radius:var(--radius);
    padding:28px 24px;
    backdrop-filter:blur(10px);
    -webkit-backdrop-filter:blur(10px);
    transition:transform .25s ease, border-color .25s ease, box-shadow .25s ease;
    overflow:hidden;
  }

  .card::before{
    content:'';
    position:absolute;
    inset:0;
    border-radius:var(--radius);
    padding:1px;
    background:linear-gradient(135deg,rgba(124,92,255,0.5),rgba(34,158,217,0.1));
    -webkit-mask:linear-gradient(#fff 0 0) content-box, linear-gradient(#fff 0 0);
    -webkit-mask-composite:xor;
    mask-composite:exclude;
    opacity:0;
    transition:opacity .25s ease;
    pointer-events:none;
  }

  .card:hover{
    transform:translateY(-6px);
    border-color:rgba(124,92,255,0.4);
    box-shadow:0 16px 40px rgba(0,0,0,0.4);
  }
  .card:hover::before{opacity:1;}

  .product-tag{
    position:absolute;
    top:20px;right:20px;
    font-size:11px;
    font-weight:700;
    letter-spacing:0.04em;
    padding:5px 11px;
    border-radius:999px;
    background:rgba(124,92,255,0.15);
    border:1px solid rgba(124,92,255,0.4);
    color:#c9bfff;
  }

  .product-icon{
    width:56px;height:56px;
    border-radius:16px;
    display:flex;align-items:center;justify-content:center;
    font-size:24px;
    background:linear-gradient(135deg,var(--tg-blue),var(--accent-2));
    box-shadow:0 8px 20px rgba(88,101,242,0.35);
    margin-bottom:20px;
  }

  .product-name{font-size:19px;font-weight:700;margin-bottom:6px;}
  .product-desc{color:var(--muted);font-size:14px;margin-bottom:18px;min-height:42px;}

  .product-footer{
    display:flex;
    align-items:center;
    justify-content:space-between;
    margin-bottom:18px;
  }

  .product-price{font-size:24px;font-weight:800;}

  .product-status{
    font-size:12px;
    color:#7fe3a3;
    display:flex;align-items:center;gap:6px;
    font-weight:600;
  }

  .buy-btn{
    width:100%;
    padding:13px;
    border-radius:12px;
    font-size:14.5px;
  }

  /* ---------- FEATURE CARDS ---------- */
  .feature-grid{
    display:grid;
    grid-template-columns:repeat(auto-fit,minmax(240px,1fr));
    gap:22px;
    max-width:1000px;
    margin:0 auto;
  }

  .feature-card{
    background:var(--card);
    border:1px solid var(--card-border);
    border-radius:var(--radius);
    padding:30px 24px;
    text-align:center;
    backdrop-filter:blur(10px);
    -webkit-backdrop-filter:blur(10px);
    transition:transform .25s ease, border-color .25s ease;
  }
  .feature-card:hover{
    transform:translateY(-5px);
    border-color:rgba(34,158,217,0.4);
  }

  .feature-icon{
    width:60px;height:60px;
    margin:0 auto 18px;
    border-radius:16px;
    display:flex;align-items:center;justify-content:center;
    font-size:26px;
    background:rgba(124,92,255,0.12);
    border:1px solid rgba(124,92,255,0.3);
    color:#b9c4ff;
  }

  .feature-card h3{font-size:17.5px;font-weight:700;margin-bottom:10px;}
  .feature-card p{color:var(--muted);font-size:14.5px;}

  /* ---------- SUPPORT CTA ---------- */
  .support-cta{
    max-width:820px;
    margin:0 auto;
    text-align:center;
    padding:56px 32px;
    border-radius:24px;
    background:linear-gradient(135deg,rgba(34,158,217,0.12),rgba(124,92,255,0.12));
    border:1px solid rgba(124,92,255,0.3);
    position:relative;
    overflow:hidden;
  }

  .support-cta h2{font-size:clamp(1.5rem,4vw,2.1rem);font-weight:800;margin-bottom:12px;}
  .support-cta p{color:var(--muted);font-size:15.5px;margin-bottom:28px;}

  /* ---------- FOOTER ---------- */
  footer{
    padding:36px 24px;
    text-align:center;
    color:var(--muted-2);
    font-size:13.5px;
    border-top:1px solid var(--card-border);
  }
  footer .foot-brand{color:var(--muted);font-weight:600;margin-bottom:4px;}

  /* ---------- RESPONSIVE ---------- */
  @media (max-width:720px){
    .nav-links .btn-ghost{display:none;}
    .nav-links{gap:8px;}
    .hero{padding:64px 18px 48px;}
    .hero-actions{flex-direction:column;align-items:stretch;}
    .hero-actions .btn{width:100%;}
    .section{padding:48px 18px;}
    .card,.feature-card{padding:22px 18px;}
  }

  @media (max-width:420px){
    .brand span.brand-text{display:none;}
  }

  /* ---------- SCROLL REVEAL ---------- */
  .reveal{
    opacity:0;
    transform:translateY(24px);
    transition:opacity .7s ease, transform .7s ease;
  }
  .reveal.in-view{
    opacity:1;
    transform:translateY(0);
  }

  /* ---------- STATS STRIP ---------- */
  .stats-strip{
    display:flex;
    justify-content:center;
    gap:48px;
    flex-wrap:wrap;
    margin:40px auto 0;
    max-width:700px;
  }
  .stat-item{text-align:center;}
  .stat-num{
    font-size:clamp(1.6rem,3.5vw,2.2rem);
    font-weight:800;
    background:linear-gradient(135deg,var(--tg-blue),var(--accent-2));
    -webkit-background-clip:text;background-clip:text;color:transparent;
  }
  .stat-label{font-size:12.5px;color:var(--muted);font-weight:600;letter-spacing:0.03em;margin-top:2px;}

  /* ---------- SHIMMER TAG ---------- */
  .product-tag{overflow:hidden;}
  .product-tag::after{
    content:'';
    position:absolute;
    top:0;left:-150%;
    width:60%;height:100%;
    background:linear-gradient(120deg,transparent,rgba(255,255,255,0.35),transparent);
    animation:shimmer 3.2s infinite;
  }
  @keyframes shimmer{
    0%{left:-150%;}
    60%,100%{left:150%;}
  }

  #particle-canvas{
    position:fixed;
    inset:0;
    z-index:-1;
    pointer-events:none;
  }
</style>
</head>
<body>
<div class="bg-glow"></div>
<canvas id="particle-canvas"></canvas>

<!-- ================= NAVBAR ================= -->
<nav class="navbar">
  <div class="nav-inner">
    <div class="brand">
      <div class="logo-badge"><i class="fa-brands fa-telegram"></i></div>
      <span class="brand-text">Frenzy Store</span>
    </div>
    <div class="nav-links">
      <a href="#products" class="btn btn-ghost"><i class="fa-solid fa-bag-shopping"></i> Store</a>
      <a href="<?= e($botLink) ?>" target="_blank" rel="noopener" class="btn btn-ghost"><i class="fa-solid fa-headset"></i> Support</a>
      <a href="<?= e($botLink) ?>" target="_blank" rel="noopener" class="btn btn-primary"><i class="fa-brands fa-telegram"></i> Open Bot</a>
    </div>
  </div>
</nav>

<!-- ================= HERO ================= -->
<section class="hero">
  <div class="status-badge"><span class="status-dot"></span> Telegram Store • Online</div>
  <h1>Welcome to Frenzy Store</h1>
  <p class="subtitle">Premium Digital Products • Fast Delivery • Telegram Support</p>
  <div class="hero-actions">
    <a href="<?= e($botLink) ?>" target="_blank" rel="noopener" class="btn btn-primary">
      <i class="fa-brands fa-telegram"></i> Start Telegram Bot
    </a>
    <a href="#products" class="btn btn-ghost">
      <i class="fa-solid fa-bag-shopping"></i> Explore Store
    </a>
  </div>

  <div class="stats-strip reveal">
    <div class="stat-item"><div class="stat-num"><?= count($products) ?>+</div><div class="stat-label">PRODUCTS</div></div>
    <div class="stat-item"><div class="stat-num">24/7</div><div class="stat-label">SUPPORT</div></div>
    <div class="stat-item"><div class="stat-num">100%</div><div class="stat-label">SECURE CHECKOUT</div></div>
  </div>
</section>

<!-- ================= PRODUCTS ================= -->
<section class="section" id="products">
  <div class="section-head reveal">
    <h2>Featured Products</h2>
    <p>Hand-picked digital products, delivered securely through Telegram.</p>
  </div>
  <div class="grid reveal">
    <?php foreach ($products as $p): ?>
      <div class="card">
        <div class="product-tag"><?= e($p['tag']) ?></div>
        <div class="product-icon"><i class="fa-solid <?= e($p['icon']) ?>"></i></div>
        <div class="product-name"><?= e($p['name']) ?></div>
        <div class="product-desc"><?= e($p['desc']) ?></div>
        <div class="product-footer">
          <div class="product-price"><?= e($p['price']) ?></div>
          <div class="product-status"><i class="fa-solid fa-circle-check"></i> In Stock</div>
        </div>
        <a href="<?= e($botLink) ?>" target="_blank" rel="noopener" class="btn btn-primary buy-btn">
          <i class="fa-brands fa-telegram"></i> Buy via Telegram
        </a>
      </div>
    <?php endforeach; ?>
  </div>
</section>

<!-- ================= WHY FRENZY STORE ================= -->
<section class="section">
  <div class="section-head reveal">
    <h2>Why Frenzy Store</h2>
    <p>Built for speed, security, and a seamless Telegram-first experience.</p>
  </div>
  <div class="feature-grid reveal">
    <div class="feature-card">
      <div class="feature-icon"><i class="fa-solid fa-bolt"></i></div>
      <h3>Fast Delivery</h3>
      <p>Fast order processing through Telegram.</p>
    </div>
    <div class="feature-card">
      <div class="feature-icon"><i class="fa-solid fa-shield"></i></div>
      <h3>Secure</h3>
      <p>Keep bot credentials and sensitive data protected on the server.</p>
    </div>
    <div class="feature-card">
      <div class="feature-icon"><i class="fa-solid fa-headset"></i></div>
      <h3>Support</h3>
      <p>Get direct assistance through Telegram.</p>
    </div>
  </div>
</section>

<!-- ================= SUPPORT CTA ================= -->
<section class="section">
  <div class="support-cta reveal">
    <h2>Need Help?</h2>
    <p>Our support team is ready to assist you.</p>
    <a href="<?= e($botLink) ?>" target="_blank" rel="noopener" class="btn btn-primary">
      <i class="fa-brands fa-telegram"></i> Contact on Telegram
    </a>
  </div>
</section>

<!-- ================= FOOTER ================= -->
<footer>
  <div class="foot-brand">© 2026 Frenzy Store</div>
  <div>Premium Telegram Store</div>
</footer>

<script>
// ---- Scroll reveal ----
const reveals = document.querySelectorAll('.reveal');
const io = new IntersectionObserver((entries) => {
  entries.forEach(e => {
    if (e.isIntersecting) {
      e.target.classList.add('in-view');
      io.unobserve(e.target);
    }
  });
}, { threshold: 0.12 });
reveals.forEach(el => io.observe(el));

// ---- Lightweight floating particle background ----
(function () {
  const canvas = document.getElementById('particle-canvas');
  const ctx = canvas.getContext('2d');
  let w, h, particles;

  function resize() {
    w = canvas.width = window.innerWidth;
    h = canvas.height = window.innerHeight;
  }
  window.addEventListener('resize', resize);
  resize();

  const COUNT = Math.min(60, Math.floor((window.innerWidth * window.innerHeight) / 22000));
  particles = Array.from({ length: COUNT }, () => ({
    x: Math.random() * w,
    y: Math.random() * h,
    r: Math.random() * 1.6 + 0.4,
    vx: (Math.random() - 0.5) * 0.15,
    vy: (Math.random() - 0.5) * 0.15,
    a: Math.random() * 0.4 + 0.1,
  }));

  function tick() {
    ctx.clearRect(0, 0, w, h);
    particles.forEach(p => {
      p.x += p.vx;
      p.y += p.vy;
      if (p.x < 0) p.x = w; if (p.x > w) p.x = 0;
      if (p.y < 0) p.y = h; if (p.y > h) p.y = 0;
      ctx.beginPath();
      ctx.arc(p.x, p.y, p.r, 0, Math.PI * 2);
      ctx.fillStyle = `rgba(124,92,255,${p.a})`;
      ctx.fill();
    });
    requestAnimationFrame(tick);
  }
  tick();
})();
</script>

</body>
</html>
