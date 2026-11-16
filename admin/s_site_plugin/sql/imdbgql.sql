#
# IMDb site plugin using the IMDb JSON services (no page scraping).
# Replaces the imdbphp plugin: keeps the site type 'imdb' and the IMDB_ID
# attribute, so existing items keep their link.
#

DELETE FROM s_site_plugin_s_attribute_type_lookup_map WHERE site_type = 'imdb';
DELETE FROM s_site_plugin_s_attribute_type_map WHERE site_type = 'imdb';
DELETE FROM s_site_plugin_link WHERE site_type = 'imdb';
DELETE FROM s_site_plugin_input_field WHERE site_type = 'imdb';
DELETE FROM s_site_plugin_conf WHERE site_type = 'imdb';
DELETE FROM s_site_plugin WHERE site_type = 'imdb';

INSERT INTO s_site_plugin ( site_type, classname, order_no, title, image, description, external_url, items_per_page, more_info_url )
VALUES ( 'imdb', 'imdbgql', 2, 'IMDb', 'imdb.gif', 'Film and series data from IMDb: German title, cast, genres, FSK, rating.', 'https://www.imdb.com', 20, 'https://www.imdb.com/title/{imdb_id}/' );

INSERT INTO s_site_plugin_input_field ( site_type, field, order_no, description, prompt, field_type, default_value, refresh_mask )
VALUES ( 'imdb', 'title', 1, '', 'Title Search', 'text', '', '{ifdef(alt_title,\'{alt_title}\',\'{title}\')}{ifdef(year,\' ({year})\')}' );
INSERT INTO s_site_plugin_input_field ( site_type, field, order_no, description, prompt, field_type, default_value, refresh_mask )
VALUES ( 'imdb', 'imdb_id', 2, '', 'IMDb ID or link', 'text', '', '{imdb_id}' );

INSERT INTO s_site_plugin_link ( site_type, s_item_type_group, s_item_type, order_no, description, url, title_url )
VALUES ( 'imdb', '*', '*', 1, 'IMDb', 'https://www.imdb.com/title/{imdb_id}/', 'https://www.imdb.com/find/?q={title}' );

INSERT INTO s_site_plugin_s_attribute_type_map ( site_type, s_item_type_group, s_item_type, variable, s_attribute_type, lookup_attribute_val_restrict_ind )
VALUES ( 'imdb', '*', '*', 'genre', 'MOVIEGENRE', 'Y' );
INSERT INTO s_site_plugin_s_attribute_type_map ( site_type, s_item_type_group, s_item_type, variable, s_attribute_type, lookup_attribute_val_restrict_ind )
VALUES ( 'imdb', '*', '*', 'plot', 'MOVIE_PLOT', 'N' );
INSERT INTO s_site_plugin_s_attribute_type_map ( site_type, s_item_type_group, s_item_type, variable, s_attribute_type, lookup_attribute_val_restrict_ind )
VALUES ( 'imdb', '*', '*', 'title', 'S_TITLE', 'N' );

UPDATE s_attribute_type SET site_type = 'imdb' WHERE s_attribute_type = 'IMDB_ID';

# genres IMDb uses that the MOVIEGENRE list did not have
INSERT INTO s_attribute_type_lookup (s_attribute_type, order_no, value, display, img, checked_ind)
SELECT 'MOVIEGENRE', 40, 'Crime', NULL, NULL, 'N' FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM s_attribute_type_lookup WHERE s_attribute_type = 'MOVIEGENRE' AND value = 'Crime');
INSERT INTO s_attribute_type_lookup (s_attribute_type, order_no, value, display, img, checked_ind)
SELECT 'MOVIEGENRE', 41, 'Family', NULL, NULL, 'N' FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM s_attribute_type_lookup WHERE s_attribute_type = 'MOVIEGENRE' AND value = 'Family');
INSERT INTO s_attribute_type_lookup (s_attribute_type, order_no, value, display, img, checked_ind)
SELECT 'MOVIEGENRE', 42, 'History', NULL, NULL, 'N' FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM s_attribute_type_lookup WHERE s_attribute_type = 'MOVIEGENRE' AND value = 'History');
INSERT INTO s_attribute_type_lookup (s_attribute_type, order_no, value, display, img, checked_ind)
SELECT 'MOVIEGENRE', 43, 'Biography', NULL, NULL, 'N' FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM s_attribute_type_lookup WHERE s_attribute_type = 'MOVIEGENRE' AND value = 'Biography');
