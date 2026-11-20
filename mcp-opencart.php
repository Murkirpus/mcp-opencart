<?php
/*
=====================================================
 MCP server for OpenCart (single file)
-----------------------------------------------------
 Supports:  OpenCart 3.0.x (and ocStore 3), 4.0.x, 4.1.x; PHP 7.4+
 Endpoint:  https://your-shop/mcp-opencart.php
 Auth:      header "Authorization: Bearer MCP_KEY" or "X-MCP-Key: MCP_KEY"
            (Apache + PHP-FPM drops Authorization: use X-MCP-Key or "CGIPassAuth On")
            (also accepted: ?key=MCP_KEY, but a header keeps the key out of logs)
 Transport: MCP Streamable HTTP (JSON-RPC 2.0 over POST)
-----------------------------------------------------
 Put the file into the OpenCart root, next to config.php.
 It reads the database settings from config.php and works
 with the tables directly, writing the same rows the
 OpenCart admin models write (descriptions for every
 language, stores, category paths, SEO URLs, specials…).
 The schema differences between 3.x and 4.x are detected
 automatically. No OpenCart file is modified.
 Input is validated before the first write, because
 OpenCart 3 tables are MyISAM (no transactions); on
 InnoDB (OpenCart 4) every write tool is a transaction.
=====================================================
*/

// ---- Settings -------------------------------------------------------------

const MCP_KEY       = '';              // access key, at least 16 characters. Empty = server disabled.
const MCP_READONLY  = false;           // true = only reading tools are available
const MCP_ALLOW_SQL = true;            // sql_select tool (read-only SELECT queries)
const MCP_IMAGE_DIR = 'catalog/mcp/';  // where downloaded images go, relative to image/

// ---------------------------------------------------------------------------

const MCP_NAME      = 'opencart-mcp';
const MCP_VERSION   = '1.0.0';
const MCP_PROTOCOLS = ['2025-11-25', '2025-06-18', '2025-03-26', '2024-11-05'];

if (!function_exists('array_is_list')) {   // PHP < 8.1 (OpenCart 3 shops often run 7.4)
    function array_is_list(array $a): bool
    {
        return $a === [] || array_keys($a) === range(0, count($a) - 1);
    }
}

final class Mcp
{
    public static $id = null;
    public static array $args = [];

    // ---- JSON-RPC plumbing -------------------------------------------------

    public static function send(int $status, ?array $payload): void
    {
        while (ob_get_level() > 0) ob_end_clean();

        if (!headers_sent()) {
            http_response_code($status);
            header('Cache-Control: no-store');
            header('X-Content-Type-Options: nosniff');
            if ($payload !== null) header('Content-Type: application/json; charset=utf-8');
        }

        if ($payload !== null) {
            echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);
        }
    }

    public static function result($result): void
    {
        self::send(200, ['jsonrpc' => '2.0', 'id' => self::$id, 'result' => $result]);
    }

    public static function error(int $code, string $message, int $status = 200): void
    {
        self::send($status, ['jsonrpc' => '2.0', 'id' => self::$id, 'error' => ['code' => $code, 'message' => $message]]);
    }

    public static function toolResult($data, bool $isError = false): void
    {
        $text = is_string($data) ? $data : json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);
        self::result(['content' => [['type' => 'text', 'text' => $text]], 'isError' => $isError]);
    }

    // ---- Arguments ---------------------------------------------------------

    public static function arg(string $name, $default = null)
    {
        return array_key_exists($name, self::$args) && self::$args[$name] !== null ? self::$args[$name] : $default;
    }

    public static function has(string $name): bool
    {
        return array_key_exists($name, self::$args) && self::$args[$name] !== null;
    }

    public static function need(string $name)
    {
        if (!self::has($name) || self::$args[$name] === '') throw new McpFail("Argument \"$name\" is required.");
        return self::$args[$name];
    }

    public static function flag($value): int
    {
        if (is_string($value)) return in_array(strtolower(trim($value)), ['', '0', 'false', 'no', 'off'], true) ? 0 : 1;
        return $value ? 1 : 0;
    }

    public static function ids($value): array
    {
        if (is_string($value)) $value = preg_split('/[\s,]+/', $value, -1, PREG_SPLIT_NO_EMPTY);
        if (!is_array($value)) $value = [$value];
        $ids = [];
        foreach ($value as $v) if (is_numeric($v) && (int)$v >= 0) $ids[(int)$v] = (int)$v;
        return array_values($ids);
    }

    public static function limits(int $def = 20, int $max = 200): array
    {
        return [max(1, min($max, (int)self::arg('limit', $def))), max(0, (int)self::arg('offset', 0))];
    }

    public static function text(string $html, int $max = 300): string
    {
        $text = html_entity_decode(strip_tags(preg_replace('#<(br|/p|/div|/li|/h\d)\b[^>]*>#i', "\n", $html)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = trim(preg_replace('/\s+/u', ' ', $text));
        return ($max > 0 && mb_strlen($text) > $max) ? mb_substr($text, 0, $max) . '…' : $text;
    }

    /** OpenCart stores descriptions HTML-escaped (the admin runs htmlspecialchars on all input). */
    public static function enc($value): string
    {
        return htmlspecialchars((string)$value, ENT_COMPAT, 'UTF-8');
    }

    public static function dec($value): string
    {
        return html_entity_decode((string)$value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    public static function slug(string $text): string
    {
        static $map = [
            'а'=>'a','б'=>'b','в'=>'v','г'=>'g','ґ'=>'g','д'=>'d','е'=>'e','ё'=>'e','є'=>'ye','ж'=>'zh','з'=>'z','и'=>'i','і'=>'i','ї'=>'yi','й'=>'y',
            'к'=>'k','л'=>'l','м'=>'m','н'=>'n','о'=>'o','п'=>'p','р'=>'r','с'=>'s','т'=>'t','у'=>'u','ф'=>'f','х'=>'h','ц'=>'ts','ч'=>'ch',
            'ш'=>'sh','щ'=>'sch','ъ'=>'','ы'=>'y','ь'=>'','э'=>'e','ю'=>'yu','я'=>'ya','ä'=>'ae','ö'=>'oe','ü'=>'ue','ß'=>'ss',
        ];
        $text = strtr(mb_strtolower(self::dec($text), 'UTF-8'), $map);
        if (function_exists('iconv')) $text = (string)@iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);
        $text = trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($text)), '-');
        return substr($text, 0, 60) ?: 'item';
    }
}

final class McpFail extends \RuntimeException {}

// ===========================================================================
//  Database layer
// ===========================================================================

final class OcDb
{
    public static \PDO $pdo;
    private static array $cols = [];
    private static ?array $tables = null;

    public static function connect(): void
    {
        $dsn = 'mysql:host=' . DB_HOSTNAME . ';dbname=' . DB_DATABASE . ';charset=utf8mb4' . (defined('DB_PORT') && DB_PORT ? ';port=' . (int)DB_PORT : '');
        self::$pdo = new \PDO($dsn, DB_USERNAME, DB_PASSWORD, [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            \PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        // the same session mode OpenCart sets in its own DB driver
        self::$pdo->exec("SET SESSION sql_mode = 'NO_ZERO_IN_DATE,NO_ENGINE_SUBSTITUTION'");
    }

    public static function t(string $table): string
    {
        return '`' . DB_PREFIX . $table . '`';
    }

    /** Replaces {table} with `prefix_table`. */
    public static function sql(string $sql): string
    {
        return preg_replace_callback('/\{([a-z0-9_]+)\}/', static fn($m) => self::t($m[1]), $sql);
    }

    public static function q(string $sql, array $p = []): \PDOStatement
    {
        $st = self::$pdo->prepare(self::sql($sql));
        $st->execute($p);
        return $st;
    }

    public static function rows(string $sql, array $p = []): array { return self::q($sql, $p)->fetchAll(); }
    public static function row(string $sql, array $p = []): array { return self::q($sql, $p)->fetch() ?: []; }
    public static function val(string $sql, array $p = []) { $v = self::q($sql, $p)->fetchColumn(); return $v === false ? null : $v; }
    public static function col(string $sql, array $p = []): array { return self::q($sql, $p)->fetchAll(\PDO::FETCH_COLUMN); }

    public static function hasTable(string $table): bool
    {
        if (self::$tables === null) self::$tables = array_flip(self::$pdo->query('SHOW TABLES')->fetchAll(\PDO::FETCH_COLUMN));
        return isset(self::$tables[DB_PREFIX . $table]);
    }

    public static function cols(string $table): array
    {
        if (!isset(self::$cols[$table])) {
            self::$cols[$table] = self::hasTable($table) ? array_flip(self::$pdo->query('SHOW COLUMNS FROM ' . self::t($table))->fetchAll(\PDO::FETCH_COLUMN)) : [];
        }
        return self::$cols[$table];
    }

    public static function hasCol(string $table, string $col): bool
    {
        return isset(self::cols($table)[$col]);
    }

    /** Keeps only the columns the table really has (forks like ocStore add their own). */
    private static function only(string $table, array $data): array
    {
        return array_intersect_key($data, self::cols($table));
    }

    public static function insert(string $table, array $data): int
    {
        $data = self::only($table, $data);
        if (!$data) throw new McpFail("Nothing to insert into $table.");
        $set = implode(', ', array_map(static fn($c) => "`$c` = ?", array_keys($data)));
        self::q('INSERT INTO ' . self::t($table) . " SET $set", array_values($data));
        return (int)self::$pdo->lastInsertId();
    }

    public static function update(string $table, array $data, array $where): int
    {
        $data = self::only($table, $data);
        if (!$data) return 0;
        $set = implode(', ', array_map(static fn($c) => "`$c` = ?", array_keys($data)));
        $cond = implode(' AND ', array_map(static fn($c) => "`$c` = ?", array_keys($where)));
        return self::q('UPDATE ' . self::t($table) . " SET $set WHERE $cond", array_merge(array_values($data), array_values($where)))->rowCount();
    }

    public static function delete(string $table, array $where): int
    {
        if (!self::hasTable($table)) return 0;
        $where = self::only($table, $where);
        if (!$where) return 0;
        $cond = implode(' AND ', array_map(static fn($c) => "`$c` = ?", array_keys($where)));
        return self::q('DELETE FROM ' . self::t($table) . " WHERE $cond", array_values($where))->rowCount();
    }

    public static function in(array $ids): string
    {
        return $ids ? implode(',', array_map('intval', $ids)) : '0';
    }
}

// ===========================================================================
//  OpenCart knowledge: versions, languages, settings, SEO, categories, images
// ===========================================================================

final class Oc
{
    public static bool $v4 = false;            // seo_url key/value, category paths in SEO keywords
    public static bool $specialTable = true;   // oc_product_special (3.x, 4.0) or specials inside oc_product_discount (4.1+)
    public static bool $codeTable = false;     // SKU, UPC… in oc_product_code (4.1+) instead of oc_product columns
    public static string $version = '';
    private static ?array $settings = null;
    private static ?array $languages = null;

    public static function init(): void
    {
        self::$v4 = OcDb::hasCol('product', 'master_id') || OcDb::hasCol('seo_url', 'key');
        self::$specialTable = OcDb::hasTable('product_special');
        self::$codeTable = !OcDb::hasCol('product', 'sku') && OcDb::hasTable('product_code');

        foreach (['index.php', 'admin/index.php'] as $f) {
            if (is_file(__DIR__ . '/' . $f) && preg_match("/define\(\s*'VERSION'\s*,\s*'([^']+)'/", (string)file_get_contents(__DIR__ . '/' . $f), $m)) {
                self::$version = $m[1];
                break;
            }
        }
        if (self::$version === '') self::$version = self::$v4 ? '4.x' : '3.x';
    }

    public static function setting(string $key, $default = null)
    {
        if (self::$settings === null) {
            self::$settings = [];
            foreach (OcDb::rows("SELECT `key`, `value`, `serialized` FROM {setting} WHERE `store_id` = 0") as $r) {
                self::$settings[$r['key']] = $r['serialized'] ? (json_decode($r['value'], true) ?? @unserialize($r['value'])) : $r['value'];
            }
        }
        return self::$settings[$key] ?? $default;
    }

    // ---- Languages ---------------------------------------------------------

    public static function languages(): array
    {
        if (self::$languages === null) {
            self::$languages = [];
            foreach (OcDb::rows("SELECT `language_id`, `name`, `code`, `status` FROM {language} ORDER BY `sort_order`, `language_id`") as $r) {
                self::$languages[(int)$r['language_id']] = $r;
            }
        }
        return self::$languages;
    }

    public static function defaultLanguageId(): int
    {
        $code = self::setting(self::$v4 ? 'config_language_catalog' : 'config_language') ?: self::setting('config_language');
        foreach (self::languages() as $id => $l) if ($l['code'] === $code) return $id;
        return (int)array_key_first(self::languages());
    }

    /** Accepts a language code ("uk-ua"), an id, or null for the shop default. */
    public static function languageId($lang): int
    {
        if ($lang === null || $lang === '') return self::defaultLanguageId();
        foreach (self::languages() as $id => $l) {
            if ((string)$id === (string)$lang || strcasecmp($l['code'], (string)$lang) === 0) return $id;
        }
        throw new McpFail('Unknown language "' . $lang . '". Available: ' . implode(', ', array_column(self::languages(), 'code')));
    }

    public static function langCode(int $id): string
    {
        return self::languages()[$id]['code'] ?? (string)$id;
    }

    public static function customerGroupId(): int
    {
        return (int)self::setting('config_customer_group_id', 1);
    }

    public static function storeIds(): array
    {
        return array_merge([0], array_map('intval', OcDb::col("SELECT `store_id` FROM {store} ORDER BY `store_id`")));
    }

    public static function catalogUrl(): string
    {
        $url = defined('HTTPS_SERVER') ? HTTPS_SERVER : (defined('HTTP_SERVER') ? HTTP_SERVER : '/');
        return rtrim($url, '/') . '/';
    }

    // ---- Multilingual descriptions -----------------------------------------

    /**
     * Writes rows of a *_description table.
     * $fields   - allowed field names
     * $required - field that must not be empty on insert (name / title)
     * On create: top-level fields go to every language that has no own entry in "descriptions".
     * On edit:   top-level fields go to the language from "language" (default: shop language, "all" = every language).
     */
    public static function saveDescriptions(string $table, string $idCol, int $id, array $fields, string $required, bool $create, array $defaults = []): array
    {
        $flat = [];
        foreach ($fields as $f) if (Mcp::has($f)) $flat[$f] = (string)Mcp::arg($f);

        $per = [];
        $given = Mcp::arg('descriptions', []);
        if (!is_array($given)) throw new McpFail('"descriptions" must be an object: {"language code": {field: value}}.');
        foreach ($given as $lang => $values) {
            if (!is_array($values)) continue;
            $lid = self::languageId($lang);
            foreach ($fields as $f) if (array_key_exists($f, $values) && $values[$f] !== null) $per[$lid][$f] = (string)$values[$f];
        }

        $targets = [];
        if ($create) {
            foreach (self::languages() as $lid => $l) $targets[$lid] = ($per[$lid] ?? []) + $flat;
        } else {
            if ($flat) {
                $lang = Mcp::arg('language');
                $list = ($lang === 'all') ? array_keys(self::languages()) : [self::languageId($lang)];
                foreach ($list as $lid) $targets[$lid] = $flat;
            }
            foreach ($per as $lid => $values) $targets[$lid] = $values + ($targets[$lid] ?? []);
        }

        $changed = [];
        $fallback = null;

        foreach ($targets as $lid => $values) {
            $exists = OcDb::row("SELECT * FROM " . OcDb::t($table) . " WHERE `$idCol` = ? AND `language_id` = ?", [$id, $lid]);

            if ($exists) {
                if (!$values) continue;
                OcDb::update($table, array_map([Mcp::class, 'enc'], $values), [$idCol => $id, 'language_id' => $lid]);
            } else {
                foreach ($defaults as $k => $v) if (($values[$k] ?? '') === '') $values[$k] = $v;
                if ($required !== '' && ($values[$required] ?? '') === '') {
                    $fallback ??= OcDb::row("SELECT * FROM " . OcDb::t($table) . " WHERE `$idCol` = ? ORDER BY `language_id` = ? DESC LIMIT 1", [$id, self::defaultLanguageId()]);
                    if ($fallback) $values += array_map([Mcp::class, 'dec'], array_intersect_key($fallback, array_flip($fields)));
                }
                if ($required !== '' && ($values[$required] ?? '') === '') throw new McpFail("\"$required\" is required for language " . self::langCode($lid) . '.');
                if ($required !== '' && isset(OcDb::cols($table)['meta_title']) && ($values['meta_title'] ?? '') === '') $values['meta_title'] = $values[$required];

                $row = [$idCol => $id, 'language_id' => $lid];
                foreach (array_keys(OcDb::cols($table)) as $c) if (!isset($row[$c]) && $c !== $idCol) $row[$c] = '';
                OcDb::insert($table, array_map([Mcp::class, 'enc'], $values) + $row);
            }
            $changed[] = self::langCode($lid);
        }

        return $changed;
    }

    /** Language-related arguments are checked before anything is written. */
    public static function validateLanguageArgs(): void
    {
        foreach (['descriptions', 'seo_keywords'] as $f) {
            if (!Mcp::has($f)) continue;
            if (!is_array(Mcp::arg($f))) throw new McpFail("\"$f\" must be an object keyed by language code.");
            foreach (array_keys(Mcp::arg($f)) as $lang) self::languageId($lang);
        }
        if (Mcp::has('language') && Mcp::arg('language') !== 'all') self::languageId(Mcp::arg('language'));
    }

    public static function descriptions(string $table, string $idCol, int $id, bool $full = true): array
    {
        $out = [];
        foreach (OcDb::rows("SELECT * FROM " . OcDb::t($table) . " WHERE `$idCol` = ?", [$id]) as $r) {
            $code = self::langCode((int)$r['language_id']);
            unset($r[$idCol], $r['language_id']);
            foreach ($r as $k => $v) {
                $r[$k] = Mcp::dec($v);
                if (!$full && $k === 'description') $r[$k] = Mcp::text($r[$k], 300);
            }
            $out[$code] = $r;
        }
        return $out;
    }

    // ---- SEO URLs ----------------------------------------------------------

    /** Returns [store_id][language code] => keyword. */
    public static function seoGet(string $key, string $value): array
    {
        $rows = self::$v4
            ? OcDb::rows("SELECT `store_id`, `language_id`, `keyword` FROM {seo_url} WHERE `key` = ? AND `value` = ?", [$key, $value])
            : OcDb::rows("SELECT `store_id`, `language_id`, `keyword` FROM {seo_url} WHERE `query` = ?", ["$key=$value"]);
        $out = [];
        foreach ($rows as $r) $out[(int)$r['store_id']][self::langCode((int)$r['language_id'])] = $r['keyword'];
        return self::byStore($out);
    }

    /** [store_id => [lang => keyword]] -> {"en-gb": "...", "store_2": {...}}: the default store is shown flat. */
    public static function byStore(array $seo): array
    {
        $flat = $seo[0] ?? [];
        foreach ($seo as $store => $byLang) if ($store !== 0) $flat['store_' . $store] = $byLang;
        return $flat;
    }

    public static function seoDelete(string $key, string $value): void
    {
        self::$v4
            ? OcDb::q("DELETE FROM {seo_url} WHERE `key` = ? AND `value` = ?", [$key, $value])
            : OcDb::q("DELETE FROM {seo_url} WHERE `query` = ?", ["$key=$value"]);
    }

    private static function keywordTaken(string $keyword, int $store, int $lid, string $key, string $value): bool
    {
        if (self::$v4) {
            return (bool)OcDb::val("SELECT 1 FROM {seo_url} WHERE `keyword` = ? AND `store_id` = ? AND `language_id` = ? AND NOT (`key` = ? AND `value` = ?) LIMIT 1", [$keyword, $store, $lid, $key, $value]);
        }
        // OpenCart 3 resolves keywords without looking at the language
        return (bool)OcDb::val("SELECT 1 FROM {seo_url} WHERE `keyword` = ? AND `store_id` = ? AND NOT (`query` = ? AND `language_id` = ?) LIMIT 1", [$keyword, $store, "$key=$value", $lid]);
    }

    public static function uniqueKeyword(string $keyword, int $store, int $lid, string $key, string $value): string
    {
        $base = $keyword;
        if (self::keywordTaken($keyword, $store, $lid, $key, $value)) {
            $suffix = explode('-', self::langCode($lid))[0];
            $keyword = "$base-$suffix";
            for ($n = 2; self::keywordTaken($keyword, $store, $lid, $key, $value); $n++) $keyword = "$base-$n";
        }
        return $keyword;
    }

    public static function seoSet(string $key, string $value, string $keyword, int $store, int $lid, int $sort = 0): string
    {
        $keyword = self::uniqueKeyword($keyword, $store, $lid, $key, $value);
        if (self::$v4) {
            OcDb::q("DELETE FROM {seo_url} WHERE `key` = ? AND `value` = ? AND `store_id` = ? AND `language_id` = ?", [$key, $value, $store, $lid]);
            OcDb::insert('seo_url', ['store_id' => $store, 'language_id' => $lid, 'key' => $key, 'value' => $value, 'keyword' => $keyword, 'sort_order' => $sort]);
        } else {
            OcDb::q("DELETE FROM {seo_url} WHERE `query` = ? AND `store_id` = ? AND `language_id` = ?", ["$key=$value", $store, $lid]);
            OcDb::insert('seo_url', ['store_id' => $store, 'language_id' => $lid, 'query' => "$key=$value", 'keyword' => $keyword]);
        }
        return $keyword;
    }

    /**
     * Keywords wanted per language id from "seo_keyword" / "seo_keywords", or generated from names.
     * $names: [language_id => name] used when nothing was passed and $generate is true.
     */
    public static function wantedKeywords(array $names, bool $generate, string $key = '', string $value = ''): array
    {
        $out = [];
        $byLang = Mcp::arg('seo_keywords', []);
        if (is_array($byLang)) foreach ($byLang as $lang => $kw) if (trim((string)$kw) !== '') $out[self::languageId($lang)] = self::cleanKeyword((string)$kw);

        if (Mcp::has('seo_keyword') && trim((string)Mcp::arg('seo_keyword')) !== '') {
            $kw = self::cleanKeyword((string)Mcp::arg('seo_keyword'));
            $lang = Mcp::arg('language');
            $list = ($generate || $lang === 'all') ? array_keys(self::languages()) : [self::languageId($lang)];
            foreach ($list as $lid) $out[$lid] ??= $kw;
        }

        // stores changed on edit: keep the current keywords and write them for the new store list
        if (!$generate && $key !== '' && Mcp::has('stores')) {
            $rows = self::$v4
                ? OcDb::rows("SELECT `language_id`, `keyword` FROM {seo_url} WHERE `key` = ? AND `value` = ? ORDER BY `store_id`", [$key, $value])
                : OcDb::rows("SELECT `language_id`, `keyword` FROM {seo_url} WHERE `query` = ? ORDER BY `store_id`", ["$key=$value"]);
            foreach ($rows as $r) $out[(int)$r['language_id']] ??= $r['keyword'];
        }

        if ($generate) {
            $default = self::defaultLanguageId();
            foreach (self::languages() as $lid => $l) {
                if (isset($out[$lid])) continue;
                $name = $names[$lid] ?? $names[$default] ?? '';
                if ($name !== '') $out[$lid] = Mcp::slug($name);
            }
        }
        return $out;
    }

    public static function cleanKeyword(string $kw): string
    {
        $kw = trim(mb_strtolower($kw, 'UTF-8'), " /\t\n");
        return preg_match('/^[a-z0-9\-_.\/]+$/', $kw) ? $kw : Mcp::slug($kw);
    }

    // ---- Categories --------------------------------------------------------

    public static function categoryPath(int $categoryId): string
    {
        return implode('_', OcDb::col("SELECT `path_id` FROM {category_path} WHERE `category_id` = ? ORDER BY `level`", [$categoryId]));
    }

    /** Rebuilds the closure table category_path for the whole tree (OpenCart "repair"). */
    public static function rebuildPaths(): void
    {
        $parent = [];
        foreach (OcDb::rows("SELECT `category_id`, `parent_id` FROM {category}") as $r) $parent[(int)$r['category_id']] = (int)$r['parent_id'];

        OcDb::q("DELETE FROM {category_path}");
        $st = OcDb::$pdo->prepare(OcDb::sql("INSERT INTO {category_path} (`category_id`, `path_id`, `level`) VALUES (?, ?, ?)"));

        foreach ($parent as $id => $p) {
            $chain = [$id];
            $seen = [$id => true];
            while ($p && isset($parent[$p]) && !isset($seen[$p])) {
                array_unshift($chain, $p);
                $seen[$p] = true;
                $p = $parent[$p];
            }
            foreach ($chain as $level => $pathId) $st->execute([$id, $pathId, $level]);
        }
    }

    public static function subtree(int $categoryId): array
    {
        return array_map('intval', OcDb::col("SELECT cp.`category_id` FROM {category_path} cp LEFT JOIN {category_path} self ON (self.`category_id` = cp.`category_id` AND self.`path_id` = cp.`category_id`) WHERE cp.`path_id` = ? ORDER BY self.`level`", [$categoryId]));
    }

    /** OpenCart 4 keeps the full keyword path ("laptops/windows") in category SEO URLs: refresh a subtree. */
    public static function refreshCategorySeo4(int $categoryId, array $own, bool $replaceOwn = false): void
    {
        if (!self::$v4) return;
        foreach (self::subtree($categoryId) as $cid) {
            $path = self::categoryPath($cid);
            $parentPath = substr($path, 0, max(0, (int)strrpos($path, '_')));
            $rows = OcDb::rows("SELECT * FROM {seo_url} WHERE `key` = 'path' AND (`value` = ? OR `value` LIKE ?)", [(string)$cid, '%\_' . $cid]);
            $slugs = [];
            foreach ($rows as $r) $slugs[(int)$r['store_id']][(int)$r['language_id']] = basename($r['keyword']);
            if ($cid === $categoryId && $replaceOwn) $slugs = [];
            if ($cid === $categoryId) foreach ($own as $store => $byLang) foreach ($byLang as $lid => $slug) $slugs[$store][$lid] = $slug;

            OcDb::q("DELETE FROM {seo_url} WHERE `key` = 'path' AND (`value` = ? OR `value` LIKE ?)", [(string)$cid, '%\_' . $cid]);
            foreach ($slugs as $store => $byLang) {
                foreach ($byLang as $lid => $slug) {
                    $prefix = $parentPath !== '' ? (string)OcDb::val("SELECT `keyword` FROM {seo_url} WHERE `key` = 'path' AND `value` = ? AND `store_id` = ? AND `language_id` = ?", [$parentPath, $store, $lid]) : '';
                    self::seoSet('path', $path, ($prefix !== '' ? $prefix . '/' : '') . $slug, $store, $lid);
                }
            }
        }
    }

    public static function categoryName(int $id): string
    {
        return Mcp::dec((string)OcDb::val("SELECT `name` FROM {category_description} WHERE `category_id` = ? ORDER BY `language_id` = ? DESC LIMIT 1", [$id, self::defaultLanguageId()]));
    }

    public static function categoryFullName(int $id): string
    {
        $names = [];
        foreach (OcDb::col("SELECT `path_id` FROM {category_path} WHERE `category_id` = ? ORDER BY `level`", [$id]) as $pid) $names[] = self::categoryName((int)$pid);
        return implode(' > ', $names);
    }

    // ---- Images ------------------------------------------------------------

    private static function publicHost(string $host): bool
    {
        $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : (gethostbynamel($host) ?: []);
        if (!$ips) return false;
        foreach ($ips as $ip) {
            if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) return false;
        }
        return true;
    }

    /** Downloads an image into image/MCP_IMAGE_DIR and returns the path OpenCart stores ("catalog/mcp/x.jpg"). */
    public static function download(string $url, string $name = ''): string
    {
        $parts = parse_url($url);
        if (!in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true) || empty($parts['host'])) throw new McpFail("Not an http(s) URL: $url");
        if (!self::publicHost($parts['host'])) throw new McpFail("The host of $url is not a public address.");

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 40,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (OpenCart MCP image fetcher)',
            CURLOPT_NOPROGRESS => false,
            CURLOPT_PROGRESSFUNCTION => static fn($c, $dlTotal, $dl) => $dl > 20 * 1024 * 1024 ? 1 : 0,
        ]);
        $data = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($data === false || $code >= 400) throw new McpFail("Download failed ($url): " . ($err ?: "HTTP $code"));

        $info = @getimagesizefromstring($data);
        $ext = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_GIF => 'gif', IMAGETYPE_WEBP => 'webp'][$info[2] ?? 0] ?? null;
        if (!$ext) throw new McpFail("Not a JPEG/PNG/GIF/WEBP image: $url");

        $dir = trim(MCP_IMAGE_DIR, '/') . '/';
        if (!is_dir(DIR_IMAGE . $dir) && !@mkdir(DIR_IMAGE . $dir, 0755, true)) throw new McpFail('Cannot create folder image/' . $dir);

        $base = Mcp::slug($name !== '' ? $name : pathinfo((string)($parts['path'] ?? ''), PATHINFO_FILENAME));
        $file = "$base.$ext";
        for ($n = 2; is_file(DIR_IMAGE . $dir . $file); $n++) $file = "$base-$n.$ext";

        if (file_put_contents(DIR_IMAGE . $dir . $file, $data) === false) throw new McpFail('Cannot write image/' . $dir . $file);
        return $dir . $file;
    }

    /** Value of an image field: URL -> downloaded; "image/catalog/x.jpg" -> "catalog/x.jpg"; "" -> "". */
    public static function image($value, string $name = ''): string
    {
        $value = trim((string)$value);
        if ($value === '') return '';
        if (preg_match('#^https?://#i', $value)) {
            $own = self::catalogUrl() . 'image/';
            if (stripos($value, $own) === 0) return urldecode(substr($value, strlen($own)));
            return self::download($value, $name);
        }
        $value = ltrim($value, '/');
        if (strpos($value, 'image/') === 0) $value = substr($value, 6);
        if (strpos($value, '..') !== false) throw new McpFail("Bad image path: $value");
        if (!is_file(DIR_IMAGE . $value)) throw new McpFail("File image/$value does not exist. Pass a URL to download it or upload it first with image_upload.");
        return $value;
    }

    public static function imageUrl(?string $path): ?string
    {
        return $path ? self::catalogUrl() . 'image/' . str_replace('%2F', '/', rawurlencode($path)) : null;
    }

    // ---- Cache -------------------------------------------------------------

    public static function clearCache(string $prefix = ''): int
    {
        $dir = defined('DIR_CACHE') ? DIR_CACHE : (defined('DIR_STORAGE') ? DIR_STORAGE . 'cache/' : '');
        if ($dir === '' || !is_dir($dir)) return 0;
        $n = 0;
        foreach (glob(rtrim($dir, '/') . '/cache.' . ($prefix !== '' ? $prefix . '.' : '') . '*') ?: [] as $f) {
            if (is_file($f) && @unlink($f)) $n++;
        }
        return $n;
    }

    public static function money($value, string $code = '', float $rate = 1.0): string
    {
        $code = $code ?: (string)self::setting('config_currency', '');
        $c = OcDb::row("SELECT `symbol_left`, `symbol_right`, `decimal_place` FROM {currency} WHERE `code` = ?", [$code]);
        $num = number_format((float)$value * $rate, (int)($c['decimal_place'] ?? 2), '.', ' ');
        return trim(($c['symbol_left'] ?? '') . $num . ($c['symbol_right'] ?? '')) ?: "$num $code";
    }
}

// ===========================================================================
//  Products
// ===========================================================================

final class OcProduct
{
    public const DESC = ['name', 'description', 'tag', 'meta_title', 'meta_h1', 'meta_description', 'meta_keyword'];
    public const CODES = ['sku', 'upc', 'ean', 'jan', 'isbn', 'mpn'];
    public const NUM = ['quantity' => 'int', 'stock_status_id' => 'int', 'manufacturer_id' => 'int', 'shipping' => 'bool', 'price' => 'float', 'points' => 'int',
        'tax_class_id' => 'int', 'weight' => 'float', 'weight_class_id' => 'int', 'length' => 'float', 'width' => 'float', 'height' => 'float',
        'length_class_id' => 'int', 'subtract' => 'bool', 'minimum' => 'int', 'sort_order' => 'int', 'status' => 'bool'];

    public static function specialSql(string $alias = 'p'): string
    {
        $cg = Oc::customerGroupId();
        $date = "(%s.`date_start` = '0000-00-00' OR %s.`date_start` IS NULL OR %s.`date_start` <= CURDATE()) AND (%s.`date_end` = '0000-00-00' OR %s.`date_end` IS NULL OR %s.`date_end` >= CURDATE())";
        if (!Oc::$specialTable) {
            return "(SELECT (CASE WHEN ps.`type` = 'P' THEN ($alias.`price` - ($alias.`price` * (ps.`price` / 100))) WHEN ps.`type` = 'S' THEN ($alias.`price` - ps.`price`) ELSE ps.`price` END)
                FROM {product_discount} ps WHERE ps.`product_id` = $alias.`product_id` AND ps.`customer_group_id` = $cg AND ps.`special` = 1 AND " . str_replace('%s', 'ps', $date) . " ORDER BY ps.`priority`, ps.`price` LIMIT 1)";
        }
        return "(SELECT ps.`price` FROM {product_special} ps WHERE ps.`product_id` = $alias.`product_id` AND ps.`customer_group_id` = $cg AND " . str_replace('%s', 'ps', $date) . " ORDER BY ps.`priority`, ps.`price` LIMIT 1)";
    }

    public static function url(int $id): string
    {
        return Oc::catalogUrl() . 'index.php?route=product/product&product_id=' . $id;
    }

    public static function exists(int $id): bool
    {
        return (bool)OcDb::val("SELECT 1 FROM {product} WHERE `product_id` = ?", [$id]);
    }

    public static function codes(int $id): array
    {
        if (!Oc::$codeTable) {
            $r = OcDb::row("SELECT `sku`, `upc`, `ean`, `jan`, `isbn`, `mpn` FROM {product} WHERE `product_id` = ?", [$id]);
            return array_filter($r, static fn($v) => $v !== '' && $v !== null);
        }
        $out = [];
        foreach (OcDb::rows("SELECT `code`, `value` FROM {product_code} WHERE `product_id` = ?", [$id]) as $r) $out[strtolower($r['code'])] = $r['value'];
        return $out;
    }

    public static function get(int $id): array
    {
        $p = OcDb::row("SELECT p.*, " . self::specialSql() . " AS `special` FROM {product} p WHERE p.`product_id` = ?", [$id]);
        if (!$p) throw new McpFail("Product $id not found.");
        $lid = Oc::defaultLanguageId();

        $out = [
            'id' => $id,
            'url' => self::url($id),
            'model' => $p['model'],
            'codes' => self::codes($id),
            'price' => (float)$p['price'],
            'special' => $p['special'] !== null ? round((float)$p['special'], 4) : null,
            'currency' => Oc::setting('config_currency'),
            'quantity' => (int)$p['quantity'],
            'status' => (bool)$p['status'],
            'image' => $p['image'] ?: null,
            'image_url' => Oc::imageUrl($p['image']),
            'images' => array_map(static fn($r) => ['image' => $r['image'], 'url' => Oc::imageUrl($r['image']), 'sort_order' => (int)$r['sort_order']],
                OcDb::rows("SELECT `image`, `sort_order` FROM {product_image} WHERE `product_id` = ? ORDER BY `sort_order`, `product_image_id`", [$id])),
            'manufacturer' => $p['manufacturer_id'] ? ['id' => (int)$p['manufacturer_id'], 'name' => OcDb::val("SELECT `name` FROM {manufacturer} WHERE `manufacturer_id` = ?", [$p['manufacturer_id']])] : null,
            'categories' => array_map(static fn($c) => ['id' => (int)$c, 'name' => Oc::categoryFullName((int)$c)],
                OcDb::col("SELECT `category_id` FROM {product_to_category} WHERE `product_id` = ?", [$id])),
            'stores' => array_map('intval', OcDb::col("SELECT `store_id` FROM {product_to_store} WHERE `product_id` = ?", [$id])),
            'descriptions' => Oc::descriptions('product_description', 'product_id', $id),
            'seo_keywords' => Oc::seoGet('product_id', (string)$id),
            'attributes' => array_map(static fn($r) => ['attribute_id' => (int)$r['attribute_id'], 'group' => Mcp::dec((string)$r['group_name']), 'name' => Mcp::dec((string)$r['name']), 'language' => Oc::langCode((int)$r['language_id']), 'text' => Mcp::dec($r['text'])],
                OcDb::rows("SELECT pa.*, ad.`name`, agd.`name` AS group_name FROM {product_attribute} pa
                    LEFT JOIN {attribute} a ON a.`attribute_id` = pa.`attribute_id`
                    LEFT JOIN {attribute_description} ad ON ad.`attribute_id` = pa.`attribute_id` AND ad.`language_id` = ?
                    LEFT JOIN {attribute_group_description} agd ON agd.`attribute_group_id` = a.`attribute_group_id` AND agd.`language_id` = ?
                    WHERE pa.`product_id` = ? ORDER BY a.`sort_order`, pa.`language_id`", [$lid, $lid, $id])),
            'specials' => !Oc::$specialTable
                ? OcDb::rows("SELECT `customer_group_id`, `priority`, `price`, `type`, `date_start`, `date_end` FROM {product_discount} WHERE `product_id` = ? AND `special` = 1", [$id])
                : OcDb::rows("SELECT `customer_group_id`, `priority`, `price`, `date_start`, `date_end` FROM {product_special} WHERE `product_id` = ?", [$id]),
            'discounts' => OcDb::rows("SELECT `customer_group_id`, `quantity`, `priority`, `price`, `date_start`, `date_end` FROM {product_discount} WHERE `product_id` = ?" . (OcDb::hasCol('product_discount', 'special') ? " AND `special` = 0" : ''), [$id]),
            'options' => array_map(static fn($r) => ['product_option_id' => (int)$r['product_option_id'], 'option_id' => (int)$r['option_id'], 'name' => Mcp::dec((string)$r['name']), 'type' => $r['type'], 'required' => (bool)$r['required']],
                OcDb::rows("SELECT po.*, od.`name`, o.`type` FROM {product_option} po LEFT JOIN {option} o ON o.`option_id` = po.`option_id` LEFT JOIN {option_description} od ON od.`option_id` = po.`option_id` AND od.`language_id` = ? WHERE po.`product_id` = ?", [$lid, $id])),
        ];

        foreach (['location', 'stock_status_id', 'tax_class_id', 'points', 'date_available', 'weight', 'weight_class_id', 'length', 'width', 'height', 'length_class_id',
                     'subtract', 'minimum', 'shipping', 'sort_order', 'viewed', 'rating', 'master_id', 'date_added', 'date_modified'] as $f) {
            if (array_key_exists($f, $p)) $out[$f] = $p[$f];
        }
        return $out;
    }

    /** Main columns of oc_product from the arguments. */
    private static function columns(bool $create): array
    {
        $data = [];
        foreach (self::NUM as $f => $type) {
            if (!Mcp::has($f)) continue;
            $v = Mcp::arg($f);
            $data[$f] = $type === 'int' ? (int)$v : ($type === 'float' ? (float)$v : Mcp::flag($v));
        }
        foreach (['model', 'location', 'date_available'] as $f) if (Mcp::has($f)) $data[$f] = (string)Mcp::arg($f);
        if (!Oc::$codeTable) foreach (self::CODES as $f) if (Mcp::has($f)) $data[$f] = (string)Mcp::arg($f);

        if (Mcp::has('manufacturer') && !Mcp::has('manufacturer_id')) $data['manufacturer_id'] = OcManufacturer::findOrCreate((string)Mcp::arg('manufacturer'));
        if (Mcp::has('image')) $data['image'] = Oc::image(Mcp::arg('image'), (string)Mcp::arg('name', ''));

        if ($create) {
            $data += [
                'model' => '', 'location' => '', 'quantity' => 1, 'minimum' => 1, 'subtract' => 1, 'stock_status_id' => (int)Oc::setting('config_stock_status_id', 0),
                'date_available' => date('Y-m-d'), 'manufacturer_id' => 0, 'shipping' => 1, 'price' => 0, 'points' => 0, 'weight' => 0,
                'weight_class_id' => (int)Oc::setting('config_weight_class_id', 1), 'length' => 0, 'width' => 0, 'height' => 0,
                'length_class_id' => (int)Oc::setting('config_length_class_id', 1), 'status' => 1, 'tax_class_id' => 0, 'sort_order' => 0, 'image' => '',
            ];
            if (Oc::$v4) $data += ['master_id' => 0, 'variant' => '', 'override' => ''];
            if (!Oc::$codeTable) $data += array_fill_keys(self::CODES, '');
        }
        if (isset($data['model']) && $data['model'] === '' && $create) throw new McpFail('"model" is required (OpenCart needs a model / article, 1-64 characters).');
        return $data;
    }

    public static function save(?int $id): array
    {
        $create = $id === null;
        if (!$create && !self::exists($id)) throw new McpFail("Product $id not found.");

        // validate and download everything first: OpenCart 3 tables are MyISAM, where a transaction cannot roll back
        $cats = null;
        if (Mcp::has('categories')) {
            $cats = Mcp::ids(Mcp::arg('categories'));
            $known = array_map('intval', OcDb::col("SELECT `category_id` FROM {category} WHERE `category_id` IN (" . OcDb::in($cats) . ")"));
            if ($missing = array_diff($cats, $known)) throw new McpFail('Unknown category id(s): ' . implode(', ', $missing));
        }
        $paths = null;
        if (Mcp::has('images')) {
            $list = Mcp::arg('images');
            if (!is_array($list)) throw new McpFail('"images" must be an array of URLs or paths.');
            $paths = [];
            foreach (array_values($list) as $n => $img) if (trim((string)$img) !== '') $paths[] = Oc::image($img, Mcp::arg('name', 'product-' . ($id ?? 'new')) . '-' . ($n + 1));
        }
        if (Mcp::has('attributes') && !is_array(Mcp::arg('attributes'))) throw new McpFail('"attributes" must be an object {"attribute id or name": "value"}.');
        Oc::validateLanguageArgs();
        if (Mcp::has('stores') && !Mcp::ids(Mcp::arg('stores'))) throw new McpFail('"stores" must contain at least one store id.');

        $data = self::columns($create);
        $data['date_modified'] = date('Y-m-d H:i:s');

        OcDb::$pdo->beginTransaction();
        try {
            if ($create) {
                $data['date_added'] = $data['date_modified'];
                $id = OcDb::insert('product', $data);
            } else {
                OcDb::update('product', $data, ['product_id' => $id]);
            }

            $changed = Oc::saveDescriptions('product_description', 'product_id', $id, self::DESC, 'name', $create);

            // product codes (4.x keeps SKU, UPC… in a separate table)
            if (Oc::$codeTable) {
                foreach (self::CODES as $f) {
                    if (!Mcp::has($f)) continue;
                    OcDb::q("DELETE FROM {product_code} WHERE `product_id` = ? AND UPPER(`code`) = ?", [$id, strtoupper($f)]);
                    if ((string)Mcp::arg($f) !== '') OcDb::insert('product_code', ['product_id' => $id, 'code' => strtoupper($f), 'value' => (string)Mcp::arg($f)]);
                }
            }

            // stores
            $stores = Mcp::has('stores') ? Mcp::ids(Mcp::arg('stores')) : ($create ? [0] : null);
            if ($stores !== null) {
                OcDb::delete('product_to_store', ['product_id' => $id]);
                foreach ($stores as $s) OcDb::insert('product_to_store', ['product_id' => $id, 'store_id' => $s]);
            }

            // categories
            if ($cats !== null) {
                OcDb::delete('product_to_category', ['product_id' => $id]);
                foreach ($cats as $c) OcDb::insert('product_to_category', ['product_id' => $id, 'category_id' => $c]);
            }

            // additional images
            if ($paths !== null) {
                OcDb::delete('product_image', ['product_id' => $id]);
                foreach ($paths as $n => $path) OcDb::insert('product_image', ['product_id' => $id, 'image' => $path, 'sort_order' => $n]);
            }

            // special price for the default customer group
            if (Mcp::has('special_price')) {
                $cg = Oc::customerGroupId();
                $price = (float)Mcp::arg('special_price');
                $start = (string)Mcp::arg('special_start', '0000-00-00') ?: '0000-00-00';
                $end = (string)Mcp::arg('special_end', '0000-00-00') ?: '0000-00-00';
                if (!Oc::$specialTable) {
                    OcDb::q("DELETE FROM {product_discount} WHERE `product_id` = ? AND `customer_group_id` = ? AND `special` = 1", [$id, $cg]);
                    if ($price > 0) OcDb::insert('product_discount', ['product_id' => $id, 'customer_group_id' => $cg, 'quantity' => 1, 'priority' => 1, 'price' => $price, 'type' => 'F', 'special' => 1, 'date_start' => $start, 'date_end' => $end]);
                } else {
                    OcDb::delete('product_special', ['product_id' => $id, 'customer_group_id' => $cg]);
                    if ($price > 0) OcDb::insert('product_special', ['product_id' => $id, 'customer_group_id' => $cg, 'priority' => 1, 'price' => $price, 'date_start' => $start, 'date_end' => $end]);
                }
            }

            // attributes: {"attribute id or name": "text"}
            if (Mcp::has('attributes')) {
                $attrs = Mcp::arg('attributes');
                if (Mcp::flag(Mcp::arg('replace_attributes', false))) OcDb::delete('product_attribute', ['product_id' => $id]);
                foreach ($attrs as $key => $text) {
                    $aid = OcAttribute::resolve((string)$key);
                    OcDb::delete('product_attribute', ['product_id' => $id, 'attribute_id' => $aid]);
                    if ($text === null || (string)$text === '') continue;
                    $texts = is_array($text) ? $text : null;
                    foreach (Oc::languages() as $lid => $l) {
                        $value = $texts !== null ? (string)($texts[$l['code']] ?? reset($texts)) : (string)$text;
                        OcDb::insert('product_attribute', ['product_id' => $id, 'attribute_id' => $aid, 'language_id' => $lid, 'text' => $value]);
                    }
                }
            }

            // SEO URLs
            $names = [];
            foreach (OcDb::rows("SELECT `language_id`, `name` FROM {product_description} WHERE `product_id` = ?", [$id]) as $r) $names[(int)$r['language_id']] = $r['name'];
            $seo = [];
            $wanted = Oc::wantedKeywords($names, $create, 'product_id', (string)$id);
            if ($wanted) {
                if (Mcp::has('stores')) Oc::seoDelete('product_id', (string)$id);
                $storeList = array_map('intval', OcDb::col("SELECT `store_id` FROM {product_to_store} WHERE `product_id` = ?", [$id])) ?: [0];
                foreach ($storeList as $s) foreach ($wanted as $lid => $kw) $seo[$s][Oc::langCode($lid)] = Oc::seoSet('product_id', (string)$id, $kw, $s, $lid, 1);
            }

            OcDb::$pdo->commit();
        } catch (\Throwable $e) {
            OcDb::$pdo->rollBack();
            throw $e;
        }

        Oc::clearCache('product');
        $result = ['ok' => true, 'id' => $id, 'url' => self::url($id), 'action' => $create ? 'created' : 'updated'];
        if ($changed) $result['languages'] = $changed;
        if ($seo) $result['seo_keywords'] = Oc::byStore($seo);
        return $result;
    }

    public static function delete(int $id): array
    {
        $name = OcDb::val("SELECT `name` FROM {product_description} WHERE `product_id` = ? LIMIT 1", [$id]);
        if (!self::exists($id)) throw new McpFail("Product $id not found.");

        OcDb::$pdo->beginTransaction();
        try {
            foreach (['product', 'product_attribute', 'product_code', 'product_description', 'product_discount', 'product_special', 'product_filter', 'product_image',
                         'product_option', 'product_option_value', 'product_recurring', 'product_subscription', 'product_report', 'product_reward', 'product_to_category',
                         'product_to_download', 'product_to_layout', 'product_to_store', 'review', 'coupon_product'] as $t) {
                OcDb::delete($t, ['product_id' => $id]);
            }
            OcDb::delete('product_related', ['product_id' => $id]);
            OcDb::delete('product_related', ['related_id' => $id]);
            if (OcDb::hasCol('product', 'master_id')) OcDb::q("UPDATE {product} SET `master_id` = 0 WHERE `master_id` = ?", [$id]);
            Oc::seoDelete('product_id', (string)$id);
            OcDb::$pdo->commit();
        } catch (\Throwable $e) {
            OcDb::$pdo->rollBack();
            throw $e;
        }
        Oc::clearCache('product');
        return ['ok' => true, 'deleted' => $id, 'name' => Mcp::dec((string)$name)];
    }

    public static function list(): array
    {
        [$limit, $offset] = Mcp::limits();
        $lid = Oc::languageId(Mcp::arg('language'));
        $where = ['1'];
        $p = [$lid];

        if (Mcp::has('search') && ($s = trim((string)Mcp::arg('search'))) !== '') {
            $codeSql = Oc::$codeTable ? "OR p.`product_id` IN (SELECT `product_id` FROM {product_code} WHERE `value` = ?)" : "OR p.`sku` = ? ";
            $where[] = "(pd.`name` LIKE ? OR p.`model` LIKE ? $codeSql OR p.`product_id` = ?)";
            array_push($p, '%' . Mcp::enc($s) . '%', '%' . $s . '%', $s, (int)$s);
        }
        if (Mcp::has('category_id')) {
            $cid = (int)Mcp::arg('category_id');
            $where[] = Mcp::flag(Mcp::arg('subcategories', true))
                ? "p.`product_id` IN (SELECT p2c.`product_id` FROM {product_to_category} p2c JOIN {category_path} cp ON cp.`category_id` = p2c.`category_id` WHERE cp.`path_id` = ?)"
                : "p.`product_id` IN (SELECT `product_id` FROM {product_to_category} WHERE `category_id` = ?)";
            $p[] = $cid;
        }
        if (Mcp::has('manufacturer_id')) { $where[] = 'p.`manufacturer_id` = ?'; $p[] = (int)Mcp::arg('manufacturer_id'); }
        if (Mcp::has('status')) { $where[] = 'p.`status` = ?'; $p[] = Mcp::flag(Mcp::arg('status')); }
        if (Mcp::has('quantity_max')) { $where[] = 'p.`quantity` <= ?'; $p[] = (int)Mcp::arg('quantity_max'); }
        if (Mcp::has('price_min')) { $where[] = 'p.`price` >= ?'; $p[] = (float)Mcp::arg('price_min'); }
        if (Mcp::has('price_max')) { $where[] = 'p.`price` <= ?'; $p[] = (float)Mcp::arg('price_max'); }
        if (Mcp::flag(Mcp::arg('special_only', false))) $where[] = self::specialSql() . ' IS NOT NULL';

        $sorts = ['newest' => 'p.`date_added` DESC', 'oldest' => 'p.`date_added` ASC', 'name' => 'pd.`name` ASC', 'price' => 'p.`price` ASC', 'price_desc' => 'p.`price` DESC',
            'quantity' => 'p.`quantity` ASC', 'modified' => 'p.`date_modified` DESC', 'id' => 'p.`product_id` ASC'];
        $order = $sorts[Mcp::arg('sort', 'newest')] ?? $sorts['newest'];

        $from = "FROM {product} p LEFT JOIN {product_description} pd ON (pd.`product_id` = p.`product_id` AND pd.`language_id` = ?) WHERE " . implode(' AND ', $where);
        $total = (int)OcDb::val("SELECT COUNT(*) $from", $p);
        $rows = OcDb::rows("SELECT p.`product_id`, pd.`name`, p.`model`, p.`price`, p.`quantity`, p.`status`, p.`image`, p.`date_added`, " . self::specialSql() . " AS `special` $from ORDER BY $order LIMIT $limit OFFSET $offset", $p);

        return ['total' => $total, 'offset' => $offset, 'currency' => Oc::setting('config_currency'), 'items' => array_map(static fn($r) => [
            'id' => (int)$r['product_id'], 'name' => Mcp::dec((string)$r['name']), 'model' => $r['model'], 'price' => (float)$r['price'],
            'special' => $r['special'] !== null ? round((float)$r['special'], 4) : null, 'quantity' => (int)$r['quantity'], 'status' => (bool)$r['status'],
            'image' => $r['image'] ?: null, 'date_added' => $r['date_added'],
        ], $rows)];
    }
}

// ===========================================================================
//  Categories, manufacturers, attributes, information pages
// ===========================================================================

final class OcCategory
{
    public const DESC = ['name', 'description', 'meta_title', 'meta_h1', 'meta_description', 'meta_keyword'];

    public static function list(): array
    {
        $lid = Oc::languageId(Mcp::arg('language'));
        $full = Mcp::flag(Mcp::arg('full', false));
        $rows = OcDb::rows("SELECT c.*, cd.`name`, cd.`meta_title`, cd.`description`, (SELECT COUNT(*) FROM {product_to_category} p2c WHERE p2c.`category_id` = c.`category_id`) AS products,
            (SELECT GROUP_CONCAT(cp.`path_id` ORDER BY cp.`level` SEPARATOR '_') FROM {category_path} cp WHERE cp.`category_id` = c.`category_id`) AS path
            FROM {category} c LEFT JOIN {category_description} cd ON (cd.`category_id` = c.`category_id` AND cd.`language_id` = ?)", [$lid]);

        $names = array_column($rows, 'name', 'category_id');
        $items = [];
        foreach ($rows as $r) {
            $path = explode('_', (string)$r['path']);
            $item = [
                'id' => (int)$r['category_id'], 'name' => Mcp::dec((string)$r['name']), 'parent_id' => (int)$r['parent_id'],
                'path' => implode(' > ', array_map(static fn($i) => Mcp::dec((string)($names[$i] ?? $i)), $path)),
                'level' => count($path) - 1, 'products' => (int)$r['products'], 'status' => (bool)$r['status'], 'sort_order' => (int)$r['sort_order'],
            ];
            if (array_key_exists('top', $r)) $item['top'] = (bool)$r['top'];
            if ($full) {
                $item['image'] = $r['image'] ?: null;
                $item['meta_title'] = Mcp::dec((string)$r['meta_title']);
                $item['description'] = Mcp::text(Mcp::dec((string)$r['description']), 200);
                $item['seo_keywords'] = Oc::seoGet(Oc::$v4 ? 'path' : 'category_id', Oc::$v4 ? (string)$r['path'] : (string)$r['category_id']);
            }
            $items[] = $item;
        }
        usort($items, static fn($a, $b) => strcmp($a['path'], $b['path']));
        return ['total' => count($items), 'items' => $items];
    }

    public static function get(int $id): array
    {
        $c = OcDb::row("SELECT * FROM {category} WHERE `category_id` = ?", [$id]);
        if (!$c) throw new McpFail("Category $id not found.");
        $c['full_name'] = Oc::categoryFullName($id);
        $c['descriptions'] = Oc::descriptions('category_description', 'category_id', $id);
        $c['stores'] = array_map('intval', OcDb::col("SELECT `store_id` FROM {category_to_store} WHERE `category_id` = ?", [$id]));
        $c['seo_keywords'] = Oc::seoGet(Oc::$v4 ? 'path' : 'category_id', Oc::$v4 ? Oc::categoryPath($id) : (string)$id);
        $c['products'] = (int)OcDb::val("SELECT COUNT(*) FROM {product_to_category} WHERE `category_id` = ?", [$id]);
        $c['image_url'] = Oc::imageUrl($c['image']);
        return $c;
    }

    public static function save(?int $id): array
    {
        $create = $id === null;
        $old = $create ? [] : OcDb::row("SELECT * FROM {category} WHERE `category_id` = ?", [$id]);
        if (!$create && !$old) throw new McpFail("Category $id not found.");
        Oc::validateLanguageArgs();

        $data = [];
        if (Mcp::has('parent_id')) {
            $parent = (int)Mcp::arg('parent_id');
            if ($parent && !OcDb::val("SELECT 1 FROM {category} WHERE `category_id` = ?", [$parent])) throw new McpFail("Parent category $parent not found.");
            if (!$create && $parent && in_array($parent, Oc::subtree($id), true)) throw new McpFail('A category cannot be moved into itself or its subcategory.');
            $data['parent_id'] = $parent;
        }
        foreach (['sort_order' => 'int', 'status' => 'bool', 'top' => 'bool', 'column' => 'int'] as $f => $type) {
            if (Mcp::has($f)) $data[$f] = $type === 'int' ? (int)Mcp::arg($f) : Mcp::flag(Mcp::arg($f));
        }
        if (Mcp::has('image')) $data['image'] = Oc::image(Mcp::arg('image'), (string)Mcp::arg('name', ''));

        $now = date('Y-m-d H:i:s');
        OcDb::$pdo->beginTransaction();
        try {
            if ($create) {
                $id = OcDb::insert('category', $data + ['image' => '', 'parent_id' => 0, 'top' => 0, 'column' => 1, 'sort_order' => 0, 'status' => 1, 'date_added' => $now, 'date_modified' => $now]);
            } else {
                OcDb::update('category', $data + ['date_modified' => $now], ['category_id' => $id]);
            }

            $changed = Oc::saveDescriptions('category_description', 'category_id', $id, self::DESC, 'name', $create);

            $stores = Mcp::has('stores') ? Mcp::ids(Mcp::arg('stores')) : ($create ? [0] : null);
            if ($stores !== null) {
                OcDb::delete('category_to_store', ['category_id' => $id]);
                foreach ($stores as $s) OcDb::insert('category_to_store', ['category_id' => $id, 'store_id' => $s]);
            }

            $moved = $create || (isset($data['parent_id']) && (int)$data['parent_id'] !== (int)$old['parent_id']);
            if ($moved) Oc::rebuildPaths();

            $names = [];
            foreach (OcDb::rows("SELECT `language_id`, `name` FROM {category_description} WHERE `category_id` = ?", [$id]) as $r) $names[(int)$r['language_id']] = $r['name'];
            $wanted = Oc::wantedKeywords($names, $create, Oc::$v4 ? 'path' : 'category_id', Oc::$v4 ? Oc::categoryPath($id) : (string)$id);
            $storeList = array_map('intval', OcDb::col("SELECT `store_id` FROM {category_to_store} WHERE `category_id` = ?", [$id])) ?: [0];
            if ($wanted && Mcp::has('stores') && !Oc::$v4) Oc::seoDelete('category_id', (string)$id);

            if (Oc::$v4) {
                $own = [];
                foreach ($storeList as $s) foreach ($wanted as $lid => $kw) $own[$s][$lid] = basename($kw);
                if ($own || $moved) Oc::refreshCategorySeo4($id, $own, $own && Mcp::has('stores'));
            } else {
                foreach ($storeList as $s) foreach ($wanted as $lid => $kw) Oc::seoSet('category_id', (string)$id, $kw, $s, $lid);
            }

            OcDb::$pdo->commit();
        } catch (\Throwable $e) {
            OcDb::$pdo->rollBack();
            throw $e;
        }

        Oc::clearCache('category');
        Oc::clearCache('product');
        return ['ok' => true, 'id' => $id, 'action' => $create ? 'created' : 'updated', 'full_name' => Oc::categoryFullName($id), 'languages' => $changed,
            'seo_keywords' => Oc::seoGet(Oc::$v4 ? 'path' : 'category_id', Oc::$v4 ? Oc::categoryPath($id) : (string)$id)];
    }

    public static function delete(int $id): array
    {
        if (!OcDb::val("SELECT 1 FROM {category} WHERE `category_id` = ?", [$id])) throw new McpFail("Category $id not found.");
        $ids = Oc::subtree($id) ?: [$id];
        $name = Oc::categoryFullName($id);

        OcDb::$pdo->beginTransaction();
        try {
            foreach ($ids as $cid) {
                if (Oc::$v4) OcDb::q("DELETE FROM {seo_url} WHERE `key` = 'path' AND (`value` = ? OR `value` LIKE ?)", [(string)$cid, '%\_' . $cid]);
                else Oc::seoDelete('category_id', (string)$cid);
                foreach (['category', 'category_description', 'category_filter', 'category_to_store', 'category_to_layout', 'product_to_category', 'coupon_category'] as $t) {
                    OcDb::delete($t, ['category_id' => $cid]);
                }
            }
            OcDb::q("DELETE FROM {category_path} WHERE `category_id` IN (" . OcDb::in($ids) . ") OR `path_id` IN (" . OcDb::in($ids) . ")");
            OcDb::$pdo->commit();
        } catch (\Throwable $e) {
            OcDb::$pdo->rollBack();
            throw $e;
        }
        Oc::clearCache('category');
        Oc::clearCache('product');
        return ['ok' => true, 'deleted' => $ids, 'name' => $name, 'note' => 'Products stay in the shop, only their links to these categories were removed.'];
    }
}

final class OcManufacturer
{
    public static function list(): array
    {
        $rows = OcDb::rows("SELECT m.*, (SELECT COUNT(*) FROM {product} p WHERE p.`manufacturer_id` = m.`manufacturer_id`) AS products FROM {manufacturer} m ORDER BY m.`name`");
        return ['total' => count($rows), 'items' => array_map(static fn($r) => ['id' => (int)$r['manufacturer_id'], 'name' => Mcp::dec($r['name']), 'products' => (int)$r['products'],
            'image' => $r['image'] ?: null, 'sort_order' => (int)$r['sort_order'], 'seo_keywords' => Oc::seoGet('manufacturer_id', (string)$r['manufacturer_id'])], $rows)];
    }

    public static function findOrCreate(string $name): int
    {
        $name = trim($name);
        if ($name === '') return 0;
        $id = OcDb::val("SELECT `manufacturer_id` FROM {manufacturer} WHERE `name` = ? OR `name` = ?", [$name, Mcp::enc($name)]);
        return $id ? (int)$id : (int)self::save(null, ['name' => $name])['id'];
    }

    public static function save(?int $id, ?array $args = null): array
    {
        $saved = Mcp::$args;
        if ($args !== null) Mcp::$args = $args;
        try {
            $create = $id === null;
            if (!$create && !OcDb::val("SELECT 1 FROM {manufacturer} WHERE `manufacturer_id` = ?", [$id])) throw new McpFail("Manufacturer $id not found.");
            Oc::validateLanguageArgs();
            $data = [];
            if (Mcp::has('name')) $data['name'] = Mcp::enc(trim((string)Mcp::arg('name')));
            if (Mcp::has('sort_order')) $data['sort_order'] = (int)Mcp::arg('sort_order');
            if (Mcp::has('image')) $data['image'] = Oc::image(Mcp::arg('image'), (string)Mcp::arg('name', ''));
            if ($create && ($data['name'] ?? '') === '') throw new McpFail('"name" is required.');

            if ($create) {
                $id = OcDb::insert('manufacturer', $data + ['image' => '', 'sort_order' => 0]);
                foreach (Mcp::has('stores') ? Mcp::ids(Mcp::arg('stores')) : [0] as $s) OcDb::insert('manufacturer_to_store', ['manufacturer_id' => $id, 'store_id' => $s]);
            } else {
                OcDb::update('manufacturer', $data, ['manufacturer_id' => $id]);
                if (Mcp::has('stores')) {
                    OcDb::delete('manufacturer_to_store', ['manufacturer_id' => $id]);
                    foreach (Mcp::ids(Mcp::arg('stores')) as $s) OcDb::insert('manufacturer_to_store', ['manufacturer_id' => $id, 'store_id' => $s]);
                }
            }

            // ocStore-like forks have manufacturer_description
            if (OcDb::hasTable('manufacturer_description')) {
                $fields = array_values(array_intersect(['name', 'description', 'meta_title', 'meta_h1', 'meta_description', 'meta_keyword'], array_keys(OcDb::cols('manufacturer_description'))));
                $brand = Mcp::dec((string)OcDb::val("SELECT `name` FROM {manufacturer} WHERE `manufacturer_id` = ?", [$id]));
                $defaults = array_intersect_key(['name' => $brand, 'meta_title' => $brand, 'meta_h1' => $brand], array_flip($fields));
                Oc::saveDescriptions('manufacturer_description', 'manufacturer_id', $id, $fields, '', $create, $defaults);
            }

            $name = Mcp::dec((string)OcDb::val("SELECT `name` FROM {manufacturer} WHERE `manufacturer_id` = ?", [$id]));
            $wanted = Oc::wantedKeywords(array_fill_keys(array_keys(Oc::languages()), $name), $create, 'manufacturer_id', (string)$id);
            if ($wanted && Mcp::has('stores')) Oc::seoDelete('manufacturer_id', (string)$id);
            $stores = array_map('intval', OcDb::col("SELECT `store_id` FROM {manufacturer_to_store} WHERE `manufacturer_id` = ?", [$id])) ?: [0];
            foreach ($stores as $s) foreach ($wanted as $lid => $kw) Oc::seoSet('manufacturer_id', (string)$id, $kw, $s, $lid);

            Oc::clearCache('manufacturer');
            return ['ok' => true, 'id' => $id, 'name' => $name, 'action' => $create ? 'created' : 'updated', 'seo_keywords' => Oc::seoGet('manufacturer_id', (string)$id)];
        } finally {
            Mcp::$args = $saved;
        }
    }

    public static function delete(int $id): array
    {
        $name = OcDb::val("SELECT `name` FROM {manufacturer} WHERE `manufacturer_id` = ?", [$id]);
        if ($name === null) throw new McpFail("Manufacturer $id not found.");
        $products = (int)OcDb::val("SELECT COUNT(*) FROM {product} WHERE `manufacturer_id` = ?", [$id]);
        if ($products && !Mcp::flag(Mcp::arg('force', false))) throw new McpFail("$products product(s) use this manufacturer. Pass force=true to delete it anyway (products keep no manufacturer).");
        foreach (['manufacturer', 'manufacturer_description', 'manufacturer_to_store', 'manufacturer_to_layout'] as $t) OcDb::delete($t, ['manufacturer_id' => $id]);
        OcDb::q("UPDATE {product} SET `manufacturer_id` = 0 WHERE `manufacturer_id` = ?", [$id]);
        Oc::seoDelete('manufacturer_id', (string)$id);
        Oc::clearCache('manufacturer');
        Oc::clearCache('product');
        return ['ok' => true, 'deleted' => $id, 'name' => Mcp::dec($name)];
    }
}

final class OcAttribute
{
    public static function list(): array
    {
        $lid = Oc::languageId(Mcp::arg('language'));
        $rows = OcDb::rows("SELECT a.`attribute_id`, a.`attribute_group_id`, ad.`name`, agd.`name` AS group_name,
            (SELECT COUNT(DISTINCT pa.`product_id`) FROM {product_attribute} pa WHERE pa.`attribute_id` = a.`attribute_id`) AS products
            FROM {attribute} a LEFT JOIN {attribute_description} ad ON (ad.`attribute_id` = a.`attribute_id` AND ad.`language_id` = ?)
            LEFT JOIN {attribute_group} ag ON ag.`attribute_group_id` = a.`attribute_group_id`
            LEFT JOIN {attribute_group_description} agd ON (agd.`attribute_group_id` = a.`attribute_group_id` AND agd.`language_id` = ?)
            ORDER BY ag.`sort_order`, a.`sort_order`", [$lid, $lid]);
        $groups = [];
        foreach ($rows as $r) {
            $g = (int)$r['attribute_group_id'];
            $groups[$g]['group_id'] = $g;
            $groups[$g]['group'] = Mcp::dec((string)$r['group_name']);
            $groups[$g]['attributes'][] = ['id' => (int)$r['attribute_id'], 'name' => Mcp::dec((string)$r['name']), 'products' => (int)$r['products']];
        }
        return ['groups' => array_values($groups)];
    }

    /** Attribute id by id or name; creates the attribute (in group "attribute_group" or the first group) when the name is new. */
    public static function resolve(string $key): int
    {
        if (ctype_digit($key) && OcDb::val("SELECT 1 FROM {attribute} WHERE `attribute_id` = ?", [(int)$key])) return (int)$key;
        $id = OcDb::val("SELECT `attribute_id` FROM {attribute_description} WHERE `name` = ? OR `name` = ? LIMIT 1", [$key, Mcp::enc($key)]);
        if ($id) return (int)$id;

        $groupName = trim((string)Mcp::arg('attribute_group', ''));
        $gid = $groupName !== '' ? OcDb::val("SELECT `attribute_group_id` FROM {attribute_group_description} WHERE `name` = ? OR `name` = ? LIMIT 1", [$groupName, Mcp::enc($groupName)]) : null;
        if (!$gid && $groupName !== '') {
            $gid = OcDb::insert('attribute_group', ['sort_order' => 0]);
            foreach (Oc::languages() as $lid => $l) OcDb::insert('attribute_group_description', ['attribute_group_id' => $gid, 'language_id' => $lid, 'name' => Mcp::enc($groupName)]);
        }
        if (!$gid) $gid = OcDb::val("SELECT `attribute_group_id` FROM {attribute_group} ORDER BY `sort_order`, `attribute_group_id` LIMIT 1");
        if (!$gid) {
            $gid = OcDb::insert('attribute_group', ['sort_order' => 0]);
            foreach (Oc::languages() as $lid => $l) OcDb::insert('attribute_group_description', ['attribute_group_id' => $gid, 'language_id' => $lid, 'name' => 'General']);
        }

        $id = OcDb::insert('attribute', ['attribute_group_id' => (int)$gid, 'sort_order' => 0]);
        foreach (Oc::languages() as $lid => $l) OcDb::insert('attribute_description', ['attribute_id' => $id, 'language_id' => $lid, 'name' => Mcp::enc($key)]);
        return $id;
    }
}

final class OcInformation
{
    public const DESC = ['title', 'description', 'meta_title', 'meta_h1', 'meta_description', 'meta_keyword'];

    public static function list(): array
    {
        $lid = Oc::languageId(Mcp::arg('language'));
        $rows = OcDb::rows("SELECT i.*, id.`title` FROM {information} i LEFT JOIN {information_description} id ON (id.`information_id` = i.`information_id` AND id.`language_id` = ?) ORDER BY i.`sort_order`, id.`title`", [$lid]);
        return ['total' => count($rows), 'items' => array_map(static fn($r) => array_filter([
            'id' => (int)$r['information_id'], 'title' => Mcp::dec((string)$r['title']), 'status' => (bool)$r['status'], 'sort_order' => (int)$r['sort_order'],
            'bottom' => array_key_exists('bottom', $r) ? (bool)$r['bottom'] : null,
            'url' => Oc::catalogUrl() . 'index.php?route=information/information&information_id=' . $r['information_id'],
        ], static fn($v) => $v !== null), $rows)];
    }

    public static function get(int $id): array
    {
        $r = OcDb::row("SELECT * FROM {information} WHERE `information_id` = ?", [$id]);
        if (!$r) throw new McpFail("Information page $id not found.");
        $r['url'] = Oc::catalogUrl() . 'index.php?route=information/information&information_id=' . $id;
        $r['descriptions'] = Oc::descriptions('information_description', 'information_id', $id);
        $r['stores'] = array_map('intval', OcDb::col("SELECT `store_id` FROM {information_to_store} WHERE `information_id` = ?", [$id]));
        $r['seo_keywords'] = Oc::seoGet('information_id', (string)$id);
        return $r;
    }

    public static function save(?int $id): array
    {
        $create = $id === null;
        if (!$create && !OcDb::val("SELECT 1 FROM {information} WHERE `information_id` = ?", [$id])) throw new McpFail("Information page $id not found.");
        Oc::validateLanguageArgs();
        $data = [];
        foreach (['sort_order' => 'int', 'status' => 'bool', 'bottom' => 'bool'] as $f => $type) if (Mcp::has($f)) $data[$f] = $type === 'int' ? (int)Mcp::arg($f) : Mcp::flag(Mcp::arg($f));

        OcDb::$pdo->beginTransaction();
        try {
            if ($create) $id = OcDb::insert('information', $data + ['bottom' => 0, 'sort_order' => 0, 'status' => 1]);
            else OcDb::update('information', $data, ['information_id' => $id]);

            $changed = Oc::saveDescriptions('information_description', 'information_id', $id, self::DESC, 'title', $create);

            $stores = Mcp::has('stores') ? Mcp::ids(Mcp::arg('stores')) : ($create ? [0] : null);
            if ($stores !== null) {
                OcDb::delete('information_to_store', ['information_id' => $id]);
                foreach ($stores as $s) OcDb::insert('information_to_store', ['information_id' => $id, 'store_id' => $s]);
            }

            $names = [];
            foreach (OcDb::rows("SELECT `language_id`, `title` FROM {information_description} WHERE `information_id` = ?", [$id]) as $r) $names[(int)$r['language_id']] = $r['title'];
            $wanted = Oc::wantedKeywords($names, $create, 'information_id', (string)$id);
            if ($wanted && Mcp::has('stores')) Oc::seoDelete('information_id', (string)$id);
            $storeList = array_map('intval', OcDb::col("SELECT `store_id` FROM {information_to_store} WHERE `information_id` = ?", [$id])) ?: [0];
            foreach ($storeList as $s) foreach ($wanted as $lid => $kw) Oc::seoSet('information_id', (string)$id, $kw, $s, $lid);

            OcDb::$pdo->commit();
        } catch (\Throwable $e) {
            OcDb::$pdo->rollBack();
            throw $e;
        }
        Oc::clearCache('information');
        return ['ok' => true, 'id' => $id, 'action' => $create ? 'created' : 'updated', 'languages' => $changed, 'seo_keywords' => Oc::seoGet('information_id', (string)$id),
            'url' => Oc::catalogUrl() . 'index.php?route=information/information&information_id=' . $id];
    }

    public static function delete(int $id): array
    {
        $title = OcDb::val("SELECT `title` FROM {information_description} WHERE `information_id` = ? LIMIT 1", [$id]);
        if (!OcDb::val("SELECT 1 FROM {information} WHERE `information_id` = ?", [$id])) throw new McpFail("Information page $id not found.");
        foreach (['information', 'information_description', 'information_to_store', 'information_to_layout'] as $t) OcDb::delete($t, ['information_id' => $id]);
        Oc::seoDelete('information_id', (string)$id);
        Oc::clearCache('information');
        return ['ok' => true, 'deleted' => $id, 'title' => Mcp::dec((string)$title)];
    }
}

// ===========================================================================
//  Orders, customers, reviews, reports
// ===========================================================================

final class OcOrder
{
    public static function statusName(int $id): string
    {
        return (string)OcDb::val("SELECT `name` FROM {order_status} WHERE `order_status_id` = ? ORDER BY `language_id` = ? DESC LIMIT 1", [$id, Oc::defaultLanguageId()]);
    }

    public static function list(): array
    {
        [$limit, $offset] = Mcp::limits();
        $where = [];
        $p = [];
        if (Mcp::has('status_id')) { $where[] = 'o.`order_status_id` = ?'; $p[] = (int)Mcp::arg('status_id'); }
        elseif (!Mcp::flag(Mcp::arg('include_missing', false))) $where[] = 'o.`order_status_id` > 0';
        if (Mcp::has('customer_id')) { $where[] = 'o.`customer_id` = ?'; $p[] = (int)Mcp::arg('customer_id'); }
        if (Mcp::has('search') && ($s = trim((string)Mcp::arg('search'))) !== '') {
            $where[] = "(CONCAT(o.`firstname`, ' ', o.`lastname`) LIKE ? OR o.`email` LIKE ? OR o.`telephone` LIKE ? OR o.`order_id` = ?)";
            array_push($p, "%$s%", "%$s%", "%$s%", (int)$s);
        }
        if (Mcp::has('date_from')) { $where[] = 'DATE(o.`date_added`) >= ?'; $p[] = (string)Mcp::arg('date_from'); }
        if (Mcp::has('date_to')) { $where[] = 'DATE(o.`date_added`) <= ?'; $p[] = (string)Mcp::arg('date_to'); }

        $w = $where ? 'WHERE ' . implode(' AND ', $where) : '';
        $total = (int)OcDb::val("SELECT COUNT(*) FROM {order} o $w", $p);
        $rows = OcDb::rows("SELECT o.`order_id`, o.`firstname`, o.`lastname`, o.`email`, o.`telephone`, o.`total`, o.`currency_code`, o.`currency_value`, o.`order_status_id`, o.`date_added`, o.`date_modified`
            FROM {order} o $w ORDER BY o.`order_id` DESC LIMIT $limit OFFSET $offset", $p);

        return ['total' => $total, 'offset' => $offset, 'items' => array_map(static fn($r) => [
            'id' => (int)$r['order_id'], 'customer' => trim($r['firstname'] . ' ' . $r['lastname']), 'email' => $r['email'], 'telephone' => $r['telephone'],
            'total' => (float)$r['total'], 'total_text' => Oc::money($r['total'], $r['currency_code'], (float)$r['currency_value']),
            'status_id' => (int)$r['order_status_id'], 'status' => $r['order_status_id'] ? self::statusName((int)$r['order_status_id']) : 'missing (not completed checkout)',
            'date_added' => $r['date_added'],
        ], $rows)];
    }

    public static function get(int $id): array
    {
        $o = OcDb::row("SELECT * FROM {order} WHERE `order_id` = ?", [$id]);
        if (!$o) throw new McpFail("Order $id not found.");
        $cur = $o['currency_code'];
        $rate = (float)$o['currency_value'];

        $products = [];
        foreach (OcDb::rows("SELECT * FROM {order_product} WHERE `order_id` = ?", [$id]) as $r) {
            $products[] = [
                'product_id' => (int)$r['product_id'], 'name' => Mcp::dec($r['name']), 'model' => $r['model'], 'quantity' => (int)$r['quantity'],
                'price' => Oc::money((float)$r['price'] + (float)$r['tax'], $cur, $rate), 'total' => Oc::money(((float)$r['price'] + (float)$r['tax']) * (int)$r['quantity'], $cur, $rate),
                'options' => array_map(static fn($op) => Mcp::dec($op['name']) . ': ' . Mcp::dec($op['value']),
                    OcDb::rows("SELECT `name`, `value` FROM {order_option} WHERE `order_id` = ? AND `order_product_id` = ?", [$id, $r['order_product_id']])),
            ];
        }

        $addr = static function (string $t) use ($o): string {
            $parts = [trim($o[$t . '_firstname'] . ' ' . $o[$t . '_lastname']), $o[$t . '_company'], $o[$t . '_address_1'], $o[$t . '_address_2'],
                trim($o[$t . '_postcode'] . ' ' . $o[$t . '_city']), $o[$t . '_zone'], $o[$t . '_country']];
            return implode(', ', array_filter(array_map('trim', $parts), 'strlen'));
        };
        $method = static function ($v) {
            $j = json_decode((string)$v, true);
            return is_array($j) ? ($j['name'] ?? $j['code'] ?? $v) : $v;
        };

        return [
            'id' => $id,
            'status_id' => (int)$o['order_status_id'],
            'status' => $o['order_status_id'] ? self::statusName((int)$o['order_status_id']) : 'missing',
            'date_added' => $o['date_added'], 'date_modified' => $o['date_modified'],
            'store' => $o['store_name'],
            'customer' => ['id' => (int)$o['customer_id'], 'name' => trim($o['firstname'] . ' ' . $o['lastname']), 'email' => $o['email'], 'telephone' => $o['telephone']],
            'payment' => ['method' => $method($o['payment_method']), 'address' => $addr('payment')],
            'shipping' => ['method' => $method($o['shipping_method']), 'address' => $addr('shipping')],
            'comment' => $o['comment'],
            'products' => $products,
            'totals' => array_map(static fn($t) => ['code' => $t['code'], 'title' => Mcp::dec($t['title']), 'value' => Oc::money($t['value'], $cur, $rate)],
                OcDb::rows("SELECT `code`, `title`, `value` FROM {order_total} WHERE `order_id` = ? ORDER BY `sort_order`", [$id])),
            'total' => Oc::money($o['total'], $cur, $rate),
            'currency' => $cur,
            'history' => array_map(static fn($h) => ['date' => $h['date_added'], 'status' => self::statusName((int)$h['order_status_id']), 'notify' => (bool)$h['notify'], 'comment' => $h['comment']],
                OcDb::rows("SELECT * FROM {order_history} WHERE `order_id` = ? ORDER BY `date_added`, `order_history_id`", [$id])),
            'ip' => $o['ip'],
        ];
    }

    public static function setStatus(): array
    {
        $id = (int)Mcp::need('order_id');
        $status = (int)Mcp::need('status_id');
        $o = OcDb::row("SELECT `order_status_id` FROM {order} WHERE `order_id` = ?", [$id]);
        if (!$o) throw new McpFail("Order $id not found.");
        $name = self::statusName($status);
        if ($name === '') throw new McpFail("Order status $status not found (see shop_info).");

        $now = date('Y-m-d H:i:s');
        OcDb::update('order', ['order_status_id' => $status, 'date_modified' => $now], ['order_id' => $id]);
        OcDb::insert('order_history', ['order_id' => $id, 'order_status_id' => $status, 'notify' => Mcp::flag(Mcp::arg('notify', false)), 'comment' => (string)Mcp::arg('comment', ''), 'date_added' => $now]);

        return ['ok' => true, 'order_id' => $id, 'from' => self::statusName((int)$o['order_status_id']), 'to' => $name,
            'note' => 'Status and history saved. No e-mail is sent and stock / reward events of OpenCart are not run: change the status in the admin panel when the customer must be notified.'];
    }
}

final class OcCustomer
{
    public static function list(): array
    {
        [$limit, $offset] = Mcp::limits();
        $where = [];
        $p = [];
        if (Mcp::has('search') && ($s = trim((string)Mcp::arg('search'))) !== '') {
            $where[] = "(CONCAT(c.`firstname`, ' ', c.`lastname`) LIKE ? OR c.`email` LIKE ? OR c.`telephone` LIKE ? OR c.`customer_id` = ?)";
            array_push($p, "%$s%", "%$s%", "%$s%", (int)$s);
        }
        if (Mcp::has('status')) { $where[] = 'c.`status` = ?'; $p[] = Mcp::flag(Mcp::arg('status')); }
        if (Mcp::has('customer_group_id')) { $where[] = 'c.`customer_group_id` = ?'; $p[] = (int)Mcp::arg('customer_group_id'); }
        $w = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        $total = (int)OcDb::val("SELECT COUNT(*) FROM {customer} c $w", $p);
        $rows = OcDb::rows("SELECT c.`customer_id`, c.`firstname`, c.`lastname`, c.`email`, c.`telephone`, c.`customer_group_id`, c.`status`, c.`newsletter`, c.`date_added`,
            (SELECT COUNT(*) FROM {order} o WHERE o.`customer_id` = c.`customer_id` AND o.`order_status_id` > 0) AS orders,
            (SELECT SUM(o.`total`) FROM {order} o WHERE o.`customer_id` = c.`customer_id` AND o.`order_status_id` > 0) AS spent
            FROM {customer} c $w ORDER BY c.`customer_id` DESC LIMIT $limit OFFSET $offset", $p);

        return ['total' => $total, 'offset' => $offset, 'items' => array_map(static fn($r) => [
            'id' => (int)$r['customer_id'], 'name' => trim($r['firstname'] . ' ' . $r['lastname']), 'email' => $r['email'], 'telephone' => $r['telephone'],
            'customer_group_id' => (int)$r['customer_group_id'], 'status' => (bool)$r['status'], 'newsletter' => (bool)$r['newsletter'],
            'orders' => (int)$r['orders'], 'spent' => Oc::money((float)$r['spent']), 'date_added' => $r['date_added'],
        ], $rows)];
    }
}

final class OcReview
{
    public static function list(): array
    {
        [$limit, $offset] = Mcp::limits();
        $where = [];
        $p = [Oc::defaultLanguageId()];
        if (Mcp::has('status')) { $where[] = 'r.`status` = ?'; $p[] = Mcp::flag(Mcp::arg('status')); }
        if (Mcp::has('product_id')) { $where[] = 'r.`product_id` = ?'; $p[] = (int)Mcp::arg('product_id'); }
        $w = $where ? 'WHERE ' . implode(' AND ', $where) : '';
        $rows = OcDb::rows("SELECT r.*, pd.`name` FROM {review} r LEFT JOIN {product_description} pd ON (pd.`product_id` = r.`product_id` AND pd.`language_id` = ?) $w ORDER BY r.`date_added` DESC LIMIT $limit OFFSET $offset", $p);
        array_shift($p);
        $total = (int)OcDb::val("SELECT COUNT(*) FROM {review} r $w", $p);
        return ['total' => $total, 'offset' => $offset, 'items' => array_map(static fn($r) => [
            'id' => (int)$r['review_id'], 'product_id' => (int)$r['product_id'], 'product' => Mcp::dec((string)$r['name']), 'author' => Mcp::dec($r['author']),
            'rating' => (int)$r['rating'], 'text' => Mcp::dec($r['text']), 'status' => (bool)$r['status'], 'date_added' => $r['date_added'],
        ], $rows)];
    }

    private static function refreshRating(int $productId): void
    {
        if (OcDb::hasCol('product', 'rating')) {
            $avg = (float)OcDb::val("SELECT AVG(`rating`) FROM {review} WHERE `product_id` = ? AND `status` = 1", [$productId]);
            OcDb::update('product', ['rating' => $avg], ['product_id' => $productId]);
        }
        Oc::clearCache('product');
    }

    public static function setStatus(int $id, int $status): array
    {
        $pid = OcDb::val("SELECT `product_id` FROM {review} WHERE `review_id` = ?", [$id]);
        if ($pid === null) throw new McpFail("Review $id not found.");
        OcDb::update('review', ['status' => $status, 'date_modified' => date('Y-m-d H:i:s')], ['review_id' => $id]);
        self::refreshRating((int)$pid);
        return ['ok' => true, 'id' => $id, 'status' => (bool)$status];
    }

    public static function delete(int $id): array
    {
        $pid = OcDb::val("SELECT `product_id` FROM {review} WHERE `review_id` = ?", [$id]);
        if ($pid === null) throw new McpFail("Review $id not found.");
        OcDb::delete('review', ['review_id' => $id]);
        self::refreshRating((int)$pid);
        return ['ok' => true, 'deleted' => $id];
    }
}

final class OcReport
{
    public static function sales(): array
    {
        $from = (string)Mcp::arg('date_from', date('Y-m-01'));
        $to = (string)Mcp::arg('date_to', date('Y-m-d'));
        $group = Mcp::arg('group', 'day');
        $fmt = ['day' => '%Y-%m-%d', 'week' => '%x-W%v', 'month' => '%Y-%m', 'year' => '%Y'][$group] ?? '%Y-%m-%d';

        $statuses = Mcp::has('status_ids') ? Mcp::ids(Mcp::arg('status_ids')) : array_map('intval', (array)Oc::setting('config_complete_status', []));
        $statuses = array_merge($statuses, Mcp::has('status_ids') ? [] : array_map('intval', (array)Oc::setting('config_processing_status', [])));
        $statuses = array_values(array_unique(array_filter($statuses)));
        $st = $statuses ? 'o.`order_status_id` IN (' . OcDb::in($statuses) . ')' : 'o.`order_status_id` > 0';

        $rows = OcDb::rows("SELECT DATE_FORMAT(o.`date_added`, '$fmt') AS period, COUNT(*) AS orders, SUM(o.`total`) AS revenue
            FROM {order} o WHERE $st AND DATE(o.`date_added`) BETWEEN ? AND ? GROUP BY period ORDER BY period", [$from, $to]);
        $items = [];
        foreach (OcDb::rows("SELECT DATE_FORMAT(o.`date_added`, '$fmt') AS period, SUM(op.`quantity`) AS items FROM {order_product} op JOIN {order} o ON o.`order_id` = op.`order_id`
            WHERE $st AND DATE(o.`date_added`) BETWEEN ? AND ? GROUP BY period", [$from, $to]) as $r) $items[$r['period']] = (int)$r['items'];
        foreach ($rows as &$r) $r['items'] = $items[$r['period']] ?? 0;
        unset($r);

        $top = OcDb::rows("SELECT op.`product_id`, op.`name`, SUM(op.`quantity`) AS qty, SUM(op.`total` + op.`tax` * op.`quantity`) AS revenue
            FROM {order_product} op JOIN {order} o ON o.`order_id` = op.`order_id` WHERE $st AND DATE(o.`date_added`) BETWEEN ? AND ?
            GROUP BY op.`product_id`, op.`name` ORDER BY qty DESC LIMIT 10", [$from, $to]);

        $sumOrders = array_sum(array_column($rows, 'orders'));
        $sumRevenue = array_sum(array_column($rows, 'revenue'));
        return [
            'period' => "$from .. $to", 'currency' => Oc::setting('config_currency'),
            'statuses' => array_map(static fn($s) => OcOrder::statusName($s), $statuses),
            'orders' => (int)$sumOrders, 'revenue' => Oc::money($sumRevenue), 'average_order' => $sumOrders ? Oc::money($sumRevenue / $sumOrders) : null,
            'by_' . $group => array_map(static fn($r) => ['period' => $r['period'], 'orders' => (int)$r['orders'], 'items' => (int)$r['items'], 'revenue' => round((float)$r['revenue'], 2)], $rows),
            'top_products' => array_map(static fn($r) => ['product_id' => (int)$r['product_id'], 'name' => Mcp::dec($r['name']), 'quantity' => (int)$r['qty'], 'revenue' => round((float)$r['revenue'], 2)], $top),
        ];
    }

    public static function sql(): array
    {
        $sql = trim((string)Mcp::need('query'));
        $sql = rtrim($sql, "; \n\t");
        if (strpos($sql, ';') !== false) throw new McpFail('Only one statement is allowed.');
        if (!preg_match('/^(SELECT|SHOW|DESCRIBE|DESC|EXPLAIN|WITH)\b/i', $sql)) throw new McpFail('Only SELECT / SHOW / DESCRIBE / EXPLAIN queries are allowed.');
        if (preg_match('/\b(INTO\s+(OUTFILE|DUMPFILE)|LOAD_FILE|SLEEP|BENCHMARK|GET_LOCK|FOR\s+UPDATE)\b/i', $sql)) throw new McpFail('This query is not allowed.');

        $max = max(1, min(500, (int)Mcp::arg('limit', 100)));
        OcDb::$pdo->exec('START TRANSACTION READ ONLY');
        try {
            $st = OcDb::$pdo->query(OcDb::sql($sql));
            $rows = [];
            while (count($rows) < $max && ($r = $st->fetch())) $rows[] = $r;
            $more = (bool)$st->fetch();
        } finally {
            OcDb::$pdo->exec('ROLLBACK');
        }
        return ['rows' => $rows, 'count' => count($rows), 'truncated' => $more];
    }
}

// ===========================================================================
//  Tools
// ===========================================================================

final class OcTools
{
    public const WRITE = ['product_add', 'product_edit', 'product_delete', 'category_add', 'category_edit', 'category_delete', 'manufacturer_add', 'manufacturer_edit',
        'manufacturer_delete', 'information_add', 'information_edit', 'information_delete', 'order_status_set', 'review_status', 'review_delete', 'image_upload', 'cache_clear'];

    public static function list(): array
    {
        $obj = static fn(array $props, array $required = []) => ['type' => 'object', 'properties' => $props ?: new stdClass, 'required' => $required];
        $s = static fn(string $d) => ['type' => 'string', 'description' => $d];
        $i = static fn(string $d) => ['type' => 'integer', 'description' => $d];
        $n = static fn(string $d) => ['type' => 'number', 'description' => $d];
        $b = static fn(string $d) => ['type' => 'boolean', 'description' => $d];
        $ints = static fn(string $d) => ['type' => 'array', 'items' => ['type' => 'integer'], 'description' => $d];

        $page = ['limit' => $i('Rows to return (default 20, max 200).'), 'offset' => $i('Rows to skip.')];
        $lang = ['language' => $s('Language code (e.g. "uk-ua", "ru-ru", "en-gb"); default is the shop language. For edits: which language the top-level text fields change, "all" = every language.')];
        $descs = ['descriptions' => ['type' => 'object', 'description' => 'Texts per language: {"uk-ua": {"name": "...", "description": "..."}, "ru-ru": {...}}. Overrides top-level text fields for that language.']];
        $seo = [
            'seo_keyword' => $s('SEO URL keyword (latin, e.g. "iphone-15-pro"). Generated from the name on create when omitted.'),
            'seo_keywords' => ['type' => 'object', 'description' => 'SEO keywords per language: {"uk-ua": "...", "ru-ru": "..."}.'],
        ];
        $stores = ['stores' => $ints('Store ids (0 = default store). Default on create: [0].')];

        $product = [
            'name' => $s('Product name.'),
            'description' => $s('Description, HTML.'),
            'tag' => $s('Tags, comma separated.'),
            'meta_title' => $s('Meta title (defaults to the name).'),
            'meta_h1' => $s('H1 (ocStore and similar forks only).'),
            'meta_description' => $s('Meta description.'),
            'meta_keyword' => $s('Meta keywords.'),
            'model' => $s('Model / article (required on create, 1-64 chars).'),
            'sku' => $s('SKU.'), 'upc' => $s('UPC.'), 'ean' => $s('EAN.'), 'jan' => $s('JAN.'), 'isbn' => $s('ISBN.'), 'mpn' => $s('MPN.'),
            'location' => $s('Location.'),
            'price' => $n('Price in the shop default currency.'),
            'special_price' => $n('Special (sale) price for the default customer group; 0 removes it.'),
            'special_start' => $s('Special start date YYYY-MM-DD.'),
            'special_end' => $s('Special end date YYYY-MM-DD.'),
            'quantity' => $i('Stock quantity (default 1 on create).'),
            'minimum' => $i('Minimum order quantity.'),
            'subtract' => $b('Subtract stock on order.'),
            'stock_status_id' => $i('Status shown when out of stock (see shop_info).'),
            'status' => $b('Enabled.'),
            'image' => $s('Main image: an http(s) URL (downloaded into the shop) or a path relative to image/ ("catalog/demo/x.jpg").'),
            'images' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Additional images (URLs or paths). Replaces the current list.'],
            'categories' => $ints('Category ids (see category_list). Replaces the current list.'),
            'manufacturer_id' => $i('Manufacturer id.'),
            'manufacturer' => $s('Manufacturer name: found or created automatically.'),
            'attributes' => ['type' => 'object', 'description' => 'Attributes: {"attribute id or name": "value"} (value may be {"uk-ua": "...", "ru-ru": "..."}). Unknown names are created. Empty value removes the attribute.'],
            'attribute_group' => $s('Group for attributes created by name (created when missing).'),
            'replace_attributes' => $b('Remove all other attributes of the product.'),
            'tax_class_id' => $i('Tax class id (0 = none).'),
            'weight' => $n('Weight.'), 'weight_class_id' => $i('Weight class id.'),
            'length' => $n('Length.'), 'width' => $n('Width.'), 'height' => $n('Height.'), 'length_class_id' => $i('Length class id.'),
            'shipping' => $b('Requires shipping.'),
            'points' => $i('Reward points needed to buy.'),
            'date_available' => $s('Date available YYYY-MM-DD.'),
            'sort_order' => $i('Sort order.'),
        ] + $seo + $stores + $descs + $lang;

        $category = [
            'name' => $s('Category name.'),
            'description' => $s('Description, HTML.'),
            'meta_title' => $s('Meta title (defaults to the name).'),
            'meta_h1' => $s('H1 (ocStore and similar forks only).'),
            'meta_description' => $s('Meta description.'),
            'meta_keyword' => $s('Meta keywords.'),
            'parent_id' => $i('Parent category id, 0 = root.'),
            'image' => $s('Image: URL or path relative to image/.'),
            'top' => $b('Show in the top menu (OpenCart 3; in 4.x the menu is configured separately).'),
            'column' => $i('Columns in the top menu dropdown (OpenCart 3).'),
            'sort_order' => $i('Sort order.'),
            'status' => $b('Enabled.'),
        ] + $seo + $stores + $descs + $lang;

        $information = [
            'title' => $s('Page title.'),
            'description' => $s('Content, HTML.'),
            'meta_title' => $s('Meta title (defaults to the title).'),
            'meta_h1' => $s('H1 (forks only).'),
            'meta_description' => $s('Meta description.'),
            'meta_keyword' => $s('Meta keywords.'),
            'bottom' => $b('Show link in the footer (OpenCart 3).'),
            'sort_order' => $i('Sort order.'),
            'status' => $b('Enabled.'),
        ] + $seo + $stores + $descs + $lang;

        $manufacturer = ['name' => $s('Manufacturer name.'), 'image' => $s('Logo: URL or path relative to image/.'), 'sort_order' => $i('Sort order.'),
            'description' => $s('Description, HTML (ocStore and other forks with manufacturer descriptions).'),
            'meta_title' => $s('Meta title (forks only).'), 'meta_h1' => $s('H1 (forks only).'),
            'meta_description' => $s('Meta description (forks only).'), 'meta_keyword' => $s('Meta keywords (forks only).'),
        ] + $seo + $stores + $descs + $lang;

        $tools = [
            ['name' => 'shop_info', 'description' => 'Shop summary: OpenCart version, URL, languages, currency, stores, counters and the ids needed by other tools (order statuses, stock statuses, tax / weight / length classes, customer groups). Call it first.', 'inputSchema' => $obj([])],

            ['name' => 'product_list', 'description' => 'Find products. Returns id, name, model, price, special price, quantity, status.', 'inputSchema' => $obj($page + [
                'search' => $s('Part of the name or model, exact SKU, or product id.'),
                'category_id' => $i('Only products of this category.'),
                'subcategories' => $b('With category_id: include subcategories (default true).'),
                'manufacturer_id' => $i('Only this manufacturer.'),
                'status' => $b('true = enabled only, false = disabled only.'),
                'quantity_max' => $i('Only products with quantity <= this value (low stock).'),
                'price_min' => $n('Minimum price.'), 'price_max' => $n('Maximum price.'),
                'special_only' => $b('Only products with an active special price.'),
                'sort' => ['type' => 'string', 'enum' => ['newest', 'oldest', 'name', 'price', 'price_desc', 'quantity', 'modified', 'id'], 'description' => 'Sort order (default newest).'],
            ] + $lang)],
            ['name' => 'product_get', 'description' => 'Full product card: texts in every language, codes, prices, specials, discounts, stock, images, categories, attributes, options, SEO keywords.', 'inputSchema' => $obj(['id' => $i('Product id.')], ['id'])],
            ['name' => 'product_add', 'description' => 'Create a product. Required: name and model. Top-level texts go to every language unless "descriptions" gives a language its own text. Images given as URLs are downloaded. SEO keyword is generated from the name when omitted.', 'inputSchema' => $obj($product, ['name', 'model'])],
            ['name' => 'product_edit', 'description' => 'Edit a product: only the passed fields change. Lists (categories, images, stores) are replaced when passed. Text fields change the language from "language" (default shop language).', 'inputSchema' => $obj(['id' => $i('Product id.')] + $product, ['id'])],
            ['name' => 'product_delete', 'description' => 'Delete a product with its texts, images links, specials, attributes, reviews and SEO URLs. Orders keep their copy.', 'inputSchema' => $obj(['id' => $i('Product id.')], ['id'])],

            ['name' => 'category_list', 'description' => 'All categories as a tree (path "Parent > Child"), with product counts.', 'inputSchema' => $obj(['full' => $b('Include image, meta title, short description and SEO keywords.')] + $lang)],
            ['name' => 'category_get', 'description' => 'One category with texts in every language and SEO keywords.', 'inputSchema' => $obj(['id' => $i('Category id.')], ['id'])],
            ['name' => 'category_add', 'description' => 'Create a category (category paths and SEO URLs are built like the admin does).', 'inputSchema' => $obj($category, ['name'])],
            ['name' => 'category_edit', 'description' => 'Edit a category: only the passed fields change. Changing parent_id moves the whole branch.', 'inputSchema' => $obj(['id' => $i('Category id.')] + $category, ['id'])],
            ['name' => 'category_delete', 'description' => 'Delete a category with all its subcategories. Products are not deleted.', 'inputSchema' => $obj(['id' => $i('Category id.')], ['id'])],

            ['name' => 'manufacturer_list', 'description' => 'List manufacturers (brands) with product counts.', 'inputSchema' => $obj([])],
            ['name' => 'manufacturer_add', 'description' => 'Create a manufacturer.', 'inputSchema' => $obj($manufacturer, ['name'])],
            ['name' => 'manufacturer_edit', 'description' => 'Edit a manufacturer.', 'inputSchema' => $obj(['id' => $i('Manufacturer id.')] + $manufacturer, ['id'])],
            ['name' => 'manufacturer_delete', 'description' => 'Delete a manufacturer.', 'inputSchema' => $obj(['id' => $i('Manufacturer id.'), 'force' => $b('Delete even when products use it.')], ['id'])],

            ['name' => 'attribute_list', 'description' => 'Product attributes grouped by attribute group.', 'inputSchema' => $obj($lang)],

            ['name' => 'information_list', 'description' => 'List information pages (About us, Delivery, Terms…).', 'inputSchema' => $obj($lang)],
            ['name' => 'information_get', 'description' => 'One information page with texts in every language.', 'inputSchema' => $obj(['id' => $i('Page id.')], ['id'])],
            ['name' => 'information_add', 'description' => 'Create an information page.', 'inputSchema' => $obj($information, ['title'])],
            ['name' => 'information_edit', 'description' => 'Edit an information page: only the passed fields change.', 'inputSchema' => $obj(['id' => $i('Page id.')] + $information, ['id'])],
            ['name' => 'information_delete', 'description' => 'Delete an information page.', 'inputSchema' => $obj(['id' => $i('Page id.')], ['id'])],

            ['name' => 'order_list', 'description' => 'List orders (newest first). Orders that never finished checkout (status 0) are hidden unless include_missing.', 'inputSchema' => $obj($page + [
                'status_id' => $i('Only this order status (see shop_info).'),
                'customer_id' => $i('Only this customer.'),
                'search' => $s('Customer name, e-mail, phone or order id.'),
                'date_from' => $s('From date YYYY-MM-DD.'), 'date_to' => $s('To date YYYY-MM-DD.'),
                'include_missing' => $b('Also show unfinished (missing) orders.'),
            ])],
            ['name' => 'order_get', 'description' => 'Full order: customer, addresses, payment / shipping methods, products with options, totals and status history.', 'inputSchema' => $obj(['id' => $i('Order id.')], ['id'])],
            ['name' => 'order_status_set', 'description' => 'Change the order status and add a history record. No e-mail is sent to the customer.', 'inputSchema' => $obj([
                'order_id' => $i('Order id.'), 'status_id' => $i('New order status id (see shop_info).'),
                'comment' => $s('Comment for the history.'), 'notify' => $b('Mark the history record as "customer notified" (no e-mail is actually sent).'),
            ], ['order_id', 'status_id'])],

            ['name' => 'customer_list', 'description' => 'List customers with their order count and spent amount.', 'inputSchema' => $obj($page + [
                'search' => $s('Name, e-mail, phone or id.'), 'status' => $b('Enabled / disabled.'), 'customer_group_id' => $i('Customer group id.'),
            ])],

            ['name' => 'review_list', 'description' => 'List product reviews (newest first).', 'inputSchema' => $obj($page + ['status' => $b('true = published, false = waiting for moderation.'), 'product_id' => $i('Only this product.')])],
            ['name' => 'review_status', 'description' => 'Publish or hide a review.', 'inputSchema' => $obj(['id' => $i('Review id.'), 'status' => $b('true = publish, false = hide.')], ['id', 'status'])],
            ['name' => 'review_delete', 'description' => 'Delete a review.', 'inputSchema' => $obj(['id' => $i('Review id.')], ['id'])],

            ['name' => 'sales_report', 'description' => 'Sales summary for a period: orders, items, revenue by day / week / month and the top-10 products. By default counts orders in the "processing" and "complete" statuses from the shop settings.', 'inputSchema' => $obj([
                'date_from' => $s('From YYYY-MM-DD (default: first day of this month).'), 'date_to' => $s('To YYYY-MM-DD (default: today).'),
                'group' => ['type' => 'string', 'enum' => ['day', 'week', 'month', 'year'], 'description' => 'Grouping (default day).'],
                'status_ids' => $ints('Count only these order statuses.'),
            ])],

            ['name' => 'image_upload', 'description' => 'Download an image from a URL into image/' . MCP_IMAGE_DIR . ' and return its path for image fields. (Product / category tools also accept URLs directly.)', 'inputSchema' => $obj([
                'url' => $s('Image URL (JPEG, PNG, GIF, WEBP).'), 'name' => $s('File name base (latin is generated from any text).'),
            ], ['url'])],
            ['name' => 'cache_clear', 'description' => 'Clear the OpenCart data cache (system/storage/cache). Write tools already clear what they touch.', 'inputSchema' => $obj([])],
        ];

        if (MCP_ALLOW_SQL) {
            $tools[] = ['name' => 'sql_select', 'description' => 'Run one read-only SQL query (SELECT / SHOW / DESCRIBE / EXPLAIN) for anything the other tools do not cover. Write table names as {product}, {order}, {customer}… — the table prefix is added automatically. Runs in a read-only transaction.', 'inputSchema' => $obj([
                'query' => $s('SQL query, e.g. SELECT COUNT(*) FROM {product} WHERE status = 1'), 'limit' => $i('Max rows (default 100, max 500).'),
            ], ['query'])];
        }

        if (MCP_READONLY) $tools = array_values(array_filter($tools, static fn($t) => !in_array($t['name'], self::WRITE, true)));
        return $tools;
    }

    public static function info(): array
    {
        $lid = Oc::defaultLanguageId();
        $names = static fn(string $table, string $id, string $name = 'name') => array_map(static fn($r) => ['id' => (int)$r[$id], 'name' => Mcp::dec((string)$r[$name])],
            OcDb::rows("SELECT `$id`, `$name` FROM " . OcDb::t($table) . (OcDb::hasCol($table, 'language_id') ? " WHERE `language_id` = $lid" : '') . " ORDER BY `$id`"));

        return [
            'opencart' => Oc::$version, 'schema' => Oc::$v4 ? '4.x' : '3.x',
            'name' => Oc::setting('config_name'), 'url' => Oc::catalogUrl(),
            'default_language' => Oc::langCode($lid),
            'languages' => array_values(array_map(static fn($l) => ['id' => (int)$l['language_id'], 'code' => $l['code'], 'name' => $l['name'], 'enabled' => (bool)$l['status']], Oc::languages())),
            'currency' => Oc::setting('config_currency'),
            'currencies' => OcDb::rows("SELECT `code`, `title`, `value`, `status` FROM {currency}"),
            'stores' => array_merge([['id' => 0, 'name' => Oc::setting('config_name'), 'url' => Oc::catalogUrl()]],
                array_map(static fn($r) => ['id' => (int)$r['store_id'], 'name' => $r['name'], 'url' => $r['url']], OcDb::rows("SELECT `store_id`, `name`, `url` FROM {store}"))),
            'seo_urls' => (bool)Oc::setting('config_seo_url'),
            'counters' => [
                'products' => (int)OcDb::val("SELECT COUNT(*) FROM {product}"),
                'products_enabled' => (int)OcDb::val("SELECT COUNT(*) FROM {product} WHERE `status` = 1"),
                'out_of_stock' => (int)OcDb::val("SELECT COUNT(*) FROM {product} WHERE `quantity` <= 0"),
                'categories' => (int)OcDb::val("SELECT COUNT(*) FROM {category}"),
                'manufacturers' => (int)OcDb::val("SELECT COUNT(*) FROM {manufacturer}"),
                'orders' => (int)OcDb::val("SELECT COUNT(*) FROM {order} WHERE `order_status_id` > 0"),
                'customers' => (int)OcDb::val("SELECT COUNT(*) FROM {customer}"),
                'reviews_pending' => (int)OcDb::val("SELECT COUNT(*) FROM {review} WHERE `status` = 0"),
            ],
            'order_statuses' => $names('order_status', 'order_status_id'),
            'stock_statuses' => $names('stock_status', 'stock_status_id'),
            'customer_groups' => $names('customer_group_description', 'customer_group_id'),
            'default_customer_group_id' => Oc::customerGroupId(),
            'tax_classes' => $names('tax_class', 'tax_class_id', 'title'),
            'weight_classes' => $names('weight_class_description', 'weight_class_id', 'title'),
            'length_classes' => $names('length_class_description', 'length_class_id', 'title'),
            'image_folder' => 'image/' . MCP_IMAGE_DIR,
            'readonly' => MCP_READONLY,
        ];
    }

    public static function call(string $tool): array
    {
        if (MCP_READONLY && in_array($tool, self::WRITE, true)) throw new McpFail('The server is in read-only mode (MCP_READONLY).');
        $id = static fn() => (int)Mcp::need('id');

        switch ($tool) {
            case 'shop_info':          return self::info();
            case 'product_list':       return OcProduct::list();
            case 'product_get':        return OcProduct::get($id());
            case 'product_add':        Mcp::need('name'); return OcProduct::save(null);
            case 'product_edit':       return OcProduct::save($id());
            case 'product_delete':     return OcProduct::delete($id());
            case 'category_list':      return OcCategory::list();
            case 'category_get':       return OcCategory::get($id());
            case 'category_add':       Mcp::need('name'); return OcCategory::save(null);
            case 'category_edit':      return OcCategory::save($id());
            case 'category_delete':    return OcCategory::delete($id());
            case 'manufacturer_list':  return OcManufacturer::list();
            case 'manufacturer_add':   return OcManufacturer::save(null);
            case 'manufacturer_edit':  return OcManufacturer::save($id());
            case 'manufacturer_delete': return OcManufacturer::delete($id());
            case 'attribute_list':     return OcAttribute::list();
            case 'information_list':   return OcInformation::list();
            case 'information_get':    return OcInformation::get($id());
            case 'information_add':    Mcp::need('title'); return OcInformation::save(null);
            case 'information_edit':   return OcInformation::save($id());
            case 'information_delete': return OcInformation::delete($id());
            case 'order_list':         return OcOrder::list();
            case 'order_get':          return OcOrder::get($id());
            case 'order_status_set':   return OcOrder::setStatus();
            case 'customer_list':      return OcCustomer::list();
            case 'review_list':        return OcReview::list();
            case 'review_status':      return OcReview::setStatus($id(), Mcp::flag(Mcp::need('status')));
            case 'review_delete':      return OcReview::delete($id());
            case 'sales_report':       return OcReport::sales();
            case 'sql_select':         if (!MCP_ALLOW_SQL) throw new McpFail('sql_select is disabled.'); return OcReport::sql();
            case 'image_upload':
                $path = Oc::download((string)Mcp::need('url'), (string)Mcp::arg('name', ''));
                $size = @getimagesize(DIR_IMAGE . $path);
                return ['ok' => true, 'path' => $path, 'url' => Oc::imageUrl($path), 'width' => $size[0] ?? null, 'height' => $size[1] ?? null, 'use' => 'Pass "path" as image / images in product_add, product_edit, category_add…'];
            case 'cache_clear':        return ['ok' => true, 'deleted_files' => Oc::clearCache()];
        }
        throw new McpFail("Unknown tool: $tool");
    }
}

// ===========================================================================
//  HTTP / JSON-RPC transport
// ===========================================================================

ini_set('display_errors', '0');
error_reporting(E_ALL);
ob_start();

$mcp_key = '';
$mcp_auth = (string)($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
if ($mcp_auth === '' && function_exists('getallheaders')) {
    // Apache + mod_php keeps a Bearer Authorization header out of $_SERVER, but getallheaders() has it
    foreach ((array)getallheaders() as $mcp_h => $mcp_v) if (strcasecmp((string)$mcp_h, 'Authorization') === 0) $mcp_auth = (string)$mcp_v;
}
if (preg_match('/^Bearer\s+(\S+)$/i', $mcp_auth, $mcp_m)) $mcp_key = $mcp_m[1];
if ($mcp_key === '') $mcp_key = (string)($_SERVER['HTTP_X_MCP_KEY'] ?? '');
if ($mcp_key === '' && isset($_GET['key']) && is_string($_GET['key'])) $mcp_key = $_GET['key'];

if (strlen(MCP_KEY) < 16) {
    Mcp::error(-32001, 'Server disabled: set MCP_KEY (at least 16 characters) at the top of ' . basename(__FILE__) . '.', 503);
    exit;
}
if (!hash_equals(MCP_KEY, $mcp_key)) {
    Mcp::error(-32001, 'Unauthorized: wrong or missing key.', 401);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    Mcp::send(405, null);   // no server-initiated stream, no sessions
    exit;
}

$mcp_request = json_decode((string)file_get_contents('php://input'), true);
if (is_array($mcp_request) && array_is_list($mcp_request) && count($mcp_request) === 1) $mcp_request = $mcp_request[0];

if (!is_array($mcp_request) || array_is_list($mcp_request)) {
    Mcp::error(is_array($mcp_request) ? -32600 : -32700, is_array($mcp_request) ? 'Batch requests are not supported.' : 'Parse error.', 400);
    exit;
}

$mcp_method = $mcp_request['method'] ?? null;
if (!array_key_exists('id', $mcp_request) || !is_string($mcp_method)) {
    Mcp::send(202, null);   // notification or a response from the client
    exit;
}

Mcp::$id = $mcp_request['id'];
$mcp_params = is_array($mcp_request['params'] ?? null) ? $mcp_request['params'] : [];

try {
    if (!is_file(__DIR__ . '/config.php')) throw new McpFail('config.php not found: put ' . basename(__FILE__) . ' into the OpenCart root folder.');
    require_once __DIR__ . '/config.php';
    if (!defined('DB_HOSTNAME') || !defined('DB_PREFIX') || !defined('DIR_IMAGE')) throw new McpFail('config.php does not look like an OpenCart catalog config.');
    OcDb::connect();
    Oc::init();
} catch (\Throwable $e) {
    Mcp::error(-32603, 'OpenCart is not available: ' . $e->getMessage(), 500);
    exit;
}

switch ($mcp_method) {
    case 'initialize':
        $v = $mcp_params['protocolVersion'] ?? '';
        Mcp::result([
            'protocolVersion' => in_array($v, MCP_PROTOCOLS, true) ? $v : MCP_PROTOCOLS[0],
            'capabilities' => ['tools' => new stdClass],
            'serverInfo' => ['name' => MCP_NAME, 'version' => MCP_VERSION],
            'instructions' => 'Manages an OpenCart ' . Oc::$version . ' shop: products, categories, manufacturers, attributes, information pages, orders, customers, reviews, sales reports. '
                . 'Start with shop_info (languages, ids of statuses and classes) and category_list. Texts are multilingual: top-level fields apply to the shop language, "descriptions" sets each language separately. '
                . 'Images can be passed as URLs, they are downloaded into the shop. Prices are in the shop default currency.' . (MCP_READONLY ? ' The server is read-only.' : ''),
        ]);
        exit;

    case 'ping':
        Mcp::result(new stdClass);
        exit;

    case 'tools/list':
        Mcp::result(['tools' => OcTools::list()]);
        exit;

    case 'resources/list':
        Mcp::result(['resources' => []]);
        exit;

    case 'prompts/list':
        Mcp::result(['prompts' => []]);
        exit;

    case 'tools/call':
        $tool = is_string($mcp_params['name'] ?? null) ? $mcp_params['name'] : '';
        if (!in_array($tool, array_column(OcTools::list(), 'name'), true)) {
            Mcp::error(-32602, 'Unknown tool: ' . $tool);
            exit;
        }
        Mcp::$args = is_array($mcp_params['arguments'] ?? null) ? $mcp_params['arguments'] : [];
        try {
            Mcp::toolResult(OcTools::call($tool));
        } catch (McpFail $e) {
            Mcp::toolResult(['ok' => false, 'error' => $e->getMessage()], true);
        } catch (\PDOException $e) {
            Mcp::toolResult(['ok' => false, 'error' => 'Database: ' . $e->getMessage()], true);
        } catch (\Throwable $e) {
            Mcp::toolResult(['ok' => false, 'error' => get_class($e) . ': ' . $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')'], true);
        }
        exit;

    default:
        Mcp::error(-32601, 'Method not found: ' . $mcp_method);
        exit;
}
