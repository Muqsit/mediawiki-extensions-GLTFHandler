<?php

namespace MediaWiki\Extension\GLTFHandler;

use MediaWiki\Html\Html;
use MediaWiki\MediaWikiServices;
use function is_string;

class GLTFTransformOutput extends \MediaTransformOutput {

	private string $pSourceFileURL;
	private float $pWidth;
	private float $pHeight;
	private array $pParams;

	/**
	 * @param string $SourceFileURL
	 * @param float $Width
	 * @param float $Height
	 * @param array $Params
	 */
	public function __construct( $SourceFileURL, $Width, $Height, $Params ) {
		$this->pSourceFileURL = $SourceFileURL;
		$this->pWidth = $Width;
		$this->pHeight = $Height;
		$this->pParams = $Params;
	}

	/**
	 * @param array $options
	 *
	 * @return string
	 */
	public function toHtml( $options = [] ) {
		$attributes = ["shadow-intensity" => "1", "camera-controls" => true, "touch-action" => "pan-y"];
		$attributes["animation-name"] = $this->pParams["animation-name"] ?? null;
		$attributes["ar"] = isset($this->pParams["ar"]);
		$attributes["autoplay"] = isset($this->pParams["autoplay"]);
		$attributes["camera-orbit"] = $this->pParams["camera-orbit"] ?? null;
		$attributes["max-camera-orbit"] = $this->pParams["max-camera-orbit"] ?? null;
		$attributes["loading"] = "eager";
		$attributes["skybox-height"] = $this->pParams["skybox-height"] ?? null;
		$attributes["src"] = $this->pSourceFileURL;

		if($this->pHeight > 0 && $this->pWidth > 0){
			if(isset($this->pParams["width"])){
				$width = (float)$this->pParams["width"];
				$height = $width * ($this->pHeight / $this->pWidth);
				$attributes["style"] = "width: {$width}px; height: {$height}px;";
			}else{
				$width = $this->pWidth;
				$height = $this->pHeight;
			}
		}else{
			$width = $height = null;
		}

		if(isset($this->pParams["poster"]) && is_string($this->pParams["poster"])){
			$poster = MediaWikiServices::getInstance()->getRepoGroup()->findFile($this->pParams["poster"]);
			if($poster !== false && $poster->isLocal() && $poster->canRender()){
				$attributes["poster"] = $poster->getUrl();
			}
		}

		if(isset($this->pParams["skybox"]) && is_string($this->pParams["skybox"])){
			$skybox = MediaWikiServices::getInstance()->getRepoGroup()->findFile($this->pParams["skybox"]);
			if($skybox !== false && $skybox->isLocal() && $skybox->canRender()){
				$attributes["skybox-image"] = $skybox->getUrl();
			}
		}

		if(isset($this->pParams["environment"]) && is_string($this->pParams["environment"])){
			$environment = MediaWikiServices::getInstance()->getRepoGroup()->findFile($this->pParams["environment"]);
			if($environment !== false && $environment->isLocal() && $environment->canRender()){
				$attributes["environment-image"] = $environment->getUrl();
			}
		}

		// attributes for dynamic resizing
		$attributes["class"] = "model-viewer-dynsize";
		$attributes["data-width"] = $width;
		$attributes["data-height"] = $height;

		$output = Html::element("model-viewer", $attributes);
		return $this->linkWrap( ["class" => "mw-file-description"], $output );
	}
}