<?php
/**
 * Meetanshi AIReporting — shared store context for report queries
 *
 * Centralises everything a report needs to turn raw sales/catalog tables into
 * figures that match the store: table prefix, store timezone, base currency,
 * which orders count as sales, net revenue, EAV attribute ids and stock source.
 *
 * @category  Meetanshi
 * @package   Meetanshi_AIReporting
 * @copyright Copyright (c) Meetanshi (https://meetanshi.com)
 */

declare(strict_types=1);

namespace Meetanshi\AIReporting\Model\Report;

use Magento\Catalog\Api\Data\CategoryInterface;
use Magento\Catalog\Api\Data\ProductInterface;
use Magento\CatalogInventory\Api\StockConfigurationInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\EntityManager\MetadataPool;
use Magento\Framework\Module\Manager as ModuleManager;
use Magento\Framework\Pricing\PriceCurrencyInterface;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;

class ReportContext
{
    /**
     * Order states that are not sales: cancelled orders and orders whose payment was never completed.
     */
    public const EXCLUDED_STATES = ['canceled', 'pending_payment'];

    private const XML_PATH_BASE_CURRENCY = 'currency/options/base';

    private ?\DateTimeZone $timezoneObject = null;
    private ?bool $msiEnabled = null;
    private ?string $currencyCode = null;
    private array $attributeIds = [];

    public function __construct(
        private readonly ResourceConnection $resourceConnection,
        private readonly TimezoneInterface $timezone,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly PriceCurrencyInterface $priceCurrency,
        private readonly MetadataPool $metadataPool,
        private readonly ModuleManager $moduleManager,
        private readonly StockConfigurationInterface $stockConfiguration
    ) {
    }

    public function getConnection(): AdapterInterface
    {
        return $this->resourceConnection->getConnection();
    }

    /**
     * Replace {{table_name}} tokens with the real (prefixed) table names.
     */
    public function resolveTables(string $sql): string
    {
        return (string) preg_replace_callback(
            '/\{\{([a-z0-9_]+)\}\}/',
            fn (array $m) => $this->resourceConnection->getTableName($m[1]),
            $sql
        );
    }

    // ── Orders ────────────────────────────────────────────────────────────

    /**
     * SQL condition that keeps only orders that count as sales.
     */
    public function validOrderCondition(string $alias = 'o'): string
    {
        return sprintf(
            "%s.state NOT IN ('%s')",
            $alias,
            implode("','", self::EXCLUDED_STATES)
        );
    }

    /**
     * Net order revenue (grand total minus refunds) converted to the global base currency.
     */
    public function orderRevenueExpr(string $alias = 'o'): string
    {
        return sprintf(
            '((%1$s.base_grand_total - IFNULL(%1$s.base_total_refunded, 0)) * IFNULL(%1$s.base_to_global_rate, 1))',
            $alias
        );
    }

    /**
     * Gross order value (ignores refunds) in the global base currency, for status breakdowns.
     */
    public function orderValueExpr(string $alias = 'o'): string
    {
        return sprintf('(%1$s.base_grand_total * IFNULL(%1$s.base_to_global_rate, 1))', $alias);
    }

    /**
     * Convert any order-level base amount column to the global base currency.
     */
    public function orderAmountExpr(string $column, string $orderAlias = 'o'): string
    {
        return sprintf('(IFNULL(%s, 0) * IFNULL(%s.base_to_global_rate, 1))', $column, $orderAlias);
    }

    /**
     * Net item revenue: row total after discount, minus refunded amount, in global base currency.
     */
    public function itemRevenueExpr(string $itemAlias = 'oi', string $orderAlias = 'o'): string
    {
        return sprintf(
            '((IFNULL(%1$s.base_row_total, 0) - IFNULL(%1$s.base_discount_amount, 0)'
            . ' - IFNULL(%1$s.base_amount_refunded, 0) + IFNULL(%1$s.base_discount_refunded, 0))'
            . ' * IFNULL(%2$s.base_to_global_rate, 1))',
            $itemAlias,
            $orderAlias
        );
    }

    /**
     * Item price in global base currency.
     */
    public function itemPriceExpr(string $itemAlias = 'oi', string $orderAlias = 'o'): string
    {
        return sprintf('(IFNULL(%s.base_price, 0) * IFNULL(%s.base_to_global_rate, 1))', $itemAlias, $orderAlias);
    }

    /**
     * Net quantity sold: ordered minus refunded minus cancelled.
     */
    public function itemQtyExpr(string $itemAlias = 'oi'): string
    {
        return sprintf(
            '(%1$s.qty_ordered - IFNULL(%1$s.qty_refunded, 0) - IFNULL(%1$s.qty_canceled, 0))',
            $itemAlias
        );
    }

    // ── Time ──────────────────────────────────────────────────────────────

    public function getTimezone(): \DateTimeZone
    {
        if ($this->timezoneObject === null) {
            try {
                $this->timezoneObject = new \DateTimeZone((string) ($this->timezone->getConfigTimezone() ?: 'UTC'));
            } catch (\Exception $e) {
                $this->timezoneObject = new \DateTimeZone('UTC');
            }
        }

        return $this->timezoneObject;
    }

    /**
     * Current time in the store timezone.
     */
    public function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now', $this->getTimezone());
    }

    /**
     * Start of today in the store timezone.
     */
    public function today(): \DateTimeImmutable
    {
        return $this->now()->setTime(0, 0);
    }

    /**
     * Parse a Y-m-d date as local midnight; null when invalid.
     */
    public function parseLocalDate(?string $date): ?\DateTimeImmutable
    {
        if ($date === null || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return null;
        }
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date, $this->getTimezone());
        $errors = \DateTimeImmutable::getLastErrors();

        if ($parsed === false || (is_array($errors) && ($errors['warning_count'] || $errors['error_count']))) {
            return null;
        }

        return $parsed;
    }

    /**
     * Convert a local [start, end) range to UTC strings for comparison with created_at columns.
     *
     * @return string[] [from, to]
     */
    public function utcRange(\DateTimeImmutable $start, \DateTimeImmutable $end): array
    {
        $utc = new \DateTimeZone('UTC');

        return [
            $start->setTimezone($utc)->format('Y-m-d H:i:s'),
            $end->setTimezone($utc)->format('Y-m-d H:i:s'),
        ];
    }

    /**
     * Convert a UTC database datetime to the store timezone.
     */
    public function toLocalDateTime(?string $utcDateTime): ?string
    {
        if (!$utcDateTime) {
            return $utcDateTime;
        }
        try {
            return (new \DateTimeImmutable($utcDateTime, new \DateTimeZone('UTC')))
                ->setTimezone($this->getTimezone())
                ->format('Y-m-d H:i:s');
        } catch (\Exception $e) {
            return $utcDateTime;
        }
    }

    /**
     * SQL expression converting a UTC datetime column to store-local time.
     *
     * Uses the exact UTC offsets (including DST changes) in effect between $from and $to,
     * so it does not depend on MySQL timezone tables being loaded.
     */
    public function localTimeExpr(string $column, \DateTimeImmutable $from, \DateTimeImmutable $to): string
    {
        $transitions = $this->getTimezone()->getTransitions($from->getTimestamp(), $to->getTimestamp());

        if (empty($transitions)) {
            return $this->shiftExpr($column, $this->getTimezone()->getOffset($from));
        }

        $first  = array_shift($transitions);
        $offset = (int) $first['offset'];

        if (empty($transitions)) {
            return $this->shiftExpr($column, $offset);
        }

        $sql = 'CASE';
        foreach ($transitions as $transition) {
            $sql .= sprintf(
                " WHEN %s < '%s' THEN %s",
                $column,
                gmdate('Y-m-d H:i:s', (int) $transition['ts']),
                $this->shiftExpr($column, $offset)
            );
            $offset = (int) $transition['offset'];
        }

        return $sql . ' ELSE ' . $this->shiftExpr($column, $offset) . ' END';
    }

    private function shiftExpr(string $column, int $seconds): string
    {
        return $seconds === 0 ? $column : sprintf('(%s + INTERVAL %d SECOND)', $column, $seconds);
    }

    /**
     * Build one row per month between $start (inclusive) and $end (exclusive), merging SQL rows keyed by "month" (Y-m).
     */
    public function fillMonthly(array $rows, \DateTimeImmutable $start, \DateTimeImmutable $end, array $defaults): array
    {
        $byKey = array_column($rows, null, 'month');
        $result = [];
        for ($cursor = $start->modify('first day of this month'); $cursor < $end; $cursor = $cursor->modify('+1 month')) {
            $key = $cursor->format('Y-m');
            $result[] = array_merge($defaults, $byKey[$key] ?? [], ['month' => $key, 'label' => $cursor->format('M Y')]);
        }

        return $result;
    }

    /**
     * Build one row per day between $start (inclusive) and $end (exclusive), merging SQL rows keyed by $keyField (Y-m-d).
     */
    public function fillDaily(
        array $rows,
        \DateTimeImmutable $start,
        \DateTimeImmutable $end,
        array $defaults,
        string $keyField = 'date'
    ): array {
        $byKey = array_column($rows, null, $keyField);
        $result = [];
        for ($cursor = $start; $cursor < $end; $cursor = $cursor->modify('+1 day')) {
            $key = $cursor->format('Y-m-d');
            $result[] = array_merge($defaults, $byKey[$key] ?? [], [$keyField => $key, 'label' => $cursor->format('d M')]);
        }

        return $result;
    }

    /**
     * Rows for Sunday..Saturday (MySQL DAYOFWEEK order), zero-filled.
     */
    public function fillDaysOfWeek(array $rows, array $defaults): array
    {
        $byKey = array_column($rows, null, 'day_num');
        $names = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
        $result = [];
        foreach ($names as $index => $name) {
            $num = $index + 1;
            $result[] = array_merge($defaults, $byKey[$num] ?? [], ['day_name' => $name, 'day_num' => $num]);
        }

        return $result;
    }

    // ── Currency ──────────────────────────────────────────────────────────

    /**
     * Global base currency code — the currency every report amount is expressed in.
     */
    public function getCurrencyCode(): string
    {
        if ($this->currencyCode === null) {
            $this->currencyCode = (string) ($this->scopeConfig->getValue(self::XML_PATH_BASE_CURRENCY) ?: 'USD');
        }

        return $this->currencyCode;
    }

    public function getCurrencySymbol(): string
    {
        try {
            return (string) $this->priceCurrency->getCurrencySymbol(null, $this->getCurrencyCode());
        } catch (\Exception $e) {
            return $this->getCurrencyCode() . ' ';
        }
    }

    public function formatPrice(float $amount): string
    {
        try {
            return (string) $this->priceCurrency->format($amount, false, 2, null, $this->getCurrencyCode());
        } catch (\Exception $e) {
            return $this->getCurrencySymbol() . number_format($amount, 2);
        }
    }

    // ── Catalog / EAV ─────────────────────────────────────────────────────

    public function getAttributeId(string $entityTypeCode, string $attributeCode): int
    {
        $key = $entityTypeCode . '/' . $attributeCode;
        if (!isset($this->attributeIds[$key])) {
            $this->attributeIds[$key] = (int) $this->getConnection()->fetchOne(
                $this->resolveTables(
                    'SELECT a.attribute_id FROM {{eav_attribute}} a
                     JOIN {{eav_entity_type}} t ON t.entity_type_id = a.entity_type_id
                     WHERE t.entity_type_code = ? AND a.attribute_code = ?'
                ),
                [$entityTypeCode, $attributeCode]
            );
        }

        return $this->attributeIds[$key];
    }

    /**
     * entity_id on Magento Open Source, row_id on Adobe Commerce (content staging).
     */
    public function getProductLinkField(): string
    {
        return $this->metadataPool->getMetadata(ProductInterface::class)->getLinkField();
    }

    public function getCategoryLinkField(): string
    {
        return $this->metadataPool->getMetadata(CategoryInterface::class)->getLinkField();
    }

    /**
     * On Adobe Commerce, restrict a catalog entity table alias to the version active now.
     * Returns an empty string on Magento Open Source.
     */
    public function currentVersionCondition(string $alias): string
    {
        if ($this->getProductLinkField() === 'entity_id') {
            return '';
        }

        return " AND {$alias}.created_in <= UNIX_TIMESTAMP() AND {$alias}.updated_in > UNIX_TIMESTAMP()";
    }

    // ── Stock ─────────────────────────────────────────────────────────────

    public function isMsiEnabled(): bool
    {
        if ($this->msiEnabled === null) {
            $this->msiEnabled = $this->moduleManager->isEnabled('Magento_Inventory')
                && $this->getConnection()->isTableExists($this->resourceConnection->getTableName('inventory_source_item'));
        }

        return $this->msiEnabled;
    }

    /**
     * Derived-table SQL with one row per stock-managed product:
     * product_id, sku, qty, is_in_stock, min_qty.
     *
     * Only product types that hold quantity (simple, virtual, downloadable, …) with "Manage Stock"
     * enabled are included, so configurable/bundle/grouped parents never show as out of stock.
     * With MSI enabled, quantity is summed across all enabled sources.
     */
    public function getStockSql(): string
    {
        $qtyTypes = array_keys($this->stockConfiguration->getIsQtyTypeIds(true)) ?: ['simple', 'virtual', 'downloadable'];
        $typeList = $this->getConnection()->quote($qtyTypes);
        $manageStockDefault = (int) $this->stockConfiguration->getManageStock();
        $manageStock = "IF(si.item_id IS NULL, {$manageStockDefault},"
            . " IF(si.use_config_manage_stock = 1, {$manageStockDefault}, si.manage_stock)) = 1";

        if ($this->isMsiEnabled()) {
            return $this->resolveTables("
                SELECT e.entity_id AS product_id, e.sku,
                       SUM(isi.quantity) AS qty,
                       MAX(isi.status) AS is_in_stock,
                       IFNULL(MAX(si.min_qty), 0) AS min_qty
                FROM {{inventory_source_item}} isi
                JOIN {{inventory_source}} src ON src.source_code = isi.source_code AND src.enabled = 1
                JOIN {{catalog_product_entity}} e ON e.sku = isi.sku
                LEFT JOIN {{cataloginventory_stock_item}} si ON si.product_id = e.entity_id AND si.stock_id = 1
                WHERE e.type_id IN ({$typeList}) AND {$manageStock}
                GROUP BY e.entity_id, e.sku
            ");
        }

        return $this->resolveTables("
            SELECT e.entity_id AS product_id, e.sku, si.qty, si.is_in_stock, si.min_qty
            FROM {{cataloginventory_stock_item}} si
            JOIN {{catalog_product_entity}} e ON e.entity_id = si.product_id
            WHERE si.stock_id = 1 AND e.type_id IN ({$typeList}) AND {$manageStock}
        ");
    }
}
