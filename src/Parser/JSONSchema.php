<?php

namespace MediaWiki\Extension\GLTFHandler\Parser;

use InvalidArgumentException;
use function array_diff_key;
use function array_keys;
use function array_slice;
use function count;
use function gettype;
use function implode;
use function is_array;
use function is_float;
use function is_int;

final class JSONSchema{

	/** @var int all keys are optional - do not bail if key does not exist */
	public const FLAG_OPTIONAL = 1 << 0;
	/** @var int bail upon encountering a key that is not defined in schema */
	public const FLAG_REPORT_UNKNOWN_KEYS = 1 << 1;
	/** @var int do not nest - only validate the parent level */
	public const FLAG_NO_NESTING = 1 << 2;

	/**
	 * @param mixed $json a parsed json output
	 * @param array $schema a schema detailing structure and type
	 * @param list<string|int> $base base directory to move to in $json
	 * @param int-mask-of<self::FLAG_*> $flags
	 * @param int $code error code to use when an error encounters
	 */
	public static function validate(mixed $json, array $schema, array $base = [], int $flags = 0, int $code = GLTFParser::ERR_INVALID_SCHEMA) : void{
		$optional = ($flags & self::FLAG_OPTIONAL) > 0;
		$report_unknown_k = ($flags & self::FLAG_REPORT_UNKNOWN_KEYS) > 0;
		$nesting = ($flags & self::FLAG_NO_NESTING) === 0;

		foreach($base as $offset => $key){
			is_array($json) || throw new InvalidArgumentException("Directory /" . implode("/", array_slice($base, 0, $offset + 1)) . " must be an array", $code);
			isset($json[$key]) || throw new InvalidArgumentException("Directory /" . implode("/", array_slice($base, 0, $offset + 1)) . " must be set", $code);
			$json = $json[$key];
		}

		$buffer = [];
		foreach($schema as $k => $v){
			$buffer[] = [[$k], $v, 0, $base];
		}
		$index = 0;
		while(isset($buffer[$index])){
			[$keys, $expect, $depth, $directory] = $buffer[$index++];
			$sub_schema = $schema;
			$value = $json;
			$directory_cur = $directory;
			foreach($keys as $key){
				$directory_cur[] = $key;
				is_array($value) || throw new InvalidArgumentException("Directory /" . implode("/", $directory_cur) . " must be an array", $code);
				if($report_unknown_k && is_array($sub_schema)){
					$unknown_k = array_keys(array_diff_key($value, $sub_schema));
					count($unknown_k) === 0 || throw new InvalidArgumentException("Unknown sub-directory encountered in /" . implode("/", $directory_cur) . ": [" . implode(", ", array_slice($unknown_k, 0, 8)) . "], expected one of: [" . implode(", ", array_keys($sub_schema)) . "]", $code);
				}
				if($optional && !isset($value[$key])){
					continue 2;
				}
				isset($value[$key]) || throw new InvalidArgumentException("Directory /" . implode("/", $directory_cur) . " must be set", $code);
				$value = $value[$key];
				$sub_schema = $sub_schema[$key];
			}
			gettype($value) === gettype($expect) || (is_float($expect) && is_int($value)) || throw new InvalidArgumentException("Expected type of value at /" . implode("/", $directory_cur) . " to be " . gettype($expect) . ", got " . gettype($value), $code);
			if(is_array($expect) && $nesting){
				foreach($expect as $k => $v){
					$keys2 = $keys;
					$keys2[] = $k;
					$directory_next = $directory;
					$directory_next[] = $k;
					$buffer[] = [$keys2, $v, $depth + 1, $directory_next];
				}
			}
		}
	}
}