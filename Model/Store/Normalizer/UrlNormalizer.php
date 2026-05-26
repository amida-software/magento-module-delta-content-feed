<?php
declare(strict_types=1);

namespace Amida\ProductDeltaFeed\Model\Store\Normalizer;

class UrlNormalizer
{
    public function normalize(?string $url, string $baseUrl, array &$diagnostics = [], string $field = 'url'): ?string
    {
        $url = trim((string)$url);
        if ($url === '') {
            return null;
        }

        if (str_starts_with($url, 'tel:') || str_starts_with($url, 'mailto:')) {
            return $url;
        }

        if (preg_match('#^[a-z][a-z0-9+.-]*:#i', $url) && !preg_match('#^https?://#i', $url)) {
            $diagnostics[] = [
                'code' => 'unsupported_url_scheme',
                'level' => 'warning',
                'message' => sprintf('Unsupported URL scheme rejected for %s.', $field),
            ];
            return null;
        }

        if (!preg_match('#^https?://#i', $url)) {
            $url = rtrim($baseUrl, '/') . '/' . ltrim($url, '/');
        }

        $parts = parse_url($url);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            $diagnostics[] = [
                'code' => 'invalid_url',
                'level' => 'warning',
                'message' => sprintf('Invalid URL rejected for %s.', $field),
            ];
            return null;
        }
        $scheme = strtolower((string)$parts['scheme']);
        if (!in_array($scheme, ['http', 'https'], true)) {
            $diagnostics[] = [
                'code' => 'unsupported_url_scheme',
                'level' => 'warning',
                'message' => sprintf('Unsupported URL scheme rejected for %s.', $field),
            ];
            return null;
        }

        $path = $parts['path'] ?? '/';
        $path = preg_replace('#/{2,}#', '/', $path) ?: '/';
        $query = isset($parts['query']) ? $this->sanitizeQuery((string)$parts['query']) : '';
        $port = isset($parts['port']) ? ':' . (int)$parts['port'] : '';
        $normalized = $scheme . '://' . $parts['host'] . $port . $path;
        if ($query !== '') {
            $normalized .= '?' . $query;
        }
        return $normalized;
    }

    private function sanitizeQuery(string $query): string
    {
        parse_str($query, $params);
        unset($params['SID'], $params['sid'], $params['___SID']);
        return http_build_query($params);
    }
}
