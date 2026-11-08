<?php
/**
 * Tutor LMS - Mentor & Student Certificates Template
 *
 * Growth Hub Portal - Course Completion & Certificate Management
 * Tutors/Mentors can monitor certificates awarded across their courses,
 * copy credential links, and view/download official certificates.
 * Students can view and download their earned credentials.
 *
 * @package GrowthHubPortal
 * @author Adeleye Shina
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

global $wpdb;

$user_id   = get_current_user_id();
$user      = wp_get_current_user();
$is_admin  = current_user_can( 'administrator' );
$is_mentor = current_user_can( 'tutor_instructor' ) || $is_admin;

if ( ! $user_id ) {
    ?>
    <div class="gh-section-card" style="padding: 40px; text-align: center;">
        <h3 style="color: #1B1240; font-family: 'Poppins', sans-serif;"><?php esc_html_e( 'Access Denied', 'growth-hub' ); ?></h3>
        <p style="color: #6B7280; font-family: 'Lato', sans-serif;"><?php esc_html_e( 'Please log in to view certificates.', 'growth-hub' ); ?></p>
    </div>
    <?php
    return;
}

$dash_url = function_exists( 'gh_portal_dashboard_url' ) ? gh_portal_dashboard_url() : ( function_exists( 'tutor_utils' ) ? tutor_utils()->tutor_dashboard_url() : home_url( '/dashboard/' ) );

// 1. Determine Courses Scoping
$courses = array();
$merged_map = array();
$statuses = array( 'publish' );

if ( $is_admin ) {
    $courses = get_posts( array(
        'post_type'      => 'courses',
        'post_status'    => $statuses,
        'posts_per_page' => -1,
        'orderby'        => 'title',
        'order'          => 'ASC',
    ) );
} elseif ( $is_mentor ) {
    // Instructor's own or assigned courses
    if ( function_exists( 'tutor_utils' ) && method_exists( tutor_utils(), 'get_courses_by_instructor' ) ) {
        $native_courses = tutor_utils()->get_courses_by_instructor( $user_id, $statuses );
        if ( ! empty( $native_courses ) && is_array( $native_courses ) ) {
            foreach ( $native_courses as $nc ) {
                $nc_obj = is_object( $nc ) ? $nc : get_post( $nc );
                if ( $nc_obj ) $merged_map[ $nc_obj->ID ] = $nc_obj;
            }
        }
    }
    $authored = get_posts( array(
        'post_type'      => 'courses',
        'post_status'    => $statuses,
        'posts_per_page' => -1,
        'author'         => $user_id,
    ) );
    if ( ! empty( $authored ) ) {
        foreach ( $authored as $c ) $merged_map[ $c->ID ] = $c;
    }
    $courses = array_values( $merged_map );
}

$course_ids = ! empty( $courses ) ? wp_list_pluck( $courses, 'ID' ) : array();

// 2. Query Completed Certificates (Strictly Real Data, Zero Dummy)
$certificates = array();
$unique_students = array();
$unique_courses_with_certs = array();
$total_enrolled = 0;

if ( $is_mentor && ! empty( $course_ids ) ) {
    $in_courses = implode( ',', array_map( 'intval', $course_ids ) );

    // Count total enrolled across these courses for completion rate metric
    $total_enrolled = (int) $wpdb->get_var( "
        SELECT COUNT(ID) FROM {$wpdb->posts}
        WHERE post_type = 'tutor_enrolled'
          AND post_status = 'completed'
          AND post_parent IN ($in_courses)
    " );

    // Query 1: Native Tutor LMS Course Completion Comments
    $completion_comments = $wpdb->get_results( "
        SELECT c.comment_ID as id,
               c.comment_post_ID as course_id,
               c.user_id as student_id,
               c.comment_date as completed_at,
               c.comment_content as raw_content,
               p.post_title as course_title
        FROM {$wpdb->comments} c
        INNER JOIN {$wpdb->posts} p ON c.comment_post_ID = p.ID
        WHERE c.comment_type = 'course_completed'
          AND c.comment_post_ID IN ($in_courses)
        ORDER BY c.comment_ID DESC
    " );

    $seen_keys = array();

    if ( ! empty( $completion_comments ) ) {
        foreach ( $completion_comments as $row ) {
            $s_id = (int) $row->student_id;
            $c_id = (int) $row->course_id;
            $dedup_key = $s_id . '_' . $c_id;
            if ( isset( $seen_keys[ $dedup_key ] ) ) continue;
            $seen_keys[ $dedup_key ] = true;

            $student_user = get_userdata( $s_id );
            if ( ! $student_user ) continue;

            $hash = '';
            if ( function_exists( 'tutor_utils' ) && method_exists( tutor_utils(), 'is_completed_course' ) ) {
                $comp_obj = tutor_utils()->is_completed_course( $c_id, $s_id );
                if ( is_object( $comp_obj ) && ! empty( $comp_obj->completed_hash ) ) {
                    $hash = $comp_obj->completed_hash;
                }
            }
            if ( empty( $hash ) ) {
                $hash = strtoupper( substr( md5( 'gh_' . $c_id . '_' . $s_id ), 0, 10 ) );
            }

            // Public certificate URL
            $cert_url = home_url( '/tutor-certificate/' . $hash );

            // Track / Category
            $cat_terms = wp_get_post_terms( $c_id, 'course-category', array( 'fields' => 'names' ) );
            $category = ! empty( $cat_terms ) && ! is_wp_error( $cat_terms ) ? $cat_terms[0] : __( 'Professional Track', 'growth-hub' );

            $student_name = $student_user->display_name ? $student_user->display_name : $student_user->user_login;
            $unique_students[ $s_id ] = true;
            $unique_courses_with_certs[ $c_id ] = true;

            $certificates[] = array(
                'student_id'   => $s_id,
                'student_name' => $student_name,
                'student_email'=> $student_user->user_email,
                'avatar_letter'=> strtoupper( substr( $student_name, 0, 1 ) ),
                'course_id'    => $c_id,
                'course_title' => $row->course_title,
                'category'     => $category,
                'completed_at' => date_i18n( 'M j, Y', strtotime( $row->completed_at ) ),
                'hash'         => $hash,
                'cert_url'     => $cert_url,
                'mentee_url'   => function_exists( 'gh_portal_dashboard_url' ) ? gh_portal_dashboard_url( 'mentees', array( 'student_id' => $s_id ) ) : add_query_arg( array( 'tab' => 'mentees', 'student_id' => $s_id ), $dash_url ),
            );
        }
    }
}

$count_total_certs   = count( $certificates );
$count_unique_students = count( $unique_students );
$count_courses_with_certs = count( $unique_courses_with_certs );
$completion_rate = $total_enrolled > 0 ? round( ( $count_total_certs / $total_enrolled ) * 100, 1 ) : 0;
?>

<div class="gh-certs-container">

        <!-- Page Header -->
        <div class="gh-certs-header">
            <div>
                <h1 class="gh-certs-title"><?php esc_html_e( 'Certificates', 'growth-hub' ); ?></h1>
                <p class="gh-certs-subtitle">
                    <?php if ( $is_admin ) : ?>
                        <?php esc_html_e( 'Inspect official certificates issued to mentees across all academy courses, verify credentials, and export records.', 'growth-hub' ); ?>
                    <?php else : ?>
                        <?php esc_html_e( 'View and verify certificates earned by your mentees upon completing course requirements and assignments.', 'growth-hub' ); ?>
                    <?php endif; ?>
                </p>
            </div>
            <div class="gh-certs-header-actions">
                <a href="<?php echo esc_url( function_exists( 'gh_portal_dashboard_url' ) ? gh_portal_dashboard_url( 'mentees' ) : add_query_arg( 'tab', 'mentees', $dash_url ) ); ?>" class="gh-btn-secondary-link">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path>
                        <circle cx="9" cy="7" r="4"></circle>
                    </svg>
                    <span><?php esc_html_e( 'View Mentee Roster', 'growth-hub' ); ?></span>
                </a>
            </div>
        </div>

        <!-- 4 KPI Summary Cards -->
        <div class="gh-certs-metrics-grid">
            <div class="gh-metric-tile">
                <div class="gh-metric-icon-wrap" style="background: rgba(99, 47, 209, 0.1); color: #632FD1;">
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <circle cx="12" cy="8" r="7"></circle>
                        <polyline points="8.21 13.89 7 23 12 20 17 23 15.79 13.88"></polyline>
                    </svg>
                </div>
                <div class="gh-metric-info">
                    <span class="gh-metric-label"><?php esc_html_e( 'Total Certificates Issued', 'growth-hub' ); ?></span>
                    <span class="gh-metric-value"><?php echo esc_html( number_format( $count_total_certs ) ); ?></span>
                </div>
            </div>

            <div class="gh-metric-tile">
                <div class="gh-metric-icon-wrap" style="background: rgba(16, 185, 129, 0.1); color: #059669;">
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path>
                        <circle cx="9" cy="7" r="4"></circle>
                        <polyline points="16 11 18 13 22 9"></polyline>
                    </svg>
                </div>
                <div class="gh-metric-info">
                    <span class="gh-metric-label"><?php esc_html_e( 'Certified Mentees', 'growth-hub' ); ?></span>
                    <span class="gh-metric-value" style="color: #059669;"><?php echo esc_html( number_format( $count_unique_students ) ); ?></span>
                </div>
            </div>

            <div class="gh-metric-tile">
                <div class="gh-metric-icon-wrap" style="background: rgba(245, 158, 11, 0.1); color: #D97706;">
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"></path>
                        <path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"></path>
                    </svg>
                </div>
                <div class="gh-metric-info">
                    <span class="gh-metric-label"><?php esc_html_e( 'Certified Courses', 'growth-hub' ); ?></span>
                    <span class="gh-metric-value" style="color: #D97706;"><?php echo esc_html( number_format( $count_courses_with_certs ) ); ?></span>
                </div>
            </div>

            <div class="gh-metric-tile">
                <div class="gh-metric-icon-wrap" style="background: rgba(79, 70, 229, 0.1); color: #4F46E5;">
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <line x1="18" y1="20" x2="18" y2="10"></line>
                        <line x1="12" y1="20" x2="12" y2="4"></line>
                        <line x1="6" y1="20" x2="6" y2="14"></line>
                    </svg>
                </div>
                <div class="gh-metric-info">
                    <span class="gh-metric-label"><?php esc_html_e( 'Completion Ratio', 'growth-hub' ); ?></span>
                    <span class="gh-metric-value"><?php echo esc_html( $completion_rate ); ?>%</span>
                </div>
            </div>
        </div>

        <!-- Toolbar: Unified Search & Course Filter -->
        <div class="gh-certs-toolbar">
            <div class="gh-search-input-box">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#6B7280" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <circle cx="11" cy="11" r="8"></circle>
                    <line x1="21" y1="21" x2="16.65" y2="16.65"></line>
                </svg>
                <input type="text" id="ghCertSearchInput" placeholder="<?php esc_attr_e( 'Search mentee, course, or ID...', 'growth-hub' ); ?>" />
            </div>

            <?php if ( ! empty( $courses ) ) : ?>
                <div class="gh-select-wrap">
                    <select id="ghCertCourseFilter" class="gh-custom-select" aria-label="<?php esc_attr_e( 'Filter by Course', 'growth-hub' ); ?>">
                        <option value="all"><?php esc_html_e( 'All Courses', 'growth-hub' ); ?></option>
                        <?php foreach ( $courses as $c ) : ?>
                            <option value="<?php echo esc_attr( $c->ID ); ?>"><?php echo esc_html( $c->post_title ); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            <?php endif; ?>
        </div>

        <!-- Certificates Roster Table -->
        <div class="gh-certs-table-card">
            <?php if ( empty( $certificates ) ) : ?>
                <div class="gh-empty-certs-state">
                    <div class="gh-empty-icon">
                        <svg width="44" height="44" viewBox="0 0 24 24" fill="none" stroke="#632FD1" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">
                            <circle cx="12" cy="8" r="7"></circle>
                            <polyline points="8.21 13.89 7 23 12 20 17 23 15.79 13.88"></polyline>
                        </svg>
                    </div>
                    <h3><?php esc_html_e( 'No Certificates Issued Yet', 'growth-hub' ); ?></h3>
                    <p><?php esc_html_e( 'As mentees complete 100% of their enrolled courses, their official certificates and verification credentials will appear here automatically.', 'growth-hub' ); ?></p>
                    <a href="<?php echo esc_url( function_exists( 'gh_portal_dashboard_url' ) ? gh_portal_dashboard_url( 'mentees' ) : add_query_arg( 'tab', 'mentees', $dash_url ) ); ?>" class="gh-btn-view-mentees">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path>
                            <circle cx="9" cy="7" r="4"></circle>
                        </svg>
                        <span><?php esc_html_e( 'Monitor Mentee Progress', 'growth-hub' ); ?></span>
                    </a>
                </div>
            <?php else : ?>
                <div class="gh-table-wrap">
                    <table class="gh-certs-table" id="ghCertsTable">
                        <thead>
                            <tr>
                                <th><?php esc_html_e( 'Mentee / Student', 'growth-hub' ); ?></th>
                                <th><?php esc_html_e( 'Course & Track', 'growth-hub' ); ?></th>
                                <th><?php esc_html_e( 'Date Issued', 'growth-hub' ); ?></th>
                                <th><?php esc_html_e( 'Credential ID', 'growth-hub' ); ?></th>
                                <th style="text-align: right;"><?php esc_html_e( 'Actions', 'growth-hub' ); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ( $certificates as $cert ) : ?>
                                <tr class="gh-cert-row" 
                                    data-course="<?php echo esc_attr( $cert['course_id'] ); ?>" 
                                    data-search="<?php echo esc_attr( strtolower( $cert['student_name'] . ' ' . $cert['student_email'] . ' ' . $cert['course_title'] . ' ' . $cert['hash'] ) ); ?>">
                                    
                                    <td>
                                        <div class="gh-mentee-cell">
                                            <div class="gh-mentee-avatar">
                                                <?php echo esc_html( $cert['avatar_letter'] ); ?>
                                            </div>
                                            <div class="gh-mentee-details">
                                                <span class="gh-mentee-name"><?php echo esc_html( $cert['student_name'] ); ?></span>
                                                <span class="gh-mentee-email"><?php echo esc_html( $cert['student_email'] ); ?></span>
                                            </div>
                                        </div>
                                    </td>

                                    <td>
                                        <div class="gh-course-cell">
                                            <span class="gh-cert-course-title"><?php echo esc_html( $cert['course_title'] ); ?></span>
                                            <span class="gh-track-pill"><?php echo esc_html( $cert['category'] ); ?></span>
                                        </div>
                                    </td>

                                    <td>
                                        <span class="gh-cert-date"><?php echo esc_html( $cert['completed_at'] ); ?></span>
                                    </td>

                                    <td>
                                        <div class="gh-hash-cell">
                                            <code class="gh-hash-code">#<?php echo esc_html( str_replace( '-', '&#8209;', $cert['hash'] ) ); ?></code>
                                            <button type="button" class="gh-copy-hash-btn" onclick="ghCopyCredentialLink('<?php echo esc_js( $cert['cert_url'] ); ?>')" title="<?php esc_attr_e( 'Copy Verification Link', 'growth-hub' ); ?>">
                                                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="#632FD1" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                                    <rect x="9" y="9" width="13" height="13" rx="2" ry="2"></rect>
                                                    <path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"></path>
                                                </svg>
                                            </button>
                                        </div>
                                    </td>

                                    <td style="text-align: right;">
                                        <div class="gh-cert-actions">
                                            <button type="button" class="gh-btn-view-cert" onclick="ghOpenCertModal(<?php echo esc_attr( json_encode( $cert ) ); ?>)">
                                                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                                    <path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"></path>
                                                    <polyline points="15 3 21 3 21 9"></polyline>
                                                    <line x1="10" y1="14" x2="21" y2="3"></line>
                                                </svg>
                                                <span><?php esc_html_e( 'View Certificate', 'growth-hub' ); ?></span>
                                            </button>
                                            <a href="<?php echo esc_url( $cert['mentee_url'] ); ?>" class="gh-btn-icon-link" title="<?php esc_attr_e( 'View Mentee Profile', 'growth-hub' ); ?>">
                                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#632FD1" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                                    <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path>
                                                    <circle cx="12" cy="7" r="4"></circle>
                                                </svg>
                                            </a>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <div class="gh-no-match-box" id="ghNoCertMatch" style="display: none;">
                    <p><?php esc_html_e( 'No certificates match your search query or selected course filter.', 'growth-hub' ); ?></p>
                </div>
            <?php endif; ?>
        </div>

    </div>

<!-- Visual Certificate Verification Modal -->
<div class="gh-modal-overlay" id="certModal">
    <div class="gh-modal-card">
        <button type="button" class="gh-modal-close-btn" onclick="ghCloseCertModal()">&times;</button>
        <div class="gh-modal-header">
            <h3 style="font-family: 'Poppins', sans-serif; font-size: 20px; font-weight: 700; color: #1B1240; margin: 0 0 4px;"><?php esc_html_e( 'Certificate Verification', 'growth-hub' ); ?></h3>
            <p style="color: #6B7280; font-size: 13.5px; margin: 0 0 16px;"><?php esc_html_e( 'Official Growth Hub accredited course completion credential.', 'growth-hub' ); ?></p>
        </div>

        <div class="gh-cert-preview-card">
            <span class="gh-cert-preview-badge"><?php esc_html_e( 'Official Credential', 'growth-hub' ); ?></span>
            <p class="gh-cert-desc"><?php esc_html_e( 'This is proudly presented to', 'growth-hub' ); ?></p>
            <h2 class="gh-cert-recipient" id="modalCertRecipient"></h2>
            <p class="gh-cert-desc"><?php esc_html_e( 'for successfully completing all curriculum requirements for', 'growth-hub' ); ?></p>
            <div class="gh-cert-course-name" id="modalCertCourse"></div>

            <div class="gh-cert-meta-footer">
                <div>
                    <strong><?php esc_html_e( 'Date:', 'growth-hub' ); ?></strong> <span id="modalCertDate"></span>
                </div>
                <div>
                    <strong><?php esc_html_e( 'Credential:', 'growth-hub' ); ?></strong> <code id="modalCertHash" style="color: #F3C343; font-family: monospace; white-space: nowrap !important; word-break: keep-all !important; hyphens: none !important; display: inline-block !important;"></code>
                </div>
            </div>
        </div>

        <div class="gh-modal-footer">
            <button type="button" class="gh-btn-secondary-link" onclick="ghCopyCurrentModalLink()" style="background: #FFFFFF; border: 1.5px solid #D8D2EE; color: #632FD1; padding: 11px 18px; border-radius: 12px; font-weight: 600; cursor: pointer; display: inline-flex; align-items: center; justify-content: center;">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="#632FD1" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align: middle; margin-right: 5px;">
                    <rect x="9" y="9" width="13" height="13" rx="2" ry="2"></rect>
                    <path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"></path>
                </svg>
                <span><?php esc_html_e( 'Copy Verification Link', 'growth-hub' ); ?></span>
            </button>

            <button type="button" class="gh-btn-primary" onclick="ghPrintCurrentCert()" style="background: #632FD1; color: #FFFFFF; border: none; padding: 11px 18px; border-radius: 12px; font-weight: 600; cursor: pointer; display: inline-flex; align-items: center; justify-content: center; gap: 8px;">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <polyline points="6 9 6 2 18 2 18 9"></polyline>
                    <path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"></path>
                    <rect x="6" y="14" width="12" height="8"></rect>
                </svg>
                <span><?php esc_html_e( 'Print / Download PDF', 'growth-hub' ); ?></span>
            </button>
        </div>
    </div>
</div>

<style>
/* Growth Hub Certificates Master Stylesheet */
.gh-certs-container {
    max-width: 1320px;
    margin: 0 auto;
    padding-bottom: 60px;
    width: 100%;
    box-sizing: border-box;
    font-family: 'Lato', -apple-system, BlinkMacSystemFont, sans-serif !important;
    color: #1B1240 !important;
}

.gh-certs-header {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 20px;
    margin-bottom: 28px;
    flex-wrap: wrap;
}

.gh-certs-title {
    font-family: 'Poppins', sans-serif !important;
    font-size: 28px !important;
    font-weight: 700 !important;
    color: #1B1240 !important;
    margin: 0 0 6px 0 !important;
    line-height: 1.2 !important;
}

.gh-certs-subtitle {
    color: #6B7280 !important;
    font-size: 14.5px !important;
    margin: 0 !important;
    line-height: 1.5 !important;
}

.gh-btn-secondary-link {
    display: inline-flex !important;
    align-items: center !important;
    gap: 8px !important;
    background: #FFFFFF !important;
    border: 1.5px solid #D8D2EE !important;
    color: #632FD1 !important;
    padding: 10px 18px !important;
    border-radius: 12px !important;
    font-family: 'Poppins', sans-serif !important;
    font-size: 13.5px !important;
    font-weight: 600 !important;
    text-decoration: none !important;
    transition: all 0.2s ease !important;
    white-space: nowrap !important;
}

.gh-btn-secondary-link:hover {
    background: #F4F1FB !important;
    border-color: #632FD1 !important;
}

/* Metric KPI Cards */
.gh-certs-metrics-grid {
    display: grid !important;
    grid-template-columns: repeat(4, 1fr) !important;
    gap: 20px !important;
    margin-bottom: 30px !important;
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

/* Toolbar */
.gh-certs-toolbar {
    display: flex !important;
    align-items: center !important;
    justify-content: space-between !important;
    gap: 16px !important;
    margin-bottom: 24px !important;
    flex-wrap: wrap !important;
    width: 100% !important;
    box-sizing: border-box !important;
}

.gh-certs-toolbar .gh-search-input-box {
    display: flex !important;
    align-items: center !important;
    gap: 10px !important;
    background: #FFFFFF !important;
    border: 1px solid #D8D2EE !important;
    border-radius: 12px !important;
    padding: 0 14px !important;
    height: 46px !important;
    min-height: 46px !important;
    width: 320px !important;
    max-width: 100% !important;
    box-sizing: border-box !important;
    transition: border-color 0.2s ease, box-shadow 0.2s ease !important;
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
    min-width: 220px !important;
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

/* Table Card */
.gh-certs-table-card {
    background: #FFFFFF !important;
    border: 1px solid #EBE7F7 !important;
    border-radius: 18px !important;
    overflow: hidden !important;
    box-shadow: 0 4px 16px rgba(27, 18, 64, 0.04) !important;
}

.gh-table-wrap {
    width: 100% !important;
    overflow-x: auto !important;
    -webkit-overflow-scrolling: touch !important;
}

.gh-certs-table {
    width: 100% !important;
    min-width: 1040px !important;
    border-collapse: collapse !important;
    text-align: left !important;
}

.gh-certs-table th {
    background: #F8F7FC !important;
    color: #4B5563 !important;
    font-family: 'Poppins', sans-serif !important;
    font-size: 13px !important;
    font-weight: 600 !important;
    padding: 16px 20px !important;
    border-bottom: 1px solid #EBE7F7 !important;
    text-transform: uppercase !important;
    letter-spacing: 0.5px !important;
}

.gh-certs-table td {
    padding: 18px 20px !important;
    border-bottom: 1px solid #F3F1FA !important;
    vertical-align: middle !important;
    font-size: 14px !important;
}

/* Column Widths & Spacing */
.gh-certs-table th:nth-child(1),
.gh-certs-table td:nth-child(1) {
    min-width: 220px !important;
}

.gh-certs-table th:nth-child(2),
.gh-certs-table td:nth-child(2) {
    min-width: 260px !important;
}

.gh-certs-table th:nth-child(3),
.gh-certs-table td:nth-child(3) {
    min-width: 130px !important;
    white-space: nowrap !important;
}

.gh-certs-table th:nth-child(4),
.gh-certs-table td:nth-child(4) {
    min-width: 200px !important;
    padding-right: 36px !important;
    white-space: nowrap !important;
}

.gh-certs-table th:nth-child(5),
.gh-certs-table td:nth-child(5) {
    min-width: 230px !important;
    padding-left: 20px !important;
    text-align: right !important;
    white-space: nowrap !important;
}

.gh-certs-table tr:hover td {
    background: #FAF9FE !important;
}

/* Mentee Cell */
.gh-mentee-cell {
    display: flex !important;
    align-items: center !important;
    gap: 12px !important;
}

.gh-mentee-avatar {
    width: 40px !important;
    height: 40px !important;
    border-radius: 50% !important;
    background: linear-gradient(135deg, #632FD1 0%, #8B5CF6 100%) !important;
    color: #FFFFFF !important;
    font-family: 'Poppins', sans-serif !important;
    font-weight: 700 !important;
    font-size: 15px !important;
    display: flex !important;
    align-items: center !important;
    justify-content: center !important;
    flex-shrink: 0 !important;
}

.gh-mentee-details {
    display: flex !important;
    flex-direction: column !important;
}

.gh-mentee-name {
    font-family: 'Poppins', sans-serif !important;
    font-size: 14.5px !important;
    font-weight: 600 !important;
    color: #1B1240 !important;
    line-height: 1.3 !important;
}

.gh-mentee-email {
    font-size: 12.5px !important;
    color: #6B7280 !important;
}

/* Course Cell */
.gh-course-cell {
    display: flex !important;
    flex-direction: column !important;
    gap: 4px !important;
}

.gh-cert-course-title {
    font-family: 'Poppins', sans-serif !important;
    font-size: 14px !important;
    font-weight: 600 !important;
    color: #1B1240 !important;
}

.gh-track-pill {
    display: inline-block !important;
    background: #F4F1FB !important;
    color: #632FD1 !important;
    border: 1px solid #D8D2EE !important;
    padding: 3px 12px !important;
    border-radius: 999px !important;
    font-size: 11.5px !important;
    font-weight: 600 !important;
    white-space: nowrap !important;
    word-break: keep-all !important;
    overflow-wrap: normal !important;
    hyphens: none !important;
    -webkit-hyphens: none !important;
    flex-shrink: 0 !important;
    width: fit-content !important;
}

.gh-cert-date {
    font-size: 13.5px !important;
    color: #4B5563 !important;
    font-weight: 500 !important;
    white-space: nowrap !important;
}

/* Hash Cell - Strict Unbreakable ID */
.gh-hash-cell {
    display: inline-flex !important;
    align-items: center !important;
    gap: 8px !important;
    white-space: nowrap !important;
    word-break: keep-all !important;
    flex-shrink: 0 !important;
}

.gh-hash-code {
    background: #F3F4F6 !important;
    color: #1B1240 !important;
    padding: 5px 10px !important;
    border-radius: 6px !important;
    font-family: monospace !important;
    font-size: 13px !important;
    font-weight: 600 !important;
    letter-spacing: 0.5px !important;
    white-space: nowrap !important;
    word-break: keep-all !important;
    overflow-wrap: normal !important;
    hyphens: none !important;
    -webkit-hyphens: none !important;
    flex-shrink: 0 !important;
    display: inline-block !important;
}

.gh-copy-hash-btn {
    width: 32px !important;
    height: 32px !important;
    min-width: 32px !important;
    border-radius: 7px !important;
    background: #FFFFFF !important;
    border: 1.5px solid #D8D2EE !important;
    color: #632FD1 !important;
    display: inline-flex !important;
    align-items: center !important;
    justify-content: center !important;
    cursor: pointer !important;
    transition: all 0.15s ease !important;
    flex-shrink: 0 !important;
    padding: 0 !important;
}

.gh-copy-hash-btn svg {
    width: 15px !important;
    height: 15px !important;
    stroke: #632FD1 !important;
    fill: none !important;
    display: block !important;
    flex-shrink: 0 !important;
}

.gh-copy-hash-btn:hover {
    background: #632FD1 !important;
    border-color: #632FD1 !important;
}

.gh-copy-hash-btn:hover svg {
    stroke: #FFFFFF !important;
}

/* Actions */
.gh-cert-actions {
    display: inline-flex !important;
    align-items: center !important;
    gap: 8px !important;
    justify-content: flex-end !important;
}

.gh-btn-view-cert {
    display: inline-flex !important;
    align-items: center !important;
    gap: 6px !important;
    background: #632FD1 !important;
    color: #FFFFFF !important;
    padding: 8px 15px !important;
    border-radius: 10px !important;
    font-family: 'Poppins', sans-serif !important;
    font-size: 12.5px !important;
    font-weight: 600 !important;
    text-decoration: none !important;
    transition: background-color 0.2s ease !important;
    white-space: nowrap !important;
    cursor: pointer !important;
    border: none !important;
    flex-shrink: 0 !important;
}

.gh-btn-view-cert:hover {
    background-color: #5224B8 !important;
    color: #FFFFFF !important;
}

.gh-btn-icon-link {
    width: 34px !important;
    height: 34px !important;
    min-width: 34px !important;
    border-radius: 8px !important;
    background: #F4F1FB !important;
    border: 1px solid #D8D2EE !important;
    color: #632FD1 !important;
    display: inline-flex !important;
    align-items: center !important;
    justify-content: center !important;
    text-decoration: none !important;
    cursor: pointer !important;
    transition: all 0.15s ease !important;
    flex-shrink: 0 !important;
    padding: 0 !important;
}

.gh-btn-icon-link svg {
    width: 16px !important;
    height: 16px !important;
    stroke: #632FD1 !important;
    fill: none !important;
    display: block !important;
    flex-shrink: 0 !important;
}

.gh-btn-icon-link:hover {
    background: #632FD1 !important;
    border-color: #632FD1 !important;
}

.gh-btn-icon-link:hover svg {
    stroke: #FFFFFF !important;
}

/* Empty State */
.gh-empty-certs-state {
    padding: 70px 24px !important;
    text-align: center !important;
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

.gh-empty-certs-state h3 {
    font-family: 'Poppins', sans-serif !important;
    font-size: 20px !important;
    font-weight: 700 !important;
    color: #1B1240 !important;
    margin: 0 0 8px 0 !important;
}

.gh-empty-certs-state p {
    color: #6B7280 !important;
    font-size: 14.5px !important;
    max-width: 480px !important;
    margin: 0 auto 24px !important;
    line-height: 1.5 !important;
}

.gh-btn-view-mentees {
    display: inline-flex !important;
    align-items: center !important;
    gap: 8px !important;
    background: #632FD1 !important;
    color: #FFFFFF !important;
    padding: 11px 22px !important;
    border-radius: 12px !important;
    font-family: 'Poppins', sans-serif !important;
    font-size: 13.5px !important;
    font-weight: 600 !important;
    text-decoration: none !important;
    transition: background 0.2s ease !important;
}

.gh-btn-view-mentees:hover {
    background: #5224B8 !important;
    color: #FFFFFF !important;
}

.gh-no-match-box {
    padding: 30px !important;
    text-align: center !important;
    color: #6B7280 !important;
    font-size: 14px !important;
}

/* Responsive */
@media (max-width: 1024px) {
    .gh-certs-metrics-grid {
        grid-template-columns: repeat(2, 1fr) !important;
    }
}

@media (max-width: 768px) {
    .gh-certs-container {
        padding: 0 0 40px 0 !important;
        width: 100% !important;
        max-width: 100% !important;
    }

    .gh-certs-header {
        flex-direction: column !important;
        align-items: stretch !important;
    }

    .gh-certs-metrics-grid {
        grid-template-columns: 1fr !important;
        gap: 12px !important;
    }

    .gh-certs-toolbar {
        flex-direction: column !important;
        align-items: stretch !important;
        gap: 10px !important;
        margin-bottom: 20px !important;
        padding: 0 !important;
        background: transparent !important;
        border: none !important;
    }

    .gh-certs-toolbar .gh-search-input-box {
        width: 100% !important;
        max-width: 100% !important;
        min-width: 0 !important;
        height: 42px !important;
        min-height: 42px !important;
        padding: 0 12px !important;
        box-sizing: border-box !important;
    }

    .gh-certs-toolbar .gh-search-input-box input {
        font-size: 13px !important;
    }

    .gh-certs-toolbar .gh-select-wrap,
    .gh-certs-toolbar .gh-custom-select {
        width: 100% !important;
        max-width: 100% !important;
        min-width: 0 !important;
        height: 42px !important;
        min-height: 42px !important;
        line-height: 40px !important;
        font-size: 13px !important;
        box-sizing: border-box !important;
    }
}
</style>

<script>
let currentSelectedCert = null;

function ghOpenCertModal(cert) {
    if (!cert) return;
    currentSelectedCert = cert;
    document.getElementById('modalCertRecipient').innerText = cert.student_name || '';
    document.getElementById('modalCertCourse').innerText = cert.course_title || '';
    document.getElementById('modalCertDate').innerText = cert.completed_at || '';
    document.getElementById('modalCertHash').innerText = '#' + (cert.hash || '').replace(/-/g, '\u2011');

    const modal = document.getElementById('certModal');
    if (modal) {
        modal.style.display = 'flex';
    }
}

function ghCloseCertModal() {
    const modal = document.getElementById('certModal');
    if (modal) {
        modal.style.display = 'none';
    }
    currentSelectedCert = null;
}

function ghCopyCurrentModalLink() {
    if (currentSelectedCert && currentSelectedCert.cert_url) {
        ghCopyCredentialLink(currentSelectedCert.cert_url);
    }
}

function ghPrintCurrentCert() {
    if (currentSelectedCert && currentSelectedCert.cert_url) {
        window.open(currentSelectedCert.cert_url, '_blank');
    }
}

window.addEventListener('click', function(e) {
    const modal = document.getElementById('certModal');
    if (e.target === modal) {
        ghCloseCertModal();
    }
});

function ghCopyCredentialLink(url) {
    if (navigator.clipboard && window.isSecureContext) {
        navigator.clipboard.writeText(url).then(function() {
            ghShowCertToast('Credential verification link copied to clipboard!');
        }, function(err) {
            ghFallbackCopy(url);
        });
    } else {
        ghFallbackCopy(url);
    }
}

function ghFallbackCopy(text) {
    const input = document.createElement('input');
    input.value = text;
    document.body.appendChild(input);
    input.select();
    try {
        document.execCommand('copy');
        ghShowCertToast('Credential verification link copied!');
    } catch (err) {
        ghShowCertToast('Failed to copy link.');
    }
    document.body.removeChild(input);
}

function ghShowCertToast(msg) {
    let container = document.getElementById('ghCertToastContainer');
    if (!container) {
        container = document.createElement('div');
        container.id = 'ghCertToastContainer';
        container.style.cssText = 'position:fixed;bottom:24px;right:24px;z-index:999999;display:flex;flex-direction:column;gap:10px;pointer-events:none;';
        document.body.appendChild(container);
    }
    const toast = document.createElement('div');
    toast.style.cssText = 'background:#1B1240;color:#FFFFFF;padding:12px 18px;border-radius:12px;font-family:sans-serif;font-size:13.5px;box-shadow:0 8px 24px rgba(27,18,64,0.3);display:flex;align-items:center;gap:8px;pointer-events:auto;';
    toast.innerHTML = '<span style="color:#10B981;font-weight:bold;">&#10003;</span> ' + msg;
    container.appendChild(toast);
    setTimeout(() => {
        toast.style.opacity = '0';
        toast.style.transition = 'opacity 0.25s ease';
        setTimeout(() => toast.remove(), 250);
    }, 3200);
}

document.addEventListener('DOMContentLoaded', function() {
    const searchInput = document.getElementById('ghCertSearchInput');
    const courseFilter = document.getElementById('ghCertCourseFilter');
    const rows = document.querySelectorAll('.gh-cert-row');
    const noMatchBox = document.getElementById('ghNoCertMatch');

    function filterCerts() {
        const query = searchInput ? searchInput.value.trim().toLowerCase() : '';
        const course = courseFilter ? courseFilter.value : 'all';

        let visibleCount = 0;

        rows.forEach(row => {
            const rowCourse = row.getAttribute('data-course') || '';
            const rowSearch = row.getAttribute('data-search') || '';

            const matchesCourse = (course === 'all') || (rowCourse === course);
            const matchesQuery = !query || rowSearch.includes(query);

            if (matchesCourse && matchesQuery) {
                row.style.display = '';
                visibleCount++;
            } else {
                row.style.display = 'none';
            }
        });

        if (noMatchBox) {
            noMatchBox.style.display = (visibleCount === 0 && rows.length > 0) ? 'block' : 'none';
        }
    }

    if (searchInput) searchInput.addEventListener('input', filterCerts);
    if (courseFilter) courseFilter.addEventListener('change', filterCerts);
});
</script>

