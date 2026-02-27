<?php

namespace Mediavine\Create\Importers\Helpers;

class Ingredient_Parse {

	public static $units = [
		'teaspoon',
		'teaspoons',
		't',
		'tsp.',
		'ounce',
		'oz',
		'oz',
		'pounds',
		'pound',
		'lb',
		'lbs',
		'lb.',
		'lbs.',
		'tablespoon',
		'T',
		'tbl.',
		'tbs.',
		'tbsp.',
		'fluid ounce',
		'ounces',
		'fl oz',
		'gill',
		'cup',
		'cups',
		'c',
		'c.',
		'pint',
		'pints',
		'p',
		'pt',
		'fl pt',
		'quart',
		'quarts',
		'q',
		'qt',
		'fl',
		'qt',
		'gallon',
		'gallons',
		'g',
		'gal',
		'ml',
		'milliliter',
		'milliliters',
		'millilitre',
		'millilitres',
		'cc',
		'mL',
		'l',
		'liter',
		'litre',
		'L',
	];

	public static function parse( $original_text, $link = '' ) {
		$ingredient = [
			'quantity'      => 0, // ignore quantity and let frontend do the parsing
			'unit'          => '',
			'name'          => '',
			'info'          => '',
			'link'          => $link,
			'original_text' => $original_text,
		];

		/*
		** Thanks to @ethanbutler
		** https://regex101.com/r/an7l0f/1
		** Finds digits for quantity; if space exists, check for fraction (1 1/2, 2 2/3); if not a fraction, only take the first digit before whitespace;
		*/
		$re = '/(^\d+(?:\s\d+\/\d+)?)(?:[-–](\d+(?:\s\d+\/\d+)?))?/m';
		preg_match( $re, $original_text, $matches );
		if ( isset( $matches[0] ) ) { //Check To See If The Ingredient contains a certain amount.
			$amount = $matches[0]; // for removing the quantity in order to get the name

			//Check To See If We Can Find a Matching Unit
			foreach ( static::$units as $unit ) {
				preg_match( '/\s+' . $unit . '\s+/', $original_text, $matches );
				if ( isset( $matches[0] ) ) {
					$ingredient['unit'] = trim( $matches[0] );
					break;
				}
			}

			//Find The Name of The Item
			//Remove The Unit and Amount
			$stripped = str_replace( [ $ingredient['unit'], $amount ], '', $original_text );

			$split_string = explode( ',', $stripped );
			if ( count( $split_string ) > 1 ) {
				$ingredient['info'] = trim( end( $split_string ) );
			}
			$ingredient['name'] = trim( $split_string[0] );

		} else {
			//We Dont a have an ingrident quanity thus we could only have an ingredient and possibly an info value
			//We Can spilt these two strings up by spilting on the ","
			$split_string = explode( ',', $original_text );
			if ( count( $split_string ) > 4 ) {
				$ingredient['info'] = end( $split_string );
			}
			$ingredient['name'] = $split_string[0];
		}
		if ( preg_match( '/href=["\']?([^"\'>]+)["\']?/', $original_text, $match ) ) {
			$ingredient['link'] = $match[1];
			// grab just the text and wrap it in markdown format
			$original_text = preg_replace( '/<a.+>/iU', '[', $original_text );
			$original_text = str_replace( '</a>', ']', $original_text );
		}
		$ingredient['original_text'] = strip_tags( $original_text, '<a>' );

		return $ingredient;
	}

}
