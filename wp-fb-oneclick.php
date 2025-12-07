<?php
/*
Plugin Name: WP Facebook One-Click Poster
Description: Add a "Post to Facebook" button on post edit screen and publish the post link to a Facebook Page using Yoast SEO social fields where available.
Version: 1.0
Author: You
Text Domain: wp-fb-oneclick
*/

if (! defined('ABSPATH')) exit;

class WP_FB_OneClick
{

    private $option_name = 'wp_fb_oneclick_options';

    public function __construct()
    {
        add_action('admin_menu', array($this, 'add_settings_page'));
        add_action('admin_init', array($this, 'register_settings'));
        add_action('add_meta_boxes', array($this, 'add_meta_box'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_assets'));
        add_action('wp_ajax_wpfboc_post_to_facebook', array($this, 'ajax_post_to_facebook'));

        // admin posts list column for 'post' post type
        add_filter('manage_post_posts_columns', array($this, 'add_shared_column'), 20);
        add_action('manage_post_posts_custom_column', array($this, 'render_shared_column'), 10, 2);
    }


    public function add_settings_page()
    {
        add_options_page('WP FB One-Click', 'WP FB One-Click', 'manage_options', 'wp-fb-oneclick', array($this, 'settings_page'));
    }

    public function register_settings()
    {
        register_setting($this->option_name, $this->option_name, array($this, 'sanitize_options'));
        add_settings_section('wpfboc_main', 'Facebook Settings', null, 'wp-fb-oneclick');
        add_settings_field('page_id', 'Facebook Page ID', array($this, 'field_page_id'), 'wp-fb-oneclick', 'wpfboc_main');
        add_settings_field('page_token', 'Page Access Token', array($this, 'field_page_token'), 'wp-fb-oneclick', 'wpfboc_main');
    }

    public function sanitize_options($input)
    {
        $out = array();
        $out['page_id'] = sanitize_text_field($input['page_id'] ?? '');
        $out['page_token'] = sanitize_text_field($input['page_token'] ?? '');
        return $out;
    }

    public function field_page_id()
    {
        $opts = get_option($this->option_name, array());
        printf('<input type="text" name="%1$s[page_id]" value="%2$s" class="regular-text" />', esc_attr($this->option_name), esc_attr($opts['page_id'] ?? ''));
    }

    public function field_page_token()
    {
        $opts = get_option($this->option_name, array());
        printf('<textarea name="%1$s[page_token]" rows="4" class="large-text code">%2$s</textarea>', esc_attr($this->option_name), esc_textarea($opts['page_token'] ?? ''));
    }

    public function settings_page()
    {
        if (! current_user_can('manage_options')) return;
?>
        <div class="wrap">
            <h1>WP FB One-Click</h1>
            <p>Enter your Facebook Page ID and a Page Access Token with <code>pages_manage_posts</code> permission (long-lived token recommended).</p>
            <form method="post" action="options.php">
                <?php
                settings_fields($this->option_name);
                do_settings_sections('wp-fb-oneclick');
                submit_button();
                ?>
            </form>
            <h2>Notes</h2>
            <ol>
                <li>Create a Facebook App, request needed permissions and generate a Page Access Token. Convert it to a long-lived token (see plugin docs below).</li>
                <li>Keep token private. Anyone with the token can post to your page.</li>
            </ol>
        </div>
<?php
    }

    public function add_meta_box()
    {
        add_meta_box('wpfboc_meta_box', 'Facebook: One-Click Post', array($this, 'meta_box_html'), array('post'), 'side', 'high');
    }

    public function meta_box_html($post)
    {
        $opts = get_option($this->option_name, array());
        $page_id = $opts['page_id'] ?? '';
        $page_token = $opts['page_token'] ?? '';

        // Show warning if not configured
        if (empty($page_id) || empty($page_token)) {
            echo '<p style="color: #a00;"><strong>Configure Facebook Page ID & Token</strong> in Settings → WP FB One-Click before using this button.</p>';
        }

        echo '<p><button type="button" class="button button-primary" id="wpfboc-post-btn">Post to Facebook</button></p>';
        echo '<p id="wpfboc-result" style="margin-top:8px;"></p>';
        // nonce
        wp_nonce_field('wpfboc_post_nonce', 'wpfboc_nonce');
        // pass post ID via JS (also available in JS via global)
        echo '<input type="hidden" id="wpfboc-post-id" value="' . esc_attr($post->ID) . '">';
    }

    public function enqueue_assets($hook)
    {
        // only enqueue on post.php / post-new.php
        if (! in_array($hook, array('post.php', 'post-new.php'), true)) return;
        wp_enqueue_script('wpfboc-admin', plugin_dir_url(__FILE__) . 'admin.js', array('jquery'), '1.0', true);
        wp_localize_script('wpfboc-admin', 'wpfboc', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce'    => wp_create_nonce('wpfboc_ajax_nonce'),
        ));
        // tiny styles
        wp_add_inline_style('wp-admin', '#wpfboc-result{font-size:13px;} #wpfboc-post-btn.loading{opacity:.6;}');
    }

   public function ajax_post_to_facebook() {
    if ( ! current_user_can( 'edit_posts' ) ) {
        wp_send_json_error( 'Insufficient permissions' );
    }

    check_ajax_referer( 'wpfboc_ajax_nonce', 'nonce' );

    $post_id = intval( $_POST['post_id'] ?? 0 );
    if ( $post_id <= 0 ) {
        wp_send_json_error( 'Invalid post ID' );
    }

    $opts = get_option( $this->option_name, array() );
    $page_id    = $opts['page_id'] ?? '';
    $page_token = $opts['page_token'] ?? '';

    if ( empty( $page_id ) || empty( $page_token ) ) {
        wp_send_json_error( 'Facebook Page ID or Token missing.' );
    }

    $post = get_post( $post_id );
    if ( ! $post ) {
        wp_send_json_error( 'Post not found.' );
    }

    // Yoast data
    $title = get_post_meta( $post_id, '_yoast_wpseo_opengraph-title', true );
    if ( empty( $title ) ) $title = get_the_title( $post );

    $description = get_post_meta( $post_id, '_yoast_wpseo_opengraph-description', true );
    if ( empty( $description ) ) {
        $description = wp_trim_words( strip_tags( $post->post_content ), 30, '...' );
    }

    // image: Yoast OG image or featured image
    $og_image = get_post_meta( $post_id, '_yoast_wpseo_opengraph-image', true );
    if ( empty( $og_image ) && has_post_thumbnail( $post_id ) ) {
        $thumb = wp_get_attachment_image_src( get_post_thumbnail_id( $post_id ), 'full' );
        if ( $thumb ) $og_image = $thumb[0];
    }

    // Build caption WITHOUT the direct link (we'll add the link as a comment)
    $message = trim( $title . "\n\n" . $description );

    // Hashtags (from post tags)
    $max_hashtags = 10;
    $tag_names = wp_get_post_terms( $post_id, 'post_tag', array( 'fields' => 'names' ) );
    $hashtags = array();
    if ( ! empty( $tag_names ) && is_array( $tag_names ) ) {
        foreach ( $tag_names as $t ) {
            if ( count( $hashtags ) >= $max_hashtags ) break;
            $clean = preg_replace( '/[^A-Za-z0-9]/', '', $t );
            if ( empty( $clean ) ) continue;
            $hashtags[] = '#' . $clean;
        }
    }
    if ( ! empty( $hashtags ) ) {
        $message .= "\n\n" . implode( ' ', $hashtags );
    }

    // final link to put in comment
    $link = get_permalink( $post_id );

    // ----------------------------------------------------------
    // If we have an image, upload as photo via multipart (source)
    // ----------------------------------------------------------
    if ( ! empty( $og_image ) ) {

        // try to download image
        $image_data = wp_remote_get( esc_url_raw( $og_image ), array( 'timeout' => 30 ) );

        if ( ! is_wp_error( $image_data ) ) {
            $image_body = wp_remote_retrieve_body( $image_data );
            $tmp = wp_tempnam( $og_image );

            if ( $tmp && file_put_contents( $tmp, $image_body ) ) {

                // prepare cURL file
                if ( class_exists( 'CURLFile' ) ) {
                    $mime = function_exists( 'mime_content_type' ) ? mime_content_type( $tmp ) : 'image/jpeg';
                    $cfile = new CURLFile( $tmp, $mime, basename( $tmp ) );
                } else {
                    $cfile = '@' . $tmp;
                }

                $photo_endpoint = "https://graph.facebook.com/{$page_id}/photos";
                $payload = array(
                    'access_token' => $page_token,
                    'caption'      => $message,
                    'source'       => $cfile,
                );

                $ch = curl_init();
                curl_setopt( $ch, CURLOPT_URL, $photo_endpoint );
                curl_setopt( $ch, CURLOPT_POST, true );
                curl_setopt( $ch, CURLOPT_POSTFIELDS, $payload );
                curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
                curl_setopt( $ch, CURLOPT_TIMEOUT, 60 );
                curl_setopt( $ch, CURLOPT_SSL_VERIFYPEER, true );
                curl_setopt( $ch, CURLOPT_SSL_VERIFYHOST, 2 );

                $resp_raw = curl_exec( $ch );
                $curl_err = curl_error( $ch );
                $curl_code = curl_getinfo( $ch, CURLINFO_HTTP_CODE );
                curl_close( $ch );

                // cleanup temp file
                @unlink( $tmp );

                if ( $resp_raw !== false ) {
                    $resp_json = json_decode( $resp_raw, true );

                    if ( $curl_code >= 200 && $curl_code < 300 && ! empty( $resp_json['id'] ) ) {
                        // photo posted — now add the post link as a comment on that photo post
                        $fb_post_id = sanitize_text_field( $resp_json['id'] );

                        // post comment: /{photo_id}/comments
                        $comment_endpoint = "https://graph.facebook.com/{$fb_post_id}/comments";
                        $comment_body = array(
                            'message'      => $link,
                            'access_token' => $page_token,
                        );

                        $comment_resp = wp_remote_post( $comment_endpoint, array( 'timeout' => 20, 'body' => $comment_body ) );

                        // Evaluate comment result (best-effort) — even if comment fails, consider post success
                        $comment_ok = false;
                        if ( ! is_wp_error( $comment_resp ) ) {
                            $comment_code = wp_remote_retrieve_response_code( $comment_resp );
                            $comment_body_raw = wp_remote_retrieve_body( $comment_resp );
                            $comment_json = json_decode( $comment_body_raw, true );
                            if ( $comment_code >= 200 && $comment_code < 300 && ! empty( $comment_json['id'] ) ) {
                                $comment_ok = true;
                            }
                        }

                        // save meta: shared, fb post id, timestamp
                        update_post_meta( $post_id, 'wpfboc_shared', '1' );
                        update_post_meta( $post_id, 'wpfboc_fb_post_id', $fb_post_id );
                        $now = current_time( 'mysql' );
                        update_post_meta( $post_id, 'wpfboc_shared_at', $now );

                        // return success message; include comment status info
                        $msg = 'Photo posted successfully! Post ID: ' . esc_html( $fb_post_id );
                        if ( ! $comment_ok ) {
                            $msg .= ' (Link comment failed—link may not appear in comments.)';
                        }
                        wp_send_json_success( $msg );
                    } else {
                        // photo upload error returned by FB
                        $err = $resp_json['error']['message'] ?? $resp_raw;
                        update_post_meta( $post_id, 'wpfboc_shared', '0' );
                        delete_post_meta( $post_id, 'wpfboc_fb_post_id' );
                        delete_post_meta( $post_id, 'wpfboc_shared_at' );
                        wp_send_json_error( 'Photo upload error: ' . $err );
                    }
                } else {
                    // curl execution error
                    update_post_meta( $post_id, 'wpfboc_shared', '0' );
                    delete_post_meta( $post_id, 'wpfboc_fb_post_id' );
                    delete_post_meta( $post_id, 'wpfboc_shared_at' );
                    wp_send_json_error( 'Photo upload failed: ' . $curl_err );
                }
            }
        }

        // If we reached here, download or file write failed — treat as failure
        update_post_meta( $post_id, 'wpfboc_shared', '0' );
        delete_post_meta( $post_id, 'wpfboc_fb_post_id' );
        delete_post_meta( $post_id, 'wpfboc_shared_at' );
        wp_send_json_error( 'Unable to download or prepare image for upload.' );
    }

    // If no image present, we cannot create the photo post — return error
    update_post_meta( $post_id, 'wpfboc_shared', '0' );
    delete_post_meta( $post_id, 'wpfboc_fb_post_id' );
    delete_post_meta( $post_id, 'wpfboc_shared_at' );
    wp_send_json_error( 'No image found for this post; photo post not created.' );
}

  public function add_shared_column( $columns ) {
    $new = array();
    foreach ( $columns as $key => $title ) {
        $new[ $key ] = $title;
        if ( $key === 'date' ) {
            $new['wpfboc_shared'] = 'FB Shared';
        }
    }
    if ( ! isset( $new['wpfboc_shared'] ) ) {
        $new['wpfboc_shared'] = 'FB Shared';
    }
    return $new;
}

public function render_shared_column( $column, $post_id ) {
    if ( $column !== 'wpfboc_shared' ) return;

    $status = get_post_meta( $post_id, 'wpfboc_shared', true );
    $fb_id  = get_post_meta( $post_id, 'wpfboc_fb_post_id', true );
    $shared_at = get_post_meta( $post_id, 'wpfboc_shared_at', true );

    if ( $status === '1' ) {
        $out = '<span style="color:green;font-weight:600;">Shared</span>';
        if ( ! empty( $shared_at ) ) {
            // show admin-local time nicely
            $dt = date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $shared_at ) );
            $out .= '<br/><small style="color:#666;">' . esc_html( $dt ) . '</small>';
        }
        if ( ! empty( $fb_id ) ) {
            // Build FB URL — Facebook accepts either full ID or page_post id; try both patterns
            $fb_url = esc_url( "https://www.facebook.com/{$fb_id}" );
            $out .= '<br/><a href="' . $fb_url . '" target="_blank" rel="noopener">View</a>';
        }
        echo $out;
    } elseif ( $status === '0' ) {
        echo '<span style="color:#a00;">Not shared</span>';
    } else {
        echo '—';
    }
}



    private function get_yoast_meta($post_id, $key)
    {
        $v = get_post_meta($post_id, $key, true);
        if (! empty($v)) return $v;
        return '';
    }
}

new WP_FB_OneClick();
