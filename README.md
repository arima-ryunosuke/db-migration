DB Migration
====

## Description

マイグレーションツールです。
yaml や json でのスキーマ・レコードと、データベース間で差分をとって yaml や json で DDL, DML を 出力・実行します。

## Install

```bash
wget https://github.com/arima-ryunosuke/db-migration/raw/master/dbmigration.phar
```

## Demo

```bash
sh /path/to/clonedir/demo/run.sh
```

## Usage

コマンドラインツールが付属しています。また、 phar もあります。
依存を避けるため phar の利用を推奨します。下記の記述例は phar が前提です。

export, import, migrate, check の4つのサブコマンドで構成されます。
役割は順に「出力・入力・マイグレーション・整合チェック」です。

作者の想定するユースケースは下記のとおりです。

1. 誰かがスキーマを変更して export して定義を commit/push
    - `php dbmigration.phar export mysql://127.0.0.1/dbname schema.yml`
2. 他の人が fetch/pull して migrate して差分を適用
    - `php dbmigration.phar migrate mysql://127.0.0.1/dbname schema.yml`
3. 新規アサイン者は import して一括適用
    - `php dbmigration.phar import mysql://127.0.0.1/dbname schema.yml`

定義ファイルとの差分を取るので up/down という概念がありません。
それはそれで弊害もありますが、比較的簡易な操作でデータベースマイグレーションが可能になります。
`migration` オプションを利用すればほぼできないことはないはずです。

### 3コマンド共通・頻出パラメータ

#### dsn

対象のデータベース DSN URL を指定します。具体的には下記のような文字列です。

- `mysql://user:pass@127.0.0.1/dbname?charset=utf8`

要するに DSN の各種パーツを URL に見立てたものです。

`user:pass@127.0.0.1` の部分を `user:@127.0.0.1` とすると入力プロンプトが表示されるようになります。
パスワードの不要の場合は `user@127.0.0.1` とします。

#### files

1つ目のファイルは DDL として扱われます。要するに Table 定義や View 定義などです。
2つ目以降のファイルは DML として扱われます。要するにレコード配列です。

DML はファイル名とテーブル名が対応します（hoge.sql で hoge テーブルが対象）。

そのとき、拡張子で挙動が変わります。

- .sql: プレーンな SQL で入出力します
- .csv: csv 形式で入出力します（DML のみ）。`delimiter` でセパレータが指定できます
- .tsv: tsv 形式で入出力します（DML のみ）。単に `delimiter` 指定が効かない CSV として振る舞います
- .php: php の配列で入出力します。ファイルが既に存在し、Closure を返すファイルの場合、出力はスキップされます
- .json: json 形式で入出力します
- .yml, .yaml: yaml 形式で入出力します
- .yml5, .yaml5: フロースタイルの yaml 形式で入出力します
- 上記以外: すべて例外

export コマンドのときは glob パターンを渡すと存在するテーブルからファイル名部分で glob するような挙動になります。
この glob 処理は、先頭に `!` を付けると否定になる特殊処理が為されます。
例えば

- `t_*_user.json`: `t_admin_user`, `t_end_user` などのみ
- `!t_*log.json`: `t_login_log`, `t_access_log` など以外
- `t_*log.json !t_blog.json`: `t_login_log`, `t_access_log` などだが `t_blog` は除外

となります。
なお、 glob の関係上、テーブルを漁る場合は `"t_*_user.json"` のようにクォートした方が無難です。

sql は DDL の差分には対応していません。
`スキーマ => sql`, `sql => スキーマ` は dotrine の機能で簡単に実現できますが、 `sql <=> スキーマ` で差分を取るのは多くの場合不可能で、一時スキーマが必要になります。
前バージョンまでは一時スキーマを作って対応していましたが、あまり対象 DB 以外に接続したくないため、非対応としています。

さらに2重拡張子でエンコーディングが指定できます。これがない場合は内部エンコーディングが使われます。
エンコーディング指定はファイル名とはみなされず、テーブル名に含まれません。

- hoge.sjis.csv: SJIS の csv として扱います
- hoge.utf8.csv: BOM の扱いはファイルタイプに任せます（過程も推測もしません）
- hoge.utf8n.csv: BOM 無し UTF8 の csv として扱います
- hoge.utf8s.csv: BOM 有り UTF8 の csv として扱います

`utf8` で BOM が関係するのは CSV だけです。
他の json や yaml などでは `utf8`, `utf8n` の違いはありません。

#### --directory (-d)

DDL のオブジェクトの入出力ディレクトリを指定します。
指定した場合、大本のスキーマファイル（第1引数）には include を示す識別子が含められ、内容としては含まれません。
オブジェクトごとにファイルを出力したい場合に便利ですが、下記の制約があります。

- sql: 対応していません
- csv, tsv: そもそも DDL に対応していません
- php: 制約はありません。php ネイティブの `include` が用いられます
- json: 文字列として出力し、あとからパースされます。したがって（まずあり得ないでしょうか）特殊なプレフィックス（!include）で始まるjsonファイルで誤作動する可能性があります
- yml, yaml, yaml5: !include タグが用いられます

このオプションを用いて export したファイルは同様にこのオプションを用いて import,migrate する必要があります。

#### --maintain-type (--no-maintain)

型をできるだけ維持するようにします。

export の時、 DB の int や float はそのまま yaml や json の値になります。

import の時、 yaml や json の型を見て bool や int はそのまま SQL 化されます（quote されないということです）。
csv の場合は型がないので注意です。

#### --migration (-m)

マイグレーションテーブル名（ディレクトリ）を指定します。
ここで指定されたディレクトリ名の basename でテーブルが作成され、中のファイルが実行されます。

- sql: プレーンな SQL として入力されます。ファイル内容がそのまま実行されるため、レコード配列である必要はありません
- csv, tsv: 各フィールドのヘッダを `table_name.column_name` とみなしてデータ配列とみなし、tablename にインサートが実行されます。つまり DML のみの対応です
- php: php の配列として入力されます。Closure を返す場合、その実行結果が行配列として読み込まれます。その際の引数は `\Doctrine\DBAL\Connection` です
- json: 単純に SQL 配列を表している場合はそれが実行されます。 `{tablename: [{records}, ...]}` のような場合はデータ配列とみなし、tablename にインサートが実行されます
- yml, yaml, yaml5: json と同じです

例えば

- migrations
  - 20170818.sql: `UPDATE t_table SET surrogate_key = CONCAT(pk1, "-", pk2)`
  - 20170819-1.sql: `UPDATE t_table SET new_column = UPPER(old_column)`
  - 20170819-2.sql: `INSERT INTO t_table_children SELECT children_id FROM t_table`
  - 20170821-1.php: `<?php return ['INSERT INTO t_table VALUES("hoge")', 'INSERT INTO t_table VALUES("fuga")'];`
  - 20170821-2.php: `<?php return function ($connection) { $connection->delete('t_table1', ['delete_flg'=>1]); };`

このようなディレクトリ構成のときに `-m migrations` を渡すと `migrations` テーブルが自動で作成され、中のファイルが実行されます。ファイル名はなんでも構いません。
.sql はそのまま流されますが、 .php は実行されます。
その際、返り値がある場合は SQL 文として解釈し実行します。配列の場合は SQL 文の配列とみなします（実際には配列を返すことのほうが多いでしょう）。
さらに `Closure` を返した場合はそのクロージャが実行されます。引数は `Doctrine\DBAL\Connection` です。

まとめると .php の動作は下記です。

```php
<?php
// 返り値を利用するパターン（単一文字列でも配列でも良い）
return ['INSERT INTO t_table VALUES("hoge")', 'INSERT INTO t_table VALUES("fuga")'];
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

一度当てたファイルは `migrations` テーブルに記録され、再マイグレーションしても実行されません。

このオプションは下記のような DDL, DML 差分でまかないきれない変更を救うときに有用です。

- 「新しく人工キー（既存レコードのとの組み合わせ）」が増えた
- 新カラムに既存データに基づくデフォルト値を入れたい
- 1対1テーブルが1対多に変更された（単一カラム管理から行管理になった）

このような変更は DDL, DML では管理しきれないため、差分適用後に何らかの SQL を実行する必要があります。
そういった事象を救うためのオプションです。

export/dump 時の指定は大きな意味はありません。 `exclude` と同じに意味になります（マイグレーションテーブルも出力されてしまうため）。
import 時は当てるか当てないかの選択肢が表示されます。初期化を想定したコマンドのため、デフォルトは "p"(当てた扱いにする) です。

#### --transaction (-T)

各種操作時のトランザクションを指定します。

- 0: トランザクションを開きません
- 1: 各操作開始時のトランザクションを開きます
- 2: 各操作のテーブル毎にトランザクションを開きます

export のときは 1 を指定すると全体の読み取り一貫性が維持できます。
import, migrate のときに 1 を指定すると一度でも失敗したときにすべてがなかったことになります。
2 を指定するとそのテーブルは巻き戻されますが、それまでの操作は確定されます。

なお、 DDL はトランザクションが効かず、変更の巻き戻しができないことに注意してください。

#### --inline, --indent, --multiline, --align, --delimiter, --format

出力時のフォーマット動作を指定します。

- `inline`: 指定された数以上のネストをインラインで出力します（php/json/yaml）
- `indent`: 空白インデント数です（sql/php/json/yaml）
- `multiline`: 複数行をリテラル的に出力します（php/sql/yaml）。.php の場合は nowdoc, sql の場合は bulk-insert, yaml の場合は `|-` で出力されます。yaml5 は対応していません
- `align`: key と value の桁合わせフラグです（php/json/yaml）。php だけで指定される想定です。json, yaml で桁合わせは一般的ではないでしょう
- `delimiter`: .sql の場合は SQL の区切り文字、 .csv の場合は CSV のセパレータとして振る舞います。その他の場合は使用されません
- `format`: .sql のときのみ有効です。コンソールにログ出力される際のフォーマットを指定します

これらは原則として `export` コマンドでの使用ですが、一部のオプションは画面に出力されるログの形式にも影響します。
なので共通的なオプションになっています。

sql で `delimiter` に `;` （デフォルト）以外を指定すると**アプリケーションレイヤーで分割して**複数のクエリを実行します。
`;` 指定だと複文として扱い、そのままクエリが実行されます。
複文の挙動は設定やエミュレーションモードに左右されるため、トリガーやファンクション定義で `;` を含む場合は注意が必要です。

普通に使う分には `export` 以外で指定することはありません。

#### --event (-E)

Doctrine イベントが記述された php ファイルを指定します。
`[EventName => Event]` 形式の配列か、それを返すようなクロージャを記述します。
クロージャの場合は実行コマンドとコネクションが引数で渡ってきます。
`Event` は配列でも構いません。

```
<?php
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Event\ConnectionEventArgs;
use Doctrine\DBAL\Event\Listeners\SQLSessionInit;
use Doctrine\DBAL\Event\SchemaCreateTableEventArgs;
use Doctrine\DBAL\Events;
use Symfony\Component\Console\Command\Command;

return function (Command $command, Connection $connection) {
    return [
        'pre-migration'             => function (ConnectionEventArgs $args) {
            $connection->executeStatement("SET FOREIGN_KEY_CHECKS = 0");
        },
        'post-migration'            => function (ConnectionEventArgs $args) {
            $connection->executeStatement("SET FOREIGN_KEY_CHECKS = 1");
        },
        Events::postConnect         => [
            new SQLSessionInit("SET FOREIGN_KEY_CHECKS = 0"),
        ],
        Events::onSchemaCreateTable => new class() {
            public function onSchemaCreateTable(SchemaCreateTableEventArgs $args) { /* ... */ }
        },
    ];
};

// OR

return [
    'pre-migration'             => function (ConnectionEventArgs $args) {
        $connection->executeStatement("SET FOREIGN_KEY_CHECKS = 0");
    },
    'post-migration'            => function (ConnectionEventArgs $args) {
        $connection->executeStatement("SET FOREIGN_KEY_CHECKS = 1");
    },
    Events::postConnect         => [
        function (ConnectionEventArgs $args) {
            $args->getConnection()->executeStatement("SET FOREIGN_KEY_CHECKS = 0");
        },
    ],
    Events::onSchemaCreateTable => function (SchemaCreateTableEventArgs $args) { /* ... */ },
];
```

詳細は Doctrine のドキュメントに譲りますが、本ツールはマイグレーションツールなので `on～` を指定する機会はほぼありません。
`postConnect` で接続時のイベントを指定するのが最も多いユースケースでしょう。

また、上記のように独自イベントとして `pre-migration` `post-migration` イベントが存在します。
これは migrate コマンドの際にマイグレーションの前後で実行されるイベントです。

なお、イベントは単純にクロージャでも構いません。
内部で自動的に Doctrine イベントリスナに変換します。

#### --config (-C)

上記のオプションを連想配列で記述した php ファイルで指定できます。

下記のような連想配列を `config.php` のようなファイル名で保存して `-C config.php` オプションを付けると適用されます。

```php
<?php return [
    // デフォルト値です。下記のコマンド固有のデフォルト値として使用されます
    'default' => [
        0           => 'このように数値キーで指定した場合は引数のデフォルト値になります',
        1           => [
            '引数が配列を受け入れる場合はこのように配列で指定します',
        ],
        'migration' => 'migration オプションのデフォルト値です',
        'include'   => [
            'オプションが配列を受け入れる場合はこのように配列で指定します',
        ],
    ],
    // export コマンドのデフォルト値です
    'export'  => [],
    // import コマンドのデフォルト値です
    'import'  => [],
    // migrate コマンドのデフォルト値です
    'migrate' => [],
];
```

オプションの優先順位は `コマンドのデフォルト値 < config ファイル（default） < config ファイル（command） < コマンドライン引数 ` となります。
端的に言えば「指定しなかったときのデフォルト値を変更するファイル」となります。

### export

第1引数の DSN を第2引数以降のファイルに出力します。

- e.g. `php dbmigration.phar export mysql://127.0.0.1/dbname schema.yml table1.yml table2.yml`

引数は下記。

```
Description:
  Export to DDL,DML files.

Usage:
  export [options] [--] [<dsn> [<files>...]]

Arguments:
  dsn                                     Specify target DSN.
  files                                   Specify database files. First argument is meaned schema

Options:
      --maintain-type|--no-maintain-type  Maintain type as much as possible.
  -d, --directory[=DIRECTORY]             Specify separative directory name.
  -m, --migration[=MIGRATION]             Specify migration directory.
  -T, --transaction[=TRANSACTION]         Specify transaction nest level (0 is not transaction, 1 is only top level, 2 is only per-table) [default: 1]
  -D, --disable[=DISABLE]                 Specify disabled schema object (enable comma separated value. e.g. --disable view,trigger) (multiple values allowed)
  -i, --include[=INCLUDE]                 Target tables pattern (enable comma separated value. e.g. --include table1,table2) (multiple values allowed)
  -e, --exclude[=EXCLUDE]                 Except tables pattern (enable comma separated value. e.g. --exclude table1,table2) (multiple values allowed)
  -w, --where[=WHERE]                     Where condition. (multiple values allowed)
  -g, --ignore[=IGNORE]                   Ignore column. (multiple values allowed)
      --inline[=INLINE]                   Specify php/json/yaml inline nest level. [default: 4]
      --indent[=INDENT]                   Specify php/json/yaml indent size. [default: 4]
      --multiline                         Specify php/sql/yaml literal multiline.
      --align                             Specify php/json/yaml align key value.
      --delimiter=DELIMITER               Specify sql/csv delimiter.
  -E, --event[=EVENT]                     Specify Event filepath
  -C, --config[=CONFIG]                   Specify Configuration filepath
  -h, --help                              Display help for the given command. When no command is given display help for the list command
  -q, --quiet                             Do not output any message
  -V, --version                           Display this application version
      --ansi|--no-ansi                    Force (or disable --no-ansi) ANSI output
  -n, --no-interaction                    Do not ask any interactive question
  -v|vv|vvv, --verbose                    Increase the verbosity of messages: 1 for normal output, 2 for more verbose output and 3 for debug

Help:
  Export to DDL,DML files based on extension.
   e.g. `dbmigration export mysql://user:pass@localhost/dbname schema.yml table1.yml table2.yml`
```

#### --disable (-D)

出力するスキーマオブジェクトを指定します。
現在対応しているのは

- table
- view
- trigger
- routine (procedure/function)
- event

です。未指定の場合上記がすべて出力されます。

#### --include, --exclude

スキーマとして出力する対象オブジェクト名を指定します。正規表現です。

評価順位は `include` -> `exclude` です。`exclude` で指定したオブジェクトが出力されることはありません。
なお、`include` 未指定時はすべてのオブジェクトが対象になります。

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

### import

第1引数の DSN に第2引数以降のファイルを取り込みます。
初期化での仕様を想定しており、**DSN の dbname は DROP DATABASE -> CREATE DATABASE されます。**

- e.g. `php dbmigration.phar import mysql://127.0.0.1/dbname schema.yml table1.yml table2.yml`

引数は下記。

```
Description:
  Import from DDL,DML files.

Usage:
  import [options] [--] [<dsn> [<files>...]]

Arguments:
  dsn                                     Specify target DSN (if not exists create database).
  files                                   Specify database files. First argument is meaned schema.

Options:
      --disable-constraint                Disable constraint (e.g. foreign key, unique, etc)
      --maintain-type|--no-maintain-type  Maintain type as much as possible.
  -d, --directory[=DIRECTORY]             Specify separative directory name.
  -m, --migration[=MIGRATION]             Specify migration directory.
  -T, --transaction[=TRANSACTION]         Specify transaction nest level (0 is not transaction, 1 is only top level, 2 is only per-table) [default: 1]
      --bulk-insert[=BULK-INSERT]         Specify bulk insert chunk size
      --inline[=INLINE]                   Specify php/json/yaml inline nest level. [default: 4]
      --indent[=INDENT]                   Specify php/json/yaml indent size. [default: 4]
      --delimiter=DELIMITER               Specify sql/csv delimiter.
  -f, --force                             Force continue, ignore errors
      --yield                             Specify sql/json/yaml generator mode.
      --format[=FORMAT]                   Format output SQL (none, pretty, format. default pretty) [default: "pretty"]
  -o, --omit=OMIT                         Omit size for long SQL
  -E, --event[=EVENT]                     Specify Event filepath
  -C, --config[=CONFIG]                   Specify Configuration filepath
  -h, --help                              Display help for the given command. When no command is given display help for the list command
  -q, --quiet                             Do not output any message
  -V, --version                           Display this application version
      --ansi|--no-ansi                    Force (or disable --no-ansi) ANSI output
  -n, --no-interaction                    Do not ask any interactive question
  -v|vv|vvv, --verbose                    Increase the verbosity of messages: 1 for normal output, 2 for more verbose output and 3 for debug

Help:
  Import from DDL,DML files based on extension.
   e.g. `dbmigration import mysql://user:pass@localhost/dbname schema.yml table1.yml table2.yml`
```

#### --disable-constraint

取り込み中、外部キー等の制約を無視します。

#### --bulk-insert

実行される INSERT 文を バルクにするオプションです。

指定しないとすべての INSERT 文は個別に実行されます。
`--bulk-insert` とすると同じテーブルの INSERT は bulk で実行されます。
`--bulk-insert 100` とすると同じテーブルの INSERT は 100 行ごとに bulk で実行されます。

#### --yield

ファイルを一括で読むのではなく、逐次読み込みでレコードを処理します。
データが巨大な場合に、メモリ不足に陥らずに処理できます。

csv だけは指定しなくても遅延読み込みです。
php は include した瞬間すべてがメモリに載ってしまうため完全に非対応です。

この機能は作者が必要に迫られて実装したもので実験的です。
いわゆる json/yaml stream であり、素朴な実装になっているため、速度も遅く、エラーも出やすいです。

### migrate

第1引数の DSN を第2引数のスキーマ定義にマイグレーションします。

- e.g. `php dbmigration.phar migrate mysql://127.0.0.1/dbname schema.yml table1.yml table2.yml`

引数は下記。

```
Description:
  Migrate DDL,DML from files.

Usage:
  migrate [options] [--] [<dsn> [<files>...]]

Arguments:
  dsn                                     Specify target DSN.
  files                                   Specify database files. First argument is meaned schema.

Options:
      --disable-constraint                Disable constraint (e.g. foreign key, unique, etc)
      --maintain-type|--no-maintain-type  Maintain type as much as possible.
  -d, --directory[=DIRECTORY]             Specify separative directory name.
  -m, --migration[=MIGRATION]             Specify migration directory.
  -T, --transaction[=TRANSACTION]         Specify transaction nest level (0 is not transaction, 1 is only top level, 2 is only per-table) [default: 1]
  -t, --type[=TYPE]                       Migration SQL type (ddl, dml. default both)
      --dml-type[=DML-TYPE]               Specify dml type (enable comma separated value. e.g. --dml-type insert,update) [default: ["insert","update","delete"]] (multiple values allowed)
  -e, --exclude[=EXCLUDE]                 Except tables pattern (enable comma separated value. e.g. --exclude table1,table2) (multiple values allowed)
  -g, --ignore[=IGNORE]                   Ignore column. (multiple values allowed)
      --bulk-insert[=BULK-INSERT]         Specify bulk insert chunk size
      --inline[=INLINE]                   Specify php/json/yaml inline nest level. [default: 4]
      --indent[=INDENT]                   Specify php/json/yaml indent size. [default: 4]
      --delimiter=DELIMITER               Specify sql/csv delimiter.
  -c, --check                             Check only (Dry run. force no-interaction)
  -f, --force                             Force continue, ignore errors
      --yield                             Specify sql/json/yaml generator mode.
      --format[=FORMAT]                   Format output SQL (none, pretty, format. default pretty) [default: "pretty"]
  -o, --omit=OMIT                         Omit size for long SQL
  -E, --event[=EVENT]                     Specify Event filepath
  -C, --config[=CONFIG]                   Specify Configuration filepath
  -h, --help                              Display help for the given command. When no command is given display help for the list command
  -q, --quiet                             Do not output any message
  -V, --version                           Display this application version
      --ansi|--no-ansi                    Force (or disable --no-ansi) ANSI output
  -n, --no-interaction                    Do not ask any interactive question
  -v|vv|vvv, --verbose                    Increase the verbosity of messages: 1 for normal output, 2 for more verbose output and 3 for debug

Help:
  Migrate dsn from files.
   e.g. `dbmigration migrate mysql://srchost/dbname table1.yml data.yml`
```

#### --disable-constraint

import と同じです。

#### --type (-t)

実行される SQL のタイプを指定します。デフォルトでは DDL, DML の両方が実行されます。
このプションと `files` の組み合わせで挙動が変わります。

- `--type both file1.php file2.php`
  - `file1.php` が DDL ファイルとして実行され、`file2.php` が DML ファイルとして実行されます
- `--type ddl file1.php file2.php`
  - `file1.php` が DDL ファイルとして実行されます。`file2.php` は単に無視されます
- `--type dml file1.php file2.php`
  - `file1.php` と `file2.php` が DML ファイルとして実行されます。DDL は実行されません

#### --dml-type

実行される DML のタイプを指定します。

- insert: 挿入が実行されます
- update: 更新が実行されます
- delete: 削除が実行されます

デフォルトはすべて指定されています。
つまり上記の3タイプがすべて実行されます。

#### --exclude (-e)

export と同じですが、include はありません。
include は単に files から定義を削ればいいからです。

フレームワークやライブラリが自動でテーブルを作成することはよくあり、そういったものを除外しないと `DROP TABLE` が走ることがあります。
管理下に居ないためです。
このオプションで除外することで管理下から除くことができます。

#### --ignore (-g)

import と同じです。

#### --bulk-insert

import と同じです。

#### --check

いわゆる dryrun です。差分の出力のみを行います。

#### --force

エラー専用の `--no-interaction` のようなもので、エラーが出ても確認を出さずに突き進みます。
流れる SQL に次第では危険なオプションです。

#### --yield

import と同じです。
同じですが、存在するレコードとの主キー比較のために一旦配列に直されるため、実質的に意味はありません。
このオプションは親和性のために用意されています。

### check

第1引数の DSN で整合性に違反するようなレコードがないかチェックします。
現在のところ外部キーのチェックのみが実装されています。

- e.g. `php dbmigration.phar check mysql://127.0.0.1/dbname

引数は下記。

```
Description:
  Check constraint.

Usage:
  check [options] [--] [<dsn>]

Arguments:
  dsn                      Specify target DSN.

Options:
  -i, --include[=INCLUDE]  Target tables pattern (enable comma separated value. e.g. --include table1,table2) (multiple values allowed)
  -e, --exclude[=EXCLUDE]  Except tables pattern (enable comma separated value. e.g. --exclude table1,table2) (multiple values allowed)
      --indent[=INDENT]    Specify php/json/yaml indent size. [default: 4]
  -E, --event[=EVENT]      Specify Event filepath
  -C, --config[=CONFIG]    Specify Configuration filepath
  -h, --help               Display help for the given command. When no command is given display help for the list command
  -q, --quiet              Do not output any message
  -V, --version            Display this application version
      --ansi|--no-ansi     Force (or disable --no-ansi) ANSI output
  -n, --no-interaction     Do not ask any interactive question
  -v|vv|vvv, --verbose     Increase the verbosity of messages: 1 for normal output, 2 for more verbose output and 3 for debug

Help:
  Check constraint (e.g. foreign key).
   e.g. `dbmigration check mysql://user:pass@localhost/dbname`
```

原則的にシンプルで引数やオプションも少ないため、説明は割愛します。

### dump

第1引数の DSN を指定ファイルにダンプします。

- e.g. `php dbmigration.phar dump mysql://127.0.0.1/dbname database.sql

引数は下記。

```
Description:
  Dump schema and records.

Usage:
  dump [options] [--] [<dsn> [<files>...]]

Arguments:
  dsn                              Specify target DSN.
  files                            Specify output files.

Options:
  -R, --recreate[=RECREATE]        Add DROP DATABASE/CREATE DATABASE. [default: ""]
  -A, --no-autoincrement           Add RESET auto_increment.
  -m, --migration[=MIGRATION]      Specify migration directory.
  -i, --include[=INCLUDE]          Target tables pattern (enable comma separated value. e.g. --include table1,table2) (multiple values allowed)
  -e, --exclude[=EXCLUDE]          Except tables pattern (enable comma separated value. e.g. --exclude table1,table2) (multiple values allowed)
  -D, --disable[=DISABLE]          Specify disabled schema object (enable comma separated value. e.g. --disable view,trigger) (multiple values allowed)
  -T, --transaction[=TRANSACTION]  Specify transaction nest level (0 is not transaction, 1 is only top level, 2 is only per-table) [default: 1]
  -E, --event[=EVENT]              Specify Event filepath
  -C, --config[=CONFIG]            Specify Configuration filepath
  -h, --help                       Display help for the given command. When no command is given display help for the list command
  -q, --quiet                      Do not output any message
  -V, --version                    Display this application version
      --ansi|--no-ansi             Force (or disable --no-ansi) ANSI output
  -n, --no-interaction             Do not ask any interactive question
  -v|vv|vvv, --verbose             Increase the verbosity of messages: 1 for normal output, 2 for more verbose output and 3 for debug

Help:
  Dump database (e.g. sakila).
   e.g. `dbmigration dump mysql://user:pass@localhost/sakila path/to/out.sql`
```

このサブコマンドは mysqldump を模倣するものではありますが、バックアップ用途ではありません。そういう用途では使用しないでください。
データの移行などで mysqldump の吐き出す巨大な1枚岩の sql ファイルのハンドリングがつらいときに使います。

スキーマオブジェクト毎にファイル単位で出力されるため、定義をいじくったりデータを書き換えたりが比較的容易に行えます。
また冒頭に `CREATE DATABASE` や `CREATE USER` が記載されるため、工夫すれば完全なる移行が行えます。
ただし、これらは安全のためデフォルトでコメントアウトされています（DATABASE に関して recreate オプションで有効化できます）。
もっとも、上記の通り1枚岩ではないのでテキストエディタで簡単に開いて編集可能です。
完全に復元名可能なクエリ（`SHOW CREATE USER` の結果）で出力されるため、情報の共有には注意を払ってください。

取り込みサブコマンドは用意されていませんが、トップレベルの database.sql に SOURCE が記載されているため、

```
# sql
mysql dbname < database.sql
cat database.sql | mysql dbname

# php
mysql dbname <(php database.sql)
php database.php | mysql dbname
```

ですべて取り込むことができます。
ただし SOURCE はカレントディレクトリの影響を受けるので実行場所には要注意です。
files に絶対パスを渡せば `SOURCE` も絶対パスになります。
同じホストであれこれする場合は絶対パスの方がいいでしょう。
別のホストへ移行したい場合は相対パスの方がいいでしょう。

なお、拡張子を .php で出力すればこの制限はありません。
`__DIR__` で自分のディレクトリが得られるため、トップレベルの database.php はすべて相対パスになります。

## Licence

[MIT](https://raw.githubusercontent.com/arima-ryunosuke/db-migration/master/LICENSE)

## Author

[arima-ryunosuke](https://github.com/arima-ryunosuke)
