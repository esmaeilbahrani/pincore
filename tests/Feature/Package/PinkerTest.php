<?php

use Pinoox\Component\Kernel\Loader;
use Pinoox\Component\Test\AppTestKit;
use Pinoox\Portal\App\AppProvider;
use Pinoox\Portal\Pinker;

beforeEach(function () {
    Loader::setBasePath(testProjectRoot());
    AppProvider::___();
    deletePinkerTestApp('com_test_pinker');
});

afterEach(function () {
    deletePinkerTestApp('com_test_pinker');
});

it('stores app baked files in the configured pinker directory', function () {
    $appsRoot = pinkerTestAppsRoot();
    $pinkerRoot = pinkerTestPinkerRoot();
    $package = 'com_test_pinker';
    $appDir = $appsRoot . '/' . $package;

    if (!is_dir($appDir)) {
        mkdir($appDir, 0777, true);
    }

    file_put_contents($appDir . '/app.php', "<?php\n\nreturn ['package' => '{$package}', 'name' => 'Pinker Test'];\n");

    $pinker = Pinker::folder($appDir, 'app.php');
    $data = $pinker->pickup();
    $status = $pinker->status();

    expect($pinker->getBakedFile())->toBe($pinkerRoot . '/apps/' . $package . '/app.php')
        ->and($data['package'])->toBe($package)
        ->and($status['source_size'])->toBe(filesize($appDir . '/app.php'))
        ->and(is_file($pinkerRoot . '/apps/' . $package . '/app.php'))->toBeTrue()
        ->and(is_dir($appDir . '/pinker'))->toBeFalse();
});

it('refreshes the cache when the source file changes', function () {
    $appsRoot = pinkerTestAppsRoot();
    $package = 'com_test_pinker';
    $appDir = $appsRoot . '/' . $package;

    if (!is_dir($appDir)) {
        mkdir($appDir, 0777, true);
    }

    file_put_contents($appDir . '/app.php', "<?php\n\nreturn ['name' => 'Before'];\n");

    $pinker = Pinker::folder($appDir, 'app.php');

    expect($pinker->pickup()['name'])->toBe('Before');

    sleep(1);
    file_put_contents($appDir . '/app.php', "<?php\n\nreturn ['name' => 'After'];\n");

    expect($pinker->pickup()['name'])->toBe('After');
});

it('keeps env sensitive source files out of the cache', function () {
    $appsRoot = pinkerTestAppsRoot();
    $package = 'com_test_pinker';
    $appDir = $appsRoot . '/' . $package;

    if (!is_dir($appDir)) {
        mkdir($appDir, 0777, true);
    }

    file_put_contents($appDir . '/app.php', "<?php\n\nreturn ['debug' => env('PINKER_TEST_DEBUG', false)];\n");

    $pinker = Pinker::folder($appDir, 'app.php');
    $pinker->pickup();

    expect(is_file($pinker->getBakedFile()))->toBeFalse();
});

it('keeps env-sensitive pinker overrides when source is newer and env keys are absent', function () {
    $appsRoot = pinkerTestAppsRoot();
    $package = 'com_test_pinker';
    $appDir = $appsRoot . '/' . $package;

    if (!is_dir($appDir)) {
        mkdir($appDir, 0777, true);
    }

    $source = $appDir . '/database.config.php';
    file_put_contents($source, <<<'PHP'
<?php

return [
    'default' => env('DB_CONNECTION', 'mysql'),
    'connections' => [
        'mysql' => [
            'driver' => 'mysql',
            'host' => env('DB_HOST', '127.0.0.1'),
            'database' => env('DB_DATABASE', 'pinoox'),
            'username' => env('DB_USERNAME', 'root'),
            'password' => env('DB_PASSWORD', ''),
        ],
    ],
];
PHP);

    $pinker = Pinker::folder($appDir, 'database.config.php');
    $overrideFile = $pinker->getOverrideFile();
    expect($overrideFile)->not->toBeNull();

    $overrideDir = dirname((string) $overrideFile);
    if (!is_dir($overrideDir)) {
        mkdir($overrideDir, 0777, true);
    }

    $updatedAt = time() - 3600;
    file_put_contents((string) $overrideFile, '<?php return ' . var_export([
        '__pinker_override__' => true,
        'schema' => 1,
        'data' => [
            'default' => 'mysql',
            'connections.mysql.host' => 'pinker-host',
            'connections.mysql.database' => 'pin',
            'connections.mysql.username' => 'orbit_user',
            'connections.mysql.password' => 'secret',
        ],
        'remove' => [],
        'info' => [
            'source' => $source,
            'updated_at' => $updatedAt,
            'env_sensitive' => 'yes',
            'env_priority' => 'env-over-pinker',
        ],
    ], true) . ';');

    putenv('APP_ENV=production');
    $_ENV['APP_ENV'] = 'production';
    $_SERVER['APP_ENV'] = 'production';
    foreach (['DB_HOST', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD', 'DB_CONNECTION'] as $key) {
        putenv($key);
        unset($_ENV[$key], $_SERVER[$key]);
    }

    // Simulate vendor upgrade: source mtime newer than pinker override.
    touch($source, time() + 120);
    clearstatcache(true, $source);

    try {
        $picked = Pinker::folder($appDir, 'database.config.php')->pickup();

        expect($picked['connections']['mysql']['host'] ?? null)->toBe('pinker-host')
            ->and($picked['connections']['mysql']['database'] ?? null)->toBe('pin')
            ->and($picked['connections']['mysql']['username'] ?? null)->toBe('orbit_user')
            ->and(is_file((string) $overrideFile))->toBeTrue();

        $override = include $overrideFile;
        expect($override['data']['connections.mysql.host'] ?? null)->toBe('pinker-host');
    } finally {
        putenv('APP_ENV');
        unset($_ENV['APP_ENV'], $_SERVER['APP_ENV']);
    }
});

it('does not persist runtime defaults absent from source on bake', function () {
    $appsRoot = pinkerTestAppsRoot();
    $package = 'com_test_pinker';
    $appDir = $appsRoot . '/' . $package;

    if (!is_dir($appDir)) {
        mkdir($appDir, 0777, true);
    }

    file_put_contents($appDir . '/app.php', "<?php\n\nreturn ['package' => '{$package}', 'name' => 'Test'];\n");

    $defaults = [
        'enable' => true,
        'lang' => 'en',
        'router' => ['routes' => ['routes/web.php']],
    ];

    $pinker = Pinker::folder($appDir, 'app.php')
        ->dumping(true)
        ->runtimeDefaults($defaults);

    $pickup = $pinker->pickup();
    $merged = array_replace_recursive($defaults, is_array($pickup) ? $pickup : []);

    $pinker->data($merged)->bake();

    expect(is_file($pinker->getOverrideFile()))->toBeFalse();
});

it('persists only user changes when saving merged runtime defaults', function () {
    $appsRoot = pinkerTestAppsRoot();
    $package = 'com_test_pinker';
    $appDir = $appsRoot . '/' . $package;

    if (!is_dir($appDir)) {
        mkdir($appDir, 0777, true);
    }

    file_put_contents($appDir . '/app.php', "<?php\n\nreturn ['package' => '{$package}', 'name' => 'Test'];\n");

    $defaults = [
        'enable' => true,
        'lang' => 'en',
    ];

    $pinker = Pinker::folder($appDir, 'app.php')
        ->dumping(true)
        ->runtimeDefaults($defaults);

    $pickup = $pinker->pickup();
    $merged = array_replace_recursive($defaults, is_array($pickup) ? $pickup : []);
    $merged['enable'] = false;
    $merged['lang'] = 'fa';

    $pinker->data($merged)->bake();

    $override = include $pinker->getOverrideFile();

    expect($override['data'])
        ->toMatchArray(['enable' => false, 'lang' => 'fa'])
        ->and($override['data'])->not->toHaveKey('router');
});

it('prefers source values for shared keys when source is newer than state', function () {
    $appsRoot = pinkerTestAppsRoot();
    $package = 'com_test_pinker';
    $appDir = $appsRoot . '/' . $package;

    if (!is_dir($appDir)) {
        mkdir($appDir, 0777, true);
    }

    file_put_contents($appDir . '/app.php', "<?php\n\nreturn ['package' => '{$package}', 'lang' => 'en'];\n");

    $pinker = Pinker::folder($appDir, 'app.php')
        ->dumping(true)
        ->runtimeDefaults(['lang' => 'en']);

    $pickup = $pinker->pickup();
    $merged = array_replace_recursive(['lang' => 'en'], is_array($pickup) ? $pickup : []);
    $merged['lang'] = 'fa';
    $merged['open'] = 'runtime';

    $pinker->data($merged)->bake();

    sleep(1);
    file_put_contents($appDir . '/app.php', "<?php\n\nreturn ['package' => '{$package}', 'lang' => 'ar'];\n");

    expect($pinker->pickup())
        ->toMatchArray(['lang' => 'ar', 'open' => 'runtime']);

    $override = include $pinker->getOverrideFile();

    expect($override['data'])
        ->toHaveKey('open')
        ->not->toHaveKey('lang');
});

it('keeps state overrides for unchanged source keys when only part of the source changes', function () {
    $appsRoot = pinkerTestAppsRoot();
    $package = 'com_test_pinker';
    $appDir = $appsRoot . '/' . $package;

    if (!is_dir($appDir)) {
        mkdir($appDir, 0777, true);
    }

    file_put_contents($appDir . '/app.php', "<?php\n\nreturn ['package' => '{$package}', 'lang' => 'en', 'title' => 'Before'];\n");

    $pinker = Pinker::folder($appDir, 'app.php')
        ->dumping(true)
        ->runtimeDefaults(['lang' => 'en']);

    $pickup = $pinker->pickup();
    $merged = array_replace_recursive(['lang' => 'en'], is_array($pickup) ? $pickup : []);
    $merged['lang'] = 'fa';
    $merged['open'] = 'runtime';

    $pinker->data($merged)->bake();

    sleep(1);
    file_put_contents($appDir . '/app.php', "<?php\n\nreturn ['package' => '{$package}', 'lang' => 'en', 'title' => 'After'];\n");

    expect($pinker->pickup())
        ->toMatchArray(['lang' => 'fa', 'title' => 'After', 'open' => 'runtime']);

    $override = include $pinker->getOverrideFile();

    expect($override['data'])
        ->toMatchArray(['lang' => 'fa', 'open' => 'runtime'])
        ->not->toHaveKey('title');
});

it('keeps state overrides for shared keys when state is newer than source', function () {
    $appsRoot = pinkerTestAppsRoot();
    $package = 'com_test_pinker';
    $appDir = $appsRoot . '/' . $package;

    if (!is_dir($appDir)) {
        mkdir($appDir, 0777, true);
    }

    file_put_contents($appDir . '/app.php', "<?php\n\nreturn ['package' => '{$package}', 'lang' => 'en'];\n");

    $pinker = Pinker::folder($appDir, 'app.php')
        ->dumping(true)
        ->runtimeDefaults(['lang' => 'en']);

    $pickup = $pinker->pickup();
    $merged = array_replace_recursive(['lang' => 'en'], is_array($pickup) ? $pickup : []);
    $merged['lang'] = 'fa';

    $pinker->data($merged)->bake();

    file_put_contents($appDir . '/app.php', "<?php\n\nreturn ['package' => '{$package}', 'lang' => 'ar'];\n");

    expect($pinker->pickup()['lang'])->toBe('fa');
});

it('stores runtime config changes as state overrides', function () {
    $appsRoot = pinkerTestAppsRoot();
    $package = 'com_test_pinker';
    $appDir = $appsRoot . '/' . $package;

    if (!is_dir($appDir)) {
        mkdir($appDir, 0777, true);
    }

    file_put_contents($appDir . '/app.php', "<?php\n\nreturn ['name' => 'Source', 'nested' => ['keep' => true, 'remove' => true]];\n");

    $pinker = Pinker::folder($appDir, 'app.php');
    $data = $pinker->pickup();
    unset($data['nested']['remove']);
    $data['name'] = 'Runtime';

    $pinker->data($data)->bake();

    expect($pinker->pickup())
        ->toMatchArray(['name' => 'Runtime', 'nested' => ['keep' => true]])
        ->and($pinker->getBakedFile())->toBe(pinkerTestPinkerRoot() . '/apps/' . $package . '/app.php')
        ->and($pinker->getOverrideFile())->toBe(pinkerTestPinkerRoot() . '/state/apps/' . $package . '/app.php')
        ->and(is_file($pinker->getOverrideFile()))->toBeTrue();
});

it('bakes theme flow aliases from app.php helpers', function () {
    $appsRoot = pinkerTestAppsRoot();
    $package = 'com_test_pinker';
    $appDir = $appsRoot . '/' . $package;

    if (!is_dir($appDir)) {
        mkdir($appDir, 0777, true);
    }

    file_put_contents($appDir . '/app.php', <<<'PHP'
<?php

return [
    'package' => 'com_test_pinker',
    'alias' => [
        'auth' => 'App\\com_test_pinker\\Flow\\AuthFlow',
        ...theme_flow_aliases(['site', 'panel']),
    ],
];
PHP);

    $pinker = Pinker::folder($appDir, 'app.php')->dumping(true);
    $data = $pinker->pickup();

    expect($data['alias']['theme']['site'])->toBeInstanceOf(\Pinoox\Flow\ThemeContextFlow::class)
        ->and($data['alias']['theme']['panel'])->toBeInstanceOf(\Pinoox\Flow\ThemeContextFlow::class)
        ->and($data['alias']['theme']['site']->context())->toBe('site');

    $baked = file_get_contents($pinker->getBakedFile());

    expect($baked)
        ->toContain('\\Pinoox\\Flow\\ThemeContextFlow::for(\'site\')')
        ->toContain('\\Pinoox\\Flow\\ThemeContextFlow::for(\'panel\')')
        ->not->toContain('__set_state');

    $reloaded = include $pinker->getBakedFile();

    expect($reloaded['alias']['theme']['panel'])->toBeInstanceOf(\Pinoox\Flow\ThemeContextFlow::class)
        ->and($reloaded['alias']['theme']['panel']->context())->toBe('panel');
});

it('bakes closures in app config files', function () {
    $appsRoot = pinkerTestAppsRoot();
    $package = 'com_test_pinker';
    $appDir = $appsRoot . '/' . $package;

    if (!is_dir($appDir)) {
        mkdir($appDir, 0777, true);
    }

    file_put_contents($appDir . '/app.php', <<<'PHP'
<?php

return [
    'package' => 'com_test_pinker',
    'startup' => function () {
        return 'ready';
    },
    'hooks' => [
        'nested' => function (array $payload = []) {
            return $payload['value'] ?? null;
        },
    ],
];
PHP);

    $pinker = Pinker::folder($appDir, 'app.php')->dumping(true);
    $data = $pinker->pickup();

    expect($data['startup'])->toBeInstanceOf(Closure::class)
        ->and($data['startup']())->toBe('ready')
        ->and($data['hooks']['nested'](['value' => 'ok']))->toBe('ok');
});

it('recovers when the baked cache file is corrupted', function () {
    $appsRoot = pinkerTestAppsRoot();
    $package = 'com_test_pinker';
    $appDir = $appsRoot . '/' . $package;

    if (!is_dir($appDir)) {
        mkdir($appDir, 0777, true);
    }

    $sourceFile = $appDir . '/app.php';
    file_put_contents($sourceFile, "<?php\n\nreturn ['name' => 'Recovered'];\n");

    $pinker = Pinker::folder($appDir, 'app.php');
    $bakedFile = $pinker->getBakedFile();

    if (!is_dir(dirname($bakedFile))) {
        mkdir(dirname($bakedFile), 0777, true);
    }

    file_put_contents($bakedFile, "<?php\n/**\n * Pinoox Baker\n * @time " . time() . "\n * @schema 2\n * @source {$sourceFile}\n * @source_hash " . sha1_file($sourceFile) . "\n * @source_mtime " . filemtime($sourceFile) . "\n * @source_size " . filesize($sourceFile) . "\n * @env_sensitive no\n */\n\nreturn [");

    expect($pinker->pickup()['name'])->toBe('Recovered')
        ->and($pinker->status()['cache_valid'])->toBeTrue();
});

it('maps pincore config source files to pinker/platform', function () {
    $corePath = pinkerTestPath(testCoreRoot()) . '/';
    $sourceFile = pinkerTestPath($corePath . 'config/app/source.config.php');

    expect(Pinker::bakedFileFromSource($sourceFile))
        ->toBe(pinkerTestPinkerRoot() . '/platform/app/source.config.php');
});

it('keeps test runtime sources inside the isolated pinker root', function () {
    $sourceFile = pinkerTestPath(testRuntimeRoot() . '/config/sample.php');
    $bakedFile = Pinker::bakedFileFromSource($sourceFile);

    expect($bakedFile)
        ->toBe(testRuntimePinker() . '/runtime/config/sample.php')
        ->and($bakedFile)->toStartWith(testRuntimePinker() . '/')
        ->and($bakedFile)->not->toContain('/pinker/pincore/tests/Fixtures/runtime/');
});

it('maps external registry app sources to pinker/apps/{package}', function () {
    $package = 'com_test_pinker_external';
    $externalApp = pinkerTestPath(dirname(testProjectRoot()) . '/pinoox_external_pinker_test/' . $package);

    if (!is_dir($externalApp)) {
        mkdir($externalApp, 0777, true);
    }

    file_put_contents($externalApp . '/app.php', "<?php\n\nreturn ['package' => '{$package}', 'name' => 'External Pinker'];\n");

    $sourceFile = $externalApp . '/theme/ada/theme.php';
    if (!is_dir(dirname($sourceFile))) {
        mkdir(dirname($sourceFile), 0777, true);
    }

    file_put_contents($sourceFile, "<?php\n\nreturn ['name' => 'ada'];\n");

    try {
        expect(Pinker::bakedFileFromSource($sourceFile))
            ->toBe(pinkerTestPinkerRoot() . '/apps/' . $package . '/theme/ada/theme.php');
    } finally {
        deletePinkerTestDirectory(dirname($externalApp));
    }
});

function deletePinkerTestApp(string $package): void
{
    AppTestKit::deleteFakeApp($package);
}

function pinkerTestPath(string $path): string
{
    return str_replace('\\', '/', $path);
}

function pinkerTestAppsRoot(): string
{
    return pinkerTestPath(\Pinoox\Support\SystemConfig::path('apps'));
}

function pinkerTestPinkerRoot(): string
{
    return pinkerTestPath(\Pinoox\Support\SystemConfig::path('pinker'));
}

function deletePinkerTestDirectory(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }

    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );

    foreach ($items as $item) {
        $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }

    rmdir($dir);
}

