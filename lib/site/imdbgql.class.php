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

  IMDb site plugin without page scraping: the www.imdb.com pages answer
  non-browsers with a JavaScript challenge (HTTP 202), so this plugin uses
  the JSON services the IMDb site itself uses:

    search and details: https://caching.graphql.imdb.com/ (GraphQL; the
    search also finds German titles)

  The functions below the class are used by the Amazon plugin too, to
  complete DVD / Blu-ray items with genre, FSK, cast and IMDb id.

  IMDb allows limited, non-commercial use of this data only.
*/
include_once("./lib/SitePlugin.class.php");

class imdbgql extends SitePlugin {
	function __construct($site_type) {
		parent::__construct($site_type);
	}

	function queryListing($page_no, $items_per_page, $offset, $s_item_type, $search_vars_r) {
		$id = imdbgql_id($search_vars_r['imdb_id'] ?? '');
		if ($id === FALSE) {
			$id = imdbgql_id($search_vars_r['title'] ?? '');
		}
		if ($id !== FALSE) {
			$this->addListingRow(NULL, NULL, NULL, array('imdb_id' => $id));
			return TRUE;
		}

		$term = trim($search_vars_r['title'] ?? '');
		if (strlen($term) == 0) {
			return FALSE;
		}
		// "Title (1999)" narrows the search to that year
		$year = NULL;
		if (preg_match('/^(.*?)\s*\((\d{4})\)\s*$/', $term, $m)) {
			$term = $m[1];
			$year = $m[2];
		}

		$error = NULL;
		$found = imdbgql_search($term, 25, $error);
		if (!is_array($found)) {
			$this->setError('IMDb request failed', $error);
			return FALSE;
		}

		$rows = array();
		foreach ($found as $r) {
			if (!imdbgql_is_video_type($r['type'])) {
				continue;
			}
			if ($year != NULL && $r['year'] > 0 && abs((int)$r['year'] - (int)$year) > 1) {
				continue;
			}
			$rows[] = $r;
		}

		$this->setTotalCount(count($rows));
		foreach ($rows as $r) {
			$title = $r['title'];
			if (strlen($r['original']) > 0 && $r['original'] != $r['title']) {
				$title .= ' / ' . $r['original'];
			}
			$title .= ($r['year'] > 0 ? ' (' . $r['year'] . ')' : '') . ($r['type'] != 'movie' && strlen($r['type_text']) > 0 ? ' [' . $r['type_text'] . ']' : '');
			if (strlen($r['cast']) > 0) {
				$title .= ' - ' . $r['cast'];
			}
			$image = $r['image'] != NULL ? imdbgql_image($r['image'], 190) : NULL;
			$this->addListingRow($title, $image, NULL, array('imdb_id' => $r['id']));
		}
		return TRUE;
	}

	function queryItem($search_attributes_r, $s_item_type) {
		$id = imdbgql_id($search_attributes_r['imdb_id'] ?? '');
		if ($id === FALSE) {
			return FALSE;
		}
		$error = NULL;
		$t = imdbgql_title($id, $error);
		if ($t === FALSE) {
			$this->setError('IMDb request failed', $error);
			return FALSE;
		}
		foreach (imdbgql_title_attributes($id, $t) as $attr => $value) {
			$this->addItemAttribute($attr, $value);
		}
		return TRUE;
	}
}

/**
 * Runs a GraphQL query against the IMDb web API; the data array or FALSE.
 */
function imdbgql_graphql($query, &$error) {
	if (!function_exists('curl_init')) {
		$error = 'IMDb needs the PHP curl extension';
		return FALSE;
	}
	$ch = curl_init('https://caching.graphql.imdb.com/');
	curl_setopt_array($ch, array(
			CURLOPT_POST => TRUE,
			CURLOPT_POSTFIELDS => json_encode(array('query' => $query)),
			CURLOPT_RETURNTRANSFER => TRUE,
			CURLOPT_CONNECTTIMEOUT => 15,
			CURLOPT_TIMEOUT => 30,
			CURLOPT_ENCODING => '',
			CURLOPT_USERAGENT => 'Mozilla/5.0 (X11; Linux x86_64; rv:140.0) Gecko/20100101 Firefox/140.0',
			CURLOPT_HTTPHEADER => array(
					'Content-Type: application/json',
					'Accept: application/json',
					'Accept-Language: de-DE,de;q=0.9,en;q=0.6',
					'Origin: https://www.imdb.com',
					'x-imdb-client-name: imdb-web-next-localized')));
	$body = curl_exec($ch);
	$status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
	$curl_error = curl_error($ch);
	curl_close($ch);

	if ($body === FALSE || $status != 200) {
		$error = $body === FALSE ? $curl_error : 'HTTP ' . $status;
		return FALSE;
	}
	$json = json_decode($body, TRUE);
	if (!is_array($json) || !isset($json['data'])) {
		$error = isset($json['errors'][0]['message']) ? $json['errors'][0]['message'] : 'invalid answer';
		return FALSE;
	}
	return $json['data'];
}

function _imdbgql_str($value) {
	return '"' . addcslashes((string)$value, "\\\"\n\r") . '"';
}

/**
 * Title search (finds German titles too). A list of
 * array(id, title, original, year, type, image, cast).
 */
function imdbgql_search($term, $limit, &$error) {
	$data = imdbgql_graphql('{ mainSearch(first: ' . (int)$limit . ', options: { searchTerm: ' . _imdbgql_str($term) . ', type: TITLE, includeAdult: false }) {'
			. ' edges { node { entity { ... on Title { id titleText { text } originalTitleText { text } releaseYear { year } titleType { id text }'
			. ' primaryImage { url } principalCredits(filter: { categories: ["cast"] }) { credits(limit: 2) { name { nameText { text } } } } } } } } }', $error);
	if ($data === FALSE) {
		return FALSE;
	}
	$rows = array();
	foreach ((array)($data['mainSearch']['edges'] ?? array()) as $e) {
		$t = $e['node']['entity'] ?? NULL;
		if (!is_array($t) || !isset($t['id'])) {
			continue;
		}
		$cast = array();
		foreach ((array)($t['principalCredits'][0]['credits'] ?? array()) as $c) {
			$cast[] = $c['name']['nameText']['text'] ?? '';
		}
		$rows[] = array(
				'id' => $t['id'],
				'title' => $t['titleText']['text'] ?? '',
				'original' => $t['originalTitleText']['text'] ?? '',
				'year' => $t['releaseYear']['year'] ?? NULL,
				'type' => $t['titleType']['id'] ?? '',
				'type_text' => $t['titleType']['text'] ?? '',
				'image' => $t['primaryImage']['url'] ?? NULL,
				'cast' => implode(', ', array_filter($cast)));
	}
	return $rows;
}

/**
 * The IMDb title types that are films or series (no episodes, podcasts...).
 */
function imdbgql_is_video_type($type) {
	return in_array($type, array('movie', 'tvMovie', 'tvSeries', 'tvMiniSeries', 'tvSpecial', 'video', 'short'), TRUE);
}

/**
 * All the data of one title, or FALSE.
 */
function imdbgql_title($id, &$error) {
	$data = imdbgql_graphql('{ title(id: ' . _imdbgql_str($id) . ') {'
			. ' titleText { text } originalTitleText { text } releaseYear { year } titleType { id }'
			. ' runtime { seconds } ratingsSummary { aggregateRating } plot { plotText { plainText } }'
			. ' genres { genres { text } } primaryImage { url }'
			. ' directors: credits(first: 10, filter: { categories: ["director"] }) { edges { node { name { nameText { text } } } } }'
			. ' writers: credits(first: 10, filter: { categories: ["writer"] }) { edges { node { name { nameText { text } } } } }'
			. ' producers: credits(first: 10, filter: { categories: ["producer"] }) { edges { node { name { nameText { text } } } } }'
			. ' composers: credits(first: 5, filter: { categories: ["composer"] }) { edges { node { name { nameText { text } } } } }'
			. ' cast: credits(first: 15, filter: { categories: ["cast"] }) { edges { node { name { nameText { text } } } } }'
			. ' akas(first: 200) { edges { node { text country { id } language { id } } } }'
			. ' certificates(first: 100) { edges { node { rating country { id } } } }'
			. ' episodes { episodes(first: 1) { total } }'
			. ' } }', $error);
	if ($data === FALSE) {
		return FALSE;
	}
	if (!is_array($data['title'] ?? NULL)) {
		$error = 'unknown title';
		return FALSE;
	}
	return $data['title'];
}

/**
 * A title as site plugin attributes: imdb_id, title (German), alt_title
 * (original), year, run_time, imdbrating, plot, imageurl, genre (MOVIEGENRE
 * values), director, writer, producer, composer, actors, age_rating (FSK),
 * episodes.
 */
function imdbgql_title_attributes($id, $t) {
	$a = array('imdb_id' => $id);

	$original = $t['originalTitleText']['text'] ?? ($t['titleText']['text'] ?? '');
	$german = imdbgql_aka($t['akas']['edges'] ?? array(), array('DE', 'AT'));
	$title = $german !== FALSE ? $german : ($t['titleText']['text'] ?? $original);
	$a['title'] = $title;
	if (strlen($original) > 0 && $original != $title) {
		$a['alt_title'] = $original;
	}

	if (isset($t['releaseYear']['year'])) {
		$a['year'] = $t['releaseYear']['year'];
	}
	if (isset($t['runtime']['seconds']) && $t['runtime']['seconds'] > 0) {
		$a['run_time'] = (int)round($t['runtime']['seconds'] / 60);
	}
	if (isset($t['ratingsSummary']['aggregateRating'])) {
		$a['imdbrating'] = $t['ratingsSummary']['aggregateRating'];
	}
	if (strlen($t['plot']['plotText']['plainText'] ?? '') > 0) {
		$a['plot'] = $t['plot']['plotText']['plainText'];
	}
	if (isset($t['primaryImage']['url'])) {
		$a['imageurl'] = imdbgql_image($t['primaryImage']['url'], 600);
	}

	$genres = array();
	foreach ((array)($t['genres']['genres'] ?? array()) as $g) {
		$genres[] = imdbgql_genre($g['text']);
	}
	if (in_array($t['titleType']['id'] ?? '', array('tvSeries', 'tvMiniSeries'), TRUE)) {
		$genres[] = 'Television';
	}
	if (count($genres) > 0) {
		$a['genre'] = array_values(array_unique($genres));
	}

	foreach (array('directors' => 'director', 'writers' => 'writer', 'producers' => 'producer', 'composers' => 'composer', 'cast' => 'actors') as $key => $attr) {
		$names = array();
		foreach ((array)($t[$key]['edges'] ?? array()) as $e) {
			if (strlen($e['node']['name']['nameText']['text'] ?? '') > 0) {
				$names[] = $e['node']['name']['nameText']['text'];
			}
		}
		if (count($names) > 0) {
			$a[$attr] = array_values(array_unique($names));
		}
	}

	foreach ((array)($t['certificates']['edges'] ?? array()) as $e) {
		if (($e['node']['country']['id'] ?? '') == 'DE') {
			$r = mb_strtolower($e['node']['rating'], 'UTF-8');
			if (preg_match('/^(0|6|12|16|18)$/', $r)) {
				$a['age_rating'] = 'FSK' . $r;
				break;
			} else if (strpos($r, 'o.al') !== FALSE || $r == 'ohne altersbeschränkung') {
				$a['age_rating'] = 'FSK0';
				break;
			}
		}
	}

	if (isset($t['episodes']['episodes']['total']) && $t['episodes']['episodes']['total'] > 0) {
		$a['episodes'] = $t['episodes']['episodes']['total'];
	}
	return $a;
}

/**
 * The IMDb id for a disc title from a shop ("Smallville Gesamtbox [60 DVDs]",
 * "Das gibt Ärger (Blu-ray)"), or FALSE.
 */
function imdbgql_find_id($title, $year, &$error) {
	$term = imdbgql_search_term($title);
	if (strlen($term) < 2) {
		return FALSE;
	}
	$rows = imdbgql_search($term, 8, $error);
	if (!is_array($rows)) {
		return FALSE;
	}
	$wanted = mb_strtolower($term, 'UTF-8');
	$best = NULL;
	foreach ($rows as $r) {
		if (!imdbgql_is_video_type($r['type'])) {
			continue;
		}
		$exact = mb_strtolower($r['title'], 'UTF-8') === $wanted || mb_strtolower($r['original'], 'UTF-8') === $wanted;
		$near = $year > 0 && $r['year'] > 0 && abs($r['year'] - $year) <= 1;
		$score = ($exact ? 2 : 0) + ($near ? 1 : 0);
		if ($best === NULL || $score > $best[0]) {
			$best = array($score, $r['id']);
		}
	}
	return $best !== NULL ? $best[1] : FALSE;
}

/**
 * A shop title without edition, box and format words.
 */
function imdbgql_search_term($title) {
	$t = preg_replace('/\s*[\[(][^\])]*[\])]/u', ' ', (string)$title);
	$t = preg_replace('/\b(Gesamtbox|Komplettbox|Die komplette Serie|Die komplette Staffel|Komplette Serie|Complete Series|Staffeln?\s*[\d\s,.&-]+|Seasons?\s*[\d\s,.&-]+|Box\s*Set|Collector\'?s Edition|Special Edition|Limited Edition|Steelbook|Director\'?s Cut|Extended( Edition| Cut)?|Uncut|Remastered|4K Ultra HD|Ultra HD|Blu-?ray|DVD)\b/iu', ' ', $t);
	$t = preg_replace('/\s*[-–:,]\s*$/u', '', trim(preg_replace('/\s+/u', ' ', $t)));
	return trim($t, " \t-–:,");
}

/**
 * tt1234567 from an id or an IMDb link, else FALSE.
 */
function imdbgql_id($value) {
	if (preg_match('/\b(tt\d{5,})\b/', (string)$value, $m)) {
		return $m[1];
	}
	return FALSE;
}

/**
 * An IMDb image URL scaled to the given width (..._V1_.jpg -> ..._V1_UX600_.jpg).
 */
function imdbgql_image($url, $width) {
	return preg_replace('/\._V1_[^\/]*\.(jpg|png)$/i', '._V1_UX' . (int)$width . '_.$1', $url);
}

/**
 * The title for one of the countries, preferring a German language entry.
 */
function imdbgql_aka($edges, $countries) {
	$found = FALSE;
	foreach ((array)$edges as $e) {
		$n = $e['node'] ?? array();
		if (in_array($n['country']['id'] ?? '', $countries, TRUE) && strlen($n['text'] ?? '') > 0) {
			$lang = $n['language']['id'] ?? NULL;
			if ($lang == NULL || $lang == 'de') {
				return $n['text'];
			}
			if ($found === FALSE) {
				$found = $n['text'];
			}
		}
	}
	return $found;
}

/**
 * IMDb genre -> MOVIEGENRE lookup value.
 */
function imdbgql_genre($genre) {
	static $map = array('Sci-Fi' => 'ScienceFiction', 'Sport' => 'Sports', 'Film-Noir' => 'Crime', 'Game-Show' => 'Television',
			'Reality-TV' => 'Television', 'Talk-Show' => 'Television', 'News' => 'Documentary');
	return $map[$genre] ?? $genre;
}
?>
