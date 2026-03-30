<?php
/**
 * proses_scan_ai.php
 * ============================================================
 * Endpoint AI Scanner — Paskibra SaaS
 * ============================================================
 * Method : POST (multipart/form-data)
 * Input  : gambar (file), id_peserta, id_juri, id_event
 * Output : JSON { status, data: { hasil, jumlah_cocok, ... } }
 *
 * Model  : gemini-2.5-flash
 * Paksa JSON murni via responseMimeType: application/json
 * Ekstraksi JSON berlapis (regex + manual strip)
 * ============================================================
 */

declare(strict_types=1);

// ── Headers ──────────────────────────────────────────────────
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Requested-With');
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST')    { jsonError(405, 'Method not allowed.'); }

// ── Konfigurasi ───────────────────────────────────────────────
require_once __DIR__ . '/koneksi.php';   // → $pdo

/**
 * Ganti dengan API key Gemini Anda, atau set environment variable:
 *   putenv("GEMINI_API_KEY=AIza...");
 * Dapatkan di: https://aistudio.google.com/apikey
 */
define('GEMINI_API_KEY', getenv('GEMINI_API_KEY') ?: 'AIzaSyBimKH3LpXCs8BGv6nf-029aXTEU6R0q8c');
define('GEMINI_MODEL',   'gemini-2.5-flash');   // model terbaru, lebih cepat & akurat
define('GEMINI_ENDPOINT',
    'https://generativelanguage.googleapis.com/v1beta/models/'
    . GEMINI_MODEL
    . ':generateContent?key='
    . GEMINI_API_KEY
);

// ── Helper functions ─────────────────────────────────────────
function jsonSuccess(array $data): never {
    echo json_encode(['status' => 'success', 'data' => $data], JSON_UNESCAPED_UNICODE);
    exit;
}
function jsonError(int $code, string $msg, array $extra = []): never {
    http_response_code($code);
    echo json_encode(
        array_merge(['status' => 'error', 'code' => $code, 'message' => $msg], $extra),
        JSON_UNESCAPED_UNICODE
    );
    exit;
}

// ── Validasi input ────────────────────────────────────────────
$idEvent   = filter_input(INPUT_POST, 'id_event',   FILTER_VALIDATE_INT) ?: 1;
$idPeserta = filter_input(INPUT_POST, 'id_peserta', FILTER_VALIDATE_INT);
$idJuri    = filter_input(INPUT_POST, 'id_juri',    FILTER_VALIDATE_INT);

if (!isset($_FILES['gambar']) || $_FILES['gambar']['error'] !== UPLOAD_ERR_OK) {
    jsonError(400, 'File gambar tidak ditemukan atau terjadi error saat upload.');
}

$file     = $_FILES['gambar'];
$filePath = $file['tmp_name'];
$mimeType = mime_content_type($filePath);

$allowedMimes = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
if (!in_array($mimeType, $allowedMimes, true)) {
    jsonError(400, "Tipe file tidak didukung: {$mimeType}. Gunakan JPG, PNG, atau WEBP.");
}

// Batas ukuran: 4 MB (Gemini inline limit)
if ($file['size'] > 4 * 1024 * 1024) {
    jsonError(400, 'Ukuran gambar melebihi batas 4 MB. Kompres gambar terlebih dahulu.');
}

// ── Encode gambar ke base64 ───────────────────────────────────
$imageBase64 = base64_encode(file_get_contents($filePath));

// ── Ambil daftar kriteria dari DB ─────────────────────────────
try {
    $stmt = $pdo->prepare(
        "SELECT
             kr.id_kriteria,
             kr.nama_gerakan,
             kr.opsi_nilai_json,
             kr.nilai_min,
             kr.nilai_max,
             kat.nama_kategori,
             kat.kode_kategori
         FROM tabel_kriteria kr
         JOIN tabel_kategori kat ON kat.id_kategori = kr.id_kategori
         WHERE kat.id_event = :ie
         ORDER BY kat.urutan ASC, kr.urutan ASC"
    );
    $stmt->execute([':ie' => $idEvent]);
    $rawKriteria = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    jsonError(500, 'Gagal mengambil data kriteria dari database.');
}

if (empty($rawKriteria)) {
    jsonError(404, "Tidak ada kriteria untuk id_event={$idEvent}. Jalankan generate_data_asli.php.");
}

// ── Bangun lookup & daftar gerakan untuk prompt ───────────────
$lookup        = [];
$daftarGerakan = [];

foreach ($rawKriteria as $row) {
    $opsiData = json_decode($row['opsi_nilai_json'], true);
    $nilaiArr = $opsiData['nilai'] ?? [];
    $labelArr = $opsiData['label'] ?? [];

    $opsiStr = [];
    foreach ($nilaiArr as $idx => $val) {
        $lbl       = $labelArr[$idx] ?? 'Opsi' . ($idx + 1);
        $opsiStr[] = "{$lbl}={$val}";
    }

    $key          = strtolower(trim($row['nama_gerakan']));
    $lookup[$key] = [
        'id_kriteria' => (int)   $row['id_kriteria'],
        'nama'        =>         $row['nama_gerakan'],
        'nilai_valid' => $nilaiArr,
        'nilai_min'   => (float) $row['nilai_min'],
        'nilai_max'   => (float) $row['nilai_max'],
    ];

    $daftarGerakan[] = "- {$row['nama_gerakan']} [" . implode(', ', $opsiStr) . "]";
}

$daftarGerakanStr = implode("\n", $daftarGerakan);

// ── Susun prompt untuk Gemini ─────────────────────────────────
$systemPrompt = <<<PROMPT
Anda adalah asisten pembaca form nilai Paskibra (Pasukan Pengibar Bendera).
Saya akan memberikan gambar form penilaian yang diisi oleh juri.

Tugas Anda adalah mengekstrak nilai yang DICORET atau DILINGKARI oleh juri
untuk setiap nama gerakan yang tercantum dalam form.

Berikut adalah daftar LENGKAP gerakan beserta opsi nilainya yang valid:
{$daftarGerakanStr}

ATURAN PENTING:
1. Kembalikan HANYA nilai yang benar-benar terlihat dicoret/dilingkari di form.
2. Nilai yang dikembalikan HARUS salah satu dari opsi nilai valid untuk gerakan tersebut.
3. Jika suatu gerakan tidak jelas atau tidak terdeteksi coretannya, JANGAN sertakan.
4. Gunakan nama gerakan PERSIS seperti yang tertulis dalam daftar di atas.
5. Jangan berikan teks apapun selain JSON array.

Contoh output:
[
  {"gerakan": "BERSHAF KUMPUL", "nilai": 25},
  {"gerakan": "SIKAP SEMPURNA", "nilai": 7}
]

PENTING: Pastikan Anda menyelesaikan seluruh data hingga akhir dan menutup JSON array dengan benar menggunakan kurung siku ']'. JANGAN biarkan respons terpotong di tengah jalan.
PROMPT;

// ── Bangun request body Gemini ────────────────────────────────
$requestBody = [
    'contents' => [
        [
            'parts' => [
                ['text' => $systemPrompt],
                [
                    'inline_data' => [
                        'mime_type' => $mimeType,
                        'data'      => $imageBase64,
                    ],
                ],
            ],
        ],
    ],
    'generationConfig' => [
        'temperature'      => 0.1,               // rendah = deterministik & akurat
        'topP'             => 0.8,
        'maxOutputTokens'  => 8192,              // dinaikkan: cegah output terpotong
        'responseMimeType' => 'application/json', // paksa Gemini kembalikan JSON murni
    ],
];

// ── Kirim ke Gemini via cURL ──────────────────────────────────
$ch = curl_init(GEMINI_ENDPOINT);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => json_encode($requestBody),
    CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
    CURLOPT_TIMEOUT        => 120,      // dinaikkan ke 2 menit: cegah putus saat AI mengetik
    CURLOPT_SSL_VERIFYPEER => true,
]);

$geminiRaw = curl_exec($ch);
$curlError = curl_error($ch);
$httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($curlError) {
    jsonError(502, "Gagal terhubung ke Gemini API: {$curlError}");
}

// ── Parse respons Gemini ──────────────────────────────────────
$geminiResp = json_decode($geminiRaw, true);

if ($httpCode !== 200) {
    $errMsg = $geminiResp['error']['message'] ?? substr($geminiRaw, 0, 300);
    jsonError(502, "Gemini API error (HTTP {$httpCode}): {$errMsg}");
}

// ── Ekstrak teks dari struktur respons ────────────────────────
// Gemini 2.x kadang memakai struktur berbeda; coba semua kemungkinan path
$geminiText = null;

// Path standar: candidates[0].content.parts[0].text
$parts = $geminiResp['candidates'][0]['content']['parts'] ?? [];
foreach ($parts as $part) {
    if (!empty($part['text'])) {
        $geminiText = $part['text'];
        break;
    }
}

// Fallback: candidates[0].text (format lama)
if ($geminiText === null) {
    $geminiText = $geminiResp['candidates'][0]['text'] ?? null;
}

// Fallback: ambil seluruh raw jika masih null (untuk debug)
if ($geminiText === null) {
    $finishReason = $geminiResp['candidates'][0]['finishReason'] ?? 'UNKNOWN';
    jsonError(502,
        "Gemini tidak menghasilkan teks (finishReason={$finishReason}). " .
        "Coba lagi atau gunakan gambar lebih jelas.",
        ['raw_candidates' => $geminiResp['candidates'][0] ?? $geminiRaw]
    );
}

$geminiText = trim($geminiText);

if ($geminiText === '') {
    jsonError(502, 'Gemini mengembalikan teks kosong. Coba lagi atau gunakan gambar berbeda.');
}

// ── Cek finishReason — deteksi output terpotong ───────────────
$finishReasonCheck = $geminiResp['candidates'][0]['finishReason'] ?? '';
if ($finishReasonCheck === 'MAX_TOKENS') {
    jsonError(422,
        'Output AI terlalu panjang dan terpotong (MAX_TOKENS). ' .
        'Silakan coba lagi — biasanya berhasil pada percobaan kedua.',
        ['finish_reason' => 'MAX_TOKENS', 'raw_partial' => substr($geminiText, 0, 300)]
    );
}

// ── Parse JSON dari teks Gemini — 4 strategi berlapis ─────────
//
//   S1: parse langsung             (responseMimeType berhasil → JSON murni)
//   S2: strip markdown ``` fence   (model tidak patuh responseMimeType)
//   S3: regex greedy  \[...\]      (ada teks sebelum/sesudah array)
//   S4: regex non-greedy \[.*?\]   (multi-array, ambil yang pertama valid)
//
$geminiData = null;

// S1 — parse langsung
$geminiData = json_decode($geminiText, true);

// S2 — strip semua variasi markdown code fence
if (!is_array($geminiData)) {
    $s2 = preg_replace('/^```+(?:json)?\s*/i', '', $geminiText);
    $s2 = preg_replace('/\s*```+\s*$/i',        '', $s2);
    $geminiData = json_decode(trim($s2), true);
}

// S3 — ekstrak JSON array dengan regex greedy (menangkap multi-line)
if (!is_array($geminiData)) {
    if (preg_match('/(\[[\s\S]*\])/s', $geminiText, $m)) {
        $geminiData = json_decode($m[1], true);
    }
}

// S4 — coba setiap kandidat array yang ditemukan, pakai yang pertama valid
if (!is_array($geminiData)) {
    preg_match_all('/(\[[^\[\]]*\])/s', $geminiText, $allMatches);
    foreach ($allMatches[1] as $candidate) {
        $try = json_decode($candidate, true);
        if (is_array($try)) { $geminiData = $try; break; }
    }
}

if (!is_array($geminiData)) {
    // Kembalikan raw_response agar mudah di-debug dari browser / Postman
    jsonError(422, 'Semua strategi parsing gagal. Lihat raw_response untuk investigasi.', [
        'raw_response'  => $geminiText,
        'json_last_error' => json_last_error_msg(),
    ]);
}

// Jika Gemini mengembalikan array kosong (tidak ada yang dicoret)
if (empty($geminiData)) {
    jsonSuccess([
        'hasil'         => [],
        'jumlah_cocok'  => 0,
        'jumlah_total'  => count($rawKriteria),
        'tidak_dikenal' => [],
        'model'         => GEMINI_MODEL,
        'pesan'         => 'Tidak ada coretan terdeteksi pada gambar.',
    ]);
}

// ── Cocokkan hasil Gemini dengan data DB ──────────────────────
$hasilCocok   = [];
$tidakDikenal = [];

foreach ($geminiData as $item) {
    if (!isset($item['gerakan'], $item['nilai'])) continue;

    $namaGemini = trim($item['gerakan']);
    $nilaiInput = is_numeric($item['nilai']) ? (float) $item['nilai'] : null;

    if ($nilaiInput === null) continue;

    // Cari di lookup: exact match (case-insensitive)
    $key    = strtolower($namaGemini);
    $krData = $lookup[$key] ?? null;

    // Fallback: fuzzy substring match
    if (!$krData) {
        foreach ($lookup as $lkKey => $lkVal) {
            if (str_contains($lkKey, $key) || str_contains($key, $lkKey)) {
                $krData = $lkVal;
                break;
            }
        }
    }

    if (!$krData) {
        $tidakDikenal[] = $namaGemini;
        continue;
    }

    // Validasi & snap ke nilai valid terdekat (toleransi ≤ 0.5)
    $nilaiTerdekat = null;
    $selisihMin    = PHP_FLOAT_MAX;

    foreach ($krData['nilai_valid'] as $v) {
        $selisih = abs((float) $v - $nilaiInput);
        if ($selisih < $selisihMin) {
            $selisihMin    = $selisih;
            $nilaiTerdekat = (float) $v;
        }
    }

    if ($selisihMin > 0.5) {
        $tidakDikenal[] = "{$namaGemini} (nilai {$nilaiInput} tidak valid)";
        continue;
    }

    $hasilCocok[] = [
        'id_kriteria' => $krData['id_kriteria'],
        'nama'        => $krData['nama'],
        'nilai'       => $nilaiTerdekat,
        'nilai_min'   => $krData['nilai_min'],
        'nilai_max'   => $krData['nilai_max'],
    ];
}

// ── Kembalikan hasil ──────────────────────────────────────────
jsonSuccess([
    'hasil'         => $hasilCocok,
    'jumlah_cocok'  => count($hasilCocok),
    'jumlah_total'  => count($rawKriteria),
    'tidak_dikenal' => $tidakDikenal,
    'model'         => GEMINI_MODEL,
]);
