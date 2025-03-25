<?php

namespace MediaWiki\Extension\GLTFHandler\Parser;

use function array_column;

final class GLTFComponentType{

	public const BYTE = 5120;
	public const UNSIGNED_BYTE = 5121;
	public const SHORT = 5122;
	public const UNSIGNED_SHORT = 5123;
	public const UNSIGNED_INT = 5125;
	public const FLOAT = 5126;

	/**
	 * @return non-empty-array<int, self>
	 */
	public static function registry() : array{
		return array_column([
			new self(self::BYTE, "BYTE", 1, "c", -0x7f - 1, 0x7f),
			new self(self::UNSIGNED_BYTE, "UNSIGNED_BYTE", 1, "C", 0, 0xff),
			new self(self::SHORT, "SHORT", 2, "s", -0x7fff - 1, 0x7fff),
			new self(self::UNSIGNED_SHORT, "UNSIGNED_SHORT", 2, "v", 0, 0xffff),
			new self(self::UNSIGNED_INT, "UNSIGNED_INT", 4, "V", 0, 0xffffffff),
			new self(self::FLOAT, "FLOAT", 4, "g", -3.4028237 * (10 ** 38), 3.4028237 * (10 ** 38)),
		], null, "code");
	}

	public function __construct(
		public int $code,
		public string $name,
		public int $size,
		public string $format,
		public mixed $min,
		public mixed $max
	){}
}