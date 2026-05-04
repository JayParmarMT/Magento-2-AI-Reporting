<?php
/**
 * Meetanshi AIReporting — Test Data Patch
 *
 * Inserts realistic customer, order, product, and inventory test data
 * for AI Reporting module testing and demonstration.
 *
 * @category  Meetanshi
 * @package   Meetanshi_AIReporting
 * @copyright Copyright (c) Meetanshi (https://meetanshi.com)
 */

declare(strict_types=1);

namespace Meetanshi\AIReporting\Setup\Patch\Data;

use Magento\Framework\Setup\Patch\DataPatchInterface;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\App\ResourceConnection;
use Psr\Log\LoggerInterface;

class InsertTestData implements DataPatchInterface
{
    public function __construct(
        private readonly ModuleDataSetupInterface $moduleDataSetup,
        private readonly ResourceConnection $resourceConnection,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * {@inheritdoc}
     */
    public static function getDependencies(): array
    {
        return [];
    }

    /**
     * {@inheritdoc}
     */
    public function getAliases(): array
    {
        return [];
    }

    /**
     * {@inheritdoc}
     */
    public function apply(): self
    {
        $this->moduleDataSetup->startSetup();

        try {
            $connection = $this->resourceConnection->getConnection();

            $this->insertCustomers($connection);
            $this->insertOrders($connection);
            $this->insertSavedReports($connection);
            $this->insertQueryLogs($connection);

            $this->logger->info('Meetanshi AIReporting: Test data inserted successfully.');
        } catch (\Exception $e) {
            $this->logger->error('Meetanshi AIReporting: Failed to insert test data.', [
                'error' => $e->getMessage()
            ]);
        }

        $this->moduleDataSetup->endSetup();

        return $this;
    }

    /**
     * Insert 25 realistic test customers into customer_entity.
     */
    private function insertCustomers(\Magento\Framework\DB\Adapter\AdapterInterface $connection): void
    {
        $table = $connection->getTableName('customer_entity');

        // Check if test data already exists
        $existing = (int) $connection->fetchOne(
            "SELECT COUNT(*) FROM {$table} WHERE email LIKE '%@aireporting-test.com'"
        );
        if ($existing > 0) {
            return;
        }

        $customers = [
            // High-value customers (frequent buyers)
            ['email' => 'john.smith@aireporting-test.com',       'firstname' => 'John',      'lastname' => 'Smith',       'group_id' => 1, 'store_id' => 1, 'is_active' => 1, 'gender' => 1, 'dob' => '1985-03-15'],
            ['email' => 'sarah.johnson@aireporting-test.com',    'firstname' => 'Sarah',     'lastname' => 'Johnson',     'group_id' => 1, 'store_id' => 1, 'is_active' => 1, 'gender' => 2, 'dob' => '1990-07-22'],
            ['email' => 'michael.williams@aireporting-test.com', 'firstname' => 'Michael',   'lastname' => 'Williams',    'group_id' => 1, 'store_id' => 1, 'is_active' => 1, 'gender' => 1, 'dob' => '1978-11-08'],
            ['email' => 'emily.brown@aireporting-test.com',      'firstname' => 'Emily',     'lastname' => 'Brown',       'group_id' => 2, 'store_id' => 1, 'is_active' => 1, 'gender' => 2, 'dob' => '1992-01-30'],
            ['email' => 'david.jones@aireporting-test.com',      'firstname' => 'David',     'lastname' => 'Jones',       'group_id' => 2, 'store_id' => 1, 'is_active' => 1, 'gender' => 1, 'dob' => '1988-05-12'],

            // Medium-value customers
            ['email' => 'jessica.davis@aireporting-test.com',    'firstname' => 'Jessica',   'lastname' => 'Davis',       'group_id' => 1, 'store_id' => 1, 'is_active' => 1, 'gender' => 2, 'dob' => '1995-09-18'],
            ['email' => 'robert.miller@aireporting-test.com',    'firstname' => 'Robert',    'lastname' => 'Miller',      'group_id' => 1, 'store_id' => 1, 'is_active' => 1, 'gender' => 1, 'dob' => '1982-12-05'],
            ['email' => 'amanda.wilson@aireporting-test.com',    'firstname' => 'Amanda',    'lastname' => 'Wilson',      'group_id' => 1, 'store_id' => 1, 'is_active' => 1, 'gender' => 2, 'dob' => '1993-04-25'],
            ['email' => 'james.taylor@aireporting-test.com',     'firstname' => 'James',     'lastname' => 'Taylor',      'group_id' => 2, 'store_id' => 1, 'is_active' => 1, 'gender' => 1, 'dob' => '1975-08-14'],
            ['email' => 'lisa.anderson@aireporting-test.com',    'firstname' => 'Lisa',      'lastname' => 'Anderson',    'group_id' => 1, 'store_id' => 1, 'is_active' => 1, 'gender' => 2, 'dob' => '1987-06-03'],

            // Low-value / one-time customers
            ['email' => 'chris.thomas@aireporting-test.com',     'firstname' => 'Chris',     'lastname' => 'Thomas',      'group_id' => 1, 'store_id' => 1, 'is_active' => 1, 'gender' => 1, 'dob' => '1991-02-28'],
            ['email' => 'jennifer.jackson@aireporting-test.com', 'firstname' => 'Jennifer',  'lastname' => 'Jackson',     'group_id' => 1, 'store_id' => 1, 'is_active' => 1, 'gender' => 2, 'dob' => '1989-10-17'],
            ['email' => 'daniel.white@aireporting-test.com',     'firstname' => 'Daniel',    'lastname' => 'White',       'group_id' => 1, 'store_id' => 1, 'is_active' => 1, 'gender' => 1, 'dob' => '1996-07-09'],
            ['email' => 'michelle.harris@aireporting-test.com',  'firstname' => 'Michelle',  'lastname' => 'Harris',      'group_id' => 2, 'store_id' => 1, 'is_active' => 1, 'gender' => 2, 'dob' => '1984-03-21'],
            ['email' => 'kevin.martin@aireporting-test.com',     'firstname' => 'Kevin',     'lastname' => 'Martin',      'group_id' => 1, 'store_id' => 1, 'is_active' => 1, 'gender' => 1, 'dob' => '1979-11-30'],

            // Inactive / churned customers
            ['email' => 'patricia.garcia@aireporting-test.com',  'firstname' => 'Patricia',  'lastname' => 'Garcia',      'group_id' => 1, 'store_id' => 1, 'is_active' => 0, 'gender' => 2, 'dob' => '1986-08-07'],
            ['email' => 'mark.martinez@aireporting-test.com',    'firstname' => 'Mark',      'lastname' => 'Martinez',    'group_id' => 1, 'store_id' => 1, 'is_active' => 1, 'gender' => 1, 'dob' => '1994-01-15'],
            ['email' => 'nancy.robinson@aireporting-test.com',   'firstname' => 'Nancy',     'lastname' => 'Robinson',    'group_id' => 2, 'store_id' => 1, 'is_active' => 1, 'gender' => 2, 'dob' => '1981-05-22'],
            ['email' => 'steven.clark@aireporting-test.com',     'firstname' => 'Steven',    'lastname' => 'Clark',       'group_id' => 1, 'store_id' => 1, 'is_active' => 1, 'gender' => 1, 'dob' => '1990-12-11'],
            ['email' => 'karen.rodriguez@aireporting-test.com',  'firstname' => 'Karen',     'lastname' => 'Rodriguez',   'group_id' => 1, 'store_id' => 1, 'is_active' => 1, 'gender' => 2, 'dob' => '1977-09-04'],

            // Guest-like customers (registered but minimal activity)
            ['email' => 'brian.lewis@aireporting-test.com',      'firstname' => 'Brian',     'lastname' => 'Lewis',       'group_id' => 0, 'store_id' => 1, 'is_active' => 1, 'gender' => 1, 'dob' => '1998-04-19'],
            ['email' => 'laura.lee@aireporting-test.com',        'firstname' => 'Laura',     'lastname' => 'Lee',         'group_id' => 0, 'store_id' => 1, 'is_active' => 1, 'gender' => 2, 'dob' => '1993-06-28'],
            ['email' => 'andrew.walker@aireporting-test.com',    'firstname' => 'Andrew',    'lastname' => 'Walker',      'group_id' => 1, 'store_id' => 1, 'is_active' => 1, 'gender' => 1, 'dob' => '1983-02-14'],
            ['email' => 'rachel.hall@aireporting-test.com',      'firstname' => 'Rachel',    'lastname' => 'Hall',        'group_id' => 1, 'store_id' => 1, 'is_active' => 0, 'gender' => 2, 'dob' => '1997-10-06'],
            ['email' => 'thomas.allen@aireporting-test.com',     'firstname' => 'Thomas',    'lastname' => 'Allen',       'group_id' => 1, 'store_id' => 1, 'is_active' => 1, 'gender' => 1, 'dob' => '1980-07-31'],
        ];

        $now = date('Y-m-d H:i:s');
        $createdDates = $this->generateSpreadDates(count($customers), 365);

        foreach ($customers as $i => $customer) {
            $customer['created_at'] = $createdDates[$i];
            $customer['updated_at'] = $now;
            $connection->insert($table, $customer);
        }
    }

    /**
     * Insert 50 realistic test orders with items, addresses, and payments.
     */
    private function insertOrders(\Magento\Framework\DB\Adapter\AdapterInterface $connection): void
    {
        $orderTable   = $connection->getTableName('sales_order');
        $itemTable    = $connection->getTableName('sales_order_item');
        $addressTable = $connection->getTableName('sales_order_address');
        $paymentTable = $connection->getTableName('sales_order_payment');

        // Check if test data already exists
        $existing = (int) $connection->fetchOne(
            "SELECT COUNT(*) FROM {$orderTable} WHERE customer_email LIKE '%@aireporting-test.com'"
        );
        if ($existing > 0) {
            return;
        }

        // Fetch test customer IDs
        $customerTable = $connection->getTableName('customer_entity');
        $testCustomers = $connection->fetchAll(
            "SELECT entity_id, email, firstname, lastname FROM {$customerTable} WHERE email LIKE '%@aireporting-test.com'"
        );
        if (empty($testCustomers)) {
            return;
        }

        // Test products (SKU, name, price)
        $products = [
            ['sku' => 'TEST-LAPTOP-001',   'name' => 'ProBook Laptop 15"',          'price' => 899.99,  'type' => 'simple'],
            ['sku' => 'TEST-PHONE-001',     'name' => 'SmartPhone X Pro',            'price' => 699.99,  'type' => 'simple'],
            ['sku' => 'TEST-HEADPHONE-001', 'name' => 'Wireless Noise-Cancel Headphones', 'price' => 249.99, 'type' => 'simple'],
            ['sku' => 'TEST-TABLET-001',    'name' => 'Digital Tablet 10"',          'price' => 449.99,  'type' => 'simple'],
            ['sku' => 'TEST-WATCH-001',     'name' => 'Smart Watch Series 5',        'price' => 349.99,  'type' => 'simple'],
            ['sku' => 'TEST-CAMERA-001',    'name' => 'Mirrorless Camera Kit',       'price' => 1299.99, 'type' => 'simple'],
            ['sku' => 'TEST-SPEAKER-001',   'name' => 'Bluetooth Speaker Pro',       'price' => 129.99,  'type' => 'simple'],
            ['sku' => 'TEST-KEYBOARD-001',  'name' => 'Mechanical Gaming Keyboard',  'price' => 159.99,  'type' => 'simple'],
            ['sku' => 'TEST-MOUSE-001',     'name' => 'Ergonomic Wireless Mouse',    'price' => 79.99,   'type' => 'simple'],
            ['sku' => 'TEST-CHARGER-001',   'name' => 'USB-C Fast Charger 65W',      'price' => 39.99,   'type' => 'simple'],
            ['sku' => 'TEST-CASE-001',      'name' => 'Premium Phone Case',          'price' => 29.99,   'type' => 'simple'],
            ['sku' => 'TEST-CABLE-001',     'name' => 'Braided USB-C Cable 2m',      'price' => 14.99,   'type' => 'simple'],
        ];

        $statuses = ['pending', 'processing', 'complete', 'complete', 'complete', 'complete', 'canceled', 'closed'];
        $shippingMethods = ['flatrate_flatrate', 'freeshipping_freeshipping', 'tablerate_bestway'];
        $shippingDescs   = ['Flat Rate - Fixed', 'Free Shipping - Free', 'Best Way - Table Rate'];
        $paymentMethods  = ['checkmo', 'cashondelivery', 'banktransfer'];
        $coupons         = [null, null, null, null, 'SAVE10', 'WELCOME20', 'SUMMER15', null];

        $regions = [
            ['city' => 'New York',      'region' => 'New York',     'region_id' => 43, 'country_id' => 'US', 'postcode' => '10001'],
            ['city' => 'Los Angeles',    'region' => 'California',   'region_id' => 12, 'country_id' => 'US', 'postcode' => '90001'],
            ['city' => 'Chicago',        'region' => 'Illinois',     'region_id' => 23, 'country_id' => 'US', 'postcode' => '60601'],
            ['city' => 'Houston',        'region' => 'Texas',        'region_id' => 57, 'country_id' => 'US', 'postcode' => '77001'],
            ['city' => 'Phoenix',        'region' => 'Arizona',      'region_id' => 4,  'country_id' => 'US', 'postcode' => '85001'],
            ['city' => 'Philadelphia',   'region' => 'Pennsylvania', 'region_id' => 51, 'country_id' => 'US', 'postcode' => '19101'],
            ['city' => 'San Antonio',    'region' => 'Texas',        'region_id' => 57, 'country_id' => 'US', 'postcode' => '78201'],
            ['city' => 'San Diego',      'region' => 'California',   'region_id' => 12, 'country_id' => 'US', 'postcode' => '92101'],
            ['city' => 'Dallas',         'region' => 'Texas',        'region_id' => 57, 'country_id' => 'US', 'postcode' => '75201'],
            ['city' => 'Miami',          'region' => 'Florida',      'region_id' => 18, 'country_id' => 'US', 'postcode' => '33101'],
        ];

        $orderDates = $this->generateSpreadDates(50, 180);
        $incrementBase = 100000000;

        for ($i = 0; $i < 50; $i++) {
            $customer    = $testCustomers[array_rand($testCustomers)];
            $status      = $statuses[array_rand($statuses)];
            $state       = $this->getStateFromStatus($status);
            $shippingIdx = array_rand($shippingMethods);
            $coupon      = $coupons[array_rand($coupons)];
            $region      = $regions[array_rand($regions)];
            $payMethod   = $paymentMethods[array_rand($paymentMethods)];

            // Pick 1-4 random products for this order
            $numItems    = rand(1, 4);
            $orderItems  = [];
            $usedIndexes = [];
            for ($j = 0; $j < $numItems; $j++) {
                do {
                    $pIdx = array_rand($products);
                } while (in_array($pIdx, $usedIndexes, true) && count($usedIndexes) < count($products));
                $usedIndexes[] = $pIdx;
                $qty = rand(1, 3);
                $orderItems[] = [
                    'product'  => $products[$pIdx],
                    'qty'      => $qty,
                    'rowTotal' => round($products[$pIdx]['price'] * $qty, 2),
                ];
            }

            $subtotal       = array_sum(array_column($orderItems, 'rowTotal'));
            $taxAmount      = round($subtotal * 0.08, 2);
            $shippingAmount = ($shippingMethods[$shippingIdx] === 'freeshipping_freeshipping') ? 0.00 : round(rand(500, 1500) / 100, 2);
            $discountAmount = 0.00;
            if ($coupon === 'SAVE10') {
                $discountAmount = round($subtotal * 0.10, 2);
            } elseif ($coupon === 'WELCOME20') {
                $discountAmount = round($subtotal * 0.20, 2);
            } elseif ($coupon === 'SUMMER15') {
                $discountAmount = round($subtotal * 0.15, 2);
            }
            $grandTotal     = round($subtotal + $taxAmount + $shippingAmount - $discountAmount, 2);
            $totalQty       = array_sum(array_column($orderItems, 'qty'));
            $incrementId    = (string) ($incrementBase + $i + 1);
            $createdAt      = $orderDates[$i];

            // Refund data for closed orders
            $totalRefunded = ($status === 'closed') ? $grandTotal : 0.00;

            // Insert sales_order
            $connection->insert($orderTable, [
                'store_id'            => 1,
                'increment_id'        => $incrementId,
                'status'              => $status,
                'state'               => $state,
                'customer_id'         => $customer['entity_id'],
                'customer_email'      => $customer['email'],
                'customer_firstname'  => $customer['firstname'],
                'customer_lastname'   => $customer['lastname'],
                'grand_total'         => $grandTotal,
                'base_grand_total'    => $grandTotal,
                'subtotal'            => $subtotal,
                'base_subtotal'       => $subtotal,
                'tax_amount'          => $taxAmount,
                'base_tax_amount'     => $taxAmount,
                'shipping_amount'     => $shippingAmount,
                'base_shipping_amount'=> $shippingAmount,
                'discount_amount'     => -$discountAmount,
                'base_discount_amount'=> -$discountAmount,
                'total_qty_ordered'   => $totalQty,
                'total_refunded'      => $totalRefunded,
                'coupon_code'         => $coupon,
                'shipping_method'     => $shippingMethods[$shippingIdx],
                'shipping_description'=> $shippingDescs[$shippingIdx],
                'created_at'          => $createdAt,
                'updated_at'          => $createdAt,
            ]);

            $orderId = (int) $connection->lastInsertId($orderTable);

            // Insert sales_order_item for each product
            foreach ($orderItems as $item) {
                $qtyShipped  = ($status === 'complete') ? $item['qty'] : 0;
                $qtyInvoiced = in_array($status, ['complete', 'closed']) ? $item['qty'] : 0;
                $qtyRefunded = ($status === 'closed') ? $item['qty'] : 0;

                $connection->insert($itemTable, [
                    'order_id'       => $orderId,
                    'product_id'     => rand(1, 100),
                    'product_type'   => $item['product']['type'],
                    'sku'            => $item['product']['sku'],
                    'name'           => $item['product']['name'],
                    'qty_ordered'    => $item['qty'],
                    'qty_invoiced'   => $qtyInvoiced,
                    'qty_shipped'    => $qtyShipped,
                    'qty_refunded'   => $qtyRefunded,
                    'price'          => $item['product']['price'],
                    'base_price'     => $item['product']['price'],
                    'row_total'      => $item['rowTotal'],
                    'base_row_total' => $item['rowTotal'],
                    'discount_amount'=> 0.00,
                    'created_at'     => $createdAt,
                    'updated_at'     => $createdAt,
                ]);
            }

            // Insert billing address
            $connection->insert($addressTable, [
                'parent_id'    => $orderId,
                'address_type' => 'billing',
                'firstname'    => $customer['firstname'],
                'lastname'     => $customer['lastname'],
                'email'        => $customer['email'],
                'city'         => $region['city'],
                'region'       => $region['region'],
                'region_id'    => $region['region_id'],
                'country_id'   => $region['country_id'],
                'postcode'     => $region['postcode'],
                'telephone'    => '555-' . str_pad((string) rand(1000, 9999), 4, '0', STR_PAD_LEFT),
            ]);

            // Insert shipping address
            $connection->insert($addressTable, [
                'parent_id'    => $orderId,
                'address_type' => 'shipping',
                'firstname'    => $customer['firstname'],
                'lastname'     => $customer['lastname'],
                'email'        => $customer['email'],
                'city'         => $region['city'],
                'region'       => $region['region'],
                'region_id'    => $region['region_id'],
                'country_id'   => $region['country_id'],
                'postcode'     => $region['postcode'],
                'telephone'    => '555-' . str_pad((string) rand(1000, 9999), 4, '0', STR_PAD_LEFT),
            ]);

            // Insert payment
            $amountPaid    = in_array($status, ['complete', 'closed']) ? $grandTotal : 0.00;
            $amountRefunded = ($status === 'closed') ? $grandTotal : 0.00;

            $connection->insert($paymentTable, [
                'parent_id'       => $orderId,
                'method'          => $payMethod,
                'amount_ordered'  => $grandTotal,
                'amount_paid'     => $amountPaid,
                'amount_refunded' => $amountRefunded,
            ]);
        }
    }

    /**
     * Insert sample saved reports for testing.
     */
    private function insertSavedReports(\Magento\Framework\DB\Adapter\AdapterInterface $connection): void
    {
        $table = $connection->getTableName('meetanshi_aireporting_saved_report');

        $existing = (int) $connection->fetchOne(
            "SELECT COUNT(*) FROM {$table} WHERE title LIKE '[Test]%'"
        );
        if ($existing > 0) {
            return;
        }

        $reports = [
            [
                'title'        => '[Test] Monthly Revenue Trend',
                'nlp_query'    => 'Show me monthly revenue for the last 12 months',
                'sql_query'    => "SELECT DATE_FORMAT(created_at, '%Y-%m') as month, ROUND(SUM(grand_total), 2) as revenue, COUNT(*) as orders FROM sales_order WHERE created_at >= DATE_SUB(NOW(), INTERVAL 12 MONTH) AND status NOT IN ('canceled') GROUP BY month ORDER BY month ASC",
                'chart_type'   => 'line',
                'chart_config' => json_encode(['xAxis' => 'month', 'yAxis' => 'revenue']),
                'admin_user_id'=> 1,
            ],
            [
                'title'        => '[Test] Top 10 Customers by Spend',
                'nlp_query'    => 'Who are the top 10 customers by total spending?',
                'sql_query'    => "SELECT customer_email, CONCAT(customer_firstname, ' ', customer_lastname) as name, ROUND(SUM(grand_total), 2) as total_spent, COUNT(*) as orders FROM sales_order WHERE customer_email IS NOT NULL AND status NOT IN ('canceled') GROUP BY customer_email, customer_firstname, customer_lastname ORDER BY total_spent DESC LIMIT 10",
                'chart_type'   => 'bar',
                'chart_config' => json_encode(['xAxis' => 'name', 'yAxis' => 'total_spent']),
                'admin_user_id'=> 1,
            ],
            [
                'title'        => '[Test] Orders by Status Distribution',
                'nlp_query'    => 'Show order count by status as a pie chart',
                'sql_query'    => "SELECT status, COUNT(*) as order_count, ROUND(SUM(grand_total), 2) as revenue FROM sales_order GROUP BY status ORDER BY order_count DESC",
                'chart_type'   => 'pie',
                'chart_config' => json_encode(['label' => 'status', 'value' => 'order_count']),
                'admin_user_id'=> 1,
            ],
            [
                'title'        => '[Test] Revenue by Region',
                'nlp_query'    => 'What is the revenue breakdown by US state?',
                'sql_query'    => "SELECT a.region as state, COUNT(DISTINCT o.entity_id) as orders, ROUND(SUM(o.grand_total), 2) as revenue FROM sales_order o JOIN sales_order_address a ON a.parent_id = o.entity_id AND a.address_type = 'shipping' WHERE o.status NOT IN ('canceled') AND a.region IS NOT NULL GROUP BY a.region ORDER BY revenue DESC LIMIT 15",
                'chart_type'   => 'bar',
                'chart_config' => json_encode(['xAxis' => 'state', 'yAxis' => 'revenue']),
                'admin_user_id'=> 1,
            ],
            [
                'title'        => '[Test] Customer Lifetime Value Segments',
                'nlp_query'    => 'Segment customers by their lifetime value',
                'sql_query'    => "SELECT CASE WHEN total < 100 THEN '$0-$99' WHEN total < 500 THEN '$100-$499' WHEN total < 1000 THEN '$500-$999' ELSE '$1000+' END as segment, COUNT(*) as customers FROM (SELECT customer_email, SUM(grand_total) as total FROM sales_order WHERE customer_email IS NOT NULL AND status NOT IN ('canceled') GROUP BY customer_email) t GROUP BY segment",
                'chart_type'   => 'pie',
                'chart_config' => json_encode(['label' => 'segment', 'value' => 'customers']),
                'admin_user_id'=> 1,
            ],
        ];

        $now = date('Y-m-d H:i:s');
        foreach ($reports as $report) {
            $report['created_at'] = $now;
            $report['updated_at'] = $now;
            $connection->insert($table, $report);
        }
    }

    /**
     * Insert sample query log entries for testing.
     */
    private function insertQueryLogs(\Magento\Framework\DB\Adapter\AdapterInterface $connection): void
    {
        $table = $connection->getTableName('meetanshi_aireporting_query_log');

        $existing = (int) $connection->fetchOne(
            "SELECT COUNT(*) FROM {$table} WHERE nlp_query LIKE '[Test]%'"
        );
        if ($existing > 0) {
            return;
        }

        $logs = [
            ['nlp_query' => '[Test] Show total revenue this month',                'sql_query' => "SELECT SUM(grand_total) as revenue FROM sales_order WHERE MONTH(created_at)=MONTH(CURDATE()) AND status NOT IN ('canceled')", 'llm_provider' => 'groq', 'status' => 'success', 'error_message' => null, 'execution_time_ms' => 45,  'rows_returned' => 1,  'admin_user_id' => 1],
            ['nlp_query' => '[Test] List top 5 products by quantity sold',         'sql_query' => "SELECT sku, name, SUM(qty_ordered) as qty FROM sales_order_item GROUP BY sku, name ORDER BY qty DESC LIMIT 5", 'llm_provider' => 'groq', 'status' => 'success', 'error_message' => null, 'execution_time_ms' => 120, 'rows_returned' => 5,  'admin_user_id' => 1],
            ['nlp_query' => '[Test] How many customers registered this week?',     'sql_query' => "SELECT COUNT(*) as new_customers FROM customer_entity WHERE YEARWEEK(created_at,1)=YEARWEEK(CURDATE(),1)", 'llm_provider' => 'groq', 'status' => 'success', 'error_message' => null, 'execution_time_ms' => 30,  'rows_returned' => 1,  'admin_user_id' => 1],
            ['nlp_query' => '[Test] Delete all orders',                            'sql_query' => null, 'llm_provider' => 'groq', 'status' => 'error', 'error_message' => 'Security violation: AI generated a non-SELECT query (DELETE). Only SELECT queries are allowed.', 'execution_time_ms' => 15, 'rows_returned' => 0, 'admin_user_id' => 1],
            ['nlp_query' => '[Test] Show average order value by day of week',      'sql_query' => "SELECT DAYNAME(created_at) as day, ROUND(AVG(grand_total),2) as aov FROM sales_order WHERE status NOT IN ('canceled') GROUP BY DAYNAME(created_at)", 'llm_provider' => 'groq', 'status' => 'success', 'error_message' => null, 'execution_time_ms' => 85, 'rows_returned' => 7, 'admin_user_id' => 1],
            ['nlp_query' => '[Test] Show orders with coupon codes used',           'sql_query' => "SELECT coupon_code, COUNT(*) as times_used, ROUND(SUM(grand_total),2) as revenue FROM sales_order WHERE coupon_code IS NOT NULL GROUP BY coupon_code", 'llm_provider' => 'groq', 'status' => 'success', 'error_message' => null, 'execution_time_ms' => 55, 'rows_returned' => 3, 'admin_user_id' => 1],
            ['nlp_query' => '[Test] What is the repeat purchase rate?',            'sql_query' => "SELECT COUNT(*) as repeat_customers FROM (SELECT customer_email, COUNT(*) as cnt FROM sales_order WHERE customer_email IS NOT NULL GROUP BY customer_email HAVING cnt > 1) t", 'llm_provider' => 'groq', 'status' => 'success', 'error_message' => null, 'execution_time_ms' => 95, 'rows_returned' => 1, 'admin_user_id' => 1],
            ['nlp_query' => '[Test] Invalid gibberish query xyz123',               'sql_query' => null, 'llm_provider' => 'groq', 'status' => 'error', 'error_message' => 'AI did not return a valid SELECT query. Please rephrase your question.', 'execution_time_ms' => 200, 'rows_returned' => 0, 'admin_user_id' => 1],
        ];

        $now = date('Y-m-d H:i:s');
        foreach ($logs as $log) {
            $log['created_at'] = $now;
            $connection->insert($table, $log);
        }
    }

    /**
     * Generate evenly spread dates over the given number of past days.
     *
     * @return string[]
     */
    private function generateSpreadDates(int $count, int $daysBack): array
    {
        $dates = [];
        $now   = time();
        $step  = (int) (($daysBack * 86400) / max($count, 1));

        for ($i = 0; $i < $count; $i++) {
            $timestamp = $now - ($step * ($count - $i));
            $dates[]   = date('Y-m-d H:i:s', $timestamp);
        }

        return $dates;
    }

    /**
     * Map order status to Magento state.
     */
    private function getStateFromStatus(string $status): string
    {
        return match ($status) {
            'pending'    => 'new',
            'processing' => 'processing',
            'complete'   => 'complete',
            'canceled'   => 'canceled',
            'closed'     => 'closed',
            default      => 'new',
        };
    }
}
