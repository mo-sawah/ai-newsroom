<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class AIN_Media {
    public static function sideload_featured_image( $post_id, $url, $desc = '' ) {
        if ( empty( $url ) || ! filter_var( $url, FILTER_VALIDATE_URL ) ) return 0;
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $tmp = download_url( $url, 30 );
        if ( is_wp_error( $tmp ) ) return 0;
        $name = basename( wp_parse_url( $url, PHP_URL_PATH ) );
        if ( ! preg_match( '/\.(jpe?g|png|webp|gif)$/i', $name ) ) {
            $name = md5( $url ) . '.jpg';
        }
        $file = array( 'name' => sanitize_file_name( $name ), 'tmp_name' => $tmp );
        $id = media_handle_sideload( $file, $post_id, $desc );
        if ( is_wp_error( $id ) ) {
            @unlink( $tmp );
            return 0;
        }
        set_post_thumbnail( $post_id, $id );
        return (int) $id;
    }

    public static function sideload_data_image( $post_id, $data_url, $desc = '' ) {
        if ( empty( $data_url ) || ! preg_match( '#^data:image/(png|jpe?g|webp);base64,#i', $data_url, $m ) ) return 0;
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $ext = strtolower( $m[1] );
        if ( 'jpeg' === $ext ) $ext = 'jpg';
        $raw = preg_replace( '#^data:image/[^;]+;base64,#i', '', $data_url );
        $binary = base64_decode( $raw );
        if ( ! $binary ) return 0;

        $tmp = wp_tempnam( 'ain-image.' . $ext );
        if ( ! $tmp ) return 0;
        file_put_contents( $tmp, $binary );
        $file = array(
            'name' => sanitize_file_name( 'ai-newsroom-' . time() . '-' . wp_generate_password( 6, false ) . '.' . $ext ),
            'tmp_name' => $tmp,
        );
        $id = media_handle_sideload( $file, $post_id, $desc );
        if ( is_wp_error( $id ) ) {
            @unlink( $tmp );
            return 0;
        }
        set_post_thumbnail( $post_id, $id );
        return (int) $id;
    }

    public static function pexels_image_url( $query ) {
        $settings = ain_get_settings();
        if ( empty( $settings['pexels_api_key'] ) || empty( $query ) ) return '';
        $url = add_query_arg( array( 'query' => $query, 'per_page' => 1, 'orientation' => 'landscape' ), 'https://api.pexels.com/v1/search' );
        $response = wp_remote_get( $url, array(
            'timeout' => 20,
            'headers' => array( 'Authorization' => $settings['pexels_api_key'] ),
        ) );
        if ( is_wp_error( $response ) ) return '';
        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        return $body['photos'][0]['src']['large2x'] ?? ( $body['photos'][0]['src']['large'] ?? '' );
    }

    public static function openrouter_image_url( $prompt, $media_config = array() ) {
        $settings = ain_get_settings();
        if ( empty( $settings['openrouter_api_key'] ) || empty( $settings['openrouter_image_model'] ) || empty( $prompt ) ) return '';

        $aspect = ! empty( $media_config['image_aspect_ratio'] ) ? $media_config['image_aspect_ratio'] : ( $settings['openrouter_image_aspect_ratio'] ?? '16:9' );
        $size = ! empty( $media_config['image_size'] ) ? $media_config['image_size'] : ( $settings['openrouter_image_size'] ?? '1K' );
        $image_prompt = trim( $prompt . "\n\nEditorial news image. No text overlay, no logos, no fake documents, no misleading depiction of real events. Use a clean professional news style." );

        $image_model = $settings['openrouter_image_model'];
        $modalities = preg_match( '/(flux|recraft|sourceful|image-1)/i', $image_model ) ? array( 'image' ) : array( 'image', 'text' );
        $payload = array(
            'model'      => $image_model,
            'messages'   => array( array( 'role' => 'user', 'content' => $image_prompt ) ),
            'modalities' => $modalities,
            'stream'     => false,
            'image_config' => array(
                'aspect_ratio' => $aspect,
                'image_size'   => $size,
            ),
        );
        $response = wp_remote_post( 'https://openrouter.ai/api/v1/chat/completions', array(
            'timeout' => 160,
            'headers' => array(
                'Authorization' => 'Bearer ' . $settings['openrouter_api_key'],
                'Content-Type'  => 'application/json',
                'HTTP-Referer'  => home_url( '/' ),
                'X-Title'       => get_bloginfo( 'name' ) . ' AI Newsroom Images',
            ),
            'body' => wp_json_encode( $payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ),
        ) );
        if ( is_wp_error( $response ) ) {
            ain_log( 'warning', 'OpenRouter image request failed.', array( 'error' => $response->get_error_message() ) );
            return '';
        }
        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        $message = $body['choices'][0]['message'] ?? array();
        if ( ! empty( $message['images'][0]['image_url']['url'] ) ) {
            return $message['images'][0]['image_url']['url'];
        }
        // Older providers sometimes return a Markdown or plain URL in content.
        $content = $message['content'] ?? '';
        if ( preg_match( '/!\[[^\]]*\]\((https?:\/\/[^\)]+)\)/', $content, $m ) ) return esc_url_raw( $m[1] );
        if ( preg_match( '/https?:\/\/\S+/', $content, $m ) ) return esc_url_raw( trim( $m[0], '"\'' ) );
        if ( preg_match( '#data:image/(?:png|jpe?g|webp);base64,[A-Za-z0-9+/=]+#', $content, $m ) ) return $m[0];
        ain_log( 'warning', 'OpenRouter image response did not include an image.', array( 'body' => $body ) );
        return '';
    }

    public static function youtube_embed_from_item( $item ) {
        $raw = ain_decode_json_field( $item->raw_payload );
        $video_id = $raw['video_id'] ?? '';
        if ( ! $video_id && ! empty( $raw['sources'][0]['video_id'] ) ) $video_id = $raw['sources'][0]['video_id'];
        if ( ! $video_id && ! empty( $item->source_url ) && preg_match( '/(?:v=|youtu\.be\/)([A-Za-z0-9_-]+)/', $item->source_url, $m ) ) {
            $video_id = $m[1];
        }
        if ( ! $video_id ) return '';
        $src = 'https://www.youtube.com/embed/' . rawurlencode( $video_id );
        return '<div class="ain-video-embed"><iframe width="560" height="315" src="' . esc_url( $src ) . '" title="YouTube video" frameborder="0" allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share" allowfullscreen loading="lazy"></iframe></div>';
    }

    public static function generate_table_html( $table ) {
        if ( empty( $table ) || ! is_array( $table ) ) return '';
        $title = sanitize_text_field( $table['title'] ?? '' );
        $headers = ! empty( $table['headers'] ) && is_array( $table['headers'] ) ? array_slice( $table['headers'], 0, 5 ) : array();
        $rows = ! empty( $table['rows'] ) && is_array( $table['rows'] ) ? array_slice( $table['rows'], 0, 8 ) : array();
        if ( empty( $headers ) || empty( $rows ) ) return '';

        $html = '<div class="ain-inline-table">';
        if ( $title ) $html .= '<h3>' . esc_html( $title ) . '</h3>';
        $html .= '<table class="ain-smart-table"><thead><tr>';
        foreach ( $headers as $header ) {
            $html .= '<th scope="col">' . esc_html( sanitize_text_field( $header ) ) . '</th>';
        }
        $html .= '</tr></thead><tbody>';
        foreach ( $rows as $row ) {
            if ( ! is_array( $row ) ) continue;
            $html .= '<tr>';
            $cells = array_slice( array_values( $row ), 0, count( $headers ) );
            for ( $i = 0; $i < count( $headers ); $i++ ) {
                $html .= '<td>' . esc_html( sanitize_text_field( $cells[ $i ] ?? '' ) ) . '</td>';
            }
            $html .= '</tr>';
        }
        $html .= '</tbody></table></div>';
        return $html;
    }

    public static function generate_chart_svg( $title, $rows ) {
        if ( empty( $rows ) || ! is_array( $rows ) ) return '';
        $clean = array();
        $max = 0;
        foreach ( array_slice( $rows, 0, 6 ) as $row ) {
            $label = sanitize_text_field( $row['label'] ?? '' );
            $value = (float) ( $row['value'] ?? 0 );
            if ( '' === $label ) continue;
            $max = max( $max, $value );
            $clean[] = array( 'label' => $label, 'value' => $value );
        }
        if ( empty( $clean ) || $max <= 0 ) return '';
        $height = 80 + count( $clean ) * 42;
        $svg = '<div class="ain-inline-chart"><h3>' . esc_html( $title ?: 'Key figures' ) . '</h3><svg viewBox="0 0 760 ' . esc_attr( $height ) . '" role="img" aria-label="' . esc_attr( $title ) . '">';
        $y = 48;
        foreach ( $clean as $row ) {
            $w = max( 6, round( ( $row['value'] / $max ) * 460 ) );
            $svg .= '<text x="20" y="' . esc_attr( $y + 18 ) . '" font-size="16">' . esc_html( $row['label'] ) . '</text>';
            $svg .= '<rect x="230" y="' . esc_attr( $y ) . '" width="' . esc_attr( $w ) . '" height="24" rx="6" fill="currentColor"></rect>';
            $svg .= '<text x="' . esc_attr( 245 + $w ) . '" y="' . esc_attr( $y + 18 ) . '" font-size="16">' . esc_html( $row['value'] ) . '</text>';
            $y += 42;
        }
        $svg .= '</svg></div>';
        return $svg;
    }
}
