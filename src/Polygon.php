<?php

namespace YMKatz\TzWhere;

class Polygon
{
    private $coordinates;

    private $north;

    private $east;

    private $south;

    private $west;

    private $hasCoordinate = false;

    public function __construct($coordinates = null, $bbox = null)
    {
        if (is_array($coordinates) || null === $coordinates) {
            $this->coordinates = [];
        } else {
            throw new \InvalidArgumentException;
        }

        if ($bbox !== null) {
            $this->west = $bbox['xmin'];
            $this->east = $bbox['xmax'];
            $this->south = $bbox['ymin'];
            $this->north = $bbox['ymax'];
        }

        if (is_array($coordinates)) {
            $this->set($coordinates, ! $bbox);
        }
    }

    public function pointInBoundingBox(array $coordinate)
    {
        return ! (
            bccomp($coordinate[0], $this->south, 8) === -1 ||
            bccomp($coordinate[0], $this->north, 8) === 1 ||
            bccomp($coordinate[1], $this->east,  8) === 1 ||
            bccomp($coordinate[1], $this->west,  8) === -1
        );
    }

    public function pointInPolygon(array $coordinate)
    {
        if (!$this->hasCoordinate) {
            return false;
        }

        if (!$this->pointInBoundingBox($coordinate)) {
            return false;
        }

        if ($this->pointOnVertex($coordinate)) {
            return true;
        }

        if ($this->pointOnBoundary($coordinate)) {
            return true;
        }

        $total_rings = $this->countRings();
        $intersections = 0;
        for ($ring = 0; $ring < $total_rings; $ring++) {
            $total = $this->countRing($ring);
            for ($i = 1; $i < $total; $i++) {
                $currentVertex = $this->get($ring, $i - 1);
                $nextVertex = $this->get($ring, $i);

                if (
                    bccomp(
                        $coordinate[0],
                        min($currentVertex[0], $nextVertex[0]),
                        8
                    ) === 1 &&
                    bccomp(
                        $coordinate[0],
                        max($currentVertex[0], $nextVertex[0]),
                        8
                    ) <= 0 &&
                    bccomp(
                        $coordinate[1],
                        max($currentVertex[1], $nextVertex[1]),
                        8
                    ) <= 0 &&
                    bccomp(
                        $currentVertex[0],
                        $nextVertex[0],
                        8
                    ) !== 0
                ) {
                    $xinters =
                        ($coordinate[0] - $currentVertex[0]) *
                        ($nextVertex[1] - $currentVertex[1]) /
                        ($nextVertex[0] - $currentVertex[0]) +
                        $currentVertex[1];

                    if (
                        bccomp(
                            $currentVertex[1],
                            $nextVertex[1],
                            8
                        ) === 0 ||
                        bccomp(
                            $coordinate[1],
                            $xinters,
                            8
                        ) <= 0
                    ) {
                        $intersections++;
                    }
                }
            }
        }

        if ($intersections % 2 != 0) {
            return true;
        }

        return false;
    }

    public function pointOnBoundary(array $coordinate)
    {
        $total_rings = $this->countRings();
        for ($ring = 0; $ring < $total_rings; $ring++) {
            $total = $this->countRing($ring);
            for ($i = 1; $i <= $total; $i++) {
                $currentVertex = $this->get($ring, $i - 1);
                $nextVertex = $this->get($ring, $i);

                if (null === $nextVertex) {
                    $nextVertex = $this->get($ring, 0);
                }

                // Check if coordinate is on a horizontal boundary
                if (
                    bccomp(
                        $currentVertex[0],
                        $nextVertex[0],
                        8
                    ) === 0 &&
                    bccomp(
                        $currentVertex[0],
                        $coordinate[0],
                        8
                    ) === 0 &&
                    bccomp(
                        $coordinate[1],
                        min($currentVertex[1], $nextVertex[1]),
                        8
                    ) === 1 &&
                    bccomp(
                        $coordinate[1],
                        max($currentVertex[1], $nextVertex[1]),
                        8
                    ) === -1
                ) {
                    return true;
                }

                // Check if coordinate is on a boundary
                if (
                    bccomp(
                        $coordinate[0],
                        min($currentVertex[0], $nextVertex[0]),
                        8
                    ) === 1 &&
                    bccomp(
                        $coordinate[0],
                        max($currentVertex[0], $nextVertex[0]),
                        8
                    ) <= 0 &&
                    bccomp(
                        $coordinate[1],
                        max($currentVertex[1], $nextVertex[1]),
                        8
                    ) <= 0 &&
                    bccomp(
                        $currentVertex[0],
                        $nextVertex[0],
                        8
                    ) !== 0
                ) {
                    $xinters =
                        ($coordinate[0] - $currentVertex[0]) *
                        ($nextVertex[1] - $currentVertex[1]) /
                        ($nextVertex[0] - $currentVertex[0]) +
                        $currentVertex[1];

                    if (bccomp($xinters, $coordinate[1], 8) === 0) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    public function pointOnVertex(array $coordinate)
    {
        foreach ($this->coordinates as $ring) {
            foreach ($ring as $vertexCoordinate) {
                if (
                    bccomp(
                        $vertexCoordinate[0],
                        $coordinate[0],
                        8
                    ) === 0 &&
                    bccomp(
                        $vertexCoordinate[1],
                        $coordinate[1],
                        8
                    ) === 0
                ) {
                    return true;
                }
            }
        }

        return false;
    }

    public function getCoordinates()
    {
        return $this->coordinates;
    }

    public function countRings()
    {
        return count($this->coordinates);
    }

    public function countRing($i)
    {
        return count($this->coordinates[$i]);
    }

    public function get($key1, $key2)
    {
        $ring = $this->coordinates[$key1];

        if ($ring && isset($ring[$key2])) {
            return $ring[$key2];
        }

        return null;
    }

    public function set($values, $calculateBbox = true)
    {
        foreach ($values as $key1 => $value1) {
            $this->coordinates[$key1] = $value1;
        }

        // Only the outer ring needs to go in the bounding box
        if ($calculateBbox) {
            foreach ($values[0] as $key => $value) {
                $this->addToBoundingBox($value);
            }
        }

    }

    private function addToBoundingBox($coordinate)
    {
        $latitude  = $coordinate[0];
        $longitude = $coordinate[1];

        if (!$this->hasCoordinate) {
            $this->hasCoordinate = true;
            $this->north = $latitude;
            $this->south = $latitude;
            $this->east = $longitude;
            $this->west = $longitude;
        } else {
            if (bccomp($latitude, $this->south, 8) === -1) {
                $this->south = $latitude;
            }
            if (bccomp($latitude, $this->north, 8) === 1) {
                $this->north = $latitude;
            }
            if (bccomp($longitude, $this->east, 8) === 1) {
                $this->east = $longitude;
            }
            if (bccomp($longitude, $this->west, 8) === -1) {
                $this->west = $longitude;
            }
        }
    }


    public function getNorth()
    {
        return $this->north;
    }

    public function getEast()
    {
        return $this->east;
    }

    public function getSouth()
    {
        return $this->south;
    }

    public function getWest()
    {
        return $this->west;
    }
}
