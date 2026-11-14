<?php
declare(strict_types=1);
require_once __DIR__.'/recipe_contract.php';

$checks = 0;
function check(bool $value, string $message): void
{
    global $checks;
    if (!$value) throw new RuntimeException($message);
    ++$checks;
}
function refuses(callable $callback, string $message): void
{
    try { $callback(); }
    catch (RuntimeException | InvalidArgumentException $error) { check(true, $message); return; }
    throw new RuntimeException('Expected refusal: '.$message);
}

$ingredients = [
    ['id'=>12,'name'=>'فسيخ كيلو ٣ سمكات','unit'=>'kg'],
    (object) ['id'=>'16','name'=>'رنجه سمينه','unit'=>'kg'],
    ['id'=>18,'name'=>'سردين بلدي','unit'=>'kg'],
    ['id'=>19,'name'=>'فسيخ كيلو 4 سمكات','unit'=>'kg'],
    ['id'=>20,'name'=>'رنجة بطارخ','unit'=>'kg'],
];
$fish = NetFilletRecipeContract::resolveIngredient($ingredients, 'feseekh');
check($fish['id'] === 12, 'Arabic digits resolve only the approved three-fish grade.');
check(NetFilletRecipeContract::resolveIngredient($ingredients, 'herring')['id'] === 16,
    'Approved herring name accepts presentation spelling.');
check(NetFilletRecipeContract::resolveIngredient($ingredients, 'sardine')['id'] === 18,
    'Sardine resolves its exact raw ingredient.');
check(NetFilletRecipeContract::normalize(' فَسيـخ   كيلو ۳ سمكات ') === 'فسيخ كيلو 3 سمكات',
    'Diacritics, tatweel, whitespace and Persian digits normalize.');
refuses(fn()=>NetFilletRecipeContract::resolveIngredient([
    $ingredients[0], ['id'=>13,'name'=>'فسيخ كيلو 3 سمكات','unit'=>'kg'],
], 'feseekh'), 'Duplicate normalized ingredients are ambiguous.');
refuses(fn()=>NetFilletRecipeContract::resolveIngredient([$ingredients[3]], 'feseekh'),
    'Four-fish grade cannot substitute for the three-fish grade.');
refuses(fn()=>NetFilletRecipeContract::resolveIngredient([$ingredients[4]], 'herring'),
    'Roe herring cannot substitute for fat herring.');
refuses(fn()=>NetFilletRecipeContract::resolveIngredient([
    ['id'=>18,'name'=>'سردين','unit'=>'kg'],
], 'sardine'), 'No unapproved shorter-name fallback.');
refuses(fn()=>NetFilletRecipeContract::resolveIngredient([
    ['id'=>18,'name'=>'سردين بلدي','unit'=>'piece'],
], 'sardine'), 'A piece ingredient cannot bind a kilogram recipe.');
refuses(fn()=>NetFilletRecipeContract::resolveIngredient($ingredients, 'unknown'),
    'Unknown fish family is refused.');

$json = NetFilletRecipeContract::variantsJson($fish);
check(str_starts_with($json, '{"0":['), 'Base variants encode as a JSON object.');
$component = json_decode($json, true, 512, JSON_THROW_ON_ERROR)[0][0];
check($component === ['ingredient_id'=>12,'name'=>'فسيخ كيلو ٣ سمكات','unit'=>'kg',
    'quantity_units'=>2000000,'measure'=>'kg','quantity'=>'2'], 'Exact component values.');

$reader = new class($fish) {
    public array $calls = [];
    public array $ingredient;
    public array $override = [];
    public function __construct(array $ingredient) { $this->ingredient = $ingredient; }
    public function snapshots(string $branch, array $lines): array
    {
        $this->calls[] = [$branch, $lines];
        foreach ($lines as &$line) {
            $line['inventory'] = array_replace([
                'version'=>1,'branch'=>$branch,'product_id'=>$line['product_id'],
                'feature_id'=>$line['feature_id'],'unit'=>'kg','recipe_id'=>55,'revision'=>1,
                'components'=>NetFilletRecipeContract::variants($this->ingredient)[0],
            ], $this->override);
        }
        unset($line);
        return $lines;
    }
};
$verified = NetFilletRecipeContract::verifySnapshots($reader, 'f:301', 987, $fish, 456);
check(count($verified) === 6 && count($reader->calls) === 6, 'Base and kilo read at all three weights.');
check(array_column($verified, 'raw_quantity_units') === [2000000,1000000,500000,2000000,1000000,500000],
    'One, half and quarter kilogram sales have the expected raw deductions.');
check(array_column($verified, 'feature_id') === [0,0,0,456,456,456], 'Kilo alias is verified separately.');
$reader->override = ['recipe_id'=>0];
refuses(fn()=>NetFilletRecipeContract::verifySnapshots($reader, 'f:301', 987, $fish),
    'Automatic raw-stock binding cannot substitute for a saved recipe.');
$reader->override = ['unit'=>'piece'];
refuses(fn()=>NetFilletRecipeContract::verifySnapshots($reader, 'f:301', 987, $fish),
    'Recipe must remain weighted.');
$reader->override = ['stock_source'=>'direct'];
refuses(fn()=>NetFilletRecipeContract::verifySnapshots($reader, 'f:301', 987, $fish),
    'Direct SKU stock cannot substitute for a raw recipe.');
$wrong = NetFilletRecipeContract::variants($fish)[0];
$wrong[0]['quantity_units'] = 1000000;
$reader->override = ['components'=>$wrong];
refuses(fn()=>NetFilletRecipeContract::verifySnapshots($reader, 'f:301', 987, $fish),
    'One-to-one ratio cannot substitute for two-to-one.');
$wrong = NetFilletRecipeContract::variants($fish)[0];
$wrong[0]['ingredient_id'] = 19;
$reader->override = ['components'=>$wrong];
refuses(fn()=>NetFilletRecipeContract::verifySnapshots($reader, 'f:301', 987, $fish),
    'Recipe cannot consume another ingredient.');
$reader->override = ['components'=>array_merge($wrong, $wrong)];
refuses(fn()=>NetFilletRecipeContract::verifySnapshots($reader, 'f:301', 987, $fish),
    'Extra components are refused.');
refuses(fn()=>NetFilletRecipeContract::verifySnapshots(new stdClass(), 'f:301', 987, $fish),
    'Runtime must provide the public snapshot reader.');

echo 'NET_FILLET_RECIPE_CONTRACT_OK checks='.$checks.PHP_EOL;
