<?php
/**
 * Role & Capability helper class
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class GH_Portal_Roles {

    public static function get_user_roles( $user_id = 0 ) {
        if ( ! $user_id ) {
            $user_id = get_current_user_id();
        }
        $user = get_userdata( $user_id );
        return $user ? (array) $user->roles : array();
    }

    public static function is_tutor( $user_id = 0 ) {
        $roles = self::get_user_roles( $user_id );
        return in_array( 'tutor_instructor', $roles, true ) || current_user_can( 'administrator' );
    }

    public static function is_student( $user_id = 0 ) {
        $roles = self::get_user_roles( $user_id );
        return in_array( 'student', $roles, true ) || in_array( 'subscriber', $roles, true );
    }

    public static function is_offline_learner( $user_id = 0 ) {
        $roles = self::get_user_roles( $user_id );
        return in_array( 'offline_learner', $roles, true );
    }

    public static function is_founder( $user_id = 0 ) {
        $roles = self::get_user_roles( $user_id );
        return in_array( 'founder', $roles, true );
    }

    /**
     * Get user role display title for sidebar badge
     * e.g. "Volunteer Mentor &middot; Tech Academy" or "Learner &middot; Frontend Cohort"
     */
    public static function get_role_badge_title( $user_id = 0 ) {
        if ( ! $user_id ) {
            $user_id = get_current_user_id();
        }

        // Custom custom field override if set
        $custom_title = get_user_meta( $user_id, 'gh_designation', true );
        if ( ! empty( $custom_title ) ) {
            return $custom_title;
        }

        if ( current_user_can( 'administrator' ) ) {
            return __( 'Administrator &middot; Growth Hub', 'growth-hub' );
        }

        if ( self::is_tutor( $user_id ) ) {
            return __( 'Volunteer Mentor &middot; Tech Academy', 'growth-hub' );
        }

        if ( self::is_offline_learner( $user_id ) || self::is_student( $user_id ) ) {
            $cohort = get_user_meta( $user_id, '_gh_cohort', true );
            $course_title = '';
            if ( function_exists( 'tutor_utils' ) ) {
                $enrolled = tutor_utils()->get_enrolled_courses_by_user( $user_id );
                if ( ! empty( $enrolled ) ) {
                    $p = is_object( $enrolled[0] ) ? $enrolled[0] : get_post( $enrolled[0] );
                    if ( $p ) {
                        $course_title = $p->post_title;
                        if ( ! $cohort ) {
                            $cohort = get_post_meta( $p->ID, '_gh_cohort', true );
                        }
                    }
                }
            }
            if ( empty( $course_title ) ) {
                return __( 'Student &middot; Tech Academy', 'growth-hub' );
            }
            if ( empty( $cohort ) ) {
                return $course_title;
            }
            return sprintf( '%s &middot; %s', $course_title, $cohort );
        }

        if ( self::is_founder( $user_id ) ) {
            return __( 'Founder &middot; LAUNCH Incubation', 'growth-hub' );
        }

        return __( 'Student &middot; Tech Academy', 'growth-hub' );
    }
}

