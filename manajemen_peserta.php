<?php
/**
 * manajemen_peserta.php
 * ============================================================
 * Kelola Data Peserta/Regu — Admin Only
 * CRUD lengkap: Tambah, Edit, Hapus via Bootstrap Modal
 * Tabel: tabel_peserta (id_peserta, id_event, no_urut, nama_regu, asal_sekolah)
 * ============================================================
 */
declare(strict_types=1);

// ── Keamanan: hanya admin ─────────────────────────────────────
require_once __DIR__ . '/auth_guard.php';
authGuard('admin');
$me = currentUser();
require_once __DIR__ . '/koneksi.php';

// ── Parameter event ───────────────────────────────────────────
$idEvent = filter_input(INPUT_GET,  'id_event', FILTER_VALIDATE_INT)
        ?: filter_input(INPUT_POST, 'id_event', FILTER_VALIDATE_INT)
        ?: ($me['id_event'] ?? 1);

// ── Flash message ─────────────────────────────────────────────
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

// ════════════════════════════════════════════════════════════════
//  PROSES CRUD
// ════════════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $aksi = trim($_POST['aksi'] ?? '');

    $redir = function(string $type, string $msg) use ($idEvent): void {
        $_SESSION['flash'] = ['type' => $type, 'msg' => $msg];
        header("Location: manajemen_peserta.php?id_event={$idEvent}");
        exit;
    };

    // ── TAMBAH ────────────────────────────────────────────────
    if ($aksi === 'tambah') {
        $noUrut      = filter_input(INPUT_POST, 'no_urut', FILTER_VALIDATE_INT);
        $namaRegu    = trim($_POST['nama_regu']    ?? '');
        $asalSekolah = trim($_POST['asal_sekolah'] ?? '');

        if (!$noUrut || $noUrut < 1 || $namaRegu === '') {
            $redir('danger', 'Nomor urut dan nama regu wajib diisi.');
        }
        try {
            $s = $pdo->prepare(
                "INSERT INTO tabel_peserta (id_event, no_urut, nama_regu, asal_sekolah)
                 VALUES (:ie, :nu, :nr, :as)"
            );
            $s->execute([':ie'=>$idEvent, ':nu'=>$noUrut,
                         ':nr'=>$namaRegu, ':as'=>($asalSekolah ?: null)]);
            $redir('success', "Peserta <strong>".htmlspecialchars($namaRegu)."</strong> berhasil ditambahkan.");
        } catch (PDOException $e) {
            $msg = str_contains($e->getMessage(), 'Duplicate')
                ? "Nomor urut {$noUrut} sudah digunakan peserta lain."
                : "Gagal menyimpan: ".$e->getMessage();
            $redir('danger', $msg);
        }
    }

    // ── EDIT ──────────────────────────────────────────────────
    elseif ($aksi === 'edit') {
        $idPeserta   = filter_input(INPUT_POST, 'id_peserta', FILTER_VALIDATE_INT);
        $noUrut      = filter_input(INPUT_POST, 'no_urut',    FILTER_VALIDATE_INT);
        $namaRegu    = trim($_POST['nama_regu']    ?? '');
        $asalSekolah = trim($_POST['asal_sekolah'] ?? '');

        if (!$idPeserta || !$noUrut || $noUrut < 1 || $namaRegu === '') {
            $redir('danger', 'Data tidak valid. Semua field wajib terisi.');
        }
        try {
            $s = $pdo->prepare(
                "UPDATE tabel_peserta
                    SET no_urut=:nu, nama_regu=:nr, asal_sekolah=:as
                  WHERE id_peserta=:ip AND id_event=:ie"
            );
            $s->execute([':nu'=>$noUrut, ':nr'=>$namaRegu, ':as'=>($asalSekolah ?: null),
                         ':ip'=>$idPeserta, ':ie'=>$idEvent]);
            $redir('success', "Data <strong>".htmlspecialchars($namaRegu)."</strong> berhasil diperbarui.");
        } catch (PDOException $e) {
            $msg = str_contains($e->getMessage(), 'Duplicate')
                ? "Nomor urut {$noUrut} sudah digunakan peserta lain."
                : "Gagal memperbarui: ".$e->getMessage();
            $redir('danger', $msg);
        }
    }

    // ── HAPUS ─────────────────────────────────────────────────
    elseif ($aksi === 'hapus') {
        $idPeserta = filter_input(INPUT_POST, 'id_peserta', FILTER_VALIDATE_INT);
        if (!$idPeserta) { $redir('danger', 'ID peserta tidak valid.'); }

        try {
            $sn = $pdo->prepare("SELECT nama_regu FROM tabel_peserta WHERE id_peserta=:ip AND id_event=:ie LIMIT 1");
            $sn->execute([':ip'=>$idPeserta, ':ie'=>$idEvent]);
            $nama = $sn->fetchColumn() ?: "ID #{$idPeserta}";

            $sd = $pdo->prepare("DELETE FROM tabel_peserta WHERE id_peserta=:ip AND id_event=:ie");
            $sd->execute([':ip'=>$idPeserta, ':ie'=>$idEvent]);
            $redir('success', "Peserta <strong>".htmlspecialchars($nama)."</strong> berhasil dihapus.");
        } catch (PDOException $e) {
            $redir('danger', "Gagal menghapus: data masih terkait penilaian yang tersimpan.");
        }
    }
}

// ════════════════════════════════════════════════════════════════
//  FETCH DATA
// ════════════════════════════════════════════════════════════════
$stmtEv = $pdo->prepare("SELECT nama_event FROM tabel_event WHERE id_event=:ie LIMIT 1");
$stmtEv->execute([':ie'=>$idEvent]);
$namaEvent = $stmtEv->fetchColumn() ?: 'Event #'.$idEvent;

$events = $pdo->query("SELECT id_event, nama_event FROM tabel_event WHERE status_aktif=1 ORDER BY id_event DESC")->fetchAll();

$stmtList = $pdo->prepare(
    "SELECT p.id_peserta, p.no_urut, p.nama_regu, p.asal_sekolah,
            (SELECT COUNT(*) FROM tabel_penilaian n WHERE n.id_peserta=p.id_peserta) AS jml_nilai
       FROM tabel_peserta p
      WHERE p.id_event=:ie
      ORDER BY p.no_urut ASC"
);
$stmtList->execute([':ie'=>$idEvent]);
$daftarPeserta   = $stmtList->fetchAll();
$totalPeserta    = count($daftarPeserta);
$sudahDinilai    = count(array_filter($daftarPeserta, fn($p) => $p['jml_nilai'] > 0));
$noUrutBerikutnya = $totalPeserta + 1;
?>
<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Manajemen Peserta &mdash; Paskibra SaaS</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
  <link href="https://fonts.googleapis.com/css2?family=Sora:wght@600;700;800&family=DM+Sans:wght@400;500&display=swap" rel="stylesheet">
  <style>
    :root {
      --navy:#0d1b2e; --navy-mid:#162640; --navy-card:#1c3050;
      --border:rgba(255,255,255,.07); --text:#dce8f5; --muted:#7a94af;
      --accent:#f5a623; --cyan:#38bdf8; --green:#2dc653;
      --red:#e63946; --yellow:#fbbf24;
    }
    *,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
    body{font-family:'DM Sans',sans-serif;background:var(--navy);color:var(--text);min-height:100vh}
    body::before{content:'';position:fixed;inset:0;z-index:0;pointer-events:none;
      background:repeating-linear-gradient(-45deg,transparent,transparent 40px,
        rgba(255,255,255,.014) 40px,rgba(255,255,255,.014) 41px)}
    .page{position:relative;z-index:1;padding:2rem 1.5rem 4rem}

    /* Page header */
    .page-header{display:flex;align-items:flex-start;justify-content:space-between;
      flex-wrap:wrap;gap:1rem;margin-bottom:1.75rem}
    .page-eyebrow{font-size:.68rem;font-weight:700;letter-spacing:.12em;text-transform:uppercase;
      color:var(--cyan);margin-bottom:.3rem}
    .page-title{font-family:'Sora',sans-serif;font-size:1.5rem;font-weight:800;color:#fff;margin:0}
    .page-sub{font-size:.82rem;color:var(--muted);margin-top:3px}

    /* Btn add */
    .btn-add{display:inline-flex;align-items:center;gap:7px;
      background:var(--cyan);color:#0d1b2e;font-family:'Sora',sans-serif;font-weight:700;
      font-size:.88rem;border:none;border-radius:10px;padding:.6rem 1.35rem;
      cursor:pointer;transition:all .15s;white-space:nowrap}
    .btn-add:hover{background:#29a8d8;transform:translateY(-1px)}

    /* Flash */
    .flash{border-radius:12px;padding:.85rem 1.1rem;font-size:.88rem;margin-bottom:1.25rem;
      display:flex;align-items:center;gap:9px;border-left:4px solid}
    .flash-success{background:rgba(45,198,83,.1);border-color:var(--green);color:#a8f0be}
    .flash-danger {background:rgba(230,57,70,.1); border-color:var(--red);  color:#f5a8a8}

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

    /* Table */
    .tbl-wrap{background:var(--navy-card);border:1px solid var(--border);
      border-radius:14px;overflow:hidden}
    .tbl-peserta{width:100%;border-collapse:collapse}
    .tbl-peserta thead th{background:rgba(255,255,255,.04);padding:.65rem 1rem;
      font-size:.67rem;font-weight:700;letter-spacing:.1em;text-transform:uppercase;
      color:var(--muted);border-bottom:1px solid var(--border);white-space:nowrap}
    .tbl-peserta tbody td{padding:.8rem 1rem;border-bottom:1px solid var(--border);
      font-size:.85rem;vertical-align:middle}
    .tbl-peserta tbody tr:last-child td{border-bottom:none}
    .tbl-peserta tbody tr{transition:background .15s}
    .tbl-peserta tbody tr:hover td{background:rgba(255,255,255,.025)}
    .no-badge{display:inline-flex;align-items:center;justify-content:center;
      width:32px;height:32px;border-radius:50%;background:rgba(56,189,248,.13);
      color:var(--cyan);font-family:'Sora',sans-serif;font-weight:800;font-size:.82rem}
    .nama-regu{font-weight:600;color:#fff}
    .asal{font-size:.74rem;color:var(--muted);margin-top:2px}
    .v-badge{font-size:.7rem;padding:2px 8px;border-radius:100px;
      background:rgba(45,198,83,.12);color:var(--green);border:1px solid rgba(45,198,83,.25)}
    .v-badge.zero{background:rgba(255,255,255,.05);color:var(--muted);border-color:var(--border)}

    /* Action buttons */
    .btn-edit{display:inline-flex;align-items:center;gap:4px;
      background:rgba(251,191,36,.12);border:1px solid rgba(251,191,36,.3);
      color:var(--yellow);border-radius:7px;padding:4px 11px;
      font-size:.76rem;font-weight:600;cursor:pointer;transition:all .15s;white-space:nowrap}
    .btn-edit:hover{background:rgba(251,191,36,.22);color:#fde68a}
    .btn-del{display:inline-flex;align-items:center;gap:4px;
      background:rgba(230,57,70,.12);border:1px solid rgba(230,57,70,.3);
      color:#ff8a8a;border-radius:7px;padding:4px 11px;
      font-size:.76rem;font-weight:600;cursor:pointer;transition:all .15s;white-space:nowrap}
    .btn-del:hover{background:rgba(230,57,70,.22);color:#ffb3b3}

    /* Empty state */
    .empty-state{text-align:center;padding:3.5rem 1rem;color:var(--muted)}
    .empty-state .ei{font-size:2.8rem;display:block;margin-bottom:.75rem}

    /* Modal */
    .modal-content{background:var(--navy-card);border:1px solid var(--border);
      border-radius:16px;color:var(--text)}
    .modal-header{border-bottom:1px solid var(--border);padding:1.1rem 1.4rem}
    .modal-title{font-family:'Sora',sans-serif;font-weight:700;font-size:1rem;color:#fff}
    .modal-footer{border-top:1px solid var(--border)}
    .lbl{font-size:.75rem;font-weight:600;letter-spacing:.05em;text-transform:uppercase;
      color:var(--muted);margin-bottom:5px;display:block}
    .fctl{background:#0f2034;color:var(--text);border:1px solid rgba(255,255,255,.12);
      border-radius:9px;padding:.6rem .9rem;width:100%;font-size:.9rem;
      font-family:'DM Sans',sans-serif;transition:border-color .15s}
    .fctl:focus{outline:none;border-color:var(--cyan);box-shadow:0 0 0 3px rgba(56,189,248,.12)}
    .fhint{font-size:.72rem;color:var(--muted);margin-top:4px}
    .btn-ms{background:var(--cyan);color:#0d1b2e;border:none;font-family:'Sora',sans-serif;
      font-weight:700;font-size:.88rem;border-radius:9px;padding:.6rem 1.4rem;cursor:pointer;transition:all .15s}
    .btn-ms:hover{background:#29a8d8}
    .btn-mc{background:rgba(255,255,255,.07);color:var(--muted);border:none;
      border-radius:9px;padding:.6rem 1.1rem;font-size:.88rem;cursor:pointer;transition:all .15s}
    .btn-mc:hover{background:rgba(255,255,255,.12);color:var(--text)}
    ::-webkit-scrollbar{width:5px}
    ::-webkit-scrollbar-thumb{background:rgba(255,255,255,.1);border-radius:3px}
  </style>
</head>
<body>

<?php
$navActive  = 'peserta';
$navIdEvent = $idEvent;
require_once __DIR__ . '/navbar.php';
?>

<div class="page">
<div class="container-fluid" style="max-width:960px;">

  <!-- Page header -->
  <div class="page-header">
    <div>
      <div class="page-eyebrow"><i class="bi bi-people-fill me-1"></i>Administrasi</div>
      <h1 class="page-title">Manajemen Data Peserta</h1>
      <div class="page-sub"><?= htmlspecialchars($namaEvent) ?></div>
    </div>
    <button class="btn-add" data-bs-toggle="modal" data-bs-target="#modalTambah">
      <i class="bi bi-plus-lg"></i>Tambah Peserta
    </button>
  </div>

  <!-- Flash message -->
  <?php if ($flash): ?>
  <div class="flash flash-<?= htmlspecialchars($flash['type']) ?>" id="flash-msg">
    <i class="bi bi-<?= $flash['type']==='success' ? 'check-circle-fill' : 'exclamation-triangle-fill' ?>"></i>
    <span><?= $flash['msg'] ?></span>
  </div>
  <?php endif; ?>

  <!-- Event switcher -->
  <?php if (count($events) > 1): ?>
  <form method="GET" class="ev-bar">
    <span class="ev-label"><i class="bi bi-calendar-event me-1"></i>Event:</span>
    <select name="id_event" class="ev-select">
      <?php foreach ($events as $ev): ?>
        <option value="<?= (int)$ev['id_event'] ?>" <?= $ev['id_event']==$idEvent ? 'selected':'' ?>>
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
      <div class="sc-icon" style="background:rgba(56,189,248,.13);color:var(--cyan);">
        <i class="bi bi-people-fill"></i></div>
      <div><div class="sc-val"><?= $totalPeserta ?></div><div class="sc-lbl">Total Peserta</div></div>
    </div>
    <div class="stat-chip">
      <div class="sc-icon" style="background:rgba(45,198,83,.12);color:var(--green);">
        <i class="bi bi-clipboard2-check-fill"></i></div>
      <div><div class="sc-val"><?= $sudahDinilai ?></div><div class="sc-lbl">Sudah Dinilai</div></div>
    </div>
    <div class="stat-chip">
      <div class="sc-icon" style="background:rgba(245,166,35,.12);color:var(--accent);">
        <i class="bi bi-hash"></i></div>
      <div><div class="sc-val"><?= $noUrutBerikutnya ?></div><div class="sc-lbl">No. Urut Berikutnya</div></div>
    </div>
  </div>

  <!-- Tabel peserta -->
  <div class="tbl-wrap">
    <?php if (empty($daftarPeserta)): ?>
    <div class="empty-state">
      <span class="ei">👥</span>
      <p>Belum ada peserta terdaftar untuk event ini.</p>
      <button class="btn-add" style="margin:1rem auto 0;"
              data-bs-toggle="modal" data-bs-target="#modalTambah">
        <i class="bi bi-plus-lg"></i>Tambah Peserta Pertama
      </button>
    </div>
    <?php else: ?>
    <table class="tbl-peserta">
      <thead>
        <tr>
          <th style="width:55px;text-align:center;">No.</th>
          <th>Nama Regu / Pangkalan</th>
          <th>Asal Sekolah / Instansi</th>
          <th style="width:110px;text-align:center;">Nilai</th>
          <th style="width:140px;text-align:center;">Aksi</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($daftarPeserta as $p): ?>
        <tr>
          <td style="text-align:center;">
            <span class="no-badge"><?= (int)$p['no_urut'] ?></span>
          </td>
          <td><div class="nama-regu"><?= htmlspecialchars($p['nama_regu']) ?></div></td>
          <td>
            <?php if ($p['asal_sekolah']): ?>
              <div class="asal"><i class="bi bi-building me-1"></i><?= htmlspecialchars($p['asal_sekolah']) ?></div>
            <?php else: ?>
              <span style="color:var(--muted);font-size:.78rem;">—</span>
            <?php endif; ?>
          </td>
          <td style="text-align:center;">
            <span class="v-badge <?= $p['jml_nilai']>0?'':'zero' ?>">
              <?php if ($p['jml_nilai'] > 0): ?>
                <i class="bi bi-check-circle-fill"></i> <?= (int)$p['jml_nilai'] ?> nilai
              <?php else: ?>
                Belum dinilai
              <?php endif; ?>
            </span>
          </td>
          <td style="text-align:center;">
            <div class="d-flex gap-1 justify-content-center">
              <!-- Edit -->
              <button class="btn-edit"
                      data-bs-toggle="modal" data-bs-target="#modalEdit"
                      data-id="<?= (int)$p['id_peserta'] ?>"
                      data-no="<?= (int)$p['no_urut'] ?>"
                      data-nama="<?= htmlspecialchars($p['nama_regu'], ENT_QUOTES) ?>"
                      data-asal="<?= htmlspecialchars($p['asal_sekolah'] ?? '', ENT_QUOTES) ?>"
                      onclick="isiEdit(this)">
                <i class="bi bi-pencil-fill"></i>Edit
              </button>
              <!-- Hapus -->
              <form method="POST" style="display:inline;"
                    onsubmit="return konfHapus(this,'<?= htmlspecialchars($p['nama_regu'],ENT_QUOTES) ?>',<?= (int)$p['jml_nilai'] ?>)">
                <input type="hidden" name="aksi"       value="hapus">
                <input type="hidden" name="id_event"   value="<?= $idEvent ?>">
                <input type="hidden" name="id_peserta" value="<?= (int)$p['id_peserta'] ?>">
                <button type="submit" class="btn-del">
                  <i class="bi bi-trash3-fill"></i>Hapus
                </button>
              </form>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <div style="padding:.6rem 1rem;font-size:.7rem;color:var(--muted);
         border-top:1px solid var(--border);display:flex;justify-content:space-between;flex-wrap:wrap;gap:4px;">
      <span><?= $totalPeserta ?> peserta &middot; Diurutkan: Nomor Urut</span>
      <span><?= htmlspecialchars($namaEvent) ?></span>
    </div>
    <?php endif; ?>
  </div><!-- /tbl-wrap -->

</div>
</div><!-- /page -->


<!-- ═══ MODAL: TAMBAH ═══ -->
<div class="modal fade" id="modalTambah" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <form method="POST" novalidate>
        <input type="hidden" name="aksi"     value="tambah">
        <input type="hidden" name="id_event" value="<?= $idEvent ?>">
        <div class="modal-header">
          <h5 class="modal-title">
            <i class="bi bi-plus-circle-fill me-2" style="color:var(--cyan);"></i>Tambah Peserta
          </h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"
                  style="filter:invert(1) brightness(2)"></button>
        </div>
        <div class="modal-body" style="padding:1.25rem 1.4rem;">
          <div class="mb-3">
            <label class="lbl" for="t_no">Nomor Urut / Nomor Dada *</label>
            <input type="number" id="t_no" name="no_urut" class="fctl"
                   min="1" max="999" value="<?= $noUrutBerikutnya ?>" required>
            <div class="fhint">Harus unik dalam satu event. Saran: <?= $noUrutBerikutnya ?></div>
          </div>
          <div class="mb-3">
            <label class="lbl" for="t_nama">Nama Regu / Pangkalan *</label>
            <input type="text" id="t_nama" name="nama_regu" class="fctl"
                   maxlength="255" placeholder="Contoh: Regu Merah" required>
          </div>
          <div>
            <label class="lbl" for="t_asal">Asal Sekolah / Instansi</label>
            <input type="text" id="t_asal" name="asal_sekolah" class="fctl"
                   maxlength="255" placeholder="Contoh: SMA Negeri 1 Malang (opsional)">
          </div>
        </div>
        <div class="modal-footer gap-2">
          <button type="button" class="btn-mc" data-bs-dismiss="modal">Batal</button>
          <button type="submit" class="btn-ms">
            <i class="bi bi-plus-lg me-1"></i>Simpan Peserta
          </button>
        </div>
      </form>
    </div>
  </div>
</div>


<!-- ═══ MODAL: EDIT ═══ -->
<div class="modal fade" id="modalEdit" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <form method="POST" novalidate>
        <input type="hidden" name="aksi"       value="edit">
        <input type="hidden" name="id_event"   value="<?= $idEvent ?>">
        <input type="hidden" name="id_peserta" id="e_id">
        <div class="modal-header">
          <h5 class="modal-title">
            <i class="bi bi-pencil-fill me-2" style="color:var(--yellow);"></i>Edit Peserta
          </h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"
                  style="filter:invert(1) brightness(2)"></button>
        </div>
        <div class="modal-body" style="padding:1.25rem 1.4rem;">
          <div class="mb-3">
            <label class="lbl" for="e_no">Nomor Urut / Nomor Dada *</label>
            <input type="number" id="e_no" name="no_urut" class="fctl" min="1" max="999" required>
          </div>
          <div class="mb-3">
            <label class="lbl" for="e_nama">Nama Regu / Pangkalan *</label>
            <input type="text" id="e_nama" name="nama_regu" class="fctl" maxlength="255" required>
          </div>
          <div>
            <label class="lbl" for="e_asal">Asal Sekolah / Instansi</label>
            <input type="text" id="e_asal" name="asal_sekolah" class="fctl"
                   maxlength="255" placeholder="(opsional)">
          </div>
        </div>
        <div class="modal-footer gap-2">
          <button type="button" class="btn-mc" data-bs-dismiss="modal">Batal</button>
          <button type="submit" class="btn-ms" style="background:var(--yellow);color:#1a0f00;">
            <i class="bi bi-check-lg me-1"></i>Perbarui Data
          </button>
        </div>
      </form>
    </div>
  </div>
</div>


<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
/* Isi form modal Edit dari data-* attribute tombol */
function isiEdit(btn) {
  document.getElementById('e_id').value   = btn.dataset.id;
  document.getElementById('e_no').value   = btn.dataset.no;
  document.getElementById('e_nama').value = btn.dataset.nama;
  document.getElementById('e_asal').value = btn.dataset.asal;
}

/* Konfirmasi hapus — peringatan ekstra jika ada data nilai */
function konfHapus(form, nama, jmlNilai) {
  let msg = `Yakin ingin menghapus peserta:\n"${nama}"?`;
  if (jmlNilai > 0) {
    msg += `\n\n\u26a0\ufe0f Peserta ini memiliki ${jmlNilai} data nilai tersimpan.`
         + `\nMenghapus akan menghapus SEMUA nilai tersebut.\nTidak bisa dibatalkan!`;
  }
  return confirm(msg);
}

/* Auto-dismiss flash alert setelah 5 detik */
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
