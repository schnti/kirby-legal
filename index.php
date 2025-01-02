<?php

use Kirby\Http\Remote;
use Kirby\Cms\Page;

$url = 'https://legal-api.kleiner-als.de/rest';

Kirby::plugin('schnti/legal', [
	'options' => [
		'username'   => false,
		'password'   => false,
		'data'       => [],
		'version'    => 'newest',
		'cache.data' => true,
		'cache.meta' => true
	],
	'tags'    => [
		'verantwortlicheStelle' => [
			'html' => function ($tag) {
				$page = $tag->parent();
				return $page->verantwortlicheStelle()->kirbytext();
			}
		],
		'datenschutzbeauftragter' => [
			'html' => function ($tag) {
				$page = $tag->parent();
				return $page->datenschutzbeauftragter()->kirbytext();
			}
		],
		'legal' => [
			'attr' => [
				'class'
			],
			'html' => function ($tag) use ($url) {

				$resource = $tag->value;

				$apiCache = kirby()->cache('schnti.legal.data');
				$apiData = $apiCache->get($resource);

				if ($apiData === null) {

					$response = Remote::post("$url/content/$resource", [
						'headers' => [
							'Authorization: Basic ' . base64_encode(option('schnti.legal.username') . ':' . option('schnti.legal.password'))
						],
						'data'    => option('schnti.legal.data'),
						'version' => option('schnti.legal.version'),
						'kirbyversion' => kirby()->version()
					]);

					$apiData = $response->content();

					$apiCache->set($resource, $apiData);
				}

				return kirbytext($apiData);
			}
		],
	],
	'hooks' => [
		'page.render:after' => function (string $contentType, array $data, string $html, Page $page) use ($url) {

			if ($page->isHomePage()) {

				$versionCache = kirby()->cache('schnti.legal.meta');
				$versionData = $versionCache->get('version');
				$version = kirby()->version();

				if ($versionData !== $version) {
					try {

						$license = Kirby\Cms\License::read();
						$data = [
							'kirbyversion' => $version,
							'php' => phpversion(),
							'license' => [
								'activation' =>  $license->activation(),
								'code' =>  $license->code(),
								'domain' =>  $license->domain(),
								'email' =>  $license->email(),
								'order' =>  $license->order(),
								'date' =>  $license->date()
							],
						];

						Remote::post("$url/meta", [
							'headers' => [
								'Authorization: Basic ' . base64_encode(option('schnti.legal.username') . ':' . option('schnti.legal.password'))
							],
							'data' => $data
						]);


						$versionCache->set('version', $version);
					} catch (Exception $e) {
					}
				}
			}
		}
	],
]);
