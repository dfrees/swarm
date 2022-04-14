<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */

namespace Application\Permissions;

use Application\Connection\ConnectionFactory;
use Application\Factory\InvokableService;
use Interop\Container\ContainerInterface;
use P4\Connection\Exception\CommandException;
use P4\Exception as P4Expection;
use Application\Config\ConfigException;

/**
 * Class IpProtects
 *
 * @package Application\Permissions
 */
class IpProtects extends Protections implements InvokableService
{
    use ConfigTrait;

    private $services;

    const IP_PROTECTS           = 'ip_protects';
    const PROTECTIONS_ARE_EMPTY = 'Protections table is empty.';

    /**
     * IpProtects constructor.
     * Check if the Perforce IP Protections is at play with limiting the view on files.
     *
     * @param ContainerInterface $services
     * @param array|null         $options
     * @throws CommandException
     * @throws ConfigException
     */
    public function __construct(ContainerInterface $services, array $options = null)
    {
        $this->services = $services;
        $remoteIp       = $_SERVER['REMOTE_ADDR'] ?? null;

        $this->setEnabled(false);

        if ($this->getEmulateIpProtections() && $remoteIp) {
            $p4 = $services->get(ConnectionFactory::P4);

            // determine whether connected server is case sensitive or case insensitive
            // if we can't puzzle it out, treat is as case sensitive (more restrictive)
            try {
                $isCaseSensitive = $p4->isCaseSensitive();
            } catch (P4Expection $e) {
                $isCaseSensitive = true;
            }

            // collect lines from the protections table to apply
            // we take non-proxy rules for user's IP, but we also take proxy rules to
            // express we treat Swarm as an intermediary
            try {
                $protectionsData = array_merge(
                    $p4->run('protects', ['-h', $remoteIp])->getData(),
                    $p4->run('protects', ['-h', 'proxy-' . $remoteIp])->getData()
                );

                // sort merged protections data to preserve their original order in the protections table
                usort(
                    $protectionsData,
                    function (array $a, array $b) {
                        return (int) $a['line'] - (int) $b['line'];
                    }
                );

                $this->setProtections($protectionsData, $isCaseSensitive);
                $this->setEnabled(true);
            } catch (CommandException $e) {
                if (strpos($e->getMessage(), self::PROTECTIONS_ARE_EMPTY) === false) {
                    // we don't recognize the message, so re-throw the exception
                    throw $e;
                }
            }
        }
    }
}
