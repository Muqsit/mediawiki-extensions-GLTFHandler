<?php

namespace MediaWiki\Extension\GLTFHandler\Tests;

use MediaWiki\Extension\GLTFHandler\Parser\GLTFParser;
use MediaWiki\Shell\Shell;
use function dirname;

/**
 * @covers \MediaWiki\Extension\GLTFHandler\Parser\GLTFParser
 */
class GLTFParserTest extends \MediaWikiIntegrationTestCase{

	/**
	 * @dataProvider provideModels
	 */
	public function testStatsMatchAssimp(string $file) : void{
		$path = dirname(__DIR__, 2) . "/resources/{$file}";
		$result = Shell::command("assimp", "info", $path, "-r")->includeStderr()->execute();
		$output = $result->getStdout();
		self::assertSame(0, $result->getExitCode(), $output);
		$stats = (new GLTFParser($path))->computeStats();
		$stats["materialCount"]++; // Assimp includes its default material.
		foreach([
			"Meshes" => "drawCallCount",
			"Animations" => "animationCount",
			"Materials" => "materialCount",
			"Vertices" => "totalVertexCount",
			"Faces" => "totalTriangleCount"
		] as $label => $stat){
			self::assertMatchesRegularExpression("/^{$label}:\\s+{$stats[$stat]}$/m", $output, $file);
		}
	}

	public static function provideModels() : array{
		return [
			"interleaved accessors" => ["BoxInterleaved.glb"],
			"embedded texture" => ["BoxTextured.glb"],
			"animation" => ["BoxAnimated.glb"],
			"skinning" => ["RiggedSimple.glb"],
			"morph targets" => ["AnimatedMorphCube.glb"],
			"production mesh" => ["Duck.glb"],
			"multi-part scene" => ["CesiumMilkTruck.glb"],
			"non-indexed geometry" => ["TriangleWithoutIndices.gltf"]
		];
	}
}
