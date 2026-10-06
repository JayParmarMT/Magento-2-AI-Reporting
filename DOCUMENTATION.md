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
│   ├── Query.php                  # "Ask AI" page: URLs, form key, suggested questions
│   ├── SavedReports.php           # Saved reports list (collection)
│   └── Reports/{Sales,Customer,Product,Inventory}.php  # Thin wrappers over Model/Report/*
├── Controller/Adminhtml/
│   ├── AbstractAction.php         # Base page action (PageFactory + ACL)
│   ├── Dashboard/{Index,RefreshData,Export}.php
│   ├── Query/{Index,Execute}.php  # Execute = main AJAX endpoint (NLP→SQL→rows)
│   ├── Chat/Send.php              # AJAX chat endpoint (NLP→SQL→rows→LLM answer) [untracked]
│   ├── Report/{Saved,Save}.php
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
| Pre-built → Sales & Revenue | `reports/sales` | `Reports\Sales` | `::reports_sales` |
| Pre-built → Customer Analytics | `reports/customer` | `Reports\Customer` | `::reports_customer` |
| Pre-built → Product Performance | `reports/product` | `Reports\Product` | `::reports_product` |
| Pre-built → Inventory | `reports/inventory` | `Reports\Inventory` | `::reports_inventory` |

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

`saved_reports.phtml` stores `{report_id, chart, nlp}` in `sessionStorage` and redirects to `query/index`. `query.phtml` then POSTs `{report_id}` to `query/execute`. The server loads the SQL from the database, but only if the report belongs to the logged-in admin, and runs it through the same checks as AI-generated SQL. Raw SQL from the browser is no longer accepted (§14).

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
| **Sales** | KPI cards for today, week, month and year (orders, revenue, AOV); revenue by month (12 months); revenue by day (30 days); orders by status (all time); revenue by shipping method (top 10); coupon usage (top 20); revenue by shipping region (top 15); refund and cancel summary; revenue by day of week (6 months) |
| **Customer** | KPIs (total, new today/week/month, distinct emails with orders); top 20 customers by spend; RFM scoring for 50 customers (the block labels them Champions, Loyal, New, At Risk, Lost or Potential); new vs returning per month; acquisition per month; CLV buckets; repeat purchase rate |
| **Product** | KPIs (total, simple, configurable, SKUs sold, quantity sold); best sellers by revenue and by quantity (20 each); worst sellers (10); revenue by product type; monthly trend of the top 5 SKUs; products never sold (20); revenue by category (15) |
| **Inventory** | KPIs (SKUs, in stock, out of stock, low stock ≤ 10, stock value = qty × base price); low-stock list (≤ 10, 30 rows); out-of-stock list (30); stock distribution; demand vs supply (30-day sales vs stock, flags CRITICAL below 7 days of cover and LOW below 14); turnover rate (30 days) |

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
- No admin grid or screen shows the query log. It is written to but never displayed.
- Saved reports cannot be edited or deleted from the UI.

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

