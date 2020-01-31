<?php

Kirby::plugin('schnti/legal', [
	'options' => [
		'username'   => false,
		'password'   => false,
		'data'       => [],
		'version'    => 'newest',
		'cache.data' => true
	],
	'tags'    => [
		'verantwortlicheStelle'      => [
			'html' => function ($tag) {
				$page = $tag->parent();
				return $page->verantwortlicheStelle()->kirbytext();
			}
		], 'datenschutzbeauftragter' => [
			'html' => function ($tag) {
				$page = $tag->parent();
				return $page->datenschutzbeauftragter()->kirbytext();
			}
		], 'legal'                   => [
			'attr' => [
				'class'
			],
			'html' => function ($tag) {

				$resource = $tag->value;

				$apiCache = kirby()->cache('schnti.legal.data');
				$apiData = $apiCache->get($resource);

				if ($apiData === null) {

					$response = Kirby\Http\Remote::post('https://legal-api.kleiner-als.de/rest/content/' . $resource, [
						'headers' => [
							'Authorization: Basic ' . base64_encode(option('schnti.legal.username') . ':' . option('schnti.legal.password'))
						],
						'data'    => option('schnti.legal.data'),
						'version' => option('schnti.legal.version'),
					]);

					$apiData = $response->content();

					$apiCache->set($resource, $apiData);

				}

				return kirbytext($apiData);
			}
		]
	]
]);