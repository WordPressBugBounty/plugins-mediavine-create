<?php
namespace Mediavine\Create\Exceptions;

class ModelNotFoundException extends \RuntimeException {
	/**
	 * Name of the affected Eloquent model.
	 *
	 * @var string
	 */
	protected $model;

	protected $code = 404;

	/**
	 * Set the affected Eloquent model.
	 *
	 * @param  string   $model
	 * @return $this
	 */
	public function set_model( $model ) {
		$this->model   = $model;
		$this->message = "No query results for model [{$model}].";
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
