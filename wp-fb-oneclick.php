<?php
/*
Plugin Name: WP Facebook One-Click Poster
Description: Add a "Post to Facebook" button on post edit screen and publish the post link to a Facebook Page using Yoast SEO social fields where available.
Version: 1.0
Author: You
Text Domain: wp-fb-oneclick
*/

if ( ! defined( 'ABSPATH' ) ) exit;

class WP_FB_OneClick {

    private $option_name = 'wp_fb_oneclick_options';

    public function __construct() {
        add_action( 'admin_menu', array( $this, 'add_settings_page' ) );
        add_action( 'admin_init', array( $this, 'register_settings' ) );
        add_action( 'add_meta_boxes', array( $this, 'add_meta_box' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
        add_action( 'wp_ajax_wpfboc_post_to_facebook', array( $this, 'ajax_post_to_facebook' ) );
    }

    public function add_settings_page() {
        add_options_page( 'WP FB One-Click', 'WP FB One-Click', 'manage_options', 'wp-fb-oneclick', array( $this, 'settings_page' ) );
    }

    public function register_settings() {
        register_setting( $this->option_name, $this->option_name, array( $this, 'sanitize_options' ) );
        add_settings_section( 'wpfboc_main', 'Facebook Settings', null, 'wp-fb-oneclick' );
        add_settings_field( 'page_id', 'Facebook Page ID', array( $this, 'field_page_id' ), 'wp-fb-oneclick', 'wpfboc_main' );
        add_settings_field( 'page_token', 'Page Access Token', array( $this, 'field_page_token' ), 'wp-fb-oneclick', 'wpfboc_main' );
    }

    public function sanitize_options( $input ) {
        $out = array();
        $out['page_id'] = sanitize_text_field( $input['page_id'] ?? '' );
        $out['page_token'] = sanitize_text_field( $input['page_token'] ?? '' );
        return $out;
    }

    public function field_page_id() {
        $opts = get_option( $this->option_name, array() );
        printf( '<input type="text" name="%1$s[page_id]" value="%2$s" class="regular-text" />', esc_attr( $this->option_name ), esc_attr( $opts['page_id'] ?? '' ) );
    }

    public function field_page_token() {
        $opts = get_option( $this->option_name, array() );
        printf( '<textarea name="%1$s[page_token]" rows="4" class="large-text code">%2$s</textarea>', esc_attr( $this->option_name ), esc_textarea( $opts['page_token'] ?? '' ) );
    }

    public function settings_page() {
        if ( ! current_user_can( 'manage_options' ) ) return;
        ?>
        <div class="wrap">
            <h1>WP FB One-Click</h1>
            <p>Enter your Facebook Page ID and a Page Access Token with <code>pages_manage_posts</code> permission (long-lived token recommended).</p>
            <form method="post" action="options.php">
                <?php
                settings_fields( $this->option_name );
                do_settings_sections( 'wp-fb-oneclick' );
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

    public function add_meta_box() {
        add_meta_box( 'wpfboc_meta_box', 'Facebook: One-Click Post', array( $this, 'meta_box_html' ), array( 'post' ), 'side', 'high' );
    }

    public function meta_box_html( $post ) {
        $opts = get_option( $this->option_name, array() );
        $page_id = $opts['page_id'] ?? '';
        $page_token = $opts['page_token'] ?? '';

        // Show warning if not configured
        if ( empty( $page_id ) || empty( $page_token ) ) {
            echo '<p style="color: #a00;"><strong>Configure Facebook Page ID & Token</strong> in Settings → WP FB One-Click before using this button.</p>';
        }

        echo '<p><button type="button" class="button button-primary" id="wpfboc-post-btn">Post to Facebook</button></p>';
        echo '<p id="wpfboc-result" style="margin-top:8px;"></p>';
        // nonce
        wp_nonce_field( 'wpfboc_post_nonce', 'wpfboc_nonce' );
        // pass post ID via JS (also available in JS via global)
        echo '<input type="hidden" id="wpfboc-post-id" value="' . esc_attr( $post->ID ) . '">';
    }

    public function enqueue_assets( $hook ) {
        // only enqueue on post.php / post-new.php
        if ( ! in_array( $hook, array( 'post.php', 'post-new.php' ), true ) ) return;
        wp_enqueue_script( 'wpfboc-admin', plugin_dir_url( __FILE__ ) . 'admin.js', array( 'jquery' ), '1.0', true );
        wp_localize_script( 'wpfboc-admin', 'wpfboc', array(
            'ajax_url' => admin_url( 'admin-ajax.php' ),
            'nonce'    => wp_create_nonce( 'wpfboc_ajax_nonce' ),
        ) );
        // tiny styles
        wp_add_inline_style( 'wp-admin', '#wpfboc-result{font-size:13px;} #wpfboc-post-btn.loading{opacity:.6;}');
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

    $og_image = get_post_meta( $post_id, '_yoast_wpseo_opengraph-image', true );

    if ( empty( $og_image ) && has_post_thumbnail( $post_id ) ) {
        $thumb = wp_get_attachment_image_src( get_post_thumbnail_id( $post_id ), 'full' );
        if ( $thumb ) $og_image = $thumb[0];
    }

    $message = trim( $title . "\n\n" . $description );
    $link = get_permalink( $post_id );

    /**
     * 📌 CASE 1 — Upload Image as Photo
     */
    if ( ! empty( $og_image ) ) {

        $image_data = wp_remote_get( $og_image, [ 'timeout' => 20 ] );

        if ( ! is_wp_error( $image_data ) ) {

            $image_body = wp_remote_retrieve_body( $image_data );
            $tmp = wp_tempnam( $og_image );

            if ( $tmp && file_put_contents( $tmp, $image_body ) ) {

                // Upload image to Facebook Page
                $photo_endpoint = "https://graph.facebook.com/{$page_id}/photos";

                $cfile = new CURLFile( $tmp, mime_content_type( $tmp ), basename( $tmp ) );

                $payload = [
                    'access_token' => $page_token,
                    'caption'      => $message,
                    'source'       => $cfile,
                ];

   $ch = curl_init();
                curl_setopt( $ch, CURLOPT_URL, $photo_endpoint );
                curl_setopt( $ch, CURLOPT_POST, true );
                curl_setopt( $ch, CURLOPT_POSTFIELDS, $payload );
                curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );

                $response = curl_exec( $ch );
                $error    = curl_error( $ch );
                curl_close( $ch );
                unlink( $tmp );

                if ( $response !== false ) {
                    $json = json_decode( $response, true );
                    if ( ! empty( $json['id'] ) ) {
                        wp_send_json_success( 'Photo posted successfully! Post ID: ' . $json['id'] );
                    }
                }
            }
        }
    }

    /**
     * 📌 CASE 2 — Fallback: Publish link only
     */
    $endpoint = "https://graph.facebook.com/{$page_id}/feed";

    $body = [
        'message'      => $message,
        'link'         => $link,
        'access_token' => $page_token,
    ];

    $response = wp_remote_post( $endpoint, [
        'timeout' => 20,
        'body'    => $body,
    ] );

    if ( is_wp_error( $response ) ) {
        wp_send_json_error( 'Failed to post link: ' . $response->get_error_message() );
    }

    $code = wp_remote_retrieve_response_code( $response );
    $resp = wp_remote_retrieve_body( $response );
    $json = json_decode( $resp, true );

    if ( $code >= 200 && $code < 300 && ! empty( $json['id'] ) ) {
        wp_send_json_success( 'Image failed — link posted instead. Post ID: ' . $json['id'] );
    }

    wp_send_json_error( 'Facebook error: ' . ( $json['error']['message'] ?? $resp ) );
}

    private function get_yoast_meta( $post_id, $key ) {
        $v = get_post_meta( $post_id, $key, true );
        if ( ! empty( $v ) ) return $v;
        return '';
    }
}

new WP_FB_OneClick();
