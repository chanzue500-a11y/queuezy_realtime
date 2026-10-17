<?php
session_start();
if (isset($_SESSION['user_id'])) {
    if (($_SESSION['user_role'] ?? '') === 'admin') {
        header("Location: dist/admin/departments.php");
        exit();
    } elseif (($_SESSION['user_role'] ?? '') === 'staff') {
        header("Location: dist/staff/index.php");
        exit();
    } else {
        header("Location: dist/customer/index.php");
        exit();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Queuezy | Smart Queue Management</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700;800&family=Space+Grotesk:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg-soft: #e9d9ff;
            --purple-1: #8b63e8;
            --purple-2: #8b63e8;
            --purple-3: #8b63e8;
            --text: #1c1833;
            --muted: #677085;
        }

        * { box-sizing: border-box; }

        html, body {
            margin: 0;
            min-height: 100%;
            height: 100%;
            font-family: 'Inter', sans-serif;
            background: var(--bg-soft);
            color: var(--text);
        }

        body {
            min-height: 100vh;
            overflow-x: hidden;
            overflow-y: auto;
            background: var(--bg-soft);
        }

        .splash-screen {
            position: fixed;
            inset: 0;
            z-index: 10;
            display: flex;
            align-items: center;
            justify-content: center;
            background: var(--bg-soft);
            opacity: 1;
            transition: opacity 0.8s ease, transform 0.8s ease;
        }

        .splash-screen.fade-out {
            opacity: 0;
            transform: scale(0.98);
        }

        .splash-box {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 12px;
            transform: translateY(2px) scale(1.18);
            transform-origin: center;
        }

        .splash-icon {
            position: relative;
            width: 180px;
            height: 180px;
            border-radius: 34px;
            overflow: hidden;
            box-shadow:
                0 0 0 1px rgba(255,255,255,0.3),
                0 0 28px rgba(176, 130, 255, 0.35),
                0 22px 32px rgba(125, 79, 226, 0.18);
            background: linear-gradient(135deg, #b89aff 0%, #8b63e8 100%);
        }

        .splash-icon::before {
            content: "";
            position: absolute;
            inset: -10%;
            border-radius: inherit;
            background: linear-gradient(
                125deg,
                rgba(255,255,255,0) 0%,
                rgba(255,255,255,0.8) 18%,
                rgba(255,255,255,0.25) 28%,
                rgba(255,255,255,0.08) 38%,
                rgba(255,255,255,0.02) 50%,
                rgba(255,255,255,0.2) 62%,
                rgba(255,255,255,0.8) 78%,
                rgba(255,255,255,0) 100%
            );
            background-size: 220% 220%;
            background-position: 0% 50%;
            animation: edgeLightSweep 4s ease-in-out infinite alternate;
            pointer-events: none;
            filter: blur(10px);
            opacity: 0.95;
            z-index: 1;
        }

        .splash-icon::after {
            content: "";
            position: absolute;
            inset: 1px;
            border-radius: 30px;
            border: 1px solid rgba(255,255,255,0.4);
            box-shadow: inset 0 0 18px rgba(255,255,255,0.22);
            pointer-events: none;
            z-index: 2;
        }

        .splash-logo {
            position: relative;
            display: block;
            width: 100%;
            height: 100%;
            object-fit: cover;
            border-radius: inherit;
            padding: 8px;
            box-sizing: border-box;
            background: transparent;
            z-index: 3;
        }

        @keyframes edgeLightSweep {
            0% {
                background-position: 0% 50%;
                opacity: 0.5;
            }
            50% {
                background-position: 100% 50%;
                opacity: 1;
            }
            100% {
                background-position: 180% 50%;
                opacity: 0.5;
            }
        }

        .splash-wordmark {
            font-size: 1.2rem;
            font-weight: 900;
            letter-spacing: 0.14em;
            text-transform: uppercase;
            color: var(--text);
            text-align: center;
            line-height: 1;
        }

        .splash-tagline {
            font-size: 0.72rem;
            font-weight: 700;
            letter-spacing: 0.12em;
            text-transform: uppercase;
            color: rgba(28, 24, 51, 0.72);
            text-align: center;
        }

        @keyframes spin {
            to { transform: rotate(360deg); }
        }

        .landing-shell {
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            color: var(--text);
            background: linear-gradient(135deg, #f3ebff 0%, #e9d9ff 100%);
            opacity: 0;
            visibility: hidden;
            transition: opacity 0.45s ease, visibility 0.45s ease;
        }

        .landing-shell.is-visible {
            opacity: 1;
            visibility: visible;
        }

        .landing-nav {
            width: min(1120px, calc(100% - 48px));
            margin: 0 auto;
            padding: 22px 0;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 20px;
        }

        .landing-brand {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            color: var(--text);
            font-size: 20px;
            font-weight: 900;
            text-decoration: none;
        }

        .landing-brand img {
            width: 48px;
            height: 48px;
            object-fit: contain;
        }

        .landing-nav-actions,
        .landing-cta {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 12px;
        }

        .landing-link {
            min-height: 44px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 0 18px;
            border: 1px solid rgba(139, 99, 232, 0.24);
            border-radius: 10px;
            color: var(--text);
            font-size: 14px;
            font-weight: 800;
            text-decoration: none;
            transition: transform 160ms ease, background-color 160ms ease;
        }

        .landing-link:hover { transform: translateY(-1px); }
        .landing-link--primary {
            border-color: var(--purple-3);
            background: var(--purple-3);
            color: #ffffff;
        }
        .landing-link--primary:hover { background: var(--purple-2); }

        .landing-hero {
            width: min(1120px, calc(100% - 48px));
            flex: 1;
            margin: 0 auto;
            padding: 30px 0 56px;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            text-align: center;
        }

        .landing-hero img {
            width: 168px;
            height: 168px;
            object-fit: contain;
            margin-bottom: 14px;
            filter: drop-shadow(0 18px 22px rgba(91, 62, 208, 0.16));
        }

        .landing-eyebrow {
            margin: 0 0 10px;
            color: var(--purple-3);
            font-size: 12px;
            font-weight: 900;
            text-transform: uppercase;
        }

        .landing-hero h1 {
            margin: 0;
            font-family: Georgia, 'Times New Roman', serif;
            font-size: 64px;
            font-weight: 800;
            line-height: 1.05;
        }

        .landing-copy {
            max-width: 640px;
            margin: 18px 0 26px;
            color: #677085;
            font-size: 18px;
            line-height: 1.6;
        }

        .landing-highlights {
            width: 100%;
            border-top: 1px solid rgba(139, 99, 232, 0.18);
            background: #e9d9ff;
        }

        .landing-highlights-inner {
            width: min(1120px, calc(100% - 48px));
            margin: 0 auto;
            padding: 18px 0;
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 16px;
            color: #677085;
            font-size: 13px;
            font-weight: 700;
            text-align: center;
        }

        .landing-highlights-inner span + span { border-left: 1px solid rgba(139, 99, 232, 0.18); }

        @media (max-width: 600px) {
            .landing-nav,
            .landing-hero,
            .landing-highlights-inner { width: calc(100% - 32px); }
            .landing-nav { padding: 16px 0; }
            .landing-brand { font-size: 17px; }
            .landing-brand img { width: 40px; height: 40px; }
            .landing-nav-actions { gap: 6px; }
            .landing-link { min-height: 40px; padding: 0 11px; font-size: 12px; }
            .landing-hero { padding: 20px 0 36px; }
            .landing-hero img { width: 132px; height: 132px; }
            .landing-hero h1 { font-size: 46px; }
            .landing-copy { font-size: 16px; }
            .landing-highlights-inner { grid-template-columns: 1fr; gap: 10px; }
            .landing-highlights-inner span + span { border-left: 0; }
        }

        @media (prefers-reduced-motion: reduce) {
            .landing-shell,
            .landing-link { transition: none; }
        }

        :root {
            --ink: #1c1833;
            --muted-ink: #677085;
            --mint: #f3ebff;
            --paper: #ffffff;
            --coral: #8b63e8;
            --blue: #8b63e8;
            --sun: #d5c0ff;
            --purple-deep: #6848bf;
        }

        html, body {
            min-height: 100%;
            height: auto;
            font-family: 'DM Sans', sans-serif;
            color: var(--ink);
            background: var(--mint);
        }

        body { overflow-x: hidden; overflow-y: auto; }

        .landing-shell {
            display: block;
            min-height: 100vh;
            color: var(--ink);
            background: var(--mint);
        }

        .landing-nav {
            width: min(1200px, calc(100% - 64px));
            min-height: 88px;
            padding: 18px 0;
        }

        .landing-brand {
            gap: 8px;
            font-family: 'Space Grotesk', sans-serif;
            font-size: 19px;
            font-weight: 700;
        }

        .landing-brand img { width: 46px; height: 46px; }
        .landing-nav-actions { gap: 10px; }

        .landing-link {
            min-height: 46px;
            padding: 0 19px;
            border: 1px solid rgba(139, 99, 232, 0.24);
            border-radius: 5px;
            color: var(--ink);
            font-family: 'DM Sans', sans-serif;
            font-size: 14px;
            font-weight: 700;
        }

        .landing-link:hover { transform: translateY(-2px); background: rgba(255, 255, 255, 0.65); }
        .landing-link--primary { border-color: var(--coral); background: var(--coral); color: #ffffff; }
        .landing-link--primary:hover { border-color: #7550cb; background: #7550cb; }

        .landing-hero {
            width: min(1200px, calc(100% - 64px));
            min-height: min(68svh, 690px);
            padding: 26px 0 48px;
            display: grid;
            grid-template-columns: minmax(0, 1fr) minmax(390px, 0.9fr);
            gap: clamp(32px, 6vw, 82px);
            align-items: center;
            text-align: left;
        }

        .hero-copy { max-width: 560px; animation: heroEnter 650ms ease both; }

        .landing-eyebrow {
            display: flex;
            align-items: center;
            gap: 10px;
            margin: 0 0 18px;
            color: var(--coral);
            font-size: 12px;
            font-weight: 800;
        }

        .eyebrow-mark { width: 28px; height: 3px; background: var(--coral); }

        .landing-hero h1 {
            margin: 0;
            font-family: 'Space Grotesk', sans-serif;
            font-size: 78px;
            font-weight: 700;
            line-height: 0.98;
        }

        .hero-period { color: var(--coral); }

        .landing-copy {
            max-width: 520px;
            margin: 22px 0 28px;
            color: var(--muted-ink);
            font-size: 19px;
            line-height: 1.55;
        }

        .landing-cta { justify-content: flex-start; gap: 12px; }
        .landing-cta .landing-link { min-height: 52px; padding: 0 22px; }

        .hero-footnote {
            margin: 24px 0 0;
            color: var(--muted-ink);
            font-size: 12px;
            font-weight: 600;
        }

        .hero-visual {
            position: relative;
            min-height: 440px;
            overflow: hidden;
            isolation: isolate;
            display: flex;
            align-items: flex-start;
            justify-content: center;
            background-color: var(--blue);
            background-image: linear-gradient(rgba(255,255,255,0.1) 1px, transparent 1px), linear-gradient(90deg, rgba(255,255,255,0.1) 1px, transparent 1px);
            background-size: 34px 34px;
            animation: visualEnter 700ms 100ms ease both;
        }

        .hero-visual::before {
            content: "";
            position: absolute;
            z-index: -1;
            top: -54px;
            right: -32px;
            width: 230px;
            height: 210px;
            background: var(--sun);
            clip-path: polygon(19% 0, 100% 0, 100% 82%, 68% 100%, 0 45%);
        }

        .hero-visual::after {
            content: "";
            position: absolute;
            z-index: -1;
            bottom: -86px;
            left: -48px;
            width: 250px;
            height: 220px;
            background: var(--coral);
            transform: rotate(-18deg);
        }

        .hero-art-label {
            position: absolute;
            top: 22px;
            left: 22px;
            color: #ffffff;
            font-family: 'Space Grotesk', sans-serif;
            font-size: 11px;
            font-weight: 700;
        }

        .hero-mascot {
            position: absolute;
            top: 42px;
            left: 50%;
            width: 220px;
            height: 220px;
            margin: 0;
            object-fit: contain;
            transform: translateX(-50%);
            filter: drop-shadow(0 16px 14px rgba(53, 34, 105, 0.22));
        }

        .hero-ticket {
            position: absolute;
            right: 32px;
            bottom: 30px;
            width: min(300px, calc(100% - 54px));
            padding: 18px 22px 20px;
            background: var(--paper);
            box-shadow: 10px 12px 0 rgba(53, 34, 105, 0.18);
            transform: rotate(-3deg);
        }

        .ticket-top,
        .ticket-bottom { display: flex; justify-content: space-between; gap: 12px; }
        .ticket-top { color: var(--muted-ink); font-size: 10px; font-weight: 800; }
        .ticket-sample { color: var(--coral); }

        .ticket-number {
            margin: 3px 0 8px;
            color: var(--ink);
            font-family: 'Space Grotesk', sans-serif;
            font-size: 66px;
            font-weight: 700;
            line-height: 1;
        }

        .ticket-bottom {
            padding-top: 10px;
            border-top: 1px dashed rgba(139, 99, 232, 0.35);
            color: var(--muted-ink);
            font-size: 11px;
            font-weight: 700;
        }

        .landing-highlights {
            border-top: 1px solid rgba(139, 99, 232, 0.18);
            background: #e9d9ff;
        }

        .landing-highlights-inner {
            width: min(1200px, calc(100% - 64px));
            min-height: 138px;
            padding: 22px 0;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            align-items: center;
            gap: 0;
            color: var(--ink);
            font-size: 14px;
            text-align: left;
        }

        .highlight-item { min-height: 66px; padding: 0 26px; display: flex; flex-direction: column; justify-content: center; gap: 6px; }
        .highlight-item:first-child { padding-left: 0; }
        .highlight-item + .highlight-item { border-left: 1px solid rgba(139, 99, 232, 0.2); }
        .highlight-number { color: var(--coral); font-family: 'Space Grotesk', sans-serif; font-size: 11px; font-weight: 700; }
        .highlight-title { font-size: 15px; font-weight: 800; }
        .highlight-copy { color: var(--muted-ink); font-size: 12px; font-weight: 500; }

        @keyframes heroEnter {
            from { opacity: 0; transform: translateY(14px); }
            to { opacity: 1; transform: translateY(0); }
        }

        @keyframes visualEnter {
            from { opacity: 0; transform: translateY(14px); }
            to { opacity: 1; transform: translateY(0); }
        }

        @media (max-width: 760px) {
            .landing-nav,
            .landing-hero,
            .landing-highlights-inner { width: calc(100% - 36px); }
            .landing-nav { min-height: 72px; padding: 12px 0; }
            .landing-brand { gap: 5px; font-size: 16px; }
            .landing-brand img { width: 38px; height: 38px; }
            .landing-nav-actions { gap: 6px; }
            .landing-nav-actions .landing-link { min-height: 40px; padding: 0 11px; font-size: 12px; }
            .landing-hero { min-height: 0; padding: 24px 0 34px; grid-template-columns: 1fr; gap: 26px; }
            .landing-eyebrow { margin-bottom: 12px; }
            .landing-hero h1 { font-size: 56px; }
            .landing-copy { margin: 14px 0 20px; font-size: 17px; }
            .landing-cta { flex-wrap: wrap; }
            .landing-cta .landing-link { min-height: 46px; padding: 0 16px; }
            .hero-footnote { margin-top: 15px; }
            .hero-visual { min-height: 310px; }
            .hero-mascot { top: 20px; width: 160px; height: 160px; }
            .hero-ticket { right: 20px; bottom: 18px; width: min(260px, calc(100% - 44px)); padding: 14px 17px 16px; }
            .ticket-number { font-size: 54px; }
            .landing-highlights-inner { min-height: 0; padding: 12px 0; grid-template-columns: 1fr; }
            .highlight-item,
            .highlight-item:first-child { min-height: 62px; padding: 10px 0; }
            .highlight-item + .highlight-item { border-top: 1px solid rgba(139, 99, 232, 0.2); border-left: 0; }
        }

        @media (max-width: 380px) {
            .landing-nav,
            .landing-hero,
            .landing-highlights-inner { width: calc(100% - 28px); }
            .landing-brand span { display: none; }
            .landing-nav-actions .landing-link { padding: 0 9px; }
            .landing-hero h1 { font-size: 50px; }
        }

        @media (prefers-reduced-motion: reduce) {
            .hero-copy,
            .hero-visual { animation: none; }
        }
    </style>
</head>
<body>
    <main class="landing-shell" id="landingPage" aria-hidden="true" inert>
        <header class="landing-nav">
            <a class="landing-brand" href="index.php" aria-label="Queuezy home">
                <img src="dist/assets/images/mascot.png" alt="" />
                <span>QUEUEZY</span>
            </a>
            <nav class="landing-nav-actions" aria-label="Account navigation">
                <a class="landing-link" href="login.php">Sign in</a>
                <a class="landing-link landing-link--primary" href="register.php">Create account</a>
            </nav>
        </header>
        <section class="landing-hero">
            <div class="hero-copy">
                <p class="landing-eyebrow"><span class="eyebrow-mark" aria-hidden="true"></span> A BETTER WAY TO WAIT</p>
                <h1>Queuezy<span class="hero-period">.</span></h1>
                <p class="landing-copy">Take your place in line online. Follow your ticket as the queue moves, then arrive when it is your turn.</p>
                <div class="landing-cta">
                    <a class="landing-link landing-link--primary" href="register.php">Join a queue <span aria-hidden="true">&rarr;</span></a>
                </div>
                <p class="hero-footnote">Service queues, ticket updates, and billing in one place.</p>
            </div>
            <div class="hero-visual" aria-label="Sample Queuezy ticket">
                <span class="hero-art-label">QUEUEZY / YOUR PLACE IN LINE</span>
                <img class="hero-mascot" src="dist/assets/images/mascot.png" alt="Queuezy mascot" />
                <div class="hero-ticket">
                    <div class="ticket-top"><span>QUEUE TICKET</span><span class="ticket-sample">SAMPLE</span></div>
                    <div class="ticket-number">Q-018</div>
                    <div class="ticket-bottom"><span>YOUR PLACE IS SAVED</span><span>01 / 03</span></div>
                </div>
            </div>
        </section>
        <section class="landing-highlights" aria-label="Queuezy benefits">
            <div class="landing-highlights-inner">
                <div class="highlight-item"><span class="highlight-number">01</span><span class="highlight-title">Join remotely</span><span class="highlight-copy">Get your ticket before you arrive.</span></div>
                <div class="highlight-item"><span class="highlight-number">02</span><span class="highlight-title">Track your place</span><span class="highlight-copy">Check your ticket status and queue position.</span></div>
                <div class="highlight-item"><span class="highlight-number">03</span><span class="highlight-title">Stay up to date</span><span class="highlight-copy">See ticket and payment status in your account.</span></div>
            </div>
        </section>
    </main>

    <div class="splash-screen" aria-label="Queuezy splash screen">
        <div class="splash-box">
            <div class="splash-icon">
                <img class="splash-logo" src="dist/assets/images/mascot.png" alt="Queuezy mascot" />
            </div>
            <div class="splash-wordmark">Queuezy</div>
            <div class="splash-tagline">Smart queue management</div>
        </div>
    </div>

    <script>
        const splash = document.querySelector('.splash-screen');
        const landingPage = document.getElementById('landingPage');

        function playSplashTone() {
            const AudioCtx = window.AudioContext || window.webkitAudioContext;
            if (!AudioCtx) return;

            const audioCtx = new AudioCtx();
            const now = audioCtx.currentTime;

            const createTone = (frequency, start, duration, gainValue, wave) => {
                const osc = audioCtx.createOscillator();
                const gain = audioCtx.createGain();
                const filter = audioCtx.createBiquadFilter();

                osc.type = wave;
                osc.frequency.setValueAtTime(frequency, now + start);

                filter.type = 'lowpass';
                filter.frequency.setValueAtTime(2600, now + start);

                gain.gain.setValueAtTime(0.0001, now + start);
                gain.gain.exponentialRampToValueAtTime(gainValue, now + start + 0.03);
                gain.gain.exponentialRampToValueAtTime(0.0001, now + start + duration);

                osc.connect(filter);
                filter.connect(gain);
                gain.connect(audioCtx.destination);

                osc.start(now + start);
                osc.stop(now + start + duration);
            };

            createTone(740, 0.00, 0.55, 0.14, 'triangle');
            createTone(980, 0.08, 0.60, 0.10, 'sine');
        }

        playSplashTone();

        setTimeout(() => {
            if (splash) {
                splash.classList.add('fade-out');
            }

            setTimeout(() => {
                splash?.remove();
                landingPage?.removeAttribute('aria-hidden');
                landingPage?.removeAttribute('inert');
                landingPage?.classList.add('is-visible');
            }, 800);
        }, 7000);
    </script>
</body>
</html>

