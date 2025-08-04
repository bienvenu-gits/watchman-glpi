<?php

use GlpiPlugin\Watchman\Superasset;
use Search;
use Html;

include ('../../../inc/includes.php');

Html::header(
    Superasset::getTypeName(),
    $_SERVER['PHP_SELF'],
    "plugins",
    Superasset::class,
    "superasset"
);
Search::show(Superasset::class);
Html::footer();