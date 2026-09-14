<?php

namespace MediaWiki\Extension\GLTFHandler\Tests;

use InvalidArgumentException;
use MediaWiki\Extension\GLTFHandler\Parser\GLTFParser;
use MediaWiki\Shell\Shell;
use function dirname;
use function file_put_contents;
use function json_encode;
use function pack;

/**
 * @covers \MediaWiki\Extension\GLTFHandler\Parser\GLTFParser
 */
class GLTFParserTest extends \MediaWikiIntegrationTestCase {

	/**
	 * @dataProvider provideModels
	 */
	public function testStatsMatchAssimp( string $file ): void {
		$path = dirname( __DIR__, 2 ) . "/resources/{$file}";
		$result = Shell::command( "assimp", "info", $path, "-r" )->includeStderr()->execute();
		$output = $result->getStdout();
		self::assertSame( 0, $result->getExitCode(), $output );
		$stats = ( new GLTFParser( $path ) )->computeStats();
		// Assimp includes its default material.
		$stats["materialCount"]++;
		foreach ( [
			"Meshes" => "drawCallCount",
			"Animations" => "animationCount",
			"Materials" => "materialCount",
			"Vertices" => "totalVertexCount",
			"Faces" => "totalTriangleCount"
		] as $label => $stat ) {
			self::assertMatchesRegularExpression( "/^{$label}:\\s+{$stats[$stat]}$/m", $output, $file );
		}
	}

	public static function provideModels(): array {
		return [
			"interleaved accessors" => [ "BoxInterleaved.glb" ],
			"embedded texture" => [ "BoxTextured.glb" ],
			"animation" => [ "BoxAnimated.glb" ],
			"skinning" => [ "RiggedSimple.glb" ],
			"morph targets" => [ "AnimatedMorphCube.glb" ],
			"production mesh" => [ "Duck.glb" ],
			"multi-part scene" => [ "CesiumMilkTruck.glb" ],
			"non-indexed geometry" => [ "TriangleWithoutIndices.gltf" ]
		];
	}

	public function testRejectsGlbChunkLengthBeyondFile(): void {
		$path = $this->getNewTempDirectory() . "/test.glb";
		file_put_contents( $path, pack( "V*", GLTFParser::HEADER_MAGIC, 2, 20, 0xffffffff, GLTFParser::CHUNK_JSON ) );
		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionCode( GLTFParser::ERR_INVALID_SCHEMA );
		new GLTFParser( $path );
	}

	public function testRejectsCumulativeResolvedResourcesBeyondLimit(): void {
		$path = $this->getNewTempDirectory() . "/test.gltf";
		$buffer = "data:application/octet-stream;base64,AAA=";
		file_put_contents( $path, json_encode( [ "asset" => [ "version" => "2.0" ], "accessors" => [], "buffers" => [ [ "byteLength" => 2, "uri" => $buffer ], [ "byteLength" => 2, "uri" => $buffer ] ] ] ) );
		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionCode( GLTFParser::ERR_INVALID_SCHEMA );
		new GLTFParser( $path, max_resolved_resource_bytes: 3 );
	}

	public function testLimitsDimensionWork(): void {
		$path = dirname( __DIR__, 2 ) . "/resources/BoxInterleaved.glb";
		$parser = new GLTFParser( $path );
		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionCode( GLTFParser::ERR_INVALID_SCHEMA );
		$parser->computeModelDimensions( max_transformed_vertices: 1 );
	}

	public function testSharedChildIsProcessedOnce(): void {
		$path = dirname( __DIR__, 2 ) . "/resources/BoxInterleaved.glb";
		$parser = new GLTFParser( $path );
		$parser->properties = [
			"scenes" => [ [ "nodes" => [ 0 ] ] ],
			"nodes" => [ [ "children" => [ 1, 2 ] ], [ "children" => [ 3 ] ], [ "children" => [ 3 ] ], [ "mesh" => 0 ] ],
			"meshes" => [ [ "primitives" => [ [ "attributes" => [ "POSITION" => 0 ] ] ] ] ]
		];
		$parser->accessor_values = [ [ $parser->accessor_values[0][0], 3, 1, [ 0.0, 0.0, 0.0 ] ] ];
		self::assertSame( [ 0.0, 0.0, 0.0 ], $parser->computeModelDimensions( max_node_references: 10, max_transformed_vertices: 1 ) );
	}
}
