<?php

/** @var SuperExport\Web\View $view */
/** @var string $message */

use SuperExport\Web\View;

?>
<h1 class="err">Error</h1>
<div class="card">
  <p><?= View::e($message) ?></p>
  <p class="actions"><a class="btn secondary" href="<?= View::e($view->url('dashboard')) ?>">Back to dashboard</a></p>
</div>
