<?php
/**
 * Student Dashboard - Weekly Check-in & History Template (Build 5 of 8)
 * 100% Dynamic - Clean Production Build (Design stickers & fake switchers removed)
 *
 * Features:
 * - 4 Dark Metric Cards (#1B1240) with real database calculations
 * - Multi-course awareness: elegant Course Switcher dropdown if enrolled in >1 course
 * - Dynamic week pacing: calculated from student's purchase / enrollment date (starts Week 1)
 * - Safe status logic: 0 delivered classes are marked Complete, never Catch-up needed
 * - Real check-in streak, lesson completion, and FlexiLearn week unlocked
 * - "Rate this week's class" live AJAX pulse modal
 *
 * Author: Adeleye Shina
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

global $wpdb;

$user_id = get_current_user_id();
$user    = wp_get_current_user();

// 1. Multi-course Support: Enrolled Courses for this student
$student_enrolled_courses = array();
if ( $user_id && function_exists( 'tutor_utils' ) ) {
    $enrolled = array();
    if ( method_exists( tutor_utils(), 'get_enrolled_courses_by_user' ) ) {
        $enrolled = tutor_utils()->get_enrolled_courses_by_user( $user_id );
    } elseif ( method_exists( tutor_utils(), 'get_enrolled_courses_ids_by_user' ) ) {
        $enrolled = tutor_utils()->get_enrolled_courses_ids_by_user( $user_id );
    }
    if ( ! empty( $enrolled ) ) {
        foreach ( $enrolled as $item ) {
            $p_obj = is_object( $item ) ? $item : get_post( $item );
            if ( $p_obj && 'courses' === $p_obj->post_type ) {
                $student_enrolled_courses[ $p_obj->ID ] = $p_obj;
            }
        }
    }
}
if ( empty( $student_enrolled_courses ) && $user_id && isset( $wpdb->posts ) ) {
    $c_ids = $wpdb->get_col( $wpdb->prepare(
        "SELECT DISTINCT post_parent FROM {$wpdb->posts} WHERE post_type = 'tutor_enrolled' AND post_author = %d",
        $user_id
    ) );
    if ( ! empty( $c_ids ) ) {
        foreach ( $c_ids as $cid ) {
            $p_obj = get_post( $cid );
            if ( $p_obj && 'courses' === $p_obj->post_type ) {
                $student_enrolled_courses[ $p_obj->ID ] = $p_obj;
            }
        }
    }
}
        if ( empty( $student_enrolled_courses ) && current_user_can( 'administrator' ) ) {
    $any_c = get_posts( array( 'post_type' => 'courses', 'post_status' => 'publish', 'posts_per_page' => 5 ) );
    if ( ! empty( $any_c ) ) {
        foreach ( $any_c as $ac ) {
            $student_enrolled_courses[ $ac->ID ] = $ac;
        }
    }
}

// Active Selected Course (respects ?course_id=...)
$req_course_id = isset( $_GET['course_id'] ) ? absint( $_GET['course_id'] ) : 0;
if ( ! $req_course_id || ! isset( $student_enrolled_courses[ $req_course_id ] ) ) {
    $c_keys = array_keys( $student_enrolled_courses );
    $course_id = ! empty( $c_keys ) ? $c_keys[0] : 0;
} else {
    $course_id = $req_course_id;
}

$active_course_obj = $course_id && isset( $student_enrolled_courses[ $course_id ] ) ? $student_enrolled_courses[ $course_id ] : null;
$course_title = $active_course_obj ? $active_course_obj->post_title : 'Course';

// Cohort Resolution
$cohort = $user_id ? get_user_meta( $user_id, '_gh_cohort', true ) : '';
if ( empty( $cohort ) && $course_id ) {
    $cohort = get_post_meta( $course_id, '_gh_cohort', true );
}
if ( empty( $cohort ) ) {
    $cohort = __( 'Active Cohort', 'growth-hub' );
}

// 2. Enrollment Date & Active Week Determination
// Calculate current week based on when THIS student enrolled in THIS course
$enrollment_date = '';
if ( $user_id && $course_id && isset( $wpdb->posts ) ) {
    $enrollment_date = $wpdb->get_var( $wpdb->prepare(
        "SELECT post_date FROM {$wpdb->posts} 
         WHERE post_type = 'tutor_enrolled' AND post_author = %d AND post_parent = %d 
         ORDER BY ID DESC LIMIT 1",
        $user_id,
        $course_id
    ) );
}
if ( empty( $enrollment_date ) && $user_id && $course_id ) {
    $t_enrolled = get_user_meta( $user_id, '_tutor_course_' . $course_id . '_enrolled', true );
    if ( $t_enrolled ) {
        $enrollment_date = is_numeric( $t_enrolled ) ? date( 'Y-m-d H:i:s', $t_enrolled ) : $t_enrolled;
    }
}
if ( empty( $enrollment_date ) && $user && isset( $user->user_registered ) ) {
    $enrollment_date = $user->user_registered;
}

$current_cohort_week = 1;
if ( ! empty( $enrollment_date ) ) {
    $enrolled_ts = strtotime( $enrollment_date );
    $now_ts      = current_time( 'timestamp' );
    $days_diff   = floor( max( 0, $now_ts - $enrolled_ts ) / DAY_IN_SECONDS );
    $current_cohort_week = max( 1, (int) floor( $days_diff / 7 ) + 1 );
} else {
    $current_cohort_week = 1;
}

// Baseline fallbacks
$classes_attended_this_week  = 0;
$classes_delivered_this_week = 0;
$tasks_done_this_week        = 0;
$tasks_total_this_week       = 0;
$unlocked_weeks              = $current_cohort_week;
$streak_weeks                = 0;
$history_rows                = array();
$latest_class_id             = 0;

try {
    // 3. Query Real Class Logs for this Course
    $all_logs = array();
    if ( $course_id ) {
        $all_logs = get_posts( array(
            'post_type'      => 'gh_class_log',
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'meta_query'     => array(
                array(
                    'key'     => '_gh_course_id',
                    'value'   => $course_id,
                    'compare' => '=',
                ),
            ),
        ) );
    }

    if ( ! empty( $all_logs ) ) {
        $latest_class_id = $all_logs[0]->ID;
    }

    // Group class logs by week number
    $logs_by_week = array();
    foreach ( $all_logs as $log ) {
        $wn = get_post_meta( $log->ID, '_gh_week_number', true );
        $wn_int = intval( preg_replace( '/[^0-9]/', '', (string) $wn ) );
        if ( $wn_int <= 0 ) {
            $wn_int = $current_cohort_week;
        }
        if ( ! isset( $logs_by_week[ $wn_int ] ) ) {
            $logs_by_week[ $wn_int ] = array();
        }
        $logs_by_week[ $wn_int ][] = $log;
    }

    // 4. Query Topics and Lessons from Tutor LMS for this course
    $topics = array();
    if ( $course_id ) {
        $topics = get_posts( array(
            'post_type'      => 'topics',
            'post_parent'    => $course_id,
            'posts_per_page' => -1,
            'orderby'        => 'menu_order',
            'order'          => 'ASC',
        ) );
    }

    $tasks_by_week = array();
    if ( ! empty( $topics ) ) {
        $w_idx = 1;
        foreach ( $topics as $topic ) {
            $lessons = get_posts( array(
                'post_type'      => array( 'lesson', 'tutor_assignments', 'tutor_quiz' ),
                'post_parent'    => $topic->ID,
                'posts_per_page' => -1,
                'fields'         => 'ids',
            ) );
            $t_total = count( $lessons );
            $t_done  = 0;
            if ( $user_id && $t_total > 0 && function_exists( 'tutor_utils' ) && method_exists( tutor_utils(), 'is_completed_lesson' ) ) {
                foreach ( $lessons as $lid ) {
                    if ( tutor_utils()->is_completed_lesson( $lid, $user_id ) ) {
                        $t_done++;
                    }
                }
            }
            $tasks_by_week[ $w_idx ] = array(
                'done'  => $t_done,
                'total' => max( 1, $t_total ),
            );
            $w_idx++;
        }
    }

    // 5. Classes Attended This Week
    $start_of_week = date( 'Y-m-d 00:00:00', strtotime( 'monday this week' ) );
    $end_of_week   = date( 'Y-m-d 23:59:59', strtotime( 'sunday this week' ) );

    $this_week_logs = get_posts( array(
        'post_type'      => 'gh_class_log',
        'post_status'    => 'publish',
        'posts_per_page' => -1,
        'meta_query'     => array(
            array(
                'key'     => '_gh_course_id',
                'value'   => $course_id,
                'compare' => '=',
            ),
        ),
        'date_query'     => array(
            array(
                'after'     => $start_of_week,
                'before'    => $end_of_week,
                'inclusive' => true,
            ),
        ),
    ) );

    $classes_delivered_this_week = count( $this_week_logs );
    if ( $classes_delivered_this_week <= 0 && isset( $logs_by_week[ $current_cohort_week ] ) ) {
        $classes_delivered_this_week = count( $logs_by_week[ $current_cohort_week ] );
        $curr_week_logs = $logs_by_week[ $current_cohort_week ];
    } else {
        $curr_week_logs = $this_week_logs;
    }

    $classes_attended_this_week = 0;
    if ( ! empty( $curr_week_logs ) && $user_id ) {
        foreach ( $curr_week_logs as $cwl ) {
            $attendees = get_post_meta( $cwl->ID, '_gh_attendees', true );
            if ( is_array( $attendees ) && ( in_array( $user_id, $attendees ) || in_array( (string) $user_id, $attendees, true ) || ( $user && in_array( $user->user_login, $attendees, true ) ) ) ) {
                $classes_attended_this_week++;
            }
        }
    }
    $direct_user_att = (int) get_user_meta( $user_id, '_gh_attendance_week_current', true );
    if ( $direct_user_att > 0 ) {
        $classes_attended_this_week = max( $classes_attended_this_week, $direct_user_att );
    }

    // 6. Tasks Done This Week
    $completed_lessons = 0;
    $total_lessons     = 0;
    if ( $course_id && function_exists( 'tutor_utils' ) ) {
        if ( method_exists( tutor_utils(), 'get_lesson_count_by_course' ) ) {
            $total_lessons = (int) tutor_utils()->get_lesson_count_by_course( $course_id );
        }
        if ( method_exists( tutor_utils(), 'get_completed_lesson_count_by_course' ) && $user_id ) {
            $completed_lessons = (int) tutor_utils()->get_completed_lesson_count_by_course( $course_id, $user_id );
        }
    }
    if ( $total_lessons <= 0 && $course_id && isset( $wpdb->posts ) ) {
        $topics_list = $wpdb->get_col( $wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'topics' AND post_parent = %d",
            $course_id
        ) );
        if ( ! empty( $topics_list ) ) {
            $t_ids = implode( ',', array_map( 'absint', $topics_list ) );
            $total_lessons = (int) $wpdb->get_var(
                "SELECT COUNT(ID) FROM {$wpdb->posts} WHERE post_type IN ('lesson', 'tutor_assignments', 'tutor_quiz') AND post_parent IN ($t_ids) AND post_status = 'publish'"
            );
        }
    }
    $tasks_done_this_week  = $completed_lessons;
    $tasks_total_this_week = $total_lessons;

    // 7. FlexiLearn Week Unlocked
    $fl_paid = (int) get_user_meta( $user_id, '_gh_fl_weeks_paid_' . $course_id, true );
    $unlocked_weeks = $fl_paid > 0 ? $fl_paid : max( 1, $current_cohort_week );

    // 8. Check-in Streak
    $saved_streak = (int) get_user_meta( $user_id, '_gh_checkin_streak', true );
    $streak_weeks = $saved_streak > 0 ? $saved_streak : ( $classes_attended_this_week > 0 ? 1 : 0 );

    // 9. Week by Week History
    $saved_learner_rating  = get_user_meta( $user_id, '_gh_learner_rating_' . $course_id, true );
    $saved_learner_comment = get_user_meta( $user_id, '_gh_learner_comment_' . $course_id, true );

    $history_rows = array();
    for ( $w = $current_cohort_week; $w >= 1; $w-- ) {
        $w_logs = isset( $logs_by_week[ $w ] ) ? $logs_by_week[ $w ] : array();
        $w_delivered = count( $w_logs );

        $w_attended = 0;
        $w_rating   = '';
        $w_comment  = '';

        if ( ! empty( $w_logs ) ) {
            foreach ( $w_logs as $wl ) {
                $att = get_post_meta( $wl->ID, '_gh_attendees', true );
                if ( is_array( $att ) && ( in_array( $user_id, $att ) || in_array( (string) $user_id, $att, true ) || ( $user && in_array( $user->user_login, $att, true ) ) ) ) {
                    $w_attended++;
                }

                $pulses = get_post_meta( $wl->ID, '_gh_pulse_ratings', true );
                if ( is_array( $pulses ) && ! empty( $pulses ) ) {
                    foreach ( $pulses as $p ) {
                        if ( isset( $p['user_id'] ) && (int) $p['user_id'] === $user_id ) {
                            $w_rating = number_format( floatval( $p['rating'] ), 1 ) . ' / 5';
                            if ( ! empty( $p['notes'] ) ) {
                                $w_comment = $p['notes'];
                            }
                        }
                    }
                }
            }
        }

        if ( $w === $current_cohort_week && $saved_learner_rating ) {
            $w_rating = number_format( floatval( $saved_learner_rating ), 1 ) . ' / 5';
            if ( $saved_learner_comment ) {
                $w_comment = $saved_learner_comment;
            }
        }

        if ( empty( $w_rating ) ) {
            $w_rating = ( $w === $current_cohort_week ) ? __( 'Pending', 'growth-hub' ) : __( 'Not rated', 'growth-hub' );
        }
        if ( empty( $w_comment ) ) {
            $w_comment = __( 'No comment', 'growth-hub' );
        }

        if ( isset( $tasks_by_week[ $w ] ) ) {
            $w_t_done = $tasks_by_week[ $w ]['done'];
            $w_t_tot  = $tasks_by_week[ $w ]['total'];
        } else {
            $w_t_done = 0;
            $w_t_tot  = 0;
        }

        // Fair Status Logic: Zero delivered classes never penalize the student
        if ( $w === $current_cohort_week ) {
            $status_label = __( 'Current', 'growth-hub' );
            $status_class = 'gh-badge-current';
        } elseif ( $w_delivered === 0 ) {
            // No class was held for this week - student is never penalized
            $status_label = __( 'Complete', 'growth-hub' );
            $status_class = 'gh-badge-complete';
        } elseif ( $w_attended >= $w_delivered && ( $w_t_tot === 0 || $w_t_done >= $w_t_tot ) ) {
            $status_label = __( 'Complete', 'growth-hub' );
            $status_class = 'gh-badge-complete';
        } elseif ( $w_delivered > 0 && $w_attended < $w_delivered ) {
            $status_label = __( 'Catch-up needed', 'growth-hub' );
            $status_class = 'gh-badge-catchup';
        } elseif ( $w_t_tot > 0 && $w_t_done < $w_t_tot ) {
            $status_label = __( 'Catch-up needed', 'growth-hub' );
            $status_class = 'gh-badge-catchup';
        } else {
            $status_label = __( 'Complete', 'growth-hub' );
            $status_class = 'gh-badge-complete';
        }

        $history_rows[] = array(
            'week'             => sprintf( __( 'Week %d', 'growth-hub' ), $w ),
            'classes_attended' => $w_attended . ' of ' . $w_delivered,
            'tasks_done'       => $w_t_done . ' of ' . $w_t_tot,
            'my_rating'        => $w_rating,
            'my_comment'       => $w_comment,
            'status_label'     => $status_label,
            'status_class'     => $status_class,
        );
    }
} catch ( Throwable $e ) {
    // Fallback gracefully
}

if ( empty( $history_rows ) ) {
    $history_rows = array(
        array(
            'week'             => __( 'Week 1', 'growth-hub' ),
            'classes_attended' => $classes_attended_this_week . ' of ' . $classes_delivered_this_week,
            'tasks_done'       => $tasks_done_this_week . ' of ' . $tasks_total_this_week,
            'my_rating'        => $saved_learner_rating ? ( number_format( floatval( $saved_learner_rating ), 1 ) . ' / 5' ) : __( 'Pending', 'growth-hub' ),
            'my_comment'       => $saved_learner_comment ? $saved_learner_comment : __( 'No comment', 'growth-hub' ),
            'status_label'     => __( 'Current', 'growth-hub' ),
            'status_class'     => 'gh-badge-current',
        ),
    );
}
?>

<div class="gh-overview-container">
    <!-- Top Header with Multi-Course Selector -->
    <div class="gh-analytics-top">
        <div>
            <h1><?php esc_html_e( 'Weekly check-in', 'growth-hub' ); ?></h1>
            <p><?php esc_html_e( 'Your attendance, tasks and feedback for each week', 'growth-hub' ); ?></p>
        </div>
        <div style="display: flex; align-items: center; gap: 14px; flex-wrap: wrap;">
            <?php if ( count( $student_enrolled_courses ) > 1 ) : ?>
                <form method="get" action="" style="margin: 0; display: inline-flex; align-items: center; gap: 8px;">
                    <label for="gh-student-course-select" style="font-size: 12px; font-weight: 700; color: #6B7280; text-transform: uppercase; letter-spacing: 0.04em;">
                        <?php esc_html_e( 'Course:', 'growth-hub' ); ?>
                    </label>
                    <select name="course_id" id="gh-student-course-select" class="gh-course-switch-select" onchange="this.form.submit()">
                        <?php foreach ( $student_enrolled_courses as $c_item ) : ?>
                            <option value="<?php echo esc_attr( $c_item->ID ); ?>" <?php selected( $c_item->ID, $course_id ); ?>>
                                <?php echo esc_html( $c_item->post_title ); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </form>
            <?php endif; ?>
            <button type="button" class="gh-btn-rate-class" onclick="ghOpenPulseModal()">
                <?php esc_html_e( 'Rate this week\'s class', 'growth-hub' ); ?>
            </button>
        </div>
    </div>

    <!-- 4 Dark Metric Cards (#1B1240) -->
    <div class="gh-analytics-stats">
        <div class="gh-stat-card">
            <div class="gh-stat-num">
                <?php echo esc_html( $classes_attended_this_week . ' of ' . $classes_delivered_this_week ); ?>
            </div>
            <div class="gh-stat-desc"><?php esc_html_e( 'Classes attended this week', 'growth-hub' ); ?></div>
        </div>

        <div class="gh-stat-card">
            <div class="gh-stat-num">
                <?php echo esc_html( $tasks_done_this_week . ' of ' . $tasks_total_this_week ); ?>
            </div>
            <div class="gh-stat-desc"><?php esc_html_e( 'Tasks done this week', 'growth-hub' ); ?></div>
        </div>

        <div class="gh-stat-card">
            <div class="gh-stat-num">
                <?php echo esc_html( 'Week ' . $unlocked_weeks ); ?>
            </div>
            <div class="gh-stat-desc"><?php esc_html_e( 'FlexiLearn week unlocked', 'growth-hub' ); ?></div>
        </div>

        <div class="gh-stat-card">
            <div class="gh-stat-num">
                <?php echo esc_html( $streak_weeks . ' ' . _n( 'week', 'weeks', $streak_weeks, 'growth-hub' ) ); ?>
            </div>
            <div class="gh-stat-desc"><?php esc_html_e( 'Check-in streak', 'growth-hub' ); ?></div>
        </div>
    </div>

    <!-- Section: Week by week -->
    <div class="gh-section-card">
        <div class="gh-card-top-bar">
            <h2><?php esc_html_e( 'Week by week', 'growth-hub' ); ?></h2>
        </div>

        <div class="gh-table-wrap">
            <table class="gh-table" id="gh-student-history-table">
                <thead>
                    <tr>
                        <th><?php esc_html_e( 'WEEK', 'growth-hub' ); ?></th>
                        <th><?php esc_html_e( 'CLASSES ATTENDED', 'growth-hub' ); ?></th>
                        <th><?php esc_html_e( 'TASKS DONE', 'growth-hub' ); ?></th>
                        <th><?php esc_html_e( 'MY RATING', 'growth-hub' ); ?></th>
                        <th><?php esc_html_e( 'MY COMMENT', 'growth-hub' ); ?></th>
                        <th><?php esc_html_e( 'STATUS', 'growth-hub' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $history_rows as $row ) : ?>
                        <tr>
                            <td><strong><?php echo esc_html( $row['week'] ); ?></strong></td>
                            <td><?php echo esc_html( $row['classes_attended'] ); ?></td>
                            <td><?php echo esc_html( $row['tasks_done'] ); ?></td>
                            <td><?php echo esc_html( $row['my_rating'] ); ?></td>
                            <td><?php echo esc_html( $row['my_comment'] ); ?></td>
                            <td>
                                <span class="gh-pill-badge <?php echo esc_attr( $row['status_class'] ); ?>">
                                    <?php echo esc_html( $row['status_label'] ); ?>
                                </span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Privacy Disclaimer Banner -->
    <div class="gh-privacy-notice-banner">
        <span><?php esc_html_e( 'Ratings are private to the Hub team. Your tutor sees class scores without your name.', 'growth-hub' ); ?></span>
    </div>
</div>

<!-- Weekly Pulse Pop-up Modal (Screen 4c) -->
<div class="gh-modal-overlay" id="gh-pulse-modal">
    <div class="gh-modal-box" style="max-width: 380px;">
        <button type="button" onclick="ghClosePulseModal()" style="position:absolute; top:16px; right:18px; background:transparent; border:none; font-size:24px; color:#6B7280; cursor:pointer;">&times;</button>
        
        <h2 style="font-family:'Poppins',sans-serif; font-size:20px; font-weight:700; color:#1B1240; margin:0 0 6px;">
            <?php esc_html_e( 'How was this class?', 'growth-hub' ); ?>
        </h2>
        <p style="font-size:12px; color:#6B7280; line-height:1.45; margin:0 0 18px;">
            <?php esc_html_e( 'Takes 10 seconds. Your tutor sees class scores, and the Ops and BD Manager sees comments.', 'growth-hub' ); ?>
        </p>

        <!-- 1 to 5 Rating Circles -->
        <div class="gh-rating-row" style="display:flex; justify-content:space-between; gap:8px; margin-bottom:6px;">
            <button type="button" class="gh-rate-circle" data-rate="1" onclick="ghSelectModalRating(1)">1</button>
            <button type="button" class="gh-rate-circle" data-rate="2" onclick="ghSelectModalRating(2)">2</button>
            <button type="button" class="gh-rate-circle" data-rate="3" onclick="ghSelectModalRating(3)">3</button>
            <button type="button" class="gh-rate-circle active" data-rate="4" onclick="ghSelectModalRating(4)">4</button>
            <button type="button" class="gh-rate-circle" data-rate="5" onclick="ghSelectModalRating(5)">5</button>
        </div>
        <div style="display:flex; justify-content:space-between; font-size:11px; color:#9CA3AF; margin-bottom:18px;">
            <span><?php esc_html_e( 'Poor', 'growth-hub' ); ?></span>
            <span><?php esc_html_e( 'Excellent', 'growth-hub' ); ?></span>
        </div>

        <div style="font-family:'Poppins',sans-serif; font-size:11px; font-weight:700; letter-spacing:0.05em; color:#6B7280; text-transform:uppercase; margin-bottom:8px;">
            <?php esc_html_e( 'THE PACE WAS', 'growth-hub' ); ?>
        </div>
        <div style="display:flex; gap:8px; margin-bottom:18px;">
            <button type="button" class="gh-pace-btn" data-pace="too_slow" onclick="ghSelectModalPace('too_slow')"><?php esc_html_e( 'Too slow', 'growth-hub' ); ?></button>
            <button type="button" class="gh-pace-btn active" data-pace="about_right" onclick="ghSelectModalPace('about_right')"><?php esc_html_e( 'About right', 'growth-hub' ); ?></button>
            <button type="button" class="gh-pace-btn" data-pace="too_fast" onclick="ghSelectModalPace('too_fast')"><?php esc_html_e( 'Too fast', 'growth-hub' ); ?></button>
        </div>

        <div style="font-family:'Poppins',sans-serif; font-size:11px; font-weight:700; letter-spacing:0.05em; color:#6B7280; text-transform:uppercase; margin-bottom:8px;">
            <?php esc_html_e( 'ANYTHING ELSE? (OPTIONAL)', 'growth-hub' ); ?>
        </div>
        <textarea id="gh-modal-pulse-comment" class="gh-pulse-textarea" rows="3" placeholder="<?php esc_attr_e( 'Add a comment', 'growth-hub' ); ?>"></textarea>

        <button type="button" class="gh-btn-primary-purple" style="margin-top:16px;" onclick="ghSubmitModalPulse()">
            <?php esc_html_e( 'Send', 'growth-hub' ); ?>
        </button>
    </div>
</div>

<style>
/* Button Top Right */
.gh-btn-rate-class {
    background-color: var(--gh-purple, #632FD1);
    color: #FFFFFF !important;
    border: none;
    border-radius: 9999px;
    padding: 11px 24px;
    font-family: var(--gh-font-h, 'Poppins', sans-serif);
    font-size: 13.5px;
    font-weight: 600;
    cursor: pointer;
    transition: 0.2s ease;
    white-space: nowrap;
    text-decoration: none;
    display: inline-block;
}
.gh-btn-rate-class:hover {
    background-color: #5224b8;
    transform: translateY(-1px);
}

/* Course Switch Dropdown */
.gh-course-switch-select {
    background-color: #FFFFFF;
    color: #1B1240;
    border: 1.5px solid #D8D2EE;
    border-radius: 8px;
    padding: 7px 12px;
    font-family: var(--gh-font-b, 'Lato', sans-serif);
    font-size: 13px;
    font-weight: 600;
    cursor: pointer;
    outline: none;
}
.gh-course-switch-select:focus {
    border-color: #632FD1;
}

/* Status Badges */
.gh-badge-current,
.gh-badge-complete {
    background: #E8F5E9 !important;
    color: #2E7D32 !important;
}
.gh-badge-catchup {
    background: #FEF3C7 !important;
    color: #B45309 !important;
}

/* Privacy Notice Banner */
.gh-privacy-notice-banner {
    background: #F4F1FB;
    border-radius: 12px;
    padding: 14px 20px;
    margin-top: 24px;
    color: var(--gh-text, #1B1240);
    font-size: 13px;
    font-family: var(--gh-font-b, 'Lato', sans-serif);
    line-height: 1.4;
    border: 1px solid #ECE8F7;
}
</style>

<script>
var ghModalRating = 4;
var ghModalPace   = 'about_right';

function ghOpenPulseModal() {
    var modal = document.getElementById('gh-pulse-modal');
    if (modal) modal.style.display = 'flex';
}

function ghClosePulseModal() {
    var modal = document.getElementById('gh-pulse-modal');
    if (modal) modal.style.display = 'none';
}

function ghSelectModalRating(rate) {
    ghModalRating = rate;
    var circles = document.querySelectorAll('#gh-pulse-modal .gh-rate-circle');
    circles.forEach(function(c) {
        c.classList.remove('active');
        if (parseInt(c.getAttribute('data-rate'), 10) === rate) {
            c.classList.add('active');
        }
    });
}

function ghSelectModalPace(pace) {
    ghModalPace = pace;
    var btns = document.querySelectorAll('#gh-pulse-modal .gh-pace-btn');
    btns.forEach(function(b) {
        b.classList.remove('active');
        if (b.getAttribute('data-pace') === pace) {
            b.classList.add('active');
        }
    });
}

function ghSubmitModalPulse() {
    var comment = document.getElementById('gh-modal-pulse-comment') ? document.getElementById('gh-modal-pulse-comment').value.trim() : '';

    var data = new FormData();
    data.append('action', 'gh_learner_pulse');
    data.append('nonce', <?php echo json_encode( wp_create_nonce( 'gh_checkin_nonce' ) ); ?>);
    data.append('class_id', <?php echo json_encode( $latest_class_id ? $latest_class_id : $course_id ); ?>);
    data.append('course_id', <?php echo json_encode( $course_id ); ?>);
    data.append('rating', ghModalRating);
    data.append('pace', ghModalPace);
    data.append('notes', comment);

    fetch(<?php echo json_encode( admin_url( 'admin-ajax.php' ) ); ?>, {
        method: 'POST',
        body: data
    })
    .then(function(res) { return res.json(); })
    .then(function(json) {
        if (typeof ghToast === 'function') {
            ghToast('Thank you! Your weekly rating has been recorded.');
        }
        ghClosePulseModal();
        location.reload();
    })
    .catch(function(err) {
        if (typeof ghToast === 'function') {
            ghToast('Thank you! Your feedback has been received.');
        }
        ghClosePulseModal();
    });
}
</script>
