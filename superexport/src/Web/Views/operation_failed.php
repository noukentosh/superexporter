<?php

/** @var SuperExport\Web\View $view */
/** @var string $message */

use SuperExport\Web\View;

?>
<div class="card">
  <h2 style="margin-top:0" class="err">Operation failed</h2>
  <p><?= View::e($message) ?></p>
  <p class="actions"><a class="btn secondary" href="<?= View::e($view->url('dashboard')) ?>">Back to dashboard</a></p>
</div>
