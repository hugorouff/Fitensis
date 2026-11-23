<?php

namespace App\Service;

final class RecipePortions
{
    public static function adjust(array $recipe, int $portions, int $base): array
    {
        if ($portions < 1 || $portions > 12 || $base < 1 || $base > 12) {
            throw new AiException(
                "Les portions et la base doivent être comprises entre 1 et 12.",
                422,
            );
        }
        $source = $recipe["source_ingredients"] ?? $recipe["ingredients"];
        $recipe["source_ingredients"] = $source;
        $recipe["base_portions"] = $base;
        $recipe["portions"] = $portions;
        $recipe["ingredients"] = array_map(
            static fn($line) => self::scale($line, $portions / $base),
            $source,
        );
        return $recipe;
    }

    public static function scale(string $line, float $factor): string
    {
        if (abs($factor - 1) < 0.00001) {
            return $line;
        }
        $normalized = strtr($line, [
            "½" => " 1/2",
            "¼" => " 1/4",
            "¾" => " 3/4",
            "⅓" => " 1/3",
            "⅔" => " 2/3",
            "⅛" => " 1/8",
        ]);
        $number = "(?:\d+\s+\d+\s*\/\s*\d+|\d+\s*\/\s*\d+|\d+(?:[.,]\d+)?)";
        if (
            !preg_match(
                "/^\s*(" . $number . ")(?:\s*[-–]\s*(" . $number . '))?(.*)$/u',
                $normalized,
                $m,
            )
        ) {
            return $line;
        }
        $parse = static function ($value): float {
            if (preg_match('/^(?:(\d+)\s+)?(\d+)\s*\/\s*(\d+)$/', $value, $fraction)) {
                return (float) ($fraction[1] ?: 0) +
                    (float) $fraction[2] / max(1, (float) $fraction[3]);
            }
            return (float) str_replace(",", ".", $value);
        };
        $format = static fn($n) => rtrim(rtrim(number_format($n, 2, ",", ""), "0"), ",");
        $quantity = $format($parse($m[1]) * $factor);
        if (($m[2] ?? "") !== "") {
            $quantity .= "–" . $format($parse($m[2]) * $factor);
        }
        return $quantity . $m[3];
    }
}
