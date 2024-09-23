<?php


namespace Mediavine\Create\Settings;

use Mediavine\Create\Plugin;

class Affiliates implements Settings_Group {

	/**
	 * @inheritDoc
	 */
	public static function settings() {
		return [
			[
				'slug'  => Plugin::$settings_group . '_enable_amazon',
				'value' => false,
				'group' => Plugin::$settings_group . '_affiliates',
				'order' => 10,
				'data'  => [
					'type'         => 'checkbox',
					'label'        => __( 'Enable Amazon Affiliates', 'mediavine' ),
					'instructions' => __(
						'When enabled, recommended products in Create cards have the ability to pull data from Amazon, using the Product Advertising API Version 5.0 (PA API 5).

						You will need to register with Amazon as an affiliate and use your own Product Advertising/Amazon Affiliates Access Key, Secret, and Store ID.

						Images will be pulled directly from Amazon and refreshed every 24 hours per their terms and conditions.

						Checking this box also acknowledges that a valid SSL certificate is required to use this feature.',
						'mediavine'
					),
					'default'      => 'Disabled',
				],
			],
			[
				'slug'  => Plugin::$settings_group . '_paapi_access_key',
				'value' => '',
				'group' => Plugin::$settings_group . '_affiliates',
				'order' => 15,
				'data'  => [
					'type'         => 'text',
					'label'        => __( 'Amazon Affiliates Access Key', 'mediavine' ),
					'instructions' => __( 'The Amazon Affiliates Access Key', 'mediavine' ),
					'default'      => '',
					'dependent_on' => Plugin::$settings_group . '_enable_amazon',
				],
			],
			[
				'slug'  => Plugin::$settings_group . '_paapi_secret_key',
				'value' => '',
				'group' => Plugin::$settings_group . '_affiliates',
				'order' => 20,
				'data'  => [
					'type'         => 'text',
					'label'        => __( 'Amazon Affiliates Secret Access Key', 'mediavine' ),
					'instructions' => __( 'The Amazon Affiliates Secret Access Key. Please note that Amazon generally takes 48 hours to provision the secret access key for use.' ),
					'default'      => '',
					'dependent_on' => Plugin::$settings_group . '_enable_amazon',
				],
			],
			[
				'slug'  => Plugin::$settings_group . '_paapi_tag',
				'value' => '',
				'group' => Plugin::$settings_group . '_affiliates',
				'order' => 25,
				'data'  => [
					'type'         => 'text',
					'label'        => __( 'Amazon Associate Tag', 'mediavine' ),
					'instructions' => __( 'The Amazon Associate Tag/Store ID for the US Marketplace (other countries will be supported in the future)', 'mediavine' ),
					'default'      => '',
					'dependent_on' => Plugin::$settings_group . '_enable_amazon',
				],
			],
		];
	}
}
