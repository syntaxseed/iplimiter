<?php
/**
*  Basic testing for IPLimiter.
*/

class IPLimiterTest extends PHPUnit_Framework_TestCase
{
    protected static $pdo;

    private static function connectToDB()
    {
        $host = 'localhost';
        $db   = 'db1_sandbox';
        $user = 'db1_usr1';
        $pass = 'DB1USR1rt6';
        $charset = 'utf8mb4';

        $dsn = "mysql:host={$host};dbname={$db};charset={$charset}";
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];

        try {
            self::$pdo = new PDO($dsn, $user, $pass, $options);
        } catch (\PDOException $e) {
            throw new \PDOException($e->getMessage(), (int)$e->getCode());
        }
    }

    /**
     * Called before all tests in this class are run.
     */
    public static function setUpBeforeClass()
    {
        self::connectToDB();
    }

    /**
     * Called before each test method is run.
     */
    protected function setUp()
    {}

    /**
    * Test migrating the database table.
    */
    public function testMigrateDatabaseTable()
    {
        $ipLimiter = new Syntaxseed\IPLimiter\IPLimiter(self::$pdo, 'syntaxseed_iplimiter');
        $result = $ipLimiter->migrate();
        $this->assertTrue($result);
    }

    /**
    * Test with a missing connection object.
    */
    public function testNoConnectionGivesException()
    {
        $this->expectException(\Exception::class);
        $ipLimiter = new Syntaxseed\IPLimiter\IPLimiter(null, 'syntaxseed_iplimiter');
    }

    /**
    * Test with a disconnected connection object.
    */
    public function testBadConnectionGivesException()
    {
        $this->expectException(\Exception::class);
        @mysqli_close(self::$pdo);
        $ipLimiter = new Syntaxseed\IPLimiter\IPLimiter(null, 'syntaxseed_iplimiter');
        unset($ipLimiter);
        self::connectToDB();
    }

    /**
    * Basic test of valid class instantiation.
    */
    public function testValidClassInstantiation()
    {
        $ipLimiter = new Syntaxseed\IPLimiter\IPLimiter(self::$pdo, 'syntaxseed_iplimiter');
        $this->assertTrue(is_object($ipLimiter));
    }

    /**
    * Test logging a visit and get the count for it.
    */
    public function testBasicLogging()
    {
        $ipLimiter = new Syntaxseed\IPLimiter\IPLimiter(self::$pdo, 'syntaxseed_iplimiter');
        $ipLimiter->event('0.0.0.1', 'phpunit');
        $ipLimiter->log();

        $attempts = $ipLimiter->attempts();
        $this->assertEquals(1, $attempts);

        $result = $ipLimiter->deleteEvent();
    }

    /**
    * Test logging a visit and get the count for it.
    */
    public function testMethodChainedLogging()
    {
        $ipLimiter = (new Syntaxseed\IPLimiter\IPLimiter(self::$pdo, 'syntaxseed_iplimiter'))
                    ->event('0.0.0.1', 'phpunit')
                    ->log()
                    ->log();

        $attempts = $ipLimiter->attempts();
        $this->assertEquals(2, $attempts);

        $result = $ipLimiter->deleteEvent();
    }

    /**
    * Test resetting the attempts count for an ip/category (event).
    */
    public function testResettingAttempts()
    {
        $ipLimiter = new Syntaxseed\IPLimiter\IPLimiter(self::$pdo, 'syntaxseed_iplimiter');
        $ipLimiter->event('0.0.0.1', 'phpunit');

        $ipLimiter->log();
        $attempts = $ipLimiter->attempts();
        $this->assertGreaterThan(0, $attempts);

        $ipLimiter->resetAttempts();
        $attempts = $ipLimiter->attempts();

        $this->assertEquals(0, $attempts);
    }

    /**
    * Test deleting an event.
    */
    public function testDeleteEvent()
    {
        $ipLimiter = new Syntaxseed\IPLimiter\IPLimiter(self::$pdo, 'syntaxseed_iplimiter');
        $ipLimiter->event('0.0.0.2', 'phpunit');

        // Delete record that doesn't exit.
        $result = $ipLimiter->deleteEvent();
        $this->assertFalse($result);

        // Actually create an event.
        $ipLimiter->log();

        // Delete record that does exist.
        $result = $ipLimiter->deleteEvent();
        $this->assertTrue($result);
    }

    /**
    * Test getting the time of the last attempt.
    */
    public function testLastEventTime()
    {
        $ipLimiter = new Syntaxseed\IPLimiter\IPLimiter(self::$pdo, 'syntaxseed_iplimiter');
        $ipLimiter->event('0.0.0.1', 'phpunit');
        //$ipLimiter->log();
        $last = $ipLimiter->last(false);
        $lastSecsAgo = $ipLimiter->last();

        $this->assertInternalType("int", $last);
        $this->assertLessThan($last, $lastSecsAgo);
    }

    /**
    * Test running a rule set.
    */
    public function testRules()
    {
        $ipLimiter = new Syntaxseed\IPLimiter\IPLimiter(self::$pdo, 'syntaxseed_iplimiter');
        $ipLimiter->event('0.0.0.1', 'phpunit');
        $ipLimiter->deleteEvent();
        $ipLimiter->log();
        $ipLimiter->log();
        $ipLimiter->log();

        // Test if last attempt was at least 60 seconds ago (fails).
        $ruleResult = $ipLimiter->rule('{
            "resetAtSeconds":-1,
            "waitAtLeast":60,
            "allowedAttempts":-1,
            "allowBanned":true
        }');
        $this->assertFalse($ruleResult);

        // Test if there has been a max of 3 attempts (passes).
        $ruleResult = $ipLimiter->rule('{
            "resetAtSeconds":-1,
            "waitAtLeast":-1,
            "allowedAttempts":3,
            "allowBanned":true
        }');
        $this->assertTrue($ruleResult);

        // Reset attempts count if last attempt was at least 0 seconds ago.
        // Then test if there has been a max of 1 attempt.
        $ruleResult = $ipLimiter->rule('{
            "resetAtSeconds":0,
            "waitAtLeast":-1,
            "allowedAttempts":1,
            "allowBanned":true
        }');
        $this->assertTrue($ruleResult);

        // Don't allow banned IPs regardless of relaxed rules.
        $ipLimiter->ban();
        $ruleResult = $ipLimiter->rule('{
            "resetAtSeconds":-1,
            "waitAtLeast":0,
            "allowedAttempts":99999,
            "allowBanned":false
        }');
        $this->assertFalse($ruleResult);
    }

    /**
    * Test banning an IP for the event category.
    */
    public function testBanning()
    {
        $ipLimiter = new Syntaxseed\IPLimiter\IPLimiter(self::$pdo, 'syntaxseed_iplimiter');
        $ipLimiter->event('0.0.0.1', 'phpunit');
        $ipLimiter->log();

        $isBanned = $ipLimiter->ban();
        $this->assertTrue($isBanned);

        $isBanned = $ipLimiter->isBanned();
        $this->assertTrue($isBanned);

        $isBanned = $ipLimiter->unBan();
        $this->assertFalse($isBanned);

        $isBanned = $ipLimiter->isBanned();
        $this->assertFalse($isBanned);
    }

    /**
    * Test deleting all events for an IP.
    */
    public function testDeleteIp()
    {
        $ipLimiter = new Syntaxseed\IPLimiter\IPLimiter(self::$pdo, 'syntaxseed_iplimiter');
        $ipLimiter->event('0.0.0.1', 'phpunit');
        $ipLimiter->log();
        $ipLimiter->event('0.0.0.1', 'otherevent');
        $ipLimiter->log();

        $result = $ipLimiter->deleteIP('0.0.0.1');
        $this->assertTrue($result);

        $attempts = $ipLimiter->attempts();
        $this->assertEquals(0, $attempts);

    }

    public function testIpV6Address()
    {
        $ipLimiter = new Syntaxseed\IPLimiter\IPLimiter(self::$pdo, 'syntaxseed_iplimiter');
        $ipLimiter->event('2001:0db8:0000:0000:0000:ff00:0042:8329', 'phpunit');
        $ipLimiter->log();
        $ipLimiter->log();
        $attempts = $ipLimiter->attempts();

        $this->assertEquals(2, $attempts);
        $ipLimiter->deleteEvent();

    }


    /**
     * Called after all tests in this class are run.
     */
    public static function tearDownAfterClass()
    {
        $ipLimiter = new Syntaxseed\IPLimiter\IPLimiter(self::$pdo, 'syntaxseed_iplimiter');
        $ipLimiter->deleteIP('0.0.0.1');
        $ipLimiter->deleteIP('0.0.0.2');
        self::$pdo = null;
        unset($ipLimiter);
    }
}
