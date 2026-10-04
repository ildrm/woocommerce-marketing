<?php
// Local integration server only; excluded from release packages.
$root=dirname(__DIR__) . '/.runtime/wordpress';
$path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);
if(is_string($path) && $path!=='/' && is_file($root . $path)) { return false; }
require $root . '/index.php';
