<?php
/**
 * cetak_berita_acara.php
 * ============================================================
 * Berita Acara Hasil Keputusan Dewan Juri — Paskibra SaaS
 * Dokumen print-ready A4, latar putih, siap tanda tangan
 * ============================================================
 */
declare(strict_types=1);
require_once __DIR__ . '/koneksi.php';   // → $pdo

// ── Parameter ─────────────────────────────────────────────────
$idEvent = filter_input(INPUT_GET, 'id_event', FILTER_VALIDATE_INT) ?: 1;

// ── Data event ────────────────────────────────────────────────
$stmtEv = $pdo->prepare(
    "SELECT nama_event, tgl_mulai, tgl_selesai
     FROM tabel_event WHERE id_event = :ie LIMIT 1"
);
$stmtEv->execute([':ie' => $idEvent]);
$event = $stmtEv->fetch() ?: ['nama_event'=>'—','tgl_mulai'=>null,'tgl_selesai'=>null];

// ── Daftar juri (untuk blok tanda tangan) ────────────────────
$stmtJ = $pdo->prepare(
    "SELECT id_juri, nama_juri, kode_juri
     FROM tabel_juri WHERE id_event = :ie ORDER BY kode_juri ASC"
);
$stmtJ->execute([':ie' => $idEvent]);
$daftarJuri = $stmtJ->fetchAll();

// ── Nilai maks teoritik ───────────────────────────────────────
$stmtMaks = $pdo->prepare(
    "SELECT COALESCE(SUM(kr.nilai_max), 0)
     FROM tabel_kriteria kr
     JOIN tabel_kategori kat ON kat.id_kategori = kr.id_kategori
     WHERE kat.id_event = :ie"
);
$stmtMaks->execute([':ie' => $idEvent]);
$nilaiMaks = (float)($stmtMaks->fetchColumn() ?: 0);

// ── Query klasemen ────────────────────────────────────────────
$stmtK = $pdo->prepare("
    SELECT
        p.id_peserta,
        p.no_urut,
        p.nama_regu,
        p.asal_sekolah,
        COUNT(DISTINCT n.id_juri)                AS jumlah_juri,
        COALESCE(SUM(n.nilai_diperoleh),  0)     AS total_nilai,
        /* Aktifkan jika setup_penalti.sql sudah dijalankan:
        COALESCE(ABS(SUM(nvp.total_minus)), 0)   AS total_penalti,
        COALESCE(SUM(n.nilai_diperoleh), 0)
          - COALESCE(ABS(SUM(nvp.total_minus)), 0) AS total_nilai_akhir, */
        COALESCE(SUM(n.nilai_diperoleh),  0)     AS total_nilai_akhir
    FROM tabel_peserta p
    LEFT JOIN tabel_penilaian n
           ON n.id_peserta = p.id_peserta
    /* LEFT JOIN tabel_nilai_penalti nvp ON nvp.id_peserta = p.id_peserta */
    WHERE p.id_event = :ie
    GROUP BY p.id_peserta, p.no_urut, p.nama_regu, p.asal_sekolah
    ORDER BY total_nilai_akhir DESC, p.no_urut ASC
");
$stmtK->execute([':ie' => $idEvent]);
$klasemen = $stmtK->fetchAll();

// ── Format tanggal Indonesia ──────────────────────────────────
function tglIndo(?string $d): string {
    if (!$d) return '___________________';
    $bulan = ['','Januari','Februari','Maret','April','Mei','Juni',
               'Juli','Agustus','September','Oktober','November','Desember'];
    [$y,$m,$dd] = explode('-', $d);
    return (int)$dd . ' ' . $bulan[(int)$m] . ' ' . $y;
}

$tanggalAcara = $event['tgl_mulai']
    ? tglIndo($event['tgl_mulai'])
      . ($event['tgl_selesai'] && $event['tgl_selesai'] !== $event['tgl_mulai']
         ? ' s.d. ' . tglIndo($event['tgl_selesai']) : '')
    : '___________________';

$tanggalCetak = tglIndo(date('Y-m-d'));
$totalPeserta = count($klasemen);

?><!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Berita Acara — <?= htmlspecialchars($event['nama_event']) ?></title>

  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
  <link href="https://fonts.googleapis.com/css2?family=Times+New+Roman&family=DM+Sans:wght@400;500;600&display=swap" rel="stylesheet">

  <style>
    /* ══════════════════════════════════════════════════
       SCREEN STYLES — pratinjau di browser
    ══════════════════════════════════════════════════ */
    body {
      background: #e8e8e8;
      font-family: 'DM Sans', Arial, sans-serif;
      color: #111;
      margin: 0;
      padding: 0;
    }

    /* ── Toolbar atas (no-print) ── */
    .toolbar {
      background: #1c3050;
      padding: .75rem 2rem;
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 1rem;
      flex-wrap: wrap;
      position: sticky; top: 0; z-index: 100;
    }
    .toolbar-brand {
      font-size: .82rem; color: #a8c0d8; font-weight: 500;
      display: flex; align-items: center; gap: 8px;
    }
    .toolbar-brand strong { color: #fff; }
    .toolbar-actions { display: flex; gap: .6rem; flex-wrap: wrap; }
    .btn-cetak {
      background: #f5a623; color: #0d1b2e;
      border: none; border-radius: 8px;
      font-weight: 700; font-size: .88rem;
      padding: .55rem 1.4rem; cursor: pointer;
      display: inline-flex; align-items: center; gap: 7px;
      transition: background .15s;
    }
    .btn-cetak:hover { background: #d48a10; }
    .btn-back {
      background: rgba(255,255,255,.1); color: #dce8f5;
      border: 1px solid rgba(255,255,255,.15); border-radius: 8px;
      font-size: .82rem; padding: .5rem 1.1rem; cursor: pointer;
      display: inline-flex; align-items: center; gap: 6px; text-decoration: none;
      transition: background .15s;
    }
    .btn-back:hover { background: rgba(255,255,255,.18); color: #fff; }

    /* ── Kertas A4 di browser ── */
    .paper {
      width: 210mm;
      min-height: 297mm;
      background: #fff;
      margin: 1.5rem auto;
      padding: 2cm 2.2cm 2cm;
      box-shadow: 0 4px 30px rgba(0,0,0,.25);
      position: relative;
    }

    /* ── Kop Surat ── */
    .kop {
      text-align: center;
      border-bottom: 3px double #111;
      padding-bottom: .75rem;
      margin-bottom: 1rem;
    }
    .kop-logo {
      width: 64px; height: 64px;
      object-fit: contain; margin-bottom: .35rem;
    }
    .kop-instansi {
      font-size: 1.05rem; font-weight: 700;
      text-transform: uppercase; letter-spacing: .05em;
      margin-bottom: 2px;
    }
    .kop-alamat { font-size: .78rem; color: #444; }

    /* ── Judul dokumen ── */
    .doc-title {
      text-align: center;
      margin: 1.25rem 0 .3rem;
    }
    .doc-title h1 {
      font-size: 1.15rem; font-weight: 800;
      text-transform: uppercase; letter-spacing: .05em;
      margin: 0 0 4px;
      text-decoration: underline;
      text-underline-offset: 4px;
    }
    .doc-title p {
      font-size: .82rem; margin: 0; color: #333;
    }

    /* ── Paragraf pembuka ── */
    .pembuka {
      font-size: .88rem;
      line-height: 1.8;
      margin: 1rem 0;
      text-align: justify;
    }
    .pembuka strong { font-weight: 700; }

    /* ── Tabel hasil ── */
    .tbl-hasil {
      width: 100%;
      border-collapse: collapse;
      font-size: .87rem;
      margin: .75rem 0 1rem;
    }
    .tbl-hasil th, .tbl-hasil td {
      border: 1.5px solid #111;
      padding: .45rem .75rem;
      vertical-align: middle;
    }
    .tbl-hasil thead tr {
      background: #1c3050;
      color: #fff;
    }
    .tbl-hasil thead th {
      font-weight: 700;
      font-size: .8rem;
      text-transform: uppercase;
      letter-spacing: .05em;
    }
    .tbl-hasil tbody tr:nth-child(even) { background: #f5f8fc; }
    .tbl-hasil tbody tr:hover { background: #eef4fb; }

    /* Rank special */
    .tbl-hasil tbody tr.rank-1 td { background: #FFF9D0 !important; font-weight: 700; }
    .tbl-hasil tbody tr.rank-2 td { background: #F4F4F4 !important; font-weight: 600; }
    .tbl-hasil tbody tr.rank-3 td { background: #FFF0E8 !important; font-weight: 600; }
    .tbl-hasil tbody tr.rank-1 td:first-child { border-left: 5px solid #FFD700; }
    .tbl-hasil tbody tr.rank-2 td:first-child { border-left: 5px solid #C0C0C0; }
    .tbl-hasil tbody tr.rank-3 td:first-child { border-left: 5px solid #CD7F32; }

    .medal { font-size: 1.1rem; margin-right: 4px; }
    .rank-num {
      display: inline-flex; align-items: center; justify-content: center;
      width: 28px; height: 28px; border-radius: 50%;
      font-weight: 800; font-size: .82rem;
    }
    .rn-1 { background: #FFD700; color: #1a0f00; }
    .rn-2 { background: #C0C0C0; color: #0d0d0d; }
    .rn-3 { background: #CD7F32; color: #fff; }
    .rn-n { background: #e8e8e8; color: #555; }

    .nilai-akhir { font-weight: 800; font-size: .95rem; }

    /* ── Paragraf penutup ── */
    .penutup {
      font-size: .85rem; line-height: 1.8;
      margin: .75rem 0 1.25rem;
      text-align: justify;
    }

    /* ── Tanda tangan ── */
    .ttd-section { margin-top: 1.5rem; }
    .ttd-title {
      font-size: .8rem; font-weight: 700;
      text-transform: uppercase; letter-spacing: .06em;
      color: #555; margin-bottom: .75rem;
      border-bottom: 1px solid #ccc; padding-bottom: .3rem;
    }
    .ttd-grid {
      display: flex;
      flex-wrap: wrap;
      gap: 1.2rem 1.5rem;
      justify-content: space-between;
    }
    .ttd-box {
      flex: 1 1 130px;
      min-width: 110px;
      max-width: 200px;
      text-align: center;
    }
    .ttd-box .jabatan {
      font-size: .75rem; font-weight: 700;
      text-transform: uppercase; color: #333;
      margin-bottom: 2px;
    }
    .ttd-box .nama-juri {
      font-size: .72rem; color: #555;
      margin-bottom: 55px;   /* ← ruang tanda tangan */
    }
    .ttd-garis {
      border-top: 1.5px solid #111;
      font-size: .78rem; font-weight: 700;
      padding-top: 4px;
    }
    .ttd-nip {
      font-size: .68rem; color: #666; margin-top: 2px;
    }

    /* TTD "Mengetahui" terpisah di kiri bawah */
    .ttd-mengetahui {
      margin-top: 1.25rem;
      display: flex; justify-content: flex-start; gap: 2rem; flex-wrap: wrap;
    }
    .ttd-mengetahui .ttd-box { max-width: 220px; }

    /* ── Nomor halaman ── */
    .page-footer {
      position: absolute; bottom: 1cm; left: 2.2cm; right: 2.2cm;
      display: flex; justify-content: space-between;
      font-size: .72rem; color: #999;
      border-top: 1px solid #ddd; padding-top: 4px;
    }

    /* ══════════════════════════════════════════════════
       PRINT STYLES
    ══════════════════════════════════════════════════ */
    @media print {
      /* Sembunyikan semua elemen non-dokumen */
      .no-print          { display: none !important; }
      .toolbar           { display: none !important; }

      /* Atur ukuran halaman */
      @page {
        size: A4 portrait;
        margin: 2cm;
      }

      /* Reset body */
      body {
        background: #fff !important;
        padding: 0 !important;
        margin: 0 !important;
      }

      /* Paper: hilangkan shadow, isi penuh halaman */
      .paper {
        width: 100% !important;
        min-height: auto !important;
        padding: 0 !important;
        margin: 0 !important;
        box-shadow: none !important;
        border: none !important;
      }

      /* Pastikan tabel tidak terpotong di tengah baris */
      .tbl-hasil tbody tr { page-break-inside: avoid; }
      .ttd-section        { page-break-inside: avoid; }

      /* Warna latar tabel tetap tercetak */
      .tbl-hasil tbody tr.rank-1 td { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
      .tbl-hasil tbody tr.rank-2 td { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
      .tbl-hasil tbody tr.rank-3 td { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
      .tbl-hasil thead tr           { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
      .rank-num                      { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    }
  </style>
</head>
<body>

<!-- ═════════════════════════════════════════
     TOOLBAR LAYAR (no-print)
════════════════════════════════════════════ -->
<div class="toolbar no-print">
  <div class="toolbar-brand">
    <i class="bi bi-file-earmark-text" style="font-size:1.1rem;color:#f5a623;"></i>
    <span>Pratinjau — <strong>Berita Acara Hasil Keputusan Dewan Juri</strong></span>
  </div>
  <div class="toolbar-actions">
    <a href="lihat_hasil.php?id_event=<?= $idEvent ?>" class="btn-back">
      <i class="bi bi-arrow-left"></i>Kembali
    </a>
    <a href="dashboard_klasemen.php?id_event=<?= $idEvent ?>" class="btn-back">
      <i class="bi bi-trophy"></i>Klasemen
    </a>
    <button class="btn-cetak" onclick="window.print()">
      <i class="bi bi-printer-fill"></i>Cetak Dokumen
    </button>
  </div>
</div>

<!-- ═════════════════════════════════════════
     KERTAS A4
════════════════════════════════════════════ -->
<div class="paper">

  <!-- ── KOP SURAT ── -->
  <div class="kop">
    <!-- Logo: ganti src dengan path logo panitia jika ada -->
    <img src="logo_panitia.png" alt="Logo" class="kop-logo"
         onerror="this.style.display='none'">
    <div class="kop-instansi">
      Panitia Penyelenggara<br>
      <?= htmlspecialchars($event['nama_event']) ?>
    </div>
    <div class="kop-alamat">
      Sekretariat Panitia &nbsp;·&nbsp;
      Alamat Panitia &nbsp;·&nbsp;
      Telp. / WA: ___________________
    </div>
  </div>

  <!-- ── JUDUL DOKUMEN ── -->
  <div class="doc-title">
    <h1>Berita Acara Hasil Keputusan Dewan Juri</h1>
    <p><?= htmlspecialchars($event['nama_event']) ?></p>
  </div>

  <!-- ── PARAGRAF PEMBUKA ── -->
  <div class="pembuka">
    Pada hari ini, <?= $tanggalCetak ?>, bertempat di ___________________________,
    telah dilaksanakan kegiatan <strong><?= htmlspecialchars($event['nama_event']) ?></strong>
    <?= $event['tgl_mulai'] ? 'yang berlangsung pada tanggal <strong>' . $tanggalAcara . '</strong>' : '' ?>.
    Dewan Juri yang telah ditunjuk secara resmi telah menyelesaikan proses penilaian
    terhadap seluruh <strong><?= $totalPeserta ?> peserta</strong> yang mengikuti kegiatan tersebut.
    Berdasarkan hasil akumulasi penilaian dari seluruh dewan juri, maka ditetapkan
    urutan hasil penilaian sebagai berikut:
  </div>

  <!-- ── TABEL HASIL ── -->
  <table class="tbl-hasil">
    <thead>
      <tr>
        <th style="width:7%;text-align:center;">Peringkat</th>
        <th style="width:10%;text-align:center;">No. Peserta</th>
        <th>Nama Regu / Pangkalan</th>
        <th style="width:15%;text-align:center;">Total Nilai</th>
        <?php if ($nilaiMaks > 0): ?>
        <th style="width:12%;text-align:center;">Persentase</th>
        <?php endif; ?>
      </tr>
    </thead>
    <tbody>
      <?php if (empty($klasemen)): ?>
      <tr>
        <td colspan="5" style="text-align:center;padding:1.5rem;color:#888;">
          Belum ada data penilaian.
        </td>
      </tr>
      <?php else: ?>
      <?php
      $rank = 0;
      foreach ($klasemen as $row):
        $rank++;
        $nilai = (float)($row['total_nilai_akhir'] ?? 0);
        $pct   = $nilaiMaks > 0 ? round($nilai / $nilaiMaks * 100, 2) : 0;
        [$trClass, $rnClass, $medal] = match($rank) {
          1 => ['rank-1','rn-1','🥇'],
          2 => ['rank-2','rn-2','🥈'],
          3 => ['rank-3','rn-3','🥉'],
          default => ['','rn-n',''],
        };
      ?>
      <tr class="<?= $trClass ?>">
        <td style="text-align:center;">
          <span class="rank-num <?= $rnClass ?>"><?= $rank ?></span>
          <?= $medal ?>
        </td>
        <td style="text-align:center;font-weight:700;"><?= (int)$row['no_urut'] ?></td>
        <td>
          <strong><?= htmlspecialchars($row['nama_regu']) ?></strong>
          <?php if ($row['asal_sekolah']): ?>
          <br><span style="font-size:.78rem;color:#555;"><?= htmlspecialchars($row['asal_sekolah']) ?></span>
          <?php endif; ?>
        </td>
        <td style="text-align:center;" class="nilai-akhir">
          <?= $nilai > 0 ? number_format($nilai, 2) : '—' ?>
        </td>
        <?php if ($nilaiMaks > 0): ?>
        <td style="text-align:center;font-size:.8rem;color:#555;">
          <?= $nilai > 0 ? $pct . '%' : '—' ?>
        </td>
        <?php endif; ?>
      </tr>
      <?php endforeach; ?>
      <?php endif; ?>
    </tbody>
  </table>

  <!-- ── PARAGRAF PENUTUP ── -->
  <div class="penutup">
    Demikian Berita Acara ini dibuat dengan sebenar-benarnya berdasarkan hasil penilaian
    yang telah dilakukan oleh Dewan Juri secara objektif dan penuh tanggung jawab.
    Keputusan Dewan Juri bersifat <strong>mutlak dan tidak dapat diganggu gugat</strong>.
    Berita Acara ini ditandatangani bersama sebagai bukti sahnya hasil keputusan tersebut.
  </div>

  <!-- ── TANDA TANGAN DEWAN JURI ── -->
  <div class="ttd-section">
    <div class="ttd-title"><i class="bi bi-pen-fill" style="margin-right:5px;"></i>Dewan Juri</div>
    <div class="ttd-grid">
      <?php if (!empty($daftarJuri)): ?>
        <?php foreach ($daftarJuri as $idx => $j): ?>
        <div class="ttd-box">
          <div class="jabatan">
            Juri <?= $j['kode_juri'] ?: ($idx + 1) ?>
          </div>
          <div class="nama-juri">&nbsp;</div>
          <div class="ttd-garis"><?= htmlspecialchars($j['nama_juri']) ?></div>
          <div class="ttd-nip">NIP / No. SK: ___________</div>
        </div>
        <?php endforeach; ?>
      <?php else: ?>
        <?php for ($i = 1; $i <= 3; $i++): ?>
        <div class="ttd-box">
          <div class="jabatan">Juri <?= $i ?></div>
          <div class="nama-juri">&nbsp;</div>
          <div class="ttd-garis">( ___________________ )</div>
          <div class="ttd-nip">NIP / No. SK: ___________</div>
        </div>
        <?php endfor; ?>
      <?php endif; ?>
    </div>
  </div>

  <!-- ── TANDA TANGAN MENGETAHUI ── -->
  <div class="ttd-section ttd-mengetahui">
    <div class="ttd-title" style="width:100%;">
      <i class="bi bi-check-circle" style="margin-right:5px;"></i>Mengetahui
    </div>
    <div class="ttd-box" style="max-width:240px;">
      <div class="jabatan">Ketua Panitia</div>
      <div class="nama-juri"><?= htmlspecialchars($event['nama_event']) ?>, <?= $tanggalCetak ?></div>
      <div class="ttd-garis">( ___________________ )</div>
      <div class="ttd-nip">NIP / No. SK: ___________</div>
    </div>
    <div class="ttd-box" style="max-width:240px;">
      <div class="jabatan">Koordinator Juri</div>
      <div class="nama-juri">&nbsp;</div>
      <div class="ttd-garis">( ___________________ )</div>
      <div class="ttd-nip">NIP / No. SK: ___________</div>
    </div>
  </div>

  <!-- ── FOOTER HALAMAN ── -->
  <div class="page-footer">
    <span>Dicetak: <?= date('d/m/Y H:i:s') ?> · Sistem Penilaian Paskibra SaaS</span>
    <span>Halaman 1 dari 1</span>
  </div>

</div><!-- /paper -->

</body>
</html>
