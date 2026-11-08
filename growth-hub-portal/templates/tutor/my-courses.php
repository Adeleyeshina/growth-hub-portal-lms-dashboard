<?php
/**
 * Tutor LMS - Mentor My Courses Template
 *
 * Growth Hub Portal - Instructor & Admin Course Roster & Management
 * Provides strict role-scoped course management, quick analytics,
 * and direct one-click integration into the Tutor LMS Frontend Course Builder.
 *
 * @package GrowthHubPortal
 * @author Adeleye Shina
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

global $wpdb;

$user_id   = get_current_user_id();
$is_admin  = current_user_can( 'administrator' );
$is_mentor = current_user_can( 'tutor_instructor' ) || $is_admin;

if ( ! $user_id || ! $is_mentor ) {
    ?>
    <div class="gh-section-card" style="padding: 40px; text-align: center;">
        <h3 style="color: #1B1240; font-family: 'Poppins', sans-serif;"><?php esc_html_e( 'Access Denied', 'growth-hub' ); ?></h3>
        <p style="color: #6B7280; font-family: 'Lato', sans-serif;"><?php esc_html_e( 'You must be logged in as an instructor or administrator to access course management.', 'growth-hub' ); ?></p>
    </div>
    <?php
    return;
}

$dash_url = function_exists( 'gh_portal_dashboard_url' ) ? gh_portal_dashboard_url() : ( function_exists( 'tutor_utils' ) ? tutor_utils()->tutor_dashboard_url() : home_url( '/dashboard/' ) );

// 1. Determine Tutor LMS Course Builder URLs
// We link directly to the native WordPress Tutor LMS course editor, where Tutor LMS embeds its full visual
// drag-and-drop course builder (curriculum, topics, lessons, quizzes, assignments, pricing, certificates).
// This guarantees zero blank screens, zero 404s, and works 100% reliably for both Admins and Instructors.
$create_course_url = admin_url( 'post-new.php?post_type=courses' );

// 2. Strict Role-Scoped Course Query
// Admin sees all courses; Tutor strictly sees authored or assigned courses
$selected_instructor = isset( $_GET['instructor_filter'] ) ? absint( $_GET['instructor_filter'] ) : 0;
$courses_raw = array();
$merged_map  = array();

$statuses = array( 'publish', 'draft', 'pending', 'private' );

if ( $is_admin && ! $selected_instructor ) {
    // Admin View: All academy courses
    $courses_raw = get_posts( array(
        'post_type'      => 'courses',
        'post_status'    => $statuses,
        'posts_per_page' => -1,
        'orderby'        => 'date',
        'order'          => 'DESC',
    ) );
    foreach ( $courses_raw as $c ) {
        $merged_map[ $c->ID ] = $c;
    }
} else {
    $target_tutor_id = ( $is_admin && $selected_instructor ) ? $selected_instructor : $user_id;

    // Method A: Tutor LMS native API
    if ( function_exists( 'tutor_utils' ) && method_exists( tutor_utils(), 'get_courses_by_instructor' ) ) {
        $native_courses = tutor_utils()->get_courses_by_instructor( $target_tutor_id, $statuses );
        if ( ! empty( $native_courses ) && is_array( $native_courses ) ) {
            foreach ( $native_courses as $nc ) {
                $nc_obj = is_object( $nc ) ? $nc : get_post( $nc );
                if ( $nc_obj && in_array( $nc_obj->post_status, $statuses, true ) ) {
                    $merged_map[ $nc_obj->ID ] = $nc_obj;
                }
            }
        }
    }

    // Method B: Direct author check
    $authored = get_posts( array(
        'post_type'      => 'courses',
        'post_status'    => $statuses,
        'posts_per_page' => -1,
        'author'         => $target_tutor_id,
        'orderby'        => 'date',
        'order'          => 'DESC',
    ) );
    if ( ! empty( $authored ) ) {
        foreach ( $authored as $c ) {
            $merged_map[ $c->ID ] = $c;
        }
    }

    // Method C: Multi-instructor meta check (_tutor_instructor_course)
    $assigned = get_posts( array(
        'post_type'      => 'courses',
        'post_status'    => $statuses,
        'posts_per_page' => -1,
        'meta_query'     => array(
            array(
                'key'     => '_tutor_instructor_course',
                'value'   => $target_tutor_id,
                'compare' => '=',
            ),
        ),
        'orderby'        => 'date',
        'order'          => 'DESC',
    ) );
    if ( ! empty( $assigned ) ) {
        foreach ( $assigned as $c ) {
            $merged_map[ $c->ID ] = $c;
        }
    }
}

$courses_list = array_values( $merged_map );

// 3. Process Course Data & Metrics
$processed_courses = array();
$count_all       = count( $courses_list );
$count_published = 0;
$count_draft     = 0;
$count_pending   = 0;
$total_students  = 0;
$all_categories  = array();

// Prepare list of instructors for admin filter
$instructors_list = array();
if ( $is_admin ) {
    $instructors_list = get_users( array(
        'role__in' => array( 'tutor_instructor', 'administrator' ),
        'orderby'  => 'display_name',
        'order'    => 'ASC',
    ) );
}

foreach ( $courses_list as $course ) {
    $c_id = (int) $course->ID;
    $status = $course->post_status;

    if ( 'publish' === $status ) {
        $count_published++;
    } elseif ( 'draft' === $status ) {
        $count_draft++;
    } elseif ( 'pending' === $status ) {
        $count_pending++;
    }

    // Enrolled count
    $enrolled_count = 0;
    if ( function_exists( 'tutor_utils' ) ) {
        $enrolled_count = (int) tutor_utils()->count_enrolled_users_by_course( $c_id );
    } else {
        $enrolled_count = (int) $wpdb->get_var( $wpdb->prepare( "
            SELECT COUNT(ID) FROM {$wpdb->posts}
            WHERE post_type = 'tutor_enrolled'
              AND post_status = 'completed'
              AND post_parent = %d
        ", $c_id ) );
    }
    $total_students += $enrolled_count;

    // Lessons count
    $lessons_count = (int) $wpdb->get_var( $wpdb->prepare( "
        SELECT COUNT(p.ID) FROM {$wpdb->posts} p
        INNER JOIN {$wpdb->posts} t ON p.post_parent = t.ID
        WHERE p.post_type = 'lesson'
          AND t.post_type = 'topics'
          AND t.post_parent = %d
    ", $c_id ) );

    // Quizzes count
    $quizzes_count = (int) $wpdb->get_var( $wpdb->prepare( "
        SELECT COUNT(p.ID) FROM {$wpdb->posts} p
        INNER JOIN {$wpdb->posts} t ON p.post_parent = t.ID
        WHERE p.post_type = 'tutor_quiz'
          AND t.post_type = 'topics'
          AND t.post_parent = %d
    ", $c_id ) );

    // Assignments count
    $assignments_count = (int) $wpdb->get_var( $wpdb->prepare( "
        SELECT COUNT(p.ID) FROM {$wpdb->posts} p
        INNER JOIN {$wpdb->posts} t ON p.post_parent = t.ID
        WHERE p.post_type = 'tutor_assignments'
          AND t.post_type = 'topics'
          AND t.post_parent = %d
    ", $c_id ) );

    // Categories / Tracks
    $cat_terms = wp_get_post_terms( $c_id, 'course-category', array( 'fields' => 'names' ) );
    $category_label = ! empty( $cat_terms ) && ! is_wp_error( $cat_terms ) ? $cat_terms[0] : __( 'General Track', 'growth-hub' );
    if ( ! empty( $category_label ) && ! in_array( $category_label, $all_categories, true ) ) {
        $all_categories[] = $category_label;
    }

    // Thumbnail
    $thumb_url = get_the_post_thumbnail_url( $c_id, 'medium_large' );

    // Instructor info
    $author_id = (int) $course->post_author;
    $author_obj = get_userdata( $author_id );
    $author_name = $author_obj ? $author_obj->display_name : __( 'Mentor', 'growth-hub' );

    // Level & Duration
    $course_level = get_post_meta( $c_id, '_tutor_course_level', true );
    $level_label = ! empty( $course_level ) ? ucfirst( $course_level ) : __( 'All Levels', 'growth-hub' );

    // Direct Edit Link in Tutor Course Builder (always opens full visual editor in WP Admin)
    $builder_edit_url = admin_url( 'post.php?post=' . $c_id . '&action=edit' );

    // Mentees Link
    $mentees_url = function_exists( 'gh_portal_dashboard_url' ) ? gh_portal_dashboard_url( 'mentees', array( 'course_id' => $c_id ) ) : add_query_arg( array( 'tab' => 'mentees', 'course_id' => $c_id ), $dash_url );

    $processed_courses[] = array(
        'id'               => $c_id,
        'title'            => $course->post_title,
        'status'           => $status,
        'date'             => get_the_date( 'M j, Y', $c_id ),
        'thumb_url'        => $thumb_url,
        'category'         => $category_label,
        'enrolled_count'   => $enrolled_count,
        'lessons_count'    => $lessons_count,
        'quizzes_count'    => $quizzes_count,
        'assignments_count'=> $assignments_count,
        'level'            => $level_label,
        'author_id'        => $author_id,
        'author_name'      => $author_name,
        'permalink'        => get_permalink( $c_id ),
        'builder_edit_url' => $builder_edit_url,
        'mentees_url'      => $mentees_url,
    );
}
?>

<div class="gh-courses-container">

        <!-- Top Header & Action Row -->
        <div class="gh-courses-header">
            <div>
                <h1 class="gh-courses-title"><?php esc_html_e( 'My Courses', 'growth-hub' ); ?></h1>
                <p class="gh-courses-subtitle">
                    <?php if ( $is_admin ) : ?>
                        <?php esc_html_e( 'Manage academy courses, monitor enrollment metrics, and launch the course builder.', 'growth-hub' ); ?>
                    <?php else : ?>
                        <?php esc_html_e( 'Manage your courses, view enrolled mentees, track curriculum, and update content.', 'growth-hub' ); ?>
                    <?php endif; ?>
                </p>
            </div>
            <div class="gh-courses-header-actions">
                <a href="<?php echo esc_url( $create_course_url ); ?>" class="gh-btn-create-course" target="_blank" rel="noopener">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                        <line x1="12" y1="5" x2="12" y2="19"></line>
                        <line x1="5" y1="12" x2="19" y2="12"></line>
                    </svg>
                    <span><?php esc_html_e( 'Create New Course', 'growth-hub' ); ?></span>
                </a>
            </div>
        </div>

        <!-- Metric KPI Cards -->
        <div class="gh-course-metrics-grid">
            <div class="gh-metric-tile">
                <div class="gh-metric-icon-wrap" style="background: rgba(99, 47, 209, 0.1); color: #632FD1;">
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"></path>
                        <path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"></path>
                    </svg>
                </div>
                <div class="gh-metric-info">
                    <span class="gh-metric-label"><?php esc_html_e( 'Total Courses', 'growth-hub' ); ?></span>
                    <span class="gh-metric-value" id="gh-metric-total"><?php echo esc_html( $count_all ); ?></span>
                </div>
            </div>

            <div class="gh-metric-tile">
                <div class="gh-metric-icon-wrap" style="background: rgba(16, 185, 129, 0.1); color: #059669;">
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <polyline points="20 6 9 17 4 12"></polyline>
                    </svg>
                </div>
                <div class="gh-metric-info">
                    <span class="gh-metric-label"><?php esc_html_e( 'Published / Live', 'growth-hub' ); ?></span>
                    <span class="gh-metric-value" id="gh-metric-published"><?php echo esc_html( $count_published ); ?></span>
                </div>
            </div>

            <div class="gh-metric-tile">
                <div class="gh-metric-icon-wrap" style="background: rgba(245, 158, 11, 0.1); color: #D97706;">
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M12 20h9"></path>
                        <path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z"></path>
                    </svg>
                </div>
                <div class="gh-metric-info">
                    <span class="gh-metric-label"><?php esc_html_e( 'Drafts & Pending', 'growth-hub' ); ?></span>
                    <span class="gh-metric-value" id="gh-metric-drafts"><?php echo esc_html( $count_draft + $count_pending ); ?></span>
                </div>
            </div>

            <div class="gh-metric-tile">
                <div class="gh-metric-icon-wrap" style="background: rgba(79, 70, 229, 0.1); color: #4F46E5;">
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path>
                        <circle cx="9" cy="7" r="4"></circle>
                        <path d="M23 21v-2a4 4 0 0 0-3-3.87"></path>
                        <path d="M16 3.13a4 4 0 0 1 0 7.75"></path>
                    </svg>
                </div>
                <div class="gh-metric-info">
                    <span class="gh-metric-label"><?php esc_html_e( 'Total Enrolled Learners', 'growth-hub' ); ?></span>
                    <span class="gh-metric-value" id="gh-metric-students"><?php echo esc_html( number_format( $total_students ) ); ?></span>
                </div>
            </div>
        </div>

        <!-- Filter & Search Toolbar -->
        <div class="gh-course-toolbar">
            <div class="gh-course-tabs">
                <button type="button" class="gh-tab-btn active" data-filter="all">
                    <?php esc_html_e( 'All Courses', 'growth-hub' ); ?> <span class="gh-tab-pill"><?php echo esc_html( $count_all ); ?></span>
                </button>
                <button type="button" class="gh-tab-btn" data-filter="publish">
                    <?php esc_html_e( 'Published', 'growth-hub' ); ?> <span class="gh-tab-pill"><?php echo esc_html( $count_published ); ?></span>
                </button>
                <button type="button" class="gh-tab-btn" data-filter="draft">
                    <?php esc_html_e( 'Drafts', 'growth-hub' ); ?> <span class="gh-tab-pill"><?php echo esc_html( $count_draft ); ?></span>
                </button>
                <button type="button" class="gh-tab-btn" data-filter="pending">
                    <?php esc_html_e( 'Pending Review', 'growth-hub' ); ?> <span class="gh-tab-pill"><?php echo esc_html( $count_pending ); ?></span>
                </button>
            </div>

            <div class="gh-course-search-filters">
                <!-- Search Input -->
                <div class="gh-search-input-box">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#6B7280" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <circle cx="11" cy="11" r="8"></circle>
                        <line x1="21" y1="21" x2="16.65" y2="16.65"></line>
                    </svg>
                    <input type="text" id="gh-course-search-input" placeholder="<?php esc_attr_e( 'Search courses by title or track...', 'growth-hub' ); ?>" />
                </div>

                <!-- Track / Category Filter -->
                <?php if ( ! empty( $all_categories ) ) : ?>
                    <div class="gh-select-wrap">
                        <select id="gh-course-cat-filter" class="gh-custom-select" aria-label="<?php esc_attr_e( 'Filter by Category', 'growth-hub' ); ?>">
                            <option value="all"><?php esc_html_e( 'All Categories', 'growth-hub' ); ?></option>
                            <?php foreach ( $all_categories as $cat ) : ?>
                                <option value="<?php echo esc_attr( strtolower( $cat ) ); ?>"><?php echo esc_html( $cat ); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                <?php endif; ?>

                <!-- Admin Instructor Filter (Admin Only) -->
                <?php if ( $is_admin && ! empty( $instructors_list ) ) : ?>
                    <div class="gh-select-wrap">
                        <select id="gh-course-instructor-filter" class="gh-custom-select" aria-label="<?php esc_attr_e( 'Filter by Instructor', 'growth-hub' ); ?>">
                            <option value="all"><?php esc_html_e( 'All Instructors', 'growth-hub' ); ?></option>
                            <?php foreach ( $instructors_list as $inst ) : ?>
                                <option value="<?php echo esc_attr( $inst->ID ); ?>" <?php selected( $selected_instructor, $inst->ID ); ?>>
                                    <?php echo esc_html( $inst->display_name ); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Course Cards Grid -->
        <div class="gh-courses-grid" id="gh-courses-grid">
            <?php if ( empty( $processed_courses ) ) : ?>
                <div class="gh-empty-courses-state" id="gh-empty-message">
                    <div class="gh-empty-icon">
                        <svg width="44" height="44" viewBox="0 0 24 24" fill="none" stroke="#632FD1" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"></path>
                            <path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"></path>
                        </svg>
                    </div>
                    <h3><?php esc_html_e( 'No Courses Found', 'growth-hub' ); ?></h3>
                    <p><?php esc_html_e( 'You do not have any courses created or assigned yet. Launch the Tutor LMS builder to get started.', 'growth-hub' ); ?></p>
                    <a href="<?php echo esc_url( $create_course_url ); ?>" class="gh-btn-create-course" target="_blank" rel="noopener">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                            <line x1="12" y1="5" x2="12" y2="19"></line>
                            <line x1="5" y1="12" x2="19" y2="12"></line>
                        </svg>
                        <span><?php esc_html_e( 'Create Your First Course', 'growth-hub' ); ?></span>
                    </a>
                </div>
            <?php else : ?>
                <?php foreach ( $processed_courses as $item ) : 
                    $status_key = $item['status'];
                    $badge_class = 'status-published';
                    $badge_label = __( 'Published', 'growth-hub' );
                    if ( 'draft' === $status_key ) {
                        $badge_class = 'status-draft';
                        $badge_label = __( 'Draft', 'growth-hub' );
                    } elseif ( 'pending' === $status_key ) {
                        $badge_class = 'status-pending';
                        $badge_label = __( 'Pending Review', 'growth-hub' );
                    } elseif ( 'private' === $status_key ) {
                        $badge_class = 'status-private';
                        $badge_label = __( 'Private', 'growth-hub' );
                    }
                ?>
                    <div class="gh-course-card" 
                         data-status="<?php echo esc_attr( $status_key ); ?>" 
                         data-category="<?php echo esc_attr( strtolower( $item['category'] ) ); ?>" 
                         data-instructor="<?php echo esc_attr( $item['author_id'] ); ?>" 
                         data-title="<?php echo esc_attr( strtolower( $item['title'] ) ); ?>">
                        
                        <!-- Thumbnail / Media -->
                        <div class="gh-course-media">
                            <?php if ( ! empty( $item['thumb_url'] ) ) : ?>
                                <img src="<?php echo esc_url( $item['thumb_url'] ); ?>" alt="<?php echo esc_attr( $item['title'] ); ?>" class="gh-course-thumb" loading="lazy" />
                            <?php else : ?>
                                <div class="gh-course-thumb-placeholder">
                                    <svg width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="#632FD1" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                                        <polygon points="12 2 2 7 12 12 22 7 12 2"></polygon>
                                        <polyline points="2 17 12 22 22 17"></polyline>
                                        <polyline points="2 12 12 17 22 12"></polyline>
                                    </svg>
                                </div>
                            <?php endif; ?>

                            <!-- Badges Overlay -->
                            <div class="gh-media-badges">
                                <span class="gh-status-pill <?php echo esc_attr( $badge_class ); ?>">
                                    <span class="gh-pill-dot"></span>
                                    <?php echo esc_html( $badge_label ); ?>
                                </span>
                                <span class="gh-category-pill"><?php echo esc_html( $item['category'] ); ?></span>
                            </div>
                        </div>

                        <!-- Card Body -->
                        <div class="gh-course-body">
                            <h3 class="gh-course-name" title="<?php echo esc_attr( $item['title'] ); ?>">
                                <?php echo esc_html( $item['title'] ); ?>
                            </h3>

                            <?php if ( $is_admin ) : ?>
                                <div class="gh-instructor-badge">
                                    <div class="gh-instructor-avatar">
                                        <?php echo esc_html( strtoupper( substr( $item['author_name'], 0, 1 ) ) ); ?>
                                    </div>
                                    <span class="gh-instructor-name"><?php echo esc_html( $item['author_name'] ); ?></span>
                                </div>
                            <?php endif; ?>

                            <!-- Meta Metrics Bar -->
                            <div class="gh-course-meta-row">
                                <div class="gh-meta-item">
                                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="#632FD1" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                        <path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"></path>
                                        <path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"></path>
                                    </svg>
                                    <span><?php echo esc_html( sprintf( _n( '%d Lesson', '%d Lessons', $item['lessons_count'], 'growth-hub' ), $item['lessons_count'] ) ); ?></span>
                                </div>

                                <div class="gh-meta-item">
                                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="#10B981" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                        <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path>
                                        <circle cx="9" cy="7" r="4"></circle>
                                    </svg>
                                    <span><?php echo esc_html( sprintf( _n( '%d Learner', '%d Learners', $item['enrolled_count'], 'growth-hub' ), $item['enrolled_count'] ) ); ?></span>
                                </div>

                                <div class="gh-meta-item">
                                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="#6B7280" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                        <polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"></polygon>
                                    </svg>
                                    <span><?php echo esc_html( $item['level'] ); ?></span>
                                </div>
                            </div>
                        </div>

                        <!-- Card Footer Action Buttons -->
                        <div class="gh-course-footer">
                            <a href="<?php echo esc_url( $item['builder_edit_url'] ); ?>" class="gh-btn-action-edit" target="_blank" rel="noopener">
                                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path>
                                    <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path>
                                </svg>
                                <span><?php esc_html_e( 'Edit Course', 'growth-hub' ); ?></span>
                            </a>

                            <div class="gh-course-quick-links">
                                <a href="<?php echo esc_url( $item['mentees_url'] ); ?>" class="gh-btn-icon-action" title="<?php esc_attr_e( 'View Enrolled Mentees', 'growth-hub' ); ?>">
                                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                        <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path>
                                        <circle cx="9" cy="7" r="4"></circle>
                                    </svg>
                                </a>

                                <a href="<?php echo esc_url( $item['permalink'] ); ?>" class="gh-btn-icon-action" target="_blank" rel="noopener" title="<?php esc_attr_e( 'View Live Course', 'growth-hub' ); ?>">
                                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                        <path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"></path>
                                        <polyline points="15 3 21 3 21 9"></polyline>
                                        <line x1="10" y1="14" x2="21" y2="3"></line>
                                    </svg>
                                </a>
                            </div>
                        </div>

                    </div>
                <?php endforeach; ?>

                <div class="gh-empty-courses-state" id="gh-no-filter-match" style="display: none;">
                    <div class="gh-empty-icon">
                        <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="#6B7280" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">
                            <circle cx="11" cy="11" r="8"></circle>
                            <line x1="21" y1="21" x2="16.65" y2="16.65"></line>
                        </svg>
                    </div>
                    <h3><?php esc_html_e( 'No Matching Courses', 'growth-hub' ); ?></h3>
                    <p><?php esc_html_e( 'No courses match your current search or filter criteria. Try clearing filters.', 'growth-hub' ); ?></p>
                </div>
            <?php endif; ?>
        </div>

    </div>

<style>
/* Growth Hub Courses Master Stylesheet */
.gh-courses-container {
    max-width: 1320px;
    margin: 0 auto;
    padding-bottom: 60px;
    width: 100%;
    box-sizing: border-box;
    font-family: 'Lato', -apple-system, BlinkMacSystemFont, sans-serif !important;
    color: #1B1240 !important;
}

.gh-courses-header {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 20px;
    margin-bottom: 28px;
    flex-wrap: wrap;
}

.gh-courses-title {
    font-family: 'Poppins', sans-serif !important;
    font-size: 28px !important;
    font-weight: 700 !important;
    color: #1B1240 !important;
    margin: 0 0 6px 0 !important;
    line-height: 1.2 !important;
}

.gh-courses-subtitle {
    color: #6B7280 !important;
    font-size: 14.5px !important;
    margin: 0 !important;
    line-height: 1.5 !important;
}

.gh-btn-create-course {
    display: inline-flex !important;
    align-items: center !important;
    justify-content: center !important;
    gap: 8px !important;
    background-color: #632FD1 !important;
    color: #FFFFFF !important;
    padding: 12px 22px !important;
    border-radius: 12px !important;
    font-family: 'Poppins', sans-serif !important;
    font-size: 14px !important;
    font-weight: 600 !important;
    text-decoration: none !important;
    box-shadow: 0 4px 14px rgba(99, 47, 209, 0.25) !important;
    transition: all 0.2s ease !important;
    white-space: nowrap !important;
    flex-shrink: 0 !important;
}

.gh-btn-create-course:hover {
    background-color: #5224B8 !important;
    transform: translateY(-1px) !important;
    box-shadow: 0 6px 18px rgba(99, 47, 209, 0.35) !important;
    color: #FFFFFF !important;
}

/* Metric KPI Cards */
.gh-course-metrics-grid {
    display: grid !important;
    grid-template-columns: repeat(4, 1fr) !important;
    gap: 20px !important;
    margin-bottom: 32px !important;
}

.gh-metric-tile {
    background: #FFFFFF !important;
    border: 1px solid #EBE7F7 !important;
    border-radius: 16px !important;
    padding: 20px 22px !important;
    display: flex !important;
    align-items: center !important;
    gap: 16px !important;
    box-shadow: 0 2px 10px rgba(27, 18, 64, 0.03) !important;
    transition: transform 0.2s ease, box-shadow 0.2s ease !important;
}

.gh-metric-tile:hover {
    transform: translateY(-2px) !important;
    box-shadow: 0 6px 16px rgba(27, 18, 64, 0.06) !important;
}

.gh-metric-icon-wrap {
    width: 48px !important;
    height: 48px !important;
    border-radius: 12px !important;
    display: flex !important;
    align-items: center !important;
    justify-content: center !important;
    flex-shrink: 0 !important;
}

.gh-metric-info {
    display: flex !important;
    flex-direction: column !important;
}

.gh-metric-label {
    font-size: 13px !important;
    color: #6B7280 !important;
    font-weight: 500 !important;
    margin-bottom: 2px !important;
}

.gh-metric-value {
    font-family: 'Poppins', sans-serif !important;
    font-size: 24px !important;
    font-weight: 700 !important;
    color: #1B1240 !important;
    line-height: 1.1 !important;
}

/* Toolbar & Filter Tabs */
.gh-course-toolbar {
    background: #FFFFFF !important;
    border: 1px solid #EBE7F7 !important;
    border-radius: 16px !important;
    padding: 16px 20px !important;
    margin-bottom: 28px !important;
    display: flex !important;
    align-items: center !important;
    justify-content: space-between !important;
    gap: 16px !important;
    flex-wrap: wrap !important;
}

.gh-course-tabs {
    display: flex !important;
    align-items: center !important;
    gap: 8px !important;
    overflow-x: auto !important;
    -webkit-overflow-scrolling: touch !important;
}

.gh-tab-btn {
    border: none !important;
    background: transparent !important;
    padding: 10px 16px !important;
    border-radius: 10px !important;
    font-family: 'Poppins', sans-serif !important;
    font-size: 13.5px !important;
    font-weight: 600 !important;
    color: #6B7280 !important;
    cursor: pointer !important;
    display: inline-flex !important;
    align-items: center !important;
    gap: 8px !important;
    transition: all 0.2s ease !important;
    white-space: nowrap !important;
}

.gh-tab-btn:hover {
    color: #1B1240 !important;
    background: #F4F1FB !important;
}

.gh-tab-btn.active {
    background: #F4F1FB !important;
    color: #632FD1 !important;
}

.gh-tab-pill {
    background: rgba(107, 114, 128, 0.12) !important;
    color: #4B5563 !important;
    padding: 2px 8px !important;
    border-radius: 999px !important;
    font-size: 12px !important;
    font-weight: 700 !important;
    line-height: 1.2 !important;
}

.gh-tab-btn.active .gh-tab-pill {
    background: #632FD1 !important;
    color: #FFFFFF !important;
}

.gh-course-search-filters {
    display: flex !important;
    align-items: center !important;
    gap: 12px !important;
    flex-wrap: wrap !important;
}

.gh-search-input-box {
    display: flex !important;
    align-items: center !important;
    gap: 10px !important;
    background: #F8F7FC !important;
    border: 1px solid #D8D2EE !important;
    border-radius: 12px !important;
    padding: 0 14px !important;
    height: 48px !important;
    min-width: 260px !important;
    transition: border-color 0.2s ease !important;
}

.gh-search-input-box:focus-within {
    border-color: #632FD1 !important;
    background: #FFFFFF !important;
    box-shadow: 0 0 0 3px rgba(99, 47, 209, 0.12) !important;
}

.gh-search-input-box input,
.gh-search-input-box input[type="text"],
.gh-search-input-box input:focus,
.gh-search-input-box input[type="text"]:focus {
    border: none !important;
    background: transparent !important;
    outline: none !important;
    box-shadow: none !important;
    font-family: 'Lato', sans-serif !important;
    font-size: 14px !important;
    color: #1B1240 !important;
    width: 100% !important;
    height: 100% !important;
    min-height: 100% !important;
    padding: 0 !important;
    margin: 0 !important;
}

/* Standardized 48px Select Dropdown */
.gh-custom-select {
    display: block !important;
    width: auto !important;
    min-width: 160px !important;
    height: 48px !important;
    line-height: 46px !important;
    padding: 0 38px 0 14px !important;
    font-family: 'Lato', sans-serif !important;
    font-size: 13.5px !important;
    font-weight: 500 !important;
    color: #1B1240 !important;
    background-color: #F8F7FC !important;
    border: 1px solid #D8D2EE !important;
    border-radius: 12px !important;
    outline: none !important;
    cursor: pointer !important;
    appearance: none !important;
    -webkit-appearance: none !important;
    background-image: url("data:image/svg+xml;charset=UTF-8,%3Csvg width='12' height='8' viewBox='0 0 12 8' fill='none' xmlns='http://www.w3.org/2000/svg'%3E%3Cpath d='M1 1.5L6 6.5L11 1.5' stroke='%23632FD1' stroke-width='1.8' stroke-linecap='round' stroke-linejoin='round'/%3E%3C/svg%3E") !important;
    background-repeat: no-repeat !important;
    background-position: right 14px center !important;
    box-sizing: border-box !important;
    vertical-align: middle !important;
    transition: border-color 0.2s ease !important;
}

.gh-custom-select:focus {
    border-color: #632FD1 !important;
    background-color: #FFFFFF !important;
    box-shadow: 0 0 0 3px rgba(99, 47, 209, 0.12) !important;
}

/* Course Cards Grid */
.gh-courses-grid {
    display: grid !important;
    grid-template-columns: repeat(auto-fill, minmax(340px, 1fr)) !important;
    gap: 24px !important;
}

.gh-course-card {
    background: #FFFFFF !important;
    border: 1px solid #EBE7F7 !important;
    border-radius: 18px !important;
    overflow: hidden !important;
    display: flex !important;
    flex-direction: column !important;
    box-shadow: 0 4px 16px rgba(27, 18, 64, 0.04) !important;
    transition: transform 0.2s ease, box-shadow 0.2s ease !important;
}

.gh-course-card:hover {
    transform: translateY(-3px) !important;
    box-shadow: 0 10px 26px rgba(27, 18, 64, 0.08) !important;
    border-color: #D8D2EE !important;
}

/* Course Media */
.gh-course-media {
    position: relative !important;
    width: 100% !important;
    height: 185px !important;
    background: #F4F1FB !important;
    overflow: hidden !important;
}

.gh-course-thumb {
    width: 100% !important;
    height: 100% !important;
    object-fit: cover !important;
    transition: transform 0.3s ease !important;
}

.gh-course-card:hover .gh-course-thumb {
    transform: scale(1.03) !important;
}

.gh-course-thumb-placeholder {
    width: 100% !important;
    height: 100% !important;
    display: flex !important;
    align-items: center !important;
    justify-content: center !important;
    background: linear-gradient(135deg, #F4F1FB 0%, #E8E2F9 100%) !important;
}

.gh-media-badges {
    position: absolute !important;
    top: 12px !important;
    left: 12px !important;
    right: 12px !important;
    display: flex !important;
    align-items: center !important;
    justify-content: space-between !important;
    pointer-events: none !important;
}

.gh-status-pill {
    display: inline-flex !important;
    align-items: center !important;
    gap: 6px !important;
    padding: 5px 12px !important;
    border-radius: 999px !important;
    font-size: 12px !important;
    font-weight: 700 !important;
    line-height: 1.2 !important;
    white-space: nowrap !important;
    box-shadow: 0 2px 6px rgba(0, 0, 0, 0.08) !important;
}

.gh-pill-dot {
    width: 6px !important;
    height: 6px !important;
    border-radius: 50% !important;
    display: inline-block !important;
}

.gh-status-pill.status-published {
    background: #ECFDF5 !important;
    color: #059669 !important;
    border: 1px solid #A7F3D0 !important;
}
.gh-status-pill.status-published .gh-pill-dot {
    background: #10B981 !important;
}

.gh-status-pill.status-draft {
    background: #FFFBEB !important;
    color: #D97706 !important;
    border: 1px solid #FDE68A !important;
}
.gh-status-pill.status-draft .gh-pill-dot {
    background: #F59E0B !important;
}

.gh-status-pill.status-pending {
    background: #F4F1FB !important;
    color: #632FD1 !important;
    border: 1px solid #D8D2EE !important;
}
.gh-status-pill.status-pending .gh-pill-dot {
    background: #632FD1 !important;
}

.gh-status-pill.status-private {
    background: #F3F4F6 !important;
    color: #4B5563 !important;
    border: 1px solid #E5E7EB !important;
}
.gh-status-pill.status-private .gh-pill-dot {
    background: #6B7280 !important;
}

.gh-category-pill {
    background: rgba(27, 18, 64, 0.75) !important;
    backdrop-filter: blur(4px) !important;
    color: #FFFFFF !important;
    padding: 5px 12px !important;
    border-radius: 999px !important;
    font-size: 11.5px !important;
    font-weight: 600 !important;
    white-space: nowrap !important;
}

/* Card Body */
.gh-course-body {
    padding: 20px 22px 16px !important;
    flex: 1 !important;
    display: flex !important;
    flex-direction: column !important;
}

.gh-course-name {
    font-family: 'Poppins', sans-serif !important;
    font-size: 16.5px !important;
    font-weight: 600 !important;
    color: #1B1240 !important;
    margin: 0 0 12px 0 !important;
    line-height: 1.4 !important;
    display: -webkit-box !important;
    -webkit-line-clamp: 2 !important;
    line-clamp: 2 !important;
    -webkit-box-orient: vertical !important;
    overflow: hidden !important;
    text-overflow: ellipsis !important;
    min-height: 46px !important;
}

.gh-instructor-badge {
    display: flex !important;
    align-items: center !important;
    gap: 8px !important;
    margin-bottom: 14px !important;
}

.gh-instructor-avatar {
    width: 24px !important;
    height: 24px !important;
    border-radius: 50% !important;
    background: #632FD1 !important;
    color: #FFFFFF !important;
    font-size: 11px !important;
    font-weight: 700 !important;
    display: flex !important;
    align-items: center !important;
    justify-content: center !important;
}

.gh-instructor-name {
    font-size: 13px !important;
    font-weight: 500 !important;
    color: #4B5563 !important;
}

.gh-course-meta-row {
    display: flex !important;
    align-items: center !important;
    gap: 14px !important;
    margin-top: auto !important;
    padding-top: 14px !important;
    border-top: 1px solid #F3F1FA !important;
    flex-wrap: wrap !important;
}

.gh-meta-item {
    display: flex !important;
    align-items: center !important;
    gap: 6px !important;
    font-size: 12.5px !important;
    color: #6B7280 !important;
    font-weight: 500 !important;
    white-space: nowrap !important;
}

/* Card Footer */
.gh-course-footer {
    padding: 14px 22px !important;
    background: #FAFAFD !important;
    border-top: 1px solid #EBE7F7 !important;
    display: flex !important;
    align-items: center !important;
    justify-content: space-between !important;
    gap: 12px !important;
}

.gh-btn-action-edit {
    display: inline-flex !important;
    align-items: center !important;
    gap: 7px !important;
    background: #FFFFFF !important;
    border: 1px solid #D8D2EE !important;
    color: #632FD1 !important;
    padding: 8px 16px !important;
    border-radius: 10px !important;
    font-family: 'Poppins', sans-serif !important;
    font-size: 13px !important;
    font-weight: 600 !important;
    text-decoration: none !important;
    transition: all 0.2s ease !important;
    white-space: nowrap !important;
}

.gh-btn-action-edit:hover {
    background: #632FD1 !important;
    border-color: #632FD1 !important;
    color: #FFFFFF !important;
    transform: translateY(-1px) !important;
}

.gh-course-quick-links {
    display: flex !important;
    align-items: center !important;
    gap: 8px !important;
}

.gh-btn-icon-action {
    width: 36px !important;
    height: 36px !important;
    border-radius: 10px !important;
    background: #FFFFFF !important;
    border: 1px solid #E5E7EB !important;
    color: #4B5563 !important;
    display: flex !important;
    align-items: center !important;
    justify-content: center !important;
    text-decoration: none !important;
    transition: all 0.2s ease !important;
}

.gh-btn-icon-action:hover {
    border-color: #632FD1 !important;
    color: #632FD1 !important;
    background: #F4F1FB !important;
}

/* Empty States */
.gh-empty-courses-state {
    grid-column: 1 / -1 !important;
    background: #FFFFFF !important;
    border: 2px dashed #D8D2EE !important;
    border-radius: 20px !important;
    padding: 60px 24px !important;
    text-align: center !important;
    margin: 20px 0 !important;
}

.gh-empty-icon {
    width: 72px !important;
    height: 72px !important;
    border-radius: 50% !important;
    background: #F4F1FB !important;
    display: flex !important;
    align-items: center !important;
    justify-content: center !important;
    margin: 0 auto 18px !important;
}

.gh-empty-courses-state h3 {
    font-family: 'Poppins', sans-serif !important;
    font-size: 20px !important;
    font-weight: 700 !important;
    color: #1B1240 !important;
    margin: 0 0 8px 0 !important;
}

.gh-empty-courses-state p {
    color: #6B7280 !important;
    font-size: 14.5px !important;
    max-width: 480px !important;
    margin: 0 auto 24px !important;
    line-height: 1.5 !important;
}

/* Mobile & Tablet Responsive Adjustments */
@media (max-width: 1024px) {
    .gh-course-metrics-grid {
        grid-template-columns: repeat(2, 1fr) !important;
    }
}

@media (max-width: 768px) {
    .gh-courses-container {
        padding: 0 0 40px 0 !important;
        width: 100% !important;
        max-width: 100% !important;
    }

    .gh-courses-header {
        flex-direction: column !important;
        align-items: stretch !important;
    }

    .gh-btn-create-course {
        width: 100% !important;
    }

    .gh-course-metrics-grid {
        grid-template-columns: 1fr !important;
        gap: 12px !important;
    }

    .gh-course-toolbar {
        flex-direction: column !important;
        align-items: stretch !important;
        padding: 14px !important;
    }

    .gh-course-tabs {
        width: 100% !important;
        padding-bottom: 6px !important;
    }

    .gh-course-search-filters {
        width: 100% !important;
        flex-direction: column !important;
    }

    .gh-search-input-box {
        width: 100% !important;
        min-width: 0 !important;
    }

    .gh-custom-select {
        width: 100% !important;
    }

    .gh-courses-grid {
        grid-template-columns: 1fr !important;
    }
}
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const tabBtns = document.querySelectorAll('.gh-tab-btn');
    const searchInput = document.getElementById('gh-course-search-input');
    const catFilter = document.getElementById('gh-course-cat-filter');
    const instFilter = document.getElementById('gh-course-instructor-filter');
    const cards = document.querySelectorAll('.gh-course-card');
    const noMatchMsg = document.getElementById('gh-no-filter-match');

    let activeFilter = 'all';

    function filterCourses() {
        const query = searchInput ? searchInput.value.trim().toLowerCase() : '';
        const cat = catFilter ? catFilter.value.toLowerCase() : 'all';
        const inst = instFilter ? instFilter.value : 'all';

        let visibleCount = 0;

        cards.forEach(card => {
            const status = card.getAttribute('data-status') || '';
            const category = card.getAttribute('data-category') || '';
            const instructor = card.getAttribute('data-instructor') || '';
            const title = card.getAttribute('data-title') || '';

            // Status Match
            let matchesStatus = (activeFilter === 'all') || (status === activeFilter);

            // Search Query Match
            let matchesQuery = !query || title.includes(query) || category.includes(query);

            // Category Match
            let matchesCat = (cat === 'all') || (category === cat);

            // Instructor Match
            let matchesInst = (inst === 'all') || (instructor === inst);

            if (matchesStatus && matchesQuery && matchesCat && matchesInst) {
                card.style.display = 'flex';
                visibleCount++;
            } else {
                card.style.display = 'none';
            }
        });

        if (noMatchMsg) {
            noMatchMsg.style.display = (visibleCount === 0 && cards.length > 0) ? 'block' : 'none';
        }
    }

    // Tab buttons event
    tabBtns.forEach(btn => {
        btn.addEventListener('click', function() {
            tabBtns.forEach(b => b.classList.remove('active'));
            this.classList.add('active');
            activeFilter = this.getAttribute('data-filter') || 'all';
            filterCourses();
        });
    });

    // Search input event
    if (searchInput) {
        searchInput.addEventListener('input', filterCourses);
    }

    // Category filter event
    if (catFilter) {
        catFilter.addEventListener('change', filterCourses);
    }

    // Instructor filter event (Admin)
    if (instFilter) {
        instFilter.addEventListener('change', filterCourses);
    }
});
</script>

