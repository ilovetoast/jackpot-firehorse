# Company Subdomain Setup Guide

This guide explains how to set up and use the company-specific subdomain feature in Jackpot.

## Overview

The subdomain feature allows each company (tenant) to have their own unique URL, for example:
- Main app: `https://jackpot.local`
- Company "Velvet Hammer Branding": `https://velvet-hammer-branding.jackpot.local`
- Company "Tech Solutions": `https://tech-solutions.jackpot.local`

## Features Implemented

### 1. Company Settings UI
- **Auto-generated slug** from company name
- **Real-time validation** with debounced uniqueness checking
- **Manual editing** allowed with instant feedback
- **Visual indicators** showing availability status

### 2. Backend Validation
- **Uniqueness checking** across all tenants
- **Format validation** (lowercase, numbers, hyphens only)
- **Reserved slug protection** (prevents using system slugs like 'api', 'admin', etc.)
- **Length validation** (3-50 characters)

### 3. Nginx Configuration
- **Subdomain routing** with wildcard subdomain matching
- **Header passing** to identify tenant slug
- **Static asset handling** for both main domain and subdomains

### 4. Tenant Resolution Middleware
- **Automatic tenant detection** from subdomain
- **Multiple detection methods** (headers, host parsing)
- **Error handling** for non-existent tenants

### 5. Tenant Portal Pages
- **Landing page** for company-specific URLs
- **User authentication** status checking
- **Access control** based on tenant membership
- **Branded experience** using company brand settings

## Setup Instructions

### 1. Development Environment Setup

#### Option A: dnsmasq (Linux/macOS) - Recommended for Wildcards

**Install dnsmasq:**
```bash
# Ubuntu/Debian
sudo apt-get install dnsmasq

# macOS with Homebrew
brew install dnsmasq
```

**Configure wildcard DNS:**
```bash
# Create/edit dnsmasq config
sudo nano /etc/dnsmasq.conf

# Add this line:
address=/jackpot.local/127.0.0.1

# Restart dnsmasq
sudo systemctl restart dnsmasq  # Linux
sudo brew services restart dnsmasq  # macOS
```

**Update DNS settings:**
```bash
# Linux: Edit resolv.conf
sudo nano /etc/resolv.conf
# Add at the top: nameserver 127.0.0.1

# macOS: Add DNS server in Network Preferences
# System Preferences → Network → Advanced → DNS → Add 127.0.0.1
```

Now ALL `*.jackpot.local` subdomains resolve automatically!

#### Option B: Laravel Valet (macOS/Linux) - Easiest
```bash
# Install Valet
composer global require laravel/valet
valet install

# Navigate to project and link
cd /var/www/jackpot-firehorse/jackpot
valet link jackpot

# Access at: jackpot.test, company-name.jackpot.test
```

#### Option C: Docker with DNS (All Platforms)
```bash
# 1. Use the DNS-enabled docker configuration
cp docker-compose.dns.yml docker-compose.yml

# 2. Start the containers
docker-compose up -d

# 3. Update your system DNS to use 127.0.0.1
# This provides wildcard *.jackpot.local support
```

#### Option D: Manual Hosts File (Not Recommended)
Only use this for quick testing:
```bash
sudo nano /etc/hosts

# Add these lines:
127.0.0.1 jackpot.local
127.0.0.1 velvet-hammer-branding.jackpot.local
127.0.0.1 your-company-slug.jackpot.local
# Add more as needed for testing
```

#### Option B: Local Development
If you're running Laravel locally, you'll need to:

1. **Configure your local nginx/Apache** to handle subdomains
2. **Update your hosts file** with test subdomains
3. **Ensure your PHP-FPM** passes the subdomain headers

### 2. Laravel Configuration

The Laravel setup is already complete, but here's what was configured:

#### Middleware Registration
```php
// bootstrap/app.php
$middleware->alias([
    'subdomain' => \App\Http\Middleware\ResolveSubdomainTenant::class,
    // ... other middleware
]);
```

#### Routes Setup
```php
// routes/web.php
Route::middleware(['subdomain'])->group(function () {
    Route::get('/tenant-portal', [TenantPortalController::class, 'show']);
    Route::get('/tenant-portal/{slug}/login', [TenantPortalController::class, 'login']);
});
```

### 3. Testing the Wildcard DNS Setup

**Verify DNS Resolution:**
```bash
# Test if wildcard DNS is working
nslookup jackpot.local
nslookup test-company.jackpot.local
nslookup any-slug.jackpot.local

# All should return 127.0.0.1
```

**Quick Test Script:**
```bash
# Linux/macOS
./test-subdomains.sh

# Windows
test-subdomains.bat
```

**Manual Testing:**
1. **Access the main app**: `http://jackpot.local`
2. **Create a company** or update an existing one with a custom slug
3. **Test the subdomain**: `http://your-company-slug.jackpot.local`
4. **Try random subdomains**: `http://random-test.jackpot.local` (should show "Company Not Found")

## How It Works

### 1. Slug Generation Process
```javascript
// When user types company name
"Velvet Hammer Branding" → "velvet-hammer-branding"

// Real-time validation checks:
// ✓ Format (lowercase, numbers, hyphens)
// ✓ Length (3-50 characters)  
// ✓ Uniqueness across all tenants
// ✓ Not reserved (api, admin, www, etc.)
```

### 2. Request Flow
```
1. User visits: velvet-hammer-branding.jackpot.local
2. Nginx captures subdomain and sets headers
3. Laravel middleware resolves tenant from slug
4. TenantPortalController shows appropriate landing page
5. User can sign in or be redirected to main app
```

### 3. User Experience
- **Unauthenticated users**: See branded landing page with login option
- **Authenticated users (no access)**: See "no access" message
- **Authenticated users (with access)**: Automatically redirected to main app

## API Endpoints

### Check Slug Availability
```
POST /app/api/companies/check-slug
Content-Type: application/json

{
    "slug": "my-company-name"
}

Response:
{
    "available": true,
    "slug": "my-company-name",
    "reason": null
}
```

## Configuration Options

### Reserved Slugs
Edit the reserved slugs list in:
- `CompanyController::checkSlugAvailability()`
- `CompanyController::updateSettings()`

### Domain Configuration
Update domain patterns in:
- `nginx-subdomain.conf` (nginx configuration)
- `ResolveSubdomainTenant::extractTenantSlug()` (middleware)
- React components (for main app links)

### Subdomain Redirect Behavior
Customize redirect logic in:
- `TenantPortalController::show()` (landing page logic)
- `TenantPortalController::login()` (login redirect)

## Production Deployment

### 1. DNS Configuration
Set up wildcard DNS records:
```
A    yourdomain.com           → your-server-ip
A    *.yourdomain.com         → your-server-ip
```

### 2. SSL Certificates
Use a wildcard SSL certificate:
```
*.yourdomain.com
```

### 3. Nginx Configuration
Update the nginx configuration to use your production domain:
```nginx
server_name ~^(?<tenant_slug>[a-z0-9-]+)\.yourdomain\.com$;
```

### 4. Laravel Environment
Update your `.env` file:
```
APP_URL=https://yourdomain.com
```

## Troubleshooting

### Common Issues

1. **Wildcard subdomains not resolving**
   - **dnsmasq not working**: Check if dnsmasq is running: `sudo systemctl status dnsmasq`
   - **DNS not updated**: Flush DNS cache: `sudo systemctl flush-dns` (Linux) or `sudo dscacheutil -flushcache` (macOS)
   - **Wrong DNS server**: Verify 127.0.0.1 is first in DNS servers list
   - **Test DNS**: Run `nslookup test-company.jackpot.local` - should return 127.0.0.1

2. **Docker DNS issues**
   - Check containers are running: `docker-compose ps`
   - DNS container logs: `docker-compose logs dns`
   - Network connectivity: `docker-compose exec nginx ping dns`

3. **Subdomain not resolving (manual hosts)**
   - Check `/etc/hosts` entries
   - Verify nginx configuration
   - Check nginx error logs

4. **Tenant not found**
   - Verify slug exists in database
   - Check middleware is registered
   - Enable debug mode for detailed headers

5. **Infinite redirects**
   - Check session domain configuration
   - Verify cookie domain settings
   - Review redirect logic in controllers

### Debug Mode
Enable debug mode to see resolution headers:
```
APP_DEBUG=true
```

Headers added in debug mode:
- `X-Resolved-Tenant-Id`
- `X-Resolved-Tenant-Slug`

## Security Considerations

1. **Slug validation** prevents injection attacks
2. **Reserved slugs** protect system routes
3. **Access control** enforced at tenant level
4. **Session isolation** between main app and subdomains

## Future Enhancements

Potential improvements you could add:

1. **Custom domains**: Allow companies to use their own domains
2. **Brand customization**: More extensive theming per tenant
3. **Analytics**: Track subdomain usage per tenant
4. **API subdomains**: Separate API endpoints per tenant
5. **Multi-level subdomains**: Support for `api.company.yourdomain.com`

## Support

For questions or issues with the subdomain setup:

1. Check this documentation
2. Review the implementation files
3. Test with debug mode enabled
4. Check nginx and Laravel logs