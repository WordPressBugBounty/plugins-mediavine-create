<?php
namespace Mediavine\Create\Exceptions;

class AttributeNotFoundException extends \RuntimeException {
	/**
	 * Name of the affected attribute.
	 *
	 * @var string
	 */
	protected $attribute;

	/**
	 * Name of the affected model.
	 *
	 * @var string
	 */
	protected $model;

	/**
	 * Set the affected attribute.
	 *
	 * @param  string   $attribute
	 * @return $this
	 */
	public function set_attribute( $attribute ) {
		$this->attribute = $attribute;
		$this->message   = sprintf( 'The attribute [%s] does not exist on this [%s] model.', $attribute, $this->get_model() );
		return $this;
	}

	/**
	 * Get the affected attribute.
	 *
	 * @return string
	 */
	public function get_attribute() {
		return $this->attribute;
	}

	/**
	 * Set the affected model.
	 *
	 * @param string $model
	 * @return $this
	 */
	public function set_model( $model ) {
		$this->model = \class_basename( $model );
		return $this;
	}

	/**
	 * Get the affected model.
	 *
	 * @return string
	 */
	public function get_model() {
		return $this->model;
	}
}
