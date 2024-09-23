<?php

namespace Mediavine\Create;

class JSON_LD_Helpers {

	/**
	 * Removes HTML from a string.
	 *
	 * @param string $content
	 * @return string
	 */
	public function remove_html( $content ) {
		// Remove any new lines already in there
		$content = str_replace( "\n", '', $content );
		// Replace <br /> and lists with \n
		$content = str_replace( [ '<br />', '<br>', '<br/>', '</ol>', '</ul>', '</li>' ], "\n", $content );
		// Replace </p> with \n\n
		$content = str_replace( '</p>', "\n\n", $content );
		// Remove <p> and lists
		$content = str_replace( [ '<p>', '<ol>', '<ul>', '<li>' ], '', $content );
		// Remove shortcodes
		$content = strip_shortcodes( $content );
		// Remove remaining HTML
		$content = wp_strip_all_tags( $content );
		// Remove any trailing whitespace
		$content = rtrim( $content );

		return $content;
	}

	/**
	 * Removes square brackets from a string.
	 *
	 * @param string $content
	 * @return string
	 */
	public function strip_square_brackets( $content ) {
		$content = str_replace( [ '[', ']' ], '', $content );

		return $content;
	}

	/**
	 * Parse time in seconds as a duration array.
	 *
	 * @param string $seconds
	 * @return array Parsed duration times
	 */
	public function parse_seconds_to_times( $seconds ) {
		// Force seconds as in integer
		$seconds = (int) $seconds;

		// gmdate() doesn't play nice with days and years because of leap years
		$days  = floor( $seconds / 86400 );
		$years = floor( $days / 365 );
		if ( $years ) {
			$days = $days - $years * 365;
		}

		// Prep values
		$time_array = [
			'original' => $seconds,
			'years'    => $years,
			'days'     => $days,
			'hours'    => (int) gmdate( 'H', $seconds ),
			'minutes'  => (int) gmdate( 'i', $seconds ),
			'seconds'  => (int) gmdate( 's', $seconds ),
		];

		return $time_array;
	}

	/**
	 * Builds the time into a duration format for schema
	 *
	 * @param array $time_array Array with the following 'years', 'days',
	 *                          'hours', 'minutes', and 'seconds'.
	 * @param array $added_arrays Array with more $time_arrays to be added
	 *                            to duration
	 * @return string Duration in required schema format
	 */
	public function build_duration( $time_array, $added_arrays = null ) {
		// Make sure time array is built
		if ( ! is_array( $time_array ) ) {
			// Only return if there are no added arrays
			if ( ! $time_array && '0' !== $time_array && empty( $added_arrays ) ) {
				return null;
			}
			$time_array = $this->parse_seconds_to_times( $time_array );
		}
		$durations      = [
			'years',
			'days',
			'hours',
			'minutes',
			'seconds',
		];
		$date_durations = [
			'Y' => 'years',
			'D' => 'days',
		];
		$time_durations = [
			'H' => 'hours',
			'M' => 'minutes',
			'S' => 'seconds',
		];
		if ( is_array( $added_arrays ) ) {
			foreach ( $added_arrays as $added_array ) {
				// Make sure time array is built
				if ( ! is_array( $added_array ) ) {
					if ( ! $added_array ) {
						continue;
					}
					$added_array = $this->parse_seconds_to_times( $added_array );
				}

				foreach ( $durations as $current_duration ) {
					// Create base time array if field missing
					if ( empty( $time_array[ $current_duration ] ) ) {
						$time_array[ $current_duration ] = 0;
					}
					// Add time to time array
					if ( ! empty( $added_array[ $current_duration ] ) ) {
						$time_array[ $current_duration ] = intval( $time_array[ $current_duration ] ) + intval( $added_array[ $current_duration ] );
					}
				}
			}

			if ( ! empty( $time_array['seconds'] ) && $time_array['seconds'] > 60 ) {
				$time_array['minutes'] = $time_array['minutes'] + floor( $time_array['seconds'] / 60 );
				$time_array['seconds'] = $time_array['seconds'] % 60;
			}
			if ( ! empty( $time_array['minutes'] ) && $time_array['minutes'] > 60 ) {
				$time_array['hours']   = $time_array['hours'] + floor( $time_array['minutes'] / 60 );
				$time_array['minutes'] = $time_array['minutes'] % 60;
			}
			if ( ! empty( $time_array['hours'] ) && $time_array['hours'] > 24 ) {
				$time_array['days']  = $time_array['days'] + floor( $time_array['hours'] / 24 );
				$time_array['hours'] = $time_array['hours'] % 24;
			}
			if ( ! empty( $time_array['days'] ) && $time_array['days'] > 365 ) {
				$time_array['years'] = $time_array['years'] + floor( $time_array['days'] / 365 );
				$time_array['days']  = $time_array['days'] % 365;
			}
		}

		$duration = 'P';
		foreach ( $date_durations as $abbr => $date_duration ) {
			if ( ! empty( $time_array[ $date_duration ] ) ) {
					$duration .= intval( $time_array[ $date_duration ] ) . $abbr;
			}
		}
		$duration .= 'T';
		foreach ( $time_durations as $abbr => $time_duration ) {
			if ( ! empty( $time_array[ $time_duration ] ) ) {
					$duration .= intval( $time_array[ $time_duration ] ) . $abbr;
			}
		}

		// Handle 0-y values
		if ( 'PT' === $duration ) {
			$duration .= '0S';
		}

		return $duration;
	}
}
