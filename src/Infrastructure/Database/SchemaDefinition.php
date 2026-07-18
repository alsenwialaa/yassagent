<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Infrastructure\Database;

use InvalidArgumentException;
use YassinStore\AiAssistant\Support\Json;

/**
 * Single authoritative definition of every plugin-owned database table.
 *
 * There is no prior public schema, so this definition is exact: obsolete columns,
 * indexes, and historical aliases are not retained.
 */
final class SchemaDefinition
{
    /** @var string */ private $prefix;
    /** @var string */ private $charset;
    /** @var string */ private $collation;
    /** @var array<string,array<string,mixed>> */ private $tables;

    public function __construct(string $prefix, string $charset = 'utf8mb4', string $collation = '')
    {
        $this->assertIdentifierPrefix($prefix);
        $this->assertIdentifier($charset);
        if ($collation !== '') {
            $this->assertIdentifier($collation);
        }

        $this->prefix = $prefix;
        $this->charset = $charset;
        $this->collation = $collation;
        $this->tables = $this->buildTables();
    }

    public static function fromWordPress(): self
    {
        global $wpdb;
        $charset = isset($wpdb->charset) && is_string($wpdb->charset) && $wpdb->charset !== ''
            ? $wpdb->charset
            : 'utf8mb4';
        $collation = isset($wpdb->collate) && is_string($wpdb->collate)
            ? $wpdb->collate
            : '';
        return new self((string) $wpdb->prefix, $charset, $collation);
    }

    /** @return array<string,array<string,mixed>> */
    public function tables(): array
    {
        return $this->tables;
    }

    /** @return array<int,string> */
    public function tableNames(): array
    {
        $names = array();
        foreach ($this->tables as $table) {
            $names[] = (string) $table['name'];
        }
        return $names;
    }

    public function tableName(string $key): string
    {
        if (!isset($this->tables[$key])) {
            throw new InvalidArgumentException('Unknown schema table key.');
        }
        return (string) $this->tables[$key]['name'];
    }

    public function collation(): string
    {
        return $this->collation;
    }

    /** Exact namespace of all tables owned by this unpublished plugin. */
    public function ownedTablePrefix(): string
    {
        return $this->prefix . 'ysai_';
    }

    public function engine(): string
    {
        return 'InnoDB';
    }

    public function fingerprint(): string
    {
        return hash('sha256', Json::canonical(array(
            'tables' => $this->tables,
            'charset' => $this->charset,
            'collation' => $this->collation,
            'engine' => $this->engine(),
        )));
    }

    /** @return array<string,string> table name => CREATE TABLE statement */
    public function createStatements(): array
    {
        $statements = array();
        foreach ($this->tables as $table) {
            $name = (string) $table['name'];
            $lines = array();
            foreach ($table['columns'] as $columnName => $column) {
                $lines[] = '    ' . $columnName . ' ' . (string) $column['sql'];
            }
            foreach ($table['indexes'] as $indexName => $index) {
                $renderedColumns = array();
                foreach ($index['columns'] as $position => $columnName) {
                    $prefixLength = $index['prefixes'][$position] ?? null;
                    $renderedColumns[] = $columnName . ($prefixLength !== null ? '(' . (int) $prefixLength . ')' : '');
                }
                $columns = implode(',', $renderedColumns);
                if ($indexName === 'PRIMARY') {
                    $lines[] = '    PRIMARY KEY  (' . $columns . ')';
                } else {
                    $prefix = !empty($index['unique']) ? 'UNIQUE KEY ' : 'KEY ';
                    $lines[] = '    ' . $prefix . $indexName . ' (' . $columns . ')';
                }
            }

            $options = 'ENGINE=' . $this->engine() . ' DEFAULT CHARACTER SET ' . $this->charset;
            if ($this->collation !== '') {
                $options .= ' COLLATE ' . $this->collation;
            }
            $statements[$name] = "CREATE TABLE {$name} (\n" . implode(",\n", $lines) . "\n) {$options};";
        }
        return $statements;
    }

    public static function quoteIdentifier(string $identifier): string
    {
        if (!preg_match('/^[A-Za-z0-9_]+$/D', $identifier)) {
            throw new InvalidArgumentException('Unsafe SQL identifier.');
        }
        return '`' . $identifier . '`';
    }

    /** @return array<string,array<string,mixed>> */
    private function buildTables(): array
    {
        return array(
            'browser_continuity_authorities' => $this->table('ysai_browser_continuity_authorities', array(
                'id' => $this->column('bigint(20) unsigned NOT NULL AUTO_INCREMENT', 'bigint(20) unsigned', false, null, 'auto_increment'),
                'secret_hash' => $this->column('char(64) NOT NULL', 'char(64)', false),
                'session_nonce' => $this->column('binary(43) NOT NULL', 'binary(43)', false),
                'status' => $this->column('varchar(16) NOT NULL', 'varchar(16)', false),
                'rotated_to_hash' => $this->column('char(64) NULL', 'char(64)', true),
                'created_at' => $this->column('datetime NOT NULL', 'datetime', false),
                'updated_at' => $this->column('datetime NOT NULL', 'datetime', false),
                'expires_at' => $this->column('datetime NOT NULL', 'datetime', false),
            ), array(
                'PRIMARY' => $this->index(true, array('id')),
                'secret_hash' => $this->index(true, array('secret_hash')),
                'session_nonce' => $this->index(true, array('session_nonce')),
                'expires_at' => $this->index(false, array('expires_at')),
            )),
            'conversations' => $this->table('ysai_conversations', array(
                'id' => $this->column('bigint(20) unsigned NOT NULL AUTO_INCREMENT', 'bigint(20) unsigned', false, null, 'auto_increment'),
                'public_id' => $this->column('char(36) NOT NULL', 'char(36)', false),
                'access_hash' => $this->column('char(64) NOT NULL', 'char(64)', false),
                'session_hash' => $this->column('char(64) NOT NULL', 'char(64)', false),
                'state' => $this->column('longtext NULL', 'longtext', true),
                'created_at' => $this->column('datetime NOT NULL', 'datetime', false),
                'updated_at' => $this->column('datetime NOT NULL', 'datetime', false),
                'expires_at' => $this->column('datetime NOT NULL', 'datetime', false),
            ), array(
                'PRIMARY' => $this->index(true, array('id')),
                'public_id' => $this->index(true, array('public_id')),
                'session_hash' => $this->index(false, array('session_hash')),
                'expires_at' => $this->index(false, array('expires_at')),
            )),
            'messages' => $this->table('ysai_messages', array(
                'id' => $this->column('bigint(20) unsigned NOT NULL AUTO_INCREMENT', 'bigint(20) unsigned', false, null, 'auto_increment'),
                'public_id' => $this->column('char(36) NOT NULL', 'char(36)', false),
                'conversation_id' => $this->column('bigint(20) unsigned NOT NULL', 'bigint(20) unsigned', false),
                'turn_id' => $this->column("varchar(64) NOT NULL DEFAULT ''", 'varchar(64)', false, ''),
                'role' => $this->column('varchar(16) NOT NULL', 'varchar(16)', false),
                'outcome' => $this->column("varchar(32) NOT NULL DEFAULT ''", 'varchar(32)', false, ''),
                'content' => $this->column('longtext NOT NULL', 'longtext', false),
                'payload' => $this->column('longtext NULL', 'longtext', true),
                'created_at' => $this->column('datetime NOT NULL', 'datetime', false),
            ), array(
                'PRIMARY' => $this->index(true, array('id')),
                'conversation_turn_role' => $this->index(true, array('conversation_id', 'turn_id', 'role')),
                'public_id' => $this->index(true, array('public_id')),
                'conversation_id' => $this->index(false, array('conversation_id')),
                'created_at' => $this->index(false, array('created_at')),
            )),
            'turns' => $this->table('ysai_turns', array(
                'id' => $this->column('bigint(20) unsigned NOT NULL AUTO_INCREMENT', 'bigint(20) unsigned', false, null, 'auto_increment'),
                'conversation_id' => $this->column('bigint(20) unsigned NOT NULL', 'bigint(20) unsigned', false),
                'turn_id' => $this->column('varchar(64) NOT NULL', 'varchar(64)', false),
                'request_hash' => $this->column('char(64) NOT NULL', 'char(64)', false),
                'status' => $this->column('varchar(20) NOT NULL', 'varchar(20)', false),
                'lease_fence' => $this->column('bigint(20) unsigned NOT NULL DEFAULT 0', 'bigint(20) unsigned', false, '0'),
                'input_payload' => $this->column('longtext NOT NULL', 'longtext', false),
                'response_payload' => $this->column('longtext NULL', 'longtext', true),
                'failure_code' => $this->column("varchar(64) NOT NULL DEFAULT ''", 'varchar(64)', false, ''),
                'created_at' => $this->column('datetime NOT NULL', 'datetime', false),
                'updated_at' => $this->column('datetime NOT NULL', 'datetime', false),
                'completed_at' => $this->column('datetime NULL', 'datetime', true),
            ), array(
                'PRIMARY' => $this->index(true, array('id')),
                'conversation_turn' => $this->index(true, array('conversation_id', 'turn_id')),
                'status_updated' => $this->index(false, array('status', 'updated_at')),
                'created_at' => $this->index(false, array('created_at')),
            )),
            'operations' => $this->table('ysai_operations', array(
                'id' => $this->column('bigint(20) unsigned NOT NULL AUTO_INCREMENT', 'bigint(20) unsigned', false, null, 'auto_increment'),
                'public_id' => $this->column('char(36) NOT NULL', 'char(36)', false),
                'conversation_id' => $this->column('bigint(20) unsigned NOT NULL', 'bigint(20) unsigned', false),
                'turn_id' => $this->column('varchar(64) NOT NULL', 'varchar(64)', false),
                'operation_key' => $this->column('char(64) NOT NULL', 'char(64)', false),
                'lease_fence' => $this->column('bigint(20) unsigned NOT NULL', 'bigint(20) unsigned', false),
                'commerce_resource_hash' => $this->column('char(64) NOT NULL', 'char(64)', false),
                'commerce_fence' => $this->column('bigint(20) unsigned NOT NULL', 'bigint(20) unsigned', false),
                'status' => $this->column('varchar(20) NOT NULL', 'varchar(20)', false),
                'plan' => $this->column('longtext NOT NULL', 'longtext', false),
                'pre_state' => $this->column('longtext NOT NULL', 'longtext', false),
                'applied_effects' => $this->column('longtext NULL', 'longtext', true),
                'post_state' => $this->column('longtext NULL', 'longtext', true),
                'receipt' => $this->column('longtext NULL', 'longtext', true),
                'failure_code' => $this->column("varchar(64) NOT NULL DEFAULT ''", 'varchar(64)', false, ''),
                'safe_message' => $this->column('text NOT NULL', 'text', false),
                'created_at' => $this->column('datetime NOT NULL', 'datetime', false),
                'updated_at' => $this->column('datetime NOT NULL', 'datetime', false),
                'completed_at' => $this->column('datetime NULL', 'datetime', true),
            ), array(
                'PRIMARY' => $this->index(true, array('id')),
                'public_id' => $this->index(true, array('public_id')),
                'operation_key' => $this->index(true, array('operation_key')),
                'conversation_turn' => $this->index(true, array('conversation_id', 'turn_id')),
                'commerce_resource_hash' => $this->index(false, array('commerce_resource_hash')),
                'status_updated' => $this->index(false, array('status', 'updated_at')),
                'created_at' => $this->index(false, array('created_at')),
            )),
            'operation_steps' => $this->table('ysai_operation_steps', array(
                'id' => $this->column('bigint(20) unsigned NOT NULL AUTO_INCREMENT', 'bigint(20) unsigned', false, null, 'auto_increment'),
                'public_id' => $this->column('char(36) NOT NULL', 'char(36)', false),
                'operation_id' => $this->column('bigint(20) unsigned NOT NULL', 'bigint(20) unsigned', false),
                'step_index' => $this->column('bigint(20) unsigned NOT NULL', 'bigint(20) unsigned', false),
                'command_index' => $this->column('bigint(20) unsigned NOT NULL', 'bigint(20) unsigned', false),
                'command_hash' => $this->column('char(64) NOT NULL', 'char(64)', false),
                'conversation_fence' => $this->column('bigint(20) unsigned NOT NULL', 'bigint(20) unsigned', false),
                'commerce_resource_hash' => $this->column('char(64) NOT NULL', 'char(64)', false),
                'commerce_fence' => $this->column('bigint(20) unsigned NOT NULL', 'bigint(20) unsigned', false),
                'status' => $this->column('varchar(24) NOT NULL', 'varchar(24)', false),
                'primitive' => $this->column('longtext NOT NULL', 'longtext', false),
                'pre_state' => $this->column('longtext NOT NULL', 'longtext', false),
                'effect' => $this->column('longtext NULL', 'longtext', true),
                'post_state' => $this->column('longtext NULL', 'longtext', true),
                'marker_digest' => $this->column("char(64) NOT NULL DEFAULT ''", 'char(64)', false, ''),
                'failure_code' => $this->column("varchar(64) NOT NULL DEFAULT ''", 'varchar(64)', false, ''),
                'safe_message' => $this->column('text NOT NULL', 'text', false),
                'created_at' => $this->column('datetime NOT NULL', 'datetime', false),
                'updated_at' => $this->column('datetime NOT NULL', 'datetime', false),
                'completed_at' => $this->column('datetime NULL', 'datetime', true),
            ), array(
                'PRIMARY' => $this->index(true, array('id')),
                'public_id' => $this->index(true, array('public_id')),
                'operation_step' => $this->index(true, array('operation_id', 'step_index')),
                'operation_id' => $this->index(false, array('operation_id')),
                'operation_status' => $this->index(false, array('operation_id', 'status')),
                'status_updated' => $this->index(false, array('status', 'updated_at')),
            )),
            'operation_step_attempts' => $this->table('ysai_operation_step_attempts', array(
                'id' => $this->column('bigint(20) unsigned NOT NULL AUTO_INCREMENT', 'bigint(20) unsigned', false, null, 'auto_increment'),
                'public_id' => $this->column('char(36) NOT NULL', 'char(36)', false),
                'step_id' => $this->column('bigint(20) unsigned NOT NULL', 'bigint(20) unsigned', false),
                'attempt_number' => $this->column('bigint(20) unsigned NOT NULL', 'bigint(20) unsigned', false),
                'conversation_fence' => $this->column('bigint(20) unsigned NOT NULL', 'bigint(20) unsigned', false),
                'commerce_resource_hash' => $this->column('char(64) NOT NULL', 'char(64)', false),
                'commerce_fence' => $this->column('bigint(20) unsigned NOT NULL', 'bigint(20) unsigned', false),
                'status' => $this->column('varchar(24) NOT NULL', 'varchar(24)', false),
                'marker_digest' => $this->column("char(64) NOT NULL DEFAULT ''", 'char(64)', false, ''),
                'marker' => $this->column('longtext NULL', 'longtext', true),
                'candidate_effect' => $this->column('longtext NULL', 'longtext', true),
                'candidate_post_state' => $this->column('longtext NULL', 'longtext', true),
                'failure_code' => $this->column("varchar(64) NOT NULL DEFAULT ''", 'varchar(64)', false, ''),
                'safe_message' => $this->column('text NOT NULL', 'text', false),
                'created_at' => $this->column('datetime NOT NULL', 'datetime', false),
                'updated_at' => $this->column('datetime NOT NULL', 'datetime', false),
                'completed_at' => $this->column('datetime NULL', 'datetime', true),
            ), array(
                'PRIMARY' => $this->index(true, array('id')),
                'public_id' => $this->index(true, array('public_id')),
                'step_attempt' => $this->index(true, array('step_id', 'attempt_number')),
                'step_status' => $this->index(false, array('step_id', 'status')),
                'status_updated' => $this->index(false, array('status', 'updated_at')),
            )),
            'leases' => $this->table('ysai_leases', array(
                'resource_hash' => $this->column('char(64) NOT NULL', 'char(64)', false),
                'resource' => $this->column('varchar(191) NOT NULL', 'varchar(191)', false),
                'owner' => $this->column('char(32) NOT NULL', 'char(32)', false),
                'fence' => $this->column('bigint(20) unsigned NOT NULL', 'bigint(20) unsigned', false),
                'lease_until' => $this->column('datetime NOT NULL', 'datetime', false),
                'updated_at' => $this->column('datetime NOT NULL', 'datetime', false),
            ), array(
                'PRIMARY' => $this->index(true, array('resource_hash')),
                'lease_until' => $this->index(false, array('lease_until')),
            )),
            'rate_limits' => $this->table('ysai_rate_limits', array(
                'bucket_hash' => $this->column('char(64) NOT NULL', 'char(64)', false),
                'request_token' => $this->column('char(32) NOT NULL', 'char(32)', false),
                'request_count' => $this->column('bigint(20) unsigned NOT NULL', 'bigint(20) unsigned', false),
                'reset_at' => $this->column('datetime NOT NULL', 'datetime', false),
                'updated_at' => $this->column('datetime NOT NULL', 'datetime', false),
            ), array(
                'PRIMARY' => $this->index(true, array('bucket_hash')),
                'reset_at' => $this->index(false, array('reset_at')),
            )),
        );
    }

    /** @param array<string,array<string,mixed>> $columns @param array<string,array<string,mixed>> $indexes @return array<string,mixed> */
    private function table(string $suffix, array $columns, array $indexes): array
    {
        return array(
            'name' => $this->prefix . $suffix,
            'columns' => $columns,
            'indexes' => $indexes,
        );
    }

    /** @return array<string,mixed> */
    private function column(string $sql, string $type, bool $nullable, ?string $default = null, string $extra = ''): array
    {
        $textual = preg_match('/(?:char|text)$/i', preg_replace('/\(.*/', '', $type) ?? $type) === 1;
        return array(
            'sql' => $sql,
            'type' => $type,
            'nullable' => $nullable,
            'default' => $default,
            'extra' => $extra,
            'charset' => $textual ? $this->charset : null,
            'collation' => $textual && $this->collation !== '' ? $this->collation : null,
        );
    }

    /** @param array<int,string> $columns @return array<string,mixed> */
    private function index(bool $unique, array $columns): array
    {
        return array(
            'unique' => $unique,
            'type' => 'BTREE',
            'columns' => $columns,
            'prefixes' => array_fill(0, count($columns), null),
        );
    }

    private function assertIdentifier(string $value): void
    {
        if ($value === '' || !preg_match('/^[A-Za-z0-9_]+$/D', $value)) {
            throw new InvalidArgumentException('Invalid database identifier component.');
        }
    }

    private function assertIdentifierPrefix(string $value): void
    {
        if ($value !== '' && !preg_match('/^[A-Za-z0-9_]+$/D', $value)) {
            throw new InvalidArgumentException('Invalid database table prefix.');
        }
    }
}
