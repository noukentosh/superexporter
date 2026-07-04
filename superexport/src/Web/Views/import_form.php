<?php

/** @var SuperExport\Web\View $view */
/** @var string $defaultInput */

use SuperExport\Web\View;

?>
<h1>Import — step 1 of 3</h1>
<div class="card">
  <p class="muted">Point to a storage directory containing <code>manifest.json</code> and <code>entities/</code> produced by a SuperExport export (from this or another CMS).</p>
  <form method="post" action="<?= View::e($view->url('import-mapping')) ?>">
    <label for="input">Storage directory</label>
    <input type="text" id="input" name="input" value="<?= View::e($defaultInput) ?>">
    <p class="actions"><button class="btn" type="submit">Continue to mapping</button></p>
  </form>
</div>
