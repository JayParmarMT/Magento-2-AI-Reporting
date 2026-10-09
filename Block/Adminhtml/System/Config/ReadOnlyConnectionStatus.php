<?php
/**
 * Meetanshi AIReporting — configuration row showing which database user runs AI queries
 *
 * AI-generated SQL is checked by SqlGuard and runs in a read-only transaction, but the strongest
 * boundary is a database user that can only SELECT the tables reports may read. This row says
 * whether such a user ("aireporting" connection in env.php) is set up, and warns if it can write.
 *
 * @category  Meetanshi
 * @package   Meetanshi_AIReporting
 * @copyright Copyright (c) Meetanshi (https://meetanshi.com)
 */

declare(strict_types=1);

namespace Meetanshi\AIReporting\Block\Adminhtml\System\Config;

use Magento\Backend\Block\Template\Context;
use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\Data\Form\Element\AbstractElement;
use Meetanshi\AIReporting\Model\Query\ReadOnlyConnectionProvider;

class ReadOnlyConnectionStatus extends Field
{
    private const WRITE_PRIVILEGE_PATTERN =
        '/\b(ALL PRIVILEGES|INSERT|UPDATE|DELETE|CREATE|DROP|ALTER|INDEX|TRIGGER|EXECUTE|FILE|SUPER)\b/i';

    public function __construct(
        Context $context,
        private readonly ReadOnlyConnectionProvider $connectionProvider,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    public function render(AbstractElement $element)
    {
        $element->unsScope()->unsCanUseWebsiteValue()->unsCanUseDefaultValue();

        return parent::render($element);
    }

    protected function _getElementHtml(AbstractElement $element)
    {
        [$ok, $message] = $this->getStatus();

        return '<div style="padding-top:6px;color:' . ($ok ? '#1a7f37' : '#b45309') . ';">'
            . $this->escapeHtml($message) . '</div>'
            . '<p class="note"><span>' . $this->escapeHtml(__(
                'Recommended: an "aireporting" connection in app/etc/env.php with a MySQL user that has SELECT on '
                . 'the report tables only. Run "bin/magento meetanshi:aireporting:readonly-grants --user=<name>" to '
                . 'print the GRANT statements.'
            )) . '</span></p>';
    }

    /**
     * @return array{0: bool, 1: string}
     */
    private function getStatus(): array
    {
        if (!$this->connectionProvider->isDedicated()) {
            return [false, (string) __(
                'Not configured: AI queries use Magento\'s main database user (they still run read-only and are '
                . 'checked before execution).'
            )];
        }

        try {
            $grants = $this->connectionProvider->getConnection()->fetchCol('SHOW GRANTS FOR CURRENT_USER()');
        } catch (\Exception $e) {
            return [false, (string) __('The "aireporting" connection is configured but failed: %1', $e->getMessage())];
        }

        foreach ($grants as $grant) {
            $privileges = (string) preg_replace('/\sON\s.*$/is', '', (string) $grant);
            if (preg_match(self::WRITE_PRIVILEGE_PATTERN, $privileges, $m)) {
                return [false, (string) __(
                    'The "aireporting" database user is configured but has the %1 privilege. Give it SELECT only.',
                    strtoupper($m[1])
                )];
            }
        }

        return [true, (string) __('Dedicated read-only database user (the "aireporting" connection) is in use.')];
    }
}
