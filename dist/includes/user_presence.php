<?php

function ensureUserPresenceColumns(mysqli $conn): bool
{
    static $checkedConnections = [];
    $connectionId = spl_object_id($conn);
    if (array_key_exists($connectionId, $checkedConnections)) {
        return $checkedConnections[$connectionId];
    }

    $readColumns = static function () use ($conn): ?array {
        $result = $conn->query("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME IN ('last_seen_at', 'is_online')");
        if (!$result) {
            return null;
        }
        $columns = [];
        while ($row = $result->fetch_assoc()) {
            $columns[$row['COLUMN_NAME']] = true;
        }
        $result->free();
        return $columns;
    };

    $columns = $readColumns();
    if ($columns === null) {
        return $checkedConnections[$connectionId] = false;
    }

    $changes = [];
    if (!isset($columns['last_seen_at'])) {
        $changes[] = 'ADD COLUMN last_seen_at DATETIME NULL';
    }
    if (!isset($columns['is_online'])) {
        $changes[] = 'ADD COLUMN is_online TINYINT(1) NOT NULL DEFAULT 0';
    }
    if ($changes === []) {
        return $checkedConnections[$connectionId] = true;
    }
    if ($conn->query('ALTER TABLE users ' . implode(', ', $changes))) {
        return $checkedConnections[$connectionId] = true;
    }

    // Another request may have added the columns while this request was checking.
    $columns = $readColumns();
    return $checkedConnections[$connectionId] = $columns !== null
        && isset($columns['last_seen_at'], $columns['is_online']);
}

function recordUserActivity(?mysqli $conn, int $userId): bool
{
    if (!$conn || $userId <= 0 || !ensureUserPresenceColumns($conn)) {
        return false;
    }

    $stmt = $conn->prepare('UPDATE users SET last_seen_at = CURRENT_TIMESTAMP, is_online = 1 WHERE id = ? AND (is_online = 0 OR last_seen_at IS NULL OR last_seen_at <= CURRENT_TIMESTAMP - INTERVAL 10 SECOND)');
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param('i', $userId);
    $saved = $stmt->execute();
    $stmt->close();
    return $saved;
}

function markUserOffline(?mysqli $conn, int $userId): bool
{
    if (!$conn || $userId <= 0 || !ensureUserPresenceColumns($conn)) {
        return false;
    }

    $stmt = $conn->prepare('UPDATE users SET last_seen_at = CURRENT_TIMESTAMP, is_online = 0 WHERE id = ?');
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param('i', $userId);
    $saved = $stmt->execute();
    $stmt->close();
    return $saved;
}

function getUserPresence(?string $lastSeenAt, ?int $now = null): array
{
    $lastSeen = null;
    if ($lastSeenAt !== null && $lastSeenAt !== '') {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $lastSeenAt, new DateTimeZone('Asia/Manila'));
        if ($date && $date->format('Y-m-d H:i:s') === $lastSeenAt) {
            $lastSeen = $date->getTimestamp();
        }
    }

    $age = $lastSeen !== null ? max(0, ($now ?? time()) - $lastSeen) : null;
    // Use recent activity so switching accounts keeps the 30-second grace period.
    $isActive = $age !== null && $age < 30;
    $lastSeenLabel = 'No activity recorded';
    if ($age !== null) {
        $lastSeenLabel = 'Last seen just now';
        if ($age >= 30 && $age < 60) {
            $lastSeenLabel = 'Last seen ' . $age . ' secs ago';
        }
        foreach ([31536000 => ['year', 'years'], 2592000 => ['month', 'months'], 604800 => ['week', 'weeks'], 86400 => ['day', 'days'], 3600 => ['hour', 'hours'], 60 => ['min', 'mins']] as $seconds => $units) {
            if ($age >= $seconds) {
                $amount = (int)floor($age / $seconds);
                $lastSeenLabel = 'Last seen ' . $amount . ' ' . $units[$amount === 1 ? 0 : 1] . ' ago';
                break;
            }
        }
    }

    return [
        'is_active' => $isActive,
        'last_seen_at' => $lastSeen,
        'status' => $isActive ? 'Active' : 'Offline',
        'last_seen_label' => $lastSeenLabel,
    ];
}
