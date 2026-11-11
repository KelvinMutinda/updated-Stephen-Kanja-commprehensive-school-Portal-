<?php
/* ════════════════════════════════════════════════════════════════════
   exam_release_ui.php — release gate for ViewResults.php.
   Sits on top of exam_release.php (examReleaseStatus, examEntryProgress,
   setExamRelease, examSubjectTeachers). Upload BOTH files.

   • Entry progress (per grade and per subject) is visible to everyone.
   • Full results are visible to everyone ONLY after an admin releases them.
   • Release is blocked on the server until every subject has marks for
     every learner in that grade.
   ════════════════════════════════════════════════════════════════════ */
require_once __DIR__ . '/exam_release.php';

function erh($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

/* Who is an admin? Uses isAdmin() from auth_helpers.php when available,
   otherwise the session role. ADJUST the session keys / role names if needed. */
/* Subjects-based completeness: every subject of the grade has marks entered
   (at least one learner). The number of learners entered no longer matters. */
function erSubjectsComplete(array $prog): bool {
    if (!$prog['by_subject']) return false;
    foreach ($prog['by_subject'] as $cnt) if ((int)$cnt <= 0) return false;
    return true;
}

function erIsAdmin(): bool {
    if (session_status() === PHP_SESSION_NONE) { @session_start(); }
    if (!function_exists('isAdmin') && is_file(__DIR__ . '/auth_helpers.php')) { @include_once __DIR__ . '/auth_helpers.php'; }
    if (function_exists('isAdmin')) { return (bool)isAdmin(); }
    $r = strtolower((string)($_SESSION['role'] ?? $_SESSION['user_role'] ?? $_SESSION['usertype'] ?? ''));
    return in_array($r, ['admin', 'administrator'], true);
}

/* ── Release / withdraw (admin only). Runs before any HTML is sent. ── */
function erHandlePost($conn, array $EXAM_TYPES, string $csrf): void {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') return;
    $act = (string)($_POST['action'] ?? '');
    if ($act !== 'release_results' && $act !== 'unrelease_results') return;

    include_once 'subjects_config.php';   // getSubjectsForGrade()

    $grade = (string)($_POST['grade'] ?? '');
    $term  = (string)($_POST['term'] ?? '');
    $year  = (string)($_POST['year'] ?? '');
    $exam  = function_exists('resolveExamCode') ? resolveExamCode((string)($_POST['exam_type'] ?? ''), $EXAM_TYPES) : null;

    $status = 'error';
    $valid = erIsAdmin()
        && hash_equals($csrf, (string)($_POST['csrf'] ?? ''))
        && ctype_digit($grade) && ctype_digit($year)
        && in_array($term, ['Term 1', 'Term 2', 'Term 3'], true)
        && $exam !== null && $exam !== '';

    if ($valid) {
        $by = (string)($_SESSION['name'] ?? $_SESSION['username'] ?? $_SESSION['email'] ?? 'admin');
        if ($act === 'release_results') {
            $cols = array_keys(getSubjectsForGrade((int)$grade));
            $prog = examEntryProgress($conn, $grade, $term, $exam, $year, $cols);
            if (!erSubjectsComplete($prog))                                 $status = 'incomplete';
            elseif (setExamRelease($conn, $grade, $term, $exam, $year, true, $by))  $status = 'released';
        } else {
            if (setExamRelease($conn, $grade, $term, $exam, $year, false, $by))     $status = 'unreleased';
        }
    }

    $back = strtok((string)($_SERVER['REQUEST_URI'] ?? 'ViewResults.php'), '?') . '?' . http_build_query([
        'grade' => $grade, 'term' => $term, 'exam_type' => (string)$exam, 'year' => $year, 'rel' => $status,
    ]);
    if (!headers_sent()) { header('Location: ' . $back); }
    else { echo "<script>window.location.href=" . json_encode($back) . ";</script>"; }
    exit;
}

/* ── Renders the progress panel + all-grades overview.
      Returns TRUE when the full results must stay hidden from this viewer. ── */
function erGate($conn, $grade, $termLabel, $exam, $examLabel, $year, array $subjectMap, string $csrf): bool {
    if (!($grade && $termLabel && $exam && $year && $subjectMap)) return false;

    $isAdmin  = erIsAdmin();
    $cols     = array_keys($subjectMap);
    $prog     = examEntryProgress($conn, $grade, $termLabel, $exam, $year, $cols);
    $status   = examReleaseStatus($conn, $grade, $termLabel, $exam, $year);
    $released = $status['released'];
    $subsDone = erSubjectsComplete($prog);

    $flash = (string)($_GET['rel'] ?? '');
    $flashHtml = '';
    if ($flash === 'released')        $flashHtml = "<div class='flash flash-ok'><i class='fa-solid fa-circle-check'></i><div>Results released. Everyone can now view them.</div></div>";
    elseif ($flash === 'unreleased')  $flashHtml = "<div class='flash flash-ok'><i class='fa-solid fa-circle-check'></i><div>Results withdrawn. They are hidden again until you release them.</div></div>";
    elseif ($flash === 'incomplete')  $flashHtml = "<div class='flash flash-err'><i class='fa-solid fa-triangle-exclamation'></i><div>Cannot release yet.<small>Some subjects still have missing marks.</small></div></div>";
    elseif ($flash === 'error')       $flashHtml = "<div class='flash flash-err'><i class='fa-solid fa-triangle-exclamation'></i><div>The action failed.<small>Only an admin can release results, and your session may have expired.</small></div></div>";

    $badge = $released
        ? "<span class='er-badge er-ok'><i class='fa-solid fa-lock-open'></i> Released</span>"
        : "<span class='er-badge er-hold'><i class='fa-solid fa-lock'></i> Not released</span>";

    echo "<style>
        .er-card{background:#fff;border:1px solid var(--border);border-radius:var(--radius);box-shadow:var(--shadow-sm);margin-bottom:26px;overflow:hidden}
        .er-head{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;padding:18px 22px;border-bottom:1px solid var(--border-2);background:var(--cream)}
        .er-head h3{font-family:'Playfair Display',serif;font-size:1.05rem;color:var(--ink)}
        .er-head small{display:block;font-family:'Plus Jakarta Sans',sans-serif;font-size:.74rem;color:var(--ink-mute);margin-top:2px}
        .er-badge{display:inline-flex;align-items:center;gap:7px;font-size:.72rem;font-weight:800;letter-spacing:.4px;padding:6px 12px;border-radius:20px}
        .er-ok{background:var(--ee-bg);color:#14532d}.er-hold{background:var(--ae-bg);color:var(--ae)}
        .er-body{padding:18px 22px}
        .er-row{display:flex;align-items:center;gap:12px;margin-bottom:11px;flex-wrap:wrap}
        .er-lbl{width:190px;flex-shrink:0;font-size:.8rem;font-weight:600;color:var(--ink-soft)}
        .er-bar{flex:1;min-width:120px;background:var(--paper);border-radius:99px;height:10px;overflow:hidden}
        .er-fill{height:100%;border-radius:99px;background:var(--accent-strong)}
        .er-fill.done{background:var(--ee)}.er-fill.zero{background:var(--be)}
        .er-cnt{width:92px;text-align:right;font-size:.76rem;font-weight:700;color:var(--ink-soft);flex-shrink:0}
        .er-who{flex-basis:100%;margin:-4px 0 4px 202px;font-size:.72rem;color:var(--ink-mute)}
        .er-actions{display:flex;align-items:center;gap:12px;flex-wrap:wrap;padding:14px 22px;border-top:1px solid var(--border-2);background:var(--cream);font-size:.8rem;color:var(--ink-soft)}
        .er-btn{display:inline-flex;align-items:center;gap:8px;border:none;border-radius:9px;padding:10px 18px;font-family:inherit;font-size:.8rem;font-weight:700;cursor:pointer;color:#fff;background:var(--accent-strong)}
        .er-btn:disabled{opacity:.45;cursor:not-allowed}.er-btn.warn{background:var(--be)}
        .er-note{margin-right:auto}
        .er-table{width:100%;min-width:620px;border-collapse:collapse;font-size:.84rem}
        .er-table th{background:var(--th-bg);color:#fff;padding:10px 12px;font-size:.64rem;letter-spacing:1px;text-transform:uppercase;border:1px solid var(--th-border);text-align:center}
        .er-table td{padding:9px 12px;border:1px solid var(--grid);text-align:center;color:var(--ink-soft)}
        .er-table td.l,.er-table th.l{text-align:left}
        .er-table tr.cur td{background:var(--row-hover);font-weight:700}
        .er-table a{color:var(--accent-strong);font-weight:700;text-decoration:none}
        .er-scroll{overflow-x:auto}
        @media(max-width:700px){.er-lbl{width:120px}.er-who{margin-left:0}}
    </style>";

    echo $flashHtml;

    /* ── Progress for the selected grade ── */
    $relNote = ($released && $status['by'] !== '')
        ? " &middot; released by " . erh($status['by']) . ($status['at'] !== '' ? " on " . erh(date('j M Y, g:i A', strtotime($status['at']))) : '')
        : '';
    echo "<div class='section-divider'><h2>Entry Progress &mdash; Grade " . erh($grade) . "</h2>{$badge}</div>";
    echo "<div class='er-card'><div class='er-head'><div><h3>" . erh($examLabel) . " &middot; " . erh($termLabel) . " &middot; " . erh($year) . "<small>"
       . (int)$prog['students'] . " learner(s) in the grade &middot; " . (int)$prog['pct'] . "% of all marks entered{$relNote}</small></div></div><div class='er-body'>";
    foreach ($subjectMap as $col => $label) {
        $n   = (int)($prog['by_subject'][$col] ?? 0);
        $tot = (int)$prog['students'];
        $pct = $n > 0 ? max(1, $tot ? (int)floor($n / $tot * 100) : 0) : 0;
        $cls = ($n > 0) ? 'done' : 'zero';
        $ico = ($n > 0) ? "<i class='fa-solid fa-circle-check' style='color:var(--ee)'></i> " : '';
        echo "<div class='er-row'><div class='er-lbl'>" . erh($label) . "</div>
              <div class='er-bar'><div class='er-fill $cls' style='width:{$pct}%'></div></div>
              <div class='er-cnt'>{$ico}{$n} / {$tot}</div>";
        if ($tot && $n < $tot) {                       // who still owes marks
            $who = examSubjectTeachers($conn, $grade, $col);
            if ($who) echo "<div class='er-who'><i class='fa-solid fa-user-clock'></i> Pending: " . erh(implode(', ', $who)) . "</div>";
        }
        echo "</div>";
    }
    echo "</div>";

    if ($isAdmin) {
        $hid = "<input type='hidden' name='csrf' value='" . erh($csrf) . "'>"
             . "<input type='hidden' name='grade' value='" . erh($grade) . "'>"
             . "<input type='hidden' name='term' value='" . erh($termLabel) . "'>"
             . "<input type='hidden' name='exam_type' value='" . erh($exam) . "'>"
             . "<input type='hidden' name='year' value='" . erh($year) . "'>";
        echo "<div class='er-actions'>";
        if ($released) {
            echo "<span class='er-note'>These results are visible to everyone.</span>
                  <form method='POST' onsubmit=\"return confirm('Withdraw these results? Teachers will no longer see them.');\">{$hid}
                  <input type='hidden' name='action' value='unrelease_results'>
                  <button class='er-btn warn' type='submit'><i class='fa-solid fa-lock'></i> Withdraw results</button></form>";
        } else {
            $dis = $subsDone ? '' : 'disabled';
            echo "<span class='er-note'>" . ($subsDone
                    ? "Marks have been entered for all subjects. Releasing makes these results visible to everyone."
                    : "Release unlocks once marks have been entered for every subject.") . "</span>
                  <form method='POST' onsubmit=\"return confirm('Release these results to everyone?');\">{$hid}
                  <input type='hidden' name='action' value='release_results'>
                  <button class='er-btn' type='submit' $dis><i class='fa-solid fa-lock-open'></i> Release results</button></form>";
        }
        echo "</div>";
    } elseif (!$released) {
        echo "<div class='er-actions'><span class='er-note'><i class='fa-solid fa-lock'></i> " . ($subsDone
            ? "All subjects have marks. The results, analysis and report cards will appear once the administrator releases them."
            : "The results, analysis and report cards appear once every subject has marks entered and the administrator releases them. Check this page for progress.") . "</span></div>";
    }
    if ($isAdmin && !$subsDone) {
        echo "<div class='er-actions'><span class='er-note'><i class='fa-solid fa-circle-info'></i> The results table and downloads stay hidden (even for you) until every subject has marks entered.</span></div>";
    }
    echo "</div>";

    /* ── Overview of all grades for this exam ── */
    include_once 'subjects_config.php';
    echo "<div class='section-divider'><h2>All Grades &mdash; " . erh($examLabel) . "</h2></div><div class='er-card'><div class='er-scroll'><table class='er-table'><thead><tr>
          <th class='l'>Grade</th><th>Learners</th><th>Subjects complete</th><th>Marks entered</th><th>Status</th></tr></thead><tbody>";
    for ($g = 1; $g <= 9; $g++) {
        $map = getSubjectsForGrade($g);
        if (!$map) continue;
        $p   = ($g == (int)$grade) ? $prog : examEntryProgress($conn, $g, $termLabel, $exam, $year, array_keys($map));
        $rel = ($g == (int)$grade) ? $released : isExamReleased($conn, $g, $termLabel, $exam, $year);
        $done = 0;
        foreach ($p['by_subject'] as $cnt) if ($cnt > 0) $done++;
        if ($rel)                    $stat = "<span class='er-badge er-ok'>Released</span>";
        elseif (erSubjectsComplete($p)) $stat = "<span class='er-badge er-hold'>Complete &ndash; awaiting release</span>";
        elseif ($p['filled'] > 0)    $stat = "<span class='er-badge er-hold'>In progress</span>";
        else                         $stat = "<span class='flag danger'>No marks yet</span>";
        $url = '?' . http_build_query(['grade' => $g, 'term' => $termLabel, 'exam_type' => $exam, 'year' => $year]);
        /* Admin can release (or withdraw) any grade straight from this table — grades are independent. */
        $act = '';
        if ($isAdmin && ($rel || erSubjectsComplete($p))) {
            $act = "<form method='POST' style='display:inline;margin-left:8px' onsubmit=\"return confirm('" . ($rel ? 'Withdraw' : 'Release') . " Grade $g results?');\">"
                 . "<input type='hidden' name='csrf' value='" . erh($csrf) . "'><input type='hidden' name='grade' value='$g'>"
                 . "<input type='hidden' name='term' value='" . erh($termLabel) . "'><input type='hidden' name='exam_type' value='" . erh($exam) . "'>"
                 . "<input type='hidden' name='year' value='" . erh($year) . "'><input type='hidden' name='action' value='" . ($rel ? 'unrelease_results' : 'release_results') . "'>"
                 . "<button class='er-btn" . ($rel ? " warn" : "") . "' type='submit' style='padding:5px 11px;font-size:.7rem'>" . ($rel ? 'Withdraw' : 'Release') . "</button></form>";
        }
        echo "<tr class='" . ($g == (int)$grade ? 'cur' : '') . "'><td class='l'><a href='" . erh($url) . "'>Grade $g</a></td>
              <td>" . (int)$p['students'] . "</td><td>$done / " . count($map) . "</td><td>" . (int)$p['pct'] . "%</td><td>$stat$act</td></tr>";
    }
    echo "</tbody></table></div></div>";

    /* Table, analysis and downloads (report cards, marks Excel, analysis PDF) appear ONLY when
       every subject has marks for every learner AND the admin has released them.
       The admin may preview as soon as all marks are in (to check before releasing). */
    return !($subsDone && ($released || $isAdmin));
}