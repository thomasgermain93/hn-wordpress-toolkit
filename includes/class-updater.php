<?php
/**
 * GitHub-based automatic updater for Hungry Nuggets WordPress Toolkit.
 *
 * Hooks into the WordPress plugin update system to check the latest GitHub
 * release. When a new tag is published (e.g. v1.2.0) the plugin appears in
 * the standard WP update flow and can be updated via Dashboard or WP-CLI.
 *
 * @package HN_Image_Optimizer
 */

defined('ABSPATH') || exit;

/**
 * Class HN_Toolkit_Updater
 *
 * Compares the installed version against the latest GitHub release tag and
 * injects the result into WordPress's `update_plugins` transient.
 *
 * Usage:
 *   (new HN_Toolkit_Updater(__FILE__, HN_TOOLKIT_VERSION, 'thomasgermain93/hn-wordpress-toolkit'))->init();
 */
class HN_Toolkit_Updater {

    private $plugin_file;
    private $plugin_slug;
    private $version;
    private $github_repo;

    /** Transient key used to cache the GitHub API response for 12 hours. */
    const TRANSIENT_KEY = 'hn_toolkit_github_release';

    public function __construct( string $plugin_file, string $version, string $github_repo ) {
        $this->plugin_file = $plugin_file;
        $this->plugin_slug = plugin_basename($plugin_file);
        $this->version     = $version;
        $this->github_repo = $github_repo;
    }

    /**
     * Register WordPress hooks.
     * Call once during plugin init (admin only is fine).
     */
    public function init() {
        add_filter('pre_set_site_transient_update_plugins', [$this, 'inject_update']);
        add_filter('plugins_api', [$this, 'plugin_info'], 20, 3);
        add_action('upgrader_process_complete', [$this, 'flush_cache'], 10, 2);
    }

    /**
     * Inject update info into the WordPress transient if a newer release exists.
     *
     * @param  object $transient WordPress update_plugins transient.
     * @return object
     */
    public function inject_update( $transient ) {
        if (empty($transient->checked)) {
            return $transient;
        }

        $release = $this->get_latest_release();
        if (! $release) {
            return $transient;
        }

        $remote_version = ltrim($release->tag_name, 'v');

        if (version_compare($remote_version, $this->version, '>')) {
            $zip_url = $this->get_zip_url($release);
            if ($zip_url) {
                $transient->response[$this->plugin_slug] = (object) [
                    'id'           => "github.com/{$this->github_repo}",
                    'slug'         => dirname($this->plugin_slug),
                    'plugin'       => $this->plugin_slug,
                    'new_version'  => $remote_version,
                    'url'          => "https://github.com/{$this->github_repo}",
                    'package'      => $zip_url,
                    'icons'        => [],
                    'banners'      => [],
                    'tested'       => '',
                    'requires_php' => '7.3',
                    'compatibility'=> new stdClass(),
                ];
            }
        } else {
            $transient->no_update[$this->plugin_slug] = (object) [
                'id'          => "github.com/{$this->github_repo}",
                'slug'        => dirname($this->plugin_slug),
                'plugin'      => $this->plugin_slug,
                'new_version' => $this->version,
                'url'         => "https://github.com/{$this->github_repo}",
                'package'     => '',
            ];
        }

        return $transient;
    }

    /**
     * Provide plugin info when WordPress queries the plugins API.
     */
    public function plugin_info( $result, $action, $args ) {
        if ($action !== 'plugin_information' || (isset($args->slug) ? $args->slug : '') !== dirname($this->plugin_slug)) {
            return $result;
        }

        $release = $this->get_latest_release();
        if (! $release) {
            return $result;
        }

        return (object) [
            'name'          => 'Hungry Nuggets WordPress Toolkit',
            'slug'          => dirname($this->plugin_slug),
            'version'       => ltrim($release->tag_name, 'v'),
            'author'        => '<a href="https://hungrynuggets.com">Hungry Nuggets</a>',
            'homepage'      => "https://github.com/{$this->github_repo}",
            'download_link' => $this->get_zip_url($release),
            'sections'      => [
                'description' => isset($release->body) ? $release->body : '',
            ],
        ];
    }

    /**
     * Flush the cached release info after the plugin is updated.
     */
    public function flush_cache( $upgrader, $options ) {
        if (
            (isset($options['action']) ? $options['action'] : '') === 'update' &&
            (isset($options['type']) ? $options['type'] : '')     === 'plugin'
        ) {
            delete_transient(self::TRANSIENT_KEY);
        }
    }

    // ─── Private helpers ──────────────────────────────────────────────────

    /**
     * Fetch (or return cached) latest GitHub release object.
     *
     * @return object|null
     */
    private function get_latest_release() {
        $cached = get_transient(self::TRANSIENT_KEY);
        if ($cached !== false) {
            return $cached ?: null;
        }

        $api_url  = "https://api.github.com/repos/{$this->github_repo}/releases/latest";
        $response = wp_remote_get($api_url, [
            'headers' => [
                'Accept'     => 'application/vnd.github.v3+json',
                'User-Agent' => 'WordPress/' . get_bloginfo('version'),
            ],
            'timeout' => 10,
        ]);

        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
            set_transient(self::TRANSIENT_KEY, false, 30 * MINUTE_IN_SECONDS);
            return null;
        }

        $release = json_decode(wp_remote_retrieve_body($response));
        set_transient(self::TRANSIENT_KEY, $release, 12 * HOUR_IN_SECONDS);
        return $release;
    }

    /**
     * Return the browser_download_url of the first ZIP asset, or the
     * GitHub-generated zipball URL as fallback.
     *
     * @return string
     */
    private function get_zip_url( $release ) {
        foreach (isset($release->assets) ? $release->assets : [] as $asset) {
            if (substr($asset->name, -4) === '.zip') {
                return $asset->browser_download_url;
            }
        }
        return isset($release->zipball_url) ? $release->zipball_url : '';
    }
}
