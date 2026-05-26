<?php
declare(strict_types=1);

namespace Amida\ProductDeltaFeed\Model\Store\Normalizer;

class SpecialNormalizer
{
    private const ALLOWED = [
        'index', 'blog', 'news', 'faq', 'offline_stores', 'contacts', 'public_offer', 'policy',
        'privacy_policy', 'terms', 'about', 'brands', 'catalog', 'sitemap', 'vacancy', 'delivery',
        'payment', 'promos', 'loyalty', 'landing', 'claims', 'returns', 'warranty', 'checkout',
        'cart', 'account', 'login', 'register', 'compare', 'wishlist', 'custom',
    ];

    private const ALIASES = [
        'devilery' => 'delivery',
        'loyality' => 'loyalty',
        'loyalities' => 'loyalty',
        'policies' => 'policy',
        'privacy' => 'privacy_policy',
        'offer' => 'public_offer',
        'oferta' => 'public_offer',
        'stores' => 'offline_stores',
        'shops' => 'offline_stores',
        'contacts-us' => 'contacts',
        'contacts_us' => 'contacts',
    ];

    /**
     * @param mixed[]|string|null $values
     * @param array<int, array<string, string>> $diagnostics
     * @return string[]
     */
    public function normalize(array|string|null $values, array &$diagnostics = []): array
    {
        if ($values === null || $values === '') {
            return [];
        }
        $items = is_array($values) ? $values : explode(',', (string)$values);
        $out = [];
        foreach ($items as $item) {
            $raw = trim((string)$item);
            if ($raw === '') {
                continue;
            }
            $key = strtolower(str_replace('-', '_', $raw));
            $key = self::ALIASES[$key] ?? self::ALIASES[$raw] ?? $key;
            if (!in_array($key, self::ALLOWED, true)) {
                $diagnostics[] = [
                    'code' => 'page_special_unknown',
                    'level' => 'warning',
                    'message' => sprintf("Unknown special value '%s' normalized to 'custom'.", $raw),
                ];
                $key = 'custom';
            }
            $out[] = $key;
        }
        return array_values(array_unique($out));
    }

    /** @return string[] */
    public function inferFromText(string $id, string $title = '', string $url = ''): array
    {
        $haystack = strtolower($id . ' ' . $title . ' ' . $url);
        $rules = [
            'index' => ['home', 'homepage', 'головна', 'главная', 'index'],
            'delivery' => ['delivery', 'shipping', 'доставка', 'доставк'],
            'payment' => ['payment', 'оплата', 'pay'],
            'contacts' => ['contact', 'contacts', 'контакт'],
            'about' => ['about', 'о-компании', 'про-нас', 'about-us'],
            'privacy_policy' => ['privacy', 'персональ', 'конфиденц'],
            'policy' => ['policy', 'политик'],
            'public_offer' => ['offer', 'oferta', 'оферта'],
            'returns' => ['return', 'returns', 'возврат', 'повернен'],
            'warranty' => ['warranty', 'гарант'],
            'brands' => ['brand', 'brands', 'бренд'],
            'catalog' => ['catalog', 'каталог'],
            'sitemap' => ['sitemap', 'карта-сайта'],
            'faq' => ['faq', 'частые-вопрос', 'питання'],
            'blog' => ['blog', 'блог'],
            'news' => ['news', 'новост', 'новин'],
            'vacancy' => ['vacancy', 'career', 'работа', 'ваканс'],
            'claims' => ['claim', 'claims', 'претенз'],
            'promos' => ['promo', 'promos', 'акци', 'акції'],
            'loyalty' => ['loyalty', 'loyality', 'лояльн'],
        ];
        $out = [];
        foreach ($rules as $special => $needles) {
            foreach ($needles as $needle) {
                if (str_contains($haystack, $needle)) {
                    $out[] = $special;
                    break;
                }
            }
        }
        return array_values(array_unique($out));
    }
}
