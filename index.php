<?php
// display_errors(1);
// ini_set('display_startup_errors', '1');
// error_reporting(E_ALL);
// echo "Hello edeWorld";
// echo __DIR__;
// exit;

declare(strict_types=1);

/**
 * Apache DirectoryIndex entry when visiting /pro_enroll_api/
 * All /v1/* routes are handled by .htaccess → public/index.php
 */
require __DIR__ . '/public/index.php';
?>