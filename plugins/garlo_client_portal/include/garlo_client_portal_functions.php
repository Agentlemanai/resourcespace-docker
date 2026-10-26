<?php
/**
 * Garlomedia client portal provisioning.
 *
 * A client's login sees only their own approved material:
 *   1. a node "<client>" in the Client field ($garlo_cp_client_field)
 *   2. a search filter "Client: <client>, approved only" = Client is <client> AND Approved
 *   3. a user group "Client - <client>" with the template group's permissions, featured
 *      collection access limited to the client's collection tree (j<collection_ref>)
 *   4. users in that group
 *
 * People limited to some of the client's projects get a group of their own:
 *   "Client - <client> · <person> [<key>]", same permissions plus -j<collection> for the projects they
 *   don't see, and a filter Client is <client> AND Approved AND Project is one of <their projects>.
 *   The Project field ($garlo_cp_project_field_name, created on first use) is kept in step with project
 *   collection membership by garlo_client_portal_sync_project_tags; an untagged file matches no
 *   project, so it is hidden from limited people until it is tagged.
 *
 * Everything is idempotent: existing records are reused. Existing users keep their password
 * (password is returned as null). Only logins that already sit in a "Client - " group are ever modified.
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

/** Common guard for every API call: POST, admin with user management rights. Returns [] when allowed. */
function garlo_cp_guard(): array
{
    global $lang;
    $assert_post = assert_post_request(defined('API_AUTHMODE_NATIVE'));
    if ($assert_post !== []) {
        return $assert_post;
    }
    if (!checkperm('a') || !checkPermission_manage_users()) {
        http_response_code(403);
        return ajax_response_fail(ajax_build_message($lang['error-permissiondenied']));
    }
    return [];
}

function garlo_cp_fail(int $code, string $message): array
{
    http_response_code($code);
    return ajax_response_fail(ajax_build_message($message));
}

/** Permissions of the template group, without any featured-collection access (j*, j<ref>, -j<ref>). */
function garlo_cp_template_permissions(): ?array
{
    $template = ps_query('SELECT permissions FROM usergroup WHERE ref = ?', ['i', garlo_cp_setting('garlo_cp_template_group', 10)]);
    if (count($template) === 0) {
        return null;
    }
    return array_values(array_filter(
        array_map('trim', explode(',', (string) $template[0]['permissions'])),
        fn($p) => $p !== '' && !preg_match('/^-?j(\*|\d+)$/', $p)
    ));
}

/** Client field node for the client (created if missing). */
function garlo_cp_client_node(string $client_name): int
{
    $field = garlo_cp_setting('garlo_cp_client_field', 90);
    $node = get_node_id($client_name, $field);
    if ($node === false) {
        $node = set_node(null, $field, $client_name, null, null);
    }
    return (int) $node;
}

/** The Project field (checkbox list), created on first use. */
function garlo_cp_project_field(): int
{
    $shortname = $GLOBALS['garlo_cp_project_field_name'] ?? 'garloproject';
    $ref = (int) ps_value('SELECT ref value FROM resource_type_field WHERE name = ?', ['s', $shortname], 0, 'schema');
    if ($ref > 0) {
        return $ref;
    }
    $ref = (int) create_resource_type_field('Project', 0, FIELD_TYPE_CHECK_BOX_LIST, $shortname, true);
    clear_query_cache('schema');
    return $ref;
}

/** Project node for a project collection: "<client collection name> / <project collection name>". */
function garlo_cp_project_node(int $collection_ref, int $field): int
{
    $c = get_collection($collection_ref);
    if (!is_array($c)) {
        return 0;
    }
    $parent = (int) $c['parent'] > 0 ? get_collection((int) $c['parent']) : false;
    $name = trim((is_array($parent) ? $parent['name'] . ' / ' : '') . $c['name']);
    $node = get_node_id($name, $field);
    if ($node === false) {
        $node = set_node(null, $field, $name, null, null);
    }
    return (int) $node;
}

/** A collection and all collections below it. */
function garlo_cp_collection_tree(int $collection_ref): array
{
    $all = [$collection_ref];
    $level = [$collection_ref];
    for ($depth = 0; $depth < 10 && count($level) > 0; $depth++) {
        $level = array_map('intval', ps_array(
            'SELECT ref value FROM collection WHERE parent IN (' . ps_param_insert(count($level)) . ')',
            ps_param_fill($level, 'i')
        ));
        $level = array_diff($level, $all);
        $all = array_merge($all, $level);
    }
    return $all;
}

/** "Client - <client>" group with its filter (created if missing). Returns [group_ref, group_created, filter_ref, node]. */
function garlo_cp_client_group(string $client_name, int $collection_ref): array
{
    $node = garlo_cp_client_node($client_name);
    $access = 'j' . $collection_ref;
    $group_name = 'Client - ' . $client_name;
    $group = ps_query('SELECT ref, permissions, search_filter_id FROM usergroup WHERE name = ?', ['s', $group_name]);

    if (count($group) > 0) {
        $group_ref = (int) $group[0]['ref'];
        $perms = array_filter(array_map('trim', explode(',', (string) $group[0]['permissions'])), 'strlen');
        if (!in_array($access, $perms, true)) {
            $perms[] = $access;
            save_usergroup($group_ref, ['permissions' => implode(',', $perms)]);
        }
        return [$group_ref, false, (int) $group[0]['search_filter_id'], $node];
    }

    $perms = garlo_cp_template_permissions();
    if ($perms === null) {
        throw new RuntimeException('Template group ' . garlo_cp_setting('garlo_cp_template_group', 10) . ' not found');
    }
    $perms[] = $access;
    $filter_ref = (int) save_filter(0, "Client: {$client_name}, approved only", RS_FILTER_ALL);
    save_filter_rule('new', $filter_ref, [[1, [$node]]]);
    save_filter_rule('new', $filter_ref, [[1, [garlo_cp_setting('garlo_cp_approved_node', 278)]]]);
    $group_ref = (int) save_usergroup(0, [
        'name'             => $group_name,
        'permissions'      => implode(',', $perms),
        'search_filter_id' => $filter_ref,
    ]);
    return [$group_ref, true, $filter_ref, $node];
}

function garlo_cp_is_client_group(int $group_ref): bool
{
    $name = (string) ps_value('SELECT name value FROM usergroup WHERE ref = ?', ['i', $group_ref], '');
    return strpos($name, 'Client - ') === 0;
}

/**
 * API: garlo_client_portal_provision — the client's shared company login.
 *
 * @return array JSend; data has user_ref, username, password (null if the user already existed),
 *               user_created, usergroup, group_created, search_filter, client_node, login_url.
 */
function api_garlo_client_portal_provision(string $client_name, int $collection_ref, string $username = '', string $fullname = ''): array
{
    global $lang, $baseurl;
    if (($g = garlo_cp_guard()) !== []) {
        return $g;
    }

    $client_name = trim($client_name);
    if ($client_name === '' || $collection_ref <= 0) {
        return garlo_cp_fail(400, 'client_name and collection_ref are required');
    }
    $collection = get_collection($collection_ref);
    if (!is_array($collection) || (int) $collection['type'] !== COLLECTION_TYPE_FEATURED) {
        return garlo_cp_fail(400, "Collection {$collection_ref} is not a featured collection");
    }

    try {
        [$group_ref, $group_created, $filter_ref, $node] = garlo_cp_client_group($client_name, $collection_ref);
    } catch (RuntimeException $e) {
        return garlo_cp_fail(500, $e->getMessage());
    }

    $username = trim($username) !== '' ? trim($username) : garlo_cp_username($client_name);
    $existing = ps_value('SELECT ref value FROM user WHERE username = ?', ['s', $username], 0);
    $password = null;
    $user_created = false;

    if ($existing > 0) {
        $user_ref = (int) $existing;
    } else {
        $user_ref = new_user($username, $group_ref);
        if (!is_numeric($user_ref) || (int) $user_ref <= 0) {
            return garlo_cp_fail(409, $user_ref === -2 ? $lang['userlimitreached'] : $lang['useralreadyexists']);
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
            return garlo_cp_fail(409, is_string($saved) ? $saved : 'save_user failed');
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

/**
 * API: garlo_client_portal_provision_person — a login for one of the client's people.
 *
 * @param string $client_name          Client name as in Twenty (Client field value).
 * @param int    $collection_ref       The client's root featured collection.
 * @param string $email                The person's email; also the username of a new login.
 * @param string $fullname             Display name.
 * @param string $person_key           Stable id of the person in Twenty (names their own group).
 * @param int    $all_projects         1 = sees everything the client sees (shared "Client - X" group).
 * @param string $project_collections  Comma-separated project collections the person sees (when not all).
 * @param int    $user_ref             The person's existing login, if Twenty knows it.
 *
 * @return array JSend; data has user_ref, username, password (null unless created), user_created,
 *               usergroup, group_name, project_nodes, hidden_collections.
 */
function api_garlo_client_portal_provision_person(
    string $client_name,
    int $collection_ref,
    string $email,
    string $fullname,
    string $person_key,
    int $all_projects = 0,
    string $project_collections = '',
    int $user_ref = 0
): array {
    global $lang;
    if (($g = garlo_cp_guard()) !== []) {
        return $g;
    }

    $client_name = trim($client_name);
    $email = strtolower(trim($email));
    $fullname = trim($fullname) !== '' ? trim($fullname) : $email;
    $key = substr(preg_replace('/[^A-Za-z0-9]/', '', $person_key), 0, 8);
    if ($client_name === '' || $collection_ref <= 0 || $key === '') {
        return garlo_cp_fail(400, 'client_name, collection_ref and person_key are required');
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return garlo_cp_fail(400, "'{$email}' is not a valid email address");
    }
    $root = get_collection($collection_ref);
    if (!is_array($root) || (int) $root['type'] !== COLLECTION_TYPE_FEATURED) {
        return garlo_cp_fail(400, "Collection {$collection_ref} is not a featured collection");
    }

    try {
        [$client_group, , , $client_node] = garlo_cp_client_group($client_name, $collection_ref);
    } catch (RuntimeException $e) {
        return garlo_cp_fail(500, $e->getMessage());
    }

    $project_nodes = [];
    $hidden = [];

    if ($all_projects) {
        $group_ref = $client_group;
        $group_name = 'Client - ' . $client_name;
    } else {
        // Projects must be direct children of the client's collection.
        $children = array_map('intval', ps_array('SELECT ref value FROM collection WHERE parent = ?', ['i', $collection_ref]));
        $wanted = array_values(array_unique(array_filter(array_map('intval', explode(',', $project_collections)))));
        $wanted = array_values(array_intersect($wanted, $children));
        if (count($wanted) === 0) {
            return garlo_cp_fail(400, 'No projects of this client were given; the person would see nothing');
        }
        $field = garlo_cp_project_field();
        foreach ($wanted as $c) {
            $n = garlo_cp_project_node($c, $field);
            if ($n > 0) {
                $project_nodes[] = $n;
            }
        }
        $hidden = array_values(array_diff($children, $wanted));

        $perms = garlo_cp_template_permissions();
        if ($perms === null) {
            return garlo_cp_fail(500, 'Template group not found');
        }
        $perms[] = 'j' . $collection_ref;
        foreach ($hidden as $c) {
            $perms[] = '-j' . $c;
        }

        $group_name = "Client - {$client_name} · {$fullname} [{$key}]";
        $existing_group = ps_query('SELECT ref, search_filter_id FROM usergroup WHERE name LIKE ?', ['s', '% [' . $key . ']']);
        $filter_name = "Client: {$client_name}, approved, projects of {$fullname}";
        if (count($existing_group) > 0) {
            $group_ref = (int) $existing_group[0]['ref'];
            $filter_ref = (int) $existing_group[0]['search_filter_id'];
            if ($filter_ref > 0) {
                save_filter($filter_ref, $filter_name, RS_FILTER_ALL);
                foreach (array_keys(get_filter_rules($filter_ref)) as $rule) {
                    delete_filter_rule($rule);
                }
            } else {
                $filter_ref = (int) save_filter(0, $filter_name, RS_FILTER_ALL);
            }
        } else {
            $group_ref = 0;
            $filter_ref = (int) save_filter(0, $filter_name, RS_FILTER_ALL);
        }
        save_filter_rule('new', $filter_ref, [[1, [$client_node]]]);
        save_filter_rule('new', $filter_ref, [[1, [garlo_cp_setting('garlo_cp_approved_node', 278)]]]);
        save_filter_rule('new', $filter_ref, [[1, $project_nodes]]);

        $group_data = ['name' => $group_name, 'permissions' => implode(',', $perms), 'search_filter_id' => $filter_ref];
        // save_usergroup returns true when it updates an existing group and the new ID when it creates one.
        if ($group_ref > 0) {
            save_usergroup($group_ref, $group_data);
        } else {
            $group_ref = (int) save_usergroup(0, $group_data);
        }
        if ($group_ref <= 0) {
            return garlo_cp_fail(500, "Could not create group {$group_name}");
        }
    }

    // The login: by ID, then by username / email. Only ever touch logins that are client logins.
    $user = $user_ref > 0 ? ps_query('SELECT ref, username, usergroup FROM user WHERE ref = ?', ['i', $user_ref]) : [];
    if (count($user) === 0) {
        $user = ps_query('SELECT ref, username, usergroup FROM user WHERE username = ? OR email = ?', ['s', $email, 's', $email]);
    }
    $password = null;
    $user_created = false;

    if (count($user) > 0) {
        $user_ref = (int) $user[0]['ref'];
        if (!garlo_cp_is_client_group((int) $user[0]['usergroup'])) {
            return garlo_cp_fail(409, "Login {$user[0]['username']} already exists and is not a client login — not touching it");
        }
        $saved = save_user($user_ref, ['fullname' => $fullname, 'email' => $email, 'usergroup' => $group_ref, 'approved' => 1]);
        $username = $user[0]['username'];
    } else {
        $new = new_user($email, $group_ref);
        if (!is_numeric($new) || (int) $new <= 0) {
            return garlo_cp_fail(409, $new === -2 ? $lang['userlimitreached'] : $lang['useralreadyexists']);
        }
        $user_ref = (int) $new;
        $username = $email;
        $password = make_password();
        $saved = save_user($user_ref, [
            'username'  => $email,
            'password'  => $password,
            'fullname'  => $fullname,
            'email'     => $email,
            'usergroup' => $group_ref,
            'approved'  => 1,
        ]);
        $user_created = true;
    }
    if ($saved !== true) {
        return garlo_cp_fail(409, is_string($saved) ? $saved : 'save_user failed');
    }

    clear_query_cache('usergroup');

    return ajax_response_ok([
        'user_ref'           => $user_ref,
        'username'           => $username,
        'password'           => $password,
        'user_created'       => $user_created,
        'usergroup'          => $group_ref,
        'group_name'         => $group_name,
        'project_nodes'      => $project_nodes,
        'hidden_collections' => $hidden,
    ]);
}

/**
 * API: garlo_client_portal_sync_project_tags — tag every file with the projects whose collections hold it.
 *
 * @param string $project_collections Comma-separated project collections (from Twenty). Files in a project
 *                                    collection or any collection below it get that project's tag; files that
 *                                    left get it removed. Tags of projects not listed are left alone.
 *
 * @return array JSend; data has projects, added, removed, field.
 */
function api_garlo_client_portal_sync_project_tags(string $project_collections): array
{
    if (($g = garlo_cp_guard()) !== []) {
        return $g;
    }
    $field = garlo_cp_project_field();
    if ($field <= 0) {
        return garlo_cp_fail(500, 'Could not find or create the Project field');
    }

    $desired = [];  // node => [resource => true]
    $projects = [];
    foreach (array_unique(array_filter(array_map('intval', explode(',', $project_collections)))) as $c) {
        $node = garlo_cp_project_node($c, $field);
        if ($node <= 0) {
            continue;
        }
        $desired[$node] = $desired[$node] ?? [];
        foreach (garlo_cp_collection_tree($c) as $sub) {
            foreach ((array) get_collection_resources($sub) as $r) {
                $desired[$node][(int) $r] = true;
            }
        }
        $projects[] = ['collection' => $c, 'node' => $node, 'files' => count($desired[$node])];
    }
    if (count($desired) === 0) {
        return ajax_response_ok(['projects' => [], 'added' => 0, 'removed' => 0, 'field' => $field]);
    }

    $current = [];
    $rows = ps_query(
        'SELECT resource, node FROM resource_node WHERE node IN (' . ps_param_insert(count($desired)) . ')',
        ps_param_fill(array_keys($desired), 'i')
    );
    foreach ($rows as $row) {
        $current[(int) $row['node']][(int) $row['resource']] = true;
    }

    $added = 0;
    $removed = 0;
    foreach ($desired as $node => $resources) {
        foreach (array_keys($resources) as $r) {
            if (!isset($current[$node][$r])) {
                add_resource_nodes($r, [$node], false, true);
                $added++;
            }
        }
        foreach (array_keys($current[$node] ?? []) as $r) {
            if (!isset($resources[$r])) {
                delete_resource_nodes($r, [$node], true);
                $removed++;
            }
        }
    }

    return ajax_response_ok(['projects' => $projects, 'added' => $added, 'removed' => $removed, 'field' => $field]);
}
