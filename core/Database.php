<?php
declare(strict_types=1);

namespace LightPress\Core;

use PDO;
use PDOException;
use RuntimeException;

/**
 * Camada unificada de conexão e manipulação segura de banco de dados via PDO.
 * Suporta MariaDB/MySQL e SQLite com prepared statements nativos e transações atômicas.
 */
class Database
{
    private static ?PDO $instance = null;
    private static array $config = [];

    /**
     * @var list<callable(): void> Callbacks a serem executados exclusivamente após o COMMIT bem-sucedido da transação raiz.
     */
    private static array $afterCommitCallbacks = [];

    /**
     * Inicializa a configuração do banco.
     */
    public static function init(array $config): void
    {
        self::$config = $config;
        self::$instance = null; // Reinicia a instância se reconfigurado
        self::$afterCommitCallbacks = [];
    }

    /**
     * Obtém a instância singleton do PDO com configurações estritas de segurança.
     */
    public static function getConnection(): PDO
    {
        if (self::$instance !== null) {
            return self::$instance;
        }

        if (empty(self::$config)) {
            if (!empty($GLOBALS['config']['database']) && is_array($GLOBALS['config']['database'])) {
                self::$config = $GLOBALS['config']['database'];
            } else {
                $globalConfig = file_exists(dirname(__DIR__) . '/config.php') ? require dirname(__DIR__) . '/config.php' : [];
                self::$config = $globalConfig['database'] ?? [];
            }
        }

        $driver = self::$config['driver'] ?? 'mysql';

        try {
            if ($driver === 'sqlite') {
                $path = self::$config['sqlite_path'] ?? dirname(__DIR__) . '/storage/database.sqlite';
                $dir = dirname($path);
                if (!is_dir($dir)) {
                    @mkdir($dir, 0755, true);
                }
                $dsn = "sqlite:{$path}";
                $pdo = new PDO($dsn, null, null, [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                ]);
                // Habilitar foreign keys e WAL mode no SQLite para alta concorrência
                $pdo->exec('PRAGMA foreign_keys = ON;');
                $pdo->exec('PRAGMA journal_mode = WAL;');
            } else {
                // MariaDB / MySQL
                $host = self::$config['host'] ?? '127.0.0.1';
                $port = self::$config['port'] ?? 3306;
                $dbname = self::$config['dbname'] ?? 'lightpress';
                $charset = self::$config['charset'] ?? 'utf8mb4';

                $dsn = "mysql:host={$host};port={$port};dbname={$dbname};charset={$charset}";
                $username = self::$config['username'] ?? 'root';
                $password = self::$config['password'] ?? '';

                $mysqlInitCommandAttr = defined('Pdo\\Mysql::ATTR_INIT_COMMAND')
                    ? \Pdo\Mysql::ATTR_INIT_COMMAND
                    : 1002;
                $options = [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                    $mysqlInitCommandAttr => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci, time_zone = '+00:00'",
                ];

                if (!empty(self::$config['options']) && is_array(self::$config['options'])) {
                    $options = array_replace($options, self::$config['options']);
                }

                $pdo = new PDO($dsn, $username, $password, $options);
            }

            self::$instance = $pdo;
            return self::$instance;

        } catch (PDOException $e) {
            // Nunca vazar credenciais ou mensagens detalhadas de conexão em exceções não tratadas
            error_log("[LightPress Database Error] " . $e->getMessage());
            throw new RuntimeException("Falha na conexão com o banco de dados. Verifique os logs do servidor.", 500, $e);
        }
    }

    /**
     * Registra um callback para ser executado exclusivamente após o commit da transação ativa.
     * Se não houver transação em andamento, executa imediatamente.
     *
     * @param callable(): void $callback
     */
    public static function afterCommit(callable $callback): void
    {
        if (!self::getConnection()->inTransaction()) {
            $callback();
            return;
        }

        self::$afterCommitCallbacks[] = $callback;
    }

    /**
     * Executa uma função em transação atômica. Em caso de exceção, realiza rollback automático.
     *
     * @template T
     * @param callable(PDO): T $callback
     * @return T
     * @throws \Throwable
     */
    public static function transaction(callable $callback): mixed
    {
        $pdo = self::getConnection();

        // Se já estiver em uma transação ativa, apenas executa o callback
        if ($pdo->inTransaction()) {
            return $callback($pdo);
        }

        $pdo->beginTransaction();
        try {
            $result = $callback($pdo);
            $pdo->commit();

            // Executa callbacks pós-commit registrados durante a transação raiz
            $callbacks = self::$afterCommitCallbacks;
            self::$afterCommitCallbacks = [];
            foreach ($callbacks as $cb) {
                try {
                    $cb();
                } catch (\Throwable $cbErr) {
                    error_log('[Database::afterCommit] Falha ao executar callback pós-commit: ' . $cbErr->getMessage());
                }
            }

            return $result;
        } catch (\Throwable $e) {
            self::$afterCommitCallbacks = [];
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Retorna o driver configurado (mysql ou sqlite).
     */
    public static function getDriver(): string
    {
        if (empty(self::$config)) {
            if (!empty($GLOBALS['config']['database']) && is_array($GLOBALS['config']['database'])) {
                self::$config = $GLOBALS['config']['database'];
            } else {
                $globalConfig = file_exists(dirname(__DIR__) . '/config.php') ? require dirname(__DIR__) . '/config.php' : [];
                self::$config = $globalConfig['database'] ?? [];
            }
        }
        return self::$config['driver'] ?? 'mysql';
    }

    /**
     * Executa uma consulta preparada com parâmetros vinculados tipados.
     */
    public static function query(string $sql, array $params = []): \PDOStatement
    {
        $stmt = self::getConnection()->prepare($sql);
        if (!empty($params)) {
            foreach ($params as $key => $val) {
                $paramType = match (true) {
                    is_int($val) => PDO::PARAM_INT,
                    is_bool($val) => PDO::PARAM_BOOL,
                    is_null($val) => PDO::PARAM_NULL,
                    default => PDO::PARAM_STR,
                };
                if (is_int($key)) {
                    $paramKey = $key + 1;
                } else {
                    $paramKey = str_starts_with($key, ':') ? $key : ':' . $key;
                }
                $stmt->bindValue($paramKey, $val, $paramType);
            }
            $stmt->execute();
        } else {
            $stmt->execute();
        }
        return $stmt;
    }

    /**
     * Retorna a primeira linha do resultado ou null.
     */
    public static function fetchOne(string $sql, array $params = []): ?array
    {
        $stmt = self::query($sql, $params);
        $result = $stmt->fetch();
        return $result ?: null;
    }

    /**
     * Retorna o valor escalar de uma única coluna da primeira linha (ex: COUNT(*), SUM(views)).
     */
    public static function fetchValue(string $sql, array $params = []): mixed
    {
        $stmt = self::query($sql, $params);
        $val = $stmt->fetchColumn();
        return $val !== false ? $val : null;
    }

    /**
     * Retorna todas as linhas do resultado.
     */
    public static function fetchAll(string $sql, array $params = []): array
    {
        return self::query($sql, $params)->fetchAll();
    }

    /**
     * Retorna o ID da última linha inserida.
     */
    public static function lastInsertId(): string
    {
        return (string)self::getConnection()->lastInsertId();
    }
}
