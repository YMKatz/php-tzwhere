<?php

require realpath(__DIR__ . '/../vendor/autoload.php');

ini_set('memory_limit', '-1');

use ShapeFile\ShapeFile;
use ShapeFile\ShapeFileException;

use YMKatz\TzWhere\Polygon;
use YMKatz\TzWhere\TzWhere;

$timezoneNamesToPolygons = [];

$tzWorldFile = realpath(__DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'efele' . DIRECTORY_SEPARATOR . 'tz_world' . DIRECTORY_SEPARATOR . 'tz_world_mp.shp');

function microtime_float()
{
    list($usec, $sec) = explode(" ", microtime());
    return ((float)$usec + (float)$sec);
}

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


function processPolygon($part, $tzname)
{
	global $timezoneNamesToPolygons;

	if ($part['numrings'] && ! array_key_exists($tzname, $timezoneNamesToPolygons)) {
		$timezoneNamesToPolygons[$tzname] = [];
	}

	$poly = [];
	foreach ($part['rings'] as $ring) {
		fwrite(STDERR, '|');
		$r = [];
		foreach ($ring['points'] as $point) {
			$r[] = [$point['y'], $point['x']];
		}
		$poly[] = $r;
	}
	$timezoneNamesToPolygons[$tzname][] = new Polygon($poly);
}

function addFeature($feature)
{

	$tzname = $feature['dbf']['TZID'];
	$region = explode('/', $tzname)[0];

	if (! in_array($region, TzWhere::EXCLUDE_REGIONS)) {
		fwrite(STDERR, $tzname);

		foreach ($feature['shp']['parts'] as $part) {
			fwrite(STDERR, ' ');
			processPolygon($part, $tzname);
		}
	}
}

$time_start = microtime_float();
try {
	$shapefile = new ShapeFile($tzWorldFile);

	while ($record = $shapefile->getRecord(ShapeFile::GEOMETRY_ARRAY)) {
		fwrite(STDERR, '<');
		addFeature($record);
		fwrite(STDERR, ">\n");
    }

    $time_end1 = microtime_float();
	echo "Generated in " . ($time_end1 - $time_start) . "sec\n";

    file_put_contents(realpath(__DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'data') . DIRECTORY_SEPARATOR . 'polygons', serialize($timezoneNamesToPolygons));

    $time_end2 = microtime_float();
	echo "Saved in " . ($time_end2 - $time_end1) . "sec\n";
	echo "Memory usage: " . formatBytes(memory_get_usage(true)) . "\n";
	echo "Peak Memory usage: " . formatBytes(memory_get_peak_usage(true)) . "\n";
} catch (ShapeFileException $e) {
	exit('Error '.$e->getCode().' ('.$e->getErrorType().'): '.$e->getMessage());
}