<?php
/**
 * dashboard_admin.php
 * ============================================================
 * Halaman Utama Admin — Paskibra SaaS
 * ============================================================
 */
declare(strict_types=1);
require_once __DIR__ . '/auth_guard.php';
authGuard('admin');                   // ← lempar 403 jika bukan admin
$me = currentUser();

require_once __DIR__ . '/koneksi.php';

// ── Parameter event aktif ─────────────────────────────────────
$idEvent = filter_input(INPUT_GET, 'id_event', FILTER_VALIDATE_INT)
        ?: ($_SESSION['id_event'] ?? 1);

// ── Query: semua event ────────────────────────────────────────
$events = $pdo->query(
    "SELECT id_event, nama_event, tgl_mulai, status_aktif
     FROM tabel_event ORDER BY id_event DESC"
)->fetchAll();

// ── Nama event terpilih ───────────────────────────────────────
$stmtEv = $pdo->prepare(
    "SELECT nama_event, tgl_mulai, tgl_selesai, status_aktif
     FROM tabel_event WHERE id_event = :ie LIMIT 1"
);
$stmtEv->execute([':ie' => $idEvent]);
$event = $stmtEv->fetch() ?: [
    'nama_event'   => 'Event #' . $idEvent,
    'tgl_mulai'    => null,
    'tgl_selesai'  => null,
    'status_aktif' => 0,
];

// ── Stat 1: Total Peserta event ini ──────────────────────────
$stmtCntP = $pdo->prepare("SELECT COUNT(*) FROM tabel_peserta WHERE id_event = :ie");
$stmtCntP->execute([':ie' => $idEvent]);
$totalPeserta = (int) $stmtCntP->fetchColumn();

// ── Stat 2: Total Juri event ini ──────────────────────────────
$stmtCntJ = $pdo->prepare(
    "SELECT COUNT(*) FROM tabel_juri WHERE id_event = :ie"
);
$stmtCntJ->execute([':ie' => $idEvent]);
$totalJuri = (int) $stmtCntJ->fetchColumn();

// ── Stat 3: Total Penilaian (baris nilai) ────────────────────
$stmtCntN = $pdo->prepare(
    "SELECT COUNT(DISTINCT n.id_peserta)
     FROM tabel_penilaian n
     JOIN tabel_peserta p ON p.id_peserta = n.id_peserta
     WHERE p.id_event = :ie"
);
$stmtCntN->execute([':ie' => $idEvent]);
$sudahDinilai = (int) $stmtCntN->fetchColumn();

// ── Stat 4: Total kriteria ────────────────────────────────────
$stmtCntK = $pdo->prepare(
    "SELECT COUNT(*) FROM tabel_kriteria kr
     JOIN tabel_kategori kat ON kat.id_kategori = kr.id_kategori
     WHERE kat.id_event = :ie"
);
$stmtCntK->execute([':ie' => $idEvent]);
$totalKriteria = (int) $stmtCntK->fetchColumn();

// ── Top 3 klasemen cepat ──────────────────────────────────────
$stmtTop = $pdo->prepare(
    "SELECT p.no_urut, p.nama_regu, p.asal_sekolah,
            COALESCE(SUM(n.nilai_diperoleh), 0) AS total_nilai
     FROM tabel_peserta p
     LEFT JOIN tabel_penilaian n ON n.id_peserta = p.id_peserta
     WHERE p.id_event = :ie
     GROUP BY p.id_peserta, p.no_urut, p.nama_regu, p.asal_sekolah
     ORDER BY total_nilai DESC, p.no_urut ASC
     LIMIT 5"
);
$stmtTop->execute([':ie' => $idEvent]);
$top5 = $stmtTop->fetchAll();

// ── Aktivitas terbaru (10 penilaian terakhir) ─────────────────
$stmtAkt = $pdo->prepare(
    "SELECT p.nama_regu, j.nama_juri, j.kode_juri,
            n.nilai_diperoleh, n.metode_input, n.updated_at,
            kr.nama_gerakan
     FROM tabel_penilaian n
     JOIN tabel_peserta  p  ON p.id_peserta  = n.id_peserta
     JOIN tabel_juri     j  ON j.id_juri     = n.id_juri
     JOIN tabel_kriteria kr ON kr.id_kriteria = n.id_kriteria
     WHERE p.id_event = :ie
     ORDER BY n.updated_at DESC
     LIMIT 10"
);
$stmtAkt->execute([':ie' => $idEvent]);
$aktivitas = $stmtAkt->fetchAll();

// ── Status lomba ──────────────────────────────────────────────
$pctSelesai  = $totalPeserta > 0
    ? round($sudahDinilai / $totalPeserta * 100) : 0;
$statusLabel = match(true) {
    $totalPeserta === 0   => ['Belum Dikonfigurasi', 'muted',  'bi-gear'],
    $sudahDinilai === 0   => ['Siap Dilaksanakan',   'cyan',   'bi-play-circle'],
    $pctSelesai < 100     => ['Sedang Berlangsung',  'accent', 'bi-lightning-charge-fill'],
    default               => ['Penilaian Selesai',   'green',  'bi-patch-check-fill'],
};

$waktuNow = date('d/m/Y H:i:s');
?><!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Dashboard Admin — Paskibra SaaS</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
  <link href="https://fonts.googleapis.com/css2?family=Sora:wght@600;700;800&family=DM+Sans:wght@300;400;500&display=swap" rel="stylesheet">

  <style>
    :root {
      --navy:      #0d1b2e;
      --navy-mid:  #162640;
      --navy-card: #1c3050;
      --border:    rgba(255,255,255,0.07);
      --text:      #dce8f5;
      --muted:     #7a94af;
      --accent:    #f5a623;
      --cyan:      #38bdf8;
      --green:     #2dc653;
      --purple:    #a78bfa;
      --red:       #e63946;
    }

    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

    body {
      font-family: 'DM Sans', sans-serif;
      background: var(--navy);
      color: var(--text);
      min-height: 100vh;
    }

    body::before {
      content: ''; position: fixed; inset: 0; z-index: 0; pointer-events: none;
      background:
        radial-gradient(ellipse 70% 40% at 10% 0%, rgba(56,189,248,.05), transparent),
        radial-gradient(ellipse 60% 40% at 90% 100%, rgba(167,139,250,.05), transparent),
        repeating-linear-gradient(-45deg, transparent, transparent 40px,
          rgba(255,255,255,.014) 40px, rgba(255,255,255,.014) 41px);
    }

    /* ── Navbar ── */
    .top-nav {
      background: var(--navy-mid);
      border-bottom: 1px solid var(--border);
      padding: 0 1.75rem;
      position: sticky; top: 0; z-index: 1000;
      backdrop-filter: blur(12px);
      height: 60px;
      display: flex; align-items: center; justify-content: space-between; gap: 1rem;
    }

    .nav-brand {
      display: flex; align-items: center; gap: 10px; text-decoration: none;
      flex-shrink: 0;
    }
    .nav-brand-icon {
      width: 36px; height: 36px; border-radius: 9px;
      background: linear-gradient(135deg, #c47d10, var(--accent));
      display: flex; align-items: center; justify-content: center;
      font-size: 1.05rem; flex-shrink: 0;
    }
    .nav-brand-text {
      font-family: 'Sora', sans-serif; font-weight: 800; font-size: 1rem; color: #fff;
    }
    .nav-brand-text span { color: var(--accent); }

    .nav-menu {
      display: flex; align-items: center; gap: .25rem;
      list-style: none; padding: 0; margin: 0;
    }
    .nav-menu a {
      display: inline-flex; align-items: center; gap: 6px;
      padding: .45rem .9rem; border-radius: 8px;
      font-size: .83rem; font-weight: 500; color: var(--muted);
      text-decoration: none; transition: all .15s;
      white-space: nowrap;
    }
    .nav-menu a:hover { color: var(--text); background: rgba(255,255,255,.06); }
    .nav-menu a.active { color: var(--cyan); background: rgba(56,189,248,.1); }

    .nav-right { display: flex; align-items: center; gap: .6rem; flex-shrink: 0; }

    .user-chip {
      display: flex; align-items: center; gap: 7px;
      background: rgba(255,255,255,.05);
      border: 1px solid var(--border);
      border-radius: 100px; padding: .35rem .85rem .35rem .45rem;
      font-size: .78rem; color: var(--text);
    }
    .user-avatar {
      width: 26px; height: 26px; border-radius: 50%;
      background: linear-gradient(135deg, var(--purple), var(--cyan));
      display: flex; align-items: center; justify-content: center;
      font-size: .7rem; font-weight: 800; color: #fff; flex-shrink: 0;
    }

    .btn-logout {
      display: inline-flex; align-items: center; gap: 6px;
      background: rgba(230,57,70,.12);
      border: 1px solid rgba(230,57,70,.3);
      color: #ff8a8a; border-radius: 8px;
      padding: .4rem .9rem; font-size: .8rem;
      text-decoration: none; transition: all .15s; white-space: nowrap;
    }
    .btn-logout:hover { background: rgba(230,57,70,.22); color: #ffb3b3; }

    /* ── Page wrapper ── */
    .page { position: relative; z-index: 1; padding: 2rem 1.75rem 4rem; }

    /* ── Welcome strip ── */
    .welcome-strip {
      background: linear-gradient(135deg, #0d2140 0%, #162e50 100%);
      border: 1px solid rgba(56,189,248,.15);
      border-radius: 16px;
      padding: 1.5rem 1.75rem;
      margin-bottom: 1.75rem;
      display: flex; align-items: center; justify-content: space-between;
      flex-wrap: wrap; gap: 1rem;
    }
    .welcome-eyebrow {
      font-size: .68rem; font-weight: 700; letter-spacing: .12em;
      text-transform: uppercase; color: var(--cyan); margin-bottom: .3rem;
    }
    .welcome-title {
      font-family: 'Sora', sans-serif; font-size: 1.45rem;
      font-weight: 800; color: #fff; margin: 0;
    }
    .welcome-sub { font-size: .82rem; color: var(--muted); margin-top: 4px; }
    .welcome-time { font-size: .75rem; color: var(--muted); text-align: right; }

    /* ── Event switcher ── */
    .ev-bar {
      background: var(--navy-card);
      border: 1px solid var(--border);
      border-radius: 12px; padding: .75rem 1.1rem;
      margin-bottom: 1.75rem;
      display: flex; align-items: center; gap: .75rem; flex-wrap: wrap;
    }
    .ev-label { font-size: .72rem; color: var(--muted); white-space: nowrap; }
    .ev-select {
      background: #0f2034; color: var(--text);
      border: 1px solid rgba(255,255,255,.12); border-radius: 8px;
      padding: .4rem .8rem; font-size: .85rem; cursor: pointer; flex: 1; min-width: 180px;
    }
    .ev-select:focus { outline: none; border-color: var(--cyan);
      box-shadow: 0 0 0 3px rgba(56,189,248,.12); }
    .btn-ev-apply {
      background: var(--cyan); color: #0d1b2e;
      border: none; border-radius: 8px;
      font-family: 'Sora', sans-serif; font-weight: 700; font-size: .8rem;
      padding: .45rem 1.1rem; cursor: pointer; transition: opacity .15s; white-space: nowrap;
    }
    .btn-ev-apply:hover { opacity: .85; }

    /* ── Stat cards ── */
    .stat-card {
      background: var(--navy-card);
      border: 1px solid var(--border);
      border-radius: 14px; padding: 1.35rem 1.4rem;
      height: 100%;
      transition: transform .15s, box-shadow .15s;
    }
    .stat-card:hover { transform: translateY(-2px); box-shadow: 0 8px 28px rgba(0,0,0,.35); }

    .stat-icon-wrap {
      width: 46px; height: 46px; border-radius: 11px; flex-shrink: 0;
      display: flex; align-items: center; justify-content: center;
      font-size: 1.3rem; margin-bottom: .9rem;
    }
    .ic-cyan   { background: rgba(56,189,248,.14);  color: var(--cyan); }
    .ic-purple { background: rgba(167,139,250,.14); color: var(--purple); }
    .ic-accent { background: rgba(245,166,35,.13);  color: var(--accent); }
    .ic-green  { background: rgba(45,198,83,.13);   color: var(--green); }
    .ic-red    { background: rgba(230,57,70,.13);   color: var(--red); }

    .stat-val {
      font-family: 'Sora', sans-serif; font-size: 2rem;
      font-weight: 800; color: #fff; line-height: 1; margin-bottom: 4px;
    }
    .stat-label { font-size: .75rem; color: var(--muted); font-weight: 500; }

    /* Status lomba chip */
    .status-chip {
      display: inline-flex; align-items: center; gap: 5px;
      border-radius: 100px; padding: 4px 10px;
      font-size: .72rem; font-weight: 700; white-space: nowrap;
    }
    .sc-cyan   { background: rgba(56,189,248,.14);  color: var(--cyan);   border: 1px solid rgba(56,189,248,.3); }
    .sc-accent { background: rgba(245,166,35,.13);  color: var(--accent); border: 1px solid rgba(245,166,35,.3); }
    .sc-green  { background: rgba(45,198,83,.13);   color: var(--green);  border: 1px solid rgba(45,198,83,.3); }
    .sc-muted  { background: rgba(255,255,255,.05); color: var(--muted);  border: 1px solid var(--border); }

    /* Progress bar */
    .mini-bar-wrap { background: rgba(255,255,255,.06); border-radius: 100px; height: 5px; margin-top: 8px; }
    .mini-bar-fill { height: 100%; border-radius: 100px; }

    /* ── Glass section cards ── */
    .glass-card {
      background: var(--navy-card);
      border: 1px solid var(--border);
      border-radius: 14px; overflow: hidden;
    }
    .card-hd {
      padding: .9rem 1.2rem; border-bottom: 1px solid var(--border);
      display: flex; align-items: center; gap: 10px;
    }
    .card-hd-icon {
      width: 32px; height: 32px; border-radius: 8px;
      display: flex; align-items: center; justify-content: center;
      font-size: .95rem; flex-shrink: 0;
    }
    .card-hd-title { font-family: 'Sora', sans-serif; font-size: .9rem; font-weight: 700; color: #fff; }
    .card-hd-sub   { font-size: .7rem; color: var(--muted); }

    /* ── Top-5 mini table ── */
    .mini-table { width: 100%; border-collapse: collapse; }
    .mini-table td { padding: .7rem 1rem; border-bottom: 1px solid var(--border);
                     font-size: .83rem; vertical-align: middle; }
    .mini-table tr:last-child td { border-bottom: none; }
    .mini-table tr:hover td { background: rgba(255,255,255,.025); }

    .mt-rank {
      width: 40px; text-align: center;
      font-family: 'Sora', sans-serif; font-weight: 800; font-size: .88rem;
    }
    .mt-nama  { color: var(--text); font-weight: 500; }
    .mt-sekolah { font-size: .7rem; color: var(--muted); margin-top: 1px; }
    .mt-nilai {
      text-align: right;
      font-family: 'Sora', sans-serif; font-weight: 800; font-size: .95rem;
    }

    .medal-1 { color: #FFD700; } .medal-2 { color: #C0C0C0; } .medal-3 { color: #CD7F32; }
    .rank-n  { color: var(--muted); font-size: .8rem; }

    .empty-row { text-align: center; padding: 2rem; color: var(--muted); font-size: .82rem; }

    /* ── Aktivitas feed ── */
    .feed-item {
      padding: .7rem 1rem; border-bottom: 1px solid var(--border);
      display: flex; align-items: center; gap: 10px;
    }
    .feed-item:last-child { border-bottom: none; }
    .feed-dot {
      width: 8px; height: 8px; border-radius: 50%; flex-shrink: 0;
    }
    .fd-scan   { background: var(--purple); }
    .fd-manual { background: var(--accent); }
    .feed-text { font-size: .8rem; color: var(--text); flex: 1; }
    .feed-text span { color: var(--muted); }
    .feed-time { font-size: .7rem; color: var(--muted); white-space: nowrap; }

    /* ── Quick action buttons ── */
    .qa-btn {
      display: flex; align-items: center; gap: 10px;
      background: rgba(255,255,255,.04);
      border: 1px solid var(--border);
      border-radius: 11px; padding: .85rem 1rem;
      text-decoration: none; color: var(--text);
      transition: all .15s; font-size: .85rem;
      width: 100%;
    }
    .qa-btn:hover { background: rgba(255,255,255,.08); color: #fff;
                    border-color: rgba(255,255,255,.14); transform: translateX(3px); }
    .qa-icon {
      width: 38px; height: 38px; border-radius: 9px;
      display: flex; align-items: center; justify-content: center;
      font-size: 1.05rem; flex-shrink: 0;
    }
    .qa-label  { font-weight: 600; }
    .qa-desc   { font-size: .7rem; color: var(--muted); margin-top: 1px; }

    ::-webkit-scrollbar { width: 5px; }
    ::-webkit-scrollbar-thumb { background: rgba(255,255,255,.1); border-radius: 3px; }
  </style>
</head>
<body>

<!-- ═══════════════ NAVBAR ═══════════════ -->
<?php include 'navbar.php'; ?>

<!-- ═══════════════ PAGE ═══════════════ -->
<div class="page">
<div class="container-fluid" style="max-width:1140px;">

  <!-- ── Welcome strip ── -->
  <div class="welcome-strip">
    <div>
      <div class="welcome-eyebrow"><i class="bi bi-shield-fill-check me-1"></i>Panel Admin</div>
      <h1 class="welcome-title">
        Selamat Datang, <?= htmlspecialchars(explode(' ', $me['nama_lengkap'])[0]) ?>! 👋
      </h1>
      <div class="welcome-sub"><?= htmlspecialchars($event['nama_event']) ?></div>
    </div>
    <div class="welcome-time">
      <div style="font-size:.8rem;color:var(--text);margin-bottom:2px;">
        <i class="bi bi-clock me-1"></i><?= $waktuNow ?>
      </div>
      <?php if ($event['tgl_mulai']): ?>
      <div>
        <i class="bi bi-calendar3 me-1"></i>
        <?= date('d/m/Y', strtotime($event['tgl_mulai'])) ?>
        <?= $event['tgl_selesai'] ? ' — ' . date('d/m/Y', strtotime($event['tgl_selesai'])) : '' ?>
      </div>
      <?php endif; ?>
    </div>
  </div>

  <!-- ── Event switcher ── -->
  <?php if (count($events) > 1): ?>
  <form method="GET" class="ev-bar">
    <span class="ev-label"><i class="bi bi-calendar-event me-1"></i>Event aktif:</span>
    <select name="id_event" class="ev-select">
      <?php foreach ($events as $ev): ?>
        <option value="<?= $ev['id_event'] ?>" <?= $ev['id_event'] == $idEvent ? 'selected' : '' ?>>
          <?= htmlspecialchars($ev['nama_event']) ?>
          <?= $ev['status_aktif'] ? ' ✓' : '' ?>
        </option>
      <?php endforeach; ?>
    </select>
    <button type="submit" class="btn-ev-apply">
      <i class="bi bi-arrow-right-circle me-1"></i>Ganti
    </button>
  </form>
  <?php endif; ?>

  <!-- ════════════════════════════════════════
       STAT CARDS
  ════════════════════════════════════════ -->
  <div class="row g-3 mb-4">

    <!-- Total Peserta -->
    <div class="col-6 col-lg-3">
      <div class="stat-card">
        <div class="stat-icon-wrap ic-cyan">
          <i class="bi bi-people-fill"></i>
        </div>
        <div class="stat-val"><?= $totalPeserta ?></div>
        <div class="stat-label">Total Peserta</div>
        <div class="mini-bar-wrap">
          <div class="mini-bar-fill" style="width:100%;background:var(--cyan);"></div>
        </div>
      </div>
    </div>

    <!-- Total Juri -->
    <div class="col-6 col-lg-3">
      <div class="stat-card">
        <div class="stat-icon-wrap ic-purple">
          <i class="bi bi-person-badge-fill"></i>
        </div>
        <div class="stat-val"><?= $totalJuri ?></div>
        <div class="stat-label">Dewan Juri</div>
        <div class="mini-bar-wrap">
          <div class="mini-bar-fill" style="width:100%;background:var(--purple);"></div>
        </div>
      </div>
    </div>

    <!-- Sudah Dinilai -->
    <div class="col-6 col-lg-3">
      <div class="stat-card">
        <div class="stat-icon-wrap ic-accent">
          <i class="bi bi-clipboard2-check-fill"></i>
        </div>
        <div class="stat-val"><?= $sudahDinilai ?><span style="font-size:1rem;color:var(--muted);">/<?= $totalPeserta ?></span></div>
        <div class="stat-label">Peserta Sudah Dinilai</div>
        <div class="mini-bar-wrap">
          <div class="mini-bar-fill"
               style="width:<?= $pctSelesai ?>%;background:var(--accent);"></div>
        </div>
      </div>
    </div>

    <!-- Status Lomba -->
    <div class="col-6 col-lg-3">
      <div class="stat-card">
        <div class="stat-icon-wrap ic-green">
          <i class="bi <?= $statusLabel[2] ?>"></i>
        </div>
        <div class="stat-val" style="font-size:1rem;padding-top:4px;">
          <span class="status-chip sc-<?= $statusLabel[1] ?>">
            <i class="bi <?= $statusLabel[2] ?>"></i><?= $statusLabel[0] ?>
          </span>
        </div>
        <div class="stat-label" style="margin-top:8px;">Status Lomba</div>
        <div class="mini-bar-wrap">
          <div class="mini-bar-fill"
               style="width:<?= $pctSelesai ?>%;background:var(--green);"></div>
        </div>
      </div>
    </div>

  </div><!-- /stat cards -->

  <!-- ════════════════════════════════════════
       KONTEN BAWAH — 3 kolom
  ════════════════════════════════════════ -->
  <div class="row g-3">

    <!-- ── Klasemen Top 5 ── -->
    <div class="col-lg-5">
      <div class="glass-card h-100">
        <div class="card-hd">
          <div class="card-hd-icon ic-accent" style="background:rgba(245,166,35,.13);">
            <i class="bi bi-trophy-fill" style="color:var(--accent);"></i>
          </div>
          <div>
            <div class="card-hd-title">Top 5 Klasemen</div>
            <div class="card-hd-sub">Berdasarkan total nilai saat ini</div>
          </div>
          <a href="lihat_hasil.php?id_event=<?= $idEvent ?>"
             style="margin-left:auto;font-size:.72rem;color:var(--cyan);text-decoration:none;">
            Lihat semua →
          </a>
        </div>

        <?php if (empty($top5)): ?>
        <div class="empty-row">
          <i class="bi bi-inbox" style="font-size:1.8rem;display:block;margin-bottom:.5rem;"></i>
          Belum ada data penilaian
        </div>
        <?php else: ?>
        <table class="mini-table">
          <?php foreach ($top5 as $i => $r):
            $rank  = $i + 1;
            $nilai = (float)$r['total_nilai'];
            $rankDisplay = match($rank) {
              1 => '<span class="medal-1">🥇</span>',
              2 => '<span class="medal-2">🥈</span>',
              3 => '<span class="medal-3">🥉</span>',
              default => "<span class=\"rank-n\">{$rank}</span>",
            };
          ?>
          <tr>
            <td class="mt-rank"><?= $rankDisplay ?></td>
            <td>
              <div class="mt-nama"><?= htmlspecialchars($r['nama_regu']) ?></div>
              <?php if ($r['asal_sekolah']): ?>
              <div class="mt-sekolah"><?= htmlspecialchars($r['asal_sekolah']) ?></div>
              <?php endif; ?>
            </td>
            <td class="mt-nilai" style="color:<?= match($rank){1=>'#FFD700',2=>'#C0C0C0',3=>'#CD7F32',default=>'#fff'} ?>;">
              <?= $nilai > 0 ? number_format($nilai, 1) : '<span style="color:var(--muted)">—</span>' ?>
            </td>
          </tr>
          <?php endforeach; ?>
        </table>
        <?php endif; ?>
      </div>
    </div>

    <!-- ── Aktivitas Terbaru ── -->
    <div class="col-lg-4">
      <div class="glass-card h-100">
        <div class="card-hd">
          <div class="card-hd-icon" style="background:rgba(167,139,250,.13);">
            <i class="bi bi-activity" style="color:var(--purple);"></i>
          </div>
          <div>
            <div class="card-hd-title">Aktivitas Terbaru</div>
            <div class="card-hd-sub">10 input nilai terakhir</div>
          </div>
        </div>

        <?php if (empty($aktivitas)): ?>
        <div class="empty-row">
          <i class="bi bi-inbox" style="font-size:1.8rem;display:block;margin-bottom:.5rem;"></i>
          Belum ada aktivitas
        </div>
        <?php else: ?>
        <div style="max-height:320px;overflow-y:auto;">
          <?php foreach ($aktivitas as $a):
            $dotClass = $a['metode_input'] === 'scan' ? 'fd-scan' : 'fd-manual';
            $waktu    = date('H:i', strtotime($a['updated_at']));
          ?>
          <div class="feed-item">
            <div class="feed-dot <?= $dotClass ?>"></div>
            <div class="feed-text">
              <strong><?= htmlspecialchars($a['nama_regu']) ?></strong>
              — <?= htmlspecialchars($a['nama_gerakan']) ?>
              <span>· <?= number_format((float)$a['nilai_diperoleh'], 1) ?></span><br>
              <span><?= htmlspecialchars(($a['kode_juri'] ?: '') . ' ' . $a['nama_juri']) ?></span>
            </div>
            <div class="feed-time"><?= $waktu ?></div>
          </div>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>
      </div>
    </div>

    <!-- ── Quick Actions ── -->
    <div class="col-lg-3">
      <div class="glass-card h-100">
        <div class="card-hd">
          <div class="card-hd-icon" style="background:rgba(56,189,248,.12);">
            <i class="bi bi-grid-fill" style="color:var(--cyan);"></i>
          </div>
          <div>
            <div class="card-hd-title">Aksi Cepat</div>
            <div class="card-hd-sub">Menu utama</div>
          </div>
        </div>

        <div class="p-3 d-flex flex-column gap-2">
          <a href="rekap.php?id_event=<?= $idEvent ?>" class="qa-btn">
            <div class="qa-icon" style="background:rgba(56,189,248,.12);color:var(--cyan);">
              <i class="bi bi-pencil-square"></i>
            </div>
            <div>
              <div class="qa-label">Input Nilai</div>
              <div class="qa-desc">Form penilaian juri</div>
            </div>
            <i class="bi bi-chevron-right ms-auto" style="color:var(--muted);font-size:.75rem;"></i>
          </a>

          <a href="dashboard_klasemen.php?id_event=<?= $idEvent ?>" class="qa-btn">
            <div class="qa-icon" style="background:rgba(255,215,0,.1);color:#FFD700;">
              <i class="bi bi-trophy-fill"></i>
            </div>
            <div>
              <div class="qa-label">Live Klasemen</div>
              <div class="qa-desc">Tayangkan ke proyektor</div>
            </div>
            <i class="bi bi-chevron-right ms-auto" style="color:var(--muted);font-size:.75rem;"></i>
          </a>

          <a href="lihat_hasil.php?id_event=<?= $idEvent ?>" class="qa-btn">
            <div class="qa-icon" style="background:rgba(167,139,250,.12);color:var(--purple);">
              <i class="bi bi-table"></i>
            </div>
            <div>
              <div class="qa-label">Rekap Detail</div>
              <div class="qa-desc">Nilai per kriteria</div>
            </div>
            <i class="bi bi-chevron-right ms-auto" style="color:var(--muted);font-size:.75rem;"></i>
          </a>

          <a href="cetak_berita_acara.php?id_event=<?= $idEvent ?>" class="qa-btn">
            <div class="qa-icon" style="background:rgba(45,198,83,.1);color:var(--green);">
              <i class="bi bi-printer-fill"></i>
            </div>
            <div>
              <div class="qa-label">Cetak Hasil</div>
              <div class="qa-desc">Berita acara A4</div>
            </div>
            <i class="bi bi-chevron-right ms-auto" style="color:var(--muted);font-size:.75rem;"></i>
          </a>

          <a href="form_penalti.php?id_event=<?= $idEvent ?>" class="qa-btn">
            <div class="qa-icon" style="background:rgba(230,57,70,.1);color:var(--red);">
              <i class="bi bi-dash-circle-fill"></i>
            </div>
            <div>
              <div class="qa-label">Form Penalti</div>
              <div class="qa-desc">Input pelanggaran</div>
            </div>
            <i class="bi bi-chevron-right ms-auto" style="color:var(--muted);font-size:.75rem;"></i>
          </a>
        </div>
      </div>
    </div>

  </div><!-- /row konten bawah -->

</div>
</div><!-- /page -->

</body>
</html>
