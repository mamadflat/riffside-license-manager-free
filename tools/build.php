<?php

if (PHP_SAPI !== 'cli') exit;

$root = dirname(__DIR__);
$output = $argv[1] ?? $root . '/dist';
$main = 'riffside-license-manager.php';
if (!preg_match('/^\h*\* Version:\h*(\d+\.\d+\.\d+)\h*\r?$/m', file_get_contents($root . '/' . $main), $match)) {
    throw new RuntimeException('Missing or invalid plugin version.');
}
$files = [
    $main,
    'uninstall.php',
    'readme.txt',
    'LICENSE.txt',
    'includes/Plugin.php',
    'prepaid/assets/customer.css',
    'prepaid/assets/customer.js',
    'prepaid/includes/class-admin.php',
    'prepaid/includes/class-crypto.php',
    'prepaid/includes/class-customer.php',
    'prepaid/includes/class-import.php',
    'prepaid/includes/class-orders.php',
    'prepaid/includes/class-plugin.php',
    'prepaid/includes/class-store.php',
];
foreach ($files as $file) {
    $path = $root . '/' . $file;
    if (!is_file($path) || is_link($path)) {
        throw new RuntimeException('Missing or unsafe source path ' . $file);
    }
}
if (!is_dir($output) && !mkdir($output, 0777, true) && !is_dir($output)) {
    throw new RuntimeException('Could not create the output directory.');
}

$name = 'riffside-license-manager-free-' . $match[1] . '.zip';
$archive = $output . '/' . $name;
$zip = new ZipArchive();
if ($zip->open($archive, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    throw new RuntimeException('Could not open the release archive.');
}
foreach ($files as $file) {
    if (!$zip->addFile($root . '/' . $file, 'riffside-license-manager/' . $file)) {
        throw new RuntimeException('Could not package ' . $file);
    }
}
if (!$zip->close() || $zip->open($archive, ZipArchive::CHECKCONS) !== true || $zip->numFiles !== count($files)) {
    throw new RuntimeException('The release archive could not be verified.');
}
$zip->close();

$digest = hash_file('sha256', $archive);
if ($digest === false || file_put_contents($archive . '.sha256', $digest . '  ' . $name . "\n") === false) {
    throw new RuntimeException('Could not write the release checksum.');
}
echo $archive . PHP_EOL . $digest . PHP_EOL;
