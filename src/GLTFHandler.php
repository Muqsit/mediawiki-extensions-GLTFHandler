<?php

namespace MediaWiki\Extension\GLTFHandler;

use InvalidArgumentException;
use MediaWiki\Extension\GLTFHandler\Parser\GLTFParser;
use MediaWiki\Status\Status;
use function array_combine;
use function array_filter;
use function count;
use function explode;
use function implode;
use function in_array;
use function is_numeric;
use function max;
use function preg_match;

class GLTFHandler extends \MediaHandler {

	/**
	 * @param \MediaHandlerState $state
	 * @param string $path
	 * @return array|null
	 */
	public function getSizeAndMetadata($state, $path){
		try{
			$parser = new GLTFParser($path);
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
		try{ new GLTFParser($fileName); }catch(InvalidArgumentException $e){
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

	/**
	 * @param \File $image
	 * @return bool
	 */
	public function isFileMetadataValid($image){
		if($image->getMetadataItem("Version") === null){
			return self::METADATA_BAD;
		}
		return self::METADATA_GOOD;
	}

	/**
	 * @param \File $image
	 * @param array &$params
	 * @return true
	 */
	public function normaliseParams( $image, &$params ) {
		return true;
	}

	/**
	 * Prevent "no higher resolution" message.
	 *
	 * @param \File $file
	 * @return true
	 */
	public function mustRender( $file ) {
		return true;
	}

	/**
	 * @return array
	 */
	public function getParamMap() {
		return [
			"img_width" => "width",
			"gltfhandler_ar" => "ar",
			"gltfhandler_camera_orbit" => "camera-orbit",
			"gltfhandler_max_camera_orbit" => "max-camera-orbit",
			"gltfhandler_environment" => "environment",
			"gltfhandler_poster" => "poster",
			"gltfhandler_skybox" => "skybox",
			"gltfhandler_skybox_height" => "skybox-height"
		];
	}

	/**
	 * @param string $name
	 * @param mixed $value
	 * @return true
	 */
	public function validateParam( $name, $value ) {
		if(in_array( $name, [ "width", "height"], true )){
			return $value > 0;
		}
		if(in_array($name, ["ar", "camera-orbit", "max-camera-orbit", "poster", "skybox", "environment"], true)){
			return true;
		}
		if($name === "skybox-height"){
			return is_numeric($value) || preg_match('/\s*([0-9.]+)\s*(mm|m|cm)/m', $value) > 0;
		}
		return true;
	}

	/**
	 * @param array $params
	 * @return string
	 */
	public function makeParamString( $params ) {
		return implode("-", [
			$params["width"] ?? "",
			$params["camera-orbit"] ?? "",
			$params["max-camera-orbit"] ?? "",
			isset($params["ar"]) ? "true" : "false",
			$params["poster"] ?? "",
			$params["skybox"] ?? "",
			$params["skybox-height"] ?? "",
			$params["environment"] ?? ""
		]);
	}

	/**
	 * @param string $str
	 * @return array|false
	 */
	public function parseParamString( $str ) {
		$values = explode("-", $str);
		if(count($values) !== 7){
			return false;
		}
		$params = array_combine(["width", "camera-orbit", "max-camera-orbit", "ar", "poster", "skybox", "skybox-height", "environment"], $values);
		$params = array_filter($params, function($x){ return $x !== ""; });
		$params["ar"] = $params["ar"] === "true";
		return $params;
	}

	/**
	 * @param \File $image
	 * @param string $dstPath
	 * @param string $dstUrl
	 * @param array $params
	 * @param int $flags
	 * @return GLTFTransformOutput
	 */
	public function doTransform( $image, $dstPath, $dstUrl, $params, $flags = 0 ) {
		return new GLTFTransformOutput( $image->getFullUrl(), $image->getWidth(), $image->getHeight(), $params );
	}
}