# Aplikasi Kasir Digital Warung Kelontong

Kelompok Firewall — Integrasi Sistem dan Komputasi Awan (25IF1504)

Repository ini berisi kontrak API, kontrak event, serta implementasi prototipe integrasi POS, Inventory, dan Procurement Export.

## Status checkpoint

Status berikut mencerminkan implementasi yang ada di branch `main` dan bukti eksekusi CI, bukan sekadar rencana:

- [x] Analisis integrasi tersimpan di repository
- [x] Kontrak OpenAPI awal
- [x] Kontrak event `SaleRecorded` v1
- [x] POS HTTP prototype: health check, pencatatan penjualan, validasi, dan penerimaan order WhatsApp idempotent
- [x] Integrasi 1: POS → Inventory melalui event `SaleRecorded`, dengan consumer idempotent
- [x] Integrasi 2: Inventory → Procurement Export berupa draf CSV stok di bawah minimum
- [x] Contract smoke dan HTTP integration smoke dijalankan oleh GitHub Actions
- [ ] Integrasi 3: WhatsApp Gateway → POS secara end-to-end; ditunda ke roadmap berikutnya
- [ ] RabbitMQ consumer aktif dan dibuktikan melalui demo runtime
- [ ] Laravel service lengkap dan feature test native Laravel

Bukti CI saat ini mencakup health `200`, penjualan valid `201`, quantity nol `422`, idempotensi order WhatsApp, pemrosesan event ke inventory, dan pembuatan draf procurement CSV. Implementasi checkpoint menggunakan file event lokal sebagai prototipe deterministik; RabbitMQ sudah disediakan melalui `docker-compose.yml`, tetapi belum menjadi jalur eksekusi consumer pada checkpoint ini.

## Arsitektur

```text
WhatsApp Gateway -- REST/JSON --> POS & Order Service       (roadmap end-to-end)
                                      |
                                      +-- SaleRecorded event --> Inventory Service
                                                                    |
                                                                    +--> Procurement Export (CSV draft)
```

### Batas layanan

- **POS & Order Service**: sumber transaksi, snapshot harga/kuantitas, pembayaran, utang, dan penerimaan order.
- **Inventory Service**: sumber saldo dan pergerakan stok; consumer event `SaleRecorded`.
- **Procurement Export Service**: menyusun draf kulakan dari stok di bawah minimum dan mengekspor dokumen untuk tinjauan manusia.

POS tidak menulis langsung ke tabel inventory. Pada prototipe checkpoint, event disimpan sebagai NDJSON agar alur dapat diuji secara deterministik. RabbitMQ adalah infrastruktur yang disiapkan untuk tahap berikutnya, bukan klaim bahwa broker sudah dipakai oleh consumer.

## Kontrak API

OpenAPI tersedia di [`openapi.yaml`](openapi.yaml). Prefix API adalah `/api/v1`, waktu menggunakan ISO 8601 UTC, dan nominal uang menggunakan integer rupiah.

Endpoint minimum:

- `POST /api/v1/sales` — mencatat penjualan (`201 Created`)
- `POST /api/v1/orders/whatsapp` — menerima order gateway (`202 Accepted`)
- `GET /api/v1/sales/{saleId}` — membaca transaksi
- `GET /api/v1/health` — pemeriksaan kesiapan

Kesalahan menggunakan `application/problem+json` sesuai RFC 9457. Validasi `quantity` menolak nilai nol atau negatif dengan status `422`.

## Kontrak event dan RabbitMQ

- Exchange: `retail.events`
- Tipe: topic, durable
- Routing key: `sale.recorded`
- Queue consumer: `inventory.sale-recorded`
- Delivery: at-least-once
- Consumer: idempotent berdasarkan `eventId`
- ACK: sesudah perubahan stok tersimpan
- Retry terbatas dan DLQ wajib didokumentasikan saat consumer RabbitMQ diaktifkan

Kontrak event ada di [`contracts/events/sale-recorded.v1.json`](contracts/events/sale-recorded.v1.json). Implementasi checkpoint memvalidasi kontrak dan menjalankan consumer melalui [`services/inventory/consumer.php`](services/inventory/consumer.php) menggunakan file event lokal.

## Menjalankan prototipe

Bagian ini menggunakan PHP CLI dan tidak memerlukan rahasia produksi:

```bash
rm -rf app/storage
php -S 127.0.0.1:8080 -t app/public app/public/index.php
```

Jalankan smoke contract dan integration flow:

```bash
php tests/contract-smoke.php
php services/inventory/consumer.php
php services/procurement/export.php
```

RabbitMQ tersedia untuk tahap lanjutan:

```bash
docker compose up -d rabbitmq
```

Cek RabbitMQ Management di `http://localhost:15672` dengan kredensial lokal yang didefinisikan di `docker-compose.yml`. Jangan commit token, password produksi, atau data pelanggan.

## Pengujian yang dibuktikan

1. Penjualan valid menghasilkan `201`, tersimpan, dan menerbitkan `SaleRecorded`.
2. Quantity nol/negatif menghasilkan Problem Details `422` dan tidak membuat transaksi.
3. Pesan WhatsApp dengan idempotency key yang sama tidak menggandakan order.
4. Event yang dikirim ulang hanya mengurangi stok satu kali.
5. Stok di bawah minimum menghasilkan draf kulakan CSV dengan waktu pembuatan.
6. Workflow GitHub Actions menjalankan contract smoke dan alur integrasi POS → Inventory → Procurement.

## Roadmap integrasi ketiga

Integrasi WhatsApp Gateway → POS ditunda setelah checkpoint ini. Tahap berikutnya perlu mencakup verifikasi signature webhook, pemetaan payload gateway ke kontrak `POST /api/v1/orders/whatsapp`, retry dengan idempotency key, observability, dan uji end-to-end terhadap gateway sandbox.

## Keputusan yang masih terbuka

- Verifikasi signature dan format webhook WhatsApp.
- Kebijakan stok negatif/reservasi.
- Katalog produk dan satuan dasar/pembelian.
- Format distributor.
- Transactional outbox dan prosedur replay.
- Data volume, backup, dan konektivitas mitra.
- Binding consumer ke RabbitMQ, retry policy, dan DLQ.

## Struktur repository

```text
README.md
openapi.yaml
docker-compose.yml
.env.example
contracts/events/sale-recorded.v1.json
app/                 # service POS prototype
services/inventory/  # consumer inventory berbasis event lokal
services/procurement/# export kulakan
 tests/               # contract dan HTTP smoke checks
```

## Anggota

- Hafizh Zidan Handrian (1224002)
- Lutfiana Faisal Patah (3224018)
- Muhammad Irfan Abdul Aziz Ramdani (1224014)
- Zaky Permana Sidiq (1224015)

Pembagian kontribusi harus disesuaikan dengan riwayat commit aktual.
