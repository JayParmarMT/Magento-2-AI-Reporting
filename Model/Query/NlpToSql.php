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

use Magento\Framework\App\ResourceConnection;
use Meetanshi\AIReporting\Model\LLM\ProviderPool;
use Meetanshi\AIReporting\Model\Report\ReportContext;
use Meetanshi\AIReporting\Model\Schema\ModuleCatalog;
use Meetanshi\AIReporting\Model\Schema\SchemaCatalog;
use Meetanshi\AIReporting\Exception\DirectAnswerException;
use Meetanshi\AIReporting\Exception\LlmException;
use Meetanshi\AIReporting\Exception\QueryException;

class NlpToSql
{
    /**
     * Core Magento 2 tables — comprehensive schema for the LLM.
     */
    private const SCHEMA_CONTEXT = <<<'SCHEMA'
Magento 2 MySQL Database Schema (READ-ONLY, SELECT queries only):

═══ ORDERS & SALES ═══
- sales_order (entity_id, increment_id, store_id, status, state, customer_id, customer_email, customer_firstname, customer_lastname, grand_total, base_grand_total, subtotal, tax_amount, shipping_amount, discount_amount, total_qty_ordered, total_refunded, created_at, updated_at, coupon_code, shipping_method, shipping_description)
- sales_order_item (item_id, order_id, product_id, product_type, sku, name, qty_ordered, qty_invoiced, qty_shipped, qty_refunded, price, base_price, row_total, discount_amount, created_at)
- sales_order_address (entity_id, parent_id, address_type, firstname, lastname, email, city, region, region_id, country_id, postcode, telephone)
- sales_order_payment (entity_id, parent_id, method, amount_ordered, amount_paid, amount_refunded)
- sales_invoice (entity_id, order_id, increment_id, state, grand_total, created_at)
- sales_creditmemo (entity_id, order_id, increment_id, state, grand_total, created_at)
- sales_shipment (entity_id, order_id, increment_id, created_at)
- quote (entity_id, store_id, is_active, customer_id, customer_email, grand_total, items_count, created_at, updated_at) -- shopping carts / abandoned carts

═══ CUSTOMERS ═══
- customer_entity (entity_id, email, group_id, store_id, website_id, created_at, updated_at, is_active)
  IMPORTANT: customer_entity does NOT reliably have firstname/lastname/gender/dob columns — those are EAV
  attributes and are missing on many stores. NEVER select ce.firstname or ce.lastname from customer_entity.
  To show a customer's name, use sales_order.customer_firstname and sales_order.customer_lastname
  (always present), joining on sales_order.customer_id = customer_entity.entity_id when needed.
  If you truly need the registered name and there are no orders, read it from customer_entity_varchar:
  JOIN customer_entity_varchar cf ON cf.entity_id = ce.entity_id AND cf.attribute_id = (SELECT attribute_id
  FROM eav_attribute WHERE entity_type_id = (SELECT entity_type_id FROM eav_entity_type WHERE entity_type_code='customer')
  AND attribute_code='firstname').
- customer_entity_varchar (value_id, entity_id, attribute_id, value) -- EAV: customer firstname, lastname, etc.
- customer_address_entity (entity_id, parent_id, firstname, lastname, city, region, region_id, country_id, postcode, telephone, street)
- customer_group (customer_group_id, customer_group_code, tax_class_id)

═══ PRODUCTS ═══
- catalog_product_entity (entity_id, sku, type_id, attribute_set_id, created_at, updated_at)
- catalog_product_entity_varchar (value_id, entity_id, attribute_id, store_id, value) -- product name, url_key, description, meta_title
- catalog_product_entity_decimal (value_id, entity_id, attribute_id, store_id, value) -- price, special_price, weight, cost
- catalog_product_entity_int (value_id, entity_id, attribute_id, store_id, value) -- status, visibility, tax_class_id
- catalog_product_entity_text (value_id, entity_id, attribute_id, store_id, value) -- description, short_description
- cataloginventory_stock_item (item_id, product_id, qty, is_in_stock, min_qty, min_sale_qty, max_sale_qty)
- catalog_product_relation (parent_id, child_id)
- catalog_product_super_link (link_id, product_id, parent_id)

═══ CATEGORIES ═══
- catalog_category_entity (entity_id, parent_id, path, level, position, children_count, created_at, updated_at)
- catalog_category_product (category_id, product_id, position)
- catalog_category_entity_varchar (value_id, entity_id, attribute_id, store_id, value)

═══ EAV ATTRIBUTES ═══
- eav_attribute (attribute_id, entity_type_id, attribute_code, backend_type, frontend_input, frontend_label, is_required, is_user_defined)
- eav_entity_type (entity_type_id, entity_type_code, entity_model, entity_table)
- catalog_eav_attribute (attribute_id, is_visible, is_searchable, is_filterable, is_comparable, used_in_product_listing, used_for_sort_by)

═══ REVIEWS & RATINGS ═══
- review (review_id, created_at, entity_id, entity_pk_value, status_id)
- review_detail (detail_id, review_id, store_id, title, detail, nickname, customer_id)
- rating_option_vote (vote_id, option_id, remote_ip, percent, value, review_id, entity_pk_value)

═══ WISHLISTS ═══
- wishlist (wishlist_id, customer_id, shared, updated_at)
- wishlist_item (wishlist_item_id, wishlist_id, product_id, store_id, added_at, qty)

═══ COUPONS & CART RULES ═══
- salesrule (rule_id, name, description, from_date, to_date, uses_per_customer, is_active, coupon_type, simple_action, discount_amount, discount_qty, times_used)
- salesrule_coupon (coupon_id, rule_id, code, usage_limit, usage_per_customer, times_used, is_primary, created_at)

═══ CMS ═══
- cms_page (page_id, title, page_layout, identifier, content_heading, is_active, creation_time, update_time)
- cms_block (block_id, title, identifier, is_active, creation_time, update_time)

═══ URL REWRITES & SEO ═══
- url_rewrite (url_rewrite_id, entity_type, entity_id, request_path, target_path, redirect_type, store_id, is_autogenerated)

═══ STORES & WEBSITES ═══
- store (store_id, code, name, website_id, group_id, is_active, sort_order)
- store_group (group_id, website_id, name, root_category_id, default_store_id, code)
- store_website (website_id, code, name, sort_order, default_group_id, is_default)

═══ SYSTEM CONFIGURATION ═══
- core_config_data (config_id, scope, scope_id, path, value, updated_at)

  KEY CONFIG PATHS (use these exact paths in WHERE clauses):

  -- VERSION & DEPLOY --
  Magento version is in setup_module table where module='Magento_Version', check schema_version column.

  -- JS / CSS / HTML OPTIMIZATION --
  JS minification: path='dev/js/minify_files' (1=enabled, 0=disabled)
  CSS minification: path='dev/css/minify_files' (1=enabled, 0=disabled)
  JS bundling: path='dev/js/enable_js_bundling' (1=enabled, 0=disabled)
  JS merge: path='dev/js/merge_files' (1=enabled, 0=disabled)
  CSS merge: path='dev/css/merge_css_files' (1=enabled, 0=disabled)
  HTML minification: path='dev/template/minify_html' (1=enabled, 0=disabled)
  Static file signing: path='dev/static/sign' (1=enabled, 0=disabled)
  Move JS to bottom: path='dev/js/move_script_to_bottom' (1=enabled, 0=disabled)

  -- CACHING --
  Full page cache backend: path='system/full_page_cache/caching_application' (1=Built-in, 2=Varnish)
  Varnish backend host: path='system/full_page_cache/varnish/backend_host'
  Varnish backend port: path='system/full_page_cache/varnish/backend_port'
  Varnish TTL: path='system/full_page_cache/ttl'

  -- CDN --
  CDN for static files: path='web/unsecure/base_static_url' or path='web/secure/base_static_url'
  CDN for media files: path='web/unsecure/base_media_url' or path='web/secure/base_media_url'
  If value is empty or starts with '{{', CDN is NOT configured. If it has a full URL like https://cdn.example.com/, CDN IS configured.

  -- REDIS / SESSION / CACHE BACKEND --
  Session save method: path='web/session/save' (values: files, redis, db)
  Redis session host: path='session/redis/host'
  Redis session port: path='session/redis/port'
  Redis session database: path='session/redis/database'
  Note: Redis cache config is usually in app/etc/env.php, not in DB. But session config may be in DB.

  -- BASE URLs & HTTPS --
  Base URL (unsecure): path='web/unsecure/base_url'
  Base URL (secure): path='web/secure/base_url'
  Use HTTPS on frontend: path='web/secure/use_in_frontend' (1=yes)
  Use HTTPS in admin: path='web/secure/use_in_adminhtml' (1=yes)

  -- SEARCH ENGINE --
  Search engine: path='catalog/search/engine' (values: elasticsearch7, elasticsearch8, opensearch, mysql)
  Elasticsearch host: path='catalog/search/elasticsearch7_server_hostname'
  Elasticsearch port: path='catalog/search/elasticsearch7_server_port'
  Elasticsearch index prefix: path='catalog/search/elasticsearch7_index_prefix'

  -- CATALOG --
  Flat catalog product: path='catalog/frontend/flat_catalog_product' (1=enabled)
  Flat catalog category: path='catalog/frontend/flat_catalog_category' (1=enabled)
  Catalog price scope: path='catalog/price/scope' (0=global, 1=website)
  Products per page: path='catalog/frontend/grid_per_page'
  Default sort by: path='catalog/frontend/default_sort_by'

  -- EMAIL / SMTP --
  Async email: path='sales_email/general/async_sending' (1=enabled)
  SMTP host: path='system/smtp/host'
  SMTP port: path='system/smtp/port'
  Store email: path='trans_email/ident_general/email'
  Store name: path='trans_email/ident_general/name'

  -- PAYMENT METHODS --
  Check if payment enabled: path LIKE 'payment/%/active' (1=enabled)
  PayPal: path LIKE 'payment/paypal%'
  Stripe: path LIKE 'payment/stripe%'
  Braintree: path LIKE 'payment/braintree%'

  -- SHIPPING METHODS --
  Check if carrier enabled: path LIKE 'carriers/%/active' (1=enabled)
  Free shipping: path='carriers/freeshipping/active'
  Flat rate: path='carriers/flatrate/active'
  Table rates: path='carriers/tablerate/active'
  UPS: path='carriers/ups/active'
  FedEx: path='carriers/fedex/active'
  DHL: path='carriers/dhl/active'

  -- SECURITY --
  Admin session lifetime: path='admin/security/session_lifetime'
  Admin password lifetime: path='admin/security/password_lifetime'
  Admin lockout failures: path='admin/security/lockout_failures'
  Two-factor auth: path LIKE 'twofactorauth/%'
  CAPTCHA enabled: path='customer/captcha/enable' or path='admin/captcha/enable'

  -- ANALYTICS --
  Google Analytics: path='google/analytics/active' (1=enabled)
  GA account: path='google/analytics/account'
  GTM: path='google/gtm/container_id'

  -- GENERAL --
  Store name: path='general/store_information/name'
  Store phone: path='general/store_information/phone'
  Country: path='general/country/default'
  Timezone: path='general/locale/timezone'
  Locale: path='general/locale/code'
  Currency base: path='currency/options/base'
  Currency default: path='currency/options/default'
  Weight unit: path='general/locale/weight_unit'

  -- NEWSLETTER --
  Newsletter enabled: path='newsletter/general/active' (1=enabled)
  Newsletter subscription confirm: path='newsletter/subscription/confirm' (1=yes)

  -- SITEMAP --
  Sitemap enabled: path='sitemap/generate/enabled' (1=enabled)
  Sitemap frequency: path='sitemap/generate/frequency'

═══ MODULES & EXTENSIONS ═══
- setup_module (module, schema_version, data_version)
  IMPORTANT: The column is "module" NOT "module_name".
  All installed modules are listed here. Third-party modules have vendor prefix like Vendor_ModuleName.
  Magento core modules start with "Magento_".
  To count third-party extensions: WHERE module NOT LIKE 'Magento_%'
  To find a specific vendor: WHERE module LIKE 'Vendor_%'

═══ INDEXERS ═══
- indexer_state (state_id, indexer_id, status, updated, hash_config) -- status values: valid, invalid, working
- mview_state (state_id, view_id, mode, status, updated, version_id)

═══ CRON JOBS ═══
- cron_schedule (schedule_id, job_code, status, messages, created_at, scheduled_at, executed_at, finished_at) -- status: pending, running, success, missed, error

═══ EMAIL & NEWSLETTER ═══
- newsletter_subscriber (subscriber_id, store_id, customer_id, subscriber_email, subscriber_status, change_status_at)

═══ SEARCH ═══
- search_query (query_id, query_text, num_results, popularity, store_id, updated_at)


SCHEMA;

    /**
     * Live tables (beyond the core list above) sent with each question.
     */
    private const MAX_RELEVANT_TABLES = 8;

    /**
     * Prefix the AI uses when the question cannot be answered with SQL.
     */
    private const DIRECT_ANSWER_PREFIX = 'ANSWER:';

    /**
     * Question words too common to point at a specific column of a core table.
     */
    private const GENERIC_COLUMN_TERMS = [
        'customer', 'product', 'amount', 'status', 'created', 'updated', 'total', 'order', 'store', 'price',
        'quantity', 'number', 'address', 'shipping', 'payment', 'invoice', 'discount', 'refund', 'refunded',
        'method', 'value', 'email', 'active', 'enabled', 'entity', 'attribute', 'category', 'website',
        'currency', 'month', 'monthly', 'weekly', 'yearly', 'daily', 'recent', 'latest', 'revenue', 'sale',
    ];

    /**
     * Optional first line of the AI reply that states how it read an ambiguous question.
     */
    private const ASSUMPTION_PATTERN = '/^[ \t]*(?:--[ \t]*)?(?:\*\*)?ASSUMPTIONS?[ \t]*:[ \t]*(?:\*\*)?[ \t]*(.*)$\R?/mi';

    /**
     * Saved reports included as examples with each question.
     */
    private const MAX_EXAMPLES = 3;

    private const SYSTEM_INTRO = "You are a Magento 2 database expert. You turn a store admin's question into one read-only "
        . "MySQL SELECT query on this store's database.";

    private const WRITE_STATEMENT_PATTERN =
        '/^\s*(INSERT|UPDATE|DELETE|DROP|ALTER|TRUNCATE|CREATE|REPLACE|GRANT|REVOKE|RENAME|SET|CALL|LOAD|LOCK|UNLOCK|HANDLER'
        . '|SHOW|DESCRIBE|EXPLAIN|USE)\b/im';

    /**
     * Core schema lines checked against the live database, and their columns (per request).
     */
    private ?string $verifiedSchema = null;

    /**
     * @var array<string, string[]>|null
     */
    private ?array $curatedColumns = null;

    private ?string $systemPrompt = null;

    /**
     * How the AI read the last question, when it had to interpret it ('' otherwise).
     */
    private string $lastAssumption = '';

    public function __construct(
        private readonly ProviderPool $providerPool,
        private readonly ResourceConnection $resourceConnection,
        private readonly ReportContext $reportContext,
        private readonly SqlGuard $sqlGuard,
        private readonly SchemaCatalog $schemaCatalog,
        private readonly ModuleCatalog $moduleCatalog,
        private readonly ExampleFinder $exampleFinder
    ) {
    }

    /**
     * Convert a natural language question to a MySQL SELECT query.
     *
     * @param string $nlpQuery
     * @param array<int, array{question: string, sql: string}> $history earlier turns of a chat, oldest first
     * @return string Raw SQL query
     * @throws DirectAnswerException when the AI answers in plain text (question is not about stored data)
     * @throws LlmException
     */
    public function convert(string $nlpQuery, array $history = []): string
    {
        $prompt   = $this->buildPrompt($nlpQuery, $history);
        $provider = $this->providerPool->getActiveProvider();

        return $this->toSql($provider->complete($prompt, $this->getSystemPrompt()));
    }

    /**
     * Ask the LLM to correct a query the database rejected (unknown column, syntax, …).
     * The real columns of the tables it used are included so it can pick existing ones.
     *
     * @param array<int, array{question: string, sql: string}> $history
     * @throws LlmException
     */
    public function repair(string $nlpQuery, string $failedSql, string $databaseError, array $history = []): string
    {
        $prompt = $this->buildPrompt($nlpQuery, $history) . "\n\n"
            . "Your previous SQL for this question failed.\n"
            . "Previous SQL: " . preg_replace('/\s+/', ' ', $failedSql) . "\n"
            . "Database error: " . $databaseError . "\n"
            . $this->describeTablesUsed($nlpQuery, $failedSql, $databaseError)
            . "Return a corrected single SELECT query that uses only the tables and columns listed in the schema. "
            . "Return only the raw SQL.";

        // A corrected query usually keeps the reading of the question without restating it
        $previousAssumption = $this->lastAssumption;
        $sql = $this->toSql($this->providerPool->getActiveProvider()->complete($prompt, $this->getSystemPrompt()));
        if ($this->lastAssumption === '') {
            $this->lastAssumption = $previousAssumption;
        }

        return $sql;
    }

    /**
     * How the AI interpreted the last converted question ("'returns' read as credit memos"), or ''.
     */
    public function getLastAssumption(): string
    {
        return $this->lastAssumption;
    }

    /**
     * Turn the AI reply into validated SQL (or a direct answer).
     *
     * @throws LlmException
     */
    private function toSql(string $response): string
    {
        $response = $this->takeAssumption($response);
        $text = trim((string) preg_replace('/^```\w*\s*|\s*```$/', '', trim($response)));
        if (stripos($text, self::DIRECT_ANSWER_PREFIX) === 0) {
            $answer = trim(substr($text, strlen(self::DIRECT_ANSWER_PREFIX)));
            if ($answer !== '') {
                throw new DirectAnswerException(__('%1', mb_substr($answer, 0, 2000)));
            }
        }

        return $this->sanitizeSql($this->applyTablePrefix($this->extractSql($response)));
    }

    /**
     * Remember the reply's "ASSUMPTION: …" line(s) and return the reply without them.
     */
    private function takeAssumption(string $response): string
    {
        $this->lastAssumption = '';
        if (!preg_match_all(self::ASSUMPTION_PATTERN, $response, $m)) {
            return $response;
        }
        $this->lastAssumption = mb_substr(trim(implode(' ', array_map('trim', $m[1]))), 0, 500);

        return (string) preg_replace(self::ASSUMPTION_PATTERN, '', $response);
    }

    /**
     * Take the query out of a reply that wraps it in prose or a code block
     * ("Here is the query: SELECT …", "SELECT …;\n\nThis query counts …").
     * A reply that starts with a write statement is left as is, for SqlGuard to reject.
     */
    private function extractSql(string $response): string
    {
        $text = trim($response);

        if (preg_match('/```(?:sql|mysql)?\s*(.*?)```/is', $text, $m) && preg_match('/^\s*(SELECT|WITH)\b/i', $m[1])) {
            return $this->cutTrailingProse(trim($m[1]));
        }

        if (!preg_match('/^\s*(SELECT|WITH)\b/i', $text)
            && preg_match('/(?:^|\n|:)\s*((?:SELECT|WITH)\b)/i', $text, $m, PREG_OFFSET_CAPTURE)
            && !preg_match(self::WRITE_STATEMENT_PATTERN, substr($text, 0, $m[1][1]))
        ) {
            $text = substr($text, $m[1][1]);
        }

        return $this->cutTrailingProse($text);
    }

    /**
     * Drop an explanation that follows "…;" on a new line (but never a second statement).
     */
    private function cutTrailingProse(string $sql): string
    {
        if (preg_match('/;\s*\n\s*(?=\S)/', $sql, $m, PREG_OFFSET_CAPTURE)) {
            $rest = substr($sql, $m[0][1] + strlen($m[0][0]));
            if (!preg_match('/^(SELECT|WITH)\b/i', $rest) && !preg_match(self::WRITE_STATEMENT_PATTERN, $rest)) {
                return substr($sql, 0, $m[0][1]);
            }
        }

        return $sql;
    }

    /**
     * Prepend the configured DB table prefix to bare Magento table names in AI-generated SQL.
     *
     * The AI is given unprefixed schema names (e.g. "sales_order"). On stores that use a
     * table prefix (db/table_prefix in env.php), the real table is "<prefix>sales_order".
     * This rewrites table references after FROM/JOIN so the query runs on prefixed stores.
     * No-op when no prefix is configured.
     */
    private function applyTablePrefix(string $sql): string
    {
        $prefix = (string) $this->resourceConnection->getTableName('');
        if ($prefix === '') {
            return $sql;
        }

        // Rewrite "FROM <table>" and "JOIN <table>" references (optionally backtick-quoted).
        // Skip names that already start with the prefix, and skip qualified names (db.table).
        return (string) preg_replace_callback(
            '/\b(FROM|JOIN)\s+`?([a-z0-9_]+)`?(?!\s*\.)/i',
            function (array $m) use ($prefix) {
                $keyword = $m[1];
                $table   = $m[2];
                if (str_starts_with($table, $prefix)) {
                    return $m[0];
                }
                return $keyword . ' ' . $prefix . $table;
            },
            $sql
        );
    }

    /**
     * Instructions and the core schema — identical for every question on this store, so providers
     * can cache it (Claude prompt caching, OpenAI/Gemini prefix caching).
     */
    private function getSystemPrompt(): string
    {
        if ($this->systemPrompt !== null) {
            return $this->systemPrompt;
        }

        return $this->systemPrompt = self::SYSTEM_INTRO . "\n\n" . $this->getVerifiedSchema() . "\n"
            . "Rules:\n"
            . "- Use only SELECT statements. Never use INSERT, UPDATE, DELETE, DROP, ALTER, TRUNCATE, or any DDL/DML.\n"
            . "- Always add a LIMIT clause (max 500 rows) unless the query is an aggregate (COUNT, SUM, AVG).\n"
            . "- Use table aliases for readability.\n"
            . "- Never use SELECT * or alias.*; always list the columns you need explicitly.\n"
            . "- CRITICAL: Only reference tables and columns that are listed — in the schema above, or in the "
            . "'MORE TABLES FROM THIS STORE' section that comes with a question. Never invent or guess names.\n"
            . "- The customer_entity table's primary key is 'entity_id' (NOT 'customer_id'). "
            . "Only sales_order, quote, wishlist, review_detail and newsletter_subscriber have a 'customer_id' column.\n"
            . "- To count/list customers, use customer_entity.entity_id, customer_entity.email and customer_entity.created_at.\n"
            . "- NEVER select firstname/lastname/gender/dob from customer_entity — those columns do not exist on many stores. "
            . "For a customer's name use sales_order.customer_firstname and sales_order.customer_lastname "
            . "(join sales_order.customer_id = customer_entity.entity_id), or the customer_entity_varchar EAV table.\n"
            . "- 'New customers this week/today/this month' = filter customer_entity.created_at by the given period.\n"
            . "- Never query admin users, roles, OAuth/integration tokens, sessions, passwords or payment card data.\n"
            . "- Always include descriptive columns (like name, title, or date) for better chart labels and ensure relevant numeric metrics are included for plotting.\n"
            . "- For configuration questions, query core_config_data using the exact path values listed above.\n"
            . "- For module/extension questions, query setup_module table. The column is 'module' (NOT 'module_name').\n"
            . "- For yes/no config checks (like is CDN enabled, is minification on, is Redis used), return the path and value columns so the answer is clear.\n"
            . "- Tables under 'MORE TABLES FROM THIS STORE' are real tables of this store's database (including "
            . "third-party modules, marked [module Vendor_Name]). For questions about those features use them with "
            . "their exact table and column names.\n"
            . "- Produce ONE complete, syntactically valid MySQL SELECT statement. Do not add a trailing semicolon, "
            . "trailing comma, trailing comment, or any text after the final clause. The LIMIT clause (when used) "
            . "must be the very last clause and use the form 'LIMIT <number>'.\n"
            . "- Never substitute an unrelated table for data this store does not record. If the question is about a "
            . "feature or kind of record that no listed table holds (for example loyalty cards on a store without a "
            . "loyalty module), do not write SQL: reply with '" . self::DIRECT_ANSWER_PREFIX . " ' and say that this "
            . "store's database has no such data.\n"
            . "- If you must read a word of the question as differently named data (for example 'returns' as credit "
            . "memos), first write one line 'ASSUMPTION: <short explanation for the admin>' and put the SQL on the next "
            . "line. Write no ASSUMPTION line when the question maps directly onto the schema.\n"
            . "- Only if NO select query over the listed tables can answer the question (a greeting, general Magento "
            . "advice or how-to, or data this database does not store), do not write SQL: reply with '"
            . self::DIRECT_ANSWER_PREFIX . " ' followed by a short, helpful plain-text reply.\n"
            . "- Otherwise return only the raw SQL (after the optional ASSUMPTION line). No explanation, no markdown, "
            . "no code fences.";
    }

    /**
     * The per-question part of the prompt: store facts (they include today's date), the live tables
     * matching the question, similar saved reports, earlier chat turns and the question itself.
     *
     * @param array<int, array{question: string, sql: string}> $history
     */
    private function buildPrompt(string $nlpQuery, array $history = []): string
    {
        // Follow-ups ("now by month") name no tables themselves: match tables on the earlier question too
        $previous      = $history ? (string) end($history)['question'] : '';
        $retrievalText = trim($previous . "\n" . $nlpQuery);

        return $this->getStoreFacts()
            . $this->getRelevantSchema($retrievalText, $nlpQuery)
            . $this->getExamples($nlpQuery)
            . $this->describeHistory($history)
            . "\nConvert the following question into a single valid MySQL SELECT query, following the rules.\n"
            . "Question: " . $nlpQuery;
    }

    /**
     * Saved reports whose question resembles this one: real, admin-approved SQL for this store.
     */
    private function getExamples(string $question): string
    {
        $lines = [];
        foreach ($this->exampleFinder->find($question, self::MAX_EXAMPLES) as $example) {
            $lines[] = 'Q: ' . $example['question'] . "\nSQL: " . preg_replace('/\s+/', ' ', $example['sql']);
        }

        return $lines
            ? "\n═══ SAVED REPORTS SIMILAR TO THIS QUESTION (saved by an admin of this store — reuse their joins and "
                . "filters; their dates are from when they were saved, so take dates for this question from STORE FACTS) ═══\n"
                . implode("\n", $lines) . "\n"
            : '';
    }

    /**
     * Earlier questions of the chat, so a follow-up ("and last month?", "only for Germany") can build on them.
     *
     * @param array<int, array{question: string, sql: string}> $history
     */
    private function describeHistory(array $history): string
    {
        if (!$history) {
            return '';
        }

        $lines = [];
        foreach ($history as $turn) {
            $lines[] = 'Q: ' . $turn['question'] . "\n"
                . ($turn['sql'] !== '' ? 'SQL: ' . preg_replace('/\s+/', ' ', $turn['sql']) : '(answered without SQL)');
        }

        return "\n═══ EARLIER IN THIS CONVERSATION (oldest first — the new question may refer to these; reuse their "
            . "tables and filters where it does) ═══\n" . implode("\n", $lines) . "\n";
    }

    /**
     * The core schema list with every table and column checked against the live database:
     * columns this store does not have (e.g. entity_id on Adobe Commerce row_id tables) and
     * tables that do not exist are left out, so the AI is never shown a name that would fail.
     */
    private function getVerifiedSchema(): string
    {
        if ($this->verifiedSchema !== null) {
            return $this->verifiedSchema;
        }

        $tables = $this->schemaCatalog->getTables();
        if (!$tables) {
            return $this->verifiedSchema = self::SCHEMA_CONTEXT;
        }

        $this->curatedColumns = [];
        $this->verifiedSchema = (string) preg_replace_callback(
            '/^- (\w+) \(([^)]*)\)(.*)\n/m',
            function (array $m) use ($tables): string {
                $table = strtolower($m[1]);
                if (!isset($tables[$table])) {
                    return '';
                }
                $live    = array_flip($tables[$table]['columns']);
                $columns = array_values(array_filter(
                    array_map('trim', explode(',', $m[2])),
                    static fn (string $column) => isset($live[strtolower($column)])
                ));
                if (!$columns) {
                    return $m[0];
                }
                $this->curatedColumns[$table] = array_map('strtolower', $columns);

                return '- ' . $m[1] . ' (' . implode(', ', $columns) . ')' . $m[3] . "\n";
            },
            self::SCHEMA_CONTEXT
        );

        return $this->verifiedSchema;
    }

    /**
     * Live tables and columns that match the question and are not in the core list:
     *  - tables of a module the question names (e.g. "size chart" → Meetanshi_SizeChart's tables),
     *  - other tables whose name or comment matches the question (core, Adobe Commerce or third-party),
     *  - columns that modules add to core tables (e.g. sales_order.some_flag) when the question mentions them,
     *  - words of the question that match nothing in the schema (data this store may not record).
     *
     * @param string $question text to match tables on (the question, plus the previous one in a chat)
     * @param string $currentQuestion the question alone
     */
    private function getRelevantSchema(string $question, string $currentQuestion = ''): string
    {
        try {
            $this->getVerifiedSchema();
            $curated = $this->curatedColumns ?? [];

            $priority = [];
            $added    = []; // table => [module => columns]
            foreach (array_slice($this->moduleCatalog->detect($question), 0, 3) as $module) {
                foreach ($this->moduleCatalog->getOwnedTables($module) as $table) {
                    $priority[] = $table;
                }
                foreach ($this->moduleCatalog->getExtendedColumns($module) as $table => $columns) {
                    $added[$table][$module] = $columns;
                }
            }

            $lines = [];
            foreach ($this->schemaCatalog->findRelevantTables($question, $priority, self::MAX_RELEVANT_TABLES) as $table) {
                if (!isset($curated[$table])) {
                    $lines[$table] = $this->schemaCatalog->describeTable($table, $question, $this->getModuleLabel($table));
                }
            }

            $thirdPartyColumns = $this->moduleCatalog->getThirdPartyColumns();
            foreach ($this->schemaCatalog->getTables() as $table => $info) {
                $known = $curated[$table] ?? null;
                if ($known === null && !isset($added[$table])) {
                    continue;
                }
                $extra = $known !== null
                    ? $this->findExtraColumns($table, $question, $known, $thirdPartyColumns[$table] ?? [])
                    : [];
                foreach ($added[$table] ?? [] as $columns) {
                    $extra = [...$extra, ...array_intersect($columns, $info['columns'])];
                }
                $extra = array_values(array_unique(array_diff($extra, $known ?? [])));
                if (!$extra) {
                    continue;
                }

                $modules = array_keys($added[$table] ?? []);
                foreach ($extra as $column) {
                    if (isset($thirdPartyColumns[$table][$column])) {
                        $modules[] = $thirdPartyColumns[$table][$column];
                    }
                }
                $line = "- {$table} also has: " . implode(', ', $extra)
                    . ($modules ? ' [added by module ' . implode(', ', array_unique($modules)) . ']' : '');
                $lines[$table] = isset($lines[$table]) ? $lines[$table] . "\n" . $line : $line;
            }

            $lines = array_filter($lines);
            $output = $lines
                ? "\n═══ MORE TABLES FROM THIS STORE (live database schema matched to the question) ═══\n"
                    . implode("\n", $lines) . "\n"
                : '';

            return $output . $this->describeUnknownWords($currentQuestion !== '' ? $currentQuestion : $question);
        } catch (\Throwable $e) {
            return '';
        }
    }

    /**
     * A note on question words that appear in no table, column or comment of this store — the usual
     * sign of data the store does not keep ("loyalty cards"), which the AI must not map onto an
     * unrelated table.
     */
    private function describeUnknownWords(string $question): string
    {
        // Known text = the schema only: the rules mention example words ("loyalty cards") on purpose
        $unknown = $this->schemaCatalog->findUnknownWords($question, $this->getVerifiedSchema());
        if (!$unknown) {
            return '';
        }

        return "\n═══ WORDS WITH NO MATCH IN THIS STORE'S DATABASE ═══\n"
            . '- No table, column or table comment matches: ' . implode(', ', $unknown) . ".\n"
            . "- If such a word names a feature or kind of record (e.g. 'loyalty card', 'gift registry'), this store "
            . 'does not record it: reply with ' . self::DIRECT_ANSWER_PREFIX . " and say so instead of using an "
            . "unrelated table. If it only describes a measure or filter (e.g. 'top spenders', 'loyal customers' = "
            . "customers with repeat orders), answer normally with the listed tables.\n";
    }

    /**
     * Columns of a core-list table that are not in the list but match a specific word of the question
     * (e.g. "gift message" → sales_order.gift_message_id, or a column a third-party module added).
     *
     * @param string[] $known
     * @param array<string, string> $thirdPartyColumns columns third-party modules added to this table
     * @return string[]
     */
    private function findExtraColumns(string $table, string $question, array $known, array $thirdPartyColumns): array
    {
        $terms = array_diff($this->schemaCatalog->getQuestionTerms($question), self::GENERIC_COLUMN_TERMS);
        if (!$terms) {
            return [];
        }

        $extra = [];
        foreach (array_diff($this->schemaCatalog->getColumns($table), $known) as $column) {
            // Shorter words are enough for columns a third-party module added (they carry the module's own wording)
            $minLength = isset($thirdPartyColumns[$column]) ? 4 : 6;
            foreach ($terms as $term) {
                if (strlen($term) >= $minLength && str_contains($column, $term)) {
                    $extra[] = $column;
                    break;
                }
            }
        }

        return $extra;
    }

    private function getModuleLabel(string $table): string
    {
        $owner = $this->moduleCatalog->getTableOwner($table);

        return ($owner !== null && $this->moduleCatalog->isThirdParty($owner)) ? 'module ' . $owner : '';
    }

    /**
     * For a failed query: the live columns of each table it used, and similar names for a missing table.
     */
    private function describeTablesUsed(string $question, string $sql, string $databaseError): string
    {
        $lines = [];
        if (preg_match_all('/\b(?:FROM|JOIN)\s+`?(\w+)`?/i', $sql, $m)) {
            foreach (array_unique(array_map('strtolower', $m[1])) as $table) {
                if ($this->schemaCatalog->hasTable($table)) {
                    $lines[] = $this->schemaCatalog->describeTable($table, $question, '', 200);
                }
            }
        }

        if (preg_match("/Table '(?:[^'.]+\\.)?([^']+)' doesn't exist/i", $databaseError, $missing)) {
            $similar = $this->schemaCatalog->suggestTables($missing[1]);
            $lines[] = "- {$missing[1]} does NOT exist in this database."
                . ($similar ? ' Existing tables with similar names: ' . implode(', ', $similar) . '.' : '');
        }

        return $lines
            ? "Actual columns of the tables involved (from the live database — use only these names):\n"
                . implode("\n", array_filter($lines)) . "\n"
            : '';
    }

    /**
     * Store-specific facts (attribute ids, timezone, currency, sales rules) so generated SQL
     * matches this store instead of assumed defaults.
     */
    private function getStoreFacts(): string
    {
        try {
            $ctx   = $this->reportContext;
            $now   = $ctx->now();
            $today = $ctx->today();
            [$todayFrom, $todayTo] = $ctx->utcRange($today, $today->modify('+1 day'));
            $offset = $now->format('P');

            $productAttributes = [];
            foreach (['name', 'price', 'special_price', 'cost', 'status', 'visibility'] as $code) {
                $productAttributes[] = $code . '=' . $ctx->getAttributeId('catalog_product', $code);
            }

            [$thirtyDaysFrom] = $ctx->utcRange($today->modify('-29 days'), $today);
            $monthStart = $today->modify('first day of this month');
            [$monthFrom, $monthTo] = $ctx->utcRange($monthStart, $monthStart->modify('+1 month'));
            $link   = $ctx->getProductLinkField();
            $nameId = $ctx->getAttributeId('catalog_product', 'name');
            $priceId = $ctx->getAttributeId('catalog_product', 'price');
            $valid  = "o.state NOT IN ('canceled','pending_payment')";

            $examples = [
                '═══ JOIN NOTES & EXAMPLES (copy these patterns) ═══',
                '- sales_order has NO order_id column; its key is entity_id. sales_order_item.order_id = sales_order.entity_id.',
                '- sales_order_item has NO state/status column: join sales_order to filter orders.',
                '- catalog_product_entity has NO name or price column: use sales_order_item.name for sold products, '
                    . 'or join the EAV tables as in the last example.',
                "Example (orders and net revenue this month): SELECT COUNT(*) AS orders, ROUND(SUM(o.base_grand_total - IFNULL(o.base_total_refunded, 0)), 2) AS revenue "
                    . "FROM sales_order o WHERE {$valid} AND o.created_at >= '{$monthFrom}' AND o.created_at < '{$monthTo}'",
                "Example (best sellers by quantity): SELECT oi.sku, MAX(oi.name) AS product_name, SUM(oi.qty_ordered) AS qty_sold "
                    . "FROM sales_order_item oi JOIN sales_order o ON o.entity_id = oi.order_id "
                    . "WHERE {$valid} AND oi.parent_item_id IS NULL GROUP BY oi.sku ORDER BY qty_sold DESC LIMIT 10",
                '- Customers who ordered: group sales_order by customer_email (guest orders have customer_id NULL, so do not join customer_entity).',
                "Example (top customers): SELECT o.customer_email, MAX(CONCAT_WS(' ', o.customer_firstname, o.customer_lastname)) AS customer_name, "
                    . "COUNT(*) AS orders, ROUND(SUM(o.base_grand_total - IFNULL(o.base_total_refunded, 0)), 2) AS revenue "
                    . "FROM sales_order o WHERE {$valid} GROUP BY o.customer_email ORDER BY orders DESC LIMIT 10",
                "Example (daily revenue, last 30 days, store-local dates): SELECT DATE(CONVERT_TZ(o.created_at, '+00:00', '{$offset}')) AS day, "
                    . "ROUND(SUM(o.base_grand_total - IFNULL(o.base_total_refunded, 0)), 2) AS revenue FROM sales_order o "
                    . "WHERE {$valid} AND o.created_at >= '{$thirtyDaysFrom}' GROUP BY day ORDER BY day",
                "Example (catalog names and prices): SELECT e.sku, n.value AS name, p.value AS price FROM catalog_product_entity e "
                    . "LEFT JOIN catalog_product_entity_varchar n ON n.{$link} = e.{$link} AND n.attribute_id = {$nameId} AND n.store_id = 0 "
                    . "LEFT JOIN catalog_product_entity_decimal p ON p.{$link} = e.{$link} AND p.attribute_id = {$priceId} AND p.store_id = 0 LIMIT 100",
            ];

            $stockLine = $ctx->isMsiEnabled()
                ? "- Stock (MSI enabled): physical quantity per source is in inventory_source_item (source_item_id, source_code, sku, quantity, status). "
                    . "Sum quantity by sku across sources. cataloginventory_stock_item only mirrors the default source."
                : '- Stock: cataloginventory_stock_item (product_id, qty, is_in_stock). Only simple/virtual/downloadable products hold quantity.';

            return implode("\n", [
                '═══ STORE FACTS (specific to this store — always follow these) ═══',
                '- Product attribute_ids (catalog_product_entity_* tables): ' . implode(', ', $productAttributes),
                '- Category name attribute_id (catalog_category_entity_varchar): '
                    . $ctx->getAttributeId('catalog_category', 'name'),
                '- EAV value tables: use store_id = 0 for default values.',
                "- All created_at/updated_at columns are stored in UTC. Store timezone: {$ctx->getTimezone()->getName()} (current UTC offset {$offset}).",
                "- Store-local today is {$today->format('Y-m-d')}; in UTC that is created_at >= '{$todayFrom}' AND created_at < '{$todayTo}'.",
                "- For day/week/month grouping use CONVERT_TZ(created_at, '+00:00', '{$offset}'). Do not use CURDATE()/NOW() for store-local dates.",
                "- Money: amounts are in base currency {$ctx->getCurrencyCode()}; use base_* columns (base_grand_total, base_row_total, base_discount_amount).",
                "- Sales/revenue: exclude orders with state IN ('canceled','pending_payment'). "
                    . 'Net revenue = base_grand_total - IFNULL(base_total_refunded, 0).',
                '- Product-level sales: use sales_order_item rows with parent_item_id IS NULL so configurable/bundle lines are not double counted.',
                $stockLine,
                '',
                ...$examples,
            ]) . "\n";
        } catch (\Throwable $e) {
            return '';
        }
    }

    /**
     * Sanitize and validate the SQL returned by the LLM.
     *
     * @throws LlmException
     */
    private function sanitizeSql(string $sql): string
    {
        try {
            return $this->sqlGuard->sanitize($sql);
        } catch (QueryException $e) {
            throw new LlmException(__('%1', $e->getMessage()));
        }
    }
}
