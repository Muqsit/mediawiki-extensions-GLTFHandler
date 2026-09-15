<?php

namespace MediaWiki\Extension\GLTFHandler\Tests;

use InvalidArgumentException;
use MediaWiki\Extension\GLTFHandler\Parser\GLTFParser;
use function base64_encode;
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
	public function testStats( string $file, array $expected ): void {
		$path = dirname( __DIR__, 2 ) . "/resources/{$file}";
		$stats = ( new GLTFParser( $path ) )->computeStats();
		$keys = [ "drawCallCount", "animationCount", "materialCount", "totalVertexCount", "totalTriangleCount" ];
		foreach ( $keys as $index => $stat ) {
			self::assertSame( $expected[$index], $stats[$stat], $stat );
		}
	}

	public static function provideModels(): array {
		// Expected counts: draw calls, animations, materials, vertices, triangles.
		return [
			"interleaved accessors" => [ "BoxInterleaved.glb", [ 1, 0, 1, 24, 12 ] ],
			"embedded texture" => [ "BoxTextured.glb", [ 1, 0, 1, 24, 12 ] ],
			"animation" => [ "BoxAnimated.glb", [ 2, 1, 2, 320, 254 ] ],
			"skinning" => [ "RiggedSimple.glb", [ 1, 1, 1, 160, 188 ] ],
			"morph targets" => [ "AnimatedMorphCube.glb", [ 1, 1, 1, 24, 12 ] ],
			"production mesh" => [ "Duck.glb", [ 1, 0, 1, 2399, 4212 ] ],
			"multi-part scene" => [ "CesiumMilkTruck.glb", [ 4, 1, 4, 3995, 2856 ] ],
			"non-indexed geometry" => [ "TriangleWithoutIndices.gltf", [ 1, 0, 0, 3, 1 ] ]
		];
	}

	public function testRejectsGlbChunkLengthBeyondFile(): void {
		$path = $this->getNewTempDirectory() . "/test.glb";
		file_put_contents( $path, pack( "V*", GLTFParser::HEADER_MAGIC, 2, 20, 0xffffffff, GLTFParser::CHUNK_JSON ) );
		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionCode( GLTFParser::ERR_INVALID_SCHEMA );
		new GLTFParser( $path );
	}

	public function testDimensionsRespectByteStrideAndParentScale(): void {
		$path = $this->getNewTempDirectory() . "/test.gltf";
		$data = pack( "g*", 1, 2, 3, 100, 4, 6, 8, 100 );
		file_put_contents( $path, json_encode( [
			"asset" => [ "version" => "2.0" ],
			"buffers" => [ [ "byteLength" => 32, "uri" => "data:application/octet-stream;base64," . base64_encode( $data ) ] ],
			"bufferViews" => [ [ "buffer" => 0, "byteLength" => 32, "byteStride" => 16 ] ],
			"accessors" => [ [ "bufferView" => 0, "componentType" => 5126, "count" => 2, "type" => "VEC3" ] ],
			"meshes" => [ [ "primitives" => [ [ "mode" => 0, "attributes" => [ "POSITION" => 0 ] ] ] ] ],
			"nodes" => [ [ "scale" => [ 2, 3, 4 ], "children" => [ 1 ] ], [ "mesh" => 0 ] ],
			"scenes" => [ [ "nodes" => [ 0 ] ] ]
		] ) );
		$parser = new GLTFParser( $path );
		self::assertSame( [ 1.0, 2.0, 3.0, 4.0, 6.0, 8.0 ], $parser->accessor_values[0][3] );
		self::assertSame( [ 6.0, 12.0, 20.0 ], $parser->computeModelDimensions() );
	}

	public function testSparseAccessorZeroFillsUnspecifiedVertices(): void {
		$path = $this->getNewTempDirectory() . "/test.gltf";
		$data = pack( "Vg*", 1, 2, 3, 4 );
		file_put_contents( $path, json_encode( [
			"asset" => [ "version" => "2.0" ],
			"buffers" => [ [ "byteLength" => 16, "uri" => "data:application/octet-stream;base64," . base64_encode( $data ) ] ],
			"bufferViews" => [ [ "buffer" => 0, "byteLength" => 4 ], [ "buffer" => 0, "byteOffset" => 4, "byteLength" => 12 ] ],
			"accessors" => [ [ "componentType" => 5126, "count" => 3, "type" => "VEC3", "sparse" => [ "count" => 1, "indices" => [ "bufferView" => 0, "componentType" => 5125 ], "values" => [ "bufferView" => 1 ] ] ] ]
		] ) );
		$parser = new GLTFParser( $path );
		self::assertEquals( [ 0, 0, 0, 2, 3, 4, 0, 0, 0 ], $parser->accessor_values[0][3] );
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
