<?php
namespace Mediavine\Create\Helpers;

/**
 * Handles injection of schema IDs into instruction list items
 * 
 * Provides both DOM-based processing (preferred) and regex fallback
 * for environments where DOM manipulation fails.
 */
class Schema_Id_Injector {

	/**
	 * Add schema IDs to list items in instructions HTML
	 * 
	 * @param string $html HTML content
	 * @param int    $creation_id Creation ID for schema anchors
	 * @return string HTML with schema IDs added
	 */
	public static function add_schema_ids( $html, $creation_id ) {
		if ( empty( $html ) || empty( $creation_id ) ) {
			return $html;
		}

		// Try DOM processing first (more reliable)
		$dom_result = self::add_schema_ids_dom( $html, $creation_id );
		
		if ( false !== $dom_result ) {
			return $dom_result;
		}

		// Fallback to regex if DOM processing fails
		self::log_dom_failure( 'regex_fallback', $creation_id, 'DOM processing failed, falling back to regex method' );
		$regex_result = self::add_schema_ids_regex( $html, $creation_id );
		
		return $regex_result;
	}

	/**
	 * Add schema IDs using DOM manipulation (preferred method)
	 * 
	 * @param string $html HTML content
	 * @param int    $creation_id Creation ID for schema anchors
	 * @return string|false HTML with IDs added, or false on failure
	 */
	private static function add_schema_ids_dom( $html, $creation_id ) {
		// Preemptive checks to avoid libxml memory issues
		$safety_check = self::can_safely_use_dom( $html );
		if ( ! $safety_check['safe'] ) {
			self::log_dom_failure( 'safety_check_failed', $creation_id, $safety_check['reason'] );
			return false; // Skip DOM processing, use regex fallback
		}
		
		// Create new DOMDocument instance
		$dom = new \DOMDocument();
		
		// Suppress DOM parsing errors since we'll handle them gracefully
		if ( function_exists( 'libxml_use_internal_errors' ) ) {
			$libxml_previous_state = libxml_use_internal_errors( true );
		}

		try {
			// Load HTML with proven Strategy 1 (Direct with XML encoding)
			$load = $dom->loadHTML( '<?xml encoding="UTF-8">' . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD );
			
			if ( ! $load ) {
				self::log_dom_failure( 'dom_load_failed', $creation_id, 'Failed to load HTML into DOM' );
				return false;
			}
			
			// Find and process list items
			$lis = $dom->getElementsByTagName( 'li' );
			$li_count = $lis->length;
			
			if ( $li_count === 0 ) {
				self::log_dom_failure( 'no_list_items', $creation_id, 'DOM loaded successfully but found 0 list items' );
				return false;
			}
			
			$counter = 1;
			foreach ( $lis as $li ) {
				// Only add ID if one doesn't already exist
				if ( ! $li->hasAttribute( 'id' ) ) {
					$li->setAttribute( 'id', "mv_create_{$creation_id}_{$counter}" );
				}
				$counter++;
			}

			// Extract content from body
			$output = self::extract_content_from_body( $dom );
			
			if ( empty( $output ) ) {
				self::log_dom_failure( 'content_extraction_failed', $creation_id, 'Failed to extract content from DOM' );
				return false;
			}
			
			// Validate DOM output
			$validation_result = self::validate_dom_output( $output, $html, $creation_id );
			if ( $validation_result['valid'] ) {
				return $output;
			} else {
				// DOM processing failed validation - trigger regex fallback
				self::log_dom_failure( 'validation_failed', $creation_id, $validation_result['reason'] );
				return false;
			}

		} catch ( \Exception $e ) {
			// DOM processing failed, return false to trigger regex fallback
			self::log_dom_failure( 'dom_exception', $creation_id, 'Exception: ' . $e->getMessage() );
			return false;
		} finally {
			// Restore previous libxml error handling state
			if ( function_exists( 'libxml_use_internal_errors' ) && isset( $libxml_previous_state ) ) {
				libxml_use_internal_errors( $libxml_previous_state );
			}
		}
	}

	/**
	 * Add schema IDs using regex patterns (fallback method)
	 * 
	 * @param string $html HTML content
	 * @param int    $creation_id Creation ID for schema anchors
	 * @return string HTML with IDs added
	 */
	private static function add_schema_ids_regex( $html, $creation_id ) {
		$counter = 1;
		
		// Add IDs to list items that don't already have them
		$html = preg_replace_callback(
			'/<li(?![^>]*\sid=)([^>]*)>/i',
			function( $matches ) use ( $creation_id, &$counter ) {
				$result = '<li id="mv_create_' . $creation_id . '_' . $counter . '"' . $matches[1] . '>';
				$counter++;
				return $result;
			},
			$html
		);
		
		return $html;
	}

	/**
	 * Validate that schema IDs were properly added
	 * 
	 * @param string $html HTML to validate
	 * @param int    $creation_id Expected creation ID
	 * @return bool True if IDs are present and properly formatted
	 */
	public static function validate_schema_ids( $html, $creation_id ) {
		// Count list items
		$li_count = preg_match_all( '/<li[^>]*>/', $html, $matches );
		
		if ( $li_count === 0 ) {
			// No list items, so no IDs needed
			return true;
		}

		// Count properly formatted schema IDs
		$schema_id_count = preg_match_all( '/id="mv_create_' . preg_quote( $creation_id, '/' ) . '_\d+"/', $html );
		
		// All list items should have schema IDs
		return $schema_id_count === $li_count;
	}

	/**
	 * Extract schema step data for JSON-LD generation
	 * 
	 * @param string $html HTML with schema IDs
	 * @param int    $creation_id Creation ID
	 * @param string $canonical_url Base URL for anchors
	 * @return array Array of step data for JSON-LD
	 */
	public static function extract_schema_steps( $html, $creation_id, $canonical_url ) {
		$steps = [];
		
		// Match list items with their IDs and content
		preg_match_all( '/<li[^>]*id="mv_create_' . preg_quote( $creation_id, '/' ) . '_(\d+)"[^>]*>(.*?)<\/li>/s', $html, $matches, PREG_SET_ORDER );
		
		foreach ( $matches as $match ) {
			$step_number = (int) $match[1];
			$step_content = strip_tags( $match[2] );
			$step_content = html_entity_decode( $step_content, ENT_QUOTES, 'UTF-8' );
			$step_content = trim( $step_content );
			
			if ( ! empty( $step_content ) ) {
				$steps[] = [
					'@type'    => 'HowToStep',
					'text'     => $step_content,
					'position' => $step_number,
					'name'     => $step_content,
					'url'      => $canonical_url . '#mv_create_' . $creation_id . '_' . $step_number,
				];
			}
		}
		
		return $steps;
	}

	/**
	 * Check if HTML needs schema ID processing
	 * 
	 * @param string $html HTML to check
	 * @return bool True if contains list items that need IDs
	 */
	public static function needs_schema_ids( $html ) {
		// Check if there are list items without schema IDs
		return preg_match( '/<li(?![^>]*id="mv_create_\d+_\d+")[^>]*>/', $html );
	}

	/**
	 * Validate DOM processing output to detect silent failures
	 * 
	 * @param string $output HTML output from DOM processing
	 * @param string $original_html Original HTML input
	 * @param int    $creation_id Creation ID for logging context
	 * @return array Array with 'valid' boolean and 'reason' string
	 */
	private static function validate_dom_output( $output, $original_html, $creation_id = 0 ) {
		// Check 1: Output should not be empty if input had content
		if ( empty( $output ) && ! empty( trim( strip_tags( $original_html ) ) ) ) {
			return [
				'valid' => false,
				'reason' => 'Check 1 failed: Output is empty while input had content'
			];
		}

		// Check 2: Should still contain list items if original did
		$original_li_count = preg_match_all( '/<li[^>]*>/', $original_html );
		$output_li_count = preg_match_all( '/<li[^>]*>/', $output );
		
		if ( $original_li_count > 0 && $output_li_count === 0 ) {
			return [
				'valid' => false,
				'reason' => sprintf( 'Check 2 failed: Lost all list items (original: %d, output: 0)', $original_li_count )
			];
		}

		// Check 3: Should not have drastically different content length
		$original_text = trim( strip_tags( $original_html ) );
		$output_text = trim( strip_tags( $output ) );
		
		if ( strlen( $original_text ) > 10 && strlen( $output_text ) < ( strlen( $original_text ) * 0.5 ) ) {
			return [
				'valid' => false,
				'reason' => sprintf( 'Check 3 failed: Lost too much content (original: %d chars, output: %d chars)', strlen( $original_text ), strlen( $output_text ) )
			];
		}

		// Check 4: Should not contain obvious corruption markers
		$corruption_markers = [
			'&lt;li&gt;' => 'Double-encoded HTML',
			'<?xml' => 'XML declaration leaked through',
			'<!DOCTYPE' => 'DOCTYPE leaked through',
			'&amp;lt;' => 'Triple encoding detected',
		];

		foreach ( $corruption_markers as $marker => $description ) {
			if ( strpos( $output, $marker ) !== false ) {
				return [
					'valid' => false,
					'reason' => 'Check 4 failed: Found corruption marker: ' . $description
				];
			}
		}

		// Check 5: Validate HTML structure integrity
		$original_open_tags = preg_match_all( '/<(ol|ul|li)[^>]*>/i', $original_html );
		$original_close_tags = preg_match_all( '/<\/(ol|ul|li)>/i', $original_html );
		$output_open_tags = preg_match_all( '/<(ol|ul|li)[^>]*>/i', $output );
		$output_close_tags = preg_match_all( '/<\/(ol|ul|li)>/i', $output );

		// Should have roughly the same number of open/close tags
		if ( $original_open_tags !== $output_open_tags || $original_close_tags !== $output_close_tags ) {
			return [
				'valid' => false,
				'reason' => sprintf( 'Check 5 failed: Tag count mismatch - Original: %d open, %d close | Output: %d open, %d close', 
					$original_open_tags, $original_close_tags, $output_open_tags, $output_close_tags )
			];
		}

		// Check 6: Verify IDs were actually added
		$ids_added = preg_match_all( '/id="mv_create_\d+_\d+"/', $output );
		if ( $output_li_count > 0 && $ids_added === 0 ) {
			return [
				'valid' => false,
				'reason' => 'Check 6 failed: No schema IDs were added to list items'
			];
		}

		return [
			'valid' => true,
			'reason' => 'All validation checks passed'
		];
	}

	/**
	 * Check if it's safe to use DOM processing based on memory and content analysis
	 * 
	 * @param string $html HTML content to analyze
	 * @return array Array with 'safe' boolean and 'reason' string
	 */
	private static function can_safely_use_dom( $html ) {
		// Check 1: Content size threshold
		$html_size = strlen( $html );
		if ( $html_size > 100000 ) { // 100KB threshold
			return [
				'safe' => false,
				'reason' => "Content too large ({$html_size} bytes > 100KB limit)"
			];
		}

		// Check 2: Available memory check
		$memory_limit = self::get_memory_limit_bytes();
		$current_usage = memory_get_usage( true );
		$available_memory = $memory_limit - $current_usage;
		
		// Need at least 5x the HTML size in memory for safe DOM processing
		$estimated_dom_memory = $html_size * 5;
		if ( $available_memory < $estimated_dom_memory ) {
			return [
				'safe' => false,
				'reason' => "Insufficient memory (need {$estimated_dom_memory} bytes, have {$available_memory} available)"
			];
		}

		// Check 3: libxml version compatibility
		if ( defined( 'LIBXML_VERSION' ) && LIBXML_VERSION < 20707 ) {
			// libxml < 2.7.7 has issues with LIBXML_HTML_NOIMPLIED
			return [
				'safe' => false,
				'reason' => "libxml version too old (" . LIBXML_VERSION . " < 20707)"
			];
		}

		// Check 4: Nested structure complexity
		$nesting_depth = self::calculate_html_nesting_depth( $html );
		if ( $nesting_depth > 20 ) {
			return [
				'safe' => false,
				'reason' => "HTML too deeply nested ({$nesting_depth} levels > 20 limit)"
			];
		}

		// Check 5: Entity density (high entity count can cause memory issues)
		$entity_count = preg_match_all( '/&[a-zA-Z0-9#]+;/', $html );
		$entity_density = $html_size > 0 ? ( $entity_count / $html_size ) : 0;
		if ( $entity_density > 0.05 ) { // More than 5% entities
			return [
				'safe' => false,
				'reason' => sprintf( "High entity density (%.2f%% > 5%% limit)", $entity_density * 100 )
			];
		}

		return [
			'safe' => true,
			'reason' => 'All safety checks passed'
		]; // Safe to use DOM processing
	}

	/**
	 * Get PHP memory limit in bytes
	 * 
	 * @return int Memory limit in bytes
	 */
	private static function get_memory_limit_bytes() {
		$memory_limit = ini_get( 'memory_limit' );
		
		if ( $memory_limit === '-1' ) {
			return PHP_INT_MAX; // No memory limit
		}
		
		$unit = strtoupper( substr( $memory_limit, -1 ) );
		$value = (int) substr( $memory_limit, 0, -1 );
		
		switch ( $unit ) {
			case 'G':
				return $value * 1024 * 1024 * 1024;
			case 'M':
				return $value * 1024 * 1024;
			case 'K':
				return $value * 1024;
			default:
				return (int) $memory_limit;
		}
	}

	/**
	 * Calculate HTML nesting depth to detect overly complex structures
	 * 
	 * @param string $html HTML content
	 * @return int Maximum nesting depth
	 */
	private static function calculate_html_nesting_depth( $html ) {
		$max_depth = 0;
		$current_depth = 0;
		
		// Simple regex-based depth calculation
		preg_replace_callback(
			'/<(\/?)[^>]+>/',
			function( $matches ) use ( &$current_depth, &$max_depth ) {
				if ( $matches[1] === '/' ) {
					$current_depth = max( 0, $current_depth - 1 );
				} else {
					$current_depth++;
					$max_depth = max( $max_depth, $current_depth );
				}
				return $matches[0];
			},
			$html
		);
		
		return $max_depth;
	}


	/**
	 * Log DOM processing failures for debugging
	 * 
	 * @param string $failure_type Type of DOM failure
	 * @param int    $creation_id Creation ID for context
	 * @param string $details Details about the failure
	 */
	private static function log_dom_failure( $failure_type, $creation_id, $details ) {
		// Only log if WP_DEBUG is enabled
		if ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG ) {
			return;
		}

		$message = sprintf(
			'[MV Create Schema DOM Failure] Type: %s | Creation: %d | Details: %s | Falling back to regex',
			$failure_type,
			$creation_id,
			$details
		);

		error_log( $message );
	}


	/**
	 * Extract content from DOM body element
	 * 
	 * @param \DOMDocument $dom DOM document
	 * @return string Extracted HTML content
	 */
	private static function extract_content_from_body( $dom ) {
		try {
			// Try to extract from body element first
			$body = $dom->getElementsByTagName( 'body' )->item( 0 );
			if ( $body && $body->hasChildNodes() ) {
				$output = '';
				foreach ( $body->childNodes as $child ) {
					$output .= $dom->saveHTML( $child );
				}
				return trim( $output );
			}
			
			// Fallback: extract from document element and clean
			$html_elem = $dom->getElementsByTagName( 'html' )->item( 0 );
			if ( $html_elem ) {
				$result = trim( $dom->saveHTML( $html_elem ) );
				// Clean up HTML wrapper elements
				$result = preg_replace( '~<(?:!DOCTYPE|/?(?:html|head|meta))[^>]*>\s*~i', '', $result );
				$result = preg_replace( '~<body[^>]*>(.*?)</body>~is', '$1', $result );
				return trim( $result );
			}
			
			// Ultimate fallback: full document with aggressive cleanup
			$output = $dom->saveHTML();
			$output = preg_replace( '~<(?:!DOCTYPE|/?(?:html|head|body|meta))[^>]*>\s*~i', '', $output );
			return trim( $output );
			
		} catch ( \Exception $e ) {
			return '';
		}
	}
}