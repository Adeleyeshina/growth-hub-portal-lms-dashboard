<?php
/**
 * Tutor Dashboard - Announcements Template
 * Build: Growth Hub Custom Announcements Hub
 * 
 * Features:
 * - Top header with "+ New Announcement" CTA
 * - Course / Cohort Filter
 * - Announcements Feed with Cohort Tag, Timestamp, and formatted body
 * - Quick Action: "Delete" with custom Confirmation Modal
 * - Responsive "+ New Announcement" Modal with Tutor LMS & WP meta persistence
 * - Zero dummy data & custom Toast notifications
 * 
 * Author: Adeleye Shina
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$user_id = get_current_user_id();
$user    = wp_get_current_user();

// Fetch Courses assigned to this instructor safely
if ( class_exists( 'GH_Portal_Class_Log' ) && method_exists( 'GH_Portal_Class_Log', 'get_instructor_courses' ) ) {
    $courses = GH_Portal_Class_Log::get_instructor_courses( $user_id );
} else {
    $courses = get_posts( array(
        'post_type'      => 'courses',
        'post_status'    => 'publish',
        'posts_per_page' => -1,
        'author'         => $user_id,
    ) );
    if ( empty( $courses ) ) {
        $courses = get_posts( array(
            'post_type'      => 'courses',
            'post_status'    => 'publish',
            'posts_per_page' => -1,
        ) );
    }
}

// Course ID to Title Map
$course_map = array();
if ( ! empty( $courses ) ) {
    foreach ( $courses as $c ) {
        $course_map[ $c->ID ] = $c->post_title;
    }
}

// Fetch stored announcements for this instructor (both post type and meta backup)
$saved_announcements = array();

// 1. Check native Tutor LMS tutor_announcements post type
$tutor_ann = get_posts( array(
    'post_type'      => 'tutor_announcements',
    'post_status'    => 'publish',
    'posts_per_page' => -1,
    'author'         => $user_id,
    'orderby'        => 'date',
    'order'          => 'DESC',
) );

if ( ! empty( $tutor_ann ) ) {
    foreach ( $tutor_ann as $ta ) {
        $course_title = isset( $course_map[ $ta->post_parent ] ) ? $course_map[ $ta->post_parent ] : get_the_title( $ta->post_parent );
        $saved_announcements[] = array(
            'id'          => 'post_' . $ta->ID,
            'wp_id'       => $ta->ID,
            'course_id'   => $ta->post_parent,
            'course_name' => $course_title ? $course_title : __( 'General Announcement', 'growth-hub' ),
            'title'       => $ta->post_title,
            'content'     => $ta->post_content,
            'date'        => get_the_date( 'Y-m-d H:i:s', $ta->ID ),
        );
    }
}

// 2. Also check custom user meta for persistent sync
$meta_key = '_gh_tutor_announcements_' . $user_id;
$meta_saved = get_user_meta( $user_id, $meta_key, true );
if ( is_array( $meta_saved ) ) {
    foreach ( $meta_saved as $ms ) {
        // avoid duplicate if same ID
        $exists = false;
        foreach ( $saved_announcements as $sa ) {
            if ( isset( $sa['id'] ) && $sa['id'] === $ms['id'] ) {
                $exists = true;
                break;
            }
        }
        if ( ! $exists ) {
            $saved_announcements[] = $ms;
        }
    }
}

// Filter by Course
$filter_course = isset( $_GET['course_filter'] ) ? intval( $_GET['course_filter'] ) : 0;
$filtered_announcements = array();

foreach ( $saved_announcements as $ann ) {
    if ( $filter_course > 0 && intval( $ann['course_id'] ) !== $filter_course ) {
        continue;
    }
    $filtered_announcements[] = $ann;
}

$dash_url = function_exists( 'gh_portal_dashboard_url' ) ? gh_portal_dashboard_url() : ( function_exists( 'tutor_utils' ) ? tutor_utils()->tutor_dashboard_url() : home_url( '/dashboard/' ) );
?>

<style>
/* Growth Hub - Announcements Master CSS */
.gh-ann-container {
    max-width: 1320px;
    margin: 0 auto;
    padding-bottom: 60px;
    width: 100%;
    box-sizing: border-box;
    font-family: 'Lato', -apple-system, BlinkMacSystemFont, sans-serif;
    color: #1B1240;
}

.gh-ann-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    margin-bottom: 24px;
    gap: 16px;
    flex-wrap: wrap;
}

.gh-ann-header h1 {
    font-family: 'Poppins', sans-serif !important;
    font-size: 26px !important;
    font-weight: 700 !important;
    color: #1B1240 !important;
    margin: 0 0 6px 0 !important;
    letter-spacing: -0.5px;
}

.gh-ann-header p {
    font-size: 14px;
    color: #8C85A6;
    margin: 0;
}

.gh-btn-announce-main {
    background: #632FD1 !important;
    color: #FFFFFF !important;
    border: none !important;
    border-radius: 9999px !important;
    padding: 10px 24px !important;
    font-family: 'Poppins', sans-serif !important;
    font-size: 13.5px !important;
    font-weight: 600 !important;
    cursor: pointer !important;
    display: inline-flex !important;
    align-items: center !important;
    gap: 8px !important;
    text-decoration: none !important;
    transition: background 0.15s ease, transform 0.1s ease !important;
    box-shadow: 0 4px 14px rgba(99, 47, 209, 0.25) !important;
}

.gh-btn-announce-main:hover {
    background: #5224B8 !important;
    color: #FFFFFF !important;
    transform: translateY(-1px);
}

/* Controls Bar */
.gh-ann-controls {
    display: flex;
    justify-content: space-between;
    align-items: center;
    background: #FFFFFF;
    border: 1px solid #D8D2EE;
    border-radius: 14px;
    padding: 14px 20px;
    margin-bottom: 24px;
    gap: 16px;
    flex-wrap: wrap;
}

.gh-ann-count-badge {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    font-family: 'Poppins', sans-serif;
    font-size: 13.5px;
    font-weight: 600;
    color: #1B1240;
}

.gh-ann-count-pill {
    background: #F4F1FB;
    color: #632FD1;
    font-size: 12px;
    font-weight: 700;
    padding: 3px 10px;
    border-radius: 9999px;
}

.gh-ann-select-wrap {
    min-width: 220px;
}

.gh-ann-select {
    background: #FFFFFF;
    border: 1px solid #D8D2EE;
    border-radius: 10px;
    padding: 8px 14px;
    font-family: 'Lato', sans-serif;
    font-size: 13px;
    color: #1B1240;
    width: 100%;
    cursor: pointer;
    outline: none;
}

.gh-ann-select:focus {
    border-color: #632FD1;
}

/* Announcements Feed */
.gh-ann-grid {
    display: flex;
    flex-direction: column;
    gap: 16px;
}

.gh-ann-card {
    background: #FFFFFF;
    border: 1px solid #D8D2EE;
    border-radius: 16px;
    padding: 24px 28px;
    box-sizing: border-box;
    transition: box-shadow 0.2s ease, border-color 0.2s ease;
    position: relative;
}

.gh-ann-card:hover {
    box-shadow: 0 8px 24px rgba(27, 18, 64, 0.06);
    border-color: #B2A2DE;
}

.gh-ann-card-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 12px;
    margin-bottom: 12px;
    flex-wrap: wrap;
}

.gh-ann-meta-left {
    display: flex;
    align-items: center;
    gap: 10px;
    flex-wrap: wrap;
}

.gh-ann-tag-course {
    font-family: 'Poppins', sans-serif;
    font-size: 11px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    color: #632FD1;
    background: #F4F1FB;
    padding: 4px 12px;
    border-radius: 9999px;
}

.gh-ann-time-text {
    font-size: 12.5px;
    color: #8C85A6;
    display: flex;
    align-items: center;
    gap: 5px;
}

.gh-ann-delete-btn {
    background: none !important;
    border: none !important;
    color: #8C85A6 !important;
    font-size: 16px !important;
    line-height: 1 !important;
    cursor: pointer !important;
    padding: 6px !important;
    border-radius: 6px !important;
    transition: color 0.15s, background 0.15s !important;
}

.gh-ann-delete-btn:hover {
    color: #DC2626 !important;
    background: #FEE2E2 !important;
}

.gh-ann-title {
    font-family: 'Poppins', sans-serif !important;
    font-size: 17px !important;
    font-weight: 700 !important;
    color: #1B1240 !important;
    margin: 0 0 10px 0 !important;
    line-height: 1.4 !important;
}

.gh-ann-content {
    font-family: 'Lato', sans-serif !important;
    font-size: 14.5px !important;
    color: #4B4566 !important;
    line-height: 1.6 !important;
    white-space: pre-line !important;
    margin: 0 !important;
}

/* Empty State */
.gh-ann-empty {
    background: #FFFFFF;
    border: 1px dashed #D8D2EE;
    border-radius: 16px;
    padding: 56px 24px;
    text-align: center;
}

.gh-ann-empty-icon {
    width: 64px;
    height: 64px;
    background: #F4F1FB;
    color: #632FD1;
    border-radius: 50%;
    margin: 0 auto 16px;
    display: flex;
    align-items: center;
    justify-content: center;
}

.gh-ann-empty h3 {
    font-family: 'Poppins', sans-serif !important;
    font-size: 18px !important;
    font-weight: 700 !important;
    color: #1B1240 !important;
    margin: 0 0 8px 0 !important;
}

.gh-ann-empty p {
    font-size: 14px;
    color: #8C85A6;
    max-width: 440px;
    margin: 0 auto 20px;
    line-height: 1.5;
}

/* Modal Styling */
.gh-modal-overlay {
    position: fixed;
    top: 0;
    left: 0;
    width: 100vw;
    height: 100vh;
    background: rgba(27, 18, 64, 0.55);
    backdrop-filter: blur(4px);
    display: none;
    align-items: center;
    justify-content: center;
    z-index: 100000;
    padding: 20px;
    box-sizing: border-box;
}

.gh-modal-box {
    background: #FFFFFF;
    border-radius: 20px;
    width: 100%;
    max-width: 580px;
    max-height: 90vh;
    overflow-y: auto;
    padding: 30px;
    box-sizing: border-box;
    box-shadow: 0 20px 50px rgba(27, 18, 64, 0.25);
    animation: ghPopIn 0.25s cubic-bezier(0.16, 1, 0.3, 1) forwards;
}

.gh-modal-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 22px;
}

.gh-modal-header h2 {
    font-family: 'Poppins', sans-serif !important;
    font-size: 20px !important;
    font-weight: 700 !important;
    color: #1B1240 !important;
    margin: 0 !important;
}

.gh-modal-close {
    background: none;
    border: none;
    font-size: 24px;
    line-height: 1;
    color: #8C85A6;
    cursor: pointer;
    padding: 4px;
}

.gh-modal-close:hover {
    color: #1B1240;
}

.gh-form-group {
    margin-bottom: 18px;
}

.gh-form-group label {
    display: block;
    font-family: 'Poppins', sans-serif;
    font-size: 13px;
    font-weight: 600;
    color: #1B1240;
    margin-bottom: 8px;
}

.gh-input-select {
    width: 100% !important;
    height: 48px !important;
    min-height: 48px !important;
    background: #FFFFFF !important;
    border: 1.5px solid #D8D2EE !important;
    border-radius: 12px !important;
    padding: 0 42px 0 16px !important;
    line-height: 46px !important;
    font-family: 'Lato', sans-serif !important;
    font-size: 14.5px !important;
    color: #1B1240 !important;
    box-sizing: border-box !important;
    outline: none !important;
    appearance: none !important;
    -webkit-appearance: none !important;
    -moz-appearance: none !important;
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 24 24' fill='none' stroke='%23632FD1' stroke-width='2.5' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpolyline points='6 9 12 15 18 9'%3E%3C/polyline%3E%3C/svg%3E") !important;
    background-repeat: no-repeat !important;
    background-position: right 16px center !important;
    background-size: 12px 12px !important;
    cursor: pointer !important;
    transition: border-color 0.15s, box-shadow 0.15s !important;
}

.gh-input-text {
    width: 100% !important;
    height: 48px !important;
    min-height: 48px !important;
    background: #FFFFFF !important;
    border: 1.5px solid #D8D2EE !important;
    border-radius: 12px !important;
    padding: 0 16px !important;
    line-height: 46px !important;
    font-family: 'Lato', sans-serif !important;
    font-size: 14.5px !important;
    color: #1B1240 !important;
    box-sizing: border-box !important;
    outline: none !important;
    transition: border-color 0.15s, box-shadow 0.15s !important;
}

.gh-input-textarea {
    width: 100% !important;
    background: #FFFFFF !important;
    border: 1.5px solid #D8D2EE !important;
    border-radius: 12px !important;
    padding: 14px 16px !important;
    line-height: 1.5 !important;
    font-family: 'Lato', sans-serif !important;
    font-size: 14.5px !important;
    color: #1B1240 !important;
    box-sizing: border-box !important;
    outline: none !important;
    resize: none !important;
    transition: border-color 0.15s, box-shadow 0.15s !important;
}

.gh-input-text:focus,
.gh-input-select:focus,
.gh-input-textarea:focus {
    border-color: #632FD1 !important;
    box-shadow: 0 0 0 3px rgba(99, 47, 209, 0.12) !important;
}

.gh-modal-footer {
    display: flex;
    justify-content: flex-end;
    gap: 12px;
    margin-top: 24px;
}

.gh-btn-modal-cancel {
    background: #FFFFFF !important;
    border: 1.5px solid #D8D2EE !important;
    color: #1B1240 !important;
    border-radius: 9999px !important;
    padding: 9px 20px !important;
    font-family: 'Poppins', sans-serif !important;
    font-size: 13.5px !important;
    font-weight: 600 !important;
    cursor: pointer !important;
    transition: all 0.15s ease !important;
    white-space: nowrap !important;
    text-align: center !important;
}

.gh-btn-modal-cancel:hover {
    background: #F4F1FB !important;
    border-color: #632FD1 !important;
    color: #632FD1 !important;
}

@media (max-width: 640px) {
    .gh-ann-header {
        flex-direction: column;
        align-items: stretch;
    }
    .gh-btn-announce-main {
        width: 100% !important;
        justify-content: center !important;
    }
    .gh-ann-controls {
        flex-direction: column;
        align-items: stretch;
    }
    .gh-ann-select-wrap {
        width: 100%;
    }
    .gh-modal-footer {
        flex-direction: column;
    }
    .gh-modal-footer button {
        width: 100%;
        text-align: center;
        justify-content: center;
    }
}
</style>

<div class="gh-ann-container">
    <!-- Top Header -->
    <div class="gh-ann-header">
        <div>
            <h1><?php esc_html_e( 'Announcements', 'growth-hub' ); ?></h1>
            <p><?php esc_html_e( 'Broadcast updates, weekly assignments, and important notices to your enrolled cohort mentees.', 'growth-hub' ); ?></p>
        </div>
        <div>
            <button type="button" class="gh-btn-announce-main" onclick="ghOpenAnnounceModal()">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
                <?php esc_html_e( 'New Announcement', 'growth-hub' ); ?>
            </button>
        </div>
    </div>

    <!-- Controls Bar -->
    <div class="gh-ann-controls">
        <div class="gh-ann-count-badge">
            <span><?php esc_html_e( 'Published Broadcasts', 'growth-hub' ); ?></span>
            <span class="gh-ann-count-pill"><?php echo count( $filtered_announcements ); ?></span>
        </div>

        <?php if ( ! empty( $courses ) ) : ?>
            <div class="gh-ann-select-wrap">
                <select class="gh-ann-select" onchange="window.location.href=this.value">
                    <option value="<?php echo esc_url( remove_query_arg( 'course_filter' ) ); ?>" <?php selected( 0, $filter_course ); ?>>
                        <?php esc_html_e( 'All Assigned Courses', 'growth-hub' ); ?>
                    </option>
                    <?php foreach ( $courses as $c ) : ?>
                        <option value="<?php echo esc_url( add_query_arg( 'course_filter', $c->ID ) ); ?>" <?php selected( $c->ID, $filter_course ); ?>>
                            <?php echo esc_html( $c->post_title ); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        <?php endif; ?>
    </div>

    <!-- Announcements Feed -->
    <div class="gh-ann-grid">
        <?php if ( ! empty( $filtered_announcements ) ) : ?>
            <?php foreach ( $filtered_announcements as $ann ) : 
                $date_time_str = date( 'M d, Y · g:i A', strtotime( $ann['date'] ) );
                ?>
                <div class="gh-ann-card" id="ann_card_<?php echo esc_attr( $ann['id'] ); ?>">
                    <div class="gh-ann-card-header">
                        <div class="gh-ann-meta-left">
                            <span class="gh-ann-tag-course"><?php echo esc_html( $ann['course_name'] ); ?></span>
                            <span class="gh-ann-time-text">
                                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg>
                                <?php echo esc_html( $date_time_str ); ?>
                            </span>
                        </div>
                        <button type="button" class="gh-ann-delete-btn" title="<?php esc_attr_e( 'Remove announcement', 'growth-hub' ); ?>" onclick="ghDeleteAnnouncement('<?php echo esc_js( $ann['id'] ); ?>')">
                            ✕
                        </button>
                    </div>

                    <h2 class="gh-ann-title"><?php echo esc_html( $ann['title'] ); ?></h2>
                    <p class="gh-ann-content"><?php echo esc_html( $ann['content'] ); ?></p>
                </div>
            <?php endforeach; ?>
        <?php else : ?>
            <div class="gh-ann-empty">
                <div class="gh-ann-empty-icon">
                    <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 5L6 9H2v6h4l5 4V5z"></path><path d="M19.07 4.93a10 10 0 0 1 0 14.14M15.54 8.46a5 5 0 0 1 0 7.07"></path></svg>
                </div>
                <h3><?php esc_html_e( 'No announcements yet', 'growth-hub' ); ?></h3>
                <p><?php esc_html_e( 'Broadcast updates, weekly assignments, and important notices to your enrolled cohort mentees.', 'growth-hub' ); ?></p>
                <button type="button" class="gh-btn-announce-main" onclick="ghOpenAnnounceModal()">
                    + <?php esc_html_e( 'Create First Announcement', 'growth-hub' ); ?>
                </button>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Modal: New Announcement -->
<div class="gh-modal-overlay" id="ghAnnounceModal">
    <div class="gh-modal-box">
        <div class="gh-modal-header">
            <h2><?php esc_html_e( 'Broadcast New Announcement', 'growth-hub' ); ?></h2>
            <button type="button" class="gh-modal-close" onclick="ghCloseAnnounceModal()">&times;</button>
        </div>

        <form id="ghAnnounceForm" onsubmit="ghSaveAnnouncement(event)">
            <div class="gh-form-group">
                <label><?php esc_html_e( 'Course / Cohort', 'growth-hub' ); ?> *</label>
                <select name="course_id" id="gh_ann_course" class="gh-input-select" required>
                    <?php if ( ! empty( $courses ) ) : ?>
                        <?php foreach ( $courses as $c ) : ?>
                            <option value="<?php echo esc_attr( $c->ID ); ?>" data-title="<?php echo esc_attr( $c->post_title ); ?>">
                                <?php echo esc_html( $c->post_title ); ?>
                            </option>
                        <?php endforeach; ?>
                    <?php else : ?>
                        <option value="" disabled selected><?php esc_html_e( 'No active courses assigned', 'growth-hub' ); ?></option>
                    <?php endif; ?>
                </select>
            </div>

            <div class="gh-form-group">
                <label><?php esc_html_e( 'Announcement Title', 'growth-hub' ); ?> *</label>
                <input type="text" name="title" id="gh_ann_title" class="gh-input-text" placeholder="<?php esc_attr_e( 'e.g. Week 4: Figma Interactive Prototypes Due Friday', 'growth-hub' ); ?>" required>
            </div>

            <div class="gh-form-group">
                <label><?php esc_html_e( 'Message Details / Instructions', 'growth-hub' ); ?> *</label>
                <textarea name="content" id="gh_ann_content" class="gh-input-textarea" rows="4" placeholder="<?php esc_attr_e( 'Write instructions, deadlines, study materials, or meeting notes for your cohort...', 'growth-hub' ); ?>" required></textarea>
            </div>

            <div class="gh-modal-footer">
                <button type="submit" class="gh-btn-announce-main">
                    <?php esc_html_e( 'Publish Announcement', 'growth-hub' ); ?>
                </button>
            </div>
        </form>
    </div>
</div>

<script>
function ghOpenAnnounceModal() {
    var modal = document.getElementById('ghAnnounceModal');
    if (modal) modal.style.display = 'flex';
}

function ghCloseAnnounceModal() {
    var modal = document.getElementById('ghAnnounceModal');
    if (modal) modal.style.display = 'none';
}

function ghSaveAnnouncement(e) {
    e.preventDefault();
    var courseSel = document.getElementById('gh_ann_course');
    var courseId = courseSel ? courseSel.value : 0;
    var courseName = (courseSel && courseSel.selectedIndex >= 0) ? (courseSel.options[courseSel.selectedIndex].getAttribute('data-title') || 'Course') : 'Course';
    var title = document.getElementById('gh_ann_title').value;
    var content = document.getElementById('gh_ann_content').value;

    var newAnn = {
        id: 'ann_' + Date.now(),
        course_id: courseId,
        course_name: courseName,
        title: title,
        content: content,
        date: new Date().toISOString()
    };

    // Save to local storage for instant test preview
    var stored = JSON.parse(localStorage.getItem('gh_announcements') || '[]');
    stored.unshift(newAnn);
    localStorage.setItem('gh_announcements', JSON.stringify(stored));

    // Also persist via WordPress AJAX if ajaxurl is defined
    if (typeof ajaxurl !== 'undefined') {
        var fd = new FormData();
        fd.append('action', 'gh_save_announcement');
        fd.append('ann_data', JSON.stringify(newAnn));
        fetch(ajaxurl, { method: 'POST', body: fd });
    }

    if (typeof ghToast === 'function') {
        ghToast('Announcement published successfully!');
    }
    ghCloseAnnounceModal();
    setTimeout(function() { window.location.reload(); }, 600);
}

function ghDeleteAnnouncement(id) {
    if (typeof ghConfirm === 'function') {
        ghConfirm(
            'Delete Announcement?',
            'Are you sure you want to remove this announcement? Mentees will no longer be able to view it.',
            function() {
                var card = document.getElementById('ann_card_' + id);
                if (card) {
                    card.style.opacity = '0.3';
                    card.style.pointerEvents = 'none';
                }

                var stored = JSON.parse(localStorage.getItem('gh_announcements') || '[]');
                stored = stored.filter(function(a) { return a.id !== id; });
                localStorage.setItem('gh_announcements', JSON.stringify(stored));

                if (typeof ajaxurl !== 'undefined') {
                    var fd = new FormData();
                    fd.append('action', 'gh_delete_announcement');
                    fd.append('ann_id', id);
                    fetch(ajaxurl, { method: 'POST', body: fd });
                }

                setTimeout(function() {
                    if (card) card.remove();
                    if (typeof ghToast === 'function') {
                        ghToast('Announcement deleted', 'info');
                    }
                }, 300);
            },
            'Delete',
            true
        );
    }
}
</script>

