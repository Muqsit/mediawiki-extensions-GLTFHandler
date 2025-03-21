<?php

namespace MediaWiki\Extension\GLTFHandler;

use InvalidArgumentException;
use JsonException;
use function array_column;
use function array_diff_key;
use function array_fill;
use function array_keys;
use function array_push;
use function array_slice;
use function bin2hex;
use function count;
use function fclose;
use function file_get_contents;
use function fopen;
use function fread;
use function fseek;
use function gettype;
use function implode;
use function is_array;
use function is_float;
use function is_infinite;
use function is_int;
use function is_nan;
use function json_decode;
use function max;
use function min;
use function strlen;
use function unpack;
use const JSON_THROW_ON_ERROR;
use const SEEK_CUR;

final class GLTFParser{

	public const HEADER_MAGIC = 0x46546C67;
	public const CHUNK_JSON = 0x4E4F534A;
	public const CHUNK_BIN = 0x004E4942;

	/**
	 * Infer file format (GLTF vs. GLB) by reading the first 4 bytes from the file.
	 *
	 * @param string $path
	 * @return bool whether file is binary (.glb)
	 */
	public static function inferBinary(string $path) : bool{
		$resource = fopen($path, "rb");
		try{
			$structure = fread($resource, 4);
		}finally{
			fclose($resource);
		}
		$type = unpack("V", $structure)[1];
		return $type === self::HEADER_MAGIC;
	}

	/**
	 * Returns validated array from JSON string.
	 *
	 * @param string $data a JSON string
	 * @return array
	 */
	private static function decodeJsonArray(string $data) : array{
		try{
			// GLTF nodes are indeed a tree structure but are represented as flat lists. A depth limit of 16 instead of
			// PHP's default 512 limit should be well more than sufficient for most if not all valid GLTF files.
			$result = json_decode($data, true, 16, JSON_THROW_ON_ERROR);
		}catch(JsonException $e){
			throw new InvalidArgumentException("Failed to decode JSON data: {$e->getMessage()}", $e->getCode(), $e);
		}
		is_array($result) || throw new InvalidArgumentException("Expected JSON data to be of type array, got " . gettype($result));
		return $result;
	}

	public const SCHEMA_OPTIONAL = 1 << 0;
	public const SCHEMA_REPORT_UNKNOWN_KEYS = 1 << 1;
	public const SCHEMA_NO_NESTING = 1 << 2;

	private static function validateJsonSchema(mixed $json, array $schema, int $flags = 0) : void{
		$optional = ($flags & self::SCHEMA_OPTIONAL) > 0;
		$report_unknown_k = ($flags & self::SCHEMA_REPORT_UNKNOWN_KEYS) > 0;
		$nesting = ($flags & self::SCHEMA_NO_NESTING) === 0;

		$buffer = [];
		foreach($schema as $k => $v){
			$buffer[] = [[$k], $v, 0];
		}
		$index = 0;
		while(isset($buffer[$index])){
			[$keys, $expect, $depth] = $buffer[$index++];
			$sub_schema = $schema;
			$value = $json;
			foreach($keys as $offset => $key){
				is_array($value) || throw new InvalidArgumentException("Key '" . implode(".", array_slice($keys, 0, $offset)) . "' must be an array");
				if($report_unknown_k && is_array($sub_schema)){
					$unknown_k = array_keys(array_diff_key($value, $sub_schema));
					count($unknown_k) === 0 || throw new InvalidArgumentException("Unknown keys encountered in '" . implode(".", array_slice($keys, 0, $offset)) . "': [" . implode(", ", array_slice($unknown_k, 0, 8)) . "], expected one of: [" . implode(", ", array_keys($sub_schema)) . "]");
				}
				if($optional && !isset($value[$key])){
					continue 2;
				}
				isset($value[$key]) || throw new InvalidArgumentException("Key '" . implode(".", array_slice($keys, 0, $offset + 1)) . "' must be set");
				$value = $value[$key];
				$sub_schema = $sub_schema[$key];
			}
			gettype($value) === gettype($expect) || (is_float($expect) && is_int($value)) || throw new InvalidArgumentException("Expected type of value at '" . implode(".", $keys) . "' to be " . gettype($expect) . ", got " . gettype($value));
			if(is_array($expect) && $nesting){
				foreach($expect as $k => $v){
					$keys2 = $keys;
					$keys2[] = $k;
					$buffer[] = [$keys2, $v, $depth + 1];
				}
			}
		}
	}

	public bool $binary;
	public int $version;
	public int $length;
	public array $properties;

	/**
	 * Parses the structure of a GLB or GLTF file.
	 *
	 * @param string $path path to a GLB or GLTF file
	 * @param bool|null $binary whether the file type is binary (GLB), or null if the parser should infer the type
	 */
	public function __construct(string $path, ?bool $binary = null){
		$binary = $binary ?? self::inferBinary($path);
		if($binary){
			$resource = fopen($path, "rb");
			$resource !== false || throw new InvalidArgumentException("Failed to open file {$path}");
			try{
				[$version, $length] = $this->readHeaderGlb($resource);
				$properties = $this->readChunkGlb($resource, self::CHUNK_JSON);
			}finally{
				fclose($resource);
			}
			// chunk1 (which may or may not exist) is encoded data representing geometry, animation key frames, skins,
			// and images. we don't really need to read this data as we are concerned only with the metadata.
			// $chunk1 = $this->readChunk(self::CHUNK_BIN);
		}else{
			$contents = file_get_contents($path);
			$length = strlen($contents);
			$contents = self::decodeJsonArray($contents);
			$version = (int) $contents["asset"]["version"];
			$properties = $contents;
		}
		if($version !== 2){
			// TODO: check what the difference between version 1 and version 2 is.
			// this will likely impact self::computeModelDimensions() and maybe self::getMetadata().
			throw new InvalidArgumentException("Unsupported GLB version ({$version}), expected version 2");
		}
		$this->validateProperties($properties);
		$this->binary = $binary;
		$this->version = $version;
		$this->length = $length;
		$this->properties = $properties;
	}

	/**
	 * Reads a GLB header and returns version and length. 'Magic' is not returned, but is instead validated.
	 *
	 * @param resource $resource a file pointer to read the header from
	 * @return array{int, int} a tuple of version and length
	 */
	private function readHeaderGlb($resource) : array{
		$header = fread($resource, 12);
		$decoded = unpack("V3h/", $header);
		$magic = $decoded["h1"];
		$version = $decoded["h2"];
		$length = $decoded["h3"];
		$magic === self::HEADER_MAGIC || throw new InvalidArgumentException("Improperly formatted GLB header: Magic has unexpected value: " . bin2hex($magic));
		return [$version, $length];
	}

	/**
	 * Reads a GLB chunk from the current file position.
	 *
	 * @param resource $resource a file pointer to read the chunk from
	 * @param self::CHUNK_* $expected_type an expected GLTF chunk type
	 * @return array|string|null chunk data (array for JSON chunks, string for binary chunks, null for unknown chunks)
	 */
	public function readChunkGlb($resource, int $expected_type) : array|string|null{
		$structure = fread($resource, 8);
		$decoded = unpack("V2s/", $structure);
		$length = $decoded["s1"];
		$type = $decoded["s2"];
		$type === $expected_type || throw new InvalidArgumentException("Unexpected chunk type ({$type}), expected {$expected_type}");
		if($type === self::CHUNK_JSON){
			$data = fread($resource, $length);
			$data = self::decodeJsonArray($data);
			return $data;
		}
		if($type === self::CHUNK_BIN){
			return fread($resource, $length);
		}
		// do not read into memory chunk of unknown types, only move offset to the end of chunk.
		// according to gltf spec: Client implementations MUST ignore chunks with unknown types to enable glTF
		// extensions to reference additional chunks with new types following the first two chunks.
		fseek($resource, $length, SEEK_CUR);
		return null;
	}

	public function validateProperties(array $properties) : void{
		$required = [
			"accessors" => [],
			"asset" => ["version" => ""]
		];
		$optional = [
			"buffers" => [], "bufferViews" => [], "materials" => [], "meshes" => [], "nodes" => [], "scene" => 0,
			"scenes" => [], "extensions" => [], "extensionsRequired" => [], "extensionsUsed" => [], "images" => [],
			"textures" => [], "cameras" => [], "animations" => [], "samplers" => [], "skins" => []
		];
		self::validateJsonSchema($properties, $required);
		self::validateJsonSchema($properties, $required + $optional, self::SCHEMA_OPTIONAL | self::SCHEMA_REPORT_UNKNOWN_KEYS | self::SCHEMA_NO_NESTING);

		// validate accessors
		$required_accessors = ["componentType" => 0, "count" => 0, "type" => ""];
		$optional_accessors = [
			"bufferView" => 0, "byteOffset" => 0, "normalized" => false, "name" => "", "min" => [], "max" => [],
			"sparse" => [], "extensions" => [], "extras" => []
		];
		$required_sparse = ["count" => 0, "indices" => [], "values" => []];
		$optional_sparse = ["extensions" => [], "extras" => []];
		foreach($properties["accessors"] as $entry){
			self::validateJsonSchema($entry, $required_accessors);
			self::validateJsonSchema($entry, $required_accessors + $optional_accessors, self::SCHEMA_OPTIONAL | self::SCHEMA_REPORT_UNKNOWN_KEYS);

			// validate min, max
			if(isset($entry["min"]) || isset($entry["max"])){
				$length = match($entry["type"]){
					"SCALAR" => 1,
					"VEC2" => 2,
					"VEC3" => 3,
					"VEC4" => 4,
					"MAT2" => 2 * 2,
					"MAT3" => 3 * 3,
					"MAT4" => 4 * 4,
					default => throw new InvalidArgumentException("Expected accessor type to be one of: SCALAR, VEC2, VEC3, VEC4, MAT2, MAT3, MAT4, got '{$entry["type"]}'")
				};
				$types = array_fill(0, $length, 0.0);
				self::validateJsonSchema($entry, ["min" => $types, "max" => $types]);
				foreach($entry["min"] as $value){
					!is_infinite($value) || throw new InvalidArgumentException("Invalid value encountered (inf) in 'min' accessor entry");
					!is_nan($value) || throw new InvalidArgumentException("Invalid value encountered (inf) in 'min' accessor entry");
				}
				foreach($entry["max"] as $value){
					!is_infinite($value) || throw new InvalidArgumentException("Invalid value encountered (inf) in 'min' accessor entry");
					!is_nan($value) || throw new InvalidArgumentException("Invalid value encountered (inf) in 'min' accessor entry");
				}
			}

			// validate sparse
			if(isset($entry["sparse"])){
				$sparse = $entry["sparse"];
				self::validateJsonSchema($sparse, $required_sparse);
				self::validateJsonSchema($sparse, $required_sparse + $optional_sparse, self::SCHEMA_OPTIONAL | self::SCHEMA_REPORT_UNKNOWN_KEYS);
				$sparse["count"] >= 1 || throw new InvalidArgumentException("Expected 'sparse.count' >= 1, got {$sparse["count"]}");
				count($sparse["indices"]) === $sparse["count"] || throw new InvalidArgumentException("Expected 'sparse.indices' to contain {$sparse["count"]} items, got " . count($sparse["indices"]));
			}
		}

		self::validateJsonSchema($properties["asset"], $required["asset"] + [
			"copyright" => "", "generator" => "", "minVersion" => "", "extensions" => [], "extras" => []
		], self::SCHEMA_OPTIONAL | self::SCHEMA_REPORT_UNKNOWN_KEYS);

		// validate animations
		$required_animations = ["channels" => [], "samplers" => []];
		$optional_animations = ["name" => "", "extensions" => [], "extras" => []];
		if(isset($properties["animations"])){
			foreach($properties["animations"] as $entry){
				self::validateJsonSchema($entry, $required_animations);
				self::validateJsonSchema($entry, $required_animations + $optional_animations, self::SCHEMA_OPTIONAL | self::SCHEMA_REPORT_UNKNOWN_KEYS);
			}
		}

		// validate buffers
		$required_buffers = ["byteLength" => 0];
		$optional_buffers = ["uri" => "", "name" => "", "extensions" => [], "extras" => []];
		if(isset($properties["buffers"])){
			foreach($properties["buffers"] as $entry){
				self::validateJsonSchema($entry, $required_buffers);
				self::validateJsonSchema($entry, $required_buffers + $optional_buffers, self::SCHEMA_OPTIONAL | self::SCHEMA_REPORT_UNKNOWN_KEYS);
				$entry["byteLength"] >= 1 || throw new InvalidArgumentException("Expected 'byteLength' >= 1, got {$entry["byteLength"]}");
			}
		}

		// validate bufferViews
		$required_buffer_views = ["buffer" => 0, "byteLength" => 0];
		$optional_buffer_views = ["byteOffset" => 0, "byteStride" => 0, "target" => 0, "name" => "", "extensions" => [], "extras" => []];
		if(isset($properties["bufferViews"])){
			foreach($properties["bufferViews"] as $entry){
				self::validateJsonSchema($entry, $required_buffer_views);
				self::validateJsonSchema($entry, $required_buffer_views + $optional_buffer_views, self::SCHEMA_OPTIONAL | self::SCHEMA_REPORT_UNKNOWN_KEYS);
				$entry["buffer"] >= 0 || throw new InvalidArgumentException("Expected 'buffer' >= 0, got {$entry["buffer"]}");
				$entry["byteLength"] >= 1 || throw new InvalidArgumentException("Expected 'byteLength' >= 1, got {$entry["byteLength"]}");

				!isset($entry["byteOffset"]) || $entry["byteOffset"] >= 0 || throw new InvalidArgumentException("Expected 'byteOffset' >= 0, got {$entry["byteOffset"]}");
				!isset($entry["byteStride"]) || ($entry["byteStride"] >= 4 && $entry["byteStride"] <= 252) || throw new InvalidArgumentException("Expected 'byteStride' >= 4, <= 252, got {$entry["byteStride"]}");
				!isset($entry["target"]) || $entry["target"] === 34962 || $entry["target"] === 34963 || throw new InvalidArgumentException("Expected 'target' to be one of: 34962, 34963, got {$entry["target"]}");
			}
		}
	}

	/**
	 * Computes length of X, Y, and Z planes of the model.
	 *
	 * @return array{float, float, float}|null a tuple of lengths of X, Y, and Z planes respectively, or null if the
	 * model dimensions could not be inferred.
	 */
	public function computeModelDimensions() : ?array{
		$values = [];
		foreach($this->properties["nodes"] as $node){
			if(!isset($node["mesh"])){
				continue;
			}

			if(isset($node["matrix"])){
				$tx = $node["matrix"][12];
				$ty = $node["matrix"][13];
				$tz = $node["matrix"][14];
			}elseif(isset($node["translation"])){
				[$tx, $ty, $tz] = $node["translation"];
			}else{
				continue;
			}

			$mesh = $this->properties["meshes"][$node["mesh"]];
			foreach($mesh["primitives"] as $primitive){
				if(!isset($primitive["attributes"]["POSITION"])){
					continue;
				}

				$accessor = $this->properties["accessors"][$primitive["attributes"]["POSITION"]];
				array_push($values, [
					$accessor["min"][0] + $tx,
					$accessor["min"][1] + $ty,
					$accessor["min"][2] + $tz,
				], [
					$accessor["max"][0] + $tx,
					$accessor["max"][1] + $ty,
					$accessor["max"][2] + $tz,
				]);
			}
		}
		if(count($values) === 0){
			return null;
		}
		$x = array_column($values, 0);
		$y = array_column($values, 1);
		$z = array_column($values, 2);
		return [max($x) - min($x), max($y) - min($y), max($z) - min($z)];
	}

	/**
	 * Returns a selected set of metadata properties from the parsed GLTF file.
	 * TODO: Standardization - return necessary metadata instead of cherry-picking the properties.
	 *
	 * @return array{Copyright?: string, Generator?: string, Version: int}
	 */
	public function getMetadata() : array{
		$metadata = [];
		if(isset($this->properties["asset"]["copyright"])){
			$metadata["Copyright"] = $this->properties["asset"]["copyright"];
		}
		if(isset($this->properties["asset"]["generator"])){
			$metadata["Generator"] = $this->properties["asset"]["generator"];
		}
		$metadata["Version"] = $this->version;
		return $metadata;
	}
}