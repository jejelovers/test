<?php
/*
Plugin Name: Statistik Desa/Kelurahan (NEW)
Description: Plugin untuk menyimpan dan menampilkan data statistik desa/kelurahan dengan form dinamis dan struktur nested untuk perbandingan gender.
Version: 2.1.1
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
    private $version = '2.1.1';

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
            category_type enum('regular', 'dynamic_rw', 'nested_gender') DEFAULT 'regular',
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
            $type = 'regular';
            if ($this->is_dynamic_rw_category($code)) {
                $type = 'dynamic_rw';
            } elseif ($this->is_nested_gender_category($code)) {
                $type = 'nested_gender';
            }

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

            // Insert fields for regular categories only
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
        add_action('wp_ajax_drop_categories_table', array($this, 'handle_drop_categories_table'));

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
     * Check if category uses nested gender structure
     * Cek apakah kategori menggunakan struktur nested gender
     */
    private function is_nested_gender_category($category)
    {
        global $wpdb;

        // Check from database first
        $category_type = $wpdb->get_var($wpdb->prepare(
            "SELECT category_type FROM {$this->categories_table} WHERE category_code = %s AND is_active = 1",
            $category
        ));

        if ($category_type) {
            return $category_type === 'nested_gender';
        }

        // Fallback to hardcoded list for default categories
        $nested_gender_categories = array(
            'agama',
            'pendidikan_dalam_kk',
            'pekerjaan',
            'status_perkawinan',
            'kategori_umur',
            'rentang_umur'
        );

        return in_array($category, $nested_gender_categories);
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
     * Get default categories (UPDATED with nested gender support)
     * Mendapatkan daftar kategori default
     */
    private function get_default_categories()
    {
        return array(
            // Kategori dengan nested gender structure
            'agama' => 'Agama',
            'pendidikan_dalam_kk' => 'Pendidikan dalam KK',
            'pekerjaan' => 'Pekerjaan',
            'status_perkawinan' => 'Status Perkawinan',
            'kategori_umur' => 'Kategori Umur',
            'rentang_umur' => 'Rentang Umur',

            // Kategori regular
            'jenis_kelamin' => 'Jenis Kelamin',
            'golongan_darah' => 'Golongan Darah',
            'hubungan_dalam_kk' => 'Hubungan dalam KK',
            'kelas_sosial' => 'Kelas Sosial',
            'penyandang_cacat' => 'Penyandang Cacat',
            'status_covid' => 'Status Covid',
            'status_penduduk' => 'Status Penduduk',
            'warga_negara' => 'Warga Negara',

            // Kategori dynamic RW
            'penerima_pemberian_makanan_tambahan' => 'Penerima Pemberian Makanan Tambahan',
            'jumlah_penerima_rastrada_berdasarkan_rw' => 'Jumlah Penerima Rastrada Berdasarkan RW',
            'jumlah_santri_berdasarkan_lokasi_rw' => 'Jumlah Santri Berdasarkan Lokasi RW',
            'jumlah_umkm_berdasarkan_rw' => 'Jumlah UMKM Berdasarkan RW',
            'jumlah_guru_ngaji_berdasarkan_lokasi_rw' => 'Jumlah Guru Ngaji Berdasarkan Lokasi RW',
            'jumlah_guru_ngaji_yang_mendapatkan_insentif_berdasarkan_wilayah' => 'Jumlah Guru Ngaji yang Mendapatkan Insentif Berdasarkan Wilayah',

            // Kategori regular lainnya
            'apbd_pelaksanaan' => 'APBD Pelaksanaan',
            'apbd_pembelanjaan' => 'APBD Pembelanjaan',
            'apbd_pendapatan' => 'APBD Pendapatan',
            'jumlah_guru_ngaji_berdasarkan_lokasi_belajar' => 'Jumlah Guru Ngaji Berdasarkan Lokasi Belajar',
            'jumlah_murid_berdasarkan_satuan_pendidikan_dan_jenis_kelamin' => 'Jumlah Murid Berdasarkan Satuan Pendidikan dan Jenis Kelamin',
            'jumlah_penerima_bpnt_pkh_dan_pbi_apbd' => 'Jumlah Penerima BPNT, PKH Dan PBI APBD',
            'jumlah_penerima_rutilahu' => 'Jumlah Penerima Rutilahu',
            'jumlah_umkm_berdasarkan_jenis_media_pemasaran' => 'Jumlah UMKM Berdasarkan Jenis Media Pemasaran',
            'jumlah_umkm_berdasarkan_jenis_usaha' => 'Jumlah UMKM Berdasarkan Jenis Usaha',
            'jumlah_umkm_berdasarkan_perizinan_dan_verifikasi' => 'Jumlah UMKM Berdasarkan Perizinan dan Verifikasi',
            'penerima_bantuan_keluarga' => 'Penerima Bantuan Keluarga',
            'penerima_bantuan_penduduk' => 'Penerima Bantuan Penduduk',
        );
    }

    /**
     * Get nested gender structure for categories
     * Mendapatkan struktur nested gender untuk kategori tertentu
     */
    private function get_nested_gender_structure()
    {
        // Built-in default structure
        $structure = array(
            'agama' => array(
                'islam' => array(
                    'laki_laki' => 'Islam - Laki-laki',
                    'perempuan' => 'Islam - Perempuan'
                ),
                'kristen' => array(
                    'laki_laki' => 'Kristen - Laki-laki',
                    'perempuan' => 'Kristen - Perempuan'
                ),
                'katolik' => array(
                    'laki_laki' => 'Katolik - Laki-laki',
                    'perempuan' => 'Katolik - Perempuan'
                ),
                'hindu' => array(
                    'laki_laki' => 'Hindu - Laki-laki',
                    'perempuan' => 'Hindu - Perempuan'
                ),
                'buddha' => array(
                    'laki_laki' => 'Buddha - Laki-laki',
                    'perempuan' => 'Buddha - Perempuan'
                ),
                'konghucu' => array(
                    'laki_laki' => 'Konghucu - Laki-laki',
                    'perempuan' => 'Konghucu - Perempuan'
                )
            ),
            'pendidikan_dalam_kk' => array(
                'tidak_sekolah' => array(
                    'laki_laki' => 'Tidak Sekolah - Laki-laki',
                    'perempuan' => 'Tidak Sekolah - Perempuan'
                ),
                'belum_tamat_sd' => array(
                    'laki_laki' => 'Belum Tamat SD - Laki-laki',
                    'perempuan' => 'Belum Tamat SD - Perempuan'
                ),
                'tamat_sd' => array(
                    'laki_laki' => 'Tamat SD - Laki-laki',
                    'perempuan' => 'Tamat SD - Perempuan'
                ),
                'sltp_sederajat' => array(
                    'laki_laki' => 'SLTP/Sederajat - Laki-laki',
                    'perempuan' => 'SLTP/Sederajat - Perempuan'
                ),
                'slta_sederajat' => array(
                    'laki_laki' => 'SLTA/Sederajat - Laki-laki',
                    'perempuan' => 'SLTA/Sederajat - Perempuan'
                ),
                'diploma_1_2' => array(
                    'laki_laki' => 'Diploma I/II - Laki-laki',
                    'perempuan' => 'Diploma I/II - Perempuan'
                ),
                'akademi_diploma_3' => array(
                    'laki_laki' => 'Akademi/Diploma III - Laki-laki',
                    'perempuan' => 'Akademi/Diploma III - Perempuan'
                ),
                'diploma_4_s1' => array(
                    'laki_laki' => 'Diploma IV/S1 - Laki-laki',
                    'perempuan' => 'Diploma IV/S1 - Perempuan'
                ),
                's2' => array(
                    'laki_laki' => 'S2 - Laki-laki',
                    'perempuan' => 'S2 - Perempuan'
                ),
                's3' => array(
                    'laki_laki' => 'S3 - Laki-laki',
                    'perempuan' => 'S3 - Perempuan'
                )
            ),
            'pekerjaan' => array(
                'petani' => array(
                    'laki_laki' => 'Petani - Laki-laki',
                    'perempuan' => 'Petani - Perempuan'
                ),
                'buruh_tani' => array(
                    'laki_laki' => 'Buruh Tani - Laki-laki',
                    'perempuan' => 'Buruh Tani - Perempuan'
                ),
                'nelayan' => array(
                    'laki_laki' => 'Nelayan - Laki-laki',
                    'perempuan' => 'Nelayan - Perempuan'
                ),
                'pedagang' => array(
                    'laki_laki' => 'Pedagang - Laki-laki',
                    'perempuan' => 'Pedagang - Perempuan'
                ),
                'pns' => array(
                    'laki_laki' => 'PNS - Laki-laki',
                    'perempuan' => 'PNS - Perempuan'
                ),
                'tni_polri' => array(
                    'laki_laki' => 'TNI/Polri - Laki-laki',
                    'perempuan' => 'TNI/Polri - Perempuan'
                ),
                'guru' => array(
                    'laki_laki' => 'Guru - Laki-laki',
                    'perempuan' => 'Guru - Perempuan'
                ),
                'dokter' => array(
                    'laki_laki' => 'Dokter - Laki-laki',
                    'perempuan' => 'Dokter - Perempuan'
                ),
                'bidan' => array(
                    'laki_laki' => 'Bidan - Laki-laki',
                    'perempuan' => 'Bidan - Perempuan'
                ),
                'perawat' => array(
                    'laki_laki' => 'Perawat - Laki-laki',
                    'perempuan' => 'Perawat - Perempuan'
                ),
                'pengusaha' => array(
                    'laki_laki' => 'Pengusaha - Laki-laki',
                    'perempuan' => 'Pengusaha - Perempuan'
                ),
                'buruh' => array(
                    'laki_laki' => 'Buruh - Laki-laki',
                    'perempuan' => 'Buruh - Perempuan'
                ),
                'sopir' => array(
                    'laki_laki' => 'Sopir - Laki-laki',
                    'perempuan' => 'Sopir - Perempuan'
                ),
                'tukang' => array(
                    'laki_laki' => 'Tukang - Laki-laki',
                    'perempuan' => 'Tukang - Perempuan'
                ),
                'ibu_rumah_tangga' => array(
                    'laki_laki' => 'Ibu Rumah Tangga - Laki-laki',
                    'perempuan' => 'Ibu Rumah Tangga - Perempuan'
                ),
                'pelajar_mahasiswa' => array(
                    'laki_laki' => 'Pelajar/Mahasiswa - Laki-laki',
                    'perempuan' => 'Pelajar/Mahasiswa - Perempuan'
                ),
                'pensiunan' => array(
                    'laki_laki' => 'Pensiunan - Laki-laki',
                    'perempuan' => 'Pensiunan - Perempuan'
                ),
                'lainnya' => array(
                    'laki_laki' => 'Lainnya - Laki-laki',
                    'perempuan' => 'Lainnya - Perempuan'
                )
            ),
            'status_perkawinan' => array(
                'belum_kawin' => array(
                    'laki_laki' => 'Belum Kawin - Laki-laki',
                    'perempuan' => 'Belum Kawin - Perempuan'
                ),
                'kawin' => array(
                    'laki_laki' => 'Kawin - Laki-laki',
                    'perempuan' => 'Kawin - Perempuan'
                ),
                'cerai_hidup' => array(
                    'laki_laki' => 'Cerai Hidup - Laki-laki',
                    'perempuan' => 'Cerai Hidup - Perempuan'
                ),
                'cerai_mati' => array(
                    'laki_laki' => 'Cerai Mati - Laki-laki',
                    'perempuan' => 'Cerai Mati - Perempuan'
                )
            ),
            'kategori_umur' => array(
                'balita_0_5' => array(
                    'laki_laki' => 'Balita (0-5 tahun) - Laki-laki',
                    'perempuan' => 'Balita (0-5 tahun) - Perempuan'
                ),
                'anak_6_12' => array(
                    'laki_laki' => 'Anak (6-12 tahun) - Laki-laki',
                    'perempuan' => 'Anak (6-12 tahun) - Perempuan'
                ),
                'remaja_13_17' => array(
                    'laki_laki' => 'Remaja (13-17 tahun) - Laki-laki',
                    'perempuan' => 'Remaja (13-17 tahun) - Perempuan'
                ),
                'dewasa_18_59' => array(
                    'laki_laki' => 'Dewasa (18-59 tahun) - Laki-laki',
                    'perempuan' => 'Dewasa (18-59 tahun) - Perempuan'
                ),
                'lansia_60_plus' => array(
                    'laki_laki' => 'Lansia (60+ tahun) - Laki-laki',
                    'perempuan' => 'Lansia (60+ tahun) - Perempuan'
                )
            ),
            'rentang_umur' => array(
                '0_4_tahun' => array(
                    'laki_laki' => '0-4 tahun - Laki-laki',
                    'perempuan' => '0-4 tahun - Perempuan'
                ),
                '5_9_tahun' => array(
                    'laki_laki' => '5-9 tahun - Laki-laki',
                    'perempuan' => '5-9 tahun - Perempuan'
                ),
                '10_14_tahun' => array(
                    'laki_laki' => '10-14 tahun - Laki-laki',
                    'perempuan' => '10-14 tahun - Perempuan'
                ),
                '15_19_tahun' => array(
                    'laki_laki' => '15-19 tahun - Laki-laki',
                    'perempuan' => '15-19 tahun - Perempuan'
                ),
                '20_24_tahun' => array(
                    'laki_laki' => '20-24 tahun - Laki-laki',
                    'perempuan' => '20-24 tahun - Perempuan'
                ),
                '25_29_tahun' => array(
                    'laki_laki' => '25-29 tahun - Laki-laki',
                    'perempuan' => '25-29 tahun - Perempuan'
                ),
                '30_34_tahun' => array(
                    'laki_laki' => '30-34 tahun - Laki-laki',
                    'perempuan' => '30-34 tahun - Perempuan'
                ),
                '35_39_tahun' => array(
                    'laki_laki' => '35-39 tahun - Laki-laki',
                    'perempuan' => '35-39 tahun - Perempuan'
                ),
                '40_44_tahun' => array(
                    'laki_laki' => '40-44 tahun - Laki-laki',
                    'perempuan' => '40-44 tahun - Perempuan'
                ),
                '45_49_tahun' => array(
                    'laki_laki' => '45-49 tahun - Laki-laki',
                    'perempuan' => '45-49 tahun - Perempuan'
                ),
                '50_54_tahun' => array(
                    'laki_laki' => '50-54 tahun - Laki-laki',
                    'perempuan' => '50-54 tahun - Perempuan'
                ),
                '55_59_tahun' => array(
                    'laki_laki' => '55-59 tahun - Laki-laki',
                    'perempuan' => '55-59 tahun - Perempuan'
                ),
                '60_64_tahun' => array(
                    'laki_laki' => '60-64 tahun - Laki-laki',
                    'perempuan' => '60-64 tahun - Perempuan'
                ),
                '65_69_tahun' => array(
                    'laki_laki' => '65-69 tahun - Laki-laki',
                    'perempuan' => '65-69 tahun - Perempuan'
                ),
                '70_74_tahun' => array(
                    'laki_laki' => '70-74 tahun - Laki-laki',
                    'perempuan' => '70-74 tahun - Perempuan'
                ),
                '75_plus_tahun' => array(
                    'laki_laki' => '75+ tahun - Laki-laki',
                    'perempuan' => '75+ tahun - Perempuan'
                )
            )
        );

        // Extend with DB-defined nested categories dynamically
        global $wpdb;
        $db_nested_categories = $wpdb->get_results("SELECT category_code FROM {$this->categories_table} WHERE category_type = 'nested_gender' AND is_active = 1");

        if ($db_nested_categories) {
            foreach ($db_nested_categories as $cat) {
                if (!isset($structure[$cat->category_code])) {
                    $fields = $wpdb->get_results($wpdb->prepare(
                        "SELECT field_code, field_name FROM {$this->fields_table} WHERE category_code = %s ORDER BY field_order ASC",
                        $cat->category_code
                    ));

                    // If fields exist, create male/female sub-structure for each
                    if ($fields) {
                        $structure[$cat->category_code] = array();
                        foreach ($fields as $field) {
                            $structure[$cat->category_code][$field->field_code] = array(
                                'laki_laki' => $field->field_name . ' - Laki-laki',
                                'perempuan' => $field->field_name . ' - Perempuan',
                            );
                        }
                    } else {
                        // Keep empty category to allow UI to display a proper message
                        $structure[$cat->category_code] = array();
                    }
                }
            }
        }
        
        return $structure;
    }

    /**
     * Split nested gender key into main key and gender part
     * Example: islam_laki_laki -> ['main_key' => 'islam', 'gender' => 'laki_laki']
     */
    private function split_nested_gender_key($key)
    {
        if (preg_match('/^(.*)_(laki_laki|perempuan)$/', $key, $matches)) {
            return array('main_key' => $matches[1], 'gender' => $matches[2]);
        }
        // Fallback handling if key was split incorrectly elsewhere
        $parts = explode('_', $key);
        if (count($parts) >= 3 && $parts[count($parts)-2] === 'laki' && $parts[count($parts)-1] === 'laki') {
            $main_key = implode('_', array_slice($parts, 0, -2));
            return array('main_key' => $main_key, 'gender' => 'laki_laki');
        }
        return array('main_key' => $key, 'gender' => null);
    }
    
    /**
     * Generate nested gender fields for form
     * Generate field form untuk kategori nested gender
     */
    private function generate_nested_gender_fields($category, $existing_data = array())
    {
        $nested_structure = $this->get_nested_gender_structure();

        if (!isset($nested_structure[$category])) {
            return '<p>Struktur nested gender tidak ditemukan untuk kategori ini.</p>';
        }
        if (empty($nested_structure[$category])) {
            return '<p>Belum ada field pada kategori ini. Tambahkan field melalui tombol Fields.</p>';
        }

        $html = '<div class="nested-gender-container">';
        $html .= '<div class="nested-gender-header">';
        $html .= '<h4>📊 Input Data dengan Perbandingan Gender</h4>';
        $html .= '<p class="description">Masukkan data untuk setiap kategori dengan pembagian laki-laki dan perempuan.</p>';
        $html .= '</div>';

        foreach ($nested_structure[$category] as $main_key => $gender_data) {
            // Extract main category name (remove gender part)
            $main_name = explode(' - ', reset($gender_data))[0];

            $html .= '<div class="nested-item">';
            $html .= '<div class="nested-item-header">';
            $html .= '<h5>' . esc_html($main_name) . '</h5>';
            $html .= '</div>';
            $html .= '<div class="nested-item-content">';

            foreach ($gender_data as $gender_key => $label) {
                $field_name = $category . '_' . $main_key . '_' . $gender_key;
                $field_value = isset($existing_data[$main_key . '_' . $gender_key]) ? $existing_data[$main_key . '_' . $gender_key] : '';

                $html .= '<div class="gender-field">';
                $html .= '<label for="' . esc_attr($field_name) . '" class="form-label">';
                $html .= '<span class="gender-icon">' . ($gender_key === 'laki_laki' ? '👨' : '👩') . '</span>';
                $html .= esc_html($gender_key === 'laki_laki' ? 'Laki-laki' : 'Perempuan');
                $html .= ':</label>';
                $html .= '<input type="number" id="' . esc_attr($field_name) . '" name="' . esc_attr($field_name) . '" min="0" step="1" class="form-control" value="' . esc_attr($field_value) . '" />';
                $html .= '</div>';
            }

            $html .= '</div>';
            $html .= '</div>';
        }

        $html .= '</div>';

        // Add CSS for nested gender styling
        $html .= '<style>
            .nested-gender-container {
                background: #f8f9fa;
                border: 1px solid #dee2e6;
                border-radius: 8px;
                padding: 20px;
                margin-bottom: 20px;
            }
            
            .nested-gender-header {
                margin-bottom: 20px;
                padding-bottom: 15px;
                border-bottom: 2px solid #e9ecef;
            }
            
            .nested-gender-header h4 {
                margin: 0 0 8px 0;
                color: #495057;
                font-size: 16px;
                font-weight: 600;
            }
            
            .nested-gender-header .description {
                margin: 0;
                color: #6c757d;
                font-size: 13px;
                font-style: italic;
            }
            
            .nested-item {
                background: white;
                border: 1px solid #e9ecef;
                border-radius: 6px;
                margin-bottom: 15px;
                overflow: hidden;
            }
            
            .nested-item:last-child {
                margin-bottom: 0;
            }
            
            .nested-item-header {
                background: #e9ecef;
                padding: 12px 15px;
                border-bottom: 1px solid #dee2e6;
            }
            
            .nested-item-header h5 {
                margin: 0;
                color: #495057;
                font-size: 14px;
                font-weight: 600;
            }
            
            .nested-item-content {
                padding: 15px;
                display: grid;
                grid-template-columns: 1fr 1fr;
                gap: 15px;
            }
            
            .gender-field {
                display: flex;
                flex-direction: column;
                gap: 5px;
            }
            
            .gender-field .form-label {
                display: flex;
                align-items: center;
                gap: 8px;
                font-size: 13px;
                font-weight: 500;
                color: #495057;
                margin-bottom: 5px;
            }
            
            .gender-icon {
                font-size: 16px;
            }
            
            .gender-field .form-control {
                padding: 8px 10px;
                border: 1px solid #ced4da;
                border-radius: 4px;
                font-size: 13px;
            }
            
            .gender-field .form-control:focus {
                outline: none;
                border-color: #007bff;
                box-shadow: 0 0 0 2px rgba(0, 123, 255, 0.25);
            }
            
            @media (max-width: 768px) {
                .nested-item-content {
                    grid-template-columns: 1fr;
                    gap: 10px;
                }
            }
        </style>';

        return $html;
    }

    /**
     * Convert nested gender form data to JSON
     * Konversi data form nested gender ke format JSON
     */
    private function convert_nested_gender_data_to_json($category, $post_data)
    {
        $nested_structure = $this->get_nested_gender_structure();
        $json_data = array();

        if (!isset($nested_structure[$category])) {
            return json_encode($json_data);
        }

        foreach ($nested_structure[$category] as $main_key => $gender_data) {
            foreach ($gender_data as $gender_key => $label) {
                $field_name = $category . '_' . $main_key . '_' . $gender_key;
                if (isset($post_data[$field_name]) && $post_data[$field_name] !== '') {
                    $json_key = $main_key . '_' . $gender_key;
                    $json_data[$json_key] = max(0, intval($post_data[$field_name]));
                }
            }
        }

        return json_encode($json_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }

    /**
     * Render nested gender display with comparison
     * Render tampilan data nested gender dengan perbandingan
     */
    private function render_nested_gender_display($category, $data)
    {
        $nested_structure = $this->get_nested_gender_structure();

        if (!isset($nested_structure[$category])) {
            echo '<p>Struktur data tidak ditemukan.</p>';
            return;
        }

        // Hitung total per sub-kategori (laki + perempuan) dan tampilkan box sederhana
        $totals_by_main = array();
        foreach ($data as $key => $value) {
            $parsed = $this->split_nested_gender_key($key);
            $main_key = $parsed['main_key'];
            if (!isset($totals_by_main[$main_key])) {
                $totals_by_main[$main_key] = 0;
            }
            $totals_by_main[$main_key] += intval($value);
        }

        echo '<div class="simple-total-grid">';
        foreach ($nested_structure[$category] as $main_key => $gender_labels) {
            $main_name = explode(' - ', reset($gender_labels))[0];
            $total_value = isset($totals_by_main[$main_key]) ? intval($totals_by_main[$main_key]) : 0;

            echo '<div class="simple-total-box">';
            echo '<div class="box-title">' . esc_html($main_name) . '</div>';
            echo '<div class="box-value">' . number_format($total_value) . '</div>';
            echo '</div>';
        }
        echo '</div>';

        // CSS ringkas untuk box kotak
        echo '<style>
            .simple-total-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(140px, 1fr)); gap: 12px; }
            .simple-total-box { border: 1px solid #e5e7eb; border-radius: 8px; background: #fff; padding: 12px; text-align: center; box-shadow: 0 1px 2px rgba(0,0,0,0.04); }
            .simple-total-box .box-title { font-size: 12px; color: #6b7280; margin-bottom: 6px; line-height: 1.2; min-height: 28px; }
            .simple-total-box .box-value { font-size: 20px; font-weight: 700; color: #111827; }
            @media (max-width: 576px) { .simple-total-grid { gap: 10px; } .simple-total-box { padding: 10px; } .simple-total-box .box-value { font-size: 18px; } }
        </style>';
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
     * Get default category fields (for backward compatibility)
     * Mendapatkan field default untuk setiap kategori
     */
    private function get_default_category_fields()
    {
        return array(
            // Regular categories (non-nested)
            'jenis_kelamin' => array(
                'laki_laki' => 'Laki-laki',
                'perempuan' => 'Perempuan'
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
            'kelas_sosial' => array(
                'prasejahtera' => 'Prasejahtera',
                'sejahtera_1' => 'Sejahtera I',
                'sejahtera_2' => 'Sejahtera II',
                'sejahtera_3' => 'Sejahtera III',
                'sejahtera_3_plus' => 'Sejahtera III Plus'
            ),
            'penyandang_cacat' => array(
                'cacat_fisik' => 'Cacat Fisik',
                'cacat_netra' => 'Cacat Netra/Buta',
                'cacat_rungu_wicara' => 'Cacat Rungu/Wicara',
                'cacat_mental' => 'Cacat Mental',
                'cacat_fisik_mental' => 'Cacat Fisik dan Mental'
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
            'warga_negara' => array(
                'wni' => 'Warga Negara Indonesia (WNI)',
                'wna' => 'Warga Negara Asing (WNA)',
                'dwi_kewarganegaraan' => 'Dwi Kewarganegaraan'
            ),
            // Add other regular categories here...
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
     * Convert form data to JSON (UPDATED to handle nested gender)
     * Konversi data form ke format JSON untuk disimpan di database
     */
    private function convert_form_data_to_json($category, $post_data)
    {
        // Check if this is a nested gender category
        if ($this->is_nested_gender_category($category)) {
            return $this->convert_nested_gender_data_to_json($category, $post_data);
        }

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
     * Generate category specific fields based on selected category (UPDATED)
     * Generate field form berdasarkan kategori yang dipilih - disesuaikan dengan nested gender
     */
    private function generate_category_fields($selected_category = '', $edit_data = null)
    {
        $fields_html = '';
        $categories = $this->get_categories();
        $existing_data = array();

        // Parse existing data if editing
        if ($edit_data && !empty($edit_data->data)) {
            $existing_data = json_decode($edit_data->data, true) ?: array();
        }

        foreach ($categories as $category => $category_name) {
            $display_style = ($selected_category == $category) ? 'block' : 'none';
            $fields_html .= '<div id="fields-' . $category . '" class="category-field" style="display: ' . $display_style . ';">';
            $fields_html .= '<div class="category-section">';
            $fields_html .= '<h3>' . esc_html($category_name) . '</h3>';

            // Check category type and generate appropriate fields
            if ($this->is_nested_gender_category($category)) {
                $fields_html .= $this->generate_nested_gender_fields($category, $existing_data);
            } elseif ($this->is_dynamic_rw_category($category)) {
                $fields_html .= $this->generate_dynamic_rw_fields($category, $existing_data);
            } else {
                // Generate regular form fields for each category
                $category_fields = $this->get_category_fields();
                if (isset($category_fields[$category])) {
                    foreach ($category_fields[$category] as $field_key => $field_label) {
                        $field_name = $category . '_' . $field_key;
                        $field_value = isset($existing_data[$field_key]) ? $existing_data[$field_key] : '';

                        $fields_html .= '<div class="field-group">';
                        $fields_html .= '<label for="' . esc_attr($field_name) . '" class="form-label">' . esc_html($field_label) . ':</label>';
                        $fields_html .= '<input type="number" id="' . esc_attr($field_name) . '" name="' . esc_attr($field_name) . '" min="0" step="1" class="form-control" value="' . esc_attr($field_value) . '" />';
                        $fields_html .= '</div>';
                    }
                }
            }

            $fields_html .= '</div>';
            $fields_html .= '</div>';
        }

        return $fields_html;
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
     * Handle form submission (UPDATED for nested gender)
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

                .type-nested {
                    background: #f0e7ff;
                    color: #7c3aed;
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
                    <button class="btn-delete" onclick="dropCategoriesTable()" title="Hapus tabel kategori (statistic_categories)">
                        🗑️ Hapus Tabel Kategori
                    </button>
                </div>


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
                                    <span class="category-type-badge <?php
                                    if ($category->category_type === 'regular')
                                        echo 'type-regular';
                                    elseif ($category->category_type === 'dynamic_rw')
                                        echo 'type-dynamic';
                                    else
                                        echo 'type-nested';
                                    ?>">
                                        <?php
                                        if ($category->category_type === 'regular')
                                            echo 'Regular';
                                        elseif ($category->category_type === 'dynamic_rw')
                                            echo 'Dynamic RW';
                                        else
                                            echo 'Nested Gender';
                                        ?>
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
                                    <?php elseif ($category->category_type === 'nested_gender'): ?>
                                        <div class="category-fields">
                                            <h4>👥 Nested Gender Fields</h4>
                                            <p style="font-size: 12px; color: #646970; margin: 0;">Field dengan struktur nested untuk
                                                perbandingan gender</p>
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
                                        <?php if ($category->category_type === 'nested_gender'): ?>
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
                                    <option value="nested_gender">Nested Gender - Field dengan perbandingan gender</option>
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

            // Hapus tabel kategori (hanya table wp_statistic_categories)
            function dropCategoriesTable() {
                if (!confirm('Apakah Anda yakin ingin menghapus tabel kategori (wp_statistic_categories)?\n\nSemua kategori dan field terkait mungkin menjadi tidak sinkron. Tindakan ini tidak dapat dibatalkan.')) {
                    return;
                }
                const btns = document.querySelectorAll('.btn-delete');
                btns.forEach(b => b.disabled = true);

                fetch(ajaxurl, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: new URLSearchParams({
                        action: 'drop_categories_table',
                        nonce: '<?php echo wp_create_nonce('drop_categories_table_nonce'); ?>'
                    })
                })
                    .then(res => res.json())
                    .then(data => {
                        if (data.success) {
                            alert('✅ ' + data.data);
                            location.reload();
                        } else {
                            alert('❌ ' + data.data);
                        }
                    })
                    .catch(err => {
                        console.error(err);
                        alert('❌ Terjadi kesalahan saat menghapus tabel kategori.');
                    })
                    .finally(() => {
                        btns.forEach(b => b.disabled = false);
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
     * NEW: Handle drop categories table only
     * Menghapus tabel kategori wp_statistic_categories saja
     */
    public function handle_drop_categories_table()
    {
        // Verify nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'drop_categories_table_nonce')) {
            wp_send_json_error('Nonce verification failed.');
            return;
        }

        // Check permission
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized access.');
            return;
        }

        global $wpdb;
        $table = $this->categories_table; // e.g., wp_statistic_categories

        $result = $wpdb->query("DROP TABLE IF EXISTS {$table}");

        if ($result === false) {
            wp_send_json_error('Gagal menghapus tabel kategori: ' . $wpdb->last_error);
        } else {
            wp_send_json_success('Tabel kategori berhasil dihapus.');
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

        if ($category_info->category_type === 'nested_gender') {
            $html .= $this->generate_nested_gender_fields($category, $existing_data);
        } elseif ($category_info->category_type === 'dynamic_rw') {
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
     * Handle delete action
     * Menangani penghapusan data statistik
     */
    public function handle_delete()
    {
        // Verifikasi nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'statistic_delete_nonce')) {
            wp_send_json_error('Nonce verification failed.');
        }

        // Cek permission
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized access.');
        }

        global $wpdb;

        $year = intval($_POST['year'] ?? 0);
        $category = sanitize_text_field($_POST['category'] ?? '');

        if (empty($year) || empty($category)) {
            wp_send_json_error('Parameter tidak valid.');
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
     * Shortcode for displaying statistics (UPDATED for nested gender)
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
     * Render public display (UPDATED for nested gender)
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

            echo '<div class="card mb-3">';
            echo '<div class="card-body">';
            echo '<div class="d-flex justify-content-between align-items-center mb-2">';
            echo '<h5 class="mb-0">' . esc_html($category_name);
            if ($atts['show_year'] === 'true') { echo '<small class="text-muted ms-2">' . esc_html($row->year) . '</small>'; }
            echo '</h5>';
            echo '</div>';

            // Check category type and display accordingly
            if ($this->is_nested_gender_category($row->category)) {
                // Display simplified total-only grid for nested categories
                $this->render_nested_gender_display($row->category, $data);
            } elseif ($this->is_dynamic_rw_category($row->category)) {
                // Display RW data as compact boxes
                echo '<div class="simple-total-grid">';
                foreach ($data as $key => $value) {
                    if (strpos($key, 'rw_') === 0) {
                        $rw_number = str_replace('rw_', '', $key);
                        echo '<div class="simple-total-box">';
                        echo '<div class="box-title">RW ' . esc_html($rw_number) . '</div>';
                        echo '<div class="box-value">' . number_format(intval($value)) . '</div>';
                        echo '</div>';
                    }
                }
                echo '</div>';
            } else {
                // Display regular category data as compact boxes
                echo '<div class="simple-total-grid">';
                foreach ($data as $key => $value) {
                    $field_label = $this->get_category_fields()[$row->category][$key] ?? $key;
                    echo '<div class="simple-total-box">';
                    echo '<div class="box-title">' . esc_html($field_label) . '</div>';
                    echo '<div class="box-value">' . number_format(intval($value)) . '</div>';
                    echo '</div>';
                }
                echo '</div>';
            }

            // CSS util untuk grid kotak sederhana
            echo '<style>
                .simple-total-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(140px, 1fr)); gap: 12px; }
                .simple-total-box { border: 1px solid #e5e7eb; border-radius: 8px; background: #fff; padding: 12px; text-align: center; box-shadow: 0 1px 2px rgba(0,0,0,0.04); }
                .simple-total-box .box-title { font-size: 12px; color: #6b7280; margin-bottom: 6px; line-height: 1.2; min-height: 28px; }
                .simple-total-box .box-value { font-size: 20px; font-weight: 700; color: #111827; }
                @media (max-width: 576px) { .simple-total-grid { gap: 10px; } .simple-total-box { padding: 10px; } .simple-total-box .box-value { font-size: 18px; } }
            </style>';

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
            if ($this->is_nested_gender_category($row->category)) {
                echo '<tr>';
                echo '<th rowspan="2" style="vertical-align: middle; text-align:center; width:46px;">#</th>';
                echo '<th rowspan="2" style="vertical-align: middle;">Kelompok</th>';
                echo '<th colspan="2" style="text-align:center;">Jumlah</th>';
                echo '<th colspan="2" style="text-align:center;">Laki-Laki</th>';
                echo '<th colspan="2" style="text-align:center;">Perempuan</th>';
                echo '</tr>';
                echo '<tr>';
                echo '<th style="text-align:center;">Sub Total</th><th style="text-align:center;">%</th>';
                echo '<th style="text-align:center;">Sub Total</th><th style="text-align:center;">%</th>';
                echo '<th style="text-align:center;">Sub Total</th><th style="text-align:center;">%</th>';
                echo '</tr>';
            } else {
                echo '<tr>';
                echo '<th style="background-color: #f8f9fa; font-weight: 600;">Kategori</th>';
                echo '<th style="background-color: #f8f9fa; font-weight: 600;">Jumlah</th>';
                echo '</tr>';
            }
            echo '</thead>';
            echo '<tbody>';

            // Display data based on category type
            if ($this->is_nested_gender_category($row->category)) {
                // For nested gender categories, show gender comparison data
                $nested_structure = $this->get_nested_gender_structure();
                if (isset($nested_structure[$row->category])) {
                    // Hitung total keseluruhan untuk persentase
                    $sum_total = 0;
                    $grouped_data = array();
                    foreach ($data as $key => $value) {
                        $parsed = $this->split_nested_gender_key($key);
                        $main_key = $parsed['main_key'];
                        $gender = $parsed['gender'];
                        if (!isset($grouped_data[$main_key])) { $grouped_data[$main_key] = array('laki_laki' => 0, 'perempuan' => 0); }
                        if ($gender === 'laki_laki' || $gender === 'perempuan') {
                            $grouped_data[$main_key][$gender] = intval($value);
                        }
                    }
                    $sum_male_total = 0; $sum_female_total = 0;
                    foreach ($grouped_data as $g) { $sum_total += ($g['laki_laki'] + $g['perempuan']); $sum_male_total += $g['laki_laki']; $sum_female_total += $g['perempuan']; }

                    $row_no = 1;
                    foreach ($nested_structure[$row->category] as $main_key => $gender_labels) {
                        if (!isset($grouped_data[$main_key])) continue;

                        $main_name = explode(' - ', reset($gender_labels))[0];
                        $laki_laki = $grouped_data[$main_key]['laki_laki'] ?? 0;
                        $perempuan = $grouped_data[$main_key]['perempuan'] ?? 0;
                        $total = $laki_laki + $perempuan;
                        $pct_total = $sum_total > 0 ? round(($total / $sum_total) * 100, 2) : 0;
                        $pct_male = $sum_total > 0 ? round(($laki_laki / $sum_total) * 100, 2) : 0;
                        $pct_female = $sum_total > 0 ? round(($perempuan / $sum_total) * 100, 2) : 0;

                        echo '<tr>';
                        echo '<td style="text-align:center;">' . $row_no++ . '</td>';
                        echo '<td>' . esc_html($main_name) . '</td>';
                        echo '<td style="text-align:right;">' . esc_html($total) . '</td>';
                        echo '<td style="text-align:right;">' . esc_html(number_format($pct_total, 2)) . '%</td>';
                        echo '<td style="text-align:right;">' . esc_html($laki_laki) . '</td>';
                        echo '<td style="text-align:right;">' . esc_html(number_format($pct_male, 2)) . '%</td>';
                        echo '<td style="text-align:right;">' . esc_html($perempuan) . '</td>';
                        echo '<td style="text-align:right;">' . esc_html(number_format($pct_female, 2)) . '%</td>';
                        echo '</tr>';
                    }

                    // Baris total ringkas
                    echo '<tr style="font-weight:bold; background:#fff8e1;">';
                    echo '<td colspan="2" style="text-align:right;">JUMLAH</td>';
                    echo '<td style="text-align:right;">' . esc_html($sum_total) . '</td>';
                    echo '<td style="text-align:right;">100.00%</td>';
                    echo '<td style="text-align:right;">' . esc_html($sum_male_total) . '</td>';
                    echo '<td style="text-align:right;">' . ($sum_total>0 ? esc_html(number_format(($sum_male_total/$sum_total)*100,2)) : '0.00') . '%</td>';
                    echo '<td style="text-align:right;">' . esc_html($sum_female_total) . '</td>';
                    echo '<td style="text-align:right;">' . ($sum_total>0 ? esc_html(number_format(($sum_female_total/$sum_total)*100,2)) : '0.00') . '%</td>';
                    echo '</tr>';

                    echo '<tr style="font-weight:bold; background:#f1f3f5;">';
                    echo '<td colspan="2" style="text-align:right;">TOTAL</td>';
                    echo '<td style="text-align:right;">' . esc_html($sum_total) . '</td>';
                    echo '<td style="text-align:right;">100.00%</td>';
                    echo '<td style="text-align:right;">' . esc_html($sum_male_total) . '</td>';
                    echo '<td style="text-align:right;">' . ($sum_total>0 ? esc_html(number_format(($sum_male_total/$sum_total)*100,2)) : '0.00') . '%</td>';
                    echo '<td style="text-align:right;">' . esc_html($sum_female_total) . '</td>';
                    echo '<td style="text-align:right;">' . ($sum_total>0 ? esc_html(number_format(($sum_female_total/$sum_total)*100,2)) : '0.00') . '%</td>';
                    echo '</tr>';
                }
            } elseif ($this->is_dynamic_rw_category($row->category)) {
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
            .statistic-table-wrapper .table thead th { text-align: center; }
            .statistic-table-wrapper .table td:nth-child(3),
            .statistic-table-wrapper .table td:nth-child(4),
            .statistic-table-wrapper .table td:nth-child(5),
            .statistic-table-wrapper .table td:nth-child(6),
            .statistic-table-wrapper .table td:nth-child(7),
            .statistic-table-wrapper .table td:nth-child(8) { text-align: right; }
            
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
        $datasets = array();

        if ($this->is_nested_gender_category($result->category)) {
            // Build grouped datasets for Laki-laki and Perempuan
            $nested_structure = $this->get_nested_gender_structure();
            $male_values = array();
            $female_values = array();
            if (isset($nested_structure[$result->category])) {
                foreach ($nested_structure[$result->category] as $main_key => $gender_labels) {
                    $main_name = explode(' - ', reset($gender_labels))[0];
                    $labels[] = $main_name;
                    $male_key = $main_key . '_laki_laki';
                    $female_key = $main_key . '_perempuan';
                    $male_values[] = isset($data[$male_key]) ? intval($data[$male_key]) : 0;
                    $female_values[] = isset($data[$female_key]) ? intval($data[$female_key]) : 0;
                }
            }
            $datasets[] = array(
                'label' => 'Laki-laki',
                'data' => $male_values,
                'backgroundColor' => 'rgba(52, 152, 219, 0.8)',
                'borderColor' => 'rgba(52, 152, 219, 1)',
                'borderWidth' => 2,
                'borderRadius' => 4,
                'borderSkipped' => false,
            );
            $datasets[] = array(
                'label' => 'Perempuan',
                'data' => $female_values,
                'backgroundColor' => 'rgba(88, 83, 201, 0.8)',
                'borderColor' => 'rgba(88, 83, 201, 1)',
                'borderWidth' => 2,
                'borderRadius' => 4,
                'borderSkipped' => false,
            );
        } elseif ($this->is_dynamic_rw_category($result->category)) {
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

        // Warna default untuk dataset tunggal (kategori non-nested)
        $background_colors = array(
            'rgba(52, 152, 219, 0.8)',
            'rgba(88, 83, 201, 1)',
            'rgba(46, 204, 113, 0.8)',
            'rgba(241, 196, 15, 0.8)',
            'rgba(231, 76, 60, 0.8)',
            'rgba(230, 126, 34, 0.8)',
            'rgba(155, 89, 182, 0.8)',
            'rgba(52, 73, 94, 0.8)',
            'rgba(26, 188, 156, 0.8)',
            'rgba(243, 156, 18, 0.8)'
        );

        $border_colors = array(
            'rgba(52, 152, 219, 1)',
            'rgba(88, 83, 201, 1)',
            'rgba(46, 204, 113, 1)',
            'rgba(241, 196, 15, 1)',
            'rgba(231, 76, 60, 1)',
            'rgba(230, 126, 34, 1)',
            'rgba(155, 89, 182, 1)',
            'rgba(52, 73, 94, 1)',
            'rgba(26, 188, 156, 1)',
            'rgba(243, 156, 18, 1)'
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
                        datasets: <?php echo !empty($datasets) ? json_encode($datasets) : json_encode([[
                            'label' => esc_js($category_name),
                            'data' => $values,
                            'backgroundColor' => $background_colors,
                            'borderColor' => $border_colors,
                            'borderWidth' => 2,
                            'borderRadius' => 4,
                            'borderSkipped' => false,
                        ]]); ?>
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
                                position: 'top',
                                labels: {
                                    color: '#2c3e50',
                                    font: { size: 12 }
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
     * Render form input untuk admin - disesuaikan dengan nested gender
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
                    max-width: 800px;
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
                    text-transform: uppercase;
                }

                .status-published {
                    background: #d4edda;
                    color: #155724;
                }

                .status-draft {
                    background: #fff3cd;
                    color: #856404;
                }

                .stats-list {
                    list-style: none;
                    padding: 0;
                    margin: 0;
                }

                .stats-list li {
                    display: flex;
                    justify-content: space-between;
                    align-items: center;
                    padding: 8px 0;
                    border-bottom: 1px solid #ecf0f1;
                }

                .stats-list li:last-child {
                    border-bottom: none;
                }

                .stats-list .label {
                    color: #2c3e50;
                    font-weight: 500;
                }

                .stats-list .value {
                    background: #e9ecef;
                    color: #495057;
                    padding: 3px 8px;
                    border-radius: 12px;
                    font-size: 12px;
                    font-weight: 600;
                }

                .no-data {
                    text-align: center;
                    color: #7f8c8d;
                    font-style: italic;
                    padding: 20px;
                }

                /* Responsive */
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
                <div class="dashboard-header">
                    <h1>📊 Dashboard Statistik Desa/Kelurahan</h1>
                    <p class="dashboard-subtitle">Kelola dan pantau data statistik desa/kelurahan dengan mudah</p>
                    <span class="dashboard-version">v<?php echo $this->version; ?></span>
                </div>

                <div class="stats-grid">
                    <div class="stat-card total">
                        <div class="stat-icon">📈</div>
                        <div class="stat-number"><?php echo number_format($total_records); ?></div>
                        <div class="stat-label">Total Data</div>
                    </div>
                    <div class="stat-card published">
                        <div class="stat-icon">✅</div>
                        <div class="stat-number"><?php echo number_format($published_records); ?></div>
                        <div class="stat-label">Dipublikasi</div>
                    </div>
                    <div class="stat-card draft">
                        <div class="stat-icon">📝</div>
                        <div class="stat-number"><?php echo number_format($draft_records); ?></div>
                        <div class="stat-label">Draft</div>
                    </div>
                    <div class="stat-card years">
                        <div class="stat-icon">📅</div>
                        <div class="stat-number"><?php echo number_format($total_years); ?></div>
                        <div class="stat-label">Tahun Data</div>
                    </div>
                    <div class="stat-card categories">
                        <div class="stat-icon">🏷️</div>
                        <div class="stat-number"><?php echo number_format($total_categories); ?></div>
                        <div class="stat-label">Kategori Terpakai</div>
                    </div>
                    <div class="stat-card custom">
                        <div class="stat-icon">⚙️</div>
                        <div class="stat-number"><?php echo number_format($custom_categories); ?></div>
                        <div class="stat-label">Kategori Custom</div>
                    </div>
                </div>


                <div class="dashboard-content">
                    <div class="main-content">
                        <h2>🚀 Aksi Cepat</h2>
                        <div class="quick-actions">
                            <a href="<?php echo admin_url('admin.php?page=statistic-create'); ?>" class="action-btn new">
                                <span>➕</span>
                                <span>Input Data Baru</span>
                            </a>
                            <a href="<?php echo admin_url('admin.php?page=statistic-list'); ?>" class="action-btn primary">
                                <span>📋</span>
                                <span>Lihat Semua Data</span>
                            </a>
                            <a href="<?php echo admin_url('admin.php?page=statistic-categories'); ?>" class="action-btn">
                                <span>🏷️</span>
                                <span>Kelola Kategori</span>
                            </a>
                            <a href="<?php echo admin_url('admin.php?page=statistic-docs'); ?>" class="action-btn">
                                <span>📖</span>
                                <span>Dokumentasi</span>
                            </a>
                        </div>

                        <h3>📋 Aktivitas Terbaru</h3>
                        <?php if (!empty($recent_records)): ?>
                            <ul class="recent-activity">
                                <?php foreach ($recent_records as $record): ?>
                                    <li>
                                        <div class="activity-info">
                                            <div class="activity-title">
                                                <?php echo esc_html($categories[$record->category] ?? $record->category); ?>
                                            </div>
                                            <div class="activity-meta">
                                                Tahun <?php echo esc_html($record->year); ?> •
                                                <?php echo esc_html(date('d M Y H:i', strtotime($record->updated_at))); ?>
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
                            <div class="no-data">Belum ada data statistik</div>
                        <?php endif; ?>
                    </div>

                    <div class="sidebar-content">

                        <div class="widget">
                            <h3>📅 Statistik per Tahun</h3>
                            <?php if (!empty($year_stats)): ?>
                                <ul class="stats-list">
                                    <?php foreach ($year_stats as $stat): ?>
                                        <li>
                                            <span class="label">Tahun <?php echo esc_html($stat->year); ?></span>
                                            <span class="value"><?php echo esc_html($stat->count); ?> data</span>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php else: ?>
                                <div class="no-data">Belum ada data</div>
                            <?php endif; ?>
                        </div>


                        <div class="widget">
                            <h3>🏷️ Kategori Populer</h3>
                            <?php if (!empty($category_stats)): ?>
                                <ul class="stats-list">
                                    <?php foreach ($category_stats as $stat): ?>
                                        <li>
                                            <span
                                                class="label"><?php echo esc_html($categories[$stat->category] ?? $stat->category); ?></span>
                                            <span class="value"><?php echo esc_html($stat->count); ?> data</span>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php else: ?>
                                <div class="no-data">Belum ada data</div>
                            <?php endif; ?>
                        </div>


                        <div class="widget">
                            <h3>ℹ️ Informasi</h3>
                            <ul class="stats-list">
                                <li>
                                    <span class="label">Plugin Version</span>
                                    <span class="value">v<?php echo $this->version; ?></span>
                                </li>
                                <li>
                                    <span class="label">Database Tables</span>
                                    <span class="value">3 tables</span>
                                </li>
                                <li>
                                    <span class="label">API Endpoints</span>
                                    <span class="value">3 endpoints</span>
                                </li>
                                <li>
                                    <span class="label">Shortcodes</span>
                                    <span class="value">4 shortcodes</span>
                                </li>
                            </ul>
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
                                    // Redirect to Statistik Desa page after successful delete
                                    window.location.href = '<?php echo admin_url('admin.php?page=statistic'); ?>';
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
     * Admin documentation page
     * Halaman dokumentasi untuk shortcode dan API
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
                                <div class="step-number">6</div>
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
                                <div class="step-number">7</div>
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
                            <div class="step-card step-gray">
                                <div class="step-number">8</div>
                                <div class="step-content">
                                    <h3>Mengelola Kategori</h3>
                                    <ul>
                                        <li>Buka menu <strong>Statistik Desa</strong> > <strong>Kelola Kategori</strong> untuk
                                            menambah atau mengedit kategori</li>
                                        <li>Klik tombol <strong>Tambah Kategori Baru</strong> untuk membuat kategori baru</li>
                                        <li>Pilih tipe kategori sesuai kebutuhan: <strong>Regular</strong> (field tetap),
                                            <strong>Dynamic RW</strong> (field RW dinamis), atau <strong>Nested Gender</strong>
                                            (perbandingan gender)</li>
                                        <li>Untuk kategori Regular dan Nested Gender, klik tombol <strong>Fields</strong> untuk
                                            mengelola field yang tersedia</li>
                                        <li>Pastikan menggunakan <strong>kode kategori</strong> yang unik dengan huruf kecil dan
                                            underscore</li>
                                    </ul>
                                </div>
                            </div>

                            <!-- Step 8 -->
                            <div class="step-card step-red">
                                <div class="step-number">9</div>
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
                                <p><strong>⚠️ Penting:</strong> Shortcode ini hanya akan ditampilkan untuk user yang memiliki
                                    hak akses admin.</p>
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
                                    <li><code>?published=false</code> - Tampilkan semua data (termasuk yang tidak dipublikasi)
                                    </li>
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
                        transition: all .3s ease;
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
                        letter-spacing: .5px;
                    }

                    .badge-primary {
                        background: #007bff;
                        color: #fff;
                    }

                    .badge-success {
                        background: #28a745;
                        color: #fff;
                    }

                    .badge-warning {
                        background: #ffc107;
                        color: #212529;
                    }

                    .badge-danger {
                        background: #dc3545;
                        color: #fff;
                    }

                    .badge-info {
                        background: #17a2b8;
                        color: #fff;
                    }

                    .tutorial-steps {
                        margin-bottom: 40px;
                    }

                    .step-card {
                        display: flex;
                        margin-bottom: 20px;
                        background: #fff;
                        border-radius: 8px;
                        box-shadow: 0 2px 8px rgba(0, 0, 0, .1);
                        overflow: hidden;
                        border-left: 4px solid;
                    }

                    .step-blue {
                        border-left-color: #007bff
                    }

                    .step-green {
                        border-left-color: #28a745
                    }

                    .step-yellow {
                        border-left-color: #ffc107
                    }

                    .step-purple {
                        border-left-color: #6f42c1
                    }

                    .step-indigo {
                        border-left-color: #6610f2
                    }

                    .step-gray {
                        border-left-color: #6c757d
                    }

                    .step-red {
                        border-left-color: #dc3545
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

                    .input-type-card li {
                        margin-bottom: 5px;
                        color: #6c757d;
                        font-size: 14px;
                    }

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
                        background: #fff;
                        padding: 15px;
                        border-radius: 6px;
                        border-left: 4px solid;
                        box-shadow: 0 2px 4px rgba(0, 0, 0, .1);
                    }

                    .tip-blue {
                        border-left-color: #007bff
                    }

                    .tip-green {
                        border-left-color: #28a745
                    }

                    .tip-yellow {
                        border-left-color: #ffc107
                    }

                    .tip-purple {
                        border-left-color: #6f42c1
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
                        background: #fff;
                        padding: 15px;
                        border-radius: 6px;
                        border-left: 4px solid #dc3545;
                        box-shadow: 0 2px 4px rgba(0, 0, 0, .1);
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

                    .shortcode-card {
                        background: #fff;
                        border: 1px solid #e9ecef;
                        border-radius: 8px;
                        margin-bottom: 25px;
                        overflow: hidden;
                        box-shadow: 0 2px 4px rgba(0, 0, 0, .1);
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
                        color: #fff;
                        border: none;
                        padding: 6px 10px;
                        border-radius: 4px;
                        cursor: pointer;
                        font-size: 11px;
                        font-weight: 500;
                        transition: background .3s;
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
                        color: #856404;
                        font-size: 14px;
                    }

                    .api-endpoints {
                        margin-bottom: 30px;
                    }

                    .endpoint-card {
                        background: #fff;
                        border: 1px solid #e9ecef;
                        border-radius: 8px;
                        padding: 20px;
                        margin-bottom: 20px;
                        box-shadow: 0 2px 4px rgba(0, 0, 0, .1);
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
                        background: #fff;
                        border-radius: 6px;
                        overflow: hidden;
                        box-shadow: 0 2px 4px rgba(0, 0, 0, .1);
                    }

                    .categories-table th {
                        background: #495057;
                        color: #fff;
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

                    .example-card {
                        background: #fff;
                        border: 1px solid #e9ecef;
                        border-radius: 8px;
                        padding: 20px;
                        margin-bottom: 25px;
                        box-shadow: 0 2px 4px rgba(0, 0, 0, .1);
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

                    @media (max-width:768px) {
                        .doc-header {
                            padding: 20px
                        }

                        .doc-header h1 {
                            font-size: 24px
                        }

                        .doc-subtitle {
                            font-size: 14px
                        }

                        .tab-content {
                            padding: 20px
                        }

                        .section-header {
                            flex-direction: column;
                            align-items: flex-start;
                            gap: 10px
                        }

                        .step-card {
                            flex-direction: column
                        }

                        .step-number {
                            width: 100%;
                            height: 50px
                        }

                        .category-grid {
                            grid-template-columns: 1fr
                        }

                        .tips-grid {
                            grid-template-columns: 1fr
                        }

                        .code-block {
                            margin: 15px 0;
                            font-size: 12px
                        }

                        .copy-btn {
                            position: static;
                            margin-top: 10px;
                            width: 100%
                        }

                        .parameters-table {
                            overflow-x: auto
                        }

                        .categories-table {
                            overflow-x: auto
                        }
                    }
                </style>

                <script>
                    // Tab functionality
                    function showTab(tabName) {
                        const tabContents = document.querySelectorAll('.tab-content');
                        tabContents.forEach(content => content.classList.remove('active'));
                        const tabButtons = document.querySelectorAll('.tab-button');
                        tabButtons.forEach(button => button.classList.remove('active'));
                        document.getElementById(tabName + '-tab').classList.add('active');
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
     * Enqueue frontend scripts and styles
     * Memuat CSS dan JS untuk frontend
     */
    public function enqueue_frontend_scripts()
    {
        // Enqueue Bootstrap CSS untuk styling yang lebih baik
        wp_enqueue_style('chartjs', 'https://cdn.jsdelivr.net/npm/chart.js', array(), null, true);

        // Enqueue custom CSS
        wp_add_inline_style('bootstrap', '
            .statistic-display .card { margin-bottom: 1rem; }
            .statistic-display .card-header { background-color: #f8f9fa; }
            .statistic-display .display-6 { font-size: 1.25rem; font-weight: 600; }
            .table-responsive { margin-top: 1rem; }
            .badge { margin-right: 0.25rem; margin-bottom: 0.25rem; }
        ');

        // Enqueue AJAX script
        wp_enqueue_script('jquery');
        wp_localize_script('jquery', 'statistic_ajax', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('statistic_nonce_action')
        ));
    }

    /**
     * Enqueue admin scripts and styles
     * Memuat CSS dan JS untuk halaman admin
     */
    public function enqueue_admin_scripts($hook)
    {
        // Hanya load di halaman plugin
        if (strpos($hook, 'statistic') === false) {
            return;
        }

        wp_enqueue_script('jquery');
        wp_localize_script('jquery', 'statistic_ajax', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('statistic_nonce_action')
        ));

        // Add custom admin CSS
        wp_add_inline_style('wp-admin', '
            .statistic-form-container {
                background: #fff;
                padding: 20px;
                border-radius: 8px;
                box-shadow: 0 1px 3px rgba(0,0,0,0.1);
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
                padding: 15px;
                margin-bottom: 10px;
                display: flex;
                align-items: center;
                gap: 15px;
            }
            
            .rw-content {
                flex: 1;
            }
            
            .rw-header {
                font-weight: 600;
                color: #495057;
                margin-bottom: 8px;
            }
            
            .btn-success, .btn-danger {
                padding: 8px 15px;
                border: none;
                border-radius: 4px;
                cursor: pointer;
                font-size: 13px;
                font-weight: 500;
            }
            
            .btn-success {
                background: #28a745;
                color: white;
            }
            
            .btn-success:hover {
                background: #218838;
            }
            
            .btn-danger {
                background: #dc3545;
                color: white;
            }
            
            .btn-danger:hover {
                background: #c82333;
            }
        ');
    }
}

// Initialize the plugin
new StatisticPlugin();
?>