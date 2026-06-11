# PukiWiki Markdown プラグイン（plugin フォルダ完結型）

**現在のバージョン: v0.1**（`md.inc.php` 内の `MD_PLUGIN_VERSION` 定数およびファイルヘッダに記載）

PukiWiki 1.5.4 に **plugin フォルダへのファイル追加・差し替えだけで** Markdown 記法対応を追加するプラグインです。`lib/`・`skin/`・`pukiwiki.ini.php` には一切手を加えません。

ページのいずれかの行に `#md` と書くだけで、そのページは Markdown（GitHub Flavored Markdown）として描画されます。書かなければ従来通りの PukiWiki 記法ページです。

[pukiwiki154_md](https://github.com/m0370/pukiwiki154_md)（lib 改造によるMarkdown対応版）の後継として、本体無改造・プラグイン完結の方式に再設計したものです。

---

## 特徴

- **PukiWiki 本体（lib/・skin/）完全無改造** — 本体のバージョンアップ時の追従が容易
- **ページ単位で記法を選択** — `#md` を書いたページだけが Markdown になる
- **GitHub Flavored Markdown 対応** — テーブル、打ち消し線、タスクリスト、オートリンク（league/commonmark 2.x + GFM 拡張）
- **緩やかな改行ルール** — 行末スペースなしの単純な改行がそのまま `<br>` として反映される
- **ページ内目次** — Markdown ページ内の `#contents`（または `!contents`）行が Markdown 見出しから生成された目次になる（TableOfContents 拡張）
- **PukiWiki プラグインが使える** — ブロックプラグインは `!plugin` 表記（例: `!comment`、`!ls`）。`&plugin;` 形式のインラインプラグインも使用可能
- **リンクは両記法対応** — `[テキスト](URL)` でも `[[ページ名]]` / `[[テキスト>URL]]` でも書ける
- **脚注は3記法対応** — Markdown 参照式 `[^1]`、Pandoc インライン式 `^[脚注]`、PukiWiki 式 `((脚注))`。いずれもページ下部の PukiWiki 標準の脚注欄に表示
- **変換結果キャッシュ** — 2回目以降の表示は変換済み HTML を再利用（ページ更新で自動再生成）
- **後方互換性** — このプラグインがない素の PukiWiki にページデータを持ち込んでもエラーにならない（後述）

---

## 動作要件

- PukiWiki 1.5.4（UTF-8 版）
- PHP 7.4 以上（PHP 8.5 + PukiWiki 1.5.4 で動作確認済み）
- league/commonmark 2.x（**同梱済み** — `plugin/markdown_parser/`）

---

## インストール

本リポジトリの `plugin/` の中身を、PukiWiki 設置先の `plugin/` にコピーするだけです。

| ファイル | 種別 | 内容 |
|---|---|---|
| `plugin/md.inc.php` | **新規** | Markdown 変換エンジン本体（このプラグインの中核） |
| `plugin/read.inc.php` | **差し替え** | PukiWiki 1.5.4 標準プラグインに 12 行追加（変更点は後述） |
| `plugin/edit.inc.php` | **差し替え** | PukiWiki 1.5.4 標準プラグインに 36 行追加（変更点は後述） |
| `plugin/markdown_parser/` | **新規** | league/commonmark 2.x と依存ライブラリ（Composer vendor 一式） |

`read.inc.php` と `edit.inc.php` は PukiWiki 1.5.4 標準のものを置き換えます。標準版に独自の改造を加えている場合は、後述の「read.inc.php / edit.inc.php の変更点」を参考に手動でマージしてください。

---

## 使い方

### Markdown ページの作成

ページの先頭（実際はどの行でも可）に `#md` と書きます。

```
#md

# 見出し1

これは1行目
これは2行目（行末スペース不要で改行が反映されます）

## 見出し2

**太字** ~~打ち消し~~ `コード`

| 列A | 列B |
|-----|-----|
| 1   | 2   |
```

新規ページ作成時には編集フォームに `#md` が自動挿入されます（`$default_md = 0;` で無効化可能）。`#md` の行を消せば従来の PukiWiki 記法ページになります。

### ページ内目次

`#contents` または `!contents` と書いた行が、Markdown 見出しから生成された目次に置き換わります。

### プラグインの使用

ブロックプラグインは `#` の代わりに `!` を使います（Markdown の見出し記法 `# 見出し` との衝突回避のため）。

```
!comment
!ls
!vote(賛成,反対)
```

複数行引数を取るプラグインも使えます（`!plugin{{ ... }}`。本体の `PKWKEXP_DISABLE_MULTILINE_PLUGIN_HACK` 設定に関係なく動作します）。

`&counter;` のようなインラインプラグインはそのまま使えます。

設定で `$markdown_support_hash_plugin = 1;` とすると `#plugin` 表記も許可されます（`# ` のようにスペースを挟んだ行は見出しとして扱われます）。

### 脚注

```
Markdown参照式の脚注[^1]
Pandocインライン式の脚注^[これは脚注]
PukiWiki式の脚注((これも脚注))

[^1]: 参照式の脚注本文
```

---

## 設定（任意）

`pukiwiki.ini.php` に追記すると挙動を変更できます。**追記しなくても下記デフォルトで動作します。**

```php
$use_markdown_cache = 1;           // 変換結果キャッシュ（0で無効）
$markdown_cache_lifetime = 604800; // キャッシュ有効期限（秒）デフォルト7日
$markdown_support_hash_plugin = 0; // 1で #plugin 表記も許可
$default_md = 1;                   // 新規ページに #md を自動挿入（0で無効）
$markdown_debug_mode = 0;          // 1でHTMLコメントにデバッグ情報出力
```

> **注意: キャッシュとプラグインの相性**
>
> キャッシュ（`$use_markdown_cache = 1`）はページ本文のダイジェストをキーに変換結果のHTMLを丸ごと保存します。このため、**ページ本文を書き換えずに表示だけが変わるプラグインは、キャッシュが有効な間は更新が反映されません**。
>
> - `#pcomment` … コメントは別ページに保存されるため、新着コメントがキャッシュ期限切れ（またはページ本文の編集）まで表示されない
> - `#counter` … アクセスカウンタがキャッシュ時点の値で固定される
> - `#calendar` / `#recent` など … 日付や他ページの更新が反映されない
>
> これらのプラグインを使うページが多い場合は `$use_markdown_cache = 0` を検討してください。

---

## 仕組み

PukiWiki 1.5.4 では、ページ表示を担う `read` コマンド自体が plugin フォルダ内のプラグイン（`plugin/read.inc.php`）です。`lib/pukiwiki.php` には次の分岐があります。

```php
if (isset($retvars['body']) && $retvars['body'] != '') {
    $body = & $retvars['body'];   // アクションプラグインが返したHTMLをそのまま採用
} else {
    $body = convert_html(get_source($base));  // 通常のPukiWiki変換
}
```

標準の `read.inc.php` は `'body' => ''` を返すため通常変換に進みますが、差し替え版はソースに `#md` がある場合に Markdown 変換済み HTML を `body` として返します。これにより **lib を改造せずに** ページ描画を Markdown に切り替えています。編集プレビューも同様に `edit.inc.php`（これも標準プラグイン）内で完結しているため、plugin フォルダ内だけで対応できます。

Markdown 変換は league/commonmark が行いますが、その前段で `md.inc.php` が行単位の前処理を行います：

1. フェンスコードブロック内（``` ～ ```）は一切加工しない
2. `#md` / `#author(...)` / `#freeze` の行を除去
3. `#contents` / `!contents` 行を目次プレースホルダに変換
4. PukiWiki 式脚注 `((...))` を Pandoc 式 `^[...]` に変換
5. `!plugin` 行をプラグイン呼び出しに変換（複数行 `{{ }}` 含む）
6. Markdown リンクの URL スキーム検査（`javascript:` 等を遮断）後、PukiWiki の `make_link()` でリンク・インラインプラグインを HTML 化

### 保存時のデータ保護

PukiWiki は保存時に `make_str_rules()` で本文を自動整形します（`*` で始まる行への `[#アンカー]` 自動付与など）。Markdown の `* リスト` 行が破壊されるのを防ぐため、差し替え版 `edit.inc.php` は `#md` ページの保存時のみ `$str_rules` と `$fixed_heading_anchor` を一時的に無効化します。PukiWiki 記法ページの保存は従来通りです（検証済み）。

---

## 後方互換性

### 検証結果（PukiWiki 1.5.4 素の状態 + PHP 8.5 で確認）

| 項目 | 結果 |
|---|---|
| `#md` ページを**素の PukiWiki**（本プラグインなし）で表示 | **HTTP 200・エラーなし**。`#md` の行が文字として表示され、本文は PukiWiki 記法として解釈された読める状態で表示される（書式は失われるが内容は閲覧可能） |
| 本プラグイン導入後の **PukiWiki 記法ページの表示** | 素の PukiWiki と完全に同一の HTML 出力（`lib/` 無改造のため） |
| 本プラグイン導入後の **PukiWiki 記法ページの保存** | 従来通り（見出しへの `[#アンカー]` 付与等の自動整形も従来通り動作） |
| `lib/` ディレクトリ | PukiWiki 1.5.4 公式と **diff 差分ゼロ** |
| `skin/` ディレクトリ | PukiWiki 1.5.4 公式と **diff 差分ゼロ** |
| `pukiwiki.ini.php` | PukiWiki 1.5.4 公式と **diff 差分ゼロ**（設定追記は任意） |

つまり、このプラグインを撤去しても（`read.inc.php`・`edit.inc.php` を標準版に戻しても）Wiki は壊れず、`#md` ページは劣化表示になるだけです。逆に、`#md` ページを含むデータを別の素の PukiWiki に移設してもエラーは発生しません。

---

## read.inc.php / edit.inc.php の変更点

差し替え版は PukiWiki 1.5.4 標準版をベースに、以下の追加のみを行っています（削除・変更した既存行は `edit.inc.php` のプレビュー3行のみで、それも分岐の else 側に温存）。

### read.inc.php（12行追加・1箇所）

`plugin_read_action()` のページ表示分岐に、`#md` ページなら Markdown 描画結果を返す処理を追加：

```diff
 		check_readable($page, true, true);
 		header_lastmod($page);
 		is_pagelist_cache_enabled(true); // Enable get_existpage() cache
+
+		// Markdown page (#md): render via md plugin instead of convert_html
+		// (lib/pukiwiki.php uses returned 'body' as-is when it is not empty)
+		$source = get_source($page);
+		if (exist_plugin('md') && function_exists('md_is_markdown') &&
+		    md_is_markdown($source)) {
+			prepare_display_materials();
+			$body = md_convert_page($source);
+			if ($body != '') {
+				return array('msg'=>'', 'body'=>$body);
+			}
+		}
 		return array('msg'=>'', 'body'=>'');
```

`md.inc.php` が存在しない場合は `exist_plugin('md')` が false になり標準動作にフォールバックします。

### edit.inc.php（3箇所）

**1. 新規ページへの `#md` 自動挿入**（`plugin_edit_action()`）：

```diff
 	$postdata = @join('', get_source($page));
 	if ($postdata === '') $postdata = auto_template($page);
+	if ($postdata === '' && exist_plugin('md') &&
+	    md_config('default_md', 1)) {
+		// New page: start in Markdown mode by default
+		$postdata = "#md\n\n";
+	}
 	$postdata = remove_author_info($postdata);
```

**2. プレビューの Markdown 対応**（`plugin_edit_preview()`）：

```diff
 	if ($postdata) {
-		$postdata = make_str_rules($postdata);
-		$postdata = explode("\n", $postdata);
-		$postdata = drop_submit(convert_html($postdata));
+		if (exist_plugin('md') && function_exists('md_is_markdown') &&
+		    md_is_markdown($postdata)) {
+			// Markdown page: skip make_str_rules, render via md plugin (no cache)
+			$postdata = drop_submit(md_convert_page(explode("\n", $postdata), FALSE));
+		} else {
+			$postdata = make_str_rules($postdata);
+			$postdata = explode("\n", $postdata);
+			$postdata = drop_submit(convert_html($postdata));
+		}
 		$body .= '<div id="preview">' . $postdata . '</div>' . "\n";
```

**3. 保存時の自動整形回避**（`plugin_edit_write()`）：

```diff
+	// Markdown page: prevent make_str_rules() in page_write() from rewriting
+	// the body (e.g. appending [#anchor] to lines starting with *)
+	$md_mode = exist_plugin('md') && function_exists('md_is_markdown') &&
+		md_is_markdown($postdata);
+	if ($md_mode) {
+		global $str_rules, $fixed_heading_anchor;
+		$md_save_rules  = $str_rules;
+		$md_save_anchor = $fixed_heading_anchor;
+		$str_rules = array();
+		$fixed_heading_anchor = 0;
+	}
 	page_write($page, $postdata, $notimeupdate != 0 && $notimestamp);
+	if ($md_mode) {
+		$str_rules = $md_save_rules;
+		$fixed_heading_anchor = $md_save_anchor;
+	}
```

---

## 制限事項

- **`#contents` は Markdown ページ内の見出しのみ対象**です（league/commonmark の TableOfContents 拡張による生成）。他ページの見出しを収集する `#contentsx` 等のサードパーティプラグインの Markdown 対応は別途プラグイン側の改造が必要です
- `include`・`menu`（MenuBar）・`calendar_viewer` など **`convert_html()` を直接呼ぶプラグインから参照された場合**、`#md` ページは PukiWiki 記法として描画されます（`#md` の行は `plugin_md_convert()` により非表示）。必要に応じて各プラグインの差し替えで対応可能です
- 素の PukiWiki（本プラグインなし）で `#md` ページを**編集・保存**すると、`make_str_rules()` の自動整形（`*` 行へのアンカー付与等）が Markdown 本文に適用される可能性があります。閲覧のみであれば問題ありません
- 編集フォームは素の PukiWiki のまま（プレーンな textarea）です。EasyMDE 等のビジュアルエディタは含みません

---

## 使用ライブラリとライセンス

- **PukiWiki 1.5.4** — GPL v2 or later（`read.inc.php`・`edit.inc.php` は PukiWiki Development Team のコードの改変版、`md.inc.php` は [pukiwiki154_md](https://github.com/m0370/pukiwiki154_md) の変換ロジックを移植・再構成したもの。いずれも GPL v2+）
- **league/commonmark 2.x** — BSD-3-Clause（`plugin/markdown_parser/` に同梱。GFM・Footnote・HeadingPermalink・TableOfContents 拡張を使用）

本リポジトリ全体のライセンスは GPL v2 or later です（`LICENSE` 参照）。

---

## 更新履歴

- **v0.1**（2026-06-11）: 初版。Markdown描画（GFM・脚注・ページ内目次・改行反映）、`!plugin` 対応、変換キャッシュ、保存時のデータ保護
