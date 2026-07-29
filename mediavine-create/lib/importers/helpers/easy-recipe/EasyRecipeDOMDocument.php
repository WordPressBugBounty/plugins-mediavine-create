<?php
/**
 * Easy Recipe plugin code to get recipe data.
 */

namespace Mediavine\Create\Importers\Helpers\EasyRecipe;

use DOMDocument;

use stdClass;

	/**
	 * Class EasyRecipeDOMDocument
	 */
class EasyRecipeDOMDocument extends DOMDocument {
	private $isValidHTML = false;

	public function __construct( $content, $load = true, $encoding = 'UTF-8' ) {
		parent::__construct( '1.0', $encoding );

		libxml_use_internal_errors( true );

		if ( $load && ! @$this->loadHTML( '<html><head><meta http-equiv="Content-Type" content="text/html; charset=utf-8" /></head><body>' . $content ) ) {
			return;
		}

		$this->isValidHTML = true;
	}

	/**
	 * Returns TRUE if we successfully load the content
	 */
	function isValid() {
		return $this->isValidHTML;
	}

	public function hasClass( $node, $className ) {
		$item = $node->attributes->getNamedItem( 'class' );
		if ( $item ) {
			$classes = explode( ' ', $item->nodeValue );
			for ( $j = 0; $j < count( $classes ); $j++ ) {
				if ( $classes[ $j ] == $className ) {
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * Removes class $class from $element
	 *
	 * @param DOMElement $element
	 * @param string     $class
	 */
	public function removeClass( $element, $class ) {
		$newClass = trim( preg_replace( "/ *(?:$class)/i", '', $element->getAttribute( 'class' ) ) );
		if ( $newClass != '' ) {
			$element->setAttribute( 'class', $newClass );
		} else {
			$element->removeAttribute( 'class' );
		}
	}

	/**
	 * Removes elements that have class $className
	 *
	 * @param string  $className
	 * @param string  $tag
	 * @param DOMNode $node
	 */
	public function removeElementsByClassName( $className, $tag = '*', $node = null ) {

		if ( $node == null ) {
			$node = $this;
		}
		$elements = $node->getElementsByClassName( $className, $tag );
		/** @var DOMNode $element */
		foreach ( $elements as $element ) {
			$element->parentNode->removeChild( $element );
		}

	}

	public function innerHTML( $node ) {
		if ( ! isset( $node->firstChild ) ) {
			return false;
		}
		$value = '';
		for ( $child = $node->firstChild; $child; $child = $child->nextSibling ) {
			$value .= $this->saveXML( $child );
		}
		return $value;
	}

	/**
	 * Get all elements that have a tag of $tag and class of $className
	 *
	 * @param        $className string The class name to search for
	 * @param string                                        $tag       string Tag of the items to search
	 * @param DOMElement                                    $node
	 *
	 * @return array
	 */

	public function getElementsByClassName( $className, $tag = '*', $node = null ) {
		$classNames  = explode( '|', str_replace( ' ', '', $className ) );
		$nodes       = array();
		$domNodeList = ( $node == null ) ? $this->getElementsByTagName( $tag ) : $node->getElementsByTagName( $tag );

		for ( $i = 0; $i < $domNodeList->length; $i++ ) {
			/** @var DOMElement $element */
			$element = $domNodeList->item( $i );
			if ( $element->hasAttributes() && $element->hasAttribute( 'class' ) ) {
				for ( $j = 0; $j < count( $classNames ); $j++ ) {
					if ( $this->hasClass( $element, $classNames[ $j ] ) ) {
						$nodes[] = $domNodeList->item( $i );
						break;
					}
				}
			}
		}

		return $nodes;
	}

	/**
	 * Convenience method to return a single element by class name when we know there's only going to be one
	 * If there is actually more than 1, return the first
	 *
	 * @param        $className
	 * @param string    $tag
	 * @param null      $node
	 * @param bool      $deep
	 *
	 * @return null
	 */
	public function getElementByClassName( $className, $tag = '*', $node = null, $deep = true ) {
		$nodes = $this->getElementsByClassName( $className, $tag, $node, $deep );
		return count( $nodes ) > 0 ? $nodes[0] : null;
	}

	/**
	 * @param             $tag
	 * @param             $propertyName
	 * @param             $propertyValue
	 * @param DOMDocument   $node
	 *
	 * @return array
	 */
	public function getElementsByProperty( $tag, $propertyName, $propertyValue, $node = null ) {
		$nodes  = $node == null ? $this->getElementsByTagName( $tag ) : $node->getElementsByTagName( $tag );
		$result = array();
		/** @var DOMElement $node */
		foreach ( $nodes as $node ) {
			if ( $node->hasAttribute( $propertyName ) ) {
				if ( $node->getAttribute( $propertyName ) == $propertyValue ) {
					$result[] = $node;
				}
			}
		}
		return $result;
	}

	public function getElementValuesByProperty( $tag, $propertyName, $propertyValue, $node = null ) {
		$result = array();
		$nodes  = $this->getElementsByProperty( $tag, $propertyName, $propertyValue, $node );
		foreach ( $nodes as $node ) {
			$result[] = $this->innerHTML( $node );
		}
		return $result;
	}

	public function getElementValueByProperty( $tag, $propertyName, $propertyValue, $node = null ) {
		$result = $this->getElementValuesByProperty( $tag, $propertyName, $propertyValue, $node );
		return count( $result ) > 0 ? $result[0] : null;
	}

	public function getElementValuesByClassName( $className, $tag = '*', $node = null ) {
		$nodes  = $this->getElementsByClassName( $className, $tag, $node );
		$result = array();
		foreach ( $nodes as $node ) {
			$result[] = $this->innerHTML( $node );
		}
		return $result;
	}

	public function getElementValueByClassName( $className, $tag = '*', $node = null ) {
		$node = $this->getElementByClassName( $className, $tag, $node );
		return $this->innerHTML( $node );
	}

	public function getElementAttributeByClassName( $className, $attributeName, $tag = '*', $node = null ) {
		$nodes  = $this->getElementsByClassName( $className, $tag, $node );
		$result = array();
		/** @var DOMElement $node */
		foreach ( $nodes as $node ) {
			if ( ( $attributeValue = $node->getAttribute( $attributeName ) ) != '' ) {
				$result[] = $attributeValue;
			}
		}
		return count( $result ) > 0 ? $result[0] : false;
	}

	/**
	 * @param        $node
	 * @param string $tag
	 *
	 * @return array
	 */
	public function getChildrenByTagName( $node, $tag = '*' ) {
		$nodes = array();
		for ( $child = $node->firstChild; $child; $child = $child->nextSibling ) {
			if ( $child instanceof DOMElement ) {
				if ( $tag == '*' || $tag == $child->tagName ) {
					$nodes[] = $child;
				}
			}
			$childNodes = $this->getChildrenByTagName( $child, $tag );
			$nodes      = array_merge( $nodes, $childNodes );
		}
		return $nodes;
	}

	public function getElementAttributesByTagName( $tag, $attributeName, $selector = '', $value = '', $baseNode = null ) {
		$nodes  = $baseNode == null ? $this->getElementsByTagName( $tag ) : $this->getChildrenByTagName( $baseNode, $tag );
		$result = array();
		/** @var DOMElement $node */
		foreach ( $nodes as $node ) {
			if ( $baseNode != null ) {
			}
			if ( $selector != '' ) {
				if ( $node->getAttribute( $selector ) != $value ) {
					continue;
				}
			}
			if ( ( $attributeValue = $node->getAttribute( $attributeName ) ) != '' ) {
				$result[] = $attributeValue;
			}
		}
		return $result;
	}

	public function getElementAttributeByTagName( $tag, $attributeName, $selector = '', $value = '', $baseNode = null ) {
		$result = $this->getElementAttributesByTagName( $tag, $attributeName, $selector, $value, $baseNode );
		return count( $result ) > 0 ? $result[0] : false;
	}

	/**
	 * Sets the text value for elements of class $className
	 * The $currentValue both explicitly identifies an ambigous element, and the actual part of the text to be replaced by $value
	 *
	 * @param $className    string
	 *                      The class name of the element(s) to adjust
	 * @param $value        string
	 *                      The value to set
	 * @param $currentValue string
	 *                      Disambiguator and also the part of the text that is to be replaced by $value
	 */
	public function setValueByClassName( $className, $value, $currentValue = '' ) {
		$nodes = $this->getElementsByClassName( $className );
		for ( $i = 0; $i < count( $nodes ); $i++ ) {
			if ( $currentValue == '' ) {
				$nodes[ $i ]->nodeValue = $value;
			} else {
				if ( preg_match( "/^$currentValue(.*)$/", $nodes[ $i ]->firstChild->nodeValue, $regs ) ) {
					$nodes[ $i ]->firstChild->nodeValue = $value . $regs[1];
				}
			}
		}
	}

	/**
	 * Gets the styles of $element as an associative array of style property/value pairs
	 *
	 * @param $element DOMElement
	 *                 The element for which to get the styles
	 *
	 * @return array An associative array of style property/values
	 */
	public function getStyles( DOMElement $element ) {
		$result      = array();
		$styleString = $element->getAttribute( 'style' );
		if ( $styleString == '' ) {
			return $result;
		}
		$styles = explode( ';', $styleString );
		for ( $i = 0; $i < count( $styles ); $i++ ) {
			if ( $styles[ $i ] != '' ) {
				$styleEntry                       = explode( ':', $styles[ $i ] );
				$result[ trim( $styleEntry[0] ) ] = trim( $styleEntry[1] );
			}
		}
		return $result;
	}

	/**
	 * Set style property $style to $value on $element
	 *
	 * @param $element DOMElement
	 *                 The elemnt to set the style for
	 * @param $style   string
	 *                 The style property name
	 * @param $value   string
	 *                 The value to set
	 */
	public function setStyle( DOMElement $element, $style, $value ) {
		$styles           = $this->getStyles( $element );
		$styles[ $style ] = $value;
		$styleString      = '';
		foreach ( $styles as $property => $value ) {
			$styleString .= $property . ':' . $value . ';';
		}
		$element->setAttribute( 'style', rtrim( $styleString, ';' ) );
	}

	/**
	 * Remove $style from $element
	 *
	 * @param $element DOMElement
	 *                 The elemnt to remove the style from
	 * @param $style   string
	 *                 The style property to remove
	 */
	public function removeStyle( DOMElement $element, $style ) {
		$styles = $this->getStyles( $element );
		if ( ! isset( $styles[ $style ] ) ) {
			return;
		}
		unset( $styles[ $style ] );
		$styleString = '';
		foreach ( $styles as $property => $value ) {
			$styleString .= $property . ':' . $value . ';';
		}
		if ( $styleString == '' ) {
			$element->removeAttribute( 'style' );
		} else {
			$element->setAttribute( 'style', rtrim( $styleString, ';' ) );
		}
	}

	/**
	 * Adds the class $class to the element $element
	 *
	 * @param $element DOMElement
	 *                 The element to use
	 * @param $class   string
	 *                 The class to add
	 */
	public function addClass( DOMElement $element, $class ) {
		$classes  = $element->getAttribute( 'class' );
		$classes .= " $class";
		$element->setAttribute( 'class', trim( $classes ) );
	}


	/**
	 * Get the processed html for the post.
	 * Needs to remove the extra stuff saveHTML adds, and wrap it in the original surrounding code
	 *
	 * @param bool $bodyOnly
	 *
	 * @return bool|string
	 */
	public function getHTML( $bodyOnly = false ) {
		if ( $bodyOnly ) {
			$body = $this->getElementsByTagName( 'body' );
			return $this->innerHTML( $body->item( 0 ) );
		}

		return $this->saveHTML();
	}

	/**
	 * Retrieves a list of microdata items.
	 *
	 * @param string $schema
	 *
	 * @return DOMNodeList A DOMNodeList containing all top level microdata items.
	 */
	public function getItems( $schema = '' ) {
		if ( empty( $schema ) ) {
			return $this->xpath()->query( '//*[@itemscope and not(@itemprop)]' );
		} else {
			return $this->xpath()->query( "//*[@itemscope and @itemtype='$schema' and not(@itemprop)]" );
		}
	}

	/**
	 * Creates a DOMXPath to query this document.
	 *
	 * @return DOMXPath object.
	 */
	public function xpath() {
		return new DOMXPath( $this );
	}
}
