<?php

namespace MediaWiki\Extension\GLTFHandler;

use InvalidArgumentException;
use MediaWiki\Extension\GLTFHandler\Parser\GLTFParser;
use MediaWiki\Status\Status;
use function is_numeric;
use function max;
use function preg_match;

class GLTFHandler extends \MediaHandler {

	public function getSizeAndMetadata($state, $path){
		global $wgGLTFHandlerMaxAccessorValues;
		try{
			$parser = new GLTFParser($path, max_accessor_values: $wgGLTFHandlerMaxAccessorValues);
		}catch(InvalidArgumentException){
			return null;
		}
		$dims = $parser->computeModelDimensions();

		$width = max($dims[0], $dims[2]);
		$height = $dims[1];

		// normalize size
		$f = $width + $height;
		if($f <= 0){
			return null;
		}
		$width /= $f;
		$height /= $f;

		$width *= 400;
		$height *= 400;

		$metadata = ["version" => $parser->version];
		if($parser->copyright !== null){
			$metadata["copyright"] = $parser->copyright;
		}
		if($parser->generator !== null){
			$metadata["generator"] = $parser->generator;
		}
		return ["width" => $width, "height" => $height, "metadata" => $metadata];
	}

	public function verifyUpload( $fileName ) {
		global $wgGLTFHandlerMaxAccessorValues;
		try{ new GLTFParser($fileName, max_accessor_values: $wgGLTFHandlerMaxAccessorValues); }catch(InvalidArgumentException $e){
			return Status::newFatal(match($e->getCode()){
				GLTFParser::ERR_UNSUPPORTED_VERSION => "gltfhandler-error-unsupportedversion",
				GLTFParser::ERR_INVALID_SCHEMA => "gltfhandler-error-invalidschema",
				GLTFParser::ERR_URI_RESOLUTION_EMBEDDED => "gltfhandler-error-uriresolutionembedded",
				GLTFParser::ERR_URI_RESOLUTION_LOCAL => "gltfhandler-error-uriresolutionlocal",
				GLTFParser::ERR_URI_RESOLUTION_REMOTE => "gltfhandler-error-uriresolutionremote",
				default => "gltfhandler-error-unknown"
			});
		}
		return parent::verifyUpload( $fileName );
	}

	public function isFileMetadataValid($image){
		if($image->getMetadataItem("Version") === null){
			return self::METADATA_BAD;
		}
		return self::METADATA_GOOD;
	}

	public function normaliseParams( $image, &$params ) {
		return true;
	}

	public function mustRender( $file ) {
		return true;
	}

	public function getParamMap() {
		return array_column(Constants::PARAMS, "name", "magic_word_id");
	}

	public function validateParam( $name, $value ) {
		return match($name){
			"width", "height" => $value > 0,
			"skybox-height" => is_numeric($value) || preg_match('/\s*([0-9.]+)\s*(mm|m|cm)/m', $value) > 0,
			default => true
		};
	}

	public function makeParamString($params){
		return bin2hex(json_encode($params));
	}

	public function parseParamString($str){
		return json_decode(hex2bin($str), true);
	}

	/**
	 * @return GLTFTransformOutput
	 */
	public function doTransform( $image, $dstPath, $dstUrl, $params, $flags = 0 ) {
		return new GLTFTransformOutput( $image, $image->getWidth(), $image->getHeight(), $params );
	}
}