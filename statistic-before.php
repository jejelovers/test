<?php
/*
Plugin Name: Statistik Desa/Kelurahan (Improved)
Description: Plugin untuk menyimpan dan menampilkan data statistik desa/kelurahan dengan form dinamis - Versi dengan tombol shortcode.
Version: 1.5
Author: Hygsan Iskandar

CARA PENGGUNAAN:
1. Aktivasi Plugin: Aktifkan plugin melalui halaman Plugins WordPress
2. Input Data: Gunakan menu "Statistik Desa" > "Input Statistik" untuk menambah data
3. Kelola Data: Lihat dan edit data melalui "Daftar Statistik"
4. Tampilkan di Frontend: Gunakan shortcode atau API (lihat dokumentasi)
5. Dokumentasi: Buka "Dokumentasi" untuk panduan lengkap shortcode dan API

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

    public function __construct()
    {
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'statistic';

        // Register hooks
        register_activation_hook(__FILE__, array($this, 'install'));
        add_action('plugins_loaded', array($this, 'init'));
    }

    /**
     * Create the custom database table
     * Membuat tabel database untuk menyimpan data statistik
     */
    public function install()
    {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$this->table_name} (
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

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }

    /**
     * Initialize the plugin
     * Inisialisasi plugin - mendaftarkan shortcode, menu admin, dan API
     */
    public function init()
    {
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

        // REST API endpoints
        add_action('rest_api_init', array($this, 'register_rest_routes'));
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
        echo "
        <style>
        .statistic-display {
            display: flex;
            flex-direction: column;
            gap: 30px;
            margin-top: 20px;
        }
        .stat-card {
            border: 1px solid #ddd;
            border-radius: 10px;
            padding: 20px;
            background: #fff;
            box-shadow: 0 2px 5px rgba(0,0,0,0.05);
        }
        .stat-card h4 {
            margin: 0 0 15px;
        }
        .card-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
            gap: 15px;
        }
        .card-item {
            background: #f8f8f8;
            padding: 10px;
            border-radius: 8px;
            text-align: center;
        }
        .card-item-title {
            font-weight: bold;
        }
        .card-item-value {
            font-size: 20px;
            margin-top: 5px;
        }
        .stat-source {
            margin-top: 10px;
            font-size: 0.9em;
            color: #777;
        }
        </style>
        ";

        foreach ($results as $row) {
            $category_name = $this->get_categories()[$row->category] ?? $row->category;
            $data = json_decode($row->data, true);

            echo '<div class="stat-card">';
            echo '<h4>' . esc_html($category_name);
            if ($atts['show_year'] === 'true') {
                echo ' - ' . esc_html($row->year);
            }
            echo '</h4>';
            echo '<div class="card-body">';

            // Cek apakah kategori menggunakan RW dinamis
            if ($this->is_dynamic_rw_category($row->category)) {
                // Display RW data
                echo '<div class="card-grid">';
                foreach ($data as $key => $value) {
                    if (strpos($key, 'rw_') === 0) {
                        $rw_number = str_replace('rw_', '', $key);
                        echo '<div class="card-item">';
                        echo '<div class="card-item-title">RW ' . esc_html($rw_number) . '</div>';
                        echo '<div class="card-item-value">' . esc_html($value) . '</div>';
                        echo '</div>';
                    }
                }
                echo '</div>';
            } else {
                // Display regular category data
                echo '<div class="card-grid">';
                foreach ($data as $key => $value) {
                    $field_label = $this->get_category_fields()[$row->category][$key] ?? $key;
                    echo '<div class="card-item">';
                    echo '<div class="card-item-title">' . esc_html($field_label) . '</div>';
                    echo '<div class="card-item-value">' . esc_html($value) . '</div>';
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
                    showCategoryFields(this.value);
                });

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
     * Generate dynamic RW fields - disesuaikan dengan gambar
     * Generate field RW yang bisa ditambah/dikurangi secara dinamis
     */
    private function generate_category_field($category, $existing_data = array())
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
                    $html .= '<div class="field-group">';
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
            $html .= '<div class="field-group">';
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
     * Check if category uses dynamic RW fields
     * Cek apakah kategori menggunakan field RW dinamis
     */
    private function is_dynamic_rw_category($category)
    {
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
     * Get categories
     * Mendapatkan daftar kategori yang tersedia (diperluas dengan kategori dari teman)
     */
    private function get_categories()
    {
        return array(
            // Kategori asli Anda
            'jenis_kelamin' => 'Jenis Kelamin',
            'agama' => 'Agama',
            'golongan_darah' => 'Golongan Darah',
            'penerima_pemberian_makanan_tambahan' => 'Penerima Pemberian Makanan Tambahan',
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
     * Get category fields
     * Mendapatkan field untuk setiap kategori (diperluas dengan field dari teman)
     */
    private function get_category_fields()
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
     * Admin menu
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
                            <a href="<?php echo home_url('/wp-json/statistic/v1/data'); ?>" class="action-btn" target="_blank">
                                <span>🔌</span>
                                <div>
                                    <div>Test API</div>
                                    <small>Cek endpoint REST</small>
                                </div>
                            </a>
                        </div>

                        <?php if ($total_records == 0): ?>
                            <!-- Getting Started Guide -->
                            <div class="getting-started">
                                <h3>🎯 Panduan Memulai</h3>
                                <p>Selamat datang! Ikuti langkah-langkah berikut untuk memulai:</p>
                                <ul>
                                    <li><strong>Langkah 1:</strong> Klik "Input Data Baru" untuk menambah statistik pertama</li>
                                    <li><strong>Langkah 2:</strong> Pilih tahun dan kategori data yang ingin diinput</li>
                                    <li><strong>Langkah 3:</strong> Isi data statistik sesuai kategori yang dipilih</li>
                                    <li><strong>Langkah 4:</strong> Gunakan shortcode untuk menampilkan data di frontend</li>
                                    <li><strong>Langkah 5:</strong> Baca dokumentasi untuk fitur lanjutan</li>
                                </ul>
                            </div>
                        <?php endif; ?>

                        <!-- Shortcode Examples -->
                        <div style="background: #f8f9fa; padding: 20px; border-radius: 8px; margin-top: 20px;">
                            <h3>💡 Contoh Shortcode Populer</h3>
                            <div style="display: grid; gap: 15px;">
                                <div style="background: white; padding: 15px; border-radius: 6px; border-left: 4px solid #007bff;">
                                    <strong>Tampilan Card:</strong><br>
                                    <code style="color: #e83e8c;">[statistic_display year="<?php echo date('Y'); ?>" category="agama"]</code>
                                </div>
                                <div style="background: white; padding: 15px; border-radius: 6px; border-left: 4px solid #28a745;">
                                    <strong>Tampilan Tabel:</strong><br>
                                    <code style="color: #e83e8c;">[statistic_table year="<?php echo date('Y'); ?>" limit="5"]</code>
                                </div>
                                <div style="background: white; padding: 15px; border-radius: 6px; border-left: 4px solid #ffc107;">
                                    <strong>Grafik Pie:</strong><br>
                                    <code style="color: #e83e8c;">[statistic_chart year="<?php echo date('Y'); ?>" category="jenis_kelamin" type="pie"]</code>
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
                                            <span class="activity-status <?php echo $record->is_published ? 'status-published' : 'status-draft'; ?>">
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
                                    <span class="chart-value">v1.5</span>
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
                }

                .data-toggle {
                    color: #2271b1;
                    text-decoration: none;
                    font-size: 11px;
                    font-weight: 600;
                }

                .data-toggle:hover {
                    text-decoration: underline;
                }

                .data-details {
                    display: none;
                    margin-top: 8px;
                    padding: 8px;
                    background: #f6f7f7;
                    border-radius: 4px;
                    font-family: monospace;
                    font-size: 11px;
                    max-height: 150px;
                    overflow-y: auto;
                }

                .status-badge {
                    display: inline-flex;
                    align-items: center;
                    gap: 5px;
                    padding: 4px 8px;
                    border-radius: 12px;
                    font-size: 11px;
                    font-weight: 600;
                }

                .status-published {
                    background: #d1e7dd;
                    color: #0a3622;
                }

                .status-draft {
                    background: #f8d7da;
                    color: #58151c;
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
                    padding: 6px 10px;
                    border: 1px solid #ddd;
                    border-radius: 4px;
                    text-decoration: none;
                    font-size: 11px;
                    font-weight: 600;
                    margin-right: 5px;
                    margin-bottom: 3px;
                    transition: all 0.2s ease;
                }

                .action-btn:hover {
                    text-decoration: none;
                    transform: translateY(-1px);
                    box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
                }

                .btn-edit {
                    background: #fff;
                    color: #2271b1;
                    border-color: #2271b1;
                }

                .btn-edit:hover {
                    background: #2271b1;
                    color: #fff;
                }

                .btn-shortcode {
                    background: #fff;
                    color: #00a32a;
                    border-color: #00a32a;
                }

                .btn-shortcode:hover {
                    background: #00a32a;
                    color: #fff;
                }

                .btn-delete {
                    background: #fff;
                    color: #d63638;
                    border-color: #d63638;
                }

                .btn-delete:hover {
                    background: #d63638;
                    color: #fff;
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

                /* Responsive Design */
                @media (max-width: 1200px) {
                    .data-preview {
                        max-width: 200px;
                    }
                }

                @media (max-width: 768px) {
                    .statistics-filters {
                        flex-direction: column;
                        align-items: stretch;
                    }

                    .filter-actions {
                        margin-left: 0;
                        justify-content: center;
                    }

                    .stats-summary {
                        flex-direction: column;
                        gap: 10px;
                        align-items: flex-start;
                    }

                    .summary-left {
                        flex-direction: column;
                        gap: 5px;
                    }

                    .statistics-table th,
                    .statistics-table td {
                        padding: 8px 10px;
                        font-size: 12px;
                    }

                    .action-btn {
                        padding: 4px 6px;
                        font-size: 10px;
                    }
                }
            </style>

            <div class="statistics-admin-container">
                <!-- Header -->
                <div class="statistics-header">
                    <h1>📊 Daftar Statistik Desa/Kelurahan</h1>
                    <p class="description">Kelola dan pantau semua data statistik yang telah diinput. Gunakan filter untuk
                        mencari data spesifik.</p>
                </div>

                <!-- Filters -->
                <div class="statistics-filters">
                    <form method="get" style="display: contents;">
                        <input type="hidden" name="page" value="statistic-list">

                        <div class="filter-group">
                            <label>Pencarian</label>
                            <input type="text" name="s" value="<?php echo esc_attr($search); ?>"
                                placeholder="Cari kategori atau sumber...">
                        </div>

                        <div class="filter-group">
                            <label>Tahun</label>
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
                            <label>Kategori</label>
                            <select name="filter_category">
                                <option value="">Semua Kategori</option>
                                <?php foreach ($categories as $key => $name): ?>
                                    <option value="<?php echo esc_attr($key); ?>" <?php selected($filter_category, $key); ?>>
                                        <?php echo esc_html($name); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="filter-group">
                            <label>Status</label>
                            <select name="filter_status">
                                <option value="">Semua Status</option>
                                <option value="1" <?php selected($filter_status, '1'); ?>>Dipublikasi</option>
                                <option value="0" <?php selected($filter_status, '0'); ?>>Draft</option>
                            </select>
                        </div>

                        <div class="filter-actions">
                            <button type="submit" class="button">🔍 Filter</button>
                            <a href="<?php echo admin_url('admin.php?page=statistic-list'); ?>" class="button">↻ Reset</a>
                        </div>
                    </form>
                </div>

                <!-- Summary Stats -->
                <?php if ($results): ?>
                    <div class="stats-summary">
                        <div class="summary-left">
                            <div class="summary-item">
                                <span>📊 Total Data:</span>
                                <span class="count"><?php echo count($results); ?></span>
                            </div>
                            <div class="summary-item">
                                <span>✅ Dipublikasi:</span>
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
                            <a href="<?php echo admin_url('admin.php?page=statistic-create'); ?>" class="button button-primary">
                                ➕ Tambah Data Baru
                            </a>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- Table Content -->
                <div class="statistics-content">
                    <?php if ($results): ?>
                        <div class="statistics-table-container">
                            <table class="statistics-table">
                                <thead>
                                    <tr>
                                        <th style="width: 80px;">Tahun</th>
                                        <th style="width: 200px;">Kategori</th>
                                        <th style="width: 150px;">Sumber Data</th>
                                        <th style="width: 300px;">Data Preview</th>
                                        <th style="width: 100px;">Status</th>
                                        <th style="width: 120px;">Terakhir Update</th>
                                        <th style="width: 180px;">Aksi</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($results as $row): ?>
                                        <?php
                                        $category_name = isset($categories[$row->category]) ? $categories[$row->category] : $row->category;
                                        $data = json_decode($row->data, true) ?: array();
                                        $data_count = count($data);
                                        ?>
                                        <tr>
                                            <!-- Year -->
                                            <td>
                                                <span class="year-badge"><?php echo esc_html($row->year); ?></span>
                                            </td>

                                            <!-- Category -->
                                            <td>
                                                <div class="category-name"><?php echo esc_html($category_name); ?></div>
                                                <div class="category-code"><?php echo esc_html($row->category); ?></div>
                                            </td>

                                            <!-- Source -->
                                            <td>
                                                <?php if (!empty($row->sumber)): ?>
                                                    <span title="<?php echo esc_attr($row->sumber); ?>">
                                                        <?php echo esc_html(wp_trim_words($row->sumber, 3, '...')); ?>
                                                    </span>
                                                <?php else: ?>
                                                    <span style="color: #646970; font-style: italic;">Tidak ada sumber</span>
                                                <?php endif; ?>
                                            </td>

                                            <!-- Data Preview -->
                                            <td class="data-preview">
                                                <?php if (!empty($data)): ?>
                                                    <div class="data-summary">
                                                        <?php
                                                        $shown = 0;
                                                        foreach ($data as $key => $value):
                                                            if ($shown >= 3)
                                                                break;
                                                            $label = $key;
                                                            if ($this->is_dynamic_rw_category($row->category) && strpos($key, 'rw_') === 0) {
                                                                $label = 'RW ' . str_replace('rw_', '', $key);
                                                            } elseif (isset($this->get_category_fields()[$row->category][$key])) {
                                                                $label = $this->get_category_fields()[$row->category][$key];
                                                            }
                                                            ?>
                                                            <span class="data-item" title="<?php echo esc_attr($label . ': ' . $value); ?>">
                                                                <?php echo esc_html(wp_trim_words($label, 2, '') . ': ' . $value); ?>
                                                            </span>
                                                            <?php
                                                            $shown++;
                                                        endforeach;
                                                        ?>
                                                        <?php if ($data_count > 3): ?>
                                                            <span class="data-item" style="background: #f0f0f1; color: #646970;">
                                                                +<?php echo ($data_count - 3); ?> lainnya
                                                            </span>
                                                        <?php endif; ?>
                                                    </div>
                                                    <a href="#" class="data-toggle" onclick="toggleDataDetails(this); return false;">
                                                        👁️ Lihat Detail
                                                    </a>
                                                    <div class="data-details">
                                                        <pre><?php echo esc_html(json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)); ?></pre>
                                                    </div>
                                                <?php else: ?>
                                                    <span style="color: #d63638; font-style: italic;">Data kosong</span>
                                                <?php endif; ?>
                                            </td>

                                            <!-- Status -->
                                            <td>
                                                <?php if ($row->is_published): ?>
                                                    <span class="status-badge status-published">
                                                        <span class="status-indicator"></span>
                                                        Aktif
                                                    </span>
                                                <?php else: ?>
                                                    <span class="status-badge status-draft">
                                                        <span class="status-indicator"></span>
                                                        Draft
                                                    </span>
                                                <?php endif; ?>
                                            </td>

                                            <!-- Last Updated -->
                                            <td>
                                                <div style="font-size: 11px; color: #646970;">
                                                    <?php echo esc_html(date('d/m/Y', strtotime($row->updated_at))); ?>
                                                    <br>
                                                    <?php echo esc_html(date('H:i', strtotime($row->updated_at))); ?>
                                                </div>
                                            </td>

                                            <!-- Actions -->
                                            <td class="actions-cell">
                                                <a href="<?php echo admin_url('admin.php?page=statistic-edit&year=' . $row->year . '&category=' . $row->category); ?>"
                                                    class="action-btn btn-edit" title="Edit Data">
                                                    ✏️ Edit
                                                </a>
                                                <button class="action-btn btn-shortcode show-shortcode-btn"
                                                    data-year="<?php echo esc_attr($row->year); ?>"
                                                    data-category="<?php echo esc_attr($row->category); ?>"
                                                    data-category-name="<?php echo esc_attr($category_name); ?>"
                                                    title="Lihat Shortcode & API">
                                                    🔗 Kode
                                                </button>
                                                <button class="action-btn btn-delete delete-statistic"
                                                    data-year="<?php echo esc_attr($row->year); ?>"
                                                    data-category="<?php echo esc_attr($row->category); ?>" title="Hapus Data">
                                                    🗑️ Hapus
                                                </button>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <!-- Empty State -->
                        <div class="empty-state">
                            <div class="empty-state-icon">📊</div>
                            <h3>Belum Ada Data Statistik</h3>
                            <p>
                                <?php if (!empty($search) || !empty($filter_year) || !empty($filter_category) || $filter_status !== ''): ?>
                                    Tidak ada data yang sesuai dengan filter yang dipilih.
                                    <br><a href="<?php echo admin_url('admin.php?page=statistic-list'); ?>">Reset filter</a> untuk
                                    melihat semua data.
                                <?php else: ?>
                                    Mulai dengan menambahkan data statistik pertama Anda.
                                <?php endif; ?>
                            </p>
                            <a href="<?php echo admin_url('admin.php?page=statistic-create'); ?>"
                                class="button button-primary button-large">
                                ➕ Tambah Data Statistik
                            </a>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Modal untuk Shortcode & API - UPDATED WITH ALL CHART TYPES -->
            <div id="shortcode-modal"
                style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 9999;">
                <div
                    style="position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); background: white; padding: 30px; border-radius: 8px; max-width: 900px; width: 90%; max-height: 80%; overflow-y: auto;">
                    <div
                        style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; border-bottom: 1px solid #ddd; padding-bottom: 15px;">
                        <h2 id="modal-title" style="margin: 0; color: #333;">Shortcode & API untuk Data</h2>
                        <button onclick="closeModal()"
                            style="background: #666; color: white; border: none; padding: 8px 12px; border-radius: 4px; cursor: pointer;">✕
                            Tutup</button>
                    </div>
                    <div id="modal-content"></div>
                </div>
            </div>
        </div>

        <script>
            // Toggle data details
            function toggleDataDetails(element) {
                const details = element.nextElementSibling;
                if (details.style.display === 'none' || details.style.display === '') {
                    details.style.display = 'block';
                    element.textContent = '👁️ Sembunyikan Detail';
                } else {
                    details.style.display = 'none';
                    element.textContent = '👁️ Lihat Detail';
                }
            }

            jQuery(document).ready(function ($) {
                // Delete functionality
                $('.delete-statistic').click(function (e) {
                    e.preventDefault();
                    if (!confirm('⚠️ Apakah Anda yakin ingin menghapus data ini?\n\nData yang dihapus tidak dapat dikembalikan.')) {
                        return;
                    }

                    var year = $(this).data('year');
                    var category = $(this).data('category');
                    var row = $(this).closest('tr');
                    var button = $(this);

                    // Disable button and show loading
                    button.prop('disabled', true).html('⏳ Menghapus...');

                    $.ajax({
                        url: ajaxurl,
                        method: 'POST',
                        data: {
                            action: 'statistic_delete',
                            year: year,
                            category: category,
                            nonce: '<?php echo wp_create_nonce('statistic_delete_nonce'); ?>'
                        },
                        success: function (response) {
                            if (response.success) {
                                row.fadeOut(400, function () {
                                    row.remove();
                                    // Update summary if needed
                                    location.reload();
                                });
                            } else {
                                alert('❌ Gagal menghapus data: ' + response.data);
                                button.prop('disabled', false).html('🗑️ Hapus');
                            }
                        },
                        error: function () {
                            alert('❌ Terjadi kesalahan saat menghapus data');
                            button.prop('disabled', false).html('🗑️ Hapus');
                        }
                    });
                });

                // Shortcode button functionality
                $('.show-shortcode-btn').click(function () {
                    var year = $(this).data('year');
                    var category = $(this).data('category');
                    var categoryName = $(this).data('category-name');
                    showShortcodeModal(year, category, categoryName);
                });
            });

            // UPDATED Function untuk menampilkan modal dengan SEMUA JENIS CHART
            function showShortcodeModal(year, category, categoryName) {
                var baseUrl = '<?php echo home_url(); ?>';
                var apiBase = baseUrl + '/wp-json/statistic/v1/data';
                var modalTitle = 'Shortcode & API untuk ' + categoryName + ' (' + year + ')';

                document.getElementById('modal-title').textContent = modalTitle;

                var content = `
                <div style="background: #f8f9fa; padding: 20px; border-radius: 6px; margin-bottom: 20px;">
                    <h3 style="margin-top: 0; color: #495057;">📊 Shortcodes</h3>
                    
                    <div style="margin-bottom: 15px;">
                        <h4 style="color: #666; margin-bottom: 8px;">1. Tampilan Card:</h4>
                        <div style="background: white; padding: 12px; border-radius: 4px; border-left: 4px solid #007cba;">
                            <code style="color: #d63384; font-weight: 500;">[statistic_display year="${year}" category="${category}"]</code>
                            <button onclick="copyToClipboard('[statistic_display year=&quot;${year}&quot; category=&quot;${category}&quot;]')" style="margin-left: 10px; padding: 4px 8px; background: #007cba; color: white; border: none; border-radius: 3px; cursor: pointer; font-size: 11px;">📋 Copy</button>
                        </div>
                    </div>
                    
                    <div style="margin-bottom: 15px;">
                        <h4 style="color: #666; margin-bottom: 8px;">2. Tampilan Tabel:</h4>
                        <div style="background: white; padding: 12px; border-radius: 4px; border-left: 4px solid #28a745;">
                            <code style="color: #d63384; font-weight: 500;">[statistic_table year="${year}" category="${category}" limit="10"]</code>
                            <button onclick="copyToClipboard('[statistic_table year=&quot;${year}&quot; category=&quot;${category}&quot; limit=&quot;10&quot;]')" style="margin-left: 10px; padding: 4px 8px; background: #28a745; color: white; border: none; border-radius: 3px; cursor: pointer; font-size: 11px;">📋 Copy</button>
                        </div>
                    </div>
                    
                    <div style="margin-bottom: 15px;">
                        <h4 style="color: #666; margin-bottom: 8px;">3. Grafik - Semua Jenis Chart:</h4>
                        
                        <!-- Bar Chart (Vertikal) -->
                        <div style="background: white; padding: 12px; border-radius: 4px; border-left: 4px solid #ffc107; margin-bottom: 8px;">
                            <strong style="color: #856404;">📊 Bar Chart (Vertikal):</strong><br>
                            <code style="color: #d63384; font-weight: 500;">[statistic_chart year="${year}" category="${category}" type="bar" height="400"]</code>
                            <button onclick="copyToClipboard('[statistic_chart year=&quot;${year}&quot; category=&quot;${category}&quot; type=&quot;bar&quot; height=&quot;400&quot;]')" style="margin-left: 10px; padding: 4px 8px; background: #ffc107; color: #212529; border: none; border-radius: 3px; cursor: pointer; font-size: 11px;">📋 Copy</button>
                        </div>
                        
                        <!-- Bar Chart (Horizontal) -->
                        <div style="background: white; padding: 12px; border-radius: 4px; border-left: 4px solid #17a2b8; margin-bottom: 8px;">
                            <strong style="color: #0c5460;">📈 Bar Chart (Horizontal):</strong><br>
                            <code style="color: #d63384; font-weight: 500;">[statistic_chart year="${year}" category="${category}" type="horizontalBar" height="400"]</code>
                            <button onclick="copyToClipboard('[statistic_chart year=&quot;${year}&quot; category=&quot;${category}&quot; type=&quot;horizontalBar&quot; height=&quot;400&quot;]')" style="margin-left: 10px; padding: 4px 8px; background: #17a2b8; color: white; border: none; border-radius: 3px; cursor: pointer; font-size: 11px;">📋 Copy</button>
                        </div>
                        
                        <!-- Pie Chart -->
                        <div style="background: white; padding: 12px; border-radius: 4px; border-left: 4px solid #dc3545; margin-bottom: 8px;">
                            <strong style="color: #721c24;">🥧 Pie Chart:</strong><br>
                            <code style="color: #d63384; font-weight: 500;">[statistic_chart year="${year}" category="${category}" type="pie" height="400"]</code>
                            <button onclick="copyToClipboard('[statistic_chart year=&quot;${year}&quot; category=&quot;${category}&quot; type=&quot;pie&quot; height=&quot;400&quot;]')" style="margin-left: 10px; padding: 4px 8px; background: #dc3545; color: white; border: none; border-radius: 3px; cursor: pointer; font-size: 11px;">📋 Copy</button>
                        </div>
                        
                        <!-- Line Chart -->
                        <div style="background: white; padding: 12px; border-radius: 4px; border-left: 4px solid #6f42c1; margin-bottom: 8px;">
                            <strong style="color: #432874;">📉 Line Chart:</strong><br>
                            <code style="color: #d63384; font-weight: 500;">[statistic_chart year="${year}" category="${category}" type="line" height="400"]</code>
                            <button onclick="copyToClipboard('[statistic_chart year=&quot;${year}&quot; category=&quot;${category}&quot; type=&quot;line&quot; height=&quot;400&quot;]')" style="margin-left: 10px; padding: 4px 8px; background: #6f42c1; color: white; border: none; border-radius: 3px; cursor: pointer; font-size: 11px;">📋 Copy</button>
                        </div>
                        
                        <!-- Doughnut Chart -->
                        <div style="background: white; padding: 12px; border-radius: 4px; border-left: 4px solid #fd7e14; margin-bottom: 8px;">
                            <strong style="color: #8a4a00;">🍩 Doughnut Chart:</strong><br>
                            <code style="color: #d63384; font-weight: 500;">[statistic_chart year="${year}" category="${category}" type="doughnut" height="400"]</code>
                            <button onclick="copyToClipboard('[statistic_chart year=&quot;${year}&quot; category=&quot;${category}&quot; type=&quot;doughnut&quot; height=&quot;400&quot;]')" style="margin-left: 10px; padding: 4px 8px; background: #fd7e14; color: white; border: none; border-radius: 3px; cursor: pointer; font-size: 11px;">📋 Copy</button>
                        </div>
                        
                        <!-- Radar Chart -->
                        <div style="background: white; padding: 12px; border-radius: 4px; border-left: 4px solid #20c997;">
                            <strong style="color: #0a6c47;">🎯 Radar Chart:</strong><br>
                            <code style="color: #d63384; font-weight: 500;">[statistic_chart year="${year}" category="${category}" type="radar" height="400"]</code>
                            <button onclick="copyToClipboard('[statistic_chart year=&quot;${year}&quot; category=&quot;${category}&quot; type=&quot;radar&quot; height=&quot;400&quot;]')" style="margin-left: 10px; padding: 4px 8px; background: #20c997; color: white; border: none; border-radius: 3px; cursor: pointer; font-size: 11px;">📋 Copy</button>
                        </div>
                        
                        <div style="background: #e9ecef; padding: 10px; border-radius: 4px; margin-top: 10px;">
                            <small style="color: #6c757d;">
                                <strong>💡 Tips:</strong> Anda dapat mengubah parameter <code>height</code> untuk menyesuaikan tinggi grafik (contoh: height="300", height="500", dll.)
                            </small>
                        </div>
                    </div>
                </div>
                
                <div style="background: #e9ecef; padding: 20px; border-radius: 6px;">
                    <h3 style="margin-top: 0; color: #495057;">🔌 REST API Endpoints</h3>
                    
                    <div style="margin-bottom: 15px;">
                        <h4 style="color: #666; margin-bottom: 8px;">1. Data Spesifik:</h4>
                        <div style="background: white; padding: 12px; border-radius: 4px; border-left: 4px solid #6f42c1;">
                            <code style="color: #d63384; font-weight: 500;">${apiBase}/${year}/${category}</code>
                            <button onclick="copyToClipboard('${apiBase}/${year}/${category}')" style="margin-left: 10px; padding: 4px 8px; background: #6f42c1; color: white; border: none; border-radius: 3px; cursor: pointer; font-size: 11px;">📋 Copy</button>
                        </div>
                    </div>
                    
                    <div style="margin-bottom: 15px;">
                        <h4 style="color: #666; margin-bottom: 8px;">2. Semua Data Tahun ${year}:</h4>
                        <div style="background: white; padding: 12px; border-radius: 4px; border-left: 4px solid #20c997;">
                            <code style="color: #d63384; font-weight: 500;">${apiBase}/${year}</code>
                            <button onclick="copyToClipboard('${apiBase}/${year}')" style="margin-left: 10px; padding: 4px 8px; background: #20c997; color: white; border: none; border-radius: 3px; cursor: pointer; font-size: 11px;">📋 Copy</button>
                        </div>
                    </div>
                    
                    <div style="margin-bottom: 15px;">
                        <h4 style="color: #666; margin-bottom: 8px;">3. Semua Data (Semua Tahun):</h4>
                        <div style="background: white; padding: 12px; border-radius: 4px; border-left: 4px solid #007bff;">
                            <code style="color: #d63384; font-weight: 500;">${apiBase}</code>
                            <button onclick="copyToClipboard('${apiBase}')" style="margin-left: 10px; padding: 4px 8px; background: #007bff; color: white; border: none; border-radius: 3px; cursor: pointer; font-size: 11px;">📋 Copy</button>
                        </div>
                    </div>
                </div>
            `;

                document.getElementById('modal-content').innerHTML = content;
                document.getElementById('shortcode-modal').style.display = 'block';
            }

            // Function untuk menutup modal
            function closeModal() {
                document.getElementById('shortcode-modal').style.display = 'none';
            }

            // Function untuk copy ke clipboard
            function copyToClipboard(text) {
                navigator.clipboard.writeText(text).then(function () {
                    var button = event.target;
                    var originalText = button.textContent;
                    button.textContent = '✅ Copied!';
                    button.style.opacity = '0.8';
                    setTimeout(() => {
                        button.textContent = originalText;
                        button.style.opacity = '1';
                    }, 1500);
                }).catch(function (err) {
                    console.error('Could not copy text: ', err);
                    alert('Berhasil menyalin ke clipboard');
                });
            }

            // Close modal when clicking outside
            document.getElementById('shortcode-modal').addEventListener('click', function (e) {
                if (e.target === this) {
                    closeModal();
                }
            });
        </script>
        <?php
    }

    /**
     * Admin documentation page - UPDATED WITH TUTORIAL SECTION
     * Halaman dokumentasi dengan styling putih abu-abu dan tutorial input
     */
    public function admin_docs_page()
    {
        ?>
        <div class="wrap">
            <div class="documentation-container">
                <div class="doc-header">
                    <h1>📚 Dokumentasi Plugin Statistik Desa/Kelurahan</h1>
                    <p class="doc-subtitle">Panduan lengkap cara input data, penggunaan shortcode dan API untuk menampilkan
                        statistik</p>
                </div>

                <div class="doc-tabs">
                    <button class="tab-button active" onclick="showTab('tutorial')">📝 Tutorial Input</button>
                    <button class="tab-button" onclick="showTab('shortcodes')">📊 Shortcodes</button>
                    <button class="tab-button" onclick="showTab('api')">🔌 REST API</button>
                    <button class="tab-button" onclick="showTab('examples')">💡 Contoh Penggunaan</button>
                </div>

                <!-- Tutorial Tab -->
                <div id="tutorial-tab" class="tab-content active">
                    <div class="doc-section">
                        <div class="section-header">
                            <h2>🚀 Tutorial Menggunakan Form Input Statistik</h2>
                            <span class="badge badge-primary">Panduan Lengkap</span>
                        </div>

                        <div class="tutorial-steps">
                            <!-- Step 1 -->
                            <div class="step-card step-blue">
                                <div class="step-number">1</div>
                                <div class="step-content">
                                    <h3>Akses Menu Input Statistik</h3>
                                    <ul>
                                        <li>Login ke WordPress Admin Dashboard</li>
                                        <li>Buka menu <strong>"Statistik Desa"</strong> di sidebar</li>
                                        <li>Klik <strong>"Input Statistik"</strong></li>
                                    </ul>
                                </div>
                            </div>

                            <!-- Step 2 -->
                            <div class="step-card step-green">
                                <div class="step-number">2</div>
                                <div class="step-content">
                                    <h3>Pilih Tahun Data</h3>
                                    <ul>
                                        <li>Pilih tahun dari dropdown (tersedia 3 tahun ke belakang dan ke depan)</li>
                                        <li>Contoh: 2024, 2023, 2025</li>
                                        <li><strong>Catatan:</strong> Tahun tidak bisa diubah saat edit data</li>
                                    </ul>
                                </div>
                            </div>

                            <!-- Step 3 -->
                            <div class="step-card step-yellow">
                                <div class="step-number">3</div>
                                <div class="step-content">
                                    <h3>Pilih Kategori Statistik</h3>
                                    <p>Pilih kategori data yang akan diinput:</p>
                                    <div class="category-grid">
                                        <?php
                                        $categories = $this->get_categories();
                                        $count = 0;
                                        foreach ($categories as $code => $name):
                                            if ($count >= 9)
                                                break; // Limit to 9 for display
                                            ?>
                                            <div class="category-item">
                                                <code><?php echo esc_html($code); ?></code>
                                                <span><?php echo esc_html($name); ?></span>
                                            </div>
                                            <?php
                                            $count++;
                                        endforeach;
                                        ?>
                                        <div class="category-item more">
                                            <span>+<?php echo (count($categories) - 9); ?> kategori lainnya</span>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Step 4 -->
                            <div class="step-card step-purple">
                                <div class="step-number">4</div>
                                <div class="step-content">
                                    <h3>Isi Sumber Data (Opsional)</h3>
                                    <ul>
                                        <li>Masukkan sumber data statistik</li>
                                        <li>Contoh: "BPS Kabupaten", "Survei Desa 2024", "Data RT/RW"</li>
                                        <li>Field ini opsional tapi disarankan untuk transparansi</li>
                                    </ul>
                                </div>
                            </div>

                            <!-- Step 5 -->
                            <div class="step-card step-indigo">
                                <div class="step-number">5</div>
                                <div class="step-content">
                                    <h3>Input Data Berdasarkan Kategori</h3>

                                    <div class="input-types">
                                        <div class="input-type-card">
                                            <h4>📊 Kategori Regular (Contoh: Agama, Jenis Kelamin)</h4>
                                            <ul>
                                                <li>Field akan muncul otomatis setelah memilih kategori</li>
                                                <li>Isi angka untuk setiap field yang tersedia</li>
                                                <li>Contoh untuk Agama: Islam: 1500, Kristen: 200, dll</li>
                                                <li>Gunakan angka 0 jika tidak ada data</li>
                                            </ul>
                                        </div>

                                        <div class="input-type-card">
                                            <h4>🏘️ Kategori RW Dinamis (Contoh: Penerima Bantuan per RW)</h4>
                                            <ul>
                                                <li>Akan muncul form RW 1 secara default</li>
                                                <li>Klik tombol <strong>"+ Tambah RW"</strong> untuk menambah RW baru</li>
                                                <li>Isi jumlah penerima untuk setiap RW</li>
                                                <li>Klik <strong>"Hapus"</strong> untuk menghapus RW yang tidak diperlukan</li>
                                                <li>RW akan otomatis ter-renumber jika ada yang dihapus</li>
                                            </ul>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Step 6 -->
                            <div class="step-card step-gray">
                                <div class="step-number">6</div>
                                <div class="step-content">
                                    <h3>Pengaturan Publikasi</h3>
                                    <ul>
                                        <li><strong>Centang "Tampilkan di publik"</strong> jika data siap dipublikasi</li>
                                        <li>Biarkan tidak tercentang untuk menyimpan sebagai draft</li>
                                        <li>Data draft tidak akan muncul di shortcode dan API publik</li>
                                    </ul>
                                </div>
                            </div>

                            <!-- Step 7 -->
                            <div class="step-card step-red">
                                <div class="step-number">7</div>
                                <div class="step-content">
                                    <h3>Simpan Data</h3>
                                    <ul>
                                        <li>Klik tombol <strong>"Simpan Data"</strong> untuk data baru</li>
                                        <li>Klik tombol <strong>"Update Data"</strong> untuk edit data</li>
                                        <li>Sistem akan menampilkan pesan konfirmasi jika berhasil</li>
                                        <li>Data akan tersimpan dan bisa diakses melalui menu "Daftar Statistik"</li>
                                    </ul>
                                </div>
                            </div>
                        </div>

                        <!-- Tips Section -->
                        <div class="tips-section">
                            <h3>💡 Tips & Best Practices</h3>
                            <div class="tips-grid">
                                <div class="tip-card tip-blue">
                                    <h4>🎯 Konsistensi Data</h4>
                                    <p>Pastikan format dan satuan data konsisten untuk setiap periode</p>
                                </div>
                                <div class="tip-card tip-green">
                                    <h4>📅 Update Berkala</h4>
                                    <p>Lakukan update data secara berkala sesuai periode yang ditentukan</p>
                                </div>
                                <div class="tip-card tip-yellow">
                                    <h4>🔍 Verifikasi Data</h4>
                                    <p>Selalu verifikasi data sebelum mempublikasikan ke publik</p>
                                </div>
                                <div class="tip-card tip-purple">
                                    <h4>📊 Backup Data</h4>
                                    <p>Lakukan backup data secara berkala untuk keamanan</p>
                                </div>
                            </div>
                        </div>

                        <!-- Troubleshooting -->
                        <div class="troubleshooting-section">
                            <h3>🚨 Troubleshooting</h3>
                            <div class="troubleshooting-items">
                                <div class="trouble-item">
                                    <h4>Data tidak tersimpan?</h4>
                                    <p>Pastikan semua field wajib terisi dan Anda memiliki hak akses admin</p>
                                </div>
                                <div class="trouble-item">
                                    <h4>Field kategori tidak muncul?</h4>
                                    <p>Refresh halaman dan pastikan JavaScript aktif di browser</p>
                                </div>
                                <div class="trouble-item">
                                    <h4>Error saat menambah RW?</h4>
                                    <p>Pastikan tidak ada field RW yang kosong sebelum menambah RW baru</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Shortcodes Tab -->
                <div id="shortcodes-tab" class="tab-content">
                    <div class="doc-section">
                        <div class="section-header">
                            <h2>📊 Shortcodes</h2>
                            <span class="badge badge-success">4 Shortcode Tersedia</span>
                        </div>

                        <!-- Display Shortcode -->
                        <div class="shortcode-card">
                            <div class="shortcode-header">
                                <h3>1. Display Shortcode - Tampilan Card</h3>
                                <span class="badge badge-primary">Populer</span>
                            </div>
                            <p>Menampilkan data statistik dalam format card yang menarik dan responsive</p>
                            <div class="code-block">
                                <code>[statistic_display year="2024" category="agama" show_source="true"]</code>
                                <button class="copy-btn" onclick="copyCode(this)">📋 Copy</button>
                            </div>

                            <div class="parameters-table">
                                <h4>Parameter yang tersedia:</h4>
                                <table>
                                    <thead>
                                        <tr>
                                            <th>Parameter</th>
                                            <th>Nilai</th>
                                            <th>Default</th>
                                            <th>Keterangan</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr>
                                            <td><code>year</code></td>
                                            <td>2024, 2023, dll</td>
                                            <td>Semua tahun</td>
                                            <td>Filter berdasarkan tahun</td>
                                        </tr>
                                        <tr>
                                            <td><code>category</code></td>
                                            <td>agama, jenis_kelamin, dll</td>
                                            <td>Semua kategori</td>
                                            <td>Filter berdasarkan kategori</td>
                                        </tr>
                                        <tr>
                                            <td><code>published_only</code></td>
                                            <td>true/false</td>
                                            <td>true</td>
                                            <td>Tampilkan hanya data yang dipublikasi</td>
                                        </tr>
                                        <tr>
                                            <td><code>show_source</code></td>
                                            <td>true/false</td>
                                            <td>true</td>
                                            <td>Tampilkan sumber data</td>
                                        </tr>
                                        <tr>
                                            <td><code>show_year</code></td>
                                            <td>true/false</td>
                                            <td>true</td>
                                            <td>Tampilkan tahun di header</td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>

                        <!-- Table Shortcode -->
                        <div class="shortcode-card">
                            <div class="shortcode-header">
                                <h3>2. Table Shortcode - Tampilan Tabel</h3>
                                <span class="badge badge-success">Interaktif</span>
                            </div>
                            <p>Menampilkan data statistik dalam format tabel yang rapi</p>
                            <div class="code-block">
                                <code>[statistic_table year="2024" limit="5" show_source="true"]</code>
                                <button class="copy-btn" onclick="copyCode(this)">📋 Copy</button>
                            </div>
                            <div class="note">
                                <p><strong>Parameter tambahan:</strong> <code>limit</code> - Batasi jumlah data yang ditampilkan
                                    (default: 10)</p>
                            </div>
                        </div>

                        <!-- Chart Shortcode -->
                        <div class="shortcode-card">
                            <div class="shortcode-header">
                                <h3>3. Chart Shortcode - Tampilan Grafik</h3>
                                <span class="badge badge-warning">Baru</span>
                            </div>
                            <p>Menampilkan data statistik dalam format grafik interaktif dengan berbagai jenis chart</p>

                            <div class="chart-types">
                                <div class="chart-type">
                                    <h5>Bar Chart (Vertikal)</h5>
                                    <div class="code-block">
                                        <code>[statistic_chart year="2024" category="agama" type="bar" height="400"]</code>
                                        <button class="copy-btn" onclick="copyCode(this)">📋 Copy</button>
                                    </div>
                                </div>

                                <div class="chart-type">
                                    <h5>Bar Chart (Horizontal) - Baru!</h5>
                                    <div class="code-block">
                                        <code>[statistic_chart year="2024" category="agama" type="horizontalBar" height="400"]</code>
                                        <button class="copy-btn" onclick="copyCode(this)">📋 Copy</button>
                                    </div>
                                </div>

                                <div class="chart-type">
                                    <h5>Pie Chart</h5>
                                    <div class="code-block">
                                        <code>[statistic_chart year="2024" category="agama" type="pie" height="400"]</code>
                                        <button class="copy-btn" onclick="copyCode(this)">📋 Copy</button>
                                    </div>
                                </div>
                            </div>

                            <div class="parameters-table">
                                <h4>Parameter yang tersedia:</h4>
                                <table>
                                    <thead>
                                        <tr>
                                            <th>Parameter</th>
                                            <th>Nilai</th>
                                            <th>Default</th>
                                            <th>Keterangan</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr>
                                            <td><code>year</code></td>
                                            <td>2024, 2023, dll</td>
                                            <td>Tahun sekarang</td>
                                            <td><strong style="color: #d63638;">⚠️ Wajib diisi!</strong></td>
                                        </tr>
                                        <tr>
                                            <td><code>category</code></td>
                                            <td>agama, jenis_kelamin, dll</td>
                                            <td>-</td>
                                            <td><strong style="color: #d63638;">⚠️ Wajib diisi!</strong></td>
                                        </tr>
                                        <tr>
                                            <td><code>type</code></td>
                                            <td>bar, horizontalBar, pie, line, doughnut, radar</td>
                                            <td>bar</td>
                                            <td>Jenis grafik</td>
                                        </tr>
                                        <tr>
                                            <td><code>height</code></td>
                                            <td>300, 400, 500, dll</td>
                                            <td>400</td>
                                            <td>Tinggi grafik (pixel)</td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>

                        <!-- Form Shortcode -->
                        <div class="shortcode-card">
                            <div class="shortcode-header">
                                <h3>4. Form Shortcode - Form Input</h3>
                                <span class="badge badge-danger">Admin Only</span>
                            </div>
                            <p>Menampilkan form input statistik di frontend (hanya untuk admin)</p>
                            <div class="code-block">
                                <code>[statistic_form]</code>
                                <button class="copy-btn" onclick="copyCode(this)">📋 Copy</button>
                            </div>
                            <div class="warning">
                                <p><strong>⚠️ Penting:</strong> Shortcode ini hanya akan ditampilkan untuk user yang memiliki hak
                                    akses admin.</p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- API Tab -->
                <div id="api-tab" class="tab-content">
                    <div class="doc-section">
                        <div class="section-header">
                            <h2>🔌 REST API Endpoints</h2>
                            <span class="badge badge-info">3 Endpoint</span>
                        </div>

                        <div class="api-endpoints">
                            <div class="endpoint-card">
                                <h3>1. Get All Statistics</h3>
                                <div class="code-block">
                                    <code>GET /wp-json/statistic/v1/data</code>
                                    <button class="copy-btn" onclick="copyCode(this)">📋 Copy</button>
                                </div>
                                <h4>Parameter Query:</h4>
                                <ul>
                                    <li><code>?published=false</code> - Tampilkan semua data (termasuk yang tidak dipublikasi)</li>
                                    <li><code>?year=2024</code> - Filter berdasarkan tahun</li>
                                    <li><code>?category=agama</code> - Filter berdasarkan kategori</li>
                                </ul>
                            </div>

                            <div class="endpoint-card">
                                <h3>2. Get Statistics by Year</h3>
                                <div class="code-block">
                                    <code>GET /wp-json/statistic/v1/data/2024</code>
                                    <button class="copy-btn" onclick="copyCode(this)">📋 Copy</button>
                                </div>
                            </div>

                            <div class="endpoint-card">
                                <h3>3. Get Specific Statistic</h3>
                                <div class="code-block">
                                    <code>GET /wp-json/statistic/v1/data/2024/agama</code>
                                    <button class="copy-btn" onclick="copyCode(this)">📋 Copy</button>
                                </div>
                            </div>
                        </div>

                        <div class="categories-table">
                            <h3>📋 Kategori yang Tersedia</h3>
                            <table>
                                <thead>
                                    <tr>
                                        <th>Kode Kategori</th>
                                        <th>Nama Kategori</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($this->get_categories() as $code => $name): ?>
                                        <tr>
                                            <td><code><?php echo esc_html($code); ?></code></td>
                                            <td><?php echo esc_html($name); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- Examples Tab -->
                <div id="examples-tab" class="tab-content">
                    <div class="doc-section">
                        <div class="section-header">
                            <h2>💡 Contoh Penggunaan</h2>
                            <span class="badge badge-success">Praktis</span>
                        </div>

                        <div class="example-card">
                            <h3>📊 Contoh 1: Halaman Statistik Lengkap</h3>
                            <p>Untuk membuat halaman yang menampilkan semua statistik tahun 2024:</p>
                            <div class="code-block large">
                                <pre><code>&lt;h2&gt;Statistik Desa Tahun 2024&lt;/h2&gt;
[statistic_display year="2024"]

&lt;h3&gt;Tabel Ringkasan&lt;/h3&gt;
[statistic_table year="2024" limit="10"]</code></pre>
                                <button class="copy-btn" onclick="copyCode(this)">📋 Copy</button>
                            </div>
                        </div>

                        <div class="example-card">
                            <h3>📈 Contoh 2: Dashboard dengan Grafik</h3>
                            <p>Untuk membuat dashboard dengan berbagai grafik:</p>
                            <div class="code-block large">
                                <pre><code>&lt;div class="row"&gt;
  &lt;div class="col-md-6"&gt;
    &lt;h3&gt;Data Agama (Pie Chart)&lt;/h3&gt;
    [statistic_chart year="2024" category="agama" type="pie"]
  &lt;/div&gt;
  &lt;div class="col-md-6"&gt;
    &lt;h3&gt;Data Jenis Kelamin (Bar Chart)&lt;/h3&gt;
    [statistic_chart year="2024" category="jenis_kelamin" type="bar"]
  &lt;/div&gt;
&lt;/div&gt;

&lt;div class="row mt-4"&gt;
  &lt;div class="col-12"&gt;
    &lt;h3&gt;Data RW (Horizontal Bar)&lt;/h3&gt;
    [statistic_chart year="2024" category="penerima_pemberian_makanan_tambahan" type="horizontalBar" height="300"]
  &lt;/div&gt;
&lt;/div&gt;</code></pre>
                                <button class="copy-btn" onclick="copyCode(this)">📋 Copy</button>
                            </div>
                        </div>

                        <div class="example-card">
                            <h3>🔧 Contoh 3: JavaScript untuk API</h3>
                            <p>Menggunakan JavaScript untuk mengambil data via API:</p>
                            <div class="code-block large">
                                <pre><code>// Ambil semua data
fetch('/wp-json/statistic/v1/data')
  .then(response => response.json())
  .then(data => {
    console.log('Semua data:', data);
  });

// Ambil data spesifik
fetch('/wp-json/statistic/v1/data/2024/agama')
  .then(response => response.json())
  .then(data => {
    console.log('Data agama 2024:', data);
  });</code></pre>
                                <button class="copy-btn" onclick="copyCode(this)">📋 Copy</button>
                            </div>
                        </div>

                        <div class="best-practices">
                            <h3>💡 Tips & Best Practices</h3>
                            <div class="tips-grid">
                                <div class="tip-card tip-blue">
                                    <h4>🎨 Responsive Design</h4>
                                    <p>Semua shortcode sudah menggunakan Bootstrap dan responsive</p>
                                </div>
                                <div class="tip-card tip-green">
                                    <h4>⚡ Performance</h4>
                                    <p>Gunakan parameter filter untuk membatasi data yang ditampilkan</p>
                                </div>
                                <div class="tip-card tip-yellow">
                                    <h4>🔒 Security</h4>
                                    <p>API hanya menampilkan data yang dipublikasi secara default</p>
                                </div>
                                <div class="tip-card tip-purple">
                                    <h4>🎯 Customization</h4>
                                    <p>Anda bisa menambahkan CSS custom untuk styling tambahan</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <style>
                /* Documentation Styling - White and Gray Theme */
                .documentation-container {
                    background: #ffffff;
                    border-radius: 8px;
                    box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
                    overflow: hidden;
                    margin: 20px 0;
                }

                .doc-header {
                    background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
                    padding: 30px;
                    text-align: center;
                    border-bottom: 1px solid #dee2e6;
                }

                .doc-header h1 {
                    margin: 0 0 10px 0;
                    color: #495057;
                    font-size: 28px;
                    font-weight: 600;
                }

                .doc-subtitle {
                    color: #6c757d;
                    font-size: 16px;
                    margin: 0;
                    max-width: 600px;
                    margin: 0 auto;
                }

                .doc-tabs {
                    display: flex;
                    background: #f8f9fa;
                    border-bottom: 1px solid #dee2e6;
                    overflow-x: auto;
                }

                .tab-button {
                    background: none;
                    border: none;
                    padding: 15px 25px;
                    cursor: pointer;
                    font-size: 14px;
                    font-weight: 500;
                    color: #6c757d;
                    border-bottom: 3px solid transparent;
                    transition: all 0.3s ease;
                    white-space: nowrap;
                }

                .tab-button:hover {
                    background: #e9ecef;
                    color: #495057;
                }

                .tab-button.active {
                    color: #495057;
                    border-bottom-color: #007bff;
                    background: #ffffff;
                }

                .tab-content {
                    display: none;
                    padding: 30px;
                }

                .tab-content.active {
                    display: block;
                }

                .doc-section {
                    max-width: 1000px;
                    margin: 0 auto;
                }

                .section-header {
                    display: flex;
                    justify-content: space-between;
                    align-items: center;
                    margin-bottom: 30px;
                    padding-bottom: 15px;
                    border-bottom: 2px solid #f8f9fa;
                }

                .section-header h2 {
                    margin: 0;
                    color: #495057;
                    font-size: 24px;
                    font-weight: 600;
                }

                .badge {
                    padding: 6px 12px;
                    border-radius: 20px;
                    font-size: 12px;
                    font-weight: 600;
                    text-transform: uppercase;
                    letter-spacing: 0.5px;
                }

                .badge-primary {
                    background: #007bff;
                    color: white;
                }

                .badge-success {
                    background: #28a745;
                    color: white;
                }

                .badge-warning {
                    background: #ffc107;
                    color: #212529;
                }

                .badge-danger {
                    background: #dc3545;
                    color: white;
                }

                .badge-info {
                    background: #17a2b8;
                    color: white;
                }

                /* Tutorial Steps Styling */
                .tutorial-steps {
                    margin-bottom: 40px;
                }

                .step-card {
                    display: flex;
                    margin-bottom: 20px;
                    background: #ffffff;
                    border-radius: 8px;
                    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
                    overflow: hidden;
                    border-left: 4px solid;
                }

                .step-blue {
                    border-left-color: #007bff;
                }

                .step-green {
                    border-left-color: #28a745;
                }

                .step-yellow {
                    border-left-color: #ffc107;
                }

                .step-purple {
                    border-left-color: #6f42c1;
                }

                .step-indigo {
                    border-left-color: #6610f2;
                }

                .step-gray {
                    border-left-color: #6c757d;
                }

                .step-red {
                    border-left-color: #dc3545;
                }

                .step-number {
                    background: #f8f9fa;
                    width: 60px;
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    font-size: 24px;
                    font-weight: bold;
                    color: #495057;
                }

                .step-content {
                    padding: 20px;
                    flex: 1;
                }

                .step-content h3 {
                    margin: 0 0 15px 0;
                    color: #495057;
                    font-size: 18px;
                    font-weight: 600;
                }

                .step-content ul {
                    margin: 0;
                    padding-left: 20px;
                }

                .step-content li {
                    margin-bottom: 8px;
                    color: #6c757d;
                    line-height: 1.5;
                }

                .category-grid {
                    display: grid;
                    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
                    gap: 10px;
                    margin-top: 15px;
                }

                .category-item {
                    background: #f8f9fa;
                    padding: 10px;
                    border-radius: 6px;
                    border: 1px solid #e9ecef;
                }

                .category-item code {
                    background: #e9ecef;
                    color: #495057;
                    padding: 2px 6px;
                    border-radius: 3px;
                    font-size: 11px;
                    font-weight: 600;
                    display: block;
                    margin-bottom: 5px;
                }

                .category-item span {
                    color: #6c757d;
                    font-size: 13px;
                }

                .category-item.more {
                    background: #e9ecef;
                    text-align: center;
                    font-style: italic;
                    color: #6c757d;
                }

                .input-types {
                    margin-top: 15px;
                }

                .input-type-card {
                    background: #f8f9fa;
                    padding: 15px;
                    border-radius: 6px;
                    margin-bottom: 15px;
                    border: 1px solid #e9ecef;
                }

                .input-type-card h4 {
                    margin: 0 0 10px 0;
                    color: #495057;
                    font-size: 16px;
                }

                .input-type-card ul {
                    margin: 0;
                    padding-left: 20px;
                }

                .input-type-card li {
                    margin-bottom: 5px;
                    color: #6c757d;
                    font-size: 14px;
                }

                /* Tips Section */
                .tips-section {
                    background: #f8f9fa;
                    padding: 25px;
                    border-radius: 8px;
                    margin-bottom: 30px;
                    border: 1px solid #e9ecef;
                }

                .tips-section h3 {
                    margin: 0 0 20px 0;
                    color: #495057;
                    font-size: 20px;
                    font-weight: 600;
                }

                .tips-grid {
                    display: grid;
                    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
                    gap: 15px;
                }

                .tip-card {
                    background: #ffffff;
                    padding: 15px;
                    border-radius: 6px;
                    border-left: 4px solid;
                    box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
                }

                .tip-blue {
                    border-left-color: #007bff;
                }

                .tip-green {
                    border-left-color: #28a745;
                }

                .tip-yellow {
                    border-left-color: #ffc107;
                }

                .tip-purple {
                    border-left-color: #6f42c1;
                }

                .tip-card h4 {
                    margin: 0 0 8px 0;
                    color: #495057;
                    font-size: 14px;
                    font-weight: 600;
                }

                .tip-card p {
                    margin: 0;
                    color: #6c757d;
                    font-size: 13px;
                    line-height: 1.4;
                }

                /* Troubleshooting */
                .troubleshooting-section {
                    background: #fff5f5;
                    padding: 25px;
                    border-radius: 8px;
                    border: 1px solid #fed7d7;
                }

                .troubleshooting-section h3 {
                    margin: 0 0 20px 0;
                    color: #c53030;
                    font-size: 20px;
                    font-weight: 600;
                }

                .troubleshooting-items {
                    display: grid;
                    gap: 15px;
                }

                .trouble-item {
                    background: #ffffff;
                    padding: 15px;
                    border-radius: 6px;
                    border-left: 4px solid #dc3545;
                    box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
                }

                .trouble-item h4 {
                    margin: 0 0 8px 0;
                    color: #c53030;
                    font-size: 14px;
                    font-weight: 600;
                }

                .trouble-item p {
                    margin: 0;
                    color: #6c757d;
                    font-size: 13px;
                    line-height: 1.4;
                }

                /* Shortcode Cards */
                .shortcode-card {
                    background: #ffffff;
                    border: 1px solid #e9ecef;
                    border-radius: 8px;
                    margin-bottom: 25px;
                    overflow: hidden;
                    box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
                }

                .shortcode-header {
                    background: #f8f9fa;
                    padding: 15px 20px;
                    border-bottom: 1px solid #e9ecef;
                    display: flex;
                    justify-content: space-between;
                    align-items: center;
                }

                .shortcode-header h3 {
                    margin: 0;
                    color: #495057;
                    font-size: 18px;
                    font-weight: 600;
                }

                .shortcode-card p {
                    padding: 0 20px;
                    margin: 15px 0;
                    color: #6c757d;
                    line-height: 1.5;
                }

                .code-block {
                    background: #f8f9fa;
                    border: 1px solid #e9ecef;
                    border-radius: 6px;
                    padding: 15px;
                    margin: 15px 20px;
                    position: relative;
                    font-family: 'Courier New', monospace;
                }

                .code-block.large {
                    margin: 15px 0;
                }

                .code-block code {
                    color: #e83e8c;
                    font-weight: 500;
                    font-size: 14px;
                }

                .code-block pre {
                    margin: 0;
                    white-space: pre-wrap;
                    word-wrap: break-word;
                }

                .copy-btn {
                    position: absolute;
                    top: 10px;
                    right: 10px;
                    background: #007bff;
                    color: white;
                    border: none;
                    padding: 6px 10px;
                    border-radius: 4px;
                    cursor: pointer;
                    font-size: 11px;
                    font-weight: 500;
                    transition: background 0.3s ease;
                }

                .copy-btn:hover {
                    background: #0056b3;
                }

                .parameters-table {
                    padding: 0 20px 20px;
                }

                .parameters-table h4 {
                    margin: 20px 0 15px 0;
                    color: #495057;
                    font-size: 16px;
                    font-weight: 600;
                }

                .parameters-table table {
                    width: 100%;
                    border-collapse: collapse;
                    border: 1px solid #e9ecef;
                    border-radius: 6px;
                    overflow: hidden;
                }

                .parameters-table th {
                    background: #f8f9fa;
                    padding: 12px;
                    text-align: left;
                    font-weight: 600;
                    color: #495057;
                    border-bottom: 1px solid #e9ecef;
                    font-size: 13px;
                }

                .parameters-table td {
                    padding: 10px 12px;
                    border-bottom: 1px solid #f8f9fa;
                    color: #6c757d;
                    font-size: 13px;
                    vertical-align: top;
                }

                .parameters-table code {
                    background: #f8f9fa;
                    color: #e83e8c;
                    padding: 2px 6px;
                    border-radius: 3px;
                    font-size: 12px;
                    font-weight: 500;
                }

                .chart-types {
                    padding: 0 20px;
                }

                .chart-type {
                    margin-bottom: 15px;
                }

                .chart-type h5 {
                    margin: 0 0 8px 0;
                    color: #495057;
                    font-size: 14px;
                    font-weight: 600;
                }

                .note {
                    background: #e7f3ff;
                    border: 1px solid #b3d9ff;
                    border-radius: 6px;
                    padding: 15px;
                    margin: 15px 20px 20px;
                }

                .note p {
                    margin: 0;
                    padding: 0;
                    color: #0066cc;
                    font-size: 14px;
                }

                .warning {
                    background: #fff3cd;
                    border: 1px solid #ffeaa7;
                    border-radius: 6px;
                    padding: 15px;
                    margin: 15px 20px 20px;
                }

                .warning p {
                    margin: 0;
                    padding: 0;
                    color: #856404;
                    font-size: 14px;
                }

                /* API Endpoints */
                .api-endpoints {
                    margin-bottom: 30px;
                }

                .endpoint-card {
                    background: #ffffff;
                    border: 1px solid #e9ecef;
                    border-radius: 8px;
                    padding: 20px;
                    margin-bottom: 20px;
                    box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
                }

                .endpoint-card h3 {
                    margin: 0 0 15px 0;
                    color: #495057;
                    font-size: 18px;
                    font-weight: 600;
                }

                .endpoint-card h4 {
                    margin: 15px 0 10px 0;
                    color: #495057;
                    font-size: 14px;
                    font-weight: 600;
                }

                .endpoint-card ul {
                    margin: 0;
                    padding-left: 20px;
                }

                .endpoint-card li {
                    margin-bottom: 5px;
                    color: #6c757d;
                    font-size: 14px;
                }

                .categories-table {
                    background: #f8f9fa;
                    padding: 20px;
                    border-radius: 8px;
                    border: 1px solid #e9ecef;
                }

                .categories-table h3 {
                    margin: 0 0 15px 0;
                    color: #495057;
                    font-size: 18px;
                    font-weight: 600;
                }

                .categories-table table {
                    width: 100%;
                    border-collapse: collapse;
                    background: #ffffff;
                    border-radius: 6px;
                    overflow: hidden;
                    box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
                }

                .categories-table th {
                    background: #495057;
                    color: white;
                    padding: 12px;
                    text-align: left;
                    font-weight: 600;
                    font-size: 13px;
                }

                .categories-table td {
                    padding: 10px 12px;
                    border-bottom: 1px solid #f8f9fa;
                    color: #6c757d;
                    font-size: 13px;
                }

                .categories-table code {
                    background: #f8f9fa;
                    color: #e83e8c;
                    padding: 2px 6px;
                    border-radius: 3px;
                    font-size: 12px;
                    font-weight: 500;
                }

                /* Examples */
                .example-card {
                    background: #ffffff;
                    border: 1px solid #e9ecef;
                    border-radius: 8px;
                    padding: 20px;
                    margin-bottom: 25px;
                    box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
                }

                .example-card h3 {
                    margin: 0 0 10px 0;
                    color: #495057;
                    font-size: 18px;
                    font-weight: 600;
                }

                .example-card p {
                    margin: 0 0 15px 0;
                    color: #6c757d;
                    line-height: 1.5;
                }

                .best-practices {
                    background: #f8f9fa;
                    padding: 25px;
                    border-radius: 8px;
                    border: 1px solid #e9ecef;
                    margin-top: 30px;
                }

                .best-practices h3 {
                    margin: 0 0 20px 0;
                    color: #495057;
                    font-size: 20px;
                    font-weight: 600;
                }

                /* Responsive Design */
                @media (max-width: 768px) {
                    .doc-header {
                        padding: 20px;
                    }

                    .doc-header h1 {
                        font-size: 24px;
                    }

                    .doc-subtitle {
                        font-size: 14px;
                    }

                    .tab-content {
                        padding: 20px;
                    }

                    .section-header {
                        flex-direction: column;
                        align-items: flex-start;
                        gap: 10px;
                    }

                    .step-card {
                        flex-direction: column;
                    }

                    .step-number {
                        width: 100%;
                        height: 50px;
                    }

                    .category-grid {
                        grid-template-columns: 1fr;
                    }

                    .tips-grid {
                        grid-template-columns: 1fr;
                    }

                    .code-block {
                        margin: 15px 0;
                        font-size: 12px;
                    }

                    .copy-btn {
                        position: static;
                        margin-top: 10px;
                        width: 100%;
                    }

                    .parameters-table {
                        overflow-x: auto;
                    }

                    .categories-table {
                        overflow-x: auto;
                    }
                }
            </style>

            <script>
                // Tab functionality
                function showTab(tabName) {
                    // Hide all tab contents
                    const tabContents = document.querySelectorAll('.tab-content');
                    tabContents.forEach(content => content.classList.remove('active'));

                    // Remove active class from all tab buttons
                    const tabButtons = document.querySelectorAll('.tab-button');
                    tabButtons.forEach(button => button.classList.remove('active'));

                    // Show selected tab content
                    document.getElementById(tabName + '-tab').classList.add('active');

                    // Add active class to clicked tab button
                    event.target.classList.add('active');
                }

                // Copy code functionality
                function copyCode(button) {
                    const codeBlock = button.parentNode;
                    const code = codeBlock.querySelector('code, pre');
                    const text = code.textContent;

                    navigator.clipboard.writeText(text).then(function () {
                        const originalText = button.textContent;
                        button.textContent = '✅ Copied!';
                        button.style.background = '#28a745';

                        setTimeout(() => {
                            button.textContent = originalText;
                            button.style.background = '#007bff';
                        }, 2000);
                    }).catch(function (err) {
                        console.error('Could not copy text: ', err);
                        alert('Kode berhasil disalin ke clipboard');
                    });
                }
            </script>
        </div>
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
            wp_send_json_error('Nonce verification failed.');
            return;
        }

        // Cek permission
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized access.');
            return;
        }

        global $wpdb;

        $year = intval($_POST['year']);
        $category = sanitize_text_field($_POST['category']);

        if (empty($year) || empty($category)) {
            wp_send_json_error('Parameter tidak lengkap.');
            return;
        }

        $result = $wpdb->delete(
            $this->table_name,
            array('year' => $year, 'category' => $category),
            array('%d', '%s')
        );

        if ($result !== false) {
            wp_send_json_success('Data berhasil dihapus.');
        } else {
            wp_send_json_error('Gagal menghapus data: ' . $wpdb->last_error);
        }
    }

    /**
     * Enqueue frontend scripts
     * Load CSS dan JS untuk frontend
     */
    public function enqueue_frontend_scripts()
    {
        // Chart.js untuk grafik
        wp_enqueue_script('chartjs', 'https://cdn.jsdelivr.net/npm/chart.js', array(), null, true);

        // Custom CSS untuk styling
        wp_add_inline_style('bootstrap', '
            .statistic-display .card { margin-bottom: 1rem; }
            .statistic-display .card-header { background-color: #f8f9fa; }
            .statistic-display .display-6 { font-size: 1.25rem; font-weight: 600; }
            .table-responsive { margin-top: 1rem; }
            .badge { margin-right: 0.25rem; margin-bottom: 0.25rem; }
        ');
    }

    /**
     * Enqueue admin scripts
     * Load CSS dan JS untuk halaman admin
     */
    public function enqueue_admin_scripts($hook)
    {
        // Hanya load di halaman plugin kita
        if (strpos($hook, 'statistic') === false) {
            return;
        }

        // jQuery untuk AJAX
        wp_enqueue_script('jquery');

        // Localize script untuk AJAX URL
        wp_localize_script('jquery', 'ajax_object', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('statistic_nonce')
        ));
    }
}

// Initialize the plugin
new StatisticPlugin();
?>
