<?php
// ============================================================
// notice_board.php — School Notice Board
// Stephen Kanja School Management System
// ============================================================
include 'conn.php';

ini_set('display_errors', 1);
error_reporting(E_ALL);

// ── Auto-create notices table ─────────────────────────────────
mysqli_query($conn, "
    CREATE TABLE IF NOT EXISTS notices (
        id         INT AUTO_INCREMENT PRIMARY KEY,
        title      VARCHAR(500) NOT NULL,
        type       ENUM('info','urgent','event') NOT NULL DEFAULT 'info',
        posted_by  VARCHAR(100) DEFAULT 'Admin',
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

$message = '';

// ── POST ─────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {

    if ($_POST['action'] === 'add_notice') {
        $title     = mysqli_real_escape_string($conn, trim($_POST['title']));
        $type      = in_array($_POST['type'], ['info', 'urgent', 'event']) ? $_POST['type'] : 'info';
        $posted_by = mysqli_real_escape_string($conn, trim($_POST['posted_by'] ?? 'Admin'));

        if (!empty($title)) {
            $sql = "INSERT INTO notices (title, type, posted_by, created_at)
                    VALUES ('$title', '$type', '$posted_by', NOW())";
            if (mysqli_query($conn, $sql)) {
                $message = '<div class="nb-alert nb-success">Notice posted successfully.</div>';
            } else {
                $message = '<div class="nb-alert nb-error">Error: ' . mysqli_error($conn) . '</div>';
            }
        } else {
            $message = '<div class="nb-alert nb-error">Notice title cannot be empty.</div>';
        }
    }

    if ($_POST['action'] === 'delete_notice' && isset($_POST['id'])) {
        $id = (int)$_POST['id'];
        if (mysqli_query($conn, "DELETE FROM notices WHERE id = $id")) {
            $message = '<div class="nb-alert nb-success">Notice deleted.</div>';
        } else {
            $message = '<div class="nb-alert nb-error">Error: ' . mysqli_error($conn) . '</div>';
        }
    }
}

// ── Fetch notices ─────────────────────────────────────────────
$filter = (isset($_GET['filter']) && in_array($_GET['filter'], ['info', 'urgent', 'event']))
    ? $_GET['filter'] : '';
$where  = $filter ? "WHERE type = '" . mysqli_real_escape_string($conn, $filter) . "'" : '';
$result = mysqli_query($conn, "SELECT * FROM notices $where ORDER BY created_at DESC");
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Notice Board — Stephen Kanja School</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=Bebas+Neue&family=DM+Sans:wght@400;500;600&display=swap" rel="stylesheet">
<style>
  *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
  :root {
    --gold: #f0c040; --gold-dim: #c9a030;
    --black: #111111; --mid: #2a2a2a;
    --bg: #f4f4f2; --bg-card: #ffffff; --bg-input: #f8f8f6;
    --text-primary: #1a1a18; --text-secondary: #5f5e5a; --text-tertiary: #888780;
    --border: rgba(0,0,0,0.09); --radius-md: 8px; --radius-lg: 12px;
  }
  body { font-family: 'DM Sans', sans-serif; background: var(--bg); color: var(--text-primary); min-height: 100vh; padding: 2rem; }
  .nb-wrapper { max-width: 860px; margin: 0 auto; display: flex; flex-direction: column; gap: 1.2rem; }

  .nb-header {
    background: var(--black); border-bottom: 3px solid var(--gold);
    padding: 1rem 1.4rem; border-radius: var(--radius-lg) var(--radius-lg) 0 0;
    display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 8px;
  }
  .nb-header-title { font-family: 'Bebas Neue', sans-serif; font-size: 1.4rem; letter-spacing: 0.1em; color: #fff; }
  .nb-header-title i { color: var(--gold); margin-right: 6px; }
  .nb-header-date { font-size: 12px; color: #555; }

  .nb-card { background: var(--bg-card); border: 1px solid var(--border); border-radius: var(--radius-lg); overflow: hidden; }
  .nb-card-head {
    background: var(--black); border-bottom: 2px solid var(--gold);
    padding: 0.7rem 1.2rem; display: flex; align-items: center; gap: 8px;
  }
  .nb-card-head span { font-family: 'Bebas Neue', sans-serif; font-size: 0.95rem; letter-spacing: 0.1em; color: #fff; }
  .nb-card-head i { color: var(--gold); font-size: 12px; }
  .nb-card-body { padding: 1.2rem 1.4rem; }

  .nb-form-row { display: flex; gap: 10px; flex-wrap: wrap; }
  .nb-form-row input, .nb-form-row select {
    flex: 1; min-width: 150px; padding: 9px 12px;
    font-family: 'DM Sans', sans-serif; font-size: 13px;
    background: var(--bg-input); border: 1px solid rgba(0,0,0,0.1);
    border-radius: var(--radius-md); outline: none; color: var(--text-primary);
    transition: border-color 0.15s, box-shadow 0.15s;
  }
  .nb-form-row input:focus, .nb-form-row select:focus {
    border-color: var(--gold); box-shadow: 0 0 0 3px rgba(240,192,64,0.12);
  }
  .btn-post {
    font-family: 'DM Sans', sans-serif; font-size: 13px; font-weight: 600;
    color: var(--black); background: var(--gold); border: none;
    border-radius: var(--radius-md); padding: 9px 22px; cursor: pointer;
    display: flex; align-items: center; gap: 6px; transition: background 0.15s; white-space: nowrap;
  }
  .btn-post:hover { background: var(--gold-dim); }

  .nb-filters { display: flex; gap: 6px; flex-wrap: wrap; align-items: center; }
  .nb-filter-label { font-size: 11px; font-weight: 600; letter-spacing: 0.08em; text-transform: uppercase; color: var(--text-tertiary); }
  .nb-filter-btn {
    padding: 5px 14px; border-radius: 20px; border: 1px solid var(--border);
    background: var(--bg-input); cursor: pointer; font-size: 12px; font-weight: 500;
    text-decoration: none; color: var(--text-secondary); transition: all 0.15s;
  }
  .nb-filter-btn:hover, .nb-filter-btn.active { background: var(--black); color: var(--gold); border-color: transparent; }

  .nb-list { display: flex; flex-direction: column; gap: 10px; }
  .nb-notice {
    border-left: 4px solid var(--border); padding: 12px 14px;
    background: var(--bg-input); border-radius: 0 var(--radius-md) var(--radius-md) 0;
    position: relative; transition: background 0.12s;
  }
  .nb-notice:hover { background: #f0efe8; }
  .nb-notice.urgent { border-left-color: #dc2626; background: #fff5f5; }
  .nb-notice.info   { border-left-color: #2563eb; background: #f0f4ff; }
  .nb-notice.event  { border-left-color: #16a34a; background: #f0fff4; }

  .nb-badge {
    display: inline-block; padding: 2px 10px; border-radius: 20px;
    font-size: 10px; font-weight: 700; margin-bottom: 5px; text-transform: uppercase; letter-spacing: 0.06em;
  }
  .badge-urgent { background: #fde8e8; color: #b91c1c; }
  .badge-info   { background: #dbeafe; color: #1e40af; }
  .badge-event  { background: #dcfce7; color: #166534; }

  .nb-notice-title { font-size: 14px; font-weight: 600; margin-bottom: 3px; color: var(--text-primary); }
  .nb-notice-meta  { font-size: 11px; color: var(--text-tertiary); }

  .nb-delete {
    position: absolute; right: 10px; top: 10px;
    background: none; border: none; color: #ccc; cursor: pointer;
    font-size: 14px; transition: color 0.15s; padding: 2px 6px; border-radius: 4px;
  }
  .nb-delete:hover { color: #dc2626; background: #fde8e8; }

  .nb-empty { text-align: center; color: var(--text-tertiary); padding: 2.5rem 0; font-size: 14px; }
  .nb-empty i { font-size: 28px; color: var(--gold); display: block; margin-bottom: 8px; }

  .nb-alert { padding: 10px 14px; border-radius: var(--radius-md); font-size: 13px; }
  .nb-success { background: #dcfce7; color: #166534; border: 1px solid #16a34a; }
  .nb-error   { background: #fde8e8; color: #b91c1c; border: 1px solid #dc2626; }

  @media (max-width: 520px) {
    body { padding: 1rem; }
    .nb-form-row { flex-direction: column; }
    .btn-post { width: 100%; justify-content: center; }
  }
</style>
</head>
<body>
<div class="nb-wrapper">

  <?= $message ?>

  <div class="nb-header">
    <div class="nb-header-title"><i class="fa-solid fa-bullhorn"></i> Notice Board</div>
    <div class="nb-header-date"><?= date('l, d M Y') ?></div>
  </div>

  <div class="nb-card">
    <div class="nb-card-head">
      <i class="fa-solid fa-pen-to-square"></i>
      <span>Post a Notice</span>
    </div>
    <div class="nb-card-body">
      <form method="POST">
        <input type="hidden" name="action" value="add_notice">
        <div class="nb-form-row">
          <input type="text" name="title" placeholder="Notice title..." required>
          <select name="type">
            <option value="info">Info</option>
            <option value="urgent">Urgent</option>
            <option value="event">Event</option>
          </select>
          <input type="text" name="posted_by" placeholder="Posted by" value="Admin">
          <button type="submit" class="btn-post">
            <i class="fa-solid fa-paper-plane"></i> Post
          </button>
        </div>
      </form>
    </div>
  </div>

  <div class="nb-card">
    <div class="nb-card-head">
      <i class="fa-solid fa-filter"></i>
      <span>Filter Notices</span>
    </div>
    <div class="nb-card-body">
      <div class="nb-filters">
        <span class="nb-filter-label">Show:</span>
        <a href="notice_board.php"     class="nb-filter-btn <?= $filter === ''       ? 'active' : '' ?>">All</a>
        <a href="?filter=urgent"       class="nb-filter-btn <?= $filter === 'urgent' ? 'active' : '' ?>">
          <i class="fa-solid fa-circle-exclamation"></i> Urgent
        </a>
        <a href="?filter=info"         class="nb-filter-btn <?= $filter === 'info'   ? 'active' : '' ?>">
          <i class="fa-solid fa-circle-info"></i> Info
        </a>
        <a href="?filter=event"        class="nb-filter-btn <?= $filter === 'event'  ? 'active' : '' ?>">
          <i class="fa-solid fa-calendar-star"></i> Events
        </a>
      </div>
    </div>
  </div>

  <div class="nb-card">
    <div class="nb-card-head">
      <i class="fa-solid fa-list"></i>
      <span>Notices</span>
    </div>
    <div class="nb-card-body">
      <div class="nb-list">
        <?php if ($result && mysqli_num_rows($result) > 0): ?>
          <?php while ($row = mysqli_fetch_assoc($result)): ?>
            <div class="nb-notice <?= htmlspecialchars($row['type']) ?>">
              <span class="nb-badge badge-<?= htmlspecialchars($row['type']) ?>">
                <?= ucfirst(htmlspecialchars($row['type'])) ?>
              </span>
              <div class="nb-notice-title"><?= htmlspecialchars($row['title']) ?></div>
              <div class="nb-notice-meta">
                <i class="fa-solid fa-user" style="font-size:10px;"></i>
                <?= htmlspecialchars($row['posted_by']) ?>
                &middot;
                <i class="fa-regular fa-clock" style="font-size:10px;"></i>
                <?= date('d M Y, g:i A', strtotime($row['created_at'])) ?>
              </div>
              <form method="POST" style="display:inline" onsubmit="return confirm('Delete this notice?')">
                <input type="hidden" name="action" value="delete_notice">
                <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
                <button type="submit" class="nb-delete" title="Delete">
                  <i class="fa-solid fa-xmark"></i>
                </button>
              </form>
            </div>
          <?php endwhile; ?>
        <?php else: ?>
          <div class="nb-empty">
            <i class="fa-solid fa-inbox"></i>
            No notices found.
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>

</div>
</body>
</html>
<?php mysqli_close($conn); ?>
