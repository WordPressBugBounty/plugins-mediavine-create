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
		if (empty($color1) && !empty($color2)) { $color1 = $color2; }
		elseif (empty($color2) && !empty($color1)) { $color2 = $color1; }
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
	 * @param string  $color
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
	 * @param string  $color
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
	 * Linearise a single sRGB channel value for luminance calculation.
	 *
	 * @param float $c Channel value 0–1.
	 *
	 * @return float Linear-light value.
	 */
	private static function linearise( $c ) {
		return $c <= 0.04045
			? $c / 12.92
			: pow( ( $c + 0.055 ) / 1.055, 2.4 );
	}

	/**
	 * WCAG 2.x relative luminance of a color (0 = black, 1 = white).
	 *
	 * @param string $color Hex color code.
	 *
	 * @return float Luminance between 0 and 1.
	 */
	public static function luminance( $color ) {
		$color = self::validate( $color );
		list( $r, $g, $b ) = self::to_rgb( $color );

		$r = self::linearise( $r / 255 );
		$g = self::linearise( $g / 255 );
		$b = self::linearise( $b / 255 );

		return 0.2126 * $r + 0.7152 * $g + 0.0722 * $b;
	}

	/**
	 * WCAG 2.x contrast ratio between two colors (1–21).
	 *
	 * @param string $fg Foreground hex color.
	 * @param string $bg Background hex color.
	 *
	 * @return float Contrast ratio.
	 */
	public static function contrast_ratio( $fg, $bg ) {
		$l1 = self::luminance( $fg );
		$l2 = self::luminance( $bg );

		if ( $l1 > $l2 ) {
			return ( $l1 + 0.05 ) / ( $l2 + 0.05 );
		}
		return ( $l2 + 0.05 ) / ( $l1 + 0.05 );
	}

	/**
	 * Return a contrasting text color for a given background using WCAG 2.x luminance.
	 *
	 * Targets a minimum contrast ratio of 5.0:1 (exceeds WCAG AA 4.5:1).
	 * Falls back to whichever of $light/$dark achieves the higher ratio.
	 *
	 * @param string $color     Hex color code of the background.
	 * @param string $light     Light text option. Default '#ffffff'.
	 * @param string $dark      Dark text option. Default '#000000'.
	 * @param float  $min_ratio Minimum acceptable contrast ratio. Default 5.0.
	 *
	 * @return string Hex color that contrasts with the background.
	 */
	public static function contrast_text( $color, $light = '#ffffff', $dark = '#000000', $min_ratio = 5.0 ) {
		$light_ratio = self::contrast_ratio( $light, $color );
		$dark_ratio  = self::contrast_ratio( $dark, $color );

		if ( $light_ratio >= $min_ratio ) {
			return $light;
		}
		if ( $dark_ratio >= $min_ratio ) {
			return $dark;
		}
		// Neither hits target — return whichever is higher.
		return $light_ratio > $dark_ratio ? $light : $dark;
	}

	/**
	 * Return rgba(r, g, b, a) CSS color format
	 *
	 * @param string $color Hex color code
	 * @param int    $fraction Alpha transparency percentage. Value between 0 and 1
	 *
	 * @return string
	 */
	public static function to_rgba( $color, $fraction = 1 ) {
		$rgba   = self::to_rgb( $color );
		if (!is_array($rgba)) { return array(); }
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
	 * @param array[ $red, $green, $blue] $color RGB color code array
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
