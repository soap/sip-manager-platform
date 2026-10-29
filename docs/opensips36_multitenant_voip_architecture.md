# OpenSIPS 3.6 Multi-tenant VoIP SaaS
## Production Routing Architecture & Implementation Specification

**เอกสาร:** Architecture and Routing Design  
**เวอร์ชันเอกสาร:** 1.0 (Design Baseline)  
**สถานะ:** Draft for Engineering Review  
**ขอบเขต:** SIP Client REGISTER กับ OpenSIPS โดยตรง, SIP Domain แยก Tenant, OpenSIPS เลือก Route ระหว่าง SIP Client และ Asterisk หลายเครื่อง

> **ข้อกำหนดสำคัญ:** เอกสารนี้เป็นแบบออกแบบและ Implementation Baseline ไม่ใช่การรับรองว่า Configuration ผ่าน Syntax Validation หรือพร้อมใช้งาน Production แล้ว ต้องตรวจสอบกับ OpenSIPS 3.6 build, modules, database schema, network topology และค่าจริงของระบบก่อน deploy

> **การนำไปใช้ใน sip-manager (29 ต.ค. 2026):** ใช้หลักการ SIP domain ต่อ tenant ของเอกสารนี้ตาม D6 ใน [management-app-plan.md](management-app-plan.md) แต่ต่างจากเอกสารนี้ 2 ข้อ — (1) **ไม่ใช้โหมด Direct-to-contact**: ทุกสายผ่าน Asterisk เพื่อ dialplan และ CDR; (2) **ไม่มี Policy Resolver ใน OpenSIPS**: สิทธิ์การโทร compile เป็นแถว dialplan ใน context `managed` (calling profiles, D7) — ไม่มีแถว = โทรไม่ได้ (fail closed). OpenSIPS ใช้ `domain` module (`attrs` = tenant code 4 หลัก) และเติม code หน้าเลขปลายทางก่อนส่งให้ Asterisk; `opensips.cfg` เป็นของ SIP Deployer

---

## 1. Executive Summary

ระบบใช้ OpenSIPS เป็น SIP Registrar และ SIP Routing Engine กลาง โดย SIP Client ลงทะเบียนกับ OpenSIPS ผ่าน SIP Domain ที่แยกตาม Tenant เช่น `acme.example.com` และ `beta.example.com` จากนั้น OpenSIPS ใช้ Caller Identity, Destination Identity, Tenant Calling Policy และ Routing Directory เพื่อเลือกเส้นทาง

1. **Direct-to-contact:** ส่งสายไปยัง Contact ที่ REGISTER ไว้ใน OpenSIPS `usrloc` เมื่อ Policy อนุญาต
2. **Via-Asterisk:** ส่งสายไปยัง Asterisk Server ที่รับผิดชอบ Tenant/Extension ปลายทาง เมื่อจำเป็นต้องใช้ PBX Dial Plan, IVR, Queue, Ring Group หรือบริการของ Asterisk

Laravel Management Application ทำหน้าที่ Control Plane: จัดการ Tenant, Domain, Extension, Server, Policy และ Provisioning ส่วน OpenSIPS เป็น Data Plane ที่จัดการ SIP แบบ latency ต่ำ ไม่ควรเรียก Laravel API แบบ synchronous สำหรับทุก SIP message

### 1.1 หลักการออกแบบ

- Tenant isolation ต้องบังคับใช้ทั้ง Authentication, Registration, Destination Resolution และ Call Authorization
- SIP username หรือ extension ซ้ำกันได้ระหว่าง Tenant แต่ AOR ต้องไม่ซ้ำกัน เช่น `1001@acme.example.com` กับ `1001@beta.example.com`
- Client ห้ามเลือก IP/port ของ Asterisk โดยส่งค่าเองใน SIP URI หรือ Header แล้วให้ Proxy ส่งต่อโดยไม่ตรวจสอบ
- Fail closed: หากไม่สามารถยืนยัน Tenant, Policy หรือ Route ได้ ให้ปฏิเสธสายใหม่
- SIP Signaling และ RTP Media เป็นคนละเส้นทาง ต้องออกแบบ NAT และ Media Relay แยกกัน
- Failover ทำได้เฉพาะ gateway ที่ได้รับการ provision ว่ารองรับ Tenant/ปลายทางเดียวกันจริง

## 2. Scope and Assumptions

### 2.1 In scope

- OpenSIPS 3.6
- SIP Client REGISTER กับ OpenSIPS โดยตรง
- Tenant-specific SIP Domains
- Digest Authentication
- Tenant authorization และ cross-tenant call policy
- Direct-to-contact และ via-Asterisk routing
- SIP transaction/dialog handling
- NAT handling และ RTPengine integration
- Failure handling, validation และ rollout checklist

### 2.2 Assumptions to confirm before deployment

- OpenSIPS 3.6.x build และ module package ที่ติดตั้งจริง
- Database backend และ schema ที่เลือกใช้ (เช่น OpenSIPS standard DB tables หรือ custom routing directory)
- SIP transport ที่เปิดใช้: UDP/TCP/TLS/WSS ตาม client types
- Public/private IP, firewall, SIP ports และ RTPengine topology
- Asterisk endpoint/trunk configuration และ dialplan convention
- TLS certificates, DNS records และ domain lifecycle
- วิธี sync Tenant Directory จาก Laravel ไปยัง OpenSIPS
- HA strategy สำหรับ OpenSIPS, `usrloc`, dialog state และ RTPengine

## 3. Logical Architecture

```text
                   ┌───────────────────────────────────┐
                   │ Laravel Management Application    │
                   │ Tenants / Domains / Extensions    │
                   │ Servers / Call Policies           │
                   └─────────────────┬─────────────────┘
                                     │ Provisioning / versioned sync
                                     ▼
                   ┌───────────────────────────────────┐
                   │ Routing Directory / Cache         │
                   │ Tenant + Extension + Policy       │
                   │ Server inventory + route version  │
                   └─────────────────┬─────────────────┘
                                     │ trusted data
                                     ▼
┌──────────────┐   SIP   ┌───────────────────────────────────┐
│ SIP Clients  │◄───────►│ OpenSIPS 3.6                      │
│ IP Phone /   │         │ Registrar / Auth / Routing       │
│ Softphone /  │         │ Transaction / Dialog / Policy    │
│ WebRTC       │         └──────────────┬────────────────────┘
└──────────────┘                        │
                       ┌────────────────┴────────────────┐
                       ▼                                 ▼
             ┌────────────────────┐            ┌────────────────────┐
             │ SIP Client Contact │            │ Asterisk A/B/C     │
             │ Direct route       │            │ PBX features       │
             └────────────────────┘            └────────────────────┘

          RTP may flow directly or through RTPengine, depending on policy.
```

### 3.1 Responsibility boundaries

| Component | Responsibilities | Must not be responsible for |
|---|---|---|
| Laravel Control Plane | Tenant lifecycle, domain assignment, extension/server mapping, call policies, provisioning | Processing each SIP packet in the call path |
| OpenSIPS | Registration, SIP authentication, tenant-aware routing, transactions, Record-Route, gateway selection | Business data ownership and arbitrary user-provided destination IPs |
| Asterisk | PBX features, dialplan, IVR, queues, ring groups, voicemail, application integration | Acting as the global tenant directory unless explicitly designed that way |
| RTPengine | SDP/media relay, NAT/media anchoring, codec/media handling as configured | SIP tenant authorization |
| Database/cache | Trusted routing and identity data | Accepting unvalidated client-provided routing instructions |

## 4. SIP Identity and Tenant Model

### 4.1 SIP identity

Example:

- `1001@acme.example.com`
- `1001@beta.example.com`

These are distinct Address-of-Record (AOR) identities. A tenant's domain must be resolved from trusted provisioning, not inferred solely from a display name or untrusted custom header.

### 4.2 Minimum logical data model

| Entity | Important fields | Constraints |
|---|---|---|
| `tenants` | `id`, `name`, `status` | Stable tenant ID; status enforced at runtime |
| `sip_domains` | `id`, `tenant_id`, `domain`, `status` | Unique normalized domain |
| `subscribers` | `id`, `tenant_id`, `username`, `password_hash/HA1` | Unique `(tenant_id, username)`; store secrets securely |
| `extensions` | `id`, `tenant_id`, `extension`, `status` | Unique `(tenant_id, extension)` |
| `asterisk_servers` | `id`, `address`, `port`, `transport`, `status` | Trusted inventory; restrict network access |
| `extension_endpoints` | `extension_id`, `asterisk_server_id`, `routing_mode` | Explicit mapping; no implicit server inference |
| `tenant_call_policies` | `source_tenant_id`, `destination_tenant_id`, `allow_call`, `mode` | Default deny for cross-tenant calls |
| `route_groups` | `id`, `tenant_id` or service scope, `policy` | Gateways grouped by actual service equivalence |
| `routing_versions` | `version`, `published_at`, `checksum` | Supports audit and rollback |

**Security note:** Digest authentication storage requirements depend on the selected algorithm and implementation. Avoid plaintext passwords. Protect HA1 material as authentication secrets, use TLS where possible, rate-limit challenges and failed attempts, and restrict database access.

## 5. Call Routing Decision

### 5.1 Decision sequence

1. Validate SIP message structure and Max-Forwards.
2. Handle in-dialog requests using Record-Route/Route set and transaction rules.
3. For a new request, authenticate the caller against the caller's trusted tenant domain.
4. Resolve the authenticated caller to a tenant and active subscriber/extension.
5. Resolve destination AOR/domain and confirm the destination tenant/domain is active.
6. Check same-tenant or cross-tenant call authorization.
7. Resolve a trusted destination endpoint or Asterisk gateway.
8. Apply route-specific NAT and RTP policy.
9. Create/maintain SIP transaction and relay the request.
10. Apply scoped failure handling; never fail over to an unrelated tenant/server.

### 5.2 Routing modes

| Mode | Use case | Routing behavior |
|---|---|---|
| `direct` | User-to-user calling where PBX features are not required | Resolve destination AOR to a registered Contact using `lookup("location")` |
| `asterisk` | Call requires destination PBX dialplan, IVR, queue, ring group, recording or policy | Route to a trusted Asterisk gateway; preserve/transform Request-URI according to documented dialplan contract |
| `policy` | Product chooses route per call type or tenant policy | Resolve mode from trusted policy and execute the corresponding route |

Direct routing is only appropriate when direct contact reachability, identity mapping, media policy, emergency/recording requirements and call permissions are understood. A registered Contact is not itself proof that the caller is authorized to reach it.

## 6. OpenSIPS 3.6 Configuration Baseline

**Important:** The snippets below are a design baseline. They must be assembled with the exact module versions, database URLs, transports, TLS settings, permissions, and routing-data resolver used in the deployed system. Run `opensips -C -f /etc/opensips/opensips.cfg` and functional tests before enabling service.

### 6.1 Module and parameter baseline

```opensips
loadmodule "sl.so"
loadmodule "tm.so"
loadmodule "rr.so"
loadmodule "maxfwd.so"
loadmodule "signaling.so"
loadmodule "sipmsgops.so"

loadmodule "auth.so"
loadmodule "auth_db.so"
loadmodule "domain.so"
loadmodule "registrar.so"
loadmodule "usrloc.so"

loadmodule "nathelper.so"
# Load rtpengine.so only when RTPengine is deployed and configured.
# loadmodule "rtpengine.so"

modparam("auth_db", "use_domain", 1)
modparam("usrloc", "use_domain", 1)

modparam("registrar", "max_contacts", 5)

# Configure received AVP consistently if using nathelper/registrar NAT handling.
modparam("nathelper", "received_avp", "$avp(rcv)")
modparam("registrar", "received_avp", "$avp(rcv)")

# Configure database URLs and module-specific parameters for the actual deployment.
```

The exact parameter names and availability must be checked against the installed OpenSIPS 3.6 module documentation. Do not copy an RTPengine socket, DB URL, or NAT parameter without verifying the corresponding service and module configuration.

### 6.2 Core request routing skeleton

```opensips
route {
    if (!mf_process_maxfwd_header(10)) {
        send_reply(483, "Too Many Hops");
        exit;
    }

    # CANCEL belongs to the INVITE transaction.
    if (is_method("CANCEL")) {
        if (t_check_trans()) {
            route(RELAY);
        }
        exit;
    }

    # Sequential requests in an established dialog.
    if (has_totag()) {
        if (loose_route()) {
            route(IN_DIALOG);
            exit;
        }

        # ACK to a non-2xx final response is transaction-related.
        if (is_method("ACK") && t_check_trans()) {
            route(RELAY);
            exit;
        }

        send_reply(404, "Dialog Route Not Found");
        exit;
    }

    if (is_method("REGISTER")) {
        route(REGISTRAR);
        exit;
    }

    if (is_method("INVITE")) {
        route(NEW_CALL);
        exit;
    }

    send_reply(405, "Method Not Allowed");
    exit;
}

route[RELAY] {
    if (!t_relay()) {
        sl_reply_error();
    }
    exit;
}

route[IN_DIALOG] {
    # Apply RTPengine only if media anchoring is configured and required.
    # rtpengine_manage();

    route(RELAY);
}
```

**Dialog caveat:** Production handling must distinguish ACK for a 2xx response (dialog route-set, no new transaction in the same way as other requests) from ACK for a non-2xx final response (INVITE transaction). Verify the final script against OpenSIPS 3.6 semantics and packet-level tests. The core skeleton is not sufficient on its own for every retransmission, forked dialog, topology-hiding or failover scenario.

### 6.3 Registration route

```opensips
route[REGISTRAR] {
    # Domain must be provisioned as a local/served SIP domain.
    if (!is_domain_local("$td")) {
        send_reply(403, "Unknown Tenant Domain");
        exit;
    }

    # Prevent a registration whose Request-URI and To domains disagree.
    if ($rd != $td) {
        send_reply(403, "Domain Mismatch");
        exit;
    }

    if (!www_authorize("$td", "subscriber")) {
        www_challenge("$td", "auth");
        exit;
    }

    # Authenticated username must match the registered AOR username.
    if ($au != $tU) {
        send_reply(403, "Authentication Identity Mismatch");
        exit;
    }

    # NAT handling is conditional and must be tested with real phones.
    force_rport();
    # If NAT is detected, use the configured nathelper/registrar approach.
    # Example helper calls must be confirmed for the installed module version.

    if (!save("location")) {
        send_reply(500, "Registration Failed");
    }
    exit;
}
```

The domain lookup and subscriber DB schema must be configured so that credentials are checked within the intended tenant domain. Do not rely on username alone. If aliases, shared devices, delegated registration or provisioning credentials are supported, define separate explicit policy for each.

### 6.4 New-call authorization skeleton

```opensips
route[NEW_CALL] {
    # Validate caller domain and destination domain against trusted inventory.
    if (!is_domain_local("$fd")) {
        send_reply(403, "Invalid Caller Domain");
        exit;
    }

    if (!is_domain_local("$td")) {
        send_reply(404, "Unknown Destination Domain");
        exit;
    }

    if (!proxy_authorize("$fd", "subscriber")) {
        proxy_challenge("$fd", "auth");
        exit;
    }

    if ($au != $fU) {
        send_reply(403, "Authentication Identity Mismatch");
        exit;
    }

    # REQUIRED: resolve caller tenant from authenticated identity.
    # REQUIRED: resolve destination tenant and extension from trusted data.
    # REQUIRED: enforce cross-tenant calling policy (default deny).
    # REQUIRED: choose direct contact or trusted Asterisk gateway.
    # REQUIRED: apply route-specific Record-Route and media policy.

    # Fail closed until a real policy resolver is integrated.
    send_reply(503, "Routing Policy Unavailable");
    exit;
}
```

This route intentionally refuses new calls until a real policy resolver is connected. Do not replace the final rejection with unconditional relay. Caller domain, From header and Request-URI are not sufficient alone to establish tenant authorization; use the authenticated subscriber mapping and trusted routing directory.

## 7. Implementing the Policy Resolver

A production implementation should expose a deterministic lookup contract to OpenSIPS. This may be implemented using a local DB query, cached routing table, or a controlled internal service with bounded latency. Avoid a blocking HTTP call to Laravel on every SIP request.

### 7.1 Suggested resolver output

```json
{
  "policy_version": 42,
  "source_tenant_id": "tenant-acme",
  "destination_tenant_id": "tenant-beta",
  "destination_aor": "1001@beta.example.com",
  "mode": "asterisk",
  "gateway_id": "asterisk-b",
  "gateway_uri": "sip:10.20.0.12:5060",
  "policy_allowed": true
}
```

This is an illustrative data contract, not a built-in OpenSIPS response format. The resolver must validate every field before it is used.

### 7.2 Security rules

- Resolve the caller from the authenticated Digest identity and the trusted subscriber record.
- Resolve the destination from a normalized AOR and an active tenant/domain record.
- Enforce default-deny cross-tenant policy.
- Do not accept a destination gateway from a client-supplied header or arbitrary SIP URI.
- Restrict gateway addresses to a provisioned allowlist and network ACL.
- Use parameterized SQL and proper escaping for all external values.
- Version policy data and support rollback.
- Define cache expiry and behavior when the data source is unavailable.
- Log policy decisions without recording passwords, HA1 secrets or unnecessary personal data.

## 8. Direct-to-Contact and Via-Asterisk Routes

### 8.1 Direct-to-Contact

1. Authenticate caller.
2. Resolve and authorize destination AOR.
3. Confirm destination is active and registered.
4. Use the appropriate AOR/domain for `lookup("location")`.
5. Apply NAT/media policy for the selected Contact.
6. Relay statefully and preserve dialog routing.

**Important:** Validate the exact `lookup()` behavior and how the Request-URI/domain is represented in the chosen OpenSIPS version and location schema. Do not assume that simply changing `$rd` is safe for all cases.

### 8.2 Via-Asterisk

1. Authenticate caller and resolve the destination tenant.
2. Confirm policy requires or permits Asterisk routing.
3. Select the Asterisk server from trusted inventory.
4. Route to that server's provisioned SIP address.
5. Ensure Asterisk accepts SIP requests from OpenSIPS and maps the called identity to the correct Tenant/extension.
6. Prevent routing loops: define a clear trust boundary and distinguish client-originated requests from trusted Asterisk-originated requests.
7. Maintain Record-Route and dialog state consistently.
8. Apply the intended media policy.

Do not send every Asterisk gateway into one global dispatcher set if the gateways serve different tenants or different dialplans. Failover targets must be service-equivalent.

## 9. SIP Transaction, Dialog and Method Handling

| Method/event | Design requirement |
|---|---|
| Initial `INVITE` | Authenticate, authorize, resolve route, create transaction |
| `100 Trying` | Transaction handling |
| `180 Ringing` / `183` | Relay provisional response and preserve transaction |
| `200 OK` to INVITE | Relay response; dialog route set must remain valid |
| `ACK` to 2xx | Follow dialog route set; do not treat as a new ordinary transaction |
| `ACK` to non-2xx | Match the INVITE transaction |
| `CANCEL` | Match existing INVITE transaction; do not route as a new call |
| `BYE` | Follow dialog route set; relay statefully |
| re-INVITE / UPDATE | Preserve dialog routing; apply media policy consistently |
| Retransmissions | Let transaction/dialog logic handle according to SIP semantics |
| Forked calls | Ensure each early/confirmed dialog is handled correctly |
| Topology hiding | If enabled, configure and test separately; it is not implied by Record-Route |

The exact implementation should be verified with SIP traces for 2xx and non-2xx ACK, CANCEL races, re-INVITE, early media, multiple branches and BYE from either side.

## 10. NAT and RTP Media Architecture

### 10.1 SIP NAT

- Use `force_rport()` where appropriate for symmetric SIP response routing.
- Use `nathelper`/`registrar` handling only after confirming the correct module parameters and NAT detection logic for the deployed OpenSIPS 3.6 build.
- Test SIP Contact rewriting, received address storage, keepalives and expiration with actual IP phones and softphones.
- Consider SIP Outbound/Path where supported and required by the client topology.
- Use TLS/WSS for clients where the security model requires it; protect certificates and private keys.

### 10.2 RTP

OpenSIPS is not itself an RTP media server. RTPengine can anchor and relay media when required.

Choose one of the following per call class:

- **Direct media:** only if endpoint reachability, NAT, security and media policy allow it.
- **RTPengine-anchored media:** when endpoints are behind NAT, WebRTC is used, media must be anchored, or network policy requires a relay.
- **Asterisk-anchored media:** when Asterisk features or dialplan behavior require media to traverse Asterisk.

Do not assume that SIP routing determines RTP routing. Configure RTPengine interfaces, advertised addresses, firewall UDP ranges, codecs, ICE/DTLS-SRTP behavior and Asterisk media settings as one end-to-end design.

## 11. Failure Handling and Failover Policy

| Failure | Expected behavior |
|---|---|
| Invalid credentials | Challenge or reject; rate-limit repeated failures |
| Unknown/disabled tenant domain | Reject; do not register or route |
| Caller not authorized for destination tenant | `403 Forbidden` |
| Unknown destination AOR | `404 Not Found` |
| No route/policy data | Fail closed for new calls |
| Routing DB/cache unavailable | Use only a documented safe cache policy; otherwise reject new calls |
| Destination Contact unreachable | Apply transaction timeout and report failure |
| Asterisk unavailable | Fail over only to an equivalent authorized gateway |
| RTPengine unavailable | Reject calls that require it, or use a prevalidated fallback policy |
| Tenant disabled during active call | Define whether existing dialogs continue or are terminated; do not leave behavior implicit |
| OpenSIPS node failure | Requires an HA design for registration/dialog state and client re-registration |

### 11.1 Retry safeguards

- Do not blindly retry an INVITE after a final response or after call setup may have reached a destination.
- Define retryable response codes and transaction state explicitly.
- Do not fail over across tenant boundaries.
- Add loop detection and a bounded maximum number of route attempts.
- Record a correlation ID and policy version for troubleshooting.
- Avoid logging full Authorization headers or credentials.

## 12. Observability and Security Operations

Recommended operational controls:

- Structured logs with Call-ID, source tenant, destination tenant, route mode, gateway ID, policy version and final SIP response.
- Metrics for REGISTER success/failure, authentication failures, active contacts, INVITE outcomes, response latency, gateway health, RTPengine health and route failures.
- Rate limits for REGISTER and INVITE; fail2ban or equivalent controls where appropriate.
- Firewall rules allowing SIP traffic only from expected sources and limiting Asterisk gateway access to trusted OpenSIPS nodes.
- TLS certificate expiry monitoring.
- Database backups and tested restoration.
- Configuration versioning, change review and rollback.
- Privacy-aware log retention and access control.

## 13. Validation and Test Plan

### 13.1 Configuration validation

```bash
sudo opensips -V
sudo opensips -C -f /etc/opensips/opensips.cfg
sudo systemctl status opensips
sudo journalctl -u opensips -n 100 --no-pager
```

Syntax validation is necessary but not sufficient. Confirm that every referenced module exists, every parameter is supported by the installed 3.6 build, the database schema matches the module configuration, and the RTPengine socket is reachable.

### 13.2 Functional test matrix

| Test | Expected result |
|---|---|
| Register valid `1001@acme.example.com` | Success |
| Register Acme credentials against Beta domain | Reject |
| Register unknown domain | Reject |
| Two tenants use extension `1001` | Distinct AORs and contacts |
| Same-tenant direct call | Correct authorized contact |
| Cross-tenant call without permission | Reject |
| Cross-tenant call with permission | Route only to approved destination |
| Call requiring PBX features | Route through correct Asterisk |
| CANCEL while ringing | Cancel matching INVITE branch(es) |
| ACK to 2xx | Correct dialog route |
| ACK to non-2xx | Correct transaction handling |
| BYE from either endpoint | Dialog terminates normally |
| re-INVITE / hold / resume | Dialog and media remain correct |
| Client behind NAT | Signaling and audio work in both directions |
| RTPengine down for a call requiring relay | Safe, documented failure |
| Asterisk A down | Only equivalent approved gateway considered |
| Routing directory unavailable | Fail-closed behavior verified |
| Tenant disabled | New registration/calls follow suspension policy |
| Spoofed destination IP/header | Rejected or ignored; no open relay |

### 13.3 Recommended rollout

1. Lab validation with one OpenSIPS and one Asterisk.
2. Add a second Asterisk and prove cross-server routing.
3. Add tenant-domain separation and duplicate extension tests.
4. Add cross-tenant allow/deny policies.
5. Test NAT and RTPengine with actual phone/softphone clients.
6. Run failure tests for Asterisk, routing data source and RTPengine.
7. Perform security review and load test.
8. Enable Production tenants gradually with rollback ready.

## 14. Decisions Required Before Production

The following values must be confirmed before a complete deployable `opensips.cfg` can be finalized:

- Exact OpenSIPS 3.6.x version/build and installed modules
- DB engine, DB URL, standard/custom table schema
- SIP domains and DNS/TLS strategy
- Public/private addresses and SIP transports
- Asterisk gateway addresses, ports and trust relationships
- Whether Asterisk sees the original tenant domain or a normalized internal dial string
- Whether direct calls may bypass Asterisk features, recording or policy
- RTPengine deployment, interfaces and media relay requirements
- OpenSIPS HA strategy and persistence/replication for `usrloc` and dialog state
- Tenant suspension behavior for active calls
- Logging, metrics, rate limits and operational response process

## 15. Official References

- OpenSIPS 3.6 Manual: https://docs.opensips.org/manual/3-6/
- OpenSIPS modules documentation: https://opensips.org/docs/modules/3.6.x/
- `auth_db`: https://opensips.org/docs/modules/3.6.x/auth_db.html
- `registrar`: https://opensips.org/docs/modules/3.6.x/registrar.html
- `usrloc`: https://opensips.org/docs/modules/3.6.x/usrloc.html
- `tm`: https://opensips.org/docs/modules/3.6.x/tm.html
- `rr`: https://opensips.org/docs/modules/3.6.x/rr.html
- `nathelper`: https://opensips.org/docs/modules/3.6.x/nathelper.html
- `dispatcher`: https://opensips.org/docs/modules/3.6.x/dispatcher.html
- `rtpengine`: https://opensips.org/docs/modules/3.6.x/rtpengine.html

Check each module's 3.6 documentation against the exact installed build before deployment.

---

**Document status:** Architecture baseline for engineering review. The policy resolver, DB schema integration, NAT/media settings, transport configuration, and final failure routes remain deployment-specific implementation tasks.
