<?php
declare(strict_types=1);
require __DIR__.'/../deployment/launch_reset/check_schema.php';
function expectLaunchSchema(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); }
$table = ['engine' => 'InnoDB', 'columns' => ['id' => 'bigint(20) unsigned', 'name' => 'varchar(100) nullable'], 'references' => [], 'unique_indexes' => ['PRIMARY' => ['id']]];
$expected = ['orders' => $table, 'employees' => $table];
$same = ['employees' => array_reverse($table, true), 'orders' => $table];
$same['orders']['columns'] = array_reverse($table['columns'], true);
$diff = launchSchemaDiff($expected, $same);
expectLaunchSchema($diff['expected_match'] && !$diff['expected_changed'] && !$diff['expected_missing'], 'Associative ordering changed equality');
expectLaunchSchema(launchSchemaFingerprint($expected) === launchSchemaFingerprint($same), 'Associative ordering changed fingerprint');
$variants = [
    'columns' => fn($t) => array_replace($t, ['columns' => $t['columns'] + ['part_time_rate' => 'int(11) nullable']]),
    'engine' => fn($t) => array_replace($t, ['engine' => 'MyISAM']),
    'references' => fn($t) => array_replace($t, ['references' => [['column' => 'name', 'table' => 'employees', 'target_column' => 'name', 'delete_rule' => 'CASCADE']]]),
    'unique_indexes' => fn($t) => array_replace($t, ['unique_indexes' => $t['unique_indexes'] + ['name_unique' => ['name']]])
];
foreach ($variants as $field => $transform) {
    $actual = $expected; $actual['orders'] = $transform($table); $diff = launchSchemaDiff($expected, $actual);
    expectLaunchSchema(!$diff['expected_match'] && $diff['expected_changed']['orders']['changed_fields'] === [$field], 'Metadata change not identified: '.$field);
    expectLaunchSchema($diff['expected_changed']['orders']['expected'] === $table && $diff['expected_changed']['orders']['actual'] === $actual['orders'], 'Full metadata delta omitted');
    expectLaunchSchema(launchSchemaFingerprint($actual) !== launchSchemaFingerprint($expected), 'Metadata change did not change fingerprint');
}
$actual = $expected; unset($actual['employees']);
$actual['new_plain'] = $table;
$actual['new_branch'] = $table; $actual['new_branch']['columns']['branch'] = 'varchar(30)';
$actual['new_fk'] = $table; $actual['new_fk']['references'] = [['column' => 'nonstandard_link', 'table' => 'orders', 'target_column' => 'id', 'delete_rule' => 'RESTRICT']];
$diff = launchSchemaDiff($expected, $actual);
expectLaunchSchema($diff['expected_missing'] === ['employees'], 'Missing table not identified');
expectLaunchSchema($diff['new_relation_tables'] === ['new_branch', 'new_fk'], 'New branch or nonstandard physical FK table not identified');
expectLaunchSchema($diff['new_tables']['new_fk']['references_expected_tables'] === $actual['new_fk']['references'], 'Physical FK metadata omitted');
expectLaunchSchema($diff['new_tables']['new_fk']['references_deleted_tables'] === $actual['new_fk']['references'], 'Deleted-parent FK metadata omitted');
expectLaunchSchema(!$diff['new_tables']['new_plain']['requires_relation_review'] && $diff['new_tables']['new_plain']['metadata'] === $table, 'Unrelated new table metadata missing');
expectLaunchSchema($diff['new_tables']['new_branch']['guard_columns'] === ['branch'], 'Existing guard columns not identified');
$ordered = $expected; $ordered['orders']['unique_indexes']['pair'] = ['id', 'name'];
$reordered = $ordered; $reordered['orders']['unique_indexes']['pair'] = ['name', 'id'];
expectLaunchSchema(!launchSchemaDiff($ordered, $reordered)['expected_match'], 'Composite unique index column order incorrectly ignored');
expectLaunchSchema(launchSchemaFingerprint($ordered) !== launchSchemaFingerprint($reordered), 'Composite index order fingerprint incorrectly ignored');
expectLaunchSchema(launchSchemaDiff($expected, $same)['expected_match'] === ($expected == $same), 'Comparison diverged from reset PHP equality');
$clean = launchSchemaDiff($expected, $same);
expectLaunchSchema(launchSchemaStrictPass($clean, true, 0, 0), 'Unchanged trigger-free schema rejected');
expectLaunchSchema(!launchSchemaStrictPass($clean, false, 0, 0), 'Unstable schema accepted');
expectLaunchSchema(!launchSchemaStrictPass($clean, true, 1, 1), 'Existing triggers accepted');
expectLaunchSchema(!launchSchemaStrictPass($diff, true, 0, 0), 'Missing or related new table accepted');
$newRelatedOnly = $expected; $newRelatedOnly['new_fk'] = $actual['new_fk'];
expectLaunchSchema(!launchSchemaStrictPass(launchSchemaDiff($expected, $newRelatedOnly), true, 0, 0), 'New physical FK table accepted');
$newPlainOnly = $expected; $newPlainOnly['new_plain'] = $table;
expectLaunchSchema(launchSchemaStrictPass(launchSchemaDiff($expected, $newPlainOnly), true, 0, 0), 'Unrelated new table unexpectedly blocked');
$preservedExpected = $expected + ['resturants' => $table];
$preservedActual = $preservedExpected;
$preservedActual['wishlists'] = $table;
$preservedActual['wishlists']['references'] = [['column' => 'resturant_id', 'table' => 'resturants', 'target_column' => 'id', 'delete_rule' => 'CASCADE']];
$preservedDiff = launchSchemaDiff($preservedExpected, $preservedActual);
expectLaunchSchema($preservedDiff['new_tables']['wishlists']['references_expected_tables'] === $preservedActual['wishlists']['references'], 'Preserved-parent FK should remain visible');
expectLaunchSchema($preservedDiff['new_tables']['wishlists']['references_deleted_tables'] === [] && !$preservedDiff['new_tables']['wishlists']['requires_relation_review'], 'Preserved restaurant FK incorrectly blocks reset');
expectLaunchSchema(launchSchemaStrictPass($preservedDiff, true, 0, 0), 'Preserved catalogue FK rejected by strict gate');
$baseline = json_decode(file_get_contents(__DIR__.'/../deployment/launch_reset/inspected_schema.json'), true, 512, JSON_THROW_ON_ERROR);
$approved = $baseline; $approved['payments']['columns']['total_price'] = 'decimal(15,2) unsigned nullable';
expectLaunchSchema(launchReviewedSchema($baseline, $baseline) === $baseline, 'Original payment schema rejected');
expectLaunchSchema(launchReviewedSchema($baseline, $approved) == $approved, 'Exact reviewed payment column rejected');
expectLaunchSchema(launchSchemaStrictPass(launchSchemaDiff(launchReviewedSchema($baseline, $approved), $approved), true, 0, 0), 'Reviewed payment variant failed strict gate');
foreach (['decimal(15,2) nullable', 'decimal(15,2) unsigned', 'decimal(15,2) unsigned nullable STORED GENERATED', 'decimal(14,2) unsigned nullable', 'decimal(15,3) unsigned nullable'] as $type) {
    $invalid = $approved; $invalid['payments']['columns']['total_price'] = $type;
    expectLaunchSchema(launchReviewedSchema($baseline, $invalid) === $baseline, 'Unapproved payment type accepted');
    expectLaunchSchema(!launchSchemaStrictPass(launchSchemaDiff(launchReviewedSchema($baseline, $invalid), $invalid), true, 0, 0), 'Unapproved payment type passed gate');
}
foreach (['column', 'index', 'reference', 'engine'] as $kind) {
    $invalid = $approved;
    if ($kind === 'column') $invalid['payments']['columns']['unexpected'] = 'varchar(20) nullable';
    if ($kind === 'index') $invalid['payments']['unique_indexes']['total_price_unique'] = ['total_price'];
    if ($kind === 'reference') $invalid['payments']['references'][] = ['column' => 'total_price', 'table' => 'orders', 'target_column' => 'id', 'delete_rule' => 'CASCADE'];
    if ($kind === 'engine') $invalid['payments']['engine'] = 'MyISAM';
    expectLaunchSchema(launchReviewedSchema($baseline, $invalid) === $baseline, 'Additional payment metadata change accepted: '.$kind);
    expectLaunchSchema(!launchSchemaStrictPass(launchSchemaDiff(launchReviewedSchema($baseline, $invalid), $invalid), true, 0, 0), 'Additional payment change passed strict gate');
}
echo "PASS: schema comparison follows reset equality, detects column/engine/FK/index/missing/new-table changes, and fingerprints preserve composite index order\n";
