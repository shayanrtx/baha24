<?php
/**
 * Plugin Name: Daily Market Baha24 Topbar
 * Description: Advanced live price topbar for Baha24 API with symbol manager, drag & drop, live preview, cache fallback and responsive controls.
 * Version: 1.4.0
 * Author: Daily Market
 * Text Domain: dm-baha24-topbar
 */

if ( ! defined( 'ABSPATH' ) ) exit;

final class DM_Baha24_Topbar_V12 {

    const VERSION         = '1.4.0';
    const OPT_SETTINGS    = 'dm_baha24_topbar_settings';
    const OPT_DATA        = 'dm_baha24_topbar_data_fallback';
    const OPT_STATUS      = 'dm_baha24_topbar_status';
    const OPT_LOGS        = 'dm_baha24_topbar_logs';
    const TRANSIENT_DATA  = 'dm_baha24_topbar_data_cache';
    const CRON_HOOK       = 'dm_baha24_topbar_cron_fetch';

    private static $instance = null;
    private $api_url = 'https://baha24.com/api/v1/price';

    public static function instance() {
        if ( self::$instance === null ) self::$instance = new self();
        return self::$instance;
    }

    private function __construct() {
        add_action( 'admin_menu', [ $this, 'admin_menu' ] );
        add_action( 'admin_init', [ $this, 'register_settings' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'admin_assets' ] );

        add_action( 'admin_post_dm_baha24_fetch_now', [ $this, 'handle_fetch_now' ] );
        add_action( 'admin_post_dm_baha24_clear_data', [ $this, 'handle_clear_data' ] );
        add_action( 'admin_post_dm_baha24_clear_logs', [ $this, 'handle_clear_logs' ] );

        add_filter( 'cron_schedules', [ $this, 'cron_schedules' ] );
        add_action( self::CRON_HOOK, [ $this, 'fetch_prices' ] );

        add_action( 'wp_body_open', [ $this, 'render_auto_topbar' ] );
        add_action( 'wp_head', [ $this, 'render_auto_topbar_early' ], 1 );
        add_shortcode( 'dm_baha24_topbar', [ $this, 'shortcode' ] );

        register_activation_hook( __FILE__, [ __CLASS__, 'activate' ] );
        register_deactivation_hook( __FILE__, [ __CLASS__, 'deactivate' ] );
    }

    public static function defaults() {
        return [
            'enabled'             => 1,
            'auto_display'        => 1,
            'sticky'              => 1,
            'token'               => '',
            'symbols'             => [ 'USD', 'USDT', 'EUR', 'GOL18', 'EMAMI1', 'AZADI1' ],
            'manual_symbols'      => 'USD,USDT,EUR,GOL18,EMAMI1,AZADI1',
            'selected_order'      => 'USD,USDT,EUR,GOL18,EMAMI1,AZADI1',

            'mode'                => 'ticker',
            'device_visibility'   => 'all', // all | desktop | mobile
            'page_scope'          => 'all', // all | home
            'unit_mode'           => 'auto', // auto | fixed | none
            'fixed_currency_label'=> 'تومان',
            'show_update_time'    => 0,

            'desktop_height'      => 45,
            'mobile_height'       => 40,
            'speed'               => 60,
            'z_index'             => 99999,
            'loop_mode'           => 0, // 0 = normal, 1 = infinite loop
            'above_all'           => 0, // 0 = normal, 1 = create own space without affecting layout

            'bg_color'            => '#121212',
            'border_color'        => '#d4af37',
            'title_color'         => '#aaaaaa',
            'value_color'         => '#d4af37',
            'currency_color'      => '#777777',
            'dot_color'           => '#333333',

            'fetch_interval'      => 'fifteen_minutes',
            'cache_ttl'           => 900,
            'timeout'             => 20,
            'debug'               => 1,
            'custom_css'          => '',
        ];
    }

    public static function activate() {
        $old = get_option( self::OPT_SETTINGS, [] );
        $old = is_array( $old ) ? $old : [];

        add_option(
            self::OPT_SETTINGS,
            wp_parse_args( $old, self::defaults() ),
            '',
            false
        );

        self::reschedule_cron();
    }

    public static function deactivate() {
        wp_clear_scheduled_hook( self::CRON_HOOK );
    }

    public static function reschedule_cron( $interval = null ) {
        wp_clear_scheduled_hook( self::CRON_HOOK );

        if ( $interval === null ) {
            $s = get_option( self::OPT_SETTINGS, self::defaults() );
            $s = wp_parse_args( is_array( $s ) ? $s : [], self::defaults() );
            $interval = $s['fetch_interval'];
        }

        if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
            wp_schedule_event( time() + 60, $interval, self::CRON_HOOK );
        }
    }

    public function cron_schedules( $schedules ) {
        $schedules['five_minutes'] = [
            'interval' => 5 * MINUTE_IN_SECONDS,
            'display'  => 'Every 5 Minutes',
        ];
        $schedules['fifteen_minutes'] = [
            'interval' => 15 * MINUTE_IN_SECONDS,
            'display'  => 'Every 15 Minutes',
        ];
        $schedules['thirty_minutes'] = [
            'interval' => 30 * MINUTE_IN_SECONDS,
            'display'  => 'Every 30 Minutes',
        ];

        return $schedules;
    }

    private function settings() {
        $s = get_option( self::OPT_SETTINGS, [] );
        $s = wp_parse_args( is_array( $s ) ? $s : [], self::defaults() );

        if ( ! is_array( $s['symbols'] ) ) {
            $s['symbols'] = $this->csv_to_array( $s['manual_symbols'] ?? '' );
        }

        return $s;
    }

    public function register_settings() {
        register_setting(
            'dm_baha24_group',
            self::OPT_SETTINGS,
            [
                'type'              => 'array',
                'sanitize_callback' => [ $this, 'sanitize_settings' ],
                'default'           => self::defaults(),
            ]
        );
    }

    public function sanitize_settings( $input ) {
        $old = $this->settings();
        $d   = self::defaults();
        $in  = is_array( $input ) ? $input : [];
        $out = [];

        $out['enabled']      = empty( $in['enabled'] ) ? 0 : 1;
        $out['auto_display'] = empty( $in['auto_display'] ) ? 0 : 1;
        $out['sticky']       = empty( $in['sticky'] ) ? 0 : 1;
        $out['token']        = sanitize_text_field( trim( $in['token'] ?? '' ) );

        $out['mode'] = in_array( $in['mode'] ?? '', [ 'ticker', 'static' ], true ) ? $in['mode'] : $d['mode'];

        $out['device_visibility'] = in_array( $in['device_visibility'] ?? '', [ 'all', 'desktop', 'mobile' ], true )
            ? $in['device_visibility']
            : $d['device_visibility'];

        $out['page_scope'] = in_array( $in['page_scope'] ?? '', [ 'all', 'home' ], true )
            ? $in['page_scope']
            : $d['page_scope'];

        $out['unit_mode'] = in_array( $in['unit_mode'] ?? '', [ 'auto', 'fixed', 'none' ], true )
            ? $in['unit_mode']
            : $d['unit_mode'];

        $out['fixed_currency_label'] = sanitize_text_field( $in['fixed_currency_label'] ?? $d['fixed_currency_label'] );
        $out['show_update_time']     = empty( $in['show_update_time'] ) ? 0 : 1;

        $out['desktop_height'] = max( 30, min( 90, absint( $in['desktop_height'] ?? $d['desktop_height'] ) ) );
        $out['mobile_height']  = max( 30, min( 80, absint( $in['mobile_height'] ?? $d['mobile_height'] ) ) );
        $out['speed']          = max( 10, min( 300, absint( $in['speed'] ?? $d['speed'] ) ) );
        $out['z_index']        = max( 1, min( 2147483647, absint( $in['z_index'] ?? $d['z_index'] ) ) );
        $out['loop_mode']      = empty( $in['loop_mode'] ) ? 0 : 1;
        $out['above_all']      = empty( $in['above_all'] ) ? 0 : 1;

        $out['bg_color']       = $this->hex( $in['bg_color'] ?? $d['bg_color'], $d['bg_color'] );
        $out['border_color']   = $this->hex( $in['border_color'] ?? $d['border_color'], $d['border_color'] );
        $out['title_color']    = $this->hex( $in['title_color'] ?? $d['title_color'], $d['title_color'] );
        $out['value_color']    = $this->hex( $in['value_color'] ?? $d['value_color'], $d['value_color'] );
        $out['currency_color'] = $this->hex( $in['currency_color'] ?? $d['currency_color'], $d['currency_color'] );
        $out['dot_color']      = $this->hex( $in['dot_color'] ?? $d['dot_color'], $d['dot_color'] );

        $allowed_intervals = [ 'five_minutes', 'fifteen_minutes', 'thirty_minutes', 'hourly', 'twicedaily', 'daily' ];
        $out['fetch_interval'] = in_array( $in['fetch_interval'] ?? '', $allowed_intervals, true )
            ? $in['fetch_interval']
            : $d['fetch_interval'];

        $out['cache_ttl'] = max( 60, min( DAY_IN_SECONDS, absint( $in['cache_ttl'] ?? $d['cache_ttl'] ) ) );
        $out['timeout']   = max( 5, min( 60, absint( $in['timeout'] ?? $d['timeout'] ) ) );
        $out['debug']     = empty( $in['debug'] ) ? 0 : 1;

        $out['custom_css'] = isset( $in['custom_css'] )
            ? trim( wp_unslash( $in['custom_css'] ) )
            : '';

        $manual = $this->sanitize_csv( $in['manual_symbols'] ?? '' );
        $checked = isset( $in['symbols'] ) && is_array( $in['symbols'] )
            ? $this->sanitize_symbol_array( $in['symbols'] )
            : [];

        $order = $this->sanitize_csv( $in['selected_order'] ?? '' );
        $order_arr = $this->csv_to_array( $order );

        if ( empty( $checked ) ) {
            $checked = $this->csv_to_array( $manual );
        }

        $sorted = [];

        foreach ( $order_arr as $symbol ) {
            if ( in_array( $symbol, $checked, true ) ) {
                $sorted[] = $symbol;
            }
        }

        foreach ( $checked as $symbol ) {
            if ( ! in_array( $symbol, $sorted, true ) ) {
                $sorted[] = $symbol;
            }
        }

        $out['manual_symbols'] = $manual;
        $out['symbols']        = array_values( array_unique( $sorted ) );
        $out['selected_order'] = implode( ',', $out['symbols'] );

        if ( $old['fetch_interval'] !== $out['fetch_interval'] ) {
            self::reschedule_cron( $out['fetch_interval'] );
        }

        $this->log( 'settings_saved', 'تنظیمات ذخیره شد.' );

        return $out;
    }

    private function hex( $value, $fallback ) {
        $v = sanitize_hex_color( $value );
        return $v ? $v : $fallback;
    }

    private function sanitize_csv( $raw ) {
        $raw = strtoupper( trim( wp_unslash( $raw ) ) );
        $raw = preg_replace( '/[^A-Z0-9_,\s]/', '', $raw );
        $parts = preg_split( '/[\s,]+/', $raw );
        $parts = array_filter( array_map( 'trim', $parts ) );
        $parts = array_unique( $parts );
        return implode( ',', $parts );
    }

    private function csv_to_array( $csv ) {
        $csv = $this->sanitize_csv( $csv );
        return $csv === '' ? [] : array_values( array_filter( array_map( 'trim', explode( ',', $csv ) ) ) );
    }

    private function sanitize_symbol_array( $symbols ) {
        $out = [];

        foreach ( $symbols as $symbol ) {
            $symbol = strtoupper( trim( wp_unslash( sanitize_text_field( $symbol ) ) ) );
            $symbol = preg_replace( '/[^A-Z0-9_]/', '', $symbol );
            if ( $symbol !== '' ) $out[] = $symbol;
        }

        return array_values( array_unique( $out ) );
    }

    public function admin_assets( $hook ) {
        if ( $hook !== 'settings_page_dm-baha24-topbar' ) return;

        wp_enqueue_style( 'wp-color-picker' );
        wp_enqueue_script( 'wp-color-picker' );
        wp_enqueue_script( 'jquery-ui-sortable' );
    }

    public function admin_menu() {
        add_options_page(
            'Baha24 Topbar',
            'Baha24 Topbar',
            'manage_options',
            'dm-baha24-topbar',
            [ $this, 'admin_page' ]
        );
    }

    public function admin_page() {
        if ( ! current_user_can( 'manage_options' ) ) return;

        $s      = $this->settings();
        $data   = $this->get_cached_data();
        $status = get_option( self::OPT_STATUS, [] );
        $logs   = get_option( self::OPT_LOGS, [] );

        $data   = is_array( $data ) ? $data : [];
        $status = is_array( $status ) ? $status : [];
        $logs   = is_array( $logs ) ? $logs : [];

        $human_error = $this->human_status_message( $status );
        ?>
        <div class="wrap dm-baha24-admin" dir="rtl">
            <style>
                .dm-baha24-admin{max-width:1280px}
                .dm-baha24-admin *{box-sizing:border-box}
                .dm-head{display:flex;align-items:center;justify-content:space-between;gap:14px;margin:22px 0}
                .dm-head h1{font-size:28px;margin:0;font-weight:800}
                .dm-sub{color:#777;margin-top:6px}
                .dm-grid{display:grid;grid-template-columns:1.15fr .85fr;gap:20px}
                .dm-card{background:#fff;border:1px solid #e7e7e7;border-radius:16px;padding:20px;box-shadow:0 4px 18px rgba(0,0,0,.035);margin-bottom:20px}
                .dm-card h2{margin:0 0 16px;font-size:18px}
                .dm-row{display:grid;grid-template-columns:210px 1fr;gap:16px;padding:12px 0;border-bottom:1px solid #f2f2f2}
                .dm-row:last-child{border-bottom:0}
                .dm-label{font-weight:700}
                .dm-muted{font-size:12px;color:#777;line-height:1.8;margin-top:6px}
                .dm-admin-input{width:100%;max-width:680px}
                .dm-actions{display:flex;gap:10px;flex-wrap:wrap}
                .dm-stat{display:grid;grid-template-columns:1fr 1fr;gap:10px}
                .dm-box{background:#fafafa;border:1px solid #eee;border-radius:12px;padding:14px}
                .dm-box .k{display:block;color:#777;font-size:12px}
                .dm-box .v{display:block;font-size:18px;font-weight:800;margin-top:6px}
                .ok{color:#008a20}.bad{color:#b32d2e}
                .dm-search{width:100%;margin-bottom:10px}
                .dm-symbol-toolbar{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:10px}
                .dm-table-wrap{max-height:420px;overflow:auto;border:1px solid #eee;border-radius:12px}
                .dm-table{width:100%;border-collapse:collapse}
                .dm-table th,.dm-table td{padding:10px;border-bottom:1px solid #f1f1f1;text-align:right}
                .dm-table th{position:sticky;top:0;background:#fafafa;z-index:1}
                .dm-selected-list{list-style:none;margin:0;padding:0;min-height:44px;border:1px dashed #ccc;border-radius:12px;background:#fcfcfc;padding:10px}
                .dm-selected-list li{display:flex;align-items:center;justify-content:space-between;gap:10px;background:#fff;border:1px solid #e7e7e7;border-radius:10px;padding:9px 10px;margin-bottom:8px;cursor:move}
                .dm-selected-list li:last-child{margin-bottom:0}
                .dm-pill{display:inline-flex;align-items:center;gap:6px;border-radius:999px;background:#f4f4f4;padding:4px 9px;font-size:12px}
                .dm-log{background:#101114;color:#d8dbe2;border-radius:12px;padding:14px;max-height:280px;overflow:auto;direction:ltr;text-align:left;font-size:12px}
                .dm-error-box{border-right:4px solid #b32d2e;background:#fff5f5;padding:12px;border-radius:10px;color:#7a1b1b}
                .dm-preview-shell{border:1px dashed #ddd;border-radius:14px;padding:16px;background:#fcfcfc}
                .dm-color{width:110px}
                @media(max-width:1000px){.dm-grid{grid-template-columns:1fr}.dm-row{grid-template-columns:1fr}}
            </style>

            <div class="dm-head">
                <div>
                    <h1>Baha24 Topbar v1.3</h1>
                    <div class="dm-sub">نوار قیمت حرفه‌ای با مدیریت نماد، کش، پیش‌نمایش زنده و کنترل نمایش. (نسخه ۱.۴: رفع مشکل نمایش ارزهای انتخابی، چسبیدن به بالاترین قسمت سایت، بهبود انیمیشن و سرعت)</div>
                </div>
            </div>

            <?php if ( isset( $_GET['dm_msg'] ) ) : ?>
                <div class="notice notice-info is-dismissible">
                    <p><?php echo esc_html( sanitize_text_field( wp_unslash( $_GET['dm_msg'] ) ) ); ?></p>
                </div>
            <?php endif; ?>

            <div class="dm-grid">
                <div>
                    <div class="dm-card">
                        <form method="post" action="options.php" id="dm-baha24-form">
                            <?php settings_fields( 'dm_baha24_group' ); ?>

                            <h2>تنظیمات اصلی</h2>

                            <div class="dm-row">
                                <div class="dm-label">فعال‌سازی</div>
                                <div>
                                    <label><input type="checkbox" name="<?php echo esc_attr( self::OPT_SETTINGS ); ?>[enabled]" value="1" <?php checked( $s['enabled'], 1 ); ?>> پلاگین فعال باشد</label>
                                </div>
                            </div>

                            <div class="dm-row">
                                <div class="dm-label">نمایش خودکار</div>
                                <div>
                                    <label><input type="checkbox" name="<?php echo esc_attr( self::OPT_SETTINGS ); ?>[auto_display]" value="1" <?php checked( $s['auto_display'], 1 ); ?>> بدون شورت‌کد در سایت نمایش داده شود</label>
                                </div>
                            </div>

                            <div class="dm-row">
                                <div class="dm-label">Sticky</div>
                                <div>
                                    <label><input type="checkbox" name="<?php echo esc_attr( self::OPT_SETTINGS ); ?>[sticky]" value="1" <?php checked( $s['sticky'], 1 ); ?>> به بالای صفحه بچسبد</label>
                                </div>
                            </div>

                            <div class="dm-row">
                                <div class="dm-label">Bearer Token</div>
                                <div>
                                    <input class="dm-admin-input" type="password" name="<?php echo esc_attr( self::OPT_SETTINGS ); ?>[token]" value="<?php echo esc_attr( $s['token'] ); ?>" autocomplete="off">
                                </div>
                            </div>

                            <h2 style="margin-top:28px">کنترل نمایش</h2>

                            <div class="dm-row">
                                <div class="dm-label">دستگاه</div>
                                <div>
                                    <select name="<?php echo esc_attr( self::OPT_SETTINGS ); ?>[device_visibility]" id="dm-device">
                                        <option value="all" <?php selected( $s['device_visibility'], 'all' ); ?>>دسکتاپ و موبایل</option>
                                        <option value="desktop" <?php selected( $s['device_visibility'], 'desktop' ); ?>>فقط دسکتاپ</option>
                                        <option value="mobile" <?php selected( $s['device_visibility'], 'mobile' ); ?>>فقط موبایل</option>
                                    </select>
                                </div>
                            </div>

                            <div class="dm-row">
                                <div class="dm-label">صفحات</div>
                                <div>
                                    <select name="<?php echo esc_attr( self::OPT_SETTINGS ); ?>[page_scope]">
                                        <option value="all" <?php selected( $s['page_scope'], 'all' ); ?>>همه صفحات</option>
                                        <option value="home" <?php selected( $s['page_scope'], 'home' ); ?>>فقط صفحه اصلی</option>
                                    </select>
                                </div>
                            </div>

                            <h2 style="margin-top:28px">ظاهر</h2>

                            <div class="dm-row">
                                <div class="dm-label">حالت</div>
                                <div>
                                    <select name="<?php echo esc_attr( self::OPT_SETTINGS ); ?>[mode]" id="dm-mode">
                                        <option value="ticker" <?php selected( $s['mode'], 'ticker' ); ?>>متحرک</option>
                                        <option value="static" <?php selected( $s['mode'], 'static' ); ?>>ایستاده</option>
                                    </select>
                                </div>
                            </div>

                            <div class="dm-row">
                                <div class="dm-label">واحد قیمت</div>
                                <div>
                                    <select name="<?php echo esc_attr( self::OPT_SETTINGS ); ?>[unit_mode]" id="dm-unit-mode">
                                        <option value="auto" <?php selected( $s['unit_mode'], 'auto' ); ?>>تشخیص خودکار</option>
                                        <option value="fixed" <?php selected( $s['unit_mode'], 'fixed' ); ?>>واحد ثابت</option>
                                        <option value="none" <?php selected( $s['unit_mode'], 'none' ); ?>>بدون نمایش واحد</option>
                                    </select>
                                    <div class="dm-muted">در حالت خودکار: رمزارزها دلار، ارز/طلا/سکه تومان نمایش داده می‌شوند.</div>
                                </div>
                            </div>

                            <div class="dm-row">
                                <div class="dm-label">واحد ثابت</div>
                                <div>
                                    <input class="dm-admin-input" type="text" id="dm-fixed-currency" name="<?php echo esc_attr( self::OPT_SETTINGS ); ?>[fixed_currency_label]" value="<?php echo esc_attr( $s['fixed_currency_label'] ); ?>">
                                </div>
                            </div>

                            <div class="dm-row">
                                <div class="dm-label">زمان آپدیت</div>
                                <div>
                                    <label><input type="checkbox" name="<?php echo esc_attr( self::OPT_SETTINGS ); ?>[show_update_time]" value="1" <?php checked( $s['show_update_time'], 1 ); ?>> نمایش زمان آخرین بروزرسانی</label>
                                </div>
                            </div>

                            <div class="dm-row">
                                <div class="dm-label">ارتفاع / سرعت</div>
                                <div>
                                    <p>
                                        دسکتاپ:
                                        <input type="number" id="dm-desktop-height" name="<?php echo esc_attr( self::OPT_SETTINGS ); ?>[desktop_height]" value="<?php echo esc_attr( $s['desktop_height'] ); ?>" min="30" max="90">
                                    </p>
                                    <p>
                                        موبایل:
                                        <input type="number" id="dm-mobile-height" name="<?php echo esc_attr( self::OPT_SETTINGS ); ?>[mobile_height]" value="<?php echo esc_attr( $s['mobile_height'] ); ?>" min="30" max="80">
                                    </p>
                                    <p>
                                        سرعت (ثانیه):
                                        <input type="number" id="dm-speed" name="<?php echo esc_attr( self::OPT_SETTINGS ); ?>[speed]" value="<?php echo esc_attr( $s['speed'] ); ?>" min="10" max="300">
                                        <div class="dm-muted">عدد بیشتر = سرعت کمتر. عدد کمتر = سرعت بیشتر.</div>
                                    </p>
                                </div>
                            </div>

                            <div class="dm-row">
                                <div class="dm-label">z-index</div>
                                <div>
                                    <input type="number" name="<?php echo esc_attr( self::OPT_SETTINGS ); ?>[z_index]" value="<?php echo esc_attr( $s['z_index'] ); ?>">
                                </div>
                            </div>

                            <div class="dm-row">
                                <div class="dm-label">تکرار نوار (Loop)</div>
                                <div>
                                    <label><input type="checkbox" name="<?php echo esc_attr( self::OPT_SETTINGS ); ?>[loop_mode]" value="1" <?php checked( $s['loop_mode'], 1 ); ?>> تکرار بی‌نهایت - وقتی نوار تمام شد دوباره از اول شروع شود</label>
                                    <div class="dm-muted">اگر فعال باشد، محتوا چند بار کپی می‌شود تا حلقه پیوسته و بدون پرش ایجاد شود.</div>
                                </div>
                            </div>

                            <div class="dm-row">
                                <div class="dm-label">فضای مستقل در بالای صفحه</div>
                                <div>
                                    <label><input type="checkbox" name="<?php echo esc_attr( self::OPT_SETTINGS ); ?>[above_all]" value="1" <?php checked( $s['above_all'], 1 ); ?>> نوار فضای خودش را بسازد و روی محتوای دیگر نباشد</label>
                                    <div class="dm-muted">اگر فعال باشد، نوار به صورت inline قرار می‌گیرد و فضای آن از محتوای صفحه کم نمی‌شود. اگر sticky هم فعال باشد، همچنان فضا رزرو می‌کند.</div>
                                </div>
                            </div>

                            <h2 style="margin-top:28px">رنگ‌ها</h2>

                            <?php
                            $colors = [
                                'bg_color'       => 'پس‌زمینه',
                                'border_color'   => 'خط پایین',
                                'title_color'    => 'عنوان',
                                'value_color'    => 'قیمت',
                                'currency_color' => 'واحد',
                                'dot_color'      => 'جداکننده',
                            ];
                            foreach ( $colors as $key => $label ) :
                            ?>
                                <div class="dm-row">
                                    <div class="dm-label"><?php echo esc_html( $label ); ?></div>
                                    <div>
                                        <input class="dm-color" id="dm-<?php echo esc_attr( $key ); ?>" type="text" name="<?php echo esc_attr( self::OPT_SETTINGS ); ?>[<?php echo esc_attr( $key ); ?>]" value="<?php echo esc_attr( $s[ $key ] ); ?>">
                                    </div>
                                </div>
                            <?php endforeach; ?>

                            <h2 style="margin-top:28px">API و کش</h2>

                            <div class="dm-row">
                                <div class="dm-label">بازه Fetch</div>
                                <div>
                                    <select name="<?php echo esc_attr( self::OPT_SETTINGS ); ?>[fetch_interval]">
                                        <option value="five_minutes" <?php selected( $s['fetch_interval'], 'five_minutes' ); ?>>هر ۵ دقیقه</option>
                                        <option value="fifteen_minutes" <?php selected( $s['fetch_interval'], 'fifteen_minutes' ); ?>>هر ۱۵ دقیقه</option>
                                        <option value="thirty_minutes" <?php selected( $s['fetch_interval'], 'thirty_minutes' ); ?>>هر ۳۰ دقیقه</option>
                                        <option value="hourly" <?php selected( $s['fetch_interval'], 'hourly' ); ?>>ساعتی</option>
                                        <option value="twicedaily" <?php selected( $s['fetch_interval'], 'twicedaily' ); ?>>روزی دو بار</option>
                                        <option value="daily" <?php selected( $s['fetch_interval'], 'daily' ); ?>>روزانه</option>
                                    </select>
                                </div>
                            </div>

                            <div class="dm-row">
                                <div class="dm-label">TTL کش</div>
                                <div>
                                    <input type="number" name="<?php echo esc_attr( self::OPT_SETTINGS ); ?>[cache_ttl]" value="<?php echo esc_attr( $s['cache_ttl'] ); ?>" min="60" max="<?php echo esc_attr( DAY_IN_SECONDS ); ?>">
                                    <div class="dm-muted">بر حسب ثانیه. پیش‌فرض ۹۰۰ ثانیه.</div>
                                </div>
                            </div>

                            <div class="dm-row">
                                <div class="dm-label">Timeout</div>
                                <div>
                                    <input type="number" name="<?php echo esc_attr( self::OPT_SETTINGS ); ?>[timeout]" value="<?php echo esc_attr( $s['timeout'] ); ?>" min="5" max="60">
                                </div>
                            </div>

                            <div class="dm-row">
                                <div class="dm-label">Debug</div>
                                <div>
                                    <label><input type="checkbox" name="<?php echo esc_attr( self::OPT_SETTINGS ); ?>[debug]" value="1" <?php checked( $s['debug'], 1 ); ?>> لاگ‌گیری فعال باشد</label>
                                </div>
                            </div>

                            <div class="dm-row">
                                <div class="dm-label">CSS اختصاصی</div>
                                <div>
                                    <textarea class="dm-admin-input" name="<?php echo esc_attr( self::OPT_SETTINGS ); ?>[custom_css]" rows="5"><?php echo esc_textarea( $s['custom_css'] ); ?></textarea>
                                </div>
                            </div>

                            <h2 style="margin-top:28px">مدیریت نمادها</h2>

                            <div class="dm-row">
                                <div class="dm-label">ورود دستی</div>
                                <div>
                                    <input class="dm-admin-input" type="text" name="<?php echo esc_attr( self::OPT_SETTINGS ); ?>[manual_symbols]" value="<?php echo esc_attr( $s['manual_symbols'] ); ?>">
                                    <div class="dm-muted">اگر هنوز Fetch انجام نشده، نمادها را دستی وارد کن. مثال: USD,USDT,EUR,GOL18,EMAMI1</div>
                                </div>
                            </div>

                            <div class="dm-row">
                                <div class="dm-label">نمادهای انتخاب‌شده</div>
                                <div>
                                    <ul id="dm-selected-list" class="dm-selected-list">
                                        <?php 
                                        $order_symbols = !empty( $s['selected_order'] ) 
                                            ? array_filter( explode( ',', $s['selected_order'] ) )
                                            : $s['symbols'];
                                        foreach ( $order_symbols as $symbol ) : 
                                            $symbol = trim( strtoupper( $symbol ) );
                                            if ( empty( $symbol ) ) continue;
                                        ?>
                                            <li data-symbol="<?php echo esc_attr( $symbol ); ?>">
                                                <strong><?php echo esc_html( $symbol ); ?></strong>
                                                <span class="dm-pill">Drag</span>
                                            </li>
                                        <?php endforeach; ?>
                                    </ul>
                                    <input type="hidden" id="dm-selected-order" name="<?php echo esc_attr( self::OPT_SETTINGS ); ?>[selected_order]" value="<?php echo esc_attr( implode( ',', $s['symbols'] ) ); ?>">
                                    <div class="dm-muted">برای تغییر ترتیب نمایش، آیتم‌ها را جابه‌جا کن.</div>
                                </div>
                            </div>

                            <div class="dm-row">
                                <div class="dm-label">لیست نمادهای API</div>
                                <div>
                                    <?php if ( empty( $data ) ) : ?>
                                        <div class="dm-muted">هنوز دیتایی وجود ندارد. ابتدا از سمت راست «دریافت دستی از API» را بزن.</div>
                                    <?php else : ?>
                                        <input type="search" id="dm-symbol-search" class="dm-search" placeholder="جستجوی نماد یا عنوان...">

                                        <div class="dm-symbol-toolbar">
                                            <button type="button" class="button" id="dm-select-all">Select All</button>
                                            <button type="button" class="button" id="dm-unselect-all">Unselect All</button>
                                        </div>

                                        <div class="dm-table-wrap">
                                            <table class="dm-table" id="dm-symbol-table">
                                                <thead>
                                                    <tr>
                                                        <th>نمایش</th>
                                                        <th>نماد</th>
                                                        <th>عنوان</th>
                                                        <th>قیمت</th>
                                                        <th>واحد خودکار</th>
                                                        <th>آپدیت</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                <?php foreach ( $data as $symbol => $item ) : ?>
                                                    <?php
                                                    $symbol = strtoupper( $symbol );
                                                    $title  = $item['title'] ?? $symbol;
                                                    ?>
                                                    <tr data-search="<?php echo esc_attr( strtolower( $symbol . ' ' . $title ) ); ?>">
                                                        <td>
                                                            <input
                                                                class="dm-symbol-check"
                                                                type="checkbox"
                                                                name="<?php echo esc_attr( self::OPT_SETTINGS ); ?>[symbols][]"
                                                                value="<?php echo esc_attr( $symbol ); ?>"
                                                                data-title="<?php echo esc_attr( $title ); ?>"
                                                                <?php checked( in_array( $symbol, $s['symbols'], true ) ); ?>
                                                            >
                                                        </td>
                                                        <td><code><?php echo esc_html( $symbol ); ?></code></td>
                                                        <td><?php echo esc_html( $title ); ?></td>
                                                        <td><strong><?php echo esc_html( $this->format_price( $item['sell'] ?? '' ) ); ?></strong></td>
                                                        <td><?php echo esc_html( $this->detect_unit( $symbol ) ); ?></td>
                                                        <td><?php echo esc_html( $item['last_update'] ?? '-' ); ?></td>
                                                    </tr>
                                                <?php endforeach; ?>
                                                </tbody>
                                            </table>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <?php submit_button( 'ذخیره تنظیمات' ); ?>
                        </form>
                    </div>
                </div>

                <div>
                    <div class="dm-card">
                        <h2>وضعیت API</h2>

                        <div class="dm-stat">
                            <div class="dm-box">
                                <span class="k">وضعیت</span>
                                <span class="v <?php echo ! empty( $status['ok'] ) ? 'ok' : 'bad'; ?>">
                                    <?php echo ! empty( $status['ok'] ) ? 'موفق' : 'ناموفق'; ?>
                                </span>
                            </div>
                            <div class="dm-box">
                                <span class="k">HTTP Code</span>
                                <span class="v"><?php echo esc_html( $status['http_code'] ?? '-' ); ?></span>
                            </div>
                            <div class="dm-box">
                                <span class="k">آخرین دریافت</span>
                                <span class="v"><?php echo esc_html( $status['time'] ?? '-' ); ?></span>
                            </div>
                            <div class="dm-box">
                                <span class="k">تعداد آیتم</span>
                                <span class="v"><?php echo esc_html( count( $data ) ); ?></span>
                            </div>
                        </div>

                        <?php if ( $human_error ) : ?>
                            <div class="dm-error-box" style="margin-top:14px">
                                <?php echo esc_html( $human_error ); ?>
                            </div>
                        <?php endif; ?>

                        <div class="dm-actions" style="margin-top:14px">
                            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                                <?php wp_nonce_field( 'dm_baha24_fetch_now' ); ?>
                                <input type="hidden" name="action" value="dm_baha24_fetch_now">
                                <?php submit_button( 'دریافت دستی از API', 'primary', 'submit', false ); ?>
                            </form>

                            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                                <?php wp_nonce_field( 'dm_baha24_clear_data' ); ?>
                                <input type="hidden" name="action" value="dm_baha24_clear_data">
                                <?php submit_button( 'پاک کردن دیتا', 'secondary', 'submit', false ); ?>
                            </form>
                        </div>

                        <hr>

                        <p><strong>Shortcode اختیاری:</strong></p>
                        <code>[dm_baha24_topbar]</code>
                    </div>

                    <div class="dm-card">
                        <h2>پیش‌نمایش زنده</h2>
                        <div class="dm-preview-shell">
                            <?php echo wp_kses_post( $this->generate_html( false, true ) ); ?>
                        </div>
                        <div class="dm-muted">رنگ‌ها، ارتفاع، سرعت، حالت نمایش و انتخاب نمادها بدون ذخیره در همین پنل قابل پیش‌نمایش هستند.</div>
                    </div>

                    <div class="dm-card">
                        <h2>لاگ</h2>

                        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-bottom:12px">
                            <?php wp_nonce_field( 'dm_baha24_clear_logs' ); ?>
                            <input type="hidden" name="action" value="dm_baha24_clear_logs">
                            <?php submit_button( 'پاک کردن لاگ', 'secondary', 'submit', false ); ?>
                        </form>

                        <div class="dm-log"><?php
                            if ( empty( $logs ) ) {
                                echo esc_html( 'No logs.' );
                            } else {
                                foreach ( array_reverse( $logs ) as $log ) {
                                    echo esc_html(
                                        '[' . ( $log['time'] ?? '-' ) . '] ' .
                                        ( $log['level'] ?? 'info' ) . ' | ' .
                                        ( $log['event'] ?? '-' ) . ' | ' .
                                        ( $log['message'] ?? '-' )
                                    );
                                    echo "\n";
                                }
                            }
                        ?></div>
                    </div>
                </div>
            </div>

            <script>
            jQuery(function($){
                $('.dm-color').wpColorPicker({
                    change: function(){ setTimeout(dmLivePreview, 20); },
                    clear: function(){ setTimeout(dmLivePreview, 20); }
                });

                function updateOrder(){
                    var arr = [];
                    $('#dm-selected-list li').each(function(){
                        arr.push($(this).data('symbol'));
                    });
                    $('#dm-selected-order').val(arr.join(','));
                }

                function addSelected(symbol){
                    if(!symbol) return;
                    if($('#dm-selected-list li[data-symbol="'+symbol+'"]').length) return;
                    $('#dm-selected-list').append(
                        '<li data-symbol="'+symbol+'"><strong>'+symbol+'</strong><span class="dm-pill">Drag</span></li>'
                    );
                    updateOrder();
                }

                function removeSelected(symbol){
                    $('#dm-selected-list li[data-symbol="'+symbol+'"]').remove();
                    updateOrder();
                }

                $('#dm-selected-list').sortable({
                    update: function(){
                        updateOrder();
                        dmLivePreview();
                    }
                });

                $('.dm-symbol-check').on('change', function(){
                    var symbol = $(this).val();
                    if($(this).is(':checked')) addSelected(symbol);
                    else removeSelected(symbol);
                    dmLivePreview();
                });

                $('#dm-select-all').on('click', function(){
                    $('.dm-symbol-check:visible').each(function(){
                        $(this).prop('checked', true).trigger('change');
                    });
                });

                $('#dm-unselect-all').on('click', function(){
                    $('.dm-symbol-check:visible').each(function(){
                        $(this).prop('checked', false).trigger('change');
                    });
                });

                $('#dm-symbol-search').on('input', function(){
                    var q = $(this).val().toLowerCase().trim();
                    $('#dm-symbol-table tbody tr').each(function(){
                        var hay = $(this).data('search') || '';
                        $(this).toggle(hay.indexOf(q) !== -1);
                    });
                });

                $('#dm-baha24-form').on('input change', 'input,select,textarea', function(){
                    dmLivePreview();
                });

                function dmLivePreview(){
                    var bg       = $('#dm-bg_color').val();
                    var border   = $('#dm-border_color').val();
                    var title    = $('#dm-title_color').val();
                    var value    = $('#dm-value_color').val();
                    var currency = $('#dm-currency_color').val();
                    var dot      = $('#dm-dot_color').val();
                    var h        = $('#dm-desktop-height').val();
                    var mh       = $('#dm-mobile-height').val();
                    var speed    = $('#dm-speed').val();
                    var mode     = $('#dm-mode').val();

                    $('.dm-baha24-topbar').each(function(){
                        var bar = $(this);
                        bar.css({
                            '--dm-baha24-bg': bg,
                            '--dm-baha24-border': border,
                            '--dm-baha24-title': title,
                            '--dm-baha24-value': value,
                            '--dm-baha24-currency': currency,
                            '--dm-baha24-dot': dot,
                            '--dm-baha24-height': h + 'px',
                            '--dm-baha24-mobile-height': mh + 'px',
                            '--dm-baha24-speed': speed + 's'
                        });

                        bar.toggleClass('dm-baha24-static', mode === 'static');
                        bar.toggleClass('dm-baha24-ticker', mode === 'ticker');
                    });
                }

                updateOrder();
            });
            </script>
        </div>
        <?php
    }

    public function handle_fetch_now() {
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Access denied.' );
        check_admin_referer( 'dm_baha24_fetch_now' );

        $r = $this->fetch_prices();

        $msg = ! empty( $r['ok'] )
            ? 'داده‌ها با موفقیت از Baha24 دریافت شد.'
            : 'دریافت داده ناموفق بود. پیام خطا را بررسی کنید.';

        wp_safe_redirect( admin_url( 'options-general.php?page=dm-baha24-topbar&dm_msg=' . rawurlencode( $msg ) ) );
        exit;
    }

    public function handle_clear_data() {
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Access denied.' );
        check_admin_referer( 'dm_baha24_clear_data' );

        delete_transient( self::TRANSIENT_DATA );
        delete_option( self::OPT_DATA );
        delete_option( self::OPT_STATUS );

        $this->log( 'data_cleared', 'دیتا و کش پاک شد.' );

        wp_safe_redirect( admin_url( 'options-general.php?page=dm-baha24-topbar&dm_msg=' . rawurlencode( 'دیتا و کش پاک شد.' ) ) );
        exit;
    }

    public function handle_clear_logs() {
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Access denied.' );
        check_admin_referer( 'dm_baha24_clear_logs' );

        delete_option( self::OPT_LOGS );

        wp_safe_redirect( admin_url( 'options-general.php?page=dm-baha24-topbar&dm_msg=' . rawurlencode( 'لاگ پاک شد.' ) ) );
        exit;
    }

    public function fetch_prices() {
        $s = $this->settings();

        if ( empty( $s['token'] ) ) {
            return $this->save_status( false, 0, 'TOKEN_EMPTY', 'توکن API وارد نشده است.' );
        }

        $res = wp_remote_get( $this->api_url, [
            'timeout'     => absint( $s['timeout'] ),
            'redirection' => 2,
            'headers'     => [
                'Accept'        => 'application/json',
                'Authorization' => 'Bearer ' . $s['token'],
                'User-Agent'    => 'DailyMarket-Baha24-Topbar/' . self::VERSION . '; ' . home_url(),
            ],
        ] );

        if ( is_wp_error( $res ) ) {
            return $this->save_status( false, 0, 'NETWORK_ERROR', $res->get_error_message() );
        }

        $code = wp_remote_retrieve_response_code( $res );
        $body = wp_remote_retrieve_body( $res );

        if ( $code === 401 || $code === 403 ) {
            return $this->save_status( false, $code, 'AUTH_ERROR', 'احراز هویت ناموفق است. توکن را بررسی کن.' );
        }

        if ( $code === 429 ) {
            return $this->save_status( false, $code, 'RATE_LIMIT', 'محدودیت تعداد درخواست API فعال شده است. بازه دریافت را افزایش بده.' );
        }

        if ( $code < 200 || $code >= 300 ) {
            return $this->save_status( false, $code, 'HTTP_ERROR', 'پاسخ نامعتبر از سرور API. HTTP Code: ' . $code );
        }

        $json = json_decode( $body, true );

        if ( json_last_error() !== JSON_ERROR_NONE ) {
            return $this->save_status( false, $code, 'JSON_ERROR', 'خروجی API قابل خواندن نیست: ' . json_last_error_msg() );
        }

        $data = $this->normalize_data( $json );

        if ( empty( $data ) ) {
            return $this->save_status( false, $code, 'EMPTY_DATA', 'داده معتبر در پاسخ API پیدا نشد.' );
        }

        set_transient( self::TRANSIENT_DATA, $data, absint( $s['cache_ttl'] ) );
        update_option( self::OPT_DATA, $data, false );

        return $this->save_status( true, $code, 'SUCCESS', 'Fetched ' . count( $data ) . ' items.' );
    }

    private function save_status( $ok, $http_code, $code, $message ) {
        $status = [
            'ok'        => (bool) $ok,
            'time'      => current_time( 'mysql' ),
            'http_code' => absint( $http_code ),
            'code'      => sanitize_text_field( $code ),
            'message'   => sanitize_text_field( $message ),
        ];

        update_option( self::OPT_STATUS, $status, false );

        $this->log(
            $ok ? 'fetch_success' : 'fetch_failed',
            $code . ' - ' . $message,
            $ok ? 'info' : 'error'
        );

        return $status;
    }

    private function normalize_data( $json ) {
        $out = [];

        if ( ! is_array( $json ) ) return $out;

        foreach ( $json as $symbol => $item ) {
            $symbol = strtoupper( sanitize_text_field( (string) $symbol ) );
            $symbol = preg_replace( '/[^A-Z0-9_]/', '', $symbol );

            if ( $symbol === '' || ! is_array( $item ) ) continue;

            $title = isset( $item['title'] ) ? sanitize_text_field( $item['title'] ) : $symbol;
            $sell  = isset( $item['sell'] ) ? $this->decimal( $item['sell'] ) : '';
            $time  = isset( $item['last_update'] ) ? sanitize_text_field( $item['last_update'] ) : '';

            if ( $sell === '' ) continue;

            $out[ $symbol ] = [
                'symbol'      => $symbol,
                'title'       => $title,
                'sell'        => $sell,
                'last_update' => $time,
            ];
        }

        return $out;
    }

    private function get_cached_data() {
        $data = get_transient( self::TRANSIENT_DATA );

        if ( is_array( $data ) && ! empty( $data ) ) {
            return $data;
        }

        $fallback = get_option( self::OPT_DATA, [] );

        return is_array( $fallback ) ? $fallback : [];
    }

    private function decimal( $value ) {
        $value = trim( (string) $value );
        $value = preg_replace( '/[^0-9.]/', '', $value );
        return $value === '' ? '' : $value;
    }

    private function format_price( $value ) {
        $value = $this->decimal( $value );
        if ( $value === '' ) return '-';

        $float = (float) $value;

        if ( $float >= 1000 ) {
            return number_format_i18n( round( $float ) );
        }

        return rtrim( rtrim( number_format( $float, 8, '.', ',' ), '0' ), '.' );
    }

    private function detect_unit( $symbol ) {
        $symbol = strtoupper( $symbol );

        $crypto = [
            'BTC', 'ETH', 'USDT', 'BNB', 'SOL', 'XRP', 'ADA', 'DOGE', 'TRX',
            'TON', 'DOT', 'AVAX', 'MATIC', 'LTC', 'BCH', 'LINK', 'UNI', 'SHIB'
        ];

        if ( in_array( $symbol, $crypto, true ) ) {
            return 'دلار';
        }

        return 'تومان';
    }

    private function get_unit_label( $symbol, $s ) {
        if ( $s['unit_mode'] === 'none' ) return '';
        if ( $s['unit_mode'] === 'fixed' ) return $s['fixed_currency_label'];
        return $this->detect_unit( $symbol );
    }

    private function human_status_message( $status ) {
        if ( empty( $status ) || ! is_array( $status ) ) {
            return 'هنوز هیچ درخواستی به API ارسال نشده است.';
        }

        if ( ! empty( $status['ok'] ) ) return '';

        $code = $status['code'] ?? '';

        $map = [
            'TOKEN_EMPTY'   => 'توکن API وارد نشده است. ابتدا Bearer Token را در تنظیمات ذخیره کن.',
            'NETWORK_ERROR' => 'ارتباط با سرور Baha24 برقرار نشد. اتصال اینترنت، DNS یا فایروال سرور را بررسی کن.',
            'AUTH_ERROR'    => 'توکن API نامعتبر است یا دسترسی لازم را ندارد.',
            'RATE_LIMIT'    => 'تعداد درخواست‌ها زیاد بوده است. بازه Fetch را بیشتر کن.',
            'HTTP_ERROR'    => 'API پاسخ غیرمنتظره داده است.',
            'JSON_ERROR'    => 'ساختار پاسخ API قابل خواندن نیست.',
            'EMPTY_DATA'    => 'API پاسخ داده، اما آیتم معتبر برای نمایش پیدا نشده است.',
        ];

        return $map[ $code ] ?? ( $status['message'] ?? 'خطای نامشخص در ارتباط با API.' );
    }

    private function log( $event, $message, $level = 'info' ) {
        $s = $this->settings();
        if ( empty( $s['debug'] ) ) return;

        $logs = get_option( self::OPT_LOGS, [] );
        $logs = is_array( $logs ) ? $logs : [];

        $logs[] = [
            'time'    => current_time( 'mysql' ),
            'level'   => sanitize_text_field( $level ),
            'event'   => sanitize_text_field( $event ),
            'message' => sanitize_text_field( $message ),
        ];

        $logs = array_slice( $logs, -120 );
        update_option( self::OPT_LOGS, $logs, false );
    }

    public function render_auto_topbar_early() {
        $s = $this->settings();

        if ( empty( $s['enabled'] ) || empty( $s['auto_display'] ) ) return;

        if ( $s['page_scope'] === 'home' && ! ( is_front_page() || is_home() ) ) return;

        // Render early for sticky mode to ensure it's at the very top
        if ( empty( $s['sticky'] ) && empty( $s['above_all'] ) ) return;

        echo $this->generate_html( ! empty( $s['sticky'] ), false );
    }

    public function render_auto_topbar() {
        $s = $this->settings();

        if ( empty( $s['enabled'] ) || empty( $s['auto_display'] ) ) return;

        if ( $s['page_scope'] === 'home' && ! ( is_front_page() || is_home() ) ) return;

        // Skip if already rendered early in wp_head for sticky or above_all mode
        if ( ! empty( $s['sticky'] ) || ! empty( $s['above_all'] ) ) return;

        echo $this->generate_html( ! empty( $s['sticky'] ), false );
    }

    public function shortcode( $atts ) {
        $atts = shortcode_atts(
            [
                'sticky' => '0',
            ],
            $atts,
            'dm_baha24_topbar'
        );

        return $this->generate_html( $atts['sticky'] === '1', false );
    }

    private function generate_html( $sticky = false, $preview = false ) {
        $s    = $this->settings();
        $data = $this->get_cached_data();

        if ( empty( $s['enabled'] ) && ! $preview ) return '';

        if ( empty( $data ) || ! is_array( $data ) ) {
            return $preview
                ? '<div style="color:#777;font-size:13px">هنوز دیتایی برای پیش‌نمایش وجود ندارد. ابتدا Fetch انجام بده.</div>'
                : '';
        }

        $symbols = $s['symbols'];

        if ( empty( $symbols ) ) {
            return $preview
                ? '<div style="color:#777;font-size:13px">هیچ نمادی انتخاب نشده است.</div>'
                : '';
        }

        $items = [];

        foreach ( $symbols as $symbol ) {
            $symbol = strtoupper( $symbol );

            if ( empty( $data[ $symbol ] ) ) continue;

            $item = $data[ $symbol ];

            $title = $item['title'] ?? $symbol;
            $sell  = $item['sell'] ?? '';
            $time  = $item['last_update'] ?? '';

            if ( $sell === '' ) continue;

            $unit_label = $this->get_unit_label( $symbol, $s );

            $unit_html = $unit_label !== ''
                ? '<span class="dm-baha24-currency">' . esc_html( $unit_label ) . '</span>'
                : '';

            $time_html = ! empty( $s['show_update_time'] ) && $time !== ''
                ? '<span class="dm-baha24-time">' . esc_html( $time ) . '</span>'
                : '';

            $items[] = sprintf(
                '<div class="dm-baha24-item" data-symbol="%1$s">
                    <span class="dm-baha24-title">%2$s</span>
                    <span class="dm-baha24-value">%3$s</span>
                    %4$s
                    %5$s
                    <span class="dm-baha24-dot"></span>
                </div>',
                esc_attr( $symbol ),
                esc_html( $title ),
                esc_html( $this->format_price( $sell ) ),
                $unit_html,
                $time_html
            );
        }

        if ( empty( $items ) ) {
            return $preview
                ? '<div style="color:#777;font-size:13px">برای نمادهای انتخاب‌شده دیتای معتبر پیدا نشد.</div>'
                : '';
        }

        $id       = 'dm-baha24-' . wp_rand( 10000, 99999 );
        $mode_cls = $s['mode'] === 'static' ? 'dm-baha24-static' : 'dm-baha24-ticker';
        
        // Determine positioning class based on above_all and sticky settings
        if ( ! empty( $s['above_all'] ) ) {
            // Create own space - inline block that doesn't overlap content
            $fix_cls = 'dm-baha24-above-all';
        } elseif ( $sticky ) {
            $fix_cls = 'dm-baha24-fixed';
        } else {
            $fix_cls = 'dm-baha24-inline';
        }
        
        $dev_cls  = 'dm-baha24-device-' . $s['device_visibility'];

        $items_html = implode( "\n", $items );

        // For loop mode, duplicate content multiple times for seamless infinite scroll
        if ( $s['mode'] === 'ticker' ) {
            if ( ! empty( $s['loop_mode'] ) ) {
                // Duplicate 3-4 times for smooth looping
                $items_html .= "\n" . implode( "\n", $items );
                $items_html .= "\n" . implode( "\n", $items );
                $items_html .= "\n" . implode( "\n", $items );
            } else {
                // Original behavior: duplicate once
                $items_html .= "\n" . implode( "\n", $items );
            }
        }

        ob_start();
        ?>
        <style>
            #<?php echo esc_attr( $id ); ?>{
                --dm-baha24-bg: <?php echo esc_html( $s['bg_color'] ); ?>;
                --dm-baha24-border: <?php echo esc_html( $s['border_color'] ); ?>;
                --dm-baha24-title: <?php echo esc_html( $s['title_color'] ); ?>;
                --dm-baha24-value: <?php echo esc_html( $s['value_color'] ); ?>;
                --dm-baha24-currency: <?php echo esc_html( $s['currency_color'] ); ?>;
                --dm-baha24-dot: <?php echo esc_html( $s['dot_color'] ); ?>;
                --dm-baha24-height: <?php echo absint( $s['desktop_height'] ); ?>px;
                --dm-baha24-mobile-height: <?php echo absint( $s['mobile_height'] ); ?>px;
                --dm-baha24-speed: <?php echo absint( $s['speed'] ); ?>s;
                width:100%;
                height:var(--dm-baha24-height);
                background:var(--dm-baha24-bg);
                color:#fff;
                direction:rtl;
                overflow:hidden;
                display:flex;
                align-items:center;
                box-sizing:border-box;
                border-bottom:2px solid var(--dm-baha24-border);
                font-family:IRANSans,Vazirmatn,system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;
                z-index:<?php echo absint( $s['z_index'] ); ?>;
            }
            #<?php echo esc_attr( $id ); ?>.dm-baha24-fixed{
                position:fixed;
                top:0;
                left:0;
                right:0;
                box-shadow:0 8px 24px rgba(0,0,0,.18);
            }
            body.admin-bar #<?php echo esc_attr( $id ); ?>.dm-baha24-fixed{top:32px}
            @media(max-width:782px){
                body.admin-bar #<?php echo esc_attr( $id ); ?>.dm-baha24-fixed{top:46px}
            }
            
            /* Above-all mode: creates its own space without overlapping content */
            #<?php echo esc_attr( $id ); ?>.dm-baha24-above-all{
                position:relative;
                top:0;
                left:0;
                right:0;
                margin:0;
                padding:0;
                display:block;
            }
            body.admin-bar #<?php echo esc_attr( $id ); ?>.dm-baha24-above-all{margin-top:32px}
            
            /* Sticky + above_all combined: fixed at top but reserves space */
            #<?php echo esc_attr( $id ); ?>.dm-baha24-fixed.dm-baha24-above-all{
                position:fixed;
                top:0;
            }
            body.admin-bar #<?php echo esc_attr( $id ); ?>.dm-baha24-fixed.dm-baha24-above-all{top:32px}
            @media(max-width:782px){
                body.admin-bar #<?php echo esc_attr( $id ); ?>.dm-baha24-fixed.dm-baha24-above-all{top:46px}
            }
            
            #<?php echo esc_attr( $id ); ?> .dm-baha24-track{
                display:flex;
                align-items:center;
                height:100%;
                white-space:nowrap;
                will-change:transform;
            }
            #<?php echo esc_attr( $id ); ?>.dm-baha24-static .dm-baha24-track{
                width:100%;
                justify-content:center;
            }
            #<?php echo esc_attr( $id ); ?>.dm-baha24-ticker .dm-baha24-track{
                width:max-content;
                animation:dmBaha24TickerRTL var(--dm-baha24-speed) linear infinite;
            }
            #<?php echo esc_attr( $id ); ?>.dm-baha24-ticker:hover .dm-baha24-track{
                animation-play-state:paused;
            }
            #<?php echo esc_attr( $id ); ?> .dm-baha24-item{
                display:inline-flex;
                align-items:center;
                height:100%;
                padding:0 20px;
                font-size:14px;
                white-space:nowrap;
            }
            #<?php echo esc_attr( $id ); ?> .dm-baha24-title{
                color:var(--dm-baha24-title);
                margin-left:8px;
                font-size:13px;
            }
            #<?php echo esc_attr( $id ); ?> .dm-baha24-value{
                color:var(--dm-baha24-value);
                font-weight:800;
                font-variant-numeric:tabular-nums;
            }
            #<?php echo esc_attr( $id ); ?> .dm-baha24-currency{
                color:var(--dm-baha24-currency);
                margin-right:5px;
                font-size:11px;
            }
            #<?php echo esc_attr( $id ); ?> .dm-baha24-time{
                color:var(--dm-baha24-currency);
                margin-right:8px;
                font-size:10px;
                opacity:.75;
            }
            #<?php echo esc_attr( $id ); ?> .dm-baha24-dot{
                width:4px;
                height:4px;
                background:var(--dm-baha24-dot);
                border-radius:50%;
                margin-right:18px;
                display:inline-block;
            }
            @keyframes dmBaha24TickerRTL{
                0%{transform:translateX(0)}
                100%{transform:translateX(-50%)}
            }
            @media(max-width:782px){
                body.admin-bar #<?php echo esc_attr( $id ); ?>.dm-baha24-fixed{top:46px}
                body.admin-bar #<?php echo esc_attr( $id ); ?>.dm-baha24-fixed.dm-baha24-above-all{top:46px}
            }
            @media(max-width:768px){
                #<?php echo esc_attr( $id ); ?>{
                    height:var(--dm-baha24-mobile-height);
                }
                #<?php echo esc_attr( $id ); ?> .dm-baha24-item{
                    padding:0 12px;
                    font-size:13px;
                }
                #<?php echo esc_attr( $id ); ?> .dm-baha24-title{
                    font-size:12px;
                }
                #<?php echo esc_attr( $id ); ?>.dm-baha24-static .dm-baha24-track{
                    justify-content:flex-start;
                    width:max-content;
                    animation:dmBaha24TickerRTL var(--dm-baha24-speed) linear infinite;
                }
            }
            @media(min-width:769px){
                #<?php echo esc_attr( $id ); ?>.dm-baha24-device-mobile{display:none}
            }
            @media(max-width:768px){
                #<?php echo esc_attr( $id ); ?>.dm-baha24-device-desktop{display:none}
            }
            <?php echo wp_strip_all_tags( $s['custom_css'] ); ?>
        </style>

        <div id="<?php echo esc_attr( $id ); ?>" class="dm-baha24-topbar <?php echo esc_attr( $mode_cls ); ?> <?php echo esc_attr( $fix_cls ); ?> <?php echo esc_attr( $dev_cls ); ?>" role="region" aria-label="Baha24 live prices">
            <div class="dm-baha24-track">
                <?php echo wp_kses_post( $items_html ); ?>
            </div>
        </div>

        <?php if ( $sticky && ! $preview ) : ?>
            <script>
            (function(){
                function applyOffset(){
                    var bar = document.getElementById('<?php echo esc_js( $id ); ?>');
                    if(!bar) return;
                    
                    // Only apply offset for fixed mode
                    var isFixed = bar.classList.contains('dm-baha24-fixed');
                    
                    if(!isFixed) return;

                    var style = window.getComputedStyle(bar);
                    if(style.display === 'none') return;

                    var h = bar.offsetHeight || <?php echo absint( $s['desktop_height'] ); ?>;
                    var old = document.body.getAttribute('data-dm-baha24-original-padding-top');

                    if(old === null){
                        old = window.getComputedStyle(document.body).paddingTop || '0px';
                        document.body.setAttribute('data-dm-baha24-original-padding-top', old);
                    }

                    document.body.style.paddingTop = ((parseFloat(old) || 0) + h) + 'px';
                }

                if(document.readyState === 'loading'){
                    document.addEventListener('DOMContentLoaded', applyOffset);
                } else {
                    applyOffset();
                }

                window.addEventListener('resize', applyOffset);
                
                // Re-apply on scroll to ensure sticky stays at top
                window.addEventListener('scroll', applyOffset);
            })();
            </script>
        <?php endif; ?>
        <?php
        return ob_get_clean();
    }
}

DM_Baha24_Topbar_V12::instance();
