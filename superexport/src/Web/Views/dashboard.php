<?php

/** @var SuperExport\Web\View $view */
/** @var array{name: string, version: string, site_url: string, db_prefix: string, entities: list<string>}|null $cms */
/** @var string|null $cmsError */
/** @var string $storagePath */
/** @var array{exported_at: string, cms: string, stats: array<string, int>}|null $lastExport */

use SuperExport\Web\View;

?>
<h1>Dashboard</h1>

<div class="card">
  <h2 style="margin-top:0">Detected CMS</h2>
  <?php if ($cms !== null): ?>
    <table>
      <tr><th>CMS</th><td><?= View::e($cms['name']) ?></td></tr>
      <tr><th>Version</th><td><?= View::e($cms['version']) ?></td></tr>
      <tr><th>Site URL</th><td><?= View::e($cms['site_url']) ?></td></tr>
      <tr><th>DB prefix</th><td><code><?= View::e($cms['db_prefix']) ?></code></td></tr>
      <tr><th>Entities</th><td>
        <?php foreach ($cms['entities'] as $entity): ?>
          <span class="badge"><?= View::e($entity) ?></span>
        <?php endforeach; ?>
      </td></tr>
    </table>
    <p class="actions">
      <a class="btn" href="<?= View::e($view->url('export')) ?>">Export content</a>
      <a class="btn secondary" href="<?= View::e($view->url('import')) ?>">Import content</a>
    </p>
  <?php else: ?>
    <p class="err"><?= View::e($cmsError ?? 'CMS could not be detected.') ?></p>
    <p class="muted">Place <code>superexport.php</code> in the CMS root or set <code>cms_root</code> in <code>config.php</code>.</p>
  <?php endif; ?>
</div>

<div class="card">
  <h2 style="margin-top:0">Storage</h2>
  <p>Path: <code><?= View::e($storagePath) ?></code></p>
  <?php if ($lastExport !== null): ?>
    <p>Last export: <strong><?= View::e($lastExport['cms']) ?></strong>
       at <?= View::e($lastExport['exported_at']) ?></p>
    <table>
      <tr><th>Entity</th><th>Records</th></tr>
      <?php foreach ($lastExport['stats'] as $entity => $count): ?>
        <tr><td><?= View::e($entity) ?></td><td><?= (int) $count ?></td></tr>
      <?php endforeach; ?>
    </table>
  <?php else: ?>
    <p class="muted">No export found in the storage directory yet.</p>
  <?php endif; ?>
</div>
