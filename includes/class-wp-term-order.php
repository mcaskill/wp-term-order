<?php

/**
 * Term Order Class
 *
 * @since 0.1.6
 *
 * @package Plugins/Terms/Metadata/Order
 */

// Exit if accessed directly
defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WP_Term_Order' ) ) :
/**
 * Main WP Term Order class
 *
 * @link https://make.wordpress.org/core/2013/07/28/potential-roadmap-for-taxonomy-meta-and-post-relationships/ Taxonomy Roadmap
 *
 * @since 0.1.0
 */
final class WP_Term_Order
{
    /**
     * @var string Plugin version
     */
    public $version = '0.1.6';

    /**
     * @var string Database version
     */
    public $db_version = 201809141200;

    /**
     * @var string Database version
     */
    public $db_version_key = 'wtm_term_order_version';

    /**
     * @var string File for plugin
     */
    public $file = '';

    /**
     * @var string URL to plugin
     */
    public $url = '';

    /**
     * @var string Path to plugin
     */
    public $path = '';

    /**
     * @var string Basename for plugin
     */
    public $basename = '';

    /**
     * @var array Which taxonomies are being targeted?
     */
    public $taxonomies = array();

    /**
     * @var boo Whether to use fancy ordering
     */
    public $fancy = false;

    /**
     * Hook into queries, admin screens, and more!
     *
     * @since 0.1.0
     *
     * @fires action:wp_term_meta_order_init
     * @fires filter:wp_fancy_term_order
     *
     * @param string $file The plugin file.
     */
    public function __construct( $file = __FILE__ )
    {
        // Setup plugin
        $this->file     = $file;
        $this->url      = plugin_dir_url( $this->file );
        $this->path     = plugin_dir_path( $this->file );
        $this->basename = plugin_basename( $this->file );
        $this->fancy    = apply_filters( 'wp_fancy_term_order', true );

        // Queries
        add_filter( 'get_terms_orderby', array( $this, 'get_terms_orderby' ), 10, 3 );
        add_action( 'create_term',       array( $this, 'add_term_order'    ), 10, 3 );
        add_action( 'edit_term',         array( $this, 'add_term_order'    ), 10, 3 );

        // Get visible taxonomies
        $this->taxonomies = $this->get_taxonomies();

        // Always hook these in, for ajax actions
        foreach ( $this->taxonomies as $taxonomy ) {
            // Unfancy gets the column
            if ( false === $this->fancy ) {
                add_filter( "manage_edit-{$taxonomy}_columns",          array( $this, 'add_column_header' ) );
                add_filter( "manage_{$taxonomy}_custom_column",         array( $this, 'add_column_value'  ), 10, 3 );
                add_filter( "manage_edit-{$taxonomy}_sortable_columns", array( $this, 'sortable_columns'  ) );
            }

            add_action( "{$taxonomy}_add_form_fields",  array( $this, 'term_order_add_form_field'  ), 10, 0 );
            add_action( "{$taxonomy}_edit_form_fields", array( $this, 'term_order_edit_form_field' ), 10, 2 );
        }

        // Ajax actions
        add_action( 'wp_ajax_reordering_terms', array( $this, 'ajax_reordering_terms' ) );

        // Customize REST API
        add_action( 'rest_api_init', array( $this, 'rest_api_init' ) );

        // Only blog admin screens
        if ( is_blog_admin() || doing_action( 'wp_ajax_inline_save_tax' ) ) {
            add_action( 'admin_init', array( $this, 'admin_init' ) );

            // Bail if taxonomy does not include colors
            if ( ! empty( $_REQUEST['taxonomy'] ) && in_array( $_REQUEST['taxonomy'], $this->taxonomies, true ) ) {
                add_action( 'load-edit-tags.php', array( $this, 'edit_tags'  ) );
            }
        }

        // Pass ths object into an action
        do_action( 'wp_term_meta_order_init', $this );
    }

    /**
     * Fires as an admin screen or script is being initialized.
     *
     * @since 0.1.0
     *
     * @listens WP#action:admin_init
     *
     * @return void
     */
    public function admin_init()
    {
        // Check for DB update
        $this->maybe_upgrade_database();
    }

    /**
     * Edit tags administration screen.
     *
     * @since 0.1.0
     *
     * @listens WP#action:load-{$page_hook}
     *
     * @return void
     */
    public function edit_tags()
    {
        add_action( 'admin_print_scripts-edit-tags.php', array( $this, 'enqueue_scripts' ) );
        add_action( 'admin_head-edit-tags.php',          array( $this, 'admin_head'      ) );
        add_action( 'admin_head-edit-tags.php',          array( $this, 'help_tabs'       ) );
        add_action( 'quick_edit_custom_box',             array( $this, 'quick_edit_term_order' ), 10, 3 );
    }

    /** Assets ****************************************************************/

    /**
     * Whether a taxonomy object supports ordering.
     *
     * If multiple taxonomies are supplied then the method will return TRUE only if all of them are supported.
     * Evaluation goes from first to last and stops as soon as an unsupported taxonomy is encountered.
     *
     * @since 0.1.5
     *
     * @param string|array $taxonomy String of taxonomy name or array of string values of taxonomy names.
     *
     * @return bool Whether the taxonomy is sortable.
     */
    public function taxonomy_supported( $taxonomy )
    {
        foreach ( (array) $taxonomy as $tax ) {
            if ( ! in_array( $tax, $this->taxonomies ) ) {
                return false;
            }
        }

        return true;
    }

    /**
     * Enqueue quick-edit JS.
     *
     * @since 0.1.0
     *
     * @listens WP#action:admin_print_scripts-{$hook_suffix}
     *
     * @return void
     */
    public function enqueue_scripts()
    {
        wp_enqueue_script( 'term-order-quick-edit', $this->url . 'js/quick-edit.js', array( 'jquery' ), $this->db_version, true );

        // Enqueue fancy ordering
        if ( true === $this->fancy ) {
            wp_enqueue_script( 'term-order-reorder', $this->url . 'js/reorder.js', array( 'jquery-ui-sortable' ), $this->db_version, true );
        }
    }

    /**
     * Add contexutal help tabs.
     *
     * @since 0.1.5
     *
     * @listens WP#action:admin_head-{$hook_suffix}
     *
     * @return void
     */
    public function help_tabs()
    {
        // Add a helpful help tab
        if ( false === $this->fancy ) {
            return;
        }

        get_current_screen()->add_help_tab( array(
            'id'      => 'wp_term_order_help_tab',
            'title'   => __( 'Term Order', 'wp-term-order' ),
            'content' => '<p>' . __( 'To reposition an item, drag and drop the row by "clicking and holding" it anywhere and moving it to its new position.', 'wp-term-order' ) . '</p>',
        ) );
    }

    /**
     * Align custom `order` column
     *
     * @since 0.1.0
     *
     * @listens WP#action:admin_head-{$hook_suffix}
     *
     * @return void
     */
    public function admin_head()
    {
        ?>

        <style type="text/css">
            .column-order {
                text-align: center;
                width: 74px;
            }
            <?php if ( true === $this->fancy ) : ?>

            .wp-list-table .ui-sortable tr:not(.no-items) {
                cursor: move;
            }

            .striped.dragging > tbody > .ui-sortable-helper ~ tr:nth-child(even) {
                background: #f9f9f9;
            }

            .striped.dragging > tbody > .ui-sortable-helper ~ tr:nth-child(odd) {
                background: #fff;
            }

            .wp-list-table .to-updating tr,
            .wp-list-table .ui-sortable tr.inline-editor {
                cursor: default;
            }

            .wp-list-table .ui-sortable-placeholder {
                outline: 1px dashed #bbb;
                background: #f1f1f1 !important;
                visibility: visible !important;
            }
            .wp-list-table .ui-sortable-helper {
                background-color: #fff !important;
                outline: 1px solid #bbb;
            }
            .wp-list-table .ui-sortable-helper .row-actions {
                visibility: hidden;
            }
            .to-row-updating .check-column {
                background: url('<?php echo admin_url( '/images/spinner.gif' );?>') 10px 9px no-repeat;
            }
            @media print,
            (-o-min-device-pixel-ratio: 5/4),
            (-webkit-min-device-pixel-ratio: 1.25),
            (min-resolution: 120dpi) {
                .to-row-updating .check-column {
                    background-image: url('<?php echo admin_url( '/images/spinner-2x.gif' );?>');
                    background-size: 20px 20px;
                }
            }
            .to-row-updating .check-column input {
                visibility: hidden;
            }
            <?php endif; ?>
        </style>

        <?php
    }

    /**
     * Return the taxonomies used by this plugin.
     *
     * @since 0.1.0
     *
     * @fires filter:wp_term_order_get_taxonomies
     *
     * @param array $args Optional. An array of `key => value` arguments to match against the taxonomy objects.
     *
     * @return array A list of taxonomy names or objects.
     */
    private static function get_taxonomies( $args = array() )
    {
        // Parse arguments
        $r = wp_parse_args( $args, array(
            'show_ui' => true,
        ) );

        // Get & return the taxonomies
        $taxonomies = get_taxonomies( $r );

        // Filter taxonomies & return
        return apply_filters( 'wp_term_order_get_taxonomies', $taxonomies, $r, $args );
    }

    /** Columns ***************************************************************/

    /**
     * Add the "Order" column to taxonomy terms list-tables.
     *
     * @since 0.1.0
     *
     * @listens WP#filter:manage_{$screen->id}_columns
     *
     * @param array $columns An array of column headers.
     *
     * @return array
     */
    public function add_column_header( $columns = array() )
    {
        $columns['order'] = __( 'Order', 'wp-term-order' );

        return $columns;
    }

    /**
     * Output the value for the custom column, in our case: `order`.
     *
     * @since 0.1.0
     *
     * @listens WP#filter:manage_{$this->screen->taxonomy}_custom_column
     *
     * @param string $empty       Blank string.
     * @param string $column_name Name of the column.
     * @param int    $term_id     Term ID.
     *
     * @return mixed
     */
    public function add_column_value( $empty = '', $custom_column = '', $term_id = 0 )
    {
        // Bail if no taxonomy passed or not on the `order` column
        if ( empty( $_REQUEST['taxonomy'] ) || ( 'order' !== $custom_column ) || ! empty( $empty ) ) {
            return $empty;
        }

        return $this->get_term_order( $term_id, $_REQUEST['taxonomy'] );
    }

    /**
     * Allow sorting by `order` order.
     *
     * @since 0.1.0
     *
     * @listens WP#filter:manage_{$this->screen->id}_sortable_columns
     *
     * @param array $columns An array of sortable columns.
     *
     * @return array
     */
    public function sortable_columns( $columns = array() )
    {
        $columns['order'] = 'order';

        return $columns;
    }

    /**
     * Add `order` to term when updating.
     *
     * @since 0.1.0
     *
     * @listens WP#action:create_term
     * @listens WP#action:edit_term
     *
     * @param int    $term_id  Term ID.
     * @param int    $tt_id    Term taxonomy ID.
     * @param string $taxonomy Taxonomy slug.
     *
     * @return void
     */
    public function add_term_order( $term_id, $tt_id, $taxonomy )
    {
        /*
         * Bail if order info hasn't been POST-ed, like when the "Quick Edit"
         * form is used to update a term.
         */
        if ( ! isset( $_POST['order'] ) ) {
            return;
        }

        // Sanitize the value.
        $order = ! empty( $_POST['order'] )
            ? (int) $_POST['order']
            : 0;

        self::set_term_order( $term_id, $taxonomy, $order );
    }

    /**
     * Set order of a specific term.
     *
     * @since 0.1.0
     *
     * @global \wpdb $wpdb The WordPress database abstraction object.
     *
     * @fires action:wp_term_order_set_term_order
     *
     * @param int     $term_id     Term ID.
     * @param string  $taxonomy    Taxonomy slug.
     * @param int     $order       Term order.
     * @param bool    $clean_cache Whether to clear the cached term.
     *
     * @return bool Whether the term was updated.
     */
    public static function set_term_order( $term_id = 0, $taxonomy = '', $order = 0, $clean_cache = false )
    {
        global $wpdb;

        // Update the database row
        $result = (bool) $wpdb->update(
            $wpdb->term_taxonomy,
            array(
                'order' => $order,
            ),
            array(
                'term_id'  => $term_id,
                'taxonomy' => $taxonomy,
            )
        );

        if ( ! $result ) {
            return false;
        }

        /**
         * Fires immediately after the term order is updated, but before the term cache is cleaned.
         *
         * @since 0.1.5
         *
         * @param int    $term_id  Term ID.
         * @param string $taxonomy Taxonomy slug.
         * @param int    $order    Term order.
         */
        do_action( 'wp_term_order_set_term_order', $term_id, $taxonomy, $order );

        // Maybe clean the term cache
        if ( true === $clean_cache ) {
            clean_term_cache( $term_id, $taxonomy );
        }

        return $result;
    }

    /**
     * Return the order of a term.
     *
     * @since 0.1.0
     * @since 0.1.6 The `$taxonomy` parameter was added.
     *
     * @param int    $term_id  The term ID.
     * @param string $taxonomy Optional. Taxonomy name that $term_id is part of.
     *
     * @return int
     */
    public function get_term_order( $term_id = 0, $taxonomy = '' )
    {
        // Get the term, probably from cache at this point
        $term = get_term( $term_id, $taxonomy );

        // Assume default order
        $order = 0;

        // Use term order if set
        if ( isset( $term->order ) ) {
            $order = $term->order;
        }

        // Check for option order
        if ( empty( $order ) ) {
            $key    = "term_order_{$term->taxonomy}";
            $orders = get_option( $key, array() );

            if ( ! empty( $orders ) ) {
                foreach ( $orders as $position => $value ) {
                    if ( $value === $term->term_id ) {
                        $order = $position;
                        break;
                    }
                }
            }
        }

        return (int) $order;
    }

    /** Markup ****************************************************************/

    /**
     * Output the "order" form field when adding a new term.
     *
     * @since 0.1.0
     *
     * @listens WP#action:{$taxonomy}_add_form_fields
     *
     * @return void
     */
    public static function term_order_add_form_field()
    {
        ?>

        <div class="form-field form-required">
            <label for="order">
                <?php esc_html_e( 'Order', 'wp-term-order' ); ?>
            </label>
            <input type="number" pattern="[0-9.]+" name="order" id="order" value="0" size="11">
            <p class="description">
                <?php esc_html_e( 'Set a specific order by entering a number (1 for first, etc.) in this field.', 'wp-term-order' ); ?>
            </p>
        </div>

        <?php
    }

    /**
     * Output the "order" form field when editing an existing term.
     *
     * @since 0.1.0
     * @since 0.1.6 The `$taxonomy` parameter was added.
     *
     * @listens WP#action:{$taxonomy}_edit_form_fields
     *
     * @param object $tag      Current taxonomy term object.
     * @param string $taxonomy Current taxonomy slug.
     *
     * @return void
     */
    public function term_order_edit_form_field( $term, $taxonomy )
    {
        ?>

        <tr class="form-field">
            <th scope="row" valign="top">
                <label for="order">
                    <?php esc_html_e( 'Order', 'wp-term-order' ); ?>
                </label>
            </th>
            <td>
                <input name="order" id="order" type="text" value="<?php echo $this->get_term_order( $term, $taxonomy ); ?>" size="11" />
                <p class="description">
                    <?php esc_html_e( 'Terms are usually ordered alphabetically, but you can choose your own order by entering a number (1 for first, etc.) in this field.', 'wp-term-order' ); ?>
                </p>
            </td>
        </tr>

        <?php
    }

    /**
     * Output the "order" quick-edit field.
     *
     * @since 0.1.0
     *
     * @listens WP#action:quick_edit_custom_box This action is fired in wp-admin/includes/class-wp-terms-list-table.php
     *
     * @param string $column_name Name of the column to edit.
     * @param string $screen      The current screen name.
     * @param string taxonomy     The taxonomy name, if any.
     *
     * @return void
     */
    public function quick_edit_term_order( $column_name = '', $screen = '', $taxonomy = '' )
    {
        // Bail if not the `order` column on the `edit-tags` screen for a visible taxonomy
        if ( ( 'order' !== $column_name ) || ( 'edit-tags' !== $screen ) || ! in_array( $taxonomy, $this->taxonomies, true ) ) {
            return false;
        }

        ?>

        <fieldset>
            <div class="inline-edit-col">
                <label>
                    <span class="title"><?php esc_html_e( 'Order', 'wp-term-order' ); ?></span>
                    <span class="input-text-wrap">
                        <input type="number" pattern="[0-9.]+" class="ptitle" name="order" value="" size="11">
                    </span>
                </label>
            </div>
        </fieldset>

        <?php
    }

    /** Query Filters *********************************************************/

    /**
     * Force `orderby` to `tt.order` if not explicitly set to something else.
     *
     * @since 0.1.0
     *
     * @listens WP#filter:get_terms_orderby
     *
     * @param string $orderby    `ORDERBY` clause of the terms query.
     * @param array  $args       An array of terms query arguments.
     * @param array  $taxonomies An array of taxonomies.
     *
     * @return string
     */
    public function get_terms_orderby( $orderby = 'name', $args = array(), $taxonomies = array() )
    {
        if ( ! $this->taxonomy_supported( $taxonomies ) ) {
            return $orderby;
        }

        // Do not override if being manually controlled
        if ( ! empty( $_GET['orderby'] ) && ! empty( $_GET['taxonomy'] ) ) {
            return $orderby;
        }

        // Maybe force `orderby`
        if ( empty( $args['orderby'] ) || empty( $orderby ) || ( 'order' === $args['orderby'] ) || in_array( $orderby, array( 'name', 't.name' ) ) ) {
            $orderby = 'tt.order';
        } elseif ( 't.name' === $orderby ) {
            $orderby = 'tt.order, t.name';
        }

        return $orderby;
    }

    /** Database Alters *******************************************************/

    /**
     * Should a database update occur.
     *
     * Runs on 'admin_init'.
     *
     * @since 0.1.0
     *
     * @return void
     */
    private function maybe_upgrade_database()
    {
        // Check DB for version
        $db_version = get_option( $this->db_version_key );

        // Needs
        if ( $db_version < $this->db_version ) {
            $this->upgrade_database( $db_version );
        }
    }

    /**
     * Modify the `term_taxonomy` table and add an `order` column to it.
     *
     * @since 0.1.0
     *
     * @global \wpdb $wpdb The WordPress database abstraction object.
     *
     * @param int $old_version The old database version number.
     *
     * @return void
     */
    private function upgrade_database( $old_version = 0 )
    {
        global $wpdb;

        $old_version = (int) $old_version;

        // The main column alter
        if ( $old_version < 201508110005 ) {
            $wpdb->query( "ALTER TABLE `{$wpdb->term_taxonomy}` ADD `order` INT (11) NOT NULL DEFAULT 0;" );
        }

        // Update the DB version
        update_option( $this->db_version_key, $this->db_version );
    }

    /** Admin Ajax ************************************************************/

    /**
     * Handle ajax term reordering
     *
     * This bit is inspired by the Simple Page Ordering plugin from 10up
     *
     * @since 0.1.0
     *
     * @listens WP#action:wp_ajax_{$action} This action is documented in wp-admin/admin-ajax.php
     *
     * @return void
     */
    public static function ajax_reordering_terms()
    {
        // Bail if required term data is missing
        if ( empty( $_POST['id'] ) || empty( $_POST['tax'] ) || ( ! isset( $_POST['previd'] ) && ! isset( $_POST['nextid'] ) ) ) {
            die( -1 );
        }

        // Attempt to get the taxonomy
        $tax = get_taxonomy( $_POST['tax'] );

        // Bail if taxonomy does not exist
        if ( empty( $tax ) ) {
            die( -1 );
        }

        // Bail if current user cannot assign terms
        if ( ! current_user_can( $tax->cap->edit_terms ) ) {
            die( -1 );
        }

        // Bail if term cannot be found
        $term = get_term( $_POST['id'], $_POST['tax'] );
        if ( empty( $term ) ) {
            die( -1 );
        }

        // Sanitize positions
        $taxonomy = $_POST['tax'];
        $previd   = empty( $_POST['previd']   ) ? false : (int) $_POST['previd'];
        $nextid   = empty( $_POST['nextid']   ) ? false : (int) $_POST['nextid'];
        $start    = empty( $_POST['start']    ) ? 1     : (int) $_POST['start'];
        $excluded = empty( $_POST['excluded'] ) ?
            array( $term->term_id ) :
            array_filter( (array) $_POST['excluded'], 'intval' );

        // Define return values
        $new_pos     = array();
        $return_data = new stdClass;

        // attempt to get the intended parent...
        $parent_id        = $term->parent;
        $next_term_parent = $nextid
            ? wp_get_term_taxonomy_parent_id( $nextid, $taxonomy )
            : false;

        // If the preceding term is the parent of the next term, move it inside
        if ( $previd === $next_term_parent ) {
            $parent_id = $next_term_parent;

        // If the next term's parent isn't the same as our parent, we need more info
        } elseif ( $next_term_parent !== $parent_id ) {
            $prev_term_parent = $previd
                ? wp_get_term_taxonomy_parent_id( $nextid, $taxonomy )
                : false;

            // If the previous term is not our parent now, set it
            if ( $prev_term_parent !== $parent_id ) {
                $parent_id = ( $prev_term_parent !== false )
                    ? $prev_term_parent
                    : $next_term_parent;
            }
        }

        // If the next term's parent isn't our parent, set to false
        if ( $next_term_parent !== $parent_id ) {
            $nextid = false;
        }

        // Get term siblings for relative ordering
        $siblings = get_terms( $taxonomy, array(
            'depth'      => 1,
            'number'     => 100,
            'parent'     => $parent_id,
            'orderby'    => 'order',
            'order'      => 'ASC',
            'hide_empty' => false,
            'exclude'    => $excluded,
        ) );

        // Loop through siblings and update terms
        foreach ( $siblings as $sibling ) {
            // Skip the actual term if it's in the array
            if ( $sibling->term_id === (int) $term->term_id ) {
                continue;
            }

            // If this is the term that comes after our repositioned term, set
            // our repositioned term position and increment order
            if ( $nextid === (int) $sibling->term_id ) {
                self::set_term_order( $term->term_id, $taxonomy, $start, true );

                $ancestors = get_ancestors( $term->term_id, $taxonomy, 'taxonomy' );

                $new_pos[ $term->term_id ] = array(
                    'order'  => $start,
                    'parent' => $parent_id,
                    'depth'  => count( $ancestors ),
                );

                $start++;
            }

            // If repositioned term has been set and new items are already in
            // the right order, we can stop looping
            if ( isset( $new_pos[ $term->term_id ] ) && (int) $sibling->order >= $start ) {
                $return_data->next = false;
                break;
            }

            // Set order of current sibling and increment the order
            if ( $start !== (int) $sibling->order ) {
                self::set_term_order( $sibling->term_id, $taxonomy, $start, true );
            }

            $new_pos[ $sibling->term_id ] = $start;
            $start++;

            if ( empty( $nextid ) && ( $previd === (int) $sibling->term_id ) ) {
                self::set_term_order( $term->term_id, $taxonomy, $start, true );

                $ancestors = get_ancestors( $term->term_id, $taxonomy, 'taxonomy' );

                $new_pos[ $term->term_id ] = array(
                    'order'  => $start,
                    'parent' => $parent_id,
                    'depth'  => count( $ancestors ),
                );

                $start++;
            }
        }

        // max per request
        if ( ! isset( $return_data->next ) && count( $siblings ) > 1 ) {
            $return_data->next = array(
                'id'       => $term->term_id,
                'previd'   => $previd,
                'nextid'   => $nextid,
                'start'    => $start,
                'excluded' => array_merge( array_keys( $new_pos ), $excluded ),
                'taxonomy' => $taxonomy,
            );
        } else {
            $return_data->next = false;
        }

        if ( empty( $return_data->next ) ) {
            // If the moved term has children, refresh the page for UI reasons
            $children = get_terms( $taxonomy, array(
                'number'     => 1,
                'depth'      => 1,
                'orderby'    => 'order',
                'order'      => 'ASC',
                'parent'     => $term->term_id,
                'fields'     => 'ids',
                'hide_empty' => false,
            ) );

            if ( ! empty( $children ) ) {
                die( 'children' );
            }
        }

        $return_data->new_pos = $new_pos;

        die( json_encode( $return_data ) );
    }

    /** REST API **************************************************************/

    /**
     * REST API hooks
     *
     * @since 0.1.5
     *
     * @listens WP#action:rest_api_init
     *
     * @return void
     */
    public function rest_api_init()
    {
        // Check for DB update
        register_rest_field( $this->taxonomies, 'order', array(
            'get_callback'    => function ( $term_arr ) {
                $order = $this->get_term_order( $term_arr['id'], $term_arr['taxonomy'] );
                return $order;
            },
            'update_callback' => function ( $order, $term_obj ) {
                $result = self::set_term_order( $term_obj->term_id, $term_obj->taxonomy, $order );

                if ( false === $result ) {
                    return new WP_Error(
                      'rest_term_order_failed',
                      __( 'Failed to update term order.' ),
                      array( 'status' => 500 )
                    );
                }

                return true;
            },
            'schema'          => array(
                'description' => __( 'The order of the object in relation to other object of its type.' ),
                'type'        => 'integer',
                'context'     => array( 'view', 'edit' ),
            ),
        ) );
    }
}
endif;
