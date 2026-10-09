# Meetanshi_AIReporting: Technical Documentation

> Code review of `app/code/Meetanshi/AIReporting` as of 2026-09-24.
> The first version of this file was written without changing any code. After that, the report and dashboard code was reworked for accuracy (**§13**), the fake test data was removed and the security issues were fixed (**§14**). Fixed issues are marked ✅ in §9.

---

## 1. What the module does

Meetanshi AI Reporting is an **admin-only** Magento 2 extension with two parts:

1. **AI query ("Ask AI").** An admin types a question in plain English, such as *"Top 10 products this month"*. An LLM turns the question into a MySQL `SELECT`, the module runs it against the Magento database, and the admin sees the result as a table or a chart (bar, line or pie). The admin can download the result as CSV or save it as a report.
2. **AI chat.** This part is uncommitted work in progress. It uses the same question-to-SQL-to-results steps, then sends the result rows back to the LLM so it can write a conversational answer.

It also ships an **analytics dashboard** and **four pre-built reports** (Sales, Customer, Product, Inventory). These run hand-written SQL and do not use the LLM.

Supported LLM providers are **Groq** (the default), **Ollama** (local), **OpenRouter**, **Google Gemini** and **OpenAI**.

| Item | Value |
|---|---|
| Module name | `Meetanshi_AIReporting` |
| Composer package | `meetanshi/module-ai-reporting` v1.0.0 (proprietary) |
| PHP | `>= 8.1` (uses constructor promotion, `readonly`, `match`, `str_contains`) |
| Magento deps (module.xml sequence) | Magento_Backend, Magento_Sales, Magento_Catalog, Magento_Customer |
| Status in this install | Enabled (`app/etc/config.php`) |
| Frontend (storefront) code | None. Admin only. |
| External JS | Chart.js 4.4.3 from `cdn.jsdelivr.net` (via RequireJS) |
| Own DB tables | `meetanshi_aireporting_saved_report`, `meetanshi_aireporting_query_log` |

---

## 2. Directory map

```
AIReporting/
├── Block/Adminhtml/
│   ├── Dashboard.php              # Dashboard data (KPIs, trends, segments), all SQL lives here
│   └── Query.php                  # "Ask AI" page: URLs, form key, suggested questions
├── Controller/Adminhtml/
│   ├── AbstractAction.php         # Base page action (PageFactory + ACL)
│   ├── Dashboard/{Index,RefreshData,Export}.php
│   ├── Query/{Index,Execute}.php  # Execute = main AJAX endpoint (NLP→SQL→rows)
│   ├── Chat/Send.php              # AJAX chat endpoint (NLP→SQL→rows→LLM answer) [untracked]
│   ├── Report/{Saved,Save,Update,Delete,ExportData}.php  # Saved AI Reports page + its actions
│   └── Reports/{Sales,Customer,Product,Inventory}.php
├── Exception/{LlmException,QueryException}.php   # both extend LocalizedException
├── Model/
│   ├── Config.php                 # All system-config getters (decrypts API keys)
│   ├── Config/Source/*.php        # Dropdown sources: providers + model lists
│   ├── LLM/
│   │   ├── ProviderInterface.php  # complete(string $prompt): string; getProviderCode()
│   │   ├── ProviderPool.php       # Picks active provider from config (di.xml array)
│   │   └── {OpenAI,Gemini,Groq,Ollama,OpenRouter}Provider.php
│   ├── Query/
│   │   ├── NlpToSql.php           # Prompt building + schema context + SQL "sanitising"
│   │   └── QueryExecutor.php      # Runs SQL, appends LIMIT, times execution
│   ├── Report/{Sales,Customer,Product,Inventory}Report.php  # Pre-built report SQL
│   ├── SavedReport.php / QueryLog.php
│   └── ResourceModel/{SavedReport,QueryLog}.php, SavedReport/Collection.php
├── Setup/Patch/Data/InsertTestData.php   # ⚠ Inserts fake customers/orders (see §9)
├── Test/
│   ├── Unit/...                   # 13 PHPUnit test classes (117 tests)
│   ├── ai-prompts/                # 1,150 prompt corpus + 2 CLI batch runners [untracked]
│   └── sql/{insert,cleanup}_test_data.sql
├── etc/  (module, di, config, acl, db_schema, csp_whitelist, adminhtml/{routes,menu,system,csp_whitelist})
└── view/adminhtml/
    ├── layout/*.xml
    ├── requirejs-config.js        # 'chartjs' → jsdelivr CDN
    ├── templates/                 # dashboard_enhanced.phtml, query.phtml, saved_reports.phtml,
    │                              # reports/*.phtml, dashboard.phtml (unused)
    └── web/css, web/images
```

---

## 3. Configuration

Go to **Stores → Configuration → Meetanshi → AI Reporting** (section `meetanshi_aireporting`, ACL `Meetanshi_AIReporting::config`).

| Path | Type / Source | Default | Notes |
|---|---|---|---|
| `general/enabled` | Yes/No | `1` | Checked by Execute and Chat controllers, and by the Query and Dashboard blocks |
| `general/llm_provider` | `LlmProvider` | `groq` | `groq`, `ollama`, `openrouter`, `gemini`, `openai` |
| `general/openai_api_key` | obscure, Encrypted | — | |
| `general/openai_model` | `OpenAiModel` | `gpt-4o` | gpt-4o, gpt-4o-mini, gpt-4-turbo, gpt-3.5-turbo |
| `general/gemini_api_key` | obscure, Encrypted | — | Sent as `?key=` in the URL |
| `general/gemini_model` | `GeminiModel` | `gemini-1.5-flash` | 1.5-flash, 1.5-pro, 2.0-flash |
| `general/groq_api_key` | obscure, Encrypted | — | |
| `general/groq_model` | `GroqModel` | `llama-3.3-70b-versatile` | also 3.1-8b-instant, llama3-70b/8b, mixtral, gemma2 |
| `general/ollama_base_url` | text | `http://localhost:11434` | Calls `{url}/api/chat`, no key |
| `general/ollama_model` | text | `llama3.2` | Must exactly match a name from `ollama list`. This install uses `qwen2.5-coder:3b` (§15). |
| `general/ollama_timeout` | text | `240` | HTTP timeout for Ollama only. Local CPU models are slow, and the first call also loads the model (§15). |
| `general/openrouter_api_key` | obscure, Encrypted | — | |
| `general/openrouter_model` | `OpenRouterModel` | `meta-llama/llama-3.1-8b-instruct:free` | 5 free + 2 paid options |
| `general/max_tokens` | text | `1024` | LLM `max_tokens` (not sent to Ollama) |
| `general/max_rows` | text | `500` | LIMIT that `QueryExecutor` appends |
| `general/query_timeout` | text | `30` | HTTP timeout for LLM calls **and** the server-side statement timeout for AI/saved-report SQL (§14) |
| `security/log_queries` | Yes/No | `1` | Writes to `meetanshi_aireporting_query_log` (Execute only) |
| `security/share_results_with_llm` | Yes/No | `0` | Send query result rows to the LLM so it can write chat answers. When set to No, chat answers are built on the server (§14) |

Many model names in the dropdowns may be outdated or retired (for example `gemini-1.5-*`, `llama3-*-8192`, `mixtral-8x7b-32768`, and OpenRouter `:free` IDs). Check them against each provider's current model list.

---

## 4. Admin menu, routes and ACL

The admin route is `meetanshi_aireporting` (frontName `meetanshi_aireporting`, router `admin`).

The module adds a top-level menu, **Meetanshi AIReporting → AI Reporting**:

| Menu item | URL (`meetanshi_aireporting/...`) | Controller | ACL resource |
|---|---|---|---|
| Dashboard | `dashboard/index` | `Dashboard\Index` | `::dashboard` |
| (AJAX) | `dashboard/refreshData` | `Dashboard\RefreshData` | `::dashboard` |
| (download) | `dashboard/export` | `Dashboard\Export` | `::dashboard` |
| Ask AI | `query/index` | `Query\Index` | `::query` |
| (AJAX POST) | `query/execute` | `Query\Execute` | `::query` |
| (AJAX POST) | `chat/send` | `Chat\Send` | `::query` |
| Saved Reports | `report/saved` | `Report\Saved` | `::saved_reports` |
| (AJAX POST) | `report/save` | `Report\Save` | `::saved_reports` |
| (AJAX POST) | `report/update` (title, chart format) | `Report\Update` | `::saved_reports` |
| (AJAX POST) | `report/delete` (`ids[]`) | `Report\Delete` | `::saved_reports` |
| (download) | `report/exportData` (result CSV) | `Report\ExportData` | `::saved_reports` + `::query` |
| Pre-built → Sales & Revenue | `reports/sales` | `Reports\Sales` | `::reports_sales` |
| (AJAX) | `reports/salesData` | `Reports\SalesData` | `::reports_sales` |
| (download) | `reports/salesExport` | `Reports\SalesExport` | `::reports_sales` |
| Pre-built → Customer Analytics | `reports/customer` | `Reports\Customer` | `::reports_customer` |
| (AJAX) | `reports/customerData` | `Reports\CustomerData` | `::reports_customer` |
| (download) | `reports/customerExport` | `Reports\CustomerExport` | `::reports_customer` |
| Pre-built → Product Performance | `reports/product` | `Reports\Product` | `::reports_product` |
| (AJAX) | `reports/productData` | `Reports\ProductData` | `::reports_product` |
| (download) | `reports/productExport` | `Reports\ProductExport` | `::reports_product` |
| Pre-built → Inventory | `reports/inventory` | `Reports\Inventory` | `::reports_inventory` |
| (AJAX) | `reports/inventoryData` | `Reports\InventoryData` | `::reports_inventory` |
| (download) | `reports/inventoryExport` | `Reports\InventoryExport` | `::reports_inventory` |

The ACL tree is `Magento_Backend::admin → Meetanshi_AIReporting::aireporting → {dashboard, query, saved_reports, reports → {reports_sales, reports_customer, reports_product, reports_inventory}, config}`.

All controllers extend `Magento\Backend\App\Action`, so Magento's usual admin session, URL secret key and form-key checks apply. The AJAX calls send `form_key`.

---

## 5. Request flows

### 5.1 Ask AI (`query/execute`)

```
query.phtml (jQuery)
  POST {query: "<question>", form_key}
        │
        ▼
Controller\Adminhtml\Query\Execute
  ├─ reject if !isAjax || !isPost || module disabled
  ├─ NlpToSql::convert(question)
  │     ├─ buildPrompt(): SCHEMA_CONTEXT (static, ~200 lines)
  │     │     + getDynamicSchema() if question matches a "system" keyword
  │     │       (SHOW TABLES → every non-core table → INFORMATION_SCHEMA columns)
  │     │     + rules + "Question: ..."
  │     ├─ ProviderPool::getActiveProvider()->complete(prompt)   (HTTP via Magento Curl)
  │     └─ sanitizeSql(): strip ``` fences and trailing ';',
  │                       reject if it STARTS WITH INSERT/UPDATE/DELETE/DROP/...,
  │                       require it to START WITH SELECT or WITH
  ├─ QueryExecutor::execute(sql)
  │     ├─ append " LIMIT {max_rows}" unless "LIMIT" appears anywhere in the SQL,
  │     │   or the SQL starts with SELECT COUNT/SUM/AVG/MIN/MAX(
  │     └─ $connection->fetchAll($sql)   (default DB connection)
  ├─ log to meetanshi_aireporting_query_log (if enabled)
  └─ JSON {success, sql_query, columns, rows, row_count, execution_time_ms, provider}
        │
        ▼
query.phtml renders the table (HTML-escaped) or Chart.js (bar/line/pie, auto or chosen X/Y axes),
Copy SQL, Download CSV (client-side), Save Report (→ report/save)
```

### 5.2 Running a saved report

The Saved AI Reports page (`saved_reports.phtml` + `js/saved-reports.js`, data from `ViewModel/SavedReports`) stores `{report_id, chart, nlp}` in `sessionStorage` and redirects to `query/index`. `query.phtml` then POSTs `{report_id}` to `query/execute`. The server loads the SQL from the database, but only if the report belongs to the logged-in admin, and runs it through the same checks as AI-generated SQL. Raw SQL from the browser is no longer accepted (§14).

The same page also offers, all owner-scoped: **Edit** (`report/update`: title and chart format only), **Delete** (`report/delete`), **Export** (`report/exportData`: re-runs the report read-only and row-limited through `QueryExecutor`, logs the run, and downloads the result as CSV; text that a spreadsheet would run as a formula is prefixed with `'`), and definition exports (JSON/CSV of title, question, SQL and run stats, built in the browser). Each report shows whether `SqlGuard` still accepts its stored SQL; **Run** and **Export** are disabled when it does not.

### 5.3 Chat (`chat/send`)

`Chat\Send` answers in this order (details in §16):

1. **A specific module or vendor** (`Model/ModuleInfoResponder`): "Is the Size Chart extension enabled?", "What version of Mageplaza SMTP is installed?", "Which Amasty modules are installed?". The answer comes from the module's own files and the store configuration. No SQL and no LLM are used.
2. **Platform and environment** (`Model/SystemInfoResponder`): version, PHP, deploy mode, module lists, disabled modules, cache status, and the cache/session/search/queue backends from `app/etc/env.php`.
3. **Anything else** goes through `QueryRunner` (`NlpToSql` → `QueryExecutor`), the same as Ask AI. The prompt includes the live tables that match the question (§16). If the question is not about stored data, the LLM may reply `ANSWER: …` instead of SQL. Chat shows that reply, and Ask AI shows it as a message.
4. When **Send Query Results to AI Provider** is Yes, up to **80 result rows** go back to the LLM for a written answer. Otherwise the answer is built on the server.
5. Returns `{answer, sql, rows, time_ms}`. The UI escapes the answer, then applies a small markdown-to-HTML formatter. The rows/time line appears only when a query ran.

Chat questions are **not** written to the query log.

### 5.4 Dashboard

`dashboard_enhanced.phtml` calls `Block\Dashboard::getDashboardData('last_6_months')` while the page renders. The date-range dropdown calls `dashboard/refreshData` over AJAX. **Export** calls `dashboard/export?format=csv&date_range=...`, which builds a CSV, writes it to `var/` through `FileFactory` and streams it.

The date ranges are `today`, `yesterday`, `last_7_days`, `last_30_days`, `last_3_months`, `last_6_months` (default), `last_year`, `this_month`, `this_year` and `custom` (with `custom_start` and `custom_end`).

The dashboard data contains:

- **KPIs:** revenue, orders, active customers and AOV, each with a percentage change against the previous period of the same length. Also products sold, low stock (qty 1–10) and out of stock (qty 0).
- **Charts and tables:** revenue trend per day, revenue by category (top 10), top 10 products, orders by status, revenue by day of week, and customer segments (New = 1 order, Returning = 2–5, VIP = 6+).
- **Stock distribution** buckets.

### 5.5 Pre-built reports (no LLM)

Each report page's block calls its `Model\Report\*Report` class. All figures are computed live on every page load, with no caching. Every report shares `Model\Report\ReportContext`, which applies the data rules in §13.

| Report | Contents |
|---|---|
| **Sales** | Redesigned page (Stitch "Sales & Revenue Report"), data from `ViewModel\SalesRevenue`. **Follows the range filter** (Today, This Week, This Month, This Year, custom; reloaded from `reports/salesData`): net revenue, orders and AOV vs the previous period of the same length; pending-payment / canceled orders (shown, not counted); highest order with its region; credit memos created in the range and refund rate (of gross); discounts, tax and shipping charged; net revenue and orders by hour (one day), day (up to 62 days) or month, with the peak; every order by status; revenue by weekday with the busiest hour; regions (shipping address, billing for virtual orders) with GMV share, AOV and tax; shipping methods with average fee and share of orders shipped; cart price rules and coupon codes applied (`sales_order.applied_rule_ids` × `salesrule`; an order counts under each rule, its discount only under rules that give one) with promotional ROI. **Always the last 30 days:** daily revenue trajectory. `reports/salesExport` downloads `type=summary` (every section) or `type=orders` (every order placed in the range, all states, with a "Counted in Sales" column). The copilot insight is rule-based; follow-ups go to `chat/send` |
| **Customer** | Redesigned page (Stitch "Customer Analytics Report"), data from `ViewModel\CustomerAnalytics`. **Follows the range filter** (Today, This Week, This Month, This Year, custom; reloaded from `reports/customerData`): buyers vs the previous period of the same length, registered vs guest, new registrations and how many ordered, repeat rate (2+ orders to date) and multi-order rate (2+ in the period), average lifetime value of the period's buyers, spend, orders per buyer, and new vs returning buyers by hour, day (up to 62 days) or month. **All-time:** CLV tiers (VIP ≥ 1,000, Growth 500–999, Core 250–499, Starter 100–249, Entry < 100) with buyers and revenue share; RFM scores 1–5 for every buyer, labelled Champions, Loyal, Promising, New Customer, At Risk or Lost; churn risk index (buyers with no order for 90+ days); re-order cycle; top-3 concentration; the most valuable lapsed buyer; the top 200 buyers by lifetime value with preferred category. `reports/customerExport` downloads every matching buyer as CSV (`segment=vip|winback`, `segments=` RFM labels, `q=` name/email text). The copilot panel's insight is rule-based; follow-up questions go to `chat/send` |
| **Product** | Redesigned page (Stitch "Product Performance Report"), data from `ViewModel\ProductPerformance`. Sales figures follow the range filter (Today, This Week, This Month, This Year, custom; reloaded from `reports/productData`); catalog size and stock are as of now. KPIs: products (and how many were created in the range), SKUs sold and sell-through (sold at least once, as an order line or a configurable/bundle child, out of enabled non-grouped products), units vs the previous period of the same length, average basket, and dead stock (sellable products without a sale, with their stock value at list price). Top 10 by net revenue or units; net revenue by product type; units by realized price band with the sweet spot; top 5 categories (gross margin only when every order line carries a product cost); product table tabs — best sellers by revenue / quantity, worst 10, never sold — with current stock, days of cover and a velocity rating (restock under 14 days of cover or out of stock; otherwise high / medium / low = top 20% / next 40% / rest by units). `reports/productExport` downloads every row of a tab as CSV, with the page's filters (`tab`, `range`, `start`, `end`, `q`, `category`, `stock`). The copilot summary is rule-based; follow-ups go to `chat/send` |
| **Inventory** | Redesigned page (Stitch "Inventory Report"), data from `ViewModel\InventoryAnalytics`; see §19. **Current stock:** SKUs, in/out of stock, disabled, on hand, reserved (MSI reservations), available = on hand − reserved, stock value = on hand × list price, tiers by available quantity (101+, 51–100, 11–50, 1–10, out). **Follows the range filter** (reloaded from `reports/inventoryData`): units sold, average daily sales, days of cover, turnover and annualized run-rate, top 6 sellers. Tabs: all SKUs, low stock & depletion (out, ≤ 10 available or < 14 days of cover), dead stock (in stock, no sale for 90+ days), turnover. `reports/inventoryExport` downloads any tab, the PO reorder plan or the clearance list as CSV |

**Revenue** means net revenue: `base_grand_total − base_total_refunded`, converted with `base_to_global_rate` to the global base currency. Orders in state `canceled` or `pending_payment` are not counted. See §13.

---

## 6. LLM providers

Every provider implements `ProviderInterface::complete(string $prompt): string`. Each one follows the same pattern:

- Uses the same hard-coded system message: *"You are a Magento 2 database expert… Return only the raw SQL…"*
- Sets `temperature: 0.1` and `max_tokens` from config (Ollama sends `stream:false` and no token limit).
- Uses `curl->setTimeout(query_timeout)` for the HTTP call.
- Throws `LlmException` on a missing key, a non-200 response, a JSON parse error or an empty response, and writes the raw response body to the Magento log.

| Code | Class | Endpoint | Auth |
|---|---|---|---|
| `openai` | `OpenAIProvider` | `https://api.openai.com/v1/chat/completions` | Bearer |
| `gemini` | `GeminiProvider` | `.../v1beta/models/{model}:generateContent?key={key}` | key in the query string |
| `groq` | `GroqProvider` | `https://api.groq.com/openai/v1/chat/completions` | Bearer |
| `ollama` | `OllamaProvider` | `{base_url}/api/chat` | none |
| `openrouter` | `OpenRouterProvider` | `https://openrouter.ai/api/v1/chat/completions` | Bearer + `HTTP-Referer`, `X-Title` |

Providers are registered in `etc/di.xml` as the `providers` array argument of `ProviderPool`. To add a new provider, write a class that implements `ProviderInterface`, add a di.xml item, add an option to `LlmProvider`, and add config fields and getters.

The five provider classes are almost identical. The system prompt appears five times, and the OpenAI-compatible request body appears four times. A shared base class would remove the duplication.

---

## 7. Database schema (`etc/db_schema.xml`)

**`meetanshi_aireporting_saved_report`** has these columns: `report_id` (PK), `title`, `nlp_query`, `sql_query`, `chart_type` (default `table`), `chart_config` (JSON text, never populated by the UI), `admin_user_id` (indexed), `created_at`, `updated_at`.

**`meetanshi_aireporting_query_log`** has these columns: `log_id` (PK), `nlp_query`, `sql_query`, `llm_provider`, `status` (`success`/`error`, indexed), `error_message`, `execution_time_ms`, `rows_returned`, `admin_user_id` (indexed), `created_at`.

- There is no foreign key to `admin_user`.
- There is no `db_schema_whitelist.json` (Magento uses it to drop columns safely in later versions).
- The Saved AI Reports page shows the admin's latest 30 log entries in its **Query Audit Log** drawer, and derives each saved report's run count, last run and duration from the log rows written after the report was saved (matched on admin + SQL). There is no full query-log grid.
- Saved reports can be renamed, given another chart format, deleted (one or many) and exported from the Saved AI Reports page. Their question and SQL never change.

---

## 8. Frontend (admin UI)

- Layout handle `meetanshi_aireporting_default` loads `aireporting.css`, `menu.css` and `dashboard_enhanced.css`. `default.xml` loads `custom.css` on every admin page to style the menu icon. `adminhtml_system_config_edit.xml` loads `meetanshi.css`.
- The templates use inline `<script>require([...])</script>` blocks and depend on `jquery`, `chartjs` and `Magento_Ui/js/modal/alert`.
- **Output escaping is done well:** table cells, SQL and chat text go through `escHtml()` (jQuery `.text()`), and PHP output uses `escapeHtml`, `escapeHtmlAttr` and `escapeJs`.
- Chart.js is loaded from `cdn.jsdelivr.net`, which both `etc/csp_whitelist.xml` (global) and `etc/adminhtml/csp_whitelist.xml` whitelist. Charts break in admin environments with no internet access or a strict CSP.
- The chat bubble uses an inline `onclick` handler (`query.phtml:825`), and the inline scripts don't use `SecureHtmlRenderer`. Both are problems under a strict CSP.
- `templates/dashboard.phtml` is **unused**. The layout points to `dashboard_enhanced.phtml`.
- The ready-made questions are `Block\Query::getSuggestedQueries()` (10 chips) plus 6 chat quick buttons hard-coded in `query.phtml`.

---

## 9. Issues and risks found

Severity reflects impact on a live store. Line numbers refer to the current working tree.

### Critical

✅ **C1: `query/execute` runs any SQL the client sends, with no checks.** *Fixed in §14.*
`Controller/Adminhtml/Query/Execute.php:54,67-68` reads the `sql` POST parameter and passes it straight to `QueryExecutor::execute()`, which calls `fetchAll()` (`Model/Query/QueryExecutor.php:44`). No `SELECT`-only check applies on this path.

Any admin with the `::query` ACL can run `UPDATE`, `DELETE` or `INSERT` statements. MySQL accepts a `LIMIT` on `UPDATE` and `DELETE`, so the appended `LIMIT 500` does not stop them. Adding `/* LIMIT */` to the statement also suppresses the append, which lets DDL through. Multiple statements are blocked, because Magento's PDO adapter disables `MULTI_STATEMENTS`.

✅ **C2: A saved report can make another admin run arbitrary SQL.** *Fixed in §14.*
`Report/Save.php:43,56` stores any `sql_query` exactly as sent. All admins see every saved report (`Block/Adminhtml/SavedReports.php:29` has no `admin_user_id` filter). When another admin clicks **Run**, the stored SQL is executed with that admin's privileges through C1.

✅ **C3: The data patch writes fake data into real sales and customer tables.** *Fixed: the patch is removed and the fake rows are deleted from this DB (§14.1).*
`Setup/Patch/Data/InsertTestData.php` runs on `setup:upgrade` on every environment. It inserts:

- 25 customers (`*@aireporting-test.com`)
- 50 orders with items, addresses and payments
- sample saved reports and query logs

The orders use hard-coded `increment_id`s 100000001–100000050 (line 199), which will collide with real orders. The patch catches every exception (line 63), so a failure midway leaves partial data and the patch is still marked as applied. The rows skip the sequence tables and `sales_order_grid`, so the fake orders don't appear in the admin order grid but **are counted in every revenue report**. The patch has no revert; the only cleanup is `Test/sql/cleanup_test_data.sql`.

### High

✅ **H1: The SELECT-only check in `sanitizeSql()` is weak.** *Fixed in §14.*
It only checks the first keyword (`NlpToSql.php:397-421`). It allows:

- `WITH … DELETE` / `WITH … UPDATE`, which MySQL 8 supports
- `SELECT … INTO OUTFILE`
- `SELECT … FOR UPDATE` (takes locks)
- `SELECT SLEEP(n)`, heavy cross joins, and similar

The module uses Magento's full read-write DB connection. The real protection would be a **read-only DB user or connection**.

✅ **H2: Sensitive data can be read and is sent to third-party LLMs.** *Fixed in §14.*
The work-in-progress schema context advertises these tables to the model:

- `admin_user` (`NlpToSql.php:209`)
- `oauth_token` including the `token` and `secret` columns (`:220`)
- `core_config_data` (`:87`)
- every third-party table's columns

`Chat/Send.php:101-108,150` sends up to 80 **result rows** (customer PII, emails, addresses, tokens) to the external provider. The committed version of `NlpToSql` said *"Only read-relevant tables are listed for security"*; the uncommitted change removes that restriction. There is no table allow-list and no column deny-list.

✅ **H3: The database query has no timeout.** *Fixed in §14.*
`query_timeout` only limits the LLM HTTP call. A slow query that the LLM generates, or that is sent directly, can run for as long as it likes. There is no `MAX_EXECUTION_TIME` hint and no `SET SESSION` limit.

✅ **H4: The unit test suite is broken.** *Fixed: all 201 tests pass (see §10).*
On this install (PHPUnit 12.5.31) the suite runs 117 tests with **26 errors and 2 failures**:

- `MockBuilder::addMethods()` no longer exists in PHPUnit 12 (ExecuteTest, SaveTest: 9 errors).
- The `@dataProvider` docblock annotation is not supported any more; PHPUnit 12 needs the `#[DataProvider]` attribute (NlpToSqlTest: 1 error).
- `SavedReportTest` creates `AbstractModel` without constructor arguments (16 errors).
- `NlpToSqlTest::testConvertBlocksNonSelectNonWithQuery` fails because the new dynamic-schema code calls a `ResourceConnection` mock that returns null. The test was not updated for the uncommitted change.
- `CustomerReportTest` expects `0.0`, but `repeat_rate` returns int `0`.

### Medium

- ✅ **M1: The row cap is easy to bypass.** *Fixed: the top-level `LIMIT` is appended or lowered, and aggregates are capped too (§14).* `enforceLimitClause()` (`QueryExecutor.php:70-77`) skips the cap if the text `LIMIT` appears anywhere, even in a subquery or a string. So an LLM-written `LIMIT 100000` is not capped, and `max_rows` is not enforced as a maximum. Queries that start with an aggregate are also left uncapped even when they have a `GROUP BY`.
- ✅ **M2: Dynamic schema discovery is slow and fires too often.** *Partly fixed: it is now a single `INFORMATION_SCHEMA` query that skips denied tables and columns. The keyword trigger is unchanged.* `getDynamicSchema()` (`NlpToSql.php:317`) runs `SHOW TABLES` plus one `INFORMATION_SCHEMA` query per non-core table (an N+1 pattern). It also sends every custom table name to the LLM. The trigger keywords (`isSystemQuestion`, `:285-300`) include very common words such as `table`, `index`, `mode`, `api`, `token`, `setting` and `enable`, so most questions trigger it.
- ✅ **M3: Export writes a file named from unchecked input.** Fixed: the file name is now sanitised. Export files still build up in `var/`. `Dashboard/Export.php:33,59` builds the file name from the raw `date_range` parameter. The file is written into `var/` and never removed (`FileFactory` is called without `rm`), so files pile up. The value also goes into the `Content-Disposition` header.
- ✅ **M4: Report queries are slow on large stores.** Mostly fixed: date filters are now index-friendly UTC ranges, the correlated subquery and `NOT IN` are rewritten, and the `SHOW TABLES` N+1 is unchanged. Caching is still missing.
  - `DATE(created_at)`, `YEAR()`, `MONTH()` and `YEARWEEK()` on `sales_order` prevent index use.
  - `CustomerReport::getNewVsReturning()` runs a correlated `MIN(entity_id)` subquery for every order (`:127`), which is O(n²).
  - `getProductsNeverSold()` uses `NOT IN` over all of `sales_order_item`.
  - The dashboard runs about 12 queries while rendering and again on each refresh.
  - Nothing is cached.
- ✅ **M5: Some reports assume a particular Magento setup.** Fixed for the reports and dashboard (table prefix, `row_id`, MSI, attribute IDs, category double counting). The AI prompt now gets the real attribute IDs.
  - They use raw table names, so stores with a **table prefix** break.
  - EAV joins use `entity_id`, so **Adobe Commerce** (which uses `row_id`) breaks.
  - Inventory uses the legacy `cataloginventory_stock_item`, so MSI source quantities are ignored.
  - The LLM prompt hard-codes attribute IDs 73, 75, 97 and 99 (`NlpToSql.php:223`), which differ between installs.
  - The dashboard category query hard-codes `entity_type_id = 3` and has no `store_id = 0` filter, so store-view names can double-count.
- ✅ **M6: Currency and timezone handling is wrong for some stores.** Fixed for the reports, dashboard, CSV export and AI prompt. Revenue sums `grand_total` (order currency) instead of `base_grand_total`, which mixes currencies on multi-currency stores. `$` is hard-coded in the blocks and the CSV. Date filters compare UTC `created_at` with the MySQL `CURDATE()`/`NOW()`, not the store's timezone.

### Low

- The raw database error text is sent back to the browser (`Query execution failed: …`).
- The Gemini API key is sent in the URL query string, so it can end up in proxy or access logs.
- `security/allowed_roles` is defined in config.xml but nothing reads it.
- `ProviderPool::getProvider()` is never called.
- `dashboard.phtml` is unused.
- Chat answers are not written to the query log.
- `chart_config` is never saved.
- The global `etc/csp_whitelist.xml` also allows jsdelivr on the storefront, which is not needed.
- Some orders are counted inconsistently: `closed` orders are excluded in some reports and included in others.
- `Chat/Send.php:166` reads `$_SERVER` directly.
- `module.xml` is missing `Magento_Quote`, `Magento_CatalogInventory` and `Magento_Review` from its sequence, even though the module queries their tables.

---

## 10. Tests and tooling

| Path | Purpose |
|---|---|
| `Test/Unit/**` | 13 PHPUnit classes covering Config, ProviderPool, NlpToSql, QueryExecutor, the 4 report models, SavedReport, the exceptions, and the Execute/Save controllers |
| `Test/ai-prompts/test_prompts.{md,csv,json}` | About 1,150 natural-language prompts by category. 1101–1150 are security and edge cases. |
| `Test/ai-prompts/run_ai_test.php` | CLI runner that boots Magento and sends prompts through `NlpToSql`/`QueryExecutor` (`--from/--to/--category/--dry-run/--security-only/...`) |
| `Test/ai-prompts/run_api_test.php` | Standalone cURL runner that logs in to the admin and calls `query/execute` |
| `Test/sql/insert_test_data.sql`, `cleanup_test_data.sql` | Manual SQL version of the data patch, plus cleanup |

**Current state (after the §13 and §14 work):** 201 tests, **all passing**. This includes `SqlGuardTest`, which covers about 50 allowed and blocked query patterns. The only runner warning is the missing optional Allure config. The notices are PHPUnit 12's "mock without expectations" notices.

To run the unit tests:

```bash
php vendor/bin/phpunit -c dev/tests/unit/phpunit.xml.dist app/code/Meetanshi/AIReporting/Test/Unit
```

---

## 11. Repository state

- The module has **its own git repo**: branch `main`, remote `github.com/JayParmarMT/Magento-2-AI-Reporting`, 2 commits on 2026-05-04.
- **Uncommitted work (the chat feature and a larger schema prompt):**
  - modified: `Block/Adminhtml/Query.php` (adds the chat URL), `Model/Query/NlpToSql.php` (much larger schema, config-path hints, dynamic third-party table discovery), `Test/Unit/Model/Query/NlpToSqlTest.php`, `view/adminhtml/templates/query.phtml` (chat panel), `view/adminhtml/web/css/aireporting.css`
  - untracked: `Controller/Adminhtml/Chat/`, `Test/ai-prompts/`
- `../11-05-2026-AIReporting.zip` (dated 2026-05-10) sits next to the module and looks like a packaged snapshot.
- `README.md` contains only a title.

---

## 12. Suggested next steps (not implemented)

1. Remove the direct `sql` parameter from `query/execute`. Re-run saved reports by `report_id` on the server, and apply the same validation as for LLM output.
2. Run AI-generated SQL through a **dedicated read-only DB connection** (a separate `env.php` connection with a `SELECT`-only MySQL user limited to allowed tables). Add a `MAX_EXECUTION_TIME` hint and cap the LIMIT properly, for example by wrapping the query as `SELECT * FROM (<query>) t LIMIT n`.
3. Add a table allow-list and a sensitive-column deny-list (`admin_user`, `oauth_*`, `integration`, `core_config_data` secrets, `customer_entity.password_hash`, and so on). Make sending result rows to external LLMs opt-in.
4. Move `InsertTestData` out of `Setup/Patch` (into a CLI command or dev-only fixture) so it never runs on production.
5. Scope saved reports per admin (or add sharing), and add delete/edit plus a query-log grid.
6. Fix the unit tests for PHPUnit 12 and the new `NlpToSql` constructor.
7. Use `ResourceConnection::getTableName()`, look up attribute IDs dynamically, use base currency and the store timezone, support MSI, and cache the dashboard and report data.
8. Bundle Chart.js locally instead of loading it from the CDN, and move the inline scripts to `x-magento-init` or `SecureHtmlRenderer`.

---

## 13. Report accuracy rework (2026-09-24)

### 13.1 Why

On this install, **50 of the 75 orders and 25 of the 27 customers are fake rows from `Setup/Patch/Data/InsertTestData`**. The fake orders add $93,386 of revenue next to $986 from real orders. Their order items also point at real catalog product IDs, so product, category and inventory-demand figures are affected too.

Separately, the report SQL itself was wrong in several ways:

- It used the UTC date, not the store timezone (America/Chicago), so orders placed in the evening landed on the next day.
- It summed order-currency `grand_total` and ignored refunds.
- It included unpaid orders in sales.
- It counted configurable and bundle lines twice.
- It showed bundle and grouped parents as "out of stock".
- It compared the dashboard against an empty "previous period" (always +100% for Today and Yesterday).
- It hard-coded attribute IDs (the prompt said price is 75; here it is 77).

### 13.2 Data rules now used everywhere (`Model/Report/ReportContext.php`)

| Rule | Implementation |
|---|---|
| Which orders are sales | `state NOT IN ('canceled','pending_payment')`. Status breakdowns still show every state. |
| Revenue | `(base_grand_total − base_total_refunded) × base_to_global_rate`, in the global base currency (`currency/options/base`) |
| Product revenue | `(base_row_total − base_discount_amount − base_amount_refunded + base_discount_refunded) × rate`, top-level lines only (`parent_item_id IS NULL`) |
| Quantity sold | `qty_ordered − qty_refunded − qty_canceled` |
| Dates | Store-local ranges converted to UTC `created_at >= ? AND created_at < ?`. Day, month and weekday grouping uses a DST-exact `CASE` of UTC offsets built from `DateTimeZone::getTransitions()`, so MySQL timezone tables are not needed. |
| Series | Month, day and weekday series are zero-filled so charts have no gaps |
| Buyers | Identified by order email, so guests are included |
| Stock | Only stock-managed products of quantity types (`StockConfiguration::getIsQtyTypeIds`). With MSI, quantity is summed over enabled sources in `inventory_source_item`. Out of stock is based on `is_in_stock`. |
| Catalog | Attribute IDs are looked up at runtime. The link field comes from `MetadataPool` (so `row_id` works on Adobe Commerce). Root categories (level < 2) are excluded, and admin-store names are used. |
| Tables | Every table name goes through `ResourceConnection::getTableName()`, so a table prefix works |
| Money display | `PriceCurrencyInterface::format()` in the base currency, in the blocks, the dashboard template and the CSV export |

### 13.3 Files changed

- **New:** `Model/Report/ReportContext.php`, `Test/Unit/Model/Report/ReportContextTrait.php`, `Test/Unit/Model/Report/ReportContextTest.php`
- **Rewritten queries** (same public methods and array keys, so the templates still work): `Model/Report/{Sales,Customer,Product,Inventory}Report.php`, `Block/Adminhtml/Dashboard.php`
- **Currency formatting:** `Block/Adminhtml/Reports/*.php`, `view/adminhtml/templates/dashboard_enhanced.phtml`, `Controller/Adminhtml/Dashboard/Export.php` (which also sanitises the file name and adds a currency row)
- **AI prompt:** `Model/Query/NlpToSql.php` now appends a "STORE FACTS" block (real attribute IDs, timezone and UTC bounds of today, base currency, sales rules, MSI stock table) in place of the hard-coded attribute IDs
- **Dependencies:** `etc/module.xml` (Magento_CatalogInventory), `composer.json` (module-catalog-inventory, module-directory)
- **Tests:** the report tests are rewritten; `NlpToSqlTest` is updated for the new constructor and the PHPUnit 12 `#[DataProvider]` attribute

### 13.4 Deployment note

`generated/metadata` holds compiled DI. The blocks, reports and `NlpToSql` have new constructor arguments, so run the following before opening the admin pages:

```bash
bin/magento setup:di:compile && bin/magento cache:flush
```

### 13.5 Not changed (still open)

- There is no caching of report results. (The fake data and security issues are handled in §14.)

---

## 14. Fake data removal and security fixes (2026-09-24)

### 14.1 Fake test data removed

**From the database:** all rows created by `InsertTestData` were deleted in one transaction, with each row count checked:

| Table | Rows deleted |
|---|---|
| `sales_order` | 50 |
| `sales_order_item` | 123 |
| `sales_order_address` | 100 |
| `sales_order_payment` | 50 |
| `customer_entity` | 25 |
| `customer_grid_flat` | 25 |
| `meetanshi_aireporting_saved_report` | 5 |
| `meetanshi_aireporting_query_log` | 8 |

The fake rows had no invoices, shipments, credit memos, quotes, reservations or addresses, and no real order referenced a fake customer. A backup of every deleted row is in `var/backups/aireporting_fake_test_data_2026-09-24.sql`. To restore, run `mysql <db> < file`.

The database now holds the real data: 25 orders ($986 gross, $947 net), 2 customers, 25 order-grid rows and no orphaned items.

**From the code:** `Setup/Patch/Data/InsertTestData.php` is deleted, so new installs get no fake data. The old `patch_list` row is harmless. `Test/sql/insert_test_data.sql` and `cleanup_test_data.sql` remain as manual, opt-in developer scripts.

### 14.2 How AI and saved-report SQL now runs

```
question ─► NlpToSql (LLM) ─► SqlGuard::sanitize ─┐
saved report (report_id, owner only) ─────────────┼─► QueryExecutor
                                                   │     1. SqlGuard::sanitize          (again — defence in depth)
                                                   │     2. SqlGuard::applyRowLimit     (top-level LIMIT ≤ Max Result Rows)
                                                   │     3. ReadOnlyConnectionProvider  (separate DB connection)
                                                   │     4. SET max_statement_time / MAX_EXECUTION_TIME = Query Timeout
                                                   │     5. START TRANSACTION READ ONLY → fetchAll → ROLLBACK
                                                   │     6. SqlGuard::redactRows        (secrets → "[redacted]")
browser never sends SQL ◄──────────────────────────┘
```

### 14.3 Fixes by issue

| Issue | Fix |
|---|---|
| **C1** raw `sql` parameter | `Query/Execute` accepts only `query` (natural language) or `report_id`. A saved report runs only if `admin_user_id` matches the logged-in admin. |
| **C2** saved report SQL from the browser | `Execute` stores the executed SQL in the admin session (`Model/Query/QueryTokenStorage`) and returns a random `query_token`. `Report/Save` takes only `title`, `query_token` and `chart_type` (whitelisted), and reads the SQL from the session. The Saved Reports page lists only the admin's own reports. |
| **H1** weak SELECT check | `Model/Query/SqlGuard` lexes the SQL (string literals, backticks, comments). It strips comments, including `/*! executable */` ones, so the SQL that is validated is exactly the SQL that runs. It requires a single `SELECT`/`WITH` statement, and rejects `UPDATE`/`DELETE` anywhere (so `WITH … DELETE` fails), `INTO`, `FOR UPDATE`/`LOCK IN SHARE MODE`/`FOR SHARE`, `SLEEP`/`BENCHMARK`/`LOAD_FILE`/locking functions, `@` variables, the `mysql`/`information_schema`/`performance_schema`/`sys` schemas, and other databases. As a second layer, the query runs in a `READ ONLY` transaction on its own connection (verified: MariaDB refuses writes with error 1792). |
| **H2** secrets and PII | Credential tables are blocked: `admin_user*`, `authorization_*`, `oauth_*`, `integration`, sessions, vault tokens, `login_as_customer*` and this module's own tables. So are secret columns such as `password_hash`, `rp_token`, `token`, `secret`, card data and `additional_information`. `SELECT *` is refused on tables that contain secret columns. Config queries that name credential paths are refused. Encrypted values and credential config paths are redacted from results. The LLM schema prompt no longer lists `admin_user` or `oauth_token`, and tells the model never to use `SELECT *`. Chat result rows go to the LLM only when **Send Query Results to AI Provider** is set to Yes (default No). |
| **H3** no DB timeout | `max_statement_time` on MariaDB or `MAX_EXECUTION_TIME` on MySQL is set from **Query Timeout** (verified: a slow query was stopped after 1 s with a friendly message) |
| **M1** row cap | `applyRowLimit()` appends `LIMIT n` or lowers a larger top-level `LIMIT`/`LIMIT o, n`/`LIMIT n OFFSET o`. A `LIMIT` inside subqueries or strings is ignored. |
| Stored XSS in report pages | Inline `json_encode()` in the report and dashboard templates now uses `JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT` |
| Gemini key in URL | The key is sent in the `x-goog-api-key` header |
| CSP | The inline `onclick` in chat is replaced by a delegated handler. The storefront-wide `etc/csp_whitelist.xml` is removed; the admin one stays. |
| Dead code / config | `templates/dashboard.phtml` is deleted (it posted raw SQL). The unused `security/allowed_roles` is removed. |

### 14.4 Recommended: a dedicated read-only database user

The guard and the read-only transaction are active now. For a third layer, give AI queries their own MySQL user that can only `SELECT` from the Magento database:

```sql
CREATE USER 'm249_ai_ro'@'localhost' IDENTIFIED BY '<strong password>';
GRANT SELECT ON m249.* TO 'm249_ai_ro'@'localhost';
```

Then add it to `app/etc/env.php` under `db → connection` as `'aireporting' => [...]`, using the same keys as `default`. `ReadOnlyConnectionProvider` uses it automatically when present.

### 14.5 Files

- **New:** `Model/Query/SqlGuard.php`, `Model/Query/ReadOnlyConnectionProvider.php`, `Model/Query/QueryTokenStorage.php`, `Test/Unit/Model/Query/SqlGuardTest.php`
- **Changed:** `Model/Query/QueryExecutor.php`, `Model/Query/NlpToSql.php`, `Controller/Adminhtml/Query/Execute.php`, `Controller/Adminhtml/Report/Save.php`, `Controller/Adminhtml/Chat/Send.php`, `Block/Adminhtml/SavedReports.php`, `Model/Config.php`, `Model/LLM/GeminiProvider.php`, `etc/config.xml`, `etc/adminhtml/system.xml`, `view/adminhtml/templates/{query,saved_reports,dashboard_enhanced}.phtml`, `view/adminhtml/templates/reports/*.phtml`
- **Tests rewritten:** `ExecuteTest`, `SaveTest`, `QueryExecutorTest`, `SavedReportTest` (PHPUnit 12)
- **Deleted:** `Setup/Patch/Data/InsertTestData.php`, `etc/csp_whitelist.xml`, `view/adminhtml/templates/dashboard.phtml`

After deploying, run `bin/magento setup:di:compile && bin/magento cache:flush`.

---

## 15. Ollama (local LLM) fixes (2026-09-24)

### 15.1 What was wrong

1. **The HTTP 404:** the configured model `llama3.2` was not installed. Ollama answered `{"error":"model 'llama3.2' not found"}`, but the module showed only a generic message.
2. **The installed model can't run here:** the only installed model, `qwen3-coder:latest` (30.5B, 18.5 GB), needs about 17.7 GB of RAM. This machine has 15 GB and no GPU, so the OOM killer stopped Ollama while it was loading the model (`journalctl -u ollama`).
3. **The prompt was cut off:** the prompt is about 4.8–5.3k tokens, but Ollama's default context is typically 4,096 tokens and it silently drops the start of the prompt (instructions and schema). The module did not set `num_ctx`, `temperature` or `num_predict` for Ollama.
4. **The timeout was too short:** 30 s is too little for a local CPU model, especially on the first request, which also loads the model.

### 15.2 Changes

- **`Model/LLM/OllamaProvider.php`:**
  - The context size is set in stable steps (8,192, 16,384 or 32,768 tokens). A stable size avoids a model reload on every change and lets Ollama reuse the cached schema prefix.
  - Sends `temperature 0.1`, `num_predict = Max Tokens` and `keep_alive 30m`.
  - Accepts a base URL entered with `/api` or `/api/chat`.
  - Strips `<think>` blocks from answers.
  - Gives actionable errors: a missing model lists the installed models; OOM, a dropped connection and a timeout each have their own message.
- **New setting "Ollama Request Timeout"** (`general/ollama_timeout`, default 240 s, kept under Apache's `Timeout 300`).
- **`Model/Query/QueryRunner.php` (new):** runs question → SQL → execution. If the database rejects the SQL for a fixable reason (unknown column, syntax, GROUP BY …), it asks the LLM **once** to correct it. Security rejections and timeouts are never retried. Used by Ask AI and Chat.
- **`NlpToSql`:** adds `repair()`, plus join notes and worked examples built from this store (real attribute IDs, this month's UTC bounds, the timezone offset, guest-safe customer grouping). These make small local models far more accurate.
- **Tests:** `OllamaProviderTest` and `QueryRunnerTest` are new. 214 tests, all passing.

### 15.3 Model on this machine

`qwen2.5-coder:3b` (1.9 GB) was pulled and configured with `bin/magento config:set`. It uses about 2.4 GB in memory at an 8,192-token context. Measured on this CPU: 8 s to load, then 9–37 s per question.

End-to-end results on real data:
- Orders and revenue for a month used the correct store-timezone bounds.
- Best sellers, most expensive products (price attribute 77), revenue by status, top customers (guest orders included) and daily revenue in local dates were all correct.
- "Delete all canceled orders" was blocked.

To use a bigger model (for example `qwen3-coder`), use a machine with 24 GB+ RAM or a GPU.

### 15.4 Server note: duplicate root cron (action needed)

**Root's** crontab and `jay`'s crontab both run `bin/magento cron:run` every minute. Root's copy creates root-owned files in `generated/` that the web server cannot write to. On 2026-09-24 this took the site down for about 4 minutes (15:42–15:46) (HTTP 500: `Theme\Collection\Interceptor does not exist`) after a `setup:di:compile` attempt cleared `generated/`.

The site was restored by renaming the 149 root-owned directories to `.stale-root-*` and letting the web server regenerate them. **This is not permanent:** root's cron keeps creating root-owned directories (265 more at 15:46), so pages or CLI commands that need a class inside one of them can fail until the steps below are done. To fix it for good, run as a sudoer:

```bash
sudo crontab -e      # delete the "bin/magento cron:run" line from ROOT's crontab (jay's crontab already runs it)
cd /var/www/html/m249
sudo find generated -mindepth 1 -maxdepth 1 ! -name .htaccess -exec rm -rf {} +
sudo chown -R jay:www-data generated var pub/static && sudo chmod -R 2775 generated var pub/static
bin/magento cache:flush
```

## 16. Chat: any store table and third-party modules (2026-10-01)

### 16.1 What was wrong

- The LLM saw a fixed list of about 45 core tables. Questions about anything else failed or got invented table names: tracking numbers, order comments, catalog price rules, stock alerts, tax rates, most-viewed products, Adobe Commerce tables, and so on.
- Third-party tables were added only when the question contained a keyword ("module", "config", "cache"…) or a word inside a table name. When they were added, **every** third-party table was dumped into the prompt.
- Columns that modules add to core tables (for example `sales_order.eattachment_email_sent`) were never shown.
- "Is the Size Chart extension enabled?" or "What version of X is installed?" became SQL against `core_config_data`/`setup_module`. That cannot tell whether a module is enabled, and it misses `config.xml` defaults.
- Redis, Varnish, session storage and RabbitMQ live in `app/etc/env.php`, not the database, so SQL answers about them were wrong ("not configured").

### 16.2 How it works now

- **`Model/Schema/SchemaCatalog`** reads every table, column and table comment from `INFORMATION_SCHEMA`. Credential tables, secret columns and index/changelog/temp tables are skipped. The result is cached with the `CONFIG` tag, so `cache:clean config` and `setup:upgrade` refresh it. For each question it scores tables by name words (whole, joined such as `giftcard`, or at a word's start/end), by comment, and by columns. Rare words weigh more, a few synonyms help ("return" → `rma`, "loyalty" → `reward`), and the top 8 tables are sent with their real columns.
- **`Model/Schema/ModuleCatalog`** reads each module's `etc/db_schema.xml` (the tables it creates and the columns it adds to other modules' tables), its `composer.json`, and its `etc/adminhtml/system.xml`, including `<include>` files. It recognises a module in a question by code (`Meetanshi_SizeChart`), by vendor + name ("Meetanshi size chart"), by a distinctive name ("size chart"), or by its composer description/package or config section label ("email attachments"). Core modules are recognised only by their code. Cached like the schema.
- **`NlpToSql`**
  - The core table list is checked against the live schema, so columns or tables this store doesn't have are dropped.
  - A "MORE TABLES FROM THIS STORE" section adds the named module's tables first, then the other best-matching tables, labelled `[module Vendor_Name]` for third-party ones. It also adds "`<core table>` also has: …" for columns that match the question or were added by a named module.
  - The reply may be `ANSWER: …` (`DirectAnswerException`).
  - The SQL is taken out of surrounding prose or code fences. A write statement before or after it is still rejected.
  - `repair()` now includes the real columns of every table the failed query used, plus similar table names when a table doesn't exist.
- **`ModuleInfoResponder`** answers module questions (status, versions, tables with row counts, added columns, settings with values) without SQL. Yes/No settings show Yes/No, secrets show `[hidden]`, and website/store overrides are counted. "How many size charts are enabled?" is a data question and still goes to SQL, now with the module's table.
- **`SystemInfoResponder`** adds:
  - infrastructure from `env.php`: cache and page-cache backends, FPC application, sessions, search engine, queue, lock provider and media storage, with hosts only and never passwords;
  - cache type status;
  - disabled modules;
  - versions in the third-party list.

### 16.3 Deployment note

Constructors of `NlpToSql`, `SystemInfoResponder` and `Chat\Send` changed. On a store with compiled DI (`generated/metadata` exists), run `bin/magento setup:di:compile`, then `bin/magento cache:clean config`. This was done on this machine on 2026-10-01.

### 16.4 Files

- **New:** `Model/Schema/SchemaCatalog.php`, `Model/Schema/ModuleCatalog.php`, `Model/ModuleInfoResponder.php`, `Exception/DirectAnswerException.php`, and tests `Test/Unit/Model/Schema/{SchemaCatalogTest,ModuleCatalogTest,SchemaCatalogTrait}.php`, `Test/Unit/Model/{ModuleInfoResponderTest,SystemInfoResponderTest}.php`
- **Changed:** `Model/Query/NlpToSql.php`, `Model/SystemInfoResponder.php`, `Model/Query/SqlGuard.php` (`isSecretConfigValue()`), `Controller/Adminhtml/Chat/Send.php`, `view/adminhtml/templates/query.phtml` (welcome text, two quick buttons, list formatting), `Test/Unit/Model/Query/NlpToSqlTest.php`

## 17. Accuracy, provider reliability and follow-up questions (2026-10-07)

### 17.1 What was wrong

The query log (79 entries) showed where questions failed:

- **14 of 19 errors were provider problems**, not SQL: HTTP 404 (retired or wrong model name), 429 (rate limit) and a missing key. OpenAI and OpenRouter never retried, Groq retried only 429, and Groq reported a 404 as "check your API key".
- **The built-in Claude model list offered only retired models** ("Claude 3.5 Haiku (Fast, Recommended)"), so a Claude setup failed with 404 out of the box. The provider also always sent `temperature`, which current Claude models reject with HTTP 400.
- **Every provider hard-coded an "SQL only, no explanation, no markdown" system prompt.** The chat answer step (friendly markdown) and the `ANSWER:` direct reply went through the same call, so the model was given contradictory instructions.
- **Silent wrong answers:** "how many customers have loyalty cards" returned *success* by reading `salesrule_customer` (coupon usage). The store has no loyalty data.
- The LLM timeout reused the **database** query timeout (30 s).
- Chat questions were never written to the query log; chat had no memory, so "and last month?" failed.
- The prompt test runner only checked that SQL *runs*, not that it returns the right data.

### 17.2 Decision: no vector database

Considered and rejected for now. None of the logged failures was a table the keyword retrieval missed. At 400–2,000 tables, `SchemaCatalog` is the right tool. A vector store would mean extra infrastructure for every merchant, and Claude and Groq have no embeddings API. Semantic search also always returns a "nearest" table, which would make the loyalty-card case worse. If `run_gold_test.php` later shows paraphrase misses, store embeddings in a MySQL table and compare them in PHP; that needs no vector database.

### 17.3 Changes

- **Providers (`Model/LLM`)**
  - `ProviderInterface::complete($prompt, $system = '')`: the caller sets the system prompt.
  - New `AbstractProvider` handles timeout, retries of 408/429/5xx/529 (honouring `Retry-After`, max 10 s wait, 2 retries) and errors that name the model and quote the provider's own message.
  - New `AbstractChatCompletionsProvider` is the base for OpenAI, Groq and OpenRouter. OpenAI uses `max_completion_tokens` and sends no temperature to reasoning models (o-series, gpt-5).
  - Gemini skips "thought" parts and explains `MAX_TOKENS`. Ollama got only the system-prompt parameter.
- **Claude**
  - The schema and rules are sent as a system block with `cache_control` (prompt caching).
  - `temperature` is sent only to models that accept it.
  - Models that think by default (Opus 5.x, Sonnet 5.x, Fable) get `max_tokens` ≥ 16000, because thinking counts against it.
  - `stop_reason: refusal` and `max_tokens` produce clear errors.
  - Opus 5.5/5, Sonnet 5.5 and Fable 5.1 send `fallbacks: "default"` (beta `server-side-fallback-2026-07-01`), so a declined request is retried server-side.
  - The model list is now Opus 5.5 (default), Sonnet 5.5 and Haiku 4.5.
- **Configuration**
  - New **AI Request Timeout** (`general/llm_timeout`, default 120 s). Query Timeout is relabelled "Database Query Timeout".
  - **Test Connection** button next to "Fetch Latest Models" (`config/testConnection`). It sends a tiny request with the saved key and model.
  - New **Database User for AI Queries** status row. It shows whether a dedicated `aireporting` connection is used and warns if that user has write privileges.
- **`NlpToSql`**
  - System prompt = role + verified core schema + rules. It is identical for every question, so it can be cached.
  - Per-question message = store facts, matched live tables, then the new parts:
    - **WORDS WITH NO MATCH** lists question words found in no table, column or comment (`SchemaCatalog::findUnknownWords`; measure words such as "spend" are ignored, and synonyms count only via table names). The model is told to reply `ANSWER:` rather than substitute an unrelated table.
    - **SAVED REPORTS SIMILAR TO THIS QUESTION** includes up to 3 saved reports, found by word overlap (`ExampleFinder`).
    - **EARLIER IN THIS CONVERSATION** includes chat history. Table retrieval also uses the previous question.
  - The model may start with `ASSUMPTION: …` when it had to interpret the question. This is returned as `assumption`, shown as "Interpreted as: …" in Ask AI and Chat, and returned by `QueryRunner::run()`.
- **Chat (`Chat\Send`)**
  - Has its own answer system prompt; result rows are marked as data, not instructions.
  - Logs every question (SQL, direct and failed answers) through the new `Model/QueryLogger` (also used by `Query\Execute`).
  - Keeps the last 3 turns in the admin session (`Model/Query/ChatHistory`, 30 min expiry). "Clear chat" posts `reset=1`.
- **CLI:** `bin/magento meetanshi:aireporting:readonly-grants --user=<name> [--host=<host>]` prints `CREATE USER` + per-table `GRANT SELECT` for every table except the credential/session tables SqlGuard refuses, plus the `env.php` snippet. It executes nothing. Re-run it after installing modules.
- **Accuracy test:** `Test/ai-prompts/gold_queries.json` (30 cases: sales, customers, products, promotions, system, one follow-up, three "no data") and `run_gold_test.php`. A case passes when the reference result is contained in the AI query's result. `--check-gold` validates the reference queries without AI calls.

### 17.4 Measured accuracy

Not measured yet. The first run on 2026-10-07 (local Ollama, `qwen2.5-coder:3b`) was interrupted by a session restart after 3 of 30 cases: 1 passed, and 2 hit the 240 s Ollama timeout on this CPU. To measure, raise "Ollama Request Timeout" (or use a hosted provider) and run:

```bash
php app/code/Meetanshi/AIReporting/Test/ai-prompts/run_gold_test.php
```

### 17.5 Deployment note

Constructors changed (`NlpToSql`, `QueryRunner` callers, `Chat\Send`, `Query\Execute`, `ModelFetcher`, all providers) and a console command was added. On compiled-DI stores run `bin/magento setup:di:compile` and `bin/magento cache:flush`. This was done on this machine on 2026-10-07, using the §15.4 procedure. No database schema change.

Saved reports freeze dates: a report saved for "this month" keeps the literal UTC dates it was generated with. Not changed here.

### 17.6 Files

- **New:** `Model/LLM/AbstractProvider.php`, `Model/LLM/AbstractChatCompletionsProvider.php`, `Model/Query/ExampleFinder.php`, `Model/Query/ChatHistory.php`, `Model/QueryLogger.php`, `Controller/Adminhtml/Config/TestConnection.php`, `Block/Adminhtml/System/Config/ReadOnlyConnectionStatus.php`, `Console/Command/ReadOnlyGrants.php`, `Test/ai-prompts/gold_queries.json`, `Test/ai-prompts/run_gold_test.php`, tests `Test/Unit/Model/LLM/{ClaudeProviderTest,ChatCompletionsProviderTest}.php`, `Test/Unit/Model/Query/{ExampleFinderTest,ChatHistoryTest}.php`
- **Changed:** all `Model/LLM/*Provider.php`, `Model/LLM/ProviderInterface.php`, `Model/LLM/ModelFetcher.php`, `Model/Config.php`, `Model/Config/Source/ClaudeModel.php`, `Model/Query/{NlpToSql,QueryRunner}.php`, `Model/Schema/SchemaCatalog.php`, `Controller/Adminhtml/{Chat/Send,Query/Execute}.php`, `Block/Adminhtml/System/Config/ModelField.php`, `etc/{config,di}.xml`, `etc/adminhtml/system.xml`, `view/adminhtml/templates/query.phtml`, `view/adminhtml/web/css/aireporting.css`, `Test/Unit/Model/Query/NlpToSqlTest.php`, `Test/Unit/Controller/Adminhtml/Query/ExecuteTest.php`

## 18. Executive Dashboard redesign (2026-10-07)

The Dashboard page (`dashboard/index`) now follows the Stitch design "Executive Dashboard - Meetanshi AI Reporting" (project "Magento AI Reports Redesign"). Only the page content changed: Magento's admin menu and header are untouched, and every style is scoped to `.mxd` in a CSS file loaded only on this page.

### 18.1 Sections and where the numbers come from

| Section | Data |
|---|---|
| Header | Magento name/version/edition, default store view and website, active AI provider and model (green dot when a key/URL is configured), date range with resolved dates. **Live** refreshes every 60 s while switched on (off by default, so an idle tab does not keep the admin session alive). **Export** is the existing CSV export for the selected range. **Ask Copilot** opens Ask AI. |
| KPI cards | Net revenue (with refunds), orders (with completed count), AOV (with best weekday), active buyers (with repeat buyers), units sold (with SKUs moved and top seller), catalog health (share of stock-managed products neither out of stock nor ≤ 10, stock value = qty × base price). Changes compare with the previous period of the same length. |
| Revenue & order trend | Daily net revenue and orders (store time), Overlaid / Revenue / Orders modes, peak revenue day callout, peak order day, store timezone. Chart.js. |
| Orders by status | Every order placed in the period (all states), count, share and gross value per status. |
| Top categories | Top 5 by net item revenue, share of total net revenue (a product in two categories counts in both, so shares can add up to more than 100%), number of categories with sales. |
| Customer segments | Buyers by email (guests included) with 6+ orders (VIP), 2–5 (Returning), 1 (First-time): buyers, transactions, revenue and share. Repeat rate. The insight box is computed: concentration risk when VIP brings ≥ 50% of revenue, a repeat-purchase tip when ≥ 50% of buyers ordered once, otherwise "balanced". |
| Day of week | Net revenue per weekday (store time), peak and runner-up highlighted, and the weekday + hour with the most orders. |
| Top products | Up to 200 products sold in the period: deepest category, units, net revenue, average realized price, current stock (MSI-aware; "Not tracked" for product types without quantity). Search, category filter, 5 per page, CSV export of the filtered rows (formula-safe), link to the product edit page, and an AI action that asks the assistant about that SKU. |
| AI assistant | Sends the question to `chat/send` (same pipeline, logging and history as Chat) and shows the answer, the AI's assumption, rows/time and the SQL. |

The design's "AI anomaly detection" and "optimal campaign trigger" texts were not used, because nothing computes them. The page shows the peak day and the busiest ordering hour instead.

### 18.2 Implementation

- `Block/Adminhtml/Dashboard.php` keeps every existing array key (the CSV export uses them) and adds: `kpis.refunded`, `complete_orders`, `skus_sold`, `stock_items`, `stock_healthy_pct`, `inventory_value`, `best_aov_day`, `top_product`, `repeat_buyers`; `trend_stats`; `category_count` and `share`; `products`; `peak_window`; status `code`; segment `transactions` (all three segments always returned). New constructor argument `ProductMetadataInterface`. `getJsConfig()` gives the script its data and URLs.
- `Model/Config::getActiveModel()` and `isActiveProviderConfigured()`.
- `view/adminhtml/templates/executive_dashboard.phtml` is the skeleton. It is filled by `view/adminhtml/web/js/executive-dashboard.js` (RequireJS module started via `x-magento-init`, no inline script), which renders from the page data on load and from `dashboard/refreshData` on every refresh.
- `view/adminhtml/web/css/executive_dashboard.css`: scoped styles, px units (the admin root font size is 62.5%), resets for Magento's global button/input styles inside `.mxd` only. Layout: 6 KPIs per row above 1500 px, 3 per row below; the 3 insight cards become 2 + 1 between 1200 and 1500 px, and everything stacks below 1200 px.
- Fonts: IBM Plex Sans, JetBrains Mono and Material Symbols from Google Fonts, added to the page head in `meetanshi_aireporting_dashboard_index.xml`, and whitelisted in `etc/adminhtml/csp_whitelist.xml` (`style-src fonts.googleapis.com`, `font-src fonts.gstatic.com`).
- `templates/dashboard_enhanced.phtml` is no longer used. It is kept because it has uncommitted edits. `css/dashboard_enhanced.css` stays: the report pages use its classes.

### 18.3 Verification

The page was rendered from the CLI with Magento's admin stylesheet and screenshotted in headless Chrome at 1600, 1366, 1280 and 1024 px, including an empty period. Paging, search, category filter, CSV export, chart modes and the export link were tested, with no JavaScript errors. All date ranges return data in 3–12 ms. The live admin page was not opened from this session (no admin login).

### 18.4 Deployment note

The block constructor changed: run `bin/magento setup:di:compile` and `bin/magento cache:flush` (done on this machine on 2026-10-07).


## 19. Inventory report redesign (2026-10-08)

The Inventory page (`reports/inventory`) now follows the Stitch design "Inventory Report - Meetanshi AI Reporting" (project "Magento AI Reports Redesign"). Only the page content changed: Magento's admin menu, header, menu entry and ACL are untouched, and every style is scoped to `.mxd.mxi` in a CSS file loaded only on this page.

### 19.1 Sections and where the numbers come from

| Section | Data |
|---|---|
| Header | Magento name/version/edition, stock source (MSI with the number of enabled sources, or single-source `cataloginventory`), default store view, query time, store timezone. Range filter: Today, This Week, This Month, This Year, custom (shared `Model/Report/ReportRange`, default This Year). **CSV** exports the open table tab with its filters; **PDF** prints the page with every row of the open tab; **Ask Copilot** opens the follow-up panel. |
| KPI cards | SKUs (in stock / disabled), stock value at list price (units on hand, units reserved), out of stock with depletion %, low stock (≤ 10 available) with the count at ≤ 5 or under 14 days of cover, stock turnover for the range (units sold ÷ units on hand) and its 365-day run-rate. Out-of-stock, low-stock and turnover cards open the matching tab. |
| Stock Level Distribution | SKUs per tier of available quantity: 101+ (High Reserve), 51–100 (Optimal), 11–50 (Buffer), 1–10 (Critical Low), Out of Stock. Clicking a tier filters the table. Total catalog valuation. |
| Top Sellers: Demand vs Current Stock | The 6 SKUs with the most units sold in the range: sold vs available, and days of cover coloured red < 14, amber < 30, green 30–90, blue > 90. |
| SKU table | Tabs: All Stocked SKUs; Low Stock & Depletion (out of stock, ≤ 10 available, or under 14 days of cover); Dead Stock / Idle Capital (in stock with no sale for over 90 days, or never sold); Turnover & Run-Rate (SKUs sold in the range). Search (SKU or name), MSI source and category filters, sortable columns, 10/20/50/all per page. Per row: deepest category, quantity per source, available, reserved, list price, stock value, units sold, days of cover, status, links to the product edit page and (dead stock) a new cart price rule, and an AI button that asks about the SKU's sales history. Below 1680 px the Reserved and Unit Price columns move under Available and Stock Value. |
| Copilot analysis | Rule-based text from the figures (in-stock rate, SKUs needing attention and which runs out first, idle capital, top seller, reorder candidates, overstock). **Export Clearance List** = dead stock by idle capital (CSV); **Generate PO Reorder Plan** = selling SKUs under 30 days of cover with the quantity that brings them to 60 days at the range's sales pace (CSV); **Inspect Raw Query** shows the SKU-table SQL; follow-up questions go to `chat/send`. |

Definitions: available = on hand − reserved (MSI reservations are units held by orders not yet shipped; without MSI, `cataloginventory` quantity is already net of orders and reserved is 0). A SKU is out of stock when its stock status is out of stock or nothing is available. Days of cover = available ÷ (units sold in the range ÷ days in the range). Units sold are net of refunds and cancellations, on the simple/child lines that hold stock; cancelled and pending-payment orders are excluded.

The design's "+8.4% vs prior cycle" stock-value change, "Model: Meetanshi Ecom-SQL v2.4", "MSI Inventory Sync: every 15m" and "Simulate Clearance Bundle" were not used, because nothing records stock history or computes them; the page shows reserved units, a rule-based label, the stock source and a clearance-list export instead. Product images are not shown (an icon per category keyword is), so the table does not download full-size originals.

### 19.2 Implementation

- `Model/Report/InventoryReport.php`: new `getSkuRows()` / `getSkuRowsSql()` (one query: stock, name, list price, status, MSI reservations, units sold in the range, last sale), `summarize()`, `getSources()`, `getSourceQuantities()`, `getProductCategories()` (with the parent's name for the category filter), `rangeDays()`; thresholds are class constants (`LOW_STOCK_QTY`, `CRITICAL_COVER_DAYS`, `HEALTHY_COVER_DAYS`, `IDLE_AFTER_DAYS`, `TIERS`). The older public methods are unchanged.
- `ViewModel/InventoryAnalytics.php` (new): page data, up to 500 rows per tab sent to the browser (exports include every row), export rows and the reorder quantity.
- `Controller/Adminhtml/Reports/InventoryData.php` (JSON) and `InventoryExport.php` (CSV, formula-safe, written to a one-off file in `var/export` that is deleted after sending). Both check `::reports_inventory`.
- `view/adminhtml/templates/reports/inventory.phtml` is the skeleton, filled by `view/adminhtml/web/js/inventory-report.js` (RequireJS module started via `x-magento-init`). Styles: `view/adminhtml/web/css/inventory_report.css` on top of `mxd_base.css`.
- `Block/Adminhtml/Reports/Inventory.php` was removed; the layout uses `Magento\Backend\Block\Template` with the view model.

### 19.3 Verification

The template was rendered from the CLI and loaded in headless Chrome with Magento's admin stylesheet; data and range changes were served by the real view model. A scripted run covered tabs, search, category/source/tier filters, sorting, paging, every range including a custom one, the SQL inspector and AI follow-ups (38 checks, no JavaScript errors). The table fits without horizontal scrolling from 1280 px up. Every export tab was run through the controller (filters applied, temporary file removed). Unit tests: `Test/Unit/Model/Report/InventoryReportTest.php` (new cases for row derivation, MSI/legacy SQL and totals). The live admin page was not opened from this session (no admin login); unauthenticated requests to the new routes redirect to the login page.

### 19.4 Deployment note

No existing constructor changed and there is no database change. The new classes work without `setup:di:compile`, but until the next compile the two new controllers run without interceptors (see §15.4 before compiling on this machine).
