<?php
declare(strict_types=1);
use NoteMy\Store\Database; use NoteMy\Store\NoteStore; use NoteMy\Store\StatsStore;
require __DIR__.'/../src/Store/Database.php'; require __DIR__.'/../src/Store/StatsStore.php'; require __DIR__.'/../src/Store/NoteStore.php';
$c = require __DIR__.'/../config/config.php';
$pdo = Database::connect($c['db']);
echo (new NoteStore($pdo, new StatsStore($pdo)))->create(rtrim(strtr(base64_encode(random_bytes(256)),'+/','-_'),'='), '1h');
