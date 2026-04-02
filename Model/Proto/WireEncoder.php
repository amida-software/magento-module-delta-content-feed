<?php
declare(strict_types=1);

namespace Amida\ProductDeltaFeed\Model\Proto;

class WireEncoder
{
    public function uintField(int $fieldNumber, int $value): string
    {
        return $this->tag($fieldNumber, 0) . $this->varint($value);
    }

    public function boolField(int $fieldNumber, bool $value): string
    {
        return $this->uintField($fieldNumber, $value ? 1 : 0);
    }

    public function stringField(int $fieldNumber, ?string $value): string
    {
        if ($value === null || $value == '') {
            return '';
        }
        return $this->tag($fieldNumber, 2) . $this->bytes((string)$value);
    }

    public function messageField(int $fieldNumber, string $payload): string
    {
        if ($payload === '') {
            return '';
        }
        return $this->tag($fieldNumber, 2) . $this->bytes($payload);
    }

    public function bytes(string $payload): string
    {
        return $this->varint(strlen($payload)) . $payload;
    }

    public function varint(int $value): string
    {
        $value = max(0, $value);
        $out = '';
        while (true) {
            if (($value & ~0x7F) === 0) {
                $out .= chr($value);
                return $out;
            }
            $out .= chr(($value & 0x7F) | 0x80);
            $value >>= 7;
        }
    }

    private function tag(int $fieldNumber, int $wireType): string
    {
        return $this->varint(($fieldNumber << 3) | $wireType);
    }
}
