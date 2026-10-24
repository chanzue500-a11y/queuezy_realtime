<?php
require_once __DIR__ . '/../dist/database/db.php';
require_once __DIR__ . '/../dist/includes/user_accounts.php';
if (!$conn) throw new RuntimeException('Database unavailable');
foreach ([
    'CREATE TEMPORARY TABLE users (id INT PRIMARY KEY, role VARCHAR(20)) ENGINE=InnoDB',
    'CREATE TEMPORARY TABLE queue_ratings (id INT PRIMARY KEY, user_id INT) ENGINE=InnoDB',
    "INSERT INTO users VALUES (1,'admin'),(2,'superadmin'),(3,'staff'),(4,'customer'),(5,'unknown')",
    'INSERT INTO queue_ratings VALUES (11,1),(12,2),(13,3),(14,4),(15,5)',
] as $sql) {
    if (!$conn->query($sql)) throw new RuntimeException($conn->error);
}
foreach ([1, 2, 5] as $protectedId) {
    $result = deleteDirectoryUser($conn, $protectedId);
    if ($result['ok']) throw new RuntimeException('Protected or unsupported account deleted');
    if ($conn->query("SELECT id FROM users WHERE id = $protectedId")->num_rows !== 1
        || $conn->query("SELECT id FROM queue_ratings WHERE user_id = $protectedId")->num_rows !== 1) {
        throw new RuntimeException('Rejected deletion changed an account or ratings');
    }
}
foreach ([3, 4] as $allowedId) {
    if (!deleteDirectoryUser($conn, $allowedId)['ok']) throw new RuntimeException('Staff/customer deletion rejected');
    if ($conn->query("SELECT id FROM users WHERE id = $allowedId")->num_rows !== 0
        || $conn->query("SELECT id FROM queue_ratings WHERE user_id = $allowedId")->num_rows !== 0) {
        throw new RuntimeException('Allowed deletion did not remove account and ratings');
    }
}
foreach ([0, -1, 999] as $invalidId) {
    if (deleteDirectoryUser($conn, $invalidId)['ok']) throw new RuntimeException('Invalid account deletion accepted');
}
echo "PASS: staff/customer deletion, admin/superadmin protection, protected ratings, and invalid requests.\n";
