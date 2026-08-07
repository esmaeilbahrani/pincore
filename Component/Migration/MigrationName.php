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

/**
 * Parse migration filenames into table / column intents.
 *
 * Used by Migrator to decide skip/verify behavior without executing PHP.
 */
final class MigrationName
{
    private const TIMESTAMP_PATTERN = '/^\d{4}_\d{2}_\d{2}_\d{6}_/';

    /** @var array<string, string> */
    private const TABLE_NAME_PATTERNS = [
        'create_table' => '/^create_(.+)_table$/',
        'drop_table' => '/^drop_(.+)_table$/',
        'alter_table' => '/^alter_(.+)_table$/',
        'add_to' => '/^add_.+_to_(.+)$/',
        'drop_from' => '/^drop_.+_from_(.+)$/',
        'remove_from' => '/^remove_.+_from_(.+)$/',
        'modify_in' => '/^modify_.+_in_(.+)$/',
        'update_in' => '/^update_.+_in_(.+)$/',
        'rename_in' => '/^rename_.+_in_(.+)$/',
        'ensure_on' => '/^ensure_.+_on_(.+)$/',
    ];

    public static function stripTimestamp(string $fileName): string
    {
        return (string) preg_replace(self::TIMESTAMP_PATTERN, '', $fileName);
    }

    public static function isCreate(string $fileName): bool
    {
        return str_starts_with(self::stripTimestamp($fileName), 'create_');
    }

    /**
     * Mutations that expect the target table to already exist.
     */
    public static function requiresExistingTable(string $fileName): bool
    {
        $clean = self::stripTimestamp($fileName);

        foreach (['add_', 'alter_', 'drop_', 'remove_', 'modify_', 'update_', 'rename_', 'ensure_'] as $prefix) {
            if (str_starts_with($clean, $prefix)) {
                // drop_*_table creates absence — handled separately
                if (str_starts_with($clean, 'drop_') && str_ends_with($clean, '_table') && !str_contains($clean, '_from_')) {
                    return false;
                }

                return true;
            }
        }

        return false;
    }

    public static function tableName(string $fileName): ?string
    {
        $clean = self::stripTimestamp($fileName);

        foreach (self::TABLE_NAME_PATTERNS as $pattern) {
            if (preg_match($pattern, $clean, $matches) !== 1) {
                continue;
            }

            $name = $matches[1];
            if (str_ends_with($name, '_table')) {
                $name = substr($name, 0, -6);
            }

            return $name !== '' ? $name : null;
        }

        return null;
    }

    /**
     * Physical columns implied by add_*_to_* names, when unambiguous.
     *
     * @return list<string>|null null when the name is semantic (e.g. add_chat_fields_to_*)
     */
    public static function addedColumns(string $fileName): ?array
    {
        $clean = self::stripTimestamp($fileName);
        if (preg_match('/^add_(.+)_to_.+$/', $clean, $matches) !== 1) {
            return null;
        }

        $part = $matches[1];
        if (preg_match('/_(fields|columns|indexes|keys|constraints)$/', $part) === 1) {
            return null;
        }

        if (str_contains($part, '_and_')) {
            return array_values(array_filter(
                array_map('trim', explode('_and_', $part)),
                static fn (string $col): bool => $col !== '',
            ));
        }

        return [$part];
    }
}
