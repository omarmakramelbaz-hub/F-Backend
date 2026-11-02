<?php

namespace Tests;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    use CreatesApplication;

    protected function setUp(): void
    {
        // Eloquent keeps each model's table columns (for mass assignment) in a static list for the whole process.
        // Tests build their own tables, so a list read by an earlier test must never decide what a later one saves.
        (function () {
            static::$guardableColumns = [];
        })->bindTo(null, Model::class)();
        parent::setUp();
    }
}
