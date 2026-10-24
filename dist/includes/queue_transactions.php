<?php

/** @return array{ok: bool, message: string} */
function deleteQueueTransaction(mysqli $connection, int $queueId): array
{
    $failure = ['ok' => false, 'message' => 'Unable to delete this transaction. No changes were saved.'];
    if ($queueId < 1) return ['ok' => false, 'message' => 'Choose a valid transaction.'];
    if (!$connection->begin_transaction()) return $failure;
    $statements = [];
    $committed = false;
    try {
        $select = $connection->prepare('SELECT id FROM queue WHERE id = ? FOR UPDATE');
        if (!$select) return $failure;
        $statements[] = $select;
        $select->bind_param('i', $queueId);
        if (!$select->execute()) return $failure;
        $result = $select->get_result();
        if (!$result || !$result->fetch_assoc()) return ['ok' => false, 'message' => 'This transaction no longer exists. Refresh the report.'];
        $deletedRows = 0;
        foreach (['DELETE FROM queue_ratings WHERE queue_id = ?', 'DELETE FROM queue_payments WHERE queue_id = ?', 'DELETE FROM queue WHERE id = ?'] as $sql) {
            $statement = $connection->prepare($sql);
            if (!$statement) return $failure;
            $statements[] = $statement;
            $statement->bind_param('i', $queueId);
            if (!$statement->execute()) return $failure;
            $deletedRows = $statement->affected_rows;
        }
        if ($deletedRows !== 1 || !$connection->commit()) return $failure;
        $committed = true;
        return ['ok' => true, 'message' => 'Transaction deleted. Report and sales totals have been updated.'];
    } catch (mysqli_sql_exception $error) {
        return $failure;
    } finally {
        if (!$committed) $connection->rollback();
        foreach ($statements as $statement) $statement->close();
    }
}
