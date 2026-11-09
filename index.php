<?php
declare(strict_types=1);
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(16));
}
$csrf = $_SESSION['csrf'];
$isEmbed = isset($_GET['mode']) && $_GET['mode'] === 'embed';
$callbackUrl = isset($_GET['callback']) ? htmlspecialchars((string)$_GET['callback'], ENT_QUOTES, 'UTF-8') : '';
?>
<!doctype html>
<html lang="es" data-theme="auto">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Cédula API · Lector y SDK Universal de Documentos Colombianos</title>
<style>
  :root {
    --bg: #f3f6fa;
    --bg-accent: radial-gradient(1200px 500px at 15% -10%, rgba(23, 73, 122, 0.10), transparent),
                 radial-gradient(900px 400px at 90% 0%, rgba(245, 189, 31, 0.08), transparent);
    --surface: #ffffff;
    --surface-2: #f8fafc;
    --surface-hover: #f1f5f9;
    --ink: #0f172a;
    --ink-soft: #334155;
    --muted: #64748b;
    --line: #e2e8f0;
    --line-strong: #cbd5e1;
    --accent: #0f4c81;
    --accent-hover: #0b3961;
    --accent-soft: rgba(15, 76, 129, 0.10);
    --accent-ink: #ffffff;
    --gold: #eab308;
    --blue: #1d4ed8;
    --red: #dc2626;
    --ok: #15803d;
    --ok-bg: #dcfce7;
    --warn: #b45309;
    --warn-bg: #fef3c7;
    --err: #b91c1c;
    --err-bg: #fee2e2;
    --shadow-sm: 0 1px 3px rgba(15, 23, 42, 0.06);
    --shadow-md: 0 10px 25px -5px rgba(15, 23, 42, 0.08), 0 4px 10px -3px rgba(15, 23, 42, 0.04);
    --radius: 14px;
    --radius-sm: 8px;
  }

  html[data-theme="dark"] {
    --bg: #0b111e;
    --bg-accent: radial-gradient(1200px 500px at 15% -10%, rgba(56, 189, 248, 0.10), transparent),
                 radial-gradient(900px 400px at 90% 0%, rgba(250, 204, 21, 0.06), transparent);
    --surface: #131c2e;
    --surface-2: #19243a;
    --surface-hover: #1e2d47;
    --ink: #f1f5f9;
    --ink-soft: #cbd5e1;
    --muted: #94a3b8;
    --line: #23324d;
    --line-strong: #334768;
    --accent: #38bdf8;
    --accent-hover: #7dd3fc;
    --accent-soft: rgba(56, 189, 248, 0.14);
    --accent-ink: #081221;
    --ok: #4ade80;
    --ok-bg: rgba(22, 163, 74, 0.20);
    --warn: #fbbf24;
    --warn-bg: rgba(217, 119, 6, 0.20);
    --err: #f87171;
    --err-bg: rgba(220, 38, 38, 0.20);
    --shadow-sm: 0 2px 6px rgba(0, 0, 0, 0.35);
    --shadow-md: 0 12px 30px -6px rgba(0, 0, 0, 0.5);
  }

  @media (prefers-color-scheme: dark) {
    html[data-theme="auto"] {
      --bg: #0b111e;
      --bg-accent: radial-gradient(1200px 500px at 15% -10%, rgba(56, 189, 248, 0.10), transparent),
                   radial-gradient(900px 400px at 90% 0%, rgba(250, 204, 21, 0.06), transparent);
      --surface: #131c2e;
      --surface-2: #19243a;
      --surface-hover: #1e2d47;
      --ink: #f1f5f9;
      --ink-soft: #cbd5e1;
      --muted: #94a3b8;
      --line: #23324d;
      --line-strong: #334768;
      --accent: #38bdf8;
      --accent-hover: #7dd3fc;
      --accent-soft: rgba(56, 189, 248, 0.14);
      --accent-ink: #081221;
      --ok: #4ade80;
      --ok-bg: rgba(22, 163, 74, 0.20);
      --warn: #fbbf24;
      --warn-bg: rgba(217, 119, 6, 0.20);
      --err: #f87171;
      --err-bg: rgba(220, 38, 38, 0.20);
      --shadow-sm: 0 2px 6px rgba(0, 0, 0, 0.35);
      --shadow-md: 0 12px 30px -6px rgba(0, 0, 0, 0.5);
    }
  }

  * { box-sizing: border-box; }
  body {
    margin: 0;
    background-color: var(--bg);
    background-image: var(--bg-accent);
    background-attachment: fixed;
    color: var(--ink);
    font: 15px/1.55 system-ui, -apple-system, "Segoe UI", Roboto, Inter, sans-serif;
    min-height: 100vh;
  }

  .tricolor-bar {
    height: 4px;
    background: linear-gradient(90deg, #eab308 0%, #eab308 50%, #1d4ed8 50%, #1d4ed8 75%, #dc2626 75%, #dc2626 100%);
  }

  .container {
    max-width: 1060px;
    margin: 0 auto;
    padding: 24px 20px 64px;
  }
  body.embed-mode .container {
    max-width: 680px;
    padding: 14px;
  }

  /* Header */
  header.app-header {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    justify-content: space-between;
    gap: 16px;
    margin-bottom: 24px;
    padding-bottom: 18px;
    border-bottom: 1px solid var(--line);
  }
  .brand {
    display: flex;
    align-items: center;
    gap: 14px;
  }
  .brand-icon {
    width: 44px;
    height: 44px;
    border-radius: 12px;
    background: var(--accent);
    color: var(--accent-ink);
    display: grid;
    place-items: center;
    box-shadow: var(--shadow-sm);
    flex-shrink: 0;
  }
  .brand-icon svg {
    width: 24px;
    height: 24px;
  }
  .brand h1 {
    font-size: 1.35rem;
    font-weight: 750;
    margin: 0;
    letter-spacing: -0.02em;
    display: flex;
    align-items: center;
    gap: 8px;
    flex-wrap: wrap;
  }

  .header-controls {
    display: flex;
    align-items: center;
    gap: 10px;
  }

  /* Main Navigation Tabs */
  .nav-tabs {
    display: flex;
    gap: 6px;
    background: var(--surface);
    padding: 5px;
    border-radius: 11px;
    border: 1px solid var(--line);
    box-shadow: var(--shadow-sm);
  }
  .nav-tab {
    border: none;
    background: transparent;
    color: var(--muted);
    font: inherit;
    font-weight: 600;
    font-size: 0.88rem;
    padding: 8px 14px;
    border-radius: 8px;
    cursor: pointer;
    transition: all 0.15s ease;
    display: inline-flex;
    align-items: center;
    gap: 6px;
  }
  .nav-tab:hover {
    color: var(--ink);
    background: var(--surface-hover);
  }
  .nav-tab.active {
    background: var(--accent);
    color: var(--accent-ink);
  }

  /* Panels & Grid */
  .grid-2 {
    display: grid;
    grid-template-columns: 1fr 1.1fr;
    gap: 20px;
    align-items: start;
  }
  @media (max-width: 860px) {
    .grid-2 { grid-template-columns: 1fr; }
  }
  body.embed-mode .grid-2 {
    grid-template-columns: 1fr;
  }

  .panel {
    background: var(--surface);
    border: 1px solid var(--line);
    border-radius: var(--radius);
    padding: 22px;
    box-shadow: var(--shadow-sm);
    margin-bottom: 20px;
  }
  .panel-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    margin-bottom: 16px;
  }
  .panel-title {
    margin: 0;
    font-size: 1.08rem;
    font-weight: 700;
  }

  /* Source Mode Switcher inside Scanner */
  .mode-pills {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 8px;
    margin-bottom: 16px;
  }
  .mode-pill {
    padding: 10px 10px;
    border-radius: var(--radius-sm);
    border: 1px solid var(--line);
    background: var(--surface-2);
    color: var(--ink-soft);
    font: inherit;
    font-size: 0.85rem;
    font-weight: 600;
    cursor: pointer;
    text-align: center;
    transition: all 0.15s ease;
  }
  .mode-pill:hover {
    border-color: var(--accent);
  }
  .mode-pill.active {
    background: var(--accent-soft);
    border-color: var(--accent);
    color: var(--accent);
  }

  /* Dropzone */
  .dropzone {
    border: 2px dashed var(--line-strong);
    border-radius: 12px;
    padding: 28px 20px;
    text-align: center;
    background: var(--surface-2);
    transition: all 0.18s ease;
    cursor: pointer;
    position: relative;
  }
  .dropzone:hover, .dropzone.dragover {
    border-color: var(--accent);
    background: var(--accent-soft);
  }
  .dropzone input[type="file"] {
    position: absolute;
    inset: 0;
    opacity: 0;
    cursor: pointer;
    width: 100%;
    height: 100%;
  }
  .dropzone-icon {
    font-size: 2rem;
    margin-bottom: 8px;
    display: block;
  }
  .dropzone-title {
    font-weight: 700;
    font-size: 0.98rem;
    margin-bottom: 4px;
    color: var(--ink);
  }
  .dropzone-sub {
    font-size: 0.82rem;
    color: var(--muted);
    margin: 0;
  }

  /* Buttons */
  .actions {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    align-items: center;
  }
  button.btn, a.btn, label.btn {
    font: inherit;
    font-size: 0.88rem;
    font-weight: 600;
    cursor: pointer;
    padding: 9px 15px;
    border-radius: var(--radius-sm);
    border: 1px solid var(--accent);
    background: var(--accent);
    color: var(--accent-ink);
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
    transition: all 0.15s ease;
  }
  button.btn:hover:not(:disabled), a.btn:hover {
    background: var(--accent-hover);
    border-color: var(--accent-hover);
  }
  button.btn.secondary, a.btn.secondary, label.btn.secondary {
    background: transparent;
    color: var(--ink);
    border-color: var(--line-strong);
  }
  button.btn.secondary:hover:not(:disabled), a.btn.secondary:hover {
    background: var(--surface-hover);
    border-color: var(--accent);
  }
  button.btn.sm, a.btn.sm {
    padding: 6px 11px;
    font-size: 0.8rem;
  }
  button.btn.danger {
    background: transparent;
    color: var(--err);
    border-color: var(--err);
  }
  button.btn.danger:hover:not(:disabled) {
    background: var(--err-bg);
  }
  button:disabled {
    opacity: 0.45;
    cursor: not-allowed;
  }

  /* Camera & Preview */
  .viewport-wrap {
    position: relative;
    margin-top: 14px;
    border-radius: 10px;
    overflow: hidden;
    background: #000;
    display: none;
  }
  .viewport-wrap.active {
    display: block;
  }
  video, img.preview {
    width: 100%;
    max-height: 320px;
    object-fit: contain;
    display: block;
  }
  .scan-guide {
    position: absolute;
    inset: 16% 10%;
    border: 2px solid rgba(56, 189, 248, 0.85);
    border-radius: 10px;
    box-shadow: 0 0 0 9999px rgba(0, 0, 0, 0.38);
    pointer-events: none;
    display: flex;
    align-items: flex-end;
    justify-content: center;
    padding-bottom: 8px;
  }
  .scan-guide span {
    background: rgba(15, 23, 42, 0.8);
    color: #fff;
    font-size: 0.75rem;
    padding: 3px 10px;
    border-radius: 999px;
  }

  /* Status Alert */
  .status-box {
    margin-top: 14px;
    padding: 11px 14px;
    border-radius: var(--radius-sm);
    font-size: 0.88rem;
    font-weight: 500;
    background: var(--surface-2);
    border: 1px solid var(--line);
    color: var(--muted);
    display: flex;
    align-items: center;
    gap: 8px;
  }
  .status-box.ok {
    background: var(--ok-bg);
    color: var(--ok);
    border-color: transparent;
  }
  .status-box.err {
    background: var(--err-bg);
    color: var(--err);
    border-color: transparent;
  }
  .status-box.info {
    background: var(--accent-soft);
    color: var(--accent);
    border-color: transparent;
  }

  /* Colombian ID Visual Card */
  .id-card {
    background: linear-gradient(135deg, var(--surface-2) 0%, var(--surface) 100%);
    border: 1px solid var(--line-strong);
    border-radius: 14px;
    padding: 18px;
    position: relative;
    overflow: hidden;
    box-shadow: var(--shadow-md);
    margin-bottom: 16px;
  }
  .id-card::before {
    content: "";
    position: absolute;
    top: 0; left: 0; right: 0;
    height: 5px;
    background: linear-gradient(90deg, #eab308 0%, #eab308 50%, #1d4ed8 50%, #1d4ed8 75%, #dc2626 75%, #dc2626 100%);
  }
  .id-card-top {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 10px;
    margin-bottom: 14px;
    padding-bottom: 12px;
    border-bottom: 1px dashed var(--line-strong);
  }
  .id-card-header-left {
    display: flex;
    align-items: center;
    gap: 12px;
  }
  .id-avatar {
    width: 48px;
    height: 48px;
    border-radius: 12px;
    background: var(--accent);
    color: var(--accent-ink);
    display: grid;
    place-items: center;
    font-weight: 800;
    font-size: 1.15rem;
    flex-shrink: 0;
  }
  .id-doc-title {
    font-size: 0.72rem;
    text-transform: uppercase;
    letter-spacing: 0.06em;
    color: var(--muted);
    font-weight: 700;
  }
  .id-doc-number {
    font-size: 1.3rem;
    font-weight: 800;
    color: var(--ink);
    letter-spacing: 0.02em;
  }
  .badges-wrap {
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
  }
  .pill-badge {
    font-size: 0.72rem;
    font-weight: 700;
    padding: 3px 9px;
    border-radius: 999px;
    background: var(--accent-soft);
    color: var(--accent);
    text-transform: uppercase;
  }
  .pill-badge.ok {
    background: var(--ok-bg);
    color: var(--ok);
  }
  .pill-badge.warn {
    background: var(--warn-bg);
    color: var(--warn);
  }

  .id-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 10px 14px;
  }
  @media (max-width: 500px) {
    .id-grid { grid-template-columns: 1fr; }
  }
  .field-box {
    background: var(--surface);
    border: 1px solid var(--line);
    border-radius: 8px;
    padding: 8px 11px;
    cursor: pointer;
    transition: border-color 0.15s ease;
    position: relative;
  }
  .field-box:hover {
    border-color: var(--accent);
  }
  .field-box:hover::after {
    content: "Copiar";
    position: absolute;
    top: 6px;
    right: 8px;
    font-size: 0.68rem;
    color: var(--accent);
    font-weight: 600;
  }
  .field-label {
    font-size: 0.72rem;
    text-transform: uppercase;
    letter-spacing: 0.04em;
    color: var(--muted);
    margin-bottom: 2px;
  }
  .field-val {
    font-weight: 700;
    font-size: 0.94rem;
    color: var(--ink);
    word-break: break-word;
  }

  /* Inputs & Textareas */
  input.text-input, select.text-input, textarea.text-input {
    width: 100%;
    padding: 9px 12px;
    border-radius: var(--radius-sm);
    border: 1px solid var(--line-strong);
    background: var(--surface-2);
    color: var(--ink);
    font: inherit;
    font-size: 0.9rem;
  }
  textarea.text-input {
    font-family: ui-monospace, SFMono-Regular, Consolas, monospace;
    font-size: 0.82rem;
    min-height: 92px;
    resize: vertical;
  }
  input.text-input:focus, select.text-input:focus, textarea.text-input:focus {
    outline: 2px solid var(--accent);
    border-color: transparent;
  }

  pre.code-block {
    margin: 0;
    padding: 14px;
    overflow-x: auto;
    background: var(--surface-2);
    border: 1px solid var(--line);
    border-radius: var(--radius-sm);
    font-family: ui-monospace, SFMono-Regular, Consolas, monospace;
    font-size: 0.82rem;
    line-height: 1.45;
    color: var(--ink);
  }

  details {
    margin-top: 12px;
    border-top: 1px solid var(--line);
    padding-top: 10px;
  }
  summary {
    cursor: pointer;
    color: var(--muted);
    font-weight: 600;
    font-size: 0.85rem;
    user-select: none;
  }

  /* Table for Saved History */
  .table-wrap {
    overflow-x: auto;
    border: 1px solid var(--line);
    border-radius: 10px;
  }
  table.data-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 0.87rem;
  }
  table.data-table th, table.data-table td {
    padding: 10px 12px;
    text-align: left;
    border-bottom: 1px solid var(--line);
  }
  table.data-table th {
    background: var(--surface-2);
    color: var(--muted);
    font-weight: 700;
    font-size: 0.76rem;
    text-transform: uppercase;
    letter-spacing: 0.04em;
  }
  table.data-table tr:last-child td {
    border-bottom: none;
  }
  table.data-table tr:hover td {
    background: var(--surface-hover);
  }

  /* Form Demo Grid */
  .form-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(190px, 1fr));
    gap: 12px;
  }
  .form-group label {
    display: block;
    font-size: 0.78rem;
    font-weight: 600;
    color: var(--muted);
    margin-bottom: 4px;
  }

  .demo-bar {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    justify-content: space-between;
    gap: 8px;
    margin-top: 14px;
    padding-top: 12px;
    border-top: 1px solid var(--line);
    font-size: 0.82rem;
    color: var(--muted);
  }

  [hidden] { display: none !important; }
</style>
</head>
<body class="<?= $isEmbed ? 'embed-mode' : '' ?>">
<div class="tricolor-bar"></div>

<div class="container">
  <?php if (!$isEmbed): ?>
  <header class="app-header">
    <div class="brand">
      <div class="brand-icon">
        <svg width="24" height="24" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="16" rx="3"/><circle cx="9" cy="10" r="2"/><path stroke-linecap="round" stroke-linejoin="round" d="M15 8h2m-2 4h2m-8 4h8"/></svg>
      </div>
      <div>
        <h1>Cédula Colombiana API &amp; SDK</h1>
      </div>
    </div>

    <div class="header-controls">
      <nav class="nav-tabs" role="tablist" aria-label="Secciones">
        <button type="button" class="nav-tab active" data-tab="scanner">
          <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M3 7V5a2 2 0 012-2h2m10 0h2a2 2 0 012 2v2m0 10v2a2 2 0 01-2 2h-2M7 21H5a2 2 0 01-2-2v-2m1 0h16M8 8h8v4H8z"/></svg>
          Escáner
        </button>
        <button type="button" class="nav-tab" data-tab="history">
          <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M4 7v10c0 2 1.5 3 3.5 3h9c2 0 3.5-1 3.5-3V7c0-2-1.5-3-3.5-3h-9C5.5 4 4 5 4 7z"/><path stroke-linecap="round" stroke-linejoin="round" d="M9 8h6m-6 4h6m-6 4h4"/></svg>
          Registros (<span id="savedCountBadge">0</span>)
        </button>
        <button type="button" class="nav-tab" data-tab="integration">
          <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M10 20l4-16m4 4l4 4-4 4M6 16l-4-4 4-4"/></svg>
          Integrar en tu Proyecto
        </button>
      </nav>
      <button type="button" id="btnTheme" class="btn secondary sm" title="Cambiar tema claro/oscuro">
        <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M20.354 15.354A9 9 0 018.646 3.646 9.003 9.003 0 0012 21a9.003 9.003 0 008.354-5.646z"/></svg>
        Tema
      </button>
    </div>
  </header>
  <?php endif; ?>

  <!-- ==================== TAB 1: ESCÁNER ==================== -->
  <div id="tab-scanner" class="tab-view">
    <div class="grid-2">
      <!-- Columna Izquierda: Entrada -->
      <section class="panel">
        <div class="panel-header">
          <h2 class="panel-title">1. Capturar Documento</h2>
          <span style="font-size:0.78rem;color:var(--muted)">Cédula Amarilla · Digital · TI</span>
        </div>

        <div class="mode-pills" role="tablist">
          <button type="button" class="mode-pill active" data-input-mode="file">
            <svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" style="vertical-align:-2px;margin-right:4px"><path stroke-linecap="round" stroke-linejoin="round" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>Subir Foto
          </button>
          <button type="button" class="mode-pill" data-input-mode="camera">
            <svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" style="vertical-align:-2px;margin-right:4px"><path stroke-linecap="round" stroke-linejoin="round" d="M15 10l4.553-2.276A1 1 0 0121 8.618v6.764a1 1 0 01-1.447.894L15 14M5 18h8a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z"/></svg>Cámara en Vivo
          </button>
          <button type="button" class="mode-pill" data-input-mode="raw">
            <svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" style="vertical-align:-2px;margin-right:4px"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v1m6 11h2m-6 0h-4v4m-4-8h16M4 7h16a2 2 0 012 2v8a2 2 0 01-2 2H4a2 2 0 01-2-2V9a2 2 0 012-2z"/></svg>Lector USB / Texto
          </button>
        </div>

        <!-- Modo A: Subir Foto / Drag & Drop -->
        <div id="input-mode-file">
          <div class="dropzone" id="dropzone">
            <input type="file" id="fileInput" accept="image/*">
            <span class="dropzone-icon">
              <svg width="44" height="44" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12"/></svg>
            </span>
            <div class="dropzone-title">Arrastra la foto del reverso aquí o haz clic para elegir</div>
            <p class="dropzone-sub">También puedes pegar una imagen directamente con <strong>Ctrl + V</strong> (JPG, PNG, WebP)</p>
          </div>
        </div>

        <!-- Modo B: Cámara en Vivo -->
        <div id="input-mode-camera" hidden>
          <div class="actions" style="margin-bottom:10px">
            <button type="button" id="btnStartCam" class="btn">
              <svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M14.752 11.168l-3.197-2.132A1 1 0 0010 9.87v4.263a1 1 0 001.555.832l3.197-2.132a1 1 0 000-1.664z"/><path stroke-linecap="round" stroke-linejoin="round" d="M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
              Iniciar Cámara
            </button>
            <button type="button" id="btnCaptureOcr" class="btn secondary" hidden title="Usa esto si estás escaneando una Cédula Digital (zona MRZ)">
              <svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="3"/><path stroke-linecap="round" stroke-linejoin="round" d="M3 9a2 2 0 012-2h.93a2 2 0 001.664-.89l.812-1.22A2 2 0 0110.07 4h3.86a2 2 0 011.664.89l.812 1.22A2 2 0 0018.07 7H19a2 2 0 012 2v9a2 2 0 01-2 2H5a2 2 0 01-2-2V9z"/></svg>
              Capturar para MRZ
            </button>
            <button type="button" id="btnStopCam" class="btn danger" hidden>
              <svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="6" y="6" width="12" height="12" rx="2"/></svg>
              Detener
            </button>
          </div>
          <p style="margin:0;font-size:0.82rem;color:var(--muted)">
            Apunta al código de barras PDF417 del reverso. Si es una <strong>Cédula Digital</strong>, enfoca las 3 líneas inferiores y pulsa <em>"Capturar para MRZ"</em>.
          </p>
        </div>

        <!-- Modo C: Lector Físico USB (Pistola 2D) o Texto Crudo -->
        <div id="input-mode-raw" hidden>
          <p style="margin:0 0 8px;font-size:0.83rem;color:var(--muted)">
            Usa una pistola lectora de códigos de barras 2D (USB/HID) o pega aquí la cadena cruda del PDF417 o las 3 líneas MRZ:
          </p>
          <textarea id="rawInputBox" class="text-input" placeholder="Pega aquí la lectura del PDF417 (PubDSK_1...) o las 3 líneas MRZ (ICCOL...)"></textarea>
          <div class="actions" style="margin-top:10px">
            <button type="button" id="btnParseRaw" class="btn">
              <svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4"/></svg>
              Decodificar Texto
            </button>
            <button type="button" id="btnClearRaw" class="btn secondary sm">Limpiar</button>
          </div>
        </div>

        <!-- Visor de Imagen / Video -->
        <div class="viewport-wrap" id="viewportWrap">
          <video id="video" playsinline muted></video>
          <div class="scan-guide" id="scanGuide" hidden>
            <span>Centra el código PDF417 o zona MRZ aquí</span>
          </div>
          <img id="preview" class="preview" alt="Vista previa del documento">
        </div>

        <!-- Estado -->
        <div id="statusBox" class="status-box" role="status" aria-live="polite" hidden></div>

        <!-- Barra de pruebas rápidas con 1 clic -->
        <div class="demo-bar">
          <span>¿Quieres probar sin foto?</span>
          <div class="actions">
            <button type="button" id="btnDemoPdf417" class="btn secondary sm">Demo PDF417 (Clásica)</button>
            <button type="button" id="btnDemoMrz" class="btn secondary sm">Demo MRZ (Digital)</button>
          </div>
        </div>
      </section>

      <!-- Columna Derecha: Resultado Estructurado -->
      <section class="panel" id="resultPanel">
        <div class="panel-header">
          <h2 class="panel-title">2. Datos Extraídos</h2>
          <div class="badges-wrap" id="resultBadges">
            <span class="pill-badge">Sin lectura aún</span>
          </div>
        </div>

        <!-- Placeholder cuando aún no hay lectura -->
        <div id="emptyState" style="text-align:center;padding:36px 16px;color:var(--muted)">
          <div class="empty-icon" style="margin-bottom:10px">
            <svg width="44" height="44" fill="none" stroke="currentColor" stroke-width="1.6" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0zM10 7v3m0 0v3m0-3h3m-3 0H7"/></svg>
          </div>
          <p style="margin:0;font-weight:600;color:var(--ink-soft)">Esperando lectura de documento</p>
          <p style="margin:4px 0 0;font-size:0.84rem">
            Los datos estructurados, la tarjeta visual y el JSON aparecerán aquí al instante.
          </p>
        </div>

        <!-- Contenido del resultado -->
        <div id="resultContent" hidden>
          <div class="id-card">
            <div class="id-card-top">
              <div class="id-card-header-left">
                <div class="id-avatar" id="cardAvatar">CO</div>
                <div>
                  <div class="id-doc-title" id="cardDocType">CÉDULA DE CIUDADANÍA</div>
                  <div class="id-doc-number" id="cardDocNumber">—</div>
                </div>
              </div>
              <div class="badges-wrap" id="cardSubBadges"></div>
            </div>

            <div class="id-grid" id="fieldsGrid"></div>
          </div>

          <div class="actions" style="margin-bottom:14px">
            <button type="button" id="btnSaveServer" class="btn">
              <svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M8 7H5a2 2 0 00-2 2v9a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-3m-1 4l-3 3m0 0l-3-3m3 3V4"/></svg>
              Guardar en Servidor
            </button>
            <button type="button" id="btnCopyJson" class="btn secondary">
              <svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="9" y="9" width="13" height="13" rx="2"/><path stroke-linecap="round" stroke-linejoin="round" d="M5 15H4a2 2 0 01-2-2V4a2 2 0 012-2h9a2 2 0 012 2v1"/></svg>
              Copiar JSON
            </button>
            <button type="button" id="btnDownloadJson" class="btn secondary sm">
              <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M4 16v2a2 2 0 002 2h12a2 2 0 002-2v-2M7 10l5 5m0 0l5-5m-5 5V3"/></svg>
              Descargar .JSON
            </button>
            <button type="button" id="btnDownloadCsv" class="btn secondary sm">
              <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M4 16v2a2 2 0 002 2h12a2 2 0 002-2v-2M7 10l5 5m0 0l5-5m-5 5V3"/></svg>
              Descargar .CSV
            </button>
            <button type="button" id="btnFillDemoForm" class="btn secondary sm">
              <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
              Auto-llenar Formulario
            </button>
            <?php if ($callbackUrl !== ''): ?>
            <button type="button" id="btnSendCallback" class="btn">
              <svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M14 5l7 7m0 0l-7 7m7-7H3"/></svg>
              Enviar a Aplicación
            </button>
            <?php endif; ?>
          </div>

          <details open>
            <summary>Respuesta JSON Estandarizada</summary>
            <pre id="jsonOutput" class="code-block" style="margin-top:8px"></pre>
          </details>

          <details>
            <summary>Payload Crudo Leído (PDF417 / MRZ)</summary>
            <pre id="rawOutput" class="code-block" style="margin-top:8px"></pre>
          </details>
        </div>
      </section>
    </div>
  </div>

  <?php if (!$isEmbed): ?>
  <!-- ==================== TAB 2: HISTORIAL DE REGISTROS ==================== -->
  <div id="tab-history" class="tab-view" hidden>
    <section class="panel">
      <div class="panel-header">
        <div>
          <h2 class="panel-title">Registros Guardados en el Servidor</h2>
          <p style="margin:2px 0 0;font-size:0.84rem;color:var(--muted)">
            Consulta, filtra o exporta las cédulas almacenadas mediante <code>api.php</code>.
          </p>
        </div>
        <div class="actions">
          <input type="search" id="historySearch" class="text-input" placeholder="Buscar por cédula, nombre o RH…" style="width:240px">
          <button type="button" id="btnRefreshHistory" class="btn secondary sm">Actualizar</button>
          <a href="api.php?action=export&amp;format=csv" class="btn secondary sm">Exportar CSV (Excel)</a>
          <a href="api.php?action=export&amp;format=json" class="btn secondary sm">Exportar JSON</a>
        </div>
      </div>

      <div class="table-wrap">
        <table class="data-table">
          <thead>
            <tr>
              <th>Documento</th>
              <th>Nombre Completo</th>
              <th>Nacimiento / Edad</th>
              <th>Sexo / RH</th>
              <th>Fuente</th>
              <th>Fecha Registro</th>
              <th>Acciones</th>
            </tr>
          </thead>
          <tbody id="historyTableBody">
            <tr><td colspan="7" style="text-align:center;color:var(--muted)">Cargando registros…</td></tr>
          </tbody>
        </table>
      </div>
    </section>
  </div>

  <!-- ==================== TAB 3: INTEGRACIÓN Y API ==================== -->
  <div id="tab-integration" class="tab-view" hidden>
    <!-- Demo Interactiva de Auto-llenado -->
    <section class="panel">
      <div class="panel-header">
        <div>
          <h2 class="panel-title">Demo en Vivo: Auto-llenado de Formulario Web</h2>
          <p style="margin:2px 0 0;font-size:0.84rem;color:var(--muted)">
            Con <code>fillFormWithCedula(form, data)</code> puedes poblar automáticamente cualquier formulario de tu ERP, clínica, hotel o ecommerce.
          </p>
        </div>
        <button type="button" id="btnTestFillNow" class="btn sm">Rellenar con lectura actual</button>
      </div>

      <form id="demoClientForm" class="form-grid" onsubmit="event.preventDefault()">
        <div class="form-group">
          <label>Tipo de Documento</label>
          <input type="text" name="documentType" class="text-input" placeholder="CC / TI">
        </div>
        <div class="form-group">
          <label>Número de Documento</label>
          <input type="text" name="documentNumber" class="text-input" placeholder="Ej. 1017929467">
        </div>
        <div class="form-group">
          <label>Nombres</label>
          <input type="text" name="nombres" class="text-input" placeholder="Nombres">
        </div>
        <div class="form-group">
          <label>Apellidos</label>
          <input type="text" name="apellidos" class="text-input" placeholder="Apellidos">
        </div>
        <div class="form-group">
          <label>Fecha de Nacimiento</label>
          <input type="date" name="fecha_nacimiento" class="text-input">
        </div>
        <div class="form-group">
          <label>Edad</label>
          <input type="number" name="edad" class="text-input" placeholder="Años">
        </div>
        <div class="form-group">
          <label>Sexo</label>
          <input type="text" name="sexo" class="text-input" placeholder="M / F">
        </div>
        <div class="form-group">
          <label>Grupo Sanguíneo (RH)</label>
          <input type="text" name="rh" class="text-input" placeholder="O+">
        </div>
      </form>
    </section>

    <div class="grid-2">
      <section class="panel">
        <h3 class="panel-title" style="margin-bottom:10px">Opción A: SDK JavaScript (React, Vue, HTML)</h3>
        <p style="margin:0 0 10px;font-size:0.84rem;color:var(--muted)">
          Importa <code>cedula-sdk.js</code> en cualquier frontend moderno sin dependencias de interfaz:
        </p>
        <pre class="code-block">import {
  CedulaReader,
  parseAuto,
  fillFormWithCedula
} from './cedula-sdk.js';

// 1. Escaneo desde archivo de imagen (PDF417 + respaldo MRZ)
const reader = new CedulaReader();
const { data } = await reader.scanFile(fileInput.files[0]);
console.log(data.documentInfo.documentNumber, data.fullName);

// 2. O auto-llenar un formulario HTML automáticamente
fillFormWithCedula('#miFormulario', data);</pre>
      </section>

      <section class="panel">
        <h3 class="panel-title" style="margin-bottom:10px">Opción B: API REST Backend (`api.php`)</h3>
        <p style="margin:0 0 10px;font-size:0.84rem;color:var(--muted)">
          Envía cadenas crudas desde apps móviles, lectores USB o microservicios a <code>api.php?action=parse</code>:
        </p>
        <pre class="code-block">// Decodificar PDF417 o MRZ en el servidor (POST JSON)
const res = await fetch('api.php?action=parse', {
  method: 'POST',
  headers: { 'Content-Type': 'application/json' },
  body: JSON.stringify({ raw: cadenaCrudaPdf417oMrz })
});
const { ok, data } = await res.json();</pre>
      </section>

      <section class="panel">
        <h3 class="panel-title" style="margin-bottom:10px">Opción C: Clase Nativa PHP (Laravel / WordPress)</h3>
        <p style="margin:0 0 10px;font-size:0.84rem;color:var(--muted)">
          Incluye <code>src/CedulaParser.php</code> directamente en cualquier proyecto PHP 8+:
        </p>
        <pre class="code-block">require_once 'src/CedulaParser.php';
use CedulaApi\CedulaParser;

// Detecta y parsea automáticamente PDF417 o MRZ TD1
$datos = CedulaParser::parseAuto($cadenaLeida);
echo $datos['documentInfo']['documentNumber'];
echo $datos['fullName']; // Ej: VALENTINA SOFIA GARCIA RESTREPO</pre>
      </section>

      <section class="panel">
        <h3 class="panel-title" style="margin-bottom:10px">Opción D: Widget Embebible (`?mode=embed`)</h3>
        <p style="margin:0 0 10px;font-size:0.84rem;color:var(--muted)">
          Incrusta el lector en cualquier sistema mediante <code>&lt;iframe&gt;</code> o ventana emergente y recibe los datos por <code>postMessage</code>:
        </p>
        <pre class="code-block">&lt;iframe src="/Cedula_api/index.php?mode=embed"
        width="100%" height="580" frameborder="0"&gt;&lt;/iframe&gt;

&lt;script&gt;
window.addEventListener('message', (e) =&gt; {
  if (e.data?.type === 'CEDULA_SCANNED') {
    console.log('Cédula recibida:', e.data.data);
  }
});
&lt;/script&gt;</pre>
      </section>
    </div>
  </div>
  <?php endif; ?>
</div>

<script type="module">
import {
  CedulaReader,
  parseAuto,
  escapeControlChars,
  fillFormWithCedula,
  SAMPLE_PDF417_RAW,
  SAMPLE_MRZ_TEXT
} from './cedula-sdk.js';

const CSRF = <?= json_encode($csrf) ?>;
const CALLBACK_URL = <?= json_encode($callbackUrl) ?>;
const $ = (id) => document.getElementById(id);

let current = null; // { data, raw, checks, imageBlob, format }
let cameraControls = null;

const reader = new CedulaReader({
  onStatus: (msg, type) => setStatus(msg, type)
});

// ---- Tema Claro / Oscuro
const themeBtn = $('btnTheme');
if (themeBtn) {
  const savedTheme = localStorage.getItem('cedula_theme') || 'auto';
  document.documentElement.setAttribute('data-theme', savedTheme);
  themeBtn.addEventListener('click', () => {
    const cur = document.documentElement.getAttribute('data-theme') || 'auto';
    const next = cur === 'dark' ? 'light' : 'dark';
    document.documentElement.setAttribute('data-theme', next);
    localStorage.setItem('cedula_theme', next);
  });
}

// ---- Navegación por Pestañas Principales
document.querySelectorAll('.nav-tab').forEach(btn => {
  btn.addEventListener('click', () => {
    document.querySelectorAll('.nav-tab').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
    const target = btn.getAttribute('data-tab');
    ['scanner', 'history', 'integration'].forEach(tab => {
      const el = $('tab-' + tab);
      if (el) el.hidden = (tab !== target);
    });
    if (target === 'history') loadHistory();
  });
});

// ---- Selector de Modo de Captura (Archivo / Cámara / Lector USB)
document.querySelectorAll('.mode-pill').forEach(btn => {
  btn.addEventListener('click', () => {
    document.querySelectorAll('.mode-pill').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
    const mode = btn.getAttribute('data-input-mode');
    if (mode !== 'camera') stopCamera();
    $('input-mode-file').hidden = (mode !== 'file');
    $('input-mode-camera').hidden = (mode !== 'camera');
    $('input-mode-raw').hidden = (mode !== 'raw');
  });
});

function setStatus(msg, cls = '') {
  const s = $('statusBox');
  if (!msg) {
    s.textContent = '';
    s.hidden = true;
    return;
  }
  s.hidden = false;
  let icon = '';
  if (cls === 'ok') {
    icon = '<svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>';
  } else if (cls === 'err') {
    icon = '<svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4m0 4h.01"/></svg>';
  } else if (cls === 'info') {
    icon = '<svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><path stroke-linecap="round" stroke-linejoin="round" d="M12 16v-4m0-4h.01"/></svg>';
  }
  s.innerHTML = (icon ? icon + ' ' : '') + '<span>' + msg + '</span>';
  s.className = 'status-box' + (cls ? ' ' + cls : '');
}

function formatNumberWithDots(numStr) {
  if (!numStr) return '—';
  return String(numStr).replace(/\B(?=(\d{3})+(?!\d))/g, '.');
}

function showScanResult({ data, raw, checks = {}, imageBlob = null, format = 'pdf417' }) {
  $('emptyState').hidden = true;
  $('resultContent').hidden = false;
  $('rawOutput').textContent = escapeControlChars(raw || '');

  if (!data || !data.documentInfo?.documentNumber) {
    current = null;
    $('resultBadges').innerHTML = '<span class="pill-badge warn"><svg width="12" height="12" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" style="vertical-align:-1px;margin-right:3px"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>Formato no reconocido</span>';
    $('jsonOutput').textContent = 'El código se leyó, pero no corresponde a una estructura válida de documento colombiano.';
    setStatus('Código leído, pero formato no reconocido.', 'err');
    return;
  }

  current = { data, raw, checks, imageBlob, format };

  // Emitir evento postMessage para integraciones en iframe o popup
  const payloadMsg = { type: 'CEDULA_SCANNED', data, format };
  if (window.parent && window.parent !== window) {
    window.parent.postMessage(payloadMsg, '*');
  }
  if (window.opener) {
    window.opener.postMessage(payloadMsg, '*');
  }

  const docTypeLabel = data.documentType === 'TI'
    ? 'TARJETA DE IDENTIDAD'
    : (data.documentType === 'CE' ? 'CÉDULA DE EXTRANJERÍA' : 'CÉDULA DE CIUDADANÍA');

  const initials = ((data.firstName?.[0] || '') + (data.lastName?.[0] || '')).toUpperCase() || 'CO';
  $('cardAvatar').textContent = initials;
  $('cardDocType').textContent = docTypeLabel;
  $('cardDocNumber').textContent = formatNumberWithDots(data.documentInfo.documentNumber);

  const sourceBadge = data.source === 'mrz' ? 'MRZ TD1' : 'PDF417';
  const verifiedOk = Boolean(data.birthDateISO && data.birthDateVerified !== false);

  const verifiedBadge = verifiedOk
    ? `<span class="pill-badge ok"><svg width="12" height="12" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24" style="vertical-align:-1px;margin-right:3px"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>Verificado</span>`
    : `<span class="pill-badge warn"><svg width="12" height="12" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" style="vertical-align:-1px;margin-right:3px"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>Revisar Fecha</span>`;

  $('resultBadges').innerHTML = `
    <span class="pill-badge">${sourceBadge}</span>
    ${verifiedBadge}
  `;

  $('cardSubBadges').innerHTML = data.bloodType
    ? `<span class="pill-badge">RH ${data.bloodType}</span>`
    : '';

  const ageStr = data.age !== null && data.age !== undefined ? ` (${data.age} años)` : '';
  const sexLabel = data.gender === 'M' ? 'Masculino (M)' : (data.gender === 'F' ? 'Femenino (F)' : (data.gender || '—'));

  const fields = [
    ['Número de Documento', data.documentInfo.documentNumber],
    ['Tipo de Documento', data.documentType || 'CC'],
    ['Primer Nombre', data.firstName || '—'],
    ['Segundo Nombre', data.middleName || '—'],
    ['Primer Apellido', data.lastName || '—'],
    ['Segundo Apellido', data.secondLastName || '—'],
    ['Fecha de Nacimiento', (data.birthDateISO || data.birthDate || '—') + ageStr],
    ['Sexo', sexLabel],
    ['Grupo Sanguíneo (RH)', data.bloodType || '—'],
    ['Código AFIS / Serial', data.documentInfo.afisCode || data.documentInfo.mrzDocumentNumber || '—']
  ];
  if (data.expiryDateISO) {
    fields.push(['Fecha de Vencimiento', data.expiryDateISO]);
  }
  if (data.documentInfo.fingerCard) {
    fields.push(['Tarjeta Dactilar', data.documentInfo.fingerCard]);
  }

  const grid = $('fieldsGrid');
  grid.innerHTML = '';
  fields.forEach(([label, value]) => {
    const box = document.createElement('div');
    box.className = 'field-box';
    box.title = 'Clic para copiar este valor';
    const l = document.createElement('div');
    l.className = 'field-label';
    l.textContent = label;
    const v = document.createElement('div');
    v.className = 'field-val';
    v.textContent = value;
    box.append(l, v);
    box.addEventListener('click', async () => {
      await navigator.clipboard.writeText(String(value));
      setStatus(`Copiado al portapapeles: ${label} (${value})`, 'ok');
    });
    grid.appendChild(box);
  });

  $('jsonOutput').textContent = JSON.stringify(data, null, 2);

  if ($('demoClientForm')) {
    fillFormWithCedula($('demoClientForm'), data);
  }

  if (verifiedOk) {
    setStatus(`Lectura exitosa (${sourceBadge}): ${data.fullName || data.documentInfo.documentNumber}`, 'ok');
  } else {
    setStatus(`Documento leído (${sourceBadge}), pero verifica manualmente la fecha de nacimiento.`, 'err');
  }
}

// ---- Procesar archivo de imagen (Drag & Drop / Input / Portapapeles)
async function handleImageFile(file) {
  if (!file) return;
  stopCamera();
  const previewUrl = URL.createObjectURL(file);
  $('viewportWrap').classList.add('active');
  $('video').style.display = 'none';
  $('scanGuide').hidden = true;
  $('preview').src = previewUrl;
  $('preview').style.display = 'block';

  try {
    const res = await reader.scanFile(file);
    showScanResult(res);
  } catch (err) {
    setStatus(err.message || 'No se pudo leer el documento.', 'err');
  }
}

$('fileInput').addEventListener('change', (e) => {
  const file = e.target.files?.[0];
  if (file) handleImageFile(file);
  e.target.value = '';
});

// Drag & Drop
const dropzone = $('dropzone');
['dragenter', 'dragover'].forEach(ev => {
  dropzone.addEventListener(ev, (e) => {
    e.preventDefault();
    dropzone.classList.add('dragover');
  });
});
['dragleave', 'drop'].forEach(ev => {
  dropzone.addEventListener(ev, (e) => {
    e.preventDefault();
    dropzone.classList.remove('dragover');
  });
});
dropzone.addEventListener('drop', (e) => {
  const file = e.dataTransfer?.files?.[0];
  if (file && file.type.startsWith('image/')) {
    handleImageFile(file);
  }
});

// Pegar imagen desde el portapapeles (Ctrl + V)
window.addEventListener('paste', (e) => {
  const items = e.clipboardData?.items || [];
  for (const item of items) {
    if (item.type.startsWith('image/')) {
      const file = item.getAsFile();
      if (file) handleImageFile(file);
      break;
    }
  }
});

// ---- Modo Cámara en Vivo
$('btnStartCam').addEventListener('click', async () => {
  $('viewportWrap').classList.add('active');
  $('preview').style.display = 'none';
  $('video').style.display = 'block';
  $('scanGuide').hidden = false;
  $('btnCaptureOcr').hidden = false;
  $('btnStopCam').hidden = false;
  setStatus('Iniciando cámara… apunta al código PDF417 del reverso.', 'info');

  try {
    const zxing = await reader._getZxingReader();
    cameraControls = await zxing.decodeFromConstraints(
      { video: { facingMode: { ideal: 'environment' }, width: { ideal: 1920 } } },
      $('video'),
      (result) => {
        if (!result) return;
        const v = $('video');
        const c = document.createElement('canvas');
        c.width = v.videoWidth;
        c.height = v.videoHeight;
        c.getContext('2d').drawImage(v, 0, 0);
        const raw = result.getText();
        stopCamera();
        c.toBlob((blob) => {
          const parsed = parseAuto(raw);
          if (parsed) {
            showScanResult({ ...parsed, imageBlob: blob });
          }
        }, 'image/jpeg', 0.9);
      }
    );
  } catch (err) {
    stopCamera();
    setStatus('No se pudo acceder a la cámara (requiere HTTPS o localhost): ' + err.message, 'err');
  }
});

// Capturar fotograma de cámara para OCR MRZ (Cédula Digital)
$('btnCaptureOcr').addEventListener('click', async () => {
  const v = $('video');
  if (!v.videoWidth) return;
  const c = document.createElement('canvas');
  c.width = v.videoWidth;
  c.height = v.videoHeight;
  c.getContext('2d').drawImage(v, 0, 0);
  stopCamera();
  c.toBlob((blob) => {
    if (blob) handleImageFile(blob);
  }, 'image/jpeg', 0.92);
});

function stopCamera() {
  if (cameraControls) {
    cameraControls.stop();
    cameraControls = null;
  }
  $('video').style.display = 'none';
  $('scanGuide').hidden = true;
  $('btnCaptureOcr').hidden = true;
  $('btnStopCam').hidden = true;
}
$('btnStopCam').addEventListener('click', () => {
  stopCamera();
  setStatus('Cámara detenida.');
});

// ---- Modo Lector USB / Texto Crudo
$('btnParseRaw').addEventListener('click', () => {
  const val = $('rawInputBox').value.trim();
  if (!val) {
    setStatus('Ingresa o escanea primero el texto del código PDF417 o las 3 líneas MRZ.', 'err');
    return;
  }
  const parsed = parseAuto(val);
  if (!parsed) {
    setStatus('No se encontró una estructura válida de PDF417 ni MRZ colombiano en el texto.', 'err');
    return;
  }
  showScanResult(parsed);
});

$('btnClearRaw').addEventListener('click', () => {
  $('rawInputBox').value = '';
  $('rawInputBox').focus();
});

// ---- Botones de Demostración en 1 Clic
$('btnDemoPdf417').addEventListener('click', () => {
  const parsed = parseAuto(SAMPLE_PDF417_RAW);
  if (parsed) showScanResult(parsed);
});

$('btnDemoMrz').addEventListener('click', () => {
  const parsed = parseAuto(SAMPLE_MRZ_TEXT);
  if (parsed) showScanResult(parsed);
});

// ---- Acciones sobre el Resultado
$('btnCopyJson').addEventListener('click', async () => {
  if (!current) return;
  await navigator.clipboard.writeText(JSON.stringify(current.data, null, 2));
  setStatus('JSON copiado al portapapeles.', 'ok');
});

$('btnDownloadJson').addEventListener('click', () => {
  if (!current) return;
  const blob = new Blob([JSON.stringify(current.data, null, 2)], { type: 'application/json' });
  const a = document.createElement('a');
  a.href = URL.createObjectURL(blob);
  a.download = `cedula-${current.data.documentInfo.documentNumber}.json`;
  a.click();
  URL.revokeObjectURL(a.href);
});

$('btnDownloadCsv').addEventListener('click', () => {
  if (!current) return;
  const d = current.data;
  const headers = ['TipoDoc','NumeroDocumento','PrimerNombre','SegundoNombre','PrimerApellido','SegundoApellido','FechaNacimientoISO','Edad','Sexo','RH','Fuente'];
  const row = [
    d.documentType || 'CC',
    d.documentInfo?.documentNumber || '',
    d.firstName || '',
    d.middleName || '',
    d.lastName || '',
    d.secondLastName || '',
    d.birthDateISO || '',
    d.age ?? '',
    d.gender || '',
    d.bloodType || '',
    d.source || ''
  ].map(v => `"${String(v).replace(/"/g, '""')}"`).join(',');
  const csv = '\uFEFF' + headers.join(',') + '\n' + row + '\n';
  const blob = new Blob([csv], { type: 'text/csv;charset=utf-8' });
  const a = document.createElement('a');
  a.href = URL.createObjectURL(blob);
  a.download = `cedula-${d.documentInfo.documentNumber}.csv`;
  a.click();
  URL.revokeObjectURL(a.href);
});

$('btnSaveServer').addEventListener('click', async () => {
  if (!current) return;
  const fd = new FormData();
  fd.append('csrf', CSRF);
  fd.append('data', JSON.stringify(current.data));
  if (current.imageBlob) {
    fd.append('image', current.imageBlob, 'reverso.jpg');
  }
  setStatus('Guardando registro en el servidor…', 'info');
  try {
    const res = await fetch('api.php?action=save', { method: 'POST', body: fd });
    const out = await res.json();
    if (!res.ok || !out.ok) throw new Error(out.error || 'Error al guardar');
    setStatus(`Guardado exitosamente en el servidor (ID: ${out.id}).`, 'ok');
    refreshSavedBadge();
  } catch (err) {
    setStatus(err.message, 'err');
  }
});

$('btnFillDemoForm')?.addEventListener('click', () => {
  if (!current) return;
  const tabBtn = document.querySelector('.nav-tab[data-tab="integration"]');
  if (tabBtn) tabBtn.click();
  fillFormWithCedula($('demoClientForm'), current.data);
});

$('btnTestFillNow')?.addEventListener('click', () => {
  if (!current) {
    const parsed = parseAuto(SAMPLE_PDF417_RAW);
    if (parsed) showScanResult(parsed);
  }
  if (current) {
    fillFormWithCedula($('demoClientForm'), current.data);
  }
});

if ($('btnSendCallback')) {
  $('btnSendCallback').addEventListener('click', () => {
    if (!current || !CALLBACK_URL) return;
    const url = new URL(CALLBACK_URL, window.location.origin);
    url.searchParams.set('cedula_data', JSON.stringify(current.data));
    window.location.href = url.toString();
  });
}

// ---- Historial de Registros Guardados
async function refreshSavedBadge() {
  const badge = $('savedCountBadge');
  if (!badge) return;
  try {
    const res = await fetch('api.php?action=health');
    const out = await res.json();
    if (out.ok) badge.textContent = out.savedCount ?? 0;
  } catch (_) {}
}

async function loadHistory() {
  const tbody = $('historyTableBody');
  if (!tbody) return;
  const q = $('historySearch')?.value.trim() || '';
  try {
    const res = await fetch('api.php?action=list&q=' + encodeURIComponent(q));
    const out = await res.json();
    if (!out.ok) throw new Error(out.error || 'Error al cargar historial');
    if ($('savedCountBadge') && !q) {
      $('savedCountBadge').textContent = out.count;
    }
    if (!out.items.length) {
      tbody.innerHTML = '<tr><td colspan="7" style="text-align:center;color:var(--muted);padding:24px">No hay registros guardados que coincidan.</td></tr>';
      return;
    }
    tbody.innerHTML = '';
    out.items.forEach(item => {
      const tr = document.createElement('tr');
      const docNum = item.documentInfo?.documentNumber || '—';
      const docType = item.documentType || 'CC';
      const fullName = item.fullName || [item.firstName, item.middleName, item.lastName, item.secondLastName].filter(Boolean).join(' ') || '—';
      const birth = (item.birthDateISO || item.birthDate || '—') + (item.age ? ` (${item.age}a)` : '');
      const sexRh = [item.gender || '—', item.bloodType || '—'].join(' · ');
      const source = (item.source || 'pdf417').toUpperCase();
      const created = item.createdAt ? new Date(item.createdAt).toLocaleString('es-CO') : '—';

      tr.innerHTML = `
        <td><strong>${docType} ${docNum}</strong></td>
        <td>${fullName}</td>
        <td>${birth}</td>
        <td>${sexRh}</td>
        <td><span class="pill-badge">${source}</span></td>
        <td style="color:var(--muted);font-size:0.8rem">${created}</td>
        <td>
          <div class="actions">
            <button type="button" class="btn secondary sm btn-view-item">Ver</button>
            <button type="button" class="btn danger sm btn-del-item">Eliminar</button>
          </div>
        </td>
      `;

      tr.querySelector('.btn-view-item').addEventListener('click', () => {
        showScanResult({ data: item, raw: JSON.stringify(item, null, 2), format: item.source || 'pdf417' });
        document.querySelector('.nav-tab[data-tab="scanner"]')?.click();
      });

      tr.querySelector('.btn-del-item').addEventListener('click', async () => {
        const fd = new FormData();
        fd.append('csrf', CSRF);
        fd.append('id', item.id);
        const delRes = await fetch('api.php?action=delete', { method: 'POST', body: fd });
        const delOut = await delRes.json();
        if (delOut.ok) {
          loadHistory();
        }
      });

      tbody.appendChild(tr);
    });
  } catch (err) {
    tbody.innerHTML = `<tr><td colspan="7" style="color:var(--err);text-align:center">${err.message}</td></tr>`;
  }
}

$('btnRefreshHistory')?.addEventListener('click', loadHistory);
$('historySearch')?.addEventListener('input', loadHistory);

refreshSavedBadge();
</script>
</body>
</html>
