<?php


namespace Mediavine\Create\Settings;

use Mediavine\Create\Plugin;

class Affiliates implements Settings_Group {

	/**
	 * @inheritDoc
	 */
	public static function settings() {
		$settings = [
			[
				'slug'  => Plugin::$settings_group . '_enable_amazon',
				'value' => false,
				'group' => Plugin::$settings_group . '_affiliates',
				'order' => 10,
				'data'  => [
					'type'         => 'checkbox',
					'label'        => __( 'Enable Amazon Affiliates', 'mediavine' ),
					'instructions' => __(
						'When enabled, recommended products in Create cards have the ability to pull data from Amazon.

						You will need to register with Amazon as an affiliate and use your credentials from Associates Central.

						Images will be pulled directly from Amazon and refreshed every 24 hours per their terms and conditions.

						Checking this box also acknowledges that a valid SSL certificate is required to use this feature.',
						'mediavine'
					),
					'default'      => 'Disabled',
				],
			],
			[
				'slug'  => Plugin::$settings_group . '_paapi_marketplace',
				'value' => 'US',
				'group' => Plugin::$settings_group . '_affiliates',
				'order' => 12,
				'data'  => [
					'type'         => 'select',
					'label'        => __( 'Amazon Marketplace / Creators API Region', 'mediavine' ),
					'instructions' => __( 'Select the Amazon marketplace your affiliate account is registered with. This also determines the Creators API region used for requests.', 'mediavine' ),
					'default'      => 'US',
					'dependent_on' => Plugin::$settings_group . '_enable_amazon',
					'options'      => [
						[ 'label' => 'United States (amazon.com)',    'value' => 'US' ],
						[ 'label' => 'United Kingdom (amazon.co.uk)', 'value' => 'UK' ],
						[ 'label' => 'Canada (amazon.ca)',            'value' => 'CA' ],
						[ 'label' => 'Germany (amazon.de)',           'value' => 'DE' ],
						[ 'label' => 'France (amazon.fr)',            'value' => 'FR' ],
						[ 'label' => 'Japan (amazon.co.jp)',          'value' => 'JP' ],
						[ 'label' => 'Australia (amazon.com.au)',     'value' => 'AU' ],
						[ 'label' => 'India (amazon.in)',             'value' => 'IN' ],
						[ 'label' => 'Italy (amazon.it)',             'value' => 'IT' ],
						[ 'label' => 'Spain (amazon.es)',             'value' => 'ES' ],
					],
				],
			],
			[
				'slug'  => Plugin::$settings_group . '_paapi_tag',
				'value' => '',
				'group' => Plugin::$settings_group . '_affiliates',
				'order' => 15,
				'data'  => [
					'type'         => 'text',
					'label'        => __( 'Amazon Associate Tag', 'mediavine' ),
					'instructions' => __( 'The Amazon Associate Tag/Store ID for the selected marketplace. Required for both the Creators API and the legacy PA-API.', 'mediavine' ),
					'default'      => '',
					'dependent_on' => Plugin::$settings_group . '_enable_amazon',
				],
			],
		];

		// Creators API settings
		$settings[] = [
			'slug'  => Plugin::$settings_group . '_creators_credential_id',
			'value' => '',
			'group' => Plugin::$settings_group . '_affiliates',
			'order' => 20,
			'data'  => [
				'type'         => 'text',
				'label'        => __( 'Creators API Credential ID', 'mediavine' ),
				'instructions' => sprintf(
					/* translators: %s: link to Amazon Associates Central Creators API tools */
					__( 'Your Credential ID from Amazon Associates Central. Find this under %s.', 'mediavine' ),
					'<a href="https://affiliate-program.amazon.com/creatorsapi" target="_blank" rel="noopener noreferrer">' . __( 'Tools &rsaquo; Creators API', 'mediavine' ) . '</a>'
				),
				'default'      => '',
				'dependent_on' => Plugin::$settings_group . '_enable_amazon',
			],
		];
		$settings[] = [
			'slug'  => Plugin::$settings_group . '_creators_credential_secret',
			'value' => '',
			'group' => Plugin::$settings_group . '_affiliates',
			'order' => 25,
			'data'  => [
				'type'         => 'secret',
				'label'        => __( 'Creators API Credential Secret', 'mediavine' ),
				'instructions' => __( 'Your Credential Secret from Amazon Associates Central. Please note that Amazon may take time to provision new credentials.', 'mediavine' ),
				'default'      => '',
				'dependent_on' => Plugin::$settings_group . '_enable_amazon',
			],
		];
		// Legacy PA-API settings
		$settings[] = [
			'slug'  => Plugin::$settings_group . '_paapi_access_key',
			'value' => '',
			'group' => Plugin::$settings_group . '_affiliates',
			'order' => 35,
			'data'  => [
				'type'         => 'text',
				'label'        => __( 'PA-API Access Key', 'mediavine' ),
				'instructions' => __( 'The PA-API 5.0 Access Key. Please migrate to the Creators API credentials above.', 'mediavine' ),
				'default'      => '',
				'dependent_on' => Plugin::$settings_group . '_enable_amazon',
				'deprecated'   => __( 'April 30, 2026', 'mediavine' ),
			],
		];
		$settings[] = [
			'slug'  => Plugin::$settings_group . '_paapi_secret_key',
			'value' => '',
			'group' => Plugin::$settings_group . '_affiliates',
			'order' => 40,
			'data'  => [
				'type'         => 'secret',
				'label'        => __( 'PA-API Secret Key', 'mediavine' ),
				'instructions' => __( 'The PA-API 5.0 Secret Key. Amazon generally takes 48 hours to provision this key. Please migrate to the Creators API credentials above.', 'mediavine' ),
				'default'      => '',
				'dependent_on' => Plugin::$settings_group . '_enable_amazon',
				'deprecated'   => __( 'April 30, 2026', 'mediavine' ),
			],
		];

		return $settings;
	}
}
