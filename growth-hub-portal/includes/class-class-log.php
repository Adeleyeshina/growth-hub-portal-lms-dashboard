<?php
/**
 * Class Log Post Type & Submission Handler
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class GH_Portal_Class_Log {

    public static function init() {
        add_action( 'init', array( __CLASS__, 'register_post_type' ) );
        add_action( 'wp_ajax_gh_submit_class_log', array( __CLASS__, 'handle_ajax_submission' ) );
        add_action( 'admin_post_gh_submit_class_log', array( __CLASS__, 'handle_post_submission' ) );
    }

    public static function register_post_type() {
        register_post_type( 'gh_class_log', array(
            'labels' => array(
                'name'               => __( 'Class Logs', 'growth-hub' ),
                'singular_name'      => __( 'Class Log', 'growth-hub' ),
                'add_new'            => __( 'Add New Log', 'growth-hub' ),
                'add_new_item'       => __( 'Add New Class Log', 'growth-hub' ),
                'edit_item'          => __( 'Edit Class Log', 'growth-hub' ),
                'new_item'           => __( 'New Class Log', 'growth-hub' ),
                'view_item'          => __( 'View Class Log', 'growth-hub' ),
                'search_items'       => __( 'Search Class Logs', 'growth-hub' ),
                'not_found'          => __( 'No class logs found', 'growth-hub' ),
            ),
            'public'              => false,
            'show_ui'             => true,
            'show_in_menu'        => true,
            'menu_icon'           => 'dashicons-clipboard',
            'supports'            => array( 'title', 'editor', 'author', 'custom-fields' ),
            'capability_type'     => 'post',
            'map_meta_cap'        => true,
        ) );
    }

    /**
     * Get tutor's week statistics for the right sidebar card
     */
    public static function get_tutor_week_stats( $user_id = 0 ) {
        if ( ! $user_id ) {
            $user_id = get_current_user_id();
        }

        // Get count of logged classes by this tutor this week (Monday to Sunday)
        $start_of_week = strtotime( 'monday this week 00:00:00' );
        $end_of_week   = strtotime( 'sunday this week 23:59:59' );

        $logs = get_posts( array(
            'post_type'      => 'gh_class_log',
            'post_status'    => array( 'publish' ),
            'author'         => $user_id,
            'date_query'     => array(
                array(
                    'after'     => date( 'Y-m-d H:i:s', $start_of_week ),
                    'before'    => date( 'Y-m-d H:i:s', $end_of_week ),
                    'inclusive' => true,
                ),
            ),
            'posts_per_page' => -1,
            'fields'         => 'ids',
        ) );

        $logged_count = count( $logs );

        // Planned target can be retrieved from mentor profile meta, default to 5
        $planned_target = (int) get_user_meta( $user_id, 'gh_weekly_classes_planned', true );
        if ( ! $planned_target ) {
            $planned_target = 5;
        }

        $still_to_log = max( 0, $planned_target - $logged_count );

        return array(
            'planned'  => $planned_target,
            'logged'   => $logged_count,
            'still'    => $still_to_log,
        );
    }

    /**
     * Get instructor's courses
     */
    public static function get_instructor_courses( $user_id = 0 ) {
        if ( ! $user_id ) {
            $user_id = get_current_user_id();
        }

        if ( current_user_can( 'administrator' ) ) {
            return get_posts( array(
                'post_type'      => 'courses',
                'post_status'    => 'publish',
                'posts_per_page' => -1,
                'orderby'        => 'title',
                'order'          => 'ASC',
            ) );
        }

        $merged_map = array();

        // 1. Tutor LMS native helper
        if ( function_exists( 'tutor_utils' ) && method_exists( tutor_utils(), 'get_courses_by_instructor' ) ) {
            $native_courses = tutor_utils()->get_courses_by_instructor( $user_id, array( 'publish' ) );
            if ( ! empty( $native_courses ) && is_array( $native_courses ) ) {
                foreach ( $native_courses as $nc ) {
                    $nc_obj = is_object( $nc ) ? $nc : get_post( $nc );
                    if ( $nc_obj && 'publish' === $nc_obj->post_status ) {
                        $merged_map[ $nc_obj->ID ] = $nc_obj;
                    }
                }
            }
        }

        // 2. Authored courses
        $authored = get_posts( array(
            'post_type'      => 'courses',
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'author'         => $user_id,
            'orderby'        => 'title',
            'order'          => 'ASC',
        ) );
        if ( ! empty( $authored ) ) {
            foreach ( $authored as $c ) {
                $merged_map[ $c->ID ] = $c;
            }
        }

        // 3. Multi-instructor courses (_tutor_instructor_course meta)
        $assigned = get_posts( array(
            'post_type'      => 'courses',
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'meta_query'     => array(
                array(
                    'key'     => '_tutor_instructor_course',
                    'value'   => $user_id,
                    'compare' => '=',
                ),
            ),
            'orderby'        => 'title',
            'order'          => 'ASC',
        ) );
        if ( ! empty( $assigned ) ) {
            foreach ( $assigned as $c ) {
                $merged_map[ $c->ID ] = $c;
            }
        }

        return array_values( $merged_map );
    }

    /**
     * Save class log logic
     */
    public static function save_class_log( $data, $user_id = 0 ) {
        if ( ! $user_id ) {
            $user_id = get_current_user_id();
        }

        $course_id   = isset( $data['course_id'] ) ? intval( $data['course_id'] ) : 0;
        $cohort      = isset( $data['cohort'] ) ? sanitize_text_field( $data['cohort'] ) : '';
        $week_number = isset( $data['week_number'] ) ? sanitize_text_field( $data['week_number'] ) : '';
        $class_date  = isset( $data['class_date'] ) ? sanitize_text_field( $data['class_date'] ) : date( 'Y-m-d' );
        $duration    = isset( $data['duration'] ) ? intval( $data['duration'] ) : 90;
        $mode        = isset( $data['mode'] ) && in_array( $data['mode'], array( 'physical', 'virtual' ), true ) ? $data['mode'] : 'virtual';
        $is_planned  = isset( $data['is_planned'] ) && '0' === $data['is_planned'] ? 0 : 1;
        $topic       = isset( $data['topic'] ) ? sanitize_text_field( $data['topic'] ) : '';
        $notes       = isset( $data['notes'] ) ? sanitize_textarea_field( $data['notes'] ) : '';
        $status      = isset( $data['status'] ) && 'draft' === $data['status'] ? 'draft' : 'publish';

        $course_title = $course_id ? get_the_title( $course_id ) : __( 'Class', 'growth-hub' );
        $post_title   = sprintf( '%s - %s (%s)', $course_title, $topic ? $topic : $week_number, $class_date );

        $post_id = wp_insert_post( array(
            'post_type'    => 'gh_class_log',
            'post_title'   => $post_title,
            'post_content' => $notes,
            'post_status'  => $status,
            'post_author'  => $user_id,
        ) );

        if ( is_wp_error( $post_id ) ) {
            return $post_id;
        }

        // Save meta fields matching Figma specification
        update_post_meta( $post_id, '_gh_course_id', $course_id );
        update_post_meta( $post_id, '_gh_cohort', $cohort );
        update_post_meta( $post_id, '_gh_week_number', $week_number );
        update_post_meta( $post_id, '_gh_class_date', $class_date );
        update_post_meta( $post_id, '_gh_duration_minutes', $duration );
        update_post_meta( $post_id, '_gh_mode', $mode );
        update_post_meta( $post_id, '_gh_is_planned', $is_planned );
        update_post_meta( $post_id, '_gh_topic', $topic );
        update_post_meta( $post_id, '_gh_logged_at', current_time( 'mysql' ) );

        // Fire action for attendance pulse & automation (Section 4.2 / Section 7.5 in PRD)
        do_action( 'gh_class_log_submitted', $post_id, $data, $user_id );

        return $post_id;
    }

    public static function handle_ajax_submission() {
        check_ajax_referer( 'gh_save_class_log_nonce', 'nonce' );

        if ( ! is_user_logged_in() ) {
            wp_send_json_error( array( 'message' => __( 'You must be logged in to log a class.', 'growth-hub' ) ) );
        }

        $result = self::save_class_log( $_POST, get_current_user_id() );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( array( 'message' => $result->get_error_message() ) );
        }

        wp_send_json_success( array(
            'message' => __( 'Class log submitted successfully! Learners can now submit attendance check-ins.', 'growth-hub' ),
            'post_id' => $result,
        ) );
    }

    public static function handle_post_submission() {
        if ( ! isset( $_POST['gh_log_nonce'] ) || ! wp_verify_nonce( $_POST['gh_log_nonce'], 'gh_save_class_log' ) ) {
            wp_die( 'Security check failed.' );
        }

        if ( ! is_user_logged_in() ) {
            wp_die( 'You must be logged in.' );
        }

        $result = self::save_class_log( $_POST, get_current_user_id() );

        $redirect = wp_get_referer() ? wp_get_referer() : home_url( '/dashboard/class-log/' );
        if ( ! is_wp_error( $result ) ) {
            $redirect = add_query_arg( 'gh_success', '1', $redirect );
        } else {
            $redirect = add_query_arg( 'gh_error', urlencode( $result->get_error_message() ), $redirect );
        }

        wp_safe_redirect( $redirect );
        exit;
    }
}

GH_Portal_Class_Log::init();

