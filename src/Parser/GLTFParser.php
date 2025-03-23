<?php

namespace MediaWiki\Extension\GLTFHandler\Parser;

use InvalidArgumentException;
use JsonException;
use function array_column;
use function array_diff_key;
use function array_fill;
use function array_keys;
use function array_slice;
use function array_splice;
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
use function getimagesizefromstring;
use function gettype;
use function implode;
use function in_array;
use function is_array;
use function is_file;
use function is_float;
use function is_infinite;
use function is_int;
use function is_nan;
use function json_decode;
use function json_encode;
use function max;
use function min;
use function pack;
use function pathinfo;
use function str_repeat;
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

	public const ACCESSOR_SIZES = [
		"SCALAR" => 1,
		"VEC2" => 2,
		"VEC3" => 3,
		"VEC4" => 4,
		"MAT2" => 2 * 2,
		"MAT3" => 3 * 3,
		"MAT4" => 4 * 4
	];

	/** @var int pertains to glTF header - this value is same as unpack("V", "glTF")[1] */
	public const HEADER_MAGIC = 0x46546C67;
	/** @var int type of GLB chunk - contains JSON data in payload */
	public const CHUNK_JSON = 0x4E4F534A;
	/** @var int type of GLB chunk - contains a binary blob in payload */
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

	/** @var array<int, GLTFComponentType> */
	public array $component_registry;

	public string $directory;
	public bool $binary;
	public int $version;
	public int $length;
	public array $properties;

	/** @var list<GLTFBuffer> */
	public array $buffers;

	/** @var list<GLTFBufferView> */
	public array $buffer_views;

	/** @var list<int|string> */
	public array $image_buffers; // (int) index of buffer_views, or (string) raw image buffer

	/** @var list<array{GLTFComponentType, int, int, list<int|float>}> */
	public array $accessor_values;

	/**
	 * Parses the structure of a GLB or GLTF file.
	 *
	 * @param string $path path to a GLB or GLTF file
	 * @param int-mask-of<self::FLAG_*> $flags
	 */
	public function __construct(string $path, int $flags = self::FLAG_RESOLVE_LOCAL_URI){
		$this->component_registry = GLTFComponentType::registry();

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
					$buffer = $this->readChunkGlb($resource, self::CHUNK_BIN);
					$buffers = [new GLTFBuffer($buffer, strlen($buffer), null, null, [], [])];
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

		// TODO: check what the difference between version 1 and version 2 is.
		// this will likely impact self::computeModelDimensions() and maybe self::getMetadata().
		$version === 2 || throw new InvalidArgumentException("Unsupported GLB version ({$version}), expected version 2", self::ERR_UNSUPPORTED_VERSION);

		[$buffers, $buffer_views] = $this->processBuffers($properties, $directory, $binary, $buffers, $flags);
		[$accessor_values, $image_buffers] = $this->processProperties($properties, $directory, $buffers, $buffer_views, $flags);

		$this->directory = $directory;
		$this->binary = $binary;
		$this->version = $version;
		$this->length = $length;
		$this->properties = $properties;
		$this->buffers = $buffers;
		$this->buffer_views = $buffer_views;
		$this->accessor_values = $accessor_values;
		$this->image_buffers = $image_buffers;
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
	 * Validates schema of GLTF buffers and buffer views, and returns them as object representations.
	 *
	 * TODO: Schema validation ought to be defined in a separate resource file, preferably one that adheres to
	 * json-schema.org.
	 *
	 * @param array $properties
	 * @param string $directory
	 * @param bool $binary
	 * @param list<GLTFBuffer> $buffers
	 * @param int-mask-of<self::FLAG_*> $flags
	 * @return array{list<GLTFBuffer>, list<GLTFBufferView>}
	 */
	public function processBuffers(array $properties, string $directory, bool $binary, array $buffers = [], int $flags = 0) : array{
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
				if(!$binary){
					$value = $this->resolveURI($entry["uri"], $relative_dir, $resolve_remote, $entry["byteLength"]);
					$buffers[$index] = new GLTFBuffer($value, $entry["byteLength"], $entry["uri"], $entry["name"] ?? null, $entry["extensions"] ?? [], $entry["extras"] ?? []);
				}else{
					$index === 0 || throw new InvalidArgumentException("Binary specification must define only one buffer, got a buffer at index {$index}", self::ERR_INVALID_SCHEMA);
					isset($buffers[$index]) || throw new InvalidArgumentException("Binary specification must pre-define buffers", self::ERR_INVALID_SCHEMA);
					$buffers[$index]->value ?? throw new InvalidArgumentException("Expected binary specification buffer to be resolved, got unresolved {$buffers[$index]->uri}", self::ERR_INVALID_SCHEMA);
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
				$buffer_views[] = new GLTFBufferView($entry["buffer"], $entry["byteLength"], $entry["byteOffset"] ?? 0, $entry["byteStride"] ?? null, $entry["target"] ?? null, $entry["name"] ?? null, $entry["extensions"] ?? [], $entry["extras"] ?? []);
			}
		}

		// integrity check
		foreach($buffer_views as $index => $view){
			isset($buffers[$view->buffer]) || throw new InvalidArgumentException("Buffer at index {$index} points to an undefined buffer index {$view->buffer} (have n_buffers=" . count($buffers) . ")");
		}
		return [$buffers, $buffer_views];
	}

	/**
	 * Validates schema of GLTF properties.
	 *
	 * TODO: Schema validation ought to be defined in a separate resource file, preferably one that adheres to
	 * json-schema.org.
	 *
	 * @param array $properties
	 * @param string $directory
	 * @param list<GLTFBuffer> $buffers
	 * @param list<GLTFBufferView> $buffer_views
	 * @param int-mask-of<self::FLAG_*> $flags
	 * @return array
	 */
	public function processProperties(array $properties, string $directory, array $buffers = [], array $buffer_views = [], int $flags = 0) : array{
		$relative_dir = ($flags & self::FLAG_RESOLVE_LOCAL_URI) > 0 ? $directory : null;
		$resolve_remote = ($flags & self::FLAG_RESOLVE_REMOTE_URI) > 0;

		// validate accessors
		$accessor_values = [];
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

			$component_type = $this->component_registry[$entry["componentType"]] ?? throw new InvalidArgumentException("Expected 'componentType' to be one of: " . implode(", ", array_keys($this->component_registry)) . ", got {$entry["componentType"]}", self::ERR_INVALID_SCHEMA);
			!isset($entry["byteOffset"]) || $entry["byteOffset"] >= 0 || throw new InvalidArgumentException("Expected 'sparse.count' >= 0, got {$entry["byteOffset"]}", self::ERR_INVALID_SCHEMA);
			if(isset($entry["normalized"]) && $entry["normalized"] && in_array($component_type->code, [GLTFComponentType::FLOAT, GLTFComponentType::UNSIGNED_INT], true)){
				throw new InvalidArgumentException("Expected 'normalized' to be false when component type is {$component_type->name}", self::ERR_INVALID_SCHEMA);
			}
			$component_count = self::ACCESSOR_SIZES[$entry["type"]] ?? throw new InvalidArgumentException("Expected accessor type to be one of: " . implode(", ", array_keys(self::ACCESSOR_SIZES)) . ", got '{$entry["type"]}'", self::ERR_INVALID_SCHEMA);

			// validate min, max
			if(isset($entry["min"]) || isset($entry["max"])){
				$types = array_fill(0, $component_count, 0.0);
				self::validateJsonSchema($entry, ["min" => $types, "max" => $types]);
				foreach([...$entry["min"], ...$entry["max"]] as $value){
					!is_infinite($value) || throw new InvalidArgumentException("Invalid value encountered (inf) in accessor entry", self::ERR_INVALID_SCHEMA);
					!is_nan($value) || throw new InvalidArgumentException("Invalid value encountered (inf) in accessor entry", self::ERR_INVALID_SCHEMA);
					($value >= $component_type->min && $value <= $component_type->max) || throw new InvalidArgumentException("Expected accessor entry to fall in range [{$component_type->min}, {$component_type->max}], got {$value}", self::ERR_INVALID_SCHEMA);
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
				$sparse_component_type = $this->component_registry[$sparse["indices"]["componentType"]] ?? throw new InvalidArgumentException("Expected 'componentType' to be one of: " . implode(", ", array_keys($this->component_registry)) . ", got {$sparse["indices"]["componentType"]}", self::ERR_INVALID_SCHEMA);
				$index = $sparse["indices"]["bufferView"];
				isset($buffer_views[$index]) || throw new InvalidArgumentException("Expected 'bufferView' >= 0, < " . count($buffer_views) . ", got {$index}", self::ERR_INVALID_SCHEMA);
				$view = $buffer_views[$index];
				$view->byte_stride === null || throw new InvalidArgumentException("Expected 'byteStride' of buffer view ({$index}) accessed from sparse indices to be undefined", self::ERR_INVALID_SCHEMA);
				$view->target === null || throw new InvalidArgumentException("Expected 'target' of buffer view ({$index}) accessed from sparse indices to be undefined", self::ERR_INVALID_SCHEMA);

				// validate if buffer view and the optional byteOffset align to the componentType byte length
				$offset_accessor = $sparse["indices"]["byteOffset"] ?? 0;
				$offset_view = $view->byte_offset;
				($offset_accessor + $offset_view) % $sparse_component_type->size === 0 || throw new InvalidArgumentException("Expected accessor offset ({$offset_accessor}) + view offset ({$offset_view}) to be a multiple of size of component '{$sparse_component_type->name}' ({$sparse_component_type->size})", self::ERR_INVALID_SCHEMA);

				$buffers[$view->buffer]->value ?? throw new InvalidArgumentException("Sparse indices points to an unresolved buffer ({$view->buffer}): {$buffers[$view->buffer]->uri}", self::ERR_INVALID_SCHEMA);
				$indices = unpack("{$sparse_component_type->format}{$sparse["count"]}/", $buffers[$view->buffer]->value, $offset_accessor + $offset_view);
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
				$view->byte_stride === null || throw new InvalidArgumentException("Expected 'byteStride' of buffer view ({$index}) accessed from sparse values to be undefined", self::ERR_INVALID_SCHEMA);
				$view->target === null || throw new InvalidArgumentException("Expected 'target' of buffer view ({$index}) accessed from sparse values to be undefined", self::ERR_INVALID_SCHEMA);

				// validate if buffer view and the optional byteOffset align to the componentType byte length
				$offset_accessor = $sparse["values"]["byteOffset"] ?? 0;
				$offset_view = $view->byte_offset ?? 0;
				($offset_accessor + $offset_view) % $component_type->size === 0 || throw new InvalidArgumentException("Expected accessor offset ({$offset_accessor}) + view offset ({$offset_view}) to be a multiple of size of component '{$component_type->name}' ({$component_type->size})", self::ERR_INVALID_SCHEMA);

				$buffers[$view->buffer]->value ?? throw new InvalidArgumentException("Sparse values points to an unresolved buffer ({$view->buffer}): {$buffers[$view->buffer]->uri}", self::ERR_INVALID_SCHEMA);
				$values = unpack($component_type->format . ($sparse["count"] * $component_count) . "/", $buffers[$view->buffer]->value, $offset_accessor + $offset_view);
				$values = array_values($values);
				$sparse = [$indices, $values];
			}else{
				// no sparse substitutions needed
				$sparse = [[], []];
			}

			if(isset($entry["bufferView"])){
				$index = $entry["bufferView"];
				isset($buffer_views[$index]) || throw new InvalidArgumentException("Expected 'bufferView' >= 0, < " . count($buffer_views) . ", got {$index}", self::ERR_INVALID_SCHEMA);
				$view = $buffer_views[$index];
				$buffers[$view->buffer]->value ?? throw new InvalidArgumentException("Accessor points to an unresolved buffer ({$view->buffer}): {$buffers[$view->buffer]->uri}", self::ERR_INVALID_SCHEMA);

				// validate if buffer view and the optional byteOffset align to the componentType byte length
				$offset_accessor = $entry["byteOffset"] ?? 0;
				$offset_view = $view->byte_offset;
				($offset_accessor + $offset_view) % $component_type->size === 0 || throw new InvalidArgumentException("Expected accessor offset ({$offset_accessor}) + view offset ({$offset_view}) to be a multiple of size of accessor component '{$component_type->name}' ({$component_type->size})", self::ERR_INVALID_SCHEMA);
				$view->byte_stride === null || $view->byte_stride % $component_type->size === 0 || throw new InvalidArgumentException("Expected byte stride of view ({$view->byte_stride}) to be a multiple of size of accessor component '{$component_type->name}' ({$component_type->size})", self::ERR_INVALID_SCHEMA);

				$EFFECTIVE_BYTE_STRIDE = $view->byte_stride ?? 0;
				$fitness = $offset_accessor + $EFFECTIVE_BYTE_STRIDE * ($entry["count"] - 1) + $component_type->size * $component_count;
				$fitness <= $view->byte_length || throw new InvalidArgumentException("Expected accessor fitness ({$fitness}) <= buffer view length ({$view->byte_length})", self::ERR_INVALID_SCHEMA);

				$values = unpack($component_type->format . ($entry["count"] * $component_count) . "/", $buffers[$view->buffer]->value, $offset_accessor + $offset_view);
				$values = array_values($values);
			}else{
				$values = array_fill(0, $entry["count"], 0);
			}

			// perform sparse substitution for $values
			foreach($sparse[0] as $index => $index_replace){
				$offset = $index_replace * $component_count;
				$replacement = array_slice($sparse[1], $index * $component_count, $component_count);
				array_splice($values, $offset, $component_count, $replacement);
			}

			$accessor_values[] = [$component_type, $component_count, $entry["count"], $values];
		}

		self::validateJsonSchema($properties["asset"], [
			"version" => "", "copyright" => "", "generator" => "", "minVersion" => "", "extensions" => [], "extras" => []
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
					isset($buffers[$view->buffer]) || throw new InvalidArgumentException("Image buffer view at index {$index} points to an undefined buffer index {$view->buffer} (have n_buffers=" . count($buffers) . ")");
					$buffers[$view->buffer]->value ?? throw new InvalidArgumentException("Image points to an unresolved buffer ({$view->buffer}): {$buffers[$view->buffer]->uri}", self::ERR_INVALID_SCHEMA);
					$image_buffers[] = $index;
				}else{
					$image_buffers[] = $this->resolveURI($entry["uri"], $relative_dir, $resolve_remote);
				}
			}
		}
		return [$accessor_values, $image_buffers];
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
	 * @return string the returned raw buffer (byte array)
	 */
	public function resolveURI(string $uri, ?string $base_directory, bool $resolve_remote, ?int $length = null) : string{
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
			$resolve_remote || throw new InvalidArgumentException("Remote resolution is not allowed", self::ERR_URI_RESOLUTION_REMOTE);
			// TODO: Validate return type, HTTP response code
			$data = file_get_contents($uri, length: $length);
			$data !== false || throw new InvalidArgumentException("Remote resolution failed for uri: {$uri}", self::ERR_URI_RESOLUTION_REMOTE);
			return $data;
		}
		$base_directory ?? throw new InvalidArgumentException("Local resolution is not allowed", self::ERR_URI_RESOLUTION_LOCAL);
		$path = $base_directory . DIRECTORY_SEPARATOR . urldecode($uri);
		(is_file($path) && file_exists($path)) || throw new InvalidArgumentException("File not found: {$path}", self::ERR_URI_RESOLUTION_LOCAL);
		$ext = pathinfo($path, PATHINFO_EXTENSION);
		in_array($ext, self::ALLOWED_URI_EXTENSIONS, true) || throw new InvalidArgumentException("Expected file extension to be one of: " . implode(", ", self::ALLOWED_URI_EXTENSIONS) . ", got {$ext}", self::ERR_URI_RESOLUTION_LOCAL);
		$data = file_get_contents($path, length: $length);
		$data !== false || throw new InvalidArgumentException("Local resolution failed for uri: {$uri}", self::ERR_URI_RESOLUTION_LOCAL);
		return $data;
	}

	public function calculateNodeTransformationMatrix(array $node) : array{
		// implemented based on notes from:
		// https://github.com/KhronosGroup/glTF-Tutorials/blob/bdc3640aad36ec9fe2c20fa262488fab5842f06b/gltfTutorial/gltfTutorial_004_ScenesNodes.md
		if(isset($node["matrix"])){
			return Matrix::transpose($node["matrix"]);
		}

		// no node found: construct a transformation matrix from TRS values
		$t = $node["translation"] ?? [0, 0, 0];
		$r = $node["rotation"] ?? [0, 0, 0, 1];
		$s = $node["scale"] ?? [1, 1, 1];

		$T = [
			1, 0, 0, $t[0],
			0, 1, 0, $t[1],
			0, 0, 1, $t[2],
			0, 0, 0, 1
		];

		$R = Matrix::quaternionToRotation($r);

		$S = [
			$s[0], 0, 0, 0,
			0, $s[1], 0, 0,
			0, 0, $s[2], 0,
			0, 0, 0, 1
		];
		return Matrix::multiply($T, Matrix::multiply($R, $S, 4), 4);
	}

	/**
	 * Computes length of X, Y, and Z planes of the model.
	 *
	 * @return array{float, float, float}|null a tuple of lengths of X, Y, and Z planes respectively, or null if the
	 * model dimensions could not be inferred.
	 */
	public function computeModelDimensions() : ?array{
		$values = [];
		$nodes = [];
		$stack = [];
		$offset = 0;
		foreach($this->properties["scenes"] as $scene){
			foreach($scene["nodes"] as $node){
				$stack[] = [$node, [Matrix::IDENTITY4]];
			}
		}

		while(isset($stack[$offset])){
			[$index, $global_transformations] = $stack[$offset++];
			$node = $this->properties["nodes"][$index];
			$nodes[$index] = true;

			$transformations = $global_transformations;
			$transformations[] = $this->calculateNodeTransformationMatrix($node); // append local transformation to globals

			if(isset($node["children"])){
				foreach($node["children"] as $child){
					if(!isset($nodes[$child])){
						$stack[] = [$child, $transformations];
					}
				}
			}

			if(!isset($node["mesh"])){
				continue;
			}

			$transformation = $transformations[0];
			for($i = 1, $j = count($transformations); $i < $j; $i++){
				$transformation = Matrix::multiply($transformation, $transformations[$i], 4);
			}

			$mesh = $this->properties["meshes"][$node["mesh"]];
			foreach($mesh["primitives"] as $primitive){
				if(!isset($primitive["attributes"]["POSITION"])){
					continue;
				}
				if(isset($primitive["extensions"]) && count($primitive["extensions"]) > 0){
					// extensions like KHR_draco_mesh_compression require further handling, otherwise we end up with
					// incorrect accessor values.
					continue;
				}
				[$comp_type, $comp_size, $n_comp, $comp_values] = $this->accessor_values[$primitive["attributes"]["POSITION"]];
				for($i = 0, $j = count($comp_values); $i < $j; $i += $comp_size){
					$x = $comp_values[$i];
					$y = $comp_values[$i + 1];
					$z = $comp_values[$i + 2];
					$values[] = [
						($transformation[0] * $x) + ($transformation[1] * $y) + ($transformation[2] * $z) + $transformation[3],
						($transformation[4] * $x) + ($transformation[5] * $y) + ($transformation[6] * $z) + $transformation[7],
						($transformation[8] * $x) + ($transformation[9] * $y) + ($transformation[10] * $z) + $transformation[11]
					];
				}
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

	/**
	 * Returns a raw GLB bytes representation of the glTF file. This is useful when generating a 'portable' glTF file is
	 * necessary. Resolved remote and local filesystem buffers and images are embedded directly.
	 *
	 * Example usage:
	 *   // convert GLTF to GLB (GLBs are always self-contained / embedded)
	 *   $parser = new GLTFParser("model2.gltf");
	 *   $contents = $parser->exportBinary();
	 *   file_put_contents("model2.glb", $contents);
	 *
	 * @return string
	 */
	public function exportBinary() : string{
		foreach($this->buffers as $index => $buffer){
			$buffer->value ?? throw new InvalidArgumentException("Buffer at index {$index} is unresolved (" . substr($buffer->uri, 0, 64) . ")");
		}

		// process properties
		$properties = $this->properties;
		if(isset($properties["images"])){
			foreach($properties["images"] as $index => $property){
				unset($properties["images"][$index]["uri"]);
			}
		}

		// process buffer
		// -- collect all buffers and concatenate into a single blob.
		// -- offset corresponding buffer views and image buffers
		$buffers = [];
		$offset = 0;
		foreach($this->buffers as $ib => $buffer){
			$buffers[] = $buffer->value;
			$byte_offset = $offset;
			foreach($properties["bufferViews"] as $iv => $view){
				if($ib === 0 || $view["buffer"] !== $ib){
					continue;
				}
				$view["buffer"] = 0;
				$view["byteOffset"] ??= 0;
				$view["byteOffset"] += $byte_offset;
				$properties["bufferViews"][$iv] = $view;
			}
			$offset += strlen($buffer->value);
		}

		$iv = count($this->buffer_views);
		foreach($this->image_buffers as $ib => $buffer){
			if(is_int($buffer)){
				continue; // we already translated buffer views
			}

			// introduce a new buffer view referencing the position of this image
			// we need to ensure length of buffer is a multiple of 4 per glTF spec
			// so we pad it with zero bytes
			$buffer .= str_repeat("\0", 4 - (strlen($buffer) % 4));
			$buffers[] = $buffer;
			$properties["images"][$ib]["bufferView"] = $iv;
			if(!isset($properties["images"][$ib]["mimeType"])){
				$image_size = getimagesizefromstring($buffer);
				($image_size !== false && isset($image_size["mime"])) || throw new InvalidArgumentException("Failed to retrieve mime for image at index {$ib}");
				$properties["images"][$ib]["mimeType"] = $image_size["mime"];
			}
			$properties["bufferViews"][$iv] = ["buffer" => 0, "byteOffset" => $offset, "byteLength" => strlen($buffer)];
			$iv++;
			$offset += strlen($buffer);
		}

		if(count($buffers) > 0){
			$chunk1 = implode($buffers);
			$properties["buffers"] = [["byteLength" => strlen($chunk1)]];
		}else{
			$chunk1 = null;
			unset($properties["buffers"]);
		}

		try{
			$chunk0 = json_encode($properties, JSON_THROW_ON_ERROR);
		}catch(JsonException $e){
			throw new InvalidArgumentException("Failed to encode chunk0: {$e->getMessage()}", 0, $e);
		}
		$data = [];
		$data[] = pack("V*", strlen($chunk0), self::CHUNK_JSON);
		$data[] = $chunk0;
		if($chunk1 !== null){
			$chunk1 = implode($buffers);
			$data[] = pack("V*", strlen($chunk1), self::CHUNK_BIN);
			$data[] = $chunk1;
		}
		$contents = implode($data);
		// write header
		return pack("V*", self::HEADER_MAGIC, $this->version, strlen($contents) + 12) . $contents;
	}
}