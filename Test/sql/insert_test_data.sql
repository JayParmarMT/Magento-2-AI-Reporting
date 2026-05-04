-- ============================================================================
-- Meetanshi AIReporting — Test Data SQL Script
-- 
-- Run this script directly against your Magento 2 database to insert
-- realistic customer and order test data for AI Reporting module testing.
--
-- Usage: mysql -u <user> -p <database> < insert_test_data.sql
--
-- @category  Meetanshi
-- @package   Meetanshi_AIReporting
-- @copyright Copyright (c) Meetanshi (https://meetanshi.com)
-- ============================================================================

SET @now = NOW();

-- ============================================================================
-- 1. TEST CUSTOMERS (25 customers with varied profiles)
-- ============================================================================

INSERT INTO customer_entity (email, firstname, lastname, group_id, store_id, is_active, gender, dob, created_at, updated_at)
SELECT * FROM (
    SELECT 'john.smith@aireporting-test.com' AS email, 'John' AS firstname, 'Smith' AS lastname, 1 AS group_id, 1 AS store_id, 1 AS is_active, 1 AS gender, '1985-03-15' AS dob, DATE_SUB(@now, INTERVAL 350 DAY) AS created_at, @now AS updated_at UNION ALL
    SELECT 'sarah.johnson@aireporting-test.com', 'Sarah', 'Johnson', 1, 1, 1, 2, '1990-07-22', DATE_SUB(@now, INTERVAL 330 DAY), @now UNION ALL
    SELECT 'michael.williams@aireporting-test.com', 'Michael', 'Williams', 1, 1, 1, 1, '1978-11-08', DATE_SUB(@now, INTERVAL 310 DAY), @now UNION ALL
    SELECT 'emily.brown@aireporting-test.com', 'Emily', 'Brown', 2, 1, 1, 2, '1992-01-30', DATE_SUB(@now, INTERVAL 290 DAY), @now UNION ALL
    SELECT 'david.jones@aireporting-test.com', 'David', 'Jones', 2, 1, 1, 1, '1988-05-12', DATE_SUB(@now, INTERVAL 270 DAY), @now UNION ALL
    SELECT 'jessica.davis@aireporting-test.com', 'Jessica', 'Davis', 1, 1, 1, 2, '1995-09-18', DATE_SUB(@now, INTERVAL 250 DAY), @now UNION ALL
    SELECT 'robert.miller@aireporting-test.com', 'Robert', 'Miller', 1, 1, 1, 1, '1982-12-05', DATE_SUB(@now, INTERVAL 230 DAY), @now UNION ALL
    SELECT 'amanda.wilson@aireporting-test.com', 'Amanda', 'Wilson', 1, 1, 1, 2, '1993-04-25', DATE_SUB(@now, INTERVAL 210 DAY), @now UNION ALL
    SELECT 'james.taylor@aireporting-test.com', 'James', 'Taylor', 2, 1, 1, 1, '1975-08-14', DATE_SUB(@now, INTERVAL 190 DAY), @now UNION ALL
    SELECT 'lisa.anderson@aireporting-test.com', 'Lisa', 'Anderson', 1, 1, 1, 2, '1987-06-03', DATE_SUB(@now, INTERVAL 170 DAY), @now UNION ALL
    SELECT 'chris.thomas@aireporting-test.com', 'Chris', 'Thomas', 1, 1, 1, 1, '1991-02-28', DATE_SUB(@now, INTERVAL 150 DAY), @now UNION ALL
    SELECT 'jennifer.jackson@aireporting-test.com', 'Jennifer', 'Jackson', 1, 1, 1, 2, '1989-10-17', DATE_SUB(@now, INTERVAL 130 DAY), @now UNION ALL
    SELECT 'daniel.white@aireporting-test.com', 'Daniel', 'White', 1, 1, 1, 1, '1996-07-09', DATE_SUB(@now, INTERVAL 110 DAY), @now UNION ALL
    SELECT 'michelle.harris@aireporting-test.com', 'Michelle', 'Harris', 2, 1, 1, 2, '1984-03-21', DATE_SUB(@now, INTERVAL 90 DAY), @now UNION ALL
    SELECT 'kevin.martin@aireporting-test.com', 'Kevin', 'Martin', 1, 1, 1, 1, '1979-11-30', DATE_SUB(@now, INTERVAL 70 DAY), @now UNION ALL
    SELECT 'patricia.garcia@aireporting-test.com', 'Patricia', 'Garcia', 1, 1, 0, 2, '1986-08-07', DATE_SUB(@now, INTERVAL 60 DAY), @now UNION ALL
    SELECT 'mark.martinez@aireporting-test.com', 'Mark', 'Martinez', 1, 1, 1, 1, '1994-01-15', DATE_SUB(@now, INTERVAL 50 DAY), @now UNION ALL
    SELECT 'nancy.robinson@aireporting-test.com', 'Nancy', 'Robinson', 2, 1, 1, 2, '1981-05-22', DATE_SUB(@now, INTERVAL 40 DAY), @now UNION ALL
    SELECT 'steven.clark@aireporting-test.com', 'Steven', 'Clark', 1, 1, 1, 1, '1990-12-11', DATE_SUB(@now, INTERVAL 30 DAY), @now UNION ALL
    SELECT 'karen.rodriguez@aireporting-test.com', 'Karen', 'Rodriguez', 1, 1, 1, 2, '1977-09-04', DATE_SUB(@now, INTERVAL 20 DAY), @now UNION ALL
    SELECT 'brian.lewis@aireporting-test.com', 'Brian', 'Lewis', 0, 1, 1, 1, '1998-04-19', DATE_SUB(@now, INTERVAL 15 DAY), @now UNION ALL
    SELECT 'laura.lee@aireporting-test.com', 'Laura', 'Lee', 0, 1, 1, 2, '1993-06-28', DATE_SUB(@now, INTERVAL 10 DAY), @now UNION ALL
    SELECT 'andrew.walker@aireporting-test.com', 'Andrew', 'Walker', 1, 1, 1, 1, '1983-02-14', DATE_SUB(@now, INTERVAL 7 DAY), @now UNION ALL
    SELECT 'rachel.hall@aireporting-test.com', 'Rachel', 'Hall', 1, 1, 0, 2, '1997-10-06', DATE_SUB(@now, INTERVAL 3 DAY), @now UNION ALL
    SELECT 'thomas.allen@aireporting-test.com', 'Thomas', 'Allen', 1, 1, 1, 1, '1980-07-31', DATE_SUB(@now, INTERVAL 1 DAY), @now
) AS tmp
WHERE NOT EXISTS (SELECT 1 FROM customer_entity WHERE email LIKE '%@aireporting-test.com' LIMIT 1);


-- ============================================================================
-- 2. TEST ORDERS (50 orders with varied statuses, products, regions, coupons)
-- ============================================================================

-- Create a temporary procedure to generate orders
DELIMITER //

DROP PROCEDURE IF EXISTS meetanshi_insert_test_orders//

CREATE PROCEDURE meetanshi_insert_test_orders()
BEGIN
    DECLARE i INT DEFAULT 0;
    DECLARE v_customer_id INT;
    DECLARE v_email VARCHAR(255);
    DECLARE v_firstname VARCHAR(255);
    DECLARE v_lastname VARCHAR(255);
    DECLARE v_status VARCHAR(32);
    DECLARE v_state VARCHAR(32);
    DECLARE v_grand_total DECIMAL(12,4);
    DECLARE v_subtotal DECIMAL(12,4);
    DECLARE v_tax DECIMAL(12,4);
    DECLARE v_shipping DECIMAL(12,4);
    DECLARE v_discount DECIMAL(12,4);
    DECLARE v_qty INT;
    DECLARE v_order_id INT;
    DECLARE v_created_at DATETIME;
    DECLARE v_coupon VARCHAR(32);
    DECLARE v_ship_method VARCHAR(64);
    DECLARE v_ship_desc VARCHAR(128);
    DECLARE v_pay_method VARCHAR(32);
    DECLARE v_city VARCHAR(64);
    DECLARE v_region VARCHAR(64);
    DECLARE v_region_id INT;
    DECLARE v_postcode VARCHAR(10);
    DECLARE v_refunded DECIMAL(12,4);
    DECLARE v_rand INT;

    -- Check if test orders already exist
    IF (SELECT COUNT(*) FROM sales_order WHERE customer_email LIKE '%@aireporting-test.com') > 0 THEN
        LEAVE meetanshi_insert_test_orders;
    END IF;

    WHILE i < 50 DO
        -- Pick random test customer
        SELECT entity_id, email, firstname, lastname
        INTO v_customer_id, v_email, v_firstname, v_lastname
        FROM customer_entity
        WHERE email LIKE '%@aireporting-test.com'
        ORDER BY RAND() LIMIT 1;

        -- Random status
        SET v_rand = FLOOR(RAND() * 8);
        SET v_status = ELT(v_rand + 1, 'pending', 'processing', 'complete', 'complete', 'complete', 'complete', 'canceled', 'closed');
        SET v_state = CASE v_status
            WHEN 'pending' THEN 'new'
            WHEN 'processing' THEN 'processing'
            WHEN 'complete' THEN 'complete'
            WHEN 'canceled' THEN 'canceled'
            WHEN 'closed' THEN 'closed'
            ELSE 'new'
        END;

        -- Random amounts
        SET v_subtotal = ROUND(50 + RAND() * 2000, 2);
        SET v_tax = ROUND(v_subtotal * 0.08, 2);
        SET v_shipping = IF(RAND() > 0.3, ROUND(5 + RAND() * 15, 2), 0);

        -- Random coupon
        SET v_rand = FLOOR(RAND() * 8);
        SET v_coupon = ELT(v_rand + 1, NULL, NULL, NULL, NULL, 'SAVE10', 'WELCOME20', 'SUMMER15', NULL);
        SET v_discount = CASE v_coupon
            WHEN 'SAVE10' THEN ROUND(v_subtotal * 0.10, 2)
            WHEN 'WELCOME20' THEN ROUND(v_subtotal * 0.20, 2)
            WHEN 'SUMMER15' THEN ROUND(v_subtotal * 0.15, 2)
            ELSE 0
        END;

        SET v_grand_total = ROUND(v_subtotal + v_tax + v_shipping - v_discount, 2);
        SET v_qty = FLOOR(1 + RAND() * 5);
        SET v_refunded = IF(v_status = 'closed', v_grand_total, 0);

        -- Random shipping
        SET v_rand = FLOOR(RAND() * 3);
        SET v_ship_method = ELT(v_rand + 1, 'flatrate_flatrate', 'freeshipping_freeshipping', 'tablerate_bestway');
        SET v_ship_desc = ELT(v_rand + 1, 'Flat Rate - Fixed', 'Free Shipping - Free', 'Best Way - Table Rate');

        -- Random payment
        SET v_pay_method = ELT(FLOOR(RAND() * 3) + 1, 'checkmo', 'cashondelivery', 'banktransfer');

        -- Random region
        SET v_rand = FLOOR(RAND() * 10);
        SET v_city = ELT(v_rand + 1, 'New York', 'Los Angeles', 'Chicago', 'Houston', 'Phoenix', 'Philadelphia', 'San Antonio', 'San Diego', 'Dallas', 'Miami');
        SET v_region = ELT(v_rand + 1, 'New York', 'California', 'Illinois', 'Texas', 'Arizona', 'Pennsylvania', 'Texas', 'California', 'Texas', 'Florida');
        SET v_region_id = ELT(v_rand + 1, 43, 12, 23, 57, 4, 51, 57, 12, 57, 18);
        SET v_postcode = ELT(v_rand + 1, '10001', '90001', '60601', '77001', '85001', '19101', '78201', '92101', '75201', '33101');

        -- Spread dates over last 180 days
        SET v_created_at = DATE_SUB(NOW(), INTERVAL FLOOR(RAND() * 180) DAY);

        -- Insert order
        INSERT INTO sales_order (
            store_id, increment_id, status, state,
            customer_id, customer_email, customer_firstname, customer_lastname,
            grand_total, base_grand_total, subtotal, base_subtotal,
            tax_amount, base_tax_amount, shipping_amount, base_shipping_amount,
            discount_amount, base_discount_amount, total_qty_ordered, total_refunded,
            coupon_code, shipping_method, shipping_description,
            created_at, updated_at
        ) VALUES (
            1, CONCAT('1000000', LPAD(i + 1, 2, '0')), v_status, v_state,
            v_customer_id, v_email, v_firstname, v_lastname,
            v_grand_total, v_grand_total, v_subtotal, v_subtotal,
            v_tax, v_tax, v_shipping, v_shipping,
            -v_discount, -v_discount, v_qty, v_refunded,
            v_coupon, v_ship_method, v_ship_desc,
            v_created_at, v_created_at
        );

        SET v_order_id = LAST_INSERT_ID();

        -- Insert 1-3 order items per order
        INSERT INTO sales_order_item (order_id, product_id, product_type, sku, name, qty_ordered, qty_invoiced, qty_shipped, qty_refunded, price, base_price, row_total, base_row_total, discount_amount, created_at, updated_at)
        SELECT v_order_id, FLOOR(1 + RAND() * 100), 'simple', sku, name, qty,
            IF(v_status IN ('complete','closed'), qty, 0),
            IF(v_status = 'complete', qty, 0),
            IF(v_status = 'closed', qty, 0),
            price, price, ROUND(price * qty, 2), ROUND(price * qty, 2), 0,
            v_created_at, v_created_at
        FROM (
            SELECT 'TEST-LAPTOP-001' AS sku, 'ProBook Laptop 15"' AS name, 899.99 AS price, FLOOR(1 + RAND() * 2) AS qty UNION ALL
            SELECT 'TEST-PHONE-001', 'SmartPhone X Pro', 699.99, FLOOR(1 + RAND() * 2) UNION ALL
            SELECT 'TEST-HEADPHONE-001', 'Wireless Noise-Cancel Headphones', 249.99, FLOOR(1 + RAND() * 3) UNION ALL
            SELECT 'TEST-WATCH-001', 'Smart Watch Series 5', 349.99, 1 UNION ALL
            SELECT 'TEST-SPEAKER-001', 'Bluetooth Speaker Pro', 129.99, FLOOR(1 + RAND() * 2) UNION ALL
            SELECT 'TEST-KEYBOARD-001', 'Mechanical Gaming Keyboard', 159.99, 1 UNION ALL
            SELECT 'TEST-MOUSE-001', 'Ergonomic Wireless Mouse', 79.99, FLOOR(1 + RAND() * 3) UNION ALL
            SELECT 'TEST-CHARGER-001', 'USB-C Fast Charger 65W', 39.99, FLOOR(1 + RAND() * 4) UNION ALL
            SELECT 'TEST-CASE-001', 'Premium Phone Case', 29.99, FLOOR(1 + RAND() * 3) UNION ALL
            SELECT 'TEST-CABLE-001', 'Braided USB-C Cable 2m', 14.99, FLOOR(1 + RAND() * 5)
        ) products
        ORDER BY RAND()
        LIMIT FLOOR(1 + RAND() * 3);

        -- Insert billing address
        INSERT INTO sales_order_address (parent_id, address_type, firstname, lastname, email, city, region, region_id, country_id, postcode, telephone)
        VALUES (v_order_id, 'billing', v_firstname, v_lastname, v_email, v_city, v_region, v_region_id, 'US', v_postcode, CONCAT('555-', LPAD(FLOOR(RAND() * 10000), 4, '0')));

        -- Insert shipping address
        INSERT INTO sales_order_address (parent_id, address_type, firstname, lastname, email, city, region, region_id, country_id, postcode, telephone)
        VALUES (v_order_id, 'shipping', v_firstname, v_lastname, v_email, v_city, v_region, v_region_id, 'US', v_postcode, CONCAT('555-', LPAD(FLOOR(RAND() * 10000), 4, '0')));

        -- Insert payment
        INSERT INTO sales_order_payment (parent_id, method, amount_ordered, amount_paid, amount_refunded)
        VALUES (v_order_id, v_pay_method, v_grand_total,
            IF(v_status IN ('complete','closed'), v_grand_total, 0),
            IF(v_status = 'closed', v_grand_total, 0));

        SET i = i + 1;
    END WHILE;
END//

DELIMITER ;

CALL meetanshi_insert_test_orders();
DROP PROCEDURE IF EXISTS meetanshi_insert_test_orders;

-- ============================================================================
-- 3. SAVED REPORTS (5 sample reports)
-- ============================================================================

INSERT INTO meetanshi_aireporting_saved_report (title, nlp_query, sql_query, chart_type, chart_config, admin_user_id, created_at, updated_at)
SELECT * FROM (
    SELECT
        '[Test] Monthly Revenue Trend' AS title,
        'Show me monthly revenue for the last 12 months' AS nlp_query,
        'SELECT DATE_FORMAT(created_at, ''%Y-%m'') as month, ROUND(SUM(grand_total), 2) as revenue, COUNT(*) as orders FROM sales_order WHERE created_at >= DATE_SUB(NOW(), INTERVAL 12 MONTH) AND status NOT IN (''canceled'') GROUP BY month ORDER BY month ASC' AS sql_query,
        'line' AS chart_type,
        '{"xAxis":"month","yAxis":"revenue"}' AS chart_config,
        1 AS admin_user_id,
        @now AS created_at,
        @now AS updated_at
    UNION ALL
    SELECT '[Test] Top 10 Customers by Spend',
        'Who are the top 10 customers by total spending?',
        'SELECT customer_email, CONCAT(customer_firstname, '' '', customer_lastname) as name, ROUND(SUM(grand_total), 2) as total_spent, COUNT(*) as orders FROM sales_order WHERE customer_email IS NOT NULL AND status NOT IN (''canceled'') GROUP BY customer_email, customer_firstname, customer_lastname ORDER BY total_spent DESC LIMIT 10',
        'bar', '{"xAxis":"name","yAxis":"total_spent"}', 1, @now, @now
    UNION ALL
    SELECT '[Test] Orders by Status Distribution',
        'Show order count by status as a pie chart',
        'SELECT status, COUNT(*) as order_count, ROUND(SUM(grand_total), 2) as revenue FROM sales_order GROUP BY status ORDER BY order_count DESC',
        'pie', '{"label":"status","value":"order_count"}', 1, @now, @now
    UNION ALL
    SELECT '[Test] Revenue by Region',
        'What is the revenue breakdown by US state?',
        'SELECT a.region as state, COUNT(DISTINCT o.entity_id) as orders, ROUND(SUM(o.grand_total), 2) as revenue FROM sales_order o JOIN sales_order_address a ON a.parent_id = o.entity_id AND a.address_type = ''shipping'' WHERE o.status NOT IN (''canceled'') AND a.region IS NOT NULL GROUP BY a.region ORDER BY revenue DESC LIMIT 15',
        'bar', '{"xAxis":"state","yAxis":"revenue"}', 1, @now, @now
    UNION ALL
    SELECT '[Test] Customer Lifetime Value Segments',
        'Segment customers by their lifetime value',
        'SELECT CASE WHEN total < 100 THEN ''$0-$99'' WHEN total < 500 THEN ''$100-$499'' WHEN total < 1000 THEN ''$500-$999'' ELSE ''$1000+'' END as segment, COUNT(*) as customers FROM (SELECT customer_email, SUM(grand_total) as total FROM sales_order WHERE customer_email IS NOT NULL AND status NOT IN (''canceled'') GROUP BY customer_email) t GROUP BY segment',
        'pie', '{"label":"segment","value":"customers"}', 1, @now, @now
) AS tmp
WHERE NOT EXISTS (SELECT 1 FROM meetanshi_aireporting_saved_report WHERE title LIKE '[Test]%' LIMIT 1);

-- ============================================================================
-- 4. QUERY LOGS (8 sample log entries)
-- ============================================================================

INSERT INTO meetanshi_aireporting_query_log (nlp_query, sql_query, llm_provider, status, error_message, execution_time_ms, rows_returned, admin_user_id, created_at)
SELECT * FROM (
    SELECT '[Test] Show total revenue this month' AS nlp_query,
        'SELECT SUM(grand_total) as revenue FROM sales_order WHERE MONTH(created_at)=MONTH(CURDATE()) AND status NOT IN (''canceled'')' AS sql_query,
        'groq' AS llm_provider, 'success' AS status, NULL AS error_message, 45 AS execution_time_ms, 1 AS rows_returned, 1 AS admin_user_id, @now AS created_at
    UNION ALL
    SELECT '[Test] List top 5 products by quantity sold',
        'SELECT sku, name, SUM(qty_ordered) as qty FROM sales_order_item GROUP BY sku, name ORDER BY qty DESC LIMIT 5',
        'groq', 'success', NULL, 120, 5, 1, @now
    UNION ALL
    SELECT '[Test] How many customers registered this week?',
        'SELECT COUNT(*) as new_customers FROM customer_entity WHERE YEARWEEK(created_at,1)=YEARWEEK(CURDATE(),1)',
        'groq', 'success', NULL, 30, 1, 1, @now
    UNION ALL
    SELECT '[Test] Delete all orders', NULL,
        'groq', 'error', 'Security violation: AI generated a non-SELECT query (DELETE). Only SELECT queries are allowed.', 15, 0, 1, @now
    UNION ALL
    SELECT '[Test] Show average order value by day of week',
        'SELECT DAYNAME(created_at) as day, ROUND(AVG(grand_total),2) as aov FROM sales_order WHERE status NOT IN (''canceled'') GROUP BY DAYNAME(created_at)',
        'groq', 'success', NULL, 85, 7, 1, @now
    UNION ALL
    SELECT '[Test] Show orders with coupon codes used',
        'SELECT coupon_code, COUNT(*) as times_used, ROUND(SUM(grand_total),2) as revenue FROM sales_order WHERE coupon_code IS NOT NULL GROUP BY coupon_code',
        'groq', 'success', NULL, 55, 3, 1, @now
    UNION ALL
    SELECT '[Test] What is the repeat purchase rate?',
        'SELECT COUNT(*) as repeat_customers FROM (SELECT customer_email, COUNT(*) as cnt FROM sales_order WHERE customer_email IS NOT NULL GROUP BY customer_email HAVING cnt > 1) t',
        'groq', 'success', NULL, 95, 1, 1, @now
    UNION ALL
    SELECT '[Test] Invalid gibberish query xyz123', NULL,
        'groq', 'error', 'AI did not return a valid SELECT query. Please rephrase your question.', 200, 0, 1, @now
) AS tmp
WHERE NOT EXISTS (SELECT 1 FROM meetanshi_aireporting_query_log WHERE nlp_query LIKE '[Test]%' LIMIT 1);

-- ============================================================================
-- Done! Verify the data:
-- ============================================================================
SELECT 'Test Customers' AS data_type, COUNT(*) AS count FROM customer_entity WHERE email LIKE '%@aireporting-test.com'
UNION ALL
SELECT 'Test Orders', COUNT(*) FROM sales_order WHERE customer_email LIKE '%@aireporting-test.com'
UNION ALL
SELECT 'Test Order Items', COUNT(*) FROM sales_order_item WHERE order_id IN (SELECT entity_id FROM sales_order WHERE customer_email LIKE '%@aireporting-test.com')
UNION ALL
SELECT 'Test Saved Reports', COUNT(*) FROM meetanshi_aireporting_saved_report WHERE title LIKE '[Test]%'
UNION ALL
SELECT 'Test Query Logs', COUNT(*) FROM meetanshi_aireporting_query_log WHERE nlp_query LIKE '[Test]%';
