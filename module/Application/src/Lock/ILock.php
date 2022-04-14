<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */

namespace Application\Lock;

interface ILock
{
    const SERVICE = 'lockService';

    /**
     * Tries to acquire a lock and will block for the number of seconds specified by the $timeout param. Once a lock is
     * acquired, it will execute the $code callback, release the lock and return whatever the $code callback returns.
     *
     * Example:
     * $var1 = 5;
     * $var2 = 7;
     * $code = function() use ($var1, $var2) {
     *     // Do something that requires a lock
     *     return $var1 + $var2;
     * }
     * lock('myLockingKey', $code);  // returns 12
     *
     * @param   string      $mutexName       The name of the mutex
     * @param   callable    $code            The code to be executed in the context of the lock; the synchronized code
     * @param   int         $timeout         Number of seconds that the mutex will block, trying to obtain the lock
     *                                       3 seconds is what the library uses by default so that's what is used here
     *
     * @return  false/mixed                  Returns whatever the $code callback returns or false if the locking fails
     *                                       or the cache is not available.
     */
    public function lock($mutexName, callable $code, $timeout = 3);

    /**
     * The double checked locking pattern is used here.
     * Acquiring a lock is relatively expensive, so if there is a check that can be done before trying to acquire a
     * lock, it makes sense to use this method as it will first evaluate the $check callback and not even bother trying
     * to lock if the $check callback evaluates to false. If the $check method does not evaluate to false, then a lock
     * is acquired. Once the lock has been acquired, the $check callback is run once more inside the lock to ensure that
     * the check condition has not changed in the time that it took to acquire the lock. Thus, "double checked locking".
     * If the check fails, this will return false. If the check passes, then the $code callback will be executed. In
     * this case, this will return whatever is returned by the $code callback.
     *
     * Example:
     * $var1 = 5;
     * $var2 = 7;
     * $code = function() use ($var1, $var2) {
     *     // Do something that requires a lock
     *     return $var1 + $var2;
     * }
     * $failingCheck = function() {
     *     return 5 == 6;
     * }
     * $passingCheck = function() {
     *     return 5 != 6;
     * }
     * lockWithCheck('myLockingKey', $failingCheck, $code);  // returns false
     * lockWithCheck('myLockingKey', $passingCheck, $code);  // returns 12
     *
     * @param   string      $mutexName          The key that is used by the mutex for locking
     * @param   callable    $check:boolean     A callable that returns false if the check fails, anything else will be
     *                                         considered a pass
     * @param   callable    $code              The code to be executed in the context of the lock; the synchronized code
     * @param   int         $timeout           Number of seconds that the mutex will block, trying to obtain the lock
     *                                         3 seconds is what the library uses by default so that's what is used here
     *
     * @return  false/mixed                    Returns false if the $check returns false, the locking fails or if the
     *                                         cache is not available; otherwise returns whatever the $code callback
     *                                         returns
     */
    public function lockWithCheck($mutexName, callable $check, callable $code, $timeout = 3);
}
