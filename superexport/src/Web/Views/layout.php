<?php

/** @var SuperExport\Web\View $view */
/** @var string $title */
/** @var string $content */

echo $view->renderPartial('header', ['title' => $title]);
echo $content;
echo $view->renderPartial('footer');
