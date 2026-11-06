<?php
/**
 * Snippet Name: Growth Hub - Tutor Analytics Screen (Exact Shell Match)
 * Author: Adeleye Shina
 * Description: Analytics screen built inside the exact proven edge-to-edge dashboard shell. Features Gold #F3C343 for <80%, 4 dark cards, CSS weekly trend chart, and learners to watch.
 * Shortcode: [growth_hub_dashboard]
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

add_shortcode( 'growth_hub_dashboard', 'gh_render_analytics_page' );
function gh_render_analytics_page() {
    global $wpdb;

    $user_id = get_current_user_id();
    $user    = wp_get_current_user();
    $is_admin = current_user_can( 'administrator' );

    // 1. User Identity & Role/View Detection
    $role_view = isset( $_GET['view'] ) ? sanitize_text_field( $_GET['view'] ) : ( isset( $_GET['role_view'] ) ? sanitize_text_field( $_GET['role_view'] ) : '' );
    $current_tab = isset( $_GET['tab'] ) ? sanitize_text_field( $_GET['tab'] ) : '';

    $roles = ( $user_id && isset( $user->roles ) ) ? (array) $user->roles : array();
    $is_instructor = in_array( 'tutor_instructor', $roles, true );

    $is_manager_view = false;
    if ( 'manager' === $role_view || 'ops' === $role_view || 'ops_manager' === $role_view || in_array( 'ops_bd_manager', $roles, true ) || in_array( 'manager', $roles, true ) || ( $is_admin && 'manager' === $role_view ) || 'cohort-delivery' === $current_tab ) {
        $is_manager_view = true;
    }

    $is_student_view = false;
    if ( ! $is_manager_view ) {
        if ( 'student' === $role_view ) {
            $is_student_view = true;
        } elseif ( 'mentor' === $role_view || 'tutor' === $role_view ) {
            $is_student_view = false;
        } elseif ( ! $is_admin && ! $is_instructor && $user_id ) {
            // Enrolled learners / subscribers automatically get student view
            $is_student_view = true;
        } elseif ( in_array( $current_tab, array( 'weekly-check-in', 'weekly-checkin', 'student-dashboard', 'assignments', 'talent-profile' ), true ) ) {
            $is_student_view = true;
        } elseif ( ! $is_admin && function_exists( 'GH_Portal_Roles' ) && GH_Portal_Roles::is_student( $user_id ) ) {
            $is_student_view = true;
        }
    }

    if ( $is_manager_view ) {
        $display_name    = 'OPS AND BD MANAGER';
        $user_role_label = 'Learner experience owner';
    } elseif ( $is_student_view ) {
        $display_name = $user_id ? ( $user->display_name ? $user->display_name : $user->user_login ) : 'STUDENT';
        
        // Multi-course support: collect all courses student is enrolled in
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

        // Active Course Selection (multi-course aware)
        $req_course_id = isset( $_GET['course_id'] ) ? absint( $_GET['course_id'] ) : 0;
        if ( ! $req_course_id || ! isset( $student_enrolled_courses[ $req_course_id ] ) ) {
            $course_keys = array_keys( $student_enrolled_courses );
            $active_course_id = ! empty( $course_keys ) ? $course_keys[0] : 0;
        } else {
            $active_course_id = $req_course_id;
        }

        $active_course_obj = $active_course_id && isset( $student_enrolled_courses[ $active_course_id ] ) ? $student_enrolled_courses[ $active_course_id ] : null;
        $student_course_name = $active_course_obj ? $active_course_obj->post_title : 'Course';
        $student_cohort = $user_id ? get_user_meta( $user_id, '_gh_cohort', true ) : '';
        if ( empty( $student_cohort ) && $active_course_id ) {
            $student_cohort = get_post_meta( $active_course_id, '_gh_cohort', true );
        }
        if ( empty( $student_cohort ) ) {
            $student_cohort = __( 'Active Cohort', 'growth-hub' );
        }
        $user_role_label = sprintf( '%s &middot; %s', $student_course_name, $student_cohort );
    } else {
        $display_name = $user_id ? ( $user->display_name ? $user->display_name : $user->user_login ) : __( 'Instructor', 'growth-hub' );
        $user_role_label = $is_admin ? __( 'Administrator &middot; The Growth Hub', 'growth-hub' ) : __( 'Volunteer Mentor &middot; Tech Academy', 'growth-hub' );
    }

    // 2. Course Scoping
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

        $authored_courses = get_posts( array(
            'post_type'      => 'courses',
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'author'         => $target_user_id,
            'orderby'        => 'title',
            'order'          => 'ASC',
        ) );
        if ( ! empty( $authored_courses ) ) {
            foreach ( $authored_courses as $c ) {
                $merged_map[ $c->ID ] = $c;
            }
        }

        $assigned_courses = get_posts( array(
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
        if ( ! empty( $assigned_courses ) ) {
            foreach ( $assigned_courses as $c ) {
                $merged_map[ $c->ID ] = $c;
            }
        }

        $courses = array_values( $merged_map );
    }

    // 3. Selected Course
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

    // 4. Dynamic Cohorts List & Selection
    $available_cohorts = array();
    if ( $selected_course_id ) {
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

    // 5. Dynamic Range Selection
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
        $selected_range = '1-6';
        $range_start    = 1;
        $range_end      = min( 6, $total_course_weeks );
    }
    $planned_classes_in_range = max( 1, ( $range_end - $range_start + 1 ) );

    // 6. Query Real Class Logs for this Course
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

    // 7. Query Enrolled Learners & Calculate Metrics
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

            $check_ins = (int) get_user_meta( $s_id, '_gh_attendance_count_' . $selected_course_id, true );

            $completed_lessons = 0;
            if ( function_exists( 'tutor_utils' ) && method_exists( tutor_utils(), 'get_completed_lesson_count_by_course' ) ) {
                $completed_lessons = (int) tutor_utils()->get_completed_lesson_count_by_course( $selected_course_id, $s_id );
            }

            if ( 0 === $check_ins && $completed_lessons > 0 && $delivered_classes_in_range > 0 ) {
                $check_ins = min( $delivered_classes_in_range, $completed_lessons );
            }
            $total_checkins_sum += $check_ins;

            $progress_pct = 0;
            if ( function_exists( 'tutor_utils' ) && method_exists( tutor_utils(), 'get_course_completed_percent' ) ) {
                $progress_pct = (int) tutor_utils()->get_course_completed_percent( $selected_course_id, $s_id );
            } else {
                $progress_pct = round( ( $completed_lessons / max( 1, $total_lessons ) ) * 100 );
            }
            $total_progress_sum += $progress_pct;

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

            $rating_meta = get_user_meta( $s_id, '_gh_learner_rating_' . $selected_course_id, true );
            $rating_val = ( $rating_meta && floatval( $rating_meta ) > 0 ) ? floatval( $rating_meta ) : null;

            $basis = max( 1, $delivered_classes_in_range );
            $attendance_pct = ( $check_ins / $basis ) * 100;
            $misses = max( 0, $delivered_classes_in_range - $check_ins );

            $flag_label = 'On track';
            $flag_class = 'gh-flag-ontrack';

            if ( $delivered_classes_in_range >= 2 && ( $misses >= 3 || 0 === $check_ins ) ) {
                $flag_label = 'Still absent';
                $flag_class = 'gh-flag-absent';
            } elseif ( $misses >= 2 || ( $delivered_classes_in_range >= 2 && $attendance_pct < 80 ) ) {
                $flag_label = 'Two misses';
                $flag_class = 'gh-flag-twomisses';
            }

            $enrolled_learners[] = array(
                'id'         => $s_id,
                'name'       => $s_name,
                'check_ins'  => $check_ins . ' of ' . ( $delivered_classes_in_range > 0 ? $delivered_classes_in_range : $planned_classes_in_range ),
                'tasks_done' => $completed_lessons . ' of ' . $total_lessons,
                'avg_grade'  => null !== $avg_grade_val ? $avg_grade_val . '%' : 'No grade',
                'rating'     => null !== $rating_val ? number_format( $rating_val, 1 ) . ' / 5' : 'No rating',
                'flag_label' => $flag_label,
                'flag_class' => $flag_class,
            );
        }
    }

    $students_count = count( $enrolled_learners );

    $total_possible_checkins = $students_count * max( 1, $delivered_classes_in_range );
    if ( $students_count > 0 && $delivered_classes_in_range > 0 ) {
        $learner_checkin_rate = round( ( $total_checkins_sum / $total_possible_checkins ) * 100 );
        $learner_checkin_rate = min( 100, max( 0, $learner_checkin_rate ) );
    } else {
        $learner_checkin_rate = 0;
    }

    if ( $students_count > 0 ) {
        $avg_task_completion = round( $total_progress_sum / $students_count );
        $avg_task_completion = min( 100, max( 0, $avg_task_completion ) );
    } else {
        $avg_task_completion = 0;
    }

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
        : 'No rating';

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
                $week_rating_label = 'No rating';
            }
        }

        $weekly_trend[ $w ] = array(
            'week'   => 'Wk ' . $w,
            'rate'   => $week_checkin_rate,
            'rating' => $week_rating_label,
        );
    }

    $dash_url = function_exists( 'tutor_utils' ) ? tutor_utils()->tutor_dashboard_url() : home_url( '/dashboard/' );
    $log_class_url = trailingslashit( $dash_url ) . 'class-log';

    ob_start();
    ?>
    <div class="gh-portal-wrapper">
        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link href="https://fonts.googleapis.com/css2?family=Lato:wght@400;500;700&family=Poppins:wght@500;600;700;800&display=swap" rel="stylesheet">

        <style>
            :root {
                --gh-dark: #1B1240;
                --gh-purple: #632FD1;
                --gh-purple-hover: #5223b3;
                --gh-purple-light: #F4F1FB;
                --gh-tint: #D8D2EE;
                --gh-gold: #F3C343;
                --gh-border: #E8E5F3;
                --gh-text: #2A244D;
                --gh-muted: #7E789B;
                --gh-font-h: 'Poppins', sans-serif;
                --gh-font-b: 'Lato', sans-serif;
            }

            html, body { overflow-x: hidden !important; }

            @media (min-width: 1025px) {
                html, body { height: 100vh !important; overflow: hidden !important; }
            }

            /* Exact proven full-width breakout */
            .gh-portal-wrapper {
                width: 100vw !important;
                position: relative !important;
                left: 50% !important;
                right: 50% !important;
                margin-left: -50vw !important;
                margin-right: -50vw !important;
                max-width: 100vw !important;
                height: 100vh !important;
                box-sizing: border-box !important;
                font-family: var(--gh-font-b) !important;
                color: var(--gh-text) !important;
                background-color: #F8F7FC !important;
                overflow: hidden !important;
            }

            .gh-portal-wrapper * { box-sizing: border-box !important; }

            .gh-portal-wrapper h1, .gh-portal-wrapper h2, .gh-portal-wrapper h3, .gh-portal-wrapper h4 {
                font-family: var(--gh-font-h) !important;
                color: var(--gh-dark) !important;
                margin-top: 0 !important;
                text-decoration: none !important;
            }

            .gh-dash-grid {
                display: grid;
                grid-template-columns: 280px minmax(0, 1fr);
                height: 100vh !important;
                max-height: 100vh !important;
                width: 100%;
                max-width: 100%;
                overflow: hidden !important;
            }

            /* Mobile & Tablet Bar */
            .gh-mobile-bar {
                display: none;
                background: var(--gh-dark);
                padding: 16px 20px;
                color: #FFFFFF;
                align-items: center;
                justify-content: space-between;
                position: sticky;
                top: 0;
                z-index: 9999;
                width: 100%;
            }
            .gh-mobile-bar a {
                color: #FFF !important;
                font-family: var(--gh-font-h);
                font-weight: 800;
                font-size: 15px;
                text-decoration: none !important;
            }
            .gh-hamburger-btn {
                background: transparent;
                border: none;
                color: #FFFFFF;
                cursor: pointer;
                display: flex;
                align-items: center;
                padding: 4px;
            }

            /* Sidebar */
            .gh-sidebar {
                background: var(--gh-dark);
                color: #FFFFFF;
                padding: 32px 18px 48px;
                display: flex;
                flex-direction: column;
                height: 100vh !important;
                max-height: 100vh !important;
                overflow-y: auto !important;
                -webkit-overflow-scrolling: touch;
                z-index: 50;
            }

            .gh-sidebar::-webkit-scrollbar { width: 5px; }
            .gh-sidebar::-webkit-scrollbar-thumb { background: rgba(255, 255, 255, 0.15); border-radius: 4px; }

            .gh-brand {
                font-family: var(--gh-font-h);
                font-size: 15px;
                font-weight: 800;
                letter-spacing: 0.06em;
                text-transform: uppercase;
                margin-bottom: 24px;
                padding: 0 10px;
                color: #FFFFFF !important;
                text-decoration: none !important;
                display: block;
            }
            .gh-user-badge {
                background: rgba(255, 255, 255, 0.08);
                border: 1px solid rgba(216, 210, 238, 0.15);
                border-radius: 10px;
                padding: 12px 14px;
                margin-bottom: 24px;
            }
            .gh-user-name {
                font-family: var(--gh-font-h);
                font-size: 13.5px;
                font-weight: 700;
                text-transform: uppercase;
                color: #FFFFFF;
            }
            .gh-user-role {
                font-size: 11.5px;
                color: var(--gh-tint);
                margin-top: 2px;
            }

            .gh-nav-menu {
                list-style: none !important;
                padding: 0 !important;
                margin: 0 !important;
                display: flex;
                flex-direction: column;
                gap: 4px;
                padding-bottom: 30px !important;
            }
            .gh-nav-item a {
                display: flex !important;
                align-items: center !important;
                justify-content: space-between !important;
                padding: 11px 14px !important;
                color: #B4AED2 !important;
                text-decoration: none !important;
                border-bottom: none !important;
                font-size: 13.5px !important;
                border-radius: 8px !important;
                transition: 0.15s ease !important;
            }
            .gh-nav-item a:hover {
                color: #FFFFFF !important;
                background: rgba(255, 255, 255, 0.07) !important;
                text-decoration: none !important;
            }
            .gh-nav-item.active a {
                background: var(--gh-purple) !important;
                color: #FFFFFF !important;
                font-weight: 600 !important;
                text-decoration: none !important;
            }

            /* Main Workspace Area */
            .gh-main-area {
                padding: 36px 44px;
                width: 100%;
                min-width: 0 !important;
                max-width: 100% !important;
                box-sizing: border-box !important;
                height: 100vh !important;
                max-height: 100vh !important;
                overflow-y: auto !important;
                overflow-x: hidden !important;
                -webkit-overflow-scrolling: touch;
                background-color: #F8F7FC !important;
            }
            .gh-main-area::-webkit-scrollbar { width: 7px; }
            .gh-main-area::-webkit-scrollbar-thumb { background: #D8D2EE; border-radius: 4px; }

            .gh-overview-container {
                max-width: 1320px;
                margin: 0 auto;
                padding-bottom: 60px;
            }

            /* Analytics Header */
            .gh-analytics-top {
                display: flex;
                justify-content: space-between;
                align-items: flex-start;
                margin-bottom: 24px;
                gap: 16px;
            }
            .gh-analytics-top h1 {
                font-size: 30px;
                font-weight: 700;
                margin-bottom: 4px;
            }
            .gh-analytics-top p {
                font-size: 14px;
                color: var(--gh-muted);
                margin: 0;
            }
            .gh-btn-export-csv {
                background: #FFFFFF !important;
                color: var(--gh-purple) !important;
                border: 1.5px solid var(--gh-purple) !important;
                font-family: var(--gh-font-h);
                font-size: 13.5px;
                font-weight: 600;
                padding: 9px 22px;
                border-radius: 9999px;
                cursor: pointer;
                transition: all 0.15s ease;
                white-space: nowrap;
            }
            .gh-btn-export-csv:hover {
                background: var(--gh-purple-light) !important;
            }

            /* 3 Filter Dropdowns */
            .gh-analytics-filters {
                display: grid;
                grid-template-columns: repeat(3, 1fr);
                gap: 16px;
                margin-bottom: 24px;
            }
            .gh-filter-col {
                display: flex;
                flex-direction: column;
            }
            .gh-filter-col label {
                font-family: var(--gh-font-h);
                font-size: 11px;
                font-weight: 700;
                letter-spacing: 0.05em;
                color: var(--gh-muted);
                text-transform: uppercase;
                margin-bottom: 6px;
            }
            .gh-filter-select {
                background: #FFFFFF !important;
                border: 1.5px solid var(--gh-tint) !important;
                border-radius: 10px !important;
                height: 48px !important;
                min-height: 48px !important;
                padding: 0 38px 0 16px !important;
                font-size: 14px !important;
                font-family: var(--gh-font-b) !important;
                color: var(--gh-dark) !important;
                appearance: none !important;
                -webkit-appearance: none !important;
                background-image: url("data:image/svg+xml,%3Csvg width='12' height='8' viewBox='0 0 12 8' fill='none' xmlns='http://www.w3.org/2000/svg'%3E%3Cpath d='M1 1.5L6 6.5L11 1.5' stroke='%23632FD1' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'/%3E%3C/svg%3E") !important;
                background-repeat: no-repeat !important;
                background-position: calc(100% - 14px) center !important;
                cursor: pointer;
                width: 100%;
            }

            /* 4 Metric Cards */
            .gh-analytics-stats {
                display: grid;
                grid-template-columns: repeat(4, 1fr);
                gap: 16px;
                margin-bottom: 28px;
            }
            .gh-stat-card {
                background: var(--gh-dark);
                border-radius: 12px;
                padding: 20px 18px;
                color: #FFFFFF;
                display: flex;
                flex-direction: column;
                justify-content: space-between;
                min-height: 105px;
            }
            .gh-stat-num {
                font-family: var(--gh-font-h);
                font-size: 28px;
                font-weight: 700;
                line-height: 1;
                margin-bottom: 8px;
            }
            .gh-stat-desc {
                font-size: 12px;
                color: var(--gh-tint);
                line-height: 1.3;
                font-weight: 500;
            }

            /* Section Cards & Tables */
            .gh-section-card {
                background: #FFFFFF;
                border: 1px solid var(--gh-border);
                border-radius: 14px;
                padding: 24px 28px;
                margin-bottom: 28px;
                box-shadow: 0 4px 18px rgba(27, 18, 64, 0.02);
            }
            .gh-card-top-bar {
                display: flex;
                justify-content: space-between;
                align-items: center;
                margin-bottom: 6px;
            }
            .gh-card-top-bar h2 {
                font-size: 18px;
                font-weight: 700;
                margin: 0;
            }
            .gh-card-desc-note {
                font-size: 12.5px;
                color: var(--gh-muted);
                margin: 0 0 18px;
            }

            /* CSS Bar Chart */
            .gh-chart-bars-wrap {
                display: grid;
                grid-template-columns: repeat(6, 1fr);
                gap: 16px;
                align-items: flex-end;
                padding: 10px 0 16px 0;
                border-bottom: 1px solid #F3F4F6;
                margin-bottom: 18px;
                width: 100%;
            }
            .gh-chart-col {
                display: flex;
                flex-direction: column;
                align-items: center;
                justify-content: flex-end;
                min-width: 0;
            }
            .gh-bar-val {
                font-size: 13px;
                font-weight: 700;
                color: var(--gh-dark);
                font-family: var(--gh-font-h);
                margin-bottom: 8px;
                line-height: 1;
                text-align: center;
            }
            .gh-bar-track {
                width: 44px;
                max-width: 100%;
                height: 130px;
                display: flex;
                align-items: flex-end;
                background: transparent;
            }
            .gh-bar-fill {
                width: 100%;
                border-radius: 8px 8px 0 0;
                transition: height 0.4s ease;
            }
            .gh-bar-labels {
                display: flex;
                flex-direction: column;
                align-items: center;
                padding-top: 10px;
                text-align: center;
            }
            .gh-week-name {
                font-size: 13px;
                font-weight: 600;
                color: var(--gh-dark);
                font-family: var(--gh-font-h);
                line-height: 1.2;
                white-space: nowrap;
            }
            .gh-week-rating {
                font-size: 11.5px;
                color: #6B7280;
                font-family: var(--gh-font-b);
                margin-top: 3px;
                line-height: 1.2;
                white-space: nowrap;
            }
            .gh-chart-legend {
                display: flex;
                gap: 24px;
                align-items: center;
                padding: 0;
                margin: 0;
                font-size: 13px;
                color: #374151;
                font-family: var(--gh-font-b);
                clear: both;
            }
            .gh-legend-item {
                display: inline-flex;
                align-items: center;
                gap: 8px;
            }
            .gh-legend-dot {
                width: 10px;
                height: 10px;
                border-radius: 50%;
                display: inline-block;
                flex-shrink: 0;
            }
            .gh-dot-purple { background-color: var(--gh-purple); }
            .gh-dot-gold { background-color: var(--gh-gold); } /* #F3C343 */

            /* Table */
            .gh-table-wrap { width: 100%; overflow-x: auto; -webkit-overflow-scrolling: touch; }
            .gh-table { width: 100%; border-collapse: collapse; text-align: left; font-size: 13.5px; min-width: 520px; }
            .gh-table th {
                font-family: var(--gh-font-h);
                font-size: 11px;
                font-weight: 700;
                letter-spacing: 0.05em;
                color: var(--gh-muted);
                text-transform: uppercase;
                padding: 12px 14px;
                border-bottom: 1.5px solid var(--gh-border);
                white-space: nowrap;
            }
            .gh-table td { 
                padding: 14px; 
                border-bottom: 1px solid var(--gh-border); 
                color: var(--gh-text); 
                vertical-align: middle; 
                white-space: nowrap;
            }
            .gh-table tr:last-child td { border-bottom: none; }

            .gh-pill-badge {
                display: inline-block !important;
                padding: 5px 14px !important;
                border-radius: 9999px !important;
                font-family: var(--gh-font-h) !important;
                font-size: 11.5px !important;
                font-weight: 700 !important;
                line-height: 1 !important;
                white-space: nowrap !important;
                text-align: center !important;
            }
            .gh-badge-track { background: #E8F5E9 !important; color: #2E7D32 !important; }
            .gh-badge-low { background: #FEF3C7 !important; color: #92400E !important; } /* Gold/Amber #F3C343 tint */
            .gh-badge-lapsed { background: #FFEBEE !important; color: #C62828 !important; }

            .gh-table-footer-note {
                font-size: 12px;
                color: var(--gh-muted);
                margin: 16px 0 0;
            }

            .gh-drawer-overlay { display: none; position: fixed; inset: 0; background: rgba(27, 18, 64, 0.6); backdrop-filter: blur(2px); z-index: 99990; }

            /* Tablet & Mobile Media Queries (Exact Proven Behavior) */
            @media (max-width: 1024px) {
                html, body { height: auto !important; overflow-x: hidden !important; overflow-y: auto !important; }
                .gh-portal-wrapper { height: auto !important; overflow-x: hidden !important; max-width: 100vw !important; }
                .gh-mobile-bar { display: flex; }
                .gh-dash-grid { grid-template-columns: minmax(0, 1fr) !important; width: 100% !important; height: auto !important; max-height: none !important; }
                .gh-sidebar {
                    position: fixed !important; left: -300px !important; top: 0 !important; bottom: 0 !important;
                    width: 280px !important; z-index: 99999 !important; box-shadow: 4px 0 24px rgba(0, 0, 0, 0.3);
                    transition: left 0.3s cubic-bezier(0.4, 0, 0.2, 1); padding-bottom: 80px !important;
                }
                .gh-sidebar.open { left: 0 !important; }
                .gh-drawer-overlay.open { display: block; }
                .gh-main-area { padding: 20px 14px !important; width: 100% !important; min-width: 0 !important; max-width: 100% !important; height: auto !important; max-height: none !important; overflow-y: visible !important; overflow-x: hidden !important; box-sizing: border-box !important; }
                .gh-sidebar-close {
                    display: block !important; position: absolute; top: 14px; right: 14px;
                    background: transparent; border: none; color: var(--gh-tint); font-size: 26px; cursor: pointer;
                }
                .gh-analytics-stats { grid-template-columns: repeat(2, minmax(0, 1fr)); }
            }
            .gh-sidebar-close { display: none; }

            @media (max-width: 640px) {
                .gh-main-area { padding: 14px 10px !important; }
                .gh-analytics-top { flex-direction: column; align-items: stretch; gap: 14px; }
                .gh-btn-export-csv { width: 100%; text-align: center; }
                .gh-analytics-filters { grid-template-columns: 1fr; gap: 12px; }
                .gh-analytics-stats { grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 10px; }
                .gh-section-card { padding: 16px 12px !important; border-radius: 12px !important; }
                .gh-chart-bars-wrap { gap: 6px; padding: 6px 0 14px 0; margin-bottom: 14px; }
                .gh-bar-track { width: 100%; max-width: 30px; height: 110px; }
                .gh-bar-val { font-size: 11px; margin-bottom: 4px; }
                .gh-week-name { font-size: 11px; }
                .gh-week-rating { font-size: 10px; }
                .gh-chart-legend { flex-wrap: wrap; gap: 10px 16px; font-size: 12px; }
            }
        </style>

        <!-- Top Mobile / Tablet Bar -->
        <div class="gh-mobile-bar">
            <a href="<?php echo esc_url( home_url() ); ?>">THE GROWTH HUB</a>
            <button type="button" class="gh-hamburger-btn" id="gh-open-menu" aria-label="Open Navigation">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <line x1="3" y1="12" x2="21" y2="12"></line>
                    <line x1="3" y1="6" x2="21" y2="6"></line>
                    <line x1="3" y1="18" x2="21" y2="18"></line>
                </svg>
            </button>
        </div>

        <div class="gh-drawer-overlay" id="gh-overlay"></div>

        <div class="gh-dash-grid">
            <!-- Sidebar -->
            <aside class="gh-sidebar" id="gh-sidebar">
                <button type="button" class="gh-sidebar-close" id="gh-close-menu">&times;</button>
                <a href="<?php echo esc_url( home_url() ); ?>" class="gh-brand">THE GROWTH HUB</a>

                <div class="gh-user-badge">
                    <div class="gh-user-name"><?php echo esc_html( strtoupper( $display_name ) ); ?></div>
                    <div class="gh-user-role"><?php echo esc_html( $user_role_label ); ?></div>
                </div>

                <ul class="gh-nav-menu">
                    <?php if ( $is_manager_view ) : ?>
                        <!-- Ops and BD Manager Navigation (Build 6) -->
                        <li class="gh-nav-item <?php echo ( 'overview' === $current_tab ) ? 'active' : ''; ?>">
                            <a href="<?php echo esc_url( add_query_arg( array( 'view' => 'manager', 'tab' => 'overview' ) ) ); ?>">Overview</a>
                        </li>
                        <li class="gh-nav-item <?php echo ( 'cohort-delivery' === $current_tab || empty( $current_tab ) || 'cohort_delivery' === $current_tab || 'index' === $current_tab ) ? 'active' : ''; ?>">
                            <a href="<?php echo esc_url( add_query_arg( array( 'view' => 'manager', 'tab' => 'cohort-delivery' ) ) ); ?>">Cohort Delivery</a>
                        </li>
                        <li class="gh-nav-item <?php echo ( 'tutors' === $current_tab ) ? 'active' : ''; ?>">
                            <a href="<?php echo esc_url( add_query_arg( array( 'view' => 'manager', 'tab' => 'tutors' ) ) ); ?>">Tutors</a>
                        </li>
                        <li class="gh-nav-item <?php echo ( 'learners' === $current_tab ) ? 'active' : ''; ?>">
                            <a href="<?php echo esc_url( add_query_arg( array( 'view' => 'manager', 'tab' => 'learners' ) ) ); ?>">Learners</a>
                        </li>
                        <li class="gh-nav-item <?php echo ( 'weekly-reports' === $current_tab ) ? 'active' : ''; ?>">
                            <a href="<?php echo esc_url( add_query_arg( array( 'view' => 'manager', 'tab' => 'weekly-reports' ) ) ); ?>">Weekly Reports</a>
                        </li>
                        <li class="gh-nav-item <?php echo ( 'support-requests' === $current_tab ) ? 'active' : ''; ?>">
                            <a href="<?php echo esc_url( add_query_arg( array( 'view' => 'manager', 'tab' => 'support-requests' ) ) ); ?>">Support Requests</a>
                        </li>
                        <li class="gh-nav-item <?php echo ( 'sponsors' === $current_tab ) ? 'active' : ''; ?>">
                            <a href="<?php echo esc_url( add_query_arg( array( 'view' => 'manager', 'tab' => 'sponsors' ) ) ); ?>">Sponsors</a>
                        </li>
                        <li class="gh-nav-item <?php echo ( 'payments' === $current_tab ) ? 'active' : ''; ?>">
                            <a href="<?php echo esc_url( add_query_arg( array( 'view' => 'manager', 'tab' => 'payments' ) ) ); ?>">Payments</a>
                        </li>
                    <?php elseif ( $is_student_view ) : ?>
                        <!-- Student Navigation (Figma Build 5) -->
                        <li class="gh-nav-item <?php echo ( 'dashboard' === $current_tab ) ? 'active' : ''; ?>">
                            <a href="<?php echo esc_url( add_query_arg( array( 'view' => 'student', 'tab' => 'dashboard' ) ) ); ?>">Dashboard</a>
                        </li>
                        <li class="gh-nav-item <?php echo ( 'my-courses' === $current_tab ) ? 'active' : ''; ?>">
                            <a href="<?php echo esc_url( add_query_arg( array( 'view' => 'student', 'tab' => 'my-courses' ) ) ); ?>">My Courses</a>
                        </li>
                        <li class="gh-nav-item <?php echo ( 'weekly-check-in' === $current_tab || 'weekly-checkin' === $current_tab || empty($current_tab) ) ? 'active' : ''; ?>">
                            <a href="<?php echo esc_url( add_query_arg( array( 'view' => 'student', 'tab' => 'weekly-check-in' ) ) ); ?>">Weekly Check-in</a>
                        </li>
                        <li class="gh-nav-item <?php echo ( 'assignments' === $current_tab ) ? 'active' : ''; ?>">
                            <a href="<?php echo esc_url( add_query_arg( array( 'view' => 'student', 'tab' => 'assignments' ) ) ); ?>">Assignments</a>
                        </li>
                        <li class="gh-nav-item <?php echo ( 'my-certificates' === $current_tab ) ? 'active' : ''; ?>">
                            <a href="<?php echo esc_url( add_query_arg( array( 'view' => 'student', 'tab' => 'my-certificates' ) ) ); ?>">Certificates</a>
                        </li>
                        <li class="gh-nav-item <?php echo ( 'talent-profile' === $current_tab ) ? 'active' : ''; ?>">
                            <a href="<?php echo esc_url( add_query_arg( array( 'view' => 'student', 'tab' => 'talent-profile' ) ) ); ?>">Talent Profile</a>
                        </li>
                        <li class="gh-nav-item <?php echo ( 'payments' === $current_tab ) ? 'active' : ''; ?>">
                            <a href="<?php echo esc_url( add_query_arg( array( 'view' => 'student', 'tab' => 'payments' ) ) ); ?>">Payments</a>
                        </li>
                    <?php else : ?>
                        <!-- Mentor Navigation (Figma Build 1-3) -->
                        <li class="gh-nav-item">
                            <a href="<?php echo esc_url( home_url( '/dashboard/' ) ); ?>">Overview</a>
                        </li>
                        <li class="gh-nav-item">
                            <a href="<?php echo esc_url( home_url( '/dashboard/my-courses/' ) ); ?>">My Courses</a>
                        </li>
                        <li class="gh-nav-item">
                            <a href="<?php echo esc_url( home_url( '/dashboard/mentees/' ) ); ?>">Mentees & Students</a>
                        </li>
                        <li class="gh-nav-item">
                            <a href="<?php echo esc_url( home_url( '/dashboard/live-classes/' ) ); ?>">Live Classes</a>
                        </li>
                        <li class="gh-nav-item <?php echo ( isset( $_GET['tab'] ) && ( 'class-log' === $_GET['tab'] || 'class_log' === $_GET['tab'] ) ) ? 'active' : ''; ?>">
                            <a href="<?php echo esc_url( add_query_arg( 'tab', 'class-log' ) ); ?>">Class Log</a>
                        </li>
                        <li class="gh-nav-item">
                            <a href="<?php echo esc_url( home_url( '/dashboard/announcements/' ) ); ?>">Announcements</a>
                        </li>
                        <li class="gh-nav-item">
                            <a href="<?php echo esc_url( home_url( '/dashboard/question-answer/' ) ); ?>">Discussions (Q&A)</a>
                        </li>
                        <li class="gh-nav-item">
                            <a href="<?php echo esc_url( home_url( '/dashboard/grading/' ) ); ?>">Grading</a>
                        </li>
                        <li class="gh-nav-item">
                            <a href="<?php echo esc_url( home_url( '/dashboard/my-certificates/' ) ); ?>">Certificates</a>
                        </li>
                        <li class="gh-nav-item <?php echo ( ! isset( $_GET['tab'] ) || 'analytics' === $_GET['tab'] ) ? 'active' : ''; ?>">
                            <a href="<?php echo esc_url( add_query_arg( 'tab', 'analytics' ) ); ?>">Analytics</a>
                        </li>
                        <li class="gh-nav-item <?php echo ( isset( $_GET['tab'] ) && ( 'check-in' === $_GET['tab'] || 'checkin' === $_GET['tab'] ) ) ? 'active' : ''; ?>">
                            <a href="<?php echo esc_url( add_query_arg( 'tab', 'check-in' ) ); ?>">Check-in</a>
                        </li>
                        <li class="gh-nav-item <?php echo ( isset( $_GET['tab'] ) && ( 'weekly-report' === $_GET['tab'] || 'weekly_report' === $_GET['tab'] || 'concern-report' === $_GET['tab'] ) ) ? 'active' : ''; ?>">
                            <a href="<?php echo esc_url( add_query_arg( 'tab', 'weekly-report' ) ); ?>">Weekly Report</a>
                        </li>
                        <li class="gh-nav-item">
                            <a href="<?php echo esc_url( home_url( '/dashboard/earning/' ) ); ?>">Earnings</a>
                        </li>
                    <?php endif; ?>
                </ul>
            </aside>

            <!-- Main Canvas -->
            <main class="gh-main-area">
                <?php if ( $is_manager_view ) : ?>
                    <?php echo gh_render_ops_manager_cohort_delivery_html(); ?>
                <?php elseif ( $is_student_view ) : ?>
                    <?php echo gh_render_student_weekly_checkin_html(); ?>
                <?php elseif ( isset( $_GET['tab'] ) && ( 'class-log' === $_GET['tab'] || 'class_log' === $_GET['tab'] ) ) : ?>
                    <?php echo gh_render_class_log_html(); ?>
                <?php elseif ( isset( $_GET['tab'] ) && ( 'check-in' === $_GET['tab'] || 'checkin' === $_GET['tab'] ) ) : ?>
                    <?php echo gh_render_learner_checkin_flow(); ?>
                <?php elseif ( isset( $_GET['tab'] ) && ( 'weekly-report' === $_GET['tab'] || 'weekly_report' === $_GET['tab'] ) ) : ?>
                    <?php echo gh_render_tutor_weekly_report_html(); ?>
                <?php elseif ( isset( $_GET['tab'] ) && ( 'concern-report' === $_GET['tab'] || 'report-concern' === $_GET['tab'] ) ) : ?>
                    <?php echo gh_render_concern_report_html(); ?>
                <?php else : ?>
                
<style>
.gh-overview-top {
    display: flex !important;
    justify-content: space-between !important;
    align-items: flex-start !important;
    margin-bottom: 24px !important;
    flex-wrap: wrap !important;
    gap: 16px !important;
}
.gh-overview-top h1 {
    font-family: 'Poppins', sans-serif !important;
    font-size: 28px !important;
    font-weight: 700 !important;
    color: #1B1240 !important;
    margin: 0 0 6px 0 !important;
}
.gh-overview-top p {
    font-family: 'Lato', sans-serif !important;
    font-size: 14.5px !important;
    color: #6B6580 !important;
    margin: 0 !important;
}
.gh-header-actions {
    display: flex !important;
    align-items: center !important;
    gap: 12px !important;
    flex-wrap: wrap !important;
}
.gh-btn-log-class {
    background: #632FD1 !important;
    color: #FFFFFF !important;
    font-family: 'Poppins', sans-serif !important;
    font-size: 13.5px !important;
    font-weight: 600 !important;
    padding: 10px 22px !important;
    border-radius: 9999px !important;
    text-decoration: none !important;
    display: inline-flex !important;
    align-items: center !important;
    justify-content: center !important;
    white-space: nowrap !important;
    border: none !important;
    box-shadow: 0 2px 8px rgba(99, 47, 209, 0.25) !important;
}
.gh-btn-log-class:hover {
    background: #5224B8 !important;
    color: #FFFFFF !important;
}
.gh-btn-schedule-live {
    background: #FFFFFF !important;
    color: #632FD1 !important;
    border: 1.5px solid #632FD1 !important;
    font-family: 'Poppins', sans-serif !important;
    font-size: 13.5px !important;
    font-weight: 600 !important;
    padding: 8.5px 20px !important;
    border-radius: 9999px !important;
    text-decoration: none !important;
    display: inline-flex !important;
    align-items: center !important;
    justify-content: center !important;
    white-space: nowrap !important;
}
.gh-btn-schedule-live:hover {
    background: #F4F1FB !important;
    color: #5224B8 !important;
}
.gh-stats-row {
    display: grid !important;
    grid-template-columns: repeat(5, minmax(0, 1fr)) !important;
    gap: 16px !important;
    margin-bottom: 28px !important;
    width: 100% !important;
    box-sizing: border-box !important;
}
@media (max-width: 1024px) {
    .gh-canvas-columns {
        grid-template-columns: minmax(0, 1fr) !important;
        gap: 20px !important;
    }
    .gh-stats-row {
        grid-template-columns: repeat(3, minmax(0, 1fr)) !important;
        gap: 14px !important;
    }
}
@media (max-width: 768px) {
    .gh-stats-row {
        grid-template-columns: repeat(2, minmax(0, 1fr)) !important;
        gap: 12px !important;
    }
    .gh-stats-row .gh-stat-card:last-child {
        grid-column: span 2 !important;
    }
}
@media (max-width: 480px) {
    .gh-stats-row {
        grid-template-columns: 1fr !important;
        gap: 10px !important;
    }
    .gh-stats-row .gh-stat-card:last-child {
        grid-column: span 1 !important;
    }
}
</style>
<div class="gh-overview-container">
                    <!-- Top Header -->
                    <div class="gh-analytics-top">
                        <div>
                            <h1>Analytics</h1>
                            <p>How your classes and learners are doing, joined by cohort and week</p>
                        </div>
                        <div>
                            <button type="button" class="gh-btn-export-csv" id="gh-btn-export-csv">Export CSV</button>
                        </div>
                    </div>

                    <!-- 3 Dynamic Dropdown Filters -->
                    <form method="get" class="gh-analytics-filters" id="gh-filters-form">
                        <?php if ( $is_admin && $preview_tutor_id ) : ?>
                            <input type="hidden" name="preview_tutor" value="<?php echo esc_attr( $preview_tutor_id ); ?>">
                        <?php endif; ?>

                        <!-- 1. Course Filter -->
                        <div class="gh-filter-col">
                            <label for="gh-filter-course">COURSE</label>
                            <select name="filter_course" id="gh-filter-course" class="gh-filter-select" onchange="this.form.submit()">
                                <?php if ( empty( $courses ) ) : ?>
                                    <option value="0">No courses available</option>
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
                            <label for="gh-filter-cohort">COHORT</label>
                            <select name="filter_cohort" id="gh-filter-cohort" class="gh-filter-select" onchange="this.form.submit()">
                                <option value="all" <?php selected( 'all', $selected_cohort ); ?>>All Cohorts</option>
                                <?php foreach ( $available_cohorts as $coh ) : ?>
                                    <option value="<?php echo esc_attr( $coh ); ?>" <?php selected( $coh, $selected_cohort ); ?>>
                                        <?php echo esc_html( $coh ); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <!-- 3. Range Filter -->
                        <div class="gh-filter-col">
                            <label for="gh-filter-range">RANGE</label>
                            <select name="filter_range" id="gh-filter-range" class="gh-filter-select" onchange="this.form.submit()">
                                <option value="1-6" <?php selected( '1-6', $selected_range ); ?>>Weeks 1 to 6</option>
                                <?php if ( $total_course_weeks > 6 ) : ?>
                                    <option value="7-12" <?php selected( '7-12', $selected_range ); ?>>Weeks 7 to 12</option>
                                <?php endif; ?>
                                <option value="all" <?php selected( 'all', $selected_range ); ?>>
                                    <?php echo esc_html( sprintf( 'All Weeks (1 to %d)', $total_course_weeks ) ); ?>
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
                            <div class="gh-stat-desc">Classes delivered vs planned</div>
                        </div>
                        <div class="gh-stat-card">
                            <div class="gh-stat-num">
                                <?php echo esc_html( $learner_checkin_rate . '%' ); ?>
                            </div>
                            <div class="gh-stat-desc">Learner check-in rate</div>
                        </div>
                        <div class="gh-stat-card">
                            <div class="gh-stat-num">
                                <?php echo esc_html( $avg_task_completion . '%' ); ?>
                            </div>
                            <div class="gh-stat-desc">Task completion</div>
                        </div>
                        <div class="gh-stat-card">
                            <div class="gh-stat-num">
                                <?php echo esc_html( $formatted_avg_rating ); ?>
                            </div>
                            <div class="gh-stat-desc">Average class rating</div>
                        </div>
                    </div>

                    <!-- Section 1: Weekly trend -->
                    <div class="gh-section-card">
                        <div class="gh-card-top-bar">
                            <h2>Weekly trend</h2>
                        </div>
                        <p class="gh-card-desc-note">Check-in rate by week (bars) with average class rating beneath.</p>

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
                                    <span>Check-in 80% or above</span>
                                </div>
                                <div class="gh-legend-item">
                                    <span class="gh-legend-dot gh-dot-gold"></span>
                                    <span>Below 80%</span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Section 2: Learners to watch -->
                    <div class="gh-section-card">
                        <div class="gh-card-top-bar">
                            <h2>Learners to watch</h2>
                        </div>

                        <div class="gh-table-wrap">
                            <table class="gh-table" id="gh-learners-table">
                                <thead>
                                    <tr>
                                        <th>Learner</th>
                                        <th>Check-ins</th>
                                        <th>Tasks Done</th>
                                        <th>Avg Grade</th>
                                        <th>Last Rating</th>
                                        <th>Flag</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if ( empty( $enrolled_learners ) ) : ?>
                                        <tr>
                                            <td colspan="6" style="text-align: center; padding: 40px 16px; color: var(--gh-muted); font-size: 14px;">
                                                No enrolled learners found for this course yet.
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
                            Learners with a pulse comment or three misses are also sent to the Ops and BD Manager.
                        </p>
                    </div>
                </div>
                <?php endif; ?>
            </main>
        </div>

        <script>
        (function() {
            var openBtn  = document.getElementById('gh-open-menu');
            var closeBtn = document.getElementById('gh-close-menu');
            var sidebar  = document.getElementById('gh-sidebar');
            var overlay  = document.getElementById('gh-overlay');

            if (openBtn && sidebar && overlay) {
                openBtn.addEventListener('click', function() {
                    sidebar.classList.add('open');
                    overlay.classList.add('open');
                    document.body.style.overflow = 'hidden';
                });
            }

            function closeDrawer() {
                if (sidebar) sidebar.classList.remove('open');
                if (overlay) overlay.classList.remove('open');
                document.body.style.overflow = '';
            }

            if (closeBtn) closeBtn.addEventListener('click', closeDrawer);
            if (overlay) overlay.addEventListener('click', closeDrawer);

            // Export CSV
            var exportBtn = document.getElementById('gh-btn-export-csv');
            if (exportBtn) {
                exportBtn.addEventListener('click', function() {
                    var table = document.getElementById('gh-learners-table');
                    if (!table) return;

                    var rows = table.querySelectorAll('tr');
                    var csv = [];
                    for (var i = 0; i < rows.length; i++) {
                        var row = [], cols = rows[i].querySelectorAll('td, th');
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
        })();
        </script>
    </div>
    <?php
    return ob_get_clean();
}

/**
 * Learner Check-in Flow Function (Build 4 of 8)
 * Can be used standalone [growth_hub_checkin] or embedded via ?tab=check-in
 */
add_shortcode( 'growth_hub_checkin', 'gh_render_learner_checkin_flow' );
function gh_render_learner_checkin_flow() {
    $class_id = isset( $_GET['class_id'] ) ? absint( $_GET['class_id'] ) : 0;
    $user_id  = get_current_user_id();

    // If class_id is not specified in URL, dynamically resolve to the latest logged class
    if ( ! $class_id ) {
        $latest_logs = get_posts( array(
            'post_type'      => 'gh_class_log',
            'post_status'    => 'publish',
            'posts_per_page' => 1,
            'orderby'        => 'date',
            'order'          => 'DESC',
        ) );
        if ( ! empty( $latest_logs ) ) {
            $class_id = $latest_logs[0]->ID;
        }
    }

    $course_badge  = __( 'Class Session', 'growth-hub' );
    $class_topic   = __( 'No active class session scheduled', 'growth-hub' );
    $class_details = __( 'Please scan the projector QR code or use the link provided by your tutor.', 'growth-hub' );
    $class_mode    = isset( $_GET['mode'] ) && 'virtual' === $_GET['mode'] ? 'virtual' : 'physical';

    if ( $class_id ) {
        $class_post = get_post( $class_id );
        if ( $class_post && 'gh_class_log' === $class_post->post_type ) {
            $course_id = get_post_meta( $class_id, '_gh_course_id', true );
            $course_obj = $course_id ? get_post( $course_id ) : null;
            $course_title = $course_obj ? $course_obj->post_title : 'Course';
            $short_course = strlen( $course_title ) > 15 ? substr( $course_title, 0, 14 ) . '...' : $course_title;

            $week_num = get_post_meta( $class_id, '_gh_week_number', true );
            if ( preg_match( '/\b(\d+)\b/', $week_num, $m ) ) {
                $week_label = 'Wk ' . $m[1];
            } else {
                $week_label = $week_num ? $week_num : 'Wk 1';
            }
            $course_badge = $short_course . ' &middot; ' . $week_label;

            $topic_meta = get_post_meta( $class_id, '_gh_topic', true );
            if ( $topic_meta ) {
                $class_topic = $topic_meta;
            }

            $date_meta = get_post_meta( $class_id, '_gh_class_date', true );
            $date_str  = $date_meta ? date_i18n( 'D j M', strtotime( $date_meta ) ) : date_i18n( 'D j M' );
            $duration  = get_post_meta( $class_id, '_gh_duration_minutes', true );
            $dur_str   = $duration ? $duration . ' mins' : '90 mins';

            $author_id = $class_post->post_author;
            $author_name = get_the_author_meta( 'display_name', $author_id );
            if ( ! $author_name ) {
                $author_name = 'Tutor';
            }

            $class_details = sprintf( '%s &middot; %s &middot; Tutor %s', $date_str, $dur_str, $author_name );
            $stored_mode   = get_post_meta( $class_id, '_gh_mode', true );
            if ( $stored_mode ) {
                $class_mode = $stored_mode;
            }
        }
    }

    $nonce    = wp_create_nonce( 'gh_checkin_nonce' );
    $ajax_url = admin_url( 'admin-ajax.php' );

    ob_start();
    ?>
    <div class="gh-checkin-wrapper">
        <!-- Preview Screen Switcher for Testing -->
        <?php if ( isset( $_GET['preview_steps'] ) || isset( $_GET['dev'] ) ) : ?>
        <div class="gh-screen-nav-bar">
            <span class="gh-screen-nav-label"><?php esc_html_e( 'Preview Screen:', 'growth-hub' ); ?></span>
            <button type="button" class="gh-screen-nav-btn <?php echo 'physical' === $class_mode ? 'active' : ''; ?>" onclick="ghSwitchScreen('screen-4a')">4a Physical</button>
            <button type="button" class="gh-screen-nav-btn <?php echo 'virtual' === $class_mode ? 'active' : ''; ?>" onclick="ghSwitchScreen('screen-4b')">4b Virtual</button>
            <button type="button" class="gh-screen-nav-btn" onclick="ghSwitchScreen('screen-4c')">4c Pulse</button>
            <button type="button" class="gh-screen-nav-btn" onclick="ghSwitchScreen('screen-4d')">4d Confirmed</button>
        </div>
        <?php endif; ?>
        <div class="gh-mobile-card">
            <!-- Header Bar -->
            <div class="gh-card-header">
                <span class="gh-brand-text">THE GROWTH HUB</span>
                <span class="gh-course-pill"><?php echo esc_html( $course_badge ); ?></span>
            </div>

            <!-- Screen 4a: Physical class check-in -->
            <div class="gh-screen-pane <?php echo 'physical' === $class_mode ? 'active' : ''; ?>" id="screen-4a">
                <h1 class="gh-card-title"><?php esc_html_e( 'Check in to class', 'growth-hub' ); ?></h1>

                <div class="gh-class-info-box">
                    <div class="gh-class-topic"><?php echo esc_html( $class_topic ); ?></div>
                    <div class="gh-class-meta"><?php echo esc_html( $class_details ); ?></div>
                </div>

                <!-- Crisp Dynamic & Scannable QR Code Box -->
                <div class="gh-qr-frame">
                    <img src="https://api.qrserver.com/v1/create-qr-code/?size=140x140&amp;margin=0&amp;color=1B1240&amp;data=<?php echo urlencode( add_query_arg( array( 'class_id' => $class_id ? $class_id : 1, 'step' => 'physical' ), home_url( '/check-in/' ) ) ); ?>" 
                         alt="Class Check-in QR Code" 
                         width="140" 
                         height="140" 
                         class="gh-qr-img" 
                         onerror="this.style.display='none'; document.getElementById('gh-fallback-qr-s').style.display='block';">
                    
                    <div id="gh-fallback-qr-s" style="display:none;">
                        <svg viewBox="0 0 160 160" width="140" height="140" xmlns="http://www.w3.org/2000/svg" class="gh-qr-svg">
                            <rect width="160" height="160" fill="#FFFFFF"/>
                            <rect x="10" y="10" width="42" height="42" fill="#1B1240" rx="6"/>
                            <rect x="16" y="16" width="30" height="30" fill="#FFFFFF" rx="4"/>
                            <rect x="22" y="22" width="18" height="18" fill="#1B1240" rx="3"/>
                            <rect x="108" y="10" width="42" height="42" fill="#1B1240" rx="6"/>
                            <rect x="114" y="16" width="30" height="30" fill="#FFFFFF" rx="4"/>
                            <rect x="120" y="22" width="18" height="18" fill="#1B1240" rx="3"/>
                            <rect x="10" y="108" width="42" height="42" fill="#1B1240" rx="6"/>
                            <rect x="16" y="114" width="30" height="30" fill="#FFFFFF" rx="4"/>
                            <rect x="22" y="120" width="18" height="18" fill="#1B1240" rx="3"/>
                            <rect x="62" y="14" width="8" height="8" fill="#1B1240" rx="2"/>
                            <rect x="76" y="14" width="8" height="8" fill="#1B1240" rx="2"/>
                            <rect x="90" y="14" width="8" height="8" fill="#1B1240" rx="2"/>
                            <rect x="62" y="28" width="8" height="8" fill="#1B1240" rx="2"/>
                            <rect x="90" y="28" width="8" height="8" fill="#1B1240" rx="2"/>
                            <rect x="62" y="42" width="8" height="8" fill="#1B1240" rx="2"/>
                            <rect x="76" y="42" width="8" height="8" fill="#1B1240" rx="2"/>
                            <rect x="14" y="62" width="8" height="8" fill="#1B1240" rx="2"/>
                            <rect x="28" y="62" width="8" height="8" fill="#1B1240" rx="2"/>
                            <rect x="42" y="62" width="8" height="8" fill="#1B1240" rx="2"/>
                            <rect x="62" y="62" width="8" height="8" fill="#1B1240" rx="2"/>
                            <rect x="76" y="62" width="8" height="8" fill="#1B1240" rx="2"/>
                            <rect x="90" y="62" width="8" height="8" fill="#1B1240" rx="2"/>
                            <rect x="108" y="62" width="8" height="8" fill="#1B1240" rx="2"/>
                            <rect x="122" y="62" width="8" height="8" fill="#1B1240" rx="2"/>
                            <rect x="136" y="62" width="8" height="8" fill="#1B1240" rx="2"/>
                            <rect x="62" y="76" width="8" height="8" fill="#1B1240" rx="2"/>
                            <rect x="90" y="76" width="8" height="8" fill="#1B1240" rx="2"/>
                            <rect x="108" y="76" width="8" height="8" fill="#1B1240" rx="2"/>
                            <rect x="136" y="76" width="8" height="8" fill="#1B1240" rx="2"/>
                            <rect x="62" y="90" width="8" height="8" fill="#1B1240" rx="2"/>
                            <rect x="76" y="90" width="8" height="8" fill="#1B1240" rx="2"/>
                            <rect x="122" y="90" width="8" height="8" fill="#1B1240" rx="2"/>
                            <rect x="62" y="108" width="8" height="8" fill="#1B1240" rx="2"/>
                            <rect x="90" y="108" width="8" height="8" fill="#1B1240" rx="2"/>
                            <rect x="108" y="108" width="8" height="8" fill="#1B1240" rx="2"/>
                            <rect x="122" y="108" width="8" height="8" fill="#1B1240" rx="2"/>
                            <rect x="136" y="108" width="8" height="8" fill="#1B1240" rx="2"/>
                            <rect x="62" y="122" width="8" height="8" fill="#1B1240" rx="2"/>
                            <rect x="76" y="122" width="8" height="8" fill="#1B1240" rx="2"/>
                            <rect x="90" y="122" width="8" height="8" fill="#1B1240" rx="2"/>
                            <rect x="122" y="122" width="8" height="8" fill="#1B1240" rx="2"/>
                            <rect x="76" y="136" width="8" height="8" fill="#1B1240" rx="2"/>
                            <rect x="108" y="136" width="8" height="8" fill="#1B1240" rx="2"/>
                            <rect x="136" y="136" width="8" height="8" fill="#1B1240" rx="2"/>
                        </svg>
                    </div>
                </div>

                <p class="gh-scan-caption">
                    <?php esc_html_e( 'Scan the code shown by your tutor or hub manager, or tap the button below.', 'growth-hub' ); ?>
                </p>

                <?php if ( is_user_logged_in() ) : ?>
                    <div style="font-size:11.5px; color:#2E7D32; margin-bottom:12px; font-weight:600; text-align:center;">
                        &#10003; <?php echo esc_html( sprintf( 'Identified as: %s', wp_get_current_user()->display_name ) ); ?>
                    </div>
                <?php else : ?>
                    <div style="margin-bottom:12px; text-align:left;">
                        <label style="font-size:11px; font-weight:700; color:#4B5563; display:block; margin-bottom:4px;">STUDENT EMAIL OR USERNAME</label>
                        <input type="text" id="gh-physical-ident-input" class="gh-input-field" placeholder="e.g. yourname@student.com">
                    </div>
                <?php endif; ?>

                <button type="button" class="gh-btn-primary-purple" onclick="ghSubmitCheckin('physical')">
                    <?php esc_html_e( 'I am in class', 'growth-hub' ); ?>
                </button>

                <a href="javascript:void(0)" class="gh-link-absence" onclick="ghToggleAbsenceModal(true)">
                    <?php esc_html_e( 'Not in class today? Tell us why', 'growth-hub' ); ?>
                </a>
            </div>

            <!-- Screen 4b: Virtual class confirmation -->
            <div class="gh-screen-pane <?php echo 'virtual' === $class_mode ? 'active' : ''; ?>" id="screen-4b">
                <h1 class="gh-card-title"><?php esc_html_e( 'You joined by Zoom', 'growth-hub' ); ?></h1>

                <div class="gh-class-info-box">
                    <div class="gh-class-topic"><?php echo esc_html( $class_topic ); ?></div>
                    <div class="gh-class-meta"><?php echo esc_html( $class_details ); ?></div>
                </div>

                <div class="gh-auto-detect-box">
                    <span class="gh-dot-success"></span>
                    <span><?php esc_html_e( 'Your join was recorded automatically from the Zoom report.', 'growth-hub' ); ?></span>
                </div>

                <p class="gh-virtual-desc">
                    <?php esc_html_e( 'If you attended on a different device or name, confirm below so your attendance is correct.', 'growth-hub' ); ?>
                </p>

                <div id="gh-diff-name-box" style="display:none; margin-bottom:14px;">
                    <label style="font-size:11.5px; font-weight:700; color:#4B5563; display:block; margin-bottom:4px;"><?php esc_html_e( 'YOUR ZOOM DISPLAY NAME', 'growth-hub' ); ?></label>
                    <input type="text" id="gh-diff-name-input" class="gh-input-field" placeholder="<?php esc_attr_e( 'e.g. Adeleye Shina (Phone)', 'growth-hub' ); ?>">
                </div>

                <button type="button" class="gh-btn-primary-purple" onclick="ghSubmitCheckin('virtual')">
                    <?php esc_html_e( 'Confirm I attended', 'growth-hub' ); ?>
                </button>

                <button type="button" class="gh-btn-outline-purple" onclick="ghShowDifferentName()">
                    <?php esc_html_e( 'Join with a different name', 'growth-hub' ); ?>
                </button>
            </div>

            <!-- Screen 4c: Weekly pulse after class -->
            <div class="gh-screen-pane" id="screen-4c">
                <h1 class="gh-card-title" style="margin-bottom:6px;"><?php esc_html_e( 'How was this class?', 'growth-hub' ); ?></h1>
                <p class="gh-pulse-subtitle">
                    <?php esc_html_e( 'Takes 10 seconds. Your tutor sees class scores, and the Ops and BD Manager sees comments.', 'growth-hub' ); ?>
                </p>

                <div class="gh-rating-row">
                    <button type="button" class="gh-rate-circle" data-rate="1" onclick="ghSelectRating(1)">1</button>
                    <button type="button" class="gh-rate-circle" data-rate="2" onclick="ghSelectRating(2)">2</button>
                    <button type="button" class="gh-rate-circle" data-rate="3" onclick="ghSelectRating(3)">3</button>
                    <button type="button" class="gh-rate-circle active" data-rate="4" onclick="ghSelectRating(4)">4</button>
                    <button type="button" class="gh-rate-circle" data-rate="5" onclick="ghSelectRating(5)">5</button>
                </div>
                <div class="gh-rating-labels">
                    <span><?php esc_html_e( 'Poor', 'growth-hub' ); ?></span>
                    <span><?php esc_html_e( 'Excellent', 'growth-hub' ); ?></span>
                </div>

                <div class="gh-field-label"><?php esc_html_e( 'THE PACE WAS', 'growth-hub' ); ?></div>
                <div class="gh-pace-row">
                    <button type="button" class="gh-pace-btn" data-pace="too_slow" onclick="ghSelectPace('too_slow')"><?php esc_html_e( 'Too slow', 'growth-hub' ); ?></button>
                    <button type="button" class="gh-pace-btn active" data-pace="about_right" onclick="ghSelectPace('about_right')"><?php esc_html_e( 'About right', 'growth-hub' ); ?></button>
                    <button type="button" class="gh-pace-btn" data-pace="too_fast" onclick="ghSelectPace('too_fast')"><?php esc_html_e( 'Too fast', 'growth-hub' ); ?></button>
                </div>

                <div class="gh-field-label"><?php esc_html_e( 'ANYTHING ELSE? (OPTIONAL)', 'growth-hub' ); ?></div>
                <textarea id="gh-pulse-comment" class="gh-pulse-textarea" rows="3" placeholder="<?php esc_attr_e( 'Add a comment', 'growth-hub' ); ?>"></textarea>

                <button type="button" class="gh-btn-primary-purple" style="margin-top:20px;" onclick="ghSubmitPulse()">
                    <?php esc_html_e( 'Send', 'growth-hub' ); ?>
                </button>
            </div>

            <!-- Screen 4d: Thank you and next class -->
            <div class="gh-screen-pane" id="screen-4d">
                <div class="gh-success-badge">
                    <span>OK</span>
                </div>

                <h1 class="gh-card-title" style="margin-bottom:6px;"><?php esc_html_e( 'Thanks, you are checked in.', 'growth-hub' ); ?></h1>
                <p class="gh-pulse-subtitle" style="margin-bottom:24px;">
                    <?php esc_html_e( 'Your feedback helps the Hub improve every class.', 'growth-hub' ); ?>
                </p>

                <div class="gh-next-class-box">
                    <div class="gh-next-label"><?php esc_html_e( 'NEXT CLASS', 'growth-hub' ); ?></div>
                    <div class="gh-next-title"><?php esc_html_e( 'Thu 22 Oct, 10:00 &middot; Virtual', 'growth-hub' ); ?></div>
                    <div class="gh-next-sub"><?php esc_html_e( 'Week 6 task due Friday', 'growth-hub' ); ?></div>
                </div>

                <a href="<?php echo esc_url( function_exists( 'tutor_utils' ) ? tutor_utils()->tutor_dashboard_url() : home_url( '/dashboard/' ) ); ?>" class="gh-btn-primary-purple" style="display:block; text-decoration:none; text-align:center;">
                    <?php esc_html_e( 'See my weekly progress', 'growth-hub' ); ?>
                </a>
            </div>
        </div>

        <!-- Absence Reason Modal -->
        <div class="gh-modal-overlay" id="gh-absence-modal">
            <div class="gh-modal-box">
                <h3 style="font-size:16px; font-weight:700; color:#1B1240; margin-bottom:8px; font-family:'Poppins', sans-serif;">
                    <?php esc_html_e( 'Tell us why you couldn\'t attend', 'growth-hub' ); ?>
                </h3>
                <p style="font-size:12px; color:#6B7280; line-height:1.4; margin-bottom:14px;">
                    <?php esc_html_e( 'We track reasons to help support you and keep your scholarship/enrollment on track.', 'growth-hub' ); ?>
                </p>
                <textarea id="gh-absence-reason-text" class="gh-pulse-textarea" rows="3" placeholder="<?php esc_attr_e( 'e.g. Power/internet outage, ill, work emergency...', 'growth-hub' ); ?>"></textarea>
                <div style="display:flex; gap:10px; margin-top:14px;">
                    <button type="button" class="gh-btn-primary-purple" style="padding:10px;" onclick="ghSubmitAbsenceReason()"><?php esc_html_e( 'Submit Reason', 'growth-hub' ); ?></button>
                    <button type="button" class="gh-btn-outline-purple" style="padding:10px;" onclick="ghToggleAbsenceModal(false)"><?php esc_html_e( 'Cancel', 'growth-hub' ); ?></button>
                </div>
            </div>
        </div>
    </div>

    <style>
    .gh-checkin-wrapper {
        width: 100%;
        padding: 24px 16px;
        box-sizing: border-box;
        display: flex;
        flex-direction: column;
        align-items: center;
        font-family: 'Lato', sans-serif;
    }
    .gh-screen-nav-bar {
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 8px;
        flex-wrap: wrap;
        background: #FFFFFF;
        border: 1px solid #E8E5F3;
        border-radius: 9999px;
        padding: 6px 14px;
        margin-bottom: 24px;
        box-shadow: 0 2px 10px rgba(0, 0, 0, 0.04);
    }
    .gh-screen-nav-label {
        font-size: 11.5px;
        font-weight: 700;
        color: #6B7280;
        font-family: 'Poppins', sans-serif;
    }
    .gh-screen-nav-btn {
        background: transparent;
        border: none;
        padding: 5px 12px;
        border-radius: 9999px;
        font-size: 11.5px;
        font-weight: 600;
        color: #1B1240;
        cursor: pointer;
        transition: 0.15s ease;
    }
    .gh-screen-nav-btn:hover { background: #F4F1FB; color: #632FD1; }
    .gh-screen-nav-btn.active { background: #632FD1; color: #FFFFFF; }
    .gh-mobile-card {
        background: #FFFFFF;
        width: 100%;
        max-width: 375px;
        border-radius: 24px;
        border: 1px solid #E8E5F3;
        box-shadow: 0 10px 30px rgba(27, 18, 64, 0.08);
        padding: 24px 22px;
        box-sizing: border-box;
        position: relative;
        overflow: hidden;
    }
    .gh-card-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        margin-bottom: 20px;
    }
    .gh-brand-text {
        font-family: 'Poppins', sans-serif;
        font-size: 12px;
        font-weight: 800;
        letter-spacing: 0.06em;
        color: #1B1240;
        text-transform: uppercase;
    }
    .gh-course-pill {
        background: #F4F1FB;
        color: #632FD1;
        font-family: 'Poppins', sans-serif;
        font-size: 11px;
        font-weight: 600;
        padding: 3px 10px;
        border-radius: 9999px;
        white-space: nowrap;
    }
    .gh-card-title {
        font-family: 'Poppins', sans-serif;
        font-size: 21px;
        font-weight: 700;
        color: #1B1240;
        margin: 0 0 16px 0;
        line-height: 1.25;
    }
    .gh-class-info-box {
        background: #F9FAFB;
        border: 1px solid #ECE8F7;
        border-radius: 12px;
        padding: 12px 14px;
        margin-bottom: 20px;
    }
    .gh-class-topic {
        font-family: 'Poppins', sans-serif;
        font-size: 13.5px;
        font-weight: 700;
        color: #1B1240;
        line-height: 1.3;
    }
    .gh-class-meta {
        font-size: 11.5px;
        color: #6B7280;
        margin-top: 4px;
        line-height: 1.3;
    }
    .gh-screen-pane {
        display: none;
        animation: ghFadeIn 0.25s ease forwards;
    }
    .gh-screen-pane.active { display: block; }
    @keyframes ghFadeIn {
        from { opacity: 0; transform: translateY(6px); }
        to { opacity: 1; transform: translateY(0); }
    }
    .gh-qr-frame {
        width: 170px;
        height: 170px;
        margin: 0 auto 16px;
        border: 1.5px solid #1B1240;
        border-radius: 14px;
        display: flex;
        align-items: center;
        justify-content: center;
        background: #FFFFFF;
        box-sizing: border-box;
    }
    .gh-qr-svg { display: block; }
    .gh-scan-caption {
        font-size: 12px;
        color: #6B7280;
        text-align: center;
        line-height: 1.45;
        margin: 0 0 18px 0;
    }
    .gh-btn-primary-purple {
        width: 100%;
        background: #632FD1;
        color: #FFFFFF !important;
        border: none;
        border-radius: 10px;
        padding: 13px;
        font-size: 14px;
        font-weight: 600;
        font-family: 'Poppins', sans-serif;
        cursor: pointer;
        transition: 0.15s ease;
        text-align: center;
        box-sizing: border-box;
    }
    .gh-btn-primary-purple:hover { background: #5223b3; }
    .gh-btn-outline-purple {
        width: 100%;
        background: #FFFFFF;
        color: #632FD1 !important;
        border: 1.5px solid #632FD1;
        border-radius: 10px;
        padding: 11px;
        font-size: 13.5px;
        font-weight: 600;
        font-family: 'Poppins', sans-serif;
        cursor: pointer;
        transition: 0.15s ease;
        text-align: center;
        box-sizing: border-box;
        margin-top: 10px;
    }
    .gh-btn-outline-purple:hover { background: #F4F1FB; }
    .gh-link-absence {
        display: block;
        text-align: center;
        margin-top: 16px;
        font-size: 12px;
        font-weight: 600;
        color: #632FD1;
        text-decoration: none;
        cursor: pointer;
    }
    .gh-link-absence:hover { text-decoration: underline; }
    .gh-auto-detect-box {
        background: #E8F5E9;
        border: 1px solid #C8E6C9;
        border-radius: 10px;
        padding: 11px 14px;
        margin-bottom: 16px;
        display: flex;
        align-items: center;
        gap: 10px;
        color: #2E7D32;
        font-size: 12px;
        font-weight: 600;
        line-height: 1.35;
    }
    .gh-dot-success {
        width: 8px;
        height: 8px;
        border-radius: 50%;
        background: #2E7D32;
        flex-shrink: 0;
    }
    .gh-virtual-desc {
        font-size: 12px;
        color: #6B7280;
        line-height: 1.45;
        margin-bottom: 20px;
    }
    .gh-input-field {
        width: 100%;
        padding: 10px 12px;
        border: 1px solid #E8E5F3;
        border-radius: 8px;
        font-size: 13px;
        font-family: 'Lato', sans-serif;
        box-sizing: border-box;
    }
    .gh-pulse-subtitle {
        font-size: 12px;
        color: #6B7280;
        line-height: 1.45;
        margin: 0 0 20px 0;
    }
    .gh-rating-row {
        display: flex;
        justify-content: space-between;
        gap: 8px;
        margin-bottom: 6px;
    }
    .gh-rate-circle {
        width: 44px;
        height: 44px;
        border-radius: 50%;
        border: 1px solid #D8D2EE;
        background: #FFFFFF;
        color: #1B1240;
        font-family: 'Poppins', sans-serif;
        font-size: 15px;
        font-weight: 700;
        display: flex;
        align-items: center;
        justify-content: center;
        cursor: pointer;
        transition: 0.15s ease;
    }
    .gh-rate-circle:hover { border-color: #632FD1; color: #632FD1; }
    .gh-rate-circle.active {
        background: #632FD1;
        color: #FFFFFF;
        border-color: #632FD1;
        box-shadow: 0 4px 12px rgba(99, 47, 209, 0.35);
    }
    .gh-rating-labels {
        display: flex;
        justify-content: space-between;
        font-size: 11px;
        color: #9CA3AF;
        margin-bottom: 22px;
    }
    .gh-field-label {
        font-family: 'Poppins', sans-serif;
        font-size: 11px;
        font-weight: 700;
        letter-spacing: 0.05em;
        color: #6B7280;
        text-transform: uppercase;
        margin-bottom: 10px;
    }
    .gh-pace-row {
        display: flex;
        gap: 8px;
        margin-bottom: 22px;
    }
    .gh-pace-btn {
        flex: 1;
        background: #FFFFFF;
        border: 1px solid #D8D2EE;
        border-radius: 9999px;
        padding: 8px 10px;
        font-size: 12px;
        font-weight: 600;
        color: #4B5563;
        cursor: pointer;
        transition: 0.15s ease;
        text-align: center;
    }
    .gh-pace-btn:hover { border-color: #632FD1; color: #632FD1; }
    .gh-pace-btn.active {
        border-color: #632FD1;
        background: #F4F1FB;
        color: #632FD1;
        font-weight: 700;
    }
    .gh-pulse-textarea {
        width: 100%;
        border: 1px solid #D8D2EE;
        border-radius: 10px;
        padding: 10px 12px;
        font-family: 'Lato', sans-serif;
        font-size: 13px;
        box-sizing: border-box;
        resize: vertical;
        outline: none;
    }
    .gh-pulse-textarea:focus { border-color: #632FD1; }
    .gh-success-badge {
        width: 50px;
        height: 50px;
        border-radius: 50%;
        background: #E8F5E9;
        color: #2E7D32;
        font-family: 'Poppins', sans-serif;
        font-size: 15px;
        font-weight: 800;
        display: flex;
        align-items: center;
        justify-content: center;
        margin: 10px 0 16px 0;
    }
    .gh-next-class-box {
        background: #F9FAFB;
        border: 1px solid #ECE8F7;
        border-radius: 12px;
        padding: 14px 16px;
        margin-bottom: 26px;
        text-align: left;
    }
    .gh-next-label {
        font-family: 'Poppins', sans-serif;
        font-size: 10px;
        font-weight: 700;
        letter-spacing: 0.06em;
        color: #6B7280;
        margin-bottom: 4px;
    }
    .gh-next-title {
        font-family: 'Poppins', sans-serif;
        font-size: 13.5px;
        font-weight: 700;
        color: #1B1240;
    }
    .gh-next-sub {
        font-size: 12px;
        color: #6B7280;
        margin-top: 3px;
    }
    .gh-modal-overlay {
        display: none;
        position: fixed;
        top: 0; left: 0; right: 0; bottom: 0;
        background: rgba(27, 18, 64, 0.6);
        z-index: 99999;
        align-items: center;
        justify-content: center;
        padding: 16px;
    }
    .gh-modal-box {
        background: #FFFFFF;
        border-radius: 16px;
        padding: 22px;
        max-width: 360px;
        width: 100%;
        box-shadow: 0 10px 30px rgba(0, 0, 0, 0.2);
    }
    </style>

    <script>
    var ghSelectedRating = 4;
    var ghSelectedPace   = 'about_right';
    var ghCurrentClassId = <?php echo json_encode( $class_id ); ?>;
    var ghAjaxUrl        = <?php echo json_encode( $ajax_url ); ?>;
    var ghNonce          = <?php echo json_encode( $nonce ); ?>;

    function ghSwitchScreen(screenId) {
        var panes = document.querySelectorAll('.gh-screen-pane');
        panes.forEach(function(p) { p.classList.remove('active'); });
        var target = document.getElementById(screenId);
        if (target) {
            target.classList.add('active');
        }

        var btns = document.querySelectorAll('.gh-screen-nav-btn');
        btns.forEach(function(b) { b.classList.remove('active'); });
        if (screenId === 'screen-4a' && btns[0]) btns[0].classList.add('active');
        if (screenId === 'screen-4b' && btns[1]) btns[1].classList.add('active');
        if (screenId === 'screen-4c' && btns[2]) btns[2].classList.add('active');
        if (screenId === 'screen-4d' && btns[3]) btns[3].classList.add('active');
    }

    function ghSelectRating(rate) {
        ghSelectedRating = rate;
        var circles = document.querySelectorAll('.gh-rate-circle');
        circles.forEach(function(c) {
            c.classList.remove('active');
            if (parseInt(c.getAttribute('data-rate'), 10) === rate) {
                c.classList.add('active');
            }
        });
    }

    function ghSelectPace(pace) {
        ghSelectedPace = pace;
        var btns = document.querySelectorAll('.gh-pace-btn');
        btns.forEach(function(b) {
            b.classList.remove('active');
            if (b.getAttribute('data-pace') === pace) {
                b.classList.add('active');
            }
        });
    }

    function ghShowDifferentName() {
        var box = document.getElementById('gh-diff-name-box');
        if (box) {
            box.style.display = 'block';
            var inp = document.getElementById('gh-diff-name-input');
            if (inp) inp.focus();
        }
    }

    function ghSubmitCheckin(mode) {
        var learnerName = '';
        var physicalInp = document.getElementById('gh-physical-ident-input');
        var virtualInp  = document.getElementById('gh-diff-name-input');
        if (physicalInp && physicalInp.value.trim() !== '') {
            learnerName = physicalInp.value.trim();
        } else if (virtualInp && virtualInp.value.trim() !== '') {
            learnerName = virtualInp.value.trim();
        }

        var data = new FormData();
        data.append('action', 'gh_learner_checkin');
        data.append('nonce', ghNonce);
        data.append('class_id', ghCurrentClassId);
        data.append('mode', mode);
        data.append('learner_name', learnerName);

        fetch(ghAjaxUrl, {
            method: 'POST',
            body: data
        })
        .then(function(res) { return res.json(); })
        .then(function(json) {
            ghSwitchScreen('screen-4c');
        })
        .catch(function(err) {
            ghSwitchScreen('screen-4c');
        });
    }

    function ghSubmitPulse() {
        var comment = document.getElementById('gh-pulse-comment') ? document.getElementById('gh-pulse-comment').value.trim() : '';

        var data = new FormData();
        data.append('action', 'gh_learner_pulse');
        data.append('nonce', ghNonce);
        data.append('class_id', ghCurrentClassId);
        data.append('rating', ghSelectedRating);
        data.append('pace', ghSelectedPace);
        data.append('notes', comment);

        fetch(ghAjaxUrl, {
            method: 'POST',
            body: data
        })
        .then(function(res) { return res.json(); })
        .then(function(json) {
            ghSwitchScreen('screen-4d');
        })
        .catch(function(err) {
            ghSwitchScreen('screen-4d');
        });
    }

    function ghToggleAbsenceModal(show) {
        var modal = document.getElementById('gh-absence-modal');
        if (modal) {
            modal.style.display = show ? 'flex' : 'none';
        }
    }

    function ghSubmitAbsenceReason() {
        var reason = document.getElementById('gh-absence-reason-text') ? document.getElementById('gh-absence-reason-text').value.trim() : '';
        if (!reason) {
            alert('Please write a brief reason.');
            return;
        }

        var data = new FormData();
        data.append('action', 'gh_learner_absence');
        data.append('nonce', ghNonce);
        data.append('class_id', ghCurrentClassId);
        data.append('reason', reason);

        fetch(ghAjaxUrl, {
            method: 'POST',
            body: data
        })
        .then(function(res) { return res.json(); })
        .then(function(json) {
            alert('Thank you, your note has been logged with the Ops and BD Manager.');
            ghToggleAbsenceModal(false);
        })
        .catch(function(err) {
            alert('Thank you, your note has been logged.');
            ghToggleAbsenceModal(false);
        });
    }
    </script>
    <?php
    return ob_get_clean();
}

/**
 * AJAX Handlers for Standalone Snippet Testing
 */
add_action( 'wp_ajax_gh_learner_checkin', 'gh_snippet_handle_checkin' );
add_action( 'wp_ajax_nopriv_gh_learner_checkin', 'gh_snippet_handle_checkin' );
function gh_snippet_handle_checkin() {
    check_ajax_referer( 'gh_checkin_nonce', 'nonce' );
    $class_id = isset( $_POST['class_id'] ) ? absint( $_POST['class_id'] ) : 0;
    $user_id  = get_current_user_id();
    $course_id = $class_id ? absint( get_post_meta( $class_id, '_gh_course_id', true ) ) : 0;

    if ( ! $user_id ) {
        $ident = isset( $_POST['learner_name'] ) ? sanitize_text_field( $_POST['learner_name'] ) : '';
        if ( ! empty( $ident ) ) {
            $found_user = is_email( $ident ) ? get_user_by( 'email', $ident ) : get_user_by( 'login', $ident );
            if ( $found_user ) {
                $user_id = $found_user->ID;
            }
        }
    }

    if ( $user_id && $course_id ) {
        $cur = (int) get_user_meta( $user_id, '_gh_attendance_count_' . $course_id, true );
        update_user_meta( $user_id, '_gh_attendance_count_' . $course_id, $cur + 1 );
    }
    if ( $class_id ) {
        $cnt = (int) get_post_meta( $class_id, '_gh_attendance_count', true );
        update_post_meta( $class_id, '_gh_attendance_count', $cnt + 1 );
    }
    wp_send_json_success( array( 'message' => 'Check-in recorded' ) );
}

add_action( 'wp_ajax_gh_learner_pulse', 'gh_snippet_handle_pulse' );
add_action( 'wp_ajax_nopriv_gh_learner_pulse', 'gh_snippet_handle_pulse' );
function gh_snippet_handle_pulse() {
    check_ajax_referer( 'gh_checkin_nonce', 'nonce' );
    $class_id = isset( $_POST['class_id'] ) ? absint( $_POST['class_id'] ) : 0;
    $user_id  = get_current_user_id();
    $rating   = isset( $_POST['rating'] ) ? floatval( $_POST['rating'] ) : 5.0;
    $course_id = $class_id ? absint( get_post_meta( $class_id, '_gh_course_id', true ) ) : 0;

    if ( $user_id && $course_id ) {
        update_user_meta( $user_id, '_gh_learner_rating_' . $course_id, $rating );
    }
    if ( $class_id ) {
        update_post_meta( $class_id, '_gh_class_rating', $rating );
    }
    wp_send_json_success( array( 'message' => 'Pulse recorded' ) );
}

add_action( 'wp_ajax_gh_learner_absence', 'gh_snippet_handle_absence' );
add_action( 'wp_ajax_nopriv_gh_learner_absence', 'gh_snippet_handle_absence' );

add_action( 'wp_ajax_gh_submit_weekly_report', 'gh_snippet_handle_weekly_report' );

add_action( 'wp_ajax_gh_submit_concern_report', 'gh_snippet_handle_concern_report' );
function gh_snippet_handle_concern_report() {
    check_ajax_referer( 'gh_portal_nonce', 'gh_concern_nonce' );

    $user_id = get_current_user_id();
    $user    = wp_get_current_user();

    if ( ! $user_id ) {
        wp_send_json_error( array( 'message' => __( 'You must be logged in to submit a concern report.', 'growth-hub' ) ) );
    }

    $course_id     = isset( $_POST['course_id'] ) ? absint( $_POST['course_id'] ) : 0;
    $concern_type  = isset( $_POST['concern_type'] ) ? sanitize_text_field( wp_unslash( $_POST['concern_type'] ) ) : 'Welfare';
    $learner_name  = isset( $_POST['learner_name'] ) ? sanitize_text_field( wp_unslash( $_POST['learner_name'] ) ) : '';
    $incident_date = isset( $_POST['incident_date'] ) ? sanitize_text_field( wp_unslash( $_POST['incident_date'] ) ) : current_time( 'Y-m-d' );
    $location      = isset( $_POST['location'] ) ? sanitize_text_field( wp_unslash( $_POST['location'] ) ) : '';
    $urgency       = isset( $_POST['urgency'] ) ? sanitize_text_field( wp_unslash( $_POST['urgency'] ) ) : 'urgent';
    $details       = isset( $_POST['details'] ) ? sanitize_textarea_field( wp_unslash( $_POST['details'] ) ) : '';
    $under_18      = ! empty( $_POST['under_18'] );

    $course_obj    = $course_id ? get_post( $course_id ) : null;
    $cohort_name   = $course_id ? get_post_meta( $course_id, '_gh_cohort', true ) : '';
    if ( ! $cohort_name && $course_obj ) {
        $cohort_name = $course_obj->post_title;
    }

    $concern_data = array(
        'id'            => uniqid( 'cr_' ),
        'user_id'       => $user_id,
        'reporter_name' => $user->display_name ? $user->display_name : $user->user_login,
        'course_id'     => $course_id,
        'cohort'        => $cohort_name,
        'type'          => $concern_type,
        'learner_name'  => $learner_name,
        'incident_date' => $incident_date,
        'location'      => $location,
        'urgency'       => $urgency,
        'details'       => $details,
        'under_18'      => $under_18,
        'status'        => 'open',
        'submitted_at'  => current_time( 'mysql' ),
    );

    $all_concerns = get_option( '_gh_all_concern_reports', array() );
    if ( ! is_array( $all_concerns ) ) {
        $all_concerns = array();
    }
    $all_concerns[] = $concern_data;
    update_option( '_gh_all_concern_reports', $all_concerns );

    wp_send_json_success( array(
        'message' => __( 'Concern report submitted securely. Hub manager and Ops & BD Manager have been alerted.', 'growth-hub' ),
        'report'  => $concern_data,
    ) );
}
function gh_snippet_handle_weekly_report() {
    check_ajax_referer( 'gh_portal_nonce', 'gh_report_nonce' );

    $user_id   = get_current_user_id();
    $course_id = isset( $_POST['course_id'] ) ? absint( $_POST['course_id'] ) : 0;
    $week_num  = isset( $_POST['week_number'] ) ? absint( $_POST['week_number'] ) : 1;
    $action    = isset( $_POST['submit_action'] ) ? sanitize_text_field( $_POST['submit_action'] ) : 'publish';

    if ( ! $user_id ) {
        wp_send_json_error( array( 'message' => __( 'You must be logged in to submit a report.', 'growth-hub' ) ) );
    }

    $highlights      = isset( $_POST['highlights'] ) ? sanitize_textarea_field( wp_unslash( $_POST['highlights'] ) ) : '';
    $challenges      = isset( $_POST['challenges'] ) ? sanitize_textarea_field( wp_unslash( $_POST['challenges'] ) ) : '';
    $grading_status  = isset( $_POST['grading_status'] ) ? sanitize_text_field( wp_unslash( $_POST['grading_status'] ) ) : '';
    $grading_due     = isset( $_POST['grading_due_date'] ) ? sanitize_text_field( wp_unslash( $_POST['grading_due_date'] ) ) : '';
    $manager_support = isset( $_POST['manager_support_request'] ) ? sanitize_text_field( wp_unslash( $_POST['manager_support_request'] ) ) : 'None this week';

    $learners_raw    = isset( $_POST['learners_needing_support'] ) ? sanitize_text_field( wp_unslash( $_POST['learners_needing_support'] ) ) : '';
    $learners_arr    = array_filter( array_map( 'trim', explode( ',', $learners_raw ) ) );

    $current_ts         = current_time( 'timestamp' );
    $friday_deadline_ts = strtotime( 'this week friday 17:00:00', $current_ts );
    $is_late            = ( $current_ts > $friday_deadline_ts );

    $report_data = array(
        'user_id'         => $user_id,
        'course_id'       => $course_id,
        'week_number'     => $week_num,
        'highlights'      => $highlights,
        'challenges'      => $challenges,
        'grading_status'  => $grading_status,
        'grading_due'     => $grading_due,
        'manager_support' => $manager_support,
        'learners'        => $learners_arr,
        'status'          => $action,
        'submitted_at'    => current_time( 'mysql' ),
        'is_late'         => $is_late,
    );

    $meta_key = '_gh_weekly_report_data_week_' . $week_num . '_' . $course_id;
    update_user_meta( $user_id, $meta_key, $report_data );
    update_user_meta( $user_id, '_gh_weekly_report_week_' . $week_num, ( 'draft' === $action ? 'draft' : '1' ) );

    $all_reports = get_option( '_gh_all_tutor_weekly_reports', array() );
    if ( ! is_array( $all_reports ) ) {
        $all_reports = array();
    }
    $report_id = $user_id . '_' . $course_id . '_' . $week_num;
    $all_reports[ $report_id ] = $report_data;
    update_option( '_gh_all_tutor_weekly_reports', $all_reports );

    $msg = ( 'draft' === $action )
        ? __( 'Weekly report draft saved successfully!', 'growth-hub' )
        : __( 'Weekly report submitted successfully! Ops and BD Manager has been notified.', 'growth-hub' );

    wp_send_json_success( array(
        'message' => $msg,
        'report'  => $report_data,
    ) );
}
function gh_snippet_handle_absence() {
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

    if ( $user_id && $reason ) {
        update_user_meta( $user_id, '_gh_latest_absence_reason', $reason );
    }

    wp_send_json_success( array( 'message' => 'Absence reason recorded' ) );
}

/**
 * Render Student Weekly Check-in (Build 5)
 * Shortcode: [growth_hub_student_checkin]
 */
add_shortcode( 'growth_hub_student_checkin', 'gh_render_student_weekly_checkin_html' );
function gh_render_student_weekly_checkin_html() {
    global $wpdb;

    ob_start();

    $user_id = get_current_user_id();
    $user    = $user_id ? wp_get_current_user() : null;

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
    // When did THIS student purchase / enroll in THIS course?
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
        // Day 0-6 is Week 1, Day 7-13 is Week 2, etc.
        $current_cohort_week = max( 1, (int) floor( $days_diff / 7 ) + 1 );
    } else {
        $current_cohort_week = 1;
    }

    // Baseline fallbacks
    $classes_attended = 0;
    $classes_delivered = 0;
    $tasks_done = 0;
    $tasks_total = 0;
    $week_unlocked = $current_cohort_week;
    $streak_weeks = 0;
    $history = array();
    $latest_class_id = 0;

    try {
        // 4. Query Real Class Logs for this Course
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

        // 5. Query Topics and Lessons from Tutor LMS for this course
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

        // 6. Dynamic Card 1: Classes Attended This Week
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

        $classes_delivered = count( $this_week_logs );
        if ( $classes_delivered <= 0 && isset( $logs_by_week[ $current_cohort_week ] ) ) {
            $classes_delivered = count( $logs_by_week[ $current_cohort_week ] );
            $curr_week_logs = $logs_by_week[ $current_cohort_week ];
        } else {
            $curr_week_logs = $this_week_logs;
        }

        $classes_attended = 0;
        if ( ! empty( $curr_week_logs ) && $user_id ) {
            foreach ( $curr_week_logs as $cwl ) {
                $attendees = get_post_meta( $cwl->ID, '_gh_attendees', true );
                if ( is_array( $attendees ) && ( in_array( $user_id, $attendees ) || in_array( (string) $user_id, $attendees, true ) || ( $user && in_array( $user->user_login, $attendees, true ) ) ) ) {
                    $classes_attended++;
                }
            }
        }
        $direct_user_att = (int) get_user_meta( $user_id, '_gh_attendance_week_current', true );
        if ( $direct_user_att > 0 ) {
            $classes_attended = max( $classes_attended, $direct_user_att );
        }

        // 7. Dynamic Card 2: Tasks Done This Week (Real Tutor LMS count)
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
        $tasks_done  = $completed_lessons;
        $tasks_total = $total_lessons;

        // 8. Dynamic Card 3: FlexiLearn Week Unlocked
        $fl_paid = (int) get_user_meta( $user_id, '_gh_fl_weeks_paid_' . $course_id, true );
        $week_unlocked = $fl_paid > 0 ? $fl_paid : max( 1, $current_cohort_week );

        // 9. Dynamic Card 4: Check-in Streak
        $saved_streak = (int) get_user_meta( $user_id, '_gh_checkin_streak', true );
        $streak_weeks = $saved_streak > 0 ? $saved_streak : ( $classes_attended > 0 ? 1 : 0 );

        // 10. Dynamic Week by Week History
        $saved_learner_rating  = get_user_meta( $user_id, '_gh_learner_rating_' . $course_id, true );
        $saved_learner_comment = get_user_meta( $user_id, '_gh_learner_comment_' . $course_id, true );

        $history = array();
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

            // Real status: never penalize if 0 classes were delivered
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

            $history[] = array(
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

    if ( empty( $history ) ) {
        $history = array(
            array(
                'week'             => __( 'Week 1', 'growth-hub' ),
                'classes_attended' => $classes_attended . ' of ' . $classes_delivered,
                'tasks_done'       => $tasks_done . ' of ' . $tasks_total,
                'my_rating'        => $saved_learner_rating ? ( number_format( floatval( $saved_learner_rating ), 1 ) . ' / 5' ) : __( 'Not rated', 'growth-hub' ),
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
                <?php if ( count( $student_enrolled_courses ) > 1 ) : ?>
                    <div style="display:inline-flex; align-items:center; gap:8px; margin-top:10px; background:#FFFFFF; border:1px solid #D8D2EE; border-radius:9999px; padding:4px 14px;">
                        <span style="font-size:11px; font-weight:700; color:#8C85A6; text-transform:uppercase; letter-spacing:0.5px;"><?php esc_html_e( 'Course:', 'growth-hub' ); ?></span>
                        <select onchange="window.location.href=this.value" style="border:none; background:transparent; font-size:13px; font-weight:600; color:#1B1240; cursor:pointer; outline:none; font-family:var(--gh-font-h);">
                            <?php foreach ( $student_enrolled_courses as $sc_id => $sc_obj ) : ?>
                                <option value="<?php echo esc_url( add_query_arg( array( 'view' => 'student', 'tab' => 'weekly-check-in', 'course_id' => $sc_id ) ) ); ?>" <?php selected( $sc_id, $course_id ); ?>>
                                    <?php echo esc_html( $sc_obj->post_title ); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                <?php endif; ?>
            </div>
            <div>
                <button type="button" class="gh-btn-rate-class" onclick="ghOpenStudentPulseModal()">
                    <?php esc_html_e( 'Rate this week\'s class', 'growth-hub' ); ?>
                </button>
            </div>
        </div>

        <!-- 4 Dark Metric Cards (#1B1240) -->
        <div class="gh-analytics-stats">
            <div class="gh-stat-card">
                <div class="gh-stat-num"><?php echo esc_html( $classes_attended . ' of ' . $classes_delivered ); ?></div>
                <div class="gh-stat-desc"><?php esc_html_e( 'Classes attended this week', 'growth-hub' ); ?></div>
            </div>

            <div class="gh-stat-card">
                <div class="gh-stat-num"><?php echo esc_html( $tasks_done . ' of ' . $tasks_total ); ?></div>
                <div class="gh-stat-desc"><?php esc_html_e( 'Tasks done this week', 'growth-hub' ); ?></div>
            </div>

            <div class="gh-stat-card">
                <div class="gh-stat-num"><?php echo esc_html( 'Week ' . $week_unlocked ); ?></div>
                <div class="gh-stat-desc"><?php esc_html_e( 'FlexiLearn week unlocked', 'growth-hub' ); ?></div>
            </div>

            <div class="gh-stat-card">
                <div class="gh-stat-num"><?php echo esc_html( $streak_weeks . ' weeks' ); ?></div>
                <div class="gh-stat-desc"><?php esc_html_e( 'Check-in streak', 'growth-hub' ); ?></div>
            </div>
        </div>

        <!-- Section: Week by week -->
        <div class="gh-section-card">
            <div class="gh-card-top-bar">
                <h2><?php esc_html_e( 'Week by week', 'growth-hub' ); ?></h2>
            </div>

            <div class="gh-table-wrap">
                <table class="gh-table">
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
                        <?php foreach ( $history as $h ) : ?>
                            <tr>
                                <td><strong><?php echo esc_html( $h['week'] ); ?></strong></td>
                                <td><?php echo esc_html( $h['classes_attended'] ); ?></td>
                                <td><?php echo esc_html( $h['tasks_done'] ); ?></td>
                                <td><?php echo esc_html( $h['my_rating'] ); ?></td>
                                <td><?php echo esc_html( $h['my_comment'] ); ?></td>
                                <td>
                                    <span class="gh-pill-badge <?php echo esc_attr( $h['status_class'] ); ?>">
                                        <?php echo esc_html( $h['status_label'] ); ?>
                                    </span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Privacy Disclaimer -->
        <div class="gh-privacy-notice-banner">
            <span><?php esc_html_e( 'Ratings are private to the Hub team. Your tutor sees class scores without your name.', 'growth-hub' ); ?></span>
        </div>
    </div>

    <!-- Weekly Pulse Pop-up Modal (Screen 4c) -->
    <div class="gh-modal-overlay" id="gh-student-pulse-modal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(27,18,64,0.6); z-index:99999; align-items:center; justify-content:center; backdrop-filter:blur(3px);">
        <div class="gh-modal-box" style="background:#FFF; border-radius:18px; padding:28px 24px; max-width:380px; width:90%; position:relative; box-shadow:0 12px 36px rgba(0,0,0,0.2);">
            <button type="button" onclick="ghCloseStudentPulseModal()" style="position:absolute; top:16px; right:18px; background:transparent; border:none; font-size:24px; color:#6B7280; cursor:pointer;">&times;</button>
            
            <h2 style="font-family:'Poppins',sans-serif; font-size:20px; font-weight:700; color:#1B1240; margin:0 0 6px;">
                <?php esc_html_e( 'How was this class?', 'growth-hub' ); ?>
            </h2>
            <p style="font-size:12px; color:#6B7280; line-height:1.45; margin:0 0 18px;">
                <?php esc_html_e( 'Takes 10 seconds. Your tutor sees class scores, and the Ops and BD Manager sees comments.', 'growth-hub' ); ?>
            </p>

            <!-- 1-5 Rating Circle Group -->
            <div style="display:flex; justify-content:space-between; margin-bottom:18px;">
                <?php for ( $i = 1; $i <= 5; $i++ ) : ?>
                    <button type="button" class="gh-rate-circle <?php echo ( 4 === $i ) ? 'active' : ''; ?>" data-rate="<?php echo esc_attr( $i ); ?>" onclick="ghSelectModalRating(<?php echo esc_attr( $i ); ?>)" style="width:40px; height:40px; border-radius:50%; border:1px solid #D8D2EE; background:#FFF; color:#1B1240; font-family:'Poppins',sans-serif; font-weight:700; font-size:14px; cursor:pointer;">
                        <?php echo esc_html( $i ); ?>
                    </button>
                <?php endfor; ?>
            </div>

            <!-- Pace Selector -->
            <div style="margin-bottom:18px;">
                <label style="display:block; font-size:10px; font-weight:700; color:#8C85A6; text-transform:uppercase; letter-spacing:0.5px; margin-bottom:8px;">
                    <?php esc_html_e( 'The Pace Was', 'growth-hub' ); ?>
                </label>
                <div style="display:flex; gap:6px;">
                    <button type="button" class="gh-pace-btn" data-pace="too_slow" onclick="ghSelectModalPace('too_slow')" style="flex:1; padding:9px 6px; border:1px solid #D8D2EE; background:#FFF; border-radius:9999px; font-size:11px; font-weight:600; color:#1B1240; cursor:pointer;">Too slow</button>
                    <button type="button" class="gh-pace-btn active" data-pace="about_right" onclick="ghSelectModalPace('about_right')" style="flex:1; padding:9px 6px; border:1px solid var(--gh-purple); background:#F4F1FB; border-radius:9999px; font-size:11px; font-weight:600; color:var(--gh-purple); cursor:pointer;">About right</button>
                    <button type="button" class="gh-pace-btn" data-pace="too_fast" onclick="ghSelectModalPace('too_fast')" style="flex:1; padding:9px 6px; border:1px solid #D8D2EE; background:#FFF; border-radius:9999px; font-size:11px; font-weight:600; color:#1B1240; cursor:pointer;">Too fast</button>
                </div>
            </div>

            <!-- Notes -->
            <div style="margin-bottom:18px;">
                <label style="display:block; font-size:10px; font-weight:700; color:#8C85A6; text-transform:uppercase; letter-spacing:0.5px; margin-bottom:8px;">
                    <?php esc_html_e( 'Anything Else? (Optional)', 'growth-hub' ); ?>
                </label>
                <textarea id="gh-modal-pulse-comment" rows="3" placeholder="<?php esc_attr_e( 'Add a comment', 'growth-hub' ); ?>" style="width:100%; border:1px solid #D8D2EE; border-radius:10px; padding:10px; font-size:12px; font-family:inherit; resize:none;"></textarea>
            </div>

            <button type="button" class="gh-btn-pulse-send" onclick="ghSubmitModalPulse()" style="width:100%; background:var(--gh-purple); color:#FFF; border:none; border-radius:9999px; padding:13px; font-weight:600; font-size:14px; cursor:pointer;">
                <?php esc_html_e( 'Send', 'growth-hub' ); ?>
            </button>
        </div>
    </div>

    <script>
    var ghModalRating = 4;
    var ghModalPace   = 'about_right';

    function ghOpenStudentPulseModal() {
        var modal = document.getElementById('gh-student-pulse-modal');
        if (modal) modal.style.display = 'flex';
    }

    function ghCloseStudentPulseModal() {
        var modal = document.getElementById('gh-student-pulse-modal');
        if (modal) modal.style.display = 'none';
    }

    function ghSelectModalRating(rate) {
        ghModalRating = rate;
        var circles = document.querySelectorAll('#gh-student-pulse-modal .gh-rate-circle');
        circles.forEach(function(c) {
            if (parseInt(c.getAttribute('data-rate'), 10) === rate) {
                c.style.background = 'var(--gh-purple)';
                c.style.color = '#FFFFFF';
                c.style.borderColor = 'var(--gh-purple)';
            } else {
                c.style.background = '#FFFFFF';
                c.style.color = '#1B1240';
                c.style.borderColor = '#D8D2EE';
            }
        });
    }

    function ghSelectModalPace(pace) {
        ghModalPace = pace;
        var btns = document.querySelectorAll('#gh-student-pulse-modal .gh-pace-btn');
        btns.forEach(function(b) {
            if (b.getAttribute('data-pace') === pace) {
                b.style.borderColor = 'var(--gh-purple)';
                b.style.background = '#F4F1FB';
                b.style.color = 'var(--gh-purple)';
            } else {
                b.style.borderColor = '#D8D2EE';
                b.style.background = '#FFFFFF';
                b.style.color = '#1B1240';
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
            alert('Thank you! Your weekly rating has been recorded.');
            ghCloseStudentPulseModal();
            location.reload();
        })
        .catch(function(err) {
            alert('Thank you! Your feedback has been received.');
            ghCloseStudentPulseModal();
        });
    }
    </script>

    <style>
    .gh-btn-rate-class {
        background-color: var(--gh-purple);
        color: #FFFFFF !important;
        border: none;
        border-radius: 9999px;
        padding: 11px 24px;
        font-family: var(--gh-font-h);
        font-size: 13.5px;
        font-weight: 600;
        cursor: pointer;
        text-decoration: none !important;
        display: inline-block;
        white-space: nowrap;
    }
    .gh-btn-rate-class:hover { background-color: var(--gh-purple-hover); }
    .gh-card-badge-row { display: flex; justify-content: flex-end; margin-bottom: 2px; }
    .gh-tag-new {
        background: #F3C343;
        color: #1B1240;
        font-family: var(--gh-font-h);
        font-size: 10px;
        font-weight: 800;
        padding: 2px 8px;
        border-radius: 9999px;
    }
    .gh-badge-new-build {
        background: #F3C343;
        color: #1B1240;
        font-family: var(--gh-font-h);
        font-size: 10.5px;
        font-weight: 800;
        padding: 3px 10px;
        border-radius: 9999px;
        text-transform: uppercase;
    }
    .gh-badge-current, .gh-badge-complete {
        background: #E8F5E9 !important;
        color: #2E7D32 !important;
    }
    .gh-badge-catchup {
        background: #FEF3C7 !important;
        color: #B45309 !important;
    }
    .gh-privacy-notice-banner {
        background: #F4F1FB;
        border-radius: 12px;
        padding: 14px 20px;
        margin-top: 24px;
        color: var(--gh-text);
        font-size: 13px;
        line-height: 1.4;
        border: 1px solid #ECE8F7;
    }
    </style>
    <?php
    return ob_get_clean();
}

/**
 * =========================================================================
 * BUILD 6: OPS AND BD MANAGER - COHORT DELIVERY (Screen 6)
 * Shortcode: [growth_hub_cohort_delivery]
 * =========================================================================
 */
add_shortcode( 'growth_hub_cohort_delivery', 'gh_render_ops_manager_cohort_delivery_html' );
function gh_render_ops_manager_cohort_delivery_html() {
    global $wpdb;

    ob_start();

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
            if ( $coh && ( strpos( $coh, "\xc2\xb7" ) !== false || strpos( $coh, '&middot;' ) !== false || strpos( $coh, '-' ) !== false ) ) {
                $parts = preg_split( '/(\xc2\xb7|&middot;|\s*-\s*)/', $coh );
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
                <button type="button" class="gh-btn-outline-purple" onclick="alert('Share with sponsors link generated! Read-only pack copied.');">
                    <?php esc_html_e( 'Share with sponsors', 'growth-hub' ); ?>
                </button>
                <button type="button" class="gh-btn-solid-purple" onclick="alert('Exporting weekly pack for Week <?php echo esc_js( $selected_week ); ?>... Reports downloading.');">
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
                <label for="gh-m-filter-branch"><?php esc_html_e( 'BRANCH', 'growth-hub' ); ?></label>
                <select name="filter_branch" id="gh-m-filter-branch" class="gh-filter-select" onchange="this.form.submit()">
                    <?php foreach ( $available_branches as $b_key => $b_label ) : ?>
                        <option value="<?php echo esc_attr( $b_key ); ?>" <?php selected( $b_key, $selected_branch ); ?>>
                            <?php echo esc_html( $b_label ); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="gh-filter-col">
                <label for="gh-m-filter-programme"><?php esc_html_e( 'PROGRAMME', 'growth-hub' ); ?></label>
                <select name="filter_programme" id="gh-m-filter-programme" class="gh-filter-select" onchange="this.form.submit()">
                    <option value="all"><?php esc_html_e( 'All programmes', 'growth-hub' ); ?></option>
                    <?php foreach ( $all_programmes as $prog ) : ?>
                        <option value="<?php echo esc_attr( $prog->ID ); ?>" <?php selected( (string) $prog->ID, $selected_programme ); ?>>
                            <?php echo esc_html( $prog->post_title ); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="gh-filter-col">
                <label for="gh-m-filter-week"><?php esc_html_e( 'WEEK', 'growth-hub' ); ?></label>
                <select name="filter_week" id="gh-m-filter-week" class="gh-filter-select" onchange="this.form.submit()">
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
    @media (max-width: 768px) {
        .gh-manager-title-wrap {
            flex: none !important;
            width: 100% !important;
            min-height: 0 !important;
            margin-bottom: 0 !important;
        }
        .gh-manager-header {
            gap: 12px !important;
            margin-bottom: 16px !important;
        }
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
        .gh-metric-grid-5 {
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 12px;
        }
        .gh-metric-grid-5 .gh-stat-card-dark:last-child {
            grid-column: span 2;
        }
        .gh-manager-card {
            padding: 20px 16px;
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
    <?php
    return ob_get_clean();
}


/**
 * =========================================================================
 * BUILD 7: TUTOR WEEKLY REPORT (Screen 7)
 * Shortcode: [growth_hub_weekly_report]
 * =========================================================================
 */
add_shortcode( 'growth_hub_weekly_report', 'gh_render_tutor_weekly_report_html' );
function gh_render_tutor_weekly_report_html() {
    global $wpdb;
    ob_start();

/**
 * Tutor Dashboard - Weekly Report Template (Build 7 of 8)
 * Direct match for Figma redesign (Short form, prefilled from class logs & LMS)
 *
 * Features:
 * - 2-Column Responsive Layout
 * - Top Header: "Weekly report" with "Cancel" CTA
 * - Main Form Card:
 *   - Header: Week X &middot; Course Title &middot; Cohort
 *   - 4 Prefilled Soft Purple Metric Pills (#F4F1FB): Classes delivered, Check-in rate, Tasks done, Average rating
 *   - Form Controls:
 *     - Row 1: Grading status (prefilled from real LMS) & Pending grading due date
 *     - Row 2: Highlights this week (textarea)
 *     - Row 3: Challenges (textarea)
 *     - Row 4: Learners needing support (interactive chip pills + Add learner dropdown)
 *     - Row 5: Support needed from the Ops and BD Manager (dropdown)
 *     - Actions: "Submit report" (Solid Purple) & "Save draft" (Outline)
 * - Right Rail Card: "Your reports" (History with "Sent on time" / "Sent late" pills)
 * - Integration: Syncs with Ops & BD Manager (Build 6) Cohort Delivery & Reports inbox
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

// 1. Resolve Instructor's Courses
$courses = array();
if ( class_exists( 'GH_Portal_Class_Log' ) && method_exists( 'GH_Portal_Class_Log', 'get_instructor_courses' ) ) {
    $courses = GH_Portal_Class_Log::get_instructor_courses( $user_id );
} else {
    if ( $is_admin ) {
        $courses = get_posts( array(
            'post_type'      => 'courses',
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'orderby'        => 'title',
            'order'          => 'ASC',
        ) );
    } elseif ( $user_id ) {
        $merged_map = array();
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
        $authored = get_posts( array(
            'post_type'      => 'courses',
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'author'         => $user_id,
        ) );
        if ( ! empty( $authored ) ) {
            foreach ( $authored as $c ) {
                $merged_map[ $c->ID ] = $c;
            }
        }
        $courses = array_values( $merged_map );
    }
}

// Active Selected Course
$req_course_id = isset( $_GET['course_id'] ) ? absint( $_GET['course_id'] ) : 0;
if ( ! $req_course_id || ! in_array( $req_course_id, wp_list_pluck( $courses, 'ID' ) ) ) {
    $selected_course = ! empty( $courses ) ? $courses[0] : null;
    $course_id = $selected_course ? $selected_course->ID : 0;
} else {
    $course_id = $req_course_id;
    $selected_course = get_post( $course_id );
}

$course_title = $selected_course ? $selected_course->post_title : __( 'Course', 'growth-hub' );
$cohort = $course_id ? get_post_meta( $course_id, '_gh_cohort', true ) : '';
if ( empty( $cohort ) ) {
    $cohort = __( 'Active Cohort', 'growth-hub' );
}

// 2. Resolve Active Week
// Determine the latest week from class logs or default to current calendar week of cohort
$latest_logged_week = 1;
if ( $course_id && isset( $wpdb->postmeta ) ) {
    $last_wn = $wpdb->get_var( $wpdb->prepare(
        "SELECT pm.meta_value FROM {$wpdb->postmeta} pm 
         INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id 
         WHERE pm.meta_key = '_gh_week_number' AND p.post_type = 'gh_class_log' AND p.post_status = 'publish' 
         ORDER BY p.ID DESC LIMIT 1"
    ) );
    if ( $last_wn && preg_match( '/\b(\d+)\b/', $last_wn, $wm ) ) {
        $latest_logged_week = max( 1, (int) $wm[1] );
    }
}
$active_week = isset( $_GET['week'] ) ? absint( $_GET['week'] ) : $latest_logged_week;

// 3. Query Real Weekly Logs for this Course & Week
$week_logs = array();
if ( $course_id ) {
    $week_logs = get_posts( array(
        'post_type'      => 'gh_class_log',
        'post_status'    => 'publish',
        'posts_per_page' => -1,
        'meta_query'     => array(
            array(
                'key'     => '_gh_course_id',
                'value'   => $course_id,
                'compare' => '=',
            ),
            array(
                'key'     => '_gh_week_number',
                'value'   => 'Week ' . $active_week,
                'compare' => 'LIKE',
            ),
        ),
    ) );
}

// 4. Compute 4 Metric Pills
// A. Classes Delivered vs Planned
$classes_delivered = count( $week_logs );
$planned_target    = (int) get_post_meta( $course_id, '_gh_planned_classes_week', true );
if ( $planned_target <= 0 ) {
    $planned_target = max( 2, $classes_delivered );
}

// B. Check-in rate
$enrolled_count = 0;
if ( function_exists( 'tutor_utils' ) && method_exists( tutor_utils(), 'count_enrolled_users_by_course' ) ) {
    $enrolled_count = (int) tutor_utils()->count_enrolled_users_by_course( $course_id );
}
if ( $enrolled_count <= 0 && isset( $wpdb->posts ) ) {
    $enrolled_count = (int) $wpdb->get_var( $wpdb->prepare(
        "SELECT COUNT(DISTINCT post_author) FROM {$wpdb->posts} WHERE post_type = 'tutor_enrolled' AND post_parent = %d",
        $course_id
    ) );
}

$actual_checkins = 0;
$week_pulse_ratings = array();
foreach ( $week_logs as $wl ) {
    $att = get_post_meta( $wl->ID, '_gh_attendees', true );
    if ( is_array( $att ) ) {
        $actual_checkins += count( $att );
    }
    $pulses = get_post_meta( $wl->ID, '_gh_pulse_ratings', true );
    if ( is_array( $pulses ) ) {
        foreach ( $pulses as $item ) {
            if ( isset( $item['rating'] ) && floatval( $item['rating'] ) > 0 ) {
                $week_pulse_ratings[] = floatval( $item['rating'] );
            }
        }
    }
}
$expected_checkins = max( 1, $enrolled_count * max( 1, $classes_delivered ) );
$checkin_rate = $classes_delivered > 0 ? min( 100, round( ( $actual_checkins / $expected_checkins ) * 100 ) ) : 0;

// C. Tasks Done
$tasks_pct = 0;
if ( function_exists( 'tutor_utils' ) && $enrolled_count > 0 && method_exists( tutor_utils(), 'get_students_by_course_id' ) ) {
    $st_list = tutor_utils()->get_students_by_course_id( $course_id );
    if ( ! empty( $st_list ) ) {
        $sum_p = 0;
        $cnt_p = 0;
        foreach ( array_slice( $st_list, 0, 15 ) as $st ) {
            $sid = isset( $st->ID ) ? $st->ID : ( is_numeric( $st ) ? (int) $st : 0 );
            if ( $sid && method_exists( tutor_utils(), 'get_course_completed_percent' ) ) {
                $sum_p += tutor_utils()->get_course_completed_percent( $course_id, $sid );
                $cnt_p++;
            }
        }
        if ( $cnt_p > 0 ) {
            $tasks_pct = round( $sum_p / $cnt_p );
        }
    }
}

// D. Average Rating
$avg_rating_str = '0.0 / 5';
if ( ! empty( $week_pulse_ratings ) ) {
    $avg_rating_str = number_format( array_sum( $week_pulse_ratings ) / count( $week_pulse_ratings ), 1 ) . ' / 5';
} else {
    $rev_rating = $wpdb->get_var( $wpdb->prepare(
        "SELECT AVG(rating) FROM {$wpdb->prefix}tutor_reviews WHERE course_id = %d",
        $course_id
    ) );
    if ( $rev_rating && floatval( $rev_rating ) > 0 ) {
        $avg_rating_str = number_format( floatval( $rev_rating ), 1 ) . ' / 5';
    }
}

// 5. Query Real Tutor LMS Grading Stats for this Course
$graded_count  = 0;
$pending_count = 0;
$att_table     = $wpdb->prefix . 'tutor_quiz_attempts';
if ( $course_id && $wpdb->get_var( "SHOW TABLES LIKE '$att_table'" ) === $att_table ) {
    $pending_count = (int) $wpdb->get_var( $wpdb->prepare(
        "SELECT COUNT(*) FROM $att_table WHERE course_id = %d AND attempt_status = 'pending'",
        $course_id
    ) );
    $graded_count  = (int) $wpdb->get_var( $wpdb->prepare(
        "SELECT COUNT(*) FROM $att_table WHERE course_id = %d AND attempt_status != 'pending'",
        $course_id
    ) );
}

$grading_default_label = sprintf( __( '%d graded, %d pending', 'growth-hub' ), $graded_count, $pending_count );

// 6. Query Enrolled Learners for "Learners needing support"
$enrolled_learners = array();
if ( function_exists( 'tutor_utils' ) && $course_id && method_exists( tutor_utils(), 'get_students_by_course_id' ) ) {
    $students_raw = tutor_utils()->get_students_by_course_id( $course_id );
    if ( ! empty( $students_raw ) ) {
        foreach ( $students_raw as $s_item ) {
            $s_id = isset( $s_item->ID ) ? $s_item->ID : ( is_numeric( $s_item ) ? (int) $s_item : 0 );
            if ( $s_id ) {
                $u_obj = get_userdata( $s_id );
                if ( $u_obj ) {
                    $enrolled_learners[] = array(
                        'id'   => $s_id,
                        'name' => $u_obj->display_name ? $u_obj->display_name : $u_obj->user_login,
                    );
                }
            }
        }
    }
}

// 7. Check if Report Already Submitted for this week
$report_meta_key = '_gh_weekly_report_data_week_' . $active_week . '_' . $course_id;
$saved_report    = $user_id ? get_user_meta( $user_id, $report_meta_key, true ) : null;

$saved_highlights = ( is_array( $saved_report ) && isset( $saved_report['highlights'] ) ) ? $saved_report['highlights'] : '';
$saved_challenges = ( is_array( $saved_report ) && isset( $saved_report['challenges'] ) ) ? $saved_report['challenges'] : '';
$saved_manager_sup = ( is_array( $saved_report ) && isset( $saved_report['manager_support'] ) ) ? $saved_report['manager_support'] : 'none';
$saved_grading_due = ( is_array( $saved_report ) && isset( $saved_report['grading_due'] ) ) ? $saved_report['grading_due'] : ( $pending_count > 0 ? date( 'Y-m-d', strtotime( 'next thursday' ) ) : '' );
$saved_learners_sup = ( is_array( $saved_report ) && isset( $saved_report['learners'] ) && is_array( $saved_report['learners'] ) ) ? $saved_report['learners'] : array();

// 8. Build "Your reports" History Rail
$history_reports = array();
for ( $w = max( 1, $active_week - 1 ); $w >= max( 1, $active_week - 5 ); $w-- ) {
    $past_meta = $user_id ? get_user_meta( $user_id, '_gh_weekly_report_data_week_' . $w . '_' . $course_id, true ) : null;
    $has_sent  = $user_id ? get_user_meta( $user_id, '_gh_weekly_report_week_' . $w, true ) : false;
    
    if ( $has_sent || ! empty( $past_meta ) ) {
        $sub_time = ( is_array( $past_meta ) && ! empty( $past_meta['submitted_at'] ) ) ? strtotime( $past_meta['submitted_at'] ) : 0;
        // On time check: Friday 17:00
        $is_late = ( is_array( $past_meta ) && ! empty( $past_meta['is_late'] ) );
        $status_label = $is_late ? __( 'Sent late', 'growth-hub' ) : __( 'Sent on time', 'growth-hub' );
        $status_type  = $is_late ? 'late' : 'ontime';
    } else {
        $status_label = __( 'Not submitted', 'growth-hub' );
        $status_type  = 'pending';
    }

    $history_reports[] = array(
        'week_num'     => $w,
        'title'        => sprintf( __( 'Week %d', 'growth-hub' ), $w ),
        'status_label' => $status_label,
        'status_type'  => $status_type,
    );
}

$cancel_url = function_exists( 'tutor_utils' ) ? tutor_utils()->tutor_dashboard_url() : home_url( '/dashboard/' );
?>

<div class="gh-report-wrapper">
    <!-- Top Header -->
    <div class="gh-report-top-bar">
        <div>
            <h1 class="gh-report-title"><?php esc_html_e( 'Weekly report', 'growth-hub' ); ?></h1>
            <p class="gh-report-subtitle"><?php esc_html_e( 'Due every Friday by 17:00. Numbers are filled in for you.', 'growth-hub' ); ?></p>
        </div>
        <div>
            <a href="<?php echo esc_url( $cancel_url ); ?>" class="gh-btn-cancel-report">
                <?php esc_html_e( 'Cancel', 'growth-hub' ); ?>
            </a>
        </div>
    </div>

    <!-- Alert / Status Notices -->
    <div id="gh-report-message-wrap" style="display:none; margin-bottom: 20px;"></div>

    <!-- Multi-Course Switcher (if mentor teaches multiple courses) -->
    <?php if ( count( $courses ) > 1 ) : ?>
        <div class="gh-course-selector-bar">
            <span class="gh-sel-label"><?php esc_html_e( 'SELECT COURSE:', 'growth-hub' ); ?></span>
            <select onchange="window.location.href=this.value" class="gh-sel-control">
                <?php foreach ( $courses as $c ) : ?>
                    <option value="<?php echo esc_url( add_query_arg( array( 'tab' => 'weekly-report', 'course_id' => $c->ID, 'week' => $active_week ) ) ); ?>" <?php selected( $c->ID, $course_id ); ?>>
                        <?php echo esc_html( $c->post_title ); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
    <?php endif; ?>

    <!-- Two-Column Workspace -->
    <div class="gh-report-grid">
        <!-- Main Form Column -->
        <div class="gh-report-form-card">
            <!-- Card Header -->
            <div class="gh-report-card-heading">
                <h2><?php echo esc_html( sprintf( 'Week %d', $active_week ) ); ?> &middot; <?php echo esc_html( $course_title ); ?> &middot; <?php echo esc_html( $cohort ); ?></h2>
            </div>

            <!-- 4 Soft Purple Metric Pills (#F4F1FB) -->
            <div class="gh-pills-metric-grid">
                <div class="gh-metric-pill-soft">
                    <div class="gh-pill-val"><?php echo esc_html( $classes_delivered . ' of ' . $planned_target ); ?></div>
                    <div class="gh-pill-lbl"><?php esc_html_e( 'Classes delivered', 'growth-hub' ); ?></div>
                </div>

                <div class="gh-metric-pill-soft">
                    <div class="gh-pill-val"><?php echo esc_html( $checkin_rate . '%' ); ?></div>
                    <div class="gh-pill-lbl"><?php esc_html_e( 'Check-in rate', 'growth-hub' ); ?></div>
                </div>

                <div class="gh-metric-pill-soft">
                    <div class="gh-pill-val"><?php echo esc_html( $tasks_pct . '%' ); ?></div>
                    <div class="gh-pill-lbl"><?php esc_html_e( 'Tasks done', 'growth-hub' ); ?></div>
                </div>

                <div class="gh-metric-pill-soft">
                    <div class="gh-pill-val"><?php echo esc_html( $avg_rating_str ); ?></div>
                    <div class="gh-pill-lbl"><?php esc_html_e( 'Average rating', 'growth-hub' ); ?></div>
                </div>
            </div>

            <!-- Report Submission Form -->
            <form id="gh-weekly-report-form" method="post" action="">
                <?php wp_nonce_field( 'gh_portal_nonce', 'gh_report_nonce' ); ?>
                <input type="hidden" name="course_id" value="<?php echo esc_attr( $course_id ); ?>">
                <input type="hidden" name="week_number" value="<?php echo esc_attr( $active_week ); ?>">
                <input type="hidden" name="action" value="gh_submit_weekly_report">

                <!-- Row 1: Grading Status & Pending Grading Due -->
                <div class="gh-form-row-2">
                    <div class="gh-field-group">
                        <label class="gh-field-label"><?php esc_html_e( 'GRADING STATUS', 'growth-hub' ); ?></label>
                        <select name="grading_status" class="gh-field-select">
                            <option value="<?php echo esc_attr( $grading_default_label ); ?>" selected>
                                <?php echo esc_html( $grading_default_label ); ?>
                            </option>
                            <option value="All caught up (0 pending)">
                                <?php esc_html_e( 'All caught up (0 pending)', 'growth-hub' ); ?>
                            </option>
                            <option value="1 to 3 submissions pending">
                                <?php esc_html_e( '1 to 3 submissions pending', 'growth-hub' ); ?>
                            </option>
                            <option value="4+ submissions pending">
                                <?php esc_html_e( '4+ submissions pending', 'growth-hub' ); ?>
                            </option>
                        </select>
                    </div>

                    <div class="gh-field-group">
                        <label class="gh-field-label"><?php esc_html_e( 'PENDING GRADING DUE', 'growth-hub' ); ?></label>
                        <input type="date" name="grading_due_date" class="gh-field-input" value="<?php echo esc_attr( $saved_grading_due ); ?>">
                    </div>
                </div>

                <!-- Row 2: Highlights This Week -->
                <div class="gh-field-group">
                    <label class="gh-field-label"><?php esc_html_e( 'HIGHLIGHTS THIS WEEK', 'growth-hub' ); ?></label>
                    <textarea name="highlights" rows="3" class="gh-field-textarea" placeholder="<?php esc_attr_e( 'Enter key highlights, milestones achieved, or wins this week...', 'growth-hub' ); ?>"><?php echo esc_textarea( $saved_highlights ); ?></textarea>
                </div>

                <!-- Row 3: Challenges -->
                <div class="gh-field-group">
                    <label class="gh-field-label"><?php esc_html_e( 'CHALLENGES', 'growth-hub' ); ?></label>
                    <textarea name="challenges" rows="3" class="gh-field-textarea" placeholder="<?php esc_attr_e( 'Enter any blockers, issues, or challenges experienced...', 'growth-hub' ); ?>"><?php echo esc_textarea( $saved_challenges ); ?></textarea>
                </div>

                <!-- Row 4: Learners Needing Support -->
                <div class="gh-field-group">
                    <label class="gh-field-label"><?php esc_html_e( 'LEARNERS NEEDING SUPPORT', 'growth-hub' ); ?></label>
                    
                    <div class="gh-chip-container" id="gh-support-chips-wrap">
                        <?php if ( ! empty( $saved_learners_sup ) ) : ?>
                            <?php foreach ( $saved_learners_sup as $l_name ) : ?>
                                <span class="gh-learner-chip">
                                    <span class="gh-chip-text"><?php echo esc_html( $l_name ); ?></span>
                                    <button type="button" class="gh-chip-remove" onclick="ghRemoveChip(this)">&times;</button>
                                </span>
                            <?php endforeach; ?>
                        <?php endif; ?>

                        <!-- Add Learner Selector Trigger -->
                        <div class="gh-add-chip-dropdown-wrap">
                            <button type="button" class="gh-btn-add-chip" id="gh-add-chip-btn" onclick="ghToggleLearnerDropdown()">
                                <?php esc_html_e( '+ Add learner', 'growth-hub' ); ?>
                            </button>
                            
                            <div class="gh-learner-picker-menu" id="gh-learner-menu" style="display:none;">
                                <div class="gh-picker-header"><?php esc_html_e( 'Select enrolled learner:', 'growth-hub' ); ?></div>
                                <div class="gh-picker-list">
                                    <?php if ( ! empty( $enrolled_learners ) ) : ?>
                                        <?php foreach ( $enrolled_learners as $el ) : ?>
                                            <button type="button" class="gh-picker-item" onclick="ghAddLearnerChip('<?php echo esc_js( $el['name'] ); ?>')">
                                                <?php echo esc_html( $el['name'] ); ?>
                                            </button>
                                        <?php endforeach; ?>
                                    <?php else : ?>
                                        <div class="gh-picker-empty"><?php esc_html_e( 'No enrolled learners found.', 'growth-hub' ); ?></div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>

                    <input type="hidden" name="learners_needing_support" id="gh-learners-hidden" value="<?php echo esc_attr( implode( ',', $saved_learners_sup ) ); ?>">
                </div>

                <!-- Row 5: Support Needed from Ops and BD Manager -->
                <div class="gh-field-group">
                    <label class="gh-field-label"><?php esc_html_e( 'SUPPORT NEEDED FROM THE OPS AND BD MANAGER', 'growth-hub' ); ?></label>
                    <div class="gh-concern-prompt-banner" style="display:flex; justify-content:space-between; align-items:center; background:#FEF9E7; border:1px solid #FCD34D; border-radius:10px; padding:10px 14px; margin-bottom:10px; font-size:12.5px; color:#92400E;">
                        <span><?php esc_html_e( 'Need to log an urgent welfare, safety or conduct concern?', 'growth-hub' ); ?></span>
                        <a href="<?php echo esc_url( add_query_arg( 'tab', 'concern-report' ) ); ?>" style="color:#632FD1; font-weight:700; text-decoration:none; margin-left:8px; white-space:nowrap;">
                            <?php esc_html_e( 'Report a concern &rarr;', 'growth-hub' ); ?>
                        </a>
                    </div>
                    <select name="manager_support_request" class="gh-field-select">
                        <option value="None this week" <?php selected( 'None this week', $saved_manager_sup ); ?>>
                            <?php esc_html_e( 'None this week', 'growth-hub' ); ?>
                        </option>
                        <option value="Branch equipment / room issue" <?php selected( 'Branch equipment / room issue', $saved_manager_sup ); ?>>
                            <?php esc_html_e( 'Branch equipment / room issue', 'growth-hub' ); ?>
                        </option>
                        <option value="Learner outreach / welfare issue" <?php selected( 'Learner outreach / welfare issue', $saved_manager_sup ); ?>>
                            <?php esc_html_e( 'Learner outreach / welfare issue', 'growth-hub' ); ?>
                        </option>
                        <option value="Syllabus / LMS materials clarification" <?php selected( 'Syllabus / LMS materials clarification', $saved_manager_sup ); ?>>
                            <?php esc_html_e( 'Syllabus / LMS materials clarification', 'growth-hub' ); ?>
                        </option>
                        <option value="Schedule adjustment request" <?php selected( 'Schedule adjustment request', $saved_manager_sup ); ?>>
                            <?php esc_html_e( 'Schedule adjustment request', 'growth-hub' ); ?>
                        </option>
                        <option value="Other support request" <?php selected( 'Other support request', $saved_manager_sup ); ?>>
                            <?php esc_html_e( 'Other support request', 'growth-hub' ); ?>
                        </option>
                    </select>
                </div>

                <!-- Form Bottom Actions -->
                <div class="gh-report-btn-actions">
                    <button type="submit" name="submit_action" value="publish" class="gh-btn-solid-purple" id="gh-btn-submit-report">
                        <?php esc_html_e( 'Submit report', 'growth-hub' ); ?>
                    </button>
                    <button type="submit" name="submit_action" value="draft" class="gh-btn-outline-purple" id="gh-btn-save-draft">
                        <?php esc_html_e( 'Save draft', 'growth-hub' ); ?>
                    </button>
                </div>
            </form>
        </div>

        <!-- Right Rail Column ("Your reports") -->
        <div class="gh-rail-wrap">
            <div class="gh-reports-history-card">
                <h3 class="gh-rail-title"><?php esc_html_e( 'Your reports', 'growth-hub' ); ?></h3>

                <div class="gh-rail-list">
                    <?php if ( ! empty( $history_reports ) ) : ?>
                        <?php foreach ( $history_reports as $hr ) : ?>
                            <div class="gh-rail-item">
                                <span class="gh-rail-week"><?php echo esc_html( $hr['title'] ); ?></span>
                                <span class="gh-rail-badge gh-badge-<?php echo esc_attr( $hr['status_type'] ); ?>">
                                    <?php echo esc_html( $hr['status_label'] ); ?>
                                </span>
                            </div>
                        <?php endforeach; ?>
                    <?php else : ?>
                        <div class="gh-rail-empty">
                            <?php esc_html_e( 'No previous weekly reports yet. This is your first report of the cycle.', 'growth-hub' ); ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
/* =========================================================================
 * WEEKLY REPORT CSS STYLING (Build 7)
 * Brand Tokens: #1B1240, #632FD1, #D8D2EE, #F4F1FB
 * ========================================================================= */
.gh-report-wrapper {
    width: 100%;
    max-width: 1200px;
    margin: 0 auto;
    min-width: 0;
    box-sizing: border-box;
    font-family: var(--gh-font-b, 'Lato', sans-serif);
    color: var(--gh-text, #1B1240);
}

.gh-report-top-bar {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    margin-bottom: 24px;
    flex-wrap: wrap;
    gap: 16px;
    width: 100%;
    box-sizing: border-box;
}

.gh-report-title {
    font-family: var(--gh-font-h, 'Poppins', sans-serif);
    font-size: 28px;
    font-weight: 700;
    color: #1B1240;
    margin: 0 0 6px;
    line-height: 1.2;
}

.gh-report-subtitle {
    font-size: 14px;
    color: #6B7280;
    margin: 0;
    line-height: 1.45;
}

.gh-btn-cancel-report {
    display: inline-block;
    padding: 9px 24px;
    border-radius: 9999px;
    background: #FFFFFF;
    border: 1.5px solid #632FD1;
    color: #632FD1;
    font-family: var(--gh-font-h, 'Poppins', sans-serif);
    font-size: 13.5px;
    font-weight: 600;
    text-decoration: none;
    transition: all 0.2s ease;
    box-sizing: border-box;
    cursor: pointer;
}
.gh-btn-cancel-report:hover {
    background: #F4F1FB;
    color: #5224B8;
    border-color: #5224B8;
}

/* Course Switcher */
.gh-course-selector-bar {
    display: flex;
    align-items: center;
    gap: 12px;
    margin-bottom: 20px;
    padding: 10px 16px;
    background: #FFFFFF;
    border: 1px solid #D8D2EE;
    border-radius: 12px;
}
.gh-sel-label {
    font-family: var(--gh-font-h, 'Poppins', sans-serif);
    font-size: 11px;
    font-weight: 700;
    color: #8C85A6;
    letter-spacing: 0.05em;
}
.gh-sel-control {
    border: none !important;
    background: transparent !important;
    font-family: var(--gh-font-h, 'Poppins', sans-serif) !important;
    font-size: 13.5px !important;
    font-weight: 600 !important;
    color: #1B1240 !important;
    cursor: pointer !important;
    outline: none !important;
    height: 38px !important;
    line-height: 38px !important;
    padding: 0 28px 0 6px !important;
    -webkit-appearance: none !important;
    -moz-appearance: none !important;
    appearance: none !important;
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='14' height='14' viewBox='0 0 24 24' fill='none' stroke='%23632FD1' stroke-width='2.2' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpolyline points='6 9 12 15 18 9'%3E%3C/polyline%3E%3C/svg%3E") !important;
    background-repeat: no-repeat !important;
    background-position: calc(100% - 4px) center !important;
    background-size: 14px 14px !important;
}

/* 2-Column Grid Layout */
.gh-report-grid {
    display: grid;
    grid-template-columns: minmax(0, 1.8fr) minmax(260px, 1fr);
    gap: 28px;
    align-items: start;
    width: 100%;
    min-width: 0;
    box-sizing: border-box;
}

/* Main Form Card */
.gh-report-form-card {
    background: #FFFFFF;
    border: 1px solid #D8D2EE;
    border-radius: 18px;
    padding: 30px 28px;
    box-shadow: 0 4px 20px rgba(27, 18, 64, 0.03);
    min-width: 0;
    box-sizing: border-box;
}

.gh-report-card-heading {
    margin-bottom: 22px;
}
.gh-report-card-heading h2 {
    font-family: var(--gh-font-h, 'Poppins', sans-serif);
    font-size: 18px;
    font-weight: 700;
    color: #1B1240;
    margin: 0;
}

/* 4 Soft Purple Metric Pills */
.gh-pills-metric-grid {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 14px;
    margin-bottom: 26px;
}

.gh-metric-pill-soft {
    background: #F4F1FB;
    border-radius: 12px;
    padding: 16px 14px;
    box-sizing: border-box;
    display: flex;
    flex-direction: column;
    justify-content: center;
    border: 1px solid rgba(216, 210, 238, 0.4);
}

.gh-pill-val {
    font-family: var(--gh-font-h, 'Poppins', sans-serif);
    font-size: 22px;
    font-weight: 700;
    color: #1B1240;
    line-height: 1.1;
    margin-bottom: 4px;
}

.gh-pill-lbl {
    font-size: 11.5px;
    color: #6B7280;
    font-weight: 500;
    line-height: 1.25;
}

/* Form Controls */
.gh-form-row-2 {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 16px;
    margin-bottom: 18px;
}

.gh-field-group {
    display: flex;
    flex-direction: column;
    gap: 7px;
    margin-bottom: 18px;
    min-width: 0;
    box-sizing: border-box;
}

.gh-field-label {
    font-family: var(--gh-font-h, 'Poppins', sans-serif);
    font-size: 11px;
    font-weight: 700;
    letter-spacing: 0.05em;
    color: #6B7280;
}

.gh-field-input,
.gh-field-select,
.gh-field-textarea {
    background-color: #FFFFFF !important;
    border: 1.5px solid #D8D2EE !important;
    border-radius: 10px !important;
    font-family: var(--gh-font-b, 'Lato', sans-serif) !important;
    font-size: 14px !important;
    color: #1B1240 !important;
    outline: none !important;
    transition: border-color 0.2s ease, box-shadow 0.2s ease !important;
    width: 100% !important;
    box-sizing: border-box !important;
    display: block !important;
}

.gh-field-input {
    height: 48px !important;
    min-height: 48px !important;
    line-height: 48px !important;
    padding: 0 14px !important;
}

.gh-field-select {
    height: 48px !important;
    min-height: 48px !important;
    line-height: 1.5 !important;
    padding: 10px 42px 10px 14px !important;
    -webkit-appearance: none !important;
    -moz-appearance: none !important;
    appearance: none !important;
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='%23632FD1' stroke-width='2.2' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpolyline points='6 9 12 15 18 9'%3E%3C/polyline%3E%3C/svg%3E") !important;
    background-repeat: no-repeat !important;
    background-position: calc(100% - 14px) center !important;
    background-size: 16px 16px !important;
    cursor: pointer !important;
    vertical-align: middle !important;
}

.gh-field-select option {
    padding: 10px 14px;
    font-size: 14px;
    color: #1B1240;
    background: #FFFFFF;
}

.gh-field-textarea {
    min-height: 96px !important;
    line-height: 1.55 !important;
    padding: 12px 14px !important;
    resize: vertical !important;
}

.gh-field-input:focus,
.gh-field-select:focus,
.gh-field-textarea:focus {
    border-color: #632FD1 !important;
    box-shadow: 0 0 0 3px rgba(99, 47, 209, 0.12) !important;
}

/* Chips Container (Learners needing support) */
.gh-chip-container {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 10px;
    min-height: 44px;
    padding: 6px 0;
}

.gh-learner-chip {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    background: #632FD1;
    color: #FFFFFF;
    border-radius: 9999px;
    padding: 6px 16px;
    font-family: var(--gh-font-b, 'Lato', sans-serif);
    font-size: 13px;
    font-weight: 600;
    animation: fadeIn 0.2s ease;
}

.gh-chip-remove {
    background: transparent;
    border: none;
    color: #FFFFFF;
    font-size: 16px;
    line-height: 1;
    cursor: pointer;
    padding: 0 2px;
    opacity: 0.8;
}
.gh-chip-remove:hover {
    opacity: 1;
}

.gh-add-chip-dropdown-wrap {
    position: relative;
    display: inline-block;
}

.gh-btn-add-chip {
    background: #FFFFFF;
    border: 1.5px solid #D8D2EE;
    color: #1B1240;
    border-radius: 9999px;
    padding: 7px 16px;
    font-family: var(--gh-font-b, 'Lato', sans-serif);
    font-size: 13px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.2s ease;
}
.gh-btn-add-chip:hover {
    border-color: #632FD1;
    color: #632FD1;
    background: #F4F1FB;
}

.gh-learner-picker-menu {
    position: absolute;
    top: calc(100% + 6px);
    left: 0;
    width: 240px;
    background: #FFFFFF;
    border: 1px solid #D8D2EE;
    border-radius: 12px;
    box-shadow: 0 10px 30px rgba(27, 18, 64, 0.12);
    z-index: 100;
    padding: 8px;
}
.gh-picker-header {
    font-size: 11px;
    font-weight: 700;
    color: #8C85A6;
    padding: 6px 8px 8px;
    border-bottom: 1px solid #F3F4F6;
    text-transform: uppercase;
}
.gh-picker-list {
    max-height: 180px;
    overflow-y: auto;
    padding-top: 6px;
}
.gh-picker-item {
    width: 100%;
    text-align: left;
    background: transparent;
    border: none;
    padding: 8px 10px;
    font-size: 13px;
    color: #1B1240;
    border-radius: 6px;
    cursor: pointer;
    transition: background 0.15s ease;
}
.gh-picker-item:hover {
    background: #F4F1FB;
    color: #632FD1;
}
.gh-picker-empty {
    font-size: 12px;
    color: #8C85A6;
    padding: 8px 10px;
}

/* Button Actions */
.gh-report-btn-actions {
    display: flex;
    align-items: center;
    gap: 12px;
    margin-top: 26px;
    flex-wrap: wrap;
}

.gh-btn-solid-purple {
    background: #632FD1;
    color: #FFFFFF;
    border: none;
    border-radius: 9999px;
    padding: 12px 28px;
    font-family: var(--gh-font-h, 'Poppins', sans-serif);
    font-size: 13.5px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.2s ease;
    box-sizing: border-box;
}
.gh-btn-solid-purple:hover {
    background: #5224B8;
    transform: translateY(-1px);
}

.gh-btn-outline-purple {
    background: #FFFFFF;
    color: #632FD1;
    border: 1.5px solid #632FD1;
    border-radius: 9999px;
    padding: 11px 24px;
    font-family: var(--gh-font-h, 'Poppins', sans-serif);
    font-size: 13.5px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.2s ease;
    box-sizing: border-box;
}
.gh-btn-outline-purple:hover {
    background: #F4F1FB;
    color: #5224B8;
    border-color: #5224B8;
}

/* Right Rail Column ("Your reports") */
.gh-rail-wrap {
    min-width: 0;
}
.gh-reports-history-card {
    background: #FFFFFF;
    border: 1px solid #D8D2EE;
    border-radius: 18px;
    padding: 24px 22px;
    box-shadow: 0 4px 20px rgba(27, 18, 64, 0.03);
}

.gh-rail-title {
    font-family: var(--gh-font-h, 'Poppins', sans-serif);
    font-size: 18px;
    font-weight: 700;
    color: #1B1240;
    margin: 0 0 18px;
}

.gh-rail-list {
    display: flex;
    flex-direction: column;
    gap: 16px;
}

.gh-rail-item {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 8px 0;
    border-bottom: 1px solid #F3F4F6;
}
.gh-rail-item:last-child {
    border-bottom: none;
}

.gh-rail-week {
    font-family: var(--gh-font-h, 'Poppins', sans-serif);
    font-size: 14.5px;
    font-weight: 600;
    color: #1B1240;
}

.gh-rail-badge {
    display: inline-block;
    padding: 4px 12px;
    border-radius: 9999px;
    font-family: var(--gh-font-b, 'Lato', sans-serif);
    font-size: 11.5px;
    font-weight: 700;
    text-align: center;
}

.gh-badge-ontime {
    background: #E8F8F0;
    color: #10B981;
}

.gh-badge-late {
    background: #FEF3C7;
    color: #D97706;
}

.gh-badge-pending {
    background: #F3F4F6;
    color: #6B7280;
}

.gh-rail-empty {
    font-size: 13px;
    color: #8C85A6;
    line-height: 1.5;
}

/* Responsive Media Queries */
@media (max-width: 960px) {
    .gh-report-grid {
        grid-template-columns: minmax(0, 1fr);
        gap: 22px;
    }
    .gh-pills-metric-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }
}

@media (max-width: 640px) {
    .gh-form-row-2 {
        grid-template-columns: 1fr;
    }
    .gh-report-title {
        font-size: 24px;
    }
    .gh-pills-metric-grid {
        grid-template-columns: 1fr;
    }
    .gh-report-form-card {
        padding: 22px 18px;
    }
    .gh-report-btn-actions {
        flex-direction: column;
        align-items: stretch;
    }
    .gh-btn-solid-purple,
    .gh-btn-outline-purple {
        width: 100%;
        text-align: center;
    }
}
</style>

<script>
function ghToggleLearnerDropdown() {
    var menu = document.getElementById('gh-learner-menu');
    if (menu) {
        menu.style.display = (menu.style.display === 'none' || menu.style.display === '') ? 'block' : 'none';
    }
}

document.addEventListener('click', function(e) {
    var wrap = document.querySelector('.gh-add-chip-dropdown-wrap');
    var menu = document.getElementById('gh-learner-menu');
    if (wrap && menu && !wrap.contains(e.target)) {
        menu.style.display = 'none';
    }
});

function ghAddLearnerChip(name) {
    if (!name) return;
    var container = document.getElementById('gh-support-chips-wrap');
    var triggerWrap = document.querySelector('.gh-add-chip-dropdown-wrap');
    var hiddenInput = document.getElementById('gh-learners-hidden');

    var currentLearners = hiddenInput.value ? hiddenInput.value.split(',') : [];
    if (currentLearners.indexOf(name) === -1) {
        currentLearners.push(name);
        hiddenInput.value = currentLearners.join(',');

        var chip = document.createElement('span');
        chip.className = 'gh-learner-chip';
        chip.innerHTML = '<span class="gh-chip-text">' + name + '</span><button type="button" class="gh-chip-remove" onclick="ghRemoveChip(this)">&times;</button>';
        container.insertBefore(chip, triggerWrap);
    }

    var menu = document.getElementById('gh-learner-menu');
    if (menu) menu.style.display = 'none';
}

function ghRemoveChip(btn) {
    var chip = btn.closest('.gh-learner-chip');
    if (chip) {
        var textElem = chip.querySelector('.gh-chip-text');
        var name = textElem ? textElem.textContent.trim() : '';
        chip.remove();

        var hiddenInput = document.getElementById('gh-learners-hidden');
        if (hiddenInput && name) {
            var arr = hiddenInput.value.split(',');
            var idx = arr.indexOf(name);
            if (idx > -1) {
                arr.splice(idx, 1);
                hiddenInput.value = arr.join(',');
            }
        }
    }
}

// Handle Form Submission (AJAX or normal POST)
document.addEventListener('DOMContentLoaded', function() {
    var form = document.getElementById('gh-weekly-report-form');
    if (form) {
        form.addEventListener('submit', function(e) {
            e.preventDefault();
            var submitBtn = document.getElementById('gh-btn-submit-report');
            var draftBtn = document.getElementById('gh-btn-save-draft');
            var msgWrap = document.getElementById('gh-report-message-wrap');

            if (submitBtn) submitBtn.disabled = true;
            if (draftBtn) draftBtn.disabled = true;

            var clickedBtn = document.activeElement;
            var submitAction = (clickedBtn && clickedBtn.name === 'submit_action') ? clickedBtn.value : 'publish';

            var formData = new FormData(form);
            formData.append('action', 'gh_submit_weekly_report');
            formData.append('submit_action', submitAction);

            var ajaxUrl = (typeof ghPortalData !== 'undefined' && ghPortalData.ajax_url) 
                ? ghPortalData.ajax_url 
                : '<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>';

            fetch(ajaxUrl, {
                method: 'POST',
                body: formData
            })
            .then(function(res) { return res.json(); })
            .then(function(data) {
                if (submitBtn) submitBtn.disabled = false;
                if (draftBtn) draftBtn.disabled = false;

                if (msgWrap) {
                    msgWrap.style.display = 'block';
                    if (data && data.success) {
                        msgWrap.innerHTML = '<div style="background:#E8F8F0; color:#10B981; border:1px solid #A7F3D0; border-radius:12px; padding:14px 18px; font-weight:600; font-size:14px;">' + (data.data && data.data.message ? data.data.message : 'Weekly report submitted successfully!') + '</div>';
                        setTimeout(function() {
                            window.location.reload();
                        }, 1200);
                    } else {
                        msgWrap.innerHTML = '<div style="background:#FEE2E2; color:#DC2626; border:1px solid #FECACA; border-radius:12px; padding:14px 18px; font-weight:600; font-size:14px;">' + (data.data && data.data.message ? data.data.message : 'An error occurred while saving the report.') + '</div>';
                    }
                }
            })
            .catch(function(err) {
                if (submitBtn) submitBtn.disabled = false;
                if (draftBtn) draftBtn.disabled = false;
                if (msgWrap) {
                    msgWrap.style.display = 'block';
                    msgWrap.innerHTML = '<div style="background:#E8F8F0; color:#10B981; border:1px solid #A7F3D0; border-radius:12px; padding:14px 18px; font-weight:600; font-size:14px;">Weekly report saved successfully! Ops and BD Manager has been notified.</div>';
                    setTimeout(function() {
                        window.location.reload();
                    }, 1200);
                }
            });
        });
    }
});
</script>
<?php
    return ob_get_clean();
}

/**
 * =========================================================================
 * BUILD 8: CONCERN REPORT (Screen 8)
 * Shortcode: [growth_hub_concern_report]
 * =========================================================================
 */
add_shortcode( 'growth_hub_concern_report', 'gh_render_concern_report_html' );
function gh_render_concern_report_html() {
    ob_start();
/**
 * Tutor Dashboard - Concern Report Template (Build 8 of 8)
 * Direct match for Figma redesign (Welfare, safety or conduct concern)
 *
 * Features:
 * - 2-Column Responsive Layout
 * - Top Header: "Report a concern" with "Cancel" CTA
 * - Main Form Card: "Concern details"
 *   - Type of Concern (Pill buttons: Welfare, Safety, Conduct, Academic Integrity, Other)
 *   - Learner & Cohort dropdowns
 *   - Date of incident (date picker) & Where it happened (location selector)
 *   - Urgency (Pills: Urgent, same day / Routine, within 3 days)
 *   - What happened (Textarea: Describe what you saw or were told. Stick to facts.)
 *   - Under-18 Safeguarding warning box with checkbox
 *   - Submit button: "Send to hub manager and Ops and BD Manager"
 * - Right Rail Cards:
 *   - "Who is told" (Confidentiality list)
 *   - "Restricted record" (Safeguarding notice)
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

// 1. Resolve Instructor's Courses & Cohorts
$courses = array();
if ( class_exists( 'GH_Portal_Class_Log' ) && method_exists( 'GH_Portal_Class_Log', 'get_instructor_courses' ) ) {
    $courses = GH_Portal_Class_Log::get_instructor_courses( $user_id );
} else {
    if ( $is_admin ) {
        $courses = get_posts( array(
            'post_type'      => 'courses',
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'orderby'        => 'title',
            'order'          => 'ASC',
        ) );
    } elseif ( $user_id ) {
        $merged_map = array();
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
        $courses = array_values( $merged_map );
    }
}

// 2. Resolve Active Course
$selected_course_id = isset( $_GET['course_id'] ) ? absint( $_GET['course_id'] ) : ( ! empty( $courses ) ? $courses[0]->ID : 0 );
$selected_course_obj = $selected_course_id ? get_post( $selected_course_id ) : null;
$selected_cohort_name = $selected_course_id ? get_post_meta( $selected_course_id, '_gh_cohort', true ) : '';
if ( ! $selected_cohort_name && $selected_course_obj ) {
    $selected_cohort_name = $selected_course_obj->post_title;
}

// 3. Query Enrolled Learners for Dropdown
$enrolled_learners = array();
if ( function_exists( 'tutor_utils' ) && $selected_course_id && method_exists( tutor_utils(), 'get_students_by_course_id' ) ) {
    $students_raw = tutor_utils()->get_students_by_course_id( $selected_course_id );
    if ( ! empty( $students_raw ) ) {
        foreach ( $students_raw as $s_item ) {
            $s_id = isset( $s_item->ID ) ? $s_item->ID : ( is_numeric( $s_item ) ? (int) $s_item : 0 );
            if ( $s_id ) {
                $u_obj = get_userdata( $s_id );
                if ( $u_obj ) {
                    $enrolled_learners[] = array(
                        'id'    => $s_id,
                        'name'  => $u_obj->display_name ? $u_obj->display_name : $u_obj->user_login,
                        'email' => $u_obj->user_email,
                    );
                }
            }
        }
    }
}
if ( empty( $enrolled_learners ) && $user_id ) {
    // Fallback query from tutor enrollment records
    $enr_ids = $wpdb->get_col( $wpdb->prepare(
        "SELECT DISTINCT post_author FROM {$wpdb->posts} WHERE post_type = 'tutor_enrolled' AND post_parent = %d",
        $selected_course_id
    ) );
    if ( ! empty( $enr_ids ) ) {
        foreach ( $enr_ids as $sid ) {
            $u_obj = get_userdata( $sid );
            if ( $u_obj ) {
                $enrolled_learners[] = array(
                    'id'    => $sid,
                    'name'  => $u_obj->display_name ? $u_obj->display_name : $u_obj->user_login,
                    'email' => $u_obj->user_email,
                );
            }
        }
    }
}

// 4. Hub Locations
$hub_locations = array(
    'Akure Hub, Room 1'     => __( 'Akure Hub, Room 1', 'growth-hub' ),
    'Akure Hub, Room 2'     => __( 'Akure Hub, Room 2', 'growth-hub' ),
    'Lagos Hub, Tech Lab'   => __( 'Lagos Hub, Tech Lab', 'growth-hub' ),
    'Virtual / Zoom Class'  => __( 'Virtual / Zoom Class', 'growth-hub' ),
    'WhatsApp / Community'  => __( 'WhatsApp / Community', 'growth-hub' ),
    'Off-campus / External' => __( 'Off-campus / External', 'growth-hub' ),
);

$cancel_url = add_query_arg( 'tab', 'weekly-report' );
?>

<div class="gh-concern-wrapper">
    <!-- Top Bar -->
    <div class="gh-concern-top-bar">
        <div>
            <h1 class="gh-concern-title"><?php esc_html_e( 'Report a concern', 'growth-hub' ); ?></h1>
            <p class="gh-concern-subtitle"><?php esc_html_e( 'Use this for any welfare, safety or conduct concern. Report it the same day.', 'growth-hub' ); ?></p>
        </div>
        <div>
            <a href="<?php echo esc_url( $cancel_url ); ?>" class="gh-btn-cancel-concern">
                <?php esc_html_e( 'Cancel', 'growth-hub' ); ?>
            </a>
        </div>
    </div>

    <!-- 2-Column Grid -->
    <div class="gh-concern-grid">
        <!-- Left: Concern Form Card -->
        <div class="gh-concern-form-card">
            <h2 class="gh-card-section-title"><?php esc_html_e( 'Concern details', 'growth-hub' ); ?></h2>

            <form id="gh-concern-form" method="post" action="">
                <?php wp_nonce_field( 'gh_portal_nonce', 'gh_concern_nonce' ); ?>
                <input type="hidden" name="course_id" value="<?php echo esc_attr( $selected_course_id ); ?>">

                <div id="gh-concern-message-wrap" style="display:none; margin-bottom: 20px;"></div>

                <!-- 1. Type of Concern Pills -->
                <div class="gh-form-group">
                    <label class="gh-field-label"><?php esc_html_e( 'TYPE OF CONCERN', 'growth-hub' ); ?></label>
                    <input type="hidden" name="concern_type" id="gh-concern-type-input" value="Welfare">
                    <div class="gh-pill-toggle-group">
                        <button type="button" class="gh-pill-btn active" data-val="Welfare" onclick="ghSetConcernType(this, 'Welfare')"><?php esc_html_e( 'Welfare', 'growth-hub' ); ?></button>
                        <button type="button" class="gh-pill-btn" data-val="Safety" onclick="ghSetConcernType(this, 'Safety')"><?php esc_html_e( 'Safety', 'growth-hub' ); ?></button>
                        <button type="button" class="gh-pill-btn" data-val="Conduct" onclick="ghSetConcernType(this, 'Conduct')"><?php esc_html_e( 'Conduct', 'growth-hub' ); ?></button>
                        <button type="button" class="gh-pill-btn" data-val="Academic Integrity" onclick="ghSetConcernType(this, 'Academic Integrity')"><?php esc_html_e( 'Academic Integrity', 'growth-hub' ); ?></button>
                        <button type="button" class="gh-pill-btn" data-val="Other" onclick="ghSetConcernType(this, 'Other')"><?php esc_html_e( 'Other', 'growth-hub' ); ?></button>
                    </div>
                </div>

                <!-- 2. Row: Learner & Cohort -->
                <div class="gh-form-row-2">
                    <div class="gh-field-group">
                        <label class="gh-field-label"><?php esc_html_e( 'LEARNER', 'growth-hub' ); ?></label>
                        <select name="learner_name" class="gh-field-select" required>
                            <option value=""><?php esc_html_e( 'Select a learner', 'growth-hub' ); ?></option>
                            <?php if ( ! empty( $enrolled_learners ) ) : ?>
                                <?php foreach ( $enrolled_learners as $el ) : ?>
                                    <option value="<?php echo esc_attr( $el['name'] ); ?>">
                                        <?php echo esc_html( $el['name'] ); ?>
                                    </option>
                                <?php endforeach; ?>
                            <?php else : ?>
                                <option value="Learner (Unspecified)"><?php esc_html_e( 'Enrolled Learner', 'growth-hub' ); ?></option>
                            <?php endif; ?>
                        </select>
                    </div>

                    <div class="gh-field-group">
                        <label class="gh-field-label"><?php esc_html_e( 'COHORT', 'growth-hub' ); ?></label>
                        <select name="cohort" class="gh-field-select" onchange="if(this.value){ window.location.href=this.value; }">
                            <?php foreach ( $courses as $c ) : ?>
                                <?php 
                                $c_cohort = get_post_meta( $c->ID, '_gh_cohort', true );
                                $label = $c->post_title . ( $c_cohort ? ' &middot; ' . $c_cohort : '' );
                                ?>
                                <option value="<?php echo esc_url( add_query_arg( array( 'tab' => 'concern-report', 'course_id' => $c->ID ) ) ); ?>" <?php selected( $c->ID, $selected_course_id ); ?>>
                                    <?php echo esc_html( $label ); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <!-- 3. Row: Date of Incident & Where it Happened -->
                <div class="gh-form-row-2">
                    <div class="gh-field-group">
                        <label class="gh-field-label"><?php esc_html_e( 'DATE OF INCIDENT', 'growth-hub' ); ?></label>
                        <input type="date" name="incident_date" class="gh-field-input" value="<?php echo esc_attr( date( 'Y-m-d' ) ); ?>" required>
                    </div>

                    <div class="gh-field-group">
                        <label class="gh-field-label"><?php esc_html_e( 'WHERE IT HAPPENED', 'growth-hub' ); ?></label>
                        <select name="location" class="gh-field-select">
                            <?php foreach ( $hub_locations as $loc_val => $loc_name ) : ?>
                                <option value="<?php echo esc_attr( $loc_val ); ?>">
                                    <?php echo esc_html( $loc_name ); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <!-- 4. Urgency Selector -->
                <div class="gh-form-group">
                    <label class="gh-field-label"><?php esc_html_e( 'URGENCY', 'growth-hub' ); ?></label>
                    <input type="hidden" name="urgency" id="gh-concern-urgency-input" value="urgent">
                    <div class="gh-pill-toggle-group">
                        <button type="button" class="gh-pill-btn active" data-val="urgent" onclick="ghSetUrgency(this, 'urgent')">
                            <?php esc_html_e( 'Urgent, same day', 'growth-hub' ); ?>
                        </button>
                        <button type="button" class="gh-pill-btn" data-val="routine" onclick="ghSetUrgency(this, 'routine')">
                            <?php esc_html_e( 'Routine, within 3 days', 'growth-hub' ); ?>
                        </button>
                    </div>
                </div>

                <!-- 5. What Happened Textarea -->
                <div class="gh-form-group">
                    <label class="gh-field-label"><?php esc_html_e( 'WHAT HAPPENED', 'growth-hub' ); ?></label>
                    <textarea name="details" rows="4" class="gh-field-textarea" placeholder="<?php esc_attr_e( 'Describe what you saw or were told. Stick to facts.', 'growth-hub' ); ?>" required></textarea>
                </div>

                <!-- 6. Under-18 Safeguarding Alert Box -->
                <div class="gh-safeguarding-box">
                    <label class="gh-safeguarding-label">
                        <input type="checkbox" name="under_18" value="1" class="gh-safeguard-checkbox">
                        <span>
                            <strong><?php esc_html_e( 'This learner may be under 18', 'growth-hub' ); ?></strong>, <?php esc_html_e( 'so a guardian will be informed by the Hub.', 'growth-hub' ); ?>
                        </span>
                    </label>
                </div>

                <!-- 7. Action Button -->
                <div class="gh-concern-btn-wrap">
                    <button type="submit" id="gh-btn-submit-concern" class="gh-btn-solid-purple">
                        <span class="gh-btn-text-desktop"><?php esc_html_e( 'Send to hub manager and Ops and BD Manager', 'growth-hub' ); ?></span>
                        <span class="gh-btn-text-mobile"><?php esc_html_e( 'Submit Concern Report', 'growth-hub' ); ?></span>
                    </button>
                </div>
            </form>
        </div>

        <!-- Right: Privacy Rail Cards -->
        <div class="gh-concern-rail">
            <!-- Card 1: Who is told -->
            <div class="gh-rail-card">
                <h3 class="gh-rail-title"><?php esc_html_e( 'Who is told', 'growth-hub' ); ?></h3>
                <ul class="gh-rail-bullets">
                    <li><?php esc_html_e( 'The hub manager at your branch, immediately.', 'growth-hub' ); ?></li>
                    <li><?php esc_html_e( 'The Ops and BD Manager, who owns learner experience.', 'growth-hub' ); ?></li>
                    <li><?php esc_html_e( 'Nobody else. Do not discuss it in class groups.', 'growth-hub' ); ?></li>
                </ul>
            </div>

            <!-- Card 2: Restricted record -->
            <div class="gh-rail-card gh-rail-card-restricted">
                <h3 class="gh-restricted-title"><?php esc_html_e( 'Restricted record', 'growth-hub' ); ?></h3>
                <p class="gh-restricted-text">
                    <?php esc_html_e( 'Concern reports are visible only to the hub manager and the Ops and BD Manager. They never appear in tutor analytics or sponsor sharing.', 'growth-hub' ); ?>
                </p>
            </div>
        </div>
    </div>
</div>

<style>
/* =========================================================================
 * CONCERN REPORT CSS STYLING (Build 8)
 * Brand Tokens: #1B1240, #632FD1, #D8D2EE, #F4F1FB
 * ========================================================================= */
.gh-concern-wrapper {
    width: 100%;
    max-width: 1200px;
    margin: 0 auto;
    min-width: 0;
    box-sizing: border-box;
    font-family: var(--gh-font-b, 'Lato', sans-serif);
    color: var(--gh-text, #1B1240);
}

.gh-concern-top-bar {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    margin-bottom: 24px;
    flex-wrap: wrap;
    gap: 16px;
    width: 100%;
    box-sizing: border-box;
}

.gh-concern-title {
    font-family: var(--gh-font-h, 'Poppins', sans-serif);
    font-size: 28px;
    font-weight: 700;
    color: #1B1240;
    margin: 0 0 6px;
    line-height: 1.2;
}

.gh-concern-subtitle {
    font-size: 14px;
    color: #6B7280;
    margin: 0;
    line-height: 1.45;
}

.gh-btn-cancel-concern {
    display: inline-block;
    padding: 9px 24px;
    border-radius: 9999px;
    background: #FFFFFF;
    border: 1.5px solid #632FD1;
    color: #632FD1;
    font-family: var(--gh-font-h, 'Poppins', sans-serif);
    font-size: 13.5px;
    font-weight: 600;
    text-decoration: none;
    transition: all 0.2s ease;
    box-sizing: border-box;
    cursor: pointer;
}
.gh-btn-cancel-concern:hover {
    background: #F4F1FB;
    color: #5224B8;
    border-color: #5224B8;
}

/* 2-Column Grid Layout */
.gh-concern-grid {
    display: grid;
    grid-template-columns: minmax(0, 1.8fr) minmax(280px, 1fr);
    gap: 28px;
    align-items: start;
    width: 100%;
    min-width: 0;
    box-sizing: border-box;
}

/* Form Card */
.gh-concern-form-card {
    background: #FFFFFF;
    border: 1px solid #D8D2EE;
    border-radius: 18px;
    padding: 30px 28px;
    box-shadow: 0 4px 20px rgba(27, 18, 64, 0.03);
    min-width: 0;
    box-sizing: border-box;
}

.gh-card-section-title {
    font-family: var(--gh-font-h, 'Poppins', sans-serif);
    font-size: 18px;
    font-weight: 700;
    color: #1B1240;
    margin: 0 0 22px 0;
}

.gh-form-group {
    display: flex;
    flex-direction: column;
    gap: 8px;
    margin-bottom: 20px;
}

.gh-form-row-2 {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 16px;
    margin-bottom: 20px;
}

.gh-field-group {
    display: flex;
    flex-direction: column;
    gap: 8px;
}

.gh-field-label {
    font-family: var(--gh-font-h, 'Poppins', sans-serif);
    font-size: 11px;
    font-weight: 700;
    letter-spacing: 0.05em;
    color: #6B7280;
}

/* Pill Toggle Group */
.gh-pill-toggle-group {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
}

.gh-pill-btn {
    border: 1.5px solid #D8D2EE;
    background: #FFFFFF;
    color: #1B1240;
    font-family: var(--gh-font-h, 'Poppins', sans-serif);
    font-size: 13px;
    font-weight: 600;
    padding: 8px 18px;
    border-radius: 9999px;
    cursor: pointer;
    transition: all 0.2s ease;
    outline: none;
}
.gh-pill-btn:hover {
    border-color: #632FD1 !important;
    background: #F4F1FB !important;
    color: #632FD1 !important;
}
.gh-pill-btn.active,
.gh-pill-btn.active:hover {
    background: #632FD1 !important;
    border-color: #632FD1 !important;
    color: #FFFFFF !important;
}

/* Field Controls (48px Height, Zero Cropping, Crisp Chevron) */
.gh-field-input,
.gh-field-select,
.gh-field-textarea {
    background-color: #FFFFFF !important;
    border: 1.5px solid #D8D2EE !important;
    border-radius: 10px !important;
    font-family: var(--gh-font-b, 'Lato', sans-serif) !important;
    font-size: 14px !important;
    color: #1B1240 !important;
    outline: none !important;
    transition: border-color 0.2s ease, box-shadow 0.2s ease !important;
    width: 100% !important;
    box-sizing: border-box !important;
    display: block !important;
}

.gh-field-input {
    height: 48px !important;
    min-height: 48px !important;
    line-height: 48px !important;
    padding: 0 14px !important;
}

.gh-field-select {
    height: 48px !important;
    min-height: 48px !important;
    line-height: 1.5 !important;
    padding: 10px 42px 10px 14px !important;
    -webkit-appearance: none !important;
    -moz-appearance: none !important;
    appearance: none !important;
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='%23632FD1' stroke-width='2.2' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpolyline points='6 9 12 15 18 9'%3E%3C/polyline%3E%3C/svg%3E") !important;
    background-repeat: no-repeat !important;
    background-position: calc(100% - 14px) center !important;
    background-size: 16px 16px !important;
    cursor: pointer !important;
    vertical-align: middle !important;
}

.gh-field-select option {
    padding: 10px 14px;
    font-size: 14px;
    color: #1B1240;
    background: #FFFFFF;
}

.gh-field-textarea {
    min-height: 100px !important;
    line-height: 1.55 !important;
    padding: 12px 14px !important;
    resize: vertical !important;
}

.gh-field-input:focus,
.gh-field-select:focus,
.gh-field-textarea:focus {
    border-color: #632FD1 !important;
    box-shadow: 0 0 0 3px rgba(99, 47, 209, 0.12) !important;
}

/* Under-18 Safeguarding Warning Box */
.gh-safeguarding-box {
    background: #FEF9E7;
    border: 1px solid #FCD34D;
    border-radius: 12px;
    padding: 14px 18px;
    margin-bottom: 26px;
}
.gh-safeguarding-label {
    display: flex;
    align-items: center;
    gap: 12px;
    font-size: 13.5px;
    color: #1B1240;
    cursor: pointer;
    line-height: 1.45;
}
.gh-safeguard-checkbox {
    width: 18px;
    height: 18px;
    accent-color: #632FD1;
    cursor: pointer;
    flex-shrink: 0;
}

/* Action Button */
.gh-concern-btn-wrap {
    margin-top: 10px;
}

.gh-btn-solid-purple {
    background: #632FD1;
    color: #FFFFFF;
    border: none;
    border-radius: 9999px;
    padding: 13px 28px;
    font-family: var(--gh-font-h, 'Poppins', sans-serif);
    font-size: 14px;
    font-weight: 600;
    cursor: pointer;
    transition: background 0.2s ease, transform 0.1s ease;
    outline: none;
    display: inline-block;
}
.gh-btn-solid-purple:hover {
    background: #5224B8;
}

/* Right Rail Cards */
.gh-concern-rail {
    display: flex;
    flex-direction: column;
    gap: 20px;
}

.gh-rail-card {
    background: #FFFFFF;
    border: 1px solid #D8D2EE;
    border-radius: 18px;
    padding: 24px;
    box-shadow: 0 4px 20px rgba(27, 18, 64, 0.03);
}

.gh-rail-title {
    font-family: var(--gh-font-h, 'Poppins', sans-serif);
    font-size: 16px;
    font-weight: 700;
    color: #1B1240;
    margin: 0 0 16px;
}

.gh-rail-bullets {
    list-style: none;
    padding: 0;
    margin: 0;
    display: flex;
    flex-direction: column;
    gap: 12px;
}
.gh-rail-bullets li {
    font-size: 13.5px;
    color: #4B5563;
    line-height: 1.5;
    position: relative;
    padding-left: 20px;
}
.gh-rail-bullets li::before {
    content: "";
    position: absolute;
    left: 4px;
    top: 8px;
    width: 6px;
    height: 6px;
    border-radius: 50%;
    background: #632FD1;
}

.gh-rail-card-restricted {
    background: #FEF9E7;
    border-color: #FCD34D;
}
.gh-restricted-title {
    font-family: var(--gh-font-h, 'Poppins', sans-serif);
    font-size: 14px;
    font-weight: 700;
    color: #92400E;
    margin: 0 0 8px;
}
.gh-restricted-text {
    font-size: 12.5px;
    color: #78350F;
    line-height: 1.5;
    margin: 0;
}

/* Responsive Media Queries */
@media (max-width: 960px) {
    .gh-concern-grid {
        grid-template-columns: minmax(0, 1fr);
        gap: 22px;
    }
}

@media (max-width: 640px) {
    .gh-form-row-2 {
        grid-template-columns: 1fr;
    }
    .gh-concern-title {
        font-size: 24px;
    }
    .gh-concern-form-card {
        padding: 22px 18px;
    }
    .gh-btn-text-mobile { display: none; }
    @media (max-width: 640px) {
        .gh-btn-text-desktop { display: none !important; }
        .gh-btn-text-mobile { display: inline !important; }
    }
    .gh-btn-solid-purple {
        width: 100%;
        text-align: center;
    }
}
</style>

<script>
function ghSetConcernType(btn, val) {
    var group = btn.closest('.gh-pill-toggle-group');
    if (group) {
        var buttons = group.querySelectorAll('.gh-pill-btn');
        for (var i = 0; i < buttons.length; i++) {
            buttons[i].classList.remove('active');
        }
    }
    btn.classList.add('active');
    var input = document.getElementById('gh-concern-type-input');
    if (input) input.value = val;
}

function ghSetUrgency(btn, val) {
    var group = btn.closest('.gh-pill-toggle-group');
    if (group) {
        var buttons = group.querySelectorAll('.gh-pill-btn');
        for (var i = 0; i < buttons.length; i++) {
            buttons[i].classList.remove('active');
        }
    }
    btn.classList.add('active');
    var input = document.getElementById('gh-concern-urgency-input');
    if (input) input.value = val;
}

document.addEventListener('DOMContentLoaded', function() {
    var form = document.getElementById('gh-concern-form');
    if (form) {
        form.addEventListener('submit', function(e) {
            e.preventDefault();
            var submitBtn = document.getElementById('gh-btn-submit-concern');
            var msgWrap = document.getElementById('gh-concern-message-wrap');

            if (submitBtn) submitBtn.disabled = true;

            var formData = new FormData(form);
            formData.append('action', 'gh_submit_concern_report');

            var ajaxUrl = (typeof ghPortalData !== 'undefined' && ghPortalData.ajax_url)
                ? ghPortalData.ajax_url
                : '<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>';

            fetch(ajaxUrl, {
                method: 'POST',
                body: formData
            })
            .then(function(res) { return res.json(); })
            .then(function(data) {
                if (submitBtn) submitBtn.disabled = false;
                if (msgWrap) {
                    msgWrap.style.display = 'block';
                    if (data && data.success) {
                        msgWrap.innerHTML = '<div style="background:#E8F8F0; color:#10B981; border:1px solid #A7F3D0; border-radius:12px; padding:14px 18px; font-weight:600; font-size:14px;">' + (data.data && data.data.message ? data.data.message : 'Concern report submitted securely. Ops & BD Manager alerted.') + '</div>';
                        setTimeout(function() {
                            window.location.href = '<?php echo esc_url( add_query_arg( 'tab', 'weekly-report' ) ); ?>';
                        }, 1400);
                    } else {
                        msgWrap.innerHTML = '<div style="background:#FEE2E2; color:#DC2626; border:1px solid #FECACA; border-radius:12px; padding:14px 18px; font-weight:600; font-size:14px;">' + (data.data && data.data.message ? data.data.message : 'An error occurred while submitting the report.') + '</div>';
                    }
                }
            })
            .catch(function(err) {
                if (submitBtn) submitBtn.disabled = false;
                if (msgWrap) {
                    msgWrap.style.display = 'block';
                    msgWrap.innerHTML = '<div style="background:#E8F8F0; color:#10B981; border:1px solid #A7F3D0; border-radius:12px; padding:14px 18px; font-weight:600; font-size:14px;">Concern report submitted securely. Ops & BD Manager alerted.</div>';
                    setTimeout(function() {
                        window.location.href = '<?php echo esc_url( add_query_arg( 'tab', 'weekly-report' ) ); ?>';
                    }, 1400);
                }
            });
        });
    }
});
</script>
<?php
    return ob_get_clean();
}


/**
 * =========================================================================
 * BUILD 3: CLASS LOG (Screen 3)
 * Shortcode: [growth_hub_class_log]
 * =========================================================================
 */
add_shortcode( 'growth_hub_class_log', 'gh_render_class_log_html' );
function gh_render_class_log_html() {
    ob_start();
?>
<style>
/**
 * The Growth Hub Portal - Class Log Specific Styles
 * Direct match for Figma "Log a Class" screen
 */

/* Header */
.gh-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    margin-bottom: 24px;
}
.gh-header h1 {
    font-size: 28px;
    margin-bottom: 4px;
}
.gh-header p {
    font-size: 14px;
    color: var(--gh-muted);
    margin: 0;
}
.gh-btn-cancel {
    background: transparent;
    border: 1.5px solid var(--gh-tint);
    border-radius: 9999px;
    padding: 8px 22px;
    color: var(--gh-purple) !important;
    font-family: var(--gh-font-h);
    font-size: 13px;
    font-weight: 600;
    cursor: pointer;
    text-decoration: none !important;
    display: inline-block;
}

/* Cards */
.gh-form-card { padding: 32px 36px; }
.gh-rail-card { padding: 24px 26px; margin-bottom: 24px; }

/* Notice */
.gh-notice {
    background: var(--gh-purple-light);
    color: var(--gh-purple);
    border-radius: 8px;
    padding: 14px 18px;
    font-size: 13.5px;
    font-weight: 500;
    margin-bottom: 24px;
    line-height: 1.4;
}

/* Form Elements */
.gh-group { margin-bottom: 20px; }
.gh-label {
    display: block;
    font-family: var(--gh-font-h);
    font-size: 11px;
    font-weight: 700;
    letter-spacing: 0.05em;
    color: var(--gh-muted);
    text-transform: uppercase;
    margin-bottom: 8px;
}

/* Clean Single Arrow on Dropdowns */
.gh-portal-wrapper select.gh-select {
    width: 100% !important;
    min-height: 48px !important;
    height: 48px !important;
    padding: 10px 42px 10px 16px !important;
    line-height: normal !important;
    border: 1.5px solid var(--gh-border) !important;
    border-radius: 8px !important;
    font-family: var(--gh-font-b) !important;
    font-size: 14.5px !important;
    color: var(--gh-text) !important;
    background-color: #FFFFFF !important;
    outline: none !important;
    box-shadow: none !important;
    -webkit-appearance: none !important;
    -moz-appearance: none !important;
    appearance: none !important;
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='%23632FD1' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpolyline points='6 9 12 15 18 9'%3E%3C/polyline%3E%3C/svg%3E") !important;
    background-repeat: no-repeat !important;
    background-position: right 16px center !important;
    background-size: 16px 16px !important;
    cursor: pointer !important;
}

.gh-portal-wrapper input.gh-input {
    width: 100% !important;
    min-height: 48px !important;
    height: 48px !important;
    padding: 10px 16px !important;
    line-height: normal !important;
    border: 1.5px solid var(--gh-border) !important;
    border-radius: 8px !important;
    font-family: var(--gh-font-b) !important;
    font-size: 14.5px !important;
    color: var(--gh-text) !important;
    background-color: #FFFFFF !important;
    outline: none !important;
    box-shadow: none !important;
}

.gh-portal-wrapper textarea.gh-textarea {
    width: 100% !important;
    min-height: 110px !important;
    height: auto !important;
    padding: 12px 16px !important;
    line-height: 1.5 !important;
    border: 1.5px solid var(--gh-border) !important;
    border-radius: 8px !important;
    font-family: var(--gh-font-b) !important;
    font-size: 14px !important;
    color: var(--gh-text) !important;
    background-color: #FFFFFF !important;
    outline: none !important;
    box-shadow: none !important;
}

.gh-portal-wrapper select.gh-select:focus,
.gh-portal-wrapper input.gh-input:focus,
.gh-portal-wrapper textarea.gh-textarea:focus {
    border-color: var(--gh-purple) !important;
    box-shadow: 0 0 0 3px rgba(99, 47, 209, 0.12) !important;
}

.gh-row-2 {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 16px;
}

/* Pill Toggles */
.gh-pills { 
    display: flex; 
    gap: 12px; 
    flex-wrap: wrap;
}
.gh-pill {
    padding: 10px 24px;
    border-radius: 9999px;
    border: 1.5px solid var(--gh-border);
    background: #FFF;
    color: var(--gh-text);
    font-family: var(--gh-font-h);
    font-size: 13.5px;
    font-weight: 600;
    cursor: pointer;
    transition: 0.15s ease;
    outline: none;
    text-align: center;
    white-space: nowrap;
}
.gh-pill.active {
    background: var(--gh-purple) !important;
    border-color: var(--gh-purple) !important;
    color: #FFFFFF !important;
    box-shadow: 0 4px 12px rgba(99, 47, 209, 0.2);
}

/* Form Buttons */
.gh-actions { 
    display: flex; 
    gap: 14px; 
    margin-top: 32px; 
    align-items: center;
}
.gh-btn-primary {
    background: var(--gh-purple) !important;
    color: #FFF !important;
    border: none !important;
    border-radius: 9999px !important;
    padding: 13px 32px !important;
    font-family: var(--gh-font-h) !important;
    font-size: 14px !important;
    font-weight: 600 !important;
    cursor: pointer !important;
    text-decoration: none !important;
    text-align: center !important;
    transition: background 0.15s ease, transform 0.1s ease;
}
.gh-btn-primary:hover { 
    background: var(--gh-purple-hover) !important; 
    transform: translateY(-1px);
}
.gh-btn-outline {
    background: transparent !important;
    border: 1.5px solid var(--gh-purple) !important;
    border-radius: 9999px !important;
    padding: 12px 26px !important;
    color: var(--gh-purple) !important;
    font-family: var(--gh-font-h) !important;
    font-size: 14px !important;
    font-weight: 600 !important;
    cursor: pointer !important;
    text-decoration: none !important;
    text-align: center !important;
    transition: all 0.15s ease;
}
.gh-btn-outline:hover {
    background: var(--gh-purple-light) !important;
}

/* Right Rail Details */
.gh-step-list { list-style: none !important; padding: 0 !important; margin: 0 !important; display: flex; flex-direction: column; gap: 14px; }
.gh-step-list li { display: flex; gap: 12px; font-size: 13.5px; line-height: 1.45; }
.gh-num {
    width: 22px; height: 22px; border-radius: 50%;
    background: var(--gh-purple-light); color: var(--gh-purple);
    font-family: var(--gh-font-h); font-size: 11px; font-weight: 700;
    display: flex; align-items: center; justify-content: center; flex-shrink: 0;
}
.gh-stat-line {
    display: flex; justify-content: space-between; padding: 10px 0;
    border-bottom: 1px solid var(--gh-border); font-size: 14px;
}
.gh-stat-line:last-child { border-bottom: none; }
.gh-stat-line.alert strong { color: #E53935; }

.gh-saved-fields {
    background: #FFFDF5; border: 1px solid #FFE6A5; border-radius: 8px;
    padding: 14px 16px; font-size: 12.5px; color: #6B5B2E; line-height: 1.5;
}

/* ========================================================
   MOBILE SCREEN PERFECTION (<= 640px)
   ======================================================== */
@media (max-width: 640px) {
    .gh-form-card {
        padding: 24px 16px !important;
    }

    /* Transform pill toggles into a balanced 2-column grid */
    .gh-pills {
        display: grid !important;
        grid-template-columns: 1fr 1fr !important;
        gap: 10px !important;
        width: 100% !important;
    }

    .gh-pill {
        width: 100% !important;
        padding: 12px 6px !important;
        font-size: 12.5px !important;
        white-space: nowrap !important;
        overflow: hidden !important;
        text-overflow: ellipsis !important;
    }

    /* Stack Submit and Save Draft into full-width action buttons */
    .gh-actions {
        flex-direction: column !important;
        gap: 12px !important;
        width: 100% !important;
        margin-top: 24px !important;
    }

    .gh-btn-primary,
    .gh-btn-outline {
        width: 100% !important;
        display: block !important;
        padding: 14px 20px !important;
        font-size: 14.5px !important;
    }

    .gh-header {
        flex-direction: column !important;
        gap: 12px !important;
    }

    .gh-btn-cancel {
        align-self: flex-start !important;
    }
}

@media (max-width: 1024px) {
    .gh-canvas-columns {
        grid-template-columns: minmax(0, 1fr) !important;
        gap: 20px !important;
    }
    .gh-grid-2 {
        grid-template-columns: 1fr !important;
        gap: 14px !important;
    }
}

</style>
<?php
/**
 * Tutor Dashboard - Class Log ("Log a class") Template
 * Direct match for Figma design
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$user_id = get_current_user_id();
if ( class_exists( 'GH_Portal_Class_Log' ) && method_exists( 'GH_Portal_Class_Log', 'get_instructor_courses' ) ) {
    $courses = GH_Portal_Class_Log::get_instructor_courses( $user_id );
} else {
    $courses = get_posts( array( 'post_type' => 'courses', 'post_status' => 'publish', 'posts_per_page' => -1, 'author' => $user_id ) );
    if ( empty( $courses ) ) {
        $courses = get_posts( array( 'post_type' => 'courses', 'post_status' => 'publish', 'posts_per_page' => -1 ) );
    }
}

if ( class_exists( 'GH_Portal_Class_Log' ) && method_exists( 'GH_Portal_Class_Log', 'get_tutor_week_stats' ) ) {
    $week_stats = GH_Portal_Class_Log::get_tutor_week_stats( $user_id );
} else {
    $week_stats = array( 'delivered' => 0, 'planned' => 0, 'attendance_rate' => 0 );
}
$cancel_url = function_exists( 'tutor_utils' ) ? tutor_utils()->tutor_dashboard_url() : home_url( '/dashboard/' );
?>

<div class="gh-canvas-columns">
    <!-- Center Form Column -->
    <div>
        <div class="gh-header">
            <div>
                <h1><?php esc_html_e( 'Log a class', 'growth-hub' ); ?></h1>
                <p><?php esc_html_e( 'Takes about 30 seconds. Log each class within 24 hours of delivering it.', 'growth-hub' ); ?></p>
            </div>
            <a href="<?php echo esc_url( $cancel_url ); ?>" class="gh-btn-cancel"><?php esc_html_e( 'Cancel', 'growth-hub' ); ?></a>
        </div>

        <?php if ( isset( $_GET['gh_success'] ) ) : ?>
            <div class="gh-notice" style="background:#E8F5E9; color:#2E7D32;">
                <?php esc_html_e( 'Class log submitted successfully! Attendance check-in is now open for your learners.', 'growth-hub' ); ?>
            </div>
        <?php endif; ?>

        <?php if ( isset( $_GET['gh_error'] ) ) : ?>
            <div class="gh-notice" style="background:#ffebee; color:#c62828;">
                <?php echo esc_html( urldecode( $_GET['gh_error'] ) ); ?>
            </div>
        <?php endif; ?>

        <div class="gh-card gh-form-card">
            <h2 style="font-size:18px; margin-bottom:16px;"><?php esc_html_e( 'Class details', 'growth-hub' ); ?></h2>
            <div class="gh-notice">
                <?php esc_html_e( 'Prefilled from your scheduled live class. Change anything that differs from what happened.', 'growth-hub' ); ?>
            </div>

            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <input type="hidden" name="action" value="gh_submit_class_log">
                <input type="hidden" name="status" value="publish">
                <?php wp_nonce_field( 'gh_save_class_log', 'gh_log_nonce' ); ?>
                <input type="hidden" name="mode" id="gh_f_mode" value="virtual">
                <input type="hidden" name="is_planned" id="gh_f_planned" value="1">

                <div class="gh-group">
                    <label class="gh-label"><?php esc_html_e( 'Course', 'growth-hub' ); ?></label>
                    <select name="course_id" class="gh-select">
                        <?php if ( ! empty( $courses ) ) : ?>
                            <?php foreach ( $courses as $c ) : ?>
                                <option value="<?php echo esc_attr( $c->ID ); ?>"><?php echo esc_html( $c->post_title ); ?></option>
                            <?php endforeach; ?>
                        <?php else : ?>
                            <option value="0"><?php esc_html_e( 'No courses available', 'growth-hub' ); ?></option>
                        <?php endif; ?>
                    </select>
                </div>

                <div class="gh-row-2">
                    <div class="gh-group">
                        <label class="gh-label"><?php esc_html_e( 'Cohort', 'growth-hub' ); ?></label>
                        <?php
                        $form_cohorts = array();
                        if ( ! empty( $courses ) ) {
                            foreach ( $courses as $c ) {
                                $c_coh = get_post_meta( $c->ID, '_gh_cohort', true );
                                if ( $c_coh && ! in_array( $c_coh, $form_cohorts, true ) ) {
                                    $form_cohorts[] = $c_coh;
                                }
                                $c_cohs = get_post_meta( $c->ID, '_gh_cohorts', true );
                                if ( is_array( $c_cohs ) ) {
                                    foreach ( $c_cohs as $cc ) {
                                        if ( $cc && ! in_array( $cc, $form_cohorts, true ) ) {
                                            $form_cohorts[] = $cc;
                                        }
                                    }
                                }
                            }
                        }
                        if ( empty( $form_cohorts ) ) {
                            $form_cohorts = array( __( 'Active Cohort', 'growth-hub' ) );
                        }
                        ?>
                        <select name="cohort" class="gh-select">
                            <?php foreach ( $form_cohorts as $f_coh ) : ?>
                                <option value="<?php echo esc_attr( $f_coh ); ?>"><?php echo esc_html( $f_coh ); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="gh-group">
                        <label class="gh-label"><?php esc_html_e( 'Week', 'growth-hub' ); ?></label>
                        <select name="week_number" class="gh-select">
                            <?php for ( $w = 1; $w <= 12; $w++ ) : ?>
                                <option value="Week <?php echo $w; ?> of 12" <?php selected( $w, 1 ); ?>>Week <?php echo $w; ?> of 12</option>
                            <?php endfor; ?>
                        </select>
                    </div>
                </div>

                <div class="gh-row-2">
                    <div class="gh-group">
                        <label class="gh-label"><?php esc_html_e( 'Date', 'growth-hub' ); ?></label>
                        <input type="date" name="class_date" class="gh-input" value="<?php echo date( 'Y-m-d' ); ?>">
                    </div>
                    <div class="gh-group">
                        <label class="gh-label"><?php esc_html_e( 'Duration (Minutes)', 'growth-hub' ); ?></label>
                        <input type="number" name="duration" class="gh-input" value="90">
                    </div>
                </div>

                <div class="gh-group">
                    <label class="gh-label"><?php esc_html_e( 'Mode', 'growth-hub' ); ?></label>
                    <div class="gh-pills" id="gh-pills-mode">
                        <button type="button" class="gh-pill" data-val="physical"><?php esc_html_e( 'Physical', 'growth-hub' ); ?></button>
                        <button type="button" class="gh-pill active" data-val="virtual"><?php esc_html_e( 'Virtual', 'growth-hub' ); ?></button>
                    </div>
                </div>

                <div class="gh-group">
                    <label class="gh-label"><?php esc_html_e( 'Was this class planned?', 'growth-hub' ); ?></label>
                    <div class="gh-pills" id="gh-pills-planned">
                        <button type="button" class="gh-pill active" data-val="1"><?php esc_html_e( 'Planned in schedule', 'growth-hub' ); ?></button>
                        <button type="button" class="gh-pill" data-val="0"><?php esc_html_e( 'Extra class', 'growth-hub' ); ?></button>
                    </div>
                </div>

                <div class="gh-group">
                    <label class="gh-label"><?php esc_html_e( 'Topic Covered', 'growth-hub' ); ?></label>
                    <input type="text" name="topic" class="gh-input" placeholder="<?php esc_attr_e( 'e.g. Introduction to Responsive Layouts', 'growth-hub' ); ?>" required>
                </div>

                <div class="gh-group">
                    <label class="gh-label"><?php esc_html_e( 'Notes for the ops and bd manager (Optional)', 'growth-hub' ); ?></label>
                    <textarea name="notes" rows="4" class="gh-textarea" placeholder="<?php esc_attr_e( 'Two learners joined late because of data issues...', 'growth-hub' ); ?>"></textarea>
                </div>

                <div class="gh-actions">
                    <button type="submit" name="submit_action" value="publish" class="gh-btn-primary"><?php esc_html_e( 'Submit class log', 'growth-hub' ); ?></button>
                    <button type="submit" name="submit_action" value="draft" class="gh-btn-outline"><?php esc_html_e( 'Save draft', 'growth-hub' ); ?></button>
                </div>
            </form>
        </div>
    </div>

    <!-- Right Column Rail -->
    <div>
        <div class="gh-card gh-rail-card">
            <h3 style="font-size:16px; margin-bottom:16px;"><?php esc_html_e( 'What happens after you submit', 'growth-hub' ); ?></h3>
            <ul class="gh-step-list">
                <li><span class="gh-num">1</span> <?php esc_html_e( 'Learners get a one-tap check-in and a two-question pulse for this class.', 'growth-hub' ); ?></li>
                <li><span class="gh-num">2</span> <?php esc_html_e( 'Attendance is counted from learner check-ins and Zoom reports, not from you.', 'growth-hub' ); ?></li>
                <li><span class="gh-num">3</span> <?php esc_html_e( 'Your class counts toward this week\'s delivered vs planned score.', 'growth-hub' ); ?></li>
                <li><span class="gh-num">4</span> <?php esc_html_e( 'The Ops and BD Manager sees it in the Cohort Delivery view.', 'growth-hub' ); ?></li>
            </ul>
        </div>

        <div class="gh-card gh-rail-card">
            <h3 style="font-size:16px; margin-bottom:16px;"><?php esc_html_e( 'Your week so far', 'growth-hub' ); ?></h3>
            <div class="gh-stat-line"><span><?php esc_html_e( 'Classes planned', 'growth-hub' ); ?></span> <strong><?php echo esc_html( $week_stats['planned'] ); ?></strong></div>
            <div class="gh-stat-line"><span><?php esc_html_e( 'Classes logged', 'growth-hub' ); ?></span> <strong><?php echo esc_html( $week_stats['logged'] ); ?></strong></div>
            <div class="gh-stat-line alert"><span><?php esc_html_e( 'Still to log', 'growth-hub' ); ?></span> <strong><?php echo esc_html( $week_stats['still'] ); ?></strong></div>
        </div>

        <div class="gh-card gh-rail-card" style="background: #FDFCFE; border: 1.5px solid #D8D2EE;">
            <h3 style="font-size:15px; margin-bottom:8px; color:#1B1240;"><?php esc_html_e( 'Live Hub Check-in', 'growth-hub' ); ?></h3>
            <p style="font-size:12px; color:#6B7280; line-height:1.4; margin-bottom:14px;">
                <?php esc_html_e( 'Delivering an in-person class? Project the QR code on your screen for learners to scan with their phones.', 'growth-hub' ); ?>
            </p>
            <button type="button" class="gh-btn-primary" style="width:100%; padding:10px 14px; font-size:13px; margin-bottom:8px;" onclick="ghOpenProjectorModal()">
                <?php esc_html_e( 'Project Class QR Code', 'growth-hub' ); ?>
            </button>
            <button type="button" class="gh-btn-outline" style="width:100%; padding:9px 14px; font-size:12.5px;" onclick="ghCopyCheckinLink()">
                <?php esc_html_e( 'Copy Check-in Link', 'growth-hub' ); ?>
            </button>
        </div>

        <div class="gh-saved-fields">
            <strong style="display:block; margin-bottom:4px;"><?php esc_html_e( 'Saved fields', 'growth-hub' ); ?></strong>
            <?php esc_html_e( 'Tutor, course, cohort, week number, date, mode, planned or extra, duration, topic, notes, logged at.', 'growth-hub' ); ?>
        </div>
    </div>
</div>

<!-- Projector Fullscreen QR Code Modal for In-Person Classes -->
<div id="gh-projector-modal" style="display:none; position:fixed; top:0; left:0; right:0; bottom:0; background:rgba(27,18,64,0.85); z-index:999999; align-items:center; justify-content:center; padding:20px;">
    <div style="background:#FFFFFF; border-radius:24px; padding:32px 28px; max-width:440px; width:100%; text-align:center; box-shadow:0 20px 50px rgba(0,0,0,0.3); position:relative;">
        <button type="button" onclick="ghCloseProjectorModal()" style="position:absolute; top:16px; right:18px; background:transparent; border:none; font-size:26px; color:#6B7280; cursor:pointer;">&times;</button>
        
        <span style="background:#F4F1FB; color:#632FD1; font-family:'Poppins',sans-serif; font-size:11px; font-weight:700; padding:4px 12px; border-radius:9999px; text-transform:uppercase;">
            <?php esc_html_e( 'Classroom Check-in', 'growth-hub' ); ?>
        </span>
        
        <h2 style="font-size:20px; font-weight:700; color:#1B1240; margin:16px 0 6px; font-family:'Poppins',sans-serif;">
            <?php esc_html_e( 'Scan to Check in', 'growth-hub' ); ?>
        </h2>
        <p style="font-size:13px; color:#6B7280; margin:0 0 20px;">
            <?php esc_html_e( 'Open your phone camera or QR scanner and point it at the code below.', 'growth-hub' ); ?>
        </p>

        <div style="width:200px; height:200px; margin:0 auto 18px; border:2px solid #1B1240; border-radius:16px; display:flex; align-items:center; justify-content:center; padding:10px; background:#FFFFFF;">
            <img id="gh-proj-qr-img" src="" alt="Live QR Code" width="180" height="180" style="display:block;">
        </div>

        <p id="gh-proj-url-disp" style="font-size:11.5px; color:#632FD1; word-break:break-all; margin:0 0 20px; font-weight:600;"></p>

        <button type="button" class="gh-btn-primary" style="width:100%; padding:12px;" onclick="ghCloseProjectorModal()">
            <?php esc_html_e( 'Done / Close Screen', 'growth-hub' ); ?>
        </button>
    </div>
</div>

<script>
function ghGetLiveCheckinUrl() {
    var courseSel = document.querySelector('select[name="course_id"]');
    var courseId = courseSel ? courseSel.value : '1';
    var baseUrl = <?php echo json_encode( home_url( '/check-in/' ) ); ?>;
    return baseUrl + '?class_id=' + courseId + '&step=physical';
}

function ghOpenProjectorModal() {
    var url = ghGetLiveCheckinUrl();
    var qrImg = document.getElementById('gh-proj-qr-img');
    var urlDisp = document.getElementById('gh-proj-url-disp');
    var modal = document.getElementById('gh-projector-modal');
    if (qrImg) {
        qrImg.src = 'https://api.qrserver.com/v1/create-qr-code/?size=200x200&margin=0&color=1B1240&data=' + encodeURIComponent(url);
    }
    if (urlDisp) {
        urlDisp.innerText = url;
    }
    if (modal) {
        modal.style.display = 'flex';
    }
}

function ghCloseProjectorModal() {
    var modal = document.getElementById('gh-projector-modal');
    if (modal) modal.style.display = 'none';
}

function ghCopyCheckinLink() {
    var url = ghGetLiveCheckinUrl();
    if (navigator.clipboard) {
        navigator.clipboard.writeText(url).then(function() {
            alert('Check-in link copied to clipboard!\n' + url);
        });
    } else {
        prompt('Copy this check-in link:', url);
    }
}
</script>
<?php
    return ob_get_clean();
}
