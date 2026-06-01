<?php
declare(strict_types=1);

namespace Amida\ProductDeltaFeed\Model\Feed;

/**
 * Generic protobuf wrapper for OpenAPI-defined JSON payloads.
 *
 * The JSON payload remains the single contract surface documented in docs/openapi.yaml.
 * Protobuf mode wraps the same canonical JSON document with path/schema metadata, so new
 * endpoints become protobuf-capable without creating hand-maintained duplicate message graphs.
 */
class OpenApiDocumentEncoder
{
    public function __construct(private readonly ProtoWriter $writer)
    {
    }

    /** @param array<string, mixed> $payload */
    public function encode(array $payload, string $entity, string $openApiPath, string $schemaRef): string
    {
        $json = $this->canonicalJson($payload);
        $message = '';
        $message .= $this->writer->int32(1, (int)($payload['schema_version'] ?? 1));
        $message .= $this->writer->string(2, $entity);
        $message .= $this->writer->string(3, $openApiPath);
        $message .= $this->writer->string(4, $schemaRef);
        $message .= $this->writer->string(5, 'application/json');
        $message .= $this->writer->bytes(6, $json);
        $message .= $this->writer->string(7, hash('sha256', $json));
        $message .= $this->writer->string(8, gmdate('c'));
        return $message;
    }

    /** @param array<string, mixed> $payload */
    public function canonicalJson(array $payload): string
    {
        $payload = $this->sortRecursively($payload);
        return (string)json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function sortRecursively(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        $isList = array_keys($value) === range(0, count($value) - 1);
        if (!$isList) {
            ksort($value);
        }
        foreach ($value as $key => $child) {
            $value[$key] = $this->sortRecursively($child);
        }
        return $value;
    }
}
