<?php
/**
 * Meetanshi AIReporting — print SQL that creates a SELECT-only database user for AI reports
 *
 * MySQL cannot exclude single tables from a database-wide GRANT, so SELECT is granted table by
 * table, leaving out the credential and session tables SqlGuard refuses (admin users, OAuth
 * tokens, payment tokens …). Re-run after installing modules: new tables need a grant too.
 *
 * Nothing is executed — the statements are printed for a database administrator to review and run.
 *
 * @category  Meetanshi
 * @package   Meetanshi_AIReporting
 * @copyright Copyright (c) Meetanshi (https://meetanshi.com)
 */

declare(strict_types=1);

namespace Meetanshi\AIReporting\Console\Command;

use Magento\Framework\App\DeploymentConfig;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Console\Cli;
use Meetanshi\AIReporting\Model\Query\ReadOnlyConnectionProvider;
use Meetanshi\AIReporting\Model\Query\SqlGuard;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class ReadOnlyGrants extends Command
{
    private const OPTION_USER = 'user';
    private const OPTION_HOST = 'host';

    private const ACCOUNT_PATTERN = '/^[A-Za-z0-9_.%-]{1,80}$/';

    public function __construct(
        private readonly ResourceConnection $resourceConnection,
        private readonly DeploymentConfig $deploymentConfig,
        private readonly SqlGuard $sqlGuard
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('meetanshi:aireporting:readonly-grants')
            ->setDescription('Print SQL that creates a SELECT-only database user for Meetanshi AI Reporting queries')
            ->addOption(self::OPTION_USER, null, InputOption::VALUE_REQUIRED, 'Database user name', 'magento_ai_ro')
            ->addOption(self::OPTION_HOST, null, InputOption::VALUE_REQUIRED, 'Host the user connects from', 'localhost');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $user = (string) $input->getOption(self::OPTION_USER);
        $host = (string) $input->getOption(self::OPTION_HOST);
        if (!preg_match(self::ACCOUNT_PATTERN, $user) || !preg_match(self::ACCOUNT_PATTERN, $host)) {
            $output->writeln('<error>User and host may contain only letters, digits and _ . % -</error>');
            return Cli::RETURN_FAILURE;
        }

        $database = (string) $this->deploymentConfig->get('db/connection/default/dbname');
        $account  = "'{$user}'@'{$host}'";
        $tables   = $this->resourceConnection->getConnection()->listTables();
        sort($tables);

        $granted = 0;
        $lines   = [
            '-- SELECT-only user for Meetanshi AI Reporting. Review, set a strong password, then run as a DB admin.',
            "CREATE USER IF NOT EXISTS {$account} IDENTIFIED BY '<choose-a-strong-password>';",
        ];
        foreach ($tables as $table) {
            if ($this->sqlGuard->isDeniedTable((string) $table)) {
                $lines[] = "-- skipped (credentials/sessions): {$table}";
                continue;
            }
            $lines[] = sprintf('GRANT SELECT ON `%s`.`%s` TO %s;', $database, $table, $account);
            $granted++;
        }

        $lines[] = '';
        $lines[] = "-- {$granted} tables granted. Then add this connection to app/etc/env.php under 'db' => 'connection':";
        $lines[] = "--   '" . ReadOnlyConnectionProvider::CONNECTION_NAME . "' => ['host' => '<db host>', 'dbname' => '{$database}', "
            . "'username' => '{$user}', 'password' => '<password>', 'model' => 'mysql4', 'engine' => 'innodb', "
            . "'initStatements' => 'SET NAMES utf8mb4', 'active' => '1'],";
        $lines[] = '-- Re-run this command after installing modules: their new tables need a grant too.';

        $output->writeln($lines, OutputInterface::OUTPUT_RAW);

        return Cli::RETURN_SUCCESS;
    }
}
