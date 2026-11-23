<?php

namespace App\Service;
class Translator
{
    private array $localMaps = [];
    public function __construct(private RemoteCache $cache, private Nutrition $nutrition) {}
    public function local(string $text, string $dictionary): string
    {
        if (!isset($this->localMaps[$dictionary])) {
            $this->localMaps[$dictionary] = [];
            foreach ($this->nutrition->catalog($dictionary) as $from => $to) {
                $this->localMaps[$dictionary][RemoteCache::normalize($from)] = (string) $to;
            }
        }
        return $this->localMaps[$dictionary][RemoteCache::normalize($text)] ?? $text;
    }
    /** Parallel, cached translations; source text is retained when the service fails. */
    public function many(array $texts): array
    {
        $queries = [];
        $out = [];
        foreach ($texts as $key => $text) {
            $text = trim((string) $text);
            $out[$key] = $text;
            if ($text === '') {
                continue;
            }
            // MyMemory limits each source segment to 500 bytes; never break a UTF-8 sequence.
            $safe = mb_strcut($text, 0, 480, 'UTF-8');
            $queries[$key] = [
                'url' => 'https://api.mymemory.translated.net/get',
                'timeout' => 4,
                'max_duration' => 8,
                'query' => ['q' => $safe, 'langpair' => 'en|fr'],
            ];
        }
        foreach ($this->cache->many($queries, 86400 * 30) as $key => $result) {
            $p = $result['data'];
            $translation = is_array($p)
                ? trim((string) ($p['responseData']['translatedText'] ?? ''))
                : '';
            if (
                (int) ($p['responseStatus'] ?? 0) === 200 &&
                $translation !== '' &&
                strtolower($translation) !== 'null' &&
                !($p['quotaFinished'] ?? false)
            ) {
                $out[$key] =
                    html_entity_decode($translation, ENT_QUOTES | ENT_HTML5, 'UTF-8') .
                    (strlen($texts[$key]) > 480 ? ' […]' : '');
            }
        }
        return $out;
    }

    public function measure(string $value): string
    {
        $rules = [
            '/\btablespoons?\b|\btbsp\b|\btblsp\b/i' => 'c. à soupe',
            '/\bteaspoons?\b|\btsp\b/i' => 'c. à café',
            '/\bcups?\b/i' => 'tasse',
            '/\bpinch\b/i' => 'pincée',
            '/\bto taste\b/i' => 'selon le goût',
            '/\bhandful\b/i' => 'poignée',
            '/\bbunch\b/i' => 'bouquet',
            '/\bslices?\b/i' => 'tranches',
            '/\bsprigs?\b/i' => 'brins',
            '/\bcans?\b/i' => 'boîtes',
            '/\bjuice of\b/i' => 'jus de',
            '/\bzest of\b/i' => 'zeste de',
        ];
        return preg_replace(array_keys($rules), array_values($rules), $value);
    }
}
