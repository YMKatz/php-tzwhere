<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use YMKatz\TzWhere\TzWhere;

final class TzWhereTest extends TestCase
{
	protected static $tzWhere;

	protected static $whiteHouse = ['lat' => 38.897663, 'lng' => -77.036562];
	protected static $swiftCurrentSask = ['lat' => 50.286666, 'lng' => -107.800457];
	protected static $outsideSwiftCurrentSask = ['lat' => 50.355715, 'lng' => -107.595065];
	protected static $chicagoLakefront = ['lat' => 41.906637, 'lng' => -87.624839];

	/**
     * @outputBuffering disabled
     */
	public static function setUpBeforeClass()
	{
		ini_set('memory_limit', '-1');
		self::$tzWhere = new TzWhere();
	}

	/**
     * @outputBuffering disabled
     * @group from-upstream
     * @group coverage
     */
	public function testReadmeExample()
	{
		$result = self::$tzWhere->tzNameAt(self::$whiteHouse['lat'], self::$whiteHouse['lng']);
		$this->assertEquals('America/New_York', $result);
	}

	/**
     * @outputBuffering disabled
     * @group from-upstream
     * @group coverage
     */
	public function testCoverage48N()
	{
		$results = [];
		for ($lng = -180; $lng < 180; $lng++) {
			$tzname = self::$tzWhere->tzNameAt(48, $lng);
			if (array_key_exists($tzname, $results)) {
				$results[$tzname]++;
			} else {
				$results[$tzname] = 0;
			}
		}

		$continents = [];
		foreach ($results as $tzname => $count) {
			$continent = explode('/', $tzname)[0];
			if (array_key_exists($continent, $continents)) {
				$continents[$continent]++;
			} else {
				$continents[$continent] = 0;
			}
		}

		$this->assertEquals(4, count($continents));
		$this->assertArrayHasKey('Europe', $continents);
		$this->assertArrayHasKey('Asia', $continents);
		$this->assertArrayHasKey('America', $continents);
		$this->assertArrayHasKey('', $continents);
		$this->assertTrue($continents['Europe'] + $continents['Asia'] + $continents['America'] > $continents['']);
	}

	/**
     * @outputBuffering disabled
     * @group from-upstream
     * @group coverage
     */
	public function testCoverageAustralia()
	{
		$results = [];
		for ($latitude = -30; $latitude <= -22; $latitude++) {
			for ($longitude = 117; $longitude <= 147 ; $longitude++) {
				$tzname = self::$tzWhere->tzNameAt($latitude, $longitude);
				if (array_key_exists($tzname, $results)) {
					$results[$tzname]++;
				} else {
					$results[$tzname] = 0;
				}
			}
		}

		$this->assertFalse(isset($results['']));
		foreach ($results as $tzname => $count) {
			$this->assertStringStartsWith('Australia/', $tzname);
		}
	}

	/**
	 * @group mmk
	 * @group accuracy
	 */
	public function testAccuracyHoles()
	{
		$this->assertEquals('America/Regina', self::$tzWhere->tzNameAt(self::$outsideSwiftCurrentSask['lat'], self::$outsideSwiftCurrentSask['lng']));
		$this->assertEquals('America/Swift_Current', self::$tzWhere->tzNameAt(self::$swiftCurrentSask['lat'], self::$swiftCurrentSask['lng']));
	}

	/**
	 * @group mmk
	 * @group accuracy
	 */
	public function testAccuracySeaside()
	{
		$this->assertEquals('America/Chicago', self::$tzWhere->tzNameAt(self::$chicagoLakefront['lat'], self::$chicagoLakefront['lng']));
	}
}