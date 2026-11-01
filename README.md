# Aplikasi Kasir Digital Warung Kelontong

Kelompok Firewall — Integrasi Sistem dan Komputasi Awan (25IF1504)

Repository ini berisi kontrak API, kontrak event, serta implementasi prototipe integrasi POS, Inventory, dan Procurement Export.

## Status checkpoint

- [x] Analisis integrasi tersimpan di repository
- [x] Kontrak OpenAPI awal
- [x] Kontrak event `SaleRecorded` v1
- [ ] POS & Order Service Laravel lengkap
- [ ] RabbitMQ aktif dan dibuktikan melalui demo
- [ ] Feature test dijalankan di lingkungan pengembangan

> Status di atas harus diperbarui berdasarkan bukti commit dan hasil eksekusi, bukan rencana.

## Arsitektur

```text
WhatsApp Gateway -- REST/JSON --> POS & Order Service
                                      |
                                      +-- RabbitMQ retail.events / sale.recorded --> Inventory Service
                                                                                         |
                                                                                         +--> Procurement Export (CSV/PDF draft)
```

### Batas layanan

- **POS & Order Service**: sumber transaksi, snapshot harga/kuantitas, pembayaran, utang, dan penerimaan order.
- **Inventory Service**: sumber saldo dan pergerakan stok; consumer event `SaleRecorded`.
- **Procurement Export Service**: menyusun draf kulakan dari stok di bawah minimum dan mengekspor dokumen untuk tinjauan manusia.

POS tidak menulis langsung ke tabel inventory. RabbitMQ adalah infrastruktur, bukan layanan bisnis.

## Kontrak API

OpenAPI tersedia di [`openapi.yaml`](openapi.yaml). Prefix API adalah `/api/v1`, waktu menggunakan ISO 8601 UTC, dan nominal uang menggunakan integer rupiah.

Endpoint minimum:

- `POST /api/v1/sales` — mencatat penjualan (`201 Created`)
- `POST /api/v1/orders/whatsapp` — menerima order gateway (`202 Accepted`)
- `GET /api/v1/sales/{saleId}` — membaca transaksi
- `GET /api/v1/health` — pemeriksaan kesiapan

Kesalahan menggunakan `application/problem+json` sesuai RFC 9457. Validasi `quantity` harus menolak nilai nol atau negatif dengan status `422`.

## Kontrak event dan RabbitMQ

- Exchange: `retail.events`
- Tipe: topic, durable
- Routing key: `sale.recorded`
- Queue consumer: `inventory.sale-recorded`
- Delivery: at-least-once
- Consumer: idempotent berdasarkan `eventId`
- ACK: sesudah perubahan stok tersimpan
- Retry terbatas dan DLQ wajib didokumentasikan saat consumer diaktifkan

Kontrak event ada di [`contracts/events/sale-recorded.v1.json`](contracts/events/sale-recorded.v1.json).

## Menjalankan prototipe

Bagian ini akan dilengkapi bersama implementasi service. Contoh konfigurasi lokal tidak memuat rahasia:

```bash
cp .env.example .env
docker compose up -d rabbitmq
```

Cek RabbitMQ Management di `http://localhost:15672` dengan kredensial lokal yang didefinisikan di `docker-compose.yml`. Jangan commit token, password produksi, atau data pelanggan.

## Pengujian yang harus dibuktikan

1. Penjualan valid menghasilkan `201`, tersimpan, dan menerbitkan `SaleRecorded`.
2. Quantity nol/negatif menghasilkan Problem Details `422` dan tidak membuat transaksi.
3. Pesan WhatsApp yang sama tidak menggandakan order.
4. Event yang dikirim ulang hanya mengurangi stok satu kali.
5. Kegagalan consumer tidak menghapus transaksi; event dapat diproses ulang melalui retry/DLQ.
6. Stok di bawah minimum menghasilkan draf kulakan dengan waktu pembuatan.

> Pada versi ini, status implementasi dan hasil test harus dibaca dari commit/CI aktual. Dokumen analisis tidak dianggap sebagai bukti layanan sudah berjalan.

## Keputusan yang masih terbuka

- Verifikasi signature dan format webhook WhatsApp.
- Kebijakan stok negatif/reservasi.
- Katalog produk dan satuan dasar/pembelian.
- Format distributor.
- Transactional outbox dan prosedur replay.
- Data volume, backup, dan konektivitas mitra.

## Struktur repository

```text
README.md
openapi.yaml
docker-compose.yml
.env.example
contracts/events/sale-recorded.v1.json
app/                 # service POS Laravel (bertahap)
services/inventory/  # consumer inventory (bertahap)
services/procurement/# export kulakan (bertahap)
docs/analysis/       # bahan analisis
```

## Anggota

- Hafizh Zidan Handrian (1224002)
- Lutfiana Faisal Patah (3224018)
- Muhammad Irfan Abdul Aziz Ramdani (1224014)
- Zaky Permana Sidiq (1224015)

Pembagian kontribusi harus disesuaikan dengan riwayat commit aktual.
