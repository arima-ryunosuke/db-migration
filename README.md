DB Migration
====

## Description

マイグレーションツールです。
yaml や json, sql でのスキーマ・レコードの入出力と、データベース間で差分をとって DDL, DML を出力・実行します。

## Demo

```bash
sh demo/run.sh
```

## Usage

コマンドラインツールが付属しています。また、 phar もあります。
依存を避けるため phar の利用を推奨します。下記の記述例は phar が前提です。

export, import, migrate の3つのサブコマンドで構成されます。
役割は順に「出力・入力・マイグレーション」です。

作者の想定するユースケースは下記のとおりです。

1. 誰かがスキーマを変更して export して定義を commit/push
    - `php dbmigration.phar export mysql://127.0.0.1/dbname schema.yml`
2. 他の人が一時スキーマに import, その一時スキーマ差分対象にして migrate
    - `php dbmigration.phar import mysql://127.0.0.1/dbname_migration schema.yml`
    - `php dbmigration.phar migrate mysql://127.0.0.1/dbname mysql://127.0.0.1/dbname_migration schema.yml`

定義ファイルとの差分を取るので up/down という概念がありません。
それはそれで弊害もありますが、比較的簡易な操作でデータベースマイグレーションが可能になります。
`migration` オプションを利用すればほぼできないことはないはずです。

### export

第1引数の DSN を第2引数以降のファイルに出力します。

- e.g. `php dbmigration.phar export mysql://127.0.0.1/dbname schema.yml table1.yml table2.yml`

引数は下記。

```
Usage:
  export [options] [--] <srcdsn> <files> (<files>)...

Arguments:
  srcdsn                                   Specify source DSN.
  files                                    Specify database files. First argument is meaned schema

Options:
  -m, --migration[=MIGRATION]              Specify migration directory.
  -i, --include[=INCLUDE]                  Target tables pattern (enable comma separated value) (multiple values allowed)
  -e, --exclude[=EXCLUDE]                  Except tables pattern (enable comma separated value) (multiple values allowed)
  -w, --where[=WHERE]                      Where condition. (multiple values allowed)
  -g, --ignore[=IGNORE]                    Ignore column. (multiple values allowed)
      --table-directory[=TABLE-DIRECTORY]  Specify separative directory name for tables.
      --view-directory[=VIEW-DIRECTORY]    Specify separative directory name for views.
      --csv-encoding[=CSV-ENCODING]        Specify CSV encoding. [default: "SJIS-win"]
      --yml-inline[=YML-INLINE]            Specify YML inline nest level. [default: 4]
      --yml-indent[=YML-INDENT]            Specify YML indent size. [default: 4]
```

#### srcdsn

対象のデータベース DSN URL を指定します。具体的には下記のような文字列です。

- `mysql://user:pass@127.0.0.1/dbname?charset=utf8`

要するに DSN の各種パーツを URL に見立てたものです。

`user:pass@127.0.0.1` の部分を `user:@127.0.0.1` とすると入力プロンプトが表示されるようになります。
パスワードの不要の場合は `user@127.0.0.1` とします。

#### files

1つ目のファイルは DDL として出力されます。主にテーブル定義と外部キー制約です。
2つ目以降のファイルは DML として出力されます。要するにレコード配列です。

ファイル名とテーブル名が対応します（hoge.sql で hoge テーブルが対象）。

そのとき、拡張子で挙動が変わります。

- .sql プレーンな SQL で出力します
- .php php の配列で出力します。ファイルが既に存在し、Closure を返すファイルの場合はスキップされます
- .json json 形式で出力します
- .yaml yaml 形式で出力します
- .csv csv 形式で出力します（DML のみ）
- 上記以外は例外

さらに2重拡張子でエンコーディングが指定できます。
エンコーディング指定はファイル名とはみなされず、テーブル名に含まれません。

- hoge.sjis.csc SJIS の csv として扱います
- hoge.utf8.yaml UTF8 の yaml として扱います

#### --migration (-m)

マイグレーションテーブル名（ディレクトリ）を指定します。
指定されると basename が `--exclude` に追加されたような動作になります。

このオプションは下記 `migrate` との親和性のために存在します。
実質的には `--exclude` で指定した場合と全く同じです。

#### --include, --exclude

出力対象テーブルを指定します。正規表現です。

評価順位は `include` -> `exclude` です。`exclude` で指定したテーブルが出力されることはありません。
なお、`include` 未指定時はすべてのテーブルが対象になります。

#### --where (-w)

DML 差分対象の WHERE 文を指定します。
このオプションで指定したレコードがエクスポートされます。

`-w table.column = 99` のように指定するとそのテーブルのみで適用されます。
`-w column = 99` のように指定すると `column` カラムを持つ全てのテーブルで適用されます。
識別子はクオートしても構いません。

#### --ignore (-g)

無視するカラム名を指定します。
このオプションで指定したカラムは空文字列で出力されるようになります。

`-g table.column` のように指定するとそのテーブルのみで適用されます。
`-g column` のように指定すると `column` カラムを持つ全てのテーブルで適用されます。
識別子はクオートしても構いません。

#### --table-directory, view-directory

テーブルとビューの出力ディレクトリを指定します。
指定した場合、大本のスキーマファイル（第1引数）には include を示す識別子が含められ、内容としては含まれません。
テーブル・ビューごとにファイルを出力したい場合に便利ですが、下記の制約があります。

- php: 制約はありません。php ネイティブの `include` を用いて出力されます
- json: 文字列として出力し、あとからパースされます。したがって（まずあり得ないでしょうか）特殊なプレフィックス（!include）で始まるjsonファイルで誤作動する可能性があります
- yaml: php-yaml 拡張が必要です。インストールされていない場合このオプションは無視されます
- csv: そもそも対応していません

### import

第1引数の DSN に第2引数以降のファイルを取り込みます。
初期化での仕様を想定しており、**DSN の dbname は DROP DATABASE -> CREATE DATABASE されます。**

- e.g. `php dbmigration.phar import mysql://127.0.0.1/dbname schema.yml table1.yml table2.yml`

引数は下記。

```
Usage:
  import [options] [--] <dstdsn> <files> (<files>)...

Arguments:
  dstdsn                                   Specify destination DSN (if not exists create database).
  files                                    Specify database files. First argument is meaned schema.

Options:
  -m, --migration[=MIGRATION]              Specify migration directory.
      --table-directory[=TABLE-DIRECTORY]  Specify separative directory name for tables.
      --view-directory[=VIEW-DIRECTORY]    Specify separative directory name for views.
      --csv-encoding[=CSV-ENCODING]        Specify CSV encoding. [default: "SJIS-win"]
      --bulk-insert                        Enable bulk insert
      --format[=FORMAT]                    Format output SQL (none, pretty, format, highlight or compress. default pretty) [default: "pretty"]
  -o, --omit=OMIT                          Omit size for long SQL
```

#### dstdsn

対象のデータベース DSN URL を指定します。具体的には下記のような文字列です。

- `mysql://user:pass@127.0.0.1/dbname?charset=utf8`

要するに DSN の各種パーツを URL に見立てたものです。

`user:pass@127.0.0.1` の部分を `user:@127.0.0.1` とすると入力プロンプトが表示されるようになります。
パスワードの不要の場合は `user@127.0.0.1` とします。

#### files

1つ目のファイルは DDL として入力されます。主にテーブル定義と外部キー制約です。
2つ目以降のファイルは DML として入力されます。要するにレコード配列です。

ファイル名とテーブル名が対応します（hoge.sql で hoge テーブルが対象）。

そのとき、拡張子で挙動が変わります。

- .sql プレーンな SQL として入力されます。ファイル内容がそのまま実行されるため、レコード配列である必要はありません
- .php php の配列として入力されます。Closure を返す場合、その実行結果が行配列として読み込まれます。その際の引数は `\Doctrine\DBAL\Connection` です
- .json json 形式として入力されます
- .yaml yaml 形式として入力されます
- .csv csv 形式として入力されます（DML のみ）

さらに2重拡張子でエンコーディングが指定できます。
エンコーディング指定はファイル名とはみなされず、テーブル名に含まれません。

- hoge.sjis.csc SJIS の csv として扱います
- hoge.utf8.yaml UTF8 の yaml として扱います

#### --migration (-m)

マイグレーションテーブル名（ディレクトリ）を指定します。
指定されるとディレクトリ以下のマイグレーションファイルが全て当たった状態でインポートされます。

#### --table-directory, view-directory

export と同じです。テーブルとビューが格納されているディレクトリを指定します。

このオプション付きで export したファイルは同様にこのオプションを付けて import する必要があります。

### migrate

第1引数の DSN を第2引数の DSN にマイグレーションします。

- e.g. `php dbmigration.phar migrate mysql://127.0.0.1/src mysql://127.0.0.1/dst`

引数は下記。

```
Usage:
  migrate [options] [--] <srcdsn> <dstdsn>

Arguments:
  srcdsn                                   Specify source DSN.
  dstdsn                                   Specify destination DSN.

Options:
  -m, --migration[=MIGRATION]              Specify migration directory.
  -t, --type[=TYPE]                        Migration SQL type (ddl, dml. default both)
      --noview                             No migration View.
  -i, --include[=INCLUDE]                  Target tables pattern (enable comma separated value) (multiple values allowed)
  -e, --exclude[=EXCLUDE]                  Except tables pattern (enable comma separated value) (multiple values allowed)
  -w, --where[=WHERE]                      Where condition. (multiple values allowed)
  -g, --ignore[=IGNORE]                    Ignore column for DML. (multiple values allowed)
      --no-insert                          Not contains INSERT DML
      --no-delete                          Not contains DELETE DML
      --no-update                          Not contains UPDATE DML
      --callback[=CALLBACK]                Specify callback php files.
      --csv-encoding[=CSV-ENCODING]        Specify CSV encoding. [default: "SJIS-win"]
      --table-directory[=TABLE-DIRECTORY]  Specify separative directory name for tables.
      --view-directory[=VIEW-DIRECTORY]    Specify separative directory name for views.
  -c, --check                              Check only (Dry run. force no-interaction)
  -f, --force                              Force continue, ignore errors
      --format[=FORMAT]                    Format output SQL (none, pretty, format, highlight or compress. default pretty) [default: "pretty"]
  -o, --omit=OMIT                          Omit size for long SQL
```

#### srcdsn

変更対象のデータベース DSN URL を指定します。
この DSN が**変更対象**です。

#### dstdsn

差分対象のデータベース DSN URL を指定します。
この DSN は**あくまで差分対象であり、変更対象ではありません**。この DSN に変更操作は行われません。

#### --migration (-m)

マイグレーションテーブル名（ディレクトリ）を指定します。
ここで指定されたディレクトリ名の basename でテーブルが作成され、中のファイル（.sql, .php）が実行されます。

- migrations
  - 20170818.sql: `UPDATE t_table SET surrogate_key = CONCAT(pk1, "-", pk2)`
  - 20170819-1.sql: `UPDATE t_table SET new_column = UPPER(old_column)`
  - 20170819-2.sql: `INSERT INTO t_table_children SELECT children_id FROM t_table`
  - 20170821-1.php: `<?php $connection->delete('t_table1', ['delete_flg'=>1]); return 'DELETE t_table2 WHERE delete_flg = 1';`

このようなディレクトリ構成のときに `-m migrations` を渡すと `migrations` テーブルが自動で作成され、中のファイルが実行されます。ファイル名はなんでも構いません。
.sql はそのまま流されますが、 .php は eval されます。
その際、返り値がある場合は SQL 文として解釈し実行します。配列の場合は SQL 文の配列とみなします（実際には配列を返すことのほうが多いでしょう）。
また、 `$connection` というローカル変数が使用できます。これは `Doctrine\DBAL\Connection` のインスタンスで、マイグレーション対象コネクションです。
このインスタンスを使用してクエリを実行することも可能です。
さらに `Closure` を返した場合はそのクロージャが実行されます。引数は `Doctrine\DBAL\Connection` です。

まとめると .php の動作は下記です。

```php
<?php
// 返り値を利用するパターン（単一文字列でも配列でも良い）
return ['INSERT INTO t_table VALUES("hoge")', 'INSERT INTO t_table VALUES("fuga")'];
```

```php
<?php
// ローカル変数の $connection を直接利用するパターン
$connection->insert('t_table', [/* data array */]);
```

```php
<?php
// クロージャを返すパターン
return function ($connection) {
    // 引数の $connection を利用できる
    $connection->insert('t_table', [/* data array */]);
    // クロージャが返した文字列配列も実行される
    return ['INSERT INTO t_table VALUES("hoge")', 'INSERT INTO t_table VALUES("fuga")'];
};
```

上記の通り、クロージャ利用パターンの優位性はありません。クロージャパターンでできることはベタコードと return で実現可能です。
これは将来的に持ち回しがしやすくなることを想定して実装されています。
（例えばクロージャ単位でトランザクションをかける、DIを利用して引数を可変にする、などです）

一度当てたファイルは `migrations` テーブルに記録され、再マイグレーションしても実行されません。

このオプションは下記のような DDL, DML 差分でまかないきれない変更を救うときに有用です。

- 「新しく人工キー（既存レコードのとの組み合わせ）」が増えた
- 新カラムに既存データに基づくデフォルト値を入れたい
- 1対1テーブルが1対多に変更された（単一カラム管理から行管理になった）

このような変更は DDL, DML では管理しきれないため、差分適用後に何らかの SQL を実行する必要があります。
そういった事象を救うためのオプションです。

#### --include, --exclude

import と同じです。

#### --where (-w)

import と同じです。

#### --ignore (-g)

import と同じです。

#### --table-directory, view-directory

import と同じです。テーブルとビューが格納されているディレクトリを指定します。

#### --callback

マイグレーション前後のイベントコールバックファイルを指定します。

```php
<?php

return [
    'pre-migration'  => function (\Doctrine\DBAL\Connection conn) {
        echo 'pre-migration';
    },
    'post-migration' => function (\Doctrine\DBAL\Connection $conn) {
        echo 'post-migration';
    },
];
```

このようなファイルを用意してこのオプションでファイル名を渡すと指定したクロージャが呼び出されるようになります。
いまのところ上記の2イベントしか存在しません。

mysql で `SET FOREIGN_KEY_CHECKS = 0` `SET FOREIGN_KEY_CHECKS = 1` したい場合などに使用します。

#### --check

いわゆる dryrun です。差分の出力のみを行います。

## Install

```json
{
    "require": {
        "ryunosuke/db-migration": "dev-master"
    }
}
```

## Licence

[MIT](https://raw.githubusercontent.com/arima-ryunosuke/db-migration/master/LICENSE)

## Author

[arima-ryunosuke](https://github.com/arima-ryunosuke)
