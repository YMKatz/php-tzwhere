<?php

/**
 * Builds the shortcut files
 */

require realpath(__DIR__ . '/../vendor/autoload.php');

ini_set('memory_limit', '-1');

use YMKatz\TzWhere\TzWhere;

function formatBytes($bytes, $precision = 2) {
    $units = array('B', 'KB', 'MB', 'GB', 'TB');

    $bytes = max($bytes, 0);
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow = min($pow, count($units) - 1);

    // Uncomment one of the following alternatives
    $bytes /= pow(1024, $pow);
    // $bytes /= (1 << (10 * $pow));

    return round($bytes, $precision) . ' ' . $units[$pow];
}

function microtime_float()
{
    list($usec, $sec) = explode(" ", microtime());
    return ((float)$usec + (float)$sec);
}

$time_start = microtime_float();
try {
    $tz = new TzWhere();

    $shortcuts = $tz->buildShortcuts();

    file_put_contents(realpath(__DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'data') . DIRECTORY_SEPARATOR . 'polygons-shortcuts', serialize($shortcuts));

    $time_end1 = microtime_float();
	echo "Processed in " . ($time_end1 - $time_start) . "sec\n";
	echo "Memory usage: " . formatBytes(memory_get_usage(true)) . "\n";
	echo "Peak Memory usage: " . formatBytes(memory_get_peak_usage(true)) . "\n";
} catch (Exception $e) {
	exit('Error '.$e->getCode().' ('.$e->getErrorType().'): '.$e->getMessage());
}