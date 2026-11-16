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

    search:  https://v3.sg.media-imdb.com/suggestion/x/<term>.json
    details: https://caching.graphql.imdb.com/ (GraphQL)

  IMDb allows limited, non-commercial use of this data only.
*/
include_once("./lib/SitePlugin.class.php");

class imdbgql extends SitePlugin {
	const GRAPHQL_URL = 'https://caching.graphql.imdb.com/';

	// IMDb title types offered for video item types
	private $video_types = array('movie', 'tvMovie', 'tvSeries', 'tvMiniSeries', 'tvSpecial', 'video', 'tvEpisode', 'short');

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

		$slug = rawurlencode(mb_strtolower($term, 'UTF-8'));
		$json = json_decode($this->fetchURI('https://v3.sg.media-imdb.com/suggestion/x/' . $slug . '.json?includeVideos=0', TRUE), TRUE);
		if (!is_array($json)) {
			return FALSE;
		}

		$rows = array();
		foreach ((array)($json['d'] ?? array()) as $r) {
			if (!isset($r['id']) || !preg_match('/^tt\d+$/', $r['id'])) {
				continue;
			}
			if (!in_array($r['qid'] ?? '', $this->video_types, TRUE)) {
				continue;
			}
			if ($year != NULL && isset($r['y']) && abs((int)$r['y'] - (int)$year) > 1) {
				continue;
			}
			$rows[] = $r;
		}

		$this->setTotalCount(count($rows));
		foreach ($rows as $r) {
			$title = $r['l'] . (isset($r['y']) ? ' (' . $r['y'] . ')' : '') . (isset($r['q']) && $r['qid'] != 'movie' ? ' [' . $r['q'] . ']' : '');
			if (strlen($r['s'] ?? '') > 0) {
				$title .= ' - ' . $r['s'];
			}
			$image = isset($r['i']['imageUrl']) ? imdbgql_image($r['i']['imageUrl'], 190) : NULL;
			$this->addListingRow($title, $image, NULL, array('imdb_id' => $r['id']));
		}
		return TRUE;
	}

	function queryItem($search_attributes_r, $s_item_type) {
		$id = imdbgql_id($search_attributes_r['imdb_id'] ?? '');
		if ($id === FALSE) {
			return FALSE;
		}

		$t = $this->graphql('{ title(id: "' . $id . '") {'
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
				. ' } }');
		if (!is_array($t)) {
			return FALSE;
		}
		$t = $t['title'] ?? NULL;
		if (!is_array($t)) {
			return FALSE;
		}

		$this->addItemAttribute('imdb_id', $id);

		// German title if IMDb knows one, the original as alternate title
		$original = $t['originalTitleText']['text'] ?? ($t['titleText']['text'] ?? '');
		$german = imdbgql_aka($t['akas']['edges'] ?? array(), array('DE', 'AT'));
		$title = $german !== FALSE ? $german : ($t['titleText']['text'] ?? $original);
		$this->addItemAttribute('title', $title);
		if (strlen($original) > 0 && $original != $title) {
			$this->addItemAttribute('alt_title', $original);
		}

		if (isset($t['releaseYear']['year'])) {
			$this->addItemAttribute('year', $t['releaseYear']['year']);
		}
		if (isset($t['runtime']['seconds']) && $t['runtime']['seconds'] > 0) {
			$this->addItemAttribute('run_time', (int)round($t['runtime']['seconds'] / 60));
		}
		if (isset($t['ratingsSummary']['aggregateRating'])) {
			$this->addItemAttribute('imdbrating', $t['ratingsSummary']['aggregateRating']);
		}
		if (strlen($t['plot']['plotText']['plainText'] ?? '') > 0) {
			$this->addItemAttribute('plot', $t['plot']['plotText']['plainText']);
		}
		if (isset($t['primaryImage']['url'])) {
			$this->addItemAttribute('imageurl', imdbgql_image($t['primaryImage']['url'], 600));
		}

		$genres = array();
		foreach ((array)($t['genres']['genres'] ?? array()) as $g) {
			$genres[] = imdbgql_genre($g['text']);
		}
		if (in_array($t['titleType']['id'] ?? '', array('tvSeries', 'tvMiniSeries'), TRUE)) {
			$genres[] = 'Television';
		}
		if (count($genres) > 0) {
			$this->addItemAttribute('genre', array_values(array_unique($genres)));
		}

		foreach (array('directors' => 'director', 'writers' => 'writer', 'producers' => 'producer', 'composers' => 'composer', 'cast' => 'actors') as $key => $attr) {
			$names = array();
			foreach ((array)($t[$key]['edges'] ?? array()) as $e) {
				if (strlen($e['node']['name']['nameText']['text'] ?? '') > 0) {
					$names[] = $e['node']['name']['nameText']['text'];
				}
			}
			if (count($names) > 0) {
				$this->addItemAttribute($attr, array_values(array_unique($names)));
			}
		}

		// German age rating (FSK)
		foreach ((array)($t['certificates']['edges'] ?? array()) as $e) {
			if (($e['node']['country']['id'] ?? '') == 'DE') {
				$r = mb_strtolower($e['node']['rating'], 'UTF-8');
				if (preg_match('/^(0|6|12|16|18)$/', $r)) {
					$this->addItemAttribute('age_rating', 'FSK' . $r);
					break;
				} else if (strpos($r, 'o.al') !== FALSE || $r == 'ohne altersbeschränkung') {
					$this->addItemAttribute('age_rating', 'FSK0');
					break;
				}
			}
		}

		if (isset($t['episodes']['episodes']['total']) && $t['episodes']['episodes']['total'] > 0) {
			$this->addItemAttribute('episodes', $t['episodes']['episodes']['total']);
		}

		return TRUE;
	}

	/**
	 * Runs a GraphQL query, returns the data array or FALSE.
	 */
	function graphql($query) {
		if (!function_exists('curl_init')) {
			$this->setError('IMDb needs the PHP curl extension');
			return FALSE;
		}
		$ch = curl_init(self::GRAPHQL_URL);
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
		$error = curl_error($ch);
		curl_close($ch);

		if ($body === FALSE || $status != 200) {
			$this->setError('IMDb request failed', $body === FALSE ? $error : 'HTTP ' . $status);
			return FALSE;
		}
		$json = json_decode($body, TRUE);
		if (!is_array($json) || !isset($json['data'])) {
			$msg = isset($json['errors'][0]['message']) ? $json['errors'][0]['message'] : 'invalid answer';
			$this->setError('IMDb request failed', $msg);
			return FALSE;
		}
		return $json['data'];
	}
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
