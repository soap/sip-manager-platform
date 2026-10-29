# Telephony Management App — แผนพัฒนา v0.3

อัปเดตล่าสุด: 29 ต.ค. 2026 (§4, §6.2, §8, §9, §12 ตรงกับโค้ดหลัง D6/D7; §2 D1 เก็บไว้เป็นประวัติ)
อ้างอิง: _Telephony Platform Architecture — DigitalOcean Pilot v1.1_ (17 ต.ค. 2026) และโค้ด SIP Deployer ณ commit `72ef432`
ขอบเขตแผนนี้: **Phase 2 (tenant/extension/device/provisioning) และ Phase 3 (CDR/events/monitoring) แบบละเอียด**, Phase 4–5 เป็นโครงเท่านั้น

---

## 0. ข้อตัดสินใจ (17 ต.ค. 2026)

| เรื่อง            | ตัดสินใจ                                                                                                       |
| ----------------- | -------------------------------------------------------------------------------------------------------------- |
| แอป               | Laravel 13 แยกจาก SIP Deployer — Modular Monolith (`app/Modules/*`) ตาม §9                                     |
| UI                | **Inertia 3 + Vue 3 + TypeScript + Tailwind 4** (Laravel Vue starter kit, Wayfinder) + REST API `/api/v1` (Sanctum) |
| Database          | PostgreSQL 16+ (desired state, CDR สำเนา, audit) — CI ใช้ PostgreSQL 17, tests ใช้ SQLite in-memory           |
| Async             | Transactional Outbox + queue `provisioning`; ตอนนี้ใช้ database queue — Redis + Horizon เมื่อ deploy (Horizon ต้องใช้ ext-pcntl) |
| Node manifest     | **Pull**: Management App เรียก API ของ SIP Deployer ด้วย token แบบอ่านอย่างเดียว                               |
| การเชื่อมต่อ node | MariaDB (`opensips`, `asterisk`), AMI, ARI เท่านั้น — **ไม่มี SSH, ไม่เขียนไฟล์**                              |
| Tests             | Pest 4, Laravel Boost (เหมือน SIP Deployer)                                                                    |

---

## 1. Contract จริงจากโค้ด SIP Deployer (ยืนยันแล้ว)

| ช่องทาง             | ค่าจริงในโค้ด                                                                                                                                                                 | ที่มา                                          |
| ------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ---------------------------------------------- |
| OpenSIPS DB         | db `opensips`, user **`manager`@'%'** — `SELECT, INSERT, UPDATE, DELETE` ทั้ง db                                                                                              | `CreateOpenSipsDatabase`                       |
| Asterisk DB         | db `asterisk`, user **`asterisk_manager`@'%'** — `SELECT, INSERT, UPDATE, DELETE` ทั้ง db; schema จาก Alembic (`config`, `cdr`)                                               | `CreateAsteriskDatabase`                       |
| AMI                 | TCP **5038**, user `manager`, permit 127.0.0.1 + management CIDRs                                                                                                             | `AsteriskConfig::manager()`                    |
| ARI                 | **HTTP 8088** (ไม่มี TLS), user `manager`                                                                                                                                     | `AsteriskConfig::http()/ari()`                 |
| Bind                | `127.0.0.1` + private IP (VPC) และ/หรือ public IP ตามชนิดของ management CIDR                                                                                                  | `Server::managementBindAddresses()`            |
| OpenSIPS transports | **UDP/TCP 5060 เท่านั้น** (ยังไม่มี TLS/WSS)                                                                                                                                  | `OpenSipsConfig::DEFAULTS`                     |
| Auth                | `www_authorize/proxy_authorize("", "subscriber")` → realm = โดเมนใน From (hostname ของ server); ตาราง `subscriber` เก็บ **ha1 เท่านั้น**                                      | `opensips.cfg`, `TestSubscribers`              |
| Domain              | ไม่มี `domain` module — `is_myself()` = hostname/IP ของ node; **หนึ่ง SIP domain ต่อ node**                                                                                   | `opensips.cfg`                                 |
| usrloc              | `single-instance-sql-write-back` → ตาราง `location` ตามหลัง memory ได้ถึง ~1 รอบ timer (ค่า default 60s)                                                                      | `opensips.cfg`                                 |
| Routing             | INVITE จากโทรศัพท์ที่ auth แล้ว → **Asterisk ทุกสาย** (127.0.0.1:5080); Asterisk โทรกลับหาโทรศัพท์ด้วย `Dial(PJSIP/<user>@opensips)` → OpenSIPS `lookup("location")`          | `opensips.cfg`, `AsteriskConfig::extensions()` |
| Dialplan            | `[from-opensips]` → `*43` echo, `switch => Realtime/managed@extensions` (**context เดียว `managed`**), แล้ว `include => direct-dial` (เฉพาะ lab/pilot; realtime rows ชนะเสมอ) | `AsteriskConfig::extensions()`                 |
| Realtime tables     | `ps_endpoints, ps_auths, ps_aors, ps_domain_aliases, ps_endpoint_id_ips, ps_registrations, extensions`, + `voicemail` และ `queues, queue_members, queue_rules` ตาม feature    | `AsteriskConfig::extconfig()`                  |
| CDR                 | `cdr_adaptive_odbc` → ตาราง `cdr` ใน db `asterisk` (`cdr_csv` ปิด)                                                                                                            | `AsteriskConfig::files()`                      |
| Feature groups      | `direct_dial`(test), `ami`, `ari`, `realtime`, `voicemail`, `queues` (default on); `conference`, `recording` (default off)                                                    | `AsteriskConfig::FEATURES`                     |
| Media               | rtpengine 10000–20000 (public), Asterisk RTP 30000–30999 (local)                                                                                                              | `OpenSipsConfig`, `AsteriskConfig`             |

### 1.1 สิ่งที่ต้องแก้ในเอกสาร

- `design/sip-deployer-design.md` v0.2 ล้าสมัยหลายจุด (RTP range, ARI 8089/HTTPS, user `sipmanager`, TLS/WSS, Asterisk "minimal + *_custom.conf", routing user↔user) — ควรอัปเดตให้ตรงกับโค้ด
- v1.1 §3 diagram เขียน OpenSIPS → Asterisk `127.0.0.1:5080` ถูกต้อง แต่ฝั่ง OpenSIPS ใช้ socket `udp:127.0.0.1:5060` ภายใน
- v1.1 §8 ขั้นที่ 6 / §11 พูดถึง "AMI reload" — ในทางปฏิบัติ realtime (`pbx_realtime`, `auth_db`, `voicemail`, sorcery realtime) **อ่านจาก DB ตอนใช้งาน** จึงไม่ต้อง reload ส่วนใหญ่ (ดู §6.3)

---

## 2. ผลต่อการออกแบบที่สำคัญ (ต้องตัดสินใจ)

**D1 — Multi-tenant บน node เดียวยังทำไม่ได้กับ base config ปัจจุบัน** — _ถูกแทนที่ด้วย D6 (21 ต.ค. 2026); เก็บไว้เป็นประวัติ_
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

**D6 — หนึ่ง SIP domain ต่อ tenant (ตัดสินใจ 21 ต.ค. 2026, แทน D1)**

เหตุผล: เลข extension อิสระต่อ tenant (`1001` ซ้ำกันได้ระหว่าง tenant), กันข้าม tenant ด้วยโครงสร้างแทนการตรวจ caller ID, ย้าย tenant ข้าม node ได้โดยไม่เปลี่ยนเบอร์, ไม่มีเพดาน ~70 tenant ต่อ node, กดเลขสั้นได้ทันที — อ้างอิงหลักการจาก `docs/opensips36_multitenant_voip_architecture.md` ยกเว้น 2 ข้อ: **ไม่ใช้โหมด `direct`** (ทุกสายยังผ่าน Asterisk เพื่อ dialplan/CDR) และ **ไม่ทำ policy resolver ใน OpenSIPS** (policy compile เป็นแถว dialplan; ไม่มีแถว = โทรไม่ได้)

_ตัวตน_

- โดเมน = `<slug>.sip.example.com` (suffix ตั้งใน config), กำหนดตอน assign node แล้ว **ไม่เปลี่ยนตาม slug** (เปลี่ยนแล้วโทรศัพท์หลุด); DNS: wildcard `*.sip.example.com` → node pilot (หลาย node = record ต่อ tenant ผ่าน DNS API — ภายหลัง)
- `tenant_code` 4 หลัก `1000`–`9999` ไม่ซ้ำทั้งระบบ, ไม่เปลี่ยน, ไม่นำกลับมาใช้ — ใช้ทั้งเป็น namespace ภายใน Asterisk และเป็นรหัสโทรข้าม tenant
- SIP username = เลข extension, realm = โดเมนของ tenant → `ha1 = md5(1001:acme.sip.example.com:pass)` (คำนวณตอน apply จากรหัสที่เข้ารหัสไว้ตาม D4)
- เลข extension: 2–5 หลัก (5 หลักเพื่อให้ ring_all 10 คนยังอยู่ใน `appdata` 256 ตัวอักษร), ห้ามขึ้นต้นด้วย `0`, `8` (โทรข้าม tenant), `9` (สงวนโทรออก Phase 4), `*`, `#`; ห้าม 3 หลักที่ขึ้นต้นด้วย `1` (191, 199 ฯลฯ)

_OpenSIPS (SIP Deployer เป็นเจ้าของ)_

- `domain` module: ตาราง `domain` (`domain` = โดเมน tenant, `attrs` = `tenant_code`), **`db_mode = 0`** (query ทุกครั้ง — Management App สั่ง `domain_reload` ผ่าน MI ไม่ได้; ต้องยืนยันชื่อ param กับเอกสาร 3.6); `use_domain = 1` ใน `auth_db`, `usrloc`, `registrar`
- REGISTER: `is_domain_local($td, $var(code))` ไม่ผ่าน = 403; `www_authorize("$td", "subscriber")`; `$au == $tU`
- คำขอใหม่จากโทรศัพท์: `is_domain_local($fd, $var(code))` (ไม่ผ่าน = `403 Foreign caller forbidden` แทนตรรกะเดิม), `proxy_authorize("$fd", "subscriber")`, `$au == $fU`, แล้ว **`$rU = $var(code) + $rU`** → Asterisk — โทรศัพท์จึงไปถึงได้เฉพาะเลขใน namespace ของ tenant ตัวเอง (กด `10421001` ตรง ๆ จะกลายเป็น `<code ตัวเอง>10421001` ซึ่งไม่มี)
- ไม่ใช้ header `X-Tenant`: tenant อยู่ใน R-URI แล้ว (เชื่อถือได้เพราะ OpenSIPS เป็นผู้เติมจากโดเมนที่ authenticate) — ลดสิ่งที่ต้องลบจาก client
- จาก Asterisk ไปโทรศัพท์: R-URI `sip:<code><ext>@…` → `sql_select_one` หาโดเมนจาก `attrs = $(rU{s.substr,0,4})` → `$rU` = ส่วนที่เหลือ, `$rd` = โดเมน → `lookup("location")`; ไม่พบโดเมน = 404 — Asterisk จึงไม่ต้องรู้จักโดเมน และ `Dial()` ยังสั้น
- `route[disabled_account]` ใช้ `$au`/`$ar` ต่อได้ (ตอนนี้ `$ar` = โดเมน tenant)
- `[from-opensips]`: `*43` เปลี่ยนเป็น `_XXXX*43` (ไม่ตั้ง `accountcode`: switch ทำงานก่อน priority ใดๆ — tenant ของ CDR = 4 หลักแรกของ `dst`)
- test subscribers ที่ใช้ hostname ของ node: เลิกใช้ หรือให้ hostname เป็นโดเมน lab ใน `domain`

_Asterisk (Management App compile ลง `managed` เดียวเหมือนเดิม)_

- exten = `<code><ext>` เช่น `10421001`; **ไม่มี `GotoIf` ตรวจ prefix ผู้โทรแล้ว** (ไม่พึ่ง caller ID เพื่อแยก tenant; `route[untrusted_identity]` ยังคงไว้)
- `Dial(PJSIP/<code><ext>@opensips,30)`; voicemail context `t<code>`, mailbox = `<ext>`
- `*97` ต่อ tenant: exten `<code>*97` → `VoiceMailMain(${CALLERID(num)}@t<code>)` (แทน row ร่วมเดิม); `denied` ร่วมเหมือนเดิม
- ring group/failover `Goto(<code><n>,1)`

_โทรข้าม tenant_ (สิทธิ์ต่อ extension ดู D7)

- ผู้ใช้กด `8` + `tenant_code` ปลายทาง + extension เช่น `810421001`; โทรถึง **ทุกเบอร์** ของปลายทาง เมื่อผ่านทั้งสองฝั่ง:
    - **ต้นทาง:** extension ผู้โทรมี calling profile ที่เปิด `cross_tenant` (D7) — tenant admin ของต้นทางกำหนด
    - **ปลายทาง:** tenant admin ของปลายทางตั้ง `tenants.cross_tenant_inbound` = `none` (default) / `all` / `selected` (+ ตาราง `tenant_inbound_allowlist` (tenant_id, source_tenant_id)) — กันไม่ให้ใครก็ได้โทรเข้ามา
- ไดเรกทอรี: tenant ที่รับสายจาก A (`all` หรืออยู่ใน allowlist) แสดงให้ admin/ผู้ใช้ของ A เห็นพร้อมรหัส `8<code>`
- compile ต่อ tenant ต้นทาง (target `calling:<code>`): pattern เดียว `_<src>8XXXX.` + marker `<src>x<dst>` ต่อปลายทางที่รับเรา — รายละเอียดใน §12 "D7" (แผนเดิม `_<src>8<dst>.` + `_<src>8.` ใช้ไม่ได้ เพราะ pbx_realtime ไม่เลือก pattern ที่ยาวที่สุด); ปลายทางเห็น `8<src><caller>` และกดกลับได้ถ้ามีสิทธิ์ย้อนกลับ
- ปลายทางเปลี่ยนการรับสาย = recompile `calling:<code>` ของต้นทางที่ได้รับผล; ปลายทางถูก suspend = แถวของปลายทางเป็น `denied` อยู่แล้ว
- MVP เฉพาะ tenant บน node เดียวกัน — ต่าง node ต้องมี trunk ระหว่าง node (Phase 4); tenant ต่าง node ไม่แสดงในไดเรกทอรี

_กระทบ sip-manager_

- `tenants`: + `tenant_code`, `sip_domain` (unique); ลบ `tenant_number_ranges` + UI prefix ใน `/admin/nodes`; `domain` row ต่อ tenant ต่อ node (compile target `domain:<code>`; ลบเมื่อเอา tenant ออกจาก node)
- `extensions`, `dial_numbers`, `ring_groups`: unique `(tenant_id, number)` (เลิก unique ต่อ node); `config_revisions` target = `number:<code><ext>`; `StoreExtensionRequest`/`NumberAllocator` ตามกติกาเลขใหม่ (ไม่มี prefix; แนะนำเลขว่างถัดไป เริ่ม 201 / ring group 500 — 1xx ใช้ไม่ได้ตามกติกาเลข)
- `NumberCompiler`: เลิก `callerCheck`/`range`, `sipDomain()` = โดเมน tenant, routing key, `*97` ต่อ tenant
- `OpenSipsDbAdapter`: subscriber แทนที่ด้วย `(username, domain)` — **ห้ามลบ username เดียวกันในโดเมนอื่นแบบตอนนี้** (จะลบ `1001` ของ tenant อื่น); + replace/read `domain`; `registrations()` กรองด้วย `(username, domain)`
- `sip_credential` ใน API/หน้าเว็บ: domain = โดเมน tenant (+ `proxy` = hostname ของ node สำหรับโทรศัพท์ที่โดเมนยังไม่มีใน DNS); CDR (P3): tenant จาก 4 หลักแรกของ `dst`

_ลำดับงานและเกณฑ์ผ่าน_

1. **SIP Deployer** opensips.cfg + `[from-opensips]` + `OpenSipsConfigTest` → reinstall node lab, `opensips -C` ผ่าน
2. **sip-manager ข้อมูล:** migration (`tenant_code`, `sip_domain`, unique ใหม่, ลบ `tenant_number_ranges`), กติกาเลข, หน้า assign node
3. **Compile/apply:** domain row, subscriber ตามโดเมน, exten `<code><ext>`, voicemail, ring group, registrations → tests ใหม่ของ compiler/adapter
4. **ย้ายข้อมูล lab:** tenant ทดสอบ (prefix 20, 30) ลบ rows เดิมบน node แล้วสร้างใหม่ (ไม่ต้อง migrate แบบรักษาข้อมูล), ตั้งโทรศัพท์ใหม่ด้วยโดเมน tenant
5. **Calling profiles + โทรข้าม tenant (D7):** profiles, การรับสายข้าม tenant, ไดเรกทอรี, compile `<code>p<ext>` และ `calling:<code>` — หมวดโทรออกบันทึกได้แต่ compile เป็น `denied` จนกว่าจะมี trunk (Phase 4)

- เกณฑ์ผ่าน: 2 tenant มี `1001` ทั้งคู่ register ได้พร้อมกัน; โทรภายใน tenant ได้; กด `8<code>1001` จาก extension ที่ไม่มีสิทธิ์ หรือไป tenant ที่ไม่รับ = ได้ยิน `denied`; มีสิทธิ์ทั้งสองฝั่ง = ดัง และปลายทางเห็น `8<code>1001`; 1001 ของ A โทรข้ามได้ แต่ 1002 (profile ภายใน) ไม่ได้; กดเลขภายในของ tenant อื่นตรง ๆ ไม่ถึง; REGISTER credentials ของ A ที่โดเมน B = 403; extension ที่ปิดยังได้ `403 Account disabled`; voicemail/`*97`/ring group ยังทำงาน

**D7 — สิทธิ์การโทรต่อ extension: calling profiles (ตัดสินใจ 21 ต.ค. 2026)**

tenant admin กำหนดเองว่า extension ไหนโทรไปที่ใดได้ (เช่น 1001 โทรข้าม tenant ได้, 1002 ไม่ได้) — ชุดเดียวกันใช้กับโทรข้าม tenant และโทรออก mobile/PSTN (Phase 4) แทน `outbound_policy` เดิม (§11 ข้อ 7)

_หมวดปลายทาง_ (ผู้ใช้กดเลขตรง ๆ ไม่ต้องกด 9 นำ — เลข extension ไม่ขึ้นต้นด้วย 0 จึงไม่ชน)

| หมวด             | flag | pattern หลัง `<code>`                                             | หมายเหตุ                                                                    |
| ---------------- | ---- | ----------------------------------------------------------------- | --------------------------------------------------------------------------- |
| ภายใน tenant     | —    | เลข extension                                                     | เปิดเสมอ                                                                    |
| ฉุกเฉิน          | —    | ตาราง `emergency_numbers` (เริ่มที่ `191`, `199`, `1669`, `1155`) | **เปิดเสมอ** เมื่อมี trunk; ไม่มี trunk = ต้องแจ้งลูกค้าว่าโทรฉุกเฉินไม่ได้ |
| ข้าม tenant      | `x`  | `8<dst>.`                                                         | ทุก tenant ที่รับเรา หรือเฉพาะที่เลือกใน profile + เงื่อนไขฝั่งปลายทาง (D6) |
| พื้นฐาน/ในประเทศ | `l`  | `0[2-7]XXXXXXX`                                                   |                                                                             |
| มือถือ           | `m`  | `0[689]XXXXXXXX`                                                  |                                                                             |
| โทรฟรี           | `f`  | `1800XXXXXX`                                                      |                                                                             |
| hotline 4 หลัก   | `h`  | `91XXX`                                                           | กด `9` นำ เพราะ `1xxx` ชนกับ extension 4 หลักได้                            |
| ต่างประเทศ       | `i`  | `00X.`                                                            | default ปิด                                                                 |
| พรีเมียม         | `p`  | `1900XXXXXX`                                                      | default ปิด                                                                 |

_ระดับสิทธิ์ต่อหมวด (ตัดสินใจ 29 ต.ค. 2026)_ — แต่ละหมวดใน profile เป็นหนึ่งใน 3 ระดับ:

| ระดับ   | ความหมาย                                                                          | flag ใน `Return()`         |
| ------- | --------------------------------------------------------------------------------- | -------------------------- |
| `allow` | โทรได้เลย                                                                         | ตัวเล็ก เช่น `m`, `xa`     |
| `code`  | โทรได้เมื่อกดรหัสอนุญาตที่ปลดหมวดนั้น (overrule — ดู _รหัสอนุญาต_ ด้านล่าง)        | ตัวใหญ่ เช่น `M`, `X1042`  |
| `deny`  | ห้ามเด็ดขาด — ไม่ถามรหัส แม้ tenant มีรหัสที่ปลดหมวดนั้น (เช่น โทรศัพท์ล็อบบี้ห้ามโทรต่างประเทศ) | ไม่มี flag             |

หมวดที่ไม่ระบุใน profile = `deny`

_ข้อมูล_

- `calling_profiles` (tenant_id, name, categories jsonb = map หมวด → ระดับ เช่น `{"x": "allow", "l": "allow", "m": "code", "i": "deny"}`, is_default; unique (tenant_id, name)) — สร้างให้ตอนสร้าง tenant: "ภายในเท่านั้น" (default; ทุกหมวด `deny`), "ภายใน + รหัส" (x l m f h = `code`), "ข้าม tenant", "ในประเทศ" (x l m f h = `allow`, i = `code`), "ทั้งหมด"; tenant admin สร้าง/แก้/ลบได้ (ลบได้เมื่อไม่มี extension ใช้)
- ขอบเขตข้าม tenant ต่อ profile: `cross_tenant_scope` = `all` (ทุก tenant ที่รับเรา) / `selected` + `calling_profile_destinations` (calling_profile_id, destination_tenant_id; สูงสุด 30 tenant ต่อ profile เพราะต้องอยู่ใน `Return()` 256 ตัวอักษร) — เช่น profile "คู่ค้า" โทรได้เฉพาะ tenant 1042 กับ 1050; ขอบเขตเดียวกันใช้กับระดับ `allow` และ `code` ของหมวดข้าม tenant
- `extensions.calling_profile_id` (ไม่ระบุ = default ของ tenant); เปลี่ยน profile ได้จากหน้า Extensions และ API (`PATCH extensions/{id}`)
- `emergency_numbers` (number, label, active) — platform admin เพิ่ม/ปิดได้ภายหลัง; เปลี่ยน = recompile `calling:<code>` ของทุก tenant (ทีละ node); เลขฉุกเฉินชนะเลข extension เสมอ (validate ตอนสร้าง extension, และเพิ่มเลขฉุกเฉินที่ชนกับ extension ที่มีอยู่ = แจ้งรายการ tenant ที่ต้องเปลี่ยนเบอร์ก่อน)
- ภายหลัง (Phase 4): limit ต่อ profile/tenant — สายพร้อมกัน, ช่วงเวลา, วงเงิน, caller ID ที่ส่งออก (DID ของ tenant)

_Compile_

- ผู้โทรระบุจาก `CALLERID(num)` = From user ที่ OpenSIPS บังคับให้ตรงกับผู้ authenticate (`$au == $fU`) และไม่มี PAI จาก client (`route[untrusted_identity]`) — ข้อเชื่อถือเดียวกับ D1 เดิม แต่ใช้เฉพาะเรื่องสิทธิ์ ไม่ใช่เพื่อแยก tenant
- ต่อ extension active หนึ่ง exten สิทธิ์ `<code>p<ext>` (อยู่ใน target `number:<code><ext>` ของ extension): 1 `Return(<flags>)` คั่นด้วย `/` — ตัวเล็ก = `allow`, ตัวใหญ่ = `code` เช่น `Return(/l/M/xa/)` (พื้นฐานได้เลย, มือถือต้องใช้รหัส, ข้าม tenant ทุกที่ที่รับ) หรือ `Return(/l/X1042/X1050/)` (ข้ามไป 1042/1050 ได้ด้วยรหัส); extension ที่ปิด = ไม่มีแถว
- ต่อหมวดของ tenant (target `calling:<code>`): exten `_<code><pattern>` →
    1. `Gosub(<code>p${CALLERID(num)},1)`
    2. `GotoIf($[${REGEX("/<flag>/" ${GOSUB_RETVAL})}]?5)` — มีสิทธิ์ `allow` ข้ามไปโทรเลย (ข้าม tenant ไป `<dst>` ใช้ `"/(xa|x<dst>)/"`)
    3. `GotoIf($[${REGEX("/<FLAG>/" ${GOSUB_RETVAL})}]?:denied,1)` — ไม่ใช่ `code` = denied (ข้าม tenant ใช้ `"/(XA|X<dst>)/"`)
    4. `Gosub(<code>auth,1(<flag>))` — กลับมาเฉพาะเมื่อรหัสถูกและปลดหมวดนี้ได้ ไม่เช่นนั้นไป denied ใน subroutine
    5… Dial ออก trunk (Phase 4) หรือ Goto ข้าม tenant
- priority 3–4 มีเฉพาะเมื่อ tenant มีรหัสอนุญาต active ที่ปลดหมวดนั้นได้ — ไม่มี = priority 3 เป็น `Goto(denied,1)` (ไม่ถามรหัสที่ไม่มีทางถูก)
- fail closed: ผู้โทรไม่มีแถวสิทธิ์ → `Gosub` ล้ม = วางสาย; ยังไม่มี trunk = หมวดโทรออกเป็น `Goto(denied,1)` ตั้งแต่ priority 1 (ไม่ถามรหัส)
- กด `p1001` ผ่าน URI ได้แถว `<code>p1001` ซึ่งแค่ `Return` → ไม่มีผล (ถ้าต้องการ: priority 1 ตรวจว่าถูกเรียกผ่าน Gosub)
- แก้ profile (หมวด หรือรายการ tenant ปลายทาง) = recompile extension ทุกตัวที่ใช้ profile นั้น (ทีละ revision ต่อ node ตามเดิม); ปลายทางเลิกรับเรา = recompile `calling:<code>` ของเรา (marker `<code>x<dst>` หายไป → denied) โดย profile ยังจำรายการไว้

_รหัสอนุญาต (overrule ด้วยรหัสผ่าน — ตัดสินใจ 29 ต.ค. 2026)_

ผู้ใช้โทรไปหมวดที่ profile ของเครื่องตั้งเป็น `code` → ได้ยิน "please enter your password" → กดรหัส + `#` → รหัสถูกและปลดหมวดนั้นได้ = โทรต่อ; ใช้ได้จากทุกเครื่องของ tenant (เช่น ครูใช้เครื่องห้องพักครูที่เป็น "ภายใน + รหัส" โทรมือถือผู้ปกครอง) — รหัสเป็นของ tenant ไม่ใช่ของ extension

- `authorization_codes` (tenant_id, label เช่น ชื่อเจ้าของ, `user_id` nullable, categories jsonb = รายการหมวดที่ปลดได้ (`x l m f h i p`), `node_hash`, status `active`/`disabled`, `expires_at` nullable, `version`) — ข้าม tenant ที่ปลดด้วยรหัส = ไปได้ทุก tenant ที่อยู่ในขอบเขตของ profile ผู้โทรและรับเรา (รหัสไม่ขยายขอบเขต)
- รหัส 6–12 หลัก unique ต่อ tenant; ระบบสุ่มให้ (default 8 หลัก) หรือ admin กำหนดเอง; **แสดงครั้งเดียว** เหมือนรหัส SIP — แอปไม่เก็บรหัสจริง เก็บแค่ `node_hash` = `md5(<tenants.auth_salt><รหัส>)` (salt สุ่มต่อ tenant ตอนสร้าง) ซึ่งพอสำหรับ compile และตรวจซ้ำ; ลืมรหัส = reset (ได้รหัสใหม่)
- compile อยู่ใน target `calling:<code>` (เพราะ priority 3–4 ของหมวดขึ้นกับว่ามีรหัสที่ปลดหมวดนั้นหรือไม่):
    - ต่อรหัส active ที่ยังไม่หมดอายุ: exten `<code>a<node_hash>` → 1 `Return(<id>/<flags>/)` เช่น `Return(17/l/m/x/)` — exten ยาว 4+1+32 = 37 ตัวอักษร (`extensions.exten` บน node กว้าง 40)
    - subroutine `<code>auth` (ARG1 = flag ที่ต้องการ):
        1. `Set(TRIES=0)`
        2. `Read(AUTHCODE,agent-pass,12,,1,8)`
        3. `Set(H=<code>a${MD5(<salt>${AUTHCODE})})`
        4. `GotoIf($[${DIALPLAN_EXISTS(${CONTEXT},${H},1)}]?:8)`
        5. `Gosub(${H},1)`
        6. `GotoIf($[${REGEX("/${ARG1}/" ${GOSUB_RETVAL})}]?:8)`
        7. `Set(CDR(userfield)=authcode:${CUT(GOSUB_RETVAL,/,1)})` แล้ว `Return()` (priority 7.1) — CDR (Phase 3) บอกได้ว่าใช้รหัสของใคร
        8. `UserEvent(AuthCodeFailed,tenant: <code>,caller: ${CALLERID(num)})`
        9. `Set(TRIES=$[${TRIES}+1])`
        10. `Playback(auth-incorrect)`
        11. `GotoIf($[${TRIES} < 3]?2:denied,1)`
    - (ลำดับ priority จริงเลื่อนตาม `Return()` หลังข้อ 7 — compiler นับให้ ข้อ 4/6 กระโดดไปข้อ 8)
    - ไม่ต้องแก้ SIP Deployer (ใช้ `managed` + sound ที่มากับ Asterisk: `agent-pass`, `auth-incorrect`)
- รหัสหมดอายุ: scheduler ทุกนาทีหา `expires_at` ที่ผ่านแล้ว → recompile `calling:<code>` ของ tenant นั้น
- ความปลอดภัย (toll fraud):
    - กดผิดได้ 3 ครั้งต่อสาย แล้ววางสาย; fail2ban มองไม่เห็น (ผ่าน auth SIP แล้ว) — Phase 3 AMI listener นับ `AuthCodeFailed` ต่อ caller/tenant แล้วแจ้งเตือน หรือปิด extension อัตโนมัติเมื่อเกินเกณฑ์ (เช่น 10 ครั้ง/ชั่วโมง)
    - `node_hash` เป็น md5 ของรหัสสั้น — ผู้ที่อ่าน DB ของ node ได้ถอดรหัสได้ไม่ยาก แต่ผู้นั้นแก้ dialplan ได้อยู่แล้ว; จุดประสงค์คือไม่ให้รหัสจริงปรากฏใน dialplan, log ของแอป, หรือ `config_revisions.compiled` (เก็บแค่ id ของรหัส ไม่เก็บ hash)
    - default ของต่างประเทศ/พรีเมียม = `deny` ใน profile ที่สร้างให้ทุกตัว ยกเว้น "ในประเทศ" (i = `code`) และ "ทั้งหมด"
    - `AUTHCODE` ไม่ถูกบันทึกลง CDR (`Read` เก็บในตัวแปรของ channel เท่านั้น)
- audit: `authorization_code.created/updated/reset/disabled/deleted` (ไม่มีรหัสหรือ hash ใน audit)

_Trunk PSTN (Phase 4 — มีทั้งสองแบบ)_

- `sip_trunks` (pbx_node_id, `tenant_id` nullable: มี = trunk ของ tenant, null = trunk กลางของ platform, name, provider, ps_* settings, status) → compile `ps_endpoints/ps_auths/ps_aors/ps_registrations/ps_endpoint_id_ips`
- trunk กลาง: platform admin เป็นเจ้าของ และอนุญาตให้ tenant ใช้ (`tenant_trunk_grants` (tenant_id, sip_trunk_id, priority)); tenant ไม่เห็น credential ของ trunk กลาง
- trunk ของ tenant: tenant admin สร้าง/แก้ได้เอง (หน้าเว็บ + API) — credential เข้ารหัส, แสดงครั้งเดียวเหมือน SIP password
- เส้นทางโทรออกต่อ tenant: รายการ trunk เรียงลำดับ (trunk ของ tenant ก่อน, trunk กลางที่ได้รับอนุญาตเป็นสำรอง) — compile เป็น `Dial(PJSIP/${EXTEN:4}@<trunk1>)` แล้ว `Dial(...@<trunk2>)` เมื่อ `DIALSTATUS` = CHANUNAVAIL/CONGESTION; ภายหลังเลือก trunk ต่อหมวดได้ (เช่น มือถือออก trunk A)
- caller ID ขาออก: DID ของ tenant บน trunk ที่ใช้ (trunk กลางต้องมี DID ที่ผูกกับ tenant); CDR แยก tenant ด้วย `accountcode` + ชื่อ trunk เพื่อคิดค่าใช้ trunk กลาง
- สายเข้าจาก trunk (DID) ยังเป็นงาน Phase 4 ตามเดิม — context แยก ไม่ผ่านตรวจสิทธิ์ผู้โทร

_API v1_ (ตามแบบ API v1 เดิม: token ผูก tenant, ทำในนาม user ด้วย role ณ ตอนนั้น — การเขียนต้องเป็น owner/admin และ token `read+write`; POST ใช้ Idempotency-Key; PATCH ใช้ `version`/`If-Match`; การเปลี่ยนที่ไปถึง PBX ตอบ `202` + `operation_id` (หลาย extension = หลาย operation → ตอบ `operation_ids`); audit ทุกครั้ง)

- `GET|POST calling-profiles`, `GET|PATCH|DELETE calling-profiles/{id}` — `categories` เป็น object ชื่อหมวด → ระดับ (`allow`/`code`/`deny`) เช่น `{"cross_tenant": "allow", "mobile": "code", "international": "deny"}` ชื่อหมวด (`cross_tenant`, `landline`, `mobile`, `toll_free`, `hotline`, `international`, `premium`) ไม่ใช่ flag ตัวอักษร; `cross_tenant_scope: {"scope": "all"|"selected", "tenants": ["1042", "1050"]}`; ลบ profile ที่ยังมี extension ใช้ = 409; ตั้ง `is_default` ได้ทีละ profile
- `GET|POST authorization-codes`, `GET|PATCH|DELETE authorization-codes/{id}`, `POST authorization-codes/{id}/reset` — `categories` เป็นรายชื่อหมวด; POST/reset คืนรหัสใน `authorization_code` **ครั้งเดียว** (POST ไม่ส่ง `code` = ระบบสุ่ม 8 หลัก); GET ไม่มีรหัส; รหัสซ้ำกับที่มีอยู่ = 422; ทุกการเปลี่ยน = `202` + `operation_id` ของ `calling:<code>`
- `GET|POST trunks`, `GET|PATCH|DELETE trunks/{id}` (Phase 4) — เฉพาะ trunk ของ tenant; trunk กลางแสดงใน `GET outbound-routes` แบบไม่มี credential; `PATCH outbound-routes` เรียงลำดับ trunk
- `PATCH extensions/{id}` รับ `calling_profile_id`; `GET extensions` คืน `calling_profile` และกรองด้วย `calling_profile_id` ได้
- `GET|PATCH cross-tenant` — การรับสายข้าม tenant ของ tenant นี้: `inbound` (`none`/`all`/`selected`) + `allowed_tenants` (รายการ `tenant_code`)
- `GET directory` — tenant ที่โทรถึงได้จาก tenant นี้ (ชื่อ, `tenant_code`, รหัสที่กด `8<code>`) — ไม่เปิดเผย tenant ที่ไม่รับ
- tenant อื่นอ้างอิงด้วย `tenant_code` (ไม่ใช่ ULID); code ที่ไม่มี = 422 — การลองเพิ่ม code บอกได้แค่ว่ามีอยู่ ไม่บอกชื่อ (ชื่อเห็นเฉพาะใน `directory` เมื่อปลายทางรับเรา) และอยู่ใต้ rate limit ของ token

_ตกลงแล้ว (21 ต.ค. 2026):_ trunk มีทั้งของ tenant และกลางของ platform; เลขฉุกเฉินเป็นตาราง เพิ่มได้ภายหลัง; profile จำกัดปลายทางข้าม tenant ได้ (`all` / `selected`)

_ตกลงแล้ว (29 ต.ค. 2026):_ สิทธิ์ผ่าน calling profiles (ไม่ใช่ checkbox ต่อ extension); หมวดมี 3 ระดับ `allow`/`code`/`deny`; overrule ด้วยรหัสอนุญาตของ tenant (ไม่ใช่ PIN ต่อ extension) — แต่ละรหัสมีเจ้าของ (label) และชุดหมวดที่ปลดได้

_ลำดับงาน D7 และเกณฑ์ผ่าน (node lab)_ — หมวดโทรออก PSTN/มือถือ/ต่างประเทศ ทดสอบจริงได้เมื่อมี trunk (Phase 4); รอบนี้ทดสอบผ่านหมวดข้าม tenant

1. ~~ยืนยันบน node lab ด้วยแถวที่เขียนมือ~~ — ผ่านแล้ว (29 ต.ค. 2026, Asterisk 22.11, tenant code ทดสอบ `0999` ลบแล้ว): `REGEX("/(xa|x1042)/" ${GOSUB_RETVAL})` ใน `Set`/`GotoIf` ของ realtime ใช้ได้ (`|` ไม่ถูกแปลง, ตัวพิมพ์ใหญ่/เล็กแยกกัน), `GotoIf(...?<priority>:denied,1)`, `Gosub` ไป exten ที่คำนวณ (`0999p${CALLERID(num)}`, `${H}`), `Read` + `MD5()` + `DIALPLAN_EXISTS`, ผิด 3 ครั้ง = `denied` (Hangup 21), รหัสถูก = CDR ของผู้โทรมี `userfield = authcode:17`; `extensions.exten` กว้าง 40, `cdr.userfield` 256, มี `agent-pass`/`auth-incorrect` — **`DIALPLAN_EXISTS` ต้องใช้ `${CONTEXT}` (= `from-opensips`) ไม่ใช่ `managed`** เพราะแถว realtime ทำงานใน context `from-opensips` ผ่าน switch; วิธีทดสอบ: `channel originate Local/<exten>@from-opensips/n extension <dtmf-exten>@from-opensips` โดยอีกขาทำ `SendDTMF`, ผลเขียนลง astdb (`Set(DB(...))`)
2. migration + models + factories: `calling_profiles`, `calling_profile_destinations`, `authorization_codes`, `tenants.cross_tenant_inbound` + `tenant_inbound_allowlist`, `tenants.auth_salt`, `extensions.calling_profile_id`; profile เริ่มต้นใน `CreateTenant`
3. compiler: `<code>p<ext>` ใน `number:`, target ใหม่ `calling:<code>` (หมวด + `<code>auth` + `<code>a<hash>`) ใน `NumberCompiler`/adapter/read-back + tests
4. actions + API + audit (profiles, codes, cross-tenant, directory) + tests
5. หน้าเว็บ: "สิทธิ์การโทร" (profiles เป็นตาราง หมวด × ระดับ, รหัสอนุญาต), เลือก profile ในหน้า Extensions, ตั้งค่าการรับสายข้าม tenant

- เกณฑ์ผ่าน: profile ข้าม tenant = `allow` → ดังโดยไม่ถามรหัส; `code` → ถามรหัส: รหัสผิด 3 ครั้ง = denied, รหัสที่ไม่ปลด `x` = denied, รหัสที่ปิดหรือหมดอายุ = denied, รหัสถูก = ดัง และ CDR มี `authcode:<id>`; `deny` → denied ทันทีแม้มีรหัส; tenant ไม่มีรหัสที่ปลด `x` → denied โดยไม่ถามรหัส; ปลายทางไม่รับเรา → denied แม้รหัสถูก

_เลื่อนไป Phase 4 (21 ต.ค. 2026):_ การคิดค่าใช้ trunk กลาง (แค่รายงานจาก CDR หรือมีวงเงิน/ตัดสาย) — ระหว่างนี้แค่เก็บ `accountcode` + ชื่อ trunk ใน CDR ให้พร้อม

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

ตามโค้ด ณ 29 ต.ค. 2026 (หลัง D6/D7) — แถวที่มี _(แผน)_ ยังไม่มีตาราง

```
app/Modules/
  Tenancy/        Tenant, TenantMembership, ApiToken, TenantContext + BelongsToTenant, SetTenantContext / SetApiTenant, policies
  Telephony/      Extension, DialNumber, SipCredential, RingGroup, CallingProfile, AuthorizationCode, NumberAllocator, ExtensionRegistrations
  Provisioning/   ConfigRevision, ProvisioningOperation, OutboxMessage, Compiler/ (NumberCompiler, CallingCompiler), ApplyRevision, ProvisioningHealth
  Nodes/          PbxNode, NodeSecret, TenantPbxAssignment, SipDeployer (client), NodeConnections, CheckNodeConnections
  Infrastructure/ OpenSips/OpenSipsDbAdapter, Asterisk/RealtimeAdapter, Asterisk/AmiClient + AmiAdapter
  Audit/          AuditLog, Audit::record() + redaction
  (แผน) CallRecords/  CallRecord, CallEvent, CdrSync — Phase 3
```

| ตาราง                                | คอลัมน์หลัก                                                                                                                                                                                                                       |
| ------------------------------------ | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| `tenants`                            | id (ulid), name, slug (unique), `code` 4 หลัก (unique, 1000–9999), `sip_domain` (unique), status, `cross_tenant_inbound` (`none/all/selected`), `auth_salt`, settings json                                                          |
| `tenant_memberships`                 | tenant_id, user_id, role (`owner/admin/member`); unique (tenant_id, user_id)                                                                                                                                                        |
| `tenant_inbound_allowlist`           | tenant_id, source_tenant_id — ใช้เมื่อ `cross_tenant_inbound = selected`                                                                                                                                                            |
| `pbx_nodes`                          | deployer_server_id (unique), manifest_version, name, hostname, sip_domain, environment, status, region, addresses/services/endpoints/dialplan json, credentials_version, connection_checks, checked_at, synced_at, removed_at       |
| `node_secrets`                       | pbx_node_id, kind (`opensips_db/asterisk_db/ami/ari`), username, secret (encrypted), fetched_at, rotated_at; unique (pbx_node_id, kind)                                                                                             |
| `tenant_pbx_assignments`             | tenant_id (unique — หนึ่ง node ต่อ tenant), pbx_node_id, status (`active/suspended`)                                                                                                                                                |
| `extensions`                         | tenant_id, pbx_node_id, extension_number (2–5 หลัก), display_name, status, voicemail_enabled, voicemail_pin (encrypted), calling_profile_id (null = default), `version`; unique (tenant_id, extension_number)                       |
| `dial_numbers`                       | tenant_id, pbx_node_id, number, owner morph (extension / ring group); unique (tenant_id, number) — ทะเบียนเบอร์กลาง (D5)                                                                                                             |
| `sip_credentials`                    | tenant_id, extension_id (unique), pbx_node_id, sip_username, secret (encrypted, D4), rotated_at; unique (tenant_id, sip_username) — ha1 คำนวณตอน apply                                                                             |
| `ring_groups` / `ring_group_members` | number, name, strategy, ring_seconds, failover_type/id, status, version; สมาชิก (ring_group_id, extension_id, position)                                                                                                             |
| `calling_profiles`                   | tenant_id, name, categories json (หมวด → `allow/code`), cross_tenant_scope (`all/selected`), is_default, version; unique (tenant_id, name)                                                                                          |
| `calling_profile_destinations`       | calling_profile_id, destination_tenant_id — ใช้เมื่อ `cross_tenant_scope = selected`                                                                                                                                                |
| `authorization_codes`                | tenant_id, label, user_id (nullable), categories json, `node_hash` = md5(auth_salt + รหัส), status, expires_at, version; unique (tenant_id, node_hash)                                                                               |
| `config_revisions`                   | tenant_id, pbx_node_id, revision_number (unique ต่อ node), target (`number:<code><n>` / `domain:<code>` / `calling:<code>`), aggregate, checksum, compiled json (ไม่มี secret), status, validation_report, apply_result            |
| `provisioning_operations`            | id (ulid = operation_id), tenant_id, type, aggregate, idempotency_key (unique ต่อ tenant), config_revision_id, status (`pending/running/applied/retrying/failed/superseded`), attempts, error_code, error_message, next_attempt_at |
| `outbox_messages`                    | topic, payload json, available_at, dispatched_at                                                                                                                                                                                     |
| `idempotency_keys`                   | tenant_id, key, request_hash, response_status, response_body, expires_at (24 ชม.)                                                                                                                                                    |
| `personal_access_tokens`             | Sanctum + `tenant_id` (token ผูก tenant เดียว)                                                                                                                                                                                       |
| `audit_logs`                         | tenant_id, actor_id, action, subject type/id, summary json (redacted), ip_address, created_at                                                                                                                                        |
| _(แผน)_ `devices`                    | tenant_id, extension_id, vendor/model, mac, provisioning_profile, status                                                                                                                                                             |
| _(แผน)_ `emergency_numbers`          | number, label, active (D7)                                                                                                                                                                                                           |
| _(แผน P3)_ `call_records`            | tenant_id, pbx_node_id, uniqueid, linkedid, sequence, direction, src, dst, start/answer/end, duration, billsec, disposition, raw json; unique (pbx_node_id, uniqueid, sequence)                                                      |
| _(แผน P3)_ `call_events`             | pbx_node_id, linkedid, uniqueid, event, payload json (redacted), occurred_at, dedupe_hash unique                                                                                                                                     |
| _(แผน P3)_ `registration_snapshots`  | pbx_node_id, sip_username, contact, user_agent, received, expires_at, seen_at — ตอนนี้อ่าน `opensips.location` สดแทน                                                                                                                |

กฎ: `tenant_id` มาจาก `TenantContext` เท่านั้น, global scope แบบ strict + policy ทุก model, FK/unique จริง, RLS เป็นทางเลือกภายหลัง

---

## 5. Node Manifest (Pull) — งานที่ต้องเพิ่มใน SIP Deployer

SIP Deployer ยังไม่มี API (มีแค่ `routes/web.php`) ต้องเพิ่ม:

- `GET /api/v1/nodes` และ `GET /api/v1/nodes/{id}` — Sanctum token ability `nodes:read`, จำกัด IP ต้นทาง
- `GET /api/v1/nodes/{id}/credentials` — ability แยก `credentials:read`, log ทุกครั้ง (ไม่ log ค่า), คืน secret ผ่าน HTTPS เท่านั้น

ตัวอย่าง manifest:

```json
{
    "id": 7,
    "name": "sip-lab-1",
    "hostname": "sip1.example.com",
    "sip_domain": "sip1.example.com",
    "environment": "lab",
    "status": "active",
    "addresses": {
        "public": "203.0.113.10",
        "private": "10.104.0.5",
        "management": "10.104.0.5"
    },
    "os": "ubuntu-24.04",
    "services": {
        "opensips": {
            "release": "3.6",
            "status": "installed",
            "transports": ["udp", "tcp"],
            "sip_port": 5060
        },
        "asterisk": {
            "release": "22",
            "version": "22.11.0",
            "status": "installed",
            "features": ["ami", "ari", "realtime", "voicemail", "queues"]
        }
    },
    "endpoints": {
        "opensips_db": {
            "host": "10.104.0.5",
            "port": 3306,
            "database": "opensips",
            "username": "manager"
        },
        "asterisk_db": {
            "host": "10.104.0.5",
            "port": 3306,
            "database": "asterisk",
            "username": "asterisk_manager"
        },
        "ami": { "host": "10.104.0.5", "port": 5038, "username": "manager" },
        "ari": {
            "host": "10.104.0.5",
            "port": 8088,
            "scheme": "http",
            "username": "manager"
        }
    },
    "dialplan": {
        "entry_context": "from-opensips",
        "managed_context": "managed",
        "backend_endpoint": "opensips"
    },
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
5. **Validate** — capability ของ node (เช่น voicemail เปิดไหม, `dialplan.tenant_routing = domain`), node ยังไม่ถูกลบ; กติกาเลข (`NumberAllocator::PATTERN`) และเลขซ้ำตรวจตอนรับคำขอแล้ว
6. **Apply** — diff กับ rows ปัจจุบันของ node แล้ว upsert/delete ภายใน transaction ต่อ db (opensips และ asterisk แยก transaction; ทำ opensips หลังสุดเพื่อไม่ให้โทรศัพท์ register ได้ก่อน dialplan พร้อม)
7. **Read-back** — SELECT rows กลับมาเทียบ checksum + ตรวจผ่าน AMI (§6.3)
8. ผล: `applied` / `retrying` (backoff 10s, 30s, 2m, 10m, 30m) / `failed` + alert

ทุก row ที่ Management App เขียนต้องระบุตัวตนได้ — ใช้ prefix/คอลัมน์เพื่อให้ reconciler แยกจาก rows อื่น:

- `extensions`: context `managed`, ใส่ marker ใน `appdata` ไม่ได้ → เก็บ ownership map ใน PostgreSQL (`config_revisions.compiled`) แล้ว reconcile เฉพาะ rows ที่อยู่ใน map

### 6.2 Compile สำหรับ extension (ตาม D6/D7 — `NumberCompiler`)

ตัวอย่าง tenant code `1042`, โดเมน `acme.sip.example.com`, extension `101`:

- `opensips.domain` (target `domain:1042`, เขียนตอน assign node): `domain=acme.sip.example.com, attrs=1042`
- `opensips.subscriber`: `username=101, domain=acme.sip.example.com, ha1=md5(101:acme.sip.example.com:<password>)` + `ha1_sha256`
- `asterisk.extensions` (context `managed`, target `number:1042101`):
    - exten `1042101`: 1 `Dial(PJSIP/1042101@opensips,30)` → (ถ้าเปิด voicemail) `VoiceMail(101@t1042,${IF($["${DIALSTATUS}" = "BUSY"]?b:u)})` → `Hangup()` — OpenSIPS แปลง `1042101` กลับเป็น `101@acme.sip.example.com` ก่อน `lookup`
    - exten `1042p101`: `Return(<flags>)` — สิทธิ์การโทรจาก calling profile (D7)
- `asterisk.voicemail` (ถ้าเปิด): `context=t1042, mailbox=101, password=<pin>, fullname`
- ต่อ tenant: `1042*97` → `VoiceMailMain(${CALLERID(num)}@t1042)`; ร่วมทุก tenant: `denied` → `Playback(ss-noservice)`, `Hangup(21)`
- extension ที่ปิด / tenant หรือ assignment suspended: subscriber `ha1 = ha1_sha256 = "disabled"` (OpenSIPS ตอบ `403 Account disabled`) + exten → `Goto(denied,1)`, ไม่มี `1042p101`; ลบ = ไม่มีแถว
- สิทธิ์ตามหมวดและรหัสอนุญาตอยู่ใน target `calling:1042` (`CallingCompiler`) — ดู D7 และ §12
- ไม่เขียน `ps_*` สำหรับโทรศัพท์ (โทรศัพท์ register ที่ OpenSIPS); `ps_*` ใช้กับ trunk ใน Phase 4

### 6.3 Read-back / health check

| สิ่งที่เปลี่ยน           | ต้อง reload?                      | ตรวจอย่างไร                                                                                                                                                                                                             |
| ------------------------ | --------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `subscriber`             | ไม่ (auth_db query ทุกครั้ง)      | SELECT ha1 ตรงกับที่ compile                                                                                                                                                                                            |
| `extensions` realtime    | ไม่                               | AMI `Getvar DIALPLAN_EXISTS(from-opensips,<n>,<p>)` = 1 ทุก priority (`dialplan show <n>@managed` ใช้ไม่ได้: context `managed` เข้าถึงผ่าน realtime switch ของ `from-opensips` เท่านั้น ไม่มีใน dialplan ในหน่วยความจำ) |
| `voicemail`              | ไม่                               | AMI `Getvar VM_INFO(<ext>@t<code>,exists)` = 1 (`VoicemailUsersList` ไม่แสดง mailbox realtime ที่ยังไม่ถูกโหลด)                                                                                                             |
| `queues`/`queue_members` | บางกรณี (`queue reload realtime`) | AMI `QueueStatus`                                                                                                                                                                                                       |
| `ps_*` (trunk, P4)       | ไม่ (sorcery realtime)            | AMI `PJSIPShowEndpoint`                                                                                                                                                                                                 |

> หลัก "อย่าถือว่าสำเร็จเพราะส่งคำสั่งแล้ว" ยังใช้ — แต่ส่วนใหญ่เป็นการตรวจผล ไม่ใช่สั่ง reload

### 6.4 Reconciliation

`nodes:reconcile` ทุก 15 นาที และหลัง SIP Deployer reinstall/upgrade (ตรวจจาก manifest checksum/`services.*.version` เปลี่ยน): compile desired state ทั้ง node → diff กับ rows จริง → รายงาน drift (missing / unexpected / changed) และ auto-fix เฉพาะ missing/changed ที่เป็นของ Management App

### 6.5 Error classes

- Retryable: connection refused/timeout, deadlock, AMI ไม่ตอบ
- Permanent: validation, capability ไม่มี, auth DB ผิด (→ re-pull credentials 1 ครั้ง แล้ว failed), schema ไม่ตรง

---

## 7. Phase 3 — CDR, Call Events, Monitoring

- **CDR sync** (`cdr:sync` ทุก 1 นาทีต่อ node): อ่านตาราง `cdr` ด้วย watermark ที่ `end` ย้อนหลัง 5 นาที (กัน CDR ช้า) → upsert ด้วย (node, uniqueid, sequence) → map tenant จาก 4 หลักแรกของ `dst` (tenant code ตาม D6) — ต้องตรวจคอลัมน์จริงของ Alembic `cdr` บน server (มี `linkedid`, `sequence`, `peeraccount`)
- **AMI listener** (`ami:listen {node}`): ReactPHP/amphp socket client ขนาดเล็ก (ไม่ใช้ PAMI ที่ไม่ดูแลแล้ว), subscribe `call,cdr,reporting`, reconnect + backoff, heartbeat `Ping`; เขียน `call_events` ผ่าน queue `events` dedupe ด้วย hash(node, uniqueid, event, timestamp)
- **Registration status**: อ่าน `opensips.location` ทุก 1 นาที → `registration_snapshots` (หน่วงได้ถึง ~60s จาก write-back — แสดงเวลาที่ "seen")
- **Business monitoring** (ไม่ซ้ำกับ SIP Deployer): extension ที่ไม่ register, provisioning ค้าง/failed, CDR sync หยุด, AMI listener หลุด, reconcile พบ drift, register fail/สายผิดปกติต่อ tenant; แจ้งผ่าน mail/Slack
- Retention: CDR 13 เดือน, call_events 30 วัน (ตั้งค่าได้)

---

## 8. API v1 และหน้าจอ

API (ตาม §12 ของ v1.1) — Idempotency-Key บน POST, `If-Match`/`version` บน PATCH, rate limit 120/นาทีต่อ token, pagination, secret ตอบครั้งเดียว:

| สถานะ   | Endpoints `/api/v1`                                                                                                                                                                                                                                                                                                   |
| ------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| ✅ มีแล้ว | `GET tenant`, `GET\|POST extensions`, `GET\|PATCH\|DELETE extensions/{id}`, `POST extensions/{id}/rotate-credential`, `GET operations/{id}`, `GET\|POST calling-profiles`, `GET\|PATCH\|DELETE calling-profiles/{id}`, `POST calling-profiles/{id}/default`, `GET\|POST authorization-codes`, `GET\|PATCH\|DELETE authorization-codes/{id}`, `POST authorization-codes/{id}/reset`, `GET\|PATCH cross-tenant`, `GET directory` |
| ⏳ ยังไม่มี | voicemail / reapply / ring groups ใน API, `POST extensions/{id}/devices`, `GET call-records`, trunks / outbound-routes (Phase 4)                                                                                                                                                                                      |

หน้าจอ (Inertia/Vue) — ✅ = มีแล้ว:

- **Platform admin**: ✅ Nodes (`/admin/nodes`: sync, สถานะ endpoint, ทดสอบการเชื่อมต่อ, assign tenant), ✅ Tenants (`/admin/tenants`); ⏳ Operations (ทั้งระบบ, retry), Drift report
- **Tenant** (`/t/{slug}/…`): ✅ Dashboard, Extensions (สร้าง/แก้/ปิด, รหัส SIP แสดงครั้งเดียว, หมุนรหัส, voicemail + PIN, calling profile, สถานะ register, reapply), Ring groups, Calling permissions (`/calling`), Members, Audit log, API tokens; ⏳ Devices, Call history (CDR), Operations

---

## 9. ลำดับงาน (milestones + เกณฑ์ผ่าน)

สถานะ (29 ต.ค. 2026): M0 ส่วนใหญ่เสร็จ (ค้างทดสอบ PAI ปลอม), M1–M2 ✅, M3 ✅ ยกเว้น integration test ใน Docker, M4 ✅, M5 ✅ (P0 ผ่าน, D7 ผ่านด้วยโทรศัพท์จริง 2 tenant), M6–M7 ยังไม่เริ่ม — รายละเอียดใน §12

| #      | งาน                                                                                                                                                                                                       | เกณฑ์ผ่าน                                                                                                                                                            |
| ------ | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| **M0** | SIP Deployer prerequisites: ~~แก้ PAI/RPID ใน opensips.cfg~~ (ทำแล้ว; ต้อง reconfigure node lab), API manifest/credentials, VPC ทดสอบ, ปิด direct_dial ได้, Asterisk ทดสอบบน server จริง (ยังค้างใน §4.5) | `curl` manifest ได้ด้วย token; โทร 1001↔1002 ผ่าน Asterisk ด้วย test subscribers                                                                                     |
| **M1** | Skeleton: Laravel 13, PostgreSQL, Redis/Horizon, Inertia+Vue, Sanctum, Boost, Pest, auth + MFA สำหรับ platform admin, Tenancy module + policies + audit                                                   | tenant isolation tests ผ่าน                                                                                                                                          |
| **M2** | Nodes: SipDeployerClient, `nodes:sync`, node_secrets, assignment, connection test (DB/AMI/ARI)                                                                                                            | node lab แสดง capability และ "connections OK"                                                                                                                        |
| **M3** | Infrastructure adapters: OpenSipsDbAdapter, AsteriskRealtimeAdapter, AmiClient (action/response)                                                                                                          | integration test กับ MariaDB/Asterisk ใน Docker                                                                                                                      |
| **M4** | Provisioning core: config_revisions, operations, outbox relay, compiler (extension + voicemail), apply, read-back, retry                                                                                  | สร้าง 1001/1002 ผ่าน API → operation `applied`; ส่งซ้ำด้วย Idempotency-Key ไม่สร้างซ้ำ; worker restart ระหว่าง apply แล้วจบถูกต้อง                                   |
| **M5** | UI tenant + API v1 ครบ, credential reveal-once/rotate                                                                                                                                                     | **P0 ผ่านผ่าน Management App:** Yealink 2 เครื่อง register และโทรหากันได้ (ปิด direct_dial บน node); สร้าง tenant ที่ 2 แล้วโทรข้าม tenant ถูกปฏิเสธ แม้ส่ง PAI ปลอม |
| **M6** | Reconciler + drift report                                                                                                                                                                                 | SIP Deployer reinstall OpenSIPS/Asterisk → reconcile ไม่พบ drift และสายยังทำงาน                                                                                      |
| **M7** | Phase 3: CDR sync, AMI listener, registration snapshots, dashboard, alerts                                                                                                                                | CDR ถูกต้องสำหรับ answered/busy/no answer; event ไม่ซ้ำหลัง listener reconnect                                                                                       |

Phase 4 (โครง): `sip_trunks` → `ps_endpoints/ps_auths/ps_aors/ps_registrations/ps_endpoint_id_ips`, `did_numbers` + inbound routes → rows ใน `managed` (หรือ context trunk ที่ต้องตกลงกับ SIP Deployer), outbound policy (prefix allowlist, concurrent limit, เวลา, spending), ring group/queue; trunk ของ tenant + trunk กลาง และ**การคิดค่าใช้ trunk กลาง** (ตัดสินตอนเริ่ม Phase 4 — ดู D7)
Phase 5: IVR (ARI Stasis, versioned flow), TTS, CRM adapter (vTiger ก่อน)

---

## 10. Testing

- Unit: compiler (snapshot ของ rows), ha1, checksum, diff, error classifier, AMI parser
- Feature: tenant isolation ทุก endpoint, idempotency, optimistic lock, credential ไม่หลุดใน log/response ซ้ำ
- Integration (Docker compose ใน CI): MariaDB + schema `opensips` (opensips-cli) + `asterisk` (Alembic) + Asterisk 22 container สำหรับ AMI
- Manual E2E (ตาม §15 v1.1): NAT ต่าง ISP, wrong password → fail2ban, reinstall, boundary (ต่อจากนอก CIDR ถูกปฏิเสธ)

---

## 11. ความเสี่ยงและคำถามที่ยังเปิด

1. ~~D1 multi-tenant ต่อ node~~ — ตัดสินแล้ว: ช่วงหมายเลข 5 หลักต่อ tenant (18 ต.ค. 2026); **เปลี่ยนเป็นโดเมนต่อ tenant ตาม D6 (21 ต.ค. 2026)** — ทำเสร็จแล้ว; §4 และ §6.2 อัปเดตตาม D6 (29 ต.ค. 2026)
2. ~~D4 เก็บ SIP password แบบเข้ารหัสเพื่อ Yealink auto-provisioning หรือไม่~~ — ตัดสินแล้ว: เข้ารหัส (20 ต.ค. 2026)
3. ~~สิทธิ์ของ extension~~ — ตัดสินแล้ว: calling profiles ตาม D7 (21 ต.ค. 2026); เดิม: สิทธิ์ของ extension ยังไม่กำหนด: โทรภายใน tenant ถึงกันได้ทุกเบอร์ (v1.1 §7 "อนุญาตเฉพาะ extension ที่มีสิทธิ์โทรถึงกัน" ยังไม่มีรูปแบบ), ค่าของ `outbound_policy`, limit ราย extension
4. DB users `manager`/`asterisk_manager` เป็น `@'%'` และไม่ `REQUIRE SSL` — พึ่ง firewall + VPC; ควรจำกัด host เป็น private CIDR เมื่อย้าย VPC
5. ARI เป็น HTTP — ใช้ได้เฉพาะใน VPC
6. Management App deploy ที่ไหน (droplet แยก region เดียวกับ SIP node ตาม §5.1 รูปแบบ B) — ใช้ dployer/Ploi หรือ SIP Deployer สร้างให้?
7. ~~Vue 3 หรือ React~~ — ตัดสินแล้ว: Vue 3 + TypeScript (M1)

---

## 12. สถานะ (29 ต.ค. 2026)

งานทั้งหมดด้านล่างของ sip-manager commit ลง `main` แล้ว (19–29 ต.ค. 2026; ข้อความ "ยังไม่ commit" ในหัวข้อย่อยหมายถึง ณ วันที่เขียน) — ส่วนของ SIP Deployer ให้ตรวจใน repo นั้น

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

- **Ring group ทดสอบด้วยโทรศัพท์แล้ว** (20 ต.ค. 2026)

### Voicemail (20 ต.ค. 2026, ยังไม่ commit)

- `extensions.voicemail_enabled` + `voicemail_pin` (6 หลัก, encrypted; แสดงครั้งเดียวตอนเปิด/ตั้งใหม่ เหมือนรหัส SIP) — เปิดได้เฉพาะ node ที่มี feature `voicemail`
- Compile ตาม §6.2: แถว `asterisk.voicemail` (context `t<prefix>`, mailbox = เบอร์, password = PIN, fullname = ชื่อ extension); dialplan หลัง Dial = `VoiceMail(<n>@t<prefix>,${IF($["${DIALSTATUS}" = "BUSY"]?b:u)})` แล้ว `Hangup()`; feature code ร่วม `*97` = `VoiceMailMain(${CALLERID(num)}@t${CALLERID(num):0:2})` เขียนทุกครั้งที่ apply (เหมือน `denied`); extension ที่ปิด/ลบ/tenant suspended = ลบแถว voicemail (ไฟล์ข้อความบน node ยังอยู่); read-back รวมแถว voicemail
- Ring group failover = `voicemail` (กล่องของ extension ที่เปิด voicemail) → `VoiceMail(<n>@t<prefix>,u)`; ปิด voicemail/ลบ extension = recompile กลุ่ม (กลับเป็น Hangup)
- ทดสอบบน node lab: 20101–20103 มี mailbox, `*97` และ dialplan ถูกต้อง (`applied`); **ทดสอบด้วยโทรศัพท์แล้ว** — ฝากข้อความ, `*97` + PIN, ฟัง/ลบข้อความได้ (DTMF แบบ RFC2833 ผ่าน rtpengine ถึง Asterisk ปกติ; โทรศัพท์ต้องตั้ง DTMF เป็น RFC2833 เพราะ endpoint `opensips` เป็น `dtmf_mode = rfc4733`)
- ข้อจำกัด: (1) PIN ที่เปลี่ยนจากเมนู `*97` จะถูกเขียนทับด้วย PIN ในระบบเมื่อ apply extension ครั้งถัดไป; (2) ไฟไฟข้อความ (MWI) ยังไม่ทำงาน เพราะโทรศัพท์ register ที่ OpenSIPS ไม่ใช่ Asterisk — ต้องให้ SIP Deployer ตั้ง MWI ผ่าน OpenSIPS หรือ subscribe; (3) voicemail-to-email ยังไม่มี (node ไม่มี MTA)

### AmiClient + read-back ผ่าน AMI (20 ต.ค. 2026, ยังไม่ commit)

- `Infrastructure\Asterisk\AmiClient`: socket แบบ blocking, ทีละ action, จับคู่คำตอบด้วย ActionID (`sip-manager-<n>`), login แบบ `Events: off` แล้วข้าม event อื่น, รองรับ action ที่ส่ง event list (`EventList: start` … `Complete`), `getvar()`, `command()` (Output lines), `logoff()`; ปฏิเสธ field ที่มี CR/LF; `AmiMessage` (parser, key ไม่สนตัวพิมพ์, key ซ้ำต่อกันด้วย `\n`)
- Error: `AmiUnavailable` (ต่อไม่ได้/timeout/ปิดการเชื่อมต่อ/ไม่ใช่ AMI → retry, `node_unreachable`), `AmiActionFailed` (`Response: Error`; Login → `node_auth_failed`, อื่น → `ami_error`; ไม่ retry)
- `AmiAdapter`: `version()` (CoreSettings — ใช้ในทดสอบการเชื่อมต่อแทน `AmiLogin` เดิม), `missingPriorities()`, `hasMailbox()` — login ครั้งละคำถามแล้ว logoff
- `ApplyRevision` หลังเทียบ checksum: ถาม Asterisk ว่าเห็นทุก priority ของเบอร์ และ mailbox (ถ้าเปิด voicemail); ไม่เห็น = `read_back_mismatch` (retry); node ที่ไม่มี endpoint `ami` ข้าม; `apply_result.asterisk` = `confirmed` / `unchecked`
- ทดสอบกับ node lab: Asterisk 22.11.0, 20101 เห็น priority 1–4, `20101@t20` มี mailbox
- ~~**ข้อจำกัด:** ตราบที่ node ยังเปิด `direct_dial` (`_X.`) priority 1 ของเบอร์ใดก็ "มี" เสมอ~~ — ปิด direct_dial บน node lab แล้ว (ดูด้านล่าง)
- 198 tests ผ่าน, Larastan ผ่าน

### API v1 (20 ต.ค. 2026, ยังไม่ commit)

- **API tokens** (หน้า `/t/{slug}/api-tokens`, owner/admin): Sanctum token ของ user ผูกกับ tenant เดียว (`personal_access_tokens.tenant_id`, model `ApiToken`); สิทธิ์ `read` หรือ `read+write`; หมดอายุ 30/90/365 วัน หรือไม่หมด; แสดง token ครั้งเดียว เก็บแค่ hash; revoke ได้; เอาสมาชิกออก = ลบ token ของเขาใน tenant นั้น; audit `api_token.created/revoked`
- คำขอ API ทำในนาม user ของ token ด้วย role ณ ตอนนั้น (member ที่ถือ write token ก็เขียนไม่ได้); tenant มาจาก token เท่านั้น (`SetApiTenant`): token ไม่มี tenant / user ไม่ได้เป็นสมาชิกแล้ว / tenant suspended = 403; Sanctum `guard => []` (ไม่รับ session ของหน้าเว็บ)
- Endpoints `/api/v1`: `GET tenant`, `GET|POST extensions` (paginate `per_page` ≤ 100, filter `status`), `GET|PATCH|DELETE extensions/{id}`, `POST extensions/{id}/rotate-credential`, `GET operations/{id}`; การเปลี่ยนที่ไปถึง PBX ตอบ `202` + `operation_id`; `sip_credential` (username, password, domain, port, transports) อยู่ในคำตอบของ create/rotate เท่านั้น
- PATCH: ต้องส่ง `version` หรือ `If-Match` (ETag ของ GET) ไม่ส่ง = 428, version ไม่ตรง = 409 (พร้อมข้อมูลล่าสุด); เปลี่ยนชื่ออย่างเดียว = 200 ไม่ provision
- **Idempotency-Key** บน POST (`idempotency_keys`: tenant, key, request_hash, response; เก็บ 24 ชม., `model:prune` รายวัน): ซ้ำ = คำตอบเดิม + `Idempotent-Replayed: true` โดย password เป็น `[redacted]` (แสดงครั้งเดียวจริง); key เดิมกับ body อื่น = 422; คำขอแรกยังทำอยู่ = 409; คำขอที่ล้มเหลวไม่จำ key
- Rate limit 120/นาทีต่อ token, rotate 10/นาที
- `StaleVersion` (extends ValidationException) ใช้กับ extension และ ring group
- 214 tests ผ่าน, Larastan, vue-tsc, `vp check` ผ่าน
- ยังไม่ทำ: voicemail/reapply/ring group ใน API, `POST extensions/{id}/devices` และ `GET call-records` (ยังไม่มี devices/CDR), หน้า Operations

### ปิด direct_dial บน node lab (20 ต.ค. 2026)

- SIP Deployer → Reinstall Asterisk ของ `sip-server-test-02` (run 12) ด้วย features `ami, ari, realtime, voicemail, queues` — ไม่ compile ใหม่ (22.11.0 build อยู่แล้ว), DB/รหัสผ่านคงเดิม, Asterisk restart สั้นๆ; `nodes:sync` แล้ว manifest ไม่มี `direct_dial`
- ตรวจผ่าน AMI: เบอร์ที่ไม่มีแถว (29999) ไม่พบ priority 1 แล้ว, 20101 เห็น priority 1–4, `denied` เห็นครบ — เบอร์ที่ไม่มี dialplan ใน `managed` โทรไม่ติดอีกต่อไป

### ทดสอบ P0 ของ M5 (20 ต.ค. 2026)

- ✅ tenant ที่ 2 (prefix 30), extension 30101 บน MizuDroid (Android) register ได้, `*43` echo ได้ยินเสียงตัวเอง
- ✅ โทรข้าม tenant (30101 → 20101) ได้ยิน "the number you dialed is not in service" (exten `denied`) — CDR `dst=denied`
- บทเรียน "โทรได้แต่ไม่มีเสียง": ดู Final packet stats ของ rtpengine (in/out ของขาโทรศัพท์) — ทั้งสองทางมีแพ็กเก็ตแปลว่า server ส่งครบ; แยกขาเข้า/ขาออกด้วยการโทรไป `denied` (ได้ยินประกาศ = ขาเข้าปกติ) — ครั้งนี้เป็นไมค์ของแอปฝั่งโทรศัพท์
- ✅ ปิด extension (20102): โทรเข้า 20101 → 20102 ไป `denied` (CDR `lastapp=Hangup`), โทรศัพท์ register ไม่ได้ (`SIP auth failed: REGISTER … as 20102`) — แต่โทรศัพท์ส่ง REGISTER ผิด 5 ครั้งใน 1 วินาที ถึงเกณฑ์ fail2ban พอดี (เสี่ยงแบน IP ทั้งออฟฟิศ) → ต้องแก้ใน SIP Deployer
- ⏳ ยังต้องทดสอบ: PAI ปลอม
- พบระหว่างทดสอบ: 20102 เป็น MizuDroid ที่ register ผ่าน IPv6 — ยังไม่ได้ตรวจว่า rtpengine มี IPv6; ~~ช่องโหว่ใน opensips.cfg ที่คำขอจากโดเมนอื่นไป `lookup("location")` ตรงโดยไม่ผ่าน Asterisk~~ (แก้แล้ว ด้านล่าง)

### SIP Deployer: ไม่มีอะไรถึงโทรศัพท์โดยไม่ผ่าน Asterisk (20 ต.ค. 2026, ยังไม่ commit ใน sip-deployer)

- `opensips.cfg` (เฉพาะ node ที่มี Asterisk): (1) คำขอที่ `From` เป็นโดเมนอื่น = `403 Foreign caller forbidden` ก่อน auth (ไม่นับใน fail2ban; ยังไม่มี trunk); (2) โทรศัพท์ส่งไปโดเมนอื่น = `403 Relay Forbidden` (เดิม relay ออกได้โดยไม่ผ่าน dialplan); (3) คำขออื่นที่ไม่ใช่ INVITE จากโทรศัพท์ถึงโทรศัพท์ (MESSAGE, OPTIONS, REFER ฯลฯ) = `403 Forbidden` (เดิมไป `lookup` ตรง ข้าม tenant ได้); สายจาก Asterisk ไปโทรศัพท์ และ in-dialog requests ไม่เปลี่ยน
- test ใน `OpenSipsConfigTest`; 267 tests ผ่าน
- ติดตั้งบน node lab: Reinstall Asterisk (run 13) เขียน opensips.cfg ใหม่ ตรวจด้วย `opensips -C` แล้ว restart; ทดสอบจริง: OPTIONS จาก `foreign.example` ถึง `20101@168.144.99.9` ได้ `403 Foreign caller forbidden`

### Extension ที่ปิดไม่ทำให้ fail2ban แบน IP (20 ต.ค. 2026, ยังไม่ commit ทั้งสอง repo)

- **ข้อตกลงระหว่างสองแอป:** subscriber ที่ `ha1 = "disabled"` = บัญชีที่ถูกปิด (digest จริงเป็น hex จึงไม่ชนกัน) — ไม่ต้องเปลี่ยน schema
- sip-manager: extension ที่ปิด / tenant หรือ assignment ถูก suspend → compile เป็น subscriber `ha1 = ha1_sha256 = "disabled"` (`NumberCompiler::DISABLED_HA1`) แทนการลบแถว; ลบ extension ยังลบแถว (เบอร์นำกลับมาใช้ได้); ring group ไม่มี subscriber
- SIP Deployer `opensips.cfg`: `route[disabled_account]` (sqlops `sql_select_one` บน subscriber ด้วย `$au`/`$ar` ที่ escape แล้ว) ก่อน `www_authorize` และ `proxy_authorize` → `403 Account disabled` โดยไม่ log "SIP auth failed" (fail2ban ไม่นับ); node ที่ยังใช้ cfg เก่าจะเห็นแถวนี้เป็นรหัสผิดเหมือนเดิม (ไม่แย่ลง)
- ลำดับการติดตั้ง: SIP Deployer ก่อน (Reinstall Asterisk run 14, `opensips -C` ผ่าน) แล้วค่อย reapply ใน sip-manager
- ทดสอบจริง: 20102 (ปิดอยู่) → แถว `ha1 = disabled` (`applied`); REGISTER พร้อม credentials ปลอมในชื่อ 20102 ได้ `403 Account disabled`, ไม่มี "SIP auth failed" จาก IP ทดสอบ
- tests: sip-manager 214, sip-deployer `OpenSipsConfigTest` 18

- ✅ ยืนยันแล้ว (21 ต.ค.): Yealink 20101 และ 20103 register ใหม่ได้ด้วย cfg ใหม่ — เส้นทาง auth ปกติไม่กระทบ

### สถานะ register ในหน้า Extensions และ API (21 ต.ค. 2026, ยังไม่ commit)

- `OpenSipsDbAdapter::registrations()` อ่าน `opensips.location` ของหลายเบอร์ใน query เดียว เฉพาะที่ยังไม่หมดอายุ (`expires = 0` หรือ `> unix_timestamp()` ของ node — แถวที่หมดอายุแล้วยังค้างในตาราง); address จาก `received` (มี NAT) หรือ `contact`, รองรับ IPv6; `last_modified` เป็น UTC
- `ExtensionRegistrations`: สถานะต่อ extension `registered` / `not_registered` / `unknown` (node ติดต่อไม่ได้ = unknown + log warning ไม่ทำให้หน้าพัง) พร้อม contacts (address, user_agent, registered_at, expires_at)
- หน้า Extensions: คอลัมน์ Phone เป็น deferred prop (หน้าไม่รอ node) + skeleton ระหว่างโหลด, poll ทุก 30 วินาที (`only: registrations`); extension ที่ปิดแสดง "—"; หลายเครื่องแสดง ×N และรายละเอียดใน tooltip
- API: `GET /extensions` และ `GET /extensions/{id}` มี `registration`
- ข้อจำกัด: ช้าได้ ~1 นาที (sql-write-back); เครื่องที่ปิดโดยไม่ unregister ยังแสดง Registered จนหมดอายุ
- ทดสอบกับ node lab: 20101/20103 (Yealink) registered พร้อม address และ user agent ถูกต้อง; 221 tests ผ่าน, Larastan, vue-tsc, build ผ่าน

### D6 — โดเมนต่อ tenant (21 ต.ค. 2026, ยังไม่ commit ทั้งสอง repo)

- **SIP Deployer:** `opensips.cfg` — `domain` module (`db_mode 0`), `usrloc use_domain 1`; REGISTER ไปโดเมนที่ไม่รู้จัก = `403 Unknown domain` (ก่อน auth); ผู้โทรต้องเป็นโดเมนของ node (`is_myself`) หรือโดเมน tenant (`is_domain_local` + attrs = code 4 หลัก) — ไม่มี code = `403 Domain has no tenant` (ก่อน auth); INVITE ไป Asterisk = `$rU = "<code>$rU"`; โทรศัพท์ส่งไปโดเมน tenant อื่นตรง ๆ = `403 Relay Forbidden`; จาก Asterisk: `route[tenant_destination]` หาโดเมนจาก attrs (`sql_select_one`) แล้ว `lookup`; `CreateOpenSipsDatabase` สร้างตารางทีละ module (เพิ่ม `domain` ให้ DB เดิมได้); `[from-opensips]` `_XXXX*43`; manifest `dialplan.tenant_routing = domain`, `tenant_code_digits = 4`; 317 tests ผ่าน — **ยังไม่ได้ reinstall node lab / `opensips -C`**
- **sip-manager:** `tenants.code` (1000–9999, max+1) + `sip_domain` (`<slug>.<SIP_TENANT_DOMAIN_SUFFIX>`, กำหนดตอนสร้าง tenant); ลบ `tenant_number_ranges`; unique ต่อ tenant ใน `extensions`/`dial_numbers`/`sip_credentials`; กติกาเลข `NumberAllocator::PATTERN` (2–5 หลัก, ไม่ขึ้นต้น 0/8/9, ไม่มี 1xx, ไม่ใช่ 1155/1669); assign/unassign = revision `domain:<code>`; revision เบอร์ = `number:<code><n>`; compiler ไม่มี `GotoIf` แล้ว, `Dial(PJSIP/<code><n>@opensips)`, voicemail `t<code>`, `<code>*97`; adapter แยกตาม (username, domain) / (context, mailbox); node ที่ยังไม่มี `tenant_routing` = assign ไม่ได้และ apply เป็น `node_capability`; 225 tests, Larastan, vue-tsc, lint, build ผ่าน
- migration แก้ไฟล์เดิม (เริ่มใหม่ — `migrate:fresh`)
- **บทเรียนจาก node lab (22 ต.ค.):** เมื่อโหลด `domain` module แล้ว `is_myself()` เป็นจริงกับทุกโดเมนในตาราง `domain` ด้วย — cfg แรกตรวจ `is_myself` ก่อน โทรศัพท์ทุก tenant จึงได้ `403 Domain has no tenant`; แก้เป็นตรวจ `is_domain_local` ก่อน และอ่าน code ด้วย `sql_select_one`; วิธีหาสาเหตุ: ใส่ค่าตัวแปรใน reason ของ 403 ชั่วคราว แล้วส่ง INVITE ทดสอบ (ก่อน auth ไม่นับ fail2ban)

### D7 — สิทธิ์การโทร + รหัสอนุญาต + โทรข้าม tenant (29 ต.ค. 2026, ยังไม่ commit)

- **ข้อมูล** (migration ใหม่ ไม่ต้อง `migrate:fresh`): `calling_profiles` (categories = map หมวด → `allow`/`code`, ไม่มี = `deny`), `calling_profile_destinations`, `authorization_codes` (เก็บแค่ `node_hash`), `tenants.cross_tenant_inbound` + `auth_salt` (เติมให้ tenant เดิมใน migration), `tenant_inbound_allowlist`, `extensions.calling_profile_id` (null = default); `CreateTenant` สร้าง 5 profile เริ่มต้น ("Internal only" เป็น default)
- **Compile:** `NumberCompiler` เขียน `<code>p<ext>` → `Return(<flags>)` ใน `number:` (extension ที่ปิด = ไม่มีแถว → `Gosub` ล้ม = โทรนอก tenant ไม่ได้); `CallingCompiler` → target `calling:<code>` เป็นเจ้าของ exten ที่ขึ้นต้น `_<code>`, `<code>a`, `<code>x` ทั้งหมด (`RealtimeAdapter::replaceExtensStartingWith`, เทียบด้วย `substr` ไม่ใช่ `LIKE` เพราะ `_`); tenant suspended / ไม่อยู่บน node = ลบทั้งหมด; AMI ตรวจ pattern ด้วยเลขตัวอย่างที่ match (`CompiledCalling::probeNumber`)
- **เปลี่ยนจากแผนเดิม (โทรข้าม tenant):** ไม่ใช้ `_<src>8<dst>.` ต่อปลายทาง + `_<src>8.` → denied เพราะ pbx_realtime เลือก pattern แรกที่ DB คืน (เรียงตาม exten: `_10208.` มาก่อน `_102081042.`) ไม่ใช่ pattern ที่ยาวที่สุด — ใช้ pattern เดียว `_<src>8XXXX.` + exten marker `<src>x<dst>` (`NoOp`) ต่อปลายทางที่รับเรา: ตรวจเลขเป็นตัวเลข → marker และเบอร์ปลายทางมีอยู่ (`DIALPLAN_EXISTS(${CONTEXT},…)`) → flag `xa`/`x<dst>` หรือ `XA`/`X<dst>` + `Gosub(<src>auth,1(x))` → `CALLERID(num)=8<src><caller>` → `Goto(${EXTEN:5},1)`; ทุกการตรวจ "ผ่านแล้วกระโดดไปข้างหน้า ไม่ผ่านตกไป denied" (expression ผิดพลาด = ปฏิเสธ); pattern ของ tenant เดียวกันห้ามซ้อนกัน; marker ไม่ขึ้นกับ node (ปลายทางต่าง node ไม่มีเบอร์บน node นี้ → denied)
- **รหัสอนุญาต:** ตาม _รหัสอนุญาต_ ข้างบน; หมดอายุด้วย `authorization-codes:expire` ทุกนาที (status `expired` + recompile); API คืนรหัสใน `authorization_code` (ไม่ใช่ `code`) เพื่อให้ `Audit::redact` ซ่อนใน Idempotency-Key replay
- **สิ่งที่ recompile:** แก้ profile → extension ที่ใช้ (default → รวมที่ไม่มี profile); เปลี่ยน default → extension ที่ไม่มี profile; รหัสเปลี่ยน → `calling:` ของ tenant; ปลายทางเปลี่ยนการรับ → `calling:` ของต้นทางที่ได้/เสียปลายทางนี้; assign / suspend / unassign / reapply domain → `calling:` ด้วย
- **หน้าเว็บ:** "Calling permissions" (`/t/{slug}/calling`): ตาราง profile × หมวด, แก้ไขในหน้า, รหัสอนุญาต (แสดงครั้งเดียว), การรับสายข้าม tenant, ไดเรกทอรี; หน้า Extensions มีคอลัมน์ Calling เลือก profile
- **API v1:** `calling-profiles` (+ `{id}/default`), `authorization-codes` (+ `{id}/reset`), `GET|PATCH cross-tenant`, `GET directory`, `PATCH extensions/{id}` รับ `calling_profile_id`, `GET extensions?calling_profile_id=`
- **ทดสอบบน node lab** (แถวจาก `CallingCompiler` จริง, tenant ทดสอบ `0999`/`0998`/`0997`, ลบแล้ว): `/xa/` → ถึงปลายทางและเห็น `80999201`; `/` → denied; ปลายทางไม่รับ → denied; `/x0998/` → ถึง; `/x1042/` → denied; `/XA/` + รหัสถูก → ถึง, CDR `userfield = authcode:17`; `/XA/` + รหัสผิด 3 ครั้ง → denied (Hangup 21)
- 254 tests, Larastan, vue-tsc, lint, build ผ่าน
- ✅ ทดสอบด้วยโทรศัพท์จริงระหว่าง 2 tenant (29 ต.ค. 2026, tenant 1000/1001): "Internal only" → denied ("not in service"); "Other tenants" + ปลายทาง `all` → ดัง; "Internal, others with a code" + รหัสอนุญาต → ดัง — กดข้าม tenant ต้องมี `8` นำ (`81000201`); กด `1000201` ตรง ๆ = 404 เพราะ OpenSIPS เติม code ของผู้โทร
- ยังไม่ได้: หมวดโทรออก (รอ trunk, Phase 4); นับ `AuthCodeFailed` (Phase 3); `emergency_numbers`

### งานรอบ 29 ต.ค. 2026 (บ่าย, ยังไม่ commit)

- tenant ที่สร้างก่อน D7 ไม่มี calling profile (สร้างเฉพาะใน `CreateTenant`) → migration `create_default_calling_profiles_for_existing_tenants` เรียก `ManageCallingProfiles::createDefaults()` ให้ tenant ที่ยังไม่มี
- relay หยุดทั้งที่ `schedule:work` รัน: lock ของ `withoutOverlapping()` ค้าง 24 ชม. หลังปิด `composer run dev` ระหว่าง relay ทำงาน (`schedule:list` แสดง "Has Mutex"; แก้ทันทีด้วย `php artisan schedule:clear-cache`) → lock หมดอายุ 1 นาที (`provisioning:relay`, `authorization-codes:expire`) / 10 นาที (`nodes:sync`)

### งานรอบ 29 ต.ค. 2026 (commit แล้ว)

- `php artisan dev` / `composer dev` เปิด server ที่พอร์ต **8080** (`AppServiceProvider::configureDevCommands`) — `APP_URL` ใน `.env.example` ยังเป็น `:8000`
- หน้า Welcome ใหม่ (ภาพรวม architecture: SIP domain ต่อ tenant, node pool, outbox → provisioning, read-back ผ่าน AMI)
- CI (`.github/workflows/tests.yml`): PHP 8.4 + `pdo_pgsql`, Node 22, PostgreSQL 17 service → `composer setup` + `composer ci:check` (`vp check`, `vue-tsc`, Pint, Larastan, tests)
- CaptainHook (`captainhook.json`): commit-msg (Beams: subject ≤ 50, body ≤ 72), pre-commit (PHP lint, `pint --dirty --test`, `npm run check`), pre-push (phpstan, tests)
- README ระบุวิธีติดตั้ง, process ที่ต้องรัน และโครงสร้าง module

### ถัดไป

หน้า Operations → M6 (reconciler + drift report) → M7 (CDR sync ก่อน AMI listener)
