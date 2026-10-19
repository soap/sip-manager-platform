# Telephony Management App — แผนพัฒนา v0.2

อ้างอิง: *Telephony Platform Architecture — DigitalOcean Pilot v1.1* (17 ต.ค. 2026) และโค้ด SIP Deployer ณ commit `72ef432`
ขอบเขตแผนนี้: **Phase 2 (tenant/extension/device/provisioning) และ Phase 3 (CDR/events/monitoring) แบบละเอียด**, Phase 4–5 เป็นโครงเท่านั้น

---

## 0. ข้อตัดสินใจ (17 ต.ค. 2026)

| เรื่อง | ตัดสินใจ |
|---|---|
| แอป | Laravel 13 แยกจาก SIP Deployer — Modular Monolith (`app/Modules/*`) ตาม §9 |
| UI | **Inertia 2 + Vue 3 + Tailwind** (สมมติ Vue — เปลี่ยนเป็น React ได้ ถ้าต้องการ) + REST API `/api/v1` (Sanctum) |
| Database | PostgreSQL 16 (desired state, CDR สำเนา, audit) |
| Async | Redis + Horizon; Transactional Outbox |
| Node manifest | **Pull**: Management App เรียก API ของ SIP Deployer ด้วย token แบบอ่านอย่างเดียว |
| การเชื่อมต่อ node | MariaDB (`opensips`, `asterisk`), AMI, ARI เท่านั้น — **ไม่มี SSH, ไม่เขียนไฟล์** |
| Tests | Pest 4, Laravel Boost (เหมือน SIP Deployer) |

---

## 1. Contract จริงจากโค้ด SIP Deployer (ยืนยันแล้ว)

| ช่องทาง | ค่าจริงในโค้ด | ที่มา |
|---|---|---|
| OpenSIPS DB | db `opensips`, user **`manager`@'%'** — `SELECT, INSERT, UPDATE, DELETE` ทั้ง db | `CreateOpenSipsDatabase` |
| Asterisk DB | db `asterisk`, user **`asterisk_manager`@'%'** — `SELECT, INSERT, UPDATE, DELETE` ทั้ง db; schema จาก Alembic (`config`, `cdr`) | `CreateAsteriskDatabase` |
| AMI | TCP **5038**, user `manager`, permit 127.0.0.1 + management CIDRs | `AsteriskConfig::manager()` |
| ARI | **HTTP 8088** (ไม่มี TLS), user `manager` | `AsteriskConfig::http()/ari()` |
| Bind | `127.0.0.1` + private IP (VPC) และ/หรือ public IP ตามชนิดของ management CIDR | `Server::managementBindAddresses()` |
| OpenSIPS transports | **UDP/TCP 5060 เท่านั้น** (ยังไม่มี TLS/WSS) | `OpenSipsConfig::DEFAULTS` |
| Auth | `www_authorize/proxy_authorize("", "subscriber")` → realm = โดเมนใน From (hostname ของ server); ตาราง `subscriber` เก็บ **ha1 เท่านั้น** | `opensips.cfg`, `TestSubscribers` |
| Domain | ไม่มี `domain` module — `is_myself()` = hostname/IP ของ node; **หนึ่ง SIP domain ต่อ node** | `opensips.cfg` |
| usrloc | `single-instance-sql-write-back` → ตาราง `location` ตามหลัง memory ได้ถึง ~1 รอบ timer (ค่า default 60s) | `opensips.cfg` |
| Routing | INVITE จากโทรศัพท์ที่ auth แล้ว → **Asterisk ทุกสาย** (127.0.0.1:5080); Asterisk โทรกลับหาโทรศัพท์ด้วย `Dial(PJSIP/<user>@opensips)` → OpenSIPS `lookup("location")` | `opensips.cfg`, `AsteriskConfig::extensions()` |
| Dialplan | `[from-opensips]` → `*43` echo, `switch => Realtime/managed@extensions` (**context เดียว `managed`**), แล้ว `include => direct-dial` (เฉพาะ lab/pilot; realtime rows ชนะเสมอ) | `AsteriskConfig::extensions()` |
| Realtime tables | `ps_endpoints, ps_auths, ps_aors, ps_domain_aliases, ps_endpoint_id_ips, ps_registrations, extensions`, + `voicemail` และ `queues, queue_members, queue_rules` ตาม feature | `AsteriskConfig::extconfig()` |
| CDR | `cdr_adaptive_odbc` → ตาราง `cdr` ใน db `asterisk` (`cdr_csv` ปิด) | `AsteriskConfig::files()` |
| Feature groups | `direct_dial`(test), `ami`, `ari`, `realtime`, `voicemail`, `queues` (default on); `conference`, `recording` (default off) | `AsteriskConfig::FEATURES` |
| Media | rtpengine 10000–20000 (public), Asterisk RTP 30000–30999 (local) | `OpenSipsConfig`, `AsteriskConfig` |

### 1.1 สิ่งที่ต้องแก้ในเอกสาร
- `design/sip-deployer-design.md` v0.2 ล้าสมัยหลายจุด (RTP range, ARI 8089/HTTPS, user `sipmanager`, TLS/WSS, Asterisk "minimal + *_custom.conf", routing user↔user) — ควรอัปเดตให้ตรงกับโค้ด
- v1.1 §3 diagram เขียน OpenSIPS → Asterisk `127.0.0.1:5080` ถูกต้อง แต่ฝั่ง OpenSIPS ใช้ socket `udp:127.0.0.1:5060` ภายใน
- v1.1 §8 ขั้นที่ 6 / §11 พูดถึง "AMI reload" — ในทางปฏิบัติ realtime (`pbx_realtime`, `auth_db`, `voicemail`, sorcery realtime) **อ่านจาก DB ตอนใช้งาน** จึงไม่ต้อง reload ส่วนใหญ่ (ดู §6.3)

---

## 2. ผลต่อการออกแบบที่สำคัญ (ต้องตัดสินใจ)

**D1 — Multi-tenant บน node เดียวยังทำไม่ได้กับ base config ปัจจุบัน**
เพราะ (ก) หนึ่ง SIP domain ต่อ node และ `lookup()` ใช้ username อย่างเดียว → username ต้องไม่ซ้ำทั้ง node และ (ข) dialplan ทุก tenant อยู่ใน context `managed` เดียว → "1002" ของ tenant A กับ B ชนกัน
**ตัดสินใจ (18 ต.ค. 2026): แบ่งช่วงหมายเลข 5 หลักต่อ tenant** — หลาย tenant อยู่บน node เดียวได้
- หมายเลข = `PP` (prefix tenant 2 หลัก) + `EEE` (extension 3 หลัก) เช่น tenant prefix `20` → 20000–20999
- prefix ใช้ได้ `20`–`89` (ประมาณ 70 tenant ต่อ node); **ห้ามขึ้นต้นด้วย 0, 1, 9, \*** — สงวนไว้สำหรับเลขฉุกเฉิน/hotline ไทย (191, 1669, 1xxx), prefix โทรออก และ feature code
- SIP username = หมายเลข 5 หลัก จึงไม่ซ้ำทั้ง node → `lookup()` และ context `managed` เดียวใช้ได้
- **กันโทรข้าม tenant:** ทุก row ใน `managed` ที่ compile ให้ tenant ตรวจ prefix ผู้โทรเป็น priority แรก เช่น `GotoIf($["${CALLERID(num):0:2}" != "20"]?denied,1)`
- **เงื่อนไขบังคับ (SIP Deployer — ทำแล้ว 18 ต.ค. 2026, commit `3c975e2`):** `route[untrusted_identity]` ใน `opensips.cfg` ลบ `P-Asserted-Identity`, `P-Preferred-Identity`, `Remote-Party-ID` จากทุก request และ reply ที่ไม่ได้มาจาก Asterisk backend (`127.0.0.1:<backend port>`) ก่อน routing ใด ๆ — Asterisk (`trust_id_inbound = yes`) จึงใช้ผู้โทรจาก header `From` ซึ่ง OpenSIPS บังคับให้ user ตรงกับผู้ที่ authenticate (`$au == $fU`) และยังคง display name จากโทรศัพท์ไว้ (ไม่ใส่ PAI ใหม่)
  เป็นงานของ SIP Deployer เพราะ `opensips.cfg` เป็น base config ที่ SIP Deployer เป็นเจ้าของและเขียนใหม่ทุกครั้งที่ติดตั้ง (§4.1 v1.1); node ที่ติดตั้งก่อนหน้านี้ต้อง reinstall/reconfigure OpenSIPS
- ข้อจำกัด: ผู้ใช้ต้องกดเลขเต็ม 5 หลัก — การกดเลขสั้น 3 หลักภายใน tenant (row `_XXX` ที่เติม prefix จากผู้โทร) เป็นงานถัดไป
- ทางเลือกระยะยาว (domain ต่อ tenant ใน OpenSIPS) ไม่จำเป็นแล้วสำหรับ pilot

**D2 — ลบ/หมุน credential ไม่ตัด registration ทันที** เพราะ usrloc อยู่ใน memory และ MI ใช้ได้แค่ในเครื่อง Management App จึงทำได้แค่ลบ `subscriber`; โทรศัพท์เดิมยังรับสายได้จนหมดอายุ register (ยอมรับใน MVP หรือขอให้ SIP Deployer เปิดช่องทาง เช่น ลด `max_expires`)

**D3 — realm ผูกกับ hostname ของ node** (`ha1 = md5(user:realm:pass)`) → manifest ต้องส่ง `sip_domain` และถ้า hostname เปลี่ยน ต้อง re-hash ทุก subscriber (ต้องมีรหัสผ่านเดิม — ดู D4)

**D4 — เก็บ SIP password แบบไหน** ถ้าจะทำ Yealink auto-provisioning หรือ re-hash เมื่อ realm เปลี่ยน ต้องเก็บ password แบบเข้ารหัส (encrypted cast + key แยก) ถ้าไม่ทำ ให้เก็บแค่ ha1 และ "ไม่แสดงซ้ำ" ตาม §12
**ตัดสินใจ (20 ต.ค. 2026): เก็บแบบเข้ารหัส** (`sip_credentials.secret`, encrypted cast ด้วย app key) — ha1 คำนวณตอน apply จาก `sip_domain` ปัจจุบันของ node จึงแก้ D3 ได้ด้วยการ apply ซ้ำ; UI แสดงรหัสผ่านครั้งเดียวตอนสร้าง/หมุน (flash) แล้วไม่ส่งออกอีก

**D5 — Ring group และทะเบียนหมายเลข (ตกลง 20 ต.ค. 2026)**
- ตาราง `dial_numbers` (tenant_id, pbx_node_id, number, owner morph; unique (pbx_node_id, number)) เป็นทะเบียนกลางของทุกปลายทางที่มีเบอร์ — extension ทำแล้ว; ring group, queue, IVR ในอนาคตลงทะเบียนที่นี่เพื่อไม่ให้ชนกัน
- `ring_groups`: tenant_id, pbx_node_id, number (ใน prefix), name, strategy (`ring_all` / `sequential`), ring_seconds, failover (type + id: extension / ring group / voicemail / hangup), status, version
- `ring_group_members`: ring_group_id, extension_id (tenant เดียวกัน), position; unique (ring_group_id, extension_id)
- Compile: exten ของกลุ่มใน `managed` = GotoIf ตรวจ prefix ผู้โทร (D1) → `Dial(PJSIP/a@opensips&PJSIP/b@opensips,N)` (ring_all) หรือ Dial ทีละคน (sequential) → failover
- กติกา: ลบ extension = เอาออกจากกลุ่มใน transaction เดียวกัน + recompile กลุ่ม; extension ที่ปิดถูกข้ามตอน compile; ไม่มีสมาชิก active = ไป failover ทันที
- ข้อควรระวัง Phase 4: สายเข้าจาก DID ไม่ผ่านการตรวจ prefix ผู้โทร — ต้องมี context สำหรับสายเข้าแยก
- **สถานะ (20 ต.ค. 2026):** ทำแล้วทั้งหมด — `ring_groups`, `ring_group_members` (สูงสุด 10 คน: `Dial()` ของ ring_all ต้องอยู่ใน `appdata` 256 ตัวอักษร), ring time 5–120 วินาที, failover = hangup / extension / ring group (กันวนกลับด้วยการไล่ chain), เลขเริ่มที่ PP500; ปิด extension = recompile กลุ่มที่มีเป็นสมาชิก/failover; ลบ extension หรือกลุ่ม = failover ที่ชี้มาเปลี่ยนเป็น hangup + recompile; เปลี่ยน prefix/เอา tenant ออกจาก node ทำไม่ได้ถ้ายังมีเบอร์ใน `dial_numbers`; หน้า `/t/{slug}/ring-groups` (สร้าง, แก้, เรียงสมาชิก, สถานะบน PBX); ทดสอบบน node lab: 20500 ring_all (20101, 20102) → `applied`

---

## 3. สถาปัตยกรรมแอป

```
Browser (Inertia + Vue 3)      External clients
        │                            │ Sanctum token / Idempotency-Key
        ▼                            ▼
   Web controllers ──► Application services (Actions) ◄── API v1 controllers
                               │  tenant context จาก auth เท่านั้น
                               ▼
              PostgreSQL: desired state + config_revisions + outbox
                               │ (outbox relay ทุก 1–2 วินาที)
                               ▼
          Redis/Horizon queues: provisioning · cdr · events · default
                               │
     ┌─────────────────────────┼───────────────────────────┐
     ▼                         ▼                           ▼
OpenSipsDbAdapter      AsteriskRealtimeAdapter        AmiClient / AriClient
(MariaDB opensips)     (MariaDB asterisk)             (TCP 5038 / HTTP 8088)
                               ▲
SipDeployerClient ── GET /api/v1/nodes (pull manifest) ── SIP Deployer
```

Long-running processes: `horizon`, `scheduler`, `ami:listen {node}` (หนึ่ง process ต่อ node ผ่าน Supervisor)

---

## 4. โมดูลและ Data Model (PostgreSQL)

```
app/Modules/
  Tenancy/        Tenant, TenantMembership, TenantContext middleware, policies
  Telephony/      Extension, Device, SipCredential, (Phase 4: SipTrunk, DidNumber, routes, RingGroup)
  Provisioning/   ConfigRevision, ProvisioningOperation, OutboxMessage, Compiler/*, Reconciler
  Nodes/          PbxNode, TenantPbxAssignment, SipDeployerClient, NodeSecret
  CallRecords/    CallRecord, CallEvent, CdrSync
  Audit/          AuditLog, Auditable trait, redaction
  Infrastructure/ OpenSips/OpenSipsDbAdapter, Asterisk/RealtimeAdapter, Asterisk/Ami/*, Asterisk/Ari/*
  Shared/         Enums, ValueObjects (E164, ExtensionNumber), Idempotency
```

| ตาราง | คอลัมน์หลัก |
|---|---|
| `tenants` | id (ulid), name, slug, status, settings jsonb |
| `tenant_number_ranges` | tenant_id, pbx_node_id, prefix (`20`–`89`), unique (pbx_node_id, prefix); MVP 1 range ต่อ tenant |
| `tenant_memberships` | tenant_id, user_id, role (`owner/admin/member`); unique (tenant_id, user_id) |
| `pbx_nodes` | id, deployer_server_id (unique), name, hostname, sip_domain, private_address, public_address, environment (`lab/pilot/production`), status, services jsonb (release/version), capabilities jsonb (feature groups), endpoints jsonb (db/ami/ari host:port), manifest_checksum, synced_at |
| `node_secrets` | pbx_node_id, kind (`opensips_db/asterisk_db/ami/ari`), username, secret (encrypted), fetched_at, rotated_at |
| `tenant_pbx_assignments` | tenant_id, pbx_node_id, role=`primary`, status; MVP: unique(tenant_id) (หนึ่ง primary node ต่อ tenant; หลาย tenant ต่อ node ได้) |
| `extensions` | tenant_id, number (5 หลัก ต้องขึ้นต้นด้วย prefix ของ tenant), display_name, status, voicemail_enabled, outbound_policy, `version` (optimistic lock); unique (tenant_id, number) |
| `devices` | tenant_id, extension_id, vendor/model, mac (nullable), provisioning_profile, status |
| `sip_credentials` | tenant_id, extension_id, sip_username, realm, ha1, secret_encrypted (ตาม D4), rotated_at; unique (pbx_node_id, sip_username) |
| `config_revisions` | tenant_id, pbx_node_id, revision_number, aggregate_type/id, checksum, compiled jsonb (rows ที่จะเขียน, ไม่มี secret), status (`draft/validated/applying/applied/failed/superseded`), validation_report, apply_result |
| `provisioning_operations` | id (ulid = operation_id), tenant_id, type, aggregate, idempotency_key (unique ต่อ tenant), config_revision_id, status (`pending/running/applied/retrying/failed`), attempts, error_code, error_message, next_attempt_at |
| `outbox_messages` | id, topic, payload jsonb, available_at, dispatched_at |
| `idempotency_keys` | tenant_id, key, request_hash, response jsonb, expires_at |
| `call_records` (P3) | tenant_id, pbx_node_id, uniqueid, linkedid, sequence, direction, src, dst, start/answer/end, duration, billsec, disposition, raw jsonb; unique (pbx_node_id, uniqueid, sequence) |
| `call_events` (P3) | pbx_node_id, linkedid, uniqueid, event, payload jsonb (redacted), occurred_at, dedupe_hash unique |
| `registration_snapshots` (P3) | pbx_node_id, sip_username, contact, user_agent, received, expires_at, seen_at |
| `audit_logs` | tenant_id, actor_id, action, resource type/id, summary jsonb (redacted), reason, ip, created_at |

กฎ: `tenant_id` มาจาก `TenantContext` เท่านั้น, global scope + policy ทุก model, FK/unique จริง, RLS เป็นทางเลือกภายหลัง

---

## 5. Node Manifest (Pull) — งานที่ต้องเพิ่มใน SIP Deployer

SIP Deployer ยังไม่มี API (มีแค่ `routes/web.php`) ต้องเพิ่ม:

- `GET /api/v1/nodes` และ `GET /api/v1/nodes/{id}` — Sanctum token ability `nodes:read`, จำกัด IP ต้นทาง
- `GET /api/v1/nodes/{id}/credentials` — ability แยก `credentials:read`, log ทุกครั้ง (ไม่ log ค่า), คืน secret ผ่าน HTTPS เท่านั้น

ตัวอย่าง manifest:

```json
{
  "id": 7, "name": "sip-lab-1", "hostname": "sip1.example.com",
  "sip_domain": "sip1.example.com", "environment": "lab", "status": "active",
  "addresses": {"public": "203.0.113.10", "private": "10.104.0.5", "management": "10.104.0.5"},
  "os": "ubuntu-24.04",
  "services": {
    "opensips": {"release": "3.6", "status": "installed", "transports": ["udp","tcp"], "sip_port": 5060},
    "asterisk": {"release": "22", "version": "22.11.0", "status": "installed",
                 "features": ["ami","ari","realtime","voicemail","queues"]}
  },
  "endpoints": {
    "opensips_db": {"host": "10.104.0.5", "port": 3306, "database": "opensips", "username": "manager"},
    "asterisk_db": {"host": "10.104.0.5", "port": 3306, "database": "asterisk", "username": "asterisk_manager"},
    "ami": {"host": "10.104.0.5", "port": 5038, "username": "manager"},
    "ari": {"host": "10.104.0.5", "port": 8088, "scheme": "http", "username": "manager"}
  },
  "dialplan": {"entry_context": "from-opensips", "managed_context": "managed", "backend_endpoint": "opensips"},
  "credentials_version": "2026-10-17T15:00:00Z",
  "manifest_version": 1
}
```

Management App: `nodes:sync` ทุก 5 นาที + ปุ่ม Sync; เทียบ `manifest_checksum`; ถ้า `credentials_version` เปลี่ยน → ดึง credentials ใหม่; ถ้า node หายจาก manifest → `status=detached` (ไม่ลบข้อมูล) ; ถ้า environment = production → ปิดการใช้ฟีเจอร์ทดสอบฝั่งนี้ด้วย

---

## 6. Provisioning Pipeline

### 6.1 Flow
1. Action (เช่น `CreateExtension`) — validate + authorize → ใน **DB transaction เดียว**: บันทึก desired state, `config_revision` (status `draft`), `provisioning_operation` (`pending`), `outbox_message`
2. API ตอบ `202 {operation_id}` (web UI แสดงสถานะ live)
3. Outbox relay → dispatch `ApplyConfigRevision` บน queue `provisioning` (ShouldBeUnique ต่อ node → apply ทีละ revision ต่อ node)
4. **Compile** — ได้ "rows ที่ต้องการ" ต่อ node (deterministic, เรียง, checksum)
5. **Validate** — capability ของ node (เช่น voicemail เปิดไหม), หมายเลขอยู่ในช่วง prefix ของ tenant, ตัวเลขซ้ำ, ไม่ชน feature code (`*43`)
6. **Apply** — diff กับ rows ปัจจุบันของ node แล้ว upsert/delete ภายใน transaction ต่อ db (opensips และ asterisk แยก transaction; ทำ opensips หลังสุดเพื่อไม่ให้โทรศัพท์ register ได้ก่อน dialplan พร้อม)
7. **Read-back** — SELECT rows กลับมาเทียบ checksum + ตรวจผ่าน AMI (§6.3)
8. ผล: `applied` / `retrying` (backoff 10s, 30s, 2m, 10m, 30m) / `failed` + alert

ทุก row ที่ Management App เขียนต้องระบุตัวตนได้ — ใช้ prefix/คอลัมน์เพื่อให้ reconciler แยกจาก rows อื่น:
- `extensions`: context `managed`, ใส่ marker ใน `appdata` ไม่ได้ → เก็บ ownership map ใน PostgreSQL (`config_revisions.compiled`) แล้ว reconcile เฉพาะ rows ที่อยู่ใน map

### 6.2 Compile สำหรับ extension (MVP)
ตัวอย่าง tenant prefix `20`, extension `20101`:
- `opensips.subscriber`: `username=20101, domain=<sip_domain>, ha1=md5(20101:<sip_domain>:<password>)` (+ `ha1_sha256` ถ้าตารางมีและต้องการ)
- `asterisk.extensions` (context `managed`, exten `20101`):
  1. `GotoIf($["${CALLERID(num):0:2}" != "20"]?denied,1)` — กันโทรข้าม tenant (พึ่ง `route[untrusted_identity]` ใน §2 D1)
  2. `Dial(PJSIP/20101@opensips,30)`
  3. ถ้าเปิด voicemail: `VoiceMail(20101@t20,u)` แล้ว `Hangup()`; ไม่เปิด: `Hangup()`
- exten `denied` (ใช้ร่วมทุก tenant): `Playback(ss-noservice)` แล้ว `Hangup(21)`
- `asterisk.voicemail` (ถ้าเปิด): `context=t20` (ตั้งชื่อ voicemail context จาก prefix), `mailbox=20101, password=<pin>, fullname, email`
- feature code ร่วม (เช่น `*97`) เป็น row เดียว: `VoiceMailMain(${CALLERID(num)}@t${CALLERID(num):0:2})` — ไม่ต้อง lookup เพราะ context ตั้งจาก prefix
- ไม่เขียน `ps_*` สำหรับโทรศัพท์ (โทรศัพท์ register ที่ OpenSIPS); `ps_*` ใช้กับ trunk ใน Phase 4

### 6.3 Read-back / health check
| สิ่งที่เปลี่ยน | ต้อง reload? | ตรวจอย่างไร |
|---|---|---|
| `subscriber` | ไม่ (auth_db query ทุกครั้ง) | SELECT ha1 ตรงกับที่ compile |
| `extensions` realtime | ไม่ | AMI `Command: dialplan show 1001@managed` เห็น priority ครบ |
| `voicemail` | ไม่ | AMI `VoicemailUsersList` มี mailbox |
| `queues`/`queue_members` | บางกรณี (`queue reload realtime`) | AMI `QueueStatus` |
| `ps_*` (trunk, P4) | ไม่ (sorcery realtime) | AMI `PJSIPShowEndpoint` |

> หลัก "อย่าถือว่าสำเร็จเพราะส่งคำสั่งแล้ว" ยังใช้ — แต่ส่วนใหญ่เป็นการตรวจผล ไม่ใช่สั่ง reload

### 6.4 Reconciliation
`nodes:reconcile` ทุก 15 นาที และหลัง SIP Deployer reinstall/upgrade (ตรวจจาก manifest checksum/`services.*.version` เปลี่ยน): compile desired state ทั้ง node → diff กับ rows จริง → รายงาน drift (missing / unexpected / changed) และ auto-fix เฉพาะ missing/changed ที่เป็นของ Management App

### 6.5 Error classes
- Retryable: connection refused/timeout, deadlock, AMI ไม่ตอบ
- Permanent: validation, capability ไม่มี, auth DB ผิด (→ re-pull credentials 1 ครั้ง แล้ว failed), schema ไม่ตรง

---

## 7. Phase 3 — CDR, Call Events, Monitoring

- **CDR sync** (`cdr:sync` ทุก 1 นาทีต่อ node): อ่านตาราง `cdr` ด้วย watermark ที่ `end` ย้อนหลัง 5 นาที (กัน CDR ช้า) → upsert ด้วย (node, uniqueid, sequence) → map tenant จาก prefix 2 หลักของ src/dst — ต้องตรวจคอลัมน์จริงของ Alembic `cdr` บน server (มี `linkedid`, `sequence`, `peeraccount`)
- **AMI listener** (`ami:listen {node}`): ReactPHP/amphp socket client ขนาดเล็ก (ไม่ใช้ PAMI ที่ไม่ดูแลแล้ว), subscribe `call,cdr,reporting`, reconnect + backoff, heartbeat `Ping`; เขียน `call_events` ผ่าน queue `events` dedupe ด้วย hash(node, uniqueid, event, timestamp)
- **Registration status**: อ่าน `opensips.location` ทุก 1 นาที → `registration_snapshots` (หน่วงได้ถึง ~60s จาก write-back — แสดงเวลาที่ "seen")
- **Business monitoring** (ไม่ซ้ำกับ SIP Deployer): extension ที่ไม่ register, provisioning ค้าง/failed, CDR sync หยุด, AMI listener หลุด, reconcile พบ drift, register fail/สายผิดปกติต่อ tenant; แจ้งผ่าน mail/Slack
- Retention: CDR 13 เดือน, call_events 30 วัน (ตั้งค่าได้)

---

## 8. API v1 และหน้าจอ

API (ตาม §12 ของ v1.1): `GET /tenant`, `GET|POST /extensions`, `GET|PATCH|DELETE /extensions/{id}`, `POST /extensions/{id}/devices`, `POST /extensions/{id}/rotate-credential`, `GET /operations/{id}`, `GET /call-records` — Idempotency-Key บน POST, `If-Match`/`version` บน PATCH, rate limit, pagination, SIP secret ตอบครั้งเดียว

หน้าจอ (Inertia/Vue):
- **Platform admin**: Nodes (sync, manifest, credentials status, assignment), Tenants, Operations (ทั้งระบบ, retry), Drift report
- **Tenant**: Dashboard (registered/total, สายวันนี้), Extensions (list/create/edit, reveal-once credential, rotate, สถานะ register), Devices, Voicemail, Call history (CDR), Operations, Audit log, API tokens, Members

---

## 9. ลำดับงาน (milestones + เกณฑ์ผ่าน)

| # | งาน | เกณฑ์ผ่าน |
|---|---|---|
| **M0** | SIP Deployer prerequisites: ~~แก้ PAI/RPID ใน opensips.cfg~~ (ทำแล้ว; ต้อง reconfigure node lab), API manifest/credentials, VPC ทดสอบ, ปิด direct_dial ได้, Asterisk ทดสอบบน server จริง (ยังค้างใน §4.5) | `curl` manifest ได้ด้วย token; โทร 1001↔1002 ผ่าน Asterisk ด้วย test subscribers |
| **M1** | Skeleton: Laravel 13, PostgreSQL, Redis/Horizon, Inertia+Vue, Sanctum, Boost, Pest, auth + MFA สำหรับ platform admin, Tenancy module + policies + audit | tenant isolation tests ผ่าน |
| **M2** | Nodes: SipDeployerClient, `nodes:sync`, node_secrets, assignment, connection test (DB/AMI/ARI) | node lab แสดง capability และ "connections OK" |
| **M3** | Infrastructure adapters: OpenSipsDbAdapter, AsteriskRealtimeAdapter, AmiClient (action/response) | integration test กับ MariaDB/Asterisk ใน Docker |
| **M4** | Provisioning core: config_revisions, operations, outbox relay, compiler (extension + voicemail), apply, read-back, retry | สร้าง 1001/1002 ผ่าน API → operation `applied`; ส่งซ้ำด้วย Idempotency-Key ไม่สร้างซ้ำ; worker restart ระหว่าง apply แล้วจบถูกต้อง |
| **M5** | UI tenant + API v1 ครบ, credential reveal-once/rotate | **P0 ผ่านผ่าน Management App:** Yealink 2 เครื่อง register และโทรหากันได้ (ปิด direct_dial บน node); สร้าง tenant ที่ 2 แล้วโทรข้าม tenant ถูกปฏิเสธ แม้ส่ง PAI ปลอม |
| **M6** | Reconciler + drift report | SIP Deployer reinstall OpenSIPS/Asterisk → reconcile ไม่พบ drift และสายยังทำงาน |
| **M7** | Phase 3: CDR sync, AMI listener, registration snapshots, dashboard, alerts | CDR ถูกต้องสำหรับ answered/busy/no answer; event ไม่ซ้ำหลัง listener reconnect |

Phase 4 (โครง): `sip_trunks` → `ps_endpoints/ps_auths/ps_aors/ps_registrations/ps_endpoint_id_ips`, `did_numbers` + inbound routes → rows ใน `managed` (หรือ context trunk ที่ต้องตกลงกับ SIP Deployer), outbound policy (prefix allowlist, concurrent limit, เวลา, spending), ring group/queue
Phase 5: IVR (ARI Stasis, versioned flow), TTS, CRM adapter (vTiger ก่อน)

---

## 10. Testing

- Unit: compiler (snapshot ของ rows), ha1, checksum, diff, error classifier, AMI parser
- Feature: tenant isolation ทุก endpoint, idempotency, optimistic lock, credential ไม่หลุดใน log/response ซ้ำ
- Integration (Docker compose ใน CI): MariaDB + schema `opensips` (opensips-cli) + `asterisk` (Alembic) + Asterisk 22 container สำหรับ AMI
- Manual E2E (ตาม §15 v1.1): NAT ต่าง ISP, wrong password → fail2ban, reinstall, boundary (ต่อจากนอก CIDR ถูกปฏิเสธ)

---

## 11. ความเสี่ยงและคำถามที่ยังเปิด

1. ~~D1 multi-tenant ต่อ node~~ — ตัดสินแล้ว: ช่วงหมายเลข 5 หลักต่อ tenant (18 ต.ค. 2026)
2. ~~D4 เก็บ SIP password แบบเข้ารหัสเพื่อ Yealink auto-provisioning หรือไม่~~ — ตัดสินแล้ว: เข้ารหัส (20 ต.ค. 2026)
7. สิทธิ์ของ extension ยังไม่กำหนด: โทรภายใน tenant ถึงกันได้ทุกเบอร์ (v1.1 §7 "อนุญาตเฉพาะ extension ที่มีสิทธิ์โทรถึงกัน" ยังไม่มีรูปแบบ), ค่าของ `outbound_policy`, limit ราย extension
3. DB users `manager`/`asterisk_manager` เป็น `@'%'` และไม่ `REQUIRE SSL` — พึ่ง firewall + VPC; ควรจำกัด host เป็น private CIDR เมื่อย้าย VPC
4. ARI เป็น HTTP — ใช้ได้เฉพาะใน VPC
5. Management App deploy ที่ไหน (droplet แยก region เดียวกับ SIP node ตาม §5.1 รูปแบบ B) — ใช้ dployer/Ploi หรือ SIP Deployer สร้างให้?
6. Vue 3 หรือ React

---

## 12. สถานะ (19 ต.ค. 2026)

### M0 — SIP Deployer
- ✅ `route[untrusted_identity]` (ลบ PAI/PPI/RPID) — commit `3c975e2`, tests `95182af`
- ✅ **Node manifest API** (ยังไม่ commit): Sanctum 4, ตาราง `api_clients` (ชื่อ + allowed CIDRs; token ใช้ได้เฉพาะจาก address เหล่านี้), Filament **Settings → API clients** (issue token เลือก ability `nodes:read` / `credentials:read`, revoke), `GET /api/v1/nodes`, `GET /api/v1/nodes/{id}`, `GET /api/v1/nodes/{id}/credentials` (Cache-Control no-store, log ทุกครั้งไม่ log ค่า), `credentials_version` = HMAC ของรหัสผ่าน; Sanctum `guard => []` (API รับเฉพาะ token ไม่รับ session ของ panel); `AsteriskConfig::ENTRY_CONTEXT`/`OPENSIPS_ENDPOINT` + contract test; 264 tests ผ่าน
- ⏳ ทดสอบบน server จริง: `opensips -C`, fail2ban, Asterisk 22.11.0, PAI ปลอม, VPC bind
- หมายเหตุ: ถ้า SIP Deployer อยู่หลัง load balancer/Cloudflare ต้องตั้ง TrustProxies ไม่งั้น `$request->ip()` จะเป็น IP ของ proxy

### M1 — sip-manager (`D:\Coding\laravel\soap\sip-manager`, ยังไม่ commit)
- Laravel 13 + Inertia 3 + Vue 3 + TypeScript (Laravel Vue starter kit, main) + Wayfinder + Tailwind 4, Fortify: **2FA, email verification, password confirmation; ปิด registration และ passkeys**
- Pest 4 (แปลงจาก PHPUnit), Laravel Boost 2 (guidelines ใน `AGENTS.md`, `CLAUDE.md` = `@AGENTS.md`), Sanctum (API v1 ว่าง รอ M5), `.env.example` = PostgreSQL; tests ใช้ SQLite in-memory
- **Tenancy module** (`app/Modules/Tenancy`): `tenants` (ULID, slug, status), `tenant_memberships` (owner/admin/member), `TenantContext` (scoped), trait `BelongsToTenant` (global scope **strict**: ไม่มี tenant ใน context = throw; ข้าม tenant ต้องเรียก `withoutTenantScope()`), middleware `SetTenantContext` (`/t/{slug}`; ไม่ใช่สมาชิก = 404; suspended = เฉพาะ platform admin), `TenantPolicy`
- กฎสมาชิก: owner/admin จัดการสมาชิก; เฉพาะ owner ให้/ถอด owner; ต้องเหลือ owner อย่างน้อย 1; เพิ่มคนใหม่ = สร้าง user + ส่งลิงก์ตั้งรหัสผ่าน (ตั้งรหัสผ่านแล้วถือว่า verify email)
- **Platform admin**: `users.is_platform_admin` (ไม่ fillable), `php artisan app:create-platform-admin {email} {name?}`, หน้า `/admin/tenants` ต้องเปิด 2FA ก่อน
- **Audit module**: `audit_logs` + `Audit::record()` redact คีย์ password/secret/token/ha1/authorization/credential/*_pin
- หน้า Vue: Dashboard (เลือก tenant), admin/tenants, tenants/Dashboard, tenants/members, tenants/AuditLog; sidebar แสดงเมนู tenant
- 84 tests ผ่าน, vue-tsc/lint/format ผ่าน, build ผ่าน
- **ยังไม่ทำ/เลื่อน:** Horizon (ต้องใช้ ext-pcntl ซึ่งไม่มีบน Windows — ติดตั้งบน server ตอน deploy), ~~Larastan ยังไม่ได้รัน~~ (รันแล้ว ผ่าน), ~~`tenant_number_ranges` ย้ายไป M2~~ (ทำแล้วใน M2)

### M2 — Nodes (19 ต.ค. 2026, ยังไม่ commit)
- **SIP Deployer client** (`app/Modules/Nodes/SipDeployer.php`): `SIP_DEPLOYER_URL` + `SIP_DEPLOYER_TOKEN` ใน `config/services.php`; connect timeout 3s / timeout 10s; retry เฉพาะ connection error, 5xx, 429; 401/403 แจ้งสาเหตุ (token, ability, allowed addresses)
- **`pbx_nodes`** (`deployer_server_id` unique, manifest_version, name, hostname, sip_domain, environment, status, region, addresses/services/endpoints/dialplan jsonb, credentials_version, connection_checks, checked_at, synced_at, removed_at) — node ที่หายจาก manifest ใส่ `removed_at` (ไม่ลบ) และกลับมาได้เมื่อมีใน manifest อีกครั้ง; รับเฉพาะ `manifest_version` 1 (อื่นข้าม + log)
- **`node_secrets`** (pbx_node_id, kind `opensips_db/asterisk_db/ami/ari`, username, secret encrypted, fetched_at, rotated_at; unique (pbx_node_id, kind)) — ดึง `/credentials` เฉพาะเมื่อ `credentials_version` เปลี่ยน; ดึงไม่ได้ = เก็บชุดเดิมไว้แล้วลองใหม่รอบหน้า; ไม่มี secret ในหน้าเว็บ, log หรือ audit
- **`nodes:sync`** ทุก 5 นาที (scheduler, withoutOverlapping) + ปุ่ม Sync; audit `node.added`, `node.removed`, `node.credentials_updated`
- **`NodeConnections`**: `openSipsDatabase()` / `asteriskDatabase()` (MariaDB connection ต่อ node, สร้างใหม่เมื่อ credential เปลี่ยน), `ari()` (HTTP client + basic auth), `ami()`; **ทดสอบการเชื่อมต่อ** (`CheckNodeConnections`): `SELECT version()` ทั้งสอง db, ARI `GET /ari/asterisk/info`, AMI Login/Logoff — node lab `sip-server-test-02` ผ่านครบ 4 ช่องทาง
- **`tenant_pbx_assignments`** (tenant_id unique, pbx_node_id, status `active/suspended`) + **`tenant_number_ranges`** (tenant_id unique, pbx_node_id, prefix; unique (pbx_node_id, prefix)) ตาม D1 — prefix `20`–`89`; เปลี่ยน prefix ได้เฉพาะตอน tenant ยังไม่มี extension; เอา tenant ออกจาก node ได้เมื่อไม่มี extension; audit `pbx.assigned/assignment_updated/unassigned`
- **`extensions`** (tenant_id, pbx_node_id, extension_number 5 หลัก ขึ้นต้นด้วย prefix, display_name, status `active/disabled`; unique (tenant_id, extension_number) และ (pbx_node_id, extension_number)) — สร้าง/เปลี่ยนชื่อ/ปิด/ลบได้โดย owner/admin; ยังไม่เขียนลง node (M4)
- หน้า Vue: `/admin/nodes` (sync, สถานะ endpoint, ทดสอบการเชื่อมต่อ, tenant + prefix ต่อ node), `/t/{slug}/extensions`
- 138 tests ผ่าน, Larastan ผ่าน, vue-tsc/lint/build ผ่าน
- **ข้อสังเกตจากการทดสอบ lab:** ถ้าเชื่อมต่อจากเครื่องนอก VPC ต้องใส่เฉพาะ public IP ใน `management_app_cidrs` ของ server แล้ว reinstall ทั้ง OpenSIPS และ Asterisk (มี private CIDR ปน = SIP Deployer ส่ง private IP เป็น management address); reinstall ที่ fail ด้วย "database is locked" มาจาก SQLite ของ SIP Deployer (`journal_mode=delete`, `DEFERRED`) — เสนอ WAL + `IMMEDIATE` + `busy_timeout`
- ยังไม่ทำ: `manifest_checksum` (ตอนนี้เขียนทับทุกรอบ sync), `role=primary` ใน assignments (MVP มีแค่ primary), status `detached` (ใช้ `removed_at` แทน)

### M3/M4 — โทรภายใน tenant (20 ต.ค. 2026, ยังไม่ commit)
- **Telephony:** `dial_numbers` (D5), `sip_credentials` (D4: encrypted, 20 ตัวอักษร/ตัวเลข), `extensions.version` (optimistic lock; PATCH ส่ง `version`); สร้าง extension = extension + dial number + credential + provisioning ใน transaction เดียว; หน้า Extensions แสดงรหัสผ่าน SIP ครั้งเดียว, สถานะบน PBX (poll ทุก 3s ระหว่างทำงาน), ปุ่ม New password / Retry
- **Infrastructure (M3 บางส่วน):** `OpenSipsDbAdapter` (replace/read `subscriber` ต่อ username), `Asterisk\RealtimeAdapter` (replace/read exten ใน context `managed`) — ยังไม่มี `AmiClient` และ integration test ใน Docker
- **Provisioning (M4):** `config_revisions` (revision_number ต่อ node, target `number:<n>`, compiled ไม่มี ha1, checksum), `provisioning_operations` (ULID, idempotency_key unique ต่อ tenant, status รวม `superseded`), `outbox_messages`; `RequestProvisioning` (ต้องอยู่ใน transaction ของการเปลี่ยน desired state) → `provisioning:relay` ทุก 2 วินาที → job `ApplyConfigRevision` บน queue `provisioning` (`WithoutOverlapping` ต่อ node) → `ApplyRevision`: compile สถานะปัจจุบันของเบอร์ ณ ตอน apply (revision ที่มาไม่เรียงก็จบที่สถานะล่าสุด) → validate (node ไม่ถูกลบ, มี OpenSIPS + realtime) → เขียน Asterisk ก่อน OpenSIPS → read-back เทียบ checksum → `applied` / `retrying` (10s, 30s, 2m, 10m, 30m) / `failed`; revision เก่าที่มี revision ใหม่กว่าของเบอร์เดียวกัน = `superseded`
- **Compile (§6.2):** extension active → `subscriber` (username, domain=sip_domain, ha1, ha1_sha256) + exten: `GotoIf(prefix ผู้โทร != PP → denied,1)`, `Dial(PJSIP/<n>@opensips,30)`, `Hangup()`; ปิด/tenant หรือ assignment suspended → ไม่มี subscriber + `Goto(denied,1)`; ลบ → ไม่มี rows; exten `denied` ร่วม = `Playback(ss-noservice)`, `Hangup(21)`; เปลี่ยนสถานะ assignment = provision ทุกเบอร์ของ tenant
- **Error classes (§6.5):** retry: MariaDB 2002/2003/2006/2013/1205/1213, read-back mismatch, node ยังไม่มี credentials; fail ทันที: 1045 (`node_auth_failed` — ยังไม่ re-pull credentials อัตโนมัติ), validation/capability
- `php artisan dev` รัน `queue:listen --queue=provisioning,default` และ `schedule:work` ด้วย
- **ทดสอบบน node lab (20 ต.ค. 2026):** tenant Demo Company 1 prefix 20, extension 20101 และ 20102 → operation `applied` ใน attempt แรก, read-back ตรง; **โทรศัพท์จริง register และโทร 20101↔20102 ได้แล้ว** — ยังต้องทดสอบ: โทรข้าม tenant ถูกปฏิเสธ (สร้าง tenant prefix 21), ปิด extension แล้ว register/โทรเข้าไม่ได้
- **บทเรียนจากการทดสอบ:** (1) ถ้า relay/worker ไม่รัน การหมุนรหัสค้างใน outbox แต่หน้าเว็บแสดงรหัสใหม่แล้ว → โทรศัพท์ใช้รหัสที่ node ยังไม่มี; (2) fail2ban (5 ครั้ง/10 นาที → แบน 1 ชม.) แบน IP ผู้ทดสอบเร็วมากเพราะ Yealink ส่ง REGISTER ซ้ำหลายครั้งต่อการลอง 1 ครั้ง; (3) รหัสผ่าน SIP ไม่ใช้ตัว `I l 1 O o 0` แล้ว (พิมพ์ลงโทรศัพท์ง่ายขึ้น)
- 153 tests ผ่าน, Larastan/vue-tsc/lint/build ผ่าน
- ยังไม่ทำ: voicemail (`voicemail_enabled`), AMI read-back (`dialplan show`), API v1 + Idempotency-Key, หน้า Operations, reconciliation (M6), ring group (D5), กดเลขสั้น 3 หลัก, ปิด `direct_dial` บน node (ทำใน SIP Deployer ก่อน M5)

- คำเตือนเมื่อการเปลี่ยนแปลงค้างเกิน 30 วินาที (`ProvisioningHealth`): แยก relay/scheduler กับ queue worker; platform admin เห็นคำสั่งที่ต้องรัน, ผู้ใช้ tenant เห็นข้อความทั่วไป

### ถัดไป
ทดสอบ ring group ด้วยโทรศัพท์ → voicemail → AmiClient + read-back ผ่าน AMI → API v1 (M5)
