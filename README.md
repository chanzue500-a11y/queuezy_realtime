# Queuezy local setup

Queuezy uses PHP, MySQL/MariaDB, vanilla JavaScript, and Tailwind CSS 3. The PHP source lives in `dist/`; root PHP files are entry points. `dist/` contains application source, so do not delete it as a generated build folder.

## Run with XAMPP

1. Start Apache and MySQL from the XAMPP Control Panel.
2. Configure the database using the environment variables documented in `dist/database/db.php`, and import `dist/database/schema.sql` into the configured database.
3. Open `http://localhost/PWA/` when this repository is under `C:\xampp\htdocs\PWA`.

Alternatively, start MySQL in XAMPP and run `npm start` from the repository root. It serves PHP at `http://localhost:8000/`. The script uses `C:/xampp/php/php.exe` on Windows when available, otherwise PHP on PATH. Set `QUEUEZY_PHP` or `QUEUEZY_PORT` to override the PHP executable or port. PHP's built-in server is for local development.

## CSS and formatting

```sh
npm run build
npm run dev:css
npm run format
```

`npm run build` uses the installed Tailwind 3 CLI to generate the minified `dist/assets/css/tailwind.css`. The generated CSS is included in the repository so pages do not need a Tailwind CDN or a Node process to render. `npm run dev:css` watches the PHP and JavaScript source during development; run a fresh build after changing utility classes. Keep complete utility names as literal strings rather than assembling class names from fragments. The formatter handles the first-party JavaScript/CSS and build configuration; PHP formatting is handled by your IDE. If installing on a fresh checkout, run `npm ci` first.

## PWA and offline behavior

The manifest is `dist/manifest.webmanifest`, and the service worker's scope is `dist/`. Open a page under this directory to register it, then use the browser's install action where supported. Registration requires HTTPS or localhost. The app starts at the sign-in page and uses a public offline page when a navigation cannot reach the server.

Only an explicit list of public CSS, JavaScript, icons, and the offline page can enter the service worker cache. Authenticated PHP pages, API responses, uploads, and POST requests are never cached or queued for replay. Ticket and payment updates require a live server connection. The on-screen status shows when polling fails or goes offline; updates retry with backoff and resume when connectivity returns. External Google Fonts and Font Awesome remain optional online assets; offline messaging uses system fonts and local artwork.

When changing the cached asset list or offline page, increment the `queuezy-public-v1` version in `dist/service-worker.js`. Public static assets use a network-first strategy so a CSS rebuild is available immediately online.

## Configuration

Keep SMTP passwords and database credentials outside source control. See the environment configuration comments in `dist/database/db.php` and `dist/includes/mail_config.php`. Production deployment needs a PHP web server and the configured database; do not use PHP's development server for public hosting.

Implementation references: [Tailwind 3 CLI](https://v3.tailwindcss.com/docs/installation), [web app manifest icons](https://developer.mozilla.org/en-US/docs/Web/Progressive_web_apps/Manifest/Reference/icons), and [accessible modal dialog keyboard behavior](https://www.w3.org/WAI/ARIA/apg/patterns/dialog-modal/).
