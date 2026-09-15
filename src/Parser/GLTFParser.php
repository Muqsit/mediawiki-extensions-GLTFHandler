<?php

namespace MediaWiki\Extension\GLTFHandler\Parser;

use finfo;
use InvalidArgumentException;
use JsonException;
use function array_fill;
use function array_keys;
use function array_push;
use function array_values;
use function base64_decode;
use function bin2hex;
use function count;
use function dirname;
use function explode;
use function fclose;
use function file_exists;
use function file_get_contents;
use function filesize;
use function fopen;
use function fread;
use function fseek;
use function ftell;
use function gettype;
use function implode;
use function in_array;
use function intdiv;
use function is_array;
use function is_file;
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
use function strtolower;
use function substr;
use function unpack;
use function urldecode;
use const DIRECTORY_SEPARATOR;
use const FILEINFO_MIME_TYPE;
use const JSON_THROW_ON_ERROR;
use const PATHINFO_EXTENSION;
use const SEEK_CUR;

final class GLTFParser {

	public const ALLOWED_MIME_URI_BUFFER = [
		"application/gltf-buffer",
		"application/octet-stream",
		// Allow all .bin files.
		[ null, "bin" ],
	];

	public const ALLOWED_MIME_URI_IMAGE = [
		// Allow application/octet-stream only if the file has a .ktx2 extension.
		[ "application/octet-stream", "ktx2" ],
		"image/jpeg",
		"image/jpg",
		"image/png",
		"image/webp"
	];

	public const ACCESSOR_SIZES = [
		"SCALAR" => 1,
		"VEC2" => 2,
		"VEC3" => 3,
		"VEC4" => 4,
		"MAT2" => 2 * 2,
		"MAT3" => 3 * 3,
		"MAT4" => 4 * 4
	];
	public const MAX_ACCESSOR_VALUES = 250_000;
	public const MAX_INPUT_BYTES = 100 * 1024 * 1024;
	public const MAX_JSON_BYTES = 16 * 1024 * 1024;
	public const MAX_RESOLVED_RESOURCE_BYTES = 100 * 1024 * 1024;
	public const MAX_RESOLVED_RESOURCES = 1_024;
	public const MAX_SCENE_NODE_REFERENCES = 100_000;
	public const MAX_TRANSFORMED_VERTICES = 1_000_000;

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
	/** @var int input file could not be opened or read. not thrown when accessing local filesystem URIs */
	public const ERR_IO = 100002;
	/** @var int a URI to an embedded resource (e.g., data:application/octet-stream) could not successfully be read */
	public const ERR_URI_RESOLUTION_EMBEDDED = 100003;
	/** @var int a URI to a local resource (i.e., a local file) could not successfully be read */
	public const ERR_URI_RESOLUTION_LOCAL = 100004;
	/** @var int a URI to a remote resource (e.g., https://...) could not successfully be read */
	public const ERR_URI_RESOLUTION_REMOTE = 100005;

	/**
	 * Infer file format (GLTF vs. GLB) by reading the first 4 bytes from the file.
	 *
	 * @param string $path
	 * @return bool whether file is binary (.glb)
	 */
	public static function inferBinary( string $path ): bool {
		$resource = fopen( $path, "rb" );
		$resource !== false || throw new InvalidArgumentException( "Could not open file: {$path}", self::ERR_IO );
		try {
			$structure = fread( $resource, 4 );
			$structure !== false || throw new InvalidArgumentException( "Could not read file header: {$path}", self::ERR_IO );
			strlen( $structure ) === 4 || throw new InvalidArgumentException( "Could not read complete file header: {$path}", self::ERR_IO );
			$type = unpack( "V", $structure )[1];
			return $type === self::HEADER_MAGIC;
		} finally {
			fclose( $resource );
		}
	}

	/**
	 * Returns validated array from JSON string.
	 *
	 * @param string $data a JSON string
	 * @return array
	 */
	private static function decodeJsonArray( string $data ): array {
		try {
			// GLTF nodes are indeed a tree structure but are represented as flat lists. A depth limit of 16 instead of
			// PHP's default 512 limit should be well more than sufficient for most if not all valid GLTF files.
			$result = json_decode( $data, true, 16, JSON_THROW_ON_ERROR );
		} catch ( JsonException $e ) {
			throw new InvalidArgumentException( "Failed to decode JSON data: {$e->getMessage()}", 0, $e );
		}
		is_array( $result ) || throw new InvalidArgumentException( "Expected JSON data to be of type array, got " . gettype( $result ) );
		return $result;
	}

	public finfo $mime_checker;
	public string $path;
	public string $directory;
	public bool $binary;
	public int $version;
	public int $length;
	public array $properties;

	/** @var list<GLTFBuffer> */
	public array $buffers;

	/** @var list<GLTFBufferView> */
	public array $buffer_views;

	/**
	 * First element is a buffer view index or raw image buffer; second is its MIME type.
	 * @var list<array{int|string, string}>
	 */
	public array $image_buffers;

	/** @var list<array{GLTFComponentType, int, int, list<int|float>}> */
	public array $accessor_values;

	public ?string $copyright;
	public ?string $generator;
	private int $input_size;
	private int $resolved_resource_bytes = 0;
	private int $resolved_resources = 0;

	/**
	 * Parses the structure of a GLB or GLTF file.
	 *
	 * @param string $path path to a GLB or GLTF file
	 * @param int $flags Bitmask of self::FLAG_* constants
	 * @param int $max_accessor_values
	 * @param int $max_resolved_resource_bytes
	 * @param int $max_resolved_resources
	 */
	public function __construct( string $path, int $flags = self::FLAG_RESOLVE_LOCAL_URI, int $max_accessor_values = self::MAX_ACCESSOR_VALUES, private int $max_resolved_resource_bytes = self::MAX_RESOLVED_RESOURCE_BYTES, private int $max_resolved_resources = self::MAX_RESOLVED_RESOURCES ) {
		$this->mime_checker = new finfo( FILEINFO_MIME_TYPE );
		$this->path = $path;
		$input_size = filesize( $path );
		$input_size !== false || throw new InvalidArgumentException( "Could not determine file size: {$path}", self::ERR_IO );
		$input_size <= self::MAX_INPUT_BYTES || throw new InvalidArgumentException( "Input exceeds parser limit of " . self::MAX_INPUT_BYTES . " bytes", self::ERR_INVALID_SCHEMA );
		$this->input_size = $input_size;

		// Needed for buffer resolution when URIs are encountered.
		$directory = dirname( $path );
		$binary = match ( true ) {
			( $flags & self::FLAG_TYPE_GLB ) > 0 => true,
			( $flags & self::FLAG_TYPE_GLTF ) > 0 => false,
			default => self::inferBinary( $path )
		};
		$version = 0;
		$length = 0;
		$properties = [];
		$buffers = [];
		if ( $binary ) {
			$resource = fopen( $path, "rb" );
			$resource !== false || throw new InvalidArgumentException( "Could not open file: {$path}", self::ERR_IO );
			try {
				[ $version, $length ] = $this->readHeaderGlb( $resource );
				$properties = $this->readChunkGlb( $resource, self::CHUNK_JSON );
				is_array( $properties ) || throw new InvalidArgumentException( "Expected GLB JSON chunk", self::ERR_INVALID_SCHEMA );
				if ( ftell( $resource ) < $this->input_size ) {
					// a GLB file has only one buffer entry
					$buffer = $this->readChunkGlb( $resource, self::CHUNK_BIN );
					is_string( $buffer ) || throw new InvalidArgumentException( "Expected GLB binary chunk", self::ERR_INVALID_SCHEMA );
					$buffers = [ new GLTFBuffer( $buffer, strlen( $buffer ), null, null, [], [] ) ];
				}
			} finally {
				fclose( $resource );
			}
		} else {
			$input_size <= self::MAX_JSON_BYTES || throw new InvalidArgumentException( "JSON exceeds parser limit of " . self::MAX_JSON_BYTES . " bytes", self::ERR_INVALID_SCHEMA );
			$contents = file_get_contents( $path );
			$contents !== false || throw new InvalidArgumentException( "Could not read file: {$path}", self::ERR_IO );
			$length = strlen( $contents );
			$contents = self::decodeJsonArray( $contents );
			$version = (int)$contents["asset"]["version"];
			$properties = $contents;
		}

		// TODO: check what the difference between version 1 and version 2 is.
		// this will likely impact self::computeModelDimensions() and maybe self::getMetadata().
		$version === 2 || throw new InvalidArgumentException( "Unsupported GLB version ({$version}), expected version 2", self::ERR_UNSUPPORTED_VERSION );

		$this->validateProperties( $properties );
		[ $buffers, $buffer_views ] = $this->processBuffers( $properties, $directory, $binary, $buffers, $flags );
		$image_buffers = $this->processImages( $properties, $directory, $buffers, $buffer_views, $flags );
		$accessor_values = $this->processAccessors( $properties, $buffers, $buffer_views, $max_accessor_values );
		foreach ( $properties["meshes"] ?? [] as $mesh ) {
			foreach ( $mesh["primitives"] ?? [] as $primitive ) {
				if ( isset( $primitive["indices"] ) ) {
					$accessor = $accessor_values[$primitive["indices"]] ?? throw new InvalidArgumentException( "Primitive points to an undefined indices accessor", self::ERR_INVALID_SCHEMA );
					!in_array( $accessor[0]->max, $accessor[3], true ) || throw new InvalidArgumentException( "Indices accessor contains a primitive restart value", self::ERR_INVALID_SCHEMA );
				}
			}
		}

		$this->directory = $directory;
		$this->binary = $binary;
		$this->version = $version;
		$this->length = $length;
		$this->properties = $properties;
		$this->buffers = $buffers;
		$this->buffer_views = $buffer_views;
		$this->accessor_values = $accessor_values;
		$this->image_buffers = $image_buffers;

		// for easy access
		$this->copyright = $this->properties["asset"]["copyright"] ?? null;
		$this->generator = $this->properties["asset"]["generator"] ?? null;
	}

	/**
	 * Reads a GLB header and returns version and length. 'Magic' is not returned, but is instead validated.
	 *
	 * @param resource $resource a file pointer to read the header from
	 * @return array{int, int} a tuple of version and length
	 */
	private function readHeaderGlb( $resource ): array {
		$header = fread( $resource, 12 );
		$header !== false || throw new InvalidArgumentException( "Could not read GLB header: {$this->path}", self::ERR_IO );
		strlen( $header ) === 12 || throw new InvalidArgumentException( "Could not read complete GLB header: {$this->path}", self::ERR_IO );
		$decoded = unpack( "V3h/", $header );
		$magic = $decoded["h1"];
		$version = $decoded["h2"];
		$length = $decoded["h3"];
		$magic === self::HEADER_MAGIC || throw new InvalidArgumentException( "Improperly formatted GLB header: Magic has unexpected value: " . bin2hex( $magic ) );
		$length === $this->input_size || throw new InvalidArgumentException( "GLB header length ({$length}) does not match file size ({$this->input_size})", self::ERR_INVALID_SCHEMA );
		return [ $version, $length ];
	}

	/**
	 * Reads a GLB chunk from the current file position.
	 *
	 * @param resource $resource a file pointer to read the chunk from
	 * @param int $expected_type An expected self::CHUNK_* constant
	 * @return array|string|null chunk data (array for JSON chunks, string for binary chunks, null for unknown chunks)
	 */
	public function readChunkGlb( $resource, int $expected_type ): array|string|null {
		$structure = fread( $resource, 8 );
		$structure !== false || throw new InvalidArgumentException( "Could not read GLB chunk header: {$this->path}", self::ERR_IO );
		strlen( $structure ) === 8 || throw new InvalidArgumentException( "Could not read complete GLB chunk header: {$this->path}", self::ERR_IO );
		$decoded = unpack( "V2s/", $structure );
		$length = $decoded["s1"];
		$type = $decoded["s2"];
		$type === $expected_type || throw new InvalidArgumentException( "Unexpected chunk type ({$type}), expected {$expected_type}" );
		$remaining = $this->input_size - ftell( $resource );
		$length <= $remaining || throw new InvalidArgumentException( "GLB chunk length ({$length}) exceeds remaining file size ({$remaining})", self::ERR_INVALID_SCHEMA );
		$type !== self::CHUNK_JSON || $length <= self::MAX_JSON_BYTES || throw new InvalidArgumentException( "JSON chunk exceeds parser limit of " . self::MAX_JSON_BYTES . " bytes", self::ERR_INVALID_SCHEMA );
		if ( $type === self::CHUNK_JSON ) {
			$data = $length === 0 ? "" : fread( $resource, $length );
			$data !== false || throw new InvalidArgumentException( "Could not read GLB JSON chunk: {$this->path}", self::ERR_IO );
			strlen( $data ) === $length || throw new InvalidArgumentException( "Could not read complete GLB JSON chunk: {$this->path}", self::ERR_IO );
			return self::decodeJsonArray( $data );
		}
		if ( $type === self::CHUNK_BIN ) {
			$data = $length === 0 ? "" : fread( $resource, $length );
			$data !== false || throw new InvalidArgumentException( "Could not read GLB binary chunk: {$this->path}", self::ERR_IO );
			strlen( $data ) === $length || throw new InvalidArgumentException( "Could not read complete GLB binary chunk: {$this->path}", self::ERR_IO );
			return $data;
		}
		// do not read into memory chunk of unknown types, only move offset to the end of chunk.
		// according to gltf spec: Client implementations MUST ignore chunks with unknown types to enable glTF
		// extensions to reference additional chunks with new types following the first two chunks.
		fseek( $resource, $length, SEEK_CUR );
		return null;
	}

	/**
	 * Validates schema of GLTF properties. This does not validate accessors, buffers, and images which are validated by
	 * other methods.
	 *
	 * @see GLTFParser::processAccessors()
	 * @see GLTFParser::processBuffers()
	 * @see GLTFParser::processImages()
	 *
	 * @param array $properties
	 */
	public function validateProperties( array $properties ): void {
		JSONSchema::validate( $properties, [
			"version" => "", "copyright" => "", "generator" => "", "minVersion" => "", "extensions" => [], "extras" => []
		], [ "asset" ], JSONSchema::FLAG_OPTIONAL | JSONSchema::FLAG_REPORT_UNKNOWN_KEYS );

		// validate animations
		$required_animations = [ "channels" => [], "samplers" => [] ];
		$optional_animations = [ "name" => "", "extensions" => [], "extras" => [] ];
		if ( isset( $properties["animations"] ) ) {
			foreach ( $properties["animations"] as $index => $entry ) {
				JSONSchema::validate( $properties, $required_animations, [ "animations", $index ] );
				JSONSchema::validate( $properties, $required_animations + $optional_animations, [ "animations", $index ], JSONSchema::FLAG_OPTIONAL | JSONSchema::FLAG_REPORT_UNKNOWN_KEYS );
			}
		}
	}

	/**
	 * Validates structure of "buffers" and "bufferViews" sections and returns a list of updated buffers and buffer
	 * views. For GLB, $buffers must be a list of 1 element.
	 *
	 * @param array $properties
	 * @param string $directory
	 * @param bool $binary
	 * @param list<GLTFBuffer> $buffers
	 * @param int $flags Bitmask of self::FLAG_* constants
	 * @return array{list<GLTFBuffer>, list<GLTFBufferView>}
	 */
	public function processBuffers( array $properties, string $directory, bool $binary, array $buffers, int $flags = 0 ): array {
		$relative_dir = ( $flags & self::FLAG_RESOLVE_LOCAL_URI ) > 0 ? $directory : null;
		$resolve_remote = ( $flags & self::FLAG_RESOLVE_REMOTE_URI ) > 0;

		$required = [
			"accessors" => [],
			"asset" => [ "version" => "" ]
		];
		$optional = [
			"buffers" => [], "bufferViews" => [], "materials" => [], "meshes" => [], "nodes" => [], "scene" => 0,
			"scenes" => [], "extensions" => [], "extensionsRequired" => [], "extensionsUsed" => [], "images" => [],
			"textures" => [], "cameras" => [], "animations" => [], "samplers" => [], "skins" => []
		];
		JSONSchema::validate( $properties, $required );
		JSONSchema::validate( $properties, $required + $optional, [], JSONSchema::FLAG_OPTIONAL | JSONSchema::FLAG_REPORT_UNKNOWN_KEYS | JSONSchema::FLAG_NO_NESTING );

		// validate buffers
		// -- buffers must be validated earliest because bufferViews relies on it
		$required_buffers = [ "byteLength" => 0 ];
		$optional_buffers = [ "name" => "", "extensions" => [], "extras" => [] ];
		if ( !$binary ) {
			count( $buffers ) === 0 || throw new InvalidArgumentException( "Supplied buffer array must be empty for non-binary specification, got " . count( $buffers ) . " entries", self::ERR_INVALID_SCHEMA );
			$required_buffers["uri"] = "";
		}
		if ( isset( $properties["buffers"] ) ) {
			foreach ( $properties["buffers"] as $index => $entry ) {
				JSONSchema::validate( $properties, $required_buffers, [ "buffers", $index ] );
				JSONSchema::validate( $properties, $required_buffers + $optional_buffers, [ "buffers", $index ], JSONSchema::FLAG_OPTIONAL | JSONSchema::FLAG_REPORT_UNKNOWN_KEYS );
				$entry["byteLength"] >= 1 || throw new InvalidArgumentException( "Expected 'byteLength' >= 1, got {$entry["byteLength"]}", self::ERR_INVALID_SCHEMA );
				if ( !$binary ) {
					[ $value, $mime ] = $this->resolveURI( $entry["uri"], $relative_dir, $resolve_remote, $entry["byteLength"], self::ALLOWED_MIME_URI_BUFFER );
					$buffers[$index] = new GLTFBuffer( $value, $entry["byteLength"], $entry["uri"], $entry["name"] ?? null, $entry["extensions"] ?? [], $entry["extras"] ?? [] );
				} else {
					$index === 0 || throw new InvalidArgumentException( "Binary specification must define only one buffer, got a buffer at index {$index}", self::ERR_INVALID_SCHEMA );
					isset( $buffers[$index] ) || throw new InvalidArgumentException( "Binary specification must pre-define buffers", self::ERR_INVALID_SCHEMA );
					$buffers[$index]->value ?? throw new InvalidArgumentException( "Expected binary specification buffer to be resolved, got unresolved {$buffers[$index]->uri}", self::ERR_INVALID_SCHEMA );
					$actual_length = strlen( $buffers[$index]->value );
					$actual_length >= $entry["byteLength"] || throw new InvalidArgumentException( "GLB buffer length ({$actual_length}) does not match declared length ({$entry["byteLength"]})", self::ERR_INVALID_SCHEMA );
					$actual_length <= $entry["byteLength"] + 3 || throw new InvalidArgumentException( "GLB buffer length ({$actual_length}) does not match declared length ({$entry["byteLength"]})", self::ERR_INVALID_SCHEMA );
					$buffers[$index]->byte_length = $entry["byteLength"];
				}
			}
		}

		// validate bufferViews
		// -- buffer views need to be validated early because 'accessors' and 'images' rely on them
		$required_buffer_views = [ "buffer" => 0, "byteLength" => 0 ];
		$optional_buffer_views = [ "byteOffset" => 0, "byteStride" => 0, "target" => 0, "name" => "", "extensions" => [], "extras" => [] ];
		$buffer_views = [];
		if ( isset( $properties["bufferViews"] ) ) {
			foreach ( $properties["bufferViews"] as $index => $entry ) {
				JSONSchema::validate( $properties, $required_buffer_views, [ "bufferViews", $index ] );
				JSONSchema::validate( $properties, $required_buffer_views + $optional_buffer_views, [ "bufferViews", $index ], JSONSchema::FLAG_OPTIONAL | JSONSchema::FLAG_REPORT_UNKNOWN_KEYS );
				$buffer_views[] = new GLTFBufferView( $entry["buffer"], $entry["byteLength"], $entry["byteOffset"] ?? 0, $entry["byteStride"] ?? null, $entry["target"] ?? null, $entry["name"] ?? null, $entry["extensions"] ?? [], $entry["extras"] ?? [] );
			}
		}

		// integrity check
		foreach ( $buffer_views as $index => $view ) {
			isset( $buffers[$view->buffer] ) || throw new InvalidArgumentException( "Buffer at index {$index} points to an undefined buffer index {$view->buffer} (have n_buffers=" . count( $buffers ) . ")" );
			$view->byte_offset <= $buffers[$view->buffer]->byte_length - $view->byte_length || throw new InvalidArgumentException( "Buffer view at index {$index} exceeds buffer length", self::ERR_INVALID_SCHEMA );
		}
		return [ $buffers, $buffer_views ];
	}

	/**
	 * Validates structure of "images" section and returns a list of processed image data.
	 *
	 * @param array $properties
	 * @param string $directory
	 * @param list<GLTFBuffer> $buffers
	 * @param list<GLTFBufferView> $buffer_views
	 * @param int $flags Bitmask of self::FLAG_* constants
	 * @return list<array{int|string, string}> a list of processed image data. The element is array{int, string} for
	 * images that reference a buffer view ([0] is the index of a buffer view). The element is array{string, string} for
	 * images that reference a URI resource ([0] is the binary image data). [1] is the mime type (e.g., image/png).
	 */
	public function processImages( array $properties, string $directory, array $buffers, array $buffer_views, int $flags = 0 ): array {
		if ( !isset( $properties["images"] ) ) {
			return [];
		}

		$relative_dir = ( $flags & self::FLAG_RESOLVE_LOCAL_URI ) > 0 ? $directory : null;
		$resolve_remote = ( $flags & self::FLAG_RESOLVE_REMOTE_URI ) > 0;
		$image_buffers = [];

		$optional_images = [ "uri" => "", "mimeType" => "", "bufferView" => 0, "name" => "", "extensions" => [], "extras" => [] ];
		foreach ( $properties["images"] as $index => $entry ) {
			JSONSchema::validate( $properties, $optional_images, [ "images", $index ], JSONSchema::FLAG_OPTIONAL | JSONSchema::FLAG_REPORT_UNKNOWN_KEYS );
			!( isset( $entry["uri"] ) && isset( $entry["bufferView"] ) ) || throw new InvalidArgumentException( "Expected images to contain one of 'uri' or 'bufferView', got both", self::ERR_INVALID_SCHEMA );
			isset( $entry["uri"] ) || isset( $entry["bufferView"] ) || throw new InvalidArgumentException( "Expected images to contain one of 'uri' or 'bufferView', got neither", self::ERR_INVALID_SCHEMA );
			if ( isset( $entry["bufferView"] ) ) {
				$entry["bufferView"] >= 0 || throw new InvalidArgumentException( "Expected 'bufferView' >= 0, got {$entry["bufferView"]}", self::ERR_INVALID_SCHEMA );
				isset( $entry["mimeType"] ) || throw new InvalidArgumentException( "Expected 'mimeType' to be defined when 'bufferView' is defined", self::ERR_INVALID_SCHEMA );
				$view_index = $entry["bufferView"];
				isset( $buffer_views[$view_index] ) || throw new InvalidArgumentException( "Expected 'bufferView' index to be valid (< " . count( $buffer_views ) . "), got {$view_index}", self::ERR_INVALID_SCHEMA );
				$view = $buffer_views[$view_index];
				isset( $buffers[$view->buffer] ) || throw new InvalidArgumentException( "Image buffer view at index {$view_index} points to an undefined buffer index {$view->buffer} (have n_buffers=" . count( $buffers ) . ")" );
				$buffers[$view->buffer]->value ?? throw new InvalidArgumentException( "Image points to an unresolved buffer ({$view->buffer}): {$buffers[$view->buffer]->uri}", self::ERR_INVALID_SCHEMA );
				$image_buffers[] = [ $view_index, $entry["mimeType"] ];
			} else {
				[ $buffer, $mime ] = $this->resolveURI( $entry["uri"], $relative_dir, $resolve_remote, null, self::ALLOWED_MIME_URI_IMAGE );
				if ( isset( $entry["mimeType"] ) ) {
					$mime = $entry["mimeType"];
				}
				$image_buffers[] = [ $buffer, $mime ];
			}
		}
		return $image_buffers;
	}

	/**
	 * Validates structure of the "accessors" section and returns processed values for every accessor after sparse
	 * substitution.
	 *
	 * @param array $properties
	 * @param list<GLTFBuffer> $buffers
	 * @param list<GLTFBufferView> $buffer_views
	 * @param int $max_accessor_values
	 * @return list<array{GLTFComponentType, int, int, list<int|float>}>
	 */
	public function processAccessors( array $properties, array $buffers, array $buffer_views, int $max_accessor_values = self::MAX_ACCESSOR_VALUES ): array {
		$component_registry = GLTFComponentType::registry();

		$accessor_values = [];
		$n_accessor_values = 0;
		$required_accessors = [ "componentType" => 0, "count" => 0, "type" => "" ];
		$optional_accessors = [
			"bufferView" => 0, "byteOffset" => 0, "normalized" => false, "name" => "", "min" => [], "max" => [],
			"sparse" => [], "extensions" => [], "extras" => []
		];
		$required_sparse = [ "count" => 0, "indices" => [], "values" => [] ];
		$optional_sparse = [ "extensions" => [], "extras" => [] ];
		$required_sparse_indices = [ "bufferView" => 0, "componentType" => 0 ];
		$optional_sparse_indices = [ "byteOffset" => 0, "extensions" => [], "extras" => [] ];
		$required_sparse_values = [ "bufferView" => 0 ];
		$optional_sparse_values = [ "byteOffset" => 0, "extensions" => [], "extras" => [] ];
		foreach ( $properties["accessors"] as $index => $entry ) {
			JSONSchema::validate( $properties, $required_accessors, [ "accessors", $index ] );
			JSONSchema::validate( $properties, $required_accessors + $optional_accessors, [ "accessors", $index ], JSONSchema::FLAG_OPTIONAL | JSONSchema::FLAG_REPORT_UNKNOWN_KEYS );

			$entry["count"] >= 1 || throw new InvalidArgumentException( "Expected 'count' >= 1, got {$entry["count"]}", self::ERR_INVALID_SCHEMA );

			$component_type = $component_registry[$entry["componentType"]] ?? throw new InvalidArgumentException( "Expected 'componentType' to be one of: " . implode( ", ", array_keys( $component_registry ) ) . ", got {$entry["componentType"]}", self::ERR_INVALID_SCHEMA );
			!isset( $entry["byteOffset"] ) || $entry["byteOffset"] >= 0 || throw new InvalidArgumentException( "Expected 'sparse.count' >= 0, got {$entry["byteOffset"]}", self::ERR_INVALID_SCHEMA );
			if ( isset( $entry["normalized"] ) && $entry["normalized"] && in_array( $component_type->code, [ GLTFComponentType::FLOAT, GLTFComponentType::UNSIGNED_INT ], true ) ) {
				throw new InvalidArgumentException( "Expected 'normalized' to be false when component type is {$component_type->name}", self::ERR_INVALID_SCHEMA );
			}
			$component_count = self::ACCESSOR_SIZES[$entry["type"]] ?? throw new InvalidArgumentException( "Expected accessor type to be one of: " . implode( ", ", array_keys( self::ACCESSOR_SIZES ) ) . ", got '{$entry["type"]}'", self::ERR_INVALID_SCHEMA );
			$entry["count"] <= intdiv( $max_accessor_values - $n_accessor_values, $component_count ) || throw new InvalidArgumentException( "Accessor values exceed parser limit of {$max_accessor_values}", self::ERR_INVALID_SCHEMA );
			$n_accessor_values += $entry["count"] * $component_count;

			// validate min, max
			if ( isset( $entry["min"] ) || isset( $entry["max"] ) ) {
				$types = array_fill( 0, $component_count, 0.0 );
				JSONSchema::validate( $properties, [ "min" => $types, "max" => $types ], [ "accessors", $index ] );
				foreach ( [ ...$entry["min"], ...$entry["max"] ] as $value ) {
					!is_infinite( $value ) || throw new InvalidArgumentException( "Invalid value encountered (inf) in accessor entry", self::ERR_INVALID_SCHEMA );
					!is_nan( $value ) || throw new InvalidArgumentException( "Invalid value encountered (inf) in accessor entry", self::ERR_INVALID_SCHEMA );
					( $value >= $component_type->min && $value <= $component_type->max ) || throw new InvalidArgumentException( "Expected accessor entry to fall in range [{$component_type->min}, {$component_type->max}], got {$value}", self::ERR_INVALID_SCHEMA );
				}
			}

			// validate sparse
			if ( isset( $entry["sparse"] ) ) {
				$sparse = $entry["sparse"];
				JSONSchema::validate( $properties, $required_sparse, [ "accessors", $index, "sparse" ] );
				JSONSchema::validate( $properties, $required_sparse + $optional_sparse, [ "accessors", $index, "sparse" ], JSONSchema::FLAG_OPTIONAL | JSONSchema::FLAG_REPORT_UNKNOWN_KEYS );
				JSONSchema::validate( $properties, $required_sparse_indices, [ "accessors", $index, "sparse", "indices" ] );
				JSONSchema::validate( $properties, $required_sparse_indices + $optional_sparse_indices, [ "accessors", $index, "sparse", "indices" ], JSONSchema::FLAG_OPTIONAL | JSONSchema::FLAG_REPORT_UNKNOWN_KEYS );
				JSONSchema::validate( $properties, $required_sparse_values, [ "accessors", $index, "sparse", "values" ] );
				JSONSchema::validate( $properties, $required_sparse_values + $optional_sparse_values, [ "accessors", $index, "sparse", "values" ], JSONSchema::FLAG_OPTIONAL | JSONSchema::FLAG_REPORT_UNKNOWN_KEYS );

				$sparse["count"] >= 1 || throw new InvalidArgumentException( "Expected 'sparse.count' >= 1, got {$sparse["count"]}", self::ERR_INVALID_SCHEMA );
				$sparse["count"] <= $entry["count"] || throw new InvalidArgumentException( "Expected 'sparse.count' ({$sparse["count"]}) <= base accessor size ({$entry["count"]})", self::ERR_INVALID_SCHEMA );

				// validate indices
				$sparse_component_type = $component_registry[$sparse["indices"]["componentType"]] ?? throw new InvalidArgumentException( "Expected 'componentType' to be one of: " . implode( ", ", array_keys( $component_registry ) ) . ", got {$sparse["indices"]["componentType"]}", self::ERR_INVALID_SCHEMA );
				$index_v = $sparse["indices"]["bufferView"];
				isset( $buffer_views[$index_v] ) || throw new InvalidArgumentException( "Expected 'bufferView' >= 0, < " . count( $buffer_views ) . ", got {$index_v}", self::ERR_INVALID_SCHEMA );
				$view = $buffer_views[$index_v];
				$view->byte_stride === null || throw new InvalidArgumentException( "Expected 'byteStride' of buffer view ({$index_v}) accessed from sparse indices to be undefined", self::ERR_INVALID_SCHEMA );
				$view->target === null || throw new InvalidArgumentException( "Expected 'target' of buffer view ({$index_v}) accessed from sparse indices to be undefined", self::ERR_INVALID_SCHEMA );

				// validate if buffer view and the optional byteOffset align to the componentType byte length
				$offset_accessor = (int)( $sparse["indices"]["byteOffset"] ?? 0 );
				$offset_view = $view->byte_offset;
				( $offset_accessor + $offset_view ) % $sparse_component_type->size === 0 || throw new InvalidArgumentException( "Expected accessor offset ({$offset_accessor}) + view offset ({$offset_view}) to be a multiple of size of component '{$sparse_component_type->name}' ({$sparse_component_type->size})", self::ERR_INVALID_SCHEMA );

				$buffers[$view->buffer]->value ?? throw new InvalidArgumentException( "Sparse indices points to an unresolved buffer ({$view->buffer}): {$buffers[$view->buffer]->uri}", self::ERR_INVALID_SCHEMA );
				$indices = unpack( "{$sparse_component_type->format}{$sparse["count"]}/", $buffers[$view->buffer]->value, $offset_accessor + $offset_view );
				$indices = array_values( $indices );
				foreach ( $indices as $index2 => $value ) {
					$value < $entry["count"] || throw new InvalidArgumentException( "Expected sparse.indices ({$value}) <= base accessor size ({$entry["count"]})", self::ERR_INVALID_SCHEMA );
					if ( $index2 > 0 && $value < $indices[$index2 - 1] ) {
						throw new InvalidArgumentException( "Expected sparse indices to strictly increase, got {$value} < {$indices[$index2 - 1]}", self::ERR_INVALID_SCHEMA );
					}
				}

				// validate values
				$index_v = $sparse["values"]["bufferView"];
				isset( $buffer_views[$index_v] ) || throw new InvalidArgumentException( "Expected 'bufferView' >= 0, < " . count( $buffer_views ) . ", got {$index_v}", self::ERR_INVALID_SCHEMA );
				$view = $buffer_views[$index_v];
				$view->byte_stride === null || throw new InvalidArgumentException( "Expected 'byteStride' of buffer view ({$index_v}) accessed from sparse values to be undefined", self::ERR_INVALID_SCHEMA );
				$view->target === null || throw new InvalidArgumentException( "Expected 'target' of buffer view ({$index_v}) accessed from sparse values to be undefined", self::ERR_INVALID_SCHEMA );

				// validate if buffer view and the optional byteOffset align to the componentType byte length
				$offset_accessor = (int)( $sparse["values"]["byteOffset"] ?? 0 );
				$offset_view = $view->byte_offset;
				( $offset_accessor + $offset_view ) % $component_type->size === 0 || throw new InvalidArgumentException( "Expected accessor offset ({$offset_accessor}) + view offset ({$offset_view}) to be a multiple of size of component '{$component_type->name}' ({$component_type->size})", self::ERR_INVALID_SCHEMA );

				$buffers[$view->buffer]->value ?? throw new InvalidArgumentException( "Sparse values points to an unresolved buffer ({$view->buffer}): {$buffers[$view->buffer]->uri}", self::ERR_INVALID_SCHEMA );
				$values = unpack( $component_type->format . ( $sparse["count"] * $component_count ) . "/", $buffers[$view->buffer]->value, $offset_accessor + $offset_view );
				$values = array_values( $values );
				$sparse = [ $indices, $values ];
			} else {
				// no sparse substitutions needed
				$sparse = [ [], [] ];
			}

			if ( isset( $entry["bufferView"] ) ) {
				$index_v = $entry["bufferView"];
				isset( $buffer_views[$index_v] ) || throw new InvalidArgumentException( "Expected 'bufferView' >= 0, < " . count( $buffer_views ) . ", got {$index_v}", self::ERR_INVALID_SCHEMA );
				$view = $buffer_views[$index_v];
				$buffers[$view->buffer]->value ?? throw new InvalidArgumentException( "Accessor points to an unresolved buffer ({$view->buffer}): {$buffers[$view->buffer]->uri}", self::ERR_INVALID_SCHEMA );

				// validate if buffer view and the optional byteOffset align to the componentType byte length
				$offset_accessor = (int)( $entry["byteOffset"] ?? 0 );
				$offset_view = $view->byte_offset;
				( $offset_accessor + $offset_view ) % $component_type->size === 0 || throw new InvalidArgumentException( "Expected accessor offset ({$offset_accessor}) + view offset ({$offset_view}) to be a multiple of size of accessor component '{$component_type->name}' ({$component_type->size})", self::ERR_INVALID_SCHEMA );
				$view->byte_stride === null || $view->byte_stride % $component_type->size === 0 || throw new InvalidArgumentException( "Expected byte stride of view ({$view->byte_stride}) to be a multiple of size of accessor component '{$component_type->name}' ({$component_type->size})", self::ERR_INVALID_SCHEMA );

				$element_size = $component_type->size * $component_count;
				$stride = $view->byte_stride ?? $element_size;
				$stride >= $element_size || throw new InvalidArgumentException( "Accessor element size ({$element_size}) exceeds byte stride ({$stride})", self::ERR_INVALID_SCHEMA );
				$fitness = $offset_accessor + $stride * ( $entry["count"] - 1 ) + $element_size;
				$fitness <= $view->byte_length || throw new InvalidArgumentException( "Expected accessor fitness ({$fitness}) <= buffer view length ({$view->byte_length})", self::ERR_INVALID_SCHEMA );

				$values = [];
				$format = $component_type->format . $component_count . "/";
				$data = $buffers[$view->buffer]->value;
				$offset = $offset_accessor + $offset_view;
				for ( $i = 0; $i < $entry["count"]; $i++, $offset += $stride ) {
					array_push( $values, ...unpack( $format, $data, $offset ) );
				}
			} else {
				$values = array_fill( 0, $entry["count"] * $component_count, 0 );
			}

			// perform sparse substitution for $values
			foreach ( $sparse[0] as $index_s => $index_replace ) {
				$offset = $index_replace * $component_count;
				$replacement_offset = $index_s * $component_count;
				for ( $component = 0; $component < $component_count; $component++ ) {
					$values[$offset + $component] = $sparse[1][$replacement_offset + $component];
				}
			}

			$accessor_values[] = [ $component_type, $component_count, $entry["count"], $values ];
		}
		return $accessor_values;
	}

	/**
	 * Checks whether a MIME (or a [MIME, file-extension] pair) is allowed as per the parser rules.
	 *
	 * @param string $mime the mime type to test
	 * @param string|null $ext extension of the file, or null if not known
	 * @param list<string|array{string|null, string}>|null $allowed_mimes Allowed MIME types, or null to allow all
	 * @return bool whether the mime is allowed as per the parser rules
	 */
	public function isMimeAllowed( string $mime, ?string $ext, ?array $allowed_mimes ): bool {
		if ( $allowed_mimes === null ) {
			return true;
		}
		$ext = $ext === null ? null : strtolower( $ext );
		foreach ( $allowed_mimes as $entry ) {
			if ( $entry === $mime || $entry === [ null, $ext ] || $entry === [ $mime, $ext ] ) {
				return true;
			}
		}
		return false;
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
	 * @param array|null $allowed_mimes Allowed MIME types, or null to allow all
	 * @return array{string, string} a pair of the returned raw buffer (byte array) and mime type
	 */
	public function resolveURI( string $uri, ?string $base_directory, bool $resolve_remote, ?int $length = null, ?array $allowed_mimes = null ): array {
		$this->resolved_resources < $this->max_resolved_resources || throw new InvalidArgumentException( "Resolved resources exceed parser limit of {$this->max_resolved_resources}", self::ERR_INVALID_SCHEMA );
		$remaining = $this->max_resolved_resource_bytes - $this->resolved_resource_bytes;
		$remaining > 0 || throw new InvalidArgumentException( "Resolved resource bytes exceed parser limit of {$this->max_resolved_resource_bytes}", self::ERR_INVALID_SCHEMA );
		$length === null || $length <= $remaining || throw new InvalidArgumentException( "Resolved resource bytes exceed parser limit of {$this->max_resolved_resource_bytes}", self::ERR_INVALID_SCHEMA );
		if ( str_starts_with( $uri, "data:" ) ) {
			$token_end = strpos( $uri, ",", 5 );
			if ( $token_end === false || $token_end > 64 ) {
				$token_end = 64;
			}
			$uri_type = substr( $uri, 5, $token_end - 5 );
			$uri_data = substr( $uri, $token_end + 1 );
			$mime = explode( ";", $uri_type, 2 )[0];
			$this->isMimeAllowed( $mime, null, $allowed_mimes ) || throw new InvalidArgumentException( "Unsupported MIME type {$mime}", self::ERR_URI_RESOLUTION_EMBEDDED );
			if ( $uri_type === "application/octet-stream" ) {
				return [ $this->accountResolvedResource( urldecode( $uri_data ), $length ), $mime ];
			}
			if ( in_array( $uri_type, [
				"application/octet-stream;base64",
				"application/gltf-buffer;base64",
				"image/png;base64",
				"image/jpeg;base64",
			], true ) ) {
				$result = base64_decode( $uri_data );
				$result !== false || throw new InvalidArgumentException( "Improperly encoded base64 data supplied for URI type {$uri_type}", self::ERR_URI_RESOLUTION_EMBEDDED );
				return [ $this->accountResolvedResource( $result, $length ), $mime ];
			}
			throw new InvalidArgumentException( "Expected URI type to be one of: application/octet-stream, application/octet-stream;base64, application/gltf-buffer;base64, got {$uri_type}", self::ERR_URI_RESOLUTION_EMBEDDED );
		}
		if ( filter_var( $uri, FILTER_VALIDATE_URL ) ) {
			$resolve_remote || throw new InvalidArgumentException( "Remote resolution is not allowed", self::ERR_URI_RESOLUTION_REMOTE );
			// TODO: Validate return type, HTTP response code
			$data = file_get_contents( $uri, length: $length ?? $remaining + 1 );
			$data !== false || throw new InvalidArgumentException( "Remote resolution failed for uri: {$uri}", self::ERR_URI_RESOLUTION_REMOTE );
			$mime = $this->mime_checker->buffer( $data );
			$this->isMimeAllowed( $mime, null, $allowed_mimes ) || throw new InvalidArgumentException( "Unsupported MIME type {$mime}", self::ERR_URI_RESOLUTION_REMOTE );
			return [ $this->accountResolvedResource( $data, $length ), $mime ];
		}
		$base_directory ?? throw new InvalidArgumentException( "Local resolution is not allowed", self::ERR_URI_RESOLUTION_LOCAL );
		\FileBackend::isPathTraversalFree( $decoded_uri = urldecode( $uri ) ) || throw new InvalidArgumentException( "Directory traversal is not allowed in local URI: {$uri}", self::ERR_URI_RESOLUTION_LOCAL );
		$path = $base_directory . DIRECTORY_SEPARATOR . $decoded_uri;
		( is_file( $path ) && file_exists( $path ) ) || throw new InvalidArgumentException( "File not found: {$path}", self::ERR_URI_RESOLUTION_LOCAL );
		$ext = pathinfo( $path, PATHINFO_EXTENSION );
		$data = file_get_contents( $path, length: $length ?? $remaining + 1 );
		$data !== false || throw new InvalidArgumentException( "Local resolution failed for uri: {$uri}", self::ERR_URI_RESOLUTION_LOCAL );
		$mime = $this->mime_checker->buffer( $data );
		$this->isMimeAllowed( $mime, $ext, $allowed_mimes ) || throw new InvalidArgumentException( "Unsupported MIME type {$mime}", self::ERR_URI_RESOLUTION_LOCAL );
		return [ $this->accountResolvedResource( $data, $length ), $mime ];
	}

	private function accountResolvedResource( string $data, ?int $expected_length ): string {
		$length = strlen( $data );
		$expected_length === null || $length === $expected_length || throw new InvalidArgumentException( "Resolved resource length ({$length}) does not match declared length ({$expected_length})", self::ERR_INVALID_SCHEMA );
		$length <= $this->max_resolved_resource_bytes - $this->resolved_resource_bytes || throw new InvalidArgumentException( "Resolved resource bytes exceed parser limit of {$this->max_resolved_resource_bytes}", self::ERR_INVALID_SCHEMA );
		$this->resolved_resource_bytes += $length;
		$this->resolved_resources++;
		return $data;
	}

	public function calculateNodeTransformationMatrix( array $node ): array {
		// implemented based on notes from:
		// https://github.com/KhronosGroup/glTF-Tutorials/blob/bdc3640aad36ec9fe2c20fa262488fab5842f06b/gltfTutorial/gltfTutorial_004_ScenesNodes.md
		if ( isset( $node["matrix"] ) ) {
			return Matrix::transpose( $node["matrix"] );
		}

		// no node found: construct a transformation matrix from TRS values
		$t = $node["translation"] ?? [ 0, 0, 0 ];
		$r = $node["rotation"] ?? [ 0, 0, 0, 1 ];
		$s = $node["scale"] ?? [ 1, 1, 1 ];

		$T = [
			1, 0, 0, $t[0],
			0, 1, 0, $t[1],
			0, 0, 1, $t[2],
			0, 0, 0, 1
		];

		$R = Matrix::quaternionToRotation( $r );

		$S = [
			$s[0], 0, 0, 0,
			0, $s[1], 0, 0,
			0, 0, $s[2], 0,
			0, 0, 0, 1
		];
		return Matrix::multiply( $T, Matrix::multiply( $R, $S, 4 ), 4 );
	}

	/**
	 * Computes length of X, Y, and Z planes of the model.
	 *
	 * @param int $max_node_references
	 * @param int $max_transformed_vertices
	 * @return array{float, float, float}|null a tuple of lengths of X, Y, and Z planes respectively, or null if the
	 * model dimensions could not be inferred.
	 */
	public function computeModelDimensions( int $max_node_references = self::MAX_SCENE_NODE_REFERENCES, int $max_transformed_vertices = self::MAX_TRANSFORMED_VERTICES ): ?array {
		$nodes = [];
		$stack = [];
		$offset = 0;
		$node_references = 0;
		$transformed_vertices = 0;
		$minimum = [ INF, INF, INF ];
		$maximum = [ -INF, -INF, -INF ];
		foreach ( $this->properties["scenes"] as $scene ) {
			foreach ( $scene["nodes"] as $node ) {
				++$node_references <= $max_node_references || throw new InvalidArgumentException( "Scene node references exceed parser limit of {$max_node_references}", self::ERR_INVALID_SCHEMA );
				if ( !isset( $nodes[$node] ) ) {
					$nodes[$node] = true;
					$stack[] = [ $node, Matrix::IDENTITY4 ];
				}
			}
		}

		while ( isset( $stack[$offset] ) ) {
			[ $index, $global_transformation ] = $stack[$offset++];
			$node = $this->properties["nodes"][$index];
			$transformation = Matrix::multiply( $global_transformation, $this->calculateNodeTransformationMatrix( $node ), 4 );

			if ( isset( $node["children"] ) ) {
				foreach ( $node["children"] as $child ) {
					++$node_references <= $max_node_references || throw new InvalidArgumentException( "Scene node references exceed parser limit of {$max_node_references}", self::ERR_INVALID_SCHEMA );
					if ( !isset( $nodes[$child] ) ) {
						$nodes[$child] = true;
						$stack[] = [ $child, $transformation ];
					}
				}
			}

			if ( !isset( $node["mesh"] ) ) {
				continue;
			}

			$mesh = $this->properties["meshes"][$node["mesh"]];
			foreach ( $mesh["primitives"] as $primitive ) {
				if ( !isset( $primitive["attributes"]["POSITION"] ) ) {
					continue;
				}
				if ( isset( $primitive["extensions"] ) && count( $primitive["extensions"] ) > 0 ) {
					// extensions like KHR_draco_mesh_compression require further handling, otherwise we end up with
					// incorrect accessor values.
					continue;
				}
				[ $comp_type, $comp_size, $n_comp, $comp_values ] = $this->accessor_values[$primitive["attributes"]["POSITION"]];
				for ( $i = 0, $j = count( $comp_values ); $i < $j; $i += $comp_size ) {
					++$transformed_vertices <= $max_transformed_vertices || throw new InvalidArgumentException( "Transformed vertices exceed parser limit of {$max_transformed_vertices}", self::ERR_INVALID_SCHEMA );
					$x = $comp_values[$i];
					$y = $comp_values[$i + 1];
					$z = $comp_values[$i + 2];
					$value = [
						( $transformation[0] * $x ) + ( $transformation[1] * $y ) + ( $transformation[2] * $z ) + $transformation[3],
						( $transformation[4] * $x ) + ( $transformation[5] * $y ) + ( $transformation[6] * $z ) + $transformation[7],
						( $transformation[8] * $x ) + ( $transformation[9] * $y ) + ( $transformation[10] * $z ) + $transformation[11]
					];
					for ( $axis = 0; $axis < 3; $axis++ ) {
						$minimum[$axis] = min( $minimum[$axis], $value[$axis] );
						$maximum[$axis] = max( $maximum[$axis], $value[$axis] );
					}
				}
			}
		}
		if ( $transformed_vertices === 0 ) {
			return null;
		}
		return [ $maximum[0] - $minimum[0], $maximum[1] - $minimum[1], $maximum[2] - $minimum[2] ];
	}

	/**
	 * Computes glTF stats for report generation and returns attributes as reported by KhronosGroup/glTF-Validator.
	 *
	 * @link https://github.com/KhronosGroup/glTF-Validator/blob/bcd52cc4ba5f333b2999a58f67cc05ddf28b4fb1/lib/src/validation_result.dart
	 * @return array<string, int>
	 */
	public function computeStats(): array {
		$vertices = 0;
		$triangles = 0;
		$draw_calls = 0;
		foreach ( $this->properties["meshes"] as $mesh ) {
			$draw_calls += count( $mesh["primitives"] );
			foreach ( $mesh["primitives"] as $primitive ) {
				if ( !isset( $primitive["attributes"]["POSITION"] ) ) {
					continue;
				}
				if ( isset( $primitive["extensions"] ) && count( $primitive["extensions"] ) > 0 ) {
					// extensions like KHR_draco_mesh_compression require further handling, otherwise we end up with
					// incorrect accessor values.
					continue;
				}
				[ $comp_type, $comp_size, $n_comp, $comp_values ] = $this->accessor_values[$primitive["attributes"]["POSITION"]];
				$vertices += $n_comp;
				$n_indices = isset( $primitive["indices"] ) ? $this->accessor_values[$primitive["indices"]][2] : $n_comp;
				$mode = $primitive["mode"] ?? 4;
				if ( $mode === 4 ) {
					// TRIANGLES
					$triangles += intdiv( $n_indices, 3 );

				// TRIANGLE_STRIP or TRIANGLE_FAN
				} elseif ( $mode === 5 || $mode === 6 ) {
					$triangles += $n_indices > 2 ? $n_indices - 2 : 0;

				}

			}
		}
		return [
			"animationCount" => isset( $this->properties["animations"] ) ? count( $this->properties["animations"] ) : 0,
			"drawCallCount" => $draw_calls,
			"materialCount" => isset( $this->properties["materials"] ) ? count( $this->properties["materials"] ) : 0,
			"totalTriangleCount" => $triangles,
			"totalVertexCount" => $vertices
		];
	}

	/**
	 * Returns a raw GLB bytes representation of the glTF file with all resources embedded. This is useful to generate a
	 * 'portable' glTF file. Resolved remote and local filesystem buffers and images are embedded directly.
	 *
	 * Example usage:
	 *   // convert GLTF to GLB
	 *   $parser = new GLTFParser("model.gltf");
	 *   $contents = $parser->exportEmbeddedBinary();
	 *   file_put_contents("model.glb", $contents);
	 *
	 * @return string
	 */
	public function exportEmbeddedBinary(): string {
		foreach ( $this->buffers as $index => $buffer ) {
			$buffer->value ?? throw new InvalidArgumentException( "Buffer at index {$index} is unresolved (" . substr( $buffer->uri, 0, 64 ) . ")" );
		}

		// process properties
		$properties = $this->properties;
		if ( isset( $properties["images"] ) ) {
			foreach ( $properties["images"] as $index => $property ) {
				unset( $properties["images"][$index]["uri"] );
			}
		}

		// process buffer
		// -- collect all buffers and concatenate into a single blob.
		// -- offset corresponding buffer views and image buffers
		$buffers = [];
		$offset = 0;
		foreach ( $this->buffers as $ib => $buffer ) {
			$buffers[] = $buffer->value;
			$byte_offset = $offset;
			foreach ( $properties["bufferViews"] as $iv => $view ) {
				if ( $ib === 0 || $view["buffer"] !== $ib ) {
					continue;
				}
				$view["buffer"] = 0;
				$view["byteOffset"] ??= 0;
				$view["byteOffset"] += $byte_offset;
				$properties["bufferViews"][$iv] = $view;
			}
			$offset += strlen( $buffer->value );
		}

		$iv = count( $this->buffer_views );
		foreach ( $this->image_buffers as $ib => [ $buffer, $mime ] ) {
			if ( is_int( $buffer ) ) {
				// We already translated buffer views.
				continue;
			}

			// introduce a new buffer view referencing the position of this image
			// we need to ensure length of buffer is a multiple of 4 per glTF spec
			// so we pad it with zero bytes
			$buffer .= str_repeat( "\0", 4 - ( strlen( $buffer ) % 4 ) );
			$buffers[] = $buffer;
			$properties["images"][$ib]["bufferView"] = $iv;
			$properties["images"][$ib]["mimeType"] = $mime;
			$properties["bufferViews"][$iv] = [ "buffer" => 0, "byteOffset" => $offset, "byteLength" => strlen( $buffer ) ];
			$iv++;
			$offset += strlen( $buffer );
		}

		if ( count( $buffers ) > 0 ) {
			$chunk1 = implode( $buffers );
			$properties["buffers"] = [ [ "byteLength" => strlen( $chunk1 ) ] ];
		} else {
			$chunk1 = null;
			unset( $properties["buffers"] );
		}

		try {
			$chunk0 = json_encode( $properties, JSON_THROW_ON_ERROR );
		} catch ( JsonException $e ) {
			throw new InvalidArgumentException( "Failed to encode chunk0: {$e->getMessage()}", 0, $e );
		}
		$data = [];
		$data[] = pack( "V*", strlen( $chunk0 ), self::CHUNK_JSON );
		$data[] = $chunk0;
		if ( $chunk1 !== null ) {
			$chunk1 = implode( $buffers );
			$data[] = pack( "V*", strlen( $chunk1 ), self::CHUNK_BIN );
			$data[] = $chunk1;
		}
		$contents = implode( $data );
		// write header
		return pack( "V*", self::HEADER_MAGIC, $this->version, strlen( $contents ) + 12 ) . $contents;
	}
}
