<?php
/**
 * Learner Check-in & Weekly Pulse Handler (Build 4 of 8)
 * Handles physical QR check-ins, Zoom virtual confirmations, weekly pulse ratings, and absence reasons.
 *
 * Author: Adeleye Shina
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class GH_Portal_Checkin {

    public static function init() {
        // AJAX Endpoints
        add_action( 'wp_ajax_gh_learner_checkin', array( __CLASS__, 'handle_checkin' ) );
        add_action( 'wp_ajax_nopriv_gh_learner_checkin', array( __CLASS__, 'handle_checkin' ) );

        add_action( 'wp_ajax_gh_learner_pulse', array( __CLASS__, 'handle_pulse' ) );
        add_action( 'wp_ajax_nopriv_gh_learner_pulse', array( __CLASS__, 'handle_pulse' ) );

        add_action( 'wp_ajax_gh_learner_absence', array( __CLASS__, 'handle_absence' ) );
        add_action( 'wp_ajax_nopriv_gh_learner_absence', array( __CLASS__, 'handle_absence' ) );

        // Shortcode [growth_hub_checkin]
        add_shortcode( 'growth_hub_checkin', array( __CLASS__, 'render_shortcode' ) );
    }

    /**
     * Handle attendance check-in (Physical QR or Virtual Confirm)
     */
    public static function handle_checkin() {
        check_ajax_referer( 'gh_checkin_nonce', 'nonce' );

        $class_id = isset( $_POST['class_id'] ) ? absint( $_POST['class_id'] ) : 0;
        $user_id  = get_current_user_id();
        $mode     = isset( $_POST['mode'] ) && in_array( $_POST['mode'], array( 'physical', 'virtual' ), true ) ? $_POST['mode'] : 'physical';

        if ( ! $user_id ) {
            $identifier = isset( $_POST['learner_name'] ) ? sanitize_text_field( $_POST['learner_name'] ) : '';
            if ( ! empty( $identifier ) ) {
                // Try finding student by email or login
                $found_user = is_email( $identifier ) ? get_user_by( 'email', $identifier ) : get_user_by( 'login', $identifier );
                if ( $found_user ) {
                    $user_id = $found_user->ID;
                } else {
                    $guest_name = $identifier;
                }
            } else {
                wp_send_json_error( array( 'message' => __( 'Please enter your student email or name to confirm attendance.', 'growth-hub' ) ) );
            }
        }

        $course_id = $class_id ? absint( get_post_meta( $class_id, '_gh_course_id', true ) ) : 0;

        if ( $user_id && $course_id ) {
            // 1. Increment learner attendance count for this course
            $current_count = (int) get_user_meta( $user_id, '_gh_attendance_count_' . $course_id, true );
            update_user_meta( $user_id, '_gh_attendance_count_' . $course_id, $current_count + 1 );

            // 2. Save log into learner attendance history
            $history = get_user_meta( $user_id, '_gh_attendance_history_' . $course_id, true );
            if ( ! is_array( $history ) ) {
                $history = array();
            }
            $history[] = array(
                'class_id'  => $class_id,
                'mode'      => $mode,
                'timestamp' => current_time( 'mysql' ),
                'device'    => isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '',
            );
            update_user_meta( $user_id, '_gh_attendance_history_' . $course_id, $history );
        }

        // 3. Increment class log total attendee count
        if ( $class_id ) {
            $class_attendance = (int) get_post_meta( $class_id, '_gh_attendance_count', true );
            update_post_meta( $class_id, '_gh_attendance_count', $class_attendance + 1 );

            $attendee_list = get_post_meta( $class_id, '_gh_attendees', true );
            if ( ! is_array( $attendee_list ) ) {
                $attendee_list = array();
            }
            $attendee_identifier = $user_id ? $user_id : ( isset( $guest_name ) ? $guest_name : 'Guest' );
            if ( ! in_array( $attendee_identifier, $attendee_list, true ) ) {
                $attendee_list[] = $attendee_identifier;
                update_post_meta( $class_id, '_gh_attendees', $attendee_list );
            }
        }

        wp_send_json_success( array(
            'message'  => __( 'Attendance recorded successfully!', 'growth-hub' ),
            'class_id' => $class_id,
        ) );
    }

    /**
     * Handle Weekly Pulse submission (Rating 1-5, Pace, Comments)
     */
    public static function handle_pulse() {
        check_ajax_referer( 'gh_checkin_nonce', 'nonce' );

        $class_id = isset( $_POST['class_id'] ) ? absint( $_POST['class_id'] ) : 0;
        $user_id  = get_current_user_id();
        $course_id = $class_id ? absint( get_post_meta( $class_id, '_gh_course_id', true ) ) : 0;

        $rating = isset( $_POST['rating'] ) ? min( 5, max( 1, floatval( $_POST['rating'] ) ) ) : 5.0;
        $pace   = isset( $_POST['pace'] ) && in_array( $_POST['pace'], array( 'too_slow', 'about_right', 'too_fast' ), true ) ? $_POST['pace'] : 'about_right';
        $notes  = isset( $_POST['notes'] ) ? sanitize_textarea_field( $_POST['notes'] ) : '';

        // Save learner rating & comment for this course
        if ( $user_id && $course_id ) {
            update_user_meta( $user_id, '_gh_learner_rating_' . $course_id, $rating );
            if ( ! empty( $notes ) ) {
                update_user_meta( $user_id, '_gh_learner_comment_' . $course_id, $notes );
            }
        }

        // Save class log rating and comments
        if ( $class_id ) {
            $ratings = get_post_meta( $class_id, '_gh_pulse_ratings', true );
            if ( ! is_array( $ratings ) ) {
                $ratings = array();
            }
            $ratings[] = array(
                'rating'    => $rating,
                'pace'      => $pace,
                'notes'     => $notes,
                'user_id'   => $user_id,
                'timestamp' => current_time( 'mysql' ),
            );
            update_post_meta( $class_id, '_gh_pulse_ratings', $ratings );

            // Recalculate average class rating
            $score_sum = 0;
            foreach ( $ratings as $r ) {
                $score_sum += floatval( $r['rating'] );
            }
            $avg_score = round( $score_sum / count( $ratings ), 1 );
            update_post_meta( $class_id, '_gh_class_rating', $avg_score );
        }

        wp_send_json_success( array(
            'message' => __( 'Thank you! Your feedback has been received.', 'growth-hub' ),
        ) );
    }

    /**
     * Handle absence reason ("Not in class today? Tell us why")
     */
    public static function handle_absence() {
        check_ajax_referer( 'gh_checkin_nonce', 'nonce' );

        $class_id = isset( $_POST['class_id'] ) ? absint( $_POST['class_id'] ) : 0;
        $user_id  = get_current_user_id();
        $reason   = isset( $_POST['reason'] ) ? sanitize_textarea_field( $_POST['reason'] ) : '';

        if ( $class_id ) {
            $absences = get_post_meta( $class_id, '_gh_absence_reports', true );
            if ( ! is_array( $absences ) ) {
                $absences = array();
            }
            $absences[] = array(
                'user_id'   => $user_id,
                'reason'    => $reason,
                'timestamp' => current_time( 'mysql' ),
            );
            update_post_meta( $class_id, '_gh_absence_reports', $absences );
        }

        wp_send_json_success( array(
            'message' => __( 'Reason logged. The Ops and BD Manager has been notified.', 'growth-hub' ),
        ) );
    }

    /**
     * Render Check-in Shortcode [growth_hub_checkin]
     */
    public static function render_shortcode( $atts ) {
        ob_start();
        include GH_PORTAL_PATH . 'templates/learner/checkin.php';
        return ob_get_clean();
    }
}

GH_Portal_Checkin::init();

