
// Lector MRZ TD1 (3 líneas de 30 caracteres)
// Incluye soporte para el formato colombiano del ejemplo.

const W = [7, 3, 1];

const DIG = {
  O: '0', Q: '0', D: '0',
  I: '1', L: '1', Z: '2',
  S: '5', G: '6', B: '8'
};

const toDigits = (s = '') =>
  [...s].map(c => DIG[c] ?? c).join('');

const normalizeNumeric = (s = '') =>
  toDigits(s).replace(/[^0-9]/g, '');

const val = (c) => {
  if (c === '<') return 0;
  if (/^[0-9]$/.test(c)) return Number(c);
  if (/^[A-Z]$/.test(c)) return c.charCodeAt(0) - 55;
  return 0;
};

export function checkDigit(s) {
  let total = 0;
  for (let i = 0; i < s.length; i++) {
    total += val(s[i]) * W[i % 3];
  }
  return total % 10;
}

const okDigit = (data, chk) => {
  const digit = toDigits(chk);
  return /^\d$/.test(digit) &&
    checkDigit(data) === Number(digit);
};

// Extrae las tres líneas de la MRZ.
export function extractMrzLines(text) {
  if (!text) return null;

  const normalized = text
    .toUpperCase()
    .replace(/\\X0A/gi, '\n')
    .replace(/\\N/gi, '\n');

  const lines = normalized
    .split(/\r?\n/)
    .map(l => l.replace(/[^A-Z0-9<]/g, ''))
    .filter(l => l.length >= 24);

  if (lines.length < 3) return null;

  // Acepta encabezados ICAO y el encabezado 1CCOL.
  let start = lines.findIndex(l =>
    /^(?:I[A-Z0-9<]|1C)COL/.test(l)
  );

  if (start < 0) start = lines.length - 3;
  if (start + 3 > lines.length) return null;

  return lines.slice(start, start + 3)
    .map(l => l.padEnd(30, '<').slice(0, 30));
}

function fullYear(yy) {
  const current = new Date().getFullYear();
  let year = 2000 + yy;

  if (year > current) year -= 100;
  return year;
}

function toDate(six, birth) {
  if (!/^\d{6}$/.test(six)) return null;

  const yy = Number(six.slice(0, 2));
  const mo = Number(six.slice(2, 4));
  const day = Number(six.slice(4, 6));
  const year = birth ? fullYear(yy) : 2000 + yy;

  const date = new Date(Date.UTC(year, mo - 1, day));

  if (
    date.getUTCFullYear() !== year ||
    date.getUTCMonth() !== mo - 1 ||
    date.getUTCDate() !== day
  ) return null;

  const p = n => String(n).padStart(2, '0');

  return {
    iso: `${year}-${p(mo)}-${p(day)}`,
    dmy: `${p(day)}-${p(mo)}-${year}`
  };
}

// Extracción específica para el patrón colombiano observado.
// No es una regla ICAO universal ni una validación oficial.
function extractColombianId(l2) {
  // Campo opcional: índices 18 a 28.
  const optional = l2.slice(18, 29);

  // En el ejemplo, 10 dígitos seguidos de '<'.
  const match = toDigits(optional).match(/^(\d{10})<$/);

  return match ? match[1] : null;
}

export function parseTD1(lines) {
  if (!Array.isArray(lines) || lines.length !== 3) {
    return null;
  }

  const [l1, l2, l3] = lines;

  // Primera línea: número de documento MRZ.
  const docField = l1.slice(5, 14);
  const docChk = l1[14];
  const opt = l1.slice(15);

  let documentNumber = '';
  let docOk = false;

  if (docChk === '<') {
    const rest = opt.split('<')[0];

    if (rest.length >= 2) {
      const full = docField + rest.slice(0, -1);
      documentNumber = full.replace(/</g, '');
      docOk = okDigit(full, rest.slice(-1));
    } else {
      documentNumber = docField.replace(/</g, '');
    }
  } else {
    documentNumber = docField.replace(/</g, '');
    docOk = okDigit(docField, docChk);
  }

  // Segunda línea: nacimiento, sexo y vencimiento.
  const birthRaw = toDigits(l2.slice(0, 6));
  const birthOk = okDigit(l2.slice(0, 6), l2[6]);

  const expRaw = toDigits(l2.slice(8, 14));
  const expOk = okDigit(l2.slice(8, 14), l2[14]);

  const birth = toDate(birthRaw, true);
  const exp = toDate(expRaw, false);

  const sex = /^[MF]$/.test(l2[7]) ? l2[7] : '';

  // Número de identidad colombiano.
const colombianId =
  /^1C[C0O]OL|^IC[C0O]OL/.test(l1)
    ? extractColombianId(l2)
    : null;

  // Tercera línea: apellidos y nombres.
  const [sur = '', given = ''] =
    l3.replace(/<+$/, '').split('<<');

  const surnames = sur.split('<').filter(Boolean);
  const givens = given.split('<').filter(Boolean);

  return {
    data: {
      source: 'mrz',
      firstName: givens[0] || '',
      middleName: givens.slice(1).join(' '),
      lastName: surnames[0] || '',
      secondLastName: surnames.slice(1).join(' '),
      birthDate: birth?.dmy || '',
      birthDateISO: birth?.iso || '',
      birthDateVerified: !!birth && birthOk,
      gender: sex,
      bloodType: '',
      expiryDateISO: exp?.iso || '',
      nationality: l2.slice(15, 18).replace(/</g, ''),
      documentInfo: {
        documentNumber: colombianId ?? documentNumber,
        mrzDocumentNumber: documentNumber,
        identityNumberSource: colombianId
          ? 'colombian_optional_field'
          : 'td1_document_field'
      }
    },
    checks: {
      document: docOk,
      birth: birthOk,
      expiry: expOk,
      colombianIdPattern: !!colombianId
    },
    text: lines.join('\n')
  };
}
