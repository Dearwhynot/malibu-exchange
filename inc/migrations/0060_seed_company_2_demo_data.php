<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return [
	'key'      => '0060_seed_company_2_demo_data',
	'title'    => 'Retire historical company 2 demo seed migration',
	'callback' => function () {
		return [
			'summary'  => 'Historical company 2 demo seed retired.',
			'messages' => [
				'This migration no longer inserts demo merchants, orders, callbacks, or payouts.',
				'Existing historical mock data must be removed by dedicated cleanup migrations.',
			],
		];
	},
];
