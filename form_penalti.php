<?php
/**
 * form_penalti.php
 * ============================================================
 * Form Penalti Dinamis — Paskibra SaaS
 * ============================================================
 * Halaman ini menangani:
 *   GET  → tampilkan form (dropdown peserta + baris pelanggaran)
 *   POST → simpan / update penalti peserta ke DB
 *
 * Tabel yang diperlukan:
 *   tabel_master_penalti  — jenis pelanggaran per event
 *   tabel_nilai_penalti   — hasil input juri (qty × poin_minus)
 *
 * Jalankan setup_penalti.sql terlebih dahulu untuk membuat kedua tabel.
 * ============================================================
 */

declare(strict_types=1);
require_once __DIR__ . '/koneksi.php';   // → $pdo

// ── Parameter ────────────────────────────────────────────────
$idEvent = filter_input(INPUT_GET,  'id_event', FILTER_VALIDATE_INT)
         ?: filter_input(INPUT_POST, 'id_event', FILTER_VALIDATE_INT)
         ?: 1;

// ── Ambil nama event ─────────────────────────────────────────
$stmtEv = $pdo->prepare("SELECT nama_event FROM tabel_event WHERE id_event = :ie LIMIT 1");
$stmtEv->execute([':ie' => $idEvent]);
$namaEvent = $stmtEv->fetchColumn() ?: 'Event #' . $idEvent;

// ── Ambil daftar event (untuk switcher) ──────────────────────
$events = $pdo->query(
    "SELECT id_event, nama_event FROM tabel_event WHERE status_aktif = 1 ORDER BY id_event DESC"
)->fetchAll();

// ── Ambil daftar peserta ─────────────────────────────────────
$stmtP = $pdo->prepare(
    "SELECT id_peserta, no_urut, nama_regu, asal_sekolah
     FROM tabel_peserta WHERE id_event = :ie ORDER BY no_urut ASC"
);
$stmtP->execute([':ie' => $idEvent]);
$daftarPeserta = $stmtP->fetchAll();

// ── Ambil master penalti event ini ───────────────────────────
$stmtM = $pdo->prepare(
    "SELECT id_penalti, nama_pelanggaran, keterangan, poin_minus
     FROM tabel_master_penalti
     WHERE id_event = :ie AND aktif = 1
     ORDER BY urutan ASC, id_penalti ASC"
);
$stmtM->execute([':ie' => $idEvent]);
$masterPenalti = $stmtM->fetchAll();

// ── State variabel UI ─────────────────────────────────────────
$toastType    = '';
$toastMsg     = '';
$selectedPeserta = 0;
$existingQty  = [];   // [id_penalti => qty]

// ════════════════════════════════════════════════════════════════
//  HANDLE POST — SIMPAN PENALTI
// ════════════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['simpan_penalti'])) {

    $idPeserta = filter_input(INPUT_POST, 'id_peserta', FILTER_VALIDATE_INT);
    $qtyMap    = $_POST['qty'] ?? [];   // array: [id_penalti => qty]

    if (!$idPeserta) {
        $toastType = 'error';
        $toastMsg  = 'Pilih peserta terlebih dahulu.';
    } elseif (empty($masterPenalti)) {
        $toastType = 'error';
        $toastMsg  = 'Tidak ada jenis pelanggaran yang terdaftar untuk event ini.';
    } else {
        try {
            $pdo->beginTransaction();

            // Hapus semua entri lama peserta ini di event ini,
            // lalu insert ulang — cara paling bersih untuk revisi
            $stmtDel = $pdo->prepare(
                "DELETE nvp FROM tabel_nilai_penalti nvp
                 JOIN tabel_master_penalti mp ON mp.id_penalti = nvp.id_penalti
                 WHERE nvp.id_peserta = :ip AND mp.id_event = :ie"
            );
            $stmtDel->execute([':ip' => $idPeserta, ':ie' => $idEvent]);

            $stmtIns = $pdo->prepare(
                "INSERT INTO tabel_nilai_penalti (id_peserta, id_penalti, qty, total_minus)
                 VALUES (:ip, :ipen, :qty, :total)"
            );

            $totalMinus  = 0.0;
            $rowsInserted = 0;

            foreach ($masterPenalti as $pen) {
                $id  = (int) $pen['id_penalti'];
                $qty = max(0, (int)($qtyMap[$id] ?? 0));
                if ($qty === 0) continue;   // skip jika tidak ada pelanggaran

                $minus = $qty * abs((float)$pen['poin_minus']);
                $totalMinus += $minus;

                $stmtIns->execute([
                    ':ip'    => $idPeserta,
                    ':ipen'  => $id,
                    ':qty'   => $qty,
                    ':total' => -$minus,   // disimpan sebagai angka negatif
                ]);
                $rowsInserted++;
            }

            $pdo->commit();

            $namaRegu = '';
            foreach ($daftarPeserta as $p) {
                if ((int)$p['id_peserta'] === $idPeserta) {
                    $namaRegu = $p['nama_regu'];
                    break;
                }
            }

            $toastType = 'success';
            $toastMsg  = $rowsInserted > 0
                ? "✅ Penalti untuk <strong>{$namaRegu}</strong> berhasil disimpan. "
                  . "Total pengurangan: <strong>-{$totalMinus} poin</strong> ({$rowsInserted} jenis pelanggaran)."
                : "✅ Penalti untuk <strong>{$namaRegu}</strong> direset (tidak ada pelanggaran dicatat).";

            $selectedPeserta = $idPeserta;

        } catch (PDOException $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $toastType = 'error';
            $toastMsg  = 'Gagal menyimpan: ' . htmlspecialchars($e->getMessage());
        }
    }
}

// ── Load existing qty jika peserta sudah dipilih (GET atau POST) ─
$selectedPeserta = $selectedPeserta ?: (int)(
    filter_input(INPUT_GET,  'id_peserta', FILTER_VALIDATE_INT) ?:
    filter_input(INPUT_POST, 'id_peserta', FILTER_VALIDATE_INT) ?: 0
);

if ($selectedPeserta > 0) {
    $stmtExist = $pdo->prepare(
        "SELECT nvp.id_penalti, nvp.qty
         FROM tabel_nilai_penalti nvp
         JOIN tabel_master_penalti mp ON mp.id_penalti = nvp.id_penalti
         WHERE nvp.id_peserta = :ip AND mp.id_event = :ie"
    );
    $stmtExist->execute([':ip' => $selectedPeserta, ':ie' => $idEvent]);
    foreach ($stmtExist->fetchAll() as $row) {
        $existingQty[(int)$row['id_penalti']] = (int)$row['qty'];
    }
}

?><!DOCTYPE html>
<html lang="id" data-bs-theme="dark">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Form Penalti — <?= htmlspecialchars($namaEvent) ?></title>

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
      --text-dim:  #7a94af;
      --accent:    #f5a623;
      --cyan:      #38bdf8;
      --green:     #2dc653;
      --red:       #e63946;
      --purple:    #a78bfa;
    }

    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

    body {
      font-family: 'DM Sans', sans-serif;
      background: var(--navy); color: var(--text); min-height: 100vh;
    }
    body::before {
      content: ''; position: fixed; inset: 0; pointer-events: none; z-index: 0;
      background: repeating-linear-gradient(-45deg, transparent, transparent 40px,
        rgba(255,255,255,0.015) 40px, rgba(255,255,255,0.015) 41px);
    }

    /* ── Navbar ── */
    .top-bar {
      background: var(--navy-mid); border-bottom: 1px solid var(--border);
      padding: .8rem 1.5rem; position: sticky; top: 0; z-index: 1000;
      backdrop-filter: blur(10px);
    }
    .brand { font-family: 'Sora', sans-serif; font-weight: 800; font-size: 1.05rem; color: #fff; }
    .brand span { color: var(--accent); }
    .sub-brand { font-size: .67rem; color: var(--text-dim); letter-spacing: .1em; text-transform: uppercase; }

    /* ── Layout ── */
    .page { position: relative; z-index: 1; padding: 2rem 0 5rem; }

    /* ── Glass card ── */
    .glass-card {
      background: var(--navy-card); border: 1px solid var(--border);
      border-radius: 14px; overflow: hidden;
      box-shadow: 0 8px 32px rgba(0,0,0,.4);
    }
    .card-hd {
      padding: .9rem 1.25rem; border-bottom: 1px solid var(--border);
      display: flex; align-items: center; gap: 10px;
    }
    .card-icon {
      width: 34px; height: 34px; border-radius: 8px; flex-shrink: 0;
      display: flex; align-items: center; justify-content: center;
      font-size: 1rem;
    }
    .card-title  { font-family: 'Sora', sans-serif; font-size: .95rem; font-weight: 700; color: #fff; }
    .card-sub    { font-size: .72rem; color: var(--text-dim); }

    /* ── Select dark ── */
    .sel-dark {
      background: #0f2034; color: var(--text);
      border: 1px solid rgba(255,255,255,.13);
      border-radius: 10px; padding: .6rem .9rem; width: 100%;
      font-size: .92rem; font-family: 'DM Sans', sans-serif;
      transition: border-color .15s;
    }
    .sel-dark:focus { border-color: var(--cyan); outline: none;
      box-shadow: 0 0 0 3px rgba(56,189,248,.15); }

    /* ── Penalti rows ── */
    .penalti-table { width: 100%; border-collapse: collapse; }
    .penalti-table th {
      padding: .55rem 1rem; font-size: .65rem; font-weight: 700;
      letter-spacing: .1em; text-transform: uppercase; color: var(--text-dim);
      border-bottom: 1px solid var(--border); background: rgba(255,255,255,.025);
    }
    .penalti-row { border-bottom: 1px solid var(--border); transition: background .15s; }
    .penalti-row:last-child { border-bottom: none; }
    .penalti-row:hover { background: rgba(255,255,255,.025); }
    .penalti-row td { padding: .8rem 1rem; vertical-align: middle; }

    .pen-nama { font-size: .88rem; color: var(--text); font-weight: 500; }
    .pen-ket  { font-size: .72rem; color: var(--text-dim); margin-top: 2px; }

    .poin-badge {
      display: inline-flex; align-items: center; gap: 4px;
      background: rgba(230,57,70,.12); border: 1px solid rgba(230,57,70,.3);
      color: #ff8a93; border-radius: 7px; padding: 3px 9px;
      font-family: 'Sora', sans-serif; font-weight: 700; font-size: .82rem;
      white-space: nowrap;
    }

    /* qty input */
    .qty-input {
      background: #0f2034; color: #fff; text-align: center;
      border: 1px solid rgba(255,255,255,.13); border-radius: 8px;
      padding: .4rem .5rem; width: 72px; font-size: .95rem;
      font-family: 'Sora', sans-serif; font-weight: 700;
      transition: border-color .15s;
    }
    .qty-input:focus { border-color: var(--cyan); outline: none;
      box-shadow: 0 0 0 3px rgba(56,189,248,.15); }
    .qty-input::-webkit-inner-spin-button { opacity: .4; }

    /* Subtotal chip */
    .subtotal-chip {
      display: inline-flex; align-items: center; gap: 5px;
      font-family: 'Sora', sans-serif; font-size: .82rem;
      font-weight: 700; min-width: 110px; transition: all .2s;
    }
    .subtotal-zero  { color: var(--text-dim); }
    .subtotal-minus { color: #ff8a93; }

    /* ── Summary footer ── */
    .summary-bar {
      background: rgba(230,57,70,.08); border-top: 1px solid rgba(230,57,70,.2);
      padding: .85rem 1.25rem;
      display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 10px;
    }
    .total-label { font-size: .78rem; color: var(--text-dim); }
    .total-val {
      font-family: 'Sora', sans-serif; font-size: 1.6rem; font-weight: 800;
      color: #ff8a93;
    }
    .total-val.zero { color: var(--text-dim); }

    /* ── Buttons ── */
    .btn-save {
      background: var(--accent); border: none; color: var(--navy);
      font-family: 'Sora', sans-serif; font-weight: 700; font-size: .92rem;
      padding: .65rem 1.8rem; border-radius: 10px; cursor: pointer;
      display: inline-flex; align-items: center; gap: 8px; transition: all .2s;
    }
    .btn-save:hover:not(:disabled) { background: #c47d10; transform: translateY(-1px); }
    .btn-save:disabled { opacity: .45; cursor: not-allowed; }

    .btn-ghost {
      background: transparent; border: 1px solid rgba(255,255,255,.12);
      color: var(--text-dim); font-family: 'DM Sans', sans-serif;
      font-size: .88rem; padding: .6rem 1.2rem; border-radius: 10px;
      cursor: pointer; display: inline-flex; align-items: center; gap: 6px;
      transition: all .2s; text-decoration: none;
    }
    .btn-ghost:hover { border-color: var(--text-dim); color: var(--text); }

    /* ── Toast ── */
    #toast-wrap {
      position: fixed; bottom: 1.5rem; right: 1.5rem; z-index: 9999;
      display: flex; flex-direction: column; gap: 10px; max-width: 400px;
    }
    .toast-item {
      padding: .85rem 1.1rem; border-radius: 12px; font-size: .84rem;
      border-left: 4px solid; display: flex; align-items: flex-start; gap: 10px;
      box-shadow: 0 8px 24px rgba(0,0,0,.5);
      animation: t-in .3s cubic-bezier(.34,1.56,.64,1);
    }
    .toast-success { background: #0d2b1a; border-color: var(--green); color: #a8f0be; }
    .toast-error   { background: #2b0d0d; border-color: var(--red);   color: #f5a8a8; }
    .toast-close { margin-left: auto; background: none; border: none; color: inherit;
      opacity: .55; cursor: pointer; font-size: .9rem; padding: 0; }
    .toast-close:hover { opacity: 1; }
    @keyframes t-in { from{opacity:0;transform:translateX(30px)} to{opacity:1;transform:translateX(0)} }

    /* ── State box ── */
    .state-box { text-align: center; padding: 3rem 1rem; color: var(--text-dim); }
    .state-box .si { font-size: 2.5rem; margin-bottom: .75rem; display: block; }

    /* ── Existing badge ── */
    .has-data { border-left: 3px solid var(--red) !important; }

    ::-webkit-scrollbar { width: 5px; }
    ::-webkit-scrollbar-thumb { background: rgba(255,255,255,.1); border-radius: 3px; }
  </style>
</head>
<body>

<!-- ═══ NAVBAR ═══ -->
<nav class="top-bar">
  <div class="container-fluid px-2 d-flex align-items-center justify-content-between gap-3">
    <div class="d-flex align-items-center gap-3">
      <div style="width:36px;height:36px;background:var(--red);border-radius:9px;
           display:flex;align-items:center;justify-content:center;flex-shrink:0;">
        <i class="bi bi-dash-circle-fill" style="color:#fff;font-size:1rem;"></i>
      </div>
      <div>
        <div class="brand">PASKIBRA <span>SAAS</span></div>
        <div class="sub-brand">Form Penalti</div>
      </div>
    </div>
    <div class="d-flex gap-2 flex-wrap">
      <a href="rekap.html"           class="btn-ghost"><i class="bi bi-pencil-square"></i> Input Nilai</a>
      <a href="lihat_hasil.php"      class="btn-ghost"><i class="bi bi-table"></i> Detail</a>
      <a href="dashboard_klasemen.php" class="btn-ghost"><i class="bi bi-trophy"></i> Klasemen</a>
    </div>
  </div>
</nav>

<!-- ═══ MAIN ═══ -->
<div class="page">
<div class="container" style="max-width:860px;">

  <!-- Heading -->
  <div class="mb-4">
    <div style="font-size:.68rem;font-weight:600;letter-spacing:.12em;text-transform:uppercase;
         color:var(--red);margin-bottom:.35rem;">
      <i class="bi bi-dash-circle-fill me-1"></i>Pengurangan Nilai
    </div>
    <h1 style="font-family:'Sora',sans-serif;font-size:1.5rem;font-weight:800;color:#fff;margin:0;">
      Form Penalti
    </h1>
    <p style="font-size:.82rem;color:var(--text-dim);margin-top:4px;">
      <?= htmlspecialchars($namaEvent) ?>
    </p>
  </div>

  <?php if (empty($masterPenalti)): ?>
  <!-- Tidak ada master penalti -->
  <div class="glass-card">
    <div class="state-box">
      <span class="si">⚠️</span>
      <p><strong>Belum ada jenis pelanggaran terdaftar</strong> untuk event ini.</p>
      <p style="margin-top:.5rem;font-size:.78rem;">
        Tambahkan data ke <code>tabel_master_penalti</code> dengan <code>id_event = <?= $idEvent ?></code>.
      </p>
    </div>
  </div>

  <?php else: ?>

  <form method="POST" id="form-penalti">
    <input type="hidden" name="id_event" value="<?= $idEvent ?>">
    <input type="hidden" name="simpan_penalti" value="1">

    <!-- ╔══════════════════════════════╗
         ║  CARD 1 — PILIH PESERTA     ║
         ╚══════════════════════════════╝ -->
    <div class="glass-card mb-3">
      <div class="card-hd">
        <div class="card-icon" style="background:rgba(56,189,248,.15);color:var(--cyan);">
          <i class="bi bi-people-fill"></i>
        </div>
        <div>
          <div class="card-title">Peserta</div>
          <div class="card-sub">Pilih regu yang akan dicatat pelanggarannya</div>
        </div>
      </div>
      <div class="p-3">
        <select name="id_peserta" id="sel-peserta" class="sel-dark" required
          onchange="loadExistingData(this.value)">
          <option value="">— Pilih Regu —</option>
          <?php foreach ($daftarPeserta as $p):
            $sel = (int)$p['id_peserta'] === $selectedPeserta ? 'selected' : '';
          ?>
          <option value="<?= $p['id_peserta'] ?>" <?= $sel ?>>
            <?= $p['no_urut'] ?>. <?= htmlspecialchars($p['nama_regu']) ?>
            <?= $p['asal_sekolah'] ? ' — ' . htmlspecialchars($p['asal_sekolah']) : '' ?>
          </option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>

    <!-- ╔══════════════════════════════╗
         ║  CARD 2 — TABEL PELANGGARAN ║
         ╚══════════════════════════════╝ -->
    <div class="glass-card mb-3">
      <div class="card-hd">
        <div class="card-icon" style="background:rgba(230,57,70,.15);color:var(--red);">
          <i class="bi bi-exclamation-triangle-fill"></i>
        </div>
        <div>
          <div class="card-title">Jenis Pelanggaran</div>
          <div class="card-sub">Isi jumlah kejadian (Qty) pada setiap baris yang relevan</div>
        </div>
      </div>

      <table class="penalti-table">
        <thead>
          <tr>
            <th style="width:40%;">Pelanggaran</th>
            <th style="width:110px;text-align:center;">Poin / Kejadian</th>
            <th style="width:90px;text-align:center;">Qty</th>
            <th style="text-align:right;">Subtotal</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($masterPenalti as $pen):
            $idPen   = (int)$pen['id_penalti'];
            $poin    = abs((float)$pen['poin_minus']);
            $initQty = $existingQty[$idPen] ?? 0;
            $rowClass = $initQty > 0 ? 'penalti-row has-data' : 'penalti-row';
          ?>
          <tr class="<?= $rowClass ?>" id="row-<?= $idPen ?>">

            <!-- Nama + keterangan -->
            <td>
              <div class="pen-nama"><?= htmlspecialchars($pen['nama_pelanggaran']) ?></div>
              <?php if ($pen['keterangan']): ?>
              <div class="pen-ket"><?= htmlspecialchars($pen['keterangan']) ?></div>
              <?php endif; ?>
            </td>

            <!-- Poin per kejadian -->
            <td style="text-align:center;">
              <span class="poin-badge">
                <i class="bi bi-dash-circle"></i><?= number_format($poin, 0) ?> poin
              </span>
            </td>

            <!-- Input qty -->
            <td style="text-align:center;">
              <input type="number"
                class="qty-input"
                name="qty[<?= $idPen ?>]"
                id="qty-<?= $idPen ?>"
                min="0" max="99" step="1"
                value="<?= $initQty ?>"
                data-poin="<?= $poin ?>"
                data-id="<?= $idPen ?>"
                oninput="hitungSubtotal(this)"
                autocomplete="off">
            </td>

            <!-- Subtotal -->
            <td style="text-align:right;">
              <span class="subtotal-chip <?= $initQty > 0 ? 'subtotal-minus' : 'subtotal-zero' ?>"
                id="sub-<?= $idPen ?>">
                <?php if ($initQty > 0): ?>
                  <?= $initQty ?> × <?= $poin ?> = <strong>-<?= $initQty * $poin ?></strong>
                <?php else: ?>
                  —
                <?php endif; ?>
              </span>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>

      <!-- Summary bar -->
      <div class="summary-bar">
        <div>
          <div class="total-label">Total Pengurangan</div>
          <div class="total-val zero" id="grand-total">0 poin</div>
        </div>
        <div class="d-flex gap-2 flex-wrap">
          <button type="button" class="btn-ghost" onclick="resetQty()">
            <i class="bi bi-arrow-counterclockwise"></i>Reset
          </button>
          <button type="submit" class="btn-save" id="btn-save">
            <i class="bi bi-shield-minus"></i>Simpan Penalti
          </button>
        </div>
      </div>
    </div>

    <!-- Ringkasan per jenis (hanya tampil jika ada pelanggaran) -->
    <div id="ringkasan-wrap" style="display:none;" class="glass-card mb-3">
      <div class="card-hd">
        <div class="card-icon" style="background:rgba(245,166,35,.12);color:var(--accent);">
          <i class="bi bi-list-check"></i>
        </div>
        <div>
          <div class="card-title">Ringkasan Pelanggaran</div>
          <div class="card-sub">Yang akan dicatat saat tombol Simpan ditekan</div>
        </div>
      </div>
      <div class="p-3" id="ringkasan-body" style="font-size:.84rem;"></div>
    </div>

  </form>
  <?php endif; ?>

</div>
</div><!-- /page -->

<!-- Toast container -->
<div id="toast-wrap"></div>

<?php
// Data master penalti untuk JS (json_encode di sini aman karena server-side)
$masterJs = array_map(fn($p) => [
    'id'    => (int)  $p['id_penalti'],
    'nama'  =>        $p['nama_pelanggaran'],
    'poin'  => abs((float)$p['poin_minus']),
], $masterPenalti);
?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
// ── Data master dari PHP ──────────────────────────────────────
const MASTER = <?= json_encode($masterJs, JSON_UNESCAPED_UNICODE) ?>;
const ID_EVENT = <?= $idEvent ?>;

// ── Hitung subtotal satu baris ────────────────────────────────
function hitungSubtotal(input) {
  const id   = input.dataset.id;
  const poin = parseFloat(input.dataset.poin);
  const qty  = Math.max(0, parseInt(input.value) || 0);
  input.value = qty;

  const chip = document.getElementById('sub-' + id);
  const row  = document.getElementById('row-' + id);

  if (qty > 0) {
    chip.className   = 'subtotal-chip subtotal-minus';
    chip.innerHTML   = `${qty} × ${poin} = <strong>-${qty * poin}</strong>`;
    row.classList.add('has-data');
  } else {
    chip.className   = 'subtotal-chip subtotal-zero';
    chip.innerHTML   = '—';
    row.classList.remove('has-data');
  }

  hitungGrandTotal();
  updateRingkasan();
}

// ── Hitung grand total semua baris ───────────────────────────
function hitungGrandTotal() {
  let total = 0;
  document.querySelectorAll('.qty-input').forEach(inp => {
    const qty  = Math.max(0, parseInt(inp.value) || 0);
    const poin = parseFloat(inp.dataset.poin);
    total += qty * poin;
  });

  const el = document.getElementById('grand-total');
  if (total > 0) {
    el.textContent = `-${total} poin`;
    el.classList.remove('zero');
  } else {
    el.textContent = '0 poin';
    el.classList.add('zero');
  }
}

// ── Update ringkasan ──────────────────────────────────────────
function updateRingkasan() {
  const aktif = [];
  document.querySelectorAll('.qty-input').forEach(inp => {
    const qty = parseInt(inp.value) || 0;
    if (qty > 0) {
      const id   = parseInt(inp.dataset.id);
      const poin = parseFloat(inp.dataset.poin);
      const m    = MASTER.find(x => x.id === id);
      if (m) aktif.push({ nama: m.nama, qty, poin, total: qty * poin });
    }
  });

  const wrap = document.getElementById('ringkasan-wrap');
  const body = document.getElementById('ringkasan-body');

  if (aktif.length === 0) {
    wrap.style.display = 'none';
    return;
  }
  wrap.style.display = '';

  let grandT = 0;
  let html = '<table style="width:100%;border-collapse:collapse;">';
  aktif.forEach(a => {
    grandT += a.total;
    html += `
      <tr style="border-bottom:1px solid rgba(255,255,255,.06);">
        <td style="padding:.45rem 0;color:var(--text);">${escHtml(a.nama)}</td>
        <td style="text-align:center;color:var(--text-dim);font-size:.78rem;">${a.qty} × ${a.poin}</td>
        <td style="text-align:right;font-family:'Sora',sans-serif;font-weight:700;color:#ff8a93;">-${a.total}</td>
      </tr>`;
  });
  html += `
    <tr>
      <td colspan="2" style="padding:.55rem 0;font-size:.78rem;color:var(--text-dim);">TOTAL</td>
      <td style="text-align:right;font-family:'Sora',sans-serif;font-weight:800;font-size:1.05rem;color:#ff8a93;">-${grandT}</td>
    </tr>`;
  html += '</table>';
  body.innerHTML = html;
}

// ── Reset semua qty ke 0 ──────────────────────────────────────
function resetQty() {
  if (!confirm('Reset semua qty pelanggaran ke 0?')) return;
  document.querySelectorAll('.qty-input').forEach(inp => {
    inp.value = 0;
    hitungSubtotal(inp);
  });
}

// ── Load data existing via fetch (saat dropdown berubah) ──────
function loadExistingData(idPeserta) {
  if (!idPeserta) {
    resetQty();
    return;
  }

  fetch(`form_penalti.php?id_event=${ID_EVENT}&id_peserta=${idPeserta}&ajax=1`)
    .then(r => r.json())
    .then(data => {
      // Reset dulu
      document.querySelectorAll('.qty-input').forEach(inp => { inp.value = 0; });

      // Isi dari server
      data.forEach(row => {
        const inp = document.getElementById('qty-' + row.id_penalti);
        if (inp) inp.value = row.qty;
      });

      // Recalculate semua
      document.querySelectorAll('.qty-input').forEach(inp => hitungSubtotal(inp));
    })
    .catch(() => {}); // silent fail — data bisa diisi manual
}

// ── Helper escHtml ────────────────────────────────────────────
function escHtml(s) {
  return String(s ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
}

// ── Toast ─────────────────────────────────────────────────────
function showToast(type, msg, dur = 7000) {
  const icons = { success: 'bi-check-circle-fill', error: 'bi-exclamation-triangle-fill' };
  const el    = document.createElement('div');
  el.className = `toast-item toast-${type}`;
  el.innerHTML = `<i class="bi ${icons[type]} flex-shrink-0"></i>
    <span>${msg}</span>
    <button class="toast-close" onclick="this.parentElement.remove()">
      <i class="bi bi-x"></i>
    </button>`;
  document.getElementById('toast-wrap').appendChild(el);
  if (dur > 0) setTimeout(() => el.remove(), dur);
}

// ── Init ──────────────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', () => {
  hitungGrandTotal();
  updateRingkasan();

  <?php if ($toastType): ?>
  showToast('<?= $toastType ?>', <?= json_encode($toastMsg) ?>);
  <?php endif; ?>
});

// ── AJAX handler: kembalikan existing qty jika dipanggil dengan ?ajax=1 ──
</script>

<?php
// Handle AJAX request untuk load existing penalti peserta
if (isset($_GET['ajax']) && $_GET['ajax'] === '1' && isset($_GET['id_peserta'])) {
    $ajaxPeserta = filter_input(INPUT_GET, 'id_peserta', FILTER_VALIDATE_INT);
    if ($ajaxPeserta) {
        $stmtAjax = $pdo->prepare(
            "SELECT nvp.id_penalti, nvp.qty
             FROM tabel_nilai_penalti nvp
             JOIN tabel_master_penalti mp ON mp.id_penalti = nvp.id_penalti
             WHERE nvp.id_peserta = :ip AND mp.id_event = :ie"
        );
        $stmtAjax->execute([':ip' => $ajaxPeserta, ':ie' => $idEvent]);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($stmtAjax->fetchAll(), JSON_UNESCAPED_UNICODE);
        exit;
    }
}
?>
</body>
</html>
