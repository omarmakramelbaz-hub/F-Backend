<?php
declare(strict_types=1);

/** Exact, read-only contract for the three approved 2:1 net-fillet recipes. */
final class NetFilletRecipeContract
{
    public const RAW_UNITS_PER_KG = 2000000;
    public const INGREDIENT_NAMES = [
        'feseekh' => 'فسيخ كيلو 3 سمكات',
        'herring' => 'رنجة سمينة',
        'sardine' => 'سردين بلدي',
    ];

    /** Presentation cleanup only: grades, weights and fish words remain distinct. */
    public static function normalize(string $name): string
    {
        $name = strtr($name, [
            'أ'=>'ا', 'إ'=>'ا', 'آ'=>'ا', 'ة'=>'ه', 'ى'=>'ي',
            '٠'=>'0', '١'=>'1', '٢'=>'2', '٣'=>'3', '٤'=>'4',
            '٥'=>'5', '٦'=>'6', '٧'=>'7', '٨'=>'8', '٩'=>'9',
            '۰'=>'0', '۱'=>'1', '۲'=>'2', '۳'=>'3', '۴'=>'4',
            '۵'=>'5', '۶'=>'6', '۷'=>'7', '۸'=>'8', '۹'=>'9',
        ]);
        $name = preg_replace('/[ـ\x{064B}-\x{065F}\x{0670}]/u', '', $name);
        if ($name === null) throw new RuntimeException('Invalid UTF-8 ingredient name.');
        $name = preg_replace('/\s+/u', ' ', $name);
        if ($name === null) throw new RuntimeException('Invalid UTF-8 ingredient name.');
        return trim($name);
    }

    /**
     * @param array<int,array|object> $rows Existing stock_ingredients rows, never menu rows.
     * @return array{id:int,name:string,unit:string}
     */
    public static function resolveIngredient(array $rows, string $family): array
    {
        if (!isset(self::INGREDIENT_NAMES[$family])) {
            throw new InvalidArgumentException('Unknown net-fillet family: '.$family);
        }
        $expected = self::normalize(self::INGREDIENT_NAMES[$family]);
        $matches = [];
        foreach ($rows as $row) {
            $row = (array) $row;
            if (!isset($row['name']) || !is_string($row['name'])) continue;
            if (self::normalize($row['name']) === $expected) $matches[] = $row;
        }
        if (count($matches) !== 1) {
            throw new RuntimeException('Expected exactly one stock ingredient for '.$family
                .' ('.self::INGREDIENT_NAMES[$family].'); found '.count($matches).'.');
        }
        return self::validatedIngredient($matches[0]);
    }

    /** @return array{0:array<int,array<string,int|string>>} */
    public static function variants(array $ingredient): array
    {
        $ingredient = self::validatedIngredient($ingredient);
        return [0 => [[
            'ingredient_id' => $ingredient['id'],
            'name' => $ingredient['name'],
            'unit' => 'kg',
            'quantity_units' => self::RAW_UNITS_PER_KG,
            'measure' => 'kg',
            'quantity' => '2',
        ]]];
    }

    public static function variantsJson(array $ingredient): string
    {
        // The object cast is essential: the base variant must encode as {"0": [...]}, not [...].
        return json_encode((object) self::variants($ingredient),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    /**
     * Check saved recipes through the production public reader without creating a sale or stock movement.
     * BranchInventory::snapshots exists in both the deployed stock version and the newer ERP version.
     * A public-app kilo feature may alias to the same base recipe; half/quarter features are not introduced.
     *
     * @return array<int,array{feature_id:int,quantity_millis:int,raw_quantity_units:int,recipe_id:int}>
     */
    public static function verifySnapshots(object $inventory, string $branch, int $skuId,
        array $ingredient, int $kiloFeatureId = 0): array
    {
        $ingredient = self::validatedIngredient($ingredient);
        if (!preg_match('/\Af:[1-9][0-9]*\z/D', $branch) || $skuId < 1 || $kiloFeatureId < 0) {
            throw new InvalidArgumentException('Invalid branch, SKU or kilo feature identifier.');
        }
        if (!is_callable([$inventory, 'snapshots'])) {
            throw new RuntimeException('The installed inventory service has no public snapshots reader.');
        }
        $result = [];
        foreach (array_unique([0, $kiloFeatureId]) as $featureId) {
            foreach ([1000, 500, 250] as $millis) {
                $rows = $inventory->snapshots($branch, [[
                    'product_id' => $skuId,
                    'name' => 'net-fillet contract verification',
                    'feature_id' => $featureId,
                    'quantity_millis' => $millis,
                ]]);
                if (!is_array($rows) || count($rows) !== 1 || !isset($rows[0]['inventory'])) {
                    throw new RuntimeException('Inventory snapshots returned an invalid verification line.');
                }
                $line = $rows[0];
                $snapshot = $line['inventory'];
                if (!is_array($snapshot)
                    || ($snapshot['version'] ?? null) !== 1
                    || ($snapshot['branch'] ?? null) !== $branch
                    || ($snapshot['product_id'] ?? null) !== $skuId
                    || ($snapshot['feature_id'] ?? null) !== $featureId
                    || ($snapshot['unit'] ?? null) !== 'kg'
                    || !is_int($snapshot['recipe_id'] ?? null) || $snapshot['recipe_id'] < 1
                    || !is_int($snapshot['revision'] ?? null) || $snapshot['revision'] < 1
                    || !empty($snapshot['stock_source'])
                    || ($line['product_id'] ?? null) !== $skuId
                    || ($line['quantity_millis'] ?? null) !== $millis) {
                    throw new RuntimeException('The saved recipe snapshot does not match the weighted SKU.');
                }
                self::assertComponents($snapshot['components'] ?? null, $ingredient);
                $rawUnits = intdiv(self::RAW_UNITS_PER_KG * $millis + 500, 1000);
                if ($rawUnits !== [1000=>2000000, 500=>1000000, 250=>500000][$millis]) {
                    throw new RuntimeException('The fractional raw-stock quantity is invalid.');
                }
                $result[] = ['feature_id'=>$featureId, 'quantity_millis'=>$millis,
                    'raw_quantity_units'=>$rawUnits, 'recipe_id'=>$snapshot['recipe_id']];
            }
        }
        return $result;
    }

    private static function validatedIngredient(array $ingredient): array
    {
        $id = $ingredient['id'] ?? null;
        if ((!is_int($id) && !(is_string($id) && ctype_digit($id)))
            || (int) $id < 1 || (string) (int) $id !== (string) $id
            || !isset($ingredient['name']) || !is_string($ingredient['name'])
            || self::normalize($ingredient['name']) === '' || ($ingredient['unit'] ?? null) !== 'kg') {
            throw new RuntimeException('A net-fillet ingredient must be an existing kilogram stock ingredient.');
        }
        return ['id'=>(int) $id, 'name'=>$ingredient['name'], 'unit'=>'kg'];
    }

    private static function assertComponents($components, array $ingredient): void
    {
        $expected = self::variants($ingredient)[0];
        if (!is_array($components) || count($components) !== 1 || !isset($components[0])
            || !is_array($components[0])) {
            throw new RuntimeException('The recipe must contain exactly one approved raw ingredient.');
        }
        $actual = $components[0];
        ksort($actual);
        $component = $expected[0];
        ksort($component);
        if ($actual !== $component) {
            throw new RuntimeException('The raw ingredient or 2:1 recipe component is incorrect.');
        }
    }
}
