# mcp-opencart

**English** | [Русский](README.md)

A single-file PHP MCP server for [OpenCart](https://www.opencart.com/). It connects Claude and other MCP clients to an online shop: products, categories, manufacturers, attributes, information pages, orders, customers, reviews and a sales report.

Supports **OpenCart 3.0.x** (including ocStore 3), **4.0.x** and **4.1.x**. The version and the schema differences are detected automatically.

## How it works

OpenCart has no standalone admin handlers that could be called from outside, and booting the whole framework for one request is heavy (and different in 3.x and 4.x). So `mcp-opencart.php` reads the database settings from `config.php` and writes **the same rows the OpenCart admin models write**:

- descriptions for every shop language (`*_description`);
- store assignments (`*_to_store`);
- the category tree (`category_path`), rebuilt on create and move;
- SEO URLs in the format of the installed version: `query = product_id=42` in 3.x, `key` / `value` in 4.x, full category paths (`laptops/apple`) in 4.x;
- specials: `product_special` in 3.x and 4.0, `product_discount` with `special = 1` in 4.1;
- SKU, UPC, EAN and other codes: `product` columns up to 4.0, the `product_code` table in 4.1.

Texts are stored HTML-escaped, exactly like the admin stores them. The OpenCart cache is cleared after every write. No OpenCart file is modified.

Extra columns added by forks are picked up automatically, e.g. `meta_h1` in ocStore.

## Requirements

- OpenCart 3.0.x, 4.0.x or 4.1.x.
- PHP 7.4+ with `pdo_mysql`, `curl`, `mbstring`.
- MySQL or MariaDB.

## How it was tested

- **Platforms:** fresh installs of OpenCart 3.0.4.1, 4.0.2.3, 4.1.0.4 and ocStore 3.0.3.7, each with two languages.
- **Web servers:** the PHP built-in server, Apache with mod_php, Apache with PHP-FPM, nginx with PHP-FPM.
- **Client:** the official MCP Python SDK (Streamable HTTP): `initialize`, `tools/list`, `ping`, `tools/call`, tool errors.
- **Storefront and admin:** created products, categories, manufacturers and pages open on the storefront and in the admin forms without PHP errors. SEO URLs, specials, attributes and ocStore `meta_h1` work.
- **Orders:** placed through the real cart as a guest (cash on delivery, flat shipping) in all four shops, then read, status changed, report built. Reviews were posted on the storefront and published through MCP.
- **Load:** 20,000 products and 10,000 orders. Search, filters, the report and writes stay under 200 ms.
- **PHP 7.4:** the code was checked for syntax and functions newer than 7.4. It was not run on 7.4 (the main testing used PHP 8.3).

## Installation

1. Put `mcp-opencart.php` into the shop root, next to `config.php`.
2. Open the file and set a key of at least 16 characters:

   ```php
   const MCP_KEY = 'a-long-random-key';
   ```

   With an empty key the server answers `503` and does nothing.
3. Add the MCP server to your client.

**Claude (claude.ai, apps)**, custom connector:

- URL: `https://example.com/mcp-opencart.php`
- header: `Authorization: Bearer <key>`

**Claude Code:**

```bash
claude mcp add --transport http opencart https://example.com/mcp-opencart.php \
  --header "Authorization: Bearer <key>"
```

Check from the server:

```bash
curl -s -X POST https://example.com/mcp-opencart.php \
  -H 'Authorization: Bearer <key>' \
  -H 'Content-Type: application/json' \
  -d '{"jsonrpc":"2.0","id":1,"method":"ping"}'
# {"jsonrpc":"2.0","id":1,"result":{}}
```

## Authentication

| Method | Example |
|---|---|
| Bearer header | `Authorization: Bearer <key>` |
| Custom header | `X-MCP-Key: <key>` |
| URL parameter | `?key=<key>` |

Prefer a header: URL parameters end up in web server logs.

**Apache with PHP-FPM** (and other CGI setups) does not pass the `Authorization` header to PHP by default, so the server answers 401. Two ways out:

- send the key in `X-MCP-Key`, which always gets through;
- or add to `.htaccess` in the shop root:

  ```apache
  <Files "mcp-opencart.php">
      CGIPassAuth On
  </Files>
  ```

  On Apache older than 2.4.13 use `SetEnvIf Authorization "(.*)" HTTP_AUTHORIZATION=$1` instead.

With Apache + mod_php and with nginx + PHP-FPM, `Authorization: Bearer` works out of the box.

The key gives full administrator-level access to the shop. It is compared with `hash_equals()`.

## Settings

At the top of the file:

```php
const MCP_KEY       = '';              // access key, at least 16 characters
const MCP_READONLY  = false;           // true = reading tools only
const MCP_ALLOW_SQL = true;            // sql_select tool
const MCP_IMAGE_DIR = 'catalog/mcp/';  // where downloaded images go, relative to image/
```

## Tools (32)

**Shop**

| Tool | What it does |
|---|---|
| `shop_info` | Version, URL, languages, currencies, stores, counters and every id other tools need: order and stock statuses, tax, weight and length classes, customer groups |
| `cache_clear` | Clears the OpenCart cache (`system/storage/cache`) |

**Products**

| Tool | What it does |
|---|---|
| `product_list` | Search by name, model, SKU or id; filters by category (with subcategories), manufacturer, status, price, stock, active special; sorting |
| `product_get` | Full product: texts in every language, codes, price, specials, discounts, stock, images, categories, attributes, options, SEO |
| `product_add` | Create. `name` and `model` are required |
| `product_edit` | Edit: only the passed fields change |
| `product_delete` | Delete with all links. Orders keep their own copy |

What create and edit accept:

- `image`, `images`: URLs are downloaded into `image/catalog/mcp/`, paths like `catalog/demo/x.jpg` are used as they are;
- `categories`: a list of ids;
- `manufacturer`: a manufacturer name, found or created;
- `attributes`: `{"Memory": "256 GB", "Color": {"en-gb": "Titanium", "uk-ua": "Титан"}}`; unknown attributes are created by name in `attribute_group`;
- `special_price`, `special_start`, `special_end`: the sale price for the default customer group, `0` removes it;
- `sku`, `upc`, `ean`, `jan`, `isbn`, `mpn`, `price`, `quantity`, `status`, weight, dimensions, `stock_status_id`, `tax_class_id` and other form fields;
- `seo_keyword` or `seo_keywords` per language. When omitted on create, the keyword is transliterated from the name;
- `stores`: the store list. When it changes, the current SEO keywords move to the new stores.

**Categories, manufacturers, attributes, information pages**

| Tool | What it does |
|---|---|
| `category_list` | The tree with "Parent > Child" paths and product counts |
| `category_get` | One category with texts in every language and SEO |
| `category_add` / `category_edit` | Create and edit; changing `parent_id` moves the whole branch, `category_path` and SEO paths are rebuilt |
| `category_delete` | Delete with all subcategories. Products stay |
| `manufacturer_list` / `manufacturer_add` / `manufacturer_edit` / `manufacturer_delete` | Manufacturers. In ocStore also description, `meta_title`, `meta_h1`. A manufacturer in use is deleted only with `force: true` |
| `attribute_list` | Attributes by group |
| `information_list` / `information_get` / `information_add` / `information_edit` / `information_delete` | Information pages: delivery, payment, about us |

**Orders, customers, reviews, reports**

| Tool | What it does |
|---|---|
| `order_list` | Orders filtered by status, customer, dates. Unfinished checkouts (status 0) are hidden unless `include_missing` |
| `order_get` | Full order: customer, addresses, payment and shipping methods, products with options, totals, status history |
| `order_status_set` | New status and a history record |
| `customer_list` | Customers with order count and amount spent |
| `review_list` / `review_status` / `review_delete` | Review moderation; the product rating is recalculated |
| `sales_report` | Orders, items and revenue by day, week, month or year plus the top 10 products. By default counts the "processing" and "complete" statuses from the shop settings |
| `sql_select` | One read-only query (`SELECT`, `SHOW`, `DESCRIBE`, `EXPLAIN`) in a read-only transaction. Write tables as `{product}`, the prefix is added. Up to 500 rows |
| `image_upload` | Download an image by URL and get the path for `image` fields |

## Languages

A shop can have several languages, so text fields work in two modes:

- **Create.** Top-level fields (`name`, `description`, `meta_title`…) go to every language. The `descriptions` object gives a language its own text:

  ```json
  {
    "name": "iPhone 15 Pro",
    "model": "IP15P",
    "descriptions": { "uk-ua": { "name": "iPhone 15 Про", "description": "<p>Опис</p>" } }
  }
  ```

- **Edit.** Top-level fields change the language from `language` (the shop language by default); `"language": "all"` changes every language.

When `meta_title` is empty it is taken from the name, as the admin does.

## Limitations

- `order_status_set` changes the status and writes history, but **sends no e-mail** to the customer and does not run OpenCart events: stock subtraction, reward points, module webhooks. When the customer must be notified, change the status in the admin panel.
- Product options, 4.x variants, quantity discounts, related products and downloads are read-only here. Use the admin panel for them.
- Orders cannot be created: only the storefront checkout does that.

## Write safety

OpenCart 3 tables are MyISAM, which has no transactions. So every check runs before the first write: categories, languages, stores, images (downloaded in advance). A bad request does not leave half a product in the database. On OpenCart 4 (InnoDB) every write also runs in a transaction.

## Security

- The key is compared with `hash_equals()`, no sessions or cookies. A key shorter than 16 characters disables the server.
- Images are downloaded over http(s) only, up to 20 MB. Local and private network addresses are blocked (SSRF protection). The type is checked by content: JPEG, PNG, GIF and WebP are accepted.
- Image paths cannot leave the `image/` folder.
- All database queries are prepared statements.
- `sql_select` accepts a single read-only statement in a read-only transaction, without `INTO OUTFILE`, `LOAD_FILE`, `SLEEP` or locks. It can be turned off (`MCP_ALLOW_SQL = false`).
- `MCP_READONLY = true` removes every write tool from the list.

If an anti-bot or a WAF with a JS challenge sits in front of the site, exclude `/mcp-opencart.php`. Otherwise the client gets the challenge page instead of JSON.

## Protocol

MCP Streamable HTTP: JSON-RPC 2.0 over `POST`, `application/json` responses, no SSE, no sessions. Supported: `initialize`, `ping`, `tools/list`, `tools/call`. Protocol versions: `2025-11-25`, `2025-06-18`, `2025-03-26`, `2024-11-05`.

## Troubleshooting

| Symptom | Cause |
|---|---|
| 503 "Server disabled" | `MCP_KEY` is empty or shorter than 16 characters |
| 401 | Wrong key, or the `Authorization` header does not reach PHP (Apache + PHP-FPM): use `X-MCP-Key` or `CGIPassAuth On`, see "Authentication" |
| 405 | The URL was opened in a browser (GET). The server accepts POST only |
| 500 "config.php not found" | The file is not in the shop root |
| HTML instead of JSON | An anti-bot or WAF intercepted the request |
| Images are not downloaded | No write access to `image/catalog/`, no curl extension, or the URL points to an internal address |

## License

[MIT](LICENSE)
