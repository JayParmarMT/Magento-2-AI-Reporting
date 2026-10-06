<?php
/**
 * Meetanshi AIReporting — isolated database connection for AI / saved report queries
 *
 * AI queries never run on Magento's shared connection. A separate connection is opened so that
 * the read-only transaction and statement timeout set by QueryExecutor cannot leak into the
 * rest of the request.
 *
 * Recommended: add a connection named "aireporting" to app/etc/env.php that uses a MySQL user
 * with SELECT-only privileges on the Magento database:
 *
 *     'db' => ['connection' => [
 *         'default'     => [...],
 *         'aireporting' => ['host' => 'localhost', 'dbname' => 'magento', 'username' => 'magento_ai_ro',
 *                           'password' => '...', 'model' => 'mysql4', 'engine' => 'innodb',
 *                           'initStatements' => 'SET NAMES utf8mb4', 'active' => '1'],
 *     ]],
 *
 * Without it, the default connection's credentials are used (still read-only per transaction).
 *
 * @category  Meetanshi
 * @package   Meetanshi_AIReporting
 * @copyright Copyright (c) Meetanshi (https://meetanshi.com)
 */

declare(strict_types=1);

namespace Meetanshi\AIReporting\Model\Query;

use Magento\Framework\App\DeploymentConfig;
use Magento\Framework\App\ResourceConnection\ConnectionFactory;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Meetanshi\AIReporting\Exception\QueryException;

class ReadOnlyConnectionProvider
{
    public const CONNECTION_NAME = 'aireporting';

    private ?AdapterInterface $connection = null;

    public function __construct(
        private readonly DeploymentConfig $deploymentConfig,
        private readonly ConnectionFactory $connectionFactory
    ) {
    }

    /**
     * @throws QueryException
     */
    public function getConnection(): AdapterInterface
    {
        if ($this->connection === null) {
            $config = $this->isDedicated()
                ? $this->deploymentConfig->get('db/connection/' . self::CONNECTION_NAME)
                : $this->deploymentConfig->get('db/connection/default');

            if (!is_array($config) || empty($config)) {
                throw new QueryException(__('The reporting database connection is not configured.'));
            }

            $this->connection = $this->connectionFactory->create($config);
        }

        return $this->connection;
    }

    /**
     * Whether a dedicated (ideally read-only) "aireporting" connection is configured in env.php.
     */
    public function isDedicated(): bool
    {
        $config = $this->deploymentConfig->get('db/connection/' . self::CONNECTION_NAME);

        return is_array($config) && !empty($config);
    }
}
