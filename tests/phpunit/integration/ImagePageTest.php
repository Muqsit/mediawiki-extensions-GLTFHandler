<?php

namespace MediaWiki\Extension\GLTFHandler\Tests;

use Wikimedia\Parsoid\Utils\DOMCompat;
use Wikimedia\Parsoid\Utils\DOMUtils;
use function dirname;

/**
 * @covers \MediaWiki\Extension\GLTFHandler\GLTFHandler
 * @covers \MediaWiki\Extension\GLTFHandler\GLTFTransformOutput
 * @group Database
 */
class ImagePageTest extends \MediaWikiIntegrationTestCase {

	public function testGLBFilePageRenders(): void {
		$file = $this->getServiceContainer()->getRepoGroup()->getLocalRepo()->newFile( "GLTFHandlerTest.glb" );
		$this->assertStatusGood( $file->upload( dirname( __DIR__, 2 ) . "/resources/BoxInterleaved.glb", "test", "", uploader: $this->getTestUser()->getAuthority() ) );
		$page = new \ImagePage( $file->getTitle() );
		$page->getContext()->getOutput()->setTitle( $file->getTitle() );
		$page->view();
		$viewer = DOMCompat::querySelector( DOMUtils::parseHTML( $page->getContext()->getOutput()->getHTML() ), "model-viewer" );
		self::assertNotNull( $viewer );
		self::assertSame( $file->getFullUrl(), $viewer->getAttribute( "src" ) );
		self::assertSame( "model-viewer-dynsize", $viewer->getAttribute( "class" ) );
	}

	public function testParamsRenderAsModelViewerAttributes(): void {
		$file = $this->getServiceContainer()->getRepoGroup()->getLocalRepo()->newFile( "GLTFHandlerTest.glb" );
		$this->assertStatusGood( $file->upload( dirname( __DIR__, 2 ) . "/resources/BoxInterleaved.glb", "test", "", uploader: $this->getTestUser()->getAuthority() ) );
		$viewer = DOMCompat::querySelector( DOMUtils::parseHTML( $this->getServiceContainer()->getParser()->parse(
			"[[File:GLTFHandlerTest.glb|100px|ar|autoplay|camera-orbit=-30deg 90deg 22|environment=GLTFHandlerTest.glb]]",
			$file->getTitle(), \MediaWiki\Parser\ParserOptions::newFromAnon()
		)->getContentHolderText() ), "model-viewer" );
		self::assertNotNull( $viewer );
		self::assertSame( "100", $viewer->getAttribute( "width" ) );
		self::assertSame( "1", $viewer->getAttribute( "ar" ) );
		self::assertSame( "", $viewer->getAttribute( "autoplay" ) );
		self::assertSame( "-30deg 90deg 22", $viewer->getAttribute( "camera-orbit" ) );
		self::assertSame( $file->getUrl(), $viewer->getAttribute( "environment-image" ) );
	}
}
