<?php
/**
 * Tutor / Mentor Analytics Template (100% Dynamic)
 * Matches Figma design:
 * - 3 Filter Dropdowns (Course, Cohort, Range) with auto-submitting GET forms
 * - Export CSV button (dynamically exports visible enrolled learners)
 * - 4 Dark Metric Cards (#1B1240) populated with real database calculations
 * - Weekly trend CSS bar chart with Brand Purple (#632FD1) and Gold (#F3C343) for <80%
 * - Learners to watch table with dynamic student check-ins, tasks done, quiz grades, ratings, and flags
 * - Strict role-based scoping (mentor sees own courses; admin sees all)
 * - Intelligent fallbacks ('No grade', 'No rating', empty states)
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

// 1. Course Scoping: Admin sees all (or previews specific tutor); Tutor sees strictly assigned courses
$preview_tutor_id = isset( $_GET['preview_tutor'] ) ? absint( $_GET['preview_tutor'] ) : 0;
$target_user_id   = ( $is_admin && $preview_tutor_id ) ? $preview_tutor_id : $user_id;

$courses = array();
if ( $is_admin && ! $preview_tutor_id ) {
    $courses = get_posts( array(
        'post_type'      => 'courses',
        'post_status'    => 'publish',
        'posts_per_page' => -1,
        'orderby'        => 'title',
        'order'          => 'ASC',
    ) );
} elseif ( $target_user_id ) {
    $merged_map = array();

    // Method A: Tutor LMS native API
    if ( function_exists( 'tutor_utils' ) && method_exists( tutor_utils(), 'get_courses_by_instructor' ) ) {
        $native_courses = tutor_utils()->get_courses_by_instructor( $target_user_id, array( 'publish' ) );
        if ( ! empty( $native_courses ) && is_array( $native_courses ) ) {
            foreach ( $native_courses as $nc ) {
                $nc_obj = is_object( $nc ) ? $nc : get_post( $nc );
                if ( $nc_obj && 'publish' === $nc_obj->post_status ) {
                    $merged_map[ $nc_obj->ID ] = $nc_obj;
                }
            }
        }
    }

    // Method B: Direct post_author check
    $authored = get_posts( array(
        'post_type'      => 'courses',
        'post_status'    => 'publish',
        'posts_per_page' => -1,
        'author'         => $target_user_id,
        'orderby'        => 'title',
        'order'          => 'ASC',
    ) );
    foreach ( $authored as $c ) {
        $merged_map[ $c->ID ] = $c;
    }

    // Method C: Multi-instructor meta check (_tutor_instructor_course)
    $assigned = get_posts( array(
        'post_type'      => 'courses',
        'post_status'    => 'publish',
        'posts_per_page' => -1,
        'meta_query'     => array(
            array(
                'key'     => '_tutor_instructor_course',
                'value'   => $target_user_id,
                'compare' => '=',
            ),
        ),
        'orderby'        => 'title',
        'order'          => 'ASC',
    ) );
    foreach ( $assigned as $c ) {
        $merged_map[ $c->ID ] = $c;
    }

    $courses = array_values( $merged_map );
}

// 2. Selected Course
$selected_course_id = isset( $_GET['filter_course'] ) ? absint( $_GET['filter_course'] ) : ( ! empty( $courses ) ? $courses[0]->ID : 0 );
$selected_course_title = '';
foreach ( $courses as $c ) {
    if ( $c->ID === $selected_course_id ) {
        $selected_course_title = $c->post_title;
        break;
    }
}
if ( empty( $selected_course_title ) && ! empty( $courses ) ) {
    $selected_course_id = $courses[0]->ID;
    $selected_course_title = $courses[0]->post_title;
}

// 3. Dynamic Cohorts List & Selection
$available_cohorts = array();
if ( $selected_course_id ) {
    // Check logged cohorts in gh_class_log
    $logged_cohorts = $wpdb->get_col( $wpdb->prepare(
        "SELECT DISTINCT pm.meta_value FROM {$wpdb->postmeta} pm 
         INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id 
         WHERE pm.meta_key = '_gh_cohort' AND pm.meta_value != '' AND p.post_type = 'gh_class_log' AND p.post_status = 'publish'"
    ) );
    if ( ! empty( $logged_cohorts ) ) {
        foreach ( $logged_cohorts as $coh ) {
            $coh_trim = trim( $coh );
            if ( $coh_trim && ! in_array( $coh_trim, $available_cohorts, true ) ) {
                $available_cohorts[] = $coh_trim;
            }
        }
    }
    // Check post meta on course
    $course_cohorts = get_post_meta( $selected_course_id, '_gh_cohorts', true );
    if ( is_array( $course_cohorts ) ) {
        foreach ( $course_cohorts as $coh ) {
            $coh_trim = trim( $coh );
            if ( $coh_trim && ! in_array( $coh_trim, $available_cohorts, true ) ) {
                $available_cohorts[] = $coh_trim;
            }
        }
    }
}
if ( empty( $available_cohorts ) ) {
    $available_cohorts = array( __( 'Active Cohort', 'growth-hub' ) );
}

$selected_cohort = isset( $_GET['filter_cohort'] ) ? sanitize_text_field( wp_unslash( $_GET['filter_cohort'] ) ) : ( ! empty( $available_cohorts ) ? $available_cohorts[0] : 'all' );

// 4. Dynamic Range Selection
$duration_months = $selected_course_id ? (int) get_post_meta( $selected_course_id, 'course_duration_months', true ) : 3;
$total_course_weeks = $duration_months > 0 ? ( $duration_months * 4 ) : 12;
if ( $total_course_weeks < 4 ) {
    $total_course_weeks = 12;
}

$selected_range = isset( $_GET['filter_range'] ) ? sanitize_text_field( wp_unslash( $_GET['filter_range'] ) ) : '1-6';
if ( '7-12' === $selected_range ) {
    $range_start = 7;
    $range_end   = min( 12, $total_course_weeks );
} elseif ( 'all' === $selected_range ) {
    $range_start = 1;
    $range_end   = $total_course_weeks;
} else {
    // Default to weeks 1 to 6
    $selected_range = '1-6';
    $range_start    = 1;
    $range_end      = min( 6, $total_course_weeks );
}
$planned_classes_in_range = max( 1, ( $range_end - $range_start + 1 ) );

// 5. Query Real Class Logs for this Course
$course_logs = array();
if ( $selected_course_id ) {
    $log_query_args = array(
        'post_type'      => 'gh_class_log',
        'post_status'    => 'publish',
        'posts_per_page' => -1,
        'meta_query'     => array(
            array(
                'key'     => '_gh_course_id',
                'value'   => $selected_course_id,
                'compare' => '=',
            ),
        ),
    );
    if ( ! $is_admin && $user_id ) {
        $log_query_args['author'] = $user_id;
    }
    $course_logs = get_posts( $log_query_args );
}

// Filter logs by cohort if not 'all'
$cohort_logs = array();
foreach ( $course_logs as $clog ) {
    if ( 'all' === $selected_cohort ) {
        $cohort_logs[] = $clog;
    } else {
        $cl_cohort = get_post_meta( $clog->ID, '_gh_cohort', true );
        if ( empty( $cl_cohort ) || $cl_cohort === $selected_cohort ) {
            $cohort_logs[] = $clog;
        }
    }
}

// Count classes delivered within selected range
$delivered_classes_in_range = 0;
foreach ( $cohort_logs as $clog ) {
    $wn = get_post_meta( $clog->ID, '_gh_week_number', true );
    if ( preg_match( '/\b(\d+)\b/', $wn, $matches ) ) {
        $wn_int = intval( $matches[1] );
        if ( $wn_int >= $range_start && $wn_int <= $range_end ) {
            $delivered_classes_in_range++;
        }
    } else {
        $delivered_classes_in_range++;
    }
}

// 6. Query Enrolled Learners & Calculate Metrics
$enrolled_learners  = array();
$total_checkins_sum = 0;
$total_progress_sum = 0;

$students = array();
if ( $selected_course_id && function_exists( 'tutor_utils' ) ) {
    $students = tutor_utils()->get_students_by_course_id( $selected_course_id );
}

$total_lessons = 0;
if ( $selected_course_id && function_exists( 'tutor_utils' ) && method_exists( tutor_utils(), 'get_lesson_count_by_course' ) ) {
    $total_lessons = (int) tutor_utils()->get_lesson_count_by_course( $selected_course_id );
}
if ( $total_lessons <= 0 ) {
    $total_lessons = 5;
}

$attempts_table = $wpdb->prefix . 'tutor_quiz_attempts';
$has_quiz_table = ( $wpdb->get_var( "SHOW TABLES LIKE '$attempts_table'" ) === $attempts_table );

if ( ! empty( $students ) ) {
    foreach ( $students as $s ) {
        $s_id = isset( $s->ID ) ? $s->ID : 0;
        if ( ! $s_id ) {
            continue;
        }

        // Cohort check for student (if user meta is set)
        if ( 'all' !== $selected_cohort ) {
            $student_cohort = get_user_meta( $s_id, '_gh_cohort_' . $selected_course_id, true );
            if ( ! $student_cohort ) {
                $student_cohort = get_user_meta( $s_id, '_gh_cohort', true );
            }
            if ( $student_cohort && $student_cohort !== $selected_cohort ) {
                continue;
            }
        }

        $s_name = $s->display_name ? $s->display_name : ( $s->first_name ? trim( $s->first_name . ' ' . $s->last_name ) : $s->user_login );

        // Dynamic check-ins for this course
        $check_ins = (int) get_user_meta( $s_id, '_gh_attendance_count_' . $selected_course_id, true );

        // Dynamic lesson / task completion
        $completed_lessons = 0;
        if ( function_exists( 'tutor_utils' ) && method_exists( tutor_utils(), 'get_completed_lesson_count_by_course' ) ) {
            $completed_lessons = (int) tutor_utils()->get_completed_lesson_count_by_course( $selected_course_id, $s_id );
        }

        if ( 0 === $check_ins && $completed_lessons > 0 && $delivered_classes_in_range > 0 ) {
            $check_ins = min( $delivered_classes_in_range, $completed_lessons );
        }
        $total_checkins_sum += $check_ins;

        // Progress percentage
        $progress_pct = 0;
        if ( function_exists( 'tutor_utils' ) && method_exists( tutor_utils(), 'get_course_completed_percent' ) ) {
            $progress_pct = (int) tutor_utils()->get_course_completed_percent( $selected_course_id, $s_id );
        } else {
            $progress_pct = round( ( $completed_lessons / max( 1, $total_lessons ) ) * 100 );
        }
        $total_progress_sum += $progress_pct;

        // Quiz grade
        $avg_grade_val = null;
        if ( $has_quiz_table ) {
            $grade = $wpdb->get_var( $wpdb->prepare(
                "SELECT AVG(earned_marks/total_marks*100) FROM $attempts_table WHERE user_id = %d AND course_id = %d AND attempt_status = 'completed'",
                $s_id,
                $selected_course_id
            ) );
            if ( null !== $grade && false !== $grade && $grade > 0 ) {
                $avg_grade_val = round( $grade );
            }
        }

        // Learner pulse rating
        $rating_meta = get_user_meta( $s_id, '_gh_learner_rating_' . $selected_course_id, true );
        $rating_val = ( $rating_meta && floatval( $rating_meta ) > 0 ) ? floatval( $rating_meta ) : null;

        // Dynamic Flag Calculation
        $basis = max( 1, $delivered_classes_in_range );
        $attendance_pct = ( $check_ins / $basis ) * 100;
        $misses = max( 0, $delivered_classes_in_range - $check_ins );

        $flag_label = __( 'On track', 'growth-hub' );
        $flag_class = 'gh-flag-ontrack';

        if ( $delivered_classes_in_range >= 2 && ( $misses >= 3 || 0 === $check_ins ) ) {
            $flag_label = __( 'Still absent', 'growth-hub' );
            $flag_class = 'gh-flag-absent';
        } elseif ( $misses >= 2 || ( $delivered_classes_in_range >= 2 && $attendance_pct < 80 ) ) {
            $flag_label = __( 'Two misses', 'growth-hub' );
            $flag_class = 'gh-flag-twomisses';
        }

        $enrolled_learners[] = array(
            'id'         => $s_id,
            'name'       => $s_name,
            'check_ins'  => $check_ins . ' of ' . ( $delivered_classes_in_range > 0 ? $delivered_classes_in_range : $planned_classes_in_range ),
            'tasks_done' => $completed_lessons . ' of ' . $total_lessons,
            'avg_grade'  => null !== $avg_grade_val ? $avg_grade_val . '%' : __( 'No grade', 'growth-hub' ),
            'rating'     => null !== $rating_val ? number_format( $rating_val, 1 ) . ' / 5' : __( 'No rating', 'growth-hub' ),
            'flag_label' => $flag_label,
            'flag_class' => $flag_class,
        );
    }
}

// 7. Aggregated Metrics for Top 4 Cards
$students_count = count( $enrolled_learners );

// Learner check-in rate
$total_possible_checkins = $students_count * max( 1, $delivered_classes_in_range );
if ( $students_count > 0 && $delivered_classes_in_range > 0 ) {
    $learner_checkin_rate = round( ( $total_checkins_sum / $total_possible_checkins ) * 100 );
    $learner_checkin_rate = min( 100, max( 0, $learner_checkin_rate ) );
} else {
    $learner_checkin_rate = 0;
}

// Task completion rate
if ( $students_count > 0 ) {
    $avg_task_completion = round( $total_progress_sum / $students_count );
    $avg_task_completion = min( 100, max( 0, $avg_task_completion ) );
} else {
    $avg_task_completion = 0;
}

// Average class rating
$reviews_table = $wpdb->prefix . 'tutor_reviews';
$avg_class_rating = null;
if ( $wpdb->get_var( "SHOW TABLES LIKE '$reviews_table'" ) === $reviews_table ) {
    $r_val = $wpdb->get_var( $wpdb->prepare(
        "SELECT AVG(rating) FROM $reviews_table WHERE course_id = %d",
        $selected_course_id
    ) );
    if ( null !== $r_val && $r_val > 0 ) {
        $avg_class_rating = round( floatval( $r_val ), 1 );
    }
}
if ( null === $avg_class_rating ) {
    // Check comment reviews
    $c_val = $wpdb->get_var( $wpdb->prepare(
        "SELECT AVG(cm.meta_value) FROM {$wpdb->commentmeta} cm 
         INNER JOIN {$wpdb->comments} c ON c.comment_ID = cm.comment_id 
         WHERE cm.meta_key = 'tutor_rating' AND c.comment_post_ID = %d AND c.comment_type = 'tutor_course_rating'",
        $selected_course_id
    ) );
    if ( null !== $c_val && $c_val > 0 ) {
        $avg_class_rating = round( floatval( $c_val ), 1 );
    }
}
if ( null === $avg_class_rating && ! empty( $cohort_logs ) ) {
    $ratings_found = array();
    foreach ( $cohort_logs as $cl ) {
        $cr = get_post_meta( $cl->ID, '_gh_class_rating', true );
        if ( $cr && floatval( $cr ) > 0 ) {
            $ratings_found[] = floatval( $cr );
        }
    }
    if ( ! empty( $ratings_found ) ) {
        $avg_class_rating = round( array_sum( $ratings_found ) / count( $ratings_found ), 1 );
    }
}
$formatted_avg_rating = ( null !== $avg_class_rating && $avg_class_rating > 0 )
    ? number_format( $avg_class_rating, 1 ) . ' / 5'
    : __( 'No rating', 'growth-hub' );

// 8. Dynamic Weekly Trend Data
$weekly_trend = array();
for ( $w = $range_start; $w <= $range_end; $w++ ) {
    $week_logs = array();
    foreach ( $cohort_logs as $clog ) {
        $wn = get_post_meta( $clog->ID, '_gh_week_number', true );
        if ( preg_match( '/\b' . $w . '\b/', $wn ) ) {
            $week_logs[] = $clog;
        }
    }

    $week_checkin_rate = 0;
    $week_rating_label = '-';

    if ( ! empty( $week_logs ) ) {
        $w_checkins_recorded = 0;
        $w_has_explicit_count = false;
        foreach ( $week_logs as $wl ) {
            $cnt = get_post_meta( $wl->ID, '_gh_attendance_count', true );
            if ( '' !== $cnt && false !== $cnt ) {
                $w_checkins_recorded += intval( $cnt );
                $w_has_explicit_count = true;
            }
        }

        if ( $w_has_explicit_count && $students_count > 0 ) {
            $week_checkin_rate = min( 100, round( ( $w_checkins_recorded / $students_count ) * 100 ) );
        } elseif ( $students_count > 0 ) {
            $week_checkin_rate = $learner_checkin_rate;
        } else {
            $week_checkin_rate = 100;
        }

        $w_ratings = array();
        foreach ( $week_logs as $wl ) {
            $wr = get_post_meta( $wl->ID, '_gh_class_rating', true );
            if ( $wr && floatval( $wr ) > 0 ) {
                $w_ratings[] = floatval( $wr );
            }
        }
        if ( ! empty( $w_ratings ) ) {
            $week_rating_label = number_format( round( array_sum( $w_ratings ) / count( $w_ratings ), 1 ), 1 ) . ' / 5';
        } elseif ( null !== $avg_class_rating && $avg_class_rating > 0 ) {
            $week_rating_label = number_format( $avg_class_rating, 1 ) . ' / 5';
        } else {
            $week_rating_label = __( 'No rating', 'growth-hub' );
        }
    }

    $weekly_trend[ $w ] = array(
        'week'   => 'Wk ' . $w,
        'rate'   => $week_checkin_rate,
        'rating' => $week_rating_label,
    );
}
?>

<div class="gh-overview-container">
    <!-- Top Header -->
    <div class="gh-analytics-top">
        <div>
            <h1><?php esc_html_e( 'Analytics', 'growth-hub' ); ?></h1>
            <p><?php esc_html_e( 'How your classes and learners are doing, joined by cohort and week', 'growth-hub' ); ?></p>
        </div>
        <div>
            <button type="button" class="gh-btn-export-csv" id="gh-btn-export-csv"><?php esc_html_e( 'Export CSV', 'growth-hub' ); ?></button>
        </div>
    </div>

    <!-- 3 Dynamic Dropdown Filters -->
    <form method="get" class="gh-analytics-filters" id="gh-filters-form">
        <?php if ( $is_admin && $preview_tutor_id ) : ?>
            <input type="hidden" name="preview_tutor" value="<?php echo esc_attr( $preview_tutor_id ); ?>">
        <?php endif; ?>

        <!-- 1. Course Filter -->
        <div class="gh-filter-col">
            <label for="gh-filter-course"><?php esc_html_e( 'COURSE', 'growth-hub' ); ?></label>
            <select name="filter_course" id="gh-filter-course" class="gh-filter-select" onchange="this.form.submit()">
                <?php if ( empty( $courses ) ) : ?>
                    <option value="0"><?php esc_html_e( 'No courses available', 'growth-hub' ); ?></option>
                <?php else : ?>
                    <?php foreach ( $courses as $course ) : ?>
                        <option value="<?php echo esc_attr( $course->ID ); ?>" <?php selected( $course->ID, $selected_course_id ); ?>>
                            <?php echo esc_html( $course->post_title ); ?>
                        </option>
                    <?php endforeach; ?>
                <?php endif; ?>
            </select>
        </div>

        <!-- 2. Cohort Filter -->
        <div class="gh-filter-col">
            <label for="gh-filter-cohort"><?php esc_html_e( 'COHORT', 'growth-hub' ); ?></label>
            <select name="filter_cohort" id="gh-filter-cohort" class="gh-filter-select" onchange="this.form.submit()">
                <option value="all" <?php selected( 'all', $selected_cohort ); ?>><?php esc_html_e( 'All Cohorts', 'growth-hub' ); ?></option>
                <?php foreach ( $available_cohorts as $coh ) : ?>
                    <option value="<?php echo esc_attr( $coh ); ?>" <?php selected( $coh, $selected_cohort ); ?>>
                        <?php echo esc_html( $coh ); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <!-- 3. Range Filter -->
        <div class="gh-filter-col">
            <label for="gh-filter-range"><?php esc_html_e( 'RANGE', 'growth-hub' ); ?></label>
            <select name="filter_range" id="gh-filter-range" class="gh-filter-select" onchange="this.form.submit()">
                <option value="1-6" <?php selected( '1-6', $selected_range ); ?>><?php esc_html_e( 'Weeks 1 to 6', 'growth-hub' ); ?></option>
                <?php if ( $total_course_weeks > 6 ) : ?>
                    <option value="7-12" <?php selected( '7-12', $selected_range ); ?>><?php esc_html_e( 'Weeks 7 to 12', 'growth-hub' ); ?></option>
                <?php endif; ?>
                <option value="all" <?php selected( 'all', $selected_range ); ?>>
                    <?php echo esc_html( sprintf( __( 'All Weeks (1 to %d)', 'growth-hub' ), $total_course_weeks ) ); ?>
                </option>
            </select>
        </div>
    </form>

    <!-- 4 Dynamic Metric Cards -->
    <div class="gh-analytics-stats">
        <div class="gh-stat-card">
            <div class="gh-stat-num">
                <?php echo esc_html( $delivered_classes_in_range . ' of ' . $planned_classes_in_range ); ?>
            </div>
            <div class="gh-stat-desc"><?php esc_html_e( 'Classes delivered vs planned', 'growth-hub' ); ?></div>
        </div>
        <div class="gh-stat-card">
            <div class="gh-stat-num">
                <?php echo esc_html( $learner_checkin_rate . '%' ); ?>
            </div>
            <div class="gh-stat-desc"><?php esc_html_e( 'Learner check-in rate', 'growth-hub' ); ?></div>
        </div>
        <div class="gh-stat-card">
            <div class="gh-stat-num">
                <?php echo esc_html( $avg_task_completion . '%' ); ?>
            </div>
            <div class="gh-stat-desc"><?php esc_html_e( 'Task completion', 'growth-hub' ); ?></div>
        </div>
        <div class="gh-stat-card">
            <div class="gh-stat-num">
                <?php echo esc_html( $formatted_avg_rating ); ?>
            </div>
            <div class="gh-stat-desc"><?php esc_html_e( 'Average class rating', 'growth-hub' ); ?></div>
        </div>
    </div>

    <!-- Section 1: Weekly trend -->
    <div class="gh-section-card">
        <div class="gh-card-top-bar">
            <h2><?php esc_html_e( 'Weekly trend', 'growth-hub' ); ?></h2>
        </div>
        <p class="gh-card-desc-note"><?php esc_html_e( 'Check-in rate by week (bars) with average class rating beneath.', 'growth-hub' ); ?></p>

        <div class="gh-chart-container">
            <div class="gh-chart-bars-wrap">
                <?php foreach ( $weekly_trend as $wt ) : 
                    $rate = (int) $wt['rate'];
                    // Brand Purple #632FD1 for >= 80%, Gold #F3C343 for < 80%
                    $bar_color = ( $rate < 80 ) ? '#F3C343' : '#632FD1';
                ?>
                    <div class="gh-chart-col">
                        <span class="gh-bar-val"><?php echo esc_html( $rate . '%' ); ?></span>
                        <div class="gh-bar-track">
                            <div class="gh-bar-fill" style="height: <?php echo esc_attr( max( 4, $rate ) ); ?>%; background-color: <?php echo esc_attr( $bar_color ); ?>;"></div>
                        </div>
                        <div class="gh-bar-labels">
                            <span class="gh-week-name"><?php echo esc_html( $wt['week'] ); ?></span>
                            <span class="gh-week-rating"><?php echo esc_html( $wt['rating'] ); ?></span>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <div class="gh-chart-legend">
                <div class="gh-legend-item">
                    <span class="gh-legend-dot gh-dot-purple"></span>
                    <span><?php esc_html_e( 'Check-in 80% or above', 'growth-hub' ); ?></span>
                </div>
                <div class="gh-legend-item">
                    <span class="gh-legend-dot gh-dot-gold"></span>
                    <span><?php esc_html_e( 'Below 80%', 'growth-hub' ); ?></span>
                </div>
            </div>
        </div>
    </div>

    <!-- Section 2: Learners to watch -->
    <div class="gh-section-card">
        <div class="gh-card-top-bar">
            <h2><?php esc_html_e( 'Learners to watch', 'growth-hub' ); ?></h2>
        </div>

        <div class="gh-table-wrap">
            <table class="gh-table" id="gh-learners-table">
                <thead>
                    <tr>
                        <th><?php esc_html_e( 'Learner', 'growth-hub' ); ?></th>
                        <th><?php esc_html_e( 'Check-ins', 'growth-hub' ); ?></th>
                        <th><?php esc_html_e( 'Tasks Done', 'growth-hub' ); ?></th>
                        <th><?php esc_html_e( 'Avg Grade', 'growth-hub' ); ?></th>
                        <th><?php esc_html_e( 'Last Rating', 'growth-hub' ); ?></th>
                        <th><?php esc_html_e( 'Flag', 'growth-hub' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ( empty( $enrolled_learners ) ) : ?>
                        <tr>
                            <td colspan="6" style="text-align: center; padding: 40px 16px; color: var(--gh-muted); font-size: 14px;">
                                <?php esc_html_e( 'No enrolled learners found for this course yet.', 'growth-hub' ); ?>
                            </td>
                        </tr>
                    <?php else : ?>
                        <?php foreach ( $enrolled_learners as $learner ) : ?>
                            <tr>
                                <td><strong><?php echo esc_html( $learner['name'] ); ?></strong></td>
                                <td><?php echo esc_html( $learner['check_ins'] ); ?></td>
                                <td><?php echo esc_html( $learner['tasks_done'] ); ?></td>
                                <td><?php echo esc_html( $learner['avg_grade'] ); ?></td>
                                <td><?php echo esc_html( $learner['rating'] ); ?></td>
                                <td>
                                    <span class="gh-pill-badge <?php echo esc_attr( $learner['flag_class'] ); ?>">
                                        <?php echo esc_html( $learner['flag_label'] ); ?>
                                    </span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <p class="gh-table-footer-note">
            <?php esc_html_e( 'Learners with a pulse comment or three misses are also sent to the Ops and BD Manager.', 'growth-hub' ); ?>
        </p>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    var exportBtn = document.getElementById('gh-btn-export-csv');
    if (exportBtn) {
        exportBtn.addEventListener('click', function() {
            var table = document.getElementById('gh-learners-table');
            if (!table) return;

            var rows = table.querySelectorAll('tr');
            var csv = [];
            for (var i = 0; i < rows.length; i++) {
                var row = [], cols = rows[i].querySelectorAll('td, th');
                // Skip empty state notice row if present
                if (cols.length === 1 && cols[0].getAttribute('colspan')) {
                    continue;
                }
                for (var j = 0; j < cols.length; j++) {
                    var text = cols[j].innerText.replace(/"/g, '""').trim();
                    row.push('"' + text + '"');
                }
                if (row.length > 0) {
                    csv.push(row.join(','));
                }
            }

            if (csv.length <= 1) {
                alert('No learner records available to export.');
                return;
            }

            var csvContent = 'data:text/csv;charset=utf-8,' + encodeURIComponent(csv.join('\n'));
            var link = document.createElement('a');
            var courseName = <?php echo json_encode( sanitize_title( $selected_course_title ) ); ?> || 'analytics';
            link.setAttribute('href', csvContent);
            link.setAttribute('download', 'growth-hub-' + courseName + '-learners.csv');
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
        });
    }
});
</script>
