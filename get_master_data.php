<?php
/**
 * get_master_data.php
 * ============================================================
 * Endpoint : GET /get_master_data.php?id_event=1
 * Deskripsi: Mengembalikan master data sebuah event:
 *            - daftar_peserta
 *            - daftar_juri
 *            - struktur_kriteria (JOIN kategori, diurutkan)
 * ============================================================
 */

// ── CORS & Headers ───────────────────────────────────────────
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Requested-With');
header('Content-Type: application/json; charset=utf-8');

// Tangani preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// ── Hanya izinkan GET ────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'code' => 405, 'message' => 'Method not allowed.']);
    exit;
}

// ── Koneksi Database ─────────────────────────────────────────
require_once __DIR__ . '/api_auth.php';   // ← JSON-only session guard
api_guard(['admin', 'juri']);             // 401 JSON jika belum login
require_once __DIR__ . '/koneksi.php';

// ── Validasi & Sanitasi Parameter ────────────────────────────
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

// ── Pastikan Event Exist & Aktif ─────────────────────────────
try {
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

    // ── Query: Daftar Peserta ─────────────────────────────────
    $stmtPeserta = $pdo->prepare(
        "SELECT id_peserta,
                id_event,
                no_urut,
                nama_regu,
                asal_sekolah
           FROM tabel_peserta
          WHERE id_event = :id_event
          ORDER BY no_urut ASC"
    );
    $stmtPeserta->execute([':id_event' => $id_event]);
    $daftarPeserta = $stmtPeserta->fetchAll();

    // Cast tipe data numerik
    foreach ($daftarPeserta as &$p) {
        $p['id_peserta'] = (int) $p['id_peserta'];
        $p['id_event']   = (int) $p['id_event'];
        $p['no_urut']    = (int) $p['no_urut'];
    }
    unset($p);

    // ── Query: Daftar Juri ────────────────────────────────────
    //
    //   Strategi dua sumber:
    //   1. Utama   → tabel_juri    (panel juri per event, ada id_event)
    //   2. Fallback → tabel_users  (akun login role='juri', id_event di kolom users)
    //
    //   Jika tabel_juri kosong untuk event ini, otomatis ambil dari tabel_users.
    //   Ini menangani kasus saat juri diisi lewat tabel_users saja (setup_users.php)
    //   tanpa populate tabel_juri terpisah.
    //
    $stmtJuri = $pdo->prepare(
        "SELECT id_juri,
                id_event,
                nama_juri,
                kode_juri
           FROM tabel_juri
          WHERE id_event = :id_event
          ORDER BY kode_juri ASC"
    );
    $stmtJuri->execute([':id_event' => $id_event]);
    $daftarJuri = $stmtJuri->fetchAll();

    // ── Fallback: tabel_users jika tabel_juri kosong ──────────
    if (empty($daftarJuri)) {
        // Cek apakah tabel_users punya kolom id_event
        $hasIdEvent = false;
        try {
            $colCheck = $pdo->query("SHOW COLUMNS FROM tabel_users LIKE 'id_event'");
            $hasIdEvent = ($colCheck->rowCount() > 0);
        } catch (PDOException $ignored) {}

        if ($hasIdEvent) {
            // Ada kolom id_event → filter per event
            $stmtJuriUser = $pdo->prepare(
                "SELECT id_user   AS id_juri,
                        id_event,
                        nama_lengkap AS nama_juri,
                        username     AS kode_juri
                   FROM tabel_users
                  WHERE role     = 'juri'
                    AND aktif    = 1
                    AND (id_event = :id_event OR id_event IS NULL)
                  ORDER BY username ASC"
            );
            $stmtJuriUser->execute([':id_event' => $id_event]);
        } else {
            // Tidak ada kolom id_event → ambil semua juri
            $stmtJuriUser = $pdo->query(
                "SELECT id_user   AS id_juri,
                        NULL      AS id_event,
                        nama_lengkap AS nama_juri,
                        username     AS kode_juri
                   FROM tabel_users
                  WHERE role  = 'juri'
                    AND aktif = 1
                  ORDER BY username ASC"
            );
        }
        $daftarJuri = $stmtJuriUser->fetchAll();
    }

    foreach ($daftarJuri as &$j) {
        $j['id_juri']  = (int) $j['id_juri'];
        $j['id_event'] = isset($j['id_event']) ? (int) $j['id_event'] : $id_event;
    }
    unset($j);

    // ── Query: Struktur Kriteria (JOIN Kategori) ──────────────
    // Dikelompokkan per kategori → setiap kategori memiliki array "kriteria"
    $stmtKriteria = $pdo->prepare(
        "SELECT
             kat.id_kategori,
             kat.kode_kategori,
             kat.nama_kategori,
             kat.urutan        AS urutan_kategori,
             kat.omr_zone_json,
             kr.id_kriteria,
             kr.urutan         AS urutan_kriteria,
             kr.nama_gerakan,
             kr.sub_kriteria,
             kr.opsi_nilai_json,
             kr.nilai_min,
             kr.nilai_max
           FROM tabel_kriteria kr
           JOIN tabel_kategori kat ON kat.id_kategori = kr.id_kategori
          WHERE kat.id_event = :id_event
          ORDER BY kat.urutan ASC, kr.urutan ASC"
    );
    $stmtKriteria->execute([':id_event' => $id_event]);
    $rawKriteria = $stmtKriteria->fetchAll();

    // ── Susun struktur hierarki: kategori → [kriteria] ────────
    $strukturKriteria = [];
    $mapKategori      = [];

    foreach ($rawKriteria as $row) {
        $idKat = (int) $row['id_kategori'];

        // Inisialisasi kategori jika belum ada
        if (!isset($mapKategori[$idKat])) {
            // Decode omr_zone_json → array PHP (null jika kolom belum ada)
            $omrZone = isset($row['omr_zone_json'])
                ? json_decode($row['omr_zone_json'], true)
                : null;

            $mapKategori[$idKat] = [
                'id_kategori'    => $idKat,
                'kode_kategori'  => $row['kode_kategori'],
                'nama_kategori'  => $row['nama_kategori'],
                'urutan'         => (int) $row['urutan_kategori'],
                'omr_zone_json'  => $omrZone,   // ← zona OMR untuk JS
                'kriteria'       => []
            ];
        }

        // Decode opsi_nilai_json → array PHP (label+nilai saja, tanpa koordinat)
        $opsiNilai = json_decode($row['opsi_nilai_json'], true);

        $mapKategori[$idKat]['kriteria'][] = [
            'id_kriteria'    => (int) $row['id_kriteria'],
            'urutan'         => (int) $row['urutan_kriteria'],
            'nama_gerakan'   => $row['nama_gerakan'],
            'sub_kriteria'   => $row['sub_kriteria'],
            'opsi_nilai'     => $opsiNilai,
            'nilai_min'      => (float) $row['nilai_min'],
            'nilai_max'      => (float) $row['nilai_max']
        ];
    }

    // Reset index array menjadi 0-based
    $strukturKriteria = array_values($mapKategori);

    // ── Susun & Kirim Response ────────────────────────────────
    http_response_code(200);
    echo json_encode([
        'status' => 'success',
        'code'   => 200,
        'data'   => [
            'event'             => $event,
            'daftar_peserta'    => $daftarPeserta,
            'daftar_juri'       => $daftarJuri,
            'struktur_kriteria' => $strukturKriteria
        ],
        // Info jumlah baris — berguna saat debug data kosong
        '_counts' => [
            'peserta'  => count($daftarPeserta),
            'juri'     => count($daftarJuri),
            'kategori' => count($strukturKriteria),
            'kriteria' => array_sum(array_map(fn($k) => count($k['kriteria']), $strukturKriteria)),
        ]
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'status'  => 'error',
        'code'    => 500,
        'message' => 'Terjadi kesalahan pada server database.',
        // 'debug' => $e->getMessage() // Aktifkan hanya saat development
    ]);
}
