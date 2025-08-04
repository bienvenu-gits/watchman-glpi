<?php
use GlpiPlugin\Watchman\MyObject;
include ("../../../inc/includes.php");

// Check if plugin is activated...
$plugin = new Plugin();
if (!$plugin->isInstalled('watchman') || !$plugin->isActivated('watchman')) {
   Html::displayNotFoundError();
}


//check for ACLs
if (MyObject::canView()) {
   //View is granted: display the list.

   //Add page header
   Html::header(
      __('My example plugin', 'watchman'),
      $_SERVER['PHP_SELF'],
      'assets',
      MyObject::class,
      'myobject'
   );

   Search::show(MyObject::class);

   Html::footer();
} else {
echo '<div class="plugin-watchman-myobject">Hello, this is my object page!</div>';

   //View is not granted.
   Html::displayRightError();
}