# FieldPulse - ISP Field Service Management Platform

## Overview

FieldPulse is an end-to-end ISP field service and operations management platform built with **PHP 8.2 + Bootstrap 5 + PostgreSQL**. It handles ticket lifecycle management, technician scheduling, field mapping, SLA tracking, vendor management, team coordination, and installation profiles.

## Tech Stack

- **Backend**: PHP 8.2 (built-in dev server on port 5000)
- **Database**: PostgreSQL via PDO
- **Frontend**: HTML5, Bootstrap 5.3, Bootstrap Icons, vanilla JS
- **Charts**: Chart.js 4.x (CDN)
- **Maps**: Leaflet.js 1.9 + OpenStreetMap (CDN)
- **Auth**: PHP sessions

## Project Structure

```
/
├── index.php          — URL router (front controller)
├── config.php         — DB connection, session, auth helpers, password migration
├── includes/
│   ├── header.php     — HTML head + Bootstrap CSS + sidebar
│   └── footer.php     — Bootstrap JS + Chart.js
├── pages/
│   ├── login.php
│   ├── logout.php
│   ├── dashboard.php
│   ├── tickets.php
│   ├── ticket-detail.php
│   ├── create-ticket.php
│   ├── customers.php
│   ├── schedule.php
│   ├── map.php
│   ├── team.php
│   ├── analytics.php
│   ├── admin.php
│   ├── installations.php
│   └── portal.php     — Public customer self-service
├── api/               — JSON endpoints (same business logic, REST API)
│   ├── auth.php
│   ├── tickets.php
│   ├── customers.php
│   ├── users.php
│   ├── hubs.php
│   ├── vendors.php
│   ├── teams.php
│   ├── fault-types.php
│   ├── sla-configs.php
│   ├── installations.php
│   ├── installation-comments.php
│   ├── dashboard.php
│   ├── analytics.php
│   ├── app-config.php
│   └── audit-logs.php
└── assets/
    └── style.css      — Custom overrides on Bootstrap
```

## URL Routing

The PHP built-in server uses `index.php` as the router:
- `/` → redirect to `/dashboard`
- `/login`, `/logout`, `/portal` — public pages
- `/dashboard`, `/tickets`, `/ticket/:id`, `/customers`, `/schedule`, `/map`, `/team`, `/analytics`, `/admin`, `/installations` — protected pages
- `/api/*` — JSON API endpoints

## Authentication

- Session-based (`$_SESSION['user_id']`, `$_SESSION['user']`)
- Passwords: bcrypt via `password_hash()` / `password_verify()`
- **Password migration**: On first boot, all legacy scrypt-hashed passwords (from the previous Node.js version) are reset to `admin123`
- Default admin: username `admin`, password `admin123`

## Features

- **Dashboard** — Real stats from DB, 24h hourly ticket chart, recent incidents
- **Tickets** — Full CRUD, role-scoped list, status/priority filters, search
- **Ticket Detail** — Comments, RCA, status/priority/assignment update, SLA breach indicator
- **Customers** — Search, CRUD, admin delete
- **Schedule** — Weekly calendar with ticket placement by date
- **Field Map** — Leaflet map with hub/ticket/engineer markers, auto-dispatch
- **Team** — Staff, vendor, and team management with modals
- **Analytics** — 30-day trend, status/priority donut charts, MTTR, SLA rate, engineer leaderboard
- **Admin** — Fault types, SLA configs, hubs, app settings, audit log
- **Installations** — Installation profiles with stage tracking and vendor comments
- **Portal** — Public customer ticket lookup by account number

## Role-Based Access

| Role | Access |
|------|--------|
| admin | All pages + delete |
| project_admin | All pages |
| supervisor-fiber | Tickets, Customers, Field, Installations, Team, Analytics |
| supervisor-noc | Tickets, Customers, Field, Team, Analytics |
| cx_supervisor | Tickets, Customers, Analytics |
| cx | Tickets, Customers |
| engineer | Tickets (assigned), Schedule, Map, Installations |
| vendor | Installations only (assigned profiles) |

## User Preferences

Preferred communication style: Simple, everyday language.
