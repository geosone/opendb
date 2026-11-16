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

  Amazon site plugin (amazon.com, .co.uk, .fr, .de).

  Search:  /s?k=<title or barcode>&i=<index>  - result tiles <div role="listitem" data-asin=...>
  Item:    /dp/<ASIN>                          - #productTitle, #landingImage, product details
           (#detailBullets_feature_div or #productDetails_* tables), #bylineInfo,
           #productDescription / #bookDescription_feature_div

  The product detail labels are in the language of the shop, they are mapped
  to one set of keys by amazon_detail_key().
*/
include_once("./lib/SitePlugin.class.php");
include_once("./lib/site/amazonutils.php");

class amazon extends SitePlugin {
	private $url;
	private $asinId;

	private $sites = array(
			'amazon' => array('asinId' => 'amazonasin', 'url' => 'www.amazon.com'),
			'amazonuk' => array('asinId' => 'amazukasin', 'url' => 'www.amazon.co.uk'),
			'amazonfr' => array('asinId' => 'amazfrasin', 'url' => 'www.amazon.fr'),
			'amazonde' => array('asinId' => 'amazdeasin', 'url' => 'www.amazon.de'));

	function __construct($site_type) {
		parent::__construct($site_type);

		$this->asinId = $this->sites[$site_type]['asinId'];
		$this->url = $this->sites[$site_type]['url'];
	}

	function queryListing($page_no, $items_per_page, $offset, $s_item_type, $search_vars_r) {
		$asin = trim($search_vars_r[$this->asinId] ?? '');
		if (strlen($asin) > 0) {
			if (preg_match('!/(?:dp|gp/product)/([A-Z0-9]{10})!i', $asin, $m)) { // a pasted product link
				$asin = $m[1];
			}
			$this->addListingRow(NULL, NULL, NULL, array($this->asinId => strtoupper($asin)));
			return TRUE;
		}

		$term = trim($search_vars_r['title'] ?? '');
		if (strlen($term) == 0) {
			return FALSE;
		}

		$index_type = ifempty($this->getConfigValue('item_type_to_index_map', $s_item_type), strtolower($s_item_type));
		$amazon_index = amazon_search_index($index_type);
		$barcode = amazon_barcode($term);

		$keywords = $barcode !== FALSE ? $barcode : $term;
		$queryUrl = "https://" . $this->url . "/s?k=" . urlencode($keywords) . ($amazon_index != NULL ? "&i=" . $amazon_index : "") . ($page_no > 1 ? "&page=" . (int)$page_no : "");

		$pageBuffer = $this->fetchURI($queryUrl, TRUE);
		if (strlen($pageBuffer) == 0) {
			return FALSE;
		}

		$results = amazon_parse_search_results($pageBuffer);
		$pageBuffer = NULL;

		// Blu-ray and DVD share one index: prefer the requested format
		if ($index_type == 'dvd' || $index_type == 'bd') {
			$wanted = array();
			foreach ($results as $r) {
				$is_bd = preg_match('/blu-?ray|4k|uhd/i', $r['title'] . ' ' . $r['format']);
				if (($s_item_type == 'BD' && $is_bd) || ($s_item_type != 'BD' && !$is_bd)) {
					$wanted[] = $r;
				}
			}
			if (count($wanted) > 0) {
				$results = $wanted;
			}
		}

		$extra = array('search.title' => $term);
		if ($barcode !== FALSE) {
			$extra['search.barcode'] = $barcode;
		}

		// Amazon also lists the bundles that contain the product for a barcode
		// ("Smallville Gesamtbox & O.C. California"): drop "A & B" titles when
		// a result without one exists
		if ($barcode !== FALSE && count($results) > 1) {
			$single = array();
			foreach ($results as $r) {
				if (!amazon_is_bundle_title($r['title'])) {
					$single[] = $r;
				}
			}
			if (count($single) > 0) {
				$results = $single;
			}
		}

		// a barcode that finds exactly one product is that product
		if ($barcode !== FALSE && count($results) == 1 && !$this->isPreviousPage()) {
			$this->addListingRow(NULL, NULL, NULL, array_merge(array($this->asinId => $results[0]['asin']), $extra));
			return TRUE;
		}

		$total = amazon_parse_result_count($results);
		$this->setTotalCount($total);

		foreach ($results as $r) {
			$title = $r['title'];
			if (strlen($r['format']) > 0 && stripos($title, $r['format']) === FALSE) {
				$title .= ' [' . $r['format'] . ']';
			}
			$this->addListingRow($title, $r['image'], NULL, array_merge(array($this->asinId => $r['asin']), $extra));
		}
		return TRUE;
	}

	function queryItem($search_attributes_r, $s_item_type) {
		$asin = $search_attributes_r[$this->asinId] ?? '';
		if (!preg_match('/^[A-Z0-9]{10}$/i', $asin)) {
			return FALSE;
		}

		$pageBuffer = $this->fetchURI("https://" . $this->url . "/dp/" . strtoupper($asin), TRUE);
		if (strlen($pageBuffer) == 0) {
			return FALSE;
		}

		$xmlDoc = amazon_load_dom($pageBuffer);
		$pageBuffer = NULL;
		if ($xmlDoc === FALSE) {
			return FALSE;
		}

		// TITLE
		foreach (array('productTitle', 'ebooksProductTitle', 'btAsinTitle', 'title') as $id) {
			$e = $xmlDoc->getElementById($id);
			if ($e && strlen(trim($e->textContent)) > 0) {
				$this->addItemAttribute('title', amazon_clean_title($e->textContent));
				break;
			}
		}

		// barcode / ISBN the item was found with
		$code = $search_attributes_r['search.barcode'] ?? amazon_barcode($search_attributes_r['search.title'] ?? '');
		if ($code !== FALSE && $code !== NULL) {
			if (preg_match('/^97[89]/', $code) && strlen($code) == 13) {
				$this->addItemAttribute('isbn13', $code);
			} else if (strlen($code) == 10 && preg_match('/^[0-9]{9}[0-9X]$/', $code)) {
				$this->addItemAttribute('isbn', $code);
			} else {
				$this->addItemAttribute('upc_id', $code);
			}
		}

		// COVER
		$image = amazon_main_image($xmlDoc);
		if ($image) {
			$this->addItemAttribute('imageurl', $image);
		}

		// PRICE
		$price = amazon_price($xmlDoc);
		if ($price) {
			$this->addItemAttribute('listprice', $price);
		}

		$details = amazon_product_details($xmlDoc);
		$byline = amazon_byline($xmlDoc);
		$description = amazon_description($xmlDoc);

		$index_type = ifempty($this->getConfigValue('item_type_to_index_map', $s_item_type), strtolower($s_item_type));
		switch ($index_type) {
			case 'dvd':
			case 'bd':
			case 'vhs':
				$this->parse_video($s_item_type, $details, $byline, $description);
				break;
			case 'books':
			case 'stripbooks':
				$this->parse_books($details, $byline, $description, $xmlDoc);
				break;
			case 'videogames':
				$this->parse_game($details, $description);
				break;
			case 'music':
			case 'popular':
				$this->parse_music($details, $byline, $description);
				break;
		}

		return TRUE;
	}

	function parse_video($s_item_type, $details, $byline, $description) {
		$people = array(
				'actors' => 'actors',
				'director' => 'director',
				'producer' => 'producer',
				'writer' => 'writer',
				'composer' => 'composer');
		foreach ($people as $key => $attr) {
			if (isset($details[$key])) {
				$this->addItemAttribute($attr, amazon_names($details[$key]));
			} else if (isset($byline[$key])) {
				$this->addItemAttribute($attr, $byline[$key]);
			}
		}

		if (isset($details['studio'])) {
			$this->addItemAttribute('studio', trim(preg_replace('/\s*\(.*\)\s*$/', '', $details['studio'])));
		}
		// the film itself (year, length, rating) as given in the description,
		// else what the product details say about the disc
		$facts = amazon_film_facts($description);
		$minutes = isset($details['run_time']) ? amazon_minutes($details['run_time']) : 0;
		if ($facts['minutes'] !== FALSE && ($minutes == 0 || $minutes > $facts['minutes'] * 1.5)) {
			$minutes = $facts['minutes'];
		}
		if ($minutes > 0) {
			$this->addItemAttribute('run_time', $minutes);
		}
		$year = $facts['year'] !== FALSE ? $facts['year'] : (isset($details['release_date']) ? amazon_year($details['release_date']) : FALSE);
		if ($year) {
			$this->addItemAttribute('year', $year);
		}
		if (!isset($details['rating']) && $facts['rating'] !== FALSE) {
			$this->addItemAttribute('age_rating', $facts['rating']);
		}
		if (isset($details['ratio']) && ($ratio = amazon_ratio($details['ratio'])) !== FALSE) {
			$this->addItemAttribute('ratio', $ratio);
		}
		if (isset($details['discs']) && preg_match('/(\d+)/', $details['discs'], $m)) {
			$this->addItemAttribute('no_media', $m[1]);
		}
		if (isset($details['language'])) {
			$this->addItemAttribute('audio_lang', amazon_languages($details['language']));
		}
		if (isset($details['subtitles'])) {
			$this->addItemAttribute('subtitles', amazon_languages($details['subtitles']));
		}
		if (isset($details['rating']) && ($rating = amazon_age_rating($details['rating'])) !== FALSE) {
			$this->addItemAttribute('age_rating', $rating);
		}
		if (isset($details['region'])) {
			$region = $details['region'];
			if ($s_item_type == 'BD') {
				if (preg_match('/\b([ABC])\b/', $region, $m)) {
					$this->addItemAttribute('bd_region', $m[1]);
				}
			} else if (preg_match('/([0-6])/', $region, $m)) {
				$this->addItemAttribute('dvd_region', $m[1]);
			}
		}
		if (isset($details['format']) && preg_match('/\b(PAL|NTSC|SECAM)\b/', $details['format'], $m)) {
			$this->addItemAttribute('vid_format', $m[1]);
		}
		if (strlen($description) > 0) {
			$this->addItemAttribute('blurb', $description);
		}
	}

	function parse_books($details, $byline, $description, $xmlDoc) {
		if (isset($byline['author'])) {
			$this->addItemAttribute('author', $byline['author']);
		}
		if (isset($details['publisher'])) {
			$this->addItemAttribute('publisher', trim(preg_replace(array('/\([^)]*\)/', '/;.*/'), '', $details['publisher'])));
		}
		if (isset($details['pages']) && preg_match('/(\d+)/', $details['pages'], $m)) {
			$this->addItemAttribute('no_pages', $m[1]);
		}
		if (isset($details['binding'])) {
			$this->addItemAttribute('binding', $details['binding']);
		}
		if (isset($details['isbn10'])) {
			$this->addItemAttribute('isbn', str_replace('-', '', $details['isbn10']));
		}
		if (isset($details['isbn13'])) {
			$this->addItemAttribute('isbn13', str_replace('-', '', $details['isbn13']));
		}
		if (isset($details['language'])) {
			$this->addItemAttribute('language', amazon_languages($details['language']));
		}
		$date = $details['release_date'] ?? ($details['publisher'] ?? '');
		if (($year = amazon_year($date)) !== FALSE) {
			$this->addItemAttribute('pub_year', $year);
		}
		if (strlen($description) > 0) {
			$this->addItemAttribute('blurb', $description);
		}
	}

	function parse_game($details, $description) {
		if (isset($details['release_date']) && ($year = amazon_year($details['release_date'])) !== FALSE) {
			$this->addItemAttribute('gamepbdate', $year);
		}
		if (isset($details['publisher'])) {
			$this->addItemAttribute('gamepblshr', $details['publisher']);
		}
		if (isset($details['rating']) && ($rating = amazon_age_rating($details['rating'], 'USK')) !== FALSE) {
			$this->addItemAttribute('gamerating', $rating);
		}
		if (strlen($description) > 0) {
			$this->addItemAttribute('blurb', $description);
		}
	}

	function parse_music($details, $byline, $description) {
		if (isset($byline['artist'])) {
			$this->addItemAttribute('artist', $byline['artist']);
		}
		if (isset($details['label'])) {
			$this->addItemAttribute('publisher', $details['label']);
		}
		if (isset($details['discs']) && preg_match('/(\d+)/', $details['discs'], $m)) {
			$this->addItemAttribute('no_media', $m[1]);
		}
		if (isset($details['release_date']) && ($year = amazon_year($details['release_date'])) !== FALSE) {
			$this->addItemAttribute('year', $year);
		}
		if (strlen($description) > 0) {
			$this->addItemAttribute('blurb', $description);
		}
	}
}
?>
