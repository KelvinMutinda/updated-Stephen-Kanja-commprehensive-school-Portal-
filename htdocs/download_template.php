<?php
/**
 * download_template.php
 * -------------------------------------------------------------
 * Marks-entry template downloader with Excel formatting.
 * Redesigned: narrower, deliberately-sized columns via <colgroup>
 * (the technique Excel actually honors, unlike per-cell widths),
 * and a selection UI matching the gold/black brand used on
 * Progress.php for a consistent look across the app.
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/conn.php';

define('SCHOOL_NAME', 'Stephen Kanja Primary & Junior School');

// Number of blank subject columns (teacher writes subject names in row 6)
const BLANK_SUBJECT_COLUMNS = 5;

const TERMS = ['Term 1', 'Term 2', 'Term 3'];
const TERMS_SHORT = ['T1', 'T2', 'T3'];
const EXAM_TYPES = ['Opener', 'Mid Term', 'End Term'];
const EXAM_TYPES_SHORT = ['OP', 'MT', 'ET'];

function getDb(): mysqli
{
    global $conn;
    return $conn;
}

function fetchAvailableGrades(mysqli $conn): array
{
    $grades = [];
    $res = mysqli_query($conn, "SELECT DISTINCT Grade FROM Student WHERE Grade IS NOT NULL ORDER BY Grade ASC");
    if ($res) {
        while ($row = mysqli_fetch_assoc($res)) {
            $grades[] = (int) $row['Grade'];
        }
    }
    return $grades;
}

// ---------------------------------------------------------------
// AJAX branch: roster count preview
// ---------------------------------------------------------------
if (isset($_GET['ajax']) && $_GET['ajax'] === 'count') {
    header('Content-Type: application/json');
    $grade = isset($_GET['grade']) ? (int) $_GET['grade'] : 0;

    if ($grade <= 0) {
        echo json_encode(['count' => 0]);
        exit;
    }

    $conn = getDb();
    $stmt = mysqli_prepare($conn, "SELECT COUNT(*) AS c FROM Student WHERE Grade = ?");
    mysqli_stmt_bind_param($stmt, 'i', $grade);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $row = mysqli_fetch_assoc($result);

    echo json_encode(['count' => (int) ($row['c'] ?? 0)]);
    exit;
}

// ---------------------------------------------------------------
// Download branch
// ---------------------------------------------------------------
function generateTemplate(int $grade, string $term, string $examType, int $year): void
{
    while (ob_get_level()) {
        ob_end_clean();
    }

    $conn = getDb();

    $countStmt = mysqli_prepare($conn, "SELECT COUNT(*) AS total FROM Student WHERE Grade = ?");
    mysqli_stmt_bind_param($countStmt, 'i', $grade);
    mysqli_stmt_execute($countStmt);
    $countRow = mysqli_fetch_assoc(mysqli_stmt_get_result($countStmt));
    $studentCount = $countRow['total'] ?? 0;

    $stmt = mysqli_prepare(
        $conn,
        "SELECT UPI, firstName, surname FROM Student WHERE Grade = ? ORDER BY surname ASC, firstName ASC"
    );
    mysqli_stmt_bind_param($stmt, 'i', $grade);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);

    $students = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $students[] = $row;
    }

    $termIndex = array_search($term, TERMS);
    $termShort = $termIndex !== false ? TERMS_SHORT[$termIndex] : $term;

    $examIndex = array_search($examType, EXAM_TYPES);
    $examShort = $examIndex !== false ? EXAM_TYPES_SHORT[$examIndex] : $examType;

    $safeGrade = $grade;
    $safeTerm = preg_replace('/[^A-Za-z0-9]+/', '', $termShort);
    $safeExam = preg_replace('/[^A-Za-z0-9]+/', '', $examShort);
    $filename = "MarksEntry_Grade{$safeGrade}_{$safeTerm}_{$safeExam}_{$year}.xls";

    header('Content-Type: application/vnd.ms-excel; charset=utf-8');
    header("Content-Disposition: attachment; filename=\"{$filename}\"");
    header('Cache-Control: max-age=0');
    header('Pragma: public');
    header('Expires: 0');

    $output = fopen('php://output', 'w');
    if ($output === false) {
        die('Failed to open output stream');
    }
    fwrite($output, "\xEF\xBB\xBF");

    // ============================================
    // Column plan (via <colgroup> — this is what Excel actually
    // respects for width, unlike CSS width on individual <td>s):
    //   #            28px
    //   UPI          65px
    //   First Name   95px
    //   Surname      95px
    //   S1..S5       45px each  <- deliberately narrow: mark entry only
    // ============================================
    $subjectColWidth = 45;
    $infoColWidths = [28, 65, 95, 95];
    $totalCols = count($infoColWidths) + BLANK_SUBJECT_COLUMNS;

    $html = '<html xmlns:o="urn:schemas-microsoft-com:office:office"
                  xmlns:x="urn:schemas-microsoft-com:office:excel"
                  xmlns="http://www.w3.org/TR/REC-html40">
    <head>
        <meta http-equiv="Content-Type" content="text/html; charset=utf-8">
        <!--[if gte mso 9]>
        <xml>
            <x:ExcelWorkbook>
                <x:ExcelWorksheets>
                    <x:ExcelWorksheet>
                        <x:Name>Marks Entry</x:Name>
                        <x:WorksheetOptions>
                            <x:DisplayGridlines/>
                            <x:Print>
                                <x:FitWidth>1</x:FitWidth>
                                <x:FitHeight>0</x:FitHeight>
                                <x:Orientation>Portrait</x:Orientation>
                                <x:PaperSizeIndex>9</x:PaperSizeIndex>
                                <x:LeftMargin>0.15</x:LeftMargin>
                                <x:RightMargin>0.15</x:RightMargin>
                                <x:TopMargin>0.3</x:TopMargin>
                                <x:BottomMargin>0.3</x:BottomMargin>
                            </x:Print>
                            <x:FreezePanes>1</x:FreezePanes>
                            <x:FrozenPanes>1</x:FrozenPanes>
                            <x:FreezeColumns>4</x:FreezeColumns>
                            <x:FreezeRows>7</x:FreezeRows>
                        </x:WorksheetOptions>
                    </x:ExcelWorksheet>
                </x:ExcelWorksheets>
            </x:ExcelWorkbook>
        </xml>
        <![endif]-->
        <style>
            table { border-collapse: collapse; font-family: Arial, sans-serif; }
            td, th { border: 1px solid #000000; padding: 2px 4px; }

            .banner    { background:#111111; color:#f0c040; font-weight:bold; font-size:12pt; text-align:center; padding:6px 2px; }
            .subbanner { background:#2a2a2a; color:#ffffff; font-weight:bold; font-size:8pt;  text-align:center; padding:3px 2px; }
            .meta      { background:#2a2a2a; color:#f0c040; font-weight:bold; font-size:7.5pt; text-align:center; padding:3px 2px; }
            .spacer    { height:2px; border:none; background:transparent; }

            .subj-label  { background:#111111; color:#f0c040; font-weight:bold; font-size:6.5pt; text-align:center; padding:2px; }
            .subj-slot   { background:#fdf6e3; color:#111111; font-weight:bold; font-size:6pt; text-align:center; padding:2px; }

            .thead { background:#111111; color:#ffffff; font-weight:bold; font-size:7pt; text-align:center; padding:3px 2px; vertical-align:middle; }

            .idx   { background:#f0f0f0; color:#111111; font-weight:bold; font-size:7pt; text-align:center; padding:2px; }
            .cell     { background:#ffffff; color:#111111; font-size:7pt; padding:2px 4px; text-align:left; }
            .cell-alt { background:#f7f7f7; color:#111111; font-size:7pt; padding:2px 4px; text-align:left; }
            .blank    { background:#fafafa; color:#cccccc; font-size:6.5pt; text-align:center; padding:2px; }

            .note { background:#f8f9fa; padding:4px 6px; font-size:6.5pt; color:#333333; }
            .note-foot { background:#f8f9fa; padding:3px 6px; font-size:5.5pt; color:#777777; text-align:center; }
        </style>
    </head>
    <body>
        <table>
            <colgroup>';

    foreach ($infoColWidths as $w) {
        $html .= '<col style="width:' . $w . 'px">';
    }
    for ($i = 0; $i < BLANK_SUBJECT_COLUMNS; $i++) {
        $html .= '<col style="width:' . $subjectColWidth . 'px">';
    }
    $html .= '</colgroup>

            <tr><td colspan="' . $totalCols . '" class="banner">' . strtoupper(SCHOOL_NAME) . '</td></tr>
            <tr><td colspan="' . $totalCols . '" class="subbanner">MARKS ENTRY TEMPLATE</td></tr>
            <tr><td colspan="' . $totalCols . '" class="spacer"></td></tr>
            <tr>
                <td colspan="' . $totalCols . '" class="meta">
                    Grade ' . $grade . '  &middot;  ' . $examShort . ' ' . $termShort . ' ' . $year . '  &middot;  ' . $studentCount . ' Learners  &middot;  Generated ' . date('j M Y') . '
                </td>
            </tr>
            <tr><td colspan="' . $totalCols . '" class="spacer"></td></tr>

            <tr>
                <td colspan="4" class="subj-label">STUDENT INFO</td>';

    for ($i = 1; $i <= BLANK_SUBJECT_COLUMNS; $i++) {
        $html .= '<td class="subj-slot">S' . $i . '</td>';
    }
    $html .= '</tr>

            <tr>';

    foreach (['#', 'UPI', 'First Name', 'Surname'] as $header) {
        $html .= '<td class="thead">' . strtoupper($header) . '</td>';
    }
    for ($i = 1; $i <= BLANK_SUBJECT_COLUMNS; $i++) {
        $html .= '<td class="thead">&nbsp;</td>';
    }
    $html .= '</tr>';

    $no = 1;
    foreach ($students as $index => $s) {
        $rowClass = ($index % 2 === 0) ? 'cell' : 'cell-alt';
        $html .= '<tr>';
        $html .= '<td class="idx">' . $no . '</td>';
        $html .= '<td class="' . $rowClass . '">' . htmlspecialchars($s['UPI']) . '</td>';
        $html .= '<td class="' . $rowClass . '">' . htmlspecialchars($s['firstName']) . '</td>';
        $html .= '<td class="' . $rowClass . '">' . htmlspecialchars($s['surname']) . '</td>';
        for ($i = 1; $i <= BLANK_SUBJECT_COLUMNS; $i++) {
            $html .= '<td class="blank">&nbsp;</td>';
        }
        $html .= '</tr>';
        $no++;
    }

    $html .= '
            <tr><td colspan="' . $totalCols . '" class="spacer"></td></tr>
            <tr><td colspan="' . $totalCols . '" class="note"><b>INSTRUCTIONS:</b> Write subject names in the S1&ndash;S5 row above &middot; enter marks 0&ndash;100.</td></tr>
            <tr><td colspan="' . $totalCols . '" class="note-foot">Generated by ' . SCHOOL_NAME . ' &middot; ' . date('Y-m-d H:i:s') . '</td></tr>
        </table>
    </body>
    </html>';

    fwrite($output, $html);
    fclose($output);
    exit;
}

// ---------------------------------------------------------------
// Handle POST request
// ---------------------------------------------------------------
$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $grade = isset($_POST['grade']) ? (int) $_POST['grade'] : 0;
    $term = trim($_POST['term'] ?? '');
    $examType = trim($_POST['exam_type'] ?? '');
    $year = isset($_POST['year']) ? (int) $_POST['year'] : 0;

    if ($grade <= 0) $errors[] = 'Grade is required.';
    if (!in_array($term, TERMS, true)) $errors[] = 'Term is required.';
    if (!in_array($examType, EXAM_TYPES, true)) $errors[] = 'Exam type is required.';
    if ($year < 2000 || $year > 2100) $errors[] = 'Year is required.';

    if (empty($errors)) {
        generateTemplate($grade, $term, $examType, $year);
    }
}

$conn = getDb();
$availableGrades = fetchAvailableGrades($conn);
$currentYear = (int) date('Y');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Download Marks Entry Template</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Bebas+Neue&family=DM+Sans:wght@400;500;700&display=swap" rel="stylesheet">
<style>
    :root {
        --gold: #f0c040;
        --gold-dim: #c9a030;
        --black: #111111;
        --mid: #2a2a2a;
        --bg: #f4f4f2;
        --card-bg: #ffffff;
        --text: #1a1a18;
        --muted: #888780;
        --border: rgba(0,0,0,0.09);
        --success: #16a34a;
        --error: #dc2626;
    }
    * { box-sizing: border-box; }
    body {
        margin: 0;
        min-height: 100vh;
        background: var(--bg);
        font-family: 'DM Sans', sans-serif;
        color: var(--text);
        display: flex;
        align-items: center;
        justify-content: center;
        padding: 24px;
    }
    .card {
        width: 100%;
        max-width: 440px;
        background: var(--card-bg);
        border-radius: 12px;
        overflow: hidden;
        box-shadow: 0 8px 24px rgba(0,0,0,0.10);
    }
    .card-header {
        background: var(--black);
        border-bottom: 3px solid var(--gold);
        color: #fff;
        padding: 24px 20px 20px;
        text-align: center;
    }
    .card-header .icon {
        width: 42px; height: 42px;
        margin: 0 auto 10px;
        border-radius: 10px;
        background: var(--gold);
        display: flex; align-items: center; justify-content: center;
    }
    .card-header .school-name {
        font-family: 'Bebas Neue', sans-serif;
        font-size: 20px;
        letter-spacing: 0.06em;
        margin: 0 0 4px;
    }
    .card-header .subtitle {
        font-size: 11px;
        color: #999;
        margin: 0;
        text-transform: uppercase;
        letter-spacing: 0.08em;
    }
    .card-body { padding: 20px 22px 24px; }
    .field { margin-bottom: 16px; }
    .field label {
        display: block;
        font-size: 10px;
        font-weight: 700;
        color: var(--muted);
        margin-bottom: 4px;
        text-transform: uppercase;
        letter-spacing: 0.06em;
    }
    .field select, .field input[type="number"] {
        width: 100%;
        padding: 9px 12px;
        border: 1px solid var(--border);
        border-radius: 8px;
        font-size: 14px;
        color: var(--text);
        background: #f8f8f6;
        font-weight: 500;
        font-family: inherit;
    }
    .field select:focus, .field input:focus {
        outline: none;
        border-color: var(--gold);
        box-shadow: 0 0 0 3px rgba(240,192,64,0.15);
    }
    .grade-row { display: flex; gap: 10px; }
    .grade-row .field { flex: 1; }
    .roster-hint {
        min-height: 18px;
        font-size: 12px;
        color: var(--success);
        margin: -6px 0 14px;
        display: flex;
        align-items: center;
        gap: 6px;
        font-weight: 500;
    }
    .roster-hint.loading { color: var(--muted); }
    .roster-hint.empty { color: var(--error); }
    .dot { width: 6px; height: 6px; border-radius: 50%; background: currentColor; flex-shrink: 0; }
    button.submit-btn {
        width: 100%;
        padding: 11px;
        border: none;
        border-radius: 8px;
        background: var(--gold);
        color: var(--black);
        font-size: 14px;
        font-weight: 700;
        letter-spacing: 0.02em;
        cursor: pointer;
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 8px;
        transition: background 0.15s;
    }
    button.submit-btn:hover { background: var(--gold-dim); }
    button.submit-btn:disabled { opacity: 0.6; cursor: not-allowed; }
    .errors {
        background: #fee2e2;
        border: 1px solid #fca5a5;
        color: var(--error);
        border-radius: 8px;
        padding: 10px 14px;
        font-size: 12px;
        margin-bottom: 16px;
    }
    .errors ul { margin: 4px 0 0 18px; padding: 0; }
    .footnote { text-align: center; font-size: 10px; color: var(--muted); margin-top: 14px; line-height: 1.6; }
    .format-note {
        background: #fdf6e3;
        border-radius: 8px;
        padding: 8px 12px;
        font-size: 11px;
        color: var(--black);
        margin-bottom: 16px;
        text-align: center;
        font-weight: 500;
    }
    .badge {
        display: inline-block;
        background: var(--black);
        color: var(--gold);
        padding: 1px 8px;
        border-radius: 10px;
        font-size: 8px;
        font-weight: 700;
        margin: 0 2px;
    }
</style>
</head>
<body>
<div class="card">
    <div class="card-header">
        <div class="icon">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                <path d="M12 3L2 8l10 5 10-5-10-5z" stroke="#111" stroke-width="2" stroke-linejoin="round"/>
                <path d="M6 10.5v5c0 1.5 2.7 3 6 3s6-1.5 6-3v-5" stroke="#111" stroke-width="2" stroke-linejoin="round"/>
                <path d="M12 16v5" stroke="#111" stroke-width="2" stroke-linecap="round"/>
            </svg>
        </div>
        <p class="school-name"><?= htmlspecialchars(SCHOOL_NAME) ?></p>
        <p class="subtitle">Marks Entry Template</p>
    </div>

    <div class="card-body">
        <?php if (!empty($errors)): ?>
            <div class="errors">
                <strong>Please fix:</strong>
                <ul>
                    <?php foreach ($errors as $e): ?>
                        <li><?= htmlspecialchars($e) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <div class="format-note">
            <span class="badge">Compact</span>
            Narrow columns — write subject names in S1–S5
        </div>

        <form method="POST" action="" id="downloadForm">
            <div class="field">
                <label for="grade">Grade</label>
                <select name="grade" id="grade" required>
                    <option value="" disabled selected>Select grade</option>
                    <?php foreach ($availableGrades as $g): ?>
                        <option value="<?= $g ?>" <?= (($_POST['grade'] ?? '') == $g) ? 'selected' : '' ?>>
                            Grade <?= $g ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div id="rosterHint" class="roster-hint"></div>

            <div class="grade-row">
                <div class="field">
                    <label for="term">Term</label>
                    <select name="term" id="term" required>
                        <option value="" disabled selected>Select term</option>
                        <?php foreach (TERMS as $index => $t): ?>
                            <option value="<?= htmlspecialchars($t) ?>" <?= (($_POST['term'] ?? '') === $t) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($t) ?> (<?= TERMS_SHORT[$index] ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field">
                    <label for="exam_type">Exam Type</label>
                    <select name="exam_type" id="exam_type" required>
                        <option value="" disabled selected>Select type</option>
                        <?php foreach (EXAM_TYPES as $index => $et): ?>
                            <option value="<?= htmlspecialchars($et) ?>" <?= (($_POST['exam_type'] ?? '') === $et) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($et) ?> (<?= EXAM_TYPES_SHORT[$index] ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="field">
                <label for="year">Year</label>
                <input type="number" name="year" id="year" min="2000" max="2100"
                       value="<?= htmlspecialchars($_POST['year'] ?? $currentYear) ?>" required>
            </div>

            <button type="submit" class="submit-btn" id="downloadBtn">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                    <path d="M12 3v12m0 0l-4-4m4 4l4-4M4 19h16" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
                Download Template
            </button>
        </form>

        <p class="footnote">
            Pre-filled with student names &amp; UPI numbers.<br>
            <strong>5 narrow columns</strong> for subjects &amp; marks.
        </p>
    </div>
</div>

<script>
const gradeSelect = document.getElementById('grade');
const hint = document.getElementById('rosterHint');
const downloadBtn = document.getElementById('downloadBtn');

async function updateRosterHint() {
    const grade = gradeSelect.value;
    if (!grade) {
        hint.textContent = '';
        hint.className = 'roster-hint';
        return;
    }
    hint.className = 'roster-hint loading';
    hint.innerHTML = '<span class="dot"></span> Checking roster…';
    try {
        const res = await fetch(`?ajax=count&grade=${encodeURIComponent(grade)}`);
        const data = await res.json();
        const count = data.count ?? 0;
        if (count > 0) {
            hint.className = 'roster-hint';
            hint.innerHTML = `<span class="dot"></span> ${count} learner${count === 1 ? '' : 's'} in this grade`;
        } else {
            hint.className = 'roster-hint empty';
            hint.innerHTML = '<span class="dot"></span> No learners found';
        }
    } catch (err) {
        hint.className = 'roster-hint';
        hint.textContent = '';
    }
}

gradeSelect.addEventListener('change', updateRosterHint);

document.getElementById('downloadForm').addEventListener('submit', function() {
    downloadBtn.disabled = true;
    downloadBtn.innerHTML = '⏳ Generating...';
    setTimeout(() => {
        downloadBtn.disabled = false;
        downloadBtn.innerHTML = `
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                <path d="M12 3v12m0 0l-4-4m4 4l4-4M4 19h16" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"/>
            </svg>
            Download Template
        `;
    }, 10000);
});

<?php if (!empty($_POST['grade'])): ?>
updateRosterHint();
<?php endif; ?>
</script>
</body>
</html>