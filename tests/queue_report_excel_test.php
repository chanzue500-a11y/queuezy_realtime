<?php
/** Run: C:/xampp/php/php.exe tests/queue_report_excel_test.php */
declare(strict_types=1);

require_once __DIR__ . '/../dist/includes/queue_report.php';
require_once __DIR__ . '/../dist/includes/queue_report_excel.php';

$testCount = 0;
$assertionCount = 0;
$failures = [];

function excelAssertSame($expected, $actual, string $message): void
{
    global $assertionCount;
    $assertionCount++;
    if ($expected !== $actual) {
        throw new RuntimeException($message . '; expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}

function excelAssert(bool $condition, string $message): void
{
    excelAssertSame(true, $condition, $message);
}

function excelTest(string $name, callable $test): void
{
    global $testCount, $failures;
    $testCount++;
    try {
        $test();
        echo "PASS: {$name}\n";
    } catch (Throwable $error) {
        $failures[] = $name . ': ' . $error->getMessage();
        echo "FAIL: {$name}\n";
    }
}

function excelXml(string $xml): DOMXPath
{
    $document = new DOMDocument();
    $previous = libxml_use_internal_errors(true);
    try {
        excelAssert($document->loadXML($xml, LIBXML_NONET), 'Package XML is well formed');
        excelAssertSame(null, $document->doctype, 'Package XML has no document type');
    } finally {
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
    }
    $xpath = new DOMXPath($document);
    $xpath->registerNamespace('s', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
    $xpath->registerNamespace('r', 'http://schemas.openxmlformats.org/officeDocument/2006/relationships');
    $xpath->registerNamespace('p', 'http://schemas.openxmlformats.org/package/2006/relationships');
    $xpath->registerNamespace('c', 'http://schemas.openxmlformats.org/package/2006/content-types');
    return $xpath;
}

function excelPackage(iterable $rows): array
{
    $path = queueReportExcelFile($rows);
    try {
        excelAssert(is_file($path), 'Export returns an existing file');
        $handle = fopen($path, 'rb');
        try {
            excelAssertSame("PK\x03\x04", fread($handle, 4), 'Export is a native ZIP package');
        } finally {
            fclose($handle);
        }
        $archive = new PharData($path);
        $xml = [];
        foreach (['[Content_Types].xml', '_rels/.rels', 'xl/workbook.xml', 'xl/_rels/workbook.xml.rels', 'xl/styles.xml', 'xl/worksheets/sheet1.xml'] as $entry) {
            excelAssert(isset($archive[$entry]), 'Package contains ' . $entry);
            $xml[$entry] = excelXml($archive[$entry]->getContent());
        }
        unset($archive);
        return $xml;
    } finally {
        if (is_file($path)) {
            unlink($path);
        }
    }
}

function excelCell(DOMXPath $sheet, string $address): DOMElement
{
    $cell = $sheet->query('//s:sheetData/s:row/s:c[@r="' . $address . '"]')->item(0);
    if (!$cell instanceof DOMElement) {
        throw new RuntimeException('Missing cell ' . $address);
    }
    return $cell;
}

function excelText(DOMXPath $sheet, string $address): string
{
    $cell = excelCell($sheet, $address);
    excelAssertSame('inlineStr', $cell->getAttribute('t'), $address . ' is stored as text');
    excelAssertSame(0, $sheet->query('s:f', $cell)->length, $address . ' has no executable formula');
    return $sheet->evaluate('string(s:is/s:t)', $cell);
}

function excelNumber(DOMXPath $sheet, string $address): float
{
    $cell = excelCell($sheet, $address);
    excelAssert(in_array($cell->getAttribute('t'), ['', 'n'], true), $address . ' is stored as a number');
    $value = $sheet->evaluate('string(s:v)', $cell);
    excelAssert(is_numeric($value), $address . ' has a numeric value');
    return (float)$value;
}

function excelBlank(DOMXPath $sheet, string $address): void
{
    $cell = $sheet->query('//s:sheetData/s:row/s:c[@r="' . $address . '"]')->item(0);
    excelAssert($cell === null || $sheet->evaluate('string(s:v)', $cell) . $sheet->evaluate('string(s:is/s:t)', $cell) === '', $address . ' is blank');
}

function excelDateSerial(string $date): float
{
    $zone = new DateTimeZone('UTC');
    return ((new DateTimeImmutable($date, $zone))->getTimestamp() - (new DateTimeImmutable('1899-12-30', $zone))->getTimestamp()) / 86400;
}

function excelFormat(DOMXPath $sheet, DOMXPath $styles, string $address): string
{
    $styleIndex = excelCell($sheet, $address)->getAttribute('s');
    excelAssert(ctype_digit($styleIndex), $address . ' has a style');
    $formatId = $styles->evaluate('string(/s:styleSheet/s:cellXfs/s:xf[' . ((int)$styleIndex + 1) . ']/@numFmtId)');
    return $styles->evaluate('string(/s:styleSheet/s:numFmts/s:numFmt[@numFmtId="' . $formatId . '"]/@formatCode)');
}

function excelFixtureRows(): array
{
    return [
        [
            'id' => 99, 'ticket_number' => '=1+1', 'fullname' => '+SUM(1,1)',
            'service_name' => '-2+3', 'dept_name' => '@SUM(A1:A2)', 'status' => 'completed',
            'created_at' => '2026-10-15 19:21:00', 'started_at' => '2026-10-16 10:45:30',
            'completed_at' => '2026-10-16 11:07:00', 'payment_id' => 69,
            'amount' => '700.50', 'payment_status' => 'paid', 'paid_at' => '2026-10-16 11:07:00',
        ],
        [
            'id' => 100, 'ticket_number' => 'Ticket " & < > Ñ 中文',
            'fullname' => '=HYPERLINK("https://example.invalid", "open")',
            'service_name' => "  Service\t&\nName  ", 'dept_name' => 'Literal _x0041_', 'status' => 'waiting',
            'created_at' => '2026-10-22 00:00:00', 'started_at' => null, 'completed_at' => null,
            'payment_id' => 70, 'amount' => '0.00', 'payment_status' => 'pending', 'paid_at' => null,
        ],
        [
            'id' => 101, 'ticket_number' => 'NO-PAYMENT', 'fullname' => null,
            'service_name' => null, 'dept_name' => null, 'status' => 'now_serving',
            'created_at' => '1899-12-29 12:00:00', 'started_at' => null, 'completed_at' => null,
            'payment_id' => null, 'amount' => '1000.00', 'payment_status' => 'pending', 'paid_at' => null,
        ],
    ];
}

excelTest('XLSX package links a workbook, worksheet and styles with the expected columns', static function (): void {
    $package = excelPackage(excelFixtureRows());
    $contentTypes = $package['[Content_Types].xml'];
    excelAssertSame('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml', $contentTypes->evaluate('string(/c:Types/c:Override[@PartName="/xl/workbook.xml"]/@ContentType)'), 'Workbook has the XLSX content type');
    excelAssertSame('application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml', $contentTypes->evaluate('string(/c:Types/c:Override[@PartName="/xl/worksheets/sheet1.xml"]/@ContentType)'), 'Worksheet has the spreadsheet content type');
    excelAssertSame('xl/workbook.xml', $package['_rels/.rels']->evaluate('string(/p:Relationships/p:Relationship[contains(@Type,"/officeDocument")]/@Target)'), 'Package points to workbook');
    $workbook = $package['xl/workbook.xml'];
    excelAssertSame(1, $workbook->query('/s:workbook/s:sheets/s:sheet')->length, 'Workbook has one sheet');
    $sheetId = $workbook->evaluate('string(/s:workbook/s:sheets/s:sheet/@r:id)');
    excelAssertSame('worksheets/sheet1.xml', $package['xl/_rels/workbook.xml.rels']->evaluate('string(/p:Relationships/p:Relationship[@Id="' . $sheetId . '"]/@Target)'), 'Workbook points to worksheet');
    excelAssertSame('styles.xml', $package['xl/_rels/workbook.xml.rels']->evaluate('string(/p:Relationships/p:Relationship[contains(@Type,"/styles")]/@Target)'), 'Workbook points to styles');
    $sheet = $package['xl/worksheets/sheet1.xml'];
    $headers = ['Queue ID', 'Ticket', 'Customer', 'Selected service', 'Department', 'Created at', 'Started at', 'Completed at', 'Queue status', 'Payment ID', 'Amount (PHP)', 'Payment status', 'Paid at', 'Amount source'];
    foreach ($headers as $index => $header) {
        excelAssertSame($header, excelText($sheet, chr(65 + $index) . '1'), 'Column ' . chr(65 + $index) . ' header');
    }
    excelAssertSame(4, $sheet->query('//s:sheetData/s:row')->length, 'Every fixture row is included');
    excelAssertSame(0, $sheet->query('//s:f')->length, 'Worksheet contains no formulas');
});

excelTest('XLSX preserves formula-like text, Unicode, whitespace and payment labels', static function (): void {
    $sheet = excelPackage(excelFixtureRows())['xl/worksheets/sheet1.xml'];
    foreach (['B2' => '=1+1', 'C2' => '+SUM(1,1)', 'D2' => '-2+3', 'E2' => '@SUM(A1:A2)', 'B3' => 'Ticket " & < > Ñ 中文', 'C3' => '=HYPERLINK("https://example.invalid", "open")', 'D3' => "  Service\t&\nName  "] as $address => $expected) {
        excelAssertSame($expected, excelText($sheet, $address), $address . ' retains literal text');
    }
    excelAssertSame('Literal _x005F_x0041_', excelText($sheet, 'E3'), 'Literal OOXML escape text cannot be decoded to a different character by Excel');
    excelAssertSame('Completed', excelText($sheet, 'I2'), 'Completed label');
    excelAssertSame('Now serving', excelText($sheet, 'I4'), 'Now serving label');
    excelAssertSame('Paid', excelText($sheet, 'L2'), 'Paid payment label');
    excelAssertSame('Pending', excelText($sheet, 'L3'), 'Pending payment label');
    excelAssertSame('Recorded payment', excelText($sheet, 'N2'), 'Recorded money is identified');
    excelAssertSame('Current fee estimate', excelText($sheet, 'N4'), 'Unrecorded amount is identified as an estimate');
    excelAssertSame('Unknown customer', excelText($sheet, 'C4'), 'Missing customer label');
    excelAssertSame('Unavailable service', excelText($sheet, 'D4'), 'Missing service label');
    excelAssertSame('Unassigned', excelText($sheet, 'E4'), 'Missing department label');
});

excelTest('XLSX uses numeric amounts and dates, preserves zero and leaves missing values blank', static function (): void {
    $package = excelPackage(excelFixtureRows());
    $sheet = $package['xl/worksheets/sheet1.xml'];
    $styles = $package['xl/styles.xml'];
    excelAssertSame(99.0, excelNumber($sheet, 'A2'), 'Queue ID is numeric');
    excelAssertSame(69.0, excelNumber($sheet, 'J2'), 'Payment ID is numeric');
    excelAssertSame(700.5, excelNumber($sheet, 'K2'), 'Payment amount is numeric');
    excelAssertSame(0.0, excelNumber($sheet, 'K3'), 'Zero amount remains numeric zero');
    excelAssertSame(1000.0, excelNumber($sheet, 'K4'), 'Estimated amount is numeric');
    excelAssertSame('#,##0.00', excelFormat($sheet, $styles, 'K2'), 'Amount displays two decimals');
    foreach (['F2' => '2026-10-15 19:21:00', 'G2' => '2026-10-16 10:45:30', 'H2' => '2026-10-16 11:07:00', 'M2' => '2026-10-16 11:07:00', 'F3' => '2026-10-22 00:00:00'] as $address => $date) {
        excelAssert(abs(excelDateSerial($date) - excelNumber($sheet, $address)) < 0.0000001, $address . ' represents the original date and time');
        excelAssertSame('yyyy-mm-dd hh:mm:ss', excelFormat($sheet, $styles, $address), $address . ' displays a readable date and time');
    }
    foreach (['G3', 'H3', 'M3', 'J4', 'G4', 'H4', 'M4'] as $address) {
        excelBlank($sheet, $address);
    }
    excelAssertSame('1899-12-29 12:00:00', excelText($sheet, 'F4'), 'Pre-1900 date remains readable without an invalid Excel serial');
});

excelTest('empty exports are valid workbooks containing the header only', static function (): void {
    $sheet = excelPackage([])['xl/worksheets/sheet1.xml'];
    excelAssertSame(1, $sheet->query('//s:sheetData/s:row')->length, 'Empty workbook has one header row');
    excelAssertSame(14, $sheet->query('//s:sheetData/s:row[@r="1"]/s:c')->length, 'Empty workbook has all report columns');
    excelAssertSame('Queue ID', excelText($sheet, 'A1'), 'First header remains available');
    excelAssertSame('Amount source', excelText($sheet, 'N1'), 'Last header remains available');
});

excelTest('an iterable failure removes temporary export files and propagates the error', static function (): void {
    $before = glob(sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'queue-report-*') ?: [];
    sort($before);
    $source = (static function (): Generator {
        yield excelFixtureRows()[0];
        throw new RuntimeException('fixture iteration failed');
    })();
    $caught = false;
    try {
        $path = queueReportExcelFile($source);
        if (is_file($path)) {
            unlink($path);
        }
    } catch (RuntimeException $error) {
        $caught = $error->getMessage() === 'fixture iteration failed';
    }
    excelAssert($caught, 'Original iteration error reaches the caller');
    $after = glob(sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'queue-report-*') ?: [];
    sort($after);
    excelAssertSame($before, $after, 'Failed export leaves no new temporary files');
});

foreach ($failures as $failure) {
    fwrite(STDERR, $failure . "\n");
}
echo sprintf("\n%d tests, %d assertions, %d failures.\n", $testCount, $assertionCount, count($failures));
exit($failures ? 1 : 0);
