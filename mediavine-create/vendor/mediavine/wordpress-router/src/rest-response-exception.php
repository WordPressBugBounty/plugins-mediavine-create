<?php
namespace Mediavine\WordPress\Router\Exceptions;

use RuntimeException;

class RestResponseException extends RuntimeException {
	private $error_code;

	function setErrorCode( $error_code = '' ) {
		$this->error_code = $error_code;
	}

	function getErrorCode() {
		return $this->error_code;
	}
}
