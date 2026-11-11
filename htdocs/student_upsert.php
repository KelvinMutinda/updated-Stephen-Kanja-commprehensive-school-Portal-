<?php
/*
 * student_upsert.php
 * ==================
 * Shared by reg.php (manual form) and bulk_register.php (Excel).
 * Decides per learner: INSERT (new) or UPDATE (already exists).
 *
 * How an existing learner is recognised, in order:
 *   1) UPI, then Assesment, then birthNo (exact match, only if supplied)
 *   2) Names: the first/middle/surname words are compared as a set, ignoring
 *      order and case. A learner is "the same" when the word sets are equal,
 *      or when the shorter set (at least 2 words) sits inside the longer one.
 *      So "John Kamau" matches "John Kamau Mwangi" and "John Mwangi", and
 *      "Kamau John" matches "John Kamau".
 *      A single name on its own is never matched (far too common).
 * If more than one learner matches, the row is skipped, never guessed.
 */

function su_norm($s) {
    return mb_strtolower(preg_replace('/\s+/u', ' ', trim((string)$s)));
}

function su_tokens(array $parts): array {
    $t = [];
    foreach ($parts as $p) {
        foreach (preg_split('/\s+/u', su_norm($p), -1, PREG_SPLIT_NO_EMPTY) as $w) $t[$w] = true;
    }
    return array_keys($t);
}

function su_grade_allows_null(mysqli $conn): bool {
    $ok = true;
    $r = $conn->query("SHOW COLUMNS FROM Student LIKE 'Grade'");
    if ($r && ($col = $r->fetch_assoc())) $ok = (strtoupper($col['Null']) === 'YES');
    return $ok;
}

/** @return array ['id'=>..,'by'=>'identifier'|'name'] | ['ambiguous'=>reason] | [] */
function su_find(mysqli $conn, array $s): array {
    // 1) identifiers
    $idCols = [
        'upi'        => ['UPI',       'UPI'],
        'assessment' => ['Assesment', 'Assessment number'],
        'birthCert'  => ['birthNo',   'Birth certificate number'],
    ];
    foreach ($idCols as $k => [$col, $label]) {
        if ($s[$k] === '') continue;
        $stmt = $conn->prepare("SELECT id FROM Student WHERE `$col` = ?");
        if (!$stmt) throw new Exception($conn->error);
        $stmt->bind_param('s', $s[$k]);
        $stmt->execute();
        $res = $stmt->get_result();
        $ids = [];
        while ($row = $res->fetch_assoc()) $ids[] = $row['id'];
        $stmt->close();
        if (count($ids) === 1) return ['id' => $ids[0], 'by' => 'identifier'];
        if (count($ids) > 1)   return ['ambiguous' => "$label matches more than one learner"];
    }

    // 2) names
    $mine = su_tokens([$s['firstName'], $s['middleName'], $s['surname']]);
    if (count($mine) < 2) return [];

    $conds = []; $params = [];
    foreach ($mine as $tok) {
        $conds[]  = "LOWER(CONCAT_WS(' ', firstName, middleName, surname)) LIKE ?";
        $params[] = '%' . addcslashes($tok, '%_\\') . '%';
    }
    $stmt = $conn->prepare(
        "SELECT id, firstName, middleName, surname FROM Student WHERE " . implode(' OR ', $conds)
    );
    if (!$stmt) throw new Exception($conn->error);
    $stmt->bind_param(str_repeat('s', count($params)), ...$params);
    $stmt->execute();
    $res = $stmt->get_result();

    $exact = []; $partial = [];
    while ($row = $res->fetch_assoc()) {
        $theirs = su_tokens([$row['firstName'], $row['middleName'], $row['surname']]);
        $common = count(array_intersect($mine, $theirs));
        $small  = min(count($mine), count($theirs));
        if ($common === count($mine) && $common === count($theirs))      $exact[]   = $row['id'];
        elseif ($small >= 2 && $common === $small)                       $partial[] = $row['id'];
    }
    $stmt->close();

    if (count($exact) === 1)   return ['id' => $exact[0],   'by' => 'name'];
    if (count($exact) > 1)     return ['ambiguous' => 'More than one learner has this name'];
    if (count($partial) === 1) return ['id' => $partial[0], 'by' => 'name'];
    if (count($partial) > 1)   return ['ambiguous' => 'More than one learner has a similar name'];
    return [];
}

/**
 * Validate + insert-or-update one learner.
 * $s keys: upi, assessment, firstName, middleName, surname, dob, grade, birthCert
 * @return array ['action'=>'insert'|'update'|'skip', 'reason'=>.., 'name'=>..]
 */
function su_process(mysqli $conn, array $s, bool $dryRun, bool $gradeAllowsNull): array {
    $g = fn($k) => trim((string)($s[$k] ?? ''));
    $upi = $g('upi'); $assessment = $g('assessment'); $firstName = $g('firstName');
    $middleName = $g('middleName'); $surname = $g('surname');
    $dobRaw = $g('dob'); $gradeRaw = $g('grade'); $birthCert = $g('birthCert');

    $errs = [];
    if ($firstName === '') $errs[] = 'First name missing';
    if ($dobRaw !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dobRaw)) $errs[] = 'DOB format invalid (expected YYYY-MM-DD)';
    if ($gradeRaw !== '' && (!is_numeric($gradeRaw) || (int)$gradeRaw < 1 || (int)$gradeRaw > 9)) $errs[] = 'Grade out of range (1-9)';
    if ($errs) return ['action' => 'skip', 'reason' => implode(', ', $errs)];

    $dob   = $dobRaw !== '' ? $dobRaw : null;
    $grade = $gradeRaw !== '' ? (int)$gradeRaw : null;
    $name  = trim($firstName . ' ' . $surname);

    $clean = [
        'upi' => $upi, 'assessment' => $assessment, 'firstName' => $firstName,
        'middleName' => $middleName, 'surname' => $surname, 'birthCert' => $birthCert,
    ];

    try {
        $found = su_find($conn, $clean);
    } catch (Throwable $e) {
        error_log('student_upsert find failed: ' . $e->getMessage());
        return ['action' => 'skip', 'reason' => 'Database error: ' . $e->getMessage()];
    }

    if (isset($found['ambiguous'])) return ['action' => 'skip', 'reason' => $found['ambiguous']];

    /* ── UPDATE existing learner ── blank cells never erase saved data ── */
    if (isset($found['id'])) {
        if (!$dryRun) {
            $set = []; $types = ''; $vals = [];
            $add = function ($col, $val) use (&$set, &$types, &$vals) {
                $set[] = "`$col` = ?"; $types .= 's'; $vals[] = $val;
            };
            if ($upi !== '')        $add('UPI', $upi);
            if ($assessment !== '') $add('Assesment', $assessment);
            if ($birthCert !== '')  $add('birthNo', $birthCert);
            if ($dob !== null)      $add('DOB', $dob);
            if ($grade !== null)    $add('Grade', $grade);
            // Matched by name -> the names already agree, so leave them alone.
            // Matched by UPI/Assessment/BirthNo -> allow name corrections.
            if ($found['by'] === 'identifier') {
                $add('firstName', $firstName);
                if ($middleName !== '') $add('middleName', $middleName);
                if ($surname !== '')    $add('surname', $surname);
            }
            if ($set) {
                $vals[] = $found['id']; $types .= 's';
                $stmt = $conn->prepare("UPDATE Student SET " . implode(', ', $set) . " WHERE id = ?");
                if (!$stmt) return ['action' => 'skip', 'reason' => 'Update prepare failed — ' . $conn->error];
                $stmt->bind_param($types, ...$vals);
                if (!$stmt->execute()) {
                    $err = $stmt->error; $stmt->close();
                    error_log('student_upsert update failed: ' . $err);
                    return ['action' => 'skip', 'reason' => 'Update failed — ' . $err];
                }
                $stmt->close();
            }
        }
        return ['action' => 'update', 'name' => $name, 'by' => $found['by']];
    }

    /* ── INSERT new learner ── */
    if ($grade === null && !$gradeAllowsNull) {
        return ['action' => 'skip', 'reason' => 'Grade required until Student.Grade is altered to allow NULL'];
    }
    if (!$dryRun) {
        $stmt = $conn->prepare(
            "INSERT INTO Student (UPI, Assesment, firstName, middleName, surname, DOB, Grade, birthNo, role)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'user')"
        );
        if (!$stmt) return ['action' => 'skip', 'reason' => 'Insert prepare failed — ' . $conn->error];
        $stmt->bind_param('ssssssis', $upi, $assessment, $firstName, $middleName, $surname, $dob, $grade, $birthCert);
        if (!$stmt->execute()) {
            $err = $stmt->error; $stmt->close();
            error_log('student_upsert insert failed: ' . $err);
            return ['action' => 'skip', 'reason' => 'Insert failed — ' . $err];
        }
        $stmt->close();
    }
    return ['action' => 'insert', 'name' => $name];
}