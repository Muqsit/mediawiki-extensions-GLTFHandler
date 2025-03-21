<?php

namespace MediaWiki\Extension\GLTFHandler;

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
	 * @return array
	 */
	public function getSizeAndMetadata($state, $path){
		$parser = new GLTFParser($path);
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
		return ["width" => $width, "height" => $height, "metadata" => $parser->getMetadata()];
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
		if(in_array($name, ["ar", "camera-orbit", "poster", "skybox", "environment"], true)){
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
		$params = array_combine(["width", "camera-orbit", "ar", "poster", "skybox", "skybox-height", "environment"], $values);
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