# vsUProtect — UniFi Protect API (PHP)

A tiny, batteries-included PHP client for the UniFi Protect **Integration API** — list cameras, fetch snapshots, create RTSP(S) streams, render quick previews (MP4/GIF via `ffmpeg`), and query viewers, liveviews, NVRs, and chimes.

![PHP](https://img.shields.io/badge/PHP-%E2%89%A58.0-777bb4) ![cURL](https://img.shields.io/badge/ext-cURL-blue) ![GD](https://img.shields.io/badge/ext-GD-blue) ![ffmpeg](https://img.shields.io/badge/ffmpeg-required%20for%20previews-black)

---

## Features

- ✅ Straightforward PHP class — no Composer package required  
- 📷 List cameras and get detailed camera info  
- 🔁 Create and read RTSP(S) stream URLs (auto “downgrade” to `rtsp://` if you want)  
- 🖼️ Pull camera snapshots (with optional fit-to-width resizing)  
- 🎞️ Generate **MP4** or **GIF** preview clips from RTSP streams or burst snapshots (via `ffmpeg`)  
- 👀 Query viewers & live views  
- 🎛️ Fetch NVR and chime details  
- 🧹 Safe temp directory handling with automatic cleanup  

---

## Requirements

- **PHP 8.0+** (uses union types & typed declarations)
- PHP extensions: **cURL** (HTTP), **GD** (for snapshot resizing)
- **ffmpeg** CLI in `PATH` (only for `createPreview`)
- A UniFi **Console** (UDM/UDR/UNVR/etc.) running **UniFi Protect** with an **Integration API Key**

---

## Install

Copy the `vsUProtect` class into your project (e.g., `src/vsUProtect.php`) and include it:

```php
require_once __DIR__ . '/src/vsUProtect.php';
```


---

## Quick Start

```php
require 'vsUProtect.php';

$consoleIP = '192.168.1.1';     // or console hostname
$apiKey    = 'YOUR_INTEGRATION_KEY';

$protect = new vsUProtect($consoleIP, $apiKey);

// List cameras
$cams = $protect->getCams();

// First camera snapshot (raw JPEG bytes)
$camID    = $cams[0]['id'] ?? null;
$snapshot = $protect->getCamSnapshot($camID, highQuality: true);

// Save snapshot to disk
file_put_contents('/tmp/cam.jpg', $snapshot);
```


---

## In your UniFi OS Console, create an Integration API Key for Protect.

Ensure your app/server can reach:
https://<console-ip-or-hostname>/proxy/protect/integration/...

If you use a self-signed certificate, see Security Notes below.

---

## Usage Examples

The class exposes a single helper callAPI() internally and public methods grouped by resource. All JSON responses are returned as associative arrays; image/video content is returned as binary strings.

### Console
```php
$meta = $protect->getMeta(); // GET v1/meta/info
```

### Cameras
```php
$all = $protect->getCams();        // GET v1/cameras
$one = $protect->getCam($camID);   // GET v1/cameras/{id}
```

### Streams
Create/read RTSP(S) stream URLs.
By default, secure (rtsps://, SRTP, port 7441). Set $secure=false to auto-convert to insecure (rtsp://, port 7447, query removed).
```php
// Read existing streams
$streams = $protect->getCamStreams($camID, secure: true);   // ['low'|'medium'|'high' => url]

// Create streams for specific qualities
$created = $protect->createCamStream($camID, quality: ['low','medium'], secure: false);
```

### Snapshots
Fetch a snapshot; optionally fit to a target width (no upscaling). When fitToWidth > 0, GD resizes and re-encodes as JPEG (quality 85).
```php
// Full quality bytes
$bytes = $protect->getCamSnapshot($camID, highQuality: true);

// Resize to 1280px wide
$bytes = $protect->getCamSnapshot($camID, highQuality: true, fitToWidth: 1280);
file_put_contents('/tmp/snap.jpg', $bytes);
```

### Preview Clips (MP4/GIF)
Create short preview clips from either the RTSP stream (preferred) or a burst of snapshots. Requires ffmpeg in PATH.
```php
// Returns binary MP4 bytes (5s @ 1fps, scaled to 960px wide)
$mp4 = $protect->createPreview(
    camID: $camID,
    highQuality: true,
    fitToWidth: 960,
    fps: 1,
    dur: 5,
    format: 'mp4',
    audio: false
);
file_put_contents('/tmp/preview.mp4', $mp4);

// Save directly to disk (GIF)
$ok = $protect->createPreview(
    camID: $camID,
    highQuality: false,
    fitToWidth: 640,
    fps: 2,
    dur: 4,
    format: 'gif',
    saveTo: '/tmp/preview.gif'
);
```

----

## Return Types & Errors
JSON endpoints → associative array

Binary endpoints (snapshots, previews) → string (raw bytes) or bool when saving to a path

Network/ffmpeg errors throw a RuntimeException or return an error array from callAPI:
```json
[
  'status' => 'error',
  'msg'    => 'cURL error message...'
]
```

### Troubleshooting
401 / 403 – Verify your Integration API Key and its permissions; confirm the Protect Integration API is available on your console version.

cURL error – Check network reachability to https://<console>/proxy/protect/integration/...

Self-signed cert – Either disable verification (default) or install/trust the console cert (recommended).

ffmpeg not found – Install ffmpeg and ensure it’s on PATH (ffmpeg -version should succeed).

Empty streams – Some cameras/qualities may not have an RTSP profile until created; call createCamStream() first.

