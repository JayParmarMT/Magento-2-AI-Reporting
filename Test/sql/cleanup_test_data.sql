-- ============================================================================
-- Meetanshi AIReporting — Cleanup Test Data SQL Script
--
-- Removes all test data inserted by insert_test_data.sql
-- Safe to run multiple times (idempotent).
--
-- Usage: mysql -u <user> -p <database> < cleanup_test_data.sql
--
-- @category  Meetanshi
-- @package   Meetanshi_AIReporting
-- @copyright Copyright (c) Meetanshi (https://meetanshi.com)
-- ============================================================================

-- Remove test query logs
DELETE FROM meetanshi_aireporting_query_log WHERE nlp_query LIKE '[Test]%';

-- Remove test saved reports
DELETE FROM meetanshi_aireporting_saved_report WHERE title LIKE '[Test]%';

-- Remove test order payments
DELETE FROM sales_order_payment
WHERE parent_id IN (
    SELECT entity_id FROM sales_order WHERE customer_email LIKE '%@aireporting-test.com'
);

-- Remove test order addresses
DELETE FROM sales_order_address
WHERE parent_id IN (
    SELECT entity_id FROM sales_order WHERE customer_email LIKE '%@aireporting-test.com'
);

-- Remove test order items
DELETE FROM sales_order_item
WHERE order_id IN (
    SELECT entity_id FROM sales_order WHERE customer_email LIKE '%@aireporting-test.com'
);

-- Remove test orders
DELETE FROM sales_order WHERE customer_email LIKE '%@aireporting-test.com';

-- Remove test customers
DELETE FROM customer_entity WHERE email LIKE '%@aireporting-test.com';

-- Verify cleanup
SELECT 'Remaining Test Customers' AS check_type, COUNT(*) AS count FROM customer_entity WHERE email LIKE '%@aireporting-test.com'
UNION ALL
SELECT 'Remaining Test Orders', COUNT(*) FROM sales_order WHERE customer_email LIKE '%@aireporting-test.com'
UNION ALL
SELECT 'Remaining Test Saved Reports', COUNT(*) FROM meetanshi_aireporting_saved_report WHERE title LIKE '[Test]%'
UNION ALL
SELECT 'Remaining Test Query Logs', COUNT(*) FROM meetanshi_aireporting_query_log WHERE nlp_query LIKE '[Test]%';
