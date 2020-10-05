<?php
namespace Syntaxseed\IPLimiter;

use Syntaxseed\IPLimiter\DatabaseInterface;

/**
 * Implementation of the interface used by IPLimiter for PDO connections.
 * @author Sherri Wheeler
 * @version  1.0.0
 * @copyright Copyright (c) 2020, Sherri Wheeler - syntaxseed.com
 * @license MIT
 */

class DatabasePDO implements DatabaseInterface{

    protected $pdo;

    public function __construct(\PDO $pdo){
        $this->pdo = $pdo;
    }

    public function executePrepared(string $statement, array $values) : ?int {
        try{
            $stmt = $this->pdo->prepare($statement);
            $stmt->execute($values);
            return $stmt->rowCount();
        } catch (\PDOException $e) {
            throw new \Exception($e->getMessage());
        }
        return null;
    }

    public function fetchPrepared(string $statement, array $values) : array {
        try {
            $stmt = $this->pdo->prepare($statement);
            $stmt->execute($values);
            $result = $stmt->fetchAll(\PDO::FETCH_ASSOC);
            return $result;
        } catch (\PDOException $e) {
            throw new \Exception($e->getMessage());
            return [];
        }
    }

    public function executeSQL(string $sql) : int {
        try {
            return $this->pdo->exec($sql);
        } catch (\PDOException $e) {
            throw new \Exception($e->getMessage());
            return 0;
        }
    }
}
