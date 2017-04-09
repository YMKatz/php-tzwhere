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


	private $tzFilename = null;
	private $timezoneNamesToPolygons = null;

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

	public function getTimezonePolygon($tzname, $polyindex)
	{
		$polygons = $this->getOrLoadTimezonePolygons(false);

		return $polygons[$tzname][$polyindex];
	}

	public function getOrLoadTimezonePolygons($autobuild_shortcuts = true)
	{
		if ($this->timezoneNamesToPolygons === null) {
			$this->timezoneNamesToPolygons = unserialize(file_get_contents($this->tzFilename));
			// If the raw data is loaded, make sure the shortcuts are loaded too unless it would cause a loop
			if ($autobuild_shortcuts) {
				$this->loadOrBuildShortcuts();
			}
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
						$poly = $this->getTimezonePolygon($tzname, $polyIndex);
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