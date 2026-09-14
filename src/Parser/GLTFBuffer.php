<?php

namespace MediaWiki\Extension\GLTFHandler\Parser;

use InvalidArgumentException;

final class GLTFBuffer {

	/**
	 * @param string|null $value Null if unresolved
	 * @param int $byte_length Buffer length in bytes
	 * @param string|null $uri Null if unresolvable
	 * @param string|null $name Buffer name
	 * @param array $extensions Buffer extensions
	 * @param array $extras Application-specific data
	 */
	public function __construct(
		public ?string $value,
		public int $byte_length,
		public ?string $uri,
		public ?string $name,
		public array $extensions,
		public array $extras
	) {
		$this->byte_length >= 1 || throw new InvalidArgumentException( "Expected 'byteLength' >= 1, got {$this->byte_length}" );
	}
}
