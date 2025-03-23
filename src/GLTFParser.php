<?php

namespace MediaWiki\Extension\GLTFHandler;

use InvalidArgumentException;
use JsonException;
use function array_column;
use function array_combine;
use function array_diff_key;
use function array_fill;
use function array_keys;
use function array_push;
use function array_slice;
use function array_values;
use function base64_decode;
use function bin2hex;
use function count;
use function dirname;
use function fclose;
use function feof;
use function file_exists;
use function file_get_contents;
use function fopen;
use function fread;
use function fseek;
use function gettype;
use function implode;
use function in_array;
use function is_array;
use function is_file;
use function is_float;
use function is_infinite;
use function is_int;
use function is_nan;
use function is_string;
use function json_decode;
use function max;
use function min;
use function pathinfo;
use function str_starts_with;
use function strlen;
use function strpos;
use function substr;
use function unpack;
use function urldecode;
use const DIRECTORY_SEPARATOR;
use const JSON_THROW_ON_ERROR;
use const PATHINFO_EXTENSION;
use const SEEK_CUR;

final class GLTFParser{

	public const ALLOWED_URI_EXTENSIONS = ["bin", "glbin", "glbuf", "png", "jpg", "jpeg"];
	public const COMPONENT_TYPES = [
		5120 => "BYTE",
		5121 => "UNSIGNED_BYTE",
		5122 => "SHORT",
		5123 => "UNSIGNED_SHORT",
		5125 => "UNSIGNED_INT",
		5126 => "FLOAT"
	];
	public const COMPONENT_SIZES = [ // in bytes
		5120 => 1,
		5121 => 1,
		5122 => 2,
		5123 => 2,
		5125 => 4,
		5126 => 4
	];

	public const HEADER_MAGIC = 0x46546C67;
	public const CHUNK_JSON = 0x4E4F534A;
	public const CHUNK_BIN = 0x004E4942;

	/** @var int whether the file type is binary (GLB) */
	public const FLAG_TYPE_GLB = 1 << 0;
	/** @var int whether the file type is JSON (GLTF) */
	public const FLAG_TYPE_GLTF = 1 << 1;
	/** @var int whether to resolve buffers pointing a local filesystem file */
	public const FLAG_RESOLVE_LOCAL_URI = 1 << 2;
	/** @var int whether to resolve buffers pointing a remote URI */
	public const FLAG_RESOLVE_REMOTE_URI = 1 << 3;

	/** @var int the file has an unsupported GLTF version */
	public const ERR_UNSUPPORTED_VERSION = 100000;
	/** @var int file metadata (glTF properties) is improperly formatted */
	public const ERR_INVALID_SCHEMA = 100001;
	/** @var int a URI to an embedded resource (e.g., data:application/octet-stream) could not successfully be read */
	public const ERR_URI_RESOLUTION_EMBEDDED = 100002;
	/** @var int a URI to a local resource (i.e., a local file) could not successfully be read */
	public const ERR_URI_RESOLUTION_LOCAL = 100003;
	/** @var int a URI to a remote resource (e.g., https://...) could not successfully be read */
	public const ERR_URI_RESOLUTION_REMOTE = 100004;

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
			throw new InvalidArgumentException("Failed to decode JSON data: {$e->getMessage()}", 0, $e);
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
				is_array($value) || throw new InvalidArgumentException("Key '" . implode(".", array_slice($keys, 0, $offset + 1)) . "' must be an array", self::ERR_INVALID_SCHEMA);
				if($report_unknown_k && is_array($sub_schema)){
					$unknown_k = array_keys(array_diff_key($value, $sub_schema));
					count($unknown_k) === 0 || throw new InvalidArgumentException("Unknown keys encountered in '" . implode(".", array_slice($keys, 0, $offset)) . "': [" . implode(", ", array_slice($unknown_k, 0, 8)) . "], expected one of: [" . implode(", ", array_keys($sub_schema)) . "]", self::ERR_INVALID_SCHEMA);
				}
				if($optional && !isset($value[$key])){
					continue 2;
				}
				isset($value[$key]) || throw new InvalidArgumentException("Key '" . implode(".", array_slice($keys, 0, $offset + 1)) . "' must be set", self::ERR_INVALID_SCHEMA);
				$value = $value[$key];
				$sub_schema = $sub_schema[$key];
			}
			gettype($value) === gettype($expect) || (is_float($expect) && is_int($value)) || throw new InvalidArgumentException("Expected type of value at '" . implode(".", $keys) . "' to be " . gettype($expect) . ", got " . gettype($value), self::ERR_INVALID_SCHEMA);
			if(is_array($expect) && $nesting){
				foreach($expect as $k => $v){
					$keys2 = $keys;
					$keys2[] = $k;
					$buffer[] = [$keys2, $v, $depth + 1];
				}
			}
		}
	}

	public string $directory;
	public bool $binary;
	public int $version;
	public int $length;
	public array $properties;

	/** @var list<string|array{string, int}> */
	public array $buffers;

	/**
	 * Parses the structure of a GLB or GLTF file.
	 *
	 * @param string $path path to a GLB or GLTF file
	 * @param self::FLAG_* $flags
	 */
	public function __construct(string $path, int $flags = self::FLAG_RESOLVE_LOCAL_URI){
		$directory = dirname($path); // needed for buffer resolution (when URIs are encountered)
		$binary = match(true){
			($flags & self::FLAG_TYPE_GLB) > 0 => true,
			($flags & self::FLAG_TYPE_GLTF) > 0 => false,
			default => self::inferBinary($path)
		};
		if($binary){
			$resource = fopen($path, "rb");
			$resource !== false || throw new InvalidArgumentException("Failed to open file {$path}");
			try{
				[$version, $length] = $this->readHeaderGlb($resource);
				$properties = $this->readChunkGlb($resource, self::CHUNK_JSON);
				if(feof($resource)){
					$buffers = [];
				}else{
					// a GLB file has only one buffer entry
					$buffers = [$this->readChunkGlb($resource, self::CHUNK_BIN)];
				}
			}finally{
				fclose($resource);
			}
		}else{
			$contents = file_get_contents($path);
			$length = strlen($contents);
			$contents = self::decodeJsonArray($contents);
			$version = (int) $contents["asset"]["version"];
			$properties = $contents;
			$buffers = [];
		}
		if($version !== 2){
			// TODO: check what the difference between version 1 and version 2 is.
			// this will likely impact self::computeModelDimensions() and maybe self::getMetadata().
			throw new InvalidArgumentException("Unsupported GLB version ({$version}), expected version 2", self::ERR_UNSUPPORTED_VERSION);
		}
		[$buffers] = $this->processProperties($properties, $directory, $binary, $buffers, $flags);
		$this->directory = $directory;
		$this->binary = $binary;
		$this->version = $version;
		$this->length = $length;
		$this->properties = $properties;
		$this->buffers = $buffers;
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

	/**
	 * Validates schema of GLTF properties.
	 *
	 * TODO: Schema validation ought to be defined in a separate resource file, preferably one that adheres to
	 * json-schema.org.
	 *
	 * @param array $properties
	 * @param bool $binary
	 * @param list<array{self::BUFFER_RESOLVED, string}|array{self::BUFFER_UNRESOLVED, array{string, int}}> $buffers
	 * @param self::FLAG_* $flags
	 * @return array
	 */
	public function processProperties(array $properties, string $directory, bool $binary, array $buffers = [], int $flags = 0) : array{
		$relative_dir = ($flags & self::FLAG_RESOLVE_LOCAL_URI) > 0 ? $directory : null;
		$resolve_remote = ($flags & self::FLAG_RESOLVE_REMOTE_URI) > 0;

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

		// validate buffers
		// -- buffers must be validated earliest because bufferViews relies on it
		$required_buffers = ["byteLength" => 0];
		$optional_buffers = ["name" => "", "extensions" => [], "extras" => []];
		if(!$binary){
			count($buffers) === 0 || throw new InvalidArgumentException("Supplied buffer array must be empty for non-binary specification, got " . count($buffers) . " entries", self::ERR_INVALID_SCHEMA);
			$required_buffers["uri"] = "";
		}
		if(isset($properties["buffers"])){
			foreach($properties["buffers"] as $index => $entry){
				self::validateJsonSchema($entry, $required_buffers);
				self::validateJsonSchema($entry, $required_buffers + $optional_buffers, self::SCHEMA_OPTIONAL | self::SCHEMA_REPORT_UNKNOWN_KEYS);
				$entry["byteLength"] >= 1 || throw new InvalidArgumentException("Expected 'byteLength' >= 1, got {$entry["byteLength"]}");
				if(!$binary){
					$buffer = $this->resolveURI($entry["uri"], $relative_dir, $resolve_remote, $entry["byteLength"]);
					$buffers[$index] = $buffer ?? [$entry["uri"], $entry["byteLength"]];
				}else{
					$index === 0 || throw new InvalidArgumentException("Binary specification must define only one buffer, got a buffer at index {$index}", self::ERR_INVALID_SCHEMA);
					isset($buffers[$index]) || throw new InvalidArgumentException("Binary specification must pre-define buffers", self::ERR_INVALID_SCHEMA);
					is_string($buffers[$index]) || throw new InvalidArgumentException("Expected binary specification buffer to be resolved, got unresolved {$buffers[$index][0]}", self::ERR_INVALID_SCHEMA);
				}
			}
		}

		// validate bufferViews
		// -- buffer views need to be validated early because 'accessors' and 'images' rely on them
		$required_buffer_views = ["buffer" => 0, "byteLength" => 0];
		$optional_buffer_views = ["byteOffset" => 0, "byteStride" => 0, "target" => 0, "name" => "", "extensions" => [], "extras" => []];
		$buffer_views = [];
		if(isset($properties["bufferViews"])){
			foreach($properties["bufferViews"] as $entry){
				self::validateJsonSchema($entry, $required_buffer_views);
				self::validateJsonSchema($entry, $required_buffer_views + $optional_buffer_views, self::SCHEMA_OPTIONAL | self::SCHEMA_REPORT_UNKNOWN_KEYS);
				isset($buffers[$entry["buffer"]]) || throw new InvalidArgumentException("Expected 'buffer' >= 0, < " . count($buffers) . ", got {$entry["buffer"]}", self::ERR_INVALID_SCHEMA);
				$entry["byteLength"] >= 1 || throw new InvalidArgumentException("Expected 'byteLength' >= 1, got {$entry["byteLength"]}", self::ERR_INVALID_SCHEMA);

				!isset($entry["byteOffset"]) || $entry["byteOffset"] >= 0 || throw new InvalidArgumentException("Expected 'byteOffset' >= 0, got {$entry["byteOffset"]}", self::ERR_INVALID_SCHEMA);
				!isset($entry["byteStride"]) || ($entry["byteStride"] >= 4 && $entry["byteStride"] <= 252) || throw new InvalidArgumentException("Expected 'byteStride' >= 4, <= 252, got {$entry["byteStride"]}", self::ERR_INVALID_SCHEMA);
				!isset($entry["target"]) || $entry["target"] === 34962 || $entry["target"] === 34963 || throw new InvalidArgumentException("Expected 'target' to be one of: 34962, 34963, got {$entry["target"]}", self::ERR_INVALID_SCHEMA);
				$buffer_views[] = $entry;
			}
		}

		// validate accessors
		$required_accessors = ["componentType" => 0, "count" => 0, "type" => ""];
		$optional_accessors = [
			"bufferView" => 0, "byteOffset" => 0, "normalized" => false, "name" => "", "min" => [], "max" => [],
			"sparse" => [], "extensions" => [], "extras" => []
		];
		$required_sparse = ["count" => 0, "indices" => [], "values" => []];
		$optional_sparse = ["extensions" => [], "extras" => []];
		$required_sparse_indices = ["bufferView" => 0, "componentType" => 0];
		$optional_sparse_indices = ["byteOffset" => 0, "extensions" => [], "extras" => []];
		$required_sparse_values = ["bufferView" => 0];
		$optional_sparse_values = ["byteOffset" => 0, "extensions" => [], "extras" => []];
		foreach($properties["accessors"] as $entry){
			self::validateJsonSchema($entry, $required_accessors);
			self::validateJsonSchema($entry, $required_accessors + $optional_accessors, self::SCHEMA_OPTIONAL | self::SCHEMA_REPORT_UNKNOWN_KEYS);

			$entry["count"] >= 1 || throw new InvalidArgumentException("Expected 'count' >= 1, got {$entry["count"]}", self::ERR_INVALID_SCHEMA);

			$component_type = $entry["componentType"];
			isset(self::COMPONENT_TYPES[$component_type]) || throw new InvalidArgumentException("Expected 'componentType' to be one of: " . implode(", ", array_keys(self::COMPONENT_TYPES)) . ", got {$component_type}", self::ERR_INVALID_SCHEMA);
			!isset($entry["byteOffset"]) || $entry["byteOffset"] >= 0 || throw new InvalidArgumentException("Expected 'sparse.count' >= 0, got {$entry["byteOffset"]}", self::ERR_INVALID_SCHEMA);
			if(isset($entry["normalized"]) && $entry["normalized"] && in_array(self::COMPONENT_TYPES[$component_type], ["FLOAT", "UNSIGNED_INT"], true)){
				throw new InvalidArgumentException("Expected 'normalized' to be false when component type is {$component_type}", self::ERR_INVALID_SCHEMA);
			}
			$component_size = self::COMPONENT_SIZES[$component_type];
			$component_count = match($entry["type"]){
				"SCALAR" => 1,
				"VEC2" => 2,
				"VEC3" => 3,
				"VEC4" => 4,
				"MAT2" => 2 * 2,
				"MAT3" => 3 * 3,
				"MAT4" => 4 * 4,
				default => throw new InvalidArgumentException("Expected accessor type to be one of: SCALAR, VEC2, VEC3, VEC4, MAT2, MAT3, MAT4, got '{$entry["type"]}'", self::ERR_INVALID_SCHEMA)
			};

			// validate min, max
			if(isset($entry["min"]) || isset($entry["max"])){
				$types = array_fill(0, $component_count, 0.0);
				self::validateJsonSchema($entry, ["min" => $types, "max" => $types]);
				foreach([...$entry["min"], ...$entry["max"]] as $value){
					!is_infinite($value) || throw new InvalidArgumentException("Invalid value encountered (inf) in accessor entry", self::ERR_INVALID_SCHEMA);
					!is_nan($value) || throw new InvalidArgumentException("Invalid value encountered (inf) in accessor entry", self::ERR_INVALID_SCHEMA);
					[$min, $max] = match(self::COMPONENT_TYPES[$component_type]){ // perhaps we should use unpack(pack()) to validate these values?
						"BYTE" => [-0x7f - 1, 0x7f],
						"UNSIGNED_BYTE" => [0, 0xff],
						"SHORT" => [-0x7fff - 1, 0x7fff],
						"UNSIGNED_SHORT" => [0, 0xffff],
						"UNSIGNED_INT" => [0, 0xffffffff],
						"FLOAT" => [-3.4028237 * (10 ** 38), 3.4028237 * (10 ** 38)]
					};
					($value >= $min && $value <= $max) || throw new InvalidArgumentException("Expected accessor entry to fall in range [{$min}, {$max}], got {$value}", self::ERR_INVALID_SCHEMA);
				}
			}

			// validate sparse
			if(isset($entry["sparse"])){
				$sparse = $entry["sparse"];
				self::validateJsonSchema($sparse, $required_sparse);
				self::validateJsonSchema($sparse, $required_sparse + $optional_sparse, self::SCHEMA_OPTIONAL | self::SCHEMA_REPORT_UNKNOWN_KEYS);
				self::validateJsonSchema($sparse["indices"], $required_sparse_indices);
				self::validateJsonSchema($sparse["indices"], $required_sparse_indices + $optional_sparse_indices, self::SCHEMA_OPTIONAL | self::SCHEMA_REPORT_UNKNOWN_KEYS);
				self::validateJsonSchema($sparse["values"], $required_sparse_values);
				self::validateJsonSchema($sparse["values"], $required_sparse_values + $optional_sparse_values, self::SCHEMA_OPTIONAL | self::SCHEMA_REPORT_UNKNOWN_KEYS);

				$sparse["count"] >= 1 || throw new InvalidArgumentException("Expected 'sparse.count' >= 1, got {$sparse["count"]}", self::ERR_INVALID_SCHEMA);
				$sparse["count"] <= $entry["count"] || throw new InvalidArgumentException("Expected 'sparse.count' ({$sparse["count"]}) <= base accessor size ({$entry["count"]})", self::ERR_INVALID_SCHEMA);

				// validate indices
				$sparse_component_type = $sparse["indices"]["componentType"];
				isset(self::COMPONENT_TYPES[$sparse_component_type]) || throw new InvalidArgumentException("Expected 'componentType' to be one of: " . implode(", ", array_keys(self::COMPONENT_TYPES)) . ", got {$sparse_component_type}", self::ERR_INVALID_SCHEMA);
				$sparse_component_size = self::COMPONENT_SIZES[$sparse_component_type];
				$index = $sparse["indices"]["bufferView"];
				isset($buffer_views[$index]) || throw new InvalidArgumentException("Expected 'bufferView' >= 0, < " . count($buffer_views) . ", got {$index}", self::ERR_INVALID_SCHEMA);
				$view = $buffer_views[$index];
				!isset($view["byteStride"]) || throw new InvalidArgumentException("Expected 'byteStride' of buffer view ({$index}) accessed from sparse indices to be undefined", self::ERR_INVALID_SCHEMA);
				!isset($view["target"]) || throw new InvalidArgumentException("Expected 'target' of buffer view ({$index}) accessed from sparse indices to be undefined", self::ERR_INVALID_SCHEMA);

				// validate if buffer view and the optional byteOffset align to the componentType byte length
				$offset_accessor = $sparse["indices"]["byteOffset"] ?? 0;
				$offset_view = $view["byteOffset"] ?? 0;
				($offset_accessor + $offset_view) % $sparse_component_size === 0 || throw new InvalidArgumentException("Expected accessor offset ({$offset_accessor}) + view offset ({$offset_view}) to be a multiple of size of component '" . self::COMPONENT_TYPES[$sparse_component_size] . "' ({$sparse_component_size})", self::ERR_INVALID_SCHEMA);

				is_string($buffers[$view["buffer"]]) || throw new InvalidArgumentException("Sparse indices points to an unresolved buffer ({$view["buffer"]}): {$buffers[$view["buffer"]][0]}", self::ERR_INVALID_SCHEMA);
				$indices = unpack(match(self::COMPONENT_TYPES[$sparse_component_type]){
					"UNSIGNED_BYTE" => "C",
					"UNSIGNED_SHORT" => "v",
					"UNSIGNED_INT" => "V",
					default => throw new InvalidArgumentException("Expected 'componentType' to be one of: UNSIGNED_BYTE, UNSIGNED_SHORT, UNSIGNED_INT, got " . self::COMPONENT_TYPES[$sparse_component_type], self::ERR_INVALID_SCHEMA)
				} . "{$sparse["count"]}/", $buffers[$view["buffer"]], $offset_accessor + $offset_view);
				$indices = array_values($indices);
				foreach($indices as $index => $value){
					$value < $entry["count"] || throw new InvalidArgumentException("Expected sparse.indices ({$value}) <= base accessor size ({$entry["count"]})", self::ERR_INVALID_SCHEMA);
					if($index > 0 && $value < $indices[$index - 1]){
						throw new InvalidArgumentException("Expected sparse indices to strictly increase, got {$value} < {$indices[$index - 1]}", self::ERR_INVALID_SCHEMA);
					}
				}

				// validate values
				$index = $sparse["values"]["bufferView"];
				isset($buffer_views[$index]) || throw new InvalidArgumentException("Expected 'bufferView' >= 0, < " . count($buffer_views) . ", got {$index}", self::ERR_INVALID_SCHEMA);
				$view = $buffer_views[$index];
				!isset($view["byteStride"]) || throw new InvalidArgumentException("Expected 'byteStride' of buffer view ({$index}) accessed from sparse values to be undefined", self::ERR_INVALID_SCHEMA);
				!isset($view["target"]) || throw new InvalidArgumentException("Expected 'target' of buffer view ({$index}) accessed from sparse values to be undefined", self::ERR_INVALID_SCHEMA);

				// validate if buffer view and the optional byteOffset align to the componentType byte length
				$offset_accessor = $sparse["values"]["byteOffset"] ?? 0;
				$offset_view = $view["byteOffset"] ?? 0;
				($offset_accessor + $offset_view) % $component_size === 0 || throw new InvalidArgumentException("Expected accessor offset ({$offset_accessor}) + view offset ({$offset_view}) to be a multiple of size of component '" . self::COMPONENT_TYPES[$component_type] . "' ({$component_size})", self::ERR_INVALID_SCHEMA);

				is_string($buffers[$view["buffer"]]) || throw new InvalidArgumentException("Sparse values points to an unresolved buffer ({$view["buffer"]}): {$buffers[$view["buffer"]][0]}", self::ERR_INVALID_SCHEMA);
				$values = unpack(match(self::COMPONENT_TYPES[$component_type]){
					"BYTE" => "c",
					"UNSIGNED_BYTE" => "C",
					"SHORT" => "s",
					"UNSIGNED_SHORT" => "v",
					"UNSIGNED_INT" => "V",
					"FLOAT" => "g"
				} . "{$sparse["count"]}/", $buffers[$view["buffer"]], $offset_accessor + $offset_view);
				$values = array_values($values);
				$sparse = array_combine($indices, $values);
			}else{
				$sparse = array_fill(0, $entry["count"] * $component_size, 0);
			}

			if(isset($entry["bufferView"])){
				$index = $entry["bufferView"];
				isset($buffer_views[$index]) || throw new InvalidArgumentException("Expected 'bufferView' >= 0, < " . count($buffer_views) . ", got {$index}", self::ERR_INVALID_SCHEMA);
				$view = $buffer_views[$index];
				is_string($buffers[$view["buffer"]]) || throw new InvalidArgumentException("Accessor points to an unresolved buffer ({$view["buffer"]}): {$buffers[$view["buffer"]][0]}", self::ERR_INVALID_SCHEMA);

				// validate if buffer view and the optional byteOffset align to the componentType byte length
				$offset_accessor = $entry["byteOffset"] ?? 0;
				$offset_view = $view["byteOffset"] ?? 0;
				($offset_accessor + $offset_view) % $component_size === 0 || throw new InvalidArgumentException("Expected accessor offset ({$offset_accessor}) + view offset ({$offset_view}) to be a multiple of size of accessor component '" . self::COMPONENT_TYPES[$component_type] . "' ({$component_size})", self::ERR_INVALID_SCHEMA);
				!isset($view["byteStride"]) || $view["byteStride"] % $component_size === 0 || throw new InvalidArgumentException("Expected byte stride of view ({$view["byteStride"]}) to be a multiple of size of accessor component '" . self::COMPONENT_TYPES[$component_type] . "' ({$component_size})", self::ERR_INVALID_SCHEMA);

				$EFFECTIVE_BYTE_STRIDE = $view["byteStride"] ?? 0;
				$fitness = $offset_accessor + $EFFECTIVE_BYTE_STRIDE * ($entry["count"] - 1) + $component_size * $component_count;
				$fitness <= $view["byteLength"] || throw new InvalidArgumentException("Expected accessor fitness ({$fitness}) <= buffer view length ({$view["byteLength"]})", self::ERR_INVALID_SCHEMA);

				$values = unpack(match(self::COMPONENT_TYPES[$component_type]){
					"BYTE" => "c",
					"UNSIGNED_BYTE" => "C",
					"SHORT" => "s",
					"UNSIGNED_SHORT" => "v",
					"UNSIGNED_INT" => "V",
					"FLOAT" => "g"
				} . "{$entry["count"]}/", $buffers[$view["buffer"]], $offset_accessor + $offset_view);
				$values = array_values($values);
				// TODO: perform $sparse substitution for $values
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

		// validate images
		$optional_images = ["uri" => "", "mimeType" => "", "bufferView" => 0, "name" => "", "extensions" => [], "extras" => []];
		$image_buffers = [];
		if(isset($properties["images"])){
			foreach($properties["images"] as $entry){
				self::validateJsonSchema($entry, $optional_images, self::SCHEMA_OPTIONAL | self::SCHEMA_REPORT_UNKNOWN_KEYS);
				!isset($entry["uri"], $entry["bufferView"]) || throw new InvalidArgumentException("Expected images to contain one of 'uri' or 'bufferView', got both", self::ERR_INVALID_SCHEMA);
				isset($entry["uri"]) || isset($entry["bufferView"]) || throw new InvalidArgumentException("Expected images to contain one of 'uri' or 'bufferView', got neither", self::ERR_INVALID_SCHEMA);
				if(isset($entry["bufferView"])){
					$entry["bufferView"] >= 0 || throw new InvalidArgumentException("Expected 'bufferView' >= 0, got {$entry["bufferView"]}", self::ERR_INVALID_SCHEMA);
					isset($entry["mimeType"]) || throw new InvalidArgumentException("Expected 'mimeType' to be defined when 'bufferView' is defined", self::ERR_INVALID_SCHEMA);
					$entry["mimeType"] === "image/jpeg" || $entry["mimeType"] === "image/png" || throw new InvalidArgumentException("Expected 'mimeType' to be one of: image/jpeg, image/png, got {$entry["mimeType"]}", self::ERR_INVALID_SCHEMA);

					$index = $entry["bufferView"];
					isset($buffer_views[$index]) || throw new InvalidArgumentException("Expected 'bufferView' index to be valid (< " . count($buffer_views) . "), got {$index}", self::ERR_INVALID_SCHEMA);
					$view = $buffer_views[$index];
					if(is_string($buffers[$view["buffer"]])){
						$image_buffers[] = substr($buffers[$view["buffer"]][1], $view["byteOffset"] ?? 0, $view["byteLength"]);
					}else{
						$image_buffers[] = [$view, null];
					}
				}else{
					$buffer = $this->resolveURI($entry["uri"], $relative_dir, $resolve_remote, null);
					$image_buffers[] = $buffer ?? [$entry["uri"], null];
				}
			}
		}
		return [$buffers, $buffer_views, $image_buffers];
	}

	/**
	 * Resolves a buffer URI based on the given options ($relative_directory, $resolve_remote), or returns null if the
	 * operation is disallowed by the options. Embedded URIs (i.e., data:application/octet-stream;base64,...) will
	 * always be resolved.
	 *
	 * @param string $uri the URI to resolve
	 * @param string|null $base_directory the base directory for relative URI paths
	 * @param bool $resolve_remote whether to resolve remote URIs (e.g., http://, https://, etc.)
	 * @param int|null $length the length of bytes of the resolved buffer, or null to ignore length constraints
	 * @return string|null the returned raw buffer (byte array), or null if the options disallow this resolution
	 */
	public function resolveURI(string $uri, ?string $base_directory, bool $resolve_remote, ?int $length = null) : ?string{
		if(str_starts_with($uri, "data:")){
			$token_end = strpos($uri, ",", 5);
			if($token_end === false || $token_end > 64){
				$token_end = 64;
			}
			$uri_type = substr($uri, 5, $token_end - 5);
			$uri_data = substr($uri, $token_end + 1);
			if($uri_type === "application/octet-stream"){
				return urldecode($uri_data);
			}
			if(in_array($uri_type, [
				"application/octet-stream;base64",
				"application/gltf-buffer;base64",
				"image/png;base64",
				"image/jpeg;base64",
			], true)){
				$result = base64_decode($uri_data);
				$result !== false || throw new InvalidArgumentException("Improperly encoded base64 data supplied for URI type {$uri_type}", self::ERR_URI_RESOLUTION_EMBEDDED);
				return $result;
			}
			throw new InvalidArgumentException("Expected URI type to be one of: application/octet-stream, application/octet-stream;base64, application/gltf-buffer;base64, got {$uri_type}", self::ERR_URI_RESOLUTION_EMBEDDED);
		}
		if(filter_var($uri, FILTER_VALIDATE_URL)){
			if(!$resolve_remote){
				return null;
			}
			// TODO: Validate return type, HTTP response code
			$data = file_get_contents($uri, length: $length);
			$data !== false || throw new InvalidArgumentException("Remote resolution failed for uri: {$uri}", self::ERR_URI_RESOLUTION_REMOTE);
			return $data;
		}
		if($base_directory === null){
			return null;
		}
		$path = $base_directory . DIRECTORY_SEPARATOR . urldecode($uri);
		(is_file($path) && file_exists($path)) || throw new InvalidArgumentException("File not found: {$path}", self::ERR_URI_RESOLUTION_LOCAL);
		$ext = pathinfo($path, PATHINFO_EXTENSION);
		in_array($ext, self::ALLOWED_URI_EXTENSIONS, true) || throw new InvalidArgumentException("Expected file extension to be one of: " . implode(", ", self::ALLOWED_URI_EXTENSIONS) . ", got {$ext}", self::ERR_URI_RESOLUTION_LOCAL);
		$data = file_get_contents($path, length: $length);
		$data !== false || throw new InvalidArgumentException("Local resolution failed for uri: {$uri}", self::ERR_URI_RESOLUTION_LOCAL);
		return $data;
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