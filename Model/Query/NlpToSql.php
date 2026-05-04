<?php
/**
 * Meetanshi AIReporting
 *
 * @category  Meetanshi
 * @package   Meetanshi_AIReporting
 * @copyright Copyright (c) Meetanshi (https://meetanshi.com)
 */

declare(strict_types=1);

namespace Meetanshi\AIReporting\Model\Query;

use Meetanshi\AIReporting\Model\LLM\ProviderPool;
use Meetanshi\AIReporting\Exception\LlmException;

class NlpToSql
{
    /**
     * Key Magento 2 tables exposed to the AI for context.
     * Only read-relevant tables are listed for security.
     */
    private const SCHEMA_CONTEXT = <<<SCHEMA
Magento 2 MySQL Database Schema (READ-ONLY, SELECT queries only):

ORDERS:
- sales_order (entity_id, increment_id, store_id, status, state, customer_id, customer_email, customer_firstname, customer_lastname, grand_total, subtotal, tax_amount, shipping_amount, discount_amount, total_qty_ordered, created_at, updated_at, coupon_code, shipping_method)
- sales_order_item (item_id, order_id, product_id, sku, name, qty_ordered, qty_invoiced, qty_shipped, qty_refunded, price, base_price, row_total, discount_amount, created_at)
- sales_order_address (entity_id, parent_id, address_type, firstname, lastname, email, city, region, region_id, country_id, postcode, telephone)
- sales_order_payment (entity_id, parent_id, method, amount_ordered, amount_paid, amount_refunded)

CUSTOMERS:
- customer_entity (entity_id, email, firstname, lastname, group_id, store_id, created_at, updated_at, is_active, gender, dob)
- customer_address_entity (entity_id, parent_id, city, region, country_id, postcode, telephone)

PRODUCTS:
- catalog_product_entity (entity_id, sku, type_id, created_at, updated_at)
- catalog_product_entity_varchar (entity_id, attribute_id, store_id, value) -- product name, description etc.
- catalog_product_entity_decimal (entity_id, attribute_id, store_id, value) -- price, weight etc.
- catalog_product_entity_int (entity_id, attribute_id, store_id, value) -- status, visibility etc.
- cataloginventory_stock_item (item_id, product_id, qty, is_in_stock, min_qty)

CATEGORIES:
- catalog_category_entity (entity_id, parent_id, path, level, created_at, updated_at)
- catalog_category_product (category_id, product_id, position)

REVIEWS:
- review (review_id, created_at, entity_id, entity_pk_value, status_id)
- review_detail (detail_id, review_id, store_id, title, detail, nickname, customer_id)
- rating_option_vote (vote_id, option_id, remote_ip, percent, value, review_id, entity_pk_value)

WISHLISTS:
- wishlist (wishlist_id, customer_id, shared, updated_at)
- wishlist_item (wishlist_item_id, wishlist_id, product_id, store_id, added_at, qty)

COUPONS / CART RULES:
- salesrule (rule_id, name, description, from_date, to_date, uses_per_customer, is_active, coupon_type, discount_amount, discount_qty, discount_step)
- salesrule_coupon (coupon_id, rule_id, code, usage_limit, usage_per_customer, times_used, created_at)

STORES:
- store (store_id, code, name, website_id, group_id, is_active)
- store_website (website_id, code, name)

Useful attribute_ids (catalog_eav_attribute):
- Product name attribute_id = 73
- Product price attribute_id = 75
- Product status attribute_id = 97
- Product visibility attribute_id = 99

SCHEMA;

    public function __construct(
        private readonly ProviderPool $providerPool
    ) {
    }

    /**
     * Convert a natural language question to a MySQL SELECT query.
     *
     * @param string $nlpQuery
     * @return string Raw SQL query
     * @throws LlmException
     */
    public function convert(string $nlpQuery): string
    {
        $prompt = $this->buildPrompt($nlpQuery);
        $provider = $this->providerPool->getActiveProvider();
        $rawSql = $provider->complete($prompt);

        return $this->sanitizeSql($rawSql);
    }

    /**
     * Build the full prompt with schema context.
     */
    private function buildPrompt(string $nlpQuery): string
    {
        return self::SCHEMA_CONTEXT . "\n\n"
            . "Convert the following question into a single valid MySQL SELECT query.\n"
            . "Rules:\n"
            . "- Use only SELECT statements. Never use INSERT, UPDATE, DELETE, DROP, ALTER, TRUNCATE, or any DDL/DML.\n"
            . "- Always add a LIMIT clause (max 500 rows) unless the query is an aggregate (COUNT, SUM, AVG).\n"
            . "- Use table aliases for readability.\n"
            . "- Always include descriptive columns (like name, title, or date) for better chart labels and ensure relevant numeric metrics are included for plotting.\n"
            . "- Return only the raw SQL. No explanation, no markdown, no code fences.\n\n"
            . "Question: " . $nlpQuery;
    }

    /**
     * Sanitize and validate the SQL returned by the LLM.
     *
     * @throws LlmException
     */
    private function sanitizeSql(string $sql): string
    {
        // Strip markdown code fences if LLM included them
        $sql = preg_replace('/```(?:sql)?\s*/i', '', $sql);
        $sql = preg_replace('/```/', '', $sql);
        $sql = trim($sql);

        // Remove trailing semicolons
        $sql = rtrim($sql, ';');

        // Security: block any non-SELECT statements
        $upperSql = strtoupper(ltrim($sql));
        $forbidden = ['INSERT', 'UPDATE', 'DELETE', 'DROP', 'ALTER', 'TRUNCATE', 'CREATE', 'REPLACE', 'GRANT', 'REVOKE'];

        foreach ($forbidden as $keyword) {
            if (str_starts_with($upperSql, $keyword)) {
                throw new LlmException(
                    __('Security violation: AI generated a non-SELECT query (%1). Only SELECT queries are allowed.', $keyword)
                );
            }
        }

        if (!str_starts_with($upperSql, 'SELECT') && !str_starts_with($upperSql, 'WITH')) {
            throw new LlmException(__('AI did not return a valid SELECT query. Please rephrase your question.'));
        }

        return $sql;
    }
}
