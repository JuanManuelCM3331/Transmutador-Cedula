<?php
declare(strict_types=1);

namespace CedulaApi;

use DateTimeImmutable;
use PDO;

/**
 * CedulaParser & Storage Engine
 * Clase independiente y reutilizable en cualquier proyecto PHP (Laravel, Symfony, WordPress o PHP nativo).
 * Permite decodificar cadenas crudas de PDF417 y MRZ TD1 de documentos colombianos en el servidor,
 * así como validar, sanitizar y almacenar registros en JSON o SQLite.
 */
class CedulaParser
{
    private const WEIGHTS = [7, 3, 1];

    private const OCR_DIGITS = [
        'O' => '0', 'Q' => '0', 'D' => '0', 'U' => '0',
        'I' => '1', 'L' => '1', '|' => '1',
        'Z' => '2', 'S' => '5', 'G' => '6', 'B' => '8',
    ];

    private const OCR_ALPHA = [
        '0' => 'O', '1' => 'I', '2' => 'Z', '5' => 'S', '8' => 'B',
    ];

    /**
     * Convierte secuencias escapadas como \x00 a su byte real.
     */
    public static function unescapeControlChars(string $raw): string
    {
        return (string)preg_replace_callback('/\\\\x([0-9a-fA-F]{2})/', static function (array $m): string {
            return chr((int)hexdec($m[1]));
        }, $raw);
    }

    /**
     * Calcula la edad cumplida a partir de una fecha ISO (YYYY-MM-DD).
     */
    public static function calculateAge(string $isoDate): ?int
    {
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $isoDate, $m)) {
            return null;
        }
        try {
            $birth = new DateTimeImmutable($isoDate);
            $now = new DateTimeImmutable('today');
            $age = $birth->diff($now)->y;
            return ($birth <= $now && $age <= 130) ? $age : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Calcula el dígito de control ICAO 9303 (módulo 10 con pesos 7, 3, 1).
     */
    public static function checkDigit(string $str): int
    {
        $total = 0;
        $len = strlen($str);
        for ($i = 0; $i < $len; $i++) {
            $c = $str[$i];
            if ($c === '<') {
                $v = 0;
            } elseif ($c >= '0' && $c <= '9') {
                $v = (int)$c;
            } elseif ($c >= 'A' && $c <= 'Z') {
                $v = ord($c) - 55;
            } else {
                $v = 0;
            }
            $total += $v * self::WEIGHTS[$i % 3];
        }
        return $total % 10;
    }

    /**
     * Limpia un campo de nombre extraído del PDF417 (maneja ISO-8859-1 / UTF-8 y Ñ).
     */
    private static function cleanNameField(string $str): string
    {
        // Convertir byte Latin-1 0xD1 / 0xF1 (Ñ/ñ) si no es UTF-8 válido
        if (!mb_check_encoding($str, 'UTF-8')) {
            $str = mb_convert_encoding($str, 'UTF-8', 'ISO-8859-1');
        }
        $str = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $str) ?? $str;
        $str = preg_replace('/[^\p{L}\s\'-]/u', ' ', $str) ?? $str;
        $str = trim((string)preg_replace('/\s+/u', ' ', $str));
        return mb_strtoupper($str, 'UTF-8');
    }

    /**
     * Parsea una cadena cruda leída del código PDF417 de una cédula o TI colombiana.
     *
     * @return array<string, mixed>|null
     */
    public static function parsePdf417(string $rawInput): ?array
    {
        $raw = self::unescapeControlChars($rawInput);
        if ($raw === '') {
            return null;
        }

        $hasPub = preg_match('/PubDSK_\d/i', $raw, $pubMatch, PREG_OFFSET_CAPTURE) === 1;
        $dateRhPattern = '/0?([MF])((?:19|20)\d{2})(0[1-9]|1[0-2])(0[1-9]|[12]\d|3[01])(\d{2})?(\d{3})?\s*(AB|A|B|O)([+-])/';
        $searchOffset = $hasPub ? (int)$pubMatch[0][1] : 0;
        $hasAnchor = preg_match($dateRhPattern, substr($raw, $searchOffset), $anchorMatch, PREG_OFFSET_CAPTURE) === 1;

        if (!$hasPub && !$hasAnchor) {
            return null;
        }

        $afterPubIdx = $hasPub ? ((int)$pubMatch[0][1] + strlen($pubMatch[0][0])) : 0;
        $tailAfterPub = substr($raw, $afterPubIdx);

        $documentNumber = '';
        $afisCode = '';
        $fingerCard = '';
        $lastName = '';
        $secondLastName = '';
        $firstName = '';
        $middleName = '';
        $gender = '';
        $birthDate = '';
        $birthDateISO = '';
        $birthDateVerified = false;
        $departmentCode = '';
        $municipalityCode = '';
        $bloodType = '';
        $documentType = (str_starts_with($raw, 'I') || str_starts_with($raw, '01')) ? 'TI' : 'CC';

        $nameStartIdx = -1;
        if (preg_match('/^[\x00\s]*(\d{10,22})([A-Za-z\xC0-\xFF])/s', $tailAfterPub, $numMatch, PREG_OFFSET_CAPTURE)) {
            $digitsBlock = $numMatch[1][0];
            if (strlen($digitsBlock) >= 10) {
                $rawDocNum = substr($digitsBlock, -10);
                $rawAfis = substr($digitsBlock, 0, -10);
                $documentNumber = (string)preg_replace('/^0+(?=\d{4,})/', '', $rawDocNum);
                $afisCode = ltrim($rawAfis, '0');
            } else {
                $documentNumber = ltrim($digitsBlock, '0');
            }
            $nameStartIdx = $afterPubIdx + (int)$numMatch[2][1];
        } elseif (strlen($raw) >= 168 && $hasPub) {
            $base = (int)$pubMatch[0][1] - 24;
            $afisCode = ltrim((string)preg_replace('/\D+/', '', substr($raw, max(0, 2 + $base), 8)), '0');
            $rawDoc = (string)preg_replace('/\D+/', '', substr($raw, 48 + $base, 10));
            $documentNumber = (string)preg_replace('/^0+(?=\d{4,})/', '', $rawDoc);
            $nameStartIdx = 58 + $base;
        }

        $searchFrom = $nameStartIdx >= 0 ? $nameStartIdx : $afterPubIdx;
        $nameEndIdx = -1;

        if (preg_match($dateRhPattern, substr($raw, $searchFrom), $m, PREG_OFFSET_CAPTURE)) {
            $gender = $m[1][0];
            $y = (int)$m[2][0];
            $mo = (int)$m[3][0];
            $d = (int)$m[4][0];
            $dept = $m[5][0] ?? '';
            $muni = $m[6][0] ?? '';
            $rhGroup = $m[7][0];
            $rhSign = $m[8][0];

            if (checkdate($mo, $d, $y) && $y >= 1900 && $y <= (int)date('Y')) {
                $birthDate = sprintf('%02d-%02d-%04d', $d, $mo, $y);
                $birthDateISO = sprintf('%04d-%02d-%02d', $y, $mo, $d);
                $birthDateVerified = true;
            }

            $departmentCode = $dept;
            $municipalityCode = $muni;
            $bloodType = $rhGroup . $rhSign;
            $nameEndIdx = $searchFrom + (int)$m[0][1];

            $afterRh = substr($raw, $searchFrom + (int)$m[0][1] + strlen($m[0][0]));
            if (preg_match('/(\d{7,10})/', $afterRh, $fMatch)) {
                $fingerCard = $fMatch[1];
            }
        }

        if ($nameStartIdx >= 0) {
            $end = $nameEndIdx > $nameStartIdx ? $nameEndIdx : ($nameStartIdx + 92);
            $nameBlock = substr($raw, $nameStartIdx, $end - $nameStartIdx);
            $blockLen = strlen($nameBlock);

            if ($blockLen >= 88 && $blockLen <= 96 && str_contains($nameBlock, "\x00")) {
                $lastName       = self::cleanNameField(substr($nameBlock, 0, 23));
                $secondLastName = self::cleanNameField(substr($nameBlock, 23, 23));
                $firstName      = self::cleanNameField(substr($nameBlock, 46, 23));
                $middleName     = self::cleanNameField(substr($nameBlock, 69, 23));
            } elseif (preg_match('/[\x00]+|\s{2,}/', $nameBlock)) {
                $parts = preg_split('/[\x00]+|\s{2,}/', $nameBlock) ?: [];
                $segments = array_values(array_filter(array_map([self::class, 'cleanNameField'], $parts)));
                $lastName       = $segments[0] ?? '';
                $secondLastName = $segments[1] ?? '';
                $firstName      = $segments[2] ?? '';
                $middleName     = implode(' ', array_slice($segments, 3));
            } else {
                $words = array_values(array_filter(explode(' ', self::cleanNameField($nameBlock))));
                $count = count($words);
                if ($count >= 4) {
                    $lastName       = $words[0];
                    $secondLastName = $words[1];
                    $firstName      = $words[2];
                    $middleName     = implode(' ', array_slice($words, 3));
                } elseif ($count === 3) {
                    $lastName       = $words[0];
                    $secondLastName = $words[1];
                    $firstName      = $words[2];
                } elseif ($count === 2) {
                    $lastName       = $words[0];
                    $firstName      = $words[1];
                } elseif ($count === 1) {
                    $lastName       = $words[0];
                }
            }
        }

        if (!preg_match('/^\d{4,12}$/', $documentNumber)) {
            return null;
        }

        $age = self::calculateAge($birthDateISO);
        if ($age !== null && $age < 18) {
            $documentType = 'TI';
        }

        $fullName = implode(' ', array_filter([$firstName, $middleName, $lastName, $secondLastName]));

        return [
            'source'            => 'pdf417',
            'documentType'      => $documentType,
            'firstName'         => $firstName,
            'middleName'        => $middleName,
            'lastName'          => $lastName,
            'secondLastName'    => $secondLastName,
            'fullName'          => $fullName,
            'birthDate'         => $birthDate,
            'birthDateISO'      => $birthDateISO,
            'age'               => $age,
            'birthDateVerified' => $birthDateVerified,
            'gender'            => $gender,
            'bloodType'         => $bloodType,
            'expiryDateISO'     => '',
            'nationality'       => 'COL',
            'documentInfo'      => [
                'documentNumber'   => $documentNumber,
                'afisCode'         => $afisCode,
                'fingerCard'       => $fingerCard,
                'departmentCode'   => $departmentCode,
                'municipalityCode' => $municipalityCode,
            ],
        ];
    }

    private static function toDigits(string $s): string
    {
        return strtr($s, self::OCR_DIGITS);
    }

    private static function toAlpha(string $s): string
    {
        return strtr($s, self::OCR_ALPHA);
    }

    private static function okDigit(string $data, string $chk): bool
    {
        $d = self::toDigits($chk);
        return preg_match('/^\d$/', $d) === 1 && self::checkDigit($data) === (int)$d;
    }

    /**
     * Extrae las 3 líneas de 30 caracteres de una zona MRZ TD1.
     *
     * @return list<string>|null
     */
    public static function extractMrzLines(string $text): ?array
    {
        $normalized = strtoupper($text);
        $normalized = str_ireplace(['\\X0A', '\\N'], "\n", $normalized);
        $normalized = str_replace(['«', '‹', '＜'], '<', $normalized);

        $rawLines = [];
        foreach (preg_split('/\r?\n/', $normalized) ?: [] as $line) {
            $clean = (string)preg_replace('/[^A-Z0-9<]/', '', (string)preg_replace('/\s+/', '', $line));
            if (strlen($clean) >= 22) {
                $rawLines[] = $clean;
            }
        }

        if (count($rawLines) < 3) {
            return null;
        }

        $start = -1;
        foreach ($rawLines as $i => $l) {
            if (preg_match('/^(?:[I1][A-Z0-9<]|[A-Z0-9]{2})C[O0]L/', $l)) {
                $start = $i;
                break;
            }
        }

        if ($start < 0) {
            $start = count($rawLines) - 3;
        }
        if ($start + 3 > count($rawLines)) {
            return null;
        }

        return array_map(
            static fn(string $l): string => substr(str_pad($l, 30, '<'), 0, 30),
            array_slice($rawLines, $start, 3)
        );
    }

    private static function parseSixDate(string $six, bool $isBirth): ?array
    {
        if (!preg_match('/^\d{6}$/', $six)) {
            return null;
        }
        $yy = (int)substr($six, 0, 2);
        $mo = (int)substr($six, 2, 2);
        $d  = (int)substr($six, 4, 2);

        $currentYear = (int)date('Y');
        $year = 2000 + $yy;
        if ($isBirth && $year > $currentYear) {
            $year -= 100;
        }

        if (!checkdate($mo, $d, $year)) {
            return null;
        }

        return [
            'iso' => sprintf('%04d-%02d-%02d', $year, $mo, $d),
            'dmy' => sprintf('%02d-%02d-%04d', $d, $mo, $year),
        ];
    }

    /**
     * Parsea un texto o arreglo de 3 líneas MRZ TD1.
     *
     * @param string|list<string> $input
     * @return array<string, mixed>|null
     */
    public static function parseMrz(string|array $input): ?array
    {
        $lines = is_string($input) ? self::extractMrzLines($input) : $input;
        if (!is_array($lines) || count($lines) !== 3) {
            return null;
        }

        [$l1, $l2, $l3] = array_map(
            static fn(string $l): string => substr(str_pad(strtoupper($l), 30, '<'), 0, 30),
            $lines
        );

        $rawDocField = substr($l1, 5, 9);
        $docChk = $l1[14];
        $opt1 = substr($l1, 15, 15);

        $documentNumber = '';
        $docOk = false;

        if ($docChk === '<') {
            $rest = explode('<', $opt1)[0];
            if (strlen($rest) >= 2) {
                $fullRaw = $rawDocField . '<' . substr($rest, 0, -1);
                $documentNumber = str_replace('<', '', self::toDigits($rawDocField . substr($rest, 0, -1)));
                $docOk = self::okDigit($fullRaw, substr($rest, -1));
            } else {
                $documentNumber = str_replace('<', '', $rawDocField);
            }
        } else {
            $docOk = self::okDigit($rawDocField, $docChk);
            $documentNumber = str_replace('<', '', $rawDocField);
        }

        $birthRaw = self::toDigits(substr($l2, 0, 6));
        $birthOk = self::okDigit($birthRaw, $l2[6]) || self::okDigit(substr($l2, 0, 6), $l2[6]);

        $expRaw = self::toDigits(substr($l2, 8, 6));
        $expOk = self::okDigit($expRaw, $l2[14]) || self::okDigit(substr($l2, 8, 6), $l2[14]);

        $birth = self::parseSixDate($birthRaw, true);
        $exp = self::parseSixDate($expRaw, false);

        $sexChar = self::toAlpha($l2[7]);
        $sex = in_array($sexChar, ['M', 'F'], true) ? $sexChar : '';

        $issuer = str_replace('<', '', self::toAlpha(substr($l1, 2, 3)));
        $nationality = str_replace('<', '', self::toAlpha(substr($l2, 15, 3))) ?: $issuer;
        $isColombian = $issuer === 'COL' || $nationality === 'COL' || preg_match('/^[I1][CDTA<]C[O0]L/', $l1) === 1;

        $colombianId = null;
        if ($isColombian) {
            $opt2 = substr($l2, 18, 11);
            $opt2Digits = self::toDigits(substr($opt2, 0, 10)) . $opt2[10];
            if (preg_match('/^0*(\d{6,10})<$/', $opt2Digits, $m)) {
                $colombianId = $m[1];
            } elseif (preg_match('/^([A-Z0-9]+?)(<+)$/', $opt2, $m)) {
                $numCandidate = self::toDigits($m[1]);
                if (preg_match('/^0*(\d{6,10})$/', $numCandidate, $mNum)) {
                    $colombianId = $mNum[1];
                }
            }
        }

        $cleanL3 = rtrim($l3, '<');
        $parts = preg_split('/<{2,}/', $cleanL3) ?: [];
        $sur = $parts[0] ?? '';
        $given = implode('<', array_slice($parts, 1));

        $surnames = array_values(array_filter(array_map(static fn($s) => trim(self::toAlpha($s)), explode('<', $sur))));
        $givens = array_values(array_filter(array_map(static fn($s) => trim(self::toAlpha($s)), explode('<', $given))));

        $firstName = $givens[0] ?? '';
        $middleName = implode(' ', array_slice($givens, 1));
        $lastName = $surnames[0] ?? '';
        $secondLastName = implode(' ', array_slice($surnames, 1));
        $fullName = implode(' ', array_filter([$firstName, $middleName, $lastName, $secondLastName]));

        $finalDoc = $colombianId ?? (string)preg_replace('/^0+(?=\d{6,})/', '', $documentNumber);
        $birthDateISO = $birth['iso'] ?? '';
        $prefix = self::toAlpha(substr($l1, 0, 2));
        $documentType = $prefix === 'IT' ? 'TI' : (($prefix === 'CE' || $prefix === 'IE') ? 'CE' : 'CC');

        return [
            'source'            => 'mrz',
            'documentType'      => $documentType,
            'firstName'         => $firstName,
            'middleName'        => $middleName,
            'lastName'          => $lastName,
            'secondLastName'    => $secondLastName,
            'fullName'          => $fullName,
            'birthDate'         => $birth['dmy'] ?? '',
            'birthDateISO'      => $birthDateISO,
            'age'               => self::calculateAge($birthDateISO),
            'birthDateVerified' => $birth !== null && $birthOk,
            'gender'            => $sex,
            'bloodType'         => '',
            'expiryDateISO'     => $exp['iso'] ?? '',
            'nationality'       => $isColombian ? 'COL' : $nationality,
            'documentInfo'      => [
                'documentNumber'       => $finalDoc,
                'mrzDocumentNumber'    => $documentNumber,
                'afisCode'             => '',
                'fingerCard'           => '',
                'identityNumberSource' => $colombianId ? 'colombian_optional_field' : 'td1_document_field',
            ],
            'checks' => [
                'document'           => $docOk,
                'birth'              => $birthOk,
                'expiry'             => $expOk,
                'colombianIdPattern' => $colombianId !== null,
            ],
        ];
    }

    /**
     * Detecta automáticamente si la entrada es PDF417 o MRZ TD1 y devuelve los datos estructurados.
     *
     * @return array<string, mixed>|null
     */
    public static function parseAuto(string $rawInput): ?array
    {
        $pdf = self::parsePdf417($rawInput);
        if ($pdf !== null) {
            return $pdf;
        }
        return self::parseMrz($rawInput);
    }

    /**
     * Sanitiza y valida un arreglo de datos de cédula recibido desde un cliente.
     *
     * @param array<string, mixed> $in
     * @return array<string, mixed>
     * @throws \InvalidArgumentException
     */
    public static function validateAndSanitize(array $in): array
    {
        if (!isset($in['documentInfo']) || !is_array($in['documentInfo'])) {
            throw new \InvalidArgumentException('Estructura de datos inválida (falta documentInfo)');
        }

        $clean = static fn(mixed $v, int $max = 60): string =>
            mb_substr((string)preg_replace('/[^\p{L}\p{N} \-+\/]/u', '', (string)$v), 0, $max);

        $docNumber = $clean($in['documentInfo']['documentNumber'] ?? '', 12);
        if (!preg_match('/^\d{4,12}$/', $docNumber)) {
            throw new \InvalidArgumentException('Número de documento inválido (debe tener entre 4 y 12 dígitos)');
        }

        $firstName = $clean($in['firstName'] ?? '');
        $middleName = $clean($in['middleName'] ?? '');
        $lastName = $clean($in['lastName'] ?? '');
        $secondLastName = $clean($in['secondLastName'] ?? '');
        $fullName = implode(' ', array_filter([$firstName, $middleName, $lastName, $secondLastName]));

        $birthDateISO = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($in['birthDateISO'] ?? ''))
            ? (string)$in['birthDateISO']
            : '';

        $expiryDateISO = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($in['expiryDateISO'] ?? ''))
            ? (string)$in['expiryDateISO']
            : '';

        $docTypeRaw = strtoupper($clean($in['documentType'] ?? 'CC', 4));
        $documentType = in_array($docTypeRaw, ['CC', 'TI', 'CE', 'PA'], true) ? $docTypeRaw : 'CC';

        return [
            'documentType'   => $documentType,
            'firstName'      => $firstName,
            'middleName'     => $middleName,
            'lastName'       => $lastName,
            'secondLastName' => $secondLastName,
            'fullName'       => $fullName,
            'birthDate'      => $clean($in['birthDate'] ?? '', 10),
            'birthDateISO'   => $birthDateISO,
            'age'            => self::calculateAge($birthDateISO),
            'bloodType'      => $clean($in['bloodType'] ?? '', 3),
            'gender'         => $clean($in['gender'] ?? '', 1),
            'expiryDateISO'  => $expiryDateISO,
            'nationality'    => $clean($in['nationality'] ?? 'COL', 3) ?: 'COL',
            'source'         => in_array($in['source'] ?? '', ['pdf417', 'mrz'], true) ? $in['source'] : 'pdf417',
            'documentInfo'   => [
                'documentNumber'   => $docNumber,
                'afisCode'         => $clean($in['documentInfo']['afisCode'] ?? '', 12),
                'fingerCard'       => $clean($in['documentInfo']['fingerCard'] ?? '', 12),
                'departmentCode'   => $clean($in['documentInfo']['departmentCode'] ?? '', 4),
                'municipalityCode' => $clean($in['documentInfo']['municipalityCode'] ?? '', 6),
            ],
        ];
    }

    /**
     * Inicializa y retorna la conexión SQLite cuando storage_driver === 'sqlite'.
     */
    public static function getSqliteConnection(string $storageDir): PDO
    {
        if (!is_dir($storageDir)) {
            mkdir($storageDir, 0750, true);
        }
        $pdo = new PDO('sqlite:' . $storageDir . '/cedulas.sqlite');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('
            CREATE TABLE IF NOT EXISTS cedulas (
                id TEXT PRIMARY KEY,
                document_number TEXT NOT NULL,
                document_type TEXT NOT NULL DEFAULT "CC",
                first_name TEXT,
                middle_name TEXT,
                last_name TEXT,
                second_last_name TEXT,
                full_name TEXT,
                birth_date_iso TEXT,
                gender TEXT,
                blood_type TEXT,
                source TEXT,
                image TEXT,
                payload_json TEXT NOT NULL,
                created_at TEXT NOT NULL
            );
            CREATE INDEX IF NOT EXISTS idx_cedulas_doc ON cedulas(document_number);
            CREATE INDEX IF NOT EXISTS idx_cedulas_created ON cedulas(created_at DESC);
        ');
        return $pdo;
    }
}
