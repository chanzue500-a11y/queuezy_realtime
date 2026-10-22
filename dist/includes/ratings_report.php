<?php

require_once __DIR__ . '/queue_report_excel.php';

/** Normalize the filters used by both the ratings screen and its export. */
function ratingsReportFilters(array $input): array
{
    $filters = ['rating' => 0, 'search' => '', 'errors' => []];
    $ratingInput = $input['rating'] ?? '';
    $ratingText = is_scalar($ratingInput) ? trim((string)$ratingInput) : '';
    $rating = filter_var($ratingText, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 5]]);
    if ($rating !== false) {
        $filters['rating'] = $rating;
    } elseif ((!is_scalar($ratingInput) && $ratingInput !== null) || ($ratingText !== '' && $ratingText !== '0')) {
        $filters['errors'][] = 'Choose a valid rating from 1 to 5.';
    }

    $searchInput = $input['search'] ?? '';
    $search = is_scalar($searchInput) ? mb_convert_encoding((string)$searchInput, 'UTF-8', 'UTF-8') : '';
    $search = preg_replace('/^\s+|\s+$/u', '', $search);
    $filters['search'] = mb_substr($search, 0, 120, 'UTF-8');
    return $filters;
}

/**
 * Stream ratings with SQL JOINs and SQL filtering, without per-row queries.
 * Omit the limit to export every matching rating rather than the screen limit.
 */
function ratingsReportRows(mysqli $connection, array $filters, ?int $limit = null): Generator
{
    if (!empty($filters['errors'])) {
        throw new InvalidArgumentException(implode(' ', $filters['errors']));
    }
    if ($limit !== null && $limit < 1) {
        throw new InvalidArgumentException('The ratings row limit must be a positive integer.');
    }

    $conditions = [];
    $parameters = [];
    $types = '';
    if ($filters['rating'] !== 0) {
        $conditions[] = 'qr.rating = ?';
        $parameters[] = $filters['rating'];
        $types .= 'i';
    }
    if ($filters['search'] !== '') {
        // Search literal substrings, including %, _ and the escape character.
        $pattern = '%' . strtr($filters['search'], ['!' => '!!', '%' => '!%', '_' => '!_']) . '%';
        $conditions[] = "CONCAT_WS(' ', COALESCE(q.ticket_number, ''), COALESCE(u.fullname, ''), COALESCE(s.service_name, ''), COALESCE(d.dept_name, ''), COALESCE(qr.feedback, '')) LIKE ? ESCAPE '!'";
        $parameters[] = $pattern;
        $types .= 's';
    }

    $sql = "SELECT qr.id, qr.rating, qr.feedback, qr.created_at, q.ticket_number,
            s.service_name, d.dept_name, u.fullname, COALESCE(qp.amount, 0) AS amount,
            COALESCE(qp.status, 'pending') AS payment_status
        FROM queue_ratings qr
        LEFT JOIN queue q ON q.id = qr.queue_id
        LEFT JOIN users u ON u.id = qr.user_id
        LEFT JOIN services s ON s.id = q.service_id
        LEFT JOIN departments d ON d.id = s.dept_id
        LEFT JOIN queue_payments qp ON qp.queue_id = q.id";
    if ($conditions) {
        $sql .= ' WHERE ' . implode(' AND ', $conditions);
    }
    $sql .= ' ORDER BY qr.created_at DESC, qr.id DESC';
    if ($limit !== null) {
        $sql .= ' LIMIT ?';
        $parameters[] = $limit;
        $types .= 'i';
    }

    $statement = $connection->prepare($sql);
    if (!$statement) {
        throw new RuntimeException($connection->error);
    }
    try {
        if ($types !== '') {
            $statement->bind_param($types, ...$parameters);
        }
        if (!$statement->execute()) {
            throw new RuntimeException($statement->error);
        }
        $statement->bind_result($id, $rating, $feedback, $createdAt, $ticket, $service,
            $department, $customer, $amount, $paymentStatus);
        while (true) {
            $fetched = $statement->fetch();
            if ($fetched === null) {
                break;
            }
            if ($fetched === false) {
                throw new RuntimeException($statement->error);
            }
            yield [
                'id' => $id, 'rating' => $rating, 'feedback' => $feedback,
                'created_at' => $createdAt, 'ticket_number' => $ticket,
                'service_name' => $service, 'dept_name' => $department,
                'fullname' => $customer, 'amount' => $amount,
                'payment_status' => $paymentStatus,
            ];
        }
    } finally {
        $statement->close();
    }
}

/** The caller must delete the returned temporary workbook after downloading it. */
function ratingsReportExcelFile(iterable $rows): string
{
    return queueReportExcelFile($rows, 'ratingsReportExcelSheet', 'Service ratings');
}

function ratingsReportExcelSheet(XMLWriter $writer, iterable $rows): void
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
    foreach ([12, 18, 28, 42, 40, 16, 60, 18, 18, 23] as $index => $width) {
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
    $headers = ['Rating ID', 'Ticket', 'Customer', 'Selected service', 'Department', 'Rating (1–5)', 'Comment', 'Amount (PHP)', 'Payment status', 'Rated at'];
    foreach ($headers as $index => $header) {
        queueReportExcelText($writer, chr(65 + $index) . '1', $header, 1);
    }
    $writer->endElement();

    $rowNumber = 1;
    foreach ($rows as $row) {
        if ($rowNumber >= 1048576) {
            throw new RuntimeException('The export exceeds the Excel worksheet row limit. Apply filters to export fewer ratings.');
        }
        $rowNumber++;
        $writer->startElement('row');
        $writer->writeAttribute('r', (string)$rowNumber);
        queueReportExcelNumber($writer, 'A' . $rowNumber, $row['id']);
        queueReportExcelText($writer, 'B' . $rowNumber, $row['ticket_number'] ?? 'Ticket unavailable');
        queueReportExcelText($writer, 'C' . $rowNumber, $row['fullname'] ?? 'Customer unavailable');
        queueReportExcelText($writer, 'D' . $rowNumber, $row['service_name'] ?? 'Service unavailable');
        queueReportExcelText($writer, 'E' . $rowNumber, $row['dept_name'] ?? 'Department unavailable');
        queueReportExcelNumber($writer, 'F' . $rowNumber, $row['rating']);
        queueReportExcelText($writer, 'G' . $rowNumber, $row['feedback'] ?? '');
        queueReportExcelNumber($writer, 'H' . $rowNumber, $row['amount'], 2);
        queueReportExcelText($writer, 'I' . $rowNumber, $row['payment_status'] === 'paid' ? 'Paid' : 'Pending');
        queueReportExcelDate($writer, 'J' . $rowNumber, $row['created_at']);
        $writer->endElement();
        if ($rowNumber % 1000 === 0) {
            $writer->flush();
        }
    }
    $writer->endElement();
    $writer->startElement('autoFilter');
    $writer->writeAttribute('ref', 'A1:J' . $rowNumber);
    $writer->endElement();
    $writer->endElement();
    $writer->endDocument();
}
