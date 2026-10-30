# SIP Manager

[![Tests](https://github.com/soap/sip-manager-platform/actions/workflows/tests.yml/badge.svg?branch=main)](https://github.com/soap/sip-manager-platform/actions/workflows/tests.yml)
[![Code Style](https://github.com/soap/sip-manager-platform/actions/workflows/lint.yml/badge.svg?branch=main)](https://github.com/soap/sip-manager-platform/actions/workflows/lint.yml)
![PHP](https://img.shields.io/badge/PHP-8.4%20%7C%208.5-777BB4?logo=php&logoColor=white)
![Laravel](https://img.shields.io/badge/Laravel-13-FF2D20?logo=laravel&logoColor=white)

Telephony Management App สำหรับ VoIP แบบหลาย tenant บน OpenSIPS 3.6 + Asterisk 22 — เป็น control plane ที่เก็บ desired state (tenant, extension, ring group, voicemail, สิทธิ์การโทร) ใน PostgreSQL แล้ว compile และเขียนลงฐานข้อมูลของแต่ละ PBX node พร้อมตรวจผลกลับ (read-back)

- **SIP Deployer** (อีกแอปหนึ่ง) เป็นผู้ติดตั้งและดูแล node — OpenSIPS, Asterisk, `opensips.cfg`, dialplan หลัก; แอปนี้ดึงรายการ node และ credentials ผ่าน API ของ SIP Deployer
- แอปนี้เชื่อมต่อ node ผ่าน MariaDB (`opensips`, `asterisk`), AMI และ ARI เท่านั้น — **ไม่มี SSH และไม่เขียนไฟล์บน node**

## ความสามารถ

| ส่วน                | รายละเอียด                                                                                                                                                                                                                                  |
| ------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Tenant              | หนึ่ง SIP domain ต่อ tenant (`<slug>.<SIP_TENANT_DOMAIN_SUFFIX>`) + `tenant_code` 4 หลัก, สมาชิก owner/admin/member, audit log, suspend                                                                                                     |
| Nodes               | sync จาก SIP Deployer ทุก 5 นาที, เก็บ credentials แบบเข้ารหัส, ทดสอบการเชื่อมต่อ DB/AMI/ARI, assign tenant ไปยัง node                                                                                                                      |
| Extensions          | เลข 2–5 หลัก (ซ้ำกันได้ระหว่าง tenant), รหัสผ่าน SIP แสดงครั้งเดียว + หมุนรหัส, ปิด/เปิด (ปิด = `403 Account disabled` ไม่โดน fail2ban), สถานะ register จาก OpenSIPS                                                                        |
| Voicemail           | กล่องต่อ extension, PIN แสดงครั้งเดียว, `*97` เข้าฟังข้อความ                                                                                                                                                                                |
| Ring groups         | `ring_all` / `sequential`, สูงสุด 10 คน, failover ไป extension / ring group / voicemail / hangup                                                                                                                                            |
| Calling permissions | calling profiles (หมวดปลายทาง × `allow`/`code`/`deny`), รหัสอนุญาตของ tenant (overrule ด้วยรหัสผ่าน), โทรข้าม tenant ด้วย `8<code><ext>` เมื่อปลายทางยอมรับ                                                                                 |
| Provisioning        | transactional outbox → queue `provisioning` → compile → apply (Asterisk ก่อน OpenSIPS) → read-back checksum + AMI; retry แบบ backoff; แจ้งเตือนเมื่อค้างเกิน 30 วินาที                                                                      |
| Operations          | หน้า Operations ของ tenant และของ platform admin (`/admin/operations`) — ดูสถานะการเปลี่ยนแปลงที่กำลังไปถึง PBX/ที่ล้มเหลว พร้อม error, retry เฉพาะรายการล่าสุดที่ failed                                                                   |
| Reconciliation      | `nodes:reconcile` เทียบ desired state กับแถวจริงบน node — missing/changed สั่ง provision ใหม่ (type `reconciled`), unexpected รายงานอย่างเดียว; drift report ในการ์ด node + ปุ่ม Reconcile now                                              |
| Call history (CDR)  | `cdr:sync` อ่านตาราง `cdr` ของ Asterisk บนแต่ละ node → `call_records` แยก tenant ทั้งฝั่งโทรและรับ, status answered/no answer/busy/failed/denied, ผูกรหัสอนุญาตที่ใช้; หน้า Call history ของ tenant, สถานะ sync ในการ์ด node, เก็บ 13 เดือน |
| API v1              | Sanctum token ผูกกับ tenant, Idempotency-Key บน POST, optimistic lock (`version`/`If-Match`) บน PATCH, `202` + `operation_id` สำหรับการเปลี่ยนที่ไปถึง PBX                                                                                  |

ยังไม่มี: AMI listener / call events, registration snapshots, dashboard และ alerts (ส่วนที่เหลือของ Phase 3), `call-records` ใน API, trunk และโทรออก PSTN (Phase 4) — ดูสถานะล่าสุดใน [docs/management-app-plan.md §12](docs/management-app-plan.md)

## Stack

- PHP 8.4+ / Laravel 13, Fortify (2FA, email verification; ปิด registration), Sanctum 4
- Inertia 3 + Vue 3 + TypeScript, Tailwind 4, Wayfinder, Vite+ (`vp`)
- PostgreSQL (แอป), MariaDB บน node (ผ่าน connection ต่อ node ที่สร้างตอนใช้งาน)
- Pest 4, Larastan, Pint, Laravel Boost

## เริ่มต้นใช้งาน (development)

ต้องมี PHP 8.4+ พร้อม `pdo_pgsql` และ `pdo_mysql`, Composer, Node 22, PostgreSQL

```bash
cp .env.example .env          # แก้ DB_*, SIP_DEPLOYER_URL, SIP_DEPLOYER_TOKEN, SIP_TENANT_DOMAIN_SUFFIX
composer setup                # install, key:generate, migrate, npm install, build
php artisan app:create-platform-admin admin@example.com "Admin"   # ถามรหัสผ่าน (≥ 12 ตัว) หรือส่ง --password=
composer dev                  # server :8080 + queue (provisioning,default) + scheduler + vite
```

เปิด `http://localhost:8080` (ตั้ง `APP_URL` ให้ตรง — `.env.example` ยังเป็น `:8000`) แล้วเข้าสู่ระบบ; หน้า `/admin/*` ต้องเปิด 2FA ใน Settings → Security ก่อน — สร้าง tenant ที่ `/admin/tenants`, sync node และ assign tenant ที่ `/admin/nodes`

### ค่าที่ต้องตั้งใน `.env`

| ตัวแปร                     | ใช้ทำอะไร                                                                                                      |
| -------------------------- | -------------------------------------------------------------------------------------------------------------- |
| `SIP_DEPLOYER_URL`         | URL ของ SIP Deployer                                                                                           |
| `SIP_DEPLOYER_TOKEN`       | token จาก SIP Deployer → Settings → API clients (ability `nodes:read` และ `credentials:read`, จำกัด IP ต้นทาง) |
| `SIP_TENANT_DOMAIN_SUFFIX` | suffix ของโดเมน tenant — ชี้ wildcard DNS `*.<suffix>` ไปที่ node                                              |
| `SIP_CDR_TIMEZONE`         | (ไม่บังคับ, default `UTC`) time zone ของเวลาที่ Asterisk เขียนลงตาราง `cdr` บน node                            |

### Process ที่ต้องรันเสมอ

ถ้า queue worker หรือ scheduler ไม่รัน การเปลี่ยนแปลงจะค้างใน outbox และไม่ถึง PBX (หน้าเว็บจะเตือน) — `composer dev` รันให้ครบ; บน server ให้ใช้ Supervisor:

| Process                                                | หน้าที่                                                                                                                                                                                                       |
| ------------------------------------------------------ | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `php artisan queue:work --queue=provisioning,default`  | apply config revision ลง node                                                                                                                                                                                 |
| `php artisan schedule:work` (หรือ cron `schedule:run`) | `provisioning:relay` ทุก 2 วินาที, `cdr:sync` และ `authorization-codes:expire` ทุกนาที, `nodes:sync` ทุก 5 นาที, `nodes:reconcile` ทุก 15 นาที, prune Idempotency-Key และ call records (เกิน 13 เดือน) รายวัน |

สั่งเองได้: `php artisan nodes:reconcile {node?} --report-only` (ดู drift โดยไม่แก้), `php artisan cdr:sync {node?}`

ถ้า scheduler เคยถูกปิดกลางงาน แล้วงานหยุดรันทั้งที่ `schedule:work` ยังทำงาน (`schedule:list` แสดง "Has Mutex") ให้รัน `php artisan schedule:clear-cache`

## โครงสร้างโค้ด

Modular monolith ใน [app/Modules/](app/Modules/):

| Module                                        | หน้าที่                                                                                                                                               |
| --------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------- |
| [Tenancy](app/Modules/Tenancy/)               | tenant, สมาชิก, `TenantContext` + global scope แบบ strict, API token ต่อ tenant                                                                       |
| [Nodes](app/Modules/Nodes/)                   | SIP Deployer client, `pbx_nodes`, `node_secrets`, การเชื่อมต่อ node, assignment                                                                       |
| [Telephony](app/Modules/Telephony/)           | extension, dial number, SIP credential, ring group, voicemail, calling profile, รหัสอนุญาต                                                            |
| [Provisioning](app/Modules/Provisioning/)     | config revision, operation, outbox, compiler (`NumberCompiler`, `CallingCompiler`), `ApplyRevision`, `NodeReadBack`, `ReconcileNode`, retry operation |
| [CallRecords](app/Modules/CallRecords/)       | `cdr_cursors`, `SyncCallRecords`, `CallParties` (map tenant จาก `src`/`dst`), `call_records`, Call history                                            |
| [Infrastructure](app/Modules/Infrastructure/) | `OpenSipsDbAdapter`, `Asterisk\RealtimeAdapter`, `Asterisk\CdrAdapter`, `AmiClient`/`AmiAdapter`                                                      |
| [Audit](app/Modules/Audit/)                   | `Audit::record()` พร้อม redact ค่า secret                                                                                                             |

Routes แยกไฟล์ตามส่วน: [routes/tenancy.php](routes/tenancy.php), [routes/nodes.php](routes/nodes.php), [routes/telephony.php](routes/telephony.php), [routes/provisioning.php](routes/provisioning.php), [routes/call-records.php](routes/call-records.php), [routes/api.php](routes/api.php)

## Tests และการตรวจโค้ด

```bash
php artisan test --compact    # SQLite in-memory (ไม่ต้องมี PostgreSQL)
composer test                 # pint --test + phpstan + tests
composer ci:check             # vp check + vue-tsc + composer test (เหมือน CI)
```

CI มี 2 workflow: [tests.yml](.github/workflows/tests.yml) รัน `composer setup` แล้ว vue-tsc, Larastan และ tests บน PostgreSQL 17; [lint.yml](.github/workflows/lint.yml) (Code Style) รัน Pint และ `vp check`; CaptainHook ([captainhook.json](captainhook.json)) ตรวจ commit message, Pint และ `vp check` ก่อน commit และรัน phpstan + tests ก่อน push

## เอกสาร

- [docs/management-app-plan.md](docs/management-app-plan.md) — แผนพัฒนา, ข้อตัดสินใจ (D1–D7), contract กับ SIP Deployer, pipeline การ provision และสถานะงานล่าสุด
- [docs/opensips36_multitenant_voip_architecture.md](docs/opensips36_multitenant_voip_architecture.md) — แบบอ้างอิงสถาปัตยกรรม OpenSIPS แบบหลาย tenant (แอปนี้ใช้หลักการโดเมนต่อ tenant แต่ทุกสายผ่าน Asterisk — ดู D6)
