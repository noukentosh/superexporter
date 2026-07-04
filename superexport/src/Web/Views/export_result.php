<?php

/** @var SuperExport\Web\View $view */
/** @var string $manifestPath */
/** @var array<string, int> $stats */

use SuperExport\Web\View;

?>
<div class="card">
  <h2 style="margin-top:0" class="ok">Export complete</h2>
  <p>Manifest: <code><?= View::e($manifestPath) ?></code></p>
  <table>
    <tr><th>Entity</th><th>Exported</th></tr>
    <?php foreach ($stats as $entity => $count): ?>
      <tr><td><?= View::e($entity) ?></td><td><?= (int) $count ?></td></tr>
    <?php endforeach; ?>
  </table>
  <p class="actions">
    <a class="btn secondary" href="<?= View::e($view->url('dashboard')) ?>">Back to dashboard</a>
    <a class="btn" href="<?= View::e($view->url('import')) ?>">Continue to import</a>
  </p>
</div>
