<?php

/**
 * Parses the shapefile into a PHP object
 */

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

$storagepath = realpath(__DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'data');

function makeFilenames($depth, $names = ['']) {
	if ($depth === 0) {
		return array_map(function ($name) {
			return 'polygons-' . (strlen($name) === 0 ? 'all' : $name);
		}, $names);
	}

	return makeFilenames($depth - 1, array_reduce($names, function ($names, $name) {
		// Quadrants are: 0 - NW, 1 - NE, 2 - SE, 3 - SW
		foreach (['0','1','2','3'] as $quadrant) {
			$names[] = $name . $quadrant;
		}
		return $names;
	}, []));
}

function getBoundsForFiles($depth, $files = ['' => ['n' => 180, 's' => 0, 'e' => 360, 'w' => 0]]) {
	if ($depth === 0) {
		$bounds = [];

		foreach ($files as $name => $poly_bounds) {
			$poly_bounds['n'] = $poly_bounds['n'] - 90;
			$poly_bounds['s'] = $poly_bounds['s'] - 90;
			$poly_bounds['e'] = $poly_bounds['e'] - 180;
			$poly_bounds['w'] = $poly_bounds['w'] - 180;

			$bounds['polygons-' . (strlen($name) === 0 ? 'all' : $name)] = $poly_bounds;
		}

		return $bounds;
	}

	$new_files = [];

	foreach ($files as $name => $parent_bounds) {
		// Quadrants are: 0 - NW, 1 - NE, 2 - SE, 3 - SW
		foreach (['0','1','2','3'] as $quadrant) {
			$full_quad_name = $name . $quadrant;
			for ($i = 0; $i < strlen($full_quad_name); $i++) {
				$subquadrant = $full_quad_name[$i];
				switch ($subquadrant) {
					case 0:
						$bounds = [
							'n' => $parent_bounds['n'],
							's' => $parent_bounds['s'] + (($parent_bounds['n'] - $parent_bounds['s']) / 2),
							'e' => $parent_bounds['e'] - (($parent_bounds['e'] - $parent_bounds['w']) / 2),
							'w' => $parent_bounds['w'],
						];
						break;
					case 1:
						$bounds = [
							'n' => $parent_bounds['n'],
							's' => $parent_bounds['s'] + (($parent_bounds['n'] - $parent_bounds['s']) / 2),
							'e' => $parent_bounds['e'],
							'w' => $parent_bounds['w'] + (($parent_bounds['e'] - $parent_bounds['w']) / 2),
						];
						break;
					case 2:
						$bounds = [
							'n' => $parent_bounds['n'] - (($parent_bounds['n'] - $parent_bounds['s']) / 2),
							's' => $parent_bounds['s'],
							'e' => $parent_bounds['e'],
							'w' => $parent_bounds['w'] + (($parent_bounds['e'] - $parent_bounds['w']) / 2),
						];
						break;
					case 3:
						$bounds = [
							'n' => $parent_bounds['n'] - (($parent_bounds['n'] - $parent_bounds['s']) / 2),
							's' => $parent_bounds['s'],
							'e' => $parent_bounds['e'] - (($parent_bounds['e'] - $parent_bounds['w']) / 2),
							'w' => $parent_bounds['w'],
						];
						break;
				}
			}

			$new_files[$full_quad_name] = $bounds;
		}
	}

	return getBoundsForFiles($depth - 1, $new_files);
}

function polygonsOverlapWithBox($polys, $box) {
	foreach ($polys as $poly) {
		if (
			$poly->getNorth() >= $box['s'] &&
			$poly->getSouth() <= $box['n'] &&
			$poly->getEast()  >= $box['w'] &&
			$poly->getWest()  <= $box['e']
		) {
			return true;
		}
	}

	return false;
}

/**
 * Write the data to serialized object file(s) as a quadtree of the depth given
 *
 * @param  integer $depth How many times to divide into quadrants when splitting up the results
 */
function writeFiles($depth = 0) {
	global $storagepath, $timezoneNamesToPolygons;

	if ($depth === 0) {
		file_put_contents($storagepath . DIRECTORY_SEPARATOR . 'polygons', serialize($timezoneNamesToPolygons));
		return;
	}

	$files = [];
	foreach (makeFilenames($depth) as $name) {
		if (! isset($files[$name])) {
			$files[$name] = [];
		}
	}
	$file_bounds = getBoundsForFiles($depth);

	file_put_contents($storagepath . DIRECTORY_SEPARATOR . 'polygons-meta', serialize([
		'depth' => $depth,
		'file_bounds' => $file_bounds,
	]));

	foreach ($timezoneNamesToPolygons as $name => $polys) {
		foreach ($file_bounds as $file => $bounds) {
			if (polygonsOverlapWithBox($polys, $bounds)) {
				$files[$file][$name] = $polys;
			}
		}
	}

	foreach ($files as $name => $values) {
		file_put_contents($storagepath . DIRECTORY_SEPARATOR . $name, serialize($values));
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

    writeFiles(TzWhere::POLYGON_FILE_SPLIT_DEPTH);

    $time_end2 = microtime_float();
	echo "Saved in " . ($time_end2 - $time_end1) . "sec\n";
	echo "Memory usage: " . formatBytes(memory_get_usage(true)) . "\n";
	echo "Peak Memory usage: " . formatBytes(memory_get_peak_usage(true)) . "\n";
} catch (ShapeFileException $e) {
	exit('Error '.$e->getCode().' ('.$e->getErrorType().'): '.$e->getMessage());
}