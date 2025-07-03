<?php
/*
Plugin Name: Topic RSS Fetcher
Description: Custom RSS feed display for any topic or subject
Version: 4.4
Author: Topic RSS Team
*/

// Prevent direct file access
if (!defined('ABSPATH')) {
    exit;
}

// Ensure required WordPress RSS functions are available
if (!function_exists('fetch_feed')) {
    require_once(ABSPATH . WPINC . '/feed.php');
}

class TopicRSSFetcher {
    // Option keys for storing configuration
    private static $FEEDS_KEY = 'topic_rss_feeds';
    private static $SETTINGS_KEY = 'topic_rss_settings';

    /**
     * Initialize plugin hooks
     */
    public static function init() {
        add_action('admin_menu', [__CLASS__, 'add_admin_menu']);
        add_shortcode('topic_rss', [__CLASS__, 'rss_shortcode']);
        add_action('wp_head', [__CLASS__, 'add_css']);
    }

    /**
     * Get default feeds
     */
    public static function get_default_feeds() {
        $saved_feeds = get_option(self::$FEEDS_KEY);
        
        // If no saved feeds, return empty array (user will configure)
        if (empty($saved_feeds)) {
            $saved_feeds = [];
        }
        
        return $saved_feeds;
    }

    /**
     * Extract featured image from RSS item
     */
    private static function extract_featured_image($item, $source_name = '') {
        $image = '';

        // Try enclosure
        $enclosure = $item->get_enclosure();
        if ($enclosure && $enclosure->get_link()) {
            $image = esc_url($enclosure->get_link());
        }

        // Try media content
        if (empty($image)) {
            $media = $item->get_item_tags('http://search.yahoo.com/mrss/', 'content');
            if (!empty($media[0]['attribs']['']['url'])) {
                $image = esc_url($media[0]['attribs']['']['url']);
            }
        }

        // Try media thumbnail
        if (empty($image)) {
            $media = $item->get_item_tags('http://search.yahoo.com/mrss/', 'thumbnail');
            if (!empty($media[0]['attribs']['']['url'])) {
                $image = esc_url($media[0]['attribs']['']['url']);
            }
        }

        // Try content:encoded images (WordPress/NESN feeds)
        if (empty($image)) {
            $content = $item->get_item_tags('http://purl.org/rss/1.0/modules/content/', 'encoded');
            if (!empty($content[0]['data'])) {
                // First try to find JSON-LD thumbnailUrl
                if (preg_match('/\"thumbnailUrl\"\s*:\s*\"([^\"]+)\"/', $content[0]['data'], $matches)) {
                    $image = esc_url($matches[1]);
                } else {
                    // Fallback to img tags
                    $pattern = '/<img[^>]+src=[\'"]([^\'"]+)[\'"][^>]*>/i';
                    if (preg_match($pattern, $content[0]['data'], $matches)) {
                        $image = esc_url($matches[1]);
                    }
                }
            }
        }
        
        // Try WordPress post thumbnail meta
        if (empty($image)) {
            $thumbnail = $item->get_item_tags('', 'post-thumbnail');
            if (!empty($thumbnail[0]['data'])) {
                $image = esc_url($thumbnail[0]['data']);
            }
        }
        
        // Try WordPress featured image URL
        if (empty($image)) {
            $featured = $item->get_item_tags('', 'featuredImage');
            if (!empty($featured[0]['data'])) {
                $image = esc_url($featured[0]['data']);
            }
        }

        // Try description image
        if (empty($image)) {
            $description = $item->get_description();
            $pattern = '/<img[^>]+src=[\'"]([^\'"]+)[\'"][^>]*>/i';
            if (preg_match($pattern, $description, $matches)) {
                $image = esc_url($matches[1]);
            }
        }

        // Last resort: Try common WordPress meta fields
        if (empty($image)) {
            // Try _thumbnail_id or similar WordPress meta
            $meta_fields = ['_thumbnail_id', 'featured_image', 'post_image', 'image_url'];
            foreach ($meta_fields as $field) {
                $meta = $item->get_item_tags('', $field);
                if (!empty($meta[0]['data'])) {
                    $potential_image = $meta[0]['data'];
                    // If it's an ID, skip; if it's a URL, use it
                    if (filter_var($potential_image, FILTER_VALIDATE_URL)) {
                        $image = esc_url($potential_image);
                        break;
                    }
                }
            }
        }
        
        // If no image found, check for fallback options based on source
        if (empty($image)) {
            $settings = get_option(self::$SETTINGS_KEY, []);
            $use_fallback = isset($settings['use_fallback_images']) ? $settings['use_fallback_images'] : true;
            
            if ($use_fallback && stripos($source_name, 'NESN') !== false) {
                // Use NESN logo as fallback
                $image = 'https://s47719.pcdn.co/wp-content/plugins/arsenal-images/dist/svg/nesn-editorial.svg';
            }
        }

        return $image;
    }

    /**
     * Filter out photo credits from description
     */
    private static function filter_description($description, $source) {
        // Get custom filter patterns from settings
        $settings = get_option(self::$SETTINGS_KEY, []);
        $custom_patterns = isset($settings['custom_filters']) ? explode("\n", $settings['custom_filters']) : [];
        
        // General filters for all sources + custom patterns
        $patterns = [
            '/Photo by[^\.]+\./i',
            '/Photo: [^\.]+\./i',
            '/Credit: [^\.]+\./i',
            '/Photo credit: [^\.]+\./i',
            '/via Getty Images[^\.]*\./i',
            '/AP Photo[^\.]*\./i'
        ];
        
        // Add custom patterns
        foreach ($custom_patterns as $custom_pattern) {
            if (!empty(trim($custom_pattern))) {
                $patterns[] = '/' . preg_quote(trim($custom_pattern), '/') . '/i';
            }
        }
        
        foreach ($patterns as $pattern) {
            $description = preg_replace($pattern, '', $description);
        }
        
        // Clean up any double spaces left behind
        $description = preg_replace('/\s{2,}/', ' ', $description);
        
        return trim($description);
    }

    /**
     * Fetch RSS feeds
     */
    public static function fetch_rss_feeds($max_items = 30) {
        $feeds = array_keys(self::get_default_feeds());
        $all_items = [];
        
        // Debug: Check if feeds are configured
        if (empty($feeds)) {
            return [];
        }
        // Get settings for blocked titles
        $settings = get_option(self::$SETTINGS_KEY, []);
        $blocked_titles = isset($settings['blocked_titles']) ? array_filter(array_map('trim', explode("\n", $settings['blocked_titles']))) : [];

        foreach ($feeds as $feed_url) {
            $rss = fetch_feed($feed_url);
            $source_name = self::get_source_name($feed_url);

            if (!is_wp_error($rss)) {
                $rss_items = $rss->get_items(0, $max_items);

                foreach ($rss_items as $item) {
                    $title = $item->get_title();
                    $description = $item->get_description();
                    
                    // Skip items with specific blocked titles
                    if (in_array(trim($title), $blocked_titles)) {
                        continue;
                    }
                    
                    // Skip content based on settings
                    $settings = get_option(self::$SETTINGS_KEY, []);
                    $skip_short_content = isset($settings['skip_short_content']) ? $settings['skip_short_content'] : false;
                    
                    if ($skip_short_content && strlen(strip_tags($description)) < 100) {
                        continue;
                    }
                    
                    // Clean the description
                    $clean_description = self::filter_description($description, $source_name);
                    
                    $all_items[] = [
                        'title' => esc_html($title),
                        'link' => esc_url($item->get_permalink()),
                        'description' => wp_trim_words($clean_description, 30),
                        'date' => $item->get_date('Y-m-d H:i:s'),
                        'source' => $source_name,
                        'image' => self::extract_featured_image($item, $source_name)
                    ];
                }
            }
        }

        // Sort items by date
        usort($all_items, function($a, $b) {
            return strtotime($b['date']) - strtotime($a['date']);
        });

        return array_slice($all_items, 0, $max_items);
    }

    /**
     * Get source name for a feed
     */
    private static function get_source_name($url) {
        $feeds = self::get_default_feeds();
        return $feeds[$url] ?? parse_url($url, PHP_URL_HOST);
    }

    /**
     * Shortcode to display RSS feeds
     */
    public static function rss_shortcode($atts) {
        $atts = shortcode_atts([
            'max' => 30,
            'layout' => 'grid',
            'columns' => '3'
        ], $atts, 'topic_rss');
        
        $settings = get_option(self::$SETTINGS_KEY, []);

        $rss_items = self::fetch_rss_feeds($atts['max']);

        ob_start();
        ?>
        <div class="topic-rss-feed topic-rss-<?php echo esc_attr($atts['layout']); ?>" data-columns="<?php echo esc_attr($atts['columns']); ?>">
            <?php foreach ($rss_items as $item): ?>
                <div class="topic-rss-card">
                    <div class="topic-rss-card-header">
                        <span class="source"><?php echo esc_html($item['source']); ?></span>
                        <span class="date"><?php echo date('M j', strtotime($item['date'])); ?></span>
                    </div>
                    <?php if (!empty($item['image'])): ?>
                        <div class="featured-image">
                            <img src="<?php echo esc_url($item['image']); ?>" alt="<?php echo esc_attr($item['title']); ?>">
                        </div>
                    <?php endif; ?>
                    <h3><?php echo $item['title']; ?></h3>
                    <div class="description"><?php echo $item['description']; ?></div>
                    <a href="<?php echo $item['link']; ?>" class="read-more" target="_blank">Read More</a>
                </div>
            <?php endforeach; ?>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Add CSS to document head
     */
    public static function add_css() {
        ?>
        <style type="text/css">
            /* TOPIC RSS GRID LAYOUT */
            .topic-rss-feed.topic-rss-grid {
                display: flex !important;
                flex-wrap: wrap !important;
                gap: 20px !important;
                padding: 20px !important;
                width: 100% !important;
                margin: 0 auto !important;
                box-sizing: border-box !important;
            }
            .topic-rss-card {
                border: 1px solid #e0e0e0 !important;
                border-radius: 8px !important;
                padding: 15px !important;
                background: #f9f9f9 !important;
                display: flex !important;
                flex-direction: column !important;
                flex: 1 1 calc(33.333% - 20px) !important;
                min-width: 250px !important;
                max-width: calc(33.333% - 20px) !important;
                min-height: 300px !important;
                box-sizing: border-box !important;
                margin: 0 !important;
            }
            .topic-rss-card-header {
                display: flex !important;
                justify-content: space-between !important;
                margin-bottom: 10px !important;
                font-size: 0.8em !important;
                color: #666 !important;
            }
            .topic-rss-card .featured-image {
                margin-bottom: 10px !important;
                overflow: hidden !important;
                border-radius: 4px !important;
                width: 100% !important;
            }
            .topic-rss-card .featured-image img {
                width: 100% !important;
                height: 150px !important;
                object-fit: cover !important;
                border-radius: 4px !important;
            }
            .topic-rss-card h3 {
                margin: 10px 0 !important;
                font-size: 1.1em !important;
                line-height: 1.3 !important;
                font-weight: 600 !important;
            }
            .topic-rss-card .description {
                margin-bottom: 15px !important;
                font-size: 0.9em !important;
                flex-grow: 1 !important;
                line-height: 1.4 !important;
                color: #555 !important;
            }
            .topic-rss-card .read-more {
                display: inline-block !important;
                color: #0066cc !important;
                text-decoration: none !important;
                align-self: flex-start !important;
                font-weight: 500 !important;
                padding: 8px 12px !important;
                border: 1px solid #0066cc !important;
                border-radius: 4px !important;
                margin-top: auto !important;
            }
            .topic-rss-card .read-more:hover {
                background-color: #0066cc !important;
                color: white !important;
            }
            @media (max-width: 1200px) {
                .topic-rss-card {
                    flex: 1 1 calc(50% - 20px) !important;
                    max-width: calc(50% - 20px) !important;
                }
            }
            @media (max-width: 768px) {
                .topic-rss-card {
                    flex: 1 1 100% !important;
                    max-width: 100% !important;
                }
                .topic-rss-feed.topic-rss-grid {
                    padding: 10px !important;
                }
            }
        </style>
        <?php
    }

    /**
     * Admin menu
     */
    public static function add_admin_menu() {
        $settings = get_option(self::$SETTINGS_KEY, []);
        $plugin_name = isset($settings['plugin_name']) ? $settings['plugin_name'] : 'Topic RSS';
        
        add_menu_page(
            $plugin_name . ' Feeds', 
            $plugin_name, 
            'manage_options', 
            'topic-rss-feeds', 
            [__CLASS__, 'render_admin_page'],
            'dashicons-rss',
            99
        );
    }

    /**
     * Render the admin page
     */
    public static function render_admin_page() {
        // Check user capabilities
        if (!current_user_can('manage_options')) {
            return;
        }
        
        $feeds = self::get_default_feeds();
        $settings = get_option(self::$SETTINGS_KEY, []);
        
        // Handle form submission
        if (isset($_POST['save_topic_rss_feeds']) && check_admin_referer('topic_rss_feeds_nonce')) {
            $new_feeds = [];
            
            if (isset($_POST['feed_urls']) && isset($_POST['feed_names'])) {
                $feed_urls = $_POST['feed_urls'];
                $feed_names = $_POST['feed_names'];
                
                foreach ($feed_urls as $index => $url) {
                    if (!empty($url)) {
                        $url = esc_url_raw(trim($url));
                        $name = sanitize_text_field($feed_names[$index] ?? '');
                        
                        if (empty($name)) {
                            $name = parse_url($url, PHP_URL_HOST);
                        }
                        
                        $new_feeds[$url] = $name;
                    }
                }
            }
            
            // Handle settings submission
            if (isset($_POST['plugin_name'])) {
                $new_settings = [
                    'plugin_name' => sanitize_text_field($_POST['plugin_name']),
                    'blocked_titles' => sanitize_textarea_field($_POST['blocked_titles']),
                    'custom_filters' => sanitize_textarea_field($_POST['custom_filters']),
                    'skip_short_content' => isset($_POST['skip_short_content'])
                ];
                update_option(self::$SETTINGS_KEY, $new_settings);
            }
            
            // Update feeds
            update_option(self::$FEEDS_KEY, $new_feeds);
            
            // Show success message
            echo '<div class="notice notice-success is-dismissible"><p>Settings saved successfully.</p></div>';
            
            // Refresh data
            $feeds = $new_feeds;
            $settings = get_option(self::$SETTINGS_KEY, []);
        }
        
        ?>
        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
            
            <div class="topic-rss-admin-content">
                <form method="post" action="">
                    <?php wp_nonce_field('topic_rss_feeds_nonce'); ?>
                    
                    <div class="topic-rss-admin-box">
                        <h2>Plugin Settings</h2>
                        <table class="form-table">
                            <tr>
                                <th><label for="plugin_name">Plugin Display Name</label></th>
                                <td><input type="text" id="plugin_name" name="plugin_name" value="<?php echo esc_attr($settings['plugin_name'] ?? 'Topic RSS'); ?>" class="regular-text" placeholder="e.g., Sports News, Tech Updates, etc."></td>
                            </tr>
                            <tr>
                                <th><label for="blocked_titles">Blocked Titles</label></th>
                                <td>
                                    <textarea id="blocked_titles" name="blocked_titles" rows="3" class="large-text" placeholder="Enter titles to block, one per line"><?php echo esc_textarea($settings['blocked_titles'] ?? ''); ?></textarea>
                                    <p class="description">Articles with these exact titles will be skipped.</p>
                                </td>
                            </tr>
                            <tr>
                                <th><label for="custom_filters">Custom Content Filters</label></th>
                                <td>
                                    <textarea id="custom_filters" name="custom_filters" rows="3" class="large-text" placeholder="Enter text patterns to remove, one per line"><?php echo esc_textarea($settings['custom_filters'] ?? ''); ?></textarea>
                                    <p class="description">Text patterns that will be removed from article descriptions.</p>
                                </td>
                            </tr>
                            <tr>
                                <th><label for="skip_short_content">Skip Short Content</label></th>
                                <td>
                                    <input type="checkbox" id="skip_short_content" name="skip_short_content" <?php checked(isset($settings['skip_short_content']) && $settings['skip_short_content']); ?>>
                                    <label for="skip_short_content">Skip articles with descriptions shorter than 100 characters</label>
                                </td>
                            </tr>
                        </table>
                    </div>
                    
                    <div class="topic-rss-admin-box">
                        <h2><?php echo esc_html($settings['plugin_name'] ?? 'Topic RSS'); ?> Feeds</h2>
                        <p>Add or modify RSS feed URLs and labels below:</p>
                        
                        <table class="form-table" id="feeds-table">
                            <thead>
                                <tr>
                                    <th>Feed URL</th>
                                    <th>Feed Name</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php 
                                if (empty($feeds)) {
                                    // Add empty row if no feeds
                                    ?>
                                    <tr>
                                        <td>
                                            <input type="url" name="feed_urls[]" value="" class="regular-text" placeholder="https://example.com/feed">
                                        </td>
                                        <td>
                                            <input type="text" name="feed_names[]" value="" class="regular-text" placeholder="Source Name">
                                        </td>
                                        <td>
                                            <button type="button" class="button remove-feed">Remove</button>
                                        </td>
                                    </tr>
                                    <?php
                                } else {
                                    foreach ($feeds as $url => $name) {
                                        ?>
                                        <tr>
                                            <td>
                                                <input type="url" name="feed_urls[]" value="<?php echo esc_attr($url); ?>" class="regular-text">
                                            </td>
                                            <td>
                                                <input type="text" name="feed_names[]" value="<?php echo esc_attr($name); ?>" class="regular-text">
                                            </td>
                                            <td>
                                                <button type="button" class="button remove-feed">Remove</button>
                                            </td>
                                        </tr>
                                        <?php
                                    }
                                }
                                ?>
                            </tbody>
                            <tfoot>
                                <tr>
                                    <td colspan="3">
                                        <button type="button" class="button" id="add-feed">Add Feed</button>
                                    </td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                    
                    <div class="topic-rss-admin-box">
                        <h2>Shortcode Usage</h2>
                        <p>Use this shortcode to display the RSS feeds on any page or post:</p>
                        <code>[topic_rss]</code>
                        
                        <p>You can customize the maximum number of items:</p>
                        <code>[topic_rss max="10"]</code>
                    </div>
                    
                    <p class="submit">
                        <input type="submit" name="save_topic_rss_feeds" class="button-primary" value="Save Settings">
                    </p>
                </form>
            </div>
            
            <script>
            jQuery(document).ready(function($) {
                // Add feed row
                $("#add-feed").on("click", function() {
                    var newRow = "<tr>" +
                        "<td><input type=\"url\" name=\"feed_urls[]\" value=\"\" class=\"regular-text\" placeholder=\"https://example.com/feed\"></td>" +
                        "<td><input type=\"text\" name=\"feed_names[]\" value=\"\" class=\"regular-text\" placeholder=\"Source Name\"></td>" +
                        "<td><button type=\"button\" class=\"button remove-feed\">Remove</button></td>" +
                        "</tr>";
                    $("#feeds-table tbody").append(newRow);
                });
                
                // Remove feed row
                $("#feeds-table").on("click", ".remove-feed", function() {
                    // Don't remove if it's the last row
                    if ($("#feeds-table tbody tr").length > 1) {
                        $(this).closest("tr").remove();
                    } else {
                        // Clear inputs instead of removing
                        $(this).closest("tr").find("input").val("");
                    }
                });
            });
            </script>
            
            <style>
            .topic-rss-admin-content {
                margin-top: 20px;
            }
            
            .topic-rss-admin-box {
                background: #fff;
                border: 1px solid #ccd0d4;
                box-shadow: 0 1px 1px rgba(0, 0, 0, 0.04);
                margin-bottom: 20px;
                padding: 15px 20px;
            }
            
            .topic-rss-admin-box h2 {
                margin-top: 0;
                padding-bottom: 12px;
                border-bottom: 1px solid #eee;
            }
            
            #feeds-table {
                width: 100%;
                border-collapse: collapse;
            }
            
            #feeds-table th {
                text-align: left;
                padding-bottom: 10px;
            }
            
            #feeds-table td {
                padding: 5px 0;
                vertical-align: top;
            }
            
            #feeds-table input[type="url"],
            #feeds-table input[type="text"] {
                width: 100%;
            }
            
            #feeds-table tfoot td {
                padding-top: 10px;
            }
            </style>
        </div>
        <?php
    }
}

// Initialize the plugin
add_action('plugins_loaded', ['TopicRSSFetcher', 'init']);