<?php
/**
 * proses_scan_kriteria.php
 * ============================================================
 * Endpoint: POST /proses_scan_kriteria.php
 * Dipanggil oleh: manajemen_kriteria.php -> modal Auto-Scan AI
 *
 * Input (multipart/form-data):
 *   gambar   - file gambar blanko (JPG/PNG/WEBP, maks 4 MB)
 *   id_event - int (opsional, saat ini belum dipakai di prompt)
 *
 * Output JSON:
 * {
 *   "status": "success",
 *   "data": [
 *     {"nama_kriteria": "SIKAP SEMPURNA", "nilai_maksimal": 100},
 *     {"nama_kriteria": "BERHITUNG", "nilai_maksimal": 80}
 *   ]
 * }
 * ============================================================
 */

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Requested-With');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

ini_set('display_errors', '0');
error_reporting(0);

set_exception_handler(function (Throwable $e): void {
    http_response_code(500);
    echo json_encode([
        'status'  => 'error',
        'code'    => 500,
        'message' => 'Server error: ' . $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
    exit;
});

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'status'  => 'error',
        'code'    => 405,
        'message' => 'Method not allowed.',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

require_once __DIR__ . '/api_auth.php';
api_guard('admin');

require_once __DIR__ . '/koneksi.php';

$api_key = 'AIzaSyBimKH3LpXCs8BGv6nf-029aXTEU6R0q8c';

if ($api_key === '' || $api_key === 'TARUH_API_KEY_SAYA_DISINI') {
    http_response_code(500);
    echo json_encode([
        'status'  => 'error',
        'code'    => 500,
        'message' => 'API Key Gemini belum diisi di proses_scan_kriteria.php.',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!isset($_FILES['gambar']) || !is_array($_FILES['gambar'])) {
    http_response_code(400);
    echo json_encode([
        'status'  => 'error',
        'code'    => 400,
        'message' => 'File gambar tidak ditemukan pada request.',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$file = $_FILES['gambar'];
$uploadError = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);

if ($uploadError !== UPLOAD_ERR_OK) {
    $errMap = [
        UPLOAD_ERR_INI_SIZE   => 'File melebihi upload_max_filesize di php.ini.',
        UPLOAD_ERR_FORM_SIZE  => 'File melebihi batas ukuran form.',
        UPLOAD_ERR_PARTIAL    => 'File hanya terunggah sebagian.',
        UPLOAD_ERR_NO_FILE    => 'Tidak ada file yang diunggah.',
        UPLOAD_ERR_NO_TMP_DIR => 'Folder temporary server tidak ditemukan.',
        UPLOAD_ERR_CANT_WRITE => 'Server gagal menulis file ke disk.',
        UPLOAD_ERR_EXTENSION  => 'Upload dihentikan oleh ekstensi PHP.',
    ];

    http_response_code(400);
    echo json_encode([
        'status'  => 'error',
        'code'    => 400,
        'message' => $errMap[$uploadError] ?? ('Upload error code: ' . $uploadError),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$tmpPath  = (string)($file['tmp_name'] ?? '');
$origName = basename((string)($file['name'] ?? 'scan'));
$fileSize = (int)($file['size'] ?? 0);

if ($tmpPath === '' || !is_uploaded_file($tmpPath)) {
    http_response_code(400);
    echo json_encode([
        'status'  => 'error',
        'code'    => 400,
        'message' => 'Upload file tidak valid.',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($fileSize <= 0) {
    http_response_code(400);
    echo json_encode([
        'status'  => 'error',
        'code'    => 400,
        'message' => 'Ukuran file tidak valid.',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($fileSize > 4 * 1024 * 1024) {
    http_response_code(400);
    echo json_encode([
        'status'  => 'error',
        'code'    => 400,
        'message' => 'Ukuran file melebihi batas maksimal 4 MB.',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$mimeType = mime_content_type($tmpPath) ?: '';
$allowedMimes = ['image/jpeg', 'image/png', 'image/webp'];

if (!in_array($mimeType, $allowedMimes, true)) {
    http_response_code(400);
    echo json_encode([
        'status'  => 'error',
        'code'    => 400,
        'message' => 'Format file tidak didukung. Gunakan JPG, PNG, atau WEBP.',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$imageBinary = file_get_contents($tmpPath);
if ($imageBinary === false) {
    http_response_code(500);
    echo json_encode([
        'status'  => 'error',
        'code'    => 500,
        'message' => 'Gagal membaca file upload.',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$imageBase64 = base64_encode($imageBinary);

$endpoint = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-1.5-flash:generateContent?key='
    . rawurlencode($api_key);

$prompt = 'Anda adalah asisten ekstraksi data. Baca gambar kertas blanko penilaian ini. '
    . 'Ekstrak setiap baris kriteria penilaian dan nilai maksimalnya (jika ada, jika tidak ada asumsikan 100). '
    . 'Kembalikan hasilnya HANYA dalam format array JSON murni tanpa markdown, dengan struktur seperti ini: '
    . '[{"nama_kriteria": "Nama Gerakan", "nilai_maksimal": 100}]. '
    . 'Jangan tambahkan teks apa pun selain JSON tersebut.';

$payload = [
    'contents' => [[
        'parts' => [
            ['text' => $prompt],
            [
                'inline_data' => [
                    'mime_type' => $mimeType,
                    'data'      => $imageBase64,
                ],
            ],
        ],
    ]],
    'generationConfig' => [
        'temperature'      => 0.1,
        'maxOutputTokens'  => 4096,
        'responseMimeType' => 'application/json',
    ],
];

$ch = curl_init($endpoint);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
    CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
    CURLOPT_TIMEOUT        => 120,
    CURLOPT_SSL_VERIFYPEER => true,
]);

$rawResponse = curl_exec($ch);
$curlError   = curl_error($ch);
$httpCode    = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($rawResponse === false || $curlError !== '') {
    http_response_code(502);
    echo json_encode([
        'status'  => 'error',
        'code'    => 502,
        'message' => 'Gagal terhubung ke Gemini API: ' . $curlError,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$geminiResponse = json_decode($rawResponse, true);
if (!is_array($geminiResponse)) {
    http_response_code(502);
    echo json_encode([
        'status'  => 'error',
        'code'    => 502,
        'message' => 'Respons Gemini tidak valid.',
        'raw'     => substr($rawResponse, 0, 500),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($httpCode >= 400) {
    $apiMessage = $geminiResponse['error']['message'] ?? 'Gemini API mengembalikan error.';
    http_response_code(502);
    echo json_encode([
        'status'  => 'error',
        'code'    => 502,
        'message' => $apiMessage,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$responseText = $geminiResponse['candidates'][0]['content']['parts'][0]['text'] ?? null;
if (!is_string($responseText) || trim($responseText) === '') {
    http_response_code(502);
    echo json_encode([
        'status'  => 'error',
        'code'    => 502,
        'message' => 'Gemini tidak mengembalikan teks hasil ekstraksi.',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$responseText = trim($responseText);
$responseText = preg_replace('/^```(?:json)?\s*/i', '', $responseText) ?? $responseText;
$responseText = preg_replace('/\s*```$/i', '', $responseText) ?? $responseText;
$responseText = trim($responseText);

$resultData = json_decode($responseText, true);

if (!is_array($resultData) && preg_match('/(\[[\s\S]*\])/u', $responseText, $match)) {
    $resultData = json_decode($match[1], true);
}

if (!is_array($resultData)) {
    http_response_code(422);
    echo json_encode([
        'status'  => 'error',
        'code'    => 422,
        'message' => 'Output JSON dari Gemini tidak bisa diparsing.',
        'raw'     => substr($responseText, 0, 500),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$normalized = [];
foreach ($resultData as $row) {
    if (!is_array($row)) {
        continue;
    }

    $namaKriteria = trim((string)($row['nama_kriteria'] ?? $row['nama_gerakan'] ?? ''));
    $nilaiMaksimalRaw = $row['nilai_maksimal'] ?? 100;

    if ($namaKriteria === '') {
        continue;
    }

    if (is_string($nilaiMaksimalRaw)) {
        $nilaiMaksimalRaw = str_replace(',', '.', $nilaiMaksimalRaw);
    }

    $nilaiMaksimal = is_numeric($nilaiMaksimalRaw) ? (float) $nilaiMaksimalRaw : 100.0;

    $normalized[] = [
        'nama_kriteria'  => $namaKriteria,
        'nilai_maksimal' => $nilaiMaksimal,
    ];
}

if (empty($normalized)) {
    http_response_code(422);
    echo json_encode([
        'status'  => 'error',
        'code'    => 422,
        'message' => 'Gemini tidak menghasilkan data kriteria yang valid.',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

echo json_encode([
    'status' => 'success',
    'data'   => $normalized,
], JSON_UNESCAPED_UNICODE);
