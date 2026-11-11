<?php
// ============================================================
// add-exam-type.php — single source of truth for exam types.
//
// Built-ins (opener / midterm / endterm) are always present.
// Anything added through the "+ Add" button in UploadResults.php
// lives in the exam_types table (id, code, name) and is appended
// after the built-ins. exam2.exam_type stores the *code*.
//
// Include with:  require_once 'add-exam-type.php';
// ============================================================

if (!defined('BUILTIN_EXAM_TYPES')) {
    define('BUILTIN_EXAM_TYPES', [
        'opener'  => 'Opener',
        'midterm' => 'Mid-Term',
        'endterm' => 'End Term',
    ]);
}

/**
 * Returns [ code => ['label' => string, 'custom' => bool], ... ]
 * Built-ins first (in their fixed order), then custom types by id.
 * If the exam_types table happens to contain the built-in codes
 * (e.g. seeded manually) they are skipped so they are never listed
 * twice and never treated as deletable.
 */
function getExamTypes($conn) {
    $types = [];
    foreach (BUILTIN_EXAM_TYPES as $code => $label) {
        $types[$code] = ['label' => $label, 'custom' => false];
    }

    $res = @mysqli_query($conn, "SELECT code, name FROM exam_types ORDER BY id ASC");
    if ($res) {
        while ($row = mysqli_fetch_assoc($res)) {
            if (!isset($types[$row['code']])) {
                $types[$row['code']] = ['label' => $row['name'], 'custom' => true];
            }
        }
    }

    return $types;
}

/**
 * Maps whatever came in (a code, or an old-style value such as
 * "mid-term" / "end-term" from a bookmarked URL) to a known exam
 * code. Returns null when nothing matches.
 */
function resolveExamCode($input, array $types) {
    $input = trim((string)$input);
    if ($input === '') return null;
    if (isset($types[$input])) return $input;

    $norm = preg_replace('/[^a-z0-9]/', '', strtolower($input));
    foreach ($types as $code => $_) {
        if (preg_replace('/[^a-z0-9]/', '', $code) === $norm) return $code;
    }
    return null;
}