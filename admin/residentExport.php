<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit;
}

require_once __DIR__ . '/../includes/check_permissions.php';
require_once __DIR__ . '/../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

// Connection is opened here (rather than down in the try block, where it
// used to live) so the permission check below has a $conn to query against.
// This endpoint exports resident data, so it belongs to the Resident
// Management module — was previously hardcoded to account_role === 'admin'
// only, which blocked any staff account granted manage_residents from
// exporting, even though they can otherwise fully manage residents.
require_once __DIR__ . '/../config/db_connection.php';
$conn->set_charset('utf8mb4');

$role = $_SESSION['account_role'] ?? '';
if ($role !== 'admin') {
    require_permission($conn, 'manage_residents');
}

function exportErrorPage(): void
{
    http_response_code(500);
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Export Failed</title><style>body{font-family:Arial,sans-serif;background:#f8fafc;color:#111827;display:flex;min-height:100vh;align-items:center;justify-content:center;margin:0;padding:24px}.box{max-width:520px;background:#fff;border:1px solid #e5e7eb;border-radius:14px;padding:24px;box-shadow:0 10px 30px rgba(0,0,0,.08)}h1{margin:0 0 8px;font-size:1.25rem}p{margin:0;line-height:1.6;color:#4b5563}</style></head><body><div class="box"><h1>Export unavailable</h1><p>Please try again later.</p></div></body></html>';
}

function formatBirthday(?string $birthday): string
{
    $birthday = trim((string) $birthday);
    if ($birthday === '' || $birthday === '0000-00-00') {
        return '';
    }

    $timestamp = strtotime($birthday);
    if ($timestamp === false) {
        return '';
    }

    return date('Y-m-d', $timestamp);
}

/**
 * Pascal-cases a value to match the template's dropdown lists exactly,
 * e.g. "male" / "MALE" -> "Male", "single" -> "Single", "filipino" -> "Filipino".
 * For multi-word values (e.g. "common law"), each word is capitalized:
 * "common law" -> "Common Law".
 */
function toPascalCase(?string $value): string
{
    $value = trim((string) $value);
    if ($value === '') {
        return '';
    }
    return mb_convert_case(mb_strtolower($value), MB_CASE_TITLE, 'UTF-8');
}

function createFallbackSpreadsheet(): Spreadsheet
{
    $spreadsheet = new Spreadsheet();
    $dataSheet = $spreadsheet->getActiveSheet();
    $dataSheet->setTitle('DATA');
    addInstructionsSheet($spreadsheet);

    $headers = [
        'INHABITANT TYPE',
        'LAST NAME',
        'FIRST NAME',
        'MIDDLE NAME',
        'SUFFIX',
        'BIRTH PLACE',
        'BIRTHDATE (YYYY-MM-DD)',
        'SEX',
        'CIVIL STATUS',
        'CITIZENSHIP',
        'PROFESSION/ OCCUPATION',
        'CONTACT NUMBER',
        'EMAIL ADDRESS',
        'HIGHEST EDUCATIONAL ATTAINMENT',
        "MOTHER'S FIRST NAME",
        "MOTHER'S MIDDLE NAME",
        "MOTHER'S LAST NAME",
    ];

    $column = 'A';
    foreach ($headers as $header) {
        $dataSheet->setCellValueExplicit($column . '1', $header, DataType::TYPE_STRING);
        $column++;
    }

    return $spreadsheet;
}

/**
 * Extends the template's existing dropdown (data validation) formulas
 * beyond their original row range (the template only ships with rows 2-21
 * validated) so every exported row keeps a working dropdown, matching
 * "the right formula" from the original template exactly.
 */
function extendDataValidations(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet, int $lastDataRow): void
{
    $originalLastRow = 21; // template ships with validation on rows 2-21
    if ($lastDataRow <= $originalLastRow) {
        return; // nothing to extend
    }

    $columnsWithValidation = ['A', 'H', 'I', 'J']; // INHABITANT TYPE, SEX, CIVIL STATUS, CITIZENSHIP
    foreach ($columnsWithValidation as $col) {
        $template = $sheet->getDataValidation($col . $originalLastRow);
        if ($template === null || $template->getType() === '') {
            continue;
        }
        for ($r = $originalLastRow + 1; $r <= $lastDataRow; $r++) {
            $sheet->setDataValidation($col . $r, clone $template);
        }
    }
}

try {
    $sql = "
        SELECT
            firstname, lastname, middlename, suffix,
            birthplace, birthday, gender, civil_status, citizenship,
            job_title, employment_status, phone, email
        FROM tbl_userinfo
        WHERE LOWER(userStatus) = 'approved'
          AND account_role_csv LIKE '%resident%'
          AND NOT account_role_csv LIKE '%non-resident%'
        ORDER BY lastname ASC, firstname ASC
    ";

    $result = $conn->query($sql);

  $templatePath = __DIR__ . '/templates/Import-Inhabitant-Template.xlsx';
    if (is_file($templatePath)) {
        $spreadsheet = IOFactory::load($templatePath);
        $dataSheet = $spreadsheet->getSheetByName('DATA');
        if ($dataSheet === null) {
            throw new RuntimeException('DATA sheet not found in template.');
        }
        if ($spreadsheet->getSheetByName('INSTRUCTIONS') === null) {
            addInstructionsSheet($spreadsheet); // safety net if a future template edit drops the tab
        }
    } else {
        $spreadsheet = createFallbackSpreadsheet();
        $dataSheet = $spreadsheet->getSheetByName('DATA');
    }

    $rowNumber = 2;
    while ($user = $result->fetch_assoc()) {
        $profession = trim((string) ($user['job_title'] ?? ''));
        if ($profession === '' || strtoupper($profession) === 'N/A') {
            $profession = (string) ($user['employment_status'] ?? '');
        }

        // NOTE: SEX / CIVIL STATUS / CITIZENSHIP use Pascal Case here
        // ("Male", "Single", "Filipino") to match the template's actual
        // dropdown list values exactly — NOT all-caps.
        $rowValues = [
            'NON-MIGRANT',
            (string) ($user['lastname'] ?? ''),
            (string) ($user['firstname'] ?? ''),
            (string) ($user['middlename'] ?? ''),
            (string) ($user['suffix'] ?? ''),
            (string) ($user['birthplace'] ?? ''),
            formatBirthday($user['birthday'] ?? null),
            toPascalCase($user['gender'] ?? ''),
            toPascalCase($user['civil_status'] ?? ''),
            toPascalCase($user['citizenship'] ?? ''),
            $profession,
            (string) ($user['phone'] ?? ''),
            (string) ($user['email'] ?? ''),
            '',
            '',
            '',
            '',
        ];

        $column = 'A';
        foreach ($rowValues as $value) {
            $dataSheet->setCellValueExplicit($column . $rowNumber, (string) $value, DataType::TYPE_STRING);
            $column++;
        }

        $rowNumber++;
    }

    $lastDataRow = $rowNumber - 1;  
    extendDataValidations($dataSheet, $lastDataRow);
    $instructionsIndex = $spreadsheet->getIndex($spreadsheet->getSheetByName('INSTRUCTIONS'));
    if ($instructionsIndex !== false) {
        $spreadsheet->setActiveSheetIndex($instructionsIndex);
    }
    foreach (range('A', 'Q') as $columnLetter) {
        $dataSheet->getColumnDimension($columnLetter)->setAutoSize(true);
    }

    if (ob_get_length()) {
        ob_end_clean();
    }

    $filename = 'Resident_Export_' . date('Ymd_His') . '.xlsx';

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: max-age=0');
    header('Pragma: public');
    header('Expires: 0');

    $writer = new Xlsx($spreadsheet);
    $writer->save('php://output');

    $conn->close();
    exit;
} catch (Throwable $e) {
    error_log('residentExport.php failed: ' . $e->getMessage());
    echo '<pre>' . htmlspecialchars($e->getMessage() . "\n" . $e->getTraceAsString()) . '</pre>';

}
/**
 * Builds a BIMS-style INSTRUCTIONS sheet matching the reference template
 * (COLUMNS / EXPECTED VALUES/FORMAT / REMARKS table + footer notes).
 * Used both as a safety net when the physical template is missing, and
 * to guarantee the tab exists even if a future template edit drops it.
 */
function addInstructionsSheet(Spreadsheet $spreadsheet): \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet
{
    $sheet = $spreadsheet->createSheet(0);
    $sheet->setTitle('INSTRUCTIONS');

    // Header row
    $sheet->setCellValueExplicit('A1', 'COLUMNS', DataType::TYPE_STRING);
    $sheet->setCellValueExplicit('B1', 'EXPECTED VALUES/ FORMAT', DataType::TYPE_STRING);
    $sheet->setCellValueExplicit('C1', 'REMARKS', DataType::TYPE_STRING);

    $rows = [
        ['INHABITANT TYPE', 'NON-MIGRANT', 'Required Field'],
        [null, 'MIGRANT', null],
        [null, 'TRANSIENT', null],
        ['LAST NAME', null, 'Required Field'],
        ['FIRST NAME', null, 'Required Field'],
        ['MIDDLE NAME', null, 'Optional Field'],
        ['SUFFIX', null, 'Optional Field'],
        ['BIRTH PLACE', null, 'Optional Field'],
        ['BIRTHDATE (YYYY-MM-DD)', '2000-12-30', 'Required Field; Prefered Date Format'],
        [null, 'December 30, 2000', 'Considered valid date format'],
        [null, '12/30/2000 (month/day/year)', 'Considered valid date format'],
        ['SEX', 'MALE', 'Required Field'],
        [null, 'FEMALE', 'Required Field'],
        ['CIVIL STATUS', 'SINGLE', 'Required Field'],
        [null, 'MARRIED', 'Required Field'],
        ['CITIZENSHIP', 'FILIPINO', 'Required Field'],
        [null, 'FOREIGNER', 'Required Field'],
        ['PROFESSION/ OCCUPATION', null, 'Optional Field'],
        ['CONTACT NUMBER', null, 'Optional Field'],
        ['EMAIL ADDRESS', null, 'Optional Field'],
        ['HIGHEST EDUCATIONAL ATTAINMENT', null, 'Optional Field'],
        ["MOTHER'S FIRST NAME", null, 'Optional Field'],
        ["MOTHER'S MIDDLE NAME", null, 'Optional Field'],
        ["MOTHER'S LAST NAME", null, 'Optional Field'],
    ];

    $r = 2;
    foreach ($rows as $row) {
        foreach (['A' => 0, 'B' => 1, 'C' => 2] as $col => $idx) {
            if ($row[$idx] !== null) {
                $sheet->setCellValueExplicit($col . $r, (string) $row[$idx], DataType::TYPE_STRING);
            }
        }
        $r++;
    }

    // Footer notes (leave one blank row, then plain instructions)
    $r++;
    $notes = [
        'Encode inhabitant profiles using the DATA Tab.',
        'Required fields cannot be null.',
        'Please follow the correct date format above.',
        'Maximum Number of Rows: 9,999',
    ];
    foreach ($notes as $note) {
        $sheet->setCellValueExplicit('A' . $r, $note, DataType::TYPE_STRING);
        $r++;
    }

    // Styling — bold header row with fill, bold field-name column, wrapped remarks
    $sheet->getStyle('A1:C1')->applyFromArray([
        'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
        'fill' => ['fillType' => 'solid', 'startColor' => ['rgb' => '15803D']],
    ]);
    $lastFieldRow = $r - count($notes) - 2; // last row of the COLUMNS table
    $sheet->getStyle("A2:A{$lastFieldRow}")->getFont()->setBold(true);
    $sheet->getStyle("A1:C{$lastFieldRow}")->applyFromArray([
        'borders' => ['allBorders' => ['borderStyle' => 'thin', 'color' => ['rgb' => 'D1D5DB']]],
    ]);
    $sheet->getStyle("C2:C{$lastFieldRow}")->getAlignment()->setWrapText(true);

    $sheet->getColumnDimension('A')->setWidth(34);
    $sheet->getColumnDimension('B')->setWidth(30);
    $sheet->getColumnDimension('C')->setWidth(38);
    $sheet->freezePane('A2');

    return $sheet;
}
