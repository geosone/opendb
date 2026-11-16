<?php
/*
    Open Media Collectors Database
    Copyright (C) 2001-2012 by Jason Pell

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
 */
function theme_header($pageid, $title, $include_menu, $mode, $user_id) {
	if ($pageid == 'install') {
		$pageTitle = get_opendb_title_and_version() . " Installation";
	} else {
		$pageTitle = get_opendb_title();
	}
	$lang = strtolower(get_opendb_site_language()) == 'german' ? 'de' : 'en';
	$with_menu = $include_menu && $mode != 'printable' && $mode != 'no-menu';

	echo ("<!DOCTYPE html>\n<html lang=\"" . $lang . "\">\n<head>"
			. "\n<meta charset=\"" . ifempty(get_opendb_config_var('themes', 'charset'), 'utf-8') . "\">"
			. "\n<meta name=\"viewport\" content=\"width=device-width, initial-scale=1\">"
			. "\n<meta name=\"color-scheme\" content=\"light dark\">"
			. "\n<title>" . $pageTitle . (!empty($title) ? " - $title" : "") . "</title>"
			. "\n<link rel=\"icon\" href=\"" . theme_image_src("icon-16x16.png") . "\" type=\"image/png\">"
			. "\n<link rel=\"search\" type=\"application/opensearchdescription+xml\" title=\"" . get_opendb_title() . " Title Search\" href=\"./searchplugins.php?type=title\">"
			. "\n<link rel=\"search\" type=\"application/opensearchdescription+xml\" title=\"" . get_opendb_title() . " UPC Search\" href=\"./searchplugins.php?type=upc\">"
			. get_theme_css($pageid, $mode)
			. get_opendb_rss_feeds_links()
			. get_theme_javascript($pageid)
			. "</head>"
			. "\n<body class=\"page-" . $pageid . ($with_menu ? " with-menu" : "") . "\">");

	echo ("<header id=\"header\">");
	if ($with_menu) {
		echo ("<button type=\"button\" class=\"navToggle\" aria-label=\"" . get_opendb_lang_var('main_menu') . "\" onclick=\"document.body.classList.toggle('nav-open'); return false;\"><span></span><span></span><span></span></button>");
	}
	echo ("<h1><a href=\"index.php\">" . $pageTitle . "</a></h1>");

	if ($include_menu) {
		echo ("<ul class=\"headerLinks\">");

		if (is_user_granted_permission(PERM_VIEW_LISTINGS, NULL, TRUE)) {
			echo ("<li class=\"search\"><form class=\"quickSearch\" action=\"listings.php\" role=\"search\">"
					. "<input type=\"hidden\" name=\"search_list\" value=\"y\">"
					. "<input type=\"hidden\" name=\"title_match\" value=\"partial\">"
					. "<input type=\"search\" class=\"text\" name=\"title\" placeholder=\"" . htmlspecialchars(get_opendb_lang_var('title')) . " …\" aria-label=\"" . htmlspecialchars(get_opendb_lang_var('title')) . "\">"
					. "</form></li>");
		}

		if (is_user_granted_permission(PERM_VIEW_ADVANCED_SEARCH, NULL, TRUE)) {
			echo ("<li><a href=\"search.php\" title=\"" . get_opendb_lang_var('advanced_search') . "\">" . get_opendb_lang_var('advanced') . "</a></li>");
		}

		if (is_exists_my_reserve_basket($user_id)) {
			echo ("<li class=\"icon\"><a href=\"borrow.php?op=my_reserve_basket\">" . theme_image("basket.png", get_opendb_lang_var('item_reserve_list')) . "</a></li>");
		}

		$printable_page_url = get_printable_page_url($pageid);
		if ($printable_page_url != NULL) {
			echo ("<li class=\"icon\"><a href=\"" . $printable_page_url . "\" target=\"_new\" title=\"" . get_opendb_lang_var('printable_version') . "\">" . theme_image("printable.gif") . "</a></li>");
		}

		$help_page = get_opendb_help_page($pageid);
		if ($help_page != NULL) {
			echo ("<li class=\"icon help\"><a href=\"help.php?page=" . $help_page . "\" target=\"_new\" title=\"" . get_opendb_lang_var('help') . "\">?</a></li>");
		}

		if (strlen($user_id) > 0) {
			echo ("<li class=\"login\"><a href=\"logout.php\">" . get_opendb_lang_var('logout', 'user_id', $user_id) . "</a></li>");
		} else {
			echo ("<li class=\"login\"><a href=\"login.php?op=login\">" . get_opendb_lang_var('login') . "</a></li>");
		}

		echo ("</ul>");
	}

	echo ("</header>");

	if ($with_menu) {
		echo ("\n<nav id=\"sidebar\">");
		if ($pageid == 'admin') {
			echo ("\n<div id=\"admin-menu\" class=\"menuContainer\">"
					. "<h2 class=\"menu\">" . get_opendb_lang_var('admin_tools') . "</h2>"
					. "<div id=\"admin-menu-content\" class=\"menuContent\">"
					. get_menu_options_list(get_system_admin_tools_menu())
					. "</div></div>");
		}
		echo ("\n<div id=\"menu\" class=\"menuContainer\">"
				. "<h2 class=\"menu\">" . get_opendb_lang_var('main_menu') . "</h2>"
				. "<div id=\"menu-content\" class=\"menuContent\">"
				. get_menu_options_list(get_menu_options($user_id))
				. "</div></div>");
		echo ("\n</nav>");
		echo ("<div class=\"navBackdrop\" onclick=\"document.body.classList.remove('nav-open');\"></div>");
	}

	echo ("\n<main id=\"content\" class=\"" . $pageid . "Content\">");
}

function theme_footer($pageid, $user_id) {
	echo ("</main>");

	if ($pageid != 'install')
		echo ("<footer id=\"footer\"><a href=\"https://github.com/geosone/opendb\">" . get_opendb_lang_var('powered_by_site', 'site', get_opendb_title_and_version()) . "</a></footer>");

	echo ("</body></html>");
}

function theme_css_map($pageid) {
	$themeCssMap = array(
			'borrow' => array('listings', 'item_display'), 
			'item_borrow' => array('listings', 'item_display'), 
			'quick_checkout' => array('listings', 'item_display'), 
			'import' => array('listings', 'item_display', 'item_input'), 
			'item_display' => array('listings'),
			'item_input' => array('listings'), 
			'user_listing' => array('listings'), 
			'admin' => array('listings', 'item_input'), 
			'export' => array('item_input'), 
			'search' => array('item_review', 'item_input'), 
			'item_review' => array('item_input'));

	if (isset($themeCssMap[$pageid])) {
		return $themeCssMap[$pageid];
	} else {
		return NULL;
	}
}
?>
