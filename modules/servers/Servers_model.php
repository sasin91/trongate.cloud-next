<?php
require_once dirname(__DIR__).'/db/traits/Database_access.php';
require_once dirname(__DIR__).'/operations/traits/Inventory_records.php';
require_once dirname(__DIR__).'/operations/traits/Encrypted_credentials.php';
require_once dirname(__DIR__).'/operations/traits/Operation_queue.php';
class Servers_model extends Model {
    use Database_access, Inventory_records, Encrypted_credentials, Operation_queue;
}
