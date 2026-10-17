<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (isset($_GET['logout'])) {
    $_SESSION = [];
    session_destroy();
    session_start();
}
require_once __DIR__ . '/database/db.php';
require_once __DIR__ . '/includes/auth_check.php';

$error = '';
$success = $_SESSION['registration_success'] ?? '';
unset($_SESSION['registration_success']);

$resetSuccess = $_SESSION['reset_success'] ?? '';
unset($_SESSION['reset_success']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || $password === '') {
        $error = 'Please enter a valid email and password.';
    } elseif (!preg_match('/^[A-Za-z0-9._%+\-]+@gmail\.com$/i', $email)) {
        $error = 'Please use a valid Gmail address.';
    } elseif (!$conn) {
        $error = 'The account service is temporarily unavailable.';
    } else {
        $stmt = $conn->prepare('SELECT * FROM users WHERE email = ? LIMIT 1');
        if ($stmt) {
            $stmt->bind_param('s', $email);
            $stmt->execute();
            $result = $stmt->get_result();
            $account = $result ? $result->fetch_assoc() : null;
            $storedPassword = $account['password_hash'] ?? $account['password'] ?? '';
            $validPassword = $account && (password_verify($password, $storedPassword) || hash_equals((string) $storedPassword, $password));

            if ($validPassword) {
                $role = strtolower($account['role'] ?? $account['user_role'] ?? 'customer');
                unset($_SESSION['guest_mode']);
                $_SESSION['user_id'] = (int) ($account['id'] ?? $account['user_id']);
                $_SESSION['user_role'] = $role;
                $_SESSION['user_name'] = $account['fullname'] ?? $account['name'] ?? $account['full_name'] ?? $email;
                redirectToRoleDashboard($role);
            }

            $error = 'Wrong email or password.';
            $stmt->close();
        } else {
            $error = 'An error occurred while processing your request.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Queuezy | Login</title>
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
            --panel: #e9d9ff;
            --card: rgba(255, 255, 255, 0.18);
            --text: #1c1833;
            --muted: #677085;
            --line: #d5c0ff;
            --green: #27b980;
            --soft-purple: #e9d9ff;
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
            padding: 0;
            gap: 0;
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
            border-radius: 0;
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
            width: 28px;
            height: 28px;
            border-radius: 50%;
            background: transparent;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
            box-shadow: none;
            position: relative;
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
            z-index: 2;
            margin-top: 32px;
            margin-left: 0;
            overflow: visible;
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
            position: relative;
            z-index: 2;
            margin: 22px 0 0 0;
            font-size: 3.4rem;
            line-height: 0.84;
            letter-spacing: -0.08em;
            font-weight: 900;
            color: #2f224a;
            max-width: 620px;
            overflow: visible;
            white-space: normal;
        }

        .hero-copy .line {
            display: block;
            letter-spacing: -0.07em;
            white-space: normal;
        }

        
        .hero-visual {
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

        .hero-visual .on-top {
            position: absolute;
            left: 68%;
            top: -24%;
            transform: none;
            z-index: 10;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 8px 14px;
            border-radius: 999px;
            background: rgba(255,255,255,0.8);
            border: 1px solid rgba(255,255,255,0.5);
            color: #312654;
            font-size: 0.68rem;
            font-weight: 700;
            box-shadow: 0 12px 24px rgba(71, 38, 122, 0.08);
        }

        .hero-visual .on-top::before {
            content: "";
            display: inline-block;
            width: 8px;
            height: 8px;
            background: #3b82f6;
            border-radius: 50%;
            box-shadow: 0 0 0 4px rgba(59, 130, 246, 0.14), 0 0 12px rgba(59, 130, 246, 0.78);
        }

        .hero-visual .bottom-badge {
            position: absolute;
            left: 12%;
            bottom: 30%;
            z-index: 10;
            display: inline-flex;
            align-items: center;
            gap: 7px;
            width: 150px;
            height: 30px;
            padding: 6px 12px;
            border-radius: 999px;
            background: rgba(255,255,255,0.78);
            border: 1px solid rgba(255,255,255,0.7);
            color: #312654;
            font-size: 0.62rem;
            font-weight: 700;
            box-shadow: 0 12px 24px rgba(71, 38, 122, 0.08);
        }

        .hero-visual .bottom-badge .ahead-dot {
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
            padding: 26px 14px 26px 0;
            background: var(--bg-soft);
            border: none;
            box-shadow: none;
        }

        .login-card {
            width: min(450px, 100%);
            height: 640px;
            min-height: 640px;
            background: #ffffff;
            border: 1px solid rgba(139, 99, 232, 0.2);
            border-radius: 28px;
            box-shadow: none;
            padding: 18px 24px 20px;
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

        .login-card h2 {
            margin: 6px 0 6px;
            font-size: 2rem;
            line-height: 1.02;
            letter-spacing: -0.07em;
            font-weight: 900;
            color: #2d2148;
        }

        .login-card p.sub {
            margin: 0 0 14px;
            font-size: 0.69rem;
            color: #5f667d;
            font-weight: 400;
        }

        .field {
            margin-bottom: 12px;
        }

        .field label {
            display: block;
            margin-bottom: 7px;
            color: #384258;
            font-size: 0.74rem;
            font-weight: 700;
        }

        .input-shell {
            position: relative;
        }

        .input-shell input {
            width: 100%;
            height: 42px;
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
            padding-right: 46px;
        }

        .password-toggle {
            position: absolute;
            top: 50%;
            right: 12px;
            transform: translateY(-50%);
            width: 22px;
            height: 22px;
            display: grid;
            place-items: center;
            border: none;
            background: transparent;
            color: #687384;
            cursor: pointer;
        }

        .row-between {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            margin: 2px 0 12px;
        }

        .remember {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            color: #445068;
            font-size: 0.74rem;
            font-weight: 500;
        }

        .remember input {
            width: 16px;
            height: 16px;
            accent-color: var(--purple-1);
        }

        .inline-link {
            color: #4c566e;
            text-decoration: none;
            font-size: 0.74rem;
            font-weight: 700;
        }

        .primary-btn {
            display: block;
            width: 100%;
            height: 40px;
            border: none;
            border-radius: 14px;
            background: linear-gradient(135deg, #8f62eb 0%, #7b4ed9 100%);
            color: #fff;
            font-size: 0.9rem;
            font-weight: 800;
            letter-spacing: -0.02em;
            box-shadow: 0 18px 26px rgba(123, 78, 217, 0.22);
            cursor: pointer;
            margin-top: 4px;
        }

        .divider {
            position: relative;
            margin:1px 0 5px;
            text-align: center;
            color: #77829a;
            font-size: 0.9rem;
            font-weight: 700;
            letter-spacing: 0.05em;
        }

        .divider::before {
            content: none;
            position: absolute;
            left: 0;
            right: 0;
            top: 50%;
            height: 1px;
            background: transparent;
        }

        .divider span {
            position: relative;
           
            padding: 0 10px;
            z-index: 1;
        }

        .guest-btn {
            display: flex;
            align-items: center;
            justify-content: center;
            width: 100%;
            height: 40px;
            border-radius: 14px;
            border: 1px solid rgba(122, 78, 218, 0.42);
            background: rgba(255,255,255,0.36);
            color: #4e2ea1;
            text-decoration: none;
            font-size: 15px;
            font-weight: 700;
        }

        .bottom-text {
            margin: 22px 0 0;
            text-align: center;
            color: #5d687d;
            font-size: 0.75rem;
        }

        .bottom-text a {
            color: #5e44c7;
            text-decoration: none;
            font-weight: 700;
        }

        .error-box {
            margin: 0 0 18px;
            padding: 12px 14px;
            background: rgba(254, 242, 242, 0.9);
            border: 1px solid rgba(239,68,68,0.18);
            border-radius: 12px;
            color: #b91c1c;
            font-size: 0.85rem;
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
                min-height: auto;
                margin: 0 auto;
            }
        }

        @media (max-width: 640px) {
            .hero-panel {
                display: none;
            }

            .right-panel {
                padding: 10px 12px;
            }

            .login-card {
                min-height: auto;
                padding: 20px 18px 18px;
            }

            .hero-copy h1 {
                font-size: 3.4rem;
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
                <span class="eyebrow">Smart queue management</span>
                <h1>
                    <span class="line">THE SMART WAY</span>
                    <span class="line">TO WAIT.</span>
                </h1>
            </div>

            <div class="hero-visual" aria-hidden="true">
                <div class="on-top">Your turn is approaching</div>
                <div class="mascot-wrap">
                    <img class="mascot-image" src="assets/images/mascot.png" alt="Queuezy mascot" />
                </div>
                <div class="bottom-badge"><span class="ahead-dot"></span> 2 people ahead</div>
                <div class="hero-note">Join a queue remotely, follow your progress, and arrive when it’s almost your turn.</div>
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

                <h2>Welcome back!</h2>

                <?php if ($error): ?>
                    <div class="error-box"><?= htmlspecialchars($error) ?></div>
                <?php endif; ?>

                <?php if ($resetSuccess): ?>
                    <div class="success-box"><?= htmlspecialchars($resetSuccess) ?></div>
                <?php endif; ?>

                <p class="sub">Log in to continue your place in line.</p>

                <form method="post" action="" autocomplete="off">
                    <div class="field">
                        <label for="email">Gmail Address</label>
                        <div class="input-shell">
                            <input id="email" type="email" name="email" maxlength="30" placeholder="example@gmail.com" autocomplete="off" pattern="^[A-Za-z0-9._%+\-]+@gmail\.com$" title="Please use a valid Gmail address." required />
                        </div>
                    </div>
                    
                    <div class="field">
                        <label for="password">Password</label>
                        <div class="input-shell password-wrap">
                            <input id="password" type="password" name="password" minlength="8" maxlength="16" placeholder="Enter your password" autocomplete="off" required />
                            <button type="button" class="password-toggle" aria-label="Show password">
                                <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                    <path d="M2 12s3.5-6 10-6 10 6 10 6-3.5 6-10 6S2 12 2 12Z"></path>
                                    <circle cx="12" cy="12" r="3"></circle>
                                </svg>
                            </button>
                        </div>
                    </div>

                    <div class="row-between">
                        <label class="remember">
                            <input type="checkbox" name="remember" />
                            <span>Remember me</span>
                        </label>
                        <a class="inline-link" href="forgot_password.php?start=1">Forgot password?</a>
                    </div>

                    <button type="submit" class="primary-btn">Log In</button>
                </form>

                <div class="divider"><span>or</span></div>

                <a href="customer/index.php?guest=1" class="guest-btn">Sign in as a guest</a>

                <p class="bottom-text">Don't have an account? <a href="register.php#flip">Sign up here</a></p>
            </div>
        </section>
    </main>

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
