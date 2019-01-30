IPLimiter
=========================

A lightweight PHP IP address logging library for tracking the # of attempts, and time of last attempt for various categories of events. An event is an IP address and category string combo. The library includes helpers for setting/getting ban status, deleting individual events or all events for a given IP, etc.

The IPLimiter constructor must be passed a connected PDO object to create a database table and log the events.

The core function of the library is to execute a set of 'rules' which IPLimiter will then determine whether the IP address passes or fails the ruleset, and therefore whether they should be allowed to proceed. Rules can specifiy a max # of attempts, and how long it must be before the next attempt. And whether ban status matters for this rule. An example will follow.

Licence: MIT.

Author: Sherri Wheeler


Features
--------

* Compliant with IPv4 and IPv6 addresses.
* Simple to learn and use.
* Track and run rules against:
  * Number of attempts.
  * Time since last attempt.
  * Ban status.
* Reset # attempts after a given time has passed.
* Flexible. IP Addresses and event strings can be anything.
* Unit-Testing with PHPUnit.


Installation
--------

Require with Composer:
```
composer require syntaxseed/iplimiter ^1.0
```


Usage - Quick Start
--------

First ensure you have a connected PDO object. (http://php.net/manual/en/book.pdo.php).

Import the namespace into your application:
```
use Syntaxseed\IPLimiter\IPLimiter;
```

Initialize a PDO object and use it to create a new IPLimiter instance. The second parameter is your desired database table name for IPLimiter to use.
```
$ipLimiter = new IPLimiter($pdo, 'syntaxseed_iplimiter');
```

**Create the IPLimiter table if it doesn't already exist.**

This and future functions will use the PDO object injected via the constructor.
```
$result = $ipLimiter->migrate();
```

**Log a new event.**

An event has an IP address and a string 'category'. Working with events requires an event to have been set.
```
$ipLimiter->event('123.123.0.1', 'sendmail');
$ipLimiter->log();
```

**Get or reset the # of attemps for a given event.**
```
$ipLimiter->event('123.123.0.1', 'sendmail');
$ipLimiter->log();
$ipLimiter->log();
$attempts = $ipLimiter->attempts();
    // Returns 2.
$ipLimiter->resetAttempts();
    // Sets value to 0.
```

**Get or reset the time since last attempt.**
```
$ipLimiter->event('123.123.0.1', 'sendmail');
$ipLimiter->log();
$lastTime = $ipLimiter->last(false);
    // Returns the unix epoc time since last attempt.
$lastSeconds = $ipLimiter->last();
    // Returns the # of seconds since last attempt.
```
Note: You cannot rest the time since last attempt. If there is a record in the database for this event, then it has a timestamp. To solve this, just delete the event completely, which equates to 'never'.

**Delete an event.**
```
$ipLimiter->event('123.123.0.1', 'sendmail');
$ipLimiter->log();
$ipLimiter->deleteEvent();
```

**Delete ALL events for a given IP.**

This function does NOT require an event to be set, instead, pass in the IP address.
```
$result = $ipLimiter->deleteIP('123.123.0.1');
// Returns false if no records were found/deleted. True otherwise.
```

**Manage ban status for an event.**

 Note that with this method, an IP is banned from individual categories of events, not banned system-wide. The ban/unBan methods return the current ban status, NOT whether the ban/unban set succeeded or not.
```
$ipLimiter->event('123.123.0.1', 'sendmail');
$ipLimiter->log();
$status = $ipLimiter->isBanned();
    // Returns false, ie not currently banned.
$status = $ipLimiter->ban();
    // Now true, ie is currently banned.
$status = $ipLimiter->unBan();
    // Now false, ie not currently banned.
```


Rules
--------

A core feature of IPLimiter is running an event against a ruleset to see if it passes. In this way your application can have different rules for various categories of actions. Here's an example:

**Rule Example: Sending Mail**

In our application, users can only send mail at most every 5 minutes (300 seconds). They can make at most 3 attempts at sending mail before the reset time. Ban status matters for this ruleset (ie some events might use ban status for other purposes but not for rules). Attempts get reset after an hour of no attempts (3600 seconds).

Our ruleset in JSON format:
```
{
    "resetAtSeconds": 3600,
    "waitAtLeast": 300,
    "allowedAttempts": 3,
    "allowBanned":false
}
```
This means:
- If last attempt was at or older than (>=) 3600 seconds ago, reset attempts to 0.
- If last attempt was more recent than (<) 300 seconds ago, FAIL.
- If current attempts is more than (>) 3, FAIL.
- If banned, FAIL.
- Otherwise, PASS.

Execute the ruleset for the currently set event (will fail):
```
$ipLimiter->event('111.222.333.444', 'sendmail');
$ipLimiter->log(); // User sent first mail.
$ruleResult = $ipLimiter->rule('{
            "resetAtSeconds":3600,
            "waitAtLeast":300,
            "allowedAttempts":3,
            "allowBanned":false
        }');

// $ruleResult is false because there was NO time since the last (log) event.
```

Execute a ruleset for the currently set event (will pass):
```
$ipLimiter->event('111.222.333.444', 'sendmail');
$ipLimiter->log(); // User sent first mail.
$ruleResult = $ipLimiter->rule('{
            "resetAtSeconds":3600,
            "waitAtLeast":-1,
            "allowedAttempts":3,
            "allowBanned":false
        }');

// $ruleResult is true because -1 means ignore time since last event, and only look at attempts. 1 <= 3 so PASS.
```

**TIP:** Parts of the ruleset "resetAtSeconds", "waitAtLeast", and "allowedAttempts"  can be set to -1 to ignore this part.


Contributing
--------
* Pull requests are welcome and appreciated! Please be patient while I find time to review.
* Donations: https://syntaxseed.com/about/donate/


Changelog
--------
* v1.0.2 - Improve readme. Better package description.
* v1.0.1 - Fix readme.
* v1.0.0 - Initial release.
