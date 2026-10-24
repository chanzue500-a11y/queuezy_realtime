<?php
require_once __DIR__ . '/../dist/database/db.php';
require_once __DIR__ . '/../dist/includes/service_catalog.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
foreach (defaultServiceCatalog() as [$name, $code, $names]) {
    if (count($names) !== 10 || count(array_unique($names)) !== 10) throw new RuntimeException('Invalid starter catalog: ' . $code);
}
// Connection-local fixtures ensure no real services or transactions are modified.
$conn->query('CREATE TEMPORARY TABLE departments (id INT PRIMARY KEY, dept_name VARCHAR(160), dept_code VARCHAR(10)) ENGINE=InnoDB');
$conn->query('CREATE TEMPORARY TABLE services (id INT AUTO_INCREMENT PRIMARY KEY, dept_id INT, service_name VARCHAR(160), estimated_time_mins INT) ENGINE=InnoDB');
$conn->query('CREATE TEMPORARY TABLE service_fees (id INT AUTO_INCREMENT PRIMARY KEY, service_id INT, amount DECIMAL(10,2)) ENGINE=InnoDB');
$conn->query('CREATE TEMPORARY TABLE queue (id INT PRIMARY KEY, service_id INT) ENGINE=InnoDB');
$conn->query('CREATE TEMPORARY TABLE queue_payments (id INT PRIMARY KEY, queue_id INT, amount DECIMAL(10,2)) ENGINE=InnoDB');
$conn->query('CREATE TEMPORARY TABLE queue_ratings (id INT PRIMARY KEY, queue_id INT, rating INT) ENGINE=InnoDB');
$conn->query("INSERT INTO departments VALUES (1,'Hair','HCHS'),(2,'Retail','RBT')");
for ($i = 1; $i <= 21; $i++) {
    $department = $i <= 13 ? 1 : 2;
    $conn->query("INSERT INTO services VALUES ($i,$department,'Custom service $i',45)");
    $conn->query("INSERT INTO service_fees (service_id,amount) VALUES ($i,500)");
}
$conn->query('INSERT INTO queue VALUES (1,13)');
$conn->query('INSERT INTO queue_payments VALUES (1,1,500)');
$conn->query('INSERT INTO queue_ratings VALUES (1,1,5)');
$argv = ['initialize_ten_services.php', '--apply'];
ob_start();
require __DIR__ . '/../scripts/initialize_ten_services.php';
ob_end_clean();
foreach ($conn->query('SELECT COUNT(*) AS total FROM services GROUP BY dept_id')->fetch_all(MYSQLI_ASSOC) as $row) {
    if ((int)$row['total'] !== 10) throw new RuntimeException('Not ten services.');
}
if ($conn->query('SELECT id FROM services WHERE id=13')->num_rows !== 1) throw new RuntimeException('Used service deleted.');
if ($conn->query('SELECT id FROM services WHERE id IN (10,11,12)')->num_rows !== 0) throw new RuntimeException('Excess services remain.');
if ($conn->query('SELECT id FROM service_fees WHERE service_id IN (10,11,12)')->num_rows !== 0) throw new RuntimeException('Orphan fees remain.');
$conn->query("INSERT INTO services (dept_id,service_name,estimated_time_mins) VALUES (1,'Manually added eleventh',30)");
if ((int)$conn->query('SELECT COUNT(*) AS total FROM services WHERE dept_id=1')->fetch_assoc()['total'] !== 11) throw new RuntimeException('Manual additions blocked.');
echo "PASS: Ten starters per department, used services/history preserved, fees cleaned, manual additions allowed.\n";
