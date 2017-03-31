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
	// TODO cache this with file and read from cached file.
	const SHORTCUT_DEGREES_LATITUDE = 1;
	const SHORTCUT_DEGREES_LONGITUDE = 1;
	// Maybe you only care about one region of the earth.  Exclude "America" to
	// discard timezones that start with "America/", such as "America/Los Angeles"
	// and "America/Chicago", etc.
	// TODO Make this user-settable
	const EXCLUDE_REGIONS = [];


	private $timezoneNamesToPolygons = [];
	private $timezoneLongitudeShortcuts = [];
	private $timezoneLatitudeShortcuts = [];

	// Don't forget to run this through `realpath`
	private $constructedShortcutFilePathBase = __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'tmp';

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

		$this->timezoneNamesToPolygons = unserialize(file_get_contents($tzWorldFile));
		$this->finishProcessing();
	}

	protected function finishProcessing()
	{
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
	}

	/*
	protected function constructShortcuts($featureCollection)
	{
		// TODO check for cache

		$this->timezoneNamesToPolygons = [];

		foreach ($featureCollection->features as $feature) {
			$tzname = $feature->properties->TZID;
			$region = explode('/', $tzname)[0];

			if (! in_array($region, self::EXCLUDE_REGIONS)) {
				// We can only process polygons
				if ($feature->geometry->type === 'Polygon') {
					$polys = $feature->geometry->coordinates;

					if (count($polys) && ! in_array($tzname, $this->timezoneNamesToPolygons)) {
						$this->timezoneNamesToPolygons[$tzname] = [];
					}

					foreach ($polys as $poly_data) {
						// WPS84 coordinates are [long, lat], while many conventions are [lat, long]
						// Our data is in WPS84.  Convert to an explicit format which Geotools likes.
						$poly = new Polygon();
						foreach ($poly_data as $point) {
							$poly->add(new Coordinate([$point[1], $point[0]]));
						}
						$this->timezoneNamesToPolygons[$tzname][] = $poly_flipped;
					}
				}
			}
		}

		$this->timezoneLongitudeShortcuts = [];
		$this->timezoneLatitudeShortcuts = [];

		foreach ($this->timezoneNamesToPolygons as $tzname => $polygons) {
			foreach ($polygons as $polyindex => $poly) {
				$bbox = $poly->getBoundingBox();
				$minLng = floor($bbox->getWest() / self::SHORTCUT_DEGREES_LONGITUDE) * self::SHORTCUT_DEGREES_LONGITUDE;
				$maxLng = floor($bbox->getEast() / self::SHORTCUT_DEGREES_LONGITUDE) * self::SHORTCUT_DEGREES_LONGITUDE;
				$minLat = floor($bbox->getSouth() / self::SHORTCUT_DEGREES_LATITUDE) * self::SHORTCUT_DEGREES_LATITUDE;
				$maxLat = floor($bbox->getNorth() / self::SHORTCUT_DEGREES_LATITUDE) * self::SHORTCUT_DEGREES_LATITUDE;

				for ($degree = $minLng; $degree <= $maxLng; $degree += self::SHORTCUT_DEGREES_LONGITUDE) {
					if (! in_array($degree, $this->timezoneLongitudeShortcuts)) {
						$this->timezoneLongitudeShortcuts[$degree] = [];
					}
					if (! in_array($tzname, $this->timezoneLongitudeShortcuts[$degree])) {
						$this->timezoneLongitudeShortcuts[$degree][$tzname] = [];
					}
					$this->timezoneLongitudeShortcuts[$degree][$tzname].push($polyIndex);
				}

				for ($degree = $minLat; $degree <= $maxLat; $degree += self::SHORTCUT_DEGREES_LATITUDE) {
					if (! in_array($degree, $this->timezoneLatitudeShortcuts)) {
						$this->timezoneLatitudeShortcuts[$degree] = [];
					}
					if (! in_array($tzname, $this->timezoneLatitudeShortcuts[$degree])) {
						$this->timezoneLatitudeShortcuts[$degree][$tzname] = [];
					}
					$this->timezoneLatitudeShortcuts[$degree][$tzname].push($polyIndex);
				}

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
				$toFind = [$lat, $lng];
				foreach ($possibleTimezones as $tzname) {
					$polyIndices = array_intersect($latTzOptions[$tzname], $lngTzOptions[$tzname]);
					foreach ($polyIndices as $polyIndex) {
						$poly = $this->timezoneNamesToPolygons[$tzname][$polyIndex];
						$found = $poly->pointInPolygon($toFind);
						if ($found) {
							return $tzname;
						}
					}
				}
		}

		// Note that we will only get here if there were options based on the shortcut
		// table but they all were wrong.
		return null;
	}
}