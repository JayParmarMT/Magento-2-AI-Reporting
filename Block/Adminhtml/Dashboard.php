<?php
/**
 * Meetanshi AIReporting
 *
 * @category  Meetanshi
 * @package   Meetanshi_AIReporting
 * @copyright Copyright (c) Meetanshi (https://meetanshi.com)
 */

declare(strict_types=1);

namespace Meetanshi\AIReporting\Block\Adminhtml;

use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use Magento\Framework\App\ResourceConnection;
use Meetanshi\AIReporting\Model\Config;

class Dashboard extends Template
{
    public function __construct(
        Context $context,
        private readonly Config $config,
        private readonly ResourceConnection $resourceConnection,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    public function isEnabled(): bool
    {
        return $this->config->isEnabled();
    }

    public function getLlmProvider(): string
    {
        return $this->config->getLlmProvider();
    }

    public function getQueryExecuteUrl(): string
    {
        return $this->getUrl('meetanshi_aireporting/query/execute');
    }

    public function getSaveReportUrl(): string
    {
        return $this->getUrl('meetanshi_aireporting/report/save');
    }

    public function getRefreshDataUrl(): string
    {
        return $this->getUrl('meetanshi_aireporting/dashboard/refreshData');
    }

    public function getExportUrl(): string
    {
        return $this->getUrl('meetanshi_aireporting/dashboard/export');
    }

    public function getFormKey(): string
    {
        return $this->formKey->getFormKey();
    }

    private function getConnection()
    {
        return $this->resourceConnection->getConnection();
    }

    /**
     * Get date range filter SQL conditions
     */
    private function getDateRangeFilter(string $range, ?string $customStart = null, ?string $customEnd = null): array
    {
        $now = new \DateTime();
        
        switch ($range) {
            case 'today':
                $start = $now->format('Y-m-d');
                $end = $now->format('Y-m-d');
                break;
            case 'yesterday':
                $yesterday = (clone $now)->modify('-1 day');
                $start = $yesterday->format('Y-m-d');
                $end = $yesterday->format('Y-m-d');
                break;
            case 'last_7_days':
                $start = (clone $now)->modify('-7 days')->format('Y-m-d');
                $end = $now->format('Y-m-d');
                break;
            case 'last_30_days':
                $start = (clone $now)->modify('-30 days')->format('Y-m-d');
                $end = $now->format('Y-m-d');
                break;
            case 'last_3_months':
                $start = (clone $now)->modify('-3 months')->format('Y-m-d');
                $end = $now->format('Y-m-d');
                break;
            case 'last_6_months':
                $start = (clone $now)->modify('-6 months')->format('Y-m-d');
                $end = $now->format('Y-m-d');
                break;
            case 'last_year':
                $start = (clone $now)->modify('-1 year')->format('Y-m-d');
                $end = $now->format('Y-m-d');
                break;
            case 'this_month':
                $start = $now->format('Y-m-01');
                $end = $now->format('Y-m-d');
                break;
            case 'this_year':
                $start = $now->format('Y-01-01');
                $end = $now->format('Y-m-d');
                break;
            case 'custom':
                $start = $customStart ?? (clone $now)->modify('-6 months')->format('Y-m-d');
                $end = $customEnd ?? $now->format('Y-m-d');
                break;
            default:
                $start = (clone $now)->modify('-6 months')->format('Y-m-d');
                $end = $now->format('Y-m-d');
        }
        
        return ['start' => $start, 'end' => $end];
    }

    /**
     * Get comprehensive dashboard data
     */
    public function getDashboardData(string $dateRange = 'last_6_months', ?string $customStart = null, ?string $customEnd = null): array
    {
        $dates = $this->getDateRangeFilter($dateRange, $customStart, $customEnd);
        
        return [
            'date_range' => $dates,
            'kpis' => $this->getKpiMetrics($dates),
            'revenue_trend' => $this->getRevenueTrend($dates),
            'revenue_by_category' => $this->getRevenueByCategory($dates),
            'top_products' => $this->getTopProducts($dates),
            'orders_by_status' => $this->getOrdersByStatus($dates),
            'revenue_by_dow' => $this->getRevenueByDayOfWeek($dates),
            'customer_segments' => $this->getCustomerSegments($dates),
            'stock_distribution' => $this->getStockDistribution()
        ];
    }

    /**
     * Get KPI metrics with comparison to previous period
     */
    private function getKpiMetrics(array $dates): array
    {
        $conn = $this->getConnection();
        $start = $dates['start'];
        $end = $dates['end'];
        
        // Calculate previous period
        $startDate = new \DateTime($start);
        $endDate = new \DateTime($end);
        $diff = $startDate->diff($endDate)->days;
        $prevStart = (clone $startDate)->modify("-{$diff} days")->format('Y-m-d');
        $prevEnd = (clone $startDate)->modify('-1 day')->format('Y-m-d');
        
        // Current period metrics
        $current = $conn->fetchRow("
            SELECT
                COUNT(*) as total_orders,
                COALESCE(SUM(grand_total), 0) as total_revenue,
                COALESCE(AVG(grand_total), 0) as avg_order_value,
                COUNT(DISTINCT customer_id) as active_customers
            FROM sales_order
            WHERE DATE(created_at) BETWEEN ? AND ?
              AND status NOT IN ('canceled','closed')
        ", [$start, $end]);
        
        // Previous period metrics
        $previous = $conn->fetchRow("
            SELECT
                COUNT(*) as total_orders,
                COALESCE(SUM(grand_total), 0) as total_revenue,
                COALESCE(AVG(grand_total), 0) as avg_order_value,
                COUNT(DISTINCT customer_id) as active_customers
            FROM sales_order
            WHERE DATE(created_at) BETWEEN ? AND ?
              AND status NOT IN ('canceled','closed')
        ", [$prevStart, $prevEnd]);
        
        // Calculate percentage changes
        $revenueChange = $this->calculatePercentageChange(
            (float)$previous['total_revenue'],
            (float)$current['total_revenue']
        );
        $ordersChange = $this->calculatePercentageChange(
            (float)$previous['total_orders'],
            (float)$current['total_orders']
        );
        $customersChange = $this->calculatePercentageChange(
            (float)$previous['active_customers'],
            (float)$current['active_customers']
        );
        $aovChange = $this->calculatePercentageChange(
            (float)$previous['avg_order_value'],
            (float)$current['avg_order_value']
        );
        
        // Additional metrics
        $productsSold = $conn->fetchOne("
            SELECT COALESCE(SUM(qty_ordered), 0)
            FROM sales_order_item soi
            JOIN sales_order so ON soi.order_id = so.entity_id
            WHERE DATE(so.created_at) BETWEEN ? AND ?
              AND so.status NOT IN ('canceled','closed')
        ", [$start, $end]);
        
        $lowStock = $conn->fetchOne("
            SELECT COUNT(*)
            FROM cataloginventory_stock_item
            WHERE qty <= 10 AND qty > 0
        ");
        
        $outOfStock = $conn->fetchOne("
            SELECT COUNT(*)
            FROM cataloginventory_stock_item
            WHERE qty = 0
        ");
        
        return [
            'total_revenue' => (float)$current['total_revenue'],
            'total_revenue_change' => $revenueChange,
            'total_orders' => (int)$current['total_orders'],
            'total_orders_change' => $ordersChange,
            'active_customers' => (int)$current['active_customers'],
            'active_customers_change' => $customersChange,
            'avg_order_value' => (float)$current['avg_order_value'],
            'avg_order_value_change' => $aovChange,
            'products_sold' => (int)$productsSold,
            'low_stock' => (int)$lowStock,
            'out_of_stock' => (int)$outOfStock
        ];
    }

    /**
     * Calculate percentage change
     */
    private function calculatePercentageChange(float $old, float $new): float
    {
        if ($old == 0) {
            return $new > 0 ? 100.0 : 0.0;
        }
        return round((($new - $old) / $old) * 100, 1);
    }

    /**
     * Get revenue trend over time
     */
    private function getRevenueTrend(array $dates): array
    {
        $conn = $this->getConnection();
        
        return $conn->fetchAll("
            SELECT
                DATE(created_at) as date,
                DATE_FORMAT(created_at, '%d %b') as label,
                COUNT(*) as orders,
                ROUND(SUM(grand_total), 2) as revenue
            FROM sales_order
            WHERE DATE(created_at) BETWEEN ? AND ?
              AND status NOT IN ('canceled','closed')
            GROUP BY DATE(created_at)
            ORDER BY date ASC
        ", [$dates['start'], $dates['end']]);
    }

    /**
     * Get revenue by category
     */
    private function getRevenueByCategory(array $dates): array
    {
        $conn = $this->getConnection();
        
        return $conn->fetchAll("
            SELECT
                c.value as category_name,
                ROUND(SUM(soi.row_total), 2) as revenue,
                SUM(soi.qty_ordered) as qty_sold
            FROM sales_order_item soi
            JOIN sales_order so ON soi.order_id = so.entity_id
            JOIN catalog_category_product ccp ON soi.product_id = ccp.product_id
            JOIN catalog_category_entity_varchar c ON ccp.category_id = c.entity_id
            WHERE DATE(so.created_at) BETWEEN ? AND ?
              AND so.status NOT IN ('canceled','closed')
              AND c.attribute_id = (SELECT attribute_id FROM eav_attribute WHERE attribute_code = 'name' AND entity_type_id = 3)
            GROUP BY c.value
            ORDER BY revenue DESC
            LIMIT 10
        ", [$dates['start'], $dates['end']]);
    }

    /**
     * Get top products by revenue
     */
    private function getTopProducts(array $dates): array
    {
        $conn = $this->getConnection();
        
        return $conn->fetchAll("
            SELECT
                soi.name as product_name,
                soi.sku,
                SUM(soi.qty_ordered) as qty_sold,
                ROUND(SUM(soi.row_total), 2) as revenue,
                ROUND(AVG(soi.price), 2) as avg_price,
                COUNT(DISTINCT soi.order_id) as order_count
            FROM sales_order_item soi
            JOIN sales_order so ON soi.order_id = so.entity_id
            WHERE DATE(so.created_at) BETWEEN ? AND ?
              AND so.status NOT IN ('canceled','closed')
            GROUP BY soi.sku, soi.name
            ORDER BY revenue DESC
            LIMIT 10
        ", [$dates['start'], $dates['end']]);
    }

    /**
     * Get orders by status
     */
    private function getOrdersByStatus(array $dates): array
    {
        $conn = $this->getConnection();
        
        return $conn->fetchAll("
            SELECT
                status,
                COUNT(*) as orders,
                ROUND(SUM(grand_total), 2) as revenue
            FROM sales_order
            WHERE DATE(created_at) BETWEEN ? AND ?
            GROUP BY status
            ORDER BY orders DESC
        ", [$dates['start'], $dates['end']]);
    }

    /**
     * Get revenue by day of week
     */
    private function getRevenueByDayOfWeek(array $dates): array
    {
        $conn = $this->getConnection();
        
        return $conn->fetchAll("
            SELECT
                DAYNAME(created_at) as day_name,
                DAYOFWEEK(created_at) as day_num,
                COUNT(*) as orders,
                ROUND(SUM(grand_total), 2) as revenue
            FROM sales_order
            WHERE DATE(created_at) BETWEEN ? AND ?
              AND status NOT IN ('canceled','closed')
            GROUP BY DAYNAME(created_at), DAYOFWEEK(created_at)
            ORDER BY day_num ASC
        ", [$dates['start'], $dates['end']]);
    }

    /**
     * Get customer segments
     */
    private function getCustomerSegments(array $dates): array
    {
        $conn = $this->getConnection();
        
        return $conn->fetchAll("
            SELECT
                CASE
                    WHEN order_count = 1 THEN 'New'
                    WHEN order_count BETWEEN 2 AND 5 THEN 'Returning'
                    ELSE 'VIP'
                END as segment,
                COUNT(*) as customers,
                ROUND(SUM(total_spent), 2) as revenue
            FROM (
                SELECT
                    customer_id,
                    COUNT(*) as order_count,
                    SUM(grand_total) as total_spent
                FROM sales_order
                WHERE DATE(created_at) BETWEEN ? AND ?
                  AND status NOT IN ('canceled','closed')
                  AND customer_id IS NOT NULL
                GROUP BY customer_id
            ) as customer_orders
            GROUP BY segment
            ORDER BY FIELD(segment, 'New', 'Returning', 'VIP')
        ", [$dates['start'], $dates['end']]);
    }

    /**
     * Get stock distribution
     */
    private function getStockDistribution(): array
    {
        $conn = $this->getConnection();
        
        return $conn->fetchAll("
            SELECT
                CASE
                    WHEN qty = 0 THEN 'Out of Stock'
                    WHEN qty <= 10 THEN 'Low (1-10)'
                    WHEN qty <= 50 THEN 'Medium (11-50)'
                    WHEN qty <= 100 THEN 'Good (51-100)'
                    ELSE 'High (100+)'
                END as stock_range,
                COUNT(*) as product_count
            FROM cataloginventory_stock_item
            GROUP BY stock_range
            ORDER BY FIELD(stock_range, 'Out of Stock', 'Low (1-10)', 'Medium (11-50)', 'Good (51-100)', 'High (100+)')
        ");
    }
}
