# DeepDive Directory Tools

Tools to inspect file inventory and directory structure of DeepDive installation.

## Web Browser Access

### Directory Viewer (Recommended)

Open `directory-viewer.html` in a web browser to get an interactive directory browser with:

- **Files List** - Table view with file sizes, types, and modification dates
- **Tree View** - Hierarchical directory structure grouped by file type
- **JSON View** - Raw JSON data for API integration

**URL**: `http://localhost/tools/directory-viewer.html`

**Features**:
- Browse any directory on the system
- View total file counts and sizes
- Filter by file extension
- Sort and search files
- Export data as JSON

## API Access

### Directory Browser API

Returns directory listing as JSON.

**URL**: `/tools/directory-browser.php?path=/path/to/directory`

**Example**:
```
GET /tools/directory-browser.php?path=/sessions/brave-vigilant-cannon/mnt/ai-debugscan3
```

**Response**:
```json
{
  "path": "/sessions/brave-vigilant-cannon/mnt/ai-debugscan3",
  "timestamp": "2026-04-23 12:34:56",
  "directories": [...],
  "files": [...],
  "stats": {
    "total_size_bytes": 12345678,
    "total_size_human": "12.3 MB",
    "total_files": 156,
    "total_dirs": 28
  }
}
```

## Command Line

### Directory Listing Script

Run from command line to generate directory reports in multiple formats.

**Location**: `bin/list-directory.php`

**Usage**:
```bash
php bin/list-directory.php [path] [--json] [--tree]
```

**Examples**:

List entire project:
```bash
php bin/list-directory.php
```

List specific directory:
```bash
php bin/list-directory.php sample
php bin/list-directory.php /sessions/brave-vigilant-cannon/mnt/ai-debugscan3
```

Output as JSON:
```bash
php bin/list-directory.php --json
```

Tree view only:
```bash
php bin/list-directory.php --tree
```

**Output Formats**:

**Human-readable** (default):
```
┌─ Directory Listing Report
│
├─ Path:           /path/to/directory
├─ Timestamp:      2026-04-23 12:34:56
│
├─ Statistics:
│  ├─ Total Files:        156
│  ├─ Total Directories:  28
│  └─ Total Size:         12.3 MB
│
├─ Files by Extension:
│  ├─ .php (45 files, 2.5 MB)
│  ├─ .js (32 files, 1.8 MB)
│  ├─ .dat (12 files, 8.0 MB)
│  └─ .json (67 files, 50 KB)
│
└─ Directory Tree:
   📁 Directories:
      📂 src
      📂 public
      📂 bin
   
   📄 Files:
      📄 README.md                             15 KB
      📄 composer.json                         2.3 KB
      📄 config.php                            8.5 KB
```

**JSON format** (`--json` flag):
```json
{
  "path": "/path/to/directory",
  "timestamp": "2026-04-23 12:34:56",
  "directories": [
    {
      "path": "src",
      "name": "src"
    }
  ],
  "files": [
    {
      "path": "README.md",
      "name": "README.md",
      "size_bytes": 15360,
      "size_human": "15 KB",
      "extension": "md",
      "modified": "2026-04-23 10:00:00"
    }
  ],
  "stats": {
    "total_size_bytes": 12345678,
    "total_size_human": "12.3 MB",
    "total_files": 156,
    "total_dirs": 28
  }
}
```

## Share Directory Listing

To share a directory listing with your developer:

### Method 1: Copy JSON Output
```bash
php bin/list-directory.php --json | pbcopy  # macOS
php bin/list-directory.php --json | xclip   # Linux
```

### Method 2: Save to File
```bash
php bin/list-directory.php --json > directory-listing.json
php bin/list-directory.php > directory-listing.txt
```

### Method 3: Browser Export
In `directory-viewer.html`, switch to JSON tab and copy the output.

## Information Provided

Each file listing includes:

- **Path** - Full relative path from base directory
- **Size** - File size in human-readable format (B, KB, MB, GB)
- **Extension** - File type
- **Modified** - Last modification timestamp
- **Statistics** - Total counts and sizes

## Exclude Patterns

The tools automatically exclude:

- Hidden files and directories (starting with `.`)
- Version control directories (`.git`)
- Dependency directories (`vendor`, `node_modules`)
- IDE configuration (`.vscode`)

## Use Cases

1. **Verify Uploads** - Check that DeepDive debug bundles have been extracted properly
2. **Monitor Disk Usage** - Track total size of extracted data
3. **Inventory Files** - Know what data is available for processing
4. **Troubleshooting** - Share directory structure with support/developers
5. **API Integration** - Use JSON API for automated directory monitoring

## Troubleshooting

**Path not found**: Ensure the path exists and is readable by the web server or PHP process.

**Permission denied**: Check file permissions. The web server user may need read access.

**No results**: Very large directories may take a moment to scan. Exclude unwanted directories by editing the filter patterns.
