<?php
session_start();
if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(16));
}
$csrf = $_SESSION['csrf'];
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Lector de cédula colombiana</title>
<style>
  :root {
    --bg: #f6f7f5; --surface: #ffffff; --ink: #1d2523; --muted: #66726f;
    --line: #d9dedb; --accent: #17497a; --accent-ink: #ffffff;
    --ok: #1f6b3a; --err: #a12a2a;
  }
  @media (prefers-color-scheme: dark) {
    :root {
      --bg: #141817; --surface: #1c2220; --ink: #e8ecea; --muted: #94a09c;
      --line: #2f3836; --accent: #6fa8dc; --accent-ink: #0d1a26;
      --ok: #7fcf9b; --err: #f08a8a;
    }
  }
  * { box-sizing: border-box; }
  body { margin: 0; background: var(--bg); color: var(--ink);
         font: 16px/1.5 system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; }
  main { max-width: 760px; margin: 0 auto; padding: 32px 20px 64px; }
  h1 { font-size: 1.6rem; margin: 0 0 4px; }
  p.lead { margin: 0 0 24px; color: var(--muted); }
  .panel { background: var(--surface); border: 1px solid var(--line);
           border-radius: 8px; padding: 20px; margin-bottom: 20px; }
  .actions { display: flex; flex-wrap: wrap; gap: 10px; }
  button, label.btn {
    font: inherit; cursor: pointer; padding: 10px 16px; border-radius: 6px;
    border: 1px solid var(--accent); background: var(--accent); color: var(--accent-ink);
  }
  button.secondary, label.btn.secondary { background: transparent; color: var(--accent); }
  button:disabled { opacity: .5; cursor: not-allowed; }
  :focus-visible { outline: 3px solid var(--accent); outline-offset: 2px; }
  input[type=file] { position: absolute; opacity: 0; width: 1px; height: 1px; }
  input[type=file]:focus-visible + span { outline: 3px solid var(--accent); outline-offset: 2px; }
  video, img.preview { width: 100%; max-height: 360px; object-fit: contain;
                       background: #000; border-radius: 6px; margin-top: 16px; display: none; }
  #status { margin: 16px 0 0; min-height: 1.5em; color: var(--muted); }
  #status.ok { color: var(--ok); } #status.err { color: var(--err); }
  dl { display: grid; grid-template-columns: max-content 1fr; gap: 6px 20px; margin: 0 0 16px; }
  dt { color: var(--muted); } dd { margin: 0; font-weight: 600; }
  pre { margin: 0; padding: 14px; overflow-x: auto; background: var(--bg);
        border: 1px solid var(--line); border-radius: 6px; font-size: .88rem; }
  details { margin-top: 16px; } summary { cursor: pointer; color: var(--muted); }
  [hidden] { display: none !important; }
</style>
</head>
<body>
<main>
  <h1>Lector de cédula colombiana</h1>
  <p class="lead">Sube una foto del reverso de la cédula (el lado del código de barras) o usa la cámara.</p>

  <section class="panel">
    <div class="actions">
      <label class="btn">
        <input type="file" id="file" accept="image/*">
        <span>Subir foto del reverso</span>
      </label>
      <button type="button" id="btnCam" class="secondary">Usar cámara</button>
      <button type="button" id="btnStop" class="secondary" hidden>Detener cámara</button>
    </div>
    <video id="video" playsinline muted></video>
    <img id="preview" class="preview" alt="Foto cargada">
    <p id="status" role="status" aria-live="polite"></p>
  </section>

  <section class="panel" id="result" hidden>
    <h2 style="margin-top:0;font-size:1.15rem">Datos de la cédula</h2>
    <dl id="fields"></dl>
    <pre id="json"></pre>
    <div class="actions" style="margin-top:16px">
      <button type="button" id="btnCopy" class="secondary">Copiar JSON</button>
      <button type="button" id="btnDownload" class="secondary">Descargar JSON</button>
      <button type="button" id="btnSave">Guardar en el servidor</button>
    </div>
    <details>
      <summary>Texto crudo leído del PDF417</summary>
      <pre id="raw"></pre>
    </details>
  </section>
</main>

<script type="module">
import { BrowserMultiFormatReader } from 'https://cdn.jsdelivr.net/npm/@zxing/browser@0.1.5/+esm';
import { DecodeHintType, BarcodeFormat } from 'https://cdn.jsdelivr.net/npm/@zxing/library@0.21.3/+esm';
import { decodeColombianPdf417 } from 'https://cdn.jsdelivr.net/npm/colombia-id-parser@1.0.0/+esm';

const CSRF = <?= json_encode($csrf) ?>;
const $ = (id) => document.getElementById(id);

const hints = new Map();
hints.set(DecodeHintType.POSSIBLE_FORMATS, [BarcodeFormat.PDF_417]);
hints.set(DecodeHintType.TRY_HARDER, true);
hints.set(DecodeHintType.CHARACTER_SET, 'ISO-8859-1'); // conserva los bytes 1:1
const reader = new BrowserMultiFormatReader(hints);

let controls = null;
let current = null; // { data, raw, imageBlob }

function setStatus(msg, cls = '') { const s = $('status'); s.textContent = msg; s.className = cls; }

function escapeControl(str) {
  return [...str].map(c => {
    const n = c.charCodeAt(0);
    return n < 32 || n === 127 ? '\\x' + n.toString(16).padStart(2, '0') : c;
  }).join('');
}

function canvasFromImage(img, maxSide) {
  const scale = Math.min(1, maxSide / Math.max(img.naturalWidth, img.naturalHeight));
  const c = document.createElement('canvas');
  c.width = Math.round(img.naturalWidth * scale);
  c.height = Math.round(img.naturalHeight * scale);
  c.getContext('2d').drawImage(img, 0, 0, c.width, c.height);
  return c;
}

const loadImage = (url) => new Promise((res, rej) => {
  const img = new Image(); img.onload = () => res(img); img.onerror = rej; img.src = url;
});

// Intenta decodificar la foto a varios tamaños (las fotos de celular son enormes).
async function decodeFile(file) {
  const url = URL.createObjectURL(file);
  $('preview').src = url; $('preview').style.display = 'block';
  const img = await loadImage(url);
  for (const side of [2000, 1400, 1000, 700]) {
    try {
      return reader.decodeFromCanvas(canvasFromImage(img, side));
    } catch (_) { /* siguiente tamaño */ }
  }
  throw new Error('No se encontró un PDF417 legible en la imagen.');
}

// colombia-id-parser corta el texto en posiciones fijas; si el texto leído por ZXing
// se corre aunque sea un carácter, la fecha sale corrupta (ej. "31-70-M200").
// Aquí re-extraemos sexo, fecha y RH anclándonos al patrón real: [M|F] + AAAAMMDD.
function parseCedula(raw) {
  const data = decodeColombianPdf417(raw);
  if (!data) return null;

  const START = 100; // después de los nombres, que solo contienen letras
  const m = /([MF])(\d{4})(\d{2})(\d{2})/.exec(raw.slice(START));
  if (m) {
    const [, sex, y, mo, d] = m;
    const year = +y, month = +mo, day = +d;
    const valid = year >= 1900 && year <= new Date().getFullYear()
               && month >= 1 && month <= 12 && day >= 1 && day <= 31;
    if (valid) {
      const idx = START + m.index;
      data.gender = sex;
      data.birthDate = `${d}-${mo}-${y}`;      // formato original de la librería
      data.birthDateISO = `${y}-${mo}-${d}`;   // AAAA-MM-DD, listo para tu app/BD
      const rh = /(AB|A|B|O)[+-]/.exec(raw.slice(idx + 9, idx + 30));
      if (rh) data.bloodType = rh[0];
    }
  }
  return data;
}

function showResult(raw, imageBlob) { showData(parseCedula(raw), raw, imageBlob); }

function showData(data, raw, imageBlob) {
  $('result').hidden = false;
  $('raw').textContent = escapeControl(raw);
  if (!data) {
    current = null;
    $('fields').innerHTML = '';
    $('json').textContent = 'El código se leyó, pero no tiene el formato de la cédula clásica (no contiene "PubDSK_").';
    ['btnCopy','btnDownload','btnSave'].forEach(id => $(id).disabled = true);
    setStatus('Código leído, pero formato no reconocido.', 'err');
    return;
  }
  current = { data, raw, imageBlob };
  const d = data.documentInfo;
  const rows = [
    ['Número de cédula', d.documentNumber],
    ['Primer nombre', data.firstName],
    ['Segundo nombre', data.middleName || '—'],
    ['Primer apellido', data.lastName],
    ['Segundo apellido', data.secondLastName],
    ['Fecha de nacimiento', data.birthDateISO || data.birthDate],
    ['Sexo', data.gender],
    ['Grupo sanguíneo (RH)', data.bloodType || '—'],
  ];
  if (data.expiryDateISO) rows.push(['Vence', data.expiryDateISO]);
  $('fields').innerHTML = '';
  rows.forEach(([k, v]) => {
    const dt = document.createElement('dt'); dt.textContent = k;
    const dd = document.createElement('dd'); dd.textContent = v;
    $('fields').append(dt, dd);
  });
  $('json').textContent = JSON.stringify(data, null, 2);
  ['btnCopy','btnDownload','btnSave'].forEach(id => $(id).disabled = false);
  const via = data.source === 'mrz' ? ' (zona MRZ)' : '';
  if (data.birthDateISO && data.birthDateVerified !== false) setStatus('Lectura exitosa' + via + '.', 'ok');
  else setStatus('Se leyó el documento' + via + ', pero la fecha de nacimiento NO pasó la validación. Revísala o repite la foto.', 'err');
}

// ---- Respaldo: leer los caracteres inferiores (MRZ) con OCR
let ocrWorker = null;
function cropBottom(img, frac) {
  const sy = Math.round(img.naturalHeight * (1 - frac));
  const sh = img.naturalHeight - sy;
  const scale = Math.min(2.5, 1800 / img.naturalWidth);
  const c = document.createElement('canvas');
  c.width = Math.round(img.naturalWidth * scale); c.height = Math.round(sh * scale);
  const ctx = c.getContext('2d');
  ctx.filter = 'grayscale(1) contrast(1.6)';
  ctx.drawImage(img, 0, sy, img.naturalWidth, sh, 0, 0, c.width, c.height);
  return c;
}

async function readMrz(file) {
  let extractMrzLines, parseTD1;
  try {
    ({ extractMrzLines, parseTD1 } = await import('./mrz.js'));
  } catch (_) {
    throw new Error('No se pudo leer el PDF417 y falta el archivo mrz.js junto a index.php (necesario para leer la zona MRZ).');
  }
  const img = await loadImage(URL.createObjectURL(file));
  if (!ocrWorker) {
    setStatus('Cargando OCR (solo la primera vez)…');
    const { createWorker } = await import('https://cdn.jsdelivr.net/npm/tesseract.js@5.1.1/+esm');
    ocrWorker = await createWorker('eng');
    await ocrWorker.setParameters({
      tessedit_char_whitelist: 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789<',
      tessedit_pageseg_mode: '6',
    });
  }
  let best = null;
  for (const frac of [0.4, 0.3, 0.55]) { // distintos recortes de la parte inferior
    setStatus('Leyendo caracteres inferiores (MRZ)…');
    const { data: { text } } = await ocrWorker.recognize(cropBottom(img, frac));
    const lines = extractMrzLines(text);
    if (!lines) continue;
    const r = parseTD1(lines);
    if (r.checks.birth && r.checks.document) return r;
    best = best || r;
  }
  if (best) return best;
  throw new Error('No se pudo leer ni el PDF417 ni la zona MRZ. Prueba con una foto más nítida, de frente y bien iluminada.');
}

// ---- Subir foto
$('file').addEventListener('change', async (e) => {
  const file = e.target.files[0];
  if (!file) return;
  stopCamera();
  setStatus('Leyendo código…');
  try {
    let result = null;
    try { result = await decodeFile(file); } catch (_) { /* respaldo MRZ */ }
    if (result) {
      showResult(result.getText(), file);
    } else {
      const mrz = await readMrz(file);
      showData(mrz.data, mrz.text, file);
    }
  } catch (err) {
    $('result').hidden = true;
    setStatus(err.message || 'No se pudo leer el documento.', 'err');
  }
  e.target.value = '';
});

// ---- Cámara
$('btnCam').addEventListener('click', async () => {
  $('preview').style.display = 'none';
  $('video').style.display = 'block';
  $('btnStop').hidden = false;
  setStatus('Apunta la cámara al código de barras del reverso…');
  try {
    controls = await reader.decodeFromConstraints(
      { video: { facingMode: { ideal: 'environment' }, width: { ideal: 1920 } } },
      $('video'),
      (result) => {
        if (!result) return;
        const v = $('video');
        const c = document.createElement('canvas');
        c.width = v.videoWidth; c.height = v.videoHeight;
        c.getContext('2d').drawImage(v, 0, 0);
        const raw = result.getText();
        stopCamera();
        c.toBlob((blob) => showResult(raw, blob), 'image/jpeg', 0.9);
      }
    );
  } catch (err) {
    stopCamera();
    setStatus('No se pudo abrir la cámara (requiere HTTPS o localhost y permisos): ' + err.message, 'err');
  }
});

function stopCamera() {
  if (controls) { controls.stop(); controls = null; }
  $('video').style.display = 'none';
  $('btnStop').hidden = true;
}
$('btnStop').addEventListener('click', () => { stopCamera(); setStatus(''); });

// ---- Acciones sobre el resultado
$('btnCopy').addEventListener('click', async () => {
  await navigator.clipboard.writeText($('json').textContent);
  setStatus('JSON copiado.', 'ok');
});

$('btnDownload').addEventListener('click', () => {
  const blob = new Blob([$('json').textContent], { type: 'application/json' });
  const a = document.createElement('a');
  a.href = URL.createObjectURL(blob);
  a.download = `cedula-${current.data.documentInfo.documentNumber}.json`;
  a.click();
  URL.revokeObjectURL(a.href);
});

$('btnSave').addEventListener('click', async () => {
  if (!current) return;
  const fd = new FormData();
  fd.append('csrf', CSRF);
  fd.append('data', JSON.stringify(current.data));
  if (current.imageBlob) fd.append('image', current.imageBlob, 'reverso.jpg');
  setStatus('Guardando…');
  try {
    const res = await fetch('guardar.php', { method: 'POST', body: fd });
    const out = await res.json();
    if (!res.ok || !out.ok) throw new Error(out.error || 'Error al guardar');
    setStatus('Guardado en el servidor con id ' + out.id + '.', 'ok');
  } catch (err) {
    setStatus(err.message, 'err');
  }
});
</script>
</body>
</html>