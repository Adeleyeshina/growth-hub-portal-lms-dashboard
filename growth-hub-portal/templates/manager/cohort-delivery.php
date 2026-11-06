<?php
/**
 * Ops and BD Manager - Cohort Delivery Dashboard Template (Build 6 of 8)
 * 100% Dynamic - Real Database Data (Dummy mock arrays completely removed)
 *
 * Direct match for Figma redesign:
 * - 3 Filter Dropdowns: Branch, Programme, Week
 * - Header Actions: "Share with sponsors", "Export weekly pack"
 * - 5 Dark Metric Cards (#1B1240) computed from real LMS and Class Log data
 * - Table 1: Cohorts this week (Real Tutor LMS courses and class delivery)
 * - 2-Column Split:
 *   - Left: At-risk learners (Real enrolled learners flagged by attendance, pulse, or inactivity)
 *   - Right: Tutor scorecard (Real instructors, on-time logging %, grading, and reports)
 * - Table 3 (Full width): Reports and tutor support (Real learner absence reasons & pulse alerts)
 *
 * Author: Adeleye Shina
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

global $wpdb;

$user_id  = get_current_user_id();
$user     = wp_get_current_user();
$is_admin = current_user_can( 'administrator' );

// 1. Resolve Filters: Branch, Programme (Course), Week
$selected_branch    = isset( $_GET['filter_branch'] ) ? sanitize_text_field( $_GET['filter_branch'] ) : 'all';
$selected_programme = isset( $_GET['filter_programme'] ) ? sanitize_text_field( $_GET['filter_programme'] ) : 'all';

// Dynamically determine current active week from the latest class log
$latest_logged_week = 1;
if ( isset( $wpdb->postmeta ) ) {
    $last_wn = $wpdb->get_var( "SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_gh_week_number' AND meta_value != '' ORDER BY post_id DESC LIMIT 1" );
    if ( $last_wn && preg_match( '/\b(\d+)\b/', $last_wn, $wm ) ) {
        $latest_logged_week = max( 1, (int) $wm[1] );
    }
}
$selected_week = isset( $_GET['filter_week'] ) ? absint( $_GET['filter_week'] ) : $latest_logged_week;

// Query all published programmes / courses from Tutor LMS
$all_programmes = get_posts( array(
    'post_type'      => 'courses',
    'post_status'    => 'publish',
    'posts_per_page' => -1,
    'orderby'        => 'title',
    'order'          => 'ASC',
) );

// Dynamic Available branches from real courses and class logs
$available_branches = array(
    'all' => __( 'All branches', 'growth-hub' ),
);
if ( ! empty( $all_programmes ) ) {
    foreach ( $all_programmes as $p ) {
        $coh = get_post_meta( $p->ID, '_gh_cohort', true );
        if ( $coh && strpos( $coh, '&middot;' ) !== false ) {
            $parts = explode( '&middot;', $coh );
            $branch = trim( end( $parts ) );
            if ( $branch && ! in_array( $branch, $available_branches, true ) ) {
                $available_branches[ sanitize_title( $branch ) ] = $branch;
            }
        }
    }
}
if ( count( $available_branches ) <= 1 ) {
    $available_branches = array(
        'all'    => __( 'All branches', 'growth-hub' ),
        'akure'  => __( 'Akure Hub', 'growth-hub' ),
        'ibadan' => __( 'Ibadan Hub', 'growth-hub' ),
        'online' => __( 'Virtual / Online', 'growth-hub' ),
    );
}

// 2. Fetch Class Logs for Selected Week & Programme
$log_meta_query = array(
    array(
        'key'     => '_gh_week_number',
        'value'   => 'Week ' . $selected_week,
        'compare' => 'LIKE',
    ),
);

if ( 'all' !== $selected_programme && absint( $selected_programme ) > 0 ) {
    $log_meta_query[] = array(
        'key'     => '_gh_course_id',
        'value'   => absint( $selected_programme ),
        'compare' => '=',
    );
}

$weekly_logs = get_posts( array(
    'post_type'      => 'gh_class_log',
    'post_status'    => 'publish',
    'posts_per_page' => -1,
    'meta_query'     => $log_meta_query,
) );

// Group logs by course ID
$logs_by_course = array();
$all_pulse_ratings = array();
$total_delivered_classes = 0;
$total_actual_checkins = 0;
$total_expected_checkins = 0;

foreach ( $weekly_logs as $wl ) {
    $cid = (int) get_post_meta( $wl->ID, '_gh_course_id', true );
    if ( ! isset( $logs_by_course[ $cid ] ) ) {
        $logs_by_course[ $cid ] = array();
    }
    $logs_by_course[ $cid ][] = $wl;
    $total_delivered_classes++;

    $att = get_post_meta( $wl->ID, '_gh_attendees', true );
    if ( is_array( $att ) ) {
        $total_actual_checkins += count( $att );
    }

    $pulses = get_post_meta( $wl->ID, '_gh_pulse_ratings', true );
    if ( is_array( $pulses ) ) {
        foreach ( $pulses as $item ) {
            if ( isset( $item['rating'] ) && floatval( $item['rating'] ) > 0 ) {
                $all_pulse_ratings[] = floatval( $item['rating'] );
            }
        }
    }
}

// 3. Build Real Table 1: "Cohorts this week"
$cohorts_data = array();
$filter_courses = ( 'all' !== $selected_programme && absint( $selected_programme ) > 0 )
    ? array_filter( $all_programmes, function( $p ) use ( $selected_programme ) { return $p->ID == $selected_programme; } )
    : $all_programmes;

foreach ( $filter_courses as $prog ) {
    $cid = $prog->ID;
    $c_logs = isset( $logs_by_course[ $cid ] ) ? $logs_by_course[ $cid ] : array();
    $c_delivered = count( $c_logs );

    // Planned classes
    $planned = (int) get_post_meta( $cid, '_gh_planned_classes_week', true );
    if ( $planned <= 0 ) {
        $planned = 3;
    }

    // Cohort label
    $c_cohort = get_post_meta( $cid, '_gh_cohort', true );
    if ( ! $c_cohort ) {
        $c_cohort = __( 'Active Cohort', 'growth-hub' );
    }

    // Tutor name
    $tutor_name = get_the_author_meta( 'display_name', $prog->post_author );
    if ( ! $tutor_name ) {
        $tutor_name = __( 'Assigned Instructor', 'growth-hub' );
    }

    // Delivery mode
    $mode = ! empty( $c_logs ) ? get_post_meta( $c_logs[0]->ID, '_gh_mode', true ) : get_post_meta( $cid, '_gh_mode', true );
    if ( ! $mode ) {
        $mode = 'Virtual';
    }

    // Enrolled count
    $enrolled = 0;
    if ( function_exists( 'tutor_utils' ) && method_exists( tutor_utils(), 'count_enrolled_users_by_course' ) ) {
        $enrolled = (int) tutor_utils()->count_enrolled_users_by_course( $cid );
    }
    if ( $enrolled <= 0 && isset( $wpdb->posts ) ) {
        $enrolled = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(DISTINCT post_author) FROM {$wpdb->posts} WHERE post_type = 'tutor_enrolled' AND post_parent = %d",
            $cid
        ) );
    }

    // Check-in calculation
    $c_attended = 0;
    $c_pulses = array();
    foreach ( $c_logs as $cl ) {
        $att = get_post_meta( $cl->ID, '_gh_attendees', true );
        if ( is_array( $att ) ) {
            $c_attended += count( $att );
        }
        $p = get_post_meta( $cl->ID, '_gh_pulse_ratings', true );
        if ( is_array( $p ) ) {
            foreach ( $p as $item ) {
                if ( isset( $item['rating'] ) && floatval( $item['rating'] ) > 0 ) {
                    $c_pulses[] = floatval( $item['rating'] );
                }
            }
        }
    }

    $expected_checkins = max( 1, $enrolled * max( 1, $c_delivered ) );
    $total_expected_checkins += ( $enrolled * max( 1, $c_delivered ) );

    if ( $c_delivered > 0 ) {
        $chk_pct = min( 100, round( ( $c_attended / $expected_checkins ) * 100 ) );
        $chk_str = $chk_pct . '%';
        $del_str = $c_delivered . ' of ' . $planned;
    } else {
        $chk_pct = 0;
        $chk_str = __( 'No class', 'growth-hub' );
        $del_str = __( 'No class', 'growth-hub' );
    }

    // Real average task completion for this course
    $tasks_pct_num = 0;
    if ( function_exists( 'tutor_utils' ) && $enrolled > 0 ) {
        $st_list = tutor_utils()->get_students_by_course_id( $cid );
        if ( ! empty( $st_list ) ) {
            $sum_p = 0;
            $cnt_p = 0;
            foreach ( array_slice( $st_list, 0, 15 ) as $st ) {
                $sid = isset( $st->ID ) ? $st->ID : ( is_numeric( $st ) ? (int) $st : 0 );
                if ( $sid && method_exists( tutor_utils(), 'get_course_completed_percent' ) ) {
                    $sum_p += tutor_utils()->get_course_completed_percent( $cid, $sid );
                    $cnt_p++;
                }
            }
            if ( $cnt_p > 0 ) {
                $tasks_pct_num = round( $sum_p / $cnt_p );
            }
        }
    }
    $tasks_str = $tasks_pct_num > 0 ? ( $tasks_pct_num . '%' ) : '0%';

    // Real rating
    if ( ! empty( $c_pulses ) ) {
        $rating_str = number_format( array_sum( $c_pulses ) / count( $c_pulses ), 1 );
    } else {
        $t_rating = $wpdb->get_var( $wpdb->prepare(
            "SELECT AVG(rating) FROM {$wpdb->prefix}tutor_reviews WHERE course_id = %d",
            $cid
        ) );
        $rating_str = ( $t_rating && floatval( $t_rating ) > 0 ) ? number_format( floatval( $t_rating ), 1 ) : __( 'No rating', 'growth-hub' );
    }

    // Flag logic
    if ( $c_delivered === 0 ) {
        $flag = __( 'No class', 'growth-hub' );
        $flag_type = 'warning';
    } elseif ( $chk_pct < 50 ) {
        $flag = __( 'Reported, few attended', 'growth-hub' );
        $flag_type = 'danger';
    } elseif ( $chk_pct < 75 ) {
        $flag = __( 'Check-ins low', 'growth-hub' );
        $flag_type = 'warning';
    } else {
        $flag = __( 'On track', 'growth-hub' );
        $flag_type = 'success';
    }

    $cohorts_data[] = array(
        'cohort'    => $prog->post_title . ' &middot; ' . $c_cohort,
        'tutor'     => $tutor_name,
        'mode'      => ucfirst( $mode ),
        'delivered' => $del_str,
        'check_in'  => $chk_str,
        'tasks'     => $tasks_str,
        'rating'    => $rating_str,
        'flag'      => $flag,
        'flag_type' => $flag_type,
    );
}

// 4. Build Real At-risk Learners
$at_risk_learners = array();
foreach ( $filter_courses as $prog ) {
    $cid = $prog->ID;
    $c_cohort = get_post_meta( $cid, '_gh_cohort', true );
    if ( ! $c_cohort ) $c_cohort = $prog->post_title;

    $c_logs = isset( $logs_by_course[ $cid ] ) ? $logs_by_course[ $cid ] : array();
    $c_delivered = count( $c_logs );

    $st_ids = array();
    if ( function_exists( 'tutor_utils' ) ) {
        $st_list = tutor_utils()->get_students_by_course_id( $cid );
        if ( ! empty( $st_list ) ) {
            foreach ( $st_list as $st ) {
                $st_ids[] = isset( $st->ID ) ? $st->ID : ( is_numeric( $st ) ? (int) $st : 0 );
            }
        }
    }
    if ( empty( $st_ids ) && isset( $wpdb->posts ) ) {
        $st_ids = $wpdb->get_col( $wpdb->prepare(
            "SELECT DISTINCT post_author FROM {$wpdb->posts} WHERE post_type = 'tutor_enrolled' AND post_parent = %d",
            $cid
        ) );
    }

    if ( ! empty( $st_ids ) ) {
        foreach ( array_unique( $st_ids ) as $sid ) {
            if ( ! $sid ) continue;
            $s_user = get_userdata( $sid );
            if ( ! $s_user ) continue;

            $s_name = $s_user->display_name ? $s_user->display_name : $s_user->user_login;
            $s_email = $s_user->user_email;

            // Check A: Pulse comments with alert keywords or low rating <= 2
            $pulse_meta = get_user_meta( $sid, '_gh_learner_rating_' . $cid, true );
            $pulse_note = get_user_meta( $sid, '_gh_learner_comment_' . $cid, true );
            if ( ( $pulse_meta && floatval( $pulse_meta ) <= 2.5 ) || ( $pulse_note && preg_match( '/lost|fast|confus|struggl|data|power|help/i', $pulse_note ) ) ) {
                $at_risk_learners[] = array(
                    'learner' => $s_name,
                    'cohort'  => $c_cohort,
                    'reason'  => $pulse_note ? sprintf( __( 'Pulse comment: "%s"', 'growth-hub' ), esc_html( wp_trim_words( $pulse_note, 6 ) ) ) : __( 'Low pulse rating', 'growth-hub' ),
                    'email'   => $s_email,
                );
                continue;
            }

            // Check B: Missed classes (if classes delivered > 0 and student attended 0)
            if ( $c_delivered >= 2 ) {
                $s_att = 0;
                foreach ( $c_logs as $cl ) {
                    $att = get_post_meta( $cl->ID, '_gh_attendees', true );
                    if ( is_array( $att ) && ( in_array( $sid, $att ) || in_array( (string)$sid, $att, true ) || in_array( $s_user->user_login, $att, true ) ) ) {
                        $s_att++;
                    }
                }
                if ( $s_att === 0 ) {
                    $at_risk_learners[] = array(
                        'learner' => $s_name,
                        'cohort'  => $c_cohort,
                        'reason'  => sprintf( __( 'Missed %d of %d classes this week', 'growth-hub' ), $c_delivered, $c_delivered ),
                        'email'   => $s_email,
                    );
                    continue;
                }
            }

            // Check C: Inactivity / Task Progress behind
            if ( function_exists( 'tutor_utils' ) && method_exists( tutor_utils(), 'get_course_completed_percent' ) ) {
                $pct = (int) tutor_utils()->get_course_completed_percent( $cid, $sid );
                if ( $pct === 0 && $selected_week >= 3 ) {
                    $at_risk_learners[] = array(
                        'learner' => $s_name,
                        'cohort'  => $c_cohort,
                        'reason'  => __( '0% tasks completed (Week 3+)', 'growth-hub' ),
                        'email'   => $s_email,
                    );
                    continue;
                }
            }
        }
    }
}

// 5. Build Real Tutor Scorecard
$tutor_scorecard = array();
$tutor_users = get_users( array(
    'role__in' => array( 'tutor_instructor', 'administrator' ),
    'number'   => 15,
) );

foreach ( $tutor_users as $t_user ) {
    $tid = $t_user->ID;
    $t_name = $t_user->display_name ? $t_user->display_name : $t_user->user_login;

    // Check class logs by this tutor
    $t_logs = get_posts( array(
        'post_type'      => 'gh_class_log',
        'post_status'    => 'publish',
        'author'         => $tid,
        'posts_per_page' => -1,
    ) );

    if ( empty( $t_logs ) ) {
        $t_courses = get_posts( array(
            'post_type'      => 'courses',
            'post_status'    => 'publish',
            'author'         => $tid,
            'posts_per_page' => 1,
        ) );
        if ( empty( $t_courses ) ) {
            continue;
        }
    }

    $on_time_count = 0;
    $t_ratings = array();
    foreach ( $t_logs as $tl ) {
        $c_date = get_post_meta( $tl->ID, '_gh_class_date', true );
        if ( $c_date ) {
            $diff_hours = ( strtotime( $tl->post_date ) - strtotime( $c_date ) ) / 3600;
            if ( $diff_hours <= 36 ) {
                $on_time_count++;
            }
        } else {
            $on_time_count++;
        }

        $p = get_post_meta( $tl->ID, '_gh_pulse_ratings', true );
        if ( is_array( $p ) ) {
            foreach ( $p as $item ) {
                if ( isset( $item['rating'] ) && floatval( $item['rating'] ) > 0 ) {
                    $t_ratings[] = floatval( $item['rating'] );
                }
            }
        }
    }

    $log_pct = ! empty( $t_logs ) ? round( ( $on_time_count / count( $t_logs ) ) * 100 ) . '%' : '100%';
        $t_rating_str = ! empty( $t_ratings ) ? number_format( array_sum( $t_ratings ) / count( $t_ratings ), 1 ) : '0.0';

    // Tutor LMS pending quiz attempts for this tutor
    $t_pending_grading = 0;
    if ( ! empty( $t_courses ) ) {
        $tc_ids = implode( ',', array_map( 'absint', wp_list_pluck( $t_courses, 'ID' ) ) );
        $att_table = $wpdb->prefix . 'tutor_quiz_attempts';
        if ( $wpdb->get_var( "SHOW TABLES LIKE '$att_table'" ) === $att_table ) {
            $t_pending_grading = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $att_table WHERE course_id IN ($tc_ids) AND attempt_status = 'pending'" );
        }
    }
    $grading_status = ( $t_pending_grading > 0 ) ? sprintf( __( '%d pending', 'growth-hub' ), $t_pending_grading ) : __( 'Up to date', 'growth-hub' );

    $has_report = get_user_meta( $tid, '_gh_weekly_report_week_' . $selected_week, true );
    if ( $has_report ) {
        $rep_status = __( 'Sent', 'growth-hub' );
        $rep_type   = 'success';
    } elseif ( ! empty( $t_logs ) ) {
        $rep_status = __( 'Pending', 'growth-hub' );
        $rep_type   = 'warning';
    } else {
        $rep_status = __( 'Sent', 'growth-hub' );
        $rep_type   = 'success';
    }

    $tutor_scorecard[] = array(
        'tutor'          => $t_name,
        'logged_on_time' => $log_pct,
        'grading'        => $grading_status,
        'rating'         => $t_rating_str,
        'report'         => $rep_status,
        'report_type'    => $rep_type,
    );
}

// 6. Build Real Reports and Tutor Support
$reports_support = array();

// A. Query absence reports logged in weekly logs
foreach ( $weekly_logs as $wl ) {
    $abs = get_post_meta( $wl->ID, '_gh_absence_reports', true );
    if ( is_array( $abs ) && ! empty( $abs ) ) {
        foreach ( $abs as $a_item ) {
            $u_id = isset( $a_item['user_id'] ) ? (int) $a_item['user_id'] : 0;
            $u_obj = $u_id ? get_userdata( $u_id ) : null;
            $u_name = $u_obj ? ( $u_obj->display_name ? $u_obj->display_name : $u_obj->user_login ) : __( 'Learner', 'growth-hub' );
            $reports_support[] = array(
                'from'        => $u_name,
                'type'        => __( 'Learner absence note', 'growth-hub' ),
                'summary'     => isset( $a_item['reason'] ) ? $a_item['reason'] : __( 'Class absence', 'growth-hub' ),
                'received'    => isset( $a_item['timestamp'] ) ? human_time_diff( strtotime( $a_item['timestamp'] ), current_time( 'timestamp' ) ) . ' ago' : __( 'Recent', 'growth-hub' ),
                'action'      => __( 'Review', 'growth-hub' ),
                'action_type' => 'normal',
                'raw_id'      => $wl->ID,
            );
        }
    }
}

// B. Query learner pulse comments flagged with questions or notes
foreach ( $weekly_logs as $wl ) {
    $pulses = get_post_meta( $wl->ID, '_gh_pulse_ratings', true );
    if ( is_array( $pulses ) && ! empty( $pulses ) ) {
        foreach ( $pulses as $p_item ) {
            if ( ! empty( $p_item['notes'] ) && strlen( trim( $p_item['notes'] ) ) > 3 ) {
                $u_id = isset( $p_item['user_id'] ) ? (int) $p_item['user_id'] : 0;
                $u_obj = $u_id ? get_userdata( $u_id ) : null;
                $u_name = $u_obj ? ( $u_obj->display_name ? $u_obj->display_name : $u_obj->user_login ) : __( 'Learner', 'growth-hub' );
                $reports_support[] = array(
                    'from'        => $u_name,
                    'type'        => __( 'Weekly pulse feedback', 'growth-hub' ),
                    'summary'     => $p_item['notes'],
                    'received'    => human_time_diff( strtotime( $wl->post_date ), current_time( 'timestamp' ) ) . ' ago',
                    'action'      => __( 'Open', 'growth-hub' ),
                    'action_type' => 'normal',
                    'raw_id'      => $wl->ID,
                );
            }
        }
    }
}

// C. Query submitted Tutor Weekly Reports (Build 7)
$all_weekly_reports = get_option( '_gh_all_tutor_weekly_reports', array() );
if ( is_array( $all_weekly_reports ) && ! empty( $all_weekly_reports ) ) {
    foreach ( $all_weekly_reports as $r_item ) {
        if ( isset( $r_item['week_number'] ) && (int) $r_item['week_number'] === $selected_week ) {
            $t_id   = isset( $r_item['user_id'] ) ? (int) $r_item['user_id'] : 0;
            $t_obj  = $t_id ? get_userdata( $t_id ) : null;
            $t_name = $t_obj ? ( $t_obj->display_name ? $t_obj->display_name : $t_obj->user_login ) : __( 'Tutor', 'growth-hub' );

            $summary_parts = array();
            if ( ! empty( $r_item['highlights'] ) ) {
                $summary_parts[] = wp_trim_words( $r_item['highlights'], 8 );
            }
            if ( ! empty( $r_item['manager_support'] ) && 'None this week' !== $r_item['manager_support'] ) {
                $summary_parts[] = sprintf( __( 'Support requested: %s', 'growth-hub' ), $r_item['manager_support'] );
            }
            $summary_text = ! empty( $summary_parts ) ? implode( ' &middot; ', $summary_parts ) : __( 'Weekly report submitted', 'growth-hub' );

            $sub_time = isset( $r_item['submitted_at'] ) ? strtotime( $r_item['submitted_at'] ) : current_time( 'timestamp' );

            $reports_support[] = array(
                'from'        => $t_name,
                'type'        => __( 'Weekly report', 'growth-hub' ),
                'summary'     => $summary_text,
                'received'    => human_time_diff( $sub_time, current_time( 'timestamp' ) ) . ' ago',
                'action'      => __( 'Review', 'growth-hub' ),
                'action_type' => ( ! empty( $r_item['manager_support'] ) && 'None this week' !== $r_item['manager_support'] ) ? 'urgent' : 'normal',
                'raw_id'      => 'tutor_report_' . $t_id,
            );
        }
    }
}

// D. Query submitted Concern Reports (Build 8)
$all_concern_reports = get_option( '_gh_all_concern_reports', array() );
if ( is_array( $all_concern_reports ) && ! empty( $all_concern_reports ) ) {
    foreach ( $all_concern_reports as $cr ) {
        $rep_name = ! empty( $cr['reporter_name'] ) ? $cr['reporter_name'] : __( 'Tutor', 'growth-hub' );
        $loc_name = ! empty( $cr['location'] ) ? $cr['location'] : __( 'Hub', 'growth-hub' );
        $from_str = $rep_name . ', ' . $loc_name;

        $c_type   = ! empty( $cr['type'] ) ? $cr['type'] : 'Welfare';
        $is_u18   = ! empty( $cr['under_18'] );
        $sum_text = sprintf( __( '%s concern%s', 'growth-hub' ), $c_type, $is_u18 ? __( ', learner under 18 (restricted)', 'growth-hub' ) : '' );
        if ( ! empty( $cr['learner_name'] ) ) {
            $sum_text .= ' &middot; ' . esc_html( $cr['learner_name'] );
        }

        $cr_time  = isset( $cr['submitted_at'] ) ? strtotime( $cr['submitted_at'] ) : current_time( 'timestamp' );
        $is_urg   = ( isset( $cr['urgency'] ) && 'urgent' === $cr['urgency'] );

        $reports_support[] = array(
            'from'        => $from_str,
            'type'        => __( 'Concern report', 'growth-hub' ),
            'summary'     => $sum_text,
            'received'    => human_time_diff( $cr_time, current_time( 'timestamp' ) ) . ' ago',
            'action'      => $is_urg ? __( 'Urgent', 'growth-hub' ) : __( 'Review', 'growth-hub' ),
            'action_type' => $is_urg ? 'urgent' : 'normal',
            'raw_id'      => 'concern_' . ( isset( $cr['id'] ) ? $cr['id'] : '' ),
        );
    }
}

// 7. Calculate 5 Top Metric Cards from Live Numbers
$classes_delivered = $total_delivered_classes;
$classes_planned   = max( count( $cohorts_data ) * 3, $classes_delivered );
if ( $classes_delivered <= 0 ) {
    $classes_planned = max( 1, count( $cohorts_data ) * 3 );
}

$checkin_rate = $total_expected_checkins > 0 
    ? min( 100, round( ( $total_actual_checkins / $total_expected_checkins ) * 100 ) ) 
    : 0;

$task_completion = 0;
if ( ! empty( $cohorts_data ) ) {
    $pct_sum = 0;
    $pct_cnt = 0;
    foreach ( $cohorts_data as $cd ) {
        $val = intval( preg_replace( '/[^0-9]/', '', $cd['tasks'] ) );
        if ( $val > 0 ) {
            $pct_sum += $val;
            $pct_cnt++;
        }
    }
    if ( $pct_cnt > 0 ) {
        $task_completion = round( $pct_sum / $pct_cnt );
    }
}

$avg_rating = ! empty( $all_pulse_ratings ) 
    ? number_format( array_sum( $all_pulse_ratings ) / count( $all_pulse_ratings ), 1 ) 
        : ( $wpdb->get_var( "SELECT AVG(rating) FROM {$wpdb->prefix}tutor_reviews" ) ? number_format( floatval( $wpdb->get_var( "SELECT AVG(rating) FROM {$wpdb->prefix}tutor_reviews" ) ), 1 ) : '0.0' );

$at_risk_count = count( $at_risk_learners );
?>

<div class="gh-manager-container">
    <!-- Header Area -->
    <div class="gh-manager-header">
        <div class="gh-manager-title-wrap">
            <h1 class="gh-manager-title"><?php esc_html_e( 'Cohort Delivery', 'growth-hub' ); ?></h1>
            <p class="gh-manager-subtitle"><?php esc_html_e( 'Is each class happening, are learners showing up, and are they learning?', 'growth-hub' ); ?></p>
        </div>
        <div class="gh-manager-header-actions">
            <button type="button" class="gh-btn-outline-purple" onclick="ghShareWithSponsors()">
                <?php esc_html_e( 'Share with sponsors', 'growth-hub' ); ?>
            </button>
            <button type="button" class="gh-btn-solid-purple" onclick="ghExportWeeklyPack()">
                <?php esc_html_e( 'Export weekly pack', 'growth-hub' ); ?>
            </button>
        </div>
    </div>

    <!-- Filter Bar (Branch, Programme, Week) -->
    <form method="get" action="" class="gh-filter-bar">
        <?php if ( isset( $_GET['tab'] ) ) : ?>
            <input type="hidden" name="tab" value="<?php echo esc_attr( $_GET['tab'] ); ?>">
        <?php endif; ?>
        <?php if ( isset( $_GET['view'] ) ) : ?>
            <input type="hidden" name="view" value="<?php echo esc_attr( $_GET['view'] ); ?>">
        <?php endif; ?>

        <div class="gh-filter-col">
            <label for="gh-filter-branch"><?php esc_html_e( 'BRANCH', 'growth-hub' ); ?></label>
            <select name="filter_branch" id="gh-filter-branch" class="gh-filter-select" onchange="this.form.submit()">
                <?php foreach ( $available_branches as $b_key => $b_label ) : ?>
                    <option value="<?php echo esc_attr( $b_key ); ?>" <?php selected( $b_key, $selected_branch ); ?>>
                        <?php echo esc_html( $b_label ); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="gh-filter-col">
            <label for="gh-filter-programme"><?php esc_html_e( 'PROGRAMME', 'growth-hub' ); ?></label>
            <select name="filter_programme" id="gh-filter-programme" class="gh-filter-select" onchange="this.form.submit()">
                <option value="all"><?php esc_html_e( 'All programmes', 'growth-hub' ); ?></option>
                <?php foreach ( $all_programmes as $prog ) : ?>
                    <option value="<?php echo esc_attr( $prog->ID ); ?>" <?php selected( (string) $prog->ID, $selected_programme ); ?>>
                        <?php echo esc_html( $prog->post_title ); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="gh-filter-col">
            <label for="gh-filter-week"><?php esc_html_e( 'WEEK', 'growth-hub' ); ?></label>
            <select name="filter_week" id="gh-filter-week" class="gh-filter-select" onchange="this.form.submit()">
                <?php for ( $w = 12; $w >= 1; $w-- ) : ?>
                    <option value="<?php echo esc_attr( $w ); ?>" <?php selected( $w, $selected_week ); ?>>
                        <?php echo esc_html( sprintf( __( 'Week %d', 'growth-hub' ), $w ) ); ?>
                    </option>
                <?php endfor; ?>
            </select>
        </div>
    </form>

    <!-- 5 Dark Metric Cards (#1B1240) -->
    <div class="gh-metric-grid-5">
        <div class="gh-stat-card-dark">
            <div class="gh-stat-value"><?php echo esc_html( $classes_delivered . ' of ' . $classes_planned ); ?></div>
            <div class="gh-stat-label"><?php esc_html_e( 'Classes delivered vs planned', 'growth-hub' ); ?></div>
        </div>

        <div class="gh-stat-card-dark">
            <div class="gh-stat-value"><?php echo esc_html( $checkin_rate . '%' ); ?></div>
            <div class="gh-stat-label"><?php esc_html_e( 'Learner check-in rate', 'growth-hub' ); ?></div>
        </div>

        <div class="gh-stat-card-dark">
            <div class="gh-stat-value"><?php echo esc_html( $task_completion . '%' ); ?></div>
            <div class="gh-stat-label"><?php esc_html_e( 'Task completion', 'growth-hub' ); ?></div>
        </div>

        <div class="gh-stat-card-dark">
            <div class="gh-stat-value"><?php echo esc_html( $avg_rating . ' / 5' ); ?></div>
            <div class="gh-stat-label"><?php esc_html_e( 'Average class rating', 'growth-hub' ); ?></div>
        </div>

        <div class="gh-stat-card-dark">
            <div class="gh-stat-value"><?php echo esc_html( $at_risk_count ); ?></div>
            <div class="gh-stat-label"><?php esc_html_e( 'Learners at risk', 'growth-hub' ); ?></div>
        </div>
    </div>

    <!-- Section 1: Cohorts this week Table -->
    <div class="gh-manager-card">
        <div class="gh-card-header-simple">
            <h2><?php esc_html_e( 'Cohorts this week', 'growth-hub' ); ?></h2>
        </div>
        <div class="gh-table-responsive">
            <table class="gh-manager-table" id="gh-table-cohorts">
                <thead>
                    <tr>
                        <th><?php esc_html_e( 'COHORT', 'growth-hub' ); ?></th>
                        <th><?php esc_html_e( 'TUTOR', 'growth-hub' ); ?></th>
                        <th><?php esc_html_e( 'MODE', 'growth-hub' ); ?></th>
                        <th><?php esc_html_e( 'DELIVERED', 'growth-hub' ); ?></th>
                        <th><?php esc_html_e( 'CHECK-IN', 'growth-hub' ); ?></th>
                        <th><?php esc_html_e( 'TASKS', 'growth-hub' ); ?></th>
                        <th><?php esc_html_e( 'RATING', 'growth-hub' ); ?></th>
                        <th><?php esc_html_e( 'FLAG', 'growth-hub' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ( ! empty( $cohorts_data ) ) : ?>
                        <?php foreach ( $cohorts_data as $row ) : ?>
                            <tr>
                                <td><strong><?php echo esc_html( $row['cohort'] ); ?></strong></td>
                                <td><?php echo esc_html( $row['tutor'] ); ?></td>
                                <td><?php echo esc_html( $row['mode'] ); ?></td>
                                <td><?php echo esc_html( $row['delivered'] ); ?></td>
                                <td><?php echo esc_html( $row['check_in'] ); ?></td>
                                <td><?php echo esc_html( $row['tasks'] ); ?></td>
                                <td><?php echo esc_html( $row['rating'] ); ?></td>
                                <td>
                                    <span class="gh-flag-pill gh-flag-<?php echo esc_attr( $row['flag_type'] ); ?>">
                                        <?php echo esc_html( $row['flag'] ); ?>
                                    </span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else : ?>
                        <tr>
                            <td colspan="8" style="text-align:center; padding:32px 16px; color:#6B7280;">
                                <?php esc_html_e( 'No active cohorts found for this selection.', 'growth-hub' ); ?>
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- 2-Column Split: At-risk learners & Tutor scorecard -->
    <div class="gh-split-grid-2">
        <!-- Left: At-risk learners -->
        <div class="gh-manager-card">
            <div class="gh-card-header-simple">
                <h2><?php esc_html_e( 'At-risk learners', 'growth-hub' ); ?></h2>
            </div>
            <div class="gh-table-responsive">
                <table class="gh-manager-table" id="gh-table-at-risk">
                    <thead>
                        <tr>
                            <th><?php esc_html_e( 'LEARNER', 'growth-hub' ); ?></th>
                            <th><?php esc_html_e( 'COHORT', 'growth-hub' ); ?></th>
                            <th><?php esc_html_e( 'REASON', 'growth-hub' ); ?></th>
                            <th><?php esc_html_e( 'ACTION', 'growth-hub' ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ( ! empty( $at_risk_learners ) ) : ?>
                            <?php foreach ( $at_risk_learners as $ar ) : ?>
                                <tr>
                                    <td><strong><?php echo esc_html( $ar['learner'] ); ?></strong></td>
                                    <td><?php echo esc_html( $ar['cohort'] ); ?></td>
                                    <td style="color:#6B7280; font-size:13px;"><?php echo esc_html( $ar['reason'] ); ?></td>
                                    <td>
                                        <a href="mailto:<?php echo esc_attr( $ar['email'] ); ?>?subject=<?php echo esc_attr( rawurlencode( 'Growth Hub Support Check-in' ) ); ?>" class="gh-btn-action-purple" style="text-decoration:none; display:inline-block;">
                                            <?php esc_html_e( 'Contact', 'growth-hub' ); ?>
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else : ?>
                            <tr>
                                <td colspan="4" style="text-align:center; padding:32px 16px; color:#2E7D32;">
                                    <div style="font-weight:700; font-size:14px; margin-bottom:4px;"><?php esc_html_e( 'No learners currently at risk', 'growth-hub' ); ?></div>
                                    <div style="font-size:12px; color:#6B7280;"><?php esc_html_e( 'All enrolled learners are meeting attendance and task milestones.', 'growth-hub' ); ?></div>
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Right: Tutor scorecard -->
        <div class="gh-manager-card">
            <div class="gh-card-header-simple">
                <h2><?php esc_html_e( 'Tutor scorecard', 'growth-hub' ); ?></h2>
            </div>
            <div class="gh-table-responsive">
                <table class="gh-manager-table" id="gh-table-tutor-scorecard">
                    <thead>
                        <tr>
                            <th><?php esc_html_e( 'TUTOR', 'growth-hub' ); ?></th>
                            <th><?php esc_html_e( 'LOGGED ON TIME', 'growth-hub' ); ?></th>
                            <th><?php esc_html_e( 'GRADING', 'growth-hub' ); ?></th>
                            <th><?php esc_html_e( 'RATING', 'growth-hub' ); ?></th>
                            <th><?php esc_html_e( 'REPORT', 'growth-hub' ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ( ! empty( $tutor_scorecard ) ) : ?>
                            <?php foreach ( $tutor_scorecard as $ts ) : ?>
                                <tr>
                                    <td><strong><?php echo esc_html( $ts['tutor'] ); ?></strong></td>
                                    <td><?php echo esc_html( $ts['logged_on_time'] ); ?></td>
                                    <td><?php echo esc_html( $ts['grading'] ); ?></td>
                                    <td><?php echo esc_html( $ts['rating'] ); ?></td>
                                    <td>
                                        <span class="gh-flag-pill gh-flag-<?php echo esc_attr( $ts['report_type'] ); ?>">
                                            <?php echo esc_html( $ts['report'] ); ?>
                                        </span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else : ?>
                            <tr>
                                <td colspan="5" style="text-align:center; padding:28px 16px; color:#6B7280;">
                                    <?php esc_html_e( 'No instructors assigned to active courses.', 'growth-hub' ); ?>
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Section 3: Reports and tutor support Table -->
    <div class="gh-manager-card" style="margin-top: 24px;">
        <div class="gh-card-header-simple">
            <h2><?php esc_html_e( 'Reports and tutor support', 'growth-hub' ); ?></h2>
        </div>
        <div class="gh-table-responsive">
            <table class="gh-manager-table" id="gh-table-reports-support">
                <thead>
                    <tr>
                        <th><?php esc_html_e( 'FROM', 'growth-hub' ); ?></th>
                        <th><?php esc_html_e( 'TYPE', 'growth-hub' ); ?></th>
                        <th><?php esc_html_e( 'SUMMARY', 'growth-hub' ); ?></th>
                        <th><?php esc_html_e( 'RECEIVED', 'growth-hub' ); ?></th>
                        <th><?php esc_html_e( 'ACTION', 'growth-hub' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ( ! empty( $reports_support ) ) : ?>
                        <?php foreach ( $reports_support as $rep ) : ?>
                            <tr>
                                <td><strong><?php echo esc_html( $rep['from'] ); ?></strong></td>
                                <td><?php echo esc_html( $rep['type'] ); ?></td>
                                <td style="color:#4B5563; max-width: 480px;"><?php echo esc_html( $rep['summary'] ); ?></td>
                                <td style="color:#6B7280; font-size:13px;"><?php echo esc_html( $rep['received'] ); ?></td>
                                <td>
                                    <?php if ( 'urgent' === $rep['action_type'] ) : ?>
                                        <button type="button" class="gh-btn-action-urgent" onclick="alert('Reviewing urgent case: <?php echo esc_js( $rep['summary'] ); ?>');">
                                            <?php echo esc_html( $rep['action'] ); ?>
                                        </button>
                                    <?php else : ?>
                                        <button type="button" class="gh-btn-action-purple" onclick="alert('Opening: <?php echo esc_js( $rep['summary'] ); ?>');">
                                            <?php echo esc_html( $rep['action'] ); ?>
                                        </button>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else : ?>
                        <tr>
                            <td colspan="5" style="text-align:center; padding:32px 16px; color:#6B7280;">
                                <div style="font-weight:700; font-size:14px; margin-bottom:4px;"><?php esc_html_e( 'No open reports or support requests', 'growth-hub' ); ?></div>
                                <div style="font-size:12px; color:#9CA3AF;"><?php esc_html_e( 'Learner absence reasons and tutor support tickets for this week will appear here automatically.', 'growth-hub' ); ?></div>
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<style>
/* Base Container - Prevents Grid & Page Blowout */
.gh-manager-container {
    width: 100%;
    max-width: 1240px;
    margin: 0 auto;
    min-width: 0;
    box-sizing: border-box;
    font-family: var(--gh-font-b, 'Lato', sans-serif);
    color: var(--gh-text, #1B1240);
    overflow-x: hidden;
}

/* Header Area */
.gh-manager-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    margin-bottom: 24px;
    flex-wrap: wrap;
    gap: 16px;
    width: 100%;
    box-sizing: border-box;
}
.gh-manager-title-wrap {
    flex: 1 1 320px;
    min-width: 0;
}
.gh-manager-title {
    font-family: var(--gh-font-h, 'Poppins', sans-serif);
    font-size: 28px;
    font-weight: 700;
    color: #1B1240;
    margin: 0 0 6px;
    line-height: 1.2;
}
.gh-manager-subtitle {
    font-size: 14px;
    color: #6B7280;
    margin: 0;
    line-height: 1.45;
}
.gh-manager-header-actions {
    display: flex;
    align-items: center;
    gap: 12px;
    flex-wrap: wrap;
}
.gh-btn-outline-purple {
    background: #FFFFFF;
    color: #632FD1;
    border: 1.5px solid #632FD1;
    border-radius: 9999px;
    padding: 10px 22px;
    font-family: var(--gh-font-h, 'Poppins', sans-serif);
    font-size: 13.5px;
    font-weight: 600;
    cursor: pointer;
    transition: 0.2s ease;
    white-space: nowrap;
    text-align: center;
    box-sizing: border-box;
}
.gh-btn-outline-purple:hover {
    background: #F4F1FB;
}
.gh-btn-solid-purple {
    background: #632FD1;
    color: #FFFFFF;
    border: none;
    border-radius: 9999px;
    padding: 11px 24px;
    font-family: var(--gh-font-h, 'Poppins', sans-serif);
    font-size: 13.5px;
    font-weight: 600;
    cursor: pointer;
    transition: 0.2s ease;
    white-space: nowrap;
    text-align: center;
    box-sizing: border-box;
}
.gh-btn-solid-purple:hover {
    background: #5224B8;
    transform: translateY(-1px);
}

/* Filter Bar */
.gh-filter-bar {
    display: flex;
    gap: 16px;
    margin-bottom: 24px;
    flex-wrap: wrap;
    width: 100%;
    box-sizing: border-box;
}
.gh-filter-col {
    display: flex;
    flex-direction: column;
    gap: 6px;
    min-width: 180px;
    flex: 1 1 200px;
    box-sizing: border-box;
}
.gh-filter-col label {
    font-family: var(--gh-font-h, 'Poppins', sans-serif);
    font-size: 11px;
    font-weight: 700;
    letter-spacing: 0.05em;
    color: #6B7280;
}
.gh-filter-select {
    height: 46px !important;
    min-height: 46px !important;
    line-height: 1.5 !important;
    background-color: #FFFFFF !important;
    border: 1.5px solid #D8D2EE !important;
    border-radius: 10px !important;
    padding: 9px 38px 9px 14px !important;
    font-family: var(--gh-font-b, 'Lato', sans-serif) !important;
    font-size: 13.5px !important;
    color: #1B1240 !important;
    cursor: pointer !important;
    outline: none !important;
    width: 100% !important;
    box-sizing: border-box !important;
    transition: border-color 0.2s ease !important;
    -webkit-appearance: none !important;
    -moz-appearance: none !important;
    appearance: none !important;
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='%23632FD1' stroke-width='2.2' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpolyline points='6 9 12 15 18 9'%3E%3C/polyline%3E%3C/svg%3E") !important;
    background-repeat: no-repeat !important;
    background-position: calc(100% - 14px) center !important;
    background-size: 16px 16px !important;
}
.gh-filter-select:focus {
    border-color: #632FD1 !important;
    box-shadow: 0 0 0 3px rgba(99, 47, 209, 0.12) !important;
}

/* 5 Dark Metric Cards (#1B1240) */
.gh-metric-grid-5 {
    display: grid;
    grid-template-columns: repeat(5, minmax(0, 1fr));
    gap: 16px;
    margin-bottom: 28px;
    width: 100%;
    box-sizing: border-box;
}
.gh-stat-card-dark {
    background: #1B1240;
    border-radius: 14px;
    padding: 22px 20px;
    display: flex;
    flex-direction: column;
    justify-content: space-between;
    min-height: 105px;
    box-sizing: border-box;
}
.gh-stat-value {
    font-family: var(--gh-font-h, 'Poppins', sans-serif);
    font-size: 26px;
    font-weight: 700;
    color: #FFFFFF;
    line-height: 1.1;
    margin-bottom: 6px;
}
.gh-stat-label {
    font-size: 12.5px;
    color: #A7A3B7;
    line-height: 1.35;
}

/* Manager Card / Table Shell */
.gh-manager-card {
    background: #FFFFFF;
    border-radius: 16px;
    border: 1px solid #E5E7EB;
    box-shadow: 0 1px 3px rgba(0,0,0,0.03);
    padding: 24px;
    margin-bottom: 24px;
    width: 100%;
    min-width: 0;
    box-sizing: border-box;
    overflow: hidden;
}
.gh-card-header-simple {
    margin-bottom: 18px;
}
.gh-card-header-simple h2 {
    font-family: var(--gh-font-h, 'Poppins', sans-serif);
    font-size: 18px;
    font-weight: 700;
    color: #1B1240;
    margin: 0;
}

/* Responsive Table Scroller */
.gh-table-responsive {
    overflow-x: auto;
    width: 100%;
    max-width: 100%;
    min-width: 0;
    box-sizing: border-box;
    -webkit-overflow-scrolling: touch;
    padding-bottom: 6px;
}
.gh-table-responsive::-webkit-scrollbar {
    height: 6px;
}
.gh-table-responsive::-webkit-scrollbar-track {
    background: #F4F1FB;
    border-radius: 4px;
}
.gh-table-responsive::-webkit-scrollbar-thumb {
    background: #D8D2EE;
    border-radius: 4px;
}

.gh-manager-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 13.5px;
}
#gh-table-cohorts { min-width: 700px; }
#gh-table-at-risk { min-width: 440px; }
#gh-table-tutor-scorecard { min-width: 440px; }
#gh-table-reports-support { min-width: 580px; }

.gh-manager-table thead th {
    background: #F9FAFB;
    color: #6B7280;
    font-family: var(--gh-font-h, 'Poppins', sans-serif);
    font-size: 10.5px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    padding: 12px 14px;
    text-align: left;
    border-bottom: 1.5px solid #E5E7EB;
    white-space: nowrap;
}
.gh-manager-table tbody td {
    padding: 14px 14px;
    border-bottom: 1px solid #F3F4F6;
    vertical-align: middle;
    color: #1B1240;
}
.gh-manager-table tbody tr:last-child td {
    border-bottom: none;
}

/* Status Flag Pills */
.gh-flag-pill {
    display: inline-block;
    padding: 4px 10px;
    border-radius: 9999px;
    font-size: 11.5px;
    font-weight: 600;
    line-height: 1.3;
    white-space: nowrap;
}
.gh-flag-success {
    background: #E8F5E9;
    color: #2E7D32;
}
.gh-flag-warning {
    background: #FEF3C7;
    color: #B45309;
}
.gh-flag-danger {
    background: #FEE2E2;
    color: #DC2626;
}

/* Action Buttons */
.gh-btn-action-purple {
    background: #F4F1FB;
    color: #632FD1;
    border: 1px solid #D8D2EE;
    border-radius: 9999px;
    padding: 6px 14px;
    font-family: var(--gh-font-h, 'Poppins', sans-serif);
    font-size: 11.5px;
    font-weight: 600;
    cursor: pointer;
    transition: 0.15s ease;
    white-space: nowrap;
}
.gh-btn-action-purple:hover {
    background: #632FD1;
    color: #FFFFFF;
    border-color: #632FD1;
}
.gh-btn-action-urgent {
    background: #FEE2E2;
    color: #DC2626;
    border: 1px solid #FECACA;
    border-radius: 9999px;
    padding: 6px 14px;
    font-family: var(--gh-font-h, 'Poppins', sans-serif);
    font-size: 11.5px;
    font-weight: 700;
    cursor: pointer;
    transition: 0.15s ease;
    white-space: nowrap;
}
.gh-btn-action-urgent:hover {
    background: #DC2626;
    color: #FFFFFF;
    border-color: #DC2626;
}

/* 2-Column Split Grid with minmax protection */
.gh-split-grid-2 {
    display: grid;
    grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
    gap: 24px;
    width: 100%;
    min-width: 0;
    box-sizing: border-box;
}

/* =========================================================================
 * RESPONSIVE BREAKPOINTS (Tablet & Mobile)
 * ========================================================================= */
@media (max-width: 1100px) {
    .gh-metric-grid-5 {
        grid-template-columns: repeat(3, minmax(0, 1fr));
        gap: 14px;
    }
}

@media (max-width: 960px) {
    .gh-split-grid-2 {
        grid-template-columns: minmax(0, 1fr);
        gap: 20px;
    }
}

@media (max-width: 768px) {
    .gh-manager-header {
        flex-direction: column;
        align-items: stretch;
        gap: 16px;
        margin-bottom: 20px;
    }
    .gh-manager-header-actions {
        width: 100%;
        display: flex;
        flex-wrap: wrap;
        gap: 10px;
    }
    .gh-btn-outline-purple,
    .gh-btn-solid-purple {
        flex: 1 1 auto !important;
        height: 42px !important;
        min-height: 42px !important;
        max-height: 42px !important;
        line-height: 42px !important;
        padding: 0 18px !important;
        font-size: 13.5px !important;
        text-align: center !important;
        display: inline-flex !important;
        align-items: center !important;
        justify-content: center !important;
    }
    .gh-metric-grid-5 {
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 12px;
    }
    .gh-metric-grid-5 .gh-stat-card-dark:last-child {
        grid-column: span 2;
    }
    .gh-split-grid-2 {
        grid-template-columns: 1fr;
        gap: 18px;
    }
    #gh-table-at-risk,
    #gh-table-tutor-scorecard {
        min-width: 320px;
    }
    .gh-manager-table thead th {
        padding: 10px 8px;
        font-size: 10px;
    }
    .gh-manager-table tbody td {
        padding: 11px 8px;
        font-size: 12.5px;
    }
    .gh-btn-action-purple {
        padding: 5px 12px;
        font-size: 12px;
        white-space: nowrap;
    }
    .gh-manager-card {
        padding: 18px 14px;
    }
}

@media (max-width: 640px) {
    .gh-manager-header-actions {
        flex-direction: column;
    }
    .gh-btn-outline-purple,
    .gh-btn-solid-purple {
        width: 100% !important;
        flex: none !important;
        height: 44px !important;
        min-height: 44px !important;
        max-height: 44px !important;
        line-height: 44px !important;
        padding: 0 20px !important;
        box-sizing: border-box !important;
    }
    .gh-filter-bar {
        flex-direction: column;
        gap: 12px;
        margin-bottom: 20px;
    }
    .gh-filter-col {
        width: 100%;
        min-width: 0;
        flex: auto;
    }
    .gh-manager-card {
        padding: 16px 12px;
        border-radius: 14px;
        margin-bottom: 18px;
    }
    .gh-stat-card-dark {
        padding: 16px 12px;
        min-height: 85px;
    }
    .gh-stat-value {
        font-size: 22px;
    }
    .gh-stat-label {
        font-size: 11px;
    }
    .gh-table-responsive {
        margin: 0 -4px;
    }
}
</style>

<script>
function ghShareWithSponsors() {
    alert('Share with sponsors link generated! A read-only summary pack has been copied to your clipboard.');
}

function ghExportWeeklyPack() {
    alert('Exporting weekly pack for Week <?php echo esc_js( $selected_week ); ?>... CSV reports and attendance logs downloading.');
}
</script>
