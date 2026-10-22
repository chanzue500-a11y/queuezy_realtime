<?php

require_once __DIR__ . '/queue_report.php';

/**
 * Build an Excel workbook without loading every transaction into memory.
 * The caller owns the returned temporary ZIP file and must delete it after use.
 */
function queueReportExcelFile(iterable $rows, ?callable $sheetWriter = null, string $sheetName = 'Queue transactions'): string
{
    $temporaryBase = tempnam(sys_get_temp_dir(), 'queue-report-');
    if ($temporaryBase === false) {
        throw new RuntimeException('Unable to create a temporary export file.');
    }
    $archivePath = $temporaryBase . '.zip';
    $writer = null;
    $archive = null;
    try {
        $writer = new XMLWriter();
        if (!$writer->openUri($temporaryBase)) {
            throw new RuntimeException('Unable to write the export worksheet.');
        }
        ($sheetWriter ?? 'queueReportExcelSheet')($writer, $rows);
        $writer->flush();
        $writer = null;

        // PharData creates ordinary ZIP files even when phar.readonly is enabled.
        $archive = new PharData($archivePath, 0, null, Phar::ZIP);
        $archive->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            . '</Types>');
        $archive->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '</Relationships>');
        $archive->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheets><sheet name="' . htmlspecialchars($sheetName, ENT_QUOTES | ENT_XML1, 'UTF-8') . '" sheetId="1" r:id="rId1"/></sheets></workbook>');
        $archive->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            . '</Relationships>');
        $archive->addFromString('xl/styles.xml', queueReportExcelStyles());
        $archive->addFile($temporaryBase, 'xl/worksheets/sheet1.xml');
        $archive->compressFiles(Phar::GZ);
        $archive = null;
        return $archivePath;
    } catch (Throwable $error) {
        $writer = null;
        $archive = null;
        if (is_file($archivePath)) {
            unlink($archivePath);
        }
        throw $error;
    } finally {
        $writer = null;
        if (is_file($temporaryBase)) {
            unlink($temporaryBase);
        }
    }
}

function queueReportExcelSheet(XMLWriter $writer, iterable $rows): void
{
    $writer->startDocument('1.0', 'UTF-8', 'yes');
    $writer->startElementNS(null, 'worksheet', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
    $writer->startElement('sheetViews');
    $writer->startElement('sheetView');
    $writer->writeAttribute('workbookViewId', '0');
    $writer->writeAttribute('showGridLines', '0');
    $writer->startElement('pane');
    foreach (['ySplit' => '1', 'topLeftCell' => 'A2', 'activePane' => 'bottomLeft', 'state' => 'frozen'] as $key => $value) {
        $writer->writeAttribute($key, $value);
    }
    $writer->endElement();
    $writer->endElement();
    $writer->endElement();
    $writer->startElement('sheetFormatPr');
    $writer->writeAttribute('defaultRowHeight', '18');
    $writer->endElement();
    $writer->startElement('cols');
    foreach ([12, 18, 28, 42, 40, 23, 23, 18, 14, 18, 18, 23] as $index => $width) {
        $writer->startElement('col');
        $writer->writeAttribute('min', (string)($index + 1));
        $writer->writeAttribute('max', (string)($index + 1));
        $writer->writeAttribute('width', (string)$width);
        $writer->writeAttribute('customWidth', '1');
        $writer->endElement();
    }
    $writer->endElement();
    $writer->startElement('sheetData');
    $writer->startElement('row');
    $writer->writeAttribute('r', '1');
    $writer->writeAttribute('ht', '28');
    $writer->writeAttribute('customHeight', '1');
    $headers = ['Queue ID', 'Ticket', 'Customer', 'Selected service', 'Department', 'Created at', 'Completed at', 'Queue status', 'Payment ID', 'Amount (PHP)', 'Payment status', 'Paid at'];
    foreach ($headers as $index => $header) {
        queueReportExcelText($writer, chr(65 + $index) . '1', $header, 1);
    }
    $writer->endElement();

    $rowNumber = 1;
    $statuses = queueReportStatuses();
    foreach ($rows as $row) {
        if ($rowNumber >= 1048576) {
            throw new RuntimeException('The export exceeds the Excel worksheet row limit. Apply filters to export fewer transactions.');
        }
        $rowNumber++;
        $writer->startElement('row');
        $writer->writeAttribute('r', (string)$rowNumber);
        queueReportExcelNumber($writer, 'A' . $rowNumber, $row['id']);
        queueReportExcelText($writer, 'B' . $rowNumber, $row['ticket_number']);
        queueReportExcelText($writer, 'C' . $rowNumber, $row['fullname'] ?? 'Unknown customer');
        queueReportExcelText($writer, 'D' . $rowNumber, $row['service_name'] ?? 'Unavailable service');
        queueReportExcelText($writer, 'E' . $rowNumber, $row['dept_name'] ?? 'Unassigned');
        queueReportExcelDate($writer, 'F' . $rowNumber, $row['created_at']);
        queueReportExcelDate($writer, 'G' . $rowNumber, $row['completed_at']);
        queueReportExcelText($writer, 'H' . $rowNumber, $statuses[$row['status']] ?? ucfirst(str_replace('_', ' ', $row['status'])));
        queueReportExcelNumber($writer, 'I' . $rowNumber, $row['payment_id']);
        queueReportExcelNumber($writer, 'J' . $rowNumber, $row['amount'], 2);
        queueReportExcelText($writer, 'K' . $rowNumber, $row['payment_status'] === 'paid' ? 'Paid' : 'Pending');
        queueReportExcelDate($writer, 'L' . $rowNumber, $row['paid_at']);
        $writer->endElement();
        if ($rowNumber % 1000 === 0) {
            $writer->flush();
        }
    }
    $writer->endElement();
    $writer->startElement('autoFilter');
    $writer->writeAttribute('ref', 'A1:L' . $rowNumber);
    $writer->endElement();
    $writer->endElement();
    $writer->endDocument();
}

function queueReportExcelText(XMLWriter $writer, string $reference, $value, int $style = 0): void
{
    $text = mb_convert_encoding((string)$value, 'UTF-8', 'UTF-8');
    $text = preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', $text);
    // Excel limits a cell to 32,767 UTF-16 code units.
    $text = mb_convert_encoding(mb_strcut(mb_convert_encoding($text, 'UTF-16LE', 'UTF-8'), 0, 65534, 'UTF-16LE'), 'UTF-8', 'UTF-16LE');
    // Preserve literal escape-like text instead of letting Excel decode it.
    $text = preg_replace('/_x([0-9A-Fa-f]{4})_/', '_x005F_x$1_', $text);
    $writer->startElement('c');
    $writer->writeAttribute('r', $reference);
    $writer->writeAttribute('t', 'inlineStr');
    if ($style !== 0) $writer->writeAttribute('s', (string)$style);
    $writer->startElement('is');
    $writer->startElement('t');
    $writer->writeAttribute('xml:space', 'preserve');
    // Explicit inline strings keep formula-like customer/service text inert.
    $writer->text($text);
    $writer->endElement();
    $writer->endElement();
    $writer->endElement();
}

function queueReportExcelNumber(XMLWriter $writer, string $reference, $value, int $style = 0): void
{
    $writer->startElement('c');
    $writer->writeAttribute('r', $reference);
    if ($style !== 0) $writer->writeAttribute('s', (string)$style);
    if ($value !== null && $value !== '') {
        if (!is_numeric($value) || !is_finite((float)$value)) {
            throw new RuntimeException('The export contains an invalid numeric value.');
        }
        $writer->writeElement('v', (string)$value);
    }
    $writer->endElement();
}

function queueReportExcelDate(XMLWriter $writer, string $reference, $value): void
{
    if ($value === null || $value === '') {
        queueReportExcelNumber($writer, $reference, null, 3);
        return;
    }
    $text = (string)$value;
    $date = preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}$/D', $text)
        ? DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $text, new DateTimeZone('UTC')) : false;
    if (!$date || $date->format('Y-m-d H:i:s') !== $text || $text < '1900-01-01 00:00:00') {
        queueReportExcelText($writer, $reference, $text);
        return;
    }
    // Treat database DATETIME values as wall-clock values, without a timezone shift.
    $epoch = new DateTimeImmutable('1899-12-31 00:00:00', new DateTimeZone('UTC'));
    $serial = ($date->getTimestamp() - $epoch->getTimestamp()) / 86400;
    if ($text >= '1900-03-01 00:00:00') $serial++; // Excel's fictitious 1900 leap day.
    queueReportExcelNumber($writer, $reference, rtrim(rtrim(sprintf('%.10F', $serial), '0'), '.'), 3);
}

function queueReportExcelStyles(): string
{
    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<numFmts count="2"><numFmt numFmtId="164" formatCode="#,##0.00"/><numFmt numFmtId="165" formatCode="yyyy-mm-dd hh:mm:ss"/></numFmts>'
        . '<fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><color rgb="FFFFFFFF"/><sz val="11"/><name val="Calibri"/></font></fonts>'
        . '<fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill><fill><patternFill patternType="solid"><fgColor rgb="FF1E293B"/><bgColor indexed="64"/></patternFill></fill></fills>'
        . '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
        . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
        . '<cellXfs count="5">'
        . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
        . '<xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1" applyAlignment="1"><alignment vertical="center" wrapText="1"/></xf>'
        . '<xf numFmtId="164" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
        . '<xf numFmtId="165" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
        . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0" applyAlignment="1"><alignment vertical="top" wrapText="1"/></xf>'
        . '</cellXfs><cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
        . '</styleSheet>';
}
