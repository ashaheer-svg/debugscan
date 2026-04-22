# Active Dashboard Lite — Master Design System & Technical Specifications V4.0

> **This document is the single Source of Truth** for all design, development, and UX decisions.
> V4.0 adds: Interactive Control Selection Guide, Notification Hierarchy, Chart Selection Guide,
> Semantic HTML Mapping, Modal vs. Page vs. Inline decision rules, Empty State specs, and Do/Don't rules.

---

## TABLE OF CONTENTS

1. [Architecture Governance & UX Principles](#i-architecture-governance--ux-principles)
2. [Iconography System](#ii-iconography-system)
3. [Design System Foundation](#iii-design-system-foundation)
4. [Component Specifications](#iv-component-specifications)
5. [Page-Specific Guides](#v-page-specific-guides)
6. [Implementation Guidelines](#vi-implementation-guidelines)
7. [Do / Don't Quick Reference](#vii-do--dont-quick-reference)

---

## I. ARCHITECTURE GOVERNANCE & UX PRINCIPLES

*These principles define **why** we design things. They govern decisions across all pages and components.*

---

### Content Display Patterns — The Decision Tree

Before designing any section, identify the primary user goal and select the correct pattern:

| Goal / Intent | Pattern to Use | When to Use | Trade-offs |
| :--- | :--- | :--- | :--- |
| **Discovery / Overview** | **Card View** | Item needs visual flair, a dedicated image, or a summary of stats. The item is a self-contained module. | ✅ High visual appeal. ✅ Best for CTAs. ❌ More vertical space. |
| **Analysis / Comparison** | **Table View** | Primary need is to scan large volumes of standardized, structured data and sort/filter on specific attributes. | ✅ Maximum data density. ✅ Best for sorting. ❌ Lacks visual flair. |
| **Management / Bulk Editing** | **List View** | Goal is to manage many entries where only a few key metadata points are needed (Title, Author, Date, Status). | ✅ Extremely space-efficient. ✅ Excellent for high-volume feeds. ❌ Lacks visual context. |
| **Sequential Workflow** | **Form / Stepper** | User must follow a defined, linear sequence of actions (e.g., creating a post). | ✅ Creates a single, achievable focus. ❌ Over-complexity causes abandonment. |
| **System Notification** | **Toast / Banner** | A transient status update (success, error, warning) triggered by a user action. | ✅ Non-blocking. ✅ Contextually relevant. ❌ Can be missed if too brief. |

---

### Interactive Control Selection Guide

> **Rule for AI:** When multiple controls could technically work, this table defines the single correct choice. Do not default to the most common control — choose by the use case column.

#### Form Control Selection

| Use Case | Correct Control | Wrong Choice | Why Wrong |
| :--- | :--- | :--- | :--- |
| Binary on/off setting with **immediate effect** (no form submit) | **Toggle Switch** | Checkbox | Checkbox implies deferred action on form submit |
| Binary setting **inside a form** (saved on submit) | **Checkbox** | Toggle | Toggle implies immediate persistence |
| 2–4 mutually exclusive options, all should be visible | **Radio Buttons** | Select Dropdown | Dropdown hides options, making comparison harder |
| 5+ mutually exclusive options | **Select Dropdown** | Radio Buttons | 5+ radios create excessive visual noise |
| Multiple independent true/false selections | **Checkboxes** | Radio Buttons | Radios are mutually exclusive; checkboxes are not |
| Free-form, single-line text | **Text Input** | Textarea | Textarea signals multi-line expectation to users |
| Free-form, multi-line text (e.g., bio, description) | **Textarea** | Text Input | Text input truncates and frustrates users |
| Selecting a date | **Date Picker input** | Plain text input | Plain text input allows invalid date formats |
| Selecting a value within a range (volume, opacity) | **Slider** | Number input | Sliders are more intuitive for continuous ranges |
| Filtering or searching a long option list (20+ items) | **Searchable Select / Combobox** | Radio or standard Select | Standard select is unusable beyond ~10 items |

#### Action Control Selection

| Use Case | Correct Control | Wrong Choice | Why Wrong |
| :--- | :--- | :--- | :--- |
| Triggering an **action** (submit, delete, open modal) | `<button>` | `<a>` tag | Links are for navigation, not actions. Breaks keyboard/screen reader semantics. |
| **Navigating** to a URL (internal or external) | `<a>` link | `<button>` | Buttons have no URL; right-click, open-in-tab, and history all break. |
| Primary action in a section (one per section max) | **Solid Primary Button** | Outline or Link | Hierarchy must be clear; multiple primaries dilute attention. |
| Secondary action alongside a primary | **Outline Button** | Second Solid Button | Two solid buttons = no visual hierarchy. |
| Low-emphasis inline action (e.g., "Edit", "Cancel") | **Link / Text Button** | Solid or Outline Button | Buttons draw too much attention for tertiary actions. |
| Destructive action (delete, remove) | **Danger Solid Button** | Primary Solid Button | Color must signal risk to prevent accidental clicks. |
| Disabled action with a reason | **Disabled Button + Tooltip** | Hidden button | Hiding removes discoverability; tooltip explains the constraint. |

#### Button Hierarchy per Section

A section must not contain more than one button of each emphasis level:

```
[Primary Solid]   ← One per section. The single most important action.
[Outline]         ← Supporting action. Optional.
[Link / Text]     ← Tertiary / cancel / low-risk action. Optional.
```

Never place two Primary Solid buttons side-by-side. If you need two equal-weight actions, use two Outline buttons and reconsider the UX flow.

---

### Notification & Feedback Hierarchy

> **Rule for AI:** Choose the notification pattern based on the *trigger type* and *required user response*, not on personal preference.

| Trigger | Pattern | Dismissal | Key Rules |
| :--- | :--- | :--- | :--- |
| Async action succeeded (save, publish, approve) | **Toast (success)** | Auto-dismiss after 4–5s | Non-blocking. User does not need to act. |
| Async action failed (network error, server error) | **Toast (danger) + Retry link** | Auto-dismiss or manual | Include a "Try again" action inside the toast. |
| Field-level validation error | **Inline error** below input | Clears when field is corrected | Must be directly adjacent to the offending field. Never grouped. |
| Form-level validation error (on submit) | **Inline errors** on each field + **scroll to first error** | Clears on correction | Do not use a toast for form errors — user needs to see which fields failed. |
| Page-level auth / session error | **Alert Banner** (top of page, full-width) | Manual dismiss | Persistent. Cannot be auto-dismissed. Use warning or danger color. |
| Irreversible or destructive action confirmation | **Confirmation Modal** | Explicit Yes / Cancel buttons | Must block the page. Must name the item being deleted. Never auto-dismiss. |
| Contextual hint (1 short line, supplementary) | **Tooltip** (hover-triggered) | Disappears on mouse-out | Never use for required information — tooltips are invisible to keyboard-only users by default. |
| Contextual help (2+ lines, optional reading) | **Popover** (click-triggered) | Click outside or X button | Persists until dismissed. Anchored to the triggering element. |
| First-time guidance for a feature | **Inline empty state** or **coach mark** | User action or dismiss | Never use a blocking modal for optional guidance. |

---

### Chart & Data Visualization Selection

> **Rule for AI:** Never default to a bar chart. Choose based on the relationship type of the data.

| Data Relationship | Chart Type | When NOT to Use It |
| :--- | :--- | :--- |
| **Trend over time** (single or few metrics) | **Line Chart** | When data points are not continuous over time (use Bar instead) |
| **Comparing discrete categories** at a point in time | **Bar Chart** | When showing trends over time (use Line instead) |
| **Part-to-whole composition** (≤6 segments) | **Donut Chart** | When segments are similar in size (hard to read) or there are 7+ segments |
| **Single metric snapshot** (no context needed) | **KPI Number Card** | Never use a chart for a single number — KPI card is cleaner |
| **Progress toward a known goal / percentage** | **Progress Bar** | When comparing multiple values against each other (use Bar) |
| **Ranked list of values** (leaderboard, referral sources) | **Leaderboard List** (horizontal rows + numeric value) | When more than ~10 items exist (use Table instead) |
| **Distribution of values across a range** | **Histogram / Area Chart** | Not currently in this design system — escalate to design review |

#### Chart Anatomy Standards

All chart containers share these properties:
- Background: White, 6px radius, Elevation 1, 20px padding
- Grid lines: `#EEF0F3`, 1px, dashed
- Axis labels: 12px, `#636E72`
- Legend: 12px, top-right, 16px margin
- Primary data color: `#0066FF` | Secondary: `#2196F3`
- Empty state: centered "No data available" text (14px, `#636E72`) + optional chart skeleton

---

### Overlay & Interruption Hierarchy

> **Rule for AI:** Choose the interruption level based on the complexity of the content and whether the action is reversible.

| Scenario | Pattern | Reasoning |
| :--- | :--- | :--- |
| **Creating a complex item** (full form, 6+ fields) | **Navigate to a new page** | A modal cannot contain that much content without internal scroll — which breaks modal UX. |
| **Confirming a destructive / irreversible action** | **Small Confirmation Modal** (title + description + 2 buttons only) | Must block flow. Forces deliberate acknowledgment. Never auto-dismiss. |
| **Editing 1–3 fields** on an existing item | **Inline edit** (field appears in place) | Least disruptive. Context remains fully visible. |
| **Editing 3–6 fields** off the main flow | **Slide-in Drawer / Side Panel** | Keeps the parent page visible behind it. Preserves context. |
| **Viewing detail** of a list or table item | **New page** (preferred) or **Drawer** (if lightweight) | A modal is too cramped for detail views. |
| **First-time onboarding** or guided setup | **Guided Modal** (with steps / progress indicator) | Controlled environment where blocking is intentional and expected. |
| **Optional feature education** | **Tooltip**, **Popover**, or **Inline callout** | Never block flow for optional guidance. |

#### Confirmation Modal Anatomy

```
┌─────────────────────────────────┐
│  [Warning Icon]  Delete Post?   │  H3, 20px, #2D3436
│                                 │
│  "This will permanently delete  │  14px, #636E72
│   'Post Title'. This action     │
│   cannot be undone."            │
│                                 │
│  [Cancel (outline)]  [Delete ▸] │  Danger solid button on right
└─────────────────────────────────┘
```

Rules:
- Always name the specific item being affected
- Destructive button on the right, Cancel on the left
- Max width: 480px, centered, Elevation 3, 24px padding
- Background overlay: `rgba(0,0,0,0.5)`, z-index: 300

---

### Progressive Disclosure — Anti-Clutter Rule

Only display the information a user needs *at the current step*. Hide advanced controls, technical settings, and secondary data behind explicit triggers (e.g., "Show Advanced Options"). This reduces cognitive load and prevents form abandonment.

---

### Perceived Performance Guidelines

| Technique | Rule |
| :--- | :--- |
| **Skeleton Loading Screens** | Never use generic spinners. Use grey shimmer-effect placeholder boxes that mimic the exact size and layout of the loading content. |
| **Optimistic UI Updates** | For single-action toggles (e.g., status toggle, bookmarking), update the UI immediately on click and sync to the backend asynchronously. |
| **Smart Pagination** | For datasets over 50 records, use infinite scrolling or paginated loading (20 records per page). Never load all data upfront. |

---

### Color & Status Usage Guide

| Element | Purpose | Principle | Example |
| :--- | :--- | :--- | :--- |
| **Primary Accent** | **Action** — directs the user to the next step. | Limited to CTAs, active links, and active states only. | Primary button backgrounds, active tab underlines. |
| **Success / Error Colors** | **Feedback** — confirms a state change. | Must always be paired with an icon (✓ or ✗) AND clear text. Never color alone. | "Profile Saved" (green), "Required Field Missing" (red). |
| **Neutral Grays** | **Metadata** — provides context without demanding attention. | Use for dates, authors, labels, and secondary statistics. | Author byline, post date. |
| **Warning** | **Caution** — highlights a pending or risky state. | Must pair with icon and text. Never use for actions. | "Scheduled" status badge, "Password Protected" badge. |

---

## II. ICONOGRAPHY SYSTEM

### Usage Rules

1. **Style Homogeneity:** Stick to one visual style (e.g., Outline) across the entire application. Mixing solid and outline icons creates cognitive friction.
2. **Sizing:** Use only the defined sizes below. Do not introduce ad-hoc sizes.
3. **Semantic Meaning:** Icons must *explain* the action, not merely decorate it.
4. **Tooltip Requirement:** Any standalone icon without a text label must have a tooltip.

### Defined Icon Sizes

| Token | Size | Usage |
|-------|------|-------|
| xs | 14px | Inline text, tight layouts |
| sm | 16px | Standard UI iconography (most common) |
| md | 20px | Button icons, medium emphasis |
| lg | 24px | Section headers, prominent icons |
| xl | 32px | Featured/hero icons only |

### Common Icon Mapping

| Concept | Recommended Icon | Primary Use |
| :--- | :--- | :--- |
| **User / Identity** | Avatar / Person outline | User Profile, Discussion Panel |
| **Edit** | Pencil outline | "Edit Post," "Edit Profile" buttons |
| **Delete** | Trash outline | Delete buttons, bulk action deletion |
| **Save / Submit** | Checkmark or Floppy disk | "Publish," "Save Draft" buttons |
| **Search** | Magnifying glass | Search bars, filter controls |
| **Info / Help** | Circle-i or Question mark | Tooltips, help links |
| **Warning / Alert** | Triangle exclamation | Warning badges, pending status |
| **Visibility** | Eye outline | Visibility control (Public/Private) |
| **Schedule** | Calendar outline | Schedule control |
| **Status / Flag** | Flag outline | Post status control |
| **More Options** | Horizontal ellipsis (⋯) | Must always have "More Options" tooltip |

---

## III. DESIGN SYSTEM FOUNDATION

### Typography

#### Font Family
- **Primary:** `-apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif`
- **Fallback:** `Helvetica, Arial, sans-serif`
- **Weight Scale:** 300 (light), 400 (regular), 500 (medium), 600 (semibold), 700 (bold)

#### Type Scale

| Element | HTML Tag | Font Size | Line Height | Letter Spacing | Weight |
|---------|----------|-----------|-------------|----------------|--------|
| H1 / Hero Title | `<h1>` | 36px / 2.25rem | 1.3 | -0.8px | 700 |
| H2 / Page Title | `<h2>` | 28px / 1.75rem | 1.4 | -0.5px | 700 |
| H3 / Section Heading | `<h3>` | 20px / 1.25rem | 1.5 | -0.3px | 600 |
| H4 / Subsection | `<h4>` | 16px / 1rem | 1.5 | 0px | 600 |
| Body Text | `<p>` | 14px / 0.875rem | 1.6 | 0px | 400 |
| Small Text / Metadata | `<small>` or `<span>` | 12px / 0.75rem | 1.5 | 0.3px | 400 |
| Caption / Label | `<label>` or `<span>` | 11px / 0.6875rem | 1.4 | 0.5px | 500 |
| Button Text (Standard) | `<button>` | 14px / 0.875rem | 1.5 | 0.5px | 500 |
| Button Text (Small) | `<button>` | 12px / 0.75rem | 1.4 | 0.4px | 500 |

> **Heading hierarchy rule:** Never skip heading levels. H2 → H3 → H4 is valid. H2 → H4 is not. H1 is reserved for hero/marketing contexts only — dashboard pages start at H2.

---

### Color Palette

#### Primary

| State | Hex | RGB |
|-------|-----|-----|
| Default | `#0066FF` | 0, 102, 255 |
| Hover | `#0052CC` | 0, 82, 204 |
| Active | `#003D99` | 0, 61, 153 |
| Disabled | `#CCDDFF` | 204, 221, 255 |

#### Secondary

| State | Hex | RGB |
|-------|-----|-----|
| Default | `#6C757D` | 108, 117, 125 |
| Hover | `#5A6268` | 90, 98, 104 |
| Active | `#495057` | 73, 80, 87 |
| Disabled | `#CED4DA` | 206, 212, 218 |

#### Status Colors

| Color | Default | Hover | Light Background |
|-------|---------|-------|------------------|
| **Success** | `#2DCE89` | `#23B373` | `#E8F8F3` |
| **Danger / Error** | `#FF6358` | `#E54D45` | `#FFE8E5` |
| **Warning** | `#FFAB2F` | `#E69A1C` | `#FFF5E5` |
| **Info** | `#2196F3` | `#1976D2` | `#E3F2FD` |

#### Neutral Colors

| Token | Hex | Usage |
|-------|-----|-------|
| Dark Text | `#2D3436` | Primary body text |
| Medium Text | `#636E72` | Secondary labels, metadata |
| Light Text | `#95A5A6` | Tertiary, muted text |
| Disabled Text | `#B0BEC5` | Disabled state text |
| Border | `#DFE3E6` | Subtle borders |
| Row Border | `#EEF0F3` | Table rows, list separators |
| Light Gray BG | `#F8F9FA` | Sidebars, alternating rows |
| Off-White | `#FAFBFC` | Minimal contrast backgrounds |
| White | `#FFFFFF` | Main backgrounds, cards |

---

### Spacing System

| Token | Size | Usage |
|-------|------|-------|
| xs | 4px / 0.25rem | Micro-spacing, icon gaps |
| sm | 8px / 0.5rem | Compact spacing, small gaps |
| md | 12px / 0.75rem | Standard internal spacing |
| lg | 16px / 1rem | Card inner content padding |
| xl | 20px / 1.25rem | Card outer padding |
| 2xl | 24px / 1.5rem | Grid gaps, column spacing |
| 3xl | 32px / 2rem | **Section margin** (between major page sections) |
| 4xl | 40px / 2.5rem | Page-level margins |
| 5xl | 48px / 3rem | Major section separation |
| 6xl | 64px / 4rem | Page-level hero spacing |

#### Standard Padding & Margins

| Component | Padding |
|-----------|---------|
| Card (outer) | 20px all sides |
| Card (inner content) | 16px |
| Button (standard) | 10px vertical × 16px horizontal |
| Button (small) | 8px vertical × 12px horizontal |
| Form Field | 10px vertical × 12px horizontal |
| **Section Margin** | **32px between major sections** |
| Grid Gap | 24px (card grids), 16px (dense grids) |

> **Note:** Section margin is 32px throughout. 24px is the grid column gap — these are not interchangeable.

---

### Shadow System

| Level | `box-shadow` value | Usage |
|-------|-------------------|-------|
| Elevation 0 | `none` | Flat/base elements |
| Elevation 1 | `0 1px 3px rgba(0,0,0,0.08)` | Cards (default), form focus |
| Elevation 2 | `0 2px 6px rgba(0,0,0,0.10)` | Hovered cards |
| Elevation 3 | `0 4px 12px rgba(0,0,0,0.12)` | Modals, dropdowns |
| Elevation 4 | `0 8px 24px rgba(0,0,0,0.15)` | Floating elements, toasts |

#### Shadow Usage Rules
- Cards (default): Elevation 1
- Cards (hover): Elevation 2 + scale(1.02x)
- Modals & Dropdowns: Elevation 3
- Floating Buttons / Toasts: Elevation 4
- Form Inputs (focus): Elevation 1 + primary border

---

### Border System

#### Border Radius

| Context | Radius |
|---------|--------|
| Small components (inputs, small buttons) | **4px** |
| Standard components (cards, medium buttons) | 6px |
| Large components (modals, panels) | 8px |
| Circular avatars | 50% |
| Badges | 4px |

#### Border Styles

| Use | Value |
|-----|-------|
| Divider lines | `1px solid #DFE3E6` |
| Table / list row separator | `1px solid #EEF0F3` |
| Input focus border | `2px solid #0066FF` |
| Input error border | `2px solid #FF6358` |
| Input success border | `2px solid #2DCE89` |

---

### Layout & Grid System

#### Container Specifications

| Property | Value |
|----------|-------|
| Max Container Width | 1080px |
| Global Sidebar Width | 240px (expanded), 80px (collapsed) |
| Page Content Padding | 24px |
| Mobile Breakpoint | 768px |

#### Grid

- 12-column grid (Bootstrap standard)
- Column Gap: 24px
- Row Gap: 24px

#### Breakpoints

| Token | Width | Device |
|-------|-------|--------|
| xs | < 576px | Mobile phones |
| sm | ≥ 576px | Small devices |
| md | ≥ 768px | Tablets |
| lg | ≥ 992px | Desktops |
| xl | ≥ 1200px | Large desktops |

---

### Z-Index Scale

| Layer | Z-Index | Usage |
|-------|---------|-------|
| Base | 0 | Normal page content |
| Raised | 10 | Sticky table headers |
| Dropdown | 100 | Select menus, popovers |
| Sticky Nav | 200 | Fixed sidebars, top navbars |
| Modal Overlay | 300 | Modal backdrop |
| Modal | 400 | Modal dialog |
| Toast / Notification | 500 | Success/error toast messages |
| Tooltip | 600 | Hover tooltips |

---

## IV. COMPONENT SPECIFICATIONS

### Semantic HTML Element Mapping

> **Rule for AI:** Always use the semantically correct HTML element. Never use a `<div>` or `<span>` where a semantic element exists. This is required for accessibility and correct assistive technology behavior.

| UI Region / Component | HTML Element | Rule / Notes |
| :--- | :--- | :--- |
| Global sidebar navigation | `<nav>` | Landmark element. Screen readers announce it. One per page. |
| Primary page content area | `<main>` | Only one `<main>` per page. |
| Self-contained content item (post card, article) | `<article>` | Must have its own heading inside. |
| Grouped content with a title (KPI section, chart block) | `<section>` | Requires a visible `<h2>`/`<h3>` heading. |
| Group of related form inputs | `<fieldset>` + `<legend>` | Legend is read as the group label by screen readers. |
| Form submission action | `<button type="submit">` | Must declare type explicitly. |
| Non-submit button (toggle, modal open, delete) | `<button type="button">` | Must declare type explicitly — omitting type defaults to submit inside a form. |
| Navigation link (goes to a URL) | `<a href="...">` | Must have `href`. Never use for actions. |
| Status badge / category tag | `<span>` | Inline, non-structural. Never `<div>`. |
| Key metric label + value pair | `<dl>` / `<dt>` / `<dd>` | Semantic key-value structure. |
| Page section heading | `<h2>` | Never a styled `<div>`. |
| Card subheading | `<h3>` | Must follow `<h2>` — no heading level skipping. |
| Form input label | `<label for="inputId">` | Must reference input `id`. Never omit. |
| Required field indicator | `<span aria-hidden="true">*</span>` + `required` attribute | The asterisk is decorative; the `required` attr conveys meaning. |
| Sidebar control block title (all-caps label) | `<p>` or `<span>` with class | Not a heading — these are labels, not structural headings. |
| Table header cell | `<th scope="col">` | `scope` attribute is required for accessibility. |
| Table data cell | `<td>` | Standard data cell. |
| Ordered/ranked list (leaderboard) | `<ol>` | Communicates ranking to screen readers. |
| Unordered list (discussion items, menu items) | `<ul>` | Never use bare `<div>` for lists. |

---

### Empty State Specs

> **Rule for AI:** Every component that can contain zero items **must** have an empty state defined. Never render a blank white box.

#### Empty State Anatomy

```
┌──────────────────────────────────┐
│                                  │
│          [Icon 48px]             │  Optional. #E0E6ED color (muted).
│                                  │
│       No posts yet               │  H3 or H4, 16px, 600 weight, #2D3436
│                                  │
│  You haven't created any posts   │  14px, 400 weight, #636E72
│  yet. Start by adding your       │  Max width: 280px, centered.
│  first one.                      │
│                                  │
│     [+ Create your first post]   │  Primary solid button, medium size
│                                  │
└──────────────────────────────────┘
```

#### Empty State Specifications

| Property | Value |
|----------|-------|
| Container alignment | Horizontally and vertically centered within the parent |
| Min height | Match the height of a full content block (e.g., 300px for a table, 380px for a card grid row) |
| Icon | 48px, `#E0E6ED` (muted gray), outline style |
| Heading | 16px, 600 weight, `#2D3436` |
| Subtext | 14px, 400 weight, `#636E72`, max-width 280px, centered |
| CTA Button | Primary solid, medium (38px) — one action only |
| Spacing | 16px between icon and heading, 8px between heading and subtext, 24px between subtext and button |

#### Empty State Messaging by Context

| Component | Heading | Subtext | CTA |
|-----------|---------|---------|-----|
| Blog Posts list | "No posts yet" | "Start writing your first post." | "Create Post" |
| Discussion panel | "No comments yet" | "When readers leave comments, they'll appear here." | None |
| Data table | "No results found" | "Try adjusting your search or filters." | "Clear filters" |
| Search results | "No matches for '[query]'" | "Check the spelling or try a different term." | None |
| User list | "No users found" | "Invite someone to get started." | "Invite User" |

---

### Avatar Sizes

| Size | Dimension | Usage |
|------|-----------|-------|
| Extra Small | 24px | Inline, notifications |
| Small | 32px | Lists, comments |
| Medium | 48px | User cards, profiles |
| Large | 80px | Profile headers |
| Extra Large | 120px | Profile hero image |

---

### Button Sizes

| Type | Height | Padding | Font Size |
|------|--------|---------|-----------|
| Extra Small | 28px | 6px 12px | 11px |
| Small | 32px | 8px 12px | 12px |
| Medium (Standard) | 38px | 10px 16px | 14px |
| Large | 44px | 12px 20px | 14px |
| Block | 38px | 10px 16px | 14px (full-width) |

---

### Component States — Full 8-State Matrix

Every interactive component must implement all applicable states from this matrix:

| State | Definition | Implementation |
| :--- | :--- | :--- |
| **Default** | Resting state, no interaction. | As per component spec. |
| **Hover** | Pointer over element. | Subtle lift (Elevation 2) + color shift (~15% darker). |
| **Focus** | Keyboard-selected (Tab). | `2px solid #0066FF` border + `outline-offset: 2px`. |
| **Filled / Active** | Input has a value / element is selected. | Text color `#2D3436`, border `#DFE3E6`. |
| **Error** | Validation failed. | `2px solid #FF6358` border + error icon + error message text. |
| **Success** | Validation passed. | `2px solid #2DCE89` border + checkmark icon + success message. |
| **Disabled** | Action unavailable. | Background `#E0E6ED`, text `#B0BEC5`, `cursor: not-allowed`. |
| **Loading** | Awaiting async result. | Skeleton shimmer placeholder matching content dimensions. |

> **Note:** The Forms & Components reference page must display all 8 states for every interactive component.

---

### Button States

| State | Background | Text | Border | Shadow |
|-------|-----------|------|--------|--------|
| Default | Brand color | White | None | Elevation 1 |
| Hover | Color −15% | White | None | Elevation 2 |
| Active/Pressed | Color −30% | White | Inset | Elevation 0 |
| Disabled | `#E0E6ED` | `#B0BEC5` | None | None |
| Focus | Brand color | White | 2px outline, offset 2px | Elevation 1 |
| Loading | Brand color | — | None | Elevation 1 (spinner inside) |

**Outline Button Variant**

| State | Background | Text | Border | Shadow |
|-------|-----------|------|--------|--------|
| Default | Transparent | Brand color | 2px solid brand color | None |
| Hover | Brand @ 10% opacity | Brand −15% | 2px solid brand color | Elevation 1 |
| Active | Brand @ 20% opacity | Brand −30% | 2px solid brand color | Elevation 0 |
| Disabled | Transparent | `#B0BEC5` | 2px solid `#DFE3E6` | None |
| Focus | Transparent | Brand color | 2px + outline offset | Elevation 1 |

**Link / Text Button Variant**
- No background, no border.
- Text: brand color (`#0066FF`), underline on hover.
- Use for low-emphasis inline actions (e.g., "Edit" in sidebar controls, "Cancel" in forms).
- Must still be a `<button type="button">` element — not an `<a>` tag — if it triggers an action.

---

### Form Input States

| State | Background | Border | Text | Notes |
|-------|-----------|--------|------|-------|
| Default | White | `1px solid #DFE3E6` | `#2D3436` | — |
| Hover | White | `1px solid #B0BEC5` | `#2D3436` | Elevation 1 shadow |
| Focus | White | `2px solid #0066FF` | `#2D3436` | Elevation 1 shadow |
| Filled | White | `1px solid #DFE3E6` | `#2D3436` | — |
| Error | White | `2px solid #FF6358` | `#FF6358` (message) | Error icon right-aligned |
| Success | White | `2px solid #2DCE89` | `#2DCE89` (message) | Checkmark icon right-aligned |
| Disabled | `#F8F9FA` | `1px solid #DFE3E6` | `#95A5A6` | `cursor: not-allowed` |
| Loading | White | `1px solid #DFE3E6` | — | Spinner icon right-aligned |

---

### Link States

| State | Color | Decoration |
|-------|-------|-----------|
| Default | `#0066FF` | None |
| Hover | `#0052CC` | Underline |
| Active | `#003D99` | Underline |
| Visited | `#666666` | None (optional) |
| Disabled | `#B0BEC5` | None |

---

### Toggle Switch

| Property | Value |
|----------|-------|
| Track Width | 50px |
| Track Height | 24px |
| Track (OFF) | `#DFE3E6` |
| Track (ON) | `#2DCE89` |
| Track (Disabled) | `#E0E6ED` |
| Knob Size | 20px diameter |
| Knob Color | White |
| Knob Shadow | Elevation 1 |
| Knob Offset (ON) | 26px from left |
| Knob Offset (OFF) | 2px from left |
| Transition | 200ms ease-in-out |
| Track Radius | 12px |

---

### Progress Bar

| Property | Value |
|----------|-------|
| Height | 8px |
| Track Background | `#E0E6ED` |
| Primary Fill | `#0066FF` |
| Success Fill | `#2DCE89` |
| Warning Fill | `#FFAB2F` |
| Danger Fill | `#FF6358` |
| Border Radius | 4px |
| Transition | 300ms ease |

---

### Skeleton Loading Placeholders

Use shimmer-effect placeholders that match the content being loaded. Never use generic spinners.

| Component | Placeholder Dimensions |
|-----------|----------------------|
| KPI Card | Full card dimensions (120px height) |
| Post Card | Full card (380px height) |
| List Row | 44px height × 100% width |
| Table Row | 48px height × 100% width |
| Avatar (sm) | 32px circle |
| Avatar (lg) | 80px circle |
| Chart | Full container height (300px) |

**Shimmer CSS pattern:**
```css
background: linear-gradient(90deg, #E0E6ED 25%, #F8F9FA 50%, #E0E6ED 75%);
background-size: 200% 100%;
animation: shimmer 1.5s infinite;
```

---

## V. PAGE-SPECIFIC GUIDES

### 1. BLOG DASHBOARD (Index Page)

**Goal:** At-a-glance KPI and activity monitoring.
**Patterns:** Card View (KPIs), Card View (Charts), List View (Leaderboard).
**Semantic Structure:** `<main>` wrapping all sections. Each section uses `<section>` with an `<h2>`.

#### KPI Cards Section

| Property | Value |
|----------|-------|
| Grid | 5-column on desktop, 3-column on tablet, 2-column on mobile |
| Card Height | 120px |
| Card Element | `<section>` or `<article>` with `<dl>` for label/value pairs |
| Card Background | White |
| Border Radius | 6px |
| Padding | 20px |
| Shadow | Elevation 1 |
| Gap | 24px |
| KPI Number | 28px, 700 weight, `#2D3436` |
| KPI Label | 12px, 400 weight, `#636E72` |
| Trend Indicator | 12px, 500 weight; `#2DCE89` (positive), `#FF6358` (negative) |
| Hover | Elevation 2 + scale(1.02x) |
| Empty State | "No data available" centered in card, 12px `#636E72` |

KPI Metrics: Posts, Pages, Comments, Users, Subscribers.

#### Charts Section

Chart type selection for this page:
- **User activity over time** → **Line Chart** (trend over time)
- **Device distribution** → **Donut Chart** (part-to-whole composition)

| Property | Value |
|----------|-------|
| Grid | 2 columns × 1 row, 24px gap |
| Chart Height | 300px |
| Card Styling | White, 6px radius, Elevation 1 |
| Padding | 20px |
| Grid Lines | `#EEF0F3`, 1px, dashed |
| Data Line | `#0066FF`, 2px |
| Secondary Line | `#2196F3` |
| Axis Labels | 12px, `#636E72` |
| Legend | 12px, top-right, 16px margin |

Time Period Selector: `<button type="button">` elements in a `<nav>` or button group. 11px, 500 weight, outline style, 24px height, active state = primary fill.

#### Discussions Panel

| Property | Value |
|----------|-------|
| Container Element | `<section>` with `<h3>` heading |
| List Element | `<ul>` with `<li>` per discussion item |
| Width | Right column (or full-width if stacked) |
| Background | White |
| Border Radius | 6px |
| Padding | 20px |
| Shadow | Elevation 1 |

Discussion item anatomy:
- Avatar: `<img>` 32px circular, `alt="[Author name]"`, margin-right 12px
- Author Name: 14px, 600 weight, primary link color — `<a>` if links to profile
- Post Title: 14px, 400 weight, link-styled — `<a href="...">`
- Timestamp: 12px, `#95A5A6`, `<time datetime="...">` element
- Comment Text: 13px, `#636E72`, margin-top 8px, line-height 1.6
- Action Buttons: `<button type="button">` — Approve (success), Reject (danger), Edit (outline)

#### Referral Leaderboard

- Use `<ol>` — ranking order is semantically meaningful
- Source Name: 14px, 500 weight, `#2D3436`
- Rank Number: 12px, `#95A5A6`, margin-right 12px
- Value: 14px, 600 weight, `#0066FF`
- Row Height: 44px, padding 12px 0
- Row border: `1px solid #EEF0F3` (bottom)
- Empty state: "No referral data yet" — 14px, `#636E72`, centered

---

### 2. BLOG POSTS (Components Page)

**Goal:** Content discovery and management.
**Patterns:** Hybrid — Featured Posts = Card View; Archive = List View.

The transition between Card View (rich preview) and List View (dense data) must feel seamless — same content, different presentation density.

#### Featured Posts Grid

| Property | Value |
|----------|-------|
| Container Element | `<section>` with `<h2>` |
| Card Element | `<article>` — each card is a self-contained post |
| Columns | 3 desktop / 2 tablet / 1 mobile |
| Gap | 24px |
| Card Height | 380px (image: 200px, content: 180px) |
| Border Radius | 6px |
| Shadow | Elevation 1 → Elevation 2 (hover) |
| Hover | scale(1.03x) |
| Image | `<img>` 200px height, `object-fit: cover`, descriptive `alt` text |
| Content Padding | 16px |
| Title | `<h3>` 18px, 600 weight, `#2D3436` — inside the `<article>` |
| Excerpt | `<p>` 13px, 400 weight, `#636E72`, max 2 lines |
| Date | `<time datetime="...">` 12px, `#95A5A6` |
| Author | `<span>` 12px, `#636E72`, preceded by "by" |

Category Badge: `<span>` element. Height: 24px, padding: 4px 8px, font: 11px 600 white, radius: 4px.
- Business: `#FF6358` | Travel: `#2196F3` | Technology: `#0066FF` | News: `#FFAB2F`

Empty state (no featured posts):
- Icon: 48px, `#E0E6ED`
- Heading: "No featured posts"
- Subtext: "Pin posts to feature them here."
- CTA: None (management action is elsewhere)

#### Compact Post List

| Property | Value |
|----------|-------|
| Container Element | `<section>` with `<h2>` |
| List Element | `<ul>` with `<li>` per row |
| Row Height | 44px |
| Padding | 12px 0 |
| Border | `1px solid #EEF0F3` (bottom) |
| Hover BG | `#F8F9FA` |
| Columns | Title, Author, Category, Date, Bookmark |
| Title | `<a href="...">` **14px**, 500 weight, `#0066FF`; underline on hover |
| Author | `<span>` 12px, `#636E72` |
| Category Badge | `<span>` 12px, 20px height (same color coding) |
| Date | `<time>` 12px, `#95A5A6` |
| Bookmark | `<button type="button">` 18px icon; inactive `#95A5A6` → active `#FFB81C` |

Empty state: "No posts found" + "Try adjusting your filters." + "Clear filters" button.

#### Pagination

- Use `<nav aria-label="Pagination">` wrapping `<ul>` of page `<li>` items
- Active page: Primary `#0066FF` bg + white text, `aria-current="page"`
- Inactive: Border `#DFE3E6`, text `#636E72`
- Height: 44px, centered, 8px item spacing

---

### 3. ADD NEW POST

**Goal:** Content creation.
**Patterns:** Form View (editor) + Stepper/Checklist (sidebar).

The sidebar controls must feel like a **Stepper** or **Status Checklist**, guiding the user through completion steps — not just listing settings.

#### Page Layout

| Property | Value |
|----------|-------|
| Page Element | `<main>` |
| Layout | 2-column grid |
| Main Width | calc(100% − 340px − 24px gap) |
| Sidebar Width | 320px |
| Gap | 24px |
| Page Padding | 24px |

#### Editor (Main Area)

Wrap in `<form>` element with `<fieldset>` for logical grouping.

| Property | Value |
|----------|-------|
| Background | White |
| Border Radius | 6px |
| Padding | 24px |
| Shadow | Elevation 1 |
| Min Height | 600px |
| Title Element | `<input type="text">` or `<textarea>` — 28px, 600 weight, `#2D3436` |
| Title Border | Underline only; default `#EEF0F3` → focus `#0066FF` |
| Body Element | `<textarea>` — 14px, 400 weight, `#2D3436`, line-height 1.6 |
| Body Min Height | 400px |
| Body Border | `1px solid #DFE3E6`, radius 4px; focus: `2px solid #0066FF` |

#### Sidebar Control Panel

| Property | Value |
|----------|-------|
| Element | `<aside>` — sidebar content, not a `<div>` |
| Width | 320px |
| Background | `#F8F9FA` |
| Border Radius | 6px |
| Padding | 20px |
| Border | `1px solid #EEF0F3` |

Top Buttons (`<button>` elements, not links):
- "Publish": `<button type="submit">` — solid primary `#0066FF`, white text, 100% width, 38px
- "Save Draft": `<button type="button">` — outline style, primary border/text, 100% width, 38px
- Margin between: 8px

Control Block anatomy (Status, Visibility, Schedule, Readability):
- Icon: 16px, `#636E72`, margin-right 8px
- Title: `<p>` 12px, 600 weight, all-caps, letter-spacing 0.5px — not a heading
- Value: `<span>` 13px, 400 weight, `#636E72`
- Edit trigger: `<button type="button">` styled as link — 11px, `#0066FF`
- Padding-bottom: 16px; border-bottom: `1px solid #DFE3E6`

Control sections:

| Section | Icon | Values | Colors |
|---------|------|--------|--------|
| Status | Flag | Draft / Published / Scheduled | Gray / Green `#2DCE89` / Yellow `#FFAB2F` |
| Visibility | Eye | Public / Private / Password Protected | Blue / Gray / Yellow |
| Schedule | Calendar | Now / Scheduled for [date] | — |
| Readability | Score badge | Ok / Good / Excellent | Gray / Green / Blue |

Categories Panel:
- Use `<fieldset>` + `<legend>Categories</legend>`
- Max-height: 200px, scrollable
- Item height: 28px
- Each item: `<label>` wrapping `<input type="checkbox">` + text
- Checkbox: 16px × 16px, primary when checked

---

### 4. FORMS & COMPONENTS

**Goal:** Reference and consistency — the master guide for all component states.

This page must clearly display **all 8 states** (Default, Hover, Focus, Filled, Error, Success, Disabled, Loading) for every interactive component.

#### Color Palette Swatches

8-column grid, 100px height per swatch:

| Color | Hex |
|-------|-----|
| Primary Blue | `#0066FF` |
| Secondary Gray | `#6C757D` |
| Success Green | `#2DCE89` |
| Info Blue | `#2196F3` |
| Warning Amber | `#FFAB2F` |
| Danger Red | `#FF6358` |
| Dark | `#2D3436` |
| Light / White | `#FFFFFF` |

Swatch card: 100px total height (75px color block + 25px label area); border `1px solid #EEF0F3`, radius 6px; label 12px bold, hex 11px monospace `#636E72`.

#### Form Inputs Grid (3 columns)

- **Checkboxes:** `<input type="checkbox">` — 18px × 18px, `2px solid #DFE3E6` default → `2px solid #0066FF` checked
- **Radio Buttons:** `<input type="radio">` — 18px diameter, 8px inner dot when selected
- **Toggle Switches:** Custom component (see spec in Section IV). Backed by `<input type="checkbox">` for accessibility.

#### Button Grid

- Small buttons (32px) and Medium buttons (38px)
- All variants: Primary, Secondary, Success, Info, Warning, Danger, Dark, Light
- Both Solid and Outline styles
- All 8 states visible

#### Progress Bars & Sliders

Per specs in Section IV. Show all 4 fill colors.

#### Button Groups

- No radius on interior buttons; 4px radius on first/last only
- Shared `1px solid #DFE3E6` borders between buttons
- Active button: `#0066FF` bg, white text
- Use `role="group"` with `aria-label` on the container

#### Input Groups

- Prepend/Append: `#F8F9FA` background, `1px solid #DFE3E6` border, 14px `#636E72` font
- Icon inside: 16px, `#636E72`

---

### 5. DATA TABLES

**Goal:** Analysis and structure.
**Pattern:** Table View. Emphasize sortable headers, row hover states, and mobile transformation.

Mobile rule: Table → Card View (one row per card, "Label: Value" pairs).

#### Table Container

| Property | Value |
|----------|-------|
| Element | `<table>` inside a `<div>` scroll container |
| Background | White |
| Border Radius | 6px |
| Shadow | Elevation 1 |
| Container Padding | 20px |
| Overflow | `auto` (horizontal scroll on tablet) |

#### Header Row

| Property | Value |
|----------|-------|
| Element | `<thead>` > `<tr>` > `<th scope="col">` |
| Background | `#F8F9FA` |
| Border Bottom | `2px solid #DFE3E6` |
| Cell Padding | 12px vertical × 16px horizontal |
| Font | 12px, 600 weight, all-caps, letter-spacing 0.5px |
| Text Color | `#636E72` |
| Sort Icon | 12px, active `#0066FF` / inactive `#95A5A6`, margin-left 4px |
| Hover (sortable) | Background `#F0F2F5`, cursor pointer |
| Sort Button | `<button type="button">` inside `<th>` — not a styled `<div>` |

#### Body Rows

| Property | Value |
|----------|-------|
| Element | `<tbody>` > `<tr>` > `<td>` |
| Row Height | 48px |
| Border Bottom | `1px solid #EEF0F3` |
| Hover Background | `#FAFBFC` |
| Transition | `background 150ms ease` |
| Cell Padding | 12px vertical × 16px horizontal |
| Font | 14px, 400 weight, `#2D3436` |
| Even Row (optional stripe) | `#F8F9FA` |

Action Icons (appear on row hover):
- Use `<button type="button">` for each — never bare `<span>` or `<div>`
- Size: 16px; color `#636E72` → `#0066FF` on hover
- Opacity: 0 → 1 on row hover
- Spacing: 8px between icons
- Each must have `aria-label` (e.g., `aria-label="Edit user"`)

Empty state (no table rows): Render a single `<tr>` spanning all columns with the empty state content centered inside.

#### Responsive Behavior

| Breakpoint | Behavior |
|-----------|----------|
| Desktop 1200px+ | Full table, all columns |
| Tablet 768–1199px | Full table condensed; 8px×12px padding; 13px font |
| Mobile < 768px | Card view per row; "Label: Value" pairs using `<dl>`/`<dt>`/`<dd>`; 16px padding; 13px font |

---

### 6. USER PROFILE

**Goal:** Identity display and data modification.
**Patterns:** Card View (Header/Bio) + Form View (Account Details).

Clear visual separation between **static personal information** (header) and **editable settings** (form).

#### Profile Header Section

| Property | Value |
|----------|-------|
| Element | `<section aria-label="User Profile">` |
| Background | White |
| Border Radius | 6px |
| Padding | 32px |
| Shadow | Elevation 1 |
| Margin Bottom | 32px |
| Layout | 2-column: [Avatar + Info] / [Stats] |
| Avatar Element | `<img>` with descriptive `alt` text |
| Avatar Size | 120px × 120px, circular (50%) |
| Avatar Border | `4px solid #0066FF` |
| Avatar Shadow | Elevation 2 |
| User Name Element | `<h2>` — 28px, 700 weight, `#2D3436` |
| Role/Title Element | `<p>` — 14px, 400 weight, `#636E72` |
| Follow Button | `<button type="button">` outline style, 38px, 120px width |
| Bio Element | `<p>` — 13px, 400 weight, `#636E72`, line-height 1.6 |

Workload Meter (right column):
- Wrap in `<dl>`: `<dt>` for "Workload", `<dd>` for the percentage value
- Label: 12px, 600 weight, all-caps, `#636E72`
- Value: 24px, 700 weight, `#0066FF`
- Bar: `<progress>` element or ARIA-attributed `<div role="progressbar" aria-valuenow="74" aria-valuemin="0" aria-valuemax="100">`
- Bar height: 6px, radius 3px, track `#E0E6ED`, fill `#0066FF`

#### Account Details Form

| Property | Value |
|----------|-------|
| Element | `<form>` with `<fieldset>` grouping |
| Background | White |
| Border Radius | 6px |
| Padding | 32px |
| Shadow | Elevation 1 |
| Margin Top | 32px |
| Grid | 2-column desktop / 1-column mobile |
| Gap | 24px column × 24px row |
| Label Element | `<label for="...">` — 12px, 600 weight, all-caps, letter-spacing 0.5px, `#636E72` |
| Input Height | 38px |
| Textarea Height | 100px min, resize: vertical |

Field layout:
```
Row 1: [First Name]     [Last Name]
Row 2: [Email]          [Password]
Row 3: [Address]        [City]
Row 4: [State (select)] [Zip]
Row 5: [Description (textarea, full width)]
```

Buttons:
- "Update Account": `<button type="submit">` — primary solid
- "Cancel": `<button type="button">` — outline style
- Margin-top: 24px

#### Success Toast Notification

| Property | Value |
|----------|-------|
| Element | `<div role="status" aria-live="polite">` |
| Position | Fixed, top center |
| Top | 20px |
| Z-Index | 500 (toast layer) |
| Min Width | 300px, max 90vw |
| Background | `#E8F8F3` |
| Border | `1px solid #2DCE89` |
| Border Radius | 6px |
| Padding | 16px 20px |
| Shadow | Elevation 4 |
| Animation | slide-down 300ms ease |
| Icon | Checkmark 16px, `#2DCE89`, `aria-hidden="true"` |
| Text | 14px, 400 weight, `#2DCE89` |

---

### 7. ERROR PAGES (500 / 404)

**Goal:** Error recovery. Tone must be empathetic. Single, clear recovery path. No technical jargon.
**Pattern:** Minimalist, vertically and horizontally centered container.

| Property | Value |
|----------|-------|
| Page Element | `<main>` centered with flexbox |
| Page BG | White |
| Container Max Width | 600px |
| Text Align | Center |
| Error Number Element | `<p>` or `<span>` — NOT `<h1>` (decorative, not structural) |
| Error Number Style | 120px, 700 weight, `#95A5A6`, letter-spacing −2px |
| Heading Element | `<h1>` — "Something went wrong!" — 32px, 600 weight, `#2D3436` |
| Message Element | `<p>` — 16px, 400 weight, `#636E72`, line-height 1.6, max-width 400px |
| Recovery Link | `<a href="javascript:history.back()">← Go Back</a>` — 14px, 500 weight, `#0066FF`, underline |
| Support Link (optional) | `<a>` — 12px, `#95A5A6`, margin-top 48px |

> **Element note:** On error pages, the large "500" number is decorative — it should be a `<p>`, not an `<h1>`. The actual `<h1>` is the human-readable heading ("Something went wrong!").

Responsive scaling:

| Breakpoint | Error Number | Heading | Message |
|-----------|-------------|---------|---------|
| Desktop 1200px+ | 120px | 32px | 16px |
| Tablet 768–1199px | 100px | 28px | 15px |
| Mobile < 768px | 80px | 24px | 14px |

Optional: subtle background illustration (200px, 0.1 opacity, `#E0E6ED`) behind error code. Use `aria-hidden="true"` on the illustration element.

---

## VI. IMPLEMENTATION GUIDELINES

### CSS Design Tokens

```css
/* ─── Colors ─── */
--color-primary:          #0066FF;
--color-primary-hover:    #0052CC;
--color-primary-active:   #003D99;
--color-primary-disabled: #CCDDFF;

--color-secondary:        #6C757D;
--color-secondary-hover:  #5A6268;

--color-success:          #2DCE89;
--color-success-hover:    #23B373;
--color-success-light:    #E8F8F3;

--color-danger:           #FF6358;
--color-danger-hover:     #E54D45;
--color-danger-light:     #FFE8E5;

--color-warning:          #FFAB2F;
--color-warning-hover:    #E69A1C;
--color-warning-light:    #FFF5E5;

--color-info:             #2196F3;
--color-info-hover:       #1976D2;
--color-info-light:       #E3F2FD;

--color-text-primary:     #2D3436;
--color-text-secondary:   #636E72;
--color-text-tertiary:    #95A5A6;
--color-text-disabled:    #B0BEC5;

--color-border:           #DFE3E6;
--color-border-row:       #EEF0F3;
--color-bg-light:         #F8F9FA;
--color-bg-off-white:     #FAFBFC;
--color-bg-white:         #FFFFFF;
--color-disabled-bg:      #E0E6ED;

/* ─── Typography ─── */
--font-family:            -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
--font-size-xs:           11px;
--font-size-sm:           12px;
--font-size-base:         14px;
--font-size-md:           16px;
--font-size-lg:           20px;
--font-size-xl:           28px;
--font-size-hero:         36px;

--font-weight-light:      300;
--font-weight-normal:     400;
--font-weight-medium:     500;
--font-weight-semibold:   600;
--font-weight-bold:       700;

/* ─── Spacing ─── */
--spacing-xs:   4px;
--spacing-sm:   8px;
--spacing-md:   12px;
--spacing-lg:   16px;
--spacing-xl:   20px;
--spacing-2xl:  24px;
--spacing-3xl:  32px;
--spacing-4xl:  40px;
--spacing-5xl:  48px;
--spacing-6xl:  64px;

/* ─── Shadows ─── */
--shadow-0: none;
--shadow-1: 0 1px 3px rgba(0,0,0,0.08);
--shadow-2: 0 2px 6px rgba(0,0,0,0.10);
--shadow-3: 0 4px 12px rgba(0,0,0,0.12);
--shadow-4: 0 8px 24px rgba(0,0,0,0.15);

/* ─── Border Radius ─── */
--radius-sm:   4px;
--radius-md:   6px;
--radius-lg:   8px;
--radius-full: 50%;

/* ─── Z-Index ─── */
--z-base:       0;
--z-raised:     10;
--z-dropdown:   100;
--z-sticky:     200;
--z-modal-bg:   300;
--z-modal:      400;
--z-toast:      500;
--z-tooltip:    600;

/* ─── Breakpoints ─── */
--bp-xs: 576px;
--bp-sm: 768px;
--bp-md: 992px;
--bp-lg: 1200px;
```

---

### Component Module API Reference

#### Button

```
variant:      'primary' | 'secondary' | 'success' | 'danger' | 'warning' | 'info' | 'dark' | 'light'
size:         'xs' | 'sm' | 'md' | 'lg'
style:        'solid' | 'outline' | 'link'
state:        'default' | 'hover' | 'active' | 'disabled' | 'loading'
icon:         boolean
iconPosition: 'left' | 'right'
fullWidth:    boolean
htmlType:     'button' | 'submit'  ← always declare explicitly
```

#### Card

```
elevation:   0 | 1 | 2 | 3 | 4
padding:     'xs' | 'sm' | 'md' | 'lg' | 'xl'
radius:      'none' | 'sm' | 'md' | 'lg'
background:  'white' | 'light' | 'dark'
border:      boolean
hover:       boolean
emptyState:  boolean  ← renders empty state content if no children
```

#### Form Input

```
type:         'text' | 'email' | 'password' | 'number' | 'textarea' | 'select' | 'checkbox' | 'radio' | 'toggle' | 'date' | 'range'
size:         'sm' | 'md' | 'lg'
state:        'default' | 'hover' | 'focus' | 'filled' | 'error' | 'success' | 'disabled' | 'loading'
label:        string
placeholder:  string
value:        string
helperText:   string
errorMessage: string
required:     boolean
disabled:     boolean
```

---

### Accessibility Requirements

1. **Color Contrast:** WCAG AA — 4.5:1 for normal text, 3:1 for large text (18px+ or 14px+ bold).
2. **Focus States:** All interactive elements must have a visible focus ring (`2px solid #0066FF`, offset 2px).
3. **Keyboard Navigation:** All functionality must be reachable via keyboard only.
4. **Alt Text:** All `<img>` elements must have descriptive `alt` attributes. Decorative images use `alt=""`.
5. **ARIA Labels:** Form inputs must have `<label for>`. Icon-only buttons must have `aria-label`. Decorative icons use `aria-hidden="true"`.
6. **Semantic HTML:** Per the Semantic HTML Mapping table in Section IV.
7. **Color not as sole indicator:** Status (error/success/warning) must always pair an icon with the color.
8. **Live Regions:** Toast notifications must use `role="status"` and `aria-live="polite"` (or `aria-live="assertive"` for errors).
9. **`<time>` element:** All timestamps must use `<time datetime="ISO-8601-value">` for machine-readable dates.

---

### Animation Standards

| Property | Value |
|----------|-------|
| Default Duration | 200ms – 300ms |
| Easing | `cubic-bezier(0.4, 0, 0.2, 1)` |
| Reduced Motion | Respect `prefers-reduced-motion: reduce` — disable all transitions/animations |
| Skeleton shimmer | 1.5s infinite linear |
| Toast slide-in | 300ms ease |

---

### Cross-Browser Compatibility

| Browser | Minimum Version |
|---------|----------------|
| Chrome / Chromium | 90+ |
| Firefox | 88+ |
| Safari | 14+ |
| Edge | 90+ |
| Mobile Safari | iOS 14+ |
| Chrome Android | Latest |

---

## VII. DO / DON'T QUICK REFERENCE

> This section is a fast-lookup constraint list. When in doubt, check here first.

### Element Usage

| ❌ Don't | ✅ Do Instead |
| :--- | :--- |
| Use `<div>` or `<span>` for clickable actions | Use `<button type="button">` |
| Use `<button>` for navigation to a URL | Use `<a href="...">` |
| Use `<a>` without an `href` | Add `href` or switch to `<button>` |
| Use `<div>` for lists of items | Use `<ul>` or `<ol>` with `<li>` |
| Use styled `<div>` for headings | Use proper `<h2>`, `<h3>`, `<h4>` tags |
| Skip heading levels (H2 → H4) | Follow sequential hierarchy: H2 → H3 → H4 |
| Omit `<label>` on form inputs | Always pair `<label for="id">` with every input |
| Omit `type` on `<button>` elements | Always declare `type="button"` or `type="submit"` |
| Use bare `<div>` for a page region | Use `<main>`, `<nav>`, `<aside>`, `<section>`, `<article>` |
| Render decorative icons without `aria-hidden` | Add `aria-hidden="true"` to all decorative icons |
| Use `<h1>` for a decorative large number (e.g., "500") | Use `<p>` for decorative text; reserve `<h1>` for the actual heading |

### Control Selection

| ❌ Don't | ✅ Do Instead |
| :--- | :--- |
| Use a Toggle for a setting saved on form submit | Use a Checkbox |
| Use a Checkbox for an immediate on/off effect | Use a Toggle Switch |
| Use Radio buttons for 5+ options | Use a Select Dropdown |
| Use a Select Dropdown for 2–4 options | Use Radio buttons |
| Use a Text Input for multi-line content | Use a Textarea |
| Use a Spinner for page or section loading | Use a Skeleton Screen |

### Button Hierarchy

| ❌ Don't | ✅ Do Instead |
| :--- | :--- |
| Place two Primary Solid buttons side-by-side | Make one Outline; reconsider the flow if both are equal |
| Use a Primary button for a destructive action | Use a Danger Solid button |
| Disable a button without explanation | Show a tooltip on the disabled button explaining why |
| Use a Solid button for a low-emphasis action like "Cancel" | Use a Link/Text button or Outline button |

### Notifications & Feedback

| ❌ Don't | ✅ Do Instead |
| :--- | :--- |
| Use a Toast to confirm a destructive action | Use a Confirmation Modal |
| Use a Modal for field validation errors | Use Inline errors below each field |
| Use color alone to indicate error/success | Always pair color with an icon AND text |
| Auto-dismiss a Confirmation Modal | Require explicit button click (Yes / Cancel) |
| Use a Tooltip for required information | Use inline helper text or a Popover |
| Show a generic spinner while content loads | Use a Skeleton Screen |

### Layout & Spacing

| ❌ Don't | ✅ Do Instead |
| :--- | :--- |
| Use 24px as the section margin | Use 32px between sections; 24px is for grid column gaps |
| Mix Elevation levels randomly | Follow the shadow hierarchy: 1 (card) → 2 (hover) → 3 (modal) → 4 (toast) |
| Render an empty list/table/grid with no content | Always implement the Empty State spec |
| Use inline styles for spacing | Use the spacing token scale (--spacing-xs through --spacing-6xl) |

---

*End of Design System V4.0*