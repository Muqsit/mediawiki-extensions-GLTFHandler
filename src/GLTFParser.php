<?php

namespace MediaWiki\Extension\GLTFHandler;

use InvalidArgumentException;
use function array_column;
use function array_push;
use function bin2hex;
use function count;
use function fclose;
use function file_get_contents;
use function fopen;
use function fread;
use function fseek;
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

	public bool $binary;
	public int $version;
	public int $length;
	public array $properties;

	public function __construct(string $path, ?bool $binary = null){
		$binary = $binary ?? self::inferBinary($path);
		if($binary){
			$resource = fopen($path, "rb");
			if($resource === false){
				throw new InvalidArgumentException("Failed to open file {$path}");
			}
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
			$contents = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
			$version = (int) $contents["asset"]["version"];
			$properties = $contents;
		}
		$this->binary = $binary;
		$this->version = $version;
		$this->length = $length;
		$this->properties = $properties;
	}

	/**
	 * @param resource $resource
	 * @return array
	 */
	private function readHeaderGlb($resource) : array{
		$header = fread($resource, 12);
		$decoded = unpack("V3h/", $header);
		$magic = $decoded["h1"];
		$version = $decoded["h2"];
		$length = $decoded["h3"];
		if($magic !== self::HEADER_MAGIC){
			throw new InvalidArgumentException("Improperly formatted GLB header: Magic has unexpected value: " . bin2hex($magic));
		}
		if($version !== 2){
			// TODO: check what the difference between version 1 and version 2 is.
			// this will likely impact self::computeModelDimensions() and maybe self::getMetadata().
			throw new InvalidArgumentException("Unsupported GLB version ({$version}), expected version 2");
		}
		return [$version, $length];
	}

	/**
	 * @param resource $resource
	 * @param int $expected_type
	 * @return array|string|null
	 */
	public function readChunkGlb($resource, int $expected_type) : array|string|null{
		$structure = fread($resource, 8);
		$decoded = unpack("V2s/", $structure);
		$length = $decoded["s1"];
		$type = $decoded["s2"];
		if($type !== $expected_type){
			throw new InvalidArgumentException("Unexpected chunk type ({$type}), expected {$expected_type}");
		}
		if($type === self::CHUNK_JSON){
			$data = fread($resource, $length);
			$data = json_decode($data, true, 512, JSON_THROW_ON_ERROR);
			return $data;
		}
		if($type === self::CHUNK_BIN){
			return fread($resource, $length);
		}
		fseek($resource, $length, SEEK_CUR);
		return null;
	}

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