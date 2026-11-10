<?php
/**
 * Peakopay — Database layer (PDO + prepared statements only).
 *
 * Every query goes through prepared statements. Convenience helpers keep
 * controllers thin while remaining safe: identifiers are validated against
 * [A-Za-z0-9_] and all values are bound parameters.
 */
declare(strict_types=1);

final class DB
{
    private static ?PDO $pdo = null;

    public static function pdo(): PDO
    {
        if (self::$pdo instanceof PDO) {
            return self::$pdo;
        }
        $c   = Config::get('db', []);
        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=%s',
            $c['host'] ?? 'localhost',
            $c['port'] ?? 3306,
            $c['name'] ?? '',
            $c['charset'] ?? 'utf8mb4'
        );
        try {
            self::$pdo = new PDO($dsn, (string) ($c['user'] ?? ''), (string) ($c['pass'] ?? ''), [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
        } catch (PDOException $e) {
            throw new RuntimeException('Database connection failed: ' . $e->getMessage(), 0, $e);
        }
        return self::$pdo;
    }

    public static function now(): string
    {
        return gmdate('Y-m-d H:i:s');
    }

    /** Prepare + execute. Returns the statement. */
    public static function q(string $sql, array $params = []): PDOStatement
    {
        $stmt = self::pdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    public static function one(string $sql, array $params = []): ?array
    {
        $row = self::q($sql, $params)->fetch();
        return $row === false ? null : $row;
    }

    public static function all(string $sql, array $params = []): array
    {
        return self::q($sql, $params)->fetchAll();
    }

    /** Single scalar value (first column of first row) or $default. */
    public static function val(string $sql, array $params = [], mixed $default = null): mixed
    {
        $v = self::q($sql, $params)->fetchColumn();
        return $v === false ? $default : $v;
    }

    public static function insert(string $table, array $data): int
    {
        $cols = self::cols(array_keys($data));
        $ph   = implode(',', array_fill(0, count($data), '?'));
        self::q("INSERT INTO `{$table}` (" . implode(',', $cols) . ") VALUES ({$ph})", array_values($data));
        return (int) self::pdo()->lastInsertId();
    }

    /** INSERT IGNORE — ردیف تکراری (کلید یکتا) بی‌صدا نادیده گرفته می‌شود. */
    public static function insertIgnore(string $table, array $data): int
    {
        $cols = self::cols(array_keys($data));
        $ph   = implode(',', array_fill(0, count($data), '?'));
        self::q("INSERT IGNORE INTO `{$table}` (" . implode(',', $cols) . ") VALUES ({$ph})", array_values($data));
        return (int) self::pdo()->lastInsertId();
    }

    public static function update(string $table, array $data, string $where, array $whereParams = []): int
    {
        if ($data === []) {
            return 0;
        }
        $sets = [];
        foreach (self::cols(array_keys($data)) as $c) {
            $sets[] = "{$c} = ?";
        }
        $sql = "UPDATE `{$table}` SET " . implode(', ', $sets) . " WHERE {$where}";
        return self::q($sql, array_merge(array_values($data), $whereParams))->rowCount();
    }

    /** Run a closure inside a transaction (retry once on deadlock). */
    public static function tx(callable $fn): mixed
    {
        $pdo = self::pdo();
        $tries = 0;
        while (true) {
            $tries++;
            $pdo->beginTransaction();
            try {
                $result = $fn();
                $pdo->commit();
                return $result;
            } catch (PDOException $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $isDeadlock = in_array($e->errorInfo[1] ?? null, [1213, 1205], true);
                if ($isDeadlock && $tries < 3) {
                    usleep(150000 * $tries);
                    continue;
                }
                throw $e;
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $e;
            }
        }
    }

    /** @return string[] validated, back-quoted column names */
    private static function cols(array $names): array
    {
        $out = [];
        foreach ($names as $n) {
            if (!preg_match('#^[A-Za-z0-9_]+$#', (string) $n)) {
                throw new RuntimeException("Illegal identifier: {$n}");
            }
            $out[] = "`{$n}`";
        }
        return $out;
    }
}
