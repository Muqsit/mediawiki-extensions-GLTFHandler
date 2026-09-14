<?php

use MediaWiki\Extension\GLTFHandler\Constants;

$magicWords = [ "en" => [] ];
foreach ( Constants::PARAMS as $value ) {
	$magicWords["en"][$value["magic_word_id"]] = [ 0, match ( $value["type"] ) {
		"bool" => $value["name"],
		default => $value["name"] . "=\$1"
	} ];
}
