<?php
/**
 * GitHub-based plugin auto-updater.
 *
 * Checks the GitHub Releases API for new versions and hooks into
 * WordPress's native update system. Clients see update notices
 * in wp-admin and can one-click update.
 *
 * Workflow:
 * 1. Push plugin code to a GitHub repo.
 * 2. Set the repo path in General settings (e.g. "MeshulashDigital/meshulash-marketing").
 * 3. When releasing: create a GitHub Release, tag = version (e.g. "1.2.0"),
 *    and attach the built meshulash-marketing.zip as a release asset.
 * 4. All client sites will see the update within 12 hours (or force-check via Dashboard → Updates).
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Meshulash_Updater {

    private $plugin_slug = 'meshulash-marketing/meshulash-marketing.php';
    private $github_repo; // e.g. "MeshulashDigital/meshulash-marketing"
    private $github_token;
    private $cache_key = 'meshulash_github_update';
    private $cache_ttl = 43200; // 12 hours

    public function __construct() {
        $this->github_repo  = Meshulash_Settings::get( 'github_repo' );
        $this->github_token = Meshulash_Settings::get( 'github_token' );

        // Don't register hooks if no repo is configured
        if ( empty( $this->github_repo ) ) {
            return;
        }

        add_filter( 'pre_set_site_transient_update_plugins', [ $this, 'check_for_update' ] );
        add_filter( 'plugins_api', [ $this, 'plugin_info' ], 10, 3 );
        add_action( 'upgrader_process_complete', [ $this, 'clear_cache' ], 10, 2 );

        // Allow GitHub ZIPs (which may have a different folder name) to be renamed correctly
        add_filter( 'upgrader_post_install', [ $this, 'fix_directory_name' ], 10, 3 );
    }

    /**
     * Fetch latest release info from GitHub (cached).
     */
    private function get_remote_info() {
        $cached = get_transient( $this->cache_key );
        if ( false !== $cached ) {
            return $cached;
        }

        $url = 'https://api.github.com/repos/' . $this->github_repo . '/releases/latest';

        $args = [
            'timeout' => 15,
            'headers' => [
                'Accept'     => 'application/vnd.github.v3+json',
                'User-Agent' => 'Meshulash-Updater/' . MESHULASH_VERSION,
            ],
        ];

        if ( ! empty( $this->github_token ) ) {
            $args['headers']['Authorization'] = 'token ' . $this->github_token;
        }

        $response = wp_remote_get( $url, $args );

        if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) !== 200 ) {
            // Cache the failure briefly to avoid hammering the API
            set_transient( $this->cache_key, [ 'version' => '0.0.0' ], 900 );
            return false;
        }

        $release = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( ! is_array( $release ) || empty( $release['tag_name'] ) ) {
            return false;
        }

        // Strip leading "v" from tag if present (e.g. "v1.2.0" → "1.2.0")
        $version = ltrim( $release['tag_name'], 'vV' );

        // Find the ZIP asset (attached .zip file, not the auto-generated source archive)
        $download_url = '';
        if ( ! empty( $release['assets'] ) && is_array( $release['assets'] ) ) {
            foreach ( $release['assets'] as $asset ) {
                if ( substr( $asset['name'], -4 ) === '.zip' ) {
                    // Use the browser_download_url for public repos,
                    // or the API URL with token for private repos
                    if ( ! empty( $this->github_token ) ) {
                        $download_url = $asset['url']; // API URL, needs Accept header
                    } else {
                        $download_url = $asset['browser_download_url'];
                    }
                    break;
                }
            }
        }

        // Fallback to the auto-generated source ZIP if no asset was attached
        if ( empty( $download_url ) && ! empty( $release['zipball_url'] ) ) {
            $download_url = $release['zipball_url'];
        }

        if ( empty( $download_url ) ) {
            return false;
        }

        $info = [
            'version'      => $version,
            'download_url' => $download_url,
            'changelog'    => isset( $release['body'] ) ? $release['body'] : '',
            'published'    => isset( $release['published_at'] ) ? $release['published_at'] : '',
        ];

        set_transient( $this->cache_key, $info, $this->cache_ttl );
        return $info;
    }

    /**
     * Inject update info into WordPress's update_plugins transient.
     */
    public function check_for_update( $transient ) {
        if ( empty( $transient->checked ) ) {
            return $transient;
        }

        $remote = $this->get_remote_info();
        if ( ! $remote || empty( $remote['version'] ) || $remote['version'] === '0.0.0' ) {
            return $transient;
        }

        if ( version_compare( $remote['version'], MESHULASH_VERSION, '>' ) ) {
            $obj              = new stdClass();
            $obj->slug        = 'meshulash-marketing';
            $obj->plugin      = $this->plugin_slug;
            $obj->new_version = $remote['version'];
            $obj->url         = 'https://github.com/' . $this->github_repo;
            $obj->package     = $remote['download_url'];
            $obj->tested      = '';
            $obj->requires    = '5.8';
            $obj->requires_php = '7.4';

            $transient->response[ $this->plugin_slug ] = $obj;
        }

        return $transient;
    }

    /**
     * Show plugin details in the "View details" popup.
     */
    public function plugin_info( $result, $action, $args ) {
        if ( 'plugin_information' !== $action || 'meshulash-marketing' !== $args->slug ) {
            return $result;
        }

        $remote = $this->get_remote_info();
        if ( ! $remote ) {
            return $result;
        }

        $info                = new stdClass();
        $info->name          = 'Meshulash Marketing — WooCommerce Tracking & Conversions';
        $info->slug          = 'meshulash-marketing';
        $info->version       = $remote['version'];
        $info->author        = '<a href="https://meshulashdigital.com">Meshulash Digital</a>';
        $info->homepage      = 'https://meshulashdigital.com';
        $info->download_link = $remote['download_url'];
        $info->requires      = '5.8';
        $info->requires_php  = '7.4';

        // Convert GitHub markdown to basic HTML for the changelog
        $changelog = isset( $remote['changelog'] ) ? $remote['changelog'] : '';
        $changelog = nl2br( esc_html( $changelog ) );

        $info->sections = [
            'changelog' => $changelog ? $changelog : '<p>See GitHub releases for details.</p>',
        ];

        return $info;
    }

    /**
     * After extraction, rename the directory to "meshulash-marketing"
     * in case GitHub's ZIP has a different folder name (e.g. "repo-name-1.2.0").
     */
    public function fix_directory_name( $response, $hook_extra, $result ) {
        global $wp_filesystem;

        // Only act on our plugin
        if ( ! isset( $hook_extra['plugin'] ) || $hook_extra['plugin'] !== $this->plugin_slug ) {
            return $response;
        }

        $correct_dir = WP_PLUGIN_DIR . '/meshulash-marketing/';
        $installed_dir = $result['destination'];

        // If already correct, nothing to do
        if ( rtrim( $installed_dir, '/' ) === rtrim( $correct_dir, '/' ) ) {
            return $response;
        }

        // Rename the extracted directory
        $wp_filesystem->move( $installed_dir, $correct_dir );
        $response['destination'] = $correct_dir;

        // Re-activate if it was active
        if ( is_plugin_active( $this->plugin_slug ) ) {
            activate_plugin( $this->plugin_slug );
        }

        return $response;
    }

    /**
     * For private repos: filter the download request to add the auth token.
     */
    public function __get_download_args( $args ) {
        if ( ! empty( $this->github_token ) ) {
            $args['headers']['Authorization'] = 'token ' . $this->github_token;
            $args['headers']['Accept'] = 'application/octet-stream';
        }
        return $args;
    }

    /**
     * Clear cached release data after any plugin update.
     */
    public function clear_cache( $upgrader, $options ) {
        if ( 'update' === $options['action'] && 'plugin' === $options['type'] ) {
            delete_transient( $this->cache_key );
        }
    }
}
