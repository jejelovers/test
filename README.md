- all nested tuh yg paling baru sudah dinamis kategori dan nested field
- before versi yg ga ada dinamis kategori tapi dokumentasinya bagus yg ini
- statistic doank yg baru dinmis kategori yg kemas

Cara tambah kategori baru (ringkas):
- Buka menu "Statistik Desa" → "Kelola Kategori & Field"
- Klik "Tambah Kategori", isi Kode, Nama, dan Tipe (regular/dynamic_rw/nested_gender)
- Simpan. Kategori muncul di form input. Kelola field jika tipe regular/nested_gender

Contoh shortcode (disarankan):
- [statistic_display year="2024" category="agama" show_source="true" show_year="true"]
- [statistic_table year="2024" limit="10" show_source="true"]
- [statistic_chart year="2024" category="jenis_kelamin" type="bar" height="360"]
