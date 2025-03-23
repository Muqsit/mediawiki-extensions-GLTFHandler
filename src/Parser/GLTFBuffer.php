<?php

namespace MediaWiki\Extension\GLTFHandler\Parser;

use InvalidArgumentException;

final class GLTFBuffer{

	public function __construct(
		public ?string $value, // null if unresolved
		public int $byte_length,
		public ?string $uri, // null if unresolvable
		public ?string $name,
		public array $extensions,
		public array $extras
	){
		$this->byte_length >= 1 || throw new InvalidArgumentException("Expected 'byteLength' >= 1, got {$this->byte_length}");
	}
}