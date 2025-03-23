<?php

namespace MediaWiki\Extension\GLTFHandler\Parser;

use InvalidArgumentException;

final class GLTFBufferView{

	public const TARGET_ARRAY_BUFFER = 34962;
	public const TARGET_ELEMENT_ARRAY_BUFFER = 34963;

	public function __construct(
		public int $buffer,
		public int $byte_length,
		public int $byte_offset,
		public ?int $byte_stride,
		public ?int $target,
		public ?string $name,
		public array $extensions,
		public array $extras
	){
		$this->buffer >= 0 || throw new InvalidArgumentException("Expected 'buffer' >= 0, got {$this->buffer}");
		$this->byte_length >= 1 || throw new InvalidArgumentException("Expected 'byteLength' >= 1, got {$this->byte_length}");
		$this->byte_offset >= 0 || throw new InvalidArgumentException("Expected 'byteOffset' >= 0, got {$this->byte_offset}");
		if($this->byte_stride !== null && ($this->byte_stride < 4 || $this->byte_stride > 252)){
			throw new InvalidArgumentException("Expected 'byteStride' >= 4, <= 252, got {$this->byte_stride}");
		}
		if($this->target !== null && $this->target !== self::TARGET_ARRAY_BUFFER && $this->target !== self::TARGET_ELEMENT_ARRAY_BUFFER){
			throw new InvalidArgumentException("Expected 'target' to be one of: " . self::TARGET_ARRAY_BUFFER . ", " . self::TARGET_ELEMENT_ARRAY_BUFFER . ", got {$this->target}");
		}
	}
}