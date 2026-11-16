<?php
/*
  Open Media Collectors Database
  Copyright (C) 2001,2013 by Jason Pell

  This program is free software; you can redistribute it and/or
  modify it under the terms of the GNU General Public License
  as published by the Free Software Foundation; either version 2
  of the License, or (at your option) any later version.

  This program is distributed in the hope that it will be useful,
  but WITHOUT ANY WARRANTY; without even the implied warranty of
  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
  GNU General Public License for more details.

  You should have received a copy of the GNU General Public License
  along with this program; if not, write to the Free Software
  Foundation, Inc., 59 Temple Place - Suite 330, Boston, MA  02111-1307, USA.

  Parsing helpers for the amazon site plugin. They work on the HTML Amazon
  serves today and do not depend on the shop language.
*/

/**
 * Amazon search index (the i= parameter) for an OpenDb index type.
 */
function amazon_search_index($index_type) {
	switch ($index_type) {
		case 'dvd':
		case 'bd':
		case 'vhs':
			return 'dvd';
		case 'books':
			return 'stripbooks';
		case 'music':
			return 'popular';
		case 'videogames':
			return 'videogames';
		default:
			return NULL;
	}
}

/**
 * The EAN / UPC / ISBN in a search term, or FALSE.
 */
function amazon_barcode($term) {
	$code = preg_replace('/[\s-]/', '', (string)$term);
	if (preg_match('/^[0-9]{12,14}$/', $code) || preg_match('/^[0-9]{9}[0-9Xx]$/', $code)) {
		return strtoupper($code);
	}
	return FALSE;
}

function amazon_text($s) {
	$s = html_entity_decode((string)$s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
	$s = str_replace(array("\u{200E}", "\u{200F}", "\u{00A0}"), array('', '', ' '), $s);
	return trim(preg_replace('/\s+/u', ' ', $s));
}

/**
 * A title that combines two products ("Smallville Gesamtbox & O.C. California"),
 * the way Amazon names bundles.
 */
function amazon_is_bundle_title($title) {
	return preg_match('/\s(&|&amp;|\+)\s/u', (string)$title) === 1;
}

/**
 * Removes edition / format suffixes Amazon puts into titles.
 */
function amazon_clean_title($title) {
	$title = amazon_text($title);
	$title = preg_replace('/\s*\[[^\]]*\]/u', '', $title);      // [Blu-ray], [DVD]
	$title = preg_replace('/\s*\((?:[^()]*(?:edition|collection|steelbook|blu-?ray|dvd|uhd|4k|exklusiv|limited|limitiert|special)[^()]*)\)/iu', '', $title);
	return trim($title, " \t-–:");
}

/**
 * Result tiles of a search page: list of array(asin, title, image, format).
 */
function amazon_parse_search_results($page) {
	$results = array();
	$tiles = preg_split('/(?=<div[^>]+role="listitem"[^>]+data-asin=")/', $page);
	array_shift($tiles);
	$seen = array();
	foreach ($tiles as $tile) {
		if (!preg_match('/data-asin="([A-Z0-9]{10})"/', $tile, $m) || isset($seen[$m[1]])) {
			continue;
		}
		$asin = $m[1];
		// sponsored tiles advertise something else than what was searched for
		if (preg_match('/AdHolder|sp-sponsored-result|puis-sponsored-label/i', substr($tile, 0, 3000))) {
			continue;
		}
		$title = NULL;
		if (preg_match('/<h2[^>]*aria-label="([^"]+)"/', $tile, $t)) {
			$title = $t[1];
		} else if (preg_match('/<h2[^>]*>(.*?)<\/h2>/s', $tile, $t)) {
			$title = strip_tags($t[1]);
		}
		if ($title == NULL) {
			continue;
		}
		$image = NULL;
		if (preg_match('/<img[^>]+class="s-image"[^>]+src="([^"]+)"/', $tile, $i) || preg_match('/<img[^>]+src="([^"]+)"[^>]+class="s-image"/', $tile, $i)) {
			$image = amazon_full_image_url($i[1]);
		}
		$format = '';
		if (preg_match('/<a[^>]*class="a-size-base a-link-normal[^"]*"[^>]*>\s*([^<]{2,30}?)\s*<\/a>/', $tile, $f)) {
			$format = amazon_text($f[1]);
		}
		$seen[$asin] = TRUE;
		$results[] = array('asin' => $asin, 'title' => amazon_text($title), 'image' => $image, 'format' => $format);
	}
	return $results;
}

function amazon_parse_result_count($results) {
	return count($results);
}

/**
 * Drops the size modifiers (._SX342_., ._AC_UY218_.) so the full image is loaded.
 */
function amazon_full_image_url($url) {
	return preg_replace('/\._[^\/]*?_\.(jpg|jpeg|png|gif)$/i', '.$1', $url);
}

function amazon_load_dom($page) {
	$page = preg_replace(array('/<script.*?<\/script>/is', '/<style.*?<\/style>/is', '/<noscript>.*?<\/noscript>/is'), '', $page);
	$doc = new DOMDocument();
	$prev = libxml_use_internal_errors(TRUE);
	$ok = $doc->loadHTML('<?xml encoding="UTF-8">' . $page, LIBXML_NONET | LIBXML_COMPACT);
	libxml_clear_errors();
	libxml_use_internal_errors($prev);
	return $ok ? $doc : FALSE;
}

function amazon_main_image($doc) {
	foreach (array('landingImage', 'imgBlkFront', 'ebooksImgBlkFront', 'main-image') as $id) {
		$e = $doc->getElementById($id);
		if (!$e) {
			continue;
		}
		if (strlen($e->getAttribute('data-old-hires')) > 0) {
			return $e->getAttribute('data-old-hires');
		}
		$dyn = json_decode(html_entity_decode($e->getAttribute('data-a-dynamic-image')), TRUE);
		if (is_array($dyn) && count($dyn) > 0) {
			$best = NULL;
			$size = 0;
			foreach ($dyn as $url => $wh) {
				if (is_array($wh) && $wh[0] * $wh[1] > $size) {
					$size = $wh[0] * $wh[1];
					$best = $url;
				}
			}
			return amazon_full_image_url($best);
		}
		if (strlen($e->getAttribute('src')) > 0 && strpos($e->getAttribute('src'), 'data:') !== 0) {
			return amazon_full_image_url($e->getAttribute('src'));
		}
	}
	return FALSE;
}

function amazon_price($doc) {
	foreach (array('corePrice_feature_div', 'corePriceDisplay_desktop_feature_div', 'price') as $id) {
		$e = $doc->getElementById($id);
		if ($e) {
			$xp = new DOMXPath($doc);
			$n = $xp->query('.//span[contains(@class,"a-offscreen")]', $e)->item(0);
			if ($n && strlen(amazon_text($n->textContent)) > 0) {
				return amazon_text($n->textContent);
			}
		}
	}
	return FALSE;
}

/**
 * Maps a product detail label (any shop language) to one key.
 */
function amazon_detail_key($label) {
	$l = mb_strtolower(trim(amazon_text($label), " :,"), 'UTF-8');
	static $keys = array(
			'actors' => array('darsteller', 'actors', 'acteurs', 'schauspieler'),
			'director' => array('regisseur', 'regie', 'director', 'directors', 'réalisateur', 'réalisateurs'),
			'producer' => array('produzent', 'produzenten', 'producer', 'producers', 'producteurs'),
			'writer' => array('drehbuchautor', 'drehbuch', 'writer', 'writers', 'scénaristes'),
			'composer' => array('komponist', 'komponisten', 'composer', 'compositeur'),
			'studio' => array('studio'),
			'run_time' => array('laufzeit', 'spieldauer', 'run time', 'durée'),
			'release_date' => array('erscheinungstermin', 'erscheinungsdatum', 'release date', 'dvd release date', 'publication date', 'date de sortie', 'date de sortie du dvd'),
			'ratio' => array('seitenverhältnis', 'aspect ratio', 'format d\'image'),
			'language' => array('sprache', 'language', 'langue'),
			'subtitles' => array('untertitel', 'subtitles', 'sous-titres'),
			'discs' => array('anzahl disks', 'anzahl discs', 'number of discs', 'nombre de disques'),
			'rating' => array('alterseinstufung', 'altersfreigabe', 'rated', 'usk-einstufung', 'classification'),
			'region' => array('regionalcode', 'region', 'region code', 'zone'),
			'format' => array('medienformat', 'format', 'media format'),
			'publisher' => array('herausgeber', 'verlag', 'publisher', 'éditeur'),
			'label' => array('label'),
			'isbn10' => array('isbn-10'),
			'isbn13' => array('isbn-13'),
			'pages' => array('seitenzahl der print-ausgabe', 'print length', 'gebundene ausgabe', 'taschenbuch', 'broschiert', 'paperback', 'hardcover', 'relié', 'broché'),
			'asin' => array('asin'));
	foreach ($keys as $key => $labels) {
		if (in_array($l, $labels, TRUE)) {
			return $key;
		}
	}
	return NULL;
}

/**
 * Product details as key => value, keys from amazon_detail_key().
 */
function amazon_product_details($doc) {
	$details = array();
	$xp = new DOMXPath($doc);

	// <div id="detailBullets_feature_div"> <li><span><span class="a-text-bold">Label :</span> <span>Value</span>
	foreach ($xp->query('//*[@id="detailBullets_feature_div" or @id="detailBulletsWrapper_feature_div"]//li') as $li) {
		$bold = $xp->query('.//span[contains(@class,"a-text-bold")]', $li)->item(0);
		if (!$bold) {
			continue;
		}
		$label = $bold->textContent;
		$value = '';
		for ($n = $bold->nextSibling; $n != NULL; $n = $n->nextSibling) {
			$value .= ' ' . $n->textContent;
		}
		amazon_add_detail($details, $label, $value);
	}

	// <table id="productDetails_techSpec_section_1"> <tr><th>Label</th><td>Value</td>
	foreach ($xp->query('//table[starts-with(@id,"productDetails_") or contains(@class,"prodDetTable")]//tr') as $tr) {
		$th = $xp->query('./th', $tr)->item(0);
		$td = $xp->query('./td', $tr)->item(0);
		if ($th && $td) {
			amazon_add_detail($details, $th->textContent, $td->textContent);
		}
	}

	// books: binding and pages from the format line / rich product information
	foreach ($xp->query('//*[@id="rpi-attribute-book_details-ebook_pages" or @id="rpi-attribute-book_details-fiona_pages"]//*[contains(@class,"rpi-attribute-value")]') as $n) {
		$details['pages'] = amazon_text($n->textContent);
	}
	return $details;
}

function amazon_add_detail(&$details, $label, $value) {
	$key = amazon_detail_key($label);
	$value = amazon_text($value);
	if ($key == NULL || strlen($value) == 0 || isset($details[$key])) {
		return;
	}
	if ($key == 'pages') {
		$details['binding'] = trim(amazon_text($label), " :,");
	}
	$details[$key] = $value;
}

/**
 * The names in the byline ("Reeves, Keanu (Darsteller), Wachowski, Lana (Regisseur)")
 * as role key => list of names.
 */
function amazon_byline($doc) {
	$e = $doc->getElementById('bylineInfo');
	$r = array();
	if (!$e) {
		return $r;
	}
	static $roles = array(
			'actors' => array('darsteller', 'actor', 'acteur', 'schauspieler'),
			'director' => array('regisseur', 'director', 'réalisateur'),
			'producer' => array('produzent', 'producer'),
			'writer' => array('drehbuchautor', 'writer', 'scénariste'),
			'author' => array('autor', 'author', 'auteur', 'herausgeber', 'editor'),
			'artist' => array('künstler', 'interpret', 'artist', 'artiste'),
			'composer' => array('komponist', 'composer'));
	$text = amazon_text($e->textContent);
	if (preg_match_all('/([^,()]+(?:,\s*[^,()]+)?)\s*\(([^)]+)\)/u', $text, $m, PREG_SET_ORDER)) {
		foreach ($m as $hit) {
			$name = amazon_person_name(preg_replace('/^(von|by|de)\s+/iu', '', trim($hit[1], " ,")));
			foreach (preg_split('/\s*,\s*/', mb_strtolower($hit[2], 'UTF-8')) as $role) {
				foreach ($roles as $key => $words) {
					if (in_array($role, $words, TRUE)) {
						$r[$key][] = $name;
					}
				}
			}
		}
	}
	foreach ($r as $k => $v) {
		$r[$k] = array_values(array_unique($v));
	}
	return $r;
}

/**
 * "Reeves, Keanu" -> "Keanu Reeves"
 */
function amazon_person_name($name) {
	$name = trim($name);
	if (preg_match('/^([^,]+),\s*([^,]+)$/u', $name, $m)) {
		return trim($m[2]) . ' ' . trim($m[1]);
	}
	return $name;
}

/**
 * A detail value with names: "Reeves, Keanu, Moss, Carrie-Anne" (last, first pairs)
 * or "Keanu Reeves, Carrie-Anne Moss".
 */
function amazon_names($value) {
	$parts = array_values(array_filter(array_map('trim', explode(',', $value)), 'strlen'));
	$names = array();
	// pairs only when every second part looks like a first name (one word)
	$pairs = count($parts) % 2 == 0 && count($parts) > 0;
	for ($i = 1; $pairs && $i < count($parts); $i += 2) {
		if (preg_match('/\s/u', $parts[$i]) && !preg_match('/^\S+\s+\S\.?$/u', $parts[$i])) {
			$pairs = FALSE;
		}
	}
	if ($pairs) {
		for ($i = 0; $i < count($parts); $i += 2) {
			$names[] = $parts[$i + 1] . ' ' . $parts[$i];
		}
	} else {
		$names = $parts;
	}
	return array_values(array_unique($names));
}

/**
 * "9 Stunden und 11 Minuten", "136 Minuten", "2 hours and 16 minutes", "1 h 52 min" -> minutes
 */
function amazon_minutes($value) {
	$min = 0;
	if (preg_match('/(\d+)\s*(?:stunden?|std|hours?|hrs?|h|heures?)\b/iu', $value, $m)) {
		$min += 60 * (int)$m[1];
	}
	if (preg_match('/(\d+)\s*(?:minuten|minutes?|mins?|min)\b/iu', $value, $m)) {
		$min += (int)$m[1];
	}
	if ($min == 0 && preg_match('/^(\d+)$/', trim($value), $m)) {
		$min = (int)$m[1];
	}
	return $min;
}

function amazon_year($value) {
	if (preg_match('/\b(1[89]\d\d|20\d\d)\b/', (string)$value, $m)) {
		return $m[1];
	}
	return FALSE;
}

/**
 * "16:9 - 1.77:1" -> nearest standard ratio (1.33, 1.78, 1.85, 2.20, 2.35, 2.40)
 */
function amazon_ratio($value) {
	$r = NULL;
	if (preg_match('/(\d[.,]\d+)\s*:\s*1/', $value, $m)) {
		$r = (float)str_replace(',', '.', $m[1]);
	} else if (preg_match('/(\d+)\s*:\s*(\d+)/', $value, $m) && (int)$m[2] > 0) {
		$r = (int)$m[1] / (int)$m[2];
	}
	if ($r === NULL) {
		return FALSE;
	}
	$best = FALSE;
	$diff = 1;
	foreach (array('1.33', '1.78', '1.85', '2.20', '2.35', '2.40', '2.78') as $std) {
		if (abs($r - (float)$std) < $diff) {
			$diff = abs($r - (float)$std);
			$best = $std;
		}
	}
	return $diff < 0.1 ? $best : FALSE;
}

/**
 * "Deutsch (Dolby Digital 5.1), Englisch (DTS-HD)" -> array(GERMAN, ENGLISH)
 */
function amazon_languages($value) {
	static $map = array(
			'GERMAN' => array('deutsch', 'german', 'allemand'),
			'ENGLISH' => array('englisch', 'english', 'anglais'),
			'FRENCH' => array('französisch', 'french', 'français'),
			'SPANISH' => array('spanisch', 'spanish', 'espagnol'),
			'ITALIAN' => array('italienisch', 'italian', 'italien'),
			'FINNISH' => array('finnisch', 'finnish', 'finnois'),
			'DANISH' => array('dänisch', 'danish', 'danois'),
			'DUTCH' => array('niederländisch', 'holländisch', 'dutch', 'néerlandais'),
			'NORWEGIAN' => array('norwegisch', 'norwegian', 'norvégien'),
			'SWEDISH' => array('schwedisch', 'swedish', 'suédois'),
			'POLISH' => array('polnisch', 'polish', 'polonais'),
			'PORTUGUESE' => array('portugiesisch', 'portuguese', 'portugais'),
			'TURKISH' => array('türkisch', 'turkish', 'turc'),
			'CZECH' => array('tschechisch', 'czech', 'tchèque'),
			'HUNGARIAN' => array('ungarisch', 'hungarian', 'hongrois'),
			'GREEK' => array('griechisch', 'greek', 'grec'),
			'RUSSIAN' => array('russisch', 'russian', 'russe'),
			'JAPANESE' => array('japanisch', 'japanese', 'japonais'),
			'CHINESE' => array('chinesisch', 'mandarin', 'kantonesisch', 'chinese', 'chinois'),
			'KOREAN' => array('koreanisch', 'korean', 'coréen'),
			'ARABIC' => array('arabisch', 'arabic', 'arabe'),
			'HEBREW' => array('hebräisch', 'hebrew', 'hébreu'),
			'HINDI' => array('hindi'),
			'ICELANDIC' => array('isländisch', 'icelandic', 'islandais'),
			'CROATIAN' => array('kroatisch', 'croatian', 'croate'),
			'ROMANIAN' => array('rumänisch', 'romanian', 'roumain'),
			'UKRAINIAN' => array('ukrainisch', 'ukrainian', 'ukrainien'),
			'THAI' => array('thailändisch', 'thai', 'thaï'));
	$langs = array();
	$value = preg_replace('/\([^)]*\)/', '', $value);
	foreach (preg_split('/\s*[,;\/]\s*/', mb_strtolower($value, 'UTF-8')) as $word) {
		$word = trim($word);
		foreach ($map as $code => $words) {
			if (in_array($word, $words, TRUE)) {
				$langs[] = $code;
			}
		}
	}
	return array_values(array_unique($langs));
}

/**
 * "Freigegeben ab 12 Jahren" / "FSK 12" / "Ohne Altersbeschränkung" -> FSK12 (or USK12 for games)
 */
function amazon_age_rating($value, $prefix = 'FSK') {
	$v = mb_strtolower($value, 'UTF-8');
	if (preg_match('/ohne alters|freigegeben ohne|ab 0\b|fsk\s*0|usk\s*0/u', $v)) {
		return $prefix . '0';
	}
	if (preg_match('/(?:ab|fsk|usk)\s*(\d{1,2})/u', $v, $m) && in_array($m[1], array('6', '12', '16', '18'))) {
		return $prefix . $m[1];
	}
	if (preg_match('/keine jugendfreigabe|nicht jugendfrei/u', $v)) {
		return $prefix . '18';
	}
	return FALSE;
}

/**
 * German descriptions usually start with "(USA 1999, 136 Min., FSK 16)":
 * returns array(year, minutes, rating) of the film, values FALSE when missing.
 */
function amazon_film_facts($description) {
	$r = array('year' => FALSE, 'minutes' => FALSE, 'rating' => FALSE);
	if (preg_match('/\(([^()]*\b(1[89]\d\d|20\d\d)\b[^()]*)\)/u', $description, $m)
			&& preg_match('/\b(?:min|minuten|fsk)\b/iu', $m[1])) {
		$r['year'] = $m[2];
		if (preg_match('/(\d{2,3})\s*min/iu', $m[1], $mm)) {
			$r['minutes'] = (int)$mm[1];
		}
		$r['rating'] = amazon_age_rating($m[1]);
	}
	return $r;
}

function amazon_description($doc) {
	foreach (array('productDescription', 'bookDescription_feature_div', 'editorialReviews_feature_div') as $id) {
		$e = $doc->getElementById($id);
		if ($e) {
			$text = '';
			foreach ($e->childNodes as $n) {
				$text .= ' ' . $n->textContent;
			}
			$text = amazon_text($text);
			$text = preg_replace('/^.*?\b(Produktbeschreibung(?:en)?|Product description|Description du produit|Kurzbeschreibung|Buchbeschreibung)[\s:>-]*/iu', '', $text, 1);
			$text = preg_replace('/^show up to \d+ reviews by default\s*/i', '', $text);
			while (preg_match('/^(Produktbeschreibung(?:en)?|Product description|Description du produit|Kurzbeschreibung)[\s:>-]*/iu', $text, $m)) {
				$text = substr($text, strlen($m[0]));
			}
			$text = preg_replace('/\s*(Mehr lesen|Weniger lesen|Read more|Read less)\s*$/iu', '', $text);
			if (strlen($text) > 20) {
				return $text;
			}
		}
	}
	return '';
}
?>
