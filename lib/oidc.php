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

	Login through an OpenID Connect provider (Authelia, Keycloak, ...):
	authorization code flow with PKCE, the code is exchanged server side
	and the user read from the userinfo endpoint. Configured in
	include/local.config.php:

	$CONFIG_VARS['oidc'] = array(
		'enable' => TRUE,
		'issuer' => 'https://auth.example.org',
		'client_id' => 'opendb',
		'client_secret' => '...',
		'redirect_uri' => 'https://opendb.example.org/login.php?op=oidc_callback',
		'scopes' => 'openid profile email',
		'button' => 'Single Sign-On',
		// provider user name => OpenDb user id; otherwise the same user
		// id, otherwise the one active user with the same email address
		'user_map' => array('jdoe' => 'admin'),
	);

	OpenDb users are not created: an unknown provider user is refused.
*/

include_once("./lib/logging.php");
include_once("./lib/user.php");
include_once("./lib/auth.php");

function is_oidc_enabled() {
	return get_opendb_config_var('oidc', 'enable') === TRUE
			&& strlen((string)get_opendb_config_var('oidc', 'issuer')) > 0
			&& strlen((string)get_opendb_config_var('oidc', 'client_id')) > 0
			&& function_exists('curl_init');
}

function oidc_button_label() {
	return ifempty(get_opendb_config_var('oidc', 'button'), 'Single Sign-On');
}

/**
 * GET or POST to the provider; returns the decoded JSON or FALSE.
 */
function _oidc_request($url, $post = NULL, $headers = array(), $basic_auth = NULL) {
	if (!preg_match('!^https://!i', $url)) {
		return FALSE;
	}
	$ch = curl_init($url);
	$opts = array(
			CURLOPT_RETURNTRANSFER => TRUE,
			CURLOPT_FOLLOWLOCATION => FALSE,
			CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
			CURLOPT_CONNECTTIMEOUT => 10,
			CURLOPT_TIMEOUT => 20,
			CURLOPT_HTTPHEADER => array_merge(array('Accept: application/json'), $headers));
	if ($post !== NULL) {
		$opts[CURLOPT_POST] = TRUE;
		$opts[CURLOPT_POSTFIELDS] = http_build_query($post, '', '&');
	}
	if ($basic_auth !== NULL) {
		$opts[CURLOPT_HTTPAUTH] = CURLAUTH_BASIC;
		$opts[CURLOPT_USERPWD] = $basic_auth;
	}
	curl_setopt_array($ch, $opts);
	$body = curl_exec($ch);
	$status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
	$error = curl_error($ch);
	curl_close($ch);

	if ($body === FALSE || $status != 200) {
		opendb_logger(OPENDB_LOG_ERROR, __FILE__, __FUNCTION__, $body === FALSE ? $error : 'HTTP ' . $status . ' ' . substr((string)$body, 0, 200), array($url));
		return FALSE;
	}
	$json = json_decode($body, TRUE);
	return is_array($json) ? $json : FALSE;
}

/**
 * The provider's endpoints from its discovery document.
 */
function oidc_discovery() {
	$issuer = rtrim(get_opendb_config_var('oidc', 'issuer'), '/');
	if (isset($_SESSION['oidc_discovery']) && ($_SESSION['oidc_discovery']['issuer'] ?? '') === $issuer) {
		return $_SESSION['oidc_discovery'];
	}
	$d = _oidc_request($issuer . '/.well-known/openid-configuration');
	if (!is_array($d) || rtrim($d['issuer'] ?? '', '/') !== $issuer
			|| !isset($d['authorization_endpoint'], $d['token_endpoint'], $d['userinfo_endpoint'])) {
		return FALSE;
	}
	$d = array(
			'issuer' => $issuer,
			'authorization_endpoint' => $d['authorization_endpoint'],
			'token_endpoint' => $d['token_endpoint'],
			'userinfo_endpoint' => $d['userinfo_endpoint']);
	$_SESSION['oidc_discovery'] = $d;
	return $d;
}

function _oidc_b64url($bin) {
	return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
}

/**
 * Sends the browser to the provider. $redirect is the local page to
 * return to after the login.
 */
function oidc_start($redirect) {
	$d = oidc_discovery();
	if ($d === FALSE) {
		return FALSE;
	}
	$verifier = _oidc_b64url(random_bytes(48));
	$_SESSION['oidc'] = array(
			'state' => _oidc_b64url(random_bytes(24)),
			'nonce' => _oidc_b64url(random_bytes(24)),
			'verifier' => $verifier,
			'redirect' => (string)$redirect,
			'time' => time());

	$params = array(
			'response_type' => 'code',
			'client_id' => get_opendb_config_var('oidc', 'client_id'),
			'redirect_uri' => get_opendb_config_var('oidc', 'redirect_uri'),
			'scope' => ifempty(get_opendb_config_var('oidc', 'scopes'), 'openid profile email'),
			'state' => $_SESSION['oidc']['state'],
			'nonce' => $_SESSION['oidc']['nonce'],
			'code_challenge' => _oidc_b64url(hash('sha256', $verifier, TRUE)),
			'code_challenge_method' => 'S256');
	$url = $d['authorization_endpoint'] . (strpos($d['authorization_endpoint'], '?') === FALSE ? '?' : '&') . http_build_query($params, '', '&', PHP_QUERY_RFC3986);

	header('Location: ' . $url, TRUE, 302);
	return TRUE;
}

/**
 * The claims of an id token received straight from the token endpoint
 * over TLS (OIDC Core 3.1.3.7: the TLS validation of the issuer may
 * replace the signature check there).
 */
function _oidc_id_token_claims($jwt) {
	$parts = explode('.', (string)$jwt);
	if (count($parts) != 3) {
		return FALSE;
	}
	$claims = json_decode(base64_decode(strtr($parts[1], '-_', '+/')), TRUE);
	return is_array($claims) ? $claims : FALSE;
}

/**
 * Handles the provider's redirect back. Returns the OpenDb user id or
 * FALSE (with $error set to a short reason).
 */
function oidc_callback($code, $state, &$error) {
	$flow = $_SESSION['oidc'] ?? NULL;
	unset($_SESSION['oidc']);

	if (!is_array($flow) || strlen((string)$state) == 0 || !hash_equals($flow['state'], (string)$state)) {
		$error = 'invalid state';
		return FALSE;
	}
	if (time() - $flow['time'] > 600) {
		$error = 'login took too long';
		return FALSE;
	}
	if (strlen((string)$code) == 0) {
		$error = 'no code';
		return FALSE;
	}

	$d = oidc_discovery();
	if ($d === FALSE) {
		$error = 'provider not reachable';
		return FALSE;
	}

	$client_id = get_opendb_config_var('oidc', 'client_id');
	$token = _oidc_request($d['token_endpoint'], array(
			'grant_type' => 'authorization_code',
			'code' => $code,
			'redirect_uri' => get_opendb_config_var('oidc', 'redirect_uri'),
			'code_verifier' => $flow['verifier']),
			array(), rawurlencode($client_id) . ':' . rawurlencode((string)get_opendb_config_var('oidc', 'client_secret')));
	if (!is_array($token) || strlen($token['access_token'] ?? '') == 0 || strlen($token['id_token'] ?? '') == 0) {
		$error = 'token exchange failed';
		return FALSE;
	}

	$claims = _oidc_id_token_claims($token['id_token']);
	$aud = (array)($claims['aud'] ?? array());
	if ($claims === FALSE || rtrim($claims['iss'] ?? '', '/') !== $d['issuer'] || !in_array($client_id, $aud, TRUE)
			|| ($claims['exp'] ?? 0) < time() - 60 || !hash_equals($flow['nonce'], (string)($claims['nonce'] ?? ''))) {
		$error = 'invalid id token';
		return FALSE;
	}

	$info = _oidc_request($d['userinfo_endpoint'], NULL, array('Authorization: Bearer ' . $token['access_token']));
	if (!is_array($info) || ($info['sub'] ?? NULL) !== ($claims['sub'] ?? '')) {
		$error = 'userinfo failed';
		return FALSE;
	}

	$user_id = oidc_map_user($info);
	if ($user_id === FALSE) {
		$error = 'no OpenDb user for ' . ($info['preferred_username'] ?? $info['sub']);
		opendb_logger(OPENDB_LOG_WARN, __FILE__, __FUNCTION__, 'No matching user', array($info['preferred_username'] ?? '', $info['email'] ?? ''));
		return FALSE;
	}
	oidc_sync_user($user_id, $info);
	$_SESSION['oidc_redirect'] = $flow['redirect'];
	return $user_id;
}

/**
 * Takes the name and email address from the provider, which is the
 * directory they are maintained in ('sync_profile' => FALSE turns it off).
 */
function oidc_sync_user($user_id, $info) {
	if (get_opendb_config_var('oidc', 'sync_profile') === FALSE) {
		return;
	}
	$user_r = fetch_user_r($user_id);
	if (!is_array($user_r)) {
		return;
	}
	$fullname = trim((string)($info['name'] ?? ''));
	if (strlen($fullname) == 0) {
		$fullname = trim(($info['given_name'] ?? '') . ' ' . ($info['family_name'] ?? ''));
	}
	$email = trim((string)($info['email'] ?? ''));
	if (($info['email_verified'] ?? TRUE) === FALSE || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
		$email = '';
	}
	$new_fullname = strlen($fullname) > 0 ? $fullname : $user_r['fullname'];
	$new_email = strlen($email) > 0 ? $email : $user_r['email_addr'];
	if ($new_fullname !== $user_r['fullname'] || $new_email !== $user_r['email_addr']) {
		update_user($user_id, $new_fullname, FALSE, FALSE, $new_email, FALSE);
	}
}

/**
 * Provider user -> active OpenDb user id, or FALSE.
 */
function oidc_map_user($info) {
	$name = strtolower(trim((string)($info['preferred_username'] ?? '')));

	$map = get_opendb_config_var('oidc', 'user_map');
	if (is_array($map) && strlen($name) > 0) {
		foreach ($map as $from => $to) {
			if (strtolower($from) === $name) {
				return is_user_active($to) ? $to : FALSE;
			}
		}
	}

	if (strlen($name) > 0 && is_user_valid($name) && is_user_active($name)) {
		return $name;
	}

	$email = strtolower(trim((string)($info['email'] ?? '')));
	if (strlen($email) > 0 && ($info['email_verified'] ?? TRUE) !== FALSE) {
		$results = db_query("SELECT user_id FROM user WHERE LOWER(email_addr) = '" . db_escape($email) . "' AND active_ind = 'Y'");
		if ($results && db_num_rows($results) == 1) {
			$r = db_fetch_assoc($results);
			db_free_result($results);
			return $r['user_id'];
		}
	}
	return FALSE;
}
?>
