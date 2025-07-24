# RELEASE

バージョニングはセマンティックバージョニングでは**ありません**。

| バージョン   | 説明
|:--           |:--
| メジャー     | 大規模な仕様変更の際にアップします（クラス構造・メソッド体系などの根本的な変更）。<br>メジャーバージョンアップ対応は多大なコストを伴います。
| マイナー     | 小規模な仕様変更の際にアップします（中機能追加・メソッドの追加など）。<br>マイナーバージョンアップ対応は1日程度の修正で終わるようにします。
| パッチ       | バグフィックス・小機能追加の際にアップします（基本的には互換性を維持するバグフィックス）。<br>パッチバージョンアップは特殊なことをしてない限り何も行う必要はありません。

なお、下記の一覧のプレフィックスは下記のような意味合いです。

- change: 仕様変更
- feature: 新機能
- fixbug: バグ修正
- refactor: 内部動作の変更
- `*` 付きは互換性破壊

## x.y.z

- choice, confirm を Symfony 標準のものに差し替える？
- DoctrineEventManager が廃止になるようなので撤廃する

## 3.2.2

- Merge tag 'v3.1.13'

## 3.2.1

- Merge tag 'v3.1.12'

## 3.2.0

- package update
  - [*change] スキーム名が厳密になった（pdo_mysql -> pdo-mysql）
  - [*change] 型が厳密になった（comment の NOT NULL, customSchemaOptions の廃止など）
  - [*change] 一部のイベントの廃止（onSchemaCreateTable）
  - [*change] maintain-type のデフォルト値を true に変更

## 3.1.14

- [feature] dump に no-autoincrement オプションを追加
- [composer] update
  - 3.10.0

## 3.1.13

- [*change] dump の php 対応と引数体系の変更
- [fixbug] 4096 の境界で文字化けする不具合
- [fixbug] CSV 以外でも utf8 で BOM 判定されていた
- [feature] migration の apply を Generator に変更
- [feature] writeRecords を Generator に変更
- [refactor] ログで使いたいので insert/upsert 文の生成と実行を分離

## 3.1.12

- [feature] dump コマンド追加
- [feature] Logger に簡易プログレス機能を追加
- [feature] バイナリ型の対応
- [feature] sql ファイルの multiline 対応
- [feature] 追加書き込み対応
- [refactor] readyTable/View を汎用的な readyObject に変更

## 3.1.11

- [bin] box を v4 系に変更
- [fixbug] php8.2 でのエラーを修正

## 3.1.10

- [fixbug] int で precision が出力される不具合を修正

## 3.1.9

- [composer] update
  - doctrine で BC break な変更があったっぽいので
- [feature] データ記述言語での migration を upsert に変更

## 3.1.8

- [composer] update
  - 式デフォルト対応

## 3.1.7

- [composer] update
  - Trigger の変更の前後に LOCK/UNLOCK TABLES を実行
    - Event,Routine はまぁ良いとして Trigger は間隙でも実行されないと困ることがある（エラーにもならないし）

## 3.1.6

- [composer] update
  - DEFINER, TABLE,COLUMN 以外ので差分コメント等

## 3.1.5

- [feature] check コマンドの結果に主キーを追加

## 3.1.4

- [fixbug] mysql の カラム順が維持されない不具合を修正

## 3.1.3

- [feature] import コマンドの migration で当てる当てないを選択可能にした
- [fixbug] migration で quote が使用されない不具合を修正

## 3.1.2

- [fixbug] json/yaml をマイグレーションデータとして使うときにエラーになる不具合を修正
- [fixbug] Platform が異なるインスタンスになる不具合を修正
- [feature] migrate コマンドに exclude オプションを追加
- [feature] 整合性チェックコマンド check を追加
- [feature] 型付 csv を追加
- [refactor] TableScanner の返り値に統一感がなかったので Generator に統一

## 3.1.1

- [feature] 型を維持させる maintain-type を追加
- [fixbug] config オプションで引数の指定が効いていない不具合を修正

## 3.1.0

- [feature][Command] mysqli も使用できるように暫定対応
- [feature][Command] disable-constraint を追加（mysql のみ）
- [feature][Command] グローバルロック機構を導入（mysql のみ）
- [feature][Command] ddl ファイルのスキップ対応
- [feature][Command] transaction オプションを追加
- [feature][Command] glob パターンでテーブルを漁る機能
- [*feature][all] DML の取得の Generator 対応
- [*feature][Csv] BOM 対応
- [*feature][Csv] NULL 対応
- [feature][TableScanner] bulk の chunk size を指定可能に変更
- [feature][Transporter] view の スキーマ修飾を取り除く機能
- [feature][Transporter] 差分コメントを有効化

## 3.0.0

- ストアド/イベントに対応
- DB<->DB の比較ではなく DB<->FILE の比較に変更
- Directory 設定を共通に変更
- Enabled 設定を共通に変更
- inline/indent 設定を共通に変更
- ファイルタイプをクラスに分離
  - tsv 追加
  - yaml5 追加

## 2.0.15

- [feature] [MigrationTable] 実行した SQL のログ機能を実装
- [feature] [MigrationTable] ALTER 対応

## 2.0.14

- [feature] 内部を doctrine 3.3 に変更
  - FULLTEXT や 生成列に対応したはず

## 2.0.13

- [fixbug] no bulk モードで sql が実行できない不具合を修正

## 2.0.12

- [refactor] クエリを実行しないように変更
- [fixbug] テーブル追加時にトリガーが2回吐かれる不具合を修正
- [fixbug] 確認前に DB が吹き飛んでいた不具合を修正
- [refactor] composer update

## 2.0.11

- [fixbug] mysql で AUTO_INCREMENT の差分が出てしまう不具合を修正

## 2.0.10

- [feature] event オプションで doctrine イベントを登録可能にした
- [change] event オプションで事足りるため callback オプションの非推奨化

## 2.0.9

- [fixbug] autoincrement がスキーマ定義に出る不具合を修正
- [fixbug] レコードファイルが空の時に Warning が出る不具合を修正

## 2.0.8

- bump version
  - php: 7.4
  - doctrine: 3.*
  - box: 3.*

## 2.0.7

- [feature] 全コマンドに config(C) オプションを追加

## 2.0.6

- php:7.2
- 依存ライブラリの更新
- import に include, exclude オプションを追加

## 2.0.5

- [refactor] 内部クラスの構成を変更
- [refactor] phpunit のバージョンアップ
- [feature] ファイルのサフィックスでエンコーディングを明示する機能を実装
- [change] php/json の出力フォーマットを修正

## 2.0.4

- [feature] $HOME/.my.cnf の読み込みに対応
- [fixbug] utf8mb4 で charset が utf8 になる不具合を修正

## 2.0.3

- [feature] トリガー対応

## 2.0.2

- [feature] migration の affected rows を表示するように変更

## 2.0.1

- [feature] パスワード入力プロンプトに対応

## 2.0.0

- [*change] php 7.1 未満を切り捨て
- [*change] サブコマンドを分離
