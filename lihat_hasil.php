<?php
/**
 * lihat_hasil.php
 * ============================================================
 * Rekapitulasi & Klasemen Nilai — Paskibra SaaS
 * ============================================================
 * Menampilkan:
 *  - Klasemen total nilai per peserta (semua juri digabung / per juri)
 *  - Modal detail nilai per gerakan per kategori
 *  - Filter event & juri
 *  - Export CSV sederhana
 * ============================================================
 */

declare(strict_types=1);
require_once __DIR__ . '/koneksi.php';   // → $pdo

// ── Parameter filter ─────────────────────────────────────────
$idEvent = filter_input(INPUT_GET, 'id_event', FILTER_VALIDATE_INT) ?: 1;
$idJuri  = filter_input(INPUT_GET, 'id_juri',  FILTER_VALIDATE_INT) ?: 0;  // 0 = semua juri
$export  = filter_input(INPUT_GET, 'export',   FILTER_VALIDATE_INT) ?: 0;

// ── Query: daftar event ──────────────────────────────────────
$stmtEvents = $pdo->query("SELECT id_event, nama_event FROM tabel_event WHERE status_aktif=1 ORDER BY id_event DESC");
$events = $stmtEvents->fetchAll();

// ── Query: daftar juri pada event ini ────────────────────────
$stmtJuri = $pdo->prepare("SELECT id_juri, nama_juri, kode_juri FROM tabel_juri WHERE id_event=:ie ORDER BY kode_juri");
$stmtJuri->execute([':ie' => $idEvent]);
$daftarJuri = $stmtJuri->fetchAll();

// ── Query: nama event aktif ───────────────────────────────────
$stmtEv = $pdo->prepare("SELECT nama_event FROM tabel_event WHERE id_event=:ie LIMIT 1");
$stmtEv->execute([':ie' => $idEvent]);
$namaEvent = $stmtEv->fetchColumn() ?: 'Event #' . $idEvent;

// ── Query utama: rekap total nilai per peserta (+ per juri) ──
//
//   Jika $idJuri = 0  → SUM seluruh juri (rata-rata panel juri)
//   Jika $idJuri > 0  → SUM hanya juri tersebut
//
$juriFilter = $idJuri > 0 ? 'AND n.id_juri = :ij' : '';

$sqlRekap = "
    SELECT
        p.id_peserta,
        p.no_urut,
        p.nama_regu,
        p.asal_sekolah,
        COUNT(DISTINCT n.id_juri)                           AS jumlah_juri,
        COUNT(n.id_penilaian)                               AS jumlah_gerakan_dinilai,
        SUM(n.nilai_diperoleh)                              AS total_nilai,
        ROUND(AVG(n.nilai_diperoleh), 2)                    AS rata_per_gerakan,
        MAX(n.updated_at)                                   AS terakhir_update
    FROM tabel_peserta p
    LEFT JOIN tabel_penilaian n
           ON n.id_peserta = p.id_peserta {$juriFilter}
    WHERE p.id_event = :ie
    GROUP BY p.id_peserta, p.no_urut, p.nama_regu, p.asal_sekolah
    ORDER BY total_nilai DESC, p.no_urut ASC
";

$paramRekap = [':ie' => $idEvent];
if ($idJuri > 0) $paramRekap[':ij'] = $idJuri;

$stmtRekap = $pdo->prepare($sqlRekap);
$stmtRekap->execute($paramRekap);
$dataRekap = $stmtRekap->fetchAll();

// Hitung nilai maksimum teoritik (semua kriteria dapet nilai_max)
$stmtMax = $pdo->prepare(
    "SELECT SUM(kr.nilai_max) AS maks_teoritik
     FROM tabel_kriteria kr
     JOIN tabel_kategori kat ON kat.id_kategori = kr.id_kategori
     WHERE kat.id_event = :ie"
);
$stmtMax->execute([':ie' => $idEvent]);
$nilaiMaks = (float)($stmtMax->fetchColumn() ?: 0);

// ── Export CSV ────────────────────────────────────────────────
if ($export === 1) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="klasemen_paskibra_' . date('Ymd_His') . '.csv"');
    echo "\xEF\xBB\xBF"; // BOM untuk Excel
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Rank','No Urut','Nama Regu','Asal Sekolah','Total Nilai','Jml Gerakan Dinilai','Juri Menilai','Update Terakhir']);
    $rank = 0;
    foreach ($dataRekap as $row) {
        $rank++;
        fputcsv($out, [
            $rank,
            $row['no_urut'],
            $row['nama_regu'],
            $row['asal_sekolah'] ?? '-',
            $row['total_nilai'] ?? 0,
            $row['jumlah_gerakan_dinilai'],
            $row['jumlah_juri'],
            $row['terakhir_update'] ?? '-',
        ]);
    }
    fclose($out);
    exit;
}

// ── Query detail: nilai per gerakan (dipanggil via AJAX) ──────
if (isset($_GET['detail_peserta'])) {
    $dpId   = filter_input(INPUT_GET, 'detail_peserta', FILTER_VALIDATE_INT);
    $djId   = filter_input(INPUT_GET, 'detail_juri',    FILTER_VALIDATE_INT) ?: 0;
    if (!$dpId) { echo json_encode([]); exit; }

    $djFilter = $djId > 0 ? 'AND n.id_juri = :ij' : '';

    $sqlDetail = "
        SELECT
            kat.kode_kategori,
            kat.nama_kategori,
            kr.urutan         AS urutan_gerakan,
            kr.nama_gerakan,
            j.kode_juri,
            j.nama_juri,
            n.nilai_diperoleh,
            n.metode_input,
            n.updated_at
        FROM tabel_penilaian n
        JOIN tabel_kriteria  kr  ON kr.id_kriteria  = n.id_kriteria
        JOIN tabel_kategori  kat ON kat.id_kategori = kr.id_kategori
        JOIN tabel_juri      j   ON j.id_juri       = n.id_juri
        WHERE n.id_peserta = :ip
          AND kat.id_event = :ie
          {$djFilter}
        ORDER BY kat.urutan ASC, kr.urutan ASC, j.kode_juri ASC
    ";

    $paramDetail = [':ip' => $dpId, ':ie' => $idEvent];
    if ($djId > 0) $paramDetail[':ij'] = $djId;

    $stmtDetail = $pdo->prepare($sqlDetail);
    $stmtDetail->execute($paramDetail);
    $detail = $stmtDetail->fetchAll();

    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($detail, JSON_UNESCAPED_UNICODE);
    exit;
}

// ── Hitung statistik ringkas ──────────────────────────────────
$totalPeserta  = count($dataRekap);
$sudahDinilai  = count(array_filter($dataRekap, fn($r) => $r['total_nilai'] > 0));
$nilaiTertinggi = $totalPeserta > 0 ? ($dataRekap[0]['total_nilai'] ?? 0) : 0;

?><!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Klasemen — <?= htmlspecialchars($namaEvent) ?></title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
  <link href="https://fonts.googleapis.com/css2?family=Sora:wght@600;700;800&family=DM+Sans:wght@300;400;500&display=swap" rel="stylesheet">
  <style>
    :root {
      --navy:      #0d1b2e;
      --navy-mid:  #162640;
      --navy-card: #1c3050;
      --accent:    #f5a623;
      --cyan:      #38bdf8;
      --green:     #2dc653;
      --purple:    #a78bfa;
      --red:       #e63946;
      --text:      #dce8f5;
      --text-dim:  #7a94af;
      --border:    rgba(255,255,255,0.07);
    }
    * { box-sizing: border-box; }
    body {
      font-family: 'DM Sans', sans-serif;
      background: var(--navy);
      color: var(--text);
      min-height: 100vh;
    }
    body::before {
      content:''; position:fixed; inset:0; pointer-events:none; z-index:0;
      background: repeating-linear-gradient(-45deg,transparent,transparent 40px,
        rgba(255,255,255,0.015) 40px,rgba(255,255,255,0.015) 41px);
    }

    /* ── Navbar ── */
    .top-bar {
      background: var(--navy-mid);
      border-bottom: 1px solid var(--border);
      padding: .75rem 1.5rem;
      position: sticky; top:0; z-index:1000;
      backdrop-filter: blur(10px);
    }
    .brand { font-family:'Sora',sans-serif; font-weight:800; font-size:1.1rem; color:#fff; }
    .brand span { color: var(--accent); }
    .sub { font-size:.68rem; color:var(--text-dim); letter-spacing:.1em; text-transform:uppercase; margin-top:1px; }

    /* ── Layout ── */
    .page { position:relative; z-index:1; padding:2rem 0 4rem; }

    /* ── Stat cards ── */
    .stat-card {
      background: var(--navy-card);
      border: 1px solid var(--border);
      border-radius: 14px; padding: 1.1rem 1.4rem;
    }
    .stat-label { font-size:.7rem; text-transform:uppercase; letter-spacing:.1em; color:var(--text-dim); margin-bottom:4px; }
    .stat-val   { font-family:'Sora',sans-serif; font-size:1.8rem; font-weight:800; line-height:1; }
    .stat-sub   { font-size:.72rem; color:var(--text-dim); margin-top:3px; }

    /* ── Filter bar ── */
    .filter-bar {
      background: var(--navy-card);
      border: 1px solid var(--border);
      border-radius: 12px; padding: .85rem 1.1rem;
    }
    .filter-bar select, .filter-bar .form-select {
      background: #0f2034; color: var(--text);
      border: 1px solid rgba(255,255,255,0.12);
      font-size: .85rem; border-radius: 8px;
    }
    .filter-bar select:focus { border-color: var(--cyan); box-shadow: 0 0 0 3px rgba(56,189,248,.15); outline:none; }

    /* ── Table ── */
    .result-table-wrap {
      background: var(--navy-card);
      border: 1px solid var(--border);
      border-radius: 14px; overflow: hidden;
    }
    .result-table { width:100%; border-collapse:collapse; font-size:.85rem; }
    .result-table thead th {
      background: rgba(255,255,255,0.04);
      color: var(--text-dim); font-size:.68rem;
      font-weight:600; letter-spacing:.08em; text-transform:uppercase;
      padding: .75rem 1rem; border-bottom: 1px solid var(--border);
      white-space: nowrap;
    }
    .result-table tbody tr { border-bottom: 1px solid var(--border); transition: background .15s; }
    .result-table tbody tr:last-child { border-bottom: none; }
    .result-table tbody tr:hover { background: rgba(255,255,255,0.025); }
    .result-table td { padding: .85rem 1rem; vertical-align: middle; }

    /* Rank badge */
    .rank-badge {
      width:32px; height:32px; border-radius:50%;
      display:inline-flex; align-items:center; justify-content:center;
      font-family:'Sora',sans-serif; font-weight:800; font-size:.85rem;
    }
    .rank-1 { background:#FFD700; color:#0d1b2e; }
    .rank-2 { background:#C0C0C0; color:#0d1b2e; }
    .rank-3 { background:#CD7F32; color:#fff; }
    .rank-n { background:rgba(255,255,255,0.08); color:var(--text-dim); }

    /* Progress bar nilai */
    .bar-wrap { background:rgba(255,255,255,0.06); border-radius:100px; height:5px; width:100px; }
    .bar-fill  { height:100%; border-radius:100px; background:linear-gradient(90deg,var(--cyan),var(--purple)); }

    /* Nilai chip */
    .nilai-chip {
      font-family:'Sora',sans-serif; font-size:.95rem; font-weight:700;
      color:#fff;
    }
    .nilai-pct { font-size:.68rem; color:var(--text-dim); }

    /* Metode badge */
    .badge-scan   { background:rgba(56,189,248,.15); color:var(--cyan); font-size:.62rem; border:1px solid rgba(56,189,248,.3); }
    .badge-manual { background:rgba(245,166,35,.12); color:var(--accent); font-size:.62rem; border:1px solid rgba(245,166,35,.25); }

    /* Btn detail */
    .btn-detail {
      background: rgba(167,139,250,.12); border: 1px solid rgba(167,139,250,.3);
      color: var(--purple); font-size:.78rem; border-radius:8px;
      padding: 4px 12px; cursor:pointer; transition:all .15s; white-space:nowrap;
    }
    .btn-detail:hover { background:rgba(167,139,250,.22); border-color:var(--purple); }

    .btn-export {
      background: rgba(45,198,83,.1); border: 1px solid rgba(45,198,83,.3);
      color: var(--green); font-size:.82rem; border-radius:8px;
      padding:6px 16px; cursor:pointer; transition:all .15s;
      text-decoration:none; display:inline-flex; align-items:center; gap:6px;
    }
    .btn-export:hover { background:rgba(45,198,83,.2); color:var(--green); }

    .btn-filter-apply {
      background: var(--cyan); color:#0d1b2e;
      font-family:'Sora',sans-serif; font-weight:700; font-size:.82rem;
      border:none; border-radius:8px; padding:6px 18px; cursor:pointer;
      transition:all .15s;
    }
    .btn-filter-apply:hover { opacity:.88; }

    /* ── Modal detail ── */
    .modal-content { background:var(--navy-card); border:1px solid var(--border); border-radius:16px; color:var(--text); }
    .modal-header  { border-bottom:1px solid var(--border); padding:1.1rem 1.4rem; }
    .modal-footer  { border-top:1px solid var(--border); }
    .modal-title   { font-family:'Sora',sans-serif; font-weight:700; font-size:1.05rem; }

    /* Accordion kategori */
    .kat-header {
      background:rgba(255,255,255,0.04); padding:.55rem .9rem;
      font-size:.68rem; font-weight:700; letter-spacing:.09em;
      text-transform:uppercase; color:var(--text-dim);
      border-radius:6px; margin-bottom:4px; margin-top:10px;
      display:flex; justify-content:space-between; align-items:center;
    }
    .kat-total { color:var(--cyan); font-family:'Sora',sans-serif; font-size:.75rem; }

    .detail-row {
      display:grid; grid-template-columns:1fr auto auto auto;
      gap:8px; padding:.45rem .9rem; align-items:center;
      border-radius:6px; font-size:.82rem;
    }
    .detail-row:hover { background:rgba(255,255,255,.03); }
    .detail-gerakan { color:var(--text); }
    .detail-nilai {
      font-family:'Sora',sans-serif; font-weight:700; font-size:.88rem;
      color:#fff; min-width:36px; text-align:right;
    }
    .detail-juri { font-size:.7rem; color:var(--text-dim); min-width:40px; text-align:right; }

    /* Loading skeleton */
    .skeleton {
      background:linear-gradient(90deg,rgba(255,255,255,.04) 25%,rgba(255,255,255,.08) 50%,rgba(255,255,255,.04) 75%);
      background-size:200% 100%; animation:shimmer 1.4s infinite; border-radius:6px;
    }
    @keyframes shimmer { 0%{background-position:200% 0} 100%{background-position:-200% 0} }

    /* State box */
    .state-box { text-align:center; padding:3rem 1rem; color:var(--text-dim); }
    .state-box .icon { font-size:2.8rem; margin-bottom:.75rem; display:block; }

    ::-webkit-scrollbar { width:5px; }
    ::-webkit-scrollbar-track { background:transparent; }
    ::-webkit-scrollbar-thumb { background:rgba(255,255,255,.1); border-radius:3px; }
  </style>
</head>
<body>

<!-- ═══════ NAVBAR ═══════ -->
<nav class="top-bar">
  <div class="container-fluid px-2 d-flex align-items-center justify-content-between gap-3">
    <div class="d-flex align-items-center gap-3">
      <div style="width:38px;height:38px;background:var(--accent);border-radius:9px;
           display:flex;align-items:center;justify-content:center;flex-shrink:0;">
        <i class="bi bi-trophy-fill" style="color:var(--navy);font-size:1.1rem;"></i>
      </div>
      <div>
        <div class="brand">PASKIBRA <span>SAAS</span></div>
        <div class="sub">Rekap &amp; Klasemen Nilai</div>
      </div>
    </div>
    <div class="d-flex align-items-center gap-2">
      <a href="rekap.php" class="btn-detail" style="text-decoration:none;">
        <i class="bi bi-pencil-square me-1"></i>Input Nilai
      </a>
    </div>
  </div>
</nav>

<!-- ═══════ MAIN ═══════ -->
<div class="page">
<div class="container" style="max-width:980px;">

  <!-- Heading -->
  <div class="mb-4">
    <div style="font-size:.68rem;font-weight:600;letter-spacing:.12em;text-transform:uppercase;
         color:var(--accent);margin-bottom:.3rem;"><i class="bi bi-bar-chart-fill me-1"></i>Klasemen Sementara</div>
    <h1 style="font-family:'Sora',sans-serif;font-size:1.55rem;font-weight:800;color:#fff;margin:0;">
      <?= htmlspecialchars($namaEvent) ?>
    </h1>
  </div>

  <!-- ── Filter bar ── -->
  <form method="GET" class="filter-bar mb-3 d-flex flex-wrap gap-2 align-items-center">
    <input type="hidden" name="id_event" value="<?= (int)$idEvent ?>">

    <div class="d-flex align-items-center gap-2 flex-grow-1 flex-wrap">
      <!-- Event selector -->
      <div style="min-width:220px;">
        <select name="id_event" class="form-select form-select-sm" onchange="this.form.submit()">
          <?php foreach ($events as $ev): ?>
            <option value="<?= (int)$ev['id_event'] ?>"
              <?= $ev['id_event'] == $idEvent ? 'selected' : '' ?>>
              <?= htmlspecialchars($ev['nama_event']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>

      <!-- Juri selector -->
      <div style="min-width:180px;">
        <select name="id_juri" class="form-select form-select-sm">
          <option value="0" <?= $idJuri === 0 ? 'selected' : '' ?>>— Semua Juri —</option>
          <?php foreach ($daftarJuri as $j): ?>
            <option value="<?= (int)$j['id_juri'] ?>" <?= $j['id_juri'] == $idJuri ? 'selected' : '' ?>>
              <?= htmlspecialchars(($j['kode_juri'] ? '[' . $j['kode_juri'] . '] ' : '') . $j['nama_juri']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>

      <button type="submit" class="btn-filter-apply">
        <i class="bi bi-funnel-fill me-1"></i>Terapkan
      </button>
    </div>

    <!-- Export CSV -->
    <a href="?id_event=<?= $idEvent ?>&id_juri=<?= $idJuri ?>&export=1" class="btn-export" target="_blank">
      <i class="bi bi-filetype-csv"></i>Export CSV
    </a>
  </form>

  <!-- ── Stat cards ── -->
  <div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
      <div class="stat-card">
        <div class="stat-label"><i class="bi bi-people-fill me-1"></i>Total Peserta</div>
        <div class="stat-val" style="color:var(--cyan);"><?= $totalPeserta ?></div>
        <div class="stat-sub"><?= $sudahDinilai ?> sudah dinilai</div>
      </div>
    </div>
    <div class="col-6 col-md-3">
      <div class="stat-card">
        <div class="stat-label"><i class="bi bi-patch-check-fill me-1"></i>Nilai Tertinggi</div>
        <div class="stat-val" style="color:var(--accent);"><?= number_format((float)$nilaiTertinggi, 1) ?></div>
        <div class="stat-sub">dari <?= number_format($nilaiMaks, 0) ?> maks</div>
      </div>
    </div>
    <div class="col-6 col-md-3">
      <div class="stat-card">
        <div class="stat-label"><i class="bi bi-person-badge-fill me-1"></i>Dewan Juri</div>
        <div class="stat-val" style="color:var(--purple);"><?= count($daftarJuri) ?></div>
        <div class="stat-sub">
          <?= $idJuri > 0
            ? htmlspecialchars(array_column($daftarJuri,'nama_juri',  'id_juri')[$idJuri] ?? '-')
            : 'semua panel' ?>
        </div>
      </div>
    </div>
    <div class="col-6 col-md-3">
      <div class="stat-card">
        <div class="stat-label"><i class="bi bi-percent me-1"></i>Skor Maks Teoritik</div>
        <div class="stat-val" style="color:var(--green);"><?= number_format($nilaiMaks, 0) ?></div>
        <div class="stat-sub">total semua gerakan</div>
      </div>
    </div>
  </div>

  <!-- ── Tabel klasemen ── -->
  <div class="result-table-wrap">
    <?php if (empty($dataRekap)): ?>
      <div class="state-box">
        <span class="icon">📋</span>
        <p>Belum ada data penilaian untuk event ini.</p>
      </div>
    <?php else: ?>
    <table class="result-table">
      <thead>
        <tr>
          <th style="width:50px;">Rank</th>
          <th>Peserta / Regu</th>
          <th style="width:130px;">Total Nilai</th>
          <th style="width:110px;" class="d-none d-md-table-cell">Progres</th>
          <th style="width:90px;" class="d-none d-sm-table-cell">Dinilai</th>
          <th style="width:90px;" class="d-none d-lg-table-cell">Metode</th>
          <th style="width:80px;" class="d-none d-lg-table-cell">Juri</th>
          <th style="width:90px;">Detail</th>
        </tr>
      </thead>
      <tbody>
        <?php
        $rank = 0;
        foreach ($dataRekap as $row):
          $rank++;
          $total    = (float)($row['total_nilai'] ?? 0);
          $pct      = $nilaiMaks > 0 ? min(100, round($total / $nilaiMaks * 100)) : 0;
          $dinilai  = (int)$row['jumlah_gerakan_dinilai'];
          $jumlahJ  = (int)$row['jumlah_juri'];

          // Warna badge rank
          $rankClass = match($rank) { 1=>'rank-1', 2=>'rank-2', 3=>'rank-3', default=>'rank-n' };
          $rankIcon  = match($rank) { 1=>'🥇', 2=>'🥈', 3=>'🥉', default=>$rank };
        ?>
        <tr>
          <!-- Rank -->
          <td>
            <span class="rank-badge <?= $rankClass ?>">
              <?= $rank <= 3 ? $rankIcon : $rank ?>
            </span>
          </td>

          <!-- Peserta -->
          <td>
            <div style="font-weight:500;color:#fff;line-height:1.3;">
              <?= htmlspecialchars($row['nama_regu']) ?>
            </div>
            <?php if ($row['asal_sekolah']): ?>
            <div style="font-size:.72rem;color:var(--text-dim);">
              <i class="bi bi-building me-1"></i><?= htmlspecialchars($row['asal_sekolah']) ?>
            </div>
            <?php endif; ?>
            <div style="font-size:.68rem;color:var(--text-dim);margin-top:2px;">
              No. <?= (int)$row['no_urut'] ?>
            </div>
          </td>

          <!-- Total nilai -->
          <td>
            <div class="nilai-chip">
              <?= $total > 0 ? number_format($total, 1) : '<span style="color:var(--text-dim);">—</span>' ?>
            </div>
            <?php if ($total > 0 && $nilaiMaks > 0): ?>
            <div class="nilai-pct"><?= $pct ?>% dari maks</div>
            <?php endif; ?>
          </td>

          <!-- Progress bar -->
          <td class="d-none d-md-table-cell">
            <div class="bar-wrap">
              <div class="bar-fill" style="width:<?= $pct ?>%;"></div>
            </div>
            <div style="font-size:.62rem;color:var(--text-dim);margin-top:2px;"><?= $pct ?>%</div>
          </td>

          <!-- Gerakan dinilai -->
          <td class="d-none d-sm-table-cell">
            <?php if ($dinilai > 0): ?>
              <span style="font-size:.78rem;color:var(--green);">
                <i class="bi bi-check-circle-fill me-1"></i><?= $dinilai ?>
              </span>
            <?php else: ?>
              <span style="font-size:.78rem;color:var(--text-dim);">—</span>
            <?php endif; ?>
          </td>

          <!-- Metode (scan/manual — majority vote) -->
          <td class="d-none d-lg-table-cell">
            <?php if ($dinilai > 0): ?>
              <span class="badge rounded-pill badge-scan">AI Scan</span>
            <?php else: ?>
              <span style="font-size:.7rem;color:var(--text-dim);">—</span>
            <?php endif; ?>
          </td>

          <!-- Jumlah juri -->
          <td class="d-none d-lg-table-cell">
            <span style="font-size:.78rem;color:var(--purple);">
              <i class="bi bi-person-badge me-1"></i><?= $jumlahJ ?>
            </span>
          </td>

          <!-- Tombol detail -->
          <td>
            <button class="btn-detail"
              onclick="bukaDetail(<?= (int)$row['id_peserta'] ?>, '<?= htmlspecialchars(addslashes($row['nama_regu'])) ?>')">
              <i class="bi bi-list-ul me-1"></i>Detail
            </button>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>

    <!-- Caption bawah tabel -->
    <div style="padding:.65rem 1rem;font-size:.7rem;color:var(--text-dim);
         border-top:1px solid var(--border);display:flex;justify-content:space-between;flex-wrap:wrap;gap:4px;">
      <span><?= $totalPeserta ?> peserta · Diurutkan: Total Nilai tertinggi</span>
      <span>Data: <?= htmlspecialchars($idJuri > 0
        ? (array_column($daftarJuri,'nama_juri','id_juri')[$idJuri] ?? 'Juri #'.$idJuri)
        : 'Semua Juri') ?></span>
    </div>
    <?php endif; ?>
  </div>

</div>
</div><!-- /page -->

<!-- ═══════ MODAL DETAIL ═══════ -->
<div class="modal fade" id="modalDetail" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <div>
          <div class="modal-title" id="modalDetailTitle">Detail Penilaian</div>
          <div style="font-size:.72rem;color:var(--text-dim);" id="modalDetailSub">—</div>
        </div>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body" id="modalDetailBody" style="padding:.75rem 1rem;">
        <!-- diisi via JS -->
      </div>
      <div class="modal-footer" style="justify-content:space-between;flex-wrap:wrap;gap:8px;">
        <div id="modalDetailTotal" style="font-family:'Sora',sans-serif;font-weight:700;font-size:.95rem;color:var(--accent);"></div>
        <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Tutup</button>
      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
const ID_EVENT = <?= (int)$idEvent ?>;
const ID_JURI  = <?= (int)$idJuri ?>;
const modalEl  = document.getElementById('modalDetail');
const bsModal  = new bootstrap.Modal(modalEl);

function esc(s) {
  return String(s ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
}

async function bukaDetail(idPeserta, namaPeserta) {
  document.getElementById('modalDetailTitle').textContent = namaPeserta;
  document.getElementById('modalDetailSub').textContent   = 'Memuat detail…';
  document.getElementById('modalDetailBody').innerHTML    =
    '<div class="skeleton" style="height:180px;border-radius:8px;"></div>';
  document.getElementById('modalDetailTotal').textContent = '';
  bsModal.show();

  try {
    const juriParam = ID_JURI > 0 ? `&detail_juri=${ID_JURI}` : '';
    const res  = await fetch(`lihat_hasil.php?id_event=${ID_EVENT}&detail_peserta=${idPeserta}${juriParam}`);
    const data = await res.json();

    if (!data.length) {
      document.getElementById('modalDetailBody').innerHTML =
        '<div class="state-box"><span class="icon">📋</span><p>Belum ada nilai yang tersimpan untuk peserta ini.</p></div>';
      document.getElementById('modalDetailSub').textContent = 'Tidak ada data';
      return;
    }

    // Kelompokkan per kategori
    const grouped = {};
    const totalPerJuri = {};

    data.forEach(r => {
      const kat = r.kode_kategori + ' · ' + r.nama_kategori;
      if (!grouped[kat]) grouped[kat] = [];
      grouped[kat].push(r);

      // Akumulasi total per juri
      const key = r.kode_juri || r.nama_juri;
      totalPerJuri[key] = (totalPerJuri[key] || 0) + parseFloat(r.nilai_diperoleh || 0);
    });

    let html = '';
    let grandTotal = 0;

    Object.entries(grouped).forEach(([kat, rows]) => {
      const katTotal = rows.reduce((s, r) => s + parseFloat(r.nilai_diperoleh || 0), 0);
      grandTotal += katTotal;

      html += `<div class="kat-header">
                 <span>${esc(kat)}</span>
                 <span class="kat-total">${katTotal.toFixed(1)}</span>
               </div>`;

      rows.forEach(r => {
        const metodeClass = r.metode_input === 'scan' ? 'badge-scan' : 'badge-manual';
        const metodeLabel = r.metode_input === 'scan' ? 'AI' : 'Manual';
        html += `<div class="detail-row">
          <div class="detail-gerakan">${esc(r.nama_gerakan)}</div>
          <span class="badge rounded-pill ${metodeClass}">${metodeLabel}</span>
          <div class="detail-juri">${esc(r.kode_juri || r.nama_juri)}</div>
          <div class="detail-nilai">${parseFloat(r.nilai_diperoleh).toFixed(r.nilai_diperoleh % 1 !== 0 ? 1 : 0)}</div>
        </div>`;
      });
    });

    // Ringkasan per juri
    const juriKeys = Object.keys(totalPerJuri);
    if (juriKeys.length > 1) {
      html += `<div class="kat-header" style="margin-top:16px;background:rgba(167,139,250,.1);color:var(--purple);">
                 <span>Subtotal per Juri</span><span></span>
               </div>`;
      juriKeys.forEach(k => {
        html += `<div class="detail-row">
          <div class="detail-gerakan" style="color:var(--purple);">${esc(k)}</div>
          <span></span><span></span>
          <div class="detail-nilai" style="color:var(--purple);">${totalPerJuri[k].toFixed(1)}</div>
        </div>`;
      });
    }

    document.getElementById('modalDetailBody').innerHTML  = html;
    document.getElementById('modalDetailSub').textContent =
      `${data.length} gerakan dinilai`;
    document.getElementById('modalDetailTotal').innerHTML =
      `<i class="bi bi-sigma me-1"></i>Grand Total: ${grandTotal.toFixed(1)}`;

  } catch (err) {
    document.getElementById('modalDetailBody').innerHTML =
      `<div class="state-box"><span class="icon">⚠️</span><p>Gagal memuat detail: ${esc(err.message)}</p></div>`;
  }
}
</script>
</body>
</html>
