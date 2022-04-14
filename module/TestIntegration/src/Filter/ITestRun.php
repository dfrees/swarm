<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */
namespace TestIntegration\Filter;

use Application\Factory\InvokableService;

/**
 * Class ITestRun
 * @package TestIntegration\Filter to describe the test run filter
 */
interface ITestRun extends InvokableService
{
    const NAME = 'testRunFilter';
    const IDS  = 'ids';
}
