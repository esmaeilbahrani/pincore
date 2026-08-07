<?php

use Pinoox\Component\Kernel\Loader;
use Pinoox\Component\Migration\MigrationBase;
use Pinoox\Portal\App\AppEngine;
use Pinoox\Portal\App\AppProvider;
use Pinoox\Portal\Database\DB;
use Pinoox\Support\PackageContext;

beforeEach(function () {
    Loader::setBasePath(testProjectRoot());
    AppProvider::___();
    PackageContext::use(null);
    deleteTestApp('com_test_migration_base');
    AppEngine::__rebuild();

    DB::refreshCoreConnection([
        'driver' => 'sqlite',
        'database' => ':memory:',
        'prefix' => 'pinx_',
    ]);
});

afterEach(function () {
    PackageContext::use(null);
    deleteTestApp('com_test_migration_base');
    AppEngine::__rebuild();
});

it('bindPackage sets PackageContext and package-aware schema', function () {
    writeTestApp('com_test_migration_base', [
        'database' => null,
        'table' => [
            'prefix' => 'mig_',
        ],
    ]);
    AppEngine::__rebuild();

    $migration = new class extends MigrationBase {
        public function up(): void
        {
        }
    };

    $migration->bindPackage('com_test_migration_base');

    expect($migration->packageName())->toBe('com_test_migration_base')
        ->and(PackageContext::resolve())->toBe('com_test_migration_base');

    $migration->schema->create('widgets', function ($table) {
        $table->increments('id');
        $table->string('name')->nullable();
    });

    expect($migration->schema->hasTable('widgets'))->toBeTrue()
        ->and(DB::connection(DB::connectionNameForPackage('com_test_migration_base'))
            ->getTablePrefix())->toBe('mig_');
});

it('requireTable throws when the logical table is missing', function () {
    writeTestApp('com_test_migration_base', [
        'database' => null,
        'table' => [
            'prefix' => 'mig_',
        ],
    ]);
    AppEngine::__rebuild();

    $migration = new class extends MigrationBase {
        public function up(): void
        {
        }

        public function assertRequired(string $table): void
        {
            $this->requireTable($table);
        }
    };

    $migration->bindPackage('com_test_migration_base');

    expect(fn () => $migration->assertRequired('missing_widgets'))
        ->toThrow(RuntimeException::class, 'missing_widgets');
});
