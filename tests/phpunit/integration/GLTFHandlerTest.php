<?php

namespace MediaWiki\Extension\GLTFHandler\Tests;

/**
 * @covers \MediaWiki\Extension\GLTFHandler\GLTFHandler
 */
class GLTFHandlerTest extends \MediaWikiIntegrationTestCase{

	public function testAccessorLimit() : void{
		$path = $this->getNewTempDirectory() . "/test.gltf";
		file_put_contents($path, json_encode([
			"asset" => ["version" => "2.0"],
			"accessors" => [["componentType" => 5126, "count" => 1 << 18, "type" => "SCALAR"]]
		]));
		$handler = $this->getServiceContainer()->getMediaHandlerFactory()->getHandler("model/gltf+json");
		$this->assertStatusNotGood($handler->verifyUpload($path));
	}
}
