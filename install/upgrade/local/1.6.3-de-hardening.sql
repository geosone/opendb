#
# Changes of the geosone/opendb fork for an existing 1.6.x database.
# Safe to run more than once.
#

# password_hash() needs up to 255 characters (passwords are rehashed
# from md5 at the next login of each user)
ALTER TABLE user MODIFY pwd VARCHAR(255);

# German age ratings (FSK), set by the amazon.de site plugin
INSERT INTO s_attribute_type_lookup (s_attribute_type, order_no, value, display, img, checked_ind)
SELECT 'AGE_RATING', 20, 'FSK0', 'FSK 0 - ohne Altersbeschränkung', NULL, 'N' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM s_attribute_type_lookup WHERE s_attribute_type = 'AGE_RATING' AND value = 'FSK0');
INSERT INTO s_attribute_type_lookup (s_attribute_type, order_no, value, display, img, checked_ind)
SELECT 'AGE_RATING', 21, 'FSK6', 'FSK 6', NULL, 'N' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM s_attribute_type_lookup WHERE s_attribute_type = 'AGE_RATING' AND value = 'FSK6');
INSERT INTO s_attribute_type_lookup (s_attribute_type, order_no, value, display, img, checked_ind)
SELECT 'AGE_RATING', 22, 'FSK12', 'FSK 12', NULL, 'N' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM s_attribute_type_lookup WHERE s_attribute_type = 'AGE_RATING' AND value = 'FSK12');
INSERT INTO s_attribute_type_lookup (s_attribute_type, order_no, value, display, img, checked_ind)
SELECT 'AGE_RATING', 23, 'FSK16', 'FSK 16', NULL, 'N' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM s_attribute_type_lookup WHERE s_attribute_type = 'AGE_RATING' AND value = 'FSK16');
INSERT INTO s_attribute_type_lookup (s_attribute_type, order_no, value, display, img, checked_ind)
SELECT 'AGE_RATING', 24, 'FSK18', 'FSK 18 - keine Jugendfreigabe', NULL, 'N' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM s_attribute_type_lookup WHERE s_attribute_type = 'AGE_RATING' AND value = 'FSK18');

# the amazon.de plugin now maps languages and ratings itself; the old
# text based lookup maps only matched a few exact spellings
DELETE FROM s_site_plugin_s_attribute_type_lookup_map WHERE site_type = 'amazonde' AND s_attribute_type IN ('AGE_RATING', 'AUDIO_LANG', 'SUBTITLES');
