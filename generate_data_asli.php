<?php
/**
 * generate_data_asli.php  — Versi 3.0  (Zone-Based OMR)
 * ============================================================
 * Jalankan: php generate_data_asli.php  /  via browser
 *
 * ARSITEKTUR BARU — Zone-Based OMR (Grid Slicing):
 *   - Koordinat OMR disimpan sebagai satu "Zone" besar per Kategori
 *     di kolom `omr_zone_json` pada tabel_kategori
 *   - tabel_kriteria.opsi_nilai_json hanya menyimpan label+nilai
 *   - JS runtime membelah Zone menjadi sub-grid (row×col) secara matematis
 *
 *   Zone JSON: {area_rx, area_ry, area_rw, area_rh, jumlah_baris, jumlah_kolom}
 *   Rumus sel:
 *     cell_w  = area_rw / jumlah_kolom
 *     cell_h  = area_rh / jumlah_baris
 *     cell_x  = area_rx + col * cell_w   (kiri kotak)
 *     cell_y  = area_ry + row * cell_h   (atas kotak)
 *
 *   Keuntungan: Snowball Effect MUSTAHIL — tinggi sel dikunci oleh area_rh / N
 * ============================================================
 */
declare(strict_types=1);

$isCli = (php_sapi_name() === 'cli');
if (!$isCli) {
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html lang="id"><head><meta charset="utf-8">'
       . '<title>Seeder v3 Zone-Based OMR</title><style>'
       . '*{box-sizing:border-box;margin:0;padding:0}'
       . 'body{font-family:"Courier New",monospace;background:#0d1b2e;color:#dce8f5;padding:2rem;line-height:1.7}'
       . 'pre{background:#162640;padding:1.25rem 1.5rem;border-radius:10px;overflow-x:auto;border:1px solid rgba(255,255,255,0.07)}'
       . '.ok{color:#2dc653}.err{color:#e63946}.warn{color:#f5a623}.info{color:#38bdf8}.dim{color:#7a94af}.head{color:#a78bfa;font-weight:bold}'
       . '</style></head><body><pre>' . "\n";
}

function log_out(string $line, string $type = 'info'): void {
    global $isCli;
    static $ansi = ['ok'=>"\033[32m",'err'=>"\033[31m",'warn'=>"\033[33m",'info'=>"\033[36m",'dim'=>"\033[90m",'head'=>"\033[35m",'reset'=>"\033[0m"];
    if ($isCli) echo ($ansi[$type]??'') . $line . $ansi['reset'] . "\n";
    else echo '<span class="'.htmlspecialchars($type).'">'.htmlspecialchars($line)."</span>\n";
}

require_once __DIR__ . '/koneksi.php';
const ID_EVENT = 1;

// ══════════════════════════════════════════════════════════════
// KONSTANTA GRID OMR — dikalibrasi dari piksel asli 1653×2338
// ══════════════════════════════════════════════════════════════
//
//   rx_start = 0.439   → pusat kiri kolom SK
//   rx_step  = 0.067   → jarak antar kolom  (0.474 / 7 ≈ 0.0677)
//   rw       = 0.045   → lebar kotak deteksi
//   rh       = 0.013   → tinggi kotak deteksi (ramping, tidak menyentuh garis)
//   ry_step  = 0.015   → jarak vertikal antar baris  (0.214 / 14 ≈ 0.0153)
//
//   Koordinat setiap opsi dihitung di PHP:
//     opsi_rx = rx_start + (j × rx_step)   ← kolom ke-j (0-based)
//     opsi_ry = base_ry  + (i × ry_step)   ← baris ke-i (0-based)
//
//   opsi_nilai_json yang dihasilkan per kriteria:
//   {
//     "label": ["SK","K","C","CB","B","SB","A"],
//     "nilai": [10, 13, 16, ...],
//     "opsi": [
//       {"nilai":10,"label":"SK","rx":0.439,"ry":0.119,"rw":0.045,"rh":0.013},
//       {"nilai":13,"label":"K", "rx":0.506,"ry":0.119,"rw":0.045,"rh":0.013},
//       ...
//     ]
//   }
//
// ══════════════════════════════════════════════════════════════
$rx_start = 0.439;
$rx_step  = 0.067;   // 0.474 / 7
$rw       = 0.045;
$rh       = 0.013;
$ry_step  = 0.015;   // 0.214 / 14

$LABELS_7 = ['SK','K','C','CB','B','SB','A'];
$LABELS_6 = ['SK','K','C','CB','B','SB'];

// ══════════════════════════════════════════════════════════════
// DATA LOMBA — base_ry per kategori + gerakan masing-masing
//
//   base_ry = posisi Y baris PERTAMA gerakan dalam kategori ini
//   Baris ke-i (0-based): ry = base_ry + (i × ry_step)
// ══════════════════════════════════════════════════════════════
$data_lomba = [

  // ── A — GERAKAN AWAL ──────────────────────────────────────
  // base_ry TERUKUR: pixel 279 / 2338 = 0.119
  [
    'kode'    => 'A',
    'nama'    => 'GERAKAN AWAL',
    'base_ry' => 0.119,
    'gerakan' => [
      ['nama'=>'BERSHAF KUMPUL','nilai'=>[10,13,16,19,22,25,28]],
    ],
  ],

  // ── B — GERAKAN DITEMPAT ──────────────────────────────────
  [
    'kode'    => 'B',
    'nama'    => 'GERAKAN DITEMPAT',
    'base_ry' => 0.165,
    'gerakan' => [
      ['nama'=>'SIKAP SEMPURNA',          'nilai'=>[1, 2, 3, 4, 5, 6, 7]],
      ['nama'=>'BERHITUNG',               'nilai'=>[2, 3, 4, 5, 6, 7, 8]],
      ['nama'=>'HORMAT',                  'nilai'=>[2, 3, 4, 5, 6, 7, 8]],
      ['nama'=>'SIKAP ISTIRAHAT DITEMPAT','nilai'=>[3, 4, 5, 6, 7, 8, 9]],
      ['nama'=>'PERIKSA KERAPIHAN',       'nilai'=>[5, 8,11,14,17,20,23]],
      ['nama'=>'1/2 LENGAN LENCANG KIRI', 'nilai'=>[7, 8, 9,10,11,12,13]],
      ['nama'=>'LENCANG KANAN',           'nilai'=>[7, 8, 9,10,11,12,13]],
      ['nama'=>'HADAP KANAN',             'nilai'=>[4, 5, 6, 7, 8, 9,10]],
      ['nama'=>'HADAP KIRI',              'nilai'=>[4, 5, 6, 7, 8, 9,10]],
      ['nama'=>'HADAP SERONG KIRI',       'nilai'=>[4, 5, 6, 7, 8, 9,10]],
      ['nama'=>'JALAN DITEMPAT',          'nilai'=>[5, 6, 7, 8, 9,10,11]],
      ['nama'=>'BALIK KANAN',             'nilai'=>[7, 8, 9,10,11,12,13]],
      ['nama'=>'HADAP SERONG KIRI HENTI', 'nilai'=>[7, 8, 9,10,11,12,13]],
      ['nama'=>'LENCANG DEPAN',           'nilai'=>[5, 6, 7, 8, 9,10,11]],
    ],
  ],

  // ── C — BERPINDAH TEMPAT ──────────────────────────────────
  [
    'kode'    => 'C',
    'nama'    => 'BERPINDAH TEMPAT',
    'base_ry' => 0.550,
    'gerakan' => [
      ['nama'=>'3 LANGKAH KE DEPAN',   'nilai'=>[5, 7, 8, 9,11,13,15]],
      ['nama'=>'3 LANGKAH KE BELAKANG','nilai'=>[5, 8, 9,10,12,14,16]],
      ['nama'=>'3 LANGKAH KE KANAN',   'nilai'=>[5, 7, 8, 9,11,13,15]],
      ['nama'=>'3 LANGKAH KE KIRI',    'nilai'=>[5, 7, 8, 9,11,13,15]],
    ],
  ],

  // ── D — GERAKAN BERJALAN KE BERJALAN ──────────────────────
  [
    'kode'    => 'D',
    'nama'    => 'GERAKAN BERJALAN KE BERJALAN',
    'base_ry' => 0.670,
    'gerakan' => [
      ['nama'=>'LANGKAH BIASA',                      'nilai'=>[ 5, 7, 9,11,13,15,17]],
      ['nama'=>'BELOK KANAN',                        'nilai'=>[11,13,15,17,19,21,23]],
      ['nama'=>'TIAP-TIAP BANJAR 2 KALI BELOK',     'nilai'=>[12,14,16,18,20,22,24]],
      ['nama'=>'BELOK KIRI',                         'nilai'=>[11,13,15,17,19,21,23]],
      ['nama'=>'LANGKAH TEGAP',                      'nilai'=>[ 9,11,13,15,17,19,21]],
      ['nama'=>'HORMAT KANAN',                       'nilai'=>[12,14,16,18,20,22,24]],
      ['nama'=>'LANGKAH BIASA 2',                    'nilai'=>[ 9,11,13,15,17,19,21]],
      ['nama'=>'TIAP-TIAP BANJAR 2 KALI BELOK KIRI','nilai'=>[12,14,16,18,20,22,24]],
      ['nama'=>'GANTI LANGKAH',                      'nilai'=>[ 8,10,12,14,16,18,20]],
      ['nama'=>'LANGKAH PERLAHAN',                   'nilai'=>[11,13,15,17,19,21,23]],
      ['nama'=>'BALIK KANAN MAJU',                   'nilai'=>[ 9,11,13,15,17,19,21]],
      ['nama'=>'HADAP KANAN HENTI',                  'nilai'=>[ 6, 8,10,12,14,16,18]],
    ],
  ],

  // ── E — GERAKAN AKHIR ─────────────────────────────────────
  [
    'kode'    => 'E',
    'nama'    => 'GERAKAN AKHIR',
    'base_ry' => 0.950,
    'gerakan' => [
      ['nama'=>'BUBAR','nilai'=>[7.5,10.5,13.5,16.5,19.5,22.5,25.5]],
    ],
  ],

  // ── F — DANPAS (6 opsi: SK–SB, tanpa kolom A) ─────────────
  [
    'kode'    => 'F',
    'nama'    => 'DANPAS',
    'base_ry' => 0.980,
    'gerakan' => [
      ['nama'=>'SIKAP',                    'nilai'=>[1,  2,  3,  4,  5,  6  ]],
      ['nama'=>'PENGUASAAN LAPANGAN',      'nilai'=>[2,  3,  4,  5,  6,  7  ]],
      ['nama'=>'PENGUASAAN MATERI',        'nilai'=>[4,  5,  6,  7,  8,  9  ]],
      ['nama'=>'INTONASI',                 'nilai'=>[1,  2,  3,  4,  5,  6  ]],
      ['nama'=>'KELANTANGAN DAN KETEGASAN','nilai'=>[2.5,3.5,4.5,5.5,6.5,7.5]],
    ],
  ],
];

// ══════════════════════════════════════════════════════════════
// EKSEKUSI
// ══════════════════════════════════════════════════════════════
log_out('');
log_out('╔══════════════════════════════════════════════════╗','head');
log_out('║  LKBB SANTRI SEASON 2 — Seeder v4.0             ║','head');
log_out('║  Metode : Per-Cell Coords (PHP pre-computed)     ║','head');
log_out('║  rx_start=0.439 rx_step=0.067 rw=0.045 rh=0.013 ║','head');
log_out('║  ry_step=0.015  |  6 kategori · 32 gerakan      ║','head');
log_out('╚══════════════════════════════════════════════════╝','head');
log_out('');

try {
    $pdo->beginTransaction();

    // ── STEP 1: TRUNCATE ─────────────────────────────────────
    log_out('[STEP 1] Truncate tabel …','warn');
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
    $pdo->exec('TRUNCATE TABLE tabel_penilaian');
    $pdo->exec('TRUNCATE TABLE tabel_kriteria');
    $pdo->exec('TRUNCATE TABLE tabel_kategori');
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    log_out('  ✓ tabel_penilaian, tabel_kriteria, tabel_kategori — truncated','ok');

    // ── STEP 2: INSERT KATEGORI ───────────────────────────────
    log_out('');
    log_out('[STEP 2] Insert kategori …','warn');

    $stmtKat = $pdo->prepare(
        'INSERT INTO tabel_kategori (id_event,urutan,kode_kategori,nama_kategori)
         VALUES (:ie,:ur,:kode,:nama)'
    );

    $katMap  = [];
    $urutKat = 0;
    foreach ($data_lomba as $kat) {
        $urutKat++;
        $stmtKat->execute([':ie'=>ID_EVENT,':ur'=>$urutKat,':kode'=>$kat['kode'],':nama'=>$kat['nama']]);
        $id = (int)$pdo->lastInsertId();
        $katMap[$kat['kode']] = $id;
        log_out(sprintf('  ✓ Kat %s [id=%d] base_ry=%.3f  %s  (%d gerakan)',
            $kat['kode'],$id,$kat['base_ry'],$kat['nama'],count($kat['gerakan'])),'ok');
    }

    // ── STEP 3: INSERT KRITERIA dengan koordinat per opsi ────
    log_out('');
    log_out('[STEP 3] Insert kriteria + koordinat per opsi …','warn');

    $stmtKr = $pdo->prepare(
        'INSERT INTO tabel_kriteria (id_kategori,urutan,nama_gerakan,opsi_nilai_json,nilai_min,nilai_max)
         VALUES (:ik,:ur,:nm,:json,:mn,:mx)'
    );

    $pdo->beginTransaction();
    
    $total = 0;
    foreach ($data_lomba as $kat) {
        $idKat   = $katMap[$kat['kode']];
        $nOpsi   = count($kat['gerakan'][0]['nilai']);  // 7 atau 6
        $labels  = ($nOpsi === 6) ? $LABELS_6 : $LABELS_7;

        log_out(sprintf('  ── Kat %s [%s]  base_ry=%.3f  %d gerakan ──',
            $kat['kode'],$kat['nama'],$kat['base_ry'],count($kat['gerakan'])),'info');

        foreach ($kat['gerakan'] as $i => $g) {
            $total++;

            // Hitung ry baris ini
            $gerakan_ry = round($kat['base_ry'] + ($i * $ry_step), 5);

            // Bangun array opsi dengan koordinat per sel
            $opsiArr = [];
            foreach ($g['nilai'] as $j => $val) {
                $opsi_rx = round($rx_start + ($j * $rx_step), 5);
                $opsiArr[] = [
                    'nilai' => $val,
                    'label' => $labels[$j],
                    'rx'    => $opsi_rx,
                    'ry'    => $gerakan_ry,
                    'rw'    => $rw,
                    'rh'    => $rh,
                ];
            }

            $opsiJson = json_encode([
                'label' => $labels,
                'nilai' => array_values($g['nilai']),
                'opsi'  => $opsiArr,
            ], JSON_UNESCAPED_UNICODE);

            $stmtKr->execute([
                ':ik'  => $idKat,
                ':ur'  => $i + 1,
                ':nm'  => $g['nama'],
                ':json'=> $opsiJson,
                ':mn'  => min($g['nilai']),
                ':mx'  => max($g['nilai']),
            ]);

            $idKr = (int)$pdo->lastInsertId();
            log_out(sprintf('    [%02d] id=%-3d  ry=%.5f  %-42s  %s–%s',
                $total,$idKr,$gerakan_ry,$g['nama'],min($g['nilai']),max($g['nilai'])),'dim');
        }
    }

    $pdo->commit();

    // ── RINGKASAN ─────────────────────────────────────────────
    log_out('');
    log_out('╔══════════════════════════════════════════════════╗','head');
    log_out('║  ✓ SELESAI — Semua data berhasil di-insert!     ║','ok');
    log_out('╚══════════════════════════════════════════════════╝','head');
    log_out(sprintf('  Total kategori : %d',count($data_lomba)),'info');
    log_out(sprintf('  Total kriteria : %d',$total),'info');
    log_out('');

    // Preview satu JSON untuk verifikasi
    log_out('[PREVIEW] opsi_nilai_json BERSHAF KUMPUL (ry=0.119):', 'warn');
    $prvOpsi = [];
    foreach ([10,13,16,19,22,25,28] as $j => $val) {
        $prvOpsi[] = ['nilai'=>$val,'label'=>$LABELS_7[$j],
                      'rx'=>round($rx_start+($j*$rx_step),5),'ry'=>0.119,'rw'=>$rw,'rh'=>$rh];
    }
    log_out(json_encode(['label'=>$LABELS_7,'nilai'=>[10,13,16,19,22,25,28],'opsi'=>$prvOpsi],
        JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT),'dim');

    log_out('');
    log_out('[INFO] Koordinat X (rx) per kolom:', 'warn');
    foreach ($LABELS_7 as $j => $lbl) {
        log_out(sprintf('  Kolom %d (%s): rx = %.5f', $j+1, $lbl,
            round($rx_start + ($j * $rx_step),5)),'dim');
    }

} catch (PDOException $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    log_out('[ERROR] '.$e->getMessage(),'err');
    log_out('[ERROR] Rollback — tidak ada perubahan tersimpan.','err');
    if (!$isCli) echo '</pre></body></html>';
    exit(1);
}
if (!$isCli) echo '</pre></body></html>';
