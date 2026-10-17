-- Queuezy MySQL schema

CREATE TABLE IF NOT EXISTS users (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    fullname VARCHAR(35) NOT NULL,
    email VARCHAR(190) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    role VARCHAR(20) NOT NULL DEFAULT 'customer',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS departments (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    dept_name VARCHAR(120) NOT NULL UNIQUE,
    dept_code VARCHAR(10) NOT NULL UNIQUE,
    status ENUM('active', 'inactive', 'maintenance') NOT NULL DEFAULT 'active',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS services (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    dept_id INT UNSIGNED NOT NULL,
    service_name VARCHAR(160) NOT NULL,
    estimated_time_mins INT UNSIGNED NOT NULL DEFAULT 30,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_services_department_name (dept_id, service_name),
    CONSTRAINT fk_services_department FOREIGN KEY (dept_id) REFERENCES departments(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS queue (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    service_id INT UNSIGNED NOT NULL,
    ticket_number VARCHAR(30) NOT NULL UNIQUE,
    status VARCHAR(20) NOT NULL DEFAULT 'waiting',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    started_at DATETIME NULL,
    call_count INT UNSIGNED NOT NULL DEFAULT 0,
    completed_at DATETIME NULL,
    CONSTRAINT fk_queue_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_queue_service FOREIGN KEY (service_id) REFERENCES services(id) ON DELETE CASCADE,
    KEY idx_queue_status (status),
    KEY idx_queue_user_status (user_id, status),
    KEY idx_queue_service_status (service_id, status),
    KEY idx_queue_created_at (created_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS service_fees (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    service_id INT UNSIGNED NOT NULL UNIQUE,
    amount DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
    updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_service_fees_service FOREIGN KEY (service_id) REFERENCES services(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS queue_payments (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    queue_id INT UNSIGNED NOT NULL UNIQUE,
    amount DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
    status ENUM('pending', 'paid') NOT NULL DEFAULT 'pending',
    paid_at DATETIME NULL,
    CONSTRAINT fk_queue_payments_queue FOREIGN KEY (queue_id) REFERENCES queue(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS queue_ratings (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    queue_id INT UNSIGNED NOT NULL UNIQUE,
    user_id INT UNSIGNED NOT NULL,
    rating TINYINT UNSIGNED NOT NULL,
    feedback VARCHAR(500) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_queue_ratings_user (user_id)
) ENGINE=InnoDB;

