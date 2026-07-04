<?php

/** @var SuperExport\Web\View $view */
/** @var string $defaultOutput */

use SuperExport\Web\View;

?>
<h1>Export</h1>
<div class="card">
  <p class="muted">Reads content from the detected CMS and writes universal JSON chunks plus <code>manifest.json</code>. Progress is streamed live.</p>
  <form method="post" action="<?= View::e($view->url('export-run')) ?>">
    <label for="output">Output directory</label>
    <input type="text" id="output" name="output" value="<?= View::e($defaultOutput) ?>">
    <p class="actions"><button class="btn" type="submit">Start export</button></p>
  </form>
</div>
