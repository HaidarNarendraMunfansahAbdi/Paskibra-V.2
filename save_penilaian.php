<?php
/**
 * save_penilaian.php
 * ============================================================
 * Endpoint : POST /save_penilaian.php
 * Content-Type: application/json
 *
 * Payload JSON yang diharapkan:
 * {
 *   "id_peserta"   : 1,
 *   "id_juri"      : 2,
 *   "metode_input" : "manual",   // "scan" | "manual"
 *   "data_nilai"   : [
 *     { "id_kriteria": 1, "nilai_diperoleh": 22 },
 *     { "id_kriteria": 2, "nilai_diperoleh": 5  },
 *     ...
 *   ]
 * }
 *
 * Strategi INSERT: INSERT ... ON DUPLICATE KEY UPDATE
 * sehingga bisa dipakai untuk insert baru maupun update (upsert).
 * Constraint UNIQUE pada tabel_penilaian: (id_peserta, id_juri, id_kriteria)
 * ============================================================
 */

// ── CORS & Headers ───────────────────────────────────────────
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Requested-With');
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// ── Hanya izinkan POST ───────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'code' => 405, 'message' => 'Method not allowed.']);
    exit;
}

// ── Koneksi Database ─────────────────────────────────────────
require_once __DIR__ . '/api_auth.php';   // ← JSON-only session guard
api_guard(['admin', 'juri']);             // 401 JSON jika belum login
require_once __DIR__ . '/koneksi.php';

// ── Baca & Decode Payload JSON ───────────────────────────────
$rawBody = file_get_contents('php://input');
$payload = json_decode($rawBody, true);

if (json_last_error() !== JSON_ERROR_NONE || !is_array($payload)) {
    http_response_code(400);
    echo json_encode([
        'status'  => 'error',
        'code'    => 400,
        'message' => 'Body request harus berupa JSON yang valid.'
    ]);
    exit;
}

// ── Sanitasi & Validasi Field Utama ──────────────────────────
$id_peserta    = isset($payload['id_peserta'])   ? filter_var($payload['id_peserta'],   FILTER_VALIDATE_INT) : false;
$id_juri       = isset($payload['id_juri'])      ? filter_var($payload['id_juri'],      FILTER_VALIDATE_INT) : false;
$metode_input  = isset($payload['metode_input']) ? trim($payload['metode_input'])                            : '';
$data_nilai    = isset($payload['data_nilai'])   ? $payload['data_nilai']                                    : null;

$errors = [];

if (!$id_peserta || $id_peserta <= 0) {
    $errors[] = 'id_peserta wajib diisi dan harus bilangan bulat positif.';
}
if (!$id_juri || $id_juri <= 0) {
    $errors[] = 'id_juri wajib diisi dan harus bilangan bulat positif.';
}
if (!in_array($metode_input, ['scan', 'manual'], true)) {
    $errors[] = 'metode_input harus bernilai "scan" atau "manual".';
}
if (!is_array($data_nilai) || count($data_nilai) === 0) {
    $errors[] = 'data_nilai wajib berupa array dan tidak boleh kosong.';
}

if (!empty($errors)) {
    http_response_code(422);
    echo json_encode([
        'status'  => 'error',
        'code'    => 422,
        'message' => 'Validasi gagal.',
        'errors'  => $errors
    ]);
    exit;
}

// ── Validasi Setiap Baris data_nilai ─────────────────────────
$barisTersanitasi = [];

foreach ($data_nilai as $index => $item) {
    $id_kriteria     = isset($item['id_kriteria'])    ? filter_var($item['id_kriteria'],    FILTER_VALIDATE_INT)   : false;
    $nilai_diperoleh = isset($item['nilai_diperoleh']) ? filter_var($item['nilai_diperoleh'], FILTER_VALIDATE_FLOAT) : false;

    if ($id_kriteria === false || $id_kriteria <= 0) {
        $errors[] = "data_nilai[{$index}]: id_kriteria tidak valid.";
        continue;
    }
    if ($nilai_diperoleh === false || $nilai_diperoleh < 0) {
        $errors[] = "data_nilai[{$index}]: nilai_diperoleh tidak valid (harus angka >= 0).";
        continue;
    }

    $barisTersanitasi[] = [
        'id_kriteria'    => (int)   $id_kriteria,
        'nilai_diperoleh'=> (float) $nilai_diperoleh
    ];
}

if (!empty($errors)) {
    http_response_code(422);
    echo json_encode([
        'status'  => 'error',
        'code'    => 422,
        'message' => 'Validasi item data_nilai gagal.',
        'errors'  => $errors
    ]);
    exit;
}

// ── Pastikan id_peserta & id_juri Exist di Database ──────────
try {
    $stmtCekPeserta = $pdo->prepare(
        "SELECT id_peserta FROM tabel_peserta WHERE id_peserta = :id LIMIT 1"
    );
    $stmtCekPeserta->execute([':id' => $id_peserta]);
    if (!$stmtCekPeserta->fetch()) {
        http_response_code(404);
        echo json_encode([
            'status'  => 'error',
            'code'    => 404,
            'message' => "Peserta dengan id_peserta={$id_peserta} tidak ditemukan."
        ]);
        exit;
    }

    $stmtCekJuri = $pdo->prepare(
        "SELECT id_juri FROM tabel_juri WHERE id_juri = :id LIMIT 1"
    );
    $stmtCekJuri->execute([':id' => $id_juri]);
    if (!$stmtCekJuri->fetch()) {
        http_response_code(404);
        echo json_encode([
            'status'  => 'error',
            'code'    => 404,
            'message' => "Juri dengan id_juri={$id_juri} tidak ditemukan."
        ]);
        exit;
    }

    // ── Transaction: Batch INSERT dengan ON DUPLICATE KEY UPDATE ──
    // Strategi UPSERT: jika juri menilai ulang, nilai diperbarui.
    $waktu_input = date('Y-m-d H:i:s');

    $stmtInsert = $pdo->prepare(
        "INSERT INTO tabel_penilaian
             (id_peserta, id_juri, id_kriteria, nilai_diperoleh, metode_input, waktu_input)
         VALUES
             (:id_peserta, :id_juri, :id_kriteria, :nilai_diperoleh, :metode_input, :waktu_input)
         ON DUPLICATE KEY UPDATE
             nilai_diperoleh = VALUES(nilai_diperoleh),
             metode_input    = VALUES(metode_input),
             waktu_input     = VALUES(waktu_input)"
    );

    $pdo->beginTransaction();

    $jumlahBerhasil = 0;

    foreach ($barisTersanitasi as $baris) {
        $stmtInsert->execute([
            ':id_peserta'     => $id_peserta,
            ':id_juri'        => $id_juri,
            ':id_kriteria'    => $baris['id_kriteria'],
            ':nilai_diperoleh'=> $baris['nilai_diperoleh'],
            ':metode_input'   => $metode_input,
            ':waktu_input'    => $waktu_input
        ]);
        $jumlahBerhasil++;
    }

    $pdo->commit();

    // ── Response Sukses ───────────────────────────────────────
    http_response_code(200);
    echo json_encode([
        'status'  => 'success',
        'code'    => 200,
        'message' => 'Penilaian berhasil disimpan.',
        'data'    => [
            'id_peserta'      => $id_peserta,
            'id_juri'         => $id_juri,
            'metode_input'    => $metode_input,
            'waktu_input'     => $waktu_input,
            'jumlah_kriteria' => $jumlahBerhasil
        ]
    ], JSON_UNESCAPED_UNICODE);

} catch (PDOException $e) {
    // Rollback jika terjadi error di tengah transaction
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    echo json_encode([
        'status'  => 'error',
        'code'    => 500,
        'message' => 'Terjadi kesalahan saat menyimpan data. Semua perubahan dibatalkan.',
        // 'debug' => $e->getMessage()
    ]);
}
