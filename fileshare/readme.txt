=== FileShare ===
Contributors:      sherpa
Tags:              file sharing, upload, download, secure, password
Requires at least: 6.2
Tested up to:      6.5
Requires PHP:      8.1
Stable tag:        1.0.0
License:           GPLv2 or later
License URI:       https://www.gnu.org/licenses/gpl-2.0.html

ドラッグ&ドロップ対応の安全なファイル共有プラグイン。ダウンロード ID + パスワード認証、回数制限、自動クリーンアップ、IP ブルートフォース対策、レート制限を備えます。

== Description ==

FileShare は WordPress でセキュアなファイル共有を実現するプラグインです。

主な機能:

* **ファイルアップロード** — ドラッグ&ドロップ対応。複数ファイルは自動的に ZIP に圧縮されます。
* **ファイル管理** — 保存ファイルの一覧表示・削除（管理画面）。
* **安全なダウンロード** — ダウンロード ID + パスワードによる認証。
* **ダウンロード回数制限** — ファイルごとに最大ダウンロード回数を設定可能（0 = 無制限）。
* **自動クリーンアップ** — 期限切れ・回数超過のファイルを WP-Cron でバックグラウンド削除。
* **IP ブルートフォース対策** — 連続ログイン失敗による IP ブロック。
* **レート制限** — エンドポイント単位のアクセス制限（flask-limiter 相当を PHP/SQLite で実装）。
* **ダークテーマ UI** — GitHub ライクな見やすいインターフェース。

== 動作環境 ==

* WordPress 6.2 以上
* PHP 8.1 以上
* PHP の sqlite3 拡張機能（ログ機能で使用）
* PHP の zip 拡張機能（複数ファイルの圧縮で使用）

== 使い方 ==

1. プラグインを有効化します。
2. 管理メニュー「FileShare」からファイルをアップロードします（パスワード・回数制限・保存期間を指定可能）。
3. アップロード後に表示される「ダウンロード ID」「パスワード」「ダウンロードページ URL」を相手に共有します。
4. ダウンロードページは専用URL（`?fileshare_download=1`）で提供され、固定ページの設置は不要です。
   * アップロード結果の URL は `?fileshare_download=1&fsid=XXXX` 形式で、ID が自動入力されます。
   * `fsid` を付けない `?fileshare_download=1` を開くと、ID とパスワードを手入力できます。

== セキュリティ設計 ==

* ファイル本体は `wp-content/uploads/fileshare-files/` にランダム名で保存し、`.htaccess` で直接アクセスを遮断します。
* ダウンロードは PHP 経由で配信し、認証成功時のみ短命の HMAC トークンを発行します。
* パスワードは `password_hash()`（bcrypt）で保存します。
* 連続したパスワード失敗で IP を一時ブロックします（既定: 15 分以内に 5 回失敗で 30 分ブロック）。
* ログイン試行・ダウンロード・ブロックは SQLite に記録され、管理画面で確認できます。

== Changelog ==

= 1.0.0 =
* 初回リリース。
