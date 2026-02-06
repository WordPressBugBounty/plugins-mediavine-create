<?php
namespace Mediavine\Create\Helpers;

/**
 * Handles cleaning up empty HTML artifacts from the Slate editor
 * 
 * The Slate editor can create empty list items, orphaned break tags,
 * and HTML structures with no actual content that need to be cleaned up
 * before rendering instructions.
 */
class Instructions_Cleaner {

	/**
	 * Clean instructions HTML by removing empty tags and whitespace artifacts
	 * 
	 * @param string $html Raw HTML from Slate editor
	 * @param int    $creation_id Creation ID for logging context (optional)
	 * @return string|null Cleaned HTML or null if effectively empty
	 */
	public static function clean_instructions( $html, $creation_id = 0 ) {
		if ( empty( $html ) ) {
			self::log_cleaning_failure( 'empty_input', $creation_id, 'HTML input is empty' );
			return null;
		}

		// Remove empty tags and whitespace-only content
		$cleaned = self::remove_empty_tags( $html );

		// If nothing substantial remains, return null
		if ( self::is_effectively_empty( $cleaned ) ) {
			self::log_cleaning_failure( 'effectively_empty', $creation_id, 'HTML is effectively empty after cleaning' );
			return null;
		}

		return $cleaned;
	}

	/**
	 * Remove common empty tags that Slate editor creates
	 * 
	 * @param string $html HTML to clean
	 * @param int    $creation_id Creation ID for logging context (optional)
	 * @return string Cleaned HTML
	 */
	private static function remove_empty_tags( $html, $creation_id = 0 ) {
		// Remove common empty tags that Slate creates
		$patterns = [
			'/<li>\s*<\/li>/' => 'Empty list items',
			'/<li><br\s*\/?><\/li>/' => 'List items with just breaks',
			'/<p>\s*<\/p>/' => 'Empty paragraphs',
			'/<p><br\s*\/?><\/p>/' => 'Paragraphs with just breaks',
			'/<p>&nbsp;<\/p>/' => 'Paragraphs with just non-breaking spaces',
			'/<div>\s*<\/div>/' => 'Empty divs',
			'/<span>\s*<\/span>/' => 'Empty spans',
			'/<strong>\s*<\/strong>/' => 'Empty strong formatting',
			'/<em>\s*<\/em>/' => 'Empty emphasis',
			'/<u>\s*<\/u>/' => 'Empty underline',
			'/<h[1-6]>\s*<\/h[1-6]>/' => 'Empty headings',
			'/\s*<br\s*\/?>\s*$/' => 'Trailing breaks',
			'/^\s*<br\s*\/?>/' => 'Leading breaks',
		];

		foreach ( $patterns as $pattern => $description ) {
			$html = preg_replace( $pattern, '', $html );
		}

		// Clean up multiple consecutive whitespace/breaks
		$html = preg_replace( '/(<br\s*\/?>){2,}/', '<br>', $html );
		
		// Remove orphaned breaks between list items or other block elements
		$orphan_patterns = [
			'/<\/li>\s*<br\s*\/?>\s*<li>/' => '</li><li>',
			'/<\/ol>\s*<br\s*\/?>\s*<ul>/' => '</ol><ul>',
			'/<\/ul>\s*<br\s*\/?>\s*<ol>/' => '</ul><ol>',
		];

		foreach ( $orphan_patterns as $pattern => $replacement ) {
			$html = preg_replace( $pattern, $replacement, $html );
		}

		// Clean up whitespace around block elements
		$html = preg_replace( '/\s*<(ol|ul|li|h[1-6]|p|div)([^>]*)>\s*/', '<$1$2>', $html );
		$html = preg_replace( '/\s*<\/(ol|ul|li|h[1-6]|p|div)>\s*/', '</$1>', $html );
		$html = trim( $html );

		return $html;
	}

	/**
	 * Check if HTML is effectively empty (no meaningful content)
	 * 
	 * @param string $html HTML to check
	 * @param int    $creation_id Creation ID for logging context (optional)
	 * @return bool True if effectively empty
	 */
	private static function is_effectively_empty( $html, $creation_id = 0 ) {
		if ( empty( $html ) ) {
			return true;
		}

		// Strip all HTML tags and check if any content remains
		$text_content = strip_tags( $html );
		
		// Remove various types of whitespace and HTML entities
		$text_content = preg_replace( '/\s+/', '', $text_content );
		$text_content = str_replace( '&nbsp;', '', $text_content );
		$text_content = html_entity_decode( $text_content, ENT_QUOTES, 'UTF-8' );

		return empty( $text_content );
	}

	/**
	 * Check if HTML contains only empty list structure
	 * 
	 * Sometimes Slate creates valid list HTML but with no content in the items.
	 * This detects those cases.
	 * 
	 * @param string $html HTML to check
	 * @param int    $creation_id Creation ID for logging context (optional)
	 * @return bool True if contains only empty lists
	 */
	public static function has_only_empty_lists( $html, $creation_id = 0 ) {
		// Remove all non-list tags and see if anything meaningful remains
		$list_only = preg_replace( '/<(?!\/?(ol|ul|li))[^>]*>/', '', $html );
		$list_only = strip_tags( $list_only );
		$list_only = preg_replace( '/\s+/', '', $list_only );
		
		return empty( $list_only );
	}

	/**
	 * Validate that cleaned HTML has proper list structure
	 * 
	 * Ensures lists are properly nested and don't have structural issues
	 * 
	 * @param string $html HTML to validate
	 * @param int    $creation_id Creation ID for logging context (optional)
	 * @return bool True if structure is valid
	 */
	public static function validate_list_structure( $html, $creation_id = 0 ) {
		// Check for basic structural issues
		$open_ol = preg_match_all( '/<ol[^>]*>/', $html );
		$close_ol = preg_match_all( '/<\/ol>/', $html );
		$open_ul = preg_match_all( '/<ul[^>]*>/', $html );
		$close_ul = preg_match_all( '/<\/ul>/', $html );

		// Lists should have matching open/close tags
		if ( $open_ol !== $close_ol || $open_ul !== $close_ul ) {
			self::log_cleaning_failure( 'structure_invalid', $creation_id, 'Mismatched list tags - structure is invalid' );
			return false;
		}

		// Check for list items outside of lists (common Slate issue)
		$without_lists = preg_replace( '/<(ol|ul)[^>]*>.*?<\/\1>/s', '', $html );
		$orphaned_li = preg_match_all( '/<li[^>]*>/', $without_lists );
		if ( $orphaned_li > 0 ) {
			self::log_cleaning_failure( 'orphaned_list_items', $creation_id, "Found {$orphaned_li} orphaned list items outside of lists" );
			return false;
		}

		return true;
	}

	/**
	 * Log instructions cleaning failures for debugging
	 *
	 * @param string $failure_type Type of cleaning failure
	 * @param int    $creation_id Creation ID for context
	 * @param string $details Details about the failure
	 */
	private static function log_cleaning_failure( $failure_type, $creation_id, $details ) {
		// Only log if WP_DEBUG is enabled AND error logging is enabled in settings
		if ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG ) {
			return;
		}

		// Check if error logging is enabled in Create settings
		$enable_logging = \Mediavine\Settings::get_setting( 'mv_create_enable_logging' );
		if ( ! $enable_logging ) {
			return;
		}

		$message = sprintf(
			'[MV Create Instructions Cleaning Failure] Type: %s | Creation: %d | Details: %s',
			$failure_type,
			$creation_id,
			$details
		);

		error_log( $message );
	}
}