<?php
/**
 * Tutor Dashboard - Class Log ("Log a class") Template
 * Direct match for Figma design
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$user_id = get_current_user_id();
$courses = GH_Portal_Class_Log::get_instructor_courses( $user_id );
$week_stats = GH_Portal_Class_Log::get_tutor_week_stats( $user_id );
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
