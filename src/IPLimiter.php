<?php
namespace Syntaxseed\IPLimiter;

/**
 * IP Limiter will log IP addresses for event categories to the database along
 * with attempts and time of last attempt.
 * An 'event' is an IP address and category string combination, which are
 * needed to uniquely identify a record in the DB.
 * @author Sherri Wheeler
 * @version  1.1.0
 * @copyright Copyright (c) 2019, Sherri Wheeler - syntaxseed.com
 * @license MIT
 */

class IPLimiter
{
    protected $pdo;
    protected $tableName;
    protected $lastError = "";
    protected $ip;
    protected $category;

    /**
     * Initialize the object with a connection and the table name.
     *
     * @param PDO $pdo
     * @param string $tableName
     * @return void
     * @throws Exception
     */
    public function __construct($pdo, $tableName='syntaxseed_iplimiter')
    {
        if (is_null($pdo) || !is_a($pdo, 'PDO')) {
            $this->lastError = 'IPLimiter requires a connected PDO object.';
            throw new \Exception($this->lastError);
        }
        if (empty($tableName) || !is_string($tableName)) {
            $this->lastError = 'IPLimiter requires a table name as the second parameter.';
            throw new \Exception($this->lastError);
        }
        $this->pdo = $pdo;
        $this->tableName = $tableName;
    }

    /**
     * Set up IPLimiter for a given IP address and event category. All other method
     * calls will use these.
     *
     * @param string $ip
     * @param string $category
     * @return void
     */
    public function event($ip, $category)
    {
        $this->ip = $ip;
        $this->category = $category;
        return $this;
    }

    /**
     * Log an action by an IP for a given category.
     *
     * @return IPLimiter
     * @throws Exception
     */
    public function log()
    {
        $this->checkEvent();

        try {
            $sql = 'INSERT INTO '.$this->tableName.' (ip, category) VALUES (:ip, :category) ON DUPLICATE KEY UPDATE attempts=attempts+1, lastattempt=NOW();';
            $stmt = $this->pdo
                        ->prepare($sql)
                        ->execute([$this->ip, $this->category]);
        } catch (\PDOException $e) {
            $this->lastError = $e->getMessage();
            throw new \Exception($this->lastError);
        }
        return $this;
    }

    /**
     * Delete the event (IP/category) record from the DB.
     *
     * @return bool
     * @throws Exception
     */
    public function deleteEvent()
    {
        $this->checkEvent();

        try {
            $sql = 'DELETE FROM '.$this->tableName.' WHERE ip=:ip AND category=:category;';
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([$this->ip, $this->category]);
        } catch (\PDOException $e) {
            $this->lastError = $e->getMessage();
            throw new \Exception($this->lastError);
        }
        if ($stmt->rowCount() < 1) {
            return false;
        }
        return true;
    }

    /**
     * Delete all event records for a given IP from the DB.
     *
     * @return bool
     * @throws Exception
     */
    public function deleteIP($ip)
    {
        try {
            $sql = 'DELETE FROM '.$this->tableName.' WHERE ip=:ip;';
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([$ip]);
        } catch (\PDOException $e) {
            $this->lastError = $e->getMessage();
            throw new \Exception($this->lastError);
        }
        if ($stmt->rowCount() < 1) {
            return false;
        }
        return true;
    }

    /**
     * Check if an IP and category have been set.
     *
     * @return void
     * @throws Exception
     */
    protected function checkEvent()
    {
        if (!isset($this->ip) || !isset($this->category)) {
            $this->lastError = "IPLimiter: IP and category have not been set. Use setup() method.";
            throw new \Exception($this->lastError);
            exit();
        }
    }

    /**
     * Get attempts by an IP on a given category.
     *
     * @return int|null
     * @throws Exception
     */
    public function attempts()
    {
        $this->checkEvent();

        try {
            $sql = 'SELECT attempts FROM '.$this->tableName.' WHERE ip=:ip AND category=:category;';
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([$this->ip, $this->category]);
            $result = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\PDOException $e) {
            $this->lastError = $e->getMessage();
            throw new \Exception($this->lastError);
        }

        if (!$result) {
            return null;
        }

        return $result[0]['attempts'];
    }

    /**
     * Reset the attempts for a given IP/Category.
     *
     * @return bool
     * @throws Exception
     */
    public function resetAttempts()
    {
        $this->checkEvent();

        try {
            $sql = 'UPDATE '.$this->tableName.' SET attempts=0 WHERE ip=:ip AND category=:category;';
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([$this->ip, $this->category]);
        } catch (\PDOException $e) {
            $this->lastError = $e->getMessage();
            throw new \Exception($this->lastError);
        }
        if ($stmt->rowCount() != 1) {
            return false;
        }
        return true;
    }

    /**
     * Get last attempt by the IP address in the given category.
     *
     * @param bool $asSecondsAgo
     * @return int|null
     * @throws Exception
     */
    public function last($asSecondsAgo=true)
    {
        $this->checkEvent();

        try {
            $sql = 'SELECT lastattempt FROM '.$this->tableName.' WHERE ip=:ip AND category=:category;';
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([$this->ip, $this->category]);
            $result = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\PDOException $e) {
            $this->lastError = $e->getMessage();
            throw new \Exception($this->lastError);
        }

        if (!$result) {
            return null;
        }

        $lastAttempt = strtotime($result[0]['lastattempt']);

        if ($asSecondsAgo) {
            $lastAttempt = time() - $lastAttempt;
        }

        return $lastAttempt;
    }


    /**
     * Execute an IPLimiter rule on a given IP/category.
     * This allows associating certian categories of events to a given ruleset,
     * then easily verify if a current attempt either passes or fails that rule.
     * And ensuring that attempts are reset after a given cool off period. 0 for always. -1 for never.
     *
     * Rule format JSON:
     *      {
     *         "resetAtSeconds": 3600,
     *         "waitAtLeast": 360,
     *         "allowedAttempts": 5,
     *         "allowBanned":false
     *      }
     * Means:
     *     - If last attempt was at or older than (>=) "resetAtSeconds" seconds ago, reset attempts to 0. -1 for don't reset.
     *     - If last attempt was more recent than (<) "waitAtLeast" seconds ago, FAIL. -1 for ignore.
     *     - If current attempts is more than (>) "allowedAttempts", FAIL. -1 for ignore.
     *     - If banned, FAIL if "allowBanned" is false. Use true to ignore banned status.
     *     - Otherwise, PASS.
     *
     * @param json $rule
     * @return bool
     * @throws Exception
     */
    public function rule($rule)
    {
        if (!$this->isValidRule($rule)) {
            throw new \Exception($this->lastError);
        }

        $rule = json_decode($rule);

        // RULE STEP 1: If last attempt was >= "resetAtSeconds" seconds ago, set attempts to 0. -1 for don't reset.
        $last = $this->last();
        if (($rule->resetAtSeconds >= 0) && ($last >= $rule->resetAtSeconds)) {
            $this->resetAttempts();
        }

        // RULE STEP 2: If last attempt was < "waitAtLeast" seconds ago, FAIL. -1 for ignore.
        $last = $this->last();
        if (($rule->waitAtLeast >= 0) && ($last < $rule->waitAtLeast)) {
            return false;
        }

        // RULE STEP 3: If current attempts is > "attempts", FAIL. -1 for ignore.
        $attempts = $this->attempts();
        if (($rule->allowedAttempts >= 0) && ($attempts > $rule->allowedAttempts)) {
            return false;
        }

        // RULE STEP 4: If banned, FAIL if "allowBanned" is false.
        $banned = $this->isBanned();
        if (!$rule->allowBanned && $banned) {
            return false;
        }

        // RULE STEP 5: Otherwise, PASS.
        return true;
    }

    /**
     * Determine if a Rule string is a valid format.
     *
     * @param string $ruleJSON
     * @return boolean
     */
    private function isValidRule($ruleJSON)
    {
        $rule = json_decode($ruleJSON);

        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->lastError = 'IPLimiter rule is not valid json.';
            return false;
        }

        if (!isset($rule->resetAtSeconds) || !is_int($rule->resetAtSeconds) || ($rule->resetAtSeconds < -1)) {
            $this->lastError = 'IPLimiter invalid rule. Bad "resetAtSeconds" value.';
            return false;
        }

        if (!isset($rule->waitAtLeast) || !is_int($rule->waitAtLeast) || ($rule->waitAtLeast < -1)) {
            $this->lastError = 'IPLimiter invalid rule. Bad "waitAtLeast" value.';
            return false;
        }

        if (!isset($rule->allowedAttempts) || !is_int($rule->allowedAttempts) || ($rule->allowedAttempts < -1)) {
            $this->lastError = 'IPLimiter invalid rule. Bad "allowedAttempts" value.';
            return false;
        }

        if (!isset($rule->allowBanned) || !is_bool($rule->allowBanned)) {
            $this->lastError = 'IPLimiter invalid rule. Bad "allowBanned" value.';
            return false;
        }

        return true;
    }

    /**
     * Set an IP as banned for the event category. Returns ban status.
     *
     * @return bool
     * @throws Exception
     */
    public function ban()
    {
        $this->checkEvent();

        if($this->isBanned()) {
            return true;
        }

        try {
            $sql = 'UPDATE '.$this->tableName.' SET banned=1 WHERE ip=:ip AND category=:category;';
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([$this->ip, $this->category]);
        } catch (\PDOException $e) {
            $this->lastError = $e->getMessage();
            throw new \Exception($this->lastError);
        }

        if ($stmt->rowCount() != 1) {
            return false;
        }
        return true;
    }

    /**
     * Get whether this IP is banned for this event category.
     *
     * @return bool
     * @throws Exception
     */
    public function isBanned()
    {
        $this->checkEvent();

        try {
            $sql = 'SELECT banned FROM '.$this->tableName.' WHERE ip=:ip AND category=:category;';
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([$this->ip, $this->category]);
            $result = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\PDOException $e) {
            $this->lastError = $e->getMessage();
            throw new \Exception($this->lastError);
        }

        if (!$result) {
            return false;
        }

        return ($result[0]['banned'] == 1);
    }

     /**
     * Set an IP as banned for the event category. Returns ban status.
     *
     * @return bool
     * @throws Exception
     */
    public function unBan()
    {
        $this->checkEvent();

        if (!$this->isBanned()) {
            return false;
        }

        try {
            $sql = 'UPDATE '.$this->tableName.' SET banned=0 WHERE ip=:ip AND category=:category;';
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([$this->ip, $this->category]);
        } catch (\PDOException $e) {
            $this->lastError = $e->getMessage();
            throw new \Exception($this->lastError);
        }

        if ($stmt->rowCount() != 1) {
            return true;
        }

        return false;
    }


    /**
     * Getter for the lastError encountered by this object.
     *
     * @return string
     */
    public function getLastError()
    {
        return $this->lastError;
    }

    /**
     * Creates the table needed for this package if it doesn't already exist.
     *
     * @return bool
     */
    public function migrate()
    {
        try {
            $sql = <<<EOF
CREATE TABLE IF NOT EXISTS `{$this->tableName}` (
`ip` varchar(39) COLLATE ascii_general_ci NOT NULL,
`category` varchar(128) COLLATE utf8mb4_general_ci NOT NULL,
`attempts` int(10) unsigned NOT NULL DEFAULT '1',
`lastattempt` datetime NOT NULL DEFAULT NOW(),
`banned` tinyint(1) NOT NULL DEFAULT '0',
PRIMARY KEY (`ip`, `category`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
EOF;
            $this->pdo->exec($sql);
        } catch (\PDOException $e) {
            $this->lastError = $e->getMessage();
            throw new \Exception($this->lastError);
        }
        return true;
    }
}
