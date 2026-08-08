<?php

use Pinoox\Component\Migration\MigrationName;

it('strips timestamp prefixes', function () {
    expect(MigrationName::stripTimestamp('2026_08_04_000002_add_chat_fields_to_task_comments_table'))
        ->toBe('add_chat_fields_to_task_comments_table');
});

it('detects create migrations', function () {
    expect(MigrationName::isCreate('2026_08_02_000008_create_task_comments_table'))->toBeTrue()
        ->and(MigrationName::isCreate('2026_08_04_000002_add_chat_fields_to_task_comments_table'))->toBeFalse();
});

it('extracts logical table names from common patterns', function () {
    expect(MigrationName::tableName('2026_08_02_000008_create_task_comments_table'))->toBe('task_comments')
        ->and(MigrationName::tableName('2026_08_04_000002_add_chat_fields_to_task_comments_table'))->toBe('task_comments')
        ->and(MigrationName::tableName('2026_08_04_000003_add_description_to_task_attachments_table'))->toBe('task_attachments')
        ->and(MigrationName::tableName('2026_08_07_000004_ensure_chat_fields_on_task_comments'))->toBe('task_comments')
        ->and(MigrationName::tableName('2026_08_08_000001_create_custom_fields_tables'))->toBe('custom_fields')
        ->and(MigrationName::tableName('2026_08_03_000012_unique_label_name_per_project'))->toBeNull();
});

it('marks add/alter/ensure as requiring an existing table', function () {
    expect(MigrationName::requiresExistingTable('2026_08_04_000002_add_chat_fields_to_task_comments_table'))->toBeTrue()
        ->and(MigrationName::requiresExistingTable('2026_08_07_000004_ensure_chat_fields_on_task_comments'))->toBeTrue()
        ->and(MigrationName::requiresExistingTable('2026_08_02_000008_create_task_comments_table'))->toBeFalse()
        ->and(MigrationName::requiresExistingTable('2026_01_01_000000_drop_legacy_table'))->toBeFalse();
});

it('parses unambiguous add_* column names and ignores semantic groups', function () {
    expect(MigrationName::addedColumns('2026_08_04_000003_add_description_to_task_attachments_table'))
        ->toBe(['description'])
        ->and(MigrationName::addedColumns('2026_08_04_000002_add_file_id_to_task_comments_table'))
        ->toBe(['file_id'])
        ->and(MigrationName::addedColumns('2026_08_04_000002_add_file_id_and_voice_url_to_task_comments_table'))
        ->toBe(['file_id', 'voice_url'])
        ->and(MigrationName::addedColumns('2026_08_04_000002_add_chat_fields_to_task_comments_table'))
        ->toBeNull();
});
