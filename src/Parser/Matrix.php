<?php

namespace MediaWiki\Extension\GLTFHandler\Parser;

use function array_fill;

final class Matrix{

	public const IDENTITY4 = [
		1, 0, 0, 0,
		0, 1, 0, 0,
		0, 0, 1, 0,
		0, 0, 0, 1
	];

	public static function multiply(array $matrix1, array $matrix2, int $size) : array{
		$result = array_fill(0, $size * $size, 0);
		for($row = 0; $row < $size; $row++){
			for($col = 0; $col < $size; $col++){
				for($k = 0; $k < $size; $k++){
					$result[$row * $size + $col] += $matrix1[$row * $size + $k] * $matrix2[$k * $size + $col];
				}
			}
		}
		return $result;
	}

	public static function transpose(array $matrix, int $size = 4) : array{
		$result = [];
		for($i = 0; $i < $size; $i++){
			for($j = 0; $j < $size; $j++){
				$result[$i * $size + $j] = $matrix[$j * $size + $i];
			}
		}
		return $result;
	}

	public static function multiplyVector(array $matrix, array $vector, int $size) : array{
		$result = array_fill(0, $size, 0);
		for($row = 0; $row < $size; $row++){
			for($k = 0; $k < $size; $k++){
				$result[$row] += $matrix[$row * $size + $k] * $vector[$k];
			}
		}
		return $result;
	}

	public static function quaternionToRotation(array $matrix) : array{
		[$x, $y, $z, $w] = $matrix;
		return [
			1 - 2 * ($y * $y + $z * $z), 2 * ($x * $y - $z * $w),     2 * ($x * $z + $y * $w),     0,
			2 * ($x * $y + $z * $w),     1 - 2 * ($x * $x + $z * $z), 2 * ($y * $z - $x * $w),     0,
			2 * ($x * $z - $y * $w),     2 * ($y * $z + $x * $w),     1 - 2 * ($x * $x + $y * $y), 0,
			0,                           0,                           0,                           1
		];
	}

	private function __construct(){
	}
}