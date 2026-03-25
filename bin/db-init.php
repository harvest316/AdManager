#!/usr/bin/env php
<?php

require_once __DIR__ . '/../vendor/autoload.php';

use AdManager\DB;

echo "Initialising database...\n";
DB::init();
echo "Done. Database at: " . (getenv('ADMANAGER_DB_PATH') ?: dirname(__DIR__) . '/db/admanager.db') . "\n";
