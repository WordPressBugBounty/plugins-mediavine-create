<?php

namespace Mediavine;

class API_Services {
	/**
	 * This loads the instance of API_Services on the Create namespace.
	 *
	 * Switching the namespace to Create caused Importers to fatal because it directly uses some
	 * of our classes. Currently, our only layer of protection is performing a check if the Create
	 * plugin is active, which is why this crashed.
	 *
	 * @deprecated Deprecated since version 1.7.0
	 *
	 * @return \Mediavine\Create\API_Services
	 */
	public static function get_instance() {
		return \Mediavine\Create\API_Services::get_instance();
	}
}
