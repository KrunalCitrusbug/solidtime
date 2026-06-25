# SolidTime — Production Deployment Plan

**Document version:** 1.1  
**Date:** June 2026  
**Project:** Custom SolidTime (Laravel + Vue/Inertia) for Citrusbug  
**Prepared for:** Internal IT / DevOps / Management review  

---

## 1. Executive Summary

This document defines how to deploy the **customized SolidTime** instance from development to production. The application is a self-hosted time-tracking platform based on [solidtime.io](https://www.solidtime.io/) with Citrusbug-specific role rules, UI changes, and compliance features (8-hour entry investigation).

**Recommended approach for Citrusbug (50–70 users):** Docker Compose on a **single VPS** or **on-premise Linux server**, with PostgreSQL, queue worker, scheduler, and SMTP mail. Estimated monthly cloud cost: **$40–90 USD** depending on platform. Estimated deployment timeline: **3–4 weeks** including staging, UAT, and go-live.

---

## 2. Scope & Functional Requirements

### 2.1 Base Platform (SolidTime)

| Area | Requirement |
|------|-------------|
| Time tracking | Start/stop timer, manual entries (role-restricted) |
| Projects & tasks | Project assignment, task visibility |
| Clients | Client management (admin) |
| Reporting | Overview, weekly, attendance, detailed reports |
| Organizations | Multi-org support, invitations, API tokens |
| Authentication | Email login, 2FA (Jetstream), session-based web auth |
| PDF export | Gotenberg service for document generation |

### 2.2 Custom Requirements (Citrusbug Build)

| # | Requirement | Status |
|---|-------------|--------|
| R1 | **Employee role:** timer only; description required on stop; no edit of completed entries; no manual entry; no calendar | Implemented |
| R2 | **Admin/Owner:** full access; dashboard; manual entry for any employee; edit all entries | Implemented |
| R3 | **Manager:** org-wide **view only** (auditor); all entries & reports; no create/edit | Implemented |
| R4 | **Team Lead:** view only, scoped to **assigned projects**; member filter on Reporting only | Implemented |
| R5 | **Dashboard:** Admin/Owner only; default landing page → `/time` | Implemented |
| R6 | **Calendar:** removed for all users (`/calendar` redirects to `/time`) | Implemented |
| R7 | **Reporting filters:** Tags, Billable, Rounding removed; Member filter hidden for employees | Implemented |
| R8 | **Timesheet:** view-only for Employee / Team Lead / Manager | Implemented |
| R9 | **Time page:** grouped by week (collapsible); member filter for Manager+ | Implemented |
| R10 | **Long entry investigation:** flag entries > 8h; email admins; investigation page with reason | Implemented |
| R11 | **Session timeout:** configurable via `SESSION_LIFETIME` (default 120 min) | Configurable |
| R12 | **Timer auto-stop (weekend / 8h):** not required; system does **not** auto-stop | By design |

### 2.3 Non-Functional Requirements

| Area | Target |
|------|--------|
| Availability | 99% uptime (business hours); acceptable for internal tool |
| Concurrent users | 50–70 registered users; ~15–25 concurrent at peak (business hours) |
| Response time | < 2s for typical page loads |
| Data residency | India or chosen region (cloud-dependent) |
| Backup | Daily DB backup; 30-day retention minimum |
| Security | HTTPS in production, secrets in env (not in git), least-privilege DB user |
| License | **AGPL v3** — self-hosting obligations apply (see Section 11) |

---

## 3. Technical Architecture

### 3.1 Application Stack

```
┌─────────────────────────────────────────────────────────────┐
│                        Users (Browser)                       │
└────────────────────────────┬────────────────────────────────┘
                             │ HTTPS
┌────────────────────────────▼────────────────────────────────┐
│  Reverse Proxy (Nginx / Traefik / Cloud LB)                  │
│  TLS termination, optional WAF                                │
└────────────────────────────┬────────────────────────────────┘
                             │
┌────────────────────────────▼────────────────────────────────┐
│  App Container (FrankenPHP / Laravel Octane)  :8000          │
│  - Web UI (Vue + Inertia)                                   │
│  - REST API (/api/v1)                                       │
└──────┬──────────────────┬──────────────────┬────────────────┘
       │                  │                  │
       ▼                  ▼                  ▼
┌──────────────┐  ┌──────────────┐  ┌──────────────────┐
│ PostgreSQL 15│  │ Queue Worker │  │ Scheduler        │
│ (database)   │  │ (emails,jobs)│  │ (cron: 8h flags, │
│              │  │              │  │  still-running)  │
└──────────────┘  └──────────────┘  └──────────────────┘
       │
       ▼
┌──────────────┐  ┌──────────────┐
│ Gotenberg 8  │  │ SMTP (Gmail / │
│ (PDF)        │  │ SendGrid/SES) │
└──────────────┘  └──────────────┘
```

### 3.2 Production Services (Docker Compose)

| Service | Image | Purpose |
|---------|-------|---------|
| `app` | Custom `solidtime` image | HTTP server (Octane/FrankenPHP) |
| `database` | `postgres:15` | Primary data store |
| `queue` | Same app image, `CONTAINER_MODE=worker` | Background jobs (mail, exports) |
| `scheduler` | Same app image, `CONTAINER_MODE=scheduler` | Laravel schedule (every 10 min tasks) |
| `gotenberg` | `gotenberg/gotenberg:8` | PDF generation |

### 3.3 Scheduled Background Tasks (Production)

| Task | Frequency | Purpose |
|------|-----------|---------|
| `time-entry:send-still-running-mails` | Every 10 min | Email employee if timer > 8h |
| `time-entry:flag-long-entries` | Every 10 min | Flag long entries; notify admins |
| `auth:send-mails-expiring-api-tokens` | Every 10 min | API token expiry warnings |

### 3.4 Minimum Server Specifications

| Tier | Users | vCPU | RAM | Disk | Notes |
|------|-------|------|-----|------|-------|
| **Small** | < 25 | 2 | 4 GB | 40 GB SSD | Dev/staging only |
| **Medium** | 50–70 | 4 | 8 GB | 80 GB SSD | **Recommended for Citrusbug** |
| **Large** | 100+ | 8 | 16 GB | 160 GB SSD | Not required at current scale |

---

## 4. Deployment Platforms & Cost Comparison

Estimates are **monthly USD**, excluding one-time setup labour. Prices vary by region (India/US/EU).

### 4.1 Option A — Single VPS (Recommended)

**Providers:** Hetzner, DigitalOcean, Linode, Vultr  

| Item | Spec | Est. Cost |
|------|------|-----------|
| VPS | 4 vCPU / 8 GB RAM / 80 GB SSD | $24–48 |
| Domain | `.com` or subdomain | $1–2 |
| SSL | Let's Encrypt (free) | $0 |
| Email | Gmail SMTP / SendGrid free tier | $0–15 |
| Backups | Provider snapshot or Borg to S3 | $5–10 |
| **Total** | | **$30–65 / month** |

**Pros:** Low cost, simple ops, matches current local Docker Compose model  
**Cons:** Single point of failure; manual scaling  

---

### 4.2 Option B — AWS

| Item | Spec | Est. Cost |
|------|------|-----------|
| EC2 | `t3.medium` (2 vCPU, 4 GB) — app + worker on one or two instances | $30–60 |
| RDS PostgreSQL | `db.t3.micro` or `db.t3.small` | $15–35 |
| S3 | File storage (exports, uploads) | $3–10 |
| SES | Transactional email | $1–5 |
| ALB (optional) | Load balancer + HTTPS | $18–25 |
| Route 53 | DNS | $1 |
| **Total** | | **$55–120 / month** |

**Pros:** Enterprise-grade, backups, scaling, IAM  
**Cons:** Higher cost and operational complexity  

---

### 4.3 Option C — Microsoft Azure

| Item | Spec | Est. Cost |
|------|------|-----------|
| App Service / VM | B2 or D2s v3 | $55–90 |
| Azure Database for PostgreSQL | Burstable B1ms | $30–50 |
| Blob Storage | Files | $3–8 |
| Azure Communication Services / SendGrid | Email | $5–15 |
| **Total** | | **$95–165 / month** |

**Pros:** Good if org already on Microsoft 365 / Azure AD  
**Cons:** Similar complexity to AWS  

---

### 4.4 Option D — On-Premise (Existing Citrusbug Server)

| Item | Est. Cost |
|------|-----------|
| Hardware | $0 (existing) |
| Static IP / domain | $1–2 |
| SMTP | $0–15 |
| UPS / maintenance | Internal |
| **Total** | **$0–20 / month** (external services only) |

**Pros:** Lowest recurring cost; data stays in-house  
**Cons:** IT team owns uptime, backups, security patches  

---

### 4.5 Option E — SolidTime Cloud (SaaS)

| Item | Notes |
|------|-------|
| Cost | Per [solidtime.io](https://www.solidtime.io/) pricing |
| Custom code | **Not supported** — Citrusbug customizations would not apply |

**Not suitable** for this customized build.

---

### 4.6 Cost Summary Matrix

| Platform | Monthly (est.) | Setup Effort | Best For |
|----------|----------------|--------------|----------|
| VPS (Hetzner/DO) | $30–65 | Low | **Citrusbug production (50–70 users)** |
| On-premise | $0–20 | Medium | Existing infra, strict data policy |
| AWS | $90–190 | High | Future scale / compliance |
| Azure | $95–165 | High | Microsoft-centric org |

---

## 5. Environment Configuration

### 5.1 Required Environment Variables (Production)

| Variable | Example / Notes |
|----------|-----------------|
| `APP_ENV` | `production` |
| `APP_DEBUG` | `false` |
| `APP_URL` | `https://time.citrusbug.com` |
| `APP_KEY` | Generate: `php artisan key:generate` |
| `APP_FORCE_HTTPS` | `true` |
| `DB_*` | PostgreSQL host, user, password, database |
| `QUEUE_CONNECTION` | `database` (or `redis` if Redis added) |
| `MAIL_*` | Production SMTP (not Mailpit) |
| `SESSION_LIFETIME` | `120` (minutes) — adjust per policy |
| `PASSPORT_*` | OAuth keys (generate once per environment) |
| `SUPER_ADMINS` | Comma-separated admin emails |
| `GOTENBERG_URL` | `http://gotenberg:3000` |
| `FILESYSTEM_DISK` | `local` or `s3` for cloud storage |
| `SCHEDULING_TASK_*` | Enable long-entry and still-running mail tasks |

### 5.2 Security Checklist

- [ ] Never commit `.env`, `app.env`, or secrets to git  
- [ ] Use strong DB password (20+ chars)  
- [ ] Enable HTTPS with valid certificate  
- [ ] Restrict DB port to internal network only  
- [ ] Rotate Gmail app passwords / use dedicated SMTP service  
- [ ] Set `APP_ENABLE_REGISTRATION=false` after initial setup (optional)  
- [ ] Configure firewall: allow 80/443 only  

---

## 6. Deployment Process

### 6.1 Build Pipeline (CI/CD Recommended)

```bash
# 1. Install dependencies & build frontend
npm ci
npm run build

# 2. Build production Docker image
docker build -f docker/prod/Dockerfile -t solidtime:citrusbug-v1.0.0 .

# Or for incremental local overlay (dev/staging only):
docker build -f Dockerfile.weekly -t solidtime-local:latest .

# 3. Push image to registry (production)
docker tag solidtime:citrusbug-v1.0.0 registry.example.com/solidtime:citrusbug-v1.0.0
docker push registry.example.com/solidtime:citrusbug-v1.0.0
```

### 6.2 Deploy to Server

```bash
# On production host
docker compose pull          # if using registry
docker compose up -d --force-recreate app scheduler queue

# Run migrations (first deploy or after updates)
docker compose exec app php artisan migrate --force

# One-time: flag existing long entries (optional)
docker compose exec app php artisan time-entry:flag-long-entries
```

### 6.3 Post-Deploy Verification

| Check | Command / Action |
|-------|------------------|
| App health | `curl -I https://time.example.com` → 200 |
| Login | Admin login works |
| Timer | Employee can start/stop timer |
| Roles | Manager view-only; Team Lead project scope |
| Investigation page | Admin sees `/time-log-investigation` |
| Scheduler | `docker compose logs scheduler` — no errors |
| Queue | `docker compose logs queue` — mails processing |
| Mail | Trigger test notification / long-entry alert |

---

## 7. Timeline & Phases

| Phase | Duration | Activities | Deliverable |
|-------|----------|------------|-------------|
| **1. Planning & sign-off** | 3–5 days | Approve platform, domain, roles, SMTP | Signed deployment plan |
| **2. Staging environment** | 5–7 days | Provision server, Docker Compose, env, SSL | Staging URL live |
| **3. Data & config** | 2–3 days | Org setup, users, projects, role assignment | Test org configured |
| **4. UAT** | 5–7 days | Admin, Manager, Team Lead, Employee test cases | UAT sign-off sheet |
| **5. Production deploy** | 1–2 days | Image deploy, migrate, DNS cutover | Production live |
| **6. Training** | 2–3 days | Admin + employee quick guides | Training complete |
| **7. Hypercare** | 10–14 days | Monitor logs, fix issues, tune session/mail | Stable operations |

**Total estimated duration: 3–4 weeks** (can compress to 2 weeks if staging exists and UAT is light).

### 7.1 Milestone Gantt (Simplified)

```
Week 1:  [Planning====][Staging setup========]
Week 2:  [Staging QA==][UAT==================]
Week 3:  [Prod deploy=][Training=][Hypercare===========>
Week 4:  [Hypercare continues=======================]
```

---

## 8. Roles & Responsibilities

| Role | Responsibility |
|------|----------------|
| **Project Owner** | Approve requirements, UAT sign-off, go-live decision |
| **Dev Team** | Build image, migrations, custom feature fixes |
| **DevOps / IT** | Server, DNS, SSL, backups, monitoring |
| **HR / Admin** | User list, role mapping (Employee / Team Lead / Manager) |
| **Security** | Review secrets, HTTPS, access policy |

---

## 9. Backup & Disaster Recovery

| Item | Strategy | RPO | RTO |
|------|----------|-----|-----|
| PostgreSQL | Daily `pg_dump` + weekly full snapshot | 24 h | 4 h |
| App storage volume | Sync to S3 / secondary disk | 24 h | 4 h |
| Env / secrets | Encrypted vault (1Password / AWS SM) | — | 1 h |
| Docker images | Tagged in container registry | — | 1 h |

**Restore test:** Perform one restore drill before go-live.

---

## 10. Monitoring & Operations

| Monitor | Tool (suggested) | Alert |
|---------|------------------|-------|
| Uptime | UptimeRobot / Pingdom | Email/Slack if down 5 min |
| Disk / CPU | Provider metrics or Netdata | > 80% usage |
| App errors | Laravel log → Papertrail / Loki | Error spike |
| Queue failures | `failed_jobs` table | Daily review |
| DB size | Postgres metrics | > 80% disk |

**Routine maintenance:** Monthly OS patches, quarterly SolidTime upstream review (security updates).

---

## 11. Licensing & Compliance

- SolidTime is licensed under **GNU AGPL v3**.  
- Self-hosting requires compliance with AGPL obligations (source availability to users if modified version is deployed over a network).  
- Maintain a fork or private repo with custom changes documented.  
- Consult legal if exposing the app to external clients or contractors.

---

## 12. Risks & Mitigations

| Risk | Impact | Mitigation |
|------|--------|------------|
| SMTP rate limits (Gmail) | Missed admin alerts | Use SendGrid / Amazon SES |
| Single server failure | Downtime | Backups + documented restore; optional warm standby |
| Forgotten running timers | Inflated hours | 8h employee email + admin investigation page |
| Secret leak in env file | Security breach | Secrets manager; never commit env files |
| Upstream SolidTime breaking changes | Upgrade pain | Pin image version; test upgrades in staging |
| AGPL compliance gap | Legal exposure | Document customizations; legal review |

---

## 13. Go-Live Checklist

### Pre-go-live
- [ ] Staging UAT signed off by Admin, Manager, Team Lead, Employee representatives  
- [ ] Production `.env` reviewed (no debug, HTTPS on)  
- [ ] DNS + SSL configured  
- [ ] Database backup tested  
- [ ] All users imported / invited with correct roles  
- [ ] Projects and Team Lead assignments configured  
- [ ] Mail delivery verified (investigation + still-running alerts)  
- [ ] `SESSION_LIFETIME` agreed with HR/IT policy  

### Go-live day
- [ ] Deploy production image  
- [ ] Run `php artisan migrate --force`  
- [ ] Smoke test all roles  
- [ ] Announce URL and support contact  

### Post-go-live (Week 1)
- [ ] Daily log review (app, scheduler, queue)  
- [ ] Review investigation page for flagged entries  
- [ ] Collect user feedback  
- [ ] Document runbook for IT  

---

## 14. Support & Escalation

| Level | Contact | Scope |
|-------|---------|-------|
| L1 | Internal IT / HR admin | Login, password, role requests |
| L2 | Dev team | Bugs, configuration, deployments |
| L3 | SolidTime upstream | Core product issues (GitHub / Discord) |

---

## 15. Appendix

### A. Current Local Development Deploy (Reference)

```bash
cd /path/to/solidtime
npm run docker:deploy
npm run docker:migrate
```

### B. Key Custom Routes

| Route | Access |
|-------|--------|
| `/time` | All authenticated users (default home) |
| `/dashboard` | Admin, Owner |
| `/time-log-investigation` | Admin, Owner |
| `/calendar` | Redirects to `/time` |

### C. Document Revision History

| Version | Date | Author | Changes |
|---------|------|--------|---------|
| 1.0 | Jun 2026 | Dev Team | Initial deployment plan |
| 1.1 | Jun 2026 | Dev Team | Sized for 50–70 company users |

---

*End of document*
