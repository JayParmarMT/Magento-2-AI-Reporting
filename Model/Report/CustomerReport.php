<?php
/**
 * Meetanshi AIReporting — Customer Analytics Report Model
 *
 * @category  Meetanshi
 * @package   Meetanshi_AIReporting
 * @copyright Copyright (c) Meetanshi (https://meetanshi.com)
 */

declare(strict_types=1);

namespace Meetanshi\AIReporting\Model\Report;

use Magento\Framework\App\ResourceConnection;

class CustomerReport
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
     * Customer KPIs
     */
    public function getKpiCards(): array
    {
        $conn = $this->getConnection();

        return [
            'total_customers'    => $conn->fetchOne("SELECT COUNT(*) FROM customer_entity"),
            'new_today'          => $conn->fetchOne("SELECT COUNT(*) FROM customer_entity WHERE DATE(created_at) = CURDATE()"),
            'new_this_week'      => $conn->fetchOne("SELECT COUNT(*) FROM customer_entity WHERE YEARWEEK(created_at,1) = YEARWEEK(CURDATE(),1)"),
            'new_this_month'     => $conn->fetchOne("SELECT COUNT(*) FROM customer_entity WHERE YEAR(created_at)=YEAR(CURDATE()) AND MONTH(created_at)=MONTH(CURDATE())"),
            'customers_with_orders' => $conn->fetchOne("SELECT COUNT(DISTINCT customer_email) FROM sales_order WHERE customer_email IS NOT NULL"),
        ];
    }

    /**
     * Top Customers by Total Spend (CLV)
     */
    public function getTopCustomersBySpend(int $limit = 20): array
    {
        return $this->getConnection()->fetchAll("
            SELECT
                customer_email,
                CONCAT(customer_firstname, ' ', customer_lastname) as customer_name,
                COUNT(*) as total_orders,
                ROUND(SUM(grand_total), 2) as lifetime_value,
                ROUND(AVG(grand_total), 2) as avg_order_value,
                MIN(created_at) as first_order_date,
                MAX(created_at) as last_order_date
            FROM sales_order
            WHERE customer_email IS NOT NULL
              AND status NOT IN ('canceled')
            GROUP BY customer_email, customer_firstname, customer_lastname
            ORDER BY lifetime_value DESC
            LIMIT {$limit}
        ");
    }

    /**
     * RFM Segmentation (Recency, Frequency, Monetary)
     * Simplified version — assigns scores 1-5 for each dimension
     */
    public function getRfmSegmentation(int $limit = 50): array
    {
        return $this->getConnection()->fetchAll("
            SELECT
                customer_email,
                CONCAT(customer_firstname, ' ', customer_lastname) as customer_name,
                DATEDIFF(NOW(), MAX(created_at)) as recency_days,
                COUNT(*) as frequency,
                ROUND(SUM(grand_total), 2) as monetary,
                CASE
                    WHEN DATEDIFF(NOW(), MAX(created_at)) <= 30 THEN 5
                    WHEN DATEDIFF(NOW(), MAX(created_at)) <= 60 THEN 4
                    WHEN DATEDIFF(NOW(), MAX(created_at)) <= 90 THEN 3
                    WHEN DATEDIFF(NOW(), MAX(created_at)) <= 180 THEN 2
                    ELSE 1
                END as r_score,
                CASE
                    WHEN COUNT(*) >= 10 THEN 5
                    WHEN COUNT(*) >= 7 THEN 4
                    WHEN COUNT(*) >= 4 THEN 3
                    WHEN COUNT(*) >= 2 THEN 2
                    ELSE 1
                END as f_score,
                CASE
                    WHEN SUM(grand_total) >= 1000 THEN 5
                    WHEN SUM(grand_total) >= 500 THEN 4
                    WHEN SUM(grand_total) >= 250 THEN 3
                    WHEN SUM(grand_total) >= 100 THEN 2
                    ELSE 1
                END as m_score
            FROM sales_order
            WHERE customer_email IS NOT NULL
              AND status NOT IN ('canceled')
            GROUP BY customer_email, customer_firstname, customer_lastname
            ORDER BY monetary DESC
            LIMIT {$limit}
        ");
    }

    /**
     * New vs Returning Customers (last 12 months)
     */
    public function getNewVsReturning(): array
    {
        return $this->getConnection()->fetchAll("
            SELECT
                DATE_FORMAT(o.created_at, '%Y-%m') as month,
                DATE_FORMAT(o.created_at, '%b %Y') as label,
                COUNT(DISTINCT CASE WHEN first_order.is_first = 1 THEN o.customer_email END) as new_customers,
                COUNT(DISTINCT CASE WHEN first_order.is_first = 0 THEN o.customer_email END) as returning_customers
            FROM sales_order o
            LEFT JOIN (
                SELECT
                    customer_email,
                    entity_id,
                    CASE WHEN entity_id = (
                        SELECT MIN(entity_id) FROM sales_order sub
                        WHERE sub.customer_email = sales_order.customer_email
                    ) THEN 1 ELSE 0 END as is_first
                FROM sales_order
                WHERE customer_email IS NOT NULL
            ) first_order ON first_order.entity_id = o.entity_id
            WHERE o.created_at >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
              AND o.customer_email IS NOT NULL
              AND o.status NOT IN ('canceled')
            GROUP BY DATE_FORMAT(o.created_at, '%Y-%m')
            ORDER BY month ASC
        ");
    }

    /**
     * Customer Acquisition by Month
     */
    public function getCustomerAcquisition(): array
    {
        return $this->getConnection()->fetchAll("
            SELECT
                DATE_FORMAT(created_at, '%Y-%m') as month,
                DATE_FORMAT(created_at, '%b %Y') as label,
                COUNT(*) as new_customers
            FROM customer_entity
            WHERE created_at >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
            GROUP BY DATE_FORMAT(created_at, '%Y-%m')
            ORDER BY month ASC
        ");
    }

    /**
     * Customer Lifetime Value Distribution
     */
    public function getClvDistribution(): array
    {
        return $this->getConnection()->fetchAll("
            SELECT
                CASE
                    WHEN total_spent < 100 THEN '$0-$99'
                    WHEN total_spent < 250 THEN '$100-$249'
                    WHEN total_spent < 500 THEN '$250-$499'
                    WHEN total_spent < 1000 THEN '$500-$999'
                    ELSE '$1000+'
                END as clv_range,
                COUNT(*) as customer_count
            FROM (
                SELECT
                    customer_email,
                    SUM(grand_total) as total_spent
                FROM sales_order
                WHERE customer_email IS NOT NULL
                  AND status NOT IN ('canceled')
                GROUP BY customer_email
            ) clv_data
            GROUP BY clv_range
            ORDER BY FIELD(clv_range, '$0-$99', '$100-$249', '$250-$499', '$500-$999', '$1000+')
        ");
    }

    /**
     * Repeat Purchase Rate
     */
    public function getRepeatPurchaseRate(): array
    {
        $conn = $this->getConnection();
        $totalCustomers = $conn->fetchOne("
            SELECT COUNT(DISTINCT customer_email)
            FROM sales_order
            WHERE customer_email IS NOT NULL
        ");

        $repeatCustomers = $conn->fetchOne("
            SELECT COUNT(*)
            FROM (
                SELECT customer_email, COUNT(*) as order_count
                FROM sales_order
                WHERE customer_email IS NOT NULL
                  AND status NOT IN ('canceled')
                GROUP BY customer_email
                HAVING order_count > 1
            ) repeat_data
        ");

        $repeatRate = $totalCustomers > 0 ? round(($repeatCustomers / $totalCustomers) * 100, 2) : 0;

        return [
            'total_customers'   => (int)$totalCustomers,
            'repeat_customers'  => (int)$repeatCustomers,
            'one_time_customers'=> (int)($totalCustomers - $repeatCustomers),
            'repeat_rate'       => $repeatRate,
        ];
    }
}
