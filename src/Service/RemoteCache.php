<?php

namespace App\Service;

use Symfony\Contracts\HttpClient\HttpClientInterface;
/** Public reference data only. Never cache account, weight, password or session data here. */
class RemoteCache
{
    private string $directory;
    public function __construct(private HttpClientInterface $http, string $projectDir)
    {
        $this->directory = $projectDir . '/var/remote';
    }

    public function read(string $key, int $ttl): ?array
    {
        $path = $this->directory . '/' . hash('sha256', $key) . '.json';
        if (!is_file($path)) {
            return null;
        }
        $data = json_decode((string) @file_get_contents($path), true);
        if (!is_array($data) || !isset($data['saved_at']) || !array_key_exists('data', $data)) {
            return null;
        }
        return $data + ['stale' => $data['saved_at'] < time() - $ttl];
    }

    public function write(string $key, mixed $data): void
    {
        if (
            !is_dir($this->directory) &&
            !@mkdir($this->directory, 0770, true) &&
            !is_dir($this->directory)
        ) {
            return;
        }
        $path = $this->directory . '/' . hash('sha256', $key) . '.json';
        $tmp = $path . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (
            @file_put_contents(
                $tmp,
                json_encode(['saved_at' => time(), 'data' => $data], JSON_INVALID_UTF8_SUBSTITUTE),
            ) !== false
        ) {
            @rename($tmp, $path);
        }
    }

    public function many(array $queries, int $ttl = 900): array
    {
        $out = [];
        $pending = [];
        foreach ($queries as $id => $q) {
            $key = $q['url'] . '?' . http_build_query($q['query'] ?? []);
            $old = $this->read($key, $ttl);
            if ($old && !$old['stale']) {
                $out[$id] = [
                    'data' => $old['data'],
                    'cached' => true,
                    'stale' => false,
                    'error' => null,
                ];
                continue;
            }
            try {
                $pending[$id] = [
                    'key' => $key,
                    'old' => $old,
                    'response' => $this->http->request('GET', $q['url'], [
                        'query' => $q['query'] ?? [],
                        'headers' => [
                            'Accept' => 'application/json',
                            'User-Agent' => 'Gym-App-Symfony/2.0',
                        ],
                        'timeout' => $q['timeout'] ?? 10,
                        'max_duration' => $q['max_duration'] ?? 25,
                        'max_redirects' => 3,
                    ]),
                ];
            } catch (\Throwable) {
                $out[$id] = [
                    'data' => $old['data'] ?? null,
                    'cached' => (bool) $old,
                    'stale' => (bool) $old,
                    'error' => 'Source indisponible.',
                ];
            }
        }
        foreach ($pending as $id => $p) {
            try {
                $data = $p['response']->toArray();
                if (
                    str_contains($p['key'], 'api.mymemory.translated.net/') &&
                    ((int) ($data['responseStatus'] ?? 0) !== 200 ||
                        ($data['quotaFinished'] ?? false))
                ) {
                    throw new \RuntimeException('Translation unavailable');
                }
                $this->write($p['key'], $data);
                $out[$id] = ['data' => $data, 'cached' => false, 'stale' => false, 'error' => null];
            } catch (\Throwable) {
                $out[$id] = [
                    'data' => $p['old']['data'] ?? null,
                    'cached' => (bool) $p['old'],
                    'stale' => (bool) $p['old'],
                    'error' => 'Source indisponible, données conservées si disponibles.',
                ];
            }
        }
        return $out;
    }

    public function json(string $url, array $query = [], int $ttl = 900): array
    {
        return $this->many([['url' => $url, 'query' => $query]], $ttl)[0];
    }
    public static function safeUrl(mixed $v): string
    {
        return is_string($v) && preg_match('~^https?://[^\s<>"\x27]+$~i', $v) ? $v : '';
    }
    public static function normalize(string $v): string
    {
        $v = mb_strtolower(str_replace(['œ', 'Œ'], ['oe', 'oe'], $v));
        $v = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $v) ?: $v;
        return trim(preg_replace('/[^a-z0-9]+/', ' ', strtolower($v)));
    }
}
