<?php
// ============================================================
// subjects_config.php — single source of truth for subject sets
// per grade band, and for the 8-level performance bands.
// Include this AFTER conn.php in any script that deals with
// subjects, marks, or performance bands.
//
// Grade bands:
//   1-3: Maths, English, Kiswahili, Environmental Activities
//        (Environmental Activities is stored in the `scie` column —
//         there is NO separate envt column. This is intentional:
//         a Grade 1-3 student only ever sits one of {Science,
//         Environmental Activities}, so reusing the column avoids
//         a schema change and keeps totals/averages correct without
//         any extra NULL-handling.)
//   4-6: all subjects except Pre-Technical (scie here means Science)
//   7-9: all 9 subjects (unchanged)
// ============================================================

// Master subject list: code => [label, grades it applies to]
// NOTE: 'scie' label is grade-dependent — see getSubjectsForGrade()
// below. The label here is just the grades-4-9 default.
$GLOBALS['SUBJECT_MASTER'] = [
    'math'   => ['label' => 'Mathematics', 'grades' => [1,2,3,4,5,6,7,8,9]],
    'eng'    => ['label' => 'English',     'grades' => [1,2,3,4,5,6,7,8,9]],
    'kisw'   => ['label' => 'Kiswahili',   'grades' => [1,2,3,4,5,6,7,8,9]],
    'sst'    => ['label' => 'Social Studies', 'grades' => [4,5,6,7,8,9]],
    'scie'   => ['label' => 'Science',     'grades' => [1,2,3,4,5,6,7,8,9]], // grades 1-3 relabeled below
    'ca'     => ['label' => 'CA',          'grades' => [4,5,6,7,8,9]],
    'agri'   => ['label' => 'Agriculture', 'grades' => [4,5,6,7,8,9]],
    're'     => ['label' => 'RE',          'grades' => [4,5,6,7,8,9]],
    'pretec' => ['label' => 'Pre-Technical','grades' => [7,8,9]],
];

/**
 * Returns [code => label] for the subjects that apply to a given grade,
 * in the master's canonical order. For grades 1-3, the 'scie' column's
 * label is swapped to "Environmental Activities" — same column, same
 * code, just a grade-appropriate display name.
 */
function getSubjectsForGrade(int $grade): array {
    $out = [];
    foreach ($GLOBALS['SUBJECT_MASTER'] as $code => $info) {
        if (in_array($grade, $info['grades'], true)) {
            if ($code === 'scie' && $grade >= 1 && $grade <= 3) {
                $out[$code] = 'Environmental Activities';
            } else {
                $out[$code] = $info['label'];
            }
        }
    }
    return $out;
}

/**
 * Whitelists a subject code against the master list (for safe use in
 * dynamic SQL column names — never interpolate a raw user string).
 */
function isKnownSubjectCode(string $code): bool {
    return array_key_exists($code, $GLOBALS['SUBJECT_MASTER']);
}

/**
 * The 8-level CBC-style performance band for a 0-100 score.
 * Sub-band 1 is the HIGHER one in every tier:
 *   EE1 90-100 | EE2 75-89 | ME1 63-74 | ME2 50-62
 *   AE1 38-49  | AE2 26-37 | BE1 13-25 | BE2 0-12
 * Returns ['code'=>'EE1','label'=>'Exceeding Expectation 1','tier'=>'ee','tierNum'=>1]
 * Adjust the cutoffs here only — keep the JS band functions in ViewResults.php in sync.
 */
function bandInfo(float $score): array {
    if ($score >= 90) return ['code' => 'EE1', 'label' => 'Exceeding Expectation 1',   'tier' => 'ee', 'tierNum' => 1];
    if ($score >= 75) return ['code' => 'EE2', 'label' => 'Exceeding Expectation 2',   'tier' => 'ee', 'tierNum' => 2];
    if ($score >= 63) return ['code' => 'ME1', 'label' => 'Meeting Expectation 1',     'tier' => 'me', 'tierNum' => 1];
    if ($score >= 50) return ['code' => 'ME2', 'label' => 'Meeting Expectation 2',     'tier' => 'me', 'tierNum' => 2];
    if ($score >= 38) return ['code' => 'AE1', 'label' => 'Approaching Expectation 1', 'tier' => 'ae', 'tierNum' => 1];
    if ($score >= 26) return ['code' => 'AE2', 'label' => 'Approaching Expectation 2', 'tier' => 'ae', 'tierNum' => 2];
    if ($score >= 13) return ['code' => 'BE1', 'label' => 'Below Expectation 1',       'tier' => 'be', 'tierNum' => 1];
    return                  ['code' => 'BE2', 'label' => 'Below Expectation 2',       'tier' => 'be', 'tierNum' => 2];
}

/** Backwards-compatible shim: old code called bandLabel() for the 4-level tier only. */
function bandLabel(float $score): string {
    return bandInfo($score)['tier']; // 'ee' | 'me' | 'ae' | 'be'
}

/** All 8 band codes in display order (highest first), for building tables/legends. */
function allBandCodes(): array {
    return ['EE1','EE2','ME1','ME2','AE1','AE2','BE1','BE2'];
}

/** A representative score for each band code, used only to fetch its label text. */
function representativeScoreFor(string $code): float {
    $map = ['EE1'=>95,'EE2'=>82,'ME1'=>68,'ME2'=>56,'AE1'=>43,'AE2'=>31,'BE1'=>19,'BE2'=>6];
    return $map[$code] ?? 0;
}

// ============================================================
// SIMPLIFIED 4-LEVEL BANDS — Grades 1-6
// Lower grades use the plain CBC bands (E.E / M.E / A.E / B.E)
// instead of the split EE1/EE2 etc used for Grades 7-9. The
// cutoffs below intentionally mirror the *tier* boundaries of
// bandInfo() above, so counts/percentages stay identical either
// way — only the on-screen label/code changes.
// ============================================================

/**
 * The simple 4-level performance band for a 0-100 score.
 * Returns ['code'=>'E.E','label'=>'Exceeding Expectation','tier'=>'ee','tierNum'=>1]
 */
function bandInfoSimple(float $score): array {
    if ($score >= 75) return ['code' => 'E.E', 'label' => 'Exceeding Expectation',    'tier' => 'ee', 'tierNum' => 1];
    if ($score >= 50) return ['code' => 'M.E', 'label' => 'Meeting Expectation',      'tier' => 'me', 'tierNum' => 1];
    if ($score >= 26) return ['code' => 'A.E', 'label' => 'Approaching Expectation',  'tier' => 'ae', 'tierNum' => 1];
    return                  ['code' => 'B.E', 'label' => 'Below Expectation',        'tier' => 'be', 'tierNum' => 1];
}

/**
 * Grade-aware dispatcher: Grades 1-6 get the simple 4-level bands
 * (E.E / M.E / A.E / B.E); Grades 7-9 keep the detailed 8-level bands
 * (EE1/EE2/ME1/ME2/AE1/AE2/BE1/BE2). Use this everywhere a band needs
 * to be shown to a user instead of calling bandInfo() directly.
 */
function bandInfoForGrade(float $score, int $grade): array {
    if ($grade >= 1 && $grade <= 6) return bandInfoSimple($score);
    return bandInfo($score);
}

/** All band codes for a grade, in display order (4 for grades 1-6, 8 for grades 7-9). */
function allBandCodesForGrade(int $grade): array {
    if ($grade >= 1 && $grade <= 6) return ['E.E','M.E','A.E','B.E'];
    return allBandCodes();
}

/** A representative score for a band code, grade-aware (used for legend rows). */
function representativeScoreForGrade(string $code, int $grade): float {
    if ($grade >= 1 && $grade <= 6) {
        $map = ['E.E'=>85,'M.E'=>62,'A.E'=>38,'B.E'=>13];
        return $map[$code] ?? 0;
    }
    return representativeScoreFor($code);
}

/** CSS-safe class fragment for a band code, e.g. 'EE1' -> 'ee1', 'E.E' -> 'ee'. */
function bandCssClass(string $code): string {
    return strtolower(str_replace('.', '', $code));
}