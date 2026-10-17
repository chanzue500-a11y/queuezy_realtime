
<?php
require __DIR__ . '/../PHPMailer/src/Exception.php';
require __DIR__ . '/../PHPMailer/src/PHPMailer.php';
require __DIR__ . '/../PHPMailer/src/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/database/db.php';

$error = '';
$success = $_SESSION['reset_success'] ?? '';
unset($_SESSION['reset_success']);
$resetComplete = isset($_GET['reset']) && $_GET['reset'] === 'success';
if ($resetComplete && $success === '') {
    $success = 'Password successfully changed.';
}
$step = 'email';

if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['start']) && $_GET['start'] === '1') {
    unset($_SESSION['pending_password_reset']);
} elseif (isset($_SESSION['pending_password_reset'])) {
    $step = 'otp';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'request';

    if ($action === 'request') {
        $email = trim($_POST['email'] ?? '');

        if (strlen($email) > 254) {
            $error = 'Email address cannot exceed 254 characters.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Please enter a valid email address.';
        } elseif (!preg_match('/^(?=.{1,254}$)[A-Za-z0-9._%+\-]+@gmail\.com$/i', $email)) {
            $error = 'Please use a valid Gmail address.';
        } elseif (!$conn) {
            $error = 'The account service is temporarily unavailable.';
        } else {
            $stmt = $conn->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
            if ($stmt) {
                $stmt->bind_param('s', $email);
                $stmt->execute();
                $result = $stmt->get_result();
                $account = $result ? $result->fetch_assoc() : null;
                $stmt->close();

                if (!$account) {
                    $error = 'No account was found with that email address.';
                } else {
                    $otp = (string) random_int(100000, 999999);
                    $_SESSION['pending_password_reset'] = [
                        'email' => $email,
                        'otp_hash' => password_hash($otp, PASSWORD_DEFAULT),
                        'otp_expires_at' => time() + 600,
                    ];

                    $mail = new PHPMailer(true);

            try {
                $mail->isSMTP();
                $mail->Host       = 'smtp.gmail.com';
                $mail->SMTPAuth   = true;
                $mail->Username   = 'developerchan25@gmail.com';
                $mail->Password   = 'ygxoadwjsodnopos';
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
                $mail->Port       = 587;

                $mail->setFrom('developerchan25@gmail.com', 'QUEUEZY');
                $mail->addAddress($email);

                $mail->isHTML(false);
                $mail->Subject = 'Queuezy password reset code';
                $mail->Body    = "Hello,\n\nYour Queuezy password reset code is: {$otp}\n\nThis code expires in 10 minutes.";

                $mail->send();
                $step = 'otp';
                $success = 'A 6-digit verification code has been sent to your email.';
            } catch (Exception $e) {
                unset($_SESSION['pending_password_reset']);
                $error = 'We could not send the reset code. Please try again.';
            }
                }
            } else {
                $error = 'Password reset is not configured for the current database.';
            }
        }
    }

    if ($action === 'reset') {
        $pending = $_SESSION['pending_password_reset'] ?? null;
        if (!$pending) {
            header('Location: forgot_password.php');
            exit;
        }

        $otp = trim($_POST['otp'] ?? '');
        $newPassword = $_POST['new_password'] ?? '';
        $confirmPassword = $_POST['confirm_password'] ?? '';

        if (!preg_match('/^\d{6}$/', $otp)) {
            $error = 'Enter the six-digit verification code.';
        } elseif (time() > (int) ($pending['otp_expires_at'] ?? 0)) {
            $error = 'This verification code has expired. Please request a new one.';
            unset($_SESSION['pending_password_reset']);
            $step = 'email';
        } elseif (!password_verify($otp, $pending['otp_hash'] ?? '')) {
            $error = 'That verification code is incorrect.';
        } elseif (strlen($newPassword) < 8 || strlen($newPassword) > 16) {
            $error = 'Your new password must be 8 to 16 characters long and include an uppercase letter and a symbol.';
        } elseif (!preg_match('/[A-Z]/', $newPassword) || !preg_match('/[^A-Za-z0-9]/', $newPassword)) {
            $error = 'Your new password must include at least one uppercase letter and one symbol.';
        } elseif ($newPassword !== $confirmPassword) {
            $error = 'The new password and confirmation do not match.';
        } elseif (!$conn) {
            $error = 'The account service is temporarily unavailable.';
        } else {
            $hashed = password_hash($newPassword, PASSWORD_DEFAULT);
            $update = $conn->prepare('UPDATE users SET password = ? WHERE email = ? LIMIT 1');
            if ($update) {
                $update->bind_param('ss', $hashed, $pending['email']);
                $passwordSaved = false;
                if ($update->execute()) {
                    $verifyUpdate = $conn->prepare('SELECT password FROM users WHERE email = ? LIMIT 1');
                    if ($verifyUpdate) {
                        $verifyUpdate->bind_param('s', $pending['email']);
                        if ($verifyUpdate->execute()) {
                            $savedAccount = $verifyUpdate->get_result()->fetch_assoc();
                            $passwordSaved = $savedAccount && password_verify($newPassword, (string) ($savedAccount['password'] ?? ''));
                        }
                        $verifyUpdate->close();
                    }
                }

                if ($passwordSaved) {
                    unset($_SESSION['pending_password_reset']);
                    $_SESSION['reset_success'] = 'Password successfully changed.';
                    $update->close();
                    header('Location: forgot_password.php?reset=success');
                    exit;
                }
                $error = 'Your password was not changed. Please request a new reset code and try again.';
                $update->close();
            } else {
                $error = 'Password reset is not configured for the current database.';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Reset password | Queuezy</title>
    <link rel="preconnect" href="https://fonts.googleapis.com" />
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet" />
    <style>
        :root {
            --bg-soft: #e9d9ff;
            --purple-1: #8b63e8;
            --purple-2: #7d4fe2;
            --purple-3: #6b47d2;
            --card: rgba(255,255,255,0.18);
            --text: #1c1833;
            --muted: #677085;
            --line: #d5c0ff;
            --green: #27b980;
            --success-bg: #edfdf5;
            --success-text: #166534;
            --error-bg: #fff3f3;
            --error-text: #b91c1c;
        }

        * { box-sizing: border-box; }

        html, body {
            margin: 0;
            min-height: 100%;
            height: 100%;
            background: #f3ebff;
            font-family: 'Inter', sans-serif;
            color: var(--text);
            overflow: hidden;
            opacity: 1;
            transform: none;
        }

        body {
            min-height: 100vh;
            height: 100vh;
            max-height: 100vh;
            background: #f3ebff;
        }

        .page-shell {
            position: relative;
            width: 100%;
            max-width: 100%;
            height: 100vh;
            min-height: 100vh;
            display: grid;
            grid-template-columns: 1.1fr 0.9fr;
            background: var(--bg-soft);
            overflow: hidden;
            gap: 0;
            padding: 0;
            border: none;
            margin: 0;
            opacity: 1;
            transform: none;
        }

        .page-shell.auth-enter {
            animation: authFadeIn 380ms ease;
        }

        @keyframes authFadeIn {
            from {
                opacity: 0;
                transform: translateY(14px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        @media (prefers-reduced-motion: reduce) {
            html, body, .page-shell {
                transition: none;
                animation: none;
            }
        }

        .hero-panel {
            position: relative;
            background: var(--bg-soft);
            color: #fff;
            padding: 0 22px 16px 32px;
            overflow: hidden;
            box-shadow: none;
            border: none;
        }

        .brand-row {
            position: relative;
            top: 0;
            left: 0;
            z-index: 3;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            font-size: 0.98rem;
            letter-spacing: 0.04em;
            text-transform: uppercase;
            font-weight: 800;
            color: #2d2148;
            margin: 0;
            white-space: nowrap;
        }

        .brand-mark {
            width: 38px;
            height: 38px;
            border-radius: 50%;
            background: transparent;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
            box-shadow: none;
            flex-shrink: 0;
        }

        .brand-mark img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            display: block;
            border-radius: 50%;
        }

        .hero-copy {
            position: relative;
            z-index: 1;
            margin-top: 10px;
            max-width: 620px;
        }

        .eyebrow {
            display: inline-block;
            padding: 8px 16px;
            border-radius: 999px;
            border: 1px solid rgba(122, 78, 218, 0.18);
            background: rgba(255,255,255,0.26);
            color: #5d42c9;
            text-transform: uppercase;
            letter-spacing: 0.14em;
            font-size: 0.62rem;
            font-weight: 800;
        }

        .hero-copy h1 {
            margin: 22px 0 0 0;
            font-size: 3.4rem;
            line-height: 0.84;
            letter-spacing: -0.08em;
            font-weight: 900;
            color: #2f224a;
        }

        .hero-copy .line {
            display: block;
            letter-spacing: -0.07em;
        }

        .visual-wrap {
            position: absolute;
            left: 50%;
            bottom: 20px;
            width: min(600px, 72%);
            height: 320px;
            transform: translateX(-50%);
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .mascot-wrap {
            position: absolute;
            left: 50%;
            top: 44%;
            transform: translate(-50%, -50%);
            width: 560px;
            max-width: 560px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .mascot-image {
            display: block;
            width: 560px;
            max-width: 560px;
            height: auto;
            object-fit: contain;
            filter: drop-shadow(0 28px 26px rgba(112, 72, 190, 0.16));
        }

        .tiny-pill {
            position: absolute;
            z-index: 2;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 8px 12px;
            border-radius: 999px;
            background: rgba(255,255,255,0.8);
            border: 1px solid rgba(255,255,255,0.5);
            color: #312654;
            font-weight: 700;
            font-size: 0.7rem;
            box-shadow: 0 12px 24px rgba(71, 38, 122, 0.08);
        }

        .tiny-pill.top {
            left: 68%;
            top: -24%;
            gap: 8px;
            padding: 8px 14px;
            font-size: 0.68rem;
        }

        .tiny-pill.bottom {
            left: 12%;
            bottom: 30%;
            width: 150px;
            height: 30px;
            gap: 7px;
            padding: 6px 12px;
            font-size: 0.62rem;
        }

        .tiny-dot,
        .ahead-dot {
            display: block;
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: var(--green);
            box-shadow: 0 0 0 4px rgba(39, 185, 128, 0.16);
        }

        .ahead-dot {
            background: #f59e0b;
            box-shadow: 0 0 0 4px rgba(245, 158, 11, 0.12);
        }

        .hero-note {
            position: absolute;
            left: 50%;
            bottom: 0;
            transform: translateX(-50%);
            width: min(440px, 72%);
            text-align: center;
            color: rgba(48, 35, 75, 0.76);
            font-size: 0.82rem;
            line-height: 1.6;
        }

        .hero-footer {
            position: absolute;
            left: 32px;
            bottom: 12px;
            color: rgba(48,35,75,0.7);
            font-size: 0.62rem;
            letter-spacing: 0.02em;
            line-height: 1.5;
            text-align: left;
        }

        .right-panel {
            display: grid;
            place-items: center;
            padding: 26px 14px 26px 0;
            background: var(--bg-soft);
            border: none;
            box-shadow: none;
        }

        .login-card {
            width: min(450px, 100%);
            height: 640px;
            min-height: 640px;
            padding: 18px 24px 20px;
            border-radius: 28px;
            background: #ffffff;
            border: 1px solid rgba(139, 99, 232, 0.2);
            box-shadow: none;
            display: flex;
            flex-direction: column;
            justify-content: flex-start;
        }

        .card-top {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 18px;
        }

        .mini-brand {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            font-weight: 900;
            letter-spacing: 0.05em;
            text-transform: uppercase;
            color: #2d1c4b;
            font-size: 1.05rem;
        }

        .mini-brand .brand-mark {
            width: 24px;
            height: 24px;
            font-size: 0.68rem;
        }

        .status-chip {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            border-radius: 999px;
            background: rgba(100, 205, 163, 0.12);
            border: 1px solid rgba(100, 205, 163, 0.25);
            color: #1d7d5c;
            padding: 8px 14px;
            font-size: 0.60rem;
            font-weight: 700;
        }

        .status-chip::before {
            content: "";
            display: block;
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: #4ad7a0;
            box-shadow: 0 0 0 4px rgba(74, 215, 160, 0.12);
        }

        h2 {
            margin: 6px 0 6px;
            font-size: 2rem;
            line-height: 1.02;
            letter-spacing: -0.07em;
            color: #2d2148;
        }

        .sub {
            margin: 0 0 14px;
            color: #5f667d;
            font-size: 0.69rem;
            font-weight: 400;
        }

        .error-box,
        .success-box {
            margin-bottom: 16px;
            padding: 12px 14px;
            border-radius: 12px;
            font-size: 0.8rem;
            line-height: 1.5;
            font-weight: 600;
        }

        .error-box {
            background: var(--error-bg);
            color: var(--error-text);
            border: 1px solid rgba(239, 68, 68, 0.18);
        }

        .success-box {
            background: var(--success-bg);
            color: var(--success-text);
            border: 1px solid rgba(34, 197, 94, 0.18);
        }

        .field {
            margin-bottom: 14px;
        }

        label {
            display: block;
            margin-bottom: 8px;
            color: #2b2241;
            font-size: 0.74rem;
            font-weight: 700;
            letter-spacing: 0.02em;
        }

        .input-shell {
            position: relative;
        }

        .input-shell input {
            width: 100%;
            height: 46px;
            border: 1px solid rgba(139, 99, 232, 0.45);
            border-radius: 12px;
            background: rgba(255,255,255,0.35);
            padding: 0 14px;
            color: #1c1833;
            font: inherit;
            font-size: 0.88rem;
            outline: none;
            transition: border-color 0.2s ease, box-shadow 0.2s ease;
        }

        .input-shell input:focus {
            border-color: var(--purple-1);
            box-shadow: 0 0 0 4px rgba(139, 99, 232, 0.12);
        }

        .password-wrap input {
            padding-right: 42px;
        }

        .password-toggle {
            position: absolute;
            top: 50%;
            right: 12px;
            transform: translateY(-50%);
            width: 22px;
            height: 22px;
            border: 0;
            background: transparent;
            color: #6f6a84;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 0;
        }

        .otp-input {
            letter-spacing: 0.36em;
            text-align: center;
            font-size: 1.2rem;
            font-weight: 700;
        }

        .primary-btn {
            width: 100%;
            height: 46px;
            border: 0;
            border-radius: 14px;
            background: linear-gradient(135deg, #8f62eb 0%, #7b4ed9 100%);
            color: #fff;
            font-size: 0.92rem;
            font-weight: 800;
            letter-spacing: -0.02em;
            cursor: pointer;
            margin-top: 6px;
            box-shadow: 0 18px 26px rgba(123, 78, 217, 0.22);
            transition: filter 0.15s ease, transform 0.15s ease;
        }

        .primary-btn:hover {
            filter: brightness(1.04);
            transform: translateY(-1px);
        }

        .footer-link {
            display: block;
            margin-top: 18px;
            text-align: center;
            color: #5e3ec0;
            text-decoration: none;
            font-size: 0.75rem;
            font-weight: 700;
        }

        .footer-link:hover {
            text-decoration: underline;
        }

        .footer-link-button {
            width: 100%;
            padding: 0;
            border: 0;
            background: transparent;
            cursor: pointer;
            font-family: inherit;
        }

        @media (max-width: 900px) {
            html, body {
                overflow: auto;
            }

            .page-shell {
                display: block;
                min-height: 100vh;
                height: auto;
            }

            .hero-panel {
                display: none;
            }

            .right-panel {
                min-height: 100vh;
                padding: 18px 12px;
            }

            .login-card {
                width: min(100%, 430px);
                min-height: auto;
            }
        }
    </style>
</head>
<body>
    <main class="page-shell">
        <section class="hero-panel">
            <div class="brand-row">
                <span class="brand-mark" aria-hidden="true"><img src="assets/images/mascot.png" alt="Queuezy mascot" /></span>
                <span>QUEUEZY</span>
            </div>

            <div class="hero-copy">
                <span class="eyebrow">Smart queue management</span>
                <h1>
                    <span class="line">RESET</span>
                    <span class="line">YOUR PASSWORD.</span>
                </h1>
            </div>

            <div class="visual-wrap" aria-hidden="true">
                <div class="tiny-pill top"><span class="tiny-dot"></span> We’ve got you covered</div>
                <div class="mascot-wrap">
                    <img class="mascot-image" src="assets/images/mascot.png" alt="Queuezy mascot" />
                </div>
                <div class="tiny-pill bottom"><span class="ahead-dot"></span> Secure recovery</div>
                
            </div>
            <footer class="hero-footer">&copy; 2026 Queuezy Inc. All rights reserved.</footer>
        </section>

        <section class="right-panel">
            <div class="login-card">
                <div class="card-top">
                    <div class="mini-brand">
                        <span class="brand-mark" aria-hidden="true"><img src="assets/images/mascot.png" alt="Queuezy mascot" /></span>
                        <span>QUEUEZY</span>
                    </div>
                    <span class="status-chip">Secure access</span>
                </div>

                <h2><?= $resetComplete ? 'Password changed' : ($step === 'otp' ? 'New password' : 'Forgot password?') ?></h2>

                <?php if ($error): ?>
                    <div class="error-box"><?= htmlspecialchars($error) ?></div>
                <?php endif; ?>

                <?php if ($success): ?>
                    <div class="success-box"><?= htmlspecialchars($success) ?></div>
                <?php endif; ?>

                <?php if ($resetComplete): ?>
                    <p class="sub">Your password has been updated. You can now sign in using your new password.</p>
                    <a class="footer-link" href="login.php">Go to login</a>
                <?php elseif ($step === 'email'): ?>
                    <p class="sub">Enter your email address and we’ll send you a one-time code to reset your account password.</p>

                    <form method="post" action="forgot_password.php">
                        <input type="hidden" name="action" value="request" />
                        <div class="field">
                            <label for="email">Gmail Address</label>
                            <div class="input-shell">
                                <input id="email" type="email" name="email" maxlength="254" placeholder="example@gmail.com" autocomplete="email" pattern="^(?=.{1,254}$)[A-Za-z0-9._%+\-]+@gmail\.com$" title="Please use a valid Gmail address (@gmail.com)." required />
                            </div>
                        </div>

                        <button type="submit" class="primary-btn">Send reset code</button>
                    </form>

                    <a class="footer-link" href="login.php">Back to login</a>
                <?php else: ?>
                    <p class="sub">We sent a six-digit code to <strong><?= htmlspecialchars($_SESSION['pending_password_reset']['email'] ?? '') ?></strong>. Enter it below and set a new password.</p>

                    <form method="post" action="forgot_password.php">
                        <input type="hidden" name="action" value="reset" />

                        <div class="field">
                            <label for="otp">Verification Code</label>
                            <div class="input-shell">
                                <input id="otp" class="otp-input" type="text" name="otp" inputmode="numeric" autocomplete="one-time-code" maxlength="6" pattern="[0-9]{6}" placeholder="123456" required />
                            </div>
                        </div>

                        <div class="field">
                            <label for="new_password">New Password</label>
                            <div class="input-shell password-wrap">
                                <input id="new_password" type="password" name="new_password" autocomplete="new-password" minlength="8" maxlength="16" pattern="(?=.*[A-Z])(?=.*[^A-Za-z0-9]).{8,16}" title="Use 8 to 16 characters, including an uppercase letter and a symbol." placeholder="8 to 16 characters" required />
                                <button type="button" class="password-toggle" aria-label="Show password">
                                    <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                        <path d="M2 12s3.5-6 10-6 10 6 10 6-3.5 6-10 6S2 12 2 12Z"></path>
                                        <circle cx="12" cy="12" r="3"></circle>
                                    </svg>
                                </button>
                            </div>
                        </div>

                        <div class="field">
                            <label for="confirm_password">Confirm Password</label>
                            <div class="input-shell password-wrap">
                                <input id="confirm_password" type="password" name="confirm_password" autocomplete="new-password" minlength="8" maxlength="16" pattern="(?=.*[A-Z])(?=.*[^A-Za-z0-9]).{8,16}" title="Use 8 to 16 characters, including an uppercase letter and a symbol." placeholder="Repeat your password" required />
                                <button type="button" class="password-toggle" aria-label="Show password">
                                    <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                        <path d="M2 12s3.5-6 10-6 10 6 10 6-3.5 6-10 6S2 12 2 12Z"></path>
                                        <circle cx="12" cy="12" r="3"></circle>
                                    </svg>
                                </button>
                            </div>
                        </div>

                        <button type="submit" class="primary-btn">Change password</button>
                    </form>

                    <form method="post" action="forgot_password.php">
                        <input type="hidden" name="action" value="request" />
                        <input type="hidden" name="email" value="<?= htmlspecialchars($_SESSION['pending_password_reset']['email'] ?? '', ENT_QUOTES, 'UTF-8') ?>" />
                        <button type="submit" class="footer-link footer-link-button">Request a new code</button>
                    </form>
                    <a class="footer-link" href="login.php">Cancel and return to login</a>
                <?php endif; ?>
            </div>
        </section>
    </main>

    <script>
        const otpInput = document.getElementById('otp');
        otpInput?.addEventListener('input', () => {
            otpInput.value = otpInput.value.replace(/[^0-9]/g, '').slice(0, 6);
        });

        document.addEventListener('DOMContentLoaded', () => {
            const authShell = document.querySelector('.page-shell');
            if (authShell) {
                requestAnimationFrame(() => {
                    authShell.classList.add('auth-enter');
                });
            }

            document.querySelectorAll('a[href^="login.php"], a[href^="forgot_password.php"]').forEach((link) => {
                link.addEventListener('click', (event) => {
                    const nextUrl = link.getAttribute('href');
                    if (!nextUrl || nextUrl.startsWith('#')) return;

                    event.preventDefault();
                    window.location.href = nextUrl;
                });
            });
        });

        document.querySelectorAll('.password-toggle').forEach((button) => {
            button.addEventListener('click', () => {
                const input = button.parentElement.querySelector('input');
                const showing = input.type === 'text';
                input.type = showing ? 'password' : 'text';
                button.setAttribute('aria-label', showing ? 'Show password' : 'Hide password');
                button.innerHTML = showing
                    ? '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M2 12s3.5-6 10-6 10 6 10 6-3.5 6-10 6S2 12 2 12Z"></path><circle cx="12" cy="12" r="3"></circle></svg>'
                    : '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 3l18 18"></path><path d="M10.58 10.58A2 2 0 0 0 13.42 13.42"></path><path d="M9.88 5.09A10.94 10.94 0 0 1 12 5c6.5 0 10 7 10 7a17.9 17.9 0 0 1-4.22 5.17"></path><path d="M6.61 6.61A16.86 16.86 0 0 0 2 12s3.5 7 10 7a11.63 11.63 0 0 0 5.39-1.61"></path></svg>';
            });
        });
    </script>
</body>
</html>
