# Blender for DAM 3D previews (install standard)

This document is the **single install standard** for the Jackpot **DAM 3D** headless poster pipeline (`GenerateThumbnailsJob` → `resources/blender/render_model_preview.py`). It applies to **local Linux / Sail-style dev hosts**, **staging workers**, and **production workers**.

## Phase 6J — Laravel Sail (Docker image)

The **Sail PHP image** built from **`docker/8.5/Dockerfile`** is shared by **`laravel.test`**, **`queue`**, and **`queue_video_heavy`** in **`compose.yaml`** — the same filesystem that runs `php artisan queue:work` therefore includes Blender.

- **Install location:** `/opt/blender/4.5.3/` (official **Blender 4.5.3** linux-x64 tarball) and symlink **`/usr/local/bin/blender`**.
- **Rebuild after Dockerfile changes** (required once, or whenever the Dockerfile changes):

```bash
./vendor/bin/sail build --no-cache
./vendor/bin/sail up -d
```

- **Verify inside the queue worker** (this is the runtime that executes `GenerateThumbnailsJob`):

```bash
./vendor/bin/sail exec queue which blender
./vendor/bin/sail exec queue blender --version
./vendor/bin/sail exec queue blender -b --python-expr "print('Blender OK')"
./vendor/bin/sail exec queue php artisan dam:3d:diagnose
```

- **`.env`:** set **`DAM_3D=true`** (and optionally **`DAM_3D_BLENDER_BINARY=/usr/local/bin/blender`**, which matches the image default in `config/dam_3d.php`). The `queue` service mounts the project directory, so it reads the same `.env` as the app container.

## Where Blender is required (and where it is not)

- **Required only on workers** that run the image / thumbnail pipeline (`queue:work` / Horizon pools consuming `images`, `images-heavy`, or the same lanes your deploy uses for `ProcessAssetJob` → `GenerateThumbnailsJob`). **Do not rely on Blender on stateless web / PHP-FPM nodes** for previews; uploads and API responses must remain healthy when Blender is absent there.
- **Web tier:** No Blender installation needed for normal HTTP traffic.

## Supported version and binary location

| Item | Standard |
|------|----------|
| **Version** | **Blender 4.5.3 LTS** (lock worker images to this minor for predictable `bpy` / add-on behaviour). |
| **Distribution** | **Official Blender `.tar.xz` from blender.org** — extract and symlink; see below. |
| **Binary on disk** | **`/usr/local/bin/blender`** (symlink to the extracted `blender` executable). |
| **Laravel env (workers)** | **`DAM_3D_BLENDER_BINARY=/usr/local/bin/blender`** (same value in local `.env` when testing real renders on a dev machine that runs the queue worker). |

Do **not** change application code to add new env keys; only set the existing `DAM_3D_BLENDER_BINARY` where needed.

## Why not Ubuntu `apt install blender`

**Warning:** On many Ubuntu releases, **`apt install blender` pulls an old build (for example 3.0.x)** that is **not** supported for this pipeline. Operators should treat **`apt` Blender as unsuitable for staging/production DAM 3D** and use the **official 4.5.3 LTS tarball** instead. If a host already has `apt` Blender, prefer installing 4.5.3 LTS to `/usr/local` and pointing **`DAM_3D_BLENDER_BINARY`** at **`/usr/local/bin/blender`** so the wrong binary is never picked up from `PATH`.

## Install from the official tarball (Linux x64)

Adjust `VERSION` / `BUILD` if the project bumps the pinned LTS later; today the standard is **4.5.3**.

1. Download the **Linux x64** archive for **Blender 4.5.3 LTS** from **[blender.org](https://www.blender.org/download/lts/4-5/)** (official “Blender 4.5 LTS” distribution). Example filename pattern: `blender-4.5.3-linux-x64.tar.xz`.
2. Verify checksums from the Blender download page when available.
3. Install under `/opt/blender` (matches the Sail Dockerfile layout):

```bash
sudo mkdir -p /opt/blender
cd /tmp
# Replace URL/filename with the exact 4.5.3 linux-x64 link from blender.org when you run this.
tar -xf blender-4.5.3-linux-x64.tar.xz
sudo rm -rf /opt/blender/4.5.3
sudo mv blender-4.5.3-linux-x64 /opt/blender/4.5.3
sudo ln -sfn /opt/blender/4.5.3/blender /usr/local/bin/blender
```

4. On **workers** (and on dev machines that run thumbnails locally), set:

```bash
DAM_3D_BLENDER_BINARY=/usr/local/bin/blender
```

5. Confirm the install (use **`/usr/local/bin/blender`** explicitly unless you know `PATH` is correct):

```bash
/usr/local/bin/blender --version
/usr/local/bin/blender -b --python-expr "print('Blender OK')"
```

When `/usr/local/bin` is on your `PATH`, the same checks can be written as:

```bash
blender --version
blender -b --python-expr "print('Blender OK')"
```

Optional app-level check (from the app root, on a machine with PHP and the repo):

```bash
php artisan dam:3d:diagnose
```

## Local development: Sail / Docker / Ubuntu-like hosts

- **Goal:** the **same** `/usr/local/bin/blender` convention inside whatever runs **`./vendor/bin/sail artisan queue:work`** (typically the `queue` service in `compose.yaml`).
- **Pattern A — bake into the Sail PHP image:** add the tarball extract + symlink steps to `docker/8.5/Dockerfile` (or your active Sail Dockerfile) so every `sail build` includes Blender 4.5.3 LTS under `/usr/local/blender/4.5.3` and `/usr/local/bin/blender`.
- **Pattern B — bind-mount from the host:** install Blender on the Linux host as above, mount the binary (or `/usr/local/blender`) read-only into the container, and set `DAM_3D_BLENDER_BINARY=/usr/local/bin/blender` in `.env` for Sail.
- **WSL2:** install on the Linux distro that runs Docker/Sail using the same tarball steps; use Linux x64 builds.

If Blender is **not** installed locally, DAM 3D still works using **stub posters**; only real headless renders require the binary.

## Staging / production workers

- Install using the **same tarball procedure** on every host (or AMI / container layer) that runs thumbnail-heavy queues.
- Set **`DAM_3D_BLENDER_BINARY=/usr/local/bin/blender`** in the worker environment (Ansible, ECS task def, systemd `Environment=`, etc.).
- Keep **web** task definitions / servers **without** Blender unless they also run workers (not recommended).

## References

- Product / pipeline behaviour: [GLB_PREVIEW_PRODUCTION.md](../GLB_PREVIEW_PRODUCTION.md) (Phase 6).
- Worker package checklist (other tools): [PRODUCTION_WORKER_SOFTWARE.md](PRODUCTION_WORKER_SOFTWARE.md).
- Queue routing: [UPLOAD_AND_QUEUE.md](../UPLOAD_AND_QUEUE.md).
