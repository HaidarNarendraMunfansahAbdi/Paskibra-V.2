<?php
/**
 * Proteksi session — harus berada di baris paling atas.
 * Jika belum login atau role kosong, redirect ke login.php.
 */
require_once __DIR__ . '/auth_guard.php';
authGuard(['admin', 'juri']);   // admin & juri boleh akses rekap
$_me = currentUser();           // data user aktif (nama, role, dll)
?>
<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Rekap Penilaian – Paskibra SaaS</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" />
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet" />
  <link href="https://fonts.googleapis.com/css2?family=Sora:wght@600;700;800&family=DM+Sans:ital,wght@0,300;0,400;0,500;1,400&display=swap" rel="stylesheet" />
  <style>
    /* ─── CSS Variables ─── */
    :root {
      --navy:        #0d1b2e;
      --navy-mid:    #162640;
      --navy-card:   #1c3050;
      --navy-deep:   #0a1520;
      --accent:      #f5a623;
      --accent-dim:  #c47d10;
      --accent-glow: rgba(245,166,35,0.18);
      --cyan:        #38bdf8;
      --cyan-dim:    rgba(56,189,248,0.12);
      --red-flag:    #e63946;
      --green-ok:    #2dc653;
      --purple:      #a78bfa;
      --muted:       #8ca0b8;
      --border:      rgba(255,255,255,0.07);
      --border-lit:  rgba(255,255,255,0.13);
      --text:        #dce8f5;
      --text-dim:    #7a94af;
      --input-bg:    #0f2034;
      --radius:      14px;
      --shadow:      0 8px 32px rgba(0,0,0,0.45);
      --shadow-lg:   0 20px 60px rgba(0,0,0,0.6);
    }

    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

    body {
      font-family: 'DM Sans', sans-serif;
      background-color: var(--navy);
      color: var(--text);
      min-height: 100vh;
      overflow-x: hidden;
    }

    body::before {
      content: '';
      position: fixed; inset: 0;
      background-image: repeating-linear-gradient(-45deg, transparent, transparent 40px,
        rgba(255,255,255,0.018) 40px, rgba(255,255,255,0.018) 41px);
      pointer-events: none; z-index: 0;
    }

    /* ─── Navbar ─── */
    .app-navbar {
      background: var(--navy-mid);
      border-bottom: 1px solid var(--border);
      padding: 0.85rem 1.5rem;
      position: sticky; top: 0; z-index: 1000;
      backdrop-filter: blur(12px);
    }
    .navbar-brand-text {
      font-family: 'Sora', sans-serif; font-weight: 800;
      font-size: 1.15rem; letter-spacing: -0.01em; color: #fff;
    }
    .navbar-brand-text span { color: var(--accent); }
    .navbar-subtitle { font-size: 0.7rem; color: var(--text-dim); letter-spacing: 0.08em; text-transform: uppercase; margin-top: 1px; }

    .conn-badge {
      display: inline-flex; align-items: center; gap: 6px;
      padding: 5px 14px; border-radius: 100px; font-size: 0.78rem;
      font-weight: 500; letter-spacing: 0.02em; transition: all 0.4s ease;
      border: 1px solid transparent;
    }
    .conn-badge.online  { background: rgba(45,198,83,0.12); border-color: rgba(45,198,83,0.3); color: var(--green-ok); }
    .conn-badge.offline { background: rgba(230,57,70,0.12);  border-color: rgba(230,57,70,0.3);  color: var(--red-flag); }
    .conn-dot { width: 7px; height: 7px; border-radius: 50%; display: inline-block; }
    .conn-badge.online  .conn-dot { background: var(--green-ok); box-shadow: 0 0 6px var(--green-ok); animation: pulse-green 2s infinite; }
    .conn-badge.offline .conn-dot { background: var(--red-flag); }
    @keyframes pulse-green { 0%,100%{opacity:1} 50%{opacity:0.4} }

    .queue-pill {
      display: none; align-items: center; gap: 6px; padding: 5px 12px;
      border-radius: 100px; font-size: 0.75rem; font-weight: 500;
      background: rgba(245,166,35,0.12); border: 1px solid rgba(245,166,35,0.3); color: var(--accent);
    }
    .queue-pill.show { display: inline-flex; }

    /* ─── Layout ─── */
    .page-wrapper { position: relative; z-index: 1; padding: 2rem 0 4rem; }

    .section-eyebrow { font-size: 0.68rem; font-weight: 600; letter-spacing: 0.12em; text-transform: uppercase; color: var(--accent); margin-bottom: 0.35rem; }
    .section-title { font-family: 'Sora', sans-serif; font-size: 1.55rem; font-weight: 700; color: #fff; margin-bottom: 0; }

    /* ─── Cards ─── */
    .glass-card {
      background: var(--navy-card); border: 1px solid var(--border);
      border-radius: var(--radius); box-shadow: var(--shadow); overflow: hidden;
    }
    .card-header-custom {
      background: rgba(255,255,255,0.035); border-bottom: 1px solid var(--border);
      padding: 1rem 1.4rem; display: flex; align-items: center; gap: 10px;
    }
    .card-icon {
      width: 34px; height: 34px; border-radius: 8px;
      background: rgba(245,166,35,0.15); color: var(--accent);
      display: flex; align-items: center; justify-content: center;
      font-size: 1rem; flex-shrink: 0;
    }
    .card-icon.cyan-icon { background: var(--cyan-dim); color: var(--cyan); }
    .card-title-text { font-family: 'Sora', sans-serif; font-weight: 600; font-size: 0.92rem; color: #fff; }
    .card-sub-text { font-size: 0.72rem; color: var(--text-dim); }

    /* ─── Form Controls ─── */
    .form-label-custom { font-size: 0.76rem; font-weight: 500; letter-spacing: 0.04em; text-transform: uppercase; color: var(--muted); margin-bottom: 0.4rem; display: block; }
    .form-control-dark, .form-select-dark {
      background-color: var(--input-bg); border: 1px solid rgba(255,255,255,0.1);
      border-radius: 10px; color: var(--text); font-family: 'DM Sans', sans-serif;
      font-size: 0.9rem; padding: 0.6rem 0.9rem;
      transition: border-color 0.2s, box-shadow 0.2s; width: 100%;
    }
    .form-control-dark:focus, .form-select-dark:focus {
      background-color: var(--input-bg); border-color: var(--accent);
      box-shadow: 0 0 0 3px rgba(245,166,35,0.15); color: var(--text); outline: none;
    }
    .form-select-dark option { background: var(--navy-mid); }
    .form-control-dark::placeholder { color: var(--text-dim); }
    .form-control-dark:disabled { opacity: 0.4; cursor: not-allowed; }

    /* ─── Kriteria rows ─── */
    .kategori-group { margin-bottom: 1.25rem; }
    .kategori-label {
      display: flex; align-items: center; gap: 8px;
      font-family: 'Sora', sans-serif; font-size: 0.78rem; font-weight: 700;
      letter-spacing: 0.08em; text-transform: uppercase; color: var(--accent);
      padding: 0.45rem 0.9rem; background: rgba(245,166,35,0.07);
      border-left: 3px solid var(--accent); border-radius: 0 6px 6px 0; margin-bottom: 0.6rem;
    }
    .kat-badge { background: var(--accent); color: var(--navy); font-size: 0.68rem; font-weight: 800; padding: 2px 7px; border-radius: 4px; }

    .kriteria-row {
      display: grid; grid-template-columns: 1fr auto; align-items: center;
      gap: 0.75rem; padding: 0.55rem 0.75rem; border-radius: 8px;
      background: rgba(255,255,255,0.025); border: 1px solid transparent;
      margin-bottom: 6px; transition: background 0.2s, border-color 0.2s;
    }
    .kriteria-row:hover { background: rgba(255,255,255,0.04); border-color: rgba(255,255,255,0.07); }
    .kriteria-row.omr-detected {
      background: rgba(45,198,83,0.07); border-color: rgba(45,198,83,0.25);
      animation: detected-flash 0.6s ease;
    }
    .kriteria-row.omr-undetected {
      background: rgba(245,166,35,0.05); border-color: rgba(245,166,35,0.15);
    }
    @keyframes detected-flash { 0%{background:rgba(45,198,83,0.25)} 100%{background:rgba(45,198,83,0.07)} }

    .kriteria-name { font-size: 0.85rem; font-weight: 400; color: var(--text); line-height: 1.3; }
    .kriteria-sub { font-size: 0.72rem; color: var(--text-dim); }

    .nilai-input-wrap { display: flex; flex-direction: column; align-items: flex-end; gap: 3px; min-width: 100px; }
    .nilai-input-wrap input[type=number] { width: 90px; text-align: center; font-family: 'Sora', sans-serif; font-size: 1rem; font-weight: 600; }
    .nilai-range-hint { font-size: 0.65rem; color: var(--text-dim); }

    .omr-status-badge {
      display: none; font-size: 0.6rem; font-weight: 600; padding: 2px 6px;
      border-radius: 4px; letter-spacing: 0.05em; text-transform: uppercase; margin-top: 2px;
    }
    .omr-status-badge.detected { display: inline-block; background: rgba(45,198,83,0.2); color: var(--green-ok); border: 1px solid rgba(45,198,83,0.3); }
    .omr-status-badge.undetected { display: inline-block; background: rgba(245,166,35,0.15); color: var(--accent); border: 1px solid rgba(245,166,35,0.25); }

    /* Highlight input yang diisi AI — flash hijau lalu fade ke glow */
    @keyframes ai-fill-flash {
      0%   { box-shadow: 0 0 0 3px rgba(45,198,83,0.9);  border-color: var(--green-ok); background: rgba(45,198,83,0.18); }
      60%  { box-shadow: 0 0 0 3px rgba(45,198,83,0.4);  border-color: var(--green-ok); background: rgba(45,198,83,0.08); }
      100% { box-shadow: 0 0 0 2px rgba(45,198,83,0.25); border-color: var(--green-ok); background: transparent; }
    }
    .ai-filled { animation: ai-fill-flash 1.2s ease forwards; }

    .opsi-pills { display: flex; flex-wrap: wrap; gap: 4px; margin-top: 5px; }
    .opsi-pill {
      background: rgba(255,255,255,0.06); border: 1px solid rgba(255,255,255,0.1);
      color: var(--muted); border-radius: 5px; padding: 2px 8px; font-size: 0.68rem;
      font-weight: 500; cursor: pointer; transition: all 0.15s; white-space: nowrap;
    }
    .opsi-pill:hover { background: rgba(245,166,35,0.15); border-color: var(--accent); color: var(--accent); }
    .opsi-pill.active { background: var(--accent); border-color: var(--accent); color: var(--navy); font-weight: 700; }

    /* ─── AI Scanner Card ─── */
    .ai-upload-zone {
      border: 2px dashed rgba(167,139,250,0.35); border-radius: 12px;
      padding: 2rem 1.5rem; text-align: center; cursor: pointer;
      transition: all 0.25s; background: rgba(167,139,250,0.04);
      position: relative; overflow: hidden;
    }
    .ai-upload-zone:hover, .ai-upload-zone.drag-over {
      border-color: var(--purple); background: rgba(167,139,250,0.09);
    }
    .ai-upload-zone input[type=file] {
      position: absolute; inset: 0; opacity: 0; cursor: pointer; width: 100%; height: 100%;
    }
    .ai-upload-icon { font-size: 2.4rem; color: var(--purple); margin-bottom: 0.6rem; display: block; }
    .ai-upload-title { font-family: 'Sora', sans-serif; font-size: 1rem; font-weight: 700; color: #fff; margin-bottom: 4px; }
    .ai-upload-hint { font-size: 0.75rem; color: var(--text-dim); }

    /* Preview thumbnail setelah pilih file */
    .ai-preview-wrap {
      display: none; margin-top: 1rem; border-radius: 10px; overflow: hidden;
      border: 1px solid rgba(167,139,250,0.25); position: relative;
    }
    .ai-preview-wrap.show { display: block; }
    .ai-preview-wrap img { display: block; width: 100%; max-height: 320px; object-fit: contain; background: #000; }
    .ai-preview-filename {
      padding: 5px 12px; background: rgba(167,139,250,0.1);
      font-size: 0.72rem; color: var(--text-dim);
      display: flex; align-items: center; gap: 6px;
    }

    /* Loading state */
    .ai-loading {
      display: none; flex-direction: column; align-items: center;
      justify-content: center; gap: 12px; padding: 2rem;
    }
    .ai-loading.show { display: flex; }
    .ai-spinner {
      width: 44px; height: 44px; border-radius: 50%;
      border: 3px solid rgba(167,139,250,0.2);
      border-top-color: var(--purple);
      animation: ai-spin 0.9s linear infinite;
    }
    @keyframes ai-spin { to { transform: rotate(360deg); } }
    .ai-loading-text { font-size: 0.85rem; color: var(--text-dim); }
    .ai-loading-subtext { font-size: 0.72rem; color: rgba(167,139,250,0.6); }

    /* Hasil scan AI table */
    .ai-results-wrap { display: none; margin-top: 0.85rem; }
    .ai-results-wrap.show { display: block; }
    .ai-result-row {
      display: grid; grid-template-columns: 2fr 1fr 80px;
      padding: 6px 12px; border-bottom: 1px solid var(--border);
      align-items: center; gap: 8px; font-size: 0.79rem;
    }
    .ai-result-row.header {
      font-size: 0.67rem; font-weight: 600; letter-spacing: 0.06em;
      text-transform: uppercase; color: var(--text-dim);
      background: rgba(255,255,255,0.03); position: sticky; top: 0;
    }
    .ai-result-row:hover { background: rgba(255,255,255,0.025); }
    .ai-val-ok  { color: var(--green-ok); font-weight: 700; font-family: 'Sora',sans-serif; }
    .ai-val-skip { color: var(--text-dim); font-style: italic; }

    /* ─── Skeleton ─── */
    .skeleton {
      background: linear-gradient(90deg,rgba(255,255,255,0.04) 25%,rgba(255,255,255,0.08) 50%,rgba(255,255,255,0.04) 75%);
      background-size: 200% 100%; animation: shimmer 1.4s infinite; border-radius: 6px;
    }
    @keyframes shimmer { 0%{background-position:200% 0} 100%{background-position:-200% 0} }
    .skeleton-row { height: 52px; margin-bottom: 6px; }
    .skeleton-label { height: 28px; width: 55%; margin-bottom: 10px; }

    /* ─── Buttons ─── */
    .btn-primary-custom {
      background: var(--accent); border: none; color: var(--navy);
      font-family: 'Sora', sans-serif; font-weight: 700; font-size: 0.9rem;
      padding: 0.65rem 1.6rem; border-radius: 10px; transition: all 0.2s;
      cursor: pointer; display: inline-flex; align-items: center; gap: 8px;
    }
    .btn-primary-custom:hover:not(:disabled) { background: var(--accent-dim); transform: translateY(-1px); box-shadow: 0 6px 20px rgba(245,166,35,0.3); }
    .btn-primary-custom:disabled { opacity: 0.45; cursor: not-allowed; }

    .btn-cyan-custom {
      background: var(--cyan-dim); border: 1px solid rgba(56,189,248,0.3);
      color: var(--cyan); font-family: 'Sora', sans-serif; font-weight: 600;
      font-size: 0.85rem; padding: 0.55rem 1.2rem; border-radius: 10px;
      transition: all 0.2s; cursor: pointer; display: inline-flex; align-items: center; gap: 7px;
    }
    .btn-cyan-custom:hover:not(:disabled) { background: rgba(56,189,248,0.18); border-color: var(--cyan); box-shadow: 0 4px 14px rgba(56,189,248,0.2); }
    .btn-cyan-custom:disabled { opacity: 0.35; cursor: not-allowed; }

    .btn-ghost { background: transparent; border: 1px solid rgba(255,255,255,0.12); color: var(--muted); font-family: 'DM Sans', sans-serif; font-size: 0.85rem; padding: 0.55rem 1.1rem; border-radius: 10px; transition: all 0.2s; cursor: pointer; display: inline-flex; align-items: center; gap: 6px; }
    .btn-ghost:hover { border-color: var(--text-dim); color: var(--text); }
    .btn-ghost.danger:hover { border-color: var(--red-flag); color: var(--red-flag); }

    /* ─── Sync strip ─── */
    #sync-strip {
      display: none; align-items: center; gap: 10px; padding: 0.75rem 1.25rem;
      background: rgba(245,166,35,0.08); border: 1px solid rgba(245,166,35,0.2);
      border-radius: var(--radius); font-size: 0.83rem; color: var(--accent); margin-bottom: 1.25rem;
    }
    #sync-strip.show { display: flex; }
    #sync-strip .spinner-border { width:1rem;height:1rem;border-width:2px; }

    /* ─── Toast ─── */
    #toast-container { position: fixed; bottom: 1.5rem; right: 1.5rem; z-index: 9999; display: flex; flex-direction: column; gap: 10px; max-width: 380px; }
    .toast-item { padding: 0.85rem 1.1rem; border-radius: 12px; font-size: 0.84rem; line-height: 1.45; border-left: 4px solid; display: flex; align-items: flex-start; gap: 10px; animation: toast-in 0.35s cubic-bezier(.34,1.56,.64,1); box-shadow: 0 8px 24px rgba(0,0,0,0.5); }
    .toast-item.toast-success { background:#0d2b1a; border-color:var(--green-ok); color:#a8f0be; }
    .toast-item.toast-offline { background:#2b1a0d; border-color:var(--accent); color:#f5d7a8; }
    .toast-item.toast-error   { background:#2b0d0d; border-color:var(--red-flag); color:#f5a8a8; }
    .toast-item.toast-info    { background:#0d1e2b; border-color:#4ea8d8; color:#a8d8f5; }
    .toast-item.toast-scan    { background:#0d2028; border-color:var(--cyan); color:#a8e4f5; }
    .toast-icon { font-size: 1.05rem; flex-shrink: 0; margin-top: 1px; }
    .toast-close { margin-left:auto; background:none; border:none; color:inherit; opacity:0.5; cursor:pointer; font-size:0.9rem; flex-shrink:0; padding:0; }
    .toast-close:hover { opacity:1; }
    @keyframes toast-in { from{opacity:0;transform:translateX(30px) scale(0.95)} to{opacity:1;transform:translateX(0) scale(1)} }
    .toast-out { animation: toast-out 0.3s ease forwards; }
    @keyframes toast-out { to{opacity:0;transform:translateX(30px)} }

    /* ─── State boxes ─── */
    .state-box { text-align:center; padding:3rem 1rem; color:var(--text-dim); }
    .state-box .state-icon { font-size:2.5rem; margin-bottom:0.75rem; display:block; }
    .state-box p { font-size:0.88rem; margin:0; }

    ::-webkit-scrollbar { width:6px; }
    ::-webkit-scrollbar-track { background:transparent; }
    ::-webkit-scrollbar-thumb { background:rgba(255,255,255,0.1); border-radius:3px; }

    /* ─── Debug overlay toggle ─── */
    .debug-toggle { font-size: 0.7rem; color: var(--text-dim); cursor: pointer; user-select: none; }
    .debug-toggle:hover { color: var(--cyan); }
  </style>
</head>
<body>

<!-- ═══════════════ NAVBAR ═══════════════ -->
<?php include 'navbar.php'; ?>

<!-- ═══════════════ MAIN ═══════════════ -->
<div class="page-wrapper">
  <div class="container" style="max-width:860px;">

    <!-- Heading -->
    <div class="mb-4">
      <div class="section-eyebrow"><i class="bi bi-pencil-square me-1"></i>Form Penilaian</div>
      <h1 class="section-title" id="event-title">Memuat event…</h1>
    </div>

    <!-- Sync strip -->
    <div id="sync-strip">
      <div class="spinner-border text-warning" role="status"></div>
      <span id="sync-strip-text">Menyinkronkan data antrian…</span>
    </div>

    <!-- ╔══════════════════════════════════════╗
         ║  CARD 1 – AI SCANNER                ║
         ╚══════════════════════════════════════╝ -->
    <div class="glass-card mb-3">
      <div class="card-header-custom">
        <div class="card-icon" style="background:rgba(167,139,250,0.15);color:var(--purple);">
          <i class="bi bi-robot"></i>
        </div>
        <div style="flex:1">
          <div class="card-title-text">
            AI Scanner
            <span style="font-size:0.7rem;color:var(--purple);font-family:'DM Sans',sans-serif;font-weight:400;margin-left:6px;">
              Powered by Gemini Vision
            </span>
          </div>
          <div class="card-sub-text">Upload foto form penilaian — AI akan membaca nilai yang dicoret secara otomatis</div>
        </div>
      </div>

      <div class="p-3">
        <!-- Upload zone -->
        <div class="ai-upload-zone" id="ai-upload-zone">
          <input type="file" id="ai-upload-input" accept="image/jpeg,image/png,image/webp" />
          <i class="bi bi-file-earmark-image ai-upload-icon"></i>
          <div class="ai-upload-title">Seret gambar ke sini atau klik untuk memilih</div>
          <div class="ai-upload-hint">JPG / PNG / WEBP · Maks 4 MB · Foto jelas = akurasi lebih tinggi</div>
        </div>

        <!-- Preview thumbnail -->
        <div class="ai-preview-wrap" id="ai-preview-wrap">
          <img id="ai-preview-img" src="" alt="Preview" />
          <div class="ai-preview-filename">
            <i class="bi bi-image"></i>
            <span id="ai-preview-name">—</span>
            <span id="ai-preview-size" style="margin-left:auto;"></span>
          </div>
        </div>

        <!-- Animasi loading -->
        <div class="ai-loading" id="ai-loading">
          <div class="ai-spinner"></div>
          <div class="ai-loading-text">Gemini sedang membaca form…</div>
          <div class="ai-loading-subtext">Proses biasanya selesai dalam 5–15 detik</div>
        </div>

        <!-- Tombol aksi -->
        <div class="d-flex gap-2 mt-3 flex-wrap" id="ai-action-row">
          <button class="btn-cyan-custom" id="btn-ai-scan" disabled
            style="background:rgba(167,139,250,0.15);border-color:rgba(167,139,250,0.4);color:var(--purple);">
            <i class="bi bi-robot"></i>Scan dengan AI
          </button>
          <button class="btn-ghost danger" id="btn-ai-clear" style="margin-left:auto; display:none;">
            <i class="bi bi-x-circle"></i>Hapus Gambar
          </button>
        </div>

        <!-- Tabel hasil scan AI -->
        <div class="ai-results-wrap" id="ai-results-wrap">
          <div style="font-size:0.72rem;font-weight:600;letter-spacing:0.07em;text-transform:uppercase;
               color:var(--text-dim);padding:0.75rem 12px 6px;display:flex;justify-content:space-between;align-items:center;">
            <span>Hasil Deteksi AI</span>
            <span id="ai-result-stat" style="color:var(--purple);font-family:'Sora',sans-serif;"></span>
          </div>
          <div style="max-height:260px;overflow-y:auto;">
            <div class="ai-result-row header">
              <div>Gerakan</div><div>Nilai AI</div><div>Status</div>
            </div>
            <div id="ai-results-body"></div>
          </div>
          <!-- Tombol apply ke form -->
          <div class="d-flex justify-content-end p-2 pt-3">
            <button class="btn-primary-custom" id="btn-ai-apply"
              style="background:var(--purple);font-size:0.85rem;padding:0.5rem 1.3rem;">
              <i class="bi bi-check2-all"></i>Terapkan ke Form
            </button>
          </div>
        </div>
      </div>
    </div>

    <!-- ╔══════════════════════════════════════╗
         ║  CARD 2 – IDENTITAS PENILAIAN       ║
         ╚══════════════════════════════════════╝ -->
    <div class="glass-card mb-3">
      <div class="card-header-custom">
        <div class="card-icon"><i class="bi bi-people-fill"></i></div>
        <div><div class="card-title-text">Identitas Penilaian</div><div class="card-sub-text">Pilih regu yang akan dinilai dan juri penilai</div></div>
      </div>
      <div class="p-3">
        <div class="row g-3">
          <div class="col-sm-6">
            <label class="form-label-custom">Peserta / Regu</label>
            <select class="form-select-dark" id="select-peserta"><option value="">— Pilih Regu —</option></select>
          </div>
          <div class="col-sm-6">
            <label class="form-label-custom">Juri Penilai</label>
            <select class="form-select-dark" id="select-juri"><option value="">— Pilih Juri —</option></select>
          </div>
        </div>
      </div>
    </div>

    <!-- ╔══════════════════════════════════════╗
         ║  CARD 3 – FORM KRITERIA             ║
         ╚══════════════════════════════════════╝ -->
    <div class="glass-card mb-4">
      <div class="card-header-custom">
        <div class="card-icon"><i class="bi bi-list-check"></i></div>
        <div><div class="card-title-text">Penilaian Gerakan</div><div class="card-sub-text">Klik opsi atau ketik nilai secara langsung · Nilai dari OMR akan terisi otomatis</div></div>
      </div>
      <div class="p-3" id="area-kriteria">
        <div id="skeleton-area">
          <div class="skeleton skeleton-label"></div>
          <div class="skeleton skeleton-row"></div>
          <div class="skeleton skeleton-row"></div>
          <div class="skeleton skeleton-row"></div>
          <div class="skeleton skeleton-label mt-3"></div>
          <div class="skeleton skeleton-row"></div>
          <div class="skeleton skeleton-row"></div>
        </div>
      </div>
    </div>

    <!-- Action buttons -->
    <div class="d-flex justify-content-between align-items-center gap-3 flex-wrap">
      <div class="d-flex gap-2 flex-wrap">
        <button class="btn-ghost" id="btn-reset" type="button">
          <i class="bi bi-arrow-counterclockwise"></i>Reset Form
        </button>
        <a href="lihat_hasil.php" class="btn-ghost" style="text-decoration:none;">
          <i class="bi bi-display"></i>Lihat Dashboard
        </a>
      </div>
      <button class="btn-primary-custom" id="btn-simpan" type="button" disabled>
        <i class="bi bi-send-fill"></i>Simpan Nilai
      </button>
    </div>

  </div>
</div>

<!-- Toast container -->
<div id="toast-container"></div>


<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
/* ╔══════════════════════════════════════════════════════════════╗
   ║  KONFIGURASI UTAMA                                          ║
   ╚══════════════════════════════════════════════════════════════╝ */
const API_BASE       = 'http://localhost/paskibra v.2';
const ID_EVENT       = 1;
const LS_PENDING_KEY = 'pending_sync_data';

/* ╔══════════════════════════════════════════════════════════════╗
   ║  STATE APLIKASI                                             ║
   ╚══════════════════════════════════════════════════════════════╝ */
let masterData = { peserta: [], juri: [], kriteria: [] };
let aiScanFile  = null;   // File gambar yang dipilih
let aiResults   = [];     // Hasil scan terakhir dari Gemini

/* ╔══════════════════════════════════════════════════════════════╗
   ║  DOM REFS                                                   ║
   ╚══════════════════════════════════════════════════════════════╝ */
const selPeserta    = document.getElementById('select-peserta');
const selJuri       = document.getElementById('select-juri');
const areaKriteria  = document.getElementById('area-kriteria');
const btnSimpan     = document.getElementById('btn-simpan');
const btnReset      = document.getElementById('btn-reset');
const connBadge     = document.getElementById('conn-badge');
const connLabel     = document.getElementById('conn-label');
const queuePill     = document.getElementById('queue-pill');
const queueCount    = document.getElementById('queue-count');
const eventTitle    = document.getElementById('event-title');
const syncStrip     = document.getElementById('sync-strip');
const syncStripText = document.getElementById('sync-strip-text');
const toastCont     = document.getElementById('toast-container');

// AI Scanner elements
const aiUploadInput  = document.getElementById('ai-upload-input');
const aiUploadZone   = document.getElementById('ai-upload-zone');
const aiPreviewWrap  = document.getElementById('ai-preview-wrap');
const aiPreviewImg   = document.getElementById('ai-preview-img');
const aiPreviewName  = document.getElementById('ai-preview-name');
const aiPreviewSize  = document.getElementById('ai-preview-size');
const aiLoading      = document.getElementById('ai-loading');
const btnAiScan      = document.getElementById('btn-ai-scan');
const btnAiClear     = document.getElementById('btn-ai-clear');
const aiResultsWrap  = document.getElementById('ai-results-wrap');
const aiResultsBody  = document.getElementById('ai-results-body');
const aiResultStat   = document.getElementById('ai-result-stat');
const btnAiApply     = document.getElementById('btn-ai-apply');

/* ╔══════════════════════════════════════════════════════════════╗
   ║                                                              ║
   ║   M O D U L   A I   S C A N N E R                           ║
   ║                                                              ║
   ╚══════════════════════════════════════════════════════════════╝ */

/**
 * ai_setFile(file)
 * Simpan file ke state, tampilkan thumbnail dan nama file.
 */
function ai_setFile(file) {
  if (!file) return;
  if (!['image/jpeg','image/png','image/webp'].includes(file.type)) {
    showToast('error', 'File harus berupa gambar JPG, PNG, atau WEBP.'); return;
  }
  if (file.size > 4 * 1024 * 1024) {
    showToast('error', 'Ukuran gambar melebihi 4 MB. Kompres terlebih dahulu.'); return;
  }

  aiScanFile = file;

  // Tampilkan preview thumbnail
  const url = URL.createObjectURL(file);
  aiPreviewImg.src = url;
  aiPreviewName.textContent = file.name;
  aiPreviewSize.textContent = (file.size / 1024 / 1024).toFixed(2) + ' MB';
  aiPreviewWrap.classList.add('show');
  btnAiClear.style.display = '';
  btnAiScan.disabled       = false;

  // Sembunyikan hasil lama
  aiResultsWrap.classList.remove('show');
  aiResults = [];
}

/**
 * ai_clearFile()
 * Reset state scanner ke kondisi awal.
 */
function ai_clearFile() {
  aiScanFile = null;
  aiResults  = [];
  aiPreviewImg.src = '';
  aiPreviewWrap.classList.remove('show');
  aiResultsWrap.classList.remove('show');
  aiLoading.classList.remove('show');
  btnAiClear.style.display = 'none';
  btnAiScan.disabled       = true;
  aiUploadInput.value      = '';

  // Hapus badge dari form
  document.querySelectorAll('.omr-status-badge').forEach(b => b.remove());
  document.querySelectorAll('.kriteria-row').forEach(r =>
    r.classList.remove('omr-detected','omr-undetected'));
}

/**
 * ai_runScan()
 * Kirim gambar ke proses_scan_ai.php, lalu langsung auto-fill
 * form utama tanpa modal — tidak ada langkah "Terapkan" lagi.
 *
 * Alur setelah respons sukses:
 *   1. Loop json.data.hasil
 *   2. Cari input via id  → isi nilai
 *   3. Sync pill aktif
 *   4. Flash highlight hijau (stagger 60 ms per baris)
 *   5. Pasang badge "AI · <nilai>"
 *   6. updateSimpanBtn() di akhir
 */
async function ai_runScan() {
  if (!aiScanFile) return;

  const idPeserta = selPeserta.value;
  const idJuri    = selJuri.value;

  if (!idPeserta || !idJuri) {
    showToast('error', 'Pilih <strong>Peserta</strong> dan <strong>Juri</strong> terlebih dahulu sebelum scan.', 6000);
    return;
  }

  // UI: tampilkan loading, sembunyikan tabel lama
  btnAiScan.disabled  = true;
  btnAiScan.innerHTML = `<span class="spinner-border spinner-border-sm"></span> Memproses…`;
  aiLoading.classList.add('show');
  aiResultsWrap.classList.remove('show');

  try {
    const formData = new FormData();
    formData.append('gambar',     aiScanFile);
    formData.append('id_event',   ID_EVENT);
    formData.append('id_peserta', idPeserta);
    formData.append('id_juri',    idJuri);

    const res  = await fetch(`${API_BASE}/proses_scan_ai.php`, {
      method: 'POST',
      body:   formData,
    });
    const json = await res.json();

    if (!res.ok || json.status !== 'success') {
      throw new Error(json.message || `HTTP ${res.status}`);
    }

    // Simpan ke state (untuk Terapkan manual jika user ingin)
    aiResults = json.data.hasil;

    const jumlah  = json.data.jumlah_cocok;
    const total   = json.data.jumlah_total;
    const skipped = total - jumlah;

    // ── Auto-fill langsung tanpa modal ─────────────────────
    let filled = 0;

    json.data.hasil.forEach((item, idx) => {

      // 1. Cari input — coba id "nilai-{id}" (format rekap.html)
      //    fallback: name="nilai[{id}]" (format alternatif)
      let input = document.getElementById(`nilai-${item.id_kriteria}`);
      if (!input) {
        input = document.querySelector(`input[name="nilai[${item.id_kriteria}]"]`);
      }
      if (!input) return;   // kriteria tidak ada di form, skip

      // 2. Isi nilai
      input.value = item.nilai;

      // 3. Sync pill — hapus semua active, nyalakan yang cocok
      const row = input.closest('.kriteria-row');
      if (row) {
        row.querySelectorAll('.opsi-pill').forEach(p => p.classList.remove('active'));
        const targetPill = row.querySelector(`.opsi-pill[data-val="${item.nilai}"]`);
        if (targetPill) targetPill.classList.add('active');

        // 4. Flash highlight hijau dengan stagger 60 ms per baris
        row.classList.remove('omr-undetected');
        row.classList.add('omr-detected');

        input.classList.remove('ai-filled');
        setTimeout(() => {
          input.classList.add('ai-filled');
          setTimeout(() => input.classList.remove('ai-filled'), 1400);
        }, idx * 60);

        // 5. Pasang / update badge "AI · <nilai>"
        let badge = row.querySelector('.omr-status-badge');
        if (!badge) {
          badge = document.createElement('span');
          badge.className = 'omr-status-badge';
          const nameDiv = row.querySelector('.kriteria-name');
          if (nameDiv) nameDiv.insertAdjacentElement('afterend', badge);
        }
        badge.className  = 'omr-status-badge detected';
        badge.textContent = `AI · ${item.nilai}`;
      }

      filled++;
    });

    // Tandai baris yang tidak terdeteksi
    json.data.hasil.length > 0 && document.querySelectorAll('.kriteria-row').forEach(row => {
      const inp = row.querySelector('.nilai-input');
      if (inp && inp.value === '' && !row.classList.contains('omr-detected')) {
        row.classList.add('omr-undetected');
        let badge = row.querySelector('.omr-status-badge');
        if (!badge) {
          badge = document.createElement('span');
          badge.className = 'omr-status-badge undetected';
          const nameDiv = row.querySelector('.kriteria-name');
          if (nameDiv) nameDiv.insertAdjacentElement('afterend', badge);
        } else {
          badge.className  = 'omr-status-badge undetected';
          badge.textContent = 'Perlu manual';
        }
      }
    });

    // 6. Cek kelengkapan tombol Simpan
    updateSimpanBtn();

    // 7. Render tabel ringkasan di card AI (tetap tampil sebagai referensi)
    ai_renderResultsTable(json.data);

    // 8. Toast konfirmasi
    showToast(
      'scan',
      `<i class="bi bi-robot me-1"></i><strong>Auto-Fill Selesai!</strong> ` +
      `${filled} nilai langsung dimasukkan ke form.` +
      (skipped > 0
        ? ` · <strong>${skipped}</strong> baris perlu diisi manual.`
        : ' · Semua baris terisi!') +
      `<br><span style="opacity:.8;font-size:.8em">⚠️ Periksa nilai sebelum menekan <strong>Simpan Nilai</strong>.</span>`,
      0   // tidak auto-dismiss — penting untuk verifikasi
    );

    // Scroll ke form kriteria agar highlight terlihat
    areaKriteria.scrollIntoView({ behavior: 'smooth', block: 'start' });

  } catch (err) {
    showToast('error', `Scan AI gagal: ${escHtml(err.message)}`, 8000);
    console.error('[AI Scanner]', err);
  } finally {
    aiLoading.classList.remove('show');
    btnAiScan.disabled  = false;
    btnAiScan.innerHTML = `<i class="bi bi-robot"></i>Scan dengan AI`;
  }
}

/**
 * ai_renderResultsTable(data)
 * Render tabel hasil { hasil, jumlah_cocok, jumlah_total, tidak_dikenal }.
 */
function ai_renderResultsTable(data) {
  const { hasil, jumlah_cocok, jumlah_total } = data;

  // Buat lookup id_kriteria → hasil
  const lookup = {};
  hasil.forEach(h => { lookup[h.id_kriteria] = h; });

  // Render per-kriteria sesuai urutan form
  let rows = '';
  masterData.kriteria.forEach(kat => {
    (kat.kriteria || []).forEach(kr => {
      const h = lookup[kr.id_kriteria];
      if (h) {
        rows += `
          <div class="ai-result-row">
            <div style="color:var(--text)">${escHtml(h.nama)}</div>
            <div class="ai-val-ok">${h.nilai}</div>
            <div><span style="font-size:0.7rem;background:rgba(45,198,83,0.15);color:var(--green-ok);
              padding:2px 7px;border-radius:4px;border:1px solid rgba(45,198,83,0.25);">
              ✓ Terdeteksi</span></div>
          </div>`;
      } else {
        rows += `
          <div class="ai-result-row">
            <div style="color:var(--text-dim)">${escHtml(kr.nama_gerakan)}</div>
            <div class="ai-val-skip">—</div>
            <div><span style="font-size:0.7rem;background:rgba(245,166,35,0.1);color:var(--accent);
              padding:2px 7px;border-radius:4px;border:1px solid rgba(245,166,35,0.2);">
              Manual</span></div>
          </div>`;
      }
    });
  });

  aiResultsBody.innerHTML = rows;
  aiResultStat.textContent = `${jumlah_cocok} / ${jumlah_total} terdeteksi`;
  aiResultsWrap.classList.add('show');
}

/**
 * ai_applyToForm()
 * Isi input form dengan nilai dari aiResults, aktifkan pills,
 * dan berikan efek highlight hijau bergantian (stagger) agar
 * panitia tahu baris mana yang diisi oleh AI.
 */
function ai_applyToForm() {
  if (!aiResults.length) return;

  let filled = 0;

  aiResults.forEach((r, idx) => {
    const input = document.getElementById(`nilai-${r.id_kriteria}`);
    if (!input) return;

    // ── 1. Isi nilai ────────────────────────────────────────
    input.value = r.nilai;

    // ── 2. Aktifkan pill yang sesuai ────────────────────────
    // syncPills mencari pill dengan data-val === nilai lalu tambah class active
    syncPills(input);

    // ── 3. Efek highlight bergantian (stagger 60 ms per baris) ──
    // Hapus dulu class lama agar animasi bisa di-replay
    input.classList.remove('ai-filled');
    setTimeout(() => {
      input.classList.add('ai-filled');
      // Hapus class setelah animasi selesai agar tidak mengganggu edit manual
      setTimeout(() => input.classList.remove('ai-filled'), 1400);
    }, idx * 60);   // stagger: baris 0→0ms, baris 1→60ms, dst.

    // ── 4. Tandai baris & pasang badge ─────────────────────
    const row = input.closest('.kriteria-row');
    if (row) {
      row.classList.remove('omr-undetected');
      row.classList.add('omr-detected');
      let badge = row.querySelector('.omr-status-badge');
      if (!badge) {
        badge = document.createElement('span');
        badge.className = 'omr-status-badge';
        const nameDiv = row.querySelector('.kriteria-name');
        if (nameDiv) nameDiv.insertAdjacentElement('afterend', badge);
      }
      badge.className  = 'omr-status-badge detected';
      badge.textContent = `AI · ${r.nilai}`;
    }
    filled++;
  });

  updateSimpanBtn();
  showToast('success',
    `✅ <strong>${filled}</strong> nilai dari AI berhasil diterapkan ke form.` +
    (filled < aiResults.length + 1 ? '' : ' Periksa kembali sebelum menyimpan.'),
    5000
  );
  areaKriteria.scrollIntoView({ behavior: 'smooth', block: 'start' });
}

/* ─── AI Scanner Event Listeners ─── */

aiUploadInput.addEventListener('change', e => {
  if (e.target.files[0]) ai_setFile(e.target.files[0]);
});

aiUploadZone.addEventListener('dragover',  e => { e.preventDefault(); aiUploadZone.classList.add('drag-over'); });
aiUploadZone.addEventListener('dragleave', ()  => aiUploadZone.classList.remove('drag-over'));
aiUploadZone.addEventListener('drop', e => {
  e.preventDefault();
  aiUploadZone.classList.remove('drag-over');
  if (e.dataTransfer.files[0]) ai_setFile(e.dataTransfer.files[0]);
});

btnAiScan.addEventListener('click',  ai_runScan);
btnAiClear.addEventListener('click', ai_clearFile);
btnAiApply.addEventListener('click', ai_applyToForm);

/* ╔══════════════════════════════════════════════════════════════╗

/* ╔══════════════════════════════════════════════════════════════╗
   ║  TOAST                                                      ║
   ╚══════════════════════════════════════════════════════════════╝ */
function showToast(type, message, duration = 5000) {
  const icons = {
    success:'bi-check-circle-fill', offline:'bi-wifi-off',
    error:'bi-exclamation-triangle-fill', info:'bi-info-circle-fill',
    scan:'bi-camera-fill'
  };
  const el = document.createElement('div');
  el.className = `toast-item toast-${type}`;
  el.innerHTML = `
    <i class="bi ${icons[type] || icons.info} toast-icon"></i>
    <span>${message}</span>
    <button class="toast-close" onclick="dismissToast(this.parentElement)"><i class="bi bi-x"></i></button>
  `;
  toastCont.appendChild(el);
  if (duration > 0) setTimeout(() => dismissToast(el), duration);
}
function dismissToast(el) {
  if (!el?.parentElement) return;
  el.classList.add('toast-out');
  setTimeout(() => el.remove(), 300);
}

/* ╔══════════════════════════════════════════════════════════════╗
   ║  STATUS KONEKSI                                             ║
   ╚══════════════════════════════════════════════════════════════╝ */
function updateConnUI() {
  // Cari elemennya dulu setiap kali fungsi dipanggil
  const connBadge = document.getElementById('connBadge');
  const connLabel = document.getElementById('connLabel');

  // Jika elemennya tidak ditemukan di halaman, hentikan fungsi agar tidak error
  if (!connBadge || !connLabel) return; 

  const online = navigator.onLine;
  connBadge.className = `conn-badge ${online ? 'online' : 'offline'}`;
  // Jika Anda pakai Bootstrap, bisa pakai class tambahan untuk warna:
  // connBadge.className = `badge ${online ? 'bg-success' : 'bg-danger'}`; 
  connLabel.textContent = online ? 'Online' : 'Offline';
}

window.addEventListener('online',  () => { updateConnUI(); syncPendingData(); });
window.addEventListener('offline', () => updateConnUI());
updateConnUI();
/* ╔══════════════════════════════════════════════════════════════╗
   ║  PENDING QUEUE (localStorage)                               ║
   ╚══════════════════════════════════════════════════════════════╝ */
function updateQueueUI() {
  // 1. Cari elemennya secara langsung
  const queuePill = document.getElementById('queuePill');
  const queueCount = document.getElementById('queueCount');

  // 2. Jika elemen tidak ada (misal di halaman lain), batalkan fungsi agar tidak error
  if (!queuePill || !queueCount) return;

  // 3. Jalankan logika aslinya
  const q = getPendingQueue();
  if (q.length > 0) { 
      queuePill.classList.remove('d-none'); // Tampilkan
      queueCount.textContent = q.length; 
  } else { 
      queuePill.classList.add('d-none'); // Sembunyikan
  }
}

/* ╔══════════════════════════════════════════════════════════════╗
   ║  AUTO-SYNC                                                  ║
   ╚══════════════════════════════════════════════════════════════╝ */
async function syncPendingData() {
  if (!navigator.onLine) return;
  const queue = getPendingQueue();
  if (!queue.length) return;

  syncStrip.classList.add('show');
  syncStripText.textContent = `Menyinkronkan ${queue.length} data antrian…`;
  const remaining = [];

  for (let i = 0; i < queue.length; i++) {
    syncStripText.textContent = `Mengirim data ${i + 1} dari ${queue.length}…`;
    try {
      const res  = await fetch(`${API_BASE}/save_penilaian.php`, { method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify(queue[i]) });
      const json = await res.json();
      if (!res.ok || json.status !== 'success') remaining.push(queue[i]);
    } catch { remaining.push(queue[i]); }
  }

  savePendingQueue(remaining);
  syncStrip.classList.remove('show');
  const berhasil = queue.length - remaining.length;
  if (berhasil > 0) showToast('success', `✅ ${berhasil} data antrian berhasil dikirim.`);
  if (remaining.length > 0) showToast('error', `${remaining.length} data gagal terkirim.`);
}
window.addEventListener('load', () => { if (navigator.onLine) syncPendingData(); });

/* ╔══════════════════════════════════════════════════════════════╗
   ║  LOAD MASTER DATA                                           ║
   ╚══════════════════════════════════════════════════════════════╝ */
async function loadMasterData() {
  try {
    const res  = await fetch(`${API_BASE}/get_master_data.php?id_event=${ID_EVENT}`);
    const json = await res.json();
    if (!res.ok || json.status !== 'success') throw new Error(json.message || 'Gagal memuat data.');

    const { event, daftar_peserta, daftar_juri, struktur_kriteria } = json.data;
    masterData = { peserta: daftar_peserta, juri: daftar_juri, kriteria: struktur_kriteria };
    eventTitle.textContent = event.nama_event;

    renderDropdownPeserta(daftar_peserta);
    renderDropdownJuri(daftar_juri);
    renderKriteria(struktur_kriteria);

  } catch (err) {
    document.getElementById('skeleton-area')?.remove();
    areaKriteria.innerHTML = `
      <div class="state-box">
        <span class="state-icon">⚠️</span>
        <p><strong>Gagal memuat data master.</strong><br>${err.message}</p>
        <button class="btn-primary-custom mt-3" onclick="location.reload()"><i class="bi bi-arrow-clockwise"></i>Coba Lagi</button>
      </div>`;
    eventTitle.textContent = 'Gagal memuat event';
    showToast('error', `Gagal terhubung ke API: ${err.message}`, 8000);
  }
}

/* ╔══════════════════════════════════════════════════════════════╗
   ║  RENDER                                                     ║
   ╚══════════════════════════════════════════════════════════════╝ */
function renderDropdownPeserta(list) {
  selPeserta.innerHTML = '<option value="">— Pilih Regu —</option>';
  list.forEach(p => {
    const opt = document.createElement('option');
    opt.value = p.id_peserta;
    opt.textContent = `${p.no_urut}. ${p.nama_regu}${p.asal_sekolah ? ' — ' + p.asal_sekolah : ''}`;
    selPeserta.appendChild(opt);
  });
}

function renderDropdownJuri(list) {
  selJuri.innerHTML = '<option value="">— Pilih Juri —</option>';
  list.forEach(j => {
    const opt = document.createElement('option');
    opt.value = j.id_juri;
    opt.textContent = `${j.kode_juri ? '[' + j.kode_juri + '] ' : ''}${j.nama_juri}`;
    selJuri.appendChild(opt);
  });
}

function renderKriteria(strukturKriteria) {
  document.getElementById('skeleton-area')?.remove();
  if (!strukturKriteria?.length) {
    areaKriteria.innerHTML = `<div class="state-box"><span class="state-icon">📋</span><p>Tidak ada kriteria terdaftar.</p></div>`;
    return;
  }

  let html = '';
  strukturKriteria.forEach(kategori => {
    html += `<div class="kategori-group">
      <div class="kategori-label"><span class="kat-badge">${escHtml(kategori.kode_kategori)}</span>${escHtml(kategori.nama_kategori)}</div>`;

    kategori.kriteria.forEach(kr => {
      const opsi     = kr.opsi_nilai?.nilai || [];
      const labels   = kr.opsi_nilai?.label || [];
      const nilaiMin = kr.nilai_min ?? (opsi[0] ?? 0);
      const nilaiMax = kr.nilai_max ?? (opsi[opsi.length - 1] ?? 100);

      let pillsHtml = '';
      if (opsi.length) {
        pillsHtml = '<div class="opsi-pills">';
        opsi.forEach((val, idx) => {
          const lbl = labels[idx] ? `${labels[idx]}<br><small>${val}</small>` : val;
          pillsHtml += `<button type="button" class="opsi-pill" data-val="${val}" data-kriteria="${kr.id_kriteria}" onclick="pilihOpsi(this)">${lbl}</button>`;
        });
        pillsHtml += '</div>';
      }

      html += `
        <div class="kriteria-row" data-id-kriteria="${kr.id_kriteria}">
          <div>
            <div class="kriteria-name">${escHtml(kr.nama_gerakan)}</div>
            ${kr.sub_kriteria ? `<div class="kriteria-sub"><i class="bi bi-arrow-return-right me-1"></i>${escHtml(kr.sub_kriteria)}</div>` : ''}
            ${pillsHtml}
          </div>
          <div class="nilai-input-wrap">
            <input type="number" class="form-control-dark nilai-input"
              id="nilai-${kr.id_kriteria}" data-id-kriteria="${kr.id_kriteria}"
              placeholder="0" min="${nilaiMin}" max="${nilaiMax}" step="1" oninput="syncPills(this)" />
            <div class="nilai-range-hint">${nilaiMin} – ${nilaiMax}</div>
          </div>
        </div>`;
    });

    html += `</div>`;
  });

  areaKriteria.innerHTML = html;
  updateSimpanBtn();
}

/* ╔══════════════════════════════════════════════════════════════╗
   ║  INTERAKSI PILLS ↔ INPUT                                   ║
   ╚══════════════════════════════════════════════════════════════╝ */
function pilihOpsi(btn) {
  const idKriteria = btn.dataset.kriteria;
  btn.closest('.kriteria-row').querySelectorAll('.opsi-pill').forEach(p => p.classList.remove('active'));
  btn.classList.add('active');
  const input = document.getElementById(`nilai-${idKriteria}`);
  if (input) input.value = parseFloat(btn.dataset.val);
}

function syncPills(input) {
  const val = parseFloat(input.value);
  input.closest('.kriteria-row').querySelectorAll('.opsi-pill').forEach(p => {
    p.classList.toggle('active', parseFloat(p.dataset.val) === val);
  });
}

/* ╔══════════════════════════════════════════════════════════════╗
   ║  FORM ACTIONS                                               ║
   ╚══════════════════════════════════════════════════════════════╝ */
selPeserta.addEventListener('change', updateSimpanBtn);
selJuri.addEventListener('change',    updateSimpanBtn);
function updateSimpanBtn() { btnSimpan.disabled = !(selPeserta.value && selJuri.value); }

function collectFormData() {
  const data_nilai = [];
  document.querySelectorAll('.nilai-input').forEach(input => {
    const val = input.value.trim();
    if (val !== '' && !isNaN(parseFloat(val))) {
      data_nilai.push({ id_kriteria: parseInt(input.dataset.idKriteria), nilai_diperoleh: parseFloat(val) });
    }
  });
  return { id_peserta: parseInt(selPeserta.value), id_juri: parseInt(selJuri.value), metode_input: 'manual', data_nilai };
}

btnSimpan.addEventListener('click', async () => {
  if (!selPeserta.value || !selJuri.value) { showToast('error', 'Pilih peserta dan juri terlebih dahulu.'); return; }
  const payload = collectFormData();
  if (!payload.data_nilai.length) { showToast('error', 'Belum ada nilai yang dimasukkan.'); return; }

  if (!navigator.onLine) {
    const q = getPendingQueue();
    q.push({ ...payload, _queued_at: new Date().toISOString() });
    savePendingQueue(q);
    showToast('offline', `<strong>Koneksi terputus.</strong><br>Data disimpan di perangkat dan akan dikirim otomatis saat online.`, 8000);
    resetForm();
    return;
  }

  btnSimpan.disabled  = true;
  btnSimpan.innerHTML = `<span class="spinner-border spinner-border-sm"></span> Menyimpan…`;

  try {
    const res  = await fetch(`${API_BASE}/save_penilaian.php`, { method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify(payload) });
    const json = await res.json();
    if (res.ok && json.status === 'success') {
      showToast('success',
        `✅ Nilai untuk <strong>${getNamaRegu(payload.id_peserta)}</strong> berhasil disimpan ` +
        `(${payload.data_nilai.length} kriteria). ` +
        `<span style="opacity:.8;font-size:.85em">Menuju dashboard dalam 1,5 detik…</span>`
      );
      resetForm();
      // Redirect ke dashboard setelah 1,5 detik
      setTimeout(() => { window.location.href = 'lihat_hasil.php'; }, 1500);
    } else { throw new Error(json.message || `HTTP ${res.status}`); }
  } catch (err) {
    if (!navigator.onLine || err instanceof TypeError) {
      const q = getPendingQueue(); q.push({ ...payload, _queued_at: new Date().toISOString() }); savePendingQueue(q);
      showToast('offline', `<strong>Gagal mengirim.</strong> Data disimpan ke antrian offline.`, 7000);
    } else { showToast('error', `Gagal menyimpan: ${err.message}`, 8000); }
  } finally {
    btnSimpan.disabled  = !(selPeserta.value && selJuri.value);
    btnSimpan.innerHTML = `<i class="bi bi-send-fill"></i>Simpan Nilai`;
  }
});

btnReset.addEventListener('click', () => { if (!confirm('Reset semua nilai?')) return; resetForm(true); });

function resetForm(resetDropdown = false) {
  if (resetDropdown) { selPeserta.value = ''; selJuri.value = ''; }
  document.querySelectorAll('.nilai-input').forEach(i => i.value = '');
  document.querySelectorAll('.opsi-pill').forEach(p => p.classList.remove('active'));
  document.querySelectorAll('.kriteria-row').forEach(r => { r.classList.remove('omr-detected','omr-undetected'); });
  document.querySelectorAll('.omr-status-badge').forEach(b => b.remove());
  updateSimpanBtn();
}

/* ─── Helpers ─── */
function escHtml(str) {
  return str ? str.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;') : '';
}
function getNamaRegu(id) {
  const p = masterData.peserta.find(x => x.id_peserta === id);
  return p ? p.nama_regu : `ID ${id}`;
}

/* ─── Init ─── */
loadMasterData();
</script>
</body>
</html>
