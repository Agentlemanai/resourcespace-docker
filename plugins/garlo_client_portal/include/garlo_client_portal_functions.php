<?php
/**
 * Garlomedia client portal provisioning.
 *
 * Gives a client a ResourceSpace login that sees only their own approved material, the same
 * way the hand-made "Client - Xbody" / "Client - Virtualitica" setups work:
 *   1. a node "<client>" in the Client field ($garlo_cp_client_field)
 *   2. a search filter "Client: <client>, approved only" = Client is <client> AND Approved
 *   3. a user group "Client - <client>" with the template group's permissions, featured
 *      collection access limited to the client's collection tree (j<collection_ref>)
 *   4. a user in that group with a generated password
 *
 * Idempotent: anything that already exists is reused, never overwritten. An existing user keeps
 * its password (password is returned as null). An existing group only gets j<collection_ref>
 * added if it is missing.
 */

function garlo_cp_setting(string $name, int $default): int
{
    return isset($GLOBALS[$name]) && is_numeric($GLOBALS[$name]) ? (int) $GLOBALS[$name] : $default;
}

function garlo_cp_username(string $client_name): string
{
    $ascii = function_exists('iconv') ? @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $client_name) : $client_name;
    $slug = strtolower(preg_replace('/[^A-Za-z0-9]+/', '', (string) $ascii));
    return $slug !== '' ? substr($slug, 0, 50) : 'client';
}

/**
 * API: garlo_client_portal_provision
 *
 * @param string $client_name     Client name as in Twenty (also the Client field value).
 * @param int    $collection_ref  The client's root featured collection.
 * @param string $username        Optional; defaults to the client name lower-cased, letters and digits only.
 * @param string $fullname        Optional display name; defaults to the client name.
 *
 * @return array JSend response; data has user_ref, username, password (null if the user already existed),
 *               user_created, usergroup, group_created, search_filter, client_node, login_url.
 */
function api_garlo_client_portal_provision(string $client_name, int $collection_ref, string $username = '', string $fullname = ''): array
{
    global $lang, $baseurl;

    $assert_post = assert_post_request(defined('API_AUTHMODE_NATIVE'));
    if ($assert_post !== []) {
        return $assert_post;
    }

    if (!checkperm('a') || !checkPermission_manage_users()) {
        http_response_code(403);
        return ajax_response_fail(ajax_build_message($lang['error-permissiondenied']));
    }

    $client_name = trim($client_name);
    if ($client_name === '' || $collection_ref <= 0) {
        http_response_code(400);
        return ajax_response_fail(ajax_build_message('client_name and collection_ref are required'));
    }

    $collection = get_collection($collection_ref);
    if (!is_array($collection) || (int) $collection['type'] !== COLLECTION_TYPE_FEATURED) {
        http_response_code(400);
        return ajax_response_fail(ajax_build_message("Collection {$collection_ref} is not a featured collection"));
    }

    $template_group = garlo_cp_setting('garlo_cp_template_group', 10);
    $client_field   = garlo_cp_setting('garlo_cp_client_field', 90);
    $approved_node  = garlo_cp_setting('garlo_cp_approved_node', 278);

    // 1. Client field value
    $node = get_node_id($client_name, $client_field);
    if ($node === false) {
        $node = set_node(null, $client_field, $client_name, null, null);
    }
    if (!$node) {
        http_response_code(500);
        return ajax_response_fail(ajax_build_message("Could not create Client value '{$client_name}' in field {$client_field}"));
    }
    $node = (int) $node;

    // 2 + 3. Group (with its filter)
    $access = 'j' . $collection_ref;
    $group_name = 'Client - ' . $client_name;
    $group = ps_query('SELECT ref, permissions, search_filter_id FROM usergroup WHERE name = ?', ['s', $group_name]);
    $group_created = false;

    if (count($group) > 0) {
        $group_ref = (int) $group[0]['ref'];
        $filter_ref = (int) $group[0]['search_filter_id'];
        $perms = array_filter(array_map('trim', explode(',', (string) $group[0]['permissions'])), 'strlen');
        if (!in_array($access, $perms, true)) {
            $perms[] = $access;
            save_usergroup($group_ref, ['permissions' => implode(',', $perms)]);
        }
    } else {
        $template = ps_query('SELECT permissions FROM usergroup WHERE ref = ?', ['i', $template_group]);
        if (count($template) === 0) {
            http_response_code(500);
            return ajax_response_fail(ajax_build_message("Template group {$template_group} not found"));
        }
        // Copy the template's permissions, but never its featured-collection access (j*, j<ref>).
        $perms = array_filter(
            array_map('trim', explode(',', (string) $template[0]['permissions'])),
            fn($p) => $p !== '' && !preg_match('/^j(\*|\d+)$/', $p)
        );
        $perms[] = $access;

        $filter_ref = (int) save_filter(0, "Client: {$client_name}, approved only", RS_FILTER_ALL);
        save_filter_rule('new', $filter_ref, [[1, [$node]]]);
        save_filter_rule('new', $filter_ref, [[1, [$approved_node]]]);

        $group_ref = (int) save_usergroup(0, [
            'name'             => $group_name,
            'permissions'      => implode(',', $perms),
            'search_filter_id' => $filter_ref,
        ]);
        $group_created = true;
    }

    // 4. User
    $username = trim($username) !== '' ? trim($username) : garlo_cp_username($client_name);
    $existing = ps_value('SELECT ref value FROM user WHERE username = ?', ['s', $username], 0);
    $password = null;
    $user_created = false;

    if ($existing > 0) {
        $user_ref = (int) $existing;
    } else {
        $user_ref = new_user($username, $group_ref);
        if (!is_numeric($user_ref) || (int) $user_ref <= 0) {
            http_response_code(409);
            return ajax_response_fail(ajax_build_message($user_ref === -2 ? $lang['userlimitreached'] : $lang['useralreadyexists']));
        }
        $user_ref = (int) $user_ref;
        $password = make_password();
        $saved = save_user($user_ref, [
            'username'  => $username,
            'password'  => $password,
            'fullname'  => trim($fullname) !== '' ? trim($fullname) : $client_name,
            'usergroup' => $group_ref,
            'approved'  => 1,
        ]);
        if ($saved !== true) {
            http_response_code(409);
            return ajax_response_fail(ajax_build_message(is_string($saved) ? $saved : 'save_user failed'));
        }
        $user_created = true;
    }

    clear_query_cache('usergroup');

    return ajax_response_ok([
        'user_ref'      => $user_ref,
        'username'      => $username,
        'password'      => $password,
        'user_created'  => $user_created,
        'usergroup'     => $group_ref,
        'group_created' => $group_created,
        'search_filter' => $filter_ref,
        'client_node'   => $node,
        'login_url'     => rtrim($baseurl, '/') . '/login.php',
    ]);
}
