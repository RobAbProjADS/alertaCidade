<?php
declare(strict_types=1);

require dirname(__DIR__) . '/database.php';

unset(
    $_SESSION['is_manager'],
    $_SESSION['manager_id'],
    $_SESSION['manager_username'],
    $_SESSION['manager_name']
);
session_regenerate_id(true);

redirect('index.php');
