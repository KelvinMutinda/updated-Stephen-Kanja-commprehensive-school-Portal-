<?php
// Include after conn.php + auth_helpers.php, inside your register/edit teacher <form>.
// Save on the server as: teacher_assignments_form.php
//
// In the form:  call renderTeacherAssignmentsFields($conn, $teacherId)   ($teacherId = 0 when registering)
// On save:      saveTeacherAssignments($conn, $teacherId, $_POST['assign'] ?? []);
//               (after an INSERT, $teacherId = mysqli_insert_id($conn))
function renderTeacherAssignmentsFields(mysqli $conn, int $teacherId = 0): void {
    $current = $teacherId > 0 ? getTeacherAssignments($conn, $teacherId) : [];
    ?>
    <div class="ta-wrap">
      <p class="ta-title">Grades &amp; subjects this teacher handles</p>
      <p class="ta-hint">The teacher will only be able to upload marks for the ticked grade/subject combinations.</p>
      <?php foreach (ALL_GRADES as $g): ?>
      <div class="ta-row">
        <label class="ta-grade">
          <input type="checkbox" class="ta-grade-all" data-grade="<?= $g ?>"> Grade <?= $g ?>
        </label>
        <div class="ta-subjects">
          <?php foreach (SUBJECT_COLS as $col => $label):
              $checked = isset($current[$g]) && in_array($col, $current[$g], true); ?>
          <label class="ta-sub">
            <input type="checkbox" name="assign[<?= $g ?>][]" value="<?= $col ?>" data-grade="<?= $g ?>"<?= $checked ? ' checked' : '' ?>>
            <?= htmlspecialchars($label) ?>
          </label>
          <?php endforeach; ?>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
    <style>
      .ta-wrap{border:1px solid #dfe3ea;border-radius:8px;padding:14px 16px;margin:14px 0;background:#fff}
      .ta-title{font-weight:700;font-size:14px;margin-bottom:2px}
      .ta-hint{font-size:12px;color:#6b7280;margin-bottom:10px}
      .ta-row{display:flex;gap:16px;padding:8px 0;border-top:1px solid #edf0f4;align-items:flex-start;flex-wrap:wrap}
      .ta-grade{width:90px;font-weight:600;font-size:13.5px;display:flex;gap:6px;align-items:center}
      .ta-subjects{display:flex;flex-wrap:wrap;gap:6px 16px;flex:1}
      .ta-sub{display:flex;gap:6px;align-items:center;font-size:13.5px}
    </style>
    <script>
      (function () {
        document.querySelectorAll('.ta-grade-all').forEach(function (master) {
          var boxes = document.querySelectorAll('.ta-sub input[data-grade="' + master.dataset.grade + '"]');
          function sync() { master.checked = Array.prototype.every.call(boxes, function (b) { return b.checked; }); }
          master.addEventListener('change', function () { boxes.forEach(function (b) { b.checked = master.checked; }); });
          boxes.forEach(function (b) { b.addEventListener('change', sync); });
          sync();
        });
      })();
    </script>
    <?php
}