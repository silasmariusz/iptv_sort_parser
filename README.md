# IPTV Sort Parser

Standalone IPTV playlist sorter with local logo mapping.

## What this project does

- Keeps unknown/unclassified channels at the end.
- Supports:
  - offline conversion (`sort_m3u.py` -> `output.m3u`)
  - on-the-fly HTTP proxy sorting (`sort_proxy.php?url=...`)

## Files

- `input.m3u` - source playlist
- `output.m3u` - generated sorted playlist.
- `channel_order.txt` - standalone sorting order used by both converters.
- `sort_m3u.py` - Python converter (file-to-file).
- `sort_proxy.php` - PHP converter (URL input, M3U output).

## Requirements

- Python 3.10+ (for Python scripts)
- PHP runtime (only if you want to run `sort_proxy.php`)

## Usage

### 1) Generate sorted output playlist (Python)

```bash
python sort_m3u.py --input input.m3u --output output.m3u --icons-source input.m3u
```

### 2) Run on-the-fly sorter (PHP proxy)

Open in browser (form mode):

```text
http://your-host/path/sort_proxy.php
```

Call:

```text
http://your-host/path/sort_proxy.php?url=http://iptv_host/get.php?username=...&password=...&type=m3u&output=ts
```

`sort_proxy.php` returns M3U content sorted by `channel_order.txt`.

For local logos, it converts `./logo.png` into absolute URLs based on the proxy location, for example:

```text
http://your-host/path/logo.png
```

## Notes

- If `channel_order.txt` is missing or empty, converters return an error.
- Unclassified channels are appended at the end.
- The proxy UI supports: Download, Preview M3U, and Channels View.
