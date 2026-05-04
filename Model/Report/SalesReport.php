<?php
/**
 * Meetanshi AIReporting — Sales & Revenue Report Model
 *
 * @category  Meetanshi
 * @package   Meetanshi_AIReporting
 * @copyright Copyright (c) Meetanshi (https://meetanshi.com)
 */

declare(strict_types=1);

namespace Meetanshi\AIReporting\Model\Report;

use Magento\Framework\App\ResourceConnection;

class SalesReport
{
    public function __construct(
        private readonly ResourceConnection $resourceConnection
    ) {
    }

    private function getConnection()
    {
        return $this->resourceConnection->getConnection();
    }

    /**
     * KPI Cards — Today, This Week, This Month, This Year
     */
    public function getKpiCards(): array
    {
        $conn = $this->getConnection();

        return [
            'today' => $conn->fetchRow("
                SELECT
                    COUNT(*) as orders,
                    COALESCE(SUM(grand_total), 0) as revenue,
                    COALESCE(AVG(grand_total), 0) as aov
                FROM sales_order
                WHERE DATE(created_at) = CURDATE()
                  AND status NOT IN ('canceled','closed')
            "),
            'week' => $conn->fetchRow("
                SELECT
                    COUNT(*) as orders,
                    COALESCE(SUM(grand_total), 0) as revenue,
                    COALESCE(AVG(grand_total), 0) as aov
                FROM sales_order
                WHERE YEARWEEK(created_at, 1) = YEARWEEK(CURDATE(), 1)
                  AND status NOT IN ('canceled','closed')
            "),
            'month' => $conn->fetchRow("
                SELECT
                    COUNT(*) as orders,
                    COALESCE(SUM(grand_total), 0) as revenue,
                    COALESCE(AVG(grand_total), 0) as aov
                FROM sales_order
                WHERE YEAR(created_at) = YEAR(CURDATE())
                  AND MONTH(created_at) = MONTH(CURDATE())
                  AND status NOT IN ('canceled','closed')
            "),
            'year' => $conn->fetchRow("
                SELECT
                    COUNT(*) as orders,
                    COALESCE(SUM(grand_total), 0) as revenue,
                    COALESCE(AVG(grand_total), 0) as aov
                FROM sales_order
                WHERE YEAR(created_at) = YEAR(CURDATE())
                  AND status NOT IN ('canceled','closed')
            "),
        ];
    }

    /**
     * Revenue by Month (last 12 months)
     */
    public function getRevenueByMonth(): array
    {
        return $this->getConnection()->fetchAll("
            SELECT
                DATE_FORMAT(created_at, '%Y-%m') as month,
                DATE_FORMAT(created_at, '%b %Y')  as label,
                COUNT(*) as orders,
                ROUND(SUM(grand_total), 2) as revenue,
                ROUND(AVG(grand_total), 2) as aov
            FROM sales_order
            WHERE created_at >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
              AND status NOT IN ('canceled','closed')
            GROUP BY DATE_FORMAT(created_at, '%Y-%m')
            ORDER BY month ASC
        ");
    }

    /**
     * Revenue by Day (last 30 days)
     */
    public function getRevenueByDay(): array
    {
        return $this->getConnection()->fetchAll("
            SELECT
                DATE(created_at) as day,
                DATE_FORMAT(created_at, '%d %b') as label,
                COUNT(*) as orders,
                ROUND(SUM(grand_total), 2) as revenue
            FROM sales_order
            WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
              AND status NOT IN ('canceled','closed')
            GROUP BY DATE(created_at)
            ORDER BY day ASC
        ");
    }

    /**
     * Orders by Status
     */
    public function getOrdersByStatus(): array
    {
        return $this->getConnection()->fetchAll("
            SELECT
                status,
                COUNT(*) as orders,
                ROUND(SUM(grand_total), 2) as revenue
            FROM sales_order
            GROUP BY status
            ORDER BY orders DESC
        ");
    }

    /**
     * Revenue by Shipping Method
     */
    public function getRevenueByShipping(): array
    {
        return $this->getConnection()->fetchAll("
            SELECT
                COALESCE(shipping_description, 'Unknown') as shipping_method,
                COUNT(*) as orders,
                ROUND(SUM(grand_total), 2) as revenue,
                ROUND(AVG(grand_total), 2) as aov
            FROM sales_order
            WHERE status NOT IN ('canceled')
            GROUP BY shipping_description
            ORDER BY revenue DESC
            LIMIT 10
        ");
    }

    /**
     * Coupon Usage Report
     */
    public function getCouponUsage(): array
    {
        return $this->getConnection()->fetchAll("
            SELECT
                coupon_code,
                COUNT(*) as times_used,
                ROUND(SUM(grand_total), 2) as revenue,
                ROUND(SUM(ABS(discount_amount)), 2) as total_discount,
                ROUND(AVG(grand_total), 2) as avg_order_value
            FROM sales_order
            WHERE coupon_code IS NOT NULL AND coupon_code != ''
              AND status NOT IN ('canceled')
            GROUP BY coupon_code
            ORDER BY times_used DESC
            LIMIT 20
        ");
    }

    /**
     * Revenue by Region/State
     */
    public function getRevenueByRegion(): array
    {
        return $this->getConnection()->fetchAll("
            SELECT
                a.region as state,
                a.country_id as country,
                COUNT(DISTINCT o.entity_id) as orders,
                ROUND(SUM(o.grand_total), 2) as revenue
            FROM sales_order o
            JOIN sales_order_address a ON a.parent_id = o.entity_id AND a.address_type = 'shipping'
            WHERE o.status NOT IN ('canceled')
              AND a.region IS NOT NULL AND a.region != ''
            GROUP BY a.region, a.country_id
            ORDER BY revenue DESC
            LIMIT 15
        ");
    }

    /**
     * Refund & Return Summary
     */
    public function getRefundSummary(): array
    {
        $conn = $this->getConnection();
        return [
            'total_refunded'  => $conn->fetchOne("SELECT COALESCE(SUM(total_refunded),0) FROM sales_order WHERE total_refunded > 0"),
            'refund_count'    => $conn->fetchOne("SELECT COUNT(*) FROM sales_order WHERE status IN ('closed','refunded')"),
            'cancel_count'    => $conn->fetchOne("SELECT COUNT(*) FROM sales_order WHERE status = 'canceled'"),
            'cancel_revenue'  => $conn->fetchOne("SELECT COALESCE(SUM(grand_total),0) FROM sales_order WHERE status = 'canceled'"),
        ];
    }

    /**
     * Top Revenue Days of Week
     */
    public function getRevenueByDayOfWeek(): array
    {
        return $this->getConnection()->fetchAll("
            SELECT
                DAYNAME(created_at) as day_name,
                DAYOFWEEK(created_at) as day_num,
                COUNT(*) as orders,
                ROUND(SUM(grand_total), 2) as revenue,
                ROUND(AVG(grand_total), 2) as aov
            FROM sales_order
            WHERE status NOT IN ('canceled','closed')
              AND created_at >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
            GROUP BY DAYNAME(created_at), DAYOFWEEK(created_at)
            ORDER BY day_num ASC
        ");
    }
}
