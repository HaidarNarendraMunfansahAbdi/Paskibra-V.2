<?php
/**
 * dashboard_klasemen.php
 * ============================================================
 * Live Leaderboard Proyektor — Paskibra SaaS
 * Dirancang untuk TV / Layar Besar
 * Auto-refresh setiap 10 detik
 * ============================================================
 */
declare(strict_types=1);
require_once __DIR__ . '/koneksi.php';   // → $pdo

// ── Parameter URL ────────────────────────────────────────────
$idEvent = filter_input(INPUT_GET, 'id_event', FILTER_VALIDATE_INT) ?: 1;
$refresh = filter_input(INPUT_GET, 'refresh',  FILTER_VALIDATE_INT) ?: 10;
$refresh = max(5, min(60, $refresh));

// ── Nama & tanggal event ─────────────────────────────────────
$stmtEv = $pdo->prepare(
    "SELECT nama_event, tgl_mulai, tgl_selesai
     FROM tabel_event WHERE id_event = :ie LIMIT 1"
);
$stmtEv->execute([':ie' => $idEvent]);
$event = $stmtEv->fetch() ?: ['nama_event' => 'Paskibra', 'tgl_mulai' => null, 'tgl_selesai' => null];

// ── Daftar event untuk switcher ──────────────────────────────
$events = $pdo->query(
    "SELECT id_event, nama_event FROM tabel_event WHERE status_aktif = 1 ORDER BY id_event DESC"
)->fetchAll();

// ── Nilai maksimum teoritik ──────────────────────────────────
$stmtMaks = $pdo->prepare(
    "SELECT COALESCE(SUM(kr.nilai_max), 0)
     FROM tabel_kriteria kr
     JOIN tabel_kategori kat ON kat.id_kategori = kr.id_kategori
     WHERE kat.id_event = :ie"
);
$stmtMaks->execute([':ie' => $idEvent]);
$nilaiMaks = (float)($stmtMaks->fetchColumn() ?: 0);

// ── Query klasemen ────────────────────────────────────────────
//
//   SUM semua nilai_diperoleh dari tabel_penilaian per peserta.
//   Sertakan penalti jika setup_penalti.sql sudah dijalankan:
//   ganti baris "total_nilai_akhir" dengan versi berpenalti.
//
$stmtK = $pdo->prepare("
    SELECT
        p.id_peserta,
        p.no_urut,
        p.nama_regu,
        p.asal_sekolah,
        COUNT(DISTINCT n.id_juri)               AS jumlah_juri,
        COALESCE(SUM(n.nilai_diperoleh), 0)      AS total_nilai,

        /* ── Aktifkan jika setup_penalti.sql sudah dijalankan ──
        COALESCE(ABS(SUM(nvp.total_minus)), 0)   AS total_penalti,
        COALESCE(SUM(n.nilai_diperoleh), 0)
          - COALESCE(ABS(SUM(nvp.total_minus)), 0) AS total_nilai_akhir,
        ── */

        COALESCE(SUM(n.nilai_diperoleh), 0)      AS total_nilai_akhir,
        MAX(n.updated_at)                        AS terakhir_update

    FROM tabel_peserta p
    LEFT JOIN tabel_penilaian n
           ON n.id_peserta = p.id_peserta

    /* ── LEFT JOIN tabel_nilai_penalti nvp ON nvp.id_peserta = p.id_peserta ── */

    WHERE p.id_event = :ie
    GROUP BY p.id_peserta, p.no_urut, p.nama_regu, p.asal_sekolah
    ORDER BY total_nilai_akhir DESC, p.no_urut ASC
");
$stmtK->execute([':ie' => $idEvent]);
$klasemen = $stmtK->fetchAll();

// ── Statistik ringkas ─────────────────────────────────────────
$totalPeserta  = count($klasemen);
$sudahDinilai  = count(array_filter($klasemen, fn($r) => $r['total_nilai_akhir'] > 0));
$nilaiTertinggi = $totalPeserta > 0 ? (float)($klasemen[0]['total_nilai_akhir'] ?? 0) : 0;
$waktuUpdate   = date('H:i:s');
?><!DOCTYPE html>
<html lang="id" data-bs-theme="dark">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <!-- Auto-refresh setiap N detik -->
  <meta http-equiv="refresh" content="<?= $refresh ?>">
  <title>Klasemen — <?= htmlspecialchars($event['nama_event']) ?></title>

  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
  <link href="https://fonts.googleapis.com/css2?family=Sora:wght@400;700;800;900&family=DM+Sans:wght@400;500&display=swap" rel="stylesheet">

  <style>
    /* ════════════════════════════════════════════════
       BASE
    ════════════════════════════════════════════════ */
    :root {
      --bg:         #050e1a;
      --bg-card:    #091422;
      --bg-row:     #0c1b2d;
      --border:     rgba(255,255,255,0.06);
      --text:       #dce8f5;
      --muted:      #4e6a87;

      --gold:       #FFD700;
      --gold-glow:  rgba(255,215,0,.35);
      --gold-bg:    rgba(255,215,0,.10);

      --silver:     #C8D6E5;
      --silver-glow: rgba(200,214,229,.25);
      --silver-bg:  rgba(200,214,229,.08);

      --bronze:     #E8956D;
      --bronze-glow: rgba(232,149,109,.25);
      --bronze-bg:  rgba(232,149,109,.09);

      --accent:     #f5a623;
      --cyan:       #38bdf8;
      --green:      #34d399;
      --red:        #e63946;
    }

    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

    html, body {
      height: 100%;
      overflow: hidden;          /* ← sembunyikan scrollbar */
    }

    body {
      font-family: 'DM Sans', sans-serif;
      background: var(--bg);
      color: var(--text);
      display: flex;
      flex-direction: column;
    }

    /* Background aksen halus */
    body::before {
      content: '';
      position: fixed; inset: 0; z-index: 0; pointer-events: none;
      background:
        radial-gradient(ellipse 80% 50% at 50% -10%, rgba(56,189,248,.06), transparent),
        radial-gradient(ellipse 60% 40% at 80% 110%, rgba(167,139,250,.05), transparent);
    }

    /* ════════════════════════════════════════════════
       HEADER
    ════════════════════════════════════════════════ */
    .ldb-header {
      position: relative; z-index: 10; flex-shrink: 0;
      padding: .9rem 2.5rem .8rem;
      background: linear-gradient(180deg, #081422 0%, #050e1a 100%);
      border-bottom: 1px solid var(--border);
      display: flex; align-items: center; justify-content: space-between; gap: 1.5rem;
    }

    .header-left { display: flex; align-items: center; gap: 1rem; }

    .trophy-icon {
      width: 52px; height: 52px; border-radius: 12px; flex-shrink: 0;
      background: linear-gradient(135deg, #b8860b, #FFD700);
      display: flex; align-items: center; justify-content: center;
      font-size: 1.5rem;
      box-shadow: 0 0 20px var(--gold-glow);
    }

    .event-eyebrow {
      font-size: .68rem; font-weight: 700; letter-spacing: .18em;
      text-transform: uppercase; color: var(--muted); margin-bottom: .15rem;
    }
    .event-title {
      font-family: 'Sora', sans-serif;
      font-size: clamp(1.1rem, 2.2vw, 1.65rem);
      font-weight: 900; color: #fff; line-height: 1.1; letter-spacing: -.01em;
    }
    .event-date { font-size: .72rem; color: var(--muted); margin-top: 3px; }

    /* Badges kanan header */
    .header-right { display: flex; align-items: center; gap: .9rem; flex-shrink: 0; }

    .live-pill {
      display: inline-flex; align-items: center; gap: 6px;
      background: rgba(230,57,70,.15); border: 1px solid rgba(230,57,70,.4);
      color: #ff7a84; border-radius: 100px;
      padding: 5px 14px; font-size: .72rem; font-weight: 800;
      letter-spacing: .1em; text-transform: uppercase;
    }
    .live-dot {
      width: 8px; height: 8px; border-radius: 50%;
      background: #ff7a84; box-shadow: 0 0 8px #ff7a84;
      animation: blink 1.2s ease-in-out infinite;
    }
    @keyframes blink { 0%,100%{opacity:1} 50%{opacity:.2} }

    .stat-pill {
      font-size: .72rem; color: var(--muted);
      display: flex; align-items: center; gap: 5px;
    }
    .stat-pill strong { color: var(--text); }

    .refresh-pill {
      font-size: .7rem; color: var(--muted);
      display: flex; align-items: center; gap: 5px;
    }
    .refresh-pill span { color: var(--cyan); font-weight: 700; }

    /* Event switcher */
    .ev-select {
      background: #0d1e35; color: #aac; border: 1px solid rgba(255,255,255,.12);
      border-radius: 7px; padding: 4px 10px; font-size: .75rem; cursor: pointer;
    }
    .ev-select:focus { outline: none; border-color: var(--cyan); }

    /* ════════════════════════════════════════════════
       COUNTDOWN BAR
    ════════════════════════════════════════════════ */
    .countdown-wrap {
      height: 3px; background: rgba(255,255,255,.05); flex-shrink: 0;
      position: relative; z-index: 11;
    }
    .countdown-fill {
      height: 100%;
      background: linear-gradient(90deg, var(--cyan), var(--gold), #a78bfa);
      animation: shrink <?= $refresh ?>s linear forwards;
      transform-origin: left;
    }
    @keyframes shrink { from{width:100%} to{width:0%} }

    /* ════════════════════════════════════════════════
       TABLE WRAPPER  — flex-grow isi sisa tinggi layar
    ════════════════════════════════════════════════ */
    .ldb-body {
      position: relative; z-index: 1;
      flex: 1; overflow: hidden;
      padding: 1.2rem 2rem 1rem;
      display: flex; flex-direction: column;
    }

    /* ════════════════════════════════════════════════
       LEADERBOARD TABLE
    ════════════════════════════════════════════════ */
    .ldb-table-wrap {
      flex: 1; overflow: hidden;
      display: flex; flex-direction: column;
    }

    table.ldb {
      width: 100%; border-collapse: separate; border-spacing: 0 .4vw;
      table-layout: fixed;
    }

    /* Header row */
    table.ldb thead th {
      padding: .35rem 1.2rem;
      font-size: clamp(.55rem, .8vw, .7rem);
      font-weight: 700; letter-spacing: .12em; text-transform: uppercase;
      color: var(--muted); border-bottom: 1px solid var(--border);
    }

    /* Data rows */
    table.ldb tbody tr { cursor: default; }

    table.ldb tbody td {
      padding: clamp(.55rem, 1.1vh, .95rem) 1.2rem;
      background: var(--bg-row);
      border-top: 1px solid var(--border);
      border-bottom: 1px solid var(--border);
      transition: background .2s;
    }
    table.ldb tbody td:first-child {
      border-left: 1px solid var(--border);
      border-radius: 10px 0 0 10px;
    }
    table.ldb tbody td:last-child {
      border-right: 1px solid var(--border);
      border-radius: 0 10px 10px 0;
    }

    /* ── Rank highlights ── */
    .row-1 td {
      background: var(--gold-bg) !important;
      border-color: var(--gold-glow) !important;
      box-shadow: inset 0 0 0 1px var(--gold-glow);
    }
    .row-1 td:first-child { border-left: 4px solid var(--gold) !important; }

    .row-2 td {
      background: var(--silver-bg) !important;
      border-color: var(--silver-glow) !important;
    }
    .row-2 td:first-child { border-left: 4px solid var(--silver) !important; }

    .row-3 td {
      background: var(--bronze-bg) !important;
      border-color: var(--bronze-glow) !important;
    }
    .row-3 td:first-child { border-left: 4px solid var(--bronze) !important; }

    /* ── Rank badge circle ── */
    .rank-circle {
      width: clamp(36px, 3.5vw, 52px);
      height: clamp(36px, 3.5vw, 52px);
      border-radius: 50%;
      display: inline-flex; align-items: center; justify-content: center;
      font-family: 'Sora', sans-serif; font-weight: 900;
      font-size: clamp(.9rem, 1.4vw, 1.3rem);
    }
    .rc-1 { background: linear-gradient(135deg,#b8860b,#FFD700); color: #1a0f00;
             box-shadow: 0 0 16px var(--gold-glow); }
    .rc-2 { background: linear-gradient(135deg,#7a8fa6,#C8D6E5); color: #0d1a27;
             box-shadow: 0 0 10px var(--silver-glow); }
    .rc-3 { background: linear-gradient(135deg,#8b5e3c,#E8956D); color: #1a0800;
             box-shadow: 0 0 10px var(--bronze-glow); }
    .rc-n { background: rgba(255,255,255,.07); color: var(--muted); }

    /* ── Column: No. peserta ── */
    .col-no {
      font-family: 'Sora', sans-serif; font-weight: 800;
      font-size: clamp(1rem, 1.6vw, 1.35rem);
      color: var(--cyan); text-align: center;
    }
    .row-1 .col-no { color: var(--gold); }
    .row-2 .col-no { color: var(--silver); }
    .row-3 .col-no { color: var(--bronze); }

    /* ── Column: Nama regu ── */
    .col-nama .regu {
      font-family: 'Sora', sans-serif; font-weight: 800;
      font-size: clamp(1rem, 1.8vw, 1.5rem);
      color: #fff; line-height: 1.2;
    }
    .row-1 .col-nama .regu { color: var(--gold); }
    .row-2 .col-nama .regu { color: var(--silver); }
    .row-3 .col-nama .regu { color: var(--bronze); }
    .col-nama .sekolah {
      font-size: clamp(.65rem, .9vw, .82rem);
      color: var(--muted); margin-top: 2px;
    }

    /* ── Column: Total nilai ── */
    .col-nilai {
      text-align: right;
    }
    .nilai-angka {
      font-family: 'Sora', sans-serif; font-weight: 900;
      font-size: clamp(1.3rem, 2.4vw, 2.1rem);
      color: #fff; white-space: nowrap;
    }
    .row-1 .nilai-angka { color: var(--gold); text-shadow: 0 0 20px var(--gold-glow); }
    .row-2 .nilai-angka { color: var(--silver); }
    .row-3 .nilai-angka { color: var(--bronze); }
    .nilai-empty { color: rgba(255,255,255,.18); font-size: 1.2rem; }

    /* Progress bar nilai */
    .bar-wrap {
      height: 4px; background: rgba(255,255,255,.06);
      border-radius: 100px; margin-top: 5px;
      width: 100%; min-width: 80px;
    }
    .bar-fill {
      height: 100%; border-radius: 100px;
      transition: width .6s ease;
    }
    .bf-1 { background: linear-gradient(90deg,#b8860b,var(--gold)); }
    .bf-2 { background: linear-gradient(90deg,#8098b0,var(--silver)); }
    .bf-3 { background: linear-gradient(90deg,#8b5e3c,var(--bronze)); }
    .bf-n { background: linear-gradient(90deg,var(--cyan),#a78bfa); }

    /* ── Juri badge ── */
    .juri-badge {
      font-size: clamp(.6rem, .8vw, .72rem);
      color: var(--muted);
      display: flex; align-items: center; gap: 3px;
      justify-content: center;
    }

    /* ── Column widths ── */
    .th-rank  { width: 7%; }
    .th-no    { width: 8%; }
    .th-nama  { /* auto */ }
    .th-juri  { width: 7%; }
    .th-nilai { width: 18%; }

    /* ════════════════════════════════════════════════
       EMPTY STATE
    ════════════════════════════════════════════════ */
    .empty {
      flex: 1; display: flex; flex-direction: column;
      align-items: center; justify-content: center;
      color: var(--muted); gap: 1rem;
    }
    .empty .ei { font-size: 4rem; }
    .empty p   { font-size: 1.05rem; }

    /* ════════════════════════════════════════════════
       FOOTER BAR
    ════════════════════════════════════════════════ */
    .ldb-footer {
      flex-shrink: 0; position: relative; z-index: 10;
      padding: .45rem 2rem;
      background: var(--bg-card);
      border-top: 1px solid var(--border);
      display: flex; align-items: center; justify-content: space-between;
      font-size: .7rem; color: var(--muted); flex-wrap: wrap; gap: .5rem;
    }
    .footer-brand {
      font-family: 'Sora', sans-serif; font-weight: 800; font-size: .82rem; color: #fff;
    }
    .footer-brand span { color: var(--accent); }
    .footer-links a {
      color: var(--muted); text-decoration: none; margin-left: 1rem;
      transition: color .15s;
    }
    .footer-links a:hover { color: var(--cyan); }

    /* ── Sembunyikan scrollbar di semua browser ── */
    ::-webkit-scrollbar { display: none; }
    * { scrollbar-width: none; -ms-overflow-style: none; }
  </style>
</head>
<body>

<!-- ═══ HEADER ═══ -->
<header class="ldb-header">
  <div class="header-left">
    <div class="trophy-icon">🏆</div>
    <div>
      <div class="event-eyebrow"><i class="bi bi-bar-chart-fill me-1"></i>Klasemen Live</div>
      <div class="event-title"><?= htmlspecialchars($event['nama_event']) ?></div>
      <?php if ($event['tgl_mulai']): ?>
      <div class="event-date">
        <i class="bi bi-calendar3 me-1"></i><?= date('d F Y', strtotime($event['tgl_mulai'])) ?>
      </div>
      <?php endif; ?>
    </div>
  </div>

  <div class="header-right">
    <!-- Statistik -->
    <div class="stat-pill">
      <i class="bi bi-people-fill" style="color:var(--cyan)"></i>
      <strong><?= $totalPeserta ?></strong> peserta
    </div>
    <div class="stat-pill">
      <i class="bi bi-check-circle-fill" style="color:var(--green)"></i>
      <strong><?= $sudahDinilai ?></strong> dinilai
    </div>

    <!-- Waktu update -->
    <div class="refresh-pill">
      <i class="bi bi-clock"></i>
      <span><?= $waktuUpdate ?></span>
    </div>

    <!-- LIVE badge -->
    <div class="live-pill"><span class="live-dot"></span>LIVE</div>

    <!-- Event switcher -->
    <?php if (count($events) > 1): ?>
    <form method="GET" style="margin:0;">
      <select class="ev-select" name="id_event" onchange="this.form.submit()">
        <?php foreach ($events as $ev): ?>
          <option value="<?= $ev['id_event'] ?>" <?= $ev['id_event'] == $idEvent ? 'selected' : '' ?>>
            <?= htmlspecialchars($ev['nama_event']) ?>
          </option>
        <?php endforeach; ?>
      </select>
      <input type="hidden" name="refresh" value="<?= $refresh ?>">
    </form>
    <?php endif; ?>
  </div>
</header>

<!-- ═══ COUNTDOWN BAR ═══ -->
<div class="countdown-wrap"><div class="countdown-fill"></div></div>

<!-- ═══ BODY ═══ -->
<div class="ldb-body">

  <?php if (empty($klasemen)): ?>
  <div class="empty">
    <span class="ei">🏆</span>
    <p>Klasemen akan tampil di sini setelah juri mulai menginput nilai.</p>
  </div>

  <?php else: ?>
  <div class="ldb-table-wrap">
    <table class="ldb">
      <thead>
        <tr>
          <th class="th-rank" style="text-align:center;">Rank</th>
          <th class="th-no"   style="text-align:center;">No.</th>
          <th class="th-nama">Nama Regu / Pangkalan</th>
          <th class="th-juri" style="text-align:center;">Juri</th>
          <th class="th-nilai" style="text-align:right;">Total Nilai</th>
        </tr>
      </thead>
      <tbody>
        <?php
        $rank = 0;
        foreach ($klasemen as $row):
          $rank++;
          $nilai    = (float)($row['total_nilai_akhir'] ?? 0);
          $pct      = $nilaiMaks > 0 ? min(100, round($nilai / $nilaiMaks * 100, 1)) : 0;
          $nJuri    = (int)$row['jumlah_juri'];

          [$trClass, $rcClass, $bfClass, $medal] = match($rank) {
            1 => ['row-1','rc-1','bf-1','🥇'],
            2 => ['row-2','rc-2','bf-2','🥈'],
            3 => ['row-3','rc-3','bf-3','🥉'],
            default => ['','rc-n','bf-n', $rank],
          };
        ?>
        <tr class="<?= $trClass ?>">

          <!-- Rank -->
          <td style="text-align:center;">
            <span class="rank-circle <?= $rcClass ?>">
              <?= $rank <= 3 ? $medal : $rank ?>
            </span>
          </td>

          <!-- No Urut -->
          <td class="col-no"><?= (int)$row['no_urut'] ?></td>

          <!-- Nama regu -->
          <td class="col-nama">
            <div class="regu"><?= htmlspecialchars($row['nama_regu']) ?></div>
            <?php if ($row['asal_sekolah']): ?>
            <div class="sekolah">
              <i class="bi bi-building" style="font-size:.7em;margin-right:3px;"></i><?= htmlspecialchars($row['asal_sekolah']) ?>
            </div>
            <?php endif; ?>
          </td>

          <!-- Jumlah juri -->
          <td>
            <?php if ($nJuri > 0): ?>
            <div class="juri-badge">
              <i class="bi bi-person-badge"></i>
              <span style="color:#a78bfa;font-weight:700;font-size:clamp(.75rem,1vw,.95rem);"><?= $nJuri ?></span>
            </div>
            <?php endif; ?>
          </td>

          <!-- Total nilai -->
          <td class="col-nilai">
            <?php if ($nilai > 0): ?>
              <div class="nilai-angka"><?= number_format($nilai, 1) ?></div>
              <?php if ($nilaiMaks > 0): ?>
              <div class="bar-wrap" title="<?= $pct ?>% dari nilai maks">
                <div class="bar-fill <?= $bfClass ?>" style="width:<?= $pct ?>%;"></div>
              </div>
              <?php endif; ?>
            <?php else: ?>
              <div class="nilai-angka nilai-empty">—</div>
            <?php endif; ?>
          </td>

        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>

</div><!-- /ldb-body -->

<!-- ═══ FOOTER ═══ -->
<footer class="ldb-footer">
  <div class="footer-brand">PASKIBRA <span>SAAS</span></div>

  <div style="display:flex;align-items:center;gap:.4rem;">
    <i class="bi bi-arrow-clockwise" style="color:var(--cyan);"></i>
    Auto-refresh:
    <strong style="color:var(--cyan);"><?= $refresh ?>s</strong>
  </div>

  <div class="footer-links">
    <a href="?id_event=<?= $idEvent ?>&refresh=5">5s</a>
    <a href="?id_event=<?= $idEvent ?>&refresh=10">10s</a>
    <a href="?id_event=<?= $idEvent ?>&refresh=30">30s</a>
    <a href="lihat_hasil.php?id_event=<?= $idEvent ?>">
      <i class="bi bi-table me-1"></i>Detail
    </a>
    <a href="rekap.php">
      <i class="bi bi-pencil-square me-1"></i>Input
    </a>
  </div>
</footer>

</body>
</html>
