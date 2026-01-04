<?php
// If the requested file exists (image/css/js), serve it normally
$path = parse_url($_SERVER["REQUEST_URI"], PHP_URL_PATH);
$file = __DIR__ . $path;

if ($path !== "/" && file_exists($file) && !is_dir($file)) {
  return false; // serve the file
}

// Otherwise, send everything to index.php (our API router)
require __DIR__ . "/index.php";
