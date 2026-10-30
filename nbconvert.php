<?php
   /*
   Plugin Name: NbConvert Fixed
   Description: A plugin to add ipynb files to a blog post or page using nbviewer (updated)
   Version: 1.2
   Author: Israel Imru
   License: MIT
   */

if ( ! defined( 'ABSPATH' ) ) {
  exit;
}

// How long rendered notebooks stay cached (seconds).
define( 'NBCONVERT_CACHE_TTL', 12 * HOUR_IN_SECONDS );

function nbconvert_handler( $atts ) {
  return nbconvert_function( $atts );
}

function get_html_by_tag_name( $tagname, $html ) {
  $dom = new DOMDocument;
  libxml_use_internal_errors( true );
  $dom->loadHTML( $html );
  libxml_clear_errors();

  $nodes = $dom->getElementsByTagName( $tagname );
  if ( $nodes->length > 0 ) {
    return nbconvert_innerHTML( $nodes->item( 0 ) );
  }
  return false;
}

function nbconvert_innerHTML( DOMNode $elm ) {
  $innerHTML = '';
  $h1Removed = false;

  foreach ( $elm->childNodes as $child ) {
    if ( $child->nodeName === 'h1' && ! $h1Removed ) {
      $h1Removed = true;
      continue;
    }
    $innerHTML .= $elm->ownerDocument->saveHTML( $child );
  }
  return $innerHTML;
}

// Only allow github.com/<owner>/<repo>/blob/<branch>/<path>.ipynb
function nbconvert_parse_github_url( $url ) {
  $parts = wp_parse_url( $url );
  if ( empty( $parts['host'] ) || strtolower( $parts['host'] ) !== 'github.com' ) {
    return false;
  }
  $segments = explode( '/', trim( $parts['path'] ?? '', '/' ) );
  if ( count( $segments ) < 5 || $segments[2] !== 'blob' ) {
    return false;
  }
  return array(
    'owner'  => $segments[0],
    'repo'   => $segments[1],
    'branch' => $segments[3],
    'path'   => implode( '/', array_slice( $segments, 4 ) ),
  );
}

function nbconvert_get_last_commit_date( $gh ) {
  $request_url = sprintf(
    'https://api.github.com/repos/%s/%s/commits/%s?path=%s',
    rawurlencode( $gh['owner'] ),
    rawurlencode( $gh['repo'] ),
    rawurlencode( $gh['branch'] ),
    rawurlencode( $gh['path'] )
  );

  $res = wp_remote_get( $request_url, array(
    'timeout'    => 5,
    'user-agent' => 'WordPress NbConvert Plugin',
  ) );
  if ( is_wp_error( $res ) || wp_remote_retrieve_response_code( $res ) !== 200 ) {
    return '';
  }

  $data = json_decode( wp_remote_retrieve_body( $res ), true );
  $datetime = $data['commit']['committer']['date'] ?? '';
  if ( ! $datetime ) {
    return '';
  }
  return date( 'd/m/Y H:i:s', strtotime( $datetime ) );
}

function nbconvert_function( $atts ) {
  $atts = shortcode_atts( array( 'url' => '' ), $atts );
  $url  = esc_url_raw( trim( $atts['url'] ) );

  $gh = nbconvert_parse_github_url( $url );
  if ( ! $gh ) {
    return '<!-- nbconvert: invalid or non-GitHub URL -->';
  }

  $cache_key = 'nbconvert_' . md5( $url );
  $cached = get_transient( $cache_key );
  if ( $cached !== false ) {
    return $cached;
  }

  $clean_url = preg_replace( '#^https?://#', '', rtrim( $url, '/' ) );
  $res = wp_remote_get( 'https://nbviewer.jupyter.org/url/' . $clean_url, array(
    'timeout'    => 15,
    'user-agent' => 'WordPress NbConvert Plugin',
  ) );
  if ( is_wp_error( $res ) || wp_remote_retrieve_response_code( $res ) !== 200 ) {
    // Short negative cache so a dead nbviewer doesn't hammer every page load.
    set_transient( $cache_key, '', 10 * MINUTE_IN_SECONDS );
    return '';
  }

  $nb_output = get_html_by_tag_name( 'main', wp_remote_retrieve_body( $res ) );
  if ( $nb_output === false ) {
    return '';
  }

  $last_update = nbconvert_get_last_commit_date( $gh );
  $updated_label = $last_update
    ? '<label class="github-last-update"> Last updated: ' . esc_html( $last_update ) . '</label>'
    : '';

  $converted_nb = '<div class="notebook">
    <div class="nbconvert">' . $nb_output . '
    </div>
    <div class="nbconvert-labels">
      <label class="github-link">
        <a href="' . esc_url( $url ) . '" target="_blank" rel="noopener noreferrer">Also available on Github</a>
        ' . $updated_label . '
      </label>
    </div>
  </div>';

  set_transient( $cache_key, $converted_nb, NBCONVERT_CACHE_TTL );
  return $converted_nb;
}

function nbconvert_enqueue_style() {
  wp_enqueue_style( 'NbConvert', plugins_url( '/css/nbconvert.css', __FILE__ ) );
}
add_action( 'wp_enqueue_scripts', 'nbconvert_enqueue_style' );
add_shortcode( 'nbconvert', 'nbconvert_handler' );