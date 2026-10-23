<?php

use Kirby\Cms\Page;
use Kirby\Exception\InvalidArgumentException;
use Kirby\Exception\PermissionException;
use Kirby\Http\Remote;
use Kirby\Toolkit\Str;

$url = option('schnti.legal.url', 'https://legal-api.kleiner-als.de/rest');

/**
 * Zugangsdaten für die Legal-API. Remote setzt daraus CURLOPT_USERPWD,
 * der Header muss nicht selbst gebaut werden.
 */
function legalAuth(): string
{
	return option('schnti.legal.username') . ':' . option('schnti.legal.password');
}

/**
 * Welche Klauseln gelten für diese Seite?
 *
 * Vorrang hat das Panel-Feld `klauseln`. Ist es leer, greift wie bisher
 * die Konfiguration `schnti.legal.data` — bestehende Installationen
 * laufen damit unverändert weiter.
 */
function legalParts(?Page $page = null): array
{
	if ($page !== null && ($parts = legalAuswahl($page)) !== []) {
		return $parts;
	}

	return (array)option('schnti.legal.data');
}

/**
 * Die im Panel gewählten Klauseln dieser Seite.
 *
 * Die Auswahl liegt auf mehrere Felder verteilt — eines je Kapitel, damit im
 * Panel Zwischenüberschriften möglich sind. Sie heißen `klauseln_<kapitel>`;
 * ein Feld namens `klauseln` gibt es nie. Wer darauf prüft, findet auf einer
 * migrierten Seite nichts und hält sie für unmigriert.
 *
 * Leeres Ergebnis heißt: keine eigene Auswahl, es gilt die Konfiguration.
 */
function legalAuswahl(Page $page): array
{
	$parts = [];

	foreach ($page->content()->toArray() as $key => $value) {
		if (str_starts_with($key, 'klauseln') === true && $value !== '') {
			$parts = [...$parts, ...Str::split((string)$value)];
		}
	}

	return $parts;
}

/**
 * Welche Anbieter-Klauseln aus dem Kapitel „Hosting" wären nach diesem
 * Speichern ausgewählt?
 *
 * Zusammengesetzt aus den neuen Werten und den Feldern, die das Formular nicht
 * mitschickt — sonst würde ein Teilformular eine bestehende Auswahl übersehen.
 * `auftragsverarbeitung` zählt nicht mit: die Klausel nennt keinen Anbieter,
 * sondern gehört zu einem.
 */
function legalHostingAuswahl(Page $page, array $values): array
{
	$keys = array_unique([
		...array_keys($page->content()->toArray()),
		...array_keys($values)
	]);

	$anbieter = [];

	foreach ($keys as $key) {
		if (str_starts_with($key, 'klauseln') === false) {
			continue;
		}

		$wert = array_key_exists($key, $values)
			? $values[$key]
			: $page->content()->get($key)->value();

		if (is_array($wert) === true) {
			$wert = implode(', ', $wert);
		}

		foreach (Str::split((string)$wert) as $uri) {
			if (str_starts_with($uri, 'datenschutz/hosting/') === true
				&& $uri !== 'datenschutz/hosting/auftragsverarbeitung') {
				$anbieter[] = $uri;
			}
		}
	}

	return array_values(array_unique($anbieter));
}

/**
 * Ein Feld der Seite als Kirbytext — oder ein Leerstring.
 *
 * Fehlt die Seite oder ist das Feld leer, wird nichts ausgegeben. Wichtig ist
 * das Nichts: der Aufrufer hängt das Ergebnis in eine Seite, und ein `null`
 * aus einem leeren Feld reicht bis in fremde kirbytags-Hooks durch.
 */
function legalFeldText(object $tag, string $feld): string
{
	$seite = $tag->parent();

	if ($seite === null) {
		return '';
	}

	$wert = $seite->content()->get($feld);

	return $wert->isNotEmpty() === true ? $wert->kirbytext() : '';
}

/**
 * Drossel für die öffentlichen Refresh-Routen.
 *
 * Sie brauchen kein Auth, damit die Update-Links aus der Legal-API heraus
 * funktionieren. Ohne Begrenzung ließe sich damit aber beliebig oft ein
 * Request der Kundenseite gegen die API auslösen. Eine Minute Sperre nimmt
 * dem den Reiz und ist im normalen Gebrauch nicht spürbar.
 */
function legalThrottled(string $name): bool
{
	$cache = kirby()->cache('schnti.legal.meta');
	$key   = 'throttle.' . $name;

	if ($cache->get($key) !== null) {
		return true;
	}

	$cache->set($key, true, 1); // Minuten

	return false;
}

/**
 * Feldname für ein Kapitel. Muss stabil und als Content-Key gültig sein.
 */
function legalGroupKey(string $uri): string
{
	return 'klauseln_' . str_replace(['/', '-'], '_', $uri);
}

/**
 * Auswählbare Klauseln für das Panel-Feld.
 *
 * Auswählbar ist, was in der API eine Klausel ist: eine Seite mit
 * Versions-Kindern. Versionen selbst (v1, v2 …) sind Blätter und
 * tauchen deshalb nicht auf.
 */
function legalFlattenStructure(array $tree): array
{
	$groups = [];

	legalWalkTree($tree, [], null, $groups);

	return $groups;
}

/**
 * Rekursion für legalFlattenStructure(). Als benannte Funktion statt als
 * sich selbst referenzierende Closure — das ist testbar und liest sich besser.
 */
function legalWalkTree(array $nodes, array $trail, ?string $parentUri, array &$groups): void
{
	foreach ($nodes as $node) {
		$children = $node['children'] ?? [];

		// Klausel = hat Kinder, aber die Kinder sind Blätter (Versionen)
		$isClause = $children !== [] && array_filter(
			$children,
			fn($child) => ($child['children'] ?? []) !== []
		) === [];

		if ($isClause === true) {
			// Gruppe ist das übergeordnete Kapitel — beim Disclaimer das Thema
			$key = legalGroupKey($parentUri ?? 'sonstige');

			$groups[$key] ??= [
				'title'   => implode(' › ', $trail),
				'options' => []
			];

			$groups[$key]['options'][] = [
				'value' => $node['uri'],
				'text'  => $node['title']  // kurz — das Kapitel steht in der Überschrift
			];
			continue;
		}

		legalWalkTree($children, [...$trail, $node['title']], $node['uri'], $groups);
	}
}

function legalStructure(string $url): array
{
	$cache = kirby()->cache('schnti.legal.structure');

	if ($options = $cache->get('options')) {
		return $options;
	}

	try {
		$response = Remote::get("$url/structure", ['basicAuth' => legalAuth()]);
		$tree     = $response->json()['data'] ?? [];
	} catch (Exception $e) {
		return [];
	}

	$options = legalFlattenStructure($tree);

	$cache->set('options', $options, 60 * 24); // 1 Tag

	return $options;
}

function getSectionData(string $url, string $section, ?Page $page = null, bool $force = false)
{
	$parts = legalParts($page);

	// Die Auswahl steckt im Cache-Key: Ändert sie sich, greift der alte
	// Eintrag nicht mehr und es wird automatisch neu geholt.
	$key = $section . '.' . substr(md5(json_encode($parts)), 0, 8);

	$apiCache = kirby()->cache('schnti.legal.data');
	$cached   = $apiCache->get($key);

	// Alte Einträge lagen als reiner String vor
	if (is_array($cached) === false) {
		$cached = null;
	}

	// Der Eintrag selbst läuft nicht ab, der Zeitstempel steuert die
	// Auffrischung. So bleibt bei einem API-Ausfall der letzte bekannte
	// Text erhalten, statt dass die Seite leer ausgeliefert wird.
	$alter = time() - ($cached['time'] ?? 0);
	$fällig = $cached === null || $alter > 60 * 60 * 24 * 30; // 30 Tage

	if ($force === true || $fällig === true) {
		try {
			$response = Remote::post("$url/content/$section", [
				'basicAuth' => legalAuth(),
				'data'      => $parts,
			]);

			// Nur erfolgreiche Antworten speichern — sonst überschriebe
			// eine Fehlerseite den gültigen Text.
			if ($response->code() === 200) {
				$cached = ['text' => $response->content(), 'time' => time()];
				$apiCache->set($key, $cached);
			}
		} catch (Throwable $e) {
			// API nicht erreichbar: mit dem bisherigen Stand weiterarbeiten.
			// Das Rendering der Kundenseite darf daran nicht scheitern.
		}
	}

	// `parent` muss mit: die Klauseltexte enthalten Tags, die Felder DIESER
	// Seite auflösen. Ohne die Angabe nimmt Kirby die gerade gerenderte Seite —
	// beim Seitenaufruf zufällig die richtige, in einer API-Route wie
	// legal-refresh aber keine, und der Tag liefert einen leeren Block.
	$daten = $page !== null ? ['parent' => $page] : [];

	return kirbytext($cached['text'] ?? '', $daten);
}

/**
 * Meldet Kirby-Version, Systemstatus, Lizenz und Plugin-Stand an die Legal-API.
 *
 * Gibt zurück, was passiert ist. Der Rückgabewert ist die einzige Spur nach
 * außen: der Hook läuft im Seitenaufbau, wo nichts sichtbar werden darf, und
 * ohne ihn meldet die Route meta-refresh auch dann Erfolg, wenn nichts ankommt.
 */
function sendMetaData($url, $force = false): string
{
	$versionCache = kirby()->cache('schnti.legal.meta');
	$versionData = $versionCache->get('version');
	$version = kirby()->version();

	// Auch die PHP-Version gehört in den Schlüssel: ein Image-Neubau bringt
	// einen PHP-Sicherheitsfix, ohne dass sich an Kirby etwas ändert — und die
	// Legal-API zeigt sonst bis zu einer Woche lang den alten Stand als offen.
	$stand = $version . '|' . PHP_VERSION;

	if ($force === false && $versionData === $stand) {
		return 'Meta unverändert, nichts gesendet';
	}

	try {
		$system = kirby()->system();

		// accounts, content, curl, sessions, mbstring, media, php
		$data['status'] = $system->status();

		// Version, Server, PHP, License, Languages
		// Since 4.3.0
		if (method_exists($system, 'info')) {
			$data['info'] = $system->info();
		} else {
			$data['info']['kirby'] = $version;
			$data['info']['php'] = phpversion();
		}

		// License
		if (class_exists('Kirby\Cms\License')) {
			$license = Kirby\Cms\License::read();
			$data['license'] = [
				'activation' =>  $license->activation(),
				'code' =>  $license->code(),
				'domain' =>  $license->domain(),
				'email' =>  $license->email(),
				'order' =>  $license->order(),
				'date' =>  $license->date()
			];
		}

		// Plugins
		$plugins = $system->plugins();
		foreach ($plugins as $plugin) {
			$data['plugins'][] = $plugin->updateStatus()->toArray();
		}

		$response = Remote::post("$url/meta", [
			'basicAuth' => legalAuth(),
			'data'      => $data
		]);

		if ($response->code() !== 200) {
			return 'Meta abgelehnt, HTTP ' . $response->code();
		}

		$versionCache->set('version', $stand, 60 * 24 * 7); // 60 Minuten * 24 Stunden * 7 Tage

		return 'Meta gesendet';
	} catch (Throwable $e) {
		return 'Meta fehlgeschlagen: ' . $e->getMessage();
	}
}

Kirby::plugin('schnti/legal', [
	'options' => [
		'username'        => false,
		'password'        => false,
		'data'            => [],
		'cache.data'      => true,
		'cache.meta'      => true,
		'cache.structure' => true
	],
	'blueprints' => [
		// Rollenabhängig: Kirby kennt keine Rollen-Bedingung für Felder,
		// Plugin-Blueprints dürfen aber Closures sein.
		'sections/legal-klauseln' => function () use ($url) {
			if (kirby()->user()?->isAdmin() !== true) {
				return [
					'type'   => 'fields',
					'fields' => [
						'hl_klauseln_hinweis' => [
							'type'  => 'info',
							'label' => 'Rechtstexte',
							'text'  => 'Die Auswahl der Rechtstexte pflegt kleiner als. Bei Änderungswünschen bitte melden.'
						]
					]
				];
			}

			$groups = legalStructure($url);

			// Ohne Struktur werden die Gruppen bewusst NICHT gerendert: Kirby
			// verwirft beim Speichern jeden Wert, der nicht in den Optionen
			// steht — leere Gruppen würden die Auswahl also stillschweigend
			// löschen. Was nicht im Formular steht, wird auch nicht überschrieben.
			if ($groups === []) {
				return [
					'type'   => 'fields',
					'fields' => [
						'hl_klauseln_fehler' => [
							'type'  => 'info',
							'label' => 'Klauseln',
							'theme' => 'negative',
							'text'  => 'Die Legal-API ist gerade nicht erreichbar, die Klauselauswahl kann deshalb nicht angezeigt werden. Die gespeicherte Auswahl bleibt unverändert und wird weiterhin ausgeliefert. Bitte später erneut öffnen.'
						]
					]
				];
			}

			$fields = [];

			foreach ($groups as $key => $group) {
				// Überschrift bewusst NICHT mit "klauseln" beginnen lassen,
				// sonst würde legalParts() sie als Auswahlfeld mitlesen.
				$fields['hl_' . $key] = [
					'type'  => 'headline',
					'label' => $group['title']
				];

				$fields[$key] = [
					'type'    => 'checkboxes',
					'label'   => false,
					'columns' => 2,
					'options' => $group['options']
				];
			}

			return ['type' => 'fields', 'fields' => $fields];
		}
	],
	'tags'    => [
		// Beide Tags geben nur aus, wenn das Feld gefüllt ist. Ein leeres
		// Kirby-Feld hat den Wert null, und null durch kirbytext() zu schicken
		// bringt jeden kirbytags-Hook zu Fall, der einen String erwartet — auf
		// einer Kundenseite, deren Hooks dieses Plugin nicht kennt.
		'verantwortlicheStelle' => [
			'html' => fn($tag) => legalFeldText($tag, 'verantwortlicheStelle')
		],
		'datenschutzbeauftragter' => [
			'html' => fn($tag) => legalFeldText($tag, 'datenschutzbeauftragter')
		],
		'legal' => [
			'attr' => [
				'class'
			],
			'html' => function ($tag) use ($url) {
				return getSectionData($url, $tag->value, $tag->parent());
			}
		],
	],
	'hooks' => [
		'page.render:after' => function (string $contentType, array $data, string $html, Page $page) use ($url) {

			if (!option('debug') && $page->isHomePage()) {
				sendMetaData($url);
			}
		},

		'page.update:before' => function (Page $page, array $values) {

			// Gültigkeitsprüfung — gilt für ALLE, auch für Admins: Die
			// Hosting-Klauseln nennen je einen Anbieter. Zwei davon gleichzeitig
			// ergeben eine Erklärung mit zwei Hostern, und das faellt beim
			// Speichern niemandem auf. Checkboxen koennen das nicht verhindern,
			// also muss es hier stehen.
			$anbieter = legalHostingAuswahl($page, $values);

			if (count($anbieter) > 1) {
				throw new InvalidArgumentException(
					'Es darf nur eine Hosting-Klausel ausgewählt sein, gewählt sind: '
					. implode(', ', $anbieter)
				);
			}

			// Berechtigungsprüfung — das ausgeblendete Feld ist nur Oberfläche,
			// sonst umgeht ein direkter Panel-API-Aufruf die Sperre.
			if (kirby()->user()?->isAdmin() === true) {
				return;
			}

			foreach ($values as $key => $new) {
				if (str_starts_with($key, 'klauseln') === false) {
					continue;
				}

				if (is_array($new) === true) {
					$new = implode(', ', $new);
				}

				if ((string)$new !== (string)$page->$key()->value()) {
					throw new PermissionException('Die Klauselauswahl darf nur die Agentur ändern.');
				}
			}
		}
	],
	'api' => [
		'routes' => [
			[
				'pattern' => 'legal-refresh',
				// Bewusst ohne Auth, damit die Update-Links im Dashboard der
				// Legal-API ohne Anmeldung auf jeder Kundenseite funktionieren.
				// Ausgelesen werden kann nichts; die Drossel unten verhindert,
				// dass sich der Aufruf als Lastverstärker missbrauchen lässt.
				'auth' => false,
				'action'  => function () use ($url) {
					if (legalThrottled('legal') === true) {
						return 'Legal Refresh übersprungen — vor weniger als einer Minute ausgeführt';
					}

					kirby()->cache('schnti.legal.structure')->flush();

					// Seiten mit eigener Auswahl einzeln auffrischen. Der
					// Cache-Schlüssel enthält die Auswahl — wer hier mit der
					// Konfiguration auffrischt, wärmt einen Eintrag, den die
					// migrierte Seite nie liest, und der Text bleibt stehen.
					$pages = site()->index()->filter(
						fn($p) => legalAuswahl($p) !== []
					);

					foreach ($pages as $page) {
						getSectionData($url, 'datenschutz', $page, true);
						getSectionData($url, 'disclaimer', $page, true);
					}

					// … sonst wie bisher über die Konfiguration
					if ($pages->count() === 0) {
						getSectionData($url, 'datenschutz', null, true);
						getSectionData($url, 'disclaimer', null, true);
					}

					return 'Legal Refresh Done';
				}
			],
			[
				// Einmalig beim Rollout: überträgt die Klauselliste aus
				// config.php -> schnti.legal.data in die Panel-Felder.
				//
				// Ohne Auth, aber ungefährlich: Die Route schreibt nur, was
				// ohnehin schon in der Config steht, überspringt Seiten mit
				// bestehender Auswahl und ist danach dauerhaft ein No-op.
				'pattern' => 'legal-migrate',
				'auth' => false,
				'action'  => function () use ($url) {
					$config = (array)option('schnti.legal.data');

					if ($config === []) {
						return 'Nichts zu migrieren — schnti.legal.data ist leer.';
					}

					$groups = legalStructure($url);

					if ($groups === []) {
						return 'Abbruch — die Legal-API ist nicht erreichbar.';
					}

					kirby()->impersonate('kirby');

					$bericht = [];

					foreach (site()->index() as $page) {
						// Seiten, die überhaupt Klauseln ausgeben
						$nutzt = false;

						foreach ($page->content()->toArray() as $key => $wert) {
							if (is_string($wert) === true && str_contains($wert, '(legal:') === true) {
								$nutzt = true;
							}

							if (str_starts_with($key, 'klauseln') === true && $wert !== '') {
								$bericht[] = $page->id() . ': bereits migriert, übersprungen';
								continue 2;
							}
						}

						if ($nutzt === false) {
							continue;
						}

						$felder = [];
						$summe  = 0;

						foreach ($groups as $key => $group) {
							$treffer = array_values(array_intersect(
								array_column($group['options'], 'value'),
								$config
							));

							$felder[$key] = implode(', ', $treffer);
							$summe += count($treffer);
						}

						$page->update($felder);

						$bericht[] = $page->id() . ': ' . $summe . ' von ' . count($config) . ' Klauseln übernommen';
					}

					return $bericht === []
						? 'Keine Seite mit (legal: …) gefunden.'
						: implode("\n", $bericht);
				}
			],
			[
				'pattern' => 'meta-refresh',
				'auth' => false,
				'action'  => function () use ($url) {
					if (legalThrottled('meta') === true) {
						return 'Meta Refresh übersprungen — vor weniger als einer Minute ausgeführt';
					}

					return sendMetaData($url, true); // force = true
				}
			]
		]
	]
]);
