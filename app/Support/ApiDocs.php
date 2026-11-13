<?php

namespace App\Support;

/**
 * Loads the interactive API docs catalogue + captured fixtures for /docs/api.
 */
class ApiDocs
{
    public static function catalog(): array
    {
        return require resource_path('api-docs/catalog.php');
    }

    public static function fixture(string $name): ?array
    {
        $paths = [
            resource_path("api-docs/fixtures/{$name}.json"),
            resource_path("api-docs/fixtures/{$name}.example.json"),
        ];

        foreach ($paths as $path) {
            if (! is_file($path)) {
                continue;
            }

            $decoded = json_decode((string) file_get_contents($path), true);

            return is_array($decoded) ? $decoded : null;
        }

        return null;
    }

    /**
     * @return array{request?: mixed, response?: mixed, http_status?: int|null, source?: string|null, note?: string|null}
     */
    public static function resolveScenario(array $scenario): array
    {
        $fixtureName = $scenario['fixture'] ?? null;
        $fixture = is_string($fixtureName) ? self::fixture($fixtureName) : null;

        $request = $scenario['request'] ?? ($fixture['request']['body'] ?? ($fixture['request'] ?? null));
        $response = $scenario['response'] ?? ($fixture['body'] ?? null);
        $http = $scenario['http_status'] ?? ($fixture['http_status'] ?? null);
        $note = $scenario['note'] ?? ($fixture['note'] ?? null);

        return [
            'request' => $request,
            'response' => $response,
            'http_status' => $http,
            'source' => $fixtureName ? (str_contains((string) $fixtureName, 'example') || isset($fixture['note']) ? 'example' : 'live') : 'documented',
            'note' => $note,
            'fixture' => $fixtureName,
        ];
    }
}
