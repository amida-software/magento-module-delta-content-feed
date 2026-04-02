<?php
declare(strict_types=1);

namespace Amida\ProductDeltaFeed\Model\Feed;

class ProtoWriter
{
    public function uint64(int $fieldNumber, int $value): string
    {
        return $this->key($fieldNumber, 0) . $this->varint($value);
    }

    public function int32(int $fieldNumber, int $value): string
    {
        return $this->key($fieldNumber, 0) . $this->varint($value);
    }

    public function sint32(int $fieldNumber, int $value): string
    {
        $zigzag = ($value << 1) ^ ($value >> 31);
        return $this->key($fieldNumber, 0) . $this->varint($zigzag);
    }

    public function bool(int $fieldNumber, bool $value): string
    {
        return $this->key($fieldNumber, 0) . $this->varint($value ? 1 : 0);
    }

    public function string(int $fieldNumber, string $value): string
    {
        return $this->key($fieldNumber, 2) . $this->lengthDelimited($value);
    }

    public function bytes(int $fieldNumber, string $value): string
    {
        return $this->key($fieldNumber, 2) . $this->lengthDelimited($value);
    }

    public function double(int $fieldNumber, float $value): string
    {
        return $this->key($fieldNumber, 1) . pack('e', $value);
    }

    public function message(int $fieldNumber, string $encodedMessage): string
    {
        return $this->key($fieldNumber, 2) . $this->lengthDelimited($encodedMessage);
    }

    private function key(int $fieldNumber, int $wireType): string
    {
        return $this->varint(($fieldNumber << 3) | $wireType);
    }

    private function lengthDelimited(string $value): string
    {
        return $this->varint(strlen($value)) . $value;
    }

    private function varint(int $value): string
    {
        $buffer = '';
        if ($value < 0) {
            // treat as uint64 two's complement on 64-bit php
            $value = $value & 0xFFFFFFFFFFFFFFFF;
        }
        while (true) {
            if (($value & ~0x7F) === 0) {
                $buffer .= chr($value);
                break;
            }
            $buffer .= chr(($value & 0x7F) | 0x80);
            $value >>= 7;
        }
        return $buffer;
    }
}
