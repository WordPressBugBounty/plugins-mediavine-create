# Create Actions and Filters

- **Table of Contents**

# Actions in Create

## `mv_{$name}_queue_action`

Defined in lib/class-publish.php (Create 1.7)
`do_action( 'mv_' . $name . '_queue_action', $id )`

### Parameters

- `$id (int)` ID of item or items being queued

## `mv_create_modify_card_style_hooks`

Defined in lib/creations/class-creations-views.php:1159 (Create 1.7)
`do_action( 'mv_create_modify_card_style_hooks', $style, $type )`
See: `mv_create_settings` or `{settings-slug}_settings_value`

### Parameters

- `$style (string)` Card style. Defined in Settings. Can be any of the following: `square` (Simple Square), `dark` (Dark Simple Square), `centered` (Classy Circle), `centered-dark` (Dark Classy Circle), or `big-image` (Hero Image)
- `$type (string)` Card type. Can be one of the following: `recipe`, `diy`, or `list`

## `mv_create_card_before_render`

Fires immediately before Create card template has been built

Defined in lib/creations/class-creations-views.php:1212 (Create 1.7)
`do_action( 'mv_create_card_before_render', $atts )`

### Parameters

- `$atts (array)` All card attributes used to generate card

## `mv_create_card_after_render`

Fires immediately after Create card template has been built

Defined in lib/creations/class-creations-views.php:1222 (Create 1.7)

### Parameters

- `$atts (array)` All card attributes used to generate card
- `$creation_view (array)` Rendered HTML of Create card

---

## `mv_create_card_before_print_render`

Fires immediately before print view output is generated

Defined at lib/creations/class-creations-views.php:1616 (Create 1.7)

### Parameters

- none

## `mv_create_print_head`

Allow developers to add markup to the print view's `<head>`. Fires after default meta data, but before `wp_head()`

Defined at lib/creations/class-creations-views.php:1628 (Create 1.7)

### Parameters

- `$args (array)` Arguments to pass to hook

### Parameters for `$args`

- `$creation (array|object)` The current Card to display a print view for
- `$card_style (string)` Can be any of the following: `square` (Simple Square), `dark` (Dark Simple Square), `centered` (Classy Circle), `centered-dark` (Dark Classy Circle), or `big-image` (Hero Image)
- `$type (string)` Card type. Can be one of the following: `recipe`, `diy`, or `list`

### Examples

```php
<?php
do_action(
    'mv_create_print_head', [
        'creation'   => $creation,
        'card_style' => $card_style,
        'type'       => $default_type,
    ]
);
?>
```

## `mv_create_print_before`

Fires before card content output, but after `<body>` tag

Defined at lib/creations/class-creations-views.php:1644 (Create 1.7)

### Parameters

- `$args (array)` Arguments to pass to hook

### Parameters for `$args`

- `$creation (array|object)` The current Card to display a print view for
- `$card_style (string)` Can be any of the following: `square` (Simple Square), `dark` (Dark Simple Square), `centered` (Classy Circle), `centered-dark` (Dark Classy Circle), or `big-image` (Hero Image)
- `$type (string)` Card type. Can be one of the following: `recipe`, `diy`, or `list`

### Examples

```php
<?php
/**
 * mv_create_print_before hook.
 */
do_action(
   'mv_create_print_before', [
      'creation'   => $creation,
      'card_style' => $card_style,
      'type'       => $default_type,
   ]
);

```

## `mv_create_print_after`

Fires after card content output, but before closing `</body>` tag

Defined at lib/creations/class-creations-views.php:1644 (Create 1.7)

### Parameters

- `$args (array)` Arguments to pass to hook

### Parameters for `$args`

- `$creation (array|object)` The current Card to display a print view for
- `$card_style (string)` Can be any of the following: `square` (Simple Square), `dark` (Dark Simple Square), `centered` (Classy Circle), `centered-dark` (Dark Classy Circle), or `big-image` (Hero Image)
- `$type (string)` Card type. Can be one of the following: `recipe`, `diy`, or `list`

### **Examples**

```php
<?php
/**
 * mv_create_print_before hook.
 */
do_action(
   'mv_create_print_before', [
      'creation'   => $creation,
      'card_style' => $card_style,
      'type'       => $default_type,
   ]
);
```

## Creations API Modification Actions

<aside>
👉🏼 These actions should be changed to either use [`apply_filters`](https://developer.wordpress.org/reference/functions/apply_filters/) or [`do_action_ref_array`](https://developer.wordpress.org/reference/functions/do_action_ref_array/), since they're modifying data instead of outputting data.

</aside>

## `mv_pre_create_card`

Allows modification of request parameters before card is created.

Defined at lib/creations/class-creations-api.php

### Parameters

- `$params (array)` Request parameters to be modified. In a hooked function, `$params` will need to be passed by reference for changes to be reflected in the parent method.

## `mv_pre_create_{$type}_card`

Similar to `mv_pre_create_card`, except targets a specific card type.

Defined at lib/creations/class-creations-api.php

## `mv_post_create_card`

Modify card data after card has been created but before the data is returned by the API

Defined at lib/creations/class-creations-api.php

### Parameters

- `$creation (object?)` Card data to be modified. In a hooked function, `$creation` will need to be passed by reference for changes to be reflected in the parent method.

## `mv_post_create_{$type}_card`

Similar to `mv_post_create_card`, except targets a specific card type.

Defined at lib/creations/class-creations-api.php

## `mv_pre_update_card`

Defined at lib/creations/class-creations-api.php

### Parameters

- `$data`

## `mv_pre_update_{$type}_card`

Defined at lib/creations/class-creations-api.php

### Parameters

- `$data`

## `mv_post_update_card`

Defined at lib/creations/class-creations-api.php

### Parameters

- `$updated`

## `mv_post_update_{$type}_card`

Defined at lib/creations/class-creations-api.php

### Parameters

- `$updated`

# Filters in Create

## Filter: mv_create_init_settings

```php
apply_filters( 'mv_create_init_settings', $settings ): array $settings 
```

Applied in `class-plugin.php`

<aside>
⚠️ Should not be used by non-Create developers, unless they *really* know what they're doing

</aside>

**Parameters**

- `$settings` - An array of settings data to be processed and added to the `mv_settings` DB table

**Returns**

- Array of settings to register in the DB

## Filter: mv_create_fields

```php
apply_filters( 'mv_create_fields', $fields ): array $fields
```

Applied in `admin/class-admin-init.php`

Manages default custom field registration.

**Parameters**

- `$fields` - An array of custom fields

**Returns**

- Array of custom fields

## Filter: mv_create_localized_admin_settings

```php
apply_filters( 'mv_create_localized_admin_settings', $settings ): array $settings
```

Applied in `admin/class-admin-init.php`

Gets settings to localize for frontend

**Parameters**

- `$settings` - An array of custom settings

**Returns**

- Array of custom settings

## Filter: {$namespace}_meta_fields

```php
apply_filters( $this->namespace . '_meta_fields', [] ) : array $fields // $fields is empty by default
```

Applied in `lib/class-custom-content.php`

A dynamic filter that adds meta fields to TinyMCE and Gutenberg editors. `$this->namespace` is a value passed in when the `Custom_Content` class is initialized. Defaults to `'mv-create'`

**Parameters**

- `$fields` - An array of custom fields

**Returns**

- Array of custom fields

## Filter: {$namespace}_content_blocks

```php
apply_filters( $this->namespace . '_content_blocks', [] ) : array $content_blocks // $content_blocks is empty by default
```

Applied in `lib/class-custom-content.php`

A dynamic filter that adds content blocks to Gutenberg editor. `$this->namespace` is a value passed in when the `Custom_Content` class is initialized. Defaults to `'mv-create'`

**Parameters**

- `$content_blocks` - An array of custom blocks to add to Gutenberg

**Returns**

- Array of custom blocks

## Filter: mv_publish_create_settings

```php
apply_filters( 'mv_publish_create_settings', $create_settings ) : array $create_settings
```

Applied in `lib/class-publish.php`

Allow users to add or modify Create settings

**Parameters**

- `$create_settings` - Array of settings

**Returns**

- Array of settings

Usage Example (found in `class-plugin.php`):

```php
add_filter(
   'mv_publish_create_settings', function ( $arr ) {
      // Get the authenticated user to assign the copyright attribution if none has been set in settings.
      $user = wp_get_current_user();
      $arr[ \Mediavine\Create\Plugin::$settings_group. '_copyright_attribution' ] = $user->display_name;

      // Assign the default settings. These can be overwritten by using this filter.
      foreach ( \Mediavine\Create\Plugin::$create_settings_slugs as $slug ) {
         $setting = \Mediavine\Settings::get_setting( $slug );
         if ( $setting ) {
            $arr[ $slug ] = $setting;
         }
      }
      return $arr;
   }
);
```

## Filter: mv_times_to_parse

```php
apply_filters( 'mv_times_to_parse', $times_to_parse ) : array $times_to_parse
```

Applied in `lib/class-publish.php`

Allow users to modify available time labels in Create

**Parameters**

- `$times_to_parse` - Array of time labels to parse

**Returns**

- Array of time labels

Usage Example (Found in `lib/class-publish.php`)

```php
$times_to_parse = [
	'prep_time',
	'active_time',
	'additional_time',
	'perform_time',
	'total_time',
];

//...

$times_to_parse = apply_filters( 'mv_times_to_parse', $times_to_parse );
```

## Filter: mv_time_units_to_parse

```php
apply_filters( 'mv_time_units_to_parse', $time_units_to_parse ) : array $time_units_to_parse
```

Applied in `lib/class-publish.php`

Allow users to modify available time units in Create

**Parameters**

- `$time_units_to_parse` - Array of time units to parse

**Returns**

- Array of time units

Usage Example (Found in `lib/class-publish.php`)

```php
$units_to_parse = apply_filters(
	'mv_time_units_to_parse', [
		'years'   => 31536000,
		'months'  => 2628000,
		'days'    => 86400,
		'hours'   => 3600,
		'minutes' => 60,
		'seconds' => 1,
	]
);
```

## Filter: mv_{$view_base}_style_version

```php
apply_filters( 'mv_' . $view_base . '_style_version', false ) : boolean $has_custom_style
```

Applied in `lib/class-view-loader.php`

Allow theme developers to override Create's default templates. `$view_base` defaults to `'create'`

<aside>
💡 `$view_base` is being detected as not defined in PhpStorm. Looks like there's an array loop and `explode` statement involved. Some additional code hinting may clear up this issue, otherwise it appears as if this filter would never actually work.

</aside>

**Parameters**

- `$has_custom_style` - Flag for determining if a theme template is active

**Returns**

- `true` if a theme developer has explicitly overridden `mv_create_style_version`. otherwise `false`

## Filter: mv_{$view_base}_view_theme_dir

```php
apply_filters( 'mv_' . $view_base . '_view_theme_dir', 'mv_create' ) : string $view_base
```

Applied in `lib/class-view-loader.php`

Allow theme developers to change the base directory name inside their theme from `'mv_create'` to one of their choosing. Works in conjunction with `mv_{$view_base}_style_version`

**Parameters**

- `$view_base` - Determines the theme base. Defaults to `mv_create`

**Returns**

- `string` Name of theme base if overridden. Otherwise defaults to `mv_create`

## Filter: mv_locate_view

```php
apply_filters( 'mv_locate_view', $view, $view_name, $view_style, $default_path ) : string $view
```

Applied in `lib/class-view-loader.php`

May allow theme developers to change the template path to one of their choosing.

**Parameters**

- `$view` - Default parameter passed by `apply_filters`. Default view being modified.
- `$view_name` -
- `$view_style` -
- `$default_path` -

**Returns**

- `string` Template path is overridden

## Filter: mv_create_jtr_button_filter

```php
apply_filters( 'mv_create_jtr_button_filter', 'the_content' ) : string $jtr_button_filter
```

Applied in `lib/creations/class-creations-jump-to-recipe.php`

Filters the hook used for the jump to recipe button output

## Filter: mv_create_jtr_screen_reader_filter

```php
apply_filters( 'mv_create_jtr_screen_reader_filter', 'wp_footer' ) : string $jtr_screen_reader_filter
```

Applied in `lib/creations/class-creations-jump-to-recipe.php`

<aside>
💡 These two filters above should be left alone, except by Create devs

</aside>

## Filter: mv_create_auto_output_jtr_shortcode

```php
apply_filters( 'mv_create_auto_output_jtr_shortcode', true ) : boolean $display_jtr
```

Applied in `lib/creations/class-creations-jump-to-recipe.php`

Allows theme developers to prevent output of JTR button in favor of using the `[mv_create_jtr]` shortcode.

**Parameters**

- `$display_jtr` Boolean flag to turn off JTR output. Defaults to `true`

**Returns**

- `boolean` Prevents or allows output of JTR button

## Filter: mv_create_screen_reader_skip_to_card_class

```php
apply_filters( 'mv_create_screen_reader_skip_to_card_class', $screen_reader_class ) : string $screen_reader_class
```

Applied in `lib/creations/class-creations-jump-to-recipe.php`

Filters the screen reader class used by Create. Allows theme developers to add additional classes  alongside the screen reader class, or even remove it to replace it with their own.

**Parameters**

- `$screen_reader_class` - Default screen-reader class. Can be overridden or added to

**Returns**

- `string` Screen-reader class

## Filter: mv_create_screen_reader_skip_to_card_css

```php
apply_filters( 'mv_create_screen_reader_skip_to_card_css', $screen_reader_css ) : string $screen_reader_css
```

Applied in `lib/creations/class-creations-jump-to-recipe.php`

Filters the screen reader CSS added by Create.

**Parameters**

- `$screen_reader_css` - CSS to be filtered

**Returns**

- `string` Screen-reader CSS

## Filter: mv_create_image_sizes

<aside>
💡 3rd party devs should leave this filter alone, as it's not clear what it does from looking at source code

</aside>

```php
apply_filters( 'mv_create_image_sizes', $image_sizes, $function ) : array $image_sizes
```

Applied in `lib/creations/class-creations-views.php`

Filters Create's default image sizes array

**Parameters**

- `$image_sizes` - Array of image sizes
- `$function` - Passes the name of the function where filter is applied

**Returns**

- `array`- Array of image sizes

## Filter: mv_create_image_size_names

<aside>
💡 3rd party devs should leave this filter alone, as it's not clear what it does from looking at source code

</aside>

```php
apply_filters( 'mv_create_image_size_names', $image_size_names ) : array $image_size_names
```

Applied in `lib/creations/class-creations-views.php`

Filters image size names

**Parameters**

- `$image_size_names` - Array of image size names

**Returns**

- `array` - Array of image size names

## Filter: mv_recipe_stylesheet

```php
apply_filters( 'mv_recipe_stylesheet', $recipe_stylesheet_url ) : string $recipe_stylesheet_url
```

Applied in `lib/creations/class-creations-views.php`

Allows developers to override Create's default Recipe card stylesheets.

**Parameters**

- `$recipe_stylesheet_url` Default Create Card stylesheet

**Returns**

- `string` Create Card stylesheet

<aside>
👉🏽 There is room for improvement of this filter. It currently runs inside a loop, with the card type added to the stylesheet url. Adding an additional parameter such as `$card_style` would allow 3rd party devs to override Create's styles for specific card styles instead of using a brute-force "all-in" method.

</aside>

## Filter: mv_create_ratings_prompt_threshold

```php
apply_filters( 'mv_create_ratings_prompt_threshold', $mv_create_ratings_prompt_threshold ) : float $mv_create_ratings_prompt_threshold
```

Applied in `lib/creations/class-creations-views.php`

Allows developers to override Create's default ratings prompt threshold

**Parameters**

- `$mv_create_ratings_prompt_threshold` Default ratings prompt threshold

**Returns**

- `float` Ratings prompt threshold

## Filter: mv_create_ratings_submit_threshold

```php
apply_filters( 'mv_create_ratings_submit_threshold', $mv_create_ratings_submit_threshold ) : float $mv_create_ratings_submit_threshold
```

Applied in `lib/creations/class-creations-views.php`

Allows developers to override Create's default ratings submit threshold

**Parameters**

- `$mv_create_ratings_prompt_threshold` Default ratings submit threshold

**Returns**

- `float` Ratings submit threshold

## Filter: mv_create_style_version

```php
apply_filters( 'mv_create_style_version', $card_style_version, $attributes ) : string $card_style_version
```

Applied in `lib/creations/class-creations-views.php`

Allows developers to override stylesheet version for `mv_create_card` short-code

**Parameters**

- `$card_style_version` Default style version
- `$attributes` Available short-code attributes

**Returns**

- `string` Ratings submit threshold

## Filter: mv_create_card_render

```php
apply_filters( 'mv_create_card_render', $creation_view, $attributes ) : string $creation_view
```

Applied in `lib/creations/class-creations-views.php`

Allows developers to modify the short-code data before it is outputted

**Parameters**

- `$creation_view` Default style version
- `$attributes` Available short-code attributes

**Returns**

- `string` Ratings submit threshold

## Filter: mv_create_diy_additionals

```php
apply_filters( 'mv_create_diy_additionals', $diy_additionals, $creation ) : array $diy_additionals
```

Applied in `lib/creations/class-creations-views.php`

Allows developers to modify the short-code data for How-Tos before it is outputted

**Parameters**

- `$diy_additionals` Additional parameters specific to How-To Cards
- `$creation` Available short-code attributes

**Returns**

- `array` Modified additional parameters specific to How-To Cards

## Filter: mv_create_time_output

```php
apply_filters( 'mv_create_time_output', $time_output, $time_array ) : string $time_output
```

Applied in `lib/creations/class-creations-views.php`

Allows developers to modify card time output

**Parameters**

- `$time_output` The original time string to be included as part of the card output
- `$time_array` Array of time attributes used to build the string

**Returns**

- `string` Modified card time string

## Filter: mv_create_print_title

```php
apply_filters( 'mv_create_print_title', $creation_title ) : string $creation_title
```

Applied in `lib/creations/class-creations-views.php`

Allows developers to modify the card title for print views

**Parameters**

- `$creation_title` The original card title for print views

**Returns**

- `string` Modified card title

## Filter: mv_create_{$default_type}_print_card_style

```php
apply_filters( 'mv_create_{$default_type}_print_card_style', $card_style ) : string $card_style
```

Applied in `lib/creations/class-creations-views.php`

Allows developers to modify the card style of a specific type (Recipes or How-Tos) for print views

**Parameters**

- `$card_style` The original card style for print views. Defaults to `'square'`

**Returns**

- `string` Modified card style

## Filter: {$namespace}_meta_fields

```php
apply_filters( '{$namespace}_meta_fields', $meta ) : array $meta
```

Applied in `lib/creations/class-creations-meta-blocks.php`

Allow devs to filter blocks array before registering

**Parameters**

- `$meta` Meta blocks to filter. Defaults to `[]`

**Returns**

- `array` Modified list of meta blocks

## Filter: mv_create_dbi_update_remove_original_object_id

```php
apply_filters( 'mv_create_dbi_update_remove_original_object_id', $should_remove_id ) : boolean $should_remove_id
```

Applied in `lib/creations/class-creations-api.php`

**Parameters**

- `$should_remove_id` The flag that determines whether or not the original object id should be removed. Defaults to `true`

**Returns**

- `boolean` Remove original object id

## Filter: mv_test_is_main_query

```php
apply_filters( 'mv_test_is_main_query', is_main_query() ) : boolean $is_main_query
```

Applied in `lib/creations/class-creations.php`

This filter only exists for unit tests, since `is_main_query()` will always return false in unit tests. 3rd party devs should not touch this filter.

**Parameters**

- `$is_main_query` Flag to check if the query running on the page is the main one. Defaults to value of `is_main_query()`

**Returns**

- `boolean` Is the query running the main query. Default to value of `is_main_query()`

## Filter: mv_create_card_pre_publish_data

```php
apply_filters( 'mv_create_card_pre_publish_data', $creation ) : object $creation
```

Applied in `lib/creations/class-creations.php`

Allow devs to filter the Create card data prior to publish

**Parameters**

- `$creation` Create card data

**Returns**

- `object` Modified Creation object

## Filter: mv_create_should_set_object_terms

```php
apply_filters( 'mv_create_should_set_object_terms', $max ) : boolean $max
```

Applied in `lib/creations/class-creations.php`

Allow devs to filter whether the object terms should be reset.

**Parameters**

- `$max` Maximum `should_set_object_terms` of revisions

**Returns**

- `boolean`

## Filter: mv_create_is_theme_genesis

```php
apply_filters( 'mv_create_is_theme_genesis', $is_theme_genesis ) : boolean $is_theme_genesis
```

Applied in `lib/helpers/functions-helpers.php`

Overrides if theme is Genesis. For PHPUnit tests only

**Parameters**

- `$is_theme_genesis` Overrides if theme is Genesis

**Returns**

- `boolean`

## Filter: mv_create_is_theme_trellis

```php
apply_filters( 'mv_create_is_theme_trellis', $is_theme_trellis ) : boolean $is_theme_trellis
```

Applied in `lib/helpers/functions-helpers.php`

Overrides if theme is Trellis. For PHPUnit tests only

**Parameters**

- `$is_theme_trellis` Overrides if theme is Trellis

**Returns**

- `boolean`

## Filter: mv_create_doing_filter_{$filter}

```php
apply_filters( 'mv_create_doing_filter_{$filter}', $doing_filter ) : boolean $doing_filter
```

Applied in `lib/helpers/functions-helpers.php`

Overrides if the filter is currently being processed. For PHPUnit tests only

**Parameters**

- `$doing_filter` Is filter currently running?

**Returns**

- `boolean`

## Filter: mv_create_current_post_id

```php
apply_filters( 'mv_create_current_post_id', $post_id ) : int $post_id
```

Applied in `lib/helpers/functions-helpers.php`

**Parameters**

- `$post_id`

**Returns**

- `int` Post ID

## Filter: mv_create_plugin_class_checks

```php
apply_filters( 'mv_create_plugin_class_checks', $class_checks ) : array $class_checks
```

Applied in `lib/helpers/class-plugin-checker.php`

Filters the plugin slugs and class names to check for active plugins. 3rd party devs shouldn't have to touch this filter.

**Parameters**

- `$class_checks` Array with plugin slug as keys and classes to check in a value array

**Returns**

- `array` Modified array with plugin slug as keys and classes to check in a value array

## Filter: mv_create_plugin_function_checks

```php
apply_filters( 'mv_create_plugin_function_checks', $function_checks ) : array $function_checks
```

Applied in `lib/helpers/class-plugin-checker.php`

Filters the plugin slugs and function names to check for active plugins. 3rd party devs shouldn't have to touch this filter.

**Parameters**

- `$function_checks` Array with plugin slug as keys and functions to check in a value array

**Returns**

- `array` Modified array with plugin slug as keys and functions to check in a value array

## Filter: mv_create_plugin_function_checks

```php
apply_filters( 'mv_create_plugin_function_checks', $function_checks ) : array $function_checks
```

Applied in `lib/helpers/class-plugin-checker.php`

Filters the plugin slugs and function names to check for active plugins. 3rd party devs shouldn't have to touch this filter.

**Parameters**

- `$function_checks` Array with plugin slug as keys and functions to check in a value array

**Returns**

- `array` Modified array with plugin slug as keys and functions to check in a value array

## Filter: mv_create_plugin_function_checks

```php
apply_filters( 'mv_create_plugin_function_checks', $function_checks ) : array $function_checks
```

Applied in `lib/helpers/class-plugin-checker.php`

Filters the plugin slugs and function names to check for active plugins. 3rd party devs shouldn't have to touch this filter.

**Parameters**

- `$function_checks` Array with plugin slug as keys and functions to check in a value array

**Returns**

- `array` Modified array with plugin slug as keys and functions to check in a value array

## Filter: mv_generate_intermediate_sizes_return_early

```php
apply_filters( 'mv_generate_intermediate_sizes_return_early', $return_early ) : bool $return_early
```

Applied in `lib/images/class-images.php`

Filters to return early from generate_intermediate_sizes. Some servers have issues with the core `wp_generate_attachment_metadata` function

**Parameters**

- `$return_early` Default 'false'. Return 'true' to return early

**Returns**

- `bool`

## Filter: mv_intermediate_image_sizes_advanced

```php
apply_filters( 'mv_intermediate_image_sizes_advanced', $img_sizes, $image_id ) : array $img_sizes
```

Applied in `lib/images/class-images.php`

Filters the array of available image sizes.

**Parameters**

- `$img_sizes` Array of image sizes to filter
- `$image_id` ID of image being filtered

**Returns**

- `bool`

## Filter: mv_create_image_resolutions

```php
apply_filters( 'mv_create_image_resolutions', $resolution ) : array $resolution
```

Applied in `lib/images/class-images.php`

Filters the array of Create's default image resolution sizes

**Parameters**

- `$resolution` Default image resolution sizes

**Returns**

- `array`

## Filter: mv_create_json_ld_build_creation

```php
apply_filters( 'mv_create_json_ld_build_creation', $creation, $type ) : array|object $creation
```

Applied in `lib/json-ld/class-json-ld.php`

Filters the Creation object before JSON+LD is outputted

**Parameters**

- `$creation` The Creation object to modify
- `$type` The Creation type

**Returns**

- `array|object`

## Filter: mv_create_json_ld_build_creation_{$type}

```php
apply_filters( 'mv_create_json_ld_build_creation_{$type}', $creation ) : array|object $creation
```

Applied in `lib/json-ld/class-json-ld.php`

Filters the Creation object by type before JSON+LD is outputted

**Parameters**

- `$creation` The Creation object to modify

**Returns**

- `array|object`

## Filter: mv_schema_types

```php
apply_filters( 'mv_schema_types', $schema_types, $type, $creation ) : array $schema_types
```

Applied in `lib/json-ld/class-json-ld.php`

Filters the available schema types

**Parameters**

- `$schema_types` Array of schema types to modify
- `$type` The Creation type
- `$creation` The Creation object

**Returns**

- `array`

## Filter: mv_create_json_ld_output

```php
apply_filters( 'mv_create_json_ld_output', $json_ld, $type, $creation ) : array $json_ld
```

Applied in `lib/json-ld/class-json-ld.php`

Filters the JSON+LD array before output

**Parameters**

- `$json_ld` JSON+LD array to modify
- `$type` The Creation type
- `$creation` The Creation object

**Returns**

- `array`

## Filter: mv_create_json_ld_output_{$type}

```php
apply_filters( 'mv_create_json_ld_output_{$type}', $json_ld, $creation ) : array $json_ld
```

Applied in `lib/json-ld/class-json-ld.php`

Filters the JSON+LD array by type before output

**Parameters**

- `$json_ld` JSON+LD array to modify
- `$creation` The Creation object

**Returns**

- `array`

## Filter: mv_json_ld_value_

```php
apply_filters( 'mv_json_ld_value_', $value, $schema_type, $schema_prop, $json_ld, $creation ) : mixed $value
```

Applied in `lib/json-ld/class-json-ld-types.php`

Filters the JSON+LD value

**Parameters**

- `$value` Value to be filtered
- `$schema_type`Yype of schema (e.g. string, integer, time)
- `$schema_prop` Property name of the schema item
- `$json_ld` The current build of the JSON-LD array
- `$creation` The full creation array for relationships

**Returns**

- `array`

## Filter: mv_json_ld_value_type_{$schema_type}

```php
apply_filters( 'mv_json_ld_value_type_' . $schema_type, $value, $schema_type, $schema_prop, $json_ld, $creation ) : mixed $value
```

Applied in `lib/json-ld/class-json-ld-types.php`

Filters the JSON+LD value by schema type

**Parameters**

- `$value` Value to be filtered
- `$schema_type`Yype of schema (e.g. string, integer, time)
- `$schema_prop` Property name of the schema item
- `$json_ld` The current build of the JSON-LD array
- `$creation` The full creation array for relationships

**Returns**

- `array`

## Filter: mv_create_image_resolutions

```php
apply_filters('mv_create_image_resolutions', $create_image_sizes) : array $create_image_sizes
```

Applied in `lib/json-ld/class-json-ld-types.php`

Allow users to filter which resolutions are included in JSON-LD

**Parameters**

- `$create_image_sizes` Value to be filtered

Default `$create_image_sizes`:

```php
[
   '_medium_res',
   '_medium_high_res',
   '_high_res',
]
```

**Returns**

- `array`

## Filter: mv_json_ld_nutrition_map

```php
apply_filters('mv_json_ld_nutrition_map', $nutrition_map) : array $nutrition_map
```

Applied in `lib/json-ld/class-json-ld-types.php`

Allow users to filter the nutrition map for JSON-LD

**Parameters**

- `$nutrition_map` Nutrition map to be filtered

Default `$nutrition_map`:

```php
[
			'calories'        => [
			   'schema' => 'calories',
			   'text'   => __( ' calories', 'mediavine' ),
			],
			'carbohydrates'   => [
			   'schema' => 'carbohydrateContent',
			   'text'   => __( ' grams carbohydrates', 'mediavine' ),
			],
			'cholesterol'     => [
			   'schema' => 'cholesterolContent',
			   'text'   => __( ' milligrams cholesterol', 'mediavine' ),
			],
			'total_fat'       => [
			   'schema' => 'fatContent',
			   'text'   => __( ' grams fat', 'mediavine' ),
			],
			'fiber'           => [
			   'schema' => 'fiberContent',
			   'text'   => __( ' grams fiber', 'mediavine' ),
			],
			'protein'         => [
			   'schema' => 'proteinContent',
			   'text'   => __( ' grams protein', 'mediavine' ),
			],
			'saturated_fat'   => [
			   'schema' => 'saturatedFatContent',
			   'text'   => __( ' grams saturated fat', 'mediavine' ),
			],
			'serving_size'    => [
			   'schema' => 'servingSize',
			   'text'   => null,
			],
			'sodium'          => [
			   'schema' => 'sodiumContent',
			   'text'   => __( ' milligrams sodium', 'mediavine' ),
			],
			'sugar'           => [
			   'schema' => 'sugarContent',
			   'text'   => __( ' grams sugar', 'mediavine' ),
			],
			'trans_fat'       => [
			   'schema' => 'transFatContent',
			   'text'   => __( ' grams trans fat', 'mediavine' ),
			],
			'unsaturated_fat' => [
			   'schema' => 'unsaturatedFatContent',
			   'text'   => __( ' grams unsaturated fat', 'mediavine' ),
			],
]
```

**Returns**

- `array`

## Filter: mv_create_json_ld

```php
apply_filters('mv_create_json_ld', $json_ld, $creations, $post_id) : array $json_ld
```

Applied in `lib/json-ld/class-json-ld-runtime.php`

Filters the JSON-LD schema to go in a script tag in `wp_head`

**Parameters**

- `$json_ld` Encoded json object of JSON-LD schema
- `$creations` List of creations in an `id` => `published_data` format
- `$post_id`Id of the current post

**Returns**

- `array`

## Filter: mv_create_amazon_rate_limit

```php
apply_filters( 'mv_create_amazon_rate_limit', $amazon_rate_limit ) : int $amazon_rate_limit
```

Applied in `lib/products/class-products.php`

Filters the JSON-LD schema to go in a script tag in `wp_head`

**Parameters**

- `$amazon_rate_limit` Rate limit time in seconds. Defaults to 3 hours (10800 seconds)

**Returns**

- `int`

## Filter: mv_create_amazon_rate_limit

```php
apply_filters( 'mv_create_amazon_rate_limit', $amazon_rate_limit ) : int $amazon_rate_limit
```

Applied in `lib/products/class-products.php`

Filters the JSON-LD schema to go in a script tag in `wp_head`

**Parameters**

- `$amazon_rate_limit` Rate limit time in seconds. Defaults to 3 hours (10800 seconds)

**Returns**

- `int`

## Filter: mv_create_amazon_rate_limit

```php
apply_filters( 'mv_create_amazon_rate_limit', $amazon_rate_limit ) : int $amazon_rate_limit
```

Applied in `lib/products/class-products.php`

Filters the Amazon rate limit frequency

**Parameters**

- `$amazon_rate_limit` Rate limit time in seconds. Defaults to 3 hours (10800 seconds)

**Returns**

- `int`

## Filter: mv_create_ratings_submit_threshold

```php
apply_filters( 'mv_create_ratings_submit_threshold', $limit_threshold ): int $limit_threshold
```

Applied in `lib/reviews/class-reviews-api.php`

Filters the ratings threshold limit.

**Parameters**

- `$limit_threshold` Ratings threshold value. Defaults to 4

**Returns**

- `int`

## Filter: mv_create_enable_text_review_modal

```php
apply_filters( 'mv_create_enable_text_review_modal', $enable_text_reviews ) : boolean $enable_text_review
```

Applied in `lib/reviews/class-reviews-models.php`

Enables the review modal

**Parameters**

- `$enable_text_reviews` Allows users to filter the boolean that enables the modal. Defaults to true

**Returns**

- `int`

## Filter: mv_create_maximum_number_of_revisions

```php
apply_filters( 'mv_create_maximum_number_of_revisions', $max_reviews ) : int $max_reviews
```

Applied in `lib/revisions/class-revisions.php`

Modify the maximum number of Create card revisions for a given Create card.

**Parameters**

- `$max_reviews` Maximum number of revisions

**Returns**

- `int`

## Filter: {$setting_slug}_settings_value

```php
apply_filters( "{$setting_slug}_settings_value", $value ) : mixed $value
```

Applied in `lib/settings/class-settings-api.php`

Allows users to modify value for a specific setting.

**Parameters**

- `$value` Value to modify

**Returns**

- `mixed`

## Filter: mv_create_settings

```php
apply_filters( 'mv_create_settings', $settings ) : array $settings
```

Applied in `lib/settings/class-settings.php`

Allows users to filter the settings array

**Parameters**

- `$settings` Entire Create Settings array

**Returns**

- `array`

## Filter: mv_create_settings

```php
apply_filters( 'mv_create_settings', $settings ) : array $settings
```

Applied in `lib/settings/class-settings.php`

Allows users to filter the settings array

**Parameters**

- `$settings` Entire Create Settings array

**Returns**

- `array`

## Filter: mv_custom_schema

```php
apply_filters( 'mv_custom_schema', $tables ) : array $tables
```

Applied in `lib/db-interface/class-mv-dbi.php`

Allows users to filter the schema array used for building tables.

Example of `$tables` array passed to `mv_custom_schema` filter.

```php
function custom_schema( $tables ) {
    $tables[] = [
			'version'    => self::DB_VERSION, // current DB version
			'table_name' => $this->table_name, // name of table from model
			'schema'     => $this->schema, // schema property that defines table from model
    ];
}
add_filter( 'mv_custom_schema', 'custom_schema' );

```

**Parameters**

- `$tables` Array of table schemas to modify

**Returns**

- `array`

## Filter: mv_custom_tables

```php
apply_filters( 'mv_custom_tables', $tables ) : array $tables
```

Applied in `lib/db-interface/class-mv-dbi.php`

Allows users to filter the schema array used for building tables.

Example of `$tables` array passed to `mv_custom_tables` filter

```php
function create_custom_tables( $custom_tables ) {

		$custom_tables[] = [
			'version'    => self::DB_VERSION,
			'table_name' => 'mv_notifications',
			'sql'        => "
				id bigint(20) NOT NULL AUTO_INCREMENT,
				type varchar(20) NOT NULL DEFAULT 'notice',
				message mediumtext NOT NULL default '',
				status tinytext,
				active boolean NOT NULL default 1,
				origin mediumtext,
				origin_id tinytext,
				link mediumtext,
				expires bigint(20) NOT NULL,
				created datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
				modified datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
				PRIMARY KEY  (id)",
		];

    return $custom_tables;

}

add_filter( 'mv_custom_tables', 'create_custom_tables' );
```

**Parameters**

- `$tables` Array of table schemas to modify

**Returns**

- `array`

## Filter: mv_dbi_after_find

Applied in `lib/db-interface/class-mv-dbi.php`

Allows users to filter the schema array used for building tables.

Filters below are a WIP
```php
apply_filters( 'mv_dbi_before_create', $data, $table_name );
apply_filters( 'mv_dbi_after_create', $data, $table_name );
apply_filters( 'mv_create_allow_normalized_null', false );
apply_filters( 'mv_dbi_before_update', $data, $table_name );
apply_filters( 'mv_dbi_after_update', $data, $table_name );
apply_filters( 'mv_create_allow_normalized_null', false );
apply_filters( 'mv_dbi_after_find', $item, $table_name );
apply_filters( 'mv_dbi_before_delete', $data, $table_name );
apply_filters( 'mv_dbi_after_delete', $data, $table_name );
apply_filters( "mv_db_select_one_defaults_{$table_name}", $params )
apply_filters( "mv_db_update_defaults_{$table_name}", $params)
```
