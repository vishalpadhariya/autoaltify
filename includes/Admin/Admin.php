<?php

/**
 * AutoAltify Admin Class
 *
 * Handles plugin admin UI, settings page, and bulk actions.
 *
 * @package AutoAltify
 * @subpackage Admin
 */

namespace AutoAltify\Admin;

use AutoAltify\Core\Generator;
use AutoAltify\Core\Logger;
use AutoAltify\Core\Options;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Admin handler for plugin settings and UI.
 */
class Admin
{

    const NONCE_ACTION = 'autoaltify_bulk_run';
    const NONCE_NAME   = 'autoaltify_bulk_nonce';

    /**
     * Options manager.
     *
     * @var Options
     */
    private $options;

    /**
     * Generator instance.
     *
     * @var Generator
     */
    private $generator;

    /**
     * Logger instance.
     *
     * @var Logger
     */
    private $logger;

    /**
     * Constructor.
     *
     * @param Options   $options   Options manager.
     * @param Generator $generator Generator instance.
     * @param Logger    $logger    Logger instance.
     */
    public function __construct(Options $options, Generator $generator, Logger $logger)
    {
        $this->options = $options;
        $this->generator = $generator;
        $this->logger = $logger;

        // Settings page.
        add_action('admin_menu', array($this, 'add_settings_page'));
        add_action('admin_init', array($this, 'register_settings'));

        // Bulk action (media library).
        add_filter('bulk_actions-upload', array($this, 'register_bulk_action'));
        add_filter('handle_bulk_actions-upload', array($this, 'handle_bulk_action'), 10, 3);

        // Media column.
        add_filter('manage_upload_columns', array($this, 'add_media_column'));
        add_action('manage_media_custom_column', array($this, 'render_media_column'), 10, 2);

        // AJAX endpoint.
        add_action('wp_ajax_autoaltify_bulk_run', array($this, 'ajax_bulk_run'));

        // Admin assets.
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_assets'));

        // Admin notices.
        add_action('admin_notices', array($this, 'show_admin_notices'));

        // Inline JS for bulk run.
        add_action('admin_footer', array($this, 'print_bulk_run_script'));
    }

    /**
     * Add settings page to admin menu.
     */
    public function add_settings_page()
    {
        add_submenu_page(
            'upload.php',
            __('AutoAltify', 'autoaltify'),
            __('AutoAltify', 'autoaltify'),
            'manage_options',
            'autoaltify',
            array($this, 'render_settings_page')
        );
    }

    /**
     * Register plugin settings and fields.
     */
    public function register_settings()
    {
        register_setting('autoaltify_settings_group', Options::OPTION_NAME, array(
            'sanitize_callback' => array($this->options, 'sanitize'),
        ));

        add_settings_section(
            'autoaltify_main_section',
            __('AutoAltify Settings', 'autoaltify'),
            array($this, 'settings_section_cb'),
            'autoaltify'
        );

        add_settings_field(
            'auto_generate_on_upload',
            __('Auto-generate on upload', 'autoaltify'),
            array($this, 'field_auto_generate_cb'),
            'autoaltify',
            'autoaltify_main_section'
        );

        add_settings_field(
            'mode',
            __('Generation Mode', 'autoaltify'),
            array($this, 'field_mode_cb'),
            'autoaltify',
            'autoaltify_main_section'
        );

        add_settings_field(
            'allowed_mimes',
            __('Allowed image types', 'autoaltify'),
            array($this, 'field_allowed_mimes_cb'),
            'autoaltify',
            'autoaltify_main_section'
        );

        add_settings_field(
            'enable_logging',
            __('Enable logging', 'autoaltify'),
            array($this, 'field_logging_cb'),
            'autoaltify',
            'autoaltify_main_section'
        );

        add_settings_field(
            'batch_size',
            __('Batch size for bulk run', 'autoaltify'),
            array($this, 'field_batch_size_cb'),
            'autoaltify',
            'autoaltify_main_section'
        );
    }

    /**
     * Settings section callback.
     */
    public function settings_section_cb()
    {
        echo '<p>' . esc_html__('Configure AutoAltify behaviour and tools.', 'autoaltify') . '</p>';
    }

    /**
     * Auto-generate on upload field callback.
     */
    public function field_auto_generate_cb()
    {
        $checked = ! empty($this->options->get('auto_generate_on_upload')) ? 'checked' : '';
?>
        <label>
            <input type="checkbox" name="<?php echo esc_attr(Options::OPTION_NAME); ?>[auto_generate_on_upload]" value="1" <?php echo esc_attr($checked); ?>>
            <?php esc_html_e('Automatically generate ALT text for images without ALT when uploaded.', 'autoaltify'); ?>
        </label>
    <?php
    }

    /**
     * Generation mode field callback.
     */
    public function field_mode_cb()
    {
        $mode = $this->options->get('mode');
    ?>
        <select name="<?php echo esc_attr(Options::OPTION_NAME); ?>[mode]">
            <option value="title_only" <?php selected($mode, 'title_only'); ?>><?php esc_html_e('Title Only', 'autoaltify'); ?></option>
            <option value="title_site" <?php selected($mode, 'title_site'); ?>><?php esc_html_e('Title + Site Name', 'autoaltify'); ?></option>
            <option value="filename_clean" <?php selected($mode, 'filename_clean'); ?>><?php esc_html_e('Clean Filename', 'autoaltify'); ?></option>
        </select>
        <p class="description"><?php esc_html_e('Choose how AutoAltify builds generated ALT values.', 'autoaltify'); ?></p>
    <?php
    }

    /**
     * Allowed MIME types field callback.
     */
    public function field_allowed_mimes_cb()
    {
        $allowed = $this->options->get('allowed_mimes');
        $defaults = $this->options->get_defaults();
        $choices = $defaults['allowed_mimes'];
        $labels = array(
            'image/jpeg' => 'JPG / JPEG',
            'image/png' => 'PNG',
            'image/gif' => 'GIF',
            'image/webp' => 'WebP',
            'image/avif' => 'AVIF',
            'image/svg+xml' => 'SVG',
        );
        foreach ($choices as $mime) {
            $chk = in_array($mime, $allowed, true) ? 'checked' : '';
            printf(
                '<label style="display:inline-block;margin-right:10px;"><input type="checkbox" name="%1$s[allowed_mimes][]" value="%2$s" %3$s> %4$s</label>',
                esc_attr(Options::OPTION_NAME),
                esc_attr($mime),
                esc_attr($chk),
                esc_html(isset($labels[$mime]) ? $labels[$mime] : $mime)
            );
        }
    }

    /**
     * Enable logging field callback.
     */
    public function field_logging_cb()
    {
        $checked = ! empty($this->options->get('enable_logging')) ? 'checked' : '';
    ?>
        <label>
            <input type="checkbox" name="<?php echo esc_attr(Options::OPTION_NAME); ?>[enable_logging]" value="1" <?php echo esc_attr($checked); ?>>
            <?php esc_html_e('Write operation logs to uploads/autoaltify-logs/', 'autoaltify'); ?>
        </label>
    <?php
    }

    /**
     * Batch size field callback.
     */
    public function field_batch_size_cb()
    {
        $size = $this->options->get('batch_size');
    ?>
        <input type="number" min="5" max="200" name="<?php echo esc_attr(Options::OPTION_NAME); ?>[batch_size]" value="<?php echo esc_attr($size); ?>">
        <p class="description"><?php esc_html_e('Number of items to process per AJAX batch during bulk runs.', 'autoaltify'); ?></p>
    <?php
    }

    /**
     * Render settings page.
     */
    public function render_settings_page()
    {
        if (! current_user_can('manage_options')) {
            wp_die(esc_html__('Insufficient permissions', 'autoaltify'));
        }
    ?>
        <div class="wrap">
            <h1><?php esc_html_e('AutoAltify Settings', 'autoaltify'); ?></h1>
            <form method="post" action="options.php">
                <?php
                settings_fields('autoaltify_settings_group');
                do_settings_sections('autoaltify');
                submit_button();
                ?>
            </form>

            <hr />

            <h2><?php esc_html_e('Tools', 'autoaltify'); ?></h2>
            <p><?php esc_html_e('Use the button below to generate missing ALT text across your media library. This runs in the background using batches to avoid timeouts.', 'autoaltify'); ?></p>

            <p>
                <button id="autoaltify-run-all" class="button button-primary"><?php esc_html_e('Run ALT Generator on all media (missing only)', 'autoaltify'); ?></button>
            </p>

            <div id="autoaltify-progress" style="display:none; margin-top:15px;">
                <p><strong><?php esc_html_e('Progress', 'autoaltify'); ?></strong></p>
                <p><span id="autoaltify-status">0</span></p>
                <div style="background:#f1f1f1;border:1px solid #ddd;height:20px;width:100%;border-radius:4px;overflow:hidden;">
                    <div id="autoaltify-bar" style="height:20px;width:0%;background:#0073aa;"></div>
                </div>
                <p id="autoaltify-summary" style="margin-top:10px;"></p>
            </div>

            <?php if (! empty($this->options->get('enable_logging'))) : ?>
                <p><?php esc_html_e('Logs are stored at wp-content/uploads/autoaltify-logs/autoaltify.log', 'autoaltify'); ?></p>
            <?php endif; ?>

        </div>
        <?php
    }

    /**
     * Enqueue admin assets.
     *
     * @param string $hook The current admin page.
     */
    public function enqueue_admin_assets($hook)
    {
        if ('settings_page_autoaltify' !== $hook) {
            return;
        }

        $batch_size = $this->options->get('batch_size');
        $data = array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce(self::NONCE_ACTION),
            'batch_size' => intval($batch_size),
        );
        wp_localize_script('autoaltify-admin', 'AutoAltifyData', $data);
    }

    /**
     * Register bulk action in media library.
     *
     * @param array $bulk_actions Existing bulk actions.
     *
     * @return array Updated bulk actions.
     */
    public function register_bulk_action($bulk_actions)
    {
        $bulk_actions['autoaltify_generate_alt'] = __('Generate ALT with AutoAltify', 'autoaltify');
        return $bulk_actions;
    }

    /**
     * Handle bulk action from media library.
     *
     * @param string $redirect_to The redirect URL.
     * @param string $doaction    The action being performed.
     * @param array  $post_ids    The post IDs being acted upon.
     *
     * @return string The redirect URL.
     */
    public function handle_bulk_action($redirect_to, $doaction, $post_ids)
    {
        if ($doaction !== 'autoaltify_generate_alt') {
            return $redirect_to;
        }

        if (! current_user_can('upload_files')) {
            $redirect_to = add_query_arg('autoaltify_error', 'perm', $redirect_to);
            return $redirect_to;
        }

        $processed = 0;
        $skipped = 0;
        $allowed = $this->options->get('allowed_mimes');
        $mode = $this->options->get('mode');

        foreach ((array) $post_ids as $post_id) {
            $mime = get_post_mime_type($post_id);
            if (! in_array($mime, $allowed, true)) {
                $skipped++;
                continue;
            }

            $existing_alt = get_post_meta($post_id, '_wp_attachment_image_alt', true);
            if (! empty($existing_alt)) {
                $skipped++;
                continue;
            }

            $title = get_the_title($post_id);
            $alt = $this->generator->build_alt($post_id, $title, $mode);

            if (! empty($alt)) {
                update_post_meta($post_id, '_wp_attachment_image_alt', wp_strip_all_tags($alt));
                $processed++;
                $this->logger->log(sprintf('Bulk: Attachment %d ALT set to: %s', $post_id, $alt), $this->options->get('enable_logging'));
            } else {
                $skipped++;
            }
        }

        $redirect_to = add_query_arg(array(
            'autoaltify_processed' => intval($processed),
            'autoaltify_skipped' => intval($skipped),
        ), $redirect_to);

        return $redirect_to;
    }

    /**
     * AJAX handler for bulk run across all media.
     */
    public function ajax_bulk_run()
    {
        if (! current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'permission'), 403);
        }

        check_ajax_referer(self::NONCE_ACTION, 'nonce');

        $batch_size = $this->options->get('batch_size');
        $offset = isset($_POST['offset']) ? intval($_POST['offset']) : 0;
        $allowed = $this->options->get('allowed_mimes');
        $mode = $this->options->get('mode');

        // Query attachments with missing or empty alt meta.
        $args = array(
            'post_type' => 'attachment',
            'post_status' => 'inherit',
            'post_mime_type' => $allowed,
            'posts_per_page' => $batch_size,
            'offset' => $offset,
            'orderby' => 'ID',
            'order' => 'ASC',
            'fields' => 'ids',
            'meta_query' => array(
                'relation' => 'OR',
                array(
                    'key' => '_wp_attachment_image_alt',
                    'value' => '',
                    'compare' => '=',
                ),
                array(
                    'key' => '_wp_attachment_image_alt',
                    'compare' => 'NOT EXISTS',
                ),
            ),
        );

        $query = new \WP_Query($args);
        $ids = $query->posts;

        $processed = 0;
        $skipped = 0;

        foreach ((array) $ids as $id) {
            $existing_alt = get_post_meta($id, '_wp_attachment_image_alt', true);
            if (! empty($existing_alt)) {
                $skipped++;
                continue;
            }

            $title = get_the_title($id);
            $alt = $this->generator->build_alt($id, $title, $mode);

            if (! empty($alt)) {
                update_post_meta($id, '_wp_attachment_image_alt', wp_strip_all_tags($alt));
                $processed++;
                $this->logger->log(sprintf('AJAX Bulk: Attachment %d ALT set to: %s', $id, $alt), $this->options->get('enable_logging'));
            } else {
                $skipped++;
            }
        }

        $more = (count($ids) === $batch_size);

        wp_send_json_success(array(
            'processed' => $processed,
            'skipped' => $skipped,
            'count' => count($ids),
            'offset' => $offset,
            'next_offset' => $offset + $batch_size,
            'more' => $more,
        ));
    }

    /**
     * Add ALT status column to media library.
     *
     * @param array $cols Existing columns.
     *
     * @return array Updated columns.
     */
    public function add_media_column($cols)
    {
        $cols['autoaltify_alt_status'] = __('ALT Status', 'autoaltify');
        return $cols;
    }

    /**
     * Render ALT status column.
     *
     * @param string $column_name The column name.
     * @param int    $post_id     The post ID.
     */
    public function render_media_column($column_name, $post_id)
    {
        if ('autoaltify_alt_status' !== $column_name) {
            return;
        }

        $alt = get_post_meta($post_id, '_wp_attachment_image_alt', true);
        if (! empty($alt)) {
            echo '<span style="color:green;">' . esc_html__('Present', 'autoaltify') . '</span>';
        } else {
            echo '<span style="color:#a00;">' . esc_html__('Missing', 'autoaltify') . '</span>';
        }
    }

    /**
     * Show admin notices for bulk action results.
     */
    public function show_admin_notices()
    {
        if (isset($_REQUEST['autoaltify_processed']) || isset($_REQUEST['autoaltify_error'])) {
            $processed = isset($_REQUEST['autoaltify_processed']) ? intval($_REQUEST['autoaltify_processed']) : 0;
            $skipped = isset($_REQUEST['autoaltify_skipped']) ? intval($_REQUEST['autoaltify_skipped']) : 0;
        ?>
            <div class="notice notice-success is-dismissible">
                <p><?php
                    /* translators: 1: number of images updated, 2: number of images skipped */
                    echo esc_html(sprintf(__('AutoAltify: %1$d images updated, %2$d skipped.', 'autoaltify'), $processed, $skipped));
                    ?></p>
            </div>
        <?php
        }

        if (isset($_REQUEST['autoaltify_error']) && 'perm' === $_REQUEST['autoaltify_error']) {
        ?>
            <div class="notice notice-error is-dismissible">
                <p><?php esc_html_e('AutoAltify: You do not have permission to run this action.', 'autoaltify'); ?></p>
            </div>
        <?php
        }
    }

    /**
     * Print inline JavaScript for bulk run functionality.
     */
    public function print_bulk_run_script()
    {
        $screen = get_current_screen();
        if (! $screen || 'settings_page_autoaltify' !== $screen->id) {
            return;
        }

        $batch_size = $this->options->get('batch_size');
        $nonce = wp_create_nonce(self::NONCE_ACTION);
        $ajax = admin_url('admin-ajax.php');
        ?>
        <script type="text/javascript">
            (function($) {
                $('#autoaltify-run-all').on('click', function(e) {
                    e.preventDefault();
                    if (!confirm('<?php echo esc_js(__("Run AutoAltify across entire media library? This will only set missing ALT attributes. Continue?", 'autoaltify')); ?>')) {
                        return;
                    }
                    $('#autoaltify-progress').show();
                    $('#autoaltify-bar').css('width', '0%');
                    $('#autoaltify-status').text('0');
                    $('#autoaltify-summary').text('');
                    var offset = 0;
                    var totalProcessed = 0;
                    var totalSkipped = 0;
                    var batch = <?php echo (int) $batch_size; ?>;

                    function runBatch() {
                        $('#autoaltify-status').text(offset);
                        $.post('<?php echo esc_js($ajax); ?>', {
                            action: 'autoaltify_bulk_run',
                            offset: offset,
                            nonce: '<?php echo esc_js($nonce); ?>'
                        }, function(response) {
                            if (response && response.success) {
                                totalProcessed += response.data.processed;
                                totalSkipped += response.data.skipped;
                                offset = response.data.next_offset;
                                var processedCount = totalProcessed + totalSkipped;
                                var pct = Math.min(100, Math.round((processedCount / (processedCount + 1)) * 100));
                                $('#autoaltify-bar').css('width', pct + '%');
                                $('#autoaltify-summary').text('<?php echo esc_js(__("Processed:", 'autoaltify')); ?>' + totalProcessed + ' | <?php echo esc_js(__("Skipped:", 'autoaltify')); ?>' + totalSkipped);
                                if (response.data.more) {
                                    setTimeout(runBatch, 250);
                                } else {
                                    $('#autoaltify-bar').css('width', '100%');
                                    $('#autoaltify-summary').text('<?php echo esc_js(__("Done. Processed:", 'autoaltify')); ?>' + totalProcessed + ' | <?php echo esc_js(__("Skipped:", 'autoaltify')); ?>' + totalSkipped);
                                    $('<div class="notice notice-success is-dismissible"><p><?php echo esc_js(__("AutoAltify bulk run finished.", 'autoaltify')); ?></p></div>').insertBefore('.wrap');
                                }
                            } else {
                                var msg = (response && response.data && response.data.message) ? response.data.message : 'error';
                                $('<div class="notice notice-error is-dismissible"><p>AutoAltify AJAX error: ' + msg + '</p></div>').insertBefore('.wrap');
                            }
                        }).fail(function(xhr) {
                            $('<div class="notice notice-error is-dismissible"><p>AutoAltify: AJAX request failed.</p></div>').insertBefore('.wrap');
                        });
                    }
                    runBatch();
                });
            })(jQuery);
        </script>
<?php
    }
}
