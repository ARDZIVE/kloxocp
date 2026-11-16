<?php
/**
 * Internal panel redirect. External / protocol-relative / javascript: targets refused (F18).
 */
chdir("../..");
include_once "lib/html/displayinclude.php";

initProgram();

$name = isset($ghtml->frm_redirectname) ? (string) $ghtml->frm_redirectname : '';

if (!$ghtml->frm_redirectaction) {
	$ghtml->print_redirect_back('you_didnt_specify_an_action', 'nname');
	exit;
}

$action = base64_decode((string) $ghtml->frm_redirectaction, true);
if ($action === false) {
	$ghtml->print_redirect_back('you_didnt_specify_an_action', 'nname');
	exit;
}

$url = str_replace("__tmp_lx_name__", $name, $action);
$url = trim($url);

if (!kloxo_safe_internal_redirect($url)) {
	$ghtml->print_redirect_back('external_url_not_allowed', 'nname');
	exit;
}

header('Location: ' . $url);
exit;

/**
 * Allow only same-app relative paths (leading / or bare display.php?... style).
 */
function kloxo_safe_internal_redirect(string $url): bool
{
	if ($url === '' || strlen($url) > 2048) {
		return false;
	}
	// Block schemes, protocol-relative, and control characters.
	if (preg_match('/^[a-z][a-z0-9+.-]*:/i', $url)) {
		return false;
	}
	if (str_starts_with($url, '//') || str_contains($url, "\n") || str_contains($url, "\r")
		|| str_contains($url, "\0")) {
		return false;
	}
	// Must be a relative path within the panel (/, ./, or bare script path).
	if ($url[0] === '/' || str_starts_with($url, './') || preg_match('/^[A-Za-z0-9_.?-]/', $url)) {
		// Reject path traversal to absolute filesystem or parent escapes via \\.
		if (str_contains($url, '\\')) {
			return false;
		}
		return true;
	}

	return false;
}
