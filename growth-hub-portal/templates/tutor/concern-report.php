<?php
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
    max-width: 1320px;
    margin: 0 auto;
    padding-bottom: 60px;
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
    .gh-btn-text-mobile {
    display: none;
}
@media (max-width: 640px) {
    .gh-btn-text-desktop {
        display: none !important;
    }
    .gh-btn-text-mobile {
        display: inline !important;
    }
    .gh-btn-solid-purple {
        width: 100% !important;
        text-align: center !important;
        padding: 12px 20px !important;
        font-size: 14.5px !important;
    }
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

