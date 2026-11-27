<?php
// Load our app helpers
require_once '../helpers.php';

require_once '../engine/ignition.php';

// Display nice error pages for uncaught exceptions and fatal errors
Error_pages::_register();

//Init Core Library
$init = new Core;