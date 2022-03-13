<?php

namespace Syntaxseed\IPLimiter;

/**
 * Interface for database connections used by IPLimiter.
 * @author Sherri Wheeler
 * @version  1.0.0
 * @copyright Copyright (c) 2020, Sherri Wheeler - syntaxseed.com
 * @license MIT
 */

interface DatabaseInterface
{
    public function executePrepared(string $statement, array $values): ?int;

    public function fetchPrepared(string $statement, array $values): array;

    public function executeSQL(string $sql): int;
}
