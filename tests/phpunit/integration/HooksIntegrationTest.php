<?php

namespace MediaWiki\Extension\GLTFHandler\Tests;

use MediaWiki\Context\RequestContext;
use MediaWiki\HookContainer\HookRunner;

/**
 * @covers \MediaWiki\Extension\GLTFHandler\Hooks
 * @group Database
 */
class HooksIntegrationTest extends \MediaWikiIntegrationTestCase {

	public function testBeforePageDisplayDoesNotLoadModelViewer(): void {
		$context = new RequestContext();
		$out = $context->getOutput();
		$skin = $context->getSkin();

		$hookRunner = new HookRunner( $this->getServiceContainer()->getHookContainer() );
		$hookRunner->onBeforePageDisplay( $out, $skin );

		self::assertNotContains( "ext.gltfHandler", $out->getModules() );
		self::assertContains( "ext.gltfHandler.scripts", $out->getModules() );
	}
}
