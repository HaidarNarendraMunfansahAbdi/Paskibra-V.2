<?php
/**
 * login.php — Halaman form login Paskibra SaaS
 */
declare(strict_types=1);
session_start();

// Sudah login? Redirect langsung
if (!empty($_SESSION['id_user'])) {
    header('Location: ' . ($_SESSION['role'] === 'admin' ? 'dashboard_klasemen.php' : 'rekap.php'));
    exit;
}

$error = $_SESSION['login_error'] ?? '';
unset($_SESSION['login_error']);
?><!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Login — Paskibra SaaS</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
  <link href="https://fonts.googleapis.com/css2?family=Sora:wght@700;800&family=DM+Sans:wght@400;500&display=swap" rel="stylesheet">
  <style>
    :root {
      --navy:     #0d1b2e;
      --navy-mid: #162640;
      --card:     #1c3050;
      --border:   rgba(255,255,255,.08);
      --accent:   #f5a623;
      --cyan:     #38bdf8;
      --text:     #dce8f5;
      --dim:      #7a94af;
    }
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    body {
      font-family: 'DM Sans', sans-serif;
      background: var(--navy);
      color: var(--text);
      min-height: 100vh;
      display: flex;
      align-items: center;
      justify-content: center;
    }
    /* Subtle grid background */
    body::before {
      content: ''; position: fixed; inset: 0; pointer-events: none; z-index: 0;
      background:
        radial-gradient(ellipse 70% 50% at 30% 20%, rgba(56,189,248,.07), transparent),
        radial-gradient(ellipse 60% 50% at 70% 80%, rgba(167,139,250,.06), transparent),
        repeating-linear-gradient(-45deg, transparent, transparent 40px,
          rgba(255,255,255,.012) 40px, rgba(255,255,255,.012) 41px);
    }

    /* Card login */
    .login-wrap {
      position: relative; z-index: 1;
      width: 100%; max-width: 420px;
      padding: 1rem;
    }
    .login-card {
      background: var(--card);
      border: 1px solid var(--border);
      border-radius: 20px;
      padding: 2.5rem 2.25rem;
      box-shadow: 0 20px 60px rgba(0,0,0,.6);
    }

    /* Logo / brand */
    .login-brand {
      text-align: center;
      margin-bottom: 2rem;
    }
    .brand-icon {
      width: 60px; height: 60px;
      background: linear-gradient(135deg, #c47d10, var(--accent));
      border-radius: 16px;
      display: inline-flex; align-items: center; justify-content: center;
      font-size: 1.65rem;
      box-shadow: 0 8px 24px rgba(245,166,35,.35);
      margin-bottom: .85rem;
    }
    .brand-name {
      font-family: 'Sora', sans-serif;
      font-size: 1.5rem; font-weight: 800; color: #fff; letter-spacing: -.01em;
    }
    .brand-name span { color: var(--accent); }
    .brand-sub { font-size: .78rem; color: var(--dim); margin-top: 3px; }

    /* Input field */
    .field-label {
      font-size: .75rem; font-weight: 600; letter-spacing: .06em;
      text-transform: uppercase; color: var(--dim);
      margin-bottom: 6px; display: block;
    }
    .field-wrap { position: relative; margin-bottom: 1.1rem; }
    .field-input {
      width: 100%;
      background: #0f2034;
      border: 1px solid rgba(255,255,255,.12);
      border-radius: 10px;
      padding: .7rem 1rem .7rem 2.8rem;
      color: var(--text);
      font-size: .95rem;
      font-family: 'DM Sans', sans-serif;
      transition: border-color .15s, box-shadow .15s;
    }
    .field-input:focus {
      outline: none;
      border-color: var(--cyan);
      box-shadow: 0 0 0 3px rgba(56,189,248,.15);
    }
    .field-icon {
      position: absolute; left: .9rem; top: 50%; transform: translateY(-50%);
      color: var(--dim); font-size: 1rem; pointer-events: none;
    }
    /* Toggle show/hide password */
    .pwd-toggle {
      position: absolute; right: .85rem; top: 50%; transform: translateY(-50%);
      background: none; border: none; color: var(--dim); cursor: pointer;
      padding: 0; font-size: 1rem; transition: color .15s;
    }
    .pwd-toggle:hover { color: var(--cyan); }

    /* Error alert */
    .login-error {
      background: rgba(230,57,70,.12);
      border: 1px solid rgba(230,57,70,.35);
      color: #ff9aa2;
      border-radius: 10px;
      padding: .7rem 1rem;
      font-size: .84rem;
      margin-bottom: 1.25rem;
      display: flex; align-items: center; gap: 8px;
    }

    /* Submit button */
    .btn-login {
      width: 100%;
      background: var(--accent);
      color: var(--navy);
      border: none;
      border-radius: 10px;
      padding: .75rem;
      font-family: 'Sora', sans-serif;
      font-weight: 700; font-size: 1rem;
      cursor: pointer;
      transition: background .15s, transform .1s;
      display: flex; align-items: center; justify-content: center; gap: 8px;
    }
    .btn-login:hover  { background: #d48a10; }
    .btn-login:active { transform: scale(.98); }

    .login-footer {
      text-align: center;
      margin-top: 1.5rem;
      font-size: .72rem; color: var(--dim);
    }
  </style>
</head>
<body>

<div class="login-wrap">
  <div class="login-card">

    <!-- Brand -->
    <div class="login-brand">
      <div class="brand-icon">🏆</div>
      <div class="brand-name">PASKIBRA <span>SAAS</span></div>
      <div class="brand-sub">Sistem Penilaian &amp; Rekapitulasi</div>
    </div>

    <!-- Error -->
    <?php if ($error): ?>
    <div class="login-error">
      <i class="bi bi-exclamation-triangle-fill"></i>
      <?= htmlspecialchars($error) ?>
    </div>
    <?php endif; ?>

    <!-- Form -->
    <form method="POST" action="proses_login.php" autocomplete="on">
      <!-- CSRF token sederhana (session-based) -->
      <?php
        if (empty($_SESSION['csrf'])) {
            $_SESSION['csrf'] = bin2hex(random_bytes(16));
        }
      ?>
      <input type="hidden" name="csrf" value="<?= $_SESSION['csrf'] ?>">

      <!-- Username -->
      <div class="field-wrap">
        <label class="field-label" for="username">Username</label>
        <i class="bi bi-person-fill field-icon"></i>
        <input type="text" id="username" name="username" class="field-input"
               placeholder="Masukkan username"
               required autocomplete="username"
               value="<?= htmlspecialchars($_POST['username'] ?? '') ?>">
      </div>

      <!-- Password -->
      <div class="field-wrap">
        <label class="field-label" for="password">Password</label>
        <i class="bi bi-lock-fill field-icon"></i>
        <input type="password" id="password" name="password" class="field-input"
               placeholder="Masukkan password"
               required autocomplete="current-password">
        <button type="button" class="pwd-toggle" id="pwd-toggle"
                onclick="togglePwd()" title="Tampilkan / sembunyikan">
          <i class="bi bi-eye" id="pwd-icon"></i>
        </button>
      </div>

      <button type="submit" class="btn-login">
        <i class="bi bi-box-arrow-in-right"></i>Masuk
      </button>
    </form>

    <div class="login-footer">Paskibra SaaS &copy; <?= date('Y') ?></div>
  </div>
</div>

<script>
function togglePwd() {
  const inp  = document.getElementById('password');
  const icon = document.getElementById('pwd-icon');
  const show = inp.type === 'password';
  inp.type   = show ? 'text' : 'password';
  icon.className = show ? 'bi bi-eye-slash' : 'bi bi-eye';
}
</script>
</body>
</html>
