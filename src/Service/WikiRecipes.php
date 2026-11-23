<?php

namespace App\Service;

/** Wikibooks recipe index and on-demand readable recipe details. */
final class WikiRecipes
{
    private const API = "https://en.wikibooks.org/w/api.php";
    private const KEY = "gym:wikibooks:recipe-index:v1";
    public function __construct(private RemoteCache $cache) {}

    public function catalog(): array
    {
        $saved = $this->cache->read(self::KEY, 86400);
        $state = $saved["data"] ?? ["items" => [], "next" => null, "complete" => false];
        if ($saved && !$saved["stale"] && $state["complete"]) {
            return $state;
        }
        if ($saved && $saved["stale"] && $state["complete"]) {
            $state = ["items" => [], "next" => null, "complete" => false];
        }
        $started = microtime(true);
        for ($page = 0; $page < 20 && microtime(true) - $started < 25; $page++) {
            $query = [
                "action" => "query",
                "list" => "categorymembers",
                "cmtitle" => "Category:Recipes",
                "cmtype" => "page",
                "cmlimit" => 500,
                "format" => "json",
                "formatversion" => 2,
            ];
            if ($state["next"]) {
                $query["cmcontinue"] = $state["next"];
            }
            $result = $this->cache->many(
                [["url" => self::API, "query" => $query, "timeout" => 10, "max_duration" => 8]],
                86400,
            )[0];
            if (!isset($result["data"]["query"]["categorymembers"])) {
                $fallback = $saved["data"]["items"] ?? [];
                return [
                    "items" => $state["items"] ?: $fallback,
                    "complete" => false,
                    "error" => true,
                ];
            }
            foreach ($result["data"]["query"]["categorymembers"] as $row) {
                if (
                    !is_int($row["pageid"] ?? null) ||
                    !str_starts_with($row["title"] ?? "", "Cookbook:")
                ) {
                    continue;
                }
                $id = "wkb-" . $row["pageid"];
                $state["items"][$id] = [
                    "idMeal" => $id,
                    "strMeal" => substr($row["title"], 9),
                    "strCategory" => "Wikibooks",
                    "strArea" => "",
                    "strSource" =>
                        "https://en.wikibooks.org/wiki/" .
                        rawurlencode(str_replace(" ", "_", $row["title"])),
                    "strInstructions" => "",
                    "_source" => "Wikibooks",
                    "_metadata_unknown" => true,
                ];
            }
            $state["next"] = $result["data"]["continue"]["cmcontinue"] ?? null;
            $state["complete"] = !$state["next"];
            $this->cache->write(self::KEY, $state);
            if ($state["complete"]) {
                break;
            }
        }
        return $state;
    }

    public function details(string $id): ?array
    {
        if (!preg_match('/^wkb-([0-9]+)$/', $id, $match)) {
            return null;
        }
        $record = $this->cache->json(
            self::API,
            [
                "action" => "parse",
                "pageid" => $match[1],
                "prop" => "text",
                "format" => "json",
                "formatversion" => 2,
            ],
            86400,
        );
        $page = $record["data"]["parse"] ?? null;
        if (
            !is_array($page) ||
            !str_starts_with($page["title"] ?? "", "Cookbook:") ||
            !is_string($page["text"] ?? null)
        ) {
            return null;
        }
        $dom = new \DOMDocument();
        $old = libxml_use_internal_errors(true);
        $dom->loadHTML(
            '<?xml encoding="UTF-8">' . $page["text"],
            LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING,
        );
        libxml_clear_errors();
        libxml_use_internal_errors($old);
        $xpath = new \DOMXPath($dom);
        $lines = [];
        foreach ($xpath->query("//h2|//h3|//p|//li") as $node) {
            if (
                $xpath->query(
                    'ancestor::*[contains(@class,"navbox") or contains(@class,"toc") or contains(@class,"infobox")]',
                    $node,
                )->length
            ) {
                continue;
            }
            $text = trim(preg_replace("/\s+/u", " ", $node->textContent));
            if (
                str_starts_with($text, "Cookbook |") ||
                str_contains($text, "[edit | edit source]")
            ) {
                continue;
            }
            if ($text !== "") {
                $lines[] = $text;
            }
        }
        return [
            "idMeal" => $id,
            "strMeal" => substr($page["title"], 9),
            "strCategory" => "Wikibooks",
            "strSource" =>
                "https://en.wikibooks.org/wiki/" .
                rawurlencode(str_replace(" ", "_", $page["title"])),
            "strInstructions" => mb_substr(implode("\n", array_unique($lines)), 0, 20000),
            "_source" => "Wikibooks",
            "_metadata_unknown" => true,
        ];
    }
}
