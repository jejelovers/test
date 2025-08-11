<?php
/*
Plugin Name: Statistik Desa/Kelurahan (Enhanced with Custom Categories) - FIXED
Description: Plugin untuk menyimpan dan menampilkan data statistik desa/kelurahan dengan form dinamis - Versi dengan kategori dan field custom.
Version: 2.0.1
Author: Kemas Kaisar x Aslamul Fikri

CARA PENGGUNAAN:
1. Aktivasi Plugin: Aktifkan plugin melalui halaman Plugins WordPress
2. Kelola Kategori: Gunakan menu "Statistik Desa" > "Kelola Kategori" untuk menambah kategori dan field custom
3. Input Data: Gunakan menu "Statistik Desa" > "Input Statistik" untuk menambah data
4. Kelola Data: Lihat dan edit data melalui "Daftar Statistik"
5. Tampilkan di Frontend: Gunakan shortcode atau API (lihat dokumentasi)
6. Dokumentasi: Buka "Dokumentasi" untuk panduan lengkap shortcode dan API

SHORTCODE UTAMA:
- [statistic_display] - Tampilan card
- [statistic_table] - Tampilan tabel  
- [statistic_chart] - Tampilan grafik
- [statistic_form] - Form input (admin only)

API ENDPOINTS:
- GET /wp-json/statistic/v1/data - Semua data
- GET /wp-json/statistic/v1/data/{year} - Data per tahun
- GET /wp-json/statistic/v1/data/{year}/{category} - Data spesifik
*/

// Prevent direct access
defined('ABSPATH') or die('No script kiddies please!');

class StatisticPlugin
{
    private $table_name;
    private $categories_table;
    private $fields_table;
    private $version = '2.0.1';

    public function __construct()
    {
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'statistic';
        $this->categories_table = $wpdb->prefix . 'statistic_categories';
        $this->fields_table = $wpdb->prefix . 'statistic_fields';

        // Register hooks
        register_activation_hook(__FILE__, array($this, 'install'));
        add_action('plugins_loaded', array($this, 'init'));

        // Add admin notice for database check
        add_action('admin_notices', array($this, 'check_database_tables'));

        // Add AJAX action for manual database creation
        add_action('wp_ajax_create_statistic_tables', array($this, 'ajax_create_tables'));
    }

    /**
     * Check if database tables exist and show notice if not
     */
    public function check_database_tables()
    {
        global $wpdb;

        // Only show on plugin pages
        $screen = get_current_screen();
        if (!$screen || strpos($screen->id, 'statistic') === false) {
            return;
        }

        // Check if tables exist
        $tables_exist = $this->check_tables_exist();

        if (!$tables_exist) {
            ?>
            <div class="notice notice-error is-dismissible">
                <p><strong>⚠️ Statistik Plugin:</strong> Tabel database belum dibuat.
                    <button type="button" class="button button-primary" onclick="createStatisticTables()"
                        style="margin-left: 10px;">
                        🔧 Buat Tabel Sekarang
                    </button>
                </p>
            </div>
            <script>
                function createStatisticTables() {
                    if (confirm('Apakah Anda yakin ingin membuat tabel database untuk plugin Statistik?')) {
                        fetch(ajaxurl, {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/x-www-form-urlencoded',
                            },
                            body: new URLSearchParams({
                                action: 'create_statistic_tables',
                                nonce: '<?php echo wp_create_nonce('create_tables_nonce'); ?>'
                            })
                        })
                            .then(response => response.json())
                            .then(data => {
                                if (data.success) {
                                    alert('✅ Tabel database berhasil dibuat!');
                                    location.reload();
                                } else {
                                    alert('❌ Error: ' + data.data);
                                }
                            })
                            .catch(error => {
                                console.error('Error:', error);
                                alert('❌ Terjadi kesalahan saat membuat tabel');
                            });
                    }
                }
            </script>
            <?php
        }
    }

    /**
     * Check if all required tables exist
     */
    private function check_tables_exist()
    {
        global $wpdb;

        $tables = array(
            $this->table_name,
            $this->categories_table,
            $this->fields_table
        );

        foreach ($tables as $table) {
            $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table'");
            if ($table_exists != $table) {
                return false;
            }
        }

        return true;
    }

    /**
     * AJAX handler to create tables manually
     */
    public function ajax_create_tables()
    {
        // Verify nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'create_tables_nonce')) {
            wp_send_json_error('Nonce verification failed.');
            return;
        }

        // Check permission
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized access.');
            return;
        }

        try {
            $this->create_database_tables();
            wp_send_json_success('Tabel database berhasil dibuat.');
        } catch (Exception $e) {
            wp_send_json_error('Gagal membuat tabel: ' . $e->getMessage());
        }
    }

    /**
     * Create the custom database tables
     * Membuat tabel database untuk menyimpan data statistik, kategori, dan field custom
     */
    public function install()
    {
        try {
            $this->create_database_tables();
        } catch (Exception $e) {
            // Log error but don't stop activation
            error_log('Statistic Plugin: Failed to create tables during activation - ' . $e->getMessage());
        }
    }

    /**
     * Create database tables
     */
    private function create_database_tables()
    {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();

        // Table untuk data statistik (existing)
        $sql1 = "CREATE TABLE {$this->table_name} (
            id int(11) NOT NULL AUTO_INCREMENT,
            year mediumint NOT NULL,
            category varchar(255) NOT NULL,
            sumber text NULL,
            data longtext NOT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            is_published tinyint(1) DEFAULT 1,
            PRIMARY KEY (id),
            UNIQUE KEY unique_year_category (year, category),
            KEY idx_year (year),
            KEY idx_category (category),
            KEY idx_published (is_published)
        ) $charset_collate;";

        // Table untuk kategori custom (NEW)
        $sql2 = "CREATE TABLE {$this->categories_table} (
            id int(11) NOT NULL AUTO_INCREMENT,
            category_code varchar(255) NOT NULL,
            category_name varchar(255) NOT NULL,
            category_type enum('regular', 'dynamic_rw') DEFAULT 'regular',
            description text NULL,
            is_active tinyint(1) DEFAULT 1,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY unique_category_code (category_code),
            KEY idx_active (is_active)
        ) $charset_collate;";

        // Table untuk field custom (NEW)
        $sql3 = "CREATE TABLE {$this->fields_table} (
            id int(11) NOT NULL AUTO_INCREMENT,
            category_code varchar(255) NOT NULL,
            field_code varchar(255) NOT NULL,
            field_name varchar(255) NOT NULL,
            field_type enum('number', 'text') DEFAULT 'number',
            field_order int(11) DEFAULT 0,
            is_required tinyint(1) DEFAULT 1,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY unique_category_field (category_code, field_code),
            KEY idx_category (category_code),
            KEY idx_order (field_order)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');

        $result1 = dbDelta($sql1);
        $result2 = dbDelta($sql2);
        $result3 = dbDelta($sql3);

        // Check if tables were created successfully
        if (!$this->check_tables_exist()) {
            throw new Exception('Failed to create one or more database tables');
        }

        // Insert default categories if not exists
        $this->insert_default_categories();

        // Update version option
        update_option('statistic_plugin_version', $this->version);
    }

    /**
     * Insert default categories and fields
     * Memasukkan kategori dan field default jika belum ada
     */
    private function insert_default_categories()
    {
        global $wpdb;

        // Check if default categories already exist
        $existing = $wpdb->get_var("SELECT COUNT(*) FROM {$this->categories_table}");
        if ($existing > 0) {
            return; // Already populated
        }

        // Insert default categories
        $default_categories = $this->get_default_categories();
        $default_fields = $this->get_default_category_fields();

        foreach ($default_categories as $code => $name) {
            // Determine category type
            $type = $this->is_dynamic_rw_category($code) ? 'dynamic_rw' : 'regular';

            // Insert category
            $result = $wpdb->insert(
                $this->categories_table,
                array(
                    'category_code' => $code,
                    'category_name' => $name,
                    'category_type' => $type,
                    'description' => 'Kategori default sistem',
                    'is_active' => 1
                ),
                array('%s', '%s', '%s', '%s', '%d')
            );

            if ($result === false) {
                error_log('Failed to insert category: ' . $code . ' - ' . $wpdb->last_error);
                continue;
            }

            // Insert fields for regular categories
            if ($type === 'regular' && isset($default_fields[$code])) {
                $order = 1;
                foreach ($default_fields[$code] as $field_code => $field_name) {
                    $field_result = $wpdb->insert(
                        $this->fields_table,
                        array(
                            'category_code' => $code,
                            'field_code' => $field_code,
                            'field_name' => $field_name,
                            'field_type' => 'number',
                            'field_order' => $order,
                            'is_required' => 1
                        ),
                        array('%s', '%s', '%s', '%s', '%d', '%d')
                    );

                    if ($field_result === false) {
                        error_log('Failed to insert field: ' . $field_code . ' for category: ' . $code . ' - ' . $wpdb->last_error);
                    }

                    $order++;
                }
            }
        }
    }

    /**
     * Initialize the plugin
     * Inisialisasi plugin - mendaftarkan shortcode, menu admin, dan API
     */
    public function init()
    {
        // Check if tables exist before initializing
        if (!$this->check_tables_exist()) {
            // Tables don't exist, limit functionality
            add_action('admin_menu', array($this, 'add_limited_admin_menu'));
            return;
        }

        // Daftarkan shortcode untuk frontend
        add_shortcode('statistic_form', array($this, 'form_shortcode'));
        add_shortcode('statistic_display', array($this, 'display_shortcode'));
        add_shortcode('statistic_table', array($this, 'table_shortcode'));
        add_shortcode('statistic_chart', array($this, 'chart_shortcode'));

        // Daftarkan menu admin
        add_action('admin_menu', array($this, 'add_admin_menu'));

        // Enqueue CSS dan JS untuk frontend
        add_action('wp_enqueue_scripts', array($this, 'enqueue_frontend_scripts'));

        // Enqueue CSS dan JS untuk admin
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));

        // AJAX actions untuk form submission dan delete
        add_action('wp_ajax_statistic_form', array($this, 'handle_form_submission'));
        add_action('wp_ajax_nopriv_statistic_form', array($this, 'handle_form_submission'));
        add_action('wp_ajax_statistic_delete', array($this, 'handle_delete'));

        // AJAX actions untuk kategori dan field management (NEW)
        add_action('wp_ajax_save_category', array($this, 'handle_save_category'));
        add_action('wp_ajax_delete_category', array($this, 'handle_delete_category'));
        add_action('wp_ajax_save_field', array($this, 'handle_save_field'));
        add_action('wp_ajax_delete_field', array($this, 'handle_delete_field'));
        add_action('wp_ajax_get_category_fields', array($this, 'handle_get_category_fields'));

        // REST API endpoints
        add_action('rest_api_init', array($this, 'register_rest_routes'));
    }

    /**
     * Add limited admin menu when tables don't exist
     */
    public function add_limited_admin_menu()
    {
        add_menu_page(
            'Statistik Desa - Setup Required',
            'Statistik Desa',
            'manage_options',
            'statistic',
            array($this, 'admin_setup_page'),
            'dashicons-chart-bar',
            50
        );
    }

    /**
     * Setup page when tables don't exist
     */
    public function admin_setup_page()
    {
        ?>
        <div class="wrap">
            <h1>🔧 Setup Plugin Statistik Desa/Kelurahan</h1>
            <div class="notice notice-warning">
                <p><strong>⚠️ Setup Diperlukan:</strong> Tabel database untuk plugin ini belum dibuat.</p>
            </div>
            <div class="card" style="max-width: 600px;">
                <h2>Langkah Setup:</h2>
                <ol>
                    <li>Klik tombol "Buat Tabel Database" di bawah ini</li>
                    <li>Tunggu hingga proses selesai</li>
                    <li>Refresh halaman untuk mengakses fitur lengkap</li>
                </ol>
                <p>
                    <button type="button" class="button button-primary button-large" onclick="createStatisticTables()">
                        🔧 Buat Tabel Database
                    </button>
                </p>
            </div>
            <script>
                function createStatisticTables() {
                    if (confirm('Apakah Anda yakin ingin membuat tabel database untuk plugin Statistik?')) {
                        const button = event.target;
                        button.disabled = true;
                        button.textContent = '⏳ Membuat tabel...';

                        fetch(ajaxurl, {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/x-www-form-urlencoded',
                            },
                            body: new URLSearchParams({
                                action: 'create_statistic_tables',
                                nonce: '<?php echo wp_create_nonce('create_tables_nonce'); ?>'
                            })
                        })
                            .then(response => response.json())
                            .then(data => {
                                if (data.success) {
                                    alert('✅ Tabel database berhasil dibuat!');
                                    location.reload();
                                } else {
                                    alert('❌ Error: ' + data.data);
                                    button.disabled = false;
                                    button.textContent = '🔧 Buat Tabel Database';
                                }
                            })
                            .catch(error => {
                                console.error('Error:', error);
                                alert('❌ Terjadi kesalahan saat membuat tabel');
                                button.disabled = false;
                                button.textContent = '🔧 Buat Tabel Database';
                            });
                    }
                }
            </script>
        </div>
        <?php
    }

    /**
     * Register REST API routes
     * Mendaftarkan endpoint API untuk akses data via REST
     */
    public function register_rest_routes()
    {
        // Endpoint untuk semua data
        register_rest_route('statistic/v1', '/data', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_statistics_api'),
            'permission_callback' => '__return_true',
        ));

        // Endpoint untuk data berdasarkan tahun
        register_rest_route('statistic/v1', '/data/(?P<year>\d+)', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_statistics_by_year_api'),
            'permission_callback' => '__return_true',
        ));

        // Endpoint untuk data spesifik (tahun + kategori)
        register_rest_route('statistic/v1', '/data/(?P<year>\d+)/(?P<category>[a-zA-Z0-9_-]+)', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_specific_statistic_api'),
            'permission_callback' => '__return_true',
        ));
    }

    /**
     * API endpoint to get all statistics
     * Endpoint API untuk mengambil semua data statistik dengan filter
     */
    public function get_statistics_api($request)
    {
        global $wpdb;
        // Ambil parameter dari request
        $published_only = $request->get_param('published') !== 'false';
        $year = $request->get_param('year');
        $category = $request->get_param('category');

        // Build WHERE conditions
        $where_conditions = array();
        $where_values = array();

        if ($published_only) {
            $where_conditions[] = 'is_published = %d';
            $where_values[] = 1;
        }

        if ($year) {
            $where_conditions[] = 'year = %d';
            $where_values[] = intval($year);
        }

        if ($category) {
            $where_conditions[] = 'category = %s';
            $where_values[] = sanitize_text_field($category);
        }

        $where_clause = '';
        if (!empty($where_conditions)) {
            $where_clause = 'WHERE ' . implode(' AND ', $where_conditions);
        }

        $query = "SELECT * FROM {$this->table_name} {$where_clause} ORDER BY year DESC, category ASC";

        // Execute query dengan atau tanpa parameter
        if (!empty($where_values)) {
            $results = $wpdb->get_results($wpdb->prepare($query, $where_values));
        } else {
            $results = $wpdb->get_results($query);
        }

        // Format hasil untuk API response
        $formatted_results = array();
        foreach ($results as $row) {
            $formatted_results[] = array(
                'id' => $row->id,
                'year' => $row->year,
                'category' => $row->category,
                'category_name' => $this->get_categories()[$row->category] ?? $row->category,
                'sumber' => $row->sumber,
                'data' => json_decode($row->data, true),
                'is_published' => (bool) $row->is_published,
                'created_at' => $row->created_at,
                'updated_at' => $row->updated_at
            );
        }

        return rest_ensure_response($formatted_results);
    }

    /**
     * API endpoint to get statistics by year
     * Endpoint API untuk data berdasarkan tahun
     */
    public function get_statistics_by_year_api($request)
    {
        $year = $request->get_param('year');
        $request->set_param('year', $year);
        return $this->get_statistics_api($request);
    }

    /**
     * API endpoint to get specific statistic
     * Endpoint API untuk data spesifik (tahun + kategori)
     */
    public function get_specific_statistic_api($request)
    {
        $year = $request->get_param('year');
        $category = $request->get_param('category');
        $request->set_param('year', $year);
        $request->set_param('category', $category);
        $results = $this->get_statistics_api($request);
        $data = $results->get_data();

        if (empty($data)) {
            return new WP_Error('not_found', 'Data tidak ditemukan', array('status' => 404));
        }

        return rest_ensure_response($data[0]);
    }

    /**
     * Shortcode for public form
     * Shortcode untuk menampilkan form input di frontend (hanya admin)
     */
    public function form_shortcode($atts)
    {
        if (!current_user_can('manage_options')) {
            return '<p>Anda tidak memiliki izin untuk mengakses form ini.</p>';
        }

        ob_start();
        $this->render_public_form();
        return ob_get_clean();
    }

    /**
     * Shortcode for displaying statistics
     * Shortcode untuk menampilkan data dalam format card
     */
    public function display_shortcode($atts)
    {
        $atts = shortcode_atts(array(
            'year' => '',
            'category' => '',
            'published_only' => 'true',
            'show_source' => 'true',
            'show_year' => 'true'
        ), $atts);

        ob_start();
        $this->render_public_display($atts);
        return ob_get_clean();
    }

    /**
     * Shortcode for displaying statistics table
     * Shortcode untuk menampilkan data dalam format tabel
     */
    public function table_shortcode($atts)
    {
        $atts = shortcode_atts(array(
            'year' => '',
            'category' => '',
            'published_only' => 'true',
            'show_source' => 'true',
            'limit' => '10'
        ), $atts);

        ob_start();
        $this->render_statistics_table($atts);
        return ob_get_clean();
    }

    /**
     * Shortcode for displaying statistics chart
     * Shortcode untuk menampilkan data dalam format grafik
     */
    public function chart_shortcode($atts)
    {
        $atts = shortcode_atts(array(
            'year' => date('Y'),
            'category' => '',
            'type' => 'bar', // bar, pie, line, horizontalBar
            'height' => '400'
        ), $atts);

        ob_start();
        $this->render_statistics_chart($atts);
        return ob_get_clean();
    }

    /**
     * Render public display
     * Render tampilan card untuk data statistik
     */
    private function render_public_display($atts)
    {
        global $wpdb;

        // Build query berdasarkan parameter
        $where_conditions = array();
        $where_values = array();

        if ($atts['published_only'] === 'true') {
            $where_conditions[] = 'is_published = %d';
            $where_values[] = 1;
        }

        if (!empty($atts['year'])) {
            $where_conditions[] = 'year = %d';
            $where_values[] = intval($atts['year']);
        }

        if (!empty($atts['category'])) {
            $where_conditions[] = 'category = %s';
            $where_values[] = sanitize_text_field($atts['category']);
        }

        $where_clause = '';
        if (!empty($where_conditions)) {
            $where_clause = 'WHERE ' . implode(' AND ', $where_conditions);
        }

        $query = "SELECT * FROM {$this->table_name} {$where_clause} ORDER BY year DESC, category ASC";

        if (!empty($where_values)) {
            $results = $wpdb->get_results($wpdb->prepare($query, $where_values));
        } else {
            $results = $wpdb->get_results($query);
        }

        if (empty($results)) {
            echo '<div class="alert alert-info">Data statistik tidak tersedia.</div>';
            return;
        }

        echo '<div class="statistic-display">';
        foreach ($results as $row) {
            $category_name = $this->get_categories()[$row->category] ?? $row->category;
            $data = json_decode($row->data, true);

            echo '<div class="card mb-4">';
            echo '<div class="card-header">';
            echo '<h4 class="mb-0">' . esc_html($category_name);
            if ($atts['show_year'] === 'true') {
                echo ' - ' . esc_html($row->year);
            }
            echo '</h4>';
            echo '</div>';
            echo '<div class="card-body">';

            // Cek apakah kategori menggunakan RW dinamis
            if ($this->is_dynamic_rw_category($row->category)) {
                // Display RW data
                echo '<div class="row">';
                foreach ($data as $key => $value) {
                    if (strpos($key, 'rw_') === 0) {
                        $rw_number = str_replace('rw_', '', $key);
                        echo '<div class="col-md-3 mb-2">';
                        echo '<div class="card text-center">';
                        echo '<div class="card-body">';
                        echo '<h5 class="card-title">RW ' . esc_html($rw_number) . '</h5>';
                        echo '<p class="card-text display-6">' . esc_html($value) . '</p>';
                        echo '</div>';
                        echo '</div>';
                        echo '</div>';
                    }
                }
                echo '</div>';
            } else {
                // Display regular category data
                echo '<div class="row">';
                foreach ($data as $key => $value) {
                    $field_label = $this->get_category_fields()[$row->category][$key] ?? $key;
                    echo '<div class="col-md-4 mb-2">';
                    echo '<div class="card text-center">';
                    echo '<div class="card-body">';
                    echo '<h6 class="card-title">' . esc_html($field_label) . '</h6>';
                    echo '<p class="card-text display-6">' . esc_html($value) . '</p>';
                    echo '</div>';
                    echo '</div>';
                    echo '</div>';
                }
                echo '</div>';
            }

            // Tampilkan sumber jika diminta
            if ($atts['show_source'] === 'true' && !empty($row->sumber)) {
                echo '<div class="mt-3">';
                echo '<small class="text-muted">Sumber: ' . esc_html($row->sumber) . '</small>';
                echo '</div>';
            }

            echo '</div>';
            echo '</div>';
        }
        echo '</div>';
    }

    /**
     * Render statistics table
     * Render tampilan tabel untuk data statistik
     */
    private function render_statistics_table($atts)
    {
        global $wpdb;

        // Build query berdasarkan parameter
        $where_conditions = array();
        $where_values = array();

        if ($atts['published_only'] === 'true') {
            $where_conditions[] = 'is_published = %d';
            $where_values[] = 1;
        }

        if (!empty($atts['year'])) {
            $where_conditions[] = 'year = %d';
            $where_values[] = intval($atts['year']);
        }

        if (!empty($atts['category'])) {
            $where_conditions[] = 'category = %s';
            $where_values[] = sanitize_text_field($atts['category']);
        }

        $where_clause = '';
        if (!empty($where_conditions)) {
            $where_clause = 'WHERE ' . implode(' AND ', $where_conditions);
        }

        $limit = intval($atts['limit']);
        $limit_clause = $limit > 0 ? "LIMIT {$limit}" : '';

        $query = "SELECT * FROM {$this->table_name} {$where_clause} ORDER BY year DESC, category ASC {$limit_clause}";

        if (!empty($where_values)) {
            $results = $wpdb->get_results($wpdb->prepare($query, $where_values));
        } else {
            $results = $wpdb->get_results($query);
        }

        if (empty($results)) {
            echo '<div class="alert alert-info">Data statistik tidak tersedia.</div>';
            return;
        }

        // Render each result as a separate simple table
        foreach ($results as $row) {
            $category_name = $this->get_categories()[$row->category] ?? $row->category;
            $data = json_decode($row->data, true);

            if (empty($data)) {
                continue;
            }

            echo '<div class="statistic-table-wrapper mb-4">';
            // Table title
            echo '<h4 class="table-title">' . esc_html($category_name) . ' - ' . esc_html($row->year) . '</h4>';

            echo '<div class="table-responsive">';
            echo '<table class="table table-striped table-bordered">';
            echo '<thead class="table-light">';
            echo '<tr>';
            echo '<th style="background-color: #f8f9fa; font-weight: 600;">Kategori</th>';
            echo '<th style="background-color: #f8f9fa; font-weight: 600;">Jumlah</th>';
            echo '</tr>';
            echo '</thead>';
            echo '<tbody>';

            // Display data based on category type
            if ($this->is_dynamic_rw_category($row->category)) {
                // For RW categories, show RW data
                foreach ($data as $key => $value) {
                    if (strpos($key, 'rw_') === 0) {
                        $rw_number = str_replace('rw_', '', $key);
                        echo '<tr>';
                        echo '<td>RW ' . esc_html($rw_number) . '</td>';
                        echo '<td>' . esc_html($value) . '</td>';
                        echo '</tr>';
                    }
                }
            } else {
                // For regular categories, show field data
                foreach ($data as $key => $value) {
                    $field_label = $this->get_category_fields()[$row->category][$key] ?? $key;
                    echo '<tr>';
                    echo '<td>' . esc_html($field_label) . '</td>';
                    echo '<td>' . esc_html($value) . '</td>';
                    echo '</tr>';
                }
            }

            echo '</tbody>';
            echo '</table>';
            echo '</div>';

            // Show source if available and requested
            if ($atts['show_source'] === 'true' && !empty($row->sumber)) {
                echo '<p class="table-source"><small class="text-muted">Sumber: ' . esc_html($row->sumber) . '</small></p>';
            }

            echo '</div>';
        }

        // Add custom CSS for better styling
        echo '<style>
            .statistic-table-wrapper {
                background: #fff;
                border-radius: 8px;
                box-shadow: 0 2px 4px rgba(0,0,0,0.1);
                padding: 20px;
                margin-bottom: 20px;
            }
            
            .table-title {
                margin: 0 0 15px 0;
                color: #333;
                font-size: 18px;
                font-weight: 600;
                padding-bottom: 10px;
                border-bottom: 2px solid #f8f9fa;
            }
            
            .statistic-table-wrapper .table {
                margin-bottom: 0;
                border: 1px solid #dee2e6;
            }
            
            .statistic-table-wrapper .table th {
                border-bottom: 2px solid #dee2e6;
                font-size: 14px;
                padding: 12px;
            }
            
            .statistic-table-wrapper .table td {
                padding: 10px 12px;
                font-size: 14px;
                vertical-align: middle;
            }
            
            .statistic-table-wrapper .table tbody tr:nth-child(even) {
                background-color: #f8f9fa;
            }
            
            .statistic-table-wrapper .table tbody tr:hover {
                background-color: #e9ecef;
            }
            
            .table-source {
                margin: 15px 0 0 0;
                padding-top: 10px;
                border-top: 1px solid #f8f9fa;
            }
            
            @media (max-width: 768px) {
                .statistic-table-wrapper {
                    padding: 15px;
                }
                
                .table-title {
                    font-size: 16px;
                }
                
                .statistic-table-wrapper .table th,
                .statistic-table-wrapper .table td {
                    padding: 8px;
                    font-size: 13px;
                }
            }
        </style>';
    }

    /**
     * Render statistics chart
     * Render tampilan grafik untuk data statistik
     */
    private function render_statistics_chart($atts)
    {
        global $wpdb;

        $year = intval($atts['year']);
        $category = sanitize_text_field($atts['category']);
        $chart_type = sanitize_text_field($atts['type']);
        $height = intval($atts['height']);

        if (empty($category)) {
            echo '<div class="alert alert-warning">Parameter kategori diperlukan untuk menampilkan chart.</div>';
            return;
        }

        $result = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table_name} WHERE year = %d AND category = %s AND is_published = 1",
            $year,
            $category
        ));

        if (!$result) {
            echo '<div class="alert alert-info">Data tidak tersedia untuk tahun ' . $year . ' kategori ' . $category . '.</div>';
            return;
        }

        $data = json_decode($result->data, true);
        $category_name = $this->get_categories()[$result->category] ?? $result->category;

        $chart_id = 'chart_' . uniqid();

        echo '<div class="card">';
        echo '<div class="card-header">';
        echo '<h5 class="mb-0">' . esc_html($category_name) . ' - ' . esc_html($year) . '</h5>';
        echo '</div>';
        echo '<div class="card-body">';
        echo '<canvas id="' . $chart_id . '" height="' . $height . '"></canvas>';
        echo '</div>';
        echo '</div>';

        // Prepare data for Chart.js
        $labels = array();
        $values = array();

        if ($this->is_dynamic_rw_category($result->category)) {
            foreach ($data as $key => $value) {
                if (strpos($key, 'rw_') === 0) {
                    $rw_number = str_replace('rw_', '', $key);
                    $labels[] = 'RW ' . $rw_number;
                    $values[] = intval($value);
                }
            }
        } else {
            foreach ($data as $key => $value) {
                $field_label = $this->get_category_fields()[$result->category][$key] ?? $key;
                $labels[] = $field_label;
                $values[] = intval($value);
            }
        }

        // Warna yang sesuai dengan gambar - biru muda dan ungu
        $background_colors = array(
            'rgba(52, 152, 219, 0.8)',  // Biru muda (background)
            'rgba(88, 83, 201, 1)',     // Ungu (background)
            'rgba(46, 204, 113, 0.8)',  // Hijau (background)
            'rgba(241, 196, 15, 0.8)',  // Kuning (background)
            'rgba(231, 76, 60, 0.8)',   // Merah (background)
            'rgba(230, 126, 34, 0.8)',  // Orange (background)
            'rgba(155, 89, 182, 0.8)',  // Ungu muda (background)
            'rgba(52, 73, 94, 0.8)',    // Abu-abu gelap (background)
            'rgba(26, 188, 156, 0.8)',  // Tosca (background)
            'rgba(243, 156, 18, 0.8)'   // Orange gelap (background)
        );

        $border_colors = array(
            'rgba(52, 152, 219, 1)',    // Biru muda (border)
            'rgba(88, 83, 201, 1)',     // Ungu (border)
            'rgba(46, 204, 113, 1)',    // Hijau (border)
            'rgba(241, 196, 15, 1)',    // Kuning (border)
            'rgba(231, 76, 60, 1)',     // Merah (border)
            'rgba(230, 126, 34, 1)',    // Orange (border)
            'rgba(155, 89, 182, 1)',    // Ungu muda (border)
            'rgba(52, 73, 94, 1)',      // Abu-abu gelap (border)
            'rgba(26, 188, 156, 1)',    // Tosca (border)
            'rgba(243, 156, 18, 1)'     // Orange gelap (border)
        );

        ?>
        <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
        <script>
            document.addEventListener('DOMContentLoaded', function () {
                const ctx = document.getElementById('<?php echo $chart_id; ?>').getContext('2d');
                const chartType = '<?php echo $chart_type; ?>' === 'horizontalBar' ? 'bar' : '<?php echo $chart_type; ?>';
                const chart = new Chart(ctx, {
                    type: chartType,
                    data: {
                        labels: <?php echo json_encode($labels); ?>,
                        datasets: [{
                            label: '<?php echo esc_js($category_name); ?>',
                            data: <?php echo json_encode($values); ?>,
                            backgroundColor: <?php echo json_encode($background_colors); ?>,
                            borderColor: <?php echo json_encode($border_colors); ?>,
                            borderWidth: 2,
                            borderRadius: 4,
                            borderSkipped: false,
                        }]
                    },
                    options: {
                        responsive: true,
                        indexAxis: '<?php echo $chart_type === 'horizontalBar' ? 'y' : 'x'; ?>',
                        plugins: {
                            title: {
                                display: true,
                                text: '<?php echo esc_js($category_name . " - " . $year); ?>',
                                font: {
                                    size: 16,
                                    weight: 'bold'
                                },
                                color: '#2c3e50'
                            },
                            legend: {
                                display: true,
                                labels: {
                                    color: '#2c3e50',
                                    font: {
                                        size: 12
                                    }
                                }
                            }
                        },
                        scales: <?php echo $chart_type !== 'pie' ? '{
                        ' . ($chart_type === 'horizontalBar' ? 'x' : 'y') . ': {
                            beginAtZero: true,
                            ticks: {
                                color: "#2c3e50"
                            },
                            grid: {
                                color: "rgba(0,0,0,0.1)"
                            }
                        },
                        ' . ($chart_type === 'horizontalBar' ? 'y' : 'x') . ': {
                            ticks: {
                                color: "#2c3e50"
                            },
                            grid: {
                                color: "rgba(0,0,0,0.1)"
                            }
                        }
                    }' : '{}'; ?>
                    }
                });
            });
        </script>
        <?php
    }

    /**
     * Render public form
     * Render form input untuk admin - disesuaikan dengan gambar
     */
    private function render_public_form($edit_data = null)
    {
        $is_edit = !is_null($edit_data);
        $form_title = $is_edit ? 'Edit Statistik Desa/Kelurahan' : 'Form Input Statistik Desa/Kelurahan';
        $button_text = $is_edit ? 'Update Data' : 'Simpan Data';

        ?>
        <div class="statistic-form-container">
            <style>
                .statistic-form-container {
                    max-width: 600px;
                    margin: 20px auto;
                    padding: 20px;
                    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
                }

                .statistic-form-card {
                    background: #ffffff;
                    border-radius: 8px;
                    box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
                    overflow: hidden;
                    border: 1px solid #ddd;
                }

                .statistic-form-header {
                    background: #f8f9fa;
                    padding: 20px;
                    border-bottom: 1px solid #ddd;
                }

                .statistic-form-header h2 {
                    margin: 0;
                    font-size: 18px;
                    font-weight: 600;
                    color: #333;
                }

                .statistic-form-body {
                    padding: 20px;
                }

                .form-group {
                    margin-bottom: 20px;
                }

                .form-label {
                    display: block;
                    margin-bottom: 8px;
                    font-size: 14px;
                    font-weight: 500;
                    color: #333;
                }

                .form-control,
                .form-select {
                    width: 100%;
                    padding: 10px 12px;
                    font-size: 14px;
                    line-height: 1.5;
                    color: #495057;
                    background-color: #fff;
                    border: 1px solid #ced4da;
                    border-radius: 4px;
                    transition: border-color 0.15s ease-in-out, box-shadow 0.15s ease-in-out;
                    box-sizing: border-box;
                }

                .form-control:focus,
                .form-select:focus {
                    outline: none;
                    border-color: #007bff;
                    box-shadow: 0 0 0 2px rgba(0, 123, 255, 0.25);
                }

                .form-select {
                    background-image: url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' fill='none' viewBox='0 0 20 20'%3e%3cpath stroke='%236b7280' stroke-linecap='round' stroke-linejoin='round' stroke-width='1.5' d='m6 8 4 4 4-4'/%3e%3c/svg%3e");
                    background-position: right 8px center;
                    background-repeat: no-repeat;
                    background-size: 16px 12px;
                    padding-right: 32px;
                    appearance: none;
                }

                .category-section {
                    background: #f8f9fa;
                    border: 1px solid #dee2e6;
                    border-radius: 4px;
                    padding: 15px;
                    margin-bottom: 20px;
                }

                .category-section h3 {
                    margin: 0 0 15px 0;
                    font-size: 16px;
                    font-weight: 600;
                    color: #333;
                }

                .field-group {
                    margin-bottom: 15px;
                }

                .field-group:last-child {
                    margin-bottom: 0;
                }

                .field-group .form-label {
                    margin-bottom: 5px;
                    font-size: 13px;
                    color: #666;
                }

                .checkbox-group {
                    display: flex;
                    align-items: center;
                    gap: 8px;
                    margin-bottom: 20px;
                }

                .form-check-input {
                    width: 16px;
                    height: 16px;
                    border: 1px solid #ced4da;
                    border-radius: 3px;
                    background-color: #fff;
                    cursor: pointer;
                }

                .form-check-input:checked {
                    background-color: #007bff;
                    border-color: #007bff;
                }

                .form-check-label {
                    font-size: 14px;
                    color: #333;
                    cursor: pointer;
                    user-select: none;
                }

                .btn-primary {
                    background-color: #007bff;
                    border: 1px solid #007bff;
                    color: #fff;
                    padding: 10px 20px;
                    font-size: 14px;
                    font-weight: 500;
                    border-radius: 4px;
                    cursor: pointer;
                    transition: all 0.15s ease-in-out;
                    text-decoration: none;
                    display: inline-block;
                    text-align: center;
                }

                .btn-primary:hover {
                    background-color: #0056b3;
                    border-color: #0056b3;
                }

                .btn-secondary {
                    background-color: #6c757d;
                    border: 1px solid #6c757d;
                    color: #fff;
                    padding: 10px 20px;
                    font-size: 14px;
                    font-weight: 500;
                    border-radius: 4px;
                    cursor: pointer;
                    transition: all 0.15s ease-in-out;
                    text-decoration: none;
                    display: inline-block;
                    text-align: center;
                    margin-right: 10px;
                }

                .btn-secondary:hover {
                    background-color: #545b62;
                    border-color: #545b62;
                }

                .form-actions {
                    display: flex;
                    justify-content: flex-end;
                    align-items: center;
                    margin-top: 20px;
                    padding-top: 15px;
                    border-top: 1px solid #dee2e6;
                }

                .rw-container-wrapper {
                    background: #f8f9fa;
                    border: 1px solid #dee2e6;
                    border-radius: 4px;
                    padding: 15px;
                    margin-bottom: 15px;
                }

                .rw-item {
                    background: #fff;
                    border: 1px solid #dee2e6;
                    border-radius: 4px;
                    padding: 12px;
                    margin-bottom: 10px;
                    display: flex;
                    justify-content: space-between;
                    align-items: center;
                }

                .rw-item:last-child {
                    margin-bottom: 0;
                }

                .rw-content {
                    flex: 1;
                }

                .rw-header {
                    font-weight: 600;
                    color: #333;
                    margin-bottom: 8px;
                    font-size: 14px;
                }

                .btn-danger {
                    background-color: #dc3545;
                    border: 1px solid #dc3545;
                    color: #fff;
                    padding: 5px 10px;
                    font-size: 12px;
                    border-radius: 3px;
                    cursor: pointer;
                    transition: all 0.15s ease-in-out;
                }

                .btn-danger:hover {
                    background-color: #c82333;
                    border-color: #c82333;
                }

                .btn-success {
                    background-color: #28a745;
                    border: 1px solid #28a745;
                    color: #fff;
                    padding: 8px 15px;
                    font-size: 14px;
                    font-weight: 500;
                    border-radius: 4px;
                    cursor: pointer;
                    transition: all 0.15s ease-in-out;
                    margin-bottom: 15px;
                }

                .btn-success:hover {
                    background-color: #218838;
                    border-color: #218838;
                }

                /* Hide category fields initially */
                .category-field {
                    display: none;
                }

                @media (max-width: 768px) {
                    .statistic-form-container {
                        margin: 10px;
                        padding: 15px;
                    }

                    .statistic-form-body {
                        padding: 15px;
                    }

                    .form-actions {
                        flex-direction: column-reverse;
                        gap: 10px;
                    }

                    .btn-secondary {
                        margin-right: 0;
                        width: 100%;
                    }

                    .btn-primary {
                        width: 100%;
                    }
                }
            </style>

            <div class="statistic-form-card">
                <div class="statistic-form-header">
                    <h2><?php echo esc_html($form_title); ?></h2>
                </div>
                <div class="statistic-form-body">
                    <form id="statistic-public-form" method="post">
                        <?php wp_nonce_field('statistic_nonce_action', 'statistic_nonce'); ?>
                        <?php if ($is_edit): ?>
                            <input type="hidden" name="edit_mode" value="1">
                            <input type="hidden" name="original_year" value="<?php echo esc_attr($edit_data->year); ?>">
                            <input type="hidden" name="original_category" value="<?php echo esc_attr($edit_data->category); ?>">
                        <?php endif; ?>

                        <!-- Tahun Field -->
                        <div class="form-group">
                            <label for="year" class="form-label">Tahun:</label>
                            <select name="year" id="year" required class="form-select" <?php echo $is_edit ? 'disabled' : ''; ?>>
                                <?php foreach ($this->get_year_options() as $key => $val): ?>
                                    <option value="<?php echo esc_attr($key); ?>" <?php selected($is_edit ? $edit_data->year : '', $key); ?>>
                                        <?php echo esc_html($val); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <?php if ($is_edit): ?>
                                <input type="hidden" name="year" value="<?php echo esc_attr($edit_data->year); ?>">
                            <?php endif; ?>
                        </div>

                        <!-- Kategori Field -->
                        <div class="form-group">
                            <label for="category" class="form-label">Kategori:</label>
                            <select name="category" id="category" required class="form-select" <?php echo $is_edit ? 'disabled' : ''; ?>>
                                <option value="">Pilih Kategori</option>
                                <?php foreach ($this->get_categories() as $key => $val): ?>
                                    <option value="<?php echo esc_attr($key); ?>" <?php selected($is_edit ? $edit_data->category : '', $key); ?>>
                                        <?php echo esc_html($val); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <?php if ($is_edit): ?>
                                <input type="hidden" name="category" value="<?php echo esc_attr($edit_data->category); ?>">
                            <?php endif; ?>
                        </div>

                        <!-- Sumber Data Field -->
                        <div class="form-group">
                            <label for="sumber" class="form-label">Sumber Data:</label>
                            <input type="text" id="sumber" name="sumber" class="form-control" placeholder="Masukkan sumber data"
                                value="<?php echo $is_edit ? esc_attr($edit_data->sumber) : ''; ?>" />
                        </div>

                        <div id="dynamic-fields">
                            <?php echo $this->generate_category_fields($is_edit ? $edit_data->category : '', $is_edit ? $edit_data : null); ?>
                        </div>

                        <!-- Tampilkan di Publik Checkbox -->
                        <div class="checkbox-group">
                            <input type="checkbox" name="is_published" value="1" <?php echo ($is_edit ? ($edit_data->is_published ? 'checked' : '') : 'checked'); ?> class="form-check-input"
                                id="is_published">
                            <label class="form-check-label" for="is_published">
                                Tampilkan di publik
                            </label>
                        </div>

                        <div class="form-actions">
                            <?php if ($is_edit): ?>
                                <a href="<?php echo admin_url('admin.php?page=statistic-list'); ?>" class="btn-secondary">Batal</a>
                            <?php endif; ?>
                            <button type="submit" class="btn-primary"><?php echo esc_html($button_text); ?></button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <script>
            document.addEventListener('DOMContentLoaded', function () {
                const categorySelect = document.getElementById('category');
                const dynamicFields = document.getElementById('dynamic-fields');
                const isEdit = <?php echo $is_edit ? 'true' : 'false'; ?>;

                // Function untuk menampilkan field berdasarkan kategori
                function showCategoryFields(category) {
                    const fields = dynamicFields.querySelectorAll('.category-field');
                    fields.forEach(field => field.style.display = 'none');

                    if (category) {
                        const selectedField = dynamicFields.querySelector('#fields-' + category);
                        if (selectedField) {
                            selectedField.style.display = 'block';
                        }
                    }
                }

                // Show appropriate fields on page load
                if (isEdit) {
                    showCategoryFields(categorySelect.value);
                }

                categorySelect.addEventListener('change', function () {
                    if (!isEdit) {
                        // Load fields dynamically for new categories
                        loadCategoryFields(this.value);
                    }
                    showCategoryFields(this.value);
                });

                // Function untuk load field kategori via AJAX
                function loadCategoryFields(category) {
                    if (!category) return;

                    fetch(ajaxurl, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded',
                        },
                        body: new URLSearchParams({
                            action: 'get_category_fields',
                            category: category,
                            nonce: '<?php echo wp_create_nonce('get_category_fields_nonce'); ?>'
                        })
                    })
                        .then(response => response.json())
                        .then(data => {
                            if (data.success) {
                                // Update dynamic fields
                                const existingField = document.getElementById('fields-' + category);
                                if (existingField) {
                                    existingField.innerHTML = data.data.html;
                                } else {
                                    const fieldDiv = document.createElement('div');
                                    fieldDiv.id = 'fields-' + category;
                                    fieldDiv.className = 'category-field';
                                    fieldDiv.innerHTML = data.data.html;
                                    dynamicFields.appendChild(fieldDiv);
                                }
                            }
                        })
                        .catch(error => {
                            console.error('Error loading category fields:', error);
                        });
                }

                // Function untuk menambah RW baru
                window.addRW = function (category) {
                    const container = document.getElementById('rw-container-' + category);
                    const rwCount = container.querySelectorAll('.rw-item').length + 1;
                    const rwDiv = document.createElement('div');
                    rwDiv.className = 'rw-item';
                    rwDiv.innerHTML = `
                    <div class="rw-content">
                        <div class="rw-header">RW ${rwCount}</div>
                        <div class="field-group">
                            <label class="form-label">Jumlah Penerima RW ${rwCount}:</label>
                            <input type="number" name="${category}_rw_${rwCount}" min="0" step="1" class="form-control" />
                        </div>
                    </div>
                    <button type="button" class="btn-danger" onclick="removeRW(this)">Hapus</button>
                `;
                    container.appendChild(rwDiv);
                };

                // Function untuk menghapus RW
                window.removeRW = function (button) {
                    const rwItem = button.closest('.rw-item');
                    const parentContainer = rwItem.parentNode;
                    rwItem.remove();

                    // Renumber remaining RW containers
                    const remainingItems = parentContainer.querySelectorAll('.rw-item');
                    remainingItems.forEach((item, index) => {
                        const rwNumber = index + 1;
                        const header = item.querySelector('.rw-header');
                        const label = item.querySelector('label');
                        const input = item.querySelector('input');

                        header.textContent = `RW ${rwNumber}`;
                        label.textContent = `Jumlah Penerima RW ${rwNumber}:`;

                        // Update input name
                        const oldName = input.name;
                        const category = oldName.split('_')[0];
                        input.name = `${category}_rw_${rwNumber}`;
                    });
                };

                // Handle form submission
                document.getElementById('statistic-public-form').addEventListener('submit', function (e) {
                    e.preventDefault();

                    const formData = new FormData(this);
                    formData.append('action', 'statistic_form');

                    fetch(ajaxurl, {
                        method: 'POST',
                        body: formData
                    })
                        .then(response => response.text())
                        .then(data => {
                            alert(data);
                            if (data.includes('berhasil')) {
                                if (isEdit) {
                                    window.location.href = '<?php echo admin_url('admin.php?page=statistic-list'); ?>';
                                } else {
                                    this.reset();
                                    showCategoryFields('');
                                }
                            }
                        })
                        .catch(error => {
                            console.error('Error:', error);
                            alert('Terjadi kesalahan saat menyimpan data');
                        });
                });
            });
        </script>
        <?php
    }

    /**
     * Generate category specific fields based on selected category
     * Generate field form berdasarkan kategori yang dipilih - disesuaikan dengan gambar
     */
    private function generate_category_fields($selected_category = '', $edit_data = null)
    {
        $fields_html = '';
        $category_fields = $this->get_category_fields();
        $existing_data = array();

        // Parse existing data if editing
        if ($edit_data && !empty($edit_data->data)) {
            $existing_data = json_decode($edit_data->data, true) ?: array();
        }

        foreach ($category_fields as $category => $fields) {
            $display_style = ($selected_category == $category) ? 'block' : 'none';
            $fields_html .= '<div id="fields-' . $category . '" class="category-field" style="display: ' . $display_style . ';">';
            $fields_html .= '<div class="category-section">';
            $fields_html .= '<h3>' . esc_html($this->get_categories()[$category] ?? $category) . '</h3>';

            // Check if this is a dynamic RW category
            if ($this->is_dynamic_rw_category($category)) {
                $fields_html .= $this->generate_dynamic_rw_fields($category, $existing_data);
            } else {
                // Generate regular form fields for each category
                foreach ($fields as $field_key => $field_label) {
                    $field_name = $category . '_' . $field_key;
                    $field_value = isset($existing_data[$field_key]) ? $existing_data[$field_key] : '';

                    $fields_html .= '<div class="field-group">';
                    $fields_html .= '<label for="' . esc_attr($field_name) . '" class="form-label">' . esc_html($field_label) . ':</label>';
                    $fields_html .= '<input type="number" id="' . esc_attr($field_name) . '" name="' . esc_attr($field_name) . '" min="0" step="1" class="form-control" value="' . esc_attr($field_value) . '" />';
                    $fields_html .= '</div>';
                }
            }

            $fields_html .= '</div>';
            $fields_html .= '</div>';
        }

        return $fields_html;
    }

    /**
     * Check if category uses dynamic RW fields
     * Cek apakah kategori menggunakan field RW dinamis
     */
    private function is_dynamic_rw_category($category)
    {
        global $wpdb;

        // Check from database first
        $category_type = $wpdb->get_var($wpdb->prepare(
            "SELECT category_type FROM {$this->categories_table} WHERE category_code = %s AND is_active = 1",
            $category
        ));

        if ($category_type) {
            return $category_type === 'dynamic_rw';
        }

        // Fallback to hardcoded list for default categories
        $dynamic_categories = array(
            'penerima_pemberian_makanan_tambahan',
            'jumlah_penerima_rastrada_berdasarkan_rw',
            'jumlah_santri_berdasarkan_lokasi_rw',
            'jumlah_umkm_berdasarkan_rw',
            'jumlah_guru_ngaji_berdasarkan_lokasi_rw',
            'jumlah_guru_ngaji_yang_mendapatkan_insentif_berdasarkan_wilayah'
        );

        return in_array($category, $dynamic_categories);
    }

    /**
     * Generate dynamic RW fields
     * Generate field RW yang bisa ditambah/dikurangi secara dinamis
     */
    private function generate_dynamic_rw_fields($category, $existing_data = array())
    {
        $html = '<div class="rw-container-wrapper">';
        $html .= '<div id="rw-container-' . $category . '">';

        // If editing and has existing data, show existing RW fields
        if (!empty($existing_data)) {
            $rw_count = 1;
            foreach ($existing_data as $key => $value) {
                if (strpos($key, 'rw_') === 0) {
                    $rw_number = str_replace('rw_', '', $key);
                    $html .= '<div class="rw-item">';
                    $html .= '<div class="rw-content">';
                    $html .= '<div class="rw-header">RW ' . $rw_number . '</div>';
                    $html .= '<div class="form-group">';
                    $html .= '<label class="form-label">Jumlah Penerima RW ' . $rw_number . ':</label>';
                    $html .= '<input type="number" name="' . $category . '_rw_' . $rw_number . '" min="0" step="1" class="form-control" value="' . esc_attr($value) . '" />';
                    $html .= '</div>';
                    $html .= '</div>';
                    $html .= '<button type="button" class="btn-danger" onclick="removeRW(this)">Hapus</button>';
                    $html .= '</div>';
                    $rw_count++;
                }
            }
        } else {
            // Default: show RW 1
            $html .= '<div class="rw-item">';
            $html .= '<div class="rw-content">';
            $html .= '<div class="rw-header">RW 1</div>';
            $html .= '<div class="form-group">';
            $html .= '<label class="form-label">Jumlah Penerima RW 1:</label>';
            $html .= '<input type="number" name="' . $category . '_rw_1" min="0" step="1" class="form-control" />';
            $html .= '</div>';
            $html .= '</div>';
            $html .= '</div>';
        }

        $html .= '</div>';
        $html .= '<button type="button" class="btn-success" onclick="addRW(\'' . $category . '\')">+ Tambah RW</button>';
        $html .= '</div>';

        return $html;
    }

    /**
     * Get year options
     * Mendapatkan opsi tahun untuk dropdown (3 tahun ke belakang dan ke depan)
     */
    private function get_year_options($delta = 3)
    {
        $current_year = date('Y');
        $years = array();
        for ($i = ($current_year - $delta); $i <= ($current_year + $delta); $i++) {
            $years[$i] = $i;
        }
        return $years;
    }

    /**
     * Get categories (UPDATED to include custom categories)
     * Mendapatkan daftar kategori yang tersedia (termasuk kategori custom)
     */
    private function get_categories()
    {
        global $wpdb;

        $categories = array();

        // Get custom categories from database
        $custom_categories = $wpdb->get_results(
            "SELECT category_code, category_name FROM {$this->categories_table} WHERE is_active = 1 ORDER BY category_name ASC"
        );

        foreach ($custom_categories as $category) {
            $categories[$category->category_code] = $category->category_name;
        }

        // If no custom categories, return default ones
        if (empty($categories)) {
            $categories = $this->get_default_categories();
        }

        return $categories;
    }

    /**
     * Get default categories (for backward compatibility)
     * Mendapatkan daftar kategori default
     */
    private function get_default_categories()
    {
        return array(
            // Kategori asli Anda
            'jenis_kelamin' => 'Jenis Kelamin',
            'agama' => 'Agama',
            'golongan_darah' => 'Golongan Darah',
            'penerima_pemberian_makanan_tambahan' => 'Penerima Pemberian Makanan Tambahan',
            // Kategori tambahan dari teman
            'apbd_pelaksanaan' => 'APBD Pelaksanaan',
            'apbd_pembelanjaan' => 'APBD Pembelanjaan',
            'apbd_pendapatan' => 'APBD Pendapatan',
            'hubungan_dalam_kk' => 'Hubungan dalam KK',
            'jumlah_guru_ngaji_berdasarkan_lokasi_belajar' => 'Jumlah Guru Ngaji Berdasarkan Lokasi Belajar',
            'jumlah_guru_ngaji_berdasarkan_lokasi_rw' => 'Jumlah Guru Ngaji Berdasarkan Lokasi RW',
            'jumlah_guru_ngaji_yang_mendapatkan_insentif_berdasarkan_wilayah' => 'Jumlah Guru Ngaji yang Mendapatkan Insentif Berdasarkan Wilayah',
            'jumlah_murid_berdasarkan_satuan_pendidikan_dan_jenis_kelamin' => 'Jumlah Murid Berdasarkan Satuan Pendidikan dan Jenis Kelamin',
            'jumlah_penerima_bpnt_pkh_dan_pbi_apbd' => 'Jumlah Penerima BPNT, PKH Dan PBI APBD',
            'jumlah_penerima_rastrada_berdasarkan_rw' => 'Jumlah Penerima Rastrada Berdasarkan RW',
            'jumlah_penerima_rutilahu' => 'Jumlah Penerima Rutilahu',
            'jumlah_santri_berdasarkan_lokasi_rw' => 'Jumlah Santri Berdasarkan Lokasi RW',
            'jumlah_umkm_berdasarkan_jenis_media_pemasaran' => 'Jumlah UMKM Berdasarkan Jenis Media Pemasaran',
            'jumlah_umkm_berdasarkan_jenis_usaha' => 'Jumlah UMKM Berdasarkan Jenis Usaha',
            'jumlah_umkm_berdasarkan_perizinan_dan_verifikasi' => 'Jumlah UMKM Berdasarkan Perizinan dan Verifikasi',
            'jumlah_umkm_berdasarkan_rw' => 'Jumlah UMKM Berdasarkan RW',
            'kategori_umur' => 'Kategori Umur',
            'kelas_sosial' => 'Kelas Sosial',
            'pekerjaan' => 'Pekerjaan',
            'pendidikan_dalam_kk' => 'Pendidikan dalam KK',
            'penerima_bantuan_keluarga' => 'Penerima Bantuan Keluarga',
            'penerima_bantuan_penduduk' => 'Penerima Bantuan Penduduk',
            'penyandang_cacat' => 'Penyandang Cacat',
            'rentang_umur' => 'Rentang Umur',
            'status_covid' => 'Status Covid',
            'status_penduduk' => 'Status Penduduk',
            'status_perkawinan' => 'Status Perkawinan',
            'warga_negara' => 'Warga Negara',
        );
    }

    /**
     * Get category fields (UPDATED to include custom fields)
     * Mendapatkan field untuk setiap kategori (termasuk field custom)
     */
    private function get_category_fields()
    {
        global $wpdb;

        $category_fields = array();

        // Get custom fields from database
        $custom_fields = $wpdb->get_results(
            "SELECT cf.category_code, cf.field_code, cf.field_name 
             FROM {$this->fields_table} cf
             INNER JOIN {$this->categories_table} cc ON cf.category_code = cc.category_code
             WHERE cc.is_active = 1 AND cc.category_type = 'regular'
             ORDER BY cf.category_code, cf.field_order ASC"
        );

        foreach ($custom_fields as $field) {
            if (!isset($category_fields[$field->category_code])) {
                $category_fields[$field->category_code] = array();
            }
            $category_fields[$field->category_code][$field->field_code] = $field->field_name;
        }

        // If no custom fields, return default ones
        if (empty($category_fields)) {
            $category_fields = $this->get_default_category_fields();
        }

        return $category_fields;
    }

    /**
     * Get default category fields (for backward compatibility)
     * Mendapatkan field default untuk setiap kategori
     */
    private function get_default_category_fields()
    {
        return array(
            // Kategori asli Anda
            'jenis_kelamin' => array(
                'laki_laki' => 'Laki-laki',
                'perempuan' => 'Perempuan',
                'bm_laki_laki' => 'bm_laki_laki',
                'bm_perempuan' => 'bm_perempuan'
            ),
            'agama' => array(
                'islam' => 'Islam',
                'kristen' => 'Kristen',
                'katolik' => 'Katolik',
                'hindu' => 'Hindu',
                'buddha' => 'Buddha',
                'konghucu' => 'Konghucu'
            ),
            'golongan_darah' => array(
                'a_positif' => 'A+',
                'a_negatif' => 'A-',
                'b_positif' => 'B+',
                'b_negatif' => 'B-',
                'ab_positif' => 'AB+',
                'ab_negatif' => 'AB-',
                'o_positif' => 'O+',
                'o_negatif' => 'O-'
            ),
            'penerima_pemberian_makanan_tambahan' => array(
                // This will be handled dynamically
            ),
            // Kategori tambahan dari teman
            'apbd_pelaksanaan' => array(
                'bidang_penyelenggaraan_pemerintahan_desa' => 'Bidang Penyelenggaraan Pemerintahan Desa',
                'bidang_pelaksanaan_pembangunan_desa' => 'Bidang Pelaksanaan Pembangunan Desa',
                'bidang_pembinaan_kemasyarakatan' => 'Bidang Pembinaan Kemasyarakatan',
                'bidang_pemberdayaan_masyarakat' => 'Bidang Pemberdayaan Masyarakat',
                'bidang_penanggulangan_bencana_darurat_dan_mendesak' => 'Bidang Penanggulangan Bencana, Darurat dan Mendesak'
            ),
            'apbd_pembelanjaan' => array(
                'belanja_pegawai' => 'Belanja Pegawai',
                'belanja_barang_dan_jasa' => 'Belanja Barang dan Jasa',
                'belanja_modal' => 'Belanja Modal'
            ),
            'apbd_pendapatan' => array(
                'pendapatan_asli_desa' => 'Pendapatan Asli Desa',
                'dana_transfer' => 'Dana Transfer',
                'pendapatan_lain_lain' => 'Pendapatan Lain-lain'
            ),
            'hubungan_dalam_kk' => array(
                'kepala_keluarga' => 'Kepala Keluarga',
                'suami' => 'Suami',
                'istri' => 'Istri',
                'anak' => 'Anak',
                'menantu' => 'Menantu',
                'cucu' => 'Cucu',
                'orangtua' => 'Orangtua',
                'mertua' => 'Mertua',
                'famili_lain' => 'Famili Lain',
                'pembantu' => 'Pembantu',
                'lainnya' => 'Lainnya'
            ),
            'jumlah_guru_ngaji_berdasarkan_lokasi_belajar' => array(
                'masjid' => 'Masjid',
                'mushola' => 'Mushola',
                'rumah_guru' => 'Rumah Guru',
                'rumah_warga' => 'Rumah Warga',
                'madrasah' => 'Madrasah'
            ),
            'jumlah_guru_ngaji_berdasarkan_lokasi_rw' => array(
                // This will be handled dynamically
            ),
            'jumlah_guru_ngaji_yang_mendapatkan_insentif_berdasarkan_wilayah' => array(
                // This will be handled dynamically
            ),
            'jumlah_murid_berdasarkan_satuan_pendidikan_dan_jenis_kelamin' => array(
                'paud_laki_laki' => 'PAUD Laki-laki',
                'paud_perempuan' => 'PAUD Perempuan',
                'tk_laki_laki' => 'TK Laki-laki',
                'tk_perempuan' => 'TK Perempuan',
                'sd_laki_laki' => 'SD Laki-laki',
                'sd_perempuan' => 'SD Perempuan',
                'smp_laki_laki' => 'SMP Laki-laki',
                'smp_perempuan' => 'SMP Perempuan',
                'sma_laki_laki' => 'SMA Laki-laki',
                'sma_perempuan' => 'SMA Perempuan'
            ),
            'jumlah_penerima_bpnt_pkh_dan_pbi_apbd' => array(
                'penerima_bpnt' => 'Penerima BPNT',
                'penerima_pkh' => 'Penerima PKH',
                'penerima_pbi_apbd' => 'Penerima PBI APBD'
            ),
            'jumlah_penerima_rastrada_berdasarkan_rw' => array(
                // This will be handled dynamically
            ),
            'jumlah_penerima_rutilahu' => array(
                'rehab_ringan' => 'Rehabilitasi Ringan',
                'rehab_sedang' => 'Rehabilitasi Sedang',
                'rehab_berat' => 'Rehab Berat',
                'bedah_rumah' => 'Bedah Rumah'
            ),
            'jumlah_santri_berdasarkan_lokasi_rw' => array(
                // This will be handled dynamically
            ),
            'jumlah_umkm_berdasarkan_jenis_media_pemasaran' => array(
                'media_sosial' => 'Media Sosial',
                'marketplace' => 'Marketplace',
                'website_sendiri' => 'Website Sendiri',
                'brosur_leaflet' => 'Brosur/Leaflet',
                'mulut_ke_mulut' => 'Mulut ke Mulut',
                'tidak_ada_promosi' => 'Tidak Ada Promosi'
            ),
            'jumlah_umkm_berdasarkan_jenis_usaha' => array(
                'kuliner' => 'Kuliner',
                'fashion' => 'Fashion',
                'kerajinan' => 'Kerajinan',
                'pertanian' => 'Pertanian',
                'peternakan' => 'Peternakan',
                'perdagangan' => 'Perdagangan',
                'jasa' => 'Jasa',
                'teknologi' => 'Teknologi'
            ),
            'jumlah_umkm_berdasarkan_perizinan_dan_verifikasi' => array(
                'memiliki_nib' => 'Memiliki NIB',
                'memiliki_siup' => 'Memiliki SIUP',
                'memiliki_tdp' => 'Memiliki TDP',
                'memiliki_npwp' => 'Memiliki NPWP',
                'belum_memiliki_izin' => 'Belum Memiliki Izin'
            ),
            'jumlah_umkm_berdasarkan_rw' => array(
                // This will be handled dynamically
            ),
            'kategori_umur' => array(
                'balita_0_5' => 'Balita (0-5 tahun)',
                'anak_6_12' => 'Anak (6-12 tahun)',
                'remaja_13_17' => 'Remaja (13-17 tahun)',
                'dewasa_18_59' => 'Dewasa (18-59 tahun)',
                'lansia_60_plus' => 'Lansia (60+ tahun)'
            ),
            'kelas_sosial' => array(
                'prasejahtera' => 'Prasejahtera',
                'sejahtera_1' => 'Sejahtera I',
                'sejahtera_2' => 'Sejahtera II',
                'sejahtera_3' => 'Sejahtera III',
                'sejahtera_3_plus' => 'Sejahtera III Plus'
            ),
            'pekerjaan' => array(
                'petani' => 'Petani',
                'buruh_tani' => 'Buruh Tani',
                'nelayan' => 'Nelayan',
                'pedagang' => 'Pedagang',
                'pns' => 'PNS',
                'tni_polri' => 'TNI/Polri',
                'guru' => 'Guru',
                'dokter' => 'Dokter',
                'bidan' => 'Bidan',
                'perawat' => 'Perawat',
                'pengusaha' => 'Pengusaha',
                'buruh' => 'Buruh',
                'sopir' => 'Sopir',
                'tukang' => 'Tukang',
                'ibu_rumah_tangga' => 'Ibu Rumah Tangga',
                'pelajar_mahasiswa' => 'Pelajar/Mahasiswa',
                'pensiunan' => 'Pensiunan',
                'lainnya' => 'Lainnya'
            ),
            'pendidikan_dalam_kk' => array(
                'tidak_sekolah' => 'Tidak Sekolah',
                'belum_tamat_sd' => 'Belum Tamat SD',
                'tamat_sd' => 'Tamat SD',
                'sltp_sederajat' => 'SLTP/Sederajat',
                'slta_sederajat' => 'SLTA/Sederajat',
                'diploma_1_2' => 'Diploma I/II',
                'akademi_diploma_3' => 'Akademi/Diploma III',
                'diploma_4_s1' => 'Diploma IV/S1',
                's2' => 'S2',
                's3' => 'S3'
            ),
            'penerima_bantuan_keluarga' => array(
                'pkh' => 'Program Keluarga Harapan (PKH)',
                'bpnt' => 'Bantuan Pangan Non Tunai (BPNT)',
                'bst' => 'Bantuan Sosial Tunai (BST)',
                'pip' => 'Program Indonesia Pintar (PIP)',
                'kip' => 'Kartu Indonesia Pintar (KIP)',
                'bantuan_desa' => 'Bantuan Desa'
            ),
            'penerima_bantuan_penduduk' => array(
                'lansia' => 'Bantuan Lansia',
                'disabilitas' => 'Bantuan Disabilitas',
                'anak_yatim' => 'Bantuan Anak Yatim',
                'janda' => 'Bantuan Janda',
                'fakir_miskin' => 'Bantuan Fakir Miskin'
            ),
            'penyandang_cacat' => array(
                'cacat_fisik' => 'Cacat Fisik',
                'cacat_netra' => 'Cacat Netra/Buta',
                'cacat_rungu_wicara' => 'Cacat Rungu/Wicara',
                'cacat_mental' => 'Cacat Mental',
                'cacat_fisik_mental' => 'Cacat Fisik dan Mental'
            ),
            'rentang_umur' => array(
                '0_4_tahun' => '0-4 tahun',
                '5_9_tahun' => '5-9 tahun',
                '10_14_tahun' => '10-14 tahun',
                '15_19_tahun' => '15-19 tahun',
                '20_24_tahun' => '20-24 tahun',
                '25_29_tahun' => '25-29 tahun',
                '30_34_tahun' => '30-34 tahun',
                '35_39_tahun' => '35-39 tahun',
                '40_44_tahun' => '40-44 tahun',
                '45_49_tahun' => '45-49 tahun',
                '50_54_tahun' => '50-54 tahun',
                '55_59_tahun' => '55-59 tahun',
                '60_64_tahun' => '60-64 tahun',
                '65_69_tahun' => '65-69 tahun',
                '70_74_tahun' => '70-74 tahun',
                '75_plus_tahun' => '75+ tahun'
            ),
            'status_covid' => array(
                'belum_vaksin' => 'Belum Vaksin',
                'vaksin_1' => 'Vaksin Dosis 1',
                'vaksin_2' => 'Vaksin Dosis 2',
                'vaksin_3_booster' => 'Vaksin Dosis 3 (Booster)',
                'pernah_positif' => 'Pernah Positif COVID-19',
                'isolasi_mandiri' => 'Sedang Isolasi Mandiri'
            ),
            'status_penduduk' => array(
                'tetap' => 'Penduduk Tetap',
                'tidak_tetap' => 'Penduduk Tidak Tetap',
                'pendatang' => 'Pendatang'
            ),
            'status_perkawinan' => array(
                'belum_kawin' => 'Belum Kawin',
                'kawin' => 'Kawin',
                'cerai_hidup' => 'Cerai Hidup',
                'cerai_mati' => 'Cerai Mati'
            ),
            'warga_negara' => array(
                'wni' => 'Warga Negara Indonesia (WNI)',
                'wna' => 'Warga Negara Asing (WNA)',
                'dwi_kewarganegaraan' => 'Dwi Kewarganegaraan'
            ),
        );
    }

    /**
     * Convert form data to JSON
     * Konversi data form ke format JSON untuk disimpan di database
     */
    private function convert_form_data_to_json($category, $post_data)
    {
        $json_data = array();

        if ($this->is_dynamic_rw_category($category)) {
            // Handle dynamic RW data
            foreach ($post_data as $key => $value) {
                if (strpos($key, $category . '_rw_') === 0 && $value !== '') {
                    $rw_key = str_replace($category . '_', '', $key);
                    $json_data[$rw_key] = max(0, intval($value));
                }
            }
        } else {
            // Handle regular category fields
            $category_fields = $this->get_category_fields();
            if (isset($category_fields[$category])) {
                foreach ($category_fields[$category] as $field_key => $field_label) {
                    $field_name = $category . '_' . $field_key;
                    if (isset($post_data[$field_name]) && $post_data[$field_name] !== '') {
                        $json_data[$field_key] = max(0, intval($post_data[$field_name]));
                    }
                }
            }
        }

        return json_encode($json_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }

    /**
     * Method to create page for input
     * Halaman admin untuk input data baru
     */
    public function admin_create_page()
    {
        echo '<div class="wrap">';
        echo '<h1>Input Statistik</h1>';
        $this->render_public_form(); // Render the form on the admin page
        echo '</div>';
    }

    /**
     * Method to create page for edit
     * Halaman admin untuk edit data yang sudah ada
     */
    public function admin_edit_page()
    {
        global $wpdb;

        $year = isset($_GET['year']) ? intval($_GET['year']) : 0;
        $category = isset($_GET['category']) ? sanitize_text_field($_GET['category']) : '';

        if (empty($year) || empty($category)) {
            echo '<div class="wrap">';
            echo '<h1>Edit Statistik</h1>';
            echo '<div class="notice notice-error"><p>Parameter tahun dan kategori diperlukan.</p></div>';
            echo '<a href="' . admin_url('admin.php?page=statistic-list') . '" class="button">Kembali ke Daftar</a>';
            echo '</div>';
            return;
        }

        $edit_data = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table_name} WHERE year = %d AND category = %s",
            $year,
            $category
        ));

        if (!$edit_data) {
            echo '<div class="wrap">';
            echo '<h1>Edit Statistik</h1>';
            echo '<div class="notice notice-error"><p>Data tidak ditemukan.</p></div>';
            echo '<a href="' . admin_url('admin.php?page=statistic-list') . '" class="button">Kembali ke Daftar</a>';
            echo '</div>';
            return;
        }

        echo '<div class="wrap">';
        echo '<h1>Edit Statistik</h1>';
        $this->render_public_form($edit_data);
        echo '</div>';
    }

    /**
     * NEW: Admin page for managing categories
     * Halaman admin untuk mengelola kategori dan field custom
     */
    public function admin_categories_page()
    {
        global $wpdb;

        // Get all categories
        $categories = $wpdb->get_results(
            "SELECT * FROM {$this->categories_table} ORDER BY category_name ASC"
        );

        ?>
        <div class="wrap">
            <style>
                /* Category Management Styling */
                .category-management-container {
                    background: #fff;
                    margin: 20px 0;
                    border-radius: 8px;
                    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
                }

                .category-header {
                    padding: 20px 25px;
                    border-bottom: 1px solid #e1e5e9;
                    background: #f8f9fa;
                    border-radius: 8px 8px 0 0;
                }

                .category-header h1 {
                    margin: 0 0 10px 0;
                    color: #32373c;
                    font-size: 24px;
                    font-weight: 600;
                }

                .category-header .description {
                    color: #646970;
                    margin: 0;
                    font-size: 14px;
                }

                .category-actions {
                    padding: 20px 25px;
                    background: #fafafa;
                    border-bottom: 1px solid #e1e5e9;
                    display: flex;
                    justify-content: space-between;
                    align-items: center;
                }

                .btn-add-category {
                    background: #007bff;
                    color: white;
                    border: none;
                    padding: 10px 20px;
                    border-radius: 4px;
                    cursor: pointer;
                    font-size: 14px;
                    font-weight: 500;
                    text-decoration: none;
                    display: inline-flex;
                    align-items: center;
                    gap: 8px;
                }

                .btn-add-category:hover {
                    background: #0056b3;
                    color: white;
                    text-decoration: none;
                }

                .categories-grid {
                    display: grid;
                    grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
                    gap: 20px;
                    padding: 25px;
                }

                .category-card {
                    background: #fff;
                    border: 1px solid #e1e5e9;
                    border-radius: 8px;
                    overflow: hidden;
                    box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
                    transition: transform 0.2s ease, box-shadow 0.2s ease;
                }

                .category-card:hover {
                    transform: translateY(-2px);
                    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
                }

                .category-card-header {
                    padding: 15px 20px;
                    background: #f8f9fa;
                    border-bottom: 1px solid #e1e5e9;
                    display: flex;
                    justify-content: space-between;
                    align-items: center;
                }

                .category-name {
                    font-weight: 600;
                    color: #32373c;
                    font-size: 16px;
                    margin: 0;
                }

                .category-type-badge {
                    padding: 4px 8px;
                    border-radius: 12px;
                    font-size: 11px;
                    font-weight: 600;
                    text-transform: uppercase;
                }

                .type-regular {
                    background: #e7f3ff;
                    color: #0073aa;
                }

                .type-dynamic {
                    background: #fff2e7;
                    color: #d63638;
                }

                .category-card-body {
                    padding: 15px 20px;
                }

                .category-code {
                    font-family: monospace;
                    background: #f0f0f1;
                    color: #646970;
                    padding: 2px 6px;
                    border-radius: 3px;
                    font-size: 12px;
                    margin-bottom: 10px;
                    display: inline-block;
                }

                .category-description {
                    color: #646970;
                    font-size: 13px;
                    margin-bottom: 15px;
                    line-height: 1.4;
                }

                .category-fields {
                    margin-bottom: 15px;
                }

                .category-fields h4 {
                    margin: 0 0 8px 0;
                    font-size: 13px;
                    font-weight: 600;
                    color: #32373c;
                }

                .fields-list {
                    display: flex;
                    flex-wrap: wrap;
                    gap: 4px;
                }

                .field-tag {
                    background: #e9ecef;
                    color: #495057;
                    padding: 2px 6px;
                    border-radius: 10px;
                    font-size: 11px;
                    font-weight: 500;
                }

                .category-card-footer {
                    padding: 15px 20px;
                    background: #f8f9fa;
                    border-top: 1px solid #e1e5e9;
                    display: flex;
                    justify-content: space-between;
                    align-items: center;
                }

                .category-status {
                    display: flex;
                    align-items: center;
                    gap: 5px;
                    font-size: 12px;
                    font-weight: 600;
                }

                .status-active {
                    color: #00a32a;
                }

                .status-inactive {
                    color: #d63638;
                }

                .status-indicator {
                    width: 6px;
                    height: 6px;
                    border-radius: 50%;
                    background: currentColor;
                }

                .category-actions-buttons {
                    display: flex;
                    gap: 5px;
                }

                .btn-small {
                    padding: 4px 8px;
                    font-size: 11px;
                    border-radius: 3px;
                    border: 1px solid;
                    cursor: pointer;
                    text-decoration: none;
                    display: inline-flex;
                    align-items: center;
                    gap: 3px;
                    font-weight: 500;
                }

                .btn-edit {
                    background: #fff;
                    color: #2271b1;
                    border-color: #2271b1;
                }

                .btn-edit:hover {
                    background: #2271b1;
                    color: #fff;
                    text-decoration: none;
                }

                .btn-fields {
                    background: #fff;
                    color: #00a32a;
                    border-color: #00a32a;
                }

                .btn-fields:hover {
                    background: #00a32a;
                    color: #fff;
                    text-decoration: none;
                }

                .btn-delete {
                    background: #fff;
                    color: #d63638;
                    border-color: #d63638;
                }

                .btn-delete:hover {
                    background: #d63638;
                    color: #fff;
                    text-decoration: none;
                }

                .empty-state {
                    text-align: center;
                    padding: 60px 20px;
                    color: #646970;
                }

                .empty-state-icon {
                    font-size: 48px;
                    margin-bottom: 15px;
                    opacity: 0.5;
                }

                .empty-state h3 {
                    margin: 0 0 10px 0;
                    color: #32373c;
                }

                .empty-state p {
                    margin: 0 0 20px 0;
                    font-size: 14px;
                }

                /* Modal Styling */
                .modal-overlay {
                    display: none;
                    position: fixed;
                    top: 0;
                    left: 0;
                    width: 100%;
                    height: 100%;
                    background: rgba(0, 0, 0, 0.5);
                    z-index: 9999;
                }

                .modal-content {
                    position: absolute;
                    top: 50%;
                    left: 50%;
                    transform: translate(-50%, -50%);
                    background: white;
                    border-radius: 8px;
                    max-width: 600px;
                    width: 90%;
                    max-height: 80%;
                    overflow-y: auto;
                    box-shadow: 0 4px 20px rgba(0, 0, 0, 0.3);
                }

                .modal-header {
                    padding: 20px 25px;
                    border-bottom: 1px solid #e1e5e9;
                    display: flex;
                    justify-content: space-between;
                    align-items: center;
                    background: #f8f9fa;
                }

                .modal-header h2 {
                    margin: 0;
                    color: #32373c;
                    font-size: 18px;
                    font-weight: 600;
                }

                .modal-close {
                    background: #666;
                    color: white;
                    border: none;
                    padding: 8px 12px;
                    border-radius: 4px;
                    cursor: pointer;
                    font-size: 12px;
                }

                .modal-body {
                    padding: 25px;
                }

                .form-group {
                    margin-bottom: 20px;
                }

                .form-label {
                    display: block;
                    margin-bottom: 8px;
                    font-size: 14px;
                    font-weight: 500;
                    color: #32373c;
                }

                .form-control {
                    width: 100%;
                    padding: 10px 12px;
                    font-size: 14px;
                    border: 1px solid #ddd;
                    border-radius: 4px;
                    box-sizing: border-box;
                }

                .form-control:focus {
                    outline: none;
                    border-color: #007bff;
                    box-shadow: 0 0 0 2px rgba(0, 123, 255, 0.25);
                }

                .form-select {
                    width: 100%;
                    padding: 10px 12px;
                    font-size: 14px;
                    border: 1px solid #ddd;
                    border-radius: 4px;
                    background: white;
                    box-sizing: border-box;
                }

                .form-textarea {
                    width: 100%;
                    padding: 10px 12px;
                    font-size: 14px;
                    border: 1px solid #ddd;
                    border-radius: 4px;
                    min-height: 80px;
                    resize: vertical;
                    box-sizing: border-box;
                }

                .form-checkbox {
                    margin-right: 8px;
                }

                .modal-footer {
                    padding: 20px 25px;
                    border-top: 1px solid #e1e5e9;
                    background: #f8f9fa;
                    display: flex;
                    justify-content: flex-end;
                    gap: 10px;
                }

                .btn-primary {
                    background: #007bff;
                    color: white;
                    border: 1px solid #007bff;
                    padding: 10px 20px;
                    border-radius: 4px;
                    cursor: pointer;
                    font-size: 14px;
                    font-weight: 500;
                }

                .btn-primary:hover {
                    background: #0056b3;
                    border-color: #0056b3;
                }

                .btn-secondary {
                    background: #6c757d;
                    color: white;
                    border: 1px solid #6c757d;
                    padding: 10px 20px;
                    border-radius: 4px;
                    cursor: pointer;
                    font-size: 14px;
                    font-weight: 500;
                }

                .btn-secondary:hover {
                    background: #545b62;
                    border-color: #545b62;
                }

                /* Fields Management */
                .fields-container {
                    border: 1px solid #e1e5e9;
                    border-radius: 6px;
                    margin-top: 15px;
                }

                .fields-header {
                    padding: 12px 15px;
                    background: #f8f9fa;
                    border-bottom: 1px solid #e1e5e9;
                    display: flex;
                    justify-content: space-between;
                    align-items: center;
                }

                .fields-header h4 {
                    margin: 0;
                    font-size: 14px;
                    font-weight: 600;
                    color: #32373c;
                }

                .btn-add-field {
                    background: #00a32a;
                    color: white;
                    border: none;
                    padding: 6px 12px;
                    border-radius: 3px;
                    cursor: pointer;
                    font-size: 12px;
                    font-weight: 500;
                }

                .btn-add-field:hover {
                    background: #007a1f;
                }

                .fields-list-container {
                    max-height: 200px;
                    overflow-y: auto;
                }

                .field-item {
                    padding: 10px 15px;
                    border-bottom: 1px solid #f0f0f1;
                    display: flex;
                    justify-content: space-between;
                    align-items: center;
                }

                .field-item:last-child {
                    border-bottom: none;
                }

                .field-info {
                    flex: 1;
                }

                .field-name {
                    font-weight: 500;
                    color: #32373c;
                    font-size: 13px;
                }

                .field-code {
                    font-family: monospace;
                    color: #646970;
                    font-size: 11px;
                    background: #f0f0f1;
                    padding: 1px 4px;
                    border-radius: 2px;
                    margin-top: 2px;
                    display: inline-block;
                }

                .field-actions {
                    display: flex;
                    gap: 5px;
                }

                .btn-field-edit,
                .btn-field-delete {
                    padding: 3px 6px;
                    font-size: 10px;
                    border-radius: 2px;
                    border: 1px solid;
                    cursor: pointer;
                    text-decoration: none;
                    font-weight: 500;
                }

                .btn-field-edit {
                    background: #fff;
                    color: #2271b1;
                    border-color: #2271b1;
                }

                .btn-field-edit:hover {
                    background: #2271b1;
                    color: #fff;
                    text-decoration: none;
                }

                .btn-field-delete {
                    background: #fff;
                    color: #d63638;
                    border-color: #d63638;
                }

                .btn-field-delete:hover {
                    background: #d63638;
                    color: #fff;
                    text-decoration: none;
                }

                .no-fields {
                    padding: 20px;
                    text-align: center;
                    color: #646970;
                    font-style: italic;
                    font-size: 13px;
                }

                /* Responsive */
                @media (max-width: 768px) {
                    .categories-grid {
                        grid-template-columns: 1fr;
                        padding: 15px;
                    }

                    .category-actions {
                        flex-direction: column;
                        gap: 15px;
                        align-items: stretch;
                    }

                    .modal-content {
                        width: 95%;
                        margin: 20px;
                    }

                    .modal-body {
                        padding: 20px;
                    }
                }
            </style>

            <div class="category-management-container">
                <!-- Header -->
                <div class="category-header">
                    <h1>🏷️ Kelola Kategori & Field</h1>
                    <p class="description">Tambah, edit, dan kelola kategori statistik beserta field-fieldnya. Kategori yang
                        dibuat di sini akan tersedia di form input statistik.</p>
                </div>

                <!-- Actions -->
                <div class="category-actions">
                    <div class="summary">
                        <span>📊 Total Kategori: <strong><?php echo count($categories); ?></strong></span>
                        <span style="margin-left: 20px;">✅ Aktif:
                            <strong><?php echo count(array_filter($categories, function ($c) {
                                return $c->is_active;
                            })); ?></strong></span>
                    </div>
                    <button class="btn-add-category" onclick="openCategoryModal()">
                        ➕ Tambah Kategori Baru
                    </button>
                </div>

                <!-- Categories Grid -->
                <div class="categories-grid">
                    <?php if (!empty($categories)): ?>
                        <?php foreach ($categories as $category): ?>
                            <?php
                            // Get fields for this category
                            $fields = $wpdb->get_results($wpdb->prepare(
                                "SELECT * FROM {$this->fields_table} WHERE category_code = %s ORDER BY field_order ASC",
                                $category->category_code
                            ));
                            ?>
                            <div class="category-card">
                                <div class="category-card-header">
                                    <h3 class="category-name"><?php echo esc_html($category->category_name); ?></h3>
                                    <span
                                        class="category-type-badge <?php echo $category->category_type === 'regular' ? 'type-regular' : 'type-dynamic'; ?>">
                                        <?php echo $category->category_type === 'regular' ? 'Regular' : 'Dynamic RW'; ?>
                                    </span>
                                </div>
                                <div class="category-card-body">
                                    <div class="category-code"><?php echo esc_html($category->category_code); ?></div>
                                    <?php if (!empty($category->description)): ?>
                                        <div class="category-description"><?php echo esc_html($category->description); ?></div>
                                    <?php endif; ?>

                                    <?php if ($category->category_type === 'regular' && !empty($fields)): ?>
                                        <div class="category-fields">
                                            <h4>📝 Fields (<?php echo count($fields); ?>):</h4>
                                            <div class="fields-list">
                                                <?php foreach (array_slice($fields, 0, 5) as $field): ?>
                                                    <span class="field-tag" title="<?php echo esc_attr($field->field_code); ?>">
                                                        <?php echo esc_html($field->field_name); ?>
                                                    </span>
                                                <?php endforeach; ?>
                                                <?php if (count($fields) > 5): ?>
                                                    <span class="field-tag" style="background: #f0f0f1; color: #646970;">
                                                        +<?php echo (count($fields) - 5); ?> lainnya
                                                    </span>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    <?php elseif ($category->category_type === 'dynamic_rw'): ?>
                                        <div class="category-fields">
                                            <h4>🏘️ Dynamic RW Fields</h4>
                                            <p style="font-size: 12px; color: #646970; margin: 0;">Field RW akan dibuat secara dinamis saat
                                                input data</p>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                <div class="category-card-footer">
                                    <div
                                        class="category-status <?php echo $category->is_active ? 'status-active' : 'status-inactive'; ?>">
                                        <span class="status-indicator"></span>
                                        <?php echo $category->is_active ? 'Aktif' : 'Nonaktif'; ?>
                                    </div>
                                    <div class="category-actions-buttons">
                                        <button class="btn-small btn-edit"
                                            onclick="editCategory(<?php echo esc_attr(json_encode($category)); ?>)">
                                            ✏️ Edit
                                        </button>
                                        <?php if ($category->category_type === 'regular'): ?>
                                            <button class="btn-small btn-fields"
                                                onclick="manageFields('<?php echo esc_attr($category->category_code); ?>', '<?php echo esc_attr($category->category_name); ?>')">
                                                📝 Fields
                                            </button>
                                        <?php endif; ?>
                                        <button class="btn-small btn-delete"
                                            onclick="deleteCategory('<?php echo esc_attr($category->category_code); ?>', '<?php echo esc_attr($category->category_name); ?>')">
                                            🗑️ Hapus
                                        </button>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="empty-state" style="grid-column: 1 / -1;">
                            <div class="empty-state-icon">🏷️</div>
                            <h3>Belum Ada Kategori Custom</h3>
                            <p>Mulai dengan menambahkan kategori statistik pertama Anda.</p>
                            <button class="btn-add-category" onclick="openCategoryModal()">
                                ➕ Tambah Kategori Pertama
                            </button>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Category Modal -->
            <div id="category-modal" class="modal-overlay">
                <div class="modal-content">
                    <div class="modal-header">
                        <h2 id="category-modal-title">Tambah Kategori Baru</h2>
                        <button class="modal-close" onclick="closeCategoryModal()">✕ Tutup</button>
                    </div>
                    <div class="modal-body">
                        <form id="category-form">
                            <input type="hidden" id="category-id" name="category_id">
                            <input type="hidden" id="original-category-code" name="original_category_code">

                            <div class="form-group">
                                <label for="category-code" class="form-label">Kode Kategori:</label>
                                <input type="text" id="category-code" name="category_code" class="form-control"
                                    placeholder="contoh: pendidikan_warga" required>
                                <small style="color: #646970; font-size: 12px;">Gunakan huruf kecil, angka, dan underscore.
                                    Tidak boleh ada spasi.</small>
                            </div>

                            <div class="form-group">
                                <label for="category-name" class="form-label">Nama Kategori:</label>
                                <input type="text" id="category-name" name="category_name" class="form-control"
                                    placeholder="contoh: Pendidikan Warga" required>
                            </div>

                            <div class="form-group">
                                <label for="category-type" class="form-label">Tipe Kategori:</label>
                                <select id="category-type" name="category_type" class="form-select" required>
                                    <option value="regular">Regular - Field tetap yang sudah ditentukan</option>
                                    <option value="dynamic_rw">Dynamic RW - Field RW yang bisa ditambah/kurangi</option>
                                </select>
                            </div>

                            <div class="form-group">
                                <label for="category-description" class="form-label">Deskripsi (Opsional):</label>
                                <textarea id="category-description" name="description" class="form-textarea"
                                    placeholder="Deskripsi singkat tentang kategori ini..."></textarea>
                            </div>

                            <div class="form-group">
                                <label>
                                    <input type="checkbox" id="category-active" name="is_active" value="1" checked
                                        class="form-checkbox">
                                    Aktifkan kategori ini
                                </label>
                            </div>
                        </form>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn-secondary" onclick="closeCategoryModal()">Batal</button>
                        <button type="button" class="btn-primary" onclick="saveCategory()">Simpan Kategori</button>
                    </div>
                </div>
            </div>

            <!-- Fields Management Modal -->
            <div id="fields-modal" class="modal-overlay">
                <div class="modal-content">
                    <div class="modal-header">
                        <h2 id="fields-modal-title">Kelola Fields</h2>
                        <button class="modal-close" onclick="closeFieldsModal()">✕ Tutup</button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" id="fields-category-code">

                        <!-- Add New Field Form -->
                        <div class="form-group">
                            <label class="form-label">Tambah Field Baru:</label>
                            <div style="display: flex; gap: 10px; margin-bottom: 10px;">
                                <input type="text" id="new-field-code" placeholder="kode_field" class="form-control"
                                    style="flex: 1;">
                                <input type="text" id="new-field-name" placeholder="Nama Field" class="form-control"
                                    style="flex: 2;">
                                <button type="button" class="btn-add-field" onclick="addField()">➕ Tambah</button>
                            </div>
                            <small style="color: #646970; font-size: 12px;">Kode field: huruf kecil, angka, underscore. Nama
                                field: bebas.</small>
                        </div>

                        <!-- Fields List -->
                        <div class="fields-container">
                            <div class="fields-header">
                                <h4>📝 Daftar Fields</h4>
                                <span id="fields-count">0 fields</span>
                            </div>
                            <div class="fields-list-container" id="fields-list">
                                <!-- Fields will be loaded here -->
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn-secondary" onclick="closeFieldsModal()">Tutup</button>
                    </div>
                </div>
            </div>

            <!-- Field Edit Modal -->
            <div id="field-edit-modal" class="modal-overlay">
                <div class="modal-content" style="max-width: 400px;">
                    <div class="modal-header">
                        <h2>Edit Field</h2>
                        <button class="modal-close" onclick="closeFieldEditModal()">✕ Tutup</button>
                    </div>
                    <div class="modal-body">
                        <form id="field-edit-form">
                            <input type="hidden" id="edit-field-id">
                            <input type="hidden" id="edit-field-category">
                            <input type="hidden" id="edit-original-field-code">

                            <div class="form-group">
                                <label for="edit-field-code" class="form-label">Kode Field:</label>
                                <input type="text" id="edit-field-code" name="field_code" class="form-control" required>
                            </div>

                            <div class="form-group">
                                <label for="edit-field-name" class="form-label">Nama Field:</label>
                                <input type="text" id="edit-field-name" name="field_name" class="form-control" required>
                            </div>
                        </form>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn-secondary" onclick="closeFieldEditModal()">Batal</button>
                        <button type="button" class="btn-primary" onclick="saveFieldEdit()">Simpan</button>
                    </div>
                </div>
            </div>
        </div>

        <script>
            // Category Management JavaScript
            let isEditMode = false;

            // Open category modal
            function openCategoryModal() {
                isEditMode = false;
                document.getElementById('category-modal-title').textContent = 'Tambah Kategori Baru';
                document.getElementById('category-form').reset();
                document.getElementById('category-id').value = '';
                document.getElementById('original-category-code').value = '';
                document.getElementById('category-active').checked = true;
                document.getElementById('category-modal').style.display = 'block';
            }

            // Edit category
            function editCategory(category) {
                isEditMode = true;
                document.getElementById('category-modal-title').textContent
                isEditMode = true;
                document.getElementById('category-modal-title').textContent = 'Edit Kategori';
                document.getElementById('category-id').value = category.id;
                document.getElementById('original-category-code').value = category.category_code;
                document.getElementById('category-code').value = category.category_code;
                document.getElementById('category-name').value = category.category_name;
                document.getElementById('category-type').value = category.category_type;
                document.getElementById('category-description').value = category.description || '';
                document.getElementById('category-active').checked = category.is_active == 1;
                document.getElementById('category-modal').style.display = 'block';
            }

            // Close category modal
            function closeCategoryModal() {
                document.getElementById('category-modal').style.display = 'none';
            }

            // Save category
            function saveCategory() {
                const formData = new FormData(document.getElementById('category-form'));
                formData.append('action', 'save_category');
                formData.append('nonce', '<?php echo wp_create_nonce('save_category_nonce'); ?>');
                formData.append('is_edit', isEditMode ? '1' : '0');

                fetch(ajaxurl, {
                    method: 'POST',
                    body: formData
                })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            alert('✅ Kategori berhasil disimpan!');
                            location.reload();
                        } else {
                            alert('❌ Error: ' + data.data);
                        }
                    })
                    .catch(error => {
                        console.error('Error:', error);
                        alert('❌ Terjadi kesalahan saat menyimpan kategori');
                    });
            }

            // Delete category
            function deleteCategory(categoryCode, categoryName) {
                if (!confirm(`⚠️ Apakah Anda yakin ingin menghapus kategori "${categoryName}"?\n\nSemua data statistik dan field yang terkait akan ikut terhapus!\n\nTindakan ini tidak dapat dibatalkan.`)) {
                    return;
                }

                const formData = new FormData();
                formData.append('action', 'delete_category');
                formData.append('category_code', categoryCode);
                formData.append('nonce', '<?php echo wp_create_nonce('delete_category_nonce'); ?>');

                fetch(ajaxurl, {
                    method: 'POST',
                    body: formData
                })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            alert('✅ Kategori berhasil dihapus!');
                            location.reload();
                        } else {
                            alert('❌ Error: ' + data.data);
                        }
                    })
                    .catch(error => {
                        console.error('Error:', error);
                        alert('❌ Terjadi kesalahan saat menghapus kategori');
                    });
            }

            // Manage fields
            function manageFields(categoryCode, categoryName) {
                document.getElementById('fields-modal-title').textContent = `Kelola Fields - ${categoryName}`;
                document.getElementById('fields-category-code').value = categoryCode;
                loadFields(categoryCode);
                document.getElementById('fields-modal').style.display = 'block';
            }

            // Close fields modal
            function closeFieldsModal() {
                document.getElementById('fields-modal').style.display = 'none';
                document.getElementById('new-field-code').value = '';
                document.getElementById('new-field-name').value = '';
            }

            // Load fields
            function loadFields(categoryCode) {
                const formData = new FormData();
                formData.append('action', 'get_category_fields');
                formData.append('category', categoryCode);
                formData.append('nonce', '<?php echo wp_create_nonce('get_category_fields_nonce'); ?>');

                fetch(ajaxurl, {
                    method: 'POST',
                    body: formData
                })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            const fieldsList = document.getElementById('fields-list');
                            const fieldsCount = document.getElementById('fields-count');

                            if (data.data.fields && data.data.fields.length > 0) {
                                let html = '';
                                data.data.fields.forEach(field => {
                                    html += `
                                    <div class="field-item">
                                        <div class="field-info">
                                            <div class="field-name">${field.field_name}</div>
                                            <div class="field-code">${field.field_code}</div>
                                        </div>
                                        <div class="field-actions">
                                            <button class="btn-field-edit" onclick="editField(${field.id}, '${field.field_code}', '${field.field_name}', '${categoryCode}')">✏️</button>
                                            <button class="btn-field-delete" onclick="deleteField(${field.id}, '${field.field_name}')">🗑️</button>
                                        </div>
                                    </div>
                                `;
                                });
                                fieldsList.innerHTML = html;
                                fieldsCount.textContent = `${data.data.fields.length} fields`;
                            } else {
                                fieldsList.innerHTML = '<div class="no-fields">Belum ada field. Tambahkan field pertama di atas.</div>';
                                fieldsCount.textContent = '0 fields';
                            }
                        }
                    })
                    .catch(error => {
                        console.error('Error:', error);
                    });
            }

            // Add field
            function addField() {
                const categoryCode = document.getElementById('fields-category-code').value;
                const fieldCode = document.getElementById('new-field-code').value.trim();
                const fieldName = document.getElementById('new-field-name').value.trim();

                if (!fieldCode || !fieldName) {
                    alert('❌ Kode field dan nama field harus diisi!');
                    return;
                }

                // Validate field code format
                if (!/^[a-z0-9_]+$/.test(fieldCode)) {
                    alert('❌ Kode field hanya boleh menggunakan huruf kecil, angka, dan underscore!');
                    return;
                }

                const formData = new FormData();
                formData.append('action', 'save_field');
                formData.append('category_code', categoryCode);
                formData.append('field_code', fieldCode);
                formData.append('field_name', fieldName);
                formData.append('field_type', 'number');
                formData.append('is_required', '1');
                formData.append('nonce', '<?php echo wp_create_nonce('save_field_nonce'); ?>');

                fetch(ajaxurl, {
                    method: 'POST',
                    body: formData
                })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            document.getElementById('new-field-code').value = '';
                            document.getElementById('new-field-name').value = '';
                            loadFields(categoryCode);
                        } else {
                            alert('❌ Error: ' + data.data);
                        }
                    })
                    .catch(error => {
                        console.error('Error:', error);
                        alert('❌ Terjadi kesalahan saat menambah field');
                    });
            }

            // Edit field
            function editField(fieldId, fieldCode, fieldName, categoryCode) {
                document.getElementById('edit-field-id').value = fieldId;
                document.getElementById('edit-field-category').value = categoryCode;
                document.getElementById('edit-original-field-code').value = fieldCode;
                document.getElementById('edit-field-code').value = fieldCode;
                document.getElementById('edit-field-name').value = fieldName;
                document.getElementById('field-edit-modal').style.display = 'block';
            }

            // Close field edit modal
            function closeFieldEditModal() {
                document.getElementById('field-edit-modal').style.display = 'none';
            }

            // Save field edit
            function saveFieldEdit() {
                const formData = new FormData();
                formData.append('action', 'save_field');
                formData.append('field_id', document.getElementById('edit-field-id').value);
                formData.append('category_code', document.getElementById('edit-field-category').value);
                formData.append('original_field_code', document.getElementById('edit-original-field-code').value);
                formData.append('field_code', document.getElementById('edit-field-code').value);
                formData.append('field_name', document.getElementById('edit-field-name').value);
                formData.append('field_type', 'number');
                formData.append('is_required', '1');
                formData.append('is_edit', '1');
                formData.append('nonce', '<?php echo wp_create_nonce('save_field_nonce'); ?>');

                fetch(ajaxurl, {
                    method: 'POST',
                    body: formData
                })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            closeFieldEditModal();
                            loadFields(document.getElementById('edit-field-category').value);
                        } else {
                            alert('❌ Error: ' + data.data);
                        }
                    })
                    .catch(error => {
                        console.error('Error:', error);
                        alert('❌ Terjadi kesalahan saat menyimpan field');
                    });
            }

            // Delete field
            function deleteField(fieldId, fieldName) {
                if (!confirm(`⚠️ Apakah Anda yakin ingin menghapus field "${fieldName}"?\n\nTindakan ini tidak dapat dibatalkan.`)) {
                    return;
                }

                const formData = new FormData();
                formData.append('action', 'delete_field');
                formData.append('field_id', fieldId);
                formData.append('nonce', '<?php echo wp_create_nonce('delete_field_nonce'); ?>');

                fetch(ajaxurl, {
                    method: 'POST',
                    body: formData
                })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            loadFields(document.getElementById('fields-category-code').value);
                        } else {
                            alert('❌ Error: ' + data.data);
                        }
                    })
                    .catch(error => {
                        console.error('Error:', error);
                        alert('❌ Terjadi kesalahan saat menghapus field');
                    });
            }

            // Close modals when clicking outside
            document.addEventListener('click', function (e) {
                if (e.target.classList.contains('modal-overlay')) {
                    e.target.style.display = 'none';
                }
            });
        </script>
        <?php
    }

    /**
     * Handle form submission
     * Menangani submit form (create/update data)
     */
    public function handle_form_submission()
    {
        // Verifikasi nonce untuk keamanan
        if (!isset($_POST['statistic_nonce']) || !wp_verify_nonce($_POST['statistic_nonce'], 'statistic_nonce_action')) {
            wp_die('Nonce verification failed.');
        }

        // Cek permission user
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized access.');
        }

        global $wpdb;

        $is_edit = isset($_POST['edit_mode']) && $_POST['edit_mode'] == '1';
        $year = intval($_POST['year']);
        $category = sanitize_text_field($_POST['category']);
        $sumber = sanitize_textarea_field($_POST['sumber'] ?? '');
        $is_published = isset($_POST['is_published']) ? 1 : 0;

        // Validate required fields
        if (empty($year) || empty($category)) {
            wp_die('Tahun dan kategori harus diisi.');
        }

        // Convert form data to JSON
        $data = $this->convert_form_data_to_json($category, $_POST);

        if (empty($data) || $data === '{}') {
            wp_die('Data tidak boleh kosong.');
        }

        if ($is_edit) {
            // Update existing record
            $original_year = intval($_POST['original_year']);
            $original_category = sanitize_text_field($_POST['original_category']);

            $result = $wpdb->update(
                $this->table_name,
                array(
                    'sumber' => $sumber,
                    'data' => $data,
                    'is_published' => $is_published,
                    'updated_at' => current_time('mysql')
                ),
                array('year' => $original_year, 'category' => $original_category),
                array('%s', '%s', '%d', '%s'),
                array('%d', '%s')
            );

            if ($result !== false) {
                wp_die('Data berhasil diupdate.');
            } else {
                wp_die('Gagal mengupdate data: ' . $wpdb->last_error);
            }
        } else {
            // Check if record exists for new entries
            $existing = $wpdb->get_row($wpdb->prepare(
                "SELECT id FROM {$this->table_name} WHERE year = %d AND category = %s",
                $year,
                $category
            ));

            if ($existing) {
                // Update existing record
                $result = $wpdb->update(
                    $this->table_name,
                    array(
                        'sumber' => $sumber,
                        'data' => $data,
                        'is_published' => $is_published,
                        'updated_at' => current_time('mysql')
                    ),
                    array('year' => $year, 'category' => $category),
                    array('%s', '%s', '%d', '%s'),
                    array('%d', '%s')
                );
            } else {
                // Insert new record
                $result = $wpdb->insert(
                    $this->table_name,
                    array(
                        'year' => $year,
                        'category' => $category,
                        'sumber' => $sumber,
                        'data' => $data,
                        'is_published' => $is_published,
                        'created_at' => current_time('mysql'),
                        'updated_at' => current_time('mysql')
                    ),
                    array('%d', '%s', '%s', '%s', '%d', '%s', '%s')
                );
            }

            if ($result !== false) {
                wp_die('Data berhasil disimpan.');
            } else {
                wp_die('Gagal menyimpan data: ' . $wpdb->last_error);
            }
        }
    }

    /**
     * NEW: Handle save category
     * Menangani penyimpanan kategori baru atau edit kategori
     */
    public function handle_save_category()
    {
        // Verifikasi nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'save_category_nonce')) {
            wp_send_json_error('Nonce verification failed.');
            return;
        }

        // Cek permission
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized access.');
            return;
        }

        global $wpdb;

        $is_edit = isset($_POST['is_edit']) && $_POST['is_edit'] == '1';
        $category_id = $is_edit ? intval($_POST['category_id']) : 0;
        $original_code = $is_edit ? sanitize_text_field($_POST['original_category_code']) : '';
        $category_code = sanitize_text_field($_POST['category_code']);
        $category_name = sanitize_text_field($_POST['category_name']);
        $category_type = sanitize_text_field($_POST['category_type']);
        $description = sanitize_textarea_field($_POST['description']);
        $is_active = isset($_POST['is_active']) ? 1 : 0;

        // Validate required fields
        if (empty($category_code) || empty($category_name) || empty($category_type)) {
            wp_send_json_error('Semua field wajib harus diisi.');
            return;
        }

        // Validate category code format
        if (!preg_match('/^[a-z0-9_]+$/', $category_code)) {
            wp_send_json_error('Kode kategori hanya boleh menggunakan huruf kecil, angka, dan underscore.');
            return;
        }

        // Check if category code already exists (for new or different code in edit)
        if (!$is_edit || ($is_edit && $category_code !== $original_code)) {
            $existing = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$this->categories_table} WHERE category_code = %s",
                $category_code
            ));

            if ($existing) {
                wp_send_json_error('Kode kategori sudah digunakan. Gunakan kode yang berbeda.');
                return;
            }
        }

        if ($is_edit) {
            // Update existing category
            $result = $wpdb->update(
                $this->categories_table,
                array(
                    'category_code' => $category_code,
                    'category_name' => $category_name,
                    'category_type' => $category_type,
                    'description' => $description,
                    'is_active' => $is_active,
                    'updated_at' => current_time('mysql')
                ),
                array('id' => $category_id),
                array('%s', '%s', '%s', '%s', '%d', '%s'),
                array('%d')
            );

            // If category code changed, update related fields and statistics
            if ($category_code !== $original_code) {
                // Update fields table
                $wpdb->update(
                    $this->fields_table,
                    array('category_code' => $category_code),
                    array('category_code' => $original_code),
                    array('%s'),
                    array('%s')
                );

                // Update statistics table
                $wpdb->update(
                    $this->table_name,
                    array('category' => $category_code),
                    array('category' => $original_code),
                    array('%s'),
                    array('%s')
                );
            }
        } else {
            // Insert new category
            $result = $wpdb->insert(
                $this->categories_table,
                array(
                    'category_code' => $category_code,
                    'category_name' => $category_name,
                    'category_type' => $category_type,
                    'description' => $description,
                    'is_active' => $is_active,
                    'created_at' => current_time('mysql'),
                    'updated_at' => current_time('mysql')
                ),
                array('%s', '%s', '%s', '%s', '%d', '%s', '%s')
            );
        }

        if ($result !== false) {
            wp_send_json_success('Kategori berhasil disimpan.');
        } else {
            wp_send_json_error('Gagal menyimpan kategori: ' . $wpdb->last_error);
        }
    }

    /**
     * NEW: Handle delete category
     * Menangani penghapusan kategori
     */
    public function handle_delete_category()
    {
        // Verifikasi nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'delete_category_nonce')) {
            wp_send_json_error('Nonce verification failed.');
            return;
        }

        // Cek permission
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized access.');
            return;
        }

        global $wpdb;

        $category_code = sanitize_text_field($_POST['category_code']);

        if (empty($category_code)) {
            wp_send_json_error('Kode kategori tidak valid.');
            return;
        }

        // Delete related fields first
        $wpdb->delete(
            $this->fields_table,
            array('category_code' => $category_code),
            array('%s')
        );

        // Delete related statistics
        $wpdb->delete(
            $this->table_name,
            array('category' => $category_code),
            array('%s')
        );

        // Delete category
        $result = $wpdb->delete(
            $this->categories_table,
            array('category_code' => $category_code),
            array('%s')
        );

        if ($result !== false) {
            wp_send_json_success('Kategori berhasil dihapus.');
        } else {
            wp_send_json_error('Gagal menghapus kategori: ' . $wpdb->last_error);
        }
    }

    /**
     * NEW: Handle save field
     * Menangani penyimpanan field baru atau edit field
     */
    public function handle_save_field()
    {
        // Verifikasi nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'save_field_nonce')) {
            wp_send_json_error('Nonce verification failed.');
            return;
        }

        // Cek permission
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized access.');
            return;
        }

        global $wpdb;

        $is_edit = isset($_POST['is_edit']) && $_POST['is_edit'] == '1';
        $field_id = $is_edit ? intval($_POST['field_id']) : 0;
        $original_field_code = $is_edit ? sanitize_text_field($_POST['original_field_code']) : '';
        $category_code = sanitize_text_field($_POST['category_code']);
        $field_code = sanitize_text_field($_POST['field_code']);
        $field_name = sanitize_text_field($_POST['field_name']);
        $field_type = sanitize_text_field($_POST['field_type']);
        $is_required = isset($_POST['is_required']) ? intval($_POST['is_required']) : 1;

        // Validate required fields
        if (empty($category_code) || empty($field_code) || empty($field_name)) {
            wp_send_json_error('Semua field wajib harus diisi.');
            return;
        }

        // Validate field code format
        if (!preg_match('/^[a-z0-9_]+$/', $field_code)) {
            wp_send_json_error('Kode field hanya boleh menggunakan huruf kecil, angka, dan underscore.');
            return;
        }

        // Check if field code already exists in this category (for new or different code in edit)
        if (!$is_edit || ($is_edit && $field_code !== $original_field_code)) {
            $existing = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$this->fields_table} WHERE category_code = %s AND field_code = %s",
                $category_code,
                $field_code
            ));

            if ($existing) {
                wp_send_json_error('Kode field sudah digunakan dalam kategori ini. Gunakan kode yang berbeda.');
                return;
            }
        }

        if ($is_edit) {
            // Update existing field
            $result = $wpdb->update(
                $this->fields_table,
                array(
                    'field_code' => $field_code,
                    'field_name' => $field_name,
                    'field_type' => $field_type,
                    'is_required' => $is_required,
                    'updated_at' => current_time('mysql')
                ),
                array('id' => $field_id),
                array('%s', '%s', '%s', '%d', '%s'),
                array('%d')
            );
        } else {
            // Get next order number
            $max_order = $wpdb->get_var($wpdb->prepare(
                "SELECT MAX(field_order) FROM {$this->fields_table} WHERE category_code = %s",
                $category_code
            ));
            $field_order = ($max_order ? $max_order : 0) + 1;

            // Insert new field
            $result = $wpdb->insert(
                $this->fields_table,
                array(
                    'category_code' => $category_code,
                    'field_code' => $field_code,
                    'field_name' => $field_name,
                    'field_type' => $field_type,
                    'field_order' => $field_order,
                    'is_required' => $is_required,
                    'created_at' => current_time('mysql'),
                    'updated_at' => current_time('mysql')
                ),
                array('%s', '%s', '%s', '%s', '%d', '%d', '%s', '%s')
            );
        }

        if ($result !== false) {
            wp_send_json_success('Field berhasil disimpan.');
        } else {
            wp_send_json_error('Gagal menyimpan field: ' . $wpdb->last_error);
        }
    }

    /**
     * NEW: Handle delete field
     * Menangani penghapusan field
     */
    public function handle_delete_field()
    {
        // Verifikasi nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'delete_field_nonce')) {
            wp_send_json_error('Nonce verification failed.');
            return;
        }

        // Cek permission
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized access.');
            return;
        }

        global $wpdb;

        $field_id = intval($_POST['field_id']);

        if (empty($field_id)) {
            wp_send_json_error('ID field tidak valid.');
            return;
        }

        $result = $wpdb->delete(
            $this->fields_table,
            array('id' => $field_id),
            array('%d')
        );

        if ($result !== false) {
            wp_send_json_success('Field berhasil dihapus.');
        } else {
            wp_send_json_error('Gagal menghapus field: ' . $wpdb->last_error);
        }
    }

    /**
     * NEW: Handle get category fields (UPDATED for AJAX)
     * Menangani pengambilan field untuk kategori tertentu via AJAX
     */
    public function handle_get_category_fields()
    {
        // Verifikasi nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'get_category_fields_nonce')) {
            wp_send_json_error('Nonce verification failed.');
            return;
        }

        global $wpdb;

        $category = sanitize_text_field($_POST['category']);

        if (empty($category)) {
            wp_send_json_error('Kategori tidak valid.');
            return;
        }

        // Get category info
        $category_info = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->categories_table} WHERE category_code = %s AND is_active = 1",
            $category
        ));

        if (!$category_info) {
            wp_send_json_error('Kategori tidak ditemukan.');
            return;
        }

        // Get fields for management modal
        $fields = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->fields_table} WHERE category_code = %s ORDER BY field_order ASC",
            $category
        ));

        // Generate HTML for form fields
        $html = $this->generate_category_fields_html($category, $category_info, array());

        wp_send_json_success(array(
            'html' => $html,
            'fields' => $fields,
            'category_info' => $category_info
        ));
    }

    /**
     * NEW: Generate category fields HTML for AJAX
     * Generate HTML untuk field kategori yang dimuat via AJAX
     */
    private function generate_category_fields_html($category, $category_info, $existing_data = array())
    {
        $html = '<div class="category-section">';
        $html .= '<h3>' . esc_html($category_info->category_name) . '</h3>';

        if ($category_info->category_type === 'dynamic_rw') {
            $html .= $this->generate_dynamic_rw_fields($category, $existing_data);
        } else {
            // Get fields from database
            global $wpdb;
            $fields = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM {$this->fields_table} WHERE category_code = %s ORDER BY field_order ASC",
                $category
            ));

            foreach ($fields as $field) {
                $field_name = $category . '_' . $field->field_code;
                $field_value = isset($existing_data[$field->field_code]) ? $existing_data[$field->field_code] : '';

                $html .= '<div class="field-group">';
                $html .= '<label for="' . esc_attr($field_name) . '" class="form-label">' . esc_html($field->field_name) . ':</label>';
                $html .= '<input type="number" id="' . esc_attr($field_name) . '" name="' . esc_attr($field_name) . '" min="0" step="1" class="form-control" value="' . esc_attr($field_value) . '" />';
                $html .= '</div>';
            }
        }

        $html .= '</div>';

        return $html;
    }

    /**
     * Admin menu (UPDATED with new category management menu)
     * Mendaftarkan menu admin WordPress
     */
    public function add_admin_menu()
    {
        // Menu utama
        add_menu_page(
            'Statistik Desa',
            'Statistik Desa',
            'manage_options',
            'statistic',
            array($this, 'admin_main_page'),
            'dashicons-chart-bar',
            50
        );

        // Submenu: Daftar Statistik
        add_submenu_page(
            'statistic',
            'Daftar Statistik',
            'Daftar Statistik',
            'manage_options',
            'statistic-list',
            array($this, 'admin_list_page')
        );

        // Submenu: Input Statistik
        add_submenu_page(
            'statistic',
            'Input Statistik',
            'Input Statistik',
            'manage_options',
            'statistic-create',
            array($this, 'admin_create_page')
        );

        // NEW: Submenu: Kelola Kategori
        add_submenu_page(
            'statistic',
            'Kelola Kategori',
            'Kelola Kategori',
            'manage_options',
            'statistic-categories',
            array($this, 'admin_categories_page')
        );

        // Hidden submenu: Edit Statistik
        add_submenu_page(
            null, // This hides the menu item from the admin menu
            'Edit Statistik',
            'Edit Statistik',
            'manage_options',
            'statistic-edit',
            array($this, 'admin_edit_page')
        );

        // Submenu: Dokumentasi
        add_submenu_page(
            'statistic',
            'Dokumentasi & Shortcode',
            'Dokumentasi',
            'manage_options',
            'statistic-docs',
            array($this, 'admin_docs_page')
        );
    }

    /**
     * Admin main page
     * Halaman utama admin
     */
    public function admin_main_page()
    {
        global $wpdb;

        // Get statistics for dashboard
        $total_records = $wpdb->get_var("SELECT COUNT(*) FROM {$this->table_name}");
        $published_records = $wpdb->get_var("SELECT COUNT(*) FROM {$this->table_name} WHERE is_published = 1");
        $draft_records = $total_records - $published_records;
        $total_years = $wpdb->get_var("SELECT COUNT(DISTINCT year) FROM {$this->table_name}");
        $total_categories = $wpdb->get_var("SELECT COUNT(DISTINCT category) FROM {$this->table_name}");
        $custom_categories = $wpdb->get_var("SELECT COUNT(*) FROM {$this->categories_table} WHERE is_active = 1");

        // Get recent activities
        $recent_records = $wpdb->get_results("SELECT * FROM {$this->table_name} ORDER BY updated_at DESC LIMIT 5");

        // Get year statistics
        $year_stats = $wpdb->get_results("SELECT year, COUNT(*) as count FROM {$this->table_name} GROUP BY year ORDER BY year DESC LIMIT 5");

        // Get category statistics
        $category_stats = $wpdb->get_results("SELECT category, COUNT(*) as count FROM {$this->table_name} GROUP BY category ORDER BY count DESC LIMIT 5");

        $categories = $this->get_categories();

        ?>
        <div class="wrap">
            <style>
                /* Dashboard Styling */
                .dashboard-container {
                    margin: 20px 0;
                }

                .dashboard-header {
                    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                    color: white;
                    padding: 30px;
                    border-radius: 12px;
                    margin-bottom: 30px;
                    box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
                }

                .dashboard-header h1 {
                    margin: 0 0 10px 0;
                    font-size: 32px;
                    font-weight: 700;
                }

                .dashboard-subtitle {
                    font-size: 16px;
                    opacity: 0.9;
                    margin: 0 0 20px 0;
                }

                .dashboard-version {
                    background: rgba(255, 255, 255, 0.2);
                    padding: 5px 12px;
                    border-radius: 20px;
                    font-size: 12px;
                    font-weight: 600;
                    display: inline-block;
                }

                .stats-grid {
                    display: grid;
                    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
                    gap: 20px;
                    margin-bottom: 30px;
                }

                .stat-card {
                    background: white;
                    padding: 25px;
                    border-radius: 12px;
                    box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
                    border-left: 4px solid;
                    transition: transform 0.3s ease, box-shadow 0.3s ease;
                }

                .stat-card:hover {
                    transform: translateY(-2px);
                    box-shadow: 0 4px 20px rgba(0, 0, 0, 0.15);
                }

                .stat-card.total {
                    border-left-color: #3498db;
                }

                .stat-card.published {
                    border-left-color: #2ecc71;
                }

                .stat-card.draft {
                    border-left-color: #f39c12;
                }

                .stat-card.years {
                    border-left-color: #9b59b6;
                }

                .stat-card.categories {
                    border-left-color: #e74c3c;
                }

                .stat-card.custom {
                    border-left-color: #1abc9c;
                }

                .stat-number {
                    font-size: 36px;
                    font-weight: 700;
                    margin: 0 0 5px 0;
                    color: #2c3e50;
                }

                .stat-label {
                    font-size: 14px;
                    color: #7f8c8d;
                    font-weight: 500;
                    text-transform: uppercase;
                    letter-spacing: 0.5px;
                }

                .stat-icon {
                    float: right;
                    font-size: 24px;
                    opacity: 0.3;
                    margin-top: -5px;
                }

                .dashboard-content {
                    display: grid;
                    grid-template-columns: 2fr 1fr;
                    gap: 30px;
                    margin-bottom: 30px;
                }

                .main-content {
                    background: white;
                    padding: 25px;
                    border-radius: 12px;
                    box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
                }

                .sidebar-content {
                    display: flex;
                    flex-direction: column;
                    gap: 20px;
                }

                .widget {
                    background: white;
                    padding: 20px;
                    border-radius: 12px;
                    box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
                }

                .widget h3 {
                    margin: 0 0 15px 0;
                    color: #2c3e50;
                    font-size: 18px;
                    font-weight: 600;
                    border-bottom: 2px solid #ecf0f1;
                    padding-bottom: 10px;
                }

                .quick-actions {
                    display: grid;
                    grid-template-columns: 1fr 1fr;
                    gap: 15px;
                    margin-bottom: 20px;
                }

                .action-btn {
                    display: flex;
                    align-items: center;
                    gap: 10px;
                    padding: 15px;
                    background: #f8f9fa;
                    border: 2px solid #e9ecef;
                    border-radius: 8px;
                    text-decoration: none;
                    color: #495057;
                    font-weight: 500;
                    transition: all 0.3s ease;
                }

                .action-btn:hover {
                    background: #e9ecef;
                    border-color: #007bff;
                    color: #007bff;
                    text-decoration: none;
                    transform: translateY(-1px);
                }

                .action-btn.primary {
                    background: #007bff;
                    border-color: #007bff;
                    color: white;
                }

                .action-btn.primary:hover {
                    background: #0056b3;
                    border-color: #0056b3;
                    color: white;
                }

                .action-btn.new {
                    background: #28a745;
                    border-color: #28a745;
                    color: white;
                }

                .action-btn.new:hover {
                    background: #218838;
                    border-color: #218838;
                    color: white;
                }

                .recent-activity {
                    list-style: none;
                    padding: 0;
                    margin: 0;
                }

                .recent-activity li {
                    padding: 12px 0;
                    border-bottom: 1px solid #ecf0f1;
                    display: flex;
                    justify-content: space-between;
                    align-items: center;
                }

                .recent-activity li:last-child {
                    border-bottom: none;
                }

                .activity-info {
                    flex: 1;
                }

                .activity-title {
                    font-weight: 600;
                    color: #2c3e50;
                    margin-bottom: 3px;
                }

                .activity-meta {
                    font-size: 12px;
                    color: #7f8c8d;
                }

                .activity-status {
                    padding: 3px 8px;
                    border-radius: 12px;
                    font-size: 11px;
                    font-weight: 600;
                }

                .status-published {
                    background: #d1e7dd;
                    color: #0a3622;
                }

                .status-draft {
                    background: #fff3cd;
                    color: #664d03;
                }

                .chart-container {
                    margin-top: 20px;
                }

                .chart-item {
                    display: flex;
                    justify-content: space-between;
                    align-items: center;
                    padding: 8px 0;
                    border-bottom: 1px solid #ecf0f1;
                }

                .chart-item:last-child {
                    border-bottom: none;
                }

                .chart-label {
                    font-size: 13px;
                    color: #495057;
                    font-weight: 500;
                }

                .chart-value {
                    font-size: 14px;
                    font-weight: 600;
                    color: #007bff;
                }

                .getting-started {
                    background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
                    color: white;
                    padding: 25px;
                    border-radius: 12px;
                    margin-bottom: 20px;
                }

                .getting-started h3 {
                    margin: 0 0 15px 0;
                    color: white;
                    border-bottom: 1px solid rgba(255, 255, 255, 0.3);
                    padding-bottom: 10px;
                }

                .getting-started ul {
                    margin: 0;
                    padding-left: 20px;
                }

                .getting-started li {
                    margin-bottom: 8px;
                    opacity: 0.9;
                }

                .empty-state {
                    text-align: center;
                    padding: 40px 20px;
                    color: #7f8c8d;
                }

                .empty-state-icon {
                    font-size: 48px;
                    margin-bottom: 15px;
                    opacity: 0.5;
                }

                /* Responsive Design */
                @media (max-width: 1200px) {
                    .dashboard-content {
                        grid-template-columns: 1fr;
                    }
                }

                @media (max-width: 768px) {
                    .stats-grid {
                        grid-template-columns: 1fr;
                    }

                    .quick-actions {
                        grid-template-columns: 1fr;
                    }

                    .dashboard-header {
                        padding: 20px;
                    }

                    .dashboard-header h1 {
                        font-size: 24px;
                    }
                }
            </style>

            <div class="dashboard-container">
                <!-- Dashboard Header -->
                <div class="dashboard-header">
                    <h1>📊 Dashboard Statistik Desa/Kelurahan</h1>
                    <p class="dashboard-subtitle">Kelola dan pantau data statistik desa/kelurahan dengan mudah dan efisien</p>
                    <span class="dashboard-version">v2.0.1 - Fixed Database Issues</span>
                </div>

                <!-- Statistics Cards -->
                <div class="stats-grid">
                    <div class="stat-card total">
                        <div class="stat-icon">📊</div>
                        <div class="stat-number"><?php echo number_format($total_records); ?></div>
                        <div class="stat-label">Total Data</div>
                    </div>
                    <div class="stat-card published">
                        <div class="stat-icon">✅</div>
                        <div class="stat-number"><?php echo number_format($published_records); ?></div>
                        <div class="stat-label">Data Dipublikasi</div>
                    </div>
                    <div class="stat-card draft">
                        <div class="stat-icon">📝</div>
                        <div class="stat-number"><?php echo number_format($draft_records); ?></div>
                        <div class="stat-label">Data Draft</div>
                    </div>
                    <div class="stat-card years">
                        <div class="stat-icon">📅</div>
                        <div class="stat-number"><?php echo number_format($total_years); ?></div>
                        <div class="stat-label">Tahun Tercatat</div>
                    </div>
                    <div class="stat-card categories">
                        <div class="stat-icon">🏷️</div>
                        <div class="stat-number"><?php echo number_format($total_categories); ?></div>
                        <div class="stat-label">Kategori Aktif</div>
                    </div>
                    <div class="stat-card custom">
                        <div class="stat-icon">⚙️</div>
                        <div class="stat-number"><?php echo number_format($custom_categories); ?></div>
                        <div class="stat-label">Kategori Custom</div>
                    </div>
                </div>

                <!-- Main Dashboard Content -->
                <div class="dashboard-content">
                    <!-- Main Content Area -->
                    <div class="main-content">
                        <h2>🚀 Quick Actions</h2>
                        <p>Akses cepat ke fitur-fitur utama plugin statistik</p>
                        <div class="quick-actions">
                            <a href="<?php echo admin_url('admin.php?page=statistic-create'); ?>" class="action-btn primary">
                                <span>➕</span>
                                <div>
                                    <div>Input Data Baru</div>
                                    <small>Tambah statistik terbaru</small>
                                </div>
                            </a>
                            <a href="<?php echo admin_url('admin.php?page=statistic-categories'); ?>" class="action-btn new">
                                <span>🏷️</span>
                                <div>
                                    <div>Kelola Kategori</div>
                                    <small>Tambah kategori & field</small>
                                </div>
                            </a>
                            <a href="<?php echo admin_url('admin.php?page=statistic-list'); ?>" class="action-btn">
                                <span>📋</span>
                                <div>
                                    <div>Kelola Data</div>
                                    <small>Lihat & edit data</small>
                                </div>
                            </a>
                            <a href="<?php echo admin_url('admin.php?page=statistic-docs'); ?>" class="action-btn">
                                <span>📚</span>
                                <div>
                                    <div>Dokumentasi</div>
                                    <small>Panduan & shortcode</small>
                                </div>
                            </a>
                        </div>

                        <?php if ($total_records == 0): ?>
                            <!-- Getting Started Guide -->
                            <div class="getting-started">
                                <h3>🎯 Panduan Memulai</h3>
                                <p>Selamat datang! Ikuti langkah-langkah berikut untuk memulai:</p>
                                <ul>
                                    <li><strong>Langkah 1:</strong> Klik "Kelola Kategori" untuk menambah kategori custom atau
                                        gunakan yang sudah ada</li>
                                    <li><strong>Langkah 2:</strong> Klik "Input Data Baru" untuk menambah statistik pertama</li>
                                    <li><strong>Langkah 3:</strong> Pilih tahun dan kategori data yang ingin diinput</li>
                                    <li><strong>Langkah 4:</strong> Isi data statistik sesuai kategori yang dipilih</li>
                                    <li><strong>Langkah 5:</strong> Gunakan shortcode untuk menampilkan data di frontend</li>
                                    <li><strong>Langkah 6:</strong> Baca dokumentasi untuk fitur lanjutan</li>
                                </ul>
                            </div>
                        <?php endif; ?>

                        <!-- Shortcode Examples -->
                        <div style="background: #f8f9fa; padding: 20px; border-radius: 8px; margin-top: 20px;">
                            <h3>💡 Contoh Shortcode Populer</h3>
                            <div style="display: grid; gap: 15px;">
                                <div
                                    style="background: white; padding: 15px; border-radius: 6px; border-left: 4px solid #007bff;">
                                    <strong>Tampilan Card:</strong><br>
                                    <code
                                        style="color: #e83e8c;">[statistic_display year="<?php echo date('Y'); ?>" category="agama"]</code>
                                </div>
                                <div
                                    style="background: white; padding: 15px; border-radius: 6px; border-left: 4px solid #28a745;">
                                    <strong>Tampilan Tabel:</strong><br>
                                    <code
                                        style="color: #e83e8c;">[statistic_table year="<?php echo date('Y'); ?>" limit="5"]</code>
                                </div>
                                <div
                                    style="background: white; padding: 15px; border-radius: 6px; border-left: 4px solid #ffc107;">
                                    <strong>Grafik Pie:</strong><br>
                                    <code
                                        style="color: #e83e8c;">[statistic_chart year="<?php echo date('Y'); ?>" category="jenis_kelamin" type="pie"]</code>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Sidebar -->
                    <div class="sidebar-content">
                        <!-- Recent Activity -->
                        <div class="widget">
                            <h3>📈 Aktivitas Terbaru</h3>
                            <?php if (!empty($recent_records)): ?>
                                <ul class="recent-activity">
                                    <?php foreach ($recent_records as $record): ?>
                                        <li>
                                            <div class="activity-info">
                                                <div class="activity-title">
                                                    <?php echo esc_html($categories[$record->category] ?? $record->category); ?>
                                                </div>
                                                <div class="activity-meta">
                                                    <?php echo esc_html($record->year); ?> •
                                                    <?php echo esc_html(date('d/m/Y H:i', strtotime($record->updated_at))); ?>
                                                </div>
                                            </div>
                                            <span
                                                class="activity-status <?php echo $record->is_published ? 'status-published' : 'status-draft'; ?>">
                                                <?php echo $record->is_published ? 'Published' : 'Draft'; ?>
                                            </span>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php else: ?>
                                <div class="empty-state">
                                    <div class="empty-state-icon">📊</div>
                                    <p>Belum ada aktivitas</p>
                                </div>
                            <?php endif; ?>
                        </div>

                        <!-- Year Statistics -->
                        <?php if (!empty($year_stats)): ?>
                            <div class="widget">
                                <h3>📅 Statistik per Tahun</h3>
                                <div class="chart-container">
                                    <?php foreach ($year_stats as $stat): ?>
                                        <div class="chart-item">
                                            <span class="chart-label">Tahun <?php echo esc_html($stat->year); ?></span>
                                            <span class="chart-value"><?php echo esc_html($stat->count); ?> data</span>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endif; ?>

                        <!-- Category Statistics -->
                        <?php if (!empty($category_stats)): ?>
                            <div class="widget">
                                <h3>🏷️ Kategori Terpopuler</h3>
                                <div class="chart-container">
                                    <?php foreach ($category_stats as $stat): ?>
                                        <div class="chart-item">
                                            <span class="chart-label">
                                                <?php echo esc_html(wp_trim_words($categories[$stat->category] ?? $stat->category, 3, '...')); ?>
                                            </span>
                                            <span class="chart-value"><?php echo esc_html($stat->count); ?></span>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endif; ?>

                        <!-- System Info -->
                        <div class="widget">
                            <h3>ℹ️ Informasi Sistem</h3>
                            <div class="chart-container">
                                <div class="chart-item">
                                    <span class="chart-label">Plugin Version</span>
                                    <span class="chart-value">v<?php echo $this->version; ?></span>
                                </div>
                                <div class="chart-item">
                                    <span class="chart-label">WordPress Version</span>
                                    <span class="chart-value"><?php echo get_bloginfo('version'); ?></span>
                                </div>
                                <div class="chart-item">
                                    <span class="chart-label">PHP Version</span>
                                    <span class="chart-value"><?php echo PHP_VERSION; ?></span>
                                </div>
                                <div class="chart-item">
                                    <span class="chart-label">Database</span>
                                    <span class="chart-value">MySQL</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Admin list page - IMPROVED VERSION WITH BETTER TABLE DESIGN
     * Halaman daftar data statistik dengan tabel yang lebih rapi dan modern
     */
    public function admin_list_page()
    {
        global $wpdb;

        // Handle search and filter
        $search = isset($_GET['s']) ? sanitize_text_field($_GET['s']) : '';
        $filter_year = isset($_GET['filter_year']) ? intval($_GET['filter_year']) : '';
        $filter_category = isset($_GET['filter_category']) ? sanitize_text_field($_GET['filter_category']) : '';
        $filter_status = isset($_GET['filter_status']) ? sanitize_text_field($_GET['filter_status']) : '';

        // Build query with filters
        $where_conditions = array();
        $where_values = array();

        if (!empty($search)) {
            $where_conditions[] = "(category LIKE %s OR sumber LIKE %s)";
            $where_values[] = '%' . $wpdb->esc_like($search) . '%';
            $where_values[] = '%' . $wpdb->esc_like($search) . '%';
        }

        if (!empty($filter_year)) {
            $where_conditions[] = "year = %d";
            $where_values[] = $filter_year;
        }

        if (!empty($filter_category)) {
            $where_conditions[] = "category = %s";
            $where_values[] = $filter_category;
        }

        if ($filter_status !== '') {
            $where_conditions[] = "is_published = %d";
            $where_values[] = intval($filter_status);
        }

        $where_clause = '';
        if (!empty($where_conditions)) {
            $where_clause = 'WHERE ' . implode(' AND ', $where_conditions);
        }

        $query = "SELECT * FROM {$this->table_name} {$where_clause} ORDER BY year DESC, category ASC";

        if (!empty($where_values)) {
            $results = $wpdb->get_results($wpdb->prepare($query, $where_values));
        } else {
            $results = $wpdb->get_results($query);
        }

        // Get unique years and categories for filters
        $years = $wpdb->get_col("SELECT DISTINCT year FROM {$this->table_name} ORDER BY year DESC");
        $categories = $this->get_categories();

        ?>
        <div class="wrap">
            <style>
                /* Modern Admin Table Styling */
                .statistics-admin-container {
                    background: #fff;
                    margin: 20px 0;
                    border-radius: 8px;
                    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
                }

                .statistics-header {
                    padding: 20px 25px;
                    border-bottom: 1px solid #e1e5e9;
                    background: #f8f9fa;
                    border-radius: 8px 8px 0 0;
                }

                .statistics-header h1 {
                    margin: 0 0 10px 0;
                    color: #32373c;
                    font-size: 24px;
                    font-weight: 600;
                }

                .statistics-header .description {
                    color: #646970;
                    margin: 0;
                    font-size: 14px;
                }

                .statistics-filters {
                    padding: 20px 25px;
                    background: #fafafa;
                    border-bottom: 1px solid #e1e5e9;
                    display: flex;
                    flex-wrap: wrap;
                    gap: 15px;
                    align-items: center;
                }

                .filter-group {
                    display: flex;
                    flex-direction: column;
                    gap: 5px;
                }

                .filter-group label {
                    font-size: 12px;
                    font-weight: 600;
                    color: #646970;
                    text-transform: uppercase;
                    letter-spacing: 0.5px;
                }

                .filter-group select,
                .filter-group input[type="text"] {
                    padding: 6px 10px;
                    border: 1px solid #ddd;
                    border-radius: 4px;
                    font-size: 13px;
                    min-width: 120px;
                }

                .filter-actions {
                    margin-left: auto;
                    display: flex;
                    gap: 10px;
                }

                .statistics-content {
                    padding: 0;
                }

                .stats-summary {
                    padding: 15px 25px;
                    background: #f0f6fc;
                    border-bottom: 1px solid #e1e5e9;
                    display: flex;
                    justify-content: space-between;
                    align-items: center;
                    font-size: 13px;
                    color: #646970;
                }

                .summary-left {
                    display: flex;
                    gap: 20px;
                }

                .summary-item {
                    display: flex;
                    align-items: center;
                    gap: 5px;
                }

                .summary-item .count {
                    font-weight: 600;
                    color: #2271b1;
                }

                .statistics-table-container {
                    overflow-x: auto;
                }

                .statistics-table {
                    width: 100%;
                    border-collapse: collapse;
                    margin: 0;
                    background: #fff;
                }

                .statistics-table th {
                    background: #f6f7f7;
                    color: #32373c;
                    font-weight: 600;
                    font-size: 13px;
                    padding: 12px 15px;
                    text-align: left;
                    border-bottom: 1px solid #e1e5e9;
                    white-space: nowrap;
                }

                .statistics-table td {
                    padding: 12px 15px;
                    border-bottom: 1px solid #f0f0f1;
                    vertical-align: top;
                    font-size: 13px;
                    line-height: 1.4;
                }

                .statistics-table tbody tr:hover {
                    background: #f6f7f7;
                }

                .year-badge {
                    display: inline-block;
                    background: #2271b1;
                    color: white;
                    padding: 4px 8px;
                    border-radius: 12px;
                    font-size: 11px;
                    font-weight: 600;
                    min-width: 45px;
                    text-align: center;
                }

                .category-name {
                    font-weight: 600;
                    color: #32373c;
                    margin-bottom: 3px;
                }

                .category-code {
                    font-size: 11px;
                    color: #646970;
                    font-family: monospace;
                    background: #f0f0f1;
                    padding: 2px 6px;
                    border-radius: 3px;
                }

                .data-preview {
                    max-width: 300px;
                }

                .data-summary {
                    display: flex;
                    flex-wrap: wrap;
                    gap: 4px;
                    margin-bottom: 8px;
                }

                .data-item {
                    background: #e7f3ff;
                    color: #0073aa;
                    padding: 2px 6px;
                    border-radius: 10px;
                    font-size: 11px;
                    font-weight: 500;
                    white-space: nowrap;
                }

                .data-item.rw {
                    background: #fff2e7;
                    color: #d63638;
                }

                .data-more {
                    color: #646970;
                    font-style: italic;
                    font-size: 11px;
                }

                .source-text {
                    color: #646970;
                    font-style: italic;
                    max-width: 200px;
                    word-wrap: break-word;
                }

                .status-badge {
                    display: inline-flex;
                    align-items: center;
                    gap: 5px;
                    padding: 4px 8px;
                    border-radius: 12px;
                    font-size: 11px;
                    font-weight: 600;
                    text-transform: uppercase;
                }

                .status-published {
                    background: #d1e7dd;
                    color: #0a3622;
                }

                .status-draft {
                    background: #fff3cd;
                    color: #664d03;
                }

                .status-indicator {
                    width: 6px;
                    height: 6px;
                    border-radius: 50%;
                    background: currentColor;
                }

                .actions-cell {
                    white-space: nowrap;
                }

                .action-btn {
                    display: inline-flex;
                    align-items: center;
                    gap: 4px;
                    padding: 4px 8px;
                    margin: 0 2px;
                    border: 1px solid;
                    border-radius: 3px;
                    text-decoration: none;
                    font-size: 11px;
                    font-weight: 500;
                    transition: all 0.2s ease;
                }

                .action-btn.edit {
                    background: #fff;
                    color: #2271b1;
                    border-color: #2271b1;
                }

                .action-btn.edit:hover {
                    background: #2271b1;
                    color: #fff;
                    text-decoration: none;
                }

                .action-btn.delete {
                    background: #fff;
                    color: #d63638;
                    border-color: #d63638;
                }

                .action-btn.delete:hover {
                    background: #d63638;
                    color: #fff;
                    text-decoration: none;
                }

                .empty-state {
                    text-align: center;
                    padding: 60px 20px;
                    color: #646970;
                }

                .empty-state-icon {
                    font-size: 48px;
                    margin-bottom: 15px;
                    opacity: 0.5;
                }

                .empty-state h3 {
                    margin: 0 0 10px 0;
                    color: #32373c;
                }

                .empty-state p {
                    margin: 0 0 20px 0;
                    font-size: 14px;
                }

                .btn-primary {
                    background: #2271b1;
                    color: white;
                    border: 1px solid #2271b1;
                    padding: 8px 16px;
                    border-radius: 4px;
                    text-decoration: none;
                    font-size: 13px;
                    font-weight: 500;
                    display: inline-flex;
                    align-items: center;
                    gap: 6px;
                }

                .btn-primary:hover {
                    background: #135e96;
                    border-color: #135e96;
                    color: white;
                    text-decoration: none;
                }

                .btn-secondary {
                    background: #f6f7f7;
                    color: #3c434a;
                    border: 1px solid #c3c4c7;
                    padding: 6px 12px;
                    border-radius: 4px;
                    text-decoration: none;
                    font-size: 12px;
                    font-weight: 500;
                }

                .btn-secondary:hover {
                    background: #f0f0f1;
                    border-color: #8c8f94;
                    color: #3c434a;
                    text-decoration: none;
                }

                /* Responsive Design */
                @media (max-width: 1200px) {

                    .statistics-table th:nth-child(4),
                    .statistics-table td:nth-child(4) {
                        display: none;
                    }
                }

                @media (max-width: 768px) {
                    .statistics-filters {
                        flex-direction: column;
                        align-items: stretch;
                    }

                    .filter-actions {
                        margin-left: 0;
                        justify-content: stretch;
                    }

                    .statistics-table th:nth-child(3),
                    .statistics-table td:nth-child(3),
                    .statistics-table th:nth-child(5),
                    .statistics-table td:nth-child(5) {
                        display: none;
                    }

                    .stats-summary {
                        flex-direction: column;
                        gap: 10px;
                        align-items: stretch;
                    }

                    .summary-left {
                        justify-content: space-between;
                    }
                }
            </style>

            <div class="statistics-admin-container">
                <!-- Header -->
                <div class="statistics-header">
                    <h1>📋 Daftar Data Statistik</h1>
                    <p class="description">Kelola semua data statistik desa/kelurahan yang telah diinput. Anda dapat mencari,
                        memfilter, mengedit, dan menghapus data.</p>
                </div>

                <!-- Filters -->
                <div class="statistics-filters">
                    <form method="get" style="display: contents;">
                        <input type="hidden" name="page" value="statistic-list">

                        <div class="filter-group">
                            <label>🔍 Pencarian</label>
                            <input type="text" name="s" value="<?php echo esc_attr($search); ?>"
                                placeholder="Cari kategori atau sumber...">
                        </div>

                        <div class="filter-group">
                            <label>📅 Tahun</label>
                            <select name="filter_year">
                                <option value="">Semua Tahun</option>
                                <?php foreach ($years as $year): ?>
                                    <option value="<?php echo esc_attr($year); ?>" <?php selected($filter_year, $year); ?>>
                                        <?php echo esc_html($year); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="filter-group">
                            <label>🏷️ Kategori</label>
                            <select name="filter_category">
                                <option value="">Semua Kategori</option>
                                <?php foreach ($categories as $key => $name): ?>
                                    <option value="<?php echo esc_attr($key); ?>" <?php selected($filter_category, $key); ?>>
                                        <?php echo esc_html(wp_trim_words($name, 4, '...')); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="filter-group">
                            <label>📊 Status</label>
                            <select name="filter_status">
                                <option value="">Semua Status</option>
                                <option value="1" <?php selected($filter_status, '1'); ?>>Published</option>
                                <option value="0" <?php selected($filter_status, '0'); ?>>Draft</option>
                            </select>
                        </div>

                        <div class="filter-actions">
                            <button type="submit" class="btn-secondary">🔍 Filter</button>
                            <a href="<?php echo admin_url('admin.php?page=statistic-list'); ?>" class="btn-secondary">🔄
                                Reset</a>
                            <a href="<?php echo admin_url('admin.php?page=statistic-create'); ?>" class="btn-primary">
                                ➕ Tambah Data
                            </a>
                        </div>
                    </form>
                </div>

                <!-- Summary -->
                <div class="stats-summary">
                    <div class="summary-left">
                        <div class="summary-item">
                            <span>📊 Total:</span>
                            <span class="count"><?php echo count($results); ?></span>
                        </div>
                        <div class="summary-item">
                            <span>✅ Published:</span>
                            <span class="count"><?php echo count(array_filter($results, function ($r) {
                                return $r->is_published;
                            })); ?></span>
                        </div>
                        <div class="summary-item">
                            <span>📝 Draft:</span>
                            <span class="count"><?php echo count(array_filter($results, function ($r) {
                                return !$r->is_published;
                            })); ?></span>
                        </div>
                    </div>
                    <div class="summary-right">
                        <?php if (!empty($search) || !empty($filter_year) || !empty($filter_category) || $filter_status !== ''): ?>
                            <span>🔍 Filter aktif</span>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Content -->
                <div class="statistics-content">
                    <?php if (!empty($results)): ?>
                        <div class="statistics-table-container">
                            <table class="statistics-table">
                                <thead>
                                    <tr>
                                        <th style="width: 80px;">📅 Tahun</th>
                                        <th style="width: 200px;">🏷️ Kategori</th>
                                        <th style="width: 300px;">📊 Data Preview</th>
                                        <th style="width: 200px;">📝 Sumber</th>
                                        <th style="width: 100px;">📊 Status</th>
                                        <th style="width: 150px;">⚙️ Aksi</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($results as $row): ?>
                                        <?php
                                        $data = json_decode($row->data, true) ?: array();
                                        $category_name = $categories[$row->category] ?? $row->category;
                                        $is_dynamic_rw = $this->is_dynamic_rw_category($row->category);
                                        ?>
                                        <tr>
                                            <td>
                                                <span class="year-badge"><?php echo esc_html($row->year); ?></span>
                                            </td>
                                            <td>
                                                <div class="category-name"><?php echo esc_html($category_name); ?></div>
                                                <div class="category-code"><?php echo esc_html($row->category); ?></div>
                                            </td>
                                            <td>
                                                <div class="data-preview">
                                                    <?php if (!empty($data)): ?>
                                                        <div class="data-summary">
                                                            <?php
                                                            $count = 0;
                                                            $max_display = 4;
                                                            foreach ($data as $key => $value):
                                                                if ($count >= $max_display)
                                                                    break;
                                                                $display_key = $key;
                                                                if ($is_dynamic_rw && strpos($key, 'rw_') === 0) {
                                                                    $display_key = 'RW ' . str_replace('rw_', '', $key);
                                                                } else {
                                                                    $category_fields = $this->get_category_fields();
                                                                    if (isset($category_fields[$row->category][$key])) {
                                                                        $display_key = $category_fields[$row->category][$key];
                                                                    }
                                                                }
                                                                ?>
                                                                <span class="data-item <?php echo $is_dynamic_rw ? 'rw' : ''; ?>">
                                                                    <?php echo esc_html(wp_trim_words($display_key, 2, '')); ?>:
                                                                    <?php echo esc_html($value); ?>
                                                                </span>
                                                                <?php
                                                                $count++;
                                                            endforeach;
                                                            ?>
                                                        </div>
                                                        <?php if (count($data) > $max_display): ?>
                                                            <div class="data-more">+<?php echo (count($data) - $max_display); ?> data lainnya
                                                            </div>
                                                        <?php endif; ?>
                                                    <?php else: ?>
                                                        <span style="color: #d63638; font-style: italic;">Data kosong</span>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                            <td>
                                                <div class="source-text">
                                                    <?php echo !empty($row->sumber) ? esc_html($row->sumber) : '<em style="color: #646970;">Tidak ada sumber</em>'; ?>
                                                </div>
                                            </td>
                                            <td>
                                                <span
                                                    class="status-badge <?php echo $row->is_published ? 'status-published' : 'status-draft'; ?>">
                                                    <span class="status-indicator"></span>
                                                    <?php echo $row->is_published ? 'Published' : 'Draft'; ?>
                                                </span>
                                            </td>
                                            <td class="actions-cell">
                                                <a href="<?php echo admin_url('admin.php?page=statistic-edit&year=' . $row->year . '&category=' . urlencode($row->category)); ?>"
                                                    class="action-btn edit" title="Edit data">
                                                    ✏️ Edit
                                                </a>
                                                <a href="#"
                                                    onclick="deleteStatistic(<?php echo $row->year; ?>, '<?php echo esc_js($row->category); ?>', '<?php echo esc_js($category_name); ?>')"
                                                    class="action-btn delete" title="Hapus data">
                                                    🗑️ Hapus
                                                </a>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <div class="empty-state">
                            <div class="empty-state-icon">📊</div>
                            <h3>
                                <?php if (!empty($search) || !empty($filter_year) || !empty($filter_category) || $filter_status !== ''): ?>
                                    Tidak Ada Data yang Sesuai Filter
                                <?php else: ?>
                                    Belum Ada Data Statistik
                                <?php endif; ?>
                            </h3>
                            <p>
                                <?php if (!empty($search) || !empty($filter_year) || !empty($filter_category) || $filter_status !== ''): ?>
                                    Coba ubah kriteria pencarian atau filter untuk menemukan data yang Anda cari.
                                <?php else: ?>
                                    Mulai dengan menambahkan data statistik pertama untuk desa/kelurahan Anda.
                                <?php endif; ?>
                            </p>
                            <a href="<?php echo admin_url('admin.php?page=statistic-create'); ?>" class="btn-primary">
                                ➕ Tambah Data Pertama
                            </a>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <script>
            function deleteStatistic(year, category, categoryName) {
                if (confirm(`⚠️ Apakah Anda yakin ingin menghapus data statistik?\n\nTahun: ${year}\nKategori: ${categoryName}\n\nTindakan ini tidak dapat dibatalkan.`)) {
                    const formData = new FormData();
                    formData.append('action', 'statistic_delete');
                    formData.append('year', year);
                    formData.append('category', category);
                    formData.append('nonce', '<?php echo wp_create_nonce('statistic_delete_nonce'); ?>');

                    fetch(ajaxurl, {
                        method: 'POST',
                        body: formData
                    })
                        .then(response => response.text())
                        .then(data => {
                            alert(data);
                            if (data.includes('berhasil')) {
                                location.reload();
                            }
                        })
                        .catch(error => {
                            console.error('Error:', error);
                            alert('❌ Terjadi kesalahan saat menghapus data');
                        });
                }
            }
        </script>
        <?php
    }

    /**
     * Handle delete action
     * Menangani penghapusan data statistik
     */
    public function handle_delete()
    {
        // Verifikasi nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'statistic_delete_nonce')) {
            wp_die('Nonce verification failed.');
        }

        // Cek permission
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized access.');
        }

        global $wpdb;

        $year = intval($_POST['year']);
        $category = sanitize_text_field($_POST['category']);

        if (empty($year) || empty($category)) {
            wp_die('Parameter tidak valid.');
        }

        $result = $wpdb->delete(
            $this->table_name,
            array('year' => $year, 'category' => $category),
            array('%d', '%s')
        );

        if ($result !== false) {
            wp_die('Data berhasil dihapus.');
        } else {
            wp_die('Gagal menghapus data: ' . $wpdb->last_error);
        }
    }

    /**
     * Admin documentation page
     * Halaman dokumentasi dan panduan penggunaan
     */
    public function admin_docs_page()
    {
        ?>
        <div class="wrap">
            <style>
                /* Documentation Styling */
                .docs-container {
                    max-width: 1200px;
                    margin: 20px 0;
                }

                .docs-header {
                    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                    color: white;
                    padding: 30px;
                    border-radius: 12px;
                    margin-bottom: 30px;
                    box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
                }

                .docs-header h1 {
                    margin: 0 0 10px 0;
                    font-size: 28px;
                    font-weight: 700;
                }

                .docs-subtitle {
                    font-size: 16px;
                    opacity: 0.9;
                    margin: 0;
                }

                .docs-grid {
                    display: grid;
                    grid-template-columns: 250px 1fr;
                    gap: 30px;
                    margin-bottom: 30px;
                }

                .docs-sidebar {
                    background: #f8f9fa;
                    padding: 20px;
                    border-radius: 8px;
                    height: fit-content;
                    position: sticky;
                    top: 20px;
                }

                .docs-nav {
                    list-style: none;
                    padding: 0;
                    margin: 0;
                }

                .docs-nav li {
                    margin-bottom: 8px;
                }

                .docs-nav a {
                    display: block;
                    padding: 8px 12px;
                    color: #495057;
                    text-decoration: none;
                    border-radius: 4px;
                    font-size: 14px;
                    font-weight: 500;
                    transition: all 0.2s ease;
                }

                .docs-nav a:hover,
                .docs-nav a.active {
                    background: #007bff;
                    color: white;
                    text-decoration: none;
                }

                .docs-content {
                    background: white;
                    padding: 30px;
                    border-radius: 8px;
                    box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
                }

                .docs-section {
                    margin-bottom: 40px;
                }

                .docs-section:last-child {
                    margin-bottom: 0;
                }

                .docs-section h2 {
                    color: #2c3e50;
                    font-size: 24px;
                    font-weight: 600;
                    margin: 0 0 20px 0;
                    padding-bottom: 10px;
                    border-bottom: 2px solid #ecf0f1;
                }

                .docs-section h3 {
                    color: #34495e;
                    font-size: 18px;
                    font-weight: 600;
                    margin: 25px 0 15px 0;
                }

                .docs-section p {
                    line-height: 1.6;
                    margin-bottom: 15px;
                    color: #555;
                }

                .code-block {
                    background: #f8f9fa;
                    border: 1px solid #e9ecef;
                    border-radius: 6px;
                    padding: 15px;
                    margin: 15px 0;
                    font-family: 'Courier New', monospace;
                    font-size: 13px;
                    overflow-x: auto;
                    position: relative;
                }

                .code-block code {
                    color: #e83e8c;
                    background: none;
                    padding: 0;
                }

                .code-title {
                    background: #007bff;
                    color: white;
                    padding: 8px 15px;
                    margin: -15px -15px 15px -15px;
                    font-size: 12px;
                    font-weight: 600;
                    border-radius: 6px 6px 0 0;
                }

                .shortcode-example {
                    background: #e7f3ff;
                    border-left: 4px solid #007bff;
                    padding: 15px;
                    margin: 15px 0;
                    border-radius: 0 6px 6px 0;
                }

                .shortcode-example h4 {
                    margin: 0 0 10px 0;
                    color: #007bff;
                    font-size: 16px;
                    font-weight: 600;
                }

                .shortcode-example .code {
                    background: white;
                    padding: 10px;
                    border-radius: 4px;
                    font-family: monospace;
                    color: #e83e8c;
                    margin: 10px 0;
                    border: 1px solid #b3d7ff;
                }

                .parameter-table {
                    width: 100%;
                    border-collapse: collapse;
                    margin: 15px 0;
                    background: white;
                    border: 1px solid #dee2e6;
                    border-radius: 6px;
                    overflow: hidden;
                }

                .parameter-table th {
                    background: #f8f9fa;
                    color: #495057;
                    font-weight: 600;
                    padding: 12px 15px;
                    text-align: left;
                    border-bottom: 1px solid #dee2e6;
                    font-size: 13px;
                }

                .parameter-table td {
                    padding: 10px 15px;
                    border-bottom: 1px solid #f8f9fa;
                    font-size: 13px;
                    vertical-align: top;
                }

                .parameter-table tr:last-child td {
                    border-bottom: none;
                }

                .parameter-table .param-name {
                    font-family: monospace;
                    background: #f8f9fa;
                    padding: 2px 6px;
                    border-radius: 3px;
                    color: #e83e8c;
                    font-weight: 600;
                }

                .api-endpoint {
                    background: #f8f9fa;
                    border: 1px solid #dee2e6;
                    border-radius: 6px;
                    padding: 15px;
                    margin: 15px 0;
                }

                .api-endpoint .method {
                    display: inline-block;
                    background: #28a745;
                    color: white;
                    padding: 4px 8px;
                    border-radius: 4px;
                    font-size: 11px;
                    font-weight: 600;
                    margin-right: 10px;
                }

                .api-endpoint .url {
                    font-family: monospace;
                    color: #495057;
                    font-weight: 600;
                }

                .api-endpoint .description {
                    margin-top: 8px;
                    color: #6c757d;
                    font-size: 13px;
                }

                .feature-grid {
                    display: grid;
                    grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
                    gap: 20px;
                    margin: 20px 0;
                }

                .feature-card {
                    background: #f8f9fa;
                    padding: 20px;
                    border-radius: 8px;
                    border-left: 4px solid #007bff;
                }

                .feature-card h4 {
                    margin: 0 0 10px 0;
                    color: #2c3e50;
                    font-size: 16px;
                    font-weight: 600;
                }

                .feature-card p {
                    margin: 0;
                    color: #6c757d;
                    font-size: 14px;
                    line-height: 1.5;
                }

                .alert {
                    padding: 15px;
                    border-radius: 6px;
                    margin: 15px 0;
                    border-left: 4px solid;
                }

                .alert-info {
                    background: #e7f3ff;
                    border-left-color: #007bff;
                    color: #004085;
                }

                .alert-warning {
                    background: #fff3cd;
                    border-left-color: #ffc107;
                    color: #664d03;
                }

                .alert-success {
                    background: #d1e7dd;
                    border-left-color: #28a745;
                    color: #0a3622;
                }

                /* Responsive */
                @media (max-width: 768px) {
                    .docs-grid {
                        grid-template-columns: 1fr;
                    }

                    .docs-sidebar {
                        position: static;
                    }

                    .feature-grid {
                        grid-template-columns: 1fr;
                    }
                }
            </style>

            <div class="docs-container">
                <!-- Header -->
                <div class="docs-header">
                    <h1>📚 Dokumentasi Plugin Statistik Desa/Kelurahan</h1>
                    <p class="docs-subtitle">Panduan lengkap penggunaan shortcode, API, dan fitur-fitur plugin</p>
                </div>

                <!-- Content Grid -->
                <div class="docs-grid">
                    <!-- Sidebar Navigation -->
                    <div class="docs-sidebar">
                        <h3 style="margin: 0 0 15px 0; color: #2c3e50; font-size: 16px;">📋 Daftar Isi</h3>
                        <ul class="docs-nav">
                            <li><a href="#overview" class="active">🏠 Overview</a></li>
                            <li><a href="#shortcodes">🔗 Shortcodes</a></li>
                            <li><a href="#api">🌐 REST API</a></li>
                            <li><a href="#categories">🏷️ Kategori & Fields</a></li>
                            <li><a href="#examples">💡 Contoh Penggunaan</a></li>
                            <li><a href="#troubleshooting">🔧 Troubleshooting</a></li>
                        </ul>
                    </div>

                    <!-- Main Content -->
                    <div class="docs-content">
                        <!-- Overview Section -->
                        <div id="overview" class="docs-section">
                            <h2>🏠 Overview Plugin</h2>
                            <p>Plugin Statistik Desa/Kelurahan adalah solusi lengkap untuk mengelola dan menampilkan data
                                statistik desa atau kelurahan. Plugin ini menyediakan berbagai fitur untuk input, pengelolaan,
                                dan visualisasi data statistik.</p>

                            <div class="feature-grid">
                                <div class="feature-card">
                                    <h4>📊 Input Data Fleksibel</h4>
                                    <p>Mendukung berbagai jenis kategori data dengan field yang dapat dikustomisasi sesuai
                                        kebutuhan.</p>
                                </div>
                                <div class="feature-card">
                                    <h4>🏷️ Kategori Custom</h4>
                                    <p>Buat kategori dan field sendiri untuk data statistik yang spesifik sesuai kebutuhan
                                        desa/kelurahan.</p>
                                </div>
                                <div class="feature-card">
                                    <h4>📈 Visualisasi Data</h4>
                                    <p>Tampilkan data dalam berbagai format: card, tabel, dan grafik (bar, pie, line).</p>
                                </div>
                                <div class="feature-card">
                                    <h4>🌐 REST API</h4>
                                    <p>Akses data melalui REST API untuk integrasi dengan aplikasi atau website lain.</p>
                                </div>
                            </div>

                            <div class="alert alert-info">
                                <strong>💡 Tips:</strong> Mulai dengan membuat kategori custom di menu "Kelola Kategori" sebelum
                                menginput data statistik.
                            </div>
                        </div>

                        <!-- Shortcodes Section -->
                        <div id="shortcodes" class="docs-section">
                            <h2>🔗 Shortcodes</h2>
                            <p>Plugin ini menyediakan beberapa shortcode untuk menampilkan data statistik di frontend website
                                Anda.</p>

                            <!-- Display Shortcode -->
                            <div class="shortcode-example">
                                <h4>📊 [statistic_display] - Tampilan Card</h4>
                                <p>Menampilkan data statistik dalam format card yang menarik dan responsif.</p>
                                <div class="code">[statistic_display year="2024" category="agama" show_source="true"]</div>

                                <h5>Parameter:</h5>
                                <table class="parameter-table">
                                    <thead>
                                        <tr>
                                            <th>Parameter</th>
                                            <th>Default</th>
                                            <th>Deskripsi</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr>
                                            <td><span class="param-name">year</span></td>
                                            <td>-</td>
                                            <td>Tahun data yang ingin ditampilkan (opsional)</td>
                                        </tr>
                                        <tr>
                                            <td><span class="param-name">category</span></td>
                                            <td>-</td>
                                            <td>Kategori data yang ingin ditampilkan (opsional)</td>
                                        </tr>
                                        <tr>
                                            <td><span class="param-name">published_only</span></td>
                                            <td>true</td>
                                            <td>Hanya tampilkan data yang dipublikasi (true/false)</td>
                                        </tr>
                                        <tr>
                                            <td><span class="param-name">show_source</span></td>
                                            <td>true</td>
                                            <td>Tampilkan sumber data (true/false)</td>
                                        </tr>
                                        <tr>
                                            <td><span class="param-name">show_year</span></td>
                                            <td>true</td>
                                            <td>Tampilkan tahun di judul (true/false)</td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>

                            <!-- Table Shortcode -->
                            <div class="shortcode-example">
                                <h4>📋 [statistic_table] - Tampilan Tabel</h4>
                                <p>Menampilkan data statistik dalam format tabel yang rapi dan mudah dibaca.</p>
                                <div class="code">[statistic_table year="2024" category="jenis_kelamin" limit="10"]</div>

                                <h5>Parameter:</h5>
                                <table class="parameter-table">
                                    <thead>
                                        <tr>
                                            <th>Parameter</th>
                                            <th>Default</th>
                                            <th>Deskripsi</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr>
                                            <td><span class="param-name">year</span></td>
                                            <td>-</td>
                                            <td>Tahun data yang ingin ditampilkan</td>
                                        </tr>
                                        <tr>
                                            <td><span class="param-name">category</span></td>
                                            <td>-</td>
                                            <td>Kategori data yang ingin ditampilkan</td>
                                        </tr>
                                        <tr>
                                            <td><span class="param-name">published_only</span></td>
                                            <td>true</td>
                                            <td>Hanya tampilkan data yang dipublikasi</td>
                                        </tr>
                                        <tr>
                                            <td><span class="param-name">show_source</span></td>
                                            <td>true</td>
                                            <td>Tampilkan sumber data</td>
                                        </tr>
                                        <tr>
                                            <td><span class="param-name">limit</span></td>
                                            <td>10</td>
                                            <td>Batasi jumlah data yang ditampilkan</td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>

                            <!-- Chart Shortcode -->
                            <div class="shortcode-example">
                                <h4>📈 [statistic_chart] - Tampilan Grafik</h4>
                                <p>Menampilkan data statistik dalam format grafik interaktif menggunakan Chart.js.</p>
                                <div class="code">[statistic_chart year="2024" category="agama" type="pie" height="400"]</div>

                                <h5>Parameter:</h5>
                                <table class="parameter-table">
                                    <thead>
                                        <tr>
                                            <th>Parameter</th>
                                            <th>Default</th>
                                            <th>Deskripsi</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr>
                                            <td><span class="param-name">year</span></td>
                                            <td>Tahun saat ini</td>
                                            <td>Tahun data yang ingin ditampilkan (wajib)</td>
                                        </tr>
                                        <tr>
                                            <td><span class="param-name">category</span></td>
                                            <td>-</td>
                                            <td>Kategori data yang ingin ditampilkan (wajib)</td>
                                        </tr>
                                        <tr>
                                            <td><span class="param-name">type</span></td>
                                            <td>bar</td>
                                            <td>Jenis grafik: bar, pie, line, horizontalBar</td>
                                        </tr>
                                        <tr>
                                            <td><span class="param-name">height</span></td>
                                            <td>400</td>
                                            <td>Tinggi grafik dalam pixel</td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>

                            <!-- Form Shortcode -->
                            <div class="shortcode-example">
                                <h4>📝 [statistic_form] - Form Input (Admin Only)</h4>
                                <p>Menampilkan form input data statistik di frontend (hanya untuk admin).</p>
                                <div class="code">[statistic_form]</div>

                                <div class="alert alert-warning">
                                    <strong>⚠️ Perhatian:</strong> Shortcode ini hanya akan menampilkan form untuk user yang
                                    memiliki capability 'manage_options' (biasanya admin).
                                </div>
                            </div>
                        </div>

                        <!-- API Section -->
                        <div id="api" class="docs-section">
                            <h2>🌐 REST API</h2>
                            <p>Plugin ini menyediakan REST API endpoints untuk mengakses data statistik secara programatis.</p>

                            <h3>Base URL</h3>
                            <div class="code-block">
                                <div class="code-title">Base URL</div>
                                <code><?php echo home_url('/wp-json/statistic/v1/'); ?></code>
                            </div>

                            <h3>Available Endpoints</h3>

                            <div class="api-endpoint">
                                <span class="method">GET</span>
                                <span class="url">/wp-json/statistic/v1/data</span>
                                <div class="description">Mengambil semua data statistik dengan opsi filter</div>
                            </div>

                            <div class="api-endpoint">
                                <span class="method">GET</span>
                                <span class="url">/wp-json/statistic/v1/data/{year}</span>
                                <div class="description">Mengambil data statistik berdasarkan tahun tertentu</div>
                            </div>

                            <div class="api-endpoint">
                                <span class="method">GET</span>
                                <span class="url">/wp-json/statistic/v1/data/{year}/{category}</span>
                                <div class="description">Mengambil data statistik spesifik berdasarkan tahun dan kategori</div>
                            </div>

                            <h3>Query Parameters</h3>
                            <table class="parameter-table">
                                <thead>
                                    <tr>
                                        <th>Parameter</th>
                                        <th>Default</th>
                                        <th>Deskripsi</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr>
                                        <td><span class="param-name">published</span></td>
                                        <td>true</td>
                                        <td>Filter data yang dipublikasi (true/false)</td>
                                    </tr>
                                    <tr>
                                        <td><span class="param-name">year</span></td>
                                        <td>-</td>
                                        <td>Filter berdasarkan tahun</td>
                                    </tr>
                                    <tr>
                                        <td><span class="param-name">category</span></td>
                                        <td>-</td>
                                        <td>Filter berdasarkan kategori</td>
                                    </tr>
                                </tbody>
                            </table>

                            <h3>Contoh Response</h3>
                            <div class="code-block">
                                <div class="code-title">JSON Response</div>
                                <code>[
                  {
                    "id": 1,
                    "year": 2024,
                    "category": "agama",
                    "category_name": "Agama",
                    "sumber": "Data BPS",
                    "data": {
                      "islam": 1500,
                      "kristen": 200,
                      "katolik": 100,
                      "hindu": 50,
                      "buddha": 30,
                      "konghucu": 10
                    },
                    "is_published": true,
                    "created_at": "2024-01-15 10:30:00",
                    "updated_at": "2024-01-15 10:30:00"
                  }
                ]</code>
                            </div>

                            <h3>Contoh Penggunaan dengan JavaScript</h3>
                            <div class="code-block">
                                <div class="code-title">JavaScript Fetch</div>
                                <code>// Mengambil semua data
                fetch('<?php echo home_url('/wp-json/statistic/v1/data'); ?>')
                  .then(response => response.json())
                  .then(data => console.log(data));

                // Mengambil data tahun 2024
                fetch('<?php echo home_url('/wp-json/statistic/v1/data/2024'); ?>')
                  .then(response => response.json())
                  .then(data => console.log(data));

                // Mengambil data spesifik
                fetch('<?php echo home_url('/wp-json/statistic/v1/data/2024/agama'); ?>')
                  .then(response => response.json())
                  .then(data => console.log(data));</code>
                            </div>
                        </div>

                        <!-- Categories Section -->
                        <div id="categories" class="docs-section">
                            <h2>🏷️ Kategori & Fields</h2>
                            <p>Plugin ini mendukung dua jenis kategori data statistik:</p>

                            <h3>1. Kategori Regular</h3>
                            <p>Kategori dengan field-field tetap yang sudah ditentukan. Cocok untuk data yang strukturnya
                                konsisten.</p>

                            <div class="alert alert-info">
                                <strong>Contoh:</strong> Kategori "Agama" dengan field: Islam, Kristen, Katolik, Hindu, Buddha,
                                Konghucu
                            </div>

                            <h3>2. Kategori Dynamic RW</h3>
                            <p>Kategori dengan field RW yang dapat ditambah/dikurangi secara dinamis. Cocok untuk data yang
                                berbasis wilayah RW.</p>

                            <div class="alert alert-info">
                                <strong>Contoh:</strong> Kategori "Penerima Bantuan per RW" dengan field: RW 1, RW 2, RW 3, dst.
                            </div>

                            <h3>Mengelola Kategori Custom</h3>
                            <ol>
                                <li>Buka menu <strong>"Statistik Desa" > "Kelola Kategori"</strong></li>
                                <li>Klik tombol <strong>"Tambah Kategori Baru"</strong></li>
                                <li>Isi form dengan data kategori:
                                    <ul>
                                        <li><strong>Kode Kategori:</strong> Kode unik (huruf kecil, angka, underscore)</li>
                                        <li><strong>Nama Kategori:</strong> Nama yang akan ditampilkan</li>
                                        <li><strong>Tipe Kategori:</strong> Regular atau Dynamic RW</li>
                                        <li><strong>Deskripsi:</strong> Penjelasan singkat (opsional)</li>
                                    </ul>
                                </li>
                                <li>Untuk kategori Regular, tambahkan field-field yang diperlukan</li>
                                <li>Aktifkan kategori agar bisa digunakan</li>
                            </ol>

                            <div class="alert alert-warning">
                                <strong>⚠️ Perhatian:</strong> Setelah kategori digunakan untuk input data, sebaiknya tidak
                                mengubah kode kategori atau menghapus field yang sudah ada.
                            </div>
                        </div>

                        <!-- Examples Section -->
                        <div id="examples" class="docs-section">
                            <h2>💡 Contoh Penggunaan</h2>

                            <h3>Skenario 1: Menampilkan Data Agama</h3>
                            <div class="code-block">
                                <div class="code-title">Shortcode</div>
                                <code>[statistic_display year="2024" category="agama" show_source="true"]</code>
                            </div>
                            <p>Akan menampilkan data agama tahun 2024 dalam format card dengan sumber data.</p>

                            <h3>Skenario 2: Tabel Data Pendidikan</h3>
                            <div class="code-block">
                                <div class="code-title">Shortcode</div>
                                <code>[statistic_table category="pendidikan_dalam_kk" limit="5"]</code>
                            </div>
                            <p>Akan menampilkan 5 data terbaru kategori pendidikan dalam format tabel.</p>

                            <h3>Skenario 3: Grafik Pie Jenis Kelamin</h3>
                            <div class="code-block">
                                <div class="code-title">Shortcode</div>
                                <code>[statistic_chart year="2024" category="jenis_kelamin" type="pie" height="300"]</code>
                            </div>
                            <p>Akan menampilkan data jenis kelamin tahun 2024 dalam grafik pie dengan tinggi 300px.</p>

                            <h3>Skenario 4: Dashboard Statistik Lengkap</h3>
                            <div class="code-block">
                                <div class="code-title">Kombinasi Shortcode</div>
                                <code>&lt;h2&gt;Statistik Desa Tahun 2024&lt;/h2&gt;

                &lt;h3&gt;Data Demografi&lt;/h3&gt;
                [statistic_display year="2024" category="jenis_kelamin"]

                &lt;h3&gt;Data Agama&lt;/h3&gt;
                [statistic_chart year="2024" category="agama" type="pie"]

                &lt;h3&gt;Semua Data Statistik&lt;/h3&gt;
                [statistic_table year="2024" limit="10"]</code>
                            </div>

                            <h3>Skenario 5: Integrasi dengan JavaScript</h3>
                            <div class="code-block">
                                <div class="code-title">HTML + JavaScript</div>
                                <code>&lt;div id="custom-stats"&gt;&lt;/div&gt;

                &lt;script&gt;
                async function loadStats() {
                    try {
                        const response = await fetch('/wp-json/statistic/v1/data/2024');
                        const data = await response.json();
        
                        let html = '&lt;h3&gt;Data Statistik 2024&lt;/h3&gt;&lt;ul&gt;';
                        data.forEach(item => {
                            html += `&lt;li&gt;${item.category_name}: ${Object.keys(item.data).length} data&lt;/li&gt;`;
                        });
                        html += '&lt;/ul&gt;';
        
                        document.getElementById('custom-stats').innerHTML = html;
                    } catch (error) {
                        console.error('Error loading stats:', error);
                    }
                }

                loadStats();
                &lt;/script&gt;</code>
                            </div>
                        </div>

                        <!-- Troubleshooting Section -->
                        <div id="troubleshooting" class="docs-section">
                            <h2>🔧 Troubleshooting</h2>

                            <h3>❌ Masalah Umum dan Solusi</h3>

                            <div class="alert alert-warning">
                                <h4>Problem: "Table doesn't exist" Error</h4>
                                <p><strong>Solusi:</strong></p>
                                <ol>
                                    <li>Deaktivasi plugin, lalu aktifkan kembali</li>
                                    <li>Atau gunakan tombol "Buat Tabel Database" di halaman admin</li>
                                    <li>Pastikan WordPress memiliki permission untuk membuat tabel database</li>
                                </ol>
                            </div>

                            <div class="alert alert-warning">
                                <h4>Problem: Shortcode tidak menampilkan data</h4>
                                <p><strong>Solusi:</strong></p>
                                <ol>
                                    <li>Pastikan data sudah diinput dan status "Published"</li>
                                    <li>Cek parameter shortcode (year, category) sudah benar</li>
                                    <li>Pastikan kategori yang dipanggil sudah aktif</li>
                                </ol>
                            </div>

                            <div class="alert alert-warning">
                                <h4>Problem: Grafik tidak muncul</h4>
                                <p><strong>Solusi:</strong></p>
                                <ol>
                                    <li>Pastikan koneksi internet stabil (Chart.js dimuat dari CDN)</li>
                                    <li>Cek console browser untuk error JavaScript</li>
                                    <li>Pastikan parameter year dan category sudah benar</li>
                                </ol>
                            </div>

                            <div class="alert alert-warning">
                                <h4>Problem: API tidak bisa diakses</h4>
                                <p><strong>Solusi:</strong></p>
                                <ol>
                                    <li>Pastikan WordPress REST API aktif</li>
                                    <li>Cek permalink settings di WordPress</li>
                                    <li>Test dengan endpoint: <code>/wp-json/wp/v2/posts</code></li>
                                </ol>
                            </div>

                            <h3>🔍 Debug Mode</h3>
                            <p>Untuk debugging, tambahkan kode berikut di wp-config.php:</p>
                            <div class="code-block">
                                <div class="code-title">wp-config.php</div>
                                <code>define('WP_DEBUG', true);
                define('WP_DEBUG_LOG', true);
                define('WP_DEBUG_DISPLAY', false);</code>
                            </div>

                            <h3>📞 Dukungan</h3>
                            <div class="alert alert-success">
                                <p>Jika masih mengalami masalah, silakan:</p>
                                <ul>
                                    <li>Cek log error di <code>/wp-content/debug.log</code></li>
                                    <li>Screenshot error yang muncul</li>
                                    <li>Catat langkah-langkah yang menyebabkan error</li>
                                    <li>Hubungi developer dengan informasi tersebut</li>
                                </ul>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <script>
            // Simple navigation highlighting
            document.addEventListener('DOMContentLoaded', function () {
                const navLinks = document.querySelectorAll('.docs-nav a');
                const sections = document.querySelectorAll('.docs-section');

                navLinks.forEach(link => {
                    link.addEventListener('click', function (e) {
                        e.preventDefault();
                        const targetId = this.getAttribute('href').substring(1);
                        const targetSection = document.getElementById(targetId);

                        if (targetSection) {
                            targetSection.scrollIntoView({ behavior: 'smooth' });

                            // Update active nav
                            navLinks.forEach(l => l.classList.remove('active'));
                            this.classList.add('active');
                        }
                    });
                });

                // Highlight current section on scroll
                window.addEventListener('scroll', function () {
                    let current = '';
                    sections.forEach(section => {
                        const sectionTop = section.offsetTop;
                        const sectionHeight = section.clientHeight;
                        if (pageYOffset >= sectionTop - 200) {
                            current = section.getAttribute('id');
                        }
                    });

                    navLinks.forEach(link => {
                        link.classList.remove('active');
                        if (link.getAttribute('href') === '#' + current) {
                            link.classList.add('active');
                        }
                    });
                });
            });
        </script>
        <?php
    }

    /**
     * Enqueue frontend scripts and styles
     * Memuat CSS dan JS untuk frontend
     */
    public function enqueue_frontend_scripts()
    {
        // Hanya muat jika ada shortcode di halaman
        global $post;
        if (
            is_a($post, 'WP_Post') && (
                has_shortcode($post->post_content, 'statistic_form') ||
                has_shortcode($post->post_content, 'statistic_display') ||
                has_shortcode($post->post_content, 'statistic_table') ||
                has_shortcode($post->post_content, 'statistic_chart')
            )
        ) {
            // Bootstrap CSS untuk styling yang lebih baik
            wp_enqueue_style('bootstrap', 'https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css');

            // Chart.js untuk grafik
            wp_enqueue_script('chartjs', 'https://cdn.jsdelivr.net/npm/chart.js', array(), '3.9.1', true);

            // AJAX untuk form submission
            wp_localize_script('jquery', 'ajax_object', array(
                'ajax_url' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('statistic_nonce_action')
            ));
        }
    }

    /**
     * Enqueue admin scripts and styles
     * Memuat CSS dan JS untuk halaman admin
     */
    public function enqueue_admin_scripts($hook)
    {
        // Hanya muat di halaman plugin
        if (strpos($hook, 'statistic') !== false) {
            wp_enqueue_script('jquery');

            // Localize script untuk AJAX
            wp_localize_script('jquery', 'ajax_object', array(
                'ajax_url' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('statistic_nonce_action')
            ));
        }
    }
}

// Initialize the plugin
new StatisticPlugin();
?>