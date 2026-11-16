<?php
/*
 * Rewrites SQL string building so that every value placed between single
 * quotes in a query goes through db_escape().
 *
 *   "... WHERE user_id = '$uid'"            -> "... WHERE user_id = '" . db_escape($uid) . "'"
 *   "... = '{$r['id']}'"                    -> "... = '" . db_escape($r['id']) . "'"
 *   "... = '" . $uid . "'"                  -> "... = '" . db_escape($uid) . "'"
 *
 * Only strings that look like SQL are touched, expressions that are already
 * escaped (db_escape, addslashes, intval, (int)) are left alone.
 *
 * usage: php tools/sql-escape-rewrite.php FILE...   (rewrites in place, prints a count per file)
 */

const SQL_RE = '/\b(SELECT|INSERT|UPDATE|DELETE|REPLACE)\b|\b(WHERE|VALUES|SET|AND|OR|LIKE|IN|FROM)\b\s/i';
const SAFE_RE = '/^\s*(db_escape|addslashes|intval|\(int\)|md5)\s*\(/i';

function tok_text($t) {
	return is_array($t) ? $t[1] : $t;
}

function tok_id($t) {
	return is_array($t) ? $t[0] : $t;
}

// Converts the tokens of a simple interpolation ($a, $a[key], $a[0], $a[$i], $o->p)
// inside a double-quoted string to plain PHP code.
function interpolation_to_code(array $toks) {
	$code = '';
	$n = count($toks);
	for ($i = 0; $i < $n; $i++) {
		$t = $toks[$i];
		if (tok_id($t) === '[' && $i + 1 < $n && tok_id($toks[$i + 1]) === T_STRING) {
			$code .= "['" . tok_text($toks[$i + 1]) . "']";
			$i += 2; // skip key and ']'
			continue;
		}
		$code .= tok_text($t);
	}
	return $code;
}

// SQL text of the statement around token $i: all string literals from the previous ; { } to the next ;
function stmt_ctx(array $toks, $i) {
	$n = count($toks);
	$a = $i;
	while ($a > 0 && !in_array(tok_id($toks[$a - 1]), [';', '{', '}'], true)) $a--;
	$b = $i;
	while ($b < $n && tok_id($toks[$b]) !== ';') $b++;
	$ctx = '';
	for ($q = $a; $q < $b; $q++) {
		$id = tok_id($toks[$q]);
		if ($id === T_CONSTANT_ENCAPSED_STRING || $id === T_ENCAPSED_AND_WHITESPACE) {
			$ctx .= tok_text($toks[$q]) . ' ';
		}
	}
	return $ctx;
}

// For a string token at $i whose text ends with a quote: if it is followed by
// . EXPR . "'..." return [index of first EXPR token, index of the closing '.', EXPR text], else NULL.
function concat_expr(array $toks, $i) {
	$n = count($toks);
	$j = $i + 1;
	while ($j < $n && tok_id($toks[$j]) === T_WHITESPACE) $j++;
	if ($j >= $n || tok_id($toks[$j]) !== '.') return NULL;
	$j++;
	$start = $j;
	$depth = 0;
	while ($j < $n) {
		$id = tok_id($toks[$j]);
		if ($id === '(' || $id === '[') $depth++;
		if ($id === ')' || $id === ']') $depth--;
		if ($depth < 0 || $id === ';' || $id === ',') return NULL;
		if ($depth == 0 && $id === '.') {
			$q = $j + 1;
			while ($q < $n && tok_id($toks[$q]) === T_WHITESPACE) $q++;
			if ($q < $n) {
				$nt = tok_text($toks[$q]);
				if ((tok_id($toks[$q]) === T_CONSTANT_ENCAPSED_STRING && preg_match('/^["\']\'/', $nt))
					|| (tok_id($toks[$q]) === '"' && $q + 1 < $n && tok_id($toks[$q + 1]) === T_ENCAPSED_AND_WHITESPACE
						&& substr(tok_text($toks[$q + 1]), 0, 1) === "'")) {
					$expr = '';
					for ($r = $start; $r < $j; $r++) $expr .= tok_text($toks[$r]);
					return [$start, $j, $expr];
				}
			}
			return NULL;
		}
		$j++;
	}
	return NULL;
}

function wrap_expr($expr) {
	$lead = substr($expr, 0, strlen($expr) - strlen(ltrim($expr)));
	$trail = substr($expr, strlen(rtrim($expr)));
	return $lead . 'db_escape(' . trim($expr) . ')' . $trail;
}

function rewrite($src, &$count) {
	$toks = token_get_all($src);
	$out = '';
	$n = count($toks);
	$count = 0;

	for ($i = 0; $i < $n; $i++) {
		$t = $toks[$i];

		// --- interpolated "..." strings --------------------------------
		if (tok_id($t) === '"') {
			// collect the whole string
			$j = $i + 1;
			$parts = [];
			while ($j < $n && tok_id($toks[$j]) !== '"') {
				$parts[] = $toks[$j];
				$j++;
			}
			$whole = '';
			foreach ($parts as $p) {
				$whole .= tok_text($p);
			}
			if (!preg_match(SQL_RE, $whole . ' ' . stmt_ctx($toks, $i))) {
				$out .= '"' . $whole . '"';
				$i = $j;
				continue;
			}
			// split into literal pieces and interpolations
			$segs = []; // ['lit', text] | ['var', code]
			$k = 0;
			$m = count($parts);
			while ($k < $m) {
				$p = $parts[$k];
				$id = tok_id($p);
				if ($id === T_ENCAPSED_AND_WHITESPACE) {
					$segs[] = ['lit', tok_text($p)];
					$k++;
				} elseif ($id === T_CURLY_OPEN || $id === T_DOLLAR_OPEN_CURLY_BRACES) {
					$depth = 1;
					$expr = [];
					$k++;
					while ($k < $m && $depth > 0) {
						$tid = tok_id($parts[$k]);
						if ($tid === '{' || $tid === T_CURLY_OPEN) $depth++;
						if ($tid === '}') {
							$depth--;
							if ($depth == 0) break;
						}
						$expr[] = $parts[$k];
						$k++;
					}
					$k++; // closing }
					$code = '';
					foreach ($expr as $e) $code .= tok_text($e);
					if ($id === T_DOLLAR_OPEN_CURLY_BRACES) {
						$segs[] = ['var', '$' . $code, '${' . $code . '}'];
					} else {
						$segs[] = ['var', $code, '{' . $code . '}'];
					}
				} elseif ($id === T_VARIABLE) {
					$v = [$p];
					$k++;
					if ($k < $m && tok_id($parts[$k]) === '[') {
						while ($k < $m) {
							$v[] = $parts[$k];
							if (tok_id($parts[$k]) === ']') {
								$k++;
								break;
							}
							$k++;
						}
					} elseif ($k + 1 < $m && tok_id($parts[$k]) === T_OBJECT_OPERATOR) {
						$v[] = $parts[$k];
						$v[] = $parts[$k + 1];
						$k += 2;
					}
					$raw = '';
					foreach ($v as $e) $raw .= tok_text($e);
					$segs[] = ['var', interpolation_to_code($v), $raw];
				} else {
					$segs[] = ['lit', tok_text($p)];
					$k++;
				}
			}
			// rebuild, escaping interpolations enclosed in '...'
			$pieces = [];
			$cur = '';
			$changed = false;
			$sc = count($segs);
			for ($s = 0; $s < $sc; $s++) {
				$seg = $segs[$s];
				if ($seg[0] === 'lit') {
					$cur .= $seg[1];
					continue;
				}
				$before = $cur;
				$after = ($s + 1 < $sc && $segs[$s + 1][0] === 'lit') ? $segs[$s + 1][1] : '';
				if (substr($before, -1) === "'" && substr($after, 0, 1) === "'") {
					$pieces[] = ['lit', $cur];
					$pieces[] = ['code', 'db_escape(' . $seg[1] . ')'];
					$cur = '';
					$changed = true;
					$count++;
				} else {
					$cur .= $seg[2];
				}
			}
			$pieces[] = ['lit', $cur];
			if (!$changed) {
				$out .= '"' . $whole . '"';
			} else {
				$code = [];
				foreach ($pieces as $pc) {
					if ($pc[0] === 'lit') {
						if ($pc[1] !== '') $code[] = '"' . $pc[1] . '"';
					} else {
						$code[] = $pc[1];
					}
				}
				$out .= implode(' . ', $code);
			}
			$i = $j;
			if (substr($whole, -1) === "'" && ($ce = concat_expr($toks, $j)) !== NULL
				&& preg_match('/\$/', $ce[2]) && !preg_match(SAFE_RE, $ce[2])
				&& preg_match(SQL_RE, stmt_ctx($toks, $j))) {
				for ($q = $j + 1; $q < $ce[0]; $q++) $out .= tok_text($toks[$q]);
				$out .= wrap_expr($ce[2]);
				$count++;
				$i = $ce[1] - 1;
			}
			continue;
		}

		// --- "...'" . EXPR . "'..." -----------------------------------
		if (tok_id($t) === T_CONSTANT_ENCAPSED_STRING && preg_match('/\'["\']$/', tok_text($t))
			&& ($ce = concat_expr($toks, $i)) !== NULL
			&& preg_match('/\$/', $ce[2]) && !preg_match(SAFE_RE, $ce[2])
			&& preg_match(SQL_RE, stmt_ctx($toks, $i))) {
			$out .= tok_text($t);
			for ($q = $i + 1; $q < $ce[0]; $q++) $out .= tok_text($toks[$q]);
			$out .= wrap_expr($ce[2]);
			$count++;
			$i = $ce[1] - 1;
			continue;
		}

		$out .= tok_text($t);
	}
	return $out;
}

foreach (array_slice($argv, 1) as $file) {
	$src = file_get_contents($file);
	$new = rewrite($src, $count);
	if ($count > 0) {
		file_put_contents($file, $new);
	}
	printf("%4d %s\n", $count, $file);
}
