<?php
/**
 * get_live_skor.php
 * ============================================================
 * Endpoint : GET /get_live_skor.php?id_event=1
 *
 * Mengembalikan rekap skor live per peserta, diurutkan dari
 * tertinggi ke terendah. Format response dirancang agar siap
 * dikonsumsi langsung oleh Highcharts (categories + series).
 *
 * Struktur Response:
 * {
 *   "status": "success",
 *   "data": {
 *     "event": { ... },
 *     "updated_at": "2026-04-20 09:00:00",
 *     "table": [ { "peringkat", "no_urut", "nama_regu", ... } ],
 *     "highcharts": {
 *       "categories": ["GARDA PERKASA", "SATRIA AGUNG", ...],
 *       "series": [
 *         { "name": "Total Skor", "data": [156.00, 143.50, ...] },
 *         { "name": "Kategori A", "data": [...] },
 *         ...
 *       ]
 *     }
 *   }
 * }
 * ============================================================
 */

// ── CORS & Headers ───────────────────────────────────────────
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Requested-With');
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'code' => 405, 'message' => 'Method not allowed.']);
    exit;
}

// ── Koneksi Database ─────────────────────────────────────────
require_once __DIR__ . '/koneksi.php';

// ── Validasi Parameter ────────────────────────────────────────
$id_event = filter_input(INPUT_GET, 'id_event', FILTER_VALIDATE_INT);

if (!$id_event || $id_event <= 0) {
    http_response_code(400);
    echo json_encode([
        'status'  => 'error',
        'code'    => 400,
        'message' => 'Parameter id_event wajib diisi dan harus berupa bilangan bulat positif.'
    ]);
    exit;
}

try {
    // ── Ambil Info Event ──────────────────────────────────────
    $stmtEvent = $pdo->prepare(
        "SELECT id_event, nama_event, tgl_mulai, tgl_selesai
           FROM tabel_event
          WHERE id_event = :id_event
            AND status_aktif = 1
          LIMIT 1"
    );
    $stmtEvent->execute([':id_event' => $id_event]);
    $event = $stmtEvent->fetch();

    if (!$event) {
        http_response_code(404);
        echo json_encode([
            'status'  => 'error',
            'code'    => 404,
            'message' => "Event dengan id_event={$id_event} tidak ditemukan atau tidak aktif."
        ]);
        exit;
    }

    // ── Query 1: Total Skor Keseluruhan per Peserta ───────────
    // Rata-rata dari semua juri, lalu dijumlah per peserta
    $stmtTotal = $pdo->prepare(
        "SELECT
             p.id_peserta,
             p.no_urut,
             p.nama_regu,
             p.asal_sekolah,
             ROUND(SUM(n.nilai_diperoleh), 2)                    AS total_skor,
             COUNT(DISTINCT n.id_juri)                           AS jumlah_juri_menilai,
             COUNT(n.id_penilaian)                               AS jumlah_kriteria_dinilai,
             MAX(n.waktu_input)                                  AS terakhir_diperbarui
           FROM tabel_peserta p
           LEFT JOIN tabel_penilaian n ON n.id_peserta = p.id_peserta
          WHERE p.id_event = :id_event
          GROUP BY p.id_peserta, p.no_urut, p.nama_regu, p.asal_sekolah
          ORDER BY total_skor DESC, p.no_urut ASC"
    );
    $stmtTotal->execute([':id_event' => $id_event]);
    $skorTotal = $stmtTotal->fetchAll();

    // ── Query 2: Skor per Kategori per Peserta ────────────────
    // Digunakan untuk series breakdown Highcharts
    $stmtPerKategori = $pdo->prepare(
        "SELECT
             p.id_peserta,
             kat.id_kategori,
             kat.kode_kategori,
             kat.nama_kategori,
             ROUND(SUM(n.nilai_diperoleh), 2) AS skor_kategori
           FROM tabel_peserta p
           LEFT JOIN tabel_penilaian  n   ON n.id_peserta  = p.id_peserta
           LEFT JOIN tabel_kriteria   kr  ON kr.id_kriteria = n.id_kriteria
           LEFT JOIN tabel_kategori   kat ON kat.id_kategori = kr.id_kategori
          WHERE p.id_event = :id_event
            AND kat.id_kategori IS NOT NULL
          GROUP BY p.id_peserta, kat.id_kategori, kat.kode_kategori, kat.nama_kategori
          ORDER BY p.id_peserta ASC, kat.urutan ASC"
    );
    $stmtPerKategori->execute([':id_event' => $id_event]);
    $skorPerKategori = $stmtPerKategori->fetchAll();

    // ── Ambil Daftar Kategori (urut) ─────────────────────────
    $stmtKategori = $pdo->prepare(
        "SELECT id_kategori, kode_kategori, nama_kategori
           FROM tabel_kategori
          WHERE id_event = :id_event
          ORDER BY urutan ASC"
    );
    $stmtKategori->execute([':id_event' => $id_event]);
    $daftarKategori = $stmtKategori->fetchAll();

    // ── Susun Map: id_peserta → skor per kategori ─────────────
    $mapSkorKategori = [];
    foreach ($skorPerKategori as $row) {
        $pid  = (int) $row['id_peserta'];
        $kode = $row['kode_kategori'];
        if (!isset($mapSkorKategori[$pid])) {
            $mapSkorKategori[$pid] = [];
        }
        $mapSkorKategori[$pid][$kode] = (float) $row['skor_kategori'];
    }

    // ── Susun Tabel Peringkat (untuk tampilan tabel di frontend) ──
    $tabelPeringkat = [];
    $peringkat      = 1;

    foreach ($skorTotal as $row) {
        $pid = (int) $row['id_peserta'];

        // Susun detail skor per kategori untuk baris ini
        $detailKategori = [];
        foreach ($daftarKategori as $kat) {
            $kode = $kat['kode_kategori'];
            $detailKategori['skor_kat_' . strtolower($kode)] =
                $mapSkorKategori[$pid][$kode] ?? 0.0;
        }

        $tabelPeringkat[] = array_merge([
            'peringkat'              => $peringkat++,
            'id_peserta'             => $pid,
            'no_urut'                => (int)   $row['no_urut'],
            'nama_regu'              => $row['nama_regu'],
            'asal_sekolah'           => $row['asal_sekolah'],
            'total_skor'             => (float)  ($row['total_skor'] ?? 0),
            'jumlah_juri_menilai'    => (int)    $row['jumlah_juri_menilai'],
            'jumlah_kriteria_dinilai'=> (int)    $row['jumlah_kriteria_dinilai'],
            'terakhir_diperbarui'    => $row['terakhir_diperbarui']
        ], $detailKategori);
    }

    // ── Susun Format Highcharts ───────────────────────────────
    // categories: nama regu (X-axis)
    // series[0]:  total skor
    // series[1..N]: skor per kategori A, B, C, ...

    $hcCategories = array_column($tabelPeringkat, 'nama_regu');

    // Series: Total Skor
    $seriesTotal = [
        'name'  => 'Total Skor',
        'type'  => 'column',
        'color' => '#1e3a5f',
        'data'  => array_map(fn($r) => $r['total_skor'], $tabelPeringkat)
    ];

    // Series: Skor per Kategori (stacked)
    $seriesKategori = [];
    $katColors = ['#2ecc71','#3498db','#9b59b6','#e67e22','#e74c3c','#1abc9c'];
    $i = 0;
    foreach ($daftarKategori as $kat) {
        $kode     = $kat['kode_kategori'];
        $fieldKey = 'skor_kat_' . strtolower($kode);

        $seriesKategori[] = [
            'name'  => 'Kat. ' . $kode . ' – ' . $kat['nama_kategori'],
            'type'  => 'column',
            'stack' => 'kategori',
            'color' => $katColors[$i % count($katColors)],
            'data'  => array_map(fn($r) => $r[$fieldKey] ?? 0.0, $tabelPeringkat)
        ];
        $i++;
    }

    $highcharts = [
        'chart'     => ['type' => 'column'],
        'title'     => ['text' => 'Live Skor – ' . $event['nama_event']],
        'xAxis'     => ['categories' => $hcCategories],
        'yAxis'     => ['title' => ['text' => 'Total Nilai']],
        'plotOptions'=> [
            'column' => [
                'grouping'   => true,
                'borderWidth'=> 0
            ]
        ],
        'series'    => array_merge([$seriesTotal], $seriesKategori)
    ];

    // ── Kirim Response ────────────────────────────────────────
    http_response_code(200);
    echo json_encode([
        'status'     => 'success',
        'code'       => 200,
        'data'       => [
            'event'      => $event,
            'updated_at' => date('Y-m-d H:i:s'),
            'table'      => $tabelPeringkat,
            'highcharts' => $highcharts
        ]
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'status'  => 'error',
        'code'    => 500,
        'message' => 'Terjadi kesalahan pada server database.',
        // 'debug' => $e->getMessage()
    ]);
}
