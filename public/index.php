<?php
// Load our app helpers
require_once dirname(__DIR__) . '/helpers.php';

require_once dirname(__DIR__) . '/engine/ignition.php';

// Display nice error pages for uncaught exceptions and fatal errors
Error_pages::_register();

// The tc_registry jail serves only the public Module Harbour routes
require_once APPPATH . 'modules/harbour/services/Harbour_public.php';
Harbour_public::enforce();

//Init Core Library
$init = new Core;
