<?php

/** @var SuperExport\Web\View $view */
/** @var array<string, array{created: int, skipped: int, errors: list<string>}> $report */
/** @var bool $dryRun */
/** @var string $input */
/** @var array<string, array<string, string>> $overrides */
/** @var string $duplicates */

use SuperExport\Web\View;

$hasErrors = false;
foreach ($report as $row) {
    if ($row['errors'] !== []) {
        $hasErrors = true;
        break;
    }
}

?>
<div class="card">
  <h2 style="margin-top:0" class="<?= $hasErrors ? 'err' : 'ok' ?>">
    <?= $dryRun ? 'Dry-run complete' : 'Import complete' ?><?= $hasErrors ? ' (with errors)' : '' ?>
  </h2>
  <table>
    <tr><th>Entity</th><th>Created</th><th>Skipped</th><th>Errors</th></tr>
    <?php foreach ($report as $entity => $row): ?>
      <tr>
        <td><?= View::e($entity) ?></td>
        <td><?= (int) $row['created'] ?></td>
        <td><?= (int) $row['skipped'] ?></td>
        <td class="<?= $row['errors'] !== [] ? 'err' : '' ?>"><?= count($row['errors']) ?></td>
      </tr>
    <?php endforeach; ?>
  </table>

  <?php foreach ($report as $entity => $row): ?>
    <?php if ($row['errors'] !== []): ?>
      <h2 class="err"><?= View::e($entity) ?> errors</h2>
      <pre class="log" style="max-height:200px"><?php
        foreach ($row['errors'] as $error) {
            echo View::e($error) . "\n";
        }
      ?></pre>
    <?php endif; ?>
  <?php endforeach; ?>

  <?php if ($dryRun): ?>
    <form method="post" action="<?= View::e($view->url('import-run')) ?>">
      <input type="hidden" name="input" value="<?= View::e($input) ?>">
      <input type="hidden" name="duplicates" value="<?= View::e($duplicates) ?>">
      <?php foreach ($overrides as $type => $fields): ?>
        <?php foreach ($fields as $canonical => $target): ?>
          <input type="hidden"
                 name="map[<?= View::e($type) ?>][<?= View::e($canonical) ?>]"
                 value="<?= View::e($target) ?>">
        <?php endforeach; ?>
      <?php endforeach; ?>
      <p class="actions">
        <button class="btn" type="submit">Run real import with the same settings</button>
        <a class="btn secondary" href="<?= View::e($view->url('dashboard')) ?>">Back to dashboard</a>
      </p>
    </form>
  <?php else: ?>
    <p>Mapping and id table saved to <code><?= View::e($input . DIRECTORY_SEPARATOR . 'import_map.json') ?></code>.</p>
    <p class="actions"><a class="btn secondary" href="<?= View::e($view->url('dashboard')) ?>">Back to dashboard</a></p>
  <?php endif; ?>
</div>
