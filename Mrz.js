// Lector de MRZ formato TD1 (3 líneas x 30 caracteres, ICAO 9303) para cédulas/TI digitales.
const W = [7, 3, 1];
const val = (c) => (c === '<' ? 0 : /\d/.test(c) ? +c : c.charCodeAt(0) - 55);
export function checkDigit(s) {
  let t = 0;
  for (let i = 0; i < s.length; i++) t += val(s[i]) * W[i % 3];
  return t % 10;
}
// Confusiones típicas del OCR en campos que deben ser numéricos
const DIG = { O: '0', Q: '0', D: '0', I: '1', L: '1', Z: '2', S: '5', G: '6', B: '8' };
const toDigits = (s) => [...s].map((c) => DIG[c] ?? c).join('');
const okDigit = (data, chk) => /^\d$/.test(toDigits(chk)) && checkDigit(toDigits(data)) === +toDigits(chk);

// Del texto OCR devuelve las 3 líneas del MRZ (30 chars c/u) o null.
export function extractMrzLines(text) {
  const lines = text.toUpperCase().split(/\r?\n/)
    .map((l) => l.replace(/[^A-Z0-9<]/g, ''))
    .filter((l) => l.length >= 24 && l.includes('<'));
  if (lines.length < 3) return null;
  let start = lines.findIndex((l) => /^I[A-Z<]COL/.test(l));
  if (start < 0 || start + 3 > lines.length) start = lines.length - 3;
  return lines.slice(start, start + 3).map((l) => l.padEnd(30, '<').slice(0, 30));
}

function fullYear(yy) {
  return (yy > new Date().getFullYear() % 100 ? 1900 : 2000) + yy;
}
function toDate(six, birth) {
  if (!/^\d{6}$/.test(six)) return null;
  const yy = +six.slice(0, 2), mo = +six.slice(2, 4), d = +six.slice(4, 6);
  if (mo < 1 || mo > 12 || d < 1 || d > 31) return null;
  const y = birth ? fullYear(yy) : 2000 + yy;
  const p = (n) => String(n).padStart(2, '0');
  return { iso: `${y}-${p(mo)}-${p(d)}`, dmy: `${p(d)}-${p(mo)}-${y}` };
}

export function parseTD1([l1, l2, l3]) {
  // Línea 1: tipo(2) país(3) nº documento(9) dígito(1) opcional(15)
  const docField = l1.slice(5, 14), docChk = l1[14], opt = l1.slice(15);
  let documentNumber, docOk;
  if (docChk === '<') { // número de más de 9 caracteres: continúa en el campo opcional
    const rest = opt.split('<')[0];
    const full = docField + rest.slice(0, -1);
    documentNumber = full.replace(/</g, '');
    docOk = okDigit(full, rest.slice(-1));
  } else {
    documentNumber = docField.replace(/</g, '');
    docOk = okDigit(docField, docChk);
  }
  documentNumber = toDigits(documentNumber).replace(/^0+/, '');

  // Línea 2: nacimiento(6) dígito sexo(1) vencimiento(6) dígito nacionalidad(3) opcional(11) compuesto(1)
  const birthRaw = toDigits(l2.slice(0, 6));
  const birthOk = okDigit(l2.slice(0, 6), l2[6]);
  const expRaw = toDigits(l2.slice(8, 14));
  const expOk = okDigit(l2.slice(8, 14), l2[14]);
  const birth = toDate(birthRaw, true), exp = toDate(expRaw, false);
  const sex = /^[MF]$/.test(l2[7]) ? l2[7] : '';

  // Línea 3: APELLIDO1<APELLIDO2<<NOMBRE1<NOMBRE2
  const [sur = '', given = ''] = l3.replace(/<+$/, '').split('<<');
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
      documentInfo: { documentNumber },
    },
    checks: { document: docOk, birth: birthOk, expiry: expOk },
    text: [l1, l2, l3].join('\n'),
  };
}