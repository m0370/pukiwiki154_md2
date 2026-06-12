<?php
// PukiWiki - Yet another WikiWikiWeb clone.
// md.inc.php
// Version: 0.1
// License: GPL v2 or (at your option) any later version
//
// Markdown rendering plugin (plugin-folder-only implementation)
//
// ページ内のいずれかの行に「#md」と書くと、そのページは Markdown 記法として
// 描画される（差し替え版 read.inc.php / edit.inc.php がこのファイルの関数を呼ぶ）。
// lib/ や skin/ は無改造。Markdown パーサー(league/commonmark)は
// plugin/markdown_parser/ に同梱する。
//
// 素の PukiWiki にこのプラグインなしでページを持ち込んだ場合、#md の行は
// そのまま文字として表示され、本文は PukiWiki 記法として解釈される（緩やかな劣化）。
//
// 設定（pukiwiki.ini.php で上書き可能。未設定ならここのデフォルトが使われる）:
//   $use_markdown_cache          = 1;      // 変換結果キャッシュ
//   $markdown_cache_lifetime     = 604800; // キャッシュ有効期限(秒) 7日
//   $markdown_support_hash_plugin = 0;     // 1にすると !plugin に加え #plugin も許可
//   $default_md                  = 1;      // 新規ページに #md を自動挿入
//   $markdown_debug_mode         = 0;      // HTMLコメントでデバッグ情報出力

define('MD_PLUGIN_VERSION', '0.2');
define('MD_PLUGIN_PARSER_DIR', PLUGIN_DIR . 'markdown_parser/');
define('MD_PLUGIN_FLAG_REGEX', '/^#md\s*$/m');

// ---------------------------------------------------------------------------
// Block plugin interface

// 「#md」の行そのもの: 通常の convert_html 経路（include 等）で処理された場合に
// 何も表示しないための空変換
function plugin_md_convert()
{
	return '';
}

// ---------------------------------------------------------------------------
// Configuration helpers

function md_config($name, $default)
{
	return isset($GLOBALS[$name]) ? $GLOBALS[$name] : $default;
}

// Markdown ページかどうか（文字列・行配列のどちらでも判定可）
function md_is_markdown($source)
{
	if (is_array($source)) $source = implode('', $source);
	return (bool) preg_match(MD_PLUGIN_FLAG_REGEX, str_replace("\r", '', $source));
}

// ---------------------------------------------------------------------------
// Error formatting

function md_format_error($type, $context, $e = null)
{
	$debug = md_config('markdown_debug_mode', 0);
	switch ($type) {
		case 'plugin_block':
			$message = 'Plugin error: ' . htmlsc($context);
			break;
		case 'parser':
		default:
			$message = 'Markdown parser error';
			break;
	}
	if ($debug && $e !== null) {
		$message .= ' (' . htmlsc($e->getMessage()) . ')';
	}
	return '<div class="alert alert-warning">' . $message . '</div>';
}

// ---------------------------------------------------------------------------
// URL safety check for Markdown links/images

function md_is_safe_url($url)
{
	if (empty($url)) return false;
	if (strpos($url, '#') === 0) return true;

	$parsed = parse_url($url);
	if ($parsed === false) return false;
	if (!isset($parsed['scheme'])) return true;

	$safe_schemes = array('http', 'https', 'mailto', 'tel');
	return in_array(strtolower($parsed['scheme']), $safe_schemes, true);
}

// ---------------------------------------------------------------------------
// Plugin dispatch
//
// lib/plugin.php の do_plugin_convert はマルチライン本文の分離を
// PKWKEXP_DISABLE_MULTILINE_PLUGIN_HACK 設定に依存して行うため、
// ini 設定に依存しない自前のディスパッチャを持つ（プラグイン呼び出し規約は同一）。

function md_do_plugin_convert($name, $args = '', $body = null)
{
	global $digest;

	if (do_plugin_init($name) === FALSE) {
		return '[Plugin init failed: ' . htmlsc($name) . ']';
	}

	if ($args === '') {
		$aryargs = array();
	} else {
		$aryargs = csv_explode(',', $args);
	}
	if ($body !== null) $aryargs[] = & $body;

	$_digest = $digest;
	$retvar  = call_user_func_array('plugin_' . $name . '_convert', $aryargs);
	$digest  = $_digest; // Revert

	if ($retvar === FALSE) {
		return htmlsc('#' . $name . ($args != '' ? '(' . $args . ')' : ''));
	}
	return $retvar;
}

// Markdown 見出し行（# 見出し 等）をプラグイン行と誤認しないための判定
function md_line_is_heading($line)
{
	if (preg_match('/^#{2,6}/', $line)) return true; // ## 以上は常に見出し
	if (preg_match('/^#(\s|$)/', $line)) return true; // 「# 」または「#」単独
	return false;
}

// マルチラインプラグイン（!plugin{{ ... }}）の本文収集
// 戻り値は "\r" 区切りで本文を連結した行。$i は消費した行数だけ進む。
function md_collect_multiline_plugin($line, $lines, &$i, $count)
{
	$hash = md_config('markdown_support_hash_plugin', 0);
	if (!empty($hash) && md_line_is_heading($line)) return $line;

	$prefix = !empty($hash) ? '[#!]' : '!';
	if (preg_match('/^' . $prefix . '[^{]+(\{\{+)\s*$/', $line, $m)) {
		$len = strlen($m[1]);
		$line .= "\r"; // Delimiter
		while ($i + 1 < $count) {
			$next = preg_replace('/[\r\n]*$/', '', $lines[$i + 1]);
			$i++;
			if (preg_match('/\}{' . $len . '}/', $next)) {
				$line .= $next;
				break;
			} else {
				$line .= $next . "\r";
			}
		}
	}
	return $line;
}

// ブロックプラグイン行（!plugin / 設定により #plugin）の処理
// プラグイン行でなければ null を返す
function md_process_block_plugin($line, &$debug_info)
{
	$hash = md_config('markdown_support_hash_plugin', 0);

	if (!empty($hash) && md_line_is_heading($line)) return null;

	$prefix = !empty($hash) ? '[#!]' : '!';

	$matches = array();
	if (!preg_match('/^' . $prefix . '([^\(\{]+)(?:\(([^\r]*)\))?(\{*)/', $line, $matches)) {
		return null; // Not a plugin line
	}

	$plugin = trim($matches[1]);
	if (!preg_match('/^\w{1,64}$/', $plugin)) return null;

	if (exist_plugin_convert($plugin)) {
		$args = isset($matches[2]) ? $matches[2] : '';
		$body = null;
		$len  = strlen($matches[3]);
		if ($len > 0 &&
		    preg_match('/\{{' . $len . '}\s*\r(.*)\r\}{' . $len . '}/', $line, $m_body)) {
			$body = $m_body[1];
		}
		try {
			$line = md_do_plugin_convert($plugin, $args, $body);
			$debug_info['plugin_calls'][] = $plugin;
		} catch (Exception $e) {
			$line = md_format_error('plugin_block', $plugin, $e);
			$debug_info['plugin_errors'][] = $plugin;
		}
	} else {
		$prefix_char = (substr(trim($line), 0, 1) == '#') ? '#' : '!';
		$line = '<div class="alert alert-warning">Plugin "' . $prefix_char .
			htmlsc($plugin) . '" not found.</div>';
		$debug_info['plugin_errors'][] = $plugin;
	}
	return $line;
}

// ---------------------------------------------------------------------------
// Markdown image / link preprocessing

// 行頭の Markdown 画像はスキーム検査のみ行い、変換は commonmark に任せる
// 画像行でなければ null を返す
function md_process_image($line, &$debug_info)
{
	$matchimg = array();
	if (preg_match('/^\!\[([^\]]*)\]\(([^\)]+)\)/u', $line, $matchimg)) {
		$img_url = trim($matchimg[2]);
		if (!md_is_safe_url($img_url)) {
			$line = '<div class="alert alert-warning">Unsafe image URL scheme detected. Only http/https are allowed.</div>';
			$debug_info['security_warnings'][] = 'Unsafe image URL';
		}
		return $line;
	}
	return null;
}

// Markdown リンクを PukiWiki リンクに変換した上で make_link を通す
// （[[PukiWikiリンク]]・&inlineplugin; もここで HTML 化される）
function md_process_links($line, &$debug_info)
{
	$line = preg_replace_callback(
		'/\[([^\]]+)\]\(([^\s\)]+)(?:\s+\"([^\"]+)\")?\)/u',
		function ($matches) use (&$debug_info) {
			$text = $matches[1];
			$url  = $matches[2];
			if (!md_is_safe_url($url)) {
				$debug_info['security_warnings'][] = 'Unsafe link URL';
				return '<span class="alert alert-warning">[Invalid URL]</span>';
			}
			return "[[{$text}>{$url}]]";
		},
		$line
	);

	// 保存時に付与済みの PukiWiki 式固定アンカーを非表示に
	$line = preg_replace('/\[\#[a-zA-Z0-9]{8}\]$/u', '', $line);

	return make_link($line);
}

// ---------------------------------------------------------------------------
// league/commonmark parser

function md_init_parser()
{
	static $parser = null;
	if ($parser !== null) return $parser;

	$autoload = MD_PLUGIN_PARSER_DIR . 'autoload.php';
	if (!file_exists($autoload)) {
		throw new Exception('league/commonmark not found in ' . MD_PLUGIN_PARSER_DIR);
	}
	require_once $autoload;

	$environment = new \League\CommonMark\Environment\Environment(array(
		'html_input'         => 'allow',  // make_link() が生成した HTML を通す
		'allow_unsafe_links' => false,    // javascript: 等を遮断
		'renderer' => array(
			// 行末スペースなしの単純改行を <br /> として反映する
			'soft_break' => "<br />\n",
		),
		'heading_permalink' => array(
			'symbol'      => '',
			'aria_hidden' => true,
		),
		'table_of_contents' => array(
			'position'    => 'placeholder',
			'placeholder' => '[TOC]',
			'style'       => 'bullet',
			'min_heading_level' => 1,
			'max_heading_level' => 6,
		),
	));

	$environment->addExtension(new \League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension());
	$environment->addExtension(new \League\CommonMark\Extension\GithubFlavoredMarkdownExtension());
	$environment->addExtension(new \League\CommonMark\Extension\Footnote\FootnoteExtension());
	// 見出しアンカーと [TOC] プレースホルダによるページ内目次
	$environment->addExtension(new \League\CommonMark\Extension\HeadingPermalink\HeadingPermalinkExtension());
	$environment->addExtension(new \League\CommonMark\Extension\TableOfContents\TableOfContentsExtension());

	$parser = new \League\CommonMark\MarkdownConverter($environment);
	return $parser;
}

// ---------------------------------------------------------------------------
// Footnotes: commonmark の脚注を PukiWiki の脚注欄($foot_explain)に移し替える

function md_convert_footnotes($html)
{
	global $foot_explain, $vars;

	if (!preg_match('/<div class="footnotes"[^>]*>(.*?)<\/div>\s*$/s', $html, $matches)) {
		if (!preg_match('/<div class="footnotes"[^>]*>(.*?)<\/div>/s', $html, $matches)) {
			return $html;
		}
	}

	$footnotes_html = $matches[1];
	$html = preg_replace('/<div class="footnotes"[^>]*>.*?<\/div>\s*$/s', '', $html);
	$html = preg_replace('/<div class="footnotes"[^>]*>.*?<\/div>/s', '', $html);

	if (!preg_match('/<ol[^>]*>(.*?)<\/ol>/s', $footnotes_html, $ol_matches)) {
		return $html;
	}

	preg_match_all('/<li[^>]*id="fn:([^"]+)"[^>]*>(.*?)<\/li>/s', $ol_matches[1], $li_matches, PREG_SET_ORDER);
	if (empty($li_matches)) return $html;

	$script = get_page_uri(isset($vars['page']) ? $vars['page'] : '');
	$footnote_counter = count($foot_explain) + 1;

	foreach ($li_matches as $li_match) {
		$content = $li_match[2];
		$content = preg_replace('/<a[^>]*class="footnote-backref"[^>]*>.*?<\/a>/s', '', $content);
		$content = trim($content);
		$content = preg_replace('/^<p>(.*?)<\/p>$/s', '$1', $content);
		$content = preg_replace('/(?:&nbsp;|\s)+$/', '', $content); // backref 前の区切り空白を除去

		$foot_explain[$footnote_counter] = '<a id="notefoot_' . $footnote_counter . '" href="' .
			$script . '#notetext_' . $footnote_counter . '" class="note_super">*' .
			$footnote_counter . '</a>' . "\n" .
			'<span class="small">' . $content . '</span><br />';

		$footnote_counter++;
	}

	$html = preg_replace_callback(
		'/<sup[^>]*><a[^>]*href="#fn:([^"]+)"[^>]*>(\d+)<\/a><\/sup>/',
		function ($matches) use ($script) {
			$display_num = $matches[2];
			return '<a id="notetext_' . $display_num . '" href="' . $script .
				'#notefoot_' . $display_num . '" class="note_super">*' . $display_num . '</a>';
		},
		$html
	);

	return $html;
}

// ---------------------------------------------------------------------------
// Cache

function md_cache_cleanup($lifetime)
{
	if (mt_rand(1, 100) !== 1) return;
	if (empty($lifetime) || !is_dir(CACHE_DIR)) return;

	$files = @glob(CACHE_DIR . 'markdown_*.cache');
	if ($files === false) return;

	$now = time();
	foreach ($files as $file) {
		$mtime = @filemtime($file);
		if ($mtime !== false && ($now - $mtime) > $lifetime) {
			@unlink($file);
		}
	}
}

function md_cache_read($cache_file, $cache_digest, $parser_mode, $lifetime)
{
	if (!file_exists($cache_file)) return null;

	$fp = @fopen($cache_file, 'r');
	if ($fp === false) return null;
	if (!flock($fp, LOCK_SH)) {
		fclose($fp);
		return null;
	}
	$content = stream_get_contents($fp);
	flock($fp, LOCK_UN);
	fclose($fp);

	if ($content === false || $content === '') return null;

	$cached = @json_decode($content, true);
	if (!is_array($cached) ||
	    !isset($cached['digest']) || $cached['digest'] !== $cache_digest ||
	    !isset($cached['parser']) || $cached['parser'] !== $parser_mode ||
	    !isset($cached['html'])) {
		return null;
	}

	if (isset($cached['timestamp']) && !empty($lifetime) &&
	    (time() - $cached['timestamp']) > $lifetime) {
		return null; // Expired
	}

	return $cached['html'];
}

function md_cache_write($cache_file, $cache_digest, $parser_mode, $html)
{
	$cache_data = array(
		'digest'    => $cache_digest,
		'parser'    => $parser_mode,
		'html'      => $html,
		'timestamp' => time(),
		'version'   => 2,
	);
	$json = json_encode($cache_data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
	if ($json === false) return;
	if (!is_dir(CACHE_DIR) && !@mkdir(CACHE_DIR, 0755, true)) return;
	@file_put_contents($cache_file, $json, LOCK_EX);
}

// ---------------------------------------------------------------------------
// Main: Markdown ページ全体を HTML へ変換する
//
// $lines: get_source() の行配列または改行区切り文字列
// $allow_cache: プレビュー時は false を渡す

function md_convert_page($lines, $allow_cache = TRUE)
{
	global $vars, $digest;

	// Set digest (プラグインが生成するフォームの整合性に必要)
	$digest = md5(join('', get_source(isset($vars['page']) ? $vars['page'] : '')));

	if (!is_array($lines)) $lines = explode("\n", $lines);

	$cache_digest = md5(implode("\n", $lines));
	$debug_info   = array('plugin_calls' => array(), 'plugin_errors' => array(), 'security_warnings' => array());

	$use_cache   = md_config('use_markdown_cache', 1) && $allow_cache;
	$lifetime    = md_config('markdown_cache_lifetime', 604800);
	$hash        = md_config('markdown_support_hash_plugin', 0);
	// MD_PLUGIN_VERSION を含めることで、変換ロジック更新時に旧キャッシュを無効化する
	$parser_mode = (!empty($hash) ? 'md-plugin-hash' : 'md-plugin') . '-v' . MD_PLUGIN_VERSION;
	$cache_file  = null;

	if ($use_cache) {
		$page_name  = isset($vars['page']) ? $vars['page'] : 'unknown';
		$cache_key  = md5($page_name . ':' . $parser_mode . ':' . $cache_digest);
		$cache_file = CACHE_DIR . 'markdown_' . $cache_key . '.cache';

		$cached_html = md_cache_read($cache_file, $cache_digest, $parser_mode, $lifetime);
		if ($cached_html !== null) {
			// キャッシュヒット時も脚注処理は毎回必要（$foot_explain を更新するため）
			return md_convert_footnotes($cached_html);
		}
	}

	$count        = count($lines);
	$result_lines = array();
	$fence_char   = '';
	$fence_len    = 0;

	// インライン脚注（PukiWiki ((...)) / Pandoc ^[...]）の内容を退避し、
	// Markdown 参照形式 [^label] に置き換えるためのコールバック。
	// インライン形式のまま commonmark に渡すと脚注内容がプレーンテキスト扱いになり
	// make_link が生成したリンク等の HTML がエスケープされてしまうため、
	// 内容を文末の参照定義（[^label]: ...）へ移して Markdown として解釈させる。
	$inline_footnotes  = array();
	$stash_inline_footnote = function ($matches) use (&$inline_footnotes) {
		$inline_footnotes[] = $matches[1];
		return '[^mdfnauto' . count($inline_footnotes) . ']';
	};

	for ($i = 0; $i < $count; $i++) {
		$line = $lines[$i];

		// フェンスコードブロック内は一切加工しない
		if (preg_match('/^[ ]{0,3}(`{3,}|~{3,})/', $line, $matches)) {
			$current_char = $matches[1][0];
			$current_len  = strlen($matches[1]);
			if ($fence_char === '') {
				$fence_char = $current_char;
				$fence_len  = $current_len;
			} elseif ($fence_char === $current_char && $current_len >= $fence_len) {
				$fence_char = '';
				$fence_len  = 0;
			}
			$result_lines[] = str_replace(array("\r\n", "\n", "\r"), '', $line);
			continue;
		}
		if ($fence_char !== '') {
			$result_lines[] = str_replace(array("\r\n", "\n", "\r"), '', $line);
			continue;
		}

		// #md, #author, #freeze は Markdown パーサーに渡さない（行頭の単独指定のみ）
		$line = preg_replace('/^(\#author\(.*\)|\#md|\#freeze)\s*$/', '', $line);

		// #contents / !contents はページ内目次プレースホルダに変換
		if (preg_match('/^[#!]contents(\(.*\))?\s*$/', rtrim($line))) {
			$result_lines[] = '[TOC]';
			continue;
		}

		// PukiWiki 脚注記法 ((コメント)) と Pandoc インライン脚注 ^[コメント] を
		// Markdown 参照形式 [^label] に変換（内容は文末の定義として後置する）
		$line = preg_replace_callback('/\(\((.+?)\)\)/', $stash_inline_footnote, $line);
		$line = preg_replace_callback('/\^\[([^\]]+)\]/', $stash_inline_footnote, $line);

		// マルチラインプラグイン本文の収集
		$line = md_collect_multiline_plugin($line, $lines, $i, $count);

		// ブロックプラグイン
		$plugin_result = md_process_block_plugin($line, $debug_info);
		if ($plugin_result !== null) {
			$line = $plugin_result;
		} else {
			// Markdown 画像
			$image_result = md_process_image($line, $debug_info);
			if ($image_result !== null) {
				$line = $image_result;
			} else {
				// リンク（Markdown / PukiWiki 両記法）とインラインプラグイン
				$line = md_process_links($line, $debug_info);
			}
		}

		$result_lines[] = str_replace(array("\r\n", "\n", "\r"), '', $line);
	}

	// 退避したインライン脚注の内容を参照定義として文末に追加。
	// 内容にも本文と同じリンク処理（URL・[[PukiWiki]]・Markdown リンク）を通す
	foreach ($inline_footnotes as $idx => $fn_content) {
		$fn_content = md_process_links($fn_content, $debug_info);
		$result_lines[] = '';
		$result_lines[] = '[^mdfnauto' . ($idx + 1) . ']: ' .
			str_replace(array("\r\n", "\n", "\r"), ' ', $fn_content);
	}

	$text = implode("\n", $result_lines);

	try {
		$parser   = md_init_parser();
		$raw_html = $parser->convert($text)->getContent();

		if ($use_cache && $cache_file !== null) {
			md_cache_write($cache_file, $cache_digest, $parser_mode, $raw_html);
			md_cache_cleanup($lifetime);
		}

		$result = md_convert_footnotes($raw_html);

		if (md_config('markdown_debug_mode', 0)) {
			$result = '<!-- md.inc.php v' . MD_PLUGIN_VERSION . ' debug: plugins=[' .
				htmlsc(implode(',', $debug_info['plugin_calls'])) . '] errors=[' .
				htmlsc(implode(',', $debug_info['plugin_errors'])) . '] warnings=[' .
				htmlsc(implode(',', $debug_info['security_warnings'])) . '] -->' . "\n" . $result;
		}

		return $result;
	} catch (Exception $e) {
		return md_format_error('parser', '', $e);
	}
}
