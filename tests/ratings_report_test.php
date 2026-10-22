<?php
/**
 * Run: C:/xampp/php/php.exe tests/ratings_report_test.php
 * Fixtures live only in connection-local TEMPORARY tables. They never modify
 * the application's stored records or schema.
 */
declare(strict_types=1);

require_once __DIR__ . '/../dist/database/db.php';
require_once __DIR__ . '/../dist/includes/ratings_report.php';

if (!$conn instanceof mysqli) {
    fwrite(STDERR, "Cannot connect to the configured database. Start MySQL or set DB_HOST/DB_USER/DB_PASS/DB_NAME.\n");
    exit(2);
}

$testCount = 0;
$assertionCount = 0;
$failures = [];

function ratingsAssertSame($expected, $actual, string $message): void
{
    global $assertionCount;
    $assertionCount++;
    if ($expected !== $actual) {
        throw new RuntimeException($message . '; expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}

function ratingsAssert(bool $condition, string $message): void
{
    ratingsAssertSame(true, $condition, $message);
}

function ratingsTest(string $name, callable $test): void
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

function ratingsInsert(string $sql, string $types, array $values): void
{
    global $conn;
    $statement = $conn->prepare($sql);
    if (!$statement) {
        throw new RuntimeException($conn->error);
    }
    try {
        $statement->bind_param($types, ...$values);
        if (!$statement->execute()) {
            throw new RuntimeException($statement->error);
        }
    } finally {
        $statement->close();
    }
}

function ratingsRows(array $input = [], ?int $limit = null): array
{
    global $conn;
    return iterator_to_array(ratingsReportRows($conn, ratingsReportFilters($input), $limit), false);
}

function ratingsIds(array $rows): array
{
    return array_map('intval', array_column($rows, 'id'));
}

function ratingsXml(string $xml): DOMXPath
{
    $document = new DOMDocument();
    $previous = libxml_use_internal_errors(true);
    try {
        ratingsAssert($document->loadXML($xml, LIBXML_NONET), 'Workbook XML is well formed');
        ratingsAssertSame(null, $document->doctype, 'Workbook XML has no document type');
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

function ratingsWorkbook(iterable $rows): array
{
    $path = ratingsReportExcelFile($rows);
    try {
        ratingsAssert(is_file($path), 'Export returns an existing workbook file');
        $handle = fopen($path, 'rb');
        try {
            ratingsAssertSame("PK\x03\x04", fread($handle, 4), 'Workbook is a native ZIP package');
        } finally {
            fclose($handle);
        }
        $archive = new PharData($path);
        $parts = [];
        foreach (['[Content_Types].xml', '_rels/.rels', 'xl/workbook.xml', 'xl/_rels/workbook.xml.rels', 'xl/styles.xml', 'xl/worksheets/sheet1.xml'] as $name) {
            ratingsAssert(isset($archive[$name]), 'Workbook contains ' . $name);
            $parts[$name] = ratingsXml($archive[$name]->getContent());
        }
        unset($archive);
        return $parts;
    } finally {
        if (is_file($path)) {
            unlink($path);
        }
    }
}

function ratingsCell(DOMXPath $sheet, string $address): DOMElement
{
    $cell = $sheet->query('//s:sheetData/s:row/s:c[@r="' . $address . '"]')->item(0);
    if (!$cell instanceof DOMElement) {
        throw new RuntimeException('Missing cell ' . $address);
    }
    return $cell;
}

function ratingsText(DOMXPath $sheet, string $address): string
{
    $cell = ratingsCell($sheet, $address);
    ratingsAssertSame('inlineStr', $cell->getAttribute('t'), $address . ' is text');
    ratingsAssertSame(0, $sheet->query('s:f', $cell)->length, $address . ' has no formula');
    return $sheet->evaluate('string(s:is/s:t)', $cell);
}

function ratingsNumber(DOMXPath $sheet, string $address): float
{
    $cell = ratingsCell($sheet, $address);
    ratingsAssert(in_array($cell->getAttribute('t'), ['', 'n'], true), $address . ' is numeric');
    $value = $sheet->evaluate('string(s:v)', $cell);
    ratingsAssert(is_numeric($value), $address . ' contains a number');
    return (float)$value;
}

function ratingsFormat(DOMXPath $sheet, DOMXPath $styles, string $address): string
{
    $index = ratingsCell($sheet, $address)->getAttribute('s');
    ratingsAssert(ctype_digit($index), $address . ' has a style');
    $format = $styles->evaluate('string(/s:styleSheet/s:cellXfs/s:xf[' . ((int)$index + 1) . ']/@numFmtId)');
    return $styles->evaluate('string(/s:styleSheet/s:numFmts/s:numFmt[@numFmtId="' . $format . '"]/@formatCode)');
}

try {
    if (!$conn->set_charset('utf8mb4')) {
        throw new RuntimeException($conn->error);
    }
    $definitions = [
        'queue_ratings' => 'id INT PRIMARY KEY, queue_id INT UNIQUE, user_id INT, rating TINYINT, feedback VARCHAR(500) NULL, created_at DATETIME',
        'queue' => 'id INT PRIMARY KEY, user_id INT, service_id INT, ticket_number VARCHAR(80)',
        'users' => 'id INT PRIMARY KEY, fullname VARCHAR(150)',
        'services' => 'id INT PRIMARY KEY, dept_id INT, service_name VARCHAR(120)',
        'departments' => 'id INT PRIMARY KEY, dept_name VARCHAR(120)',
        'queue_payments' => 'id INT PRIMARY KEY, queue_id INT UNIQUE, amount DECIMAL(10,2), status VARCHAR(20), paid_at DATETIME NULL',
    ];
    foreach ($definitions as $table => $definition) {
        if (!$conn->query("CREATE TEMPORARY TABLE `{$table}` ({$definition}) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci")) {
            throw new RuntimeException('Could not create temporary fixture table: ' . $conn->error);
        }
    }

    foreach ([[1, 'Admissions'], [2, '美容 Department'], [3, 'Registrar']] as $row) {
        ratingsInsert('INSERT INTO departments (id, dept_name) VALUES (?, ?)', 'is', $row);
    }
    foreach ([[1, "Alice O'Brien"], [2, 'Customer 100%_Done!'], [3, 'Ordinary Customer']] as $row) {
        ratingsInsert('INSERT INTO users (id, fullname) VALUES (?, ?)', 'is', $row);
    }
    foreach ([[1, 1, 'Alpha Clearance'], [2, 2, 'Unicode café ✂'], [3, 3, 'Gamma Transcript']] as $row) {
        ratingsInsert('INSERT INTO services (id, dept_id, service_name) VALUES (?, ?, ?)', 'iis', $row);
    }
    $queues = [[1, 1, 1, 'ADM-001'], [2, 2, 1, 'LITERAL_%!'], [3, 3, 2, 'UNI-003'], [4, 1, 2, 'ADM-004'], [5, 3, 3, 'REG-005'], [6, 2, 3, 'REG-006'], [8, 3, 9999, 'ORPHAN-SERVICE']];
    foreach ($queues as $row) {
        ratingsInsert('INSERT INTO queue (id, user_id, service_id, ticket_number) VALUES (?, ?, ?, ?)', 'iiis', $row);
    }
    $ratings = [
        [1, 1, 1, 5, '=SUM(1,2)', '2026-01-01 09:15:30'],
        [2, 2, 2, 4, 'Literal feedback 100%_Done!', '2026-01-02 09:15:30'],
        [3, 3, 3, 3, 'Maayos po – salamat 😊', '2026-01-03 09:15:30'],
        [4, 4, 1, 5, '"Great" & <clean>', '2026-01-04 09:15:30'],
        [5, 5, 3, 1, null, '2026-01-05 09:15:30'],
        [6, 6, 2, 2, 'Needs improvement', '2026-01-06 09:15:30'],
        [7, 7777, 9999, 5, 'Orphaned rating', '2026-01-07 09:15:30'],
        [8, 8, 1, 4, '+cmd|example', '2026-01-08 09:15:30'],
    ];
    foreach ($ratings as $row) {
        ratingsInsert('INSERT INTO queue_ratings (id, queue_id, user_id, rating, feedback, created_at) VALUES (?, ?, ?, ?, ?, ?)', 'iiiiss', $row);
    }
    foreach ([[1, 1, 700, 'paid'], [2, 2, 0, 'paid'], [4, 4, 1234.5, 'paid'], [5, 5, 50, 'pending'], [6, 6, 0, 'pending'], [8, 8, 3.5, 'paid']] as $row) {
        ratingsInsert('INSERT INTO queue_payments (id, queue_id, amount, status) VALUES (?, ?, ?, ?)', 'iids', $row);
    }
    // More than 500 matches establish that an export does not inherit the screen cap.
    for ($id = 10; $id <= 514; $id++) {
        ratingsInsert('INSERT INTO queue (id, user_id, service_id, ticket_number) VALUES (?, ?, ?, ?)', 'iiis', [$id, 3, 3, sprintf('BULK-%03d', $id)]);
        ratingsInsert('INSERT INTO queue_ratings (id, queue_id, user_id, rating, feedback, created_at) VALUES (?, ?, ?, ?, ?, ?)', 'iiiiss', [$id, $id, 3, $id % 5 + 1, 'Bulk feedback', '2026-02-01 10:00:00']);
    }

    ratingsTest('filters normalize star selections and Unicode search', static function (): void {
        $defaults = ratingsReportFilters([]);
        ratingsAssertSame(0, $defaults['rating'], 'Default includes all star ratings');
        ratingsAssertSame('', $defaults['search'], 'Default search is empty');
        ratingsAssertSame([], $defaults['errors'], 'Default filters are valid');
        foreach (['', '0', 0] as $all) {
            ratingsAssertSame([], ratingsReportFilters(['rating' => $all])['errors'], 'All-ratings selection is valid');
        }
        foreach (range(1, 5) as $score) {
            $filters = ratingsReportFilters(['rating' => (string)$score, 'search' => '  café 😊  ']);
            ratingsAssertSame($score, $filters['rating'], 'Selected star count is an integer');
            ratingsAssertSame('café 😊', $filters['search'], 'Unicode search is trimmed');
            ratingsAssertSame([], $filters['errors'], 'Valid filters have no errors');
        }
        $search = ratingsReportFilters(['search' => str_repeat('😊', 140)])['search'];
        ratingsAssertSame(120, mb_strlen($search, 'UTF-8'), 'Search limit counts Unicode characters');
        ratingsAssert(mb_check_encoding($search, 'UTF-8'), 'Search truncation preserves valid UTF-8');
        ratingsAssertSame('', ratingsReportFilters(['search' => ['unexpected']])['search'], 'Array search is safely ignored');
    });

    ratingsTest('invalid constraints do not silently export every rating', static function (): void {
        foreach ([-1, 6, '1 OR 1=1', ['unexpected'], '2.5'] as $value) {
            $filters = ratingsReportFilters(['rating' => $value]);
            ratingsAssert(count($filters['errors']) > 0, 'Invalid star filter reports an error');
            $caught = false;
            try {
                ratingsRows(['rating' => $value]);
            } catch (InvalidArgumentException $error) {
                $caught = true;
            }
            ratingsAssert($caught, 'Invalid star filter cannot become an unfiltered download');
        }
        foreach ([0, -1] as $limit) {
            $caught = false;
            try {
                ratingsRows([], $limit);
            } catch (InvalidArgumentException $error) {
                $caught = true;
            }
            ratingsAssert($caught, 'Invalid screen limit is rejected');
        }
    });

    ratingsTest('screen cap and full export use the same deterministic SQL results', static function (): void {
        $all = ratingsRows();
        $screen = ratingsRows([], 500);
        $expected = array_merge(range(514, 10), range(8, 1));
        ratingsAssertSame(513, count($all), 'Export includes every rating beyond 500');
        ratingsAssertSame(500, count($screen), 'Screen keeps its 500-row cap');
        ratingsAssertSame($expected, ratingsIds($all), 'Newest first; tied dates use descending IDs');
        ratingsAssertSame(array_slice($all, 0, 500), $screen, 'Screen and export have identical records and order');
        ratingsAssertSame($expected, ratingsIds(ratingsRows()), 'Repeated read has stable order');
        $bulk = ratingsRows(['search' => 'Bulk feedback']);
        ratingsAssertSame(505, count($bulk), 'Filtered export also includes matches beyond 500');
        ratingsAssertSame(array_slice($bulk, 0, 500), ratingsRows(['search' => 'Bulk feedback'], 500), 'Filtered screen is a prefix of the full export');
    });

    ratingsTest('LEFT JOIN results retain missing links and original payment values', static function (): void {
        $rows = array_column(ratingsRows(), null, 'id');
        ratingsAssertSame("Alice O'Brien", $rows[1]['fullname'], 'Customer name is joined');
        ratingsAssertSame('Alpha Clearance', $rows[1]['service_name'], 'Selected service is joined');
        ratingsAssertSame('Admissions', $rows[1]['dept_name'], 'Department is joined');
        ratingsAssertSame(700.0, (float)$rows[1]['amount'], 'Stored payment amount is retained');
        ratingsAssertSame(0.0, (float)$rows[2]['amount'], 'A paid zero amount stays zero');
        ratingsAssertSame('paid', $rows[2]['payment_status'], 'A zero payment can still be paid');
        ratingsAssertSame(0.0, (float)$rows[3]['amount'], 'Missing payment displays zero');
        ratingsAssertSame('pending', $rows[3]['payment_status'], 'Missing payment is pending');
        ratingsAssertSame(0.0, (float)$rows[6]['amount'], 'A recorded pending zero stays zero');
        foreach (['ticket_number', 'fullname', 'service_name', 'dept_name'] as $field) {
            ratingsAssertSame(null, $rows[7][$field], 'Missing queue and user retain the rating: ' . $field);
        }
        ratingsAssertSame('ORPHAN-SERVICE', $rows[8]['ticket_number'], 'Missing service retains its queue');
        ratingsAssertSame(null, $rows[8]['service_name'], 'Missing service is nullable');
        ratingsAssertSame("Alice O'Brien", $rows[8]['fullname'], 'Customer comes from the rating user');
    });

    ratingsTest('star and literal search filters match across screen and export', static function (): void {
        $expectedStars = [1 => [5], 2 => [6], 3 => [3], 4 => [8, 2], 5 => [7, 4, 1]];
        foreach ($expectedStars as $score => $special) {
            $expected = [];
            for ($id = 514; $id >= 10; $id--) {
                if ($id % 5 + 1 === $score) $expected[] = $id;
            }
            ratingsAssertSame(array_merge($expected, $special), ratingsIds(ratingsRows(['rating' => (string)$score])), 'Star filter selects only ' . $score . '-star ratings');
        }
        $searches = ['%' => [6, 2], '_' => [6, 2], '!' => [6, 2], '100%_Done!' => [6, 2], "O'Brien" => [8, 4, 1], 'LITERAL_%!' => [2], 'Alpha Clearance' => [2, 1], 'Admissions' => [2, 1], 'REG-005' => [5], 'Needs improvement' => [6], 'café' => [4, 3], '美容' => [4, 3], '😊' => [3], "' OR 1=1 --" => []];
        foreach ($searches as $search => $expected) {
            ratingsAssertSame($expected, ratingsIds(ratingsRows(['search' => $search])), 'Literal field search: ' . $search);
        }
        foreach ([['rating' => '5', 'search' => " O'Brien "], ['rating' => '4', 'search' => '100%_Done!'], ['rating' => '3', 'search' => '😊'], ['rating' => '2', 'search' => 'café']] as $index => $input) {
            $expected = [[4, 1], [2], [3], []][$index];
            $export = ratingsRows($input);
            ratingsAssertSame($expected, ratingsIds($export), 'Star and search constraints combine');
            ratingsAssertSame($export, ratingsRows($input, 500), 'Filtered screen and export stay consistent');
        }
    });

    ratingsTest('Excel contains every SQL result with native amounts, scores and dates', static function (): void {
        global $conn;
        $parts = ratingsWorkbook(ratingsReportRows($conn, ratingsReportFilters([])));
        $sheet = $parts['xl/worksheets/sheet1.xml'];
        $styles = $parts['xl/styles.xml'];
        ratingsAssertSame('Service ratings', $parts['xl/workbook.xml']->evaluate('string(/s:workbook/s:sheets/s:sheet/@name)'), 'Workbook has a ratings sheet');
        ratingsAssertSame(514, $sheet->query('/s:worksheet/s:sheetData/s:row')->length, 'Workbook includes 513 ratings plus its header');
        ratingsAssertSame('A1:J514', $sheet->evaluate('string(/s:worksheet/s:autoFilter/@ref)'), 'Excel filtering covers every exported column and row');
        ratingsAssertSame('frozen', $sheet->evaluate('string(/s:worksheet/s:sheetViews/s:sheetView/s:pane/@state)'), 'Column headings are frozen');
        $headers = ['Rating ID', 'Ticket', 'Customer', 'Selected service', 'Department', 'Rating (1–5)', 'Comment', 'Amount (PHP)', 'Payment status', 'Rated at'];
        foreach ($headers as $column => $header) {
            ratingsAssertSame($header, ratingsText($sheet, chr(65 + $column) . '1'), 'Ratings export column ' . ($column + 1));
        }
        ratingsAssertSame(10, $sheet->query('/s:worksheet/s:sheetData/s:row[@r="514"]/s:c')->length, 'Last record has every export column');
        ratingsAssertSame(514.0, ratingsNumber($sheet, 'A2'), 'First record follows SQL ordering');
        ratingsAssertSame(1.0, ratingsNumber($sheet, 'A514'), 'Oldest record beyond the screen cap is included');
        ratingsAssertSame(5.0, ratingsNumber($sheet, 'F514'), 'Star score is an Excel number');
        ratingsAssertSame(700.0, ratingsNumber($sheet, 'H514'), 'Payment amount is an Excel number');
        ratingsAssertSame(0.0, ratingsNumber($sheet, 'H513'), 'Stored zero payment remains numeric zero');
        ratingsAssertSame(0.0, ratingsNumber($sheet, 'H512'), 'Missing payment exports numeric zero');
        ratingsAssertSame(1234.5, ratingsNumber($sheet, 'H511'), 'Fractional currency is retained');
        ratingsAssertSame('#,##0.00', ratingsFormat($sheet, $styles, 'H511'), 'Amount uses a decimal currency format');
        $zone = new DateTimeZone('UTC');
        $expectedDate = ((new DateTimeImmutable('2026-01-01 09:15:30', $zone))->getTimestamp() - (new DateTimeImmutable('1899-12-30', $zone))->getTimestamp()) / 86400;
        ratingsAssert(abs($expectedDate - ratingsNumber($sheet, 'J514')) < 0.00000001, 'Rated date is a native wall-clock Excel timestamp');
        ratingsAssertSame('yyyy-mm-dd hh:mm:ss', ratingsFormat($sheet, $styles, 'J514'), 'Rated date has a date format');
        ratingsAssertSame('Paid', ratingsText($sheet, 'I513'), 'Zero paid amount has a paid label');
        ratingsAssertSame('Pending', ratingsText($sheet, 'I512'), 'Missing payment has a pending label');
        ratingsAssertSame('=SUM(1,2)', ratingsText($sheet, 'G514'), 'Formula-like feedback stays literal');
        ratingsAssertSame('+cmd|example', ratingsText($sheet, 'G507'), 'Leading plus feedback stays literal');
        ratingsAssertSame('Maayos po – salamat 😊', ratingsText($sheet, 'G512'), 'Unicode feedback survives export');
        ratingsAssertSame('"Great" & <clean>', ratingsText($sheet, 'G511'), 'XML characters survive export');
        ratingsAssertSame(0, $sheet->query('//s:f')->length, 'Workbook contains no executable formulas');
    });

    ratingsTest('empty filter results still create a usable ten-column workbook', static function (): void {
        global $conn;
        $input = ['rating' => '1', 'search' => 'does-not-exist'];
        ratingsAssertSame([], ratingsRows($input), 'Unmatched filter has no records');
        $parts = ratingsWorkbook(ratingsReportRows($conn, ratingsReportFilters($input)));
        $sheet = $parts['xl/worksheets/sheet1.xml'];
        ratingsAssertSame(1, $sheet->query('/s:worksheet/s:sheetData/s:row')->length, 'Empty workbook retains only its header');
        ratingsAssertSame(10, $sheet->query('/s:worksheet/s:sheetData/s:row/s:c')->length, 'All ten headings remain present');
        ratingsAssertSame('A1:J1', $sheet->evaluate('string(/s:worksheet/s:autoFilter/@ref)'), 'Empty export filter range is valid');
        ratingsAssertSame('Rated at', ratingsText($sheet, 'J1'), 'Last heading remains present');
    });
} catch (Throwable $error) {
    fwrite(STDERR, 'Fixture setup failed: ' . $error->getMessage() . "\n");
    $conn->close();
    exit(2);
}

// The connection owns every fixture table; closing it removes all fixture data.
$conn->close();
foreach ($failures as $failure) {
    fwrite(STDERR, $failure . "\n");
}
echo sprintf("\n%d tests, %d assertions, %d failures.\n", $testCount, $assertionCount, count($failures));
exit($failures ? 1 : 0);
