<?php

namespace MediaWiki\Extension\GLTFHandler;

use MediaWiki\Hook\BeforePageDisplayHook;
use MediaWiki\Hook\MimeMagicImproveFromExtensionHook;

class Hooks implements BeforePageDisplayHook, MimeMagicImproveFromExtensionHook {

	public function onBeforePageDisplay( $out, $skin ): void {
		$out->addModules(["ext.gltfHandler", "ext.gltfHandler.scripts"]);
	}

	public function onMimeMagicImproveFromExtension($mimeMagic, $ext, &$mime){
		if($ext === "gltf" && $mime === "application/json"){
			$mime = "model/gltf+json";
			return true;
		}
	}
}
