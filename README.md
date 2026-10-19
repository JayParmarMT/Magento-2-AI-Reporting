# Meetanshi AI Reporting: Configuration Guide

This guide explains every setting of the AI Reporting extension: what it does, its default, and when to change it.

**Where to find it:** **Stores → Configuration → Meetanshi → AI Reporting**, or **Meetanshi AIReporting → Configuration** in the admin menu.
**Who can see it:** admin roles with the **Meetanshi AI Reporting → Configuration** permission (System → Permissions → User Roles).

Changes take effect as soon as you click **Save Config**. If you change a value from the command line with `bin/magento config:set`, also run `bin/magento cache:clean config`.

---

## Quick setup

1. Set **LLM Provider** to the AI service you want to use.
2. Enter that provider's **API Key** and click **Save Config**. Ollama has no key; enter its URL and model name instead.
3. Click **Fetch Latest Models**, pick a model, and click **Save Config** again.
4. Click **Test Connection**. A success message means the key and model work.
5. Optional but recommended: set up a dedicated read-only database user. See [Database User for AI Queries](#database-user-for-ai-queries).

---

## All settings at a glance

| Setting | Default | What it controls |
|---|---|---|
| Enable AI Reporting | Yes | Turns the extension's AI features and pages on or off |
| LLM Provider | Groq | Which AI service turns questions into SQL |
| *Provider* API Key | (empty) | Credentials for the selected provider |
| *Provider* Model | Depends on provider | Which AI model of that provider is used |
| Ollama Base URL / Model Name / Request Timeout | `http://localhost:11434` / `llama3.2` / 240 s | Connection to a local Ollama server |
| Max Tokens | 1024 | Longest reply the AI may write |
| Max Result Rows | 500 | Most rows an AI query or saved report can return |
| Database Query Timeout | 30 s | Longest time an AI query may run on the database |
| AI Request Timeout | 120 s | How long to wait for a hosted AI provider to reply |
| Database User for AI Queries | (status only) | Shows which database user runs AI queries |
| Log All Queries | Yes | Writes every question and its SQL to an audit log |
| Send Query Results to AI Provider | No | Whether chat sends result rows to the AI to write its answer |
| Show SQL Inspector on Ask AI | No | Whether Ask AI shows the generated SQL under each result |

---

## General Settings

### Enable AI Reporting

**What it does.** The master switch for the extension.

When set to **No**:

- The **Dashboard**, **Ask AI** and **Saved Reports** pages show a "disabled" notice instead of their content.
- Ask AI questions, Chat, running saved reports and exporting saved-report results are all refused.
- The **pre-built reports** (Sales, Customer, Product, Inventory) still work, because they do not use AI. Only their AI follow-up panel is turned off.

**Default:** Yes.

> **Set this at the Default Config scope.** The field shows `[store view]`, but admin pages always read the Default Config value, so a value set for a website or store view has no effect.

### LLM Provider

**What it does.** Chooses the AI service that reads your question, writes the SQL query, and (in Chat) writes the answer. Only the selected provider is used. The page shows the key and model fields for that provider only. Keys you saved for other providers are kept, so you can switch back without re-entering them.

**Default:** Groq.

| Provider | Cost | Needs an API key | Get a key at | Default model |
|---|---|---|---|---|
| **Groq** | Free tier, no credit card | Yes | console.groq.com | GPT-OSS 120B (`openai/gpt-oss-120b`) |
| **Ollama** | Free, runs on your own server | No | ollama.com (install) | `llama3.2` |
| **OpenRouter** | Free and paid models | Optional for free models | openrouter.ai | Llama 3.1 8B (free) |
| **Google Gemini** | Free tier and paid | Yes | aistudio.google.com | Gemini 2.5 Flash |
| **OpenAI** | Paid | Yes | platform.openai.com | GPT-4o |
| **Anthropic Claude** | Paid | Yes | console.anthropic.com | Claude Opus 5.5 |

**How to choose:**

- **To try the extension,** use Groq: it is free and fast.
- **For the most accurate answers on complex questions,** use a larger hosted model, such as Claude Opus 5.5 or Gemini 2.5 Pro.
- **If store data must never leave your server,** use Ollama. It needs enough free RAM for the model, and on a CPU-only server answers take tens of seconds.

### API Key (OpenAI, Gemini, Groq, OpenRouter, Claude)

**What it does.** The secret key the extension uses to call the selected provider. Magento stores it encrypted and shows it as dots after saving.

**When to change it:** when you switch provider, when the provider rotates or revokes your key, or when you see an error such as *"authentication failed (HTTP 401)"*.

### Model (OpenAI, Gemini, Groq, OpenRouter, Claude)

**What it does.** Chooses which of the provider's models answers your questions. Larger models write more accurate SQL for complex questions. Smaller models are faster and cheaper.

The dropdown starts with a built-in list. Built-in lists can go out of date when providers retire models; this applies especially to the OpenAI and OpenRouter lists. Use **Fetch Latest Models** to see what your account can use today.

Built-in Claude models: **Claude Opus 5.5** (recommended, default), **Claude Sonnet 5.5** (faster, lower cost), **Claude Haiku 4.5** (fastest, lowest cost).

Two buttons sit under the model dropdown:

| Button | What it does |
|---|---|
| **Fetch Latest Models** | Asks the provider for its current model list, using the **saved** API key, and fills the dropdown with it. For Claude, if no key is saved or the request fails, it shows the built-in list. For OpenRouter, a key is optional. For Ollama, it lists the models installed on your Ollama server. |
| **Test Connection** | Sends a tiny request with the **saved** key and model, then shows either a success message or the provider's own error message. Use it after every key or model change. |

Both buttons use the saved settings. Click **Save Config** before you use them.

### Ollama Base URL

**What it does.** The address of your Ollama server. You can enter it with or without `/api` or `/api/chat` at the end.

**Default:** `http://localhost:11434`. This works when Ollama runs on the same server as Magento.

**When to change it:** when Ollama runs on another machine, for example `http://10.0.0.5:11434`. That machine must accept connections from the Magento server.

### Ollama Model Name

**What it does.** The model Ollama should run. It must match a name from `ollama list` exactly, for example `qwen2.5-coder:3b`.

**Default:** `llama3.2`.

**Notes:**

- Download the model first with `ollama pull <model>`. If the model is not installed, the error message lists the models that are.
- The model must fit in the server's free memory. If it does not, Ollama is stopped while loading and the request fails.
- Code-focused models, such as `qwen2.5-coder`, write better SQL than general chat models of the same size.
- The extension chooses Ollama's context size itself, so the full prompt always fits.

### Ollama Request Timeout (seconds)

**What it does.** How long to wait for Ollama to answer. Local models on a CPU are slow, and the first request after a pause also loads the model into memory.

**Default:** 240 seconds. **Minimum:** 10 seconds.

**When to change it:** raise it if you see timeout errors with Ollama. Keep it below your web server's request timeout (Apache's default is 300 seconds); otherwise the browser gets a server error before Ollama finishes.

### Max Tokens

**What it does.** The longest reply the AI is allowed to write, in tokens. One token is roughly ¾ of an English word. SQL queries and chat answers usually need 200–800 tokens.

**Default:** 1024. An empty value or 0 also means 1024.

**Notes:**

- Models that think before answering need room for that thinking. The extension raises the limit automatically for them: to at least 16,000 for Claude Opus 5.5 and Sonnet 5.5, and to a higher minimum for OpenAI's reasoning models. You do not need to change this setting for them.
- For Ollama, this is the most the model may generate per answer.

**When to change it:** raise it to 2048 if long answers or long queries are cut off, or you see an error mentioning `MAX_TOKENS` or "max tokens". Lower values save cost, but the AI may stop before the query is complete.

### Max Result Rows

**What it does.** The most rows a single AI query can return. If the AI asks for more, or does not say how many, the extension adds or lowers a `LIMIT` on the query.

It applies to Ask AI, Chat, running a saved report, and exporting a saved report's results. It does not apply to the Dashboard or the pre-built reports, which have their own fixed queries.

**Default:** 500.

**When to change it:** raise it if you need to export larger result sets; very large values make the page slow. Lower it to protect a busy database.

> Chat sends at most 80 result rows to the AI to write its answer, whatever this value is, and only when [Send Query Results to AI Provider](#send-query-results-to-ai-provider) is Yes.

### Database Query Timeout (seconds)

**What it does.** The longest time an AI-written or saved-report query may run on the database. The database itself stops the query when time runs out, and the admin sees *"the query took longer than the configured timeout."* This protects the store from a heavy query slowing down checkout.

It applies to Ask AI, Chat and saved reports. It does not apply to the Dashboard or the pre-built reports.

**Default:** 30 seconds.

**When to change it:** raise it for large catalogues or order histories when legitimate reports time out. Rewording the question to cover a shorter date range often helps more than a longer timeout.

### AI Request Timeout (seconds)

**What it does.** How long to wait for a hosted AI provider (Groq, OpenRouter, Gemini, OpenAI or Claude) to answer. Ollama uses its own timeout instead (see above).

**Default:** 120 seconds. **Minimum:** 10 seconds.

**Notes:** if the provider is busy or rate-limited, for example with HTTP 429 or 5xx errors, the extension retries up to 2 times and waits up to 10 seconds between tries. A failing question can therefore take longer in total than this timeout.

**When to change it:** raise it if a model that thinks before answering, such as Claude Opus 5.5, times out on complex questions. Lower it if you prefer a quick error over a long wait.

---

## Security Settings

### Database User for AI Queries

This row is a **status message**, not a setting. It shows which database user runs AI queries:

| Message | Meaning |
|---|---|
| ✅ *Dedicated read-only database user (the "aireporting" connection) is in use.* | Recommended setup. AI queries use a separate database user that can only read data. |
| ⚠ No dedicated user configured | AI queries use Magento's own database user. The extension's checks still apply: SELECT only, read-only transaction, row limit, timeout, credential tables blocked. However, the database itself does not stop anything those checks miss. |
| ⚠ *The "aireporting" connection is configured but failed: …* | The `aireporting` connection in `app/etc/env.php` is wrong (host, user name or password). Every AI query fails until it is fixed. |
| ⚠ The dedicated user has write privileges | The dedicated user can change data. Remove every privilege except `SELECT`. |

**Why use a dedicated user.** It is the strongest protection: the database itself refuses any change and any access to tables that are not granted, whatever query the AI writes.

**How to set it up** (a developer or server administrator does this once):

1. Generate the SQL statements:

   ```bash
   bin/magento meetanshi:aireporting:readonly-grants --user=magento_ai_ro --host=localhost
   ```

   The command **only prints** the statements; it does not run anything. It prints a `CREATE USER` statement, then a `GRANT SELECT` for each table except the credential and session tables (admin users, tokens, sessions, and so on), then the `env.php` snippet.

2. Run the printed SQL as a database administrator. Choose a strong password.

3. Add the connection to `app/etc/env.php` under `db` → `connection`, next to `default`:

   ```php
   'aireporting' => [
       'host' => 'localhost',
       'dbname' => 'magento',          // same database as 'default'
       'username' => 'magento_ai_ro',
       'password' => '<password>',
       'model' => 'mysql4',
       'engine' => 'innodb',
       'initStatements' => 'SET NAMES utf8mb4',
       'active' => '1',
   ],
   ```

4. Reload the configuration page. The status should turn green.

> **After installing a new extension**, run the command again and apply the new grants. Otherwise questions about that extension's tables fail with *"the reporting database user is not allowed to read this table."*

### Log All Queries

**What it does.** Records every Ask AI and Chat question in the database table `meetanshi_aireporting_query_log`. Each record stores:

- the question and the SQL the AI generated
- the provider
- whether it succeeded, and the error message if not
- the run time and number of rows
- the admin user and the date

**Result data is not stored.**

The log feeds:

- the **Query Audit Log** drawer on the Saved Reports page (your 30 most recent entries);
- the run count, last run and duration shown for each saved report.

**Default:** Yes.

**When to change it:** keep it on for auditing who asked what. When set to No, the audit drawer is empty and saved-report run statistics stop updating.

> The log has no automatic cleanup. On a busy store, purge old rows from time to time.

### Send Query Results to AI Provider

**What it does.** Controls whether **Chat** sends query results to the AI provider so it can write a conversational answer. This also covers the AI assistant on the Dashboard and the follow-up questions on the pre-built reports, which use Chat.

- **Yes:** up to 80 result rows are sent to the provider, and the AI writes the answer. Answers read more naturally, but the rows can contain customer names, emails, addresses and order details.
- **No:** results stay on your server. The chat shows a summary built by the extension itself.

**Default:** No.

**What is always sent, whatever this setting is:**

- the question;
- database table and column names;
- store facts: timezone, base currency, attribute IDs;
- the last 3 chat questions and their SQL;
- the title, question and SQL of up to 3 similar saved reports.

**Ask AI** never sends result rows. Its table and chart are built in your browser.

**When to set it to Yes:** only if your agreement with the AI provider allows sending customer data, or if you use Ollama, which runs on your own server.

### Show SQL Inspector on Ask AI

**What it does.** Shows or hides the **Executed Magento 2 SQL Inspector** panel under each Ask AI result: the SQL the AI generated, the tables it read, **Copy SQL**, and the limits it ran under (read-only, max rows, timeout).

- **Yes:** the panel appears below the table or chart. Useful for developers who want to check or reuse the query.
- **No:** the panel is not on the page.

**Default:** No.

The setting only changes what the page shows. Queries still run the same way, are still read-only and are still logged (see [Log All Queries](#log-all-queries)). Saved reports keep their SQL; the ⋮ menu on Saved AI Reports still offers **Copy SQL**.

---

## Good to know

- **Saved reports keep their dates.** A report saved from "orders this month" keeps the exact dates of the month it was created in. Ask again to get a report for a new period.
- **The AI settings affect only Ask AI, Chat and the AI follow-up panels.** The Dashboard and pre-built reports use fixed queries and do not depend on the provider, Max Tokens, Max Result Rows or the timeouts.
- **Switching provider** keeps all saved keys and models, so you can compare providers by switching back and forth.

## Troubleshooting

| Message or symptom | Setting to check |
|---|---|
| *authentication failed (HTTP 401)* / *Check your API key* | **API Key**: re-enter it, save, then click **Test Connection** |
| *HTTP 404* / *model not found* | **Model**: the model was retired or misspelled; click **Fetch Latest Models** |
| *HTTP 429* / rate limit | Provider plan limits; wait a minute, or upgrade the provider plan |
| The AI does not answer in time | **AI Request Timeout**, or **Ollama Request Timeout** for Ollama |
| *the query took longer than the configured timeout* | **Database Query Timeout**, or ask about a shorter period |
| The answer or SQL is cut off; *MAX_TOKENS* | **Max Tokens**: raise it to 2048 |
| Results always stop at the same number of rows | **Max Result Rows** |
| *Access denied for user …* | The `aireporting` connection in `app/etc/env.php` (user name, password, host) |
| *the reporting database user is not allowed to read this table* | Run the `readonly-grants` command again and apply the new grants |
| Pages show *AI Reporting is disabled* | **Enable AI Reporting**, set at Default Config |

## Configuration paths (for `bin/magento config:set`)

| Path | Default |
|---|---|
| `meetanshi_aireporting/general/enabled` | `1` |
| `meetanshi_aireporting/general/llm_provider` | `groq` (`groq`, `ollama`, `openrouter`, `gemini`, `openai`, `claude`) |
| `meetanshi_aireporting/general/openai_api_key` | (encrypted) |
| `meetanshi_aireporting/general/openai_model` | `gpt-4o` |
| `meetanshi_aireporting/general/gemini_api_key` | (encrypted) |
| `meetanshi_aireporting/general/gemini_model` | `gemini-2.5-flash` |
| `meetanshi_aireporting/general/groq_api_key` | (encrypted) |
| `meetanshi_aireporting/general/groq_model` | `openai/gpt-oss-120b` |
| `meetanshi_aireporting/general/ollama_base_url` | `http://localhost:11434` |
| `meetanshi_aireporting/general/ollama_model` | `llama3.2` |
| `meetanshi_aireporting/general/ollama_timeout` | `240` |
| `meetanshi_aireporting/general/openrouter_api_key` | (encrypted) |
| `meetanshi_aireporting/general/openrouter_model` | `meta-llama/llama-3.1-8b-instruct:free` |
| `meetanshi_aireporting/general/claude_api_key` | (encrypted) |
| `meetanshi_aireporting/general/claude_model` | `claude-opus-5-5` |
| `meetanshi_aireporting/general/max_tokens` | `1024` |
| `meetanshi_aireporting/general/max_rows` | `500` |
| `meetanshi_aireporting/general/query_timeout` | `30` |
| `meetanshi_aireporting/general/llm_timeout` | `120` |
| `meetanshi_aireporting/security/log_queries` | `1` |
| `meetanshi_aireporting/security/share_results_with_llm` | `0` |
| `meetanshi_aireporting/security/show_sql_inspector` | `0` |

Enter API keys on the admin configuration page, which encrypts them before saving. Do not write them into `core_config_data` directly: the extension expects them to be encrypted.
