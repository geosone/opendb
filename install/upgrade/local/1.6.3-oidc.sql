#
# Admin options for the single sign-on (lib/oidc.php). The provider
# connection itself (issuer, client id and secret) stays in
# include/local.config.php.
#
DELETE FROM s_config_group_item_var WHERE group_id = 'login.oidc';
DELETE FROM s_config_group_item WHERE group_id = 'login.oidc';
DELETE FROM s_config_group WHERE id = 'login.oidc';

INSERT INTO s_config_group ( id, order_no, name, description ) VALUES ( 'login.oidc', 2, 'Single Sign-On', 'Login through the OpenID Connect provider configured in include/local.config.php' );

INSERT INTO s_config_group_item ( group_id, id, order_no, prompt, description, type, subtype ) VALUES ('login.oidc', 'create_users', 1, 'Create Users', 'Create an OpenDb user at the first single sign-on of a provider user who has none (no password; only the single sign-on logs them in)', 'boolean', NULL);
INSERT INTO s_config_group_item ( group_id, id, order_no, prompt, description, type, subtype ) VALUES ('login.oidc', 'create_role', 2, 'Role of Created Users', '', 'value_select', 'OWNER,BORROWER,GUEST');
INSERT INTO s_config_group_item ( group_id, id, order_no, prompt, description, type, subtype ) VALUES ('login.oidc', 'create_groups', 3, 'Only Members of Groups', 'Create users only for members of one of these provider groups (empty: everyone the provider lets through)', 'array', 'text');
INSERT INTO s_config_group_item ( group_id, id, order_no, prompt, description, type, subtype ) VALUES ('login.oidc', 'sync_profile', 4, 'Take Name and Email', 'Update name and email address from the provider at every single sign-on', 'boolean', NULL);

INSERT INTO s_config_group_item_var ( group_id, id, value ) VALUES ('login.oidc', 'create_users', 'FALSE');
INSERT INTO s_config_group_item_var ( group_id, id, value ) VALUES ('login.oidc', 'create_role', 'OWNER');
INSERT INTO s_config_group_item_var ( group_id, id, value ) VALUES ('login.oidc', 'sync_profile', 'TRUE');
