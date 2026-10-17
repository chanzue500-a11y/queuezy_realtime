<?php
session_start();
require_once __DIR__ . '/database/db.php';

$pending = $_SESSION['pending_registration'] ?? null;
$error = '';
$success = $_SESSION['verification_success'] ?? '';
unset($_SESSION['verification_success']);

if (isset($_GET['verified'])) {
    $pending = null;
} elseif (!$pending) {
    header('Location: register.php');
    exit;
}

if (isset($_GET['cancel'])) {
    unset($_SESSION['pending_registration']);
    header('Location: register.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $otp = trim($_POST['otp'] ?? '');

    if (!preg_match('/^\d{6}$/', $otp)) {
        $error = 'Enter the six-digit verification code.';
    } elseif (time() > (int) ($pending['otp_expires_at'] ?? 0)) {
        $error = 'This verification code has expired. Please register again.';
        unset($_SESSION['pending_registration']);
    } elseif (!password_verify($otp, $pending['otp_hash'] ?? '')) {
        $error = 'That verification code is incorrect.';
    } elseif (!$conn) {
        $error = 'The account service is temporarily unavailable.';
    } else {
        $role = 'customer';
        $insert = $conn->prepare('INSERT INTO users (fullname, email, password, role) VALUES (?, ?, ?, ?)');
        if ($insert) {
            $insert->bind_param('ssss', $pending['fullname'], $pending['email'], $pending['password_hash'], $role);
            if ($insert->execute()) {
                unset($_SESSION['pending_registration']);
                $_SESSION['verification_success'] = 'Create account successfully';
                $insert->close();
                header('Location: verify_otp.php?verified=1');
                exit;
            }
            $error = 'We could not create your account. It may already exist.';
            $insert->close();
        } else {
            $error = 'Registration is not configured for the current database.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Verify email | Queuezy</title>
    <link rel="preconnect" href="https://fonts.googleapis.com" />
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet" />
    <style>
        :root {
            --bg: #e9d9ff;
            --ink: #24163f;
            --muted: #6e6880;
            --purple: #7b4ed9;
            --line: #d5c0ff;
        }

        * { box-sizing: border-box; }

        html, body { min-height: 100%; margin: 0; }

        body {
            height: 100vh;
            overflow: hidden;
            display: grid;
            place-items: center;
            padding: 32px;
            background: radial-gradient(circle at 18% 12%, rgba(255,255,255,0.55), transparent 32%), var(--bg);
            color: var(--ink);
            font-family: 'Inter', sans-serif;
        }

        .page {
            display: grid;
            grid-template-columns: 1fr 1fr;
            width: min(1240px, calc(100vw - 64px));
            height: min(680px, calc(100vh - 64px));
            min-height: 0;
            overflow: hidden;
            border-radius: 28px;
            background: rgba(255,255,255,0.2);
            box-shadow: 0 24px 70px rgba(78, 46, 132, 0.16);
        }

        .intro {
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            padding: 42px 48px;
        }

        .brand { display: inline-flex; align-items: center; gap: 9px; font-size: 1rem; font-weight: 900; letter-spacing: 0.04em; transform: translateY(-12px); }
        .brand img { width: 38px; height: 38px; border-radius: 50%; object-fit: cover; }
        .intro-copy { margin-top: 72px; }
        .eyebrow { color: #5d42c9; font-size: 0.64rem; font-weight: 800; letter-spacing: 0.14em; text-transform: uppercase; }
        .intro h1 { max-width: 390px; margin: 16px 0 0; font-size: clamp(2.5rem, 5vw, 5rem); line-height: 0.88; letter-spacing: -0.08em; }
        .mascot { display: block; width: min(360px, 100%); margin: 2px auto 0; transform: translateY(-24px); filter: drop-shadow(0 20px 18px rgba(112, 72, 190, 0.16)); }
        .copyright { color: rgba(48,35,75,0.7); font-size: 0.62rem; text-align: center; }

        .card {
            align-self: center;
            width: min(540px, calc(100% - 48px));
            margin: 18px;
            padding: 52px;
            border: 1px solid rgba(139, 99, 232, 0.18);
            border-radius: 24px;
            background: #fff;
        }

        .card h2 { margin: 0 0 10px; font-size: 2.2rem; line-height: 1; letter-spacing: -0.07em; }
        .card p { margin: 0; color: var(--muted); font-size: 0.86rem; line-height: 1.55; }
        .email { color: #4e2ea1; font-weight: 700; overflow-wrap: anywhere; }
        .error { margin: 18px 0 0; padding: 12px 14px; border: 1px solid rgba(239,68,68,0.2); border-radius: 12px; background: #fff3f3; color: #b91c1c; font-size: 0.78rem; line-height: 1.4; }
        label { display: block; margin: 24px 0 8px; color: #384258; font-size: 0.74rem; font-weight: 700; }
        input { width: 100%; height: 60px; border: 1px solid var(--line); border-radius: 12px; padding: 0 14px; color: var(--ink); font-size: 1.35rem; font-weight: 700; letter-spacing: 0.32em; text-align: center; outline: none; }
        input:focus { border-color: var(--purple); box-shadow: 0 0 0 4px rgba(123,78,217,0.1); }
        button { width: 100%; height: 46px; margin-top: 18px; border: 0; border-radius: 12px; background: linear-gradient(135deg, #8f62eb, #7b4ed9); color: #fff; font: inherit; font-size: 0.86rem; font-weight: 800; cursor: pointer; }
        button:hover { filter: brightness(1.04); }
        .cancel-link { display: block; margin-top: 12px; color: #6e6880; font-size: 0.78rem; font-weight: 700; text-align: center; text-decoration: none; }
        .cancel-link:hover { color: #4e2ea1; }
        .modal-backdrop { position: fixed; inset: 0; z-index: 20; display: grid; place-items: center; padding: 20px; background: rgba(36, 22, 63, 0.34); opacity: 0; pointer-events: none; transition: opacity 0.2s ease; }
        .modal-backdrop.is-open { opacity: 1; pointer-events: auto; }
        .modal { width: min(360px, 100%); padding: 26px; border-radius: 20px; background: #fff; box-shadow: 0 24px 70px rgba(36, 22, 63, 0.24); transform: translateY(8px); transition: transform 0.2s ease; }
        .modal-backdrop.is-open .modal { transform: translateY(0); }
        .modal h2 { margin: 0 0 8px; font-size: 1.25rem; letter-spacing: -0.04em; }
        .modal p { margin: 0; font-size: 0.82rem; }
        .modal-actions { display: flex; gap: 10px; margin-top: 20px; }
        .modal-actions button, .modal-actions a { display: inline-flex; align-items: center; justify-content: center; width: 50%; height: 42px; margin: 0; border-radius: 10px; font: inherit; font-size: 0.78rem; font-weight: 800; text-decoration: none; }
        .modal-actions .modal-cancel { border: 1px solid var(--line); background: #fff; color: #4e2ea1; }
        .modal-actions .modal-confirm { background: #7b4ed9; color: #fff; }

        @media (max-width: 720px) {
            body { height: auto; min-height: 100vh; overflow: auto; padding: 16px; }
            .page { display: block; min-height: auto; }
            .intro { min-height: 220px; padding: 22px; }
            .intro-copy { margin-top: 26px; }
            .intro h1 { max-width: 100%; font-size: 2.55rem; }
            .mascot, .copyright { display: none; }
            .card { width: calc(100% - 24px); margin: 12px; padding: 26px 22px; }
        }
    </style>
</head>
<body>
    <main class="page">
        <section class="intro" aria-label="Queuezy verification">
            <div class="brand"><img src="assets/images/mascot.png" alt="Queuezy mascot" /> <span>QUEUEZY</span></div>
            <div class="intro-copy">
                <div class="eyebrow">Almost there</div>
                <h1>YOUR PLACE IS WAITING.</h1>
                <img class="mascot" src="assets/images/mascot.png" alt="Queuezy mascot" />
            </div>
            <div class="copyright">&copy; 2026 Queuezy Inc. All rights reserved.</div>
        </section>
        <section class="card" aria-labelledby="verify-title">
            <h2 id="verify-title">Verify your email.</h2>
            <?php if ($success): ?>
                <div class="success" role="status" style="margin: 0 0 18px; padding: 12px 14px; border: 1px solid rgba(34,197,94,0.2); border-radius: 12px; background: #edfdf5; color: #166534; font-size: 0.8rem; font-weight: 700; line-height: 1.5;"><?= htmlspecialchars($success) ?></div>
            <?php else: ?>
                <p>We sent a six-digit code to <span class="email"><?= htmlspecialchars($pending['email']) ?></span>. Enter it within 10 minutes to finish creating your account.</p>
            <?php endif; ?>
            <?php if ($error): ?><div class="error" role="alert"><?= htmlspecialchars($error) ?></div><?php endif; ?>
            <?php if (!$success): ?>
                <form method="post" action="">
                    <label for="otp">Verification code</label>
                    <input id="otp" name="otp" type="text" inputmode="numeric" autocomplete="one-time-code" maxlength="6" pattern="[0-9]{6}" required autofocus />
                    <button type="submit">Verify email</button>
                    <a class="cancel-link" href="verify_otp.php?cancel=1" data-cancel-link>Cancel and start over</a>
                </form> 
            <?php else: ?>
                <a class="cancel-link" href="login.php">Go to login</a>
            <?php endif; ?>
        </section>
    </main>
    <div class="modal-backdrop" data-cancel-modal aria-hidden="true">
        <div class="modal" role="dialog" aria-modal="true" aria-labelledby="cancel-title">
            <h2 id="cancel-title">Start over?</h2>
            <p>Your current verification code will be discarded.</p>
            <div class="modal-actions">
                <button class="modal-cancel" type="button" data-modal-close>Keep verifying</button>
                <a class="modal-confirm" href="verify_otp.php?cancel=1">Cancel registration</a>
            </div>
        </div>
    </div>
    <script>
        const otpInput = document.getElementById('otp');
        otpInput?.addEventListener('input', () => {
            otpInput.value = otpInput.value.replace(/[^0-9]/g, '').slice(0, 6);
        });

        const cancelLink = document.querySelector('[data-cancel-link]');
        const cancelModal = document.querySelector('[data-cancel-modal]');
        const closeModal = document.querySelector('[data-modal-close]');

        cancelLink?.addEventListener('click', (event) => {
            event.preventDefault();
            cancelModal.classList.add('is-open');
            cancelModal.setAttribute('aria-hidden', 'false');
        });

        closeModal?.addEventListener('click', () => {
            cancelModal.classList.remove('is-open');
            cancelModal.setAttribute('aria-hidden', 'true');
        });

        cancelModal?.addEventListener('click', (event) => {
            if (event.target === cancelModal) closeModal.click();
        });
    </script>
</body>
</html>
