<?php
/**
 * 外部ライター互換テスト。
 *
 * REST API や draft プラグインのように「素の page_write()」で #md ページを
 * 書き込む経路があっても、描画が正しく・保存で自己修復されることを検証する。
 *
 * 実行方法（使い捨ての PukiWiki 1.5.4 コピーに plugin/ を被せて実行）:
 *   cp -R /path/to/pukiwiki-1.5.4 /tmp/pkwk-md
 *   cp -R plugin/* /tmp/pkwk-md/plugin/
 *   PKWK_ROOT=/tmp/pkwk-md php test/external_writer_test.php
 *
 * License: GPL v2 or (at your option) any later version
 */
declare(strict_types=0);

error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);

$pkwk_root = getenv('PKWK_ROOT');
if (!$pkwk_root || !is_file($pkwk_root . '/pukiwiki.ini.php')) {
    fwrite(STDERR, "PKWK_ROOT に使い捨ての PukiWiki コピーを指定してください。\n");
    exit(2);
}

// ---- PukiWiki 本体を「Main の直前まで」ロード ------------------------------
$_SERVER += [
    'SERVER_NAME' => 'localhost', 'SERVER_PORT' => '80',
    'REQUEST_URI' => '/index.php', 'SCRIPT_NAME' => '/index.php',
    'REQUEST_METHOD' => 'GET', 'REMOTE_ADDR' => '127.0.0.1',
    'HTTP_USER_AGENT' => 'md-external-writer-test',
];
unset($_SERVER['QUERY_STRING']);
$_GET = [];
chdir($pkwk_root);
define('DATA_HOME', '');
define('LIB_DIR', 'lib/');
foreach (['func', 'file', 'plugin', 'html', 'backup', 'convert_html',
          'make_link', 'diff', 'config', 'link', 'auth', 'proxy'] as $lib) {
    require LIB_DIR . $lib . '.php';
}
$notify = 0;
require LIB_DIR . 'init.php';

require_once PLUGIN_DIR . 'md.inc.php';

// ---- アサーションヘルパ ------------------------------------------------------
$pass = 0; $fail = 0;
function ok($cond, $label) {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  ✓ {$label}\n"; }
    else       { $fail++; echo "  ✗ FAIL: {$label}\n"; }
}
function render($page) {
    global $vars;
    $vars['page'] = $page;
    return md_convert_page(get_source($page), false); // キャッシュなし経路
}
function stored($page) {
    return join('', get_source($page));
}

echo "== 前提 ==\n";
ok($GLOBALS['fixed_heading_anchor'] == 1, 'fixed_heading_anchor が有効（汚染が起きる設定）');
ok(function_exists('md_page_write'), 'md_page_write() が定義されている');
ok(MD_PLUGIN_VERSION === '0.4', 'MD_PLUGIN_VERSION = 0.4（キャッシュ自動無効化）');

// =========================================================================
echo "\n== 1. 外部ライター耐性（素の page_write でも描画が正しい） ==\n";
// =========================================================================
$page = 'MDテスト/外部ライター';
$src  = "#md\n# 見出し\n\n* 項目1\n* 項目2\n*強調から*始まる行\n\n通常の段落。\n";
page_write($page, $src);   // ← REST API / draft 相当（ガードなし）

$s = stored($page);
ok(preg_match('/^\* 項目1 \[#[a-z][0-9a-f]{7}\]$/m', $s) === 1,
   '前提確認: 素の page_write で箇条書き行にアンカーが付与される');

$html = render($page);
ok(!preg_match('/\[#[a-z][0-9a-f]{7}\]/', $html), '★ 描画 HTML にアンカーが漏れない');
ok(strpos($html, '項目1') !== false && strpos($html, '項目2') !== false, '箇条書きが描画される');
ok(strpos($html, '<li>') !== false, 'Markdown リストとして描画される（<li>）');

// =========================================================================
echo "\n== 2. フェンスコードブロック ==\n";
// =========================================================================
$page2 = 'MDテスト/フェンス';
$src2  = "#md\n本文です。\n\n```c\n*ptr = value;\n* リストではないコード\n```\n";
page_write($page2, $src2); // フェンス内の '*' 行も make_str_rules に汚染される

$s2 = stored($page2);
ok(strpos($s2, '*ptr = value; [#') !== false,
   '前提確認: フェンス内のコード行も素の page_write で汚染される');

$html2 = render($page2);
ok(strpos($html2, '*ptr = value;') !== false, '★ コード行が描画で復元される');
ok(!preg_match('/\[#[a-z][0-9a-f]{7}\]/', $html2), 'フェンス内にもアンカーが漏れない');

// 手書きの独自アンカー風文字列（生成 ID の形状と異なる）は保持される
$page2b = 'MDテスト/手書きアンカー';
$src2b  = "#md\n```\n* setting [#section1]\n```\n";
page_write($page2b, $src2b);
$html2b = render($page2b);
ok(strpos($html2b, '[#section1]') !== false, '生成 ID 形状でない [#section1] は除去されない');

// =========================================================================
echo "\n== 3. md_page_write() による自己修復 ==\n";
// =========================================================================
// 汚染済みソース（テスト1で作成）を Web UI 相当の md_page_write で再保存
$polluted = stored($page);
$polluted = str_replace('通常の段落。', "通常の段落。\n\n&now; はマクロ。", $polluted);
md_page_write($page, $polluted);

$healed = stored($page);
ok(!preg_match('/\[#[a-z][0-9a-f]{7}\]/', $healed), '★ 保存でアンカーが除去される（自己修復）');
ok(strpos($healed, '&now;') !== false, '$str_rules マクロ（&now;）が置換されない');
ok(preg_match('/^#author\(/m', $healed) === 1, '#author 行は通常どおり付与される');

// =========================================================================
echo "\n== 4. 非 md ページへの無影響 ==\n";
// =========================================================================
$page4 = 'MDテスト/通常ページ';
md_page_write($page4, "*見出し\n本文\n");
$s4 = stored($page4);
ok(preg_match('/^\*見出し \[#[a-z][0-9a-f]{7}\]$/m', $s4) === 1,
   '非 md ページは従来どおりアンカーが付与される');

// =========================================================================
echo "\n== 5. キャッシュ経路 ==\n";
// =========================================================================
$GLOBALS['vars']['page'] = $page;
$html_c1 = md_convert_page(get_source($page), true);  // キャッシュ生成
$html_c2 = md_convert_page(get_source($page), true);  // キャッシュヒット
ok($html_c1 === $html_c2, 'キャッシュヒット時も同一出力');
ok(!preg_match('/\[#[a-z][0-9a-f]{7}\]/', $html_c1), 'キャッシュ経由でもアンカーが漏れない');

// =========================================================================
echo "\n== 6. 同梱サンプル #md ページの回帰 ==\n";
// =========================================================================
$sample_dir = dirname(__DIR__) . '/wiki';
$checked = 0;
if (is_dir($sample_dir)) {
    foreach (glob($sample_dir . '/*.txt') as $f) {
        $content = (string)file_get_contents($f);
        if (!md_is_markdown($content)) continue;
        $GLOBALS['vars']['page'] = 'サンプル' . $checked;
        $html_s = md_convert_page(explode("\n", $content), false);
        ok(is_string($html_s) && $html_s !== '', basename($f) . ' が描画できる');
        $checked++;
        if ($checked >= 5) break;
    }
}
if ($checked === 0) echo "  （サンプル #md ページなし — スキップ）\n";

// -------------------------------------------------------------------------
echo "\n----------------------------------------\n結果: {$pass} passed, {$fail} failed\n";
exit($fail > 0 ? 1 : 0);
