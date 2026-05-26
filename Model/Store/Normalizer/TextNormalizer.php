<?php
declare(strict_types=1);

namespace Amida\ProductDeltaFeed\Model\Store\Normalizer;

class TextNormalizer
{
    public function normalize(?string $value, int $maxLength = 500): ?string
    {
        $value = (string)$value;
        $value = preg_replace('#<script\b[^>]*>.*?</script>#is', ' ', $value) ?? $value;
        $value = preg_replace('#<style\b[^>]*>.*?</style>#is', ' ', $value) ?? $value;
        $value = strip_tags($value);
        $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        if (function_exists('mb_substr') && function_exists('mb_strlen')) {
            if (mb_strlen($value) > $maxLength) {
                $value = rtrim(mb_substr($value, 0, $maxLength - 1)) . '…';
            }
            return $value;
        }
        return strlen($value) > $maxLength ? rtrim(substr($value, 0, $maxLength - 1)) . '…' : $value;
    }

    public function excerpt(?string $html, int $maxLength = 500): ?string
    {
        return $this->normalize($html, $maxLength);
    }
}
