<?php
/**
 * navbar.php
 * ============================================================
 * Navbar modular — include di semua halaman yang sudah login.
 *
 * Cara pakai (taruh tepat setelah <body> di halaman tujuan):
 *
 *   <?php
 *     $navActive  = 'dashboard';   // tentukan menu mana yang aktif:
 *                                  // 'dashboard' | 'rekap' | 'klasemen'
 *                                  // 'cetak' | 'penalti' | 'hasil'
 *     $navIdEvent = $idEvent ?? 1; // id_event halaman ini (opsional)
 *     require_once __DIR__ . '/navbar.php';
 *   ?>
 *
 * Variabel opsional yang bisa di-set sebelum include:
 *   $navActive   – string, nama menu yang sedang aktif
 *   $navIdEvent  – int,    id_event untuk query string link menu
 *   $navSubtitle – string, teks sub-judul di bawah brand (opsional)
 * ============================================================
 */

// ── Guard: auth_guard.php harus sudah di-include oleh halaman induk ──
// Jika belum, kita include di sini sebagai fallback.
if (!function_exists('currentUser')) {
    require_once __DIR__ . '/auth_guard.php';
    authGuard();
}
$_nav_user    = currentUser();
$_nav_role    = $_nav_user['role']         ?? '';
$_nav_nama    = $_nav_user['nama_lengkap'] ?? 'User';
$_nav_uname   = $_nav_user['username']     ?? '';
$_nav_inisial = strtoupper(substr(trim($_nav_nama), 0, 1)) ?: 'U';
$_nav_event   = (int)($navIdEvent ?? $_nav_user['id_event'] ?? 1);
$_nav_active  = $navActive  ?? '';
$_nav_sub     = $navSubtitle ?? match($_nav_role) {
    'admin' => 'Panel Admin',
    'juri'  => 'Form Penilaian Juri',
    default => 'Paskibra SaaS',
};

// Helper: tambah class 'active' jika nama cocok
function _navActive(string $key, string $current): string {
    return $key === $current ? ' nav-active' : '';
}
?>

<!-- ═══════════════════════════════════════════════════════════
     NAVBAR PASKIBRA SAAS
     Inline <style> dan <script> disertakan agar navbar.php
     benar-benar self-contained — tidak bergantung pada CSS
     halaman induk.
════════════════════════════════════════════════════════════ -->
<style>
/* ── Navbar shell ── */
.psk-navbar {
  background: var(--navy-mid, #162640);
  border-bottom: 1px solid var(--border, rgba(255,255,255,.07));
  padding: 0 1.4rem;
  position: sticky; top: 0; z-index: 1050;
  backdrop-filter: blur(14px);
  height: 60px;
  display: flex; align-items: center; justify-content: space-between; gap: .75rem;
}

/* ── Brand ── */
.psk-brand {
  display: flex; align-items: center; gap: 10px;
  text-decoration: none; flex-shrink: 0;
}
.psk-brand-icon {
  width: 36px; height: 36px; border-radius: 9px;
  background: linear-gradient(135deg, #c47d10, #f5a623);
  display: flex; align-items: center; justify-content: center;
  font-size: 1.05rem; flex-shrink: 0;
  box-shadow: 0 4px 12px rgba(245,166,35,.3);
}
.psk-brand-name {
  font-family: 'Sora', sans-serif; font-weight: 800;
  font-size: 1rem; color: #fff; letter-spacing: -.01em; line-height: 1.15;
}
.psk-brand-name span { color: #f5a623; }
.psk-brand-sub {
  font-size: .62rem; color: var(--text-dim, #7a94af);
  letter-spacing: .07em; text-transform: uppercase;
}

/* ── Menu tengah ── */
.psk-menu {
  display: flex; align-items: center; gap: .15rem;
  list-style: none; margin: 0; padding: 0;
  flex: 1; justify-content: center;
}
.psk-menu a {
  display: inline-flex; align-items: center; gap: 5px;
  padding: .4rem .85rem; border-radius: 8px;
  font-size: .82rem; font-weight: 500;
  color: var(--text-dim, #7a94af);
  text-decoration: none; transition: all .15s; white-space: nowrap;
}
.psk-menu a:hover    { color: var(--text, #dce8f5); background: rgba(255,255,255,.06); }
.psk-menu a.nav-active {
  color: #38bdf8;
  background: rgba(56,189,248,.1);
  font-weight: 600;
}
/* Mobile: sembunyikan menu tengah di layar kecil */
@media (max-width: 767px) { .psk-menu { display: none; } }

/* ── Sisi kanan ── */
.psk-right {
  display: flex; align-items: center; gap: .5rem; flex-shrink: 0;
}

/* ── Dropdown profil ── */
.psk-profile-btn {
  display: flex; align-items: center; gap: 7px; cursor: pointer;
  background: rgba(255,255,255,.05);
  border: 1px solid rgba(255,255,255,.08);
  border-radius: 100px; padding: 4px 10px 4px 4px;
  font-size: .78rem; color: var(--text, #dce8f5);
  transition: background .15s; user-select: none;
  position: relative;
}
.psk-profile-btn:hover { background: rgba(255,255,255,.10); }

.psk-avatar {
  width: 28px; height: 28px; border-radius: 50%; flex-shrink: 0;
  background: linear-gradient(135deg, #a78bfa, #38bdf8);
  display: flex; align-items: center; justify-content: center;
  font-size: .7rem; font-weight: 800; color: #fff;
}
.psk-caret {
  font-size: .6rem; color: var(--text-dim, #7a94af);
  transition: transform .2s;
}
.psk-profile-btn.open .psk-caret { transform: rotate(180deg); }

/* ── Dropdown panel ── */
.psk-dropdown {
  position: absolute; top: calc(100% + 8px); right: 0;
  min-width: 230px;
  background: #1c3050;
  border: 1px solid rgba(255,255,255,.1);
  border-radius: 13px;
  box-shadow: 0 16px 48px rgba(0,0,0,.55);
  padding: .4rem 0;
  display: none; z-index: 2000;
  animation: dd-in .18s ease;
}
.psk-dropdown.show { display: block; }
@keyframes dd-in {
  from { opacity: 0; transform: translateY(-8px); }
  to   { opacity: 1; transform: translateY(0); }
}

/* Header dropdown: nama & role */
.psk-dd-header {
  padding: .65rem 1rem .55rem;
  border-bottom: 1px solid rgba(255,255,255,.07);
  margin-bottom: .25rem;
}
.psk-dd-name  { font-weight: 700; font-size: .88rem; color: #fff; }
.psk-dd-role  {
  display: inline-block; margin-top: 3px;
  font-size: .65rem; font-weight: 700; letter-spacing: .08em;
  text-transform: uppercase; border-radius: 100px;
  padding: 2px 8px;
}
.dd-role-admin { background: rgba(245,166,35,.15); color: #f5a623;
                 border: 1px solid rgba(245,166,35,.3); }
.dd-role-juri  { background: rgba(56,189,248,.13);  color: #38bdf8;
                 border: 1px solid rgba(56,189,248,.3); }

/* Item dropdown */
.psk-dd-item {
  display: flex; align-items: center; gap: 9px;
  padding: .52rem 1rem; font-size: .83rem;
  color: var(--text, #dce8f5); text-decoration: none;
  transition: background .12s; cursor: pointer;
  border: none; width: 100%; background: transparent; text-align: left;
}
.psk-dd-item:hover { background: rgba(255,255,255,.06); color: #fff; }
.psk-dd-item .dd-icon {
  width: 28px; height: 28px; border-radius: 7px; flex-shrink: 0;
  display: flex; align-items: center; justify-content: center; font-size: .85rem;
}
.psk-dd-item.danger       { color: #ff8a8a; }
.psk-dd-item.danger:hover { background: rgba(230,57,70,.1); color: #ffb3b3; }

.psk-divider { border-color: rgba(255,255,255,.07); margin: .3rem 0; }

/* ── Theme toggle switch ── */
.theme-switch-row {
  display: flex; align-items: center; justify-content: space-between;
  padding: .48rem 1rem; gap: 8px;
}
.theme-switch-label {
  display: flex; align-items: center; gap: 9px;
  font-size: .83rem; color: var(--text, #dce8f5);
}
.theme-switch-label .dd-icon {
  width: 28px; height: 28px; border-radius: 7px; flex-shrink: 0;
  display: flex; align-items: center; justify-content: center; font-size: .85rem;
}
/* Toggle pill */
.psk-toggle {
  position: relative; width: 42px; height: 23px; cursor: pointer; flex-shrink: 0;
}
.psk-toggle input { opacity: 0; width: 0; height: 0; position: absolute; }
.psk-toggle-track {
  position: absolute; inset: 0;
  background: rgba(255,255,255,.12);
  border: 1px solid rgba(255,255,255,.18);
  border-radius: 100px; transition: background .25s;
}
.psk-toggle input:checked ~ .psk-toggle-track {
  background: #38bdf8;
  border-color: #38bdf8;
}
.psk-toggle-thumb {
  position: absolute; top: 3px; left: 3px;
  width: 15px; height: 15px; border-radius: 50%;
  background: rgba(255,255,255,.55);
  transition: transform .25s, background .25s;
}
.psk-toggle input:checked ~ .psk-toggle-thumb {
  transform: translateX(19px);
  background: #fff;
}

/* ── Light mode overrides ── */
[data-theme="light"] .psk-navbar {
  background: #f0f4f8;
  border-bottom-color: #d1dce8;
}
[data-theme="light"] .psk-brand-name  { color: #0d1b2e; }
[data-theme="light"] .psk-brand-sub   { color: #6a8aaa; }
[data-theme="light"] .psk-menu a      { color: #4e6a87; }
[data-theme="light"] .psk-menu a:hover { background: rgba(0,0,0,.05); color: #0d1b2e; }
[data-theme="light"] .psk-menu a.nav-active { color: #0b74b5; background: rgba(56,189,248,.12); }
[data-theme="light"] .psk-profile-btn { background: rgba(0,0,0,.04); border-color: rgba(0,0,0,.1); color: #1a3050; }
[data-theme="light"] .psk-dropdown    { background: #fff; border-color: #d1dce8;
                                         box-shadow: 0 12px 36px rgba(0,0,0,.15); }
[data-theme="light"] .psk-dd-name     { color: #0d1b2e; }
[data-theme="light"] .psk-dd-item     { color: #1a3050; }
[data-theme="light"] .psk-dd-item:hover { background: rgba(0,0,0,.04); }
[data-theme="light"] .psk-divider     { border-color: #d1dce8; }
[data-theme="light"] .theme-switch-label { color: #1a3050; }
[data-theme="light"] .psk-toggle-track { background: rgba(0,0,0,.15); border-color: rgba(0,0,0,.2); }
</style>

<!-- ══ MARKUP NAVBAR ══ -->
<nav class="psk-navbar">

  <!-- ── Brand / Logo ── -->
  <a class="psk-brand" href="<?= $_nav_role === 'admin' ? 'dashboard_admin.php' : 'rekap.php' ?>">
    <div class="psk-brand-icon">🏆</div>
    <div>
      <div class="psk-brand-name">PASKIBRA <span>SAAS</span></div>
      <div class="psk-brand-sub"><?= htmlspecialchars($_nav_sub) ?></div>
    </div>
  </a>

  <!-- ── Menu Tengah (role-based) ── -->
  <ul class="psk-menu">

    <?php if ($_nav_role === 'admin'): ?>
    <!-- MENU ADMIN -->
    <li>
      <a href="dashboard_admin.php"<?= _navActive('dashboard', $_nav_active) ?>>
        <i class="bi bi-speedometer2"></i>Dashboard
      </a>
    </li>
    <li>
      <a href="rekap.php?id_event=<?= $_nav_event ?>"<?= _navActive('rekap', $_nav_active) ?>>
        <i class="bi bi-pencil-square"></i>Input Nilai
      </a>
    </li>
    <li>
      <a href="lihat_hasil.php?id_event=<?= $_nav_event ?>"<?= _navActive('hasil', $_nav_active) ?>>
        <i class="bi bi-table"></i>Rekap
      </a>
    </li>
    <li>
      <a href="dashboard_klasemen.php?id_event=<?= $_nav_event ?>"<?= _navActive('klasemen', $_nav_active) ?>>
        <i class="bi bi-trophy-fill"></i>Klasemen
      </a>
    </li>
    <li>
      <a href="form_penalti.php?id_event=<?= $_nav_event ?>"<?= _navActive('penalti', $_nav_active) ?>>
        <i class="bi bi-dash-circle-fill"></i>Penalti
      </a>
    </li>
    <li>
      <a href="cetak_berita_acara.php?id_event=<?= $_nav_event ?>"<?= _navActive('cetak', $_nav_active) ?>>
        <i class="bi bi-printer-fill"></i>Cetak
      </a>
    </li>
    <a href="manajemen_peserta.php?id_event=<?= $_nav_event ?>"<?= _navActive('cetak', $_nav_active) ?>>
        <i class="bi bi-printer-fill"></i>Manajemen Peserta
      </a>
    </li>

    <?php elseif ($_nav_role === 'juri'): ?>
    <!-- MENU JURI — lebih sederhana -->
    <li>
      <a href="rekap.php?id_event=<?= $_nav_event ?>"<?= _navActive('rekap', $_nav_active) ?>>
        <i class="bi bi-pencil-square"></i>Input Nilai
      </a>
    </li>
    <li>
      <a href="lihat_hasil.php?id_event=<?= $_nav_event ?>"<?= _navActive('hasil', $_nav_active) ?>>
        <i class="bi bi-table"></i>Lihat Hasil
      </a>
    </li>
    <li>
      <a href="dashboard_klasemen.php?id_event=<?= $_nav_event ?>"<?= _navActive('klasemen', $_nav_active) ?>>
        <i class="bi bi-trophy-fill"></i>Klasemen
      </a>
    </li>

    <?php endif; ?>
  </ul>

  <!-- ── Sisi Kanan ── -->
  <div class="psk-right">

    <!-- Dropdown profil -->
    <div style="position:relative;">
      <div class="psk-profile-btn" id="psk-profile-btn"
           onclick="pskToggleDropdown()" role="button" aria-expanded="false">
        <div class="psk-avatar"><?= $_nav_inisial ?></div>
        <span class="d-none d-sm-inline"><?= htmlspecialchars($_nav_nama) ?></span>
        <i class="bi bi-chevron-down psk-caret"></i>
      </div>

      <!-- Panel dropdown -->
      <div class="psk-dropdown" id="psk-dropdown">

        <!-- Header: nama + role badge -->
        <div class="psk-dd-header">
          <div class="psk-dd-name"><?= htmlspecialchars($_nav_nama) ?></div>
          <span class="psk-dd-role dd-role-<?= htmlspecialchars($_nav_role) ?>">
            <?= $_nav_role === 'admin' ? '⚙ Admin' : '🎖 Juri' ?>
          </span>
        </div>

        <!-- My Profile -->
        <a href="profile.php" class="psk-dd-item">
          <div class="dd-icon" style="background:rgba(167,139,250,.15);color:#a78bfa;">
            <i class="bi bi-person-fill"></i>
          </div>
          My Profile
        </a>

        <!-- Dark / Light mode toggle -->
        <div class="theme-switch-row">
          <div class="theme-switch-label">
            <div class="dd-icon" style="background:rgba(245,166,35,.13);color:#f5a623;" id="psk-theme-icon">
              <i class="bi bi-moon-stars-fill"></i>
            </div>
            <span id="psk-theme-label">Mode Gelap</span>
          </div>
          <label class="psk-toggle" title="Ganti tema">
            <input type="checkbox" id="psk-theme-chk" onchange="pskToggleTheme(this.checked)">
            <div class="psk-toggle-track"></div>
            <div class="psk-toggle-thumb"></div>
          </label>
        </div>

        <hr class="dropdown-divider psk-divider">

        <!-- Logout -->
        <a href="logout.php" class="psk-dd-item danger">
          <div class="dd-icon" style="background:rgba(230,57,70,.13);color:#ff8a8a;">
            <i class="bi bi-box-arrow-right"></i>
          </div>
          Logout
        </a>

      </div><!-- /psk-dropdown -->
    </div>

  </div><!-- /psk-right -->
  <div class="d-flex align-items-center">
        
        <div class="d-flex align-items-center me-3" title="Status Koneksi">
            <span id="connBadge" class="badge bg-success rounded-pill" style="width: 10px; height: 10px; display: inline-block; padding: 0;"></span>
            <small id="connLabel" class="ms-1 fw-bold text-secondary" style="font-size: 0.8rem;">Online</small>
        </div>
    </div>
    
    <div id="queuePill" class="d-none d-flex align-items-center me-3" title="Data belum tersinkronisasi">
    <span class="badge bg-warning text-dark d-flex align-items-center rounded-pill">
        <i class="bi bi-cloud-arrow-up me-1"></i> <span id="queueCount">0</span>
    </span>
</div>

</nav>

<!-- ══ SCRIPT THEME + DROPDOWN ══ -->
<script>
/* ─────────────────────────────────────────────────────────────
   DARK / LIGHT MODE
   - Menyimpan pilihan di localStorage key 'pskTheme'
   - Menerapkan data-theme="dark"|"light" ke <html>
   - Bootstrap 5 menggunakan data-bs-theme; kita set keduanya
     agar kompatibel dengan custom CSS maupun komponen BS
───────────────────────────────────────────────────────────── */
(function pskInitTheme() {
  const saved  = localStorage.getItem('pskTheme') || 'dark';
  const isDark = saved === 'dark';

  // Terapkan ke <html>
  document.documentElement.setAttribute('data-theme',    saved);
  document.documentElement.setAttribute('data-bs-theme', saved);

  // Sync toggle setelah DOM siap
  document.addEventListener('DOMContentLoaded', function () {
    const chk = document.getElementById('psk-theme-chk');
    if (chk) chk.checked = !isDark;   // checked = light mode aktif
    _pskUpdateThemeUI(!isDark);
  });
})();

function pskToggleTheme(isLight) {
  const theme = isLight ? 'light' : 'dark';
  localStorage.setItem('pskTheme', theme);
  document.documentElement.setAttribute('data-theme',    theme);
  document.documentElement.setAttribute('data-bs-theme', theme);
  _pskUpdateThemeUI(isLight);
}

function _pskUpdateThemeUI(isLight) {
  const lbl  = document.getElementById('psk-theme-label');
  const icon = document.getElementById('psk-theme-icon');
  if (lbl)  lbl.textContent = isLight ? 'Mode Terang' : 'Mode Gelap';
  if (icon) icon.innerHTML  = isLight
    ? '<i class="bi bi-sun-fill"></i>'
    : '<i class="bi bi-moon-stars-fill"></i>';
}

/* ─────────────────────────────────────────────────────────────
   DROPDOWN PROFIL
   - Toggle class .show dan .open
   - Tutup jika klik di luar dropdown
───────────────────────────────────────────────────────────── */
function pskToggleDropdown() {
  const btn = document.getElementById('psk-profile-btn');
  const dd  = document.getElementById('psk-dropdown');
  const open = dd.classList.toggle('show');
  btn.classList.toggle('open', open);
  btn.setAttribute('aria-expanded', open);
}

// Tutup dropdown jika klik di luar area navbar
document.addEventListener('click', function (e) {
  const btn = document.getElementById('psk-profile-btn');
  const dd  = document.getElementById('psk-dropdown');
  if (btn && dd && !btn.contains(e.target) && !dd.contains(e.target)) {
    dd.classList.remove('show');
    btn.classList.remove('open');
    btn.setAttribute('aria-expanded', 'false');
  }
});

// Tutup dropdown saat tekan Escape
document.addEventListener('keydown', function (e) {
  if (e.key === 'Escape') {
    const btn = document.getElementById('psk-profile-btn');
    const dd  = document.getElementById('psk-dropdown');
    if (dd) { dd.classList.remove('show'); }
    if (btn) { btn.classList.remove('open'); btn.setAttribute('aria-expanded','false'); }
  }
});
</script>
