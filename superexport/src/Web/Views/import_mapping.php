<?php

/** @var SuperExport\Web\View $view */
/** @var string $input */
/** @var array<string, mixed> $manifest */
/** @var string $targetCms */
/** @var list<SuperExport\Universal\EntityKey> $types */
/** @var list<SuperExport\Universal\EntityKey> $unsupported */
/** @var array<string, array{target: ?SuperExport\Universal\EntityKey, label: string, canonical_kind: string}> $entityMapping */
/** @var array<string, string> $supportedTargets */
/** @var array<string, array<string, array{type: string, required: bool}>> $schemaFields */
/** @var array<string, string> $defaults */
/** @var array<string, list<array<string, mixed>>> $preview */

use SuperExport\Web\View;

$source = (array) ($manifest['source'] ?? []);
$stats = (array) ($manifest['stats'] ?? []);

?>
<h1>Import — step 2 of 3: field mapping</h1>

<div class="card">
  <table>
    <tr><th>Source CMS</th><td><?= View::e($source['cms'] ?? 'unknown') ?> <?= View::e($source['cms_version'] ?? '') ?></td></tr>
    <tr><th>Source site</th><td><?= View::e($source['site_url'] ?? 'unknown') ?></td></tr>
    <tr><th>Exported at</th><td><?= View::e($manifest['exported_at'] ?? 'unknown') ?></td></tr>
    <tr><th>Target CMS</th><td><strong><?= View::e($targetCms) ?></strong></td></tr>
    <tr><th>Records</th><td>
      <?php foreach ($stats as $entity => $count): ?>
        <span class="badge"><?= View::e($entity) ?>: <?= (int) $count ?></span>
      <?php endforeach; ?>
    </td></tr>
  </table>
  <?php if ($unsupported !== []): ?>
    <p class="muted" style="margin-bottom:0">No automatic target mapping — will be skipped unless you map manually below:
      <?php foreach ($unsupported as $key): ?>
        <span class="badge"><?= View::e($key->value) ?></span>
      <?php endforeach; ?>
    </p>
  <?php endif; ?>
</div>

<form method="post" action="<?= View::e($view->url('import-run')) ?>">
  <input type="hidden" name="input" value="<?= View::e($input) ?>">

  <div class="card">
    <h2 style="margin-top:0">Entity mapping</h2>
    <p class="muted">Source entity types are mapped to the closest target entity for cross-CMS import. Override when needed.</p>
    <table>
      <tr>
        <th>Source entity</th>
        <th>Label</th>
        <th>Canonical kind</th>
        <th>Target entity (<?= View::e($targetCms) ?>)</th>
      </tr>
      <?php foreach ($entityMapping as $sourceKey => $info): ?>
        <tr>
          <td><code><?= View::e($sourceKey) ?></code></td>
          <td><?= View::e($info['label']) ?></td>
          <td class="muted"><?= View::e($info['canonical_kind']) ?></td>
          <td>
            <select name="entity_map[<?= View::e($sourceKey) ?>]">
              <option value="">— skip —</option>
              <?php foreach ($supportedTargets as $targetValue => $targetLabel): ?>
                <option value="<?= View::e($targetValue) ?>"<?= ($info['target']?->value ?? '') === $targetValue ? ' selected' : '' ?>>
                  <?= View::e($targetLabel) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </td>
        </tr>
      <?php endforeach; ?>
    </table>
  </div>

  <div class="card">
    <h2 style="margin-top:0">Field mapping</h2>
    <p class="muted">Leave a target field empty to use the adapter default. Overrides are saved to <code>import_map.json</code> after a real import.</p>

    <?php foreach ($types as $key): ?>
      <h2><?= View::e($key->value) ?></h2>
      <table>
        <tr><th style="width:30%">Canonical field</th><th style="width:15%">Type</th><th style="width:15%">Required</th><th>Target field (<?= View::e($targetCms) ?>)</th></tr>
        <?php foreach (($schemaFields[$key->value] ?? []) as $field => $def): ?>
          <tr>
            <td><code><?= View::e($field) ?></code></td>
            <td class="muted"><?= View::e($def['type'] ?? '') ?></td>
            <td><?= !empty($def['required']) ? 'yes' : '<span class="muted">no</span>' ?></td>
            <td>
              <input class="map" type="text"
                     name="map[<?= View::e($key->value) ?>][<?= View::e($field) ?>]"
                     value=""
                     placeholder="<?= View::e($defaults[$field] ?? 'adapter default') ?>">
            </td>
          </tr>
        <?php endforeach; ?>
      </table>

      <?php if (!empty($preview[$key->value])): ?>
        <details style="margin:8px 0 16px">
          <summary class="muted">Preview first <?= count($preview[$key->value]) ?> record(s)</summary>
          <pre class="log" style="max-height:240px"><?php
            foreach ($preview[$key->value] as $record) {
                echo View::e((string) json_encode($record, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)) . "\n";
            }
          ?></pre>
        </details>
      <?php endif; ?>
    <?php endforeach; ?>
  </div>

  <div class="card">
    <h2 style="margin-top:0">Options</h2>
    <label for="duplicates">Duplicate slug strategy</label>
    <select id="duplicates" name="duplicates">
      <option value="skip">skip — keep existing records</option>
      <option value="suffix">suffix — create with a numbered slug</option>
      <option value="overwrite">overwrite — replace existing records</option>
    </select>
    <div style="margin-top:8px">
      <label class="inline"><input type="checkbox" name="dry_run" value="1" checked> Dry-run (validate only, write nothing)</label>
      <label class="inline"><input type="checkbox" name="resume" value="1"> Resume from checkpoint (ignored with dry-run)</label>
    </div>
    <p class="actions"><button class="btn" type="submit">Run import</button></p>
  </div>
</form>
