<?php

namespace App\Service;
class Nutrition
{
    private array $catalog;
    public function __construct(string $projectDir)
    {
        $this->catalog = json_decode(
            file_get_contents($projectDir . '/data/catalog.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
    }

    public function catalog(string $key): array
    {
        return $this->catalog[$key] ?? [];
    }

    private function n(array $d, string $k, float $min, float $max, ?float $default = null): float
    {
        $v = $d[$k] ?? $default;
        if (!is_numeric($v) || !is_finite((float) $v) || $v < $min || $v > $max) {
            throw new \InvalidArgumentException("$k : valeur attendue entre $min et $max.");
        }
        return (float) $v;
    }

    public function macros(int $cal, float $weight, string $goal, ?float $ratio = null): array
    {
        if (!in_array($goal, ['cut', 'maintain', 'bulk'])) {
            throw new \InvalidArgumentException('Objectif inconnu.');
        }
        $this->n(['c' => $cal], 'c', 1200, 6000);
        $this->n(['w' => $weight], 'w', 35, 250);
        $ratio ??= ['cut' => 1.8, 'maintain' => 1.6, 'bulk' => 1.6][$goal];
        $this->n(['r' => $ratio], 'r', 1.4, 2.2);
        $p = round($weight * $ratio);
        $f = round(
            max(
                $weight * ['cut' => 0.7, 'maintain' => 0.8, 'bulk' => 0.9][$goal],
                (0.2 * $cal) / 9,
            ),
        );
        $c = round(($cal - 4 * $p - 9 * $f) / 4);
        if ($c < 0) {
            throw new \InvalidArgumentException('Calories insuffisantes pour les macros choisies.');
        }
        return [
            'calories' => $cal,
            'protein' => $p,
            'protein_g_per_kg' => $ratio,
            'fat' => $f,
            'carbs' => $c,
            'energy_from_macros' => 4 * $p + 9 * $f + 4 * $c,
        ];
    }

    private function trajectory(array $d, float $w, int $tdee, bool $calibrate = false): array
    {
        $target = $d['target_weight_kg'] ?? null;
        $date = $d['target_date'] ?? null;
        $deficit = $tdee * 0.15;
        $trajectory = null;
        if (($target === null) !== ($date === null)) {
            throw new \InvalidArgumentException('Renseigne ensemble le poids et la date cibles.');
        }
        if ($target !== null) {
            $this->n(['w' => $target], 'w', 35, 250);
            $dt = \DateTimeImmutable::createFromFormat('!Y-m-d', (string) $date);
            if (!$dt || $dt->format('Y-m-d') !== $date || $dt <= new \DateTimeImmutable('today')) {
                throw new \InvalidArgumentException('La date cible doit être future.');
            }
            if ($target >= $w) {
                throw new \InvalidArgumentException(
                    'Le poids cible en sèche doit être inférieur au poids actuel.',
                );
            }
            $days = (int) (new \DateTimeImmutable('today'))->diff($dt)->days;
            $delta = $w - $target;
            $required = ($delta * 7700) / $days;
            $max = min($calibrate ? 750 : 850, $tdee * ($calibrate ? 0.2 : 0.25));
            $deficit = round(min($required, $max) / 10) * 10;
            $feasible = $required <= $max && ($delta * 7) / $days <= $w * 0.01;
            $trajectory = [
                'starting_weight_kg' => $w,
                'target_weight_kg' => $target,
                'target_date' => $date,
                'days_remaining' => $days,
                'weeks_remaining' => round($days / 7, 1),
                'weight_to_lose_kg' => round($delta, 2),
                'average_kg_per_week' => round(($delta * 7) / $days, 2),
                'theoretical_daily_deficit' => round($required),
                'applied_daily_deficit' => $deficit,
                'calorie_ceiling_applied' => !$feasible,
                'note' => $feasible
                    ? 'Repère indicatif, à ajuster selon les observations.'
                    : 'Objectif ambitieux : déficit plafonné, la date ne peut pas être garantie.',
                'kg_to_lose' => round($delta, 2),
                'theoretical_deficit' => round($required),
                'suggested_deficit' => $deficit,
            ];
        }
        return [$deficit, $trajectory];
    }

    private function plans(int $tdee, float $w, float $deficit, float $ratio): array
    {
        $plans = [];
        foreach (
            [
                'cut' => max(1200, (int) (round(($tdee - $deficit) / 10) * 10)),
                'maintain' => $tdee,
                'bulk' => (int) (round(($tdee * 1.08) / 10) * 10),
            ]
            as $g => $c
        ) {
            $plans[$g] =
                [
                    'goal' => $g,
                    'label' => [
                        'cut' => 'Sèche',
                        'maintain' => 'Maintien',
                        'bulk' => 'Prise de masse',
                    ][$g],
                    'difference_vs_maintenance' => $c - $tdee,
                ] + $this->macros($c, $w, $g, $g === 'cut' ? $ratio : null);
        }
        return $plans;
    }

    public function calculate(array $d): array
    {
        if (!in_array($d['sex'] ?? '', ['male', 'female'])) {
            throw new \InvalidArgumentException('Sexe invalide.');
        }
        $age = $this->n($d, 'age', 18, 100);
        $w = $this->n($d, 'weight_kg', 35, 250);
        $h = $this->n($d, 'height_cm', 120, 230);
        $act = $this->n($d, 'activity_factor', 1.2, 1.9);
        $ratio = $this->n($d, 'cut_protein_g_per_kg', 1.4, 2.2, 1.8);
        $bmr = 10 * $w + 6.25 * $h - 5 * $age + ($d['sex'] === 'male' ? 5 : -161);
        $tdee = (int) (round(($bmr * $act) / 10) * 10);
        if ($tdee < 1350 || $tdee > 5500) {
            throw new \InvalidArgumentException(
                'Maintien hors plage du calculateur. Vérifie le profil.',
            );
        }
        [$deficit, $trajectory] = $this->trajectory($d, $w, $tdee);
        return [
            'profile' => $d,
            'bmr' => round($bmr),
            'maintenance' => $tdee,
            'plans' => $this->plans($tdee, $w, $deficit, $ratio),
            'trajectory' => $trajectory,
            'method' => 'Mifflin-St Jeor + activité, estimation indicative',
            'notes' => [
                'Le maintien est une estimation, pas une mesure.',
                'Ajuster après 2 à 3 semaines de moyennes de pesées.',
                'La date cible reste une intention : métabolisme et eau corporelle évoluent.',
            ],
            'sources' => [
                'https://pubmed.ncbi.nlm.nih.gov/2305711/',
                'https://pmc.ncbi.nlm.nih.gov/articles/PMC5477153/',
                'https://www.niddk.nih.gov/bwp',
            ],
        ];
    }

    public function calibrate(array $d): array
    {
        $c = $this->n($d, 'average_daily_calories', 1200, 6000);
        $start = $this->n($d, 'start_weight_kg', 35, 250);
        $end = $this->n($d, 'end_weight_kg', 35, 250);
        $days = $this->n($d, 'duration_days', 10, 90);
        $ratio = $this->n($d, 'cut_protein_g_per_kg', 1.4, 2.2, 1.8);
        $tdee = (int) (round(($c + (($start - $end) * 7700) / $days) / 10) * 10);
        if ($tdee < 1400 || $tdee > 5500) {
            throw new \InvalidArgumentException(
                'Valeur incohérente. Utilise des moyennes réelles sur 10–90 jours.',
            );
        }
        [$deficit, $trajectory] = $this->trajectory($d, $end, $tdee, true);
        return [
            'maintenance' => $tdee,
            'weekly_weight_change_kg' => round((($start - $end) * 7) / $days, 2),
            'plans' => $this->plans($tdee, $end, $deficit, $ratio),
            'trajectory' => $trajectory,
            'notes' => [
                'Les variations d’eau et de glycogène peuvent fausser cette estimation.',
                'Comparer des moyennes de poids et ajuster progressivement.',
            ],
            'method' => 'Bilan énergétique observé, avec incertitude importante',
            'source' => 'weight_trend',
            'duration_days' => $days,
        ];
    }

    private function sum(array $items): array
    {
        $total = ['kcal' => 0., 'protein' => 0., 'fat' => 0., 'carbs' => 0.];
        foreach ($items as $i) {
            $f = $this->catalog['FOOD_DB'][$i['food']];
            $mult = $f['unit'] === 'piece' ? $i['amount'] : $i['amount'] / 100;
            foreach (['protein', 'carbs', 'fat'] as $m) {
                $total[$m] += $f[$m] * $mult;
            }
        }
        $total['kcal'] = 4 * $total['protein'] + 4 * $total['carbs'] + 9 * $total['fat'];
        return $total;
    }

    private function totals(array $state): array
    {
        $total = ['kcal' => 0., 'protein' => 0., 'fat' => 0., 'carbs' => 0.];
        foreach ($state as $m) {
            $n = $this->sum($m['ingredients']);
            foreach ($total as $k => $v) {
                $total[$k] += $n[$k];
            }
        }
        return $total;
    }

    private function score(array $state, array $targets): float
    {
        $t = $this->totals($state);
        $score =
            (($t['kcal'] - $targets['calories']) / 20) ** 2 +
            (($t['protein'] - $targets['protein']) / 4) ** 2 +
            (($t['fat'] - $targets['fat']) / 2.5) ** 2 +
            (($t['carbs'] - $targets['carbs']) / 6) ** 2;
        foreach ($state as $meal) {
            $n = $this->sum($meal['ingredients']);
            $delta =
                $n['kcal'] -
                $targets['calories'] * $this->catalog['SLOT_RATIOS'][$meal['template']['slot']];
            $score += 1.3 * ($delta / 50) ** 2;
            if (abs($delta) > 75) {
                $score += 18 + 5 * ((abs($delta) - 75) / 25) ** 2;
            }
            foreach ($meal['ingredients'] as $i) {
                $span = $i['max'] - $i['min'];
                if ($span > 0) {
                    $score += 0.1 * (($i['amount'] - $i['base']) / $span) ** 2;
                }
            }
        }
        return $score;
    }

    private function normalize(string $s): string
    {
        return strtolower(iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s) ?: $s);
    }

    private function matches(array $template, array $pantry): array
    {
        $aliases = [];
        foreach ($template['ingredients'] as $i) {
            $food = $this->catalog['FOOD_DB'][$i['food']];
            $aliases = array_merge($aliases, $food['aliases'], [$food['label']]);
        }
        return array_values(
            array_filter($pantry, function ($p) use ($aliases) {
                $p = $this->normalize($p);
                foreach ($aliases as $a) {
                    $a = $this->normalize($a);
                    if ($p !== '' && (str_contains($a, $p) || str_contains($p, $a))) {
                        return true;
                    }
                }
                return false;
            }),
        );
    }

    public function menu(array $d): array
    {
        $cal = (int) $this->n($d, 'calories', 1200, 6000);
        $w = $this->n($d, 'weight', 40, 250, 80);
        $goal = $d['goal'] ?? 'maintain';
        $ratio =
            isset($d['protein_g_per_kg']) && $d['protein_g_per_kg'] !== ''
                ? $this->n($d, 'protein_g_per_kg', 1.4, 2.2)
                : null;
        $targets = $this->macros($cal, $w, $goal, $ratio);
        $variant = (int) $this->n($d, 'variant', 0, 10000, 0);
        $pantry = array_values(
            array_filter(
                array_map('trim', explode(',', mb_substr($d['ingredients'] ?? '', 0, 2000))),
            ),
        );
        $state = [];
        $used = [];
        $dairy = false;
        $eggs = false;
        $slotIndex = 0;
        foreach ($this->catalog['SLOT_RATIOS'] as $slot => $portion) {
            $choices = [];
            foreach ($this->catalog['MEAL_TEMPLATES'] as $t) {
                if ($t['slot'] !== $slot) {
                    continue;
                }
                $items = array_map(fn($i) => $i + ['amount' => $i['base']], $t['ingredients']);
                $n = $this->sum($items);
                $foods = array_column($items, 'food');
                $proteins = array_intersect($foods, [
                    'tuna',
                    'chicken',
                    'turkey',
                    'beef5',
                    'salmon',
                    'cod',
                ]);
                $hasDairy = (bool) array_intersect($foods, [
                    'skyr',
                    'fromage_blanc_0',
                    'greek_yogurt',
                ]);
                $hasEgg = (bool) array_intersect($foods, ['egg', 'egg_white']);
                $repeated =
                    (bool) array_intersect($proteins, $used) ||
                    ($dairy && $hasDairy) ||
                    ($eggs && $hasEgg);
                $s =
                    (in_array($goal, $t['goals']) ? 45 : 0) +
                    count($this->matches($t, $pantry)) * 18 -
                    (abs($n['kcal'] - $cal * $portion) / ($cal * $portion)) * 45 -
                    (abs($n['protein'] - $targets['protein'] * $portion) /
                        max(1, $targets['protein'] * $portion)) *
                        30 -
                    ($repeated ? 1000 : 0);
                $choices[] = [
                    'score' => $s,
                    'repeated' => $repeated,
                    'template' => $t,
                    'ingredients' => $items,
                    'proteins' => $proteins,
                    'dairy' => $hasDairy,
                    'eggs' => $hasEgg,
                ];
            }
            $unique = array_values(array_filter($choices, fn($c) => !$c['repeated']));
            if ($unique) {
                $choices = $unique;
            }
            usort($choices, fn($a, $b) => $b['score'] <=> $a['score']);
            $top = array_slice($choices, 0, 5);
            $chosen = $top[($variant + $slotIndex++) % count($top)];
            $state[] = $chosen;
            $used = array_merge($used, $chosen['proteins']);
            $dairy = $dairy || $chosen['dairy'];
            $eggs = $eggs || $chosen['eggs'];
        }
        for ($iter = 0; $iter < 260; $iter++) {
            $best = $this->score($state, $targets);
            $move = null;
            foreach ($state as $mi => $meal) {
                foreach ($meal['ingredients'] as $ii => $i) {
                    if ($i['step'] <= 0) {
                        continue;
                    }
                    foreach ([-$i['step'], $i['step']] as $delta) {
                        $a = $i['amount'] + $delta;
                        if ($a < $i['min'] || $a > $i['max']) {
                            continue;
                        }
                        $state[$mi]['ingredients'][$ii]['amount'] = $a;
                        $score = $this->score($state, $targets);
                        $state[$mi]['ingredients'][$ii]['amount'] = $i['amount'];
                        if ($score < $best - 1e-7) {
                            $best = $score;
                            $move = [$mi, $ii, $delta];
                        }
                    }
                };
            }
            if (!$move) {
                break;
            }
            [$mi, $ii, $delta] = $move;
            $state[$mi]['ingredients'][$ii]['amount'] += $delta;
        }
        // Coupled moves preserve total calories while refining fats and carbohydrates.
        $mutable = [];
        foreach ($state as $mi => $meal) {
            foreach ($meal['ingredients'] as $ii => $i) {
                if ($i['step'] > 0) {
                    $mutable[] = [$mi, $ii, $i['step']];
                }
            };
        }
        for ($iter = 0; $iter < 8; $iter++) {
            $best = $this->score($state, $targets);
            $pair = null;
            foreach ($mutable as $pos => [$ma, $ia, $sa]) {
                foreach ([-$sa, $sa] as $da) {
                    $a = $state[$ma]['ingredients'][$ia];
                    if ($a['amount'] + $da < $a['min'] || $a['amount'] + $da > $a['max']) {
                        continue;
                    }
                    $state[$ma]['ingredients'][$ia]['amount'] += $da;
                    foreach (array_slice($mutable, $pos + 1) as [$mb, $ib, $sb]) {
                        foreach ([-$sb, $sb] as $db) {
                            $b = $state[$mb]['ingredients'][$ib];
                            if ($b['amount'] + $db < $b['min'] || $b['amount'] + $db > $b['max']) {
                                continue;
                            }
                            $state[$mb]['ingredients'][$ib]['amount'] += $db;
                            $score = $this->score($state, $targets);
                            $state[$mb]['ingredients'][$ib]['amount'] -= $db;
                            if ($score < $best - 1e-7) {
                                $best = $score;
                                $pair = [$ma, $ia, $da, $mb, $ib, $db];
                            }
                        };
                    }
                    $state[$ma]['ingredients'][$ia]['amount'] -= $da;
                };
            }
            if (!$pair) {
                break;
            }
            [$ma, $ia, $da, $mb, $ib, $db] = $pair;
            $state[$ma]['ingredients'][$ia]['amount'] += $da;
            $state[$mb]['ingredients'][$ib]['amount'] += $db;
        }
        $meals = [];
        $allMatches = [];
        foreach ($state as $m) {
            $t = $m['template'];
            $n = $this->sum($m['ingredients']);
            $matches = $this->matches($t, $pantry);
            $allMatches = array_merge($allMatches, $matches);
            $ingredients = [];
            $keywords = [];
            foreach ($m['ingredients'] as $i) {
                $f = $this->catalog['FOOD_DB'][$i['food']];
                if ($i['amount'] > 0) {
                    $ingredients[] =
                        round($i['amount']) .
                        ' ' .
                        ($f['unit'] === 'piece' ? '' : $f['unit'] . ' de ') .
                        $f['label'];
                }
                if (
                    in_array($i['food'], [
                        'chicken',
                        'turkey',
                        'tuna',
                        'cod',
                        'salmon',
                        'beef5',
                        'lentils_cooked',
                        'egg',
                        'pasta_dry',
                        'rice_cooked',
                    ])
                ) {
                    $keywords[] = $f['aliases'][0];
                }
            }
            $target = (int) round($targets['calories'] * $this->catalog['SLOT_RATIOS'][$t['slot']]);
            $meals[] = [
                'id' => $t['id'],
                'slot' => $t['slot'],
                'slot_label' => $this->catalog['SLOT_LABELS'][$t['slot']],
                'name' => $t['name'],
                'emoji' => $t['emoji'],
                'kcal' => round($n['kcal']),
                'protein' => round($n['protein']),
                'carbs' => round($n['carbs']),
                'fat' => round($n['fat']),
                'ingredients' => $ingredients,
                'pantry_matches' => $matches,
                'pantry_match_count' => count($matches),
                'web_keywords' => array_slice($keywords, 0, 3),
                'target_kcal' => $target,
                'difference_kcal' => round($n['kcal']) - $target,
                'distribution_ok' => abs(round($n['kcal']) - $target) <= 75,
            ];
        }
        $total = $this->totals($state);
        $actual = [
            'calories' => round($total['kcal']),
            'protein' => round($total['protein']),
            'carbs' => round($total['carbs']),
            'fat' => round($total['fat']),
        ];
        $diff = [];
        foreach ($actual as $k => $v) {
            $diff[$k] = $v - $targets[$k];
        }
        return [
            'success' => true,
            'goal' => $goal,
            'weight' => $w,
            'targets' => $targets,
            'actual' => $actual,
            'differences' => $diff,
            'target_kcal' => $cal,
            'estimated_total_kcal' => $actual['calories'],
            'difference_kcal' => $diff['calories'],
            'protein' => $actual['protein'],
            'carbs' => $actual['carbs'],
            'fat' => $actual['fat'],
            'pantry_used' => array_values(array_unique($allMatches)),
            'meals' => $meals,
            'note' =>
                'Quantités finales. Calories calculées depuis les macros des ingrédients (4/4/9). Estimations indicatives.',
        ];
    }
}
