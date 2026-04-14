<?php
$ok = [
 'PHP >= 7.2'      => version_compare(PHP_VERSION, '7.2.0', '>='),
 'ext-gd'          => extension_loaded('gd'),
 'ext-mbstring'    => extension_loaded('mbstring'),
 'ext-xml'         => extension_loaded('xml'),
 'ext-curl'        => extension_loaded('curl'),
 'ext-zip'         => extension_loaded('zip'),
 'vendor/autoload' => file_exists(__DIR__.'/vendor/autoload.php') && @include __DIR__.'/vendor/autoload.php'
];

header('Content-Type: text/plain; charset=utf-8');
foreach ($ok as $k=>$v) echo sprintf("[%s] %s\n", $v ? 'OK' : 'FAIL', $k);

$paths = [
 'fonts cache' => __DIR__.'/vendor/dompdf/dompdf/lib/fonts',
 'logs dir'    => __DIR__.'/logs',
 'cookies.txt' => __DIR__.'/cookies.txt',
];
echo "\nWritable checks:\n";
foreach ($paths as $k=>$p) {
  if (!file_exists($p)) { echo "MISS: $k ($p)\n"; continue; }
  echo (is_writable($p) ? "OK   " : "FAIL ").": $k ($p)\n";
}
