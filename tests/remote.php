<?php
/** Deterministic remote-data tests. Run: php tests/remote.php (no Internet or database required). */
require dirname(__DIR__) . '/vendor/autoload.php';
use App\Service\{RemoteCache, Translator, Nutrition, GymStore, ExerciseCatalog, RecipeSearch};
use Symfony\Component\HttpClient\{MockHttpClient};
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\RequestStack;
$root = dirname(__DIR__);
$nutrition = new Nutrition($root);
$count = 0;
function check(bool $condition, string $message): void
{
    global $count;
    if (!$condition) {
        throw new RuntimeException($message);
    }
    $count++;
}
function services(callable $handler): array
{
    global $nutrition;
    $dir = sys_get_temp_dir() . '/gym-remote-test-' . bin2hex(random_bytes(5));
    mkdir($dir);
    $cache = new RemoteCache(new MockHttpClient($handler), $dir);
    $translator = new Translator($cache, $nutrition);
    $store = new GymStore($dir, 'sqlite:///' . $dir . '/unused.db', new RequestStack());
    return [
        $cache,
        $translator,
        new ExerciseCatalog($store, $nutrition, $cache, $translator),
        new RecipeSearch($nutrition, $cache, $translator),
        $dir,
    ];
}
function response(array $p): MockResponse
{
    return new MockResponse(json_encode($p), ['http_code' => 200]);
}
$rich = [
    'id' => 'bench',
    'name' => 'Barbell Bench Press',
    'target' => 'pectorals',
    'muscleGroup' => 'triceps',
    'secondaryMuscles' => ['triceps', 'deltoids'],
    'difficulty' => 'advanced',
    'shortDescription' => 'Keep the back stable.',
    'steps' => ['Lower the bar.', 'Press up.'],
    'instructions' => 'Instructions in one string.',
    'formCues' => ['Feet on floor.'],
    'commonMistakes' => ['Bouncing.'],
    'breathing' => 'Exhale as you press.',
    'videos' => [
        'male' => 'https://example.org/bench.mp4',
        'female' => 'https://example.org/bench-female.mp4',
    ],
    'thumbnails' => ['male' => 'https://example.org/bench.jpg'],
];
$calls = 0;
[$cache, $tr, $ex, $recipes] = $s = services(function ($method, $url) use (&$calls, $rich) {
    $calls++;
    if (str_contains($url, 'translated.net')) {
        return response([
            'responseStatus' => 200,
            'responseData' => ['translatedText' => 'Texte traduit'],
        ]);
    }
    return response([
        $rich,
        [
            'id' => 'wrist',
            'name' => 'Reverse Wrist Curl',
            'target' => 'biceps',
            'secondaryMuscles' => [],
        ],
        ['id' => 'unknown', 'name' => 'Unmapped exercise', 'target' => 'unmapped'],
    ]);
});
$all = $ex->all();
check(count($all) === 3, 'Unmapped exercises must remain visible');
$bench = array_values(array_filter($all, fn($e) => $e['id'] === 'bench'))[0];
check(
    $bench['steps'] === ['Lower the bar.', 'Press up.'],
    'Detailed steps preserved, instructions string not substituted',
);
check($bench['difficulty'] === 'advanced', 'Difficulty preserved');
check(
    $bench['videos']['female'] === 'https://example.org/bench-female.mp4',
    'Both video versions preserved',
);
check($bench['formCues'] === ['Feet on floor.'], 'Cues preserved');
check(
    $bench['primaryGroup'] === 'chest',
    'Explicit pectoral target takes priority over inconsistent muscleGroup',
);
check(str_contains($bench['name_fr'], 'développé couché'), 'French search name present');
$wrist = array_values(array_filter($all, fn($e) => $e['id'] === 'wrist'))[0];
check($wrist['primaryGroup'] === 'forearms', 'Incorrect wrist classification corrected');
$ex->all();
check($calls === 1, 'Fresh exercise data cached');
$detail = $ex->details('bench');
check(
    $detail['description'] === 'Texte traduit' &&
        $detail['description_original'] === 'Keep the back stable.',
    'Translation retains original',
);
check($detail['steps_original'] === $rich['steps'], 'Original steps retained');
[$cache2, $tr2, $ex2] = services(function ($method, $url) {
    if (str_contains($url, 'luisaraujoc')) {
        return new MockResponse('{}', ['http_code' => 410]);
    }
    return response([
        [
            'id' => 'fallback',
            'name' => 'Fallback Squat',
            'primaryMuscles' => ['quadriceps'],
            'instructions' => ['Bend knees.'],
            'images' => ['Squat/0.jpg'],
            'level' => 'beginner',
        ],
    ]);
});
$fallback = $ex2->all();
check(
    count($fallback) === 1 && $ex2->status()['source'] === 'Free Exercise DB',
    'Secondary source used',
);
check(
    str_contains($fallback[0]['thumbnails']['male'], 'Squat/0.jpg'),
    'Secondary source image resolved',
);
check($fallback[0]['steps'] === ['Bend knees.'], 'Secondary schema instructions preserved');
[$staleCache, $staleTr, $staleEx, , $dir] = services(
    fn() => new MockResponse('{}', ['http_code' => 503]),
);
$url = array_values(ExerciseCatalog::SOURCES)[0];
$staleCache->write($url . '?', [$rich]);
$path = $dir . '/var/remote/' . hash('sha256', $url . '?') . '.json';
$p = json_decode(file_get_contents($path), true);
$p['saved_at'] = time() - 10000;
file_put_contents($path, json_encode($p));
check(
    count($staleEx->all()) === 1 && $staleEx->status()['stale'],
    'Stale data retained during outage',
);
[$emptyCache, $emptyTr, $emptyEx] = services(
    fn() => new MockResponse('not-json', ['http_code' => 200]),
);
check(
    count($emptyEx->all()) === 15 && $emptyEx->status()['status'] === 'degraded',
    'Explicit local fallback during total outage',
);
check(!$recipes->phraseMatch('rice', 'licorice'), 'Ingredient matching uses words');
check($recipes->phraseMatch('rice', 'brown rice'), 'Ingredient variants match');
check(
    in_array('pasta', $recipes->terms('PÂTES')),
    'Accents and generic ingredient aliases normalized',
);
$meal = [
    'idMeal' => '42',
    'strMeal' => 'Chicken Rice',
    'strCategory' => 'Chicken',
    'strArea' => 'French',
    'strMealThumb' => 'https://example.org/meal.jpg',
    'strIngredient1' => 'Chicken',
    'strMeasure1' => '2 tablespoons',
    'strIngredient2' => 'Rice',
    'strMeasure2' => '100 g',
    'strIngredient3' => 'Broccoli',
    'strMeasure3' => '200 g',
    'strInstructions' => 'Cook chicken. Serve with rice.',
    'strSource' => 'https://example.org/recipe',
];
$filterCalls = [];
[$rc, $rt, $rx, $rp] = services(function ($method, $url, $options) use ($meal, &$filterCalls) {
    parse_str(parse_url($url, PHP_URL_QUERY) ?? '', $query);
    if (str_contains($url, 'search.php')) {
        $filterCalls[] = $query['f'] ?? '';
        return response(['meals' => [$meal]]);
    }
    if (str_contains($url, 'lookup.php')) {
        return response(['meals' => [$meal]]);
    }
    if (str_contains($url, 'translated.net')) {
        return response([
            'responseStatus' => 200,
            'responseData' => ['translatedText' => 'Traduction'],
        ]);
    }
    return response([]);
});
$result = $rp->search(['ingredients' => 'poulet,riz,brocoli,oeufs', 'goal' => 'cut']);
check(count(array_unique($filterCalls)) === 26, 'All letters of Internet catalogue retrieved');
check($result['count'] === 1, 'Duplicate recipe candidates deduplicated');
check(
    $result['data'][0]['owned_recipe_ingredients'] === 3,
    'Owned ingredients matched in French and English',
);
check(
    str_contains(html_entity_decode($result['data'][0]['ingredients'][0]['measure']), 'c. à soupe'),
    'Measures translated locally',
);
check(
    $result['data'][0]['nutrition_verified'] === false,
    'External recipes do not invent nutrition',
);
$detail = $rp->details('42', 'riz');
check(
    $detail !== null &&
        html_entity_decode($detail['instructions_original']) === $meal['strInstructions'],
    'Full recipe preparation and original returned',
);
$catalog = [];
for ($i = 1; $i <= 83; $i++) {
    $catalog[] = $meal + [];
}
for ($i = 0; $i < 83; $i++) {
    $catalog[$i]['idMeal'] = (string) (1000 + $i);
    $catalog[$i]['strMeal'] = 'Recipe ' . str_pad((string) $i, 3, '0', STR_PAD_LEFT);
}
[$manyCache, $manyTr, $manyEx, $manyRecipes] = services(function ($method, $url) use ($catalog) {
    if (str_contains($url, 'search.php')) {
        return response(['meals' => $catalog]);
    }
    if (str_contains($url, 'translated.net')) {
        return response([
            'responseStatus' => 200,
            'responseData' => ['translatedText' => 'Traduction'],
        ]);
    }
    return response(['meals' => null]);
});
$ids = [];
for ($page = 1; $page <= 4; $page++) {
    $batch = $manyRecipes->search(['page' => $page, 'limit' => 24]);
    check(
        $batch['total'] === 83 && $batch['catalog_size'] === 83,
        'Count is actual unique catalogue size',
    );
    $ids = array_merge($ids, array_column($batch['data'], 'id'));
    check($batch['has_more'] === $page < 4, 'Pagination end detected correctly');
}
check(
    count($ids) === 83 && count(array_unique($ids)) === 83,
    'All recipes reachable without repeats or 20-item truncation',
);
$filtered = $manyRecipes->search(['search' => 'poulet riz', 'limit' => 48]);
check($filtered['total'] === 83, 'French multiword ingredient search applies to Internet recipes');
$empty = $manyRecipes->search(['ingredients' => 'introuvablexyz']);
check($empty['total'] === 0, 'Unmatched pantry ingredient filters the full catalogue');
$browse = $manyRecipes->search(['ingredients' => 'introuvablexyz', 'filter_pantry' => '0']);
check($browse['total'] === 83, 'Browse all without clearing pantry');
$lean = $rp->profile(
    ['strMeal' => 'Chicken salad', 'strCategory' => 'Chicken'],
    [['name' => 'chicken'], ['name' => 'lettuce'], ['name' => 'tomato']],
);
$richProfile = $rp->profile(
    ['strMeal' => 'Chocolate puff pastry', 'strCategory' => 'Dessert'],
    [['name' => 'chocolate'], ['name' => 'double cream'], ['name' => 'puff pastry']],
);
check(
    $lean['recommended_goal'] === 'cut' && $richProfile['recommended_goal'] === 'bulk',
    'Original goal ranking distinguishes lean and rich dishes',
);
[$quotaCache, $quotaTranslator] = services(
    fn() => response([
        'responseStatus' => 429,
        'quotaFinished' => true,
        'responseData' => ['translatedText' => 'QUOTA EXCEEDED'],
    ]),
);
check(
    $quotaTranslator->many(['x' => 'Keep your back straight'])['x'] === 'Keep your back straight',
    'Translation failure keeps original text',
);
echo "OK — $count remote-data checks (mocked sources, cache, translation, recipes).\n";
