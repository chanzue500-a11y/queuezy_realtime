<?php

function ensureCustomerArchivesTable(mysqli $conn): bool
{
    try {
        // Installed databases may use signed IDs even though new schemas use unsigned IDs.
        $result = $conn->query("SHOW COLUMNS FROM users LIKE 'id'");
        $column = $result ? $result->fetch_assoc() : null;
        if ($result) {
            $result->free();
        }
        $idType = $column['Type'] ?? '';
        if (!preg_match('/\A(?:tinyint|smallint|mediumint|int|bigint)(?:\(\d+\))?(?: unsigned)?\z/i', $idType)) {
            return false;
        }
        return (bool)$conn->query("CREATE TABLE IF NOT EXISTS customer_archives (
            user_id {$idType} NOT NULL PRIMARY KEY,
            archived_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB");
    } catch (mysqli_sql_exception $exception) {
        return false;
    }
}

/**
 * Organize customer records without changing accounts, access, or queue history.
 * Call before any caller-owned transaction: table setup may execute DDL.
 *
 * @return array{ok: bool, message: string, affected: int}
 */
function setCustomerArchiveState(?mysqli $conn, array $userIds, bool $archived): array
{
    $failure = ['ok' => false, 'message' => 'Select between 1 and 500 customers.', 'affected' => 0];
    if (!$userIds || count($userIds) > 500) {
        return $failure;
    }
    $ids = [];
    $maximumId = (string)PHP_INT_MAX;
    foreach ($userIds as $userId) {
        if ((!is_int($userId) && !is_string($userId))
            || !preg_match('/\A[1-9][0-9]*\z/', (string)$userId)
            || strlen((string)$userId) > strlen($maximumId)
            || (strlen((string)$userId) === strlen($maximumId) && strcmp((string)$userId, $maximumId) > 0)) {
            return ['ok' => false, 'message' => 'Select valid customers and try again.', 'affected' => 0];
        }
        $ids[(int)$userId] = (int)$userId;
    }
    $ids = array_values($ids);
    sort($ids, SORT_NUMERIC);
    if (!$conn) {
        return ['ok' => false, 'message' => 'The database is unavailable. Please try again.', 'affected' => 0];
    }

    $failure['message'] = $archived
        ? 'We could not archive the selected customers. Please try again.'
        : 'We could not restore the selected customers. Please try again.';
    // CREATE TABLE can implicitly commit, so it must precede the transaction.
    if (!ensureCustomerArchivesTable($conn)) {
        return $failure;
    }
    $usersStmt = $archivesStmt = $changeStmt = null;
    $transactionStarted = $committed = false;
    try {
        if (!$conn->begin_transaction()) {
            return $failure;
        }
        $transactionStarted = true;
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $types = str_repeat('i', count($ids));
        // Lock in a stable order so overlapping bulk actions serialize safely.
        $usersStmt = $conn->prepare("SELECT id, role FROM users WHERE id IN ({$placeholders}) ORDER BY id FOR UPDATE");
        if (!$usersStmt || !$usersStmt->bind_param($types, ...$ids) || !$usersStmt->execute()) {
            return $failure;
        }
        $users = $usersStmt->get_result();
        if (!$users) {
            return $failure;
        }
        $eligible = $users->num_rows === count($ids);
        while ($user = $users->fetch_assoc()) {
            if (!in_array(strtolower((string)$user['role']), ['customer', 'user'], true)) {
                $eligible = false;
            }
        }
        $users->free();
        if (!$eligible) {
            return ['ok' => false, 'message' => 'Only existing customer accounts can be archived or restored. Refresh the list and try again.', 'affected' => 0];
        }

        $archivesStmt = $conn->prepare("SELECT user_id FROM customer_archives WHERE user_id IN ({$placeholders}) ORDER BY user_id FOR UPDATE");
        if (!$archivesStmt || !$archivesStmt->bind_param($types, ...$ids) || !$archivesStmt->execute()) {
            return $failure;
        }
        $archiveRows = $archivesStmt->get_result();
        if (!$archiveRows) {
            return $failure;
        }
        $alreadyArchived = [];
        while ($archive = $archiveRows->fetch_assoc()) {
            $alreadyArchived[(int)$archive['user_id']] = true;
        }
        $archiveRows->free();
        $changeStmt = $conn->prepare($archived
            ? 'INSERT INTO customer_archives (user_id) VALUES (?)'
            : 'DELETE FROM customer_archives WHERE user_id = ?');
        if (!$changeStmt) {
            return $failure;
        }
        $selectedId = 0;
        if (!$changeStmt->bind_param('i', $selectedId)) {
            return $failure;
        }
        $affected = 0;
        foreach ($ids as $selectedId) {
            if (isset($alreadyArchived[$selectedId]) === $archived) {
                continue;
            }
            if (!$changeStmt->execute()) {
                return $failure;
            }
            $affected += $changeStmt->affected_rows;
        }
        if (!$conn->commit()) {
            return $failure;
        }
        $committed = true;
        if ($affected === 0) {
            $message = $archived ? 'The selected customers are already archived.' : 'The selected customers are already active.';
        } else {
            $message = $affected . ($affected === 1 ? ' customer ' : ' customers ') . ($archived ? 'archived successfully.' : 'restored successfully.');
        }
        return ['ok' => true, 'message' => $message, 'affected' => $affected];
    } catch (mysqli_sql_exception $exception) {
        return $failure;
    } finally {
        if ($transactionStarted && !$committed) {
            $conn->rollback();
        }
        foreach ([$usersStmt, $archivesStmt, $changeStmt] as $statement) {
            if ($statement instanceof mysqli_stmt) {
                $statement->close();
            }
        }
    }
}
