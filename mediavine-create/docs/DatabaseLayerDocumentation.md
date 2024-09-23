MV-DBI Documentation
----

## How to Use

### Getting started ###

```php
// Create a new table model
// Table name without wp_* prefix
$table = new \Mediavine\MV_DBI( 'mv_creations' );
```

### Default Variables ####

Unless overwritten by the method calls, most of these methods build their queries using the following default variables.

```php
protected $limit    = 50;
protected $offset   = 0;
protected $order_by = 'created';
protected $order    = 'DESC';
protected $select   = '*';
```


### `set_limit` method

Overrides the default limit of 50

#### Parameters

* integer|null `$limit` New limit value

#### Returns

* \Mediavine\MV_DBI instance of MV_DBI class


### `set_offset` method

Overrides the default offset value of 0

#### Parameters

* integer|null `$offset` New offset value

#### Returns

* \Mediavine\MV_DBI instance of MV_DBI class


### `set_order_by` method

Overrides the default ORDER BY column `created`. Important if a dev is intending to use this class against default WordPress or
other plugin tables.

#### Parameters

* integer|null `$order_by` New offset value

#### Returns

* \Mediavine\MV_DBI instance of MV_DBI class


### `set_order` method

Overrides the default ORDER direction `DESC`.

#### Parameters

* integer|null `$order` New order value. Can be `ASC` or `DESC`

#### Returns

* \Mediavine\MV_DBI instance of MV_DBI class


### `set_select` method

Overrides the default columns to select in a DB query. Default is `*`. 

This method is actually called by the `find` method if the `select` parameter contains columns. Otherwise, the query will default to `*`

#### Parameters

* string `$select` Columns to select.

#### Returns

* \Mediavine\MV_DBI instance of MV_DBI class


---


### `select_one` method

Finds a row based on provided criteria.

#### Confusing to new users

👉 `key` parameter is actually the value that's passed to `select_one`, while `col` is the column you're querying against.

👉 `select_one` does not use the same `where` parameter setup that the `where` and `where_many` methods do. See examples for details.

#### Parameters

* array|mixed `$args` If `$args` is a single value instead of an array, then this value will be treated as a key

#### Returns

* object DB row if results exist, `null` if no results, and `\WP_Error` if there's an error

#### Example
```php
    // Querying by column and array
    $result = $table->select_one([
        'col' => 'ID',
        'key' => 2,
    ]);

    // querying default `id` column — mv_* tables only
    $result = $table->select_one(2);

    $result = $table->select_one_by_id(2); // is the same as calling $table->find_one(2)
```

Example from `class-images.php`, in `get_attachment_id_from_url` method
```php
     $postmeta = new \Mediavine\MV_DBI( 'postmeta' );
     $postmeta->set_order_by( 'post_id' ); // override default order column `created`
     $result = $postmeta->select_one(
         [
             'where' => [
                 'meta_key'   => 'origin_uri',
                 'meta_value' => $url,
             ],
         ]
     );

    // returns:
    {stdClass} [4]
       meta_id = "3271"
       post_id = "2502"
       meta_key = "origin_uri"
       meta_value = "https://example/some/image/hotdog.jpg"
```


### `select_one_by_id` method

Wrapper for `select_one` method


### `find` method

Retrieve an entire SQL result set from the database. Prepare queries that need a prepared statement

⭐ use with `mv_*` tables only

#### Parameters

* array `$args` Array containing basic SQL arguments or a prepared SQL statement
* array `$search_params` Array containing a list of column names that should be searched with LIKE/OR queries to support text search

#### Returns

* object Database query results, or `\WP_Error` if there is an error present


#### Examples

Returning results with only the columns specified. Equivalent to `SELECT ID, type FROM wp_mv_creations ORDER BY created DESC LIMIT 0, 5`

```php
    $table = new \Mediavine\MV_DBI( 'mv_creations' );
    // query will only return the columns specified
    $result = $table->find( [
        'select' => [
            'ID',
            'type',
        ],
        'limit' => 5,
    ] );

    // Returns:
    array (size=5)
      0 => 
        object(stdClass)[1288]
          public 'ID' => string '16' (length=2)
          public 'type' => string 'recipe' (length=6)
      1 => 
        object(stdClass)[1291]
          public 'ID' => string '13' (length=2)
          public 'type' => string 'recipe' (length=6)
      2 => 
        object(stdClass)[1286]
          public 'ID' => string '8' (length=1)
          public 'type' => string 'list' (length=4)
      3 => 
        object(stdClass)[1289]
          public 'ID' => string '7' (length=1)
          public 'type' => string 'list' (length=4)
      4 => 
        object(stdClass)[1287]
          public 'ID' => string '6' (length=1)
          public 'type' => string 'list' (length=4)
```

Using column aliases. Equivalent to `SELECT ID as creation_id, type as creation_type FROM wp_mv_creations ORDER BY created DESC LIMIT 0, 5;`

```php
    $table = new \Mediavine\MV_DBI( 'mv_creations' );
    // query will only return the columns specified
    $result = $table->find( [
        'select' => [
            'ID as creation_id',
            'type as creation_type',
        ],
        'limit' => 5,
    ] );
    
    // Returns: 
    array (size=5)
      0 => 
        object(stdClass)[1288]
          public 'creation_id' => string '16' (length=2)
          public 'creation_type' => string 'recipe' (length=6)
      1 => 
        object(stdClass)[1291]
          public 'creation_id' => string '13' (length=2)
          public 'creation_type' => string 'recipe' (length=6)
      2 => 
        object(stdClass)[1286]
          public 'creation_id' => string '8' (length=1)
          public 'creation_type' => string 'list' (length=4)
      3 => 
        object(stdClass)[1289]
          public 'creation_id' => string '7' (length=1)
          public 'creation_type' => string 'list' (length=4)
      4 => 
        object(stdClass)[1287]
          public 'creation_id' => string '6' (length=1)
          public 'creation_type' => string 'list' (length=4)
```

Modifying the `where` parameter. Equivalent to `SELECT * FROM wp_mv_creations WHERE type = 'list' ORDER BY created DESC LIMIT 0, 5;`

```php
    $table = new \Mediavine\MV_DBI( 'mv_creations' );
    // using the `where` parameter
    $result = $table->find( [
        'where' => [
            'type' => 'list',
        ],
        'limit' => 5,
    ] );

    // Returns:
    array (size=5)
       0 =>
        object(stdClass)[47]
          public 'ID' => string '8' (length=1)
          public 'created' => string '2021-10-26 18:22:16' (length=19)
          public 'modified' => string '2021-11-24 18:51:50' (length=19)
          public 'object_id' => string '44' (length=2)
          public 'type' => 'list' (length=4)
          public 'title' => 'MV Test List 1.7.0' (length=18)
          ...
      1 =>
        object(stdClass)[47]
          public 'ID' => string '6' (length=1)
          public 'created' => string '2021-10-07 18:49:34' (length=19)
          public 'modified' => string '2021-10-26 19:50:57' (length=19)
          public 'object_id' => string '27' (length=2)
          public 'type' => 'list' (length=4)
          public 'title' => 'Test for Ads' (length=18)
          ...
    ...
```

Select all columns, but limit to 20 items
```php
    $result = $table->find( [ 'limit' => 20 ] );
```


### `select` method

Deprecated. Use `MV_DBI::find()` instead

  
### `where` method

Add one or many where clauses to a query.

⭐ use with `mv_*` tables only

#### Parameters

* string|array `$column` Name of column for `where` method
* mixed   `$operator` Comparison operator
* mixed   `$value` Comparison value
* string  `$after` any SQL to insert after (LIMIT, ORDER, etc.)

#### Returns
* @return array|\WP_Error

#### Examples

Returning results with the column name, comparison operator, and comparison value specified for one where clause. Equivalent to `SELECT * FROM wp_mv_creations WHERE type = 'recipe' ORDER BY created DESC LIMIT 0, 50;`

```php
    $table = new \Mediavine\MV_DBI( 'mv_creations' );
    // query will return all rows where type = 'recipe'
    $result = $table->where( [
       ['type', '=', 'recipe'],
    ] );

    // Returns:
    array (size=5)
      0 => 
        object(stdClass)[47]
          public 'ID' => string '16' (length=2)
          public 'created' => string '2021-11-24 15:18:14' (length=19)
          public 'modified' => string '2021-11-24 15:18:14' (length=19)
          public 'object_id' => string '64' (length=2)
          public 'type' => 'recipe' (length=6)
          public 'title' => 'Recipe for Demo' (length=15)
          ...
      1 =>
        object(stdClass)[47]
          public 'ID' => string '13' (length=2)
          public 'created' => string '2021-11-24 13:53:36' (length=19)
          public 'modified' => string '2021-11-24 15:01:34' (length=19)
          public 'object_id' => string '60' (length=2)
          public 'type' => 'recipe' (length=6)
          public 'title' => 'A Delicious Burger' (length=18)
          ...
    ...
```

Returning results with the full where clause passed to the function.  Equivalent to `SELECT * FROM wp_mv_creations WHERE type = "recipe" ORDER BY created DESC LIMIT 0, 50;`

```php
    $table = new \Mediavine\MV_DBI( 'mv_creations' );
    // query will return all rows where type = 'recipe'
    $result = $table->where( 'type = "recipe"' );

    // Returns:
    array (size=5)
      0 => 
        object(stdClass)[47]
          public 'ID' => string '16' (length=2)
          public 'created' => string '2021-11-24 15:18:14' (length=19)
          public 'modified' => string '2021-11-24 15:18:14' (length=19)
          public 'object_id' => string '64' (length=2)
          public 'type' => 'recipe' (length=6)
          public 'title' => 'Recipe for Demo' (length=15)
          ...
      1 =>
        object(stdClass)[47]
          public 'ID' => string '13' (length=2)
          public 'created' => string '2021-11-24 13:53:36' (length=19)
          public 'modified' => string '2021-11-24 15:01:34' (length=19)
          public 'object_id' => string '60' (length=2)
          public 'type' => 'recipe' (length=6)
          public 'title' => 'A Delicious Burger' (length=18)
          ...
    ...
```

Adding multiple statements to where clause.  Calls the `where_many()` function.  Equivalent to `SELECT * FROM wp_mv_creations WHERE (type = 'recipe' OR (associated_posts LIKE '%5%') OR original_post_id IS NOT NULL) ORDER BY created DESC LIMIT 0, 50;`

```php
    $table = new \Mediavine\MV_DBI( 'mv_creations' );
    // query will return all rows where type = 'recipe'
    $result = $table->where( [
       ['type', '=', 'recipe', 'or'],
       ['associated_posts', 'LIKE', '%5%', 'or'],
       ['original_post_id', 'IS NOT', 'NULL']
    ] );

    // Returns:
    array (size=12)
      0 => 
        object(stdClass)[47]
          public 'ID' => string '16' (length=2)
          public 'created' => string '2021-11-24 15:18:14' (length=19)
          public 'modified' => string '2021-11-24 15:18:14' (length=19)
          public 'object_id' => string '64' (length=2)
          public 'type' => 'recipe' (length=6)
          public 'title' => 'Recipe for Demo' (length=15)
          ...
      1 =>
        object(stdClass)[47]
          public 'ID' => string '13' (length=2)
          public 'created' => string '2021-11-24 13:53:36' (length=19)
          public 'modified' => string '2021-11-24 15:01:34' (length=19)
          public 'object_id' => string '60' (length=2)
          public 'type' => 'recipe' (length=6)
          public 'title' => 'A Delicious Burger' (length=18)
          ...
      2 =>
        object(stdClass)[47]
          public 'ID' => string '8' (length=1)
          public 'created' => string '2021-10-26 18:22:16' (length=19)
          public 'modified' => string '2021-11-24 18:51:50' (length=19)
          public 'object_id' => string '44' (length=2)
          public 'type' => 'list' (length=4)
          public 'title' => 'MV Test List 1.7.0' (length=18)
          ...
      3 =>
        object(stdClass)[47]
          public 'ID' => string '6' (length=1)
          public 'created' => string '2021-10-07 18:49:34' (length=19)
          public 'modified' => string '2021-10-26 19:50:57' (length=19)
          public 'object_id' => string '27' (length=2)
          public 'type' => 'list' (length=4)
          public 'title' => 'Test for Ads' (length=18)
          ...
    ...
```

Getting results using MySQL IN statement.

Equivalent to `SELECT * FROM wp_mv_creations WHERE id IN ( ... )`

```php
    $creations_table = new \Mediavine\MV_DBI( 'mv_creations' );

    $creation_ids = [ ... ]; // a list of Creation ids 

    $original_products = $creations_table->find( [
      'where'  => [
        'id' => [
          'in' => $creation_ids,
        ],
      ],
    ] );
```


### `find_one` method

Wrapper for `select_one` method


### `find_one_by_id` method

Wrapper for `select_one_by_id`


### `where_many` method

Get results with several conditionals.

If a single set of conditionals, the values come in the format `[ $column, $operator, $value, $after ]` (see `where` for more details)
If an array of conditional sets, the values should look like `[ $column, $operator, $value, $boolean ]`
 - `$boolean` is a SQL boolean string (`AND`, `OR`, etc.)
 - if the last conditional set is actually a string, this is appended to the SQL statement,
   allowing for LIMIT, and ORDER BY statements to be added


#### Parameters

* array `$array` Array of parameters


#### Examples
```php
$last_year = date('Y-m-d H:i:s', strtotime( 'last year' ));
$today     = date( 'Y-m-d H:i:s' );
$dbi_model->set_limit(10);

$dbi_model->where_many(['id', '=', 183]); // SELECT * FROM wp_mv_creations WHERE id = '183'  ORDER BY created DESC LIMIT 0, 10

$dbi_model->where_many(['title', 'LIKE', '%test%']); // SELECT * FROM wp_mv_creations WHERE title LIKE '%test%'  ORDER BY created DESC LIMIT 0, 10

$dbi_model->where_many([ // SELECT * FROM wp_mv_creations WHERE title LIKE '%test%' AND (created > '2020-11-29 21:52:17' OR created <= '2021-11-29 21:52:17')  ORDER BY created DESC LIMIT 0, 10
    ['title', 'LIKE', '%test%'],
    ['created', '>', $last_year, 'or'],
    ['created', '<=', $today],
]);
```

#### Returns

* array|\WP_Error array of results or error on failure


### `normalize_data` method

Normalizes data so that only return data that exists as cols within table is returned

#### Parameters

* array $data Data to be normalized
* boolean $allow_null Are null values allowed

#### Returns

* array Normalized data


### `get_sprintf` method

Returns the sprintf type for preparing sql statements

#### Parameters

* mixed $var Variable to determine type

#### Returns

* string|false sprintf type


### `has_duplicate` method

Checks if duplicate exists in table

#### Parameters

* array $where_array Columns and values to check against

#### Returns

* object|false Database query result of duplicate entry


### `insert` method

Inserts new row into custom table

#### Parameters

* array $data Data to be inserted. Array keys must match the table columns of the table that the data is being added to

#### Returns

* object Database query result from insert


### `create` method

Insert a singular row. Wrapper method for `insert` but adds `add_filter( 'query', [ $this, 'allow_null' ] );` prior


### `create_many` method
Insert many items into the database in a single transaction.

#### Parameters

* array $data Array of row arrays containing data to insert. Should match the table schema

#### Returns
* int|null|\WP_Error inserted count




### `find_or_create` method

Find record, or create record if it doesn't exist

#### Parameters 
* array $data
* array $where_array

#### Returns

* array|object|WP_Error|null



### `upsert_without_modified_date` method

Check for a record and update it without modifying the date. Is a wrapper for `upsert`

#### Parameters

* array $data Data to be updated
* array $where_array Determines what record(s) to update
* false $modify_date Should the modified_date column be updated? False (no), True (yes) Defaults to false

#### Returns
* 
* WP_Error|null



### `upsert` method

Check for a record and update it if exists, or create a new one if it doesn't.

#### Parameters

* array $data
* array $where_array
* bool $modify_date

#### Returns

* WP_Error|null



### `update_without_modified_date` method

Update a DB record without updating the modified date

#### Parameters 

* array $data Data to be updated
* array|integer|null $args an array of args or an integer id of the item being updated
* boolean $return_updated returns the updated record if true

#### Returns

* object|array|\WP_Error|null


### `update` method

Update a DB record

#### Parameters
* array $data Data to be updated
* array|integer|null $args an array of args or an integer id of the item being updated
* boolean $return_updated returns the updated record if true
* boolean $modify_date whether or not to update the `modified` date column

#### Returns
@return object|array|\WP_Error|null
