<?php
/**
 * Plugin Name: CMDB Endpoints & Webhooks + Admin Snapshot
 * Description: Exposes /cmdb/v1/snapshot, pushes webhooks on changes, inventories plugins/themes (active+inactive), update/auto-update flags, and adds an Admin Snapshot page (Tools → CMDB Snapshot).
 * Added in 1.3.0 /user to allow calling from google scripts to retrieve the user list
 * Version: 1.3.0
 * Author: Steve O'Rourke
 */

if (!defined('ABSPATH')) exit;

// In wp-config.php per-site, define:
//   define('CMDB_WEBHOOK_URL', 'https://script.google.com/macros/s/XXXXX/exec?site=' . rawurlencode(site_url()));
//   define('CMDB_SHARED_SECRET', 'your-strong-secret');
// Optional: define('CMDB_INCLUDE_SERVICES', 'stripe,bloomreach');

/** Utilities **/
function cmdb__require_wp_admin_files() {
    if (!function_exists('get_plugins'))        require_once ABSPATH . 'wp-admin/includes/plugin.php';
    if (!function_exists('get_mu_plugins'))     require_once ABSPATH . 'wp-admin/includes/plugin.php';
    if (!function_exists('get_dropins'))        require_once ABSPATH . 'wp-admin/includes/plugin.php';
    if (!function_exists('wp_update_plugins'))  require_once ABSPATH . 'wp-admin/includes/update.php';
    if (!function_exists('wp_update_themes'))   require_once ABSPATH . 'wp-admin/includes/update.php';
    if (!function_exists('wp_get_themes'))      require_once ABSPATH . 'wp-includes/theme.php';
}

/** Collect plugins (active+inactive+mu+dropins) with update/auto-update flags **/
function cmdb_collect_plugins_all() {
    cmdb__require_wp_admin_files();
    @wp_update_plugins();

    $active_plugins          = (array) get_option('active_plugins', []);
    $active_plugins_sitewide = is_multisite() ? array_keys((array) get_site_option('active_sitewide_plugins', [])) : [];

    $auto_update_plugins = is_multisite()
        ? (array) get_site_option('auto_update_plugins', [])
        : (array) get_option('auto_update_plugins', []);

    $all_plugins  = get_plugins();
    $mu_plugins   = function_exists('get_mu_plugins') ? get_mu_plugins() : [];
    $dropins      = function_exists('get_dropins') ? get_dropins() : [];

    $updates = get_site_transient('update_plugins');
    $updates_resp = is_object($updates) && isset($updates->response) ? (array) $updates->response : [];

    $plugins_all = [];

    // Regular plugins
    foreach ($all_plugins as $file => $p) {
        $is_active = in_array($file, $active_plugins, true) || in_array($file, $active_plugins_sitewide, true);
        $update_obj = isset($updates_resp[$file]) ? $updates_resp[$file] : null;
        $update_available = !empty($update_obj);
        $new_version = '';
        if ($update_available) {
            if (is_object($update_obj) && isset($update_obj->new_version)) $new_version = $update_obj->new_version;
            if (is_array($update_obj) && isset($update_obj['new_version'])) $new_version = $update_obj['new_version'];
        }
        $auto_update = in_array($file, $auto_update_plugins, true);

        $plugins_all[] = [
            'file'                 => $file,
            'name'                 => $p['Name'] ?? basename($file),
            'version'              => $p['Version'] ?? '',
            'plugin_uri'           => $p['PluginURI'] ?? '',
            'author'               => $p['Author'] ?? '',
            'active'               => $is_active,
            'network_active'       => in_array($file, $active_plugins_sitewide, true),
            'type'                 => 'regular',
            'update_available'     => $update_available,
            'new_version'          => $new_version,
            'auto_update_enabled'  => $auto_update,
            'needs_attention'      => $update_available && !$auto_update,
        ];
    }

    // MU plugins
    foreach ($mu_plugins as $file => $p) {
        $plugins_all[] = [
            'file'                 => "mu-plugins/$file",
            'name'                 => $p['Name'] ?? $file,
            'version'              => $p['Version'] ?? '',
            'plugin_uri'           => $p['PluginURI'] ?? '',
            'author'               => $p['Author'] ?? '',
            'active'               => true,
            'network_active'       => is_multisite(),
            'type'                 => 'mu',
            'update_available'     => false,
            'new_version'          => '',
            'auto_update_enabled'  => false,
            'needs_attention'      => false,
        ];
    }

    // Drop-ins
    foreach ($dropins as $file => $p) {
        $plugins_all[] = [
            'file'                 => $file,
            'name'                 => $p['Name'] ?? $file,
            'version'              => $p['Version'] ?? '',
            'plugin_uri'           => $p['PluginURI'] ?? '',
            'author'               => $p['Author'] ?? '',
            'active'               => true,
            'network_active'       => is_multisite(),
            'type'                 => 'dropin',
            'update_available'     => false,
            'new_version'          => '',
            'auto_update_enabled'  => false,
            'needs_attention'      => false,
        ];
    }

    return $plugins_all;
}

/** Collect themes (active+inactive) with update/auto-update flags **/
function cmdb_collect_themes_all() {
    cmdb__require_wp_admin_files();
    @wp_update_themes();

    $themes = wp_get_themes();
    $active = wp_get_theme();
    $active_id = $active->get_stylesheet();

    $auto_update_themes = is_multisite()
        ? (array) get_site_option('auto_update_themes', [])
        : (array) get_option('auto_update_themes', []);

    $updates = get_site_transient('update_themes');
    $updates_resp = (is_object($updates) && isset($updates->response)) ? (array) $updates->response : [];

    $themes_all = [];
    foreach ($themes as $stylesheet => $theme) {
        $upd = isset($updates_resp[$stylesheet]) ? $updates_resp[$stylesheet] : null;
        $update_available = !empty($upd);
        $new_version = '';
        if ($update_available) {
            if (is_array($upd) && isset($upd['new_version'])) $new_version = $upd['new_version'];
            if (is_object($upd) && isset($upd->new_version))   $new_version = $upd->new_version;
        }
        $auto_update = in_array($stylesheet, $auto_update_themes, true);

        $themes_all[] = [
            'stylesheet'           => $stylesheet,
            'template'             => $theme->get_template(),
            'name'                 => $theme->get('Name'),
            'version'              => $theme->get('Version'),
            'status'               => ($stylesheet === $active_id) ? 'active' : 'inactive',
            'parent'               => $theme->parent() ? $theme->parent()->get_stylesheet() : '',
            'update_available'     => $update_available,
            'new_version'          => $new_version,
            'auto_update_enabled'  => $auto_update,
            'needs_attention'      => $update_available && !$auto_update,
        ];
    }

    return [$themes_all, $active];
}

/** REST endpoint: /wp-json/cmdb/v1/snapshot **/
add_action('rest_api_init', function () {
    register_rest_route('cmdb/v1', '/snapshot', [
        'methods'  => 'GET',
        'permission_callback' => function() {
            // Expect external Basic Auth (Application Passwords) at the server layer.
            return true;
        },
        'callback' => 'cmdb_build_snapshot',
    ]);
});

function cmdb_build_snapshot() {
    cmdb__require_wp_admin_files();

    $plugins_all = cmdb_collect_plugins_all();
    list($themes_all, $active_theme) = cmdb_collect_themes_all();

    $plugins_active = array_values(array_filter($plugins_all, function($pl){ return !empty($pl['active']); }));

    // Core update + auto-update
    @wp_version_check();
    $core_updates = get_site_transient('update_core');
    $core_update_available = false;
    $core_new_version = '';
    if (!empty($core_updates->updates) && is_array($core_updates->updates)) {
        foreach ($core_updates->updates as $update) {
            if (isset($update->response) && $update->response === 'upgrade' && isset($update->current)) {
                $core_update_available = true;
                $core_new_version = $update->current;
                break;
            }
        }
    }
    $core_auto_update_enabled = defined('WP_AUTO_UPDATE_CORE') ? WP_AUTO_UPDATE_CORE : 'default';

    $payload = [
        'site' => [
            'name'     => get_bloginfo('name'),
            'site_url' => site_url(),
            'home_url' => home_url(),
            'locale'   => get_locale(),
            'timezone' => get_option('timezone_string') ?: 'UTC',
        ],
        'environment' => [
            'wordpress_version' => get_bloginfo('version'),
            'php_version'        => PHP_VERSION,
            'server'             => $_SERVER['SERVER_SOFTWARE'] ?? '',
            'db_version'         => get_option('db_version'),
            'multisite'          => is_multisite(),
        ],
        'core' => [
            'version'             => get_bloginfo('version'),
            'update_available'    => $core_update_available,
            'new_version'         => $core_new_version,
            'auto_update_enabled' => $core_auto_update_enabled,
            'needs_attention'     => $core_update_available && !$core_auto_update_enabled,
        ],
        'theme'        => [
            'name'       => $active_theme->get('Name'),
            'version'    => $active_theme->get('Version'),
            'stylesheet' => $active_theme->get_stylesheet(),
            'template'   => $active_theme->get_template(),
        ],
        'themes_all'   => $themes_all,
        'plugins'      => $plugins_active,   // convenience
        'plugins_all'  => $plugins_all,
        'plugins_hash' => hash('sha256', json_encode(
            array_map(function($p){ return $p['file'].':'.$p['version'].':'.($p['active']?'1':'0'); }, $plugins_all)
        )),
        'counters'     => [
            'plugins_total'           => count($plugins_all),
            'plugins_active'          => count($plugins_active),
            'plugins_updates'         => count(array_filter($plugins_all, function($p){ return !empty($p['update_available']); })),
            'plugins_attention'       => count(array_filter($plugins_all, function($p){ return !empty($p['needs_attention']); })),
            'themes_total'            => count($themes_all),
            'themes_updates'          => count(array_filter($themes_all, function($t){ return !empty($t['update_available']); })),
            'themes_attention'        => count(array_filter($themes_all, function($t){ return !empty($t['needs_attention']); })),
        ],
        'declared_services' => array_filter(array_map('trim', explode(',', defined('CMDB_INCLUDE_SERVICES') ? CMDB_INCLUDE_SERVICES : ''))),
        'timestamp'    => current_time('mysql'),
    ];

    return rest_ensure_response($payload);
}

/** REST endpoint: /wp-json/cmdb/v1/users */
add_action('rest_api_init', function () {
    register_rest_route('cmdb/v1', '/users', [
        'methods'             => 'GET',
        'permission_callback' => '__return_true', // we gate with the shared-secret check below
        'callback'            => 'cmdb_build_users_payload',
    ]);
});

/** Shared-secret check (matches your webhook model) */
function cmdb_users_auth_ok( \WP_REST_Request $req ) {
    $provided = $req->get_param('key') ?: $req->get_header('x-cmdb-key');
    $expected = defined('CMDB_SHARED_SECRET') ? CMDB_SHARED_SECRET : '';
    return $expected && hash_equals( (string) $expected, (string) $provided );
}

/** Build users payload */
function cmdb_build_users_payload( \WP_REST_Request $req ) {
    if ( ! cmdb_users_auth_ok( $req ) ) {
        return new \WP_REST_Response( [ 'error' => 'unauthorized' ], 403 );
    }

    // Collect users (safe fields only)
    $args = [
        'blog_id' => get_current_blog_id(),
        'fields'  => [ 'ID', 'user_login', 'user_email', 'display_name', 'user_registered' ],
        'number'  => -1,
    ];
    $wp_users = get_users( $args );

    // Common last-login meta keys used by popular plugins (best-effort)
    $last_login_keys = [ 'last_login', 'wp_last_login', 'simple_history_last_seen', 'wc_last_active' ];

    $users_out = [];
    foreach ( $wp_users as $u ) {
        $ud    = get_userdata( $u->ID );
        $roles = $ud && ! empty( $ud->roles ) ? array_values( (array) $ud->roles ) : [];

        $first = get_user_meta( $u->ID, 'first_name', true );
        $last  = get_user_meta( $u->ID, 'last_name', true );

        $last_login = '';
        foreach ( $last_login_keys as $k ) {
            $v = get_user_meta( $u->ID, $k, true );
            if ( ! empty( $v ) ) {
                $last_login = is_numeric( $v ) ? gmdate( 'Y-m-d H:i:s', (int) $v ) : (string) $v;
                break;
            }
        }

        $users_out[] = [
            'id'              => (int) $u->ID,
            'user_login'      => $u->user_login,
            'user_email'      => $u->user_email,      // mask or drop in Apps Script/LS if needed
            'display_name'    => $u->display_name,
            'first_name'      => $first,
            'last_name'       => $last,
            'roles'           => $roles,
            'user_registered' => $u->user_registered,
            'last_login'      => $last_login,
        ];
    }

    $payload = [
        'site' => [
            'name'     => get_bloginfo( 'name' ),
            'site_url' => site_url(),
        ],
        'users'     => $users_out,
        'timestamp' => current_time( 'mysql' ),
    ];

    return rest_ensure_response( $payload );
}


/** Webhook push **/
function cmdb_send_webhook($event_type, $details = []) {
    if (!defined('CMDB_WEBHOOK_URL') || !CMDB_WEBHOOK_URL) return;
    if (!defined('CMDB_SHARED_SECRET') || !CMDB_SHARED_SECRET) return;

    $snapshot = cmdb_build_snapshot();
    $payload_data = $snapshot instanceof WP_REST_Response ? $snapshot->get_data() : $snapshot;
    $body = [
        'event'    => $event_type,
        'occurred' => current_time('mysql'),
        'snapshot' => $payload_data,
        'details'  => $details,
    ];
    $json = wp_json_encode($body);

    $sig  = 'sha256=' . hash_hmac('sha256', $json, CMDB_SHARED_SECRET);
    $url = CMDB_WEBHOOK_URL;
    $sep = (strpos($url, '?') === false) ? '?' : '&';
    $url .= $sep . 'site=' . rawurlencode(site_url()) . '&sig=' . rawurlencode($sig);

    wp_remote_post($url, [
        'timeout' => 10,
        'headers' => [ 'Content-Type' => 'application/json' ],
        'body'    => $json,
    ]);
}

add_action('activated_plugin',   function($plugin){ cmdb_send_webhook('plugin_activated',   ['plugin'=>$plugin]); }, 10, 1);
add_action('deactivated_plugin', function($plugin){ cmdb_send_webhook('plugin_deactivated', ['plugin'=>$plugin]); }, 10, 1);
add_action('upgrader_process_complete', function($upgrader_obj, $options){
    $type = $options['type'] ?? 'unknown';
    $action = $options['action'] ?? 'unknown';
    cmdb_send_webhook('upgrader_process_complete', ['type'=>$type, 'action'=>$action]);
}, 10, 2);
add_action('switch_theme', function($new_name, $new_theme){ cmdb_send_webhook('theme_switched', ['new_name'=>$new_name]); }, 10, 2);
add_action('_core_updated_successfully', function($wp_version){ cmdb_send_webhook('core_updated', ['version'=>$wp_version]); }, 10, 1);

/** Admin page: Tools → CMDB Snapshot **/
add_action('admin_menu', function(){
    add_management_page('CMDB Snapshot', 'CMDB Snapshot', 'manage_options', 'cmdb-snapshot', 'cmdb_admin_snapshot_page');
});

function cmdb_admin_snapshot_page() {
    if (!current_user_can('manage_options')) return;
    $response = cmdb_build_snapshot();
    $data = $response instanceof WP_REST_Response ? $response->get_data() : $response;
    ?>
    <div class="wrap">
        <h1>CMDB Snapshot</h1>
        <p><em>Site:</em> <?php echo esc_html($data['site']['site_url']); ?> | <em>Generated:</em> <?php echo esc_html($data['timestamp']); ?></p>

        <style>
            .cmdb-box{background:#fff;border:1px solid #ddd;border-radius:8px;padding:16px;margin:16px 0;}
            .cmdb-badge{display:inline-block;padding:2px 8px;border-radius:999px;border:1px solid #ccc;margin-right:8px;}
            .cmdb-badge.red{background:#ffeaea;border-color:#ffb3b3;}
            .cmdb-badge.green{background:#eaffea;border-color:#b3ffb3;}
            .cmdb-table{width:100%;border-collapse:collapse;margin-top:10px;}
            .cmdb-table th,.cmdb-table td{border:1px solid #e5e5e5;padding:8px;text-align:left;}
            .cmdb-table th{background:#f8f8f8;}
            details summary{cursor:pointer;font-weight:600;}
            code{background:#f3f3f3;padding:2px 4px;border-radius:4px;}
        </style>

        <div class="cmdb-box">
            <h2>Core Status</h2>
            <?php $core = $data['core']; ?>
            <p>
                Version: <strong><?php echo esc_html($core['version']); ?></strong>
                <?php if (!empty($core['update_available'])): ?>
                    <span class="cmdb-badge red">Update available → <?php echo esc_html($core['new_version']); ?></span>
                <?php else: ?>
                    <span class="cmdb-badge green">Up to date</span>
                <?php endif; ?>
                Auto-update: <code><?php echo esc_html(is_bool($core['auto_update_enabled']) ? ($core['auto_update_enabled']?'true':'false') : (string)$core['auto_update_enabled']); ?></code>
            </p>
        </div>

        <div class="cmdb-box">
            <h2>Theme</h2>
            <p>Active theme: <strong><?php echo esc_html($data['theme']['name']); ?></strong> (<?php echo esc_html($data['theme']['version']); ?>)</p>
            <details>
                <summary>All themes (<?php echo count($data['themes_all']); ?>)</summary>
                <table class="cmdb-table">
                    <thead><tr><th>Status</th><th>Stylesheet</th><th>Name</th><th>Version</th><th>Parent</th><th>Update</th><th>Auto‑Update</th></tr></thead>
                    <tbody>
                    <?php foreach ($data['themes_all'] as $t): ?>
                        <tr>
                            <td><?php echo esc_html($t['status']); ?></td>
                            <td><code><?php echo esc_html($t['stylesheet']); ?></code></td>
                            <td><?php echo esc_html($t['name']); ?></td>
                            <td><?php echo esc_html($t['version']); ?></td>
                            <td><?php echo esc_html($t['parent']); ?></td>
                            <td><?php echo !empty($t['update_available']) ? '→ '.$t['new_version'] : '—'; ?></td>
                            <td><?php echo !empty($t['auto_update_enabled']) ? 'Enabled' : 'Disabled'; ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </details>
        </div>

        <div class="cmdb-box">
            <h2>Plugins</h2>
            <p>Total: <?php echo intval($data['counters']['plugins_total']); ?> |
               Active: <?php echo intval($data['counters']['plugins_active']); ?> |
               Updates: <?php echo intval($data['counters']['plugins_updates']); ?> |
               Needs attention: <?php echo intval($data['counters']['plugins_attention']); ?></p>

            <details open>
                <summary>Plugins needing attention (update available & auto‑update disabled)</summary>
                <table class="cmdb-table">
                    <thead><tr><th>File</th><th>Name</th><th>Installed</th><th>New</th><th>Active</th><th>Type</th></tr></thead>
                    <tbody>
                    <?php foreach ($data['plugins_all'] as $p): if (empty($p['needs_attention'])) continue; ?>
                        <tr>
                            <td><code><?php echo esc_html($p['file']); ?></code></td>
                            <td><?php echo esc_html($p['name']); ?></td>
                            <td><?php echo esc_html($p['version']); ?></td>
                            <td><?php echo esc_html($p['new_version']); ?></td>
                            <td><?php echo !empty($p['active']) ? 'Yes' : 'No'; ?></td>
                            <td><?php echo esc_html($p['type']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </details>

            <details>
                <summary>All plugins (<?php echo count($data['plugins_all']); ?>)</summary>
                <table class="cmdb-table">
                    <thead><tr><th>File</th><th>Name</th><th>Version</th><th>Type</th><th>Active</th><th>Network Active</th><th>Update</th><th>Auto‑Update</th></tr></thead>
                    <tbody>
                    <?php foreach ($data['plugins_all'] as $p): ?>
                        <tr>
                            <td><code><?php echo esc_html($p['file']); ?></code></td>
                            <td><?php echo esc_html($p['name']); ?></td>
                            <td><?php echo esc_html($p['version']); ?></td>
                            <td><?php echo esc_html($p['type']); ?></td>
                            <td><?php echo !empty($p['active']) ? 'Yes' : 'No'; ?></td>
                            <td><?php echo !empty($p['network_active']) ? 'Yes' : 'No'; ?></td>
                            <td><?php echo !empty($p['update_available']) ? '→ '.$p['new_version'] : '—'; ?></td>
                            <td><?php echo !empty($p['auto_update_enabled']) ? 'Enabled' : 'Disabled'; ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </details>
        </div>

        <div class="cmdb-box">
            <details>
                <summary>Raw JSON snapshot</summary>
                <textarea style="width:100%;height:300px;"><?php echo esc_textarea(wp_json_encode($data, JSON_PRETTY_PRINT)); ?></textarea>
            </details>
        </div>
    </div>
    <?php
}
