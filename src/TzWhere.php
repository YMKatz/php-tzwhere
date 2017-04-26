<?php

namespace YMKatz\TzWhere;

class TzWhere
{

	// The "shortcut" iterates the timezone polygons when they are read in, and
	// determines the minimum/maximum longitude of each.  Because there are what
	// we professionals refer to as "a ****load" of polygons, and because the
	// naive method I use for determining which timezone contains a given point
	// could in the worst case require calculations on the order of O(****load),
	// I take advantage of the fact that my particular dataset clusters very
	// heavily by degrees longitude.
	const SHORTCUT_DEGREES_LATITUDE = 1;
	const SHORTCUT_DEGREES_LONGITUDE = 1;
	const POLYGON_FILE_SPLIT_DEPTH = 2;
	const CACHE_PARTIALS_OFF = 0;
	const CACHE_PARTIALS_FULLY = 1;
	const CACHE_PARTIALS_PER_RUN = 2;
	const CACHE_PARTIALS_PER_RUN_LOTTERY = 3;
	const CACHE_PARTIALS = self::CACHE_PARTIALS_PER_RUN_LOTTERY;
	// Maybe you only care about one region of the earth.  Exclude "America" to
	// discard timezones that start with "America/", such as "America/Los Angeles"
	// and "America/Chicago", etc.
	// TODO Make this user-settable
	const EXCLUDE_REGIONS = ['uninhabited'];


	private $tzFilename = null;
	private $timezoneNamesToPolygons = [];
	private $fully_loaded = false;
	private $partials_loaded = [];

	private $shortcutsFilename;
	private $timezoneLongitudeShortcuts = null;
	private $timezoneLatitudeShortcuts = null;

	public function __construct($base_path = null, $tzWorldFile = null)
	{
		if ($tzWorldFile === null) {
			if ($base_path === null) {
				$base_path = __DIR__;
			}
			$dataDir = $base_path . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'data';
			$tzWorldFile = $dataDir . DIRECTORY_SEPARATOR . 'polygons';
			$tzWorldFileZip = $tzWorldFile . '.zip';

			// Check for zipped version and unzip it
			if (! realpath($tzWorldFile) && realpath($tzWorldFileZip)) {
				$zip = new \ZipArchive;
				if ($zip->open(realpath($tzWorldFile . '.zip')) === true) {
					$zip->extractTo(realpath($dataDir));
					$zip->close();
				}
			}
		}

		// TODO check for cache

		$this->tzFilename = $tzWorldFile;
		$this->shortcutsFilename = $tzWorldFile . '-shortcuts';
	}

	public function getTimezonePolygon($tzname, $polyindex, $point)
	{
		$polygons = $this->getSubsetOfPolygonsForPoint($point);
		return $polygons[$tzname][$polyindex];
	}

	public function getPolygonFilenames()
	{
		return $this->makePolygonFilenames(self::POLYGON_FILE_SPLIT_DEPTH);
	}

	protected function makePolygonFilenames($depth, $names = [''])
	{
		if ($depth === 0) {
			return array_map(function ($name) {
				return $this->tzFilename . '-' . (strlen($name) === 0 ? 'all' : $name);
			}, $names);
		}

		return $this->makePolygonFilenames($depth - 1, array_reduce($names, function ($names, $name) {
			// Quadrants are: 0 - NW, 1 - NE, 2 - SE, 3 - SW
			foreach (['0','1','2','3'] as $quadrant) {
				$names[] = $name . $quadrant;
			}
			return $names;
		}, []));
	}

	public function getOrLoadTimezonePolygons($autobuild_shortcuts = true)
	{
		if (!$this->fully_loaded) {
			foreach ($this->getPolygonFilenames() as $file) {
				if (!isset($this->partials_loaded[$file])) {
					$zones = unserialize(file_get_contents($file));
					$this->timezoneNamesToPolygons = array_merge($this->timezoneNamesToPolygons, $zones);
					$this->partials_loaded[$file] = true;
				}
			}
			// If the raw data is loaded, make sure the shortcuts are loaded too unless it would cause a loop
			if ($autobuild_shortcuts) {
				$this->loadOrBuildShortcuts();
			}

			$this->fully_loaded = true;
		}

		return $this->timezoneNamesToPolygons;
	}

	protected function loadOrBuildShortcuts()
	{
		if (is_array($this->timezoneLatitudeShortcuts) && is_array($this->timezoneLongitudeShortcuts)) {
			return;
		}

		try {
			if (realpath($this->shortcutsFilename)) {
				list($this->timezoneLatitudeShortcuts, $this->timezoneLongitudeShortcuts) =
					unserialize(file_get_contents($this->shortcutsFilename));
			}
		} catch (\Exception $e) {
			// If we couldn't load the data, rebuild it.
			$this->buildShortcuts();
		}
	}

	public function buildShortcuts()
	{
		$this->getOrLoadTimezonePolygons(false);

		$this->timezoneLatitudeShortcuts = [];
		$this->timezoneLongitudeShortcuts = [];

		foreach ($this->timezoneNamesToPolygons as $tzname => $polygons) {
			foreach ($polygons as $polyIndex => $poly) {
				$minLng = floor($poly->getWest() / self::SHORTCUT_DEGREES_LONGITUDE) * self::SHORTCUT_DEGREES_LONGITUDE;
				$maxLng = floor($poly->getEast() / self::SHORTCUT_DEGREES_LONGITUDE) * self::SHORTCUT_DEGREES_LONGITUDE;
				$minLat = floor($poly->getSouth() / self::SHORTCUT_DEGREES_LATITUDE) * self::SHORTCUT_DEGREES_LATITUDE;
				$maxLat = floor($poly->getNorth() / self::SHORTCUT_DEGREES_LATITUDE) * self::SHORTCUT_DEGREES_LATITUDE;

				for ($d = $minLng; $d <= $maxLng; $d += self::SHORTCUT_DEGREES_LONGITUDE) {
					$degree = (string) $d;
					if (! array_key_exists($degree, $this->timezoneLongitudeShortcuts)) {
						$this->timezoneLongitudeShortcuts[$degree] = [];
					}
					if (! array_key_exists($tzname, $this->timezoneLongitudeShortcuts[$degree])) {
						$this->timezoneLongitudeShortcuts[$degree][$tzname] = [];
					}
					$this->timezoneLongitudeShortcuts[$degree][$tzname][] = $polyIndex;
				}

				for ($d = $minLat; $d <= $maxLat; $d += self::SHORTCUT_DEGREES_LATITUDE) {
					$degree = (string) $d;
					if (! array_key_exists($degree, $this->timezoneLatitudeShortcuts)) {
						$this->timezoneLatitudeShortcuts[$degree] = [];
					}
					if (! array_key_exists($tzname, $this->timezoneLatitudeShortcuts[$degree])) {
						$this->timezoneLatitudeShortcuts[$degree][$tzname] = [];
					}
					$this->timezoneLatitudeShortcuts[$degree][$tzname][] = $polyIndex;
				}
			}
		}

		return [$this->timezoneLatitudeShortcuts, $this->timezoneLongitudeShortcuts];
	}

	public function getSubsetOfPolygonsForPoint($point)
	{
		$bounds = ['n' => 180, 's' => 0, 'e' => 360, 'w' => 0];
		$point['lat'] = $point['lat'] + 90;
		$point['lng'] = $point['lng'] + 180;
		$file = $this->tzFilename . '-';

		if (self::POLYGON_FILE_SPLIT_DEPTH === 0) {
			$file .= 'all';
		} else {
			for ($i = 0; $i < self::POLYGON_FILE_SPLIT_DEPTH; $i++) {
				$mid_lat = $bounds['s'] + (($bounds['n'] - $bounds['s']) / 2);
				$mid_lng = $bounds['w'] + (($bounds['e'] - $bounds['w']) / 2);
				// Quadrants are: 0 - NW, 1 - NE, 2 - SE, 3 - SW
				$number = 0;

				if ($point['lat'] < $mid_lat) {
					$bounds['n'] = $mid_lat;
					$number = 2; // South = 2 or 3
				} else {
					$bounds['s'] = $mid_lat;
				}

				if ($point['lng'] < $mid_lng) {
					$bounds['e'] = $mid_lng;
					// If north stay at 0 for west, if south go to 3 for west
					$number = ($number === 0 ? 0 : 3);
				} else {
					$bounds['w'] = $mid_lng;
					// If north go to 1 for east, if south go to 2 for east
					$number = ($number === 0 ? 1 : 2);
				}

				// Append the quadrant number to the filename
				$file .= $number;
			}
		}

		// If we are already in memory, then skip loading again.
		if (! $this->fully_loaded && ! isset($this->partials_loaded[$file])) {
			$zones = unserialize(file_get_contents($file));

			if (self::CACHE_PARTIALS) {
				$this->timezoneNamesToPolygons = array_merge($this->timezoneNamesToPolygons, $zones);
				$this->partials_loaded[$file] = true;
				return $this->timezoneNamesToPolygons;
			}

			return $zones;
		}

		return $this->timezoneNamesToPolygons;
	}

	protected function clearCachedPartialsFromRun()
	{
		if (self::CACHE_PARTIALS === self::CACHE_PARTIALS_PER_RUN) {
			$this->timezoneNamesToPolygons = [];
			$this->partials_loaded = [];
		} elseif (self::CACHE_PARTIALS === self::CACHE_PARTIALS_PER_RUN_LOTTERY) {
			$rand = mt_rand(0, 10);
			// 20% of the time we clear partials
			if ($rand < 2) {
				$this->timezoneNamesToPolygons = [];
				$this->partials_loaded = [];
			}
		}
	}

	/*
	protected function constructShortcuts($featureCollection)
	{

				// As we're painstakingly constructing the shortcut table, let's write
				// it to cache so that future generations will be saved the ten
				// seconds of agony, and more importantly, the huge memory consumption.

				/* TODO What does this do for us?
				$polyTranslationsForReduce = [];
				$reducedShortcutData = [
					'lat' => [
						'degree' => SHORTCUT_DEGREES_LATITUDE,
					],
					'lng' => [
						'degree' => SHORTCUT_DEGREES_LONGITUDE,
					],
					'polys' => [],
				];
				$avgTzPerShortcut = 0;

				for (var lngDeg in timezoneLongitudeShortcuts) {
					for (var latDeg in timezoneLatitudeShortcuts) {
						var lngSet = new sets.Set(Object.keys(timezoneLongitudeShortcuts[lngDeg]));
						var latSet = new sets.Set(Object.keys(timezoneLatitudeShortcuts[latDeg]));
						var applicableTimezones = lngSet.intersection(latSet).array();
						if (applicableTimezones.length > 1) {
							// We need these polys
							for (var tzindex in applicableTimezones) {
								var tzname = applicableTimezones[tzindex];
								var latPolys = timezoneLatitudeShortcuts[latDeg][tzname];
								var lngPolys = timezoneLongitudeShortcuts[lngDeg][tzname];
							}
						}
						avgTzPerShortcut += applicableTimezones.length;
					}
				}
				avgTzPerShortcut /= (Object.keys(timezoneLongitudeShortcuts).length * Object.keys(timezoneLatitudeShortcuts).length);

				console.log(Date.now() - now + 'ms to construct shortcut table');
				console.log('Average timezones per ' + SHORTCUT_DEGREES_LATITUDE + '° lat x ' + SHORTCUT_DEGREES_LONGITUDE + '° lng: ' + avgTzPerShortcut);
				* /
			}
		}
	}
	*/

	public function tzNameAt($lat, $lng)
	{
		$this->loadOrBuildShortcuts();

		$latTzOptions = $this->timezoneLatitudeShortcuts[(string) floor($lat / self::SHORTCUT_DEGREES_LATITUDE) * self::SHORTCUT_DEGREES_LATITUDE];
		$latSet = array_keys($latTzOptions);
		$lngTzOptions = $this->timezoneLongitudeShortcuts[(string) floor($lng / self::SHORTCUT_DEGREES_LONGITUDE) * self::SHORTCUT_DEGREES_LONGITUDE];
		$lngSet = array_keys($lngTzOptions);
		$possibleTimezones = array_intersect($latSet, $lngSet);

		switch (count($possibleTimezones)) {
			case 0:
				return null;
			case 1:
				return array_values($possibleTimezones)[0];
			default:
				// Now we need to load the full data
				$toFind = [$lat, $lng];
				foreach ($possibleTimezones as $tzname) {
					// TODO: Does this actually work properly?
					$polyIndices = array_intersect($latTzOptions[$tzname], $lngTzOptions[$tzname]);
					foreach ($polyIndices as $polyIndex) {
						$poly = $this->getTimezonePolygon($tzname, $polyIndex, ['lat' => $lat, 'lng' => $lng]);
						$found = $poly->pointInPolygon($toFind);
						if ($found) {
							$this->clearCachedPartialsFromRun();
							return $tzname;
						}
					}
				}
		}

		$this->clearCachedPartialsFromRun();

		// Note that we will only get here if there were options based on the shortcut
		// table but they all were wrong.
		return null;
	}
}