<?php

namespace MediaWiki\Extension\GLTFHandler\Tests;

use MediaWiki\MainConfigNames;

/**
 * @covers \MediaWiki\Extension\GLTFHandler\GLTFTransformOutput
 * @group Database
 */
class ImagePageTest extends \MediaWikiIntegrationTestCase{

	public function testGLBFilePageRenders() : void{
		$path = $this->getNewTempDirectory() . "/test.glb";
		file_put_contents($path, base64_decode("Z2xURgIAAABkAQAAJAEAAEpTT057ImFzc2V0Ijp7InZlcnNpb24iOiIyLjAifSwiYnVmZmVycyI6W3siYnl0ZUxlbmd0aCI6MzZ9XSwiYnVmZmVyVmlld3MiOlt7ImJ1ZmZlciI6MCwiYnl0ZUxlbmd0aCI6MzZ9XSwiYWNjZXNzb3JzIjpbeyJidWZmZXJWaWV3IjowLCJjb21wb25lbnRUeXBlIjo1MTI2LCJjb3VudCI6MywidHlwZSI6IlZFQzMifV0sIm1lc2hlcyI6W3sicHJpbWl0aXZlcyI6W3siYXR0cmlidXRlcyI6eyJQT1NJVElPTiI6MH19XX1dLCJub2RlcyI6W3sibWVzaCI6MH1dLCJzY2VuZXMiOlt7Im5vZGVzIjpbMF19XSwic2NlbmUiOjB9JAAAAEJJTgAAAAAAAAAAAAAAAAAAAIA/AAAAAAAAAAAAAAAAAACAPwAAAAA="));
		$file = $this->getServiceContainer()->getRepoGroup()->getLocalRepo()->newFile("GLTFHandlerTest.glb");
		$this->assertStatusGood($file->upload($path, "test", "", uploader: $this->getTestUser()->getAuthority()));
		$this->overrideConfigValue(MainConfigNames::ImageLimits, [[100, 100]]);
		$page = new \ImagePage($file->getTitle());
		$page->getContext()->getOutput()->setTitle($file->getTitle());
		$page->view();
		self::assertStringContainsString("<model-viewer", $page->getContext()->getOutput()->getHTML());
	}
}
