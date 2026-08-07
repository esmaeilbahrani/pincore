<?php

use Pinoox\Component\Kernel\Loader;
use Pinoox\Component\Migration\MigrationQuery;
use Pinoox\Component\Migration\Migrator;
use Pinoox\Component\Test\AppTestKit;
use Pinoox\Model\Table;
use Pinoox\Portal\App\AppEngine;
use Pinoox\Portal\App\AppProvider;
use Pinoox\Portal\Database\DB;
use Pinoox\Support\PackageContext;

$package = 'com_test_migrator_harden';

beforeEach(function () use ($package) {
    Loader::setBasePath(testProjectRoot());
    AppProvider::___();
    PackageContext::use(null);
    deleteTestApp($package);
    AppEngine::__rebuild();

    @unlink(sys_get_temp_dir() . "/migration_lock_{$package}.lock");
    @unlink(sys_get_temp_dir() . '/migration_lock_platform.lock');

    DB::refreshCoreConnection([
        'driver' => 'sqlite',
        'database' => ':memory:',
        'prefix' => 'pinx_',
    ]);

    (new Migrator('platform', 'run'))->run();
});

afterEach(function () use ($package) {
    PackageContext::use(null);
    deleteTestApp($package);
    AppEngine::__rebuild();
    @unlink(sys_get_temp_dir() . "/migration_lock_{$package}.lock");
});

function migratorHardeningHistoryExists(string $package, string $migration): bool
{
    return DB::table(DB::tableName(Table::HISTORY, 'platform'), null, 'platform')
        ->where('type', MigrationQuery::TYPE_MIGRATION)
        ->where('app', $package)
        ->where('migration', $migration)
        ->exists();
}

function migratorHardeningAppSchema(string $package)
{
    return DB::schema(DB::connectionNameForPackage($package));
}

function migratorHardeningFakeApp(string $package, array $migrationFiles): void
{
    $files = [
        'app.php' => "<?php\n\nreturn "
            . var_export([
                'package' => $package,
                'enable' => true,
                'name' => $package,
                'database' => null,
                'table' => ['prefix' => 'harden_'],
            ], true)
            . ";\n",
    ];

    foreach ($migrationFiles as $name => $contents) {
        $files['database/migrations/' . $name . '.php'] = $contents;
    }

    AppTestKit::fakeApp($package, $files);
    AppEngine::__rebuild();
}

it('runs create then add and records both only after schema changes', function () use ($package) {
    migratorHardeningFakeApp($package, [
        '2026_01_01_000001_create_widgets_table' => <<<'PHP'
<?php
use Illuminate\Database\Schema\Blueprint;
use Pinoox\Component\Migration\MigrationBase;
return new class extends MigrationBase {
    public function up(): void
    {
        $this->schema->create('widgets', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name')->nullable();
        });
    }
    public function down(): void
    {
        $this->schema->dropIfExists('widgets');
    }
};
PHP,
        '2026_01_01_000002_add_description_to_widgets_table' => <<<'PHP'
<?php
use Illuminate\Database\Schema\Blueprint;
use Pinoox\Component\Migration\MigrationBase;
return new class extends MigrationBase {
    public function up(): void
    {
        $this->requireTable('widgets');
        $this->schema->table('widgets', function (Blueprint $table) {
            $table->string('description')->nullable();
        });
    }
    public function down(): void
    {
        $this->schema->table('widgets', function (Blueprint $table) {
            $table->dropColumn('description');
        });
    }
};
PHP,
    ]);

    $result = (new Migrator($package, 'run'))->run();

    $schema = migratorHardeningAppSchema($package);

    expect($result['executed'] ?? [])
        ->toContain('2026_01_01_000001_create_widgets_table')
        ->toContain('2026_01_01_000002_add_description_to_widgets_table')
        ->and($schema->hasTable('widgets'))->toBeTrue()
        ->and($schema->hasColumn('widgets', 'description'))->toBeTrue()
        ->and(migratorHardeningHistoryExists($package, '2026_01_01_000001_create_widgets_table'))->toBeTrue()
        ->and(migratorHardeningHistoryExists($package, '2026_01_01_000002_add_description_to_widgets_table'))->toBeTrue();
});

it('refuses add migrations when the target table is missing and does not record history', function () use ($package) {
    migratorHardeningFakeApp($package, [
        '2026_01_01_000002_add_description_to_widgets_table' => <<<'PHP'
<?php
use Illuminate\Database\Schema\Blueprint;
use Pinoox\Component\Migration\MigrationBase;
return new class extends MigrationBase {
    public function up(): void
    {
        $this->schema->table('widgets', function (Blueprint $table) {
            $table->string('description')->nullable();
        });
    }
    public function down(): void {}
};
PHP,
    ]);

    expect(fn () => (new Migrator($package, 'run'))->run())
        ->toThrow(Exception::class, "target table 'widgets' does not exist");

    expect(migratorHardeningHistoryExists($package, '2026_01_01_000002_add_description_to_widgets_table'))
        ->toBeFalse();
});

it('does not record create migrations that finish without creating the table', function () use ($package) {
    migratorHardeningFakeApp($package, [
        '2026_01_01_000001_create_widgets_table' => <<<'PHP'
<?php
use Pinoox\Component\Migration\MigrationBase;
return new class extends MigrationBase {
    public function up(): void
    {
        // Silent no-op — historically could still be recorded as success.
    }
    public function down(): void {}
};
PHP,
    ]);

    expect(fn () => (new Migrator($package, 'run'))->run())
        ->toThrow(Exception::class, "table 'widgets' is still missing");

    expect(migratorHardeningHistoryExists($package, '2026_01_01_000001_create_widgets_table'))
        ->toBeFalse()
        ->and(migratorHardeningAppSchema($package)->hasTable('widgets'))->toBeFalse();
});

it('does not record add migrations that finish without the expected column', function () use ($package) {
    migratorHardeningFakeApp($package, [
        '2026_01_01_000001_create_widgets_table' => <<<'PHP'
<?php
use Illuminate\Database\Schema\Blueprint;
use Pinoox\Component\Migration\MigrationBase;
return new class extends MigrationBase {
    public function up(): void
    {
        $this->schema->create('widgets', function (Blueprint $table) {
            $table->increments('id');
        });
    }
    public function down(): void
    {
        $this->schema->dropIfExists('widgets');
    }
};
PHP,
        '2026_01_01_000002_add_description_to_widgets_table' => <<<'PHP'
<?php
use Pinoox\Component\Migration\MigrationBase;
return new class extends MigrationBase {
    public function up(): void
    {
        // Pretend success without adding the column named in the file.
    }
    public function down(): void {}
};
PHP,
    ]);

    expect(fn () => (new Migrator($package, 'run'))->run())
        ->toThrow(Exception::class, "column 'description' is missing");

    expect(migratorHardeningHistoryExists($package, '2026_01_01_000001_create_widgets_table'))
        ->toBeTrue()
        ->and(migratorHardeningHistoryExists($package, '2026_01_01_000002_add_description_to_widgets_table'))
        ->toBeFalse()
        ->and(migratorHardeningAppSchema($package)->hasColumn('widgets', 'description'))->toBeFalse();
});

it('re-runs create migrations when history exists but the table was dropped', function () use ($package) {
    migratorHardeningFakeApp($package, [
        '2026_01_01_000001_create_widgets_table' => <<<'PHP'
<?php
use Illuminate\Database\Schema\Blueprint;
use Pinoox\Component\Migration\MigrationBase;
return new class extends MigrationBase {
    public function up(): void
    {
        $this->schema->create('widgets', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name')->nullable();
        });
    }
    public function down(): void
    {
        $this->schema->dropIfExists('widgets');
    }
};
PHP,
    ]);

    (new Migrator($package, 'run'))->run();

    $schema = migratorHardeningAppSchema($package);
    expect($schema->hasTable('widgets'))->toBeTrue()
        ->and(migratorHardeningHistoryExists($package, '2026_01_01_000001_create_widgets_table'))->toBeTrue();

    $schema->drop('widgets');
    expect($schema->hasTable('widgets'))->toBeFalse();

    $result = (new Migrator($package, 'run'))->run();

    expect($result['executed'] ?? [])
        ->toContain('2026_01_01_000001_create_widgets_table')
        ->and($schema->hasTable('widgets'))->toBeTrue();
});
