# AI DebugScan v3 — UI & Reporting Specification

**Version:** 1.0
**Date:** 2026-04-07
**Status:** Final — AI Development Reference
**Companion Documents:** `ActiveDesign.md` (design system), `AI_DebugScan_v3_Development_Specification.md` (technical spec)

> **Instruction to AI:** This document defines every page, every component, and every report format for AI DebugScan v3. Read this alongside `ActiveDesign.md` (the master design system) and `AI_DebugScan_v3_Development_Specification.md` (the technical spec) before generating any UI code. All design tokens (colors, spacing, shadows, typography) are defined in `ActiveDesign.md` Section VI. This document specifies how those tokens are applied to each page and component of this application specifically.

---

## TABLE OF CONTENTS

1. [Global Layout & Shell](#1-global-layout--shell)
2. [Design Tokens Quick Reference](#2-design-tokens-quick-reference)
3. [Shared Components](#3-shared-components)
4. [Authentication Pages](#4-authentication-pages)
5. [Admin Module Pages](#5-admin-module-pages)
6. [Tenant Module Pages](#6-tenant-module-pages)
7. [Scan Progress Page](#7-scan-progress-page)
8. [Report Formats](#8-report-formats)
9. [Modal Specifications](#9-modal-specifications)
10. [Empty States](#10-empty-states)
11. [Status Badges & Health Indicators](#11-status-badges--health-indicators)
12. [Responsive Behaviour](#12-responsive-behaviour)
13. [Error Pages](#13-error-pages)
14. [CSS Architecture](#14-css-architecture)

---

## 1. GLOBAL LAYOUT & SHELL

### 1.1 Page Shell Structure

Every authenticated page uses a two-column shell:

```
┌──────────────────────────────────────────────────────────────┐
│  SIDEBAR (240px)  │  MAIN CONTENT AREA (flex-1)              │
│                   │  ┌────────────────────────────────────┐  │
│  [Logo]           │  │ TOPBAR (56px, sticky)              │  │
│  ─────────────    │  └────────────────────────────────────┘  │
│  Nav items        │  ┌────────────────────────────────────┐  │
│                   │  │                                    │  │
│  (bottom)         │  │  PAGE CONTENT (padding: 24px)      │  │
│  Token balance    │  │                                    │  │
│  Logout           │  │                                    │  │
│                   │  └────────────────────────────────────┘  │
└──────────────────────────────────────────────────────────────┘
```

| Property | Value |
|---|---|
| Sidebar width | 240px (expanded), 64px (collapsed icon-only) |
| Sidebar background | `#2D3436` (dark) |
| Sidebar text | `#FFFFFF` (primary), `#95A5A6` (secondary/metadata) |
| Main content background | `#F8F9FA` |
| Topbar height | 56px |
| Topbar background | `#FFFFFF` |
| Topbar border-bottom | `1px solid #DFE3E6` |
| Topbar shadow | `0 1px 3px rgba(0,0,0,0.08)` (Elevation 1) |
| Page content padding | 24px all sides |
| Max content width | 1080px (centered within main area) |

### 1.2 Sidebar — Admin

```html
<nav aria-label="Admin navigation">

  <!-- Logo block -->
  <div class="sidebar-logo">
    <img src="/assets/img/logo.svg" alt="AI DebugScan" height="32">
    <span>AI DebugScan</span>           <!-- 14px, 600 weight, white, hide when collapsed -->
  </div>

  <!-- Nav items -->
  <ul>
    <li><a href="/admin">
      [icon: grid-2x2]  Dashboard
    </a></li>
    <li><a href="/admin/tenants">
      [icon: users]  Tenants
    </a></li>
    <li><a href="/admin/settings">
      [icon: settings]  Settings
    </a></li>
    <li><a href="/admin/errors">
      [icon: triangle-alert]  Error Log
      <!-- If unresolved errors exist: show count badge -->
      <span class="badge badge-danger">3</span>
    </a></li>
  </ul>

  <!-- Bottom section -->
  <div class="sidebar-footer">
    <span class="sidebar-user-name">[Admin display name]</span>
    <a href="/auth/logout">[icon: log-out]  Logout</a>
  </div>

</nav>
```

**Nav item states:**

| State | Background | Text | Left border |
|---|---|---|---|
| Default | Transparent | `#95A5A6` | None |
| Hover | `rgba(255,255,255,0.07)` | `#FFFFFF` | None |
| Active (current page) | `rgba(0,102,255,0.15)` | `#FFFFFF` | `3px solid #0066FF` |

Nav item layout: height 44px, padding 0 20px, flex row, icon 18px + 12px gap + label 14px 500 weight.

### 1.3 Sidebar — Tenant

```html
<nav aria-label="Tenant navigation">

  <div class="sidebar-logo">
    <img src="/assets/img/logo.svg" alt="AI DebugScan" height="32">
    <span>AI DebugScan</span>
  </div>

  <ul>
    <li><a href="/dashboard">
      [icon: layout-dashboard]  Dashboard
    </a></li>
    <li><a href="/projects">
      [icon: folder]  Projects
    </a></li>
  </ul>

  <!-- Token balance display (read-only) -->
  <div class="sidebar-token-block">
    <span class="label">SCAN TOKENS</span>
    <span class="token-count">[n]</span>   <!-- 24px, 700 weight, #0066FF -->
    <span class="sublabel">available</span>
  </div>

  <div class="sidebar-footer">
    <span class="sidebar-user-name">[Tenant display name]</span>
    <a href="/auth/logout">[icon: log-out]  Logout</a>
  </div>

</nav>
```

### 1.4 Topbar

```
┌─────────────────────────────────────────────────────────────┐
│  [≡ collapse toggle]  [Page title / breadcrumb]     [user]  │
└─────────────────────────────────────────────────────────────┘
```

| Element | Spec |
|---|---|
| Collapse toggle | `<button type="button">` icon: `menu`, 20px, `#636E72`, left edge |
| Page title | `<h1>` — but styled as H4 (16px, 600 weight, `#2D3436`). This is the only instance H1 is styled small — it remains the structural H1 for screen readers but is visually compact in a topbar context. |
| Breadcrumb (secondary pages) | `<nav aria-label="breadcrumb">` > `<ol>` — 12px, `#95A5A6`, separator `›` |
| User area (right) | Display name 13px `#636E72` + avatar 32px circular |

---

## 2. DESIGN TOKENS QUICK REFERENCE

All tokens defined fully in `ActiveDesign.md` Section VI. Quick reference for tokens used throughout this document:

```
PRIMARY BLUE:   #0066FF   (hover: #0052CC, active: #003D99)
SUCCESS:        #2DCE89   (light bg: #E8F8F3)
DANGER:         #FF6358   (light bg: #FFE8E5)
WARNING:        #FFAB2F   (light bg: #FFF5E5)
INFO:           #2196F3   (light bg: #E3F2FD)

TEXT PRIMARY:   #2D3436
TEXT SECONDARY: #636E72
TEXT MUTED:     #95A5A6

BORDER:         #DFE3E6
ROW BORDER:     #EEF0F3
BG LIGHT:       #F8F9FA
BG OFF-WHITE:   #FAFBFC
WHITE:          #FFFFFF

SHADOW-1: 0 1px 3px rgba(0,0,0,0.08)
SHADOW-2: 0 2px 6px rgba(0,0,0,0.10)
SHADOW-3: 0 4px 12px rgba(0,0,0,0.12)
SHADOW-4: 0 8px 24px rgba(0,0,0,0.15)

SPACING: 4 / 8 / 12 / 16 / 20 / 24 / 32 / 40 / 48 / 64px
RADIUS: 4px (small) / 6px (standard) / 8px (large)
```

---

## 3. SHARED COMPONENTS

### 3.1 KPI Card

Used on admin and tenant dashboards.

```
┌────────────────────────────┐
│  [icon 24px]               │
│                            │
│  42                        │  ← 28px, 700, #2D3436
│  Total Projects            │  ← 12px, 400, #636E72
│                            │
│  ▲ 3 this week             │  ← 12px, 500, #2DCE89 (positive) or #FF6358 (negative)
└────────────────────────────┘
```

| Property | Value |
|---|---|
| Element | `<article>` |
| Background | `#FFFFFF` |
| Radius | 6px |
| Padding | 20px |
| Shadow | Elevation 1 |
| Height | 120px |
| Hover | Elevation 2 + `transform: scale(1.02)`, transition 200ms |
| Icon color | `#0066FF` (primary KPIs), `#636E72` (neutral KPIs) |
| Number | 28px, 700 weight, `#2D3436` |
| Label | 12px, 400 weight, `#636E72`, margin-top 4px |
| Trend indicator | 12px, 500 weight; prefix `▲` success green or `▼` danger red |

### 3.2 Status Badge

Used throughout for scan status, user status, health scores.

| Variant | Text | Background | Text Color | Border |
|---|---|---|---|---|
| `queued` | QUEUED | `#E3F2FD` | `#1976D2` | `1px solid #2196F3` |
| `running` | RUNNING | `#FFF5E5` | `#E69A1C` | `1px solid #FFAB2F` |
| `completed` | COMPLETED | `#E8F8F3` | `#23B373` | `1px solid #2DCE89` |
| `failed` | FAILED | `#FFE8E5` | `#E54D45` | `1px solid #FF6358` |
| `cancelled` | CANCELLED | `#F8F9FA` | `#636E72` | `1px solid #DFE3E6` |
| `active` | ACTIVE | `#E8F8F3` | `#23B373` | `1px solid #2DCE89` |
| `inactive` | INACTIVE | `#F8F9FA` | `#636E72` | `1px solid #DFE3E6` |
| `pending` | PENDING | `#FFF5E5` | `#E69A1C` | `1px solid #FFAB2F` |

Badge anatomy: height 22px, padding 2px 8px, font 11px 600 weight, border-radius 4px, letter-spacing 0.4px. Use `<span>` element, never `<div>`.

### 3.3 Health Score Badge

Large prominent badge for NAS overall health. Used in scan reports and project lists.

| Score | Label | Background | Text Color | Border | Icon |
|---|---|---|---|---|---|
| `CRITICAL` | CRITICAL | `#FFE8E5` | `#E54D45` | `2px solid #FF6358` | `circle-alert` 16px |
| `WARNING` | WARNING | `#FFF5E5` | `#E69A1C` | `2px solid #FFAB2F` | `triangle-alert` 16px |
| `NORMAL` | NORMAL | `#E8F8F3` | `#23B373` | `2px solid #2DCE89` | `circle-check` 16px |

Large variant (report header): height 36px, padding 6px 16px, font 13px 600 weight, icon 18px.
Small variant (table/list): height 24px, padding 3px 10px, font 11px 600 weight, icon 14px.

### 3.4 Severity Finding Badge (Report)

Used on individual scan findings within reports.

| Severity | Background | Text Color | Left Border Color |
|---|---|---|---|
| `critical` | `#FFE8E5` | `#E54D45` | `#FF6358` (4px solid) |
| `warning` | `#FFF5E5` | `#E69A1C` | `#FFAB2F` (4px solid) |
| `info` | `#E3F2FD` | `#1976D2` | `#2196F3` (4px solid) |
| `ok` | `#E8F8F3` | `#23B373` | `#2DCE89` (4px solid) |

### 3.5 Toast Notifications

Position: fixed, top-center, z-index 500, margin-top 20px.

```
┌─────────────────────────────────────────┐
│  [✓ icon]  Profile saved successfully.  │  [×]
└─────────────────────────────────────────┘
```

| Variant | Background | Border | Icon | Auto-dismiss |
|---|---|---|---|---|
| Success | `#E8F8F3` | `1px solid #2DCE89` | `check-circle` green | 4 seconds |
| Error | `#FFE8E5` | `1px solid #FF6358` | `x-circle` red | Manual only |
| Warning | `#FFF5E5` | `1px solid #FFAB2F` | `triangle-alert` amber | 6 seconds |
| Info | `#E3F2FD` | `1px solid #2196F3` | `info` blue | 4 seconds |

Toast element: `<div role="status" aria-live="polite">`. Width: min 300px, max 480px. Padding: 14px 16px. Radius 6px. Shadow Elevation 4. Text: 14px 400 weight. Dismiss button: `<button type="button" aria-label="Dismiss">` × icon 14px.

### 3.6 Data Table (Standard)

All list/table views share this structure.

```
┌─────────────────────────────────────────────────────────┐
│  Section heading + action button (right-aligned)         │
├────────────┬──────────────┬──────────┬─────────┬────────┤
│ COL A  ↕  │ COL B    ↕  │ COL C ↕ │ STATUS  │ ACTIONS│  ← thead, #F8F9FA bg
├────────────┼──────────────┼──────────┼─────────┼────────┤
│ value      │ value        │ value    │ [badge] │ ✎ 🗑   │  ← row, 48px height
│ value      │ value        │ value    │ [badge] │ ✎ 🗑   │
└────────────┴──────────────┴──────────┴─────────┴────────┘
│ Showing 1–20 of 47         [‹ 1 2 3 ›]                  │
```

| Property | Value |
|---|---|
| Container | `<div>` white bg, 6px radius, Elevation 1, 20px padding |
| Table element | `<table>` 100% width, `border-collapse: collapse` |
| `<th>` | 12px, 600 weight, ALL-CAPS, `#636E72`, `#F8F9FA` bg, 2px bottom border `#DFE3E6`, padding 12px 16px, `scope="col"` |
| Sort button | `<button type="button">` inside `<th>`, inline-flex, icon 12px; active direction: `#0066FF`, inactive: `#95A5A6` |
| `<td>` | 14px, 400 weight, `#2D3436`, padding 12px 16px, border-bottom `1px solid #EEF0F3` |
| Row height | 48px |
| Row hover | background `#FAFBFC`, transition 150ms |
| Action buttons | Appear on row hover, `<button type="button">`, icon 16px, `#636E72` → `#0066FF`, opacity 0 → 1 |

**Pagination:** `<nav aria-label="Pagination">` below table. Current page: `#0066FF` bg + white text. Others: border `#DFE3E6`, text `#636E72`. Button height 36px, min-width 36px.

### 3.7 Confirmation Modal

Used for all destructive or irreversible actions.

```
┌─────────────────────────────────────┐
│  [⚠ icon]  Delete Project?          │  ← h3, 20px, #2D3436
│                                     │
│  This will permanently delete       │  ← 14px, #636E72
│  "Home NAS Project" and all its     │
│  files and reports. This action     │
│  cannot be undone.                  │
│                                     │
│       [Cancel]    [Delete ▸]        │  ← Cancel=outline, Delete=danger solid
└─────────────────────────────────────┘
```

| Property | Value |
|---|---|
| Max width | 480px, horizontally centered |
| Padding | 24px |
| Radius | 8px |
| Shadow | Elevation 3 |
| Overlay | `rgba(0,0,0,0.5)`, z-index 300 |
| Modal z-index | 400 |
| Icon | 24px, `#FFAB2F` (warning) or `#FF6358` (critical delete) |
| Heading | `<h3>` 20px, 600 weight, `#2D3436`, margin-bottom 12px |
| Body text | 14px, 400 weight, `#636E72`, line-height 1.6 |
| Buttons | Cancel: outline, left. Destructive: danger solid, right. 8px gap. |
| Dismiss | Clicking overlay does NOT dismiss — user must click Cancel or Confirm |

### 3.8 Progress Bar (File Upload / Scan)

```
Uploading debug file...
[███████████░░░░░░░░░░░░░░░]  43%
```

| Property | Value |
|---|---|
| Container height | 8px |
| Track background | `#E0E6ED` |
| Fill color — upload | `#0066FF` |
| Fill color — success | `#2DCE89` |
| Fill color — error | `#FF6358` |
| Fill color — warning/queue | `#FFAB2F` |
| Radius | 4px |
| Transition | `width 300ms ease` |
| Label above | 14px `#636E72`, margin-bottom 8px |
| Percentage | 13px `#0066FF`, right-aligned |

### 3.9 Skeleton Loading

Show shimmer placeholders while content loads. Never use a spinner.

```css
/* Shimmer animation */
@keyframes shimmer {
  0%   { background-position: -200% 0; }
  100% { background-position:  200% 0; }
}
.skeleton {
  background: linear-gradient(90deg, #E0E6ED 25%, #F8F9FA 50%, #E0E6ED 75%);
  background-size: 200% 100%;
  animation: shimmer 1.5s infinite linear;
  border-radius: 4px;
}
```

| Component | Skeleton dimensions |
|---|---|
| KPI card | Full card 120px height |
| Table row | 48px height × 100% width |
| Project card | 380px height |
| Report finding card | 120px height |
| Chart area | Full container height (300px) |

---

## 4. AUTHENTICATION PAGES

### 4.1 Login Page (`/login`)

Full-page centered layout. No sidebar. No topbar.

```
┌─────────────────────────────────────────────────────────┐
│                                                         │
│              [Logo 48px]                                │
│           AI DebugScan                                  │
│        Sign in to your account                          │
│                                                         │
│  ┌───────────────────────────────────────────────┐      │
│  │  Email address                                │      │
│  │  [────────────────────────────────────────]   │      │
│  │                                               │      │
│  │  Password                                     │      │
│  │  [────────────────────────────────────────]   │      │
│  │                                    [👁 show]  │      │
│  │                                               │      │
│  │  [ Sign In ▸ ]  (full width, primary solid)   │      │
│  └───────────────────────────────────────────────┘      │
│                                                         │
│          Forgot your password? Contact admin            │
│                                                         │
└─────────────────────────────────────────────────────────┘
```

| Property | Value |
|---|---|
| Page background | `#F8F9FA` |
| Card width | 400px max, centered vertically and horizontally |
| Card background | `#FFFFFF` |
| Card padding | 40px |
| Card radius | 8px |
| Card shadow | Elevation 3 |
| Logo | 48px, centered, margin-bottom 12px |
| App name | 22px, 700 weight, `#2D3436`, centered, margin-bottom 4px |
| Subtitle | 14px, 400 weight, `#636E72`, centered, margin-bottom 32px |
| Field labels | 12px, 600 weight, ALL-CAPS, `#636E72`, letter-spacing 0.5px |
| Input height | 42px |
| Password toggle | Eye icon button inside the input, right-aligned |
| Sign In button | `<button type="submit">`, full width, 42px, primary solid |
| Button loading state | Spinner inside button, text "Signing in…", disabled |
| Error state | Alert banner (not toast) below email field: `#FFE8E5` bg, `#FF6358` border, `#E54D45` text, lock icon |
| Footer text | 13px, `#95A5A6`, centered, margin-top 24px |

**Error messages:**
- Invalid credentials: "Invalid email or password. Please try again."
- Account locked: "Account locked due to too many failed attempts. Try again in [N] minutes."
- Account inactive: "Your account has been deactivated. Contact your administrator."

### 4.2 Change Password Page (`/auth/change-password`)

Authenticated page, uses full layout shell (sidebar + topbar).

Card (max-width 480px, centered in content area):

```
Change Password

Current Password        [────────────────]
New Password            [────────────────]
  Strength: [██████░░░░] Medium
Confirm New Password    [────────────────]

[Cancel]   [Update Password ▸]
```

Password strength bar: height 4px, fills as user types.
- 1 criterion met: 33% width, `#FF6358` (weak)
- 2 criteria: 66% width, `#FFAB2F` (medium)
- 3+ criteria: 100% width, `#2DCE89` (strong)

---

## 5. ADMIN MODULE PAGES

### 5.1 Admin Dashboard (`/admin`)

**Page title:** Dashboard

**KPI Cards Row** — 5 cards, equal width grid, 24px gap:

| Card | Icon | Metric | Trend |
|---|---|---|---|
| Total Tenants | `users` | Count of active tenants | +N this month |
| Total Scans | `scan-line` | All-time scan count | N today |
| Tokens Issued | `ticket` | Sum tokens distributed | vs. used |
| Queue Status | `list-ordered` | N queued / N running | — |
| Storage Used | `hard-drive` | Total GB uploaded | — |

**Charts Row** — 2 charts, equal width, 300px height each, 24px gap:

**Chart 1: Scan Activity (Line Chart)**
- Title: "Scan Activity — Last 30 Days"
- X axis: dates, 12px `#636E72`
- Y axis: scan count, 12px `#636E72`
- Line 1: Level 1 scans — `#0066FF` 2px solid
- Line 2: Level 2 scans — `#2196F3` 2px dashed
- Grid lines: `#EEF0F3` 1px dashed
- Legend: top-right, 12px, 16px margin

**Chart 2: Token Consumption by Tenant (Bar Chart)**
- Title: "Token Consumption — Last 30 Days"
- X axis: tenant names (truncated at 12 chars)
- Y axis: tokens used
- Bar color: `#0066FF`, hover `#0052CC`
- Show top 8 tenants; "Others" grouped if > 8

**Tenant List Table:**

Columns: `Name` | `Email` | `Status` | `Projects` | `Tokens Avail.` | `Tokens Used` | `Scans L1/L2` | `Last Login` | `Actions`

Action column: [edit pencil icon] [token-add icon] [deactivate toggle icon] — appear on row hover.

### 5.2 Tenant List Page (`/admin/tenants`)

**Page title:** Tenants

**Topbar actions:** `[+ Create Tenant]` — primary solid button, right-aligned.

**Filter/Search bar** (above table):
```
[🔍 Search by name or email...    ]   [Status: All ▼]
```
Search input: 320px width. Status dropdown: 160px. Both 38px height.

**Table columns:** `Name` | `Email` | `Role` | `Status` | `Tokens Avail.` | `Scans` | `Last Login` | `Actions`

Actions per row: Edit `[pencil]`, Adjust Tokens `[+]`, Reset Password `[key]`, Deactivate `[toggle]`, Delete `[trash]` — all icon buttons, appear on hover.

Delete action: opens confirmation modal naming the tenant. Soft-delete only (sets status = 'inactive').

### 5.3 Create / Edit Tenant Form (`/admin/tenants/create`, `/admin/tenants/{id}/edit`)

Card layout, max-width 640px, centered:

```
Create Tenant
─────────────────────────────────────────

ACCOUNT DETAILS

Display Name *      [────────────────────────]
Email Address *     [────────────────────────]
Password *          [────────────────────────]
  (Edit mode: leave blank to keep current)
Status              (●) Active  ( ) Inactive

SCAN TOKENS

Initial Token Allocation *   [──────]
  (Tokens immediately available to this tenant)

─────────────────────────────────────────
AI SCAN LIMITS (per scan)

(These values default from system settings.
 Override here for this tenant only.)

[ ] Override system defaults
    Level 1 Max Input Tokens   [──────]  default: 8000
    Level 1 Max Output Tokens  [──────]  default: 4000
    Level 2 Max Input Tokens   [──────]  default: 32000
    Level 2 Max Output Tokens  [──────]  default: 8000

─────────────────────────────────────────

[Cancel]      [Create Tenant ▸] / [Save Changes ▸]
```

Field specifications:
- Labels: 12px, 600 weight, ALL-CAPS, `#636E72`, letter-spacing 0.5px
- Required marker: `<span aria-hidden="true">*</span>` in `#FF6358`
- Checkbox for "Override system defaults": reveals the token limit fields on check (Alpine.js `x-show`)
- Section titles (ACCOUNT DETAILS, SCAN TOKENS): 11px, 600 weight, ALL-CAPS, `#95A5A6`, letter-spacing 0.8px, margin-bottom 16px; separated by `1px solid #EEF0F3` hr

**Tenant Edit mode — additional section:**

```
USAGE SUMMARY
─────────────────────────────────────────
Tokens Available    42       Tokens Used        18
Total Scans         7        Last Login    Mar 12, 2026
Level 1 Scans       5        Account Created   Jan 4, 2026
Level 2 Scans       2
```

Usage summary uses `<dl>` / `<dt>` / `<dd>` pairs. Value: 20px, 700 weight, `#2D3436`. Label: 12px, `#636E72`.

**Token Adjustment Widget** (inline, on edit page):

```
Adjust Tokens
─────────────────────────────────────────
Current Balance: 42 tokens
[ Add (●) ]  [ Subtract ( ) ]
Amount  [──────]
Note    [────────────────────────────────]
                              [Apply ▸]
```

### 5.4 System Settings Page (`/admin/settings`)

Card sections, max-width 720px:

**Section 1: AI Configuration**

```
AI CONFIGURATION
─────────────────────────────────────────
Groq API Key          [●●●●●●●●●●●●●]  [👁 reveal]

LEVEL 1 SCAN (1 token)
AI Model              [llama-3.3-70b-versatile  ▼]
Max Input Tokens      [8000    ]
Max Output Tokens     [4000    ]

LEVEL 2 SCAN (2 tokens)
AI Model              [llama-3.3-70b-versatile  ▼]
Max Input Tokens      [32000   ]
Max Output Tokens     [8000    ]
```

Model dropdown: searchable select (Combobox), populated from system. Width 320px.

**Section 2: Queue & Operations**

```
QUEUE & OPERATIONS
─────────────────────────────────────────
Max Concurrent Scans  [2  ]    (Scans running simultaneously)
```

**Section 3: Retention**

```
RETENTION
─────────────────────────────────────────
File Retention Period  [90  ]  days
  Debug files and reports will be permanently deleted after
  this many days from upload date.

Max Upload Size        [200 ]  MB
```

**Section 4: System Health Summary** (read-only, cards):

```
SYSTEM STATUS
─────────────
[✓ Database Connected]  [✓ Queue Worker Active]  [✓ Storage Writable]
Last worker run: 2 minutes ago    Next retention cleanup: Tonight 2:00 AM
```

Buttons at bottom of page: `[Cancel]` `[Save Settings ▸]`

### 5.5 Extraction Error Log (`/admin/errors`)

**Page title:** Error Log

**Filter bar:**
```
[🔍 Search by filename or message...]   [Error Type: All ▼]   [Status: Unresolved ▼]
```

**Table columns:** `Date` | `Tenant` | `Filename` | `Error Type` | `Error Message` | `Severity` | `Status` | `Actions`

Error type badge: small badge using standard component.
- `corrupt_zip` — danger
- `missing_file` — warning
- `parse_error` — warning
- `timeout` — danger

Severity badge: `error` = danger, `warning` = warning.

Actions: [Download debug file icon] [Mark Resolved icon] — hover-reveal icon buttons.

---

## 6. TENANT MODULE PAGES

### 6.1 Tenant Dashboard (`/dashboard`)

**Page title:** Dashboard

**KPI Cards Row** — 4 cards:

| Card | Icon | Metric |
|---|---|---|
| Scan Tokens | `ticket` | Available tokens (large number in `#0066FF`) |
| Projects | `folder` | Total project count |
| Scans This Month | `scan-line` | L1 count + L2 count below |
| Last Scan | `clock` | Date of last completed scan |

Tokens KPI card: if tokens = 0, number color changes to `#FF6358` and card border becomes `1px solid #FF6358`.

**Recent Scans Section** (below KPI cards):

Heading: "Recent Scans" (H2 section heading), + "View all" link right-aligned.

List view (not table): last 5 completed scans.

Each row:
```
[health badge] Project Name — NAS Model             [L1/L2 badge]  Mar 15, 2026  [View Report →]
```

Row: height 56px, border-bottom `1px solid #EEF0F3`, hover `#FAFBFC`.
Project name: 14px, 600 weight, `#2D3436`.
NAS model: 13px, `#636E72`.
Date: 12px, `#95A5A6`, `<time>` element.
"View Report →": link button, 13px, `#0066FF`.

Empty state if no scans: icon `scan-line` 48px `#E0E6ED`, heading "No scans yet", subtext "Upload a debug file and run your first scan.", CTA "Go to Projects" → `/projects`.

### 6.2 Projects List (`/projects`)

**Page title:** Projects

**Topbar actions:** `[+ New Project]` — primary solid button.

**Table columns:** `Project Name` | `Device Model` | `Serial Number` | `Bays` | `Last Scan` | `Health` | `Actions`

- Project Name: link `<a>`, `#0066FF`, 14px 600 weight
- Device Model: 14px, `#2D3436`
- Serial Number: 13px, `#636E72`, monospace font
- Bays: 13px, `#2D3436`
- Last Scan: `<time>` 13px, `#95A5A6`
- Health: health score badge (small variant)
- Actions: [View `eye`] [Scan L1 `zap`] [Edit `pencil`] [Delete `trash`] — hover-reveal

**Expandable row "More Info"** (click arrow on left of row):

```
  ┌───────────────────────────────────────────────────────────┐
  │  RAM: 8 GB   |  CPU: Intel Atom C3538   |  DSM: 7.3.2    │
  │  Drive Bays: 5 of 8 filled              |  Uptime: 45 d  │
  └───────────────────────────────────────────────────────────┘
```

Expand animation: 200ms ease, chevron icon rotates 90°.
Sub-row background: `#FAFBFC`. Content: 13px, `#636E72`. Labels: 12px 600 weight ALL-CAPS `#95A5A6`.

### 6.3 Create / Edit Project Form (`/projects/create`, `/project/{id}/edit`)

Modal (3–5 fields, per ActiveDesign.md drawer/modal rule — use modal for ≤ 5 fields):

```
┌──────────────────────────────────────┐
│  Create New Project                  │
│  ──────────────────────────────────  │
│  Project Name *                      │
│  [──────────────────────────────────]│
│                                      │
│  Notes (optional)                    │
│  [──────────────────────────────────]│
│  [                                  ]│
│                                      │
│  NAS Serial Number (optional)        │
│  [──────────────────────────────────]│
│  Leave blank — auto-filled from      │
│  first debug file upload.            │
│                                      │
│  [Cancel]          [Create Project ▸]│
└──────────────────────────────────────┘
```

### 6.4 Project Detail Page (`/project/{id}`)

**Page title:** [Project Name] *(breadcrumb: Projects › Project Name)*

**Layout:** Single column, full content width. Sections separated by 32px margin.

---

**Section 1: NAS Identity Card**

```
┌────────────────────────────────────────────────────────────┐
│  [server icon 32px]  DS1821+                               │
│                      DSM 7.3.2 (build 86009)               │
│                                                            │
│  Serial: 2320SKRBDTQZ0   RAM: 8 GB    CPU: Ryzen V1500B   │
│  Drive Bays: 5 of 8      Uptime: 45 days  (at last scan)  │
│                                                            │
│  [Edit Project ▸]  (outline button, right-aligned)         │
└────────────────────────────────────────────────────────────┘
```

Card: white bg, 6px radius, 20px padding, Elevation 1.
Model name: H2, 22px, 700 weight, `#2D3436`.
DSM version: 14px, `#636E72`.
Metadata row: `<dl>` pairs, 13px, label `#95A5A6` 12px, value `#2D3436` 13px 600 weight. Pipe `|` separator between items, `#DFE3E6`.

If no NAS data extracted yet (project just created):

Empty state in card: "No NAS data yet — upload a debug file to populate device information." 14px `#636E72`, icon `server` 32px `#E0E6ED`.

---

**Section 2: Debug Files**

Heading (H2): "Debug Files"
Topbar-right: `[Upload Debug File ▸]` — primary solid button.

Table: 5 columns: `Filename` | `Upload Date` | `File Size` | `DSM` | `Status` | `Actions`

- Filename: 14px 500 weight `#2D3436`
- Upload Date: `<time>` 13px `#95A5A6`
- File Size: 13px `#636E72` (formatted: "45.2 MB")
- DSM: 13px `#636E72`
- Status badge: `pending` / `completed` / `failed` (standard badge)
- Actions: [Download] [Delete] — icon buttons, hover-reveal

If extraction failed, row has left border `3px solid #FF6358` and status badge shows `failed` danger style. Hovering the badge shows a tooltip with the error message.

---

**Section 3: Scans**

Heading (H2): "Scans"
Topbar-right: `[Run Level 1 Scan]` (outline, secondary) + `[Run Level 2 Scan]` (primary solid) — 8px gap.

Table: 6 columns: `Date` | `Level` | `Files` | `Status` | `Health` | `Duration` | `Actions`

- Date: `<time>` 13px `#95A5A6`
- Level: `L1` (info badge) or `L2` (primary badge)
- Files: 13px `#636E72` (e.g., "1 file" or "3 files")
- Status: standard status badge
- Health: health score badge (small variant) — only shown when status = completed
- Duration: 13px `#636E72` (e.g., "1m 42s")
- Actions: [View Report `eye`] — only shown when status = completed; [Cancel `x`] — only when queued

Running scan row: orange left border `3px solid #FFAB2F`, pulsing dot animation in status cell.

---

**Scan Initiation — Level 1:**

Click "Run Level 1 Scan" → slide-in drawer from right (3–6 fields, per ActiveDesign.md):

```
┌──────────────────────────────────────────────┐
│  Run Level 1 Scan                     [×]    │
│  ──────────────────────────────────────────  │
│  Select Debug File                           │
│  ( ) debug_2320SKRBDTQZ0.dat  Mar 15, 2026  │
│  (●) debug_2550RXR77XJT0.dat  Mar 22, 2026  │
│  ( ) debug_2550RXRGBZQ0T.dat  Apr 1, 2026   │
│                                              │
│  Cost: 1 scan token                          │
│  Your balance: 15 tokens                     │
│                                              │
│  [Cancel]       [Start Level 1 Scan ▸]       │
└──────────────────────────────────────────────┘
```

Drawer width: 400px. Overlay: `rgba(0,0,0,0.3)`. Slide-in from right 300ms ease.
Radio button list for file selection. Selected file: row bg `#E3F2FD`, border `1px solid #2196F3`.
Cost line: 14px `#636E72`. Balance: 14px `#2DCE89` (positive) or `#FF6358` (0 tokens).
If insufficient tokens: button disabled, tooltip "Insufficient tokens — contact your administrator."

---

**Scan Initiation — Level 2:**

```
┌──────────────────────────────────────────────┐
│  Run Level 2 Deep Scan                [×]    │
│  ──────────────────────────────────────────  │
│  Select Debug Files (2 or more for           │
│  trend analysis)                             │
│                                              │
│  [✓] debug_2320SKRBDTQZ0.dat  Mar 15, 2026  │
│  [ ] debug_2550RXR77XJT0.dat  Mar 22, 2026  │
│  [✓] debug_2550RXRGBZQ0T.dat  Apr 1, 2026   │
│                                              │
│  Selected: 2 files (ordered by date)         │
│  Cost: 2 scan tokens                         │
│  Your balance: 15 tokens                     │
│                                              │
│  [Cancel]       [Start Level 2 Scan ▸]       │
└──────────────────────────────────────────────┘
```

Checkbox list (multiple). Min 1 file required (button disabled otherwise).
Info text below selection: "Files will be analyzed in chronological order. Level 2 scans show problem progression over time." 13px `#636E72`.

### 6.5 File Upload Page (`/project/{id}/upload`)

Dedicated page (not modal — file upload is a significant action).

**Page title:** Upload Debug File *(breadcrumb: Projects › [Project Name] › Upload)*

```
┌──────────────────────────────────────────────────────────────┐
│  Upload Debug File                                            │
│  ──────────────────────────────────────────────────────────  │
│                                                              │
│  ┌──────────────────────────────────────────────────────┐   │
│  │                                                      │   │
│  │            [cloud-upload icon 48px]                  │   │
│  │                                                      │   │
│  │      Drag and drop your debug.dat file here          │   │
│  │              or click to browse                      │   │
│  │                                                      │   │
│  │      Accepted: .dat files up to 200 MB               │   │
│  │                                                      │   │
│  └──────────────────────────────────────────────────────┘   │
│                                                              │
│  ← Back to Project                                           │
└──────────────────────────────────────────────────────────────┘
```

Drop zone: `1px dashed #DFE3E6` border, 6px radius, `#FAFBFC` bg, 200px height, 100% width.
Drop zone hover/drag-over: `2px dashed #0066FF` border, `#E3F2FD` bg.
Icon: 48px, `#B0BEC5`.
Primary text: 16px, 500 weight, `#636E72`.
Secondary text: 13px, `#95A5A6`.

**During upload (same page, replaces drop zone):**

```
┌──────────────────────────────────────────────────────────────┐
│                                                              │
│  Uploading debug_2320SKRBDTQZ0.dat                           │
│  43.0 MB                                                     │
│                                                              │
│  [████████████████░░░░░░░░░░░░░░░░░░░░]  47%                 │
│                                                              │
│  Extracting and analyzing hardware data...                   │
│                                                              │
└──────────────────────────────────────────────────────────────┘
```

**After extraction completes — serial number check modal** appears automatically (see Section 9.2).

**On success — extracted hardware summary card:**

```
┌────────────────────────────────────────────────────────────┐
│  ✓  File uploaded and processed successfully               │
│  ──────────────────────────────────────────────────────── │
│  Model:    DS1821+                  RAM:     8 GB          │
│  Serial:   2320SKRBDTQZ0           CPU:     Ryzen V1500B  │
│  DSM:      7.3.2 (build 86009)     Drives:  5 installed   │
│  RAID:     RAID 5 — 5 members      Volume:  29.1 TB total │
│  Uptime:   45 days                 Health:  [NORMAL ✓]    │
│                                                            │
│  [Run Level 1 Scan ▸]     [Back to Project]               │
└────────────────────────────────────────────────────────────┘
```

Success card: white bg, `2px solid #2DCE89` left border, 6px radius, 20px padding.

---

## 7. SCAN PROGRESS PAGE

**Route:** `/scan/{id}/progress`
**Page title:** Scan in Progress
Refreshes via SSE (Server-Sent Events). No page reload needed.

```
┌──────────────────────────────────────────────────────────────┐
│                                                              │
│  Level 1 Scan — DS1821+ (SN: 2320SKRBDTQZ0)                 │
│  Started: 14:32:07                                           │
│                                                              │
│  ┌──────────────────────────────────────────────────────┐   │
│  │                                                      │   │
│  │  [████████████████████░░░░░░░░░░░░░░]  60%           │   │
│  │                                                      │   │
│  │  ✓  Validating file                  0.3s            │   │
│  │  ✓  Extracting log data             1.2s            │   │
│  │  ◉  Querying AI model...            (running)        │   │  ← animated pulse
│  │  ○  Processing results                               │   │
│  │                                                      │   │
│  └──────────────────────────────────────────────────────┘   │
│                                                              │
│  AI Model: llama-3.3-70b-versatile                           │
│  Elapsed: 00:00:18                                           │
│                                                              │
│  [Cancel Scan]  (outline danger button, small)               │
│                                                              │
└──────────────────────────────────────────────────────────────┘
```

| Property | Value |
|---|---|
| Page background | `#F8F9FA` |
| Progress card | white, 6px radius, 24px padding, Elevation 1, max-width 600px, centered |
| NAS headline | 18px, 600 weight, `#2D3436` |
| Sub-info | 13px, `#636E72` |
| Stage list | `<ol>` — stages in order |
| Completed stage | `✓` icon green `#2DCE89`, stage text `#2D3436`, time right-aligned `#95A5A6` 12px |
| Active stage | `◉` animated pulse icon `#FFAB2F`, text `#2D3436` 500 weight |
| Pending stage | `○` icon `#DFE3E6`, text `#95A5A6` |
| Elapsed counter | `<time>` 13px `#636E72`, updates every second |
| Cancel button | `<button type="button">` outline danger, 32px, appears only when status = queued or running |

**Queued state (waiting for slot):**

```
  [clock icon 32px]
  Your scan is queued
  Position: 1 of 2 waiting

  The system is processing another scan.
  Your scan will start automatically.

  [Cancel Scan]
```

**On completion:** SSE pushes `status: completed`. Page automatically redirects to `/scan/{id}/report` after 1.5 seconds with a success toast.

**On failure:** SSE pushes `status: failed`. Shows error card:

```
  [x-circle icon 32px red]
  Scan failed

  The AI service returned an error. No tokens were charged.
  Error: [error message]

  [Try Again ▸]   [Back to Project]
```

---

## 8. REPORT FORMATS

Reports are the primary deliverable of the application. Two formats exist: the **Hardware Extraction Summary** (free, immediate) and the **AI Scan Report** (Level 1 or Level 2).

---

### 8.1 Hardware Extraction Summary

Shown immediately after upload. Also accessible from the project detail page (click "View Hardware Info" on a debug file row).

**Route:** `/file/{id}/hardware`
**Page title:** Hardware Summary — [NAS Model]

```
┌──────────────────────────────────────────────────────────────────┐
│  HARDWARE EXTRACTION SUMMARY                                     │
│  DS1821+ · Serial: 2320SKRBDTQZ0 · Extracted: Apr 7, 2026       │
│                                                          [Export]│
└──────────────────────────────────────────────────────────────────┘
```

**Card 1: Device Identity**

```
┌──────────────────────────────────────────────────────────────────┐
│  [server icon]  DEVICE IDENTITY                                  │
│  ────────────────────────────────────────────────────────────    │
│  Model           DS1821+          Drive Bays   8 (5 installed)  │
│  Serial Number   2320SKRBDTQZ0    RAM          8 GB             │
│  DSM Version     7.3.2            CPU          Ryzen V1500B     │
│  Build Number    86009            CPU Cores    4                │
│  Build Date      2025/10/03       Uptime       45.3 days        │
└──────────────────────────────────────────────────────────────────┘
```

Layout: `<dl>` grid, 2-column, `dt` 12px ALL-CAPS `#636E72`, `dd` 14px 500 weight `#2D3436`. Card: white bg, 6px radius, 20px padding, Elevation 1.

**Card 2: Drive Inventory**

Header row: `BAY` | `DEVICE` | `MODEL` | `SERIAL` | `CAPACITY` | `TEMP` | `STATUS` | `SMART`

```
┌───┬────────┬────────────────┬──────────────────┬────────┬─────┬──────────┬────────┐
│ 1 │ sata1  │ HAT5300-4T     │ 2420U6RA0A06QFW  │ 3.6 TB │ 22° │ [NORMAL] │ [PASS] │
│ 2 │ sata2  │ HAT5300-4T     │ 2420U6RA0A07QFW  │ 3.6 TB │ 23° │ [NORMAL] │ [PASS] │
│ 3 │ sata3  │ HAT5300-4T     │ 2420U6RA0A08QFW  │ 3.6 TB │ 22° │ [NORMAL] │ [PASS] │
│ 4 │ sata4  │ HDWG480        │ 4240A21NFR0H      │ 7.3 TB │ 29° │[WARNING] │ [PASS] │
│ 5 │ sata5  │ HAT5300-4T     │ 2420U6RA0A09QFW  │ 3.6 TB │ 21° │ [NORMAL] │ [PASS] │
│ — │  —     │  —             │  —               │  —     │  —  │ [EMPTY]  │   —    │  ← bays 6-8
└───┴────────┴────────────────┴──────────────────┴────────┴─────┴──────────┴────────┘
```

- Empty bays: row background `#F8F9FA`, text `#B0BEC5` dash
- STATUS badge colors: NORMAL = success, WARNING = warning, CRITICAL = danger, EMPTY = neutral grey
- SMART badge: PASS = success, FAIL = danger, UNKNOWN = neutral
- Temperature: if > 50°C, show in `#FFAB2F`; if > 60°C, show in `#FF6358`
- Firmware column (optional, show if firmware_status != "-"): WARNING badge if update available

**Card 3: RAID Configuration**

```
┌──────────────────────────────────────────────────────────────────┐
│  [shield icon]  RAID CONFIGURATION                               │
│  ────────────────────────────────────────────────────────────    │
│                                                                  │
│  md2  RAID 5  ●●●●●○○○   5/8 drives   [NORMAL]   29.1 TB usable│
│  md1  RAID 1  ●●●●●○○○   5/8 drives   [NORMAL]   2 GB swap      │
│  md0  RAID 1  ●●●●●○○○   5/8 drives   [NORMAL]   8 GB system    │
│                                                                  │
│  Superblock:  1.2 (clean)   Last Rebuild:  None                  │
└──────────────────────────────────────────────────────────────────┘
```

RAID member visualization: filled circles `●` = active member, empty circles `○` = unused bay slot. Green fill for active, grey for empty. Use `<span aria-label="Active member">` for accessibility.
Array health badge: NORMAL/WARNING/CRITICAL using standard health badge spec.

**Card 4: Volume & Storage**

```
┌──────────────────────────────────────────────────────────────────┐
│  [database icon]  STORAGE VOLUMES                                │
│  ────────────────────────────────────────────────────────────    │
│  Volume 1   btrfs   [NORMAL]                                     │
│  [████████████████████░░░░░░░░░░░░░░░░░░░]  33.6% used          │
│  9.85 TB used  of  29.3 TB total  ·  19.4 TB free               │
│                                                                  │
│  Scrub Status: Idle   Last Scrub: Mar 10, 2026   Errors: None    │
└──────────────────────────────────────────────────────────────────┘
```

Usage bar: standard progress bar component, color `#0066FF` up to 80%, `#FFAB2F` 80–90%, `#FF6358` > 90%.

**Card 5: System Snapshot**

```
┌──────────────────────────────────────────────────────────────────┐
│  [activity icon]  SYSTEM SNAPSHOT (at capture time)              │
│  ────────────────────────────────────────────────────────────    │
│  Load Average   1.2 / 1.5 / 1.3          IO Wait    5.2%        │
│  Memory Usage   14% used                  Swap       0% used     │
│  CPU Cores      4                         Zombies    0           │
│  Self-check     Check Success             Imp. Shutdown  None    │
└──────────────────────────────────────────────────────────────────┘
```

**Pre-Scan Health Score Card**

```
┌──────────────────────────────────────────────────────────────────┐
│  Pre-Analysis Health Assessment                                  │
│  ────────────────────────────────────────────────────────────    │
│                                                                  │
│  Hardware:    [NORMAL ✓]    RAID:       [NORMAL ✓]              │
│  Network:     [NORMAL ✓]    Performance:[WARNING ⚠]              │
│  System:      [NORMAL ✓]                                         │
│                                                                  │
│  Overall: [WARNING ⚠]                                            │
│                                                                  │
│  1 pre-scan warning detected. Run a Level 1 scan for             │
│  AI-powered root cause analysis and fix recommendations.         │
│                                                                  │
│  [Run Level 1 Scan — 1 Token ▸]                                  │
└──────────────────────────────────────────────────────────────────┘
```

CTA only shown if tenant has sufficient tokens. If 0 tokens: replace button with "Contact your administrator to purchase scan tokens." in `#FF6358`.

---

### 8.2 Level 1 AI Scan Report

**Route:** `/scan/{id}/report`
**Page title:** Scan Report — [NAS Model]

---

**Report Header Bar**

Full-width card, white bg, 20px padding, Elevation 1:

```
┌──────────────────────────────────────────────────────────────────┐
│  [server icon]  DS1821+  ·  SN: 2320SKRBDTQZ0                    │
│                 DSM 7.3.2 (build 86009)                          │
│                                                       [Export ▼] │
│  ─────────────────────────────────────────────────────────────── │
│  Scan Level:  [L1 badge]     Date:  Apr 7, 2026 14:32            │
│  AI Model:    llama-3.3-70b-versatile                            │
│  Prompt v:    1.0                                                │
│                                                                  │
│  ┌───────────────────────────────────────────────────────────┐  │
│  │  Queue Wait   Extraction   AI Analysis   Processing  Total│  │
│  │     4s           1.2s         18.3s         0.4s    24.1s│  │
│  └───────────────────────────────────────────────────────────┘  │
│                                                                  │
│  Tokens:  Input 4,831  ·  Output 1,247  ·  Cost: 1 token        │
│                                                                  │
│  Overall Health:  [WARNING ⚠]  (large badge)                    │
└──────────────────────────────────────────────────────────────────┘
```

Timing bar: flex row, equal width cells, each cell = label 11px ALL-CAPS `#95A5A6` + value 16px 700 weight `#2D3436`. Cells separated by `1px solid #EEF0F3` vertical divider.
Overall health badge: large variant (36px height), prominently right-side or centered below timing.

---

**Executive Summary Card**

```
┌──────────────────────────────────────────────────────────────────┐
│  [file-text icon]  EXECUTIVE SUMMARY                             │
│  ────────────────────────────────────────────────────────────    │
│                                                                  │
│  The NAS shows signs of I/O performance stress linked to drive   │
│  sata4. Repeated timeout events on this drive are causing        │
│  elevated I/O wait across the system. The RAID array and all     │
│  other drives are healthy. Immediate attention to sata4 is       │
│  recommended before the issue progresses to RAID degradation.    │
│                                                                  │
└──────────────────────────────────────────────────────────────────┘
```

White bg, 6px radius, 20px padding, Elevation 1. Text: 15px, 400 weight, `#2D3436`, line-height 1.7.

---

**Findings Count Summary Bar**

Horizontal summary of finding counts by severity:

```
  ● CRITICAL  1   ● WARNING  3   ● INFO  2   ● OK  5    Total: 11 findings
```

Counts: 24px 700 weight colored per severity. Labels: 12px `#636E72`. Whole bar: `#F8F9FA` bg, 12px padding, 6px radius, margin-bottom 32px.

---

**Category Tabs**

```
[ All ] [ Hardware ] [ RAID ] [ Network ] [ Performance ] [ Security ] [ System ]
```

Tabs: `<nav>` with `<button type="button">` per tab. Active: `border-bottom 2px solid #0066FF`, text `#0066FF`, bg white. Inactive: text `#636E72`, bg transparent.
Each tab shows a count badge if it has findings: e.g., `Hardware (3)`.
Badge on tab: small, 18px height, colored by highest severity in that category (red if any critical, amber if warning, grey if only info/ok).

---

**Finding Cards**

Each finding renders as a card. Default view shows all findings sorted by severity (critical first, then warning, info, ok).

**Critical / Warning Finding Card:**

```
┌─ CRITICAL ───────────────────────────────────────────────────────┐  ← 4px left border #FF6358
│  [circle-alert icon]  Drive sata4 — Repeated I/O Timeouts        │  ← 16px, 600 weight
│  Category: Hardware                                               │  ← 12px #636E72
│  ─────────────────────────────────────────────────────────────── │
│  DESCRIPTION                                                      │
│  Drive sata4 (HDWG480, SN: 4240A21NFR0H) has logged 47 I/O       │
│  timeout events in the last 72 hours. Timeout frequency is        │
│  accelerating — 3 in the past 6 hours alone.                      │
│                                                                   │
│  ROOT CAUSE                                                       │
│  The drive is exhibiting failure behaviour. The high I/O wait     │
│  (37.3% at capture time) and system load of 7.36 are both         │
│  directly attributable to blocked I/O on this drive.              │
│                                                                   │
│  RECOMMENDATION                                                   │
│  Replace sata4 immediately. Run a SMART extended test now.        │
│  After replacement, verify RAID rebuilds cleanly.                 │
│                                                                   │
│  ▼ EVIDENCE (4 log entries)             Confidence: 97%           │
│  ─────────────────────────────────────────────────────────────── │
│  [dsm/var/log/disk_log.csv]                                       │
│  debug, 2026/03/24 03:33:40, /dev/sata4, HDWG480,                │
│  4240A21NFR0H, DS1821+, 4, timeout, rw, 0                        │
│  debug, 2026/03/24 03:03:08, /dev/sata4, HDWG480,                │
│  4240A21NFR0H, DS1821+, 4, timeout, rw, 0                        │
│                                                                   │
│  [dsm/result/top.result]                                          │
│  top - load average: 7.36, 8.55, 8.60 [IO: 7.13, 8.43, 8.53 ... │
│                                                                   │
└───────────────────────────────────────────────────────────────────┘
```

**Finding card specifications:**

| Property | Value |
|---|---|
| Background | White |
| Radius | 6px |
| Shadow | Elevation 1 |
| Padding | 20px |
| Left border | 4px solid — severity color |
| Margin bottom | 16px |
| Severity badge | Top-left, using severity badge component |
| Title | `<h3>` 16px, 600 weight, `#2D3436`, margin-left 8px (inline with badge) |
| Category | 12px, `#636E72`, margin-bottom 16px |
| Section labels | "DESCRIPTION", "ROOT CAUSE", "RECOMMENDATION" — 11px, 600 weight, ALL-CAPS, `#95A5A6`, letter-spacing 0.8px, margin-bottom 8px |
| Body text | 14px, 400 weight, `#2D3436`, line-height 1.6 |
| Evidence section | Collapsible (`<details>` / `<summary>` or Alpine.js toggle). Collapsed by default. |
| Evidence label | "▼ EVIDENCE (N log entries)" — `<button type="button">` styled as link, 13px `#0066FF` |
| Evidence block | `<code>` or `<pre>` in `#F8F9FA` bg, 13px monospace font `Consolas, monospace`, 12px padding, 4px radius, `overflow-x: auto` |
| Source file label | 11px, 600 weight, `#95A5A6`, ALL-CAPS, above each evidence block |
| Confidence | 11px, `#95A5A6`, right-aligned on same row as "EVIDENCE" label |

**OK Finding Card:**

Lighter treatment — less visual weight since it confirms a healthy state.

```
┌─ OK ─────────────────────────────────────────────────────────────┐  ← 4px left border #2DCE89
│  [circle-check icon]  RAID Array md2 — Fully Healthy             │
│  Category: RAID                                                   │
│  ─────────────────────────────────────────────────────────────── │
│  All 5 RAID 5 members are active and synchronized. No rebuild     │
│  in progress. Superblock state: clean. No stale members.         │
│                                                                   │
│  ▶ EVIDENCE (1 log entry)                       Confidence: 99%  │
└───────────────────────────────────────────────────────────────────┘
```

OK cards: shadow Elevation 0 (flat), bg `#FAFBFC`. Evidence collapsed by default.

---

**Category Summary Cards** (shown above findings when a category tab is active)

When viewing a specific category (e.g., "Hardware"), show a compact summary card at the top:

```
┌──────────────────────────────────────────────────────────────────┐
│  HARDWARE — 3 findings                                           │
│  1 CRITICAL  ·  1 WARNING  ·  0 INFO  ·  1 OK                   │
└──────────────────────────────────────────────────────────────────┘
```

Small banner, `#F8F9FA` bg, 12px padding, 6px radius, margin-bottom 24px.

---

**Report Export Menu** (`[Export ▼]` button in header):

Dropdown (Elevation 3): `[PDF Report]` · `[JSON Data]` · `[Print View]`
Each is a `<a>` link (navigation action, not a button).
PDF: generates a simplified print-friendly version.
JSON: downloads the raw `result_summary` JSONB.

---

### 8.3 Level 2 AI Scan Report (Multi-File)

Extends the Level 1 format with the following additions:

---

**Multi-File Header Extension** (below the main report header card):

```
┌──────────────────────────────────────────────────────────────────┐
│  [layers icon]  MULTI-FILE ANALYSIS — 2 Debug Files              │
│  ────────────────────────────────────────────────────────────    │
│  File 1:  debug_2320SKRBDTQZ0.dat  ·  Mar 15, 2026              │
│  File 2:  debug_2550RXRGBZQ0T.dat  ·  Apr 1, 2026               │
│  Span:  17 days                                                  │
└──────────────────────────────────────────────────────────────────┘
```

---

**Change Status Badges** on each finding:

Each finding card gains an additional badge in the top-right corner indicating whether the issue changed across the analyzed files:

| Change Status | Badge Label | Color |
|---|---|---|
| `NEW` | NEW | `#2196F3` info blue |
| `PERSISTENT` | PERSISTENT | `#FFAB2F` warning amber |
| `WORSENING` | WORSENING | `#FF6358` danger red |
| `RESOLVED` | RESOLVED | `#2DCE89` success green |

Badge position: top-right of the finding card, 8px from corner.

---

**Health Score Timeline** (section above findings):

```
  HEALTH PROGRESSION
  ──────────────────────────────────────────────────────────────────
  Mar 15, 2026                          Apr 1, 2026
  [WARNING ⚠]  ──────────────────────►  [CRITICAL ●]

  Hardware:   WARNING  ──────────────►  CRITICAL   ↑ worsened
  RAID:       NORMAL   ──────────────►  NORMAL     → unchanged
  Network:    NORMAL   ──────────────►  NORMAL     → unchanged
  Performance:WARNING  ──────────────►  CRITICAL   ↑ worsened
  Security:   NORMAL   ──────────────►  NORMAL     → unchanged
  System:     NORMAL   ──────────────►  NORMAL     → unchanged
```

Layout: `<table>` with category rows. Column per debug file (date as header). Each cell: health score badge (small). Arrow column shows direction: `↑ worsened` (red), `↓ improved` (green), `→ unchanged` (grey).

---

**Trend Evidence Section** (within worsening/persistent findings):

For WORSENING findings, the evidence section expands to show entries from each file chronologically:

```
  ▼ EVIDENCE — Progression across files

  [FILE 1: Mar 15, 2026]
  — 3 timeout events on sata4 in disk_log.csv
  — reset_fail_weight: 12 (warning threshold: 20)

  [FILE 2: Apr 1, 2026]
  — 47 timeout events on sata4 in disk_log.csv
  — reset_fail_status: critical
  — reset_fail_weight: 31 (critical threshold exceeded)
```

File section headers: 12px, 600 weight, `#0066FF`, bg `#E3F2FD`, 8px padding, 4px radius.

---

## 9. MODAL SPECIFICATIONS

### 9.1 Serial Number — Match Found Modal

Triggered when a debug file's extracted serial matches an existing project.

```
┌─────────────────────────────────────────────────────────┐
│  [info icon]  Device Already Registered                 │
│  ─────────────────────────────────────────────────────  │
│  The uploaded file is from:                             │
│  DS1821+  ·  Serial: 2320SKRBDTQZ0                      │
│                                                         │
│  This device is linked to project:                      │
│  "Home NAS"  (3 files, last scan Mar 15)               │
│                                                         │
│  What would you like to do?                             │
│                                                         │
│  (●) Add this file to "Home NAS"                        │
│  ( ) Add to current project ([current project name])    │
│  ( ) Create a new project for this device               │
│                                                         │
│  [Cancel]               [Continue ▸]                   │
└─────────────────────────────────────────────────────────┘
```

### 9.2 Serial Number — No Match Modal

Triggered when extracted serial doesn't match any existing project.

```
┌─────────────────────────────────────────────────────────┐
│  [info icon]  New Device Detected                       │
│  ─────────────────────────────────────────────────────  │
│  The uploaded file is from:                             │
│  DS1821+  ·  Serial: 9999NEWDEV0001                     │
│                                                         │
│  (●) Add to current project                             │
│      "[current project name]"                           │
│                                                         │
│  ( ) Create a new project for this device               │
│      Project name: [DS1821+ — 9999NEWDEV0001       ]    │
│                    (pre-filled, editable)                │
│                                                         │
│  [Cancel]               [Continue ▸]                   │
└─────────────────────────────────────────────────────────┘
```

### 9.3 Serial Number — Not Found in File Modal

Triggered when no serial number could be extracted.

```
┌─────────────────────────────────────────────────────────┐
│  [help-circle icon]  Device Not Identified              │
│  ─────────────────────────────────────────────────────  │
│  Could not extract a serial number from this file.      │
│  This may be a very old DSM version or a fresh install. │
│                                                         │
│  (●) Add to current project "[current project name]"    │
│  ( ) Add to a different project [select  ▼]             │
│                                                         │
│  [Cancel]               [Continue ▸]                   │
└─────────────────────────────────────────────────────────┘
```

### 9.4 Insufficient Tokens Modal

Triggered when a scan is attempted with insufficient tokens.

```
┌─────────────────────────────────────────────────────────┐
│  [ticket icon]  Insufficient Scan Tokens                │
│  ─────────────────────────────────────────────────────  │
│  A Level 2 scan costs 2 tokens.                         │
│  Your current balance: 1 token                          │
│                                                         │
│  Contact your administrator to add tokens to your       │
│  account.                                               │
│                                                         │
│                               [Close]                   │
└─────────────────────────────────────────────────────────┘
```

---

## 10. EMPTY STATES

Every component that can contain zero items must have a defined empty state. Never render a blank container.

| Page / Component | Icon | Heading | Subtext | CTA |
|---|---|---|---|---|
| Projects list | `folder` | "No projects yet" | "Create your first project to get started." | "Create Project" → `/projects/create` |
| Debug files (on project) | `upload-cloud` | "No files uploaded" | "Upload a Synology debug.dat file to begin." | "Upload Debug File" |
| Scans (on project) | `scan-line` | "No scans yet" | "Upload a debug file and run your first scan." | "Upload Debug File" |
| Recent scans (dashboard) | `clock` | "No recent activity" | "Your scan history will appear here." | "Go to Projects" |
| Tenant list (admin) | `users` | "No tenants yet" | "Create your first tenant account." | "Create Tenant" |
| Error log (admin) | `check-circle` | "No errors logged" | "All debug file extractions have completed successfully." | None |
| Search results | `search` | "No results found" | "Try adjusting your search terms." | "Clear search" |

Empty state anatomy: container centered (both axes), min-height 280px. Icon 48px `#E0E6ED`. Heading `<h3>` or `<h4>` 16px 600 weight `#2D3436`, margin-top 16px. Subtext `<p>` 14px `#636E72` max-width 280px centered, margin-top 8px. CTA: primary solid button 38px, margin-top 24px (if applicable).

---

## 11. STATUS BADGES & HEALTH INDICATORS

### 11.1 Complete Badge Inventory

**Scan Status Badges:**

```
[QUEUED]      #E3F2FD bg  #1976D2 text  #2196F3 border
[RUNNING]     #FFF5E5 bg  #E69A1C text  #FFAB2F border  (+ pulse animation on dot)
[COMPLETED]   #E8F8F3 bg  #23B373 text  #2DCE89 border
[FAILED]      #FFE8E5 bg  #E54D45 text  #FF6358 border
[CANCELLED]   #F8F9FA bg  #636E72 text  #DFE3E6 border
```

**User Status:**
```
[ACTIVE]      #E8F8F3 bg  #23B373 text  #2DCE89 border
[INACTIVE]    #F8F9FA bg  #636E72 text  #DFE3E6 border
[SUSPENDED]   #FFE8E5 bg  #E54D45 text  #FF6358 border
```

**Extraction Status:**
```
[PENDING]     #FFF5E5 bg  #E69A1C text  #FFAB2F border
[COMPLETED]   #E8F8F3 bg  #23B373 text  #2DCE89 border
[FAILED]      #FFE8E5 bg  #E54D45 text  #FF6358 border
```

**Scan Level:**
```
[L1]          #E3F2FD bg  #1976D2 text  none border   (Level 1)
[L2]          #EDE7F6 bg  #5E35B1 text  none border   (Level 2 — purple to distinguish)
```

**Change Status (Level 2 reports):**
```
[NEW]         #E3F2FD bg  #1976D2 text
[PERSISTENT]  #FFF5E5 bg  #E69A1C text
[WORSENING]   #FFE8E5 bg  #E54D45 text
[RESOLVED]    #E8F8F3 bg  #23B373 text
```

### 11.2 Running Scan Pulse Animation

For RUNNING status, include a pulsing dot before the text:

```css
.badge-running::before {
  content: '';
  display: inline-block;
  width: 8px;
  height: 8px;
  border-radius: 50%;
  background: #FFAB2F;
  margin-right: 6px;
  animation: pulse 1.5s ease-in-out infinite;
}

@keyframes pulse {
  0%, 100% { opacity: 1; transform: scale(1); }
  50%       { opacity: 0.4; transform: scale(0.8); }
}
```

---

## 12. RESPONSIVE BEHAVIOUR

### 12.1 Breakpoints

| Token | Width | Target |
|---|---|---|
| Mobile | < 768px | Phones |
| Tablet | 768px – 1199px | Tablets, small laptops |
| Desktop | 1200px+ | Standard desktops |

### 12.2 Layout Changes by Breakpoint

| Component | Desktop | Tablet | Mobile |
|---|---|---|---|
| Sidebar | 240px fixed | Collapsed to 64px icon-only | Hidden, hamburger menu opens overlay |
| Main content | flex-1 with max 1080px | Full width - 64px | Full width |
| KPI cards | 4–5 column grid | 2–3 column grid | 2 column grid |
| Charts | 2 column | 1 column (stacked) | 1 column |
| Data tables | Full table | Full table (scrollable) | Card view per row |
| Finding cards | Full width cards | Full width cards | Full width, evidence collapsed by default |
| Report header timing bar | 5 columns inline | 5 columns inline | 2 × 3 grid |
| Drawers | 400px from right | Full screen | Full screen |

### 12.3 Mobile Sidebar Overlay

Mobile: sidebar hidden. Hamburger button in topbar (`<button type="button" aria-label="Open menu">`). Tap to open sidebar as full-height overlay from left, 280px wide. Overlay behind: `rgba(0,0,0,0.5)`. Close on tap outside or × button.

### 12.4 Mobile Table → Card Transform

Each table row becomes a card on mobile:

```
┌──────────────────────────────────────────┐
│  DS1821+  ·  SN: 2320SKRBDTQZ0          │
│  Last Scan: Mar 15, 2026  [WARNING ⚠]   │
│  Bays: 5 of 8  ·  Scans: 7             │
│  [View ▸]  [Edit]  [Delete]              │
└──────────────────────────────────────────┘
```

Card: white bg, 6px radius, Elevation 1, 16px padding, 12px gap between cards. Primary identifier bold top-left. Secondary info 13px `#636E72`. Badges visible. Actions as text links below.

---

## 13. ERROR PAGES

### 13.1 404 Not Found

```
                   404
        Page not found

     The page you're looking for doesn't
        exist or has been moved.

           [← Go Back]
        or [Go to Dashboard]
```

| Property | Value |
|---|---|
| Error number | `<p>` 120px, 700 weight, `#E0E6ED`, NOT `<h1>` |
| Heading | `<h1>` "Page not found" — 28px, 600 weight, `#2D3436` |
| Subtext | `<p>` 15px, `#636E72`, max-width 360px, centered |
| Go Back | `<a>` with `onclick="history.back()"` — 14px, `#0066FF` |
| Dashboard link | `<a href="/dashboard">` — 14px, `#0066FF` |
| Page bg | `#F8F9FA` |
| Container | max-width 600px, vertically + horizontally centered |

### 13.2 500 Server Error

Same structure. Error number: "500". Heading: "Something went wrong." Subtext: "An unexpected error occurred. Please try again or contact support." CTA: "← Go Back" only.

### 13.3 Session Expired

```
                [lock icon 48px]
         Your session has expired

    For your security, you've been logged out
           after a period of inactivity.

              [Sign In Again ▸]
```

Redirect to login, preserving the intended destination URL in a query parameter.

---

## 14. CSS ARCHITECTURE

### 14.1 File Structure

```
public/assets/css/
├── tokens.css         ← CSS custom properties (from ActiveDesign.md Section VI)
├── reset.css          ← Minimal reset (box-sizing, margin, padding)
├── layout.css         ← Shell, sidebar, topbar, grid, page structure
├── typography.css     ← Font scale, heading styles, body text
├── components/
│   ├── buttons.css
│   ├── badges.css
│   ├── cards.css
│   ├── forms.css
│   ├── tables.css
│   ├── modals.css
│   ├── toasts.css
│   ├── progress.css
│   ├── skeleton.css
│   └── empty-states.css
├── pages/
│   ├── auth.css
│   ├── dashboard.css
│   ├── projects.css
│   ├── upload.css
│   ├── scan-progress.css
│   └── report.css
└── app.css            ← Imports all above in order
```

### 14.2 Naming Convention

BEM-style with application prefix:

```css
/* Block */
.ds-card { }
.ds-badge { }
.ds-table { }

/* Element */
.ds-card__header { }
.ds-badge__icon { }
.ds-table__row { }

/* Modifier */
.ds-badge--critical { }
.ds-badge--success { }
.ds-card--elevated { }

/* State (Alpine.js driven) */
.ds-table__row--selected { }
.ds-sidebar--collapsed { }
```

### 14.3 Alpine.js Usage Patterns

```html
<!-- Sidebar collapse -->
<div x-data="{ collapsed: false }">
  <nav :class="collapsed ? 'ds-sidebar--collapsed' : ''">...</nav>
  <button @click="collapsed = !collapsed">...</button>
</div>

<!-- Toast notifications -->
<div x-data="{ show: false, message: '', type: 'success' }"
     x-show="show"
     x-transition:enter="transition ease-out duration-300"
     x-transition:enter-start="opacity-0 -translate-y-4"
     x-transition:enter-end="opacity-100 translate-y-0"
     @toast.window="message = $event.detail.message; type = $event.detail.type; show = true; setTimeout(() => show = false, 4000)">
  ...
</div>

<!-- Confirmation modal -->
<div x-data="{ open: false, itemName: '' }"
     x-show="open"
     @confirm-delete.window="itemName = $event.detail.name; open = true">
  ...
</div>

<!-- Expandable evidence block -->
<div x-data="{ expanded: false }">
  <button @click="expanded = !expanded" type="button">
    <span x-text="expanded ? '▲ EVIDENCE' : '▼ EVIDENCE'"></span>
  </button>
  <div x-show="expanded" x-transition>...</div>
</div>

<!-- SSE scan progress -->
<div x-data="scanProgress()" x-init="connect()">
  <div x-text="stage"></div>
  <div :style="'width: ' + percent + '%'" class="ds-progress__fill"></div>
</div>
<script>
function scanProgress() {
  return {
    stage: 'Connecting...', percent: 0, status: 'queued',
    connect() {
      const es = new EventSource('/scan/{{ scan_id }}/progress');
      es.onmessage = (e) => {
        const d = JSON.parse(e.data);
        this.stage = d.stage; this.percent = d.percent; this.status = d.status;
        if (['completed','failed'].includes(d.status)) {
          es.close();
          if (d.status === 'completed') window.location = '/scan/{{ scan_id }}/report';
        }
      };
    }
  }
}
</script>
```

### 14.4 Print / PDF Styles

For report export, include print-specific styles:

```css
@media print {
  .ds-sidebar,
  .ds-topbar,
  .ds-report__export-btn,
  .ds-finding__evidence-toggle { display: none; }

  .ds-finding__evidence { display: block !important; }  /* Always show evidence in print */

  .ds-report-header { border: 1px solid #DFE3E6; padding: 16px; }
  .ds-finding-card  { break-inside: avoid; border: 1px solid #DFE3E6; margin-bottom: 12px; }

  body { background: white; font-size: 12px; }
  a    { color: #2D3436; text-decoration: none; }
}
```

---

*End of UI & Reporting Specification.*
*For design tokens: see `ActiveDesign.md`.*
*For technical implementation: see `AI_DebugScan_v3_Development_Specification.md`.*
*For debug file parsing logic: see `Hardwarev2.md`.*
