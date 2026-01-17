Perfect — noted 👍
`koopo-buddyboss-media-ux/README.md`

---

## Koopo BuddyBoss Media UX

**Koopo BuddyBoss Media UX** is a custom plugin designed to modernize and extend the media experience in **BuddyBoss Platform**, focusing on:

* seamless avatar & cover photo updates
* modern, modal-based UX (no page redirects)
* deep integration with BuddyBoss Media (Photos & Albums)
* performance, optimization, and offloading
* moderation and administrative control

This plugin is built to be **upgrade-safe**, **BuddyBoss-native**, and extensible for future marketplace and social features on the Koopo platform.

---

## What This Plugin Does

* Replaces BuddyBoss avatar/cover uploads with an in‑modal flow (no redirects)
* Lets users set avatar/cover from existing BuddyBoss photos
* Adds media optimization options (resize cap, quality, WebP/AVIF, EXIF stripping)
* Integrates Real Media Library (RML) folder mapping
* Provides WebP backfill for previously uploaded images
* Adds upload‑source metadata visible in Media Library and attachment details

---

## Configuration (Admin)

Open **Settings → Koopo Media Offload** and configure each section:

1. **Settings**
   * Enable Offload URLs (optional)
   * Choose scope: BuddyBoss only or all uploads
   * Define folder templates per post type/media type

2. **Provider**
   * Set provider and base URL (for URL rewriting only)
   * **Bunny setup (Storage + CDN)**
     * Create a **Storage Zone** in Bunny.
     * Generate a **Storage API Key** for that zone.
     * Create a **Pull Zone** that points to the Storage Zone.
     * Use the **Pull Zone URL** as the plugin’s Base URL.
     * Use the **Storage Zone name** + **API Key** in the Provider section.
     * (Optional) set the Storage Endpoint if Bunny gave you a regional endpoint.
   * Screenshot placeholders (to add later):
     * Storage Zone settings screen
     * API key screen
     * Pull Zone URL screen

3. **Media Library (RML)**
   * Enable RML mapping
   * Set folder map per context/media type
   * Optional: run RML backfill

4. **Delete Local**
   * Configure deletion policy by context/media type
   * Allowed extensions list per media type

5. **Optimization**
   * Max dimension cap
   * Allowed image sizes (toggle registered sizes)
   * JPEG/WebP quality
   * EXIF stripping
   * WebP/AVIF generation
   * Keep original (off removes full‑size originals after a scaled version exists)
   * WebP backfill for existing media

---

## 🎯 Core Goals

* Eliminate disruptive page redirects for avatar/cover updates
* Provide a modern, intuitive media workflow similar to native social apps
* Reuse BuddyBoss/BuddyPress internals (no fragile hacks)
* Support performance scaling (optimization + offload)
* Enable governance (moderation, abuse prevention)
* Prepare for richer media capture and creation workflows

---

## ✅ Completed Phases (So Far)

### **Phase 1 — Modal Avatar & Cover Editing (Core UX)**

**Status: Complete**

* Replaced BuddyBoss redirect-based avatar/cover editing with a modal
* Direct AJAX integration with:

  * `bp_avatar_upload`
  * `bp_avatar_set`
  * `bp_cover_image_upload`
* Avatar cropping handled inside modal
* Cover photo upload handled without cropping
* Cache-safe DOM updates (no full reload required)
* Fixed BuddyBoss Nouveau cover layering issues (removed background-image seams)

**Key commits**

* Commit 001 → Core modal + upload + crop
* Commit 002 → Polish + no reload
* Commit 002C → Final cover seam fix (single image layer)

---

### **Phase 2 — Use Existing Photos as Avatar / Cover**

**Status: Complete (initial scope)**

* Added actions to BuddyBoss Photos grid:

  * **Set as Profile Photo**
  * **Set as Cover Photo**
* Avatar-from-photo opens directly into crop modal
* Cover-from-photo sets immediately
* Strict permission checks:

  * users can only use their own photos
* Uses BuddyBoss Media → Attachment → BuddyPress upload pipeline (safe + native)

**Key commit**

* Commit 003

---

## 🚧 Planned Phases (Roadmap)

### **Phase 3 — Camera Capture (Desktop + Mobile)**

**Status: Planned (not yet implemented)**

#### Feature

Allow users to **take a photo directly from their device camera**, even on desktop (e.g. MacBook Pro), not just mobile.

#### UX

When updating avatar or cover:

* Upload from device
* **Take a picture** (if camera available)
* Choose from my Photos (future phase)

#### Technical approach

* Use `getUserMedia()` (WebRTC MediaDevices API)
* Detect camera availability:

  * desktop webcams (MacBook, Windows laptops)
  * mobile cameras
* Modal flow:

  1. User clicks “Take a picture”
  2. Camera preview opens in modal
  3. Capture frame → convert to Blob
  4. Send Blob through existing BuddyBoss upload pipeline
* Graceful fallback:

  * If camera permission denied or unavailable, hide option

#### Notes

* Works in modern browsers (Chrome, Safari, Edge)
* Requires HTTPS (already standard for production)
* No third-party services required

---

### **Phase 4 — “Choose from My Photos” Inside Modal**

**Status: Planned**

* Add a tabbed interface inside the avatar/cover modal:

  * Upload
  * Take a picture
  * Choose from my Photos
* Reuse logic from Phase 2 but embedded in the modal UX
* Lazy-load photo grid for performance

---

### **Phase 5 — Image Optimization Pipeline**

**Status: In Progress**

* Automatic image optimization on upload:

  * Strip EXIF metadata (privacy)
  * Resize oversized images
  * Compress intelligently (quality settings)
  * Generate WebP (and AVIF where supported)
* Optional background processing via Action Scheduler (if available)
* Admin settings:

  * quality
  * max dimensions
  * keep original or not
  * allowed sizes
  * WebP/AVIF toggles

---

### **Phase 6 — Media Offloading (Performance & Scale)**

**Status: In Progress**

* Admin offload settings UI (provider selection, base URL, scoping)
* Folder templates per post type and media type
* Real Media Library (RML) mapping with context-based routing
* Deletion policy controls (by context/media type/extension) — gated by offload adapter
* Offload adapter hooks (upload + post-upload)

**Remaining**

* Provider adapters (Bunny.net, S3-compatible, Google Drive, OneDrive)
* Background offload queue + retry
* Optional “delete local after offload” toggle validation in adapter
* Media library backfill/migration tools

  * S3-compatible (AWS S3, Wasabi, Backblaze, DO Spaces)
  * OneDrive (later phase)
* CDN-friendly delivery
* Compatible with existing WP offload plugins or custom adapter
* Preserve BuddyBoss privacy rules

---

### **Phase 7 — Admin Media Moderation & Governance**

**Status: Planned**

* Admin dashboard for user media:

  * filter by user, date, type
  * thumbnail preview
* Actions:

  * delete
  * quarantine (hide without deleting)
  * mark reviewed
* Audit log (who did what, when)
* Optional user upload restrictions

---

### **Phase 8 — Safety & Abuse Prevention**

**Status: Planned**

* File-type hardening
* Optional virus scanning
* Optional content reporting by users
* Future: automated moderation hooks

---

## 🧱 Architecture Principles

* **Custom plugin only** (no BuddyBoss core edits)
* Minimal child-theme involvement
* Server-side permission validation for all actions
* Progressive enhancement (features appear only if supported)
* Cache-aware (LiteSpeed / aggressive caching safe)

---

## 🧠 Notes for Future Contributors

* Avatar and cover workflows must always route through BuddyPress attachment APIs
* Never rely on embedding BuddyBoss pages (iframe/fetch approach intentionally abandoned)
* DOM updates must account for BuddyBoss Nouveau’s layered cover implementation
* Media actions should be injected defensively (AJAX + MutationObserver)
