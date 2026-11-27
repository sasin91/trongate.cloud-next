<?php
// Load our app helpers
require_once dirname(__DIR__) . '/helpers.php';

require_once dirname(__DIR__) . '/engine/ignition.php';

// Display nice error pages for uncaught exceptions and fatal errors
Error_pages::_register();

//Init Core Library
$init = new Core;