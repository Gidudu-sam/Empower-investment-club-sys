<?php
/**
 * Database class — singleton PDO wrapper
 */
class Database
{
    private static ?Database $instance = null;
    private PDO $pdo;

    private function __construct()
    {
        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=%s',
            DB_HOST, DB_PORT, DB_NAME, DB_CHARSET
        );

        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];

        try {
            $this->pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
        } catch (PDOException $e) {
            // Log and show a friendly message instead of leaking credentials
            error_log('DB Connection Error: ' . $e->getMessage());
            die(json_encode([
                'success' => false,
                'message' => 'Database connection failed. Please contact the administrator.',
            ]));
        }
    }

    /** Returns the singleton instance */
    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /** Returns the underlying PDO object */
    public function getConnection(): PDO
    {
        return $this->pdo;
    }

    /**
     * Prepare and execute a statement, returning the PDOStatement.
     *
     * @param string $sql    SQL with optional named/positional placeholders
     * @param array  $params Parameters to bind
     */
    public function query(string $sql, array $params = []): PDOStatement
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    /** Prevent cloning */
    private function __clone() {}

    /** Prevent unserialization */
    public function __wakeup()
    {
        throw new \Exception('Cannot unserialize a singleton.');
    }
}
