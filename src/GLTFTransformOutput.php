<?php

namespace MediaWiki\Extension\GLTFHandler;

use MediaWiki\FileRepo\File\File;
use MediaWiki\Html\Html;
use MediaWiki\MediaWikiServices;
use function is_string;

class GLTFTransformOutput extends \MediaTransformOutput {

	private array $pParams;

	/**
	 * @param File $File
	 * @param float $Width
	 * @param float $Height
	 * @param array $Params
	 */
	public function __construct( $File, $Width, $Height, $Params ) {
		$this->file = $File;
		$this->width = (float)$Width;
		$this->height = (float)$Height;
		$this->pParams = $Params;
		$this->url = ""; // to have SearchResultThumbnailProvider::buildSearchResultThumbnailFromFile() return null
	}

	public function toHtml( $options = [] ) {
		$attributes = [];
		foreach(Constants::PARAMS as $value){
			$user_value = $this->pParams[$value["name"]] ?? $value["default"];
			if($value["type"] === "bool"){
				$attributes[$value["name"]] = $user_value;
			}elseif($value["type"] === "file"){
				if(is_string($user_value)){
					$file = MediaWikiServices::getInstance()->getRepoGroup()->findFile($this->pParams["poster"]);
					if($file !== false && $file->isLocal() && $file->canRender()){
						$attributes[$value["attributes"]["mv_param"]] = $file->getUrl();
					}
				}
			}else{
				$attributes[$value["name"]] = $user_value;
			}
		}

		$attributes["src"] = $this->file->getFullUrl();

		if($this->height > 0 && $this->width > 0){
			if(isset($this->pParams["width"])){
				$width = (float)$this->pParams["width"];
				$height = $width * ($this->height / $this->width);
				$attributes["style"] = "width: {$width}px; height: {$height}px;";
			}else{
				$width = $this->width;
				$height = $this->height;
			}
		}else{
			$width = $height = null;
		}

		// attributes for dynamic resizing
		$attributes["class"] = "model-viewer-dynsize";
		$attributes["data-width"] = $width;
		$attributes["data-height"] = $height;

		$output = Html::element("model-viewer", $attributes);
		return $this->linkWrap( ["class" => "mw-file-description"], $output );
	}
}