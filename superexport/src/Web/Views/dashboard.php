<?php

/** @var SuperExport\Web\View $view */
/** @var array{name: string, version: string, site_url: string, db_prefix: string, entities: list<string>}|null $cms */
/** @var string|null $cmsError */
/** @var string $cmsRoot */
/** @var list<array{name: string, label: string, detected: bool, selected: bool, checks: list<array{label: string, passed: bool, level: int, detail?: string}>}> $detectionScan */
/** @var string $storagePath */
/** @var array{exported_at: string, cms: string, stats: array<string, int>}|null $lastExport */

use SuperExport\Web\View;

?>
<h1>Dashboard</h1>

<div class="card">
  <h2 style="margin-top:0">CMS detection</h2>
  <p class="muted">Root: <code><?= View::e($cmsRoot) ?></code></p>
  <ul class="detect-list">
    <?php foreach ($detectionScan as $item): ?>
      <li class="<?= $item['selected'] ? 'detect-selected' : '' ?>">
        <label class="detect-item">
          <input type="checkbox" disabled<?= $item['detected'] ? ' checked' : '' ?>>
          <span><?= View::e($item['label']) ?></span>
          <?php if ($item['selected']): ?>
            <span class="badge ok-badge">selected</span>
          <?php elseif ($item['detected']): ?>
            <span class="badge">matched</span>
          <?php endif; ?>
        </label>
        <?php if ($item['checks'] !== []): ?>
          <ul class="detect-sub">
            <?php foreach ($item['checks'] as $check): ?>
              <li class="detect-sub-<?= (int) $check['level'] ?>">
                <label class="detect-item">
                  <input type="checkbox" disabled<?= $check['passed'] ? ' checked' : '' ?>>
                  <span><?= View::e($check['label']) ?></span>
                </label>
                <?php if (!empty($check['detail'])): ?>
                  <div class="detect-detail"><?= View::e($check['detail']) ?></div>
                <?php endif; ?>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
      </li>
    <?php endforeach; ?>
  </ul>
  <?php if ($cms !== null): ?>
    <h3>Active adapter</h3>
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
