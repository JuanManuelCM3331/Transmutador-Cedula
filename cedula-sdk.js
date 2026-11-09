/**
 * Cedula API - SDK JavaScript Universal (ES Module)
 * Adaptable a cualquier proyecto web (Vanilla JS, React, Vue, Angular, PHP, Laravel, etc.)
 *
 * Soporta:
 *  1. Decodificación nativa de PDF417 de Cédula de Ciudadanía y Tarjeta de Identidad (con o sin bytes NUL).
 *  2. Lectura de zona MRZ TD1 (Cédula Digital / TI Digital) con validación de dígitos ICAO 9303.
 *  3. Respaldo OCR para reverso de Cédula Amarilla clásica cuando el código de barras está deteriorado.
 *  4. Escaneo desde archivo de imagen, cámara en vivo, portapapeles o pistola lectora USB (HID).
 *  5. Auto-llenado de formularios HTML y comunicación por postMessage (modo iframe/widget).
 */

import {
  checkDigit,
  calculateAge,
  extractMrzLines,
  parseTD1,
  parseYellowCardOcr
} from './mrz.js';

export {
  checkDigit,
  calculateAge,
  extractMrzLines,
  parseTD1,
  parseYellowCardOcr
};

/**
 * Datos de demostración (ficticios) para pruebas rápidas sin documento físico.
 */
export const SAMPLE_PDF417_RAW =
  '030492144302            PubDSK_1\x00\x00\x00\x00\x00\x0000412307721017929467' +
  'GARCIA\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00' +
  'RESTREPO\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00' +
  'VALENTINA\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00' +
  'SOFIA\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00' +
  '0F1998052405001O+\x00\x0025382599\x00\x00\x00\x00';

export const SAMPLE_MRZ_TEXT = [
  'ICCOL0019482732<<<<<<<<<<<<<<<',
  '9805242F3205242COL1020304050<3',
  'GARCIA<RESTREPO<<VALENTINA<SOF'
].join('\n');

/**
 * Convierte secuencias escapadas como \x00 o \x0a de vuelta a sus caracteres reales.
 * Útil cuando el usuario pega el "texto crudo" escapado para volver a probarlo.
 * @param {string} str
 * @returns {string}
 */
export function unescapeControlChars(str) {
  if (!str || typeof str !== 'string') return '';
  return str.replace(/\\x([0-9a-fA-F]{2})/g, (_, hex) =>
    String.fromCharCode(parseInt(hex, 16))
  );
}

/**
 * Escapa caracteres de control ASCII (< 32 o 127) en notación \xHH para visualización segura.
 * @param {string} str
 * @returns {string}
 */
export function escapeControlChars(str) {
  if (!str || typeof str !== 'string') return '';
  return [...str].map(c => {
    const n = c.charCodeAt(0);
    return n < 32 || n === 127 ? '\\x' + n.toString(16).padStart(2, '0') : c;
  }).join('');
}

/**
 * Limpia un campo de nombre extraído del PDF417 conservando letras y la Ñ.
 */
function cleanNameField(str) {
  if (!str) return '';
  return str
    .replace(/\xD1/g, 'Ñ')
    .replace(/\xF1/g, 'Ñ')
    .replace(/[\x00-\x1F\x7F]+/g, ' ')
    .replace(/[^A-Za-zÁÉÍÓÚÜÑáéíóúüñ\s'-]/g, ' ')
    .replace(/\s+/g, ' ')
    .trim()
    .toUpperCase();
}

/**
 * Parsea la cadena cruda del código de barras PDF417 de una cédula o TI colombiana.
 * Funciona tanto con lecturas binarias de cámara/imagen (con bytes \x00) como con
 * pistolas lectoras USB (HID) que eliminan o convierten los bytes nulos en espacios.
 *
 * @param {string} rawInput - Cadena leída del PDF417
 * @returns {object|null} Objeto de datos normalizado o null si no es un PDF417 colombiano válido
 */
export function parseColombianPdf417(rawInput) {
  if (!rawInput || typeof rawInput !== 'string') return null;

  const raw = unescapeControlChars(rawInput);

  // Verificar marcador de documento colombiano (PubDSK_ o estructura de fecha/RH con cabecera numérica)
  const pubMatch = /PubDSK_\d/i.exec(raw);
  const dateRhRegex = /0?([MF])((?:19|20)\d{2})(0[1-9]|1[0-2])(0[1-9]|[12]\d|3[01])(\d{2})?(\d{3})?\s*(AB|A|B|O)([+-])/;
  const anchorMatch = dateRhRegex.exec(raw.slice(pubMatch ? pubMatch.index : 0));

  if (!pubMatch && !anchorMatch) {
    return null;
  }

  let documentNumber = '';
  let afisCode = '';
  let fingerCard = '';
  let lastName = '';
  let secondLastName = '';
  let firstName = '';
  let middleName = '';
  let gender = '';
  let birthDate = '';
  let birthDateISO = '';
  let birthDateVerified = false;
  let departmentCode = '';
  let municipalityCode = '';
  let bloodType = '';
  let documentType = raw.startsWith('I') || raw.startsWith('01') ? 'TI' : 'CC';

  // Localizar inicio tras PubDSK_1 (o al principio si el lector recortó la cabecera)
  const afterPubIdx = pubMatch ? pubMatch.index + pubMatch[0].length : 0;
  const tailAfterPub = raw.slice(afterPubIdx);

  // Buscar el bloque numérico (AFIS + Número de Cédula) justo antes del primer apellido
  const numAndNameMatch = /^[\x00\s]*(\d{10,22})([A-Za-zÁÉÍÓÚÜÑ\xD1])/.exec(tailAfterPub);

  let nameStartIdx = -1;
  if (numAndNameMatch) {
    const digitsBlock = numAndNameMatch[1];
    // Los últimos 10 dígitos del bloque corresponden al número de documento; los anteriores al código AFIS
    if (digitsBlock.length >= 10) {
      const rawDocNum = digitsBlock.slice(-10);
      const rawAfis = digitsBlock.slice(0, -10);
      documentNumber = rawDocNum.replace(/^0+(?=\d{4,})/, '');
      afisCode = rawAfis.replace(/^0+/, '');
    } else {
      documentNumber = digitsBlock.replace(/^0+/, '');
    }
    nameStartIdx = afterPubIdx + numAndNameMatch.index + numAndNameMatch[0].length - 1;
  } else if (raw.length >= 168 && pubMatch) {
    // Respaldo por posiciones relativas estándar respecto a PubDSK_1 (que suele estar en offset 24)
    const base = pubMatch.index - 24;
    afisCode = raw.slice(Math.max(0, 2 + base), Math.max(0, 10 + base)).replace(/\D+/g, '').replace(/^0+/, '');
    documentNumber = raw.slice(48 + base, 58 + base).replace(/\D+/g, '').replace(/^0+(?=\d{4,})/, '');
    nameStartIdx = 58 + base;
  }

  // Extraer fecha de nacimiento, sexo, código DANE y RH desde el ancla segura
  const searchFrom = nameStartIdx >= 0 ? nameStartIdx : afterPubIdx;
  const m = dateRhRegex.exec(raw.slice(searchFrom));

  let nameEndIdx = -1;
  if (m) {
    const [, sex, y, mo, d, dept, muni, rhGroup, rhSign] = m;
    const year = Number(y), month = Number(mo), day = Number(d);
    const currentYear = new Date().getFullYear();

    // Validar fecha real en calendario
    const dt = new Date(Date.UTC(year, month - 1, day));
    const isValidDate =
      year >= 1900 && year <= currentYear &&
      dt.getUTCFullYear() === year &&
      dt.getUTCMonth() === month - 1 &&
      dt.getUTCDate() === day;

    gender = sex;
    if (isValidDate) {
      birthDate = `${d}-${mo}-${y}`;
      birthDateISO = `${y}-${mo}-${d}`;
      birthDateVerified = true;
    }
    departmentCode = dept || '';
    municipalityCode = muni || '';
    bloodType = `${rhGroup}${rhSign}`;

    nameEndIdx = searchFrom + m.index;

    // Buscar tarjeta decadactilar (fingerCard) después del RH
    const afterRh = raw.slice(searchFrom + m.index + m[0].length);
    const fingerMatch = /(\d{7,10})/.exec(afterRh);
    if (fingerMatch) {
      fingerCard = fingerMatch[1];
    }
  }

  // Extraer los 4 campos de nombres entre nameStartIdx y nameEndIdx
  if (nameStartIdx >= 0) {
    const end = nameEndIdx > nameStartIdx ? nameEndIdx : nameStartIdx + 92;
    const nameBlock = raw.slice(nameStartIdx, end);

    if (nameBlock.length >= 88 && nameBlock.length <= 96 && /[\x00]/.test(nameBlock)) {
      // Bloques fijos de 23 bytes (formato nativo Registraduría)
      lastName       = cleanNameField(nameBlock.slice(0, 23));
      secondLastName = cleanNameField(nameBlock.slice(23, 46));
      firstName      = cleanNameField(nameBlock.slice(46, 69));
      middleName     = cleanNameField(nameBlock.slice(69, 92));
    } else if (/[\x00]+|\s{2,}/.test(nameBlock)) {
      // Separado por secuencias de NULs o múltiples espacios
      const segments = nameBlock
        .split(/[\x00]+|\s{2,}/)
        .map(cleanNameField)
        .filter(Boolean);
      lastName       = segments[0] || '';
      secondLastName = segments[1] || '';
      firstName      = segments[2] || '';
      middleName     = segments.slice(3).join(' ');
    } else {
      // Lector USB que colapsó los NULs en espacios simples
      const words = cleanNameField(nameBlock).split(' ').filter(Boolean);
      if (words.length >= 4) {
        lastName       = words[0];
        secondLastName = words[1];
        firstName      = words[2];
        middleName     = words.slice(3).join(' ');
      } else if (words.length === 3) {
        lastName       = words[0];
        secondLastName = words[1];
        firstName      = words[2];
      } else if (words.length === 2) {
        lastName       = words[0];
        firstName      = words[1];
      } else if (words.length === 1) {
        lastName       = words[0];
      }
    }
  }

  if (!documentNumber || !/^\d{4,12}$/.test(documentNumber)) {
    return null;
  }

  const age = calculateAge(birthDateISO);
  if (age !== null && age < 18) {
    documentType = 'TI';
  }

  const fullName = [firstName, middleName, lastName, secondLastName].filter(Boolean).join(' ');

  return {
    source: 'pdf417',
    documentType,
    firstName,
    middleName,
    lastName,
    secondLastName,
    fullName,
    birthDate,
    birthDateISO,
    age,
    birthDateVerified,
    gender,
    bloodType,
    expiryDateISO: '',
    nationality: 'COL',
    documentInfo: {
      documentNumber,
      afisCode,
      fingerCard,
      departmentCode,
      municipalityCode
    }
  };
}

/**
 * Detecta automáticamente el formato del texto crudo (PDF417, MRZ TD1 o OCR de Cédula Amarilla)
 * y retorna `{ data, raw, checks, format }`.
 *
 * @param {string} rawText
 * @returns {{ data: object, raw: string, checks?: object, format: 'pdf417'|'mrz'|'ocr_yellow' } | null}
 */
export function parseAuto(rawText) {
  if (!rawText || typeof rawText !== 'string') return null;

  // 1. Intentar como PDF417
  const pdfData = parseColombianPdf417(rawText);
  if (pdfData) {
    return {
      data: pdfData,
      raw: unescapeControlChars(rawText),
      checks: {
        document: Boolean(pdfData.documentInfo?.documentNumber),
        birth: Boolean(pdfData.birthDateVerified)
      },
      format: 'pdf417'
    };
  }

  // 2. Intentar como MRZ TD1 (3 líneas x 30)
  const mrzLines = extractMrzLines(rawText);
  if (mrzLines) {
    const mrzResult = parseTD1(mrzLines);
    if (mrzResult && mrzResult.data?.documentInfo?.documentNumber) {
      return {
        data: mrzResult.data,
        raw: mrzResult.text,
        checks: mrzResult.checks,
        format: 'mrz'
      };
    }
  }

  // 3. Intentar como texto OCR del reverso de cédula amarilla
  const yellowResult = parseYellowCardOcr(rawText);
  if (yellowResult) {
    return {
      data: yellowResult.data,
      raw: yellowResult.text,
      checks: yellowResult.checks,
      format: 'ocr_yellow'
    };
  }

  return null;
}

/**
 * Auto-llena cualquier formulario HTML con los datos extraídos de la cédula.
 * Busca campos por atributo `data-cedula`, `name` o `id`.
 *
 * @param {HTMLFormElement|HTMLElement|string} formOrSelector
 * @param {object} data - Objeto `data` retornado por el parser
 * @returns {number} Cantidad de campos rellenados
 */
export function fillFormWithCedula(formOrSelector, data) {
  const root = typeof formOrSelector === 'string'
    ? document.querySelector(formOrSelector)
    : formOrSelector;
  if (!root || !data) return 0;

  const map = {
    documentNumber: data.documentInfo?.documentNumber || '',
    cedula: data.documentInfo?.documentNumber || '',
    documento: data.documentInfo?.documentNumber || '',
    numero_documento: data.documentInfo?.documentNumber || '',
    documentType: data.documentType || 'CC',
    tipo_documento: data.documentType || 'CC',
    firstName: data.firstName || '',
    primer_nombre: data.firstName || '',
    middleName: data.middleName || '',
    segundo_nombre: data.middleName || '',
    nombres: [data.firstName, data.middleName].filter(Boolean).join(' '),
    lastName: data.lastName || '',
    primer_apellido: data.lastName || '',
    secondLastName: data.secondLastName || '',
    segundo_apellido: data.secondLastName || '',
    apellidos: [data.lastName, data.secondLastName].filter(Boolean).join(' '),
    fullName: data.fullName || [data.firstName, data.middleName, data.lastName, data.secondLastName].filter(Boolean).join(' '),
    nombre_completo: data.fullName || [data.firstName, data.middleName, data.lastName, data.secondLastName].filter(Boolean).join(' '),
    birthDate: data.birthDate || '',
    birthDateISO: data.birthDateISO || '',
    fecha_nacimiento: data.birthDateISO || '',
    age: data.age ?? '',
    edad: data.age ?? '',
    gender: data.gender || '',
    sexo: data.gender || '',
    genero: data.gender || '',
    bloodType: data.bloodType || '',
    rh: data.bloodType || '',
    grupo_sanguineo: data.bloodType || '',
    expiryDateISO: data.expiryDateISO || '',
    fecha_vencimiento: data.expiryDateISO || '',
    afisCode: data.documentInfo?.afisCode || '',
    fingerCard: data.documentInfo?.fingerCard || ''
  };

  let count = 0;
  for (const [key, val] of Object.entries(map)) {
    const elements = root.querySelectorAll(
      `[data-cedula="${key}"], [name="${key}"], #${CSS.escape(key)}`
    );
    elements.forEach(el => {
      if ('value' in el) {
        el.value = val;
        el.dispatchEvent(new Event('input', { bubbles: true }));
        el.dispatchEvent(new Event('change', { bubbles: true }));
        count++;
      }
    });
  }
  return count;
}

/**
 * Clase de alto nivel para decodificar imágenes, controlar cámara y escuchar lectores USB.
 */
export class CedulaReader {
  constructor(options = {}) {
    this.onStatus = options.onStatus || (() => {});
    this.onResult = options.onResult || (() => {});
    this.onError = options.onError || (() => {});
    this.zxingReader = null;
    this.ocrWorker = null;
    this.cameraControls = null;
  }

  async _getZxingReader() {
    if (this.zxingReader) return this.zxingReader;
    const [{ BrowserMultiFormatReader }, { DecodeHintType, BarcodeFormat }] = await Promise.all([
      import('https://cdn.jsdelivr.net/npm/@zxing/browser@0.1.5/+esm'),
      import('https://cdn.jsdelivr.net/npm/@zxing/library@0.21.3/+esm')
    ]);
    const hints = new Map();
    hints.set(DecodeHintType.POSSIBLE_FORMATS, [BarcodeFormat.PDF_417]);
    hints.set(DecodeHintType.TRY_HARDER, true);
    hints.set(DecodeHintType.CHARACTER_SET, 'ISO-8859-1');
    this.zxingReader = new BrowserMultiFormatReader(hints);
    return this.zxingReader;
  }

  static loadImage(url) {
    return new Promise((res, rej) => {
      const img = new Image();
      img.onload = () => res(img);
      img.onerror = () => rej(new Error('No se pudo cargar el archivo de imagen.'));
      img.src = url;
    });
  }

  static buildCanvas(img, { maxSide = 1600, cropYStart = 0, cropYEnd = 1, filter = 'none', rotate180 = false } = {}) {
    const sy = Math.round(img.naturalHeight * cropYStart);
    const sh = Math.max(1, Math.round(img.naturalHeight * (cropYEnd - cropYStart)));
    const sw = img.naturalWidth;
    const scale = Math.min(2.2, maxSide / Math.max(sw, sh));
    const c = document.createElement('canvas');
    c.width = Math.max(1, Math.round(sw * scale));
    c.height = Math.max(1, Math.round(sh * scale));
    const ctx = c.getContext('2d');
    if (filter && filter !== 'none') {
      ctx.filter = filter;
    }
    if (rotate180) {
      ctx.translate(c.width / 2, c.height / 2);
      ctx.rotate(Math.PI);
      ctx.drawImage(img, 0, sy, sw, sh, -c.width / 2, -c.height / 2, c.width, c.height);
    } else {
      ctx.drawImage(img, 0, sy, sw, sh, 0, 0, c.width, c.height);
    }
    return c;
  }

  /**
   * Intenta decodificar el código PDF417 probando múltiples escalas, recortes, contrastes y rotación 180°.
   */
  async decodePdf417FromImage(img) {
    const reader = await this._getZxingReader();
    const attempts = [
      // Imagen completa en varias resoluciones
      { maxSide: 2200, cropYStart: 0, cropYEnd: 1, filter: 'none', rotate180: false },
      { maxSide: 1500, cropYStart: 0, cropYEnd: 1, filter: 'none', rotate180: false },
      { maxSide: 1000, cropYStart: 0, cropYEnd: 1, filter: 'none', rotate180: false },
      { maxSide: 700,  cropYStart: 0, cropYEnd: 1, filter: 'none', rotate180: false },
      // Mitad inferior donde suele ubicarse el PDF417
      { maxSide: 1800, cropYStart: 0.35, cropYEnd: 1, filter: 'grayscale(1) contrast(1.5)', rotate180: false },
      { maxSide: 1300, cropYStart: 0.40, cropYEnd: 0.95, filter: 'grayscale(1) contrast(1.8)', rotate180: false },
      // Imagen completa con alto contraste
      { maxSide: 1600, cropYStart: 0, cropYEnd: 1, filter: 'grayscale(1) contrast(1.6)', rotate180: false },
      // Rotada 180° por si la foto fue tomada al revés
      { maxSide: 1600, cropYStart: 0, cropYEnd: 1, filter: 'none', rotate180: true },
      { maxSide: 1600, cropYStart: 0, cropYEnd: 0.65, filter: 'grayscale(1) contrast(1.5)', rotate180: true }
    ];

    for (const cfg of attempts) {
      try {
        const canvas = CedulaReader.buildCanvas(img, cfg);
        const res = reader.decodeFromCanvas(canvas);
        if (res && res.getText()) {
          return res.getText();
        }
      } catch (_) {
        // Continuar al siguiente intento
      }
    }
    return null;
  }

  /**
   * Ejecuta OCR con Tesseract.js sobre la imagen para extraer MRZ TD1 o texto de respaldo.
   */
  async decodeOcrFromImage(img) {
    if (!this.ocrWorker) {
      this.onStatus('Cargando motor OCR (solo la primera vez)…', 'info');
      const { createWorker } = await import('https://cdn.jsdelivr.net/npm/tesseract.js@5.1.1/+esm');
      this.ocrWorker = await createWorker('eng');
    }

    await this.ocrWorker.setParameters({
      tessedit_char_whitelist: 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789<-+ ',
      tessedit_pageseg_mode: '6'
    });

    let bestMrz = null;
    const crops = [
      { cropYStart: 0.55, cropYEnd: 1.0, filter: 'grayscale(1) contrast(1.6)' },
      { cropYStart: 0.65, cropYEnd: 1.0, filter: 'grayscale(1) contrast(1.8)' },
      { cropYStart: 0.40, cropYEnd: 1.0, filter: 'grayscale(1) contrast(1.5)' },
      { cropYStart: 0.0,  cropYEnd: 1.0, filter: 'grayscale(1) contrast(1.5)' }
    ];

    for (const crop of crops) {
      this.onStatus('Analizando caracteres ópticos (MRZ / OCR)…', 'info');
      const canvas = CedulaReader.buildCanvas(img, { maxSide: 1800, ...crop });
      const { data: { text } } = await this.ocrWorker.recognize(canvas);

      // 1. Probar como MRZ TD1 (Cédula Digital / TI Digital)
      const lines = extractMrzLines(text);
      if (lines) {
        const parsed = parseTD1(lines);
        if (parsed && parsed.checks.birth && parsed.checks.document) {
          return { data: parsed.data, raw: parsed.text, checks: parsed.checks, format: 'mrz' };
        }
        bestMrz = bestMrz || parsed;
      }

      // 2. Probar como reverso de Cédula Amarilla clásica (línea P-xxxxxxx + fechas)
      const yellow = parseYellowCardOcr(text);
      if (yellow && yellow.data.documentInfo.documentNumber) {
        return { data: yellow.data, raw: yellow.text, checks: yellow.checks, format: 'ocr_yellow' };
      }
    }

    if (bestMrz) {
      return { data: bestMrz.data, raw: bestMrz.text, checks: bestMrz.checks, format: 'mrz' };
    }

    return null;
  }

  /**
   * Procesa un archivo de imagen (File/Blob) completo: intenta PDF417 y si falla usa OCR MRZ/Amarilla.
   */
  async scanFile(file) {
    const objectUrl = URL.createObjectURL(file);
    try {
      this.onStatus('Procesando imagen y buscando código PDF417…', 'info');
      const img = await CedulaReader.loadImage(objectUrl);

      const rawPdf417 = await this.decodePdf417FromImage(img);
      if (rawPdf417) {
        const data = parseColombianPdf417(rawPdf417);
        const out = {
          data,
          raw: rawPdf417,
          imageBlob: file,
          format: 'pdf417'
        };
        this.onResult(out);
        return out;
      }

      // Respaldo OCR (MRZ o Cédula Amarilla)
      const ocrResult = await this.decodeOcrFromImage(img);
      if (ocrResult) {
        const out = {
          data: ocrResult.data,
          raw: ocrResult.raw,
          checks: ocrResult.checks,
          imageBlob: file,
          format: ocrResult.format
        };
        this.onResult(out);
        return out;
      }

      throw new Error(
        'No se pudo detectar un código PDF417 ni zona MRZ legible. Asegúrate de que la foto esté enfocada, de frente y sin reflejos de luz.'
      );
    } finally {
      URL.revokeObjectURL(objectUrl);
    }
  }
}
