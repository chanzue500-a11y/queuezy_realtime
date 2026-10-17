<?php
require __DIR__ . '/../../PHPMailer/src/Exception.php';
require __DIR__ . '/../../PHPMailer/src/PHPMailer.php';
require __DIR__ . '/../../PHPMailer/src/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;

session_start();
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
require_once __DIR__ . '/../database/db.php';
require_once __DIR__ . '/../includes/profile_photo.php';

if (isset($_GET['guest']) && $_GET['guest'] === '1') {
  $_SESSION['guest_mode'] = true;
  unset($_SESSION['user_id'], $_SESSION['user_role'], $_SESSION['user_name'], $_SESSION['user_email']);
}
$isGuest = !isset($_SESSION['user_id']) && !empty($_SESSION['guest_mode']);

if (!$isGuest && !isset($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit;
}

$userRole = strtolower($_SESSION['user_role'] ?? $_SESSION['role'] ?? 'customer');

$userId = (int)($_SESSION['user_id'] ?? 0);
$userPhotoFilename = getUserProfilePhotoFilename($userId);
$userPhotoUrl = $userPhotoFilename ? '../uploads/profile/' . rawurlencode($userPhotoFilename) : '';
$userName = $isGuest ? 'Guest' : ($_SESSION['user_name'] ?? 'Customer');
$userEmail = $_SESSION['user_email'] ?? '';
$activeTicketNumber = null;

$msg = '';
$msgType = 'success';
$ratingMessage = '';
if (empty($_SESSION['rating_csrf_token'])) {
  $_SESSION['rating_csrf_token'] = bin2hex(random_bytes(32));
}
$ratingCsrfToken = $_SESSION['rating_csrf_token'];

if ($conn) {
  $conn->query("CREATE TABLE IF NOT EXISTS queue_ratings (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    queue_id INT UNSIGNED NOT NULL UNIQUE,
    user_id INT UNSIGNED NOT NULL,
    rating TINYINT UNSIGNED NOT NULL,
    feedback VARCHAR(500) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_queue_ratings_user (user_id)
  ) ENGINE=InnoDB");

  $feedbackColumnResult = $conn->query("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'queue_ratings' AND COLUMN_NAME = 'feedback' LIMIT 1");
  if ($feedbackColumnResult) {
    if ($feedbackColumnResult->num_rows === 0) {
      $conn->query('ALTER TABLE queue_ratings ADD COLUMN feedback VARCHAR(500) NULL AFTER rating');
    }
    $feedbackColumnResult->free();
  }
}

$ratingMessageQueueId = (int)($_POST['queue_id'] ?? 0);
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'rate_ticket') {
  if ($isGuest || !$conn || !hash_equals($ratingCsrfToken, (string)($_POST['csrf_token'] ?? ''))) {
    $ratingMessage = 'We could not verify this rating request. Please try again.';
  } else {
    $queueId = (int)($_POST['queue_id'] ?? 0);
    $rating = filter_var($_POST['rating'] ?? null, FILTER_VALIDATE_INT);
    $feedback = trim((string)($_POST['feedback'] ?? ''));
    $eligibleStmt = $conn->prepare("SELECT q.id FROM queue q JOIN queue_payments qp ON qp.queue_id = q.id WHERE q.id = ? AND q.user_id = ? AND qp.status = 'paid' LIMIT 1");
    if ($queueId <= 0 || $rating === false || $rating < 1 || $rating > 5 || strlen($feedback) > 50 || !$eligibleStmt) {
      $ratingMessage = strlen($feedback) > 50 ? 'Keep your comment under 50 characters.' : 'Choose a rating from 1 to 5 stars.';
    } else {
      $eligibleStmt->bind_param('ii', $queueId, $userId);
      $eligibleStmt->execute();
      $eligibleTicket = $eligibleStmt->get_result()->fetch_assoc();
      $eligibleStmt->close();

      if (!$eligibleTicket) {
        $ratingMessage = 'Only your paid tickets can be rated.';
      } else {
        $ratingStmt = $conn->prepare('INSERT INTO queue_ratings (queue_id, user_id, rating, feedback) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE rating = VALUES(rating), feedback = VALUES(feedback), updated_at = CURRENT_TIMESTAMP');
        if ($ratingStmt) {
          $feedbackValue = $feedback === '' ? null : $feedback;
          $ratingStmt->bind_param('iiis', $queueId, $userId, $rating, $feedbackValue);
          $ratingMessage = $ratingStmt->execute() ? 'Thank you. Your rating has been saved.' : 'We could not save your rating. Please try again.';
          $ratingStmt->close();
        } else {
          $ratingMessage = 'We could not save your rating. Please try again.';
        }
      }
    }
  }
}

if ($conn) {
  $accountStmt = $conn->prepare('SELECT fullname, email FROM users WHERE id = ? LIMIT 1');
    if ($accountStmt) {
        $accountStmt->bind_param('i', $userId);
        $accountStmt->execute();
        $account = $accountStmt->get_result()->fetch_assoc();
        if ($account) {
            $userName = $account['fullname'] ?? $userName;
            $userEmail = $account['email'] ?? $userEmail;
        }
        $accountStmt->close();
    }
      $activeCheck = $conn->prepare("SELECT ticket_number FROM queue WHERE user_id = ? AND status IN ('waiting', 'called', 'now_serving') ORDER BY id DESC LIMIT 1");
      if ($activeCheck) {
        $activeCheck->bind_param('i', $userId);
        $activeCheck->execute();
        $activeRow = $activeCheck->get_result()->fetch_assoc();
        $activeTicketNumber = $activeRow['ticket_number'] ?? null;
        $activeCheck->close();
      }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
  if ($_POST['action'] === 'upload_profile_photo' && !$isGuest) {
    $photoResult = saveUserProfilePhoto($userId, $_FILES['profile_photo'] ?? []);
    $msg = $photoResult['message'];
    $msgType = $photoResult['ok'] ? 'success' : 'error';
    if ($photoResult['ok']) {
      $userPhotoFilename = $photoResult['filename'];
      $userPhotoUrl = '../uploads/profile/' . rawurlencode($userPhotoFilename);
    }
  }

  if ($_POST['action'] === 'update_profile' && $conn && !$isGuest) {
    $newName = trim($_POST['full_name'] ?? '');
    $newEmail = trim($_POST['email'] ?? '');
    if ($newName === '') {
      $msg = 'Full name is required.';
      $msgType = 'error';
    } elseif (!preg_match('/^[A-Za-z ]{1,35}$/', $newName)) {
      $msg = 'Full name must contain letters and spaces only, with a maximum of 35 characters.';
      $msgType = 'error';
    } elseif (!filter_var($newEmail, FILTER_VALIDATE_EMAIL)) {
      $msg = 'Please enter a valid email address.';
      $msgType = 'error';
    } elseif ($newEmail !== $userEmail) {
      $emailCheck = $conn->prepare('SELECT id FROM users WHERE email = ? AND id <> ? LIMIT 1');
      if ($emailCheck) {
        $emailCheck->bind_param('si', $newEmail, $userId);
        $emailCheck->execute();
        $emailExists = $emailCheck->get_result()->fetch_assoc();
        $emailCheck->close();

        if ($emailExists) {
          $msg = 'That email address is already in use.';
          $msgType = 'error';
        } else {
          $otp = (string) random_int(100000, 999999);
          $mail = new PHPMailer(true);

          try {
            $mail->isSMTP();
            $mail->Host = 'smtp.gmail.com';
            $mail->SMTPAuth = true;
            $mail->Username = 'developerchan25@gmail.com';
            $mail->Password = 'ygxoadwjsodnopos';
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port = 587;
            $mail->setFrom('developerchan25@gmail.com', 'QUEUEZY');
            $mail->addAddress($newEmail, $newName);
            $mail->isHTML(false);
            $mail->Subject = 'Verify your new Queuezy email address';
            $mail->Body = "Hello {$newName},\n\nYour Queuezy email-change verification code is: {$otp}\n\nThis code expires in 10 minutes. Your email address will not change until you verify this code.";
            $mail->send();

            $_SESSION['pending_email_change'] = [
              'user_id' => $userId,
              'current_email' => $userEmail,
              'fullname' => $newName,
              'email' => $newEmail,
              'otp_hash' => password_hash($otp, PASSWORD_DEFAULT),
              'otp_expires_at' => time() + 600,
            ];
            $msg = 'A verification code was sent to ' . $newEmail . '. Your email will change after verification.';
          } catch (\Throwable $e) {
            $msg = 'We could not send a verification code. Please try again.';
            $msgType = 'error';
          }
        }
      } else {
        $msg = 'We could not check that email address. Please try again.';
        $msgType = 'error';
      }
    } else {
      $profileStmt = $conn->prepare('UPDATE users SET fullname = ? WHERE id = ?');
      if ($profileStmt) {
        $profileStmt->bind_param('si', $newName, $userId);
        if ($profileStmt->execute()) {
          $_SESSION['user_name'] = $newName;
          $userName = $newName;
          $msg = 'Your profile was updated.';
        } else {
          $msg = 'We could not update your profile.';
          $msgType = 'error';
        }
        $profileStmt->close();
      }
    }
  }

  if ($_POST['action'] === 'verify_email_change' && $conn && !$isGuest) {
    $pendingEmailChange = $_SESSION['pending_email_change'] ?? null;
    $otp = trim($_POST['otp'] ?? '');

    if (!$pendingEmailChange || (int) ($pendingEmailChange['user_id'] ?? 0) !== $userId) {
      $msg = 'Request an email change before entering a verification code.';
      $msgType = 'error';
    } elseif (time() > (int) ($pendingEmailChange['otp_expires_at'] ?? 0)) {
      unset($_SESSION['pending_email_change']);
      $msg = 'That verification code has expired. Submit your new email address again to get another code.';
      $msgType = 'error';
    } elseif (!preg_match('/^\d{6}$/', $otp)) {
      $msg = 'Enter the six-digit verification code.';
      $msgType = 'error';
    } elseif (!password_verify($otp, $pendingEmailChange['otp_hash'] ?? '')) {
      $msg = 'That verification code is incorrect.';
      $msgType = 'error';
    } else {
      $profileStmt = $conn->prepare('UPDATE users SET fullname = ?, email = ? WHERE id = ? AND email = ?');
      if ($profileStmt) {
        $profileStmt->bind_param('ssis', $pendingEmailChange['fullname'], $pendingEmailChange['email'], $userId, $pendingEmailChange['current_email']);
        if ($profileStmt->execute() && $profileStmt->affected_rows === 1) {
          $_SESSION['user_name'] = $pendingEmailChange['fullname'];
          $_SESSION['user_email'] = $pendingEmailChange['email'];
          $userName = $pendingEmailChange['fullname'];
          $userEmail = $pendingEmailChange['email'];
          unset($_SESSION['pending_email_change']);
          $msg = 'Your email address was verified and updated.';
        } else {
          $msg = 'We could not update your email address. It may already be in use, or your account details changed.';
          $msgType = 'error';
        }
        $profileStmt->close();
      }
    }
  }

  if ($_POST['action'] === 'cancel_email_change') {
    unset($_SESSION['pending_email_change']);
    $msg = 'The pending email change was cancelled.';
  }

  if ($_POST['action'] === 'change_password' && $conn) {
    $currentPassword = $_POST['current_password'] ?? '';
    $newPassword = $_POST['new_password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';
    $passwordStmt = $conn->prepare('SELECT password FROM users WHERE id = ? LIMIT 1');
    $passwordStmt?->bind_param('i', $userId);
    $passwordStmt?->execute();
    $passwordRow = $passwordStmt ? $passwordStmt->get_result()->fetch_assoc() : null;
    $passwordStmt?->close();
    $storedPassword = $passwordRow['password'] ?? '';
    $validCurrent = $storedPassword !== '' && (password_verify($currentPassword, $storedPassword) || hash_equals((string) $storedPassword, $currentPassword));
    if (!$validCurrent) {
      $msg = 'Your current password is incorrect.';
      $msgType = 'error';
    } elseif (strlen($newPassword) < 8 || strlen($newPassword) > 16) {
      $msg = 'Your new password must be 8 to 16 characters long and include an uppercase letter and a symbol.';
      $msgType = 'error';
    } elseif (!preg_match('/[A-Z]/', $newPassword) || !preg_match('/[^A-Za-z0-9]/', $newPassword)) {
      $msg = 'Your new password must include at least one uppercase letter and one symbol.';
      $msgType = 'error';
    } elseif ($newPassword !== $confirmPassword) {
      $msg = 'Your new passwords do not match.';
      $msgType = 'error';
    } else {
      $newHash = password_hash($newPassword, PASSWORD_DEFAULT);
      $updatePassword = $conn->prepare('UPDATE users SET password = ? WHERE id = ?');
      if ($updatePassword) {
        $updatePassword->bind_param('si', $newHash, $userId);
        $msg = $updatePassword->execute() ? 'Your password was changed.' : 'We could not change your password.';
        $msgType = $updatePassword->affected_rows >= 0 && $msg === 'Your password was changed.' ? 'success' : 'error';
        $updatePassword->close();
      }
    }
  }
}

// Handle Joining Queue Action (Inserts ticket into MySQL `queue` table)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
  if ($isGuest && in_array($_POST['action'], ['join_queue', 'join_other_queue', 'cancel_queue', 'update_profile', 'change_password'], true)) {
    $msg = 'Please sign in or create an account to use queue tickets.';
    $msgType = 'error';
  }

  if ($_POST['action'] === 'join_queue' && !$conn) {
    $msg = 'The queue service is temporarily unavailable. Please try again later.';
    $msgType = 'error';
  }

  if (!$isGuest && $_POST['action'] === 'join_queue' && $conn && $activeTicketNumber) {
    $msg = 'You already have an active ticket #' . $activeTicketNumber . '. Please wait until it is completed or cancel it before joining another queue.';
    $msgType = 'error';
  }

  if (!$isGuest && $_POST['action'] === 'join_queue' && $conn && !$activeTicketNumber) {
        $serviceId = (int)($_POST['service_id'] ?? 0);
        
    if ($serviceId <= 0) {
      $msg = 'Please select a valid service.';
      $msgType = 'error';
    } else {
            // Check service & dept details
                $sStmt = $conn->prepare("SELECT s.*, d.dept_code, d.dept_name, d.status AS dept_status FROM services s LEFT JOIN departments d ON s.dept_id = d.id WHERE s.id = ?");
            if ($sStmt) {
                $sStmt->bind_param("i", $serviceId);
                $sStmt->execute();
                $sRes = $sStmt->get_result();
                $serviceData = $sRes->fetch_assoc();
                $sStmt->close();

                if ($serviceData && ($serviceData['dept_status'] ?? 'active') !== 'active') {
                  $msg = 'This department is currently ' . ($serviceData['dept_status'] === 'maintenance' ? 'under maintenance' : 'inactive') . '. Please choose another service.';
                  $msgType = 'error';
                } elseif ($serviceData) {
                    $prefix = !empty($serviceData['dept_code']) ? strtoupper($serviceData['dept_code']) : 'Q';
                    
                    // Generate Next Ticket Number (e.g., R-029)
                    $maxQuery = $conn->query("SELECT MAX(id) as max_id FROM queue");
                    $nextId = 1;
                    if ($maxQuery && $maxRow = $maxQuery->fetch_assoc()) {
                        $nextId = ((int)$maxRow['max_id']) + 1;
                    }
                    $ticketNumber = sprintf("%s-%03d", $prefix, $nextId);

                    // Insert ticket into MySQL
                    $insertStmt = $conn->prepare("INSERT INTO queue (user_id, service_id, ticket_number, status) VALUES (?, ?, ?, 'waiting')");
                    if ($insertStmt) {
                        $insertStmt->bind_param("iis", $userId, $serviceId, $ticketNumber);
                        if ($insertStmt->execute()) {
                            header("Location: index.php?joined=1&ticket=" . urlencode($ticketNumber) . '#active-ticket-section');
                            exit;
                        } else {
                            $msg = "Error issuing ticket. Please try again.";
                            $msgType = "error";
                        }
                        $insertStmt->close();
                      } else {
                        $msg = 'We could not prepare your queue ticket. Please try again.';
                        $msgType = 'error';
                    }
                    } else {
                      $msg = 'That service is no longer available. Please choose another service.';
                      $msgType = 'error';
                }
                  } else {
                    $msg = 'We could not load that service. Please try again.';
                    $msgType = 'error';
            }
        }
    }

    if (!$isGuest && $_POST['action'] === 'join_other_queue' && !$conn) {
      $msg = 'The queue service is temporarily unavailable. Please try again later.';
      $msgType = 'error';
    }

    if (!$isGuest && $_POST['action'] === 'join_other_queue' && $conn && $activeTicketNumber) {
      $msg = 'You already have an active ticket #' . $activeTicketNumber . '. Please wait until it is completed or cancel it before joining another queue.';
      $msgType = 'error';
    }

    if (!$isGuest && $_POST['action'] === 'join_other_queue' && $conn && !$activeTicketNumber) {
      $otherServiceName = trim($_POST['other_service_name'] ?? '');
      $otherDepartmentId = (int)($_POST['other_department_id'] ?? 0);
      if ($otherServiceName === '' || strlen($otherServiceName) > 120) {
        $msg = 'Please enter an other service name up to 120 characters.';
        $msgType = 'error';
      } elseif ($otherDepartmentId <= 0) {
        $msg = 'Please select a department for your request.';
        $msgType = 'error';
      } else {
        $deptStmt = $conn->prepare("SELECT id, dept_code, status FROM departments WHERE id = ? LIMIT 1");
        $deptRow = null;
        $deptStmt?->bind_param('i', $otherDepartmentId);
        if ($deptStmt && $deptStmt->execute()) {
          $deptRow = $deptStmt->get_result()->fetch_assoc();
        }
        $deptStmt?->close();

        $otherDeptId = (int)($deptRow['id'] ?? 0);
        if ($otherDeptId <= 0 || ($deptRow['status'] ?? 'active') !== 'active') {
          $msg = 'That department is not currently available. Please choose another department.';
          $msgType = 'error';
          $otherDeptId = 0;
        }

        $serviceId = 0;
        if ($otherDeptId > 0) {
          $estimatedTime = 10;
          $createService = $conn->prepare('INSERT INTO services (dept_id, service_name, estimated_time_mins) VALUES (?, ?, ?)');
          if ($createService) {
            $createService->bind_param('isi', $otherDeptId, $otherServiceName, $estimatedTime);
            if ($createService->execute()) {
              $serviceId = $createService->insert_id;
            }
            $createService->close();
          }
        }

        if ($serviceId > 0) {
          $maxQuery = $conn->query('SELECT MAX(id) AS max_id FROM queue');
          $nextId = 1;
          if ($maxQuery && $maxRow = $maxQuery->fetch_assoc()) {
            $nextId = ((int)$maxRow['max_id']) + 1;
          }
          $ticketNumber = sprintf('O-%03d', $nextId);
          $insertOther = $conn->prepare("INSERT INTO queue (user_id, service_id, ticket_number, status) VALUES (?, ?, ?, 'waiting')");
          if ($insertOther) {
            $insertOther->bind_param('iis', $userId, $serviceId, $ticketNumber);
            if ($insertOther->execute()) {
              header('Location: index.php?joined=1&ticket=' . urlencode($ticketNumber) . '#active-ticket-section');
              exit;
            }
            $insertOther->close();
          }
        }

        $msg = 'We could not create your other service ticket. Please try again.';
        $msgType = 'error';
      }
    }

    if (!$isGuest && $_POST['action'] === 'cancel_queue' && $conn) {
        $queueId = (int)($_POST['queue_id'] ?? 0);
        if ($queueId > 0) {
            $cStmt = $conn->prepare("UPDATE queue SET status = 'cancelled' WHERE id = ? AND user_id = ?");
            if ($cStmt) {
                $cStmt->bind_param("ii", $queueId, $userId);
          $cStmt->execute();
          $cancelled = $cStmt->affected_rows > 0;
                $cStmt->close();
          header("Location: index.php?cancelled=" . ($cancelled ? '1' : '0'));
                exit;
            }
        }
    }
}

// Query Active MySQL Data for Customer Dashboard
$deptList = [];
$serviceList = [];
$servicesByDepartment = [];
$myActiveTicket = null;
$myRecentTicket = null;
$billHistory = [];
$nowServingTicket = null;

if ($conn) {
  $conn->query("CREATE TABLE IF NOT EXISTS service_fees (
    id INT AUTO_INCREMENT PRIMARY KEY,
    service_id INT NOT NULL UNIQUE,
    amount DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_service_fees_service FOREIGN KEY (service_id) REFERENCES services(id) ON DELETE CASCADE
  ) ENGINE=InnoDB");
    $conn->query("CREATE TABLE IF NOT EXISTS queue_payments (
      id INT AUTO_INCREMENT PRIMARY KEY,
      queue_id INT NOT NULL UNIQUE,
      amount DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
      status ENUM('pending', 'paid') NOT NULL DEFAULT 'pending',
      paid_at DATETIME NULL,
      CONSTRAINT fk_queue_payments_queue FOREIGN KEY (queue_id) REFERENCES queue(id) ON DELETE CASCADE
    ) ENGINE=InnoDB");

    // 1. Departments from MySQL
    $dRes = $conn->query("SELECT d.*, (SELECT COUNT(*) FROM services s WHERE s.dept_id = d.id) AS service_count FROM departments d ORDER BY d.id ASC");
    if ($dRes) {
        while ($dRow = $dRes->fetch_assoc()) {
            $deptList[] = $dRow;
        }
    }

    // 2. Services from MySQL
    $sRes = $conn->query("SELECT s.*, d.dept_name, d.dept_code, COALESCE(d.status, 'active') AS dept_status, COALESCE(sf.amount, 0) AS service_fee FROM services s LEFT JOIN departments d ON s.dept_id = d.id LEFT JOIN service_fees sf ON sf.service_id = s.id WHERE d.dept_code IS NULL OR d.dept_code <> 'O' ORDER BY s.id ASC");
    if ($sRes) {
        while ($sRow = $sRes->fetch_assoc()) {
            $serviceList[] = $sRow;
        }
    }

    $servicesByDepartment = [];
    foreach ($serviceList as $service) {
      $departmentKey = (string)($service['dept_id'] ?? 'unassigned');
      $servicesByDepartment[$departmentKey]['name'] = $service['dept_name'] ?? 'General Services';
      $servicesByDepartment[$departmentKey]['code'] = $service['dept_code'] ?? 'Q';
      $servicesByDepartment[$departmentKey]['services'][] = $service;
    }

    // 3. Customer's current active queue ticket from MySQL
    $tStmt = $conn->prepare("SELECT q.*, s.service_name, s.estimated_time_mins, d.dept_name, d.dept_code, COALESCE(sf.amount, 0) AS bill_amount, COALESCE(qp.status, 'pending') AS payment_status, qp.paid_at, qr.rating AS customer_rating, qr.feedback AS customer_feedback FROM queue q JOIN services s ON s.id = q.service_id JOIN departments d ON d.id = s.dept_id LEFT JOIN service_fees sf ON sf.service_id = q.service_id LEFT JOIN queue_payments qp ON qp.queue_id = q.id LEFT JOIN queue_ratings qr ON qr.queue_id = q.id WHERE q.user_id = ? AND q.status IN ('waiting', 'called', 'now_serving') ORDER BY q.id DESC LIMIT 1");
    if (!$tStmt) {
      $tStmt = $conn->prepare("SELECT q.*, s.service_name, s.estimated_time_mins, d.dept_name, d.dept_code, COALESCE(sf.amount, 0) AS bill_amount, COALESCE(qp.status, 'pending') AS payment_status, qp.paid_at, NULL AS customer_rating, NULL AS customer_feedback FROM queue q JOIN services s ON s.id = q.service_id JOIN departments d ON d.id = s.dept_id LEFT JOIN service_fees sf ON sf.service_id = q.service_id LEFT JOIN queue_payments qp ON qp.queue_id = q.id WHERE q.user_id = ? AND q.status IN ('waiting', 'called', 'now_serving') ORDER BY q.id DESC LIMIT 1");
    }
    if ($tStmt) {
        $tStmt->bind_param("i", $userId);
        $tStmt->execute();
        $tRes = $tStmt->get_result();
        $myActiveTicket = $tRes->fetch_assoc();
        $tStmt->close();
    }

      // 4. Most recent finished ticket for the customer's ticket history
      $recentStmt = $conn->prepare("SELECT q.*, s.service_name, s.estimated_time_mins, d.dept_name, d.dept_code, COALESCE(sf.amount, 0) AS bill_amount, COALESCE(qp.status, 'pending') AS payment_status, qp.paid_at, qr.rating AS customer_rating, qr.feedback AS customer_feedback FROM queue q JOIN services s ON q.service_id = s.id JOIN departments d ON s.dept_id = d.id LEFT JOIN service_fees sf ON sf.service_id = s.id LEFT JOIN queue_payments qp ON qp.queue_id = q.id LEFT JOIN queue_ratings qr ON qr.queue_id = q.id WHERE q.user_id = ? AND q.status IN ('completed', 'cancelled', 'no_show') ORDER BY q.id DESC LIMIT 1");
      if (!$recentStmt) {
        $recentStmt = $conn->prepare("SELECT q.*, s.service_name, s.estimated_time_mins, d.dept_name, d.dept_code, COALESCE(sf.amount, 0) AS bill_amount, COALESCE(qp.status, 'pending') AS payment_status, qp.paid_at, NULL AS customer_rating, NULL AS customer_feedback FROM queue q JOIN services s ON q.service_id = s.id JOIN departments d ON s.dept_id = d.id LEFT JOIN service_fees sf ON sf.service_id = s.id LEFT JOIN queue_payments qp ON qp.queue_id = q.id WHERE q.user_id = ? AND q.status IN ('completed', 'cancelled', 'no_show') ORDER BY q.id DESC LIMIT 1");
      }
      if ($recentStmt) {
        $recentStmt->bind_param('i', $userId);
        $recentStmt->execute();
        $recentResult = $recentStmt->get_result();
        $myRecentTicket = $recentResult->fetch_assoc();
        $recentStmt->close();
      }

      $billHistoryStmt = $conn->prepare("SELECT q.id AS queue_id, q.ticket_number, q.status AS queue_status, s.service_name, d.dept_name, COALESCE(qp.amount, sf.amount, 0) AS amount, COALESCE(qp.status, 'pending') AS payment_status, qp.paid_at, qr.rating AS customer_rating, qr.feedback FROM queue q JOIN services s ON s.id = q.service_id JOIN departments d ON d.id = s.dept_id LEFT JOIN queue_payments qp ON qp.queue_id = q.id LEFT JOIN service_fees sf ON sf.service_id = q.service_id LEFT JOIN queue_ratings qr ON qr.queue_id = q.id WHERE q.user_id = ? AND q.status IN ('completed', 'cancelled', 'no_show') ORDER BY q.id DESC LIMIT 50");
      if (!$billHistoryStmt) {
        $billHistoryStmt = $conn->prepare("SELECT q.id AS queue_id, q.ticket_number, q.status AS queue_status, s.service_name, d.dept_name, COALESCE(qp.amount, sf.amount, 0) AS amount, COALESCE(qp.status, 'pending') AS payment_status, qp.paid_at, NULL AS customer_rating, NULL AS feedback FROM queue q JOIN services s ON s.id = q.service_id JOIN departments d ON d.id = s.dept_id LEFT JOIN queue_payments qp ON qp.queue_id = q.id LEFT JOIN service_fees sf ON sf.service_id = q.service_id WHERE q.user_id = ? AND q.status IN ('completed', 'cancelled', 'no_show') ORDER BY q.id DESC LIMIT 50");
      }
      if ($billHistoryStmt) {
        $billHistoryStmt->bind_param('i', $userId);
        $billHistoryStmt->execute();
        $billHistoryResult = $billHistoryStmt->get_result();
        while ($billRecord = $billHistoryResult->fetch_assoc()) {
          $billHistory[] = $billRecord;
        }
        $billHistoryStmt->close();
      }

      // 5. Overall Now Serving Ticket from MySQL
    $nsRes = $conn->query("SELECT q.ticket_number, d.dept_name FROM queue q JOIN services s ON q.service_id = s.id JOIN departments d ON s.dept_id = d.id WHERE q.status IN ('called', 'now_serving') ORDER BY q.id DESC LIMIT 1");
    if ($nsRes && $nsRow = $nsRes->fetch_assoc()) {
        $nowServingTicket = $nsRow;
    }
}

// Calculate position ahead in queue
$activeStatusLabel = '';
if ($myActiveTicket) {
  $activeStatusLabel = in_array($myActiveTicket['status'], ['called', 'now_serving'], true)
    ? 'Calling'
    : ucfirst(str_replace('_', ' ', $myActiveTicket['status']));
}
$waitingAhead = 0;
$waitingTimeAhead = 0;
if ($myActiveTicket && $conn) {
  $posStmt = $conn->prepare("SELECT COUNT(*) AS ahead, COALESCE(SUM(s.estimated_time_mins), 0) AS wait_ahead_mins FROM queue q JOIN services s ON s.id = q.service_id WHERE q.status = 'waiting' AND q.id < ?");
    if ($posStmt) {
    $posStmt->bind_param("i", $myActiveTicket['id']);
        $posStmt->execute();
        $posRes = $posStmt->get_result();
        if ($posRow = $posRes->fetch_assoc()) {
            $waitingAhead = (int)$posRow['ahead'];
            $waitingTimeAhead = (int)$posRow['wait_ahead_mins'];
        }
        $posStmt->close();
    }
}
$estimatedWaitMins = $myActiveTicket
  ? $waitingTimeAhead + (int)$myActiveTicket['estimated_time_mins']  
  : 0;
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>QUEUEZY</title>
  <link rel="icon" type="image/png" href="../assets/images/mascot.png">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <script src="https://cdn.tailwindcss.com"></script>
  <link rel="stylesheet" href="../assets/css/admin-queuezy.css">
  <script src="../assets/js/feather.min.js"></script>
  <style>
    input[type="radio"][name="rating"]:checked + span {
      color: #facc15 !important;
    }
    .rating-star-selected {
      color: #facc15 !important;
    }
    .rating-star-group:has(input[value="1"]:checked) label:nth-child(-n+1) span,
    .rating-star-group:has(input[value="2"]:checked) label:nth-child(-n+2) span,
    .rating-star-group:has(input[value="3"]:checked) label:nth-child(-n+3) span,
    .rating-star-group:has(input[value="4"]:checked) label:nth-child(-n+4) span,
    .rating-star-group:has(input[value="5"]:checked) label:nth-child(-n+5) span {
      color: #facc15 !important;
    }
    body {
      font-family: 'DM Sans', 'Inter', -apple-system, sans-serif;
      background: #f8f7fc;
      color: #1e1b4b;
      margin: 0;
      padding: 0;
    }
    .queue-layout {
      display: flex;
      min-height: 100vh;
      width: 100%;
    }
    .queue-sidebar {
      width: 256px;
      height: 100vh;
      max-height: 100vh;
      padding: 28px 18px;
      background: #ffffff;
      border-right: 1px solid #ece9f4;
      position: fixed;
      top: 0; bottom: 0; left: 0;
      z-index: 40;
      display: flex;
      flex-direction: column;
      box-sizing: border-box;
      overflow: hidden;
    }
    .queue-main {
      margin-left: 256px;
      min-height: 100vh;
      flex: 1;
      display: flex;
      flex-direction: column;
      min-width: 0;
    }
    .queue-topbar {
      height: 76px;
      padding: 0 clamp(20px, 3vw, 48px);
      background: #ffffff;
      border-bottom: 1px solid #ece9f4;
      display: flex;
      align-items: center;
      justify-content: space-between;
      position: sticky;
      top: 0;
      z-index: 30;
    }
    .queue-menu {
      display: none;
      align-items: center;
      justify-content: center;
      border: 1px solid #ece9f4;
      border-radius: 10px;
      background: transparent;
      color: #7c3aed;
      cursor: pointer;
      padding: 8px;
    }
    .queue-menu svg,
    .queue-sidebar-toggle svg { width: 18px; height: 18px; stroke-width: 2.5; }
    .queue-menu span,
    .queue-sidebar-toggle span { font-size: 22px; line-height: 1; font-weight: 700; }
    .queue-sidebar-backdrop {
      display: none;
    }
    .queue-nav {
      display: flex;
      flex-direction: column;
      gap: 6px;
      margin-top: 16px;
    }
    .queue-nav a {
      display: flex;
      align-items: center;
      gap: 12px;
      padding: 12px 14px;
      border-radius: 12px;
      color: #64748b;
      font-size: 13px;
      font-weight: 600;
      text-decoration: none;
      transition: all 0.2s;
    }
    .queue-nav a.is-active, .queue-nav a:hover {
      background: #f3e8ff;
      color: #7c3aed;
    }
    .queue-nav a:focus-visible,
    .queue-sidebar-toggle:focus-visible,
    .queue-menu:focus-visible {
      outline: 3px solid #c4b5fd;
      outline-offset: 2px;
    }
    .queue-brand {
      display: flex;
      align-items: center;
      gap: 8px;
      font-size: 20px;
      font-weight: 800;
      color: #0f172a;
      text-decoration: none;
    }
    .queue-brand-logo {
      display: block;
      flex: 0 0 64px;
      width: 64px !important;
      height: 64px !important;
      max-width: 64px;
      max-height: 64px;
      object-fit: contain;
      border-radius: 12px;
    }
    .queue-brand-logo--processing { opacity: 0; }
    .queue-brand-name { color: #0f172a; font-weight: 900; text-transform: uppercase; white-space: nowrap; }
    .queue-brand-name span { color: #6d28d9; }
    .queue-brand-row {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 12px;
    }
    .queue-sidebar-toggle {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      width: 34px;
      height: 34px;
      border: 1px solid #ece9f4;
      border-radius: 10px;
      background: #ffffff;
      color: #7c3aed;
      cursor: pointer;
    }
    .queue-sidebar-toggle:hover,
    .queue-menu:hover { background: #f3e8ff; color: #6d28d9; }
    .queue-brand-mark {
      width: 36px;
      height: 36px;
      border-radius: 12px;
      background: #7c3aed;
      color: #ffffff;
      display: flex;
      align-items: center;
      justify-content: center;
      font-weight: 900;
    }
    .queue-sidebar__label {
      font-size: 10px;
      font-weight: 800;
      text-transform: uppercase;
      letter-spacing: 1px;
      color: #94a3b8;
      margin-top: 24px;
      margin-bottom: 8px;
    }
    #receiptPrintArea {
      display: none !important;
    }
    .queue-sidebar-section-toggle {
      width: 100%;
      border: 0;
      background: transparent;
      text-align: left;
      cursor: pointer;
      display: flex;
      align-items: center;
      justify-content: space-between;
      font: inherit;
    }
    .queue-sidebar-section-toggle svg {
      width: 14px;
      height: 14px;
      transition: transform 0.2s ease;
    }
    .queue-sidebar-section-toggle[aria-expanded="false"] svg {
      transform: rotate(-90deg);
    }
    .queue-sidebar-section.is-collapsed {
      display: none;
    }
    .queue-sidebar__account {
      margin-top: auto;
    }
    .queue-sidebar__footer {
      margin-top: 0;
      padding-top: 16px;
      border-top: 1px solid #f1f0f7;
      display: flex;
      align-items: center;
      gap: 12px;
      font-size: 12px;
    }
    .queue-sidebar__footer strong,
    .queue-sidebar__footer small {
      display: block;
      line-height: 1.35;
    }
    .queue-help-icon {
      width: 36px;
      height: 36px;
      flex: 0 0 36px;
      display: flex;
      align-items: center;
      justify-content: center;
      border-radius: 10px;
      background: #f8fafc;
      color: #7c3aed;
    }
    .profile-icon {
      width: 42px;
      height: 42px;
      flex: 0 0 42px;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      border-radius: 50%;
      background: #f1f5f9;
      color: #111827;
    }
    .profile-icon svg { width: 28px; height: 28px; stroke-width: 2.3; }
    .profile-icon i { font-size: 20px; }
    .profile-icon img { width: 100%; height: 100%; border-radius: inherit; object-fit: cover; }
    .queue-avatar {
      width: 36px;
      height: 36px;
      border-radius: 50%;
      background: #f3e8ff;
      color: #7c3aed;
      display: flex;
      align-items: center;
      justify-content: center;
      font-weight: 800;
    }
    .queue-user {
      display: flex;
      align-items: center;
      gap: 10px;
      padding: 8px 14px 8px 8px;
      border: 1px solid #e2e8f0;
      border-radius: 14px;
      background: #f8fafc;
      color: inherit;
      text-decoration: none;
      cursor: pointer;
    }
    .queue-user:hover { border-color: #c4b5fd; background: #f3e8ff; }
    .queue-user strong,
    .queue-user small {
      display: block;
      line-height: 1.3;
    }
    .queue-user strong { color: #0f172a; font-size: 12px; }
    .queue-user small { color: #7c3aed; font-size: 9px; font-weight: 700; text-transform: uppercase; margin-top: 2px; }
    .queue-user .profile-icon {
      width: 36px;
      height: 36px;
      flex-basis: 36px;
    }
    .notification-sidebar-link { position: relative; }
    .sidebar-notification-dot {
      width: 7px;
      height: 7px;
      margin-left: auto;
      border-radius: 50%;
      background: #dc2626;
      border: 1px solid #ffffff;
    }
    .queue-topbar__actions {
      margin-left: auto;
      display: flex;
      align-items: center;
      gap: 16px;
      position: relative;
    }
    .queue-icon-button {
      position: relative;
      width: 42px;
      height: 42px;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      border: 1px solid #e2e8f0;
      border-radius: 12px;
      background: #f8fafc;
      color: #7c3aed;
      cursor: pointer;
    }
    .queue-icon-button i { font-size: 17px; }
    .queue-icon-button:hover { background: #f3e8ff; color: #6d28d9; }
    .queue-icon-button b {
      position: absolute;
      top: 8px;
      right: 9px;
      width: 5px;
      height: 5px;
      border-radius: 50%;
      background: #ef4444;
    }
    .notification-panel {
      position: absolute;
      top: 54px;
      right: 0;
      z-index: 60;
      width: 300px;
      padding: 16px;
      border: 1px solid #e2e8f0;
      border-radius: 14px;
      background: #ffffff;
      color: #0f172a;
      box-shadow: 0 12px 28px rgba(15, 23, 42, 0.16);
      opacity: 0;
      visibility: hidden;
      pointer-events: none;
      transform: translateY(-6px);
      transition: opacity 180ms ease, transform 180ms ease, visibility 180ms ease;
    }
    .notification-panel.is-visible { opacity: 1; visibility: visible; pointer-events: auto; transform: translateY(0); }
    .notification-panel h3 { margin: 0; font-size: 14px; font-weight: 800; }
    .notification-item { display: flex; gap: 10px; margin-top: 14px; font-size: 12px; line-height: 1.4; }
    .notification-item i { color: #7c3aed; margin-top: 2px; }
    .notification-item strong { display: block; }
    .notification-item span { color: #64748b; }
    .queue-ticket-button,
    a[data-page-link="services"] { box-shadow: none; }
    .queue-ticket-button:hover,
    a[data-page-link="services"]:hover { box-shadow: none; }
    .queue-ticket-button.is-blocked { opacity: 0.5; cursor: not-allowed; }
    a.queue-join-button {
      position: relative;
      isolation: isolate;
      overflow: hidden;
      border: 2px solid transparent;
      background: linear-gradient(100deg, #982fe8, #5147df) padding-box,
        conic-gradient(from var(--queue-edge-angle), #982fe8 0deg 250deg, #f5d0fe 285deg, #ffffff 315deg, #982fe8 350deg 360deg) border-box !important;
      box-shadow: 0 0 16px rgba(124, 58, 237, 0.28);
      animation: queueEdgeRotation 2.8s linear infinite;
    }
    a.queue-join-button:hover,
    a.queue-join-button:focus-visible {
      box-shadow: 0 0 22px rgba(168, 85, 247, 0.52);
    }
    @property --queue-edge-angle {
      syntax: '<angle>';
      initial-value: 0deg;
      inherits: false;
    }
    @keyframes queueEdgeRotation {
      to { --queue-edge-angle: 360deg; }
    }
    @media (prefers-reduced-motion: reduce) {
      a.queue-join-button { animation: none; }
    }
    .other-service-card { position: relative; overflow: visible !important; }
    .other-service-card[data-hover-message]::after {
      content: attr(data-hover-message);
      position: absolute;
      left: 0;
      top: calc(100% + 8px);
      width: 100%;
      box-sizing: border-box;
      padding: 10px 12px;
      border-radius: 10px;
      background: #1e1b4b;
      color: #ffffff;
      font-size: 11px;
      font-weight: 700;
      line-height: 1.35;
      text-align: center;
      opacity: 0;
      pointer-events: none;
      transform: translateY(-5px);
      transition: opacity 0.18s ease, transform 0.18s ease;
      z-index: 3;
    }
    .other-service-card[data-hover-message]:hover::after,
    .other-service-card[data-hover-message]:focus-within::after { opacity: 1; transform: translateY(0); }
    body.dark-mode .other-service-card[data-hover-message]::after { background: #1e1b4b; color: #ffffff; }
    body.dark-mode .other-service-card h3 { color: #f8fafc !important; }
    body.dark-mode .other-service-card p { color: #cbd5e1 !important; }
    body.dark-mode .other-service-card input { background: #0f172a; border-color: #475569; color: #f8fafc; }
    body.dark-mode .other-service-card input::placeholder { color: #94a3b8; }
    .queue-content {
      max-width: 1360px;
      width: 100%;
      box-sizing: border-box;
      padding: 36px clamp(20px, 3.5vw, 48px);
      margin: 0 auto;
      flex: 1;
    }
    .print-receipt-button {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      gap: 0.35rem;
      border: 1px solid #c4b5fd;
      background: linear-gradient(135deg, #f5f3ff, #ede9fe);
      color: #4c1d95;
      font-weight: 800;
      font-size: 0.6rem;
      letter-spacing: 0.02em;
      text-transform: uppercase;
      border-radius: 0.7rem;
      padding: 0.45rem 0.7rem;
      cursor: pointer;
      transition: transform 0.2s ease, box-shadow 0.2s ease;
      box-shadow: 0 8px 18px rgba(124, 58, 237, 0.12);
    }
    .print-receipt-button:hover,
    .print-receipt-button:focus-visible {
      transform: translateY(-1px);
      box-shadow: 0 12px 24px rgba(124, 58, 237, 0.18);
      outline: none;
    }
    .receipt-print-area {
      display: flex;
      justify-content: center;
      margin-top: 18px;
    }
    .receipt-sheet {
      width: 100%;
      max-width: 290px;
      margin: 0 auto;
      padding: 12px 12px 14px;
      background: #fffdf7;
      color: #111827;
      box-sizing: border-box;
      border: 1.5px solid #111827;
      border-radius: 0;
      font-family: 'Courier New', Courier, monospace;
      box-shadow: none;
      letter-spacing: 0;
      line-height: 1.28;
    }
    .receipt-kiosk-header {
      text-align: center;
      margin-bottom: 10px;
    }
    .receipt-kiosk-brand {
      font-size: 26px;
      line-height: 1;
      font-weight: 900;
      letter-spacing: 0.06em;
      color: #111827;
      text-transform: uppercase;
    }
    .receipt-kiosk-brand small {
      font-size: 9px;
      letter-spacing: 0.18em;
      font-weight: 700;
      color: #111827;
      text-transform: uppercase;
      display: block;
      margin-top: 5px;
    }
    .receipt-kiosk-address {
      margin-top: 8px;
      padding-top: 7px;
      padding-bottom: 7px;
      border-top: 2px dashed #111827;
      border-bottom: 2px dashed #111827;
      font-size: 10px;
      line-height: 1.55;
      text-align: center;
      color: #111827;
      font-weight: 700;
    }
    .receipt-order-meta {
      margin-top: 7px;
      display: flex;
      justify-content: space-between;
      gap: 8px;
      font-size: 10.5px;
      line-height: 1.35;
      color: #111827;
      font-weight: 700;
    }
    .receipt-divider {
      border-top: 2px dashed #111827;
      border-bottom: 2px dashed #111827;
      margin: 7px 0 8px;
      padding: 5px 0;
    }
    .receipt-table {
      width: 100%;
      border-collapse: collapse;
      font-size: 10px;
      color: #111827;
    }
    .receipt-table th,
    .receipt-table td {
      padding: 1px 0;
      vertical-align: top;
      font-weight: 700;
    }
    .receipt-table th {
      color: #111827;
      font-size: 9px;
      text-transform: uppercase;
      letter-spacing: 0.04em;
      font-weight: 800;
    }
    .receipt-table td:nth-child(2),
    .receipt-table th:nth-child(2) {
      text-align: right;
      white-space: nowrap;
    }
    .receipt-table tbody tr td:first-child {
      padding-right: 10px;
    }
    .receipt-totals {
      margin-top: 8px;
      padding-top: 5px;
      font-size: 10px;
      color: #111827;
      border-top: 2px solid #111827;
    }
    .receipt-total-row {
      display: flex;
      justify-content: space-between;
      gap: 12px;
      margin-bottom: 1px;
      line-height: 1.3;
    }
    .receipt-total-row.is-grand {
      margin-top: 6px;
      padding-top: 5px;
      border-top: 2px dashed #111827;
      font-size: 11px;
      font-weight: 900;
      color: #111827;
    }
    .receipt-payment {
      margin-top: 8px;
      padding-top: 5px;
      border-top: 2px solid #111827;
      font-size: 10px;
      color: #111827;
    }
    .receipt-payment strong {
      display: block;
      font-size: 10px;
      color: #111827;
      margin-top: 1px;
      font-weight: 800;
    }
    .receipt-footer {
      margin-top: 8px;
      padding-top: 7px;
      border-top: 2px dashed #111827;
      font-size: 8.5px;
      line-height: 1.45;
      text-align: center;
      color: #111827;
    }
    @media print {
      body * {
        visibility: hidden !important;
      }
      .receipt-print-area,
      .receipt-print-area * {
        visibility: visible !important;
      }
      .receipt-print-area {
        display: block !important;
        position: fixed;
        inset: 0;
        background: #ffffff;
        z-index: 2147483647;
        padding: 20px 0;
      }
      .receipt-sheet {
        max-width: 320px;
        margin: 0 auto;
        padding: 20px 18px;
        background: white;
        color: #0f172a;
        box-sizing: border-box;
        border: 1px solid #cbd5e1;
        border-radius: 0;
        box-shadow: none;
      }
      .queue-layout,
      .queue-sidebar,
      .queue-sidebar-backdrop,
      .queue-main,
      .queue-topbar,
      .queue-content,
      .toast-container,
      .confirm-modal,
      .queue-page,
      .stat-card-luxury,
      .notification-panel {
        display: none !important;
        visibility: hidden !important;
      }
    }
    @media (max-width: 900px) {
      .queue-menu { display: inline-flex; align-items: center; justify-content: center; }
      .queue-sidebar { transform: translateX(-100%); transition: transform 0.25s; }
      .queue-sidebar.is-open { transform: translateX(0); box-shadow: 0 10px 40px rgba(0,0,0,0.2); }
      .queue-main { margin-left: 0; }
      .queue-sidebar-backdrop {
        position: fixed;
        inset: 0;
        z-index: 35;
        background: rgba(15, 23, 42, 0.35);
      }
      .queue-sidebar-backdrop.is-visible { display: block; }
    }
    .stat-card-luxury {
      background: #ffffff;
      border: 1px solid #ece9f4;
      border-radius: 20px;
      padding: 24px;
      transition: all 0.28s cubic-bezier(0.4, 0, 0.2, 1);
      box-shadow: 0 4px 20px -2px rgba(57, 35, 97, 0.04);
      position: relative;
      overflow: hidden;
      opacity: 1;
      transform: translateY(0);
    }
    .queue-page#bill-section .stat-card-luxury,
    #bill-section .stat-card-luxury {
      opacity: 0;
      transform: translateY(12px);
      animation: billCardEnter 420ms cubic-bezier(0.22, 1, 0.36, 1) forwards;
    }
    @keyframes billCardEnter {
      from {
        opacity: 0;
        transform: translateY(12px);
      }
      to {
        opacity: 1;
        transform: translateY(0);
      }
    }
    .stat-card-luxury:hover {
      transform: none;
      box-shadow: none;
      border-color: #ece9f4;
    }
    .stat-card-luxury::after {
      content: '';
      position: absolute;
      top: 0; left: 0; right: 0;
      height: 4px;
      background: linear-gradient(90deg, #7c3aed, #a855f7);
      opacity: 0;
      transition: opacity 0.25s ease;
    }
    .stat-card-luxury:hover::after { opacity: 0; }

    .live-pulse-dot {
      width: 8px;
      height: 8px;
      border-radius: 50%;
      background: #10b981;
      display: inline-block;
      box-shadow: 0 0 0 0 rgba(16, 185, 129, 0.7);
      animation: livePulse 1.6s infinite;
    }
    @keyframes livePulse {
      0% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(16, 185, 129, 0.7); }
      70% { transform: scale(1); box-shadow: 0 0 0 8px rgba(16, 185, 129, 0); }
      100% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(16, 185, 129, 0); }
    }

    .toast-container {
      position: fixed;
      bottom: 24px; right: 24px;
      z-index: 1000;
      display: flex; flex-direction: column; gap: 10px;
      pointer-events: none;
    }
    .toast-item {
      background: #1e1b4b; color: #ffffff;
      padding: 14px 20px; border-radius: 16px;
      font-size: 13px; font-weight: 600;
      display: flex; align-items: center; gap: 12px;
      box-shadow: 0 12px 30px rgba(0, 0, 0, 0.2);
      pointer-events: auto;
    }
    .confirm-modal {
      position: fixed;
      inset: 0;
      z-index: 1100;
      display: none;
      align-items: center;
      justify-content: center;
      padding: 20px;
      background: rgba(15, 23, 42, 0.48);
    }
    .confirm-modal.is-visible { display: flex; }
    .confirm-dialog {
      width: min(380px, 100%);
      padding: 24px;
      border: 1px solid #e2e8f0;
      border-radius: 18px;
      background: #ffffff;
      color: #0f172a;
      box-shadow: 0 20px 50px rgba(15, 23, 42, 0.22);
    }
    .confirm-dialog h2 { margin: 0; font-size: 18px; font-weight: 800; }
    .customer-call-overlay {
      position: fixed;
      inset: 0;
      z-index: 1200;
      display: none;
      align-items: center;
      justify-content: center;
      padding: 20px;
      background: rgba(15, 23, 42, 0.72);
    }
    .customer-call-overlay.is-visible { display: flex; }
    .customer-call-dialog {
      width: min(430px, 100%);
      padding: 28px 22px 22px;
      border: 1px solid #475569;
      border-radius: 22px;
      background: #1e293b;
      color: #ffffff;
      text-align: center;
      box-shadow: 0 24px 70px rgba(0, 0, 0, 0.45);
    }
    .customer-call-dialog__ticket {
      margin: 18px 0;
      padding: 24px 12px;
      border: 1px solid #475569;
      border-radius: 18px;
      background: #0f172a;
      font-size: clamp(42px, 12vw, 72px);
      font-weight: 900;
      line-height: 1;
      letter-spacing: -1px;
    }
    .customer-call-dialog__stop {
      width: 100%;
      border: 0;
      border-radius: 12px;
      padding: 13px 16px;
      background: #dc2626;
      color: #ffffff;
      font-weight: 800;
      cursor: pointer;
    }
    .confirm-dialog p { margin: 8px 0 20px; color: #64748b; font-size: 13px; }
    .confirm-actions { display: flex; justify-content: flex-end; gap: 10px; }
    .confirm-actions button { border: 0; border-radius: 10px; padding: 10px 16px; font-weight: 700; cursor: pointer; }
    .confirm-cancel { background: #f1f5f9; color: #475569; }
    .confirm-continue { background: #7c3aed; color: #ffffff; }
    .confirm-continue.is-logging-out { cursor: wait; opacity: 0.8; }
    .confirm-continue.is-logging-out::before {
      content: '';
      display: inline-block;
      width: 12px;
      height: 12px;
      margin-right: 7px;
      border: 2px solid rgba(255, 255, 255, 0.45);
      border-top-color: #ffffff;
      border-radius: 50%;
      vertical-align: -2px;
      animation: logoutSpinner 650ms linear infinite;
    }
    @keyframes logoutSpinner { to { transform: rotate(360deg); } }
    .faq-item {
      border: 1px solid #e2e8f0;
      border-radius: 14px;
      padding: 14px 16px;
      background: #ffffff;
    }
    .faq-item summary {
      cursor: pointer;
      color: #0f172a;
      font-size: 14px;
      font-weight: 800;
    }
    .faq-item p {
      margin: 10px 0 0;
      color: #475569;
      font-size: 13px;
      line-height: 1.6;
    }
    .customer-ticket-number {
      display: block;
      width: auto;
      text-align: left;
      line-height: 0.9;
      margin: 0;
    }
    .customer-queue-position {
      display: flex;
      flex-direction: column;
      align-items: center;
      text-align: center;
    }
    .customer-queue-position form { width: 100%; }
    body.dark-mode .faq-item { background: #1e293b; border-color: #475569; }
    body.dark-mode .faq-item summary { color: #f8fafc; }
    body.dark-mode .faq-item p { color: #cbd5e1; }
    body.dark-mode .confirm-dialog { background: #1e293b; border-color: #475569; color: #f8fafc; }
    body.dark-mode .confirm-dialog p { color: #cbd5e1; }
    body.dark-mode .confirm-cancel { background: #334155; color: #e2e8f0; }
    .hidden { display: none !important; }
    .queue-page { display: none !important; opacity: 0; transform: translateY(8px); }
    .queue-page.is-current { display: block !important; animation: queuePageEnter 320ms cubic-bezier(0.22, 1, 0.36, 1) both; }
    .queue-page.is-current.grid { display: grid !important; }
    @keyframes queuePageEnter { from { opacity: 0; transform: translateY(8px); } to { opacity: 1; transform: translateY(0); } }
    @media (prefers-reduced-motion: reduce) {
      .notification-panel { transition: none; }
      .queue-page.is-current { animation: none; opacity: 1; transform: none; }
    }
    .queue-page.is-hidden { display: none !important; }
    body.dark-mode { background: #0f172a; color: #e5e7eb; }
    body.dark-mode .queue-content { background: #0f172a; }
    body.dark-mode .queue-topbar,
    body.dark-mode .queue-sidebar,
    body.dark-mode .stat-card-luxury,
    body.dark-mode .queue-brand-row button,
    body.dark-mode #settings-section form,
    body.dark-mode #settings-section > div > div { background: #1e293b; border-color: #334155; }
    body.dark-mode .queue-topbar { border-color: #334155; }
    body.dark-mode .queue-sidebar { border-color: #334155; }
    body.dark-mode h1,
    body.dark-mode h2,
    body.dark-mode h3,
    body.dark-mode label { color: #f9fafb; }
    body.dark-mode .text-slate-900,
    body.dark-mode .text-slate-800,
    body.dark-mode .queue-brand { color: #f9fafb; }
    body.dark-mode .queue-brand-name { color: #ffffff !important; }
    body.dark-mode .queue-brand-name span { color: #c4b5fd !important; }
    body.dark-mode p,
    body.dark-mode .text-slate-500,
    body.dark-mode .text-slate-400 { color: #9ca3af; }
    body.dark-mode .queue-nav a { color: #cbd5e1; }
    body.dark-mode .queue-nav a.is-active,
    body.dark-mode .queue-nav a:hover { background: #312e81; color: #ffffff; }
    body.dark-mode .queue-sidebar__label { color: #94a3b8; }
    body.dark-mode input { background: #0f172a; border-color: #475569; color: #f9fafb; }
    body.dark-mode .text-slate-600,
    body.dark-mode .text-slate-700,
    body.dark-mode .text-gray-500,
    body.dark-mode .text-gray-600,
    body.dark-mode .text-gray-700 { color: #cbd5e1 !important; }
    body.dark-mode .text-slate-900,
    body.dark-mode .text-slate-800,
    body.dark-mode .text-gray-800 { color: #f8fafc !important; }
    body.dark-mode .profile-icon { background: #334155; color: #f8fafc; }
    body.dark-mode .queue-menu,
    body.dark-mode .queue-sidebar-toggle { background: #1e293b; border-color: #475569; color: #c4b5fd; }
    body.dark-mode .queue-menu:hover,
    body.dark-mode .queue-sidebar-toggle:hover { background: #312e81; color: #ffffff; }
    .queue-layout.sidebar-collapsed .queue-sidebar { transform: translateX(-100%); transition: transform 0.25s; }
    .queue-sidebar.is-collapsed { transform: translateX(-100%); transition: transform 0.25s; }
    .queue-layout.sidebar-collapsed .queue-main { margin-left: 0; }
    .queue-layout.sidebar-collapsed .queue-menu { display: inline-flex; }
    body.dark-mode .queue-sidebar__footer strong,
    body.dark-mode .queue-user strong { color: #f9fafb; }
    body.dark-mode .queue-sidebar__footer small,
    body.dark-mode .queue-user small { color: #94a3b8; }
    body.dark-mode .queue-user { background: #1e293b; border-color: #475569; }
    body.dark-mode .queue-icon-button { background: #1e293b; border-color: #475569; color: #c4b5fd; }
    body.dark-mode .queue-icon-button:hover { background: #312e81; color: #ffffff; }
    body.dark-mode .notification-panel { background: #1e293b; border-color: #475569; color: #f8fafc; box-shadow: 0 12px 28px rgba(0, 0, 0, 0.35); }
    body.dark-mode .notification-item span { color: #cbd5e1; }
    body.dark-mode .stat-card-luxury .bg-purple-50 { background: #3b2d52 !important; }
    body.dark-mode .stat-card-luxury .bg-indigo-50 { background: #293754 !important; }
    body.dark-mode .stat-card-luxury .bg-emerald-50 { background: #183d35 !important; }
    body.dark-mode .stat-card-luxury .bg-amber-50 { background: #493c1e !important; }
    body.dark-mode .stat-card-luxury .text-purple-600 { color: #c4b5fd !important; }
    body.dark-mode .stat-card-luxury .text-indigo-600 { color: #bfdbfe !important; }
    body.dark-mode .stat-card-luxury .text-emerald-600 { color: #6ee7b7 !important; }
    body.dark-mode .queue-ticket-button,
    body.dark-mode a[data-page-link="services"] { box-shadow: none !important; }
    body.dark-mode .queue-ticket-button:hover,
    body.dark-mode a[data-page-link="services"]:hover { box-shadow: none !important; }
    .customer-department-services {
      max-height: 0;
      opacity: 0;
      overflow: hidden;
      transition: max-height 360ms cubic-bezier(0.4, 0, 0.2, 1), opacity 240ms ease;
    }
    .customer-department-services.is-expanded { opacity: 1; }
    .customer-department-chevron {
      color: #334155;
      transition: none;
    }
    .customer-service-row {
      min-height: 76px;
    }
    .service-price {
      display: inline-flex;
      align-items: center;
      min-height: 30px;
      padding: 5px 10px;
      border: 1px solid #dbe3ee;
      border-radius: 8px;
      background: #f8fafc;
      color: #334155;
      font-size: 12px;
      font-weight: 800;
      white-space: nowrap;
    }
    .customer-service-row .queue-ticket-button {
      min-width: 112px;
      justify-content: center;
      border-radius: 10px;
      box-shadow: none;
    }
    body.dark-mode .customer-department-chevron { color: #e2e8f0 !important; }
    body.dark-mode .service-price { background: #1e293b; border-color: #475569; color: #e2e8f0; }
    @media (max-width: 900px) {
      .queue-menu { display: inline-flex; }
      .queue-layout:has(.queue-sidebar.is-open) .queue-menu { display: none; }
      .queue-layout.sidebar-collapsed .queue-sidebar.is-open { transform: translateX(0); }
    }
  </style>
</head>
<script src="../assets/js/password-toggle.js"></script>
<body class="queue-app">
<script>
  window.queuezyCustomerThemeKey = <?= json_encode('queuezy-dark-mode-' . ($isGuest ? 'guest' : $userId)) ?>;
  if (localStorage.getItem(window.queuezyCustomerThemeKey) === 'true') document.body.classList.add('dark-mode');
</script>
<div class="queue-layout">

  <!-- Sidebar aligned with Admin layout -->
  <aside class="queue-sidebar" id="queueSidebar">
    <div class="queue-brand-row">
      <div class="queue-brand" role="img" aria-label="Queuezy"><img class="queue-brand-logo queue-brand-logo--processing" id="queueBrandLogo" src="../assets/images/mascot.png" alt="" width="64" height="64"><span class="queue-brand-name">Queue<span>zy</span></span></div>
      <button class="queue-sidebar-toggle" id="queueSidebarToggle" type="button" aria-label="Collapse sidebar"><span aria-hidden="true">&lt;</span></button>
    </div>
    <script>
      (() => {
        const logo = document.getElementById('queueBrandLogo');
        const removeCheckerboard = () => {
          if (!logo?.naturalWidth) return;
          const canvas = document.createElement('canvas');
          canvas.width = logo.naturalWidth;
          canvas.height = logo.naturalHeight;
          const context = canvas.getContext('2d');
          context.drawImage(logo, 0, 0);
          const image = context.getImageData(0, 0, canvas.width, canvas.height);
          for (let index = 0; index < image.data.length; index += 4) {
            const red = image.data[index];
            const green = image.data[index + 1];
            const blue = image.data[index + 2];
            const brightness = (red + green + blue) / 3;
            const saturation = Math.max(red, green, blue) - Math.min(red, green, blue);
            if (saturation < 18 && brightness < 180) image.data[index + 3] = 0;
          }
          context.putImageData(image, 0, 0);
          logo.src = canvas.toDataURL('image/png');
          logo.classList.remove('queue-brand-logo--processing');
        };
        logo?.addEventListener('load', removeCheckerboard, { once: true });
        if (logo?.complete) removeCheckerboard();
      })();
    </script>
    <div class="queue-sidebar-section" data-sidebar-section="service">
      <nav class="queue-nav" aria-label="Customer navigation">
        <a href="index.php"><i data-feather="home"></i><span>Home</span></a>
        <a href="#services-section"><i data-feather="layers"></i><span>Services</span></a>
        <a href="#notifications" class="notification-sidebar-link" id="notificationSidebarLink"<?php if ($myActiveTicket): ?> data-notification-key="<?= htmlspecialchars($myActiveTicket['ticket_number'] . '|' . $myActiveTicket['status'] . '|' . ($myActiveTicket['call_count'] ?? 0), ENT_QUOTES) ?>"<?php endif; ?>><i data-feather="bell"></i><span>Notifications</span><?php if ($myActiveTicket): ?><b class="sidebar-notification-dot" aria-label="Unread notification"></b><?php endif; ?></a>
      </nav>
    </div>
    <div class="queue-sidebar-section" data-sidebar-section="ticket">
      <nav class="queue-nav" aria-label="My queue navigation">
        <a href="#active-ticket-section"><i data-feather="clock"></i><span>My Queue Ticket</span></a>
        <a href="#bill-section"><i data-feather="credit-card"></i><span>My Bill</span></a>
        <a href="#settings-section"><i data-feather="settings"></i><span>Settings</span></a>
        <a href="#faqs"><i data-feather="help-circle"></i><span>FAQs</span></a>
      </nav>
    </div>
    <div class="queue-sidebar__account">
      <div class="queue-sidebar-section" data-sidebar-section="account">
        <div class="queue-sidebar__footer">
          <?php if ($userPhotoUrl): ?>
          <div class="profile-icon" aria-label="Profile"><img src="<?= htmlspecialchars($userPhotoUrl, ENT_QUOTES) ?>" alt="Profile photo"></div>
          <?php else: ?>
          <div class="profile-icon" aria-label="Default profile icon"><i data-feather="user"></i></div>
          <?php endif; ?>
          <div style="flex:1;">
            <strong><?= htmlspecialchars($userName) ?></strong>
            <small>Customer</small>
          </div>
        </div>
        <div style="padding: 12px 18px 0 18px;">
          <a href="../logout.php" class="logout-link" style="color: #ef4444; font-weight: 700; text-decoration: none; display: flex; align-items: center; gap: 8px; font-size: 13px;">
            <i data-feather="log-out" style="width: 16px; height: 16px; color: #ef4444;"></i>
            <span>Log Out</span>
          </a>
        </div>
      </div>
    </div>
  </aside>
  <script>if (typeof feather !== 'undefined') feather.replace();</script>
  <div class="queue-sidebar-backdrop" id="queueSidebarBackdrop"></div>
  <div class="customer-call-overlay" id="customerCallOverlay" role="alertdialog" aria-modal="true" aria-labelledby="customerCallTitle">
    <div class="customer-call-dialog">
      <p class="text-xs font-bold uppercase tracking-widest text-blue-200">Your ticket is calling</p>
      <h2 id="customerCallTitle" class="mt-2 text-2xl font-black">Front Desk</h2>
      <p id="customerCallService" class="mt-1 text-sm text-slate-300">Please proceed to the front desk.</p>
      <div id="customerCallTicket" class="customer-call-dialog__ticket">--</div>
      <button id="stopCustomerCallOverlay" type="button" class="customer-call-dialog__stop">Stop Ringing</button>
    </div>
  </div>

  <!-- Main Content -->
  <main class="queue-main">
    <header class="queue-topbar">
      <button class="queue-menu" id="queueMenu" type="button" aria-label="Open sidebar"><span aria-hidden="true">&gt;</span></button>
      <div class="queue-topbar__actions">
        <button class="queue-icon-button" id="notificationButton" type="button" aria-label="Notifications" aria-expanded="false"><i class="fa-solid fa-bell"></i><?php if ($myActiveTicket): ?><b id="topNotificationDot" aria-label="Unread notification"></b><?php endif; ?></button>
        <div class="notification-panel" id="notificationPanel" role="status" aria-live="polite">
          <h3>Notifications</h3>
          <?php if ($myActiveTicket): ?>
          <?php if (in_array($myActiveTicket['status'], ['called', 'now_serving'], true)): ?>
          <div class="notification-item"><i class="fa-solid fa-bullhorn"></i><div><strong id="notificationTitle">Your ticket is now calling</strong><span id="notificationMessage">Ticket #<?= htmlspecialchars($myActiveTicket['ticket_number']) ?> is now calling. Please proceed to the service desk.</span></div></div>
          <?php else: ?>
          <div class="notification-item"><i class="fa-solid fa-ticket"></i><div><strong id="notificationTitle">Your queue is active</strong><span id="notificationMessage">Ticket #<?= htmlspecialchars($myActiveTicket['ticket_number']) ?> is <?= htmlspecialchars(str_replace('_', ' ', $myActiveTicket['status'])) ?>.</span></div></div>
          <?php endif; ?>
          <?php else: ?>
          <div class="notification-item"><i class="fa-solid fa-circle-check"></i><div><strong>No new notifications</strong><span>You are all caught up.</span></div></div>
          <?php endif; ?>
        </div>
        <a href="#settings-section" class="queue-user" data-page-link="settings" aria-label="Open customer settings">
          <?php if ($userPhotoUrl): ?>
          <span class="profile-icon" aria-label="Profile">
            <img src="<?= htmlspecialchars($userPhotoUrl, ENT_QUOTES) ?>" alt="Profile photo">
          </span>
          <?php else: ?>
          <span class="profile-icon" aria-label="Default profile icon"><i class="fa-solid fa-user"></i></span>
          <?php endif; ?>
          <span><strong><?= htmlspecialchars($userName) ?></strong><small>Customer</small></span>
        </a>
      </div>
    </header>

    <div class="queue-content">
      
      <!-- Top Welcome Ribbon -->
      <div class="queue-page" data-page="home">
      <div class="mb-8 flex flex-wrap items-center justify-between gap-4">
        <div>
          <h1 class="text-2xl sm:text-3xl font-black text-slate-900 tracking-tight flex items-center gap-3">
            <span>Welcome, <?= htmlspecialchars($userName) ?>!</span>
            
          </h1>
          <p class="text-sm text-slate-500 mt-1">Join a queue line remotely, view active tickets, and track counter calls in real time.</p>
        </div>

        <a href="#services-section" data-page-link="services" class="queue-join-button ml-auto flex-shrink-0 px-5 py-2.5 rounded-xl bg-gradient-to-r from-purple-600 to-indigo-600 text-white font-bold text-sm hover:from-purple-700 hover:to-indigo-700 transition inline-flex items-center gap-2">
          <i data-feather="plus-circle" class="w-4 h-4"></i>
          <span>Join a Queue Line</span>
        </a>
      </div>

      <?php if ($msg): ?>
      <div class="queue-page mb-6 p-4 rounded-2xl border <?= $msgType === 'error' ? 'bg-red-50 border-red-200 text-red-700' : 'bg-emerald-50 border-emerald-200 text-emerald-700' ?> font-semibold text-xs flex items-center gap-2" data-page="home">
        <i data-feather="<?= $msgType === 'error' ? 'alert-circle' : 'check-circle' ?>" class="w-4 h-4"></i>
        <span><?= htmlspecialchars($msg) ?></span>
      </div>
      <?php endif; ?>

      <!-- Executive Metric Cards -->
      <div class="queue-page grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-5 mb-8" data-page="home">
        <div class="stat-card-luxury">
          <div class="flex items-center justify-between">
            <span class="text-xs font-bold text-slate-400 uppercase tracking-wider">Your Active Ticket</span>
            <div class="w-10 h-10 rounded-2xl bg-purple-50 text-purple-600 flex items-center justify-center">
              <i class="fa-solid fa-ticket" style="font-size: 20px;" aria-hidden="true"></i>
            </div>
          </div>
          <div class="mt-3">
            <h3 class="text-3xl font-black text-purple-600">
              <?= $myActiveTicket ? '#' . htmlspecialchars($myActiveTicket['ticket_number']) : 'No Ticket' ?>
            </h3>
            <p class="text-xs text-slate-500 mt-2">
              <?= $myActiveTicket ? htmlspecialchars($myActiveTicket['service_name']) : 'Select a service below to join' ?>
            </p>
          </div>
        </div>

        <div class="stat-card-luxury">
          <div class="flex items-center justify-between">
            <span class="text-xs font-bold text-slate-400 uppercase tracking-wider">Queue Position</span>
            <div class="w-10 h-10 rounded-2xl bg-indigo-50 text-indigo-600 flex items-center justify-center">
              <i class="fa-solid fa-users" style="font-size: 20px;" aria-hidden="true"></i>
            </div>
          </div>
          <div class="mt-3">
            <h3 class="text-3xl font-black text-indigo-600">
              <?= $myActiveTicket ? '#' . ($waitingAhead + 1) : '--' ?>
            </h3>
            <p class="text-xs text-slate-500 mt-2">
              <?= $myActiveTicket ? $waitingAhead . ' customer(s) ahead of you' : 'Join a service line' ?>
            </p>
          </div>
        </div>

        <div class="stat-card-luxury">
          <div class="flex items-center justify-between">
            <span class="text-xs font-bold text-slate-400 uppercase tracking-wider">Now Serving</span>
            <div class="w-10 h-10 rounded-2xl bg-emerald-50 text-emerald-600 flex items-center justify-center">
              <i class="fa-solid fa-bullhorn" style="font-size: 20px;" aria-hidden="true"></i>
            </div>
          </div>
          <div class="mt-3">
            <h3 class="text-3xl font-black text-emerald-600">
              <?= $nowServingTicket ? '#' . htmlspecialchars($nowServingTicket['ticket_number']) : 'None' ?>
            </h3>
            <p class="text-xs text-slate-500 mt-2">
              <?= $nowServingTicket ? htmlspecialchars($nowServingTicket['dept_name']) . ' Desk' : 'Counter active' ?>
            </p>
          </div>
        </div>

        <div class="stat-card-luxury">
          <div class="flex items-center justify-between">
            <span class="text-xs font-bold text-slate-400 uppercase tracking-wider">Available Services</span>
            <div class="w-10 h-10 rounded-2xl bg-amber-50 text-amber-600 flex items-center justify-center">
              <i class="fa-solid fa-layer-group" style="font-size: 20px;" aria-hidden="true"></i>
            </div>
          </div>
          <div class="mt-3">
            <h3 class="text-3xl font-black text-slate-900"><?= count($serviceList) ?></h3>
            <p class="text-xs text-slate-500 mt-2">Services ready in catalog</p>
          </div>
        </div>
      </div>
      </div>

      <!-- Active Ticket Banner (If customer has a ticket in queue) -->
      <?php if ($myActiveTicket || $myRecentTicket): ?>
      <div class="queue-page bg-gradient-to-br from-purple-900 via-indigo-900 to-slate-900 text-white rounded-3xl p-6 sm:p-8 shadow-xl mb-10 border border-purple-800/40 relative overflow-hidden" id="active-ticket-section" data-page="ticket">
        <div class="flex flex-col md:flex-row items-center md:items-center justify-between gap-6 relative z-10">
          <div>
            <?php $displayTicket = $myActiveTicket ?: $myRecentTicket; ?>
            <span class="inline-flex items-center gap-2 <?= $myActiveTicket ? 'bg-purple-500/20 text-purple-300 border-purple-500/30' : 'bg-slate-500/20 text-slate-300 border-slate-400/30' ?> text-xs font-bold px-3 py-1 rounded-full border mb-3">
              <?php if ($myActiveTicket): ?><span class="live-pulse-dot"></span><?php endif; ?>
              <?= $myActiveTicket ? 'Status: ' : 'Recent Ticket: ' ?><?= $myActiveTicket ? htmlspecialchars($activeStatusLabel) : ucfirst(str_replace('_', ' ', $displayTicket['status'])) ?>
            </span>
            <h2 class="text-xs uppercase font-extrabold tracking-widest text-purple-300"><?= $myActiveTicket ? 'Your Live Queue Ticket' : 'Your Recent Ticket' ?></h2>
            <div class="customer-ticket-number text-5xl sm:text-6xl font-black text-white tracking-tight mt-1 mb-2">
              #<?= htmlspecialchars($displayTicket['ticket_number']) ?>
            </div>
            <p class="text-sm text-purple-200">
              Department: <strong><?= htmlspecialchars($displayTicket['dept_name']) ?></strong> | Service: <strong><?= htmlspecialchars($displayTicket['service_name']) ?></strong>
            </p>
          </div>

          <div class="customer-queue-position bg-white/10 backdrop-blur-md rounded-2xl p-5 border border-white/10 text-center min-w-[220px]">
            <?php if ($myActiveTicket): ?>
            <div class="text-xs font-bold text-purple-200 uppercase tracking-wider mb-1">Queue Position</div>
            <div class="text-3xl font-black text-white">#<?= $waitingAhead + 1 ?></div>
            <p class="text-xs text-purple-300 mt-1">Est. Wait: ~<?= $estimatedWaitMins ?> mins</p>

            <form method="post" class="mt-4 confirm-action" data-confirm-message="Cancel your queue ticket #<?= htmlspecialchars($myActiveTicket['ticket_number'], ENT_QUOTES) ?>?">
              <input type="hidden" name="action" value="cancel_queue">
              <input type="hidden" name="queue_id" value="<?= $myActiveTicket['id'] ?>">
              <button type="submit" class="w-full py-2 rounded-xl bg-red-500/20 hover:bg-red-500/30 text-red-200 font-bold text-xs border border-red-400/40 transition">
                Cancel Queue Ticket
              </button>
            </form>
            <?php else: ?>
            <div class="text-xs font-bold text-slate-300 uppercase tracking-wider mb-1">Ticket Status</div>
            <div class="text-2xl font-black text-white">Finished</div>
            <p class="text-xs text-slate-300 mt-1">This ticket is no longer active.</p>
            <?php endif; ?>
          </div>
        </div>
      </div>
      <?php endif; ?>

      <?php $billTicket = $myActiveTicket ?: $myRecentTicket; ?>
      <?php if ($billTicket): ?>
      <?php $billAmount = (float)($billTicket['bill_amount'] ?? 0); ?>
      <?php $billIsCancelled = ($billTicket['status'] ?? '') === 'cancelled'; ?>
      <?php $billIsNoShow = ($billTicket['status'] ?? '') === 'no_show'; ?>
      <section class="queue-page mb-10" id="bill-section" data-page="bill">
        <div class="stat-card-luxury p-6 sm:p-8">
          <div class="flex flex-col items-start gap-4 sm:flex-row sm:justify-between">
            <div>
              <span class="text-xs font-extrabold uppercase tracking-widest text-purple-600">My Bill</span>
              <h2 class="mt-2 text-2xl font-black text-slate-900"><?= htmlspecialchars($billTicket['service_name']) ?></h2>
              <p class="mt-1 text-sm text-slate-500">Ticket #<?= htmlspecialchars($billTicket['ticket_number']) ?> · <?= htmlspecialchars($billTicket['dept_name']) ?></p>
            </div>
            <div class="text-left">
              <?php if ($billIsCancelled || $billIsNoShow): ?>
                <p class="text-xs font-bold uppercase tracking-wider text-red-500">Status</p>
                <p class="mt-1 text-3xl font-black text-red-600"><?= $billIsNoShow ? 'No Show' : 'Canceled' ?></p>
              <?php else: ?>
                <p class="text-xs font-bold uppercase tracking-wider text-slate-400">Amount due</p>
                <p class="mt-1 text-3xl font-black text-slate-900">&#8369;<?= number_format($billAmount, 2) ?></p>
              <?php endif; ?>
            </div>
          </div>
          <div class="mt-5 flex flex-col gap-3">
            <div class="flex items-center gap-2 rounded-xl px-4 py-3 text-sm font-semibold <?= $billIsCancelled || $billIsNoShow ? 'bg-red-50 text-red-700' : (($billTicket['payment_status'] ?? 'pending') === 'paid' ? 'bg-emerald-50 text-emerald-700' : 'bg-amber-50 text-amber-700') ?>">
              <i data-feather="<?= $billIsCancelled || $billIsNoShow ? 'x-circle' : (($billTicket['payment_status'] ?? 'pending') === 'paid' ? 'check-circle' : 'info') ?>" class="h-4 w-4"></i>
              <?php if ($billIsCancelled): ?>
                This ticket was canceled. No payment is required.
              <?php elseif ($billIsNoShow): ?>
                No Show. No payment is required.
              <?php elseif (($billTicket['payment_status'] ?? 'pending') === 'paid'): ?>
                Paid<?= !empty($billTicket['paid_at']) ? ' on ' . htmlspecialchars($billTicket['paid_at']) : '' ?>
              <?php else: ?>
                <?= $billAmount > 0 ? 'Please settle this amount at the service desk.' : 'The fee for this service has not been configured yet.' ?>
              <?php endif; ?>
            </div>
          </div>
          <?php if (!$billIsCancelled && !$billIsNoShow): ?>
          <div class="mt-5 border-t border-slate-200 pt-4">
            <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
              <div class="flex-1">
                <?php if (!empty($billTicket['customer_rating'])): ?>
                  <p class="text-xs font-bold text-slate-600">Your service rating</p>
                  <div class="mt-1 text-xl text-amber-500" aria-label="You rated this service <?= (int)$billTicket['customer_rating'] ?> out of 5 stars">
                    <?php for ($star = 1; $star <= 5; $star++): ?><span aria-hidden="true"><?= $star <= (int)$billTicket['customer_rating'] ? '&#9733;' : '&#9734;' ?></span><?php endfor; ?>
                  </div>
                  <?php if (!empty($billTicket['customer_feedback'])): ?><p class="mt-1 text-sm text-slate-600"><?= htmlspecialchars($billTicket['customer_feedback']) ?></p><?php endif; ?>
                <?php elseif (($billTicket['payment_status'] ?? '') === 'paid'): ?>
                  <form method="post" class="max-w-lg">
                    <input type="hidden" name="action" value="rate_ticket">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($ratingCsrfToken, ENT_QUOTES) ?>">
                    <input type="hidden" name="queue_id" value="<?= (int)$billTicket['id'] ?>">
                    <fieldset>
                      <legend class="text-sm font-bold text-slate-800">Rate this service</legend>
                      <div class="rating-star-group mt-1 flex gap-1">
                        <?php for ($star = 1; $star <= 5; $star++): ?>
                        <label class="cursor-pointer">
                          <input class="peer sr-only" type="radio" name="rating" value="<?= $star ?>" required aria-label="<?= $star ?> star<?= $star === 1 ? '' : 's' ?>">
                          <span class="inline-block text-2xl text-slate-400 transition peer-checked:text-yellow-400 peer-focus-visible:outline peer-focus-visible:outline-2 peer-focus-visible:outline-purple-600" aria-hidden="true">&#9733;</span>
                        </label>
                        <?php endfor; ?>
                      </div>
                    </fieldset>
                    <label class="mt-2 block text-xs font-semibold text-slate-700" for="currentBillFeedback<?= (int)$billTicket['id'] ?>">Comment (optional)</label>
                    <textarea id="currentBillFeedback<?= (int)$billTicket['id'] ?>" name="feedback" maxlength="50" rows="2" class="mt-1 w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-700" placeholder="Share a little about your experience"></textarea>
                    <button type="submit" class="mt-3 rounded-lg bg-purple-600 px-4 py-2 text-xs font-bold text-white hover:bg-purple-700">Submit rating</button>
                    <?php if ($ratingMessage !== '' && $ratingMessageQueueId === (int)$billTicket['id']): ?>
                      <p class="mt-2 text-xs <?= str_starts_with($ratingMessage, 'Thank you') ? 'text-emerald-700' : 'text-rose-600' ?>" role="status"><?= htmlspecialchars($ratingMessage) ?></p>
                    <?php endif; ?>
                  </form>
                <?php else: ?>
                  <p class="text-sm font-bold text-slate-700">Rate this service</p>
                  <p class="mt-1 text-xs text-slate-500">Rating opens after payment is complete.</p>
                  <div class="mt-1 flex gap-1 text-2xl text-slate-300" aria-label="Rating available after payment">
                    <?php for ($star = 1; $star <= 5; $star++): ?><span aria-hidden="true">&#9734;</span><?php endfor; ?>
                  </div>
                <?php endif; ?>
              </div>

            </div>
          </div>
          <?php endif; ?>
        </div>
      </section>
      <?php else: ?>
      <section class="queue-page mb-10" id="bill-section" data-page="bill">
        <div class="stat-card-luxury p-8 sm:p-10">
          <div class="flex flex-col items-center justify-center text-center py-4">
            <h2 class="text-2xl font-black text-slate-900">No bill yet.</h2>
            <p class="mt-2 max-w-md text-sm text-slate-500">Once you have an active or completed service ticket, your bill will appear here.</p>
          </div>
        </div>
      </section>
      <?php endif; ?>

      <?php $previousBills = array_filter($billHistory, static fn($billRecord) => (int)$billRecord['queue_id'] !== (int)($billTicket['id'] ?? 0)); ?>
      <section class="queue-page mb-10" data-page="bill">
        <div class="mb-4 flex items-center justify-between gap-3">
          <div>
            <h2 class="text-xl font-black text-slate-900">Bill history</h2>
          
          </div>
          <span class="text-xs font-bold text-slate-500"><?= count($previousBills) ?> bill<?= count($previousBills) === 1 ? '' : 's' ?></span>
        </div>
        <?php if (!$previousBills): ?>
        <p class="rounded-xl border border-slate-200 bg-white p-5 text-sm text-slate-500">No previous bills yet.</p>
        <?php else: ?>
        <div class="space-y-3">
          <?php foreach ($previousBills as $billRecord): ?>
          <?php $recordIsNoPayment = in_array($billRecord['queue_status'], ['cancelled', 'no_show'], true); ?>
          <article class="grid grid-cols-[minmax(0,1fr)_auto] items-center gap-x-4 gap-y-3 rounded-xl border <?= $billRecord['payment_status'] === 'paid' ? 'border-emerald-200' : 'border-slate-200' ?> bg-white p-5 shadow-sm">
            <div class="min-w-0">
              <h3 class="text-sm font-black text-slate-900"><?= htmlspecialchars($billRecord['service_name']) ?></h3>
              <p class="mt-1 text-xs text-slate-500">Ticket #<?= htmlspecialchars($billRecord['ticket_number']) ?> · <?= htmlspecialchars($billRecord['dept_name']) ?></p>
              <span class="mt-2 inline-flex rounded-full border px-3 py-1 text-xs font-bold <?= $billRecord['queue_status'] === 'completed' ? 'border-emerald-200 bg-emerald-50 text-emerald-700' : 'border-slate-200 bg-slate-50 text-slate-600' ?>"><?= htmlspecialchars(ucfirst(str_replace('_', ' ', $billRecord['queue_status']))) ?></span>
              <?php if (!empty($billRecord['paid_at'])): ?><p class="mt-1 text-xs text-slate-500">Paid <?= htmlspecialchars($billRecord['paid_at']) ?></p><?php endif; ?>
            </div>
            <div class="shrink-0 text-right">
              <?php if ($recordIsNoPayment): ?>
                <p class="text-sm font-bold text-slate-500">No payment</p>
              <?php else: ?>
                <p class="text-lg font-black <?= $billRecord['payment_status'] === 'paid' ? 'text-emerald-700' : 'text-slate-900' ?>">&#8369;<?= number_format((float)$billRecord['amount'], 2) ?></p>
                <p class="text-xs font-bold <?= $billRecord['payment_status'] === 'paid' ? 'text-emerald-700' : 'text-amber-700' ?>"><?= $billRecord['payment_status'] === 'paid' ? 'Paid' : 'Payment pending' ?></p>
              <?php endif; ?>
            </div>
            <?php if ($billRecord['payment_status'] === 'paid'): ?>
            <div class="col-span-2 flex items-center justify-between gap-4 border-t border-slate-200 pt-3">
              <?php if (!empty($billRecord['customer_rating'])): ?>
                <div class="flex-1">
                  <p class="text-xs font-bold text-slate-600">Your service rating</p>
                  <div class="mt-1 text-xl text-amber-500" aria-label="You rated this service <?= (int)$billRecord['customer_rating'] ?> out of 5 stars">
                    <?php for ($star = 1; $star <= 5; $star++): ?><span aria-hidden="true"><?= $star <= (int)$billRecord['customer_rating'] ? '&#9733;' : '&#9734;' ?></span><?php endfor; ?>
                  </div>
                  <?php if (!empty($billRecord['feedback'])): ?><p class="mt-1 text-sm text-slate-600"><?= htmlspecialchars($billRecord['feedback']) ?></p><?php endif; ?>
                </div>
              <?php else: ?>
                <form method="post" class="w-full">
                  <input type="hidden" name="action" value="rate_ticket">
                  <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($ratingCsrfToken, ENT_QUOTES) ?>">
                  <input type="hidden" name="queue_id" value="<?= (int)$billRecord['queue_id'] ?>">
                  <div class="flex flex-wrap items-end justify-between gap-3">
                    <fieldset>
                      <legend class="text-xs font-bold text-slate-700">Rate this service</legend>
                      <div class="rating-star-group mt-1 flex gap-1">
                        <?php for ($star = 1; $star <= 5; $star++): ?>
                        <label class="cursor-pointer">
                          <input class="peer sr-only" type="radio" name="rating" value="<?= $star ?>" required aria-label="<?= $star ?> star<?= $star === 1 ? '' : 's' ?>">
                          <span class="inline-block text-2xl text-slate-400 transition peer-checked:text-yellow-400 peer-focus-visible:outline peer-focus-visible:outline-2 peer-focus-visible:outline-purple-600" aria-hidden="true">&#9733;</span>
                        </label>
                        <?php endfor; ?>
                      </div>
                    </fieldset>
                    <button type="submit" class="rounded-lg bg-purple-600 px-4 py-2 text-xs font-bold text-white hover:bg-purple-700">Submit rating</button>
                  </div>
                  <label class="mt-2 block text-xs font-semibold text-slate-700" for="paidFeedback<?= (int)$billRecord['queue_id'] ?>">Comment (optional)</label>
                  <textarea id="paidFeedback<?= (int)$billRecord['queue_id'] ?>" name="feedback" maxlength="50" rows="2" class="mt-1 w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-700" placeholder="Share a little about your experience"></textarea>
                  <?php if ($ratingMessage !== '' && $ratingMessageQueueId === (int)$billRecord['queue_id']): ?>
                    <p class="mt-2 text-xs <?= str_starts_with($ratingMessage, 'Thank you') ? 'text-emerald-700' : 'text-rose-600' ?>" role="status"><?= htmlspecialchars($ratingMessage) ?></p>
                  <?php endif; ?>
                </form>
              <?php endif; ?>

            </div>
            <?php endif; ?>
          </article>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>
      </section>

      <!-- Available Services Catalog Section -->
      <div class="queue-page" id="services-section" data-page="services">
      <div class="mb-6">
        <h2 class="text-2xl font-black text-slate-900 tracking-tight">Available Queue Lines & Services</h2>
        <p class="text-xs text-slate-500 mt-1">Select any service line below to issue your ticket directly in the system.</p>
      </div>

      <?php if (empty($serviceList)): ?>
      <div class="py-16 text-center bg-white border border-[#ece9f4] rounded-2xl p-8">
        <div class="w-16 h-16 rounded-full bg-purple-50 text-purple-600 flex items-center justify-center mx-auto mb-4">
          <i data-feather="briefcase" class="w-7 h-7"></i>
        </div>
        <h3 class="font-extrabold text-lg text-slate-800">No active services available</h3>
        <p class="text-xs text-slate-400 max-w-sm mx-auto mt-1">Services configured by administrators will appear here.</p>
      </div>
      <?php else: ?>
      <?php foreach ($servicesByDepartment as $departmentGroup): ?>
      <section class="stat-card-luxury p-6 mb-10 last:mb-0 customer-department-group" data-department-search="<?= htmlspecialchars(strtolower($departmentGroup['name'])) ?>" aria-labelledby="department-<?= htmlspecialchars(md5($departmentGroup['name'])) ?>">
        <button type="button" class="customer-department-toggle flex w-full items-center gap-3 text-left" aria-expanded="false" aria-controls="department-services-<?= htmlspecialchars(md5($departmentGroup['name'])) ?>">
          <span class="w-2 h-8 rounded-full bg-purple-600" aria-hidden="true"></span>
          <div class="flex-1 min-w-0">
            <h3 id="department-<?= htmlspecialchars(md5($departmentGroup['name'])) ?>" class="text-xl font-black text-slate-900"><?= htmlspecialchars($departmentGroup['name']) ?></h3>
            <p class="text-xs text-slate-500"><?= count($departmentGroup['services']) ?> service<?= count($departmentGroup['services']) === 1 ? '' : 's' ?> available</p>
          </div>
          <span class="customer-department-chevron flex h-10 w-10 flex-shrink-0 items-center justify-center text-black" aria-hidden="true">
            <i class="fa-solid fa-chevron-down text-xl"></i>
          </span>
        </button>
      <div id="department-services-<?= htmlspecialchars(md5($departmentGroup['name'])) ?>" class="customer-department-services mt-4 divide-y divide-slate-100">
        <?php foreach ($departmentGroup['services'] as $s): ?>
        <?php $departmentStatus = $s['dept_status'] ?? 'active'; $departmentAvailable = $departmentStatus === 'active'; ?>
        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 py-4 first:pt-0 last:pb-0 customer-service-row" data-service-search="<?= htmlspecialchars(strtolower($s['service_name'] . ' ' . ($s['dept_name'] ?? ''))) ?>">
          <div>
            <h4 class="text-base font-black text-slate-900"><?= htmlspecialchars($s['service_name']) ?></h4>
            <?php if ((float)$s['service_fee'] > 0): ?>
            <div class="mt-2">
              <span class="service-price">Price: &#8369;<?= number_format((float)$s['service_fee'], 2) ?></span>
            </div>
            <?php endif; ?>
          </div>

          <div class="flex items-center justify-between sm:justify-end gap-5 flex-shrink-0">
            <span class="text-xs font-bold <?= $departmentAvailable ? 'text-emerald-600' : ($departmentStatus === 'maintenance' ? 'text-amber-600' : 'text-red-600') ?> flex items-center gap-1.5">
              <span class="w-2 h-2 rounded-full <?= $departmentAvailable ? 'bg-emerald-500' : ($departmentStatus === 'maintenance' ? 'bg-amber-500' : 'bg-red-500') ?>"></span> <?= $departmentAvailable ? 'Front Desk Open' : ucfirst($departmentStatus) ?>
            </span>

            <form method="post" class="join-queue-form">
              <input type="hidden" name="action" value="join_queue">
              <input type="hidden" name="service_id" value="<?= $s['id'] ?>">
              <button type="submit" class="queue-ticket-button<?= $isGuest || $myActiveTicket || !$departmentAvailable ? ' is-blocked' : '' ?> px-4 py-2 rounded-xl bg-purple-600 hover:bg-purple-700 text-white font-bold text-xs transition inline-flex items-center gap-1.5"<?= $isGuest || $myActiveTicket || !$departmentAvailable ? ' aria-disabled="true" disabled' : '' ?> title="<?= $isGuest ? 'Create an account to get a ticket.' : '' ?>">
                <i data-feather="plus-circle" class="w-3.5 h-3.5"></i>
                <span>Get Ticket</span>
              </button>
            </form>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
      </section>
      <?php endforeach; ?>
      <p id="customerServiceNoResults" class="hidden py-8 text-center text-sm font-semibold text-slate-500">No matching services found.</p>
      <?php endif; ?>

      <?php $bottomTicket = $myActiveTicket ?: $myRecentTicket; ?>
      <?php if ($bottomTicket): ?>
      <a href="#active-ticket-section" data-page-link="ticket" class="stat-card-luxury mt-6 flex items-center justify-between gap-4 no-underline">
        <div class="flex items-center gap-3">
          <div class="w-10 h-10 rounded-2xl bg-purple-50 text-purple-600 flex items-center justify-center">
            <i class="fa-solid fa-ticket" style="font-size: 18px;" aria-hidden="true"></i>
          </div>
          <div>
            <h3 class="text-base font-black text-slate-900">Recent Ticket</h3>
            <p class="text-xs text-slate-500 mt-1">Ticket #<?= htmlspecialchars($bottomTicket['ticket_number']) ?> is <?= htmlspecialchars(str_replace('_', ' ', $bottomTicket['status'])) ?>.</p>
          </div>
        </div>
        <span class="text-xs font-bold text-purple-600">View Ticket &rarr;</span>
      </a>
      <?php endif; ?>
      </div>

      <section class="queue-page" id="notifications" data-page="notifications">
        <div class="mb-8 flex flex-wrap items-center justify-between gap-4">
          <div>
            <h1 class="text-3xl font-black text-slate-900 tracking-tight">Notifications</h1>
            <p class="text-sm text-slate-500 mt-1">View updates about your queue ticket.</p>
          </div>
          <button id="clearCustomerNotifications" type="button" class="inline-flex items-center gap-2 rounded-lg border border-slate-300 px-4 py-2 text-sm font-bold text-slate-700 hover:bg-slate-100 disabled:cursor-not-allowed disabled:opacity-50" title="Clear notifications" aria-label="Clear notifications">
            <i data-feather="trash-2" aria-hidden="true"></i>
            <span>Clear notifications</span>
          </button>
        </div>
        <div id="customerPaidNotificationCard" class="mb-4 bg-white border border-emerald-200 rounded-2xl p-6 shadow-sm flex items-start gap-4 hidden">
          <div class="w-10 h-10 rounded-full bg-emerald-50 text-emerald-600 flex items-center justify-center flex-shrink-0">
            <i class="fa-solid fa-circle-check" aria-hidden="true"></i>
          </div>
          <div>
            <p class="text-xs font-bold uppercase tracking-wide text-emerald-700">Payment update</p>
            <h2 id="customerPaymentNotificationTitle" class="text-base font-black text-slate-900">Your bill is paid</h2>
            <p id="customerPaymentNotificationMessage" class="text-sm text-slate-600 mt-1"></p>
          </div>
        </div>
        <div id="customerNotificationCard" class="bg-white border border-red-200 rounded-2xl p-6 shadow-sm flex items-start gap-4<?= $myActiveTicket ? '' : ' hidden' ?>">
          <div class="w-10 h-10 rounded-full bg-red-50 text-red-600 flex items-center justify-center flex-shrink-0">
            <i class="fa-solid fa-bullhorn" aria-hidden="true"></i>
          </div>
          <div>
            <p class="text-xs font-bold uppercase tracking-wide text-slate-500">Latest notification</p>
            <h2 id="notificationPageTitle" class="text-base font-black text-slate-900"><?= $myActiveTicket ? (in_array($myActiveTicket['status'], ['called', 'now_serving'], true) ? 'Your ticket is now calling' : 'Your queue ticket is active') : 'No recent notification yet' ?></h2>
            <p id="notificationPageMessage" class="text-sm text-slate-600 mt-1"><?php if ($myActiveTicket): ?><?= in_array($myActiveTicket['status'], ['called', 'now_serving'], true) ? 'Ticket #' . htmlspecialchars($myActiveTicket['ticket_number']) . ' is now calling. Please proceed to the service desk.' : 'Ticket #' . htmlspecialchars($myActiveTicket['ticket_number']) . ' is ' . htmlspecialchars(str_replace('_', ' ', $myActiveTicket['status'])) . '.' ?><?php else: ?>A recent queue update will appear here.<?php endif; ?></p>
            <button id="stopCustomerRinging" type="button" class="mt-4 rounded-xl bg-red-600 px-4 py-2 text-xs font-bold text-white hover:bg-red-700<?= $myActiveTicket && in_array($myActiveTicket['status'], ['called', 'now_serving'], true) ? '' : ' hidden' ?>">Stop Ringing</button>
          </div>
        </div>
        <div id="customerNoNotification" class="bg-white border border-slate-200 rounded-2xl p-6 text-sm text-slate-600<?= $myActiveTicket ? ' hidden' : '' ?>">You have no notifications yet.</div>
        <div class="mt-8">
          <div class="mb-4 flex items-center justify-between gap-3">
            <h2 class="text-lg font-black text-slate-900">Service history</h2>
            <span id="customerTransactionCount" class="text-xs font-bold text-slate-500">Loading</span>
          </div>
          <div id="customerTransactionHistory" class="space-y-3" aria-live="polite">
            <p class="text-sm text-slate-500">Loading service history...</p>
          </div>
        </div>
      </section>

      <section class="queue-page" id="faqs" data-page="faqs">
        <div class="mb-8">
          <h1 class="text-3xl font-black text-slate-900 tracking-tight">Frequently Asked Questions</h1>
          <p class="text-sm text-slate-500 mt-1">Helpful answers about using Queuezy.</p>
        </div>
        <div class="grid grid-cols-1 xl:grid-cols-2 gap-6">
          <div class="bg-white border border-slate-200 rounded-2xl p-6 shadow-sm">
            <h2 class="text-lg font-black text-slate-900 mb-4">Using Queuezy</h2>
            <div class="space-y-3">
              <details class="faq-item" open>
                <summary>What is Queuezy?</summary>
                <p>Queuezy is a digital queue management system that lets you join a service queue, receive a ticket, and follow your place without waiting in a physical line.</p>
              </details>
              <details class="faq-item">
                <summary>How do I get a queue ticket?</summary>
                <p>Open Services, choose a department and service, then select Get Ticket. Your active ticket appears in My Queue Ticket.</p>
              </details>
              <details class="faq-item">
                <summary>How will I know when it is my turn?</summary>
                <p>Open Notifications to see queue updates. When your ticket is called, proceed to the service window shown by the staff.</p>
              </details>
              <details class="faq-item">
                <summary>Can I join another queue while I have a ticket?</summary>
                <p>No. Finish or cancel your active ticket before joining another queue.</p>
              </details>
            </div>
          </div>
          <div class="bg-white border border-slate-200 rounded-2xl p-6 shadow-sm">
            <h2 class="text-lg font-black text-slate-900 mb-4">About The System</h2>
            <div class="space-y-3">
              <details class="faq-item" open>
                <summary>Who are the developers of Queuezy?</summary>
                <p>The developers of Queuezy are Christian Achera and James Cabandocos.</p>
              </details>
              <details class="faq-item">
                <summary>Who is part of the Queuezy project team?</summary>
                <p><strong>Project Manager:</strong> Jeliane Mae Galupar.<br><strong>Team Members:</strong> Christian Achera, James Cabandocos, Ron Cristofer Ravancho, and Jefferson Asistorga.</p>
              </details>
              <details class="faq-item">
                <summary>What should I do if I miss my call?</summary>
                <p>Check your ticket status and contact the service desk for assistance. Staff may mark an unattended ticket as no-show.</p>
              </details>
              <details class="faq-item">
                <summary>Why is my ticket not moving?</summary>
                <p>Queue progress depends on service availability and the number of customers ahead of you. Refresh the dashboard or check Notifications for updates.</p>
              </details>
              <details class="faq-item">
                <summary>How can I get help?</summary>
                <p>Visit the service desk shown for your department and provide your ticket number to a staff member.</p>
              </details>
            </div>
          </div>
        </div>
      </section>

      <section class="queue-page" id="settings-section" data-page="settings">
        <div class="mb-8">
          <h1 class="text-3xl font-black text-slate-900 tracking-tight">Settings</h1>
          <p class="text-sm text-slate-500 mt-1">Manage your account and dashboard preferences.</p>
        </div>
        <?php if ($msg): ?>
        <div class="mb-6 p-4 rounded-2xl border <?= $msgType === 'error' ? 'bg-red-50 border-red-200 text-red-700' : 'bg-emerald-50 border-emerald-200 text-emerald-700' ?> font-semibold text-xs">
          <?= htmlspecialchars($msg) ?>
        </div>
        <?php endif; ?>
        <div class="grid grid-cols-1 xl:grid-cols-2 gap-6">
          <form method="post" enctype="multipart/form-data" class="bg-white border border-slate-200 rounded-2xl p-6" data-no-transition="true">
            <input type="hidden" name="action" value="upload_profile_photo">
            <h2 class="text-lg font-black text-slate-900">Profile photo</h2>
            <p class="mt-2 text-sm text-slate-500"></p>
            <div class="mt-5 flex items-center gap-4">
              <div class="profile-icon" style="width: 72px; height: 72px; flex-basis: 72px; overflow: hidden;">
                <?php if ($userPhotoUrl): ?><img src="<?= htmlspecialchars($userPhotoUrl, ENT_QUOTES) ?>" alt="Current profile photo">
                <?php else: ?><i data-feather="user"></i><?php endif; ?>
              </div>
              <label class="block min-w-0 text-sm font-bold text-slate-700">Choose photo
                <input type="file" name="profile_photo" accept="image/jpeg,image/png,image/webp" required class="mt-2 block max-w-full text-xs"<?= $isGuest ? ' disabled' : '' ?>>
              </label>
            </div>
            <button type="submit" class="mt-5 rounded-xl bg-purple-600 px-5 py-3 text-sm font-bold text-white hover:bg-purple-700"<?= $isGuest ? ' disabled aria-disabled="true"' : '' ?>>Upload photo</button>
          </form>

          <form method="post" class="bg-white border border-slate-200 rounded-2xl p-6 confirm-action" data-confirm-message="Save your profile changes?">
            <input type="hidden" name="action" value="update_profile">
            <h2 class="text-lg font-black text-slate-900">Profile</h2>
            <?php if ($isGuest): ?>
            <div class="mt-4 rounded-xl border border-amber-200 bg-amber-50 px-3 py-2 text-xs font-semibold text-amber-700">Guest mode is read-only. Create an account to edit your profile.</div>
            <?php endif; ?>
            <label class="block mt-5 text-sm font-bold text-slate-700">Full name
              <input name="full_name" value="<?= htmlspecialchars($userName, ENT_QUOTES) ?>" required maxlength="35" pattern="[A-Za-z ]{1,35}" title="Use letters and spaces only, maximum 35 characters" class="mt-2 w-full rounded-xl border border-slate-200 px-4 py-3 outline-none focus:border-purple-500"<?= $isGuest ? ' disabled readonly' : '' ?>>
            </label>
            <label class="block mt-4 text-sm font-bold text-slate-700">Email address
              <input type="email" name="email" value="<?= htmlspecialchars($userEmail, ENT_QUOTES) ?>" required class="mt-2 w-full rounded-xl border border-slate-200 px-4 py-3 outline-none focus:border-purple-500"<?= $isGuest ? ' disabled readonly' : '' ?>>
            </label>
            <button type="submit" class="mt-5 rounded-xl bg-purple-600 px-5 py-3 text-sm font-bold text-white hover:bg-purple-700"<?= $isGuest ? ' disabled aria-disabled="true"' : '' ?>>Save profile</button>
          </form>

          <?php if (!empty($_SESSION['pending_email_change']) && (int) ($_SESSION['pending_email_change']['user_id'] ?? 0) === $userId): ?>
          <form method="post" class="bg-white border border-slate-200 rounded-2xl p-6 xl:col-span-2">
            <h2 class="text-lg font-black text-slate-900">Verify your new email</h2>
            <p class="mt-2 text-sm text-slate-600">Enter the six-digit code sent to <strong><?= htmlspecialchars($_SESSION['pending_email_change']['email'], ENT_QUOTES) ?></strong>. Your current email remains active until verification.</p>
            <label class="block mt-4 text-sm font-bold text-slate-700">Verification code
              <input type="text" name="otp" inputmode="numeric" autocomplete="one-time-code" maxlength="6" pattern="[0-9]{6}" required class="mt-2 w-full max-w-sm rounded-xl border border-slate-200 px-4 py-3 outline-none focus:border-purple-500">
            </label>
            <div class="mt-5 flex flex-wrap gap-3">
              <button type="submit" name="action" value="verify_email_change" class="rounded-xl bg-purple-600 px-5 py-3 text-sm font-bold text-white hover:bg-purple-700">Verify and update email</button>
              <button type="submit" name="action" value="cancel_email_change" formnovalidate class="rounded-xl border border-slate-300 px-5 py-3 text-sm font-bold text-slate-700 hover:bg-slate-50">Cancel email change</button>
            </div>
          </form>
          <?php endif; ?>

          <form method="post" class="bg-white border border-slate-200 rounded-2xl p-6 confirm-action" data-confirm-message="Change your password?">
            <input type="hidden" name="action" value="change_password">
            <h2 class="text-lg font-black text-slate-900">Change password</h2>
            <?php if ($isGuest): ?>
            <div class="mt-4 rounded-xl border border-amber-200 bg-amber-50 px-3 py-2 text-xs font-semibold text-amber-700">Guest mode cannot change passwords.</div>
            <?php endif; ?>
            <label class="block mt-5 text-sm font-bold text-slate-700">Current password
              <input type="password" name="current_password" autocomplete="current-password" required minlength="8" maxlength="16" class="mt-2 w-full rounded-xl border border-slate-200 px-4 py-3 outline-none focus:border-purple-500"<?= $isGuest ? ' disabled readonly' : '' ?>>
            </label>
            <label class="block mt-4 text-sm font-bold text-slate-700">New password
              <input type="password" name="new_password" autocomplete="new-password" minlength="8" maxlength="16" pattern="(?=.*[A-Z])(?=.*[^A-Za-z0-9]).{8,16}" title="Use 8 to 16 characters, including an uppercase letter and a symbol." required class="mt-2 w-full rounded-xl border border-slate-200 px-4 py-3 outline-none focus:border-purple-500"<?= $isGuest ? ' disabled readonly' : '' ?>>
            </label>
            <label class="block mt-4 text-sm font-bold text-slate-700">Confirm new password
              <input type="password" name="confirm_password" autocomplete="new-password" minlength="8" maxlength="16" pattern="(?=.*[A-Z])(?=.*[^A-Za-z0-9]).{8,16}" title="Use 8 to 16 characters, including an uppercase letter and a symbol." required class="mt-2 w-full rounded-xl border border-slate-200 px-4 py-3 outline-none focus:border-purple-500"<?= $isGuest ? ' disabled readonly' : '' ?>>
            </label>
            <button type="submit" class="mt-5 rounded-xl bg-purple-600 px-5 py-3 text-sm font-bold text-white hover:bg-purple-700"<?= $isGuest ? ' disabled aria-disabled="true"' : '' ?>>Change password</button>
          </form>

          <div class="bg-white border border-slate-200 rounded-2xl p-6">
            <h2 class="text-lg font-black text-slate-900">Preferences</h2>
            <label class="mt-5 flex items-center justify-between gap-4 text-sm font-bold text-slate-700">Dark mode
              <input type="checkbox" id="darkModeToggle" class="h-5 w-5 accent-purple-600">
            </label>
            <label class="mt-5 flex items-center justify-between gap-4 text-sm font-bold text-slate-700">Email notifications
              <input type="checkbox" id="emailNotificationsToggle" checked class="h-5 w-5 accent-purple-600">
            </label>
            <label class="mt-5 flex items-center justify-between gap-4 text-sm font-bold text-slate-700">Queue reminders
              <input type="checkbox" id="queueRemindersToggle" checked class="h-5 w-5 accent-purple-600">
            </label>
          </div>
        </div>
      </section>

    </div>
  </main>
</div>

<div class="toast-container" id="toastBox"></div>
<div class="confirm-modal" id="confirmModal" role="dialog" aria-modal="true" aria-label="Confirmation message">
  <div class="confirm-dialog">
    <p id="confirmMessage"></p>
    <div class="confirm-actions">
      <button class="confirm-cancel" id="confirmCancel" type="button">Cancel</button>
      <button class="confirm-continue" id="confirmContinue" type="button">Continue</button>
    </div>
  </div>
</div>

<script>
  function showToast(msg) {
    const toastBox = document.getElementById('toastBox');
    const toast = document.createElement('div');
    toast.className = 'toast-item';
    toast.innerHTML = `<i data-feather="check-circle" style="width: 18px; height: 18px; color: #34d399;"></i><span>${msg}</span>`;
    toastBox.appendChild(toast);
    if (typeof feather !== 'undefined') feather.replace();
    setTimeout(() => { toast.remove(); }, 3500);
  }

  document.addEventListener('DOMContentLoaded', () => {
    if (typeof feather !== 'undefined') {
      feather.replace();
    }
    const queueMenu = document.getElementById('queueMenu');
    const queueSidebar = document.getElementById('queueSidebar');
    const queueSidebarBackdrop = document.getElementById('queueSidebarBackdrop');
    const queueSidebarToggle = document.getElementById('queueSidebarToggle');
    const queueLayout = document.querySelector('.queue-layout');
    const notificationButton = document.getElementById('notificationButton');
    const notificationSidebarLink = document.getElementById('notificationSidebarLink');
    const notificationPanel = document.getElementById('notificationPanel');
    const customerCallOverlay = document.getElementById('customerCallOverlay');
    const customerNotificationCard = document.getElementById('customerNotificationCard');
    const customerPaidNotificationCard = document.getElementById('customerPaidNotificationCard');
    const customerNoNotification = document.getElementById('customerNoNotification');
    const customerTransactionHistory = document.getElementById('customerTransactionHistory');
    const customerTransactionCount = document.getElementById('customerTransactionCount');
    const clearCustomerNotificationsButton = document.getElementById('clearCustomerNotifications');
    const lastNotificationStorageKey = <?= json_encode('queuezy-last-notification-' . $userId, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
    const clearedQueueNotificationStorageKey = <?= json_encode('queuezy-cleared-queue-notification-' . $userId, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
    const clearedPaymentNotificationStorageKey = <?= json_encode('queuezy-cleared-payment-notification-' . $userId, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
    const customerCallTitle = document.getElementById('customerCallTitle');
    const customerCallService = document.getElementById('customerCallService');
    const customerCallTicket = document.getElementById('customerCallTicket');
    const stopCustomerCallOverlay = document.getElementById('stopCustomerCallOverlay');
    const confirmModal = document.getElementById('confirmModal');
    const confirmMessage = document.getElementById('confirmMessage');
    const confirmCancel = document.getElementById('confirmCancel');
    const confirmContinue = document.getElementById('confirmContinue');
    let customerAudioContext = null;
    let pendingCustomerRing = null;
    let repeatedCustomerRingTimer = null;
    let activeCallingNotificationKey = '';
    let silencedCallingNotificationKey = localStorage.getItem('queuezy-silenced-notification') || '';
    let pendingConfirmation = null;
    let logoutConfirmationPending = false;
    const queueLinks = document.querySelectorAll('.queue-nav a');
    const queuePages = document.querySelectorAll('[data-page]');
    const updateRatingStars = (selectedInput) => {
      const fieldset = selectedInput.closest('fieldset');
      if (!fieldset) return;
      const selectedValue = Number(selectedInput.value);
      fieldset.querySelectorAll('input[type="radio"][name="rating"]').forEach((input) => {
        input.nextElementSibling?.classList.toggle('rating-star-selected', Number(input.value) <= selectedValue);
      });
    };
    document.querySelectorAll('input[type="radio"][name="rating"]').forEach((input) => {
      input.addEventListener('change', () => updateRatingStars(input));
      if (input.checked) updateRatingStars(input);
    });
    const markNotificationsViewed = () => {
      const notificationKey = notificationSidebarLink?.dataset.notificationKey;
      if (!notificationKey) return;
      localStorage.setItem('queuezy-viewed-notification', notificationKey);
      notificationSidebarLink.querySelector('.sidebar-notification-dot')?.remove();
      document.getElementById('topNotificationDot')?.remove();
    };
    const addNotificationDots = () => {
      if (notificationSidebarLink && !notificationSidebarLink.querySelector('.sidebar-notification-dot')) {
        const dot = document.createElement('b');
        dot.className = 'sidebar-notification-dot';
        dot.setAttribute('aria-label', 'Unread notification');
        notificationSidebarLink.appendChild(dot);
      }
      if (notificationButton && !document.getElementById('topNotificationDot')) {
        const dot = document.createElement('b');
        dot.id = 'topNotificationDot';
        dot.setAttribute('aria-label', 'Unread notification');
        notificationButton.appendChild(dot);
      }
    };
    const renderCustomerTransactionHistory = (transactions) => {
      if (!customerTransactionHistory) return;
      const transactionList = Array.isArray(transactions) ? transactions : [];
      if (customerTransactionCount) {
        customerTransactionCount.textContent = `${transactionList.length} ticket${transactionList.length === 1 ? '' : 's'}`;
      }
      customerTransactionHistory.replaceChildren();
      if (transactionList.length === 0) {
        const emptyMessage = document.createElement('p');
        emptyMessage.className = 'rounded-xl border border-slate-200 bg-white p-5 text-sm text-slate-500';
        emptyMessage.textContent = 'No service transactions yet.';
        customerTransactionHistory.appendChild(emptyMessage);
        return;
      }
      const statusLabels = {
        waiting: 'Waiting',
        called: 'Called',
        now_serving: 'Now serving',
        completed: 'Completed',
        cancelled: 'Cancelled',
        no_show: 'No show'
      };
      transactionList.forEach((transaction) => {
        const item = document.createElement('article');
        item.className = 'rounded-xl border border-slate-200 bg-white p-5 shadow-sm';

        const header = document.createElement('div');
        header.className = 'flex flex-wrap items-start justify-between gap-3';

        const details = document.createElement('div');
        const service = document.createElement('h3');
        service.className = 'text-sm font-black text-slate-900';
        service.textContent = transaction.service || 'Service ticket';
        const ticket = document.createElement('p');
        ticket.className = 'mt-1 text-xs text-slate-500';
        ticket.textContent = `Ticket #${transaction.ticket || '-'}${transaction.department ? ` · ${transaction.department}` : ''}`;
        details.append(service, ticket);

        const status = document.createElement('span');
        status.className = transaction.status === 'completed'
          ? 'rounded-full border border-emerald-200 bg-emerald-50 px-3 py-1 text-xs font-bold text-emerald-700'
          : 'rounded-full border border-slate-200 bg-slate-50 px-3 py-1 text-xs font-bold text-slate-700';
        status.textContent = statusLabels[transaction.status] || String(transaction.status || 'Unknown');
        header.append(details, status);
        item.appendChild(header);

        const metadata = document.createElement('p');
        metadata.className = 'mt-3 text-xs text-slate-500';
        const dateLabel = transaction.completed_at || transaction.created_at;
        metadata.textContent = transaction.payment_status === 'paid'
          ? `Paid${transaction.amount ? ` · PHP ${Number(transaction.amount).toFixed(2)}` : ''}${transaction.paid_at ? ` · ${transaction.paid_at}` : ''}`
          : `${transaction.payment_status === 'pending' ? 'Payment pending · ' : ''}${dateLabel ? `Created ${dateLabel}` : ''}`;
        if (metadata.textContent) item.appendChild(metadata);
        customerTransactionHistory.appendChild(item);
      });
    };
    const restoreLastCustomerNotification = () => {
      let lastNotification = null;
      try {
        lastNotification = JSON.parse(localStorage.getItem(lastNotificationStorageKey) || 'null');
      } catch (error) {
        localStorage.removeItem(lastNotificationStorageKey);
      }
      if (!lastNotification?.title || !lastNotification?.message) return false;
      if (lastNotification.key && localStorage.getItem(clearedQueueNotificationStorageKey) === lastNotification.key) return false;
      customerNotificationCard?.classList.remove('hidden');
      customerNoNotification?.classList.add('hidden');
      if (clearCustomerNotificationsButton) clearCustomerNotificationsButton.disabled = false;
      const title = document.getElementById('notificationPageTitle');
      const message = document.getElementById('notificationPageMessage');
      if (title) title.textContent = lastNotification.title;
      if (message) message.textContent = lastNotification.message;
      document.getElementById('stopCustomerRinging')?.classList.add('hidden');
      return true;
    };
    const updateCustomerPaymentNotification = (ticket, amount, paidAt) => {
      if (!ticket) {
        if (customerPaidNotificationCard) {
          customerPaidNotificationCard.dataset.notificationKey = '';
          customerPaidNotificationCard.classList.add('hidden');
        }
        return;
      }
      const paymentKey = `${ticket}|${paidAt || ''}|${amount ?? ''}`;
      if (customerPaidNotificationCard) customerPaidNotificationCard.dataset.notificationKey = paymentKey;
      if (localStorage.getItem(clearedPaymentNotificationStorageKey) === paymentKey) {
        customerPaidNotificationCard?.classList.add('hidden');
        return;
      }
      const title = document.getElementById('customerPaymentNotificationTitle');
      const message = document.getElementById('customerPaymentNotificationMessage');
      const formattedAmount = Number.isFinite(Number(amount))
        ? new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP' }).format(Number(amount))
        : '';
      if (title) title.textContent = 'Your bill is paid';
      if (message) {
        message.textContent = `Payment received for ticket #${ticket}${formattedAmount ? ` (${formattedAmount})` : ''}${paidAt ? ` on ${paidAt}` : ''}.`;
      }
      customerPaidNotificationCard?.classList.remove('hidden');
      customerNoNotification?.classList.add('hidden');
      if (clearCustomerNotificationsButton) clearCustomerNotificationsButton.disabled = false;
    };
    const unlockCustomerAudio = async () => {
      if (!customerAudioContext) {
        const AudioContextClass = window.AudioContext || window.webkitAudioContext;
        if (!AudioContextClass) return;
        customerAudioContext = new AudioContextClass();
      }
      if (customerAudioContext.state === 'suspended') {
        try { await customerAudioContext.resume(); } catch (error) { return; }
      }
    };
    const ringCustomerPhone = async (ticket) => {
      await unlockCustomerAudio();
      if (!customerAudioContext || customerAudioContext.state !== 'running') {
        pendingCustomerRing = ticket;
        return;
      }
      pendingCustomerRing = null;
      if (customerAudioContext) {
        const start = customerAudioContext.currentTime;
        const gain = customerAudioContext.createGain();
        const firstTone = customerAudioContext.createOscillator();
        const secondTone = customerAudioContext.createOscillator();
        firstTone.type = 'sine';
        secondTone.type = 'sine';
        firstTone.frequency.setValueAtTime(659.25, start);
        secondTone.frequency.setValueAtTime(987.77, start + 0.18);
        gain.gain.setValueAtTime(0.001, start);
        gain.gain.exponentialRampToValueAtTime(0.28, start + 0.03);
        gain.gain.exponentialRampToValueAtTime(0.001, start + 0.5);
        firstTone.connect(gain);
        secondTone.connect(gain);
        gain.connect(customerAudioContext.destination);
        firstTone.start(start);
        firstTone.stop(start + 0.18);
        secondTone.start(start + 0.18);
        secondTone.stop(start + 0.5);
      }
      if (navigator.vibrate) navigator.vibrate([220, 100, 220]);
      if ('speechSynthesis' in window && ticket) {
        const spokenTicket = String(ticket).trim().replace(/-/g, ' ').replace(/\b0+(\d+)/g, '$1');
        const announcement = new SpeechSynthesisUtterance(`Ticket ${spokenTicket} is now calling. Please proceed to the service desk.`);
        announcement.rate = 0.9;
        announcement.pitch = 1;
        announcement.lang = 'en-US';
        window.speechSynthesis.speak(announcement);
      }
    };
    document.addEventListener('pointerdown', async () => {
      if ('Notification' in window && Notification.permission === 'default') {
        Notification.requestPermission().catch(() => {});
      }
      await unlockCustomerAudio();
      if (pendingCustomerRing) ringCustomerPhone(pendingCustomerRing);
    });
    const notifyCustomerPhone = (ticket) => {
      if (!('Notification' in window) || Notification.permission !== 'granted') return;
      try {
        new Notification('Queuezy: your ticket is calling', {
          body: `Ticket #${ticket} is now calling. Please proceed to the service desk.`,
          tag: `queuezy-${ticket}`,
          renotify: true
        });
      } catch (error) {}
    };
    const stopRepeatedCustomerRing = () => {
      if (repeatedCustomerRingTimer) window.clearInterval(repeatedCustomerRingTimer);
      repeatedCustomerRingTimer = null;
      activeCallingNotificationKey = '';
    };
    const stopCustomerRinging = () => {
      silencedCallingNotificationKey = activeCallingNotificationKey || notificationSidebarLink?.dataset.notificationKey || '';
      if (silencedCallingNotificationKey) localStorage.setItem('queuezy-silenced-notification', silencedCallingNotificationKey);
      stopRepeatedCustomerRing();
      pendingCustomerRing = null;
      localStorage.removeItem('queuezy-rung-notification');
      window.speechSynthesis?.cancel();
      if (navigator.vibrate) navigator.vibrate(0);
      const stopButton = document.getElementById('stopCustomerRinging');
      if (stopButton) {
        stopButton.classList.add('hidden');
        stopButton.disabled = true;
      }
      customerCallOverlay?.classList.remove('is-visible');
    };
    const clearCustomerNotifications = () => {
      let lastNotification = null;
      try {
        lastNotification = JSON.parse(localStorage.getItem(lastNotificationStorageKey) || 'null');
      } catch (error) {
        localStorage.removeItem(lastNotificationStorageKey);
      }
      const queueKey = notificationSidebarLink?.dataset.notificationKey || lastNotification?.key || '';
      const paymentKey = customerPaidNotificationCard?.dataset.notificationKey || '';
      if (queueKey) localStorage.setItem(clearedQueueNotificationStorageKey, queueKey);
      if (paymentKey) localStorage.setItem(clearedPaymentNotificationStorageKey, paymentKey);
      localStorage.removeItem(lastNotificationStorageKey);
      if (queueKey) stopCustomerRinging();
      customerNotificationCard?.classList.add('hidden');
      customerPaidNotificationCard?.classList.add('hidden');
      customerNoNotification?.classList.remove('hidden');
      if (clearCustomerNotificationsButton) clearCustomerNotificationsButton.disabled = true;
      markNotificationsViewed();
      notificationPanel?.classList.remove('is-visible');
      notificationButton?.setAttribute('aria-expanded', 'false');
    };
    const startRepeatedCustomerRing = (ticket, notificationKey) => {
      if (silencedCallingNotificationKey === notificationKey) return;
      if (activeCallingNotificationKey === notificationKey) return;
      stopRepeatedCustomerRing();
      activeCallingNotificationKey = notificationKey;
      if (localStorage.getItem('queuezy-rung-notification') !== notificationKey) {
        localStorage.setItem('queuezy-rung-notification', notificationKey);
        ringCustomerPhone(ticket);
        notifyCustomerPhone(ticket);
      }
    };
    const updateCustomerNotification = (ticket, status, department, service, callCount) => {
      if (!ticket || !status) {
        stopCustomerRinging();
        if (restoreLastCustomerNotification()) return;
        customerNotificationCard?.classList.add('hidden');
        if (customerPaidNotificationCard && !customerPaidNotificationCard.classList.contains('hidden')) {
          customerNoNotification?.classList.add('hidden');
          return;
        }
        customerNoNotification?.classList.remove('hidden');
        return;
      }
      const notificationKey = `${ticket}|${status}|${callCount || 0}`;
      if (notificationSidebarLink) notificationSidebarLink.dataset.notificationKey = notificationKey;
      if (localStorage.getItem(clearedQueueNotificationStorageKey) === notificationKey) {
        stopCustomerRinging();
        customerNotificationCard?.classList.add('hidden');
        if (customerPaidNotificationCard && !customerPaidNotificationCard.classList.contains('hidden')) {
          customerNoNotification?.classList.add('hidden');
        } else {
          customerNoNotification?.classList.remove('hidden');
        }
        return;
      }
      const isCalling = status === 'called' || status === 'now_serving';
      const title = isCalling
        ? 'Your ticket is now calling'
        : status === 'completed'
          ? 'Your service is complete'
          : status === 'cancelled'
            ? 'Your ticket was cancelled'
            : status === 'no_show'
              ? 'Ticket marked as no-show'
              : 'Your queue is active';
      const message = isCalling
        ? `Ticket #${ticket} is now calling. Please proceed to the service desk.`
        : `Ticket #${ticket} is ${status.replace('_', ' ')}.`;
      localStorage.setItem(lastNotificationStorageKey, JSON.stringify({ title, message, key: notificationKey }));
      customerNotificationCard?.classList.remove('hidden');
      customerNoNotification?.classList.add('hidden');
      if (clearCustomerNotificationsButton) clearCustomerNotificationsButton.disabled = false;
      if (isCalling) {
        if (silencedCallingNotificationKey !== notificationKey) {
          if (customerCallTitle) customerCallTitle.textContent = department || 'Front Desk';
          if (customerCallService) customerCallService.textContent = service || 'Please proceed to the front desk.';
          if (customerCallTicket) customerCallTicket.textContent = ticket;
          customerCallOverlay?.classList.add('is-visible');
        } else {
          customerCallOverlay?.classList.remove('is-visible');
        }
      }
      if (!isCalling) customerCallOverlay?.classList.remove('is-visible');
      document.querySelectorAll('#notificationTitle, #notificationPageTitle').forEach((element) => { element.textContent = title; });
      document.querySelectorAll('#notificationMessage, #notificationPageMessage').forEach((element) => { element.textContent = message; });
      if (isCalling) {
        const stopButton = document.getElementById('stopCustomerRinging');
        const isNotificationSilenced = silencedCallingNotificationKey === notificationKey;
        stopButton?.classList.toggle('hidden', isNotificationSilenced);
        if (stopButton && !isNotificationSilenced) {
          stopButton.textContent = 'Stop Ringing';
          stopButton.disabled = false;
        }
        if (silencedCallingNotificationKey !== notificationKey) localStorage.removeItem('queuezy-silenced-notification');
        if (silencedCallingNotificationKey !== notificationKey) silencedCallingNotificationKey = '';
        startRepeatedCustomerRing(ticket, notificationKey);
      } else {
        document.getElementById('stopCustomerRinging')?.classList.add('hidden');
        stopRepeatedCustomerRing();
        localStorage.removeItem('queuezy-rung-notification');
      }
      if (localStorage.getItem('queuezy-viewed-notification') !== notificationKey) addNotificationDots();
    };
    document.getElementById('stopCustomerRinging')?.addEventListener('click', stopCustomerRinging);
    const checkCustomerNotification = () => {
      fetch('../api/customer_notification.php', { cache: 'no-store' })
        .then((response) => response.ok ? response.json() : null)
        .then((data) => {
          if (data?.ok) {
            updateCustomerPaymentNotification(data.paid_ticket, data.paid_amount, data.paid_at);
            updateCustomerNotification(data.ticket, data.status, data.department, data.service, data.call_count);
            renderCustomerTransactionHistory(data.transactions);
          }
        })
        .catch(() => {});
    };
    if (notificationSidebarLink?.dataset.notificationKey === localStorage.getItem('queuezy-viewed-notification')) {
      notificationSidebarLink.querySelector('.sidebar-notification-dot')?.remove();
      document.getElementById('topNotificationDot')?.remove();
    }
    restoreLastCustomerNotification();
    if (clearCustomerNotificationsButton) {
      clearCustomerNotificationsButton.disabled = customerNotificationCard?.classList.contains('hidden') !== false
        && customerPaidNotificationCard?.classList.contains('hidden') !== false;
    }
    checkCustomerNotification();
    window.setInterval(checkCustomerNotification, 5000);
    stopCustomerCallOverlay?.addEventListener('click', stopCustomerRinging);
    const closeQueueSidebar = () => {
      queueSidebar?.classList.remove('is-open');
      queueSidebarBackdrop?.classList.remove('is-visible');
    };
    const toggleQueueSidebar = () => {
      if (!queueSidebar) return;
      if (window.innerWidth <= 900) {
        queueSidebar.classList.remove('is-collapsed');
        const isOpen = queueSidebar.classList.toggle('is-open');
        queueSidebarBackdrop?.classList.toggle('is-visible', isOpen);
      } else {
        const collapsed = queueSidebar.classList.toggle('is-collapsed');
        queueLayout?.classList.toggle('sidebar-collapsed', collapsed);
      }
    };
    const toggleSidebarButton = (event) => {
      event?.preventDefault();
      if (window.innerWidth <= 900) {
        closeQueueSidebar();
      } else {
        const collapsed = queueSidebar.classList.toggle('is-collapsed');
        queueLayout?.classList.toggle('sidebar-collapsed', collapsed);
      }
    };
    if (queueSidebar) {
      queueMenu?.addEventListener('click', toggleQueueSidebar);
      queueSidebarToggle?.addEventListener('click', toggleSidebarButton);
      queueSidebarBackdrop?.addEventListener('click', closeQueueSidebar);
      queueSidebar.querySelectorAll('a').forEach((link) => link.addEventListener('click', closeQueueSidebar));
      document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') closeQueueSidebar();
      });
    }
    notificationButton?.addEventListener('click', (event) => {
      event.stopPropagation();
      markNotificationsViewed();
      const visible = notificationPanel?.classList.toggle('is-visible') === true;
      notificationButton.setAttribute('aria-expanded', String(visible));
    });
    notificationSidebarLink?.addEventListener('click', markNotificationsViewed);
    document.addEventListener('click', (event) => {
      if (notificationPanel?.classList.contains('is-visible') && !notificationPanel.contains(event.target) && event.target !== notificationButton) {
        notificationPanel.classList.remove('is-visible');
        notificationButton?.setAttribute('aria-expanded', 'false');
      }
    });
    document.addEventListener('keydown', (event) => {
      if (event.key === 'Escape') {
        notificationPanel?.classList.remove('is-visible');
        notificationButton?.setAttribute('aria-expanded', 'false');
        confirmModal?.classList.remove('is-visible');
        pendingConfirmation = null;
      }
    });
    const openConfirmation = (message, action, continueLabel = 'Continue') => {
      pendingConfirmation = action;
      if (confirmMessage) confirmMessage.textContent = message;
      if (confirmContinue) confirmContinue.textContent = continueLabel;
      confirmModal?.classList.add('is-visible');
      confirmCancel?.focus();
    };
    const closeConfirmation = () => {
      confirmModal?.classList.remove('is-visible');
      pendingConfirmation = null;
      logoutConfirmationPending = false;
      if (confirmContinue) confirmContinue.style.display = '';
      if (confirmContinue) confirmContinue.textContent = 'Continue';
      confirmContinue?.classList.remove('is-logging-out');
      if (confirmContinue) confirmContinue.disabled = false;
      if (confirmCancel) confirmCancel.textContent = 'Cancel';
      if (confirmCancel) confirmCancel.disabled = false;
    };
    clearCustomerNotificationsButton?.addEventListener('click', () => {
      openConfirmation('Clear all current notifications? New notifications will still appear.', clearCustomerNotifications, 'Clear notifications');
    });
    confirmCancel?.addEventListener('click', closeConfirmation);
    confirmContinue?.addEventListener('click', () => {
      const action = pendingConfirmation;
      if (logoutConfirmationPending) {
        confirmContinue.classList.add('is-logging-out');
        confirmContinue.disabled = true;
        confirmContinue.textContent = 'Logging out...';
        confirmCancel.disabled = true;
        logoutConfirmationPending = false;
        window.setTimeout(() => {
          closeConfirmation();
          action?.();
        }, 350);
        return;
      }
      closeConfirmation();
      action?.();
    });
    confirmModal?.addEventListener('click', (event) => {
      if (event.target === confirmModal) closeConfirmation();
    });
    const showBlockedMessage = () => {
      pendingConfirmation = null;
      if (confirmMessage) confirmMessage.textContent = 'Please wait for your ticket to be done or cancelled.';
      if (confirmContinue) confirmContinue.style.display = 'none';
      if (confirmCancel) confirmCancel.textContent = 'Close';
      confirmModal?.classList.add('is-visible');
      confirmCancel?.focus();
    };
    const customerServiceSearch = document.getElementById('customerServiceSearch');
    const customerServiceSearchButton = document.getElementById('customerServiceSearchButton');
    const customerDepartmentGroups = [...document.querySelectorAll('.customer-department-group')];
    const customerServiceNoResults = document.getElementById('customerServiceNoResults');
    const setDepartmentExpanded = (group, expanded) => {
      const toggle = group.querySelector('.customer-department-toggle');
      const services = group.querySelector('.customer-department-services');
      const chevron = group.querySelector('.customer-department-chevron');
      toggle?.setAttribute('aria-expanded', String(expanded));
      if (services) {
        services.classList.toggle('is-expanded', expanded);
        services.style.maxHeight = expanded ? `${services.scrollHeight}px` : '0px';
      }
    };
    customerDepartmentGroups.forEach((group) => {
      group.querySelector('.customer-department-toggle')?.addEventListener('click', () => {
        const toggle = group.querySelector('.customer-department-toggle');
        setDepartmentExpanded(group, toggle?.getAttribute('aria-expanded') !== 'true');
      });
    });
    const filterCustomerServices = () => {
      const query = customerServiceSearch?.value.trim().toLowerCase() || '';
      let visibleGroups = 0;

      customerDepartmentGroups.forEach((group) => {
        const departmentMatches = (group.dataset.departmentSearch || '').includes(query);
        const rows = [...group.querySelectorAll('.customer-service-row')];
        let visibleRows = 0;

        rows.forEach((row) => {
          const matches = departmentMatches || (row.dataset.serviceSearch || '').includes(query);
          row.classList.toggle('hidden', !matches);
          if (matches) visibleRows += 1;
        });

        group.classList.toggle('hidden', visibleRows === 0);
        if (query && visibleRows > 0) setDepartmentExpanded(group, true);
        if (visibleRows > 0) visibleGroups += 1;
      });

      customerServiceNoResults?.classList.toggle('hidden', visibleGroups !== 0 || !query);
    };
    customerServiceSearch?.addEventListener('input', filterCustomerServices);
    customerServiceSearchButton?.addEventListener('click', () => {
      customerServiceSearch?.focus();
      filterCustomerServices();
    });
    document.querySelectorAll('.join-queue-form').forEach((form) => {
      form.addEventListener('submit', (event) => {
        if (form.querySelector('[aria-disabled="true"]')) {
          event.preventDefault();
          showBlockedMessage();
          return;
        }
        const otherServiceInput = form.querySelector('[name="other_service_name"]');
        const serviceName = otherServiceInput?.value.trim() || form.closest('.stat-card-luxury')?.querySelector('h3')?.textContent.trim() || 'this service';
        if (!serviceName) {
          event.preventDefault();
          otherServiceInput?.focus();
          return;
        }
        event.preventDefault();
        openConfirmation(`Get a queue ticket for ${serviceName}?`, () => form.submit());
      });
    });
    document.querySelectorAll('.confirm-action').forEach((form) => {
      form.addEventListener('submit', (event) => {
        event.preventDefault();
        openConfirmation(form.dataset.confirmMessage || 'Are you sure you want to continue?', () => form.submit());
      });
    });
    document.querySelectorAll('.logout-link').forEach((link) => {
      link.addEventListener('click', (event) => {
        event.preventDefault();
        if (confirmContinue) confirmContinue.textContent = 'Log Out';
        openConfirmation('Are you sure you want to log out?', () => { window.location.href = link.href; });
        logoutConfirmationPending = true;
      });
    });
    const setActiveQueuePage = () => {
      const target = window.location.hash || '#home-section';
      const hasTicketPage = document.querySelector('[data-page="ticket"]');
      const page = target === '#services-section' ? 'services' : target === '#active-ticket-section' && hasTicketPage ? 'ticket' : target === '#bill-section' ? 'bill' : target === '#notifications' ? 'notifications' : target === '#settings-section' ? 'settings' : target === '#faqs' ? 'faqs' : 'home';
      queuePages.forEach((element) => {
        const active = element.dataset.page === page;
        element.classList.toggle('is-current', active);
        element.classList.toggle('is-hidden', !active);
      });
      queueLinks.forEach((link) => {
        const isHome = page === 'home' && link.getAttribute('href') === 'index.php';
        const isTarget = link.getAttribute('href') === target;
        link.classList.toggle('is-active', isHome || isTarget);
      });
    };
    queueLinks.forEach((link) => link.addEventListener('click', closeQueueSidebar));
    document.querySelectorAll('[data-page-link]').forEach((link) => link.addEventListener('click', closeQueueSidebar));
    window.addEventListener('hashchange', setActiveQueuePage);
    setActiveQueuePage();
    const preferenceToggles = {
      darkModeToggle: window.queuezyCustomerThemeKey,
      emailNotificationsToggle: 'queuezy-email-notifications',
      queueRemindersToggle: 'queuezy-queue-reminders'
    };
    Object.entries(preferenceToggles).forEach(([id, storageKey]) => {
      const toggle = document.getElementById(id);
      if (!toggle) return;
      const stored = localStorage.getItem(storageKey);
      if (stored === null && id === 'darkModeToggle') {
        toggle.checked = false;
        localStorage.setItem(storageKey, 'false');
      } else if (stored !== null) {
        toggle.checked = stored === 'true';
      }
      toggle.addEventListener('change', () => {
        localStorage.setItem(storageKey, String(toggle.checked));
        if (id === 'darkModeToggle') document.body.classList.toggle('dark-mode', toggle.checked);
      });
    });
    document.body.classList.toggle('dark-mode', document.getElementById('darkModeToggle')?.checked === true);
    document.querySelectorAll('[data-sidebar-toggle]').forEach((toggle) => {
      toggle.addEventListener('click', () => {
        const section = document.querySelector(`[data-sidebar-section="${toggle.dataset.sidebarToggle}"]`);
        const expanded = toggle.getAttribute('aria-expanded') === 'true';
        toggle.setAttribute('aria-expanded', String(!expanded));
        section?.classList.toggle('is-collapsed', expanded);
      });
    });
    const urlParams = new URLSearchParams(window.location.search);
    if (urlParams.has('joined')) {
      const ticket = urlParams.get('ticket') || '';
      showToast(`Successfully joined queue! Your ticket is #${ticket}`);
    }
    if (urlParams.get('cancelled') === '1') {
      showToast('Queue ticket cancelled.');
    } else if (urlParams.get('cancelled') === '0') {
      showToast('We could not cancel that queue ticket.');
    }
  });
</script>

</body>
</html>
