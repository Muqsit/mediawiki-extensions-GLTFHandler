<?php

namespace MediaWiki\Extension\GLTFHandler;

final class Constants {

	/**
	 * @var list<array{magic_word_id: string, name: string, type: string, default: bool|null, attributes: array<string, mixed>}>
	 */
	public const PARAMS = [
		[
			"magic_word_id" => "img_width",
			"name" => "width",
			"type" => "string",
			"default" => null,
			"attributes" => []
		],
		[
			"magic_word_id" => "gltfhandler_animation_name",
			"name" => "animation-name",
			"type" => "string",
			"default" => null,
			"attributes" => []
		],
		[
			"magic_word_id" => "gltfhandler_ar",
			"name" => "ar",
			"type" => "bool",
			"default" => false,
			"attributes" => []
		],
		[
			"magic_word_id" => "gltfhandler_autoplay",
			"name" => "autoplay",
			"type" => "bool",
			"default" => false,
			"attributes" => []
		],
		[
			"magic_word_id" => "gltfhandler_camera_controls",
			"name" => "camera-controls",
			"type" => "bool",
			"default" => true,
			"attributes" => []
		],
		[
			"magic_word_id" => "gltfhandler_camera_orbit",
			"name" => "camera-orbit",
			"type" => "string",
			"default" => null,
			"attributes" => []
		],
		[
			"magic_word_id" => "gltfhandler_environment",
			"name" => "environment",
			"type" => "file",
			"default" => null,
			"attributes" => [ "mv_param" => "environment-image" ]
		],
		[
			"magic_word_id" => "gltfhandler_loading",
			"name" => "loading",
			"type" => "string",
			"default" => "eager",
			"attributes" => []
		],
		[
			"magic_word_id" => "gltfhandler_max_camera_orbit",
			"name" => "max-camera-orbit",
			"type" => "string",
			"default" => null,
			"attributes" => []
		],
		[
			"magic_word_id" => "gltfhandler_poster",
			"name" => "poster",
			"type" => "file",
			"default" => null,
			"attributes" => [ "mv_param" => "poster" ]
		],
		[
			"magic_word_id" => "gltfhandler_shadow_intensity",
			"name" => "shadow-intensity",
			"type" => "string",
			"default" => 1,
			"attributes" => []
		],
		[
			"magic_word_id" => "gltfhandler_skybox",
			"name" => "skybox",
			"type" => "file",
			"default" => null,
			"attributes" => [ "mv_param" => "skybox-image" ]
		],
		[
			"magic_word_id" => "gltfhandler_skybox_height",
			"name" => "skybox-height",
			"type" => "string",
			"default" => null,
			"attributes" => []
		],
		[
			"magic_word_id" => "gltfhandler_touch_action",
			"name" => "touch-action",
			"type" => "string",
			"default" => "pan-y",
			"attributes" => []
		],
	];
}
