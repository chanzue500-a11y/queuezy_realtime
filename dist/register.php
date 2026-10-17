<?php
require __DIR__ . '/../PHPMailer/src/Exception.php';
require __DIR__ . '/../PHPMailer/src/PHPMailer.php';
require __DIR__ . '/../PHPMailer/src/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
session_start();
require_once __DIR__ . '/database/db.php';

$error = '';
$old = ['fullname' => '', 'email' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $old['fullname'] = trim($_POST['fullname'] ?? $_POST['name'] ?? '');
    $old['email'] = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirmPassword = $_POST['password_confirmation'] ?? '';

    if ($old['fullname'] === '' || !filter_var($old['email'], FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter your name and a valid email address.';
    } elseif (!preg_match('/^[A-Za-z ]{1,35}$/', $old['fullname'])) {
        $error = 'Full name must contain letters and spaces only, with a maximum of 35 characters.';
    } elseif (!preg_match('/^[A-Za-z0-9._%+\-]+@gmail\.com$/i', $old['email'])) {
        $error = 'Please use a valid Gmail address (@gmail.com).';
    } elseif (strlen($password) < 8 || strlen($password) > 16) {
        $error = 'Your password must be 8 to 16 characters long and include an uppercase letter and a symbol.';
    } elseif (!preg_match('/[A-Z]/', $password) || !preg_match('/[^A-Za-z0-9]/', $password)) {
        $error = 'Your password must include at least one uppercase letter and one symbol.';
    } elseif ($password !== $confirmPassword) {
        $error = 'Your passwords do not match.';
    } elseif (!isset($_POST['terms'])) {
        $error = 'Please accept the terms and conditions.';
    } elseif (!$conn) {
        $error = 'The account service is temporarily unavailable.';
    } else {
        $check = $conn->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
        if ($check) {
            $check->bind_param('s', $old['email']);
            $check->execute();
            $exists = $check->get_result()->fetch_assoc();
            $check->close();

            if ($exists) {
                $error = 'An account with that email already exists.';
            } else {
                $hash = password_hash($password, PASSWORD_DEFAULT);
                $otp = (string) random_int(100000, 999999);
                $otpExpiresAt = time() + 600;
                $mail = new PHPMailer(true);
            $mailSent = false;

            try {
            $mail->isSMTP();
            $mail->Host       = 'smtp.gmail.com';
            $mail->SMTPAuth   = true;
            $mail->Username   = 'developerchan25@gmail.com';
            $mail->Password   = 'ygxoadwjsodnopos';
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS; // IDAGDAG ITO
            $mail->Port       = 587;

                $mail->setFrom('developerchan25@gmail.com', 'QUEUEZY');
                $mail->addAddress($old['email']);

                $mail->isHTML(false);
                $mail->Subject = 'Your Queuezy verification code';
                $mail->Body    = "Hello {$old['fullname']},\n\nYour Queuezy verification code is: {$otp}\n\nThis code expires in 10 minutes.";

                $mail->send();
                $mailSent = true;
            } catch (Exception $e) {
                $mailSent = false;
            }

            if ($mailSent) {
              
                    $_SESSION['pending_registration'] = [
                        'fullname' => $old['fullname'],
                        'email' => $old['email'],
                        'password_hash' => $hash,
                        'otp_hash' => password_hash($otp, PASSWORD_DEFAULT),
                        'otp_expires_at' => $otpExpiresAt,
                    ];
                    header('Location: verify_otp.php');
                    exit;
                }
                $error = 'We could not send the verification email. Please check your mail configuration and try again.';
            }
        } else {
            $error = 'Registration is not configured for the current database.';
        }
    }
}

$old['name'] = $old['fullname'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Create account | Queuezy</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="preconnect" href="https://fonts.googleapis.com" />
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet" />
    <style>
        :root {
            --bg-soft: #e9d9ff;
            --purple-1: #8b63e8;
            --purple-2: #8b63e8;
            --purple-3: #8b63e8;
            --card: rgba(255,255,255,0.18);
            --text: #1c1833;
            --muted: #677085;
            --line: #d5c0ff;
            --green: #27b980;
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
            max-height: 100vh;
            margin: 0;
            display: grid;
            grid-template-columns: 1.1fr 0.9fr;
            background: var(--bg-soft);
            overflow: hidden;
            gap: 0;
            padding: 0;
            border: none;
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
            padding: 28px 22px 16px 32px;
            overflow: hidden;
            box-shadow: none;
            border: none;
        }

        .hero-panel::before,
        .hero-panel::after {
            content: none !important;
            display: none !important;
            background: transparent !important;
        }

        .brand-row {
            position: absolute;
            top: 15px;
            left: 32px;
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
            transform: none;
            right: auto;
            bottom: auto;
            line-height: 1;
            white-space: nowrap;
        }

        .brand-mark {
            width: 26px;
            height: 26px;
            border-radius: 50%;
            background: transparent;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
            box-shadow: none;
            flex-shrink: 0;
            transform: none;
        }

        .brand-mark img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            display: block;
            border-radius: 50%;
        }

        .brand-mark--queuezy {
            position: relative;
            width: 38px;
            height: 38px;
            border-radius: 50%;
            background: transparent;
            box-shadow: none;
            overflow: hidden;
            transform: none;
            left: auto;
            top: auto;
        }

        .brand-mark--queuezy::before {
            content: "";
            position: absolute;
            inset: 0;
            border-radius: 50%;
            background: transparent;
        }

        .hero-copy {
            position: relative;
            z-index: 1;
            margin-top: 32px;
        }

        .eyebrow {
            display: inline-block;
            padding: 9px 18px;
            border-radius: 999px;
            border: 1px solid rgba(122, 78, 218, 0.18);
            background: rgba(255,255,255,0.26);
            color: #5a3dc4;
            text-transform: uppercase;
            letter-spacing: 0.12em;
            font-size: 0.58rem;
            font-weight: 800;
        }

        .hero-copy h1 {
            margin: 22px 0 0 0;
            font-size: 3.4rem;
            line-height: 0.84;
            letter-spacing: -0.08em;
            font-weight: 900;
            color: #2f224a;
            max-width: 620px;
        }

        .hero-copy h1 .line { display: block; letter-spacing: -0.07em; }


        .visual-wrap {
            position: relative;
            z-index: 1;
            margin-top: 74px;
            width: 100%;
            height: 420px;
        }

        .mascot-wrap {
            position: absolute;
            left: 50%;
            top: 36%;
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
            padding: 10px 16px;
            background: rgba(255,255,255,0.8);
            border: 1px solid rgba(255,255,255,0.5);
            color: #312654;
            border-radius: 999px;
            font-size: 0.76rem;
            font-weight: 700;
            box-shadow: 0 12px 24px rgba(71, 38, 122, 0.08);
        }

        .tiny-pill.top {
            left: 68%;
            top: -24%;
            gap: 8px;
            padding: 8px 14px;
            font-size: 0.68rem;
        }

        .tiny-pill.top .tiny-dot {
            width: 8px;
            height: 8px;
            background: #3b82f6;
            box-shadow: 0 0 0 4px rgba(59, 130, 246, 0.14), 0 0 12px rgba(59, 130, 246, 0.78);
        }

        .tiny-pill.bottom {
            left: 12%;
            bottom: 30%;
            width: 150px;
            height: 30px;
            gap: 7px;
            padding: 6px 12px;
            background: rgba(255,255,255,0.78);
            border-color: rgba(255,255,255,0.7);
            font-size: 0.62rem;
        }

        .tiny-dot {
            width: 9px;
            height: 9px;
            border-radius: 50%;
            background: #873bfa;
            box-shadow: 0 0 0 4px rgba(135, 59, 250, 0.12);
        }

        .ahead-dot {
            display: inline-block;
            width: 9px;
            height: 9px;
            border-radius: 50%;
            background: #873bfa;
            box-shadow: 0 0 0 4px rgba(135, 59, 250, 0.12);
        }

        .hero-note {
            position: absolute;
            left: 12%;
            bottom: 15%;
            max-width: 360px;
            color: rgba(48, 35, 75, 0.8);
            font-size: 0.82rem;
            line-height: 1.45;
            font-weight: 500;
        }

        .hero-footer {
            position: absolute;
            left: 32px;
            bottom: 12px;
            z-index: 2;
            height: auto;
            display: flex;
            align-items: center;
            justify-content: flex-start;
            color: rgba(48, 35, 75, 0.72);
            font-size: 0.62rem;
            line-height: 1.5;
            white-space: nowrap;
            text-align: left;
        }

        .right-panel {
            display: grid;
            place-items: center;
            background: var(--bg-soft);
            padding: 24px 12px;
            overflow: hidden;
            border: none;
            box-shadow: none;
        }

        .login-card {
            width: min(450px, 100%);
            height: 640px;
            min-height: 640px;
            background: #ffffff;
            border: 1px solid rgba(139, 99, 232, 0.2);
            border-radius: 30px;
            box-shadow: none;
            padding: 14px 22px;
            margin-top: 0;
            backdrop-filter: blur(10px);
            overflow: hidden;
            display: flex;
            flex-direction: column;
            justify-content: flex-start;
        }

        .card-top {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 10px;
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

        .login-card h2 {
            margin: 3px 0 5px;
            font-size: 2rem;
            line-height: 1.02;
            letter-spacing: -0.06em;
            font-weight: 900;
            color: #2d2148;
        }

        .login-card .sub {
            margin: 0 0 8px;
            font-size: 0.69rem;
            color: #5f667d;
            font-weight: 400;
        }

        .field {
            margin-bottom: 6px;
        }

        .field label {
            display: block;
            margin-bottom: 4px;
            color: #384258;
            font-size: 0.76rem;
            font-weight: 700;
        }

        .input-shell {
            position: relative;
        }

        .input-shell input {
            width: 100%;
            height: 40px;
            border: 1px solid var(--line);
            border-radius: 12px;
            background: rgba(255,255,255,0.65);
            padding: 0 12px;
            font-size: 0.82rem;
            color: #213042;
            outline: none;
            transition: box-shadow 0.2s ease, border-color 0.2s ease;
        }

        .input-shell input::placeholder { color: #8d93a0; }
        .input-shell input:focus {
            border-color: rgba(122, 78, 218, 0.7);
            box-shadow: 0 0 0 4px rgba(122, 78, 218, 0.08);
        }

        .password-wrap input {
            padding-right: 44px;
        }

        .password-toggle {
            position: absolute;
            top: 50%;
            right: 12px;
            transform: translateY(-50%);
            width: 24px;
            height: 24px;
            display: grid;
            place-items: center;
            border: none;
            background: transparent;
            color: #687384;
            cursor: pointer;
        }

        .check-row {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 8px;
            margin: 2px 0 8px;
            color: #455264;
            font-size: 0.69rem;
        }

        .check-row input {
            width: 15px;
            height: 15px;
            accent-color: var(--purple-1);
        }

        .check-row a {
            color: var(--purple-1);
            font-weight: 700;
            text-underline-offset: 2px;
        }

        .policy-dialog {
            width: min(680px, calc(100% - 32px));
            max-width: none;
            max-height: 82vh;
            padding: 0;
            overflow: hidden;
            border: 0;
            border-radius: 16px;
            background: #fff;
            box-shadow: 0 24px 70px rgba(31, 24, 51, 0.28);
        }

        .policy-dialog::backdrop { background: rgba(24, 20, 39, 0.55); }

        .policy-dialog__heading {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            padding: 14px 18px;
            color: #29233c;
            font-size: 0.92rem;
            font-weight: 800;
        }

        .policy-dialog__close {
            display: grid;
            width: 34px;
            height: 34px;
            place-items: center;
            border: 0;
            border-radius: 9px;
            background: #f1eef7;
            color: #453963;
            font-size: 1.25rem;
            cursor: pointer;
        }

        .policy-dialog iframe {
            display: block;
            width: 100%;
            height: min(64vh, 560px);
            min-height: 280px;
            border: 0;
        }

        .primary-btn {
            display: block;
            width: 100%;
            height: 40px;
            border: none;
            border-radius: 14px;
            background: linear-gradient(135deg, #8f62eb 0%, #7b4ed9 100%);
            color: #fff;
            font-size: 0.92rem;
            font-weight: 800;
            letter-spacing: -0.02em;
            box-shadow: 0 18px 26px rgba(123, 78, 217, 0.22);
            cursor: pointer;
            margin-top: 2px;
        }

        .account-cta {
            margin-top: 8px;
            text-align: center;
            color: #5c697f;
            font-size: 0.75rem;
        }

        .account-cta a {
            color: #5a41c7;
            text-decoration: none;
            font-weight: 700;
        }

        .error-box {
            margin: 0 0 9px;
            padding: 10px 12px;
            background: rgba(254, 242, 242, 0.9);
            border: 1px solid rgba(239,68,68,0.18);
            border-radius: 12px;
            color: #b91c1c;
            font-size: 0.69rem;
            font-weight: 600;
        }

        @media (max-width: 980px) {
            html, body {
                overflow: hidden;
            }

            .page-shell {
                display: flex;
                align-items: center;
                justify-content: center;
                width: 100vw;
                height: 100vh;
                max-width: 100vw;
                max-height: 100vh;
                margin: 0;
                padding: 0;
                border-radius: 0;
                background: #f3ebff;
            }

            .hero-panel {
                display: none;
            }

            .right-panel {
                display: flex;
                align-items: center;
                justify-content: center;
                width: 100%;
                height: 100vh;
                padding: 14px 12px;
                background: #f3ebff;
                place-items: unset;
            }

            .login-card {
                width: min(100%, 430px);
                max-width: 100%;
                height: auto;
                min-height: 0;
                margin: 0 auto;
                
            }
        }

        @media (max-width: 640px) {
            .hero-panel {
                padding: 20px 20px 18px;
            }

            .hero-copy h1 {
                font-size: 3.4rem;
            }

            .right-panel {
                padding: 18px 16px 26px;
            }

            .login-card {
                padding: 12px 18px;
            }
        }
    </style>
</head>
<body>
    <main class="page-shell">
        <section class="hero-panel">
            <div class="brand-row">
                <span class="brand-mark brand-mark--queuezy" aria-hidden="true"><img src="assets/images/mascot.png" alt="Queuezy mascot" /></span>
                <span>QUEUEZY</span>
            </div>

            <div class="hero-copy">
                <span class="eyebrow">Get started with Queuezy</span>
                <h1>
                    YOUR PLACE IN LINE,
                    WITHOUT THE LINE.
                </h1>
            </div>

            <div class="visual-wrap" aria-hidden="true">
                <div class="tiny-pill top"><span class="tiny-dot"></span> Your turn is approaching</div>
                <div class="mascot-wrap">
                    <img class="mascot-image" src="assets/images/mascot.png" alt="Queuezy mascot" />
                </div>
                <div class="tiny-pill bottom"><span class="ahead-dot"></span> 2 people ahead</div>
                <div class="hero-note">Get started with Queuezy and turn waiting time into time you can actually use.</div>
            </div>
            <footer class="hero-footer">&copy; 2026 Queuezy Inc. All rights reserved.</footer>
        </section>

        <section class="right-panel">
            <div class="login-card">
                <div class="card-top">
                    <div class="mini-brand">
                        <span class="brand-mark brand-mark--queuezy" aria-hidden="true"><img src="assets/images/mascot.png" alt="Queuezy mascot" /></span>
                        <span>QUEUEZY</span>
                    </div>
                    <span class="status-chip">Secure access</span>
                </div>

                <h2>Your turn starts here.</h2>
                <p class="sub">Create your account and make waiting a little smarter.</p>

                <?php if ($error): ?>
                    <div class="error-box"><?= htmlspecialchars($error) ?></div>
                <?php endif; ?>

                <form method="post" action="">
                    <div class="field">
                        <label for="fullname">Full Name</label>
                        <div class="input-shell">
                            <input id="fullname" type="text" name="fullname" value="<?= htmlspecialchars($old['fullname']) ?>" placeholder="Juan Dela Cruz" maxlength="35" pattern="[A-Za-z ]{1,35}" title="Use letters and spaces only, maximum 35 characters" oninput="this.value = this.value.replace(/[0-9]/g, '')" required />
                        </div>
                    </div>

                    <div class="field">
                        <label for="email">Gmail Address</label>
                        <div class="input-shell">
                            <input id="email" type="email" name="email" maxlength="30" value="<?= htmlspecialchars($old['email']) ?>" placeholder="example@gmail.com" autocomplete="email" pattern="^[A-Za-z0-9._%+\-]+@gmail\.com$" title="Please use a valid Gmail address (@gmail.com)." required />
                        </div>
                    </div>

                    <div class="field">
                        <label for="password">Password</label>
                        <div class="input-shell password-wrap">
                            <input id="password" type="password" name="password" placeholder="8 to 16 characters" minlength="8" maxlength="16" pattern="(?=.*[A-Z])(?=.*[^A-Za-z0-9]).{8,16}" title="Use 8 to 16 characters, including an uppercase letter and a symbol." autocomplete="new-password" required />
                            <button type="button" class="password-toggle" aria-label="Show password">
                                <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                    <path d="M2 12s3.5-6 10-6 10 6 10 6-3.5 6-10 6S2 12 2 12Z"></path>
                                    <circle cx="12" cy="12" r="3"></circle>
                                </svg>
                            </button>
                        </div>
                    </div>

                    <div class="field">
                        <label for="password_confirmation">Confirm password</label>
                        <div class="input-shell password-wrap">
                            <input id="password_confirmation" type="password" name="password_confirmation" placeholder="Repeat your password" minlength="8" maxlength="16" pattern="(?=.*[A-Z])(?=.*[^A-Za-z0-9]).{8,16}" title="Use 8 to 16 characters, including an uppercase letter and a symbol." autocomplete="new-password" required />
                            <button type="button" class="password-toggle" aria-label="Show password">
                                <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                    <path d="M2 12s3.5-6 10-6 10 6 10 6-3.5 6-10 6S2 12 2 12Z"></path>
                                    <circle cx="12" cy="12" r="3"></circle>
                                </svg>
                            </button>
                        </div>
                    </div>

                    <div class="check-row">
                        <input id="terms" type="checkbox" name="terms" aria-label="I agree to the Queuezy Terms of Service and Privacy Policy" required />
                        <span>I agree to the Queuezy</span>
                        <a href="policies.php#terms" data-policy-link>Terms of Service</a>
                        <span>and</span>
                        <a href="policies.php#privacy" data-policy-link>Privacy Policy</a>.
                    </div>

                    <button type="submit" class="primary-btn">Create account</button>
                </form>

                <p class="account-cta">Already have an account? <a href="login.php#flip">Log in</a></p>
            </div>
        </section>
    </main>

    <dialog class="policy-dialog" id="policyDialog" aria-labelledby="policyDialogTitle">
        <div class="policy-dialog__heading">
            <span id="policyDialogTitle">Queuezy policies</span>
            <button class="policy-dialog__close" id="policyDialogClose" type="button" aria-label="Close policy">&times;</button>
        </div>
        <iframe id="policyFrame" title="Queuezy policy text" src="about:blank"></iframe>
    </dialog>

    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const authShell = document.querySelector('.page-shell');
            if (authShell) {
                requestAnimationFrame(() => {
                    authShell.classList.add('auth-enter');
                });
            }

            document.querySelectorAll('a[href^="login.php"], a[href^="register.php"], a[href^="forgot_password.php"]').forEach((link) => {
                link.addEventListener('click', (event) => {
                    const nextUrl = link.getAttribute('href');
                    if (!nextUrl || nextUrl.startsWith('#')) return;

                    event.preventDefault();
                    window.location.href = nextUrl;
                });
            });

            const policyDialog = document.getElementById('policyDialog');
            const policyFrame = document.getElementById('policyFrame');
            document.querySelectorAll('[data-policy-link]').forEach((link) => {
                link.addEventListener('click', (event) => {
                    event.preventDefault();
                    policyFrame.src = link.href;
                    policyDialog.showModal();
                });
            });
            document.getElementById('policyDialogClose').addEventListener('click', () => policyDialog.close());
            policyDialog.addEventListener('close', () => { policyFrame.src = 'about:blank'; });
            policyDialog.addEventListener('click', (event) => {
                if (event.target === policyDialog) policyDialog.close();
            });
        });

        const passwordToggles = document.querySelectorAll('.password-toggle');
        passwordToggles.forEach((button) => {
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
