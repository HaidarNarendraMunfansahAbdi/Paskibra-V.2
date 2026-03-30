<?php
/**
 * manajemen_kriteria.php
 * ============================================================
 * Kelola Master Data Kriteria Penilaian — Admin Only
 * CRUD manual (kategori + kriteria) + modal Auto-Scan AI
 * ============================================================
 * Struktur tabel:
 *   tabel_kategori : id_kategori, id_event, urutan, kode_kategori, nama_kategori
 *   tabel_kriteria : id_kriteria, id_kategori, urutan, nama_gerakan,
 *                    opsi_nilai_json, nilai_min, nilai_max
 *   opsi_nilai_json: {"label":["SK",...,"A"], "nilai":[10,...,28],
 *                     "opsi":[{"nilai":10,"label":"SK",...},…]}
 * ============================================================
 */
declare(strict_types=1);

require_once __DIR__ . '/auth_guard.php';
authGuard('admin');
$me = currentUser();
require_once __DIR__ . '/koneksi.php';

// ── Parameter ─────────────────────────────────────────────────
$idEvent = filter_input(INPUT_GET,  'id_event', FILTER_VALIDATE_INT)
        ?: filter_input(INPUT_POST, 'id_event', FILTER_VALIDATE_INT)
        ?: ($me['id_event'] ?? 1);

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

// ── Helper: encode opsi_nilai_json dari string nilai ─────────
// Input: "10,13,16,19,22,25,28" + "SK,K,C,CB,B,SB,A"
// Output: JSON string siap simpan ke DB
function buildOpsiJson(string $nilaiStr, string $labelStr): ?string
{
    $nilaiArr = array_map('floatval', array_filter(
        array_map('trim', explode(',', $nilaiStr)),
        fn($v) => is_numeric($v)
    ));
    $labelArr = array_filter(
        array_map('trim', explode(',', $labelStr))
    );

    if (empty($nilaiArr)) return null;

    // Jika label kurang, buat label default: Opsi1, Opsi2, ...
    $labels = array_values($labelArr);
    foreach ($nilaiArr as $i => $_) {
        if (!isset($labels[$i])) $labels[$i] = 'Opsi' . ($i + 1);
    }

    $opsi = [];
    foreach ($nilaiArr as $i => $v) {
        $opsi[] = ['nilai' => $v, 'label' => $labels[$i]];
    }

    return json_encode([
        'label' => $labels,
        'nilai' => array_values($nilaiArr),
        'opsi'  => $opsi,
    ], JSON_UNESCAPED_UNICODE);
}

// ════════════════════════════════════════════════════════════════
//  PROSES CRUD
// ════════════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $aksi = trim($_POST['aksi'] ?? '');

    $redir = function(string $type, string $msg) use ($idEvent): void {
        $_SESSION['flash'] = ['type' => $type, 'msg' => $msg];
        header("Location: manajemen_kriteria.php?id_event={$idEvent}");
        exit;
    };

    // ══ KATEGORI ══════════════════════════════════════════════

    // Tambah kategori
    if ($aksi === 'tambah_kategori') {
        $kode  = strtoupper(trim($_POST['kode_kategori'] ?? ''));
        $nama  = trim($_POST['nama_kategori'] ?? '');
        $urut  = filter_input(INPUT_POST, 'urutan', FILTER_VALIDATE_INT) ?: 0;
        if ($nama === '') { $redir('danger', 'Nama kategori wajib diisi.'); }
        try {
            $s = $pdo->prepare("INSERT INTO tabel_kategori (id_event,urutan,kode_kategori,nama_kategori)
                                 VALUES(:ie,:ur,:kd,:nm)");
            $s->execute([':ie'=>$idEvent,':ur'=>$urut,':kd'=>($kode?:null),':nm'=>$nama]);
            $redir('success', "Kategori <strong>".htmlspecialchars($nama)."</strong> ditambahkan.");
        } catch (PDOException $e) { $redir('danger', 'Gagal: '.$e->getMessage()); }
    }

    // Edit kategori
    elseif ($aksi === 'edit_kategori') {
        $idKat = filter_input(INPUT_POST,'id_kategori',FILTER_VALIDATE_INT);
        $kode  = strtoupper(trim($_POST['kode_kategori'] ?? ''));
        $nama  = trim($_POST['nama_kategori'] ?? '');
        $urut  = filter_input(INPUT_POST,'urutan',FILTER_VALIDATE_INT) ?: 0;
        if (!$idKat || $nama==='') { $redir('danger','Data tidak valid.'); }
        try {
            $s = $pdo->prepare("UPDATE tabel_kategori SET urutan=:ur,kode_kategori=:kd,nama_kategori=:nm
                                  WHERE id_kategori=:ik AND id_event=:ie");
            $s->execute([':ur'=>$urut,':kd'=>($kode?:null),':nm'=>$nama,':ik'=>$idKat,':ie'=>$idEvent]);
            $redir('success', "Kategori diperbarui.");
        } catch (PDOException $e) { $redir('danger', 'Gagal: '.$e->getMessage()); }
    }

    // Hapus kategori
    elseif ($aksi === 'hapus_kategori') {
        $idKat = filter_input(INPUT_POST,'id_kategori',FILTER_VALIDATE_INT);
        if (!$idKat) { $redir('danger','ID tidak valid.'); }
        try {
            $s = $pdo->prepare("DELETE FROM tabel_kategori WHERE id_kategori=:ik AND id_event=:ie");
            $s->execute([':ik'=>$idKat,':ie'=>$idEvent]);
            $redir('success', "Kategori dan semua kriterianya dihapus.");
        } catch (PDOException $e) { $redir('danger','Gagal menghapus (mungkin masih ada penilaian terkait).'); }
    }

    // ══ KRITERIA ══════════════════════════════════════════════

    // Tambah kriteria
    elseif ($aksi === 'tambah_kriteria') {
        $idKat    = filter_input(INPUT_POST,'id_kategori',FILTER_VALIDATE_INT);
        $nama     = trim($_POST['nama_gerakan'] ?? '');
        $urut     = filter_input(INPUT_POST,'urutan',FILTER_VALIDATE_INT) ?: 0;
        $nilaiStr = trim($_POST['nilai_opsi']  ?? '');
        $labelStr = trim($_POST['label_opsi']  ?? 'SK,K,C,CB,B,SB,A');
        if (!$idKat || $nama==='' || $nilaiStr==='') { $redir('danger','Kategori, nama, dan opsi nilai wajib diisi.'); }
        $opsiJson = buildOpsiJson($nilaiStr, $labelStr);
        if (!$opsiJson) { $redir('danger','Format opsi nilai tidak valid. Gunakan: 10,13,16,19,22,25,28'); }
        $nilaiArr = json_decode($opsiJson,true)['nilai'];
        try {
            $s = $pdo->prepare("INSERT INTO tabel_kriteria (id_kategori,urutan,nama_gerakan,opsi_nilai_json,nilai_min,nilai_max)
                                 VALUES(:ik,:ur,:nm,:js,:mn,:mx)");
            $s->execute([':ik'=>$idKat,':ur'=>$urut,':nm'=>$nama,':js'=>$opsiJson,
                         ':mn'=>min($nilaiArr),':mx'=>max($nilaiArr)]);
            $redir('success', "Kriteria <strong>".htmlspecialchars($nama)."</strong> ditambahkan.");
        } catch (PDOException $e) { $redir('danger','Gagal: '.$e->getMessage()); }
    }

    // Edit kriteria
    elseif ($aksi === 'edit_kriteria') {
        $idKr     = filter_input(INPUT_POST,'id_kriteria',FILTER_VALIDATE_INT);
        $idKat    = filter_input(INPUT_POST,'id_kategori',FILTER_VALIDATE_INT);
        $nama     = trim($_POST['nama_gerakan'] ?? '');
        $urut     = filter_input(INPUT_POST,'urutan',FILTER_VALIDATE_INT) ?: 0;
        $nilaiStr = trim($_POST['nilai_opsi']  ?? '');
        $labelStr = trim($_POST['label_opsi']  ?? 'SK,K,C,CB,B,SB,A');
        if (!$idKr || !$idKat || $nama==='' || $nilaiStr==='') { $redir('danger','Data tidak valid.'); }
        $opsiJson = buildOpsiJson($nilaiStr, $labelStr);
        if (!$opsiJson) { $redir('danger','Format opsi nilai tidak valid.'); }
        $nilaiArr = json_decode($opsiJson,true)['nilai'];
        try {
            $s = $pdo->prepare("UPDATE tabel_kriteria SET id_kategori=:ik,urutan=:ur,nama_gerakan=:nm,
                                   opsi_nilai_json=:js,nilai_min=:mn,nilai_max=:mx
                                 WHERE id_kriteria=:ikr");
            $s->execute([':ik'=>$idKat,':ur'=>$urut,':nm'=>$nama,':js'=>$opsiJson,
                         ':mn'=>min($nilaiArr),':mx'=>max($nilaiArr),':ikr'=>$idKr]);
            $redir('success', "Kriteria <strong>".htmlspecialchars($nama)."</strong> diperbarui.");
        } catch (PDOException $e) { $redir('danger','Gagal: '.$e->getMessage()); }
    }

    // Hapus kriteria
    elseif ($aksi === 'hapus_kriteria') {
        $idKr = filter_input(INPUT_POST,'id_kriteria',FILTER_VALIDATE_INT);
        if (!$idKr) { $redir('danger','ID tidak valid.'); }
        try {
            $sn = $pdo->prepare("SELECT nama_gerakan FROM tabel_kriteria WHERE id_kriteria=:ik LIMIT 1");
            $sn->execute([':ik'=>$idKr]);
            $nama = $sn->fetchColumn() ?: "ID #{$idKr}";
            $s = $pdo->prepare("DELETE FROM tabel_kriteria WHERE id_kriteria=:ik");
            $s->execute([':ik'=>$idKr]);
            $redir('success', "Kriteria <strong>".htmlspecialchars($nama)."</strong> dihapus.");
        } catch (PDOException $e) { $redir('danger','Gagal menghapus (ada penilaian terkait).'); }
    }

    // Simpan hasil scan AI
    elseif ($aksi === 'simpan_scan') {
        $rows = $_POST['scan_row'] ?? [];
        $idKat = filter_input(INPUT_POST,'scan_id_kategori',FILTER_VALIDATE_INT);
        if (!$idKat || empty($rows)) { $redir('danger','Tidak ada data scan yang dipilih.'); }
        try {
            $pdo->beginTransaction();
            $s = $pdo->prepare("INSERT INTO tabel_kriteria (id_kategori,urutan,nama_gerakan,opsi_nilai_json,nilai_min,nilai_max)
                                 VALUES(:ik,:ur,:nm,:js,:mn,:mx)");
            // Ambil urutan terakhir di kategori ini
            $lastUrut = (int)$pdo->prepare("SELECT COALESCE(MAX(urutan),0) FROM tabel_kriteria WHERE id_kategori=:ik")
                           ->execute([':ik'=>$idKat]) ? (function() use ($pdo,$idKat) {
                               $q = $pdo->prepare("SELECT COALESCE(MAX(urutan),0) FROM tabel_kriteria WHERE id_kategori=:ik");
                               $q->execute([':ik'=>$idKat]); return (int)$q->fetchColumn();
                           })() : 0;
            // versi bersih:
            $qU = $pdo->prepare("SELECT COALESCE(MAX(urutan),0) FROM tabel_kriteria WHERE id_kategori=:ik");
            $qU->execute([':ik'=>$idKat]);
            $lastUrut = (int)$qU->fetchColumn();

            $inserted = 0;
            foreach ($rows as $i => $row) {
                $nama     = trim($row['nama'] ?? '');
                $nilaiStr = trim($row['nilai'] ?? '');
                $labelStr = trim($row['label'] ?? 'SK,K,C,CB,B,SB,A');
                if ($nama==='' || $nilaiStr==='') continue;
                $opsiJson = buildOpsiJson($nilaiStr, $labelStr);
                if (!$opsiJson) continue;
                $nilaiArr = json_decode($opsiJson,true)['nilai'];
                $s->execute([':ik'=>$idKat,':ur'=>$lastUrut+$i+1,':nm'=>$nama,
                             ':js'=>$opsiJson,':mn'=>min($nilaiArr),':mx'=>max($nilaiArr)]);
                $inserted++;
            }
            $pdo->commit();
            $redir('success', "{$inserted} kriteria dari hasil scan berhasil disimpan.");
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $redir('danger','Gagal menyimpan scan: '.$e->getMessage());
        }
    }
}

// ════════════════════════════════════════════════════════════════
//  FETCH DATA
// ════════════════════════════════════════════════════════════════
$stmtEv = $pdo->prepare("SELECT nama_event FROM tabel_event WHERE id_event=:ie LIMIT 1");
$stmtEv->execute([':ie'=>$idEvent]);
$namaEvent = $stmtEv->fetchColumn() ?: 'Event #'.$idEvent;

$events = $pdo->query("SELECT id_event,nama_event FROM tabel_event WHERE status_aktif=1 ORDER BY id_event DESC")->fetchAll();

// Ambil semua kategori beserta kriterianya (JOIN)
$stmtKat = $pdo->prepare(
    "SELECT kat.id_kategori, kat.kode_kategori, kat.nama_kategori, kat.urutan AS urutan_kat,
            kr.id_kriteria, kr.urutan AS urutan_kr, kr.nama_gerakan,
            kr.opsi_nilai_json, kr.nilai_min, kr.nilai_max
       FROM tabel_kategori kat
       LEFT JOIN tabel_kriteria kr ON kr.id_kategori = kat.id_kategori
      WHERE kat.id_event = :ie
      ORDER BY kat.urutan ASC, kr.urutan ASC"
);
$stmtKat->execute([':ie'=>$idEvent]);
$rawData = $stmtKat->fetchAll();

// Susun struktur hierarki
$kategoris = [];
foreach ($rawData as $row) {
    $kid = (int)$row['id_kategori'];
    if (!isset($kategoris[$kid])) {
        $kategoris[$kid] = [
            'id_kategori'   => $kid,
            'kode_kategori' => $row['kode_kategori'],
            'nama_kategori' => $row['nama_kategori'],
            'urutan'        => (int)$row['urutan_kat'],
            'kriteria'      => [],
        ];
    }
    if ($row['id_kriteria']) {
        $opsi = json_decode($row['opsi_nilai_json'] ?? '{}', true);
        $kategoris[$kid]['kriteria'][] = [
            'id_kriteria'  => (int)$row['id_kriteria'],
            'urutan'       => (int)$row['urutan_kr'],
            'nama_gerakan' => $row['nama_gerakan'],
            'nilai_min'    => $row['nilai_min'],
            'nilai_max'    => $row['nilai_max'],
            'label_str'    => implode(',', $opsi['label'] ?? []),
            'nilai_str'    => implode(',', $opsi['nilai'] ?? []),
        ];
    }
}

$totalKategori = count($kategoris);
$totalKriteria = array_sum(array_map(fn($k) => count($k['kriteria']), $kategoris));
?>
<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Manajemen Kriteria &mdash; Paskibra SaaS</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
  <link href="https://fonts.googleapis.com/css2?family=Sora:wght@600;700;800&family=DM+Sans:wght@400;500&display=swap" rel="stylesheet">
  <style>
    :root{
      --navy:#0d1b2e;--navy-mid:#162640;--navy-card:#1c3050;--navy-deep:#0a1520;
      --border:rgba(255,255,255,.07);--border-lit:rgba(255,255,255,.13);
      --text:#dce8f5;--muted:#7a94af;
      --accent:#f5a623;--cyan:#38bdf8;--green:#2dc653;
      --red:#e63946;--yellow:#fbbf24;--purple:#a78bfa;
    }
    *,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
    body{font-family:'DM Sans',sans-serif;background:var(--navy);color:var(--text);min-height:100vh}
    body::before{content:'';position:fixed;inset:0;z-index:0;pointer-events:none;
      background:repeating-linear-gradient(-45deg,transparent,transparent 40px,
      rgba(255,255,255,.014) 40px,rgba(255,255,255,.014) 41px)}
    .page{position:relative;z-index:1;padding:2rem 1.5rem 5rem}

    /* Page header */
    .page-header{display:flex;align-items:flex-start;justify-content:space-between;flex-wrap:wrap;gap:1rem;margin-bottom:1.75rem}
    .page-eyebrow{font-size:.68rem;font-weight:700;letter-spacing:.12em;text-transform:uppercase;color:var(--cyan);margin-bottom:.3rem}
    .page-title{font-family:'Sora',sans-serif;font-size:1.5rem;font-weight:800;color:#fff;margin:0}
    .page-sub{font-size:.82rem;color:var(--muted);margin-top:3px}

    /* Buttons */
    .btn-add{display:inline-flex;align-items:center;gap:7px;background:var(--cyan);color:#0d1b2e;
      font-family:'Sora',sans-serif;font-weight:700;font-size:.85rem;border:none;border-radius:10px;
      padding:.55rem 1.2rem;cursor:pointer;transition:all .15s;white-space:nowrap}
    .btn-add:hover{background:#29a8d8;transform:translateY(-1px)}
    .btn-scan{display:inline-flex;align-items:center;gap:7px;
      background:rgba(167,139,250,.13);border:1px solid rgba(167,139,250,.35);
      color:var(--purple);font-family:'Sora',sans-serif;font-weight:700;font-size:.85rem;
      border-radius:10px;padding:.55rem 1.2rem;cursor:pointer;transition:all .15s;white-space:nowrap}
    .btn-scan:hover{background:rgba(167,139,250,.22);border-color:var(--purple)}
    .btn-kat{display:inline-flex;align-items:center;gap:6px;
      background:rgba(245,166,35,.12);border:1px solid rgba(245,166,35,.3);
      color:var(--accent);font-family:'Sora',sans-serif;font-weight:700;font-size:.82rem;
      border-radius:8px;padding:.45rem 1rem;cursor:pointer;transition:all .15s;white-space:nowrap}
    .btn-kat:hover{background:rgba(245,166,35,.22)}

    /* Flash */
    .flash{border-radius:12px;padding:.85rem 1.1rem;font-size:.88rem;margin-bottom:1.25rem;
      display:flex;align-items:center;gap:9px;border-left:4px solid}
    .flash-success{background:rgba(45,198,83,.1);border-color:var(--green);color:#a8f0be}
    .flash-danger{background:rgba(230,57,70,.1);border-color:var(--red);color:#f5a8a8}

    /* Event switcher */
    .ev-bar{background:var(--navy-card);border:1px solid var(--border);border-radius:12px;
      padding:.7rem 1.1rem;display:flex;align-items:center;gap:.75rem;flex-wrap:wrap;margin-bottom:1.5rem}
    .ev-label{font-size:.72rem;color:var(--muted);white-space:nowrap}
    .ev-select{background:#0f2034;color:var(--text);border:1px solid rgba(255,255,255,.12);
      border-radius:8px;padding:.4rem .8rem;font-size:.85rem;flex:1;min-width:160px;cursor:pointer}
    .ev-select:focus{outline:none;border-color:var(--cyan)}
    .btn-ev{background:var(--cyan);color:#0d1b2e;border:none;font-family:'Sora',sans-serif;
      font-weight:700;font-size:.8rem;border-radius:8px;padding:.42rem 1rem;cursor:pointer}

    /* Stat chips */
    .stat-row{display:flex;gap:.75rem;flex-wrap:wrap;margin-bottom:1.5rem}
    .stat-chip{background:var(--navy-card);border:1px solid var(--border);border-radius:10px;
      padding:.65rem 1.1rem;display:flex;align-items:center;gap:8px}
    .sc-icon{width:36px;height:36px;border-radius:9px;display:flex;align-items:center;
      justify-content:center;font-size:1rem;flex-shrink:0}
    .sc-val{font-family:'Sora',sans-serif;font-size:1.4rem;font-weight:800;line-height:1;color:#fff}
    .sc-lbl{font-size:.72rem;color:var(--muted);margin-top:2px}

    /* Accordion kategori */
    .kat-block{background:var(--navy-card);border:1px solid var(--border);
      border-radius:14px;overflow:hidden;margin-bottom:1rem}
    .kat-header{padding:.85rem 1.1rem;display:flex;align-items:center;justify-content:space-between;
      gap:.75rem;flex-wrap:wrap;border-bottom:1px solid var(--border);cursor:pointer;
      transition:background .15s;user-select:none}
    .kat-header:hover{background:rgba(255,255,255,.03)}
    .kat-title{font-family:'Sora',sans-serif;font-weight:700;font-size:.95rem;color:#fff;
      display:flex;align-items:center;gap:8px}
    .kat-kode{background:rgba(245,166,35,.15);color:var(--accent);border:1px solid rgba(245,166,35,.3);
      border-radius:6px;padding:2px 9px;font-size:.72rem;font-weight:800}
    .kat-count{font-size:.72rem;color:var(--muted)}
    .kat-chevron{color:var(--muted);transition:transform .2s;font-size:.9rem}
    .kat-chevron.open{transform:rotate(180deg)}

    /* Kriteria table */
    .kr-table{width:100%;border-collapse:collapse;font-size:.83rem}
    .kr-table thead th{padding:.55rem 1rem;font-size:.65rem;font-weight:700;letter-spacing:.09em;
      text-transform:uppercase;color:var(--muted);border-bottom:1px solid var(--border);
      background:rgba(255,255,255,.025);white-space:nowrap}
    .kr-table tbody td{padding:.7rem 1rem;border-bottom:1px solid var(--border);vertical-align:middle}
    .kr-table tbody tr:last-child td{border-bottom:none}
    .kr-table tbody tr:hover td{background:rgba(255,255,255,.02)}
    .opsi-pills{display:flex;flex-wrap:wrap;gap:3px}
    .opsi-pill{font-size:.65rem;padding:1px 6px;border-radius:4px;
      background:rgba(56,189,248,.1);color:var(--cyan);border:1px solid rgba(56,189,248,.2);
      font-family:'Sora',sans-serif;font-weight:700;white-space:nowrap}

    /* Action buttons */
    .btn-edit{display:inline-flex;align-items:center;gap:4px;
      background:rgba(251,191,36,.12);border:1px solid rgba(251,191,36,.3);
      color:var(--yellow);border-radius:7px;padding:3px 10px;
      font-size:.74rem;font-weight:600;cursor:pointer;transition:all .15s}
    .btn-edit:hover{background:rgba(251,191,36,.22)}
    .btn-del{display:inline-flex;align-items:center;gap:4px;
      background:rgba(230,57,70,.12);border:1px solid rgba(230,57,70,.3);
      color:#ff8a8a;border-radius:7px;padding:3px 10px;
      font-size:.74rem;font-weight:600;cursor:pointer;transition:all .15s}
    .btn-del:hover{background:rgba(230,57,70,.22)}
    .btn-sm-add{display:inline-flex;align-items:center;gap:4px;
      background:rgba(45,198,83,.1);border:1px solid rgba(45,198,83,.25);
      color:var(--green);border-radius:7px;padding:3px 10px;
      font-size:.74rem;font-weight:600;cursor:pointer;transition:all .15s}
    .btn-sm-add:hover{background:rgba(45,198,83,.2)}

    /* Empty state */
    .empty-kr{text-align:center;padding:1.5rem;color:var(--muted);font-size:.82rem}

    /* Modal */
    .modal-content{background:var(--navy-card);border:1px solid var(--border);border-radius:16px;color:var(--text)}
    .modal-header{border-bottom:1px solid var(--border);padding:1.1rem 1.4rem}
    .modal-title{font-family:'Sora',sans-serif;font-weight:700;font-size:1rem;color:#fff}
    .modal-footer{border-top:1px solid var(--border)}
    .lbl{font-size:.73rem;font-weight:700;letter-spacing:.05em;text-transform:uppercase;
      color:var(--muted);margin-bottom:5px;display:block}
    .fctl{background:#0f2034;color:var(--text);border:1px solid rgba(255,255,255,.12);
      border-radius:9px;padding:.58rem .9rem;width:100%;font-size:.88rem;
      font-family:'DM Sans',sans-serif;transition:border-color .15s}
    .fctl:focus{outline:none;border-color:var(--cyan);box-shadow:0 0 0 3px rgba(56,189,248,.12)}
    .fhint{font-size:.7rem;color:var(--muted);margin-top:4px}
    .btn-ms{background:var(--cyan);color:#0d1b2e;border:none;font-family:'Sora',sans-serif;
      font-weight:700;font-size:.88rem;border-radius:9px;padding:.58rem 1.3rem;cursor:pointer;transition:all .15s}
    .btn-ms:hover{background:#29a8d8}
    .btn-mc{background:rgba(255,255,255,.07);color:var(--muted);border:none;
      border-radius:9px;padding:.58rem 1.1rem;font-size:.88rem;cursor:pointer;transition:all .15s}
    .btn-mc:hover{background:rgba(255,255,255,.12);color:var(--text)}

    /* Scan AI modal styles */
    .scan-upload-zone{border:2px dashed rgba(167,139,250,.35);border-radius:12px;
      padding:2rem 1.5rem;text-align:center;cursor:pointer;transition:all .2s;
      background:rgba(167,139,250,.04);position:relative;overflow:hidden}
    .scan-upload-zone:hover,.scan-upload-zone.drag-over{border-color:var(--purple);background:rgba(167,139,250,.09)}
    .scan-upload-zone input[type=file]{position:absolute;inset:0;opacity:0;cursor:pointer;width:100%;height:100%}
    .scan-spinner{display:none;flex-direction:column;align-items:center;justify-content:center;
      gap:10px;padding:1.5rem}
    .scan-spinner.show{display:flex}
    .spin{width:40px;height:40px;border-radius:50%;border:3px solid rgba(167,139,250,.2);
      border-top-color:var(--purple);animation:spin .9s linear infinite}
    @keyframes spin{to{transform:rotate(360deg)}}

    /* Scan preview table */
    .scan-preview{display:none;margin-top:1rem}
    .scan-preview.show{display:block}
    .scan-tbl{width:100%;border-collapse:collapse;font-size:.82rem}
    .scan-tbl th{padding:.45rem .75rem;font-size:.65rem;font-weight:700;letter-spacing:.08em;
      text-transform:uppercase;color:var(--muted);border-bottom:1px solid var(--border);background:rgba(255,255,255,.025)}
    .scan-tbl td{padding:.55rem .75rem;border-bottom:1px solid var(--border);vertical-align:middle}
    .scan-tbl tr:last-child td{border-bottom:none}
    .scan-tbl td input{background:#0a1828;border:1px solid rgba(255,255,255,.1);color:var(--text);
      border-radius:6px;padding:3px 7px;font-size:.82rem;width:100%}
    .scan-tbl td input:focus{outline:none;border-color:var(--cyan)}
    .scan-stat{font-size:.75rem;color:var(--muted);margin-top:.5rem;text-align:right}
    .scan-stat strong{color:var(--purple)}

    ::-webkit-scrollbar{width:5px}
    ::-webkit-scrollbar-thumb{background:rgba(255,255,255,.1);border-radius:3px}
  </style>
</head>
<body>

<?php
$navActive  = 'kriteria';
$navIdEvent = $idEvent;
require_once __DIR__ . '/navbar.php';
?>

<div class="page">
<div class="container-fluid" style="max-width:1000px;">

  <!-- Page header -->
  <div class="page-header">
    <div>
      <div class="page-eyebrow"><i class="bi bi-list-check me-1"></i>Master Data</div>
      <h1 class="page-title">Manajemen Kriteria Penilaian</h1>
      <div class="page-sub"><?= htmlspecialchars($namaEvent) ?></div>
    </div>
    <div class="d-flex gap-2 flex-wrap">
      <button class="btn-kat" data-bs-toggle="modal" data-bs-target="#modalTambahKat">
        <i class="bi bi-folder-plus"></i>+ Kategori
      </button>
      <button class="btn-add" data-bs-toggle="modal" data-bs-target="#modalTambahKr"
              onclick="resetFormKr()">
        <i class="bi bi-pencil-square"></i>Tambah Manual
      </button>
      <button class="btn-scan" data-bs-toggle="modal" data-bs-target="#modalScan">
        <i class="bi bi-camera-fill"></i>Auto-Scan AI
      </button>
    </div>
  </div>

  <!-- Flash -->
  <?php if ($flash): ?>
  <div class="flash flash-<?= htmlspecialchars($flash['type']) ?>" id="flash-msg">
    <i class="bi bi-<?= $flash['type']==='success'?'check-circle-fill':'exclamation-triangle-fill' ?>"></i>
    <span><?= $flash['msg'] ?></span>
  </div>
  <?php endif; ?>

  <!-- Event switcher -->
  <?php if (count($events) > 1): ?>
  <form method="GET" class="ev-bar">
    <span class="ev-label"><i class="bi bi-calendar-event me-1"></i>Event:</span>
    <select name="id_event" class="ev-select">
      <?php foreach ($events as $ev): ?>
        <option value="<?= (int)$ev['id_event'] ?>" <?= $ev['id_event']==$idEvent?'selected':'' ?>>
          <?= htmlspecialchars($ev['nama_event']) ?>
        </option>
      <?php endforeach; ?>
    </select>
    <button type="submit" class="btn-ev"><i class="bi bi-arrow-right-circle me-1"></i>Ganti</button>
  </form>
  <?php endif; ?>

  <!-- Stat chips -->
  <div class="stat-row">
    <div class="stat-chip">
      <div class="sc-icon" style="background:rgba(245,166,35,.13);color:var(--accent);">
        <i class="bi bi-folder-fill"></i></div>
      <div><div class="sc-val"><?= $totalKategori ?></div><div class="sc-lbl">Kategori</div></div>
    </div>
    <div class="stat-chip">
      <div class="sc-icon" style="background:rgba(56,189,248,.13);color:var(--cyan);">
        <i class="bi bi-list-check"></i></div>
      <div><div class="sc-val"><?= $totalKriteria ?></div><div class="sc-lbl">Total Kriteria</div></div>
    </div>
    <div class="stat-chip">
      <div class="sc-icon" style="background:rgba(167,139,250,.13);color:var(--purple);">
        <i class="bi bi-robot"></i></div>
      <div><div class="sc-val" style="font-size:.9rem;padding-top:3px">AI Scan</div>
           <div class="sc-lbl">Tersedia</div></div>
    </div>
  </div>

  <!-- Daftar Kategori + Kriteria -->
  <?php if (empty($kategoris)): ?>
  <div style="text-align:center;padding:3rem;color:var(--muted);">
    <span style="font-size:2.5rem;display:block;margin-bottom:.75rem;">📋</span>
    <p>Belum ada kategori. Tambahkan kategori terlebih dahulu.</p>
    <button class="btn-kat mt-3" data-bs-toggle="modal" data-bs-target="#modalTambahKat">
      <i class="bi bi-folder-plus"></i>Tambah Kategori Pertama
    </button>
  </div>
  <?php else: ?>

  <?php foreach ($kategoris as $kat): $katId = $kat['id_kategori']; ?>
  <div class="kat-block">
    <!-- Header kategori (klik untuk toggle) -->
    <div class="kat-header" onclick="toggleKat(<?= $katId ?>)">
      <div class="kat-title">
        <?php if ($kat['kode_kategori']): ?>
        <span class="kat-kode"><?= htmlspecialchars($kat['kode_kategori']) ?></span>
        <?php endif; ?>
        <?= htmlspecialchars($kat['nama_kategori']) ?>
        <span class="kat-count">(<?= count($kat['kriteria']) ?> kriteria)</span>
      </div>
      <div class="d-flex align-items-center gap-2">
        <!-- Edit Kategori -->
        <button class="btn-edit"
                data-bs-toggle="modal" data-bs-target="#modalEditKat"
                data-id="<?= $katId ?>"
                data-kode="<?= htmlspecialchars($kat['kode_kategori']??'',ENT_QUOTES) ?>"
                data-nama="<?= htmlspecialchars($kat['nama_kategori'],ENT_QUOTES) ?>"
                data-urut="<?= $kat['urutan'] ?>"
                onclick="event.stopPropagation();isiEditKat(this)">
          <i class="bi bi-pencil-fill"></i>Edit
        </button>
        <!-- Hapus Kategori -->
        <form method="POST" style="display:inline"
              onsubmit="return confirm('Hapus kategori \'<?= htmlspecialchars($kat['nama_kategori'],ENT_QUOTES) ?>\' beserta SEMUA kriterianya?')">
          <input type="hidden" name="aksi"        value="hapus_kategori">
          <input type="hidden" name="id_event"    value="<?= $idEvent ?>">
          <input type="hidden" name="id_kategori" value="<?= $katId ?>">
          <button type="submit" class="btn-del" onclick="event.stopPropagation()">
            <i class="bi bi-trash3-fill"></i>Hapus
          </button>
        </form>
        <!-- Tambah kriteria ke kategori ini -->
        <button class="btn-sm-add"
                data-bs-toggle="modal" data-bs-target="#modalTambahKr"
                onclick="event.stopPropagation();pilihKatForm(<?= $katId ?>,'<?= htmlspecialchars($kat['nama_kategori'],ENT_QUOTES) ?>')">
          <i class="bi bi-plus-lg"></i>+ Kriteria
        </button>
        <i class="bi bi-chevron-down kat-chevron" id="chev-<?= $katId ?>"></i>
      </div>
    </div>

    <!-- Body: tabel kriteria -->
    <div id="kat-body-<?= $katId ?>" style="display:none;">
      <?php if (empty($kat['kriteria'])): ?>
      <div class="empty-kr">Belum ada kriteria di kategori ini.</div>
      <?php else: ?>
      <div style="overflow-x:auto;">
        <table class="kr-table">
          <thead>
            <tr>
              <th style="width:45px;">No.</th>
              <th>Nama Gerakan / Kriteria</th>
              <th>Opsi Nilai</th>
              <th style="width:80px;text-align:center;">Min</th>
              <th style="width:80px;text-align:center;">Maks</th>
              <th style="width:120px;text-align:center;">Aksi</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($kat['kriteria'] as $i => $kr): ?>
            <tr>
              <td style="color:var(--muted);font-family:'Sora',sans-serif;font-weight:700;">
                <?= (int)$kr['urutan'] ?>
              </td>
              <td style="color:#fff;font-weight:500;"><?= htmlspecialchars($kr['nama_gerakan']) ?></td>
              <td>
                <div class="opsi-pills">
                  <?php foreach (explode(',', $kr['nilai_str']) as $j => $v): ?>
                    <?php $lbl = explode(',', $kr['label_str'])[$j] ?? ''; ?>
                    <span class="opsi-pill" title="<?= htmlspecialchars($lbl) ?>"><?= $v ?></span>
                  <?php endforeach; ?>
                </div>
              </td>
              <td style="text-align:center;color:var(--muted);"><?= number_format((float)$kr['nilai_min'],1) ?></td>
              <td style="text-align:center;color:var(--green);font-family:'Sora',sans-serif;font-weight:700;">
                <?= number_format((float)$kr['nilai_max'],1) ?>
              </td>
              <td style="text-align:center;">
                <div class="d-flex gap-1 justify-content-center">
                  <button class="btn-edit"
                          data-bs-toggle="modal" data-bs-target="#modalEditKr"
                          data-id="<?= $kr['id_kriteria'] ?>"
                          data-kat="<?= $katId ?>"
                          data-nama="<?= htmlspecialchars($kr['nama_gerakan'],ENT_QUOTES) ?>"
                          data-urut="<?= $kr['urutan'] ?>"
                          data-nilai="<?= htmlspecialchars($kr['nilai_str'],ENT_QUOTES) ?>"
                          data-label="<?= htmlspecialchars($kr['label_str'],ENT_QUOTES) ?>"
                          onclick="isiEditKr(this)">
                    <i class="bi bi-pencil-fill"></i>Edit
                  </button>
                  <form method="POST" style="display:inline"
                        onsubmit="return confirm('Hapus kriteria \'<?= htmlspecialchars($kr['nama_gerakan'],ENT_QUOTES) ?>\'?')">
                    <input type="hidden" name="aksi"        value="hapus_kriteria">
                    <input type="hidden" name="id_event"    value="<?= $idEvent ?>">
                    <input type="hidden" name="id_kriteria" value="<?= $kr['id_kriteria'] ?>">
                    <button type="submit" class="btn-del"><i class="bi bi-trash3-fill"></i></button>
                  </form>
                </div>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php endif; ?>
    </div>
  </div>
  <?php endforeach; ?>
  <?php endif; ?>

</div>
</div><!-- /page -->


<!-- ═══ MODAL: TAMBAH KATEGORI ═══ -->
<div class="modal fade" id="modalTambahKat" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <form method="POST">
        <input type="hidden" name="aksi"     value="tambah_kategori">
        <input type="hidden" name="id_event" value="<?= $idEvent ?>">
        <div class="modal-header">
          <h5 class="modal-title"><i class="bi bi-folder-plus me-2" style="color:var(--accent);"></i>Tambah Kategori</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" style="filter:invert(1) brightness(2)"></button>
        </div>
        <div class="modal-body" style="padding:1.25rem 1.4rem;">
          <div class="mb-3">
            <label class="lbl">Urutan Tampil</label>
            <input type="number" name="urutan" class="fctl" min="0" max="99" value="<?= $totalKategori+1 ?>">
          </div>
          <div class="mb-3">
            <label class="lbl">Kode Singkat (Opsional)</label>
            <input type="text" name="kode_kategori" class="fctl" maxlength="3" placeholder="Mis: A, B, C">
            <div class="fhint">Maks 3 karakter, huruf kapital. Contoh: A, B, GDT</div>
          </div>
          <div>
            <label class="lbl">Nama Kategori *</label>
            <input type="text" name="nama_kategori" class="fctl" required maxlength="255"
                   placeholder="Contoh: GERAKAN DITEMPAT">
          </div>
        </div>
        <div class="modal-footer gap-2">
          <button type="button" class="btn-mc" data-bs-dismiss="modal">Batal</button>
          <button type="submit" class="btn-ms"><i class="bi bi-folder-plus me-1"></i>Simpan Kategori</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- ═══ MODAL: EDIT KATEGORI ═══ -->
<div class="modal fade" id="modalEditKat" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <form method="POST">
        <input type="hidden" name="aksi"        value="edit_kategori">
        <input type="hidden" name="id_event"    value="<?= $idEvent ?>">
        <input type="hidden" name="id_kategori" id="ek_id">
        <div class="modal-header">
          <h5 class="modal-title"><i class="bi bi-folder me-2" style="color:var(--yellow);"></i>Edit Kategori</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" style="filter:invert(1) brightness(2)"></button>
        </div>
        <div class="modal-body" style="padding:1.25rem 1.4rem;">
          <div class="mb-3">
            <label class="lbl">Urutan</label>
            <input type="number" name="urutan" id="ek_urut" class="fctl" min="0" max="99">
          </div>
          <div class="mb-3">
            <label class="lbl">Kode Singkat</label>
            <input type="text" name="kode_kategori" id="ek_kode" class="fctl" maxlength="3">
          </div>
          <div>
            <label class="lbl">Nama Kategori *</label>
            <input type="text" name="nama_kategori" id="ek_nama" class="fctl" required maxlength="255">
          </div>
        </div>
        <div class="modal-footer gap-2">
          <button type="button" class="btn-mc" data-bs-dismiss="modal">Batal</button>
          <button type="submit" class="btn-ms" style="background:var(--yellow);color:#1a0f00;">
            <i class="bi bi-check-lg me-1"></i>Perbarui
          </button>
        </div>
      </form>
    </div>
  </div>
</div>


<!-- ═══ MODAL: TAMBAH KRITERIA ═══ -->
<div class="modal fade" id="modalTambahKr" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-lg">
    <div class="modal-content">
      <form method="POST">
        <input type="hidden" name="aksi"     value="tambah_kriteria">
        <input type="hidden" name="id_event" value="<?= $idEvent ?>">
        <div class="modal-header">
          <h5 class="modal-title"><i class="bi bi-pencil-square me-2" style="color:var(--cyan);"></i>Tambah Kriteria Manual</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" style="filter:invert(1) brightness(2)"></button>
        </div>
        <div class="modal-body" style="padding:1.25rem 1.4rem;">
          <div class="row g-3">
            <div class="col-sm-8">
              <label class="lbl">Kategori *</label>
              <select name="id_kategori" id="kr_kat" class="fctl" required>
                <option value="">— Pilih Kategori —</option>
                <?php foreach ($kategoris as $k): ?>
                <option value="<?= $k['id_kategori'] ?>">
                  <?= $k['kode_kategori'] ? '['.$k['kode_kategori'].'] ' : '' ?><?= htmlspecialchars($k['nama_kategori']) ?>
                </option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-sm-4">
              <label class="lbl">Urutan</label>
              <input type="number" name="urutan" class="fctl" min="0" max="999" value="1">
            </div>
            <div class="col-12">
              <label class="lbl">Nama Gerakan / Kriteria *</label>
              <input type="text" name="nama_gerakan" class="fctl" required maxlength="255"
                     placeholder="Contoh: SIKAP SEMPURNA">
            </div>
            <div class="col-sm-6">
              <label class="lbl">Opsi Nilai (pisahkan koma) *</label>
              <input type="text" name="nilai_opsi" class="fctl" required
                     placeholder="10,13,16,19,22,25,28">
              <div class="fhint">Nilai dari kecil ke besar, pisahkan dengan koma</div>
            </div>
            <div class="col-sm-6">
              <label class="lbl">Label Opsi (pisahkan koma)</label>
              <input type="text" name="label_opsi" class="fctl"
                     value="SK,K,C,CB,B,SB,A" placeholder="SK,K,C,CB,B,SB,A">
              <div class="fhint">Jumlah label harus sama dengan jumlah nilai</div>
            </div>
          </div>
          <!-- Preview realtime -->
          <div style="margin-top:1rem;padding:.75rem 1rem;background:rgba(56,189,248,.06);
               border:1px solid rgba(56,189,248,.15);border-radius:9px;">
            <div style="font-size:.7rem;color:var(--muted);margin-bottom:6px;">Preview opsi nilai:</div>
            <div id="kr_preview" class="opsi-pills"></div>
          </div>
        </div>
        <div class="modal-footer gap-2">
          <button type="button" class="btn-mc" data-bs-dismiss="modal">Batal</button>
          <button type="submit" class="btn-ms"><i class="bi bi-plus-lg me-1"></i>Simpan Kriteria</button>
        </div>
      </form>
    </div>
  </div>
</div>


<!-- ═══ MODAL: EDIT KRITERIA ═══ -->
<div class="modal fade" id="modalEditKr" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-lg">
    <div class="modal-content">
      <form method="POST">
        <input type="hidden" name="aksi"        value="edit_kriteria">
        <input type="hidden" name="id_event"    value="<?= $idEvent ?>">
        <input type="hidden" name="id_kriteria" id="ekr_id">
        <div class="modal-header">
          <h5 class="modal-title"><i class="bi bi-pencil-fill me-2" style="color:var(--yellow);"></i>Edit Kriteria</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" style="filter:invert(1) brightness(2)"></button>
        </div>
        <div class="modal-body" style="padding:1.25rem 1.4rem;">
          <div class="row g-3">
            <div class="col-sm-8">
              <label class="lbl">Kategori *</label>
              <select name="id_kategori" id="ekr_kat" class="fctl" required>
                <?php foreach ($kategoris as $k): ?>
                <option value="<?= $k['id_kategori'] ?>">
                  <?= $k['kode_kategori'] ? '['.$k['kode_kategori'].'] ' : '' ?><?= htmlspecialchars($k['nama_kategori']) ?>
                </option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-sm-4">
              <label class="lbl">Urutan</label>
              <input type="number" name="urutan" id="ekr_urut" class="fctl" min="0" max="999">
            </div>
            <div class="col-12">
              <label class="lbl">Nama Gerakan / Kriteria *</label>
              <input type="text" name="nama_gerakan" id="ekr_nama" class="fctl" required maxlength="255">
            </div>
            <div class="col-sm-6">
              <label class="lbl">Opsi Nilai *</label>
              <input type="text" name="nilai_opsi" id="ekr_nilai" class="fctl" required>
            </div>
            <div class="col-sm-6">
              <label class="lbl">Label Opsi</label>
              <input type="text" name="label_opsi" id="ekr_label" class="fctl">
            </div>
          </div>
          <div style="margin-top:1rem;padding:.75rem 1rem;background:rgba(56,189,248,.06);
               border:1px solid rgba(56,189,248,.15);border-radius:9px;">
            <div style="font-size:.7rem;color:var(--muted);margin-bottom:6px;">Preview opsi nilai:</div>
            <div id="ekr_preview" class="opsi-pills"></div>
          </div>
        </div>
        <div class="modal-footer gap-2">
          <button type="button" class="btn-mc" data-bs-dismiss="modal">Batal</button>
          <button type="submit" class="btn-ms" style="background:var(--yellow);color:#1a0f00;">
            <i class="bi bi-check-lg me-1"></i>Perbarui Kriteria
          </button>
        </div>
      </form>
    </div>
  </div>
</div>


<!-- ═══ MODAL: AUTO-SCAN AI ═══ -->
<div class="modal fade" id="modalScan" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-xl">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">
          <i class="bi bi-robot me-2" style="color:var(--purple);"></i>Auto-Scan Blanko AI
          <span style="font-size:.7rem;color:var(--muted);font-weight:400;margin-left:6px;">
            Powered by Gemini Vision
          </span>
        </h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"
                style="filter:invert(1) brightness(2)" onclick="resetScan()"></button>
      </div>

      <div class="modal-body" style="padding:1.25rem 1.4rem;">
        <div class="row g-3">

          <!-- Kiri: upload & kontrol -->
          <div class="col-md-5">
            <!-- Upload zone -->
            <div class="scan-upload-zone" id="scan-upload-zone">
              <input type="file" id="scan-file-input" accept="image/jpeg,image/png,image/webp">
              <i class="bi bi-camera-fill" style="font-size:2rem;color:var(--purple);display:block;margin-bottom:.5rem;"></i>
              <div style="font-family:'Sora',sans-serif;font-weight:700;color:#fff;margin-bottom:4px;">
                Upload Foto Blanko
              </div>
              <div style="font-size:.75rem;color:var(--muted);">
                Foto lembaran blanko kriteria penilaian<br>JPG / PNG / WEBP · Maks 4 MB
              </div>
            </div>

            <!-- Thumbnail preview -->
            <div id="scan-thumb-wrap" style="display:none;margin-top:.75rem;border-radius:10px;
                 overflow:hidden;border:1px solid rgba(167,139,250,.25);">
              <img id="scan-thumb" src="" style="width:100%;max-height:200px;object-fit:contain;background:#000;">
              <div style="padding:4px 10px;background:rgba(167,139,250,.08);font-size:.72rem;color:var(--muted);"
                   id="scan-fname"></div>
            </div>

            <!-- Pilih kategori tujuan -->
            <div style="margin-top:.85rem;">
              <label class="lbl">Simpan ke Kategori</label>
              <select id="scan-id-kategori" class="fctl">
                <option value="">— Pilih Kategori —</option>
                <?php foreach ($kategoris as $k): ?>
                <option value="<?= $k['id_kategori'] ?>">
                  <?= $k['kode_kategori'] ? '['.$k['kode_kategori'].'] ' : '' ?>
                  <?= htmlspecialchars($k['nama_kategori']) ?>
                </option>
                <?php endforeach; ?>
              </select>
            </div>

            <!-- Tombol scan -->
            <button class="btn-scan" id="btn-do-scan"
                    style="width:100%;margin-top:.75rem;justify-content:center;" disabled
                    onclick="jalankanScan()">
              <i class="bi bi-robot"></i>Mulai Scan AI
            </button>
          </div>

          <!-- Kanan: hasil scan -->
          <div class="col-md-7">

            <!-- Loading spinner -->
            <div class="scan-spinner" id="scan-spinner">
              <div class="spin"></div>
              <div style="font-size:.85rem;color:var(--muted);">Gemini membaca blanko…</div>
              <div style="font-size:.72rem;color:rgba(167,139,250,.6);">Biasanya selesai 5–15 detik</div>
            </div>

            <!-- Preview tabel hasil -->
            <div class="scan-preview" id="scan-preview">
              <div style="font-size:.72rem;font-weight:700;letter-spacing:.08em;text-transform:uppercase;
                   color:var(--muted);margin-bottom:8px;display:flex;justify-content:space-between;">
                <span>Hasil Deteksi AI</span>
                <span id="scan-result-stat" style="color:var(--purple);"></span>
              </div>
              <div style="max-height:340px;overflow-y:auto;">
                <table class="scan-tbl">
                  <thead>
                    <tr>
                      <th style="width:32px;"><input type="checkbox" id="scan-check-all" onchange="toggleAllScan(this)" style="accent-color:var(--purple)"></th>
                      <th>Nama Gerakan</th>
                      <th style="width:140px;">Nilai (koma)</th>
                      <th style="width:120px;">Label (koma)</th>
                    </tr>
                  </thead>
                  <tbody id="scan-result-body"></tbody>
                </table>
              </div>
              <div class="scan-stat" id="scan-stat-txt"></div>
            </div>

            <!-- Placeholder saat belum scan -->
            <div id="scan-placeholder" style="display:flex;flex-direction:column;align-items:center;
                 justify-content:center;height:100%;min-height:200px;color:var(--muted);text-align:center;
                 border:1px dashed var(--border);border-radius:10px;padding:2rem;">
              <i class="bi bi-camera" style="font-size:2rem;margin-bottom:.75rem;"></i>
              <div style="font-size:.85rem;">Upload foto blanko dan klik<br><strong style="color:var(--purple);">Mulai Scan AI</strong></div>
            </div>
          </div>
        </div>
      </div>

      <!-- Footer modal scan: form simpan -->
      <div class="modal-footer" id="scan-footer" style="display:none!important;">
        <form method="POST" id="form-simpan-scan">
          <input type="hidden" name="aksi"             value="simpan_scan">
          <input type="hidden" name="id_event"         value="<?= $idEvent ?>">
          <input type="hidden" name="scan_id_kategori" id="fs_id_kat">
          <div id="fs_rows_hidden"></div>
        </form>
        <button type="button" class="btn-mc" data-bs-dismiss="modal" onclick="resetScan()">Batal</button>
        <button type="button" class="btn-ms" id="btn-simpan-scan"
                onclick="konfirmasiSimpanScan()"
                style="background:var(--purple);color:#fff;">
          <i class="bi bi-cloud-upload-fill me-1"></i>Simpan Hasil Scan ke DB
        </button>
      </div>
      <!-- Footer default -->
      <div class="modal-footer" id="scan-footer-default">
        <button type="button" class="btn-mc" data-bs-dismiss="modal" onclick="resetScan()">Tutup</button>
      </div>
    </div>
  </div>
</div>


<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
const ID_EVENT  = <?= $idEvent ?>;
const API_BASE  = 'http://localhost/paskibra v.2';

// ════════════════════════════════════════════════════════════════
//  ACCORDION KATEGORI
// ════════════════════════════════════════════════════════════════
function toggleKat(id) {
  const body  = document.getElementById('kat-body-' + id);
  const chev  = document.getElementById('chev-' + id);
  const open  = body.style.display === 'none';
  body.style.display = open ? '' : 'none';
  chev.classList.toggle('open', open);
}
// Buka semua setelah load jika hanya 1 kategori
document.addEventListener('DOMContentLoaded', () => {
  const blocks = document.querySelectorAll('[id^="kat-body-"]');
  if (blocks.length === 1) {
    const id = blocks[0].id.replace('kat-body-','');
    toggleKat(id);
  }
});

// ════════════════════════════════════════════════════════════════
//  MODAL: KATEGORI
// ════════════════════════════════════════════════════════════════
function isiEditKat(btn) {
  document.getElementById('ek_id').value   = btn.dataset.id;
  document.getElementById('ek_urut').value = btn.dataset.urut;
  document.getElementById('ek_kode').value = btn.dataset.kode;
  document.getElementById('ek_nama').value = btn.dataset.nama;
}

// ════════════════════════════════════════════════════════════════
//  MODAL: KRITERIA — preview opsi nilai realtime
// ════════════════════════════════════════════════════════════════
function buildPreview(nilaiStr, labelStr, targetId) {
  const nilaiArr = nilaiStr.split(',').map(s => s.trim()).filter(s => s !== '' && !isNaN(s));
  const labelArr = labelStr.split(',').map(s => s.trim());
  const el = document.getElementById(targetId);
  if (!el) return;
  el.innerHTML = nilaiArr.map((v, i) => {
    const lbl = labelArr[i] || '';
    return `<span class="opsi-pill" title="${lbl}">${v}</span>`;
  }).join('');
}

// Pasang listener preview realtime
document.addEventListener('DOMContentLoaded', () => {
  // Modal tambah
  ['nilai_opsi','label_opsi'].forEach(name => {
    document.querySelector(`#modalTambahKr [name="${name}"]`)
      ?.addEventListener('input', () => {
        const n = document.querySelector('#modalTambahKr [name="nilai_opsi"]')?.value || '';
        const l = document.querySelector('#modalTambahKr [name="label_opsi"]')?.value || '';
        buildPreview(n, l, 'kr_preview');
      });
  });
  // Modal edit
  ['ekr_nilai','ekr_label'].forEach(id => {
    document.getElementById(id)?.addEventListener('input', () => {
      buildPreview(
        document.getElementById('ekr_nilai')?.value || '',
        document.getElementById('ekr_label')?.value || '',
        'ekr_preview'
      );
    });
  });
});

function resetFormKr() {
  document.getElementById('kr_kat').value = '';
}

function pilihKatForm(katId, katNama) {
  const sel = document.getElementById('kr_kat');
  if (sel) sel.value = katId;
}

function isiEditKr(btn) {
  document.getElementById('ekr_id').value    = btn.dataset.id;
  document.getElementById('ekr_kat').value   = btn.dataset.kat;
  document.getElementById('ekr_urut').value  = btn.dataset.urut;
  document.getElementById('ekr_nama').value  = btn.dataset.nama;
  document.getElementById('ekr_nilai').value = btn.dataset.nilai;
  document.getElementById('ekr_label').value = btn.dataset.label;
  buildPreview(btn.dataset.nilai, btn.dataset.label, 'ekr_preview');
}

// ════════════════════════════════════════════════════════════════
//  MODAL: AUTO-SCAN AI
// ════════════════════════════════════════════════════════════════
let scanFile = null;

// Pilih file
document.getElementById('scan-file-input')?.addEventListener('change', e => {
  const f = e.target.files[0];
  if (!f) return;
  if (!['image/jpeg','image/png','image/webp'].includes(f.type)) {
    alert('File harus JPG, PNG, atau WEBP.'); return;
  }
  if (f.size > 4*1024*1024) { alert('File melebihi 4 MB.'); return; }
  scanFile = f;

  // Thumbnail
  const url = URL.createObjectURL(f);
  document.getElementById('scan-thumb').src = url;
  document.getElementById('scan-fname').textContent = f.name;
  document.getElementById('scan-thumb-wrap').style.display = '';
  document.getElementById('btn-do-scan').disabled = false;
});

// Drag & drop
const uz = document.getElementById('scan-upload-zone');
uz?.addEventListener('dragover',  e => { e.preventDefault(); uz.classList.add('drag-over'); });
uz?.addEventListener('dragleave', ()  => uz.classList.remove('drag-over'));
uz?.addEventListener('drop', e => {
  e.preventDefault(); uz.classList.remove('drag-over');
  const f = e.dataTransfer.files[0];
  if (f) { document.getElementById('scan-file-input').files = e.dataTransfer.files;
           document.getElementById('scan-file-input').dispatchEvent(new Event('change')); }
});

function resetScan() {
  scanFile = null;
  document.getElementById('scan-file-input').value = '';
  document.getElementById('scan-thumb-wrap').style.display   = 'none';
  document.getElementById('scan-spinner').classList.remove('show');
  document.getElementById('scan-preview').classList.remove('show');
  document.getElementById('scan-placeholder').style.display  = '';
  document.getElementById('scan-footer').style.setProperty('display','none','important');
  document.getElementById('scan-footer-default').style.display = '';
  document.getElementById('btn-do-scan').disabled = true;
  document.getElementById('scan-result-body').innerHTML = '';
}

/**
 * jalankanScan()
 * Kirim gambar ke proses_scan_kriteria.php, render tabel preview.
 * Endpoint ini belum dibuat — akan Anda buat nanti.
 * Respons yang diharapkan:
 * {
 *   "status": "success",
 *   "data": [
 *     { "nama_gerakan": "SIKAP SEMPURNA", "nilai": "1,2,3,4,5,6,7", "label": "SK,K,C,CB,B,SB,A" },
 *     ...
 *   ]
 * }
 */
async function jalankanScan() {
  if (!scanFile) return;

  // UI: loading
  document.getElementById('btn-do-scan').disabled = true;
  document.getElementById('btn-do-scan').innerHTML = '<span class="spin" style="width:16px;height:16px;border-width:2px;"></span> Memproses…';
  document.getElementById('scan-spinner').classList.add('show');
  document.getElementById('scan-preview').classList.remove('show');
  document.getElementById('scan-placeholder').style.display = 'none';

  try {
    const fd = new FormData();
    fd.append('gambar',   scanFile);
    fd.append('id_event', ID_EVENT);

    const res  = await fetch(`${API_BASE}/proses_scan_kriteria.php`, { method:'POST', body:fd });
    const json = await res.json();

    if (!res.ok || json.status !== 'success') throw new Error(json.message || `HTTP ${res.status}`);

    renderHasilScan(json.data);

  } catch (err) {
    // Cek apakah endpoint belum dibuat (404) → tampilkan pesan informatif
    const isNotFound = err.message.includes('404') || err.message.includes('not found');
    alert(isNotFound
      ? 'File proses_scan_kriteria.php belum ada.\nBuat file tersebut terlebih dahulu.'
      : 'Scan gagal: ' + err.message);
    document.getElementById('scan-placeholder').style.display = '';
  } finally {
    document.getElementById('scan-spinner').classList.remove('show');
    document.getElementById('btn-do-scan').disabled  = false;
    document.getElementById('btn-do-scan').innerHTML = '<i class="bi bi-robot"></i>Mulai Scan AI';
  }
}

/**
 * renderHasilScan(data)
 * Render baris tabel preview dari array hasil AI.
 * Setiap baris bisa diedit sebelum disimpan.
 */
function renderHasilScan(data) {
  const tbody = document.getElementById('scan-result-body');
  tbody.innerHTML = '';

  data.forEach((row, i) => {
    tbody.insertAdjacentHTML('beforeend', `
      <tr id="srow-${i}">
        <td><input type="checkbox" class="scan-chk" data-i="${i}" checked
                   style="accent-color:var(--purple)" onchange="updateScanCount()"></td>
        <td><input type="text"  id="sn-${i}" value="${escHtml(row.nama_gerakan || '')}"
                   placeholder="Nama gerakan" style="min-width:160px;"></td>
        <td><input type="text"  id="sv-${i}" value="${escHtml(row.nilai || '')}"
                   placeholder="10,13,..."></td>
        <td><input type="text"  id="sl-${i}" value="${escHtml(row.label || 'SK,K,C,CB,B,SB,A')}"
                   placeholder="SK,K,..."></td>
      </tr>`);
  });

  document.getElementById('scan-result-stat').textContent = data.length + ' baris terdeteksi';
  updateScanCount();
  document.getElementById('scan-preview').classList.add('show');
  document.getElementById('scan-footer').style.removeProperty('display');
  document.getElementById('scan-footer-default').style.display = 'none';
}

function escHtml(s) {
  return String(s ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;')
                        .replace(/"/g,'&quot;').replace(/'/g,'&#39;');
}

function toggleAllScan(chk) {
  document.querySelectorAll('.scan-chk').forEach(c => { c.checked = chk.checked; });
  updateScanCount();
}

function updateScanCount() {
  const total   = document.querySelectorAll('.scan-chk').length;
  const checked = document.querySelectorAll('.scan-chk:checked').length;
  document.getElementById('scan-stat-txt').innerHTML =
    `<strong style="color:var(--purple)">${checked}</strong> / ${total} baris dipilih untuk disimpan`;
}

function konfirmasiSimpanScan() {
  const idKat = document.getElementById('scan-id-kategori').value;
  if (!idKat) { alert('Pilih kategori tujuan terlebih dahulu.'); return; }

  const rows = [];
  document.querySelectorAll('.scan-chk:checked').forEach(chk => {
    const i = chk.dataset.i;
    rows.push({
      nama  : document.getElementById('sn-' + i)?.value || '',
      nilai : document.getElementById('sv-' + i)?.value || '',
      label : document.getElementById('sl-' + i)?.value || '',
    });
  });

  if (rows.length === 0) { alert('Pilih minimal satu baris untuk disimpan.'); return; }
  if (!confirm(`Simpan ${rows.length} kriteria hasil scan ke database?`)) return;

  // Isi form hidden lalu submit
  document.getElementById('fs_id_kat').value = idKat;
  const wrap = document.getElementById('fs_rows_hidden');
  wrap.innerHTML = '';
  rows.forEach((r, i) => {
    wrap.innerHTML += `
      <input type="hidden" name="scan_row[${i}][nama]"  value="${escHtml(r.nama)}">
      <input type="hidden" name="scan_row[${i}][nilai]" value="${escHtml(r.nilai)}">
      <input type="hidden" name="scan_row[${i}][label]" value="${escHtml(r.label)}">`;
  });
  document.getElementById('form-simpan-scan').submit();
}

// ── Auto-dismiss flash ──────────────────────────────────────────
(function() {
  const el = document.getElementById('flash-msg');
  if (!el) return;
  setTimeout(() => {
    el.style.transition = 'opacity .4s';
    el.style.opacity    = '0';
    setTimeout(() => el.remove(), 400);
  }, 5000);
})();
</script>
</body>
</html>
