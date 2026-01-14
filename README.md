<p align="center"><a href="https://laravel.com" target="_blank"><img src="https://raw.githubusercontent.com/laravel/art/master/logo-lockup/5%20SVG/2%20CMYK/1%20Full%20Color/laravel-logolockup-cmyk-red.svg" width="400" alt="Laravel Logo"></a></p>

<p align="center">
<a href="https://github.com/laravel/framework/actions"><img src="https://github.com/laravel/framework/workflows/tests/badge.svg" alt="Build Status"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/dt/laravel/framework" alt="Total Downloads"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/v/laravel/framework" alt="Latest Stable Version"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/l/laravel/framework" alt="License"></a>
</p>

---

## ⚠️ Phase 2 is Locked

**Phase 2 (Upload System) is production-ready and locked.**

- ✅ Production-ready upload infrastructure (multipart, resume, cleanup)
- ⚠️ **No new features or refactors should be added without starting Phase 3**
- 📚 See [docs/PHASE_2_UPLOAD_SYSTEM.md](./docs/PHASE_2_UPLOAD_SYSTEM.md) for full documentation

---

## 🚀 Queue Workers (Local Development)

**Queue workers are required for thumbnail processing and background jobs.**

### Quick Start

Queue workers run automatically via Docker Compose:

```bash
# Start all services (including queue worker)
./vendor/bin/sail up -d

# Verify queue worker is running
./vendor/bin/sail ps | grep queue
```

### Manual Commands

**Start queue worker:**
```bash
./vendor/bin/sail up -d queue
```

**Stop queue worker:**
```bash
./vendor/bin/sail stop queue
```

**View queue worker logs:**
```bash
./vendor/bin/sail logs -f queue
```

**Process jobs manually (one-time):**
```bash
./vendor/bin/sail artisan queue:work --once
```

### Health Check

Check for stuck jobs and missing workers:

```bash
./vendor/bin/sail artisan queue:health-check
```

This command will:
- ✓ Report if queue is healthy (no jobs)
- ⚠️ Warn if jobs are stuck (>5 minutes old)
- Show total jobs and stale job count

### Troubleshooting

**Problem: Thumbnails never complete, "Processing (1)" stuck**

1. Check if queue worker is running:
   ```bash
   ./vendor/bin/sail ps | grep queue
   ```

2. Check for stuck jobs:
   ```bash
   ./vendor/bin/sail artisan queue:health-check
   ```

3. If jobs are stuck, restart the queue worker:
   ```bash
   ./vendor/bin/sail restart queue
   ```

4. Or manually process stuck jobs:
   ```bash
   ./vendor/bin/sail artisan queue:work --once --tries=1
   # Repeat until queue is empty
   ```

**Problem: Queue worker keeps crashing**

Check logs for errors:
```bash
./vendor/bin/sail logs queue
```

Common issues:
- Database connection errors → Ensure MySQL is running
- Memory limits → Increase PHP memory limit in Dockerfile
- Timeout errors → Jobs exceeding 90s timeout

### Configuration

Queue connection: `database` (configured in `config/queue.php`)

Worker settings (in `compose.yaml`):
- `--tries=3` - Maximum retry attempts
- `--timeout=90` - Job timeout in seconds
- `--sleep=3` - Seconds to sleep when no jobs available
- `--max-jobs=1000` - Restart worker after processing N jobs (prevents memory leaks)
- `--max-time=3600` - Restart worker after N seconds (1 hour)

---

## About Laravel

Laravel is a web application framework with expressive, elegant syntax. We believe development must be an enjoyable and creative experience to be truly fulfilling. Laravel takes the pain out of development by easing common tasks used in many web projects, such as:

- [Simple, fast routing engine](https://laravel.com/docs/routing).
- [Powerful dependency injection container](https://laravel.com/docs/container).
- Multiple back-ends for [session](https://laravel.com/docs/session) and [cache](https://laravel.com/docs/cache) storage.
- Expressive, intuitive [database ORM](https://laravel.com/docs/eloquent).
- Database agnostic [schema migrations](https://laravel.com/docs/migrations).
- [Robust background job processing](https://laravel.com/docs/queues).
- [Real-time event broadcasting](https://laravel.com/docs/broadcasting).

Laravel is accessible, powerful, and provides tools required for large, robust applications.

## Learning Laravel

Laravel has the most extensive and thorough [documentation](https://laravel.com/docs) and video tutorial library of all modern web application frameworks, making it a breeze to get started with the framework. You can also check out [Laravel Learn](https://laravel.com/learn), where you will be guided through building a modern Laravel application.

If you don't feel like reading, [Laracasts](https://laracasts.com) can help. Laracasts contains thousands of video tutorials on a range of topics including Laravel, modern PHP, unit testing, and JavaScript. Boost your skills by digging into our comprehensive video library.

## Laravel Sponsors

We would like to extend our thanks to the following sponsors for funding Laravel development. If you are interested in becoming a sponsor, please visit the [Laravel Partners program](https://partners.laravel.com).

### Premium Partners

- **[Vehikl](https://vehikl.com)**
- **[Tighten Co.](https://tighten.co)**
- **[Kirschbaum Development Group](https://kirschbaumdevelopment.com)**
- **[64 Robots](https://64robots.com)**
- **[Curotec](https://www.curotec.com/services/technologies/laravel)**
- **[DevSquad](https://devsquad.com/hire-laravel-developers)**
- **[Redberry](https://redberry.international/laravel-development)**
- **[Active Logic](https://activelogic.com)**

## Contributing

Thank you for considering contributing to the Laravel framework! The contribution guide can be found in the [Laravel documentation](https://laravel.com/docs/contributions).

## Code of Conduct

In order to ensure that the Laravel community is welcoming to all, please review and abide by the [Code of Conduct](https://laravel.com/docs/contributions#code-of-conduct).

## Security Vulnerabilities

If you discover a security vulnerability within Laravel, please send an e-mail to Taylor Otwell via [taylor@laravel.com](mailto:taylor@laravel.com). All security vulnerabilities will be promptly addressed.

## License

The Laravel framework is open-sourced software licensed under the [MIT license](https://opensource.org/licenses/MIT).
