<?php

/**
 *      ****  *  *     *  ****  ****  *    *
 *      *  *  *  * *   *  *  *  *  *   *  *
 *      ****  *  *  *  *  *  *  *  *    *
 *      *     *  *   * *  *  *  *  *   *  *
 *      *     *  *    **  ****  ****  *    *
 * @author   Pinoox
 * @link https://www.pinoox.com/
 * @license  https://opensource.org/licenses/MIT MIT License
 */

namespace Pinoox\Component\Migration;

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Builder;
use Illuminate\Contracts\Database\Query\Expression;
use Pinoox\Component\Database\Seeder\SeederRunner;
use Pinoox\Portal\Database\DB;
use Pinoox\Support\PackageContext;
use RuntimeException;

class MigrationBase extends Migration
{
    public Builder $schema;

    protected ?string $package = null;

    public static function usePackage(?string $package): void
    {
        PackageContext::use($package);
    }

    public function __construct(?string $package = null)
    {
        $this->bindPackage($package ?? PackageContext::resolve());
    }

    /**
     * Force schema + PackageContext onto the install target package.
     *
     * PinGate may run while App::package() is installer/manager; without an
     * explicit bind, hasTable/hasColumn can hit the wrong connection/prefix.
     */
    public function bindPackage(string $package): static
    {
        $this->package = PackageContext::resolve($package);
        PackageContext::use($this->package);
        $this->schema = DB::schema(DB::connectionNameForPackage($this->package));
        $this->schema->blueprintResolver(
            fn ($table, $callback, $prefix) => new MigrationBlueprint($table, $callback, $prefix),
        );

        return $this;
    }

    public function packageName(): ?string
    {
        return $this->package;
    }

    /**
     * Fail loudly when the logical table is missing (prefer over silent return).
     */
    protected function requireTable(string $logical): void
    {
        if ($this->schema->hasTable($logical)) {
            return;
        }

        throw new RuntimeException(sprintf(
            'Migration requires table [%s] for package [%s], but it does not exist.',
            $logical,
            $this->package ?? PackageContext::resolve(),
        ));
    }

    protected function table(string $name, ?string $package = null): string
    {
        return DB::tableName($name, $package ?? $this->package ?? PackageContext::resolve());
    }

    protected function foreignTable(string $name, ?string $package = null): Expression
    {
        return DB::raw(DB::physicalTableName($name, $package ?? $this->package ?? PackageContext::resolve()));
    }

    /**
     * Run seeders by file basename or SeederBase class (current package when $package is null).
     *
     * @param string|array<int, string> $name
     */
    protected function seed(string|array $name, ?string $package = null): void
    {
        (new SeederRunner())->run($name, $package ?? $this->package);
    }

    /**
     * Run all seeders for a package (current package when $package is null).
     */
    protected function seedAll(?string $package = null): void
    {
        (new SeederRunner())->runAll($package ?? $this->package);
    }
}
