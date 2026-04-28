# Comprehensive Code Commenting Plan

**Date:** April 28, 2026  
**Status:** Planning Phase  
**Scope:** 90 PHP files, ~16,143 lines of code

---

## Executive Summary

This plan outlines a systematic approach to adding detailed, professional comments throughout the entire application codebase. The goal is to make the codebase self-documenting, maintainable, and accessible to new developers.

---

## Codebase Structure Analysis

### Total Statistics
- **Total PHP Files:** 90
- **Total Lines of Code:** ~16,143
- **Main Directories:** 7 (Controllers, DeepDive, Helpers, Middleware, Models, Parsers, Services)
- **Key Subsystem:** DeepDive (40+ files)

### Directory Breakdown with File Counts

```
Root Level (2 files)
├── AppBootstrap.php          - Application bootstrap & routing
├── Database.php              - Database connection management

Controllers (5 files)
├── ProjectController.php      - Project CRUD operations
├── ScanController.php         - AI scan management
├── FileController.php         - File upload/management
├── ReportController.php       - Report generation
└── UtilityController.php      - Utility endpoints

DeepDive Subsystem (40+ files) - NEW BETA FEATURE
├── Controllers/
│   └── JobController.php      - DeepDive job lifecycle
├── Hardware/
│   ├── HardwareSpecExtractor.php  - Hardware detection & extraction
│   └── HardwareSpec.php           - Hardware data model
├── Parsers/ (4 files)
│   ├── *.php                  - DSM config/log parsers
├── Rules/ (11 files)
│   ├── Matchers/              - Pattern matching logic
│   └── Sources/               - Rule source implementations
├── Pipeline/ (12 files)
│   ├── DetectionPipeline.php  - Multi-stage analysis
│   ├── Stages/                - Individual pipeline stages
├── Report/ (2 files)
│   ├── ReportRenderer.php     - HTML report generation
│   └── ReportLayout.php       - Report structure
├── Visualization/
│   └── BayLayoutRenderer.php  - SVG drive bay diagrams
├── Correlation/ (3 files)
│   ├── ChainCorrelator.php    - Cause-effect analysis
│   └── *.php                  - Correlation logic
├── Narrator/ (2 files)
│   └── *.php                  - Executive summary generation
├── Services/ (2 files)
│   ├── JobRepository.php      - Job persistence
│   └── *.php
├── Worker/
│   └── JobWorker.php          - Background job execution
└── Support/
    ├── Paths.php              - Path management
    └── *.php

Helpers (3 files)
├── ArrayHelper.php            - Array utilities
├── FileHelper.php             - File operations
└── StringHelper.php           - String manipulation

Middleware (4 files)
├── AuthMiddleware.php         - Authentication
├── CsrfMiddleware.php         - CSRF protection
├── ViewDataMiddleware.php     - View data injection
└── *.php

Models (18 files)
├── Project.php, File.php, etc - Data models

Parsers (18 files)
├── Synology DSM parsers       - DSM config parsing
└── Log parsers

Services (9 files)
├── Database service           - DB operations
├── Auth service               - Authentication
└── Various utilities
```

---

## Commenting Strategy & Standards

### Commenting Levels

#### Level 1: Class & File Headers
- **Scope:** Every file
- **Content:** 
  - File purpose in 1-2 sentences
  - Key responsibilities
  - Related classes/dependencies
  - Author/Date created (where applicable)

```php
/**
 * ProjectController: Project Management
 * 
 * Handles CRUD operations for forensic projects including:
 * - Project creation and configuration
 * - File management and association
 * - Scan job coordination
 * 
 * @uses ProjectModel, FileController, ScanController
 * @see templates/tenant/project_view.twig
 */
```

#### Level 2: Method Documentation
- **Scope:** All public methods, important private methods
- **Content:**
  - Purpose statement
  - Parameter descriptions (types, ranges, constraints)
  - Return value documentation
  - Throws/exceptions
  - Usage examples (for complex methods)

```php
/**
 * Store uploaded debug file and initiate extraction
 * 
 * Validates file:
 * - Max 2GB size
 * - .dat extension (Synology debug bundles)
 * - Unique per project-serial combo
 * 
 * @param ServerRequestInterface $req HTTP request with uploaded file
 * @param int $projectId Parent project UUID
 * @return array ['success' => bool, 'file_id' => string, 'message' => string]
 * @throws InvalidFileException If validation fails
 * @throws StorageException If disk write fails
 */
public function storeUpload(ServerRequestInterface $req, int $projectId): array
```

#### Level 3: Complex Logic Blocks
- **Scope:** Algorithm sections, business logic, non-obvious code
- **Content:**
  - Why this approach (not just what)
  - Key decision points
  - State transitions
  - Edge cases being handled

```php
// Fallback: synoinfo.conf parsing for bay capacity
// ===============================================
// We use synoinfo.conf as a last resort because:
// 1) load_info.result may not exist on older DSM versions
// 2) synostorage/ disk dirs may not be populated yet
// Only count actual slot definitions (slot0_type, slot1_type, etc)
// to get true bay count, not theoretical maximum
```

#### Level 4: Inline Comments
- **Scope:** Tricky one-liners, non-obvious operations
- **Content:** Why this specific approach

```php
// Use actual bay count, not max_bay_count (which is device capacity spec)
$actualBayCount = $loadInfo['internal_slot_count'] ?? 0;

// Skip DSM 6.0 epoch - timestamp '1970/01/01 05:30:00' indicates no real data
if ($time !== '1970/01/01 05:30:00') {
    $history['timeline'][] = ['time' => $time, 'serial' => $serial];
}
```

### Comment Style Guidelines

```php
// USE THIS STYLE FOR COMMENTS:
// ✓ Clear, direct language
// ✓ Complete sentences
// ✓ Explain WHY, not just WHAT
// ✓ Avoid redundant comments (don't repeat code)

// DON'T DO THIS:
// // increment counter
// $count++;  // Bad: comment just restates code

// DO THIS INSTEAD:
// Move to next unprocessed file in batch
$count++;
```

### JSDoc Format for Complex Methods

```php
/**
 * Extract SMART health data from diskprediction snapshots
 * 
 * Analyzes SMART attribute 5 (Reallocated Sectors) to assess drive health
 * across multiple daily snapshots, detecting trends:
 * - Stable: 0-5 sectors (healthy)
 * - Growing: 6-50 sectors (monitor)  
 * - Deteriorating: 50+ sectors (replace soon)
 * 
 * @param string $diskSerial       Drive serial number to look up
 * @param string $extractedPath    Path to extracted debug bundle
 * @return array{
 *     bad_sectors: int,          Current bad sector count
 *     growth_rate: float,        Sectors/day growth (0.0-10.0)
 *     health_status: string,     'healthy'|'caution'|'warning'|'critical'
 *     snapshots: int,            Number of data points analyzed
 *     oldest_snapshot: string,   ISO 8601 date of oldest SMART record
 * }|null
 * @throws Exception If diskprediction data cannot be parsed
 */
public function extractSmartHealthData(string $diskSerial): ?array
```

---

## Priority Tiers

### TIER 1: Critical Path (High Impact, High Complexity)
**Estimated:** 40-50 files, 40-50 hours
**Target:** Weeks 1-2

These are the files that developers interact with most and that are most complex:

1. **Core Infrastructure** (5 files, ~500 lines)
   - AppBootstrap.php
   - Database.php
   - Key Middleware files

2. **Main Controllers** (3 files, ~800 lines)
   - ProjectController.php
   - ScanController.php
   - FileController.php

3. **DeepDive Hardware Extraction** (2 files, ~1400 lines)
   - HardwareSpecExtractor.php (critical, 700 lines)
   - HardwareSpec.php (data model)

4. **DeepDive Pipeline Core** (6 files, ~1200 lines)
   - DetectionPipeline.php
   - Key stage files
   - JobController.php

5. **Report Generation** (2 files, ~1000 lines)
   - ReportRenderer.php (critical, 650 lines)
   - BayLayoutRenderer.php (SVG rendering)

### TIER 2: High-Value Systems (Medium Impact, Medium Complexity)
**Estimated:** 25-30 files, 30-40 hours
**Target:** Weeks 2-3

1. **DeepDive Rules** (11 files, ~1400 lines)
   - Rule matchers
   - Rule sources
   - Rule framework

2. **Parsers** (18 files, ~1800 lines)
   - DSM configuration parsers
   - Log parsers
   - Utility parsers

3. **Services & Models** (27 files, ~2000 lines)
   - Database services
   - Data models
   - Utility services

### TIER 3: Supporting Systems (Lower Impact, Lower Complexity)
**Estimated:** 20-25 files, 15-20 hours
**Target:** Week 4

1. **Helper Classes** (3 files, ~200 lines)
2. **Remaining Utilities** (5-10 files, ~300 lines)
3. **Minor Parsers** (10-15 files, ~500 lines)

---

## File-by-File Breakdown with Priorities

### TIER 1 - CRITICAL FILES

#### Core Infrastructure
| File | Lines | Priority | Complexity | Est. Time |
|------|-------|----------|-----------|-----------|
| AppBootstrap.php | 400 | P1 | High | 2h |
| Database.php | 80 | P1 | Medium | 0.5h |
| Middleware/AuthMiddleware.php | 120 | P1 | High | 1h |
| Middleware/CsrfMiddleware.php | 100 | P1 | High | 1h |
| Middleware/ViewDataMiddleware.php | 150 | P1 | Medium | 1h |

#### Main Controllers
| File | Lines | Priority | Complexity | Est. Time |
|------|-------|----------|-----------|-----------|
| Controllers/ProjectController.php | 350 | P1 | High | 2h |
| Controllers/ScanController.php | 280 | P1 | High | 1.5h |
| Controllers/FileController.php | 220 | P1 | High | 1.5h |
| Controllers/ReportController.php | 150 | P1 | Medium | 1h |
| Controllers/UtilityController.php | 100 | P2 | Low | 0.5h |

#### DeepDive Core
| File | Lines | Priority | Complexity | Est. Time |
|------|-------|----------|-----------|-----------|
| DeepDive/Hardware/HardwareSpecExtractor.php | 700 | P1 | Very High | 4h |
| DeepDive/Hardware/HardwareSpec.php | 250 | P1 | Medium | 1h |
| DeepDive/Controllers/JobController.php | 280 | P1 | High | 1.5h |
| DeepDive/Report/ReportRenderer.php | 800 | P1 | Very High | 4h |
| DeepDive/Visualization/BayLayoutRenderer.php | 300 | P1 | High | 1.5h |

#### DeepDive Pipeline
| File | Lines | Priority | Complexity | Est. Time |
|------|-------|----------|-----------|-----------|
| DeepDive/Pipeline/DetectionPipeline.php | 200 | P1 | High | 1.5h |
| DeepDive/Pipeline/Stage/*.php | 800 | P1 | High | 3h |
| DeepDive/Parsers/*.php | 600 | P1 | High | 2.5h |

**Tier 1 Total:** ~45 files, ~7,500 lines, **~40-45 hours**

---

### TIER 2 - HIGH-VALUE FILES

#### DeepDive Rules
| File | Lines | Priority | Complexity | Est. Time |
|------|-------|----------|-----------|-----------|
| DeepDive/Rules/*.php | 600 | P2 | High | 2.5h |
| DeepDive/Rules/Matchers/*.php | 500 | P2 | High | 2h |
| DeepDive/Rules/Sources/*.php | 400 | P2 | Medium | 1.5h |

#### DeepDive Analysis
| File | Lines | Priority | Complexity | Est. Time |
|------|-------|----------|-----------|-----------|
| DeepDive/Correlation/*.php | 400 | P2 | High | 2h |
| DeepDive/Narrator/*.php | 250 | P2 | High | 1.5h |

#### Services
| File | Lines | Priority | Complexity | Est. Time |
|------|-------|----------|-----------|-----------|
| Services/*.php | 900 | P2 | Medium | 3h |
| DeepDive/Services/*.php | 200 | P2 | Medium | 1h |

#### Models
| File | Lines | Priority | Complexity | Est. Time |
|------|-------|----------|-----------|-----------|
| Models/*.php | 1200 | P2 | Low-Medium | 3h |

#### Parsers (Main)
| File | Lines | Priority | Complexity | Est. Time |
|------|-------|----------|-----------|-----------|
| Parsers/DsmConfig*.php (8 files) | 1000 | P2 | High | 3.5h |
| Parsers/LogParser*.php (10 files) | 800 | P2 | Medium | 2.5h |

**Tier 2 Total:** ~30 files, ~6,000 lines, **~30-35 hours**

---

### TIER 3 - SUPPORTING FILES

#### Helpers
| File | Lines | Priority | Complexity | Est. Time |
|------|-------|----------|-----------|-----------|
| Helpers/ArrayHelper.php | 150 | P3 | Low | 0.5h |
| Helpers/FileHelper.php | 120 | P3 | Low | 0.5h |
| Helpers/StringHelper.php | 100 | P3 | Low | 0.5h |

#### Utilities
| File | Lines | Priority | Complexity | Est. Time |
|------|-------|----------|-----------|-----------|
| DeepDive/Support/*.php | 200 | P3 | Low | 0.5h |
| DeepDive/Worker/JobWorker.php | 150 | P3 | Medium | 1h |
| DeepDive/Models/*.php | 100 | P3 | Low | 0.5h |

**Tier 3 Total:** ~15 files, ~1,500 lines, **~10-12 hours**

---

## Implementation Phases

### Phase 1: Planning & Setup (Week 1, Days 1-2)
- [x] Analyze codebase structure
- [x] Create comprehensive plan (THIS DOCUMENT)
- [ ] Define company-specific comment conventions
- [ ] Set up commenting checklist template
- [ ] Review and approve plan with team

**Deliverable:** Approved commenting standards document

### Phase 2: Critical Path - Core & Controllers (Week 1-2, 10-12 hours/day)
**Target:** Monday-Wednesday

1. **Day 1-2: Core Infrastructure**
   - AppBootstrap.php
   - Database.php
   - Middleware files
   - Time: 5-6 hours

2. **Day 2-3: Main Controllers**
   - ProjectController.php
   - ScanController.php
   - FileController.php
   - ReportController.php
   - Time: 6-7 hours

3. **Day 3-4: DeepDive Core**
   - HardwareSpecExtractor.php (largest, most complex)
   - HardwareSpec.php
   - JobController.php
   - Time: 7-8 hours

**Deliverable:** Core files fully commented, peer review complete

### Phase 3: Critical Path - Pipeline & Report (Week 2, 10-12 hours/day)
**Target:** Thursday-Friday + Monday-Tuesday

1. **Day 5-6: Report Generation**
   - ReportRenderer.php (detailed, complex)
   - BayLayoutRenderer.php
   - Time: 6-7 hours

2. **Day 6-7: Pipeline**
   - DetectionPipeline.php
   - Pipeline stages
   - Time: 5-6 hours

3. **Day 7-8: Parsers & Rules**
   - DSM parsers
   - Initial rule files
   - Time: 6-7 hours

**Deliverable:** Critical pipeline fully documented

### Phase 4: High-Value Systems (Week 3, 8-10 hours/day)
**Target:** Tuesday-Thursday

1. **Day 9-10: Rules & Correlation**
   - Complete rules system
   - Correlation logic
   - Time: 5-6 hours

2. **Day 10-11: Remaining Parsers & Services**
   - Complete parser coverage
   - Service layer
   - Time: 5-6 hours

3. **Day 11-12: Analysis & Narrative**
   - Narrator system
   - Analysis modules
   - Time: 4-5 hours

**Deliverable:** All high-value systems commented

### Phase 5: Supporting Systems (Week 3-4, 6-8 hours/day)
**Target:** Friday + next week

1. **Helpers & Utilities**
   - Helper classes
   - Utility modules
   - Time: 2-3 hours

2. **Models & Remaining**
   - Data models
   - Miscellaneous files
   - Time: 3-4 hours

**Deliverable:** Complete codebase documented

### Phase 6: Review & Refinement (Week 4)
1. Automated style checking
2. Peer review of comments
3. Fix inconsistencies
4. Create developer guide based on comments

**Deliverable:** Production-ready, fully-commented codebase

---

## Estimated Total Effort

| Phase | Files | Hours | Duration |
|-------|-------|-------|----------|
| Tier 1: Critical Path | 45 | 40-45 | 4-5 days |
| Tier 2: High-Value | 30 | 30-35 | 3-4 days |
| Tier 3: Supporting | 15 | 10-12 | 1-2 days |
| Review & QA | - | 8-10 | 1 day |
| **TOTAL** | **90** | **88-102 hours** | **9-12 business days** |

**Assuming 8-10 hours/day for focused commenting work:**
- **Best case:** 9 days (all features, multiple developers)
- **Expected case:** 11-12 days (single developer, full coverage)
- **Conservative case:** 14-15 days (with extensive code review)

---

## Tools & Automation

### Style Checking
- PHP CodeSniffer with custom ruleset
- Psalm/Phan for type documentation validation
- IDE plugins (VS Code PHP intelephense, PHPStorm)

### Documentation Generation
- PHPDocumentor for auto-generated HTML docs
- mkdocs for narrative documentation

### Process Automation
```bash
# Validate comments after each phase
./vendor/bin/phpcs src/ --standard=PSR12

# Generate documentation
./vendor/bin/phpdoc run -d src -t docs/generated

# Check for missing docblocks
./vendor/bin/phpstan analyse src --level=5
```

---

## Quality Checklist

For each file, verify:

- [ ] File header with purpose and key responsibilities
- [ ] All public methods documented (JSDoc format)
- [ ] All parameters documented with types
- [ ] Return values documented
- [ ] Exceptions/throws documented
- [ ] Complex logic blocks explained
- [ ] Edge cases commented
- [ ] No redundant comments
- [ ] Consistent terminology used
- [ ] Examples provided for complex methods
- [ ] Cross-references to related classes
- [ ] No TODO/FIXME comments without context
- [ ] Formatting follows PSR-5 standards

---

## Post-Implementation

### Developer Onboarding
- Create "Code Reading Guide" from comments
- Set up IDE shortcuts to jump to documented methods
- Generate API reference from doc blocks

### Maintenance
- Update comments when code changes
- Quarterly review for accuracy
- Archive commenting patterns as company standards

### Metrics
- % of files with documentation (target: 100%)
- % of public methods documented (target: 100%)
- Comment-to-code ratio (target: 25-35%)
- Average comment clarity score (code review)

---

## Next Steps

1. **Approve this plan** with stakeholders
2. **Assign resources** - suggest 1 senior developer for 2 weeks, or 2 developers for 1 week
3. **Set up environment** - install PHP doc tools, configure IDE
4. **Begin Phase 1** with core infrastructure files
5. **Weekly checkpoint** meetings to track progress and adjust

---

## Questions & Clarifications

Before beginning, confirm:

1. **Comment Style:** Approve JSDoc format and inline comment style above
2. **Level of Detail:** Is the "Level 1-4 commenting" approach appropriate?
3. **Code Examples:** Should we include inline code examples in comments?
4. **Language:** Professional/technical tone vs. conversational?
5. **Audience:** Assume comment reader is a mid-level PHP developer?
6. **Timeline:** Can we allocate 11-12 days for this effort?
7. **Review Process:** Who will review comments for accuracy & clarity?

