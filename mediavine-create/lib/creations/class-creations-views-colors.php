<?php
namespace Mediavine\Create;

class Creations_Views_Colors extends Creations_Views {

	public static function lighten( $color, $percent = 20 ) {
		// check if color is valid. If shorthand hex, convert to normal
		$color = self::validate( $color );

		return self::tint( $color, $percent );
	}

	public static function darken( $color, $percent = 20 ) {
		$color = self::validate( $color );

		return self::shade( $color, $percent );
	}

	public static function mix( $color1, $color2, $percent = 50 ) {
		$first  = self::to_rgb( $color1 );
		$second = self::to_rgb( $color2 );

		$weight = $percent / 100;

		$red   = intval( round( $first[0] * ( 1 - $weight ) + $second[0] * $weight ) );
		$green = intval( round( $first[1] * ( 1 - $weight ) + $second[1] * $weight ) );
		$blue  = intval( round( $first[2] * ( 1 - $weight ) + $second[2] * $weight ) );

		return self::to_hex( [ $red, $green, $blue ] );
	}

	/**
	 * Mixes a color with white
	 *
	 * @param string $color
	 * @param integer $percent
	 *
	 * @return string
	 */
	public static function tint( $color, $percent ) {
		return self::mix( $color, '#FFFFFF', $percent );
	}

	/**
	 * Mixes a color with black
	 *
	 * @param string $color
	 * @param integer $percent
	 *
	 * @return string
	 */
	public static function shade( $color, $percent ) {
		return self::mix( $color, '#000000', $percent );
	}

	/**
	 * Check lightness of color
	 *
	 * @param string $color Hex color code
	 *
	 * @return bool
	 */
	public static function is_light( $color ) {
		$color = self::validate( $color );

		list( $r, $g, $b ) = self::to_rgb( $color );

		$darkness = 1 - ( 0.299 * $r + 0.587 * $g + 0.114 * $b ) / 255;

		return $darkness < 0.5;
	}

	/**
	 * Check color's darkness
	 *
	 * @param string $color Hex color code
	 *
	 * @return bool
	 */
	public static function is_dark( $color ) {
		return ! self::is_light( $color );
	}

	/**
	 * Return rgba(r, g, b, a) CSS color format
	 *
	 * @param string $color Hex color code
	 * @param int $fraction Alpha transparency percentage. Value between 0 and 1
	 *
	 * @return string
	 */
	public static function to_rgba( $color, $fraction = 1 ) {
		$rgba   = self::to_rgb( $color );
		$rgba[] = self::alpha( $fraction );

		// use %s for alpha to prevent precision issues
		return vsprintf( 'rgba(%d, %d, %d, %s)', $rgba );
	}

	/**
	 * Check passed value, and convert to decimal if necessary
	 *
	 * @param float|int $alpha
	 *
	 * @return float|int
	 */
	public static function alpha( $alpha ) {
		if ( $alpha <= 1 ) {
			return $alpha;
		} else {
			$alpha = $alpha / 100;
		}

		return $alpha;
	}

	/**
	 * Convert hex to RGB
	 *
	 * @param string $color Hex color to be converted
	 *
	 * @return array[$red, $green, $blue] Array of values 0 to 255
	 */
	public static function to_rgb( $color ) {
		$color = self::validate( $color );

		return sscanf( $color, '#%02x%02x%02x' );
	}

	/**
	 * Convert an RGB color to a hex color
	 * @param array[$red, $green, $blue] $color RGB color code array
	 *
	 * @return string
	 */
	public static function to_hex( $color ) {
		return vsprintf( '#%02x%02x%02x', $color );
	}

	/**
	 * Validate hex code. Doesn't support alpha-channel hex codes
	 *
	 * @param string $code
	 *
	 * @return string|bool
	 */
	public static function validate( $code ) {
		$color = str_replace( '#', '', $code );
		// for shorthand hex colors
		if ( strlen( $color ) === 3 ) {
			$color = $color[0] . $color[0] . $color[1] . $color[1] . $color[2] . $color[2];
		}

		return preg_match( '/^[a-f0-9]{6}$/i', $color ) ? "#{$color}" : false;
	}
}
