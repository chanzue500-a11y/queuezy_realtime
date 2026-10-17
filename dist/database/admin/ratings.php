<?php
session_start();
if (!in_array($_SESSION['user_role'] ?? '', ['admin', 'staff'], true)) {
    header('Location: ../login.php');
    exit;
}
require_once '../database/db.php';
$conn = $conn ?? null;

$ratingRows = [];
$ratingTotal = 0;
$averageRating = 0.0;
$ratingCounts = [1 => 0, 2 => 0, 3 => 0, 4 => 0, 5 => 0];
$selectedRating = filter_var($_GET['rating'] ?? 0, FILTER_VALIDATE_INT);
if ($selectedRating === false || $selectedRating < 1 || $selectedRating > 5) {
  $selectedRating = 0;
}
$searchTerm = trim((string)($_GET['search'] ?? ''));
$searchTerm = substr($searchTerm, 0, 120);
$searchPattern = '%' . $searchTerm . '%';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_rating') {
    $ratingIdToDelete = filter_var($_POST['rating_id'] ?? 0, FILTER_VALIDATE_INT);
    if ($ratingIdToDelete && $conn) {
        $deleteRatingStmt = $conn->prepare('DELETE FROM queue_ratings WHERE id = ?');
        if ($deleteRatingStmt) {
            $deleteRatingStmt->bind_param('i', $ratingIdToDelete);
            $deleteRatingStmt->execute();
            $deleteRatingStmt->close();
        }
    }
    header('Location: ratings.php');
    exit;
}

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

    $feedbackColumn = $conn->query("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'queue_ratings' AND COLUMN_NAME = 'feedback' LIMIT 1");
    if ($feedbackColumn) {
        if ($feedbackColumn->num_rows === 0) {
            $conn->query('ALTER TABLE queue_ratings ADD COLUMN feedback VARCHAR(500) NULL AFTER rating');
        }
        $feedbackColumn->free();
    }

    $summaryResult = $conn->query("SELECT COUNT(*) AS total, COALESCE(AVG(rating), 0) AS average_rating, SUM(rating = 5) AS five_star, SUM(rating = 4) AS four_star, SUM(rating = 3) AS three_star, SUM(rating = 2) AS two_star, SUM(rating = 1) AS one_star FROM queue_ratings");
    if ($summaryResult && $summary = $summaryResult->fetch_assoc()) {
        $ratingTotal = (int)$summary['total'];
        $averageRating = (float)$summary['average_rating'];
        $ratingCounts = [
            1 => (int)$summary['one_star'],
            2 => (int)$summary['two_star'],
            3 => (int)$summary['three_star'],
            4 => (int)$summary['four_star'],
            5 => (int)$summary['five_star'],
        ];
    }

    $ratingsSql = "SELECT qr.id, qr.rating, qr.feedback, qr.created_at, q.ticket_number, s.service_name, d.dept_name, u.fullname, COALESCE(qp.amount, 0) AS amount, COALESCE(qp.status, 'pending') AS payment_status
        FROM queue_ratings qr
        LEFT JOIN queue q ON q.id = qr.queue_id
        LEFT JOIN users u ON u.id = qr.user_id
        LEFT JOIN services s ON s.id = q.service_id
        LEFT JOIN departments d ON d.id = s.dept_id
        LEFT JOIN queue_payments qp ON qp.queue_id = q.id";
    $filteredRatingsSql = $ratingsSql . " WHERE (? = 0 OR qr.rating = ?) AND (? = '' OR CONCAT_WS(' ', COALESCE(q.ticket_number, ''), COALESCE(u.fullname, ''), COALESCE(s.service_name, ''), COALESCE(d.dept_name, ''), COALESCE(qr.feedback, '')) LIKE ?) ORDER BY qr.created_at DESC LIMIT 500";
    $ratingsStmt = $conn->prepare($filteredRatingsSql);
    if ($ratingsStmt) {
      $ratingsStmt->bind_param('iiss', $selectedRating, $selectedRating, $searchTerm, $searchPattern);
      $ratingsStmt->execute();
      $ratingsResult = $ratingsStmt->get_result();
      while ($ratingRow = $ratingsResult->fetch_assoc()) {
        $ratingRows[] = $ratingRow;
        }
      $ratingsStmt->close();
    }
}

$pageTitle = 'Ratings | Queuezy';
$pageHeading = 'Ratings';
require_once '../includes/header.php';
?>

<style>
  .ratings-page { padding: 10px 0 28px; }
  .ratings-heading { display: flex; align-items: flex-end; justify-content: space-between; gap: 20px; margin-bottom: 24px; }
  .ratings-heading h1 { margin: 0; color: #f8fafc; font-size: 28px; font-weight: 900; }
  .ratings-heading p { margin: 6px 0 0; color: #94a3b8; font-size: 13px; }
  .ratings-summary { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 14px; margin-bottom: 20px; }
  .ratings-metric { min-width: 0; padding: 18px 20px; border: 1px solid #334155; border-radius: 12px; background: #1e293b; }
  .ratings-metric span { display: block; color: #94a3b8; font-size: 11px; font-weight: 800; text-transform: uppercase; }
  .ratings-metric strong { display: block; margin-top: 8px; color: #f8fafc; font-size: 26px; font-weight: 900; }
  .ratings-metric .rating-stars { color: #facc15; font-size: 18px; letter-spacing: 0; }
  .ratings-toolbar { display: flex; align-items: center; justify-content: space-between; gap: 12px; margin: 0 0 14px; }
  .ratings-filters { display: flex; flex-wrap: wrap; gap: 10px; }
  .ratings-filters input, .ratings-filters select { min-height: 40px; padding: 8px 11px; border: 1px solid #475569; border-radius: 8px; background: #1e293b; color: #e2e8f0; font: inherit; font-size: 13px; }
  .ratings-filter-button { min-height: 40px; padding: 8px 14px; border: 1px solid #475569; border-radius: 8px; background: #334155; color: #f8fafc; font: inherit; font-size: 13px; font-weight: 700; cursor: pointer; }
  .ratings-filter-button:hover { background: #475569; }
  .ratings-count { color: #94a3b8; font-size: 12px; }
  .ratings-table-wrap { overflow-x: auto; border: 1px solid #334155; border-radius: 12px; background: #1e293b; }
  .ratings-table { width: 100%; min-width: 850px; border-collapse: collapse; text-align: left; }
  .ratings-table th { padding: 12px 14px; border-bottom: 1px solid #334155; color: #94a3b8; font-size: 10px; font-weight: 800; text-transform: uppercase; }
  .ratings-table td { padding: 14px; border-bottom: 1px solid #293548; color: #e2e8f0; font-size: 12px; vertical-align: top; }
  .ratings-table tr:last-child td { border-bottom: 0; }
  .ratings-table .rating-score { color: #facc15; white-space: nowrap; font-size: 15px; }
  .ratings-table .rating-score span { color: #facc15; }
  .ratings-table .rating-comment { max-width: 320px; white-space: pre-wrap; overflow-wrap: anywhere; color: #cbd5e1; }
  .ratings-delete-button {
    min-height: 34px;
    padding: 7px 12px;
    border: 1px solid rgba(248, 113, 113, 0.55);
    border-radius: 8px;
    background: linear-gradient(135deg, #7f1d1d, #991b1b);
    color: #fee2e2;
    font: inherit;
    font-size: 12px;
    font-weight: 700;
    cursor: pointer;
    transition: transform 0.15s ease, filter 0.15s ease;
  }
  .ratings-delete-button:hover,
  .ratings-delete-button:focus-visible {
    filter: brightness(1.08);
    transform: translateY(-1px);
    outline: none;
  }
  .ratings-empty { padding: 28px; color: #94a3b8; text-align: center; font-size: 13px; }
  @media (max-width: 760px) {
    .ratings-heading { align-items: flex-start; flex-direction: column; }
    .ratings-summary { grid-template-columns: 1fr; }
    .ratings-toolbar { align-items: flex-start; flex-direction: column; }
    .ratings-filters { width: 100%; }
    .ratings-filters input { flex: 1; min-width: 0; }
  }
</style>

<div class="ratings-page">
  <header class="ratings-heading">
    <div>
      <h1>Service ratings</h1>
      <p>Customer scores and comments for paid services.</p>
    </div>
  </header>

  <section class="ratings-summary" aria-label="Rating summary">
    <article class="ratings-metric">
      <span>Total ratings</span>
      <strong><?= number_format($ratingTotal) ?></strong>
    </article>
    <article class="ratings-metric">
      <span>Average score</span>
      <strong><?= number_format($averageRating, 1) ?><span class="rating-stars" aria-hidden="true"> / 5</span></strong>
    </article>
    <article class="ratings-metric">
      <span>5-star ratings</span>
      <strong><?= number_format($ratingCounts[5]) ?></strong>
    </article>
  </section>

  <div class="ratings-toolbar">
    <form class="ratings-filters" method="get">
      <select name="rating" aria-label="Filter by star rating" onchange="this.form.submit()">
        <option value="">All ratings</option>
        <?php for ($score = 5; $score >= 1; $score--): ?>
        <option value="<?= $score ?>"<?= $selectedRating === $score ? ' selected' : '' ?>><?= $score ?> star<?= $score === 1 ? '' : 's' ?></option>
        <?php endfor; ?>
      </select>
      <input id="ratingSearch" name="search" type="search" value="<?= htmlspecialchars($searchTerm, ENT_QUOTES) ?>" placeholder="Search customer, ticket, service, comment" aria-label="Search ratings">
      <button class="ratings-filter-button" type="submit">Apply</button>
    </form>
    <span class="ratings-count"><span id="visibleRatingCount"><?= count($ratingRows) ?></span> shown</span>
  </div>

  <div class="ratings-table-wrap">
    <table class="ratings-table">
      <thead>
        <tr>
          <th>Ticket / Customer</th>
          <th>Service</th>
          <th>Rating</th>
          <th>Comment</th>
          <th>Payment</th>
          <th>Rated</th>
          <th>Action</th>
        </tr>
      </thead>
      <tbody id="ratingsTableBody">
        <?php foreach ($ratingRows as $ratingRow): ?>
        <?php $ratingScore = (int)$ratingRow['rating']; ?>
        <tr>
          <td>
            <strong><?= htmlspecialchars($ratingRow['ticket_number'] ?? 'Ticket unavailable') ?></strong>
            <div><?= htmlspecialchars($ratingRow['fullname'] ?? 'Customer unavailable') ?></div>
          </td>
          <td>
            <strong><?= htmlspecialchars($ratingRow['service_name'] ?? 'Service unavailable') ?></strong>
            <div><?= htmlspecialchars($ratingRow['dept_name'] ?? 'Department unavailable') ?></div>
          </td>
          <td class="rating-score" aria-label="<?= $ratingScore ?> out of 5 stars">
            <?php for ($star = 1; $star <= 5; $star++): ?><span aria-hidden="true"><?= $star <= $ratingScore ? '&#9733;' : '&#9734;' ?></span><?php endfor; ?>
            <strong><?= $ratingScore ?>/5</strong>
          </td>
          <td class="rating-comment"><?= trim((string)($ratingRow['feedback'] ?? '')) !== '' ? htmlspecialchars($ratingRow['feedback']) : '<span class="ratings-count">No comment</span>' ?></td>
          <td><?= ($ratingRow['payment_status'] ?? '') === 'paid' ? 'Paid · PHP ' . number_format((float)$ratingRow['amount'], 2) : 'Unpaid' ?></td>
          <td><?= htmlspecialchars($ratingRow['created_at'] ?? '') ?></td>
          <td>
            <form method="post" onsubmit="return confirm('Delete this rating?');">
              <input type="hidden" name="action" value="delete_rating">
              <input type="hidden" name="rating_id" value="<?= (int)($ratingRow['id'] ?? 0) ?>">
              <button type="submit" class="ratings-delete-button">Delete</button>
            </form>
          </td>
        </tr>
        <?php endforeach; ?>
        <?php if (!$ratingRows): ?>
        <tr><td colspan="7" class="ratings-empty">No customer ratings match this filter.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<script>
  const ratingSearch = document.getElementById('ratingSearch');
  const ratingRows = [...document.querySelectorAll('#ratingsTableBody tr')].filter((row) => row.querySelector('.rating-score'));
  const visibleRatingCount = document.getElementById('visibleRatingCount');
  ratingSearch?.addEventListener('input', () => {
    const query = ratingSearch.value.trim().toLowerCase();
    let visibleCount = 0;
    ratingRows.forEach((row) => {
      const isVisible = row.textContent.toLowerCase().includes(query);
      row.hidden = !isVisible;
      if (isVisible) visibleCount++;
    });
    if (visibleRatingCount) visibleRatingCount.textContent = String(visibleCount);
  });
</script>

<?php require_once '../includes/footer.php'; ?>