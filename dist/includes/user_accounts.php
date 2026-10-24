<?php

/** @return array{ok: bool, message: string} */
function deleteDirectoryUser(mysqli $connection, int $userId): array
{
    $failure = ['ok' => false, 'message' => 'Could not delete this account and its ratings. Please try again.'];
    if ($userId < 1) return ['ok' => false, 'message' => 'Choose a valid user.'];
    if (!$connection->begin_transaction()) return $failure;
    $statements = [];
    $committed = false;
    try {
        $select = $connection->prepare('SELECT role FROM users WHERE id = ? FOR UPDATE');
        if (!$select) return $failure;
        $statements[] = $select;
        $select->bind_param('i', $userId);
        if (!$select->execute()) return $failure;
        $result = $select->get_result();
        $user = $result ? $result->fetch_assoc() : null;
        if (!$user) return ['ok' => false, 'message' => 'This account no longer exists. Refresh the list.'];
        if (!in_array(strtolower($user['role']), ['staff', 'customer'], true)) {
            return ['ok' => false, 'message' => 'Admin accounts are protected and cannot be deleted.'];
        }
        $ratings = $connection->prepare('DELETE FROM queue_ratings WHERE user_id = ?');
        if (!$ratings) return $failure;
        $statements[] = $ratings;
        $ratings->bind_param('i', $userId);
        if (!$ratings->execute()) return $failure;
        $delete = $connection->prepare("DELETE FROM users WHERE id = ? AND role IN ('staff', 'customer')");
        if (!$delete) return $failure;
        $statements[] = $delete;
        $delete->bind_param('i', $userId);
        if (!$delete->execute() || $delete->affected_rows !== 1 || !$connection->commit()) return $failure;
        $committed = true;
        return ['ok' => true, 'message' => 'User deleted.'];
    } catch (mysqli_sql_exception $error) {
        return $failure;
    } finally {
        if (!$committed) $connection->rollback();
        foreach ($statements as $statement) $statement->close();
    }
}
