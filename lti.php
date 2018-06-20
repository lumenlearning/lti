<?php
/**
 * @wordpress-plugin
 * Plugin Name:       LTI
 * Description:       IMS LTI Integration for Wordpress
 * Version:           0.1
 * Author:            Lumen Learning
 * Author URI:        http://lumenlearning.com
 * Text Domain:       lti
 * License:           MIT
 * GitHub Plugin URI: https://github.com/lumenlearning/lti
 */

// If file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) exit;

// Do our necessary plugin setup and add_action routines.
LTI::init();

class LTI {
  /**
   * Takes care of registering our hooks and setting constants.
   */
  public static function init() {
    if ( !defined( 'LTI_PLUGIN_DIR' ) ) {
      define( 'LTI_PLUGIN_DIR', __DIR__ . '/' );
    }

    if ( ! defined( 'LTI_PLUGIN_URL' ) ) {
      define( 'LTI_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
    }

    // Length of token generated by OAuthProvider::generateToken()
    define ('LTI_OAUTH_TOKEN_LENGTH', 40);

    // Constants for our meta field names
    define('LTI_META_KEY_NAME', '_lti_consumer_key');
    define('LTI_META_SECRET_NAME', '_lti_consumer_secret');
//    define('LUMEN_GUID', '_lumen_guid');

    // How big of a window to allow timestamps in seconds. Default 90 minutes (5400 seconds).
    define('LTI_NONCE_TIMELIMIT', 5400);

    // LTI Nonce table name.
    define('LTI_TABLE_NAME', 'ltinonce');

    // Database version
    define('LTI_DB_VERSION', '1.0');

    register_activation_hook( __FILE__, array( __CLASS__, 'install' ) );

    add_action( 'admin_notices', array( __CLASS__, 'check_dependencies') );
    add_action( 'init', array( __CLASS__, 'register_post_type' ) );
    add_action( 'init', array( __CLASS__, 'register_consumer_key') );
    add_action( 'init', array( __CLASS__, 'register_consumer_secret') );
    add_action( 'init', array( __CLASS__, 'register_lumen_guid' ) );

    add_action( 'add_meta_boxes', array( __CLASS__, 'add_meta_boxes' ) );
    add_action( 'save_post', array( __CLASS__, 'save') );
    add_action( 'pre_get_posts', array( __CLASS__, 'pre_get_posts'), 1 );

    add_filter( 'query_vars', array( __CLASS__, 'add_query_vars' ) );
    add_filter( 'template_include', array( __CLASS__, 'template_include' ) );

    # API details
    add_action( 'init', array( __CLASS__, 'add_rewrite_rule' ) );
    add_action( 'query_vars', array( __CLASS__, 'query_vars' ) );
    add_action( 'parse_request', array( __CLASS__, 'parse_request' ) );

	}

  /**
   * Add our nonce table to log received nonce to avoid replay attacks.
   */
  public static function install( $sitewide ) {
    global $wpdb;

    LTI::create_db_table();

    // Register our nonce cleanup.
    wp_schedule_event( time(), 'daily', LTIOAuth::purgeNonces() );
  }

  /**
   * Handle logging only if WP_DEBUG is enabled.
   */
  public static function log( $message ) {
    if ( true === WP_DEBUG ) {
      if ( is_array( $message ) || is_object ( $message ) ) {
        error_log ( print_r( $message, true ) );
      }
      else {
        error_log ( $message );
      }
    }
  }

  /**
   * Create a database table for storing nonces.
   */
  public static function create_db_table() {
    global $wpdb;

    $table_name = $wpdb->prefix . LTI_TABLE_NAME;

    $sql = "CREATE TABLE $table_name (
      id mediumint(9) NOT NULL AUTO_INCREMENT,
      noncetime datetime DEFAULT '0000-00-00 00:00:00' NOT NULL,
      noncevalue tinytext NOT NULL,
      PRIMARY KEY  id (id),
      INDEX tv_idx (noncetime, noncevalue(16))
    );";
    require_once( ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta( $sql );

    add_option( 'lti_db_version', LTI_DB_VERSION );
  }

  /**
   * Check dependencies
   */
  public static function check_dependencies() {
    if ( ! extension_loaded('oauth') ) {
      echo '<div id="message" class="error fade"><p>';
      echo 'LTI integration requires the OpenAuth library. Please see <a href="http://php.net/manual/en/book.oauth.php">oauth</a> for more information.';
      echo '</p></div>';
    }
  }

  /**
   * Register our custom post type to track LTI consumers.
   *
   * @see http://codex.wordpress.org/Function_Reference/register_post_type
   */
  public static function register_post_type() {
    $args = array(
      'labels' => array(
        'name' => __( 'LTI Consumers' ),
        'add_new' => _x('Add New', 'lti_consumer' ),
        'singular_name' => __( 'LTI Consumer' ),
        'add_new_item' => __( 'Add New LTI Consumer' ),
        'edit_item' => __( 'Edit LTI Consumer' ),
        'new_item' => __( 'New LTI Consumer' ),
        'view_item' => __( 'View LTI Consumer' ),
        'search_items' => __( 'Search LTI Consumers' ),
        'not_found' => __( 'No LTI Consumers found' ),
        'not_found_in_trash' => ( 'No LTI Consumers found in trash' ),
      ),
      'description' => 'LTI consumer credential information',
      'public' => true,
      'rewrite' => true,
      'can_export' => false,
      'show_in_rest' => true,
      'rest_base' => 'lti_credentials',
      'supports' => array(
        'title',
        'author',
      )
    );

    // Hide this endpoint if user does not have "super admin" capabilities
    if ( ! is_super_admin( $current_user->ID ) ) {
        $args['show_in_rest'] = false;
    }

    register_post_type( 'lti_consumer', $args );
  }

  /**
   * Register our custom post type's meta data (lti key)
   *
   * @see https://developer.wordpress.org/reference/functions/register_rest_field/
   */
  public static function register_consumer_key() {
      register_rest_field(
          'lti_consumer',
          '_lti_consumer_key',
          array(
              'get_callback' => array( __CLASS__, 'get_lti_consumer_meta'),
              'update_callback' => array( __CLASS__, 'update_lti_consumer_meta')
          )
      );
  }

    /**
     * Register our custom post type's meta data (lti key)
     *
     * @see https://developer.wordpress.org/reference/functions/register_rest_field/
     */
    public static function register_consumer_secret() {
        register_rest_field(
            'lti_consumer',
            '_lti_consumer_secret',
            array(
                'update_callback' => array( __CLASS__, 'update_lti_consumer_meta')
            )
        );
    }

  /**
   * Register our custom post type's meta data (lti key)
   *
   * @see https://developer.wordpress.org/reference/functions/register_rest_field/
   */
  public static function register_lumen_guid() {
      register_rest_field(
          'lti_consumer',
          '_lumen_guid',
          array(
              'get_callback' => array( __CLASS__, 'get_lti_consumer_meta'),
              'update_callback' => array( __CLASS__, 'update_lti_consumer_meta')
          )
      );
  }

  /**
   * Get the value of the lti_consumer meta
   *
   * @param array $object
   * @param string $field_name
   * @param WP_REST_Request $request
   *
   * @return mixed
   */
  public static function get_lti_consumer_meta( $object, $field_name, $request ) {
      return get_post_meta( $object['id'], $field_name, true );
  }

  /**
   * Update the value of the lti_consumer meta
   *
   * @param $value
   * @param $object
   * @param $field_name
   *
   * @return bool|int|void
   */
  public static function update_lti_consumer_meta( $value, $object, $field_name ) {
      if ( ! is_string( $value ) ) {
          return;
      }

      return update_post_meta( $object->ID, $field_name, $value );
  }


  /**
   * Add query variables to allow searching/sorting LTI consumers by metadata
   *
   * @param $query_vars
   * @return array
   */
  public static function pre_get_posts( $query ) {
      $meta_query = array();

      if ( ! empty( $_GET['lumen_guid'] ) ) {
          $meta_query[] = array( 'key' => '_lumen_guid', 'value' => $_GET['lumen_guid'] );
      }

      if (! empty( $_GET['lti_key'] ) ) {
          $meta_query[] = array( 'key' => '_lti_consumer_key', 'value' => $_GET['lti_key'] );
      }

      if ( count( $meta_query ) > 0 ) {
          $query->set( 'meta_query', $meta_query );
      }
  }

  /**
   * Add custom query variables
   *
   * @param $vars
   * @return array
   */
  public static function add_query_vars( $vars ) {
      $vars[] = 'lumen_guid';
      $vars[] = 'lti_key';

      return $vars;
  }

  /**
   * Setup our custom template
   */
  public static function template_include( $template_path ) {
    if ( get_post_type() == 'lti_consumer' ) {
      if ( is_single() ) {
        if ( $theme_file = locate_template( array('single-lti_consumer.php' ) ) ) {
          $template_path = $theme_file;
        }
        else {
          $template_path = plugin_dir_path( __FILE__ ) . '/single-lti_consumer.php';
        }
      }
    }
    return $template_path;
  }

  /**
   * Attach custom meta fields.
   *
   * @see http://codex.wordpress.org/Function_Reference/add_meta_box
   */
  public static function add_meta_boxes() {
    add_meta_box('api_endpoint_info', 'API URL', array( __CLASS__, 'api_endpoint_info_meta' ), 'lti_consumer', 'normal' );
    add_meta_box('consumer_secret', 'Consumer Secret', array( __CLASS__, 'consumer_secret_meta'), 'lti_consumer', 'normal' );
    add_meta_box('consumer_key', 'Consumer Key', array( __CLASS__, 'consumer_key_meta'), 'lti_consumer', 'normal' );
    add_meta_box('lumen_guid', 'Lumen GUID', array( __CLASS__, 'lumen_guid_meta'), 'lti_consumer', 'normal' );
  }

  /**
   * Callback for add_meta_box().
   */
  public static function api_endpoint_info_meta( $post ) {
    global $wpdb;
    echo '<p>';
    _e( 'Your API endpoint can be accessed via the following URL. Replace BLOGID with the site id of the site the user should be redirected to.' );
    echo '<div>' . get_site_url(1) . '/api/lti/BLOGID</div>';
    echo '</p>';
  }

  /**
   * Callback for add_meta_box().
   *
   * @see http://codex.wordpress.org/Function_Reference/add_meta_box
   */
  public static function consumer_secret_meta( $post ) {
    // Use get_post_meta to retrieve an existing value from the database.
    $secret = get_post_meta( $post->ID, LTI_META_SECRET_NAME, true);

    if ( empty($secret) ) {
      $secret = __('Secret will be generated when post is saved.');
    }

    // Display the form, using the current value.
    echo '<label for="lti_consumer_secret">';
    _e( 'Consumer secret used for signing LTI requests.' );
    echo '</label>';
    echo '<div id="lti_consumer_secret" name="lti_consumer_secret">' . esc_attr( $secret ) . '</div>';

  }

  /**
   * Callback for add_meta_box().
   *
   * @see http://codex.wordpress.org/Function_Reference/add_meta_box
   */
  public static function consumer_key_meta( $post ) {
      // Use get_post_meta to retrieve an existing value from the database.
    $key = get_post_meta( $post->ID, LTI_META_KEY_NAME, true);

    if ( empty( $key ) ) {
      $key = __('Key will be generated when post is saved.');
    }

    // Display the form, using the current value.
    echo '<label for="lti_consumer_key">';
    _e( 'Consumer key used for signing LTI requests.' );
    echo '</label>';
    echo '<div id="lti_consumer_key" name="lti_consumer_key">' . esc_attr( $key ) . '</div>';

  }

  /**
   * Callback for add_meta_box().
   *
   * @see http://codex.wordpress.org/Function_Reference/add_meta_box
   */
  public static function lumen_guid_meta( $post ) {
      // Use get_post_meta to retrieve an existing value from the database.
    $guid = get_post_meta( $post->ID, LUMEN_GUID, true);

    if ( empty( $guid ) ) {
      $guid = __('GUID will be generated when post is saved.');
    }

    // Display the form, using the current value.
    echo '<label for="lumen_guid">';
    _e( 'GUID used for identifying a school.' );
    echo '</label>';
    echo '<div id="lumen_guid" name="lumen_guid">' . esc_attr( $guid ) . '</div>';
  }

  /**
   * Create a new random token
   *
   * We pass through sha1() to return a 40 character token.
   *
   * @param string $type
   *  The type of token to generate either: 'key', 'secret'
   */
  public static function generateToken($type) {
    $token = OAuthProvider::generateToken(LTI_OAUTH_TOKEN_LENGTH);

    $args = array(
      'post_type' => 'lti_consumer',
      'meta_value' => sha1($token),
    );
    switch ($type) {
      case 'key':
        $args['meta_key'] = LTI_META_KEY_NAME;
        break;
      case 'secret':
        $args['meta_key'] = LTI_SECRET_KEY_NAME;
        break;
    }

    $posts = get_posts($args);

    // Loop until our token is unique for this meta value.
    while ( !empty($posts) ) {
      $token = OAuthProvider::generateToken(LTI_OAUTH_TOKEN_LENGTH);
      $args['meta_value'] = sha1($token);
      $posts = get_posts($args);
    }

    return sha1($token);
  }

  /**
   * Minimum check to see if string is SHA1.
   */
  public static function is_sha1($string) {
    if (ctype_xdigit($string) && strlen($string) == 40) {
      return TRUE;
    }
    return FALSE;
  }

  /**
   * Save a post submitted via form.
   *
   * This is here for completeness, but likely needs review to see if we want to
   * expose this part of the UI workflow at all.
   */
  public static function save( $post_id ) {
    if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
      return $post_id;
    }

    if ( ! current_user_can( 'edit_page', $post_id) ) {
      return $post_id;
    }

    if ( isset( $_POST['post_type'] ) && ('lti_consumer' == $_POST['post_type']) ) {
      // Generate and save our key/secret if necessary.
      $key = get_post_meta( $post_id, LTI_META_KEY_NAME, true);
      if ( ! LTI::is_sha1($key) ) {
        update_post_meta( $post_id, LTI_META_KEY_NAME, LTI::generateToken('key') );
      }

      $secret = get_post_meta( $post_id, LTI_META_SECRET_NAME, true);
      if ( ! LTI::is_sha1($secret) ) {
        update_post_meta( $post_id, LTI_META_SECRET_NAME, LTI::generateToken('key') );
      }
    }
  }

  /**
   * Add our LTI api endpoint vars so that wordpress "understands" them.
   */
  public static function query_vars( $query_vars ) {
    $query_vars[] = '__lti';
    $query_vars[] = 'blog';
    return $query_vars;
  }

  /**
   * Add our LTI api endpoint
   */
  public static function add_rewrite_rule() {
    add_rewrite_rule( '^api/lti/([0-9]+/?)', 'index.php?__lti=1&blog=$matches[1]', 'top');
  }

  /**
   * Implementation of action 'parse_request'.
   *
   * @see http://codex.wordpress.org/Plugin_API/Action_Reference/parse_request
   */
  public static function parse_request() {
    if ( LTI::is_lti_request() ) {
      global $wp;

      // Make sure our queries run against the appropriate site.
      $LTIOAuth = new LTIOAuth();
      do_action('lti_setup');
      do_action('lti_pre');
      do_action('lti_launch');

    }
  }

  /**
   * Checks $_POST to see if the current post data is an incoming LTI request.
   *
   * We only check that the required LTI parameters are present. No furhter
   * validation occurs at this point.
   *
   * @return bool TRUE if POST data represents valid lti request.
   */
  public static function is_lti_request() {
    // Check required parameters.
    if (isset( $_POST['lti_message_type'] )  && isset( $_POST['lti_version'] ) && isset( $_POST['resource_link_id'] ) ) {
      // Required LTI parameters present.
      return TRUE;
    }
    return FALSE;
  }
}

class LTIOAuth {
  private $oauthProvider;

  /**
   * Attempt to validate the incoming LTI request.
   */
  public function __construct() {
    try {
      $this->oauthProvider = new OAuthProvider();
      $this->oauthProvider->consumerHandler( array( $this, 'consumerHandler' ) );
      $this->oauthProvider->timestampNonceHandler( array( $this, 'timestampNonceHandler' ) );
      $this->oauthProvider->isRequestTokenEndpoint(true);
      $this->oauthProvider->setParam('url', NULL);
      $this->oauthProvider->checkOAuthRequest();
    }
    catch (OAuthException $e) {
      LTI::log( OAuthProvider::reportProblem( $e ) );

      switch ($e->getCode()) {
        case OAUTH_BAD_NONCE:
          wp_die(__('This LTI request has expired. Please return to your application and restart the launch process.'), __( 'LTI Error' ) );
          break;
        case OAUTH_BAD_TIMESTAMP:
          wp_die(__('This request is too old. Please return to your application and restart the launch process.'), __( 'LTI Error' ) );
          break;
        case OAUTH_CONSUMER_KEY_UNKNOWN:
          wp_die(__('Consumer key is unknown, or has been temporarily disabled. Please check your consumer key settings and restart the launch process.'), __( 'LTI Error' ) );
          break;
        case OAUTH_CONSUMER_KEY_REFUSED:
          wp_die(__('The consumer key was refused. Please check your configuration and follow up with the LTI provider for support.'), __( 'LTI Error' ) );
          break;
        case OAUTH_INVALID_SIGNATURE:
          wp_die(__('The request signature is invalid, or does not match the signature computed.'), __( 'LTI Error' ) );
          break;
        case OAUTH_PARAMETER_ABSENT:
          wp_die(__('A required launch parameter was not provided.'), __( 'LTI Error' ) );
          break;
        case OAUTH_SIGNATURE_METHOD_REJECTED:
          wp_die(__('The signature method was not accepted by the service provider.'), __( 'LTI Error' ) );
          break;
        default:
          // We really shouldn't get any of the other OAuthProvider error codes.
          // log this.
          wp_die(__('General launch error. Please follow up with the tool provider to consult any logs to further diagnose the issue.'), __( 'LTI Error' ) );
          break;
      }
    }
  }
  /**
   * Implement timestampNonceHandler for OAuthProvider.
   *
   * @see http://us3.php.net/manual/en/oauthprovider.timestampnoncehandler.php
   */
  public function timestampNonceHandler() {
    // If nonce is not within timestamp range reject it.
    if ( ( time() - (int)$_POST['oauth_timestamp'] ) > LTI_NONCE_TIMELIMIT ) {
      // Request is too old.
      return OAUTH_BAD_TIMESTAMP;
    }

    // Find out if this nonce has been used before.
    global $wpdb;

    $table_name = $wpdb->prefix . LTI_TABLE_NAME;
    $query = $wpdb->prepare("SELECT noncevalue FROM $table_name WHERE noncevalue = %s AND noncetime >= DATE_SUB(NOW(), interval %d SECOND) ", $_POST['oauth_nonce'], LTI_NONCE_TIMELIMIT);

    $results = $wpdb->get_results($query);
    if ( empty($results) ) {
      // Store the nonce as we haven't seen it before.
      $query = $wpdb->prepare("INSERT INTO $table_name (noncevalue, noncetime)VALUES(%s, FROM_UNIXTIME(%d))", array($_POST['oauth_nonce'], $_POST['oauth_timestamp']));
      $wpdb->query($query);
      return OAUTH_OK;
    }
    else {
      // Replay attack or improper refresh.
      return OAUTH_BAD_NONCE;
    }

    // We should not get here, but in case return OAUTH_BAD_NONCE.
    LTI::log("Reached bad branch in timestampNonceHandler: Post data follows:\n". var_export($_POST, 1));
    return OAUTH_BAD_NONCE;
  }

  /**
   * Purge old nonces from table.
   */
  public static function purgeNonces() {
    // Purge old nonces outside window of acceptable time.
    global $wpdb;
    $table_name = $wpdb->prefix . LTI_TABLE_NAME;
    $query = $wpdb->prepare("DELETE FROM $table_name WHERE noncetime < DATE_SUB(NOW(), interval %d SECOND) ", LTI_NONCE_TIMELIMIT);
    $wpdb->query($query);

  }

  /**
   * Implement consumerHandler for OAuthProvider.
   *
   * @see http://us3.php.net/manual/en/oauthprovider.consumerhandler.php
   */
  public function consumerHandler () {
    // Lookup consumer key.
    if ( ! empty($_POST['oauth_consumer_key']) ) {
      $args = array(
        'post_type' => 'lti_consumer',
        'meta_key' => LTI_META_KEY_NAME,
        'meta_value' => $_POST['oauth_consumer_key'],
      );
      $q = new WP_Query( $args );

      if ( $q->have_posts() ) {
        if ( $q->posts[0] == 'trash' ) {
          // Corresponding lti_consumer post was deleted.
          return OAUTH_CONSUMER_KEY_REFUSED;
        }
        else {
          $secret = get_post_meta( $q->posts[0]->ID, LTI_META_SECRET_NAME, TRUE);
          if ( ! empty( $secret ) ) {
            $this->oauthProvider->consumer_secret = $secret;
            return OAUTH_OK;
          }
          else {
            // This should have resulted in valid secret.
            LTI::log("Failed to find proper secret for lti consumer ID: " . $q->posts[0]->ID);
            return OAUTH_CONSUMER_KEY_UNKOWN;
          }
        }
      }
      else {
        // We did not find a matching consumer key.
        return OAUTH_CONSUMER_KEY_UNKNOWN;
      }

    }
    else {
      // No consumer key present in POST data.
      return OAUTH_CONSUMER_KEY_UNKNOWN;
    }

    // Not sure how we would get here, but refust the key in the event
    LTI::log("Reached bad branch in consumerHandler: Post data follows:\n". var_export($_POST, 1));
    return OAUTH_CONSUMER_KEY_REFUSED;
  }
}
