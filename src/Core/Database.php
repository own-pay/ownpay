<?php
declare(strict_types=1);

namespace OwnPay\Core;

use OwnPay\Event\EventManager;
use OwnPay\Plugin\PluginRegistry;
use PDO;
use PDOStatement;

/**
 * Class Database
 *
 * Thin database wrapper around PDO.
 * Provides convenience methods for common queries while maintaining
 * full prepared-statement safety. No raw string interpolation ever.
 * Injected via DI container - never instantiate directly.
 *
 * @package OwnPay\Core
 */
class Database
{
    /**
     * @var PDO The underlying PDO instance.
     */
    private PDO $pdo;

    /**
     * Configured application table prefix.
     */
    private string $tablePrefix = 'op_';

    /**
     * @var self|null The singleton instance used for testing/fallback context.
     */
    private static ?self $instance = null;

    /**
     * @var EventManager|null Optional EventManager for query hooks.
     */
    private ?EventManager $events = null;

    /**
     * @var PluginRegistry|null Optional PluginRegistry for sandbox checks.
     */
    private ?PluginRegistry $registry = null;

    /**
     * @var bool Guard against infinite recursion when hooks themselves query the database.
     */
    private bool $firingHooks = false;

    /**
     * Database constructor.
     *
     * @param PDO $pdo The underlying PDO connection.
     * @param string $tablePrefix The configured application table prefix.
     */
    public function __construct(PDO $pdo, string $tablePrefix = 'op_')
    {
        $this->pdo = $pdo;
        if (!preg_match('/^[a-z0-9_]{1,30}$/i', $tablePrefix)) {
            throw new \InvalidArgumentException('Invalid database table prefix.');
        }
        $this->tablePrefix = $tablePrefix;
    }

    /**
     * Resets the singleton instance (used for testing).
     *
     * @return void
     */
    public static function reset(): void
    {
        self::$instance = null;
    }

    /**
     * Manually registers the singleton instance (used in container factory).
     *
     * @param self $instance The active database instance.
     * @return void
     */
    public static function setInstance(self $instance): void
    {
        self::$instance = $instance;
    }

    /**
     * Injects the EventManager after container build.
     * Called by Kernel::boot() once DI is ready.
     *
     * @param EventManager $events The event manager.
     * @return void
     */
    public function setEventManager(EventManager $events): void
    {
        $this->events = $events;
    }

    /**
     * Injects the PluginRegistry after container build.
     * Called by Kernel::boot() once DI is ready.
     *
     * @param PluginRegistry $registry The plugin registry.
     * @return void
     */
    public function setPluginRegistry(PluginRegistry $registry): void
    {
        $this->registry = $registry;
    }

    /**
     * Creates and stores a singleton instance from connection parameters.
     * Used by integration tests that cannot access the DI container.
     *
     * The default PDO option set only set errmode/fetch-mode/emulate-prepares;
     * it omitted any connection timeout, any TLS configuration, and any
     * persistent-connection toggle. This made remote-DB deployments either
     * hang for the full MySQL default 10s connect_timeout during bootstrap
     * (no application-level override) or traverse the wire in cleartext.
     *
     * The caller may now pass:
     *   - $options[PDO::ATTR_TIMEOUT] (default 5) - connect timeout in seconds.
     *   - $options[PDO::ATTR_PERSISTENT] (default false) - set true for
     *     long-running CLI/cron workers to reuse pooled connections.
     *   - $sslCa (path to a PEM-encoded CA bundle) - when non-null, TLS is
     *     enabled with server-cert verification pinned to the supplied CA.
     *
     * @param string                                                $host    The database host.
     * @param string                                                $name    The database name.
     * @param string                                                $user    The database username.
     * @param string                                                $pass    The database password.
     * @param int                                                   $port    The database port.
     * @param array<int, int|bool|string>                           $options PDO driver options (merged over defaults).
     * @param string|null                                           $sslCa   Optional path to a CA bundle for TLS verification.
     * @return self The initialized Database instance.
     */
    public static function init(
        string $host,
        string $name,
        string $user,
        string $pass,
        int $port = 3306,
        array $options = [],
        ?string $sslCa = null
    ): self {
        $dsn = "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4";

        // Sensible defaults that the caller may override via $options.
        $defaults = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            // Cap connect_timeout at 5s so a hung/unreachable DB fails fast
            // during bootstrap instead of stalling the full request.
            PDO::ATTR_TIMEOUT            => 5,
        ];

        if ($sslCa !== null && $sslCa !== '') {
            // Enable TLS with server-cert verification pinned to the supplied
            // CA bundle so remote-DB credentials cannot be sniffed/MITM'd.
            $defaults[PDO::MYSQL_ATTR_SSL_CA] = $sslCa;
            if (defined('PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT')) {
                $defaults[\constant('PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT')] = true;
            }
        }

        // Caller-provided options override the defaults.
        $merged = $defaults;
        foreach ($options as $key => $value) {
            $merged[$key] = $value;
        }

        $pdo = new PDO($dsn, $user, $pass, $merged);
        $prefix = is_string($_ENV['DB_PREFIX'] ?? null) && $_ENV['DB_PREFIX'] !== ''
            ? $_ENV['DB_PREFIX']
            : (getenv('DB_PREFIX') ?: 'op_');
        self::$instance = new self($pdo, $prefix);
        return self::$instance;
    }

    /**
     * Retrieves the singleton set by init().
     *
     * @throws \RuntimeException If init() was never called.
     * @return self The active Database instance.
     */
    public static function getInstance(): self
    {
        if (self::$instance === null) {
            throw new \RuntimeException('Database::init() must be called before getInstance().');
        }
        return self::$instance;
    }

    /**
     * Returns the underlying PDO connection.
     *
     * @return PDO The PDO connection object.
     */
    public function pdo(): PDO
    {
        return $this->pdo;
    }

    /**
     * Fetches all matching rows for a query.
     *
     * @param string $sql    The SQL query.
     * @param array<string|int, mixed>  $params The parameters to bind.
     * @return array<int, array<string, mixed>> The array of query results.
     */
    public function fetchAll(string $sql, array $params = []): array
    {
        $stmt = $this->execute($sql, $params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Fetches a single row.
     *
     * @param string $sql    The SQL query.
     * @param array<string|int, mixed>  $params The parameters to bind.
     * @return array<string, mixed>|null The result row or null if not found.
     */
    public function fetchOne(string $sql, array $params = []): ?array
    {
        $stmt = $this->execute($sql, $params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (is_array($row)) {
            $mapped = [];
            foreach ($row as $k => $v) {
                $mapped[(string)$k] = $v;
            }
            return $mapped;
        }
        return null;
    }

    /**
     * Fetches a single column value.
     *
     * @param string $sql    The SQL query.
     * @param array<string|int, mixed>  $params The parameters to bind.
     * @param int    $column The 0-indexed column offset to fetch.
     * @return mixed The column value or null if not found.
     */
    public function fetchColumn(string $sql, array $params = [], int $column = 0): mixed
    {
        $stmt = $this->execute($sql, $params);
        $value = $stmt->fetchColumn($column);
        return $value !== false ? $value : null;
    }

    /**
     * Prepares and executes an SQL statement with parameter binding.
     *
     * @param string $sql    The SQL query.
     * @param array<string|int, mixed>  $params The parameters to bind.
     * @throws \RuntimeException If the query is blocked by sandbox policy.
     * @return PDOStatement The executed PDO statement.
     */
    public function execute(string $sql, array $params = []): PDOStatement
    {
        $canonicalSql = $sql;
        // DB-1: The db.query.before filter previously fired on EVERY query,
        // including core-originated queries against sensitive tables
        // (op_api_keys, op_users, op_merchant_users, op_password_resets,
        // op_audit_log, op_sessions). A malicious plugin could register a
        // filter that rewrote `SELECT api_key FROM op_api_keys WHERE id=:id`
        // into `SELECT api_key FROM op_api_keys WHERE 1=1` and exfiltrate
        // every API key, or rewrite `UPDATE op_transactions SET status=
        // 'completed' WHERE id=:id AND merchant_id=:mid` to drop the tenant
        // guard. The sandbox check on line 238-247 only ran when the active
        // owner was a plugin - for core-originated queries, no sandbox
        // validation happened at all, and the rewritten SQL was executed
        // verbatim.
        //
        // We now refuse to fire the db.query.before filter for queries that
        // touch any table in a hardcoded PROTECTED_TABLES list, regardless
        // of the active owner. Plugins can still observe/profiling queries
        // against non-protected tables, but cannot rewrite queries that
        // touch credentials, sessions, audit logs, or user accounts.
        $protectedTables = [
            'op_api_keys',
            'op_users',
            'op_merchant_users',
            'op_password_resets',
            'op_audit_log',
            'op_sessions',
            'op_password_resets',
            'op_totp_secrets',
        ];
        $sqlLower = strtolower($canonicalSql);
        $isProtected = false;
        foreach ($protectedTables as $table) {
            if (str_contains($sqlLower, $table)) {
                $isProtected = true;
                break;
            }
        }

        // Validate plugin SQL before applying a custom prefix; otherwise a
        // plugin could evade the canonical core-table restrictions.
        if ($this->registry !== null && $this->events !== null) {
            $activeOwner = $this->events->getActiveOwner();
            if ($activeOwner !== 'core') {
                $sandbox = $this->registry->getSandbox($activeOwner);
                if ($sandbox !== null && !$sandbox->validateSql($canonicalSql)) {
                    throw new \RuntimeException(
                        "Database query blocked by plugin sandbox for '{$activeOwner}': direct access to core tables or dangerous SQL operations are restricted."
                    );
                }
            }
        }

        $sql = $this->applyTablePrefix($sql);

        // Fire db.query.before filter - plugins can modify SQL/params.
        // Guard prevents infinite recursion when hook listeners query DB.
        // DB-1: skip the filter entirely for queries against protected tables.
        if (!$isProtected && $this->events !== null && !$this->firingHooks) {
            $this->firingHooks = true;
            try {
                /** @var array<string, mixed> $queryData */
                $queryData = $this->events->applyFilter('db.query.before', [
                    'sql' => $sql,
                    'params' => $params,
                ]);
                if (isset($queryData['sql']) && is_string($queryData['sql'])) {
                    $sql = $queryData['sql'];
                }
                if (isset($queryData['params']) && is_array($queryData['params'])) {
                    $params = $queryData['params'];
                }
            } finally {
                $this->firingHooks = false;
            }
        }

        $start = hrtime(true);
        $stmt = $this->pdo->prepare($sql);

        // With ATTR_EMULATE_PREPARES=false, PDO requires
        // LIMIT/OFFSET bound as PDO::PARAM_INT. Using $stmt->execute()
        // binds everything as PDO::PARAM_STR, causing MySQL errors.
        foreach ($params as $key => $value) {
            $paramKey = is_int($key) ? $key + 1 : ":{$key}";
            if (is_int($value)) {
                $stmt->bindValue($paramKey, $value, PDO::PARAM_INT);
            } elseif ($value === null) {
                $stmt->bindValue($paramKey, null, PDO::PARAM_NULL);
            } elseif (is_bool($value)) {
                $stmt->bindValue($paramKey, $value, PDO::PARAM_BOOL);
            } else {
                $stmt->bindValue($paramKey, $value, PDO::PARAM_STR);
            }
        }
        $stmt->execute();
        $durationMs = (hrtime(true) - $start) / 1_000_000;

        // Fire db.query.after action - profiling, audit logging
        if ($this->events !== null && !$this->firingHooks) {
            $this->firingHooks = true;
            try {
                $this->events->doAction('db.query.after', $sql, $params, $durationMs);
            } finally {
                $this->firingHooks = false;
            }
        }

        return $stmt;
    }

    /**
     * Rewrites core table identifiers from the canonical op_ prefix to the
     * configured prefix. SQL values and comments are intentionally untouched.
     *
     * @param string $sql SQL statement containing canonical table identifiers.
     * @return string SQL statement using the configured table prefix.
     */
    private function applyTablePrefix(string $sql): string
    {
        if ($this->tablePrefix === 'op_') {
            return $sql;
        }

        $rewritten = preg_replace_callback(
            '/(?P<q>`)(?P<name>op_[a-z_][a-z0-9_]*)\k<q>'
            . '|(?P<pre>[\s(])(?P<bare>op_[a-z_][a-z0-9_]*)/i',
            function (array $match): string {
                if ($match['q'] !== '') {
                    $name = $match['name'];
                    return $match['q'] . $this->tablePrefix . substr($name, 3) . $match['q'];
                }
                return $match['pre'] . $this->tablePrefix . substr($match['bare'], 3);
            },
            $sql
        );

        return is_string($rewritten) ? $rewritten : $sql;
    }

    /**
     * Executes an INSERT query and returns the last insert ID.
     *
     * @param string $sql    The SQL INSERT statement.
     * @param array<string|int, mixed>  $params The parameters to bind.
     * @return string The auto-incremented last insert ID.
     */
    public function insert(string $sql, array $params = []): string
    {
        $this->execute($sql, $params);
        $id = $this->pdo->lastInsertId();
        return is_string($id) ? $id : '0';
    }

    /**
     * Executes an UPDATE query and returns the number of affected rows.
     *
     * @param string $sql    The SQL UPDATE statement.
     * @param array<string|int, mixed>  $params The parameters to bind.
     * @return int The count of affected rows.
     */
    public function update(string $sql, array $params = []): int
    {
        return $this->execute($sql, $params)->rowCount();
    }

    /**
     * Executes a DELETE query and returns the number of deleted rows.
     *
     * @param string $sql    The SQL DELETE statement.
     * @param array<string|int, mixed>  $params The parameters to bind.
     * @return int The count of deleted rows.
     */
    public function delete(string $sql, array $params = []): int
    {
        return $this->execute($sql, $params)->rowCount();
    }

    /**
     * Wraps execution in a transaction context.
     *
     * @template T
     * @param callable(): T $callback The transaction operations.
     * @throws \Throwable If a statement fails, transaction is rolled back.
     * @return T The callback result.
     */
    public function transaction(callable $callback): mixed
    {
        $hasActive = $this->pdo->inTransaction();
        if (!$hasActive) {
            $this->pdo->beginTransaction();
        }
        try {
            $result = $callback();
            if (!$hasActive) {
                $this->pdo->commit();
            }
            return $result;
        } catch (\Throwable $e) {
            if (!$hasActive) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Begins an SQL transaction.
     *
     * @return void
     */
    public function beginTransaction(): void
    {
        $this->pdo->beginTransaction();
    }

    /**
     * Commits the active transaction.
     *
     * @return void
     */
    public function commit(): void
    {
        $this->pdo->commit();
    }

    /**
     * Rolls back the active transaction.
     *
     * @return void
     */
    public function rollBack(): void
    {
        $this->pdo->rollBack();
    }

    /**
     * Checks if transaction is active.
     *
     * @return bool True if currently in transaction, false otherwise.
     */
    public function inTransaction(): bool
    {
        return $this->pdo->inTransaction();
    }

    /**
     * Checks if a row exists in the database.
     *
     * SECURITY: The $where argument is concatenated directly into the SQL
     * string. Callers MUST NOT interpolate user input into $where - only
     * literal SQL fragments with :named placeholders bound via $params are
     * permitted. A runtime assertion rejects obvious SQL-injection markers
     * (statement separator, comment markers, NUL/control bytes) as a
     * defence-in-depth guardrail against future callers that take a shortcut.
     *
     * @param string $table  The table name.
     * @param string $where  The SQL WHERE clause. Use :named placeholders only; never interpolate user input.
     * @param array<string|int, mixed>  $params The parameters to bind.
     * @throws \InvalidArgumentException If table name or WHERE clause contains forbidden characters.
     * @return bool True if row exists, false otherwise.
     */
    public function exists(string $table, string $where, array $params = []): bool
    {
        // Validate table name to prevent SQL injection via caller
        if (!preg_match('/^[a-zA-Z0-9_`]+$/', $table)) {
            throw new \InvalidArgumentException('Invalid table name: ' . $table);
        }
        self::assertSafeWhereClause($where);
        $sql = "SELECT 1 FROM {$table} WHERE {$where} LIMIT 1";
        return $this->fetchColumn($sql, $params) !== null;
    }

    /**
     * Counts rows matching selection parameters.
     *
     * SECURITY: The $where argument is concatenated directly into the SQL
     * string. Callers MUST NOT interpolate user input into $where - only
     * literal SQL fragments with :named placeholders bound via $params are
     * permitted. A runtime assertion rejects obvious SQL-injection markers
     * (statement separator, comment markers, NUL/control bytes) as a
     * defence-in-depth guardrail against future callers that take a shortcut.
     *
     * @param string $table  The table name.
     * @param string $where  The SQL WHERE clause. Use :named placeholders only; never interpolate user input.
     * @param array<string|int, mixed>  $params The parameters to bind.
     * @throws \InvalidArgumentException If table name or WHERE clause contains forbidden characters.
     * @return int The row count.
     */
    public function count(string $table, string $where = '1=1', array $params = []): int
    {
        // Validate table name to prevent SQL injection via caller
        if (!preg_match('/^[a-zA-Z0-9_`]+$/', $table)) {
            throw new \InvalidArgumentException('Invalid table name: ' . $table);
        }
        self::assertSafeWhereClause($where);
        $sql = "SELECT COUNT(*) FROM {$table} WHERE {$where}";
        $val = $this->fetchColumn($sql, $params);
        return is_scalar($val) ? (int) $val : 0;
    }

    /**
     * Defence-in-depth guard for the $where clause accepted by exists()/count().
     *
     * Rejects obvious SQL-injection markers (the semicolon statement
     * separator, the double-dash and slash-star/star-slash comment markers,
     * and NUL/control bytes other than the whitespace chars that legitimately
     * appear in WHERE clauses). Legitimate WHERE fragments built with :named
     * placeholders and quoted SQL literals (e.g. status IN ('open',
     * 'under_review')) pass through unchanged.
     *
     * This is NOT a complete SQL-injection defence - the only safe pattern is
     * to bind all user-derived values via $params. The guard exists solely to
     * turn an accidental caller shortcut into a loud failure rather than a
     * silent exploit.
     *
     * @param string $where The WHERE clause to validate.
     * @throws \InvalidArgumentException If the WHERE clause contains forbidden tokens.
     * @return void
     */
    private static function assertSafeWhereClause(string $where): void
    {
        if ($where === '') {
            return;
        }
        // Reject statement separators and SQL comment markers - no legitimate
        // WHERE clause needs them.
        if (
            str_contains($where, ';')
            || str_contains($where, '--')
            || str_contains($where, '/*')
            || str_contains($where, '*/')
        ) {
            throw new \InvalidArgumentException(
                'Refusing to execute WHERE clause containing SQL statement/comment markers; '
                . 'use :named placeholders for all user-supplied values.'
            );
        }
        // Reject NUL and other C0 control bytes except tab/newline/CR (which
        // are whitespace and may legitimately appear in multi-line clauses).
        for ($i = 0, $len = strlen($where); $i < $len; $i++) {
            $ord = ord($where[$i]);
            if ($ord < 0x20 && $ord !== 0x09 && $ord !== 0x0A && $ord !== 0x0D) {
                throw new \InvalidArgumentException(
                    'Refusing to execute WHERE clause containing control bytes; '
                    . 'use :named placeholders for all user-supplied values.'
                );
            }
        }
    }

    /**
     * Returns the last auto-incremented ID.
     *
     * @return string The last insert ID.
     */
    public function lastInsertId(): string
    {
        $id = $this->pdo->lastInsertId();
        return is_string($id) ? $id : '0';
    }
}
