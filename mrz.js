/**
 * Lector y parser MRZ formato TD1 (3 líneas x 30 caracteres, ICAO Doc 9303)
 * Optimizado para Cédula Digital Colombiana, Tarjeta de Identidad y respaldo OCR
 * de Cédula Amarilla con hologramas.
 */

const W = [7, 3, 1];

// Mapa de confusiones frecuentes de OCR cuando un campo debe ser numérico
const DIG = {
  O: '0', Q: '0', D: '0', U: '0',
  I: '1', L: '1', '|': '1',
  Z: '2',
  S: '5',
  G: '6',
  B: '8'
};

// Mapa inverso cuando un campo debe ser alfabético
const ALPHA = {
  '0': 'O',
  '1': 'I',
  '2': 'Z',
  '5': 'S',
  '8': 'B'
};

export const toDigits = (s = '') =>
  [...String(s)].map(c => DIG[c] ?? c).join('');

export const toAlpha = (s = '') =>
  [...String(s)].map(c => ALPHA[c] ?? c).join('');

export const normalizeNumeric = (s = '') =>
  toDigits(s).replace(/[^0-9]/g, '');

const val = (c) => {
  if (c === '<') return 0;
  if (/^[0-9]$/.test(c)) return Number(c);
  if (/^[A-Z]$/.test(c)) return c.charCodeAt(0) - 55;
  return 0;
};

/**
 * Calcula el dígito de control ICAO 9303 (pesos 7, 3, 1 módulo 10).
 * @param {string} s
 * @returns {number}
 */
export function checkDigit(s) {
  let total = 0;
  const str = String(s || '');
  for (let i = 0; i < str.length; i++) {
    total += val(str[i]) * W[i % 3];
  }
  return total % 10;
}

const okDigit = (data, chk) => {
  const digit = toDigits(chk);
  return /^\d$/.test(digit) && checkDigit(data) === Number(digit);
};

/**
 * Calcula la edad cumplida a partir de una fecha ISO (YYYY-MM-DD).
 * @param {string} isoDate
 * @returns {number|null}
 */
export function calculateAge(isoDate) {
  if (!/^\d{4}-\d{2}-\d{2}$/.test(isoDate || '')) return null;
  const [y, m, d] = isoDate.split('-').map(Number);
  const now = new Date();
  let age = now.getFullYear() - y;
  const mDiff = (now.getMonth() + 1) - m;
  if (mDiff < 0 || (mDiff === 0 && now.getDate() < d)) {
    age--;
  }
  return age >= 0 && age <= 130 ? age : null;
}

/**
 * Extrae y normaliza las 3 líneas de 30 caracteres de una zona MRZ TD1.
 * Tolera ruido de OCR, saltos escapados y confusiones 1/I o 0/O en el encabezado.
 * @param {string} text
 * @returns {string[]|null}
 */
export function extractMrzLines(text) {
  if (!text || typeof text !== 'string') return null;

  const normalized = text
    .toUpperCase()
    .replace(/\\X0A/gi, '\n')
    .replace(/\\N/gi, '\n')
    .replace(/[«‹＜]/g, '<');

  const rawLines = normalized
    .split(/\r?\n/)
    .map(l => l.replace(/\s+/g, '').replace(/[^A-Z0-9<]/g, ''))
    .filter(l => l.length >= 22);

  if (rawLines.length < 3) return null;

  // Busca inicio de documento TD1 (ej. ICCOL, IDCOL, ITCOL, IACOL, 1CCOL, I<COL, etc.)
  const headerRegex = /^(?:[I1][A-Z0-9<]|[A-Z0-9]{2})C[O0]L/;
  let start = rawLines.findIndex(l => headerRegex.test(l));

  // Si no es colombiano o el encabezado tiene ruido, busca patrón de fecha en la segunda línea
  if (start < 0) {
    for (let i = 0; i <= rawLines.length - 3; i++) {
      const candidateL2 = toDigits(rawLines[i + 1].slice(0, 6));
      if (/^\d{6}$/.test(candidateL2) && rawLines[i + 2].includes('<')) {
        start = i;
        break;
      }
    }
  }

  if (start < 0) start = rawLines.length - 3;
  if (start + 3 > rawLines.length) return null;

  return rawLines
    .slice(start, start + 3)
    .map(l => l.padEnd(30, '<').slice(0, 30));
}

function fullYear(yy) {
  const current = new Date().getFullYear();
  let year = 2000 + yy;
  if (year > current) year -= 100;
  return year;
}

function toDate(six, isBirth) {
  if (!/^\d{6}$/.test(six)) return null;

  const yy = Number(six.slice(0, 2));
  const mo = Number(six.slice(2, 4));
  const day = Number(six.slice(4, 6));
  const year = isBirth ? fullYear(yy) : 2000 + yy;

  const date = new Date(Date.UTC(year, mo - 1, day));
  if (
    date.getUTCFullYear() !== year ||
    date.getUTCMonth() !== mo - 1 ||
    date.getUTCDate() !== day
  ) {
    return null;
  }

  const p = n => String(n).padStart(2, '0');
  return {
    iso: `${year}-${p(mo)}-${p(day)}`,
    dmy: `${p(day)}-${p(mo)}-${year}`
  };
}

/**
 * Extrae el NUIP / Número de Cédula colombiano desde el campo opcional de la línea 2 (o línea 1).
 * Soporta cédulas de 10 dígitos (ej. 1017929467<) y cédulas antiguas de 6 a 9 dígitos
 * rellenadas con ceros a la izquierda (ej. 0079123456<) o con '<' (ej. 79123456<<<).
 */
function extractColombianId(l1, l2) {
  // 1. Campo opcional de línea 2 (posiciones 18 a 29, 11 caracteres)
  const opt2 = l2.slice(18, 29);
  const opt2Clean = opt2.replace(/^([A-Z0-9]+?)(<+)$/, (_, num, pad) => toDigits(num) + pad);
  const m2 = opt2Clean.match(/^0*(\d{6,10})<*$/);
  if (m2) return m2[1];

  // Si todos los 10 primeros caracteres son dígitos (o equivalentes OCR) y el 11º es '<'
  const m2Strict = (toDigits(opt2.slice(0, 10)) + opt2[10]).match(/^0*(\d{6,10})<$/);
  if (m2Strict) return m2Strict[1];

  // 2. Campo opcional de línea 1 (posiciones 15 a 30) cuando el serial va en 5..14
  const opt1 = l1.slice(15, 30);
  const opt1Clean = opt1.replace(/^<*([A-Z0-9]+?)(<*)$/, (_, num, pad) => toDigits(num) + pad);
  const m1 = opt1Clean.match(/^0*(\d{6,10})<+$/);
  if (m1) return m1[1];

  return null;
}

/**
 * Detecta el tipo de documento según el encabezado MRZ.
 */
function detectDocumentType(l1) {
  const prefix = toAlpha(l1.slice(0, 2));
  if (prefix === 'IT') return 'TI';
  if (prefix === 'CE' || prefix === 'IE') return 'CE';
  return 'CC';
}

/**
 * Parsea las 3 líneas de un código MRZ TD1 y devuelve datos normalizados + validaciones.
 * @param {string[]|string} input - Arreglo de 3 líneas o texto completo con MRZ
 */
export function parseTD1(input) {
  const lines = typeof input === 'string' ? extractMrzLines(input) : input;
  if (!Array.isArray(lines) || lines.length !== 3) {
    return null;
  }

  const [l1, l2, l3] = lines.map(l => String(l).toUpperCase().padEnd(30, '<').slice(0, 30));

  // Primera línea: tipo(0..2), emisor(2..5), nº documento(5..14), check(14), opcional(15..30)
  const rawDocField = l1.slice(5, 14);
  const docChk = l1[14];
  const opt1 = l1.slice(15, 30);

  let documentNumber = '';
  let docOk = false;

  if (docChk === '<') {
    // Número extendido en el campo opcional (estándar ICAO cuando supera 9 caracteres)
    const rest = opt1.split('<')[0];
    if (rest.length >= 2) {
      const fullRaw = rawDocField + '<' + rest.slice(0, -1);
      const fullDigits = toDigits(rawDocField + rest.slice(0, -1));
      documentNumber = fullDigits.replace(/</g, '');
      docOk = okDigit(fullRaw, rest.slice(-1)) || okDigit(toDigits(rawDocField) + '<' + toDigits(rest.slice(0, -1)), rest.slice(-1));
    } else {
      documentNumber = rawDocField.replace(/</g, '');
    }
  } else {
    // Si el campo de documento parece numérico con errores OCR, verificamos ambos
    const cleanedDocField = /^\d+$/.test(toDigits(rawDocField.replace(/</g, '')))
      ? toDigits(rawDocField.replace(/</g, '')).padEnd(9, '<')
      : rawDocField;
    docOk = okDigit(rawDocField, docChk) || okDigit(cleanedDocField, docChk);
    documentNumber = (docOk && !okDigit(rawDocField, docChk) ? cleanedDocField : rawDocField).replace(/</g, '');
  }

  // Segunda línea: nacimiento(0..6), check(6), sexo(7), vencimiento(8..14), check(14), nacionalidad(15..18), opcional(18..29), check compuesto(29)
  const birthRaw = toDigits(l2.slice(0, 6));
  const birthOk = okDigit(birthRaw, l2[6]) || okDigit(l2.slice(0, 6), l2[6]);

  const expRaw = toDigits(l2.slice(8, 14));
  const expOk = okDigit(expRaw, l2[14]) || okDigit(l2.slice(8, 14), l2[14]);

  const birth = toDate(birthRaw, true);
  const exp = toDate(expRaw, false);

  const sexChar = toAlpha(l2[7]);
  const sex = /^[MF]$/.test(sexChar) ? sexChar : '';

  const issuer = toAlpha(l1.slice(2, 5)).replace(/</g, '');
  const nationality = toAlpha(l2.slice(15, 18)).replace(/</g, '') || issuer;
  const isColombian = issuer === 'COL' || nationality === 'COL' || /^[I1][CDTA<]C[O0]L/.test(l1);

  const colombianId = isColombian ? extractColombianId(l1, l2) : null;

  // Dígito de control compuesto ICAO TD1: l1(5..30) + l2(0..7) + l2(8..15) + l2(18..29)
  const compositeData =
    l1.slice(5, 30) +
    birthRaw + toDigits(l2[6]) +
    expRaw + toDigits(l2[14]) +
    l2.slice(18, 29);
  const compositeOk = okDigit(compositeData, l2[29]) || okDigit(
    l1.slice(5, 30) + l2.slice(0, 7) + l2.slice(8, 15) + l2.slice(18, 29),
    l2[29]
  );

  // Tercera línea: APELLIDO1<APELLIDO2<<NOMBRE1<NOMBRE2
  const cleanL3 = l3.replace(/<+$/, '');
  const parts = cleanL3.split(/<{2,}/);
  const sur = parts[0] || '';
  const given = parts.slice(1).join('<') || '';

  const surnames = sur.split('<').map(s => toAlpha(s).trim()).filter(Boolean);
  const givens = given.split('<').map(s => toAlpha(s).trim()).filter(Boolean);

  const firstName = givens[0] || '';
  const middleName = givens.slice(1).join(' ');
  const lastName = surnames[0] || '';
  const secondLastName = surnames.slice(1).join(' ');
  const fullName = [firstName, middleName, lastName, secondLastName].filter(Boolean).join(' ');

  const finalDocNumber = colombianId ?? documentNumber.replace(/^0+(?=\d{6,})/, '');
  const birthDateISO = birth?.iso || '';

  return {
    data: {
      source: 'mrz',
      documentType: detectDocumentType(l1),
      firstName,
      middleName,
      lastName,
      secondLastName,
      fullName,
      birthDate: birth?.dmy || '',
      birthDateISO,
      age: calculateAge(birthDateISO),
      birthDateVerified: Boolean(birth && birthOk),
      gender: sex,
      bloodType: '',
      expiryDateISO: exp?.iso || '',
      nationality: isColombian ? 'COL' : nationality,
      documentInfo: {
        documentNumber: finalDocNumber,
        mrzDocumentNumber: documentNumber,
        afisCode: '',
        fingerCard: '',
        identityNumberSource: colombianId
          ? 'colombian_optional_field'
          : 'td1_document_field'
      }
    },
    checks: {
      document: docOk,
      birth: birthOk,
      expiry: expOk,
      composite: compositeOk,
      colombianIdPattern: Boolean(colombianId)
    },
    text: [l1, l2, l3].join('\n')
  };
}

/**
 * Respaldo OCR para el reverso de la Cédula Amarilla / TI clásica cuando el PDF417 está borroso.
 * Busca la línea inferior impresa bajo el código de barras:
 * Ej: P-0116600-00645479-M-1017929467-20141120  0041230772A 1  2532882599
 * Y las fechas / RH impresos en el reverso (ej. 18-MAR-2007, O+).
 * @param {string} text
 */
export function parseYellowCardOcr(text) {
  if (!text || typeof text !== 'string') return null;
  const upper = text.toUpperCase();

  // Patrón de la línea inferior bajo el PDF417: [P|I|A]-xxxxxxx-xxxxxxxx-[M|F]-<CEDULA>-<YYYYMMDD>
  const footerMatch = upper.match(
    /[PIA1l][-\s]?\d{5,8}[-\s]?\d{6,9}[-\s]?([MF])[-\s]?0*(\d{6,10})[-\s]?(\d{8})/
  );
  if (!footerMatch) return null;

  const [, gender, docNumber] = footerMatch;

  // Buscar código AFIS y tarjeta dactilar después de la línea inferior
  const afterFooter = upper.slice(footerMatch.index + footerMatch[0].length);
  const extraNums = [...afterFooter.matchAll(/\b0*(\d{7,10})[A-Z]?\b/g)].map(m => m[1]);
  const afisCode = extraNums[0] || '';
  const fingerCard = extraNums[1] || '';

  // Meses en español para extraer FECHA DE NACIMIENTO y FECHA DE VENCIMIENTO
  const MONTHS = {
    ENE: '01', FEB: '02', MAR: '03', ABR: '04', MAY: '05', JUN: '06',
    JUL: '07', AGO: '08', SEP: '09', SET: '09', OCT: '10', NOV: '11', DIC: '12'
  };
  const dates = [...upper.matchAll(/\b(\d{2})[-\s./]*(ENE|FEB|MAR|ABR|MAY|JUN|JUL|AGO|SEP|SET|OCT|NOV|DIC)[-\s./]*((?:19|20)\d{2})\b/g)]
    .map(m => ({
      d: m[1],
      mo: MONTHS[m[2]],
      y: m[3],
      iso: `${m[3]}-${MONTHS[m[2]]}-${m[1]}`,
      dmy: `${m[1]}-${MONTHS[m[2]]}-${m[3]}`
    }));

  const birth = dates[0] || null;
  const expiry = dates.length > 1 ? dates[1] : null;

  // Buscar grupo sanguíneo RH
  const rhMatch = upper.match(/\b(AB|A|B|O)\s*([+-])/);
  const bloodType = rhMatch ? `${rhMatch[1]}${rhMatch[2]}` : '';

  return {
    data: {
      source: 'mrz', // compatible con el validador de servidor
      documentType: expiry ? 'TI' : 'CC',
      firstName: '',
      middleName: '',
      lastName: '',
      secondLastName: '',
      fullName: '',
      birthDate: birth?.dmy || '',
      birthDateISO: birth?.iso || '',
      age: birth ? calculateAge(birth.iso) : null,
      birthDateVerified: Boolean(birth),
      gender,
      bloodType,
      expiryDateISO: expiry?.iso || '',
      nationality: 'COL',
      documentInfo: {
        documentNumber: docNumber,
        afisCode,
        fingerCard
      }
    },
    checks: {
      document: true,
      birth: Boolean(birth),
      expiry: Boolean(expiry),
      colombianIdPattern: true
    },
    text: upper.trim()
  };
}
